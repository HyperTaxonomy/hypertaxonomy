---
title: Hook Reference
parent: Architecture
nav_order: 3
---

# Hook Reference
{: .no_toc }

Where HyperTaxonomy registers its hooks, and the order they run in where that matters: events with several callbacks, priorities chosen to run before or after other code, and hooks registered per taxonomy. Single routine registrations (a menu page, a script, an AJAX or REST handler) are left out; search the code for `add_action( 'hook_name'` to find any of them. The hooks HyperTaxonomy fires for other code are documented in [Actions & Filters](../developer-reference/actions-filters.html). For functions by module, see the [Function Reference](function-reference.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Hook Organization

### Action Hooks (includes/hooks/actions.php)

Registered by section, in file order:

1. **Initialization and Setup** — `plugins_loaded`, `admin_init`, `init`
2. **Admin Enqueue Scripts** — `admin_enqueue_scripts`
3. **Admin Notices** — `admin_notices`, plus `deactivated_plugin` to reset the Tag Groups notice dismissal
4. **Term Edit Form Fields** (registered per enabled taxonomy on `init` at priority 999) — `{$taxonomy}_add_form_fields` and `{$taxonomy}_edit_form_fields`: field handlers at priorities 8–17, plus collapsible open and close at priorities 9 and 19. Because this runs late, companion plugins that add fields at priority 9 earlier (TermConnect, on `init` at priority 15) appear after Term Groups and before the collapsible. The `group` taxonomy gets only the field handlers that have their own group guards, and no collapsible. The External Links field is registered by TermConnect when active.
5. **Term Save Hooks** (same `init` callback) — `create_{$taxonomy}` priority 5, `edited_{$taxonomy}` priorities 20 and 30
6. **Cache Management** — `edited_term`, `delete_term`, term meta changes, `htax_term_groups_changed`, `htax_async_group_propagate`, post assignment and status changes, comment counts, `htax_term_visibility_changed`, and site-level events such as `switch_theme`
7. **Term Taxonomy Conversion** — `edit_term` priority 5, `created_term` priority 1, `pre_delete_term` priority 5, `delete_term` priority 5
8. **Custom Relationship Hooks** — `htax_relationship_saved` and `htax_relationship_deleted`, for search index requeue
9. **Cleanup Hooks** — `edited_term`, `created_term`, `delete_term`, `delete_term_taxonomy`, `delete_post`
10. **Query Modification** — `parse_tax_query`, `pre_get_posts`, `template_redirect`, search notices (`loop_start`, `loop_no_results`), search state (`init`), and post text sync (`save_post`, `wp_update_comment_count`)
11. **Frontend Display** — `wp_head`, `admin_head`, `wp_footer`
12. **AJAX Handlers** — `wp_ajax_*` for the handlers registered outside `includes/admin/`
13. **Security** — `send_headers`
14. **Multisite Support** — `wp_initialize_site`
15. **Integration API** — `get_terms`, and the relationship and redirect change actions

Admin UI enhancements are registered in `includes/admin/tables.php`. Registered outside this file:
- `htax_apply_archive_filter` (`pre_get_posts` priority 11), from `class-filter-options.php`
- Built-in and user-defined relationship type loading (`init` priorities 0 and 5), from `relationship-types.php`
- The search index handlers, from `class-search-index.php`

`htax_expand_secondary_query` (`pre_get_posts` priority 10) is registered here, with its callback in `integration-api.php`. `htax_fire_archive_changed_on_hierarchy` (`edited_term` priority 11) and the `htax_redirect_changed` closure are registered here, with their callbacks in `cache.php`.

### Filter Hooks (includes/hooks/filters.php)

Registered by section, in file order:

1. **Plugin Meta and Links** — `plugin_action_links_{basename}`, `plugin_row_meta`
2. **Term Visibility** — `term_links-{$taxonomy}`, `get_the_terms`, `get_term`, `terms_clauses`, `get_ancestors`, `wp_tag_cloud`, and the `set_object_terms` action
3. **Admin Columns** (dynamic per taxonomy) — column, sortable column, and bulk action hooks, `default_hidden_columns`, and `admin_notices`
4. **Query Filtering** (admin only) — `get_terms_args` for the redirect, icon, visibility, and group filters
5. **Archive Display** — `get_the_archive_title`
6. **Post Styling** — `post_class`
7. **The Posts** — `the_posts`, `found_posts`, `post_limits`, and the search form hooks (`get_search_query`, `pre_get_search_form`, `get_search_form`, `pre_render_block`, `render_block_core/search`)
8. **Admin Term Links** (dynamic per taxonomy) — `term_links-{$taxonomy}` sticky indicators
9. **Admin Styling** — `tag_row_class`
10. **Native Block Extensions** — `query_loop_block_query_vars`, `pre_render_block`
11. **REST API** — `rest_api_init`, `get_terms_args` (converts term search text to stored form during REST requests; `wp_ajax_ajax-tag-search` does the same for Classic Editor suggestions, with `ajax_term_search_results` to decode what they show), `rest_post_dispatch`
12. **Sitemaps** — `wp_sitemaps_taxonomies_query_args`
13. **Tag Creation Gate** — `rest_request_before_callbacks` → `htax_maybe_prevent_tag_creation`, defined in `core/functions.php`; `admin_init` → `htax_remove_new_tags_from_post_request` and `admin_notices` → `htax_show_tags_not_created_notice`, defined in `admin/post-edit.php`
14. **Conditional** — `query` and `query_results`, added by `htax_log_debug()` when `HTAX_QUERY_MONITOR` is defined

### Other Hook Notes

- Computed term meta (`core/term-meta-computed.php`, registered in that file): `get_term_metadata` answers the computed keys; `add_term_metadata`, `update_term_metadata` and `delete_term_metadata` refuse writes to them; `init` priority 20 registers them for each enabled taxonomy
- `htax_expanded_term_ids` — filter fired by `htax_get_expanded_term_ids()`
- `htax_term_post_count` — filter fired by `htax_adjust_term_counts()` for each expansion-aware count
- `htax_remove_hidden_terms_from_list` — filter that controls hidden term removal in term lists
- `rest_api_init` handlers in `filters.php` enrich term responses with `htax_*` fields, enforce hidden-term visibility, and normalize HTML entity encoding in term name searches. The entity normalization filter hooks `get_terms_args` for the lifetime of the REST request: WP stores term names HTML-encoded in `wp_terms.name` (e.g. `H&amp;M`), but REST clients (Gutenberg tag autocomplete) send raw text; without normalization, searching `h&m` never matches `H&amp;M`. The filter applies `html_entity_decode` → `htmlspecialchars` to `args['search']` using the same round-trip as `htax_run_term_autocomplete_search()`.

## WordPress Core Hooks HyperTaxonomy Fires

`htax_update_group_term_count()`, the `update_count_callback` of the `group` taxonomy, fires `edit_term_taxonomy` and `edited_term_taxonomy` around each count update, as WordPress's own count callbacks do. Code that listens for term count changes therefore sees group counts change too. WordPress runs this callback only when something recounts the `group` taxonomy; in HyperTaxonomy that is a merge of groups (`htax_merge_terms()` recounts the merged terms).

## Hook Execution Order

### Event: `edit_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 5 | `htax_cache_term_taxonomy_before_edit` | Capture the term's data before the edit, for parent-change detection |

### Event: `edited_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_save_term_groups` | Save Term Groups assignments from the checklist field |
| 10 | `htax_reload_term_caches_on_edit` | Reload term caches (`htax_inc_*`, `htax_cont_*`, `htax_excl_*`, `htax_redir_*`, `htax_groups_*`, `htax_direct_rel_*`, `htax_direct_rel_grp_*`, `htax_crel_*`, `htax_crel_rev_*` for all registered slugs) |
| 10 | `handle_slug_change` (term icons) | Handle an icon when the term's slug changes |
| 11 | `htax_fire_archive_changed_on_hierarchy` | Fire `htax_term_archive_changed` for old and new parent chains when the parent changes |
| 12 | `htax_invalidate_caches_on_group_reparent` | For a group whose parent changed, invalidate related relationship caches |
| 15 | `htax_clear_ajax_caches` | Clear autocomplete caches |
| 20 | `htax_cleanup_hierarchy_conflicts` | Remove conflicting relationships, fire reindex actions |
| 21 | `htax_cascade_hidden_on_reparent` | Cascade hidden status when reparented under a hidden parent |

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

### Event: `create_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 4 | `htax_save_term_groups` | Save Term Groups assignments, before `htax_save_term_data()` validates relationships on `create_{$taxonomy}` at priority 5 |

### Event: `created_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 1 | `htax_track_created_term_for_conversion` | Records the new term in `$GLOBALS['htax_recently_created_terms']` (keyed by lowercase name) for external-conversion detection — runs before all other HyperTaxonomy hooks |
| 20 | `htax_cleanup_hierarchy_conflicts` | Remove conflicting relationships, fire reindex actions |
| 21 | `htax_cascade_hidden_on_create` | Inherit hidden status from parent |

### Event: `pre_delete_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 5 | `htax_detect_external_conversion_pre_delete` | Checks whether the term being deleted has the same name as a recently created term in a different taxonomy (external-conversion pattern). If matched: captures groups, hierarchy, icon ID, hidden status, and sticky post assignments into `$GLOBALS['htax_pending_external_conversions']` before term meta is wiped by `wp_delete_term()`. Sticky assignments are captured here (not at `delete_term`) because `delete_term_taxonomy` fires before `delete_term` and `htax_cleanup_bulk_deleted_terms` deletes sticky postmeta at `delete_term_taxonomy` priority 10. |

### Event: `delete_term_taxonomy`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_cleanup_bulk_deleted_terms` | Fires before deletion. Invalidates caches, then runs `htax_cleanup_deleted_term()` and the term icon cleanup |

### Event: `delete_term`

| Priority | Handler | Action |
|----------|---------|--------|
| 5 | `on_term_deleted` (search index) | `DELETE FROM wp_htax_search_terms WHERE source_term_id = %d` |
| 5 | `htax_complete_external_conversion_after_delete` | If `$GLOBALS['htax_pending_external_conversions']` has an entry for this term (and `htax_native_conversion_in_progress` is not set): transfers hidden status, icon (if dimensions match), and sticky posts to the new term; queues reindex; fires `htax_term_converted`. Group memberships are not transferred — no stub means no taxonomy-correct recipient. No redirect stub is created. Sticky assignments were captured at `pre_delete_term` time (before `delete_term_taxonomy` cleans them up) and are replayed here directly. |
| 10 | `htax_invalidate_term_caches_on_delete` | Invalidate term caches |
| 10 | `htax_cleanup_deleted_term` | Delete from `wp_htax_term_relationships` (all relationship types) + term meta + `_htax_sticky_{term_id}` post meta |
| 10 | `htax_cleanup_term_icon` | Clean up the term's icon |
| 15 | `htax_clear_ajax_caches` | Clear autocomplete caches |

The priority-10 handlers are independent and have no ordering dependency.

### Event: `set_object_terms`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `on_term_relationships_added` | Delta-based incremental index update — diffs the indexed source terms against the post's actual terms read from the database (the hook's `$terms` holds only the appended terms on `wp_add_object_terms()`; `deleted_term_relationships` → `on_term_relationships_removed()` reuses the same path); targeted count cache clear |
| 10 | `htax_keep_hidden_terms_on_save` | Restore hidden terms that a logged-in user who can't see them would otherwise remove by saving |
| 10 | `htax_post_list_cache_on_object_terms_set` | Invalidate affected post list caches |
| 10 | Closure in `term-archives.php` | `htax_invalidate_sticky_cache_for_post()` |

**Incremental update:** Queries current source terms from index (1 query), calculates delta, deletes removed term relationships by `source_term_id` (1 query), inserts new ones (~4–9 queries per new term). Approximately 5× faster than full rebuild for typical 1–3 term changes. The inserted rows carry the column-default strength, so when terms were added the post is also queued (`queue_post_reindex()`) for a background reindex that computes their strengths.

### Event: `deleted_term_relationships`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `on_term_relationships_removed` | Incremental index update, using the same path as `on_term_relationships_added` |
| 10 | `htax_post_list_cache_on_term_relationships_deleted` | Invalidate affected post list caches |
| 10 | Closure in `term-archives.php` | `htax_invalidate_sticky_cache_for_post()` |

### Event: `delete_post` / `trash_post`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_cleanup_sticky_on_delete` | Delete sticky post meta (`delete_post` only) |
| 10 | `on_post_delete` (search index) | Delete from the search index and `wp_htax_post_text` (both hooks) |

### Event: `save_post`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_sync_post_text_row` | Upsert row in `wp_htax_post_text` for published, draft, pending, future, and private posts using `htax_extract_post_body()`; delete row for trash and auto-draft. Skips revisions and autosaves. Draft rows enable content-based tag suggestions in the editor. Force-deleted posts that bypass trash may leave an orphan row until the next full rebuild. |
| 20 | `on_post_saved` (search index) | Queue a published, indexed-type post for reindex so its stored strengths reflect the post text just refreshed at priority 10. Skips revisions and autosaves. |

### Event: `wp_update_comment_count`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `htax_sync_post_text_comment` | Update `comment_count` and `last_comment_gmt` in `wp_htax_post_text`. No-ops if the post row does not exist in the table. |
| 10 | `htax_post_list_cache_on_comment_count` | Invalidate post lists for a published post's index terms |

### Event: `transition_post_status`

| Priority | Handler | Action |
|----------|---------|--------|
| 10 | `on_post_status_change` | Remove from index if unpublished; queue reindex if newly published |
| 10 | `htax_post_list_cache_on_post_status_change` | Invalidate post lists when a post is published or unpublished |
| 10 | Closure in `term-archives.php` | `htax_invalidate_sticky_cache_for_post()` when the status actually changed |

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
| `htax_relationship_saved` | `$source_term_id, $target_term_id, $type_slug` | Fired by `htax_add_term_relationship()` after a relationship row is inserted; for types mapped to `includes`, queues reindex for both source and target terms |
| `htax_relationship_deleted` | `$source_term_id, $target_term_id, $type_slug` | Fired by `htax_remove_term_relationship()` after a relationship row is deleted; for types mapped to `includes`, queues reindex for both source and target terms |

**Dual handler architecture:** The includes, containers, excludes, included groups, and container groups actions each have two handlers at priority 10 — one for index updates, one for cache invalidation. Both process old and new relationship values for complete coverage.

**Type registration action:** `htax_relationship_types` fires at the end of `htax_register_builtin_relationship_types()` (init priority 0), after all four built-in types are registered. Plugin code that calls `htax_register_relationship_type()` in PHP should hook here rather than using `init` directly, to guarantee built-in types are already registered when the hook fires.

In addition to the per-operation actions above, `htax_term_archive_changed( $term_id, $taxonomy )` is the unified signal for any change to a term's archive composition. It fires for: every relationship save/delete; a term's first redirect (not target changes); redirect removal; term parent changes (via `htax_fire_archive_changed_on_hierarchy` in `cache.php`, at `edited_term` priority 11, firing for old/new parent ancestor chains); post-term assignment changes (fires for directly-assigned terms and their containers/ancestors via `htax_expand_term_ids_for_contrib_invalidation`); post publish/unpublish; and sticky post changes. Internally, HyperTaxonomy hooks it at priority 10 to invalidate the `htax_contrib_` paginated archive cache (`htax_invalidate_contrib_cache`). The named callback `htax_fire_term_archive_changed` (also in `cache.php`) is used by `add_action` registrations in `actions.php` to bridge fine-grained relationship actions to this signal. External integrations can hook it to invalidate their own derived data without subscribing to each fine-grained action.
