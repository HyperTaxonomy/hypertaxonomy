<?php
/**
 * Term Groups Functions
 *
 * Core functions for managing term groups and term-to-group relationships.
 *
 * @package HyperTaxonomy
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get groups assigned to a term
 *
 * @since 1.0
 *
 * @param int $term_id Term ID.
 * @return WP_Term[] Array of group WP_Term objects, ordered by name. Empty array on failure.
 */
function htax_get_term_groups( $term_id ): array {
    global $wpdb;

    // Get term to verify it exists and check if feature enabled
    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return array();
    }

    // Check if feature enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $term->taxonomy, 'group_assignment' ) ) {
        return array();
    }

    // Check cache first
    $cache_key = 'htax_groups_' . $term_id;
    $cached_group_ids = htax_get_transient( $cache_key );

    if ( false !== $cached_group_ids && ! is_array( $cached_group_ids ) ) {
        htax_delete_transient( $cache_key );
        $cached_group_ids = false;
    }

    if ( false === $cached_group_ids ) {
        // Get group IDs from wp_htax_term_relationships table using member_of relationships
        $table_name = $wpdb->prefix . 'htax_term_relationships';
        $group_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT target_term_id FROM {$table_name}
            WHERE source_term_id = %d
            AND target_taxonomy = 'group'
            AND relationship_type = 'member_of'",
            $term_id
        ) );

        // Cache result (eager caching - no TTL)
        $cached_group_ids = ! empty( $group_ids ) ? array_map( 'intval', $group_ids ) : array();
        htax_set_transient( $cache_key, $cached_group_ids, 0 ); // No TTL — explicit invalidation only
    }

    if ( empty( $cached_group_ids ) ) {
        return array();
    }

    // Get group term objects
    $groups = get_terms( array(
        'taxonomy'   => 'group',
        'include'    => $cached_group_ids,
        'orderby'    => 'name',
        'order'      => 'ASC',
        'hide_empty' => false,
    ) );

    return is_wp_error( $groups ) ? array() : $groups;
}

/**
 * Get IDs of groups assigned to a term
 *
 * @since 1.0
 *
 * @param int $term_id Term ID.
 * @return int[] Array of group term IDs. Empty array on failure or if term has no groups.
 */
function htax_get_term_group_ids( int $term_id ): array {
    global $wpdb;

    // Get term to verify it exists and check if feature enabled
    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return array();
    }

    // Check if feature enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $term->taxonomy, 'group_assignment' ) ) {
        return array();
    }

    // Check cache first
    $cache_key = 'htax_groups_' . $term_id;
    $cached_group_ids = htax_get_transient( $cache_key );

    if ( false !== $cached_group_ids && ! is_array( $cached_group_ids ) ) {
        htax_delete_transient( $cache_key );
        $cached_group_ids = false;
    }

    if ( false === $cached_group_ids ) {
        // Get group IDs from wp_htax_term_relationships table using member_of relationships
        $table_name = $wpdb->prefix . 'htax_term_relationships';
        $group_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT target_term_id FROM {$table_name}
            WHERE source_term_id = %d
            AND target_taxonomy = 'group'
            AND relationship_type = 'member_of'",
            $term_id
        ) );

        // Cache result (eager caching - no TTL)
        $cached_group_ids = ! empty( $group_ids ) ? array_map( 'intval', $group_ids ) : array();
        htax_set_transient( $cache_key, $cached_group_ids, 0 ); // No TTL — explicit invalidation only
    }

    return $cached_group_ids;
}


/**
 * Expand parent groups to include all descendants
 *
 * Given an array of group IDs, returns all those groups plus any child groups.
 * Uses WordPress native child_of parameter to get all descendants efficiently.
 *
 * @since 1.0
 *
 * @param int[] $group_ids Group term IDs.
 * @return int[] Expanded array of group IDs (including original IDs).
 */
function htax_get_group_hierarchy( $group_ids ) {
    $group_ids = htax_sanitize_term_ids( $group_ids );

    if ( empty( $group_ids ) ) {
        return array();
    }

    // Cache per unique input set to avoid redundant tree traversal within the same request
    static $result_cache = array();

    $cache_key = implode( ',', $group_ids );
    if ( isset( $result_cache[ $cache_key ] ) ) {
        return $result_cache[ $cache_key ];
    }

    // _get_term_hierarchy() returns [parent_id => [child_id, ...]] from WP's object cache
    // (populated on first access via get_terms() internally — no extra DB query after that).
    $hierarchy = _get_term_hierarchy( 'group' );

    if ( empty( $hierarchy ) ) {
        $result_cache[ $cache_key ] = $group_ids;
        return $group_ids;
    }

    $expanded = $group_ids;
    $to_check = $group_ids;
    $checked  = array();

    while ( ! empty( $to_check ) ) {
        $current_id = array_shift( $to_check );
        if ( isset( $checked[ $current_id ] ) ) {
            continue;
        }
        $checked[ $current_id ] = true;

        foreach ( $hierarchy[ $current_id ] ?? array() as $child_id ) {
            if ( ! isset( $checked[ $child_id ] ) ) {
                $expanded[] = $child_id;
                $to_check[] = $child_id;
            }
        }
    }

    $result                     = array_unique( $expanded );
    $result_cache[ $cache_key ] = $result;
    return $result;
}


