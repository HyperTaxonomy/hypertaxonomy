<?php
/**
 * Frontend Display Functions
 * Handles all public-facing output and styling
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Filter terms to hide hidden ones from public display
 */
function htax_filter_term_links( $term_links, $taxonomy = '' ) {
    // Only filter on frontend
    if ( is_admin() ) {
        return $term_links;
    }

    // Check visibility setting
    $options = get_option( 'htax_settings', array() );
    $visibility = isset( $options['hidden_terms_visibility'] ) ? $options['hidden_terms_visibility'] : 'editors';

    // Determine if user can see hidden terms
    $can_see_hidden = false;
    if ( 'all' === $visibility ) {
        $can_see_hidden = true;
    } elseif ( 'editors' === $visibility ) {
        $can_see_hidden = current_user_can( 'edit_posts' );
    }

    // Allow themes to override (taxonomy may be empty if not passed by filter)
    if ( apply_filters( 'htax_show_hidden_terms', $can_see_hidden, $taxonomy ) ) {
        $can_see_hidden = true;
    }

    // Build a canonical href → term_id map for this taxonomy so the filter works
    // for pretty permalinks and custom taxonomies (not just ugly-permalink query vars).
    // get_terms() primes the term meta cache in one query, so get_term_meta() below is free.
    // Cache per taxonomy for the lifetime of the request (filter may be called once per post in a loop).
    static $taxonomy_maps = array();

    if ( ! isset( $taxonomy_maps[ $taxonomy ] ) ) {
        $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
        if ( is_wp_error( $terms ) ) {
            return $term_links;
        }

        $link_to_id = array();
        $hidden_ids = array();
        foreach ( $terms as $term ) {
            $canonical = get_term_link( $term );
            if ( ! is_wp_error( $canonical ) ) {
                $link_to_id[ $canonical ] = $term->term_id;
            }
            if ( '1' === get_term_meta( $term->term_id, '_htax_is_hidden', true ) ) {
                $hidden_ids[ $term->term_id ] = true;
            }
        }

        $taxonomy_maps[ $taxonomy ] = array( $link_to_id, $hidden_ids );
    }

    if ( empty( $taxonomy_maps[ $taxonomy ][0] ) ) {
        return $term_links;
    }

    [ $link_to_id, $hidden_ids ] = $taxonomy_maps[ $taxonomy ];

    $filtered_links = array();

    foreach ( $term_links as $term_link ) {
        $term_id = 0;

        // Extract href and look it up in the canonical map.
        if ( preg_match( '/href=["\']([^"\']+)["\']/', $term_link, $m ) ) {
            $term_id = $link_to_id[ $m[1] ] ?? 0;
        }

        if ( $term_id && isset( $hidden_ids[ $term_id ] ) ) {
            if ( $can_see_hidden ) {
                $term_link       = str_replace( '<a ', '<a class="htax-hidden-term" ', $term_link );
                $filtered_links[] = $term_link;
            }
            // Hidden and user can't see — omit.
        } else {
            $filtered_links[] = $term_link;
        }
    }

    return $filtered_links;
}

/**
 * Filter get_the_terms to mark hidden terms
 */
function htax_filter_get_the_terms( $terms, $post_id, $taxonomy ) {
    if ( ! $terms || is_wp_error( $terms ) || is_admin() ) {
        return $terms;
    }

    // Check if we should filter — evaluate capability first, then allow filter to override.
    $can_see = htax_can_see_hidden_terms( $taxonomy );
    if ( apply_filters( 'htax_show_hidden_terms', $can_see, $taxonomy ) ) {
        return $terms;
    }

    foreach ( $terms as $key => $term ) {
        $is_hidden = get_term_meta( $term->term_id, '_htax_is_hidden', true );
        if ( '1' === $is_hidden ) {
            // Add property for themes to check
            $term->is_htax_hidden = true;

            // Optionally remove from array (controlled by filter)
            if ( apply_filters( 'htax_remove_hidden_terms_from_list', true, $term, $taxonomy ) ) {
                unset( $terms[ $key ] );
            }
        }
    }

    return array_values( $terms ); // Re-index array
}

/**
 * Filter tag cloud to exclude hidden terms
 */
function htax_filter_tag_cloud( $return, $args ) {
    // Parse the tag cloud HTML to remove hidden terms
    if ( empty( $return ) ) {
        return $return;
    }

    // Get all hidden term IDs for the taxonomy
    $hidden_term_ids = htax_get_all_hidden_term_ids();

    if ( empty( $hidden_term_ids ) ) {
        return $return;
    }

    // Remove hidden terms from the cloud.
    // WordPress adds class="... tag-link-{id} ..." to every tag cloud link — match by that.
    // The previous approach using tag_ID= / \/tag\/ patterns was wrong: tag_ID= never appears
    // in frontend links, and \/tag\/[^\/]*\/ matches every tag link (not just the hidden one).
    $id_alternation = implode( '|', array_map( 'intval', $hidden_term_ids ) );
    $pattern        = '/<a\b[^>]*\btag-link-(?:' . $id_alternation . ')\b[^>]*>.*?<\/a>/is';
    $return         = preg_replace( $pattern, '', $return );

    return $return;
}

