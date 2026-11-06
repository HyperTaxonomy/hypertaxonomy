---
title: Search Internals
parent: Architecture
nav_order: 6
---

# Search Internals

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

Enforcing exclusion and required-all conditions before LIMIT keeps page sizes and total counts accurate.

**AJAX path**: `ajax_search_posts()` passes the raw `$fields` structure (field_name → field_data with `terms`, `taxonomy`, `match`, and optional `expand_types` keys) directly to `get_posts_by_custom_sql()`, which POST searches also use. A search with only a filter panel selection lists matching posts unscored, newest first. With no text, terms, exclusions or filter, the function returns no posts and sets `no_criteria`, which `ajax_search_posts()` includes in its response.

**`relationship_type IN (...)`** is computed per field by `HyperTaxonomy_Search_Widget::normalize_expand_types()`. By default it includes all types active for the taxonomy (`direct` always; `hierarchy` when hierarchical; `container` when `term_relationships` feature enabled; `redirect_target` when `redirects` feature enabled). A field carrying `expand_types` overrides this: `"all"` → feature-flag defaults; `"none"` → `direct` only; comma-separated tokens (`container`, `hierarchy`, `redirect`) → explicit subset. The `expand` shortcode attribute on `[ht_pills_field]`, `[ht_checklist_field]`, `[ht_hidden_field]`, and `[ht_distance_field]` sets this value.

### PowerSearch — Double-Nested Subquery

MySQL does not allow one expression in a SELECT to reference a column alias from the same SELECT, so `relevance` can't be computed from the field scores directly. The double-nested structure works around this:
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

**One taxonomy match, one field.** General words score each taxonomy once: in its own field when the query has one, otherwise in `terms_score`. A custom-taxonomy operator (`colors:x`) removes its taxonomy from the combined scan; in the field-specific path the tags and categories fields score general words, so `terms_score` scores `post_tag`, `category` and custom-field taxonomies with `terms:` words only (general exclusions kept in its term-name query) and skips them when there are none. `min_tags_score` / `min_categories_score` set `$split_tax_scores`: the same scan also carries each scored row's source-term taxonomy and returns per-taxonomy sub-totals (`tags_part`, `cats_part`), selected as `tags_threshold_score` / `categories_threshold_score` and compared in HAVING. Those columns are deliberately not relevance parts — `terms_score` already includes them — so a threshold query ranks exactly like the same query without the threshold, just filtered.

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
- Field scores are summed in both relations; AND relations require each scoring field score > 0 in HAVING, and OR relations require at least one

**Hierarchy and Inclusion Expansion (post scoring):**
The search index already holds a row for each way a post reaches a term (`direct`, `hierarchy`, `container`, `redirect_target`), so selecting a parent term or container matches posts assigned to any child or included term. The widget filters those rows by `relationship_type` rather than expanding the selected term IDs.

#### Field Group Boolean Logic
```
Top Level: AND (all fields must match)
├── Field 1
├── Field 2
└── [ht_field_group relation="OR"]
    ├── Field 3 (any of these)
    ├── Field 4 (any of these)
    └── [ht_field_group relation="AND"]
        ├── Field 5 (all of these)
        └── Field 6 (all of these)
```

Query: (Field1 AND Field2 AND (Field3 OR Field4 OR (Field5 AND Field6)))

### Search Widget Validation

The search widget performs PHP-based validation during shortcode rendering to detect misconfigured forms before they produce unexpected results.

#### One Text Field Per Form, Top-Level Only

`[ht_text_field]` must be a direct child of `[ht_search_form]`, not nested inside a `[ht_field_group]`. The text field is a global filter applied to the full result set independently of the AND/OR taxonomy group structure.

#### Nested Field Groups

Nested `[ht_field_group]` tags are paired by `HyperTaxonomy_Search_Widget::render_form_content()`, which renders the content of `[ht_search_form]` and of every group: WordPress's shortcode parser would close an enclosing `[ht_field_group]` at the first `[/ht_field_group]` it meets, so groups are matched with a depth counter instead and rendered through `field_group_shortcode()` (which recurses back into `render_form_content()`), while text between groups goes through `do_shortcode()` as usual. Each group renders a `.htax-field-group` element, and the JS collects the nesting from the DOM.

