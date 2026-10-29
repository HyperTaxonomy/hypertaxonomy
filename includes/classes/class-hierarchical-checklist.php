<?php
/**
 * Hierarchical Checkbox Component
 *
 * Reusable component for rendering hierarchical term checkboxes with search filter.
 * Based on WordPress core's category selection UI (wp_terms_checklist).
 * Can be used in admin screens and frontend widgets.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Walker class for hierarchical term checkbox list
 * Based on WordPress core's Walker_Category_Checklist
 */
class HyperTaxonomy_Walker_Checklist extends Walker {
    public $tree_type = 'term';  // Changed from 'category' to 'term'
    public $db_fields = array(
        'parent' => 'parent',
        'id'     => 'term_id',
    );

    /**
     * Starts the list before the elements are added
     *
     * @param string $output Used to append additional content (passed by reference)
     * @param int    $depth  Depth of term
     * @param array  $args   An array of arguments
     */
    public function start_lvl( &$output, $depth = 0, $args = array() ) {
        $indent  = str_repeat( "\t", $depth );
        $output .= "$indent<ul class='children'>\n";
    }

    /**
     * Ends the list after the elements are added
     *
     * @param string $output Used to append additional content (passed by reference)
     * @param int    $depth  Depth of term
     * @param array  $args   An array of arguments
     */
    public function end_lvl( &$output, $depth = 0, $args = array() ) {
        $indent  = str_repeat( "\t", $depth );
        $output .= "$indent</ul>\n";
    }

    /**
     * Start the element output
     *
     * @param string  $output            Used to append additional content (passed by reference)
     * @param WP_Term $data_object       The current term object
     * @param int     $depth             Depth of the term in reference to parents
     * @param array   $args              An array of arguments
     * @param int     $current_object_id Optional. ID of the current term. Default 0
     */
    public function start_el( &$output, $data_object, $depth = 0, $args = array(), $current_object_id = 0 ) {
        $term = $data_object;

        if ( empty( $args['taxonomy'] ) ) {
            $taxonomy = 'group';
        } else {
            $taxonomy = $args['taxonomy'];
        }

        // Use custom name if provided, otherwise use tax_input format
        if ( ! empty( $args['name'] ) ) {
            $name = $args['name'];
        } else {
            $name = 'tax_input[' . $taxonomy . ']';
        }

        // Add popular class if this is a popular term
        $args['popular_terms'] = ! empty( $args['popular_terms'] ) ? array_map( 'intval', $args['popular_terms'] ) : array();
        $class = in_array( $term->term_id, $args['popular_terms'], true ) ? ' class="popular-term"' : '';

        // Get selected terms
        $args['selected_terms'] = ! empty( $args['selected_terms'] ) ? array_map( 'intval', $args['selected_terms'] ) : array();

        // Determine if disabled — either every item (bool true) or a specific
        // set of term IDs (array), e.g. items another tool owns and HT's own
        // UI shouldn't let an admin toggle.
        $disabled_arg = isset( $args['disabled'] ) ? $args['disabled'] : false;
        if ( is_array( $disabled_arg ) ) {
            $disabled = in_array( $term->term_id, array_map( 'intval', $disabled_arg ), true );
        } else {
            $disabled = ! empty( $disabled_arg );
        }

        $label_attrs = $disabled && ! empty( $args['disabled_title'] )
            ? ' title="' . esc_attr( $args['disabled_title'] ) . '"'
            : '';
        $item_class  = $disabled ? ' class="htax-checklist-item-locked"' : '';

        $output .= "\n<li id='htax-{$taxonomy}-{$term->term_id}'$class>" .
            "<label$label_attrs class=\"selectit\"><input value=\"" . $term->term_id . '" type="checkbox" name="' . $name . '[]" id="in-htax-' . $taxonomy . '-' . $term->term_id . '"' .
            checked( in_array( $term->term_id, $args['selected_terms'], true ), true, false ) .
            disabled( $disabled, true, false ) . " /> <span$item_class>" .
            esc_html( $term->name ) . '</span></label>';
    }

    /**
     * Ends the element output
     *
     * @param string  $output      Used to append additional content (passed by reference)
     * @param WP_Term $data_object The current term object
     * @param int     $depth       Depth of the term in reference to parents
     * @param array   $args        An array of arguments
     */
    public function end_el( &$output, $data_object, $depth = 0, $args = array() ) {
        $output .= "</li>\n";
    }
}

