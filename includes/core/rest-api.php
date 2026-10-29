<?php
/**
 * REST API integration
 *
 * Extends the standard WordPress REST API with HT-specific fields:
 *
 * Term endpoints (/wp-json/wp/v2/{taxonomy}/{id}):
 *   htax_is_hidden   bool    True when the term is hidden (authorized users only).
 *   htax_icon        object  Icon URLs + dimensions at small/medium/large.
 *   htax_redirect    object  Stub of the term this term redirects to.
 *   htax_includes    array   WP descendants + direct HT inclusions, each as a minimal
 *                            term stub carrying "strength" (the relationship's explicit
 *                            weight). Mutual inclusions carry "synonym": true.
 *   htax_synonyms    array   Synonym terms (mutual inclusions) as term stubs:
 *                            id, slug, name, taxonomy. Dedicated field for direct
 *                            synonym access without parsing htax_includes.
 *
 * Posts endpoint (/wp-json/wp/v2/posts):
 *   htax_score              number  Scored responses only. Normalized 0–1 relevance.
 *   htax_matched_term_slugs array   Scored responses only. Slugs of matched terms.
 *   htax_term_peers         object  Scored responses only. Keyed by term ID; each
 *                                   value: { peers: [{origin, slug}] }.
 *   htax_term_strengths     object  Opt-in via _fields. Map of the post's directly-
 *                                   assigned term IDs => association strength (0–1).
 *
 * Query params accepted on posts: htax_includes, htax_match, htax_weights.
 *
 * X-HyperTaxonomy version header is added to all REST responses for peer-site
 * capability detection.
 *
 * Custom REST routes:
 *   GET htax/v1/term-meta?taxonomy=X&ids=1,2,3
 *     Returns badge metadata (redirect, hidden, inc_count, cont_count) for the
 *     requested term IDs. Terms with no HT features are also included when the
 *     taxonomy supports sticky pins (customize_archives). Used by the post editor
 *     JS to fetch metadata for terms added via autocomplete mid-session.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// HELPERS
// ==============================================================================

/**
 * Return true if the given field is among the requested _fields, or if _fields
 * is absent (no restriction — all fields computed for backward compatibility).
 *
 * @since 1.0.0
 * @param WP_REST_Request|null $request REST request, or null outside a request context.
 * @param string               $field   Top-level field name, e.g. 'htax_icon'.
 * @return bool
 */
function htax_rest_field_requested( ?WP_REST_Request $request, string $field ): bool {
    // Some fields are globally disabled by admin settings regardless of _fields.
    static $globally_disabled = null;
    if ( null === $globally_disabled ) {
        $htax_opts         = get_option( 'htax_settings', array() );
        $globally_disabled = array();
        $rel_data_off      = isset( $htax_opts['rest_include_relationship_data'] ) && empty( $htax_opts['rest_include_relationship_data'] );
        $peer_sync_off     = isset( $htax_opts['rest_peer_sync_enabled'] ) && empty( $htax_opts['rest_peer_sync_enabled'] );
        if ( $rel_data_off ) {
            $globally_disabled[] = 'htax_includes';
            $globally_disabled[] = 'htax_synonyms';
        }
        if ( $rel_data_off || $peer_sync_off ) {
            $globally_disabled[] = 'htax_relationships';
        }
    }
    if ( in_array( $field, $globally_disabled, true ) ) {
        return false;
    }

    if ( ! $request ) {
        return true;
    }
    $fields = $request->get_param( '_fields' );
    if ( empty( $fields ) ) {
        return true;
    }
    if ( is_string( $fields ) ) {
        $fields = array_map( 'trim', explode( ',', $fields ) );
    }
    return in_array( $field, (array) $fields, true );
}

/**
 * Build a minimal term object for use inside htax_includes / htax_redirect fields.
 *
 * Contains only the fields needed to render a link without a follow-up request.
 *
 * @param WP_Term $term Term object.
 * @return array { id, name, slug, taxonomy, link }
 */
function htax_rest_term_stub( WP_Term $term ): array {
    $link = get_term_link( $term );
    return array(
        'id'       => $term->term_id,
        'name'     => $term->name,
        'slug'     => $term->slug,
        'taxonomy' => $term->taxonomy,
        'link'     => is_wp_error( $link ) ? '' : $link,
    );
}

/**
 * Build htax_icon REST field data for a term.
 *
 * Returns an array keyed by size ('small', 'medium', 'large'), each containing
 * 'url', 'width', and 'height'. Returns null when no icon is set or the icons
 * feature is not enabled for the taxonomy.
 *
 * @param WP_Term $term Term object.
 * @return array|null Icon data or null.
 */
