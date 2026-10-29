<?php
/**
 * Admin List Table Customizations
 * Handles columns, filters, and styling for both term and post list tables in wp-admin
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// ASSET ENQUEUING
// ============================================================================

// ============================================================================
// SORTABLE COLUMNS
// ============================================================================

/**
 * Register HyperTaxonomy columns as sortable for the term list table.
 *
 * WordPress makes a column sortable when it appears in the array returned by
 * this filter. The column key maps to the orderby value used in the URL.
 *
 * @param array $columns Already-registered sortable columns.
 * @return array
 */
function htax_register_sortable_columns( $columns ) {
    $screen = get_current_screen();
    if ( ! $screen ) {
        return $columns;
    }
    $taxonomy = $screen->taxonomy;

    if ( htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        $columns['htax_redirect'] = array( 'htax_redirect', true ); // DESC first: redirects present
    }
    if ( htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
        $columns['htax_icon'] = array( 'htax_icon', true ); // DESC first: icons present
    }
    if ( htax_taxonomy_has_feature( $taxonomy, 'visibility' ) ) {
        $columns['htax_hidden'] = array( 'htax_hidden', true ); // DESC first: hidden terms
    }
    if ( htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        $columns['htax_term_groups'] = 'htax_term_groups'; // ASC first: ungrouped then alphabetical
    }
    return $columns;
}

/**
 * Rewrite ORDER BY for HyperTaxonomy sortable columns.
 *
 * Scoped to term list table requests only. Uses EXISTS subqueries so all terms
 * appear regardless of whether they have the relevant meta or relationship —
 * the sort puts terms that "have" the attribute first (DESC) or last (ASC).
 *
 * @param array $clauses    SQL clause fragments from WP_Term_Query.
 * @param array $taxonomies Taxonomy slugs being queried.
 * @param array $_args      Original query args (unused).
 * @return array
 */
function htax_sortable_column_terms_clauses( $clauses, $taxonomies, $_args ) {
    global $wpdb;

    if ( ! htax_is_term_list_request() ) {
        return $clauses;
    }

    $orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
    $order    = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';
    $taxonomy = isset( $taxonomies[0] ) ? $taxonomies[0] : '';

    if ( ! in_array( $orderby, array( 'htax_redirect', 'htax_icon', 'htax_hidden', 'htax_term_groups' ), true ) ) {
        return $clauses;
    }

    switch ( $orderby ) {
        case 'htax_redirect':
            if ( ! htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
                return $clauses;
            }
            $rel_table          = $wpdb->prefix . 'htax_term_relationships';
            $clauses['orderby'] = "ORDER BY EXISTS(
                SELECT 1 FROM {$rel_table} rel
                WHERE rel.source_term_id = t.term_id
                  AND rel.relationship_type = 'redirect'
            ) {$order}, t.name ASC";
            $clauses['order']   = '';
            break;

        case 'htax_icon':
            if ( ! htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
                return $clauses;
            }
            $clauses['orderby'] = "ORDER BY EXISTS(
                SELECT 1 FROM {$wpdb->termmeta} tm
                WHERE tm.term_id = t.term_id
                  AND tm.meta_key = '_htax_icon_id'
                  AND tm.meta_value != ''
            ) {$order}, t.name ASC";
            $clauses['order']   = '';
            break;

        case 'htax_hidden':
            if ( ! htax_taxonomy_has_feature( $taxonomy, 'visibility' ) ) {
                return $clauses;
            }
            $clauses['orderby'] = "ORDER BY EXISTS(
                SELECT 1 FROM {$wpdb->termmeta} tm
                WHERE tm.term_id = t.term_id
                  AND tm.meta_key = '_htax_is_hidden'
                  AND tm.meta_value = '1'
            ) {$order}, t.name ASC";
            $clauses['order']   = '';
            break;

        case 'htax_term_groups':
            if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
                return $clauses;
            }
            // Sort key: GROUP_CONCAT of group names (sorted) for this term's member_of rows.
            // Empty string (no groups) sorts before any group name in ASC, after in DESC.
            $rel_table          = $wpdb->prefix . 'htax_term_relationships';
            $clauses['orderby'] = "ORDER BY COALESCE((
                SELECT GROUP_CONCAT(gt.name ORDER BY gt.name SEPARATOR '_')
                FROM {$rel_table} grp
                JOIN {$wpdb->terms} gt ON gt.term_id = grp.target_term_id
                WHERE grp.source_term_id = t.term_id
                  AND grp.relationship_type = 'member_of'
            ), '') {$order}, t.name ASC";
            $clauses['order']   = '';
            break;
    }

    return $clauses;
}
add_filter( 'terms_clauses', 'htax_sortable_column_terms_clauses', 10, 3 );

