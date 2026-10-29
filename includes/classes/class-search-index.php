<?php
/**
 * Search Index Management for HyperTaxonomy
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main Search Index Manager Class
 */
class HyperTaxonomy_Search_Index_Manager {
    
    /**
     * Instance
     */
    private static $instance = null;

    /**
     * Pending post IDs to reindex (deduplicated queue)
     */
    private static $pending_reindex_posts = array();

    /**
     * Whether shutdown hook has been scheduled for queue processing
     */
    private static $reindex_scheduled = false;

    /**
     * Maximum number of queued posts reindexed per WP Cron run
     */
    const REINDEX_BATCH_SIZE = 100;

    /**
     * Get instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Hook into all events that require index updates
        add_action( 'delete_post', array( $this, 'on_post_delete' ) );
        add_action( 'trash_post', array( $this, 'on_post_delete' ) );
        add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 10, 3 );

        // Term assignment events
        add_action( 'set_object_terms', array( $this, 'on_term_relationships_added' ), 10, 4 );
        add_action( 'deleted_term_relationships', array( $this, 'on_term_relationships_removed' ), 10, 3 );

        // Group membership changes (triggers reindex for affected posts)
        add_action( 'htax_term_groups_changed', array( $this, 'on_term_groups_changed' ), 10, 4 );

        // Term deletion
        // Priority 5 - MUST run before htax_cleanup_deleted_term (priority 10) deletes relationships
        add_action( 'delete_term', array( $this, 'on_term_deleted' ), 5, 3 );

        // Hierarchy changes for any hierarchical taxonomy (parent can change on edit)
        // Group uses its own handler (on_group_hierarchy_changed) due to different logic
        add_action( 'edited_term', array( $this, 'on_taxonomy_hierarchy_changed' ), 10, 3 );

        // Group hierarchy changes (when a group's parent changes, affects which terms are included via group expansion)
        add_action( 'edited_group', array( $this, 'on_group_hierarchy_changed' ), 10, 2 );

        // Term visibility changes (when a term is hidden/unhidden, affects search index)
        add_action( 'htax_term_visibility_changed', array( $this, 'on_term_visibility_changed' ), 10, 2 );

        // Admin actions
        add_action( 'wp_ajax_htax_rebuild_indexes', array( $this, 'ajax_rebuild_indexes' ) );

        // Bulk operations
        add_action( 'wp_ajax_htax_bulk_rebuild', array( $this, 'ajax_bulk_rebuild' ) );

        // Full index rebuild (activation, reactivation, or manual trigger)
        add_action( 'htax_rebuild_indexes', array( $this, 'rebuild_indexes' ) );

        // Sync index when HT taxonomy enabled/disabled state changes
        add_action( 'update_option_htax_settings', array( $this, 'on_taxonomy_enabled_state_changed' ), 10, 2 );

        // Recompute stored strengths when propagation weights change
        add_action( 'update_option_htax_settings', array( $this, 'on_relevance_weights_changed' ), 10, 2 );

        // Recompute stored strengths when a published post's content changes.
        // Priority 20: after htax_sync_post_text_row (priority 10) refreshes the post text.
        add_action( 'save_post', array( $this, 'on_post_saved' ), 20, 2 );
    }
    
    /**
     * When post is deleted
     */
    public function on_post_delete( $post_id ) {
        $this->remove_post_from_search_index( $post_id );
    }

