<?php
/**
 * Post Editor Modifications
 * Handles all modifications to the post edit screen (assets, meta boxes, save handlers)
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// POST EDITOR ASSETS
// ============================================================================

/**
 * Enqueue post editor assets
 *
 * Loads on any post type that has at least one HT-enabled taxonomy, so that
 * redirect and hidden-term markers appear in taxonomy pills/checklists for
 * custom post types, not just the built-in 'post' type.
 */
function htax_enqueue_post_editor_assets( $hook ) {
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }

    // Only enqueue when this post type has at least one HT-enabled taxonomy
    $post_type_taxonomies = get_object_taxonomies( $screen->post_type, 'names' );
    $enabled_taxonomies   = array_intersect( $post_type_taxonomies, htax_get_enabled_taxonomies() );
    if ( empty( $enabled_taxonomies ) ) {
        return;
    }

    // Enqueue CSS
    wp_enqueue_style(
        'htax-post-edit-css',
        HTAX_PLUGIN_URL . 'admin/css/htax-post-edit.css',
        array(),
        HTAX_VERSION
    );

    $is_block_editor = method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();

    // Enqueue tag suggestions first so its registerPlugin fires before Sticky for
    // Terms — this makes Document settings preferences list them in sidebar order.
    $options             = get_option( 'htax_settings', array() );
    $suggestions_enabled = ! isset( $options['content_tag_suggestions'] ) || ! empty( $options['content_tag_suggestions'] );
    if ( $is_block_editor && $suggestions_enabled ) {
        wp_enqueue_script(
            'htax-tag-suggestions-js',
            HTAX_PLUGIN_URL . 'admin/js/htax-tag-suggestions.js',
            array(
                'wp-plugins',
                'wp-edit-post',
                'wp-editor',
                'wp-data',
                'wp-element',
                'wp-components',
                'wp-api-fetch',
            ),
            HTAX_VERSION,
            true
        );
        // Build the list of flat HT-enabled taxonomies visible in the block editor.
        $flat_taxonomies = array();
        foreach ( $enabled_taxonomies as $tax_slug ) {
            if ( is_taxonomy_hierarchical( $tax_slug ) ) {
                continue;
            }
            $tax_obj = get_taxonomy( $tax_slug );
            if ( ! $tax_obj || empty( $tax_obj->show_in_rest ) ) {
                continue;
            }
            $rest_base         = ! empty( $tax_obj->rest_base ) ? $tax_obj->rest_base : $tax_slug;
            $flat_taxonomies[] = array(
                'slug'     => $tax_slug,
                'restBase' => $rest_base,
                'label'    => $tax_obj->labels->name,
            );
        }

        wp_localize_script( 'htax-tag-suggestions-js', 'htaxTagSuggestions', array(
            'restUrl'    => rest_url( 'htax/v1/suggested-tags' ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'taxonomies' => $flat_taxonomies,
        ) );
    }

    // Script dependencies: add Gutenberg packages when in the block editor.
    // Depend on htax-tag-suggestions-js (when active) so it loads first.
    $script_deps = array( 'jquery' );
    if ( $is_block_editor ) {
        $script_deps = array_merge( $script_deps, array(
            'wp-plugins', 'wp-edit-post', 'wp-data', 'wp-element', 'wp-components', 'wp-i18n',
        ) );
        if ( $suggestions_enabled ) {
            $script_deps[] = 'htax-tag-suggestions-js';
        }
        // Editor display preferences — five per-user toggles in the ··· menu.
        wp_enqueue_script(
            'htax-editor-prefs-js',
            HTAX_PLUGIN_URL . 'admin/js/htax-editor-prefs.js',
            array(
                'wp-plugins',
                'wp-edit-post',
                'wp-data',
                'wp-element',
                'wp-preferences',
            ),
            HTAX_VERSION,
            true
        );
        // One-time callout: show a Gutenberg notice on first block editor load per user.
        $dismissed = get_user_meta( get_current_user_id(), '_htax_prefs_callout_dismissed', true );
        wp_localize_script( 'htax-editor-prefs-js', 'htaxEditorPrefsConfig', array(
            'showCallout'  => empty( $dismissed ),
            'dismissNonce' => wp_create_nonce( 'htax_dismiss_prefs_callout' ),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        ) );
    }

    // Enqueue main post-edit JS
    wp_enqueue_script(
        'htax-post-edit-js',
        HTAX_PLUGIN_URL . 'admin/js/htax-post-edit.js',
        $script_deps,
        HTAX_VERSION,
        true
    );

    $term_metadata = array();

    // Exclude 'group' (internal taxonomy)
    $enabled_taxonomies = array_values( array_diff( $enabled_taxonomies, array( 'group' ) ) );
    if ( empty( $enabled_taxonomies ) ) {
        wp_localize_script( 'htax-post-edit-js', 'htaxTermMeta', $term_metadata );
        return;
    }

    $post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

    // Load term flag sets; user display preferences are JS-side (core/preferences store).
    $redirect_lookup = array_flip( htax_get_all_redirect_term_ids() );
    $hidden_lookup   = array_flip( htax_get_all_hidden_term_ids() );

    foreach ( $enabled_taxonomies as $taxonomy ) {
        $is_hierarchical = is_taxonomy_hierarchical( $taxonomy );

        // Taxonomy-level feature gates — user display preferences applied in JS.
        $tax_has_rels   = htax_taxonomy_has_feature( $taxonomy, 'term_relationships' );
        $tax_has_redir  = htax_taxonomy_has_feature( $taxonomy, 'redirects' );
        $tax_has_hidden = htax_taxonomy_has_feature( $taxonomy, 'visibility' );
        // Any taxonomy with archive customisation support sticky pins. Every assigned
        // term needs to be in htaxTermMeta so the JS can find it by name — even terms
        // that have no inclusions, redirects, or hidden flag.
        $tax_has_sticky = htax_taxonomy_has_feature( $taxonomy, 'customize_archives' );

        // Skip taxonomies that support none of the post-editor features.
        if ( ! $tax_has_rels && ! $tax_has_redir && ! $tax_has_hidden && ! $tax_has_sticky ) {
            continue;
        }

        // Hierarchical: the checklist shows every term, so load all.
        // Flat: only assigned terms are loaded here; newly added pills get their
        // metadata on demand via the htax/v1/term-meta REST endpoint + Redux subscriber.
        if ( $is_hierarchical ) {
            $terms = get_terms( array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ) );
        } elseif ( $post_id ) {
            $terms = get_the_terms( $post_id, $taxonomy );
        } else {
            $terms = array();
        }

        if ( ! $terms || is_wp_error( $terms ) ) {
            continue;
        }

        foreach ( $terms as $term ) {
            $tid        = (int) $term->term_id;
            $is_redir   = $tax_has_redir && isset( $redirect_lookup[ $tid ] );
            $is_hidden  = $tax_has_hidden && isset( $hidden_lookup[ $tid ] );
            $inc_count  = 0;
            $cont_count = 0;
            $included   = array();
            $containers = array();

            if ( $tax_has_rels ) {
                // Read per-term caches: false = cold, [] = warm-empty.
                // Calling the function on a cold cache warms it (TTL=0).
                $included = htax_get_transient( 'htax_inc_' . $tid );
                if ( false === $included ) {
                    $included = htax_get_included_terms( $tid );
                }
                $containers = htax_get_transient( 'htax_cont_' . $tid );
                if ( false === $containers ) {
                    $containers = htax_get_container_terms( $tid );
                }
                $inc_count  = count( $included );
                $cont_count = count( $containers );
            }

            // Include terms that have something to show, or belong to a sticky-eligible
            // hierarchical taxonomy (so the JS tristate can operate on any assigned category).
            if ( $inc_count || $cont_count || $is_redir || $is_hidden || $tax_has_sticky ) {
                $inc_names  = array_values( wp_list_pluck( $included, 'name' ) );
                $cont_names = array_values( wp_list_pluck( $containers, 'name' ) );

                $redir_target_name = '';
                if ( $is_redir ) {
                    $redir_target = htax_get_redirect_target( $tid );
                    if ( $redir_target instanceof WP_Term ) {
                        $redir_target_name = $redir_target->name;
                    }
                }

                $term_metadata[ $tid ] = array(
                    'id'                  => $tid,
                    'name'                => $term->name,
                    'slug'                => $term->slug,
                    'taxonomy'            => $taxonomy,
                    'is_redirect'         => $is_redir,
                    'is_hidden'           => $is_hidden,
                    'is_hierarchical'     => $is_hierarchical,
                    'inclusion_count'     => $inc_count,
                    'container_count'     => $cont_count,
                    'included_names'      => $inc_names,
                    'container_names'     => $cont_names,
                    'redirect_target_name' => $redir_target_name,
                );
            }
        }
    }

    // Localize script with term metadata
    wp_localize_script( 'htax-post-edit-js', 'htaxTermMeta', $term_metadata );

    // Tell JS when new tag creation is prevented so it can update the input placeholder.
    if ( ! empty( $options['prevent_tag_creation'] ) ) {
        wp_add_inline_script( 'htax-post-edit-js', 'window.htaxPreventTagCreation = true;', 'before' );
    }

    // Per-taxonomy editor feature flags for the JS autocomplete subscriber.
    // htax/v1/term-meta returns raw data; the subscriber uses these flags to
    // decide what to display — keeping editor preferences out of the API.
    $editor_features = array();
    // Per-taxonomy feature gates — emitted as htaxTaxonomyFeatures for JS.
    // User display preferences are stored in core/preferences (htax/post-edit scope)
    // and applied client-side; the API and this data carry only taxonomy-level gates.
    $taxonomy_features = array();
    foreach ( $enabled_taxonomies as $tax ) {
        $taxonomy_features[ $tax ] = array(
            'inclusions' => htax_taxonomy_has_feature( $tax, 'term_relationships' ),
            'containers' => htax_taxonomy_has_feature( $tax, 'term_relationships' ),
            'redirects'  => htax_taxonomy_has_feature( $tax, 'redirects' ),
            'visibility' => htax_taxonomy_has_feature( $tax, 'visibility' ),
        );
    }
    wp_localize_script( 'htax-post-edit-js', 'htaxTaxonomyFeatures', $taxonomy_features );

    // Name→flags map for styling the Gutenberg autocomplete suggestions dropdown.
    // Only redirect and hidden terms are included — typically O(10s) of entries —
    // so inlining at page load is cheaper than per-keystroke REST calls.
    $autocomplete_meta = array();
    $flat_enabled      = array();
    foreach ( $enabled_taxonomies as $tax ) {
        if ( ! is_taxonomy_hierarchical( $tax ) ) {
            $flat_enabled[] = $tax;
        }
    }
    if ( ! empty( $flat_enabled ) ) {
        $relevant_ids = array_unique( array_merge(
            array_keys( $redirect_lookup ),
            array_keys( $hidden_lookup )
        ) );
        if ( ! empty( $relevant_ids ) ) {
            $ac_terms = get_terms( array(
                'taxonomy'   => $flat_enabled,
                'include'    => $relevant_ids,
                'hide_empty' => false,
            ) );
            if ( $ac_terms && ! is_wp_error( $ac_terms ) ) {
                foreach ( $ac_terms as $ac_term ) {
                    $tid       = (int) $ac_term->term_id;
                    $is_redir  = isset( $redirect_lookup[ $tid ] ) && htax_taxonomy_has_feature( $ac_term->taxonomy, 'redirects' );
                    $is_hidden = isset( $hidden_lookup[ $tid ] ) && htax_taxonomy_has_feature( $ac_term->taxonomy, 'visibility' );
                    if ( $is_redir || $is_hidden ) {
                        $autocomplete_meta[ $ac_term->name ] = array(
                            'r' => $is_redir ? 1 : 0,
                            'h' => $is_hidden ? 1 : 0,
                        );
                    }
                }
            }
        }
    }
    wp_localize_script( 'htax-post-edit-js', 'htaxAutocompleteMeta', $autocomplete_meta );

    // Pre-populate the Gutenberg Redux store with assigned term objects so
    // getEntityRecord() returns immediately without triggering per-term REST calls.
    $preload_enabled = ! isset( $options['preload_editor_terms'] ) || ! empty( $options['preload_editor_terms'] );
    if ( $preload_enabled && $is_block_editor && $post_id ) {
        $preloaded = array();
        foreach ( $enabled_taxonomies as $taxonomy ) {
            $tax_obj = get_taxonomy( $taxonomy );
            if ( ! $tax_obj || empty( $tax_obj->show_in_rest ) ) {
                continue;
            }
            $assigned = get_the_terms( $post_id, $taxonomy );
            if ( ! $assigned || is_wp_error( $assigned ) ) {
                continue;
            }
            $preloaded[ $taxonomy ] = array();
            foreach ( $assigned as $term ) {
                $link                     = get_term_link( $term );
                $preloaded[ $taxonomy ][] = array(
                    'id'          => $term->term_id,
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'taxonomy'    => $taxonomy,
                    'link'        => is_string( $link ) ? $link : '',
                    'description' => $term->description,
                    'parent'      => $term->parent,
                    'count'       => $term->count,
                    'meta'        => new stdClass(),
                );
            }
        }
        if ( ! empty( $preloaded ) ) {
            // Attach the injection as an inline script that runs BEFORE wp-edit-post
            // initializes the block editor. At that point @wordpress/core-data is already
            // loaded (it's a dependency), so dispatch('core') is available. Running here
            // — not in htax-post-edit.js — ensures the store is pre-populated and the
            // resolvers are marked fulfilled before any getEntityRecord() call is made,
            // which eliminates all per-term REST fetches.
            wp_add_inline_script(
                'wp-edit-post',
                '(function(){' .
                'var p=' . wp_json_encode( $preloaded ) . ';' .
                'window.htaxPreloadedTerms=p;' .
                'if(!window.wp||!window.wp.data)return;' .
                'var d=window.wp.data.dispatch("core");' .
                'if(!d||!d.receiveEntityRecords)return;' .
                'Object.keys(p).forEach(function(tax){' .
                'var terms=p[tax];' .
                'd.receiveEntityRecords("taxonomy",tax,terms,undefined,false);' .
                'if(d.finishResolution){' .
                'terms.forEach(function(t){' .
                'd.finishResolution("getEntityRecord",["taxonomy",tax,t.id]);' .
                '});' .
                '}' .
                '});' .
                '})();',
                'before'
            );
        }
    }

    // Flat taxonomy slugs + REST bases for the JS autocomplete subscriber.
    // The subscriber watches the Redux store for newly assigned flat-taxonomy terms
    // and fetches their badge metadata on demand via htax/v1/term-meta.
    if ( $is_block_editor ) {
        $flat_tax_info = array();
        foreach ( $enabled_taxonomies as $tax ) {
            if ( is_taxonomy_hierarchical( $tax ) ) {
                continue;
            }
            $tax_obj = get_taxonomy( $tax );
            if ( ! $tax_obj || empty( $tax_obj->show_in_rest ) ) {
                continue;
            }
            $flat_tax_info[] = array(
                'slug'     => $tax,
                'restBase' => ! empty( $tax_obj->rest_base ) ? $tax_obj->rest_base : $tax,
            );
        }
        if ( ! empty( $flat_tax_info ) ) {
            wp_localize_script( 'htax-post-edit-js', 'htaxFlatTaxonomies', $flat_tax_info );
        }
    }

    // Localize sticky terms config for all taxonomies that support archive customization.
    // Whether to show the pins is a per-user preference (core/preferences htax/post-edit).
    if ( $is_block_editor ) {
        $sticky_config = array();
        $tax_objects   = get_object_taxonomies( $screen->post_type, 'objects' );
        foreach ( $tax_objects as $tax_obj ) {
            if ( 'group' === $tax_obj->name ) {
                continue;
            }
            if ( ! htax_taxonomy_has_feature( $tax_obj->name, 'customize_archives' ) ) {
                continue;
            }
            $sticky_config[] = array(
                'name'     => $tax_obj->name,
                'label'    => $tax_obj->labels->name,
                'restBase' => $tax_obj->rest_base ? $tax_obj->rest_base : $tax_obj->name,
            );
        }
        if ( ! empty( $sticky_config ) ) {
            wp_localize_script( 'htax-post-edit-js', 'htaxStickyConfig', $sticky_config );
        }
    }
}

