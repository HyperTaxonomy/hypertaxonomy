<?php
/**
 * AJAX Handler Functions
 * Handles all AJAX requests from admin interface
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================================================
// AUTOCOMPLETE HELPER FUNCTIONS
// ==================================================

/**
 * Split term description into searchable fragments
 *
 * Processes term descriptions to extract meaningful search fragments by:
 * - Stripping HTML tags
 * - Converting line breaks and semicolons to commas
 * - Splitting on commas
 * - Removing duplicates and empty strings
 *
 * This allows descriptions like "tag1; tag2; tag3" or multi-line descriptions
 * to be searched fragment-by-fragment in autocomplete.
 *
 * @since 1.0.0
 *
 * @param string $description Term description to split
 * @return array Array of unique, trimmed fragments
 *
 * @example
 * $fragments = htax_split_description( "tag1; tag2\ntag3" );
 * // Returns ['tag1', 'tag2', 'tag3']
 */
function htax_split_description( $description ) {
    if ( empty( $description ) ) {
        return array();
    }

    // Strip HTML tags to get plain text
    $plain_text = wp_strip_all_tags( $description );

    // Decode HTML entities that might remain (e.g., &amp; -> &)
    $plain_text = html_entity_decode( $plain_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    // Replace line breaks with commas for better fragment detection
    // This helps when people use line breaks as logical separators
    $plain_text = preg_replace( '/\s*[\r\n]+\s*/', ', ', $plain_text );

    // Normalize whitespace (multiple spaces/tabs to single space)
    $plain_text = preg_replace( '/\s+/', ' ', $plain_text );

    // Also handle cases where semicolons might be used as separators
    // (common in some descriptions like "tag1; tag2; tag3")
    $plain_text = str_replace( ';', ',', $plain_text );

    // Split by comma, trim whitespace
    $fragments = explode( ',', $plain_text );
    $fragments = array_map( 'trim', $fragments );

    // Remove empty fragments and duplicates
    $fragments = array_filter( $fragments );
    $fragments = array_unique( $fragments );

    // Re-index array to ensure sequential numeric keys
    $fragments = array_values( $fragments );

    return $fragments;
}

/**
 * Truncate text to specified length with ellipsis
 *
 * Truncates text to a maximum length using multibyte-safe string operations.
 * Strips HTML tags before truncation and adds ellipsis if text was truncated.
 *
 * @since 1.0.0
 *
 * @param string $text   Text to truncate
 * @param int    $length Maximum length (default: 30 characters)
 * @return string Truncated text with ellipsis if needed
 *
 * @example
 * echo htax_truncate_text( 'This is a very long description', 10 );
 * // "This is a..."
 * echo htax_truncate_text( 'Short', 10 );               // "Short"
 */
function htax_truncate_text( $text, $length = 30 ) {
    // Strip HTML tags for truncation
    $plain_text = wp_strip_all_tags( $text );

    if ( mb_strlen( $plain_text ) <= $length ) {
        return $plain_text;
    }

    // Use multibyte-safe truncation
    return mb_substr( $plain_text, 0, $length ) . '...';
}

// ==================================================
// RATE LIMITING
// ==================================================

/**
 * Check rate limit for AJAX requests
 *
 * Prevents DoS attacks by limiting requests per user per minute
 *
 * @param string $action The AJAX action name
 * @param int $limit Maximum requests per minute (default: 60)
 * @return bool True if within limit, false if rate exceeded
 */
function htax_check_ajax_rate_limit( $action, $limit = 60 ) {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return true; // Don't rate limit non-logged-in users (they're already blocked by capability checks)
    }

    $cache_key = 'htax_rate_' . md5( $action . '_' . $user_id );
    $count     = wp_cache_incr( $cache_key, 1, 'htax_rate_limits' );
    if ( false === $count ) {
        wp_cache_set( $cache_key, 1, 'htax_rate_limits', MINUTE_IN_SECONDS );
        $count = 1;
    }

    if ( $count > $limit ) {
        htax_log_error( 'AJAX rate limit exceeded', array(
            'action'  => $action,
            'user_id' => $user_id,
            'count'   => $count,
            'limit'   => $limit,
        ) );
        return false;
    }

    return true;
}

/**
 * AJAX handler: Get terms in specified groups
 *
 * @since 1.0.0
 */
