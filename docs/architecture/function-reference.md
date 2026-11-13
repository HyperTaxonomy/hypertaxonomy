---
title: Function Reference
parent: Architecture
nav_order: 2
---

# Function Reference
{: .no_toc }

Functions and methods by module, chosen by one rule, used across the suite's plugins. Listed: the public API (documented in the [Developer Reference](../developer-reference/)) and anything called from another file, which is what contributors reuse. Not listed: hook callbacks, which the [Hook Reference](hook-reference.html) names next to their hooks; screen wiring (rendering, enqueueing, menu and column registration, constructors); overrides of a base class's method; and anything used only inside its own file or class, which is best read in the code.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Function Organization

Term links, external posts, feeds, the peer post shortcodes, and link types belong to TermConnect; see the [TermConnect Developer Reference](https://hypertaxonomy.org/termconnect/developer-reference/).

### Plugin Configuration & Lifecycle

**Core (includes/core/functions.php)**

Public API + plugin lifecycle functions. Does not contain internal utilities — those live in helpers.php.

- `htax_health_check()` - Plugin health diagnostics
- `htax_is_taxonomy_enabled()` - Check if taxonomy is enabled in settings AND currently registered via `taxonomy_exists()`; returns false for unregistered taxonomies even if the setting says enabled, suspending HyperTaxonomy features without losing stored data
- `htax_get_available_taxonomies()` - Get all public taxonomies usable with HyperTaxonomy
- `htax_get_taxonomy_info()` - Get taxonomy object with metadata
- `htax_get_enabled_taxonomies()` - Get list of enabled taxonomy slugs; filters out any slugs that are no longer registered (`taxonomy_exists()` check), so callers always receive a clean list safe to pass to WordPress taxonomy functions
- `htax_get_indexed_post_types()` - Get post types included in the search index (default: `['post', 'page']`)
- `htax_get_taxonomy_features()` - Get feature configuration for a taxonomy
- `htax_taxonomy_has_feature()` - Check if specific feature is enabled for taxonomy
- `htax_get_cache_duration($type)` - Get cache duration from settings; types: `term` (default 2 hours), `search` (default 30 min), `autocomplete` (default 5 min), `relationship` (default 0 = never expire, explicit invalidation), `sticky` (default 0), `shortcode` (default 1 hour)
- `htax_generate_transient_key()` - Safe transient key generation
- `htax_get_current_taxonomy()` - Get current taxonomy from screen or request

**Compatibility (includes/core/compatibility.php)**
- `htax_can_load()` - Master check before loading plugin

**Activation (includes/core/activation.php)**
- `htax_activate_single_site()` - Single site activation; runs `HyperTaxonomy_Activator::activate()`, adds capabilities, runs repair scans (`htax_repair_visibility_cascade`, `htax_repair_orphaned_term_relationships`, `htax_repair_orphaned_sticky_meta`, `htax_repair_hierarchy_conflicts`), calls `HyperTaxonomy_Activator::schedule_index_rebuild()` to schedule the full index rebuild after repairs complete, registers the group taxonomy, flushes rewrite rules, and creates the search index table
- `htax_repair_hierarchy_conflicts(): int` - Activation-time scan; removes direct inclusion, container, and exclusion rows that place an ancestor or descendant of a term in its relationship sets

**Activator Class (includes/classes/class-activator.php)**
- `HyperTaxonomy_Activator::activate()` - Creates DB tables, adds indexes, sets version option
- `HyperTaxonomy_Activator::schedule_index_rebuild()` - Schedules `htax_rebuild_indexes` cron event 30 seconds in the future; idempotent (skips if already scheduled); called from `htax_activate_single_site()` after repair scans complete so the rebuild starts from clean data. The rebuild repopulates both `wp_htax_search_terms` and `wp_htax_post_text` — `wp_htax_post_text` is truncated and rebuilt first so that strength scores in `wp_htax_search_terms` are derived from fresh post content; `wp_htax_search_terms` is cleared by `clear_search_terms_for_rebuild()`, which keeps external strength models' scores for posts that will be reindexed (see [Data Storage → Custom Database Tables](data-storage.html#custom-database-tables), `model`). All rebuild paths (`rebuild_indexes()`, `rebuild_post_text_table()`, `ajax_bulk_rebuild()`) select posts with the private `get_indexable_post_ids()`, which reads `wp_posts` directly: going through `WP_Query` would run `pre_get_posts` handlers, including HyperTaxonomy's own hidden-term exclusion, which applies in WP Cron (no privileged user) and would otherwise drop posts with hidden terms from cron rebuilds.

**Unregistered Taxonomy Handling**

When a third-party plugin that registered a custom taxonomy is deactivated, WordPress fires no event that HyperTaxonomy can intercept. The taxonomy disappears silently on the next request. HyperTaxonomy handles this gracefully:

**Feature suspension (immediate, zero data loss):**
- `htax_is_taxonomy_enabled()` returns `false` for any taxonomy that fails `taxonomy_exists()`, regardless of the stored setting. HyperTaxonomy features (archive expansion, search index writes, admin UI) are silently suspended.
- `htax_get_enabled_taxonomies()` filters out unregistered slugs before returning, so every caller automatically receives only currently-registered taxonomies. No individual call site needs a `taxonomy_exists()` guard.

**Stored data (fully preserved):**
- `wp_htax_term_relationships` rows for the taxonomy's terms are untouched.
- `wp_htax_search_terms` rows for the taxonomy's terms persist (stale but harmless — archive expansion is gated on `htax_is_taxonomy_enabled()`). They are cleaned up the next time any of those posts are reindexed for another reason, or on a manual index rebuild.
- The `enabled_taxonomies` option value remains `true` for the taxonomy. When the plugin is reactivated and the taxonomy re-registered, `htax_is_taxonomy_enabled()` immediately returns `true` and all features resume — no settings change required.

**Cache handling:**
- Most term-keyed transients (`htax_inc_`, `htax_cont_`, `htax_excl_`, `htax_groups_`, `htax_contrib_`) are safe when their taxonomy is unregistered: they are only consumed when serving a term's archive, which requires the taxonomy to be registered. Group member caches (`htax_grp_{group_id}_{taxonomy}`) are scoped by both group and taxonomy, so a disabled taxonomy's entries are never consulted for active archives. Custom relationship type caches (`htax_crel_{slug}_*` and `htax_crel_rev_{slug}_*`) are similarly safe: they are only consumed by callers that explicitly query by type slug, and those callers are always within active-taxonomy contexts.
- `htax_get_redirect_target()` validates the target's taxonomy at resolution time. For an **unregistered** target taxonomy it returns false without overwriting the cache, so the redirect resumes automatically the next request after the taxonomy is re-registered. For a **disabled in settings** (registered but off in settings) target taxonomy it caches `'none'`; `on_taxonomy_enabled_state_changed` clears all caches on re-enable so the redirect recovers then too.
- `on_taxonomy_enabled_state_changed()` calls `htax_clear_all_caches()` whenever any taxonomy transitions between enabled and disabled, giving a clean slate for the intentional-disable path.

**Resumption:**
No action required after the third-party plugin is reactivated. `htax_is_taxonomy_enabled()` and `htax_get_enabled_taxonomies()` will return the correct results on the first request after the taxonomy is registered again. Stale index rows are cleaned up incrementally; a manual rebuild via Settings → HyperTaxonomy → Search → General produces a fully clean state immediately.

**`includes/classes/class-capabilities.php`**

- `HyperTaxonomy_Capabilities::add_capabilities()` - Add capabilities to roles on activation
- `HyperTaxonomy_Capabilities::remove_capabilities()` - Remove capabilities on deactivation

### Term Groups

**Membership CRUD (includes/core/group-functions.php)**

Membership CRUD + group taxonomy queries. Handles assigning terms to groups and querying the group taxonomy.

- `htax_get_term_groups()` - Get groups assigned to a term as WP_Term[] ordered by name (cached as `htax_groups_{term_id}`, eager reload)
- `htax_get_term_group_ids( int $term_id ): int[]` - Get IDs of groups a term belongs to; shares the `htax_groups_{term_id}` transient cache with `htax_get_term_groups()`
- `htax_get_group_hierarchy()` - Expand parent groups to include all descendants; uses `_get_term_hierarchy('group')` (WP object cache) for BFS — no extra DB query after first call per request
- `htax_set_term_groups()` - Replace all group assignments for a term
- `htax_update_group_counts( array $group_ids ): void` - Recount groups' stored counts (direct member terms) through `wp_update_term_count()`, so it honors `wp_defer_term_counting()`. Called on `htax_term_groups_changed` and when a member term is deleted
- `htax_repair_group_counts(): void` - Recount every group; runs on activation
- `htax_group_archive_has_posts( int $group_id ): bool` - Whether the group's archive shows a published post: runs `htax_build_group_tax_query()` for one post, with post types chosen the way WordPress chooses them for a taxonomy archive. Used by the sitemap filter to list only non-empty group archives

**Groups-as-inclusion-sources (includes/core/term-groups.php)**

Groups-as-inclusion-sources feature. Handles group-based inclusion and container relationships for term archives.

- `htax_get_terms_in_groups()` - Get all terms from specified groups (with hierarchy expansion + per-group caching)
- `htax_get_terms_from_included_groups()` - Get WP_Term[] from a term's included groups
- `htax_validate_and_filter_group_relationships()` - Validate group relationships (hierarchy conflict detection)

**Term Groups admin savers (includes/admin/term-edit.php)**
- `htax_save_term_groups( int $term_id )` - Save Term Groups assignments from the checklist field. Hooked on `edited_term` (priority 10) and `create_term` (priority 4), so it runs before `htax_save_term_data()` validates relationships. Admin-only (term-edit.php). Ignores requests without `htax_groups` in `$_POST`.

### Relationship Type Registry (includes/core/relationship-types.php)

Manages the in-memory registry of built-in and user-defined relationship types. Built-in types are registered on `init` at priority 0; user-defined types are loaded from `wp_options` on `init` at priority 5. Inclusion queries use `htax_relationship_type_sql_in( 'includes' )` to get an `IN (...)` SQL fragment covering the built-in slug and all user-defined slugs mapped to it.

**Two additional fields** on each `htax_custom_relationship_types` entry (absent keys default to empty/false):
- `source_site` (string) — URL of the peer site that originated this type via TermConnect sync. Empty = locally owned. Controls REST visibility: types with `source_site` set are excluded from `/relationship-types` responses (they are accessible at the originating site). Cleared by `htax_make_relationship_type_local()`.
- `is_private` (bool) — when true, excluded from both `/relationship-types` and `/term/{id}/relationships` REST responses.

**Registration API:**
- `htax_register_relationship_type( string $slug, array $args ): void` - Register a type (slug-keyed, invalidates slug cache; called directly or via `htax_relationship_types` action)
- `htax_get_registered_relationship_types( bool $skip_private = false ): array` - Return full slug => args registry; when `$skip_private = true`, custom types with `is_private` set are filtered out (built-in types are always included)
- `htax_get_relationship_type( string $slug ): ?array` - Return args for a single type, or null
- `htax_get_relationship_type_slugs( string $core_type_slug ): string[]` - Return all slugs whose `core_type` matches (base types self-include; cached per core type)
- `htax_relationship_type_sql_in( string $core_type_slug ): string` - Return e.g. `"relationship_type IN ('includes','wrote')"` — safe to interpolate into `$wpdb->prepare()` strings; slugs come from the registry and are passed through `esc_sql()`

**Persistence (user-defined types):**
- `htax_set_custom_relationship_type( string $slug, array $args, bool $is_new = false ): true|WP_Error` - Persist a new or updated user-defined type to `wp_options` and sync the in-memory registry. `$is_new = true` requires the slug to be unused (returns `WP_Error('slug_exists')` if it already exists as a custom type); `$is_new = false` requires the slug to already exist as a custom type (returns `WP_Error('not_found')` otherwise). Additional validations: slug format; prevents overwriting core types; validates `core_type` mappability; **locks `core_type` on edit** (passed value is ignored, existing value is preserved — no conversion between inclusion-based and custom after creation); for `core_type = 'includes'`, enforces that `source_taxonomies` and `target_taxonomies` are identical single-element arrays; strips `'group'` from `source_taxonomies`/`target_taxonomies` (groups are always implicitly allowed); **enforces label and inverse label uniqueness** — both are checked case-insensitively against every other registered type's label and inverse label, and label must differ from inverse label; calls `htax_delete_transients_by_prefix('htax_crel_{slug}_')` and `htax_delete_transients_by_prefix('htax_crel_rev_{slug}_')` after persisting
- `htax_make_relationship_type_local( string $slug ): bool` - Clear `source_site` on a remote-delegated type, making it locally owned. Irreversible. Returns false for core types or unknown slugs.
- `htax_remove_custom_relationship_type( string $slug ): bool` - Remove a user-defined type from `wp_options` and the in-memory registry; calls `htax_delete_transients_by_prefix('htax_crel_{slug}_')` and `htax_delete_transients_by_prefix('htax_crel_rev_{slug}_')` to clear all cached relationship data for the deleted type; returns false for core types
- `htax_remove_invalid_term_relationships( string $slug ): int` - Delete rows that no longer satisfy the type's constraints. Applies to both core and custom types. Conditions checked: group+group rows (always invalid); `source_taxonomies`/`target_taxonomies` violations (group side exempt); cross-taxonomy and self-relation rows for `includes`/`excludes`; `member_of` rows where source is a group or target is not a group; `redirect` self-redirect rows and chain rows (where the target is itself a redirect source — chain rows use a JOIN-based DELETE since MySQL forbids same-table subqueries in a plain DELETE). Pre-reads affected term IDs before deleting, then calls `htax_delete_term_transients()` for each. Returns total rows deleted. Called after `htax_set_custom_relationship_type()` and during activation for all four core types plus all custom types.

**Query API:**
- `htax_get_related_terms( int $term_id, string $type_slug, bool $include_redirect_data = false, bool $skip_if_private = true ): WP_Term[]` - Get related WP_Term objects for a term filtered by exact slug (forward direction). **Non-core custom types only** — returns `[]` immediately for built-in slugs. Also returns `[]` when `$skip_if_private = true` (default) and the type has `is_private` set; pass `false` for local UI that legitimately needs private data. For non-core types, group targets are expanded to their member terms (via `htax_get_terms_in_groups()`). Direct targets are batch-fetched per taxonomy. Returns `WP_Term[]` indexed by term_id. Cached as `htax_crel_{slug}_{term_id}` (no TTL). Invalidated by: `htax_add_term_relationship`/`htax_remove_term_relationship` (source's cache), `htax_save_term_relationships` edit form path (edited term's own cache + all changed other-side terms), `htax_delete_term_transients` (term's own cache), `htax_invalidate_term_caches_on_delete` (source terms whose cache pointed to the deleted term), type-prefix clear on type save/delete, and `htax_invalidate_group_relationship_caches` when group membership or group hierarchy changes.
- `htax_get_reverse_related_terms( int $term_id, string $type_slug ): WP_Term[]` - Get WP_Term objects that have a relationship of this type pointing to this term (reverse direction). **Non-core custom types only** — returns `[]` immediately for built-in slugs. For non-core types, group sources are expanded to their member terms. Returns `WP_Term[]` indexed by term_id. Cached as `htax_crel_rev_{slug}_{term_id}` (no TTL). Invalidated by: `htax_add_term_relationship`/`htax_remove_term_relationship` (target's cache), `htax_save_term_relationships` edit form path (edited term's own cache + changed fwd targets' rev caches), `htax_delete_term_transients`, `htax_invalidate_term_caches_on_delete` (target terms whose cache contained the deleted term), type-prefix clear on type save/delete, and `htax_invalidate_group_relationship_caches` when group membership or group hierarchy changes.
- `htax_get_relationship_types_for_taxonomy( string $taxonomy ): array` - Return slug-keyed user-defined types where `show_in_ui` is true and `source_taxonomies` is empty or contains the given taxonomy; used by term-edit UI and the save orchestrator to iterate visible types for a term

**Term relationship write operations:**
- `htax_add_term_relationship( int $source_term_id, int $target_term_id, string $type_slug ): true|WP_Error` - INSERT IGNORE a relationship row; valid for `'includes'`, `'excludes'`, and all non-core types; `'redirect'` and `'member_of'` trigger `_doing_it_wrong()`. Validates term existence; enforces group-aware taxonomy constraints (group+group rejected; group side is exempt from source/target taxonomy restrictions; includes-mapped types require both non-group sides to be in the same taxonomy; includes-mapped types reject self-inclusions). Invalidates per-term caches, fires type-specific change actions (`htax_includes_changed`/`htax_containers_changed` for `'includes'`, `htax_excludes_changed` for `'excludes'`), and fires `htax_relationship_saved`.
- `htax_remove_term_relationship( int $source_term_id, int $target_term_id, string $type_slug ): bool` - DELETE a relationship row; same type restrictions as `htax_add_term_relationship()`; fires type-specific change actions and `htax_relationship_deleted` on success.

### Term Relationships (includes/core/term-relationships.php)

**Low-level readers — direct DB / cache wrappers:**
- `htax_get_direct_rel_term_ids( int $term_id, string $slug )` - Get direct forward target IDs for a relationship type: raw T→U rows for the given slug, non-group targets only (cached as `htax_direct_rel_{slug}_{term_id}`). Valid for `'includes'`, `'excludes'`, and non-core types. Calling with `'member_of'` or `'redirect'` triggers `_doing_it_wrong()` and returns `[]` — use `htax_get_term_groups()` or `htax_get_redirect_target()` instead.
- `htax_get_reverse_rel_term_ids( int $term_id, string $slug )` - Get direct reverse source IDs for a relationship type: raw A→T rows for the given slug, non-group sources only (cached as `htax_reverse_rel_{slug}_{term_id}`). Same slug restrictions as `htax_get_direct_rel_term_ids`.
- `htax_get_direct_rel_group_ids( int $term_id, string $slug )` - Get direct forward group target IDs for a relationship type: raw T→G rows for the given slug, group targets only (cached as `htax_direct_rel_grp_{slug}_{term_id}`). Same slug restrictions as `htax_get_direct_rel_term_ids`.
- `htax_get_reverse_rel_group_ids( int $term_id, string $slug )` - Get direct reverse group source IDs for a relationship type: raw G→T rows for the given slug, group sources only (cached as `htax_reverse_rel_grp_{slug}_{term_id}`). Same slug restrictions as above.
- `htax_get_included_term_ids( int|WP_Term $term, bool $include_redirect_data = false )` - Get IDs for the second OR clause of `htax_build_expanded_tax_query`: included terms plus all their descendants. Included terms' children are pre-expanded here because that clause uses `include_children => false`. The primary term's own children are **not** pre-expanded here — they are handled by the first OR clause (which uses `include_children => is_taxonomy_hierarchical`). Only called from `htax_build_expanded_tax_query`.

**Resolved readers — multi-path, WP_Term objects:**
- `htax_get_included_terms( int|WP_Term $term, bool $include_redirect_data = false, bool $include_disabled = false )` - Returns empty when `term_relationships` is disabled for the taxonomy unless `$include_disabled` (search index callers only); the cached set is feature-independent. Get WP_Term[] of included terms indexed by term_id (cached as `htax_inc_{term_id}`, eager reload). No TTL — persists until explicitly deleted by `htax_delete_term_transients()`. `false` = cold (never computed or evicted); `[]` = warm with no relationships. Empty result is stored as `[]` so that a warm cache with no inclusions never triggers a DB query.
- `htax_get_container_terms( int|WP_Term $term, bool $include_disabled = false )` - Same `term_relationships` gating and `$include_disabled` opt-out (search index callers). Get WP_Term[] of container terms indexed by term_id (cached as `htax_cont_{term_id}`, lazy-load; 3 paths: direct, container groups, group inclusion). No TTL — persists until explicitly deleted by `htax_delete_term_transients()`. Same `false` vs `[]` sentinel convention as `htax_inc_{term_id}`.
- `htax_get_synonym_terms( int|WP_Term $term, bool $include_redirect_data = false )` - Get WP_Term[] of terms with a bidirectional `includes` relationship (A→B and B→A). Display utility — not required for archive expansion. Two-tier cache: static per-request array + persistent transient `htax_syno_{term_id}` (TTL=0). Invalidated by `htax_delete_term_transients()` or `htax_delete_term_transients_batch()`; re-warmed asynchronously via the `htax_async_rebuild_term_caches` cron event.
- `htax_get_public_related_term_ids( WP_Term $term, string $kind ): int[]` - IDs of a term's `includes`, `containers`, `synonyms` or `redirect` target with hidden terms left out for every viewer; the single source for the REST relationship fields and the computed term meta, so they always agree. Reads the same caches as the getters above
- `htax_get_excluded_term_ids( int $term_id, bool $include_redirect_data = false ): int[]` - Get excluded term IDs (cached as `htax_excl_{term_id}`); returns `[]` for redirect terms unless `$include_redirect_data` is true

**Redirect suppression pattern:**
All logical relationship getters suppress their results when the source term is a redirect and the taxonomy has the `redirects` feature enabled. Redirect terms preserve their stored rows in the DB and the cache, but callers see an empty result by default. Admin display passes `$include_redirect_data = true` to show the data grayed-out. The save handler (`htax_save_term_relationships`) skips processing for redirect terms to prevent the disabled form fields from wiping preserved data.

Functions that accept `$include_redirect_data`:
- `htax_get_included_terms( $term, bool $include_redirect_data = false, bool $include_disabled = false )`
- `htax_get_included_term_ids( $term, bool $include_redirect_data = false )`
- `htax_get_synonym_terms( $term, bool $include_redirect_data = false )` — also filters redirect terms out of the result set
- `htax_get_excluded_term_ids( int $term_id, bool $include_redirect_data = false )`
- (`term-groups.php`) `htax_get_terms_from_included_groups( int $term_id, string $taxonomy, bool $include_redirect_data = false )`
- (`relationship-types.php`) `htax_get_related_terms( int $term_id, string $type_slug, bool $include_redirect_data = false, bool $skip_if_private = true )` — non-core custom types only; returns `[]` for built-in slugs
- (`relationship-types.php`) `htax_get_reverse_related_terms( int $term_id, string $type_slug )` — non-core custom types only; returns `[]` for built-in slugs
- (`term-archives.php`) `htax_get_sticky_posts_for_term( int $term_id, bool $include_redirect_data = false )`

**Redirects:**
- `htax_get_redirect_target( $term_id )` - Get redirect target as WP_Term|false; applies two taxonomy guards: (1) if the target taxonomy is **disabled in settings** (registered but off in settings), caches `'none'` — safe because `on_taxonomy_enabled_state_changed` calls `htax_clear_all_caches()` on re-enable; (2) if the target taxonomy is **unregistered**, returns false without touching the cache, so the cached WP_Term (or the DB row) remains intact and the redirect resumes automatically when the plugin re-registers the taxonomy
- `htax_is_redirect( $term_id )` - Check if term is redirect (returns true regardless of feature flag — use in combination with `htax_taxonomy_has_feature($taxonomy, 'redirects')` when suppression semantics are needed)
- `htax_get_all_redirect_term_ids(): int[]` - Bulk-fetch all source term IDs with a redirect row; cached per request (`htax_request_cache( 'redirect_term_ids' )`) and as the `htax_redirect_terms` transient, both cleared by `htax_clear_redirect_term_ids_cache()` on `htax_redirect_changed` and `htax_redirect_removed` (and by `htax_clear_relationship_caches()` / `htax_clear_all_caches()`)
- `htax_clear_redirect_target_cache( int $term_id = 0 )` - Delete a term's `htax_redir_` transient and its per-request copy (0 = every term's per-request copy); `htax_set_redirect()` / `htax_remove_redirect()` call it before firing their actions so index handlers see the new state
- `htax_get_ids_of_redirects_to( $target_id, $taxonomies = null )` - Get terms redirecting to target (cross-taxonomy)
- `htax_prewarm_redirect_cache()` - Bulk-fetch all redirect rows in one query, then batch-check target terms by taxonomy, and populate `htax_redir_{id}` transients. Call before bulk index loops to convert O(n) lazy queries into 1 + 1-per-taxonomy queries.
- `htax_set_redirect( int|WP_Term $term, int|WP_Term $target )` - Set redirect with circular detection and chain resolution; replaces the row in a transaction and returns false if a write fails, keeping the old redirect
- `htax_get_redirect_sources( int $target_term_id ): array` - Get the terms that redirect, directly or transitively, to a target term
- `htax_remove_redirect( $term_id )` - Remove redirect from term; returns false, with no hook fired, if the delete fails

**Save orchestration:**
- `htax_save_term_data( $term_id, $tt_id )` - Orchestrator that validates nonce once, checks permissions conditionally based on enabled features and form data, then calls all save functions (runs on both `edited_{$taxonomy}` at priority 20 and `create_{$taxonomy}` at priority 5 for early AJAX compatibility)
- `htax_save_term_relationships( $term_id, $tt_id )` - Save include/container relationships (called by orchestrator, which verifies the nonce); replaces rows in a transaction and rolls back if any write fails
- `htax_save_group_relationships( $term_id, $taxonomy, $relationship_direction, $type_slug = 'includes', $raw_field_value = null )` - Save container or included group memberships for a term; called from within `htax_save_term_relationships()` for both directions and for each visible custom type
- (`admin/term-edit.php`) `htax_save_archive_settings( $term_id )` - Save archive display settings; skips when customize_archives disabled (called by orchestrator)
- `HyperTaxonomy_Term_Icons::save_term_icon( $term_id )` - Save term icon if enabled for taxonomy (called by orchestrator)

**Cleanup:**
- `htax_cleanup_deleted_term( $term_id, $tt_id )` - On `delete_term`: removes all relationship rows where the term is source or target; clears `_htax_auto_redirect` meta from any terms that were redirecting to this term; clears `_htax_show_description` and `_htax_is_hidden` meta from the deleted term; deletes the `_htax_sticky_{term_id}` post meta entry and its transient
- `htax_cleanup_hierarchy_conflicts( $term_id, $tt_id, $taxonomy )` - Remove conflicts when hierarchy changes
- `htax_remove_hierarchy_conflict_rows( int $term_id, string $taxonomy ): bool` - Delete direct inclusion and exclusion rows that place an ancestor or descendant of a term in its relationship sets
- `htax_cleanup_symmetric_relationship_duplicates(): int` - For each symmetric custom relationship type, finds A→B + B→A duplicate row pairs and deletes the one where `source_term_id > target_term_id`, keeping a single canonical row per pair; called by `htax_repair_orphaned_term_relationships()` and on activation; idempotent
- `htax_repair_orphaned_term_relationships(): int` - Activation-time scan; (1) removes rows whose source term no longer exists, (2) removes rows whose target term no longer exists, (3) removes stale `_htax_auto_redirect` meta from terms with no redirect row, (4) calls `htax_remove_invalid_term_relationships()` for all four core types and every user-defined type enforcing taxonomy constraints and self-relation rules, (5) calls `htax_remove_duplicate_redirects()` to enforce the one-target-per-source invariant, (6) calls `htax_cleanup_symmetric_relationship_duplicates()` for all symmetric custom types; returns total rows deleted; idempotent

**Symmetric custom relationship types:**
User-defined types with `symmetric: true` have identical forward and inverse labels (e.g. "Related"). The term edit UI collapses both direction containers into one merged container: reverse pills (rows where another term points to this one) are shown with a dashed border and a `←` arrow after the label; forward pills (rows created from this term's edit page) are shown with a `→` arrow after the label. Adding via `+` always creates a forward row; removing either direction deletes the appropriate row. The save handler strips any forward-direction term IDs that already appear in the reverse direction, preventing A→B + B→A duplicates at write time. The autocomplete excludes all pills (both directions) so users cannot add a term already related in either direction.

**`includes/core/term-relationships.php`**

- `htax_get_term_relationship_ids( $term_id, $id_column, $where_column, $relationship_type, $extra_where = array() )` - Generic database query helper for term relationships
- `htax_get_terms_with_container_groups( $group_ids, $taxonomy )` - Get terms that have any of the specified groups as container groups

### Term Conversion (includes/core/term-conversion.php)

Moves terms between taxonomies, natively or by detecting conversions made by other plugins.

- `htax_convert_term( int $term_id, string $target_taxonomy, bool $create_redirect = true )` - Convert one term; returns a result array or `WP_Error`. With a redirect, the original term stays as a stub in the old taxonomy; without one, it is replaced and the new term claims its slug. Returns `WP_Error`, removing the new term, if moving posts fails.
- `htax_convert_terms_batch( array $term_ids, string $from_taxonomy, string $to_taxonomy, bool $create_redirect = true ): array` - Convert several terms, parents first, and return a summary
- `htax_create_redirect_term( $name, $old_taxonomy, $target_term_id, $target_taxonomy, $old_term_data = null )` - Create a redirect stub pointing to the converted term

**`includes/core/term-conversion.php`**

- `htax_get_taxonomy_singular( $taxonomy )` - Return the singular label for a taxonomy.

### Term Merge (includes/core/term-merge.php)

- `htax_merge_terms( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect = false )` - Move post assignments, group memberships, inclusions, exclusions, custom relationship types, and sticky pins to the target in a transaction, then delete the sources and optionally create redirect stubs. A source's icon and hidden status aren't transferred. Returns `{posts_reassigned, terms_deleted, redirects_created, target_id}`, or `WP_Error` after rolling back if a write fails. Fires `htax_before_merge_terms` and `htax_after_merge_terms`.

### Term Visibility (includes/core/term-visibility.php)

Hidden term logic: permission checks, write-time cascade, and frontend query filtering. Applies to all taxonomies including `group` when the `visibility` feature is enabled for it (Settings → Features → Term Groups → Enable Term Group visibility options).

**Read / permission checks:**
- `htax_is_term_hidden( $term_id )` - Returns true if the term has `_htax_is_hidden` meta set
- `htax_remove_hidden_term_ids( array $term_ids ): array` - Leaves hidden terms out of a list of term IDs for every viewer; used for relationship data (REST relationship fields), which describes the public vocabulary
- `htax_can_see_hidden_terms( $taxonomy = '' )` - Whether the current user can see hidden terms. In admin requests (`htax_is_admin_request()`), `manage_term_visibility` grants access. Otherwise, and for anyone without it, the `hidden_terms_visibility` setting decides: `all`, `post_editors` (`edit_posts`), or `none`. So `none` hides terms from Administrators on the front end.
- `htax_get_all_hidden_term_ids()` - Returns all hidden term IDs from the DB; cached via persistent transient; invalidated on any visibility change
- `htax_get_visible_term_ids( $term_ids )` - Filters an array of term IDs, removing hidden ones for unprivileged users
- `htax_get_visible_wp_descendants( int $term_id, string $taxonomy )` - Returns WP descendants of a term excluding those marked hidden; relies on write-time cascade for completeness
**Cascade writes:**
- `htax_set_descendant_visibility( int $term_id, string $taxonomy, bool $hide )` - Marks all WP descendants hidden or visible; fires `htax_term_visibility_changed` per changed descendant
**Hook callbacks:**
- `htax_repair_visibility_cascade(): int` - Activation-time scan; re-cascades hidden status to any descendants of hidden terms that drifted out of sync while the plugin was inactive; returns count of corrected terms
- `htax_hide_terms_in_tax_query( WP_Query $query )` - `parse_tax_query` hook (runs after WordPress converts every taxonomy query var — `tag`, `cat`, `tag__in`, `taxonomy`/`term`, `tax_query`, REST filters — into tax clauses); for users who cannot see hidden terms, removes hidden terms from every positive (`IN`/`AND`) clause via `htax_strip_hidden_terms_from_tax_clauses()`, so hidden terms behave as if they did not exist: a clause left empty or an `AND` requiring a hidden term matches nothing, `include_children` expands to visible descendants only, `NOT IN`/`EXISTS` clauses are untouched. Posts carrying hidden terms are never excluded — they stay discoverable through their other terms and in unfiltered lists. The sticky fetch (`post__in`, no tax clauses) is unaffected, so the `htax_sticky_*` cache is viewer-independent
**Admin save:**
- (`admin/term-edit.php`) `htax_save_hidden_term( $term_id )` - Save term visibility setting (called by orchestrator)

### Archive Expansion

**Term archives (includes/core/term-archives.php)**
- `htax_get_enabled_archive_taxonomy()` - Returns the current archive's taxonomy slug if HyperTaxonomy is enabled for it; null otherwise. Used as a guard by the sort-order block and archive query handlers.
- `htax_build_expanded_tax_query( int $term_id, string $taxonomy, ?WP_Term $term_obj = null )` - Build OR tax_query for a term's archive expansion; returns null if no HyperTaxonomy contributions or if `term_relationships` feature is not enabled for the taxonomy. Called by both `htax_modify_archive_query` and `htax_expand_secondary_query`.
- `htax_modify_archive_query()` - Modify main archive query for inclusions/redirects (delegates expansion to `htax_build_expanded_tax_query`). When an expanded `tax_query` is applied, WordPress's built-in per-taxonomy query vars (`tag_id`, `cat`, `category_name`, `tag`, `term`) are cleared to prevent WP from constructing a redundant native clause. The `taxonomy` query var is intentionally preserved so that `is_tax()`, breadcrumb plugins, and SEO plugins continue to identify the archive context correctly.
- `htax_get_contributing_terms_for_current_page()` - Get terms contributing to archive
- `htax_get_sticky_posts_for_term()` - Get sticky post objects for a term (taxonomy derived from the term). The `htax_sticky_{term_id}` cache holds only the validated post IDs — an empty list is cached too — and posts are loaded fresh on each call (all indexed post types)
- `htax_handle_group_archives()` - `pre_get_posts` handler for group taxonomy archives; builds an OR tax_query via `htax_build_group_tax_query()` and applies it to the main query
- `htax_repair_orphaned_sticky_meta(): int` - Activation-time scan; finds all `_htax_sticky_*` post meta keys and deletes any whose term no longer exists; idempotent

**Integration API (includes/core/integration-api.php)**

Public integration API for third-party plugins.

- `htax_get_expanded_term_ids( int $term_id, bool $include_redirect_sources = false )` - Returns the complete flat list of term IDs whose posts appear in a term's archive: self + WP descendants (via `htax_get_visible_wp_descendants`) + all HyperTaxonomy-contributed terms from `htax_build_expanded_tax_query` (inclusions pre-expanded to include their own WP descendants). WP descendants of the primary term are always added explicitly regardless of whether HyperTaxonomy contributions are present. Taxonomy is looked up automatically. With `$include_redirect_sources`, also includes terms that redirect to this term. Applies `htax_expanded_term_ids` filter.
- `htax_expand_secondary_query( WP_Query $query )` - `pre_get_posts` handler (priority 10) that expands single-term tax_query clauses for enabled taxonomies in secondary/third-party queries. Controlled by `htax_expand_terms` query var or `expand_secondary_queries` global setting. Skips the main archive query.
- `htax_adjust_term_counts( array $terms, ?array $taxonomies, array $args )` - `get_terms` filter; skips post term queries (`object_ids`). Replaces native WP counts with expansion-aware counts. Frontend only, active only when `expand_secondary_queries` is on.
- `htax_get_local_post_scores( array $term_ids, array $args = [] ): array` - Score local posts for a set of terms using the search index, gated by each taxonomy's relationship features and by term visibility; returns `post_id => score`, or empty when the index table is unavailable. `$args['exclude']` omits post IDs.
- `htax_get_post_term_strengths( int $post_id, array $term_ids = [] ): array` - A post's direct term strengths from the model selected for related posts, normalized so the strongest is 1.0; terms the model has not scored are omitted
- `htax_get_term_uris( int $term_id ): array` - External URIs for a term, from the `htax_term_uris` filter
- `htax_remove_terms_hidden_from_caller( array $terms ): array` - Applies hidden-term visibility to a shared-cache list for callers outside HyperTaxonomy
- `htax_get_term_external_posts( int $term_id, array $args = [] ): array` - External posts for a term, from the `htax_term_external_posts` filter; returns empty without firing the filter when the term is hidden from the current user. `$args` is passed to providers unchanged.
- `htax_get_peer_sites(): array` - Known peer sites, from the `htax_peer_sites` filter
- `htax_get_term_locations( int $term_id ): array` - Location rows for a term, from the `htax_term_locations` filter

**`includes/core/integration-api.php`**

- `htax_build_term_weight_multiplier( array $weights, string $term_col )` - Build a SQL `* (CASE …)` multiplier that scales a per-term aggregate by a caller-supplied weight map.

### Frontend Display (includes/core/frontend.php)

**Hidden term filters:**
- `htax_filter_term_links()` - Remove hidden terms from WordPress's native `term_links-{taxonomy}` HTML link lists
- `htax_filter_get_the_terms()` - Mark hidden terms in arrays

**Archive description:**
- `htax_get_allowed_description_html()` - Define allowed HTML tags

**Styles:**
- `htax_frontend_styles()` - Output frontend CSS

**Icon / pill rendering:**
- `htax_get_the_archive_icon( string $size, array $attr )` - Get current term archive icon as responsive `<img>` with srcset; empty string on non-term archives or missing icon
- `htax_the_archive_icon( string $size, array $attr )` - Echo wrapper for `htax_get_the_archive_icon()`
- `htax_get_term_icon_url( WP_Term|int $term, string $size )` - Get term icon URL (accepts WP_Term object or term ID)
- `htax_get_term_icon_html( WP_Term|int $term, string $size, array $attr )` - Get term icon HTML (accepts WP_Term object or term ID)
- `htax_get_icon_display_for_taxonomy( string $taxonomy )` - Get icon display setting for taxonomy
- `htax_get_term_pill( WP_Term $term, array $args )` - Get term as a styled pill with optional icon
- `htax_the_term_pills( int $post_id, string $taxonomy, array $args )` - Display all post terms as styled pills
- `htax_add_icon_to_term_link( string $term_link, WP_Term $term, string $show_icons, bool $is_hidden )` - Transform existing term link into pill

**`includes/admin/term-icons.php`**

- `HyperTaxonomy_Term_Icons::get_size_name( $taxonomy, $size_type = 'small' )` - Get taxonomy-specific size name for a given size type
- `HyperTaxonomy_Term_Icons::get_icon_dimensions( $taxonomy, $size_type = 'small' )` - Get icon dimensions for a taxonomy
- `HyperTaxonomy_Term_Icons::get_palette_size()` - Number of colors in the placeholder palette.
- `HyperTaxonomy_Term_Icons::are_icons_enabled( $taxonomy = '' )`
- `HyperTaxonomy_Term_Icons->get_batch_term_icon_data( $term_ids, $size = 'htax-icon-small', $taxonomy = '' )` - Get icon data for multiple terms in a single query with caching
- `HyperTaxonomy_Term_Icons->clear_icon_cache( $term_id )` - Clear icon cache when icon is updated or deleted
- `HyperTaxonomy_Term_Icons->regenerate_thumbnail_sizes( string $taxonomy )` - Regenerate small and medium thumbnail sizes for all icons in a taxonomy.
- `HyperTaxonomy_Term_Icons->get_term_icon_data( $term_id, $size = 'htax-icon-small', $include_placeholder = false, $taxonomy = '' )` - Get term icon data
- `HyperTaxonomy_Term_Icons::get_color_for_slug( $slug )` - Return the placeholder hex color for a term slug.
- `HyperTaxonomy_Term_Icons->htax_cleanup_term_icon( $term_id, $tt_id, $taxonomy )` - Cleanup icon when term is deleted

**`includes/core/frontend.php`**

- `htax_resolve_border_radius_css( $border_radius )` - Resolve a border_radius settings array to a CSS value string.
- `htax_get_placeholder_icon_html( $placeholder_color, $dimensions, $taxonomy )` - Build the HTML for a colored placeholder icon span.

### Search

**Query Intercept (includes/core/search.php)**
- `htax_handle_widget_post_search( WP_Query $query )` - `pre_get_posts` callback; runs a search widget POST-mode search (detected via `$_POST['htax_post_search']`, normally restored from a results-page token — see Search State below); verifies the nonce unless the state came from a token, forces `is_search`, runs `get_posts_by_custom_sql()` for the requested page with the form's `htax_posts_per_page` / `htax_max_results`, and applies the page with `htax_apply_search_page_to_query()`
- `htax_handle_power_post_search( WP_Query $query )` - `pre_get_posts` callback; runs a PowerSearch POST-mode search (detected via `$_POST['htax_power_post_search']`); verifies the nonce unless the state came from a token, validates minimum query length, builds config from the form's hidden inputs (`posts_per_page`, `max_results`, `match`, `near_country`, `search_in`, `orderby`), runs `perform_indexed_text_search()` for the requested page and applies it with `htax_apply_search_page_to_query()`

**Search State — POST/Redirect/GET (includes/core/search-state.php)**

POST-mode forms submit once; the results pages are GET requests carrying `?htax_q=<token>`, so the theme's `/page/N/` links (which keep the query string), back/refresh, bookmarks and page caches work.
- `htax_restore_search_state()` - `init` priority 0; on a GET with `htax_q`, decodes the state and writes it (slashed, as WordPress slashes request data) into `$_POST` / `$_REQUEST`, so the handlers and the form-restoration code run unchanged; marks the request with `htax_is_search_state_request()`.
- `htax_is_search_state_request( ?bool $set = null ): bool` - whether this request's state came from a token (verified at submission, so the handlers skip their nonce check — the nonce would have expired or belong to another visitor).
- `htax_apply_search_page_to_query( WP_Query $query, array $post_ids, int $total, int $posts_per_page, int $paged )` - sets the main query to exactly one engine page: `post__in` in order, indexed post types, no stickies, and query var `htax_search_total`. With it, `htax_search_page_post_limits()` (`post_limits`) starts the LIMIT at row 0 — the LIMIT is kept because WordPress computes `max_num_pages` only for limited queries — and `htax_search_page_found_posts()` (`found_posts`) reports the true total.
- `htax_get_search_page_number( WP_Query $query ): int` - current page from `paged`, or `page` on singular page URLs.
- `htax_filter_search_query_text()` (`get_search_query`) - returns the `htax_search_text` query var when `s` was cleared by an HT search, so theme titles show the query. While a search form renders (`htax_track_search_form_rendering()` on `pre_get_search_form` / `get_search_form` / `pre_render_block` / `render_block_core/search`; state in `htax_is_rendering_search_form()`), it is applied on native `?s=` searches, and on HT results pages only when Site Search Integration (`always_enhance_search`) is on — otherwise that box would run WordPress's own search, so it stays empty.

**Search notices (includes/core/search.php)**
- `htax_add_search_notice( string $code, string $message )` / `htax_get_search_notices(): array` - per-request visitor messages (`$GLOBALS['htax_search_notices']`, keyed by code). Included as `notices` in widget and PowerSearch AJAX responses; `htax_print_search_notices()` (`loop_start` / `loop_no_results`, main search query, once) prints them on results pages.

**Post Text Sync (includes/core/search.php):**
- `htax_sync_post_text_row( int $post_id, WP_Post $post )` - `save_post` handler; upserts a row into `wp_htax_post_text` for published, draft, pending, future, and private posts using `htax_extract_post_body()` on `post_content`; deletes the row for trash and auto-draft. Skips revisions and autosaves. Draft rows allow the post editor to perform content-based tag suggestions against the same table public search queries use.
- `htax_extract_post_body( string $html ): string` - Converts raw `post_content` HTML to plain text for FULLTEXT indexing. Preserves semantically useful attribute values before stripping tags: `<a href>` URLs appended as `[URL]` after anchor text, `<img alt>` text, and `<abbr title>` / `<acronym title>` expanded forms appended after the abbreviated text. Then strips all remaining HTML with `wp_strip_all_tags()`. Struck-through content (`<del>`, `<s>`) is kept — authors use strikethrough as editorial rephrase and the wording remains meaningful.
- `htax_sync_post_text_comment( int $post_id, int $new_count )` - `wp_update_comment_count` handler; updates `comment_count` and `last_comment_gmt` for rows that already exist in `wp_htax_post_text`. No-ops if the post is not in the table (trashed, auto-draft, or not an indexed post type).

**Search Enhancement:**
- `htax_parse_orderby()` - Parse orderby parameter string into array
- `htax_enhance_search_query()` - Native `?s=` search through the PowerSearch engine (always, or with `htax_search`): leaves an empty `s` to WordPress; runs the requested page with the query's `posts_per_page` (`posts_per_rss` for search feeds), clears `s`, stores the text in `htax_search_text`, and applies the page with `htax_apply_search_page_to_query()` — the engine sorts by the full orderby, so the page keeps its order
- `htax_get_search_results_per_page()` - Default posts-per-page for all HT search forms; returns `get_option('posts_per_page', 20)` so that the WordPress Reading setting is the single site-wide control. Shortcode-level `posts_per_page` attributes always override this.
- `htax_get_default_match_mode()` - Returns `'all'` or `'any'` from `htax_settings[default_match_mode]`; defaults to `'any'`.
- `htax_build_orderby_sql( $orderby_parsed, $post_alias = 'pt', $relevance_column = 'term_relevance' )` - Build a SQL `ORDER BY` clause from a parsed orderby array
- `htax_get_term_sort_order( $term_id )` - Get the parsed sort order for a term's archive
- `htax_encode_term_search( $search )` - Convert term search text to the form WordPress stores term names in: `&`, `<`, and `>` as entities, quotes as typed. Used by block editor and Classic Editor tag suggestions, the term autocomplete, and the search widget's term search. Defined in `core/functions.php`.
- `htax_set_sql_orderby( WP_Query $query, array $orderby_parsed, int $term_id = 0 ): bool` - Sort a query in SQL when its sort order uses `last_comment`, `last_activity`, or `relevance`, which WP_Query can't sort by. Returns false when WP_Query can sort it itself. Without a term, `relevance` is dropped.
- `htax_apply_sql_orderby_clauses( $clauses, $query )` - `posts_clauses` callback for it: joins `wp_htax_post_text` (and the search index for `relevance`) and builds the ORDER BY with `htax_build_orderby_sql()`.
- `htax_get_default_text_weights()` - Default weights for keyword matches in the title, content, and excerpt. Used by PowerSearch, post lists, and the settings defaults until the site has saved its own values.
- `htax_get_relevance_weights( string $taxonomy, ?array $options = null ): array` - Configured relevance weights for a taxonomy, falling back to defaults
- `htax_get_default_expand_types(): array` / `htax_get_expand_types_for_taxonomy( string $taxonomy ): array` - Site-wide default relationship expansion types for search, and the active types for a taxonomy after site settings and feature flags

**URL Parameters:**
- `htax_search=1` - Trigger enhanced search
- `htax_search_in` - Comma-separated list: title, content, excerpt, tags, categories
- `htax_orderby` - Sort order format: "field:order,field:order" (e.g., "relevance:desc,date:desc")
  - Valid fields: relevance, date, modified, title, author, comment_count, last_comment, last_activity, ID
  - Valid orders: asc, desc (defaults to desc if omitted)
  - Relevance works with text searches only in enhanced search
  - `last_comment` sorts by `last_comment_gmt`; `last_activity` sorts by `GREATEST(post_modified_gmt, last_comment_gmt)` — both columns are always present because all queries use `wp_htax_post_text` as the primary post source
  - Also accepted on term archive URLs to override per-term sort order; emitted by `[ht_sort_order_options action="navigate"]`
  - Example: `?s=test&htax_search=1&htax_orderby=date:desc,title:asc`

**Sort Order Picker (includes/shortcodes/class-sort-order.php)**

Shared renderer and shortcode handler for the pill-based sort order picker component. Loaded before `class-post-list.php` since post list and search result shortcodes call `htax_render_sort_order_picker()` directly.

- `htax_sort_field_labels()` - Returns the canonical `field_slug => translated label` map for all supported sort fields (`date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `relevance`, `ID`)
- `htax_render_sort_order_picker( array $args )` - Renders the complete `[data-htax-sort-root]` HTML block: toggle button, active-pill container with add button, picker popover, hidden value input. Arguments: `fields` (array or comma-string), `state` (expanded|collapsed), `action` (navigate|submit|''), `default_value`, `input_name`, `text_collapsed`, `text_expanded`, `class`

**JavaScript component (assets/js/htax-sort-order.js):**
- `window.htaxSortOrder.init(root, opts)` - Initializes a picker within a root element; returns `{ getValue, setValue, destroy }`
- Auto-initializes all `[data-htax-sort-root]` elements on DOMReady
- Fires jQuery event `htax:sort-change` on the root element (bubbles) with the new serialized value as second argument
- `data-htax-sort-action="navigate|submit"` controls what happens on change; omit for event-only (admin/AJAX contexts)
- `syncAddBtn()` — hides the `+` button when all available fields are already active as pills

**sort_order_options attribute on result shortcodes:**
- `[ht_post_list sort_order_options="..."]` — renders picker as sibling of `.htax-post-list-wrapper` inside `.htax-post-list-block`; `htax-post-list.js` handles `htax:sort-change` by updating `data-orderby` and calling `loadPage($wrapper, 1)`
- `[ht_search_results sort_order_options="..."]` — renders picker in a `.htax-controls-bar` above `.htax-search-results`, both inside `.htax-search-results-block`. Both search widget and PowerSearch store their JS instance on the container via `$results.data('htax-form-instance', this)`; the single delegated `htax:sort-change` handler in `htax-search-widget.js` reads this reference and calls `instance.performSearch(1)` regardless of which form type is in use. Pagination prev/next buttons use the same stored reference.

**Filter Options Panel (includes/shortcodes/class-filter-options.php)**

Collapsible filter panel supporting term, date range, and author filters. Works in three action modes: `navigate` (URL params on archives), `submit` (form submission), and event-only (AJAX result shortcodes). Loaded before `class-post-list.php` since post list, search widget, and PowerSearch shortcodes call its render and SQL-builder functions directly.

**Key global functions:**

- `htax_build_filter_sql( array $filter_state, array $filter_configs = [] ): string` — Builds SQL WHERE clauses from a parsed filter state array. Uses `pt` as the `wp_htax_post_text` alias (consistent across all three query contexts). Returns empty string when state is empty. Term filters use `pt.post_id IN (SELECT post_id …)` subqueries against `wp_htax_search_terms`; date filters use `pt.post_date_gmt`/`pt.post_modified_gmt`; author filter uses `pt.post_author IN (…)`.
- `htax_parse_filter_state_ajax( $raw ): array` — Parses filter state from a JSON string POSTed as `htax_filter` in AJAX requests. Same sanitization as navigate variant; preserves `term_match` keys from the client state.
- `htax_resolve_filter_configs( array $raw_configs, array $filter_state, $archive_exclude_term, array $primary_seed_ids = [] ): array` — Converts raw shortcode attribute arrays into fully resolved config arrays. Fetches term options from the DB, applies pool include/exclude rules, merges initial selection from filter state and seed IDs, and forces seeded terms into the pool. Returns one config array per filter group ready for rendering.
- `htax_render_filter_options( array $configs, array $args, array $filter_state ): string` — Renders the complete `[data-htax-filter-root]` HTML block: toggle/clear header, body with one group per config, hidden value inputs, and `data-htax-filter-state`/`data-htax-filter-initial` JSON attributes.
- `htax_parse_inline_filters( string $filters_string, string $filter_options_string = '' ): array` — Converts the `filters=`/`filter_options=` inline shortcode attributes into raw config arrays compatible with `htax_resolve_filter_configs()`. Keywords: `date_published`, `date_modified`, `author`, or any taxonomy slug. First taxonomy slug is marked `primary=true`. Position-aligned `filter_options` values: `single` for date type, `group:N` for term pool.
- `htax_apply_archive_filter( WP_Query $query )` — `pre_get_posts` callback at priority 11. Reads `$_GET['htax_filter']`, parses via `htax_parse_filter_state_navigate()`, builds mods via `htax_build_archive_filter_mods()`, and merges them into the main query. Only runs on the main front-end query with a `queried_object` (i.e. actual archive pages).

**JavaScript component (assets/js/htax-filter-options.js):**
- Each `[data-htax-filter-root]` element gets a `FilterPanel` instance on DOMReady.
- State is stored in `this.state` (current) and `this.initial` (initial/seed); `isDirty()` compares them to control the "Clear filters" button visibility.
- `commit( newState )` — updates state and fires `htax:filter-change` (event mode), navigates (navigate mode), or submits the ancestor form (submit mode).
- `resetToInitial()` — restores all UI controls to initial values and calls `commit(initial)`.
- Three delegated `htax:filter-change` handlers wire up to result shortcodes. Each finds its target beside the panel, inside a result block beside the panel, or in the block containing the panel:
  - Post list: sets `$wrapper.data('htax-filter', newState)` then triggers `htax:load-page` on `.htax-post-list-wrapper`
  - Search widget: sets `$results.data('htax-filter', newState)` then calls `HTAXSearchWidget.forms[formId].performSearch(1, true)`, finding the form through `data-results`. The second argument empties the results area, rather than reporting no results, when the response has `no_criteria` set. The widget's `htax:sort-change` handler passes it too.
  - PowerSearch: sets `$results.data('htax-filter', newState)` then calls the `PowerSearchForm` instance's `performSearch(1)` if anything has been typed; otherwise the filter waits for the first search

**Filter state integration in AJAX handlers:**
- All three AJAX search handlers (`ajax_load_page` in post list, `ajax_search_posts` in search widget and PowerSearch) read `$_POST['htax_filter']`, parse via `htax_parse_filter_state_ajax()`, build SQL via `htax_build_filter_sql()`, and pass the result as `$extra_where` / `user_filter_sql` to the query layer.
- SQL alias `pt` (for `wp_htax_post_text`) is consistent across all three query contexts, so filter SQL is interchangeable.

**`filters`/`filter_options` inline attributes on result shortcodes:**
- Available on `[ht_post_list]` and `[ht_search_results]` (the unified results container used by both search widget and PowerSearch).
- When set, `htax_parse_inline_filters()` → `htax_resolve_filter_configs()` → `htax_render_filter_options()` is called at render time (always `action=""`).
- The filter panel HTML is placed in a `.htax-controls-bar` above the results wrapper, both inside a block div (`.htax-post-list-block`, `.htax-search-results-block`). The JS filter-change handlers find the results through that block.
- `[ht_post_list]` also passes resolved `filter_terms` IDs as `$primary_seed_ids` so the filter panel UI matches the initial page-load query result.

**ATCF Integration (includes/integrations/class-atcf-integration.php)**

Always loaded; its hooks register only when `function_exists( 'atcf_get_term_ids_within_radius' )`. Hooks:
- `htax_pre_search_fields` — receives the full search query spec `{relation, fields, groups, text_search}` and resolves any `distance`-type fields in `$query['fields']` to `type:"hidden"` resolved fields containing matching term IDs before the SQL query runs. Prefers JS-provided coordinates (`_atcf_lat`/`_atcf_lng` request params) over postal-code centroid lookup; falls back to `atcf_get_visitor_location()`. Any `expand_types` value from the distance field is passed through unchanged to the resolved field; `normalize_expand_types()` in the search widget class handles final normalization at query time.
- `htax_allowed_field_types` — adds `'distance'` to the accepted field types so the AJAX handler does not reject distance fields
- `htax_term_locations` — provides ATCF location rows to HT's filter, available through `htax_get_term_locations()`. REST responses get locations from `ATCF_REST_API::format_term_locations()` instead (see [REST API](#rest-api-includescorerest-apiphp) below)

The integration touches **no ATCF database tables directly** — all location data is retrieved via ATCF's public API (`atcf_get_locations_within_radius()`, `atcf_get_term_ids_within_radius()`, `atcf_get_postal_centroid()`, `atcf_get_visitor_location()`, `ATCF_Locations::get_aggregated()`). The only direct DB queries HT performs are against WP's own `term_relationships` and `term_taxonomy` tables to map term IDs to post IDs.

The `[ht_distance_field]` shortcode is registered unconditionally (so the tag is always recognized in content). When ATCF is inactive at render time, the shortcode outputs a notice for admins and nothing for other users.

**JavaScript event:**
- `atcf:termFilterInserted` — jQuery event fired by ATCF's admin term list script with the filter's container. `admin/js/htax-tables.js` listens for it to move the filter into HyperTaxonomy's filter row.

**`htax_search_proximity_where` filter (PowerSearch + search widget):**

Receives `(string $where, array $proximity, string $post_column)` and returns an SQL condition (no leading AND; `''` = none). Both `perform_indexed_text_search()` (PowerSearch) and `get_posts_by_custom_sql()` (search widget) fire it when proximity tokens are present and append the result to the WHERE clause — before ORDER BY / LIMIT, so pages and totals are computed on the filtered set and the relative order of the remaining posts is unchanged. PowerSearch resolves the condition before its result-cache lookup and includes it in the cache key, since `near:me` depends on the viewer's location.

The ATCF integration's `build_proximity_where()` keeps a post when at least one of its terms in an eligible taxonomy (public taxonomies enabled in ATCF) has a location in range of some positive clause. Posts with no eligible terms, or whose eligible terms have no locations, are dropped — their location is unknown. Positive clauses are unioned (in-range term IDs from every resolvable clause); unresolvable clauses are ignored, and when none resolves there is no positive condition. Negated clauses (`-near:`) drop posts in range of any of them. Hidden terms are left out for viewers who can't see them.

In-range term IDs come from ATCF's API (`atcf_get_locations_within_radius()`). `posts_with_terms_sql()` resolves them to `term_taxonomy_id`s and emits a correlated `EXISTS (SELECT 1 FROM term_relationships WHERE object_id = <post> AND term_taxonomy_id IN (…))`, which uses the table's `(object_id, term_taxonomy_id)` primary key per candidate post and lets an `ORDER BY … LIMIT` query stop once its page is full. On a 300k-post / 2.7M-relationship test set this measured ~70 ms for page 1 and ~120 ms at offset 5000 (vs ~0.5 s for an `IN (SELECT …)` semi-join and ~0.7 s / 90 MB for fetching the full ordered result and filtering it in PHP), with identical rows.

**Proximity syntax parsed by `htax_parse_search_query()` (ATCF-gated):**

```
near:taxonomy:slug[/radius]   — reference: term's ATCF locations (+ WP descendants + HT inclusions)
near:X[/radius]               — X checked against country's postal format (uppercase):
                                 match → postal centroid; no match → slug lookup in all eligible taxonomies
near:me[/radius]              — reference: user location (_atcf_lat/_atcf_lng or IP geo fallback)
-near:...                     — negation; excludes posts in range
```

Default radius: 10, in the ATCF distance unit (`atcf_distance_units`). Country context (for postal format check and centroid lookup) is set by the `near_country` shortcode/block attribute (default `'US'`). When ATCF is inactive, `near:` text is not extracted and falls through to FULLTEXT search unchanged.

**Reference point aggregation for `near:taxonomy:term`:**

Reference locations are resolved via `ATCF_Locations::get_aggregated()`, which expands the scope to include WP taxonomy descendants (`get_term_children()`) and HT inclusion terms (`htax_get_included_term_ids()`), and excludes HT hidden terms (`htax_get_all_hidden_term_ids()`). This means `near:geo:europe` finds locations across all European child terms, not just direct ATCF locations on the "europe" term itself.

**`includes/classes/class-power-search.php`**

- `HyperTaxonomy_Power_Search->search_form_shortcode( $atts )` - Search form shortcode
- `HyperTaxonomy_Power_Search->apply_score_thresholds( array &$config, array $source, string $prefix = '' )` - Apply minimum score thresholds from a source array into a search config array.

**`includes/classes/class-search-widget.php`**

- `HyperTaxonomy_Search_Widget->extract_text_search_from_fields( $query, $default_min_length = 3 )` - Extract text search query and search_in parameter from a query spec.
- `HyperTaxonomy_Search_Widget->has_taxonomy_terms_in_fields( $query )` - Check if a query spec contains any taxonomy terms.

**`includes/core/search.php`**

- `htax_get_default_relevance_weights( $taxonomy )`
- `htax_get_enabled_taxonomies_with_search()` - Get enabled taxonomies with search enhancement

### Integrations (includes/integrations/)

**`includes/integrations/class-atcf-integration.php`**

- `HyperTaxonomy_ATCF_Integration::register_distance_shortcode()` - Register [ht_distance_field] shortcode for use in search widget forms.
- `HyperTaxonomy_ATCF_Integration::get_user_location()` - Return the visitor's current location, from ATCF's location chain (atcf_get_visitor_location()): browser coordinates sent with the request (_atcf_lat / _atcf_lng — the search scripts ask the browser for them when a query uses near:me), CDN location headers, IP geolocation, then the site's fallback location.
- `HyperTaxonomy_ATCF_Integration::get_in_range_term_ids( array $ref_points, float $radius_km, string $taxonomy = '' )` - Return term IDs whose ATCF locations are within $radius_km of any reference point.

### Computed Term Meta (includes/core/term-meta-computed.php)

Read-only `get_term_meta()` keys computed from existing caches; nothing stored. Hidden terms are left out for every viewer. See [Term Meta](../developer-reference/term-meta.html).

### REST API (includes/core/rest-api.php)

- `htax_rest_field_requested( ?WP_REST_Request $request, string $field ): bool` - Whether a REST response should include a field: true when `_fields` names it or is absent, so expensive extra fields are computed only when asked for

**Field builders:**
- `htax_rest_build_icon_data( WP_Term $term )` - Build `htax_icon` REST field value (null when no icon set)
- `ATCF_REST_API::format_term_locations( int $term_id )` - **Owned by ATCF.** Returns `htax_locations` REST field value for a term: Schema.org Place array, or null when no locations. Caches per term_id for request lifetime. HT calls this with a `method_exists` guard; it uses `ATCF_Locations::get_by_term_id()` internally so no taxonomy parameter is needed.

**Term endpoint filters & registration:**
- `htax_filter_rest_prepare_term()` - Block hidden terms with 404 (always enforced); inject sparse `htax_*` fields when data exists. Enrichment fields are also skipped when `_locale=user` is present in the request — `wp.apiFetch` always adds this param so the block editor never receives the enrichment fields (it reads HyperTaxonomy display data from PHP-localized `htaxTermMeta` instead); external REST clients, which do not send `_locale=user`, receive full enrichment. Hidden-term 404 enforcement fires unconditionally regardless of `_locale=user`.

**Posts endpoint extensions:**
- `htax_term_peers` field — registered by TermConnect (when active) on post REST responses. Returns peer connection data for all terms on returned posts. See [TermConnect's REST API](https://hypertaxonomy.org/termconnect/developer-reference/rest-api.html#post-endpoints).

**Relationship type endpoints (includes/core/relationship-types-rest.php):**

**`_fields` awareness.** All HT-added fields are gated by `htax_rest_field_requested( ?WP_REST_Request $request, string $field ): bool`. Returns `false` if: (a) the field is globally disabled by the Term Inclusion Relationships setting (`htax_includes`, `htax_synonyms`), OR (b) a `_fields` parameter was supplied and does not include this field. Returns `true` when `_fields` is absent. Callers should specify `_fields` to avoid unused computation; TermConnect does this in all outbound requests.

**All term fields are available on peer collection responses.** Every field listed in the build-helpers above is available on both individual and peer collection responses when requested in `_fields`. `htax_synonyms` and `htax_includes` are gated by the Term Inclusion Relationships setting. `htax_relationships` is off when either that setting or Custom Term Relationships and Relationship Types is off. `htax_locations` is gated by the share_locations feature flag.

**`htax_relationships` is also available on individual term responses** (not just peer collection), gated by `_fields`.

**`htax_relationship_types_admin_footer` action.** Fires at the bottom of the relationship types admin page. TC hooks here to inject the "Discover from peer site" UI.

**Scoring:** for each post, take the best matched weight per requested term, with non-direct primary matches discounted when `htax_expand` is on, sum those, and divide by the sum of the primary-term weights, clamped to 1.0. Weights come from `htax_term_weights` (default 1.0). Hidden-term rows are excluded via `is_hidden = 0`. AND mode (`htax_match=all`) removes posts missing a match in any primary term group before normalization.

### Native Block Extensions (includes/core/native-blocks.php)

### Shortcodes and Blocks (includes/shortcodes/, includes/classes/class-blocks.php)

**`includes/shortcodes/class-filter-options.php`**

- `htax_get_archive_exclude_term()` - Get the term ID to exclude from term filter pools when rendering in an archive context.

**`includes/shortcodes/class-shortcode-base.php`**

- `HyperTaxonomy_Shortcode_Base->resolve_term_ids( array $atts, string $taxonomy )` - Resolve the final include/exclude term ID sets from shortcode attributes.
- `HyperTaxonomy_Shortcode_Base->load_redirect_terms( $taxonomy )` - Load redirect terms for a taxonomy
- `HyperTaxonomy_Shortcode_Base->is_term_redirect( $term )` - Check if a term is a redirect term
- `HyperTaxonomy_Shortcode_Base->get_redirect_target( $term )` - Get redirect target information for a term
- `HyperTaxonomy_Shortcode_Base->build_redirect_link_attributes( $term, &$classes )` - Build redirect link attributes based on redirect link behavior setting
- `HyperTaxonomy_Shortcode_Base->sanitize_class_parameter( $class_string )` - Sanitize and parse class parameter into array of CSS class names
- `HyperTaxonomy_Shortcode_Base->build_container_classes( $base_class, $include_columns = true )` - Build container CSS classes array
- `HyperTaxonomy_Shortcode_Base->build_container_id_attr()` - Build the id attribute string for a container element.
- `HyperTaxonomy_Shortcode_Base->should_exclude_term( $term )` - Check if term should be excluded based on filter criteria
- `HyperTaxonomy_Shortcode_Base->filter_terms( $terms )` - Filter terms array based on exclusion criteria
- `HyperTaxonomy_Shortcode_Base->process_header_placeholders( $header_text, $terms, $taxonomy )` - Process header placeholders
- `HyperTaxonomy_Shortcode_Base->process_placeholders( $text, $term, $depth = 0 )` - Process placeholders in text
- `HyperTaxonomy_Shortcode_Base->build_term_item_classes( $term, string $base_class )` - Build CSS class array for a term item, including redirect-specific classes.
- `HyperTaxonomy_Shortcode_Base->build_term_item_styles( $term, array $extra_styles = array() )` - Build inline CSS styles array for a term item.
- `HyperTaxonomy_Shortcode_Base->build_term_name_html( $term )` - Return the escaped term name, wrapped in <em> if the term is a redirect and italicize_redirects is enabled.
- `HyperTaxonomy_Shortcode_Base->no_italic( $term, string $html )` - Wrap $html in a font-style:normal span when the current term's name is italicised, so append/count content rendered inside the link element cannot inherit italic from any source (outer container, theme CSS, or browser rendering context after <em>).
- `HyperTaxonomy_Shortcode_Base->get_redirect_title( $term )` - Get title attribute for redirect term

### Admin Pages

- **Terms menu** (`includes/admin/admin-menu.php`) - `htax_register_admin_menu()` adds the top-level menu, which opens Term Groups; `htax_sort_terms_submenu()` keeps its submenus in order
- **Term Groups** (`includes/admin/group-admin.php`) - The native term screens for the `group` taxonomy, extended with the fields listed under [Group Admin](#admin--group-admin-includesadmingroup-adminphp) below
- **Term Relationship Types** (`includes/admin/relationship-types-admin.php`) - `htax_render_term_relationships_page()`
- **Merge Terms** (`includes/admin/merge-terms.php`) - `htax_render_merge_terms_page()`, a form over `htax_merge_terms()`
- **Convert Terms** (`includes/admin/convert-terms.php`) - `htax_render_convert_terms_page()`, a form over `htax_convert_terms_batch()`, with `htax_ajax_get_terms_for_conversion()` loading the term checklist
- **Settings** (`includes/admin/settings.php`) - `HyperTaxonomy_Settings`, with Features, Display, Icons, Search, Cache, Export / Import, and Advanced tabs

### Admin — Term Edit & Bulk Actions

**Term Edit (includes/admin/term-edit.php)**

**`admin/js/htax-tag-field-repair.js` — tag field repair:** Loaded in the block editor for post types with tags, with or without `prevent_tag_creation`. The tag field sends a create request for every entered tag it hasn't loaded, treats an "already exists" reply as a match, and waits for the whole batch, so a refusal drops the valid tags entered with it, and entering a tag during an earlier batch or before the post's tags load can drop tags too. The script watches those requests through a `wp.apiFetch` middleware without changing them. When a batch finishes, it assigns the tags the server resolved, restores any of the post's tags that were dropped, and removes pills of refused tags. It locks saving and autosaving while a batch is pending. It covers tags only, not other flat taxonomies.

**`admin/js/htax-term-edit.js` — unsaved changes guard:** On DOM-ready, captures `$('#edittag').serialize()` as the baseline form state. A `beforeunload` listener compares the current serialized state to the baseline and fires the browser's native "Leave site?" dialog (via `e.preventDefault()` + non-empty `e.returnValue`) if they differ. The listener removes itself on `form#edittag submit` so the warning is suppressed when the user intentionally clicks Save. The baseline is also reset on `htax:saved` (AJAX save, no page reload). The comparison covers all standard inputs plus the group-checklist checkboxes (`name="htax_groups[]"`) which are part of `#edittag` even when the floating dropdown is visually detached.

- `htax_render_merged_pill_container( array $args ): void` - Render a single merged pill container. Named `$args` keys: `term_id`, `taxonomy`, `field_terms` (hidden input name for direct term IDs), `field_groups` (hidden input name for group IDs; omit to suppress the groups section), `direct_term_ids` (int[]), `group_ids` (int[]), `via_group_map` (term_id → [group_id, ...] indirect pills via group membership), `via_container_map` (term_id → [group_id, ...] indirect pills via container-group attribution), `custom_type_paths` (term_id → [[label, dir, slug], ...] for cross-panel attribution badges), `redirected_term_ids` (int[] — adds ↩ indicator), `excluded_ids` (int[] — suppresses indirect pills; adds `htax-overridden` strikethrough class to direct pills), `panel` ('includes'|'containers'|'custom', derived from `field_terms` if omitted), `placeholder` (search input text), `disabled` (bool), `taxonomies` (extra taxonomy slugs for cross-taxonomy relationships). Batch-loads all term and group objects via two `get_terms()` calls, and all indirect-group objects via a third call, building `$all_group_name_map` inline — group names for tooltip data are resolved entirely in PHP (not via `HTAX_Ajax.group_names`, which is only used by JS for dynamically added pills). Outputs a `.htax-tag-container.htax-merged-container` div with `data-panel`, `data-current`, `data-taxonomy`, `data-taxonomies`, `data-action` attributes containing: group section label + editable group pills + add-group button + terms section label + direct term pills (sorted A–Z, with × remove button) + add-term button + `.htax-search.htax-merged-search` input + hidden inputs + indirect section label + indirect pills (sorted A–Z, `.htax-indirect`, no remove button) + group dropdown checklist. JS-side: `isTermExcluded()` checks for an excluded pill by searching from `.htax-rel-tab-panel` (the common ancestor of the includes and excludes panels) — not from `[data-panel]` on the merged container itself, which is a descendant of the panel rather than a sibling to `.htax-exclude-panel`.
- `htax_build_via_group_map( array $group_ids, string $taxonomy ): array` - Calls `htax_get_terms_in_groups( [$gid], $taxonomy )` per group ID (uses its own per-group cache) and builds `term_id => [group_id, ...]` map. Per-group iteration preserves attribution; cache hits are cheap for groups already resolved this request.
- `htax_build_container_via_group_map( array $cont_group_ids, string $taxonomy ): array` - Expands `$cont_group_ids` upward via `htax_get_group_ancestors()` (mirrors htax_get_container_terms() Path 3), then queries `member_of` rows `WHERE target_term_id IN ($expanded)` directly; returns `term_id => [group_id, ...]`. Used for the containers panel to find terms that are members of ancestor groups of the current term's container groups.
- **Hide indirect relationships toggle:** Eye-icon `<button class="htax-via-header-btn htax-via-section-toggle">` rendered in the Term Relationships `<th>` header when any indirect pills exist for the current term. Clicking it toggles class `.htax-via-hidden` on `.htax-inclusions-field` via JS; CSS hides all `.htax-indirect` pills when that class is present. State persisted per term in `localStorage` under key `htax-via-hidden-section-{termId}`.

**Term Edit Locking (includes/admin/term-edit-lock.php)**

Prevents concurrent editing of the same term or relationship type by multiple admins, using the same mechanism as WordPress post locking: a heartbeat-refreshed lock with a take-over flow.

**Lock storage:**
- Terms: `_htax_edit_lock` term meta — `"{timestamp}:{user_id}"`. Applies to every term edit screen (`edit-tags.php` / `term.php`) regardless of whether the taxonomy is HyperTaxonomy-enabled; covers groups, custom taxonomies, and built-in taxonomies.
- Relationship types: `htax_rt_lock_{slug}` transient — same format; TTL = `HTAX_LOCK_TIMEOUT + 30s` so transient expiry slightly outlasts the logical timeout.

**Lock timeout:** `HTAX_LOCK_TIMEOUT` = 150 seconds (matches WordPress post lock). A lock with no heartbeat refresh for 150 s is considered expired.

**Page-load flow:** On `load-edit-tags.php` / `load-term.php` (terms) or `admin_init` when `?page=htax-term-relationships&edit=slug` (RT): if the lock belongs to another non-expired user, render the blocking `#htax-term-lock-dialog` overlay with "Go Back" and "Take Over" buttons; otherwise set the lock and render the edit form normally. The take-over button is a nonce-protected URL (`?htax-take-lock=1` / `?htax-rt-take-lock=1`); the handler verifies the nonce, forcibly overwrites the lock, then redirects back to the same URL without the parameter.

**Heartbeat flow:** The client (`admin/js/htax-term-edit-lock.js`) sends `htax_term_lock_refresh` or `htax_rt_lock_refresh` on each heartbeat tick. The server handler (`htax_heartbeat_refresh_lock`) verifies the nonce, then either refreshes the lock timestamp (returns `lock_refreshed: true`) or signals displacement (returns `lock_error: true` with the new holder's name and avatar URL). On displacement the JS hides the editing section, populates the `#htax-lock-message-taken` section, and shows the dialog.

**Dialog HTML:** Always rendered in `admin_footer` via `htax_render_lock_dialog()` using WordPress's standard `.notification-dialog-wrap` CSS classes. Contains two sections: `#htax-lock-message-editing` (shown at page load when another user holds the lock) and `#htax-lock-message-taken` (always hidden at load, shown by JS on heartbeat displacement). When the current user holds the lock the entire dialog is hidden at page load.

**Bulk Actions (includes/admin/bulk-actions.php)**
- `htax_ajax_bulk_assign_groups()` — `wp_ajax_htax_bulk_assign_groups` handler; accepts `term_ids[]`, `add_groups[]`, `remove_groups[]`, `taxonomy`, `nonce`; for each term, merges its existing group IDs with `add_groups` then removes `remove_groups` (preserving all unmentioned groups), then calls `htax_set_term_groups()`; returns `{ processed: N, message: string }`. The JS modal uses three-state checkboxes (checked = add to all, unchecked = remove from all, indeterminate = no change); only groups whose state differs from the initial state are sent as `add_groups` or `remove_groups`. Group labels passed to JS are decoded with `html_entity_decode()` before `wp_localize_script` to avoid double-encoding (WordPress's `get_terms()` already HTML-encodes names for display).

### Admin — Group Admin (includes/admin/group-admin.php)

Group admin hooks to `edited_group` (and `group_edit_form_fields`) to manage four relationship sets visible from the group's edit screen. The three member and inclusion saves share one routine, `htax_replace_group_screen_rows()`, which replaces the group's rows of one type in a transaction and rolls back with an error notice if a write fails. After the commit each one calls `htax_fire_group_changes_per_term()`, which fires the term-level hook (`htax_term_groups_changed`, `htax_container_groups_changed` or `htax_included_groups_changed`) once per changed term with that term's group list after and before the save, so the search index and caches update as they do for a term-screen save:

- `htax_add_terms_with_container_group_field()` / `htax_save_terms_with_container_group()` — Display/save `includes` rows where this group is the source (G→T). Save path: calls `htax_reload_term_caches_on_edit` for each changed target term, calls `htax_invalidate_group_relationship_caches`, then calls `htax_clear_member_caches_for_groups([group_id], 'inc')` to clear `htax_inc_M` for all members of this group and its ancestor groups (path 3 of `htax_get_included_terms` expands member groups downward, so ancestor-group members also reach these container rows).
- `htax_add_terms_with_included_group_field()` / `htax_save_terms_with_included_group()` — Display/save `includes` rows where this group is the target (T→G: terms whose archives expand to include all this group's members). Save path: calls `htax_reload_term_caches_on_edit` for each changed source term, calls `htax_invalidate_group_relationship_caches`, then calls `htax_clear_member_caches_for_groups([group_id], 'cont')` to clear `htax_cont_M` for all members of this group and its descendant groups (`htax_get_container_terms` path 2 matches exact membership; `htax_get_terms_in_groups` expands downward).

**Terminology:** "terms with container group" (G→T) and "terms with included group" (T→G) are opposite directions of the `includes` relationship involving a group. `htax_grp_*` caches are only affected by membership changes (`member_of` rows). G→T container-group changes affect `htax_inc_*` caches for the target term T and for members M of the group (and ancestor groups). T→G included-group changes affect `htax_cont_*` caches for members M of the group (and descendant groups). Custom-type group rows additionally affect `htax_crel_*/htax_crel_rev_*` caches for members. `htax_invalidate_group_relationship_caches` handles the T and custom-rel side; `htax_clear_member_caches_for_groups` handles the member side.

### Admin — Relationship Types (includes/admin/relationship-types-admin.php)

Admin UI for viewing built-in relationship types and managing user-defined types. Registered as the "Term Relationship Types" submenu under the Terms top-level menu.

- `htax_handle_save_relationship_type()` - `admin_post_htax_save_relationship_type` handler; validates and persists a new or updated custom type via `htax_set_custom_relationship_type()`; calls `htax_remove_invalid_term_relationships()` after save to purge rows that violate the updated constraints
- `htax_handle_remove_relationship_type()` - `admin_post_htax_remove_relationship_type` handler; deletes a custom type via `htax_remove_custom_relationship_type()`; redirects back with success/error notice

### Admin — Tables, AJAX, Import/Export

**Post Editor (includes/admin/post-edit.php)**
Loaded unconditionally (outside `is_admin()`) so the REST field is available during REST API requests.
- `htax_enqueue_post_editor_assets()` - Enqueue CSS/JS and build JS globals for the block/classic editor. PHP always emits the full taxonomy-gated data; per-user display preferences (which of the five indicators to show) are stored in the Gutenberg `core/preferences` store under `htax/post-edit` and applied client-side. Also enqueues `htax-editor-prefs.js` in the block editor, which registers five `PluginMoreMenuItem` toggles in the ··· menu and dispatches a one-time Gutenberg notice (via `core/notices`) on first block-editor visit; dismiss state stored in user meta `_htax_prefs_callout_dismissed` (set via `htax_ajax_dismiss_prefs_callout` / `wp_ajax_htax_dismiss_prefs_callout`). Globals emitted:
  - **`htaxTermMeta`** — keyed by term ID; each entry: `{id, name, slug, taxonomy, is_redirect, is_hidden, is_hierarchical, inclusion_count, container_count, included_names, container_names, redirect_target_name}`. Hierarchical taxonomies include all terms; flat taxonomies include only the post's currently assigned terms. Terms with at least one HT flag are always included; additionally, all assigned terms of a `customize_archives`-enabled taxonomy are included (even plain terms with no flags) so the sticky pin can be shown.
  - **Assigned term request** (Gutenberg only, flat taxonomies, when `preload_editor_terms` is on) — a `wp.apiFetch` middleware added inline after `wp-api-fetch` answers the field's `per_page=-1` request for the post's terms from the page, so the field doesn't accept typing before its terms load. WordPress's `block_editor_rest_api_preload_paths` can't serve it, because the REST server rejects `per_page=-1`. If the path stops matching, the field makes the request itself.
  - **`htaxPreloadedTerms`** (Gutenberg only, when a `post` URL param is present) — assigned term objects injected into the Gutenberg Redux store before `wp-edit-post` initializes, via `wp_add_inline_script('wp-edit-post', ..., 'before')` using `receiveEntityRecords` + `finishResolution`. Eliminates the per-term REST calls Gutenberg would otherwise make when it resolves `getEntityRecord`.
  - **`htaxFlatTaxonomies`** (Gutenberg only) — array of `{slug, restBase}` for flat (non-hierarchical) HT-enabled taxonomies that are REST-exposed. Used by the JS autocomplete subscriber.
  - **`htaxTaxonomyFeatures`** (Gutenberg only) — `{[taxonomy]: {inclusions, containers, redirects, visibility}}` per-taxonomy feature flags (from `htax_taxonomy_has_feature()`). Applied client-side by `fetchPendingMeta` when merging REST responses. User display preferences are NOT reflected here — they come from `core/preferences`.
  - **`htaxStickyConfig`** (Gutenberg only) — array of `{name, label, restBase}` for taxonomies with the `customize_archives` feature; always emitted when at least one eligible taxonomy exists. Whether pins actually appear is gated by the per-user `htax/post-edit → sticky` preference.
  Badge rendering (via `admin/js/htax-post-edit.js` and `admin/css/htax-post-edit.css`): Classic Editor injects badge `<span>` elements before term labels; Gutenberg token pills receive badge groups prepended into `token-text`, while hierarchical checklist rows receive badge groups injected between the checkbox and label. Deduplication uses a `data-htax-badges` signature attribute — the DOM is only touched when counts or state change. Badge groups display:
  - **Includes badge**: `⇉ [N]` (rightward-double-arrow symbol with count) in light blue (`#ddeeff` bg, `#7ab8e8` border, `#003366` text)
  - **Container badge**: `⇇ [N]` (leftward-double-arrow symbol with count) in light green (`#eef7ef` bg, `#a5d6a7` border, `#2e7d32` text)
  - **Redirect indicator**: Term names receive italic styling, plus a `↪` hook symbol (U+21AA) appended to the name via `.redirect-indicator` span; tooltip shows redirect target name
  - **Hidden indicator**: Terms marked hidden receive reduced opacity (45%) via `data-htax-hidden="1"` attribute selector
  **Autocomplete subscriber** (Gutenberg, flat taxonomies only): `wp.data.subscribe` watches the Redux store for term IDs newly added via the Gutenberg tag autocomplete mid-session (i.e. IDs not present in `htaxTermMeta` at page load). When a new ID is detected, it calls `GET /wp-json/htax/v1/term-meta` to fetch badge metadata and merges the result into `htaxTermMeta` for subsequent badge rendering passes. Terms with no HT flags are merged when the taxonomy has `customize_archives` enabled (so newly-added plain tags still get sticky pins).
  **Sticky for Terms panel**: `useEffect` auto-removes terms from the `htax_sticky_terms` post attribute whenever they are unassigned from the post. Checkboxes for newly-added (unsaved) terms are disabled with the helper text "Save the post first to enable sticky for this term." Redirect terms are excluded from sticky options entirely (their archives redirect away, so stickying has no visible effect).
- `htax_remove_new_tags_from_post_request()` - With the "prevent tag creation" setting on, remove tags that don't exist yet from Classic Editor saves (`admin_init`). WordPress stops assigning a post's tags at the first one it can't create, so refusing the new tag at insertion would discard the whole tag change.

**List Table (includes/admin/tables.php)**
- `htax_enqueue_table_assets()` - Enqueue list table CSS/JS with localized filter config (`htaxTablesConfig`); includes `bulkAssignNonce` and `groupFilter.options` (group names decoded via `html_entity_decode()` to avoid double-encoding from `get_terms()` display escaping); localized config powers both the Term Groups filter dropdown and the bulk assignment modal
- `htax_display_hidden_column()` - Display visibility icons in term lists; also renders a hidden `<span class="htax-qe-vis-data" data-hidden="0|1" data-parent-hidden="0|1">` per row. `data-hidden` reflects the term's own visibility state; `data-parent-hidden` is 1 when any ancestor is currently hidden (computed by walking the parent chain against `htax_get_all_hidden_term_ids()`, cached with a static variable across rows). Quick Edit JS reads `data-parent-hidden` to disable the visibility checkbox with a tooltip when the user cannot change visibility without first making a parent visible.
- `htax_sortable_column_terms_clauses( $clauses, $taxonomies, $_args )` - `terms_clauses` filter; rewrites `ORDER BY` when `?orderby=` matches a HyperTaxonomy column key; scoped to `htax_is_term_list_request()` only; uses `EXISTS` subqueries (redirect row in `wp_htax_term_relationships`, meta presence in `wp_termmeta`) so all terms appear regardless of whether they carry the attribute; secondary sort always `t.name ASC`; Term Groups uses `GROUP_CONCAT` of group names to produce a stable alphabetic key
- `htax_add_sticky_indicators()` - Add sticky indicators to term columns in post list

**Term Filters (includes/admin/term-filters.php)**
- `htax_is_term_list_request(): bool` - Returns true when the current request is an admin term list screen or its AJAX counterpart (Quick Edit). Used to gate `get_terms_args` filters that should only apply on list screens, not on dropdowns or autocomplete queries.

**AJAX Handlers (includes/admin/ajax.php)**
- `htax_ajax_search_terms()` - Autocomplete search (6-bucket ranking); searches every taxonomy in `taxonomies[]`, labeling results by taxonomy when there is more than one; optional `include_groups` / `exclude_groups` (comma-separated group IDs expanded to term IDs via `htax_get_terms_in_groups()` to restrict the option pool); results cached via `htax_get_cache_duration('autocomplete')`
- `htax_ajax_import_tag_groups_finish()` (`wp_ajax_htax_import_tag_groups_finish`) - Chunked import, final request: clears caches, stores the run snapshot via `htax_store_tag_groups_import_snapshot()`.
- `htax_ajax_count_hidden_descendants()` — `wp_ajax_htax_count_hidden_descendants` handler; accepts `term_ids[]`, `taxonomy`, `nonce`; for each term ID returns a count of hidden descendants via `get_term_children()` filtered against `htax_get_all_hidden_term_ids()`. Descendants that are themselves present in `term_ids` are excluded from every count — when a parent and child are both selected for bulk "Make Visible", the child does not inflate the cascade-prompt count since it will be made visible on its own. Called before bulk and Quick Edit make-visible saves to decide whether to show the cascade modal.

**Export (export-hypertaxonomy.php):**
- `htax_generate_export_data( $taxonomies, $include_term_posts )` - Generate JSON export data
  - Exports terms with metadata (visibility, icons, archive settings, sticky posts)
  - Exports relationships (includes, containers, redirects - all using slug-based matching)
  - Optional: exports term-to-post assignments
  - Returns array with version, site_url, exported_at, terms[], relationships[], term_post_assignments[]
- `htax_download_export( $taxonomies, $include_term_posts )` - Trigger JSON file download

**Import (import-hypertaxonomy.php):**
- `htax_validate_import_data( $data )` - Validate JSON structure and version
- `htax_preview_import( $data )` - Analyze import file and return preview summary
- `htax_process_import( $data, $options )` - Core import logic: terms, then a second pass for parents listed after their children (ignored on flat taxonomies), custom relationship types, relationships (redirects through `htax_set_redirect()`, group memberships through `htax_set_term_groups()`, added to existing ones; other types by direct insert), post assignments
- `htax_process_import_with_output( $data, $options )` - Import with HTML progress output

**Tag Groups Migration (import-tag-groups-handler.php):** Tag Groups was rebranded "PublishPress Tag Groups" in October 2026 — the database format below is unchanged under either name.
- `htax_get_tag_groups_site_token(): string` - Random UUID, generated once and stored in the `htax_tag_groups_site_token` option, scoping the mapping below to this site. Confirmed `htax_generate_export_data()`'s `$meta_keys` is a hardcoded enumerated whitelist that does not include the mapping meta key, so HT's own JSON export/import never carries it across sites — the scoping instead guards against other ways `wp_termmeta` can move between sites (a raw database export/import, a staging→production clone, a third-party site-migration plugin), where a bare Tag Groups ID could otherwise collide with an unrelated group on the destination site and silently rename it.
- `htax_orphan_deleted_tag_groups( array $current_tg_group_ids ): array` - Strips the mapping meta (never deletes the HT term) for any previously-mapped group no longer in Tag Groups' current `term_groups` option.
- `htax_split_term_groups_by_provenance( array $group_ids ): array` - Splits a term's current HT groups into Tag-Groups-mapped vs. HT-only, by presence of the mapping meta.
- `htax_apply_tag_groups_term_assignment( int $term_id, string $taxonomy, array $tg_mapped_new_ids, string $mode = 'keep' ): string` - Applies one term's Tag Groups membership report to its HT group assignment (the provenance-filtered merge), returning `'assigned'`, `'skipped'`, or `'error'`. Used by both the batch loop and the live-sync handler.
- `htax_store_tag_groups_import_snapshot(): void` - Stores `{groups, terms, time}` to the `htax_tag_groups_last_import` option after a successful run, for the migration screen's diff display.
- `htax_import_tag_groups_create_groups(): array` - First pass: resolves/creates/renames group terms, applies hierarchy, detects deletions. Matches ID-first via the site-scoped mapping.
- `htax_import_tag_groups_process_batch( array $group_mapping, int $offset, int $batch_size, string $mode = 'keep' ): array` - Batch pass: assigns one batch of terms to their groups. Works on any taxonomy, gated by the per-row `group_assignment` feature check. `$mode` controls the Tag-Groups-mapped subset only (HT-only memberships are always preserved): `'keep'` (default) only ever adds; `'mirror'` makes it identical to Tag Groups' current report for the term, including removals.
  - Reads from wp_options: term_groups, term_group_labels, term_group_positions, term_group_parents
  - Reads from wp_termmeta: _cm_term_group_array
  - Converts to HyperTaxonomy Term Groups

**Integration mode (import-tag-groups-handler.php):**
- `htax_tag_groups_integration_mode(): string` - The `htax_tag_groups_integration_mode` option (`'independent'` or `'locked'`; default `'independent'`), validated against the known set. `'independent'` disables every lock below regardless of what data Tag Groups has written; `'locked'` locks them between manual migration runs. Set from Settings → HyperTaxonomy → Export / Import → Sync via the `htax_save_tag_groups_sync_settings` AJAX action (ajax.php), which saves the mode, the continuous-sync checkbox, and removal handling (`htax_tag_groups_sync_mode`) together. Enabling continuous sync reports `needs_resync: true` so the UI can run one full resync first via the Migrate subtab (`resync=1`).

**Re-run overwrite protection (import-tag-groups-handler.php, loaded unconditionally in admin — not just from the importer's own AJAX requests, since `htax_save_term_groups()`/`htax_save_terms_in_group()` need these on every term/group save):**
- `htax_tag_groups_import_in_progress( ?bool $set = null ): bool` - Reentrancy flag, set around `htax_import_tag_groups_create_groups()`'s own rename/hierarchy writes so `htax_lock_tag_groups_mapped_name()` can tell the importer's own legitimate write apart from an admin's.
- `htax_tag_groups_taxonomy_is_managed( string $taxonomy ): bool` - Whether Tag Groups has actually written `_cm_term_group_array` termmeta for a taxonomy, read directly rather than via any Tag Groups setting — the gate for every lock below. Returns `false` unconditionally when integration mode is `'independent'`. Per-request cached.
- `htax_save_term_groups( int $term_id )` (term-edit.php) - Re-merges in the Tag-Groups-mapped subset of the term's existing group IDs over whatever was submitted, for any taxonomy `htax_tag_groups_taxonomy_is_managed()` is true for. Covers the term's own edit screen and Quick Edit's "Term Groups" checklist, which reuses this handler.
- `htax_tag_groups_native_field_promoted(): bool` - Whether Tag Groups' own native per-term field should take the always-visible spot HT's Term Groups selector normally occupies on the term edit/add screens, with HT's own (now read-only) selector demoted into the "More options" collapsible instead. True only when Locked mode is selected and Tag Groups is currently active. Read by `htax_add_edit_form_collapsible_start()` / `htax_add_form_collapsible_start()` (emitted as `data-promote-native-groups` for the JS reorder in htax-term-edit.js / htax-tables.js) and by the `{taxonomy}_*_form_fields` registration in `includes/hooks/actions.php` (controls whether `htax_add_term_groups_field()` is hooked at priority 8, always visible, or priority 16, inside the collapsible between Redirect and Term Relationships).
- `htax_find_ht_group_by_tag_groups_id( int $tg_group_id ): ?int` - Read-only companion to `htax_resolve_or_create_tag_group()` (which calls this internally for its own ID-match step) — looks up the HT group mapped to a Tag Groups ID on this site without creating anything. Used by the live-sync membership handler, which only ever sees IDs Tag Groups itself reports as already existing.

**Live sync (tag-groups-live-sync.php, loaded unconditionally in admin alongside import-tag-groups-handler.php; registers nothing unless `htax_tag_groups_live_sync_active()` is true):**
- `htax_tag_groups_live_sync_enabled(): bool` (import-tag-groups-handler.php) - The `htax_tag_groups_live_sync` option is on and the integration mode is `'locked'`. It is cleared whenever the mode is saved as `'independent'`.
- `htax_tag_groups_live_sync_active(): bool` (tag-groups-live-sync.php) - `function_exists('tag_groups_init')` and `htax_tag_groups_live_sync_enabled()`.
- Three Tag Groups hooks (Free v3.0.0 / Pro v2.0.9), registered only when the above is true:
  - `term_group_saved` (no args) → `htax_tag_groups_live_sync_on_group_saved()` - thin wrapper calling `htax_import_tag_groups_create_groups()` directly; that function already does this hook's whole job (resolve/create/rename, one-time hierarchy seed, orphan detection).
  - `tag_groups_term_group_deleted( int $group_id )` → `htax_tag_groups_live_sync_on_group_deleted()` - calls `htax_orphan_deleted_tag_groups()` against Tag Groups' current `term_groups` option.
  - `tag_groups_groups_of_term_saved( array $term_groups, int $term_id )` → `htax_tag_groups_live_sync_on_term_groups_saved()` - maps Tag Groups' IDs to HT IDs via `htax_find_ht_group_by_tag_groups_id()` (skipping any not yet mapped) and calls `htax_apply_tag_groups_term_assignment()`, same as the periodic batch importer does per term.

**UI niceties for the above (no new enforcement — the save-function guards above are what actually prevent the overwrite):**
- `htax_hierarchical_checklist()` (class-hierarchical-checklist.php) has `disabled_terms` (int[]) and `disabled_title` (string) args, and the walker's `start_el()` accepts `$args['disabled']` as either a bool (existing all-items behavior) or an array of specific term IDs to disable — used to gray out locked groups in the term-edit screen's add-group checklist.
- Quick Edit's group checklist (`htax-tables.js`, built from `htaxTablesConfig.groupFilter`) uses `lockedGroupIds`/`lockedTitle`, localized from PHP once per screen (the lock is per-taxonomy, not per-row) — disables the matching checkboxes with the same tooltip.

### Cache Management (includes/core/cache.php)

**Transient wrappers:**
- `htax_get_transient( $key )` / `htax_set_transient( $key, $value, $expiration )` / `htax_delete_transient( $key )` - Transient API wrappers used for every HyperTaxonomy transient; resolve the storage key via `htax_transient_key()`

**Nuclear (full-reset):**
- `htax_clear_caches_on_feature_change( $old_value, $new_value )` - `update_option_htax_settings` (priority 10): calls `htax_clear_all_caches()` when `taxonomy_features` changed. Relationship caches are feature-independent (the getters apply `term_relationships` on read), but rendered outputs — post-list results, contributing terms — are computed with the flags applied
- `htax_clear_all_caches()` - Nuclear option: deletes all `_transient_htax_*` entries. Per-type variants: `htax_clear_relationship_caches()`, `htax_clear_search_caches()` (htax_contrib_*), `htax_clear_autocomplete_caches()` (htax_ac_*), `htax_clear_sticky_caches()` (htax_sticky_*), `htax_clear_shortcode_caches()` (htax_pl_*, htax_total_*)

**Per-term invalidation:**
- `htax_delete_term_transients( int $term_id )` - Delete all transients for a single term: core logical caches (`htax_inc_`, `htax_cont_`, `htax_excl_`, `htax_redir_`, `htax_groups_`, `htax_syno_`), contrib cache, all direct relationship caches for every registered type via `htax_delete_custom_rel_caches_for_term()` (covers `htax_crel_`, `htax_crel_rev_`, `htax_direct_rel_`, `htax_direct_rel_grp_` for each slug including core `'includes'`), and post list shortcode caches. After clearing, calls `htax_queue_term_cache_rebuild()` to schedule async re-warming.
- `htax_delete_term_transients_batch( array $term_ids )` - Batch-delete core relationship transients for multiple terms in two queries instead of N×individual calls. Builds all named option keys for `htax_inc_`, `htax_excl_`, `htax_redir_`, `htax_groups_`, `htax_cont_`, and `htax_syno_` prefixes across all term IDs and removes them in a single `DELETE … WHERE option_name IN (…)`. Then does two broad prefix deletes for `htax_contrib_` and `htax_pl_` (one query each, covering all affected terms at once). Finally queues each term ID via `htax_queue_term_cache_rebuild()`. Used by `htax_invalidate_group_relationship_caches()` to handle large fan-outs efficiently.
- `htax_do_rebuild_term_caches( array $queue )` - Async cron handler (hooked to `htax_async_rebuild_term_caches`). Re-warms `htax_inc_{term_id}`, `htax_cont_{term_id}`, and `htax_syno_{term_id}` for each queued term ID by calling `htax_get_included_terms()`, `htax_get_container_terms()`, and `htax_get_synonym_terms()`. Skips term IDs that no longer exist.
- `htax_delete_custom_rel_caches_for_term( int $term_id )` - Delete `htax_crel_{slug}_{term_id}`, `htax_crel_rev_{slug}_{term_id}`, `htax_direct_rel_{slug}_{term_id}`, `htax_reverse_rel_{slug}_{term_id}`, `htax_direct_rel_grp_{slug}_{term_id}`, and `htax_reverse_rel_grp_{slug}_{term_id}` for every registered relationship type; called by `htax_delete_term_transients()`
- `htax_invalidate_term_caches_on_delete( $term_id_or_term, $tt_id = 0, $taxonomy = '' )` - Full cache invalidation for a deleted term. Uses a **read-before-clear** pattern: reads T's outgoing direct caches (`htax_direct_rel_includes_T`, `htax_direct_rel_grp_includes_T`, `htax_direct_rel_{slug}_T`, and `htax_direct_rel_grp_{slug}_T` for all non-core slugs) *before* clearing T's own transients. Steps: (1) PRE-CLEAR: if all those caches are warm, the outgoing data is fully available with no DB queries; if any are cold, falls back to a broad `SELECT … WHERE source_term_id = T` query and parses rows into categorized arrays (`$out_inc_ids`, `$out_inc_grp_ids`, `$out_crel_ids[slug]`, `$out_crel_grp_ids[slug]`); (2) clears T's own caches via `htax_delete_term_transients(T)`; (3) surgically updates member-group caches; (4a) for incoming references (A→T rows): calls `htax_reload_term_caches_on_edit(A)` — when A is a group (G→T row), also calls `htax_clear_member_caches_for_groups([G], 'inc')` to clear `htax_inc_M` for members of G and ancestor groups; (4b) for outgoing references, iterates flat pre-read arrays: `$out_inc_ids` → deletes `htax_cont_U` + `htax_reverse_rel_includes_U`; `$out_inc_grp_ids` → deletes G's own `htax_reverse_rel_includes_G` then calls `htax_clear_member_caches_for_groups([G], 'cont')`; `$out_crel_ids[slug]` → deletes `htax_crel_rev_{slug}_U` + `htax_reverse_rel_{slug}_U`; `$out_crel_grp_ids[slug]` → deletes G's own `htax_reverse_rel_{slug}_G` then calls `htax_clear_member_caches_for_groups([G], 'crel_rev', slug)`; (5) clears caches for terms related via T's group memberships
- `htax_reload_term_caches_on_edit( int|WP_Term $term_id_or_term, int $tt_id )` - Invalidate all caches for a term on edit (no eager reload — lazy-load on next access)

**Targeted caches:**
- `htax_clear_count_cache()` - Clear total post count caches (htax_total_*)
- `htax_clear_ajax_caches()` - Clear autocomplete caches
- `htax_delete_transients_by_prefix( string $prefix )` - Delete all transients whose key starts with `$prefix` (both value and timeout rows); used for bulk-clearing type-scoped custom rel caches (`htax_crel_{slug}_` and `htax_crel_rev_{slug}_`), count caches, autocomplete caches, sticky post caches, and similar prefix-keyed groups

**Group-aware invalidation:**
- `htax_invalidate_group_relationship_caches( array $group_ids ): void` - Invalidate `htax_inc_*`, `htax_crel_{slug}_*`, and `htax_crel_rev_{slug}_*` caches for all terms that include or are container-related to the given groups (and their ancestors/descendants). Runs two SQL queries: (A) inclusions fwd — collect all `source_term_id` values from T→G rows where G is in `groups_with_ancestors`; (B) inclusions rev/container — collect all `target_term_id` values from G→T rows where G is in `groups_all` (ancestors + descendants). Both collected ID sets are merged and passed to `htax_delete_term_transients_batch()` in one call, which batch-deletes named transients and queues async rebuild for all affected terms. Custom rel caches (sections C+D) are still cleared per-term via `htax_delete_transient()` since they are slug-keyed and not covered by the batch function. Hooked to `htax_async_group_propagate` cron event (fired by `htax_update_group_membership_caches`); also called directly by `htax_invalidate_caches_on_group_reparent`.
- `htax_clear_member_caches_for_groups( array $group_ids, string $mode, string $cslug = '' ): void` - Clear a specific transient for all members of the given groups. Expands groups upward (`htax_get_group_ancestors`) for `'inc'` mode, or downward (`htax_get_group_hierarchy`) for all other modes, then queries `member_of` rows to find members and deletes the appropriate transient per member. Modes: `'inc'` deletes `htax_inc_M`; `'cont'` deletes `htax_cont_M`; `'crel'` deletes `htax_crel_{cslug}_M`; `'crel_rev'` deletes `htax_crel_rev_{cslug}_M`.
- `htax_invalidate_caches_on_group_reparent( int $term_id, int $tt_id, string $taxonomy ): void` - Hooked to `edited_term` at priority 12. Returns early unless taxonomy is `'group'`. Reads old parent from `htax_term_data_{term_id}` transient (captured by `htax_cache_term_taxonomy_before_edit` at priority 5), compares to current parent, and if the parent changed: (1) calls `htax_invalidate_group_relationship_caches([old_parent, new_parent])` to clear `htax_inc_*`, `htax_cont_*`, and `htax_crel_*/htax_crel_rev_*` for terms T that have rows pointing to the old/new parent chains; (2) calls `htax_clear_member_caches_for_groups([old_parent, new_parent], 'inc')` to clear `htax_inc_M` for members of the old and new parent groups (and their ancestors), because path 3 of `htax_get_included_terms` expands member groups downward so ancestor-group members lose or gain G in their expanded set when G moves.

**Frontend / shortcode caches:**
- `htax_invalidate_contrib_cache( int $term_id )` - Delete all `htax_contrib_{term_id}_p*` pages; deduped per request via static array
- `htax_invalidate_post_list_cache( int[] $term_ids )` - Pattern-delete `htax_pl_*` shortcode caches for given term IDs; over 100 terms clears all of them
- `htax_get_post_index_term_ids( int|int[] $post_ids ): int[]` - Distinct term IDs in the search index for one or more posts
- `HyperTaxonomy_Transient_Batch` - Holds non-expiring transients set during its lifetime and writes them in one statement; created by term shortcode handlers

**Tracing:**
- `htax_trace( string $msg ): void` - Write a timestamped trace line to the debug log when `HTAX_TIMING` is on

**Async cron hooks (registered in includes/hooks/actions.php):**
- `htax_async_group_propagate` → `htax_invalidate_group_relationship_caches( array $group_ids )` — Scheduled by `htax_update_group_membership_caches()` after a group membership change; propagates cache invalidation to all terms that include or are related to the affected groups, asynchronously.

**`includes/core/cache.php`**

- `htax_fire_archive_changed_on_hierarchy( int $term_id, int $tt_id, string $taxonomy )` - Fire htax_term_archive_changed for parent terms when a term's WP parent changes.
- `htax_fire_term_archive_changed( int $term_id, string $taxonomy )` - Fire the htax_term_archive_changed action.

### Group Taxonomy (includes/core/taxonomies.php)

- `htax_register_group_taxonomy()` - Register the `group` taxonomy
- `htax_update_group_term_count( $terms, $taxonomy )` - The group taxonomy's `update_count_callback`: a group's count is its number of direct member terms, not posts

### Search Index (includes/classes/class-search-index.php)

`HyperTaxonomy_Search_Index_Manager` keeps `wp_htax_search_terms` and `wp_htax_post_text` current. Its `on_*` handlers are listed in the [Hook Reference](hook-reference.html).

- `htax_get_search_index()` / `htax_init_search_index()` - Get the manager instance
- `HyperTaxonomy_Search_Index_Manager::queue_post_reindex( $post_ids )` - Queue posts for reindexing; `process_reindex_queue()` runs at `shutdown`, and `process_async_reindex_batch()` handles large batches
- `HyperTaxonomy_Search_Index_Manager::rebuild_indexes(): void` - Full rebuild, run from the `htax_rebuild_indexes` cron event
- `HyperTaxonomy_Search_Index_Manager::get_posts_with_term_ids( $term_ids )` / `get_posts_directly_assigned_terms( $term_ids, $taxonomy )` - Find posts by indexed or directly assigned terms
- `htax_create_search_term_index_table()` - Create the search terms table
- `htax_calculate_direct_term_strength( int $term_id, array $post_text, array $field_weights ): float` - Calculate the built-in model's raw strength for a post and term

### Strength Models (includes/core/strength-models.php)

Lets other plugins supply their own term-to-post strengths alongside the built-in model.

**Public API:**
- `htax_register_strength_model( string $key, array $args = [] ): bool` / `htax_unregister_strength_model( string $key ): bool` - Register or unregister an external model
- `htax_get_strength_models(): array` - All registered models, including the built-in one
- `htax_get_strength_model( string $purpose = 'search' ): string` - The model a search-index read should use; `$purpose` is `'search'` or `'related'`. Falls back to the built-in model when the selected one is unregistered.
- `htax_set_term_strengths( string $model, int $post_id, array $strengths )` - Store an external model's calibrated 0–1 scores for a post's directly assigned terms, and the rows derived from them. All-or-nothing; returns `true` or `WP_Error`. Fires `htax_term_strengths_updated`.
- `htax_remove_term_strengths( string $model, int $post_id = 0, array $term_ids = [] )` - Remove an external model's stored scores
- `htax_get_strength_model_coverage( string $model ): array` - How much of the index a model has scored, per taxonomy
- `htax_strength_model_has_full_coverage( string $model ): bool` - Whether a model has scored every directly assigned post–term pair
- `htax_get_stored_strength_models(): array` - External models with scores in the index

**Internal:**
- `htax_strength_model_sql( string $alias = '', string $purpose = 'search' ): string` - SQL condition restricting index rows to one model; required by any query that reads or aggregates strength
- `htax_derive_indirect_strength( float $source_strength, string $taxonomy, string $relationship_type ): float` - An indirect row's strength from its source term's direct strength
- `htax_get_external_direct_strengths( int $post_id ): array` - Direct strengths stored by external models for a post

**`includes/core/strength-models.php`**

- `htax_collect_strength_rows( int $post_id, array $relationships, array $direct_strengths, string $model, bool $skip_unscored, array &$placeholders, array &$values )` - Build INSERT placeholders and values for one model's rows of a post.
- `htax_write_strength_rows( array $placeholders, array $values )` - Write collected strength rows, replacing strength and is_hidden on existing keys.

### Utilities & Helpers (includes/core/helpers.php)

Shared internal utilities used throughout the plugin. Not part of the public API.

**SQL Utilities:**
- `htax_sql_placeholders()` - Generate SQL placeholders for prepared statements

**Term ID Utilities:**
- `htax_sanitize_term_ids()` - Sanitize and validate array of term IDs
- `htax_parse_term_ids($meta_value, $taxonomy = '')` - Parse a comma-separated term list into term IDs; numeric tokens are IDs, and when `$taxonomy` is given, non-numeric tokens are resolved as slugs within it (slugs are unique per taxonomy). Without a taxonomy, only numeric IDs are kept. Slugs resolve to IDs before any cache key is built. Filters out zero/negative values

**Term Validation:**
- `htax_get_valid_term(int $term_id)` - Safely get and validate a term; no taxonomy parameter (WP term IDs are globally unique); per-request cache (`htax_request_cache( 'valid_term' )`) capped at 500 entries (FIFO eviction) to prevent unbounded memory growth in long-running requests; entries are dropped on WordPress's `clean_term_cache` action, so a term updated or deleted earlier in the request is never returned stale
- `htax_request_cache( string $group ): array&` / `htax_clear_request_cache( string $group, $key = null )` - Named per-request caches. Used instead of function-level statics wherever the cached data can change later in the same request, so the code that invalidates the data (or its transient) can clear the per-request copy too. Groups: `redirect_target`, `redirect_term_ids`, `synonyms`, `valid_term`, `taxonomy_enabled`, `indexed_post_types`, `enabled_taxonomies`.
- `htax_clear_settings_request_caches()` (`includes/core/functions.php`) - Hooked first (priority 0) on `update_option_htax_settings`; clears the per-request copies behind `htax_is_taxonomy_enabled()`, `htax_get_indexed_post_types()` and `htax_get_enabled_taxonomies()`, so handlers later in a settings-save request read the new settings

**Admin Notices:**
- `htax_display_queued_notices()` - Display notices queued from previous requests

**Security:**
- `htax_check_ajax_rate_limit( string $action, int $limit = 0 ): bool` - Per-minute request limit for AJAX handlers, counted per logged-in user or per visitor IP (`htax_rate_limit_client_ip` filter); `$limit = 0` uses the Requests per minute setting (PowerSearch and the search widget), the admin handlers pass their own. Counts live in the object cache when persistent, else in one transient per client and action
- `htax_verify_term_nonce()` - Verify WordPress term edit/add nonces; accepts three nonce sources: `_wpnonce` (action `update-tag_{id}`, full edit form), `_wpnonce_add-tag` (action `add-tag`, AJAX new-term form), and `_inline_edit` (action `taxinlineeditnonce`, WP Quick Edit). The `htax_save_term_data` early-return guard checks for the presence of any of these three fields before calling this function.

**Logging & Error Handling:**
- `htax_log_debug()` - Debug logging (WP_DEBUG + WP_DEBUG_LOG, supports verbose backtrace mode)
- `htax_log_error()` - Error logging (always logs, supports verbose backtrace mode)
- `htax_handle_db_error()` - Check wpdb->last_error; returns `$default` (and logs) on error, returns `false` when no error. Callers use `if ( false !== $err = htax_handle_db_error(...) ) { return $err; }` to short-circuit on failure.
- `htax_throw_on_db_error( string $operation ): void` - Check wpdb->last_error after a write inside a transaction; logs and throws `RuntimeException` on error

**Meta Key Helpers:**
- `htax_get_sticky_assignment_key(int $term_id)` - Get the post meta key for a sticky term assignment (`_htax_sticky_{term_id}`); taxonomy is omitted because WordPress term IDs are globally unique

**Search Query Building (includes/core/search.php):**
- `htax_parse_search_query( string $query, string $near_country = 'US' ): array` - Parse a free-text search string into structured per-field arrays
  - Returns keys: `title`, `body`, `excerpt`, `tags`, `categories`, `terms`, `general`, `proximity`
  - Each field value is an array of `['term', 'required', 'excluded', 'quoted', 'slug_match']` entries
  - `+term` sets `required=true`; `-term` sets `excluded=true`; `"term"` sets `quoted=true`
  - `slug_match=true` for **singular** operators (`tag:`, `category:`, `term:`, taxonomy slug, singular display label) — callers match against term slugs rather than FULLTEXT names. `slug_match=false` for **plural** operators (`tags:`, `categories:`, `terms:`, plural display label) — FULLTEXT matching.
  - `quoted=true` for field-specific terms if the value was wrapped in `"..."` in the input; for general terms, `quoted=true` only when the term appeared as `"word"` — used by exact-match scoring to distinguish intentional label lookups from content searches
  - `proximity` key: array of parsed `near:` tokens (see [As the Crow Flies Integration](../developer-reference/atcf.html)). Empty when ATCF is inactive or no proximity operators are present.
  - `$near_country`: ISO 3166-1 code passed down to proximity tokens for postal-code format validation and centroid lookup. Defaults to `'US'`.
  - Used by all three search systems: post list, advanced widget, PowerSearch
- `htax_get_exact_match_term_ids( string $term_string, string $taxonomy = '' ): array` — B-tree equality lookup: returns term IDs where `LOWER(name) = LOWER($term_string)`, optionally filtered to one taxonomy. Used by PowerSearch to pre-fetch IDs for exact-match scoring.
- `htax_build_boolean_query_string( array $field_terms, array $general_terms = [] ): string` - Build a MySQL FULLTEXT BOOLEAN MODE query string (used for taxonomy scoring and WHERE enforcement)
- `htax_build_natural_language_query_string( array $field_terms, array $general_terms = [] ): string` - Build a NATURAL LANGUAGE MODE query string (operators stripped, excluded terms omitted; used for post text field scoring)
  - Accepts a flat array of `['term', 'required', 'excluded']` entries
  - Multi-word phrases are wrapped in double quotes; `+`/`-` prefixes applied without modifying term content
- `htax_build_term_filter_clauses( array $filter_term_ids, array $filter_group_term_sets, array $filter_out_term_ids ): array` - Build SQL AND clauses for post-by-term filtering
  - All clauses use `pt.post_id IN (SELECT post_id FROM wp_htax_search_terms WHERE ...)` subqueries; assumes query uses `wp_htax_post_text AS pt`
  - `$filter_term_ids`: AND across all IDs — each gets its own subquery
  - `$filter_group_term_sets`: array of arrays — AND across groups, OR within each group
  - `$filter_out_term_ids`: NOT IN exclusion — single subquery
  - Returns empty array when all inputs are empty
- `htax_get_hidden_term_filter_clause()` - Get SQL WHERE clause for filtering hidden terms
  - Returns empty string if user can see hidden terms
  - Returns `AND is_hidden = 0` clause otherwise
  - Ensures consistent permission-based filtering across all queries

**Search Response Formatting:**
- `htax_format_search_result_post()` - Format post object for search result display
  - Standardized formatting for AJAX responses across PowerSearch and widget searches
  - Returns array with: id, title, excerpt, permalink, thumbnail, date, modified, author, comment_count
  - Accepts optional image_size parameter (default: 'thumbnail')
- `htax_build_pagination_response()` - Build pagination response structure
  - Creates consistent response arrays with pagination metadata
  - Returns array with: posts, found_posts, results_label (translated, pluralized "N results found"), max_pages, current_page, notices
  - Used across all search AJAX endpoints
- `htax_process_post_placeholders( string $text, WP_Post $post ): string` - Replace `{placeholder}` tokens in a template string with post data
  - Tokens: `{id}`, `{title}`, `{url}`, `{date}`, `{modified}`, `{author}`, `{excerpt}`, `{excerpt_text}`, `{comment_count}`, `{post_type}`, `{slug}`, `{image_src}`, `{image_alt}`, `{image_width}`, `{image_height}`, `{thumbnail}`, `{thumbnail:size}`
  - `{thumbnail}` / `{thumbnail:size}` — full `<img>` HTML from `get_the_post_thumbnail()` at the specified WP registered size (default `medium`); multiple distinct sizes in the same template each resolve independently
  - Expensive tokens (author meta, excerpt, featured image, thumbnail) are lazy-evaluated via `strpos` / `preg_match` check
  - Output is passed through `wp_kses_post()`
  - Used by `[ht_post_list]`, `[ht_related_posts]`, and `[ht_search_results]` for `prepend`/`append` attributes and the `meta` template attribute
