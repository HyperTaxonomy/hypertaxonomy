<?php
/**
 * Tag Groups Import Processing Handler
 *
 * Chunked import functions called by three AJAX endpoints:
 *   htax_import_tag_groups_init   – create/update groups + hierarchy, detect
 *                                   deletions, return ID mapping + total term count
 *   htax_import_tag_groups_batch  – assign one batch of terms, return progress
 *   htax_import_tag_groups_finish – clear caches, store a diff snapshot, return final stats
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// RE-RUN OVERWRITE PROTECTION
//
// Mirror mode (see htax_apply_tag_groups_term_assignment()) means a mapped
// group's label and membership get reasserted from Tag Groups' current state
// on every run. An admin who edits one of those through HT's own UI between
// runs would otherwise get that edit silently discarded on the next import.
// Guards live at the lowest common point for each: a WP core filter for the
// name (catches every path, including WordPress's own native Quick Edit,
// which isn't HT's code to intercept any other way), and the two HT-owned
// save functions for membership (every current UI entry point, including
// HT's own Quick Edit, already funnels through one of those two).
// ==============================================================================

/**
 * Whether a Tag Groups import pass is currently running.
 *
 * Set around htax_import_tag_groups_create_groups()'s own rename/hierarchy
 * writes so htax_lock_tag_groups_mapped_name() can tell "the importer is
 * legitimately applying a Tag Groups rename" apart from "an admin is trying
 * to rename this through some other path" — both call wp_update_term() on
 * the same group term, and only the second one should be blocked.
 *
 * @since 1.0.0
 * @param bool|null $set Pass true/false to set the flag; omit to just read it.
 * @return bool
 */
function htax_tag_groups_import_in_progress( ?bool $set = null ): bool {
    static $in_progress = false;
    if ( null !== $set ) {
        $in_progress = $set;
    }
    return $in_progress;
}

/**
 * The admin's chosen Tag Groups integration mode, validated against the
 * known set. Controls whether the re-run-overwrite locks below (and the
 * continuous sync in tag-groups-live-sync.php) are active at all:
 *
 * - 'independent' — no lock, no automatic sync either direction. The import
 *   tool is still available to run manually; the admin accepts that doing so
 *   can silently revert an HT-side edit to a mapped group, same as before
 *   this protection existed.
 * - 'locked'      — mapped groups' names and Tag-Groups-managed membership are
 *   locked in HT's UI between manual migration runs. For admins who will re-run
 *   the migration.
 *
 * Continuous sync is a separate option layered on top of 'locked'; see
 * htax_tag_groups_live_sync_enabled().
 *
 * @since 1.0.0
 * @return string
 */
function htax_tag_groups_integration_mode(): string {
    $mode = get_option( 'htax_tag_groups_integration_mode', 'independent' );
    return in_array( $mode, array( 'independent', 'locked' ), true ) ? $mode : 'independent';
}

/**
 * Whether continuous Tag Groups sync is enabled: the 'live sync' checkbox is
 * ticked and the mode is 'locked'. Saving 'independent' clears the checkbox
 * too, so the stored value always matches what is in effect. Whether
 * Tag Groups is actually active is checked separately where the hooks are
 * registered (tag-groups-live-sync.php), so this can be set before Tag Groups
 * is activated.
 *
 * @since 1.0.0
 * @return bool
 */
function htax_tag_groups_live_sync_enabled(): bool {
    return 'locked' === htax_tag_groups_integration_mode() && (bool) get_option( 'htax_tag_groups_live_sync', 0 );
}

/**
 * Whether Tag Groups' own native per-term field should be promoted to where
 * HT's Term Groups selector normally sits (always visible, above the
 * collapsible "More options" section), with HT's own field demoted into that
 * collapsible section instead.
 *
 * True only when the admin is actively using Tag Groups as their primary
 * group-management tool: Locked mode selected and Tag Groups currently active.
 * Independent mode never promotes it — HT's own selector stays the primary,
 * always-visible one. Locked mode with Tag Groups inactive doesn't promote it
 * either, since there's no native field to show in its place.
 *
 * @since 1.0.0
 * @return bool
 */
