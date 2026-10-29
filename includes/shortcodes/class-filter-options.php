<?php
/**
 * Filter Options Shortcodes
 *
 * [ht_filter_options]        — Parent container: collects child filter configs and renders
 *                              the complete filter panel.
 * [ht_filter_terms]          — Term filter child: pills or checklist for a single taxonomy.
 * [ht_filter_date_published] — Date published filter child: single or range date picker.
 * [ht_filter_date_modified]  — Date modified filter child: single or range date picker.
 * [ht_filter_author]         — Author filter child: checklist of post authors.
 *
 * Also provides htax_render_filter_options() for inline use by result shortcodes.
 *
 * Filter state encoding
 * ---------------------
 * Navigate mode (URL): ?htax_filter[post_tag]=5,12&htax_filter[category]=3
 *                       &htax_filter[date_from]=2024-01-01&htax_filter[date_to]=2024-12-31
 *                       &htax_filter[date_modified_from]=...&htax_filter[date_modified_to]=...
 *                       &htax_filter[author]=1,2
 *
 * AJAX mode (data-filter on results wrapper): JSON object with the same keys.
 * Term entries include match type: {"post_tag":{"match":"any","terms":[5,12]},...}
 *
 * In navigate mode all term filters use match="any". Submit and event (AJAX) modes
 * support all three match types.
 *
 * Primary filter default
 * ----------------------
 * If no [htax_filter_terms] has primary="true", the first one is treated as primary.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ===========================================================================
// Static collector for child shortcode configs
// ===========================================================================

/** @var array[]|null Collected child configs during a [htax_filter_options] render. null = not collecting. */
$GLOBALS['_htax_filter_collector'] = null;

/**
 * Push a child filter config into the active collector.
 *
 * Called by each child shortcode. No-op if no collector is active
 * (i.e., the child is used outside a parent [htax_filter_options]).
 *
 * @param array $config Normalized child config.
 */
function htax_filter_collect( array $config ) {
    if ( is_array( $GLOBALS['_htax_filter_collector'] ) ) {
        $GLOBALS['_htax_filter_collector'][] = $config;
    }
}

// ===========================================================================
// Child shortcodes
// ===========================================================================

/**
 * [ht_filter_terms] — Term filter child.
 *
 * Attributes:
 *   taxonomy              Taxonomy slug. Required.
 *   label                 Display label. Default: taxonomy label.
 *   type                  'pills' | 'checklist'. Default: auto (pills for flat, checklist for hierarchical).
 *   match                 'any' | 'all' | 'none'. Default: 'any'.
 *   primary               'true' | 'false'. Default: 'false'. See primary filter rules in plan.
 *   include_group_options Group IDs — option pool definition.
 *   exclude_group_options Group IDs — option pool definition.
 *   include_term_options  Term IDs  — option pool definition.
 *   exclude_term_options  Term IDs  — option pool definition.
 *   include_groups        Group IDs — initial selection.
 *   exclude_groups        Group IDs — initial selection.
 *   include_terms         Term IDs  — initial selection.
 *   exclude_terms         Term IDs  — initial selection.
 */
function htax_filter_terms_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'taxonomy'             => '',
        'label'                => '',
        'type'                 => '',       // '' = auto-detect
        'match'                => 'any',
        'primary'              => 'false',
        'include_group_options' => '',
        'exclude_group_options' => '',
        'include_term_options'  => '',
        'exclude_term_options'  => '',
        'include_groups'       => '',
        'exclude_groups'       => '',
        'include_terms'        => '',
        'exclude_terms'        => '',
    ), $atts, 'ht_filter_terms' );

    $taxonomy = sanitize_key( $atts['taxonomy'] );
    if ( ! $taxonomy ) {
        return '';
    }

    $match = in_array( $atts['match'], array( 'any', 'all', 'none' ), true ) ? $atts['match'] : 'any';

    // Validate: primary=true is incompatible with match=none
    $is_primary = ( 'true' === $atts['primary'] && 'none' !== $match );

    htax_filter_collect( array(
        'type'                 => 'terms',
        'taxonomy'             => $taxonomy,
        'label'                => sanitize_text_field( $atts['label'] ),
        'display_type'         => in_array( $atts['type'], array( 'pills', 'checklist' ), true ) ? $atts['type'] : '',
        'match'                => $match,
        'primary'              => $is_primary,
        'include_group_options' => $atts['include_group_options'],
        'exclude_group_options' => $atts['exclude_group_options'],
        'include_term_options'  => $atts['include_term_options'],
        'exclude_term_options'  => $atts['exclude_term_options'],
        'include_groups'       => $atts['include_groups'],
        'exclude_groups'       => $atts['exclude_groups'],
        'include_terms'        => $atts['include_terms'],
        'exclude_terms'        => $atts['exclude_terms'],
    ) );

    return '';
}
add_shortcode( 'ht_filter_terms', 'htax_filter_terms_shortcode' );

/**
 * [ht_filter_date_published] — Date published filter child.
 *
 * Attributes:
 *   label   Display label. Default: 'Date'.
 *   type    'range' | 'single'. Default: 'range'.
 */
function htax_filter_date_published_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'label' => __( 'Date', 'hypertaxonomy' ),
        'type'  => 'range',
    ), $atts, 'ht_filter_date_published' );

    htax_filter_collect( array(
        'type'      => 'date_published',
        'label'     => sanitize_text_field( $atts['label'] ),
        'date_type' => 'single' === $atts['type'] ? 'single' : 'range',
    ) );

    return '';
}
add_shortcode( 'ht_filter_date_published', 'htax_filter_date_published_shortcode' );

/**
 * [ht_filter_date_modified] — Date modified filter child.
 *
 * Attributes:
 *   label   Display label. Default: 'Modified'.
 *   type    'range' | 'single'. Default: 'range'.
 */
function htax_filter_date_modified_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'label' => __( 'Modified', 'hypertaxonomy' ),
        'type'  => 'range',
    ), $atts, 'ht_filter_date_modified' );

    htax_filter_collect( array(
        'type'      => 'date_modified',
        'label'     => sanitize_text_field( $atts['label'] ),
        'date_type' => 'single' === $atts['type'] ? 'single' : 'range',
    ) );

    return '';
}
add_shortcode( 'ht_filter_date_modified', 'htax_filter_date_modified_shortcode' );

/**
 * [htax_filter_author] — Author filter child.
 *
 * Attributes:
 *   label   Display label. Default: 'Author'.
 */