    /**
     * Remove a post from wp_htax_search_terms and clear the count cache for
     * whatever terms it was associated with.
     *
     * @param int $post_id Post ID.
     */
    private function remove_post_from_search_index( $post_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        // Get all terms from this post before deleting (for cache clearing)
        $terms_by_taxonomy = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT source_term_id, taxonomy
             FROM {$table_name}
             WHERE post_id = %d
             AND relationship_type = 'direct'",
            $post_id
        ), ARRAY_A );

        // Delete post from index
        $wpdb->delete(
            $table_name,
            array( 'post_id' => $post_id ),
            array( '%d' )
        );

        // Clear count cache for affected terms (grouped by taxonomy)
        if ( ! empty( $terms_by_taxonomy ) ) {
            $this->clear_count_cache_for_terms( $terms_by_taxonomy );
        }
    }

    /**
     * Mark the last entry in $term_relationships as hidden if either the redirect
     * source or target term is hidden.
     *
     * @param array $term_relationships Relationships array (modified in place).
     * @param array $redirect_target    Redirect target info with 'term_id' key.
     * @param int   $source_term_id     ID of the redirecting (source) term.
     */
    public static function mark_redirect_as_hidden_if_needed( array &$term_relationships, \WP_Term $redirect_target, int $source_term_id ): void {
        $last_idx = count( $term_relationships ) - 1;
        if ( $last_idx >= 0 ) {
            $is_target_hidden = htax_is_term_hidden( $redirect_target->term_id );
            $is_source_hidden = htax_is_term_hidden( $source_term_id );
            if ( $is_target_hidden || $is_source_hidden ) {
                $term_relationships[ $last_idx ]['is_hidden'] = 1;
            }
        }
    }

    /**
     * Group flat term rows by taxonomy and clear count cache for each group.
     *
     * @param array $terms_by_taxonomy Rows with 'taxonomy' and 'source_term_id' keys.
     */
    private function clear_count_cache_for_terms( $terms_by_taxonomy ) {
        $terms_grouped = array();
        foreach ( $terms_by_taxonomy as $row ) {
            $taxonomy = $row['taxonomy'];
            if ( ! isset( $terms_grouped[ $taxonomy ] ) ) {
                $terms_grouped[ $taxonomy ] = array();
            }
            $terms_grouped[ $taxonomy ][] = (int) $row['source_term_id'];
        }

        foreach ( $terms_grouped as $taxonomy => $term_ids ) {
            htax_clear_count_cache( $term_ids, $taxonomy );
        }
    }

    /**
     * When post status changes
     *
     * Handles transitions between published and non-published states.
     * Only published posts should be in the search index.
     *
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public function on_post_status_change( $new_status, $old_status, $post ) {
        $was_published = ( 'publish' === $old_status );
        $is_published  = ( 'publish' === $new_status );

        // Current post_type is not one we index. This also catches a post whose
        // type changed away from an indexed type while post_status itself stayed
        // "publish" (no status transition, so the branches below would otherwise
        // never run) — if it was published before, make sure it isn't left behind
        // in the index.
        if ( ! in_array( $post->post_type, htax_get_indexed_post_types(), true ) ) {
            if ( $was_published ) {
                $this->remove_post_from_search_index( $post->ID );
            }
            return;
        }

        // No change in published state - nothing to do
        if ( $was_published === $is_published ) {
            return;
        }

        if ( $is_published ) {
            // Post is now published - rebuild index
            $this->queue_post_reindex( $post->ID );

            // Clear count cache for terms being added
            // Get terms from the post (they may have been assigned while in draft)
            $post_taxonomies = get_object_taxonomies( $post->post_type, 'names' );
            foreach ( $post_taxonomies as $taxonomy ) {
                $terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    htax_clear_count_cache( $terms, $taxonomy );
                }
            }
        } else {
            // Post is no longer published - remove from index
            $this->remove_post_from_search_index( $post->ID );
        }
    }

    /**
     * When terms are assigned/removed from a post
     */
    public function on_term_relationships_added( $object_id, $terms, $tt_ids, $taxonomy ) {
        // Only process taxonomies registered for an indexed post type
        $indexed_types = htax_get_indexed_post_types();
        $tax_obj       = get_taxonomy( $taxonomy );
        if ( ! $tax_obj || empty( array_intersect( $indexed_types, (array) $tax_obj->object_type ) ) ) {
            return;
        }

        $post = get_post( $object_id );
        if ( ! $post || ! in_array( $post->post_type, $indexed_types, true ) ) {
            return;
        }

        // Skip if not published
        if ( 'publish' !== $post->post_status ) {
            return;
        }

        // Get currently indexed source terms (directly assigned terms)
        $current_term_ids = $this->get_source_terms_from_index( $object_id, $taxonomy );

        // The post's actual terms after the change. Both hooks fire after the database
        // is updated; the hook's own $terms can't be used — on an append
        // (wp_add_object_terms) it holds only the appended terms, and the removal hook
        // passes none at all, which would drop every other term from the index.
        global $wpdb;
        $new_term_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy = %s",
            $object_id,
            $taxonomy
        ) ) );

        // Sort for comparison
        sort( $current_term_ids );
        sort( $new_term_ids );

        // If terms haven't changed, skip update
        if ( $current_term_ids === $new_term_ids ) {
            return;
        }

        // Calculate delta: what was added and what was removed
        $added_terms = array_diff( $new_term_ids, $current_term_ids );
        $removed_terms = array_diff( $current_term_ids, $new_term_ids );

        // Clear count cache for affected terms
        if ( ! empty( $added_terms ) || ! empty( $removed_terms ) ) {
            $affected_term_ids = array_unique( array_merge( $added_terms, $removed_terms ) );
            htax_clear_count_cache( $affected_term_ids, $taxonomy );
        }

        // Perform incremental update instead of full rebuild
        $this->incremental_index_update( $object_id, $added_terms, $removed_terms, $taxonomy );
    }
    
    /**
     * When term relationships are removed
     */
    public function on_term_relationships_removed( $object_id, $tt_ids, $taxonomy ) {
        // Skip if we're in set_object_terms - on_term_relationships_added will handle it
        if ( doing_action( 'set_object_terms' ) ) {
            return;
        }

        // Same delta logic as on_term_relationships_added (which reads the post's
        // remaining terms from the database).
        $this->on_term_relationships_added( $object_id, array(), $tt_ids, $taxonomy );
    }
    
    /**
     * When a term is deleted
     */
    public function on_term_deleted( $term_id, $tt_id, $taxonomy ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        // With source_term_id tracking, we can simply delete all index entries
        // derived from this term (both direct and indirect relationships)
        //
        // source_term_id = X catches:
        // - Direct relationship (term_id = X, source_term_id = X)
        // - All indirect relationships derived from this term (containers, hierarchy, etc.)
        //
        // No need to check term_id separately - source_term_id covers everything
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name}
                 WHERE source_term_id = %d",
                $term_id
            )
        );

        htax_handle_db_error( 'on_term_deleted' );
    }

    /**
     * When a term's group membership changes
     *
     * @param int $term_id Term ID that changed groups
     * @param string $taxonomy Taxonomy name
     * @param array $new_groups New group IDs
     * @param array $old_groups Old group IDs
     */
    public function on_term_groups_changed( $term_id, $taxonomy, $new_groups, $old_groups ) {
        // When Term A's group membership changes (joins/leaves Group X):
        // Effect 1: Posts with Term A - their container_group relationships change
        // Effect 2: Terms with container_groups=X - they now container Term A (or don't anymore)
        // Effect 3: Terms that include Group X - they now include Term A (or don't anymore)

        global $wpdb;

        // Effect 1: Use optimized direct SQL handler for Term A's container_group relationships
        // This updates posts with Term A ~800x faster than full reindex
        $this->on_container_groups_changed( $term_id, $taxonomy, $new_groups, $old_groups );

        $all_affected_groups = array_unique( array_merge( $new_groups, $old_groups ) );

        if ( empty( $all_affected_groups ) ) {
            return; // No referencing terms to handle
        }

        // Effect 2 (container groups): Term B's stored container group Z, expanded upward, includes
        // GroupChild iff Z is GroupChild or a descendant of GroupChild. Expand downward.
        $groups_for_container_query = htax_get_group_hierarchy( $all_affected_groups );

        // Effect 3 (included groups): Term B's stored include group W, expanded downward, includes
        // GroupChild's members iff W is GroupChild or an ancestor of GroupChild. Expand upward.
        $groups_for_includes_query = htax_get_group_ancestors( $all_affected_groups );

        $post_ids = array();

        // Effect 2: Find terms that have any of these groups as container_groups
        // These terms now container Term A (or stop containering it)
        $includes_in     = htax_relationship_type_sql_in( 'includes' );
        $cg_placeholders = htax_sql_placeholders( count( $groups_for_container_query ) );
        $terms_with_container_groups = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT target_term_id
             FROM {$wpdb->prefix}htax_term_relationships
             WHERE source_term_id IN ($cg_placeholders)
             AND source_taxonomy = 'group'
             AND target_taxonomy = %s
             AND {$includes_in}",
            array_merge( $groups_for_container_query, array( $taxonomy ) )
        ) );

        htax_handle_db_error( 'on_term_groups_changed: find terms with container_groups' );

        if ( ! empty( $terms_with_container_groups ) ) {
            // Batch query: Get posts with all these terms at once
            $post_ids_batch = $this->get_posts_directly_assigned_terms( $terms_with_container_groups, $taxonomy );
            $post_ids = array_merge( $post_ids, $post_ids_batch );
        }

        // Effect 3: Find terms that include any of these groups
        // These terms now include Term A (or stop including it)
        $ig_placeholders = htax_sql_placeholders( count( $groups_for_includes_query ) );
        $terms_including_groups = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT source_term_id
             FROM {$wpdb->prefix}htax_term_relationships
             WHERE target_term_id IN ($ig_placeholders)
             AND target_taxonomy = 'group'
             AND source_taxonomy = %s
             AND {$includes_in}",
            array_merge( $groups_for_includes_query, array( $taxonomy ) )
        ) );

        htax_handle_db_error( 'on_term_groups_changed: find terms including groups' );

        if ( ! empty( $terms_including_groups ) ) {
            // Posts with Term A affected (already handled by on_container_groups_changed)
            // But also need to reindex posts with the including terms themselves
            // because their 'includes' expansion now includes/excludes Term A
            $post_ids_batch = $this->get_posts_directly_assigned_terms( $terms_including_groups, $taxonomy );
            $post_ids = array_merge( $post_ids, $post_ids_batch );
        }

        if ( ! empty( $post_ids ) ) {
            $this->queue_post_reindex( $post_ids );
        }
    }

    /**
     * When a hierarchical term's parent changes
     *
     * Handles hierarchy index entries for any hierarchical taxonomy.
     * Group uses its own handler (on_group_hierarchy_changed) due to different semantics.
     */
    public function on_taxonomy_hierarchy_changed( $term_id, $tt_id, $taxonomy ) {
        // Only hierarchical taxonomies have ancestor entries in the index
        if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
            return;
        }

        // Group has its own handler with different logic
        if ( 'group' === $taxonomy ) {
            return;
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }

        // Check if parent actually changed (cached before edit by htax_cache_term_taxonomy_before_edit)
        $old_data   = htax_get_transient( 'htax_term_data_' . $tt_id );
        $old_parent = isset( $old_data['parent'] ) ? (int) $old_data['parent'] : 0;
        $new_parent = (int) $term->parent;

        if ( $old_parent === $new_parent ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        // When hierarchy changes, we need to update hierarchy entries for:
        // 1. The changed term itself (as source)
        // 2. All descendants of the changed term (as sources)
        //
        // For each affected source term, we:
        // - Delete old hierarchy entries
        // - Insert new hierarchy entries with updated ancestor chain

        $affected_term_ids = array( $term_id );
        $children          = get_term_children( $term_id, $taxonomy );
        if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
            $affected_term_ids = array_merge( $affected_term_ids, $children );
        }

        // Delete all affected terms' hierarchy entries in a single query
        if ( ! empty( $affected_term_ids ) ) {
            $placeholders = htax_sql_placeholders( count( $affected_term_ids ) );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_name}
                     WHERE source_term_id IN ({$placeholders})
                       AND relationship_type = 'hierarchy'",
                    ...$affected_term_ids
                )
            );

            htax_handle_db_error( 'on_taxonomy_hierarchy_changed - delete old hierarchy' );
        }

        // Collect all INSERT values across all affected terms for batch insert
        $all_values       = array();
        $all_placeholders = array();
        $reindex_post_ids = array();

        // Collect all unique ancestor IDs across all affected terms, then batch-fetch
        // their term objects in one query to avoid one get_term() call per ancestor per source.
        $ancestor_map = array(); // source_id => int[]
        $all_ancestor_ids = array();
        foreach ( $affected_term_ids as $source_id ) {
            $ancestors = get_ancestors( $source_id, $taxonomy, 'taxonomy' );
            $ancestor_map[ $source_id ] = $ancestors;
            $all_ancestor_ids = array_merge( $all_ancestor_ids, $ancestors );
        }

        $ancestor_terms = array(); // ancestor_id => WP_Term
        if ( ! empty( $all_ancestor_ids ) ) {
            $fetched = get_terms( array(
                'taxonomy'   => $taxonomy,
                'include'    => array_unique( $all_ancestor_ids ),
                'hide_empty' => false,
            ) );
            if ( ! is_wp_error( $fetched ) ) {
                foreach ( $fetched as $at ) {
                    $ancestor_terms[ $at->term_id ] = $at;
                }
            }
        }

        foreach ( $affected_term_ids as $source_id ) {
            $ancestors = $ancestor_map[ $source_id ];

            if ( ! empty( $ancestors ) ) {
                $post_ids = $this->get_posts_directly_assigned_terms( $source_id, $taxonomy );

                htax_handle_db_error( 'on_taxonomy_hierarchy_changed - get posts' );

                if ( ! empty( $post_ids ) ) {
                    $is_source_hidden = htax_is_term_hidden( $source_id );
                    $reindex_post_ids = array_merge( $reindex_post_ids, $post_ids );

                    foreach ( $post_ids as $post_id ) {
                        foreach ( $ancestors as $parent_id ) {
                            if ( htax_get_redirect_target( $parent_id ) ) {
                                break; // Redirect in chain — don't index this or any higher ancestor.
                            }
                            $parent_term = $ancestor_terms[ $parent_id ] ?? null;
                            if ( ! $parent_term ) {
                                continue;
                            }

                            $is_hidden = $is_source_hidden || htax_is_term_hidden( $parent_id );

                            $all_values[] = $post_id;
                            $all_values[] = $parent_id;
                            $all_values[] = $source_id;
                            $all_values[] = $taxonomy;
                            $all_values[] = $is_hidden ? 1 : 0;

                            $all_placeholders[] = '(%d, %d, %d, %s, \'hierarchy\', %d)';
                        }
                    }
                }
            }
        }

        // Execute single batch INSERT for all affected terms
        htax_upsert_search_rows( $table_name, $all_placeholders, $all_values, 'on_taxonomy_hierarchy_changed - insert new hierarchy' );

        // New rows carry the column-default strength; the reindex computes the real values.
        if ( ! empty( $reindex_post_ids ) ) {
            $this->queue_post_reindex( $reindex_post_ids );
        }

        // Clear count cache - hierarchy changes affect {total_count} in shortcodes
        htax_clear_count_cache( $affected_term_ids, $taxonomy );

        // Clear htax_inc_ / htax_cont_ caches for the reparented term's descendants.
        // The reparented term itself is cleared by htax_reload_term_caches_on_edit at
        // priority 10; its ancestors are cleared by htax_fire_archive_changed_on_hierarchy
        // at priority 11. Descendants are only handled here.
        if ( ! is_wp_error( $children ) ) {
            foreach ( $children as $child_id ) {
                htax_delete_transient( 'htax_inc_' . $child_id );
                htax_delete_transient( 'htax_cont_' . $child_id );
            }
        }

        // Delete stale container index entries where the reparented term (or any of its
        // descendants) appears as source_term_id under any of its new ancestors as term_id.
        // Before the reparent these may have been valid indirect inclusions via a group;
        // after the reparent they are hierarchy-covered and must not double-score.
        $new_ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
        if ( ! empty( $new_ancestors ) && ! empty( $affected_term_ids ) ) {
            $anc_placeholders = htax_sql_placeholders( count( $new_ancestors ) );
            $aff_placeholders = htax_sql_placeholders( count( $affected_term_ids ) );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_name}
                     WHERE term_id IN ({$anc_placeholders})
                     AND source_term_id IN ({$aff_placeholders})
                     AND relationship_type = 'container'",
                    array_merge( $new_ancestors, $affected_term_ids )
                )
            );
            htax_handle_db_error( 'on_taxonomy_hierarchy_changed - delete stale container entries' );
        }
    }

    /**
     * Restore hierarchy index entries for a term that just stopped being a redirect.
     *
     * When term B was a redirect, its hierarchy entries were stripped. This method
     * rebuilds entries for B and its non-redirect ancestors, for all posts directly
     * assigned to any descendant of B. The ancestor chain is walked with break-on-redirect
     * semantics so intermediate redirects are correctly respected.
     *
     * @param int    $term_id  Term ID that is no longer a redirect.
     * @param string $taxonomy Taxonomy name.
     */
    public function on_hierarchy_parent_restored( $term_id, $taxonomy ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        $descendants = get_term_children( $term_id, $taxonomy );
        if ( is_wp_error( $descendants ) || empty( $descendants ) ) {
            return;
        }

        // The ancestors we need to restore entries for: $term_id itself plus its own ancestors.
        $ancestors_of_term    = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
        $term_and_ancestors   = array_merge( array( $term_id ), $ancestors_of_term );

        // Delete stale entries: any hierarchy row whose source is a descendant of $term_id
        // and whose term_id is $term_id or higher (these were stripped when B became a redirect).
        $desc_placeholders = htax_sql_placeholders( count( $descendants ) );
        $anc_placeholders  = htax_sql_placeholders( count( $term_and_ancestors ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table_name}
              WHERE source_term_id IN ({$desc_placeholders})
                AND term_id IN ({$anc_placeholders})
                AND relationship_type = 'hierarchy'",
            array_merge( $descendants, $term_and_ancestors )
        ) );
        htax_handle_db_error( 'on_hierarchy_parent_restored - delete stale' );

        // Rebuild: for each descendant, walk its ancestor chain (stopping at any redirect)
        // and re-insert entries for those ancestors that belong to $term_and_ancestors.
        $all_values       = array();
        $all_placeholders = array();
        $reindex_post_ids = array();

        foreach ( $descendants as $descendant_id ) {
            $post_ids = $this->get_posts_directly_assigned_terms( $descendant_id, $taxonomy );
            if ( empty( $post_ids ) ) {
                continue;
            }
            $reindex_post_ids = array_merge( $reindex_post_ids, $post_ids );

            $is_source_hidden = htax_is_term_hidden( $descendant_id );

            $ancestors = get_ancestors( $descendant_id, $taxonomy, 'taxonomy' );
            foreach ( $ancestors as $ancestor_id ) {
                if ( htax_get_redirect_target( $ancestor_id ) ) {
                    break; // Redirect in chain — stop here.
                }
                if ( ! in_array( $ancestor_id, $term_and_ancestors, true ) ) {
                    continue; // This ancestor was not affected by $term_id's redirect; skip.
                }

                $is_ancestor_hidden = htax_is_term_hidden( $ancestor_id );
                $is_hidden          = $is_source_hidden || $is_ancestor_hidden;

                foreach ( $post_ids as $post_id ) {
                    $all_values[]       = $post_id;
                    $all_values[]       = $ancestor_id;
                    $all_values[]       = $descendant_id;
                    $all_values[]       = $is_hidden ? 1 : 0;
                    $all_placeholders[] = '(%d, %d, %d, \'' . esc_sql( $taxonomy ) . '\', \'hierarchy\', %d)';
                }
            }
        }

        htax_upsert_search_rows( $table_name, $all_placeholders, $all_values, 'on_hierarchy_parent_restored - insert' );

        // New rows carry the column-default strength; the reindex computes the real values.
        if ( ! empty( $all_placeholders ) ) {
            $this->queue_post_reindex( $reindex_post_ids );
        }
    }

    /**
     * When a group's hierarchy changes (parent changes)
     *
     * When a group becomes a child or parent of another group, any terms that include
     * the parent group will now also include members of the child group (via hierarchy expansion).
     * We need to reindex all posts with terms that reference this group or its new/old ancestors.
     *
     * This handler retrieves the old parent from the cached data and processes both the old
     * and new hierarchy branches to ensure complete reindex coverage.
     *
     * @param int $group_id Group term ID
     * @param int $tt_id Term taxonomy ID
     */
    public function on_group_hierarchy_changed( $group_id, $tt_id ) {
        $group = htax_get_valid_term( $group_id );
        if ( ! $group ) {
            return;
        }

        // Get old parent from cached data (cached before edit by htax_cache_term_taxonomy_before_edit)
        $old_data = htax_get_transient( 'htax_term_data_' . $tt_id );
        $old_parent = isset( $old_data['parent'] ) ? (int) $old_data['parent'] : 0;
        $new_parent = (int) $group->parent;

        // If parent didn't actually change, no reindex needed
        if ( $old_parent === $new_parent ) {
            return;
        }

        // Get all terms that are members of this group or any of its descendants
        // When this group's hierarchy changes, it affects all these member terms
        // Organize by taxonomy for batched queries
        $affected_terms_by_taxonomy = array();

        // Get all member terms of this group, organized by taxonomy
        foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
            $tax_terms = htax_get_terms_in_groups( array( $group_id ), $taxonomy );
            foreach ( $tax_terms as $term ) {
                $affected_terms_by_taxonomy[ $taxonomy ][] = $term->term_id;
            }
        }

        // Get all descendant groups and their members
        $descendant_groups = get_term_children( $group_id, 'group' );
        if ( ! is_wp_error( $descendant_groups ) && ! empty( $descendant_groups ) ) {
            foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
                $tax_terms = htax_get_terms_in_groups( $descendant_groups, $taxonomy );
                foreach ( $tax_terms as $term ) {
                    $affected_terms_by_taxonomy[ $taxonomy ][] = $term->term_id;
                }
            }
        }

        // Batch query posts with affected terms (one query per taxonomy instead of N queries)
        $post_ids = array();
        foreach ( $affected_terms_by_taxonomy as $taxonomy => $term_ids ) {
            if ( ! empty( $term_ids ) ) {
                $term_ids = array_unique( $term_ids );
                $post_ids_batch = $this->get_posts_directly_assigned_terms( $term_ids, $taxonomy );
                $post_ids = array_merge( $post_ids, $post_ids_batch );
            }
        }

        // "Terms including groups": Term B's stored include group W, expanded downward, reaches
        // members of this group iff W is this group or an ancestor. Expand upward (ancestor chain).
        $groups_for_includes_query = array( $group_id );
        $new_ancestors = get_ancestors( $group_id, 'group', 'taxonomy' );
        if ( ! empty( $new_ancestors ) ) {
            $groups_for_includes_query = array_merge( $groups_for_includes_query, $new_ancestors );
        }
        if ( $old_parent > 0 ) {
            $groups_for_includes_query[] = $old_parent;
            $old_ancestors = get_ancestors( $old_parent, 'group', 'taxonomy' );
            if ( ! empty( $old_ancestors ) ) {
                $groups_for_includes_query = array_merge( $groups_for_includes_query, $old_ancestors );
            }
        }
        $groups_for_includes_query = array_unique( $groups_for_includes_query );

        // "Terms with container groups": Term B's stored container group Z, expanded upward, includes
        // this group iff Z is this group or a descendant. Expand downward (descendant chain).
        $groups_for_container_query = htax_get_group_hierarchy( array( $group_id ) );

        // Find all taxonomies enabled for HyperTaxonomy
        $enabled_taxonomies = htax_get_enabled_taxonomies();
        global $wpdb;
        $ig_placeholders = htax_sql_placeholders( count( $groups_for_includes_query ) );
        $cg_placeholders = htax_sql_placeholders( count( $groups_for_container_query ) );

        foreach ( $enabled_taxonomies as $taxonomy ) {
            // Collect all affected term IDs for this taxonomy
            $referencing_terms = array();

            $includes_in = htax_relationship_type_sql_in( 'includes' );

            // Terms that include these groups
            $terms_including = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT source_term_id
                 FROM {$wpdb->prefix}htax_term_relationships
                 WHERE target_term_id IN ($ig_placeholders)
                 AND target_taxonomy = 'group'
                 AND source_taxonomy = %s
                 AND {$includes_in}",
                array_merge( $groups_for_includes_query, array( $taxonomy ) )
            ) );

            htax_handle_db_error( 'on_group_hierarchy_changed: find terms including groups' );

            if ( ! empty( $terms_including ) ) {
                $referencing_terms = array_merge( $referencing_terms, $terms_including );
            }

            // Terms that have these groups as containers
            $terms_with_container = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT target_term_id
                 FROM {$wpdb->prefix}htax_term_relationships
                 WHERE source_term_id IN ($cg_placeholders)
                 AND source_taxonomy = 'group'
                 AND target_taxonomy = %s
                 AND {$includes_in}",
                array_merge( $groups_for_container_query, array( $taxonomy ) )
            ) );

            htax_handle_db_error( 'on_group_hierarchy_changed: find terms with container groups' );

            if ( ! empty( $terms_with_container ) ) {
                $referencing_terms = array_merge( $referencing_terms, $terms_with_container );
            }

            // Batch query: Get posts with all referencing terms at once
            if ( ! empty( $referencing_terms ) ) {
                $referencing_terms = array_unique( $referencing_terms );
                $post_ids_batch = $this->get_posts_directly_assigned_terms( $referencing_terms, $taxonomy );
                $post_ids = array_merge( $post_ids, $post_ids_batch );

                // Invalidate htax_inc_* caches for these terms. The search index reindex above
                // bypasses htax_get_included_terms() and queries directly, so it stays correct.
                // But the transient cache (no TTL) is not cleared by the reindex path — archive
                // query expansion and admin UI would serve stale results until explicitly cleared.
                foreach ( $referencing_terms as $ref_term_id ) {
                    do_action( 'htax_includes_changed', (int) $ref_term_id, $taxonomy, null, null );
                }
            }
        }

        // Queue reindex for all affected posts
        $this->queue_post_reindex( $post_ids );

        // Clear count cache - group hierarchy changes affect {total_count} in shortcodes
        // htax_clear_count_cache will find ancestors automatically
        htax_clear_count_cache( array( $group_id ), 'group' );
    }

    /**
     * When term containers change (direct 'includes' relationships)
     *
     * Optimized handler that directly updates container rows instead of full reindex.
     * Updates all posts that have the source term assigned, changing their container relationships.
     *
     * @param int $term_id Source term whose containers changed
     * @param string $taxonomy Taxonomy of the source term
     * @param array $new_containers New container term IDs
     * @param array $old_containers Old container term IDs
     */
    public function on_containers_changed( $term_id, $taxonomy, $new_containers, $old_containers ) {
        // Delegate to unified handler
        $this->update_container_index( $term_id, $taxonomy );
    }

    /**
     * When term container groups change
     *
     * Optimized handler that directly updates container rows instead of full reindex.
     * Updates all posts that have the source term assigned, changing their container relationships.
     *
     * @param int $term_id Source term whose container groups changed
     * @param string $taxonomy Taxonomy of the source term
     * @param array $new_groups New container group IDs
     * @param array $old_groups Old container group IDs
     */
    public function on_container_groups_changed( $term_id, $taxonomy, $new_groups, $old_groups ) {
        // Delegate to unified handler
        $this->update_container_index( $term_id, $taxonomy );
    }

    /**
     * Unified container index update handler (private)
     *
     * Handles container index updates for both direct containers and container groups.
     * Container cache is the single source of truth - this method syncs index to cache.
     *
     * Architecture:
     * - Cache as source of truth: htax_get_container_terms() computes ALL containers (direct + via groups)
     * - Delta-based updates: Calculate difference between old and new state, only update what changed
     * - Unified storage: Index uses only relationship_type='container' (not 'container_group')
     *
     * @param int    $term_id   Term whose containers changed
     * @param string $taxonomy  Term's taxonomy
     */
    private function update_container_index( $term_id, $taxonomy ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        // 1. Invalidate container cache (will lazy-reload on first access)
        htax_delete_transient( 'htax_cont_' . $term_id );

        // 2. Get posts with this term assigned
        $post_ids = $this->get_posts_directly_assigned_terms( $term_id, $taxonomy );
        if ( empty( $post_ids ) ) {
            return; // No posts, nothing to update
        }

        // 3. Get NEW container state from cache (lazy-loads if needed)
        // Returns WP_Term objects indexed by term_id
        // All containers guaranteed to match source term's taxonomy (filtered in query)
        $container_terms = htax_get_container_terms( $term_id, true );
        $new_container_ids = array_map( 'intval', array_keys( $container_terms ) );

        // Index rows cover each container plus its WP descendants in hierarchical
        // taxonomies (as add_term_relationships_to_index() writes them), so diff against
        // that same expanded set — otherwise descendant rows look "removed" and are lost.
        if ( is_taxonomy_hierarchical( $taxonomy ) && ! empty( $new_container_ids ) ) {
            $new_container_ids = array_map( 'intval', htax_expand_term_hierarchy( $new_container_ids, $taxonomy ) );
        }

        // 4. Get OLD container state from index (current state before update)
        $old_container_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT term_id FROM {$table_name}
             WHERE source_term_id = %d
             AND relationship_type = 'container'",
            $term_id
        ) );
        $old_container_ids = array_map( 'intval', $old_container_ids );

        // 5. Calculate delta (what changed)
        $removed = array_diff( $old_container_ids, $new_container_ids );
        $added = array_diff( $new_container_ids, $old_container_ids );

        // 6. Delete removed containers (delta-based, surgical)
        if ( ! empty( $removed ) ) {
            $placeholders = htax_sql_placeholders( count( $removed ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table_name}
                 WHERE source_term_id = %d
                 AND term_id IN ($placeholders)
                 AND relationship_type = 'container'",
                array_merge( array( $term_id ), $removed )
            ) );
        }

        // 7. Insert added containers (delta-based, surgical)
        if ( ! empty( $added ) ) {
            $values = array();
            $insert_placeholders = array();

            // Cache source term's hidden state once — constant across all inner iterations
            $source_is_hidden = htax_is_term_hidden( $term_id );

            foreach ( $post_ids as $post_id ) {
                foreach ( $added as $container_id ) {
                    // $added is already hierarchy-expanded (see step 3).
                    $insert_placeholders[] = "(%d, %d, %d, %s, %s, %d)";
                    $values[] = $post_id;
                    $values[] = $container_id;
                    $values[] = $term_id;
                    $values[] = $taxonomy;
                    $values[] = 'container';
                    $values[] = ( $source_is_hidden || htax_is_term_hidden( $container_id ) ) ? 1 : 0;
                }
            }

            // Insert all added container rows
            htax_upsert_search_rows( $table_name, $insert_placeholders, $values );

            // New rows carry the column-default strength; the reindex computes the real values.
            $this->queue_post_reindex( $post_ids );
        }
    }

    /**
     * When term includes change (what this term includes)
     *
     * When Term A's includes change, posts with the included terms need updating
     * because they now have (or no longer have) Term A as a container.
     *
     * Uses direct SQL for performance:
     * - DELETE old container rows for Term A from posts with old included terms
     * - INSERT new container rows for Term A into posts with new included terms
     *
     * Note: This does NOT affect posts with Term A directly assigned - those are unaffected.
     *
     * @param int $term_id Container term whose includes changed
     * @param string $taxonomy Taxonomy of the container term
     * @param array $new_included New included term IDs
     * @param array $old_included Old included term IDs
     */
    public function on_includes_changed( $term_id, $taxonomy, $new_included, $old_included ) {
        if ( null === $new_included || null === $old_included ) {
            // Fired as a cache-invalidation signal with no delta data.
            // on_term_groups_changed already queued the affected posts for reindex.
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        // Get all affected source term IDs (terms that are no longer included or newly included)
        $removed_term_ids = array_diff( $old_included, $new_included );
        $added_term_ids = array_diff( $new_included, $old_included );

        // Step 1: DELETE old container rows
        // Remove Term A as container from posts with terms that are no longer included
        if ( ! empty( $removed_term_ids ) ) {
            $placeholders = htax_sql_placeholders( count( $removed_term_ids ) );
            $query_args = array_merge(
                array( $term_id, $taxonomy ),
                $removed_term_ids
            );

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_name}
                     WHERE term_id = %d
                       AND taxonomy = %s
                       AND relationship_type = 'container'
                       AND source_term_id IN ({$placeholders})",
                    $query_args
                )
            );

            htax_handle_db_error( 'on_includes_changed: DELETE removed includes' );
        }

        // Step 2: INSERT new container rows
        // Add Term A as container to posts with newly included terms
        if ( ! empty( $added_term_ids ) ) {
            // Get term details for the container term
            $container_term = get_term( $term_id, $taxonomy );
            if ( is_wp_error( $container_term ) ) {
                htax_log_error( 'Failed to get container term in on_includes_changed', array(
                    'term_id' => $term_id,
                    'taxonomy' => $taxonomy,
                ) );
                return;
            }

            $is_container_hidden = htax_is_term_hidden( $term_id );

            // Build values for batch INSERT
            $values = array();
            $placeholders_arr = array();
            $reindex_post_ids = array();

            // Pre-prime term meta cache for all added terms to avoid N+1 get_term_meta calls.
            update_meta_cache( 'term', $added_term_ids );

            // For each newly included term, find posts with that term and add container relationship
            foreach ( $added_term_ids as $included_term_id ) {
                // Get posts that have this included term directly assigned
                $post_ids = $this->get_posts_directly_assigned_terms( $included_term_id, $taxonomy );
                $reindex_post_ids = array_merge( $reindex_post_ids, $post_ids );

                // Get whether source term is hidden (hits object cache primed above)
                $is_source_hidden = htax_is_term_hidden( $included_term_id );
                $is_hidden = $is_source_hidden || $is_container_hidden ? 1 : 0;

                foreach ( $post_ids as $post_id ) {
                    $values[] = $post_id;
                    $values[] = $term_id;
                    $values[] = $included_term_id; // source_term_id
                    $values[] = $taxonomy;
                    $values[] = 'container';
                    $values[] = $is_hidden;

                    $placeholders_arr[] = '(%d, %d, %d, %s, %s, %d)';
                }
            }

            // Execute batch INSERT
            htax_upsert_search_rows( $table_name, $placeholders_arr, $values, 'on_includes_changed: INSERT new includes' );

            // New rows carry the column-default strength; the reindex computes the real values.
            if ( ! empty( $reindex_post_ids ) ) {
                $this->queue_post_reindex( $reindex_post_ids );
            }
        }
    }

    /**
     * When term excludes change (what this term explicitly excludes)
     *
     * When Term A's excludes change, the full included set for Term A may change,
     * so posts associated with any previously or newly included term need reindexing.
     *
     * @param int $term_id Container term whose excludes changed
     * @param string $taxonomy Taxonomy of the container term
     * @param array $new_excluded New excluded term IDs
     * @param array $old_excluded Old excluded term IDs
     */
    public function on_excludes_changed( $term_id, $taxonomy, $new_excluded, $old_excluded ) {
        // Get included groups for this container term
        $included_groups = htax_get_direct_rel_group_ids( $term_id, 'includes' );

        if ( empty( $included_groups ) ) {
            // No included groups, so exclusions don't affect anything
            return;
        }

        // Get all terms that are members of any included group (single batched query).
        $affected_term_ids = array();
        foreach ( htax_get_terms_in_groups( $included_groups, $taxonomy ) as $member_term ) {
            if ( $member_term->term_id !== (int) $term_id ) {
                $affected_term_ids[] = $member_term->term_id;
            }
        }

        if ( empty( $affected_term_ids ) ) {
            return;
        }

        // Reindex posts with any of these terms (batched query)
        // Their container relationships may have changed due to exclusion rule changes
        $affected_term_ids = array_unique( $affected_term_ids );
        $post_ids = $this->get_posts_directly_assigned_terms( $affected_term_ids, $taxonomy );

        $this->queue_post_reindex( $post_ids );
    }

    /**
     * When term included groups change (which groups this term includes)
     *
     * When Term A's included groups change, posts with members of those groups need reindexing
     * because they now have (or no longer have) Term A as a container (via Path 3).
     *
     * @param int $term_id Container term whose included groups changed
     * @param string $taxonomy Taxonomy of the container term
     * @param array $new_groups New included group IDs
     * @param array $old_groups Old included group IDs
     */
    public function on_included_groups_changed( $term_id, $taxonomy, $new_groups, $old_groups ) {
        // Get all affected groups (both added and removed)
        $all_groups = array_unique( array_merge( $new_groups, $old_groups ) );

        if ( empty( $all_groups ) ) {
            return;
        }

        // Get exclusions to apply proper filtering
        $excluded_term_ids = htax_get_excluded_term_ids( $term_id );

        // Get all terms that are members of any affected group (single batched query).
        $affected_term_ids = array();
        foreach ( htax_get_terms_in_groups( $all_groups, $taxonomy ) as $member_term ) {
            if ( $member_term->term_id !== (int) $term_id && ! in_array( $member_term->term_id, $excluded_term_ids, true ) ) {
                $affected_term_ids[] = $member_term->term_id;
            }
        }

        if ( empty( $affected_term_ids ) ) {
            return;
        }

        // Reindex posts with any of these terms (batched query)
        $affected_term_ids = array_unique( $affected_term_ids );
        $post_ids = $this->get_posts_directly_assigned_terms( $affected_term_ids, $taxonomy );

        $this->queue_post_reindex( $post_ids );
    }

    /**
     * When inclusion-based custom relationship rows change on a term save.
     *
     * Container index rows derive from htax_get_container_terms(), which covers every
     * inclusion-based type, so posts with any affected term are fully reindexed.
     *
     * @since 1.0.0
     * @param int    $term_id           Term that was saved.
     * @param string $taxonomy          Its taxonomy.
     * @param int[]  $affected_term_ids Terms whose container sets changed.
     */
    public function on_custom_inclusions_changed( $term_id, $taxonomy, $affected_term_ids ) {
        $post_ids = $this->get_posts_directly_assigned_terms( array_map( 'intval', (array) $affected_term_ids ), $taxonomy );
        if ( ! empty( $post_ids ) ) {
            $this->queue_post_reindex( $post_ids );
        }
    }

    /**
     * When term visibility changes
     *
     * Similar to hierarchy changes, visibility changes affect which posts get which terms
     * indexed. When a term becomes hidden or unhidden, we need to reindex all posts
     * that might be affected by this term's relationships.
     *
     * @param int $term_id Term ID
     * @param string $taxonomy Taxonomy name
     */
    public function on_term_visibility_changed( $term_id, $taxonomy ) {
        // Group visibility does not affect the search index: group expansion and
        // archive contribution are shared across all users regardless of visibility.
        if ( 'group' === $taxonomy ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        // Recalculate is_hidden for all affected rows
        // is_hidden = (source is hidden OR term is hidden)
        //
        // Update rows where changed term appears as EITHER source_term_id OR term_id
        // Use LEFT JOIN so we get 0 when term is not hidden (no meta row)
        // Note: We don't filter by taxonomy to handle cross-taxonomy redirects
        // (e.g., tag redirecting to category - index row has category taxonomy,
        // but we need to update when tag visibility changes)
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name} idx
                 LEFT JOIN {$wpdb->prefix}termmeta tm_source
                   ON idx.source_term_id = tm_source.term_id
                   AND tm_source.meta_key = '_htax_is_hidden'
                 LEFT JOIN {$wpdb->prefix}termmeta tm_term
                   ON idx.term_id = tm_term.term_id
                   AND tm_term.meta_key = '_htax_is_hidden'
                 SET idx.is_hidden = (
                   CASE WHEN tm_source.meta_value = '1' OR tm_term.meta_value = '1' THEN 1 ELSE 0 END
                 )
                 WHERE idx.source_term_id = %d OR idx.term_id = %d",
                $term_id,
                $term_id
            )
        );

        htax_handle_db_error( 'on_term_visibility_changed' );

        // Clear count cache - visibility changes affect {total_count} in shortcodes
        htax_clear_count_cache( array( $term_id ), $taxonomy );
    }

    /**
     * AJAX handler for rebuilding the entire search index
     */
    public function ajax_rebuild_indexes() {
        // Check nonce and capabilities
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax-rebuild-index' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'hypertaxonomy' ) ) );
            return;
        }

        if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_hypertaxonomy_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'hypertaxonomy' ) ) );
            return;
        }

        htax_log_debug( 'Starting search index rebuild via AJAX' );

        global $wpdb;
        $this->clear_search_terms_for_rebuild();
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}htax_post_text" );

        if ( $wpdb->last_error ) {
            htax_log_error( 'Failed to truncate index tables', array(
                'error' => $wpdb->last_error
            ) );
            wp_send_json_error( array( 'message' => __( 'Database error. Check logs.', 'hypertaxonomy' ) ) );
            return;
        }

        // Get count of all published posts across all indexed post types
        $count = 0;
        foreach ( htax_get_indexed_post_types() as $post_type ) {
            $counts = wp_count_posts( $post_type );
            $count += isset( $counts->publish ) ? $counts->publish : 0;
        }

        htax_log_debug( 'Search index truncated, starting rebuild', array(
            'total_posts' => $count
        ) );

        wp_send_json_success( array(
            'message' => sprintf( __( 'Index cleared. Rebuilding %d posts...', 'hypertaxonomy' ), $count ),
            'total' => $count
        ) );
    }

    /**
     * Clear wp_htax_search_terms before a full rebuild.
     *
     * Deletes every built-in model row, which the rebuild recomputes. External
     * models' rows are kept for posts the rebuild will reindex, because their
     * scores can't be recomputed here; htax_index_post_terms() re-derives them as
     * each post is rebuilt. External rows of posts that won't be reindexed (no
     * longer published, or of a post type that is no longer indexed) are deleted.
     */
    private function clear_search_terms_for_rebuild(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'htax_search_terms';

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE model = %s", HTAX_DEFAULT_STRENGTH_MODEL ) );
        htax_handle_db_error( 'clear_search_terms_for_rebuild: built-in rows' );

        $indexed_types = htax_get_indexed_post_types();
        $type_in       = empty( $indexed_types )
            ? "''"
            : "'" . implode( "','", array_map( 'esc_sql', $indexed_types ) ) . "'";
        $wpdb->query(
            "DELETE st FROM {$table} st
             LEFT JOIN {$wpdb->posts} p
               ON p.ID = st.post_id AND p.post_status = 'publish' AND p.post_type IN ({$type_in})
             WHERE p.ID IS NULL"
        );
        htax_handle_db_error( 'clear_search_terms_for_rebuild: orphaned external rows' );
    }

    public function ajax_bulk_rebuild() {
        check_ajax_referer( 'htax-rebuild-index', 'nonce' );

        if (!HyperTaxonomy_Capabilities::current_user_can('manage_hypertaxonomy_settings')) {
            wp_die( __( 'Unauthorized access.', 'hypertaxonomy' ), 403 );
        }

        set_time_limit( 300 );

        $offset = isset( $_POST['offset'] ) ? intval( wp_unslash( $_POST['offset'] ) ) : 0;
        $batch_size = 50;

        $post_ids = $this->get_indexable_post_ids( array( 'publish' ), $batch_size, $offset );

        htax_prewarm_redirect_cache();
        // Sync post text rows first so strength calculation has current content.
        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( $post ) {
                htax_sync_post_text_row( $post_id, $post );
            }
        }
        $post_text_map = htax_fetch_post_text_batch( $post_ids );
        foreach ( $post_ids as $post_id ) {
            htax_build_post_search_index( $post_id, $post_text_map[ $post_id ] ?? array() );
        }

        $done = count( $post_ids ) < $batch_size;

        if ( $done ) {
            htax_log_debug( 'Search index rebuild completed via AJAX', array(
                'total_processed' => $offset + count( $post_ids ),
            ) );
        }

        wp_send_json_success( array(
            'processed'   => count( $post_ids ),
            'next_offset' => $offset + $batch_size,
            'done'        => $done,
        ) );
    }

    /**
     * Rebuild both search indexes on activation, reactivation, or manual trigger.
     *
     * Clears wp_htax_search_terms (see clear_search_terms_for_rebuild()) and
     * wp_htax_post_text, then repopulates both from the current published post set.
     * Stale rows from deleted posts or changed indexed_post_types settings are
     * cleared in the process.
     *
     * Runs as a background WP Cron job (htax_rebuild_indexes) scheduled 30 seconds
     * after activation so repair scans complete before the index is built.
     */
    public function rebuild_indexes(): void {
        ini_set( 'memory_limit', '512M' );
        set_time_limit( 300 );

        $this->clear_search_terms_for_rebuild();

        htax_log_debug( 'Starting full index rebuild' );

        $post_ids = $this->get_indexable_post_ids( array( 'publish' ) );

        if ( empty( $post_ids ) ) {
            htax_log_debug( 'No posts to index' );
            return;
        }

        htax_log_debug( 'Rebuilding indexes', array( 'count' => count( $post_ids ) ) );

        // Post text must be rebuilt before search terms — strength values depend on it.
        $this->rebuild_post_text_table();
        $this->batch_reindex_posts( $post_ids );

        htax_log_debug( 'Full index rebuild completed', array( 'posts_indexed' => count( $post_ids ) ) );
    }

    /**
     * Truncate and repopulate wp_htax_post_text.
     *
     * Stores published, draft, pending, future, and private posts so that
     * draft text is available for editor tag suggestions. Public search queries
     * filter by post_status = 'publish' at query time.
     * Processes posts in chunks of 100 to keep memory usage bounded.
     */
    private function rebuild_post_text_table(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}htax_post_text" );

        $batch_size      = 100;
        $offset          = 0;
        $stored_statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );

        do {
            $post_ids = $this->get_indexable_post_ids( $stored_statuses, $batch_size, $offset );
            _prime_post_caches( $post_ids, false, false );

            foreach ( $post_ids as $post_id ) {
                $post = get_post( $post_id );
                if ( $post ) {
                    htax_sync_post_text_row( $post_id, $post );
                }
            }

            $offset += $batch_size;
        } while ( count( $post_ids ) === $batch_size );
    }

    /**
     * Get IDs of posts of the indexed post types with the given statuses, by ID.
     *
     * Index maintenance reads wp_posts directly rather than via WP_Query/get_posts():
     * pre_get_posts handlers (including HyperTaxonomy's own hidden-term exclusion,
     * which applies to WP Cron requests because they have no privileged user) would
     * otherwise silently drop posts from rebuilds.
     *
     * @since 1.0.0
     *
     * @param string[] $statuses Post statuses to include.
     * @param int      $limit    Maximum number of IDs; 0 = no limit.
     * @param int      $offset   Number of IDs to skip (only with $limit).
     * @return int[] Post IDs in ascending order.
     */
    private function get_indexable_post_ids( array $statuses, int $limit = 0, int $offset = 0 ): array {
        global $wpdb;

        $post_types = htax_get_indexed_post_types();
        if ( empty( $post_types ) || empty( $statuses ) ) {
            return array();
        }

        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN (" . htax_sql_placeholders( count( $post_types ), 's' ) . ")
               AND post_status IN (" . htax_sql_placeholders( count( $statuses ), 's' ) . ")
             ORDER BY ID ASC",
            array_merge( $post_types, $statuses )
        );
        if ( $limit > 0 ) {
            $sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
        }

        $ids = $wpdb->get_col( $sql );
        htax_handle_db_error( 'get_indexable_post_ids' );

        return array_map( 'intval', (array) $ids );
    }
    
    /**
     * Get posts that have these terms (by term_id)
     *
     * Finds posts where the specified terms appear in the term_id column,
     * meaning posts that "have" these terms through any relationship type.
     *
     * @param array|int $term_ids Single term ID or array of term IDs.
     * @return array Array of post IDs.
     */
    public function get_posts_with_term_ids( $term_ids ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        if ( empty( $term_ids ) ) {
            return array();
        }

        if ( ! is_array( $term_ids ) ) {
            $term_ids = array( $term_ids );
        }

        $placeholders = htax_sql_placeholders( count( $term_ids ) );

        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id
                 FROM {$table_name}
                 WHERE term_id IN ($placeholders)
                   AND is_hidden = 0",
                $term_ids
            )
        );

        htax_handle_db_error( 'get_posts_with_term_ids' );

        return array_map( 'intval', $post_ids );
    }

    /**
     * Get posts where these terms were directly assigned
     *
     * Queries by source_term_id with relationship_type = 'direct'.
     * Returns posts where these terms appear as the source of a direct assignment.
     *
     * This is used when we need to update derived relationships (containers, includes, etc.)
     * that depend on which terms are directly assigned to posts.
     *
     * @param array|int $term_ids  Single term ID or array of term IDs.
     * @param string    $taxonomy  Taxonomy name.
     * @return array Array of post IDs.
     */
    public function get_posts_directly_assigned_terms( $term_ids, $taxonomy ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        if ( empty( $term_ids ) ) {
            return array();
        }

        if ( ! is_array( $term_ids ) ) {
            $term_ids = array( $term_ids );
        }

        $placeholders = htax_sql_placeholders( count( $term_ids ) );
        $query_args = array_merge( $term_ids, array( $taxonomy ) );

        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id
                 FROM {$table_name}
                 WHERE source_term_id IN ($placeholders)
                   AND taxonomy = %s
                   AND relationship_type = 'direct'",
                $query_args
            )
        );

        htax_handle_db_error( 'get_posts_directly_assigned_terms' );

        return array_map( 'intval', $post_ids );
    }

    /**
     * Get source term IDs from index for a post
     *
     * Returns the list of directly assigned terms (where relationship_type = 'direct')
     * that are currently in the index for this post.
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @return array Array of term IDs
     */
    private function get_source_terms_from_index( $post_id, $taxonomy ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT term_id
                 FROM {$table_name}
                 WHERE post_id = %d
                   AND taxonomy = %s
                   AND relationship_type = 'direct'",
                $post_id,
                $taxonomy
            )
        );

        htax_handle_db_error( 'get_source_terms_from_index' );

        return array_map( 'intval', $term_ids );
    }

    /**
     * Add all relationships for a single term to the index
     *
     * Adds direct relationship plus all indirect relationships (containers, hierarchy, redirects, etc.)
     * for a single term assigned to a post.
     *
     * @param int $post_id Post ID
     * @param int $term_id Term ID to add
     * @param string $taxonomy Taxonomy name
     * @return array Array of relationship data to be inserted
     */
    private function add_term_relationships_to_index( $post_id, $term_id, $taxonomy ) {
        $term_relationships = array();

        // Helper closure to add term relationship
        $add_term_rel = function( $tid, $source_tid, $tax, $rel_type ) use ( &$term_relationships ) {
            $term_relationships[] = array(
                'term_id'           => $tid,
                'source_term_id'    => $source_tid,
                'taxonomy'          => $tax,
                'relationship_type' => $rel_type,
                'is_hidden'         => htax_is_term_hidden( $source_tid ) || htax_is_term_hidden( $tid ),
            );
        };

        // Track indexed pairs to avoid duplicates
        $indexed_pairs = array();

        // 1. Add direct relationship (source = itself)
        $pair_key = $term_id . ':' . $term_id . ':direct';
        if ( ! isset( $indexed_pairs[$pair_key] ) ) {
            $add_term_rel( $term_id, $term_id, $taxonomy, 'direct' );
            $indexed_pairs[$pair_key] = true;
        }

        // 2. Add indirect relationships based on taxonomy capabilities.
        // Applies to any HT-enabled taxonomy — flat or hierarchical, with or without inclusion.
        $is_hierarchical = is_taxonomy_hierarchical( $taxonomy );

        // Add parent hierarchy relationships (hierarchical taxonomies only).
        // Stop the chain at any redirect ancestor: a redirect term has no archive,
        // so neither it nor ancestors above it should match descendants.
        if ( $is_hierarchical ) {
            $ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
            foreach ( $ancestors as $parent_id ) {
                if ( htax_get_redirect_target( $parent_id ) ) {
                    break; // Redirect in chain — don't index this or any higher ancestor.
                }
                $pair_key = $parent_id . ':' . $term_id . ':hierarchy';

                if ( ! isset( $indexed_pairs[$pair_key] ) ) {
                    $add_term_rel( $parent_id, $term_id, $taxonomy, 'hierarchy' );
                    $indexed_pairs[$pair_key] = true;
                }
            }
        }

        // Add container relationships. Always indexed regardless of whether the
        // inclusion feature is currently enabled — feature gates happen at query time.
        // In hierarchical taxonomies each container is expanded to itself plus its WP
        // descendants, so a search on a container's child term also matches these posts.
        $containers = array_keys( htax_get_container_terms( $term_id, true ) );

            foreach ( $containers as $container_id ) {
                $container_ids = $is_hierarchical
                    ? htax_expand_term_hierarchy( array( $container_id ), $taxonomy )
                    : array( $container_id );

                foreach ( $container_ids as $hier_id ) {
                    $pair_key = $hier_id . ':' . $term_id . ':container';

                    if ( ! isset( $indexed_pairs[$pair_key] ) ) {
                        $add_term_rel( $hier_id, $term_id, $taxonomy, 'container' );
                        $indexed_pairs[$pair_key] = true;
                    }
                }
            }

        // 3. Add redirect_target relationship. Always indexed regardless of whether the
        // redirects feature is currently enabled — feature gates happen at query time.
        $redirect_target = htax_get_redirect_target( $term_id );

        if ( $redirect_target ) {
            $pair_key = $redirect_target->term_id . ':' . $term_id . ':redirect_target';

            if ( ! isset( $indexed_pairs[$pair_key] ) ) {
                // When term X redirects to term Y, posts with X directly assigned
                // should be found when searching for Y
                // term_id = redirect target (what user searches for)
                // source_term_id = redirecting term (what's assigned to post)
                $add_term_rel( $redirect_target->term_id, $term_id, $redirect_target->taxonomy, 'redirect_target' );

                // Mark as hidden if EITHER redirecting term OR target is hidden
                self::mark_redirect_as_hidden_if_needed( $term_relationships, $redirect_target, $term_id );

                $indexed_pairs[$pair_key] = true;
            }
        }

        // 4. Insert all collected relationships
        if ( ! empty( $term_relationships ) ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'htax_search_terms';

            foreach ( $term_relationships as $rel ) {
                $wpdb->insert(
                    $table_name,
                    array(
                        'post_id'           => $post_id,
                        'term_id'           => $rel['term_id'],
                        'source_term_id'    => $rel['source_term_id'],
                        'taxonomy'          => $rel['taxonomy'],
                        'relationship_type' => $rel['relationship_type'],
                        'is_hidden'         => $rel['is_hidden'],
                    ),
                    array( '%d', '%d', '%d', '%s', '%s', '%d' )
                );
            }

            htax_handle_db_error( 'add_term_relationships_to_index' );
        }

        return $term_relationships;
    }

    /**
     * Incrementally update index for added/removed terms
     *
     * Instead of rebuilding entire index for a post, only update relationships
     * for terms that were added or removed. This is much faster for typical
     * small changes (1-3 terms).
     *
     * @param int $post_id Post ID
     * @param array $added_terms Term IDs that were added
     * @param array $removed_terms Term IDs that were removed
     * @param string $taxonomy Taxonomy name
     */
    private function incremental_index_update( $post_id, $added_terms, $removed_terms, $taxonomy ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_search_terms';

        // 1. Remove relationships for removed terms
        if ( ! empty( $removed_terms ) ) {
            $placeholders = htax_sql_placeholders( count( $removed_terms ) );
            $query_params = array_merge( array( $post_id ), $removed_terms );

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_name}
                     WHERE post_id = %d
                       AND source_term_id IN ($placeholders)",
                    $query_params
                    // source_term_id alone identifies which rows to remove — no taxonomy filter needed
                )
            );

            htax_handle_db_error( 'incremental_index_update - remove terms' );
        }

        // 2. Add relationships for added terms
        if ( ! empty( $added_terms ) ) {
            foreach ( $added_terms as $term_id ) {
                $this->add_term_relationships_to_index( $post_id, $term_id, $taxonomy );
            }

            // The rows above are written immediately so the post is findable by its new
            // terms, but without a computed strength (column default). The queued reindex
            // replaces them with strengths from htax_index_post_terms().
            $this->queue_post_reindex( $post_id );
        }
    }

    /**
     * Queue posts for reindexing (with automatic deduplication)
     *
     * Posts are queued and deduplicated, then processed once at shutdown.
     * This prevents the same post from being reindexed multiple times in a single request.
     *
     * @param array|int $post_ids Single post ID or array of post IDs to queue
     */
    public function queue_post_reindex( $post_ids ) {
        // Normalize to array
        $post_ids = (array) $post_ids;

        // Add to queue
        self::$pending_reindex_posts = array_merge(
            self::$pending_reindex_posts,
            $post_ids
        );

        // Schedule processing if not already scheduled
        if ( ! self::$reindex_scheduled ) {
            add_action( 'shutdown', array( $this, 'process_reindex_queue' ), 1 );
            self::$reindex_scheduled = true;
        }
    }

    /**
     * Process the reindex queue
     *
     * Called automatically at shutdown. Deduplicates queued posts and processes them.
     */
    public function process_reindex_queue() {
        if ( empty( self::$pending_reindex_posts ) ) {
            return;
        }

        // Deduplicate and normalize
        $post_ids = htax_sanitize_term_ids( self::$pending_reindex_posts );

        // Process the batch
        $this->batch_reindex_posts( $post_ids );

        // Clear queue
        self::$pending_reindex_posts = array();
    }

    /**
     * Batch reindex multiple posts efficiently
     *
     * Appends the posts to the persistent queue (htax_pending_reindex) and schedules
     * WP Cron processing, which works through the queue in batches of
     * REINDEX_BATCH_SIZE (see process_async_reindex_batch()).
     *
     * @param array $post_ids Array of post IDs to reindex
     */
    private function batch_reindex_posts( $post_ids ) {
        if ( empty( $post_ids ) ) {
            return;
        }

        // Remove duplicates
        $post_ids = htax_sanitize_term_ids( $post_ids );

        // Always defer to cron — no reindex batch belongs in the save request's shutdown.
        $existing = get_option( 'htax_pending_reindex', array() );
        update_option(
            'htax_pending_reindex',
            htax_sanitize_term_ids( array_merge( (array) $existing, $post_ids ) ),
            false
        );

        if ( ! wp_next_scheduled( 'htax_process_reindex_batch' ) ) {
            wp_schedule_single_event( time(), 'htax_process_reindex_batch' );
        }
    }

    /**
     * When HT taxonomy enabled/disabled state changes, keep the search index in sync.
     *
     * Disabled taxonomies: existing index rows are deleted immediately.
     * Newly enabled taxonomies: posts that carry terms in the taxonomy are queued for reindex.
     *
     * @param mixed $old_value Previous htax_settings value.
     * @param mixed $new_value New htax_settings value.
     */
    public function on_taxonomy_enabled_state_changed( $old_value, $new_value ) {
        $old_enabled = isset( $old_value['enabled_taxonomies'] ) && is_array( $old_value['enabled_taxonomies'] )
            ? $old_value['enabled_taxonomies'] : array();
        $new_enabled = isset( $new_value['enabled_taxonomies'] ) && is_array( $new_value['enabled_taxonomies'] )
            ? $new_value['enabled_taxonomies'] : array();

        $all_keys = array_unique( array_merge( array_keys( $old_enabled ), array_keys( $new_enabled ) ) );

        $newly_disabled = array();
        $newly_enabled  = array();

        foreach ( $all_keys as $taxonomy ) {
            $was_enabled = ! empty( $old_enabled[ $taxonomy ] );
            $is_enabled  = ! empty( $new_enabled[ $taxonomy ] );

            if ( $was_enabled && ! $is_enabled ) {
                $newly_disabled[] = $taxonomy;
            } elseif ( ! $was_enabled && $is_enabled ) {
                $newly_enabled[] = $taxonomy;
            }
        }

        if ( empty( $newly_disabled ) && empty( $newly_enabled ) ) {
            return;
        }

        // Clear all transient caches — stale redirect targets, includes, containers, and
        // group member caches may reference terms from the taxonomy being disabled/enabled.
        htax_clear_all_caches();

        global $wpdb;
        $table = $wpdb->prefix . 'htax_search_terms';

        foreach ( $newly_disabled as $taxonomy ) {
            $wpdb->delete( $table, array( 'taxonomy' => $taxonomy ), array( '%s' ) );
            htax_log_debug( 'Removed search index entries for disabled taxonomy', array( 'taxonomy' => $taxonomy ) );
        }

        if ( ! empty( $newly_enabled ) ) {
            $indexed_post_types = htax_get_indexed_post_types();
            if ( empty( $indexed_post_types ) ) {
                return;
            }

            $post_ids = array();

            foreach ( $newly_enabled as $taxonomy ) {
                $found    = $this->get_published_post_ids_in_taxonomy( $taxonomy );
                $post_ids = array_merge( $post_ids, $found );

                htax_log_debug( 'Queuing posts for reindex after taxonomy enabled', array(
                    'taxonomy'   => $taxonomy,
                    'post_count' => count( $found ),
                ) );
            }

            if ( ! empty( $post_ids ) ) {
                $this->batch_reindex_posts( array_unique( array_map( 'intval', $post_ids ) ) );
            }
        }
    }

    /**
     * When propagation weights change, recompute stored strengths for affected posts.
     *
     * Indirect rows (hierarchy, container, redirect_target) store the source term's
     * strength multiplied by the taxonomy's explicit weight for that relationship type,
     * fixed at index time. Changing one of those weights therefore requires reindexing
     * every published post with terms in the taxonomy. Direct strengths don't depend on
     * these settings, and text/description weights are applied at query time.
     *
     * @since 1.0.0
     *
     * @param mixed $old_value Previous htax_settings value.
     * @param mixed $new_value New htax_settings value.
     */
    public function on_relevance_weights_changed( $old_value, $new_value ) {
        $old_value = is_array( $old_value ) ? $old_value : array();
        $new_value = is_array( $new_value ) ? $new_value : array();

        $post_ids = array();

        foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
            $old_weights = htax_get_relevance_weights( $taxonomy, $old_value );
            $new_weights = htax_get_relevance_weights( $taxonomy, $new_value );

            $changed = false;
            foreach ( array( 'hierarchy', 'container', 'redirect' ) as $type_key ) {
                $old_explicit = isset( $old_weights[ $type_key ]['explicit'] ) ? (float) $old_weights[ $type_key ]['explicit'] : 1.0;
                $new_explicit = isset( $new_weights[ $type_key ]['explicit'] ) ? (float) $new_weights[ $type_key ]['explicit'] : 1.0;
                if ( abs( $old_explicit - $new_explicit ) > 0.000001 ) {
                    $changed = true;
                    break;
                }
            }

            if ( ! $changed ) {
                continue;
            }

            $found    = $this->get_published_post_ids_in_taxonomy( $taxonomy );
            $post_ids = array_merge( $post_ids, $found );

            htax_log_debug( 'Queuing posts for reindex after propagation weight change', array(
                'taxonomy'   => $taxonomy,
                'post_count' => count( $found ),
            ) );
        }

        if ( ! empty( $post_ids ) ) {
            $this->batch_reindex_posts( array_unique( array_map( 'intval', $post_ids ) ) );
        }
    }

    /**
     * When a published post is saved, recompute its strengths.
     *
     * Direct strengths are derived from the post's title, excerpt, and body
     * (htax_sync_post_text_row() refreshes that text on the same save_post action),
     * so a content edit changes them even when no term assignment changes.
     *
     * @since 1.0.0
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function on_post_saved( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, htax_get_indexed_post_types(), true ) ) {
            return;
        }

        $this->queue_post_reindex( $post_id );
    }

    /**
     * Get published, indexed-type posts that have any term in a taxonomy assigned.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy Taxonomy slug.
     * @return int[] Post IDs.
     */
    private function get_published_post_ids_in_taxonomy( string $taxonomy ): array {
        global $wpdb;

        $indexed_post_types = htax_get_indexed_post_types();
        if ( empty( $indexed_post_types ) || ! taxonomy_exists( $taxonomy ) ) {
            return array();
        }

        $type_placeholders = htax_sql_placeholders( count( $indexed_post_types ), 's' );

        $found = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tr.object_id
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                 WHERE tt.taxonomy = %s
                 AND p.post_type IN ($type_placeholders)
                 AND p.post_status = 'publish'",
                array_merge( array( $taxonomy ), $indexed_post_types )
            )
        );

        htax_handle_db_error( 'get_published_post_ids_in_taxonomy' );

        return array_map( 'intval', (array) $found );
    }

    /**
     * Process async reindex batch (WP Cron handler)
     *
     * Reindexes up to REINDEX_BATCH_SIZE queued posts per run and schedules another
     * run while posts remain, so a large queue (e.g. every post in a taxonomy after a
     * propagation weight change) drains over several cron events instead of one
     * request that could exceed PHP time or memory limits.
     * Called automatically by WP Cron when scheduled.
     */
    public function process_async_reindex_batch() {
        $queue = (array) get_option( 'htax_pending_reindex', array() );

        if ( empty( $queue ) ) {
            return;
        }

        $post_ids  = array_slice( $queue, 0, self::REINDEX_BATCH_SIZE );
        $remaining = array_slice( $queue, self::REINDEX_BATCH_SIZE );

        // Write the remainder back BEFORE processing. Posts queued during this run are
        // merged into that stored remainder by batch_reindex_posts(); updating the queue
        // after processing instead would silently discard those additions.
        if ( empty( $remaining ) ) {
            delete_option( 'htax_pending_reindex' );
        } else {
            update_option( 'htax_pending_reindex', array_values( $remaining ), false );

            // WP Cron unschedules a single event before running it, so this schedules
            // the next batch unless a post queued meanwhile already did.
            if ( ! wp_next_scheduled( 'htax_process_reindex_batch' ) ) {
                wp_schedule_single_event( time(), 'htax_process_reindex_batch' );
            }
        }

        // Sync post_text rows first (same as the manual rebuild path). If a row was
        // never created or was stale, this repairs it before building term relationships.
        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( $post ) {
                htax_sync_post_text_row( $post_id, $post );
            }
        }

        htax_prewarm_redirect_cache();
        $post_text_map = htax_fetch_post_text_batch( $post_ids );
        foreach ( $post_ids as $post_id ) {
            htax_build_post_search_index( $post_id, $post_text_map[ $post_id ] ?? array() );
        }
    }

}

