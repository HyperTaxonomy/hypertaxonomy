<?php
/**
 * Register all WordPress action hooks
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// INITIALIZATION AND SETUP
// ==============================================================================

add_action( 'init', 'htax_load_textdomain' );
add_action( 'plugins_loaded', 'htax_check_version' );

add_action( 'plugins_loaded', function() {
    htax_init_search_index();
}, 10 );

// Health check
add_action( 'admin_init', function() {
    $current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $check_screens = array( 'edit-tags', 'term', 'settings_page_hypertaxonomy' );
    if ( $current_screen && in_array( $current_screen->base, $check_screens, true ) ) {
        static $health_checked = false;
        if ( ! $health_checked ) {
            $health_checked = true;
            htax_health_check();
        }
    }
} );

add_action( 'admin_init', array( 'HyperTaxonomy_Upgrader', 'check_upgrades' ) );
add_action( 'init', 'htax_integrate_description_display' );

// ==============================================================================
// ADMIN ENQUEUE SCRIPTS
// ==============================================================================

add_action( 'admin_enqueue_scripts', 'htax_enqueue_admin_assets' );
add_action( 'admin_enqueue_scripts', 'htax_enqueue_post_editor_assets' );

// ==============================================================================
// ADMIN NOTICES
// ==============================================================================

add_action( 'admin_notices', 'htax_display_queued_notices', 5 );

/**
 * Notice: both Tag Groups and HyperTaxonomy's own Term Groups feature are
 * active. Informational only — points at the comparison doc and the
 * migration tool; neutral about Tag Groups, not disparaging. Shown only on
 * HyperTaxonomy's own admin screens and permanently dismissible per-user
 * (not time-limited), per WordPress.org Guideline 11.
 *
 * @since 1.0.0
 */
function htax_maybe_show_tag_groups_coexistence_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( get_user_meta( get_current_user_id(), '_htax_dismissed_tag_groups_notice', true ) ) {
        return;
    }
    if ( ! function_exists( 'tag_groups_init' ) ) {
        return;
    }

    $has_group_assignment = false;
    foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
        if ( htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
            $has_group_assignment = true;
            break;
        }
    }
    if ( ! $has_group_assignment ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || false === strpos( (string) $screen->id, 'htax' ) ) {
        return;
    }

    $nonce = wp_create_nonce( 'htax_dismiss_tag_groups_notice' );
    ?>
    <div class="notice notice-info is-dismissible htax-tag-groups-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <p>
            <?php
            printf(
                /* translators: %s: link to the comparison/migration documentation */
                esc_html__( 'Both Tag Groups and HyperTaxonomy\'s Term Groups feature are active. They manage grouping independently — see %s for how they compare and how to migrate.', 'hypertaxonomy' ),
                '<a href="' . esc_url( 'https://hypertaxonomy.org/hypertaxonomy/comparison.html' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'the comparison guide', 'hypertaxonomy' ) . '</a>'
            );
            ?>
        </p>
    </div>
    <script>
    jQuery( document ).on( 'click', '.htax-tag-groups-notice .notice-dismiss', function() {
        jQuery.post( ajaxurl, {
            action: 'htax_dismiss_tag_groups_notice',
            nonce: jQuery( '.htax-tag-groups-notice' ).data( 'nonce' )
        } );
    } );
    </script>
    <?php
}
add_action( 'admin_notices', 'htax_maybe_show_tag_groups_coexistence_notice' );

/**
 * Clear every user's dismissal of the Tag Groups coexistence notice when
 * Tag Groups is deactivated, so the notice is shown again (until re-dismissed)
 * if Tag Groups is reactivated later and the other conditions are still met.
 * A dismissal made during one coexistence period shouldn't silently suppress
 * the notice forever across a deactivate/reactivate cycle.
 *
 * @since 1.0.0
 * @param string $plugin Basename of the deactivated plugin, e.g. 'tag-groups/tag-groups.php'.
 */
function htax_clear_tag_groups_notice_dismissal_on_deactivate( $plugin ) {
    if ( 0 !== strpos( $plugin, 'tag-groups' ) ) {
        return;
    }
    delete_metadata( 'user', 0, '_htax_dismissed_tag_groups_notice', '', true );
}
add_action( 'deactivated_plugin', 'htax_clear_tag_groups_notice_dismissal_on_deactivate' );

// ==============================================================================
// TERM EDIT FORM FIELDS (DYNAMIC REGISTRATION)
// ==============================================================================