function htax_filter_author_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'label' => __( 'Author', 'hypertaxonomy' ),
    ), $atts, 'ht_filter_author' );

    htax_filter_collect( array(
        'type'  => 'author',
        'label' => sanitize_text_field( $atts['label'] ),
    ) );

    return '';
}
add_shortcode( 'ht_filter_author', 'htax_filter_author_shortcode' );

// ===========================================================================
// Parent shortcode
// ===========================================================================

/**
 * [ht_filter_options] — Parent filter panel shortcode.
 *
 * Attributes:
 *   action         'navigate' | 'submit' | '' (event only). Default: 'navigate'.
 *   state          'expanded' | 'collapsed'. Default: 'expanded'.
 *   label          Toggle button label. Default: 'Filter'.
 *   text_collapsed Toggle button label when collapsed. Default: value of label.
 *   text_expanded  Toggle button label when expanded. Default: value of label.
 *   class          Extra CSS classes on the root element.
 */
function htax_filter_options_shortcode( $atts, $content = '' ) {
    $atts = shortcode_atts( array(
        'action'        => 'navigate',
        'state'         => 'expanded',
        'label'         => __( 'Filter', 'hypertaxonomy' ),
        'text_collapsed' => '',
        'text_expanded'  => '',
        'class'         => '',
    ), $atts, 'ht_filter_options' );

    if ( empty( $content ) ) {
        return '';
    }

    // Activate collector, run children, collect configs
    $GLOBALS['_htax_filter_collector'] = array();
    do_shortcode( $content );
    $raw_configs = $GLOBALS['_htax_filter_collector'];
    $GLOBALS['_htax_filter_collector'] = null;

    if ( empty( $raw_configs ) ) {
        return '';
    }

    // Read current filter state from GET params (navigate mode) or empty
    $filter_state = htax_get_filter_state_from_get();

    // Resolve configs (term pools, initial selections, author pool)
    $archive_exclude_term = htax_get_archive_exclude_term();
    $configs = htax_resolve_filter_configs( $raw_configs, $filter_state, $archive_exclude_term );

    $label           = sanitize_text_field( $atts['label'] );
    $text_collapsed  = ! empty( $atts['text_collapsed'] ) ? sanitize_text_field( $atts['text_collapsed'] ) : $label;
    $text_expanded   = ! empty( $atts['text_expanded'] )  ? sanitize_text_field( $atts['text_expanded'] )  : $label;

    return htax_render_filter_options( $configs, array(
        'action'         => $atts['action'],
        'state'          => $atts['state'],
        'text_collapsed' => $text_collapsed,
        'text_expanded'  => $text_expanded,
        'class'          => $atts['class'],
    ), $filter_state );
}
add_shortcode( 'ht_filter_options', 'htax_filter_options_shortcode' );

// ===========================================================================
// Config resolution
// ===========================================================================

/**
 * Resolve raw child configs into fully-populated filter configs.
 *
 * - Resolves option pools and initial selections for term filters.
 * - Applies archive exclusion to term pools.
 * - Assigns default-primary to the first terms filter if none has primary=true.
 * - Fetches author pool for author filters.
 *
 * @param  array      $raw_configs       Collected raw configs from child shortcodes.
 * @param  array      $filter_state      Current filter state (from GET or AJAX).
 * @param  int|null   $archive_exclude_term  Term ID to exclude from pools (archive context) or null.
 * @param  array      $primary_seed_ids  Term IDs to seed into the primary filter (simplified form).
 * @return array      Resolved configs.
 */
function htax_resolve_filter_configs( array $raw_configs, array $filter_state, $archive_exclude_term = null, array $primary_seed_ids = array() ) {
    // Determine primary: find first explicitly primary, or first terms config
    $has_explicit_primary = false;
    foreach ( $raw_configs as $cfg ) {
        if ( 'terms' === $cfg['type'] && ! empty( $cfg['primary'] ) ) {
            $has_explicit_primary = true;
            break;
        }
    }

    $first_terms_index = null;
    if ( ! $has_explicit_primary ) {
        foreach ( $raw_configs as $idx => $cfg ) {
            if ( 'terms' === $cfg['type'] ) {
                $first_terms_index = $idx;
                break;
            }
        }
    }

    $configs = array();
    foreach ( $raw_configs as $idx => $raw ) {
        if ( 'terms' === $raw['type'] ) {
            // Assign default primary to first terms config when none is explicit
            if ( ! $has_explicit_primary && $idx === $first_terms_index ) {
                $raw['primary'] = true;
            }

            $taxonomy = $raw['taxonomy'];

            // Resolve label
            $label = $raw['label'];
            if ( ! $label ) {
                $tax_obj = get_taxonomy( $taxonomy );
                $label   = $tax_obj ? $tax_obj->label : $taxonomy;
            }

            // Resolve display type
            $display_type = $raw['display_type'];
            if ( ! $display_type ) {
                $display_type = is_taxonomy_hierarchical( $taxonomy ) ? 'checklist' : 'pills';
            }

            // Resolve option pool
            $pool_ids = htax_resolve_filter_term_pool( $raw, $taxonomy );

            // Exclude archive term from pool
            if ( $archive_exclude_term ) {
                $pool_ids = array_values( array_diff( $pool_ids, array( (int) $archive_exclude_term ) ) );
            }

            // Resolve initial selection
            $initial_ids = htax_resolve_filter_initial_selection( $raw, $taxonomy );

            // Primary: seed with extra IDs and force them into the pool
            if ( ! empty( $raw['primary'] ) && ! empty( $primary_seed_ids ) ) {
                $initial_ids = array_unique( array_merge( $initial_ids, array_map( 'intval', $primary_seed_ids ) ) );
            }

            // Force initial selection IDs into the pool (primary rule)
            if ( ! empty( $raw['primary'] ) && ! empty( $initial_ids ) ) {
                $pool_ids = array_unique( array_merge( $pool_ids, $initial_ids ) );
            }

            // If pool is empty (no pool definition at all), load all terms in taxonomy
            if ( empty( $pool_ids )
                && empty( $raw['include_group_options'] )
                && empty( $raw['include_term_options'] ) ) {
                $all_terms = get_terms( array(
                    'taxonomy'               => $taxonomy,
                    'hide_empty'             => false,
                    'fields'                 => 'ids',
                    'number'                 => 500,
                    'update_term_meta_cache' => false,
                ) );
                $pool_ids = is_wp_error( $all_terms ) ? array() : array_map( 'intval', $all_terms );
            }

            // Fetch WP_Term objects for the pool
            $pool_terms = array();
            if ( ! empty( $pool_ids ) ) {
                $terms = get_terms( array(
                    'taxonomy'               => $taxonomy,
                    'include'                => $pool_ids,
                    'hide_empty'             => false,
                    'number'                 => 0,
                    'orderby'                => 'name',
                    'order'                  => 'ASC',
                    'update_term_meta_cache' => false,
                ) );
                if ( ! is_wp_error( $terms ) ) {
                    // Always exclude hidden terms from filter panel display
                    foreach ( $terms as $term ) {
                        if ( ! htax_is_term_hidden( $term->term_id ) ) {
                            $pool_terms[] = $term;
                        }
                    }
                }
            }

            // Current selection from filter state
            $current_ids = array();
            if ( isset( $filter_state['terms'][ $taxonomy ] ) ) {
                $current_ids = array_map( 'intval', (array) $filter_state['terms'][ $taxonomy ] );
            } elseif ( ! empty( $initial_ids ) ) {
                $current_ids = $initial_ids;
            }

            $configs[] = array(
                'type'         => 'terms',
                'taxonomy'     => $taxonomy,
                'label'        => $label,
                'display_type' => $display_type,
                'match'        => $raw['match'],
                'primary'      => ! empty( $raw['primary'] ),
                'pool_terms'   => $pool_terms,
                'initial_ids'  => $initial_ids,
                'current_ids'  => $current_ids,
            );

        } elseif ( 'date_published' === $raw['type'] || 'date_modified' === $raw['type'] ) {
            $is_modified   = ( 'date_modified' === $raw['type'] );
            $from_key      = $is_modified ? 'date_modified_from' : 'date_from';
            $to_key        = $is_modified ? 'date_modified_to'   : 'date_to';

            $configs[] = array(
                'type'      => $raw['type'],
                'label'     => $raw['label'],
                'date_type' => $raw['date_type'],
                'from_key'  => $from_key,
                'to_key'    => $to_key,
                'from_val'  => isset( $filter_state[ $from_key ] ) ? $filter_state[ $from_key ] : '',
                'to_val'    => isset( $filter_state[ $to_key ] )   ? $filter_state[ $to_key ]   : '',
            );

        } elseif ( 'author' === $raw['type'] ) {
            $current_authors = isset( $filter_state['author'] )
                ? array_map( 'intval', (array) $filter_state['author'] )
                : array();

            $configs[] = array(
                'type'            => 'author',
                'label'           => $raw['label'],
                'pool_authors'    => htax_get_author_pool(),
                'current_authors' => $current_authors,
            );
        }
    }

    return $configs;
}