/**
 * Render a hierarchical term checklist with search filter
 * Based on WordPress core's post_categories_meta_box and wp_terms_checklist
 *
 * @since 1.0.0
 *
 * @param array $args {
 *     Arguments for rendering the checklist
 *
 *     @type string $taxonomy        Taxonomy to display. Default 'group'.
 *     @type array  $selected_terms  Array of term IDs to mark as checked. Default array().
 *     @type string $name            Name attribute for checkboxes. Default 'tax_input[{taxonomy}]'.
 *     @type string $id              Container ID. Default 'htax-{taxonomy}-checklist'.
 *     @type bool   $show_search     Whether to show search input. Default true.
 *     @type bool   $show_tabs       Whether to show All/Most Used tabs. Default false.
 *     @type bool   $checked_ontop   Whether to move checked items to top. Default true.
 *     @type bool   $echo            Whether to echo or return output. Default true.
 *     @type string $wrapper_class   Additional classes for wrapper div. Default ''.
 *     @type int    $max_height      Maximum height in pixels for scrollable area. Default 200.
 *     @type int[]  $exclude         Term IDs to omit from the list. Default array().
 *     @type int[]  $disabled_terms  Term IDs to render checked/unchecked but not togglable —
 *                                   e.g. items another tool owns. Default array().
 *     @type string $disabled_title  Tooltip shown on a disabled item's label. Default ''.
 * }
 * @return string HTML output if $echo is false
 */
function htax_hierarchical_checklist( $args = array() ) {
    $defaults = array(
        'taxonomy'       => 'group',
        'selected_terms' => array(),
        'name'           => '',
        'id'             => '',
        'show_search'    => true,
        'show_tabs'      => false,
        'checked_ontop'  => true,
        'echo'           => true,
        'wrapper_class'  => '',
        'max_height'     => 200,
        'exclude'        => array(),
        'disabled_terms' => array(),
        'disabled_title' => '',
    );

    $args = wp_parse_args( $args, $defaults );

    // Set defaults based on taxonomy
    if ( empty( $args['name'] ) ) {
        $args['name'] = 'tax_input[' . $args['taxonomy'] . ']';
    }
    if ( empty( $args['id'] ) ) {
        $args['id'] = 'htax-' . $args['taxonomy'] . '-checklist';
    }

    $taxonomy = $args['taxonomy'];
    $tax_obj  = get_taxonomy( $taxonomy );

    if ( ! $tax_obj ) {
        return '';
    }

    // Get all terms in taxonomy
    // IMPORTANT: For hierarchical display, we need parent=>child ordering
    $query_args = array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'orderby'    => 'parent',  // Order by parent first for hierarchy
        'order'      => 'ASC',
    );
    if ( ! empty( $args['exclude'] ) ) {
        $query_args['exclude'] = array_map( 'intval', (array) $args['exclude'] );
    }
    $all_terms = get_terms( $query_args );

    if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
        $all_terms = array();
    }

    // Get popular terms if showing tabs
    $popular_terms = array();
    if ( $args['show_tabs'] ) {
        $popular_terms_objs = get_terms( array(
            'taxonomy'     => $taxonomy,
            'fields'       => 'ids',
            'orderby'      => 'count',
            'order'        => 'DESC',
            'number'       => 10,
            'hierarchical' => false,
        ) );
        if ( ! is_wp_error( $popular_terms_objs ) ) {
            $popular_terms = $popular_terms_objs;
        }
    }

    // Start output
    $wrapper_classes = array( 'htax-hierarchical-checklist-wrapper', 'categorydiv' );
    if ( ! empty( $args['wrapper_class'] ) ) {
        $wrapper_classes[] = $args['wrapper_class'];
    }

    // Generate dynamic label based on selected terms
    $button_label = __( 'Select Term Groups...', 'hypertaxonomy' );
    if ( ! empty( $args['selected_terms'] ) ) {
        $selected_count = count( $args['selected_terms'] );

        // Try to show term names if they fit
        // Build a lookup from the already-fetched $all_terms to avoid a second query
        $selected_set        = array_flip( $args['selected_terms'] );
        $selected_term_names = array();
        foreach ( $all_terms as $term ) {
            if ( isset( $selected_set[ $term->term_id ] ) ) {
                $selected_term_names[] = $term->name;
            }
        }

        // Join names with commas
        $names_string = implode( ', ', $selected_term_names );

        // If the string is too long (more than 40 characters), just show count
        if ( strlen( $names_string ) > 40 ) {
            /* translators: %d: number of selected term groups */
            $button_label = sprintf( _n( '%d Term Group', '%d Term Groups', $selected_count, 'hypertaxonomy' ), $selected_count );
        } else {
            $button_label = $names_string;
        }
    }

    ob_start();
    ?>
    <div id="<?php echo esc_attr( $args['id'] . '-wrapper' ); ?>" class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>">

        <?php if ( $args['show_tabs'] ) : ?>
            <ul id="<?php echo esc_attr( $args['id'] . '-tabs' ); ?>" class="category-tabs htax-checklist-tabs">
                <li class="tabs htax-collapsed"><a href="#<?php echo esc_attr( $args['id'] . '-all' ); ?>"><?php echo esc_html( $button_label ); ?></a></li>
            </ul>
        <?php endif; ?>

        <div id="<?php echo esc_attr( $args['id'] . '-all' ); ?>" class="tabs-panel" <?php echo $args['show_tabs'] ? 'style="display: none; margin: 0; padding: 0;"' : 'style="border: 0; padding: 0; margin: 0;"'; ?>>
            <?php
            // Hidden input to ensure empty value is sent
            echo '<input type="hidden" name="' . esc_attr( $args['name'] ) . '[]" value="0" />';
            ?>

            <?php if ( $args['show_search'] ) : ?>
                <input type="search" class="htax-checklist-search" placeholder="<?php esc_attr_e( 'Search...', 'hypertaxonomy' ); ?>" style="width: 100%; margin: 0 0 0 0; padding: 3px 6px; border: 1px solid #dcdcde; box-sizing: border-box;" />
            <?php endif; ?>

            <div class="htax-checklist-container" style="max-height: <?php echo absint( $args['max_height'] ); ?>px; overflow: auto; border: 1px solid #c3c4c7; border-top: 0; background-color: #fff; padding: 0 0.9em; margin: 0; box-sizing: border-box;">
                <ul id="<?php echo esc_attr( $args['id'] ); ?>" class="categorychecklist htax-checklist form-no-clear">
                    <?php
                    echo htax_render_checklist_terms( $all_terms, array(
                        'taxonomy'       => $taxonomy,
                        'name'           => $args['name'],
                        'selected_terms' => $args['selected_terms'],
                        'popular_terms'  => $popular_terms,
                        'checked_ontop'  => $args['checked_ontop'],
                        'disabled'       => $args['disabled_terms'],
                        'disabled_title' => $args['disabled_title'],
                    ) );
                    ?>
                </ul>
            </div>
        </div>
    </div>
    <?php

    $output = ob_get_clean();

    if ( $args['echo'] ) {
        echo $output;
        return '';
    }

    return $output;
}