// Register hooks dynamically for all enabled taxonomies on init
add_action( 'init', function() {
    $enabled_taxonomies = htax_get_enabled_taxonomies();

    foreach ( $enabled_taxonomies as $taxonomy ) {
        // Field order (after Description - bottommost native field):

        // Add form: Term Groups first (always visible), then collapsible wrapper around the rest.
        // Edit form: fields in the original interleaved order (no collapsible needed).

        // Priority 10: Show description in archive
        add_action( "{$taxonomy}_edit_form_fields", 'htax_add_description_display_field', 10 );
        add_action( "{$taxonomy}_add_form_fields", 'htax_add_description_display_field', 10 );

        // Priority 11: Pagination size
        // Priority 12: Max sticky posts
        add_action( "{$taxonomy}_edit_form_fields", 'htax_add_archive_settings_field', 11 );
        add_action( "{$taxonomy}_add_form_fields", 'htax_add_archive_settings_field', 11 );

        // Priority 13: Term icon (registered in term-icons.php)

        // Priority 14: Hide term from display
        add_action( "{$taxonomy}_edit_form_fields", 'htax_add_hidden_term_field', 14 );
        add_action( "{$taxonomy}_add_form_fields", 'htax_add_hidden_term_field', 14 );

        // Priority 15: Redirect field
        add_action( "{$taxonomy}_add_form_fields", 'htax_add_redirect_field', 15 );
        add_action( "{$taxonomy}_edit_form_fields", 'htax_add_redirect_field', 15 );

        // Priority 17: Single unified tabbed inclusions/relationships field — handles both
        // add and edit forms. For new terms (add form) all panels start empty.
        add_action( "{$taxonomy}_add_form_fields",  'htax_add_inclusions_tabs_field', 17 );
        add_action( "{$taxonomy}_edit_form_fields", 'htax_add_inclusions_tabs_field', 17 );

        // Add/edit form collapsibles and Term Groups are not applicable to the group
        // taxonomy — it has its own admin UI and none of the collapsible fields apply.
        if ( 'group' !== $taxonomy ) {
            // Term Groups normally sits at priority 8, always visible above the
            // collapsible. When Tag Groups is the admin's active primary tool (Locked
            // mode, Tag Groups active), the JS reorder promotes Tag Groups' own native
            // field to that spot instead and our field — now read-only for the groups
            // it manages — is demoted to priority 16, between Redirect and Term
            // Relationships, where it still lands inside the collapsible zone.
            $term_groups_priority = ( function_exists( 'htax_tag_groups_native_field_promoted' ) && htax_tag_groups_native_field_promoted() )
                ? 16
                : 8;

            add_action( "{$taxonomy}_add_form_fields", 'htax_add_term_groups_field', $term_groups_priority );
            add_action( "{$taxonomy}_add_form_fields", 'htax_add_form_collapsible_start', 9 );
            add_action( "{$taxonomy}_add_form_fields", 'htax_add_form_collapsible_end', 19 );

            add_action( "{$taxonomy}_edit_form_fields", 'htax_add_term_groups_field', $term_groups_priority );
            add_action( "{$taxonomy}_edit_form_fields", 'htax_add_edit_form_collapsible_start', 9 );
            add_action( "{$taxonomy}_edit_form_fields", 'htax_add_edit_form_collapsible_end', 19 );
        }
    }

    // ==============================================================================
    // TERM SAVE HOOKS (DYNAMIC REGISTRATION)
    // ==============================================================================

    // Register save hooks dynamically for all enabled taxonomies
    foreach ( $enabled_taxonomies as $taxonomy ) {
        // Orchestrator function that handles all term metadata saves
        // This centralizes nonce/permission/taxonomy validation, then calls individual save functions
        // Priority 20 - runs AFTER the Tag Groups plugin saves (now rebranded "PublishPress Tag
        // Groups" as of its v3.0.0 release, Oct 2026 — re-verified: free v3.0.0 still uses default
        // priority 10 on create_term/edit_term hooks, same as free v2.1.1 and Pro v2.0.9 previously)
        // so that Tag Groups has already saved which group(s) the term belongs to before we validate
        // relationships. Pro's current priority was not independently re-verified (private repo).
        add_action( "edited_{$taxonomy}", 'htax_save_term_data', 20, 2 );
        // Use create_{taxonomy} with priority 5 to save data BEFORE AJAX response is generated
        // (WordPress wp_ajax_add_tag calls single_row() between wp_insert_term and created_{taxonomy})
        add_action( "create_{$taxonomy}", 'htax_save_term_data', 5, 2 );
    }

    // Clear count cache on save (priority 30) - dynamic for all enabled taxonomies
    // Priority 30 ensures this runs AFTER all term modifications at priority 20
    foreach ( $enabled_taxonomies as $taxonomy ) {
        add_action( "edited_{$taxonomy}", 'htax_clear_count_cache_on_save', 30, 2 );
    }
}, 999 );

