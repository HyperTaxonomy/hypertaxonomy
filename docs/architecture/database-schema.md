---
title: Database Schema
parent: Architecture
nav_order: 3
---

## Database Schema

### Term Meta Keys

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

**Tag Groups Migration Key** (on `group` taxonomy terms only):
- `_htax_tag_groups_source_id` - Site-scoped mapping key, `{site_token}:{tag_groups_group_id}`, linking an HT group term back to the Tag Groups group it was imported from. `site_token` is a random UUID generated once per site (see `htax_tag_groups_site_token` option below) and never carried by HT's own export/import feature, so an imported mapping from another site fails to match here by construction and falls through to name-matching instead — see `htax_get_tag_groups_site_token()` / `htax_resolve_or_create_tag_group()` in `includes/admin/import-tag-groups-handler.php`. Enables rename-safe and removal-safe re-imports (see Options below for the related run-state options). Stripped (not the term) when the corresponding Tag Groups group is deleted — the HT term itself is never auto-deleted.

### Post Meta Keys
- `_htax_sticky_{term_id}` - Sticky assignment flag (value `1`) for a specific term. One row per assignment, e.g. `_htax_sticky_5 = 1` means this post is sticky for term ID 5. Taxonomy is omitted from the key because WordPress term IDs are globally unique. Present on posts of any indexed post type with a `customize_archives`-enabled taxonomy.

### User Meta Keys
- `_htax_dismissed_taxopress_merge_notice` (bool, value `1`) - Set when an admin dismisses the "use HyperTaxonomy's merge instead" notice on TaxoPress's own Manage Terms → Merge Terms screen (`HyperTaxonomy_TaxoPress_Integration::ajax_dismiss_notice()`, `includes/integrations/class-taxopress-integration.php`). Per-user, not per-site — each admin dismisses independently. Cleared for every user (`delete_metadata('user', 0, ..., '', true)`) when TaxoPress is deactivated, so the notice reappears (until re-dismissed) if TaxoPress is reactivated later.
- `_htax_dismissed_tag_groups_notice` (bool) - Set when an admin dismisses the Tag Groups migration notice.
- `_htax_prefs_callout_dismissed` (bool) - Set when a user dismisses the post editor's display-preferences callout.
- `{table prefix}persisted_preferences` (core) - Block-editor preferences. HT stores its post-editor display preferences in the `htax/post-edit` scope, and its sidebar panels appear in `core.openPanels` / `core.inactivePanels` as `htax-*/…` IDs.