/**
 * Resolve option pool term IDs from a raw terms config.
 *
 * Uses the same 4-step pool logic as the search widget field:
 *   1. include_group_options → expand to term IDs
 *   2. exclude_group_options → remove from pool
 *   3. include_term_options  → add individual term IDs
 *   4. exclude_term_options  → remove individual term IDs
 *
 * @param  array  $raw      Raw config from child shortcode.
 * @param  string $taxonomy Taxonomy slug.
 * @return int[]            Resolved term IDs for the option pool.
 */
function htax_resolve_filter_term_pool( array $raw, string $taxonomy ): array {
    $include_ids = array();
    $exclude_ids = array();
    $include_specified = false;

    if ( ! empty( $raw['include_group_options'] ) ) {
        $include_specified = true;
        $group_terms       = htax_get_terms_in_groups( htax_parse_term_ids( $raw['include_group_options'], 'group' ), $taxonomy );
        if ( ! empty( $group_terms ) ) {
            $include_ids = array_map( 'intval', array_keys( $group_terms ) );
        }
    }

    if ( ! empty( $raw['exclude_group_options'] ) ) {
        $excl_group_terms = htax_get_terms_in_groups( htax_parse_term_ids( $raw['exclude_group_options'], 'group' ), $taxonomy );
        if ( ! empty( $excl_group_terms ) ) {
            $exclude_ids = array_merge( $exclude_ids, array_map( 'intval', array_keys( $excl_group_terms ) ) );
        }
    }

    if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
        $include_ids = array_values( array_diff( $include_ids, $exclude_ids ) );
    }

    if ( ! empty( $raw['include_term_options'] ) ) {
        $include_specified = true;
        $individual        = array_map( 'intval', htax_parse_term_ids( $raw['include_term_options'], $taxonomy ) );
        $include_ids       = array_values( array_unique( array_merge( $include_ids, $individual ) ) );
    }

    if ( ! empty( $raw['exclude_term_options'] ) ) {
        $excl = array_map( 'intval', htax_parse_term_ids( $raw['exclude_term_options'], $taxonomy ) );
        $exclude_ids = array_merge( $exclude_ids, $excl );
    }

    if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
        $include_ids = array_values( array_diff( $include_ids, $exclude_ids ) );
    }

    if ( $include_specified && empty( $include_ids ) ) {
        return array();
    }

    return $include_ids;
}

/**
 * Resolve initial selection term IDs from a raw terms config.
 *
 * Uses the same 4-step logic with include_groups / exclude_groups / include_terms / exclude_terms.
 *
 * @param  array  $raw      Raw config from child shortcode.
 * @param  string $taxonomy Taxonomy slug.
 * @return int[]            Resolved initial selection term IDs.
 */
function htax_resolve_filter_initial_selection( array $raw, string $taxonomy ): array {
    $include_ids = array();
    $exclude_ids = array();
    $include_specified = false;

    if ( ! empty( $raw['include_groups'] ) ) {
        $include_specified = true;
        $group_terms       = htax_get_terms_in_groups( htax_parse_term_ids( $raw['include_groups'], 'group' ), $taxonomy );
        if ( ! empty( $group_terms ) ) {
            $include_ids = array_map( 'intval', array_keys( $group_terms ) );
        }
    }

    if ( ! empty( $raw['exclude_groups'] ) ) {
        $excl_group_terms = htax_get_terms_in_groups( htax_parse_term_ids( $raw['exclude_groups'], 'group' ), $taxonomy );
        if ( ! empty( $excl_group_terms ) ) {
            $exclude_ids = array_merge( $exclude_ids, array_map( 'intval', array_keys( $excl_group_terms ) ) );
        }
    }

    if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
        $include_ids = array_values( array_diff( $include_ids, $exclude_ids ) );
    }

    if ( ! empty( $raw['include_terms'] ) ) {
        $include_specified = true;
        $individual        = array_map( 'intval', htax_parse_term_ids( $raw['include_terms'], $taxonomy ) );
        $include_ids       = array_values( array_unique( array_merge( $include_ids, $individual ) ) );
    }

    if ( ! empty( $raw['exclude_terms'] ) ) {
        $excl = array_map( 'intval', htax_parse_term_ids( $raw['exclude_terms'], $taxonomy ) );
        $exclude_ids = array_merge( $exclude_ids, $excl );
    }

    if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
        $include_ids = array_values( array_diff( $include_ids, $exclude_ids ) );
    }

    if ( $include_specified && empty( $include_ids ) ) {
        return array();
    }

    return $include_ids;
}

