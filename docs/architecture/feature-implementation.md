---
title: Feature Implementation
parent: Architecture
nav_order: 4
---

## Feature Implementation Notes

> For implementation patterns (nonce handling, AJAX detection, etc.), error handling patterns, and coding standards, see the [Contributing](../contributing.html).

### 1. **Feature Flag Enforcement for Archive Expansion**

One feature flag controls the entire archive expansion pipeline:

- **`term_relationships`** — gates all expansion. When not enabled for a taxonomy, `htax_build_expanded_tax_query()` returns `null` immediately and no expansion of any kind occurs. This flag covers direct inclusion/container relationships, group-based relationships (Included Groups & Container Groups), and custom relationship types. Corresponds to "Enable term relationships" in the per-taxonomy Features settings.

The flag is checked by `htax_taxonomy_has_feature()` at runtime, so enabling or disabling it takes effect immediately without a reindex.

### 2. **Container Groups (Term Groups Integration)**

Container Groups allow a term to be automatically included in all archives of terms belonging to specified Term Groups. This is useful for bulk relationships.

**Normalized Architecture - Intent Storage:**
- Relationships stored as INTENT, expanded dynamically at query time via SQL JOINs
- **Included Groups**: `source=Term, target=GroupX, type='includes', target_taxonomy='group'`
- **Container Groups**: `source=GroupX, target=Term, type='includes', source_taxonomy='group'` (REVERSE includes)
- No eager expansion needed - SQL computes final relationships on demand

**Query Expansion:**
When fetching included terms for Term A:
1. Query direct inclusions: `source=A, target=B, type='includes'`
2. Query included group members via JOIN:
   - Find groups A includes: `source=A, target=GroupX, type='includes', target_taxonomy='group'`
   - JOIN to find members: `source=Member, target=GroupX, type='member_of'`
3. Return UNION of both result sets, filtered by ancestors

**Hierarchy Filtering:**
- Ancestors filtered out at query time using `get_ancestors()` upfront
- SQL exclusion list prevents infinite loops
- Descendants allowed (redundant but harmless)
- Enables proper SQL pagination with LIMIT/OFFSET

**Restrictions:**
- A relationship cannot have a group term on both sides (group+group rows are always invalid).
- Can be disabled per-taxonomy by turning off the "Term Relationships" feature in Settings → HyperTaxonomy → Features. This gates all archive expansion including group-based relationships.

**Benefits:**
- No sync functions needed - simpler codebase
- No stale data - always accurate at query time
- Proper SQL pagination support
- Single source of truth
- Efficient indexed lookups

### 3. **Multi-Group Membership**

Term Groups allows the same term to belong to multiple groups simultaneously. HyperTaxonomy fully supports this feature:

**Implementation:**
- When a term is in multiple groups, and any of those groups is included, the term appears
- Container groups work similarly - term appears in archives of all members of all its container groups
- No special handling needed - our group expansion naturally accommodates multiple memberships

**Example:**
- Term "Apple" belongs to groups "Fruits" (ID: 5) and "Healthy Foods" (ID: 8)
- Term "Vegetables" includes group "Healthy Foods"
- Result: "Vegetables" archive includes "Apple" (via group 8)

### 4. **Nested Group Expansion for Container Groups**

When looking up container group inclusions for a term T, T's member groups are expanded **downward** to all descendant subgroups (via `htax_get_group_hierarchy()`), then any term B whose stored container group falls within that expanded set is included in T's archive.

The net effect: a term with a more specific container group (e.g. "PHP") automatically appears in archives of terms that belong to broader ancestor groups (e.g. "Programming"), because expanding "Programming" downward reaches "PHP".

**Example:**
- Group "Programming" has child groups "PHP", "JavaScript", "Python"
- Term T is a direct member of "Programming"
- "PHP-Tutorials" has "PHP" as its stored container group
- Result: T's archive includes "PHP-Tutorials" because expanding T's group "Programming" downward reaches "PHP"
- Also: "General-Tutorials" has "Programming" as its stored container group — also included in T's archive
- A term U that is only a member of "PHP" would include "PHP-Tutorials" but NOT "General-Tutorials" (expanding "PHP" downward does not reach "Programming")

### 5. **Parent Group Hierarchy Expansion**

Term Groups support parent-child hierarchy. When a group is included by a term, HyperTaxonomy automatically expands it to include all member terms from the group and all its descendant groups using WordPress's native `get_terms()` with the `child_of` parameter.