function htax_rest_build_icon_data( WP_Term $term ): ?array {
    if ( ! htax_is_taxonomy_enabled( $term->taxonomy ) || ! htax_taxonomy_has_feature( $term->taxonomy, 'icons' ) ) {
        return null;
    }

    $taxonomy      = $term->taxonomy;
    $attachment_id = (int) get_term_meta( $term->term_id, '_htax_icon_id', true );
    if ( ! $attachment_id ) {
        return null;
    }

    // Verify the attachment is still an image.
    if ( ! wp_attachment_is_image( $attachment_id ) ) {
        return null;
    }

    // Size names follow the pattern used when sizes are registered: htax-icon-{type}-{taxonomy}.
    $small_url = wp_get_attachment_image_url( $attachment_id, "htax-icon-small-{$taxonomy}" );
    if ( ! $small_url ) {
        return null;
    }

    // Read configured dimensions once per taxonomy per request.
    static $dims_cache = array();
    if ( ! isset( $dims_cache[ $taxonomy ] ) ) {
        $options       = get_option( 'htax_settings', array() );
        $icon_settings = isset( $options['icon_settings'][ $taxonomy ] ) ? $options['icon_settings'][ $taxonomy ] : array();
        $dims_cache[ $taxonomy ] = array(
            'base_width'  => isset( $icon_settings['width'] )      ? (int) $icon_settings['width']      : 0,
            'base_height' => isset( $icon_settings['height'] )     ? (int) $icon_settings['height']     : 0,
            'medium_pct'  => isset( $icon_settings['medium_pct'] ) ? (int) $icon_settings['medium_pct'] : 50,
            'small_pct'   => isset( $icon_settings['small_pct'] )  ? (int) $icon_settings['small_pct']  : 25,
        );
    }
    $base_width  = $dims_cache[ $taxonomy ]['base_width'];
    $base_height = $dims_cache[ $taxonomy ]['base_height'];
    $medium_pct  = $dims_cache[ $taxonomy ]['medium_pct'];
    $small_pct   = $dims_cache[ $taxonomy ]['small_pct'];

    $icon = array();
    foreach ( array( 'small', 'medium', 'large' ) as $size_type ) {
        $size_name = "htax-icon-{$size_type}-{$taxonomy}";
        $url       = wp_get_attachment_image_url( $attachment_id, $size_name );
        if ( ! $url ) {
            $url = $small_url; // fallback to small
        }
        switch ( $size_type ) {
            case 'large':
                $w = $base_width;
                $h = $base_height;
                break;
            case 'medium':
                $w = (int) round( $base_width * $medium_pct / 100 );
                $h = (int) round( $base_height * $medium_pct / 100 );
                break;
            default: // small
                $w = (int) round( $base_width * $small_pct / 100 );
                $h = (int) round( $base_height * $small_pct / 100 );
                break;
        }
        $icon[ $size_type ] = array(
            'url'    => $url,
            'width'  => $w,
            'height' => $h,
        );
    }

    return $icon;
}


function htax_rest_build_redirect_data( WP_Term $term ): ?array {
    if ( ! htax_is_taxonomy_enabled( $term->taxonomy ) ) {
        return null;
    }

    $target = htax_get_redirect_target( $term->term_id );
    if ( ! $target ) {
        return null;
    }

    return htax_rest_term_stub( $target );
}

/**
 * Build htax_includes REST field data for a term.
 *
 * For any hierarchical taxonomy, includes all WP descendant terms (mirroring
 * the include_children behavior of WP_Query). For HT-enabled taxonomies,
 * also includes directly configured term inclusions. Hidden terms are filtered
 * out for users who cannot see them. Returns an empty array when there is
 * nothing to include.
 *
 * Each entry is a term stub (id, name, slug, taxonomy, link) carrying a `strength`
 * — the explicit relationship weight by which it relates to this term (hierarchy
 * weight for WP descendants, container weight for inclusions) — so cross-site
 * consumers can weight each included term as HyperTaxonomy's own index does.
 * Mutual inclusions also carry `synonym: true`.
 *
 * @param WP_Term $term Term object.
 * @return array Array of extended term stubs.
 */
function htax_rest_build_includes_data( WP_Term $term ): array {
    $taxonomy       = $term->taxonomy;
    $term_id        = $term->term_id;
    $items          = array();
    $can_see_hidden = htax_can_see_hidden_terms( $taxonomy );
    $hidden_set     = null; // Lazy-loaded on first visibility check.

    // Explicit relationship weights for this taxonomy, exposed per entry as the
    // strength by which each included term relates (the same weights baked into the
    // search index). Falls back to the documented defaults.
    $weights     = function_exists( 'htax_get_relevance_weights' ) ? htax_get_relevance_weights( $taxonomy ) : array();
    $w_hierarchy = isset( $weights['hierarchy']['explicit'] ) ? round( (float) $weights['hierarchy']['explicit'], 4 ) : 0.7;
    $w_container = isset( $weights['container']['explicit'] ) ? round( (float) $weights['container']['explicit'], 4 ) : 0.75;

    // All WP descendants for hierarchical taxonomies.
    if ( is_taxonomy_hierarchical( $taxonomy ) ) {
        $children = get_term_children( $term_id, $taxonomy );
        if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
            if ( ! $can_see_hidden ) {
                $hidden_set = array_flip( htax_get_all_hidden_term_ids() );
                $children   = array_values( array_filter(
                    $children,
                    function ( $id ) use ( $hidden_set ) {
                        return ! isset( $hidden_set[ $id ] );
                    }
                ) );
            }
            foreach ( $children as $child_id ) {
                $child = get_term( $child_id );
                if ( ! $child || is_wp_error( $child ) ) {
                    continue;
                }
                $stub               = htax_rest_term_stub( $child );
                $stub['strength']   = $w_hierarchy;
                $items[ $child_id ] = $stub;
            }
        }
    }

    // Direct HT inclusions for HT-enabled taxonomies.
    if ( htax_is_taxonomy_enabled( $taxonomy ) ) {
        // Pre-load synonym IDs so mutual inclusions can be flagged.
        // htax_get_synonym_terms() is statically cached — no extra SQL after the first call.
        $synonym_ids = array_keys( htax_get_synonym_terms( $term ) );

        foreach ( htax_get_included_terms( $term ) as $included_id => $included_term ) {
            if ( ! $can_see_hidden ) {
                if ( null === $hidden_set ) {
                    $hidden_set = array_flip( htax_get_all_hidden_term_ids() );
                }
                if ( isset( $hidden_set[ $included_id ] ) ) {
                    continue;
                }
            }
            $stub             = htax_rest_term_stub( $included_term );
            $stub['strength'] = $w_container;
            if ( in_array( $included_id, $synonym_ids, true ) ) {
                $stub['synonym'] = true;
            }
            $items[ $included_id ] = $stub;
        }
    }

    return array_values( $items );
}