/**
 * Return post authors for the author filter option pool.
 *
 * Returns all users who have at least one published post.
 *
 * @return array[] Array of { 'id' => int, 'name' => string } objects.
 */
function htax_get_author_pool(): array {
    global $wpdb;

    $user_ids = $wpdb->get_col(
        "SELECT DISTINCT p.post_author
         FROM {$wpdb->posts} p
         WHERE p.post_status = 'publish'
         AND p.post_type = 'post'
         ORDER BY p.post_author ASC"
    );

    if ( empty( $user_ids ) ) {
        return array();
    }

    $users = get_users( array(
        'include' => array_map( 'intval', $user_ids ),
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array( 'ID', 'display_name' ),
    ) );

    $authors = array();
    foreach ($users as $user) {
        $authors[] = array(
            'id'   => (int) $user->ID,
            'name' => $user->display_name,
        );
    }
    return $authors;
}

/**
 * Get the term ID to exclude from term filter pools when rendering in an archive context.
 *
 * Returns the current queried term ID, or null if not on a term archive.
 *
 * @return int|null
 */
function htax_get_archive_exclude_term() {
    if ( is_tax() || is_tag() || is_category() ) {
        $term = get_queried_object();
        if ( $term instanceof WP_Term ) {
            return $term->term_id;
        }
    }
    return null;
}

// ===========================================================================
// Filter state parsing
// ===========================================================================

/**
 * Read current filter state from $_GET['htax_filter'].
 *
 * Used for navigate mode (URL-based filtering).
 * Term match types are always 'any' in navigate mode.
 *
 * @return array Sanitized filter state.
 */
function htax_get_filter_state_from_get(): array {
    $raw = isset( $_GET['htax_filter'] ) ? wp_unslash( $_GET['htax_filter'] ) : array();
    return htax_parse_filter_state_navigate( $raw );
}

/**
 * Parse and sanitize a filter state array from navigate-mode GET params.
 *
 * Format: htax_filter[taxonomy]=5,12  htax_filter[date_from]=2024-01-01  htax_filter[author]=1,2
 * Match type for term filters is always 'any' in navigate mode.
 *
 * @param  array $raw Raw GET array (already wp_unslash'd).
 * @return array Sanitized state with keys: terms[], term_match[], date_from, date_to,
 *               date_modified_from, date_modified_to, author[].
 */
function htax_parse_filter_state_navigate( $raw ): array {
    $state = htax_empty_filter_state();

    if ( ! is_array( $raw ) ) {
        return $state;
    }

    $reserved = array( 'date_from', 'date_to', 'date_modified_from', 'date_modified_to', 'author' );

    foreach ( $raw as $key => $value ) {
        $key = sanitize_key( $key );
        if ( ! $key ) {
            continue;
        }

        if ( in_array( $key, array( 'date_from', 'date_to', 'date_modified_from', 'date_modified_to' ), true ) ) {
            $val = htax_sanitize_filter_date( $value );
            if ( $val ) {
                $state[ $key ] = $val;
            }
        } elseif ( 'author' === $key ) {
            $state['author'] = array_values( array_filter( array_map( 'intval', explode( ',', (string) $value ) ) ) );
        } elseif ( ! in_array( $key, $reserved, true ) ) {
            // Treat as taxonomy slug
            $term_ids             = array_values( array_filter( array_map( 'intval', explode( ',', (string) $value ) ) ) );
            $state['terms'][ $key ] = $term_ids;
            $state['term_match'][ $key ] = 'any'; // navigate mode always 'any'
        }
    }

    return $state;
}

/**
 * Parse and sanitize a filter state object from AJAX request data.
 *
 * AJAX format: {"post_tag":{"match":"any","terms":[5,12]},"date_from":"2024-01-01","author":[1]}
 *
 * @param  string|array $raw Raw filter value from AJAX (JSON string or already-decoded array).
 * @return array             Sanitized filter state.
 */
function htax_parse_filter_state_ajax( $raw ): array {
    $state = htax_empty_filter_state();

    if ( is_string( $raw ) ) {
        $raw = json_decode( wp_unslash( $raw ), true );
    }

    if ( ! is_array( $raw ) ) {
        return $state;
    }

    $reserved = array( 'date_from', 'date_to', 'date_modified_from', 'date_modified_to', 'author' );

    foreach ( $raw as $key => $value ) {
        $key = sanitize_key( $key );
        if ( ! $key ) {
            continue;
        }

        if ( in_array( $key, array( 'date_from', 'date_to', 'date_modified_from', 'date_modified_to' ), true ) ) {
            $val = htax_sanitize_filter_date( $value );
            if ( $val ) {
                $state[ $key ] = $val;
            }
        } elseif ( 'author' === $key ) {
            $state['author'] = array_values( array_filter( array_map( 'intval', (array) $value ) ) );
        } elseif ( ! in_array( $key, $reserved, true ) && is_array( $value ) ) {
            // Term filter: {"match":"any","terms":[5,12]}
            $match = isset( $value['match'] ) && in_array( $value['match'], array( 'any', 'all', 'none' ), true )
                ? $value['match'] : 'any';
            $term_ids = array_values( array_filter( array_map( 'intval', (array) ( $value['terms'] ?? array() ) ) ) );
            $state['terms'][ $key ] = $term_ids;
            $state['term_match'][ $key ] = $match;
        }
    }

    return $state;
}

/**
 * Sanitize and validate a filter date value.
 *
 * Returns the date string if it matches YYYY-MM-DD format, empty string otherwise.
 * Shared by htax_parse_filter_state_navigate() and htax_parse_filter_state_ajax().
 *
 * @param  mixed $value Raw value.
 * @return string       Validated date string or ''.
 */
function htax_sanitize_filter_date( $value ): string {
    $val = sanitize_text_field( (string) $value );
    return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ? $val : '';
}

/**
 * Return an empty filter state array.
 *
 * @return array
 */