function htax_tag_groups_native_field_promoted(): bool {
    return 'locked' === htax_tag_groups_integration_mode() && function_exists( 'tag_groups_init' );
}

/**
 * Whether Tag Groups has actually written group-assignment data for a given
 * taxonomy — i.e. whether a mirror-mode re-run would touch that taxonomy's
 * term-group memberships at all. Read directly from the data Tag Groups
 * itself produced, not from any Tag Groups setting or internal API, which
 * also means this is automatically correct if that setting's scope ever
 * changes without a corresponding re-run.
 *
 * Also the single gate every membership/UI lock call site uses — 'independent'
 * mode means no taxonomy is ever "managed" for locking purposes, regardless
 * of what data Tag Groups has written.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy slug.
 * @return bool
 */
function htax_tag_groups_taxonomy_is_managed( string $taxonomy ): bool {
    if ( 'independent' === htax_tag_groups_integration_mode() ) {
        return false;
    }

    static $cache = array();
    if ( isset( $cache[ $taxonomy ] ) ) {
        return $cache[ $taxonomy ];
    }

    global $wpdb;
    $exists = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT 1 FROM {$wpdb->termmeta} tm
         INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
         WHERE tm.meta_key = '_cm_term_group_array' AND tt.taxonomy = %s
         LIMIT 1",
        $taxonomy
    ) );

    $cache[ $taxonomy ] = $exists;
    return $exists;
}

/**
 * Force a Tag-Groups-mapped group's name back to its current value, blocking
 * any attempted rename through HT's own UI (full edit screen, HT's Quick
 * Edit — though neither renders an editable Name field in the first place,
 * this is defense in depth) or, critically, WordPress's own native Quick
 * Edit, the REST API, or WP-CLI — none of which are HT's code to guard any
 * other way, since they all call wp_update_term() directly. Does nothing
 * while the importer itself is applying a legitimate Tag Groups rename, or
 * while the admin has chosen 'independent' mode.
 *
 * @since 1.0.0
 * @param array  $data     Term data about to be saved (includes 'name').
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy slug.
 * @param array  $args     Arguments passed to wp_update_term() (unused — the lock only cares about $data['name']).
 * @return array
 */
function htax_lock_tag_groups_mapped_name( $data, $term_id, $taxonomy, $args ) {
    if ( 'group' !== $taxonomy || htax_tag_groups_import_in_progress() ) {
        return $data;
    }
    if ( 'independent' === htax_tag_groups_integration_mode() ) {
        return $data;
    }
    if ( ! get_term_meta( $term_id, '_htax_tag_groups_source_id', true ) ) {
        return $data;
    }

    $current = get_term( $term_id, 'group' );
    if ( $current && ! is_wp_error( $current ) ) {
        $data['name'] = $current->name;
    }
    return $data;
}
add_filter( 'wp_update_term_data', 'htax_lock_tag_groups_mapped_name', 10, 4 );

// ==============================================================================
// SITE-SCOPED MAPPING KEY HELPERS
// ==============================================================================

/**
 * A random, site-local token used to scope the Tag Groups ID mapping to this
 * install specifically.
 *
 * Tag Groups' own group IDs are per-site auto-increment integers with no
 * global uniqueness — two independent installs can easily both have a group
 * id of 1. If HT's own export/import feature moves a migrated site's data to
 * another site that also runs Tag Groups, a bare ID match could collide with
 * an unrelated group on the destination and silently rename it. Scoping the
 * mapping to a token generated once per site (never exported as termmeta,
 * since it lives in an option) makes an imported mapping fail to match on any
 * other site, falling through to the existing name-match path instead, which
 * self-heals the mapping to the new site's real local IDs.
 *
 * @since 1.0.0
 * @return string
 */
function htax_get_tag_groups_site_token(): string {
    $token = get_option( 'htax_tag_groups_site_token' );
    if ( ! $token || ! is_string( $token ) ) {
        $token = wp_generate_uuid4();
        update_option( 'htax_tag_groups_site_token', $token, false );
    }
    return $token;
}