// Initialize the index manager
HyperTaxonomy_Search_Index_Manager::get_instance();

/**
 * Create the normalized search terms table
 *
 * This table stores post-term associations for FULLTEXT search and filtering.
 * Text matching (term names, descriptions) is done at query time via wp_terms
 * and wp_term_taxonomy, which carry FULLTEXT indexes and always reflect the
 * current term data without any denormalization to maintain.
 *
 * Strength is stored per scoring model (model column, part of the primary key).
 * Rows written without a model belong to the built-in model
 * (HTAX_DEFAULT_STRENGTH_MODEL); see htax_get_strength_model() for which reads
 * must filter on it.
 *
 * @since 1.0
 */
function htax_create_search_term_index_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'htax_search_terms';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        post_id bigint(20) NOT NULL,
        term_id bigint(20) NOT NULL,
        source_term_id bigint(20) NOT NULL,
        taxonomy varchar(32) NOT NULL,
        relationship_type varchar(32) NOT NULL,
        model varchar(64) NOT NULL DEFAULT '" . HTAX_DEFAULT_STRENGTH_MODEL . "',
        is_hidden tinyint(1) NOT NULL DEFAULT 0,
        strength float NOT NULL DEFAULT 1.0,
        PRIMARY KEY (post_id, term_id, source_term_id, relationship_type, model),
        KEY idx_taxonomy (taxonomy),
        KEY idx_term_lookup (term_id),
        KEY idx_post_lookup (post_id),
        KEY idx_relationship (relationship_type, taxonomy),
        KEY idx_hidden (is_hidden, relationship_type),
        KEY idx_source_term (source_term_id)
    ) $charset_collate ENGINE=InnoDB;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/**
 * Calculate raw TF-based strength for a direct term-to-post relationship.
 *
 * Returns a value in [0, 1] reflecting how central the term is to the post's
 * content. Used internally; callers apply the relationship-type weight on top.
 *
 * Field coefficients sum to 1.0 at maximum and are filterable via
 * 'htax_strength_field_weights' for site-specific tuning. This is the built-in
 * strength model (HTAX_DEFAULT_STRENGTH_MODEL); other models submit their own
 * scores via htax_set_term_strengths().
 *
 * @since 1.0
 *
 * @param int   $term_id       Term ID.
 * @param array $post_text     Associative array with 'title', 'body', 'excerpt' keys (plain text).
 * @param array $field_weights Coefficients: 'floor', 'title', 'excerpt', 'body'.
 * @return float Strength in [0.0, 1.0].
 */