function htax_empty_filter_state(): array {
    return array(
        'terms'              => array(),
        'term_match'         => array(),
        'date_from'          => '',
        'date_to'            => '',
        'date_modified_from' => '',
        'date_modified_to'   => '',
        'author'             => array(),
    );
}

/**
 * Return true if a filter state has any active (non-empty) filters.
 *
 * @param  array $state Filter state.
 * @return bool
 */
function htax_filter_state_is_active( array $state ): bool {
    foreach ( $state['terms'] as $ids ) {
        if ( ! empty( $ids ) ) {
            return true;
        }
    }
    if ( $state['date_from'] || $state['date_to'] || $state['date_modified_from'] || $state['date_modified_to'] ) {
        return true;
    }
    if ( ! empty( $state['author'] ) ) {
        return true;
    }
    return false;
}

// ===========================================================================
// SQL clause builder
// ===========================================================================

/**
 * Build additional WHERE clause fragments for a post list or PowerSearch query.
 *
 * The clauses are appended to the existing WHERE section. Each clause already
 * starts with "AND" so they can be directly concatenated.
 *
 * Empty term selections, empty date ranges, and empty author selections produce
 * no clause (no-op).
 *
 * @param  array  $filter_state  Sanitized filter state from htax_parse_filter_state_*.
 * @param  array  $filter_configs Resolved configs (from htax_resolve_filter_configs).
 *                                Used to look up match types for term filters.
 * @return string SQL clause fragments (may be empty string).
 */
function htax_build_filter_sql( array $filter_state, array $filter_configs = array() ): string {
    global $wpdb;

    // Build match-type lookup from configs
    $match_map = array(); // taxonomy => match type
    foreach ( $filter_configs as $cfg ) {
        if ( 'terms' === $cfg['type'] ) {
            $match_map[ $cfg['taxonomy'] ] = $cfg['match'];
        }
    }

    $clauses = array();

    // --- Term filters ---
    foreach ( $filter_state['terms'] as $taxonomy => $term_ids ) {
        if ( ! taxonomy_exists( $taxonomy ) || empty( $term_ids ) ) {
            continue;
        }

        $term_ids = array_map( 'intval', $term_ids );
        $id_str   = implode( ',', $term_ids );

        // Build relationship_type filter based on enabled features
        $allowed_rel_types = array( 'direct' );
        if ( is_taxonomy_hierarchical( $taxonomy ) ) {
            $allowed_rel_types[] = 'hierarchy';
        }
        if ( htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
            $allowed_rel_types[] = 'container';
        }
        if ( htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
            $allowed_rel_types[] = 'redirect_target';
        }
        $rel_types_quoted = implode( "','", array_map( 'esc_sql', $allowed_rel_types ) );
        $rel_type_clause  = " AND relationship_type IN ('$rel_types_quoted')";
        $hidden_clause    = ' AND is_hidden = 0';

        // Match type: from configs (AJAX) or from filter state (navigate = always 'any')
        $match = isset( $filter_state['term_match'][ $taxonomy ] )
            ? $filter_state['term_match'][ $taxonomy ]
            : ( isset( $match_map[ $taxonomy ] ) ? $match_map[ $taxonomy ] : 'any' );

        if ( 'all' === $match ) {
            $clauses[] = $wpdb->prepare(
                "AND pt.post_id IN (
                    SELECT post_id FROM {$wpdb->prefix}htax_search_terms
                    WHERE term_id IN ($id_str)$rel_type_clause$hidden_clause
                    GROUP BY post_id HAVING COUNT(DISTINCT term_id) = %d
                )",
                count( $term_ids )
            );
        } elseif ( 'none' === $match ) {
            $clauses[] = "AND pt.post_id NOT IN (
                    SELECT post_id FROM {$wpdb->prefix}htax_search_terms
                    WHERE term_id IN ($id_str)$rel_type_clause$hidden_clause
                )";
        } else {
            // 'any'
            $clauses[] = "AND pt.post_id IN (
                    SELECT post_id FROM {$wpdb->prefix}htax_search_terms
                    WHERE term_id IN ($id_str)$rel_type_clause$hidden_clause
                )";
        }
    }

    // --- Date published ---
    if ( $filter_state['date_from'] ) {
        $clauses[] = $wpdb->prepare( "AND pt.post_date_gmt >= %s", $filter_state['date_from'] . ' 00:00:00' );
    }
    if ( $filter_state['date_to'] ) {
        $clauses[] = $wpdb->prepare( "AND pt.post_date_gmt <= %s", $filter_state['date_to'] . ' 23:59:59' );
    }

    // --- Date modified ---
    if ( $filter_state['date_modified_from'] ) {
        $clauses[] = $wpdb->prepare( "AND pt.post_modified_gmt >= %s", $filter_state['date_modified_from'] . ' 00:00:00' );
    }
    if ( $filter_state['date_modified_to'] ) {
        $clauses[] = $wpdb->prepare( "AND pt.post_modified_gmt <= %s", $filter_state['date_modified_to'] . ' 23:59:59' );
    }

    // --- Author ---
    if ( ! empty( $filter_state['author'] ) ) {
        $author_ids = array_map( 'intval', $filter_state['author'] );
        $author_str = implode( ',', $author_ids );
        $clauses[]  = "AND pt.post_author IN ($author_str)";
    }

    return implode( "\n               ", $clauses );
}

/**
 * Build WP_Query modifications for archive filter (pre_get_posts).
 *
 * Returns an array of WP_Query setter calls as:
 *   array( array( 'key' => string, 'value' => mixed ), ... )
 * plus a special 'tax_query_additional' key for AND-merged tax_query entries.
 *
 * In navigate mode all term filters use match='any' (IN).
 *
 * @param  array $filter_state Sanitized filter state.
 * @return array               Modifications to apply.
 */