/**
 * Build the site-scoped mapping key stored in `_htax_tag_groups_source_id`.
 *
 * @since 1.0.0
 * @param int $tg_group_id Tag Groups' own numeric group ID.
 * @return string
 */
function htax_make_tag_groups_source_key( int $tg_group_id ): string {
    return htax_get_tag_groups_site_token() . ':' . $tg_group_id;
}

/**
 * Parse a stored mapping key, returning the Tag Groups group ID only if the
 * key belongs to this site. A key stamped on another site (e.g. carried in
 * via HT's own export/import feature) returns null rather than a wrong ID —
 * callers fall through to name-matching in that case.
 *
 * @since 1.0.0
 * @param string $key Stored `_htax_tag_groups_source_id` meta value.
 * @return int|null
 */
function htax_parse_tag_groups_source_key( string $key ): ?int {
    $prefix = htax_get_tag_groups_site_token() . ':';
    if ( 0 === strpos( $key, $prefix ) ) {
        return (int) substr( $key, strlen( $prefix ) );
    }
    return null;
}

/**
 * Look up the HT group term already mapped to a given Tag Groups group on
 * this site, without creating anything — a read-only companion to
 * htax_resolve_or_create_tag_group() for callers (the live-sync hooks) that
 * only ever see IDs Tag Groups itself already reports as existing.
 *
 * @since 1.0.0
 * @param int $tg_group_id Tag Groups' own numeric group ID.
 * @return int|null HT group term ID, or null if this site has no mapping for it.
 */
function htax_find_ht_group_by_tag_groups_id( int $tg_group_id ): ?int {
    $matches = get_terms( array(
        'taxonomy'   => 'group',
        'hide_empty' => false,
        'meta_key'   => '_htax_tag_groups_source_id',
        'meta_value' => htax_make_tag_groups_source_key( $tg_group_id ),
        'fields'     => 'ids',
        'number'     => 1,
    ) );

    return ( ! is_wp_error( $matches ) && ! empty( $matches ) ) ? (int) $matches[0] : null;
}

/**
 * Map each child Tag Groups Pro group to its parent group's old ID.
 *
 * Derived from position order: every non-parent-flagged group belongs to the
 * most recent parent-flagged group above it. Tag Groups Pro caps hierarchy at
 * two levels, so this is a single pass.
 *
 * @since 1.0.0
 * @return array<int,int> Child old group ID => parent old group ID.
 */
function htax_get_tag_groups_parent_map(): array {
    $positions = get_option( 'term_group_positions', array() );
    $parents   = get_option( 'term_group_parents', array() );

    if ( empty( $positions ) || empty( $parents ) || ! is_array( $parents ) ) {
        return array();
    }

    asort( $positions );
    $parent_flags          = array_map( 'intval', $parents );
    $current_parent_old_id = 0;
    $map                   = array();

    foreach ( array_map( 'intval', array_keys( $positions ) ) as $old_id ) {
        if ( in_array( $old_id, $parent_flags, true ) ) {
            $current_parent_old_id = $old_id;
            continue;
        }
        if ( 0 !== $current_parent_old_id ) {
            $map[ $old_id ] = $current_parent_old_id;
        }
    }

    return $map;
}

/**
 * Find HT group terms whose decoded name equals the given label.
 *
 * @since 1.0.0
 * @param string $label Decoded group label.
 * @return WP_Term[]
 */
function htax_find_group_terms_by_label( string $label ): array {
    $terms = get_terms( array( 'taxonomy' => 'group', 'hide_empty' => false ) );
    if ( is_wp_error( $terms ) ) {
        return array();
    }

    return array_values( array_filter(
        $terms,
        fn( $term ) => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === $label
    ) );
}