// ============================================================================
// ASSET ENQUEUING
// ============================================================================

/**
 * Enqueue list table assets for term list tables
 */
function htax_enqueue_table_assets( $hook ) {
    // Only load on edit-tags screen
    if ( 'edit-tags.php' !== $hook ) {
        return;
    }

    $current_screen = get_current_screen();
    $taxonomy = $current_screen ? $current_screen->taxonomy : '';

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return;
    }

    // Check if redirect feature is enabled for this taxonomy
    $has_redirects = htax_taxonomy_has_feature( $taxonomy, 'redirects' );
    $show_redirect_column = $has_redirects;

    // Check if icons are enabled for this taxonomy
    $has_icons = htax_taxonomy_has_feature( $taxonomy, 'icons' );

    // Always enqueue for visibility filter (which is always available)
    // Plus redirect filter (if enabled) and icon filter (if icons enabled)

    // Enqueue CSS
    wp_enqueue_style(
        'htax-tables-css',
        HTAX_PLUGIN_URL . 'admin/css/htax-tables.css',
        array(),
        HTAX_VERSION
    );

    // Enqueue JavaScript
    wp_enqueue_script(
        'htax-tables-js',
        HTAX_PLUGIN_URL . 'admin/js/htax-tables.js',
        array( 'jquery' ),
        HTAX_VERSION,
        true
    );

    // Get current filter values and sanitize
    $redirect_filter = isset( $_GET['htax_redirect_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['htax_redirect_filter'] ) ) : '';
    $icon_filter = isset( $_GET['htax_icon_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['htax_icon_filter'] ) ) : '';
    $visibility_filter = isset( $_GET['htax_visibility_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['htax_visibility_filter'] ) ) : '';

    // Build config for JavaScript
    $config = array(
        'taxonomy' => $taxonomy,
        'bulkAssignNonce' => wp_create_nonce( 'htax_bulk_assign_groups' ),
    );

    // Add redirect filter config if redirect column is enabled
    if ( $show_redirect_column ) {
        $config['redirectFilter'] = array(
            'selected' => array(
                'all'           => ( '' === $redirect_filter ) ? 'selected' : '',
                'standard'      => ( 'standard' === $redirect_filter ) ? 'selected' : '',
                'redirects'     => ( 'redirects' === $redirect_filter ) ? 'selected' : '',
                'auto_redirects' => ( 'auto_redirects' === $redirect_filter ) ? 'selected' : '',
                'targets'       => ( 'targets' === $redirect_filter ) ? 'selected' : '',
            ),
            'labels' => array(
                'all'           => __( 'All Terms', 'hypertaxonomy' ),
                'standard'      => __( 'Non-Redirects', 'hypertaxonomy' ),
                'redirects'     => __( 'Redirects', 'hypertaxonomy' ),
                'auto_redirects' => __( 'Auto-redirects', 'hypertaxonomy' ),
                'targets'       => __( 'Targets', 'hypertaxonomy' ),
            ),
        );
    }

    // Add icon filter config if icons are enabled
    if ( $has_icons ) {
        $config['iconFilter'] = array(
            'selected' => array(
                'all'           => ( '' === $icon_filter ) ? 'selected' : '',
                'with_icons'    => ( 'with_icons' === $icon_filter ) ? 'selected' : '',
                'without_icons' => ( 'without_icons' === $icon_filter ) ? 'selected' : '',
            ),
            'labels' => array(
                'all'           => __( 'All Icons', 'hypertaxonomy' ),
                'with_icons'    => __( 'With Icons', 'hypertaxonomy' ),
                'without_icons' => __( 'No Icons', 'hypertaxonomy' ),
            ),
        );
    }

    // Add visibility filter config if visibility feature is enabled
    if ( htax_taxonomy_has_feature( $taxonomy, 'visibility' ) ) {
        $config['visibilityFilter'] = array(
            'selected' => array(
                'all'     => ( '' === $visibility_filter ) ? 'selected' : '',
                'visible' => ( 'visible' === $visibility_filter ) ? 'selected' : '',
                'hidden'  => ( 'hidden' === $visibility_filter ) ? 'selected' : '',
            ),
            'labels' => array(
                'all'     => __( 'All Visibility', 'hypertaxonomy' ),
                'visible' => __( 'Visible', 'hypertaxonomy' ),
                'hidden'  => __( 'Hidden', 'hypertaxonomy' ),
            ),
        );
        $config['visibilityNonce']  = wp_create_nonce( 'htax-visibility' );
        $config['isHierarchical']   = is_taxonomy_hierarchical( $taxonomy );
    }

    // Add term groups filter if enabled for this taxonomy
    if ( htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        $current_group_filter = isset( $_GET['htax_group_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['htax_group_filter'] ) ) : '';

        // Build hierarchical group list - start with "Ungrouped" option
        // When no groups are selected, all groups are shown (Clear button handles this)
        $group_options = array(
            array(
                'value' => '-1',
                'label' => __( 'Ungrouped', 'hypertaxonomy' ),
                'selected' => ( '-1' === $current_group_filter ),
                'depth' => 0,
            ),
        );

        // Get all groups in hierarchical order
        $all_groups = get_terms( array(
            'taxonomy'   => 'group',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );

        // Add groups to options if any exist
        if ( ! empty( $all_groups ) && ! is_wp_error( $all_groups ) ) {
            // Build hierarchical structure
            $group_hierarchy = htax_get_group_display_tree( $all_groups, 0 );
            foreach ( $group_hierarchy as $group ) {
                $group_options[] = array(
                    'value' => $group->term_id,
                    'label' => html_entity_decode( $group->name, ENT_QUOTES, 'UTF-8' ),
                    'selected' => ( $current_group_filter === (string) $group->term_id ),
                    'depth' => isset( $group->depth ) ? $group->depth : 0,
                );
            }
        }

        // Always add the filter, even if there are no groups yet
        $config['groupFilter'] = array(
            'options' => $group_options,
        );

        // Groups Tag Groups owns the membership data for on this taxonomy —
        // Quick Edit's checklist disables these so an edit doesn't appear to
        // stick only to be reverted on the next import (htax_save_term_groups()
        // already discards a change to these regardless of this UI treatment).
        $locked_group_ids = array();
        if ( function_exists( 'htax_tag_groups_taxonomy_is_managed' )
            && htax_tag_groups_taxonomy_is_managed( $taxonomy ) && ! empty( $all_groups ) && ! is_wp_error( $all_groups )
        ) {
            foreach ( $all_groups as $g ) {
                if ( get_term_meta( $g->term_id, '_htax_tag_groups_source_id', true ) ) {
                    $locked_group_ids[] = $g->term_id;
                }
            }
        }
        $config['groupFilter']['lockedGroupIds'] = $locked_group_ids;
        $config['groupFilter']['lockedTitle']    = __( 'Managed by Tag Groups — edit this group\'s membership there instead.', 'hypertaxonomy' );
    }

    // Pass configuration to JavaScript
    wp_localize_script(
        'htax-tables-js',
        'htaxTablesConfig',
        $config
    );
}

/**
 * Build hierarchical group structure for filter display
 */
function htax_get_group_display_tree( $groups, $parent = 0, $depth = 0 ) {
    $result = array();

    foreach ( $groups as $group ) {
        if ( $group->parent == $parent ) {
            $group->depth = $depth;
            $result[] = $group;

            // Recursively add children
            $children = htax_get_group_display_tree( $groups, $group->term_id, $depth + 1 );
            if ( ! empty( $children ) ) {
                $result = array_merge( $result, $children );
            }
        }
    }

    return $result;
}
add_action( 'admin_enqueue_scripts', 'htax_enqueue_table_assets' );

// ============================================================================
// COLUMN HELPERS
// ============================================================================

/**
 * Insert a column into a columns array after a specific key.
 * If $after_key is not found, the new column is appended at the end.
 *
 * @param array  $columns    Existing columns array
 * @param string $after_key  Key of the column to insert after
 * @param string $new_key    Key for the new column
 * @param string $new_label  Label for the new column
 * @return array Updated columns array
 */
function htax_insert_column_after( $columns, $after_key, $new_key, $new_label ) {
    if ( ! isset( $columns[ $after_key ] ) ) {
        $columns[ $new_key ] = $new_label;
        return $columns;
    }
    $new_columns = array();
    foreach ( $columns as $key => $value ) {
        $new_columns[ $key ] = $value;
        if ( $key === $after_key ) {
            $new_columns[ $new_key ] = $new_label;
        }
    }
    return $new_columns;
}

// ============================================================================
// ICON COLUMN
// ============================================================================

/**
 * Add icon column to term list tables
 */
function htax_add_icon_column( $columns ) {
    $taxonomy = htax_get_current_taxonomy();
    if ( false === $taxonomy ) {
        return $columns;
    }

    // Check if icons are enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
        return $columns;
    }

    // Insert icon column after redirect column if it exists; otherwise append
    return htax_insert_column_after( $columns, 'htax_redirect', 'htax_icon', __( 'Icon', 'hypertaxonomy' ) );
}

/**
 * Render icon column content
 */
function htax_render_icon_column( $content, $column_name, $term_id ) {
    if ( 'htax_icon' !== $column_name ) {
        return $content;
    }

    $current_screen = get_current_screen();
    $taxonomy = $current_screen ? $current_screen->taxonomy : '';

    $icon_html = htax_get_term_icon_html( $term_id, 'small' );

    if ( $icon_html ) {
        return '<span class="htax-icon-cell">' . $icon_html . '</span>';
    }

    return '<span class="dashicons dashicons-format-image" style="opacity:0.2;font-size:20px;" title="' . esc_attr__( 'No icon', 'hypertaxonomy' ) . '"></span>';
}


// ============================================================================
// HIDDEN TERM COLUMN
// ============================================================================

/**
 * Add hidden column to term list tables
 */
function htax_add_hidden_column( $columns ) {
    $taxonomy = htax_get_current_taxonomy();
    if ( false === $taxonomy ) {
        return $columns;
    }

    // Check if visibility feature is enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'visibility' ) ) {
        return $columns;
    }

    // Insert visibility column after icon column if it exists; otherwise append
    return htax_insert_column_after( $columns, 'htax_icon', 'htax_hidden', __( 'Visibility', 'hypertaxonomy' ) );
}

/**
 * Display hidden column content
 */
function htax_display_hidden_column( $content, $column_name, $term_id ) {
    if ( 'htax_hidden' !== $column_name ) {
        return $content;
    }

    $is_hidden = get_term_meta( $term_id, '_htax_is_hidden', true );

    // Check whether any ancestor is hidden so Quick Edit can disable the checkbox.
    // Use a static cache to avoid repeated array_flip() across rows on the same page load.
    static $hidden_set = null;
    $parent_hidden = false;
    $term_obj      = get_term( $term_id );
    if ( $term_obj && ! is_wp_error( $term_obj ) && $term_obj->parent && is_taxonomy_hierarchical( $term_obj->taxonomy ) ) {
        if ( null === $hidden_set ) {
            $hidden_set = array_flip( htax_get_all_hidden_term_ids() );
        }
        $ancestor = (int) $term_obj->parent;
        while ( $ancestor ) {
            if ( isset( $hidden_set[ $ancestor ] ) ) {
                $parent_hidden = true;
                break;
            }
            $anc_obj  = get_term( $ancestor );
            $ancestor = ( $anc_obj && ! is_wp_error( $anc_obj ) ) ? (int) $anc_obj->parent : 0;
        }
    }

    // Hidden span carries current state for Quick Edit JS to read.
    $data_span = '<span class="htax-qe-vis-data"'
        . ' data-hidden="' . ( '1' === $is_hidden ? '1' : '0' ) . '"'
        . ' data-parent-hidden="' . ( $parent_hidden ? '1' : '0' ) . '"'
        . ' style="display:none;"></span>';

    if ( '1' === $is_hidden ) {
        return '<span class="dashicons dashicons-hidden" title="' .
                              esc_attr__( 'Hidden from public display', 'hypertaxonomy' ) .
                              '"></span>' . $data_span;
    }

    return '<span class="dashicons dashicons-visibility" style="opacity: 0.3;" title="' .
                          esc_attr__( 'Visible', 'hypertaxonomy' ) .
                          '"></span>' . $data_span;
}

// ============================================================================
// REDIRECT COLUMN
// ============================================================================

/**
 * Add redirect column to term list tables
 */
function htax_add_redirect_column( $columns ) {
    $taxonomy = htax_get_current_taxonomy();
    if ( false === $taxonomy ) {
        return $columns;
    }

    // Check if redirects feature is enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        return $columns;
    }

    // Check if our HyperTaxonomy Term Groups column or the Tag Groups plugin's column exists
    // (Tag Groups was rebranded "PublishPress Tag Groups" in Oct 2026; its column key is unchanged)
    $groups_column_key = null;
    $groups_column_names = array( 'htax_term_groups', 'term_group', 'tag_groups', 'term-group' );

    foreach ( $groups_column_names as $column_name ) {
        if ( isset( $columns[ $column_name ] ) ) {
            $groups_column_key = $column_name;
            break;
        }
    }

    // If groups column exists, insert our column after it
    if ( $groups_column_key ) {
        return htax_insert_column_after( $columns, $groups_column_key, 'htax_redirect', __( 'Redirect', 'hypertaxonomy' ) );
    }

    // Otherwise insert after the Count column ('posts'); append if not found
    // Note: The "Count" column header is internally keyed as 'posts' in WordPress
    return htax_insert_column_after( $columns, 'posts', 'htax_redirect', __( 'Redirect', 'hypertaxonomy' ) );
}

/**
 * Display redirect information in the column
 */
function htax_display_redirect_column( $content, $column_name, $term_id ) {
    if ( 'htax_redirect' !== $column_name ) {
        return $content;
    }

    // Get taxonomy from screen object, AJAX request, or term object
    $current_screen = get_current_screen();
    if ( $current_screen && isset( $current_screen->taxonomy ) ) {
        $taxonomy = $current_screen->taxonomy;
    } elseif ( isset( $_REQUEST['taxonomy'] ) ) {
        $taxonomy = sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) );
    } else {
        // Fallback: get from term object
        $term = get_term( $term_id );
        if ( $term && ! is_wp_error( $term ) ) {
            $taxonomy = $term->taxonomy;
        } else {
            return '—';
        }
    }

    if ( ! htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        return '—';
    }

    $redirect_term = htax_get_redirect_target( $term_id );
    $is_redirect = (bool) $redirect_term;

    if ( $is_redirect ) {
        // Check if this is an auto-redirect
        $is_auto_redirect = '1' === get_term_meta( $term_id, '_htax_auto_redirect', true );

        // Get the edit link for the target term
        $edit_link = get_edit_term_link( $redirect_term->term_id, $redirect_term->taxonomy );

        // Build the redirect indicator with icon for auto-redirects
        $indicator = '<span class="htax-redirect-indicator" style="color: #666;">↪ </span>';
        if ( $is_auto_redirect ) {
            $indicator = '<span class="dashicons dashicons-update" style="color: #2271b1; font-size: 16px; width: 16px; height: 16px; margin-right: 2px;" title="' . esc_attr__( 'Auto-redirect (created from taxonomy conversion)', 'hypertaxonomy' ) . '"></span>';
        }

        // Display indicator and linked term name
        return $indicator .
               '<a href="' . esc_url( $edit_link ) . '" style="font-style: italic;">' .
               esc_html( $redirect_term->name ) .
               '</a>';
    }

    // Check if this term is targeted by redirects (from any taxonomy, since redirects can be cross-taxonomy)
    $redirecting_terms = htax_get_ids_of_redirects_to( $term_id, null );
    if ( ! empty( $redirecting_terms ) ) {
        $count = count( $redirecting_terms );

        return '<span style="color: #2271b1;" title="' .
               esc_attr( sprintf( _n( '%d term redirects here', '%d terms redirect here', $count, 'hypertaxonomy' ), $count ) ) .
               '">' .
               sprintf( _n( '← %d', '← %d', $count, 'hypertaxonomy' ), $count ) .
               '</span>';
    }

    return '—'; // Em dash for empty cells
}

// ============================================================================
// LIST TABLE ROW CLASSES
// ============================================================================

/**
 * Add class to redirect term rows in admin list
 */
function htax_tag_row_class( $classes, $tag ) {
    $redirect_term = htax_get_redirect_target( $tag->term_id );

    if ( $redirect_term ) {
        $classes[] = 'htax-is-redirect';
    }

    return $classes;
}

// ============================================================================
// POST LIST TABLE MODIFICATIONS
// ============================================================================

/**
 * Add sticky indicators to term lists in admin post list table.
 *
 * Operates on the raw array of term link HTML strings passed by the
 * term_links-{taxonomy} filter, avoiding fragile implode/explode on ', '.
 *
 * @param string[] $term_list Array of term link HTML strings.
 * @param string   $taxonomy  The taxonomy slug.
 * @param int      $post_id   The post ID.
 * @return string[] Modified array of term link HTML strings.
 */
function htax_add_sticky_indicators( $term_list, $taxonomy, $post_id ) {
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        return $term_list;
    }

    // Collect sticky term IDs for this post+taxonomy by scanning postmeta once.
    $prefix          = '_htax_sticky_';
    $prefix_len      = strlen( $prefix );
    $all_post_meta   = get_post_meta( $post_id );
    $sticky_term_ids = array();

    foreach ( $all_post_meta as $meta_key => $values ) {
        if ( 0 !== strpos( $meta_key, $prefix ) ) {
            continue;
        }
        $t_id = (int) substr( $meta_key, $prefix_len );
        if ( $t_id <= 0 ) {
            continue;
        }
        $t = get_term( $t_id );
        if ( $t && ! is_wp_error( $t ) && $t->taxonomy === $taxonomy ) {
            $sticky_term_ids[] = $t_id;
        }
    }

    if ( empty( $sticky_term_ids ) ) {
        return $term_list;
    }

    // Add indicators to each term link HTML string in the array.
    foreach ( $term_list as $i => $term_html ) {
        if ( preg_match( '/tag_ID=(\d+)/', $term_html, $matches ) ||
             preg_match( '/category&amp;tag_ID=(\d+)/', $term_html, $matches ) ) {
            if ( in_array( (int) $matches[1], $sticky_term_ids ) ) {
                $term_list[ $i ] .= ' <span class="dashicons dashicons-admin-post" title="' .
                                    esc_attr__( 'Sticky for this term', 'hypertaxonomy' ) .
                                    '" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-top;"></span>';
            }
        }
    }

    return $term_list;
}

// ============================================================================
// TERM GROUPS COLUMN
// ============================================================================

/**
 * Add Term Groups column to term list tables
 *
 * @since 1.0
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function htax_add_term_groups_column( $columns ) {
    // Get taxonomy from screen object or AJAX request
    $screen = get_current_screen();
    if ( $screen && isset( $screen->taxonomy ) ) {
        $taxonomy = $screen->taxonomy;
    } elseif ( isset( $_REQUEST['taxonomy'] ) ) {
        $taxonomy = sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) );
    } else {
        return $columns;
    }

    // Skip for group taxonomy itself
    if ( 'group' === $taxonomy ) {
        return $columns;
    }

    // Check if feature enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        return $columns;
    }

    return htax_insert_column_after( $columns, 'posts', 'htax_term_groups', __( 'Groups', 'hypertaxonomy' ) );
}

/**
 * Populate Term Groups column content
 *
 * @since 1.0
 *
 * @param string $content     Column content.
 * @param string $column_name Column name.
 * @param int    $term_id     Term ID.
 * @return string Column content.
 */
function htax_term_groups_column_content( $content, $column_name, $term_id ) {
    if ( 'htax_term_groups' !== $column_name ) {
        return $content;
    }

    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        return $content;
    }

    // Get groups for this term
    $groups = htax_get_term_groups( $term_id );

    // Hidden span carries current group IDs for Quick Edit JS to read.
    $group_ids_str = empty( $groups ) ? '' : implode( ',', wp_list_pluck( $groups, 'term_id' ) );
    $data_span = '<span class="htax-qe-grp-data" data-group-ids="' . esc_attr( $group_ids_str ) . '" style="display:none;"></span>';

    if ( empty( $groups ) ) {
        return '—' . $data_span;
    }

    $group_links = array();
    foreach ( $groups as $group ) {
        $edit_link = get_edit_term_link( $group->term_id, 'group' );
        if ( $edit_link ) {
            $group_links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $edit_link ),
                esc_html( $group->name )
            );
        } else {
            $group_links[] = esc_html( $group->name );
        }
    }

    return implode( ', ', $group_links ) . $data_span;
}