**Behavior:**
- Including a parent group automatically includes members of all descendant groups
- Multi-level: grandchildren, great-grandchildren, etc. are all expanded
- Transparent to users — select parent group, get all terms from child groups automatically

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
- No upfront option load. The autocomplete input sends a request to `htax_autocomplete_options` on each keystroke (debounced 300 ms). `suggest_on_focus="false"` is implicit in this mode — `minLength` is already 1 by default when `suggestOnFocus` is false, so no empty-string AJAX call fires on focus.
- `_activeGroups` is still maintained by controller actions (same `includeGroupOptions()`/`excludeGroupOptions()` calls), so the server receives only the currently-active group IDs as a filter.
- Server-side handler (`ajax_autocomplete_options`): accepts `taxonomy`, `search`, `show_icons`, and `groups` (comma-separated active group IDs). If `groups` is non-empty it resolves members via `htax_get_terms_in_groups()`, filters hidden terms, and passes `include` to `get_terms()`. Returns up to 20 results ordered by name when a search string is present, or by post count when the input is empty.
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

**Depth Control:**
- `max_group_depth` parameter available in `[ht_checklist_field]` and `[ht_term_list]` shortcodes (default: `-1` = unlimited)
- `-1` = unlimited depth; `0` = top-level group members only; `1–99` = explicit depth
- Examples:
  - `[ht_checklist_field include_group_options="5"]` — unlimited depth
  - `[ht_checklist_field include_group_options="5" max_group_depth="2"]` — 2 levels deep
  - `[ht_term_list include_groups="5" max_group_depth="0"]` — top-level only

### 6. **Redirects**

**What Makes Redirects Special:**

1. **Archive URL redirects** - visiting /tag/ml/ redirects to /tag/machine-learning/
   - The redirect term has no archive of its own

2. **No redirect chains** - if A→B and B→C, then A automatically updates to A→C
   - Prevents circular redirects

3. **Optional search expansion** - redirects can be followed to include targets in search results

4. **Inclusion relationships preserved in DB** - inclusion relationships set on a redirect term are kept
   in `wp_htax_term_relationships` and remain visible (but dimmed and non-editable) in the admin UI
   while the redirect is active. Their derived `container` entries are removed from the search index
   so they have no effect on search results or `[ht_post_list]`. When the redirect is removed the
   container entries are automatically restored and the fields become editable again.