function htax_calculate_direct_term_strength( int $term_id, array $post_text, array $field_weights ): float {
    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return 1.0;
    }

    $name    = strtolower( $term->name );
    $title   = strtolower( $post_text['title']   ?? '' );
    $excerpt = strtolower( $post_text['excerpt'] ?? '' );
    $body    = strtolower( $post_text['body']    ?? '' );

    $body_words = str_word_count( $body );
    $body_tf    = $body_words > 0
        ? min( 1.0, substr_count( $body, $name ) / max( 1, $body_words ) * 100 )
        : 0.0;

    $score = $field_weights['floor']
           + $body_tf * $field_weights['body']
           + ( str_contains( $excerpt, $name ) ? $field_weights['excerpt'] : 0.0 )
           + ( str_contains( $title,   $name ) ? $field_weights['title']   : 0.0 );

    return (float) min( 1.0, $score );
}

/**
 * Store a post's search-index rows for every strength model.
 *
 * Replaces all of the post's rows. Built-in model rows are computed from the
 * post text: direct rows get the TF score, indirect rows the source term's TF
 * score × the taxonomy's explicit relationship weight (htax_derive_indirect_strength()).
 * Direct strengths previously stored by external models are kept for terms that
 * are still directly assigned, and their indirect rows are re-derived against the
 * current structure. For each registered external model, directly assigned terms
 * it has not scored are reported via 'htax_term_strength_missing'.
 *
 * @param int   $post_id       Post ID.
 * @param array $relationships Array of arrays, each containing: ['term_id' => int, 'source_term_id' => int, 'taxonomy' => string, 'relationship_type' => string, 'is_hidden' => bool].
 * @param array $post_text     Plain-text post fields ('title', 'body', 'excerpt') from wp_htax_post_text. Empty array falls back to neutral strength 1.0.
 */