// ==============================================================================
// CACHE MANAGEMENT
// ==============================================================================

// Reload term caches (priority 10)
add_action( 'edited_term', 'htax_reload_term_caches_on_edit', 10, 2 );
add_action( 'delete_term', 'htax_invalidate_term_caches_on_delete', 10, 3 );

// Clear site-wide hidden terms cache only when visibility meta actually changes.
add_action( 'added_term_meta',   'htax_clear_hidden_terms_cache_on_meta', 10, 3 );
add_action( 'updated_term_meta', 'htax_clear_hidden_terms_cache_on_meta', 10, 3 );
add_action( 'deleted_term_meta', 'htax_clear_hidden_terms_cache_on_meta', 10, 3 );

// Surgical cache updates for group membership changes
add_action( 'htax_term_groups_changed', 'htax_update_group_membership_caches', 10, 4 );

// Async cron handler: propagate group-membership change to all connected terms
add_action( 'htax_async_group_propagate', 'htax_invalidate_group_relationship_caches' );

// Note: Count cache for set_object_terms/deleted_term_relationships is now cleared
// in class-search-index.php on_term_relationships_added() for targeted invalidation

// Post list shortcode cache invalidation on post-term assignment changes.
// set_object_terms receives 6 args so we can diff old vs new term taxonomy IDs in one call.
add_action( 'set_object_terms',          'htax_post_list_cache_on_object_terms_set',          10, 6 );
add_action( 'deleted_term_relationships', 'htax_post_list_cache_on_term_relationships_deleted', 10, 3 );
add_action( 'transition_post_status',    'htax_post_list_cache_on_post_status_change',          10, 3 );

// Clear AJAX caches (priority 15)
add_action( 'edited_term', 'htax_clear_ajax_caches', 15, 1 );
add_action( 'delete_term', 'htax_clear_ajax_caches', 15, 1 );

// Cache clearing triggers
add_action( 'switch_theme', 'htax_clear_all_caches' );
add_action( 'wp_cache_flush', 'htax_clear_all_caches' );

// Invalidate contributing terms cache when global posts_per_page changes
// Page boundaries shift when posts_per_page changes, invalidating cached contributing terms
add_action( 'update_option_posts_per_page', 'htax_invalidate_all_contrib_caches' );

// Invalidate contrib cache whenever any term's archive composition changes.
// htax_term_archive_changed fires for: direct/group inclusions, container groups,
// group membership changes (for terms that include the affected group), group
// hierarchy changes (for terms referencing the affected groups), and redirects.
add_action( 'htax_term_archive_changed', 'htax_invalidate_contrib_cache', 10, 1 );

// Fire htax_term_archive_changed for parent ancestor chains when a term's WP parent
// changes (not covered by fine-grained HT relationship actions).
// Priority 11: after htax_reload_term_caches_on_edit (10) and the search index handler (10).
add_action( 'edited_term', 'htax_fire_archive_changed_on_hierarchy', 11, 3 );

// Invalidate inclusion and custom rel caches when a group is reparented.
// Priority 12: after htax_fire_archive_changed_on_hierarchy (11) which skips group taxonomy.
add_action( 'edited_term', 'htax_invalidate_caches_on_group_reparent', 12, 3 );

// ==============================================================================
// TERM TAXONOMY CONVERSION
// ==============================================================================

// Cache hierarchy data (parent, children) before a term edit so the search
// index can detect parent changes. Reads: htax_term_data_{tt_id} transient.
add_action( 'edit_term', 'htax_cache_term_taxonomy_before_edit', 5, 3 );

// External conversion detection — handles plugins (e.g. Categories to Tags
// Converter) that convert by creating a new term then deleting the old one.
// Priority 1 on created_term: run before other HT hooks so the entry is in
// place by the time pre_delete_term fires in the same request.
add_action( 'created_term', 'htax_track_created_term_for_conversion', 1, 3 );

// Priority 5 on pre_delete_term: capture HT state (groups, hierarchy, icon)
// before WordPress wipes term meta during wp_delete_term().
// Note: WordPress uses 'pre_delete_term' (not 'before_delete_term') — it fires
// before any deletion and passes ($term_id, $taxonomy).
add_action( 'pre_delete_term', 'htax_detect_external_conversion_pre_delete', 5, 2 );

