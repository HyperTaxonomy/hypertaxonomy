<?php
/**
 * Tag Groups Live Sync
 *
 * Continuous Tag Groups → HyperTaxonomy sync, active only when the admin has
 * enabled the 'Sync continuously' option on the Sync subtab and Tag Groups is
 * active (see htax_tag_groups_live_sync_enabled() in import-tag-groups-handler.php).
 * Reuses the same functions the periodic importer uses for every write — a
 * sync pass and a manual re-run reassert the exact same fields, so the
 * re-run-overwrite locks (also in import-tag-groups-handler.php) already
 * cover continuous sync without any changes.
 *
 * Three hooks, Tag Groups Free v3.0.0 / Pro v2.0.9:
 *   term_group_saved                - any group create/rename/reorder/reparent
 *   tag_groups_term_group_deleted   - a group is deleted
 *   tag_groups_groups_of_term_saved - one term's group memberships saved
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whether live sync should be active right now.
 *
 * @since 1.0.0
 * @return bool
 */
function htax_tag_groups_live_sync_active(): bool {
    return function_exists( 'tag_groups_init' ) && htax_tag_groups_live_sync_enabled();
}

/**
 * term_group_saved — a group was created, renamed, reordered, or reparented
 * (Pro's parent-group writes included). A deletion fires this hook and
 * tag_groups_term_group_deleted in sequence, since Tag Groups' delete()
 * calls save() internally first; htax_orphan_deleted_tag_groups() handles
 * that case correctly regardless of which of the two fires the detection,
 * since both read Tag Groups' current term_groups option fresh.
 *
 * htax_import_tag_groups_create_groups() already does this hook's whole job
 * (resolve/create/rename, one-time hierarchy seed, orphan detection) as a
 * self-contained pass — nothing new needed here.
 *
 * @since 1.0.0
 */
function htax_tag_groups_live_sync_on_group_saved(): void {
    htax_import_tag_groups_create_groups();
}

/**
 * tag_groups_term_group_deleted — a group was deleted. By the time this
 * fires, Tag Groups has already removed the ID from its own term_groups
 * option, so the same comparison pass the periodic importer runs after every
 * batch already handles it correctly.
 *
 * @since 1.0.0
 * @param int $group_id Tag Groups' own numeric group ID (unused — the
 *                       comparison re-reads Tag Groups' current option state
 *                       rather than acting on this one ID in isolation).
 */
function htax_tag_groups_live_sync_on_group_deleted( $group_id ): void {
    htax_orphan_deleted_tag_groups( get_option( 'term_groups', array() ) );
}

/**
 * tag_groups_groups_of_term_saved — one term's group memberships changed
 * (add/remove/clear-all). Maps Tag Groups' own IDs through this site's
 * mapping — read-only, since a membership event never creates a group; group
 * creation is term_group_saved's job, above — then applies the same
 * provenance-filtered merge the periodic batch importer applies per term.
 *
 * @since 1.0.0
 * @param array $term_groups Tag Groups' own group IDs now assigned to the term.
 * @param int   $term_id     The term whose membership changed.
 */
function htax_tag_groups_live_sync_on_term_groups_saved( $term_groups, $term_id ): void {
    $term = get_term( (int) $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return;
    }

    $mapped_ids = array();
    foreach ( (array) $term_groups as $tg_group_id ) {
        $ht_id = htax_find_ht_group_by_tag_groups_id( (int) $tg_group_id );
        if ( null !== $ht_id ) {
            $mapped_ids[] = $ht_id;
        }
        // An ID this site has no mapping for yet is skipped rather than
        // guessed at. term_group_saved (above) is what creates mappings; if
        // it hasn't fired for this ID yet, the next membership event (or a
        // manual re-run) picks it up once it has.
    }

    $mode = get_option( 'htax_tag_groups_sync_mode', 'keep' );
    htax_apply_tag_groups_term_assignment( (int) $term_id, $term->taxonomy, $mapped_ids, $mode );
}

/**
 * Register the Tag Groups hooks once every plugin has loaded, so Tag Groups' functions exist.
 *
 * @since 1.0.0
 */
function htax_tag_groups_live_sync_register(): void {
    if ( ! htax_tag_groups_live_sync_active() ) {
        return;
    }
    add_action( 'term_group_saved', 'htax_tag_groups_live_sync_on_group_saved' );
    add_action( 'tag_groups_term_group_deleted', 'htax_tag_groups_live_sync_on_group_deleted' );
    add_action( 'tag_groups_groups_of_term_saved', 'htax_tag_groups_live_sync_on_term_groups_saved', 10, 2 );
}
add_action( 'plugins_loaded', 'htax_tag_groups_live_sync_register', 20 );
