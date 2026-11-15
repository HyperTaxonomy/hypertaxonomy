<?php
/**
 * Sort Order Options Shortcode
 *
 * [ht_sort_order_options] — Standalone sort order picker, primarily used on
 * archive pages (action="navigate") or inside [htax_search_form] (action="submit").
 *
 * Also provides htax_render_sort_order_picker() used by other shortcodes to embed
 * a sort picker directly in their output (post list, search results, PowerSearch results).
 *
 * Shortcode attributes:
 *   fields         Comma-separated field slugs, in display order.
 *                  Default: date,modified,title,author,comment_count
 *   state          'expanded' | 'collapsed'. Default: 'expanded'.
 *   action         'navigate' | 'submit' | '' (empty = fire htax:sort-change only).
 *                  Default: 'navigate'.
 *   default        Initial value e.g. "date:desc,title:asc". For action="navigate",
 *                  auto-populated from $_GET['htax_orderby'] when empty.
 *   input_name     Name attribute on the hidden value input. Default: 'htax_orderby'.
 *   text_collapsed Toggle button label when collapsed. Default: 'Sort'.
 *   text_expanded  Toggle button label when expanded. Default: 'Sort'.
 *   class          Extra CSS classes on the root element.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Human-readable labels for every supported sort field.
 *
 * @return array  field_slug => translated label
 */
function htax_sort_field_labels() {
    return array(
        'date'          => __( 'Date', 'hypertaxonomy' ),
        'modified'      => __( 'Modified', 'hypertaxonomy' ),
        'title'         => __( 'Title', 'hypertaxonomy' ),
        'author'        => __( 'Author', 'hypertaxonomy' ),
        'comment_count' => __( 'Comments', 'hypertaxonomy' ),
        'last_comment'  => __( 'Latest Comment', 'hypertaxonomy' ),
        'last_activity' => __( 'Latest Activity', 'hypertaxonomy' ),
        'relevance'     => __( 'Relevance', 'hypertaxonomy' ),
        'ID'            => __( 'ID', 'hypertaxonomy' ),
    );
}

/**
 * Enqueue sort order picker frontend assets.
 *
 * Safe to call multiple times; skips if already enqueued.
 */
function htax_enqueue_sort_order_assets() {
    if ( ! wp_style_is( 'htax-sort-order', 'enqueued' ) && ! wp_style_is( 'htax-sort-order', 'done' ) ) {
        wp_enqueue_style(
            'htax-sort-order',
            HTAX_PLUGIN_URL . 'assets/css/htax-sort-order.css',
            array(),
            HTAX_VERSION
        );
    }
    if ( ! wp_script_is( 'htax-sort-order', 'enqueued' ) && ! wp_script_is( 'htax-sort-order', 'done' ) ) {
        wp_enqueue_script(
            'htax-sort-order',
            HTAX_PLUGIN_URL . 'assets/js/htax-sort-order.js',
            array( 'jquery' ),
            HTAX_VERSION,
            true
        );
    }
}

/**
 * Render a sort order picker component.
 *
 * @param array $args {
 *   @type string[]|string $fields         Field slugs to offer, in display order.
 *                                         Accepts array or comma-separated string.
 *   @type string          $state          'expanded' | 'collapsed'. Default 'expanded'.
 *   @type string          $action         'navigate' | 'submit' | ''. Default ''.
 *   @type string          $default_value  Initial serialized value e.g. "date:desc".
 *   @type string          $input_name     name= on the hidden value input. Default 'htax_orderby'.
 *   @type string          $text_collapsed Toggle button label when collapsed. Default 'Sort'.
 *   @type string          $text_expanded  Toggle button label when expanded. Default 'Sort'.
 *   @type string          $class          Extra CSS class on root element.
 * }
 * @return string HTML output.
 */