function htax_ajax_get_group_terms() {
    if ( ! check_ajax_referer( 'htax-autocomplete', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed' );
        return;
    }

    // Rate limiting check (60 requests per minute)
    if ( ! htax_check_ajax_rate_limit( 'get_group_terms', 60 ) ) {
        wp_send_json_error( 'Rate limit exceeded. Please wait a moment and try again.' );
        return;
    }

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
        return;
    }

    $group_ids = isset( $_GET['group_ids'] ) && is_array( $_GET['group_ids'] ) ? array_map( 'intval', wp_unslash( $_GET['group_ids'] ) ) : array();
    $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : 'post_tag';

    htax_log_debug( 'AJAX get group terms', array(
        'group_ids' => $group_ids,
        'taxonomy' => $taxonomy
    ) );

    $result = array();
    foreach ( $group_ids as $group_id ) {
        $terms = htax_get_terms_in_groups( array( $group_id ), $taxonomy );
        $items = array();
        foreach ( $terms as $term ) {
            $items[] = array(
                'id'   => $term->term_id,
                'name' => $term->name,
            );
        }
        $result[ $group_id ] = $items;
    }
    wp_send_json_success( $result );
}

/**
 * Run a term autocomplete search and return bucket-sorted results.
 *
 * Shared by htax_ajax_search_terms() and htax_ajax_search_groups(). Callers handle
 * auth, caching, and any post-processing (hidden-term filtering, icon appending).
 *
 * @since 1.0.0
 *
 * @param string $taxonomy Taxonomy to search.
 * @param string $search   Search string (>= 2 chars expected).
 * @param int[]  $exclude  Term IDs to exclude.
 * @return array[] Bucket-sorted result items, each with 'id', 'value', 'label' keys.
 */