/**
 * Render the actual checklist terms using walker
 *
 * @since 1.0.0
 *
 * @param array $terms All terms to display
 * @param array $args  Arguments for walker
 * @return string HTML output
 */
function htax_render_checklist_terms( $terms, $args = array() ) {
    $defaults = array(
        'taxonomy'       => 'group',
        'name'           => '',
        'selected_terms' => array(),
        'popular_terms'  => array(),
        'filter_popular' => false,
        'checked_ontop'  => true,
        'walker'         => null,
        'disabled'       => false,
        'disabled_title' => '',
    );

    $args = wp_parse_args( $args, $defaults );

    // Filter to only popular if requested
    if ( $args['filter_popular'] && ! empty( $args['popular_terms'] ) ) {
        $terms = array_filter( $terms, function( $term ) use ( $args ) {
            return in_array( $term->term_id, $args['popular_terms'], true );
        } );
    }

    if ( empty( $terms ) ) {
        return '<li>' . esc_html__( 'No items found.', 'hypertaxonomy' ) . '</li>';
    }

    // Use custom walker if provided, otherwise use default
    if ( empty( $args['walker'] ) || ! ( $args['walker'] instanceof Walker ) ) {
        $walker = new HyperTaxonomy_Walker_Checklist();
    } else {
        $walker = $args['walker'];
    }

    $output = '';

    // For hierarchical taxonomies, checked_ontop breaks parent-child relationships
    // Instead, we render all terms in their natural hierarchy
    // WordPress Walker will automatically handle the parent-child relationships
    $output .= htax_walk_tree( $terms, 0, $args, $walker );

    return $output;
}

/**
 * Helper function to walk the tree (wrapper around WordPress Walker::walk)
 *
 * @since 1.0.0
 *
 * @param array  $terms  Terms to walk
 * @param int    $depth  Starting depth (not used, kept for compatibility)
 * @param array  $args   Arguments
 * @param Walker $walker Walker instance
 * @return string HTML output
 */
function htax_walk_tree( $terms, $depth, $args, $walker ) {
    if ( empty( $terms ) ) {
        return '';
    }

    // WordPress Walker::walk() signature: walk( $elements, $max_depth, ...$args )
    // $max_depth of 0 means show all levels (0 = unlimited in WP terms_checklist context)
    // Pass $args as individual parameters after $max_depth
    return $walker->walk( $terms, 0, $args );
}
