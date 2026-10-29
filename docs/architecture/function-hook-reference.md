---
title: Function & Hook Reference
parent: Architecture
nav_order: 2
---

# Function & Hook Reference

## Function Organization

### Plugin Configuration & Lifecycle

**Core (includes/core/functions.php)**

Public API + plugin lifecycle functions. Does not contain internal utilities — those live in helpers.php.

- `htax_health_check()` - Plugin health diagnostics
- `htax_load_textdomain()` - Load translations
- `htax_add_settings_link()` - Add settings link to plugins page
- `htax_add_plugin_meta_links()` - Add meta links to plugin row
- `htax_check_version()` - Version comparison for upgrades
- `htax_upgrade()` - Run upgrade routines
- `htax_is_taxonomy_enabled()` - Check if taxonomy is enabled in settings AND currently registered via `taxonomy_exists()`; returns false for unregistered taxonomies even if the setting says enabled, suspending HyperTaxonomy features without losing stored data
- `htax_get_available_taxonomies()` - Get all public taxonomies usable with HyperTaxonomy
- `htax_get_taxonomy_info()` - Get taxonomy object with metadata
- `htax_get_enabled_taxonomies()` - Get list of enabled taxonomy slugs; filters out any slugs that are no longer registered (`taxonomy_exists()` check), so callers always receive a clean list safe to pass to WordPress taxonomy functions
- `htax_get_indexed_post_types()` - Get post types included in the search index (default: `['post', 'page']`)
- `htax_get_taxonomy_features()` - Get feature configuration for a taxonomy
- `htax_taxonomy_has_feature()` - Check if specific feature is enabled for taxonomy
- `htax_get_cache_duration($type)` - Get cache duration from settings; types: `search` (default 30 min), `autocomplete` (default 5 min), `relationship` (default 0 = never expire, explicit invalidation), `sticky` (default 0), `shortcode` (default 0)
- `htax_generate_transient_key()` - Safe transient key generation
- `htax_debug_info()` - Display debug information on archive pages
- `htax_get_group_type($taxonomy)` - Get Term Groups group type suffix for taxonomy
- `htax_get_current_taxonomy()` - Get current taxonomy from screen or request

**Compatibility (includes/core/compatibility.php)**
- `htax_check_compatibility()` - Verify environment requirements
- `htax_display_compatibility_errors()` - Show admin notices for issues
- `htax_check_file_integrity()` - Verify all required files exist
- `htax_display_file_integrity_errors()` - Show missing file notices
- `htax_can_load()` - Master check before loading plugin

**Activation (includes/core/activation.php)**
- `htax_activate()` - Main activation handler
- `htax_activate_network()` - Network-wide activation
- `htax_activate_single_site()` - Single site activation; runs repair scans (`htax_repair_visibility_cascade`, `htax_repair_orphaned_term_relationships`, `htax_repair_orphaned_sticky_meta`), then calls `HyperTaxonomy_Activator::schedule_index_rebuild()` to schedule the full index rebuild after repairs complete, registers taxonomies, flushes rewrite rules, creates DB tables
- `htax_new_site_created()` - New multisite site handler
- `htax_deactivate()` - Main deactivation handler
- `htax_deactivate_network()` - Network-wide deactivation
- `htax_deactivate_single_site()` - Clears all transients and removes capabilities; data and tables are preserved
- `htax_add_security_headers()` - Add security headers

**Activator Class (includes/classes/class-activator.php)**
- `HyperTaxonomy_Activator::activate()` - Creates DB tables, adds indexes, sets version option
- `HyperTaxonomy_Activator::schedule_index_rebuild()` - Schedules `htax_rebuild_indexes` cron event 30 seconds in the future; idempotent (skips if already scheduled); called from `htax_activate_single_site()` after repair scans complete so the rebuild starts from clean data. The rebuild repopulates both `wp_htax_search_terms` and `wp_htax_post_text` — `wp_htax_post_text` is truncated and rebuilt first so that strength scores in `wp_htax_search_terms` are derived from fresh post content; `wp_htax_search_terms` is cleared by `clear_search_terms_for_rebuild()`, which keeps external strength models' scores for posts that will be reindexed (see Database Schema → `model`). All rebuild paths (`rebuild_indexes()`, `rebuild_post_text_table()`, `ajax_bulk_rebuild()`) select posts with the private `get_indexable_post_ids()`, which reads `wp_posts` directly: going through `WP_Query` would run `pre_get_posts` handlers, including HyperTaxonomy's own hidden-term exclusion, which applies in WP Cron (no privileged user) and would otherwise drop posts with hidden terms from cron rebuilds.

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
- `htax_get_redirect_target()` validates the target's taxonomy at resolution time. For an **unregistered** target taxonomy it returns false without overwriting the cache, so the redirect resumes automatically the next request after the taxonomy is re-registered. For an **disabled in settings** (registered but off in settings) target taxonomy it caches `'none'`; `on_taxonomy_enabled_state_changed` clears all caches on re-enable so the redirect recovers then too.
- `on_taxonomy_enabled_state_changed()` calls `htax_clear_all_caches()` whenever any taxonomy transitions between enabled and disabled, giving a clean slate for the intentional-disable path.

**Search index cleanup on settings save:**
- `HyperTaxonomy_Search_Index_Manager::on_taxonomy_enabled_state_changed()` hooks `update_option_htax_settings` and compares old vs new `enabled_taxonomies`. Taxonomies that transition to disabled have their `wp_htax_search_terms` rows deleted and all transient caches cleared. Taxonomies that transition to enabled have affected posts queued for reindex. Unregistered taxonomies cannot be toggled from the UI, so this path is not the primary cleanup mechanism for plugin deactivation — it covers intentional admin disables.
- `HyperTaxonomy_Search_Index_Manager::on_relevance_weights_changed()` also hooks `update_option_htax_settings`. For each enabled taxonomy it compares the effective `hierarchy`, `container`, and `redirect` explicit weights between old and new settings and, when any differ, queues every published post with terms in that taxonomy for reindex (those weights are multiplied into stored indirect-row strengths). Both handlers collect posts through the private `get_published_post_ids_in_taxonomy()`.

**Resumption:**
No action required after the third-party plugin is reactivated. `htax_is_taxonomy_enabled()` and `htax_get_enabled_taxonomies()` will return the correct results on the first request after the taxonomy is registered again. Stale index rows are cleaned up incrementally; a manual rebuild via Settings → Search Index produces a fully clean state immediately.

### Term Groups

**Membership CRUD (includes/core/group-functions.php)**

Membership CRUD + group taxonomy queries. Handles assigning terms to groups and querying the group taxonomy.

- `htax_get_term_groups()` - Get groups assigned to a term as WP_Term[] ordered by name (cached as `htax_groups_{term_id}`, eager reload)
- `htax_get_term_group_ids( int $term_id ): int[]` - Get IDs of groups a term belongs to; shares the `htax_groups_{term_id}` transient cache with `htax_get_term_groups()`
- `htax_get_group_hierarchy()` - Expand parent groups to include all descendants; uses `_get_term_hierarchy('group')` (WP object cache) for BFS — no extra DB query after first call per request
- `htax_set_term_groups()` - Replace all group assignments for a term
- `htax_set_group_term_count()` - Recount a group's direct members for a taxonomy and store it in `_htax_count_{taxonomy}` term meta; recounts on every call so bulk membership changes in one request stay accurate
- `htax_get_all_term_groups()` - Get all Term Groups as group_id => WP_Term map

**Groups-as-inclusion-sources (includes/core/term-groups.php)**

Groups-as-inclusion-sources feature. Handles group-based inclusion and container relationships for term archives.

- `htax_get_terms_in_groups()` - Get all terms from specified groups (with hierarchy expansion + per-group caching)
- `htax_get_terms_from_included_groups()` - Get WP_Term[] from a term's included groups
- `htax_validate_and_filter_group_relationships()` - Validate group relationships (hierarchy conflict detection)

**Term Groups admin savers (includes/admin/term-edit.php)**
- `htax_add_term_groups_field()` - Term Groups assignment field (checklist). On the add form (priority 8) it renders above the collapsible toggle so it is always visible; on the edit form (priority 8) JS moves it before the native Description row. Not registered for `group` taxonomy. Returns early when `group_assignment` is disabled or no groups exist.
- `htax_save_term_groups( int $term_id )` - Save Term Groups assignments from the checklist field. Hooked on `edited_term` (priority 10) and `create_term` (priority 4), so it runs before `htax_save_term_data()` validates relationships. Admin-only (term-edit.php). Ignores requests without `htax_groups` in `$_POST`.

**Add/Edit form collapsibles (includes/admin/term-edit.php)**
- `htax_add_form_collapsible_start()` - Priority 9 on `{tax}_add_form_fields`. Outputs the "More options ▼" toggle button and opens the collapsible `<div hidden>`. JS moves the native Description `<textarea>` row into the collapsible as its first child. Not registered for `group` taxonomy.
- `htax_add_form_collapsible_end()` - Priority 19 on `{tax}_add_form_fields`. Closes the collapsible and outer wrapper divs.
- `htax_add_edit_form_collapsible_start()` - Priority 9 on `{tax}_edit_form_fields`. Outputs the toggle as a `<tr><th colspan="2">` row; JS hides all `<tr>` siblings until the end-marker row and moves the native Description row into the collapsible zone.
- `htax_add_edit_form_collapsible_end()` - Priority 19 on `{tax}_edit_form_fields`. Outputs the empty end-marker `<tr class="htax-edit-form-collapse-end">` that bounds the collapsible range.

**Attribution helpers (includes/admin/term-edit.php)**
- `htax_build_container_group_attribution( array $group_ids, string $taxonomy ): array` - Queries G→T `includes` rows where `source_term_id IN ($group_ids)` and `source_taxonomy='group'`; returns `term_id => [group_id, ...]` map. Used for the includes-panel reverse path: terms that appear in this term's archive because they are targeted by a group this term is a member of.
- `htax_build_included_group_attribution( array $group_ids, string $taxonomy ): array` - Mirror query: T→G `includes` rows where `target_term_id IN ($group_ids)` and `target_taxonomy='group'`; returns `term_id => [group_id, ...]` map. Used for the containers-panel reverse path: terms whose archives include this term because they include a group this term belongs to.

### Relationship Type Registry (includes/core/relationship-types.php)

Manages the in-memory registry of built-in and user-defined relationship types. Built-in types are registered on `init` at priority 0; user-defined types are loaded from `wp_options` on `init` at priority 5. All query sites use `htax_relationship_type_sql_in()` to expand a core type slug into an `IN (...)` SQL fragment that covers both the built-in slug and all user-defined slugs mapped to it.

**Two new fields** on each `htax_custom_relationship_types` entry (absent keys default to empty/false — no migration needed):
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
- `htax_add_term_relationship( int $source, int $target, string $type_slug ): true|WP_Error` - INSERT IGNORE a relationship row; valid for `'includes'`, `'excludes'`, and all non-core types; `'redirect'` and `'member_of'` trigger `_doing_it_wrong()`. Validates term existence; enforces group-aware taxonomy constraints (group+group rejected; group side is exempt from source/target taxonomy restrictions; includes-mapped types require both non-group sides to be in the same taxonomy; includes-mapped types reject self-inclusions). Invalidates per-term caches, fires type-specific change actions (`htax_includes_changed`/`htax_containers_changed` for `'includes'`, `htax_excludes_changed` for `'excludes'`), and fires `htax_relationship_saved`.
- `htax_remove_term_relationship( int $source, int $target, string $type_slug ): bool` - DELETE a relationship row; same type restrictions as `htax_add_term_relationship()`; fires type-specific change actions and `htax_relationship_deleted` on success.

**Initialization:**
- `htax_register_builtin_relationship_types(): void` - Register built-in types in order: `member_of → includes → excludes → redirect`; fires `htax_relationship_types` action for code-based registration. Registration order determines the display sequence in the Built-in Relationship Types table on the Term Relationship Types admin page; all runtime lookups are slug-keyed and unaffected by order.
- `htax_load_custom_relationship_types(): void` - Load stored user-defined types from `wp_options` into the registry (init priority 5)

### Term Relationships (includes/core/term-relationships.php)

**Low-level readers — direct DB / cache wrappers:**
- `htax_get_direct_rel_term_ids( int $term_id, string $slug )` - Get direct forward target IDs for a relationship type: raw T→U rows for the given slug, non-group targets only (cached as `htax_direct_rel_{slug}_{term_id}`). Valid for `'includes'`, `'excludes'`, and non-core types. Calling with `'member_of'` or `'redirect'` triggers `_doing_it_wrong()` and returns `[]` — use `htax_get_term_groups()` or `htax_get_redirect()` instead.
- `htax_get_reverse_rel_term_ids( int $term_id, string $slug )` - Get direct reverse source IDs for a relationship type: raw A→T rows for the given slug, non-group sources only (cached as `htax_reverse_rel_{slug}_{term_id}`). Same slug restrictions as `htax_get_direct_rel_term_ids`.
- `htax_get_direct_rel_group_ids( int $term_id, string $slug )` - Get direct forward group target IDs for a relationship type: raw T→G rows for the given slug, group targets only (cached as `htax_direct_rel_grp_{slug}_{term_id}`). Same slug restrictions as `htax_get_direct_rel_term_ids`.
- `htax_get_reverse_rel_group_ids( int $term_id, string $slug )` - Get direct reverse group source IDs for a relationship type: raw G→T rows for the given slug, group sources only (cached as `htax_reverse_rel_grp_{slug}_{term_id}`). Same slug restrictions as above.
- `htax_get_included_term_ids( int|WP_Term $term, bool $include_redirect_data = false )` - Get IDs for the second OR clause of `htax_build_expanded_tax_query`: included terms plus all their descendants. Included terms' children are pre-expanded here because that clause uses `include_children => false`. The primary term's own children are **not** pre-expanded here — they are handled by the first OR clause (which uses `include_children => is_taxonomy_hierarchical`). Only called from `htax_build_expanded_tax_query`.

**Resolved readers — multi-path, WP_Term objects:**
- `htax_get_included_terms( int|WP_Term $term, bool $include_redirect_data = false, bool $include_disabled = false )` - Returns empty when `term_relationships` is disabled for the taxonomy unless `$include_disabled` (search index callers only); the cached set is feature-independent. Get WP_Term[] of included terms indexed by term_id (cached as `htax_inc_{term_id}`, eager reload). No TTL — persists until explicitly deleted by `htax_delete_term_transients()`. `false` = cold (never computed or evicted); `[]` = warm with no relationships. Empty result is stored as `[]` so that a warm cache with no inclusions never triggers a DB query.
- `htax_get_container_terms( int|WP_Term $term, bool $include_disabled = false )` - Same `term_relationships` gating and `$include_disabled` opt-out (search index callers). Get WP_Term[] of container terms indexed by term_id (cached as `htax_cont_{term_id}`, lazy-load; 3 paths: direct, container groups, group inclusion). No TTL — persists until explicitly deleted by `htax_delete_term_transients()`. Same `false` vs `[]` sentinel convention as `htax_inc_{term_id}`.
- `htax_get_synonym_terms( int|WP_Term $term )` - Get WP_Term[] of terms with a bidirectional `includes` relationship (A→B and B→A). Display utility — not required for archive expansion. Two-tier cache: static per-request array + persistent transient `htax_syno_{term_id}` (TTL=0). Invalidated by `htax_delete_term_transients()` or `htax_delete_term_transients_batch()`; re-warmed asynchronously via the `htax_async_rebuild_term_caches` cron event.
- `htax_get_excluded_term_ids( int $term_id, bool $include_redirect_data = false ): int[]` - Get excluded term IDs (cached as `htax_excl_{term_id}`); returns `[]` for redirect terms unless `$include_redirect_data` is true