function htax_run_term_autocomplete_search( $taxonomy, $search, $exclude, $include_ids = array() ) {
    global $wpdb;

    // WordPress stores term names HTML-encoded in the DB (e.g. "H&amp;M").
    // Normalize the caller's input: decode any entities the user may have typed
    // (e.g. "h&amp;m" → "h&m"), then re-encode to match DB storage format.
    // This lets "h&m", "h&amp;m", and partial inputs all find "H&amp;M".
    $search_decoded = html_entity_decode( $search, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $search_db      = htmlspecialchars( $search_decoded, ENT_QUOTES, 'UTF-8' );

    $search_like    = '%' . $wpdb->esc_like( $search_db ) . '%';
    $where_clauses  = array( 'tt.taxonomy = %s', '(t.name LIKE %s OR tt.description LIKE %s)' );
    $prepare_values = array( $taxonomy, $search_like, $search_like );

    if ( ! empty( $include_ids ) ) {
        $include_placeholders = htax_sql_placeholders( count( $include_ids ) );
        $where_clauses[]      = "t.term_id IN ($include_placeholders)";
        $prepare_values       = array_merge( $prepare_values, $include_ids );
    }

    if ( ! empty( $exclude ) ) {
        $exclude_placeholders = htax_sql_placeholders( count( $exclude ) );
        $where_clauses[]      = "t.term_id NOT IN ($exclude_placeholders)";
        $prepare_values       = array_merge( $prepare_values, $exclude );
    }

    $where_sql = implode( ' AND ', $where_clauses );

    $sql = "SELECT DISTINCT t.term_id, t.name, t.slug, tt.description, tt.count
            FROM {$wpdb->term_taxonomy} tt USE INDEX (taxonomy)
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE $where_sql
            ORDER BY t.name ASC
            LIMIT " . HTAX_AJAX_SEARCH_LIMIT;

    $placeholder_count = substr_count( $sql, '%s' ) + substr_count( $sql, '%d' );
    if ( count( $prepare_values ) !== $placeholder_count ) {
        htax_log_error( 'SQL placeholder count mismatch in AJAX search', array(
            'expected' => $placeholder_count,
            'actual'   => count( $prepare_values ),
            'sql'      => $sql,
        ) );
        return array();
    }

    $terms    = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_values ) );
    $db_error = htax_handle_db_error( 'run_term_autocomplete_search', array() );
    if ( false !== $db_error || empty( $terms ) ) {
        return array();
    }

    // Use the decoded form for all string comparisons and display.
    $search_lower  = strtolower( $search_decoded );
    $buckets       = array(
        'name_starts'   => array(),
        'name_boundary' => array(),
        'name_contains' => array(),
        'desc_starts'   => array(),
        'desc_boundary' => array(),
        'desc_contains' => array(),
    );
    $processed_ids = array();

    foreach ( $terms as $term ) {
        if ( isset( $processed_ids[ $term->term_id ] ) ) {
            continue;
        }

        // Decode the stored (HTML-encoded) name for comparisons and display.
        $name_display = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $name_lower   = strtolower( $name_display );
        $found        = false;

        if ( 0 === strpos( $name_lower, $search_lower ) ) {
            $buckets['name_starts'][] = array(
                'id'    => $term->term_id,
                'value' => $name_display,
                'label' => htax_highlight_match( $name_display, $search_decoded, 0 ),
            );
            $processed_ids[ $term->term_id ] = true;
            $found = true;
        } elseif ( $pos = htax_find_after_non_letter( $name_lower, $search_lower ) ) {
            $buckets['name_boundary'][] = array(
                'id'    => $term->term_id,
                'value' => $name_display,
                'label' => htax_highlight_match( $name_display, $search_decoded, $pos ),
            );
            $processed_ids[ $term->term_id ] = true;
            $found = true;
        } elseif ( ( $pos = stripos( $name_lower, $search_lower ) ) !== false ) {
            $buckets['name_contains'][] = array(
                'id'    => $term->term_id,
                'value' => $name_display,
                'label' => htax_highlight_match( $name_display, $search_decoded, $pos ),
            );
            $processed_ids[ $term->term_id ] = true;
            $found = true;
        }

        if ( ! $found && ! empty( $term->description ) ) {
            $desc_fragments = htax_split_description( $term->description );
            foreach ( $desc_fragments as $fragment ) {
                $fragment_lower = strtolower( $fragment );
                $desc_suffix    = esc_html( '(' . htax_truncate_text( $fragment, 30 ) . ')' );

                if ( 0 === strpos( $fragment_lower, $search_lower ) ) {
                    $buckets['desc_starts'][] = array(
                        'id'    => $term->term_id,
                        'value' => $name_display,
                        'label' => esc_html( $name_display ) . ' <span class="htax-desc-match">' . $desc_suffix . '</span>',
                    );
                    $processed_ids[ $term->term_id ] = true;
                    break;
                } elseif ( htax_find_after_non_letter( $fragment_lower, $search_lower ) ) {
                    $buckets['desc_boundary'][] = array(
                        'id'    => $term->term_id,
                        'value' => $name_display,
                        'label' => esc_html( $name_display ) . ' <span class="htax-desc-match">' . $desc_suffix . '</span>',
                    );
                    $processed_ids[ $term->term_id ] = true;
                    break;
                } elseif ( stripos( $fragment_lower, $search_lower ) !== false ) {
                    $buckets['desc_contains'][] = array(
                        'id'    => $term->term_id,
                        'value' => $name_display,
                        'label' => esc_html( $name_display ) . ' <span class="htax-desc-match">' . $desc_suffix . '</span>',
                    );
                    $processed_ids[ $term->term_id ] = true;
                    break;
                }
            }
        }
    }

    $results      = array();
    $max_results  = 20;
    $bucket_order = array( 'name_starts', 'name_boundary', 'name_contains', 'desc_starts', 'desc_boundary', 'desc_contains' );

    foreach ( $bucket_order as $bucket_key ) {
        if ( count( $results ) >= $max_results ) {
            break;
        }
        $bucket = $buckets[ $bucket_key ];
        if ( empty( $bucket ) ) {
            continue;
        }
        $remaining = $max_results - count( $results );
        if ( $remaining > 0 ) {
            usort( $bucket, function( $a, $b ) {
                return strcasecmp( $a['value'], $b['value'] );
            } );
            $results = array_merge( $results, array_slice( $bucket, 0, $remaining ) );
        }
    }

    return $results;
}

/**
 * AJAX handler: Search for terms with autocomplete
 *
 * @since 1.0.0
 */
