<?php
/**
 * HyperTaxonomy Cloud Shortcode
 *
 * Displays a tag/term cloud with variable font sizes based on post counts.
 * All terms from selected groups are displayed together in a single cloud.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_Cloud extends HyperTaxonomy_Term_Display_Base {

    public function __construct() {
        add_shortcode( 'ht_term_cloud', array( $this, 'shortcode_handler' ) );
    }

    protected function get_default_atts() {
        return array_merge( parent::get_default_atts(), array(
            // Cloud-specific parameters
            'smallest' => '12', // Smallest font size in points
            'largest' => '22', // Largest font size in points
            'unit' => 'pt', // Font size unit (pt, px, em, rem)
            'max_results' => '0', // Maximum number of terms to display (0 = all)

            // Template parameters
            'separator' => ' ', // Separator between terms

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

        // Get taxonomy
        $taxonomy = $this->htax_atts['taxonomy'];

        // Load redirect terms
        $this->load_redirect_terms( $taxonomy );

        // Get terms using parent method (handles include_groups)
        $terms = parent::get_terms( $taxonomy );

        // Filter terms based on exclusion criteria
        $terms = $this->filter_terms( $terms );

        if ( empty( $terms ) ) {
            return '';
        }

        // Limit displayed terms if max_results is set
        $max_results = intval( $this->htax_atts['max_results'] );
        if ( $max_results > 0 && count( $terms ) > $max_results ) {
            $terms = array_slice( $terms, 0, $max_results );
        }

        // Calculate font sizes
        $this->calculate_font_sizes( $terms );

        // Render the cloud
        return $this->render_cloud( $terms );
    }

    /**
     * Calculate font sizes for terms based on post counts
     */
    private function calculate_font_sizes( &$terms ) {
        if ( empty( $terms ) ) {
            return;
        }

        // Find min and max counts
        $min_count = PHP_INT_MAX;
        $max_count = 0;

        foreach ( $terms as $term ) {
            $count = $term->count;
            if ( $count < $min_count ) {
                $min_count = $count;
            }
            if ( $count > $max_count ) {
                $max_count = $count;
            }
        }

        // Get font size range
        $smallest = floatval( $this->htax_atts['smallest'] );
        $largest = floatval( $this->htax_atts['largest'] );

        // Calculate font size for each term
        foreach ( $terms as $term ) {
            if ( $max_count > $min_count ) {
                // Proportional scaling
                $term->font_size = round(
                    ( $term->count - $min_count ) * ( $largest - $smallest ) / ( $max_count - $min_count ) + $smallest,
                    2
                );
            } else {
                // All terms have same count, use middle size
                $term->font_size = round( ( $smallest + $largest ) / 2, 2 );
            }
        }
    }

    /**
     * Render the cloud
     */
    private function render_cloud( $terms ) {
        $unit = esc_attr( $this->htax_atts['unit'] );
        $separator = wp_kses_post( $this->htax_atts['separator'] );
        $taxonomy = $this->htax_atts['taxonomy'];

        // Build container classes
        $classes = $this->build_container_classes( 'htax-cloud', false );

        // Start output
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $this->build_container_id_attr() . '>';

        // Show header if header parameter is specified
        if ( ! empty( $this->htax_atts['header'] ) ) {
            $header_text = $this->process_header_placeholders( $this->htax_atts['header'], $terms, $taxonomy );
            $output .= '<h3>' . wp_kses_post( $header_text ) . '</h3>';
        }

        // Render terms
        $output .= '<div class="htax-cloud-tags">';
        $first = true;

        foreach ( $terms as $term ) {
            // Add separator
            if ( ! $first && ! empty( $separator ) ) {
                $output .= $separator;
            }
            $first = false;

            // Render term
            $output .= $this->render_term( $term, $unit );
        }

        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render a single term in the cloud
     */
    private function render_term( $term, $unit ) {
        $classes    = $this->build_term_item_classes( $term, 'htax-cloud-term' );
        $styles     = $this->build_term_item_styles( $term, array( 'font-size: ' . $term->font_size . $unit ) );
        $title_attr = $this->get_redirect_title( $term );

        // Determine link behavior for redirects
        $link_attrs_result = $this->build_redirect_link_attributes( $term, $classes );
        $link_tag = $link_attrs_result['link_tag'];
        $link_href = $link_attrs_result['link_href'];
        $link_attrs = $link_attrs_result['link_attrs'];

        // Process prepend/append content
        $prepend_content = ! empty( $this->htax_atts['prepend'] )
            ? $this->process_placeholders( $this->htax_atts['prepend'], $term )
            : '';
        $append_content = ! empty( $this->htax_atts['append'] )
            ? $this->process_placeholders( $this->htax_atts['append'], $term )
            : '';

        // Build output
        $output = '<span class="' . esc_attr( implode( ' ', $classes ) ) . '"';
        $output .= ' style="' . esc_attr( implode( '; ', $styles ) ) . '">';

        if ( ! empty( $prepend_content ) ) {
            $output .= $prepend_content;
        }

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
        $output .= '>' . $this->build_term_name_html( $term ) . '</' . $link_tag . '>';

        if ( ! empty( $append_content ) ) {
            $output .= $append_content;
        }

        $output .= '</span>';

        return $output;
    }

}

// Initialize class
new HyperTaxonomy_Cloud();

