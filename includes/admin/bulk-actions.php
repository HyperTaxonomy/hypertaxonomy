<?php
/**
 * Bulk Actions for Term List Tables
 * Adds custom bulk actions and handles their execution
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// REGISTER BULK ACTIONS
// ============================================================================

/**
 * Add custom bulk actions to term list tables
 */
function htax_add_bulk_actions( $bulk_actions ) {
    $taxonomy = htax_get_current_taxonomy();
    if ( false === $taxonomy ) {
        return $bulk_actions;
    }

    // Show actions whenever the feature is enabled — applying an action to terms
    // where it's already a no-op is harmless and avoids an extra get_terms() query.
    if ( htax_taxonomy_has_feature( $taxonomy, 'visibility' ) ) {
        $bulk_actions['htax_make_visible'] = __( 'Make Visible', 'hypertaxonomy' );
        $bulk_actions['htax_make_hidden']  = __( 'Make Hidden', 'hypertaxonomy' );
    }

    if ( htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
        $bulk_actions['htax_remove_icon'] = __( 'Remove Icon', 'hypertaxonomy' );
    }

    if ( htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        $bulk_actions['htax_assign_to_groups'] = __( 'Assign to Term Groups...', 'hypertaxonomy' );
    }

    return $bulk_actions;
}

// ============================================================================
// HANDLE BULK ACTIONS
// ============================================================================

/**
 * Handle custom bulk actions
 */
function htax_handle_bulk_actions( $redirect_to, $action, $term_ids ) {
    // Only process our custom actions
    if ( ! in_array( $action, array( 'htax_make_visible', 'htax_make_hidden', 'htax_remove_icon', 'htax_assign_to_groups' ) ) ) {
        return $redirect_to;
    }

    $taxonomy = htax_get_current_taxonomy();
    if ( false === $taxonomy ) {
        return $redirect_to;
    }

    // Check permissions - require edit_terms capability (editor and above)
    $taxonomy_obj = get_taxonomy( $taxonomy );
    if ( ! $taxonomy_obj || ! current_user_can( $taxonomy_obj->cap->edit_terms ) ) {
        return $redirect_to;
    }

    // Group assignment is handled via AJAX modal - nothing to do here.
    if ( 'htax_assign_to_groups' === $action ) {
        return $redirect_to;
    }

    $processed = 0;

    foreach ( $term_ids as $term_id ) {
        switch ( $action ) {
            case 'htax_make_visible':
            case 'htax_make_hidden':
                // Check manage_term_visibility capability
                if ( htax_taxonomy_has_feature( $taxonomy, 'visibility' ) &&
                     HyperTaxonomy_Capabilities::current_user_can( 'manage_term_visibility' ) ) {
                    $is_hidden = '1' === get_term_meta( $term_id, '_htax_is_hidden', true );
                    if ( 'htax_make_visible' === $action && $is_hidden ) {
                        // Skip silently if any ancestor is still hidden at processing time.
                        // (When parent and child are both selected, WP processes them in list
                        //  order so a parent above its child will be made visible first,
                        //  letting the child pass this check on its own turn.)
                        if ( is_taxonomy_hierarchical( $taxonomy ) ) {
                            static $bulk_hidden_set = null;
                            if ( null === $bulk_hidden_set ) {
                                $bulk_hidden_set = array_flip( htax_get_all_hidden_term_ids() );
                            }
                            $term_obj = get_term( $term_id );
                            $ancestor = ( $term_obj && ! is_wp_error( $term_obj ) ) ? (int) $term_obj->parent : 0;
                            $has_hidden_ancestor = false;
                            while ( $ancestor ) {
                                if ( isset( $bulk_hidden_set[ $ancestor ] ) ) {
                                    $has_hidden_ancestor = true;
                                    break;
                                }
                                $anc_obj  = get_term( $ancestor );
                                $ancestor = ( $anc_obj && ! is_wp_error( $anc_obj ) ) ? (int) $anc_obj->parent : 0;
                            }
                            if ( $has_hidden_ancestor ) {
                                break; // skip this term, continue foreach
                            }
                            // Remove from the set so a child processed later sees this term as visible.
                            unset( $bulk_hidden_set[ $term_id ] );
                        }
                        delete_term_meta( $term_id, '_htax_is_hidden' );
                        do_action( 'htax_term_visibility_changed', $term_id, $taxonomy );
                        if ( isset( $_POST['htax_cascade_visible'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['htax_cascade_visible'] ) ) ) {
                            htax_set_descendant_visibility( $term_id, $taxonomy, false );
                        }
                        $processed++;
                    } elseif ( 'htax_make_hidden' === $action && ! $is_hidden ) {
                        update_term_meta( $term_id, '_htax_is_hidden', '1' );
                        do_action( 'htax_term_visibility_changed', $term_id, $taxonomy );
                        htax_set_descendant_visibility( $term_id, $taxonomy, true );
                        $processed++;
                    }
                }
                break;

            case 'htax_remove_icon':
                // Check manage_term_icons capability
                if ( htax_taxonomy_has_feature( $taxonomy, 'icons' ) &&
                     HyperTaxonomy_Capabilities::current_user_can( 'manage_term_icons' ) ) {
                    $icon_id = get_term_meta( $term_id, '_htax_icon_id', true );
                    if ( $icon_id ) {
                        $other_users = get_terms( array(
                            'meta_key'   => '_htax_icon_id',
                            'meta_value' => (string) $icon_id,
                            'fields'     => 'ids',
                            'hide_empty' => false,
                            'exclude'    => array( $term_id ),
                            'number'     => 1,
                        ) );
                        if ( empty( $other_users ) || is_wp_error( $other_users ) ) {
                            wp_delete_attachment( $icon_id, true );
                        }
                        // Remove the meta
                        delete_term_meta( $term_id, '_htax_icon_id' );
                        // Clear icon cache
                        $icon_handler = HyperTaxonomy_Term_Icons::get_instance();
                        if ( method_exists( $icon_handler, 'clear_icon_cache' ) ) {
                            $icon_handler->clear_icon_cache( $term_id );
                        }
                        $processed++;
                    }
                }
                break;

            }
    }

    // Add query arg to show admin notice
    if ( $processed > 0 ) {
        $redirect_to = add_query_arg( array(
            'htax_bulk_action' => $action,
            'htax_processed'   => $processed,
            'htax_nonce'       => wp_create_nonce( 'htax_bulk_notice' ),
        ), $redirect_to );
    }

    return $redirect_to;
}