/**
 * Build a single term entry for htax_term_data: id, slug, name, taxonomy.
 *
 * @since 1.0.0
 * @param WP_Term $term Term object.
 * @return array
 */
function htax_rest_build_term_entry( WP_Term $term ): array {
    return array(
        'id'       => $term->term_id,
        'slug'     => $term->slug,
        'name'     => $term->name,
        'taxonomy' => $term->taxonomy,
    );
}

/**
 * Build htax_synonyms REST field data for a term.
 *
 * Returns an array of term entries for all synonym terms (mutual inclusions).
 * Each entry has the same shape as htax_term_data entries: id, slug, name,
 * taxonomy, and htax_links when external identifiers are set. Hidden synonym
 * terms are filtered out for users who cannot see them.
 *
 * Returns null when the term has no synonyms or is not HT-enabled.
 *
 * @since 1.0.0
 * @param WP_Term $term Term object.
 * @return array|null Array of synonym term entries, or null.
 */
function htax_rest_build_synonyms_data( WP_Term $term ): ?array {
    if ( ! htax_is_taxonomy_enabled( $term->taxonomy ) ) {
        return null;
    }

    $synonym_terms = htax_get_synonym_terms( $term );
    if ( empty( $synonym_terms ) ) {
        return null;
    }

    $can_see_hidden = htax_can_see_hidden_terms( $term->taxonomy );
    $hidden_set     = $can_see_hidden ? array() : array_flip( htax_get_all_hidden_term_ids() );

    $result = array();
    foreach ( $synonym_terms as $syn_id => $syn_term ) {
        if ( ! $can_see_hidden && isset( $hidden_set[ $syn_id ] ) ) {
            continue;
        }
        $result[] = htax_rest_build_term_entry( $syn_term );
    }

    return empty( $result ) ? null : $result;
}


/**
 * Build the htax_relationships array for a term: effective outbound relationships
 * for all storage-only custom types (core_type = null, non-private).
 *
 * Inclusion-based custom types (core_type = 'includes') are excluded — they are
 * delivered via htax_includes as plain term stubs.
 *
 * @since 1.0.0
 * @param WP_Term $term Source term.
 * @return array[] Each entry: { relationship_type, target_slug, target_taxonomy }
 */
function htax_rest_build_relationships_data( WP_Term $term ): array {
    $result = array();

    foreach ( htax_get_registered_relationship_types( true ) as $slug => $def ) {
        if ( ! empty( $def['is_core'] ) ) {
            continue;
        }
        if ( null !== ( $def['core_type'] ?? null ) ) {
            continue; // Inclusion-based — travels via htax_includes.
        }
        $related = htax_get_related_terms( $term->term_id, $slug );
        foreach ( $related as $related_term ) {
            $result[] = array(
                'relationship_type' => $slug,
                'target_slug'       => $related_term->slug,
                'target_taxonomy'   => $related_term->taxonomy,
            );
        }
    }

    return $result;
}

// ==============================================================================
// FILTERS
// ==============================================================================

/**
 * Exclude hidden terms from REST API collection responses.
 *
 * Hooked to rest_{taxonomy}_query for all public show_in_rest taxonomies.
 *
 * @since 1.0.0
 * @param array           $args    Query arguments.
 * @param WP_REST_Request $request REST request object.
 * @return array Modified query arguments.
 */
function htax_filter_rest_terms_query( $args, $request ) {
    $t0       = microtime( true );
    $taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : '';
    if ( is_array( $taxonomy ) ) {
        $taxonomy = reset( $taxonomy );
    }

    if ( htax_can_see_hidden_terms( $taxonomy ) ) {
        htax_trace( "TERMS_QUERY tax={$taxonomy} visible_to_user skip_exclude " . round( ( microtime( true ) - $t0 ) * 1000 ) . 'ms' );
        return $args;
    }

    $hidden_term_ids = htax_get_all_hidden_term_ids();

    if ( ! empty( $hidden_term_ids ) ) {
        $existing_exclude = isset( $args['exclude'] ) ? (array) $args['exclude'] : array();
        $args['exclude']  = array_unique( array_merge( $existing_exclude, $hidden_term_ids ) );
    }

    htax_trace( "TERMS_QUERY tax={$taxonomy} exclude_n=" . count( $args['exclude'] ?? array() ) . ' ' . round( ( microtime( true ) - $t0 ) * 1000 ) . 'ms' );
    return $args;
}

/**
 * Enrich single-term REST responses with HT fields and block hidden terms.
 *
 * Hooked to rest_prepare_{taxonomy} for all public show_in_rest taxonomies.
 *
 * Hidden terms always return a 404 for users who cannot see them.
 *
 * The following sparse fields are added when data exists:
 *   htax_is_hidden  bool   true when the term is hidden (authorized users only).
 *   htax_icon       object Icon URLs + dimensions at small/medium/large.
 *   htax_redirect   object Stub of the term this term redirects to.
 *   htax_includes   array  WP descendants + direct HT inclusions (stubs).
 *                          Mutual inclusions carry "synonym": true.
 *                          Present for all hierarchical taxonomies, not just
 *                          HT-enabled ones, so headless clients can reproduce
 *                          WP_Query's include_children behavior.
 *   htax_synonyms   array  Synonym terms (mutual inclusions) as term entries.
 *                          Subset of htax_includes flagged entries, exposed
 *                          as a dedicated field for direct access.
 *
 * @since 1.0.0
 * @param WP_REST_Response $response The response object.
 * @param WP_Term          $term     The term object.
 * @param WP_REST_Request  $request  The request object.
 * @return WP_REST_Response|WP_Error
 */