5. **Hierarchy entries suspended for hierarchical taxonomies** - when a category (or other
   hierarchical term) B becomes a redirect, the search index removes:
   - All `hierarchy` rows where `term_id = B` (posts in B's subtree no longer match searches for B)
   - All `hierarchy` rows where `source_term_id` is a descendant of B **and** `term_id` is B or an
     ancestor of B (posts in B's subtree must not match searches for ancestors of B either, because
     the only path from those descendants to the ancestor runs through the redirect term B)
   - At index-build time, ancestor-chain traversal also stops (`break`) at any redirect term, so
     the same rule applies transitively: A > B > C where B is a redirect means posts in C will not
     index a `hierarchy` row for B or A.
   When the redirect is removed, all of these hierarchy entries are restored automatically.

**Example: ML → Machine Learning**
```
Setup:
  - Term "ML" redirects to "Machine Learning"
  - "Machine Learning" includes "ML" (typical pattern - so ML posts appear in ML archive)
  - Term "AI" includes both ML and Machine Learning
  - 10 posts tagged "ML", 20 posts tagged "Machine Learning"

Results:
  - Visit /tag/ml/ → Redirects to /tag/machine-learning/ (shows 30 posts: 10 from ML + 20 from ML itself)
  - Visit /tag/ai/ → Shows 30 posts (10 from ML + 20 from Machine Learning)

Note: The "Machine Learning includes ML" relationship is optional but typical - it ensures posts
tagged with the redirecting term appear in the target's archive. You can set up redirects without
this inclusion if you want different behavior.
```

**Example: Category hierarchy with redirect**
```
Hierarchy: Science > Physics > Quantum
Physics becomes a redirect → Physics target.

Search index changes:
  - hierarchy(Physics, Quantum) REMOVED — Physics has no archive
  - hierarchy(Science, Quantum) REMOVED — path to Science goes through Physics (redirect)
  - direct(Quantum) kept — posts in Quantum are still directly indexed
  - hierarchy(Science, Physics) kept — posts directly in Physics are still a direct child of Science

When Physics redirect is removed:
  - hierarchy(Physics, Quantum) RESTORED
  - hierarchy(Science, Quantum) RESTORED
```

---

### 7. **Redirect Chain Prevention**

When a term becomes a redirect, any terms redirecting TO it need to update their redirect target to prevent chains (e.g., Term C → Term A → Term B should become Term C → Term B).

**Example:**
- Term A redirects to Term B
- Term C redirects to Term A
- User changes A's redirect: A → D (instead of A → B)
- **Result:**
  - C's redirect updates: C → D (prevents chain C → A → D)

**Implementation:** See redirect chain prevention logic in `includes/core/term-relationships.php`

---

### 8. **Hierarchical Taxonomy Display Depth**

For hierarchical taxonomies like categories, the `max_depth` parameter controls how many levels of the tree to display.

**Depth Control:**
- `max_depth` parameter available in shortcodes that implement custom hierarchical rendering
- Global setting in Settings → Display (default: -1 = unlimited)
- Convention: `-1` for unlimited, `0` for top-level only, `1-99` for limited depth
- **Only supported by:** `[ht_checklist_field]` and `[ht_term_list]`

**Examples:**
- `[ht_checklist_field taxonomy="category"]` - uses global setting (unlimited by default)
- `[ht_checklist_field taxonomy="category" max_depth="0"]` - show only top-level categories (no children)
- `[ht_checklist_field taxonomy="category" max_depth="2"]` - show only 2 levels
- `[ht_term_list taxonomy="category" max_depth="3"]` - limit to 3 levels deep
- `[ht_term_list taxonomy="category" max_depth="-1"]` - force unlimited (override setting)

**Implementation:**
- Hierarchical rendering stops at specified depth
- Used in `render_hierarchical_checklist()` for search widgets
- Used in `render_hierarchical_list()` for term lists
- Depth tracking during recursive rendering

## Term Icon System

Icons are stored as media library attachments. The filename is derived from the term slug at upload time using a collision-avoidance scheme: `slug.ext` → `slug-taxonomy.ext` → `slug-taxonomy-N.ext`. When a term's slug changes, the icon file is automatically renamed to match. The configured dimensions are the large (full) size; medium and small are generated at configurable percentages (defaults: 50% and 25%) set per taxonomy in Settings.

**Upload flow (`htax-term-icons.js` + `term-icons.php`):**
- The `wp.media` library picker overrides `library.add` before Backbone processes each attachment model, so raster images narrower or shorter than the configured dimensions are never added to the collection — they are invisible in the grid.
- After the user selects an attachment, `htax-term-icons.js` checks pixel dimensions against the configured target:
  - **Exact** — accepted immediately; attachment ID stored in the hidden form field.
  - **Smaller** (caught on selection for zero-dimension or unknown entries the filter missed) — media modal closed, inline error shown; previous icon unchanged.
  - **Larger** — media modal closed; a crop dialog opens using WordPress's bundled `imgareaselect` library. The selection rectangle is fixed at the exact target dimensions (scaled to the display size); `handles: false` prevents resizing — the user can only reposition it. Confirming POSTs crop coordinates to `wp_ajax_htax_crop_term_icon`, which calls `wp_crop_image()` and registers the cropped file as a new child attachment (`wp_insert_attachment` + `wp_generate_attachment_metadata`). The cropped attachment ID replaces the original. Cancelling leaves the previous icon unchanged.
  - **SVG** — accepted at any size; no minimum dimension check. If WordPress can read the SVG's natural dimensions (from `width`/`height` attributes or `viewBox`) and the aspect ratio differs from the configured slot by more than 2%, a `window.confirm` warning is shown — the user can proceed or cancel. SVGs with unknown dimensions are accepted silently.

An icon-shaped color placeholder (color assigned deterministically from term slug; shape and dimensions follow the taxonomy's icon settings) is shown for terms without icons when `show_icons="always"` is set on a shortcode. Placeholder color is computed by `HyperTaxonomy_Term_Icons::get_color_for_slug( $slug )` — a public static method that hashes the slug with `crc32` and maps it to one of 20 palette colors. Dimensions (width, height) and visual shape (border-radius) are enforced via the dynamic CSS block emitted by `htax_icon_border_radius_styles()` with `!important` to override any conflicting stylesheet rules. The `HyperTaxonomy_Term_Icons` class is loaded on all page requests (not just admin) so that `htax_get_term_icon_html()` and the shortcode `{icon}` placeholder work on the frontend.

During taxonomy conversion (a term moving from one taxonomy to another), icons are preserved when the source and destination taxonomies share the same configured icon dimensions; otherwise the icon is detached and must be re-uploaded.

When icons are enabled for a taxonomy, icon URLs and dimensions are exposed in the WordPress REST API as the `htax_icon` field on the term object. The field is absent when no icon is set. See [REST API Functions](#rest-api-functions-includescorerest-apiphp) for the full field structure.

---

## Hidden Terms Architecture

### Two-Tier Approach

**Tier 1 — Index building:** When the search index is built for a post, each relationship row in `wp_htax_search_terms` records the `is_hidden` flag. The flag is `1` if either the directly-assigned source term (`source_term_id`) or the relationship target term (`term_id`) is hidden. Covers all relationship types: direct, container, hierarchy, redirect_target.

Because hidden is propagated to descendants at write time (see [Write-Time Cascade](#write-time-cascade) below), a term's `_htax_is_hidden` meta is always authoritative — no runtime ancestor traversal is needed anywhere in the codebase.

**Tier 2 — Runtime filtering:** At query time, hidden term handling differs by context:

| Context | Policy | Rationale |
|---------|--------|-----------|
| PowerSearch results, search widget scoring | Permission-aware — `htax_get_hidden_term_filter_clause()` appends `AND is_hidden = 0` for unprivileged users, empty string for privileged users | Search results are user-specific; privileged users may legitimately search for content tagged with hidden terms |
| Post list shortcode results (`[ht_post_list]`, `[ht_related_posts]`) | Permission-aware (same function). Transient key appends `H` for privileged users to prevent cross-user cache contamination | Post lists respect the same visibility rules as search |
| Contributing terms archive title (`htax_get_contributing_terms_for_current_page`) | Permission-aware. Transient key appends `H` for privileged users | Same reasoning; contributing term labels reflect the post set |
| Post meta term display (`get_the_terms` filter — `htax_filter_get_the_terms`) | Permission-aware — hidden terms are removed for unprivileged users; privileged users see them with an `is_htax_hidden` property set on the term object. Both `htax_show_hidden_terms` and `htax_remove_hidden_terms_from_list` filters allow theme/plugin override | Post footers and term meta widgets are user-facing; same permission logic applies |
| Native WP tag cloud (`wp_tag_cloud` filter — `htax_filter_tag_cloud`) | Always excluded — hidden term links are stripped from the rendered HTML | Tag clouds are public navigation surfaces; no role exception |
| Term list shortcodes (`[ht_term_list]`, `[ht_term_cloud]`, `[ht_term_index]`, `[ht_term_groups]`) | Always excluded — `should_exclude_term()` returns true for any hidden term regardless of role | Public browseable navigation surfaces; privileged users access hidden term details via the admin interface |
| Search widget / filter panel option lists (pills, checkboxes, dropdowns) | Always excluded | UI selection surfaces follow the same always-exclude policy as term lists |
| Filter SQL subqueries (`htax_build_filter_sql`) | Always `AND is_hidden = 0` | Filter terms are drawn from the always-excluded option lists above; the SQL must be consistent |
| REST API term list (`rest_{$taxonomy}_query` filter) | Permission-aware — hidden term IDs added to `exclude` for unprivileged users | REST consumers may be headless clients or block editor components; same role threshold applies |
| REST API single term (`rest_prepare_{$taxonomy}` filter) | Permission-aware — returns `WP_Error` (404) for unprivileged users; adds `htax_is_hidden: true` field for privileged users | Prevents direct URL enumeration of hidden terms |
| Archive queries (`pre_get_posts` expansion in `htax_expand_term_archive`) | Permission-aware — hidden terms excluded from the expanded `tax_query` for unprivileged users | Archive expansion should not surface content that the user cannot see via direct navigation |
| Group archives (`pre_get_posts` via `htax_handle_group_archives`) | Permission-aware — `htax_build_group_tax_query()` excludes hidden terms for unprivileged users | Group archives must filter consistently with all other archive contexts |
| Group archive page itself (the group's own `is_tax('group')` archive) | When the `visibility` feature is enabled for the `group` taxonomy: `htax_handle_redirects()` fires on `template_redirect` and returns a 404 for unprivileged users if the requested group is hidden. Same code path as regular hidden term archives. | Hidden group archives behave identically to hidden term archives from the visitor's perspective |
| All frontend post queries (Query Loop block, Latest Posts block, REST `/wp/v2/posts`, custom `WP_Query`) | Permission-aware — `htax_hide_terms_in_tax_query` (`parse_tax_query`) removes hidden terms from the query's positive tax clauses for unprivileged users, as if the terms did not exist; posts carrying hidden terms remain discoverable through their other terms | A hidden term is invisible, not a quarantine: it must not be usable to find posts, but it must not hide content that is reachable otherwise |

### `source_term_id` Column

Every row in `wp_htax_search_terms` records the `source_term_id` — the directly-assigned term that led to this relationship entry. This enables:
- **Efficient cleanup:** `DELETE WHERE source_term_id = %d` removes all direct and indirect entries when a term is deleted
- **Incremental updates:** Delta-based removal by `source_term_id` for changed post-term assignments
- **Visibility propagation:** When a source term's visibility changes, `UPDATE WHERE source_term_id = $term_id` updates all affected rows in one query

### Write-Time Cascade

Hidden status is propagated to descendants at write time, not derived at runtime. When a term is marked hidden (via the term edit screen or bulk action), `htax_set_descendant_visibility($term_id, $taxonomy, true)` immediately sets `_htax_is_hidden = 1` on every WP descendant that is not already hidden, firing `htax_term_visibility_changed` for each. The same cascade fires when a term is reparented under a hidden ancestor (`htax_cascade_hidden_on_reparent`, hooked to `edited_term` at priority 21).

**Consequence:** Descendants remain hidden even if the ancestor is later made visible. The admin UI (term edit, quick edit, and Make Visible bulk action) detects this and shows a confirmation modal offering to also cascade the make-visible operation to descendants (`htax_set_descendant_visibility($term_id, $taxonomy, false)`, triggered by the `htax_cascade_visible` POST parameter). This keeps hidden status fully explicit — `_htax_is_hidden` on the term itself is always the authoritative answer, and `htax_get_all_hidden_term_ids()` is a complete set.

**New term creation under a hidden parent** is handled by `htax_cascade_hidden_on_create` (`created_term`, priority 21): if the new term's parent is hidden, the term is immediately marked hidden. No descendant cascade is needed since new terms have no children.

**Un-hiding a term whose ancestor is hidden is blocked.** The term edit screen detects a hidden ancestor at page load and renders the visibility checkbox as `disabled` with an explanatory message. The server-side save handler (`htax_save_hidden_term`) re-validates independently — if a POST request attempts to un-hide a term while any ancestor is hidden, the change is rejected with an admin notice and the term remains hidden. The admin must first make the parent visible, which unlocks the child.

**Activation repair scans.** Write-time hooks only fire when HyperTaxonomy is active. Changes made while HyperTaxonomy is deactivated — via WP admin, WP-CLI, CSV importer, or another plugin — bypass these hooks and can leave state inconsistent. Several idempotent scans run on every activation:

- `htax_repair_visibility_cascade()` — re-propagates hidden status to descendants of hidden terms that drifted out of sync (e.g. a term reparented under a hidden ancestor while HyperTaxonomy was inactive).
- `htax_repair_orphaned_term_relationships()` — five-pass repair of `wp_htax_term_relationships`: removes rows whose source or target no longer exists; clears stale `_htax_auto_redirect` meta; enforces taxonomy constraints and self-relation rules for all core and custom types; removes duplicate redirect rows (multiple targets for the same source). Covers terms deleted via WP-CLI, bulk DB imports, or any path that bypasses `delete_term`.
- `htax_repair_orphaned_sticky_meta()` — removes `_htax_sticky_{term_id}` post meta entries whose term no longer exists. Covers terms deleted while HyperTaxonomy was inactive.

**Term taxonomy conversion — two paths.**

*Native path (`htax_convert_term` / `htax_convert_terms_batch`).* Creates a new clean term in the target taxonomy and moves post assignments via a single `UPDATE wp_term_relationships` query. The handling of the old term then diverges:

- **With redirect stub** (`$create_redirect = true`, the default): the old term stays in the old taxonomy and becomes the redirect stub pointing to the new term. It keeps its original slug — so `/old-tax/old-slug` correctly finds the stub and HyperTaxonomy's `template_redirect` handler fires the 301 redirect. All old metadata (icon, group memberships, HyperTaxonomy relationships) remains on the stub; it is in the correct taxonomy for all of it. The new term starts with no inherited metadata. Because the old term's `term_id` is unchanged, child terms' `parent` pointers remain valid with no re-parenting needed. Slug uniqueness follows WordPress's own rule: per-taxonomy. If no term in the target taxonomy already has the slug, the new term receives the original slug — the stub in the old taxonomy retains the same slug, which is valid since they live in different taxonomies. The redirect is stored as a `redirect` row in `wp_htax_term_relationships` (source = stub, target = new term) plus `_htax_auto_redirect = 1` term meta on the stub. `htax_find_auto_redirect_to_term()` detects existing stubs by querying `wp_htax_term_relationships` for this combination, enabling reverse conversion.
- **Without redirect stub** (`$create_redirect = false`): fires the same WordPress hook sequence as wpcat2tag — `wp_insert_term()` (fires `created_term`) → `UPDATE wp_term_relationships` → `wp_delete_term()` (fires `delete_term`) → `wp_update_term()` to claim the original slug. This ordering means plugins that detect term conversions by watching standard WordPress hooks (WPML, Polylang, etc.) recognize HyperTaxonomy's native no-redirect conversion automatically, without needing HyperTaxonomy-specific integration. A flag (`$GLOBALS['htax_native_conversion_in_progress']`) suppresses HyperTaxonomy's own external-detection hooks during this sequence to prevent double-firing.

Reverse conversion is detected at the start of `htax_convert_term()` via `htax_find_auto_redirect_to_term()`, which queries `wp_htax_term_relationships` for a `redirect` row pointing from the target taxonomy to the source term combined with `_htax_auto_redirect = 1` term meta. If such a stub is found, `htax_restore_converted_term()` moves posts back to the stub, removes the redirect row and meta, and deletes the replacement term — restoring the original term to its exact pre-conversion state.

**Redirect stub creation is per-operation, not global.** There is no site-wide `auto_redirect_on_conversion` setting. The Convert Terms admin page and the `htax_convert_term()` API both take an explicit `$create_redirect` parameter; the checkbox in the admin UI defaults to checked. External converters (wpcat2tag and similar) do not create stubs — the explicit per-operation choice only applies to HyperTaxonomy's own native converter.

Pre-conversion guards: name collision in target taxonomy (rejects — consistent with `wp_insert_term` which also errors on duplicate names; rename source or target term first; Merge Terms is same-taxonomy only and cannot be used directly cross-taxonomy); slug collision is **not** guarded — WordPress auto-suffixes (`test` → `test-2`) when a slug is taken, matching native Add Term UI behavior, so conversion proceeds and the new term receives the suffixed slug; converting the WordPress default category is blocked; a non-zero `wp_terms.term_group` value that already exists in the target taxonomy is blocked (signals a translation plugin's synonym grouping); converting a term that is already a redirect stub is blocked. `htax_sort_term_ids_parent_first()` topologically sorts batch term lists (DFS, parents before children) so parents are converted before children.

*External plugin path (create-new + delete-old).* Plugins such as the WordPress Categories to Tags Converter create a new term in the target taxonomy via `wp_insert_term()`, move post assignments by direct SQL, then delete the old term via `wp_delete_term()`. HyperTaxonomy detects this pattern: `created_term` (priority 1) records newly created terms; `pre_delete_term` (priority 5) captures group memberships, hierarchy, icon ID, hidden status, and sticky post assignments before term meta is wiped; `delete_term` (priority 5) transfers concept-level attributes (hidden status, icon, sticky posts) to the new term. Group memberships are not transferred — without a stub to hold them in the old taxonomy there is no safe recipient; the admin reassigns manually. No redirect stub is created for external conversions. HyperTaxonomy relationship rows for the deleted term are taxonomy-pair-specific and invalid in the new taxonomy; `htax_cleanup_deleted_term` removes them at priority 10.

**Sticky posts.** `htax_transfer_sticky_posts_on_convert()` moves `_htax_sticky_{old_term_id}` postmeta entries to `_htax_sticky_{new_term_id}` in the native path. In the external path, sticky assignments are captured at `pre_delete_term` time (before `delete_term_taxonomy` fires and `htax_cleanup_deleted_term` deletes the postmeta rows) and replayed directly in `htax_complete_external_conversion_after_delete` at `delete_term` priority 5. Without transfer, the new term's archive would show no stickied posts even though the term's content is unchanged.

**WordPress navigation menus.** `htax_update_nav_menu_items_for_converted_term()` runs from both paths. It finds `nav_menu_item` posts with `_menu_item_type = taxonomy`, `_menu_item_object = $old_taxonomy`, `_menu_item_object_id = $old_term_id` and updates the taxonomy slug (and object_id when term_id changes in the external path), preventing broken archive URLs.

**Third-party plugin data.** In the native redirect path the old term remains in the old taxonomy as a stub, so plugins that store data keyed by `(taxonomy_slug, term_id)` (Yoast SEO `wp_yoast_indexable`, WPML `wp_icl_translations`) continue to find it correctly under the old taxonomy. The new term starts fresh with a new `term_id`. In the no-redirect path the old term is deleted; those plugins' records are orphaned and must be updated via the `htax_term_converted` hook. `wp_termmeta` data (ACF field values, etc.) follows the term_id: it stays on the stub in the redirect path, and is removed with the old term in the no-redirect path — the new term starts clean in both cases.

**`htax_term_converted` action.** Fires at the end of both paths with signature `($new_term_id, $old_taxonomy, $new_taxonomy, $old_term_id)`. `$old_term_id` is the stub (redirect path) or the deleted term (no-redirect / external paths). Plugins use this to update taxonomy-keyed records.

**Redirect stubs** created by the external path via `htax_create_redirect_term()` inherit the hidden status of the target term and validate that the intended parent is still in the old taxonomy before setting it (batch-safe).

### Visibility Change Propagation

When `htax_term_visibility_changed` fires, a single SQL UPDATE recalculates `is_hidden` for all affected rows — no reindexing needed:
```sql
UPDATE wp_htax_search_terms
SET is_hidden = (source_is_hidden OR term_is_hidden)
WHERE source_term_id = $term_id OR term_id = $term_id
```

**Group taxonomy exception:** When `htax_term_visibility_changed` fires for a term in the `group` taxonomy, `HyperTaxonomy_Search_Index::on_term_visibility_changed()` returns early without running this UPDATE. Group expansion and archive contribution are visibility-independent by design — all users see the same expanded post sets regardless of whether they can see a hidden group's archive. The `is_hidden` flag in `wp_htax_search_terms` reflects the *content* term's visibility (e.g. whether a tag is hidden), not the group's visibility.

---

## Conflict Detection

HyperTaxonomy automatically prevents or resolves several classes of relationship conflicts:

| Conflict | Behavior |
|----------|----------|
| Self-inclusion | Silently removed from save data before writing |
| Circular redirects (A→B→A) | Auto-resolved by flipping direction; no error shown |
| Redirect chains (A→B→C) | Resolved to A→C at save time; chains never stored |
| Hierarchy/inclusion conflicts | Removed automatically on `edited_term` hook, admin notice shown |
| Self-redirects | Rejected with admin notice; redirect not saved |

### Same-Taxonomy Requirement

Core inclusion relationships (`includes`, containers, `excludes`) must connect terms within the same taxonomy. Cross-taxonomy rows would never surface — the query layer filters by taxonomy at runtime. This constraint is also enforced for inclusion-based custom types: both non-group sides must be in the same taxonomy.

**Group exemption:** When either the source or target term belongs to the `group` taxonomy, the same-taxonomy constraint is waived. Groups can form relationships with terms in any taxonomy. The only hard constraint is that a relationship cannot have group terms on **both** sides.

**Other exceptions:** Redirects and `member_of` relationships can also cross taxonomy boundaries.

### Self-Inclusion Prevention

A term cannot include itself. Attempting to save a self-inclusion is silently removed from the submitted list.

### Hierarchy Conflicts

For hierarchical taxonomies, HyperTaxonomy inclusions and containers are restricted to strictly cross-branch relationships — neither ancestors nor descendants appear in either set:

- **`htax_get_included_terms(A)`** excludes A's WP ancestors (semantic loops) and A's WP descendants (already in archive via `include_children`; redundant).
- **`htax_get_container_terms(B)`** excludes B's WP descendants (cannot include their own ancestors) and B's WP ancestors (already include B's posts via `include_children`; redundant).

This keeps HyperTaxonomy inclusions and containers disjoint from the native WP hierarchy — both functions return only cross-branch relationships. Archive expansion still combines both (HyperTaxonomy inclusions plus WP descendants) at query time; the restriction applies only to the HyperTaxonomy semantic layer.

**Conflict rows are prevented at multiple levels:**

1. **Save time** — `htax_save_term_relationships()` computes ancestors + descendants of the current term before writing and strips them from the incoming inclusions, containers, and exclusions. Direct DB writes and imports that bypass the save handler are caught by the activation sweep below.

2. **Hierarchy change** — `htax_cleanup_hierarchy_conflicts()` fires on `edited_term`. It uses the pre-edit parent (cached by `htax_cache_term_taxonomy_before_edit` at `edit_term` priority 5) to detect whether the hierarchy actually changed. If a parent was added or changed, `htax_remove_hierarchy_conflict_rows()` removes any *direct* inclusion, container, and exclusion rows that now conflict, an admin notice is shown, and `htax_includes_changed` is fired for each affected ancestor so caches and the search index stay consistent. Removing a parent cannot create new conflicts, so only the cache-invalidation action fires in that case.

   *Indirect* inclusions (via groups) are not stored as direct rows and therefore cannot be removed by `htax_cleanup_hierarchy_conflicts`. Instead, `htax_fire_archive_changed_on_hierarchy()` (hooked to `edited_term` priority 11) deletes `htax_inc_` and `htax_cont_` transients for every term in the old and new parent chains, forcing those caches to recompute with the updated `never_include` set on next access. Concurrently, `on_taxonomy_hierarchy_changed()` (search index, hooked to `edited_term` priority 10) updates hierarchy index entries for the reparented term and all its descendants, clears `htax_inc_`/`htax_cont_` for those descendants, and deletes any stale `relationship_type='container'` index entries where the reparented term or a descendant appears under one of the new ancestors — preventing double-scoring against both hierarchy and container paths.

3. **Term merge** — After transferring relationships from source to target, `htax_remove_hierarchy_conflict_rows()` runs on the target term to remove any rows that became conflicts because the target is in a different hierarchy position than the sources.

4. **Activation sweep** — `htax_repair_hierarchy_conflicts()` iterates every term in every hierarchical enabled taxonomy and calls `htax_remove_hierarchy_conflict_rows()` for any term with a parent. This catches conflicts that accumulated while the plugin was inactive (e.g. another plugin reparented terms, or a taxonomy hierarchy changed via WP core operations).

5. **Query time (safety net)** — `htax_get_included_terms()` and `htax_get_container_terms()` still apply the exclusions at read time, so any row that slips past the write-time gates (direct `$wpdb` writes, third-party plugins) is silently ignored rather than producing incorrect results.

The shared helper is `htax_remove_hierarchy_conflict_rows( int $term_id, string $taxonomy ): bool` in term-relationships.php, used by all four active enforcement points.

**Cache invalidation for group-based hierarchy interactions:**

- When a term joins or leaves a group, `htax_update_group_membership_caches()` step 4 clears the joining/leaving term M's own caches (including `htax_inc_M`, `htax_cont_M`, `htax_crel_*/htax_crel_rev_*_M`). Step 5 calls `htax_invalidate_group_relationship_caches($all_affected_groups)`, which covers: (A) `htax_inc_T` for terms T that include the affected group or its ancestors (fwd T→G); (B) `htax_cont_T` for terms T that are targeted by container rows on the affected group or its ancestors/descendants (rev G→T); (C) `htax_crel_{slug}_T` and (D) `htax_crel_rev_{slug}_T` for the same two sets. The rev/container direction uses the full union of ancestors + descendants because M's path 3 expansion reaches descendant groups.
- When group members are edited via the group admin screen (`htax_save_terms_in_group`), `htax_delete_transients_by_prefix('htax_grp_{group_id}_')` clears all `htax_grp_{group_id}_{taxonomy}` caches across all taxonomies, then `htax_invalidate_group_relationship_caches` covers the downstream `htax_inc_*` / `htax_crel_*/htax_crel_rev_*` caches.
- When G→T container-group rows are added/removed (either via the term edit screen Container Groups field or `htax_save_terms_with_container_group`), `htax_clear_member_caches_for_groups([G], 'inc')` clears `htax_inc_M` for all members of G and G's ancestor groups. This is necessary because path 3 of `htax_get_included_terms(M)` expands M's member groups downward, so members of ancestor groups of G also see G→T container rows.
- When T→G included-group rows are added/removed (either via the term edit screen Included Groups field or `htax_save_terms_with_included_group`), `htax_clear_member_caches_for_groups([G], 'cont')` clears `htax_cont_M` for all members of G and G's descendant groups, keeping the container view consistent with the inclusion view.
- When custom-type T→G or G→T group rows change in `htax_save_term_relationships`, `htax_clear_member_caches_for_groups` is called with `'crel_rev'` or `'crel'` respectively to clear the affected member caches. Additionally, when only custom-type group rows change (no term-to-term rows change), `$custom_types_changed` is set so that `htax_delete_term_transients(T)` runs and T's own `htax_crel_*/htax_crel_rev_*` caches are cleared.
- When a group's parent changes, `htax_invalidate_caches_on_group_reparent()` (hooked to `edited_term` priority 12) calls `htax_invalidate_group_relationship_caches([old_parent, new_parent])` and then `htax_clear_member_caches_for_groups([old_parent, new_parent], 'inc')`. The first call clears `htax_inc_*`/`htax_cont_*`/`htax_crel_*/htax_crel_rev_*` for terms T that have rows pointing to the affected parent chains. The second clears `htax_inc_M` for members of the old and new parent groups and their ancestor groups, covering the members whose path-3 downward expansion previously (or now) reaches through the reparented group to its container rows.
- When a term merge transfers source terms' `member_of` group memberships to the target (`htax_merge_terms()` in term-merge.php), the set of affected group IDs is expanded to include group ancestors (group expansion is hierarchical, so a child group's member-list cache feeds its ancestors' expansions). For each affected group it deletes the `htax_grp_{group_id}_` transients, refreshes the group term count, and cleans the term cache, then fires `htax_term_groups_changed` once for the target term so the search index and other membership-change listeners stay consistent.

Functions:
- `htax_cleanup_hierarchy_conflicts( $term_id, $tt_id, $taxonomy )` — runs on `edited_term` priority 20; detects parent change via pre-edit cache, removes conflicting direct rows if any, fires `htax_includes_changed` for affected ancestors
- `htax_fire_archive_changed_on_hierarchy( $term_id, $tt_id, $taxonomy )` — runs on `edited_term` priority 11; detects parent change, deletes `htax_inc_` and `htax_cont_` transients for all terms in old and new parent chains, then fires `htax_term_archive_changed` for each
- `on_taxonomy_hierarchy_changed( $term_id, $tt_id, $taxonomy )` — search index handler on `edited_term` priority 10; rebuilds hierarchy index entries for the reparented term and descendants, clears `htax_inc_`/`htax_cont_` for descendants, deletes stale container index entries under new ancestors
- `htax_invalidate_caches_on_group_reparent( $term_id, $tt_id, $taxonomy )` — runs on `edited_term` priority 12; detects group parent changes and calls `htax_invalidate_group_relationship_caches`

### Redirect Constraints

- **No circular redirects:** A→B is blocked if B already redirects (directly or transitively) back to A
- **No redirect chains:** When A→B exists and B's redirect target changes to C, A is automatically updated to A→C
- **No self-redirect:** A term cannot redirect to itself
- **Cross-taxonomy supported:** Redirects can point to a term in a different taxonomy (e.g. a tag redirecting to a category). `htax_get_redirect_target()` validates the target taxonomy at resolution time.

### Group Conflicts

Multi-group membership is fully supported — no conflicts arise. When a term belongs to multiple groups and one group is included by another term, the inclusion set is expanded correctly through all applicable groups.

---

