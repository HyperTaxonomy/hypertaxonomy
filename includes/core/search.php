<?php
/**
 * Search Functions
 *
 * All search-related functionality including:
 * - Redirect resolution for search queries
 * - WordPress search enhancement
 * - Orderby parameter parsing and application
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Extract post IDs from a search results array
 *
 * @param array $results Array of result rows, each expected to have a 'post_id' key
 * @return array Validated list of integer post IDs
 */
function htax_extract_post_ids( $results ) {
    $post_ids = array();
    foreach ( $results as $result ) {
        if ( is_array( $result ) && isset( $result['post_id'] ) ) {
            $post_ids[] = $result['post_id'];
        }
    }
    return $post_ids;
}

// ============================================================================
// SEARCH ENHANCEMENT
// ============================================================================

/**
 * Get the parsed sort order for a term's archive.
 *
 * Returns the term's `_htax_sort_order` meta value parsed into a WP_Query-compatible
 * array orderby structure: [ 'date' => 'DESC', 'title' => 'ASC' ].
 * Only archive-valid fields are returned (search-only fields like relevance
 * are excluded). Returns an empty array when no sort order is configured.
 *
 * @since 1.0.0
 *
 * @param int $term_id Term ID.
 * @return array Parsed orderby array suitable for WP_Query's 'orderby' argument, or [].
 */
function htax_get_term_sort_order( $term_id ) {
    $sort_order_str = get_term_meta( (int) $term_id, '_htax_sort_order', true );
    if ( ! $sort_order_str ) {
        return array();
    }

    $archive_fields = array( 'date', 'modified', 'title', 'author', 'comment_count' );
    $parsed         = htax_parse_orderby( $sort_order_str );

    return array_intersect_key( $parsed, array_flip( $archive_fields ) );
}

/**
 * Parse orderby parameter into array of field=>order pairs
 *
 * Format: "field:order,field:order" or "field,field" (defaults to DESC)
 * Example: "relevance:desc,date:desc" or "date,title:asc"
 *
 * @param string $orderby Orderby string
 * @return array Array of field=>order pairs, e.g. ['relevance' => 'DESC', 'date' => 'DESC']
 */
function htax_parse_orderby( $orderby ) {
    $parsed = array();
    $valid_fields = array( 'relevance', 'date', 'modified', 'title', 'author', 'comment_count', 'last_comment', 'last_activity', 'ID' );

    // Split by comma
    $parts = array_map( 'trim', explode( ',', $orderby ) );

    foreach ( $parts as $part ) {
        // Check if it contains a colon (field:order format)
        if ( strpos( $part, ':' ) !== false ) {
            list( $field, $order ) = array_map( 'trim', explode( ':', $part, 2 ) );
        } else {
            // No colon, use default DESC order
            $field = $part;
            $order = 'desc';
        }

        $field = strtolower( $field );
        $order = strtoupper( $order );

        // Validate field and order
        if ( in_array( $field, $valid_fields, true ) && in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
            $parsed[ $field ] = $order;
        }
    }

    // Default to date:DESC if nothing valid was parsed
    if ( empty( $parsed ) ) {
        $parsed['date'] = 'DESC';
    }

    return $parsed;
}

/**
 * Build a SQL ORDER BY clause string from a parsed orderby array.
 *
 * Centralises the field→column mapping used by every custom SQL query so
 * that all callers stay consistent (search widget, post list shortcode, etc.).
 * The alias must refer to wp_htax_post_text (column names: post_date_gmt,
 * post_modified_gmt, title, post_id, last_comment_gmt).
 *
 * @since 1.0.0
 *
 * @param array  $orderby_parsed   Output of htax_parse_orderby().
 * @param string $post_alias       Table alias for wp_htax_post_text. Default 'pt'.
 * @param string $relevance_column Column name for relevance. Default 'term_relevance'.
 * @return string Comma-separated ORDER BY expression, ready to interpolate into SQL.
 */
function htax_build_orderby_sql( $orderby_parsed, $post_alias = 'pt', $relevance_column = 'term_relevance' ) {
    $order_parts = array();
    $first_dir   = null;
    foreach ( $orderby_parsed as $field => $dir ) {
        $dir       = ( 'ASC' === $dir ) ? 'ASC' : 'DESC';
        if ( null === $first_dir ) {
            $first_dir = $dir;
        }
        switch ( $field ) {
            case 'relevance':
                $order_parts[] = "$relevance_column $dir";
                break;
            case 'date':
                $order_parts[] = "{$post_alias}.post_date_gmt $dir";
                break;
            case 'modified':
                $order_parts[] = "{$post_alias}.post_modified_gmt $dir";
                break;
            case 'title':
                $order_parts[] = "{$post_alias}.title $dir";
                break;
            case 'author':
                $order_parts[] = "{$post_alias}.post_author $dir";
                break;
            case 'comment_count':
                $order_parts[] = "{$post_alias}.comment_count $dir";
                break;
            case 'last_comment':
                $order_parts[] = "COALESCE({$post_alias}.last_comment_gmt, '0000-00-00 00:00:00') $dir";
                break;
            case 'last_activity':
                $order_parts[] = "GREATEST({$post_alias}.post_modified_gmt, COALESCE({$post_alias}.last_comment_gmt, '0000-00-00 00:00:00')) $dir";
                break;
            case 'ID':
                $order_parts[] = "{$post_alias}.post_id $dir";
                break;
        }
    }
    if ( empty( $order_parts ) ) {
        return "{$post_alias}.post_date_gmt DESC, {$post_alias}.post_id DESC";
    }
    if ( ! isset( $orderby_parsed['ID'] ) ) {
        $order_parts[] = "{$post_alias}.post_id " . ( $first_dir ?? 'DESC' );
    }
    return implode( ', ', $order_parts );
}

/**
 * Get configured relevance weights for a taxonomy, falling back to defaults.
 *
 * Merges user settings with htax_get_default_relevance_weights() so callers
 * always receive a complete weight array without needing to handle the fallback.
 *
 * @since 1.0
 *
 * @param string     $taxonomy Taxonomy slug.
 * @param array|null $options  htax_settings value to read from; null reads the stored option.
 *                             Lets callers compare weights between old and new settings.
 * @return array Weights keyed by relationship type ('direct', 'hierarchy', 'container', 'redirect').
 */
function htax_get_relevance_weights( string $taxonomy, ?array $options = null ): array {
    if ( null === $options ) {
        $options = get_option( 'htax_settings', array() );
    }
    $multipliers = isset( $options['relevance_multipliers'] ) ? $options['relevance_multipliers'] : array();
    $configured  = isset( $multipliers['taxonomies'][ $taxonomy ] ) ? $multipliers['taxonomies'][ $taxonomy ] : array();
    return ! empty( $configured ) ? $configured : htax_get_default_relevance_weights( $taxonomy );
}

function htax_get_default_relevance_weights( $taxonomy ) {
    if ( is_taxonomy_hierarchical( $taxonomy ) ) {
        return array(
            'direct'    => array( 'explicit' => 1.0,  'text' => 0.6  ),
            'hierarchy' => array( 'explicit' => 0.7,  'text' => 0.4  ),
            'container' => array( 'explicit' => 0.75, 'text' => 0.5  ),
            'redirect'  => array( 'explicit' => 0.5,  'text' => 0.3  ),
        );
    }
    return array(
        'direct'    => array( 'explicit' => 1.0,  'text' => 0.6  ),
        'container' => array( 'explicit' => 0.75, 'text' => 0.5  ),
        'redirect'  => array( 'explicit' => 0.6,  'text' => 0.35 ),
    );
}

