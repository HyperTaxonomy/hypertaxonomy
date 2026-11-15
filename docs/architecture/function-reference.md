---
title: Function Reference
parent: Architecture
nav_order: 2
---

# Function Reference
{: .no_toc }

Functions and methods by module, chosen by one rule, used across the suite's plugins. Listed: the public API (documented in the [Developer Reference](../developer-reference/)) and anything called from another file, which is what contributors reuse. Not listed: hook callbacks, of which the [Hook Reference](hook-reference.html) covers the registrations you can't guess from the code (search for the others by name); screen wiring (rendering, enqueueing, menu and column registration, constructors); overrides of a base class's method; and anything used only inside its own file or class, which is best read in the code.

Term links, external posts, feeds, the peer post shortcodes, and link types belong to TermConnect; see the [TermConnect Developer Reference](https://hypertaxonomy.org/termconnect/developer-reference/).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Core

### Plugin Configuration & Lifecycle

**Core (includes/core/functions.php)**

Public API + plugin lifecycle functions. Does not contain internal utilities — those live in helpers.php.

- `htax_health_check()` - Plugin health diagnostics
- `htax_get_setting( string $key, $default = null )` - Read one value from the `htax_settings` option, with a default when unset
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

**Resumption:**
No action required after the third-party plugin is reactivated. `htax_is_taxonomy_enabled()` and `htax_get_enabled_taxonomies()` will return the correct results on the first request after the taxonomy is registered again. Stale index rows are cleaned up incrementally; a manual rebuild via Settings → HyperTaxonomy → Search → General produces a fully clean state immediately.

**`includes/classes/class-capabilities.php`**

- `HyperTaxonomy_Capabilities::add_capabilities()` - Add capabilities to roles on activation
- `HyperTaxonomy_Capabilities::remove_capabilities()` - Remove capabilities on deactivation

### Utilities & Helpers (includes/core/helpers.php)

Shared internal utilities used throughout the plugin. Not part of the public API.

**SQL Utilities:**
- `htax_sql_placeholders()` - Generate SQL placeholders for prepared statements

**Term ID Utilities:**
- `htax_sanitize_term_ids()` - Sanitize and validate array of term IDs
- `htax_parse_term_ids($meta_value, $taxonomy = '')` - Parse a comma-separated term list into term IDs; numeric tokens are IDs, and when `$taxonomy` is given, non-numeric tokens are resolved as slugs within it (slugs are unique per taxonomy). Without a taxonomy, only numeric IDs are kept. Slugs resolve to IDs before any cache key is built. Filters out zero/negative values

**Term Validation:**
- `htax_get_valid_term(int $term_id)` - Safely get and validate a term; no taxonomy parameter (WP term IDs are globally unique); per-request cache (`htax_request_cache( 'valid_term' )`) capped at 500 entries (FIFO eviction) to prevent unbounded memory growth in long-running requests; entries are dropped on WordPress's `clean_term_cache` action, so a term updated or deleted earlier in the request is never returned stale
- `htax_is_per_object_transient( string $key ): bool` - Whether a transient is a per-term cache stored with autoload off (the key carries a term ID; not group member lists; false with an external object cache). See [Caching → Autoloading](caching.html)
- `htax_prime_transients( array $keys ): void` / `htax_prime_term_transients( int[] $term_ids, string[] $prefixes ): void` - Load many per-object transients in one query (`wp_prime_option_caches()`), absent ones included, so the reads that follow don't query one by one. Do nothing with an external object cache
- `htax_cached_media_library_months( $months )` / `htax_clear_media_library_months()` (term-icons.php) - Serve the media modal's month list from the `htax_media_months` transient; cleared on `add_attachment` / `delete_attachment`
- `htax_clear_term_inclusion_caches( int $term_id ): void` - Clear what an inclusion change on the other side affects: the term's `htax_inc_` / `htax_cont_`, contributing terms, count and post lists (not its redirect, groups or exclusions); used by the relationship cache handler for the terms in an action's old and new lists
- `htax_clear_term_archive_caches( int $term_id ): void` - Clear what a change of the term's own inclusions affects: its `htax_inc_`, contributing terms, count and post lists (not its containers); used where only that side of a relationship changed
- `htax_get_term_data_cache_version(): int` / `htax_bump_term_data_cache_version(): void` - The `htax_term_data_version` option in the search widget's cached term data keys, and its bump (hooked to term, membership, visibility, icon, archive and settings changes)
- `htax_prime_term_list_caches( $terms )` - `get_terms` filter (priority 5) on the term list screens (`edit-tags.php`): loads, for all listed terms at once, their redirect and group caches, the terms redirecting to them, their groups' terms and their icon attachments
- `htax_request_cache( string $group ): array&` / `htax_clear_request_cache( string $group, $key = null )` - Named per-request caches. Used instead of function-level statics wherever the cached data can change later in the same request, so the code that invalidates the data (or its transient) can clear the per-request copy too. Groups: `redirect_target`, `redirect_term_ids`, `valid_term`, `taxonomy_enabled`, `indexed_post_types`, `enabled_taxonomies`.

**Admin Notices:**
- `htax_display_queued_notices()` - Display notices queued from previous requests

**Security:**
- `htax_check_ajax_rate_limit( string $action, int $limit = 0 ): bool` - Per-minute request limit for AJAX handlers, counted per logged-in user or per visitor IP (`htax_rate_limit_client_ip` filter); `$limit = 0` uses the Requests per minute setting (PowerSearch and the search widget), the admin handlers pass their own. Counts live in the object cache when persistent, else in one transient per client and action
- `htax_verify_term_nonce()` - Verify WordPress term edit/add nonces; accepts three nonce sources: `_wpnonce` (action `update-tag_{id}`, full edit form), `_wpnonce_add-tag` (action `add-tag`, AJAX new-term form), and `_inline_edit` (action `taxinlineeditnonce`, WP Quick Edit). The `htax_save_term_data` early-return guard checks for the presence of any of these three fields before calling this function.

**Logging & Error Handling:**
- `htax_log_debug()` - Debug logging (WP_DEBUG + WP_DEBUG_LOG, supports verbose backtrace mode)
- `htax_log_error()` - Error logging (always logs, supports verbose backtrace mode)
- `htax_handle_db_error()` - Check wpdb->last_error; returns `$default` (and logs) on error, returns `false` when no error. Callers use `if ( false !== $err = htax_handle_db_error(...) ) { return $err; }` to short-circuit on failure.
- `htax_allowed_form_html(): array` - Allowed tags and attributes for `wp_kses()` when echoing markup built from escaped parts: term checklists, term pills, the archive icon and the archive description's appended HTML. Keeps forms, inputs, buttons, links, images and lists (with `data-*` and common `aria-*` attributes); strips scripts and other tags
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
  - `proximity` key: array of parsed `near:` tokens (see [Locations & Proximity Search](../developer-reference/locations-proximity.html)). Empty when As the Crow Flies is inactive or no proximity operators are present.
  - `$near_country`: ISO 3166-1 code passed down to proximity tokens for postal-code format validation and centroid lookup. Defaults to `'US'`.
  - Used by all three search systems: post list, advanced widget, PowerSearch
- `htax_get_slug_match_term_ids( string $slug, string $taxonomy = '' ): array` — term IDs with exactly this slug, in one taxonomy or (empty) every enabled taxonomy except Term Groups; used by PowerSearch's singular operators (`tag:`, `category:`, `term:`, custom singular labels)
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

### Cache Management (includes/core/cache.php)

**Transient wrappers:**
- `htax_get_transient( $key )` / `htax_set_transient( $key, $value, $expiration )` / `htax_delete_transient( $key )` - Transient API wrappers used for every HyperTaxonomy transient; resolve the storage key via `htax_transient_key()`

**Nuclear (full-reset):**
- `htax_clear_all_caches()` - Nuclear option: deletes all `_transient_htax_*` entries. Per-type variants: `htax_clear_relationship_caches()`, `htax_clear_search_caches()` (htax_contrib_*), `htax_clear_autocomplete_caches()` (htax_ac_*), `htax_clear_sticky_caches()` (htax_sticky_*), `htax_clear_shortcode_caches()` (htax_pl_*, htax_total_*)
- `htax_clear_caches_on_feature_change( $old_value, $new_value )` - On `update_option_htax_settings`: clears every cache when any per-taxonomy feature flag changed, since rendered outputs (post lists, contributing terms) are computed with the flags applied

**Per-term invalidation:**
- `htax_delete_term_transients( int $term_id )` - Delete all transients for a single term: core logical caches (`htax_inc_`, `htax_cont_`, `htax_excl_`, `htax_redir_`, `htax_groups_`), contrib cache, all direct relationship caches for every registered type via `htax_delete_custom_rel_caches_for_term()` (covers `htax_crel_`, `htax_crel_rev_`, `htax_direct_rel_`, `htax_direct_rel_grp_` for each slug including core `'includes'`), and post list shortcode caches. After clearing, calls `htax_queue_term_cache_rebuild()` to schedule async re-warming.
- `htax_invalidate_term_caches_on_delete( $term_id_or_term, $tt_id = 0, $taxonomy = '' )` - Full cache invalidation for a deleted term. Uses a **read-before-clear** pattern: reads T's outgoing direct caches (`htax_direct_rel_includes_T`, `htax_direct_rel_grp_includes_T`, `htax_direct_rel_{slug}_T`, and `htax_direct_rel_grp_{slug}_T` for all non-core slugs) *before* clearing T's own transients. Steps: (1) PRE-CLEAR: if all those caches are warm, the outgoing data is fully available with no DB queries; if any are cold, falls back to a broad `SELECT … WHERE source_term_id = T` query and parses rows into categorized arrays (`$out_inc_ids`, `$out_inc_grp_ids`, `$out_crel_ids[slug]`, `$out_crel_grp_ids[slug]`); (2) clears T's own caches via `htax_delete_term_transients(T)`; (3) surgically updates member-group caches; (4a) for incoming references (A→T rows): calls `htax_reload_term_caches_on_edit(A)` — when A is a group (G→T row), also calls `htax_clear_member_caches_for_groups([G], 'inc')` to clear `htax_inc_M` for members of G and ancestor groups; (4b) for outgoing references, iterates flat pre-read arrays: `$out_inc_ids` → deletes `htax_cont_U` + `htax_reverse_rel_includes_U`; `$out_inc_grp_ids` → deletes G's own `htax_reverse_rel_includes_G` then calls `htax_clear_member_caches_for_groups([G], 'cont')`; `$out_crel_ids[slug]` → deletes `htax_crel_rev_{slug}_U` + `htax_reverse_rel_{slug}_U`; `$out_crel_grp_ids[slug]` → deletes G's own `htax_reverse_rel_{slug}_G` then calls `htax_clear_member_caches_for_groups([G], 'crel_rev', slug)`; (5) clears caches for terms related via T's group memberships
- `htax_reload_term_caches_on_edit( int|WP_Term $term_id_or_term, int $tt_id )` - Invalidate all caches for a term (no eager reload — lazy-load on next access); used for terms related to a deleted term. Term edits use `htax_handle_term_rename()` instead
- `htax_handle_term_rename( int $term_id, int $tt_id, string $taxonomy )` - On `edited_term`, when the name changed (pre-edit name from `htax_term_data_{tt_id}`): queues the term's posts for reindexing (built-in strengths score the name), clears the counts and contributing terms of the archives that show its posts, and the `htax_inc_*` / `htax_cont_*` lists that contain it (ordered by name). Skips groups and disabled taxonomies
- `htax_invalidate_archives_on_visibility_change( int $term_id, string $taxonomy )` - On `htax_term_visibility_changed` (priority 20): clears the counts and contributing terms of the archives that show the term's posts; not for groups
- `htax_delete_term_cache_family_batch( int[] $term_ids, string[] $prefixes, string[] $suffixes = [''] )` - Delete the given per-term cache families (such as `htax_inc_`) for several terms in one query

**Targeted caches:**
- `htax_clear_count_cache()` - Clear total post count caches (htax_total_*)
- `htax_clear_ajax_caches()` - Clear autocomplete caches
- `htax_delete_transients_by_prefix( string $prefix )` - Delete all transients whose key starts with `$prefix` (both value and timeout rows); used for bulk-clearing type-scoped custom rel caches (`htax_crel_{slug}_` and `htax_crel_rev_{slug}_`), count caches, autocomplete caches, sticky post caches, and similar prefix-keyed groups

**Group-aware invalidation:**
- `htax_invalidate_group_relationship_caches( array $group_ids ): array` - Invalidate `htax_inc_*`, `htax_crel_{slug}_*`, and `htax_crel_rev_{slug}_*` caches for all terms that include or are container-related to the given groups (and their ancestors/descendants). Runs two SQL queries: (A) inclusions fwd — collect all `source_term_id` values from T→G rows where G is in `groups_with_ancestors`; (B) inclusions rev/container — collect all `target_term_id` values from G→T rows where G is in `groups_all` (ancestors + descendants). The including terms (A) lose their `htax_inc_*`, count, contributing terms and post lists; the container-group targets (B) only their `htax_cont_*` — their other caches are unaffected. Returns both ID lists (`including`, `included`), which `htax_update_group_membership_caches()` uses to clear the joining or leaving term's own container list (when A is not empty) and inclusions and archive caches (when B is not empty). Each family is deleted for all terms in one query (`htax_delete_term_cache_family_batch()`). Custom rel caches (sections C+D) are still cleared per-term via `htax_delete_transient()` since they are slug-keyed and not covered by the batch function. Hooked to `htax_async_group_propagate` cron event (fired by `htax_update_group_membership_caches`); also called directly by `htax_invalidate_caches_on_group_reparent`.
- `htax_clear_member_caches_for_groups( array $group_ids, string $mode, string $cslug = '' ): void` - Clear a specific transient for all members of the given groups. Expands groups upward (`htax_get_group_ancestors`) for `'inc'` mode, or downward (`htax_get_group_hierarchy`) for all other modes, then queries `member_of` rows to find members and deletes the appropriate transient per member. Modes: `'inc'` deletes `htax_inc_M`; `'cont'` deletes `htax_cont_M`; `'crel'` deletes `htax_crel_{cslug}_M`; `'crel_rev'` deletes `htax_crel_rev_{cslug}_M`.
- `htax_invalidate_caches_on_group_reparent( int $term_id, int $tt_id, string $taxonomy ): void` - On `edited_term` (priority 12), when a group's parent changed: `htax_invalidate_group_relationship_caches()` for the old and new parent chains, then `htax_clear_member_caches_for_groups( …, 'inc' )` for their members

**Frontend / shortcode caches:**
- `htax_invalidate_contrib_cache( int|int[] $term_id )` - Delete all `htax_contrib_{term_id}_p*` pages and the `htax_tcount_*` counts for one term or several, in one query; deduped per request (`contrib_swept` request cache)
- `htax_expand_term_ids_for_contrib_invalidation( int[] $term_ids, string $taxonomy ): int[]` - The archives that show posts of the given terms: the terms, their containers, their ancestors and the terms that include an ancestor
- `htax_invalidate_post_list_cache( int[] $term_ids )` - Pattern-delete `htax_pl_*` shortcode caches for given term IDs; over 100 terms clears all of them
- `htax_get_post_index_term_ids( int|int[] $post_ids ): int[]` - Distinct term IDs in the search index for one or more posts
- `HyperTaxonomy_Transient_Batch` - Holds non-expiring transients set during its lifetime and writes them in one statement; created by term shortcode handlers

**Tracing:**
- `htax_trace( string $msg ): void` - Write a timestamped trace line to the debug log when `HTAX_TIMING` is on

**Async cron hooks (registered in includes/hooks/actions.php):**
- `htax_async_group_propagate` → `htax_invalidate_group_relationship_caches( array $group_ids )` — Kept only for events scheduled by earlier code; `htax_update_group_membership_caches()` now calls `htax_invalidate_group_relationship_caches()` directly.
- `htax_resync_redirect_term_inclusions( $term_id )` (class-search-index.php) - On `htax_redirect_changed` / `htax_redirect_removed`: clears the caches and re-syncs the container index rows of every term in the redirecting term's stored inclusion set.

**`includes/core/cache.php`**

- `htax_fire_archive_changed_on_hierarchy( int $term_id, int $tt_id, string $taxonomy )` - Fire htax_term_archive_changed for the old and new parent chains, and for the terms that include any of them, when a term's WP parent changes.
- `htax_fire_term_archive_changed( int $term_id, string $taxonomy )` - Fire the htax_term_archive_changed action.
- `htax_fire_archive_changed_for_containers( $term_id, $taxonomy, $new_containers, $old_containers )` / `htax_fire_archive_changed_for_container_groups( $term_id, $taxonomy, $new_groups, $old_groups )` - On `htax_containers_changed` / `htax_container_groups_changed`: fire htax_term_archive_changed for the containers added or removed, or for the direct members of the container groups added or removed and of their parent groups (the term's own archive doesn't change). Without lists (API calls) nothing fires here; the caller fires `htax_includes_changed` for the container side

## Terms & Groups

### Term Groups

**Membership CRUD (includes/core/group-functions.php)**

Membership CRUD + group taxonomy queries. Handles assigning terms to groups and querying the group taxonomy.

- `htax_get_all_term_groups(): WP_Term[]` - Every Term Group, as `group_id => WP_Term`, ordered by name. Used externally by As the Crow Flies.
- `htax_get_term_groups()` - Get groups assigned to a term as WP_Term[] ordered by name; reads the IDs from `htax_get_term_group_ids()` and the groups from the term cache (no term query per call)
- `htax_get_term_group_ids( int $term_id ): int[]` - Get IDs of groups a term belongs to (cached as `htax_groups_{term_id}`, eager reload)
- `htax_get_group_hierarchy()` - Expand parent groups to include all descendants; uses `_get_term_hierarchy('group')` (WP object cache) for BFS — no extra DB query after first call per request
- `htax_set_term_groups()` - Replace all group assignments for a term
- `htax_update_group_counts( array $group_ids ): void` - Recount groups' stored counts (direct member terms) through `wp_update_term_count()`, so it honors `wp_defer_term_counting()`. Called on `htax_term_groups_changed` and when a member term is deleted
- `htax_repair_group_counts(): void` - Recount every group; runs on activation
- `htax_group_archive_has_posts( int $group_id ): bool` - Whether the group's archive shows a published post: runs `htax_build_group_tax_query()` for one post, with post types chosen the way WordPress chooses them for a taxonomy archive. Used by the sitemap filter to list only non-empty group archives

**Groups-as-inclusion-sources (includes/core/term-groups.php)**

Groups-as-inclusion-sources feature. Handles group-based inclusion and container relationships for term archives.

- `htax_get_terms_in_groups()` - Get all terms from specified groups (with hierarchy expansion + per-group caching); none for a taxonomy with Group Assignment off (`htax_load_terms_in_groups()` checks it before reading the cache)
- `htax_get_terms_from_included_groups()` - Get WP_Term[] from a term's included groups
- `htax_validate_and_filter_group_relationships()` - Validate group relationships (hierarchy conflict detection)

**Term Groups admin savers (includes/admin/term-edit.php)**
- `htax_save_term_groups( int $term_id )` - Save Term Groups assignments from the checklist field. Hooked on `edited_term` (priority 10) and `create_term` (priority 4), so it runs before `htax_save_term_data()` validates relationships. Admin-only (term-edit.php). Ignores requests without `htax_groups` in `$_POST`.

### Group Taxonomy (includes/core/taxonomies.php)

- `htax_register_group_taxonomy()` - Register the `group` taxonomy
- `htax_update_group_term_count( $terms, $taxonomy )` - The group taxonomy's `update_count_callback`: a group's count is its number of direct member terms, not posts, in taxonomies that are enabled and have Group Assignment on

### Term Visibility (includes/core/term-visibility.php)

Hidden term logic: permission checks, write-time cascade, and frontend query filtering. Applies to all taxonomies including `group` when the `visibility` feature is enabled for it (Settings → Features → Term Groups → Enable Term Group visibility options).

**Read / permission checks:**
- `htax_is_term_hidden( $term_id )` - Returns true if the term has `_htax_is_hidden` meta set
- `htax_remove_hidden_term_ids( array $term_ids ): array` - Leaves hidden terms out of a list of term IDs for every viewer; used for relationship data (REST relationship fields), which describes the public vocabulary
- `htax_can_see_hidden_terms( $taxonomy = '' )` - Whether the current user can see hidden terms. In admin requests (`htax_is_admin_request()`), `manage_term_visibility` or `manage_term_relationships` grants access, so relationship editors always see the terms their changes affect. Otherwise, and for anyone without either, the `hidden_terms_visibility` setting decides: `all`, `post_editors` (`edit_posts`), or `none`. So `none` hides terms from Administrators on the front end.
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

### Term Conversion (includes/core/term-conversion.php)

Moves terms between taxonomies, natively or by detecting conversions made by other plugins.

- `htax_convert_term( int $term_id, string $target_taxonomy, bool $create_redirect = true )` - Convert one term; returns a result array or `WP_Error`. With a redirect, the original term stays as a stub in the old taxonomy; without one, it is replaced and the new term claims its slug. Returns `WP_Error`, removing the new term, if moving posts fails.
- `htax_convert_terms_batch( array $term_ids, string $from_taxonomy, string $to_taxonomy, bool $create_redirect = true ): array` - Convert several terms, parents first, and return a summary
- `htax_create_redirect_term( $name, $old_taxonomy, $target_term_id, $target_taxonomy, $old_term_data = null )` - Create a redirect stub pointing to the converted term

**`includes/core/term-conversion.php`**

- `htax_get_taxonomy_singular( $taxonomy )` - Return the singular label for a taxonomy.

### Term Merge (includes/core/term-merge.php)

- `htax_merge_terms( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect = false )` - Move post assignments, group memberships, inclusions, exclusions, custom relationship types, and sticky pins to the target in a transaction, then delete the sources and optionally create redirect stubs. A source's icon and hidden status aren't transferred. Returns `{posts_reassigned, terms_deleted, redirects_created, target_id}`, or `WP_Error` after rolling back if a write fails. Fires `htax_before_merge_terms` and `htax_after_merge_terms`.

### Term Meta (includes/core/term-meta.php)

**Stored keys:**
- `htax_stored_term_meta_keys(): array` - The `_htax_*` term meta keys HyperTaxonomy stores, each with a `portable` flag; portable keys travel in the export file (`_htax_icon_id` as `_htax_icon_url`). Export and import read this list
- `htax_sanitize_stored_term_meta( string $key, $value )` - Sanitize a stored key's value as the term edit screen does: flags to `'1'`, page sizes clamped to 1–100, sort orders re-serialized through `htax_parse_orderby()`; `''` when nothing valid remains (an empty sort order clears it; one with no valid field becomes `date:desc`, as on the edit screen). Used by the term edit screen and import

**Computed keys** (read-only `get_term_meta()` keys computed from existing caches; nothing stored; hidden terms left out for every viewer; see [Term Meta](../developer-reference/term-meta.html)):

## Relationships

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
- `htax_remove_custom_relationship_type( string $slug ): bool` - Remove a user-defined type: deletes its rows and passes each through `htax_handle_term_relationship_change()` while the type is still registered (for an inclusion-based type, the two terms' inclusion and container caches and the search index's container rows change), then removes it from `wp_options` and the in-memory registry and clears its cached rows by prefix (`htax_crel_`, `htax_crel_rev_`, `htax_direct_rel_`, `htax_reverse_rel_` and their `_grp_` variants, `{slug}_`); returns false for core types
- `htax_remove_invalid_term_relationships( string $slug ): int` - Delete rows that no longer satisfy the type's constraints. Applies to both core and custom types. Conditions checked: group+group rows (always invalid); `source_taxonomies`/`target_taxonomies` violations (group side exempt); cross-taxonomy and self-relation rows for `includes`/`excludes`; `member_of` rows where source is a group or target is not a group; `redirect` self-redirect rows and chain rows (where the target is itself a redirect source — chain rows use a JOIN-based DELETE since MySQL forbids same-table subqueries in a plain DELETE). Pre-reads affected term IDs before deleting, then calls `htax_delete_term_transients()` for each. Returns total rows deleted. Called after `htax_set_custom_relationship_type()` and during activation for all four core types plus all custom types.

**Query API:**
- `htax_get_related_terms( int $term_id, string $type_slug, bool $include_redirect_data = false, bool $skip_if_private = true ): WP_Term[]` - Get related WP_Term objects for a term filtered by exact slug (forward direction). **Non-core custom types only** — returns `[]` immediately for built-in slugs. Also returns `[]` when `$skip_if_private = true` (default) and the type has `is_private` set; pass `false` for local UI that legitimately needs private data. For non-core types, group targets are expanded to their member terms (via `htax_get_terms_in_groups()`). Direct targets are batch-fetched per taxonomy. Returns `WP_Term[]` indexed by term_id. Cached as `htax_crel_{slug}_{term_id}` (no TTL). Invalidated by: `htax_add_term_relationship`/`htax_remove_term_relationship` (source's cache), `htax_save_term_relationships` edit form path (edited term's own cache + all changed other-side terms), `htax_delete_term_transients` (term's own cache), `htax_invalidate_term_caches_on_delete` (source terms whose cache pointed to the deleted term), type-prefix clear on type save/delete, and `htax_invalidate_group_relationship_caches` when group membership or group hierarchy changes.
- `htax_get_reverse_related_terms( int $term_id, string $type_slug ): WP_Term[]` - Get WP_Term objects that have a relationship of this type pointing to this term (reverse direction). **Non-core custom types only** — returns `[]` immediately for built-in slugs. For non-core types, group sources are expanded to their member terms. Returns `WP_Term[]` indexed by term_id. Cached as `htax_crel_rev_{slug}_{term_id}` (no TTL). Invalidated by: `htax_add_term_relationship`/`htax_remove_term_relationship` (target's cache), `htax_save_term_relationships` edit form path (edited term's own cache + changed fwd targets' rev caches), `htax_delete_term_transients`, `htax_invalidate_term_caches_on_delete` (target terms whose cache contained the deleted term), type-prefix clear on type save/delete, and `htax_invalidate_group_relationship_caches` when group membership or group hierarchy changes.
- `htax_get_relationship_types_for_taxonomy( string $taxonomy ): array` - Return slug-keyed user-defined types where `show_in_ui` is true and `source_taxonomies` is empty or contains the given taxonomy; used by term-edit UI and the save orchestrator to iterate visible types for a term

**Term relationship write operations:**
- `htax_add_term_relationship( int $source_term_id, int $target_term_id, string $type_slug ): true|WP_Error` - INSERT IGNORE a relationship row; valid for `'includes'`, `'excludes'`, and all non-core types; `'redirect'` and `'member_of'` trigger `_doing_it_wrong()`. Validates term existence; enforces group-aware taxonomy constraints (group+group rejected; group side is exempt from source/target taxonomy restrictions; includes-mapped types require both non-group sides to be in the same taxonomy; includes-mapped types reject self-inclusions). For a symmetric type whose pair is already stored in the reverse direction, writes nothing and returns `true` (checked with `htax_symmetric_pair_exists()`). Invalidates per-term caches, fires type-specific change actions (`htax_includes_changed`/`htax_containers_changed` for `'includes'`, `htax_excludes_changed` for `'excludes'`, after clearing the source's `htax_inc_` and the target's `htax_cont_`), and fires `htax_relationship_saved`.
- `htax_handle_term_relationship_change( int $source_term_id, int $target_term_id, string $type_slug ): void` - After a row is added or removed: clears the row's raw caches on both sides and, for inclusion-based types and `excludes`, the source's inclusions and archive caches and the target's container list (members' caches for group rows), then fires the matching change actions with both terms marked fresh in the `term_caches_fresh` request cache
- `htax_symmetric_pair_exists( int $source_term_id, int $target_term_id, string $type_slug ): bool` - True when the type is symmetric and the target→source row already exists; always false for non-symmetric types. Used by `htax_add_term_relationship()` and the JSON importer so each symmetric pair is stored once
- `htax_remove_term_relationship( int $source_term_id, int $target_term_id, string $type_slug ): bool` - DELETE a relationship row; same type restrictions as `htax_add_term_relationship()`; fires type-specific change actions and `htax_relationship_deleted` on success.

### Term Relationships (includes/core/term-relationships.php)

**Low-level readers — direct DB / cache wrappers:**
- `htax_get_direct_rel_term_ids( int $term_id, string $slug )` - Get direct forward target IDs for a relationship type: raw T→U rows for the given slug, non-group targets only (cached as `htax_direct_rel_{slug}_{term_id}`). Valid for `'includes'`, `'excludes'`, and non-core types. Calling with `'member_of'` or `'redirect'` triggers `_doing_it_wrong()` and returns `[]` — use `htax_get_term_groups()` or `htax_get_redirect_target()` instead.
- `htax_get_reverse_rel_term_ids( int $term_id, string $slug )` - Get direct reverse source IDs for a relationship type: raw A→T rows for the given slug, non-group sources only (cached as `htax_reverse_rel_{slug}_{term_id}`). Same slug restrictions as `htax_get_direct_rel_term_ids`.
- `htax_get_direct_rel_group_ids( int $term_id, string $slug )` - Get direct forward group target IDs for a relationship type: raw T→G rows for the given slug, group targets only (cached as `htax_direct_rel_grp_{slug}_{term_id}`). Same slug restrictions as `htax_get_direct_rel_term_ids`.
- `htax_get_reverse_rel_group_ids( int $term_id, string $slug )` - Get direct reverse group source IDs for a relationship type: raw G→T rows for the given slug, group sources only (cached as `htax_reverse_rel_grp_{slug}_{term_id}`). Same slug restrictions as above.
- `htax_get_group_ancestors( int|int[] $group_ids ): int[]` - The given groups and all their parent groups (inputs included)
- `htax_get_included_term_ids( int|WP_Term $term, bool $include_redirect_data = false )` - Get IDs for the second OR clause of `htax_build_expanded_tax_query`: included terms plus all their descendants. Included terms' children are pre-expanded here because that clause uses `include_children => false`. The primary term's own children are **not** pre-expanded here — they are handled by the first OR clause (which uses `include_children => is_taxonomy_hierarchical`). Only called from `htax_build_expanded_tax_query`.

**Resolved readers — multi-path, WP_Term objects:**
- `htax_get_included_terms( int|WP_Term $term, bool $include_redirect_data = false, bool $include_disabled = false )` - Returns empty when `term_relationships` is disabled for the taxonomy unless `$include_disabled` (search index callers only); the cached set is feature-independent. Get WP_Term[] of included terms indexed by term_id (cached as `htax_inc_{term_id}`, as IDs, eager reload). No TTL — persists until explicitly deleted by `htax_delete_term_transients()`. `false` = cold (never computed or evicted); `[]` = warm with no relationships. Empty result is stored as `[]` so that a warm cache with no inclusions never triggers a DB query.
- `htax_get_container_terms( int|WP_Term $term, bool $include_disabled = false )` - Same `term_relationships` gating and `$include_disabled` opt-out (search index callers). Get WP_Term[] of container terms indexed by term_id (cached as `htax_cont_{term_id}`, as IDs, lazy-load; 3 paths: direct, group inclusion — terms including any of the term's groups or their ancestor groups — and container groups of any inclusion-based type, built-in or custom, expanded upward to ancestors). No TTL — persists until explicitly deleted by `htax_delete_term_transients()`. Same `false` vs `[]` sentinel convention as `htax_inc_{term_id}`.
- `htax_load_included_term_ids( $term, bool $include_redirect_data, bool $include_disabled ): int[]` / `htax_load_container_term_ids( $term, bool $include_disabled ): int[]` / `htax_load_synonym_term_ids( $term, bool $include_redirect_data ): int[]` - The cached ID sets behind the getters above (hidden terms included, ordered by name); `htax_load_included_terms()` / `htax_load_container_terms()` / `htax_load_synonym_terms()` turn them into WP_Term[] with `htax_get_terms_by_ids()`. Used where only IDs are needed (`htax_get_public_related_term_ids()`, synonyms)
- `htax_is_term_id_cache( $cached ): bool` / `htax_get_existing_term_ids_by_name( int[] $term_ids, string $taxonomy ): int[]` / `htax_get_term_taxonomy( int|WP_Term $term ): string` - Helpers for those loaders: recognize a cached ID list, keep the IDs that exist ordered by name in one query, and read a term's taxonomy
- `htax_capture_effective_sets_before_delete( $term_id, $taxonomy )` / `htax_mark_term_deleting( $term_id )` - `pre_delete_term` helpers: record a term's effective inclusions and containers for the deletion cache invalidation, and mark it as deleted through `wp_delete_term()` so `htax_cleanup_bulk_deleted_terms()` leaves it to `delete_term`
- `htax_get_synonym_terms( int|WP_Term $term, bool $include_redirect_data = false )` - Get WP_Term[] of the term's synonyms: the intersection of its effective inclusions and effective containers (`htax_load_synonym_terms()`), so any path counts in each direction — direct, inclusion-based custom type, group inclusion, container group — after exclusions. Display utility — not required for archive expansion. No cache of its own: reads `htax_inc_{term_id}` and `htax_cont_{term_id}`.
- `htax_get_public_related_term_ids( WP_Term $term, string $kind ): int[]` - IDs of a term's `includes`, `containers`, `synonyms` or `redirect` target with hidden terms left out for every viewer; the single source for the REST relationship fields and the computed term meta, so they always agree. Reads the same caches as the getters above
- `htax_get_excluded_term_ids( int $term_id, bool $include_redirect_data = false ): int[]` - Get excluded term IDs (cached as `htax_excl_{term_id}`); returns `[]` for redirect terms unless `$include_redirect_data` is true

**Redirect suppression pattern:**
All logical relationship getters suppress their results when the source term is a redirect and the taxonomy has the `redirects` feature enabled. Redirect terms preserve their stored rows in the DB and the cache, but callers see an empty result by default. Admin display passes `$include_redirect_data = true` to show the data grayed-out. The save handler (`htax_save_term_relationships`) skips processing for redirect terms to prevent the disabled form fields from wiping preserved data.

Functions that accept `$include_redirect_data`:
- `htax_get_included_terms( $term, bool $include_redirect_data = false, bool $include_disabled = false )`
- `htax_get_included_term_ids( $term, bool $include_redirect_data = false )`
- `htax_get_synonym_terms( $term, bool $include_redirect_data = false )` — redirect terms are also left out of the result set, since a redirect is never a container
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
- `htax_get_ids_of_redirects_to( $target_id, $taxonomies = null )` - Get terms redirecting to target (cross-taxonomy); answered from the per-request map when `htax_prime_redirects_to( int[] $target_ids )` has loaded it (term list screens), which a redirect change clears
- `htax_prewarm_redirect_cache()` - Bulk-fetch all redirect rows in one query, then batch-check target terms by taxonomy, and populate `htax_redir_{id}` transients. Call before bulk index loops to convert O(n) lazy queries into 1 + 1-per-taxonomy queries.
- `htax_set_redirect( int|WP_Term $term, int|WP_Term $target )` - Set redirect with circular detection and chain resolution; replaces the row in a transaction and returns false if a write fails, keeping the old redirect
- `htax_get_redirect_sources( int $target_term_id ): array` - Get the terms that redirect, directly or transitively, to a target term
- `htax_remove_redirect( $term_id )` - Remove redirect from term; returns false, with no hook fired, if the delete fails

**Save orchestration:**
- `htax_save_term_data( $term_id, $tt_id )` - Orchestrator that validates nonce once, checks permissions conditionally based on enabled features and form data, then calls all save functions (runs on both `edited_{$taxonomy}` at priority 20 and `create_{$taxonomy}` at priority 5 for early AJAX compatibility)
- `htax_save_term_relationships( $term_id, $tt_id )` - Save include/container relationships (called by orchestrator, which verifies the nonce); replaces rows in a transaction and rolls back if any write fails
- (`admin/term-edit.php`) `htax_save_archive_settings( $term_id )` - Save archive display settings; skips when customize_archives disabled (called by orchestrator)
- `HyperTaxonomy_Term_Icons::save_term_icon( $term_id )` - Save term icon if enabled for taxonomy (called by orchestrator)

**Cleanup:**
- `htax_cleanup_deleted_term( $term_id, $tt_id )` - On `delete_term`: removes all relationship rows where the term is source or target; clears `_htax_auto_redirect` meta from any terms that were redirecting to this term; clears `_htax_show_description` and `_htax_is_hidden` meta from the deleted term; deletes the `_htax_sticky_{term_id}` post meta entry and its transient
- `htax_cleanup_hierarchy_conflicts( $term_id, $tt_id, $taxonomy )` - Remove conflicts when hierarchy changes
- `htax_remove_hierarchy_conflict_rows( int $term_id, string $taxonomy ): bool` - Delete direct inclusion and exclusion rows that place an ancestor or descendant of a term in its relationship sets
- `htax_cleanup_symmetric_relationship_duplicates(): int` - For each symmetric custom relationship type, finds A→B + B→A duplicate row pairs and deletes the one where `source_term_id > target_term_id`, keeping a single canonical row per pair; called by `htax_repair_orphaned_term_relationships()` and on activation, and after a term merge; idempotent. A safety net: the writers already skip a pair stored the other way round
- `htax_repair_orphaned_term_relationships(): int` - Activation-time scan; (1) removes rows whose source term no longer exists, (2) removes rows whose target term no longer exists, (3) removes stale `_htax_auto_redirect` meta from terms with no redirect row, (4) calls `htax_remove_invalid_term_relationships()` for all four core types and every user-defined type enforcing taxonomy constraints and self-relation rules, (5) calls `htax_remove_duplicate_redirects()` to enforce the one-target-per-source invariant, (6) calls `htax_cleanup_symmetric_relationship_duplicates()` for all symmetric custom types; returns total rows deleted; idempotent

**Symmetric custom relationship types:**
User-defined types with `symmetric: true` have identical forward and inverse labels (e.g. "Related"). The term edit UI collapses both direction containers into one merged container: reverse pills (rows where another term points to this one) are shown with a dashed border and a `←` arrow after the label; forward pills (rows created from this term's edit page) are shown with a `→` arrow after the label. Adding via `+` always creates a forward row; removing either direction deletes the appropriate row. The save handler strips any forward-direction term IDs that already appear in the reverse direction, preventing A→B + B→A duplicates at write time. The autocomplete excludes all pills (both directions) so users cannot add a term already related in either direction.

**`includes/core/term-relationships.php`**

- `htax_get_term_relationship_ids( $term_id, $id_column, $where_column, $relationship_type, $extra_where = array() )` - Generic database query helper for term relationships
- `htax_get_terms_with_container_groups( $group_ids, $taxonomy )` - Get terms that have any of the specified groups as container groups

### Archive Expansion

**Term archives (includes/core/term-archives.php)**
- `htax_get_enabled_archive_taxonomy()` - Returns the current archive's taxonomy slug if HyperTaxonomy is enabled for it; null otherwise. Used as a guard by the sort-order block and archive query handlers.
- `htax_build_expanded_tax_query( int $term_id, string $taxonomy, ?WP_Term $term_obj = null )` - Build OR tax_query for a term's archive expansion; returns null if no HyperTaxonomy contributions or if `term_relationships` feature is not enabled for the taxonomy. Called by both `htax_modify_archive_query` and `htax_expand_secondary_query`.
- `htax_modify_archive_query()` - Modify main archive query for inclusions/redirects (delegates expansion to `htax_build_expanded_tax_query`). When an expanded `tax_query` is applied, WordPress's built-in per-taxonomy query vars (`tag_id`, `cat`, `category_name`, `tag`, `term`) are cleared to prevent WP from constructing a redundant native clause. The `taxonomy` query var is intentionally preserved so that `is_tax()`, breadcrumb plugins, and SEO plugins continue to identify the archive context correctly.
- `htax_get_contributing_terms_for_current_page()` - Get terms contributing to archive
- `htax_get_sticky_posts_for_term()` - Get sticky post objects for a term (taxonomy derived from the term). The `htax_sticky_{term_id}` cache holds only the validated post IDs — an empty list is cached too — and posts are loaded fresh on each call (all indexed post types)
- `htax_repair_orphaned_sticky_meta(): int` - Activation-time scan; finds all `_htax_sticky_*` post meta keys and deletes any whose term no longer exists; idempotent

**Integration API (includes/core/integration-api.php)**

Public integration API for third-party plugins.

- `htax_get_expanded_term_ids( int $term_id, bool $include_redirect_sources = false )` - Returns the complete flat list of term IDs whose posts appear in a term's archive: self + WP descendants (via `htax_get_visible_wp_descendants`) + all HyperTaxonomy-contributed terms from `htax_build_expanded_tax_query` (inclusions pre-expanded to include their own WP descendants). WP descendants of the primary term are always added explicitly regardless of whether HyperTaxonomy contributions are present. Taxonomy is looked up automatically. With `$include_redirect_sources`, also includes terms that redirect to this term. Applies `htax_expanded_term_ids` filter.
- `htax_expand_secondary_query( WP_Query $query )` - `pre_get_posts` handler (priority 10) that expands single-term tax_query clauses for enabled taxonomies in secondary/third-party queries. Controlled by `htax_expand_terms` query var or `expand_secondary_queries` global setting. Skips the main archive query.
- `htax_adjust_term_counts( array $terms, ?array $taxonomies, array $args )` - `get_terms` filter; skips post term queries (`object_ids`). Replaces native WP counts with expansion-aware counts: published posts of the taxonomy's post types, like WordPress's own counts, in batched queries per taxonomy (`htax_count_expanded_term_posts()`); counts are cached per term (`htax_tcount_`), and for the terms not cached the inclusion and redirect caches load in one query first. Frontend only, active only when `expand_secondary_queries` is on.
- `htax_get_local_post_scores( array $term_ids, array $args = [] ): array` - Score local posts for a set of terms using the search index, gated by each taxonomy's relationship features and by term visibility; returns `post_id => score`, or empty when the index table is unavailable. `$args['exclude']` omits post IDs; `$args['term_weights']` (`term_id => weight`) multiplies each term's score.
- `htax_get_post_term_strengths( int $post_id, array $term_ids = [] ): array` - A post's direct term strengths from the model selected for related posts, normalized so the strongest is 1.0; terms the model has not scored are omitted
- `htax_get_term_uris( int $term_id ): array` - External URIs for a term, from the `htax_term_uris` filter
- `htax_remove_terms_hidden_from_caller( array $terms ): array` (`includes/core/term-visibility.php`) - Applies hidden-term visibility to a shared-cache list for callers outside HyperTaxonomy
- `htax_get_term_external_posts( int $term_id, array $args = [] ): array` - External posts for a term, from the `htax_term_external_posts` filter; returns empty without firing the filter when the term is hidden from the current user. `$args` is passed to providers unchanged.
- `htax_get_peer_sites(): array` - Known peer sites, from the `htax_peer_sites` filter
- `htax_get_term_locations( int $term_id ): array` - Location rows for a term, from the `htax_term_locations` filter

**`includes/core/integration-api.php`**

- `htax_build_term_weight_multiplier( array $weights, string $term_col )` - Build a SQL `* (CASE …)` multiplier that scales a per-term aggregate by a caller-supplied weight map.

## Search & Ranking

### Search

**Search State — POST/Redirect/GET (includes/core/search-state.php)**

POST-mode forms submit once; the results pages are GET requests carrying `?htax_q=<token>`, so the theme's `/page/N/` links (which keep the query string), back/refresh, bookmarks and page caches work.
- `htax_is_search_state_request( ?bool $set = null ): bool` - whether this request's state came from a token (verified at submission, so the handlers skip their nonce check — the nonce would have expired or belong to another visitor).
- `htax_apply_search_page_to_query( WP_Query $query, array $post_ids, int $total, int $posts_per_page, int $paged )` - sets the main query to exactly one engine page: `post__in` in order, indexed post types, no stickies, and query var `htax_search_total`. With it, `htax_search_page_post_limits()` (`post_limits`) starts the LIMIT at row 0 — the LIMIT is kept because WordPress computes `max_num_pages` only for limited queries — and `htax_search_page_found_posts()` (`found_posts`) reports the true total.
- `htax_get_search_page_number( WP_Query $query ): int` - current page from `paged`, or `page` on singular page URLs.
- `htax_filter_search_query_text()` (`get_search_query`) - returns the `htax_search_text` query var when `s` was cleared by a HyperTaxonomy search, so theme titles show the query. While a search form renders (`htax_track_search_form_rendering()` on `pre_get_search_form` / `get_search_form` / `pre_render_block` / `render_block_core/search`; state in `htax_is_rendering_search_form()`), it is applied on native `?s=` searches, and on HyperTaxonomy results pages only when Site Search Integration (`always_enhance_search`) is on — otherwise that box would run WordPress's own search, so it stays empty.

**Search notices (includes/core/search.php)**
- `htax_add_search_notice( string $code, string $message )` / `htax_get_search_notices(): array` - per-request visitor messages (`$GLOBALS['htax_search_notices']`, keyed by code). Included as `notices` in widget and PowerSearch AJAX responses; `htax_print_search_notices()` (`loop_start` / `loop_no_results`, main search query, once) prints them on results pages.

**Post Text Sync (includes/core/search.php):**
- `htax_sync_post_text_row( int $post_id, WP_Post $post )` - `save_post` handler; upserts a row into `wp_htax_post_text` for published, draft, pending, future, and private posts using `htax_extract_post_body()` on `post_content`; deletes the row for trash and auto-draft. Skips revisions and autosaves. Draft rows allow the post editor to perform content-based tag suggestions against the same table public search queries use.
- `htax_extract_post_body( string $html ): string` - Converts raw `post_content` HTML to plain text for FULLTEXT indexing. Preserves semantically useful attribute values before stripping tags: `<a href>` URLs appended as `[URL]` after anchor text, `<img alt>` text, and `<abbr title>` / `<acronym title>` expanded forms appended after the abbreviated text. Tags are matched with a quote-aware attribute pattern (a `>` inside a quoted value doesn't end the tag), and the attribute is read with `htax_html_attribute_value()`, so its position and quote style don't matter. Then strips all remaining HTML with `wp_strip_all_tags()`. Struck-through content (`<del>`, `<s>`) is kept — authors use strikethrough as editorial rephrase and the wording remains meaningful.
- `htax_html_attribute_value( string $attrs, string $name ): ?string` - Reads one attribute's value from a tag's attribute string (double-quoted, single-quoted or unquoted), taking the attributes one at a time so text inside another attribute's value never matches; `null` when absent.
- `htax_sync_post_text_comment( int $post_id, int $new_count )` - `wp_update_comment_count` handler; updates `comment_count` and `last_comment_gmt` for rows that already exist in `wp_htax_post_text`. No-ops if the post is not in the table (trashed, auto-draft, or not an indexed post type).

**Search Enhancement:**
- `htax_parse_orderby()` - Parse orderby parameter string into array
- `htax_get_search_results_per_page()` - Default posts-per-page for all HyperTaxonomy search forms; returns `get_option('posts_per_page', 20)` so that the WordPress Reading setting is the single site-wide control. Shortcode-level `posts_per_page` attributes always override this.
- `htax_get_default_match_mode()` - Returns `'all'` or `'any'` from `htax_settings[default_match_mode]`; defaults to `'any'`.
- `htax_build_orderby_sql( $orderby_parsed, $post_alias = 'pt', $relevance_column = 'term_relevance' )` - Build a SQL `ORDER BY` clause from a parsed orderby array
- `htax_get_term_sort_order( $term_id )` - Get the parsed sort order for a term's archive
- `htax_encode_term_search( $search )` (`includes/core/functions.php`) - Convert term search text to the form WordPress stores term names in: `&`, `<`, and `>` as entities, quotes as typed. Used by block editor and Classic Editor tag suggestions, the term autocomplete, and the search widget's term search. Defined in `core/functions.php`.
- `htax_set_sql_orderby( WP_Query $query, array $orderby_parsed, int $term_id = 0 ): bool` - Sort a query in SQL when its sort order uses `last_comment`, `last_activity`, or `relevance`, which WP_Query can't sort by. Returns false when WP_Query can sort it itself. Without a term, `relevance` is dropped.
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
- `htax_parse_filter_state_ajax( $raw ): array` — Parses filter state from a JSON string POSTed as `htax_filter` in AJAX requests; preserves `term_match` keys from the client state.
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

**As the Crow Flies Integration (includes/integrations/class-atcf-integration.php)**

Always loaded; its hooks register only when `function_exists( 'atcf_get_term_ids_within_radius' )`. Hooks:
- `htax_pre_search_fields` — receives the full search query spec `{relation, fields, groups, text_search}` and resolves any `distance`-type fields in `$query['fields']` to `type:"hidden"` resolved fields containing matching term IDs before the SQL query runs. Prefers JS-provided coordinates (`_atcf_lat`/`_atcf_lng` request params) over postal-code centroid lookup; falls back to `atcf_get_visitor_location()`. Any `expand_types` value from the distance field is passed through unchanged to the resolved field; `normalize_expand_types()` in the search widget class handles final normalization at query time.
- `htax_allowed_field_types` — adds `'distance'` to the accepted field types so the AJAX handler does not reject distance fields
- `htax_term_locations` — provides As the Crow Flies location rows to HyperTaxonomy's filter, available through `htax_get_term_locations()`. REST responses get locations from `ATCF_REST_API::format_term_locations()` instead (see [REST API](#rest-api-includescorerest-apiphp) below)

The integration touches **none of As the Crow Flies' database tables directly** — all location data comes through that plugin's public API (`atcf_get_locations_within_radius()`, `atcf_get_term_ids_within_radius()`, `atcf_get_postal_centroid()`, `atcf_get_visitor_location()`, `ATCF_Locations::get_aggregated()`). The only direct DB queries HyperTaxonomy performs are against WP's own `term_relationships` and `term_taxonomy` tables to map term IDs to post IDs.

The `[ht_distance_field]` shortcode is registered unconditionally (so the tag is always recognized in content). When As the Crow Flies is inactive at render time, the shortcode outputs a notice for admins and nothing for other users.

**JavaScript event:**
- `atcf:termFilterInserted` — jQuery event fired by As the Crow Flies' admin term list script with the filter's container. `admin/js/htax-tables.js` listens for it to move the filter into HyperTaxonomy's filter row.

**`htax_search_proximity_where` filter (PowerSearch + search widget):**

Receives `(string $where, array $proximity, string $post_column)` and returns an SQL condition (no leading AND; `''` = none). `perform_indexed_text_search()` (PowerSearch) fires it when proximity tokens are present and append the result to the WHERE clause — before ORDER BY / LIMIT, so pages and totals are computed on the filtered set and the relative order of the remaining posts is unchanged. PowerSearch resolves the condition before its result-cache lookup and includes it in the cache key, since `near:me` depends on the viewer's location.

The integration's `build_proximity_where()` keeps a post when at least one of its terms in an eligible taxonomy (a public taxonomy enabled in As the Crow Flies) has a location in range of some positive clause. Posts with no eligible terms, or whose eligible terms have no locations, are dropped — their location is unknown. Positive clauses are unioned (in-range term IDs from every resolvable clause); unresolvable clauses are ignored, and when none resolves there is no positive condition. Negated clauses (`-near:`) drop posts in range of any of them. Hidden terms are left out for viewers who can't see them.

In-range term IDs come from As the Crow Flies' API (`atcf_get_locations_within_radius()`). `posts_with_terms_sql()` resolves them to `term_taxonomy_id`s and emits a correlated `EXISTS (SELECT 1 FROM term_relationships WHERE object_id = <post> AND term_taxonomy_id IN (…))`, which uses the table's `(object_id, term_taxonomy_id)` primary key per candidate post and lets an `ORDER BY … LIMIT` query stop once its page is full. On a 300k-post / 2.7M-relationship test set this measured ~70 ms for page 1 and ~120 ms at offset 5000 (vs ~0.5 s for an `IN (SELECT …)` semi-join and ~0.7 s / 90 MB for fetching the full ordered result and filtering it in PHP), with identical rows.

**Proximity syntax parsed by `htax_parse_search_query()` (only when As the Crow Flies is active):**

```
near:taxonomy:slug[/radius]   — reference: term's ATCF locations (+ WP descendants + HT inclusions)
near:X[/radius]               — X checked against country's postal format (uppercase):
                                 match → postal centroid; no match → slug lookup in all eligible taxonomies
near:me[/radius]              — reference: user location (_atcf_lat/_atcf_lng or IP geo fallback)
-near:...                     — negation; excludes posts in range
```

Default radius: 10, in the As the Crow Flies distance unit (`atcf_distance_units`). Country context (for postal format check and centroid lookup) is set by the `near_country` shortcode/block attribute (default `'US'`). When As the Crow Flies is inactive, `near:` text is not extracted and falls through to FULLTEXT search unchanged.

**Reference point aggregation for `near:taxonomy:term`:**

Reference locations are resolved via `ATCF_Locations::get_aggregated()`, which expands the scope to include WP taxonomy descendants (`get_term_children()`) and included terms (`htax_get_included_term_ids()`), and excludes hidden terms (`htax_get_all_hidden_term_ids()`). This means `near:geo:europe` finds locations across all European child terms, not just direct As the Crow Flies locations on the "europe" term itself.

**`includes/classes/class-power-search.php`**

- `HyperTaxonomy_Power_Search->search_form_shortcode( $atts )` - Search form shortcode
- `HyperTaxonomy_Power_Search->apply_score_thresholds( array &$config, array $source, string $prefix = '' )` - Apply minimum score thresholds from a source array into a search config array.
- `HyperTaxonomy_Power_Search->perform_indexed_text_search( $query, $config = array() )` - Run a PowerSearch query against the search index: text matching, term matches with relationship expansion, field operators and score thresholds; returns the page of `{post_id, relevance}` results and the total

**`includes/classes/class-search-widget.php`**

- `HyperTaxonomy_Search_Widget->extract_text_search_from_fields( $query, $default_min_length = 3 )` - Extract text search query and search_in parameter from a query spec.
- `HyperTaxonomy_Search_Widget->has_taxonomy_terms_in_fields( $query )` - Check if a query spec contains any taxonomy terms.
- `HyperTaxonomy_Search_Widget->get_posts_by_custom_sql( $query, $orderby_parsed, $posts_per_page, $page, $text_search_query = '', $min_score = 0, $search_in = 'all', $extra_where = '', $max_results = 0 )` - Run a search widget query (nested field groups, optional text search, ordering, paging, result cap) as one SQL query against the search index; returns the page of post IDs and the total

**`includes/core/search.php`**

- `htax_get_default_relevance_weights( $taxonomy )`
- `htax_get_enabled_taxonomies_with_search()` - Get enabled taxonomies with search enhancement

### Search Index (includes/classes/class-search-index.php)

`HyperTaxonomy_Search_Index_Manager` keeps `wp_htax_search_terms` and `wp_htax_post_text` current. Its `on_*` handlers are listed in the [Hook Reference](hook-reference.html).

- `htax_get_search_index()` - Get the manager instance (created on first call, at `plugins_loaded`)
- `HyperTaxonomy_Search_Index_Manager::queue_post_reindex( $post_ids )` - Queue posts for reindexing; `process_reindex_queue()` runs at `shutdown`, and `process_async_reindex_batch()` handles large batches
- `HyperTaxonomy_Search_Index_Manager::rebuild_indexes(): void` - Full rebuild, run from the `htax_rebuild_indexes` cron event
- `HyperTaxonomy_Search_Index_Manager::on_term_created( int $term_id, int $tt_id, string $taxonomy )` - On `created_term` (priority 20), for a new term under a parent in a hierarchical taxonomy: queues the posts with container rows on its ancestors, since a container's rows cover its descendants
- `HyperTaxonomy_Search_Index_Manager::refresh_container_rows( int[] $term_ids )` - Re-sync the given terms' container index rows from their recomputed container sets; used where relationships move between terms (merges, group deletion, single-row term-to-group inclusions)
- `HyperTaxonomy_Search_Index_Manager::on_group_assignment_changed( $old_value, $new_value )` - On a settings save that turns a taxonomy's Group Assignment on or off: recounts every group and queues the taxonomy's posts for reindexing (Tags and Categories count as on until their features are saved)
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

## Front End & Blocks

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

### Shortcodes and Blocks (includes/shortcodes/, includes/classes/class-blocks.php)

**`includes/shortcodes/class-filter-options.php`**

- `htax_get_archive_exclude_term()` - Get the term ID to exclude from term filter pools when rendering in an archive context.

**`includes/shortcodes/class-shortcode-base.php`**

- `HyperTaxonomy_Shortcode_Base->resolve_term_ids( array $atts, string $taxonomy )` - Resolve the final include/exclude term ID sets from shortcode attributes.
- `HyperTaxonomy_Shortcode_Base->load_redirect_terms( $taxonomy )` - Load redirect terms for a taxonomy
- `HyperTaxonomy_Shortcode_Base->is_term_redirect( $term )` - Check if a term is a redirect term
- `HyperTaxonomy_Shortcode_Base->get_redirect_target( $term )` - Get redirect target information for a term
- `HyperTaxonomy_Term_Display_Base->get_terms( $taxonomy, $additional_args = array() )` - The terms a term list, cloud, index or groups shortcode shows, applying its group and term include/exclude attributes in a fixed order (include groups, exclude groups, include terms, exclude terms)
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

### Native Block Extensions (includes/core/native-blocks.php)

Every function in this file is a hook callback; see [Feature Internals → Native Block Group Filter](feature-internals.html#native-block-group-filter).

## REST & Integrations

### REST API (includes/core/rest-api.php)

- `htax_rest_field_requested( ?WP_REST_Request $request, string $field ): bool` - Whether a REST response should include a field: true when `_fields` names it or is absent, so expensive extra fields are computed only when asked for

**Field builders:**
- `htax_rest_build_icon_data( WP_Term $term )` - Build `htax_icon` REST field value (null when no icon set)
- `ATCF_REST_API::format_term_locations( int $term_id )` - **Owned by As the Crow Flies.** Returns `htax_locations` REST field value for a term: Schema.org Place array, or null when no locations. Caches per term_id for request lifetime. HyperTaxonomy calls this with a `method_exists` guard; it uses `ATCF_Locations::get_by_term_id()` internally so no taxonomy parameter is needed.

**Term endpoint filters & registration:**
- `htax_filter_rest_prepare_term()` - Block hidden terms with 404 (always enforced); inject sparse `htax_*` fields when data exists. Enrichment fields are also skipped when `_locale=user` is present in the request — `wp.apiFetch` always adds this param so the block editor never receives the enrichment fields (it reads HyperTaxonomy display data from PHP-localized `htaxTermMeta` instead); external REST clients, which do not send `_locale=user`, receive full enrichment. Hidden-term 404 enforcement fires unconditionally regardless of `_locale=user`.

**Posts endpoint extensions:**
- `htax_term_peers` field — registered by TermConnect (when active) on post REST responses. Returns peer connection data for all terms on returned posts. See [TermConnect's REST API](https://hypertaxonomy.org/termconnect/developer-reference/rest-api.html#post-endpoints).

**Relationship type endpoints (includes/core/relationship-types-rest.php):**

**`_fields` awareness.** All fields HyperTaxonomy adds are gated by `htax_rest_field_requested( ?WP_REST_Request $request, string $field ): bool`. Returns `false` if: (a) the field is globally disabled by the Term Inclusion Relationships setting (`htax_includes`, `htax_synonyms`), OR (b) a `_fields` parameter was supplied and does not include this field. Returns `true` when `_fields` is absent. Callers should specify `_fields` to avoid unused computation; TermConnect does this in all outbound requests.

**All term fields are available on peer collection responses.** Every field listed in the build-helpers above is available on both individual and peer collection responses when requested in `_fields`. `htax_synonyms` and `htax_includes` are gated by the Term Inclusion Relationships setting. `htax_relationships` is off when either that setting or Custom Term Relationships and Relationship Types is off. `htax_locations` is gated by the share_locations feature flag.

**`htax_relationships` is also available on individual term responses** (not just peer collection), gated by `_fields`.

**`htax_relationship_types_admin_footer` action.** Fires at the bottom of the relationship types admin page. TermConnect hooks here to inject the "Discover from peer site" UI.

**Scoring:** for each post, take the best matched weight per requested term, with non-direct primary matches discounted when `htax_expand` is on, sum those, and divide by the sum of the primary-term weights, clamped to 1.0. Weights come from `htax_term_weights` (default 1.0). Hidden-term rows are excluded via `is_hidden = 0`. AND mode (`htax_match=all`) removes posts missing a match in any primary term group before normalization.

### Integrations (includes/integrations/)

**`includes/integrations/class-atcf-integration.php`**

- `HyperTaxonomy_ATCF_Integration::register_distance_shortcode()` - Register [ht_distance_field] shortcode for use in search widget forms.
- `HyperTaxonomy_ATCF_Integration::get_user_location()` - Return the visitor's current location, from As the Crow Flies' location chain (atcf_get_visitor_location()): browser coordinates sent with the request (_atcf_lat / _atcf_lng — the search scripts ask the browser for them when a query uses near:me), CDN location headers, IP geolocation, then the site's fallback location.
- `HyperTaxonomy_ATCF_Integration::get_in_range_term_ids( array $ref_points, float $radius_km, string $taxonomy = '' )` - Return term IDs whose As the Crow Flies locations are within $radius_km of any reference point.

## Admin

### Admin Pages

- **Terms menu** (`includes/admin/admin-menu.php`) - `htax_register_admin_menu()` adds the top-level menu, which opens Term Groups; `htax_sort_terms_submenu()` keeps its submenus in order
- **Term Groups** (`includes/admin/group-admin.php`) - The native term screens for the `group` taxonomy, extended with the fields listed under [Group Admin](#group-admin-includesadmingroup-adminphp) below
- **Term Relationship Types** (`includes/admin/relationship-types-admin.php`) - `htax_render_term_relationships_page()`
- **Merge Terms** (`includes/admin/merge-terms.php`) - `htax_render_merge_terms_page()`, a form over `htax_merge_terms()`
- **Synonyms** (`includes/admin/synonyms.php`) - `htax_add_synonyms_page()` (on `admin_menu` priority 12, with Screen Options for rows per page) and `htax_render_synonyms_page()`, a `HyperTaxonomy_Synonyms_Table` list table. `htax_get_synonym_rows()` builds every ordered pair from the cached ID sets only (`htax_load_synonym_term_ids()`, and `htax_load_included_term_ids()` for the "Archives differ" lists, kept as IDs); `htax_add_synonym_paths( array $rows )` adds the inclusion paths, for every row only when an inclusion type or relationship type filter is set, otherwise for the shown page only. Helpers: `htax_get_synonym_candidate_ids( string $taxonomy ): int[]` (terms that can have a synonym: they include something — sources of inclusion rows, members of container groups and their parent groups — and are included by something — targets of inclusion rows, members of included groups and their subgroups; only these have their caches loaded), `htax_get_inclusion_paths( WP_Term $term, WP_Term $included ): array` (every way one term includes another: kind `direct` / `group` / `container_group`, relationship type slug, the group, and for group paths the `chain` of groups between the included group and the member's group; memberships from `htax_get_term_group_ids()`), `htax_group_chain_up( int $from, int $to ): array` (the groups from one group up to an ancestor, inclusive), `htax_render_inclusion_path_compact( array $path ): string` (the Inclusion paths column's line: `→` direct, `⇢` custom type, `⇒ Group › Subgroup` group inclusion, `⇐ Subgroup ‹ Group` container group), `htax_describe_inclusion_path()` (the same path in words, used as the line's hover text), and `htax_prime_all_group_terms()` (every group in one query, once per request). The shown page's "Archives differ" names load in one query, and hidden markers come from `htax_get_all_hidden_term_ids()`; with warm caches a page of 20 rows runs about 11 queries. The Actions column lists **⧉ Merge** (shown only with `manage_options`), which links to Merge Terms with `taxonomy`, `source` and `target` query arguments that the Merge Terms page reads to pre-fill its form, and **✎** links to the term edit screen sections holding each path, by anchor: `#htax-included`, `#htax-containers`, `#htax-term-groups` or `#htax-rel-{slug}` (opened by `openSectionFromHash()` in `htax-term-edit.js`). **⇄ reverse** links to the row with the two terms swapped (row ids `htax-syn-{term}-{synonym}`), on whichever page it falls.
- **Convert Terms** (`includes/admin/convert-terms.php`) - `htax_render_convert_terms_page()`, a form over `htax_convert_terms_batch()`, with `htax_ajax_get_terms_for_conversion()` loading the term checklist
- **Settings** (`includes/admin/settings.php`) - `HyperTaxonomy_Settings`, with Features, Display, Icons, Search, Cache, Export / Import, and Advanced tabs

### Term Edit & Bulk Actions

**Term Edit (includes/admin/term-edit.php)**

**`admin/js/htax-tag-field-repair.js` — tag field repair:** Loaded in the block editor for post types with tags, with or without `prevent_tag_creation`. The tag field sends a create request for every entered tag it hasn't loaded, treats an "already exists" reply as a match, and waits for the whole batch, so a refusal drops the valid tags entered with it, and entering a tag during an earlier batch or before the post's tags load can drop tags too. The script watches those requests through a `wp.apiFetch` middleware without changing them. When a batch finishes, it assigns the tags the server resolved, restores any of the post's tags that were dropped, and removes pills of refused tags. It locks saving and autosaving while a batch is pending. It covers tags only, not other flat taxonomies.

**`admin/js/htax-term-edit.js` — unsaved changes guard:** On DOM-ready, captures `$('#edittag').serialize()` as the baseline form state. A `beforeunload` listener compares the current serialized state to the baseline and fires the browser's native "Leave site?" dialog (via `e.preventDefault()` + non-empty `e.returnValue`) if they differ. The listener removes itself on `form#edittag submit` so the warning is suppressed when the user intentionally clicks Save. The baseline is also reset on `htax:saved` (AJAX save, no page reload). The comparison covers all standard inputs plus the group-checklist checkboxes (`name="htax_groups[]"`) which are part of `#edittag` even when the floating dropdown is visually detached.

- **Hide indirect relationships toggle:** Eye-icon `<button class="htax-via-header-btn htax-via-section-toggle">` rendered in the Term Relationships `<th>` header when any indirect pills exist for the current term. Clicking it toggles class `.htax-via-hidden` on `.htax-inclusions-field` via JS; CSS hides all `.htax-indirect` pills when that class is present. The state is one per-user choice for every term, kept in the `core/preferences` store under scope `htax/term-edit`, key `hideIndirectRelationships` (so `htax-term-edit-js` depends on `wp-data` and `wp-preferences`); on load the script also removes the per-term `localStorage` keys (`htax-via-hidden-section-{termId}`) that older builds wrote.

**Term Edit Locking (includes/admin/term-edit-lock.php)**

Prevents concurrent editing of the same term or relationship type by multiple admins, using the same mechanism as WordPress post locking: a heartbeat-refreshed lock with a take-over flow.

**Lock storage:**
- Terms: `_htax_edit_lock` term meta — `"{timestamp}:{user_id}"`. Applies to every term edit screen (`edit-tags.php` / `term.php`) regardless of whether the taxonomy is HyperTaxonomy-enabled; covers groups, custom taxonomies, and built-in taxonomies.
- Relationship types: `htax_rt_lock_{slug}` transient — same format; TTL = `HTAX_LOCK_TIMEOUT + 30s` so transient expiry slightly outlasts the logical timeout.

**Lock timeout:** `HTAX_LOCK_TIMEOUT` = 150 seconds (matches WordPress post lock). A lock with no heartbeat refresh for 150 s is considered expired.

**Page-load flow:** On `load-edit-tags.php` / `load-term.php` (terms) or `admin_init` when `?page=htax-term-relationships&edit=slug` (RT): if the lock belongs to another non-expired user, render the blocking `#htax-term-lock-dialog` overlay with "Go Back" and "Take Over" buttons; otherwise set the lock and render the edit form normally. The take-over button is a nonce-protected URL (`?htax-take-lock=1` / `?htax-rt-take-lock=1`); the handler verifies the nonce, forcibly overwrites the lock, then redirects back to the same URL without the parameter.

**Heartbeat flow:** The client (`admin/js/htax-term-edit-lock.js`) sends `htax_term_lock_refresh` or `htax_rt_lock_refresh` on each heartbeat tick. The server handler (`htax_heartbeat_refresh_lock`) verifies the nonce, then either refreshes the lock timestamp (returns `lock_refreshed: true`) or signals displacement (returns `lock_error: true` with the new holder's name and avatar URL). On displacement the JS hides the editing section, populates the `#htax-lock-message-taken` section, and shows the dialog.

**Dialog HTML:** Always rendered in `admin_footer` via `htax_render_lock_dialog()` using WordPress's standard `.notification-dialog-wrap` CSS classes. Contains two sections: `#htax-lock-message-editing` (shown at page load when another user holds the lock) and `#htax-lock-message-taken` (always hidden at load, shown by JS on heartbeat displacement). When the current user holds the lock the entire dialog is hidden at page load.

### Group Admin (includes/admin/group-admin.php)

Group admin hooks to `edited_group` (and `group_edit_form_fields`) to manage four relationship sets visible from the group's edit screen. Three of them (Group Members, Container For and Included By) share one renderer and one saver, configured by `htax_get_group_screen_fields()`:

- `htax_get_group_screen_fields(): array` - Settings of the three fields, keyed by POST field name: row type (`member_of` or `includes`), direction, taxonomy feature (`group_assignment` for members, `term_relationships` for the inclusion fields), labels and error notice
- `htax_get_group_screen_taxonomies( string $feature ): array` - Taxonomies a field shows: public, not `group`, with the feature, and enabled for the inclusion fields
- `htax_get_group_locked_taxonomies( int $group_id, array $taxonomies ): array` - Taxonomies whose membership in a Tag-Groups-mapped group Tag Groups owns; shown read-only and kept unchanged on save
- `htax_add_group_screen_fields( $term )` - Renders the three fields (`group_edit_form_fields`, priority 16)
- `htax_save_group_screen_fields( $term_id )` - Saves them (`edited_group`). Submitted terms are kept only if their real taxonomy is editable in that field; current rows of taxonomies the field doesn't show or can't edit are carried forward, so a save never deletes rows the screen didn't display. `htax_replace_group_screen_rows()` replaces the group's rows of one type in a transaction and rolls back with an error notice if a write fails. After the commit the raw caches of the changed rows are cleared, and `htax_fire_group_changes_per_term()` fires the term-level hook (`htax_term_groups_changed`, `htax_container_groups_changed` or `htax_included_groups_changed`) once per changed term with that term's group list after and before the save, so the search index and caches update as they do for a term-screen save
- `htax_parse_group_screen_field( string $field ): ?array` - Submitted term IDs of one field, or null when it wasn't submitted

**Terminology:** "terms with container group" (G→T) and "terms with included group" (T→G) are opposite directions of the `includes` relationship involving a group. `htax_grp_*` caches are only affected by membership changes (`member_of` rows). G→T container-group changes affect `htax_inc_*` caches for the target term T and for members M of the group (and ancestor groups). T→G included-group changes affect `htax_cont_*` caches for members M of the group (and descendant groups). Custom-type group rows additionally affect `htax_crel_*/htax_crel_rev_*` caches for members. `htax_invalidate_group_relationship_caches` handles the T and custom-rel side; `htax_clear_member_caches_for_groups` handles the member side.

### Relationship Types (includes/admin/relationship-types-admin.php)

Admin UI for viewing built-in relationship types and managing user-defined types. Registered as the "Term Relationship Types" submenu under the Terms top-level menu.

### Tables, AJAX, Import/Export

**Post Editor (includes/admin/post-edit.php)**
Loaded unconditionally (outside `is_admin()`) so the REST field is available during REST API requests.
- `htax_enqueue_post_editor_assets()` - Enqueue CSS/JS and build JS globals for the block/classic editor. PHP always emits the full taxonomy-gated data; per-user display preferences (which of the five indicators to show) are stored in the Gutenberg `core/preferences` store under `htax/post-edit` and applied client-side. Also enqueues `htax-editor-prefs.js` in the block editor, which registers five `PluginMoreMenuItem` toggles in the ··· menu and dispatches a one-time Gutenberg notice (via `core/notices`) on first block-editor visit; dismiss state stored in user meta `_htax_prefs_callout_dismissed` (set via `htax_ajax_dismiss_prefs_callout` / `wp_ajax_htax_dismiss_prefs_callout`). Globals emitted:
  - **`htaxTermMeta`** — keyed by term ID; each entry: `{id, name, slug, taxonomy, is_redirect, is_hidden, is_hierarchical, inclusion_count, container_count, included_names, container_names, redirect_target_name}`. Hierarchical taxonomies include all terms; flat taxonomies include only the post's currently assigned terms. Terms with at least one HyperTaxonomy flag are always included; additionally, all assigned terms of a `customize_archives`-enabled taxonomy are included (even plain terms with no flags) so the sticky pin can be shown.
  - **Assigned term request** (Gutenberg only, flat taxonomies, when `preload_editor_terms` is on) — a `wp.apiFetch` middleware added inline after `wp-api-fetch` answers the field's `per_page=-1` request for the post's terms from the page, so the field doesn't accept typing before its terms load. WordPress's `block_editor_rest_api_preload_paths` can't serve it, because the REST server rejects `per_page=-1`. If the path stops matching, the field makes the request itself.
  - **`htaxPreloadedTerms`** (Gutenberg only, when a `post` URL param is present) — assigned term objects injected into the Gutenberg Redux store before `wp-edit-post` initializes, via `wp_add_inline_script('wp-edit-post', ..., 'before')` using `receiveEntityRecords` + `finishResolution`. Eliminates the per-term REST calls Gutenberg would otherwise make when it resolves `getEntityRecord`.
  - **`htaxFlatTaxonomies`** (Gutenberg only) — array of `{slug, restBase}` for flat (non-hierarchical) taxonomies enabled in HyperTaxonomy that are REST-exposed. Used by the JS autocomplete subscriber.
  - **`htaxTaxonomyFeatures`** (Gutenberg only) — `{[taxonomy]: {inclusions, containers, redirects, visibility}}` per-taxonomy feature flags (from `htax_taxonomy_has_feature()`). Applied client-side by `fetchPendingMeta` when merging REST responses. User display preferences are NOT reflected here — they come from `core/preferences`.
  - **`htaxStickyConfig`** (Gutenberg only) — array of `{name, label, restBase}` for taxonomies with the `customize_archives` feature; always emitted when at least one eligible taxonomy exists. Whether pins actually appear is gated by the per-user `htax/post-edit → sticky` preference.
  Badge rendering (via `admin/js/htax-post-edit.js` and `admin/css/htax-post-edit.css`): Classic Editor injects badge `<span>` elements before term labels; Gutenberg token pills receive badge groups prepended into `token-text`, while hierarchical checklist rows receive badge groups injected between the checkbox and label. Deduplication uses a `data-htax-badges` signature attribute — the DOM is only touched when counts or state change. Badge groups display:
  - **Includes badge**: `⇉ [N]` (rightward-double-arrow symbol with count) in light blue (`#ddeeff` bg, `#7ab8e8` border, `#003366` text)
  - **Container badge**: `⇇ [N]` (leftward-double-arrow symbol with count) in light green (`#eef7ef` bg, `#a5d6a7` border, `#2e7d32` text)
  - **Redirect indicator**: Term names receive italic styling, plus a `↪` hook symbol (U+21AA) appended to the name via `.redirect-indicator` span; tooltip shows redirect target name
  - **Hidden indicator**: Terms marked hidden receive reduced opacity (45%) via `data-htax-hidden="1"` attribute selector
  Entries come from `htax_get_editor_term_badges()` (`includes/core/post-editor-rest.php`), which also builds the `htax/v1/term-meta` response, so both carry the same keys. `htaxPostEditL10n` carries the translated badge and pin tooltips.
  **Autocomplete subscriber** (Gutenberg, flat taxonomies only): `wp.data.subscribe` watches the Redux store for term IDs newly added via the Gutenberg tag autocomplete mid-session (i.e. IDs not present in `htaxTermMeta` at page load). When a new ID is detected, it calls `GET /wp-json/htax/v1/term-meta` to fetch badge metadata and merges the result into `htaxTermMeta` for subsequent badge rendering passes. Terms with no HyperTaxonomy flags are merged when the taxonomy has `customize_archives` enabled (so newly-added plain tags still get sticky pins).
  **Sticky for Terms panel**: `useEffect` auto-removes terms from the `htax_sticky_terms` post attribute whenever they are unassigned from the post. Checkboxes for newly-added (unsaved) terms are disabled with the helper text "Save the post first to enable sticky for this term." Redirect terms are excluded from sticky options entirely (their archives redirect away, so stickying has no visible effect).
- `htax_remove_new_tags_from_post_request()` - With the "prevent tag creation" setting on, remove tags that don't exist yet from Classic Editor saves (`admin_init`). WordPress stops assigning a post's tags at the first one it can't create, so refusing the new tag at insertion would discard the whole tag change.

**Post Editor REST (includes/core/post-editor-rest.php)**
- `htax_editor_badge_features( string $taxonomy ): array` - The taxonomy's `relationships`, `redirects`, `visibility` and `sticky` (archive customization) feature flags, which decide what its badges show
- `htax_get_editor_term_badges( array $terms, string $taxonomy ): array` - Badge entries keyed by term ID, for `htaxTermMeta` and `htax/v1/term-meta`; terms with nothing to show are left out unless the taxonomy supports sticky pins

**List Table (includes/admin/tables.php)**
- `htax_add_sticky_indicators()` - Add sticky indicators to term columns in post list

**Term Filters (includes/admin/term-filters.php)**
- `htax_is_term_list_request(): bool` - Returns true when the current request is an admin term list screen or its AJAX counterpart (Quick Edit). Used to gate `get_terms_args` filters that should only apply on list screens, not on dropdowns or autocomplete queries.

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
- `htax_process_import_with_output( $data, $options )` - Runs the import with HTML progress output, through `htax_process_import()`: core import logic: terms, then a second pass for parents listed after their children (ignored on flat taxonomies), custom relationship types, relationships (redirects through `htax_set_redirect()`, group memberships through `htax_set_term_groups()`, added to existing ones; other types by direct insert), post assignments

**Tag Groups Migration (import-tag-groups-handler.php):** Tag Groups was rebranded "PublishPress Tag Groups" in October 2026 — the database format below is unchanged under either name.
- `htax_orphan_deleted_tag_groups( array $current_tg_group_ids ): array` - Strips the mapping meta (never deletes the HyperTaxonomy term) for any previously-mapped group no longer in Tag Groups' current `term_groups` option.
- `htax_split_term_groups_by_provenance( array $group_ids ): array` - Splits a term's current HyperTaxonomy groups into Tag-Groups-mapped vs. those that exist only in HyperTaxonomy, by presence of the mapping meta.
- `htax_apply_tag_groups_term_assignment( int $term_id, string $taxonomy, array $tg_mapped_new_ids, string $mode = 'keep' ): string` - Applies one term's Tag Groups membership report to its HyperTaxonomy group assignment (the provenance-filtered merge), returning `'assigned'`, `'skipped'`, or `'error'`. Used by both the batch loop and the live-sync handler.
- `htax_store_tag_groups_import_snapshot(): void` - Stores `{groups, terms, time}` to the `htax_tag_groups_last_import` option after a successful run, for the migration screen's diff display.
- `htax_import_tag_groups_create_groups(): array` - First pass: resolves/creates/renames group terms, applies hierarchy, detects deletions. Matches ID-first via the site-scoped mapping.
- `htax_import_tag_groups_process_batch( array $group_mapping, int $offset, int $batch_size, string $mode = 'keep' ): array` - Batch pass: assigns one batch of terms to their groups. Works on any taxonomy, gated by the per-row `group_assignment` feature check. `$mode` controls the Tag-Groups-mapped subset only (memberships that exist only in HyperTaxonomy are always preserved): `'keep'` (default) only ever adds; `'mirror'` makes it identical to Tag Groups' current report for the term, including removals.
  - Reads from wp_options: term_groups, term_group_labels, term_group_positions, term_group_parents
  - Reads from wp_termmeta: _cm_term_group_array
  - Converts to HyperTaxonomy Term Groups

**Integration mode (import-tag-groups-handler.php):**
- `htax_tag_groups_integration_mode(): string` - The `htax_tag_groups_integration_mode` option (`'independent'` or `'locked'`; default `'independent'`), validated against the known set. `'independent'` disables every lock below regardless of what data Tag Groups has written; `'locked'` locks them between manual migration runs. Set from Settings → HyperTaxonomy → Export / Import → Sync via the `htax_save_tag_groups_sync_settings` AJAX action (ajax.php), which saves the mode, the continuous-sync checkbox, and removal handling (`htax_tag_groups_sync_mode`) together. Enabling continuous sync reports `needs_resync: true` so the UI can run one full resync first via the Migrate subtab (`resync=1`).

**Re-run overwrite protection (import-tag-groups-handler.php, loaded unconditionally in admin — not just from the importer's own AJAX requests, since `htax_save_term_groups()`/`htax_save_group_screen_fields()` need these on every term/group save):**
- `htax_tag_groups_taxonomy_is_managed( string $taxonomy ): bool` - Whether Tag Groups has actually written `_cm_term_group_array` termmeta for a taxonomy, read directly rather than via any Tag Groups setting — the gate for every lock below. Returns `false` unconditionally when integration mode is `'independent'`. Per-request cached.
- `htax_save_term_groups( int $term_id )` (term-edit.php) - Re-merges in the Tag-Groups-mapped subset of the term's existing group IDs over whatever was submitted, for any taxonomy `htax_tag_groups_taxonomy_is_managed()` is true for. Covers the term's own edit screen and Quick Edit's "Term Groups" checklist, which reuses this handler.
- `htax_tag_groups_native_field_promoted(): bool` - Whether Tag Groups' own native per-term field should take the always-visible spot HyperTaxonomy's Term Groups selector normally occupies on the term edit/add screens, moving that selector (now read-only) into the "More options" collapsible instead. True only when Locked mode is selected and Tag Groups is currently active. Read by `htax_add_edit_form_collapsible_start()` / `htax_add_form_collapsible_start()` (emitted as `data-promote-native-groups` for the JS reorder in htax-term-edit.js / htax-tables.js) and by the `{taxonomy}_*_form_fields` registration in `includes/hooks/actions.php` (controls whether `htax_add_term_groups_field()` is hooked at priority 8, always visible, or priority 16, inside the collapsible between Redirect and Term Relationships).
- `htax_find_ht_group_by_tag_groups_id( int $tg_group_id ): ?int` - Read-only companion to `htax_resolve_or_create_tag_group()` (which calls this internally for its own ID-match step) — looks up the HyperTaxonomy group mapped to a Tag Groups ID on this site without creating anything. Used by the live-sync membership handler, which only ever sees IDs Tag Groups itself reports as already existing.

**Live sync (tag-groups-live-sync.php, loaded unconditionally in admin alongside import-tag-groups-handler.php; registers nothing unless `htax_tag_groups_live_sync_active()` is true):**
- `htax_tag_groups_live_sync_enabled(): bool` (import-tag-groups-handler.php) - The `htax_tag_groups_live_sync` option is on and the integration mode is `'locked'`. It is cleared whenever the mode is saved as `'independent'`.
- Three Tag Groups hooks (Free v3.0.0 / Pro v2.0.9), registered only when the above is true:
  - `term_group_saved` (no args) → `htax_tag_groups_live_sync_on_group_saved()` - thin wrapper calling `htax_import_tag_groups_create_groups()` directly; that function already does this hook's whole job (resolve/create/rename, one-time hierarchy seed, orphan detection).
  - `tag_groups_term_group_deleted( int $group_id )` → `htax_tag_groups_live_sync_on_group_deleted()` - calls `htax_orphan_deleted_tag_groups()` against Tag Groups' current `term_groups` option.
  - `tag_groups_groups_of_term_saved( array $term_groups, int $term_id )` → `htax_tag_groups_live_sync_on_term_groups_saved()` - maps Tag Groups' IDs to HyperTaxonomy IDs via `htax_find_ht_group_by_tag_groups_id()` (skipping any not yet mapped) and calls `htax_apply_tag_groups_term_assignment()`, same as the periodic batch importer does per term.

**UI niceties for the above (no new enforcement — the save-function guards above are what actually prevent the overwrite):**
- `htax_hierarchical_checklist()` (class-hierarchical-checklist.php) has `disabled_terms` (int[]) and `disabled_title` (string) args, and the walker's `start_el()` accepts `$args['disabled']` as either a bool (existing all-items behavior) or an array of specific term IDs to disable — used to gray out locked groups in the term-edit screen's add-group checklist.
- Quick Edit's group checklist (`htax-tables.js`, built from `htaxTablesConfig.groupFilter`) uses `lockedGroupIds`/`lockedTitle`, localized from PHP once per screen (the lock is per-taxonomy, not per-row) — disables the matching checkboxes with the same tooltip.
