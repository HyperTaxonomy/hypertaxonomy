---
title: Search Internals
parent: Architecture
nav_order: 5
---

## Search Query Architecture

### Two Systems

| Aspect | Search Widget | PowerSearch |
|--------|--------------|-----------------|
| **Input** | Selected term IDs (pills, checkboxes) | Search text string |
| **Purpose** | Filter posts by taxonomy terms | Text relevance scoring across post + term content |
| **Scoring** | noisy-OR per term, `SUM` across terms, per field — relationship-type weights baked into `strength` at index time | MySQL `MATCH() AGAINST()` NATURAL LANGUAGE MODE (post text fields); BOOLEAN MODE × `hst.strength` (taxonomy term names/descriptions) |
| **Index** | `idx_term_lookup` on `wp_htax_search_terms` | FULLTEXT indexes on `wp_htax_post_text` (`htax_ft_pt_title`, `htax_ft_pt_body`, `htax_ft_pt_excerpt`, `htax_ft_pt_all`) + `htax_ft_term_name` on `wp_terms` + `htax_ft_term_desc` on `wp_term_taxonomy` |
| **Query structure** | LEFT JOIN subqueries per field | Double-nested subquery (alias reference workaround) |
| **Class** | `HyperTaxonomy_Search_Widget` | `HyperTaxonomy_Power_Search` |

### Search Widget — Query Pattern

**Scoring fields** (`match='any'` or `match='all'`): one subquery per field sums strength across all matching terms. Rows sharing a `(post_id, term_id)` combine via noisy-OR — `1 − Π(1 − strength)` — regardless of which `source_term_id` or `relationship_type` produced them, so a term reached by several distinct source terms scores higher (monotonically) without the combined value ever reaching 1.0, the ceiling a single direct row can hit. This includes a term's own `direct` row when it has one — it combines into the same group as any indirect rows for that term rather than being tracked separately (see "Relevance aggregation" in [Database Schema](database-schema.html) for a worked example). A term reached by only one path is unaffected:
```sql
SELECT post_id, SUM(term_score) AS field_score
FROM (
  SELECT post_id, term_id,
         1 - EXP(SUM(LN(GREATEST(1 - strength, 0.000001)))) AS term_score
  FROM wp_htax_search_terms
  WHERE taxonomy = %s AND relationship_type IN (...) AND term_id IN (...)
    AND model = 'htax_tf'   -- htax_strength_model_sql()
  GROUP BY post_id, term_id
) t
GROUP BY post_id
```
Relationship-type weights are baked into `strength` at index build time — no dynamic weight multiplication at query time. Strength-reading subqueries restrict to one strength model (see `model` in [Database Schema](database-schema.html)); the exclusion and required-all subqueries below only test which posts reach a term, so they don't. The main query LEFT JOINs all field subqueries and applies a HAVING threshold on the sum.

**Exclusion fields** (`match='none'`): enforced via `WHERE NOT IN` before LIMIT — not via scoring. `flatten_field_structure_for_scoring()` leaves them out of the scoring set, so the exclusion/required-all loop flattens the structure separately with `$include_exclusions = true`; the conditions apply at every nesting level and are ANDed. A query of exclusion fields alone still runs (every indexed post except the excluded ones):
```sql
AND pt.post_id NOT IN (
    SELECT post_id FROM wp_htax_search_terms
    WHERE taxonomy = '{tax}' AND relationship_type IN (...) AND term_id IN (excluded_ids)
)
```

**Required-all fields** (`match='all'`): additionally enforced via `WHERE IN` with count before LIMIT, ensuring every selected term is individually matched:
```sql
AND pt.post_id IN (
    SELECT post_id FROM wp_htax_search_terms
    WHERE taxonomy = '{tax}' AND relationship_type IN (...) AND term_id IN (required_ids)
    GROUP BY post_id HAVING COUNT(DISTINCT term_id) >= {n}
)
```

Moving exclusion and required-all enforcement before LIMIT ensures that page sizes and total counts are accurate — post-SQL PHP filtering was the source of a pagination accuracy bug in earlier versions.

**AJAX path**: `ajax_search_posts()` passes the raw `$fields` structure (field_name → field_data with `terms`, `taxonomy`, `match`, and optional `expand_types` keys) directly to `get_posts_by_custom_sql()`. The WP-style tax_query produced by `build_taxonomy_query_with_hierarchy()` is used only for the POST/`pre_get_posts` path.