// ============================================================================
// QUICK EDIT FIELDS
// ============================================================================

/**
 * Render HyperTaxonomy fields in the term Quick Edit row.
 *
 * Renders once per row, anchored to the most appropriate column:
 * - htax_term_groups when group_assignment is enabled.
 * - htax_hidden when only visibility is enabled.
 *
 * Values are populated from per-row data spans by htax-tables.js.
 * Saving reuses the existing edited_{taxonomy} hooks (htax_save_term_data
 * and htax_save_term_groups) which read the same $_POST field names.
 *
 * @param string $column_name Column name triggering this action.
 * @param string $screen      Admin screen identifier.
 * @param string $taxonomy    Current taxonomy slug.
 */
function htax_quick_edit_fields( $column_name, $screen, $taxonomy ) {
    $has_visibility = htax_taxonomy_has_feature( $taxonomy, 'visibility' );
    $has_groups     = htax_taxonomy_has_feature( $taxonomy, 'group_assignment' );

    // Render once, anchored to the most appropriate column.
    if ( $has_groups && 'htax_term_groups' === $column_name ) {
        // Groups column is the anchor when group_assignment is enabled.
    } elseif ( $has_visibility && ! $has_groups && 'htax_hidden' === $column_name ) {
        // Visibility column is the anchor when only visibility is enabled.
    } else {
        return;
    }
    ?>
    <fieldset class="inline-edit-col-right htax-quick-edit-col">
        <div class="inline-edit-col">
            <?php if ( $has_visibility ) : ?>
            <label class="alignleft" style="width:100%;padding-top:2px;">
                <?php /* Hidden zero ensures htax_is_hidden is in $_POST when the box is unchecked. */ ?>
                <input type="hidden" name="htax_is_hidden" value="0" />
                <input type="checkbox" name="htax_is_hidden" id="htax_qe_is_hidden" value="1" />
                <span class="checkbox-title"><?php esc_html_e( 'Hidden from public display', 'hypertaxonomy' ); ?></span>
            </label>
            <?php endif; ?>
            <?php if ( $has_groups ) : ?>
            <div class="htax-qe-groups-wrap<?php echo $has_visibility ? ' htax-qe-groups-with-vis' : ''; ?>">
                <span class="title"><?php esc_html_e( 'Term Groups', 'hypertaxonomy' ); ?></span>
                <?php /* Hidden zero ensures htax_groups[] is always in $_POST even when no boxes are checked. */ ?>
                <input type="hidden" name="htax_groups[]" value="0" />
                <?php /* Checklist is built and populated by htax-tables.js using htaxTablesConfig.groupFilter.options. */ ?>
                <div id="htax-qe-group-list" class="htax-qe-group-list"></div>
            </div>
            <?php endif; ?>
        </div>
    </fieldset>
    <?php
}
add_action( 'quick_edit_custom_box', 'htax_quick_edit_fields', 10, 3 );

// Hook into enabled taxonomies dynamically
add_action(
    'init',
    function() {
        $enabled_taxonomies = htax_get_enabled_taxonomies();

        foreach ( $enabled_taxonomies as $taxonomy ) {
            // Skip group taxonomy
            if ( 'group' === $taxonomy ) {
                continue;
            }

            // Check if group assignment is enabled for this taxonomy
            if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
                continue;
            }

            // Add column (priority 12 - before Redirect at 13, Icon at 14, and Visibility at 15)
            add_filter( "manage_edit-{$taxonomy}_columns", 'htax_add_term_groups_column', 12 );
            add_filter( "manage_{$taxonomy}_custom_column", 'htax_term_groups_column_content', 10, 3 );
        }
    },
    999
);