/**
 * Output frontend styles for term icons and sticky posts
 *
 * @since 1.0.0
 */
function htax_frontend_styles() {
    if ( ! is_admin() ) {
        $options = get_option( 'htax_settings', array() );

        // Allow themes to disable plugin styles entirely
        if ( apply_filters( 'htax_disable_frontend_styles', false ) ) {
            return;
        }

        // Check if user has disabled styles in settings
        if ( ! empty( $options['disable_sticky_styles'] ) ) {
            return;
        }

        ?>
        <style type="text/css" id="htax-frontend-styles">
        /* Allow themes to filter these styles */
        <?php echo wp_strip_all_tags( apply_filters( 'htax_frontend_styles', '
        .htax-redirect-term {
            font-style: italic !important;
            opacity: 0.8;
        }
        .wp-tag-cloud .htax-redirect-term,
        .tagcloud .htax-redirect-term,
        .widget_categories .htax-redirect-term,
        .widget_tag_cloud .htax-redirect-term,
        .entry-meta .htax-redirect-term,
        .post-meta .htax-redirect-term {
            font-style: italic !important;
        }

        /* Sticky post default styles - subtle and should work with most themes */
        .htax-sticky-post {
            position: relative;
            padding-top: 1.5em;
            border-top: 3px solid currentColor;
            opacity: 0.95;
        }

        .htax-sticky-post::before {
            content: "📌 Pinned";
            position: absolute;
            top: 0;
            left: 0;
            font-size: 0.85em;
            opacity: 0.7;
            font-weight: 600;
        }

        /* Separator line after the last sticky post, before the first regular post */
        .htax-sticky-post + article:not(.htax-sticky-post),
        .htax-sticky-post + .post:not(.htax-sticky-post),
        .htax-sticky-post + .hentry:not(.htax-sticky-post) {
            border-top: 3px solid currentColor;
            padding-top: 1.5em;
        }

        /* Archive description styles */
        .htax-archive-description {
            margin: 1.5em 0;
            padding: 1.5em;
            background: #f8f9fa;
            border-left: 4px solid #0073aa;
            border-radius: 4px;
            font-size: 1.05em;
            line-height: 1.6;
        }

        .htax-archive-description p:first-child {
            margin-top: 0;
        }

        .htax-archive-description p:last-child {
            margin-bottom: 0;
        }

        .htax-archive-description ul,
        .htax-archive-description ol {
            margin: 1em 0;
            padding-left: 2em;
        }

        .htax-archive-description blockquote {
            margin: 1em 0;
            padding: 0.5em 1em;
            border-left: 3px solid #ccc;
            background: rgba(255, 255, 255, 0.5);
            font-style: italic;
        }

        .htax-archive-description code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }

        .htax-archive-description h3 {
            margin-top: 1.2em;
            margin-bottom: 0.6em;
            font-size: 1.2em;
        }

        .htax-archive-description h4 {
            margin-top: 1em;
            margin-bottom: 0.5em;
            font-size: 1.1em;
        }

        /* Hidden terms — only added for users who CAN see hidden terms, as a visual indicator */
        .htax-hidden-term {
            color: #999 !important;
            opacity: 0.6;
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .htax-archive-description {
                background: #2c3338;
                color: #f0f0f1;
                border-left-color: #3582c4;
            }

            .htax-archive-description code {
                background: #1e1e1e;
                color: #f0f0f1;
            }

            .htax-archive-description blockquote {
                background: rgba(0, 0, 0, 0.3);
                border-left-color: #666;
            }

            .htax-hidden-term {
                color: #666 !important;
            }
        }
        ' ) ); ?>
        </style>
        <?php
    }
}

/**
 * Resolve a border_radius settings array to a CSS value string.
 *
 * Percentage values scale proportionally with the element's own dimensions,
 * so the visual corner weight is consistent regardless of icon size.
 *
 * @param array $border_radius The border_radius settings sub-array from htax_settings.
 * @return string CSS border-radius value, e.g. '7%' or '20% 20% 20% 20%'.
 */
function htax_resolve_border_radius_css( $border_radius ) {
    $style = isset( $border_radius['style'] ) ? $border_radius['style'] : 'rounded';

    switch ( $style ) {
        case 'square':
            return '0';
        case 'rounded':
            return '7%';
        case 'extra_rounded':
            return '15%';
        case 'circular':
            return '50%';
        case 'custom':
            $tl = $border_radius['top_left']     ?? 20;
            $tr = $border_radius['top_right']    ?? 20;
            $bl = $border_radius['bottom_left']  ?? 20;
            $br = $border_radius['bottom_right'] ?? 20;
            return $tl . '% ' . $tr . '% ' . $br . '% ' . $bl . '%';
        default:
            return '7%';
    }
}

function htax_icon_border_radius_styles() {
    $options = get_option( 'htax_settings', array() );
    $icon_settings = isset( $options['icon_settings'] ) ? $options['icon_settings'] : array();

    if ( empty( $icon_settings ) ) {
        return;
    }

    // Build CSS for each taxonomy
    $css_rules = array();

    foreach ( $icon_settings as $taxonomy => $settings ) {
        if ( empty( $settings['enabled'] ) ) {
            continue;
        }

        $border_radius = isset( $settings['border_radius'] ) ? $settings['border_radius'] : array();
        $css = htax_resolve_border_radius_css( $border_radius );

        if ( $css ) {
            $css_rules[ $taxonomy ] = $css;
        }
    }

    if ( empty( $css_rules ) ) {
        return;
    }

    ?>
    <style type="text/css" id="htax-icon-border-radius">
        /* HyperTaxonomy Dynamic Icon Border Radius - Per Taxonomy */
        <?php foreach ( $css_rules as $taxonomy => $css ) : ?>
        /* <?php echo esc_attr( $taxonomy ); ?> */
        .htax-icon-small[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-pill-icon[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-term-icon.htax-icon-small[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        img.htax-icon-small[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        img.htax-term-icon[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        img.htax-autocomplete-icon[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-term-link .htax-term-icon[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"] {
            border-radius: <?php echo esc_attr( $css ); ?> !important;
            border: 1px solid rgba(0,0,0,0.15);
        }

        .htax-icon-medium[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-term-icon.htax-icon-medium[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        img.htax-icon-medium[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-archive-icon img[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"] {
            border-radius: <?php echo esc_attr( $css ); ?> !important;
            border: 1px solid rgba(0,0,0,0.15);
        }

        .htax-icon-large[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-term-icon.htax-icon-large[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        img.htax-icon-large[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"] {
            border-radius: <?php echo esc_attr( $css ); ?> !important;
            border: 1px solid rgba(0,0,0,0.15);
        }

        /* Placeholder squares and elements - match small icon dimensions and shape */
        <?php $small_dims = HyperTaxonomy_Term_Icons::get_icon_dimensions( $taxonomy, 'small' ); ?>
        .htax-autocomplete-placeholder[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-icon-placeholder[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-term-link .htax-term-placeholder[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"],
        .htax-archive-icon .htax-icon-placeholder[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"] {
            display: inline-block !important;
            width: <?php echo intval( $small_dims['width'] ); ?>px !important;
            height: <?php echo intval( $small_dims['height'] ); ?>px !important;
            vertical-align: middle !important;
            border-radius: <?php echo esc_attr( $css ); ?> !important;
            border: 1px solid rgba(0,0,0,0.15);
        }

        /* Pill placeholders: stretch to fill pill height, derive width from aspect ratio */
        .htax-pill-placeholder[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"] {
            display: block !important;
            width: auto !important;
            aspect-ratio: <?php echo intval( $small_dims['width'] ); ?> / <?php echo intval( $small_dims['height'] ); ?> !important;
            box-sizing: border-box !important;
            border-radius: <?php echo esc_attr( $css ); ?> !important;
            border: 1px solid rgba(0,0,0,0.15);
        }

        /* Pill icon imgs: stretch to fill pill height, derive width from aspect ratio */
        .htax-pill-icon[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"] {
            width: auto !important;
            aspect-ratio: <?php echo intval( $small_dims['width'] ); ?> / <?php echo intval( $small_dims['height'] ); ?> !important;
        }

        /* Autocomplete icon imgs: explicit configured dimensions */
        .htax-autocomplete-icon[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"] {
            width: <?php echo intval( $small_dims['width'] ); ?>px !important;
            height: <?php echo intval( $small_dims['height'] ); ?>px !important;
        }

        /* Pill containers - match icon border radius on left and right sides */
        .htax-pill[data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"] {
            border-radius: <?php echo esc_attr( $css ); ?> !important;
        }
        <?php endforeach; ?>
    </style>
    <?php
}

/**
 * Get icon display setting for a specific taxonomy
 *
 * @param string $taxonomy The taxonomy name (e.g., 'post_tag', 'category')
 * @return string The display setting: 'always', 'when_available', or 'never'
 */
function htax_get_icon_display_for_taxonomy( $taxonomy ) {
    $options = get_option( 'htax_settings', array() );
    $icon_settings = isset( $options['icon_settings'][$taxonomy] ) ? $options['icon_settings'][$taxonomy] : array();

    // Return taxonomy-specific display setting if exists, otherwise default
    return isset( $icon_settings['display'] ) ? $icon_settings['display'] : 'when_available';
}

/**
 * Transform a term link into a styled pill with icon/placeholder
 *
 * Takes a standard WordPress term link and transforms it into a pill-styled link
 * with optional icon or colored placeholder. Automatically applies border-radius
 * styling based on taxonomy icon settings.
 *
 * @param string $term_link The original term link HTML (from the_tags(), etc.)
 * @param WP_Term $term The term object
 * @param string $show_icons Display mode: 'always' (show placeholder if no icon),
 *                           'when_available' (icon only), or 'never' (text only)
 * @param bool $is_hidden Whether the term is marked as hidden
 * @return string Modified link with pill class, data-taxonomy attribute, and icon/placeholder
 *
 * @since 1.0.0
 */
function htax_add_icon_to_term_link( $term_link, $term, $show_icons = 'when_available', $is_hidden = false ) {
    // Get taxonomy-specific size name and dimensions
    $taxonomy_size_name = HyperTaxonomy_Term_Icons::get_size_name( $term->taxonomy, 'small' );
    $dimensions = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, 'small' );

    // Get icon data
    $icons = HyperTaxonomy_Term_Icons::get_instance();
    $icon_data = $icons->get_term_icon_data( $term->term_id, $taxonomy_size_name, false, $term->taxonomy );
    $has_icon = $icon_data['has_icon'];

    // Determine if we should show icon or placeholder
    $show_visual = false;
    $icon_html = '';

    if ( $has_icon ) {
        $show_visual = true;
        $icon_html = sprintf(
            '<img src="%s" class="htax-pill-icon" alt="" data-taxonomy="%s" width="%d" height="%d" />',
            esc_url( $icon_data['url'] ),
            esc_attr( $term->taxonomy ),
            intval( $dimensions['width'] ),
            intval( $dimensions['height'] )
        );
    } elseif ( 'always' === $show_icons ) {
        // Show placeholder
        $placeholder_color = get_term_meta( $term->term_id, '_htax_placeholder_color', true );
        if ( $placeholder_color ) {
            $show_visual = true;
            $icon_html = htax_get_placeholder_icon_html( $placeholder_color, $dimensions, $term->taxonomy );
        }
    }

    // Extract link attributes and content
    if ( preg_match( '/<a([^>]*)>(.*?)<\/a>/s', $term_link, $matches ) ) {
        $attributes = $matches[1];
        $link_text = $matches[2];

        // Add pill class and data-taxonomy to existing classes
        if ( preg_match( '/class=(["\'])([^"\']*)\1/', $attributes, $class_matches ) ) {
            $existing_classes = $class_matches[2];
            $new_classes = 'htax-pill ' . $existing_classes;
            if ( $is_hidden ) {
                $new_classes .= ' htax-hidden-term';
            }
            $attributes = preg_replace(
                '/class=["\'][^"\']*["\']/',
                'class="' . esc_attr( $new_classes ) . '" data-taxonomy="' . esc_attr( $term->taxonomy ) . '"',
                $attributes,
                1
            );
        } else {
            // No existing class attribute
            $classes = 'htax-pill';
            if ( $is_hidden ) {
                $classes .= ' htax-hidden-term';
            }
            $attributes .= ' class="' . esc_attr( $classes ) . '" data-taxonomy="' . esc_attr( $term->taxonomy ) . '"';
        }

        // Rebuild link with icon and wrapped text
        if ( $show_visual ) {
            return sprintf(
                '<a%s>%s<span class="htax-pill-text">%s</span></a>',
                $attributes,
                $icon_html,
                $link_text
            );
        } else {
            // No icon/placeholder, but still add pill styling
            return sprintf(
                '<a%s><span class="htax-pill-text">%s</span></a>',
                $attributes,
                $link_text
            );
        }
    }

    // Couldn't parse, return original
    return $term_link;
}

/**
 * Add CSS classes to sticky posts for theme styling
 */
function htax_filter_post_class( $classes, $class, $post_id ) {
    global $wp_query;

    if ( ! is_archive() || ! ( is_tag() || is_category() || is_tax() ) ) {
        return $classes;
    }

    // Check if this post is sticky for current term
    $term_id  = get_queried_object_id();
    $queried  = get_queried_object();
    $taxonomy = isset( $queried->taxonomy ) ? $queried->taxonomy : '';

    if ( ! $taxonomy || ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        return $classes;
    }

    $assignment_key = htax_get_sticky_assignment_key( $term_id );

    if ( get_post_meta( $post_id, $assignment_key, true ) ) {
        // Only on first page
        if ( ! is_paged() ) {
            // Only posts that were actually promoted (within max_sticky_posts) get the class.
            // htax_modify_archive_query_for_stickies stores the promoted list on the query.
            $promoted      = $wp_query->get( 'htax_sticky_posts' );
            $promoted_ids  = ! empty( $promoted ) ? array_map( 'intval', wp_list_pluck( $promoted, 'ID' ) ) : array();
            if ( empty( $promoted_ids ) || ! in_array( (int) $post_id, $promoted_ids, true ) ) {
                return $classes;
            }
            $classes[] = 'htax-sticky-post';
            $classes[] = 'htax-sticky-' . $taxonomy;
            $classes[] = 'htax-sticky-term-' . $term_id;
        }
    }

    return $classes;
}

/**
 * Get term icon URL
 *
 * @param WP_Term|int $term Term object or term ID.
 * @param string      $size Icon size.
 * @return string|false Icon URL or false if no icon.
 */
function htax_get_term_icon_url( $term, $size = 'htax-icon-small' ) {
    // Accept WP_Term or int
    $term_id = is_object( $term ) ? $term->term_id : $term;

    $icons = HyperTaxonomy_Term_Icons::get_instance();
    $data = $icons->get_term_icon_data( $term_id, $size, false );

    return $data['has_icon'] ? $data['url'] : false;
}

/**
 * Map an icon size string to a size type key ('small', 'medium', or 'large').
 *
 * @param string $size Icon size string (e.g. 'htax-icon-medium').
 * @return string Size type: 'small', 'medium', or 'large'.
 */
function htax_get_icon_size_type( $size ) {
    if ( strpos( $size, 'medium' ) !== false ) {
        return 'medium';
    }
    if ( strpos( $size, 'large' ) !== false ) {
        return 'large';
    }
    return 'small';
}

/**
 * Build the HTML for a colored placeholder icon span.
 *
 * @param string $placeholder_color Hex color string.
 * @param array  $dimensions        Associative array with 'width' and 'height' keys (pixels).
 * @param string $taxonomy          Taxonomy slug for the data-taxonomy attribute.
 * @return string HTML span element.
 */
function htax_get_placeholder_icon_html( $placeholder_color, $dimensions, $taxonomy ) {
    return sprintf(
        '<span class="htax-pill-placeholder" style="background-color: %s; width: %dpx; height: %dpx; display: inline-block;" data-taxonomy="%s"></span>',
        esc_attr( $placeholder_color ),
        intval( $dimensions['width'] ),
        intval( $dimensions['height'] ),
        esc_attr( $taxonomy )
    );
}

/**
 * Get term icon HTML
 *
 * @param WP_Term|int $term Term object or term ID.
 * @param string      $size Icon size.
 * @param array       $attr Additional HTML attributes.
 * @return string Icon HTML or empty string if no icon.
 */
function htax_get_term_icon_html( $term, $size = 'htax-icon-small', $attr = array() ) {
    // Accept WP_Term or int
    $term_obj = is_object( $term ) ? $term : get_term( $term );
    if ( ! $term_obj || is_wp_error( $term_obj ) ) {
        return '';
    }

    // Get taxonomy-specific size name
    $size_type = htax_get_icon_size_type( $size );

    $taxonomy_size_name = HyperTaxonomy_Term_Icons::get_size_name( $term_obj->taxonomy, $size_type );
    $dimensions = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term_obj->taxonomy, $size_type );

    $icons = HyperTaxonomy_Term_Icons::get_instance();
    $data = $icons->get_term_icon_data( $term_obj->term_id, $taxonomy_size_name, false, $term_obj->taxonomy );

    if ( $data['has_icon'] ) {
        $classes = isset( $attr['class'] ) ? $attr['class'] . ' htax-term-icon' : 'htax-term-icon';
        return sprintf(
            '<img src="%s" class="%s" alt="%s" data-taxonomy="%s" width="%d" height="%d" />',
            esc_url( $data['url'] ),
            esc_attr( $classes ),
            esc_attr( isset( $attr['alt'] ) ? $attr['alt'] : '' ),
            esc_attr( $term_obj->taxonomy ),
            intval( $dimensions['width'] ),
            intval( $dimensions['height'] )
        );
    }

    return '';
}

/**
 * Get the current term archive icon as a responsive <img> tag.
 *
 * Resolves the queried term automatically. Returns an empty string on non-term
 * archives (date, author, search, post-type) and on any term that has no icon
 * set — so the call requires no conditional wrapper in the theme template.
 *
 * The <img> carries a srcset built from all three registered htax sizes for the
 * term's taxonomy (small, medium, large), letting the browser pick the most
 * appropriate one. The default `sizes` value matches the requested size width;
 * pass a custom `sizes` string via $attr when the theme layout demands it.
 *
 * @param string $size Icon size shortcut: 'small', 'medium', or 'large'. Also
 *                     accepts full htax size names. Default 'large'.
 * @param array  $attr Additional <img> attributes. Recognized keys:
 *                     'alt'   — overrides default (term name).
 *                     'class' — merged with 'htax-archive-icon'.
 *                     'sizes' — overrides the default sizes attribute.
 *                     Any other key is passed through as-is.
 * @return string <img> HTML, or empty string if no icon or not a term archive.
 *
 * @since 1.0.0
 */
function htax_get_the_archive_icon( $size = 'large', $attr = array() ) {
    $term = get_queried_object();
    if ( ! ( $term instanceof WP_Term ) ) {
        return '';
    }

    $size_type = htax_get_icon_size_type( $size );
    $size_name = HyperTaxonomy_Term_Icons::get_size_name( $term->taxonomy, $size_type );

    $icons = HyperTaxonomy_Term_Icons::get_instance();
    $data  = $icons->get_term_icon_data( $term->term_id, $size_name, false, $term->taxonomy );

    if ( ! $data['has_icon'] ) {
        return '';
    }

    $attachment_id  = $data['attachment_id'];
    $primary_dims   = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, $size_type );

    // Build srcset from all three registered htax sizes.
    $srcset_parts = array();
    foreach ( array( 'small', 'medium', 'large' ) as $s ) {
        $s_name = HyperTaxonomy_Term_Icons::get_size_name( $term->taxonomy, $s );
        $dims   = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, $s );
        $url    = wp_get_attachment_image_url( $attachment_id, $s_name );
        if ( $url && $dims['width'] > 0 ) {
            $srcset_parts[] = esc_url( $url ) . ' ' . $dims['width'] . 'w';
        }
    }

    $classes = isset( $attr['class'] ) ? $attr['class'] . ' htax-archive-icon' : 'htax-archive-icon';
    $alt     = isset( $attr['alt'] )   ? $attr['alt']                          : $term->name;
    $sizes   = isset( $attr['sizes'] ) ? $attr['sizes']                        : $primary_dims['width'] . 'px';

    $img = sprintf(
        '<img src="%s" class="%s" alt="%s" data-taxonomy="%s" width="%d" height="%d"',
        esc_url( $data['url'] ),
        esc_attr( $classes ),
        esc_attr( $alt ),
        esc_attr( $term->taxonomy ),
        intval( $primary_dims['width'] ),
        intval( $primary_dims['height'] )
    );

    if ( ! empty( $srcset_parts ) ) {
        $img .= sprintf( ' srcset="%s"', implode( ', ', $srcset_parts ) );
        $img .= sprintf( ' sizes="%s"', esc_attr( $sizes ) );
    }

    foreach ( $attr as $key => $val ) {
        if ( in_array( $key, array( 'class', 'alt', 'sizes' ), true ) ) {
            continue;
        }
        $img .= ' ' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
    }

    $img .= ' />';

    return $img;
}

/**
 * Output the current term archive icon as a responsive <img> tag.
 *
 * Echo wrapper for htax_get_the_archive_icon(). See that function for full
 * parameter and return-value documentation.
 *
 * @param string $size Icon size shortcut. Default 'large'.
 * @param array  $attr Additional <img> attributes.
 *
 * @since 1.0.0
 */
function htax_the_archive_icon( $size = 'large', $attr = array() ) {
    echo htax_get_the_archive_icon( $size, $attr );
}

/**
 * Get term as a styled pill with optional icon
 *
 * Returns a single term as a styled pill with optional icon or placeholder.
 * Uses htax-pill class for consistent styling with border-radius from settings.
 *
 * @param WP_Term|int $term Term object or term ID
 * @param array $args {
 *     Optional. Array of arguments.
 *
 *     @type string $icon_size        Icon size. Default 'htax-icon-small'.
 *     @type bool   $link             Whether to make it a link. Default true.
 *     @type string $class            CSS class(es) for the wrapper. Default 'htax-pill'.
 *     @type bool   $show_placeholder Show colored placeholder if no icon. Default false.
 * }
 * @return string HTML for term pill
 *
 * @since 1.0.0
 */
function htax_get_term_pill( $term, $args = array() ) {
    $defaults = array(
        'icon_size' => 'htax-icon-small',
        'link' => true,
        'class' => 'htax-pill',
        'show_placeholder' => false,
    );

    $args = wp_parse_args( $args, $defaults );

    // Get term object if ID was passed
    $term_obj = is_object( $term ) ? $term : get_term( $term );
    if ( ! $term_obj || is_wp_error( $term_obj ) ) {
        return '';
    }

    $icon = htax_get_term_icon_html( $term_obj, $args['icon_size'] );

    // If no icon and placeholder requested, generate placeholder
    if ( empty( $icon ) && $args['show_placeholder'] ) {
        $placeholder_color = get_term_meta( $term_obj->term_id, '_htax_placeholder_color', true );
        if ( $placeholder_color ) {
            // Get dimensions for placeholder
            $size_type = htax_get_icon_size_type( $args['icon_size'] );

            $dimensions = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term_obj->taxonomy, $size_type );

            $icon = htax_get_placeholder_icon_html( $placeholder_color, $dimensions, $term_obj->taxonomy );
        }
    }

    if ( $args['link'] ) {
        $url = get_term_link( $term_obj );
        if ( is_wp_error( $url ) ) {
            $args['link'] = false;
        }
    }

    if ( $args['link'] ) {
        if ( ! empty( $icon ) ) {
            return sprintf(
                '<a href="%s" class="%s" data-taxonomy="%s">%s<span class="htax-pill-text">%s</span></a>',
                esc_url( $url ),
                esc_attr( $args['class'] ),
                esc_attr( $term_obj->taxonomy ),
                $icon,
                esc_html( $term_obj->name )
            );
        } else {
            return sprintf(
                '<a href="%s" class="%s" data-taxonomy="%s"><span class="htax-pill-text">%s</span></a>',
                esc_url( $url ),
                esc_attr( $args['class'] ),
                esc_attr( $term_obj->taxonomy ),
                esc_html( $term_obj->name )
            );
        }
    } else {
        if ( ! empty( $icon ) ) {
            return sprintf(
                '<span class="%s" data-taxonomy="%s">%s<span class="htax-pill-text">%s</span></span>',
                esc_attr( $args['class'] ),
                esc_attr( $term_obj->taxonomy ),
                $icon,
                esc_html( $term_obj->name )
            );
        } else {
            return sprintf(
                '<span class="%s" data-taxonomy="%s"><span class="htax-pill-text">%s</span></span>',
                esc_attr( $args['class'] ),
                esc_attr( $term_obj->taxonomy ),
                esc_html( $term_obj->name )
            );
        }
    }
}

/**
 * Display post terms as styled pills
 *
 * Convenience function to display all terms of a taxonomy for a post as pills.
 * Similar to the_tags() but with pill styling and icons.
 *
 * @param int $post_id Post ID
 * @param string $taxonomy Taxonomy name
 * @param array $args {
 *     Optional. Array of arguments.
 *
 *     @type string $before           HTML to output before the list. Default ''.
 *     @type string $after            HTML to output after the list. Default ''.
 *     @type string $separator        Separator between pills. Default ' '.
 *     @type bool   $show_placeholder Show colored placeholder if no icon. Default false.
 *     @type string $icon_size        Icon size. Default 'htax-icon-small'.
 * }
 *
 * @since 1.0.0
 */
function htax_the_term_pills( $post_id, $taxonomy, $args = array() ) {
    $defaults = array(
        'before' => '',
        'after' => '',
        'separator' => ' ',
        'show_placeholder' => false,
        'icon_size' => 'htax-icon-small',
    );

    $args = wp_parse_args( $args, $defaults );

    $terms = get_the_terms( $post_id, $taxonomy );
    if ( ! $terms || is_wp_error( $terms ) ) {
        return;
    }

    $output = $args['before'];

    $term_pills = array();
    foreach ( $terms as $term ) {
        $term_pills[] = htax_get_term_pill( $term, array(
            'show_placeholder' => $args['show_placeholder'],
            'icon_size' => $args['icon_size'],
        ) );
    }

    $output .= implode( $args['separator'], $term_pills );
    $output .= $args['after'];

    echo $output;
}


// ============================================================================
// ARCHIVE DISPLAY FUNCTIONS
// ============================================================================

/**
 * Add contributing terms to archive title
 *
 * Adds indicator of how many additional terms contribute to the current archive
 * via inclusions, synonyms, or redirects.
 */
function htax_filter_title( $title ) {
    // Check if showing contributing terms is enabled
    $options = get_option( 'htax_settings', array() );
    if ( empty( $options['show_contributing_terms'] ) ) {
        return $title;
    }

    $taxonomy = htax_get_enabled_archive_taxonomy();
    if ( false === $taxonomy ) {
        return $title;
    }

    $contributing_terms = htax_get_contributing_terms_for_current_page();

    if ( ! empty( $contributing_terms ) ) {
        // Get template from settings
        $template = isset( $options['contributing_terms_template'] ) ?
                    $options['contributing_terms_template'] :
                    __( ' (including: %s)', 'hypertaxonomy' );

        // Apply template with comma-separated list
        $title .= sprintf( $template, implode( ', ', array_map( 'esc_html', $contributing_terms ) ) );
    }

    return $title;
}

/**
 * Display term description below archive title if enabled
 */
function htax_display_archive_description() {
    $options = get_option( 'htax_settings', array() );
    if ( empty( $options['show_archive_description'] ) ) {
        return;
    }

    $taxonomy = htax_get_enabled_archive_taxonomy();
    if ( false === $taxonomy || ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        return;
    }

    $term_id = get_queried_object_id();
    $term    = get_queried_object();

    if ( '1' !== get_term_meta( $term_id, '_htax_show_description', true ) ) {
        return;
    }

    $description = '';
    if ( ! empty( $term->description ) ) {
        $allowed_html = htax_get_allowed_description_html();
        $description  = htax_process_term_description( $term->description, $allowed_html );
    }

    if ( '' === $description ) {
        return;
    }

    /**
     * Filters HTML appended inside the archive description block (after the description).
     *
     * The value is plugin-generated markup and is output as-is — like the_content filter
     * output, each callback is responsible for escaping what it adds. (Post-content kses
     * would strip the inline scripts and form inputs that appended widgets rely on.)
     *
     * @since 1.0.0
     * @param string  $append HTML accumulated so far.
     * @param WP_Term $term   The archive's term.
     */
    $append = (string) apply_filters( 'htax_archive_description_append', '', $term );
    ?>
    <div class="archive-description htax-archive-description">
        <?php echo wp_kses_post( $description ); ?>
        <?php echo $append; ?>
    </div>
    <?php
}

/**
 * Get allowed HTML tags for term descriptions
 */
function htax_get_allowed_description_html() {
    $allowed_tags = array(
        'strong' => array(),
        'em' => array(),
        'b' => array(),
        'i' => array(),
        'a' => array(
            'href' => array(),
            'title' => array(),
            'target' => array(),
            'rel' => array(),
        ),
        'code' => array(),
        'pre' => array(),
        'br' => array(),
        'p' => array(
            'class' => array(),
            'id' => array(),
        ),
        'ul' => array(
            'class' => array(),
        ),
        'ol' => array(
            'class' => array(),
        ),
        'li' => array(),
        'blockquote' => array(
            'cite' => array(),
            'class' => array(),
        ),
        'h3' => array(
            'class' => array(),
            'id' => array(),
        ),
        'h4' => array(
            'class' => array(),
            'id' => array(),
        ),
        'span' => array(
            'class' => array(),
            'style' => array(),
        ),
    );

    /**
     * Filter the allowed HTML tags for term descriptions
     *
     * @param array $allowed_tags Array of allowed HTML tags and attributes
     */
    return apply_filters( 'htax_allowed_description_html', $allowed_tags );
}

/**
 * Sanitize and process a term description for display.
 *
 * Strips disallowed HTML, converts bare line breaks to <br>, and runs shortcodes.
 *
 * @param string $description Raw term description.
 * @param array  $allowed_html Allowed HTML tags (from htax_get_allowed_description_html()).
 * @return string Processed description HTML.
 */
function htax_process_term_description( $description, $allowed_html ) {
    $description = wp_kses( $description, $allowed_html );

    if ( ! preg_match( '/<(p|div|ul|ol|blockquote|h[1-6])[^>]*>/i', $description ) ) {
        $description = nl2br( $description );
    }

    return do_shortcode( $description );
}

/**
 * Integrate description display with themes
 */
function htax_integrate_description_display() {
    // For block themes and FSE
    add_filter( 'render_block', 'htax_filter_query_title_block', 10, 2 );

    // Suppress native WordPress description when HT's block handles it, preventing
    // duplication. Runs at priority 5 so TC's see-also (priority 10) and suggest-form
    // (priority 20) hooks on get_the_archive_description bail via the global flag.
    add_filter( 'get_the_archive_description', 'htax_suppress_native_archive_description', 5 );

    // Fallback: Use loop_start hook
    add_action( 'loop_start', function( $query ) {
        if ( $query->is_main_query() && ( is_tag() || is_category() || is_tax() ) ) {
            static $displayed = false;
            if ( ! $displayed ) {
                htax_maybe_display_archive_description();
                $displayed = true;
            }
        }
    }, 5 );

    // Alternative hook for themes that don't use loop_start
    add_action( 'archive_description', 'htax_maybe_display_archive_description', 5 );
}

/**
 * Return empty string for get_the_archive_description when HT's own block handles it.
 *
 * Sets $htax_handling_archive_description so TC's hooks on this same filter can bail.
 *
 * @since 1.0.0
 * @param string $description
 * @return string
 */
function htax_suppress_native_archive_description( string $description ): string {
    global $htax_handling_archive_description;
    $options = get_option( 'htax_settings', array() );
    if ( empty( $options['show_archive_description'] ) ) {
        return $description;
    }
    $taxonomy = htax_get_enabled_archive_taxonomy();
    if ( false === $taxonomy || ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        return $description;
    }
    // HT takes over description display for this taxonomy. When the per-term checkbox
    // is on, set the flag so TC's get_the_archive_description hooks bail (TC uses
    // htax_archive_description_append inside HT's block instead). Either way, strip
    // the raw description text from the native area — HT's block or nothing shows it.
    if ( '1' === get_term_meta( get_queried_object_id(), '_htax_show_description', true ) ) {
        $htax_handling_archive_description = true;
    }
    return '';
}

/**
 * Conditionally display archive description
 */
function htax_maybe_display_archive_description() {
    $options = get_option( 'htax_settings', array() );
    if ( empty( $options['show_archive_description'] ) ) {
        return;
    }

    $taxonomy = htax_get_enabled_archive_taxonomy();
    if ( false === $taxonomy || ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        return;
    }

    $term_id = get_queried_object_id();
    $show_description = get_term_meta( $term_id, '_htax_show_description', true );

    if ( '1' === $show_description ) {
        htax_display_archive_description();
    }
}

/**
 * Filter Query Title block for FSE themes
 */
function htax_filter_query_title_block( $block_content, $block ) {
    if ( 'core/query-title' === $block['blockName'] ) {
        $taxonomy = htax_get_enabled_archive_taxonomy();
        if ( false === $taxonomy ) {
            return $block_content;
        }

        $term_id = get_queried_object_id();
        $term = get_queried_object();
        $show_description = get_term_meta( $term_id, '_htax_show_description', true );

        $options = get_option( 'htax_settings', array() );
        if ( ! empty( $options['show_archive_description'] ) && '1' === $show_description && ! empty( $term->description ) && htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
            $allowed_html = htax_get_allowed_description_html();
            $description = htax_process_term_description( $term->description, $allowed_html );

            $description_html = '<div class="htax-archive-description">' . $description . '</div>';

            // Inject description after the title block
            $block_content .= $description_html;
        }
    }

    return $block_content;
}



// ==============================================================================
// TERM LINKS — SEE ALSO
// ==============================================================================

