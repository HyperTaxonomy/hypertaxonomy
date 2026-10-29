<?php
/**
 * Admin Term List Table Filters
 *
 * Backend filtering logic for term list tables in wp-admin.
 * These filters modify get_terms() queries based on URL parameters
 * set by the filter dropdowns in the admin UI.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================================================
// HELPER FUNCTIONS
// ==================================================

/**
 * Append a meta_query clause to a get_terms() args array.
 *
 * @param array $args       get_terms() args array (passed by reference)
 * @param array $meta_query Single meta_query clause to add
 */
function htax_push_meta_query_clause( &$args, $meta_query ) {
    if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
        $args['meta_query'][] = $meta_query;
    } else {
        $args['meta_query'] = array( $meta_query );
    }
}

/**
 * Check if the current request is from the term list page
 *
 * Determines if the current request originates from the edit-tags.php page
 * (term list table) or from an AJAX request made by that page. This is used
 * by filters to ensure they only apply to term list queries and not to other
 * queries that happen during admin page loads.
 *
 * @since 1.0.0
 *
 * @return bool True if request is from term list page or its AJAX calls, false otherwise
 *
 * @example
 * // In a get_terms filter callback
 * if ( ! htax_is_term_list_request() ) {
 *     return $args; // Don't apply filter
 * }
 */
function htax_is_term_list_request() {
    // Check if we're on the edit-tags.php page (not a custom page)
    if ( ! isset( $_GET['page'] ) && isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'edit-tags.php' ) !== false ) {
        return true;
    }

    return false;
}

/**
 * Merge term IDs into the exclude parameter of get_terms() args
 *
 * Handles merging an array of term IDs into the 'exclude' parameter of
 * get_terms() arguments, accounting for whether 'exclude' is already set
 * and whether it's an array or comma-separated string.
 *
 * @since 1.0.0
 *
 * @param array $args       get_terms() arguments array (passed by reference)
 * @param int[] $exclude_ids Array of term IDs to exclude
 * @return void Modifies $args by reference
 *
 * @example
 * $args = array();
 * htax_merge_term_ids_to_exclude( $args, array(1, 2, 3) );
 * // $args['exclude'] is now [1, 2, 3]
 *
 * $args = array('exclude' => array(5, 6));
 * htax_merge_term_ids_to_exclude( $args, array(1, 2) );
 * // $args['exclude'] is now [5, 6, 1, 2]
 */
function htax_merge_term_ids_to_exclude( &$args, $exclude_ids ) {
    if ( empty( $exclude_ids ) ) {
        return;
    }

    if ( isset( $args['exclude'] ) ) {
        if ( is_array( $args['exclude'] ) ) {
            $args['exclude'] = array_unique( array_merge( $args['exclude'], $exclude_ids ) );
        } elseif ( is_string( $args['exclude'] ) ) {
            $existing = array_map( 'intval', explode( ',', $args['exclude'] ) );
            $args['exclude'] = array_unique( array_merge( $existing, $exclude_ids ) );
        }
    } else {
        $args['exclude'] = $exclude_ids;
    }
}

/**
 * Intersect term IDs with the include parameter of get_terms() args
 *
 * Handles intersecting an array of term IDs with the 'include' parameter of
 * get_terms() arguments. If 'include' is already set, it intersects with it.
 * If not set, it sets 'include' to the provided IDs.
 *
 * @since 1.0.0
 *
 * @param array $args       get_terms() arguments array (passed by reference)
 * @param int[] $include_ids Array of term IDs to include
 * @return void Modifies $args by reference
 *
 * @example
 * $args = array();
 * htax_intersect_term_ids_to_include( $args, array(1, 2, 3) );
 * // $args['include'] is now [1, 2, 3]
 *
 * $args = array('include' => array(2, 3, 4));
 * htax_intersect_term_ids_to_include( $args, array(1, 2, 3) );
 * // $args['include'] is now [2, 3] (intersection)
 */