function htax_ajax_search_terms() {
    check_ajax_referer( 'htax-autocomplete', 'nonce' );

    // Rate limiting check (100 requests per minute for autocomplete)
    if ( ! htax_check_ajax_rate_limit( 'search_terms', 100 ) ) {
        wp_send_json_error( 'Rate limit exceeded. Please wait a moment and try again.' );
        return;
    }

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
        return;
    }

    // JS sends taxonomies[] (array from merged containers); fall back to taxonomy (singular).
    if ( ! empty( $_GET['taxonomies'] ) && is_array( $_GET['taxonomies'] ) ) {
        $taxonomy = sanitize_key( wp_unslash( reset( $_GET['taxonomies'] ) ) );
    } elseif ( isset( $_GET['taxonomy'] ) ) {
        $taxonomy = sanitize_key( wp_unslash( $_GET['taxonomy'] ) );
    } else {
        $taxonomy = 'post_tag';
    }

    // Single validation check - supports all enabled taxonomies including custom ones
    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        wp_send_json_error( 'Invalid or disabled taxonomy' );
        return;
    }

    $search = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
    if ( strlen( $search ) < 2 ) {
        wp_send_json( array() );
        return;
    }

    $exclude = isset( $_GET['exclude'] ) ? array_map( 'intval', wp_unslash( (array) $_GET['exclude'] ) ) : array();
    // Merged and sym containers send exclude_terms instead of exclude.
    if ( ! empty( $_GET['exclude_terms'] ) ) {
        $exclude = array_merge( $exclude, array_map( 'intval', wp_unslash( (array) $_GET['exclude_terms'] ) ) );
    }
    $current_id = isset( $_GET['current'] ) ? intval( $_GET['current'] ) : 0;

    // Expand include_groups / exclude_groups to term IDs for option-pool filtering
    $include_ids       = array();
    $include_requested = false;
    if ( ! empty( $_GET['include_groups'] ) ) {
        $include_group_ids = htax_parse_term_ids( sanitize_text_field( wp_unslash( $_GET['include_groups'] ) ) );
        if ( ! empty( $include_group_ids ) ) {
            $include_requested  = true;
            $group_term_objects = htax_get_terms_in_groups( $include_group_ids, $taxonomy );
            $include_ids        = array_map( function( $t ) { return (int) $t->term_id; }, $group_term_objects );
        }
    }
    // An include constraint was requested but expanded to zero terms: the result set must
    // be empty, not unrestricted. Force a sentinel that can never match a real term ID.
    if ( $include_requested && empty( $include_ids ) ) {
        $include_ids = array( 0 );
    }
    if ( ! empty( $_GET['exclude_groups'] ) ) {
        $exclude_group_ids = htax_parse_term_ids( sanitize_text_field( wp_unslash( $_GET['exclude_groups'] ) ) );
        if ( ! empty( $exclude_group_ids ) ) {
            $excl_group_term_objects = htax_get_terms_in_groups( $exclude_group_ids, $taxonomy );
            $excl_ids                = array_map( function( $t ) { return (int) $t->term_id; }, $excl_group_term_objects );
            $exclude                 = array_merge( $exclude, $excl_ids );
        }
    }

    // SHORT-LIVED CACHE FOR IDENTICAL REQUESTS
    $cache_key = htax_generate_transient_key( 'htax_ac_', md5( $taxonomy . '|' . $search . '|' . implode( ',', $exclude ) . '|' . $current_id . '|' . implode( ',', $include_ids ) ) );
    $cached = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json( $cached );
        return;
    }

    if ( $current_id ) {
        $exclude[] = $current_id;
        // For hierarchical taxonomies, suppress ancestors and descendants from suggestions.
        // HT inclusions/containers are restricted to cross-branch relationships; ancestors
        // and descendants are handled by WP's native hierarchy and cannot be valid targets.
        // This applies equally to the include, container, and exclude autocomplete contexts.
        if ( is_taxonomy_hierarchical( $taxonomy ) ) {
            $ancestor_ids = get_ancestors( $current_id, $taxonomy, 'taxonomy' );
            if ( ! empty( $ancestor_ids ) ) {
                $exclude = array_merge( $exclude, $ancestor_ids );
            }
            $descendant_ids = get_term_children( $current_id, $taxonomy );
            if ( ! is_wp_error( $descendant_ids ) && ! empty( $descendant_ids ) ) {
                $exclude = array_merge( $exclude, $descendant_ids );
            }
        }
    }

    $results = htax_run_term_autocomplete_search( $taxonomy, $search, $exclude, $include_ids );

    // Filter out hidden terms if user doesn't have permission to see them
    if ( ! htax_can_see_hidden_terms() ) {
        $results = array_values( array_filter( $results, function( $item ) {
            return ! htax_is_term_hidden( $item['id'] );
        } ) );
    }

    if ( ! empty( $results ) && HyperTaxonomy_Term_Icons::are_icons_enabled( $taxonomy ) ) {
        $icons         = HyperTaxonomy_Term_Icons::get_instance();
        $term_ids      = array_map( function( $item ) { return $item['id']; }, $results );
        $all_icon_data = $icons->get_batch_term_icon_data( $term_ids, 'htax-icon-small', $taxonomy );

        foreach ( $results as &$result ) {
            if ( isset( $all_icon_data[ $result['id'] ] ) && $all_icon_data[ $result['id'] ]['has_icon'] ) {
                $result['icon_url'] = $all_icon_data[ $result['id'] ]['url'];
            }
        }
    }

    htax_set_transient( $cache_key, $results, htax_get_cache_duration( 'autocomplete' ) );

    wp_send_json( $results );
}

