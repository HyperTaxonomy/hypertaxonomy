<?php
/**
 * Term Merge
 *
 * Permanently merges one or more source terms into a target term:
 * reassigns post-term relationships, transfers HT relationship records,
 * deletes source terms, and optionally creates redirect stubs.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Merge one or more terms into a target term.
 *
 * For each source term:
 * 1. Post assignments are moved to the target term.
 * 2. HT relationship records (group memberships, inclusions, exclusions) are
 *    transferred to the target, with deduplication via INSERT IGNORE.
 * 3. The source term is deleted (existing delete hooks handle search-index
 *    cleanup and relationship table cleanup automatically).
 * 4. If $create_redirect is true, a redirect stub with the source term's name
 *    is created in the same taxonomy, pointing to the chain's final non-redirect
 *    target so no redirect chains are introduced.
 *
 * @since 1.0.0
 *
 * @param int[]  $source_ids      One or more source term IDs to merge.
 * @param int    $target_id       Target term ID to merge into.
 * @param string $taxonomy        Taxonomy all terms belong to.
 * @param bool   $create_redirect Whether to create redirect stubs for deleted terms.
 * @return array|WP_Error On success: {
 *     posts_reassigned int   Total post assignments moved.
 *     terms_deleted    int   Number of source terms deleted.
 *     redirects_created int  Number of redirect stubs created.
 *     target_id        int   Actual target term ID used.
 * } WP_Error on failure.
 */