**`relationship_type IN (...)`** is computed per field by `HyperTaxonomy_Search_Widget::normalize_expand_types()`. By default it includes all types active for the taxonomy (`direct` always; `hierarchy` when hierarchical; `container` when `term_relationships` feature enabled; `redirect_target` when `redirects` feature enabled). A field carrying `expand_types` overrides this: `"all"` → feature-flag defaults; `"none"` → `direct` only; comma-separated tokens (`container`, `hierarchy`, `redirect`) → explicit subset. The `expand` shortcode attribute on `[ht_pills_field]`, `[ht_checklist_field]`, `[ht_hidden_field]`, and `[htax_distance_field]` sets this value.

### PowerSearch — Double-Nested Subquery

MySQL does not allow referencing column aliases from the same SELECT in a HAVING clause. The double-nested structure works around this:
```sql
SELECT scored.*,
       (title_score + content_score + excerpt_score + tags_score + categories_score + terms_score) AS relevance
FROM (
    SELECT pt.post_id,
           MATCH(pt.title) AGAINST(%s IN NATURAL LANGUAGE MODE) * {weight} AS title_score,
           -- taxonomy LEFT JOINs per field (tags, categories, terms, custom taxonomies):
           COALESCE(htax_tags.tags_score, 0) AS tags_score,
           -- ...
           pt.post_date_gmt AS post_date, pt.post_modified_gmt AS post_modified,
           pt.title AS post_title, pt.post_author, pt.comment_count,
           pt.last_comment_gmt,
           GREATEST(pt.post_modified_gmt, COALESCE(pt.last_comment_gmt, '0000-00-00 00:00:00')) AS last_activity_gmt
    FROM wp_htax_post_text pt
    -- LEFT JOINs for taxonomy scoring subqueries (htax_tags, htax_cats, htax_terms, etc.)
    WHERE pt.post_type IN ({indexed_post_types})
) AS scored
HAVING relevance >= {min_score} AND title_score >= {min_title_score} ...
ORDER BY {dynamic_orderby}
LIMIT {per_page} OFFSET {offset}
```

The same double-nested structure is used for the count query (wrapped in an additional `SELECT COUNT(*) FROM (...)`).

**Taxonomy scoring paths.** A query with no `tags:` / `categories:` / `terms:` operators scores every indexed taxonomy in one FULLTEXT scan over `wp_terms` (`$use_combined_tax_scan`): one LEFT JOIN whose `terms_score` sums a CASE-weighted score per taxonomy and relationship type. Queries with those operators use separate per-field LEFT JOINs (`tags_score`, `categories_score`, `terms_score`, custom taxonomies) so each field's score and threshold stay distinct.

Score thresholds on taxonomy fields don't change the path. `min_terms_score` compares the sum of all taxonomy columns (`tags_score + categories_score + terms_score` plus custom-taxonomy fields) — in a plain query that is just the combined `terms_score`.

**One taxonomy match, one field.** General words score each taxonomy once: in its own field when the query has one, otherwise in `terms_score`. A custom-taxonomy operator (`colors:x`) removes its taxonomy from the combined scan; in the field-specific path the tags and categories fields score general words, so `terms_score` scores `post_tag`, `category` and custom-field taxonomies with `terms:` words only (general exclusions kept in its term-name query) and skips them when there are none. Before 2026-10-29 these matches counted in two fields. `min_tags_score` / `min_categories_score` set `$split_tax_scores`: the same scan also carries each scored row's source-term taxonomy and returns per-taxonomy sub-totals (`tags_part`, `cats_part`), selected as `tags_threshold_score` / `categories_threshold_score` and compared in HAVING. Those columns are deliberately not relevance parts — `terms_score` already includes them — so a threshold query ranks exactly like the same query without the threshold, just filtered. (Before 2026-10-29 these thresholds switched to the per-field path, which was 2–3× slower on common words, counted tag and category matches twice in relevance, and didn't enforce general `-word` exclusions at post level.)

**One term, one score per post.** A post can reach a matched (source) term through several index rows — one per term it carries that leads there, and one per relationship type (primary key `post_id, term_id, source_term_id, relationship_type, model`). Every taxonomy scoring variant — combined scan and field-specific path alike — takes `MAX(score)` per `(post_id, source_term_id)` before summing, so a term reached through several paths counts once, at its strongest, and never outweighs a direct match on it. In the field-specific path the MAX also spans relationship types: each relationship-type subquery from `build_term_search_subqueries()` returns per-term rows, and `sum_term_scores_sql()` takes the MAX across them before the per-post SUM. Adding the source taxonomy to that grouping (for the threshold sub-totals) changes no group, since a source term has one taxonomy.