#### Initial Selection and Actions

Initial selection attributes are resolved server-side. `fold_group_selection_atts()` also converts `add_terms`, `remove_terms`, `include_term_options` and `exclude_term_options` from slugs to IDs, since the JS only reads IDs, and drops terms hidden from the viewer from `add_terms` and `include_term_options` (`filter_visible_term_ids()`). A tampered request naming a hidden term finds nothing anyway: search queries apply `htax_get_hidden_term_filter_clause()`. `HyperTaxonomy_Search_Widget::fold_group_selection_atts()` (called by the pills, checklist and hidden field shortcodes) folds `add_groups` / `remove_groups` into `add_terms` / `remove_terms` — member terms of the groups in the field's taxonomy in post mode, the group IDs themselves in term mode (`taxonomy_type="term"`) — and clears the `*_groups` attributes, so the JS only ever receives term IDs (`data-add-terms` / `data-remove-terms`). The checklist marks `add_terms` minus `remove_terms` as checked when rendering; pills and hidden fields apply the two lists in JS. Pills seed their `*_termdata` input with the names and icons of the initial selection, so its pills are labeled even without a prefetched option pool.

Term actions and field-level term attributes use the same map. `fold_group_selection_atts()` saves the field's own `add_terms` as `_explicit_add_terms` before folding groups in, and `option_action_shortcode()` records `add_terms` and `include_term_options` IDs under `term_actions`. `render_group_members_map()` then gives each target: `implied`, the explicit field-level IDs that exist, aren't in `exclude_term_options`, and aren't hidden from this viewer; `terms`, names and icons for every term named; `check_terms`, set when actions name terms, which makes `_isKnownTerm()` skip anything missing from `terms`; and for checklists `initial`, the implied terms to check. A forward `add_terms` action calls `addTerms( ids, true )`, which brings a term outside the pool into the field's `_actionTerms` (`_canJoinPool()` refuses terms missing from `terms`); `removeTerms()` takes it back out, and reversing a `remove_terms` action calls `addTerms()` without the flag, so it only restores terms still in the pool. `_impliedTerms()` returns `implied` plus `_actionTerms`. Pills add it to `_computeActiveOptions()` and to the `include_terms` autocomplete parameter (only when a pool is declared, since naming terms would otherwise narrow an open pool); hidden fields add it to `_computeActivePool()`; checklists add and remove an option for it (`_addOption()`, which places it by name among its siblings, under its parent when listed, using the `parent` from `build_terms_data()`). Pills apply `include_term_options` and `exclude_term_options` through `_includeTerms` / `_excludeTerms`. Once a prefetch finishes, pills drop selected terms outside the pool, which catches `add_groups` terms added before the pool was known.

Controller group actions are resolved from a per-form map. `option_action_shortcode()` records the group IDs of every `add_groups` / `remove_groups` / `include_group_options` / `exclude_group_options` action per target field in `self::$forms[ $form_id ]['group_actions']`; after the form content has rendered (so every field and controller is known), `render_group_members_map()` outputs `<script type="application/json" class="htax-group-members">` with `{ field: { groups: { gid: [term IDs] }, terms: { id: {name, count, icon_url, placeholder_color} } } }` for pills and checklist targets — members in the field's taxonomy (descendant groups included) in post mode, the group itself in term mode; `terms` (pills only) labels pills for terms the field hasn't prefetched; hidden terms are omitted for viewers who can't see them. Hidden fields resolve groups from their own `data-groups-map` and are not included. `SearchForm` parses the map into `form.groupMembers`; `FieldBase._groupMemberIds()` resolves group IDs from it. PillsField `addGroups` / `removeGroups` add/remove the members (seeding `_termData` from `terms`) and merges the map into `_allOptionsByGroup` after prefetch so `include_group_options` / `exclude_group_options` can narrow the pool to groups outside the declared universe (the active set is still intersected with the prefetched universe). ChecklistField `addGroups` / `removeGroups` check/uncheck the members; checklist options are fixed at render time, so pool actions don't apply to it. Every checklist item — hierarchical and flat — carries `data-term-id`, which the selection actions use to find it.

#### Result Loading