/**
 * AJAX handler: Return all term IDs belonging to the given group IDs.
 *
 * Used by the search widget JS to determine which currently-selected pills
 * should be removed when a group is excluded from the option pool.
 *
 * @since 1.0.0
 */
function htax_ajax_get_group_term_ids() {
    if ( ! htax_check_ajax_rate_limit( 'get_group_term_ids', 100 ) ) {
        wp_send_json_error( 'Rate limit exceeded' );
        return;
    }

    $nonce_valid = check_ajax_referer( 'htax-autocomplete', 'nonce', false ) ||
                   check_ajax_referer( 'htax-search-widget', 'nonce', false );
    if ( ! $nonce_valid ) {
        wp_send_json_error( 'Security check failed' );
        return;
    }

    $groups   = isset( $_GET['groups'] ) ? htax_parse_term_ids( sanitize_text_field( wp_unslash( $_GET['groups'] ) ) ) : array();
    $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : 'post_tag';

    if ( empty( $groups ) || ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        wp_send_json_success( array() );
        return;
    }

    $term_objects = htax_get_terms_in_groups( $groups, $taxonomy );
    $term_ids     = array_values( array_map( 'intval', array_keys( $term_objects ) ) );

    if ( ! htax_can_see_hidden_terms() ) {
        $term_ids = array_values( array_filter( $term_ids, static function( $id ) {
            return ! htax_is_term_hidden( $id );
        } ) );
    }

    wp_send_json_success( $term_ids );
}

/**
 * AJAX handler: Search for group terms with autocomplete
 *
 * Admin-only counterpart to htax_ajax_search_terms() for the 'group' taxonomy.
 *
 * @since 1.0.0
 */
function htax_ajax_search_groups() {
    if ( ! check_ajax_referer( 'htax-autocomplete', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed' );
        return;
    }

    if ( ! htax_check_ajax_rate_limit( 'search_groups', 100 ) ) {
        wp_send_json_error( 'Rate limit exceeded. Please wait a moment and try again.' );
        return;
    }

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
        return;
    }

    $search = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
    if ( strlen( $search ) < 2 ) {
        wp_send_json( array() );
        return;
    }

    $exclude    = isset( $_GET['exclude'] ) ? array_map( 'intval', wp_unslash( (array) $_GET['exclude'] ) ) : array();
    $current_id = isset( $_GET['current'] ) ? intval( $_GET['current'] ) : 0;

    if ( $current_id ) {
        $exclude[] = $current_id;
    }

    $cache_key = htax_generate_transient_key( 'htax_ac_grp_', md5( $search . '|' . implode( ',', $exclude ) ) );
    $cached    = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json( $cached );
        return;
    }

    $results = htax_run_term_autocomplete_search( 'group', $search, $exclude );

    htax_set_transient( $cache_key, $results, htax_get_cache_duration( 'autocomplete' ) );

    wp_send_json( $results );
}


/**
 * Helper: Find search string after non-letter boundary
 *
 * @since 1.0.0
 * @param string $haystack The string to search in
 * @param string $needle The string to search for
 * @return int|false Position of match after non-letter, or false if not found
 */
function htax_find_after_non_letter( $haystack, $needle ) {
    $pos = 0;

    while ( ( $pos = stripos( $haystack, $needle, $pos ) ) !== false ) {
        // Check if preceded by non-letter (or at start)
        if ( 0 === $pos ) {
            return $pos;
        }
        $prev_char = mb_substr( $haystack, $pos - 1, 1, 'UTF-8' );
        if ( ! preg_match( '/^\p{L}$/u', $prev_char ) ) {
            return $pos;
        }
        $pos++;
    }

    return false;
}

/**
 * Helper: Highlight matching portion in bold
 *
 * @since 1.0.0
 * @param string $text The text to highlight in
 * @param string $search The search term to highlight
 * @param int $position Position where the match starts
 * @return string HTML string with highlighted match
 */