**Redirect suppression pattern:**
All logical relationship getters suppress their results when the source term is a redirect and the taxonomy has the `redirects` feature enabled. Redirect terms preserve their stored rows in the DB and the cache, but callers see an empty result by default. Admin display passes `$include_redirect_data = true` to show the data grayed-out. The save handler (`htax_save_term_relationships`) skips processing for redirect terms to prevent the disabled form fields from wiping preserved data.

Functions that accept `$include_redirect_data`:
- `htax_get_included_terms( $term, bool $include_redirect_data = false, bool $include_disabled = false )`
- `htax_get_included_term_ids( $term, bool $include_redirect_data = false )`
- `htax_get_synonym_terms( $term, bool $include_redirect_data = false )` — also filters redirect terms out of the result set
- `htax_get_excluded_term_ids( int $term_id, bool $include_redirect_data = false )`
- `htax_get_terms_from_included_groups( int $term_id, string $taxonomy, bool $include_redirect_data = false )`
- `htax_get_related_terms( int $term_id, string $type_slug, bool $include_redirect_data = false )` — non-core custom types only; returns `[]` for built-in slugs
- `htax_get_reverse_related_terms( int $term_id, string $type_slug )` — non-core custom types only; returns `[]` for built-in slugs
- `htax_get_sticky_posts_for_term( int $term_id, bool $include_redirect_data = false )`

**Redirects:**
- `htax_get_redirect_target( $term_id )` - Get redirect target as WP_Term|false; applies two taxonomy guards: (1) if the target taxonomy is **disabled in settings** (registered but off in settings), caches `'none'` — safe because `on_taxonomy_enabled_state_changed` calls `htax_clear_all_caches()` on re-enable; (2) if the target taxonomy is **unregistered**, returns false without touching the cache, so the cached WP_Term (or the DB row) remains intact and the redirect resumes automatically when the plugin re-registers the taxonomy
- `htax_is_redirect( $term_id )` - Check if term is redirect (returns true regardless of feature flag — use in combination with `htax_taxonomy_has_feature($taxonomy, 'redirects')` when suppression semantics are needed)
- `htax_get_all_redirect_term_ids(): int[]` - Bulk-fetch all source term IDs with a redirect row; cached per request (`htax_request_cache( 'redirect_term_ids' )`) and as the `htax_redirect_terms` transient, both cleared by `htax_clear_redirect_term_ids_cache()` on `htax_redirect_changed` and `htax_redirect_removed` (and by `htax_clear_relationship_caches()` / `htax_clear_all_caches()`)
- `htax_clear_redirect_target_cache( int $term_id = 0 )` - Delete a term's `htax_redir_` transient and its per-request copy (0 = every term's per-request copy); `htax_set_redirect()` / `htax_remove_redirect()` call it before firing their actions so index handlers see the new state
- `htax_clear_redirect_term_ids_cache()` - Clear the `htax_redirect_terms` transient and its per-request copy
- `htax_get_ids_of_redirects_to( $term_id, $taxonomies )` - Get terms redirecting to target (cross-taxonomy)
- `htax_prewarm_redirect_cache()` - Bulk-fetch all redirect rows in one query, then batch-fetch target term objects by taxonomy, and populate `htax_redir_{id}` transients. Call before bulk index loops to convert O(n) lazy queries into 1 + 1-per-taxonomy queries.
- `htax_set_redirect( int|WP_Term $term, int|WP_Term $target )` - Set redirect with circular detection and chain resolution
- `htax_remove_redirect( $term_id )` - Remove redirect from term
- `htax_save_redirect( $term_id, $tt_id )` - Save redirect settings (called by orchestrator)

**Write operations:**

**Save orchestration:**
- `htax_save_term_data( $term_id, $tt_id )` - Orchestrator that validates nonce once, checks permissions conditionally based on enabled features and form data, then calls all save functions (runs on both `edited_{$taxonomy}` at priority 20 and `create_{$taxonomy}` at priority 5 for early AJAX compatibility)
- `htax_save_term_relationships( $term_id, $tt_id )` - Save include/container relationships (called by orchestrator, uses dual nonce verification)
- `htax_save_group_relationships( $term_id, $taxonomy, $direction, $type_slug, $raw_value )` - Save container or included group memberships for a term; called from within `htax_save_term_relationships()` for both directions and for each visible custom type
- `htax_save_archive_settings( $term_id )` - Save archive display settings; skips when customize_archives disabled (called by orchestrator)
- `HyperTaxonomy_Term_Icons::save_term_icon( $term_id )` - Save term icon if enabled for taxonomy (called by orchestrator)

**Cleanup:**
- `htax_cleanup_deleted_term( $term_id, $tt_id )` - On `delete_term`: removes all relationship rows where the term is source or target; clears `_htax_auto_redirect` meta from any terms that were redirecting to this term; clears `_htax_show_description` and `_htax_is_hidden` meta from the deleted term; deletes the `_htax_sticky_{term_id}` post meta entry and its transient
- `htax_cleanup_bulk_deleted_terms( $tt_id )` - Clean up bulk deletions (auto-detects taxonomy from tt_id)
- `htax_cleanup_hierarchy_conflicts( $term_id, $tt_id, $taxonomy )` - Remove conflicts when hierarchy changes
- `htax_remove_duplicate_redirects(): int` - Removes redirect rows where a source term has more than one target (schema allows it; write-time code prevents it, but direct DB manipulation could create this state); keeps the row with the lowest `target_term_id` (deterministic tiebreaker) and deletes the rest; idempotent
- `htax_cleanup_symmetric_relationship_duplicates(): int` - For each symmetric custom relationship type, finds A→B + B→A duplicate row pairs and deletes the one where `source_term_id > target_term_id`, keeping a single canonical row per pair; called by `htax_repair_orphaned_term_relationships()` and on activation; idempotent
- `htax_repair_orphaned_term_relationships(): int` - Activation-time scan; (1) removes rows whose source term no longer exists, (2) removes rows whose target term no longer exists, (3) removes stale `_htax_auto_redirect` meta from terms with no redirect row, (4) calls `htax_remove_invalid_term_relationships()` for all four core types and every user-defined type enforcing taxonomy constraints and self-relation rules, (5) calls `htax_remove_duplicate_redirects()` to enforce the one-target-per-source invariant, (6) calls `htax_cleanup_symmetric_relationship_duplicates()` for all symmetric custom types; returns total rows deleted; idempotent