The AJAX handlers load the SQL page with `WP_Query( post__in, orderby=post__in, post_type=htax_get_indexed_post_types(), ignore_sticky_posts=true )`. The SQL has already applied every filter and the LIMIT, so this query must neither drop posts (a missing `post_type` defaults to `post`; `'any'` drops types excluded from search) nor add them (an argument-less query is "home", where WordPress prepends every sticky post).

#### Submitted Format

JS always submits the hierarchical format: all taxonomy fields and declared `[ht_field_group]` sub-groups are placed into an implicit root AND group (`{relation, fields, groups}`). The text field is collected separately under a top-level `text_search` key — it is not part of the AND/OR group structure.

`extract_text_search_from_fields()` checks `$field_data['text_search']` first (JS-submitted hierarchical format). It falls through to a flat-field iteration (`type === 'text'`) only for the no-JS POST fallback, where PHP constructs the field data directly from `$_POST`.

`has_taxonomy_terms_in_fields()` handles the hierarchical format by recursing into `fields` and `groups`; the flat-iteration fallback covers the no-JS POST case.

#### Multiple Text Field Warning

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

### Search Widget Fields

**Group-descendant checklist (`include_descendant_options` + `filterby`):**
- When `include_descendant_options` is set on a checklist field, `render_checklist_field()` routes to `render_descendant_options_checklist()`.
- `include_descendant_options="936"` expands root group 936 via `htax_get_group_hierarchy()`, then removes the roots themselves — only descendants appear as checkboxes.
- `taxonomy` should be a term-organizing taxonomy registered with no post object types (e.g. `"group"`): the displayed options are group terms. `filterby="post_tag"` (or a comma-separated taxonomy list) tells the query layer which post taxonomies to expand selected group IDs into at query time; omit to expand across every HyperTaxonomy-enabled taxonomy that is attached to a post type.
- `exclude_term_options` removes specific group term IDs from the displayed set.
- `hierarchical="true"` renders a nested tree via `render_descendant_option_tree()`; `"false"` renders a flat alphabetical list via `render_descendant_option_item()`. Each item carries `data-term-id` and `data-parent-id` attributes.
- Submitted values are group IDs. Term-organizing taxonomies have no post types, so the search index holds no rows for them; `HyperTaxonomy_Search_Widget::expand_term_type_fields()` (called at the start of `get_posts_by_custom_sql()`, covering both AJAX and POST searches) detects such fields by `empty( $tax_object->object_type )` and expands the selected group IDs (with descendant groups) via `htax_get_terms_in_groups()` into member terms of each taxonomy in the field's `filterby` list — or of every indexed HyperTaxonomy taxonomy when `filterby` is omitted. A post is "in" a group when it carries a member term in any target taxonomy. With `match="any"` / `"none"` the field covers the members of all selected groups: one target taxonomy replaces the field in place; several become an `OR` sub-group with one field per taxonomy (`group_member_structure()`). With `match="all"` the post must be in every selected group: the field becomes an `AND` sub-group with one any-match member structure per selected group (fields named `{field}_g{group_id}`). A group with no members becomes term `0`, so it matches nothing rather than being ignored.
- `addDescendants(groupIds)` / `removeDescendants(groupIds)` — available both as controller action handlers and as initial-state shortcode attributes (`add_descendants`, `remove_descendants`). `_getDescendantItemIds(parentId)` recurses via `data-parent-id` DOM attributes to collect all descendant item IDs. After setting checked state, `_resetImpliedState()` clears the `htax-implied-by-parent` class and re-enables disabled inputs, then `initHierarchy()` re-applies the parent→child implied-state machinery from scratch. For PillsField, `addDescendants(groupIds)` resolves term IDs from `_allOptionsByGroup` (or queues them in `_pendingAddDescendants` if prefetch is still in flight).

**PillsField option-pool architecture:**

PillsField supports two option-loading modes controlled by the `prefetch` shortcode attribute (default `"true"`).