function htax_index_post_terms( $post_id, $relationships, array $post_text = [] ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_search_terms';
    $post_id    = (int) $post_id;

    // External models' direct strengths can't be recomputed here — read them before
    // the delete so they survive the rebuild.
    $external_strengths = htax_get_external_direct_strengths( $post_id );

    // Delete existing relationships for this post (every model). Done even when the
    // post has no relationships left, so a post whose terms were all removed doesn't
    // keep stale rows.
    $wpdb->delete(
        $table_name,
        array( 'post_id' => $post_id ),
        array( '%d' )
    );
    htax_handle_db_error( 'htax_index_post_terms_delete' );

    if ( empty( $relationships ) ) {
        return;
    }

    // Field weights for TF formula — filterable for site-specific tuning.
    $field_weights = apply_filters( 'htax_strength_field_weights', array(
        'floor'   => 0.20,
        'body'    => 0.40,
        'excerpt' => 0.20,
        'title'   => 0.20,
    ) );

    // Built-in model: TF score for each directly assigned term.
    $direct_strengths = array();
    foreach ( $relationships as $rel ) {
        if ( 'direct' === $rel['relationship_type'] && ! empty( $rel['term_id'] ) ) {
            $direct_strengths[ (int) $rel['term_id'] ] =
                htax_calculate_direct_term_strength( (int) $rel['term_id'], $post_text, $field_weights );
        }
    }

    $placeholders = array();
    $values       = array();
    htax_collect_strength_rows( $post_id, $relationships, $direct_strengths, HTAX_DEFAULT_STRENGTH_MODEL, false, $placeholders, $values );

    // External models: keep scores for terms still directly assigned; unscored pairs are not stored.
    foreach ( $external_strengths as $model => $model_strengths ) {
        $kept = array_intersect_key( $model_strengths, $direct_strengths );
        if ( ! empty( $kept ) ) {
            htax_collect_strength_rows( $post_id, $relationships, $kept, $model, true, $placeholders, $values );
        }
    }

    if ( ! htax_write_strength_rows( $placeholders, $values ) ) {
        htax_handle_db_error( 'htax_index_post_terms_insert' );
        return;
    }

    // Report directly assigned terms each registered external model has not scored.
    foreach ( array_keys( htax_get_strength_models() ) as $model ) {
        if ( HTAX_DEFAULT_STRENGTH_MODEL === $model ) {
            continue;
        }
        $missing = array_keys( array_diff_key( $direct_strengths, $external_strengths[ $model ] ?? array() ) );
        if ( ! empty( $missing ) ) {
            /**
             * Fires when a post is (re)indexed and a registered external strength model
             * has no strength for some of its directly assigned terms — e.g. a newly
             * published post, a newly assigned term, or a model registered after the
             * post was scored. The model's provider should score these terms and
             * submit them with htax_set_term_strengths(). Fires during background
             * reindexes and full rebuilds, so slow work (e.g. remote API calls) should
             * be deferred rather than done inline.
             *
             * @since 1.0.0
             *
             * @param int    $post_id  Post ID.
             * @param int[]  $term_ids Directly assigned term IDs without a strength from the model.
             * @param string $model    Model key.
             */
            do_action( 'htax_term_strength_missing', $post_id, $missing, $model );
        }
    }
}