function htax_render_sort_order_picker( array $args ) {
    $args = wp_parse_args( $args, array(
        'fields'         => array( 'date', 'modified', 'title', 'author', 'comment_count' ),
        'state'          => 'expanded',
        'action'         => '',
        'default_value'  => '',
        'input_name'     => 'htax_orderby',
        'text_collapsed' => __( 'Sort', 'hypertaxonomy' ),
        'text_expanded'  => __( 'Sort', 'hypertaxonomy' ),
        'class'          => '',
    ) );

    htax_enqueue_sort_order_assets();

    // Normalize fields to an array of slugs
    if ( ! is_array( $args['fields'] ) ) {
        $args['fields'] = array_filter( array_map( 'trim', explode( ',', (string) $args['fields'] ) ) );
    }

    $labels     = htax_sort_field_labels();
    $fields     = array();
    foreach ( $args['fields'] as $slug ) {
        if ( isset( $labels[ $slug ] ) ) {
            $fields[ $slug ] = $labels[ $slug ];
        }
    }

    if ( empty( $fields ) ) {
        return '';
    }

    $state      = 'collapsed' === $args['state'] ? 'collapsed' : 'expanded';
    $action     = in_array( $args['action'], array( 'navigate', 'submit' ), true ) ? $args['action'] : '';
    $value      = (string) $args['default_value'];
    $input_name = sanitize_key( $args['input_name'] ) ?: 'htax_orderby';
    $extra      = ! empty( $args['class'] ) ? ' ' . esc_attr( $args['class'] ) : '';

    $label_asc    = __( 'Ascending — click to toggle',  'hypertaxonomy' );
    $label_desc   = __( 'Descending — click to toggle', 'hypertaxonomy' );
    $label_remove = __( 'Remove', 'hypertaxonomy' );
    $label_add    = __( 'Add sort field', 'hypertaxonomy' );

    $out  = '<div class="htax-sort-order-root' . $extra . '"';
    $out .= ' data-htax-sort-root';
    $out .= $action ? ' data-htax-sort-action="' . esc_attr( $action ) . '"' : '';
    $out .= ' data-state="' . esc_attr( $state ) . '">';

    // Toggle button — always rendered; CSS hides/shows the container
    $out .= '<button type="button" class="htax-sort-toggle"';
    $out .= ' data-text-collapsed="' . esc_attr( $args['text_collapsed'] ) . '"';
    $out .= ' data-text-expanded="' . esc_attr( $args['text_expanded'] ) . '">';
    $out .= '<span class="htax-sort-toggle-text">';
    $out .= esc_html( 'collapsed' === $state ? $args['text_collapsed'] : $args['text_expanded'] );
    $out .= '</span></button>';

    // Container (active pills + add button)
    $out .= '<div data-htax-sort-container class="htax-sort-order-container"';
    $out .= ' data-label-asc="'    . esc_attr( $label_asc )    . '"';
    $out .= ' data-label-desc="'   . esc_attr( $label_desc )   . '"';
    $out .= ' data-label-remove="' . esc_attr( $label_remove ) . '">';
    $out .= '<button type="button" class="htax-sort-order-add" data-htax-sort-add';
    $out .= ' aria-label="' . esc_attr( $label_add ) . '">+</button>';
    $out .= '</div>';

    // Picker popover
    $out .= '<div data-htax-sort-picker class="htax-sort-order-picker" style="display:none">';
    foreach ( $fields as $slug => $label ) {
        $out .= '<button type="button" class="htax-sort-picker-pill"';
        $out .= ' data-field="' . esc_attr( $slug ) . '">';
        $out .= esc_html( $label );
        $out .= '</button>';
    }
    $out .= '</div>';

    // Hidden value input
    $out .= '<input type="hidden"';
    $out .= ' name="' . esc_attr( $input_name ) . '"';
    $out .= ' data-htax-sort-value';
    $out .= ' value="' . esc_attr( $value ) . '">';

    $out .= '</div>';

    return $out;
}

/**
 * [ht_sort_order_options] shortcode handler.
 *
 * Standalone sort order picker. Defaults to action="navigate" for archives.
 * Place inside [htax_search_form] with action="submit" for search form use.
 */
function htax_sort_order_options_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'fields'         => 'date,modified,title,author,comment_count',
        'state'          => 'expanded',
        'action'         => 'navigate',
        'default'        => '',
        'input_name'     => 'htax_orderby',
        'text_collapsed' => __( 'Sort', 'hypertaxonomy' ),
        'text_expanded'  => __( 'Sort', 'hypertaxonomy' ),
        'class'          => '',
    ), $atts, 'ht_sort_order_options' );

    $default_value = $atts['default'];

    // For navigate, pre-populate from current URL so picker reflects active sort
    if ( 'navigate' === $atts['action'] && '' === $default_value && isset( $_GET['htax_orderby'] ) ) {
        $default_value = sanitize_text_field( wp_unslash( $_GET['htax_orderby'] ) );
    }

    return htax_render_sort_order_picker( array(
        'fields'         => $atts['fields'],
        'state'          => $atts['state'],
        'action'         => $atts['action'],
        'default_value'  => $default_value,
        'input_name'     => $atts['input_name'],
        'text_collapsed' => $atts['text_collapsed'],
        'text_expanded'  => $atts['text_expanded'],
        'class'          => $atts['class'],
    ) );
}

add_shortcode( 'ht_sort_order_options', 'htax_sort_order_options_shortcode' );