/**
 * Fetch term IDs whose name exactly matches the given string (case-insensitive).
 *
 * Used by PowerSearch exact match scoring to give a flat name_exact weight to
 * terms that precisely equal the search string, rather than mixing them in with
 * FULLTEXT partial-match scores.
 *
 * @since 1.0.0
 *
 * @param string $term_string  The search string to match against term names.
 * @param string $taxonomy     Taxonomy slug to restrict the lookup. Empty = all taxonomies.
 * @return int[] Array of matching term IDs.
 */
function htax_get_exact_match_term_ids( string $term_string, string $taxonomy = '' ): array {
    global $wpdb;
    // Collation-based case-insensitive equality (WordPress default: utf8mb4_unicode_ci / _general_ci).
    // Using name = %s (not LOWER(name) = LOWER(%s)) lets MySQL use the existing B-tree name index.
    if ( '' !== $taxonomy ) {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE t.name = %s AND tt.taxonomy = %s",
            $term_string, $taxonomy
        ) );
    } else {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT term_id FROM {$wpdb->terms} WHERE name = %s",
            $term_string
        ) );
    }
    return array_map( 'intval', (array) $ids );
}

/**
 * Apply orderby parameters to WP_Query args or query object
 *
 * Removes 'term_relevance' field (only works with text search) and sets appropriate
 * orderby/order parameters for WP_Query.
 *
 * @param array $orderby_parsed Parsed orderby array from htax_parse_orderby()
 * @param array|WP_Query $query_or_args WP_Query args array or WP_Query object (passed by reference)
 * @return array|void Returns modified args array if $query_or_args is array, otherwise modifies WP_Query object directly
 */
function htax_apply_orderby_to_wp_query( $orderby_parsed, &$query_or_args ) {
    // Remove fields that WP_Query has no native support for.
    $wp_orderby = $orderby_parsed;
    unset( $wp_orderby['relevance'] );
    unset( $wp_orderby['last_comment'] );
    unset( $wp_orderby['last_activity'] );

    $is_query_object = is_a( $query_or_args, 'WP_Query' );

    // Set WP_Query orderby
    if ( 1 === count( $wp_orderby ) ) {
        // Single orderby
        $field = key( $wp_orderby );
        $order = current( $wp_orderby );
        if ( $is_query_object ) {
            $query_or_args->set( 'orderby', $field );
            $query_or_args->set( 'order', $order );
        } else {
            $query_or_args['orderby'] = $field;
            $query_or_args['order'] = $order;
        }
    } elseif ( count( $wp_orderby ) > 1 ) {
        // Multiple orderby (WP 4.0+)
        if ( $is_query_object ) {
            $query_or_args->set( 'orderby', $wp_orderby );
        } else {
            $query_or_args['orderby'] = $wp_orderby;
        }
    } else {
        // No valid fields left, fall back to date
        if ( $is_query_object ) {
            $query_or_args->set( 'orderby', 'date' );
            $query_or_args->set( 'order', 'DESC' );
        } else {
            $query_or_args['orderby'] = 'date';
            $query_or_args['order'] = 'DESC';
        }
    }

    // Return modified args if it's an array
    if ( ! $is_query_object ) {
        return $query_or_args;
    }
}

/**
 * Enhance WordPress search
 */
