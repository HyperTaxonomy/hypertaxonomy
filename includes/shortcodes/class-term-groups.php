<?php
/**
 * HyperTaxonomy Term Groups Shortcode
 *
 * Displays terms organized by groups in a collapsible tree structure.
 * Similar to a directory/file browser with groups as folders and terms as files.
 * Uses native HTML5 <details>/<summary> for collapsible sections (no JavaScript required).
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_Term_Groups extends HyperTaxonomy_Shortcode_Base {

    private $processed_terms = array();
    private $group_terms_cache = array();

    public function __construct() {
        add_shortcode( 'ht_term_groups', array( $this, 'shortcode_handler' ) );
    }

    protected function get_default_atts() {
        return array_merge( parent::get_default_atts(), array(
            // Display parameters
            'taxonomies' => 'post_tag', // Comma-separated list of taxonomies to display
            'hide_empty' => '0',

            // Group parameters
            'include_groups' => '', // Root-level groups (comma-separated IDs, empty = all root groups)
            'exclude_groups' => '', // Groups to exclude

            // Tree structure parameters
            'collapsible' => 'true', // 'false', 'true' (expanded), or 'collapsed'
            'auto_collapse' => 'false', // Auto-collapse siblings when expanding a group
            'show_empty_groups' => 'true', // Show groups even if they have no terms

            // Display options
            'show_group_count' => 'true', // Show term count for each group
            'show_term_count' => 'true', // Show post count for each term
            'group_icon' => '📁', // Icon for groups (can be emoji or HTML)
            'term_icon' => '📄', // Icon for terms — emoji or HTML; used as fallback when show_term_icons='auto'
            'show_term_icons' => 'never', // 'never' = always use term_icon text; 'auto' = use uploaded HT icon, fall back to term_icon

        ) );
    }

    /**
     * Main shortcode handler
     */
    public function shortcode_handler( $atts ) {
        // Enqueue styles
        $this->enqueue_styles();

        // Check if Term Groups taxonomy is registered
        if ( ! taxonomy_exists( 'group' ) ) {
            return '<div class="htax-error">' .
                   __( 'The Term Groups taxonomy is not registered.', 'hypertaxonomy' ) .
                   '</div>';
        }

        // Extract attributes
        $this->htax_atts = shortcode_atts( $this->get_default_atts(), $atts );

        // Parse taxonomies
        $taxonomies = array_map( 'trim', explode( ',', $this->htax_atts['taxonomies'] ) );
        $taxonomies = array_filter( $taxonomies );

        if ( empty( $taxonomies ) ) {
            return '<div class="htax-error">' .
                   __( 'No valid taxonomies specified.', 'hypertaxonomy' ) .
                   '</div>';
        }

        // Load redirect terms for all taxonomies
        foreach ( $taxonomies as $taxonomy ) {
            $this->load_redirect_terms( $taxonomy );
        }

        // Get root groups
        $root_groups = $this->get_root_groups();

        if ( empty( $root_groups ) ) {
            return '';
        }

        // Render the tree
        return $this->render_tree( $root_groups, $taxonomies );
    }

    /**
     * Get root-level groups to display
     */
    private function get_root_groups() {
        $args = array(
            'taxonomy' => 'group',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        );

        // Handle include_groups parameter
        if ( ! empty( $this->htax_atts['include_groups'] ) ) {
            $include_ids = htax_parse_term_ids( $this->htax_atts['include_groups'], 'group' );
            $args['include'] = $include_ids;
        } else {
            // Get only root-level groups (parent = 0)
            $args['parent'] = 0;
        }

        // Handle exclude_groups parameter
        if ( ! empty( $this->htax_atts['exclude_groups'] ) ) {
            $exclude_ids = htax_parse_term_ids( $this->htax_atts['exclude_groups'], 'group' );
            $args['exclude'] = $exclude_ids;
        }

        $groups = get_terms( $args );

        if ( is_wp_error( $groups ) ) {
            htax_log_error( 'Failed to get groups for term_groups shortcode', $groups->get_error_message() );
            return array();
        }

        return $groups;
    }

    /**
     * Get child groups for a parent group
     */
    private function get_child_groups( $parent_id ) {
        $args = array(
            'taxonomy' => 'group',
            'parent' => $parent_id,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        );

        // Handle exclude_groups parameter
        if ( ! empty( $this->htax_atts['exclude_groups'] ) ) {
            $exclude_ids = htax_parse_term_ids( $this->htax_atts['exclude_groups'], 'group' );
            $args['exclude'] = $exclude_ids;
        }

        $groups = get_terms( $args );

        if ( is_wp_error( $groups ) ) {
            return array();
        }

        return $groups;
    }

    /**
     * Get terms for a specific group across specified taxonomies
     */
    private function get_group_terms( $group_id, $taxonomies ) {
        // Check cache
        $cache_key = $group_id . '_' . implode( '_', $taxonomies );
        if ( isset( $this->group_terms_cache[ $cache_key ] ) ) {
            return $this->group_terms_cache[ $cache_key ];
        }

        // Collect all term IDs from all taxonomies using the core function
        $all_term_ids = array();
        foreach ( $taxonomies as $taxonomy ) {
            // Use core function which handles hierarchy expansion automatically
            $term_objects = htax_get_terms_in_groups( array( $group_id ), $taxonomy );
            if ( ! empty( $term_objects ) ) {
                $all_term_ids = array_merge( $all_term_ids, array_keys( $term_objects ) );
            }
        }

        // Remove duplicates
        $all_term_ids = array_unique( $all_term_ids );

        if ( empty( $all_term_ids ) ) {
            $this->group_terms_cache[ $cache_key ] = array();
            return array();
        }

        // Get term objects for these IDs, filtered by taxonomies (always alphabetical)
        $args = array(
            'taxonomy' => $taxonomies,
            'include' => $all_term_ids,
            'hide_empty' => $this->htax_atts['hide_empty'] === '1',
            'orderby' => 'name',
            'order' => 'ASC',
        );

        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            $this->group_terms_cache[ $cache_key ] = array();
            return array();
        }

        // Filter terms
        $terms = $this->filter_terms( $terms );

        $this->group_terms_cache[ $cache_key ] = $terms;
        return $terms;
    }

    /**
     * Render the complete tree
     */
    private function render_tree( $root_groups, $taxonomies ) {
        // Build container classes
        $classes = $this->build_container_classes( 'htax-term-groups', false );

        // Add auto-collapse class if enabled
        if ( $this->htax_atts['auto_collapse'] === 'true' ) {
            $classes[] = 'htax-auto-collapse';
        }

        // Start output
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $this->build_container_id_attr() . '>';

        // Determine initial state for root groups
        $is_root_open = ( $this->htax_atts['collapsible'] === 'true' );

        foreach ( $root_groups as $group ) {
            $output .= $this->render_group( $group, $taxonomies, 0, $is_root_open );
        }

        $output .= '</div>';

        // Enqueue JavaScript if auto_collapse is enabled
        if ( $this->htax_atts['auto_collapse'] === 'true' && $this->htax_atts['collapsible'] === 'true' ) {
            $this->enqueue_auto_collapse_script();
        }

        return $output;
    }

    /**
     * Render a single group with its terms and child groups
     */
    private function render_group( $group, $taxonomies, $depth, $is_open = false ) {
        // Get terms for this group
        $terms = $this->get_group_terms( $group->term_id, $taxonomies );

        // Get child groups
        $child_groups = $this->get_child_groups( $group->term_id );

        // Check if we should show this group
        $has_content = ! empty( $terms ) || ! empty( $child_groups );
        if ( ! $has_content && 'true' !== $this->htax_atts['show_empty_groups'] ) {
            return '';
        }

        $output = '';
        $is_collapsible = in_array( $this->htax_atts['collapsible'], array( 'true', 'collapsed' ), true );

        if ( $is_collapsible ) {
            // Use <details> for collapsible sections
            $output .= '<details class="htax-group htax-group-depth-' . $depth . '"';
            if ( $is_open ) {
                $output .= ' open';
            }
            $output .= '>';

            // Summary (group header)
            $output .= '<summary class="htax-group-header">';
            if ( ! empty( $this->htax_atts['group_icon'] ) ) {
                $output .= '<span class="htax-group-icon">' . wp_kses_post( $this->htax_atts['group_icon'] ) . '</span> ';
            }
            $output .= '<span class="htax-group-name">' . esc_html( $group->name ) . '</span>';

            if ( $this->htax_atts['show_group_count'] === 'true' ) {
                $term_count = count( $terms );
                $output .= ' <span class="htax-group-count">(' . $term_count . ')</span>';
            }

            $output .= '</summary>';

            // Group content
            $output .= '<div class="htax-group-content">';
        } else {
            // Non-collapsible display
            $output .= '<div class="htax-group htax-group-depth-' . $depth . '">';
            $output .= '<div class="htax-group-header">';
            if ( ! empty( $this->htax_atts['group_icon'] ) ) {
                $output .= '<span class="htax-group-icon">' . wp_kses_post( $this->htax_atts['group_icon'] ) . '</span> ';
            }
            $output .= '<span class="htax-group-name">' . esc_html( $group->name ) . '</span>';

            if ( $this->htax_atts['show_group_count'] === 'true' ) {
                $term_count = count( $terms );
                $output .= ' <span class="htax-group-count">(' . $term_count . ')</span>';
            }

            $output .= '</div>';
            $output .= '<div class="htax-group-content">';
        }

        // Render child groups first (folders before files)
        // When auto_collapse is enabled with collapsible=true, subgroups start collapsed
        $child_is_open = false;
        if ( 0 === $depth && 'true' === $this->htax_atts['collapsible'] && 'false' === $this->htax_atts['auto_collapse'] ) {
            // Root level with auto_collapse=false: children inherit open state
            $child_is_open = $is_open;
        }

        foreach ( $child_groups as $child_group ) {
            $output .= $this->render_group( $child_group, $taxonomies, $depth + 1, $child_is_open );
        }

        // Render terms
        if ( ! empty( $terms ) ) {
            $output .= '<div class="htax-group-terms">';
            foreach ( $terms as $term ) {
                if ( ! isset( $this->processed_terms[ $term->term_id ] ) ) {
                    $this->processed_terms[ $term->term_id ] = true;
                    $output .= $this->render_term( $term, $depth );
                }
            }
            $output .= '</div>';
        }

        $output .= '</div>'; // Close htax-group-content

        if ( $is_collapsible ) {
            $output .= '</details>';
        } else {
            $output .= '</div>'; // Close htax-group
        }

        return $output;
    }

    /**
     * Render a single term
     */
    private function render_term( $term, $depth ) {
        $classes    = $this->build_term_item_classes( $term, 'htax-term-item' );
        $styles     = $this->build_term_item_styles( $term );
        $title_attr = $this->get_redirect_title( $term );

        // Determine link behavior for redirects
        $link_attrs_result = $this->build_redirect_link_attributes( $term, $classes );
        $link_tag = $link_attrs_result['link_tag'];
        $link_href = $link_attrs_result['link_href'];
        $link_attrs = $link_attrs_result['link_attrs'];

        // Process prepend/append content
        $prepend_content = ! empty( $this->htax_atts['prepend'] )
            ? $this->process_placeholders( $this->htax_atts['prepend'], $term, $depth )
            : '';
        $append_content = ! empty( $this->htax_atts['append'] )
            ? $this->process_placeholders( $this->htax_atts['append'], $term, $depth )
            : '';

        // Build output
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"';
        if ( ! empty( $styles ) ) {
            $output .= ' style="' . esc_attr( implode( '; ', $styles ) ) . '"';
        }
        $output .= '>';

        // Add prepend content
        if ( ! empty( $prepend_content ) ) {
            $output .= $prepend_content;
        }

        // Add term icon: when show_term_icons='auto', the uploaded HT icon takes priority;
        // if no HT icon is set for the term, fall back to the term_icon text (emoji/HTML).
        if ( 'auto' === $this->htax_atts['show_term_icons'] ) {
            $ht_icon_html = htax_get_term_icon_html( $term );
            if ( $ht_icon_html ) {
                $output .= $ht_icon_html . ' ';
            } elseif ( ! empty( $this->htax_atts['term_icon'] ) ) {
                $output .= '<span class="htax-term-icon">' . wp_kses_post( $this->htax_atts['term_icon'] ) . '</span> ';
            }
        } elseif ( ! empty( $this->htax_atts['term_icon'] ) ) {
            $output .= '<span class="htax-term-icon">' . wp_kses_post( $this->htax_atts['term_icon'] ) . '</span> ';
        }

        // Add the term link or span
        $output .= '<' . $link_tag;
        if ( 'a' === $link_tag ) {
            $output .= ' href="' . esc_url( $link_href ) . '"';
        }
        if ( ! empty( $title_attr ) ) {
            $output .= ' title="' . esc_attr( $title_attr ) . '"';
        }
        if ( ! empty( $link_attrs ) ) {
            $output .= $link_attrs;
        }
        $output .= ' class="htax-term-link">';
        $output .= $this->build_term_name_html( $term );

        if ( $this->htax_atts['show_term_count'] === 'true' ) {
            $output .= $this->no_italic( $term, ' <span class="htax-term-count">(' . $term->count . ')</span>' );
        }

        if ( ! empty( $append_content ) ) {
            $output .= $this->no_italic( $term, $append_content );
        }

        $output .= '</' . $link_tag . '>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Enqueue auto-collapse JavaScript
     *
     * Adds inline script to handle auto-collapsing of sibling groups when one is expanded.
     * Only siblings at the same depth level are collapsed.
     */
    private function enqueue_auto_collapse_script() {
        static $enqueued = false;

        if ( $enqueued ) {
            return;
        }

        $script = "
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                // Find all auto-collapse containers
                var containers = document.querySelectorAll('.htax-term-groups.htax-auto-collapse');

                containers.forEach(function(container) {
                    // Add toggle event listener to all details elements
                    var allDetails = container.querySelectorAll('details.htax-group');

                    allDetails.forEach(function(details) {
                        details.addEventListener('toggle', function(e) {
                            // Only act when opening (not closing)
                            if (!this.open) {
                                return;
                            }

                            // Find parent container to determine siblings
                            var parent = this.parentElement;

                            // Get all sibling details at the same level
                            var siblings = Array.from(parent.children).filter(function(child) {
                                return child !== e.target &&
                                       child.tagName === 'DETAILS' &&
                                       child.classList.contains('htax-group');
                            });

                            // Close all siblings
                            siblings.forEach(function(sibling) {
                                sibling.removeAttribute('open');
                            });
                        });
                    });
                });
            });
        })();
        ";

        // 'htax-shortcodes' is registered as a stylesheet only, so attach the inline JS to a
        // real (src-less) script handle, which WordPress prints in the footer.
        if ( ! wp_script_is( 'htax-term-groups-collapse', 'registered' ) ) {
            wp_register_script( 'htax-term-groups-collapse', '', array(), HTAX_VERSION, true );
        }
        wp_enqueue_script( 'htax-term-groups-collapse' );
        wp_add_inline_script( 'htax-term-groups-collapse', $script );
        $enqueued = true;
    }

}

// Initialize class
new HyperTaxonomy_Term_Groups();