function htax_highlight_match( $text, $search, $position ) {
    $before = esc_html( mb_substr( $text, 0, $position, 'UTF-8' ) );
    $match  = esc_html( mb_substr( $text, $position, mb_strlen( $search, 'UTF-8' ), 'UTF-8' ) );
    $after  = esc_html( mb_substr( $text, $position + mb_strlen( $search, 'UTF-8' ), null, 'UTF-8' ) );

    return $before . '<strong>' . $match . '</strong>' . $after;
}

/**
 * Chunked import — first request: create groups, return ID mapping + total term count.
 *
 * @since 1.0.0
 */
function htax_ajax_import_tag_groups_init() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax_import_tag_groups' ) ) {
        wp_send_json_error( __( 'Security check failed.', 'hypertaxonomy' ) );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) );
        return;
    }

    require_once HTAX_PLUGIN_DIR . 'includes/admin/import-tag-groups-handler.php';
    wp_send_json_success( htax_import_tag_groups_create_groups() );
}
add_action( 'wp_ajax_htax_import_tag_groups_init', 'htax_ajax_import_tag_groups_init' );

/**
 * Chunked import — batch request: process one batch of term assignments.
 *
 * @since 1.0.0
 */
function htax_ajax_import_tag_groups_batch() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax_import_tag_groups' ) ) {
        wp_send_json_error( __( 'Security check failed.', 'hypertaxonomy' ) );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) );
        return;
    }

    $group_mapping = isset( $_POST['group_mapping'] ) ? json_decode( wp_unslash( $_POST['group_mapping'] ), true ) : array();
    $offset        = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
    $batch_size    = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 50;
    $batch_size    = max( 1, min( 200, $batch_size ) );
    $mode          = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'keep';

    if ( ! is_array( $group_mapping ) ) {
        wp_send_json_error( __( 'Invalid group mapping.', 'hypertaxonomy' ) );
        return;
    }

    require_once HTAX_PLUGIN_DIR . 'includes/admin/import-tag-groups-handler.php';
    wp_send_json_success( htax_import_tag_groups_process_batch( $group_mapping, $offset, $batch_size, $mode ) );
}
add_action( 'wp_ajax_htax_import_tag_groups_batch', 'htax_ajax_import_tag_groups_batch' );

/**
 * Chunked import — final request: clear caches after all batches complete.
 *
 * @since 1.0.0
 */
function htax_ajax_import_tag_groups_finish() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax_import_tag_groups' ) ) {
        wp_send_json_error( __( 'Security check failed.', 'hypertaxonomy' ) );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) );
        return;
    }

    htax_clear_all_caches();

    require_once HTAX_PLUGIN_DIR . 'includes/admin/import-tag-groups-handler.php';
    htax_store_tag_groups_import_snapshot();

    wp_send_json_success( array( 'done' => true ) );
}
add_action( 'wp_ajax_htax_import_tag_groups_finish', 'htax_ajax_import_tag_groups_finish' );

/**
 * Save the Tag Groups sync settings from the Sync subtab in one request:
 * integration mode, the continuous-sync checkbox, and removal handling. Takes
 * effect on the next page load, when the live-sync hooks in
 * tag-groups-live-sync.php register (or don't).
 *
 * Turning continuous sync on needs a one-time full resync first — any drift
 * from time spent without it has to be caught up before per-event sync can
 * rely on every mapping already being current. The response's `needs_resync`
 * flag tells the UI to run that resync via the Migrate subtab's existing flow.
 *
 * @since 1.0.0
 */