function htax_enhance_search_query( $query ) {
    if ( ! $query->is_search() || ! $query->is_main_query() || is_admin() ) {
        return;
    }

    // PowerSearch POST handler (htax_handle_power_post_search) already ran at priority 5
    // and set up post__in from its own results. Do not override it.
    if ( ! empty( $_POST['htax_power_post_search'] ) ) {
        return;
    }

    // Search Widget POST handler also owns its request.
    if ( ! empty( $_POST['htax_post_search'] ) ) {
        return;
    }

    // Check if enhanced search is enabled (either globally or via parameter)
    $options = get_option( 'htax_settings', array() );
    $always_enhance = ! empty( $options['always_enhance_search'] );
    $param_enhance = isset( $_GET['htax_search'] );

    if ( ! $always_enhance && ! $param_enhance ) {
        return;
    }

    $search_in = isset( $_GET['htax_search_in'] ) ? sanitize_text_field( wp_unslash( $_GET['htax_search_in'] ) ) : 'all';

    // Get orderby from URL or settings
    $default_orderby = isset( $options['enhanced_search_orderby'] ) && ! empty( $options['enhanced_search_orderby'] )
        ? $options['enhanced_search_orderby']
        : 'relevance:desc,date:desc';
    $orderby_string = isset( $_GET['htax_orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['htax_orderby'] ) ) : $default_orderby;

    // Parse orderby parameter
    $orderby_parsed = htax_parse_orderby( $orderby_string );

    // An empty search (?s=) keeps WordPress's own behavior: every post, newest first.
    $search_text = get_search_query( false );
    if ( '' === trim( $search_text ) ) {
        return;
    }

    // Use the PowerSearch engine for the requested page; it sorts by the full orderby
    // (relevance included), so the page keeps the engine's order. Page size is what
    // WordPress would use: posts_per_rss for search feeds, the query's or the Reading
    // setting otherwise.
    if ( $query->is_feed() ) {
        $posts_per_page = (int) get_option( 'posts_per_rss', 10 );
    } else {
        $posts_per_page = (int) $query->get( 'posts_per_page' ) > 0 ? (int) $query->get( 'posts_per_page' ) : (int) get_option( 'posts_per_page', 10 );
    }
    $paged          = htax_get_search_page_number( $query );
    $power_search   = HyperTaxonomy_Power_Search::get_instance();
    $search_result  = $power_search->perform_indexed_text_search( $search_text, array(
        'search_in'      => $search_in,
        'orderby'        => $orderby_parsed,
        'posts_per_page' => $posts_per_page,
        'page'           => $paged,
    ) );

    // Clear the search term so WordPress doesn't run its own search; titles read the
    // text from htax_search_text (htax_filter_search_query_text()).
    $query->set( 's', '' );
    $query->set( 'htax_search_text', $search_text );

    $post_ids = is_array( $search_result ) && ! empty( $search_result['results'] ) ? htax_extract_post_ids( $search_result['results'] ) : array();
    htax_apply_search_page_to_query( $query, $post_ids, is_array( $search_result ) ? (int) $search_result['total'] : 0, $posts_per_page, $paged );
}


/**
 * Handle POST/GET form submissions from HyperTaxonomy search widget.
 *
 * @since 1.0.0
 * @param WP_Query $query The WordPress Query object.
 */
function htax_handle_widget_post_search( $query ) {
    // Check if this is a HyperTaxonomy search widget POST submission
    if ( empty( $_POST['htax_post_search'] ) ) {
        return;
    }

    // Only run on main query and not in admin
    if ( ! $query->is_main_query() || is_admin() ) {
        return;
    }

    $data  = $_POST;
    $nonce = isset( $data['htax_search_nonce'] ) ? sanitize_text_field( wp_unslash( $data['htax_search_nonce'] ) ) : '';

    // Verify nonce — a state restored from a results URL was verified when submitted.
    if ( ! htax_is_search_state_request() && ! wp_verify_nonce( $nonce, 'htax-search-widget' ) ) {
        return;
    }

    // Get the search widget instance to use its processing methods
    $widget = HyperTaxonomy_Search_Widget::get_instance();
    if ( ! $widget ) {
        return;
    }

    // Force the query to be a search query and clear conflicting page/single flags.
    $query->is_search   = true;
    $query->is_home     = false;
    $query->is_page     = false;
    $query->is_single   = false;
    $query->is_singular = false;
    $query->set( 'pagename', '' );
    $query->set( 'page_id', 0 );
    $query->set( 'name', '' );
    $query->set( 'p', 0 );

    // Restrict to indexed post types
    $query->set( 'post_type', htax_get_indexed_post_types() );
    $query->set( 'post_status', 'publish' );

    // Get field data from JSON, or build from individual POST fields (JS-disabled fallback)
    if ( ! empty( $data['htax_field_data'] ) ) {
        // POST submission with JSON field data
        $field_data_json = wp_unslash( $data['htax_field_data'] );
        $field_data = json_decode( $field_data_json, true );
        if ( ! is_array( $field_data ) ) {
            $field_data = array();
        }
    } else {
        // No JSON field data — build from individual POST fields (JS-disabled fallback)
        $field_data = array();

        // Read all taxonomy parameters from POST
        foreach ( $data as $key => $value ) {
            // Skip system fields
            if ( in_array( $key, array( 'htax_search_nonce', '_wp_http_referer', 'htax_orderby', 'htax_post_search', 'htax_field_data' ), true ) ) {
                continue;
            }

            // Skip empty values
            if ( empty( $value ) ) {
                continue;
            }

            // Check if this is a taxonomy field (remove trailing [] if present)
            $taxonomy = preg_replace( '/\[\]$/', '', $key );

            if ( taxonomy_exists( $taxonomy ) ) {
                // Handle array values (checkboxes - categories[]=423)
                if ( is_array( $value ) ) {
                    $term_ids = array_map( 'intval', array_filter( $value ) );
                    // Filter out hidden terms if user doesn't have permission
                    $term_ids = htax_get_visible_term_ids( $term_ids );
                    if ( ! empty( $term_ids ) ) {
                        $field_data[ $taxonomy ] = array(
                            'taxonomy' => $taxonomy,
                            'terms' => $term_ids,
                            'match' => 'any',
                            'type' => 'checklist'
                        );
                    }
                } else {
                    // Handle comma-separated values (pills - tags=1,2,3)
                    $term_ids = array_map( 'intval', array_filter( explode( ',', $value ) ) );
                    // Filter out hidden terms if user doesn't have permission
                    $term_ids = htax_get_visible_term_ids( $term_ids );
                    if ( ! empty( $term_ids ) ) {
                        $field_data[ $taxonomy ] = array(
                            'taxonomy' => $taxonomy,
                            'terms' => $term_ids,
                            'match' => 'any',
                            'type' => 'pills'
                        );
                    }
                }
            } else {
                // Non-taxonomy field - treat as text search field
                // This allows any field name to be used for text search
                if ( ! is_array( $value ) ) {
                    $field_data[ $key ] = array(
                        'type' => 'text',
                        'value' => sanitize_text_field( $value )
                    );
                }
            }
        }
    }

    // Extract text search and check for taxonomy terms using helper methods
    $text_search_data = $widget->extract_text_search_from_fields( $field_data );
    $text_search_query = $text_search_data['query'];
    $text_search_in = $text_search_data['search_in'];
    $has_text_search = ! empty( $text_search_query );
    $has_taxonomy_terms = $widget->has_taxonomy_terms_in_fields( $field_data );

    // Parse orderby
    $orderby_string = ! empty( $data['htax_orderby'] )
        ? sanitize_text_field( wp_unslash( $data['htax_orderby'] ) )
        : 'relevance:desc,date:desc';
    $orderby_parsed = htax_parse_orderby( $orderby_string );

    // Check if user wants term relevance scoring
    $wants_term_relevance = isset( $orderby_parsed['relevance'] );

    // Pagination: the form's posts_per_page / max_results (WordPress's Reading setting
    // when absent); the page comes from /page/N/ on the tokenized results URL.
    $posts_per_page = ! empty( $data['htax_posts_per_page'] ) ? max( 1, (int) $data['htax_posts_per_page'] ) : (int) get_option( 'posts_per_page', 10 );
    $max_results    = isset( $data['htax_max_results'] ) ? max( 0, (int) $data['htax_max_results'] ) : 0;
    $paged          = htax_get_search_page_number( $query );

    // Custom SQL handles every case that has taxonomy terms or text — this covers nested
    // field groups, AND/OR logic, HT inclusion expansion via the search index, exclusions,
    // relevance scoring, and FULLTEXT search consistently without WordPress memory overhead.
    // WP_Query is used only for the empty-filter case (no terms, no text) so that standard
    // orderby, post type filters, and the existing query context apply without interference.
    if ( $has_taxonomy_terms || $has_text_search ) {
        // The form's min_score, as in AJAX mode (absent = no threshold).
        $min_score     = isset( $data['htax_min_score'] ) ? max( 0.0, (float) $data['htax_min_score'] ) : 0.0;
        $search_result = $widget->get_posts_by_custom_sql( $field_data, $orderby_parsed, $posts_per_page, $paged, $text_search_query, $min_score, $text_search_in, '', $max_results );
        $total         = (int) $search_result['total'];
        htax_apply_search_page_to_query( $query, array_map( 'intval', $search_result['results'] ), $max_results > 0 ? min( $total, $max_results ) : $total, $posts_per_page, $paged );
        if ( $has_text_search ) {
            $query->set( 'htax_search_text', $text_search_query );
        }
        return;
    }

    // Empty filter — no taxonomy terms and no text. Let WP_Query run with only the orderby
    // override so standard WordPress behaviour (post types, date ranges, etc.) is preserved.
    if ( ! empty( $orderby_parsed ) ) {
        htax_apply_orderby_to_wp_query( $orderby_parsed, $query );
    }
}
// Hook will be registered in includes/hooks/actions.php

/**
 * Prevent query-running Gutenberg blocks from executing on search-results pages.
 *
 * Blocks such as core/latest-posts and core/query issue their own WP_Query during
 * block rendering. If a page containing such a block appears in search results, any
 * plugin that processes post content during the request (e.g. to generate structured
 * data) can trigger cascading queries that exhaust PHP memory. Returning an empty
 * string short-circuits render_block() for the listed block types.
 *
 * Registered only for htax PowerSearch POST requests.
 *
 * @param string|null $pre       Existing override, or null to render normally.
 * @param array       $block     Parsed block data.
 * @return string|null Empty string to suppress, or $pre to pass through.
 */
function htax_suppress_query_blocks_on_search( $pre, $block ) {
    static $query_blocks = array(
        'core/latest-posts',
        'core/query',
        'core/post-template',
        'core/post-content',
    );
    if ( isset( $block['blockName'] ) && in_array( $block['blockName'], $query_blocks, true ) ) {
        return '';
    }
    return $pre;
}

/**
 * Extract plain text from post HTML content, preserving semantically useful
 * attribute values and explicitly removing struck-through content.
 *
 * Processing order:
 * 1. Expand <a href="URL">text</a> → "text [URL]" so domain names are searchable.
 * 2. Expand <img alt="ALT"> → "ALT" so image descriptions are searchable.
 * 3. Expand <abbr title="FULL">abbr</abbr> → "abbr FULL" so abbreviations resolve.
 * 4. Strip remaining HTML tags.
 *
 * Struck-through content (<del>, <s>) is intentionally kept — authors sometimes
 * use strikethrough as an editorial rephrase and the original wording remains meaningful.
 *
 * @since 1.0
 *
 * @param string $html Raw post_content HTML.
 * @return string Plain text suitable for FULLTEXT indexing.
 */
function htax_extract_post_body( string $html ): string {
    // 1. Links: preserve href alongside anchor text.
    $html = preg_replace_callback(
        '/<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
        function ( $m ) {
            $text = trim( wp_strip_all_tags( $m[2] ) );
            $url  = trim( $m[1] );
            return $text !== '' && $url !== '' ? "$text [$url]" : $text . $url;
        },
        $html
    );

    // 2. Images: preserve alt text.
    $html = preg_replace_callback(
        '/<img\b[^>]*\balt=["\']([^"\']*)["\'][^>]*\/?>/is',
        function ( $m ) {
            return trim( $m[1] );
        },
        $html
    );

    // 3. Abbreviations: append expanded form so both forms are searchable.
    $html = preg_replace_callback(
        '/<(?:abbr|acronym)\b[^>]*\btitle=["\']([^"\']*)["\'][^>]*>(.*?)<\/(?:abbr|acronym)>/is',
        function ( $m ) {
            $abbr = trim( wp_strip_all_tags( $m[2] ) );
            $full = trim( $m[1] );
            return $abbr !== '' && $full !== '' ? "$abbr $full" : $abbr . $full;
        },
        $html
    );

    // 4. Strip remaining HTML.
    return wp_strip_all_tags( $html );
}

/**
 * Sync one post into wp_htax_post_text on save.
 *
 * Fires on save_post. Inserts or replaces the row for published, draft, and
 * pending posts; deletes the row when a post transitions to trash or auto-draft.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post object.
 */
function htax_sync_post_text_row( $post_id, $post ) {
    global $wpdb;

    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }

    $table = $wpdb->prefix . 'htax_post_text';

    // Current post_type is not one we index. This also catches a post whose type
    // changed away from an indexed type (e.g. post -> a custom type) without a
    // status transition — delete any pre-existing row so it doesn't go stale.
    $indexed_types = htax_get_indexed_post_types();
    if ( ! in_array( $post->post_type, $indexed_types, true ) ) {
        $wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
        return;
    }

    // Store published, draft, and pending posts. Remove everything else (trash,
    // auto-draft, inherit) so the table never holds truly stale content.
    $indexed_statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );
    if ( ! in_array( $post->post_status, $indexed_statuses, true ) ) {
        $wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
        return;
    }

    if ( (int) $post->comment_count > 0 ) {
        $last_comment_gmt = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(comment_date_gmt) FROM {$wpdb->comments}
             WHERE comment_post_ID = %d AND comment_approved = '1'",
            $post_id
        ) );
    } else {
        $last_comment_gmt = null;
    }

    $wpdb->replace(
        $table,
        array(
            'post_id'           => $post_id,
            'post_status'       => $post->post_status,
            'post_type'         => $post->post_type,
            'post_date_gmt'     => $post->post_date_gmt,
            'post_modified_gmt' => $post->post_modified_gmt,
            'post_author'       => (int) $post->post_author,
            'comment_count'     => (int) $post->comment_count,
            'last_comment_gmt'  => $last_comment_gmt ?: null,
            'title'             => $post->post_title,
            'body'              => htax_extract_post_body( $post->post_content ),
            'excerpt'           => $post->post_excerpt,
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
    );
}

