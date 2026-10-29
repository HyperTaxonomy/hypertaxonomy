<?php
/**
 * Base class for HyperTaxonomy shortcodes
 *
 * Provides common functionality for all HyperTaxonomy shortcodes including:
 * - Redirect term handling
 * - Term filtering (min_count, exclude_redirects, etc.)
 * - Placeholder processing
 * - HTML processing for redirect styling
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Build cache key for post count based on shortcode attributes.
 *
 * Creates a deterministic, order-independent cache key from groups, includes,
 * and excludes. Format: htax_total_{ig…}{it…}{eg…}{et…}_{taxonomy}
 *
 * @since 1.0.0
 * @param string $include_groups Comma-separated group IDs to include.
 * @param string $include_terms  Comma-separated term IDs to include.
 * @param string $exclude_groups Comma-separated group IDs to exclude.
 * @param string $exclude_terms  Comma-separated term IDs to exclude.
 * @param string $taxonomy       Taxonomy name.
 * @return string Cache key.
 */
function htax_build_count_cache_key( $include_groups, $include_terms, $exclude_groups, $exclude_terms, $taxonomy ) {
    // Slugs allowed: group attrs resolve against the 'group' taxonomy, term attrs
    // against the content taxonomy — matching the resolution in resolve_term_ids().
    $groups      = htax_parse_term_ids( $include_groups, 'group' );
    $includes    = htax_parse_term_ids( $include_terms, $taxonomy );
    $excl_groups = htax_parse_term_ids( $exclude_groups, 'group' );
    $excludes    = htax_parse_term_ids( $exclude_terms, $taxonomy );

    sort( $groups,      SORT_NUMERIC );
    sort( $includes,    SORT_NUMERIC );
    sort( $excl_groups, SORT_NUMERIC );
    sort( $excludes,    SORT_NUMERIC );

    $key_parts = array();
    if ( ! empty( $groups ) ) {
        $key_parts[] = 'ig' . implode( '_ig', $groups );
    }
    if ( ! empty( $includes ) ) {
        $key_parts[] = 'it' . implode( '_it', $includes );
    }
    if ( ! empty( $excl_groups ) ) {
        $key_parts[] = 'eg' . implode( '_eg', $excl_groups );
    }
    if ( ! empty( $excludes ) ) {
        $key_parts[] = 'et' . implode( '_et', $excludes );
    }

    return 'htax_total_' . implode( '_', $key_parts ) . '_' . $taxonomy;
}

/**
 * Expand shortcode attributes to a final deduplicated array of term IDs.
 *
 * Applies 4-step inclusion/exclusion logic: include groups → exclude groups →
 * include individual terms → exclude individual terms.
 *
 * @since 1.0.0
 * @param string $include_groups Comma-separated group IDs to include.
 * @param string $include_terms  Comma-separated term IDs to include.
 * @param string $exclude_groups Comma-separated group IDs to exclude.
 * @param string $exclude_terms  Comma-separated term IDs to exclude.
 * @param string $taxonomy       Taxonomy name.
 * @return int[] Deduplicated array of final term IDs.
 */
function htax_expand_shortcode_terms( $include_groups, $include_terms, $exclude_groups, $exclude_terms, $taxonomy ) {
    $include_ids = array();
    $exclude_ids = array();

    if ( ! empty( $include_groups ) ) {
        $group_terms = htax_get_terms_in_groups( htax_parse_term_ids( $include_groups, 'group' ), $taxonomy );
        if ( ! empty( $group_terms ) ) {
            $include_ids = array_keys( $group_terms );
        }
    }

    if ( ! empty( $exclude_groups ) ) {
        $exclude_group_terms = htax_get_terms_in_groups( htax_parse_term_ids( $exclude_groups, 'group' ), $taxonomy );
        if ( ! empty( $exclude_group_terms ) ) {
            $exclude_ids = array_merge( $exclude_ids, array_keys( $exclude_group_terms ) );
        }
    }

    if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
        $include_ids = array_diff( $include_ids, $exclude_ids );
    }

    if ( ! empty( $include_terms ) ) {
        $include_ids = array_unique( array_merge( $include_ids, htax_parse_term_ids( $include_terms, $taxonomy ) ) );
    }

    if ( ! empty( $exclude_terms ) ) {
        $exclude_ids = array_merge( $exclude_ids, htax_parse_term_ids( $exclude_terms, $taxonomy ) );
    }

    if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
        $include_ids = array_diff( $include_ids, $exclude_ids );
    }

    return array_values( $include_ids );
}