function htax_merge_terms( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect = false ) {
    global $wpdb;

    // ----------------------------------------------------------------
    // 1. Validate
    // ----------------------------------------------------------------

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        return new WP_Error( 'htax_permission_denied', __( 'You do not have permission to merge terms.', 'hypertaxonomy' ) );
    }

    if ( empty( $source_ids ) ) {
        return new WP_Error( 'htax_no_sources', __( 'No source terms specified.', 'hypertaxonomy' ) );
    }

    $source_ids = array_map( 'intval', $source_ids );
    $target_id  = (int) $target_id;

    if ( ! taxonomy_exists( $taxonomy ) ) {
        return new WP_Error( 'htax_invalid_taxonomy', __( 'Invalid taxonomy.', 'hypertaxonomy' ) );
    }

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return new WP_Error( 'htax_taxonomy_not_enabled', __( 'The specified taxonomy is not enabled for HyperTaxonomy.', 'hypertaxonomy' ) );
    }

    $target_term = get_term( $target_id, $taxonomy );
    if ( is_wp_error( $target_term ) || ! $target_term ) {
        return new WP_Error( 'htax_invalid_target', __( 'Target term not found.', 'hypertaxonomy' ) );
    }

    if ( in_array( $target_id, $source_ids, true ) ) {
        return new WP_Error( 'htax_self_merge', __( 'Target term cannot be one of the source terms.', 'hypertaxonomy' ) );
    }

    // Validate each source term.
    foreach ( $source_ids as $source_id ) {
        $source_term = get_term( $source_id, $taxonomy );
        if ( is_wp_error( $source_term ) || ! $source_term ) {
            return new WP_Error(
                'htax_invalid_source',
                sprintf(
                    /* translators: %d: term ID */
                    __( 'Source term %d not found in the specified taxonomy.', 'hypertaxonomy' ),
                    $source_id
                )
            );
        }
    }

    // Get target term_taxonomy_id once.
    $target_tt_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
        WHERE term_id = %d AND taxonomy = %s",
        $target_id,
        $taxonomy
    ) );

    if ( ! $target_tt_id ) {
        return new WP_Error( 'htax_target_tt_missing', __( 'Could not resolve target term_taxonomy_id.', 'hypertaxonomy' ) );
    }

    $rel_table = $wpdb->prefix . 'htax_term_relationships';

    // ----------------------------------------------------------------
    // 2. Collect source data before any deletion
    // ----------------------------------------------------------------

    $source_data = array();
    foreach ( $source_ids as $source_id ) {
        $source_term = get_term( $source_id, $taxonomy );
        if ( is_wp_error( $source_term ) || ! $source_term ) {
            continue;
        }

        $source_tt_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
            WHERE term_id = %d AND taxonomy = %s",
            $source_id,
            $taxonomy
        ) );

        // Group IDs this source term belongs to.
        $group_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT target_term_id FROM {$rel_table}
            WHERE source_term_id = %d
            AND relationship_type = 'member_of'",
            $source_id
        ) );

        $source_data[ $source_id ] = array(
            'name'      => $source_term->name,
            'tt_id'     => $source_tt_id,
            'group_ids' => array_map( 'intval', $group_ids ),
        );
    }

    // ----------------------------------------------------------------
    // 3. Reassign posts — for each source term
    // ----------------------------------------------------------------

    /**
     * Fires before HyperTaxonomy begins merging source terms into a target term.
     *
     * @since 1.0.0
     *
     * @param int[]  $source_ids      Source term IDs being merged.
     * @param int    $target_id       Target term ID.
     * @param string $taxonomy        Taxonomy slug.
     * @param bool   $create_redirect Whether redirect stubs will be created.
     */
    do_action( 'htax_before_merge_terms', $source_ids, $target_id, $taxonomy, $create_redirect );

    $wpdb->query( 'START TRANSACTION' );

    $all_post_ids    = array();
    $posts_reassigned = 0;

    foreach ( $source_ids as $source_id ) {
        $source_tt_id = $source_data[ $source_id ]['tt_id'];
        if ( ! $source_tt_id ) {
            continue;
        }

        // Collect post IDs that will be reassigned (before the update).
        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT object_id FROM {$wpdb->term_relationships}
            WHERE term_taxonomy_id = %d",
            $source_tt_id
        ) );

        if ( ! empty( $post_ids ) ) {
            $all_post_ids = array_merge( $all_post_ids, $post_ids );

            // Move posts not already assigned to target.
            $placeholders = htax_sql_placeholders( count( $post_ids ) );
            $already_assigned = $wpdb->get_col( $wpdb->prepare(
                "SELECT object_id FROM {$wpdb->term_relationships}
                WHERE term_taxonomy_id = %d
                AND object_id IN ($placeholders)",
                array_merge( array( $target_tt_id ), $post_ids )
            ) );

            $to_move = array_diff( $post_ids, $already_assigned );
            if ( ! empty( $to_move ) ) {
                $move_placeholders = htax_sql_placeholders( count( $to_move ) );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->term_relationships}
                    SET term_taxonomy_id = %d
                    WHERE term_taxonomy_id = %d
                    AND object_id IN ($move_placeholders)",
                    array_merge( array( $target_tt_id, $source_tt_id ), $to_move )
                ) );
                $posts_reassigned += count( $to_move );
            }

            // Delete any remaining source assignments (posts already on target).
            $wpdb->delete(
                $wpdb->term_relationships,
                array( 'term_taxonomy_id' => $source_tt_id ),
                array( '%d' )
            );
        }

        // Keep WP post counts accurate.
        wp_update_term_count( array( $target_id, $source_id ), $taxonomy );
    }

    $all_post_ids = array_unique( array_map( 'intval', $all_post_ids ) );

    // ----------------------------------------------------------------
    // 4. Transfer HT relationships — before deletion
    // ----------------------------------------------------------------

    foreach ( $source_ids as $source_id ) {
        // 4a. member_of: source is a member of some group → target inherits membership.
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$rel_table}
            (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
            SELECT %d, target_term_id, relationship_type, %s, target_taxonomy
            FROM {$rel_table}
            WHERE source_term_id = %d
            AND relationship_type = 'member_of'",
            $target_id,
            $taxonomy,
            $source_id
        ) );

        // 4b. includes/excludes where source is the subject:
        //     "source includes/excludes X" → "target includes/excludes X"
        //     Skip rows where target_term_id = target (would create self-loop).
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$rel_table}
            (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
            SELECT %d, target_term_id, relationship_type, %s, target_taxonomy
            FROM {$rel_table}
            WHERE source_term_id = %d
            AND relationship_type IN ('includes', 'excludes')
            AND target_term_id != %d",
            $target_id,
            $taxonomy,
            $source_id,
            $target_id
        ) );

        // 4c. includes/excludes where source is the object:
        //     "X includes/excludes source" → "X includes/excludes target"
        //     Skip rows where source_term_id = target (would create self-loop).
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$rel_table}
            (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
            SELECT source_term_id, %d, relationship_type, source_taxonomy, %s
            FROM {$rel_table}
            WHERE target_term_id = %d
            AND relationship_type IN ('includes', 'excludes')
            AND source_term_id != %d",
            $target_id,
            $taxonomy,
            $source_id,
            $target_id
        ) );

        // redirect rows are intentionally not transferred; the delete hooks
        // for the source term will clean them up automatically in the next step.
    }

    if ( $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'htax_merge_db_error', __( 'Database error during term merge; all changes rolled back.', 'hypertaxonomy' ) );
    }

    // ----------------------------------------------------------------
    // 5. Create redirect stubs (optional) — inside the transaction
    //    Stubs are created before COMMIT so that the transaction covers
    //    both relationship transfers and redirect creation atomically.
    //    If stub creation returns a WP_Error the transaction is rolled
    //    back before any term is deleted.
    // ----------------------------------------------------------------

    $redirects_created  = 0;
    $resolved_target_id = $target_id;
    if ( $create_redirect ) {
        // Resolve target's redirect chain to find the ultimate non-redirect term.
        $hops = 0;
        while ( $hops < 10 ) {
            $next = htax_get_redirect_target( $resolved_target_id );
            if ( ! $next ) {
                break;
            }
            $resolved_target_id = (int) $next->term_id;
            $hops++;
        }

        foreach ( $source_ids as $source_id ) {
            $data    = $source_data[ $source_id ];
            $stub_id = htax_create_redirect_term(
                $data['name'],
                $taxonomy,
                $resolved_target_id,
                $taxonomy
            );
            if ( is_wp_error( $stub_id ) ) {
                $wpdb->query( 'ROLLBACK' );
                return $stub_id;
            }
            if ( $stub_id ) {
                $redirects_created++;
            }
        }
    }

    $wpdb->query( 'COMMIT' );

    // ----------------------------------------------------------------
    // 6. Delete source terms
    //    - on_term_deleted() (priority 5) removes search index entries
    //    - htax_cleanup_deleted_term() (priority 10) removes all relationship rows
    // ----------------------------------------------------------------

    $terms_deleted = 0;
    foreach ( $source_ids as $source_id ) {
        $result = wp_delete_term( $source_id, $taxonomy );
        if ( ! is_wp_error( $result ) && $result ) {
            $terms_deleted++;
        }
    }

    // ----------------------------------------------------------------
    // 7. Rebuild search index for reassigned posts
    // ----------------------------------------------------------------

    if ( ! empty( $all_post_ids ) ) {
        htax_get_search_index()->queue_post_reindex( $all_post_ids );
    }

    // ----------------------------------------------------------------
    // 8. Clear caches for target term
    // ----------------------------------------------------------------

    clean_term_cache( $target_id, $taxonomy );
    htax_reload_term_caches_on_edit( $target_id );
    htax_delete_term_transients( $target_id );

    // The 'member_of' transfer (step 4a) moved the source terms' group memberships
    // to the target. Invalidate the caches for every group whose membership changed
    // so member-list transients (htax_grp_*) and group counts do not go stale.
    $affected_group_ids = array();
    foreach ( $source_data as $data ) {
        $affected_group_ids = array_merge( $affected_group_ids, $data['group_ids'] );
    }
    $affected_group_ids = array_unique( array_filter( array_map( 'intval', $affected_group_ids ) ) );

    if ( ! empty( $affected_group_ids ) ) {
        // Include group ancestors: group expansion is hierarchical, so a child
        // group's member-list cache feeds its ancestors' expansions too.
        $affected_group_ids = htax_get_group_ancestors( $affected_group_ids );

        foreach ( $affected_group_ids as $group_id ) {
            htax_set_group_term_count( $group_id, $taxonomy );
            htax_delete_transients_by_prefix( 'htax_grp_' . (int) $group_id . '_' );
            clean_term_cache( $group_id, 'group' );
        }

        // Fire the canonical membership-change event so the search index and other
        // listeners (htax_update_group_membership_caches) stay consistent.
        do_action( 'htax_term_groups_changed', $target_id, $taxonomy, $affected_group_ids, array() );
    }

    // The merge may have transferred relationships from source terms that are now
    // ancestors or descendants of the target. Remove any such conflict rows and
    // fire htax_includes_changed so caches and the search index stay consistent.
    if ( is_taxonomy_hierarchical( $taxonomy ) && htax_is_taxonomy_enabled( $taxonomy ) ) {
        if ( htax_remove_hierarchy_conflict_rows( $target_id, $taxonomy ) ) {
            do_action( 'htax_includes_changed', $target_id, $taxonomy, null, null );
        }
    }

    $result = array(
        'posts_reassigned'  => $posts_reassigned,
        'terms_deleted'     => $terms_deleted,
        'redirects_created' => $redirects_created,
        'target_id'         => $target_id,
    );

    /**
     * Fires after HyperTaxonomy has successfully merged source terms into a target term.
     *
     * At this point all post reassignments, HT relationship transfers, source term
     * deletions, redirect stubs (if requested), and cache invalidation are complete.
     *
     * @since 1.0.0
     *
     * @param int[]  $source_ids  Source term IDs that were merged (now deleted).
     * @param int    $target_id   Target term ID.
     * @param string $taxonomy    Taxonomy slug.
     * @param array  $result      Merge result: {
     *     posts_reassigned  int  Total post assignments moved.
     *     terms_deleted     int  Number of source terms deleted.
     *     redirects_created int  Number of redirect stubs created.
     *     target_id         int  Actual target term ID used.
     * }
     */
    do_action( 'htax_after_merge_terms', $source_ids, $target_id, $taxonomy, $result );

    return $result;
}