function htax_build_archive_filter_mods( array $filter_state ): array {
    $mods = array(
        'tax_query_additional' => array(),
        'date_query'           => array(),
        'author__in'           => array(),
    );

    // Term filters (navigate mode = always 'any' = IN)
    foreach ( $filter_state['terms'] as $taxonomy => $term_ids ) {
        if ( ! taxonomy_exists( $taxonomy ) || empty( $term_ids ) ) {
            continue;
        }
        $match = isset( $filter_state['term_match'][ $taxonomy ] )
            ? $filter_state['term_match'][ $taxonomy ] : 'any';
        $operator = 'IN';
        if ( 'all' === $match ) {
            $operator = 'AND';
        } elseif ( 'none' === $match ) {
            $operator = 'NOT IN';
        }

        $mods['tax_query_additional'][] = array(
            'taxonomy' => sanitize_key( $taxonomy ),
            'field'    => 'term_id',
            'terms'    => array_map( 'intval', $term_ids ),
            'operator' => $operator,
        );
    }

    // Date published
    $date_query = array();
    if ( $filter_state['date_from'] ) {
        $date_query['after'] = $filter_state['date_from'];
    }
    if ( $filter_state['date_to'] ) {
        $date_query['before'] = $filter_state['date_to'];
    }
    if ( ! empty( $date_query ) ) {
        $date_query['inclusive'] = true;
        $date_query['column']    = 'post_date';
        $mods['date_query'][]    = $date_query;
    }

    // Date modified
    $mod_date_query = array();
    if ( $filter_state['date_modified_from'] ) {
        $mod_date_query['after'] = $filter_state['date_modified_from'];
    }
    if ( $filter_state['date_modified_to'] ) {
        $mod_date_query['before'] = $filter_state['date_modified_to'];
    }
    if ( ! empty( $mod_date_query ) ) {
        $mod_date_query['inclusive'] = true;
        $mod_date_query['column']    = 'post_modified';
        $mods['date_query'][]        = $mod_date_query;
    }

    // Author
    if ( ! empty( $filter_state['author'] ) ) {
        $mods['author__in'] = array_map( 'intval', $filter_state['author'] );
    }

    return $mods;
}

// ===========================================================================
// Archive integration
// ===========================================================================

/**
 * Apply htax_filter GET params to archive queries (pre_get_posts priority 11).
 *
 * Runs after htax_modify_archive_query (priority 10) so it can safely merge
 * with any tax_query already set by the archive expansion.
 *
 * @param WP_Query $query
 */
function htax_apply_archive_filter( WP_Query $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if ( ! ( $query->is_tax() || $query->is_tag() || $query->is_category() ) ) {
        return;
    }

    if ( empty( $_GET['htax_filter'] ) ) {
        return;
    }

    $filter_state = htax_get_filter_state_from_get();

    if ( ! htax_filter_state_is_active( $filter_state ) ) {
        return;
    }

    $mods = htax_build_archive_filter_mods( $filter_state );

    // Merge additional tax_query entries with relation=AND
    if ( ! empty( $mods['tax_query_additional'] ) ) {
        $existing = $query->get( 'tax_query' );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }
        $new_tq             = array_merge( $existing, $mods['tax_query_additional'] );
        $new_tq['relation'] = 'AND';
        $query->set( 'tax_query', $new_tq );
    }

    // Merge date_query
    if ( ! empty( $mods['date_query'] ) ) {
        $existing_dq = $query->get( 'date_query' );
        if ( ! is_array( $existing_dq ) ) {
            $existing_dq = array();
        }
        $merged = array_merge( $existing_dq, $mods['date_query'] );
        if ( count( $merged ) > 1 ) {
            $merged['relation'] = 'AND';
        }
        $query->set( 'date_query', $merged );
    }

    // Author filter
    if ( ! empty( $mods['author__in'] ) ) {
        $existing_authors = (array) $query->get( 'author__in' );
        if ( ! empty( $existing_authors ) ) {
            $merged_authors = array_intersect( $existing_authors, $mods['author__in'] );
            $query->set( 'author__in', array_values( $merged_authors ) ?: array( 0 ) );
        } else {
            $query->set( 'author__in', $mods['author__in'] );
        }
    }
}
add_action( 'pre_get_posts', 'htax_apply_archive_filter', 11 );

// ===========================================================================
// Renderer
// ===========================================================================

/**
 * Enqueue filter options frontend assets.
 *
 * Safe to call multiple times; skips if already enqueued.
 */
function htax_enqueue_filter_assets() {
    if ( ! wp_style_is( 'htax-filter-options', 'enqueued' ) && ! wp_style_is( 'htax-filter-options', 'done' ) ) {
        wp_enqueue_style(
            'htax-filter-options',
            HTAX_PLUGIN_URL . 'assets/css/htax-filter-options.css',
            array(),
            HTAX_VERSION
        );
    }
    if ( ! wp_script_is( 'htax-filter-options', 'enqueued' ) && ! wp_script_is( 'htax-filter-options', 'done' ) ) {
        wp_enqueue_script(
            'htax-filter-options',
            HTAX_PLUGIN_URL . 'assets/js/htax-filter-options.js',
            array( 'jquery' ),
            HTAX_VERSION,
            true
        );
    }
}

/**
 * Parse simplified inline filters= / filter_options= attributes into raw filter configs.
 *
 * Converts comma-separated keyword lists into the same raw config format that child
 * shortcodes ([htax_filter_terms], [htax_filter_date_published], etc.) produce.
 * Result can be passed directly to htax_resolve_filter_configs().
 *
 * filters keywords: 'date_published', 'date_modified', 'author', any taxonomy slug.
 * filter_options positional values (aligned by index):
 *   '' (empty)  — all defaults for that filter type
 *   'single'    — for date filters: single date picker
 *   'group:N'   — for term filters: option pool = terms from group N
 *
 * The first taxonomy slug in filters is implicitly primary. All term filters use match="any".
 *
 * @param  string $filters_string        Comma-separated filter keywords.
 * @param  string $filter_options_string Comma-separated positional options aligned to keywords.
 * @return array  Raw filter configs suitable for htax_resolve_filter_configs().
 */