function htax_intersect_term_ids_to_include( &$args, $include_ids ) {
    if ( isset( $args['include'] ) && ! empty( $args['include'] ) ) {
        // Intersect with existing include
        if ( is_array( $args['include'] ) ) {
            $args['include'] = array_intersect( $args['include'], $include_ids );
        } elseif ( is_string( $args['include'] ) ) {
            $existing = array_map( 'intval', explode( ',', $args['include'] ) );
            $args['include'] = array_intersect( $existing, $include_ids );
        }
    } else {
        // Just use our IDs
        $args['include'] = $include_ids;
    }
}

// ==================================================
// FILTER FUNCTIONS
// ==================================================

/**
 * Filter terms by icon presence in admin
 *
 * @param array $args       Array of get_terms() arguments
 * @param array $taxonomies Array of taxonomy names
 * @return array Modified arguments
 */
function htax_filter_terms_by_icon( $args, $taxonomies ) {
    if ( ! is_admin() ) {
        return $args;
    }

    // Check for filter parameter
    if ( ! isset( $_GET['htax_icon_filter'] ) || empty( $_GET['htax_icon_filter'] ) ) {
        return $args;
    }

    // Make sure this is for the term list, not some other query
    if ( ! htax_is_term_list_request() ) {
        return $args;
    }

    $filter = sanitize_text_field( wp_unslash( $_GET['htax_icon_filter'] ) );

    // Build meta query based on filter
    $meta_query = array(
        'key'     => '_htax_icon_id',
        'compare' => ( 'with_icons' === $filter ) ? 'EXISTS' : 'NOT EXISTS',
    );

    htax_push_meta_query_clause( $args, $meta_query );

    return $args;
}

/**
 * Filter terms by visibility status in admin
 *
 * @param array $args       Array of get_terms() arguments
 * @param array $taxonomies Array of taxonomy names
 * @return array Modified arguments
 */
function htax_filter_terms_by_visibility( $args, $taxonomies ) {
    if ( ! is_admin() ) {
        return $args;
    }

    // Check for filter parameter
    if ( ! isset( $_GET['htax_visibility_filter'] ) || empty( $_GET['htax_visibility_filter'] ) ) {
        return $args;
    }

    // Make sure this is for the term list, not some other query
    if ( ! htax_is_term_list_request() ) {
        return $args;
    }

    $filter = sanitize_text_field( wp_unslash( $_GET['htax_visibility_filter'] ) );

    // Build meta query based on filter
    $meta_query = array(
        'key'     => '_htax_is_hidden',
        'value'   => '1',
        'compare' => ( 'hidden' === $filter ) ? '=' : '!=',
    );

    htax_push_meta_query_clause( $args, $meta_query );

    return $args;
}

/**
 * Filter terms by redirect status in admin
 *
 * @param array $args       Array of get_terms() arguments
 * @param array $taxonomies Array of taxonomy names
 * @return array Modified arguments
 */