// ============================================================================
// STICKY TERMS — REST FIELD (read + write, used by Gutenberg sidebar panel)
// ============================================================================

/**
 * Register the htax_sticky_terms REST field on all eligible post types.
 */
function htax_register_sticky_rest_field() {
    $post_types = get_post_types( array( 'public' => true ), 'names' );

    foreach ( $post_types as $post_type ) {
        if ( 'page' === $post_type ) {
            continue;
        }

        $taxonomies = get_object_taxonomies( $post_type, 'names' );
        $has_sticky = false;
        foreach ( $taxonomies as $tax ) {
            if ( 'group' !== $tax && htax_taxonomy_has_feature( $tax, 'customize_archives' ) ) {
                $has_sticky = true;
                break;
            }
        }
        if ( ! $has_sticky ) {
            continue;
        }

        register_rest_field(
            $post_type,
            'htax_sticky_terms',
            array(
                'get_callback'    => 'htax_rest_get_sticky_terms',
                'update_callback' => 'htax_rest_update_sticky_terms',
                'schema'          => array(
                    'description'          => 'Sticky term assignments keyed by taxonomy slug',
                    'type'                 => 'object',
                    'context'              => array( 'edit' ),
                    'properties'           => array(),
                    'additionalProperties' => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'integer' ),
                    ),
                ),
            )
        );
    }
}
add_action( 'rest_api_init', 'htax_register_sticky_rest_field' );