function htax_filter_rest_prepare_term( $response, $term, $request = null ) {
    $t0 = microtime( true );

    // Memoize capability check per taxonomy for the lifetime of this request: the
    // result never changes mid-request, and the filter fires once per term in a collection.
    static $can_see_cache = array();
    $taxonomy = $term->taxonomy;
    if ( ! isset( $can_see_cache[ $taxonomy ] ) ) {
        $can_see_cache[ $taxonomy ] = htax_can_see_hidden_terms( $taxonomy );
    }
    $can_see_hidden = $can_see_cache[ $taxonomy ];

    // Single transient read for the full hidden-term set, then O(1) isset per term.
    static $hidden_id_set = null;
    if ( null === $hidden_id_set ) {
        $hidden_id_set = array_flip( htax_get_all_hidden_term_ids() );
    }
    $is_hidden = isset( $hidden_id_set[ (int) $term->term_id ] );

    // Block hidden terms for unauthorized users — always enforced regardless of context.
    if ( ! $can_see_hidden && $is_hidden ) {
        htax_trace( "PREPARE_TERM tid={$term->term_id} tax={$taxonomy} HIDDEN_BLOCKED " . round( ( microtime( true ) - $t0 ) * 1000 ) . 'ms' );
        return new WP_Error(
            'rest_term_invalid',
            __( 'Term not found.', 'hypertaxonomy' ),
            array( 'status' => 404 )
        );
    }

    // No HT fields on the internal term groups taxonomy.
    if ( 'group' === $taxonomy ) {
        return $response;
    }

    // Skip enrichment for collection requests (sidebar panels need only name/count)
    // and for Gutenberg individual requests (wp.apiFetch adds _locale=user; the block
    // editor reads all HT data from htaxTermMeta, not from the REST response).
    //
    // Exception: collection requests from HT/TC peer sites (identified by the
    // X-HyperTaxonomy request header) receive a lightweight peer payload containing
    // htax_redirect and htax_includes — needed for identity delegation and relationship
    // sync. The full enrichment (icons, synonyms) is still skipped for performance.
    $is_collection = $request && ! preg_match( '/\/\d+$/', $request->get_route() );
    $is_gutenberg  = ! $is_collection && $request && 'user' === $request->get_param( '_locale' );
    $is_peer       = $is_collection && $request && '' !== (string) $request->get_header( 'x-hypertaxonomy' );

    if ( $is_gutenberg ) {
        htax_trace( "PREPARE_TERM tid={$term->term_id} tax={$taxonomy} GUTENBERG " . round( ( microtime( true ) - $t0 ) * 1000 ) . 'ms' );
        return $response;
    }

    if ( $is_collection && ! $is_peer ) {
        htax_trace( "PREPARE_TERM tid={$term->term_id} tax={$taxonomy} COLLECTION " . round( ( microtime( true ) - $t0 ) * 1000 ) . 'ms' );
        return $response;
    }

    if ( $is_peer ) {
        $data = $response->get_data();

        if ( htax_rest_field_requested( $request, 'htax_redirect' ) ) {
            $redirect_data = htax_rest_build_redirect_data( $term );
            if ( null !== $redirect_data ) {
                $data['htax_redirect'] = $redirect_data;
            }
        }

        if ( htax_rest_field_requested( $request, 'htax_includes' ) ) {
            $includes_data = htax_rest_build_includes_data( $term );
            if ( ! empty( $includes_data ) ) {
                $data['htax_includes'] = $includes_data;
            }
        }

        if ( htax_rest_field_requested( $request, 'htax_relationships' ) ) {
            $relationships = htax_rest_build_relationships_data( $term );
            if ( ! empty( $relationships ) ) {
                $data['htax_relationships'] = $relationships;
            }
        }

        if ( $is_hidden && htax_rest_field_requested( $request, 'htax_is_hidden' ) ) {
            $data['htax_is_hidden'] = true;
        }

        if ( htax_rest_field_requested( $request, 'htax_icon' ) ) {
            $icon_data = htax_rest_build_icon_data( $term );
            if ( null !== $icon_data ) {
                $data['htax_icon'] = $icon_data;
            }
        }

        if ( htax_rest_field_requested( $request, 'htax_synonyms' ) ) {
            $synonyms_data = htax_rest_build_synonyms_data( $term );
            if ( null !== $synonyms_data ) {
                $data['htax_synonyms'] = $synonyms_data;
            }
        }

        if ( htax_rest_field_requested( $request, 'htax_locations' )
             && htax_taxonomy_has_feature( $taxonomy, 'share_locations' )
             && method_exists( 'ATCF_REST_API', 'format_term_locations' ) ) {
            $formatted = ATCF_REST_API::format_term_locations( (int) $term->term_id );
            if ( null !== $formatted ) {
                $data['htax_locations'] = $formatted;
            }
        }

        $response->set_data( $data );
        htax_trace( "PREPARE_TERM tid={$term->term_id} tax={$taxonomy} PEER_COLLECTION " . round( ( microtime( true ) - $t0 ) * 1000 ) . 'ms' );
        return $response;
    }

    $data = $response->get_data();

    if ( $is_hidden && htax_rest_field_requested( $request, 'htax_is_hidden' ) ) {
        $data['htax_is_hidden'] = true;
    }

    if ( htax_rest_field_requested( $request, 'htax_icon' ) ) {
        $icon_data = htax_rest_build_icon_data( $term );
        if ( null !== $icon_data ) {
            $data['htax_icon'] = $icon_data;
        }
    }

    if ( htax_rest_field_requested( $request, 'htax_redirect' ) ) {
        $redirect_data = htax_rest_build_redirect_data( $term );
        if ( null !== $redirect_data ) {
            $data['htax_redirect'] = $redirect_data;
        }
    }

    if ( htax_rest_field_requested( $request, 'htax_includes' ) ) {
        $includes_data = htax_rest_build_includes_data( $term );
        if ( ! empty( $includes_data ) ) {
            $data['htax_includes'] = $includes_data;
        }
    }

    if ( htax_rest_field_requested( $request, 'htax_synonyms' ) ) {
        $synonyms_data = htax_rest_build_synonyms_data( $term );
        if ( null !== $synonyms_data ) {
            $data['htax_synonyms'] = $synonyms_data;
        }
    }

    if ( htax_rest_field_requested( $request, 'htax_locations' )
         && htax_taxonomy_has_feature( $taxonomy, 'share_locations' )
         && method_exists( 'ATCF_REST_API', 'format_term_locations' ) ) {
        $formatted = ATCF_REST_API::format_term_locations( (int) $term->term_id );
        if ( null !== $formatted ) {
            $data['htax_locations'] = $formatted;
        }
    }

    if ( htax_rest_field_requested( $request, 'htax_relationships' ) ) {
        $relationships = htax_rest_build_relationships_data( $term );
        if ( ! empty( $relationships ) ) {
            $data['htax_relationships'] = $relationships;
        }
    }

    $response->set_data( $data );
    htax_trace( "PREPARE_TERM tid={$term->term_id} tax={$taxonomy} INDIVIDUAL " . round( ( microtime( true ) - $t0 ) * 1000 ) . 'ms' );
    return $response;
}