function htax_filter_terms_by_redirect( $args, $taxonomies ) {
    if ( ! is_admin() ) {
        return $args;
    }

    // Check for filter parameter - this is the key check
    if ( ! isset( $_GET['htax_redirect_filter'] ) || empty( $_GET['htax_redirect_filter'] ) ) {
        return $args;
    }

    // Check if we're dealing with an HT-enabled taxonomy that has redirects
    if ( ! isset( $_GET['taxonomy'] ) || ! htax_taxonomy_has_feature( sanitize_key( wp_unslash( $_GET['taxonomy'] ) ), 'redirects' ) ) {
        return $args;
    }

    // Make sure this is for the term list, not some other query
    if ( ! htax_is_term_list_request() ) {
        return $args;
    }

    $filter = sanitize_text_field( wp_unslash( $_GET['htax_redirect_filter'] ) );
    $taxonomy = sanitize_key( wp_unslash( $_GET['taxonomy'] ) );

    global $wpdb;

    switch ( $filter ) {
        case 'standard':
            // Exclude only redirect sources; redirect targets are included (they are
            // valid terms in their own right and appear under "Redirect Targets" too).
            $redirect_ids = $wpdb->get_col(
                "SELECT DISTINCT source_term_id
                 FROM {$wpdb->prefix}htax_term_relationships
                 WHERE relationship_type = 'redirect'"
            );
            if ( htax_handle_db_error( 'filter_terms_by_redirect', array() ) !== false ) {
                return array();
            }

            $exclude_ids = array_map( 'intval', $redirect_ids );
            htax_merge_term_ids_to_exclude( $args, $exclude_ids );
            break;

        case 'redirects':
            // Show only terms that ARE redirects
            $redirect_ids = $wpdb->get_col(
                "SELECT DISTINCT source_term_id
                 FROM {$wpdb->prefix}htax_term_relationships
                 WHERE relationship_type = 'redirect'"
            );
            if ( htax_handle_db_error( 'filter_terms_by_redirect', array() ) !== false ) {
                return array();
            }

            if ( ! empty( $redirect_ids ) ) {
                $redirect_ids = array_map( 'intval', $redirect_ids );
                htax_intersect_term_ids_to_include( $args, $redirect_ids );
            } else {
                // No redirects exist, force empty result
                $args['include'] = array( 0 );
            }
            break;

        case 'auto_redirects':
            // Show only terms that ARE auto-redirects
            // First get all redirect term IDs from relationships table
            $redirect_ids = $wpdb->get_col(
                "SELECT DISTINCT source_term_id
                 FROM {$wpdb->prefix}htax_term_relationships
                 WHERE relationship_type = 'redirect'"
            );
            if ( htax_handle_db_error( 'filter_terms_by_redirect', array() ) !== false ) {
                return array();
            }

            if ( ! empty( $redirect_ids ) ) {
                // Now get only those that have the auto_redirect meta flag
                $placeholders = htax_sql_placeholders( count( $redirect_ids ) );
                $auto_redirect_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT term_id
                     FROM {$wpdb->termmeta}
                     WHERE term_id IN ({$placeholders})
                     AND meta_key = '_htax_auto_redirect'
                     AND meta_value = '1'",
                    ...$redirect_ids
                ) );
                if ( htax_handle_db_error( 'filter_terms_by_redirect', array() ) !== false ) {
                    return array();
                }

                if ( ! empty( $auto_redirect_ids ) ) {
                    $auto_redirect_ids = array_map( 'intval', $auto_redirect_ids );
                    htax_intersect_term_ids_to_include( $args, $auto_redirect_ids );
                } else {
                    // No auto-redirects, force empty result
                    $args['include'] = array( 0 );
                }
            } else {
                // No redirects at all, force empty result
                $args['include'] = array( 0 );
            }
            break;

        case 'targets':
            // Get all term IDs that are targets of redirects
            $target_ids = $wpdb->get_col(
                "SELECT DISTINCT target_term_id
                 FROM {$wpdb->prefix}htax_term_relationships
                 WHERE relationship_type = 'redirect'"
            );
            if ( htax_handle_db_error( 'filter_terms_by_redirect', array() ) !== false ) {
                return array();
            }

            if ( ! empty( $target_ids ) ) {
                $target_ids = array_map( 'intval', $target_ids );

                // Get ALL term IDs for this taxonomy
                $all_term_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT t.term_id
                     FROM {$wpdb->terms} t
                     INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = %s",
                    $taxonomy
                ) );
                if ( htax_handle_db_error( 'filter_terms_by_redirect', array() ) !== false ) {
                    return array();
                }

                // Exclude everything that's NOT a target
                $exclude_ids = array_diff( $all_term_ids, $target_ids );
                htax_merge_term_ids_to_exclude( $args, $exclude_ids );
            } else {
                // No redirect targets exist, force empty result by excluding everything
                $args['include'] = array( 0 );
            }
            break;
    }

    return $args;
}

/**
 * Filter terms by term group
 *
 * @param array $args       Array of get_terms() arguments
 * @param array $taxonomies Array of taxonomy names
 * @return array Modified arguments
 */