### Field Operators (PowerSearch)

Parsed via regex: `/([+\-]?)\b(title|content|body|excerpt|tags|categories|terms|...):(...)/i`

- `+term` required, `-term` excluded, no prefix = optional in OR mode / required in AND mode
- `title:word` — title field only; `tags:word` — tag taxonomy; `terms:word` — all enabled taxonomies
- Custom taxonomy operators: the taxonomy slug (e.g. `product_category:blue`) or the plural display label with spaces replaced by underscores (e.g. `product_categories:blue`); case-insensitive

---

### Search Widget — Detailed Query Architecture

For shortcode reference and configuration see the [User Guide § Advanced Search Widget](../user-guide/search.html#advanced-search-widget).

**Taxonomy Relevance Scoring:**
- `match='any'` fields: score = SUM across terms of a per-term noisy-OR combination of `strength` (see "Relevance aggregation" in [Database Schema](database-schema.html)) — relationship-type weights baked into `strength` at index time; HAVING field_score > 0
- `match='all'` fields: scored the same way, AND enforced via `WHERE IN … HAVING COUNT >= N` before LIMIT
- `match='none'` fields: no scoring subquery, no HAVING condition; enforced via `WHERE NOT IN` before LIMIT — excluded terms never reach the result set regardless of page position
- AND relations: field scores are summed; HAVING requires each scoring field score > 0
- OR relations: maximum field score is used

**Hierarchy and Inclusion Expansion (post scoring):**
Before scoring a post against the selected term IDs in a field, the widget expands the selected IDs via `get_terms_with_hierarchy($term_ids, $taxonomy)`. This expansion applies to any taxonomy that is hierarchical (`is_taxonomy_hierarchical()`) or has the `term_relationships` feature enabled. It follows both WP parent–child hierarchy (descendants) and HyperTaxonomy inclusion relationships, so selecting a parent term or container matches posts assigned to any child or included term. Results are cached per taxonomy + term ID set within the request.

#### Field Group Boolean Logic
```
Top Level: AND (all fields must match)
├── Field 1
├── Field 2
└── htax_field_group (relation="OR")
    ├── Field 3 (any of these)
    ├── Field 4 (any of these)
    └── htax_field_group (relation="AND")
        ├── Field 5 (all of these)
        └── Field 6 (all of these)
```

Query: (Field1 AND Field2 AND (Field3 OR Field4 OR (Field5 AND Field6)))

### Search Widget Validation

The search widget performs PHP-based validation during shortcode rendering to detect misconfigured forms before they produce unexpected results.

#### One Text Field Per Form, Top-Level Only

`[ht_text_field]` must be a direct child of `[ht_search_form]`, not nested inside a `[ht_field_group]`. The text field is a global filter applied to the full result set independently of the AND/OR taxonomy group structure.

Nested `[ht_field_group]` tags are paired by `HyperTaxonomy_Search_Widget::render_form_content()`, which renders the content of `[ht_search_form]` and of every group: WordPress's shortcode parser would close an enclosing `[ht_field_group]` at the first `[/ht_field_group]` it meets, so groups are matched with a depth counter instead and rendered through `field_group_shortcode()` (which recurses back into `render_form_content()`), while text between groups goes through `do_shortcode()` as usual. Each group renders a `.htax-field-group` element, and the JS collects the nesting from the DOM.

Initial selection attributes are resolved server-side. `HyperTaxonomy_Search_Widget::fold_group_selection_atts()` (called by the pills, checklist and hidden field shortcodes) folds `add_groups` / `remove_groups` into `add_terms` / `remove_terms` — member terms of the groups in the field's taxonomy in post mode, the group IDs themselves in term mode (`taxonomy_type="term"`) — and clears the `*_groups` attributes, so the JS only ever receives term IDs (`data-add-terms` / `data-remove-terms`). The checklist marks `add_terms` minus `remove_terms` as checked when rendering; pills and hidden fields apply the two lists in JS. Pills seed their `*_termdata` input with the names and icons of the initial selection, so its pills are labeled even without a prefetched option pool.

Controller group actions are resolved from a per-form map. `option_action_shortcode()` records the group IDs of every `add_groups` / `remove_groups` / `include_group_options` / `exclude_group_options` action per target field in `self::$forms[ $form_id ]['group_actions']`; after the form content has rendered (so every field and controller is known), `render_group_members_map()` outputs `<script type="application/json" class="htax-group-members">` with `{ field: { groups: { gid: [term IDs] }, terms: { id: {name, count, icon_url, placeholder_color} } } }` for pills and checklist targets — members in the field's taxonomy (descendant groups included) in post mode, the group itself in term mode; `terms` (pills only) labels pills for terms the field hasn't prefetched; hidden terms are omitted for viewers who can't see them. Hidden fields resolve groups from their own `data-groups-map` and are not included. `SearchForm` parses the map into `form.groupMembers`; `FieldBase._groupMemberIds()` resolves group IDs from it. PillsField `addGroups` / `removeGroups` add/remove the members (seeding `_termData` from `terms`) and merges the map into `_allOptionsByGroup` after prefetch so `include_group_options` / `exclude_group_options` can narrow the pool to groups outside the declared universe (the active set is still intersected with the prefetched universe). ChecklistField `addGroups` / `removeGroups` check/uncheck the members; checklist options are fixed at render time, so pool actions don't apply to it. Every checklist item — hierarchical and flat — carries `data-term-id`, which the selection actions use to find it.

Result loading: the AJAX handlers load the SQL page with `WP_Query( post__in, orderby=post__in, post_type=htax_get_indexed_post_types(), ignore_sticky_posts=true )`. The SQL has already applied every filter and the LIMIT, so this query must neither drop posts (a missing `post_type` defaults to `post`; `'any'` drops types excluded from search) nor add them (an argument-less query is "home", where WordPress prepends every sticky post).

JS always submits the hierarchical format: all taxonomy fields and declared `[ht_field_group]` sub-groups are placed into an implicit root AND group (`{relation, fields, groups}`). The text field is collected separately under a top-level `text_search` key — it is not part of the AND/OR group structure.

`extract_text_search_from_fields()` checks `$field_data['text_search']` first (JS-submitted hierarchical format). It falls through to a flat-field iteration (`type === 'text'`) only for the no-JS POST fallback, where PHP constructs the field data directly from `$_POST`.

`has_taxonomy_terms_in_fields()` handles the hierarchical format by recursing into `fields` and `groups`; the flat-iteration fallback covers the no-JS POST case.

If multiple text fields are detected during shortcode rendering, a visible warning is injected inside the form:

```html
<div class="htax-validation-warnings" style="background:#fff3cd;border:1px solid #ffc107;padding:10px;margin:10px 0;border-radius:4px;">
    <p style="margin:5px 0;color:#856404;">
        <strong>⚠ HyperTaxonomy:</strong> Multiple text fields detected (name1, name2). Only the first text field will be used.
    </p>
</div>
```

#### Implementation

- `track_field_type( $type, $name )` — called by each field shortcode; records field in `self::$forms[$form_id]['fields']`
- `get_validation_warnings( $form_id )` — iterates collected fields and returns warning strings
- Warnings are rendered after `do_shortcode()` processes nested content, before the closing `</form>` tag
- Only text fields have the "only one per form / top-level only" restriction; pills, checklist, and hidden fields may be nested freely inside `[ht_field_group]`

---

## Performance Architecture

### Reindex Queue System

Posts are queued via `queue_post_reindex()` rather than processed immediately. The queue deduplicates post IDs automatically; all queued posts are processed once at `shutdown`. Result: 2–5× improvement when a term edit triggers multiple change events for the same posts.

Example: User edits term name — no reindex triggered because term text is read from `wp_terms` at query time. User also changes includes → `htax_includes_changed` queues affected posts → shutdown processes each post once.

### Incremental Index Updates

For post-term assignments (`set_object_terms` / `deleted_term_relationships`):
1. Query current source terms from index (WHERE `relationship_type = 'direct'`) — 1 query
2. Calculate delta: added = new − current, removed = current − new
3. DELETE removed term relationships by `source_term_id` — 1 query
4. INSERT new term relationships (direct + container + hierarchy + redirect_target) — ~4–9 queries per new term

| Operation | Full Rebuild | Incremental | Speedup |
|-----------|-------------|-------------|---------|
| Add 1 term | ~12 queries | ~3 queries | **5×** |
| Remove 1 term | ~12 queries | ~2 queries | **6×** |
| Replace all terms | ~12 queries | ~14 queries | 0.8× |

Trade-off: replacing all terms is slightly slower (rare case), but typical small changes are 5–6× faster.

### Container Change Optimization

When `htax_containers_changed` or `htax_container_groups_changed` fires, the index is updated via direct SQL delta instead of a full reindex:
1. Invalidate container cache, reload via `htax_get_container_terms()` (handles direct + group expansion)
2. Query OLD container state from index
3. DELETE only removed containers, INSERT only added containers (delta-based)

Example: Adding 1 container to a term that already has 10 containers and 100 affected posts = 1 INSERT (not 1 DELETE + 11 INSERTs) — approximately 25× faster.

### Async Processing for Large Batches

When `batch_reindex_posts()` receives >100 posts, the post list is stored in WordPress options and a WP Cron event is scheduled for immediate background processing. The current request returns immediately (non-blocking), preventing PHP timeouts on shared hosting.

### Change Detection

Hooks skip unnecessary work before doing any computation:
- `on_taxonomy_hierarchy_changed` — skips non-hierarchical taxonomies, group taxonomy, and cases where the parent did not change
- `on_term_relationships_deleted` — skips if `set_object_terms` is currently running
- `on_terms_changed` — compares current index state with new term assignments before acting

### Cache Layers

Three distinct clearing functions with distinct purposes (cannot be consolidated):

| Priority | Handler | Scope |
|----------|---------|-------|
| 10 on `edited_term` | `htax_reload_term_caches_on_edit` | Relationship caches: `htax_inc_*`, `htax_cont_*`, `htax_excl_*`, `htax_redir_*`, `htax_groups_*`, `htax_direct_rel_*`, `htax_direct_rel_grp_*`, `htax_crel_*`, `htax_crel_rev_*` for all registered slugs |
| 15 on `edited_term` | `htax_clear_ajax_caches` | Autocomplete caches (broad clearing for admin UI freshness) |
| 30 on `edited_{tax}` | `htax_clear_count_cache_on_save` | Count caches (`htax_total_*`) based on final saved state |
| `added/updated/deleted_term_meta` | `htax_clear_hidden_terms_cache_on_meta` | `htax_hidden_terms` — only when `_htax_is_hidden` meta changes |
| `delete_term` | `htax_invalidate_term_caches_on_delete` | All above + `htax_sticky_{term_id}` + `htax_hidden_terms` (term may have been hidden) |
| REST UPDATE (`htax_rest_update_sticky_terms`) | `htax_rest_update_sticky_terms` | `_htax_sticky_{term_id}` per-term assignment keys written/deleted; `htax_sticky_{term_id}` transient cleared for each changed term |
| `delete_post` | `htax_cleanup_sticky_on_delete` | All `_htax_sticky_{term_id}` keys for the post |

### Bottlenecks to Monitor

- **Very large inclusion sets on high-traffic archives** — pre-computed in the search index, but very large expanded sets produce large result pages; cap with `max_results` or `posts_per_page`
- **Archive queries with many synonyms** — each synonym adds its posts to the expanded set; the index handles this in a single indexed lookup but the result size grows
- **Autocomplete search** (`htax_run_term_autocomplete_search`) — double-sided `LIKE '%…%'` scan on `wp_terms`; cached aggressively, acceptable for admin use
- **Sticky post prepending** — runs an additional query per archive page; has a per-term transient that invalidates on post save and assignment changes
- **Transient table growth** — `[ht_post_list]` cache keys are hashed and scoped to affected term sets; on very high write-volume sites monitor `wp_options` row count for `_transient_htax_pl_*`

---

## Native Block Group Filter

The HT Term Group Filter extends the native WordPress `core/query` (Query Loop) and `core/latest-posts` (Latest Posts) blocks. It requires no template changes — the filter is added through each block's inspector.

### Editor-Side (JavaScript)

`blocks/ht-query-group-filter/src/index.js` registers two `addFilter` calls that target both `core/query` and `core/latest-posts`:

1. **`blocks.registerBlockType`** — runs once per block registration. Appends `htax_filter_groups: { type: 'string', default: '' }` to each block's attribute schema so the value is persisted in post_content.

2. **`editor.BlockEdit`** — wraps every block's `Edit` component using `createHigherOrderComponent`. For blocks other than `core/query` and `core/latest-posts` the wrapper is a no-op passthrough. For the two target blocks it renders both the original `BlockEdit` and an extra `<InspectorControls>` panel containing a `GroupTokenField`. Group IDs are stored as a comma-separated string in `htax_filter_groups`.

The block's `block.json` sets `"supports": {"inserter": false}` to prevent it from appearing in the block inserter. The `register_block_type` call in `HyperTaxonomy_Blocks::register()` has no render callback — the registration exists solely to auto-enqueue the `editorScript` via `block.json`.

### Server-Side (PHP)

**Query Loop block** — `htax_query_loop_group_filter( $query_vars, $block, $page )` hooked to `query_loop_block_query_vars` at priority 10.

Algorithm:

1. Read `$block->parsed_block['attrs']['htax_filter_groups']`; bail if empty.
2. Split to integer group IDs; bail if none survive.
3. Detect post type from `$query_vars['post_type']` (default `'post'`).
4. Delegate to `htax_build_group_tax_query( $group_ids, $post_type )` (shared with group archives — see below).
5. If the block already has a `tax_query` (native category/tag filter), wrap both in `relation: AND`.

**Latest Posts block** — `htax_latest_posts_group_filter( $pre_render, $parsed_block )` hooked to `pre_render_block` at priority 10.

`core/latest-posts` has no equivalent to `query_loop_block_query_vars` — it builds a `WP_Query` directly in its render callback. The filter intercepts `pre_render_block`, reads `htax_filter_groups` from the block's attributes, then registers a one-shot `pre_get_posts` callback that injects the resolved `tax_query` before the block's `WP_Query` executes. The callback removes itself immediately after firing, so it does not affect any other query on the same page. `core/latest-posts` always queries the `post` post type.

**Shared tax_query builder — `htax_build_group_tax_query( array $group_ids, string $post_type = '' ): array`** (in `group-functions.php`):

- Expands groups to subgroups, checks the `group_assignment` feature flag per taxonomy, filters hidden terms for unprivileged users.
- When `$post_type` is non-empty, limits taxonomies to those registered for that post type; pass empty string in archive contexts where all enabled taxonomies apply.
- Returns `['relation' => 'OR', ...clauses]`. Callers check `count($result) > 1` before applying.
- Used by `htax_query_loop_group_filter` (Query Loop), `htax_latest_posts_group_filter` (Latest Posts), and `htax_handle_group_archives` (term archive handler), ensuring identical filtering logic in all contexts.

**OR logic rationale:** Multiple groups = union. Selecting groups A and B includes posts from A, B, or both. Within a single group, subgroup expansion is inclusive. Across taxonomies, OR is used because a post might carry the relevant term in any of its taxonomies — requiring ALL taxonomies to match would incorrectly exclude posts that use only one of them.

---

## AJAX Endpoints

### wp_ajax_htax_get_group_terms
Get terms from specified Term Groups

### wp_ajax_htax_search_groups
Admin-only group term autocomplete. Requires `htax-autocomplete` nonce and `manage_term_relationships` capability. Returns bucket-sorted results via `htax_run_term_autocomplete_search()`.

### wp_ajax_htax_search_terms
Unified autocomplete for the term edit screen inclusions UI. Requires `htax-autocomplete` nonce and `manage_term_relationships` capability. Accepts `taxonomies[]` (array of taxonomy slugs to search alongside the `group` taxonomy). Groups appear at the top of results (prefixed with a filled-square marker in JS). Term results are deduplicated by ID across taxonomies. Hidden terms are filtered out for users without `manage_term_visibility`.

**Request parameters:**
- `taxonomies[]` — array of enabled taxonomy slugs to search (at least one required)
- `term` — search string (minimum 2 characters)
- `exclude_terms[]` — term IDs to exclude from results
- `exclude_groups[]` — group IDs to exclude from results
- `current` — current term ID (auto-added to exclude_terms)
- `nonce` — `htax-autocomplete` nonce

Each result item: `{ id, value, label, item_type: 'term'|'group' }`

## Security Measures

1. **ABSPATH Checks:** All files check for direct access
2. **Nonce Verification:** All AJAX and form submissions
3. **Capability Checks:** HyperTaxonomy custom capabilities (e.g., `manage_term_relationships`, `manage_term_redirects`) for term edits
4. **SQL Injection Prevention:** $wpdb->prepare() everywhere
5. **XSS Prevention:** esc_html(), esc_attr(), wp_kses()
6. **CSRF Protection:** WordPress nonces
7. **Data Sanitization:** sanitize_text_field(), wp_unslash()

## Credits
- **Author:** Eugene Weinstein
- **License:** GPLv2 or later
- **Requires:** WordPress 6.6+, PHP 7.4+, MySQL 5.7+ (MariaDB 10.3+)
- **Version:** 1.0.0

## Support
- **Documentation:** https://wordpress.org/plugins/hypertaxonomy/#description
- **Support Forum:** https://wordpress.org/support/plugin/hypertaxonomy/
- **Plugin page:** https://hypertaxonomy.org/hypertaxonomy/