/**
 * Choose the HT group name for a Tag Groups group.
 *
 * The plain label is used unless another HT group carries it and is mapped to a
 * different Tag Groups group, since taking that name would merge two unrelated
 * groups. Tag Groups Pro allows the same label under different parents, so the
 * parent's label is appended first; a numeric suffix is the fallback when there
 * is no parent or the parent form is also taken.
 *
 * @since 1.0.0
 * @param string      $label        Decoded label of the Tag Groups group.
 * @param string|null $parent_label Decoded label of its parent group, or null.
 * @param string      $source_key   This group's mapping key.
 * @param int         $own_term_id  HT term already mapped to this group, or 0 when creating.
 * @return string
 */
function htax_get_tag_groups_display_name( string $label, ?string $parent_label, string $source_key, int $own_term_id = 0 ): string {
    $clashes = false;
    foreach ( htax_find_group_terms_by_label( $label ) as $term ) {
        $mapped_key = (string) get_term_meta( $term->term_id, '_htax_tag_groups_source_id', true );
        if ( '' !== $mapped_key && $mapped_key !== $source_key ) {
            $clashes = true;
            break;
        }
    }
    if ( ! $clashes ) {
        return $label;
    }

    $candidate = ( null !== $parent_label && '' !== $parent_label ) ? $label . ' (' . $parent_label . ')' : null;
    $n         = 2;
    while ( null === $candidate || htax_is_group_label_taken( $candidate, $own_term_id ) ) {
        $candidate = $label . ' (' . $n . ')';
        ++$n;
    }

    return $candidate;
}

/**
 * Whether any HT group other than $own_term_id already has this label.
 *
 * @since 1.0.0
 * @param string $label        Decoded group label.
 * @param int    $own_term_id  Term to ignore (the group being named), or 0.
 * @return bool
 */