function htax_parse_inline_filters( $filters_string, $filter_options_string = '' ): array {
    $keywords    = array_map( 'trim', explode( ',', $filters_string ) );
    $options     = array_map( 'trim', explode( ',', $filter_options_string ) );
    $raw_configs = array();
    $first_tax   = true;

    foreach ( $keywords as $i => $keyword ) {
        $keyword = sanitize_key( $keyword );
        if ( ! $keyword ) {
            continue;
        }

        $opt = isset( $options[ $i ] ) ? trim( $options[ $i ] ) : '';

        if ( 'date_published' === $keyword ) {
            $raw_configs[] = array(
                'type'      => 'date_published',
                'label'     => __( 'Date', 'hypertaxonomy' ),
                'date_type' => 'single' === $opt ? 'single' : 'range',
            );
        } elseif ( 'date_modified' === $keyword ) {
            $raw_configs[] = array(
                'type'      => 'date_modified',
                'label'     => __( 'Modified', 'hypertaxonomy' ),
                'date_type' => 'single' === $opt ? 'single' : 'range',
            );
        } elseif ( 'author' === $keyword ) {
            $raw_configs[] = array(
                'type'  => 'author',
                'label' => __( 'Author', 'hypertaxonomy' ),
            );
        } else {
            // Taxonomy slug — first one is primary
            $is_primary = $first_tax;
            $first_tax  = false;

            $include_group_options = '';
            if ( preg_match( '/^group:(\d+)$/', $opt, $m ) ) {
                $include_group_options = $m[1];
            }

            $tax_obj = get_taxonomy( $keyword );
            $label   = $tax_obj ? $tax_obj->label : $keyword;

            $raw_configs[] = array(
                'type'                  => 'terms',
                'taxonomy'              => $keyword,
                'label'                 => $label,
                'display_type'          => '',
                'match'                 => 'any',
                'primary'               => $is_primary,
                'include_group_options' => $include_group_options,
                'exclude_group_options' => '',
                'include_term_options'  => '',
                'exclude_term_options'  => '',
                'include_groups'        => '',
                'exclude_groups'        => '',
                'include_terms'         => '',
                'exclude_terms'         => '',
            );
        }
    }

    return $raw_configs;
}

/**
 * Render a filter options panel.
 *
 * @param  array  $configs      Resolved filter configs (from htax_resolve_filter_configs).
 * @param  array  $args {
 *   @type string $action          'navigate' | 'submit' | ''. Default: 'navigate'.
 *   @type string $state           'expanded' | 'collapsed'. Default: 'expanded'.
 *   @type string $text_collapsed  Toggle label when collapsed. Default: 'Filter'.
 *   @type string $text_expanded   Toggle label when expanded. Default: 'Filter'.
 *   @type string $class           Extra CSS classes.
 * }
 * @param  array  $filter_state Current filter state (used to determine if clear button shows).
 * @return string HTML output.
 */
function htax_render_filter_options( array $configs, array $args = array(), array $filter_state = array() ): string {
    if ( empty( $configs ) ) {
        return '';
    }

    htax_enqueue_filter_assets();

    $args = wp_parse_args( $args, array(
        'action'         => 'navigate',
        'state'          => 'expanded',
        'text_collapsed' => __( 'Filter', 'hypertaxonomy' ),
        'text_expanded'  => __( 'Filter', 'hypertaxonomy' ),
        'class'          => '',
    ) );

    $action = in_array( $args['action'], array( 'navigate', 'submit' ), true ) ? $args['action'] : '';
    $state  = 'collapsed' === $args['state'] ? 'collapsed' : 'expanded';
    $extra  = ! empty( $args['class'] ) ? ' ' . esc_attr( $args['class'] ) : '';

    // Encode current state and initial state for JS
    $js_initial = htax_filter_state_to_js( $configs, true );  // initial state
    $js_current = htax_filter_state_to_js( $configs, false ); // current (selected) state

    $is_active = htax_filter_state_is_active( $filter_state );

    $out  = '<div class="htax-filter-options htax-filter-state-' . esc_attr( $state ) . $extra . '"';
    $out .= ' data-htax-filter-root';
    $out .= $action ? ' data-htax-filter-action="' . esc_attr( $action ) . '"' : '';
    $out .= ' data-htax-filter-initial=\'' . wp_json_encode( $js_initial ) . '\'';
    $out .= ' data-htax-filter-state=\'' . wp_json_encode( $js_current ) . '\'>';

    // Header: toggle + clear
    $out .= '<div class="htax-filter-header">';
    $out .= '<button type="button" class="htax-filter-toggle"';
    $out .= ' data-text-collapsed="' . esc_attr( $args['text_collapsed'] ) . '"';
    $out .= ' data-text-expanded="' . esc_attr( $args['text_expanded'] ) . '">';
    $out .= esc_html( 'collapsed' === $state ? $args['text_collapsed'] : $args['text_expanded'] );
    $out .= '</button>';
    $out .= '<button type="button" class="htax-filter-clear"';
    $out .= $is_active ? '' : ' style="display:none"';
    $out .= '>' . esc_html__( 'Clear filters', 'hypertaxonomy' ) . '</button>';
    $out .= '</div>'; // .htax-filter-header

    // Body: filter groups
    $out .= '<div class="htax-filter-body">';

    foreach ( $configs as $cfg ) {
        $out .= htax_render_filter_group( $cfg, $action );
    }

    $out .= '</div>'; // .htax-filter-body
    $out .= '</div>'; // .htax-filter-options

    return $out;
}

/**
 * Render a single filter group (one child filter).
 *
 * @param  array  $cfg    Resolved filter config.
 * @param  string $action Action mode ('navigate' | 'submit' | '').
 * @return string HTML.
 */
function htax_render_filter_group( array $cfg, string $action ): string {
    $type = $cfg['type'];

    if ( 'terms' === $type ) {
        return htax_render_filter_group_terms( $cfg, $action );
    }

    if ( 'date_published' === $type || 'date_modified' === $type ) {
        return htax_render_filter_group_date( $cfg, $action );
    }

    if ( 'author' === $type ) {
        return htax_render_filter_group_author( $cfg, $action );
    }

    return '';
}

/**
 * Render a term filter group.
 */
function htax_render_filter_group_terms( array $cfg, string $action ): string {
    if ( empty( $cfg['pool_terms'] ) ) {
        return '';
    }

    $taxonomy     = esc_attr( $cfg['taxonomy'] );
    $display_type = $cfg['display_type']; // 'pills' or 'checklist'
    $match        = esc_attr( $cfg['match'] );
    $current_ids  = array_map( 'intval', (array) $cfg['current_ids'] );

    $out  = '<div class="htax-filter-group htax-filter-terms htax-filter-' . esc_attr( $display_type ) . '-mode"';
    $out .= ' data-type="terms"';
    $out .= ' data-taxonomy="' . $taxonomy . '"';
    $out .= ' data-match="' . $match . '">';

    $out .= '<div class="htax-filter-group-label">' . esc_html( $cfg['label'] ) . '</div>';

    if ( 'pills' === $display_type ) {
        $out .= '<div class="htax-filter-pills">';
        foreach ( $cfg['pool_terms'] as $term ) {
            $is_active = in_array( (int) $term->term_id, $current_ids, true );
            $out .= '<button type="button" class="htax-filter-pill' . ( $is_active ? ' htax-active' : '' ) . '"';
            $out .= ' data-term-id="' . (int) $term->term_id . '">';
            $out .= esc_html( $term->name );
            $out .= '</button>';
        }
        $out .= '</div>';

        // Hidden input for navigate/submit action
        if ( $action ) {
            $val = implode( ',', $current_ids );
            $out .= '<input type="hidden" class="htax-filter-value-input"';
            $out .= ' name="htax_filter[' . $taxonomy . ']"';
            $out .= ' value="' . esc_attr( $val ) . '">';
        }
    } else {
        // checklist
        $out .= '<div class="htax-filter-checklist">';
        foreach ( $cfg['pool_terms'] as $term ) {
            $is_checked = in_array( (int) $term->term_id, $current_ids, true );
            $input_name = $action ? ' name="htax_filter[' . $taxonomy . '][]"' : '';
            $out .= '<label class="htax-filter-checklist-item">';
            $out .= '<input type="checkbox" class="htax-filter-checkbox"';
            $out .= ' value="' . (int) $term->term_id . '"';
            $out .= $input_name;
            $out .= $is_checked ? ' checked' : '';
            $out .= '>';
            $out .= '<span class="htax-filter-term-name">' . esc_html( $term->name ) . '</span>';
            $out .= '</label>';
        }
        $out .= '</div>';
    }

    $out .= '</div>'; // .htax-filter-group

    return $out;
}