/**
 * Sync comment_count and last_comment_gmt in wp_htax_post_text.
 *
 * Fires on wp_update_comment_count (3 args: post_id, new_count, old_count).
 * The third argument is accepted by WordPress but not needed here.
 *
 * @param int $post_id   Post ID.
 * @param int $new_count New comment count.
 */
function htax_sync_post_text_comment( $post_id, $new_count ) {
    global $wpdb;

    $table = $wpdb->prefix . 'htax_post_text';

    // Only update rows that already exist in our table.
    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE post_id = %d", $post_id ) );
    if ( ! $exists ) {
        return;
    }

    $last_comment_gmt = $wpdb->get_var( $wpdb->prepare(
        "SELECT MAX(comment_date_gmt) FROM {$wpdb->comments}
         WHERE comment_post_ID = %d AND comment_approved = '1'",
        $post_id
    ) );

    $wpdb->update(
        $table,
        array(
            'comment_count'   => (int) $new_count,
            'last_comment_gmt' => $last_comment_gmt ?: null,
        ),
        array( 'post_id' => $post_id ),
        array( '%d', '%s' ),
        array( '%d' )
    );
}

/**
 * Handle PowerSearch POST submissions
 *
 * This function intercepts PowerSearch POST requests and modifies
 * the main WordPress query to show search results using the PowerSearch index.
 *
 * @param WP_Query $query The WordPress query object
 */
