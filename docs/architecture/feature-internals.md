---
title: Feature Internals
parent: Architecture
nav_order: 6
---

# Feature Internals
{: .no_toc }

How individual features are built: the feature flag behind archive expansion, group relationships, the native block group filter, redirects, display depth, term icons, hidden terms, conversion and merge, and conflict detection. For implementation patterns (nonce handling, AJAX detection, etc.), error handling patterns, and coding standards, see [Contributing](contributing.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Feature Flag Enforcement for Archive Expansion

Site owners turn the flag on per taxonomy as **Term Relationships** in [Settings → HyperTaxonomy → Features](../user-guide/plugin-settings.html#features-tab). The public functions for archive expansion are in the [Query Expansion API](../developer-reference/query-expansion.html).

One feature flag controls the entire archive expansion pipeline:

- **`term_relationships`** — gates all expansion. When not enabled for a taxonomy, `htax_build_expanded_tax_query()` returns `null` immediately and no expansion of any kind occurs. This flag covers direct inclusion/container relationships, group-based relationships (Included Groups & Container Groups), and custom relationship types. Corresponds to "Enable relationships for {taxonomy}" in the per-taxonomy Features settings.

The flag is checked by `htax_taxonomy_has_feature()` at runtime, so enabling or disabling it takes effect immediately without a reindex.

**`group_assignment`** decides whether a taxonomy's group memberships count. While it is off the `member_of` rows stay stored but are ignored everywhere they shape output: `htax_load_terms_in_groups()` returns no members (group inclusions, group archives, the Query Loop filter, shortcodes, the search widget), `htax_get_term_group_ids()` returns no groups (the container-group path of `htax_get_included_terms()`), `htax_get_container_terms()` skips its group-inclusion and container-group paths, and group counts (`htax_update_group_term_count()`) leave the taxonomy's members out. Unlike `term_relationships`, the search index stores the result, so turning it on or off needs a reindex: `htax_clear_caches_on_feature_change()` clears the caches, and `HyperTaxonomy_Search_Index_Manager::on_group_assignment_changed()` recounts every group and queues the taxonomy's posts for reindexing.

## Group Relationships

For how site owners set these up, see [Term Relationships → Group Inclusions](../user-guide/term-relationships.html#group-inclusions) and [Container Groups](../user-guide/term-relationships.html#container-groups); for reading group data in code, see [Relationship Data API → Term Groups](../developer-reference/relationship-data.html#term-groups).

Terms relate to groups in two directions. A **group inclusion** puts a group's members' posts into the including term's archive; a **container group** puts a term's posts into the archive of every member of the group and of its parent groups. Relationships are stored as intent and expanded at query time. See [Custom Database Tables](data-storage.html#custom-database-tables) for the row formats.

### Group inclusions

When fetching included terms for Term A:
1. Query direct inclusions: `source=A, target=B, type='includes'`
2. Query included group members via JOIN:
   - Find groups A includes: `source=A, target=GroupX, type='includes', target_taxonomy='group'`
   - JOIN to find members: `source=Member, target=GroupX, type='member_of'`
3. Return the union of both result sets, without self, ancestors, or descendants

**Hierarchy Filtering:** self, ancestors, and descendants never appear in the result. See [Hierarchy Conflicts](#hierarchy-conflicts).

Term Groups support parent-child hierarchy. When a group is included by a term, HyperTaxonomy automatically expands it to include all member terms from the group and all its descendant groups using `htax_get_group_hierarchy()`.

**Behavior:**
- Including a parent group automatically includes members of all descendant groups
- Multi-level: grandchildren, great-grandchildren, etc. are all expanded
- Transparent to users — select parent group, get all terms from child groups automatically

### Container groups

When looking up container group inclusions for a term T, T's member groups are expanded **downward** to all descendant subgroups (via `htax_get_group_hierarchy()`), then any term B whose stored container group falls within that expanded set is included in T's archive.

The net effect: a term with a more specific container group (e.g. "PHP") automatically appears in archives of terms that belong to broader ancestor groups (e.g. "Programming"), because expanding "Programming" downward reaches "PHP".

**Example:**
- Group "Programming" has child groups "PHP", "JavaScript", "Python"
- Term T is a direct member of "Programming"
- "PHP-Tutorials" has "PHP" as its stored container group
- Result: T's archive includes "PHP-Tutorials" because expanding T's group "Programming" downward reaches "PHP"
- Also: "General-Tutorials" has "Programming" as its stored container group — also included in T's archive
- A term U that is only a member of "PHP" would include "PHP-Tutorials" but NOT "General-Tutorials" (expanding "PHP" downward does not reach "Programming")

### Terms in several groups

A term can belong to several groups at once.

**Implementation:**
- When a term is in multiple groups, and any of those groups is included, the term appears
- Container groups work similarly - term appears in archives of all members of all its container groups
- No special handling needed: group expansion naturally accommodates multiple memberships

**Example:**
- Term "Apple" belongs to groups "Fruits" (ID: 5) and "Healthy Foods" (ID: 8)
- Term "Vegetables" includes group "Healthy Foods"
- Result: "Vegetables" archive includes "Apple" (via group 8)

### Restrictions

- A relationship cannot have a group term on both sides (group+group rows are always invalid).
- Can be disabled per-taxonomy by turning off its relationships feature (`term_relationships`) in Settings → HyperTaxonomy → Features. This gates all archive expansion including group-based relationships.

## Native Block Group Filter

For how editors use it, see [Archive & Results Controls → Query Loop and Latest Posts](../user-guide/archive-controls.html#gutenberg-block-extensions).

The HT Term Group Filter extends the native WordPress `core/query` (Query Loop) and `core/latest-posts` (Latest Posts) blocks. It requires no template changes — the filter is added through each block's inspector.

### Editor-Side (JavaScript)

`blocks/ht-query-group-filter/src/index.js` registers two `addFilter` calls that target both `core/query` and `core/latest-posts`:

1. **`blocks.registerBlockType`** — runs once per block registration. Appends `htax_filter_groups: { type: 'string', default: '' }` (comma-separated group IDs; the PHP side also accepts an array) to each block's attribute schema so the value is persisted in post_content.

2. **`editor.BlockEdit`** — wraps every block's `Edit` component using `createHigherOrderComponent`. For blocks other than `core/query` and `core/latest-posts` the wrapper is a no-op passthrough. For the two target blocks it renders both the original `BlockEdit` and an extra `<InspectorControls>` panel containing a `GroupTokenField`. Group IDs are stored as a comma-separated string in `htax_filter_groups`. For `core/query`, the panel also writes the same value to `query.htax_filter_groups` (and removes it when cleared). The post template forwards extra `query` keys to the REST request behind the editor preview, so `htax_rest_posts_group_filter()` (below) can filter the preview the same way as the page. `core/latest-posts` builds its preview request from a fixed set of its own settings, so its editor preview stays unfiltered.

The block's `block.json` sets `"supports": {"inserter": false}` to prevent it from appearing in the block inserter. The `register_block_type` call in `HyperTaxonomy_Blocks::register()` has no render callback — the registration exists solely to auto-enqueue the `editorScript` via `block.json`.

### Server-Side (PHP)

**Query Loop block** — `htax_query_loop_group_filter( $query_vars, $block, $page )` hooked to `query_loop_block_query_vars` at priority 10.

WordPress calls this filter for the blocks inside the Query Loop (post template, pagination), which see the loop's settings only through the `query` context, not the outer block's attributes. `htax_query_loop_group_context()`, hooked to `render_block_data`, copies `htax_filter_groups` into the `core/query` block's `query` attribute in memory as it renders, so it reaches that context; nothing is saved.

Algorithm:

1. Read `$block->context['query']['htax_filter_groups']`, falling back to the block's own `htax_filter_groups` attribute; bail if empty.
2. Split to integer group IDs; bail if none survive.
3. Detect post type from `$query_vars['post_type']` (default `'post'`).
4. Delegate to `htax_build_group_tax_query( $group_ids, $post_type )` (shared with group archives — see below).
5. If the block already has a `tax_query` (its own taxonomy filter), wrap both in `relation: AND`.

**Editor preview (REST)** — `htax_rest_posts_group_filter( $args, $request )`, registered by `htax_register_rest_posts_group_filter()` on `rest_api_init` as `rest_{$post_type}_query` for every post type in the REST API. When the request carries `htax_filter_groups` and the user can `edit_posts`, it adds the same group `tax_query`. The Query Loop's editor preview sends the parameter; other requests are unaffected.

**Latest Posts block** — `htax_latest_posts_group_filter( $pre_render, $parsed_block )` hooked to `pre_render_block` at priority 10.

`core/latest-posts` has no equivalent to `query_loop_block_query_vars` — it builds a `WP_Query` directly in its render callback. The filter intercepts `pre_render_block`, reads `htax_filter_groups` from the block's attributes, then registers a one-shot `pre_get_posts` callback that injects the resolved `tax_query` before the block's `WP_Query` executes. The callback removes itself immediately after firing, so it does not affect any other query on the same page. `core/latest-posts` always queries the `post` post type.

**Shared tax_query builder — `htax_build_group_tax_query( array $group_ids, string $post_type = '' ): array`** (in `group-functions.php`):

- Expands groups to subgroups, checks the `group_assignment` feature flag per taxonomy, filters hidden terms for unprivileged users.
- When `$post_type` is non-empty, limits taxonomies to those registered for that post type; pass empty string in archive contexts where all enabled taxonomies apply.
- Returns `['relation' => 'OR', ...clauses]`. Callers check `count($result) > 1` before applying.
- Used by `htax_query_loop_group_filter` (Query Loop), `htax_latest_posts_group_filter` (Latest Posts), `htax_rest_posts_group_filter` (Query Loop editor preview), and `htax_handle_group_archives` (term archive handler), ensuring identical filtering logic in all contexts.

**OR logic rationale:** Multiple groups = union. Selecting groups A and B includes posts from A, B, or both. Within a single group, subgroup expansion is inclusive. Across taxonomies, OR is used because a post might carry the relevant term in any of its taxonomies — requiring ALL taxonomies to match would incorrectly exclude posts that use only one of them.

## Redirects

For how site owners create redirects, see [Term Relationships → Redirects](../user-guide/term-relationships.html#redirects); for reading them in code, see [Relationship Data API → Redirect target](../developer-reference/relationship-data.html#redirect-target).

**What Makes Redirects Special:**

1. **Archive URL redirects** - visiting /tag/ml/ redirects to /tag/machine-learning/
   - The redirect term has no archive of its own

2. **No redirect chains** - if A→B and B→C, then A automatically updates to A→C
   - Prevents circular redirects

3. **Search expansion** - a search for the target also finds posts tagged with terms that redirect to it
   (`redirect_target` index rows, matched when the **Redirects** option of **Term Relationship Expansion**
   is on, the default). It works in that direction only: a redirect term's own inclusions are inactive,
   and a target's archive shows the redirecting term's posts only if the target includes that term

4. **Inclusion relationships preserved in DB** - inclusion relationships set on a redirect term are kept
   in `wp_htax_term_relationships`, but the inclusion and exclusion panels are hidden on the term
   edit screen while the redirect is active. Their derived `container` entries are removed from the
   search index so they have no effect on search results or `[ht_post_list]`. When the redirect is
   removed the container entries are automatically restored and the panels reappear.

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
  - Visit /tag/ml/ → Redirects to /tag/machine-learning/ (shows 30 posts: 10 from ML + 20 from Machine Learning itself)
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

### Redirect Chain Prevention

**Example:**
- Term A redirects to Term B
- Term C redirects to Term A
- User changes A's redirect: A → D (instead of A → B)
- **Result:**
  - C's redirect updates: C → D (prevents chain C → A → D)

**Implementation:** the cascade in `htax_set_redirect()`, in `includes/core/term-relationships.php`.

## Hierarchical Taxonomy Display Depth

`[ht_term_list]` (and the Term List block) and the search widget's hierarchical checklists (`[ht_checklist_field]`) accept `max_depth`: `0` shows only the top level, and `1`–`99` limit the depth. Their `-1` default means "use the **Max Term Hierarchy Depth** setting" (Settings → HyperTaxonomy → Display), whose own `-1` means unlimited. User-facing usage is in [Term Lists & Clouds](../user-guide/term-lists.html) and [Search Widget](../user-guide/search-widget.html).

**Implementation:** `render_hierarchical_list()` (term lists) and `render_hierarchical_checklist()` (search widget) track depth while rendering recursively and stop at the limit.

**Group expansion:** `include_group_options` always expands to every descendant group (`htax_get_terms_in_groups()`), with no depth limit, because a member of a child group counts as a member of its parents throughout HyperTaxonomy.

## Term Icon System

For how site owners add icons, see [Per-Term Settings → Term Icons](../user-guide/per-term-settings.html#term-icons); for displaying them in themes, see [Term Icons & Badges](../developer-reference/term-icons.html).

Icons are stored as media library attachments. The filename is derived from the term slug at upload time using a collision-avoidance scheme: `slug.ext` → `slug-taxonomy.ext` → `slug-taxonomy-N.ext`. When a term's slug changes, the icon file is automatically renamed to match. The configured dimensions are the large (full) size; medium and small are generated at configurable percentages (defaults: 50% and 25%) set per taxonomy in Settings.

**Upload flow** (`htax-term-icons.js` + `term-icons.php`), which enforces the [upload rules](../developer-reference/term-icons.html#upload-rules):
- The `wp.media` library picker overrides `library.add` before Backbone processes each attachment model, so raster images narrower or shorter than the configured dimensions are never added to the collection — they are invisible in the grid.
- After the user selects an attachment, `htax-term-icons.js` checks pixel dimensions against the configured target:
  - **Exact** — accepted immediately; attachment ID stored in the hidden form field.
  - **Smaller** (caught on selection for zero-dimension or unknown entries the filter missed) — media modal closed, inline error shown; previous icon unchanged.
  - **Larger** — media modal closed; a crop dialog opens using WordPress's bundled `imgareaselect` library. The selection rectangle is fixed at the exact target dimensions (scaled to the display size); `handles: false` prevents resizing — the user can only reposition it. Confirming POSTs crop coordinates to `wp_ajax_htax_crop_term_icon`, which calls `wp_crop_image()` and registers the cropped file as a new child attachment (`wp_insert_attachment` + `wp_generate_attachment_metadata`). The cropped attachment ID replaces the original. Cancelling leaves the previous icon unchanged.
  - **SVG** — accepted at any size; no minimum dimension check. If WordPress can read the SVG's natural dimensions (from `width`/`height` attributes or `viewBox`) and the aspect ratio differs from the configured slot by more than 2%, a `window.confirm` warning is shown — the user can proceed or cancel. SVGs with unknown dimensions are accepted silently.

An icon-shaped color placeholder ([`get_color_for_slug()`](../developer-reference/term-icons.html#placeholder-color-for-a-term); color assigned deterministically from term slug; shape and dimensions follow the taxonomy's icon settings) is shown for terms without icons when `show_icons="always"` is set on a shortcode. Placeholder color is computed by `HyperTaxonomy_Term_Icons::get_color_for_slug( $slug )` — a public static method that hashes the slug with `crc32` and maps it to one of 20 palette colors. Dimensions (width, height) and visual shape (border-radius) are enforced via the dynamic CSS block emitted by `htax_icon_border_radius_styles()` with `!important` to override any conflicting stylesheet rules. The `HyperTaxonomy_Term_Icons` class is loaded on all page requests (not just admin) so that `htax_get_term_icon_html()` and the shortcode `{icon}` placeholder work on the frontend.

During taxonomy conversion (a term moving from one taxonomy to another), icons are preserved when the source and destination taxonomies share the same configured icon dimensions; otherwise the icon is detached and must be re-uploaded.

When icons are enabled for a taxonomy, icon URLs and dimensions are exposed in the WordPress REST API as the `htax_icon` field on the term object. The field is absent when no icon is set. See [REST API → Term endpoint enrichment](../developer-reference/rest-api.html#term-endpoint-enrichment) for the full field structure.

---

## Hidden Terms Architecture

Site owners hide terms as described in [Per-Term Settings → Term Visibility](../user-guide/per-term-settings.html#term-visibility). [Handling Hidden Terms](../developer-reference/hidden-terms.html) covers what other plugins and themes see.

### Two-Tier Approach

**Tier 1 — Index building:** When the search index is built for a post, each relationship row in `wp_htax_search_terms` records the `is_hidden` flag. The flag is `1` if either the directly-assigned source term (`source_term_id`) or the relationship target term (`term_id`) is hidden. Covers all relationship types: direct, container, hierarchy, redirect_target. Other plugins apply the same flag as described in [Hidden Terms in the Search Index](../developer-reference/hidden-terms.html#hidden-terms-in-the-search-index).

Because hidden is propagated to descendants at write time (see [Write-Time Cascade](#write-time-cascade) below), a term's `_htax_is_hidden` meta is always authoritative, and visibility checks need no runtime ancestor traversal. The admin UI does walk a term's ancestors, to disable the un-hide option when a parent is hidden.

**Tier 2 — Runtime filtering:** At query time, hidden term handling differs by context (the public side is in [Automatic Filtering in Native Blocks](../developer-reference/hidden-terms.html#automatic-filtering-in-native-blocks) and [Filtering Hidden Terms in Custom Queries](../developer-reference/hidden-terms.html#filtering-hidden-terms-in-custom-queries)):

| Context | Policy | Rationale |
|---------|--------|-----------|
| PowerSearch results, search widget scoring | Permission-aware — `htax_get_hidden_term_filter_clause()` appends `AND is_hidden = 0` for unprivileged users, empty string for privileged users | Search results are user-specific; privileged users may legitimately search for content tagged with hidden terms |
| Post list shortcode results (`[ht_post_list]`, `[ht_related_posts]`) | Permission-aware (same function). Transient key appends `H` for privileged users to prevent cross-user cache contamination | Post lists respect the same visibility rules as search |
| Contributing terms archive title (`htax_get_contributing_terms_for_current_page`) | Permission-aware. Transient key appends `H` for privileged users | Same reasoning; contributing term labels reflect the post set |
| Post meta term display (`get_the_terms` filter — `htax_filter_get_the_terms`) | Permission-aware — hidden terms are removed for unprivileged users; privileged users see them with an `is_htax_hidden` property set on the term object. Both `htax_show_hidden_terms` and `htax_remove_hidden_terms_from_list` filters allow theme/plugin override | Post footers and term meta widgets are user-facing; same permission logic applies |
| Other plugins and themes (`get_term` and `terms_clauses` filters — `htax_get_term_ids_hidden_from_caller()`) | Permission-aware — lookups find nothing and term queries leave hidden terms out for unprivileged users on read requests; HyperTaxonomy's own code and some core callers keep the real terms | Code that knows nothing about hidden terms needs no special handling |
| Native WP tag cloud (`wp_tag_cloud` filter — `htax_filter_tag_cloud`) | Always excluded — hidden term links are stripped from the rendered HTML | Tag clouds are public navigation surfaces; no role exception |
| Term list shortcodes (`[ht_term_list]`, `[ht_term_cloud]`, `[ht_term_index]`, `[ht_term_groups]`) | Always excluded — `should_exclude_term()` returns true for any hidden term regardless of role | Public browseable navigation surfaces; privileged users access hidden term details via the admin interface |
| Search widget option lists (pills, checkboxes, autocomplete, group-member maps) | Permission-aware — `htax_can_see_hidden_terms()` per taxonomy; nothing is cached across viewers | Search is user-specific, like the results: editors can search with hidden terms, visitors never see them |
| Archive filter panel option lists | Always excluded | Filter panels sit on public archives and follow the same always-exclude policy as term lists |
| Filter SQL subqueries (`htax_build_filter_sql`) | Always `AND is_hidden = 0` | Filter terms are drawn from the always-excluded filter panel options above; the SQL must be consistent |
| REST API term list (`rest_{$taxonomy}_query` filter) | Permission-aware — hidden term IDs added to `exclude` for unprivileged users | REST consumers may be headless clients or block editor components; same role threshold applies |
| Computed term meta (`get_term_metadata` filter — `htax_filter_computed_term_meta`) | Always excluded, for every viewer (`htax_remove_hidden_term_ids()`); a hidden term returns nothing | Read by GraphQL plugins and page builders, often over POST and behind shared caches; same rule as the REST relationship fields |
| REST API relationship fields (`htax_includes`, `htax_synonyms`, `htax_relationships`, `htax_redirect`, `htax_term_strengths`, `htax/v1/term/{id}/relationships`) | Always excluded, for every viewer (`htax_remove_hidden_term_ids()`) | They describe the public vocabulary; identical responses for all viewers are safe to cache, and don't depend on the request method |
| REST API single term (`rest_prepare_{$taxonomy}` filter) | Permission-aware — returns `WP_Error` (404) for unprivileged users; adds `htax_is_hidden: true` field for privileged users | Prevents direct URL enumeration of hidden terms |
| Archive queries (`pre_get_posts` expansion in `htax_modify_archive_query`) | Permission-aware — hidden terms excluded from the expanded `tax_query` for unprivileged users | Archive expansion should not surface content that the user cannot see via direct navigation |
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

**Consequence:** Descendants remain hidden even if the ancestor is later made visible. The admin UI (term edit, quick edit, and Make Visible bulk action) detects this and shows a confirmation modal offering to also cascade the make-visible operation to descendants (`htax_set_descendant_visibility($term_id, $taxonomy, false)`, triggered by the `htax_cascade_visible` POST parameter). To decide whether to ask, the screen first asks `htax_ajax_count_hidden_descendants()` (`wp_ajax_htax_count_hidden_descendants`) how many hidden descendants each term has, not counting descendants that are themselves selected, since they are made visible anyway. This keeps hidden status fully explicit — `_htax_is_hidden` on the term itself is always the authoritative answer, and `htax_get_all_hidden_term_ids()` is a complete set.

**New term creation under a hidden parent** is handled by `htax_cascade_hidden_on_create` (`created_term`, priority 21): if the new term's parent is hidden, the term is immediately marked hidden. No descendant cascade is needed since new terms have no children.

**Un-hiding a term whose ancestor is hidden is blocked.** The term edit screen detects a hidden ancestor at page load and renders the visibility checkbox as `disabled` with an explanatory message. The server-side save handler (`htax_save_hidden_term`) re-validates independently — if a POST request attempts to un-hide a term while any ancestor is hidden, the change is rejected with an admin notice and the term remains hidden. The admin must first make the parent visible, which unlocks the child.

**Activation repair scans.** Write-time hooks only fire when HyperTaxonomy is active. Changes made while HyperTaxonomy is deactivated — via WP admin, WP-CLI, CSV importer, or another plugin — bypass these hooks and can leave state inconsistent. Several idempotent scans run on every activation (described for site owners in [Reactivation](../user-guide/deactivation-uninstall.html#reactivation)):

- `htax_repair_visibility_cascade()` — re-propagates hidden status to descendants of hidden terms that drifted out of sync (e.g. a term reparented under a hidden ancestor while HyperTaxonomy was inactive).
- `htax_repair_orphaned_term_relationships()` — six-pass repair of `wp_htax_term_relationships`: removes rows whose source or target no longer exists; clears stale `_htax_auto_redirect` meta; enforces taxonomy constraints and self-relation rules for all core and custom types; removes duplicate redirect rows (multiple targets for the same source); removes duplicate rows for symmetric custom types. Covers terms deleted via WP-CLI, bulk DB imports, or any path that bypasses `delete_term`.
- `htax_repair_orphaned_sticky_meta()` — removes `_htax_sticky_{term_id}` post meta entries whose term no longer exists. Covers terms deleted while HyperTaxonomy was inactive.

### Hiding Terms from Other Code

`htax_hide_term_on_read()` (`get_term` filter) and `htax_hide_terms_in_term_queries()` (`terms_clauses` filter) make a hidden term not exist for a viewer who can't see it, so plugins that know nothing about hidden terms need no handling. Both ask `htax_get_term_ids_hidden_from_caller()`, which applies to front-end, REST and AJAX GET/HEAD requests only: wp-admin, cron, CLI and write requests get the real terms. Within those requests a backtrace (taken for term queries when the site has hidden terms and the viewer can't see them, and for lookups only when the term itself is hidden, since a page makes thousands of lookups) identifies callers that keep the real terms: HyperTaxonomy's own code, which applies visibility itself and builds caches shared between viewers, so search and display logic are unaffected; `WP_Term_Query::populate_terms()`, where core writes to what `get_term()` returns and a null is a fatal error; `get_object_term_cache()`, `update_object_term_cache()`, `get_the_terms()` and `is_object_in_term()`, which store full lists shared between viewers (`htax_filter_get_the_terms()` then filters per viewer); `_get_term_hierarchy()`, which stores each taxonomy's hierarchy in the persistent `{taxonomy}_children` option; core functions that change data (`wp_insert_term()`, `wp_update_term()`, `wp_delete_term()`, `wp_insert_post()`, `wp_set_object_terms()`, `wp_remove_object_terms()`, `wp_delete_object_term_relationships()`, the term count update), which can run during a read request; `get_queried_object()`, so hidden archives still 404; `WP_Tax_Query::transform_query()`, so posts carrying hidden terms are never excluded; `get_ancestors()` and `Walker_Category`, which walk a visible term's ancestors; and `get_term_link()` and `get_term_parents_list()` only while looking up a hidden ancestor of a visible term (they read it without checking, and a null ancestor is a fatal error in `get_term_parents_list()`), not when asked about the hidden term itself. `get_term_link()` is checked against its argument; `get_term_parents_list()` overwrites its argument while looping, so `htax_note_visible_term_ancestors()` (`get_ancestors` filter) records the hidden ancestors of visible terms for the request. Core write functions include `wp_set_object_terms()` and `wp_remove_object_terms()`, which call `term_exists()`. The SQL exclusion is safe with the term query cache, whose key includes the SQL.

`htax_keep_hidden_terms_on_save()` (`set_object_terms` action) puts hidden terms back when a logged-in user who can't see them saves a post, since their editor never showed those terms. Cron, CLI and imports aren't affected.

### Visibility Change Propagation

When [`htax_term_visibility_changed`](../developer-reference/actions-filters.html#visibility-changed) fires, a single SQL UPDATE recalculates `is_hidden` for all affected rows — no reindexing needed:
```sql
UPDATE wp_htax_search_terms
SET is_hidden = (source_is_hidden OR term_is_hidden)
WHERE source_term_id = $term_id OR term_id = $term_id
```

**Group taxonomy exception:** When `htax_term_visibility_changed` fires for a term in the `group` taxonomy, `HyperTaxonomy_Search_Index_Manager::on_term_visibility_changed()` returns early without running this UPDATE. Group expansion and archive contribution are visibility-independent by design — all users see the same expanded post sets regardless of whether they can see a hidden group's archive. The `is_hidden` flag in `wp_htax_search_terms` reflects the *content* term's visibility (e.g. whether a tag is hidden), not the group's visibility.

## Term Taxonomy Conversion

For how site owners convert terms, see [Merge & Convert Terms → Convert Terms](../user-guide/merge-convert.html#convert-terms); the `htax_term_converted` action is in [Actions & Filters](../developer-reference/actions-filters.html#term-converted-to-another-taxonomy).

There are two paths.

*Native path (`htax_convert_term` / `htax_convert_terms_batch`).* Creates a new clean term in the target taxonomy and moves post assignments via a single `UPDATE wp_term_relationships` query. The handling of the old term then diverges:

- **With redirect stub** (`$create_redirect = true`, the default): the old term stays in the old taxonomy and becomes the redirect stub pointing to the new term. It keeps its original slug — so `/old-tax/old-slug` correctly finds the stub and HyperTaxonomy's `template_redirect` handler fires the 301 redirect. Group memberships and HyperTaxonomy relationships stay on the stub, which is in the correct taxonomy for them. The new term gets the hidden status, a copy of the icon when `htax_should_transfer_icon()` allows it, and the sticky posts. Because the old term's `term_id` is unchanged, child terms' `parent` pointers remain valid with no re-parenting needed. Slug uniqueness follows WordPress's own rule: per-taxonomy. If no term in the target taxonomy already has the slug, the new term receives the original slug — the stub in the old taxonomy retains the same slug, which is valid since they live in different taxonomies. The redirect is stored as a `redirect` row in `wp_htax_term_relationships` (source = stub, target = new term) plus `_htax_auto_redirect = 1` term meta on the stub. `htax_find_auto_redirect_to_term()` detects existing stubs by querying `wp_htax_term_relationships` for this combination, enabling reverse conversion.
- **Without redirect stub** (`$create_redirect = false`): fires the same WordPress hook sequence as wpcat2tag — `wp_insert_term()` (fires `created_term`) → `UPDATE wp_term_relationships` → `wp_delete_term()` (fires `delete_term`) → `wp_update_term()` to claim the original slug. This ordering means plugins that detect term conversions by watching standard WordPress hooks (WPML, Polylang, etc.) recognize HyperTaxonomy's native no-redirect conversion automatically, without needing HyperTaxonomy-specific integration. A flag (`$GLOBALS['htax_native_conversion_in_progress']`) suppresses HyperTaxonomy's own external-detection hooks during this sequence to prevent double-firing.

Reverse conversion is detected at the start of `htax_convert_term()` via `htax_find_auto_redirect_to_term()`, which queries `wp_htax_term_relationships` for a `redirect` row pointing from the target taxonomy to the source term combined with `_htax_auto_redirect = 1` term meta. If such a stub is found, `htax_restore_converted_term()` moves posts back to the stub, removes the redirect row and meta, and deletes the replacement term — restoring the original term to its exact pre-conversion state.

**Redirect stub creation is per-operation, not global.** There is no site-wide `auto_redirect_on_conversion` setting. The Convert Terms admin page and the `htax_convert_term()` API both take an explicit `$create_redirect` parameter; the checkbox in the admin UI defaults to checked. External converters (wpcat2tag and similar) do not create stubs — the explicit per-operation choice only applies to HyperTaxonomy's own native converter.

Pre-conversion guards: name collision in target taxonomy (rejects — consistent with `wp_insert_term` which also errors on duplicate names; rename source or target term first; Merge Terms is same-taxonomy only and cannot be used directly cross-taxonomy); slug collision is **not** guarded — WordPress auto-suffixes (`test` → `test-2`) when a slug is taken, matching native Add Term UI behavior, so conversion proceeds and the new term receives the suffixed slug; converting the WordPress default category is blocked; a non-zero `wp_terms.term_group` value that already exists in the target taxonomy is blocked (WordPress core's term aliases, from `wp_insert_term()`'s `alias_of`, share a `term_group`; converting would make the two terms aliases in the target taxonomy); converting a term that is already a redirect stub is blocked. `htax_sort_term_ids_parent_first()` topologically sorts batch term lists (DFS, parents before children) so parents are converted before children.

*External plugin path (create-new + delete-old).* Plugins such as the WordPress Categories to Tags Converter create a new term in the target taxonomy via `wp_insert_term()`, move post assignments by direct SQL, then delete the old term via `wp_delete_term()`. HyperTaxonomy detects this pattern: `created_term` (priority 1) records newly created terms; `pre_delete_term` (priority 5) captures group memberships, hierarchy, icon ID, hidden status, and sticky post assignments before term meta is wiped; `delete_term` (priority 5) transfers concept-level attributes (hidden status, icon, sticky posts) to the new term. Group memberships are not transferred — without a stub to hold them in the old taxonomy there is no safe recipient; the admin reassigns manually. No redirect stub is created for external conversions. HyperTaxonomy relationship rows for the deleted term are taxonomy-pair-specific and invalid in the new taxonomy; `htax_cleanup_deleted_term` removes them at priority 10.

**[Sticky posts](../user-guide/per-term-settings.html#sticky-posts).** `htax_transfer_sticky_posts_on_convert()` moves `_htax_sticky_{old_term_id}` postmeta entries to `_htax_sticky_{new_term_id}` in the native path. In the external path, sticky assignments are captured at `pre_delete_term` time (before `delete_term_taxonomy` fires and `htax_cleanup_deleted_term` deletes the postmeta rows) and replayed directly in `htax_complete_external_conversion_after_delete` at `delete_term` priority 5. Without transfer, the new term's archive would show no stickied posts even though the term's content is unchanged.

**WordPress navigation menus.** `htax_update_nav_menu_items_for_converted_term()` runs from both paths. It finds `nav_menu_item` posts with `_menu_item_type = taxonomy`, `_menu_item_object = $old_taxonomy`, `_menu_item_object_id = $old_term_id` and updates the taxonomy slug (and object_id when term_id changes in the external path), preventing broken archive URLs.

**Third-party plugin data.** In the native redirect path the old term remains in the old taxonomy as a stub, so plugins that store data keyed by `(taxonomy_slug, term_id)` (Yoast SEO `wp_yoast_indexable`, WPML `wp_icl_translations`) continue to find it correctly under the old taxonomy. The new term starts fresh with a new `term_id`. In the no-redirect path the old term is deleted; those plugins' records are orphaned and must be updated via the `htax_term_converted` hook. Other plugins' `wp_termmeta` data (ACF field values, etc.) follows the term_id: it stays on the stub in the redirect path, and is removed with the old term in the no-redirect path — the new term starts clean in both cases. For site owners, these cases are covered in [Yoast SEO and Rank Math](../user-guide/other-plugins.html#yoast-seo-and-rank-math), [WPML](../user-guide/other-plugins.html#wpml) and [Polylang](../user-guide/other-plugins.html#polylang).

**`htax_term_converted` action.** Fires at the end of both paths with signature `($new_term_id, $old_taxonomy, $new_taxonomy, $old_term_id)`. `$old_term_id` is the stub (redirect path) or the deleted term (no-redirect / external paths). Plugins use this to update taxonomy-keyed records.

## Term Merge

For how site owners merge terms, see [Merge & Convert Terms → Merge Terms](../user-guide/merge-convert.html#merge-terms); the merge actions are in [Actions & Filters](../developer-reference/actions-filters.html#before-a-merge).

`htax_merge_terms()` creates [redirect stubs](../user-guide/term-relationships.html#redirects) with `htax_create_redirect_term()` after deleting the source terms, because a stub takes its source's name and slug. That step runs after the transaction, so a failed stub doesn't undo the merge: it just isn't counted in `redirects_created`.

[Sticky pins](../user-guide/per-term-settings.html#sticky-posts) move in SQL inside the merge transaction, because the delete hooks remove a deleted term's pins. A post already pinned to the target keeps that pin. Symmetric custom relationships can end up duplicated when the target already has the reverse row, so `htax_cleanup_symmetric_relationship_duplicates()` runs after the sources are deleted.

When a term merge transfers source terms' `member_of` group memberships to the target (`htax_merge_terms()` in term-merge.php), the set of affected group IDs is expanded to include group ancestors (group expansion is hierarchical, so a child group's member-list cache feeds its ancestors' expansions). For each affected group it deletes the `htax_grp_{group_id}_` transients, refreshes the group term count, and cleans the term cache, then fires `htax_term_groups_changed` once for the target term so the search index and other membership-change listeners stay consistent.

## Conflict Detection

The constraints site owners see on the term edit screen are summarized in [Term Relationships → Inclusions and Containers](../user-guide/term-relationships.html#inclusions-and-containers).

HyperTaxonomy automatically prevents or resolves several classes of relationship conflicts:

| Conflict | Behavior |
|----------|----------|
| Self-inclusion | Silently removed from save data before writing |
| Circular redirects (A→B→A) | Auto-resolved: the target's redirect is removed so the new one can save, with an info notice |
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

The shared helper is `htax_remove_hierarchy_conflict_rows( int $term_id, string $taxonomy ): bool` in term-relationships.php, used by the hierarchy change, term merge, and activation sweep enforcement points.

**Cache invalidation for group-based hierarchy interactions:**

- When a term starts or stops redirecting, `htax_resync_redirect_term_inclusions()` (on `htax_redirect_changed` / `htax_redirect_removed`, priority 20) clears the caches of every term in its stored inclusion set, however reached (directly, through groups or container groups), and re-syncs their container index rows: the redirect term stops or starts being their container.
- When a term's WordPress parent changes, `htax_fire_archive_changed_on_hierarchy()` also re-syncs the container index rows of every term its old and new ancestors include: the index stores each container with its child terms, so those terms gain or lose the reparented term as a container. It fires `htax_term_archive_changed` for the old and new ancestors and for the terms that include any of them, since an included term's archive expansion covers its descendants.
- When a term is created under a parent in a hierarchical taxonomy, the search index's `on_term_created()` (`created_term`, priority 20) queues the posts that have container rows on its ancestors: a container's rows cover its descendants, so the new term needs them too.
- When a group is deleted, `capture_group_delete_affected()` (search index, `pre_delete_term`) records the members of the group and its subgroups, the members of its parent groups, the terms including it or a parent group, and the targets of container rows on it or its subgroups. After the rows are gone (`delete_term` priority 20) their inclusion caches are cleared (`htax_clear_term_inclusion_caches()`) and the container index rows of the included side are re-synced. WordPress moves the group's subgroups up to its parent, which this covers too.
- When a term joins or leaves a group, `htax_update_group_membership_caches()` updates the member caches of the groups it joined or left (each group's `htax_grp_*` lists its direct members only; `htax_load_terms_in_groups()` adds subgroups' members when reading, so ancestors' lists don't change), and clears the joining/leaving term M's own `htax_groups_M`; M's `htax_cont_M` when some term includes one of the changed groups or a parent group, and M's `htax_inc_M` and archive caches when one of them or a subgroup is a container group for some term (the two lists `htax_invalidate_group_relationship_caches()` returns). M's redirect, exclusions and relationship rows are unchanged: its custom relationship caches expand the other side's groups, not M's. Step 5 calls `htax_invalidate_group_relationship_caches($all_affected_groups)` right away (it was a cron event before; the reindex the change queues could then run first and read stale caches). The handler runs at priority 5, before the search index handler. It covers: (A) `htax_inc_T` and T's archive caches (count, contributing terms, post lists) for terms T that include the affected group or its ancestors (fwd T→G); (B) `htax_cont_T` only for terms T that are targeted by container rows on the affected group or its ancestors/descendants (rev G→T); (C) `htax_crel_{slug}_T` and (D) `htax_crel_rev_{slug}_T` for the same two sets. The rev/container direction uses the full union of ancestors + descendants because M's path 3 expansion reaches descendant groups.
- When group members are edited via the group admin screen (`htax_save_group_screen_fields`), the group's `htax_grp_{group_id}_{taxonomy}` caches for the changed members' taxonomies and each changed member's `htax_groups_*` are deleted; `htax_term_groups_changed`, fired per changed member, then runs `htax_update_group_membership_caches()` for it, which covers its own caches and the downstream `htax_inc_*` / `htax_cont_*` / `htax_crel_*/htax_crel_rev_*` caches of terms connected through the group.
- The group screen's **Container For** and **Included By** fields clear the group's own raw row cache (`htax_direct_rel_includes_{group}` / `htax_reverse_rel_includes_{group}`) and each changed term's (`htax_reverse_rel_grp_includes_*` / `htax_direct_rel_grp_includes_*`) before firing `htax_container_groups_changed` / `htax_included_groups_changed` per changed term, whose cache handler clears that term's container list or inclusions; the members' caches are cleared as described next.
- When G→T container-group rows are added/removed (either via the term edit screen Container Groups field or the group screen's Container For field), `htax_clear_member_caches_for_groups([G], 'inc')` clears `htax_inc_M`, and M's count and contributing terms, for all members of G and G's ancestor groups. This is necessary because path 3 of `htax_get_included_terms(M)` expands M's member groups downward, so members of ancestor groups of G also see G→T container rows.
- When T→G included-group rows are added/removed (either via the term edit screen Included Groups field or the group screen's Included By field), `htax_clear_member_caches_for_groups([G], 'cont')` clears `htax_cont_M` for all members of G and G's descendant groups, keeping the container view consistent with the inclusion view.
- When custom-type T→G or G→T group rows change in `htax_save_term_relationships`, `htax_clear_member_caches_for_groups` is called with `'crel_rev'` or `'crel'` respectively to clear the affected member caches. For inclusion-based custom types, which count like `includes` (a G→T row is a container group of T), it also clears `htax_cont_M` (T→G) or `htax_inc_M` (G→T) for the affected members, and fires `htax_custom_inclusions_changed` for the search index. Additionally, when only custom-type group rows change (no term-to-term rows change), `$custom_types_changed` is set so that T's own `htax_crel_*/htax_crel_rev_*` and raw row caches for the custom types are cleared.
- When a group's parent changes, `htax_invalidate_caches_on_group_reparent()` (hooked to `edited_term` priority 12) calls `htax_invalidate_group_relationship_caches([old_parent, new_parent])` and then `htax_clear_member_caches_for_groups([old_parent, new_parent], 'inc')`. The first call clears `htax_inc_*`/`htax_cont_*`/`htax_crel_*/htax_crel_rev_*` for terms T that have rows pointing to the affected parent chains. The second clears `htax_inc_M` for members of the old and new parent groups and their ancestor groups, covering the members whose path-3 downward expansion previously (or now) reaches through the reparented group to its container rows.

Functions:
- `htax_cleanup_hierarchy_conflicts( $term_id, $tt_id, $taxonomy )` — runs on `edited_term` priority 20; detects parent change via pre-edit cache, removes conflicting direct rows if any, fires `htax_includes_changed` for affected ancestors
- `htax_fire_archive_changed_on_hierarchy( $term_id, $tt_id, $taxonomy )` — runs on `edited_term` priority 11; detects parent change, deletes `htax_inc_` and `htax_cont_` transients for all terms in old and new parent chains, then fires `htax_term_archive_changed` for each
- `on_taxonomy_hierarchy_changed( $term_id, $tt_id, $taxonomy )` — search index handler on `edited_term` priority 10; rebuilds hierarchy index entries for the reparented term and descendants, clears `htax_inc_`/`htax_cont_` for descendants, deletes stale container index entries under new ancestors
- `htax_invalidate_caches_on_group_reparent( $term_id, $tt_id, $taxonomy )` — runs on `edited_term` priority 12; detects group parent changes and calls `htax_invalidate_group_relationship_caches`

### Redirect Constraints

Site owners meet these rules on the term edit screen; see [Term Relationships → Redirects](../user-guide/term-relationships.html#redirects).

- **No circular redirects:** When A→B is set and B already redirects (directly or transitively) back to A, B's redirect is removed so the new one can save, and an info notice explains it
- **No redirect chains:** When A→B exists and B's redirect target changes to C, A is automatically updated to A→C
- **No self-redirect:** A term cannot redirect to itself
- **Cross-taxonomy supported:** Redirects can point to a term in a different taxonomy (e.g. a tag redirecting to a category). `htax_get_redirect_target()` validates the target taxonomy at resolution time.

### Group Conflicts

Multi-group membership is fully supported — no conflicts arise. When a term belongs to multiple groups and one group is included by another term, the inclusion set is expanded correctly through all applicable groups.