/**
 * Register REST API filters for all public show_in_rest taxonomies.
 *
 * @since 1.0.0
 */
function htax_register_rest_term_filters() {
    $taxonomies = get_taxonomies( array( 'public' => true, 'show_in_rest' => true ), 'names' );

    foreach ( $taxonomies as $taxonomy ) {
        add_filter( "rest_{$taxonomy}_query",   'htax_filter_rest_terms_query',  10, 2 );
        add_filter( "rest_prepare_{$taxonomy}", 'htax_filter_rest_prepare_term', 10, 3 );
    }

    // Also cover the internal term groups taxonomy.
    add_filter( 'rest_htax_term_group_query',   'htax_filter_rest_terms_query',  10, 2 );
    add_filter( 'rest_prepare_htax_term_group', 'htax_filter_rest_prepare_term', 10, 3 );
}

// ==============================================================================
// POSTS ENDPOINT EXTENSIONS
// ==============================================================================

// Request-scoped scoring state. Populated by htax_rest_apply_htax_post_query(),
// consumed by the htax_score / htax_matched_term_slugs / htax_term_peers field callbacks.
$_htax_rest_post_scores    = array();
$_htax_rest_matched_slugs  = array();
$_htax_rest_post_term_map  = array(); // post_id => int[] of all term IDs on that post
$_htax_rest_scoring_active = false;

/**
 * Add X-HyperTaxonomy version header to all WordPress REST API responses.
 *
 * Requesting peer sites check this header (via HEAD /wp-json/) to detect that
 * HyperTaxonomy is active and htax_* query params are supported.
 *
 * @since 1.0.0
 * @param mixed $result REST response (may be WP_REST_Response, WP_HTTP_Response, WP_Error, or array).
 * @return mixed The result, unchanged.
 */
function htax_rest_add_version_header( $result ) {
    if ( $result instanceof WP_REST_Response ) {
        $result->header( 'X-HyperTaxonomy', HTAX_VERSION );
    }
    return $result;
}

/**
 * REST getter for `htax_term_strengths`: a compact map of the post's directly-
 * assigned term IDs to their association strength (the direct-row score of the
 * selected strength model — how central each term is to the post; terms the model
 * has not scored are omitted). Keyed by the same term IDs the post
 * already lists natively (tags/categories), so it adds the strength attribute
 * without duplicating term data. Hidden terms are filtered for the current user.
 *
 * Opt-in: computed only when explicitly requested via `_fields`. On a full
 * response (no `_fields`) WordPress would call this for every post, so it returns
 * null there without touching the database — the per-post index lookup runs only
 * when a consumer asks for the field.
 *
 * @since 1.0.0
 * @param array           $post_arr Post data array (provides 'id').
 * @param string          $field    Field name (unused).
 * @param WP_REST_Request $request  REST request (for the _fields opt-in check).
 * @return object|null Map of term_id => strength, or null when not requested.
 */
function htax_rest_get_post_term_strengths( $post_arr, $field = '', $request = null ) {
    $fields = ( $request instanceof WP_REST_Request ) ? $request->get_param( '_fields' ) : '';
    if ( empty( $fields ) ) {
        return null; // Not explicitly requested — skip the per-post query.
    }

    global $wpdb;
    $post_id = (int) ( $post_arr['id'] ?? 0 );
    if ( $post_id <= 0 ) {
        return (object) array();
    }

    $st   = $wpdb->prefix . 'htax_search_terms';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, taxonomy, strength, is_hidden
         FROM {$st}
         WHERE post_id = %d AND relationship_type = 'direct'" . htax_strength_model_sql(),
        $post_id
    ) );
    if ( empty( $rows ) ) {
        return (object) array();
    }

    $can_see = array();
    $out     = array();
    foreach ( $rows as $r ) {
        $tax = (string) $r->taxonomy;
        if ( ! isset( $can_see[ $tax ] ) ) {
            $can_see[ $tax ] = htax_can_see_hidden_terms( $tax );
        }
        if ( (int) $r->is_hidden && ! $can_see[ $tax ] ) {
            continue;
        }
        $out[ (int) $r->term_id ] = round( (float) $r->strength, 4 );
    }

    return (object) $out;
}

