<?php
/**
 * HyperTaxonomy Term List Shortcode
 *
 * Displays a list of terms with support for:
 * - Hierarchical taxonomy display with depth control
 * - Term Groups filtering
 * - Redirect term handling
 * - Customizable templates and styling
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_Term_List extends HyperTaxonomy_Term_Display_Base {

    /** @var array Pre-fetched map of parent_term_id => WP_Term[] children */
    private $children_map = array();

    public function __construct() {
        add_shortcode( 'ht_term_list', array( $this, 'shortcode_handler' ) );
    }

    protected function get_default_atts() {
        return array_merge( parent::get_default_atts(), array(
            // Sorting parameters (only term-list supports user-controlled sorting)
            'orderby' => 'name',
            'order' => 'ASC',

            // Depth control parameters (only term-list implements hierarchical rendering)
            'max_depth' => '-1', // for hierarchical taxonomy display (-1 = use setting, 0-99 = limit)

            // Term list specific parameters
            'indent_style' => 'padding', // 'padding', 'dash', or 'none'
            'indent_size' => '20', // Pixels for padding indent
            'generate_anchors' => 'true',
            'columns' => '1', // Number of columns (1-4) for group content display
            'font_size' => '12', // Font size in pixels (Tag Groups default)

            // Collapsible container parameters
            'collapsible' => 'false', // 'false', 'true' (expanded), or 'collapsed'

            // Template parameters
            'separator' => '', // HTML between terms (only for non-hierarchical display)
        ) );
    }

    /**
     * Main shortcode handler
     */
    public function shortcode_handler( $atts ) {
        // Enqueue styles
        $this->enqueue_styles();

        // Extract HyperTaxonomy specific attributes
        $this->htax_atts = shortcode_atts( $this->get_default_atts(), $atts );

        // Default header_link_behavior to 'toggle' when collapsible is enabled
        $is_collapsible = in_array( $this->htax_atts['collapsible'], array( 'true', 'collapsed' ), true );
        if ( $is_collapsible && ! isset( $atts['header_link_behavior'] ) ) {
            $this->htax_atts['header_link_behavior'] = 'toggle';
        }

        // Apply global settings for max_depth if set to -1
        if ( intval( $this->htax_atts['max_depth'] ) === -1 ) {
            $options = get_option( 'htax_settings', array() );
            $this->htax_atts['max_depth'] = intval( $options['max_depth'] ?? -1 );
        }

        // Convert string to int for max_depth (allow negative for unlimited)
        $this->htax_atts['max_depth'] = intval( $this->htax_atts['max_depth'] );

        // Get taxonomy
        $taxonomy = $this->htax_atts['taxonomy'];

        // Load redirect terms
        $this->load_redirect_terms( $taxonomy );

        // Custom rendering for hierarchical taxonomies with max_depth
        // Render hierarchically if depth is specified (0 or positive)
        if ( $this->htax_atts['max_depth'] >= 0 && is_taxonomy_hierarchical( $taxonomy ) ) {
            return $this->render_hierarchical_list( $atts );
        }

        // Standard flat list rendering
        return $this->render_flat_list( $atts );
    }

    /**
     * Fetch and filter terms for the current taxonomy, applying orderby/order settings.
     *
     * @param string $taxonomy Taxonomy slug.
     * @return WP_Term[] Filtered term objects, or empty array.
     */
    private function get_ordered_filtered_terms( $taxonomy ) {
        $terms = parent::get_terms( $taxonomy, array(
            'orderby' => $this->htax_atts['orderby'],
            'order'   => $this->htax_atts['order'],
        ) );
        return $this->filter_terms( $terms );
    }

    /**
     * Render flat (non-hierarchical) term list
     */
    private function render_flat_list( $atts ) {
        $taxonomy = $this->htax_atts['taxonomy'];

        $terms = $this->get_ordered_filtered_terms( $taxonomy );

        if ( empty( $terms ) ) {
            return '';
        }

        // Check if collapsible
        $is_collapsible = in_array( $this->htax_atts['collapsible'], array( 'true', 'collapsed' ), true );

        // Build container classes
        $classes = $this->build_container_classes( 'htax-term-list' );
        $classes[] = 'htax-flat';

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $this->build_container_id_attr() . '>';

        // Show header if header parameter is specified (but not if collapsible, handled by summary)
        if ( ! $is_collapsible && ! empty( $this->htax_atts['header'] ) ) {
            $header_text = $this->process_header_placeholders( $this->htax_atts['header'], $terms, $taxonomy );
            $output .= '<h3>' . wp_kses_post( $header_text ) . '</h3>';
        }

        // Render terms
        $output .= '<div class="htax-term-list-tags">';
        $separator = wp_kses_post( $this->htax_atts['separator'] );
        $first = true;

        foreach ( $terms as $term ) {
            if ( ! $first && ! empty( $separator ) ) {
                $output .= $separator;
            }
            $first = false;

            $output .= $this->render_single_term( $term, 0 );
        }

        $output .= '</div>';

        $output .= '</div>';

        // Wrap with collapsible details/summary if needed
        return $this->wrap_collapsible( $output, $terms, $taxonomy );
    }

    /**
     * Fetch all terms for a taxonomy and group them by parent ID.
     * Stored in $this->children_map to avoid per-term queries in add_children().
     */
    private function build_children_map( string $taxonomy ): void {
        $this->children_map = array();

        $all_terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => $this->htax_atts['hide_empty'] === '1',
        ) );

        if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
            return;
        }

        foreach ( $all_terms as $term ) {
            $this->children_map[ (int) $term->parent ][] = $term;
        }
    }

    /**
     * Render hierarchical term list with subcategories
     */
    private function render_hierarchical_list( $atts ) {
        $taxonomy = $this->htax_atts['taxonomy'];

        $terms = $this->get_ordered_filtered_terms( $taxonomy );

        if ( empty( $terms ) ) {
            return '';
        }

        // Pre-fetch all children in one query to avoid N+1 inside add_children()
        if ( $this->htax_atts['max_depth'] > 0 ) {
            $this->build_children_map( $taxonomy );
        }

        // Build hierarchical structure
        $hierarchical_terms = $this->build_hierarchy( $terms, $taxonomy );

        // Render the list
        $output = $this->render_hierarchy( $hierarchical_terms, $terms, $taxonomy );

        // Wrap with collapsible details/summary if needed
        return $this->wrap_collapsible( $output, $terms, $taxonomy );
    }


    /**
     * Build hierarchical structure including subcategories
     */
    private function build_hierarchy( $terms, $taxonomy ) {
        $hierarchical = array();

        foreach ( $terms as $term ) {
            $this->term_depths[ $term->term_id ] = 0;

            // Add the term itself
            $hierarchical[ $term->term_id ] = array(
                'term' => $term,
                'depth' => 0,
                'children' => array()
            );

            // Get children if max_depth allows
            if ( $this->htax_atts['max_depth'] > 0 ) {
                $this->add_children( $hierarchical[ $term->term_id ], $taxonomy, 1 );
            }
        }

        return $hierarchical;
    }

    /**
     * Recursively add children to hierarchy using the pre-fetched children_map.
     */
    private function add_children( &$parent_array, $taxonomy, $current_depth ) {
        if ( $current_depth > $this->htax_atts['max_depth'] ) {
            return;
        }

        $children = $this->children_map[ (int) $parent_array['term']->term_id ] ?? array();

        foreach ( $children as $child ) {
            $this->term_depths[ $child->term_id ] = $current_depth;
            $this->term_anchors[ $child->term_id ] = 'htax-term-' . $child->term_id;

            $child_array = array(
                'term' => $child,
                'depth' => $current_depth,
                'children' => array()
            );

            // Recursively add children
            if ( $current_depth < $this->htax_atts['max_depth'] ) {
                $this->add_children( $child_array, $taxonomy, $current_depth + 1 );
            }

            $parent_array['children'][ $child->term_id ] = $child_array;
        }
    }

    /**
     * Render the hierarchical structure
     */
    private function render_hierarchy( $hierarchical_terms, $terms, $taxonomy ) {
        // Check if collapsible
        $is_collapsible = in_array( $this->htax_atts['collapsible'], array( 'true', 'collapsed' ), true );

        // Build container classes
        $classes = $this->build_container_classes( 'htax-term-list' );
        $classes[] = 'htax-hierarchical';

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $this->build_container_id_attr() . '>';

        // Show header if header parameter is specified (but not if collapsible, handled by summary)
        if ( ! $is_collapsible && ! empty( $this->htax_atts['header'] ) ) {
            $header_text = $this->process_header_placeholders( $this->htax_atts['header'], $terms, $taxonomy );
            $output .= '<h3>' . wp_kses_post( $header_text ) . '</h3>';
        }

        foreach ( $hierarchical_terms as $term_data ) {
            if ( $this->should_exclude_term( $term_data['term'] ) ) {
                continue;
            }
            $output .= $this->render_term_with_children( $term_data );
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Render a single term with its children
     */
    private function render_term_with_children( $term_data ) {
        $term = $term_data['term'];
        $depth = $term_data['depth'];

        // Skip if already processed (to avoid duplicates)
        if ( isset( $this->processed_terms[ $term->term_id ] ) ) {
            return '';
        }
        $this->processed_terms[ $term->term_id ] = true;

        $output = $this->render_single_term( $term, $depth );

        // Render children
        if ( ! empty( $term_data['children'] ) ) {
            foreach ( $term_data['children'] as $child_data ) {
                if ( ! $this->should_exclude_term( $child_data['term'] ) ) {
                    $output .= $this->render_term_with_children( $child_data );
                }
            }
        }

        return $output;
    }

    /**
     * Render a single term
     */
    private function render_single_term( $term, $depth ) {
        $is_redirect = $this->is_term_redirect( $term );

        // Build classes
        $classes = array( 'htax-term-item' );
        $classes[] = 'htax-depth-' . $depth;

        if ( $is_redirect ) {
            $classes[] = 'htax-redirect-term';
            if ( ! empty( $this->htax_atts['redirect_div_class'] ) ) {
                $classes[] = $this->htax_atts['redirect_div_class'];
            }
        }

        // Build styles
        $styles = array();
        if ( $depth > 0 && $this->htax_atts['indent_style'] === 'padding' ) {
            $indent = $depth * intval( $this->htax_atts['indent_size'] );
            $styles[] = 'padding-left: ' . $indent . 'px';
        }

        // Process template prepend/append parameters
        $prepend_content = '';
        if ( ! empty( $this->htax_atts['prepend'] ) ) {
            $prepend_content = $this->process_placeholders( $this->htax_atts['prepend'], $term, $depth );
        }
        $append_content = $this->process_term_placeholders( $term, $depth );

        // Get title attribute
        $title_attr = $this->get_redirect_title( $term );

        // Determine link behavior for redirects
        $link_attrs_result = $this->build_redirect_link_attributes( $term, $classes );
        $link_tag = $link_attrs_result['link_tag'];
        $link_href = $link_attrs_result['link_href'];
        $link_attrs = $link_attrs_result['link_attrs'];

        // Add redirect target name to attrs (term-list specific)
        if ( $is_redirect ) {
            $redirect_target = $this->get_redirect_target( $term );
            if ( $redirect_target ) {
                $link_attrs .= ' data-htax-redirect-target="' . esc_attr( $redirect_target['target_term']->name ) . '"';
            }
        }

        // Build output
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"';
        if ( ! empty( $styles ) ) {
            $output .= ' style="' . esc_attr( implode( '; ', $styles ) ) . '"';
        }
        // Add anchor ID if available and generate_anchors is enabled
        if ( $this->htax_atts['generate_anchors'] === 'true' && isset( $this->term_anchors[ $term->term_id ] ) ) {
            $output .= ' id="' . esc_attr( $this->term_anchors[ $term->term_id ] ) . '"';
        }
        $output .= '>';

        // Add indent dashes if specified
        if ( $depth > 0 && $this->htax_atts['indent_style'] === 'dash' ) {
            $output .= str_repeat( '– ', $depth );
        }

        // Add prepend content
        if ( ! empty( $prepend_content ) ) {
            $output .= $prepend_content;
        }

        // Add the term link or span
        $output .= '<' . $link_tag;
        if ( 'a' === $link_tag ) {
            $output .= ' href="' . esc_url( $link_href ) . '"';
        }
        // Add inline font-size (Tag Groups style)
        $font_size = intval( $this->htax_atts['font_size'] );
        if ( $font_size > 0 ) {
            $output .= ' style="font-size:' . $font_size . 'px"';
        }
        if ( ! empty( $title_attr ) ) {
            $output .= ' title="' . esc_attr( $title_attr ) . '"';
        }
        if ( ! empty( $link_attrs ) ) {
            $output .= $link_attrs;
        }
        $output .= '>' . $this->build_term_name_html( $term );

        if ( ! empty( $append_content ) ) {
            $output .= $this->no_italic( $term, $append_content );
        }

        $output .= '</' . $link_tag . '></div>';

        return $output;
    }

    /**
     * Process placeholders for a term
     */
    private function process_term_placeholders( $term, $depth ) {
        $append = $this->htax_atts['append'];
        if ( empty( $append ) ) {
            return '';
        }

        return $this->process_placeholders( $append, $term, $depth );
    }

    /**
     * Wrap content with collapsible details/summary if needed
     *
     * @param string $content The content to wrap
     * @param array $terms Array of term objects (for header placeholders)
     * @param string $taxonomy Taxonomy name
     * @return string Wrapped content or original content
     */
    private function wrap_collapsible( $content, $terms, $taxonomy ) {
        $is_collapsible = in_array( $this->htax_atts['collapsible'], array( 'true', 'collapsed' ), true );

        if ( ! $is_collapsible ) {
            return $content;
        }

        $is_initially_open = ( $this->htax_atts['collapsible'] === 'true' );

        // Prepare header text
        $header_text = '';
        if ( ! empty( $this->htax_atts['header'] ) ) {
            $header_text = $this->process_header_placeholders( $this->htax_atts['header'], $terms, $taxonomy );
        } else {
            // Auto-generate default header for collapsible
            $header_text = $this->process_header_placeholders( '{term_count} Terms', $terms, $taxonomy );
        }

        // Start details element
        $output = '<details class="htax-term-list-collapsible"';
        if ( $is_initially_open ) {
            $output .= ' open';
        }
        $output .= '>';

        // Add summary (header)
        $output .= '<summary class="htax-collapsible-header">' . wp_kses_post( $header_text ) . '</summary>';

        // Add content wrapper
        $output .= '<div class="htax-collapsible-content">';
        $output .= $content;
        $output .= '</div>'; // .htax-collapsible-content

        $output .= '</details>';

        return $output;
    }

}

// Initialize class
new HyperTaxonomy_Term_List();




