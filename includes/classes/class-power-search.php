<?php
/**
 * Standalone PowerSearch Widget
 *
 * PowerSearch widget with relevance scoring and field-specific operators.
 * This is completely independent from the advanced taxonomy search widget.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_Power_Search {

    /**
     * Singleton instance
     */
    private static $instance = null;

    private static $instance_counter = 0;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the widget (private constructor for singleton)
     */
    private function __construct() {
        // Register shortcodes
        add_shortcode( 'ht_power_search', array( $this, 'search_form_shortcode' ) );

        // AJAX handler
        add_action( 'wp_ajax_htax_power_search_posts', array( $this, 'ajax_search_posts' ) );
        add_action( 'wp_ajax_nopriv_htax_power_search_posts', array( $this, 'ajax_search_posts' ) );

        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Flag set by shortcodes when they render on the current page.
     */
    private static $scripts_needed = false;

    /**
     * Called by each shortcode at render time to request asset enqueue.
     */
    public static function request_scripts() {
        self::$scripts_needed = true;
    }

    /**
     * Enqueue frontend scripts only when a PowerSearch shortcode is present on the page.
     *
     * The stylesheet is always enqueued because wp_enqueue_scripts fires before shortcodes
     * render — by the time a shortcode sets $scripts_needed, wp_head has already run and
     * it's too late to inject a <link> tag. The JS is conditional because it's larger and
     * can safely be deferred until shortcodes have run (it loads in the footer).
     */
    public function enqueue_scripts() {
        wp_enqueue_style(
            'htax-power-search',
            HTAX_PLUGIN_URL . 'assets/css/htax-search.css',
            array(),
            HTAX_VERSION
        );

        $options = get_option( 'htax_settings', array() );
        if ( ! self::$scripts_needed && empty( $options['always_load_search_assets'] ) ) {
            return;
        }

        // near:me uses As the Crow Flies' browser-location helpers when ATCF is active.
        wp_enqueue_script( 'htax-geo', HTAX_PLUGIN_URL . 'assets/js/htax-geo.js', function_exists( 'atcf_get_visitor_location' ) ? array( 'atcf-frontend' ) : array(), HTAX_VERSION, true );
        wp_enqueue_script(
            'htax-power-search',
            HTAX_PLUGIN_URL . 'assets/js/htax-power-search.js',
            array( 'jquery', 'htax-geo' ),
            HTAX_VERSION,
            true
        );

        wp_localize_script( 'htax-power-search', 'HTAX_PowerSearch', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'i18n' => array(
                'loading'    => __( 'Searching...', 'hypertaxonomy' ),
                'no_results' => __( 'No results found.', 'hypertaxonomy' ),
                'error'      => __( 'An error occurred during search.', 'hypertaxonomy' ),
                'min_length' => __( 'Please enter at least %d characters.', 'hypertaxonomy' ),
                'prev'       => __( 'Previous', 'hypertaxonomy' ),
                'next'       => __( 'Next', 'hypertaxonomy' ),
                'page_of'    => __( 'Page %1$d of %2$d', 'hypertaxonomy' ),
            ),
        ) );
    }

    /**
     * Search form shortcode
     *
     * Renders a PowerSearch form with a single input field.
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function search_form_shortcode( $atts ) {
        $defaults = array(
            'id' => 'htax-power-search-' . ++self::$instance_counter,
            'placeholder' => __( 'Search...', 'hypertaxonomy' ),
            'button_text' => '', // Empty = no button, rely on Enter key
            'min_length' => '3',
            'match' => htax_get_default_match_mode(), // 'any' (OR logic) or 'all' (AND logic); configurable in Settings → Search
            'search_in' => 'all', // Fields to search: all, title, content, excerpt, tags, categories, terms, or taxonomy slug
            'min_score' => '0.01', // Minimum overall score to exclude completely irrelevant posts
            'min_title_score' => '0',
            'min_content_score' => '0',
            'min_excerpt_score' => '0',
            'min_tags_score' => '0',
            'min_categories_score' => '0',
            'min_terms_score' => '0',
            'orderby' => 'relevance:desc,date:desc',
            'posts_per_page' => (string) htax_get_search_results_per_page(),
            'max_results' => '0',
            'near_country' => 'US', // ISO country code used to validate near:postal_code tokens
            'show_thumbnail' => 'true',
            'show_excerpt' => 'true',
            'show_meta' => 'true',
            'image_size' => 'medium',
            'columns'    => '1',
            'ajax' => '', // Empty = POST submission, container-id = AJAX mode
            'class' => '',
        );

        $atts = shortcode_atts( $defaults, $atts );

        self::request_scripts();

        // Determine if AJAX mode
        $is_ajax = ! empty( $atts['ajax'] );
        $results_id = $is_ajax ? $atts['ajax'] : '';

        $classes = array( 'htax-power-search-form' );
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" id="' . esc_attr( $atts['id'] ) . '">';

        // Search form
        $output .= '<form class="htax-power-search-input-wrapper" ';
        $output .= 'data-ajax="' . esc_attr( $is_ajax ? 'true' : 'false' ) . '" ';
        $output .= 'data-orderby="' . esc_attr( $atts['orderby'] ) . '" ';
        $output .= 'data-posts-per-page="' . max( 1, intval( $atts['posts_per_page'] ) ) . '" ';
        $output .= 'data-max-results="' . max( 0, intval( $atts['max_results'] ) ) . '" ';
        if ( ! empty( $results_id ) ) {
            $output .= 'data-results="' . esc_attr( $results_id ) . '" ';
        }

        // For non-AJAX forms, set method to POST
        if ( ! $is_ajax ) {
            $output .= 'method="post" ';
        }

        $output .= '>';
        $output .= wp_nonce_field( 'htax-power-search', 'htax_power_search_nonce', true, false );

        // Add hidden fields for POST submissions
        if ( ! $is_ajax ) {
            $output .= '<input type="hidden" name="htax_power_post_search" value="1" />';
            $output .= '<input type="hidden" name="htax_orderby" value="' . esc_attr( $atts['orderby'] ) . '" />';
            $output .= '<input type="hidden" name="htax_posts_per_page" value="' . max( 1, intval( $atts['posts_per_page'] ) ) . '" />';
            $output .= '<input type="hidden" name="htax_max_results" value="' . max( 0, intval( $atts['max_results'] ) ) . '" />';
            $output .= '<input type="hidden" name="htax_min_length" value="' . intval( $atts['min_length'] ) . '" />';
            $output .= '<input type="hidden" name="htax_match" value="' . esc_attr( $atts['match'] ) . '" />';
            $output .= '<input type="hidden" name="htax_search_in" value="' . esc_attr( $atts['search_in'] ) . '" />';
            if ( 'US' !== strtoupper( $atts['near_country'] ) ) {
                $output .= '<input type="hidden" name="htax_near_country" value="' . esc_attr( strtoupper( $atts['near_country'] ) ) . '" />';
            }
            if ( floatval( $atts['min_score'] ) > 0 ) {
                $output .= '<input type="hidden" name="htax_min_score" value="' . esc_attr( $atts['min_score'] ) . '" />';
            }
            if ( floatval( $atts['min_title_score'] ) > 0 ) {
                $output .= '<input type="hidden" name="htax_min_title_score" value="' . esc_attr( $atts['min_title_score'] ) . '" />';
            }
            if ( floatval( $atts['min_content_score'] ) > 0 ) {
                $output .= '<input type="hidden" name="htax_min_content_score" value="' . esc_attr( $atts['min_content_score'] ) . '" />';
            }
            if ( floatval( $atts['min_excerpt_score'] ) > 0 ) {
                $output .= '<input type="hidden" name="htax_min_excerpt_score" value="' . esc_attr( $atts['min_excerpt_score'] ) . '" />';
            }
            if ( floatval( $atts['min_tags_score'] ) > 0 ) {
                $output .= '<input type="hidden" name="htax_min_tags_score" value="' . esc_attr( $atts['min_tags_score'] ) . '" />';
            }
            if ( floatval( $atts['min_categories_score'] ) > 0 ) {
                $output .= '<input type="hidden" name="htax_min_categories_score" value="' . esc_attr( $atts['min_categories_score'] ) . '" />';
            }
            if ( floatval( $atts['min_terms_score'] ) > 0 ) {
                $output .= '<input type="hidden" name="htax_min_terms_score" value="' . esc_attr( $atts['min_terms_score'] ) . '" />';
            }
            $output .= '<input type="hidden" name="htax_show_thumbnail" value="' . esc_attr( $atts['show_thumbnail'] ) . '" />';
            $output .= '<input type="hidden" name="htax_show_excerpt" value="' . esc_attr( $atts['show_excerpt'] ) . '" />';
            $output .= '<input type="hidden" name="htax_show_meta" value="' . esc_attr( $atts['show_meta'] ) . '" />';
            $output .= '<input type="hidden" name="htax_image_size" value="' . esc_attr( sanitize_key( $atts['image_size'] ) ) . '" />';
        }

        // Preserve query value after POST submission
        $query_value = '';
        if ( ! $is_ajax && isset( $_POST['htax_power_post_search'] ) && isset( $_POST['query'] ) ) {
            $query_value = sanitize_text_field( wp_unslash( $_POST['query'] ) );
        }

        $output .= '<input type="text" ';
        $output .= 'class="htax-power-search-input" ';
        $output .= 'name="query" ';
        $output .= 'value="' . esc_attr( $query_value ) . '" ';
        $output .= 'placeholder="' . esc_attr( $atts['placeholder'] ) . '" ';
        $output .= 'data-min-length="' . intval( $atts['min_length'] ) . '" ';
        $output .= 'data-match="' . esc_attr( $atts['match'] ) . '" ';
        $output .= 'data-search-in="' . esc_attr( $atts['search_in'] ) . '" ';
        if ( 'US' !== strtoupper( $atts['near_country'] ) ) {
            $output .= 'data-near-country="' . esc_attr( strtoupper( $atts['near_country'] ) ) . '" ';
        }
        foreach ( array( 'min_score', 'min_title_score', 'min_content_score', 'min_excerpt_score', 'min_tags_score', 'min_categories_score', 'min_terms_score' ) as $score_key ) {
            if ( floatval( $atts[ $score_key ] ) > 0 ) {
                $output .= 'data-' . str_replace( '_', '-', $score_key ) . '="' . esc_attr( $atts[ $score_key ] ) . '" ';
            }
        }
        if ( floatval( $atts['min_title_score'] ) > 0 ) {
            $output .= 'data-min-title-score="' . esc_attr( $atts['min_title_score'] ) . '" ';
        }
        if ( floatval( $atts['min_content_score'] ) > 0 ) {
            $output .= 'data-min-content-score="' . esc_attr( $atts['min_content_score'] ) . '" ';
        }
        if ( floatval( $atts['min_excerpt_score'] ) > 0 ) {
            $output .= 'data-min-excerpt-score="' . esc_attr( $atts['min_excerpt_score'] ) . '" ';
        }
        if ( floatval( $atts['min_tags_score'] ) > 0 ) {
            $output .= 'data-min-tags-score="' . esc_attr( $atts['min_tags_score'] ) . '" ';
        }
        if ( floatval( $atts['min_categories_score'] ) > 0 ) {
            $output .= 'data-min-categories-score="' . esc_attr( $atts['min_categories_score'] ) . '" ';
        }
        if ( floatval( $atts['min_terms_score'] ) > 0 ) {
            $output .= 'data-min-terms-score="' . esc_attr( $atts['min_terms_score'] ) . '" ';
        }
        $output .= '/>';

        // Only show button if button_text is specified
        if ( ! empty( $atts['button_text'] ) ) {
            $output .= '<button type="submit" class="htax-power-search-button">';
            $output .= esc_html( $atts['button_text'] );
            $output .= '</button>';
        }

        $output .= '</form>';
        $output .= '</div>'; // .htax-power-search-form

        return $output;
    }

    /**
     * Apply minimum score thresholds from a source array into a search config array.
     *
     * @param array  $config  Search config array (modified in place).
     * @param array  $source  Source data array (e.g. $_POST or $post_data).
     * @param string $prefix  Key prefix used in $source ('htax_' for POST-mode form fields,
     *                        '' for the AJAX request).
     */
    public function apply_score_thresholds( array &$config, array $source, string $prefix = '' ) {
        $score_keys = array(
            'min_score', 'min_title_score', 'min_content_score',
            'min_excerpt_score', 'min_tags_score', 'min_categories_score', 'min_terms_score',
        );
        foreach ( $score_keys as $key ) {
            $source_key = $prefix . $key;
            if ( isset( $source[ $source_key ] ) ) {
                $config[ $key ] = floatval( $source[ $source_key ] );
            }
        }
    }

    /**
     * AJAX handler for PowerSearch
     */
    public function ajax_search_posts() {
        if ( ! check_ajax_referer( 'htax-power-search', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
            return;
        }

        if ( ! htax_check_ajax_rate_limit( 'power_search', 30 ) ) {
            wp_send_json_error( array( 'message' => 'Rate limit exceeded. Please wait a moment and try again.' ), 429 );
            return;
        }

        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        $page = isset( $_POST['page'] ) ? intval( wp_unslash( $_POST['page'] ) ) : 1;
        $posts_per_page = isset( $_POST['posts_per_page'] ) ? intval( wp_unslash( $_POST['posts_per_page'] ) ) : 10;
        $max_results = isset( $_POST['max_results'] ) ? max( 0, intval( wp_unslash( $_POST['max_results'] ) ) ) : 0;
        $orderby_string = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'relevance:desc,date:desc';

        // Validate minimum length
        $min_length = isset( $_POST['min_length'] ) ? intval( wp_unslash( $_POST['min_length'] ) ) : 3;
        if ( mb_strlen( $query ) < $min_length ) {
            wp_send_json_success( array(
                'posts' => array(),
                'found_posts' => 0,
                'max_pages' => 0,
                'current_page' => $page,
                'message' => sprintf( __( 'Please enter at least %d characters.', 'hypertaxonomy' ), $min_length ),
            ) );
            return;
        }

        // Parse orderby
        $orderby_parsed = htax_parse_orderby( $orderby_string );

        // Build extra WHERE from filter state
        $extra_where = '';
        $raw_filter = isset( $_POST['htax_filter'] ) ? wp_unslash( $_POST['htax_filter'] ) : '';
        if ( '' !== $raw_filter ) {
            $filter_state = htax_parse_filter_state_ajax( $raw_filter );
            if ( ! empty( $filter_state ) ) {
                $extra_where = htax_build_filter_sql( $filter_state );
            }
        }

        // Build config
        $config = array(
            'orderby'        => $orderby_parsed,
            'posts_per_page' => $posts_per_page,
            'max_results'    => $max_results,
            'page'           => $page,
            'match'          => isset( $_POST['match'] ) && in_array( $_POST['match'], array( 'any', 'all' ), true ) ? $_POST['match'] : htax_get_default_match_mode(),
            'search_in'      => isset( $_POST['search_in'] ) ? sanitize_text_field( wp_unslash( $_POST['search_in'] ) ) : 'all',
            'extra_where'    => $extra_where,
            'near_country'   => isset( $_POST['near_country'] )
                ? strtoupper( sanitize_text_field( wp_unslash( $_POST['near_country'] ) ) )
                : 'US',
        );

        // Add minimum score thresholds if provided
        $this->apply_score_thresholds( $config, $_POST );

        // Perform PowerSearch using the trait method
        $search_result = $this->perform_indexed_text_search( $query, $config );

        // Validate search result structure
        if ( ! is_array( $search_result ) || ! isset( $search_result['results'] ) || ! isset( $search_result['total'] ) ) {
            htax_log_error( 'PowerSearch returned invalid structure', array(
                'search_result' => $search_result,
                'query' => $query
            ) );
            wp_send_json_success( array(
                'posts' => array(),
                'found_posts' => 0,
                'max_pages' => 0,
                'current_page' => $page,
            ) );
            return;
        }

        $results = $search_result['results'];
        $total_found = $search_result['total'];

        if ( empty( $results ) ) {
            wp_send_json_success( array(
                'posts' => array(),
                'found_posts' => 0,
                'max_pages' => 0,
                'current_page' => $page,
            ) );
            return;
        }

        // Validate results array structure
        if ( ! is_array( $results ) ) {
            htax_log_error( 'PowerSearch results is not an array', array(
                'results' => $results
            ) );
            wp_send_json_success( array(
                'posts' => array(),
                'found_posts' => 0,
                'max_pages' => 0,
                'current_page' => $page,
            ) );
            return;
        }

        // Get post IDs from current page results
        // Validate each result has the expected structure
        $post_ids = array();
        foreach ( $results as $index => $result ) {
            if ( ! is_array( $result ) || ! isset( $result['post_id'] ) ) {
                htax_log_error( 'PowerSearch result element missing post_id', array(
                    'index' => $index,
                    'result' => $result
                ) );
                continue;
            }
            $post_ids[] = $result['post_id'];
        }

        // Pagination is applied in the SQL query — do not paginate again here.
        $paged_ids = $post_ids;

        // Get posts
        if ( empty( $paged_ids ) ) {
            wp_send_json_success( htax_build_pagination_response( array(), $total_found, $posts_per_page, $page, $max_results ) );
            return;
        }

        // The SQL already applied every filter and the page LIMIT, so this query must not
        // drop or add posts: indexed post types ('any' would drop types excluded from
        // search) and no sticky posts (WordPress prepends them to "home" queries).
        $query = new WP_Query( array(
            'post__in'            => $paged_ids,
            'orderby'             => 'post__in',
            'posts_per_page'      => count( $paged_ids ),
            'post_type'           => htax_get_indexed_post_types(),
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ) );

        // Format response
        $ajax_image_size = isset( $_POST['image_size'] ) ? sanitize_key( wp_unslash( $_POST['image_size'] ) ) : 'medium';
        $formatted_posts = array();
        foreach ( $query->posts as $post ) {
            $formatted_posts[] = htax_format_search_result_post( $post, $ajax_image_size );
        }

        wp_send_json_success( htax_build_pagination_response( $formatted_posts, $total_found, $posts_per_page, $page, $max_results ) );
    }

    /**
     * Executes PowerSearch against wp_posts and wp_htax_search_terms
     * with field-specific operators and tier-based relevance weights.
     *
     * Supports operators: title:, content:, excerpt:, tags:, categories:, terms:, {taxonomy_plural}:
     * Example: title:wordpress photography categories:news colors:blue
     *
     * @param string $query Search query with optional field operators
     * @param array $config Configuration array with keys:
     *                      - orderby: Parsed orderby array
     *                      - posts_per_page: Number of results per page
     *                      - page: Current page number
     *                      - min_score: Minimum overall relevance score (0-1, default: 0)
     *                        Posts must achieve at least this total score to be included in results.
     *                        Uses same 0-1 scale as relevance multipliers in settings.
     *                      - min_title_score: Minimum score from title matches (0-1, default: 0)
     *                        If >0, post must score at least this from title matches to be included.
     *                      - min_content_score: Minimum score from content matches (0-1, default: 0)
     *                      - min_excerpt_score: Minimum score from excerpt matches (0-1, default: 0)
     *                      - min_tags_score: Minimum score from tag matches (0-1, default: 0)
     *                      - min_categories_score: Minimum score from category matches (0-1, default: 0)
     *                      - min_terms_score: Minimum score from term matches (0-1, default: 0)
     *                      Note: Setting field min_score to 0 means that field is not required; >0 means
     *                      the post MUST score at least that amount from that specific field type.
     *                      All scores use the same 0-1 scale as relevance multipliers in settings.
     *                      - near_country: ISO 3166-1 code for near: postal codes (default 'US')
     * @return array{results: array<array{post_id: int, relevance: float}>, total: int}
     */
    public function perform_indexed_text_search( $query, $config = array() ) {
        global $wpdb;

        // Short-lived transient cache (60 s) prevents concurrent identical searches from
        // each running two expensive SQL queries, which is the primary cause of memory exhaustion
        // when users resubmit while waiting for slow results.
        $skip_count = ! empty( $config['skip_count'] );
        // Include the viewer's hidden-term visibility in the cache key so cached
        // results for a privileged user are never served to an unprivileged viewer
        // within the TTL. The visibility flag gates hidden rows in the SQL below.
        // Get match mode: 'any' (OR) or 'all' (AND - adds + to all terms).
        // Falls back to the site-wide default from Settings → Search.
        $match_mode = isset( $config['match'] ) ? $config['match'] : htax_get_default_match_mode();

        // Parse search_in parameter to determine which fields are searchable
        // Default: 'all' (everything: title, content, excerpt, tags, categories, all configured taxonomies)
        // Valid values: 'all', 'title', 'content', 'excerpt', 'tags', 'categories', 'terms', or specific taxonomy slugs
        $search_in_raw = isset( $config['search_in'] ) ? $config['search_in'] : 'all';
        $search_in_values = is_array( $search_in_raw ) ? $search_in_raw : array_map( 'trim', explode( ',', $search_in_raw ) );

        // Get enabled taxonomies with search enhancement
        $enabled_taxonomies = htax_get_enabled_taxonomies_with_search();

        // Build allowed fields map based on search_in parameter
        $allowed_fields = array();

        if ( in_array( 'all', $search_in_values, true ) ) {
            // Search everything
            $allowed_fields['title'] = true;
            $allowed_fields['content'] = true;
            $allowed_fields['excerpt'] = true;
            $allowed_fields['tags'] = in_array( 'post_tag', $enabled_taxonomies, true );
            $allowed_fields['categories'] = in_array( 'category', $enabled_taxonomies, true );
            $allowed_fields['terms'] = ! empty( $enabled_taxonomies );
            foreach ( $enabled_taxonomies as $taxonomy ) {
                $allowed_fields[ $taxonomy ] = true;
            }
        } else {
            // Parse individual search_in values
            foreach ( $search_in_values as $value ) {
                switch ( $value ) {
                    case 'title':
                    case 'content':
                    case 'excerpt':
                        $allowed_fields[ $value ] = true;
                        break;

                    case 'tags':
                        // Only allow if post_tag is configured for search
                        $allowed_fields['tags'] = in_array( 'post_tag', $enabled_taxonomies, true );
                        break;

                    case 'categories':
                        // Only allow if category is configured for search
                        $allowed_fields['categories'] = in_array( 'category', $enabled_taxonomies, true );
                        break;

                    case 'terms':
                        // Search ALL configured taxonomies
                        $allowed_fields['terms'] = ! empty( $enabled_taxonomies );
                        foreach ( $enabled_taxonomies as $taxonomy ) {
                            $allowed_fields[ $taxonomy ] = true;
                        }
                        break;

                    default:
                        // Check if it's a specific taxonomy slug
                        if ( in_array( $value, $enabled_taxonomies, true ) ) {
                            $allowed_fields[ $value ] = true;
                        }
                        break;
                }
            }
        }

        // Parse query for field-specific operators
        $parsed_query = $this->parse_search_query( $query, $config['near_country'] ?? 'US' );

        // near: tokens become a WHERE condition (applied before LIMIT, so pages and totals
        // reflect the filtered set). It is resolved now because it depends on the viewer's
        // location (near:me), which the cache key must reflect.
        $proximity_where = '';
        if ( ! empty( $parsed_query['proximity'] ) ) {
            $proximity_where = (string) apply_filters( 'htax_search_proximity_where', '', $parsed_query['proximity'], 'pt.post_id' );
        }

        // The cache key includes the viewer's hidden-term visibility, so cached results for
        // a privileged user are never served to an unprivileged viewer within the TTL, and
        // the resolved proximity condition.
        $cache_key = 'htax_ft_' . md5( $query . serialize( $config ) . '|' . (int) htax_can_see_hidden_terms() . '|' . $proximity_where );
        $cached    = htax_get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        // Filter parsed query based on allowed fields
        // Silently ignore field operators for restricted fields
        $filtered_query = array();
        foreach ( $parsed_query as $field => $terms ) {
            if ( 'general' === $field ) {
                // General terms apply to all allowed fields
                $filtered_query['general'] = $terms;
            } elseif ( 'title' === $field || 'body' === $field || 'excerpt' === $field ) {
                // Post fields - map 'body' to 'content'
                $check_field = ( 'body' === $field ) ? 'content' : $field;
                if ( ! empty( $allowed_fields[ $check_field ] ) ) {
                    $filtered_query[ $field ] = $terms;
                }
            } elseif ( 'tags' === $field ) {
                // Tags - check if tags allowed
                if ( ! empty( $allowed_fields['tags'] ) ) {
                    $filtered_query['tags'] = $terms;
                }
            } elseif ( 'categories' === $field ) {
                // Categories - check if categories allowed
                if ( ! empty( $allowed_fields['categories'] ) ) {
                    $filtered_query['categories'] = $terms;
                }
            } elseif ( 'terms' === $field ) {
                // All terms - check if terms search is allowed
                if ( ! empty( $allowed_fields['terms'] ) ) {
                    $filtered_query['terms'] = $terms;
                }
            } elseif ( 'proximity' === $field ) {
                // Proximity tokens are not a searchable field — always preserve them.
                $filtered_query['proximity'] = $terms;
            } else {
                // Check if it's a specific taxonomy field (e.g., 'post_tag', 'category', custom taxonomy)
                // The parser creates these from operators like 'intaxonomy:' or 'in{taxonomy}:'
                if ( ! empty( $allowed_fields[ $field ] ) ) {
                    $filtered_query[ $field ] = $terms;
                }
            }
        }

        // Replace parsed query with filtered version.
        $parsed_query = $filtered_query;

        // If match='all', mark all non-excluded terms as required
        if ( 'all' === $match_mode ) {
            foreach ( $parsed_query as $field => &$terms ) {
                if ( 'proximity' === $field ) {
                    continue;
                }
                foreach ( $terms as &$term_data ) {
                    if ( ! $term_data['excluded'] && ! $term_data['required'] ) {
                        $term_data['required'] = true;
                    }
                }
            }
            unset( $terms, $term_data ); // Break references
        }

        // Get relevance multipliers from settings or use defaults
        $options = get_option( 'htax_settings', array() );
        $multipliers = isset( $options['relevance_multipliers'] ) ? $options['relevance_multipliers'] : array();

        // Default weights (compatible with existing settings structure)
        // Note: container_group uses container's weight
        $default_multipliers = array(
            'title'   => 2.0,
            'content' => 1.0,
            'excerpt' => 0.8,
            'taxonomies' => array(
                'post_tag' => array(
                    'use_description' => false,
                    'direct'    => array( 'text' => 0.6,  'description' => 0.4  ),
                    'container' => array( 'text' => 0.5,  'description' => 0.35 ),
                    'redirect'  => array( 'text' => 0.35, 'description' => 0.25 ),
                ),
                'category' => array(
                    'use_description' => false,
                    'direct'    => array( 'text' => 0.5,  'description' => 0.35 ),
                    'hierarchy' => array( 'text' => 0.4,  'description' => 0.3  ),
                    'container' => array( 'text' => 0.35, 'description' => 0.25 ),
                    'redirect'  => array( 'text' => 0.3,  'description' => 0.2  ),
                ),
            ),
            'custom_taxonomies_text' => array(
                'flat' => array(
                    'use_description' => false,
                    'direct'      => 0.6,
                    'container'   => 0.5,
                    'redirect'    => 0.35,
                    'description' => array(
                        'direct'    => 0.4,
                        'container' => 0.35,
                        'redirect'  => 0.25,
                    ),
                ),
                'hierarchical' => array(
                    'use_description' => false,
                    'direct'      => 0.5,
                    'hierarchy'   => 0.4,
                    'container'   => 0.35,
                    'redirect'    => 0.3,
                    'description' => array(
                        'direct'    => 0.35,
                        'hierarchy' => 0.3,
                        'container' => 0.25,
                        'redirect'  => 0.2,
                    ),
                ),
            ),
        );

        $multipliers = array_replace_recursive( $default_multipliers, $multipliers );

        // Auto-populate per-taxonomy defaults for any enabled custom taxonomy that has no
        // individual entry yet (e.g. newly added taxonomy, settings never saved for it).
        // Uses the flat/hierarchical template from custom_taxonomies_text, which is itself
        // user-configurable, so custom taxonomies behave identically to post_tag/category:
        // defaults are always present and individual saved values overlay them.
        foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
            if ( in_array( $taxonomy, array( 'post_tag', 'category', 'group' ), true ) ) {
                continue;
            }
            if ( isset( $multipliers['taxonomies'][ $taxonomy ] ) ) {
                continue; // Already has individual configuration
            }
            $template     = is_taxonomy_hierarchical( $taxonomy )
                ? $multipliers['custom_taxonomies_text']['hierarchical']
                : $multipliers['custom_taxonomies_text']['flat'];
            $tax_defaults = array(
                'use_description' => ! empty( $template['use_description'] ),
            );
            foreach ( array( 'direct', 'container', 'hierarchy', 'redirect' ) as $rel_type ) {
                if ( ! isset( $template[ $rel_type ] ) ) {
                    continue;
                }
                $tax_defaults[ $rel_type ] = array(
                    'text'        => $template[ $rel_type ],
                    'description' => isset( $template['description'][ $rel_type ] )
                        ? $template['description'][ $rel_type ]
                        : round( (float) $template[ $rel_type ] * 0.7, 2 ),
                );
            }
            $multipliers['taxonomies'][ $taxonomy ] = $tax_defaults;
        }

        // Helper function to get name/description weights for a taxonomy/relationship pair.
        // Every enabled taxonomy now has an entry in taxonomies[] (either user-saved or
        // auto-populated above), so the lookup always succeeds for known taxonomies.
        $defaults_cache   = array();
        $get_term_weights = function( $taxonomy, $rel_type ) use ( $multipliers, &$defaults_cache ) {
            if ( ! isset( $defaults_cache[ $taxonomy ] ) ) {
                $defaults_cache[ $taxonomy ] = htax_get_default_relevance_weights( $taxonomy );
            }
            $defaults     = $defaults_cache[ $taxonomy ];
            $tax_data     = $multipliers['taxonomies'][ $taxonomy ] ?? array();
            $text_weight  = floatval( $tax_data[ $rel_type ]['text']        ?? $defaults[ $rel_type ]['text']     ?? 0.5 );
            $desc_weight  = floatval( $tax_data[ $rel_type ]['description'] ?? round( $text_weight * 0.7, 2 ) );
            $expl_weight  = floatval( $tax_data[ $rel_type ]['explicit']    ?? $defaults[ $rel_type ]['explicit'] ?? 1.0 );
            return array(
                'name'            => $text_weight,
                'explicit'        => $expl_weight,
                'description'     => $desc_weight,
                'use_description' => (bool) ( $tax_data['use_description'] ?? false ),
            );
        };

        // Extract and validate minimum score thresholds
        // Valid range: 0.0 to 1.0 (exact same scale as relevance multipliers in settings)
        // 0 = field not required, >0 = minimum points required from that field
        $min_score = isset( $config['min_score'] ) ? floatval( $config['min_score'] ) : 0;
        $min_scores = array(
            'title' => isset( $config['min_title_score'] ) ? floatval( $config['min_title_score'] ) : 0,
            'content' => isset( $config['min_content_score'] ) ? floatval( $config['min_content_score'] ) : 0,
            'excerpt' => isset( $config['min_excerpt_score'] ) ? floatval( $config['min_excerpt_score'] ) : 0,
            'tags' => isset( $config['min_tags_score'] ) ? floatval( $config['min_tags_score'] ) : 0,
            'categories' => isset( $config['min_categories_score'] ) ? floatval( $config['min_categories_score'] ) : 0,
            'terms' => isset( $config['min_terms_score'] ) ? floatval( $config['min_terms_score'] ) : 0,
        );

        // Validate score ranges (0-1, same as relevance multiplier settings)
        if ( $min_score < 0 || $min_score > 1 ) {
            htax_log_error( 'Invalid min_score value', array( 'min_score' => $min_score ) );
            return array( 'results' => array(), 'total' => 0 );
        }
        foreach ( $min_scores as $field => $score ) {
            if ( $score < 0 || $score > 1 ) {
                htax_log_error( 'Invalid minimum score threshold', array( 'field' => $field, 'score' => $score ) );
                return array( 'results' => array(), 'total' => 0 );
            }
        }

        // Build search conditions and relevance scores
        // Each field type gets its own score column for proper min score comparison
        $select_parts = array();
        $join_clauses = array(); // populated with LEFT JOINs for taxonomy scoring (one per score type)
        $having_parts = array();
        $query_params = array();

        // Process wp_posts fields - calculate weighted scores for each field
        $has_title   = ! empty( $parsed_query['title'] )   || ( ! empty( $parsed_query['general'] ) && ! empty( $allowed_fields['title'] ) );
        $has_content = ! empty( $parsed_query['body'] )    || ( ! empty( $parsed_query['general'] ) && ! empty( $allowed_fields['content'] ) );
        $has_excerpt = ! empty( $parsed_query['excerpt'] ) || ( ! empty( $parsed_query['general'] ) && ! empty( $allowed_fields['excerpt'] ) );

        // Post text fields score with NATURAL LANGUAGE MODE so that IDF and document-length
        // normalisation prevent a single high-frequency term (e.g. "gift" ×600 or "target"
        // from href attributes) from dominating posts that cover more query terms.
        // Required/excluded term enforcement is handled separately via WHERE.
        if ( $has_title ) {
            $title_nl = htax_build_natural_language_query_string(
                array_merge( $parsed_query['title'] ?? array(), $parsed_query['general'] ?? array() )
            );
            if ( '' !== $title_nl ) {
                $select_parts['title_score'] = sprintf( 'COALESCE(MATCH(pt.title) AGAINST(%%s IN NATURAL LANGUAGE MODE) * %f, 0)', $multipliers['title'] );
                $query_params[]              = $title_nl;
            } else {
                $select_parts['title_score'] = '0';
            }
            if ( $min_scores['title'] > 0 ) {
                $having_parts[] = sprintf( 'title_score >= %f', $min_scores['title'] );
            }
        } else {
            $select_parts['title_score'] = '0';
        }

        if ( $has_content ) {
            $content_nl = htax_build_natural_language_query_string(
                array_merge( $parsed_query['body'] ?? array(), $parsed_query['general'] ?? array() )
            );
            if ( '' !== $content_nl ) {
                $select_parts['content_score'] = sprintf( 'COALESCE(MATCH(pt.body) AGAINST(%%s IN NATURAL LANGUAGE MODE) * %f, 0)', $multipliers['content'] );
                $query_params[]                = $content_nl;
            } else {
                $select_parts['content_score'] = '0';
            }
            if ( $min_scores['content'] > 0 ) {
                $having_parts[] = sprintf( 'content_score >= %f', $min_scores['content'] );
            }
        } else {
            $select_parts['content_score'] = '0';
        }

        if ( $has_excerpt ) {
            $excerpt_nl = htax_build_natural_language_query_string(
                array_merge( $parsed_query['excerpt'] ?? array(), $parsed_query['general'] ?? array() )
            );
            if ( '' !== $excerpt_nl ) {
                $select_parts['excerpt_score'] = sprintf( 'COALESCE(MATCH(pt.excerpt) AGAINST(%%s IN NATURAL LANGUAGE MODE) * %f, 0)', $multipliers['excerpt'] );
                $query_params[]                = $excerpt_nl;
            } else {
                $select_parts['excerpt_score'] = '0';
            }
            if ( $min_scores['excerpt'] > 0 ) {
                $having_parts[] = sprintf( 'excerpt_score >= %f', $min_scores['excerpt'] );
            }
        } else {
            $select_parts['excerpt_score'] = '0';
        }

        // Process term fields - build separate subqueries for tags, categories, and all terms
        $tags_subqueries = array();
        $cats_subqueries = array();
        $all_terms_subqueries = array();

        // Check what term queries we have
        $has_tags_query = ! empty( $parsed_query['tags'] );
        $has_cats_query = ! empty( $parsed_query['categories'] );
        $has_terms_query = ! empty( $parsed_query['terms'] );
        $has_general = ! empty( $parsed_query['general'] );

        // Build WHERE clause early so the closures below can append required/excluded term
        // conditions to it during the field-specific path. Initialising here (before any
        // of the taxonomy-scoring closures run) prevents the appended conditions from being
        // discarded when the variable is later assigned.
        $indexed_post_types = htax_get_indexed_post_types();
        $post_type_list     = implode( "','", array_map( 'esc_sql', $indexed_post_types ) );
        $where_clause = "pt.post_type IN ('$post_type_list') AND pt.post_status = 'publish'";
        $extra_where        = isset( $config['extra_where'] ) ? $config['extra_where'] : '';
        if ( '' !== $extra_where ) {
            $where_clause .= ' ' . $extra_where;
        }
        if ( '' !== $proximity_where ) {
            $where_clause .= ' AND ' . $proximity_where;
        }

        // ── Taxonomy scoring ─────────────────────────────────────────────────────
        // For pure general queries (no explicit field operators) we collapse all
        // taxonomy scoring into ONE LEFT JOIN with ONE FULLTEXT scan on wp_terms,
        // using a CASE expression to apply the correct weight per taxonomy/rel_type.
        //
        // For field-specific queries (tags:, categories:, terms:) separate LEFT JOINs are
        // kept so scores are distinct and per-field HAVING thresholds can be applied.

        // For excluded terms (- prefix), two enforcement layers are needed:
        //
        // 1. Taxonomy layer: a post that scores positively from one matching term and is also
        //    tagged with an excluded term will still appear because FULLTEXT "-word" only zeroes
        //    the excluded term's row score; other terms keep their positive score.  A WHERE
        //    p.ID NOT IN (posts tagged with the excluded term) is required.
        //
        // 2. Text layer (general excluded terms only): a post may still score via one text field
        //    even when another field contains the excluded word, because per-field FULLTEXT scoring
        //    zeroes only that field's score while the others remain positive.  A second NOT IN
        //    against MATCH(title, content, excerpt) AGAINST('+word') covers this case.
        //
        // Both subqueries use pre-prepared SQL so no %s placeholders are added to $where_clause,
        // avoiding any disruption to the existing $query_params ordering.
        $add_excluded_term_where = function( array $field_terms, string $taxonomy, bool $include_text_layer = false )
            use ( $wpdb, &$where_clause ) {
            $hst_hidden = htax_can_see_hidden_terms() ? '' : ' AND hst.is_hidden = 0';
            $tax_sql    = $taxonomy !== '' ? " AND hst.taxonomy = '" . esc_sql( $taxonomy ) . "'" : '';
            foreach ( $field_terms as $td ) {
                if ( empty( $td['excluded'] ) ) {
                    continue;
                }
                $term_str = $td['term'];
                $ft_val   = strpos( $term_str, ' ' ) !== false ? '"' . $term_str . '"' : $term_str;

                // Layer 1: taxonomy exclusion (applies to all field operators and general).
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT t.term_id FROM {$wpdb->terms} t
                     WHERE MATCH(t.name) AGAINST(%s IN BOOLEAN MODE) > 0
                     UNION
                     SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
                     WHERE MATCH(tt.description) AGAINST(%s IN BOOLEAN MODE) > 0",
                    $ft_val, $ft_val
                ) );
                if ( ! empty( $ids ) ) {
                    $id_list       = implode( ',', array_map( 'intval', $ids ) );
                    $where_clause .= " AND pt.post_id NOT IN (
                        SELECT hst.post_id FROM {$wpdb->prefix}htax_search_terms hst
                        WHERE hst.source_term_id IN ({$id_list}){$tax_sql}{$hst_hidden}
                    )";
                }

                // Layer 2: text exclusion — only for unqualified general terms, not for field
                // operators like terms: whose taxonomy='' means "all indexed taxonomies" rather
                // than "search post text".
                if ( $include_text_layer ) {
                    $ptt           = $wpdb->prefix . 'htax_post_text';
                    $text_excl     = $wpdb->prepare(
                        "AND pt.post_id NOT IN (
                            SELECT pt2.post_id FROM {$ptt} pt2
                            WHERE MATCH(pt2.title, pt2.body, pt2.excerpt)
                                  AGAINST(%s IN BOOLEAN MODE) > 0
                        )",
                        '+' . $ft_val
                    );
                    $where_clause .= ' ' . $text_excl;
                }
            }
        };

        // For taxonomy fields, FULLTEXT queries against individual term-name rows cannot use
        // "+word1 +word2" to require multiple distinct terms — that would demand both words in
        // the same term name, which is impossible.  Instead, required taxonomy terms are demoted
        // to optional in the FULLTEXT query (OR mode for scoring) and each required term is
        // enforced separately via a WHERE p.ID IN (id_list) using pre-fetched term IDs.
        //
        // Pre-fetching avoids adding %s placeholders to $where_clause, which would disrupt the
        // carefully ordered $query_params array that the outer $wpdb->prepare() relies on.
        //
        // $field_terms  — parsed query array for one field (e.g. $parsed_query['tags'])
        // $taxonomy     — slug to restrict the htax_search_terms lookup, or '' for all
        // $where_clause is captured by reference; $query_params is NOT modified here.
        $add_required_term_where = function( array $field_terms, string $taxonomy )
            use ( $wpdb, &$where_clause ) {
            $hst_hidden = htax_can_see_hidden_terms() ? '' : ' AND hst.is_hidden = 0';
            $tax_sql    = $taxonomy !== '' ? " AND hst.taxonomy = '" . esc_sql( $taxonomy ) . "'" : '';
            foreach ( $field_terms as $td ) {
                if ( empty( $td['required'] ) ) {
                    continue;
                }
                $term_str = $td['term'];
                $ft_val   = strpos( $term_str, ' ' ) !== false ? '"' . $term_str . '"' : $term_str;
                // Fetch term IDs matching via name OR description (same two branches as the scoring JOIN).
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT t.term_id FROM {$wpdb->terms} t
                     WHERE MATCH(t.name) AGAINST(%s IN BOOLEAN MODE) > 0
                     UNION
                     SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
                     WHERE MATCH(tt.description) AGAINST(%s IN BOOLEAN MODE) > 0",
                    $ft_val, $ft_val
                ) );
                if ( empty( $ids ) ) {
                    $where_clause .= ' AND 1=0'; // required term matches no terms → no posts possible
                    return;
                }
                $id_list       = implode( ',', array_map( 'intval', $ids ) );
                $where_clause .= " AND pt.post_id IN (
                    SELECT hst.post_id FROM {$wpdb->prefix}htax_search_terms hst
                    WHERE hst.source_term_id IN ({$id_list}){$tax_sql}{$hst_hidden}
                )";
            }
        };

        // Required general terms (+prefix without a field operator) must appear somewhere:
        // in the post's text fields (title/content/excerpt) OR in the taxonomy search index.
        // With NATURAL LANGUAGE MODE scoring this can no longer be inferred from the score
        // being zero, so a pre-prepared WHERE OR condition is added explicitly.
        // Same pre-fetch pattern as $add_required_term_where — no %s added to $query_params.
        $add_required_general_term_where = function( array $general_terms )
            use ( $wpdb, &$where_clause ) {
            $hst_hidden = htax_can_see_hidden_terms() ? '' : ' AND hst.is_hidden = 0';
            foreach ( $general_terms as $td ) {
                if ( empty( $td['required'] ) ) {
                    continue;
                }
                $term_str = $td['term'];
                $ft_val   = strpos( $term_str, ' ' ) !== false ? '"' . $term_str . '"' : $term_str;

                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT t.term_id FROM {$wpdb->terms} t
                     WHERE MATCH(t.name) AGAINST(%s IN BOOLEAN MODE) > 0
                     UNION
                     SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
                     WHERE MATCH(tt.description) AGAINST(%s IN BOOLEAN MODE) > 0",
                    $ft_val, $ft_val
                ) );

                $text_cond = $wpdb->prepare(
                    'MATCH(pt.title, pt.body, pt.excerpt) AGAINST(%s IN BOOLEAN MODE) > 0',
                    $ft_val
                );

                if ( ! empty( $ids ) ) {
                    $id_list       = implode( ',', array_map( 'intval', $ids ) );
                    $where_clause .= " AND ({$text_cond} OR pt.post_id IN (
                        SELECT hst.post_id FROM {$wpdb->prefix}htax_search_terms hst
                        WHERE hst.source_term_id IN ({$id_list}){$hst_hidden}
                    ))";
                } else {
                    $where_clause .= " AND {$text_cond}";
                }
            }
        };

        // Returns true if any term in a parsed field array carries required=true (+prefix).
        // Used by both the field-specific path and the custom taxonomy section.
        $has_required = static function( array $field_terms ) {
            foreach ( $field_terms as $td ) {
                if ( ! empty( $td['required'] ) ) {
                    return true;
                }
            }
            return false;
        };

        // Enforce required general terms via WHERE (text OR taxonomy).
        // Must run before both combined and field-specific paths so it applies in either case.
        //
        // Quoted phrases (multi-word terms) are also enforced here regardless of the + flag:
        // NATURAL LANGUAGE MODE does not support phrase syntax, so without a BOOLEAN MODE
        // WHERE guard a quoted phrase like "air canada" degrades to two independent optional
        // words and posts containing only "air" or only "canada" can appear in results.
        $general_for_where = array_map(
            static function ( $td ) {
                if ( ! $td['excluded'] && strpos( $td['term'], ' ' ) !== false ) {
                    return array_merge( $td, array( 'required' => true ) );
                }
                return $td;
            },
            $parsed_query['general'] ?? array()
        );
        $add_required_general_term_where( $general_for_where );

        // Enforce required field-specific text terms (+title:word, +content:word, +excerpt:word)
        // with an explicit BOOLEAN MODE WHERE guard: NATURAL LANGUAGE MODE scoring does not
        // zero out posts missing a required term, so the requirement must be enforced here.
        foreach ( array(
            'title'   => 'pt.title',
            'body'    => 'pt.body',
            'excerpt' => 'pt.excerpt',
        ) as $field_key => $field_col ) {
            foreach ( $parsed_query[ $field_key ] ?? array() as $td ) {
                if ( empty( $td['required'] ) ) {
                    continue;
                }
                $ft_val        = strpos( $td['term'], ' ' ) !== false ? '"' . $td['term'] . '"' : $td['term'];
                $where_clause .= $wpdb->prepare(
                    " AND MATCH({$field_col}) AGAINST(%s IN BOOLEAN MODE) > 0",
                    $ft_val
                );
            }
        }

        // Plain text queries (no tags: / categories: / terms: operators) score all taxonomies in
        // one FULLTEXT scan. Tag / category score thresholds don't need separate scans: the same
        // scan also returns per-taxonomy sub-totals (see $split_tax_scores), used only by the
        // thresholds — relevance stays the single terms_score, exactly as without thresholds.
        $use_combined_tax_scan = $has_general
            && ! $has_tags_query
            && ! $has_cats_query
            && ! $has_terms_query;
        $split_tax_scores      = $use_combined_tax_scan && ( $min_scores['tags'] > 0 || $min_scores['categories'] > 0 );

        // Custom taxonomy fields — parsed as taxonomy slug keys (e.g., 'color', 'location').
        $custom_taxonomy_fields = array();
        foreach ( $parsed_query as $key => $value ) {
            // Skip known built-in fields
            if ( in_array( $key, array( 'title', 'body', 'excerpt', 'tags', 'categories', 'terms', 'general', 'proximity' ), true ) ) {
                continue;
            }

            // This is a custom taxonomy field
            if ( ! empty( $value ) && is_array( $value ) ) {
                $custom_taxonomy_fields[ $key ] = $value;
            }
        }

        // General words score each taxonomy once: in that taxonomy's own field when the query
        // has one (custom taxonomy fields here; tags and categories too in the field-specific
        // path), otherwise in terms_score. These taxonomies are left out of terms_score's
        // general-word scoring so the same match doesn't count in two fields.
        $general_field_taxonomies = $has_general
            ? array_values( array_filter( array_keys( $custom_taxonomy_fields ), 'taxonomy_exists' ) )
            : array();

        // min_terms_score is checked once every taxonomy field is built (see below).
        $apply_terms_threshold = false;

        // Pre-fetch exact-match term IDs for quoted general terms.
        // Field-specific operators always check exact match; general terms only when quoted
        // (plain `target` is ambiguous — could be text content — so no exact-term bonus).
        $general_exact_ids = array();
        foreach ( $parsed_query['general'] ?? array() as $td ) {
            if ( empty( $td['excluded'] ) && ! empty( $td['quoted'] ) ) {
                $general_exact_ids = array_merge(
                    $general_exact_ids,
                    htax_get_exact_match_term_ids( $td['term'] )
                );
            }
        }
        $general_exact_ids = array_unique( $general_exact_ids );

        // Collect exact-match IDs for one taxonomy (field-specific path and custom taxonomy fields):
        // field-specific terms always get exact matching; quoted general terms get it only when
        // the flag is set.
        $get_field_exact_ids = function( array $field_terms, string $taxonomy, bool $include_general = true ) use ( $parsed_query ): array {
            $ids = array();
            foreach ( $field_terms as $td ) {
                if ( empty( $td['excluded'] ) ) {
                    $ids = array_merge( $ids, htax_get_exact_match_term_ids( $td['term'], $taxonomy ) );
                }
            }
            foreach ( $include_general ? ( $parsed_query['general'] ?? array() ) : array() as $td ) {
                if ( empty( $td['excluded'] ) && ! empty( $td['quoted'] ) ) {
                    $ids = array_merge( $ids, htax_get_exact_match_term_ids( $td['term'], $taxonomy ) );
                }
            }
            return array_unique( array_map( 'intval', $ids ) );
        };

        if ( $use_combined_tax_scan ) {
            // Build a single CASE-weighted LEFT JOIN across all taxonomies/rel_types.
            $all_tax_query = htax_build_boolean_query_string( $parsed_query['general'] ?? array() );
            $taxonomies_in_index = $this->get_indexed_taxonomies();

            $case_whens_name = array();
            $case_whens_desc = array();
            foreach ( array_diff( $taxonomies_in_index, $general_field_taxonomies ) as $taxonomy ) {
                $rel_types = htax_get_expand_types_for_taxonomy( $taxonomy );
                foreach ( $rel_types as $rel_type ) {
                    $settings_rel_type = ( 'redirect_target' === $rel_type ) ? 'redirect' : $rel_type;
                    $weights           = $get_term_weights( $taxonomy, $settings_rel_type );
                    // hst. prefix required: combined JOIN query references two tables.
                    // Text (name/description) weights stay dynamic; explicit weight is baked into strength.
                    $case_whens_name[] = sprintf(
                        "WHEN hst.taxonomy = '%s' AND hst.relationship_type = '%s' THEN %f",
                        esc_sql( $taxonomy ), esc_sql( $rel_type ), $weights['name']
                    );
                    if ( $weights['use_description'] ) {
                        $case_whens_desc[] = sprintf(
                            "WHEN hst.taxonomy = '%s' AND hst.relationship_type = '%s' THEN %f",
                            esc_sql( $taxonomy ), esc_sql( $rel_type ), $weights['description']
                        );
                    }
                }
            }

            if ( ! empty( $case_whens_name ) ) {
                $case_sql_name = 'CASE ' . implode( ' ', $case_whens_name ) . ' ELSE 0 END';
                // Appended to every hst branch below: visibility plus the strength model,
                // since every branch scores by hst.strength.
                $hst_hidden    = ( htax_can_see_hidden_terms() ? '' : ' AND hst.is_hidden = 0' )
                               . htax_strength_model_sql( 'hst' );
                // The exact-match branch has no CASE weight, so taxonomies scored in their own
                // field are filtered out of every branch here.
                if ( $general_field_taxonomies ) {
                    $hst_hidden .= " AND hst.taxonomy NOT IN ('" . implode( "','", array_map( 'esc_sql', $general_field_taxonomies ) ) . "')";
                }

                // Exact-match branch: injected when quoted general terms match any indexed term name.
                // The branch produces a flat explicit-weight score; exact-match IDs are excluded from
                // the FULLTEXT branch so each term is scored by exactly one branch.
                // With $split_tax_scores each scored row also carries its source term's taxonomy,
                // and the outer GROUP BY adds per-taxonomy sub-totals next to terms_score. A source
                // term has a single taxonomy, so grouping by it as well leaves every MAX unchanged.
                $tax_col         = $split_tax_scores ? ' hst.taxonomy AS tax,' : '';
                $tax_group       = $split_tax_scores ? ', tax' : '';
                $tax_sums        = $split_tax_scores
                    ? ", SUM(IF(tax = 'post_tag', max_score, 0)) AS tags_part, SUM(IF(tax = 'category', max_score, 0)) AS cats_part"
                    : '';
                $exact_branch    = '';
                $exact_name_excl = '';
                if ( ! empty( $general_exact_ids ) ) {
                    $exact_id_list   = implode( ',', $general_exact_ids );
                    $exact_name_excl = " AND t.term_id NOT IN ({$exact_id_list})";
                    // Explicit weight is baked into strength; use hst.strength directly.
                    $exact_branch    = "
                                     UNION ALL
                                     SELECT hst.post_id, hst.source_term_id,{$tax_col}
                                            hst.strength AS score
                                     FROM {$wpdb->prefix}htax_search_terms hst
                                     WHERE hst.source_term_id IN ({$exact_id_list}){$hst_hidden}";
                }

                if ( ! empty( $case_whens_desc ) ) {
                    // Two-branch UNION (name + desc) — plus optional exact branch.
                    // MAX per (post, source_term) prevents double-counting.
                    // text_weight stays dynamic via CASE; strength adds per-post centrality.
                    $case_sql_desc  = 'CASE ' . implode( ' ', $case_whens_desc ) . ' ELSE 0 END';
                    $join_clauses[] = sprintf(
                        "LEFT JOIN (
                             SELECT post_id, SUM(max_score) AS terms_score{$tax_sums}
                             FROM (
                                 SELECT post_id, source_term_id{$tax_group}, MAX(score) AS max_score FROM (
                                     SELECT hst.post_id, hst.source_term_id,{$tax_col}
                                            MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) * (%s) * hst.strength AS score
                                     FROM {$wpdb->terms} t
                                     INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = t.term_id
                                     WHERE MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) > 0%s%s
                                     UNION ALL
                                     SELECT hst.post_id, hst.source_term_id,{$tax_col}
                                            MATCH(tt.description) AGAINST(%%s IN BOOLEAN MODE) * (%s) * hst.strength AS score
                                     FROM {$wpdb->term_taxonomy} tt
                                     INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = tt.term_id
                                     WHERE MATCH(tt.description) AGAINST(%%s IN BOOLEAN MODE) > 0%s%s
                                 ) AS branches GROUP BY post_id, source_term_id{$tax_group}
                             ) AS per_term GROUP BY post_id
                         ) AS htax_all ON htax_all.post_id = pt.post_id",
                        $case_sql_name, $hst_hidden, $exact_name_excl,
                        $case_sql_desc, $hst_hidden, $exact_branch
                    );
                    $query_params[] = $all_tax_query; // branch 1: MATCH(t.name) in SELECT
                    $query_params[] = $all_tax_query; // branch 1: MATCH(t.name) in WHERE
                    $query_params[] = $all_tax_query; // branch 2: MATCH(tt.description) in SELECT
                    $query_params[] = $all_tax_query; // branch 2: MATCH(tt.description) in WHERE
                } else {
                    // Name-only scan — plus optional exact branch.
                    if ( '' !== $exact_branch ) {
                        $join_clauses[] = sprintf(
                            "LEFT JOIN (
                                 SELECT post_id, SUM(max_score) AS terms_score{$tax_sums}
                                 FROM (
                                     SELECT post_id, source_term_id{$tax_group}, MAX(score) AS max_score FROM (
                                         SELECT hst.post_id, hst.source_term_id,{$tax_col}
                                                MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) * (%s) * hst.strength AS score
                                         FROM {$wpdb->terms} t
                                         INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = t.term_id
                                         WHERE MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) > 0%s%s%s
                                     ) AS branches GROUP BY post_id, source_term_id{$tax_group}
                                 ) AS per_term GROUP BY post_id
                             ) AS htax_all ON htax_all.post_id = pt.post_id",
                            $case_sql_name, $hst_hidden, $exact_name_excl, $exact_branch
                        );
                        $query_params[] = $all_tax_query; // MATCH SELECT
                        $query_params[] = $all_tax_query; // MATCH WHERE
                    } elseif ( $split_tax_scores ) {
                        // No exact branch, with per-taxonomy sub-totals: MAX per (post, source term),
                        // then sum per post.
                        $join_clauses[] = sprintf(
                            "LEFT JOIN (
                                 SELECT post_id, SUM(max_score) AS terms_score{$tax_sums}
                                 FROM (
                                     SELECT hst.post_id AS post_id, hst.source_term_id,{$tax_col}
                                            MAX(MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) * (%s) * hst.strength) AS max_score
                                     FROM {$wpdb->terms} t
                                     INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = t.term_id
                                     WHERE MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) > 0%s
                                     GROUP BY hst.post_id, hst.source_term_id{$tax_group}
                                 ) AS per_term GROUP BY post_id
                             ) AS htax_all ON htax_all.post_id = pt.post_id",
                            $case_sql_name, $hst_hidden
                        );
                        $query_params[] = $all_tax_query; // MATCH SELECT
                        $query_params[] = $all_tax_query; // MATCH WHERE
                    } else {
                        // No exact branch: MAX per (post, source term), then sum per post. A post can reach
                        // one matched term through several index rows (several of its terms lead there,
                        // or several relationship types); the term still counts once, at its strongest.
                        $join_clauses[] = sprintf(
                            "LEFT JOIN (
                                 SELECT post_id, SUM(max_score) AS terms_score
                                 FROM (
                                     SELECT hst.post_id AS post_id, hst.source_term_id,
                                            MAX(MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) * (%s) * hst.strength) AS max_score
                                     FROM {$wpdb->terms} t
                                     INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = t.term_id
                                     WHERE MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) > 0%s
                                     GROUP BY hst.post_id, hst.source_term_id
                                 ) AS per_term GROUP BY post_id
                             ) AS htax_all ON htax_all.post_id = pt.post_id",
                            $case_sql_name, $hst_hidden
                        );
                        $query_params[] = $all_tax_query; // MATCH SELECT
                        $query_params[] = $all_tax_query; // MATCH WHERE
                    }
                }
                $select_parts['terms_score'] = 'COALESCE(htax_all.terms_score, 0)';
            } else {
                $select_parts['terms_score'] = '0';
            }

            $select_parts['tags_score']       = '0';
            $select_parts['categories_score'] = '0';

            // Enforce excluded general terms at the post level: both taxonomy and text layers.
            $add_excluded_term_where( $parsed_query['general'] ?? array(), '', true );

            $apply_terms_threshold = $min_scores['terms'] > 0;
            // Tag / category thresholds compare the scan's per-taxonomy sub-totals; these
            // columns are deliberately not relevance parts (terms_score already includes them).
            if ( $split_tax_scores && isset( $case_sql_name ) ) {
                if ( $min_scores['tags'] > 0 ) {
                    $select_parts['tags_threshold_score'] = 'COALESCE(htax_all.tags_part, 0)';
                    $having_parts[]                       = sprintf( 'tags_threshold_score >= %f', $min_scores['tags'] );
                }
                if ( $min_scores['categories'] > 0 ) {
                    $select_parts['categories_threshold_score'] = 'COALESCE(htax_all.cats_part, 0)';
                    $having_parts[]                             = sprintf( 'categories_threshold_score >= %f', $min_scores['categories'] );
                }
            } elseif ( $min_scores['tags'] > 0 || $min_scores['categories'] > 0 ) {
                // No taxonomy is indexed for search, so no post can reach the threshold.
                $having_parts[] = '1 = 0';
            }

        } else {
            // ── Separate per-field LEFT JOINs ────────────────────────────────────

            // Enforce excluded general terms at the post level, as in the combined path: both
            // taxonomy and text layers.
            $add_excluded_term_where( $parsed_query['general'] ?? array(), '', true );

            // Tags - tags: queries + general queries
            if ( $has_tags_query || $has_general ) {
                // Demote required tag terms to optional in the FULLTEXT query; presence is
                // enforced per-term via WHERE subqueries so multiple required tags find posts that
                // carry all required tags separately rather than requiring both words in one term name.
                $tags_terms_or = array_map(
                    fn( $t ) => $t['required'] ? array_merge( $t, array( 'required' => false ) ) : $t,
                    $parsed_query['tags'] ?? array()
                );
                $tags_query = htax_build_boolean_query_string( array_merge( $tags_terms_or, $parsed_query['general'] ?? array() ) );

                $tag_rel_types = htax_get_expand_types_for_taxonomy( 'post_tag' );

                $tags_subqueries = $this->build_term_search_subqueries(
                    $tags_query, 'post_tag', $tag_rel_types, $get_term_weights, $query_params,
                    $get_field_exact_ids( $parsed_query['tags'] ?? array(), 'post_tag' )
                );
                $tags_union = implode( ' UNION ALL ', $tags_subqueries );
                $join_clauses[] = $this->sum_term_scores_sql( $tags_union, 'tags_score', 'htax_tags' );
                $select_parts['tags_score'] = 'COALESCE(htax_tags.tags_score, 0)';
                $add_required_term_where( $parsed_query['tags'] ?? array(), 'post_tag' );
                $add_excluded_term_where( $parsed_query['tags'] ?? array(), 'post_tag' );

                if ( $min_scores['tags'] > 0 ) {
                    $having_parts[] = sprintf( 'tags_score >= %f', $min_scores['tags'] );
                } elseif ( $has_tags_query && $has_required( $parsed_query['tags'] ?? array() ) ) {
                    $having_parts[] = 'tags_score > 0';
                }
            } else {
                $select_parts['tags_score'] = '0';
            }

            // Categories - categories: queries + general queries
            if ( $has_cats_query || $has_general ) {
                $cats_terms_or = array_map(
                    fn( $t ) => $t['required'] ? array_merge( $t, array( 'required' => false ) ) : $t,
                    $parsed_query['categories'] ?? array()
                );
                $cats_query = htax_build_boolean_query_string( array_merge( $cats_terms_or, $parsed_query['general'] ?? array() ) );

                $cat_rel_types = htax_get_expand_types_for_taxonomy( 'category' );

                $cats_subqueries = $this->build_term_search_subqueries(
                    $cats_query, 'category', $cat_rel_types, $get_term_weights, $query_params,
                    $get_field_exact_ids( $parsed_query['categories'] ?? array(), 'category' )
                );
                $cats_union = implode( ' UNION ALL ', $cats_subqueries );
                $join_clauses[] = $this->sum_term_scores_sql( $cats_union, 'cats_score', 'htax_cats' );
                $select_parts['categories_score'] = 'COALESCE(htax_cats.cats_score, 0)';
                $add_required_term_where( $parsed_query['categories'] ?? array(), 'category' );
                $add_excluded_term_where( $parsed_query['categories'] ?? array(), 'category' );

                if ( $min_scores['categories'] > 0 ) {
                    $having_parts[] = sprintf( 'categories_score >= %f', $min_scores['categories'] );
                } elseif ( $has_cats_query && $has_required( $parsed_query['categories'] ?? array() ) ) {
                    $having_parts[] = 'categories_score > 0';
                }
            } else {
                $select_parts['categories_score'] = '0';
            }

            // All terms - terms: queries (and general as fallback)
            if ( $has_terms_query || $has_general ) {
                $terms_terms_or = array_map(
                    fn( $t ) => $t['required'] ? array_merge( $t, array( 'required' => false ) ) : $t,
                    $parsed_query['terms'] ?? array()
                );
                $all_terms_query = htax_build_boolean_query_string( array_merge( $terms_terms_or, $parsed_query['general'] ?? array() ) );
                // Tags and categories always get their own field here when there are general
                // words, so those words already score them; for these taxonomies terms_score
                // scores only terms: words (general exclusions stay, to keep term-name matching).
                $own_field_taxonomies = $has_general
                    ? array_merge( array( 'post_tag', 'category' ), $general_field_taxonomies )
                    : array();
                $terms_only_query     = htax_build_boolean_query_string( array_merge(
                    $terms_terms_or,
                    array_filter( $parsed_query['general'] ?? array(), fn( $t ) => ! empty( $t['excluded'] ) )
                ) );
                $has_positive_terms   = (bool) array_filter( $parsed_query['terms'] ?? array(), fn( $t ) => empty( $t['excluded'] ) );
                $taxonomies_in_index  = $this->get_indexed_taxonomies();
                $all_terms_subqueries = array();

                foreach ( $taxonomies_in_index as $taxonomy ) {
                    $own_field = in_array( $taxonomy, $own_field_taxonomies, true );
                    if ( $own_field && ! $has_positive_terms ) {
                        continue;
                    }
                    $rel_types            = htax_get_expand_types_for_taxonomy( $taxonomy );
                    $all_terms_subqueries = array_merge(
                        $all_terms_subqueries,
                        $this->build_term_search_subqueries(
                            $own_field ? $terms_only_query : $all_terms_query, $taxonomy, $rel_types, $get_term_weights, $query_params,
                            $get_field_exact_ids( $parsed_query['terms'] ?? array(), $taxonomy, ! $own_field )
                        )
                    );
                }

                if ( $all_terms_subqueries ) {
                    $all_terms_union = implode( ' UNION ALL ', $all_terms_subqueries );
                    $join_clauses[] = $this->sum_term_scores_sql( $all_terms_union, 'terms_score', 'htax_terms' );
                    $select_parts['terms_score'] = 'COALESCE(htax_terms.terms_score, 0)';
                } else {
                    $select_parts['terms_score'] = '0';
                }
                // '' = no taxonomy restriction: terms: searches all indexed taxonomies.
                $add_required_term_where( $parsed_query['terms'] ?? array(), '' );
                $add_excluded_term_where( $parsed_query['terms'] ?? array(), '' );

                if ( $min_scores['terms'] > 0 ) {
                    $apply_terms_threshold = true;
                } elseif ( $has_terms_query && $has_required( $parsed_query['terms'] ?? array() ) ) {
                    $having_parts[] = 'terms_score > 0';
                }
            } else {
                $select_parts['terms_score'] = '0';
            }
        } // end field-specific path

        // Process each custom taxonomy field
        foreach ( $custom_taxonomy_fields as $taxonomy_slug => $terms ) {
            // Demote required terms to optional in the FULLTEXT query; presence is enforced via
            // WHERE subqueries so multiple required terms find posts carrying all of them separately.
            $terms_or = array_map(
                fn( $t ) => $t['required'] ? array_merge( $t, array( 'required' => false ) ) : $t,
                $terms
            );
            $taxonomy_query = htax_build_boolean_query_string( array_merge( $terms_or, $parsed_query['general'] ?? array() ) );

            // Determine relationship types for this taxonomy
            $tax_obj = get_taxonomy( $taxonomy_slug );
            if ( ! $tax_obj ) {
                continue; // Invalid taxonomy
            }

            // Build rel-type list gated by site settings and per-feature flags at query time.
            $rel_types = htax_get_expand_types_for_taxonomy( $taxonomy_slug );

            // Build subqueries for this custom taxonomy
            $taxonomy_subqueries = $this->build_term_search_subqueries(
                $taxonomy_query,
                $taxonomy_slug,
                $rel_types,
                $get_term_weights,
                $query_params,
                $get_field_exact_ids( $terms, $taxonomy_slug )
            );

            $safe_slug    = esc_sql( $taxonomy_slug );
            $score_column = $safe_slug . '_score';
            $tax_union    = implode( ' UNION ALL ', $taxonomy_subqueries );
            $join_clauses[] = $this->sum_term_scores_sql( $tax_union, $score_column, 'htax_' . $safe_slug );
            $select_parts[ $score_column ] = "COALESCE(htax_{$safe_slug}.{$score_column}, 0)";
            $add_required_term_where( $terms, $taxonomy_slug );
            $add_excluded_term_where( $terms, $taxonomy_slug );

            if ( isset( $min_scores[ $taxonomy_slug ] ) && $min_scores[ $taxonomy_slug ] > 0 ) {
                $having_parts[] = sprintf( '%s >= %f', $score_column, $min_scores[ $taxonomy_slug ] );
            } elseif ( $has_required( $custom_taxonomy_fields[ $taxonomy_slug ] ) ) {
                $having_parts[] = "$score_column > 0";
            }
        }

        // min_terms_score: the score across all taxonomies. Each taxonomy match counts in exactly
        // one of these columns (general words score a taxonomy in its own field when the query has
        // one, otherwise in terms_score), so their sum is the total taxonomy score. Not a
        // relevance part of its own.
        if ( $apply_terms_threshold ) {
            $tax_columns = array( 'tags_score', 'categories_score', 'terms_score' );
            foreach ( array_keys( $custom_taxonomy_fields ) as $taxonomy_slug ) {
                if ( isset( $select_parts[ esc_sql( $taxonomy_slug ) . '_score' ] ) ) {
                    $tax_columns[] = esc_sql( $taxonomy_slug ) . '_score';
                }
            }
            $having_parts[] = sprintf( '( %s ) >= %f', implode( ' + ', $tax_columns ), $min_scores['terms'] );
        }

        // Check that we have at least one search criterion
        $has_custom_taxonomy = ! empty( $custom_taxonomy_fields );
        $has_any_query = $has_title || $has_content || $has_excerpt || $has_tags_query || $has_cats_query || $has_terms_query || $has_general || $has_custom_taxonomy;

        // A search made only of near: tokens returns the posts with a location in range (the
        // condition is already in the WHERE clause), scored by distance so that relevance
        // ordering is nearest first. As in any search, a near: whose location is unknown is
        // ignored (the visitor is told), leaving every post.
        $near_only = ! $has_any_query && ! empty( $parsed_query['proximity'] );
        if ( ! $has_any_query && ! $near_only ) {
            return array( 'results' => array(), 'total' => 0 ); // No search criteria
        }
        if ( $near_only ) {
            $select_parts['proximity_score'] = (string) apply_filters( 'htax_search_proximity_relevance', '0', $parsed_query['proximity'], 'pt.post_id' );
        }

        // Build SELECT clause with all field scores, each with proper column alias
        $select_fields_with_aliases = array();
        foreach ( $select_parts as $column_name => $formula ) {
            $select_fields_with_aliases[] = "$formula AS $column_name";
        }
        $select_fields = implode( ', ', $select_fields_with_aliases );

        // Calculate overall relevance as sum of all field score COLUMNS (not formulas)
        // Use column names instead of repeating MATCH() clauses to avoid doubling query params
        $relevance_parts = array();
        if ( '0' !== $select_parts['title_score'] ) {
            $relevance_parts[] = 'title_score';
        }
        if ( '0' !== $select_parts['content_score'] ) {
            $relevance_parts[] = 'content_score';
        }
        if ( '0' !== $select_parts['excerpt_score'] ) {
            $relevance_parts[] = 'excerpt_score';
        }
        if ( '0' !== $select_parts['tags_score'] ) {
            $relevance_parts[] = 'tags_score';
        }
        if ( '0' !== $select_parts['categories_score'] ) {
            $relevance_parts[] = 'categories_score';
        }
        if ( '0' !== $select_parts['terms_score'] ) {
            $relevance_parts[] = 'terms_score';
        }

        // Add custom taxonomy scores to relevance calculation
        foreach ( $custom_taxonomy_fields as $taxonomy_slug => $terms ) {
            $score_column = esc_sql( $taxonomy_slug ) . '_score';
            if ( isset( $select_parts[ $score_column ] ) && '0' !== $select_parts[ $score_column ] ) {
                $relevance_parts[] = $score_column;
            }
        }

        if ( $near_only ) {
            $relevance_parts[] = 'proximity_score';
        }

        // Build relevance formula from column names
        if ( empty( $relevance_parts ) ) {
            // No search criteria - this shouldn't happen due to earlier check, but be safe
            $relevance_formula = '0';
        } elseif ( 1 === count( $relevance_parts ) ) {
            // Single part - just use the column name
            $relevance_formula = $relevance_parts[0];
        } else {
            // Multiple parts - sum the columns
            $relevance_formula = '(' . implode( ' + ', $relevance_parts ) . ')';
        }

        // Build HAVING clause with AND logic for all min score checks
        if ( $min_score > 0 ) {
            $having_parts[] = sprintf( 'relevance >= %f', $min_score );
        }

        // If no min scores set, require at least some relevance. Score thresholds rate text
        // matches, so a near:-only search (scored by distance) keeps every post in range.
        $having_clause = ! empty( $having_parts )
            ? implode( ' AND ', $having_parts )
            : 'relevance > 0';
        if ( $near_only ) {
            $having_clause = '1 = 1';
        }

        // Build ORDER BY clause
        // Note: Since we use a subquery, column references in ORDER BY should use subquery aliases (not p.)
        $orderby = isset( $config['orderby'] ) ? $config['orderby'] : array( 'relevance' => 'DESC', 'date' => 'DESC' );

        $order_parts = array();
        $first_dir   = null;
        foreach ( $orderby as $field => $order ) {
            $order = strtoupper( $order );
            if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
                $order = 'DESC';
            }
            if ( null === $first_dir ) {
                $first_dir = $order;
            }

            if ( 'relevance' === $field ) {
                $order_parts[] = "relevance $order";
            } elseif ( 'last_comment' === $field ) {
                $order_parts[] = "COALESCE(last_comment_gmt, '0000-00-00 00:00:00') $order";
            } elseif ( 'last_activity' === $field ) {
                $order_parts[] = "last_activity_gmt $order";
            } elseif ( 'ID' === $field ) {
                $order_parts[] = "post_id $order";
            } else {
                $safe_field = sanitize_key( $field );
                // Use column alias from subquery (post_date, post_modified, etc.)
                $order_parts[] = "post_$safe_field $order";
            }
        }

        if ( ! empty( $order_parts ) && ! isset( $orderby['ID'] ) ) {
            $order_parts[] = 'post_id ' . ( $first_dir ?? 'DESC' );
        }
        $order_clause = ! empty( $order_parts ) ? implode( ', ', $order_parts ) : "relevance DESC, post_id DESC";

        // Pagination
        $posts_per_page = isset( $config['posts_per_page'] ) ? intval( $config['posts_per_page'] ) : 10;
        $max_results    = isset( $config['max_results'] ) ? max( 0, intval( $config['max_results'] ) ) : 0;
        $page           = isset( $config['page'] ) ? intval( $config['page'] ) : 1;
        $offset         = ( $page - 1 ) * $posts_per_page;

        // Cap LIMIT so the query never returns results beyond max_results
        $effective_limit = $posts_per_page;
        if ( $max_results > 0 ) {
            if ( $offset >= $max_results ) {
                return array( 'results' => array(), 'total' => 0 );
            }
            $effective_limit = min( $posts_per_page, $max_results - $offset );
        }

        // Taxonomy LEFT JOINs are placed before WHERE in the inner SELECT so each FT scan
        // runs once globally.  For rare/no-match words both branches return 0 rows instantly.
        // For common words the FT scan materialises once, GROUP BY aggregates, then joins.
        $join_sql = implode( "\n                 ", $join_clauses );
        $ptt = $wpdb->prefix . 'htax_post_text';
        $sql = sprintf(
            "SELECT scored.*,
                    %s AS relevance
             FROM (
                 SELECT pt.post_id,
                        %s,
                        pt.post_date_gmt AS post_date, pt.post_modified_gmt AS post_modified,
                        pt.title AS post_title, pt.post_author, pt.comment_count,
                        pt.last_comment_gmt,
                        GREATEST(pt.post_modified_gmt, COALESCE(pt.last_comment_gmt, '0000-00-00 00:00:00')) AS last_activity_gmt
                 FROM {$ptt} pt
                 %s
                 WHERE %s
             ) AS scored
             HAVING %s
             ORDER BY %s
             LIMIT %d OFFSET %d",
            $relevance_formula,
            $select_fields,
            $join_sql,
            $where_clause,
            $having_clause,
            $order_clause,
            $effective_limit,
            $offset
        );

        // Raise session-level temp-table limits so the UNION ALL intermediate results fit in
        // memory instead of spilling to disk.  The default (16 MB) is far too small for the
        // union of multiple htax_search_terms scans; 256 MB is restored automatically when
        // the connection closes.  These are session-scoped: no global side effects.
        static $session_limits_set = false;
        if ( ! $session_limits_set ) {
            $wpdb->query( 'SET SESSION tmp_table_size = 268435456' );      // 256 MB
            $wpdb->query( 'SET SESSION max_heap_table_size = 268435456' ); // 256 MB
            $session_limits_set = true;
        }

        // The SQL was built with sprintf() to inject structural parts ($select_fields, etc.)
        // Those parts contain %%s which became %s after sprintf.
        // Now we need to safely inject the actual search terms using wpdb->prepare()
        $prepared_ps_sql = $wpdb->prepare( $sql, ...$query_params );
        $results = $wpdb->get_results( $prepared_ps_sql );

        // Check for database errors — check null return first since last_error is not always populated
        if ( null === $results || $wpdb->last_error ) {
            $error_msg = $wpdb->last_error ?: 'Query returned null';
            // Check if error is due to missing FULLTEXT index
            $is_fulltext_error = stripos( $error_msg, 'fulltext' ) !== false ||
                                 stripos( $error_msg, 'Can\'t find' ) !== false;

            htax_log_error( 'PowerSearch query failed', array(
                'error' => $error_msg,
                'is_fulltext_index_missing' => $is_fulltext_error,
                'suggestion' => $is_fulltext_error ? 'Try deactivating and reactivating the plugin to create FULLTEXT indexes' : 'Check database query syntax'
            ) );
            return array( 'results' => array(), 'total' => 0 );
        }

        // Format results
        $formatted = array();
        foreach ( $results as $result ) {
            if ( ! isset( $result->post_id ) ) {
                htax_log_error( 'PowerSearch result missing post_id', array(
                    'result' => $result,
                    'query'  => $sql,
                ) );
                continue;
            }
            $formatted[] = array(
                'post_id'   => (int) $result->post_id,
                'relevance' => isset( $result->relevance ) ? (float) $result->relevance : 0.0,
            );
        }

        // Skip count when the caller doesn't need pagination totals.
        if ( $skip_count ) {
            $result = array( 'results' => $formatted, 'total' => -1 );
            htax_set_transient( $cache_key, $result, 60 );
            return $result;
        }

        // Count query: same LEFT JOIN structure, no LIMIT.
        $count_sql = sprintf(
            "SELECT COUNT(*) as total
             FROM (
                 SELECT scored.*,
                        %s AS relevance
                 FROM (
                     SELECT pt.post_id,
                            %s
                     FROM {$ptt} pt
                     %s
                     WHERE %s
                 ) AS scored
                 HAVING %s
             ) AS filtered_results",
            $relevance_formula,
            $select_fields,
            $join_sql,
            $where_clause,
            $having_clause
        );

        $total_count = $wpdb->get_var( $wpdb->prepare( $count_sql, ...$query_params ) );

        $total = intval( $total_count );

        $result = array(
            'results' => $formatted,
            'total'   => $total,
        );
        htax_set_transient( $cache_key, $result, 60 );
        return $result;
    }

    /**
     * Wrap a UNION of build_term_search_subqueries() rows into a per-post field score join.
     *
     * Takes the best score per (post, source term) across relationship types, then sums per
     * post: a term reached both directly and through, say, a container scores once, at its
     * strongest, never more than a direct match on it.
     *
     * @param string $union        UNION ALL of (post_id, source_term_id, score) subqueries.
     * @param string $score_column Output column name (e.g. 'tags_score').
     * @param string $alias        Join alias (e.g. 'htax_tags').
     * @return string LEFT JOIN clause.
     */
    private function sum_term_scores_sql( $union, $score_column, $alias ) {
        return "LEFT JOIN (
                    SELECT post_id, SUM(max_score) AS {$score_column}
                    FROM (
                        SELECT post_id, source_term_id, MAX(score) AS max_score
                        FROM ({$union}) AS raw_{$alias} GROUP BY post_id, source_term_id
                    ) AS per_term_{$alias} GROUP BY post_id
                ) AS {$alias} ON {$alias}.post_id = pt.post_id";
    }

    /**
     * Build FULLTEXT search subqueries for a taxonomy (field-specific scoring path).
     *
     * Returns one subquery per relationship type. Each subquery may contain up to three
     * UNION branches depending on configuration:
     *   Branch A (exact):  flat explicit weight for terms whose name exactly equals the search
     *                      string — no FULLTEXT needed, IDs pre-fetched by caller.
     *   Branch B (name):   MATCH(t.name) BOOLEAN MODE * name_weight (exact IDs excluded).
     *   Branch C (desc):   MATCH(tt.description) BOOLEAN MODE * desc_weight (when enabled).
     *
     * Each subquery returns the best score per (post, source_term) for its relationship type —
     * a term reached through several branches or several of the post's terms counts once.
     * Callers wrap the union with sum_term_scores_sql(), which also takes the MAX across
     * relationship types before summing per post.
     *
     * Appends 2 query params per rel_type when name-only, 4 when name+desc.
     * No params added for the exact branch (integer IDs are interpolated directly).
     *
     * @param string   $query            Boolean mode query string (e.g. '+amazon gift')
     * @param string   $taxonomy         Taxonomy slug (e.g., 'post_tag', 'category')
     * @param array    $db_rel_types     Relationship type values to include
     * @param callable $get_term_weights Returns ['name', 'explicit', 'description', 'use_description'] per taxonomy/rel_type
     * @param array    &$query_params    Appended by reference; caller passes to wpdb->prepare()
     * @param int[]    $exact_term_ids   Term IDs that exactly match the search string; get explicit weight.
     * @return array Array of (post_id, source_term_id, score) subquery SQL strings; caller assembles UNION ALL
     */
    private function build_term_search_subqueries( $query, $taxonomy, $db_rel_types, $get_term_weights, &$query_params, array $exact_term_ids = array() ) {
        global $wpdb;
        $subqueries = array();

        // Appended to every hst branch below: visibility plus the strength model,
        // since every branch scores by hst.strength.
        $hst_hidden    = ( htax_can_see_hidden_terms() ? '' : ' AND hst.is_hidden = 0' )
                       . htax_strength_model_sql( 'hst' );
        $esc_tax       = esc_sql( $taxonomy );
        $has_exact     = ! empty( $exact_term_ids );
        $exact_id_list = $has_exact ? implode( ',', $exact_term_ids ) : '';
        $exact_excl    = $has_exact ? " AND t.term_id NOT IN ({$exact_id_list})" : '';

        foreach ( $db_rel_types as $db_rel_type ) {
            $settings_rel_type = ( 'redirect_target' === $db_rel_type ) ? 'redirect' : $db_rel_type;
            $weights           = $get_term_weights( $taxonomy, $settings_rel_type );
            $esc_rel           = esc_sql( $db_rel_type );

            // Branch A: exact match — score by the indexed strength, so posts where
            // the matching term is more central to the post rank higher.
            $exact_branch = $has_exact ? sprintf(
                "
                              SELECT hst.post_id, hst.source_term_id, hst.strength AS score
                              FROM {$wpdb->prefix}htax_search_terms hst
                              WHERE hst.source_term_id IN (%s)
                                AND hst.taxonomy = '%s' AND hst.relationship_type = '%s'%s
                              UNION ALL",
                $exact_id_list,
                $esc_tax, $esc_rel, $hst_hidden
            ) : '';

            if ( $weights['use_description'] ) {
                // Branches A+B+C (or B+C when no exact IDs).
                // MAX per (post, source_term) prevents double-counting across branches.
                // text_weight stays dynamic; strength adds per-post centrality signal.
                $subqueries[] = sprintf(
                    "(SELECT post_id, source_term_id, MAX(score) AS score FROM (%s
                              SELECT hst.post_id, hst.source_term_id,
                                     MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) * %f * hst.strength AS score
                              FROM {$wpdb->terms} t
                              INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = t.term_id
                              WHERE MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) > 0
                                AND hst.taxonomy = '%s' AND hst.relationship_type = '%s'%s%s
                              UNION ALL
                              SELECT hst.post_id, hst.source_term_id,
                                     MATCH(tt.description) AGAINST(%%s IN BOOLEAN MODE) * %f * hst.strength AS score
                              FROM {$wpdb->term_taxonomy} tt
                              INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = tt.term_id
                              WHERE MATCH(tt.description) AGAINST(%%s IN BOOLEAN MODE) > 0
                                AND hst.taxonomy = '%s' AND hst.relationship_type = '%s'%s
                          ) AS branches GROUP BY post_id, source_term_id)",
                    $exact_branch,
                    $weights['name'],
                    $esc_tax, $esc_rel, $hst_hidden, $exact_excl,
                    $weights['description'],
                    $esc_tax, $esc_rel, $hst_hidden
                );
                $query_params[] = $query; // branch B: MATCH(t.name) in SELECT
                $query_params[] = $query; // branch B: MATCH(t.name) in WHERE
                $query_params[] = $query; // branch C: MATCH(tt.description) in SELECT
                $query_params[] = $query; // branch C: MATCH(tt.description) in WHERE
            } elseif ( $has_exact ) {
                // Branches A+B: exact + name FULLTEXT, no description.
                $subqueries[] = sprintf(
                    "(SELECT post_id, source_term_id, MAX(score) AS score FROM (%s
                              SELECT hst.post_id, hst.source_term_id,
                                     MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) * %f * hst.strength AS score
                              FROM {$wpdb->terms} t
                              INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = t.term_id
                              WHERE MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) > 0
                                AND hst.taxonomy = '%s' AND hst.relationship_type = '%s'%s%s
                          ) AS branches GROUP BY post_id, source_term_id)",
                    $exact_branch,
                    $weights['name'],
                    $esc_tax, $esc_rel, $hst_hidden, $exact_excl
                );
                $query_params[] = $query; // branch B: MATCH SELECT
                $query_params[] = $query; // branch B: MATCH WHERE
            } else {
                // Branch B only: name FULLTEXT, no description, no exact IDs.
                $subqueries[] = sprintf(
                    "(SELECT hst.post_id AS post_id, hst.source_term_id,
                             MAX(MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) * %f * hst.strength) AS score
                      FROM {$wpdb->terms} t
                      INNER JOIN {$wpdb->prefix}htax_search_terms hst ON hst.source_term_id = t.term_id
                      WHERE MATCH(t.name) AGAINST(%%s IN BOOLEAN MODE) > 0
                        AND hst.taxonomy = '%s' AND hst.relationship_type = '%s'%s
                      GROUP BY hst.post_id, hst.source_term_id)",
                    $weights['name'],
                    $esc_tax, $esc_rel, $hst_hidden
                );
                $query_params[] = $query;
                $query_params[] = $query;
            }
        }

        return $subqueries;
    }

    /**
     * Parse PowerSearch query into field-specific and general terms
     *
     * Extracts field-specific operators (title:, content:, etc.) and their terms
     * from the query, leaving remaining terms as general search.
     *
     * Supports modifiers:
     * - + (required): Term must be present
     * - - (excluded): Term must NOT be present
     * - No modifier: Term is optional (OR logic)
     *
     * Field operators:
     * - title: Search in post title
     * - content: Search in post content
     * - excerpt: Search in post excerpt
     * - tags: Search in tag names/descriptions
     * - categories: Search in category names/descriptions
     * - terms: Search in all taxonomy term names/descriptions
     * - {taxonomy_plural}: Search in custom taxonomy term names/descriptions (e.g., colors:, locations:)
     *
     * @param string $query Raw search query
     * @return array Array with keys: 'title', 'body', 'excerpt', 'tags', 'categories', 'terms', 'general'
     *               Each value is array of term data: ['term' => string, 'required' => bool, 'excluded' => bool]
     */
    /**
     * Get the list of taxonomies present in the search index.
     *
     * Cached statically for the request lifetime — the set changes only when posts
     * are published or the index is rebuilt, so re-querying on every search is wasteful.
     *
     * @return string[]
     */
    private function get_indexed_taxonomies(): array {
        static $cache = null;
        if ( null === $cache ) {
            global $wpdb;
            $cache = $wpdb->get_col(
                "SELECT DISTINCT taxonomy FROM {$wpdb->prefix}htax_search_terms"
            );
        }
        return $cache;
    }

    private function parse_search_query( string $query, string $near_country = 'US' ): array {
        return htax_parse_search_query( $query, $near_country );
    }
}

// Initialize
HyperTaxonomy_Power_Search::get_instance();
