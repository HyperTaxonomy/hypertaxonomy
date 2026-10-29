<?php
/**
 * Term Groups Core Functions
 *
 * Core functionality for the built-in Term Groups taxonomy. Provides functions
 * for working with included_groups and container_groups metadata, which integrate
 * with the term inclusion/container system.
 *
 * @package HyperTaxonomy
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get all terms from specified groups
 *
 * Mimics how WordPress handles get_terms() with 'child_of' parameter
 * Automatically expands group hierarchy like categories do
 *
 * @since 1.0
 *
 * @param array  $group_ids Array of group term IDs.
 * @param string $taxonomy  Taxonomy to get terms from. Default 'post_tag'.
 * @return WP_Term[] Array of WP_Term objects indexed by term_id.
 */
function htax_get_terms_in_groups( $group_ids, $taxonomy = 'post_tag' ) {
    if ( empty( $group_ids ) ) {
        return array();
    }

    // Expand parent groups to their children using native WordPress API.
    $expanded_group_ids = htax_get_group_hierarchy( $group_ids );

    // Each group is cached separately for targeted invalidation.
    // Separate cached groups from uncached so we can batch-query the latter.
    $all_terms    = array(); // Indexed by term_id to handle overlaps.
    $uncached_ids = array();

    foreach ( $expanded_group_ids as $group_id ) {
        $cache_key = 'htax_grp_' . $group_id . '_' . $taxonomy;
        $cached    = htax_get_transient( $cache_key );

        if ( false !== $cached ) {
            $all_terms = $all_terms + $cached;
        } else {
            $uncached_ids[] = $group_id;
        }
    }

    if ( empty( $uncached_ids ) ) {
        return $all_terms;
    }

    // Batch-fetch all relationship rows for uncached groups in one query,
    // then split results by group, cache individually, and merge.
    global $wpdb;

    $table_name   = $wpdb->prefix . 'htax_term_relationships';
    $placeholders = htax_sql_placeholders( count( $uncached_ids ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT source_term_id, target_term_id
         FROM {$table_name}
         WHERE target_term_id IN ($placeholders)
         AND target_taxonomy = 'group'
         AND source_taxonomy = %s
         AND relationship_type = 'member_of'",
        array_merge( $uncached_ids, array( $taxonomy ) )
    ) );

    // Group term IDs by their source group.
    $term_ids_by_group = array_fill_keys( $uncached_ids, array() );
    $all_term_ids      = array();

    if ( ! empty( $rows ) ) {
        foreach ( $rows as $row ) {
            $gid = (int) $row->target_term_id;
            $tid = (int) $row->source_term_id;
            if ( isset( $term_ids_by_group[ $gid ] ) ) {
                $term_ids_by_group[ $gid ][] = $tid;
            }
            $all_term_ids[] = $tid;
        }
    }

    // Batch-fetch all WP_Term objects needed across all uncached groups at once.
    $term_objects = array();

    if ( ! empty( $all_term_ids ) ) {
        $fetched = get_terms( array(
            'taxonomy'   => $taxonomy,
            'include'    => array_unique( $all_term_ids ),
            'hide_empty' => false,
        ) );

        if ( ! is_wp_error( $fetched ) ) {
            foreach ( $fetched as $term ) {
                $term_objects[ $term->term_id ] = $term;
            }
        }
    }

    // Build per-group result sets, cache each individually, then merge.
    foreach ( $uncached_ids as $group_id ) {
        $group_term_objects = array();

        foreach ( $term_ids_by_group[ $group_id ] as $term_id ) {
            if ( isset( $term_objects[ $term_id ] ) ) {
                $group_term_objects[ $term_id ] = $term_objects[ $term_id ];
            }
        }

        $cache_key = 'htax_grp_' . $group_id . '_' . $taxonomy;
        htax_set_transient( $cache_key, $group_term_objects, htax_get_cache_duration( 'relationship' ) );

        $all_terms = $all_terms + $group_term_objects;
    }

    return $all_terms;
}

/**
 * Get terms from included groups for a term.
 *
 * Reads includes_group relationships from database and expands to terms.
 * Mimics WordPress behavior - returns empty array if feature disabled or no groups set.
 *
 * Returns an empty array when the term is a redirect unless $include_redirect_data is true.
 *
 * @since 1.0
 *
 * @param int    $term_id              Term ID.
 * @param string $taxonomy             Term's taxonomy.
 * @param bool   $include_redirect_data When true, return data even if the term is a redirect.
 * @return WP_Term[] Array of WP_Term objects from included groups, indexed by term_id.
 */
function htax_get_terms_from_included_groups( $term_id, $taxonomy, bool $include_redirect_data = false ) {
    // Check if feature is enabled
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
        return array();
    }

    if ( ! $include_redirect_data && htax_is_redirect( $term_id )
         && htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        return array();
    }

    // Get included group IDs from database
    $group_ids = htax_get_direct_rel_group_ids( $term_id, 'includes' );

    if ( empty( $group_ids ) ) {
        return array();
    }

    // Get terms from these groups (automatically expands hierarchy)
    return htax_get_terms_in_groups( $group_ids, $taxonomy );
}

/**
 * Validate and filter group relationships
 *
 * Validates that group relationships don't create invalid configurations:
 * - Hierarchy conflicts: For hierarchical taxonomies with included groups, can't include
 *   groups that contain ancestor or descendant terms
 *
 * Note: Self-relationships are automatically filtered in JOIN queries via exclusion list.
 *
 * @since 1.0
 *
 * @param int    $term_id           Term ID being validated.
 * @param array  $group_ids         Array of group IDs to validate.
 * @param string $taxonomy          Taxonomy name.
 * @param string $relationship_type Type of relationship: 'included' or 'container'. Default 'included'.
 * @return array {
 *     Validation result.
 *
 *     @type array $valid_groups   Group IDs that passed validation.
 *     @type array $invalid_groups Associative array of group_id => reason for invalid groups.
 * }
 */
function htax_validate_and_filter_group_relationships( $term_id, $group_ids, $taxonomy, $relationship_type = 'included' ) {
    $result = array(
        'valid_groups'   => array(),
        'invalid_groups' => array(), // Format: group_id => reason
    );

    if ( empty( $group_ids ) ) {
        return $result;
    }

    $group_ids = array_unique( array_map( 'intval', $group_ids ) );

    // Check if taxonomy is hierarchical
    $is_hierarchical = is_taxonomy_hierarchical( $taxonomy );

    foreach ( $group_ids as $group_id ) {
        $is_valid = true;
        $reason   = '';

        // Get all terms in this group (with parent expansion)
        $group_terms = htax_get_terms_in_groups( array( $group_id ), $taxonomy );

        // NOTE: Self-relationships are automatically filtered in JOIN queries via exclusion list.

        // CHECK 1: Hierarchy conflicts (only for hierarchical taxonomies and included groups)
        if ( $is_valid && $is_hierarchical && 'included' === $relationship_type ) {
            // Check if any group members have parent-child relationship with this term
            foreach ( $group_terms as $member_term ) {
                // Check if member is parent or child of this term
                if ( term_is_ancestor_of( $term_id, $member_term->term_id, $taxonomy ) ||
                     term_is_ancestor_of( $member_term->term_id, $term_id, $taxonomy ) ) {
                    $is_valid = false;
                    $reason   = 'hierarchy-conflict';
                    break;
                }
            }
        }

        if ( $is_valid ) {
            $result['valid_groups'][] = $group_id;
        } else {
            $result['invalid_groups'][ $group_id ] = $reason;
        }
    }

    return $result;
}