function htax_filter_terms_by_group( $args, $taxonomies ) {
    if ( ! is_admin() ) {
        return $args;
    }

    // Never filter the 'group' taxonomy itself
    if ( in_array( 'group', (array) $taxonomies, true ) ) {
        return $args;
    }

    // Check for filter parameter
    if ( ! isset( $_GET['htax_group_filter'] ) ) {
        return $args;
    }

    // Make sure this is for the term list, not some other query
    if ( ! htax_is_term_list_request() ) {
        return $args;
    }

    // Get current taxonomy
    $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
    if ( empty( $taxonomy ) || 'group' === $taxonomy ) {
        return $args;
    }

    // Check if this taxonomy has Term Groups feature enabled
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        return $args;
    }

    // Get the filter value (can be comma-separated for multiple groups)
    $group_filter = isset( $_GET['htax_group_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['htax_group_filter'] ) ) : '';

    // Empty string means "All Term Groups" - no filtering
    if ( '' === $group_filter ) {
        return $args;
    }

    global $wpdb;
    $relationships_table = $wpdb->prefix . 'htax_term_relationships';

    // Parse multiple group IDs (comma-separated)
    $selected_groups = array_map( 'trim', explode( ',', $group_filter ) );
    $selected_groups = array_filter( $selected_groups, function( $id ) {
        return '' !== $id;
    } );

    // Special case: "Ungrouped" (-1)
    if ( in_array( '-1', $selected_groups, true ) ) {
        // Get all term IDs that ARE in groups using member_of relationships
        $grouped_term_ids = $wpdb->get_col(
            "SELECT DISTINCT source_term_id FROM {$relationships_table}
            WHERE target_taxonomy = 'group' AND relationship_type = 'member_of'"
        );

        if ( htax_handle_db_error( 'filter_terms_by_group', array() ) !== false ) {
            return $args;
        }

        // Exclude all terms that are in any group
        if ( ! empty( $grouped_term_ids ) ) {
            $grouped_term_ids = array_map( 'intval', $grouped_term_ids );
            htax_merge_term_ids_to_exclude( $args, $grouped_term_ids );
        }
    } else {
        // Filter by one or more specific groups (OR logic - show terms in ANY selected group)
        $all_group_ids = array();

        // For each selected group, get it and all its descendants.
        // htax_get_group_hierarchy() already includes the input IDs themselves.
        foreach ( $selected_groups as $group_filter_id ) {
            $group_id = intval( $group_filter_id );
            if ( $group_id > 0 ) {
                $all_group_ids = array_merge( $all_group_ids, htax_get_group_hierarchy( array( $group_id ) ) );
            }
        }

        // Remove duplicates (in case of overlapping hierarchies)
        $all_group_ids = array_unique( $all_group_ids );

        if ( ! empty( $all_group_ids ) ) {
            // Get all term IDs that belong to ANY of these groups using member_of relationships
            $placeholders = htax_sql_placeholders( count( $all_group_ids ) );
            $term_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT source_term_id FROM {$relationships_table}
                WHERE target_term_id IN ({$placeholders})
                AND target_taxonomy = 'group'
                AND relationship_type = 'member_of'",
                ...$all_group_ids
            ) );

            if ( htax_handle_db_error( 'filter_terms_by_group', array() ) !== false ) {
                return $args;
            }

            // Only include terms from these groups
            if ( ! empty( $term_ids ) ) {
                $term_ids = array_map( 'intval', $term_ids );

                // Use 'include' parameter to limit results
                if ( isset( $args['include'] ) && ! empty( $args['include'] ) ) {
                    // If include is already set and not empty, intersect with our results
                    if ( is_array( $args['include'] ) ) {
                        $args['include'] = array_intersect( $args['include'], $term_ids );
                    } elseif ( is_string( $args['include'] ) ) {
                        $existing = array_map( 'intval', explode( ',', $args['include'] ) );
                        $args['include'] = array_intersect( $existing, $term_ids );
                    }
                } else {
                    // If include is not set or is empty, just use our term IDs
                    $args['include'] = $term_ids;
                }
            } else {
                // No terms in these groups, force empty result
                $args['include'] = array( 0 );
            }
        } else {
            // No valid groups selected, force empty result
            $args['include'] = array( 0 );
        }
    }

    return $args;
}