/**
 * REST GET: return sticky term assignments for a post.
 *
 * @param array $post_arr Post data array from the REST API.
 * @return object Assignments keyed by taxonomy slug, e.g. {"post_tag":[12,34]}.
 */
function htax_rest_get_sticky_terms( $post_arr, string $field_name, WP_REST_Request $request ) {
    if ( ! htax_rest_field_requested( $request, 'htax_sticky_terms' ) ) {
        return (object) array();
    }

    return (object) htax_get_sticky_terms_by_taxonomy( (int) $post_arr['id'] );
}

/**
 * Read sticky term assignments for a post, grouped by taxonomy slug.
 *
 * @since 1.0.0
 * @param int $post_id Post ID.
 * @return array Assignments keyed by taxonomy slug, e.g. ['post_tag' => [12, 34]].
 */
function htax_get_sticky_terms_by_taxonomy( int $post_id ): array {
    $result        = array();
    $all_meta      = get_post_meta( $post_id );
    $sticky_prefix = '_htax_sticky_';
    $prefix_len    = strlen( $sticky_prefix );

    foreach ( $all_meta as $key => $values ) {
        if ( 0 !== strpos( $key, $sticky_prefix ) ) {
            continue;
        }
        $term_id = (int) substr( $key, $prefix_len );
        if ( $term_id <= 0 || empty( $values[0] ) ) {
            continue;
        }
        $term = get_term( $term_id );
        if ( $term && ! is_wp_error( $term ) ) {
            $result[ $term->taxonomy ][] = $term_id;
        }
    }

    return $result;
}