/**
 * Register htax_* query parameters and htax_score/htax_matched_term_slugs
 * fields on the posts REST endpoint, and wire up the scoring query filter.
 *
 * @since 1.0.0
 */
function htax_rest_register_post_extensions(): void {
    add_filter( 'rest_post_collection_params', 'htax_rest_register_post_htax_params', 10, 2 );
    add_filter( 'rest_post_query', 'htax_rest_apply_htax_post_query', 10, 2 );

    register_rest_field(
        'post',
        'htax_score',
        array(
            'get_callback'    => 'htax_rest_get_post_score',
            'update_callback' => null,
            'schema'          => array(
                'description' => 'HyperTaxonomy relevance score (0–1). Present only on scored responses (htax_includes param required).',
                'type'        => array( 'number', 'null' ),
                'context'     => array( 'view' ),
                'readonly'    => true,
            ),
        )
    );

    register_rest_field(
        'post',
        'htax_matched_term_slugs',
        array(
            'get_callback'    => 'htax_rest_get_post_matched_slugs',
            'update_callback' => null,
            'schema'          => array(
                'description' => 'Slugs of matched terms from htax_includes. Absent on non-scored responses.',
                'type'        => array( 'array', 'null' ),
                'items'       => array( 'type' => 'string' ),
                'context'     => array( 'view' ),
                'readonly'    => true,
            ),
        )
    );

    register_rest_field(
        'post',
        'htax_term_strengths',
        array(
            'get_callback'    => 'htax_rest_get_post_term_strengths',
            'update_callback' => null,
            'schema'          => array(
                'description' => 'Opt-in (request via _fields). Map of the post\'s directly-assigned term IDs to their association strength (0–1 centrality, from the selected strength model; unscored terms omitted). Visibility-filtered; null unless explicitly requested.',
                'type'        => array( 'object', 'null' ),
                'context'     => array( 'view' ),
                'readonly'    => true,
            ),
        )
    );

}

/**
 * Register htax_includes, htax_match, and htax_weights as accepted query params
 * on the posts collection endpoint.
 *
 * @since 1.0.0
 * @param array               $params    Registered collection params.
 * @param WP_Post_Type|string $post_type Post type object or slug.
 * @return array
 */
function htax_rest_register_post_htax_params( array $params, $post_type ): array {
    $slug = $post_type instanceof WP_Post_Type ? $post_type->name : $post_type;
    if ( 'post' !== $slug ) {
        return $params;
    }

    $params['htax_includes'] = array(
        'description' => 'Term structure: { primary_term_id: "include_id1,include_id2" }. Keys are primary term IDs; values are CSV of semantically included term IDs. Defines which terms count and the AND groups; weights come from htax_term_weights.',
        'type'        => 'object',
        'default'     => array(),
    );
    $params['htax_match']    = array(
        'description' => 'Match mode: any (OR) or all (AND) — each primary term in htax_includes must have at least one match.',
        'type'        => 'string',
        'enum'        => array( 'any', 'all' ),
        'default'     => 'any',
    );
    $params['htax_term_weights'] = array(
        'description' => 'Per-term scoring weights: { term_id: weight } (the requester has already applied its own relationship weighting). Sole weight source; any matched term not listed scores 1.0.',
        'type'        => 'object',
        'default'     => array(),
    );
    $params['htax_expand'] = array(
        'description' => 'When true, also match each primary term via this site\'s own relationship graph (not just direct assignments), with non-direct matches discounted. Off by default.',
        'type'        => 'boolean',
        'default'     => false,
    );

    // Extend the built-in orderby enum to include 'score'.
    // Without this, WordPress rejects orderby=score with a 400 before the
    // htax_rest_apply_htax_post_query filter gets a chance to handle it.
    if ( isset( $params['orderby']['enum'] ) && ! in_array( 'score', $params['orderby']['enum'], true ) ) {
        $params['orderby']['enum'][] = 'score';
    }

    return $params;
}

/**
 * Apply htax_* scoring to a posts REST query when htax_includes are present.
 *
 * Reads primary and included term IDs from htax_includes, queries the HT search
 * index, scores and optionally filters posts, then restricts the WP_Query via
 * post__in. The standard taxonomy params (?tags=, ?categories=) remain in effect
 * and AND with post__in, so results always satisfy both constraints.
 *
 * Per-term weights come from htax_term_weights (default 1.0). With htax_expand,
 * primaries are also matched via this site's own relationship graph, discounted.
 * Score formula: raw = sum(best weight per matched term); normalized to
 * raw / (sum of primary weights), clamped to [0, 1].
 *
 * @since 1.0.0
 * @param array           $args    WP_Query arguments.
 * @param WP_REST_Request $request REST request.
 * @return array Modified WP_Query arguments.
 */