**Symmetric custom relationship types:**
User-defined types with `symmetric: true` have identical forward and inverse labels (e.g. "Related"). The term edit UI collapses both direction containers into one merged container: reverse pills (rows where another term points to this one) are shown with a dashed border and a `←` arrow after the label; forward pills (rows created from this term's edit page) are shown with a `→` arrow after the label. Adding via `+` always creates a forward row; removing either direction deletes the appropriate row. The save handler strips any forward-direction term IDs that already appear in the reverse direction, preventing A→B + B→A duplicates at write time. The autocomplete excludes all pills (both directions) so users cannot add a term already related in either direction.

### Term Links Functions (includes/core/term-links.php)

Owned by TermConnect. See the TermConnect documentation.

### Term Visibility (includes/core/term-visibility.php)

Hidden term logic: permission checks, write-time cascade, and frontend query filtering. Applies to all taxonomies including `group` when the `visibility` feature is enabled for it (Settings → Features → Term Groups → Enable Term Group visibility options).

**Read / permission checks:**
- `htax_is_term_hidden( $term_id )` - Returns true if the term has `_htax_is_hidden` meta set
- `htax_can_see_hidden_terms( $taxonomy = '' )` - Returns true if the current user holds `manage_term_visibility` capability or meets the site-wide role threshold (editors / all / none)
- `htax_get_all_hidden_term_ids()` - Returns all hidden term IDs from the DB; cached via persistent transient; invalidated on any visibility change
- `htax_get_visible_term_ids( $term_ids )` - Filters an array of term IDs, removing hidden ones for unprivileged users
- `htax_get_visible_wp_descendants( int $term_id, string $taxonomy )` - Returns WP descendants of a term excluding those marked hidden; relies on write-time cascade for completeness
**Cascade writes:**
- `htax_set_descendant_visibility( int $term_id, string $taxonomy, bool $hide )` - Marks all WP descendants hidden or visible; fires `htax_term_visibility_changed` per changed descendant
**Hook callbacks:**
- `htax_cascade_hidden_on_reparent( int $term_id, int $tt_id, string $taxonomy )` - `edited_term` handler (priority 21); cascades hidden to a term and its descendants when reparented under a hidden parent
- `htax_cascade_hidden_on_create( int $term_id, int $tt_id, string $taxonomy )` - `created_term` handler (priority 21); marks a newly created term hidden when its parent is hidden
- `htax_repair_visibility_cascade(): int` - Activation-time scan; re-cascades hidden status to any descendants of hidden terms that drifted out of sync while the plugin was inactive; returns count of corrected terms
- `htax_hide_terms_in_tax_query( WP_Query $query )` - `parse_tax_query` hook (runs after WordPress converts every taxonomy query var — `tag`, `cat`, `tag__in`, `taxonomy`/`term`, `tax_query`, REST filters — into tax clauses); for users who cannot see hidden terms, removes hidden terms from every positive (`IN`/`AND`) clause via `htax_strip_hidden_terms_from_tax_clauses()`, so hidden terms behave as if they did not exist: a clause left empty or an `AND` requiring a hidden term matches nothing, `include_children` expands to visible descendants only, `NOT IN`/`EXISTS` clauses are untouched. Posts carrying hidden terms are never excluded — they stay discoverable through their other terms and in unfiltered lists. The sticky fetch (`post__in`, no tax clauses) is unaffected, so the `htax_sticky_*` cache is viewer-independent
**Admin save:**
- `htax_save_hidden_term( $term_id )` - Save term visibility setting (called by orchestrator)

### Archive Expansion

**Term archives (includes/core/term-archives.php)**
- `htax_get_enabled_archive_taxonomy()` - Returns the current archive's taxonomy slug if HyperTaxonomy is enabled for it; null otherwise. Used as a guard by the sort-order block and archive query handlers.
- `htax_build_expanded_tax_query( int $term_id, string $taxonomy, ?WP_Term $term_obj = null )` - Build OR tax_query for a term's archive expansion; returns null if no HyperTaxonomy contributions or if `term_relationships` feature is not enabled for the taxonomy. Called by both `htax_modify_archive_query` and `htax_expand_secondary_query`.
- `htax_modify_archive_query()` - Modify main archive query for inclusions/redirects (delegates expansion to `htax_build_expanded_tax_query`). When an expanded `tax_query` is applied, WordPress's built-in per-taxonomy query vars (`tag_id`, `cat`, `category_name`, `tag`, `term`) are cleared to prevent WP from constructing a redundant native clause. The `taxonomy` query var is intentionally preserved so that `is_tax()`, breadcrumb plugins, and SEO plugins continue to identify the archive context correctly.
- `htax_handle_redirects()` - Handle 301 redirects for terms
- `htax_get_contributing_terms_for_current_page()` - Get terms contributing to archive
- `htax_get_sticky_term_from_tax_query( WP_Query $query )` - Extract a single sticky-eligible term from a REST `tax_query`; returns `['term_id', 'taxonomy']` when the query has exactly one `IN`/`AND` inclusion entry with one term ID in a `customize_archives`-enabled taxonomy, null otherwise
- `htax_modify_archive_query_for_stickies()` - Add sticky posts to archive query; handles both regular term archives (main query, via `get_queried_object()`) and REST API requests (via `htax_get_sticky_term_from_tax_query()`); stores state on the query object itself via `$query->set()` so it works for non-global REST queries
- `htax_get_sticky_posts_for_term()` - Get sticky post objects for a term (taxonomy derived from the term). The `htax_sticky_{term_id}` cache holds only the validated post IDs — an empty list is cached too — and posts are loaded fresh on each call (all indexed post types)
- `htax_invalidate_sticky_cache_on_meta( $meta_id, $object_id, $meta_key )` - `added_post_meta` / `updated_post_meta` / `deleted_post_meta`: clears `htax_sticky_{term_id}` when any `_htax_sticky_{term_id}` key is written, by any writer
- `htax_invalidate_sticky_cache_for_post( int $post_id )` - clears the sticky cache of every term the post is pinned to; hooked to `transition_post_status` (status actually changed), `set_object_terms`, and `deleted_term_relationships`; filters by `post_status = 'publish'`
- `htax_prepend_sticky_posts()` - Prepend sticky posts to query results; handles both main query archives and REST API requests; also recomputes `max_num_pages` from the full page size (WP derives it from the reduced page-1 `posts_per_page`)
- `htax_sticky_found_posts( $found_posts, $query )` - `found_posts` filter: adds the pinned count (`htax_sticky_count` query var) back, since pinned posts are excluded from the regular query on every page. With pinning, page 1 queries `posts_per_page − N` regular posts and pages 2+ use `offset = (paged − 1) × posts_per_page − N`, so no regular post is skipped; stickies beyond the max-sticky cap are not pinned and stay in their natural position
- `htax_handle_group_archives()` - `pre_get_posts` handler for group taxonomy archives; builds an OR tax_query via `htax_build_group_tax_query()` and applies it to the main query
- `htax_cleanup_sticky_on_delete( $post_id )` - On `delete_post`: removes all `_htax_sticky_{term_id}` post meta entries for the deleted post
- `htax_repair_orphaned_sticky_meta(): int` - Activation-time scan; finds all `_htax_sticky_*` post meta keys and deletes any whose term no longer exists; idempotent

**Integration API (includes/core/integration-api.php)**

Public integration API for third-party plugins.

- `htax_get_expanded_term_ids( int $term_id )` - Returns the complete flat list of term IDs whose posts appear in a term's archive: self + WP descendants (via `htax_get_visible_wp_descendants`) + all HyperTaxonomy-contributed terms from `htax_build_expanded_tax_query` (inclusions pre-expanded to include their own WP descendants). WP descendants of the primary term are always added explicitly regardless of whether HyperTaxonomy contributions are present. Taxonomy is looked up automatically. Applies `htax_expanded_term_ids` filter.
- `htax_expand_secondary_query( WP_Query $query )` - `pre_get_posts` handler (priority 10) that expands single-term tax_query clauses for enabled taxonomies in secondary/third-party queries. Controlled by `htax_expand_terms` query var or `expand_secondary_queries` global setting. Skips the main archive query.
- `htax_adjust_term_counts( array $terms, ... )` - `get_terms` filter. Replaces native WP counts with expansion-aware counts. Frontend only, active only when `expand_secondary_queries` is on.

### Frontend Display (includes/core/frontend.php)

**Hidden term filters:**
- `htax_filter_term_links()` - Remove hidden terms from WordPress's native `term_links-{taxonomy}` HTML link lists
- `htax_filter_get_the_terms()` - Mark hidden terms in arrays
- `htax_filter_tag_cloud()` - Remove hidden terms from tag cloud

**Archive description:**
- `htax_filter_title()` - Add contributing terms to archive title
- `htax_display_archive_description()` - Display term description
- `htax_get_allowed_description_html()` - Define allowed HTML tags
- `htax_integrate_description_display()` - Set up description hooks
- `htax_maybe_display_archive_description()` - Conditional display
- `htax_filter_query_title_block()` - Filter FSE Query Title block

**Styles:**
- `htax_frontend_styles()` - Output frontend CSS
- `htax_icon_border_radius_styles()` - Dynamic icon border radius CSS
- `htax_filter_post_class()` - Add sticky post CSS classes

**Icon / pill rendering:**
- `htax_get_the_archive_icon( string $size, array $attr )` - Get current term archive icon as responsive `<img>` with srcset; empty string on non-term archives or missing icon
- `htax_the_archive_icon( string $size, array $attr )` - Echo wrapper for `htax_get_the_archive_icon()`
- `htax_get_term_icon_url( WP_Term|int $term, string $size )` - Get term icon URL (accepts WP_Term object or term ID)
- `htax_get_term_icon_html( WP_Term|int $term, string $size, array $attr )` - Get term icon HTML (accepts WP_Term object or term ID)
- `htax_get_icon_display_for_taxonomy( string $taxonomy )` - Get icon display setting for taxonomy
- `htax_get_term_pill( WP_Term $term, array $args )` - Get term as a styled pill with optional icon
- `htax_the_term_pills( int $post_id, string $taxonomy, array $args )` - Display all post terms as styled pills
- `htax_add_icon_to_term_link( string $term_link, WP_Term $term, string $show_icons, bool $is_hidden )` - Transform existing term link into pill

**Text utilities:**
- `htax_split_description()` - Split description into fragments
- `htax_truncate_text()` - Truncate text with ellipsis

- `htax_suppress_native_archive_description( string $description ): string` - `get_the_archive_description` filter at priority 1. Returns empty string when `_htax_show_description = '1'`, preventing a theme's `the_archive_description()` call from duplicating the description that `htax_display_archive_description()` already outputs. WordPress skips the wrapper div entirely on empty return.

### Search

**Query Intercept (includes/core/search.php)**
- `htax_handle_widget_post_search( WP_Query $query )` - `pre_get_posts` callback; runs a search widget POST-mode search (detected via `$_POST['htax_post_search']`, normally restored from a results-page token — see Search State below); verifies the nonce unless the state came from a token, forces `is_search`, runs `get_posts_by_custom_sql()` for the requested page with the form's `htax_posts_per_page` / `htax_max_results`, and applies the page with `htax_apply_search_page_to_query()`
- `htax_handle_power_post_search( WP_Query $query )` - `pre_get_posts` callback; runs a PowerSearch POST-mode search (detected via `$_POST['htax_power_post_search']`); verifies the nonce unless the state came from a token, validates minimum query length, builds config from the form's hidden inputs (`posts_per_page`, `max_results`, `match`, `near_country`, `search_in`, `orderby`), runs `perform_indexed_text_search()` for the requested page and applies it with `htax_apply_search_page_to_query()`

**Search State — POST/Redirect/GET (includes/core/search-state.php)**

POST-mode forms submit once; the results pages are GET requests carrying `?htax_q=<token>`, so the theme's `/page/N/` links (which keep the query string), back/refresh, bookmarks and page caches work.
- `htax_redirect_search_submission()` - `init` priority 1; for a POST with `htax_post_search` / `htax_power_post_search` and a valid nonce, encodes every submitted field except nonces, `_wp_http_referer` and `*_termdata` (labels are rebuilt from term IDs) and redirects (303) to the posted URL minus `/page/N/` plus `htax_q`. An invalid nonce is left for the handlers to reject.
- `htax_restore_search_state()` - `init` priority 0; on a GET with `htax_q`, decodes the state and writes it (slashed, as WordPress slashes request data) into `$_POST` / `$_REQUEST`, so the handlers and the form-restoration code run unchanged; marks the request with `htax_is_search_state_request()`.
- `htax_search_state_encode( array $state ): string` / `htax_search_state_decode( string $token ): ?array` - `z` + base64url(deflate(JSON)) when ≤ `HTAX_SEARCH_STATE_MAX_INLINE` (1500) characters (durable, no storage); otherwise `k` + 16-hex content hash of a transient `htax_ss_{hash}` kept `HTAX_SEARCH_STATE_TTL` (30 days). Decoding caps the inflated size at 64 KB.
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
- `htax_apply_orderby_to_wp_query()` - Apply parsed orderby to WP_Query arguments
- `htax_enhance_search_query()` - Native `?s=` search through the PowerSearch engine (always, or with `htax_search`): leaves an empty `s` to WordPress; runs the requested page with the query's `posts_per_page` (`posts_per_rss` for search feeds), clears `s`, stores the text in `htax_search_text`, and applies the page with `htax_apply_search_page_to_query()` — the engine sorts by the full orderby, so the page keeps its order
- `htax_get_search_results_per_page()` - Default posts-per-page for all HT search forms; returns `get_option('posts_per_page', 20)` so that the WordPress Reading setting is the single site-wide control. Shortcode-level `posts_per_page` attributes always override this.
- `htax_get_default_match_mode()` - Returns `'all'` or `'any'` from `htax_settings[default_match_mode]`; defaults to `'any'`.

**URL Parameters:**
- `htax_search=1` - Trigger enhanced search
- `htax_search_in` - Comma-separated list: title, content, excerpt, tags, categories
- `htax_search_mode` - Search mode: phrase_first (default), phrase_only, words_any, words_all
- `htax_orderby` - Sort order format: "field:order,field:order" (e.g., "relevance:desc,date:desc")
  - Valid fields: relevance, date, modified, title, author, comment_count, last_comment, last_activity, ID
  - Valid orders: asc, desc (defaults to desc if omitted)
  - Relevance works with text searches only in enhanced search
  - `last_comment` sorts by `last_comment_gmt`; `last_activity` sorts by `GREATEST(post_modified_gmt, last_comment_gmt)` — both columns are always present because all queries use `wp_htax_post_text` as the primary post source
  - Also accepted on term archive URLs to override per-term sort order (archive-valid fields only: date, modified, title, author, comment_count); emitted by `[ht_sort_order_options action="navigate"]`
  - Example: `?s=test&htax_search=1&htax_orderby=date:desc,title:asc`

**Sort Order Picker (includes/shortcodes/class-sort-order.php)**

Shared renderer and shortcode handler for the pill-based sort order picker component. Loaded before `class-post-list.php` since post list and search result shortcodes call `htax_render_sort_order_picker()` directly.

- `htax_sort_field_labels()` - Returns the canonical `field_slug => translated label` map for all supported sort fields (`date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `relevance`, `ID`)
- `htax_enqueue_sort_order_assets()` - Enqueues `htax-sort-order` CSS and JS; safe to call multiple times (checks `wp_style_is`/`wp_script_is` before enqueuing)
- `htax_render_sort_order_picker( array $args )` - Renders the complete `[data-htax-sort-root]` HTML block: toggle button, active-pill container with add button, picker popover, hidden value input. Arguments: `fields` (array or comma-string), `state` (expanded|collapsed), `action` (navigate|submit|''), `default_value`, `input_name`, `text_collapsed`, `text_expanded`, `class`
- `htax_sort_order_options_shortcode( $atts )` - Handler for `[ht_sort_order_options]`; defaults to `action="navigate"` and auto-populates `default_value` from `$_GET['htax_orderby']` when action is navigate

**JavaScript component (assets/js/htax-sort-order.js):**
- `window.htaxSortOrder.init(root, opts)` - Initializes a picker within a root element; returns `{ getValue, setValue, destroy }`
- Auto-initializes all `[data-htax-sort-root]` elements on DOMReady
- Fires jQuery event `htax:sort-change` on the root element (bubbles) with the new serialized value as second argument
- `data-htax-sort-action="navigate|submit"` controls what happens on change; omit for event-only (admin/AJAX contexts)
- `syncAddBtn()` — hides the `+` button when all available fields are already active as pills

**sort_order_options attribute on result shortcodes:**
- `[ht_post_list sort_order_options="..."]` — renders picker as sibling of `.htax-post-list-wrapper` inside `.htax-post-list-block`; `htax-post-list.js` handles `htax:sort-change` by updating `data-orderby` and calling `loadPage($wrapper, 1)`
- `[ht_search_results sort_order_options="..."]` — renders picker inside `.htax-search-results` before the results container. Both search widget and PowerSearch store their JS instance on the container via `$results.data('htax-form-instance', this)`; the single delegated `htax:sort-change` handler in `htax-search-widget.js` reads this reference and calls `instance.performSearch(1)` regardless of which form type is in use. Pagination prev/next buttons use the same stored reference.

**Filter Options Panel (includes/shortcodes/class-filter-options.php)**

Collapsible filter panel supporting term, date range, and author filters. Works in three action modes: `navigate` (URL params on archives), `submit` (form submission), and event-only (AJAX result shortcodes). Loaded before `class-post-list.php` since post list, search widget, and PowerSearch shortcodes call its render and SQL-builder functions directly.

**Key global functions:**

- `htax_build_filter_sql( array $filter_state, array $filter_configs = [] ): string` — Builds SQL WHERE clauses from a parsed filter state array. Uses `pt` as the `wp_htax_post_text` alias (consistent across all three query contexts). Returns empty string when state is empty. Term filters use `pt.post_id IN (SELECT post_id …)` subqueries against `wp_htax_search_terms`; date filters use `pt.post_date_gmt`/`pt.post_modified_gmt`; author filter uses `pt.post_author IN (…)`.
- `htax_parse_filter_state_navigate( array $raw ): array` — Sanitizes a filter state from `$_GET['htax_filter']` URL params. Term IDs are `intval`-cast; dates are validated with `preg_match`; author IDs are `intval`-cast. Match type is always `'any'` in navigate mode.
- `htax_parse_filter_state_ajax( string $json ): array` — Parses filter state from a JSON string POSTed as `htax_filter` in AJAX requests. Same sanitization as navigate variant; preserves `term_match` keys from the client state.
- `htax_resolve_filter_configs( array $raw_configs, array $filter_state, $archive_exclude_term, array $primary_seed_ids = [] ): array` — Converts raw shortcode attribute arrays into fully resolved config arrays. Fetches term options from the DB, applies pool include/exclude rules, merges initial selection from filter state and seed IDs, and forces seeded terms into the pool. Returns one config array per filter group ready for rendering.
- `htax_render_filter_options( array $configs, array $args, array $filter_state ): string` — Renders the complete `[data-htax-filter-root]` HTML block: toggle/clear header, body with one group per config, hidden value inputs, and `data-htax-filter-state`/`data-htax-filter-initial` JSON attributes.
- `htax_parse_inline_filters( string $filters_string, string $filter_options_string = '' ): array` — Converts the `filters=`/`filter_options=` inline shortcode attributes into raw config arrays compatible with `htax_resolve_filter_configs()`. Keywords: `date_published`, `date_modified`, `author`, or any taxonomy slug. First taxonomy slug is marked `primary=true`. Position-aligned `filter_options` values: `single` for date type, `group:N` for term pool.
- `htax_build_archive_filter_mods( array $filter_state ): array` — Converts filter state into `WP_Query` modification arrays: `tax_query_additional` (merged into existing `tax_query` with `relation=AND`), `date_query`, `author__in`.
- `htax_apply_archive_filter( WP_Query $query )` — `pre_get_posts` callback at priority 11. Reads `$_GET['htax_filter']`, parses via `htax_parse_filter_state_navigate()`, builds mods via `htax_build_archive_filter_mods()`, and merges them into the main query. Only runs on the main front-end query with a `queried_object` (i.e. actual archive pages).
- `htax_enqueue_filter_options_assets()` — Enqueues `htax-filter-options` CSS and JS; safe to call multiple times.

**Shortcode handlers (registered on `init`):**
- `htax_filter_options_shortcode( $atts, $content )` — `[ht_filter_options]` parent; collects child configs captured during `do_shortcode($content)`, resolves them, and renders the panel. In archive context, seeds the primary filter's initial selection from the current term's child/parent terms.
- `htax_filter_terms_shortcode( $atts )` — `[ht_filter_terms]`; pushes a raw config onto the active capture stack.
- `htax_filter_date_published_shortcode( $atts )` — `[ht_filter_date_published]`; pushes a date config.
- `htax_filter_date_modified_shortcode( $atts )` — `[ht_filter_date_modified]`; pushes a date config.
- `htax_filter_author_shortcode( $atts )` — `[ht_filter_author]`; pushes an author config.

**JavaScript component (assets/js/htax-filter-options.js):**
- Each `[data-htax-filter-root]` element gets a `FilterPanel` instance on DOMReady.
- State is stored in `this.state` (current) and `this.initial` (initial/seed); `isDirty()` compares them to control the "Clear filters" button visibility.
- `commit( newState )` — updates state and fires `htax:filter-change` (event mode), navigates (navigate mode), or submits the ancestor form (submit mode).
- `resetToInitial()` — restores all UI controls to initial values and calls `commit(initial)`.
- Three delegated `htax:filter-change` handlers wire up to result shortcodes:
  - Post list: sets `$wrapper.data('htax-filter', newState)` then triggers `htax:load-page` on `.htax-post-list-wrapper`
  - Search widget: sets `$results.data('htax-filter', newState)` then calls `HTAXSearchWidget.forms[formId].performSearch(1)`
  - PowerSearch: sets `$results.data('htax-filter', newState)` then calls the `FulltextSearchForm` instance's `performSearch(1)`

**Filter state integration in AJAX handlers:**
- All three AJAX search handlers (`ajax_load_page` in post list, `ajax_search_posts` in search widget and PowerSearch) read `$_POST['htax_filter']`, parse via `htax_parse_filter_state_ajax()`, build SQL via `htax_build_filter_sql()`, and pass the result as `$extra_where` / `user_filter_sql` to the query layer.
- SQL alias `pt` (for `wp_htax_post_text`) is consistent across all three query contexts, so filter SQL is interchangeable.

**`filters`/`filter_options` inline attributes on result shortcodes:**
- Available on `[ht_post_list]` and `[ht_search_results]` (the unified results container used by both search widget and PowerSearch).
- When set, `htax_parse_inline_filters()` → `htax_resolve_filter_configs()` → `htax_render_filter_options()` is called at render time (always `action=""`).
- The filter panel HTML is prepended as a sibling of the results wrapper inside a block div (`.htax-post-list-block`, `.htax-search-results-block`). The JS filter-change handlers rely on this sibling relationship.
- `[ht_post_list]` also passes resolved `filter_terms` IDs as `$primary_seed_ids` so the filter panel UI matches the initial page-load query result.

**ATCF Integration (includes/integrations/class-atcf-integration.php)**

Conditionally loaded when `function_exists( 'atcf_get_term_ids_within_radius' )`. Hooks:
- `htax_pre_search_fields` — receives the full search query spec `{relation, fields, groups, text_search}` and resolves any `distance`-type fields in `$query['fields']` to `type:"hidden"` resolved fields containing matching term IDs before the SQL query runs. Prefers JS-provided coordinates (`_atcf_lat`/`_atcf_lng` request params) over postal-code centroid lookup; falls back to `atcf_get_ip_location()`. Any `expand_types` value from the distance field is passed through unchanged to the resolved field; `normalize_expand_types()` in the search widget class handles final normalization at query time.
- `htax_allowed_field_types` — adds `'distance'` to the accepted field types so the AJAX handler does not reject distance fields
- `htax_search_proximity_where` — `build_proximity_where()` turns `near:`/`-near:` tokens into a WHERE condition for PowerSearch and the search widget (see below)
- `htax_search_proximity_relevance` — `build_proximity_relevance()` scores posts by distance for `near:`-only searches (PowerSearch `$near_only` path: `proximity_score` select column, HAVING `1 = 1`; widget text-only branch). Both builders share `resolve_proximity()`, memoized per request (proximity tokens + hidden-term visibility), which resolves references once, records unresolved-clause notices once, and returns in-range term distances (`get_in_range_term_distances()`, from ATCF's `distance_km`)
- `htax_term_locations` — provides ATCF location rows to HT's filter; consumed by `htax_get_term_locations()` for REST responses
- `htax_term_locations` — `htax_filter_rest_prepare_term` reads location data from this filter directly (rather than from `rest_prepare_{$taxonomy}`)

The integration touches **no ATCF database tables directly** — all location data is retrieved via ATCF's public API (`atcf_get_locations_within_radius()`, `atcf_get_term_ids_with_locations()`, `atcf_get_postal_centroid()`, `ATCF_Locations::get_aggregated()`). The only direct DB queries HT performs are against WP's own `term_relationships` and `term_taxonomy` tables to map term IDs to post IDs.

The `[htax_distance_field]` shortcode is registered unconditionally (so the tag is always recognized in content). When ATCF is inactive at render time, the shortcode outputs a notice for admins and nothing for other users.

**JavaScript event fired by `[htax_distance_field]`:**
- `atcf:termFilterInserted` — dispatched on `document` after the distance filter element is rendered into the form. `e.detail.fieldName` is the field's `name` attribute; `e.detail.formId` is the enclosing `[ht_search_form]` id.

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
-near:...                     — negation; excludes class D posts
```

Default radius: 10, in the ATCF distance unit (`atcf_distance_units`). Country context (for postal format check and centroid lookup) is set by the `near_country` shortcode/block attribute (default `'US'`). When ATCF is inactive, `near:` text is not extracted and falls through to FULLTEXT search unchanged.

**Reference point aggregation for `near:taxonomy:term`:**

Reference locations are resolved via `ATCF_Locations::get_aggregated()`, which expands the scope to include WP taxonomy descendants (`get_term_children()`) and HT inclusion terms (`htax_get_included_term_ids()`), and excludes HT hidden terms (`htax_get_all_hidden_term_ids()`). This means `near:geo:europe` finds locations across all European child terms, not just direct ATCF locations on the "europe" term itself.

### REST API (includes/core/rest-api.php)

**Field builders:**
- `htax_rest_term_stub( WP_Term $term )` - Build minimal `{ id, name, slug, taxonomy, link }` object for REST field values
- `htax_rest_build_icon_data( WP_Term $term )` - Build `htax_icon` REST field value (null when no icon set)
- `htax_rest_build_redirect_data( WP_Term $term )` - Build `htax_redirect` REST field value (null when not a redirect)
- `htax_rest_build_includes_data( WP_Term $term )` - Build `htax_includes` REST field value (WP descendants + direct HyperTaxonomy inclusions, including inclusion-based custom types)
- `htax_rest_build_synonyms_data( WP_Term $term )` - Build `htax_synonyms` REST field value (null when no synonyms)
- `ATCF_REST_API::format_term_locations( int $term_id )` - **Owned by ATCF.** Returns `htax_locations` REST field value for a term: Schema.org Place array, or null when no locations. Caches per term_id for request lifetime. HT calls this with a `method_exists` guard; it uses `ATCF_Locations::get_by_term_id()` internally so no taxonomy parameter is needed.
- `htax_rest_build_relationships_data( WP_Term $term )` - Build `htax_relationships` REST field value; storage-only custom types only (`core_type = null`, non-private); inclusion-based types excluded (they travel via `htax_includes`); returns `[{relationship_type, target_slug, target_taxonomy}]`
- `htax_rest_build_term_entry( WP_Term $term )` - Build a minimal term stub `{id, slug, name, taxonomy, link}` used inside `htax_includes` stubs and `htax_synonyms` entries

**Term endpoint filters & registration:**
- `htax_filter_rest_terms_query()` - Exclude hidden terms from REST collection responses (always enforced)
- `htax_filter_rest_prepare_term()` - Block hidden terms with 404 (always enforced); inject sparse `htax_*` fields when data exists. Enrichment fields are also skipped when `_locale=user` is present in the request — `wp.apiFetch` always adds this param so the block editor never receives the enrichment fields (it reads HyperTaxonomy display data from PHP-localized `htaxTermMeta` instead); external REST clients, which do not send `_locale=user`, receive full enrichment. Hidden-term 404 enforcement fires unconditionally regardless of `_locale=user`.
- `htax_register_rest_term_filters()` - Hook the above two functions onto every public `show_in_rest` taxonomy
- `htax_filter_sitemap_terms_query()` - Exclude hidden term archives from XML sitemaps

**Posts endpoint extensions:**
- `htax_rest_add_version_header( WP_REST_Response $result )` - Adds `X-HyperTaxonomy: {version}` header to every REST response; used by peer sites for HyperTaxonomy detection. Conditionally registered in `hooks/filters.php` based on the **HyperTaxonomy Presence Header** Advanced setting (default on)
- `htax_rest_register_post_extensions()` - Registers `htax_*` collection params, the scoring query filter, and the `htax_score`/`htax_matched_term_slugs` REST fields on the `post` type; hooked on `rest_api_init`
- `htax_register_tag_suggestions_rest_route()` - Registers `GET /htax/v1/suggested-tags`; requires `edit_posts`; hooked on `rest_api_init`
- `htax_rest_suggested_tags( WP_REST_Request $request )` - Callback for `/htax/v1/suggested-tags`. Accepts `post_id` (required), `taxonomy` (default `post_tag`, any flat taxonomy supported), and `per_page`. Fetches the post's text from `wp_htax_post_text` (falls back to live post), applies ignore words per-field (word-boundary regex from Settings → Advanced), then counts occurrences of each unassigned term's name in title, excerpt, and body independently. Returns terms in three groups — title matches first, then excerpt-only, then body-only — each group sorted by count descending, sliced to `per_page`. Response mirrors WP REST term format so Gutenberg's tag selector can consume it directly. The Gutenberg panel registers one `PluginDocumentSettingPanel` per enabled flat taxonomy (including custom ones) and positions each panel immediately after its taxonomy section in the sidebar via `domReady` DOM manipulation.
- `htax_register_term_meta_route()` - Registers `GET /htax/v1/term-meta`; requires `edit_posts`; hooked on `rest_api_init`. Used by the JS autocomplete subscriber to fetch badge metadata for terms added mid-session.
- `htax_rest_term_meta( WP_REST_Request $request )` - Callback for `GET /htax/v1/term-meta`. Accepts `taxonomy` (required, must exist) and `ids` (required, comma-separated integers, capped at 50). Returns `{ terms: [{id, name, slug, is_redirect, is_hidden, inc_count, cont_count, included_names, container_names, redirect_target_name}] }` — only terms with at least one HT flag are included; terms with nothing to show are omitted.
- `htax_rest_register_post_htax_params( array $params, string $post_type )` - `rest_post_collection_params` filter: registers `htax_includes`, `htax_match`, `htax_weights` as accepted params on `/wp-json/wp/v2/posts`
- `htax_rest_apply_htax_post_query( array $args, WP_REST_Request $request )` - `rest_wp_post_query` filter: when `htax_includes` is present, reads primary and included term IDs, queries the search index (`relationship_type='direct'` only), scores posts, restricts the WP_Query via `post__in`, and stores normalized scores in `$_htax_rest_post_scores` for the field callbacks. The standard `?tags=`/`?categories=` params remain in effect and AND with `post__in`.
- `htax_rest_get_post_score( array $post )` - REST field callback for `htax_score`; returns 0–1 float or null
- `htax_rest_get_post_matched_slugs( array $post )` - REST field callback for `htax_matched_term_slugs`; returns string[] (remote term slugs derived from matched term IDs) or null
- `htax_term_peers` field — registered by TermConnect (when active) on post REST responses. Returns peer connection data for all terms on returned posts. See TermConnect documentation.

**`_fields` awareness.** All HT-added fields are gated by `htax_rest_field_requested( ?WP_REST_Request $request, string $field ): bool`. Returns `false` if: (a) the field is globally disabled by the Relationship Data in REST Responses setting (`htax_includes`, `htax_synonyms`), OR (b) a `_fields` parameter was supplied and does not include this field. Returns `true` when `_fields` is absent (backward compatible). Callers should specify `_fields` to avoid unused computation; TermConnect does this in all outbound requests.

**All term fields now available on peer collection responses.** Every field listed in the build-helpers above is available on both individual and peer collection responses when requested in `_fields`. Relationship-specific fields (`htax_includes`, `htax_relationships`) are additionally gated by the Peer Relationship Delegation API Advanced setting. `htax_synonyms` and `htax_includes` are gated by the Relationship Data in REST Responses setting. `htax_locations` is gated by the share_locations feature flag.

**`htax_relationships` is also available on individual term responses** (not just peer collection), gated by `_fields`.

**`htax_relationship_types_admin_footer` action.** Fires at the bottom of the relationship types admin page. TC hooks here to inject the "Discover from peer site" UI.

**Scoring:** Score = `sum(weight per matched term) / (n_primary_terms × weight_direct)`, clamped to 1.0. Primary term IDs (keys of `htax_includes`) use `weight_direct` (default 1.0); included term IDs (values of `htax_includes`) use `weight_included_direct` (default 0.7). Hidden-term rows excluded via `is_hidden = 0`. AND mode (`htax_match=all`) removes posts missing a match in any primary term group before normalization.


### External Posts Platform (includes/core/external-posts.php)

Owned by TermConnect. See the TermConnect documentation.

### Feed Platform (includes/core/feed-platform.php)

Owned by TermConnect. See the TermConnect documentation.

### External Post List Shortcodes (`includes/shortcodes/class-external-post-list.php`)

Owned by TermConnect. See the TermConnect documentation.

### Admin — Term Edit & Bulk Actions

**Term Edit (includes/admin/term-edit.php)**

**`admin/js/htax-term-edit.js` — unsaved changes guard:** On DOM-ready, captures `$('#edittag').serialize()` as the baseline form state. A `beforeunload` listener compares the current serialized state to the baseline and fires the browser's native "Leave site?" dialog (via `e.preventDefault()` + non-empty `e.returnValue`) if they differ. The listener removes itself on `form#edittag submit` so the warning is suppressed when the user intentionally clicks Save. The baseline is also reset on `htax:saved` (AJAX save, no page reload). The comparison covers all standard inputs plus the group-checklist checkboxes (`name="htax_groups[]"`) which are part of `#edittag` even when the floating dropdown is visually detached.

- `htax_enqueue_admin_assets()` - Enqueue CSS/JS and localize data for term edit screens; injects `HTAX_Ajax` with `nonce` (htax-autocomplete), `link_suggest_nonce` (htax-link-suggest), `taxonomy` (current taxonomy slug), visibility data, synonym data, and (for edit form) `group_terms` map (groupId → [{id, name}] member list) and `group_names` map (groupId → name string for tooltip rendering of dynamically created indirect pills)
- `htax_add_description_display_field()` - Archive description checkbox field
- `htax_add_hidden_term_field()` - Hidden term checkbox field
- `htax_is_add_form()` - Helper to detect if form is add vs edit (true when `$term_or_taxonomy` is a string)
- `htax_extract_term_edit_context( $term_or_taxonomy ): array` - Normalizes a term-form renderer's argument to `{taxonomy, term_id, term}` (`term_id` 0 and `term` null on the add form; the taxonomy comes from the term on the edit form). Used by the description, hidden, redirect, archive settings, term groups and relationships field renderers
- `htax_add_inclusions_tabs_field()` - Unified tabbed inclusions/relationships field for the edit form; renders core inclusion tabs (Included Terms, Container Terms) and one tab per visible custom relationship type. Render uses low-level split caches (`htax_get_direct_rel_term_ids`, `htax_get_direct_rel_group_ids`, `htax_get_reverse_rel_term_ids`, `htax_get_reverse_rel_group_ids`) so group pills and direct-term pills are separately identifiable for editable vs read-only display; the group-expanded `htax_crel_` caches are not used here. The save handler reads the same low-level split caches for diff computation.
- `htax_render_merged_pill_container( array $args ): void` - Render a single merged pill container. Named `$args` keys: `term_id`, `taxonomy`, `field_terms` (hidden input name for direct term IDs), `field_groups` (hidden input name for group IDs; omit to suppress the groups section), `direct_term_ids` (int[]), `group_ids` (int[]), `via_group_map` (term_id → [group_id, ...] indirect pills via group membership), `via_container_map` (term_id → [group_id, ...] indirect pills via container-group attribution), `custom_type_paths` (term_id → [[label, dir, slug], ...] for cross-panel attribution badges), `redirected_term_ids` (int[] — adds ↩ indicator), `excluded_ids` (int[] — suppresses indirect pills; adds `htax-overridden` strikethrough class to direct pills), `panel` ('includes'|'containers'|'custom', derived from `field_terms` if omitted), `placeholder` (search input text), `disabled` (bool), `taxonomies` (extra taxonomy slugs for cross-taxonomy relationships). Batch-loads all term and group objects via two `get_terms()` calls, and all indirect-group objects via a third call, building `$all_group_name_map` inline — group names for tooltip data are resolved entirely in PHP (not via `HTAX_Ajax.group_names`, which is only used by JS for dynamically added pills). Outputs a `.htax-tag-container.htax-merged-container` div with `data-panel`, `data-current`, `data-taxonomy`, `data-taxonomies`, `data-action` attributes containing: group section label + editable group pills + add-group button + terms section label + direct term pills (sorted A–Z, with × remove button) + add-term button + `.htax-search.htax-merged-search` input + hidden inputs + indirect section label + indirect pills (sorted A–Z, `.htax-indirect`, no remove button) + group dropdown checklist. JS-side: `isTermExcluded()` checks for an excluded pill by searching from `.htax-rel-tab-panel` (the common ancestor of the includes and excludes panels) — not from `[data-panel]` on the merged container itself, which is a descendant of the panel rather than a sibling to `.htax-exclude-panel`.
- `htax_build_via_group_map( array $group_ids, string $taxonomy ): array` - Calls `htax_get_terms_in_groups( [$gid], $taxonomy )` per group ID (uses its own per-group cache) and builds `term_id => [group_id, ...]` map. Per-group iteration preserves attribution; cache hits are cheap for groups already resolved this request.
- `htax_build_container_via_group_map( array $cont_group_ids, string $taxonomy ): array` - Expands `$cont_group_ids` upward via `htax_get_group_ancestors()` (mirrors htax_get_container_terms() Path 3), then queries `member_of` rows `WHERE target_term_id IN ($expanded)` directly; returns `term_id => [group_id, ...]`. Used for the containers panel to find terms that are members of ancestor groups of the current term's container groups.
- **Hide indirect relationships toggle:** Eye-icon `<button class="htax-via-header-btn htax-via-section-toggle">` rendered in the Term Relationships `<th>` header when any indirect pills exist for the current term. Clicking it toggles class `.htax-via-hidden` on `.htax-inclusions-field` via JS; CSS hides all `.htax-indirect` pills when that class is present. State persisted per term in `localStorage` under key `htax-via-hidden-section-{termId}`.
- `htax_add_redirect_field()` - Term redirect field
- `htax_add_archive_settings_field()` - Archive customization settings field (sticky posts, posts per page, sort order)
- `htax_handle_add_custom_relationship_from_form()` - Handle inline add-row form submission for custom relationship types (used in add form context)

**Term Edit Locking (includes/admin/term-edit-lock.php)**

Prevents concurrent editing of the same term or relationship type by multiple admins, using the same mechanism as WordPress post locking: a heartbeat-refreshed lock with a take-over flow.

**Lock storage:**
- Terms: `_htax_edit_lock` term meta — `"{timestamp}:{user_id}"`. Applies to every term edit screen (`edit-tags.php` / `term.php`) regardless of whether the taxonomy is HyperTaxonomy-enabled; covers groups, custom taxonomies, and built-in taxonomies.
- Relationship types: `htax_rt_lock_{slug}` transient — same format; TTL = `HTAX_LOCK_TIMEOUT + 30s` so transient expiry slightly outlasts the logical timeout.

**Lock timeout:** `HTAX_LOCK_TIMEOUT` = 150 seconds (matches WordPress post lock). A lock with no heartbeat refresh for 150 s is considered expired.

**Page-load flow:** On `load-edit-tags.php` / `load-term.php` (terms) or `admin_init` when `?page=htax-term-relationships&edit=slug` (RT): if the lock belongs to another non-expired user, render the blocking `#htax-term-lock-dialog` overlay with "Go Back" and "Take Over" buttons; otherwise set the lock and render the edit form normally. The take-over button is a nonce-protected URL (`?htax-take-lock=1` / `?htax-rt-take-lock=1`); the handler verifies the nonce, forcibly overwrites the lock, then redirects back to the same URL without the parameter.

**Heartbeat flow:** The client (`admin/js/htax-term-edit-lock.js`) sends `htax_term_lock_refresh` or `htax_rt_lock_refresh` on each heartbeat tick. The server handler (`htax_heartbeat_refresh_lock`) verifies the nonce, then either refreshes the lock timestamp (returns `lock_refreshed: true`) or signals displacement (returns `lock_error: true` with the new holder's name and avatar URL). On displacement the JS hides the editing section, populates the `#htax-lock-message-taken` section, and shows the dialog.

**Dialog HTML:** Always rendered in `admin_footer` via `htax_render_lock_dialog()` using WordPress's standard `.notification-dialog-wrap` CSS classes. Contains two sections: `#htax-lock-message-editing` (shown at page load when another user holds the lock) and `#htax-lock-message-taken` (always hidden at load, shown by JS on heartbeat displacement). When the current user holds the lock the entire dialog is hidden at page load.

- `htax_set_term_edit_lock( int $term_id )` - Write or refresh `_htax_edit_lock` term meta for current user
- `htax_check_term_edit_lock( int $term_id ): WP_User|false` - Return locking user or false (own lock / expired / absent)
- `htax_set_rt_edit_lock( string $slug )` - Write or refresh `htax_rt_lock_{slug}` transient
- `htax_check_rt_edit_lock( string $slug ): WP_User|false` - Same semantics as term variant
- `htax_heartbeat_refresh_lock( array $response, array $data ): array` - `heartbeat_received` filter; handles both `htax_term_lock_refresh` and `htax_rt_lock_refresh` payloads
- `htax_enqueue_lock_script( array $config )` - Enqueue `htax-term-edit-lock` script and pass `HTAXLock` config (term_id or rt_slug, nonce, locked-user data, list URL, i18n strings)
- `htax_render_lock_dialog( WP_User|false $locked_by, string $back_url, string $take_url )` - Output the dialog HTML; both sections always present, visibility controlled by PHP for initial state and JS for displacement
- `htax_term_lock_on_load()` - `load-edit-tags.php` / `load-term.php` handler; skips add screens (no tag_ID); handles take-over redirect; sets up enqueue and footer render closures
- `htax_rt_lock_on_admin_init()` - `admin_init` handler for relationship type edit page; same flow as term variant

**Bulk Actions (includes/admin/bulk-actions.php)**
- `htax_add_bulk_actions( array $bulk_actions ): array` - Filter callback on `bulk_actions-edit-{$taxonomy}`; appends `htax_make_visible`, `htax_make_hidden` (when visibility enabled), `htax_remove_icon` (when icons enabled), and `htax_assign_to_groups` (when group assignment enabled) to the term list table's bulk action dropdown
- `htax_handle_bulk_actions( string $redirect_to, string $action, int[] $term_ids ): string` - Filter callback on `handle_bulk_actions-edit-{$taxonomy}`; processes `htax_make_visible`, `htax_make_hidden`, and `htax_remove_icon` for each selected term ID; `htax_assign_to_groups` is handled entirely via AJAX modal and returns early here; appends `htax_bulk_action` and `htax_processed` query vars to `$redirect_to` when any terms were updated, for admin notice display. For `htax_make_visible`: silently skips any term whose ancestor is still hidden at processing time (a term cannot be made visible while a parent is hidden); because WP processes selected terms in list order, selecting both a parent and its descendant works correctly — parent is made visible first, descendant passes the ancestor check on its own turn.
- `htax_ajax_bulk_assign_groups()` — `wp_ajax_htax_bulk_assign_groups` handler; accepts `term_ids[]`, `add_groups[]`, `remove_groups[]`, `taxonomy`, `nonce`; for each term, merges its existing group IDs with `add_groups` then removes `remove_groups` (preserving all unmentioned groups), then calls `htax_set_term_groups()`; returns `{ processed: N, message: string }`. The JS modal uses three-state checkboxes (checked = add to all, unchecked = remove from all, indeterminate = no change); only groups whose state differs from the initial state are sent as `add_groups` or `remove_groups`. Group labels passed to JS are decoded with `html_entity_decode()` before `wp_localize_script` to avoid double-encoding (WordPress's `get_terms()` already HTML-encodes names for display).

### Admin — Group Admin (includes/admin/group-admin.php)

Group admin hooks to `edited_group` (and `group_edit_form_fields`) to manage four relationship sets visible from the group's edit screen:

- `htax_add_terms_in_group_field()` / `htax_save_terms_in_group()` — Display/save `member_of` rows (T→G membership). Save path: deletes `htax_grp_{group_id}_*` caches (all taxonomies) via `htax_delete_transients_by_prefix`, calls `htax_reload_term_caches_on_edit` for each changed member term, calls `htax_invalidate_group_relationship_caches`, then fires `htax_term_groups_changed` for search-index listeners.
- `htax_add_terms_with_container_group_field()` / `htax_save_terms_with_container_group()` — Display/save `includes` rows where this group is the source (G→T). Save path: calls `htax_reload_term_caches_on_edit` for each changed target term, calls `htax_invalidate_group_relationship_caches`, then calls `htax_clear_member_caches_for_groups([group_id], 'inc')` to clear `htax_inc_M` for all members of this group and its ancestor groups (path 3 of `htax_get_included_terms` expands member groups downward, so ancestor-group members also reach these container rows).
- `htax_add_terms_with_included_group_field()` / `htax_save_terms_with_included_group()` — Display/save `includes` rows where this group is the target (T→G: terms whose archives expand to include all this group's members). Save path: calls `htax_reload_term_caches_on_edit` for each changed source term, calls `htax_invalidate_group_relationship_caches`, then calls `htax_clear_member_caches_for_groups([group_id], 'cont')` to clear `htax_cont_M` for all members of this group and its descendant groups (`htax_get_container_terms` path 2 matches exact membership; `htax_get_terms_in_groups` expands downward).
- `htax_add_group_custom_relationships_field()` / `htax_save_group_custom_type_relationships()` — Display/save custom (non-core) relationship rows where this group is source or target. Renders one tab per registered non-core type, each with a forward panel (G→U, group as source) and a reverse panel (U→G, group as target). Groups cannot be on the other side because group+group rows are always invalid, so only non-group term IDs are stored. Save path: diffs submitted `htax_cust_{slug}_fwd_terms` / `htax_cust_{slug}_rev_terms` against current DB rows and calls `htax_add_term_relationship` / `htax_remove_term_relationship` for each change (those functions handle cache invalidation).

**Terminology:** "terms with container group" (G→T) and "terms with included group" (T→G) are opposite directions of the `includes` relationship involving a group. `htax_grp_*` caches are only affected by membership changes (`member_of` rows). G→T container-group changes affect `htax_inc_*` caches for the target term T and for members M of the group (and ancestor groups). T→G included-group changes affect `htax_cont_*` caches for members M of the group (and descendant groups). Custom-type group rows additionally affect `htax_crel_*/htax_crel_rev_*` caches for members. `htax_invalidate_group_relationship_caches` handles the T and custom-rel side; `htax_clear_member_caches_for_groups` handles the member side.

- `htax_render_group_term_section()` - Shared pill-container renderer used by the member/container/included-group fields (per-taxonomy sections)
- `htax_render_group_crel_pill_container()` - Cross-taxonomy pill container used by the custom relationships field; loads term objects across all enabled non-group taxonomies (term IDs are globally unique); uses `htax_search_terms` AJAX action with `data-taxonomies` scoped to the type's `target_taxonomies` / `source_taxonomies` (or all enabled non-group taxonomies if unset)
- `htax_get_taxonomy_map_for_terms( int[] $term_ids )` - Batch-fetch taxonomy slugs for term IDs; used by save functions to build source_taxonomy values for new rows

### Admin — Relationship Types (includes/admin/relationship-types-admin.php)

Admin UI for viewing built-in relationship types and managing user-defined types. Registered as the "Term Relationship Types" submenu under the Terms top-level menu.

- `htax_render_term_relationships_page()` - Render the Term Relationships admin page; shows built-in types as a read-only table and custom types with edit/delete actions; "New Inclusion-based" and "New Custom" buttons open the `#htax-rt-pane` floating dialog (same as Edit) with a slug input and cleared fields; Edit and Delete actions also operate through the pane/AJAX without page navigation
- `htax_handle_save_relationship_type()` - `admin_post_htax_save_relationship_type` handler; validates and persists a new or updated custom type via `htax_set_custom_relationship_type()`; calls `htax_remove_invalid_term_relationships()` after save to purge rows that violate the updated constraints
- `htax_handle_remove_relationship_type()` - `admin_post_htax_remove_relationship_type` handler; deletes a custom type via `htax_remove_custom_relationship_type()`; redirects back with success/error notice
- `htax_ajax_count_ineligible_custom_relationships()` - `wp_ajax_htax_count_ineligible_custom_relationships` handler; counts existing relationship rows that would become invalid if taxonomy constraints are narrowed; used by the edit form to warn before saving
- `htax_render_taxonomy_checkboxes( string $field_name, array $selected, array $taxonomies )` - Render source/target taxonomy checkbox inputs for the add/edit forms

### Admin — Link Types (includes/admin/link-types-admin.php)

Owned by TermConnect. See the TermConnect documentation.

### Admin — Tables, AJAX, Import/Export

**Post Editor (includes/admin/post-edit.php)**
Loaded unconditionally (outside `is_admin()`) so the REST field is available during REST API requests.
- `htax_enqueue_post_editor_assets()` - Enqueue CSS/JS and build JS globals for the block/classic editor. PHP always emits the full taxonomy-gated data; per-user display preferences (which of the five indicators to show) are stored in the Gutenberg `core/preferences` store under `htax/post-edit` and applied client-side. Also enqueues `htax-editor-prefs.js` in the block editor, which registers five `PluginMoreMenuItem` toggles in the ··· menu and dispatches a one-time Gutenberg notice (via `core/notices`) on first block-editor visit; dismiss state stored in user meta `_htax_prefs_callout_dismissed` (set via `htax_ajax_dismiss_prefs_callout` / `wp_ajax_htax_dismiss_prefs_callout`). Globals emitted:
  - **`htaxTermMeta`** — keyed by term ID; each entry: `{id, name, slug, taxonomy, is_redirect, is_hidden, is_hierarchical, inclusion_count, container_count, included_names, container_names, redirect_target_name}`. Hierarchical taxonomies include all terms; flat taxonomies include only the post's currently assigned terms. Terms with at least one HT flag are always included; additionally, all assigned terms of a `customize_archives`-enabled taxonomy are included (even plain terms with no flags) so the sticky pin can be shown.
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
- `htax_register_sticky_rest_field()` - Register `htax_sticky_terms` REST field on all eligible public post types (via `rest_api_init`)
- `htax_rest_get_sticky_terms()` - REST GET callback: return sticky term IDs keyed by taxonomy slug
- `htax_rest_update_sticky_terms()` - REST UPDATE callback: write/delete `_htax_sticky_{term_id}` post meta and invalidate caches
- `htax_get_sticky_terms_by_taxonomy( int $post_id )` - Return a post's sticky term IDs grouped by taxonomy slug; shared by the REST GET callback and the update callback's change detection (called with a post ID only — no `$request` needed)

**List Table (includes/admin/tables.php)**
- `htax_enqueue_table_assets()` - Enqueue list table CSS/JS with localized filter config (`htaxTablesConfig`); includes `bulkAssignNonce` and `groupFilter.options` (group names decoded via `html_entity_decode()` to avoid double-encoding from `get_terms()` display escaping); localized config powers both the Term Groups filter dropdown and the bulk assignment modal
- `htax_add_icon_column()` - Add icon column to term list tables (AJAX-compatible)
- `htax_render_icon_column()` - Render icon column content
- `htax_add_hidden_column()` - Add visibility column to term list tables (AJAX-compatible)
- `htax_display_hidden_column()` - Display visibility icons in term lists; also renders a hidden `<span class="htax-qe-vis-data" data-hidden="0|1" data-parent-hidden="0|1">` per row. `data-hidden` reflects the term's own visibility state; `data-parent-hidden` is 1 when any ancestor is currently hidden (computed by walking the parent chain against `htax_get_all_hidden_term_ids()`, cached with a static variable across rows). Quick Edit JS reads `data-parent-hidden` to disable the visibility checkbox with a tooltip when the user cannot change visibility without first making a parent visible.
- `htax_quick_edit_fields()` - Renders the HyperTaxonomy fieldset inside the term Quick Edit row (hooked on `quick_edit_custom_box`); anchors to `htax_term_groups` column when group_assignment is enabled, otherwise to `htax_hidden`; renders once per row. Values are populated from per-row data spans by `htax-tables.js` on `inlineEditTax.edit`. Saving reuses `edited_{taxonomy}` hooks (`htax_save_term_data`, `htax_save_term_groups`), which accept WP's Quick Edit nonce (`_inline_edit` / `taxinlineeditnonce`).
- `htax_ajax_count_hidden_descendants()` — `wp_ajax_htax_count_hidden_descendants` handler; accepts `term_ids[]`, `taxonomy`, `nonce`; for each term ID returns a count of hidden descendants via `get_term_children()` filtered against `htax_get_all_hidden_term_ids()`. Descendants that are themselves present in `term_ids` are excluded from every count — when a parent and child are both selected for bulk "Make Visible", the child does not inflate the cascade-prompt count since it will be made visible on its own. Called before bulk and Quick Edit make-visible saves to decide whether to show the cascade modal.
- `htax_add_redirect_column()` - Add redirect column to term list tables (AJAX-compatible)
- `htax_display_redirect_column()` - Display redirect information (count of redirecting terms)
- `htax_add_term_groups_column()` - Add Term Groups column to term list tables (AJAX-compatible)
- `htax_term_groups_column_content()` - Render Term Groups badges and a hidden `<span class="htax-qe-grp-data" data-group-ids="...">` with comma-separated group IDs; this span is read by the bulk assignment modal JS to compute initial three-state checkbox values without an extra AJAX round-trip
- `htax_register_sortable_columns( $columns )` - Registers Redirect, Icon, Visibility, and Term Groups columns as sortable on `manage_edit-{taxonomy}_sortable_columns`; guards each column behind its corresponding feature flag
- `htax_sortable_column_terms_clauses( $clauses, $taxonomies, $_args )` - `terms_clauses` filter; rewrites `ORDER BY` when `?orderby=` matches a HyperTaxonomy column key; scoped to `htax_is_term_list_request()` only; uses `EXISTS` subqueries (redirect row in `wp_htax_term_relationships`, meta presence in `wp_termmeta`) so all terms appear regardless of whether they carry the attribute; secondary sort always `t.name ASC`; Term Groups uses `GROUP_CONCAT` of group names to produce a stable alphabetic key
- `htax_tag_row_class()` - Add CSS class to redirect term rows
- `htax_add_sticky_indicators()` - Add sticky indicators to term columns in post list

**Term Filters (includes/admin/term-filters.php)**
- `htax_filter_terms_by_redirect()` - `get_terms_args` filter; narrows term queries to redirect or non-redirect terms when the admin list filter is active. Filter values: `standard` (Non-Redirects — excludes redirect sources, includes redirect targets), `redirects`, `auto_redirects`, `targets` (Redirect Targets only).
- `htax_is_term_list_request(): bool` - Returns true when the current request is an admin term list screen or its AJAX counterpart (Quick Edit). Used to gate `get_terms_args` filters that should only apply on list screens, not on dropdowns or autocomplete queries.
- `htax_merge_term_ids_to_exclude( array &$args, int[] $ids ): void` - Merge `$ids` into `$args['exclude']`, deduplicating and removing any IDs that also appear in `$args['include']` (WordPress's `get_terms` ignores exclude when the same ID is included).
- `htax_intersect_term_ids_to_include( array &$args, int[] $ids ): void` - Restrict results to `$ids` by setting `$args['include']`; if `$args['include']` is already set, takes the intersection so existing include constraints are preserved.
- `htax_push_meta_query_clause( array &$args, array $clause ): void` - Append a `meta_query` clause to `$args`, initialising the `meta_query` key as needed.

**AJAX Handlers (includes/admin/ajax.php)**
- `htax_ajax_get_group_terms()` - Get terms from Term Groups
- `htax_ajax_search_terms()` - Autocomplete search (6-bucket ranking); accepts `taxonomies[]`, optional `include_groups` / `exclude_groups` (comma-separated group IDs expanded to term IDs via `htax_get_terms_in_groups()` to restrict the option pool); results cached via `htax_get_cache_duration('autocomplete')`
- `htax_ajax_get_group_term_ids()` (`wp_ajax_htax_get_group_term_ids`, also `nopriv`) — Returns all term IDs belonging to the given `groups` (comma-separated) for `taxonomy`. Rate-limited; nonce required for admin users. Used by the search widget JS to determine which selected pills to animate-remove when a group is excluded from the option pool.
- `htax_ajax_import_tag_groups_init()` (`wp_ajax_htax_import_tag_groups_init`) - Chunked import, first request: resolves/creates/renames groups, applies hierarchy, detects deletions. Accepts `mode` (`mirror`|`keep`) for this run only; the choice is chosen per run in the import modal and not persisted.
- `htax_ajax_import_tag_groups_batch()` (`wp_ajax_htax_import_tag_groups_batch`) - Chunked import, batch request: assigns one batch of terms. Accepts `group_mapping`, `offset`, `batch_size`, `mode`.
- `htax_ajax_import_tag_groups_finish()` (`wp_ajax_htax_import_tag_groups_finish`) - Chunked import, final request: clears caches, stores the run snapshot via `htax_store_tag_groups_import_snapshot()`.
- `htax_ajax_dismiss_tag_groups_notice()` (`wp_ajax_htax_dismiss_tag_groups_notice`) - Permanently dismisses the Tag Groups coexistence notice for the current user (`_htax_dismissed_tag_groups_notice` user meta).
- `htax_clear_tag_groups_notice_dismissal_on_deactivate( $plugin )` (`deactivated_plugin`) - Clears every user's `_htax_dismissed_tag_groups_notice` meta when a plugin whose basename starts with `tag-groups` is deactivated, so the coexistence notice is shown again (until re-dismissed) if Tag Groups is reactivated later.
- `htax_ajax_export()` - Export HyperTaxonomy data to JSON
- `htax_ajax_preview_import()` - Preview HyperTaxonomy import data
- `htax_ajax_import()` - Import HyperTaxonomy data from JSON
- `htax_find_after_non_letter()` - Find search after word boundary
- `htax_highlight_match()` - Highlight search matches

**Export/Import (includes/admin/)**

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
- `htax_process_import( $data, $options )` - Core import logic (3 phases: terms, relationships, post assignments)
- `htax_process_import_with_output( $data, $options )` - Import with HTML progress output
- `htax_import_icon_from_url( $url )` - Match icons by filename from URL

**Tag Groups Migration (import-tag-groups-handler.php):** Tag Groups was rebranded "PublishPress Tag Groups" in October 2026 — the database format below is unchanged under either name.
- `htax_get_tag_groups_site_token(): string` - Random UUID, generated once and stored in the `htax_tag_groups_site_token` option, scoping the mapping below to this site. Confirmed `htax_generate_export_data()`'s `$meta_keys` is a hardcoded enumerated whitelist that does not include the mapping meta key, so HT's own JSON export/import never carries it across sites — the scoping instead guards against other ways `wp_termmeta` can move between sites (a raw database export/import, a staging→production clone, a third-party site-migration plugin), where a bare Tag Groups ID could otherwise collide with an unrelated group on the destination site and silently rename it.
- `htax_make_tag_groups_source_key( int $tg_group_id ): string` / `htax_parse_tag_groups_source_key( string $key ): ?int` - Build/parse the `{site_token}:{tg_group_id}` mapping key; parse returns `null` (not a wrong match) for a key stamped on another site.
- `htax_resolve_or_create_tag_group( int $tg_group_id, string $label, ?string $parent_label, array &$stats ): int` - ID-match (this site's mapping) → name-match against unmapped groups only (first import, or re-linking after a cross-site meta mismatch) → create. The HT name comes from `htax_get_tag_groups_display_name()`, so it is the plain label unless another group with that name is mapped to a different Tag Groups group; then it is suffixed (see helpers below). Keeps the name in sync on every run, so Tag Groups-side renames propagate instead of creating a duplicate. Name comparison decodes HTML entities on both sides, so "&" in a Tag Groups label matches "&amp;" stored in HyperTaxonomy and does not trigger a rename on every run. Records newly-stamped mappings (name-match and create cases) to `$stats['newly_mapped']`, the signal `htax_import_tag_groups_hierarchy()` uses to seed a parent field once and never again.
- `htax_orphan_deleted_tag_groups( array $current_tg_group_ids ): array` - Strips the mapping meta (never deletes the HT term) for any previously-mapped group no longer in Tag Groups' current `term_groups` option.
- `htax_import_tag_groups_hierarchy( array $group_mapping, array $newly_mapped_old_ids ): array` - Seeds Tag Groups Pro's parent-group data (`term_group_positions` + `term_group_parents`) onto the native `parent` field of newly-mapped HT group terms **only** — `$newly_mapped_old_ids` restricts writes to groups mapped for the first time this run; a group already mapped from a prior run is never touched again, deliberately. (HT's native hierarchy supports arbitrary depth; Tag Groups Pro's is capped at two levels — re-applying on every run would silently flatten any deeper structure an admin built in HT back down to that cap.) Single pass, not recursive — Tag Groups' own documentation confirms parent groups are capped at two levels. Provenance-aware even for the one-time seed: skips (and counts as a conflict) rather than overwrites when a newly-matched group's current parent is a non-mapped, HT-native group.
- `htax_split_term_groups_by_provenance( array $group_ids ): array` - Splits a term's current HT groups into Tag-Groups-mapped vs. HT-only, by presence of the mapping meta.
- `htax_apply_tag_groups_term_assignment( int $term_id, string $taxonomy, array $tg_mapped_new_ids, string $mode = 'mirror' ): string` - Applies one term's Tag Groups membership report to its HT group assignment (the provenance-filtered merge), returning `'assigned'`, `'skipped'`, or `'error'`. Factored out of the batch loop so it's independently callable — see `tag-groups-live-sync-coexistence.md`.
- `htax_store_tag_groups_import_snapshot(): void` - Stores `{groups, terms, time}` to the `htax_tag_groups_last_import` option after a successful run, for the migration screen's diff display.
- `htax_import_tag_groups_create_groups(): array` - First pass: resolves/creates/renames group terms, applies hierarchy, detects deletions. No longer matches by name only — ID-first via the site-scoped mapping.
- `htax_import_tag_groups_process_batch( array $group_mapping, int $offset, int $batch_size, string $mode = 'mirror' ): array` - Batch pass: assigns one batch of terms to their groups. No longer restricted to `post_tag`/`category` — any taxonomy works, gated the same way the per-row `group_assignment` feature check already did. `$mode` controls the Tag-Groups-mapped subset only (HT-only memberships are always preserved): `'mirror'` (default) makes it identical to Tag Groups' current report for the term, including removals; `'keep'` only ever adds.
  - Reads from wp_options: term_groups, term_group_labels, term_group_positions, term_group_parents
  - Reads from wp_termmeta: _cm_term_group_array
  - Converts to HyperTaxonomy Term Groups

**Integration mode (import-tag-groups-handler.php):**
- `htax_tag_groups_integration_mode(): string` - The `htax_tag_groups_integration_mode` option (`'independent'` or `'locked'`; default `'independent'`), validated against the known set. `'independent'` disables every lock below regardless of what data Tag Groups has written; `'locked'` locks them between manual migration runs. Set from Settings → HyperTaxonomy → Export / Import → Sync via the `htax_save_tag_groups_sync_settings` AJAX action (ajax.php), which saves the mode, the continuous-sync checkbox, and removal handling (`htax_tag_groups_sync_mode`) together. Enabling continuous sync reports `needs_resync: true` so the UI can run one full resync first via the Migrate subtab (`resync=1`).

**Re-run overwrite protection (import-tag-groups-handler.php, loaded unconditionally in admin — not just from the importer's own AJAX requests, since `htax_save_term_groups()`/`htax_save_terms_in_group()` need these on every term/group save):**
- `htax_tag_groups_import_in_progress( ?bool $set = null ): bool` - Reentrancy flag, set around `htax_import_tag_groups_create_groups()`'s own rename/hierarchy writes so `htax_lock_tag_groups_mapped_name()` can tell the importer's own legitimate write apart from an admin's.
- `htax_tag_groups_taxonomy_is_managed( string $taxonomy ): bool` - Whether Tag Groups has actually written `_cm_term_group_array` termmeta for a taxonomy, read directly rather than via any Tag Groups setting — the gate for every lock below. Returns `false` unconditionally when integration mode is `'independent'`. Per-request cached.
- `htax_lock_tag_groups_mapped_name( $data, $term_id, $taxonomy, $args )` - Filters `wp_update_term_data` (WP core, fires in `wp_update_term()` before the DB write, unconditionally for every entry point — full edit screen, native Quick Edit, REST, WP-CLI). For a `group` term carrying `_htax_tag_groups_source_id`, outside an import pass and outside `'independent'` mode, restores `$data['name']` to the term's current name so a rename never sticks through any path except the importer itself.
- `htax_save_term_groups( int $term_id )` (term-edit.php) - Unchanged signature; now re-merges in the Tag-Groups-mapped subset of the term's existing group IDs over whatever was submitted, for any taxonomy `htax_tag_groups_taxonomy_is_managed()` is true for. Covers the term's own edit screen and Quick Edit's "Term Groups" checklist, which reuses this handler.
- `htax_save_terms_in_group( int $term_id )` (group-admin.php) - Unchanged signature; for a group carrying `_htax_tag_groups_source_id`, carries forward the current members of any locked taxonomy instead of applying the submission for it (per-taxonomy, since one group can hold members across several at once).
- `htax_get_tag_groups_parent_map(): array` - Child Tag Groups group ID → parent group ID, derived from `term_group_positions` and `term_group_parents` (single pass; Tag Groups Pro caps hierarchy at two levels). Used by the hierarchy seed and to look up parent labels for naming.
- `htax_find_group_terms_by_label( string $label ): WP_Term[]` - HT group terms whose decoded name equals `$label`.
- `htax_get_tag_groups_display_name( string $label, ?string $parent_label, string $source_key, int $own_term_id = 0 ): string` - Returns `$label` unless another HT group with that name is mapped to a different Tag Groups group. A clash appends the parent label as `Label (Parent)`, falling back to `Label (2)`, `Label (3)`… when there is no parent or the candidate is taken. `$own_term_id` is ignored when checking whether a candidate is taken, so a group keeps its own suffix on later runs.
- `htax_is_group_label_taken( string $label, int $own_term_id ): bool` - Whether any HT group other than `$own_term_id` has this name.
- `htax_tag_groups_native_field_promoted(): bool` - Whether Tag Groups' own native per-term field should take the always-visible spot HT's Term Groups selector normally occupies on the term edit/add screens, with HT's own (now read-only) selector demoted into the "More options" collapsible instead. True only when Locked mode is selected and Tag Groups is currently active. Read by `htax_add_edit_form_collapsible_start()` / `htax_add_form_collapsible_start()` (emitted as `data-promote-native-groups` for the JS reorder in htax-term-edit.js / htax-tables.js) and by the `{taxonomy}_*_form_fields` registration in `includes/hooks/actions.php` (controls whether `htax_add_term_groups_field()` is hooked at priority 8, always visible, or priority 16, inside the collapsible between Redirect and Term Relationships).
- `htax_find_ht_group_by_tag_groups_id( int $tg_group_id ): ?int` - Read-only companion to `htax_resolve_or_create_tag_group()` (which now calls this internally for its own ID-match step) — looks up the HT group mapped to a Tag Groups ID on this site without creating anything. Used by the live-sync membership handler, which only ever sees IDs Tag Groups itself reports as already existing.

**Live sync (tag-groups-live-sync.php, loaded unconditionally in admin alongside import-tag-groups-handler.php; registers nothing unless `htax_tag_groups_live_sync_active()` is true):**
- `htax_tag_groups_live_sync_enabled(): bool` (import-tag-groups-handler.php) - The `htax_tag_groups_live_sync` option is on and the integration mode is `'locked'`. It is cleared whenever the mode is saved as `'independent'`.
- `htax_tag_groups_live_sync_active(): bool` (tag-groups-live-sync.php) - `function_exists('tag_groups_init')` and `htax_tag_groups_live_sync_enabled()`.
- `htax_tag_groups_live_sync_register()` (tag-groups-live-sync.php) - Hooked on `plugins_loaded` (priority 20) and registers the three Tag Groups hooks below only when `htax_tag_groups_live_sync_active()` is true. Runs after every plugin has loaded, so Tag Groups' functions exist; checking at file-include time would miss Tag Groups when it loads after HyperTaxonomy. Changes take effect on the next page load, not dynamically mid-request.
- Three Tag Groups hooks (Free v3.0.0 / Pro v2.0.9), registered only when the above is true:
  - `term_group_saved` (no args) → `htax_tag_groups_live_sync_on_group_saved()` - thin wrapper calling `htax_import_tag_groups_create_groups()` directly; that function already does this hook's whole job (resolve/create/rename, one-time hierarchy seed, orphan detection).
  - `tag_groups_term_group_deleted( int $group_id )` → `htax_tag_groups_live_sync_on_group_deleted()` - calls `htax_orphan_deleted_tag_groups()` against Tag Groups' current `term_groups` option.
  - `tag_groups_groups_of_term_saved( array $term_groups, int $term_id )` → `htax_tag_groups_live_sync_on_term_groups_saved()` - maps Tag Groups' IDs to HT IDs via `htax_find_ht_group_by_tag_groups_id()` (skipping any not yet mapped) and calls `htax_apply_tag_groups_term_assignment()`, same as the periodic batch importer does per term.

**UI niceties for the above (no new enforcement — the save-function guards above are what actually prevent the overwrite):**
- `htax_hierarchical_checklist()` (class-hierarchical-checklist.php) gained `disabled_terms` (int[]) and `disabled_title` (string) args, and the walker's `start_el()` now accepts `$args['disabled']` as either a bool (existing all-items behavior) or an array of specific term IDs to disable — used to gray out locked groups in the term-edit screen's add-group checklist.
- `htax_render_group_term_section()` (group-admin.php) gained a `$locked_taxonomies` parameter (default `array()`); only `htax_add_terms_in_group_field()` ("Group Members") passes a non-empty value — the other two callers (inclusion-type fields) are never locked.
- `htax_group_tag_groups_mapping_notice( WP_Term $term ): void` (group-admin.php) - `group_edit_form_fields` notice on a mapped group's own edit screen stating its locked fields and what deleting it does (not blocked, just surfaced).
- `htax_group_custom_column_content()` (group-admin.php) - the "Taxonomies" list-table column gets a lock-icon prefix for a mapped group, so it's identifiable without opening it.
- Quick Edit's group checklist (`htax-tables.js`, built from `htaxTablesConfig.groupFilter`) gained `lockedGroupIds`/`lockedTitle`, localized from PHP once per screen (the lock is per-taxonomy, not per-row) — disables the matching checkboxes with the same tooltip.

### Cache Management (includes/core/cache.php)

**Nuclear (full-reset):**
- `htax_get_transient( $key )` / `htax_set_transient( $key, $value, $expiration )` / `htax_delete_transient( $key )` - Transient API wrappers used for every HyperTaxonomy transient; resolve the storage key via `htax_transient_key()`
- `htax_transient_key( string $key ): string` - Under an external object cache, appends `~{global generation}{family generation}` to `htax_*` keys; otherwise returns the key unchanged
- `htax_transient_family( string $key ): string` - Maps a key to its invalidation family (`rel`, `contrib`, `pl`, `total`, `ac`, `sticky`, `misc`)
- `htax_cache_generation( string $family, bool $bump = false ): string` - Current (or new) random generation token for a family, stored in the `htax_cache_generation` object-cache group
- `htax_bump_cache_family( string $family ): void` - Called after every bulk `DELETE … LIKE`: bumps the family generation under an external object cache, otherwise flushes the runtime `options` cache group

- `htax_clear_caches_on_feature_change( $old_value, $new_value )` - `update_option_htax_settings` (priority 10): calls `htax_clear_all_caches()` when `taxonomy_features` changed. Relationship caches are feature-independent (the getters apply `term_relationships` on read), but rendered outputs — post-list results, contributing terms — are computed with the flags applied
- `htax_clear_all_caches()` - Nuclear option: deletes all `_transient_htax_*` entries. Per-type variants: `htax_clear_relationship_caches()`, `htax_clear_search_caches()` (htax_contrib_*), `htax_clear_autocomplete_caches()` (htax_ac_*), `htax_clear_sticky_caches()` (htax_sticky_*), `htax_clear_shortcode_caches()` (htax_pl_*, htax_total_*)

**Per-term invalidation:**
- `htax_delete_term_transients( int $term_id )` - Delete all transients for a single term: core logical caches (`htax_inc_`, `htax_cont_`, `htax_excl_`, `htax_redir_`, `htax_groups_`, `htax_syno_`), contrib cache, all direct relationship caches for every registered type via `htax_delete_custom_rel_caches_for_term()` (covers `htax_crel_`, `htax_crel_rev_`, `htax_direct_rel_`, `htax_direct_rel_grp_` for each slug including core `'includes'`), and post list shortcode caches. After clearing, calls `htax_queue_term_cache_rebuild()` to schedule async re-warming.
- `htax_delete_term_transients_batch( array $term_ids )` - Batch-delete core relationship transients for multiple terms in two queries instead of N×individual calls. Builds all named option keys for `htax_inc_`, `htax_excl_`, `htax_redir_`, `htax_groups_`, `htax_cont_`, and `htax_syno_` prefixes across all term IDs and removes them in a single `DELETE … WHERE option_name IN (…)`. Then does two broad prefix deletes for `htax_contrib_` and `htax_pl_` (one query each, covering all affected terms at once). Finally queues each term ID via `htax_queue_term_cache_rebuild()`. Used by `htax_invalidate_group_relationship_caches()` to handle large fan-outs efficiently.
- `htax_queue_term_cache_rebuild( int $term_id )` - Buffer a term ID in `$GLOBALS['htax_term_rebuild_queue']`. Registers `htax_rebuild_queued_term_caches` on the `shutdown` hook (WordPress deduplicates same-callback `add_action` calls, so multiple invocations per request collapse to a single pass). When `HTAX_TIMING` is enabled, emits a `QUEUE_REBUILD` trace with a 5-frame call stack on the first enqueue of each term ID per request.
- `htax_rebuild_queued_term_caches()` - Shutdown callback (priority 5) that schedules an async cron event for cache re-warming. Collects all queued term IDs and calls `wp_schedule_single_event(time(), 'htax_async_rebuild_term_caches', [$queue])`, then spawns WP-Cron non-blocking. The response is never delayed by this call; the actual rebuild runs in the next cron pass.
- `htax_do_rebuild_term_caches( array $queue )` - Async cron handler (hooked to `htax_async_rebuild_term_caches`). Re-warms `htax_inc_{term_id}`, `htax_cont_{term_id}`, and `htax_syno_{term_id}` for each queued term ID by calling `htax_get_included_terms()`, `htax_get_container_terms()`, and `htax_get_synonym_terms()`. Skips term IDs that no longer exist.
- `htax_delete_custom_rel_caches_for_term( int $term_id )` - Delete `htax_crel_{slug}_{term_id}`, `htax_crel_rev_{slug}_{term_id}`, `htax_direct_rel_{slug}_{term_id}`, `htax_reverse_rel_{slug}_{term_id}`, `htax_direct_rel_grp_{slug}_{term_id}`, and `htax_reverse_rel_grp_{slug}_{term_id}` for every registered relationship type; called by `htax_delete_term_transients()`
- `htax_invalidate_term_caches_on_delete( int|WP_Term $term, int $tt_id, string $taxonomy )` - Full cache invalidation for a deleted term. Uses a **read-before-clear** pattern: reads T's outgoing direct caches (`htax_direct_rel_includes_T`, `htax_direct_rel_grp_includes_T`, `htax_direct_rel_{slug}_T`, and `htax_direct_rel_grp_{slug}_T` for all non-core slugs) *before* clearing T's own transients. Steps: (1) PRE-CLEAR: if all those caches are warm, the outgoing data is fully available with no DB queries; if any are cold, falls back to a broad `SELECT … WHERE source_term_id = T` query and parses rows into categorised arrays (`$out_inc_ids`, `$out_inc_grp_ids`, `$out_crel_ids[slug]`, `$out_crel_grp_ids[slug]`); (2) clears T's own caches via `htax_delete_term_transients(T)`; (3) surgically updates member-group caches; (4a) for incoming references (A→T rows): calls `htax_reload_term_caches_on_edit(A)` — when A is a group (G→T row), also calls `htax_clear_member_caches_for_groups([G], 'inc')` to clear `htax_inc_M` for members of G and ancestor groups; (4b) for outgoing references, iterates flat pre-read arrays: `$out_inc_ids` → deletes `htax_cont_U` + `htax_reverse_rel_includes_U`; `$out_inc_grp_ids` → deletes G's own `htax_reverse_rel_includes_G` then calls `htax_clear_member_caches_for_groups([G], 'cont')`; `$out_crel_ids[slug]` → deletes `htax_crel_rev_{slug}_U` + `htax_reverse_rel_{slug}_U`; `$out_crel_grp_ids[slug]` → deletes G's own `htax_reverse_rel_{slug}_G` then calls `htax_clear_member_caches_for_groups([G], 'crel_rev', slug)`; (5) clears caches for terms related via T's group memberships
- `htax_reload_term_caches_on_edit( int|WP_Term $term_id_or_term, int $tt_id )` - Invalidate all caches for a term on edit (no eager reload — lazy-load on next access)

**Targeted caches:**
- `htax_clear_count_cache()` - Clear total post count caches (htax_total_*)
- `htax_clear_ajax_caches()` - Clear autocomplete caches
- `htax_delete_transients_by_prefix( string $prefix )` - Delete all transients whose key starts with `$prefix` (both value and timeout rows); used for bulk-clearing type-scoped custom rel caches (`htax_crel_{slug}_` and `htax_crel_rev_{slug}_`), count caches, autocomplete caches, sticky post caches, and similar prefix-keyed groups

**Group-aware invalidation:**
- `htax_update_group_membership_caches()` - Surgical group member cache updates when term group membership changes. Steps: (1) invalidate+reload term's `htax_groups_*` cache; (2) surgically update `htax_grp_{group_id}_{taxonomy}` caches for affected groups + ancestors; (3) invalidate container cache; (4) reload term's other caches via `htax_reload_term_caches_on_edit` (clears all of M's own transients including `htax_inc_M`, `htax_cont_M`, `htax_crel_*/htax_crel_rev_*_M`); (5) schedule `wp_schedule_single_event(time(), 'htax_async_group_propagate', [$all_affected_groups])` so that `htax_invalidate_group_relationship_caches` runs asynchronously in the next cron pass without blocking the save response. Triggered by `htax_term_groups_changed` hook. Receives proper `$new_groups`/`$old_groups` only when called via `htax_set_term_groups`; group-admin.php edit-screen savers call `htax_invalidate_group_relationship_caches` and `htax_clear_member_caches_for_groups` directly.
- `htax_invalidate_group_relationship_caches( array $group_ids ): void` - Invalidate `htax_inc_*`, `htax_crel_{slug}_*`, and `htax_crel_rev_{slug}_*` caches for all terms that include or are container-related to the given groups (and their ancestors/descendants). Runs two SQL queries: (A) inclusions fwd — collect all `source_term_id` values from T→G rows where G is in `groups_with_ancestors`; (B) inclusions rev/container — collect all `target_term_id` values from G→T rows where G is in `groups_all` (ancestors + descendants). Both collected ID sets are merged and passed to `htax_delete_term_transients_batch()` in one call, which batch-deletes named transients and queues async rebuild for all affected terms. Custom rel caches (sections C+D) are still cleared per-term via `htax_delete_transient()` since they are slug-keyed and not covered by the batch function. Hooked to `htax_async_group_propagate` cron event (fired by `htax_update_group_membership_caches`); also called directly by `htax_invalidate_caches_on_group_reparent`.
- `htax_clear_member_caches_for_groups( array $group_ids, string $mode, string $cslug = '' ): void` - Clear a specific transient for all members of the given groups. Expands groups upward (`htax_get_group_ancestors`) for `'inc'` mode, or downward (`htax_get_group_hierarchy`) for all other modes, then queries `member_of` rows to find members and deletes the appropriate transient per member. Modes: `'inc'` deletes `htax_inc_M`; `'cont'` deletes `htax_cont_M`; `'crel'` deletes `htax_crel_{cslug}_M`; `'crel_rev'` deletes `htax_crel_rev_{cslug}_M`.
- `htax_invalidate_caches_on_group_reparent( int $term_id, int $tt_id, string $taxonomy ): void` - Hooked to `edited_term` at priority 12. Returns early unless taxonomy is `'group'`. Reads old parent from `htax_term_data_{term_id}` transient (captured by `htax_cache_term_taxonomy_before_edit` at priority 5), compares to current parent, and if the parent changed: (1) calls `htax_invalidate_group_relationship_caches([old_parent, new_parent])` to clear `htax_inc_*`, `htax_cont_*`, and `htax_crel_*/htax_crel_rev_*` for terms T that have rows pointing to the old/new parent chains; (2) calls `htax_clear_member_caches_for_groups([old_parent, new_parent], 'inc')` to clear `htax_inc_M` for members of the old and new parent groups (and their ancestors), because path 3 of `htax_get_included_terms` expands member groups downward so ancestor-group members lose or gain G in their expanded set when G moves.

**Frontend / shortcode caches:**
- `htax_invalidate_contrib_cache( int $term_id )` - Delete all `htax_contrib_{term_id}_p*` pages; deduped per request via static array
- `htax_invalidate_post_list_cache( int[] $term_ids )` - Pattern-delete `htax_pl_*` shortcode caches for given term IDs
- `htax_expand_term_ids_for_contrib_invalidation( int[] $term_ids, string $taxonomy ): int[]` - Expand a term ID list to include all terms whose contrib caches are affected: adds WP ancestors, group-expanded included terms, and reverse-included terms (terms that include any of the input terms). Called before firing `htax_term_archive_changed` so that the contrib-cache invalidation covers the full upstream fan-out.
- `htax_tt_ids_to_term_ids( int[] $tt_ids ): int[]` - Convert term-taxonomy IDs to term IDs via a single `SELECT term_id FROM wp_term_taxonomy WHERE term_taxonomy_id IN (…)` query; used in `deleted_term_relationships` hook handlers that receive `$tt_ids` from WordPress.
- `htax_get_post_index_term_ids( int $post_id ): int[]` - Return all term IDs associated with a post via the denormalized `wp_htax_search_terms` index; used to find which terms' contrib caches need invalidating when a post's term assignments change.

**Async cron hooks (registered in includes/hooks/actions.php):**
- `htax_async_rebuild_term_caches` → `htax_do_rebuild_term_caches( array $queue )` — Scheduled by `htax_rebuild_queued_term_caches()` at shutdown; re-warms `htax_inc_*`, `htax_cont_*`, and `htax_syno_*` for each queued term without blocking the current request.
- `htax_async_group_propagate` → `htax_invalidate_group_relationship_caches( array $group_ids )` — Scheduled by `htax_update_group_membership_caches()` after a group membership change; propagates cache invalidation to all terms that include or are related to the affected groups, asynchronously.

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
- `htax_clear_settings_request_caches()` - Hooked first (priority 0) on `update_option_htax_settings`; clears the per-request copies behind `htax_is_taxonomy_enabled()`, `htax_get_indexed_post_types()` and `htax_get_enabled_taxonomies()`, so handlers later in a settings-save request read the new settings

**Admin Notices:**
- `htax_add_admin_notice()` - Add admin notice (immediate or queued)
- `htax_display_queued_notices()` - Display notices queued from previous requests

**Security:**
- `htax_verify_term_nonce()` - Verify WordPress term edit/add nonces; accepts three nonce sources: `_wpnonce` (action `update-tag_{id}`, full edit form), `_wpnonce_add-tag` (action `add-tag`, AJAX new-term form), and `_inline_edit` (action `taxinlineeditnonce`, WP Quick Edit). The `htax_save_term_data` early-return guard checks for the presence of any of these three fields before calling this function.

**Logging & Error Handling:**
- `htax_log_debug()` - Debug logging (WP_DEBUG + WP_DEBUG_LOG, supports verbose backtrace mode)
- `htax_log_error()` - Error logging (always logs, supports verbose backtrace mode)
- `htax_get_filtered_backtrace()` - Generate filtered backtrace string (excludes logging frames)
- `htax_handle_db_error()` - Check wpdb->last_error; returns `$default` (and logs) on error, returns `false` when no error. Callers use `if ( false !== $err = htax_handle_db_error(...) ) { return $err; }` to short-circuit on failure.

**Meta Key Helpers:**
- `htax_get_sticky_assignment_key(int $term_id)` - Get the post meta key for a sticky term assignment (`_htax_sticky_{term_id}`); taxonomy is omitted because WordPress term IDs are globally unique

**Search Query Building:**
- `htax_parse_search_query( string $query, string $near_country = 'US' ): array` - Parse a free-text search string into structured per-field arrays
  - Returns keys: `title`, `body`, `excerpt`, `tags`, `categories`, `terms`, `general`, `proximity`
  - Each field value is an array of `['term', 'required', 'excluded', 'quoted', 'slug_match']` entries
  - `+term` sets `required=true`; `-term` sets `excluded=true`; `"term"` sets `quoted=true`
  - `slug_match=true` for **singular** operators (`tag:`, `category:`, `term:`, taxonomy slug, singular display label) — callers match against term slugs rather than FULLTEXT names. `slug_match=false` for **plural** operators (`tags:`, `categories:`, `terms:`, plural display label) — FULLTEXT matching.
  - `quoted=true` for field-specific terms if the value was wrapped in `"..."` in the input; for general terms, `quoted=true` only when the term appeared as `"word"` — used by exact-match scoring to distinguish intentional label lookups from content searches
  - `proximity` key: array of parsed `near:` tokens (see ATCF Integration). Empty when ATCF is inactive or no proximity operators are present.
  - `$near_country`: ISO 3166-1 code passed down to proximity tokens for postal-code format validation and centroid lookup. Defaults to `'US'`.
  - Used by all three search systems: post list, advanced widget, PowerSearch
- `htax_get_exact_match_term_ids( string $term_string, string $taxonomy = '' ): array` — B-tree equality lookup: returns term IDs where `LOWER(name) = LOWER($term_string)`, optionally filtered to one taxonomy. Used by PowerSearch to pre-fetch IDs for exact-match scoring.
- `htax_build_boolean_query_string( array $field_terms, array $general_terms = [] ): string` - Build a MySQL FULLTEXT BOOLEAN MODE query string (used for taxonomy scoring and WHERE enforcement)
- `htax_build_natural_language_query_string( array $field_terms, array $general_terms = [] ): string` - Build a NATURAL LANGUAGE MODE query string (operators stripped, excluded terms omitted; used for post text field scoring)
  - Accepts a flat array of `['term', 'required', 'excluded']` entries
  - Multi-word phrases are wrapped in double quotes; `+`/`-` prefixes applied without modifying term content
  - Replaces the removed `htax_build_boolean_query()` (which had a bug where `str_replace` stripped `-` from term content)
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

## Hook Organization

### Action Hooks (includes/hooks/actions.php)
Organized by category:
1. **Initialization and Setup** (6 hooks — `plugins_loaded` ×3, `admin_init` ×2, `init` ×1)
2. **Admin Enqueue Scripts** (2 hooks)
3. **Admin Notices** (1 hook)
4. **Term Edit Form Fields** (dynamic on `init`; per non-group taxonomy: up to 14 hooks — field handlers at priorities 8–18 × add/edit variants plus collapsible open/close at priorities 9+19; group taxonomy gets only the field handlers that have their own group guards). The External Links field is registered by TermConnect (when active); collapsible hooks are not registered for group at all.
5. **Term Save Hooks** (3 hooks per enabled taxonomy — `create_{tax}` p5, `edited_{tax}` p20/p30, dynamic on `init`)
6. **Cache Management** (14 hooks — includes surgical hidden-terms meta hooks and post-list cache invalidation)
7. **Term Edit Pre-Cache** (1 hook — `edit_term` p5 to cache hierarchy data for search-index parent-change detection)
7a. **External Conversion Detection** (3 hooks — `created_term` p1, `pre_delete_term` p5, `delete_term` p5)
8. **Cleanup Hooks** (5 hooks — `edited_term`, `created_term`, `delete_term`, `delete_term_taxonomy`, `delete_post`)
9. **Query Modification** (7 hooks — 6× `pre_get_posts` + `template_redirect`)
   - Note: `htax_apply_archive_filter` (`pre_get_posts` priority 11) is registered directly from `class-filter-options.php`, not from hooks/actions.php
   - Note: `htax_expand_secondary_query` (`pre_get_posts` priority 10) is registered directly from `integration-api.php`; `htax_fire_archive_changed_on_hierarchy` (`edited_term` priority 11) and the `htax_redirect_changed` closure are registered from `actions.php` but their callbacks live in `cache.php`
10. **Frontend Display** (4 hooks — `wp_head` ×3, `admin_head` ×1)
11. **AJAX Handlers** (4 hooks)
12. **Admin UI Enhancements** — hooks in `includes/admin/tables.php` (enqueued via `htax_enqueue_table_assets`)
13. **Security** (1 hook — `send_headers`)
14. **Multisite Support** (1 hook — `wp_initialize_site`)
15. **Custom Relationship Hooks** (4 hooks — `init` ×2 for type registration/loading, `htax_relationship_saved` and `htax_relationship_deleted` for search index requeue)

### Filter Hooks (includes/hooks/filters.php)
Organized by category:
1. **Plugin Meta and Links** (2 filters)
2. **Term Visibility** (3 filters)
3. **Admin Columns** (dynamic per-taxonomy: redirect, icon, hidden, bulk action filters)
4. **Query Filtering** (4 admin filters — redirect, icon, visibility, group)
5. **Archive Display** (1 filter — `get_the_archive_title`)
6. **Post Styling** (1 filter)
7. **Meta Box Defaults** (1 filter)
8. **The_Posts Filter** (1 filter)
9. **Pagination** (1 filter)
10. **Admin Term Links** (dynamic per-taxonomy sticky indicators)
11. **Admin Styling** (1 filter)
12. **Tag Creation Gate** (1 filter — `pre_insert_term` → `htax_maybe_prevent_tag_creation`; function defined in `core/functions.php`)

### Integration API Hooks (registered directly from integration-api.php)
- `htax_expanded_term_ids` — filter on expanded term ID list (registered within `htax_get_expanded_term_ids`)
- `htax_term_post_count` — filter on per-term expansion-aware count (registered from `htax_adjust_term_counts`)
- `get_terms` filter (`htax_adjust_term_counts`) — active on frontend only when `expand_secondary_queries` is on
- `rest_api_init` — registers REST filters that enrich term responses with `htax_*` fields, enforce hidden-term visibility, and normalize HTML entity encoding in term name searches (registered from `includes/hooks/filters.php`). The entity normalization filter hooks `get_terms_args` for the lifetime of the REST request: WP stores term names HTML-encoded in `wp_terms.name` (e.g. `H&amp;M`), but REST clients (Gutenberg tag autocomplete) send raw text; without normalization, searching `h&m` never matches `H&amp;M`. The filter applies `html_entity_decode` → `htmlspecialchars` to `args['search']` using the same round-trip as `htax_run_term_autocomplete_search()`.
- `wp_sitemaps_taxonomies_query_args` — excludes hidden term archives from XML sitemaps (registered from `includes/hooks/filters.php`)

## Hook Execution Order

### Event: `edited_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_reload_term_caches_on_edit` | Reload term caches (`htax_inc_*`, `htax_cont_*`, `htax_excl_*`, `htax_redir_*`, `htax_groups_*`, `htax_direct_rel_*`, `htax_direct_rel_grp_*`, `htax_crel_*`, `htax_crel_rev_*` for all registered slugs) |
| 15 | `htax_clear_ajax_caches` | Clear autocomplete caches |
| 20 | `htax_cleanup_hierarchy_conflicts` | Remove conflicting relationships, fire reindex actions |

`edited_{$taxonomy}` also fires (separate WordPress hook):
- Priority 20: `htax_save_term_data` — save relationships (includes/containers, redirects, custom type tab rows), eager reload caches, fire custom actions
- Priority 30: `htax_clear_count_cache_on_save` — targeted count cache clear

**Critical timing:** Cache is reloaded INSIDE relationship setters (`htax_save_term_relationships()`, `htax_set_redirect()`, `htax_remove_redirect()`) AFTER the DB update but BEFORE custom actions fire. This ensures index handlers always read the new state from cache, preventing race conditions.

### Event: `edited_term` (hierarchy handler)

In addition to the `edited_term` handlers listed above, a dedicated hierarchy handler runs for all hierarchical taxonomies (except `group`, which has its own handler):

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `on_taxonomy_hierarchy_changed` | Batched DELETE + INSERT of hierarchy entries when parent changes |

Hierarchy change optimization: identifies changed term plus all descendants, deletes old hierarchy entries in one query, inserts all new entries in one batched INSERT (`ON DUPLICATE KEY UPDATE`). Handles grandchildren, great-grandchildren, etc. Skips non-hierarchical taxonomies and the `group` taxonomy (handled by `on_group_hierarchy_changed` via `edited_group`).

### Event: `created_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 1 | `htax_track_created_term_for_conversion` | Records the new term in `$GLOBALS['htax_recently_created_terms']` (keyed by lowercase name) for external-conversion detection — runs before all other HyperTaxonomy hooks |
| 20 | `htax_cleanup_hierarchy_conflicts` | Remove conflicting relationships, fire reindex actions |
| 21 | `htax_cascade_hidden_on_create` | Inherit hidden status from parent |

### Event: `pre_delete_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 5 | `htax_detect_external_conversion_pre_delete` | Checks whether the term being deleted has the same name as a recently created term in a different taxonomy (external-conversion pattern). If matched: captures groups, hierarchy, icon ID, hidden status, and sticky post assignments into `$GLOBALS['htax_pending_external_conversions']` before term meta is wiped by `wp_delete_term()`. Sticky assignments are captured here (not at `delete_term`) because `delete_term_taxonomy` fires before `delete_term` and `htax_cleanup_deleted_term` deletes sticky postmeta via `delete_term_taxonomy` at priority 10. |

### Event: `delete_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 5 | `on_term_deleted` (search index) | `DELETE FROM wp_htax_search_terms WHERE source_term_id = %d` |
| 5 | `htax_complete_external_conversion_after_delete` | If `$GLOBALS['htax_pending_external_conversions']` has an entry for this term (and `htax_native_conversion_in_progress` is not set): transfers hidden status, icon (if dimensions match), and sticky posts to the new term; queues reindex; fires `htax_term_converted`. Group memberships are not transferred — no stub means no taxonomy-correct recipient. No redirect stub is created. Sticky assignments were captured at `pre_delete_term` time (before `delete_term_taxonomy` cleans them up) and are replayed here directly. |
| 10 | `htax_invalidate_term_caches_on_delete` | Invalidate term caches |
| 10 | `htax_cleanup_deleted_term` | Delete from `wp_htax_term_relationships` (all relationship types) + term meta + `_htax_sticky_{term_id}` post meta |
| 15 | `htax_clear_ajax_caches` | Clear autocomplete caches |

The two priority-10 handlers are independent (different tables) and have no ordering dependency.

### Event: `set_object_terms`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `on_term_relationships_added` | Delta-based incremental index update — diffs the indexed source terms against the post's actual terms read from the database (the hook's `$terms` holds only the appended terms on `wp_add_object_terms()`; `deleted_term_relationships` → `on_term_relationships_removed()` reuses the same path); targeted count cache clear |

**Incremental update:** Queries current source terms from index (1 query), calculates delta, deletes removed term relationships by `source_term_id` (1 query), inserts new ones (~4–9 queries per new term). Approximately 5× faster than full rebuild for typical 1–3 term changes. The inserted rows carry the column-default strength, so when terms were added the post is also queued (`queue_post_reindex()`) for a background reindex that computes their strengths.

### Event: `delete_post` / `trash_post`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_cleanup_sticky_on_delete` | Delete sticky post meta (`delete_post` only) |
| 10 | `on_post_delete` (search index) | Delete from search index (both hooks) |

### Event: `save_post`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_sync_post_text_row` | Upsert row in `wp_htax_post_text` for published, draft, pending, future, and private posts using `htax_extract_post_body()`; delete row for trash and auto-draft. Skips revisions and autosaves. Draft rows enable content-based tag suggestions in the editor. Force-deleted posts that bypass trash may leave an orphan row until the next full rebuild. |
| 20 | `on_post_saved` (search index) | Queue a published, indexed-type post for reindex so its stored strengths reflect the post text just refreshed at priority 10. Skips revisions and autosaves. |

### Event: `wp_update_comment_count`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_sync_post_text_comment` | Update `comment_count` and `last_comment_gmt` in `wp_htax_post_text`. No-ops if the post row does not exist in the table. |

### Event: `transition_post_status`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `on_post_status_change` | Remove from index if unpublished; queue reindex if newly published |

Skips if both old and new status are the same publish-state. When a scheduled post auto-publishes via cron, the transition fires and triggers a fresh index build.

### Custom Actions (Relationship Changes)

Fired by `htax_save_term_relationships()` and `htax_set_redirect()` / `htax_remove_redirect()` when relationships change:

| Action | Parameters | Index Strategy |
|--------|-----------|----------------|
| `htax_includes_changed` | `$term_id, $taxonomy, $new_included, $old_included` | Direct SQL: update container rows in posts with newly/no-longer-included terms |
| `htax_containers_changed` | `$term_id, $taxonomy, $new_containers, $old_containers` | Direct SQL delta: DELETE removed + INSERT added container rows |
| `htax_excludes_changed` | `$term_id, $taxonomy, $new_excluded, $old_excluded` | Queue reindex of posts with included group members (exclusion logic too complex for SQL) |
| `htax_included_groups_changed` | `$term_id, $taxonomy, $new_groups, $old_groups` | Queue reindex of posts with members of affected groups |
| `htax_custom_inclusions_changed` | `$term_id, $taxonomy, $affected_term_ids` | `on_custom_inclusions_changed()`: queue reindex of posts with the affected (included-side) terms |
| `htax_container_groups_changed` | `$term_id, $taxonomy, $new_groups, $old_groups` | Direct SQL delta via unified container handler (groups expanded by cache) |
| `htax_redirect_changed` | `$term_id, $taxonomy, $new_target_id, $new_taxonomy, $old_target` | DELETE old redirect_target rows + INSERT new ones; on first redirect also DELETE `container` entries for the term and, for hierarchical taxonomies, DELETE `hierarchy` entries where term is ancestor or where descendants point to ancestors-via-this-term. `$old_target` is `null` on first redirect, WP_Term on target change. `htax_term_archive_changed` fires only when `$old_target` is null — changing an existing redirect's target has no archive effect |
| `htax_redirect_removed` | `$term_id, $taxonomy, $old_target_id, $old_taxonomy` | DELETE redirect_target rows for this source term; restore `container` entries from preserved inclusions; for hierarchical taxonomies, restore `hierarchy` entries via `on_hierarchy_parent_restored()` |
| `htax_term_groups_changed` | `$term_id, $taxonomy, $new_groups, $old_groups` | Queue reindex of posts with the term + containers + terms with this group included |
| `htax_term_visibility_changed` | `$term_id, $taxonomy` | Direct SQL UPDATE of `is_hidden` flag for all rows where changed term appears as `source_term_id` or `term_id`; no reindex needed |
| `htax_relationship_saved` | `$source_term_id, $target_term_id, $type_slug` | Fired by `htax_add_term_relationship()` after a relationship row is inserted; queues reindex for both source and target terms |
| `htax_relationship_deleted` | `$source_term_id, $target_term_id, $type_slug` | Fired by `htax_remove_term_relationship()` after a relationship row is deleted; queues reindex for both source and target terms |

**Dual handler architecture:** Each action has two handlers at priority 10 — one for index updates, one for cache invalidation. Both process old and new relationship values for complete coverage.

**Type registration action:** `htax_relationship_types` fires at the end of `htax_register_builtin_relationship_types()` (init priority 0), after all four built-in types are registered. Plugin code that calls `htax_register_relationship_type()` in PHP should hook here rather than using `init` directly, to guarantee built-in types are already registered when the hook fires.

In addition to the per-operation actions above, `htax_term_archive_changed( $term_id, $taxonomy )` is the unified signal for any change to a term's archive composition. It fires for: every relationship save/delete; a term's first redirect (not target changes); redirect removal; term parent changes (via `htax_fire_archive_changed_on_hierarchy` in `cache.php`, at `edited_term` priority 11, firing for old/new parent ancestor chains); post-term assignment changes (fires for directly-assigned terms and their containers/ancestors via `htax_expand_term_ids_for_contrib_invalidation`); post publish/unpublish; and sticky post changes. Internally, HyperTaxonomy hooks it at priority 10 to invalidate the `htax_contrib_` paginated archive cache (`htax_invalidate_contrib_cache`). The named callback `htax_fire_term_archive_changed` (also in `cache.php`) is used by `add_action` registrations in `actions.php` to bridge fine-grained relationship actions to this signal. External integrations can hook it to invalidate their own derived data without subscribing to each fine-grained action.

---