/**
 * REST UPDATE: write sticky term assignments for a post.
 *
 * @param mixed   $value New assignments, e.g. {"post_tag":[12,34]}.
 * @param WP_Post $post  Post object.
 */
function htax_rest_update_sticky_terms( $value, $post ) {
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return;
    }
    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_sticky_posts' ) ) {
        return;
    }
    if ( ! is_array( $value ) && ! is_object( $value ) ) {
        return;
    }

    $value      = (array) $value;
    $old_result = htax_get_sticky_terms_by_taxonomy( (int) $post->ID );

    foreach ( $value as $taxonomy => $new_sticky_ids ) {
        if ( ! is_string( $taxonomy ) || ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
            continue;
        }

        $old_sticky = isset( $old_result[ $taxonomy ] ) ? (array) $old_result[ $taxonomy ] : array();
        $new_sticky = array_map( 'intval', (array) $new_sticky_ids );

        $assigned = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
        if ( ! is_wp_error( $assigned ) ) {
            $new_sticky = array_values( array_intersect( $new_sticky, $assigned ) );
        }

        foreach ( $new_sticky as $term_id ) {
            update_post_meta( $post->ID, htax_get_sticky_assignment_key( $term_id ), 1 );
        }
        foreach ( array_diff( $old_sticky, $new_sticky ) as $term_id ) {
            delete_post_meta( $post->ID, htax_get_sticky_assignment_key( $term_id ) );
        }

        foreach ( array_unique( array_merge( $old_sticky, $new_sticky ) ) as $term_id ) {
            if ( $term_id ) {
                htax_delete_transient( 'htax_sticky_' . (int) $term_id );
                do_action( 'htax_term_archive_changed', (int) $term_id, $taxonomy );
            }
        }
    }
}

// ============================================================================
// EDITOR PREFERENCES CALLOUT — dismiss permanently for the current user
// ============================================================================

/**
 * Mark the post-editor ··· menu indicator callout as dismissed for the current user.
 */
function htax_ajax_dismiss_prefs_callout() {
    check_ajax_referer( 'htax_dismiss_prefs_callout', 'nonce' );
    update_user_meta( get_current_user_id(), '_htax_prefs_callout_dismissed', '1' );
    wp_send_json_success();
}

// ============================================================================
// HOOK REGISTRATION (Only in admin context)
// ============================================================================

// REST field registration is in includes/core/rest-api.php (loaded unconditionally)