/**
 * Get DISTINCT post count for shortcode term/group attributes.
 *
 * Counts unique posts that have ANY of the resolved terms. Uses an
 * order-independent cache key for a higher cache-hit rate.
 *
 * @since 1.0.0
 * @param string $include_groups Comma-separated group IDs to include.
 * @param string $include_terms  Comma-separated term IDs to include.
 * @param string $exclude_groups Comma-separated group IDs to exclude.
 * @param string $exclude_terms  Comma-separated term IDs to exclude.
 * @param string $taxonomy       Taxonomy name.
 * @return int Total count of distinct matching posts.
 */
function htax_get_distinct_post_count_for_shortcode( $include_groups, $include_terms, $exclude_groups, $exclude_terms, $taxonomy ) {
    $cache_key = htax_build_count_cache_key( $include_groups, $include_terms, $exclude_groups, $exclude_terms, $taxonomy );

    $cached = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        return (int) $cached;
    }

    $term_ids = htax_expand_shortcode_terms( $include_groups, $include_terms, $exclude_groups, $exclude_terms, $taxonomy );

    if ( empty( $term_ids ) ) {
        htax_set_transient( $cache_key, 0, HOUR_IN_SECONDS );
        return 0;
    }

    $search_index = htax_get_search_index();
    $count        = count( $search_index->get_posts_with_term_ids( $term_ids ) );

    htax_set_transient( $cache_key, $count, HOUR_IN_SECONDS );

    return $count;
}

abstract class HyperTaxonomy_Shortcode_Base {

    protected $redirect_terms = array();
    protected $htax_atts = array();
    protected $term_anchors = array();
    protected $term_depths = array();

    /**
     * Get default attributes common to all shortcodes
     */
    protected function get_default_atts() {
        return array(
            // Template parameters
            'prepend' => '', // Content before each term
            'append' => '', // Content after each term
            'show_icons' => 'when_available', // Controls {icon} placeholder: 'when_available' = icon only; 'always' = icon or colored placeholder

            // Redirect parameters
            'exclude_redirects' => '0',
            'hide_empty_redirects' => '1',
            'redirect_div_class' => '',
            'italicize_redirects' => 'true',
            'custom_title_for_redirects' => '',
            'custom_title_zero_for_redirects' => '',
            'custom_title_plural_for_redirects' => '',
            'redirect_link_behavior' => 'original', // 'original', 'target', 'anchor', 'none'

            // Filtering parameters
            'min_count' => '0', // Minimum post count to include term

            // Header parameters
            'header' => '', // Optional header text, can use {group_names} placeholder
            'group_name_delimiter' => ', ', // Delimiter for concatenating group names in {group_names} placeholder
            'header_link_behavior' => 'none', // 'none', 'link', or 'toggle' (toggle only for term-list)

            // Styling parameters
            'class'     => '', // Space-separated CSS class names for custom styling
            'div_class' => '', // Additional CSS class for container div
            'div_id'    => '', // CSS ID for container div
        );
    }

    /**
     * Enqueue shortcode styles (called when shortcode is rendered)
     *
     * Checks if styles are disabled in settings and enqueues the stylesheet if needed.
     * Only enqueues once per page load.
     *
     * @since 1.0.0
     */
    protected function enqueue_styles() {
        // Check if styles should be disabled
        $options = get_option( 'htax_settings', array() );
        if ( ! empty( $options['disable_shortcode_styles'] ) ) {
            return;
        }

        // Only enqueue once
        if ( ! wp_style_is( 'htax-shortcodes', 'enqueued' ) && ! wp_style_is( 'htax-shortcodes', 'done' ) ) {
            wp_enqueue_style(
                'htax-shortcodes',
                HTAX_PLUGIN_URL . 'assets/css/htax-shortcodes.css',
                array(),
                HTAX_VERSION
            );
        }
    }