function htax_is_group_label_taken( string $label, int $own_term_id ): bool {
    foreach ( htax_find_group_terms_by_label( $label ) as $term ) {
        if ( (int) $term->term_id !== $own_term_id ) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve an HT group term for a given Tag Groups group, or create one.
 *
 * Matches in order: (1) an HT group term whose mapping key resolves to this
 * TG id on this site, (2) an HT group term with the same name (covers the
 * first-ever import, and re-links a group after a cross-site HT export/import
 * once the ID-scoped match above correctly fails), (3) create new. Keeps the
 * label in sync on every run so renames on the Tag Groups side propagate.
 *
 * Also records, in `$stats['newly_mapped']`, every Tag Groups ID whose mapping
 * didn't exist before this call (cases 2 and 3) — this is the one-time signal
 * `htax_import_tag_groups_hierarchy()` uses to seed a group's parent field
 * exactly once and never touch it again. See that function's docblock for why:
 * HT's native hierarchy supports arbitrary depth, Tag Groups Pro's is capped
 * at two levels, and re-applying on every run would hold HT's richer
 * capability hostage to that cap indefinitely.
 *
 * @since 1.0.0
 * @param int         $tg_group_id  Tag Groups' own numeric group ID.
 * @param string      $label        Current label for this group.
 * @param string|null $parent_label Label of this group's Tag Groups parent, or null.
 * @param array       $stats        By reference; increments 'created', 'existing', or 'renamed'; appends to 'newly_mapped' and 'disambiguated'.
 * @return int HT group term ID, or 0 on failure (an error is appended to $stats['errors']).
 */
function htax_resolve_or_create_tag_group( int $tg_group_id, string $label, ?string $parent_label, array &$stats ): int {
    $source_key  = htax_make_tag_groups_source_key( $tg_group_id );
    $existing_id = htax_find_ht_group_by_tag_groups_id( $tg_group_id );

    if ( null !== $existing_id ) {
        $term_id = $existing_id;
        $term    = get_term( $term_id, 'group' );
        $target  = htax_get_tag_groups_display_name( $label, $parent_label, $source_key, $term_id );
        if ( $term && ! is_wp_error( $term ) && html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $target ) {
            wp_update_term( $term_id, 'group', array( 'name' => $target ) );
            ++$stats['renamed'];
            if ( $target !== $label ) {
                $stats['disambiguated'][] = $target;
            }
        } else {
            ++$stats['existing'];
        }
        return $term_id;
    }

    // Name fallback — first-time import, or re-linking after a cross-site HT export/import.
    // Only groups not yet mapped to a Tag Groups group are candidates: a mapped group
    // already belongs to another Tag Groups ID, and taking it over would re-point its mapping.
    $by_name = null;
    foreach ( get_terms( array( 'taxonomy' => 'group', 'hide_empty' => false ) ) as $candidate ) {
        if ( html_entity_decode( $candidate->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $label ) {
            continue;
        }
        if ( get_term_meta( $candidate->term_id, '_htax_tag_groups_source_id', true ) ) {
            continue;
        }
        $by_name = $candidate;
        break;
    }
    if ( $by_name ) {
        update_term_meta( $by_name->term_id, '_htax_tag_groups_source_id', $source_key );
        ++$stats['existing'];
        ++$stats['adopted'];
        $stats['adopted_names'][] = $label;
        $stats['newly_mapped'][]  = $tg_group_id;
        return (int) $by_name->term_id;
    }

    $target = htax_get_tag_groups_display_name( $label, $parent_label, $source_key );
    $result = wp_insert_term( $target, 'group' );
    if ( is_wp_error( $result ) ) {
        $stats['errors'][] = sprintf( 'Group "%s": %s', $target, $result->get_error_message() );
        return 0;
    }

    $new_id = (int) $result['term_id'];
    update_term_meta( $new_id, '_htax_tag_groups_source_id', $source_key );
    ++$stats['created'];
    $stats['newly_mapped'][] = $tg_group_id;
    if ( $target !== $label ) {
        $stats['disambiguated'][] = $target;
    }
    return $new_id;
}

/**
 * Detect groups deleted on the Tag Groups side since the last run and orphan
 * their mapping — strip `_htax_tag_groups_source_id`, never delete the HT
 * term itself, matching HT's non-destructive philosophy everywhere else.
 *
 * @since 1.0.0
 * @param int[] $current_tg_group_ids Tag Groups' current `term_groups` option, already filtered to > 0.
 * @return int[] HT term IDs that were orphaned this run.
 */
function htax_orphan_deleted_tag_groups( array $current_tg_group_ids ): array {
    global $wpdb;

    $site_token = htax_get_tag_groups_site_token();
    $like       = $wpdb->esc_like( $site_token . ':' ) . '%';

    $mapped_term_ids = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = '_htax_tag_groups_source_id' AND meta_value LIKE %s",
        $like
    ) );

    $orphaned = array();
    foreach ( $mapped_term_ids as $row ) {
        $tg_id = htax_parse_tag_groups_source_key( (string) $row->meta_value );
        if ( null !== $tg_id && ! in_array( $tg_id, $current_tg_group_ids, true ) ) {
            delete_term_meta( (int) $row->term_id, '_htax_tag_groups_source_id' );
            $orphaned[] = (int) $row->term_id;
        }
    }

    return $orphaned;
}

/**
 * Seed Tag Groups Pro's parent-group hierarchy onto newly-mapped HT group
 * terms, once, at the moment they're first mapped — never on subsequent runs.
 *
 * This is deliberately a one-time seed, not an ongoing sync. HT's native
 * `group` taxonomy hierarchy supports arbitrary depth; Tag Groups Pro's is
 * capped at exactly two levels (confirmed by its own documentation). If this
 * re-applied Tag Groups' structure on every run, an admin's own deeper
 * reorganization in HT would get silently flattened back to Tag Groups' two
 * levels the next time the importer ran — not a one-off overwrite risk like
 * a rename or membership change, but a permanent cap on a capability Tag
 * Groups doesn't have at all. Seeding once, then leaving a group's hierarchy
 * fully HT's own from that point on, avoids this entirely — there is
 * correspondingly no "hierarchy" lock in the UI-lock spec, because there's no
 * later re-run to protect against.
 *
 * Mechanism (confirmed directly against Tag Groups Pro source — see
 * tag-groups-migration-improvements.md): `term_group_parents` is a flat list
 * of group IDs flagged as "parent" type, not a parent-pointer map. A group's
 * parent is derived purely from position order: walking the position-ordered
 * list, every non-parent-flagged ID belongs to whichever parent-flagged ID
 * most recently preceded it. Confirmed by Tag Groups' own documentation to be
 * capped at exactly two levels — a parent-flagged group can never itself be a
 * child — so this is a single pass, not a recursion.
 *
 * Provenance-aware even for the one-time seed: only ever writes `parent` when
 * the child's *current* parent (if any) is unset or also Tag-Groups-mapped.
 * If an admin's existing HT-native group gets matched by name to a Tag Groups
 * group for the first time and already has an independent HT parent, that's
 * left alone and counted as a skipped conflict rather than overwritten.
 *
 * @since 1.0.0
 * @param array $group_mapping      Old Tag Groups ID → HT term ID, for groups resolved this run.
 * @param int[] $newly_mapped_old_ids Old Tag Groups IDs whose mapping didn't exist before this run (from `$stats['newly_mapped']`) — only these get a parent field written; an already-mapped group from a prior run is never touched again.
 * @return array { @type int $parents_applied @type int $skipped_conflicts }
 */
function htax_import_tag_groups_hierarchy( array $group_mapping, array $newly_mapped_old_ids ): array {
    $parents_applied   = 0;
    $skipped_conflicts = 0;

    foreach ( htax_get_tag_groups_parent_map() as $old_id => $parent_old_id ) {
        if ( ! isset( $group_mapping[ $old_id ] ) || ! isset( $group_mapping[ $parent_old_id ] ) ) {
            continue; // One side wasn't resolved this run; shouldn't normally happen.
        }

        if ( ! in_array( $old_id, $newly_mapped_old_ids, true ) ) {
            continue; // Already mapped from a prior run — seeded once already, never re-touched.
        }

        $child_term_id  = (int) $group_mapping[ $old_id ];
        $parent_term_id = (int) $group_mapping[ $parent_old_id ];

        $child_term = get_term( $child_term_id, 'group' );
        if ( ! $child_term || is_wp_error( $child_term ) ) {
            continue;
        }

        $existing_parent_field = (int) $child_term->parent;

        if ( $existing_parent_field === $parent_term_id ) {
            continue; // Already correct.
        }

        if ( $existing_parent_field > 0 ) {
            $existing_parent_is_mapped = (bool) get_term_meta( $existing_parent_field, '_htax_tag_groups_source_id', true );
            if ( ! $existing_parent_is_mapped ) {
                // Current parent is an HT-native structure the admin set up independently — don't clobber it.
                ++$skipped_conflicts;
                continue;
            }
        }

        wp_update_term( $child_term_id, 'group', array( 'parent' => $parent_term_id ) );
        ++$parents_applied;
    }

    return array(
        'parents_applied'   => $parents_applied,
        'skipped_conflicts' => $skipped_conflicts,
    );
}

/**
 * First pass: read Tag Groups options, resolve/create/rename group terms,
 * apply Pro hierarchy data, detect deletions, return ID mapping.
 *
 * @since 1.0.0
 * @return array {
 *   @type int[]    $group_mapping     Old group ID → HT term ID.
 *   @type int      $total_terms       Total distinct terms with group assignments (any taxonomy).
 *   @type int      $groups_created
 *   @type int      $groups_existing
 *   @type int      $groups_renamed
 *   @type int      $parents_applied
 *   @type int      $parents_skipped_conflicts
 *   @type int[]    $groups_orphaned   HT term IDs whose mapping was removed (deleted on the TG side).
 *   @type string[] $errors
 * }
 */
function htax_import_tag_groups_create_groups(): array {
    global $wpdb;

    $group_ids    = get_option( 'term_groups', array() );
    $group_labels = get_option( 'term_group_labels', array() );

    $group_ids = array_values( array_unique( array_map( 'intval', array_filter( (array) $group_ids, fn( $id ) => $id > 0 ) ) ) );

    // Tag Groups may store names as HTML entities — decode before inserting.
    $label_for  = fn( int $id ): string => html_entity_decode( isset( $group_labels[ $id ] ) ? $group_labels[ $id ] : 'Imported Group ' . $id, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $parent_map = htax_get_tag_groups_parent_map();

    $stats = array(
        'created'       => 0,
        'existing'      => 0,
        'renamed'       => 0,
        'errors'        => array(),
        'newly_mapped'  => array(),
        'adopted'       => 0,
        'adopted_names' => array(),
        'disambiguated' => array(),
    );

    // Mark this pass as the importer's own, so htax_lock_tag_groups_mapped_name()
    // doesn't block the legitimate renames applied below. Always cleared, even if
    // something in the loop throws.
    htax_tag_groups_import_in_progress( true );
    try {
        $group_mapping = array();
        foreach ( $group_ids as $old_id ) {
            $parent_label = isset( $parent_map[ $old_id ] ) ? $label_for( $parent_map[ $old_id ] ) : null;

            $term_id = htax_resolve_or_create_tag_group( $old_id, $label_for( $old_id ), $parent_label, $stats );
            if ( $term_id ) {
                $group_mapping[ $old_id ] = $term_id;
            }
        }

        $hierarchy_result = htax_import_tag_groups_hierarchy( $group_mapping, $stats['newly_mapped'] );
        $orphaned         = htax_orphan_deleted_tag_groups( $group_ids );
    } finally {
        htax_tag_groups_import_in_progress( false );
    }

    $total_terms = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key = '_cm_term_group_array'"
    );

    return array(
        'group_mapping'             => $group_mapping,
        'total_terms'               => $total_terms,
        'groups_created'            => $stats['created'],
        'groups_existing'           => $stats['existing'],
        'groups_renamed'            => $stats['renamed'],
        'groups_adopted'            => $stats['adopted_names'],
        'groups_disambiguated'      => $stats['disambiguated'],
        'parents_applied'           => $hierarchy_result['parents_applied'],
        'parents_skipped_conflicts' => $hierarchy_result['skipped_conflicts'],
        'groups_orphaned'           => $orphaned,
        'errors'                    => $stats['errors'],
    );
}

/**
 * Split a term's current HT group IDs by provenance: Tag-Groups-mapped
 * (carries `_htax_tag_groups_source_id`) versus HT-only (doesn't).
 *
 * @since 1.0.0
 * @param int[] $group_ids HT group term IDs.
 * @return array { @type int[] $mapped @type int[] $ht_only }
 */
function htax_split_term_groups_by_provenance( array $group_ids ): array {
    $mapped  = array();
    $ht_only = array();
    foreach ( $group_ids as $group_id ) {
        if ( get_term_meta( $group_id, '_htax_tag_groups_source_id', true ) ) {
            $mapped[] = $group_id;
        } else {
            $ht_only[] = $group_id;
        }
    }
    return array(
        'mapped'  => $mapped,
        'ht_only' => $ht_only,
    );
}

/**
 * Apply one term's Tag Groups membership report to its HT group assignment.
 *
 * Provenance-aware: HT-only group memberships (groups Tag Groups has no
 * knowledge of) are always preserved, regardless of mode. For the
 * Tag-Groups-mapped subset, 'mirror' makes it identical to what
 * Tag Groups currently reports for the term — including removals; 'keep'
 * (default) only ever adds, matching the old additive-only behavior for admins who
 * want an append-only import.
 *
 * Pulled out as its own function so both the batch importer and a future
 * reactive listener (see tag-groups-live-sync-coexistence.md, which
 * identified this as the one piece of logic not yet directly reusable) can
 * call the identical, already-correct logic rather than duplicating it.
 *
 * @since 1.0.0
 * @param int    $term_id           Term to update.
 * @param string $taxonomy          The term's taxonomy (for the `group_assignment` feature gate).
 * @param int[]  $tg_mapped_new_ids HT group term IDs Tag Groups currently reports for this term.
 * @param string $mode              'mirror' or 'keep'.
 * @return string 'assigned', 'skipped', or 'error'.
 */
function htax_apply_tag_groups_term_assignment( int $term_id, string $taxonomy, array $tg_mapped_new_ids, string $mode = 'keep' ): string {
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        return 'skipped';
    }

    $mode = in_array( $mode, array( 'mirror', 'keep' ), true ) ? $mode : 'keep';

    $existing = htax_get_term_group_ids( $term_id );
    $split    = htax_split_term_groups_by_provenance( $existing );

    if ( 'keep' === $mode ) {
        $final_tg_mapped = array_unique( array_merge( $split['mapped'], $tg_mapped_new_ids ) );
    } else {
        $final_tg_mapped = array_unique( $tg_mapped_new_ids );
    }

    $final = array_values( array_unique( array_merge( $split['ht_only'], $final_tg_mapped ) ) );

    $sorted_final    = $final;
    $sorted_existing = $existing;
    sort( $sorted_final );
    sort( $sorted_existing );
    if ( $sorted_final === $sorted_existing ) {
        return 'skipped';
    }

    $result = htax_set_term_groups( $term_id, $final );
    return is_wp_error( $result ) ? 'error' : 'assigned';
}

