---
title: Data Storage
parent: Architecture
nav_order: 4
---

# Data Storage
{: .no_toc }

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Term Meta Keys

The keys `htax_includes`, `htax_containers`, `htax_synonyms`, `htax_groups`, `htax_archive_term_ids`, `htax_redirect` and `htax_icon_url*` that `get_term_meta()` returns are computed, not stored; see [Term Meta](../developer-reference/term-meta.html) and [Function Reference → Computed Term Meta](function-reference.html#computed-term-meta-includescoreterm-meta-computedphp).

**Note:** All term relationships are stored in the `wp_htax_term_relationships` table, not in term meta:
- Inclusion relationships (`relationship_type = 'includes'`)
- Exclusion relationships (`relationship_type = 'excludes'`)
- Redirects (`relationship_type = 'redirect'`)
- Group membership (`relationship_type = 'member_of'`)

The following meta keys are for display and configuration only.

**Display Keys**:
- `_htax_is_hidden` - Boolean flag ('1' or empty) for hidden terms
- `_htax_icon_id` - WordPress attachment ID for term icon
- `_htax_placeholder_color` - Stored color for a term's placeholder square shown when no icon is set

**Redirect Keys**:
- `_htax_auto_redirect` - Boolean flag ('1') marking a redirect that was auto-created by the term conversion feature (distinguishes it from a redirect set manually). Cleaned up automatically when the redirect row no longer exists; the actual redirect target lives in `wp_htax_term_relationships`, not in term meta.

**Archive Settings Keys** (any enabled taxonomy with `customize_archives` feature):
- `_htax_max_sticky_posts` - Maximum sticky posts for this term's archive (1-100)
- `_htax_posts_per_page` - Posts per page override for this term's archive (1-100)
- `_htax_sort_order` - Default sort order for this term's archive (e.g. `date:desc,title:asc`)
- `_htax_show_description` - Boolean flag ('1' or empty) to display description on archive

**Internal State Keys**:
- `_htax_edit_lock` - `{timestamp}:{user_id}` lock for concurrent term editing
- `_htax_count_{taxonomy}` - On `group` terms: the group's direct member count for that taxonomy

**Tag Groups Migration Key** (on `group` taxonomy terms only):
- `_htax_tag_groups_source_id` - Site-scoped mapping key, `{site_token}:{tag_groups_group_id}`, linking an HT group term back to the Tag Groups group it was imported from. `site_token` is a random UUID generated once per site (see `htax_tag_groups_site_token` option below) and never carried by HT's own export/import feature, so an imported mapping from another site fails to match here by construction and falls through to name-matching instead — see `htax_get_tag_groups_site_token()` / `htax_resolve_or_create_tag_group()` in `includes/admin/import-tag-groups-handler.php`. Enables rename-safe and removal-safe re-imports (see Options below for the related run-state options). Stripped (not the term) when the corresponding Tag Groups group is deleted — the HT term itself is never auto-deleted.

## Post Meta Keys
- `_htax_sticky_{term_id}` - Sticky assignment flag (value `1`) for a specific term. One row per assignment, e.g. `_htax_sticky_5 = 1` means this post is sticky for term ID 5. Taxonomy is omitted from the key because WordPress term IDs are globally unique. Present on posts of any indexed post type with a `customize_archives`-enabled taxonomy.

## User Meta Keys
- `_htax_dismissed_taxopress_merge_notice` (bool, value `1`) - Set when an admin dismisses the "use HyperTaxonomy's merge instead" notice on TaxoPress's own Manage Terms → Merge Terms screen (`HyperTaxonomy_TaxoPress_Integration::ajax_dismiss_notice()`, `includes/integrations/class-taxopress-integration.php`). Per-user, not per-site — each admin dismisses independently. Cleared for every user (`delete_metadata('user', 0, ..., '', true)`) when TaxoPress is deactivated, so the notice reappears (until re-dismissed) if TaxoPress is reactivated later.
- `_htax_dismissed_tag_groups_notice` (bool) - Set when an admin dismisses the Tag Groups migration notice.
- `_htax_prefs_callout_dismissed` (bool) - Set when a user dismisses the post editor's display-preferences callout.
- `{table prefix}persisted_preferences` (core) - Block-editor preferences. HT stores its post-editor display preferences in the `htax/post-edit` scope, and its sidebar panels appear in `core.openPanels` / `core.inactivePanels` as `htax-*/…` IDs.

All of these are removed on uninstall (the dismissals for every user; the `htax/post-edit` scope and `htax-*` panel IDs from each user's preferences).

## Options
- `htax_settings` - Plugin settings array
  - `enabled_taxonomies` - Array of enabled taxonomy slugs for custom taxonomy support
  - `indexed_post_types` - Array of post type slugs to include in the search index and term archive features (default: `['post', 'page']`)
  - `taxonomy_features` - Per-taxonomy feature toggles:
    - `search_enhancement` - Use for search enhancement
    - `icons` - Enable term icons
    - `visibility` - Enable term visibility options (hide from public)
    - `term_relationships` - Enable all relationship features: direct inclusion/container relationships, group-based relationships (Included Groups & Container Groups), and custom relationship types. Gates all archive expansion — when not set, `htax_build_expanded_tax_query()` returns `null` immediately.
    - `group_assignment` - Assign terms to Term Groups (required for Tag Groups import)
    - `redirects` - Allow term redirects
    - `customize_archives` - Enable per-term archive customization: sticky posts, posts per page, and sort order. When disabled, existing settings are preserved but not applied.
    - `share_locations` - Include As the Crow Flies location data in this taxonomy's REST term responses
  - `max_sticky_posts` - Global maximum sticky posts per archive (default: 10)
  - `disable_sticky_styles` - Whether to disable default sticky post styles
  - `show_contributing_terms` - Show contributing terms in archive titles
  - `hidden_terms_visibility` - Hidden term visibility setting (all/post_editors/none); `post_editors` means users with `edit_posts`
  - `expand_secondary_queries` - Enable automatic expansion of secondary/third-party queries (default: off); when on, `htax_expand_secondary_query` and `htax_adjust_term_counts` become active
  - *(Post editor display preferences are per-user, stored in the Gutenberg `core/preferences` store under `htax/post-edit` — not in `htax_settings`. The five toggles (Inclusion Badges, Container Badges, Redirect Indicators, Hidden Terms, Sticky Pushpins) are accessible via the block editor's ··· menu.)*
  - `advertise_ht_presence` (bool, default `true`) — whether to add the `X-HyperTaxonomy` header to REST responses; disabling opts the site out of peer discovery
  - `rest_include_relationship_data` (bool, default `true`) — setting label: **Term Inclusion Relationships**. Disabling removes `htax_includes`, `htax_synonyms`, and `htax_relationships` from WP REST term responses.
  - `rest_peer_sync_enabled` (bool, default `true`) — setting label: **Custom Term Relationships and Relationship Types**. Disabling unregisters `GET htax/v1/relationship-types` and `GET htax/v1/term/{id}/relationships`, and additionally removes `htax_relationships` from WP REST term responses. `htax_relationships` is therefore disabled if *either* `rest_include_relationship_data` or `rest_peer_sync_enabled` is off.
  - Additional settings for search, icons, caching, etc.
- `htax_version` - Installed version
- `htax_db_version` - Database schema version
- `htax_custom_relationship_types` - User-defined relationship types, keyed by slug
- `htax_pending_reindex` - Post IDs queued for background reindexing, drained by the `htax_process_reindex_batch` cron event
- `htax_tag_groups_site_token` - Random UUID (`wp_generate_uuid4()`), generated once on first use of the Tag Groups migration tool. Scopes the `_htax_tag_groups_source_id` mapping key to this site specifically — see that meta key's entry above. Internal state, not relationship data; deliberately not part of HT's own export/import format.
- `htax_tag_groups_integration_mode` - `independent` (default) or `locked`. `independent` disables the re-run-overwrite locks on mapped groups entirely (see the meta key's entry above); `locked` locks them between manual migration runs.
- `htax_tag_groups_live_sync` - `1` or `0` (default). Continuous sync checkbox, effective only when the integration mode is `locked`. When on, Tag Groups' own `term_group_saved` / `tag_groups_term_group_deleted` / `tag_groups_groups_of_term_saved` hooks are registered (`includes/admin/tag-groups-live-sync.php`) and apply changes as they happen. Cleared whenever the mode is saved as `independent`. Set on Settings → HyperTaxonomy → Export / Import → Sync (`htax_save_tag_groups_sync_settings` AJAX action); enabling it triggers one full resync first, via the Migrate subtab.
- `htax_tag_groups_sync_mode` - `keep` (default) or `mirror`. Removal handling for continuous per-event sync; each manual migration run chooses its own mode in the import modal, pre-selected to this value while continuous sync is on. Treats a term's previously-Tag-Groups-mapped group memberships that Tag Groups no longer reports: `mirror` removes them to match Tag Groups' current state exactly; `keep` only ever adds, never removes. Applies only to the Tag-Groups-mapped subset of a term's groups — HT-only group memberships (groups Tag Groups has no knowledge of) are always preserved regardless of mode. Set via the radio control under "Sync continuously" on the Sync subtab.
- `htax_tag_groups_last_import` - `{ groups: int, terms: int, time: int }` snapshot stored after each successful Tag Groups import run, used to show a concrete diff ("3 new groups and 12 new term assignments since your last import") on the migration screen instead of a generic reminder.
- `htax_cleaned_panel_prefs_N` (bool) - One-time-run marker for `HyperTaxonomy_Upgrader::clean_stale_panel_prefs()`, which strips removed `PluginDocumentSettingPanel` IDs out of every user's `{table prefix}persisted_preferences` user meta (`core.openPanels` array). Gutenberg fetches that array asynchronously after initial render and re-renders panels in its stored order, so a stale entry (left behind when a panel is removed from the plugin) silently pushes active panels to the wrong position — client-side reposition JS can't out-race this second render. Whenever a `PluginDocumentSettingPanel` is removed, add its ID (format `{plugin-name}/{panel-name}`) to the `$stale` array in that method and bump `N` so the cleanup re-runs for everyone. The cleaned preferences are stamped with a fresh `_modified`: the editor also keeps a copy in the browser's localStorage and uses whichever copy is newer, so an unstamped server copy could be overwritten by a stale browser copy.

## Indexes on Native WordPress Tables

HyperTaxonomy adds the following indexes to native WordPress tables at activation (via `add_indexes()` in `class-activator.php`):

**`wp_posts`** (checked by index name before creation):
- `htax_post_date (post_date, post_status, post_type)` - helps date-ordered WP_Query archive results
- `htax_post_modified (post_modified, post_status, post_type)` - helps modified-date-ordered WP_Query archive results

Note: FULLTEXT indexes for post content scoring (`htax_ft_pt_title`, `htax_ft_pt_body`, `htax_ft_pt_excerpt`, `htax_ft_pt_all`) are on `wp_htax_post_text` so that FULLTEXT operates on processed plain text rather than raw HTML post content. Processing preserves semantically useful content (link URLs, image alt text, abbreviation expansions) while stripping markup that would otherwise pollute search results.

**`wp_terms`** (checked by index name before creation):
- `htax_ft_term_name (name)` - FULLTEXT for term name scoring and required/excluded term lookups in search

**`wp_term_taxonomy`** (checked by index name before creation):
- `htax_ft_term_desc (description)` - FULLTEXT for term description scoring and required/excluded term lookups in search

**`wp_termmeta`** (error-suppressed; no-ops if already exist):
- `htax_icons (meta_key(50), term_id)` - icon attachment ID lookups by meta key
- `htax_term_meta (term_id, meta_key(50))` - accelerates `get_term_meta()` calls

Uninstall drops these indexes when it deletes the plugin's data.

## Custom Database Tables

**`{$wpdb->prefix}htax_term_relationships`** - Unified relationship storage with normalized architecture
- Columns:
  - `source_term_id` (bigint) - The term that initiates the relationship
  - `target_term_id` (bigint) - The term that receives the relationship
  - `relationship_type` (varchar 100) - Built-in types: 'includes', 'member_of', 'redirect', 'excludes'; user-defined type slugs stored as-is
  - `source_taxonomy` (varchar 32) - Source term's taxonomy
  - `target_taxonomy` (varchar 32) - Target term's taxonomy
- Indexes:
  - PRIMARY KEY (source_term_id, target_term_id, relationship_type)
  - KEY `target_lookup` (target_term_id, relationship_type)
  - KEY `source_taxonomy` (source_taxonomy)
  - KEY `target_taxonomy` (target_taxonomy)
  - KEY `relationship_type` (relationship_type)

**Normalized Architecture - Intent Storage:**
Relationships are stored as INTENT and expanded dynamically at query time via SQL JOINs.

**Relationship Types:**

1. **Direct Inclusion** (A includes B):
   - Storage: `source=A, target=B, type='includes'`
   - Effect: Posts from B appear in A's archive

2. **Group Membership** (A is member of GroupX):
   - Storage: `source=A, target=GroupX, type='member_of'`
   - Effect: A can be queried as part of GroupX

3. **Included Groups** (A includes GroupX):
   - Storage: `source=A, target=GroupX, type='includes', target_taxonomy='group'`
   - Effect: All members of GroupX appear in A's archive (expanded via JOIN)

4. **Container Groups** (A included into GroupX members):
   - Storage: `source=GroupX, target=A, type='includes', source_taxonomy='group'`
   - Effect: A appears in all GroupX members' archives (REVERSE includes)

5. **Redirects** (A redirects to B):
   - Storage: `source=A, target=B, type='redirect'`
   - Effect: 301 redirect from A's archive to B's archive

**Query Pattern Examples:**

```sql
-- Get terms included by Term A (direct + group expansion)
SELECT DISTINCT included_term_id, included_taxonomy
FROM (
    -- Direct inclusions
    SELECT target_term_id AS included_term_id, target_taxonomy AS included_taxonomy
    FROM wp_htax_term_relationships
    WHERE source_term_id = A
    AND relationship_type = 'includes'
    AND target_taxonomy != 'group'

    UNION

    -- Included group members
    SELECT r2.source_term_id AS included_term_id, r2.source_taxonomy AS included_taxonomy
    FROM wp_htax_term_relationships AS r1
    INNER JOIN wp_htax_term_relationships AS r2
        ON r1.target_term_id = r2.target_term_id
        AND r1.target_taxonomy = 'group'
        AND r2.relationship_type = 'member_of'
        AND r2.target_taxonomy = 'group'
    WHERE r1.source_term_id = A
    AND r1.relationship_type = 'includes'
    AND r1.target_taxonomy = 'group'
) AS all_included
WHERE included_term_id NOT IN (A, ancestors...)
```

**`htax_get_included_terms()` and `htax_get_container_terms()` are mirrors of each other.** Both build the full result set first, then apply exclusions last.

**`htax_get_included_terms(A)` — what terms does A pull into its archive:**

Inclusions:
- Members of included groups, with descendant subgroup expansion (`htax_get_terms_in_groups()`)
- Directly included individual terms
- Terms with container groups matching A's member groups (expanded to subgroups via `htax_get_group_hierarchy()`), found via `htax_get_terms_with_container_groups()`

Exclusions (applied last, cannot be overridden):
- Directly excluded individual terms
- Self, ancestors, and descendants for hierarchical taxonomies. Ancestors would create
  semantic loops; descendants are already in the archive via WP's native `include_children`
  and do not represent cross-branch relationships. Both are excluded to keep the HT
  inclusion set strictly cross-branch.

**`htax_get_container_terms(B)` — what terms include B in their archive:**

Inclusions:
- Direct containers (terms with a direct `includes` relationship to B)
- Group inclusion containers (B is member of GroupY; find terms that include GroupY)
- Container group members: B's container groups expanded upward to ancestors via `htax_get_group_ancestors()`; find all terms that are members of those groups

Exclusions (applied last):
- Self, descendants, and ancestors for hierarchical taxonomies. Descendants cannot be
  containers (they cannot include their own ancestors). Ancestors are excluded for symmetry:
  a term's posts are already visible in ancestor archives via `include_children`, so an
  ancestor appearing as a container would be redundant rather than a cross-branch relation.
- Containers that explicitly exclude B

The two functions are consistent: if A includes B (per `htax_get_included_terms`), B lists A as a container (per `htax_get_container_terms`), and vice versa. The group hierarchy expansions are symmetric — member group expansion goes downward in `htax_get_included_terms`, container group expansion goes upward in `htax_get_container_terms`. Both functions restrict their results to strictly cross-branch relationships, with no overlap with the WP-native hierarchy.

> **Note:** Group exclusion is not supported in the term relationship context (term edit screen). The display shortcodes (`[ht_term_list]`, etc.) have their own `exclude_groups` parameter that uses a separate processing path (`htax_expand_shortcode_terms()`).

**Why PHP processing is required:** Group hierarchy expansion uses WordPress's native `get_terms()` / `get_term_children()` and cannot be done efficiently in a single SQL query without recursive CTEs (requires MySQL 8.0+).

**Benefits of Normalized Architecture:**
- No sync functions needed - relationships computed at query time
- No data duplication or staleness issues
- Better SQL pagination support (filtering in SQL, not PHP)
- Single source of truth for all relationships

**`{$wpdb->prefix}htax_search_terms`** - Post-term association index for search and filtering
- Columns: post_id, term_id, source_term_id, taxonomy, relationship_type, **model**, is_hidden, **strength**
- `PRIMARY KEY (post_id, term_id, source_term_id, relationship_type, model)`
- No FULLTEXT indexes. B-tree indexes only: `idx_taxonomy` (taxonomy), `idx_term_lookup` (term_id), `idx_post_lookup` (post_id), `idx_relationship` (relationship_type, taxonomy), `idx_hidden` (is_hidden, relationship_type), `idx_source_term` (source_term_id). InnoDB appends the primary key columns to every secondary index, so `model` is available for filtering in each of them without a row lookup.
- **`model VARCHAR(64) DEFAULT 'htax_tf'`** — the scoring model that produced the row's `strength`. Strengths from several models are stored side by side; direct and indirect rows are both stored per model. `htax_tf` (constant `HTAX_DEFAULT_STRENGTH_MODEL`) is the built-in TF heuristic, maintained by HT. Other plugins register models and submit scores through the strength model API (`includes/core/strength-models.php`, see below).
  - **Invariant:** `htax_tf` rows always form the complete structural set (every post/term/source/relationship-type combination). Any other model's rows are a subset of those keys that differ only in `strength`; an external model only has rows for the directly assigned terms it has scored and the rows derived from them.
  - **Strength reads filter on one model** — any query that reads `strength` or aggregates it (noisy-OR, `SUM`, `COUNT(*)`) appends `htax_strength_model_sql( $alias, $purpose )`, otherwise each row would count once per model. Purpose `'search'`: PowerSearch term scoring (both the combined scan and `build_term_search_subqueries()`, via the shared `$hst_hidden` suffix), search widget field scores, the `htax_term_strengths` REST field, and the WP-SKOS document endpoint. Purpose `'related'`: `query_posts_by_term_ids()` (post list / related posts), `htax_get_local_post_scores()`, `htax_get_post_term_strengths()`. Both purposes currently read the single model selected in Settings → Search (`htax_settings['strength_model']`); the purpose is passed so they can be selected separately later.
  - **Unscored pairs are excluded, not substituted:** under an external model, a post–term pair it hasn't scored contributes nothing to strength-ranked reads — there is no per-pair fallback to `htax_tf`, so scores from different models are never mixed in one ranking.
  - **Structural reads need no filter** — queries that only test which posts reach a term (`IN`/`EXISTS`/`DISTINCT`, `COUNT(DISTINCT post_id)`) return the same set whether or not other models' rows exist, by the invariant above.
  - **Deletes and `is_hidden` updates apply to every model** — they reflect structural changes (term unassigned or deleted, include removed, visibility changed) that hold regardless of which model scored the row.
  - **Reindexing keeps external scores:** `htax_index_post_terms()` reads every external model's direct strengths for the post before deleting its rows, writes the `htax_tf` rows, then rewrites each external model's direct strengths for terms that are still directly assigned and re-derives their indirect rows against the current structure. Afterwards it fires `htax_term_strength_missing( $post_id, $term_ids, $model )` for each registered external model that lacks a strength for some directly assigned term. Scores of unregistered models are kept the same way but never read.
  - **Full rebuilds keep external scores:** both rebuild paths (`ajax_rebuild_indexes()` and the WP Cron `rebuild_indexes()`) call `clear_search_terms_for_rebuild()` instead of truncating: it deletes all `htax_tf` rows and the external rows of posts that won't be reindexed (no longer published or no longer an indexed post type). Each post's external rows are then re-derived as it is reindexed.
  - **Indirect strength derivation** is shared by every model: `htax_derive_indirect_strength( $source_strength, $taxonomy, $relationship_type )` = source strength × the taxonomy's explicit weight for that relationship type. Relevance weights are global, not per model.
- `source_term_id` is the directly-assigned term that led to this index entry — used for efficient cleanup, incremental updates, and visibility propagation. Term text is not stored here; it is read at query time from `wp_terms.name` and `wp_term_taxonomy.description`
- **`strength FLOAT DEFAULT 1.0`** — per-row relevance contribution score, baked in at index build time:
  - **Direct rows** (`relationship_type = 'direct'`): TF-based score reflecting how central the term is to the post's content. Formula: `floor(0.2) + body_tf×0.4 + excerpt_hit×0.2 + title_hit×0.2`, capped at 1.0. Body TF is normalized (occurrences / word_count × 100, capped at 1.0). Exact phrase match, case-insensitive.
  - **Indirect rows** (`hierarchy`, `container`, `redirect_target`): `source_tf × explicit_relationship_weight`. The source term's raw TF score propagated at the configured explicit weight (e.g. hierarchy=0.7, container=0.75, redirect=0.5–0.6). Explicit weights are baked in at index time, not applied at query time.
  - Strengths from other sources are supplied as a separate model via `htax_set_term_strengths()`, not by overriding `htax_tf` rows.
  - Filter hook `htax_strength_field_weights`: override `['floor', 'body', 'excerpt', 'title']` coefficients (default 0.20/0.40/0.20/0.20; must sum ≤ 1.0).
  - **Keeping strengths current:** strength is only computed by the full per-post path (`htax_build_post_search_index()` → `htax_index_post_terms()`). Everything else that changes a stored strength queues the affected posts for that path via `queue_post_reindex()`. The queue is deduplicated in memory per request, persisted at shutdown to the `htax_pending_reindex` option, and drained by the WP Cron event `htax_process_reindex_batch` (`process_async_reindex_batch()`) in batches of `REINDEX_BATCH_SIZE` (100) posts per run. Each run writes the remainder back before processing (posts queued meanwhile merge into it) and schedules the next run while posts remain, so a large queue never has to fit in one request's time or memory limits. Sources of queued posts:
    - **Incremental structural writes** — term assigned to a published post (`add_term_relationships_to_index()`), parent change (`on_taxonomy_hierarchy_changed()`), redirect removed from an ancestor (`on_hierarchy_parent_restored()`), containers changed (`update_container_index()`), includes changed (`on_includes_changed()`), redirect target changed (`htax_redirect_changed`). These insert rows immediately (via `htax_upsert_search_rows()`, which writes the column-default strength 1.0) so the structure is correct at once, then queue the posts so the default is replaced with a computed strength.
    - **Content edits** — `on_post_saved()` (`save_post`, priority 20, after `htax_sync_post_text_row` refreshes the post text) queues the published post, because direct strengths are derived from its title, excerpt, and body.
    - **Propagation weight changes** — `on_relevance_weights_changed()` (`update_option_htax_settings`) compares the effective `hierarchy`/`container`/`redirect` explicit weights per enabled taxonomy between the old and new settings (`htax_get_relevance_weights( $taxonomy, $options )`) and queues every published post with terms in a taxonomy whose weights changed. Text weights used by PowerSearch remain dynamic and apply immediately.
- **Relevance aggregation — noisy-OR per `(post, term)`, then `SUM` across terms:** every term-strength scorer (post list / related posts `query_posts_by_term_ids`, `htax_get_local_post_scores`, the search widget field score, and the WP-SKOS document endpoint) computes a post's relevance the same way. All rows sharing a `(post_id, term_id)` — regardless of `source_term_id` or `relationship_type` — combine via noisy-OR: `combined = 1 − Π(1 − strength)`, computed in SQL as `1 - EXP(SUM(LN(GREATEST(1 - strength, 0.000001))))` (MySQL has no `PRODUCT()` aggregate). The outer `SUM` across distinct `term_id`s preserves **breadth** — a post connected through more distinct query terms scores higher.
  - **Why noisy-OR, not `SUM`:** a term reached by only one path (the common case) keeps its value, because noisy-OR of a single value returns that value. A term reached by *multiple* paths (e.g. a container of several directly-tagged terms) scores higher with more paths, but the combined value stays strictly below 1.0, the ceiling a single direct row can reach, no matter how many indirect paths contribute. A plain `SUM` has no such ceiling: two container rows at the default 0.75 weight would sum past 1.0, letting an indirect term outscore the strongest possible direct term.
  - This also covers the rare case where one source term reaches a target via more than one `relationship_type`: that row is one more input to the same noisy-OR combination, with no separate collapsing step, because the combinator ignores `relationship_type`.
  - **A term that is both directly assigned to the post and reached indirectly is not a special case.** Its `direct` row (`source_term_id = term_id`) shares the same `(post_id, term_id)` group as any `container`/`hierarchy`/`redirect_target` rows for that term, so all of them combine via the identical noisy-OR formula — the direct row is just one more input, not a separately-tracked ceiling. Example: post 8919, term 78 has a `direct` row (strength 0.2) plus four indirect rows (three `container` at 0.15, one `redirect_target` at 0.12); combined = 0.58 — higher than the direct row alone (0.2) or any single indirect row alone (0.15), still strictly below 1.0.
  - PowerSearch's term-name/description text scoring reads the same per-row `strength` value but doesn't use this aggregation: it takes the strongest row per term. See [Search Internals](search-internals.html#powersearch--scoring-and-execution).
- **Always indexes all relationship types** (`direct`, `container`, `hierarchy`, `redirect_target`) regardless of which features are currently enabled for a taxonomy. Enabling or disabling inclusions/redirects does not require reindexing.
- **Taxonomy-gated writes:** `htax_build_post_search_index()` skips any taxonomy that fails `htax_is_taxonomy_enabled()` — including unregistered ones — so index rows for a suspended taxonomy are never added by incremental reindexes. When a taxonomy is disabled via Settings → Features, `on_taxonomy_enabled_state_changed` deletes existing rows for that taxonomy immediately. When a taxonomy is newly enabled, posts carrying terms from it are discovered via a `wp_term_relationships` JOIN and queued for reindex.
- **Feature gates applied at query time:** All queries against this table filter `relationship_type` to only the types enabled for the queried taxonomy via `htax_taxonomy_has_feature()`. Applies to: PowerSearch subqueries, search widget scoring subqueries, post list shortcode queries, and filter SQL subqueries. Term archives apply equivalent feature gates but read from `wp_htax_term_relationships` directly rather than this index.
  - **Multi-taxonomy gating** (post list / related posts): `query_posts_by_term_ids()` can span several taxonomies at once (`[ht_related_posts]` with `taxonomy=""` or a CSV pools the post's terms across taxonomies). The `relationship_type` gate is then built **per taxonomy** and OR'd together, keyed on the `taxonomy` column each row carries — `(st.taxonomy = 'A' AND st.relationship_type IN (…A's enabled types…)) OR (st.taxonomy = 'B' AND …)`. Each term's strength is summed across taxonomies, so sharing terms in more taxonomies raises a post's score. A single-taxonomy query yields exactly one branch (a plain `taxonomy = … AND relationship_type IN (…)`). When `taxonomy` is empty the taxonomy set is derived from the distinct `taxonomy` values of the query term IDs (`wp_term_taxonomy`), which also keeps AJAX pagination correct without threading a taxonomy list.
- Supports field-specific operators (title:, content:, excerpt:, tags:, categories:, terms:)
- Post text scoring uses `wp_htax_post_text` (HTML-stripped title/body/excerpt) via NATURAL LANGUAGE MODE FULLTEXT; taxonomy term name/description scoring uses `wp_terms`/`wp_term_taxonomy` via BOOLEAN MODE FULLTEXT (`htax_ft_term_name`, `htax_ft_term_desc`) — see "Indexes on Native WordPress Tables" and "Custom Database Tables" sections

**`{$wpdb->prefix}htax_post_text`** - Processed post text for FULLTEXT search and sort metadata

Why a separate table? MySQL's FULLTEXT index operates on the raw stored text. `wp_posts.post_content` is raw HTML — every href URL, CSS class, and attribute value (`target="_blank"`, `class="wp-block-image"`) is tokenised as a searchable word. A search for `+target` would match every post that contains a link with `target="_blank"`, regardless of content. Stripping HTML before indexing is not possible inside a MySQL FULLTEXT index (no function-based FULLTEXT support). The only option is a pre-stripped copy.

Storing the sort columns (`post_date_gmt`, `post_modified_gmt`, `post_author`, `comment_count`, `last_comment_gmt`) in the same table eliminates the JOIN to `wp_posts` on every query. The table stores published, draft, pending, future, and private posts. All public-facing queries filter `pt.post_status = 'publish'`; the draft rows exist so the post editor can query content-based tag suggestions against the same table.

- Columns: `post_id` (PK), `post_status`, `post_type`, `post_date_gmt`, `post_modified_gmt`, `post_author`, `comment_count`, `last_comment_gmt` (NULL = no approved comments), `title` (plain text), `body` (`post_content` processed by `htax_extract_post_body()`), `excerpt`
- Populated on rebuild via PHP loop across published + draft + pending + future + private posts; kept in sync by `htax_sync_post_text_row` (`save_post` hook, uses `htax_extract_post_body()`) and `htax_sync_post_text_comment` (`wp_update_comment_count` hook, updates `comment_count` + `last_comment_gmt` in one write); the row is removed when the post is trashed or deleted (`on_post_delete`)
- FULLTEXT indexes: `htax_ft_pt_title (title)`, `htax_ft_pt_body (body)`, `htax_ft_pt_excerpt (excerpt)`, `htax_ft_pt_all (title, body, excerpt)`
- B-tree indexes: `htax_pt_status_type`, `htax_pt_date`, `htax_pt_modified`, `htax_pt_comments`, `htax_pt_last_comment`

PowerSearch query execution, scoring, thresholds, and index management are described in [Search Internals](search-internals.html#powersearch--scoring-and-execution).

## Transients

Caches are transients too; their keys, contents and what clears them are on the [Caching](caching.html) page. These transients hold short-lived state rather than cached data:

- `htax_tags_not_created_{user_id}` - Tags left off a user's last post save while the "prevent tag creation" setting is on, until the notice shows them (5 minutes).
- `htax_term_data_{term_id}` - A term's data captured before an edit, for parent-change detection
- `htax_rt_lock_{slug}` - Edit lock for a relationship type
- `htax_admin_notices` - Admin notices queued for the next page load
- `htax_icon_regen_pending` - Icon regeneration waiting to run after a settings change
- `htax_ss_{hash}` - Search state of a POST-mode search too large for an inline results-page token (`htax_search_state_encode()`; the token is `k{hash}`, a 16-hex content hash). TTL 30 days (`HTAX_SEARCH_STATE_TTL`); an expired token makes the results URL render as if no search was submitted.