    /**
     * Resolve the final include/exclude term ID sets from shortcode attributes.
     *
     * Implements the standard 4-step filter order:
     *   1. Include groups (hierarchy-expanded)
     *   2. Exclude groups (removes from included set)
     *   3. Include individual terms (overrides group exclusions)
     *   4. Exclude individual terms (final override)
     *
     * @param array  $atts     Shortcode attributes. Reads: include_groups, exclude_groups,
     *                         include_terms, exclude_terms.
     * @param string $taxonomy Taxonomy slug.
     * @return array {
     *     @type int[]  $include_ids              Final set of term IDs to include. Empty = no include filter.
     *     @type int[]  $exclude_ids              Final set of term IDs to exclude.
     *     @type bool   $include_groups_specified Whether include_groups was provided.
     *     @type bool   $include_terms_specified  Whether include_terms was provided.
     * }
     */
    protected function resolve_term_ids( array $atts, string $taxonomy ): array {
        $include_ids              = array();
        $exclude_ids              = array();
        $include_groups_specified = false;
        $include_terms_specified  = false;

        // Step 1: Include groups (with hierarchy expansion)
        if ( ! empty( $atts['include_groups'] ) ) {
            $include_groups_specified = true;
            $group_terms              = htax_get_terms_in_groups( htax_parse_term_ids( $atts['include_groups'], 'group' ), $taxonomy );
            if ( ! empty( $group_terms ) ) {
                $include_ids = array_keys( $group_terms );
            }
        }

        // Step 2: Exclude groups (removes from included set)
        if ( ! empty( $atts['exclude_groups'] ) ) {
            $excl_group_terms = htax_get_terms_in_groups( htax_parse_term_ids( $atts['exclude_groups'], 'group' ), $taxonomy );
            if ( ! empty( $excl_group_terms ) ) {
                $exclude_ids = array_merge( $exclude_ids, array_keys( $excl_group_terms ) );
            }
        }

        if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
            $include_ids = array_diff( $include_ids, $exclude_ids );
        }

        // Step 3: Include individual terms (overrides group exclusions)
        if ( ! empty( $atts['include_terms'] ) ) {
            $include_terms_specified = true;
            $include_ids             = array_unique( array_merge( $include_ids, htax_parse_term_ids( $atts['include_terms'], $taxonomy ) ) );
        }

        // Step 4: Exclude individual terms (final override)
        if ( ! empty( $atts['exclude_terms'] ) ) {
            $exclude_ids = array_merge( $exclude_ids, htax_parse_term_ids( $atts['exclude_terms'], $taxonomy ) );
        }