**`prefetch="true"` (default) — one upfront load, client-side filtering:**
- On page load JS makes **one** AJAX call (`htax_prefetch_options`) to fetch all member terms of the groups declared in `include_group_options`, receiving `{ terms: {id: {name, count, icon_url, placeholder_color}}, groups: {groupId: [termIds]} }`. `count` is the native WP post count from `wp_term_taxonomy.count`.
- Results are stored in `_allOptionsById` (Map termId→`{id, name, count, icon_url, placeholder_color}`) and `_allOptionsByGroup` (Map groupId→Set<termId>). Group membership is hierarchy-expanded at index time (PHP calls `htax_get_terms_in_groups` which expands descendants), so `_allOptionsByGroup.get(gid)` contains all terms belonging to gid's full subtree.
- `_activeGroups` (Set) tracks which groups are currently allowed by controller actions. Starts empty (= no restriction = full universe active).
- `_computeActiveOptions()` derives `_activeOptions[]` from `_activeGroups ∩ _allOptionsById`; called after every `includeGroupOptions()`/`excludeGroupOptions()` call.
- Autocomplete is **client-side only** — no per-keystroke AJAX. When `suggestOnFocus` is true (default), the dropdown opens immediately on focus (`minLength: 0`). When false (`suggest_on_focus="false"`), `minLength` is 1 so the dropdown only opens after the user types. Suggestion filtering is 3-tier: **0 chars** = all active unselected options sorted by `count` DESC; **1–2 chars** = prefix match (`name.startsWith`) sorted by `count` DESC; **3+ chars** = substring match (`name.includes`) in natural order. All tiers cap at 10 results.
- `excludeGroupOptions()` computes removals client-side (no AJAX needed) using `_allOptionsByGroup`.
- `addDescendants(groupIds)` / `removeDescendants(groupIds)` — **selection** actions (not pool restriction): add or remove as selected pills all terms in `_allOptionsByGroup` for the given group IDs. If called before prefetch completes, group IDs are queued in `_pendingAddDescendants` / `_pendingRemoveDescendants` and flushed in the prefetch success callback.

**`prefetch="false"` — AJAX-on-demand autocomplete:**
- No upfront option load; hidden terms are left out of responses for visitors who can't see them. The autocomplete input sends a request to `htax_autocomplete_options` on each keystroke (debounced 300 ms). `suggest_on_focus="false"` is implicit in this mode — `minLength` is already 1 by default when `suggestOnFocus` is false, so no empty-string AJAX call fires on focus.
- `_activeGroups` is still maintained by controller actions (same `includeGroupOptions()`/`excludeGroupOptions()` calls), so the server receives only the currently-active group IDs as a filter.
- Server-side handler (`ajax_autocomplete_options`): accepts `taxonomy`, `search`, `show_icons`, `groups` (comma-separated active group IDs), and `include_terms` / `exclude_terms` (comma-separated term IDs). Group members and `include_terms` form the pool: minus `exclude_terms` and hidden terms, it's passed to `get_terms()` as `include`. With no pool, `exclude_terms` plus any hidden terms the viewer can't see are passed as `exclude`. Returns up to 20 results ordered by name when a search string is present, or by post count when the input is empty.
- Term data returned by AJAX is cached in `_termData[id]` so `addPill()` can reference it immediately after selection without a second round-trip.
- `_isKnownTerm()` always returns `true` in this mode (no local universe to check against).
- `excludeGroupOptions()` is a no-op in this mode (server already filters by active groups on each request).
- `_pendingAddDescendants` / `_pendingRemoveDescendants` queues are not used in this mode (no prefetch callback to flush them into).

**PillsField placeholder:**
- `_originalPlaceholder` is saved from the input element's `placeholder` attribute at construction time. `enforceConstraints()` restores it when the count drops below the maximum (avoiding stale "Maximum items reached" text persisting after a pill is removed). The fallback `'Type to search...'` is only used when the shortcode `placeholder` attribute was not set.

**Search form state preservation (POST round-trip):**
- On POST form submission, `preparePostSubmission()` serializes the full UI state in two ways:
  1. `htax_field_data` JSON hidden input — hierarchical structure of field→term selections, built by `collectFieldDataHierarchical()` using each field's `getValue()` result.
  2. `htax_ui_state` JSON hidden input — flat map of `controller:name → show_when` and `enabled:controller:name → true/false` entries, written by `saveUiState()`.