/**
 * Fetch plain-text post data for a batch of posts from wp_htax_post_text.
 *
 * Returns an array keyed by post_id; each value is an associative array with
 * 'title', 'body', 'excerpt' keys. Posts not yet present in the table are
 * omitted (callers fall back to empty array / neutral strength 1.0).
 *
 * @since 1.0
 *
 * @param int[] $post_ids Post IDs to fetch.
 * @return array<int, array{title: string, body: string, excerpt: string}>
 */
function htax_fetch_post_text_batch( array $post_ids ): array {
    global $wpdb;

    if ( empty( $post_ids ) ) {
        return array();
    }

    $ids_str = implode( ',', array_map( 'intval', $post_ids ) );
    $rows    = $wpdb->get_results(
        "SELECT post_id, title, body, excerpt FROM {$wpdb->prefix}htax_post_text WHERE post_id IN ($ids_str)",
        ARRAY_A
    );

    $map = array();
    foreach ( $rows as $row ) {
        $map[ (int) $row['post_id'] ] = $row;
    }

    return $map;
}

/**
 * Upsert a batch of rows into wp_htax_search_terms (excluding the strength column).
 *
 * Updates is_hidden on duplicate key so visibility changes propagate without
 * re-inserting every row. New rows get the column-default strength and model, so
 * callers must queue the affected posts for reindex (queue_post_reindex()) to
 * replace the default with a computed strength.
 *
 * @since 1.0.0
 * @param string   $table_name    Fully-qualified table name.
 * @param string[] $placeholders  Per-row placeholder strings, e.g. '(%d, %d, %d, %s, %s, %d)'.
 * @param array    $values        Flat list of values matching all placeholders.
 * @param string   $error_context Passed to htax_handle_db_error(); omit to skip error check.
 */