// Priority 5 on delete_term: complete the conversion (create redirect stub,
// transfer groups) BEFORE htax_cleanup_deleted_term (priority 10) removes
// the old term's relationship rows.
add_action( 'delete_term', 'htax_complete_external_conversion_after_delete', 5, 3 );

// ==============================================================================
// CUSTOM RELATIONSHIP HOOKS
// ==============================================================================

// When a custom relationship mapped to 'includes' is saved or deleted, reindex
// both terms so their archive expansion is reflected in the search index.
add_action( 'htax_relationship_saved', function ( int $source_id, int $target_id, string $type_slug ) {
    $type = htax_get_relationship_type( $type_slug );
    if ( $type && 'includes' === $type['core_type'] ) {
        $index = htax_get_search_index();
        if ( $index ) {
            $source_term = get_term( $source_id );
            $target_term = get_term( $target_id );
            if ( $source_term && ! is_wp_error( $source_term ) ) {
                $index->queue_post_reindex( $index->get_posts_directly_assigned_terms( array( $source_id ), $source_term->taxonomy ) );
            }
            if ( $target_term && ! is_wp_error( $target_term ) ) {
                $index->queue_post_reindex( $index->get_posts_directly_assigned_terms( array( $target_id ), $target_term->taxonomy ) );
            }
        }
    }
}, 10, 3 );

add_action( 'htax_relationship_deleted', function ( int $source_id, int $target_id, string $type_slug ) {
    $type = htax_get_relationship_type( $type_slug );
    if ( $type && 'includes' === $type['core_type'] ) {
        $index = htax_get_search_index();
        if ( $index ) {
            $source_term = get_term( $source_id );
            $target_term = get_term( $target_id );
            if ( $source_term && ! is_wp_error( $source_term ) ) {
                $index->queue_post_reindex( $index->get_posts_directly_assigned_terms( array( $source_id ), $source_term->taxonomy ) );
            }
            if ( $target_term && ! is_wp_error( $target_term ) ) {
                $index->queue_post_reindex( $index->get_posts_directly_assigned_terms( array( $target_id ), $target_term->taxonomy ) );
            }
        }
    }
}, 10, 3 );

// ==============================================================================
// CLEANUP HOOKS
// ==============================================================================

// Hierarchy conflict cleanup (priority 20 - after relationship save at priority 10)
// Cleans up HyperTaxonomy relationships that conflict with native WordPress parent-child hierarchy
add_action( 'edited_term', 'htax_cleanup_hierarchy_conflicts', 20, 3 );
add_action( 'created_term', 'htax_cleanup_hierarchy_conflicts', 20, 3 );

// Hidden-flag cascade when a term is reparented under a hidden parent (priority 21)
add_action( 'edited_term', 'htax_cascade_hidden_on_reparent', 21, 3 );
// Hidden-flag cascade when a new term is created under a hidden parent (priority 21)
add_action( 'created_term', 'htax_cascade_hidden_on_create', 21, 3 );

// Term deletion cleanup
add_action( 'delete_term', 'htax_cleanup_deleted_term', 10, 2 );
add_action( 'delete_term_taxonomy', 'htax_cleanup_bulk_deleted_terms', 10, 1 );

// Post deletion cleanup
add_action( 'delete_post', 'htax_cleanup_sticky_on_delete', 10, 1 );

// ==============================================================================
// QUERY MODIFICATION
// ==============================================================================

// Treat hidden terms as non-existent in frontend tax queries for unprivileged users
add_action( 'parse_tax_query', 'htax_hide_terms_in_tax_query' );

// Modify archive queries (priority 10)
add_action( 'pre_get_posts', 'htax_modify_archive_query', 10 );

// Expand secondary queries for third-party plugin compatibility (priority 10)
add_action( 'pre_get_posts', 'htax_expand_secondary_query', 10 );

// Modify archive queries for sticky posts (priority 15 - after main modification)
add_action( 'pre_get_posts', 'htax_modify_archive_query_for_stickies', 15 );

// Enhance search query (for text-based searches)
add_action( 'pre_get_posts', 'htax_enhance_search_query' );

// POST-mode search forms: restore a tokenized search state (?htax_q=) into $_POST, and
// redirect a verified submission to its tokenized results URL (Post/Redirect/Get).
add_action( 'init', 'htax_restore_search_state', 0 );
add_action( 'init', 'htax_redirect_search_submission', 1 );

// Tell visitors when a search couldn't run exactly as asked (e.g. near:me, location unknown)
add_action( 'loop_start', 'htax_print_search_notices' );
add_action( 'loop_no_results', 'htax_print_search_notices' );

// Handle widget POST form submissions (for taxonomy-filtered searches with full hierarchical logic)
add_action( 'pre_get_posts', 'htax_handle_widget_post_search', 5 );