function htax_ajax_save_tag_groups_sync_settings() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax_import_tag_groups' ) ) {
        wp_send_json_error( __( 'Security check failed.', 'hypertaxonomy' ) );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) );
        return;
    }

    $integration_mode = isset( $_POST['integration_mode'] ) ? sanitize_key( wp_unslash( $_POST['integration_mode'] ) ) : '';
    $sync_mode        = isset( $_POST['sync_mode'] ) ? sanitize_key( wp_unslash( $_POST['sync_mode'] ) ) : '';
    if ( ! in_array( $integration_mode, array( 'independent', 'locked' ), true ) ) {
        wp_send_json_error( __( 'Invalid integration mode.', 'hypertaxonomy' ) );
        return;
    }
    if ( ! in_array( $sync_mode, array( 'mirror', 'keep' ), true ) ) {
        wp_send_json_error( __( 'Invalid removal handling mode.', 'hypertaxonomy' ) );
        return;
    }

    require_once HTAX_PLUGIN_DIR . 'includes/admin/import-tag-groups-handler.php';
    $was_live_sync = htax_tag_groups_live_sync_enabled();

    update_option( 'htax_tag_groups_integration_mode', $integration_mode, false );
    $live_sync = 'locked' === $integration_mode && ! empty( $_POST['live_sync'] ) && '0' !== $_POST['live_sync'];
    update_option( 'htax_tag_groups_live_sync', $live_sync ? 1 : 0, false );
    update_option( 'htax_tag_groups_sync_mode', $sync_mode, false );

    wp_send_json_success( array(
        'needs_resync' => htax_tag_groups_live_sync_enabled() && ! $was_live_sync,
    ) );
}
add_action( 'wp_ajax_htax_save_tag_groups_sync_settings', 'htax_ajax_save_tag_groups_sync_settings' );

/**
 * AJAX handler to permanently dismiss the Tag Groups coexistence notice for
 * the current user.
 *
 * @since 1.0.0
 */
function htax_ajax_dismiss_tag_groups_notice() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax_dismiss_tag_groups_notice' ) ) {
        wp_send_json_error( __( 'Security check failed.', 'hypertaxonomy' ) );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) );
        return;
    }

    update_user_meta( get_current_user_id(), '_htax_dismissed_tag_groups_notice', 1 );
    wp_send_json_success();
}
add_action( 'wp_ajax_htax_dismiss_tag_groups_notice', 'htax_ajax_dismiss_tag_groups_notice' );

/**
 * AJAX handler for HyperTaxonomy export
 *
 * @since 1.0.0
 */
function htax_ajax_export() {
    // Check nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax_export' ) ) {
        wp_die( esc_html__( 'Security check failed.', 'hypertaxonomy' ) );
    }

    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to perform this action.', 'hypertaxonomy' ) );
    }

    // Load export functionality
    require_once HTAX_PLUGIN_DIR . 'includes/admin/export-hypertaxonomy.php';

    // Get taxonomies from POST (sent as JSON.stringify'd array by the export form).
    $taxonomies_raw = isset( $_POST['taxonomies'] ) ? wp_unslash( $_POST['taxonomies'] ) : '';
    $taxonomies     = is_string( $taxonomies_raw ) ? json_decode( $taxonomies_raw, true ) : array();
    $include_term_posts = isset( $_POST['include_term_posts'] ) && '1' === wp_unslash( $_POST['include_term_posts'] );

    // Validate taxonomies
    if ( ! is_array( $taxonomies ) || empty( $taxonomies ) ) {
        wp_die( esc_html__( 'No taxonomies selected.', 'hypertaxonomy' ) );
    }

    $taxonomies = array_filter(
        array_map( 'sanitize_key', (array) $taxonomies ),
        'taxonomy_exists'
    );
    if ( empty( $taxonomies ) ) {
        wp_die( esc_html__( 'No valid taxonomies selected.', 'hypertaxonomy' ) );
    }

    // Download export
    htax_download_export( $taxonomies, $include_term_posts );
    exit;
}
add_action( 'wp_ajax_htax_export', 'htax_ajax_export' );

/**
 * AJAX handler for HyperTaxonomy import preview
 *
 * @since 1.0.0
 */
function htax_ajax_preview_import() {
    // Check nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax_import' ) ) {
        wp_send_json_error( array( 'error' => __( 'Security check failed.', 'hypertaxonomy' ) ) );
        return;
    }

    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'error' => __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) ) );
        return;
    }

    // Load import functionality
    require_once HTAX_PLUGIN_DIR . 'includes/admin/import-hypertaxonomy.php';

    // Get import data from POST
    $import_data = isset( $_POST['import_data'] ) ? json_decode( wp_unslash( $_POST['import_data'] ), true ) : null;

    if ( empty( $import_data ) ) {
        wp_send_json_error( array( 'error' => __( 'No import data provided.', 'hypertaxonomy' ) ) );
        return;
    }

    // Validate import data
    $validation = htax_validate_import_data( $import_data );
    if ( is_wp_error( $validation ) ) {
        wp_send_json_error( array( 'error' => $validation->get_error_message() ) );
        return;
    }

    // Generate preview
    $preview = htax_preview_import( $import_data );
    wp_send_json_success( array( 'preview' => $preview ) );
}
add_action( 'wp_ajax_htax_preview_import', 'htax_ajax_preview_import' );