function htax_rest_apply_htax_post_query( array $args, WP_REST_Request $request ): array {
    global $wpdb, $_htax_rest_post_scores, $_htax_rest_matched_slugs, $_htax_rest_post_term_map, $_htax_rest_scoring_active;

    $_htax_rest_post_scores    = array();
    $_htax_rest_matched_slugs  = array();
    $_htax_rest_post_term_map  = array();
    $_htax_rest_scoring_active = false;

    $htax_includes = $request->get_param( 'htax_includes' );
    if ( empty( $htax_includes ) || ! is_array( $htax_includes ) ) {
        return $args;
    }

    $raw_match    = $request->get_param( 'htax_match' );
    $htax_match   = $raw_match ? $raw_match : 'any';
    // Per-term weight map supplied by the requester (it has already applied its
    // own relationship weighting). This is the sole weight source; any requested
    // term not listed here defaults to 1.0.
    $raw_term_weights = $request->get_param( 'htax_term_weights' );
    $term_weights_in  = array();
    if ( is_array( $raw_term_weights ) ) {
        foreach ( $raw_term_weights as $tid => $wt ) {
            $tid = (int) $tid;
            $wt  = (float) $wt;
            if ( $tid > 0 && $wt > 0 ) {
                $term_weights_in[ $tid ] = $wt;
            }
        }
    }

    // Re-expand primaries through this site's own relationship graph when requested.
    $htax_expand     = rest_sanitize_boolean( $request->get_param( 'htax_expand' ) );
    $expand_discount = 0.6; // Non-direct (peer-inferred) matches contribute at this fraction.

    // Parse htax_includes into the scored term set and AND group list. Keys are
    // primary term IDs; values are CSV of included term IDs. Weights come solely
    // from htax_term_weights (default 1.0 for any term not listed).
    $weight_of = static function ( $tid ) use ( $term_weights_in ) {
        return isset( $term_weights_in[ $tid ] ) ? (float) $term_weights_in[ $tid ] : 1.0;
    };

    $term_weight_map = array();
    $term_groups     = array();
    $primary_ids     = array();

    // Pass 1: primary term IDs.
    $raw_groups = array();
    foreach ( $htax_includes as $primary_str => $includes_csv ) {
        $primary_id = (int) $primary_str;
        if ( $primary_id <= 0 ) {
            continue;
        }
        $term_weight_map[ $primary_id ] = $weight_of( $primary_id );
        $primary_ids[ $primary_id ]     = true;
        $raw_groups[]                   = array( 'primary_id' => $primary_id, 'csv' => (string) $includes_csv );
    }

    // Pass 2: included term IDs not already registered as primaries.
    foreach ( $raw_groups as $raw ) {
        $include_ids = array();
        if ( '' !== $raw['csv'] ) {
            foreach ( explode( ',', $raw['csv'] ) as $inc_str ) {
                $inc_id = (int) trim( $inc_str );
                if ( $inc_id > 0 && ! isset( $term_weight_map[ $inc_id ] ) ) {
                    $term_weight_map[ $inc_id ] = $weight_of( $inc_id );
                    $include_ids[]              = $inc_id;
                }
            }
        }
        $term_groups[] = array(
            'primary_id'  => $raw['primary_id'],
            'include_ids' => $include_ids,
        );
    }

    if ( empty( $term_weight_map ) ) {
        $args['post__in'] = array( 0 );
        return $args;
    }

    $all_term_ids = array_keys( $term_weight_map );

    // Build term_id => slug map for the htax_matched_term_slugs response field.
    $term_slugs = array();
    foreach ( $all_term_ids as $tid ) {
        $t                  = get_term( $tid );
        $term_slugs[ $tid ] = ( $t instanceof WP_Term ) ? $t->slug : (string) $tid;
    }

    // Query the search index for posts directly tagged with any of the requested terms.
    // 'direct' only: the requesting site's includes list already encodes its own HT
    // expansion; expanding again here would double-count.
    $table        = $wpdb->prefix . 'htax_search_terms';
    $placeholders = htax_sql_placeholders( count( $all_term_ids ) );

    // Default: direct assignments only (the requester's own includes are already
    // encoded in the request). Expand mode also matches each primary via this
    // site's relationship graph (hierarchy/container/redirect), discounted below.
    $rel_sql = $htax_expand
        ? "relationship_type IN ('direct','hierarchy','container','redirect_target')"
        : "relationship_type = 'direct'";

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT post_id, term_id, relationship_type
             FROM {$table}
             WHERE term_id IN ($placeholders)
               AND {$rel_sql}
               AND is_hidden = 0",
            $all_term_ids
        ),
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        $args['post__in'] = array( 0 );
        return $args;
    }

    $n_groups        = count( $term_groups );
    $post_term_best  = array(); // post_id => [ term_id => best weight ]
    $post_group_hits = array(); // post_id => bool[] keyed by group index

    foreach ( $rows as $row ) {
        $post_id = (int) $row['post_id'];
        $term_id = (int) $row['term_id'];

        if ( ! isset( $term_weight_map[ $term_id ] ) ) {
            continue;
        }

        $is_direct = ( 'direct' === $row['relationship_type'] );
        if ( ! $is_direct ) {
            // Non-direct (re-expanded) matches count only for primary terms, and
            // only in expand mode.
            if ( ! $htax_expand || ! isset( $primary_ids[ $term_id ] ) ) {
                continue;
            }
        }

        $weight = $is_direct
            ? (float) $term_weight_map[ $term_id ]
            : (float) $term_weight_map[ $term_id ] * $expand_discount;

        if ( ! isset( $post_group_hits[ $post_id ] ) ) {
            $post_group_hits[ $post_id ] = array_fill( 0, $n_groups, false );
        }

        // Keep the highest weight per (post, term): a direct hit beats a
        // discounted re-expansion of the same term.
        if ( ! isset( $post_term_best[ $post_id ][ $term_id ] ) || $weight > $post_term_best[ $post_id ][ $term_id ] ) {
            $post_term_best[ $post_id ][ $term_id ] = $weight;
        }

        foreach ( $term_groups as $gi => $group ) {
            if ( $term_id === $group['primary_id'] || in_array( $term_id, $group['include_ids'], true ) ) {
                $post_group_hits[ $post_id ][ $gi ] = true;
            }
        }
    }

    // Collapse per-term best weights into raw scores and matched slug lists.
    $post_raw_scores    = array();
    $post_matched_slugs = array();
    foreach ( $post_term_best as $post_id => $terms ) {
        $sum   = 0.0;
        $slugs = array();
        foreach ( $terms as $tid => $w ) {
            $sum    += $w;
            $slugs[] = $term_slugs[ $tid ] ?? (string) $tid;
        }
        $post_raw_scores[ $post_id ]    = $sum;
        $post_matched_slugs[ $post_id ] = $slugs;
    }

    // AND mode: remove posts missing a match in any primary term group.
    if ( 'all' === $htax_match ) {
        foreach ( array_keys( $post_raw_scores ) as $post_id ) {
            if ( in_array( false, $post_group_hits[ $post_id ], true ) ) {
                unset( $post_raw_scores[ $post_id ], $post_matched_slugs[ $post_id ] );
            }
        }
    }

    if ( empty( $post_raw_scores ) ) {
        $args['post__in'] = array( 0 );
        return $args;
    }

    // Normalize against the max possible raw = sum of each group's primary weight
    // (every primary contributing its own weight; missing weights default to 1.0).
    $max_possible = 0.0;
    foreach ( $term_groups as $group ) {
        $pid           = $group['primary_id'];
        $max_possible += isset( $term_weight_map[ $pid ] ) ? (float) $term_weight_map[ $pid ] : 1.0;
    }
    $max_possible = max( $max_possible, 0.001 );
    $normalized   = array();
    foreach ( $post_raw_scores as $post_id => $raw ) {
        $normalized[ $post_id ] = min( round( $raw / $max_possible, 4 ), 1.0 );
    }

    $_htax_rest_post_scores    = $normalized;
    $_htax_rest_matched_slugs  = $post_matched_slugs;
    $_htax_rest_scoring_active = true;

    // Pre-load all term IDs for scored posts so htax_term_peers callbacks
    // are served from the WordPress meta cache rather than making per-post queries.
    // Two queries total for the collection: one to get post→term relationships,
    // one batch meta load for all unique term IDs found.
    $scored_post_ids = array_keys( $normalized );
    if ( ! empty( $scored_post_ids ) ) {
        $id_placeholders = htax_sql_placeholders( count( $scored_post_ids ) );
        $tr_rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT tr.object_id AS post_id, tt.term_id
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id IN ($id_placeholders)",
                $scored_post_ids
            ),
            ARRAY_A
        );

        $post_term_map = array();
        $all_term_ids  = array();
        foreach ( $tr_rows as $tr ) {
            $pid                     = (int) $tr['post_id'];
            $tid                     = (int) $tr['term_id'];
            $post_term_map[ $pid ][] = $tid;
            $all_term_ids[]          = $tid;
        }

        if ( ! empty( $all_term_ids ) ) {
            update_meta_cache( 'term', array_unique( $all_term_ids ) );
        }

        $_htax_rest_post_term_map = $post_term_map;
    }

    if ( 'score' === $request->get_param( 'orderby' ) ) {
        arsort( $normalized );
        $args['post__in'] = array_keys( $normalized );
        $args['orderby']  = 'post__in';
        $args['order']    = 'ASC';
    } else {
        $args['post__in'] = array_keys( $normalized );
    }

    return $args;
}