All of these are removed on uninstall (the dismissals for every user; the `htax/post-edit` scope and `htax-*` panel IDs from each user's preferences).

### Options
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
  - `max_sticky_posts` - Global maximum sticky posts per archive (default: 10)
  - `disable_sticky_styles` - Whether to disable default sticky post styles
  - `show_contributing_terms` - Show contributing terms in archive titles
  - `hidden_terms_visibility` - Hidden term visibility setting (all/editors/none)
  - `expand_secondary_queries` - Enable automatic expansion of secondary/third-party queries (default: off); when on, `htax_expand_secondary_query` and `htax_adjust_term_counts` become active
  - *(Post editor display preferences are now per-user, stored in the Gutenberg `core/preferences` store under `htax/post-edit` — not in `htax_settings`. The five toggles (Inclusion Badges, Container Badges, Redirect Indicators, Hidden Terms, Sticky Pushpins) are accessible via the block editor's ··· menu.)*
  - `advertise_ht_presence` (bool, default `true`) — whether to add the `X-HyperTaxonomy` header to REST responses; disabling opts the site out of peer discovery
  - `rest_include_relationship_data` (bool, default `true`) — setting label: **Term Inclusion Relationships**. Disabling removes `htax_includes`, `htax_synonyms`, and `htax_relationships` from WP REST term responses.
  - `rest_peer_sync_enabled` (bool, default `true`) — setting label: **Custom Term Relationships and Relationship Types**. Disabling unregisters `GET htax/v1/relationship-types` and `GET htax/v1/term/{id}/relationships`, and additionally removes `htax_relationships` from WP REST term responses. `htax_relationships` is therefore disabled if *either* `rest_include_relationship_data` or `rest_peer_sync_enabled` is off.
  - Additional settings for search, icons, caching, etc.
- `htax_version` - Installed version
- `htax_db_version` - Database schema version
- `htax_tag_groups_site_token` - Random UUID (`wp_generate_uuid4()`), generated once on first use of the Tag Groups migration tool. Scopes the `_htax_tag_groups_source_id` mapping key to this site specifically — see that meta key's entry above. Internal state, not relationship data; deliberately not part of HT's own export/import format.
- `htax_tag_groups_integration_mode` - `independent` (default) or `locked`. `independent` disables the re-run-overwrite locks on mapped groups entirely (see the meta key's entry above); `locked` locks them between manual migration runs.
- `htax_tag_groups_live_sync` - `1` or `0` (default). Continuous sync checkbox, effective only when the integration mode is `locked`. When on, Tag Groups' own `term_group_saved` / `tag_groups_term_group_deleted` / `tag_groups_groups_of_term_saved` hooks are registered (`includes/admin/tag-groups-live-sync.php`) and apply changes as they happen. Cleared whenever the mode is saved as `independent`. Set on Settings → HyperTaxonomy → Export / Import → Sync (`htax_save_tag_groups_sync_settings` AJAX action); enabling it triggers one full resync first, via the Migrate subtab.
- `htax_tag_groups_sync_mode` - `keep` (default) or `mirror`. Removal handling for continuous per-event sync; each manual migration run chooses its own mode in the import modal, pre-selected to this value while continuous sync is on. Treats a term's previously-Tag-Groups-mapped group memberships that Tag Groups no longer reports: `mirror` removes them to match Tag Groups' current state exactly; `keep` only ever adds, never removes. Applies only to the Tag-Groups-mapped subset of a term's groups — HT-only group memberships (groups Tag Groups has no knowledge of) are always preserved regardless of mode. Set via the radio control under "Sync continuously" on the Sync subtab.
- `htax_tag_groups_last_import` - `{ groups: int, terms: int, time: int }` snapshot stored after each successful Tag Groups import run, used to show a concrete diff ("3 new groups and 12 new term assignments since your last import") on the migration screen instead of a generic reminder.
- `htax_cleaned_panel_prefs_N` (bool) - One-time-run marker for `HyperTaxonomy_Upgrader::clean_stale_panel_prefs()`, which strips removed `PluginDocumentSettingPanel` IDs out of every user's `{table prefix}persisted_preferences` user meta (`core.openPanels` array). Gutenberg fetches that array asynchronously after initial render and re-renders panels in its stored order, so a stale entry (left behind when a panel is removed from the plugin) silently pushes active panels to the wrong position — client-side reposition JS can't out-race this second render. Whenever a `PluginDocumentSettingPanel` is removed, add its ID (format `{plugin-name}/{panel-name}`) to the `$stale` array in that method and bump `N` so the cleanup re-runs for everyone. The cleaned preferences are stamped with a fresh `_modified`: the editor also keeps a copy in the browser's localStorage and uses whichever copy is newer, so an unstamped server copy could be overwritten by a stale browser copy.

### Indexes on Native WordPress Tables

HyperTaxonomy adds the following indexes to native WordPress tables at activation (via `add_indexes()` in `class-activator.php`):

**`wp_posts`** (checked by index name before creation):
- `htax_post_date (post_date, post_status, post_type)` - helps date-ordered WP_Query archive results
- `htax_post_modified (post_modified, post_status, post_type)` - helps modified-date-ordered WP_Query archive results

Note: FULLTEXT indexes for post content scoring (`htax_ft_title`, `htax_ft_content`, `htax_ft_excerpt`, `htax_ft_combined`) are on `wp_htax_post_text` so that FULLTEXT operates on processed plain text rather than raw HTML post content. Processing preserves semantically useful content (link URLs, image alt text, abbreviation expansions) while stripping markup that would otherwise pollute search results.

**`wp_terms`** (checked by index name before creation):
- `htax_ft_term_name (name)` - FULLTEXT for term name scoring and required/excluded term lookups in search

**`wp_term_taxonomy`** (checked by index name before creation):
- `htax_ft_term_desc (description)` - FULLTEXT for term description scoring and required/excluded term lookups in search

**`wp_termmeta`** (error-suppressed; no-ops if already exist):
- `htax_icons (meta_key(50), term_id)` - icon attachment ID lookups by meta key
- `htax_term_meta (term_id, meta_key(50))` - accelerates `get_term_meta()` calls
### Custom Database Tables

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
- Simplified schema (6 columns instead of 9)
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
  - **Why noisy-OR, not `SUM`:** a term reached by only one path (the common case) is unaffected — noisy-OR of a single value returns that value unchanged, so single-path results are unchanged from the old `SUM` behavior. A term reached by *multiple* paths (e.g. a container of several directly-tagged terms) still scores higher with more paths (monotonically increasing), but the combined value is mathematically guaranteed to stay strictly below 1.0 — the ceiling a single direct row can reach — no matter how many indirect paths contribute. A plain `SUM` had no such ceiling: two container rows at the default 0.75 weight could sum past 1.0, letting an indirect term outscore the strongest possible direct term. Noisy-OR gives the "more paths ⇒ higher score" behavior without that crossover.
  - This also subsumes the old `MAX(strength)` dedup step (for the rare case where one source term reaches a target via more than one `relationship_type`): that row is now just one more input to the same noisy-OR combination, with no separate collapsing step needed — the combinator is agnostic to `relationship_type` throughout.
  - **A term that is both directly assigned to the post and reached indirectly is not a special case.** Its `direct` row (`source_term_id = term_id`) shares the same `(post_id, term_id)` group as any `container`/`hierarchy`/`redirect_target` rows for that term, so all of them combine via the identical noisy-OR formula — the direct row is just one more input, not a separately-tracked ceiling. Live example: post 8919, term 78 has a `direct` row (strength 0.2) plus four indirect rows (three `container` at 0.15, one `redirect_target` at 0.12); combined = 0.58 — higher than the direct row alone (0.2) or any single indirect row alone (0.15), still strictly below 1.0.
  - PowerSearch's term-name/description text scoring reads the same per-row `strength` value but never re-aggregates multiple source paths for one term, so it is unaffected by this change.
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
- Populated on rebuild via PHP loop across published + draft + pending + future + private posts; kept in sync by `htax_sync_post_text_row` (`save_post` hook, uses `htax_extract_post_body()`) and `htax_sync_post_text_comment` (`wp_update_comment_count` hook, updates `comment_count` + `last_comment_gmt` in one write)
- FULLTEXT indexes: `htax_ft_pt_title (title)`, `htax_ft_pt_body (body)`, `htax_ft_pt_excerpt (excerpt)`, `htax_ft_pt_all (title, body, excerpt)`
- B-tree indexes: `htax_pt_status_type`, `htax_pt_date`, `htax_pt_modified`, `htax_pt_comments`, `htax_pt_last_comment`

**PowerSearch Architecture:**

The PowerSearch system uses MySQL FULLTEXT indexes for performance with support for field-specific operators, minimum score thresholds, search scope restriction, and dynamic ordering.

*Query Parsing (htax_parse_search_query in search.php, called from perform_indexed_text_search):*
- Regex pattern: `/([+\-]?)\b(title|content|body|excerpt|tags?|categories?|terms?|...):("([^"]+)"|(\S+))/i`
- Singular aliases accepted: `tag:` = `tags:`, `category:` = `categories:`, `term:` = `terms:`, `body:` = `content:`
- Extracts field-specific terms with modifiers into arrays: title, body, excerpt, tags, categories, terms, general
- Each term stored as: `array('term' => 'word', 'required' => bool, 'excluded' => bool)`
- Modifiers: `+` (required, also adds HAVING score > 0 for that field), `-` (excluded), no prefix (optional)
- Supports quoted phrases: `title:"exact phrase"` or `+title:"exact phrase"`
- General terms (no operator) match in all allowed fields
- Helper functions in search.php: `htax_build_boolean_query_string()` converts to MySQL BOOLEAN MODE syntax (taxonomy scoring, WHERE enforcement); `htax_build_natural_language_query_string()` produces operator-stripped bare words for NL MODE post text scoring

*Search Scope Restriction (search_in parameter):*
- Default: `'all'` (searches title, content, excerpt, all enabled taxonomies)
- Valid values: `all`, `title`, `content`, `excerpt`, `tags`, `categories`, `terms`, or specific taxonomy slugs
- Filters parsed query to remove restricted field operators (silently ignored)
- Admin can restrict search scope for security or UX reasons

*Match Modes:*
- `match="any"` (default): OR logic - terms optional unless prefixed with `+`
- `match="all"`: AND logic - auto-adds `+` to all non-excluded terms
- Exclusions (`-`) work in both modes

*Dynamic Ordering (orderby parameter):*
- Format: `field:direction,field:direction,...`
- Supported fields: `relevance`, `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `ID`
- Example: `relevance:desc,date:desc,title:asc`

*Query Execution (perform_indexed_text_search in class-power-search.php):*
1. Check 60-second transient cache; return cached result on hit. The cache key (`htax_ft_…`) is `md5( $query . serialize( $config ) . '|' . (int) htax_can_see_hidden_terms() )`. Baking the hidden-terms visibility flag into the key prevents cross-user contamination — a result set computed for a user who can see hidden terms is never served to one who cannot, and vice versa.
2. Get match mode from config; if not set, read the site-wide default from `htax_settings[default_match_mode]` via `htax_get_default_match_mode()` (configurable in Settings → Search → Default Match Mode; built-in default: 'any')
3. Parse search_in parameter and build allowed fields map
4. Parse query with htax_parse_search_query() (supports field operators including singular aliases)
5. Filter parsed query based on search_in restrictions
6. If match='all', mark all non-excluded terms as required
7. Read relevance multipliers from settings; auto-populate per-taxonomy defaults for newly enabled taxonomies
8. Build NATURAL LANGUAGE MODE MATCH() clauses for `wp_htax_post_text` fields (title, body, excerpt) using `htax_build_natural_language_query_string()` — operators stripped, excluded terms omitted.
9. Add WHERE enforcement for required and phrase terms (runs before both combined and field-specific taxonomy paths):
   - **Required general terms** (`+word` or multi-word quoted phrases): pre-fetch term IDs matching the term via BOOLEAN MODE FULLTEXT on `wp_terms.name` and `wp_term_taxonomy.description`; add `WHERE (MATCH(pt.title, pt.body, pt.excerpt) AGAINST(term IN BOOLEAN MODE) > 0 OR pt.post_id IN (taxonomy lookup))`. Quoted phrases always get this enforcement regardless of `+` prefix, because NL MODE cannot represent phrase constraints.
   - **Required field-specific text terms** (`+title:word`, `+content:word`, `+excerpt:word`): `WHERE MATCH(pt.field) AGAINST(term IN BOOLEAN MODE) > 0`.
10. Build taxonomy LEFT JOINs. Each subquery may contain up to three UNION branches per relationship type:
   - **Branch A (exact match)**: `hst.strength AS score` for terms whose `LOWER(name) = LOWER(search_string)` — no FULLTEXT scan, IDs pre-fetched via `htax_get_exact_match_term_ids()`. Strength replaces the old flat `explicit` weight; posts where the matched term is more central to the content rank higher. These terms are excluded from Branch B to prevent double-scoring. Branch A fires: always for field-specific operators (`tags:target`); only for quoted general terms (`"target"`) to avoid mistaking post-content searches for term-label lookups.
   - **Branch B (name)**: `MATCH(t.name) AGAINST(query IN BOOLEAN MODE) * name_weight * hst.strength` — FT scan on `wp_terms.name` via `htax_ft_term_name`; JOIN to `htax_search_terms` on `source_term_id`. `name_weight` (text weight) remains dynamic; `hst.strength` adds per-post centrality signal. Exact-match IDs excluded.
   - **Branch C (description)**: `MATCH(tt.description) AGAINST(query IN BOOLEAN MODE) * description_weight * hst.strength` — only when `use_description=true` for that taxonomy.
   - `MAX(branch_score)` per `(post_id, source_term_id)` — across branches, across the several index rows through which a post can reach one matched term (several of its terms included in it, or several relationship types), in every variant of both paths — so a term counts once per post, at its strongest, never more than a direct match on it. `SUM` across terms yields the field score. In the field-specific path each relationship-type subquery returns `(post_id, source_term_id, score)` rows and `sum_term_scores_sql()` takes the MAX across relationship types before summing.
   - **Combined scan** (general queries with no field operators): single LEFT JOIN covering all taxonomies and rel-types, with separate CASE expressions for name/description text weights (the explicit relationship weight is baked into `hst.strength`, not applied in the CASE). If all indexed taxonomies have `use_description=false`, the description branch is omitted entirely. All taxonomy scores collapse into `terms_score`.
   - **Field-specific path** (explicit `tags:` / `categories:` / `terms:` or custom-taxonomy operators): separate LEFT JOIN per field (tags, categories, terms, custom taxonomies).
   - **Required field-taxonomy term enforcement** (`+tags:word`, `+categories:word`, etc.): FULLTEXT queries for taxonomy fields use OR mode so individual term-name rows can match independently. Each required term is enforced by a pre-fetched `WHERE pt.post_id IN (SELECT post_id FROM htax_search_terms WHERE source_term_id IN (...))` clause. This correctly handles multiple required terms in the same field (`+tags:A +tags:B` → posts carrying both tags).
   - **Excluded term enforcement** (`-word` and `-field:word`): matching term IDs are pre-fetched and added as `WHERE pt.post_id NOT IN (SELECT post_id ... WHERE source_term_id IN (...))`. For general unqualified excluded terms (`-word`) — in both the combined and the field-specific path — a second NOT IN against `MATCH(pt.title, pt.body, pt.excerpt)` ensures posts where the excluded word appears in any text field are also removed, even when another field still scores positively. Field-specific excluded terms (`-tags:word`, `-terms:word`) only apply the taxonomy layer — post text is not checked.
11. Calculate overall relevance as sum of all field scores; apply HAVING clause (min score thresholds)
12. Apply dynamic ORDER BY; execute with LIMIT/OFFSET
13. For paginated requests: run count query with same structure but no LIMIT
14. Store result in 60-second transient; return

*Cumulative Relevance Scoring:*

Post text fields (title, content, excerpt) use **NATURAL LANGUAGE MODE** so that IDF (inverse document frequency) and document-length normalisation prevent a single high-frequency term from dominating results. A post with "gift" ×600 and an 86 KB body does not outrank a shorter post that covers all query terms meaningfully. Taxonomy term fields continue to use **BOOLEAN MODE** (small corpus, phrase/operator support required). Required and excluded term enforcement for all fields is handled via explicit WHERE clauses — not by relying on the score being zero.

**Field Score Calculation:**
- **title_score**: `MATCH(pt.title) AGAINST(nl_query IN NATURAL LANGUAGE MODE) * title_weight`
- **content_score**: `MATCH(pt.body) AGAINST(nl_query IN NATURAL LANGUAGE MODE) * content_weight`
- **excerpt_score**: `MATCH(pt.excerpt) AGAINST(nl_query IN NATURAL LANGUAGE MODE) * excerpt_weight`
  - All three fields come from `wp_htax_post_text` (`pt`), processed by `htax_extract_post_body()` — link URLs are appended as `[URL]`, img alt text and abbr expansions are preserved, then all remaining HTML is stripped. All public queries filter `pt.post_status = 'publish'`.
  - `nl_query` = query terms with `+`/`-` operators stripped and excluded terms removed; produced by `htax_build_natural_language_query_string()` (in search.php). NL MODE does not support phrase syntax, so multi-word phrases contribute their individual words to the score; phrase presence is enforced separately via a WHERE guard (see below).
- **tags_score / categories_score / terms_score / custom taxonomy scores**: up to three branches per relationship type. `hst.strength` encodes the per-row relevance contribution (TF-based for direct rows; `source_tf × explicit_weight` for indirect rows — see `wp_htax_search_terms` schema above):
  - Branch A (exact, conditional): `hst.strength AS score` when `LOWER(t.name) = LOWER(search_string)`. Strength replaces the flat `explicit` weight — posts where the matched term is central to the content rank higher. Fires for all non-excluded field-operator terms, and for quoted general terms (e.g. `"target"`). Unquoted general terms do not trigger exact matching.
  - Branch B (name, always active): `MATCH(t.name) AGAINST(query IN BOOLEAN MODE) * name_weight * hst.strength`. Text weight (name_weight) stays dynamic; strength adds centrality. Exact-match term IDs excluded when Branch A is active.
  - Branch C (description, when `use_description=true`): `MATCH(tt.description) AGAINST(query IN BOOLEAN MODE) * description_weight * hst.strength`. Description weight defaults to ~70% of name weight.
  - `MAX(branch_score)` per `(post_id, source_term_id)` — a term scores once per post, via its strongest branch, index row and relationship type; `SUM` across terms per post yields the field score.
  - Relationship types: direct, hierarchy (hierarchical taxonomies), container, redirect_target — all gated by feature flags at query time
  - **Combined general scan** uses a single LEFT JOIN with CASE expressions for name/description text weights (explicit weight is baked into `hst.strength`, not in the CASE); all taxonomy scores collapse into `terms_score`. **Field-specific path** produces separate tags_score, categories_score, terms_score columns.

**Overall Relevance:**
- relevance = title_score + content_score + excerpt_score + tags_score + categories_score + terms_score
- Multiple matching terms in the same field are cumulative (e.g., matching 3 terms in title gives higher title_score than matching 1)
- General terms (no field operator) contribute to the title, content and excerpt scores, and score each taxonomy **once**: in that taxonomy's own field when the query has one, otherwise in `terms_score`. Combined scan: custom-taxonomy fields in the query (`colors:x`) are filtered out of the scan (`$general_field_taxonomies`). Field-specific path: tags and categories always have their own field when there are general words, so `terms_score` scores them — and custom-field taxonomies — with `terms:` words only.
- Score range: 0-1+ (can exceed 1 when multiple terms match with high weights)

*Minimum Score Thresholds:*
- **min_score**: Overall minimum relevance threshold (default 0.01)
- **min_title_score**: Minimum weighted score for title matches (default 0)
- **min_content_score**: Minimum weighted score for content matches (default 0)
- **min_excerpt_score**: Minimum weighted score for excerpt matches (default 0)
- **min_tags_score**: Minimum cumulative score across all tag relationships (default 0)
- **min_categories_score**: Minimum cumulative score across all category relationships (default 0)
- **min_terms_score**: Minimum cumulative score across ALL taxonomy relationships (default 0) — compared in HAVING against `tags_score + categories_score + terms_score + <custom>_score`, which don't overlap (see above). Applied in the combined path and whenever the field-specific path builds `terms_score`.
- Valid range: 0.0 to 1.0+ (same scale as relevance multiplier settings)
- Each threshold is independent - all non-zero thresholds must be met (AND logic in HAVING clause)
- Applied via HAVING clause after weighted score calculation

*Query Examples:*
```
"+photography wordpress tutorial" (match="any")
  → "photography" REQUIRED, "wordpress" and "tutorial" optional (boost score)

"title:wordpress photography" (match="any")
  → Both optional, posts with both rank higher

"+title:\"getting started\" react" (match="any")
  → "getting started" phrase REQUIRED in title, "react" optional anywhere

"+tags:javascript content:tutorial" (match="any")  [tag: also accepted]
  → "javascript" REQUIRED in tags (HAVING tags_score > 0 enforced), "tutorial" optional in post content

"photography -stock" (match="any")
  → Posts with "photography" but NOT "stock"

"wordpress react tutorial" (match="all")
  → All three terms REQUIRED (auto-adds + to each)

"+tags:amazon promo" (match="any")
  → "amazon" tag REQUIRED (no amazon tag → excluded even if text mentions amazon), "promo" optional in any field
```

*Field operator aliases (all case-insensitive):*
- `tag:` = `tags:`, `category:` = `categories:`, `term:` = `terms:`
- `body:` = `content:`

*Index Management:*
- htax_build_post_search_index($post_id): Indexes single post, called on publish/update
- htax_index_post_terms($post_id, $relationships): Stores post-term association rows; handles any enabled taxonomy generically — hierarchy ancestors for any `is_taxonomy_hierarchical()` taxonomy, containers for any `term_relationships`-enabled taxonomy
- Bulk reindex via admin AJAX: truncate table, reindex all published posts (all enabled taxonomies processed uniformly)
- Delete on post deletion: removes from wp_htax_search_terms
- `htax_expand_term_hierarchy($term_ids, $taxonomy)`: Helper to expand term IDs to include all descendants for any taxonomy (replaces former category-only version)
- `htax_upsert_search_rows($table_name, $placeholders, $values, $error_context)`: Shared helper for the standard 6-column upsert (`post_id, term_id, source_term_id, taxonomy, relationship_type, is_hidden`) with `ON DUPLICATE KEY UPDATE is_hidden = VALUES(is_hidden)`. Used by the hierarchy, container, includes, and redirect index writers. New rows get the column-default `strength` and `model`, so every caller also queues the affected posts for reindex to compute their strengths. The variant that includes `strength` (full reindex path) does not use this helper.
- **Strength models (`includes/core/strength-models.php`):**
  - Registry (in memory, per request): `htax_register_strength_model( $key, $args )`, `htax_unregister_strength_model( $key )`, `htax_get_strength_models()`, `htax_validate_strength_model_key( $key )`. `htax_tf` and every other `htax_`-prefixed key are reserved: registering or unregistering them returns false.
  - Selection: `htax_get_strength_model( $purpose )` returns `htax_settings['strength_model']` when that model is registered, otherwise `htax_tf`. `htax_strength_model_sql( $alias, $purpose )` builds the ` AND {alias}.model = '…'` condition. The Settings sanitizer only accepts a new selection with full coverage; code writing `htax_settings` directly bypasses that check.
  - Writes: `htax_set_term_strengths( $model, $post_id, $strengths )` (validates every score and term first, then writes direct + derived rows in one `INSERT … ON DUPLICATE KEY UPDATE`; returns `true|WP_Error`), `htax_remove_term_strengths( $model, $post_id = 0, $term_ids = [] )` (returns rows removed or `WP_Error`). Both refuse `htax_tf` and fire `htax_term_strengths_updated`.
  - Coverage: `htax_get_strength_model_coverage( $model )` (per taxonomy: `htax_tf` direct pairs vs. the model's direct pairs, via a primary-key self-join), `htax_strength_model_has_full_coverage( $model )`.
  - Internal helpers shared with `htax_index_post_terms()`: `htax_derive_indirect_strength()`, `htax_collect_strength_rows()`, `htax_write_strength_rows()`, `htax_get_external_direct_strengths()`.
  - `htax_get_stored_strength_models()`: distinct external model keys with stored rows, registered or not.
  - Admin: Settings → Search → Strength Model / Index Coverage / Index Rebuild render one panel per registered model plus one per *inactive* model (stored rows, not registered), and the dropdown shows the model in effect (`render_search_tab()`). Inactive models are listed only so their scores can be cleared: the sanitizer never saves an unregistered key. The form sends `strength_model_changed` (set to 1 by JS on change); when it is 0 the saved selection is left alone, so saving the tab while a selected provider is inactive keeps its selection. A note under the dropdown appears when the saved model differs from the one in effect.
  - Clear Scores calls `htax_ajax_clear_strength_model()` (`admin/ajax.php`, nonce `htax-clear-strength-model`), which also switches the selection back to `htax_tf` if the cleared model was selected.

### Transients (with safe key generation)

**Per-request copies:** some getters also keep a per-request copy of a transient's value (`htax_request_cache()` groups `redirect_target` for `htax_redir_*`, `redirect_term_ids` for `htax_redirect_terms`, `synonyms` for `htax_syno_*`). Whenever one of these transients is deleted — per term in `htax_delete_term_transients()` / `htax_delete_term_transients_batch()` / `htax_clear_redirect_target_cache()`, or in bulk in `htax_clear_relationship_caches()` / `htax_clear_all_caches()` — the matching per-request copy is cleared too, so later code in the same request (notably the search index handlers on `htax_redirect_changed` / `htax_redirect_removed`) reads the new state.

**External object caches:** all HyperTaxonomy code reads and writes transients through `htax_get_transient()` / `htax_set_transient()` / `htax_delete_transient()`, never the core functions directly. Bulk invalidation is a `DELETE … LIKE` on `wp_options`, which cannot reach transients held by a persistent external object cache (Redis, Memcached) — and many of these caches have no TTL. So when `wp_using_ext_object_cache()` is true, `htax_transient_key()` appends a generation token to every `htax_*` key: one global token plus one per family (`htax_transient_family()`: `rel` for the relationship caches, `contrib`, `pl`, `total`, `ac`, `sticky`, `misc`). Every bulk clear ends with `htax_bump_cache_family()`, which starts a new generation for the family (`all` from `htax_clear_all_caches()`), making every existing key in it unreachable; the cache's own eviction reclaims them. Pattern clears that are per-term in the database (`htax_invalidate_contrib_cache()`, `htax_invalidate_post_list_cache()`, `htax_delete_count_cache_for_ids()`) bump the whole family, since object-cache keys can't be matched by pattern. Tokens are random, so an evicted token is replaced rather than reset and never revalidates old entries. Without an external object cache keys are unchanged, and `htax_bump_cache_family()` instead drops WordPress's runtime `options` cache group so code later in the same request doesn't read the deleted values.

**Note:** Relationship data lives in database tables; the following transients are used for performance optimization:

- `htax_archive_terms_{taxonomy}_{term_id}` - Archive term data (includes children, included terms, group expansions)
- `htax_contrib_{term_id}_p{page}[H]` - Contributing terms by page (suffix `H` when viewer can see hidden terms). Stores an array of term name strings used to build the archive title. Invalidated via `htax_invalidate_contrib_cache($term_id)`, which is hooked to `htax_term_archive_changed` — the unified signal that covers relationship changes, first-redirect, redirect removal, term hierarchy changes, post assignments, and sticky post changes. Also invalidated directly when a term's `posts_per_page` setting changes (page boundaries shift)
- `htax_crel_{slug}_{term_id}` - Outgoing custom relationship rows for a term and type, with group targets already expanded to member terms (non-core types only). No TTL. Invalidated by: `htax_add_term_relationship` / `htax_remove_term_relationship` (source's cache); `htax_save_term_relationships` edit form path for changed sources; `htax_delete_term_transients` for the term itself; `htax_invalidate_term_caches_on_delete` for source terms that had a row pointing to the deleted term; type-prefix clear on type save/delete; `htax_invalidate_group_relationship_caches` when group membership or hierarchy changes.
- `htax_crel_rev_{slug}_{term_id}` - Incoming custom relationship rows for a term and type, with group sources already expanded to member terms (non-core types only). No TTL. Invalidated by: `htax_add_term_relationship` / `htax_remove_term_relationship` (target's cache); `htax_save_term_relationships` edit form path for changed targets; `htax_delete_term_transients` for the term itself; `htax_invalidate_term_caches_on_delete` for target terms that had a row from the deleted term; type-prefix clear on type save/delete; `htax_invalidate_group_relationship_caches` when group membership or hierarchy changes.
- `htax_direct_rel_{slug}_{term_id}` - Raw T→U rows for a relationship type, non-group targets only. Works for any slug including core `'includes'`. No TTL. Invalidated by `htax_delete_custom_rel_caches_for_term` (called from `htax_delete_term_transients`). Used by the term edit screen and the PRE-CLEAR warm-cache path in `htax_invalidate_term_caches_on_delete`.
- `htax_reverse_rel_{slug}_{term_id}` - Raw A→T rows for a relationship type, non-group sources only. Works for any slug including core `'includes'`. No TTL. Invalidated by `htax_delete_custom_rel_caches_for_term`. Used by the term edit screen and surgically by `htax_invalidate_term_caches_on_delete` to clear a target's cache when its source term is deleted.
- `htax_direct_rel_grp_{slug}_{term_id}` - Raw T→G rows for a relationship type, group targets only. Works for any slug including core `'includes'`. No TTL. Invalidated by `htax_delete_custom_rel_caches_for_term`. Used by the PRE-CLEAR warm-cache path in `htax_invalidate_term_caches_on_delete` to avoid a DB query for group targets.
- `htax_reverse_rel_grp_{slug}_{term_id}` - Raw G→T rows for a relationship type, group sources only. Works for any slug including core `'includes'`. No TTL. Invalidated by `htax_delete_custom_rel_caches_for_term`.
- `htax_autocomplete_*` - Autocomplete caches for admin UI
- `htax_ss_{hash}` - Search state of a POST-mode search too large for an inline results-page token (`htax_search_state_encode()`; the token is `k{hash}`, a 16-hex content hash). TTL 30 days (`HTAX_SEARCH_STATE_TTL`); an expired token makes the results URL render as if no search was submitted.
- `htax_pl_t{ids}_f{ids}_p{page}_n{ppp}_m{max}_o{orderby}_x{excludes}` - Post list shortcode query results (see below)

### Post List Shortcode Cache (`htax_pl_*`)

The `[ht_post_list]` and `[ht_related_posts]` shortcodes cache the result of `query_posts_by_term_ids()` — the search-index query that returns a paginated list of post IDs with a total count. No TTL is set; caches are invalidated explicitly.

**What is stored:**
```php
array(
    'post_ids'  => int[],   // Ordered post IDs for the requested page
    'total'     => int,     // Total matching posts (respects max_results cap)
    'max_pages' => int,     // Total page count
)
```

**Key format:** `htax_pl_t{sorted-main-ids}_f{sorted-filter-term-ids}_g{sorted-filter-group-ids}_fo{sorted-filter-out-term-ids}_fog{sorted-filter-out-group-ids}_p{page}_n{ppp}_m{max}_o{orderby}_x{exclude-ids}`

- `t{id}` — one segment per main term ID (sorted ascending), e.g. `t3_t7`
- `f{id}` — one segment per individual filter term ID from `filter_terms` attr (sorted ascending); omitted if empty
- `g{id}` — one segment per filter GROUP ID from `filter_groups` attr (sorted ascending); omitted if empty. The group IDs themselves — not expanded member term IDs — are stored here, because `filter_groups` semantics are AND-across-groups/OR-within-group: merging all member term IDs into a flat list would conflate different configurations that happen to share the same union of members.
- `fo{id}` — one segment per individual exclusion term ID from `filter_out_terms` attr (sorted ascending); omitted if empty
- `fog{id}` — one segment per exclusion GROUP ID from `filter_out_groups` attr (sorted ascending); omitted if empty. Group IDs stored for same reason as `g{id}` above.
- `p{n}` — page number (visible in key so all pages for a given query can be pattern-matched)
- `n{n}` — posts_per_page
- `m{n}` — max_results (0 = unlimited)
- `o{key}` — compact orderby: first char of each field + `D`/`A` for direction, segments joined by `-`. Examples: `date:DESC` → `dD`; `date:DESC,title:ASC` → `dD-tA`; `date` → `d`
- `x{ids}` — exclude post IDs joined by `-`; omitted if empty

Caching is skipped if the key exceeds 172 characters (WordPress transient key limit). Hidden term visibility is permission-aware: `htax_get_hidden_term_filter_clause()` returns `AND st.is_hidden = 0` for unprivileged users and an empty string for privileged users. To prevent cross-user cache contamination, `H` is appended to the cache key when `htax_can_see_hidden_terms()` is true, so privileged users get a separate cache entry that includes hidden-term posts.

**Invalidation:**

IDs are embedded directly in the key, enabling `LIKE '%{prefix}{id}\_%'` pattern-based deletion. The `\_` escaped underscore prevents false matches (e.g. `t1` matching `t10`). Five prefix patterns are checked per ID: `t`, `f`, `g`, `fo`, `fog`.

`htax_invalidate_post_list_cache( int[] $term_ids )` deletes all matching transients. It is called from:

| Trigger | Hook | Handler | Notes |
|---------|------|---------|-------|
| Post-term assignment changed | `set_object_terms` (6 args) | `htax_post_list_cache_on_object_terms_set` | Post list: merges direct term IDs with search-index term IDs (index has container/hierarchy/redirect associations). Contrib: fires `htax_term_archive_changed` for each ID returned by `htax_expand_term_ids_for_contrib_invalidation` on the direct terms |
| Term relationships deleted outside `set_object_terms` | `deleted_term_relationships` | `htax_post_list_cache_on_term_relationships_deleted` | Skips if `set_object_terms` is currently running. Same dual strategy as above |
| Post published or unpublished | `transition_post_status` | `htax_post_list_cache_on_post_status_change` | Only fires on publish↔non-publish transitions. Post list: search-index IDs. Contrib: fires `htax_term_archive_changed` per taxonomy via `htax_expand_term_ids_for_contrib_invalidation` |
| Any HyperTaxonomy term relationship or visibility changed | via `htax_delete_term_transients()` | — | Ensures post list caches are invalidated when inclusions or group memberships change |
| Global cache clear | `htax_clear_all_caches()` | — | Covered by the `_transient_htax_` prefix pattern |

**Post list vs contrib invalidation strategies differ by design.** Post list uses the search index (pre-computes all relationship types for the post) because it needs every term whose shortcode query would return the post. Contrib uses `htax_expand_term_ids_for_contrib_invalidation` (queries `htax_get_container_terms()` + `get_ancestors()` directly) because the search index is built asynchronously and may not yet reflect a newly-added term assignment.

---