function htax_upsert_search_rows( string $table_name, array $placeholders, array $values, string $error_context = '' ): void {
	global $wpdb;
	if ( empty( $placeholders ) ) {
		return;
	}
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table_name}
			 (post_id, term_id, source_term_id, taxonomy, relationship_type, is_hidden)
			 VALUES " . implode( ', ', $placeholders ) . "
			 ON DUPLICATE KEY UPDATE is_hidden = VALUES(is_hidden)",
			$values
		)
	);
	if ( '' !== $error_context ) {
		htax_handle_db_error( $error_context );
	}
}

/**
 * Index all term relationships for a post within one taxonomy.
 *
 * Handles direct, hierarchy, container, and redirect relationships in a single
 * pass. Called once per enabled taxonomy from htax_build_post_search_index().
 *
 * Relationship types:
 * - direct: term is directly assigned to the post
 * - hierarchy: term is an ancestor of a directly assigned term (hierarchical taxonomies only;
 *   chain stops at any redirect ancestor, which has no archive of its own)
 * - container: term includes the directly assigned term (via direct inclusion or group membership)
 * - redirect_target: term redirects to the directly assigned term
 *
 * All types are always written to the index; feature gates (redirects disabled, inclusions
 * disabled) are enforced at query time, not at index time.
 *
 * @param string   $taxonomy         Taxonomy to process.
 * @param int[]    $direct_term_ids  Terms directly assigned to the post in this taxonomy.
 * @param array    $term_relationships Relationship rows collected so far (by reference).
 * @param array    $indexed_pairs    Deduplication map keyed "term_id:source_id:type" (by reference).
 * @param callable $add_term_rel     Closure that appends one row to $term_relationships.
 */
function htax_index_post_taxonomy_terms(
    string $taxonomy,
    array $direct_term_ids,
    array &$term_relationships,
    array &$indexed_pairs,
    callable $add_term_rel
): void {
    if ( empty( $direct_term_ids ) ) {
        return;
    }

    $is_hierarchical = is_taxonomy_hierarchical( $taxonomy );

    foreach ( $direct_term_ids as $term_id ) {

        // Direct relationship.
        $pair_key = $term_id . ':' . $term_id . ':direct';
        if ( ! isset( $indexed_pairs[ $pair_key ] ) ) {
            $add_term_rel( $term_id, $term_id, $taxonomy, 'direct' );
            $indexed_pairs[ $pair_key ] = true;
        }

        // Hierarchy relationships (hierarchical taxonomies only).
        if ( $is_hierarchical ) {
            $ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
            foreach ( $ancestors as $parent_id ) {
                if ( htax_get_redirect_target( $parent_id ) ) {
                    break; // Redirect in chain — don't index this or any higher ancestor.
                }
                $pair_key = $parent_id . ':' . $term_id . ':hierarchy';
                if ( ! isset( $indexed_pairs[ $pair_key ] ) ) {
                    $add_term_rel( $parent_id, $term_id, $taxonomy, 'hierarchy' );
                    $indexed_pairs[ $pair_key ] = true;
                }
            }
        }

        // Container relationships.
        $containers = array_keys( htax_get_container_terms( $term_id, true ) );
        foreach ( $containers as $container_id ) {
            $container_ids = $is_hierarchical
                ? htax_expand_term_hierarchy( array( $container_id ), $taxonomy )
                : array( $container_id );

            foreach ( $container_ids as $hier_id ) {
                $pair_key = $hier_id . ':' . $term_id . ':container';
                if ( ! isset( $indexed_pairs[ $pair_key ] ) ) {
                    $add_term_rel( $hier_id, $term_id, $taxonomy, 'container' );
                    $indexed_pairs[ $pair_key ] = true;
                }
            }
        }

        // Redirect relationship.
        $redirect_target = htax_get_redirect_target( $term_id );
        if ( $redirect_target ) {
            $pair_key = $redirect_target->term_id . ':' . $term_id . ':redirect_target';
            if ( ! isset( $indexed_pairs[ $pair_key ] ) ) {
                $add_term_rel( $redirect_target->term_id, $term_id, $redirect_target->taxonomy, 'redirect_target' );
                HyperTaxonomy_Search_Index_Manager::mark_redirect_as_hidden_if_needed( $term_relationships, $redirect_target, $term_id );
                $indexed_pairs[ $pair_key ] = true;
            }
        }
    }
}

/**
 * Build search index for a post with full HyperTaxonomy support.
 *
 * Iterates over all HT-enabled taxonomies attached to the post type and delegates
 * per-taxonomy relationship building to htax_index_post_taxonomy_terms(). Stores
 * the resulting rows in wp_htax_search_terms via htax_index_post_terms().
 *
 * Each relationship type written:
 * - direct: term directly assigned to the post
 * - hierarchy: ancestor term (hierarchical taxonomies; chain stops at redirect ancestors)
 * - container: term that includes the directly assigned term
 * - redirect_target: term that redirects to the directly assigned term
 *
 * Feature gates (redirects disabled, inclusions disabled) are enforced at query time,
 * not at index time, so the index stays correct across settings changes without a rebuild.
 */