/**
 * AJAX handler for HyperTaxonomy import
 *
 * @since 1.0.0
 */
function htax_ajax_import() {
    // Check nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax_import' ) ) {
        wp_send_json_error( array( 'error' => __( 'Security check failed.', 'hypertaxonomy' ) ) );
        return;
    }

    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'error' => __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) ) );
        return;
    }

    // Load import functionality
    require_once HTAX_PLUGIN_DIR . 'includes/admin/import-hypertaxonomy.php';

    // Get import data and options from POST
    $import_data = isset( $_POST['import_data'] ) ? json_decode( wp_unslash( $_POST['import_data'] ), true ) : null;
    $update_existing = isset( $_POST['update_existing'] ) && '1' === $_POST['update_existing'];
    $import_term_posts = isset( $_POST['import_term_posts'] ) && '1' === $_POST['import_term_posts'];

    if ( empty( $import_data ) ) {
        wp_send_json_error( array( 'error' => __( 'No import data provided.', 'hypertaxonomy' ) ) );
        return;
    }

    // Validate import data
    $validation = htax_validate_import_data( $import_data );
    if ( is_wp_error( $validation ) ) {
        wp_send_json_error( array( 'error' => $validation->get_error_message() ) );
        return;
    }

    // Process import with output
    ob_start();
    htax_process_import_with_output( $import_data, array(
        'update_existing'   => $update_existing,
        'import_term_posts' => $import_term_posts,
    ) );
    $html = ob_get_clean();

    wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_htax_import', 'htax_ajax_import' );

/**
 * AJAX: count hidden descendants for one or more terms.
 *
 * Used by the "make visible" flow to decide whether to show the cascade prompt.
 * Returns an object keyed by term_id, each with a `hidden_descendants` count.
 *
 * @since 1.0.0
 */
function htax_ajax_count_hidden_descendants() {
    check_ajax_referer( 'htax-visibility', 'nonce' );

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_visibility' ) ) {
        wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'hypertaxonomy' ) ), 403 );
    }

    $term_ids = isset( $_POST['term_ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['term_ids'] ) ) : array();
    $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';

    if ( empty( $term_ids ) || ! taxonomy_exists( $taxonomy ) || ! is_taxonomy_hierarchical( $taxonomy ) ) {
        wp_send_json_success( array( 'counts' => array() ) );
    }

    $hidden_set   = array_flip( htax_get_all_hidden_term_ids() );
    $selected_set = array_flip( $term_ids ); // descendants already in the selection don't need cascade
    $counts       = array();

    foreach ( $term_ids as $term_id ) {
        $descendants = get_term_children( $term_id, $taxonomy );
        if ( is_wp_error( $descendants ) || empty( $descendants ) ) {
            $counts[ $term_id ] = 0;
            continue;
        }
        $counts[ $term_id ] = count( array_filter( $descendants, function ( $id ) use ( $hidden_set, $selected_set ) {
            return isset( $hidden_set[ $id ] ) && ! isset( $selected_set[ $id ] );
        } ) );
    }

    wp_send_json_success( array( 'counts' => $counts ) );
}

add_action( 'wp_ajax_htax_count_hidden_descendants', 'htax_ajax_count_hidden_descendants' );

/**
 * AJAX handler for Settings → Search → Clear Scores: removes every score
 * stored for an external strength model. If that model is the selected
 * strength model, the selection switches back to the built-in model, since
 * a model with no scores would leave term-based ranking empty.
 *
 * @since 1.0.0
 */
function htax_ajax_clear_strength_model() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax-clear-strength-model' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed.', 'hypertaxonomy' ) ) );
        return;
    }
    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_hypertaxonomy_settings' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) ) );
        return;
    }

    $model   = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
    $removed = htax_remove_term_strengths( $model );
    if ( is_wp_error( $removed ) ) {
        wp_send_json_error( array( 'message' => $removed->get_error_message() ) );
        return;
    }

    $options = get_option( 'htax_settings', array() );
    if ( isset( $options['strength_model'] ) && $model === $options['strength_model'] ) {
        $options['strength_model'] = HTAX_DEFAULT_STRENGTH_MODEL;
        update_option( 'htax_settings', $options );
    }

    wp_send_json_success( array( 'removed' => $removed ) );
}
add_action( 'wp_ajax_htax_clear_strength_model', 'htax_ajax_clear_strength_model' );