/**
 * Batch pass: assign one batch of terms to their groups.
 *
 * No longer restricted to post_tag/category — any taxonomy works, gated the
 * same way `htax_apply_tag_groups_term_assignment()` already gates feature
 * availability.
 *
 * @since 1.0.0
 * @param array  $group_mapping Old group ID → HT term ID.
 * @param int    $offset        Row offset (0-based).
 * @param int    $batch_size    Number of term rows to process.
 * @param string $mode          'mirror' or 'keep'.
 * @return array {
 *   @type int $assigned
 *   @type int $skipped
 *   @type int $errors
 *   @type int $next_offset
 *   @type int $row_count
 * }
 */
function htax_import_tag_groups_process_batch( array $group_mapping, int $offset, int $batch_size, string $mode = 'keep' ): array {
    global $wpdb;

    $assigned = 0;
    $skipped  = 0;
    $errors   = 0;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT tm.term_id, tm.meta_value, tt.taxonomy
         FROM {$wpdb->termmeta} tm
         INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
         WHERE tm.meta_key = '_cm_term_group_array'
         ORDER BY tm.term_id
         LIMIT %d OFFSET %d",
        $batch_size,
        $offset
    ) );

    foreach ( $rows as $row ) {
        // Parse the comma-delimited group ID list, e.g. ",2,5,".
        $old_ids = array_filter( explode( ',', trim( $row->meta_value, ',' ) ), 'is_numeric' );
        $tg_mapped_new_ids = array();
        foreach ( $old_ids as $old_id ) {
            $old_id = (int) $old_id;
            if ( isset( $group_mapping[ $old_id ] ) ) {
                $tg_mapped_new_ids[] = $group_mapping[ $old_id ];
            }
        }

        switch ( htax_apply_tag_groups_term_assignment( (int) $row->term_id, $row->taxonomy, $tg_mapped_new_ids, $mode ) ) {
            case 'assigned':
                ++$assigned;
                break;
            case 'error':
                ++$errors;
                break;
            default:
                ++$skipped;
                break;
        }
    }

    return array(
        'assigned'    => $assigned,
        'skipped'     => $skipped,
        'errors'      => $errors,
        'next_offset' => $offset + count( $rows ),
        'row_count'   => count( $rows ),
    );
}

/**
 * Store a snapshot of the current Tag Groups data after a successful run, so
 * the next visit to the import screen can show a concrete diff ("3 new
 * groups and 12 new term assignments since your last import") rather than a
 * generic reminder to re-run.
 *
 * @since 1.0.0
 */
function htax_store_tag_groups_import_snapshot(): void {
    global $wpdb;

    $group_ids   = get_option( 'term_groups', array() );
    $group_count = count( array_filter( (array) $group_ids, fn( $id ) => $id > 0 ) );
    $term_count  = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key = '_cm_term_group_array'"
    );

    update_option( 'htax_tag_groups_last_import', array(
        'groups' => $group_count,
        'terms'  => $term_count,
        'time'   => time(),
    ), false );
}