// Handle PowerSearch POST form submissions
add_action( 'pre_get_posts', 'htax_handle_power_post_search', 5 );

// Maintain wp_htax_post_text on post saves and comment count changes
add_action( 'save_post', 'htax_sync_post_text_row', 10, 2 );
add_action( 'wp_update_comment_count', 'htax_sync_post_text_comment', 10, 2 );

// Template redirect for term redirects
add_action( 'template_redirect', 'htax_handle_redirects' );

// ==============================================================================
// FRONTEND DISPLAY
// ==============================================================================

// Frontend styles (priority 999)
add_action( 'wp_head', 'htax_frontend_styles', 999 );

// Icon border radius styles (priority 100)
add_action( 'wp_head', 'htax_icon_border_radius_styles', 100 );
add_action( 'admin_head', 'htax_icon_border_radius_styles', 100 );

// Debug info
add_action( 'wp_head', 'htax_debug_info' );

// ==============================================================================
// AJAX HANDLERS
// ==============================================================================

add_action( 'wp_ajax_htax_dismiss_prefs_callout', 'htax_ajax_dismiss_prefs_callout' );
add_action( 'wp_ajax_htax_get_group_terms', 'htax_ajax_get_group_terms' );
add_action( 'wp_ajax_htax_search_terms', 'htax_ajax_search_terms' );
add_action( 'wp_ajax_htax_get_group_term_ids', 'htax_ajax_get_group_term_ids' );
add_action( 'wp_ajax_nopriv_htax_get_group_term_ids', 'htax_ajax_get_group_term_ids' );
add_action( 'wp_ajax_htax_search_groups', 'htax_ajax_search_groups' );
add_action( 'wp_ajax_htax_count_ineligible_custom_relationships', 'htax_ajax_count_ineligible_custom_relationships' );

// ==============================================================================
// ADMIN UI ENHANCEMENTS
// ==============================================================================

// Note: Admin list table assets (CSS/JS) are now enqueued via htax_enqueue_table_assets()
// registered in includes/admin/tables.php

// ==============================================================================
// SECURITY
// ==============================================================================

add_action( 'send_headers', 'htax_add_security_headers' );

// ==============================================================================
// ACTIVATION/DEACTIVATION
// ==============================================================================

// Handled via register_activation_hook and register_deactivation_hook in main file

// ==============================================================================
// MULTISITE SUPPORT
// ==============================================================================

add_action( 'wp_initialize_site', 'htax_new_site_created', 10, 1 );

// ==============================================================================
// INTEGRATION API
// ==============================================================================

// Count adjustment for frontend get_terms() calls
add_filter( 'get_terms', 'htax_adjust_term_counts', 10, 3 );

// Fire htax_term_archive_changed from fine-grained relationship/redirect actions.
// Accepts 2 args: all fine-grained actions pass ($term_id, $taxonomy, ...) as first two args.
add_action( 'htax_includes_changed', 'htax_fire_term_archive_changed', 10, 2 );
add_action( 'htax_containers_changed', 'htax_fire_term_archive_changed', 10, 2 );
add_action( 'htax_excludes_changed', 'htax_fire_term_archive_changed', 10, 2 );
add_action( 'htax_container_groups_changed', 'htax_fire_term_archive_changed', 10, 2 );
add_action( 'htax_included_groups_changed', 'htax_fire_term_archive_changed', 10, 2 );
// Only fire when T first becomes a redirect; changing an existing redirect's target has no
// archive effect (T's archive was already gone), and the target's archive is never affected.
add_action( 'htax_redirect_changed', function( $term_id, $taxonomy, $new_target_id, $new_target_taxonomy, $old_target ) {
    if ( false === $old_target ) {
        do_action( 'htax_term_archive_changed', (int) $term_id, $taxonomy );
    }
}, 10, 5 );
add_action( 'htax_redirect_removed', 'htax_fire_term_archive_changed', 10, 2 );

// Invalidate the bulk redirect-terms list whenever a redirect is added or removed.
add_action( 'htax_redirect_changed', 'htax_clear_redirect_term_ids_cache', 10, 0 );
add_action( 'htax_redirect_removed', 'htax_clear_redirect_term_ids_cache', 10, 0 );

// ==============================================================================
// DYNAMIC HOOKS (Anonymous Functions)
// ==============================================================================

// Admin notices for database errors (added dynamically in htax_handle_db_error)
// Admin notices for validation errors (added dynamically in htax_add_admin_notice)
// Admin notices for save errors (added dynamically in htax_save_term_relationships)