/**
 * REST field callback: return the htax_score for a post.
 *
 * Returns the normalized 0–1 relevance score when this response was scored
 * via htax_includes; returns null otherwise so the field is omitted.
 *
 * @since 1.0.0
 * @param array $post Post data array from the REST response.
 * @return float|null
 */
function htax_rest_get_post_score( array $post ): ?float {
    global $_htax_rest_post_scores, $_htax_rest_scoring_active;
    if ( ! $_htax_rest_scoring_active ) {
        return null;
    }
    return $_htax_rest_post_scores[ $post['id'] ?? 0 ] ?? 0.0;
}

/**
 * REST field callback: return htax_matched_term_slugs for a post.
 *
 * Returns the slugs of the remote terms (from htax_includes keys and values)
 * that contributed to this post's htax_score. Returns null on non-scored
 * responses so the field is omitted from the response entirely.
 *
 * @since 1.0.0
 * @param array $post Post data array from the REST response.
 * @return array|null
 */
function htax_rest_get_post_matched_slugs( array $post ): ?array {
    global $_htax_rest_matched_slugs, $_htax_rest_scoring_active;
    if ( ! $_htax_rest_scoring_active ) {
        return null;
    }
    return $_htax_rest_matched_slugs[ $post['id'] ?? 0 ] ?? array();
}



// ==============================================================================
// SITEMAPS
// ==============================================================================

/**
 * Exclude hidden term archives from XML sitemaps.
 *
 * Sitemaps are always public, so hidden terms are excluded unless the global
 * visibility setting is 'all'.
 *
 * @since 1.0.0
 * @param array  $args     Query arguments for the sitemap term query.
 * @param string $taxonomy Taxonomy name.
 * @return array Modified query arguments.
 */
function htax_filter_sitemap_terms_query( $args, $taxonomy ) {
    $options    = get_option( 'htax_settings', array() );
    $visibility = isset( $options['hidden_terms_visibility'] ) ? $options['hidden_terms_visibility'] : 'editors';

    if ( 'all' === $visibility ) {
        return $args;
    }

    $hidden_term_ids = htax_get_all_hidden_term_ids();

    if ( ! empty( $hidden_term_ids ) ) {
        $existing_exclude = isset( $args['exclude'] ) ? (array) $args['exclude'] : array();
        $args['exclude']  = array_unique( array_merge( $existing_exclude, $hidden_term_ids ) );
    }

    return $args;
}