function htax_build_post_search_index( $post_id, array $post_text = [] ) {
    global $wpdb;

    $post = get_post( $post_id );
    if ( ! $post || 'publish' !== $post->post_status ) {
        $wpdb->delete( $wpdb->prefix . 'htax_search_terms', array( 'post_id' => $post_id ) );
        return;
    }

    if ( ! in_array( $post->post_type, htax_get_indexed_post_types(), true ) ) {
        return;
    }

    $term_relationships = array();
    $indexed_pairs      = array();

    // Appends one relationship row to $term_relationships.
    $add_term_rel = function( $term_id, $source_term_id, $taxonomy, $relationship_type ) use ( &$term_relationships ) {
        $term_relationships[] = array(
            'term_id'           => $term_id,
            'source_term_id'    => $source_term_id,
            'taxonomy'          => $taxonomy,
            'relationship_type' => $relationship_type,
            'is_hidden'         => htax_is_term_hidden( $source_term_id ) || htax_is_term_hidden( $term_id ),
        );
    };

    // post_format is a UI meta-taxonomy; it carries no search-relevant term assignments.
    $taxonomies = array_diff( get_object_taxonomies( $post->post_type ), array( 'post_format' ) );

    foreach ( $taxonomies as $taxonomy ) {
        if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
            continue;
        }

        // get_the_tags() primes the term object cache; use it for post_tag to avoid
        // a redundant DB lookup on warm-cache rebuilds.
        if ( 'post_tag' === $taxonomy ) {
            $objects         = get_the_tags( $post_id );
            $direct_term_ids = $objects ? wp_list_pluck( $objects, 'term_id' ) : array();
        } else {
            $direct_term_ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( is_wp_error( $direct_term_ids ) || empty( $direct_term_ids ) ) {
                continue;
            }
        }

        htax_index_post_taxonomy_terms( $taxonomy, $direct_term_ids, $term_relationships, $indexed_pairs, $add_term_rel );
    }

    htax_index_post_terms( $post_id, $term_relationships, $post_text );
}

/**
 * Helper: Expand term IDs to include all children (any hierarchical taxonomy)
 *
 * @param int[]  $term_ids  Term IDs to expand.
 * @param string $taxonomy  Taxonomy slug.
 * @return int[]
 */
function htax_expand_term_hierarchy( $term_ids, $taxonomy ) {
    if ( empty( $term_ids ) ) {
        return array();
    }

    $all_ids = array();

    foreach ( $term_ids as $term_id ) {
        $all_ids[] = $term_id;
        $children = get_term_children( $term_id, $taxonomy );
        if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
            $all_ids = array_merge( $all_ids, $children );
        }
    }

    return htax_sanitize_term_ids( $all_ids );
}

/**
 * Initialize the search index system
 */
function htax_init_search_index() {
    return HyperTaxonomy_Search_Index_Manager::get_instance();
}

/**
 * Get the search index manager instance (alias for htax_init_search_index).
 */
function htax_get_search_index() {
    return HyperTaxonomy_Search_Index_Manager::get_instance();
}

// Hook to reindex when redirect changes
add_action( 'htax_redirect_changed', function( $term_id, $term_taxonomy, $new_target_id, $new_target_taxonomy, $old_target ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_search_terms';

    // Delete old redirect relationships for this redirecting term
    // source_term_id = redirecting term, term_id = old target
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name}
             WHERE source_term_id = %d
               AND relationship_type = 'redirect_target'",
            $term_id
        )
    );

    htax_handle_db_error( 'htax_redirect_changed (delete old)' );

    // Add new redirect relationships if new target exists
    if ( $new_target_id > 0 ) {
        // Find all posts that have the redirecting term directly assigned
        $post_ids = htax_get_search_index()->get_posts_directly_assigned_terms( $term_id, $term_taxonomy );

        if ( ! empty( $post_ids ) ) {
            $is_target_hidden = get_term_meta( $new_target_id, '_htax_is_hidden', true ) ? 1 : 0;

            $values = array();
            $placeholders = array();
            foreach ( $post_ids as $post_id ) {
                $values[] = $post_id;
                $values[] = $new_target_id;
                $values[] = $term_id;
                $values[] = $new_target_taxonomy;
                $values[] = $is_target_hidden;
                $placeholders[] = '(%d, %d, %d, %s, \'redirect_target\', %d)';
            }

            htax_upsert_search_rows( $table_name, $placeholders, $values, 'htax_redirect_changed (insert new)' );

            // New rows carry the column-default strength; the reindex computes the real values.
            htax_get_search_index()->queue_post_reindex( $post_ids );
        }
    }
}, 10, 5 );

// Hook to reload caches when redirect changes
add_action( 'htax_redirect_changed', function( $term_id, $term_taxonomy, $new_target_id, $new_target_taxonomy, $old_target ) {
    // Reload cache for the redirecting term
    htax_reload_term_caches_on_edit( $term_id );

    // Reload cache for old target
    if ( $old_target instanceof WP_Term ) {
        htax_reload_term_caches_on_edit( $old_target->term_id );
    } elseif ( is_array( $old_target ) && isset( $old_target['term_id'] ) ) {
        htax_reload_term_caches_on_edit( $old_target['term_id'] );
    }

    // Reload cache for new target
    if ( $new_target_id > 0 ) {
        htax_reload_term_caches_on_edit( $new_target_id );
    }
}, 10, 5 );

// Hook to reindex when redirect is removed
add_action( 'htax_redirect_removed', function( $term_id, $term_taxonomy, $old_target_id, $old_target_taxonomy ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_search_terms';

    // Delete redirect relationships for this redirecting term
    // source_term_id = redirecting term, term_id = old target
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name}
             WHERE source_term_id = %d
               AND relationship_type = 'redirect_target'",
            $term_id
        )
    );

    htax_handle_db_error( 'htax_redirect_removed' );
}, 10, 4 );

// When a term becomes a redirect for the first time, strip its container and hierarchy entries.
// A redirect term has no archive of its own, so it must not appear in search as a container or
// as a hierarchy ancestor. If the term was already a redirect ($old_target is set), these entries
// are already absent.
add_action( 'htax_redirect_changed', function( $term_id, $term_taxonomy, $new_target_id, $new_target_taxonomy, $old_target ) {
    if ( $old_target ) {
        return; // Already a redirect — entries were removed when redirect was first set.
    }
    global $wpdb;

    // Remove container entries: this term is no longer a valid inclusion container.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}htax_search_terms WHERE term_id = %d AND relationship_type = 'container'",
        $term_id
    ) );
    htax_handle_db_error( 'htax_redirect_changed (remove container entries)' );

    // Remove hierarchy entries for this term as ancestor and for all its descendants as source
    // pointing to ancestors above it. Posts in B's subtree must not appear in B's archive (no
    // archive exists) or in ancestors of B (path goes through the redirect term B).
    if ( is_taxonomy_hierarchical( $term_taxonomy ) ) {
        $descendants = get_term_children( $term_id, $term_taxonomy );
        if ( ! is_wp_error( $descendants ) && ! empty( $descendants ) ) {
            $ancestors_of_term  = get_ancestors( $term_id, $term_taxonomy, 'taxonomy' );
            $term_and_ancestors = array_merge( array( $term_id ), $ancestors_of_term );

            $desc_placeholders = htax_sql_placeholders( count( $descendants ) );
            $anc_placeholders  = htax_sql_placeholders( count( $term_and_ancestors ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}htax_search_terms
                  WHERE source_term_id IN ({$desc_placeholders})
                    AND term_id IN ({$anc_placeholders})
                    AND relationship_type = 'hierarchy'",
                array_merge( $descendants, $term_and_ancestors )
            ) );
            htax_handle_db_error( 'htax_redirect_changed (remove hierarchy entries)' );
        }

        // Also remove entries where $term_id itself is the ancestor (source = any term).
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}htax_search_terms WHERE term_id = %d AND relationship_type = 'hierarchy'",
            $term_id
        ) );
        htax_handle_db_error( 'htax_redirect_changed (remove hierarchy entries for term as ancestor)' );
    }
}, 20, 5 );

// When a redirect is removed, restore container entries for any inclusions the term has.
// The inclusions were preserved in the DB while the term was a redirect; this rebuilds the index.
add_action( 'htax_redirect_removed', function( $term_id, $term_taxonomy, $old_target_id, $old_target_taxonomy ) {
    $included_ids = array_keys( htax_get_included_terms( $term_id, false, true ) );
    if ( empty( $included_ids ) ) {
        return;
    }
    htax_get_search_index()->on_includes_changed( $term_id, $term_taxonomy, $included_ids, array() );
}, 20, 4 );

// When a redirect is removed from a hierarchical term, restore its hierarchy entries.
// Posts in descendants that were blocked by the redirect chain are reconnected to this term
// and its ancestors.
add_action( 'htax_redirect_removed', function( $term_id, $term_taxonomy, $old_target_id, $old_target_taxonomy ) {
    if ( ! is_taxonomy_hierarchical( $term_taxonomy ) ) {
        return;
    }
    htax_get_search_index()->on_hierarchy_parent_restored( $term_id, $term_taxonomy );
}, 20, 4 );

// Hook to reload caches when redirect is removed
add_action( 'htax_redirect_removed', function( $term_id, $term_taxonomy, $old_target_id, $old_target_taxonomy ) {
    // Reload cache for the term that was redirecting
    htax_reload_term_caches_on_edit( $term_id );

    // Reload cache for the old target
    if ( $old_target_id > 0 ) {
        htax_reload_term_caches_on_edit( $old_target_id );
    }
}, 10, 4 );

// Register optimized handlers for relationship changes
add_action( 'htax_containers_changed', array( HyperTaxonomy_Search_Index_Manager::get_instance(), 'on_containers_changed' ), 10, 4 );
add_action( 'htax_container_groups_changed', array( HyperTaxonomy_Search_Index_Manager::get_instance(), 'on_container_groups_changed' ), 10, 4 );
add_action( 'htax_includes_changed', array( HyperTaxonomy_Search_Index_Manager::get_instance(), 'on_includes_changed' ), 10, 4 );
add_action( 'htax_excludes_changed', array( HyperTaxonomy_Search_Index_Manager::get_instance(), 'on_excludes_changed' ), 10, 4 );
add_action( 'htax_included_groups_changed', array( HyperTaxonomy_Search_Index_Manager::get_instance(), 'on_included_groups_changed' ), 10, 4 );
add_action( 'htax_custom_inclusions_changed', array( HyperTaxonomy_Search_Index_Manager::get_instance(), 'on_custom_inclusions_changed' ), 10, 3 );

// Cache reload handler for relationship changes
// Reload caches for terms that were added or removed from relationships
$htax_relationship_cache_invalidation_handler = function( $term_id, $taxonomy, $new_relations = null, $old_relations = null ) {
    // 1. Reload cache for the changed term itself
    htax_reload_term_caches_on_edit( $term_id );

    // 2. Reload caches for terms that were added or removed
    $all_affected_term_ids = array();

    if ( is_array( $new_relations ) ) {
        $all_affected_term_ids = array_merge( $all_affected_term_ids, $new_relations );
    }

    if ( is_array( $old_relations ) ) {
        $all_affected_term_ids = array_merge( $all_affected_term_ids, $old_relations );
    }

    $all_affected_term_ids = array_unique( array_filter( $all_affected_term_ids ) );
    foreach ( $all_affected_term_ids as $affected_term_id ) {
        htax_reload_term_caches_on_edit( $affected_term_id );
    }
};

// Register cache invalidation for all relationship change actions
add_action( 'htax_includes_changed', $htax_relationship_cache_invalidation_handler, 10, 4 );
add_action( 'htax_containers_changed', $htax_relationship_cache_invalidation_handler, 10, 4 );
add_action( 'htax_excludes_changed', $htax_relationship_cache_invalidation_handler, 10, 4 );
add_action( 'htax_included_groups_changed', $htax_relationship_cache_invalidation_handler, 10, 4 );
add_action( 'htax_container_groups_changed', $htax_relationship_cache_invalidation_handler, 10, 4 );
// htax_term_groups_changed is intentionally excluded: htax_update_group_membership_caches already
// reloads the edited term's caches, and the group IDs in $new_groups/$old_groups are membership
// targets whose own htax_inc_*/htax_cont_* transients are unaffected by a membership change.

// Register WP Cron handler for async batch processing
add_action( 'htax_process_reindex_batch', array( HyperTaxonomy_Search_Index_Manager::get_instance(), 'process_async_reindex_batch' ) );