/**
 * Set groups for a term
 *
 * Replaces all group assignments for a term with the specified groups. The term's
 * taxonomy is derived from the term (term IDs are globally unique).
 *
 * @since 1.0
 *
 * @param int    $term_id   Term ID.
 * @param int[]  $group_ids Array of group term IDs.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function htax_set_term_groups( $term_id, $group_ids ) {
    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        return new WP_Error( 'invalid_term', __( 'Invalid term.', 'hypertaxonomy' ) );
    }
    $term_id  = (int) $term->term_id;
    $taxonomy = $term->taxonomy;

    // Term Groups use native WordPress parent-child hierarchy, not member_of relationships.
    if ( 'group' === $taxonomy ) {
        return new WP_Error(
            'group_self_assignment',
            __( 'Term Groups cannot be assigned to other Term Groups via group membership. Use the native WordPress parent field instead.', 'hypertaxonomy' )
        );
    }

    // Check if feature enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        return new WP_Error(
            'group_assignment_disabled',
            __( 'Group assignment is not enabled for this taxonomy.', 'hypertaxonomy' )
        );
    }

    global $wpdb;

    // Sanitize group IDs
    $group_ids = htax_sanitize_term_ids( $group_ids );

    // Validate that all IDs are actual group terms — drop phantom or wrong-taxonomy IDs.
    if ( ! empty( $group_ids ) ) {
        $valid_ids = get_terms( array(
            'taxonomy'   => 'group',
            'include'    => $group_ids,
            'fields'     => 'ids',
            'hide_empty' => false,
        ) );
        $group_ids = is_wp_error( $valid_ids ) ? array() : array_map( 'intval', $valid_ids );
    }

    // Get old groups for comparison
    $old_groups = htax_get_term_group_ids( $term_id );

    // Early return when groups haven't changed — avoids unnecessary DB writes, action
    // fires, and the entire cross-term cache invalidation cascade.
    $sorted_new = $group_ids;
    $sorted_old = $old_groups;
    sort( $sorted_new );
    sort( $sorted_old );
    if ( $sorted_new === $sorted_old ) {
        return true;
    }

    // Use wp_htax_term_relationships table with member_of relationships
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Delete old group assignments and insert new ones atomically.
    $wpdb->query( 'START TRANSACTION' );

    $wpdb->delete(
        $table_name,
        array(
            'source_term_id'    => $term_id,
            'relationship_type' => 'member_of',
            'target_taxonomy'   => 'group',
        ),
        array( '%d', '%s', '%s' )
    );

    if ( $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'htax_db_error', __( 'Database error saving group assignments; changes rolled back.', 'hypertaxonomy' ) );
    }

    // Insert new group assignments as member_of relationships
    if ( ! empty( $group_ids ) ) {
        $values = array();
        foreach ( $group_ids as $group_id ) {
            $values[] = $wpdb->prepare(
                '(%d, %d, %s, %s, %s)',
                $term_id,
                $group_id,
                'member_of',
                $taxonomy,
                'group'
            );
        }
        $wpdb->query( "INSERT IGNORE INTO {$table_name}
            (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
            VALUES " . implode( ',', $values ) );

        if ( $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'htax_db_error', __( 'Database error saving group assignments; changes rolled back.', 'hypertaxonomy' ) );
        }
    }

    $wpdb->query( 'COMMIT' );

    $all_affected_groups = array_unique( array_merge( $group_ids, $old_groups ) );

    // Fire custom hook for group changes
    do_action( 'htax_term_groups_changed', $term_id, $taxonomy, $group_ids, $old_groups );

    // Clear caches
    clean_term_cache( $term_id, $taxonomy );
    foreach ( $all_affected_groups as $group_id ) {
        clean_term_cache( $group_id, 'group' );
    }

    return true;
}


/**
 * Build a tax_query array matching posts that belong to any of the given groups.
 *
 * Shared implementation used by group archives and the Query Loop block filter.
 * Expands groups to subgroups, applies the group_assignment feature flag,
 * filters hidden terms for unprivileged users, and optionally narrows to
 * taxonomies associated with a specific post type.
 *
 * Returns an array with at least `['relation' => 'OR']`. Callers should check
 * `count($result) > 1` before applying it to a query.
 *
 * @since 1.0.0
 *
 * @param int[]  $group_ids Array of group term IDs to filter by.
 * @param string $post_type Optional. When non-empty, limits taxonomies to those
 *                          registered for this post type. Pass empty string for
 *                          archive contexts where all enabled taxonomies apply.
 * @return array tax_query array with relation key and zero or more clauses.
 */
