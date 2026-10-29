<?php
/**
 * HyperTaxonomy Term Index Shortcode
 *
 * Displays terms alphabetically organized by first letter.
 * Includes optional letter navigation for jumping to sections.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_Term_Index extends HyperTaxonomy_Term_Display_Base {

    public function __construct() {
        add_shortcode( 'ht_term_index', array( $this, 'shortcode_handler' ) );
    }

    protected function get_default_atts() {
        return array_merge( parent::get_default_atts(), array(
            // Index-specific parameters
            'show_navigation' => 'true', // Show A-Z navigation at top
            'show_letter_headers' => 'true', // Show letter headers (A, B, C, etc.)
            'columns' => '1', // Number of columns (1-4)
            'navigation_position' => 'top', // 'top', 'bottom', 'both'

            // Display options
            'show_count' => 'true', // Show post count for each term
            'uppercase_letters' => 'true', // Use uppercase for letter headers
            'empty_message' => '', // Message to show for letters with no terms

            // Template parameters
            'format' => 'ul',    // 'ul', 'ol', or 'div' (div uses separator between terms)
            'separator' => '', // Separator between terms (only used when format=div)

        ) );
    }

    /**
     * Main shortcode handler
     */
    public function shortcode_handler( $atts ) {
        // Enqueue styles
        $this->enqueue_styles();

        // Extract attributes
        $this->htax_atts = shortcode_atts( $this->get_default_atts(), $atts );

        // Get taxonomy
        $taxonomy = $this->htax_atts['taxonomy'];

        // Load redirect terms
        $this->load_redirect_terms( $taxonomy );

        // Get terms using parent method (handles include_groups, always sorted alphabetically)
        $terms = parent::get_terms( $taxonomy, array(
            'orderby' => 'name',
            'order' => 'ASC',
        ) );

        // Filter terms
        $terms = $this->filter_terms( $terms );

        if ( empty( $terms ) ) {
            return '';
        }

        // Group terms by first letter
        $grouped_terms = $this->group_by_letter( $terms );

        // Render the index
        return $this->render_index( $grouped_terms );
    }


    /**
     * Group terms by first letter
     */
    private function group_by_letter( $terms ) {
        $grouped = array();

        foreach ( $terms as $term ) {
            // Get first character and normalize
            $first_char = mb_substr( $term->name, 0, 1 );
            $first_char = mb_strtoupper( $first_char );

            // Check if it's a letter
            if ( preg_match( '/^[A-Z]$/u', $first_char ) ) {
                $letter = $first_char;
            } elseif ( preg_match( '/^\d$/u', $first_char ) ) {
                $letter = '0-9';
            } else {
                $letter = '#'; // Special characters
            }

            if ( ! isset( $grouped[ $letter ] ) ) {
                $grouped[ $letter ] = array();
            }

            $grouped[ $letter ][] = $term;
        }

        // Sort by letter
        ksort( $grouped );

        // Move special groups to end
        if ( isset( $grouped['0-9'] ) ) {
            $numbers = $grouped['0-9'];
            unset( $grouped['0-9'] );
            $grouped['0-9'] = $numbers;
        }
        if ( isset( $grouped['#'] ) ) {
            $special = $grouped['#'];
            unset( $grouped['#'] );
            $grouped['#'] = $special;
        }

        return $grouped;
    }

    /**
     * Render the complete index
     */
    private function render_index( $grouped_terms ) {
        // Build container classes
        $classes = $this->build_container_classes( 'htax-term-index' );

        // Start output
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $this->build_container_id_attr() . '>';

        // Show header if specified
        if ( ! empty( $this->htax_atts['header'] ) ) {
            // Flatten grouped_terms to get all terms for placeholder processing
            $all_terms = $grouped_terms ? array_merge( ...array_values( $grouped_terms ) ) : array();

            $header_text = $this->process_header_placeholders(
                $this->htax_atts['header'],
                $all_terms,
                $this->htax_atts['taxonomy']
            );
            $output .= '<h3 class="htax-index-header">' . wp_kses_post( $header_text ) . '</h3>';
        }

        // Add navigation at top if requested
        $nav_position = $this->htax_atts['navigation_position'];
        if ( 'true' === $this->htax_atts['show_navigation'] && ( 'top' === $nav_position || 'both' === $nav_position ) ) {
            $output .= $this->render_navigation( $grouped_terms );
        }

        // Add letter sections
        $output .= '<div class="htax-index-content">';
        foreach ( $grouped_terms as $letter => $terms ) {
            $output .= $this->render_letter_section( $letter, $terms );
        }
        $output .= '</div>';

        // Add navigation at bottom if requested
        if ( 'true' === $this->htax_atts['show_navigation'] && ( 'bottom' === $nav_position || 'both' === $nav_position ) ) {
            $output .= $this->render_navigation( $grouped_terms );
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Render the A-Z navigation
     */
    private function render_navigation( $grouped_terms ) {
        $available_letters = array_keys( $grouped_terms );

        $output = '<nav class="htax-index-navigation">';

        // Generate all letters A-Z
        foreach ( range( 'A', 'Z' ) as $letter ) {
            $is_available = in_array( $letter, $available_letters );

            if ( $is_available ) {
                $output .= '<a href="#htax-letter-' . strtolower( $letter ) . '" class="htax-nav-letter htax-nav-available">';
                $output .= esc_html( $letter );
                $output .= '</a> ';
            } else {
                $output .= '<span class="htax-nav-letter htax-nav-unavailable">';
                $output .= esc_html( $letter );
                $output .= '</span> ';
            }
        }

        // Add 0-9 if present
        if ( in_array( '0-9', $available_letters ) ) {
            $output .= '<a href="#htax-letter-09" class="htax-nav-letter htax-nav-available">';
            $output .= '0-9</a> ';
        }

        // Add # if present
        if ( in_array( '#', $available_letters ) ) {
            $output .= '<a href="#htax-letter-special" class="htax-nav-letter htax-nav-available">';
            $output .= '#</a>';
        }

        $output .= '</nav>';

        return $output;
    }

    /**
     * Render a letter section with its terms
     */
    private function render_letter_section( $letter, $terms ) {
        // Determine anchor ID
        if ( '0-9' === $letter ) {
            $anchor_id = 'htax-letter-09';
        } elseif ( '#' === $letter ) {
            $anchor_id = 'htax-letter-special';
        } else {
            $anchor_id = 'htax-letter-' . strtolower( $letter );
        }

        $output = '<div class="htax-letter-section" id="' . esc_attr( $anchor_id ) . '">';

        // Add letter header
        if ( $this->htax_atts['show_letter_headers'] === 'true' ) {
            $display_letter = $this->htax_atts['uppercase_letters'] === 'true' ? $letter : strtolower( $letter );
            $output .= '<h3 class="htax-letter-header">' . esc_html( $display_letter ) . '</h3>';
        }

        // Render terms
        $format = $this->htax_atts['format'];
        if ( 'ul' === $format || 'ol' === $format ) {
            $tag     = esc_attr( $format );
            $output .= '<' . $tag . ' class="htax-letter-terms">';
            foreach ( $terms as $term ) {
                if ( ! isset( $this->processed_terms[ $term->term_id ] ) ) {
                    $this->processed_terms[ $term->term_id ] = true;
                    $output .= '<li>' . $this->render_term( $term ) . '</li>';
                }
            }
            $output .= '</' . $tag . '>';
        } else {
            $output .= '<div class="htax-letter-terms">';
            $separator = wp_kses_post( $this->htax_atts['separator'] );
            $first = true;
            foreach ( $terms as $term ) {
                if ( ! isset( $this->processed_terms[ $term->term_id ] ) ) {
                    $this->processed_terms[ $term->term_id ] = true;
                    if ( ! $first && ! empty( $separator ) ) {
                        $output .= $separator;
                    }
                    $first = false;
                    $output .= $this->render_term( $term );
                }
            }
            $output .= '</div>';
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Render a single term
     */
    private function render_term( $term ) {
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
            ? $this->process_placeholders( $this->htax_atts['prepend'], $term )
            : '';
        $append_content = ! empty( $this->htax_atts['append'] )
            ? $this->process_placeholders( $this->htax_atts['append'], $term )
            : '';

        // Build output
        $output = '<span class="' . esc_attr( implode( ' ', $classes ) ) . '"';
        if ( ! empty( $styles ) ) {
            $output .= ' style="' . esc_attr( implode( '; ', $styles ) ) . '"';
        }
        $output .= '>';

        // Add prepend content
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
        $output .= '>' . $this->build_term_name_html( $term );

        if ( $this->htax_atts['show_count'] === 'true' ) {
            $output .= $this->no_italic( $term, ' <span class="htax-term-count">(' . $term->count . ')</span>' );
        }

        if ( ! empty( $append_content ) ) {
            $output .= $this->no_italic( $term, $append_content );
        }

        $output .= '</' . $link_tag . '>';
        $output .= '</span>';

        return $output;
    }

}

// Initialize class
new HyperTaxonomy_Term_Index();