/**
 * Render a date filter group.
 */
function htax_render_filter_group_date( array $cfg, string $action ): string {
    $from_key = $cfg['from_key'];
    $to_key      = $cfg['to_key'];
    $from_val    = esc_attr( $cfg['from_val'] );
    $to_val      = esc_attr( $cfg['to_val'] );

    $out  = '<div class="htax-filter-group htax-filter-date-group"';
    $out .= ' data-type="' . esc_attr( $cfg['type'] ) . '"';
    $out .= ' data-date-type="' . esc_attr( $cfg['date_type'] ) . '">';

    $out .= '<div class="htax-filter-group-label">' . esc_html( $cfg['label'] ) . '</div>';
    $out .= '<div class="htax-filter-date-inputs">';

    if ( 'single' === $cfg['date_type'] ) {
        $name_attr = $action ? ' name="htax_filter[' . esc_attr( $from_key ) . ']"' : '';
        $out .= '<input type="date" class="htax-filter-date-from"' . $name_attr;
        $out .= ' id="htax-filter-date-' . esc_attr( $from_key ) . '"';
        $out .= ' aria-label="' . esc_attr( $cfg['label'] ) . '"';
        $out .= ' value="' . $from_val . '">';
    } else {
        $from_name = $action ? ' name="htax_filter[' . esc_attr( $from_key ) . ']"' : '';
        $to_name   = $action ? ' name="htax_filter[' . esc_attr( $to_key ) . ']"' : '';
        $out .= '<input type="date" class="htax-filter-date-from"' . $from_name;
        $out .= ' id="htax-filter-date-from-' . esc_attr( $from_key ) . '"';
        $out .= ' aria-label="' . esc_attr( sprintf( '%s \u2014 %s', $cfg['label'], __( 'From', 'hypertaxonomy' ) ) ) . '"';
        $out .= ' value="' . $from_val . '" placeholder="' . esc_attr__( 'From', 'hypertaxonomy' ) . '">';
        $out .= '<span class="htax-filter-date-sep">' . esc_html__( '–', 'hypertaxonomy' ) . '</span>';
        $out .= '<input type="date" class="htax-filter-date-to"' . $to_name;
        $out .= ' id="htax-filter-date-to-' . esc_attr( $to_key ) . '"';
        $out .= ' aria-label="' . esc_attr( sprintf( '%s \u2014 %s', $cfg['label'], __( 'To', 'hypertaxonomy' ) ) ) . '"';
        $out .= ' value="' . $to_val . '" placeholder="' . esc_attr__( 'To', 'hypertaxonomy' ) . '">';
    }

    $out .= '</div>';
    $out .= '</div>'; // .htax-filter-group

    return $out;
}

/**
 * Render an author filter group.
 */
function htax_render_filter_group_author( array $cfg, string $action ): string {
    if ( empty( $cfg['pool_authors'] ) ) {
        return '';
    }

    $current_authors = array_map( 'intval', (array) $cfg['current_authors'] );

    $out  = '<div class="htax-filter-group htax-filter-author-group" data-type="author">';
    $out .= '<div class="htax-filter-group-label">' . esc_html( $cfg['label'] ) . '</div>';
    $out .= '<div class="htax-filter-checklist">';

    foreach ( $cfg['pool_authors'] as $author ) {
        $is_checked = in_array( (int) $author['id'], $current_authors, true );
        $input_name = $action ? ' name="htax_filter[author][]"' : '';
        $out .= '<label class="htax-filter-checklist-item">';
        $out .= '<input type="checkbox" class="htax-filter-checkbox"';
        $out .= ' value="' . (int) $author['id'] . '"';
        $out .= $input_name;
        $out .= $is_checked ? ' checked' : '';
        $out .= '>';
        $out .= '<span class="htax-filter-author-name">' . esc_html( $author['name'] ) . '</span>';
        $out .= '</label>';
    }

    $out .= '</div>';
    $out .= '</div>';

    return $out;
}

/**
 * Build the JS state representation from resolved configs.
 *
 * @param  array $configs  Resolved filter configs.
 * @param  bool  $initial  If true, use initial_ids; if false, use current_ids.
 * @return array           JS-compatible state object.
 */
function htax_filter_state_to_js( array $configs, bool $initial ): array {
    $state = array();

    foreach ( $configs as $cfg ) {
        if ( 'terms' === $cfg['type'] ) {
            $ids = $initial ? $cfg['initial_ids'] : $cfg['current_ids'];
            $state[ $cfg['taxonomy'] ] = array(
                'match' => $cfg['match'],
                'terms' => array_values( array_map( 'intval', (array) $ids ) ),
            );
        } elseif ( 'date_published' === $cfg['type'] ) {
            $state['date_from'] = $initial ? '' : $cfg['from_val'];
            $state['date_to']   = $initial ? '' : $cfg['to_val'];
        } elseif ( 'date_modified' === $cfg['type'] ) {
            $state['date_modified_from'] = $initial ? '' : $cfg['from_val'];
            $state['date_modified_to']   = $initial ? '' : $cfg['to_val'];
        } elseif ( 'author' === $cfg['type'] ) {
            $state['author'] = $initial ? array() : array_values( array_map( 'intval', (array) $cfg['current_authors'] ) );
        }
    }

    return $state;
}