/**
 * Display admin notice after bulk action
 */
function htax_bulk_action_admin_notice() {
    // Check for success messages
    if ( ! isset( $_GET['htax_bulk_action'] ) || ! isset( $_GET['htax_processed'] ) ) {
        return;
    }

    if ( ! isset( $_GET['htax_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['htax_nonce'] ) ), 'htax_bulk_notice' ) ) {
        return;
    }

    $action = sanitize_key( wp_unslash( $_GET['htax_bulk_action'] ) );
    $processed = intval( $_GET['htax_processed'] );

    if ( 0 === $processed ) {
        return;
    }

    $message = '';

    switch ( $action ) {
        case 'htax_make_visible':
            $message = sprintf(
                _n( '%d term made visible.', '%d terms made visible.', $processed, 'hypertaxonomy' ),
                $processed
            );
            break;

        case 'htax_make_hidden':
            $message = sprintf(
                _n( '%d term hidden from public.', '%d terms hidden from public.', $processed, 'hypertaxonomy' ),
                $processed
            );
            break;

        case 'htax_remove_icon':
            $message = sprintf(
                _n( 'Icon removed from %d term.', 'Icons removed from %d terms.', $processed, 'hypertaxonomy' ),
                $processed
            );
            break;

        case 'htax_assign_to_groups':
            $message = sprintf(
                _n( 'Group assignments updated for %d term.', 'Group assignments updated for %d terms.', $processed, 'hypertaxonomy' ),
                $processed
            );
            break;

    }

    if ( $message ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }
}

// ============================================================================
// AJAX HANDLER FOR BULK GROUP ASSIGNMENT
// ============================================================================

/**
 * Handle AJAX request to update term group memberships.
 * add_groups are added to each term's existing groups; remove_groups are removed.
 * Groups not mentioned are left untouched.
 */
function htax_ajax_bulk_assign_groups() {
    // Verify nonce
    if ( ! check_ajax_referer( 'htax_bulk_assign_groups', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed.', 'hypertaxonomy' ) ) );
        return;
    }

    // Get parameters
    $term_ids      = isset( $_POST['term_ids'] )      ? array_map( 'intval', wp_unslash( (array) $_POST['term_ids'] ) )      : array();
    $add_groups    = isset( $_POST['add_groups'] )    ? array_map( 'intval', wp_unslash( (array) $_POST['add_groups'] ) )    : array();
    $remove_groups = isset( $_POST['remove_groups'] ) ? array_map( 'intval', wp_unslash( (array) $_POST['remove_groups'] ) ) : array();
    $taxonomy      = isset( $_POST['taxonomy'] )      ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) )       : '';

    // Validate
    if ( empty( $term_ids ) || empty( $taxonomy ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid request.', 'hypertaxonomy' ) ) );
        return;
    }

    if ( empty( $add_groups ) && empty( $remove_groups ) ) {
        wp_send_json_error( array( 'message' => __( 'Please select at least one group.', 'hypertaxonomy' ) ) );
        return;
    }

    // Check permissions
    $taxonomy_obj = get_taxonomy( $taxonomy );
    if ( ! $taxonomy_obj || ! current_user_can( $taxonomy_obj->cap->edit_terms ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to edit terms.', 'hypertaxonomy' ) ) );
        return;
    }

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to manage term relationships.', 'hypertaxonomy' ) ) );
        return;
    }

    // Check if group assignment feature is enabled
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        wp_send_json_error( array( 'message' => __( 'Group assignment is not enabled for this taxonomy.', 'hypertaxonomy' ) ) );
        return;
    }

    $processed = 0;

    foreach ( $term_ids as $term_id ) {
        $existing   = htax_get_term_group_ids( $term_id );
        $new_groups = array_values( array_unique( array_merge(
            array_diff( $existing, $remove_groups ),
            $add_groups
        ) ) );
        $result = htax_set_term_groups( $term_id, $new_groups );
        if ( ! is_wp_error( $result ) ) {
            $processed++;
        }
    }

    wp_send_json_success( array(
        'processed' => $processed,
        'message'   => sprintf(
            _n( '%d term updated.', '%d terms updated.', $processed, 'hypertaxonomy' ),
            $processed
        )
    ) );
}
add_action( 'wp_ajax_htax_bulk_assign_groups', 'htax_ajax_bulk_assign_groups' );