- PHP restoration on re-render: `get_saved_ui_state()` (static-cached) parses `htax_ui_state`; controllers restore their `show_when` and `enabled` state; fields restore only their `enabled` state (not `show_when` — controller actions during JS `init()` re-derive field visibility from scratch).
- **Locked controller checkboxes:** when a checkbox controller's `min_items` constraint is at its limit, the last checked option's input gets `disabled` via `enforceConstraints()`. Browsers omit disabled inputs from POST. `preparePostSubmission()` compensates by appending a hidden `<input class="htax-locked-proxy">` for every `input[type="checkbox"].htax-locked`, so `get_post_controller_values()` sees the value in `$_POST[$name]` on the next render.
- **Keyboard navigation in autocomplete:** jQuery UI autocomplete handles Up/Down arrow navigation and Enter selection natively. The custom `select` callback calls `addPill()` and clears the input; the `focus` callback (autocomplete's item-focus event, not DOM focus) prevents the input text from being replaced with the highlighted item's label while navigating.

**Validation messages for `[ht_pills_field]` / `[ht_checklist_field]`:**
- `min_items_message` — shown when fewer than `min_items` items are selected; falls back to a built-in string ("Please select at least N items.").
- `max_items_message` — shown when more than `max_items` items are selected; falls back to a built-in string ("Please select no more than N items.").
- Messages appear in a `<div class="htax-validation-message">` rendered immediately after the pills/checklist container. The error styling (`htax-error` class + red border on the container) is set on submit and cleared automatically as soon as the constraint is satisfied without requiring another submit attempt.

### PowerSearch — Scoring and Execution

The PowerSearch system uses MySQL FULLTEXT indexes for performance with support for field-specific operators, minimum score thresholds, search scope restriction, and dynamic ordering.

*Query Parsing (htax_parse_search_query in search.php, called from perform_indexed_text_search):*
- Regex pattern: `/([+\-]?)\b(title|content|body|excerpt|tags?|categories?|terms?|...):("([^"]+)"|(\S+))/i`
- Singular aliases accepted: `tag:` = `tags:`, `category:` = `categories:`, `term:` = `terms:`, `body:` = `content:`
- Extracts field-specific terms with modifiers into arrays: title, body, excerpt, tags, categories, terms, general, plus parsed `near:` tokens under `proximity`
- Each term stored as: `array('term' => 'word', 'required' => bool, 'excluded' => bool, 'quoted' => bool, 'slug_match' => bool)`
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
1. Check 60-second transient cache; return cached result on hit. The cache key (`htax_ft_…`) is an MD5 of the query, the config, the hidden-terms visibility flag, and the resolved proximity condition. Including the flag prevents cross-user contamination: a result set computed for a user who can see hidden terms is never served to one who cannot, and vice versa. Including the proximity condition keeps `near:me` results separate per location.
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
   - **Branch A (exact match)**: `hst.strength AS score` for terms whose `LOWER(name) = LOWER(search_string)` — no FULLTEXT scan, IDs pre-fetched via `htax_get_exact_match_term_ids()`. Posts where the matched term is more central to the content rank higher. These terms are excluded from Branch B to prevent double-scoring. Branch A fires: always for field-specific operators (`tags:target`); only for quoted general terms (`"target"`) to avoid mistaking post-content searches for term-label lookups.
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

Post text fields (title, content, excerpt) use **NATURAL LANGUAGE MODE** so that IDF (inverse document frequency) and document-length normalization prevent a single high-frequency term from dominating results. A very long post that repeats one query word hundreds of times does not outrank a shorter post that covers all query terms meaningfully. Taxonomy term fields continue to use **BOOLEAN MODE** (small corpus, phrase/operator support required). Required and excluded term enforcement for all fields is handled via explicit WHERE clauses — not by relying on the score being zero.

**Field Score Calculation:**
- **title_score**: `MATCH(pt.title) AGAINST(nl_query IN NATURAL LANGUAGE MODE) * title_weight`
- **content_score**: `MATCH(pt.body) AGAINST(nl_query IN NATURAL LANGUAGE MODE) * content_weight`
- **excerpt_score**: `MATCH(pt.excerpt) AGAINST(nl_query IN NATURAL LANGUAGE MODE) * excerpt_weight`
  - All three fields come from `wp_htax_post_text` (`pt`), processed by `htax_extract_post_body()` — link URLs are appended as `[URL]`, img alt text and abbr expansions are preserved, then all remaining HTML is stripped. All public queries filter `pt.post_status = 'publish'`.
  - `nl_query` = query terms with `+`/`-` operators stripped and excluded terms removed; produced by `htax_build_natural_language_query_string()` (in search.php). NL MODE does not support phrase syntax, so multi-word phrases contribute their individual words to the score; phrase presence is enforced separately via a WHERE guard (see below).
- **tags_score / categories_score / terms_score / custom taxonomy scores**: up to three branches per relationship type. `hst.strength` encodes the per-row relevance contribution (TF-based for direct rows; `source_tf × explicit_weight` for indirect rows — see the [`wp_htax_search_terms` schema](database-schema.html#custom-database-tables)):
  - The branches and the `MAX` / `SUM` aggregation are described in step 10 above. Description weight defaults to about 70% of name weight.
  - Relationship types: direct, hierarchy (hierarchical taxonomies), container, redirect_target — all gated by feature flags at query time

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

"+tags:javascript tutorial" (match="any")
  → "javascript" tag REQUIRED (no javascript tag → excluded even if text mentions javascript), "tutorial" optional in any field
```

### Index Management

- htax_build_post_search_index($post_id): Indexes single post, called on publish/update
- htax_index_post_terms($post_id, $relationships): Stores post-term association rows; handles any enabled taxonomy generically — hierarchy ancestors for any `is_taxonomy_hierarchical()` taxonomy, containers for any `term_relationships`-enabled taxonomy
- Bulk reindex via admin AJAX: empty `wp_htax_post_text`, clear `wp_htax_search_terms` with `clear_search_terms_for_rebuild()`, which keeps external models' scores for posts that will be reindexed, then reindex all published posts (all enabled taxonomies processed uniformly)
- Delete on post deletion: removes the post's rows from `wp_htax_search_terms` and `wp_htax_post_text`
- `htax_expand_term_hierarchy($term_ids, $taxonomy)`: Helper to expand term IDs to include all descendants for any taxonomy
- `htax_upsert_search_rows($table_name, $placeholders, $values, $error_context)`: Shared helper for the standard 6-column upsert (`post_id, term_id, source_term_id, taxonomy, relationship_type, is_hidden`) with `ON DUPLICATE KEY UPDATE is_hidden = VALUES(is_hidden)`. Used by the hierarchy, container, includes, and redirect index writers. New rows get the column-default `strength` and `model`, so every caller also queues the affected posts for reindex to compute their strengths. The variant that includes `strength` (full reindex path) does not use this helper.

### Strength Models (includes/core/strength-models.php)

- Registry (in memory, per request): `htax_register_strength_model( $key, $args )`, `htax_unregister_strength_model( $key )`, `htax_get_strength_models()`, `htax_validate_strength_model_key( $key )`. `htax_tf` and every other `htax_`-prefixed key are reserved: registering or unregistering them returns false.
- Selection: `htax_get_strength_model( $purpose )` returns `htax_settings['strength_model']` when that model is registered, otherwise `htax_tf`. `htax_strength_model_sql( $alias, $purpose )` builds the ` AND {alias}.model = '…'` condition. The Settings sanitizer only accepts a new selection with full coverage; code writing `htax_settings` directly bypasses that check.
- Writes: `htax_set_term_strengths( $model, $post_id, $strengths )` (validates every score and term first, then writes direct + derived rows in one `INSERT … ON DUPLICATE KEY UPDATE`; returns `true|WP_Error`), `htax_remove_term_strengths( $model, $post_id = 0, $term_ids = [] )` (returns rows removed or `WP_Error`). Both refuse `htax_tf` and fire `htax_term_strengths_updated`.
- Coverage: `htax_get_strength_model_coverage( $model )` (per taxonomy: `htax_tf` direct pairs vs. the model's direct pairs, via a primary-key self-join), `htax_strength_model_has_full_coverage( $model )`.
- Internal helpers shared with `htax_index_post_terms()`: `htax_derive_indirect_strength()`, `htax_collect_strength_rows()`, `htax_write_strength_rows()`, `htax_get_external_direct_strengths()`.
- `htax_get_stored_strength_models()`: distinct external model keys with stored rows, registered or not.
- Admin: Settings → Search → Strength Model / Index Coverage / Index Rebuild render one panel per registered model plus one per *inactive* model (stored rows, not registered), and the dropdown shows the model in effect (`render_search_tab()`). Inactive models are listed only so their scores can be cleared: the sanitizer never saves an unregistered key. The form sends `strength_model_changed` (set to 1 by JS on change); when it is 0 the saved selection is left alone, so saving the tab while a selected provider is inactive keeps its selection. A note under the dropdown appears when the saved model differs from the one in effect.
- Clear Scores calls `htax_ajax_clear_strength_model()` (`admin/ajax.php`, nonce `htax-clear-strength-model`), which also switches the selection back to `htax_tf` if the cleared model was selected.

## Performance Architecture

### Reindex Queue System

Posts are queued via `queue_post_reindex()` rather than processed immediately. At `shutdown`, the request's queue is deduplicated and added to the `htax_pending_reindex` option, and the `htax_process_reindex_batch` cron event works through it in batches of `REINDEX_BATCH_SIZE` (100) posts per run.

Example: User edits term name — no reindex triggered because term text is read from `wp_terms` at query time. User also changes includes → `htax_includes_changed` queues affected posts → each post is reindexed once.

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

`batch_reindex_posts()` always defers to WP Cron, whatever the batch size, so reindexing never runs in the request that queued it. This prevents PHP timeouts on shared hosting.

### Change Detection

Hooks skip unnecessary work before doing any computation:
- `on_taxonomy_hierarchy_changed` — skips non-hierarchical taxonomies, group taxonomy, and cases where the parent did not change
- `htax_post_list_cache_on_term_relationships_deleted` — skips if `set_object_terms` is currently running
- `on_term_relationships_added` — compares the indexed source terms with the post's actual terms and acts only on the difference

### Cache Layers

These clearing functions have distinct purposes and can't be consolidated:

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
- **Transient table growth** — `[ht_post_list]` cache keys contain the term IDs they depend on; on very high write-volume sites monitor `wp_options` row count for `_transient_htax_pl_*`

---

## Native Block Group Filter

The HT Term Group Filter extends the native WordPress `core/query` (Query Loop) and `core/latest-posts` (Latest Posts) blocks. It requires no template changes — the filter is added through each block's inspector.

### Editor-Side (JavaScript)

`blocks/ht-query-group-filter/src/index.js` registers two `addFilter` calls that target both `core/query` and `core/latest-posts`:

1. **`blocks.registerBlockType`** — runs once per block registration. Appends `htax_filter_groups: { type: 'string', default: '' }` (comma-separated group IDs; the PHP side also accepts an array) to each block's attribute schema so the value is persisted in post_content.

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

These are the term and group autocomplete endpoints. For the other handlers, see the [Function Reference](function-reference.html).

### wp_ajax_htax_get_group_terms
Get terms from specified Term Groups

### wp_ajax_htax_search_terms
Autocomplete for the term edit screen's relationship fields. Requires `htax-autocomplete` nonce and `manage_term_relationships` capability. Searches every enabled taxonomy in `taxonomies[]`. With more than one, each label names its taxonomy, and results are ordered by names starting with the search, then names containing it, then description matches. Groups are added through their own checklist, not this endpoint. Hidden terms are filtered out for users who can't see them.

**Request parameters:**
- `taxonomies[]` — taxonomy slugs to search (`taxonomy` is accepted for one; default `post_tag`)
- `term` — search string (minimum 2 characters)
- `exclude_terms[]` — term IDs to exclude from results
- `include_groups` / `exclude_groups` — comma-separated group IDs; their member terms form the pool, or are left out of it
- `current` — current term ID, excluded along with its ancestors and descendants
- `nonce` — `htax-autocomplete` nonce

Each result item: `{ id, value, label }`, plus `icon_url` when the term has an icon.

## Security Measures

1. **ABSPATH Checks:** All files check for direct access
2. **Nonce Verification:** AJAX and form submissions
3. **Capability Checks:** HyperTaxonomy custom capabilities (e.g., `manage_term_relationships`, `manage_term_redirects`) for term edits
4. **SQL Injection Prevention:** `$wpdb->prepare()` for SQL with variable values
5. **XSS Prevention:** esc_html(), esc_attr(), wp_kses()
6. **CSRF Protection:** WordPress nonces
7. **Data Sanitization:** sanitize_text_field(), wp_unslash()