function htax_handle_power_post_search( $query ) {
    // Check if this is a PowerSearch POST
    if ( empty( $_POST['htax_power_post_search'] ) || empty( $_POST['query'] ) ) {
        return; // Not a PowerSearch, skip
    }

    // Only run on main query and not in admin
    if ( ! $query->is_main_query() || is_admin() ) {
        return;
    }

    // Verify nonce
    $nonce = isset( $_POST['htax_power_search_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_power_search_nonce'] ) ) : '';
    // A state restored from a results URL was verified when submitted.
    if ( ! htax_is_search_state_request() && ! wp_verify_nonce( $nonce, 'htax-power-search' ) ) {
        return;
    }

    // Suppress query-running Gutenberg blocks (core/latest-posts, core/query, etc.) for the
    // entire lifetime of this search-results request. Without this guard, a page that contains
    // such a block and appears in the search results can recursively execute nested queries
    // during content processing (e.g. Yoast SEO reading post content for structured data),
    // each of which repeats the cycle and exhausts available memory.
    add_filter( 'pre_render_block', 'htax_suppress_query_blocks_on_search', 10, 2 );

    // Get the PowerSearch instance
    $power_search = HyperTaxonomy_Power_Search::get_instance();
    if ( ! $power_search ) {
        return;
    }

    // Get search parameters from POST
    $search_query = sanitize_text_field( wp_unslash( $_POST['query'] ) );
    $min_length = isset( $_POST['htax_min_length'] ) ? intval( wp_unslash( $_POST['htax_min_length'] ) ) : 3;

    // Validate minimum length
    if ( mb_strlen( $search_query ) < $min_length ) {
        // Set query to return no results
        $query->set( 'post__in', array( 0 ) );
        return;
    }

    // Build search config from POST data
    $orderby_string = isset( $_POST['htax_orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_orderby'] ) ) : 'relevance:desc,date:desc';
    $orderby_parsed = htax_parse_orderby( $orderby_string );

    $posts_per_page = isset( $_POST['htax_posts_per_page'] ) ? max( 1, intval( wp_unslash( $_POST['htax_posts_per_page'] ) ) ) : htax_get_search_results_per_page();
    $paged          = htax_get_search_page_number( $query );
    $config         = array(
        'search_in'      => isset( $_POST['htax_search_in'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_search_in'] ) ) : 'all',
        'orderby'        => $orderby_parsed,
        'posts_per_page' => $posts_per_page,
        'max_results'    => isset( $_POST['htax_max_results'] ) ? max( 0, intval( wp_unslash( $_POST['htax_max_results'] ) ) ) : 0,
        'page'           => $paged,
        'match'          => isset( $_POST['htax_match'] ) && 'all' === sanitize_key( wp_unslash( $_POST['htax_match'] ) ) ? 'all' : htax_get_default_match_mode(),
        'near_country'   => isset( $_POST['htax_near_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['htax_near_country'] ) ) ) : 'US',
    );
    // The form's min_score / min_*_score thresholds (htax_-prefixed hidden fields), as in AJAX mode.
    $power_search->apply_score_thresholds( $config, wp_unslash( $_POST ), 'htax_' );

    // Perform PowerSearch
    $search_result = $power_search->perform_indexed_text_search( $search_query, $config );

    // Force the query to be a search query and clear conflicting page/single flags.
    // Without this, when the form is placed on a page (e.g. /search/) the query
    // still has is_page=true and pagename set from URL parsing. WP_Query then adds
    // AND ID = {page_id} to WHERE, which conflicts with our post__in list.
    $query->is_search   = true;
    $query->is_home     = false;
    $query->is_page     = false;
    $query->is_single   = false;
    $query->is_singular = false;
    $query->set( 'pagename', '' );
    $query->set( 'page_id', 0 );
    $query->set( 'name', '' );
    $query->set( 'p', 0 );

    // Clear any existing native WordPress search parameter to prevent mixing searches
    $query->set( 's', '' );

    // Keep the search text in a custom query var instead of 's'; get_search_query()
    // reports it for titles (htax_filter_search_query_text()).
    $query->set( 'htax_search_text', $search_query );

    $post_ids = is_array( $search_result ) && ! empty( $search_result['results'] ) ? htax_extract_post_ids( $search_result['results'] ) : array();
    $total    = is_array( $search_result ) ? (int) $search_result['total'] : 0;
    if ( $config['max_results'] > 0 ) {
        $total = min( $total, $config['max_results'] );
    }
    htax_apply_search_page_to_query( $query, $post_ids, $total, $posts_per_page, $paged );
}
// Hook will be registered in includes/hooks/actions.php

// ==================================================
// AJAX RESPONSE FORMATTING
// ==================================================

/**
 * Format a post object for search result display
 *
 * Converts a WP_Post object into a standardized array format for AJAX responses.
 * Used across PowerSearch and search widget to ensure consistent formatting.
 *
 * @since 1.0.0
 *
 * @param WP_Post $post       Post object to format
 * @param string  $image_size Thumbnail size (default: 'thumbnail')
 * @return array Formatted post data with keys: id, title, excerpt, permalink, thumbnail, date, author
 *
 * @example
 * // Format posts for AJAX response
 * $formatted_posts = array_map( function( $post ) {
 *     return htax_format_search_result_post( $post, 'medium' );
 * }, $query->posts );
 */
function htax_format_search_result_post( $post, $image_size = 'thumbnail' ) {
    return array(
        'id'            => $post->ID,
        'title'         => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
        'excerpt'       => html_entity_decode( wp_strip_all_tags( get_the_excerpt( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
        'permalink'     => get_permalink( $post ),
        'thumbnail'     => get_the_post_thumbnail_url( $post, $image_size ),
        'date'          => get_the_date( '', $post ),
        'modified'      => get_the_modified_date( '', $post ),
        'author'        => get_the_author_meta( 'display_name', $post->post_author ),
        'comment_count' => (int) $post->comment_count,
    );
}

/**
 * Build pagination response structure for search results
 *
 * Creates a standardized response array with pagination metadata.
 * Used across PowerSearch and search widget AJAX responses.
 *
 * @since 1.0.0
 *
 * @param array $posts          Array of formatted post data
 * @param int   $total_found    Total number of results found
 * @param int   $posts_per_page Number of posts per page
 * @param int   $current_page   Current page number
 * @param int   $max_results    Optional cap on total results (0 = no cap).
 * @return array Response array with keys: posts, found_posts, max_pages, current_page
 *
 * @example
 * // Build AJAX response
 * $response = htax_build_pagination_response(
 *     $formatted_posts,
 *     $total_found,
 *     10,
 *     1
 * );
 * wp_send_json_success( $response );
 */
function htax_build_pagination_response( $posts, $total_found, $posts_per_page, $current_page, $max_results = 0 ) {
    $effective_total = ( $max_results > 0 ) ? min( $total_found, (int) $max_results ) : $total_found;
    return array(
        'posts'         => $posts,
        'found_posts'   => $effective_total,
        /* translators: %s: number of search results */
        'results_label' => sprintf( _n( '%s result found', '%s results found', $effective_total, 'hypertaxonomy' ), number_format_i18n( $effective_total ) ),
        'max_pages'     => $posts_per_page > 0 ? (int) ceil( $effective_total / $posts_per_page ) : 1,
        'current_page'  => $current_page,
        'notices'       => htax_get_search_notices(),
    );
}

/**
 * Record a message for the visitor about how the current search was run.
 *
 * Used when a search could not be run exactly as asked — e.g. near:me when the visitor's
 * location is unknown. Search responses include the messages (AJAX: a `notices` array;
 * POST-mode results pages: printed above the results by htax_print_search_notices()).
 *
 * @since 1.0.0
 * @param string $code    Identifier; a second notice with the same code replaces the first.
 * @param string $message Plain-text message.
 */
function htax_add_search_notice( string $code, string $message ): void {
    $GLOBALS['htax_search_notices'][ $code ] = $message;
}

/**
 * Messages recorded with htax_add_search_notice() for the current request.
 *
 * @since 1.0.0
 * @return string[]
 */
function htax_get_search_notices(): array {
    return array_values( $GLOBALS['htax_search_notices'] ?? array() );
}

/**
 * Print search notices above the results of a POST-mode / native search results page.
 *
 * Hooks: loop_start and loop_no_results, main query only; printed once.
 *
 * @since 1.0.0
 * @param WP_Query $query Query whose loop is starting.
 */
function htax_print_search_notices( $query ): void {
    static $printed = false;
    if ( $printed || ! ( $query instanceof WP_Query ) || ! $query->is_main_query() || ! $query->is_search() ) {
        return;
    }
    $notices = htax_get_search_notices();
    if ( empty( $notices ) ) {
        return;
    }
    $printed = true;
    foreach ( $notices as $notice ) {
        echo '<p class="htax-search-notice" role="status">' . esc_html( $notice ) . '</p>';
    }
}

// ==================================================
// POST RESULT PLACEHOLDERS
// ==================================================

/**
 * Replace post placeholder variables in a template string.
 *
 * Used by [ht_post_list], [ht_related_posts], and [ht_search_results]
 * to process prepend/append content around each result item.
 *
 * Supported placeholders:
 *   {id}            — Post ID (integer)
 *   {title}         — Post title (escaped)
 *   {url}           — Post permalink (escaped URL)
 *   {date}          — Publish date in the site's configured date format
 *   {modified}      — Last modified date in the site's configured date format
 *   {comment_count} — Number of comments (integer)
 *   {post_type}     — Post type slug (escaped)
 *   {slug}          — Post slug / post_name (escaped)
 *   {author}        — Author display name (escaped) [lazy: user meta query]
 *   {excerpt}       — Post excerpt with HTML preserved (wp_kses_post, may be empty) [lazy]
 *   {excerpt_text}  — Post excerpt as plain text, HTML stripped (may be empty) [lazy: same call as excerpt]
 *   {image_src}     — Featured image URL, empty string if none [lazy: post meta + attachment queries]
 *   {image_alt}     — Featured image alt text, empty string if none [lazy: same queries as image_src]
 *   {image_width}   — Featured image width in pixels, empty string if none [lazy: same queries as image_src]
 *   {image_height}  — Featured image height in pixels, empty string if none [lazy: same queries as image_src]
 *
 * The output is passed through wp_kses_post() so HTML in prepend/append is
 * permitted within the same set of tags allowed in post content.
 *
 * @since 1.0.0
 *
 * @param string  $text Template string containing placeholder tokens.
 * @param WP_Post $post Post object.
 * @return string Template string with placeholders replaced and kses-filtered.
 */
function htax_process_post_placeholders( $text, WP_Post $post ) {
    if ( '' === $text ) {
        return '';
    }

    // Cheap replacements — all data already in the WP_Post object.
    $replacements = array(
        '{id}'            => $post->ID,
        '{title}'         => esc_html( get_the_title( $post ) ),
        '{url}'           => esc_url( get_permalink( $post ) ),
        '{date}'          => esc_html( get_the_date( '', $post ) ),
        '{modified}'      => esc_html( get_the_modified_date( '', $post ) ),
        '{comment_count}' => (int) $post->comment_count,
        '{post_type}'     => esc_html( $post->post_type ),
        '{slug}'          => esc_html( $post->post_name ),
    );

    // Lazy: author display name requires a user meta query.
    if ( strpos( $text, '{author}' ) !== false ) {
        $replacements['{author}'] = esc_html( get_the_author_meta( 'display_name', $post->post_author ) );
    }

    // Lazy: excerpt — both variants share the same get_the_excerpt() call.
    $needs_excerpt = strpos( $text, '{excerpt}' ) !== false || strpos( $text, '{excerpt_text}' ) !== false;
    if ( $needs_excerpt ) {
        $raw_excerpt = get_the_excerpt( $post );
        if ( strpos( $text, '{excerpt}' ) !== false ) {
            $replacements['{excerpt}'] = wp_kses_post( $raw_excerpt );
        }
        if ( strpos( $text, '{excerpt_text}' ) !== false ) {
            $replacements['{excerpt_text}'] = esc_html( wp_strip_all_tags( $raw_excerpt ) );
        }
    }

    // Lazy: featured image requires post meta + attachment post/meta queries.
    // When update_post_meta_cache is true on the parent WP_Query (the default),
    // get_post_thumbnail_id() is served from cache; wp_get_attachment_image_src()
    // primes the attachment's meta cache on first call, so {image_width} and
    // {image_height} are free once {image_src} has been resolved.
    $needs_image = strpos( $text, '{image_src}' ) !== false
        || strpos( $text, '{image_alt}' ) !== false
        || strpos( $text, '{image_width}' ) !== false
        || strpos( $text, '{image_height}' ) !== false;

    if ( $needs_image ) {
        $thumbnail_id = get_post_thumbnail_id( $post->ID );
        if ( $thumbnail_id ) {
            $img_src = wp_get_attachment_image_src( $thumbnail_id, 'full' );
            $replacements['{image_src}']    = $img_src ? esc_url( $img_src[0] ) : '';
            $replacements['{image_width}']  = $img_src ? (int) $img_src[1] : '';
            $replacements['{image_height}'] = $img_src ? (int) $img_src[2] : '';
            $replacements['{image_alt}']    = esc_attr( get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );
        } else {
            $replacements['{image_src}']    = '';
            $replacements['{image_width}']  = '';
            $replacements['{image_height}'] = '';
            $replacements['{image_alt}']    = '';
        }
    }

    // Lazy: {thumbnail} / {thumbnail:size} — full <img> HTML via get_the_post_thumbnail().
    // {thumbnail} uses 'medium'; {thumbnail:large} uses 'large', etc.
    if ( preg_match( '/\{thumbnail(?::[^}]+)?\}/', $text ) ) {
        preg_match_all( '/\{thumbnail(?::([^}]+))?\}/', $text, $tmatches, PREG_SET_ORDER );
        $done_tokens = array();
        foreach ( $tmatches as $tmatch ) {
            $token = $tmatch[0];
            if ( isset( $done_tokens[ $token ] ) ) {
                continue;
            }
            $done_tokens[ $token ] = true;
            $size = ! empty( $tmatch[1] ) ? sanitize_key( $tmatch[1] ) : 'medium';
            $replacements[ $token ] = get_the_post_thumbnail( $post->ID, $size );
        }
    }

    return wp_kses_post( strtr( $text, $replacements ) );
}

// ==================================================
// SEARCH UTILITIES
// ==================================================

/**
 * Build AND-filter SQL clauses for term-based post filtering.
 *
 * Produces zero or more SQL fragments (each beginning with AND) that restrict
 * a post query to posts matching the given filter criteria, without affecting
 * relevance scoring.  The fragments reference `pt.post_id`, so the calling
 * query must have `wp_htax_post_text` joined with the alias `pt`.
 *
 * Three independent filters are applied:
 * - $filter_term_ids       — post must be tagged with ALL of these terms.
 * - $filter_group_term_sets — post must match at least one term from EACH group
 *                             (AND across groups, OR within each group).
 * - $filter_out_term_ids   — post must not be tagged with ANY of these terms.
 *
 * @since 1.0.0
 *
 * @param int[]   $filter_term_ids       Term IDs that must ALL be present (AND).
 * @param int[][] $filter_group_term_sets Keyed by group ID; values are term ID arrays.
 *                                        One IN subquery per group (AND across groups).
 * @param int[]   $filter_out_term_ids   Term IDs where ANY match excludes the post.
 * @return string[] Array of SQL clause strings, each starting with 'AND'.
 */
function htax_build_term_filter_clauses( array $filter_term_ids, array $filter_group_term_sets, array $filter_out_term_ids ): array {
    global $wpdb;

    $clauses = array();

    // filter_terms: post must carry ALL of these terms.
    if ( ! empty( $filter_term_ids ) ) {
        $ft_count        = count( $filter_term_ids );
        $ft_placeholders = implode( ',', array_fill( 0, $ft_count, '%d' ) );
        $clauses[]       = $wpdb->prepare(
            "AND pt.post_id IN (
                SELECT post_id FROM {$wpdb->prefix}htax_search_terms
                WHERE term_id IN ($ft_placeholders)
                GROUP BY post_id HAVING COUNT(DISTINCT term_id) = %d
            )",
            ...array_merge( $filter_term_ids, array( $ft_count ) )
        );
    }

    // filter_groups: at least one term from each group must be present
    // (AND across groups, OR within each group).
    foreach ( $filter_group_term_sets as $fg_term_ids ) {
        $fg_count        = count( $fg_term_ids );
        $fg_placeholders = implode( ',', array_fill( 0, $fg_count, '%d' ) );
        $clauses[]       = $wpdb->prepare(
            "AND pt.post_id IN (
                SELECT post_id FROM {$wpdb->prefix}htax_search_terms
                WHERE term_id IN ($fg_placeholders)
            )",
            ...$fg_term_ids
        );
    }

    // filter_out: post must not carry ANY of these terms.
    if ( ! empty( $filter_out_term_ids ) ) {
        $fo_count        = count( $filter_out_term_ids );
        $fo_placeholders = implode( ',', array_fill( 0, $fo_count, '%d' ) );
        $clauses[]       = $wpdb->prepare(
            "AND pt.post_id NOT IN (
                SELECT post_id FROM {$wpdb->prefix}htax_search_terms
                WHERE term_id IN ($fo_placeholders)
            )",
            ...$filter_out_term_ids
        );
    }

    return $clauses;
}

/**
 * Get SQL WHERE clause for filtering hidden terms
 *
 * Returns empty string if user can see hidden terms, otherwise returns
 * clause to filter them out. Used in SQL queries to consistently apply
 * hidden term filtering based on user permissions.
 *
 * @since 1.0.0
 *
 * @param string $column Column name to check (default: 'is_hidden')
 * @return string SQL WHERE clause (with leading AND) or empty string
 *
 * @example
 * // In SQL query building
 * $hidden_clause = htax_get_hidden_term_filter_clause();
 * $query = "SELECT * FROM wp_htax_search_terms WHERE taxonomy = 'post_tag'{$hidden_clause}";
 * // For editors: $hidden_clause = ''
 * // For visitors: $hidden_clause = ' AND is_hidden = 0'
 */
function htax_get_hidden_term_filter_clause( $column = 'is_hidden' ) {
    if ( htax_can_see_hidden_terms() ) {
        return '';
    }
    return sprintf( ' AND %s = 0', esc_sql( $column ) );
}

/**
 * Get enabled taxonomies with search enhancement
 *
 * Returns list of taxonomies that have search_enhancement feature enabled,
 * with post_tag first and category second for consistency.
 *
 * @since 1.0.0
 * @return array Array of taxonomy slugs with search enhancement enabled
 */
function htax_get_enabled_taxonomies_with_search() {
    $enabled = htax_get_enabled_taxonomies();
    $with_search = array();

    foreach ( $enabled as $taxonomy ) {
        if ( htax_taxonomy_has_feature( $taxonomy, 'search_enhancement' ) ) {
            $with_search[] = $taxonomy;
        }
    }

    // Ensure post_tag is first, category second for consistency
    $ordered = array();
    if ( in_array( 'post_tag', $with_search, true ) ) {
        $ordered[] = 'post_tag';
    }
    if ( in_array( 'category', $with_search, true ) ) {
        $ordered[] = 'category';
    }
    foreach ( $with_search as $tax ) {
        if ( ! in_array( $tax, array( 'post_tag', 'category' ), true ) ) {
            $ordered[] = $tax;
        }
    }

    return $ordered;
}

// ==================================================
// SEARCH QUERY PARSING
// ==================================================

/**
 * Parse a PowerSearch query string into field-keyed token arrays.
 *
 * Each token is an associative array:
 *   term       string  — the search word or phrase
 *   required   bool    — true when prefixed with + (must appear somewhere)
 *   excluded   bool    — true when prefixed with - (must not appear)
 *   quoted     bool    — true when the value was in double quotes
 *   slug_match bool    — true for singular operators (tag:, category:, singular custom taxonomy):
 *                        callers should match against term slugs rather than FULLTEXT term names
 *
 * Singular operators (tag:, category:, term:, custom singular label) → slug_match = true.
 * Plural operators (tags:, categories:, terms:, custom plural label) → slug_match = false.
 * The taxonomy slug itself maps to slug_match = true (it is the identifier, not a label search).
 *
 * Proximity tokens (near:) are extracted into $parsed['proximity'] when ATCF is
 * active; otherwise the raw text is left in the query and falls through to FULLTEXT search.
 *
 * @param string $query        Raw query string from the user.
 * @param string $near_country ISO 3166-1 country code used when `near:postal_code` tokens need
 *                             centroid lookup or format validation. Default 'US'.
 * @return array
 */
function htax_parse_search_query( string $query, string $near_country = 'US' ): array {
    $parsed = array(
        'title'      => array(),
        'body'       => array(),
        'excerpt'    => array(),
        'tags'       => array(),
        'categories' => array(),
        'terms'      => array(),
        'general'    => array(),
        'proximity'  => array(),
    );

    // When ATCF is active, extract near: tokens before the main operator pass.
    // Stripping them first prevents them from contaminating the general FULLTEXT search.
    // When ATCF is absent the text is left untouched so it falls through to FULLTEXT as-is.
    if ( function_exists( 'atcf_get_term_ids_within_radius' ) ) {
        // Matches: [-]near:(me | taxonomy:slug | postal_code)[/radius]
        // Value alternatives (no spaces — tokens are space-separated in PowerSearch):
        //   me               user location
        //   taxonomy:slug    has exactly one colon
        //   postal_code      alphanum + hyphens, no colon (e.g. 07012, SW1A1AA, 97201-4567)
        $prox_re = '/([+\-]?)(near):((?:me|[a-z0-9_\-]+:[a-z0-9_\-]+|[a-z0-9][a-z0-9\-]*)(?:\/\d+(?:\.\d+)?)?)(?=\s|$)/i';
        preg_match_all( $prox_re, $query, $pm, PREG_SET_ORDER );
        foreach ( $pm as $m ) {
            $negated = ( '-' === $m[1] );
            $value   = $m[3];               // e.g. 'me', 'me/5', 'store:downtown/3', '07012/10'
            $radius  = 10.0;
            if ( preg_match( '#/(\d+(?:\.\d+)?)$#', $value, $rm ) ) {
                $radius = (float) $rm[1];
                $value  = substr( $value, 0, -strlen( $rm[0] ) );
            }
            if ( 0 === strcasecmp( $value, 'me' ) ) {
                $parsed['proximity'][] = array(
                    'negated'       => $negated,
                    'location_type' => 'me',
                    'taxonomy'      => null,
                    'term_slug'     => null,
                    'postal_code'   => null,
                    'country'       => strtoupper( $near_country ),
                    'radius'        => $radius,
                );
            } elseif ( str_contains( $value, ':' ) ) {
                $parts = explode( ':', $value, 2 );
                $parsed['proximity'][] = array(
                    'negated'       => $negated,
                    'location_type' => 'term',
                    'taxonomy'      => $parts[0],
                    'term_slug'     => $parts[1] ?? '',
                    'postal_code'   => null,
                    'country'       => strtoupper( $near_country ),
                    'radius'        => $radius,
                );
            } else {
                // Value is not 'me' and has no colon → could be a postal code or a term slug.
                // Resolution (format check → centroid lookup vs slug lookup) is deferred to
                // resolve_reference_points() which has access to the country's format regex.
                $parsed['proximity'][] = array(
                    'negated'       => $negated,
                    'location_type' => 'postal_code',
                    'taxonomy'      => null,
                    'term_slug'     => null,
                    'postal_code'   => $value,
                    'country'       => strtoupper( $near_country ),
                    'radius'        => $radius,
                );
            }
        }
        if ( ! empty( $parsed['proximity'] ) ) {
            $query = trim( preg_replace( $prox_re, '', $query ) );
        }
    }

    // Build operator maps for custom taxonomies.
    //
    // Plural label (e.g. product_categories:) → FULLTEXT match against term names/descriptions.
    // Taxonomy slug and singular label (e.g. product_category:) → exact slug match.
    //
    // Built-in taxonomies (post_tag, category) are handled by the fixed operators below:
    //   tags:/tag:   categories:/category:   terms:/term:
    $fulltext_ops = array(); // operator → taxonomy_slug, FULLTEXT matching
    $slug_ops     = array(); // operator → taxonomy_slug, exact slug matching

    $enabled_taxonomies = htax_get_enabled_taxonomies_with_search();
    foreach ( $enabled_taxonomies as $taxonomy_slug ) {
        if ( in_array( $taxonomy_slug, array( 'post_tag', 'category' ), true ) ) {
            continue;
        }

        // The taxonomy slug is an identifier → slug match.
        $slug_ops[ $taxonomy_slug ] = $taxonomy_slug;

        $tax_obj = get_taxonomy( $taxonomy_slug );
        if ( ! $tax_obj ) {
            continue;
        }

        // Plural display name → FULLTEXT (e.g. "Product Categories" → product_categories:).
        $plural_key = strtolower( str_replace( ' ', '_', $tax_obj->labels->name ?? '' ) );
        if ( $plural_key && $plural_key !== $taxonomy_slug
            && ! isset( $fulltext_ops[ $plural_key ] ) && ! isset( $slug_ops[ $plural_key ] )
        ) {
            $fulltext_ops[ $plural_key ] = $taxonomy_slug;
        }

        // Singular display name → slug match (e.g. "Product Category" → product_category:).
        $singular_key = strtolower( str_replace( ' ', '_', $tax_obj->labels->singular_name ?? '' ) );
        if ( $singular_key && $singular_key !== $taxonomy_slug
            && ! isset( $fulltext_ops[ $singular_key ] ) && ! isset( $slug_ops[ $singular_key ] )
        ) {
            $slug_ops[ $singular_key ] = $taxonomy_slug;
        }
    }

    // All operators for the combined match regex (order: fixed built-ins first, then custom).
    $all_custom_ops = array_unique( array_merge( array_keys( $fulltext_ops ), array_keys( $slug_ops ) ) );
    $custom_ops_str = ! empty( $all_custom_ops )
        ? '|' . implode( '|', array_map( 'preg_quote', $all_custom_ops, array_fill( 0, count( $all_custom_ops ), '/' ) ) )
        : '';

    $pattern = '/([+\-]?)\b(title|content|body|excerpt|tags?|categories?|terms?' . $custom_ops_str . '):("([^"]+)"|(\S+))/i';

    preg_match_all( $pattern, $query, $matches, PREG_SET_ORDER );

    foreach ( $matches as $match ) {
        $modifier  = $match[1];
        $operator  = strtolower( $match[2] );
        $term      = ! empty( $match[4] ) ? $match[4] : $match[5];
        $term_data = array(
            'term'       => $term,
            'required'   => ( '+' === $modifier ),
            'excluded'   => ( '-' === $modifier ),
            'quoted'     => ! empty( $match[4] ),
            'slug_match' => false, // overridden to true for singular operators below
        );

        switch ( $operator ) {
            case 'title':
                $parsed['title'][] = $term_data;
                break;
            case 'content':
            case 'body':
                $parsed['body'][] = $term_data;
                break;
            case 'excerpt':
                $parsed['excerpt'][] = $term_data;
                break;
            case 'tags':
                $parsed['tags'][] = $term_data;
                break;
            case 'tag': // singular → exact slug match
                $parsed['tags'][] = array_merge( $term_data, array( 'slug_match' => true ) );
                break;
            case 'categories':
                $parsed['categories'][] = $term_data;
                break;
            case 'category': // singular → exact slug match
                $parsed['categories'][] = array_merge( $term_data, array( 'slug_match' => true ) );
                break;
            case 'terms':
                $parsed['terms'][] = $term_data;
                break;
            case 'term': // singular → exact slug match across all enabled taxonomies
                $parsed['terms'][] = array_merge( $term_data, array( 'slug_match' => true ) );
                break;
            default:
                if ( isset( $fulltext_ops[ $operator ] ) ) {
                    $slug = $fulltext_ops[ $operator ];
                    $parsed[ $slug ][] = $term_data;
                } elseif ( isset( $slug_ops[ $operator ] ) ) {
                    $slug = $slug_ops[ $operator ];
                    if ( ! isset( $parsed[ $slug ] ) ) {
                        $parsed[ $slug ] = array();
                    }
                    $parsed[ $slug ][] = array_merge( $term_data, array( 'slug_match' => true ) );
                }
                break;
        }
    }

    // Collect unqualified (general) terms from whatever remains.
    $general_query = trim( preg_replace( '/\s+/', ' ', preg_replace( $pattern, '', $query ) ) );
    if ( '' !== $general_query ) {
        preg_match_all( '/([+\-]?)("([^"]+)"|(\S+))/', $general_query, $gm, PREG_SET_ORDER );
        foreach ( $gm as $gmatch ) {
            $term = ! empty( $gmatch[3] ) ? $gmatch[3] : $gmatch[4];
            if ( '' !== $term ) {
                $parsed['general'][] = array(
                    'term'     => $term,
                    'required' => ( '+' === $gmatch[1] ),
                    'excluded' => ( '-' === $gmatch[1] ),
                    'quoted'   => ! empty( $gmatch[3] ),
                );
            }
        }
    }

    return $parsed;
}

/**
 * Build a MySQL FULLTEXT boolean mode query string from parsed term data.
 *
 * Accepts field-specific terms and optional general (unqualified) terms
 * separately — they are merged before building the string. Callers that
 * have already merged both sets may pass a single array as $field_terms.
 *
 * Prefix semantics (MySQL FULLTEXT boolean mode):
 *   +term  — must be present (mandatory)
 *   -term  — must NOT be present (hard exclusion)
 *   term   — optional (boosts relevance when present)
 *
 * Multi-word phrases are automatically wrapped in double quotes.
 *
 * @since 1.0.0
 *
 * @param array $field_terms   Term data arrays for the specific field (or all terms pre-merged).
 *                             Each element: [ 'term' => string, 'required' => bool, 'excluded' => bool ].
 * @param array $general_terms Unqualified term data arrays to merge in. Default empty.
 * @return string Boolean mode query string, or '' if no terms.
 */
/**
 * Build a MySQL FULLTEXT natural language mode query string from parsed term data.
 *
 * Strips all +/- operators (excluded terms are dropped entirely; required and
 * optional terms are included as bare words). NATURAL LANGUAGE MODE does not
 * support operator syntax — relevance ranking is handled by IDF and document-
 * length normalisation instead.
 *
 * @param array $field_terms   Parsed term data for one field.
 * @param array $general_terms Unqualified terms to merge in. Default empty.
 * @return string Space-joined bare words for use with IN NATURAL LANGUAGE MODE.
 */
function htax_build_natural_language_query_string( array $field_terms, array $general_terms = array() ) {
    $parts = array();
    foreach ( array_merge( $field_terms, $general_terms ) as $td ) {
        if ( $td['excluded'] || '' === trim( $td['term'] ) ) {
            continue; // excluded terms omitted; handled via WHERE NOT IN
        }
        $parts[] = $td['term'];
    }
    return implode( ' ', $parts );
}

/**
 * Return the configured default match mode ('any' or 'all').
 *
 * Used wherever a match mode is needed but no explicit value was supplied by
 * the caller. Reads htax_settings[default_match_mode]; falls back to 'any'.
 *
 * @return string 'any' or 'all'.
 */
function htax_get_default_match_mode() {
    $options = get_option( 'htax_settings', array() );
    return ( isset( $options['default_match_mode'] ) && 'all' === $options['default_match_mode'] ) ? 'all' : 'any';
}

/**
 * Get the site-wide default relationship expansion types for search.
 *
 * Returns the types enabled by the Search → General "Term Relationship Expansion" setting.
 * Does not apply per-taxonomy feature flags — use htax_get_expand_types_for_taxonomy() for that.
 *
 * @since 1.0.0
 * @return array DB-level type names: subset of {'container', 'hierarchy', 'redirect_target'}.
 */
function htax_get_default_expand_types(): array {
    $options = get_option( 'htax_settings', array() );
    if ( ! isset( $options['search_expand_types'] ) ) {
        return array( 'container', 'hierarchy', 'redirect_target' );
    }
    $allowed = array( 'container', 'hierarchy', 'redirect_target' );
    return array_values( array_intersect( (array) $options['search_expand_types'], $allowed ) );
}

/**
 * Get the active relationship types for a taxonomy, applying both site settings and feature flags.
 *
 * Always includes 'direct'. Each expansion type requires both the site-wide setting to be
 * enabled AND the taxonomy-level feature flag to be active.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy slug.
 * @return array DB-level relationship_type values for use in IN(...) clauses.
 */
function htax_get_expand_types_for_taxonomy( string $taxonomy ): array {
    $defaults = htax_get_default_expand_types();
    $types    = array( 'direct' );
    if ( in_array( 'hierarchy', $defaults, true ) && is_taxonomy_hierarchical( $taxonomy ) ) {
        $types[] = 'hierarchy';
    }
    if ( in_array( 'container', $defaults, true ) && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
        $types[] = 'container';
    }
    if ( in_array( 'redirect_target', $defaults, true ) && htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        $types[] = 'redirect_target';
    }
    return $types;
}

/**
 * Get the default results-per-page for all HT search forms.
 *
 * Falls back to the WordPress "Blog pages show at most" Reading setting so
 * there is a single place for admins to configure pagination site-wide.
 *
 * @return int
 */
function htax_get_search_results_per_page() {
    return max( 1, (int) get_option( 'posts_per_page', 20 ) );
}

function htax_build_boolean_query_string( array $field_terms, array $general_terms = array() ) {
    $parts = array();
    foreach ( array_merge( $field_terms, $general_terms ) as $td ) {
        $prefix = $td['required'] ? '+' : ( $td['excluded'] ? '-' : '' );
        $term   = $td['term'];
        // Wrap multi-word phrases in double quotes for boolean mode.
        if ( strpos( $term, ' ' ) !== false ) {
            $parts[] = $prefix . '"' . addslashes( $term ) . '"';
        } else {
            $parts[] = $prefix . $term;
        }
    }
    return implode( ' ', $parts );
}