function htax_build_group_tax_query( array $group_ids, string $post_type = '' ): array {
    $tax_query = array( 'relation' => 'OR' );

    if ( empty( $group_ids ) ) {
        return $tax_query;
    }

    $enabled    = htax_get_enabled_taxonomies();
    $taxonomies = array_diff( $enabled, array( 'group' ) );

    if ( $post_type ) {
        $taxonomies = array_intersect( $taxonomies, (array) get_object_taxonomies( $post_type ) );
    }

    if ( empty( $taxonomies ) ) {
        return $tax_query;
    }

    $can_see_hidden = htax_can_see_hidden_terms();
    $hidden_set     = $can_see_hidden ? array() : array_flip( htax_get_all_hidden_term_ids() );

    foreach ( $taxonomies as $taxonomy ) {
        if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
            continue;
        }

        $term_ids = array_keys( htax_get_terms_in_groups( $group_ids, $taxonomy ) );

        if ( ! $can_see_hidden ) {
            $term_ids = array_values( array_filter( $term_ids, function ( $id ) use ( $hidden_set ) {
                return ! isset( $hidden_set[ $id ] );
            } ) );
        }

        if ( ! empty( $term_ids ) ) {
            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $term_ids,
                'operator' => 'IN',
            );
        }
    }

    return $tax_query;
}

/**
 * Get all Term Groups
 *
 * Returns associative array of group_id => WP_Term object
 * Mimics WordPress get_categories() behavior - provides full term objects for flexibility
 *
 * @since 1.0
 * @return array Associative array of group_id => WP_Term object.
 */
function htax_get_all_term_groups() {
    $groups = get_terms(
        array(
            'taxonomy'   => 'group',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        )
    );

    if ( is_wp_error( $groups ) || empty( $groups ) ) {
        return array();
    }

    // Convert to ID => WP_Term object map (mimics get_categories pattern)
    $group_map = array();
    foreach ( $groups as $group ) {
        $group_map[ $group->term_id ] = $group;
    }

    return $group_map;
}

/**
 * Recount the stored member counts of some groups.
 *
 * A group's count is its number of direct member terms (htax_update_group_term_count(),
 * the taxonomy's update_count_callback). WordPress reads it wherever empty terms are
 * hidden, such as XML sitemaps and get_terms() with hide_empty, so it must change
 * whenever membership does. Honors wp_defer_term_counting().
 *
 * @since 1.0.0
 * @param int[] $group_ids Group term IDs.
 */
function htax_update_group_counts( array $group_ids ): void {
    $tt_ids = array();
    foreach ( array_unique( array_map( 'intval', $group_ids ) ) as $group_id ) {
        $group = get_term( $group_id, 'group' );
        if ( $group instanceof WP_Term ) {
            $tt_ids[] = (int) $group->term_taxonomy_id;
        }
    }
    if ( $tt_ids ) {
        wp_update_term_count( $tt_ids, 'group' );
    }
}

/**
 * Keep group counts current when a term joins or leaves groups.
 *
 * @since 1.0.0
 * @param int    $term_id    Term whose memberships changed.
 * @param string $taxonomy   Its taxonomy.
 * @param int[]  $new_groups Groups it belongs to now.
 * @param int[]  $old_groups Groups it belonged to before.
 */
function htax_update_group_counts_on_membership_change( $term_id, $taxonomy, $new_groups, $old_groups ): void {
    htax_update_group_counts( array_merge( (array) $new_groups, (array) $old_groups ) );
}

/**
 * Recount every group's member count.
 *
 * Runs on activation: memberships change without a recount while the plugin is
 * inactive, for example when a member term is deleted.
 *
 * @since 1.0.0
 */
function htax_repair_group_counts(): void {
    $tt_ids = get_terms( array(
        'taxonomy'   => 'group',
        'hide_empty' => false,
        'fields'     => 'tt_ids',
    ) );
    if ( ! is_wp_error( $tt_ids ) && $tt_ids ) {
        wp_update_term_count_now( array_map( 'intval', $tt_ids ), 'group' );
    }
}

/**
 * Whether a group's archive shows any published post.
 *
 * Runs the archive's own query (htax_build_group_tax_query(): the members of the
 * group and its subgroups, hidden terms removed for viewers who can't see them)
 * for one post. Post types are chosen the way WordPress chooses them for a
 * taxonomy archive: every searchable type that uses one of the queried taxonomies.
 *
 * @since 1.0.0
 * @param int $group_id Group term ID.
 * @return bool
 */
function htax_group_archive_has_posts( int $group_id ): bool {
    $tax_query = htax_build_group_tax_query( array( $group_id ) );
    if ( count( $tax_query ) < 2 ) {
        return false;
    }

    $taxonomies = wp_list_pluck( array_filter( $tax_query, 'is_array' ), 'taxonomy' );
    $post_types = array();
    foreach ( get_post_types( array( 'exclude_from_search' => false ) ) as $post_type ) {
        if ( array_intersect( $taxonomies, get_object_taxonomies( $post_type ) ) ) {
            $post_types[] = $post_type;
        }
    }
    if ( ! $post_types ) {
        return false;
    }

    $query = new WP_Query( array(
        'post_type'              => $post_types,
        'post_status'            => 'publish',
        'tax_query'              => $tax_query,
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ) );
    return ! empty( $query->posts );
}