        if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
            $include_ids = array_diff( $include_ids, $exclude_ids );
        }

        return array(
            'include_ids'              => array_values( array_map( 'intval', $include_ids ) ),
            'exclude_ids'              => array_values( array_map( 'intval', $exclude_ids ) ),
            'include_groups_specified' => $include_groups_specified,
            'include_terms_specified'  => $include_terms_specified,
        );
    }

    /**
     * Load redirect terms for a taxonomy
     */
    protected function load_redirect_terms( $taxonomy ) {
        global $wpdb;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT tr.source_term_id as term_id, tr.target_term_id as redirect_to, t.slug
             FROM {$wpdb->prefix}htax_term_relationships tr
             INNER JOIN {$wpdb->terms} t ON tr.source_term_id = t.term_id
             WHERE tr.relationship_type = 'redirect'
             AND tr.source_taxonomy = %s",
            $taxonomy
        ) );

        if ( htax_handle_db_error( 'load_redirect_terms', array() ) !== false ) {
            return;
        }

        foreach ( $results as $row ) {
            $this->redirect_terms[ $row->term_id ] = array(
                'target_id' => $row->redirect_to,
                'slug' => $row->slug
            );
        }
    }

    /**
     * Check if a term is a redirect term
     *
     * @since 1.0.0
     *
     * @param WP_Term $term Term object to check
     * @return bool True if term is a redirect, false otherwise
     */
    protected function is_term_redirect( $term ) {
        return isset( $this->redirect_terms[ $term->term_id ] );
    }

    /**
     * Get redirect target information for a term
     *
     * Returns an array with target_id and target_term if the term is a redirect and the target exists.
     *
     * @since 1.0.0
     *
     * @param WP_Term $term Term object to get redirect target for
     * @return array|false Array with 'target_id' and 'target_term' keys, or false if not a redirect or target not found
     */
    protected function get_redirect_target( $term ) {
        if ( ! $this->is_term_redirect( $term ) ) {
            return false;
        }

        $target_id = $this->redirect_terms[ $term->term_id ]['target_id'];
        $target_term = htax_get_valid_term( $target_id );

        if ( $target_term ) {
            return array(
                'target_id' => $target_id,
                'target_term' => $target_term
            );
        }

        return false;
    }

    /**
     * Build redirect link attributes based on redirect link behavior setting
     *
     * Returns link tag type ('a' or 'span'), href, and data attributes for redirect terms.
     *
     * @since 1.0.0
     *
     * @param WP_Term $term Term object to build link for
     * @param array &$classes Array of CSS classes (passed by reference, may be modified)
     * @return array Array with 'link_tag', 'link_href', and 'link_attrs' keys
     */
    protected function build_redirect_link_attributes( $term, &$classes ) {
        $result = array(
            'link_tag' => 'a',
            'link_href' => get_term_link( $term ),
            'link_attrs' => ''
        );

        $redirect_target = $this->get_redirect_target( $term );
        if ( ! $redirect_target ) {
            return $result;
        }

        $behavior = $this->htax_atts['redirect_link_behavior'];
        $target_term = $redirect_target['target_term'];
        $target_id = $redirect_target['target_id'];

        switch ( $behavior ) {
            case 'target':
                $result['link_href'] = get_term_link( $target_term );
                break;

            case 'anchor':
                if ( isset( $this->term_anchors[ $target_id ] ) ) {
                    $result['link_href'] = '#' . $this->term_anchors[ $target_id ];
                } else {
                    $result['link_href'] = get_term_link( $target_term );
                }
                break;

            case 'none':
                $result['link_tag'] = 'span';
                $result['link_href'] = '';
                $classes[] = 'htax-redirect-nolink';
                break;

            case 'original':
            default:
                // Keep original term link
                break;
        }

        $result['link_attrs'] .= ' data-htax-redirect="1"';
        $result['link_attrs'] .= ' data-htax-redirect-target-id="' . esc_attr( $target_id ) . '"';
        $result['link_attrs'] .= ' data-htax-redirect-behavior="' . esc_attr( $behavior ) . '"';

        return $result;
    }

    /**
     * Sanitize and parse class parameter into array of CSS class names
     *
     * Takes a space-separated string of CSS class names and returns a sanitized array.
     * Each class is sanitized using WordPress sanitize_html_class() function.
     *
     * @since 1.0.0
     *
     * @param string $class_string Space-separated CSS class names
     * @return array Array of sanitized CSS class names
     */
    protected function sanitize_class_parameter( $class_string ) {
        if ( empty( $class_string ) ) {
            return array();
        }

        $classes = explode( ' ', $class_string );
        $sanitized = array();

        foreach ( $classes as $class ) {
            $class = trim( $class );
            if ( ! empty( $class ) ) {
                $sanitized_class = sanitize_html_class( $class );
                if ( ! empty( $sanitized_class ) ) {
                    $sanitized[] = $sanitized_class;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Build container CSS classes array
     *
     * Builds an array of CSS classes for shortcode containers, including base class,
     * optional column class, custom div_class, and class parameter classes.
     *
     * @since 1.0.0
     *
     * @param string $base_class Base CSS class name (e.g., 'htax-term-list')
     * @param bool $include_columns Whether to include column classes (default: true)
     * @return array Array of CSS class names
     */
    protected function build_container_classes( $base_class, $include_columns = true ) {
        $classes = array( $base_class );

        if ( $include_columns && isset( $this->htax_atts['columns'] ) ) {
            $columns = intval( $this->htax_atts['columns'] );
            if ( $columns > 1 && $columns <= 4 ) {
                $classes[] = 'htax-columns-' . $columns;
            }
        }

        if ( ! empty( $this->htax_atts['div_class'] ) ) {
            $classes[] = $this->htax_atts['div_class'];
        }

        // Add class parameter classes (space-separated custom CSS classes)
        if ( ! empty( $this->htax_atts['class'] ) ) {
            $custom_classes = $this->sanitize_class_parameter( $this->htax_atts['class'] );
            $classes = array_merge( $classes, $custom_classes );
        }

        return $classes;
    }

    /**
     * Build the id attribute string for a container element.
     *
     * Returns a space-prefixed ` id="..."` string when div_id is set, or an
     * empty string when it is not — suitable for direct concatenation into HTML.
     *
     * @since 1.0.0
     *
     * @return string ` id="..."` or empty string.
     */
    protected function build_container_id_attr() {
        if ( empty( $this->htax_atts['div_id'] ) ) {
            return '';
        }
        return ' id="' . esc_attr( $this->htax_atts['div_id'] ) . '"';
    }

    /**
     * Generate anchor from term slug
     */
    protected function generate_term_anchor( $term ) {
        return 'htax-' . sanitize_title( $term->slug );
    }

    /**
     * Check if term should be excluded based on filter criteria
     */
    protected function should_exclude_term( $term ) {
        // Always exclude hidden terms from frontend term lists
        $is_hidden = get_term_meta( $term->term_id, '_htax_is_hidden', true );
        if ( '1' === $is_hidden ) {
            return true;
        }

        $is_redirect = isset( $this->redirect_terms[ $term->term_id ] );

        // Exclude all redirects if requested
        if ( $this->htax_atts['exclude_redirects'] === '1' && $is_redirect ) {
            return true;
        }

        // Exclude empty redirects if requested
        if ( $this->htax_atts['hide_empty_redirects'] === '1' && $is_redirect && $term->count == 0 ) {
            return true;
        }

        // Check minimum count
        $min_count = intval( $this->htax_atts['min_count'] );
        if ( $min_count > 0 && $term->count < $min_count ) {
            return true;
        }

        return false;
    }

    /**
     * Filter terms array based on exclusion criteria
     *
     * @param array $terms Array of term objects
     * @return array Filtered array of terms
     */
    protected function filter_terms( $terms ) {
        if ( empty( $terms ) ) {
            return $terms;
        }

        return array_filter( $terms, function( $term ) {
            return ! $this->should_exclude_term( $term );
        } );
    }

    /**
     * Process header placeholders
     *
     * Processes placeholders in header text that need information about all displayed terms.
     * Supports: {term_count}, {total_count}, {group_names}
     *
     * @param string $header_text Header text with placeholders
     * @param array $terms Array of term objects that will be displayed
     * @param string $taxonomy Taxonomy name
     * @return string Processed header text with placeholders replaced
     */
    protected function process_header_placeholders( $header_text, $terms, $taxonomy ) {
        if ( empty( $header_text ) ) {
            return '';
        }

        // Calculate values only if the placeholder is present (for performance)
        $replacements = array();

        // {term_count} - Simple count of terms being displayed
        if ( strpos( $header_text, '{term_count}' ) !== false ) {
            $replacements['{term_count}'] = count( $terms );
        }

        // {total_count} - DISTINCT post count based on shortcode attributes
        // Uses order-independent cache key for better cache hit rate
        if ( strpos( $header_text, '{total_count}' ) !== false ) {
            $replacements['{total_count}'] = htax_get_distinct_post_count_for_shortcode(
                $this->htax_atts['include_groups'] ?? '',
                $this->htax_atts['include_terms'] ?? '',
                $this->htax_atts['exclude_groups'] ?? '',
                $this->htax_atts['exclude_terms'] ?? '',
                $taxonomy
            );
        }

        // {group_names} - Comma-separated list of group names (plain text or links)
        if ( strpos( $header_text, '{group_names}' ) !== false && ! empty( $this->htax_atts['include_groups'] ) ) {
            $group_ids = htax_parse_term_ids( $this->htax_atts['include_groups'], 'group' );
            $group_items = array();
            $use_links = ( isset( $this->htax_atts['header_link_behavior'] ) &&
                           $this->htax_atts['header_link_behavior'] === 'link' );

            foreach ( $group_ids as $group_id ) {
                $group = htax_get_valid_term( $group_id );
                if ( $group ) {
                    if ( $use_links ) {
                        // Generate link to group archive
                        $url = get_term_link( $group, 'group' );
                        if ( ! is_wp_error( $url ) ) {
                            $group_items[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $group->name ) . '</a>';
                        } else {
                            // Fallback to plain text if link generation fails
                            $group_items[] = esc_html( $group->name );
                        }
                    } else {
                        // Plain text (default and 'toggle' mode)
                        $group_items[] = esc_html( $group->name );
                    }
                }
            }
            $delimiter = esc_html( $this->htax_atts['group_name_delimiter'] ?? ', ' );
            $replacements['{group_names}'] = implode( $delimiter, $group_items );
        }

        // Apply replacements
        return strtr( $header_text, $replacements );
    }

    /**
     * Process placeholders in text
     */
    protected function process_placeholders( $text, $term, $depth = 0 ) {
        $is_redirect = isset( $this->redirect_terms[ $term->term_id ] );

        $term_url = get_term_link( $term );

        $replacements = array(
            '{id}'                     => $term->term_id,
            '{name}'                   => esc_html( $term->name ),
            '{url}'                    => ! is_wp_error( $term_url ) ? esc_url( $term_url ) : '',
            '{slug}'                   => esc_html( $term->slug ),
            '{description}'            => wp_kses_post( $term->description ),
            '{count}'                  => $term->count,
            '{depth_level}'            => $depth,
            '{is_redirect}'            => $is_redirect ? '1' : '0',
            '{target}'                 => '',
            '{redirect_target_link}'   => '',
            '{redirect_target_anchor}' => '',
            '{icon}'                      => $this->resolve_icon_html( $term ),
            '{icon_src}'                  => esc_url( (string) htax_get_term_icon_url( $term, 'htax-icon-small' ) ),
            '{icon_src_medium}'           => esc_url( (string) htax_get_term_icon_url( $term, 'htax-icon-medium' ) ),
            '{icon_src_large}'            => esc_url( (string) htax_get_term_icon_url( $term, 'htax-icon-large' ) ),
            '{icon_width}'                => '',
            '{icon_height}'               => '',
            '{icon_width_medium}'         => '',
            '{icon_height_medium}'        => '',
            '{icon_width_large}'          => '',
            '{icon_height_large}'         => '',
        );

        // Icon dimensions are taxonomy-configured, not per-attachment — free from settings cache.
        $small  = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, 'small' );
        $medium = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, 'medium' );
        $large  = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, 'large' );
        $replacements['{icon_width}']         = $small['width'];
        $replacements['{icon_height}']        = $small['height'];
        $replacements['{icon_width_medium}']  = $medium['width'];
        $replacements['{icon_height_medium}'] = $medium['height'];
        $replacements['{icon_width_large}']   = $large['width'];
        $replacements['{icon_height_large}']  = $large['height'];

        if ( $is_redirect ) {
            $target_id = $this->redirect_terms[ $term->term_id ]['target_id'];
            $target_term = get_term( $target_id, $term->taxonomy );

            if ( $target_term && ! is_wp_error( $target_term ) ) {
                $replacements['{target}']                 = esc_html( $target_term->name );
                $replacements['{redirect_target_link}']   = get_term_link( $target_term );
                $replacements['{redirect_target_anchor}'] = $this->generate_term_anchor( $target_term );
            }
        }

        $text = strtr( $text, $replacements );

        // Process conditional logic
        $pattern = '/\{is_redirect\?([^:}]*):([^}]*)\}/';
        $replacement = $is_redirect ? '$1' : '$2';
        $text = preg_replace( $pattern, $replacement, $text );

        return wp_kses_post( $text );
    }

    /**
     * Build CSS class array for a term item, including redirect-specific classes.
     *
     * @param object $term       Term object.
     * @param string $base_class The shortcode-specific base CSS class (e.g. 'htax-cloud-term').
     * @return array CSS class names.
     */
    protected function build_term_item_classes( $term, string $base_class ): array {
        $classes = array( $base_class );
        if ( $this->is_term_redirect( $term ) ) {
            $classes[] = 'htax-redirect-term';
            if ( ! empty( $this->htax_atts['redirect_div_class'] ) ) {
                $classes[] = $this->htax_atts['redirect_div_class'];
            }
        }
        return $classes;
    }

    /**
     * Build inline CSS styles array for a term item.
     *
     * @param object $term         Term object.
     * @param array  $extra_styles Additional styles to include first (e.g. font-size for clouds).
     * @return array CSS declarations.
     */
    protected function build_term_item_styles( $term, array $extra_styles = array() ): array {
        return $extra_styles;
    }

    /**
     * Return the escaped term name, wrapped in <em> if the term is a redirect and
     * italicize_redirects is enabled. Use this instead of bare esc_html($term->name)
     * so that only the name is italicised, not surrounding prepend/append/count content.
     */
    protected function build_term_name_html( $term ): string {
        $name = esc_html( $term->name );
        if ( $this->is_term_redirect( $term ) && 'true' === $this->htax_atts['italicize_redirects'] ) {
            return '<em>' . $name . '</em>';
        }
        return $name;
    }

    /**
     * Wrap $html in a font-style:normal span when the current term's name is italicised,
     * so append/count content rendered inside the link element cannot inherit italic from
     * any source (outer container, theme CSS, or browser rendering context after <em>).
     * Returns $html unchanged when italicize_redirects is off or the term is not a redirect.
     *
     * @param object $term Term object.
     * @param string $html HTML string to conditionally wrap.
     * @return string
     */
    protected function no_italic( $term, string $html ): string {
        if ( '' === $html ) {
            return '';
        }
        if ( $this->is_term_redirect( $term ) && 'true' === $this->htax_atts['italicize_redirects'] ) {
            return '<span style="font-style:normal">' . $html . '</span>';
        }
        return $html;
    }

    /**
     * Get title attribute for redirect term
     */
    protected function get_redirect_title( $term ) {
        if ( ! isset( $this->redirect_terms[ $term->term_id ] ) ) {
            return '';
        }

        $count = $term->count;

        if ( 0 === $count && ! empty( $this->htax_atts['custom_title_zero_for_redirects'] ) ) {
            return $this->htax_atts['custom_title_zero_for_redirects'];
        } elseif ( 1 === $count && ! empty( $this->htax_atts['custom_title_for_redirects'] ) ) {
            return str_replace( '{count}', $count, $this->htax_atts['custom_title_for_redirects'] );
        } elseif ( $count > 1 && ! empty( $this->htax_atts['custom_title_plural_for_redirects'] ) ) {
            return str_replace( '{count}', $count, $this->htax_atts['custom_title_plural_for_redirects'] );
        }

        return '';
    }

    /**
     * Resolve icon HTML for the {icon} placeholder.
     *
     * Returns the uploaded icon <img> when one is set. When show_icons='always'
     * and no icon is set, returns a colored placeholder <span> using the same
     * taxonomy-configured dimensions as the real icon. Returns '' otherwise.
     *
     * @param WP_Term $term Term object.
     * @return string Icon or placeholder HTML, or empty string.
     */
    protected function resolve_icon_html( $term ) {
        $icon_html = htax_get_term_icon_html( $term );
        if ( $icon_html ) {
            return $icon_html;
        }

        $show_icons = isset( $this->htax_atts['show_icons'] ) ? $this->htax_atts['show_icons'] : 'when_available';
        if ( 'always' === $show_icons ) {
            $placeholder_color = HyperTaxonomy_Term_Icons::get_color_for_slug( $term->slug );
            $dimensions        = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, 'small' );
            return htax_get_placeholder_icon_html( $placeholder_color, $dimensions, $term->taxonomy );
        }

        return '';
    }

    /**
     * Abstract method that each shortcode must implement
     */
    abstract public function shortcode_handler( $atts );
}
