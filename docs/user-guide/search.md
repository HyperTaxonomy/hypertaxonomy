---
title: Search & PowerSearch
parent: User Guide
nav_order: 5
---

## 7. Search Features


### Advanced Search Widget

Build complex multi-field search forms with AJAX results, conditional logic, and taxonomy filtering. Composed of nested shortcodes inside `[ht_search_form]`.

- **Fields** — collect user input. `[ht_text_field]` captures a keyword; `[ht_pills_field]`, `[ht_checklist_field]`, and `[ht_hidden_field]` let users select taxonomy terms.
- **Field group** (`[ht_field_group]`) — groups fields, controllers, and other field groups under a single AND or OR logic container. Can be targeted by controller actions to show, hide, enable, or disable all its contents at once.
- **Controllers** (`[ht_search_controller]`) — present a set of options (radio buttons, checkboxes, a dropdown, etc.). Each option can carry one or more actions that fire when it is selected or deselected.
- **Controller actions** (`[ht_option_action]`) — each action targets a single field or field group and changes its state: selected terms, option pool, visibility, or enabled/disabled state. Multiple actions in one option all fire together.

#### [ht_search_form]

Container for all search widget shortcodes.

| Parameter | Description | Default |
|-----------|-------------|---------|
| `id` | Form container ID (auto-generated if not specified) | — |
| `state` | Initial state: `expanded` or `collapsed` | `collapsed` |
| `ajax` | Container ID for AJAX results (empty = POST) | — |
| `action` | Form action URL for POST mode. Omit to POST to the current page; set to a URL (e.g. `/search`) to submit to a dedicated results page. See [POST mode results pages](#post-mode-results-pages). | — |
| `orderby` | Comma-separated `field:order` pairs. Fields: `relevance`, `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `ID`. Order: `asc` or `desc` (desc assumed if omitted). Example: `relevance:desc,date:desc,title:asc`. Both POST and AJAX modes support all fields. | `relevance:desc,date:desc` |
| `posts_per_page` | Results per page. Defaults to the WordPress **Blog pages show at most** setting (Settings → Reading). | WP setting |
| `max_results` | Hard cap on total results across all pages (0 = no cap). Set equal to `posts_per_page` to disable pagination. | `0` |
| `min_score` | Minimum overall relevance (0–1) | auto |
| `class` | Additional CSS classes | — |

`relevance` orders by taxonomy match count (weighted by relationship type and configured per-taxonomy weights). When field groups are present, scoring is computed in PHP; otherwise it is computed in SQL.

#### Field shortcodes

**`[ht_text_field]`** — Full-text keyword search. At most one per form; must be placed at the top level of `[ht_search_form]`, not inside `[ht_field_group]`. The text field filters the entire result set independently — it is not part of the AND/OR taxonomy group logic. Supports boolean operators: `+required`, `-excluded`, `"exact phrase"`. When As the Crow Flies is active it also accepts the PowerSearch proximity operators (`near:`, `-near:`) — see **Proximity operators** under [PowerSearch](#ht_power_search--powersearch-form); a text field holding only `near:` operators lists results nearest first under the default sort order.

**`[ht_pills_field]`** — Autocomplete pill-selector. With the default `suggest_on_focus="true"`, the dropdown opens as soon as the input is focused — no typing required. Set `suggest_on_focus="false"` to require at least one character before suggestions appear. With the default `prefetch="true"`, all options are loaded on page load and suggestions are filtered client-side:

- **0 characters** — top terms by post count.
- **1–2 characters** — prefix match, sorted by post count.
- **3+ characters** — substring match, natural order.

All states cap at 10 suggestions and exclude already-selected terms. The option pool must cover every term that could ever be selected, including those that only become visible under a specific controller state — `include_group_options` controller actions narrow the visible subset but never add terms outside the original pool.

**`[ht_checklist_field]`** — Checkbox list rendered as a tree or flat alphabetical list. Can show individual taxonomy terms (the default) or subgroups as the selectable options — the latter is useful when you want users to filter by a category of terms without exposing every individual term.

**`[ht_hidden_field]`** — Submits a set of term IDs without any visible UI. There are no interactive elements; the selection can only be set via shortcode attributes or changed by controllers at runtime.

The three filter fields narrow `[ht_text_field]` results when a text field is present, or filter all site content when it is not.

All field shortcodes require a `name` parameter. The visible field shortcodes (`[ht_text_field]`, `[ht_pills_field]`, `[ht_checklist_field]`) additionally share:

| Parameter | Description | Default |
|-----------|-------------|---------|
| `label` | Visible label | — |
| `show_when` | `always`, `expanded`, `collapsed`, or `never` | `always` |
| `enabled` | `true` or `false` | `true` |
| `class` | Additional CSS classes | — |

Controllers share these same parameters — see the Controllers section below.

**`[ht_text_field]` parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `search_in` | `all`, `title`, `content`, or `excerpt` | `all` |
| `default_value` | Pre-fills the field with a real value that is submitted with the form; once set, `placeholder` is never visible | — |
| `placeholder` | Grey hint text shown when the field is empty; hidden when the field has a value | — |
| `min_length` | Minimum character count; shorter input is rejected with a validation error (only enforced when the field is non-empty) | 3 |
| `min_length_message` | Validation message shown when input is shorter than `min_length` | built-in |
| `max_length` | Maximum character count (0 = no limit); enforced by the browser via the HTML `maxlength` attribute — excess characters on paste are truncated silently | 0 |

**Parameters common to `[ht_hidden_field]`, `[ht_pills_field]`, and `[ht_checklist_field]`:**

> The term option attributes (`include_term_options`, `exclude_term_options`, `add_terms`, `remove_terms`) accept term IDs **or** slugs (resolved within the field's `taxonomy`); the group option attributes (`*_group_options`, `*_descendant_options`, `add_groups`, `remove_groups`) accept group IDs or slugs (resolved within the `group` taxonomy). IDs and slugs may be mixed; a purely numeric token is treated as an ID.

| Parameter | Description | Default |
|-----------|-------------|---------|
| `taxonomy` | Taxonomy slug | `post_tag` |
| `taxonomy_type` | `post` (default) or `term`. Controls how group references are interpreted throughout the field — see table below. | `post` |
| `match` | `any` (OR), `all` (AND), or `none` (NOT IN). With `taxonomy_type="term"` the selection is groups: `any` matches posts in any selected group, `all` posts in every selected group, `none` excludes posts in any selected group (a post is "in" a group when it carries one of the group's member terms). | `any` |
| `expand` | Which relationship types are followed when expanding selected terms against the search index: `all` (use all types enabled by taxonomy feature flags — inclusions, hierarchy, redirects), `none` (direct matches only), or a comma-separated subset: `container`, `hierarchy`, `redirect`. | `all` |
| `filterby` | When `taxonomy_type="term"`: comma-separated post taxonomy slugs to expand submitted group IDs into at query time. Omit to expand across every HyperTaxonomy-enabled taxonomy that is attached to a post type. | — |
| `include_group_options` | **post:** member terms of these groups become the option universe. **term:** these group IDs themselves are the options. | — |
| `include_descendant_options` | **post:** member terms of all descendant groups become the option universe. **term:** descendant group IDs themselves are the options. | — |
| `include_term_options` | Comma-separated term IDs to include as options | — |
| `exclude_term_options` | Term IDs to exclude from the option pool entirely | — |
| `exclude_group_options` | **post:** member terms of these groups are excluded. **term:** these group IDs are excluded. | — |
| `exclude_descendant_options` | **post:** member terms of descendant groups are excluded. **term:** keep these groups as options but prune their subtrees. Applied before `exclude_term_options`. | — |
| `add_terms` | Term IDs to include in the field's initial selection | — |
| `add_groups` | **post:** include member terms of these groups. **term:** include these group IDs directly. | — |
| `add_descendants` | **post:** include member terms of all descendant groups (roots excluded). **term:** include all descendant group IDs (roots excluded). | — |
| `remove_terms` | Term IDs to remove from the initial selection | — |
| `remove_groups` | **post:** remove member terms of these groups. **term:** remove these group IDs. | — |
| `remove_descendants` | **post:** remove member terms of all descendant groups (roots excluded). **term:** remove all descendant group IDs (roots excluded). | — |
| `min_items` | Minimum selections required before the form can be submitted. When ≥ 1, the field label shows a `*` indicator (pills and checklist only — hidden fields have no label). | 0 |
| `max_items` | Maximum selections allowed (0 = unlimited) | 0 |

Controller `add_*` actions only add terms already in the declared option pool — terms outside the pool are silently skipped. See `not_found_message` on `[ht_option_action]` to surface this to users.

All three field types follow the same `taxonomy_type` rules.

**`taxonomy_type` behavior summary:**

| | `taxonomy_type="post"` | `taxonomy_type="term"` |
|---|---|---|
| Selected values | Post taxonomy term IDs | Group/term IDs, expanded server-side at query time |
| Group references expand to | Member terms | The group IDs themselves |
| Descendant references expand to | Member terms of all descendant groups | Descendant group IDs |
| `filterby` | Not used | Comma-separated post taxonomies to expand into; omit for all public taxonomies |

**Group-descendant mode** — an advanced pattern that applies to all three filter fields. Set `taxonomy="group"` and `include_descendant_options` to a root group ID; each descendant group becomes a selectable option. At query time, each selected group is expanded to its member post-taxonomy terms. Use `filterby` to control which post taxonomy receives the expansion.

```
[ht_checklist_field name="spec" taxonomy="group"
    include_descendant_options="3"
    filterby="post_tag"
    hierarchical="false"
    show_when="never"]
```

- `include_descendant_options="3"` — show all descendants of group 3 as options; group 3 itself is excluded.
- `filterby="post_tag"` — expand each selected group into its `post_tag` member terms at query time. Multiple: `filterby="post_tag,category"`. Omit to expand across all public taxonomies.
- When a group is selected, posts must have at least one of its member terms. Deselecting everything disables the filter.

**Parameters common to `[ht_pills_field]` and `[ht_checklist_field]`:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `max_rows` | Cap visible height to this many rows, then scroll (0 = no cap) | 0 |
| `show_count` | Show post count next to each term | `false` |
| `show_icons` | `never`, `when_available`, or `always` | — |
| `min_items_message` | Validation message shown when fewer than `min_items` items are selected | — |
| `max_items_message` | Validation message shown when more than `max_items` items are selected | — |

**`[ht_pills_field]` parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `placeholder` | Autocomplete input placeholder | — |
| `prefetch` | `true` loads the full option pool on page load; suggestions are filtered client-side. `false` fetches matching terms from the server on each keystroke — suitable for very large option pools where upfront loading would be too slow. | `true` |
| `suggest_on_focus` | `true` opens the suggestion dropdown immediately when the input is focused (even with no text typed). `false` requires at least one character before suggestions appear. | `true` |

**`[ht_checklist_field]` parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `hierarchical` | Display in a nested tree (hierarchical taxonomies only) | `true` |
| `max_depth` | Maximum depth for hierarchical display (`-1` = unlimited, `0` = top level only) | `-1` |
| `max_group_depth` | How deep to expand child groups when resolving `include_group_options` (`-1` = global setting) | `-1` |

#### [ht_field_group]

Groups filter fields, controllers, and other `[ht_field_group]`s under a single AND/OR logic container.

| Parameter | Description | Default |
|-----------|-------------|---------|
| `relation` | `AND` or `OR` | `AND` |
| `name` | Optional identifier for targeting this group with controller actions | — |
| `show_when` | `always`, `expanded`, `collapsed`, or `never` | `always` |
| `class` | Additional CSS classes | — |

```
[ht_search_form]
    [ht_text_field name="keyword" search_in="all"]
    [ht_field_group relation="OR"]
        [ht_pills_field name="tags" taxonomy="post_tag"]
        [ht_checklist_field name="categories" taxonomy="category"]
    [/ht_field_group]
[/ht_search_form]
```

**Nesting groups:** place `[ht_field_group]` inside another `[ht_field_group]` to any depth — each opening tag is paired with its own closing tag.

```
[ht_search_form]
    [ht_field_group relation="AND"]
        [ht_pills_field name="species" taxonomy="post_tag"]
        [ht_field_group relation="OR"]
            [ht_checklist_field name="habitat" taxonomy="group" taxonomy_type="term"]
            [ht_checklist_field name="field" taxonomy="category"]
        [/ht_field_group]
    [/ht_field_group]
[/ht_search_form]
```

#### [ht_search_submit]

Submit button for the search form.

| Parameter | Description | Default |
|-----------|-------------|---------|
| `text` | Button label | `"Search"` |
| `show_when` | `always`, `expanded`, or `collapsed` | `always` |
| `class` | Additional CSS classes | — |

```
[ht_search_submit text="Find Posts" class="my-button"]
```

#### [ht_search_toggle]

Toggles the form between collapsed and expanded states. Fields with `show_when="expanded"` are hidden when the form is collapsed and shown when expanded; fields with `show_when="collapsed"` do the opposite. The button label swaps automatically between the two states.

| Parameter | Description | Default |
|-----------|-------------|---------|
| `text_collapsed` | Button label when the form is collapsed | `"Show advanced options"` |
| `text_expanded` | Button label when the form is expanded | `"Hide advanced options"` |
| `class` | Additional CSS classes | — |

```
[ht_search_toggle text_collapsed="Show filters" text_expanded="Hide filters"]
```

#### [htax_distance_field] — Location-Based Distance Filter

Adds a distance filter to a search widget form that lets visitors narrow results by proximity (postal code or geolocation). Requires the [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) (ATCF) plugin to be active. If ATCF is not active, site administrators see an admin notice in place of the field; the shortcode renders nothing for other users.

Must be placed inside `[ht_search_form]`.

| Attribute | Description | Default |
|-----------|-------------|---------|
| `name` | Field name submitted with the form | `htax_distance` |
| `radius` | Comma-separated radius values for the dropdown (in the selected unit) | `5,10,25,50` |
| `unit` | Distance unit: `miles` or `km` | `miles` |
| `country` | ISO country code for postal centroid lookup | `US` |
| `taxonomy` | Taxonomy slug to limit which terms are searched for matching locations | — |
| `filterby` | Alias for `taxonomy` | — |
| `no_location` | What to do with terms that have no location set: `include` (terms without a location pass through, showing their posts regardless of distance) or `exclude` (strict — only terms with a matching location within the radius are included) | `include` |
| `input` | Input method(s): `zip` (postal code input only), `geolocation` (standalone "Find nearby" button), or `zip,geolocation` (postal input with crosshair icon inside it) | `zip,geolocation` |
| `include_term_options` | Comma-separated term IDs or slugs — only these terms are candidates for distance matching | — |
| `exclude_term_options` | Comma-separated term IDs or slugs — these terms are excluded from distance matching | — |
| `include_group_options` | Comma-separated group term IDs — member terms of these groups are candidates | — |
| `exclude_group_options` | Comma-separated group term IDs — member terms of these groups are excluded | — |
| `include_descendant_options` | Root term ID or slug — all descendants are included as candidates | — |
| `exclude_descendant_options` | Root term ID or slug — all descendants are excluded from matching | — |
| `expand` | Relationship expansion for the resolved in-range terms. Same semantics as the `expand` parameter on `[ht_pills_field]` / `[ht_checklist_field]` / `[ht_hidden_field]`: `all` (feature-flag defaults), `none` (direct only), or comma-separated `container`, `hierarchy`, `redirect`. | `all` |

**Example — store finder:**

```
[ht_search_form id="store-finder"]
  [htax_distance_field taxonomy="retailer" radius="5,10,25,50" unit="miles" no_location="exclude"]
[/ht_search_form]
```

The `no_location="exclude"` setting means only retailer terms that have a physical location set in ATCF will ever appear in results — useful when every term is expected to have a location and you do not want unlocated terms showing up regardless of the user's distance selection.

**Expansion and term relationships:**

By default (`expand="all"`), when the distance filter resolves a set of in-range terms, HyperTaxonomy still follows the normal relationship graph — inclusions, hierarchy, and redirects — just as it would for a regular taxonomy field. For example, if *Dollar General* includes *Dollar General Express*, a search within 5 miles of the user will surface posts tagged with either term as long as *Dollar General* has a location in range.

Use `expand="none"` to restrict results to posts directly tagged with the in-range terms only, ignoring inclusions and hierarchy. Use a comma-separated list to allow only specific relationship types:

```
[htax_distance_field taxonomy="retailer" radius="5,10,25" expand="none"]
[htax_distance_field taxonomy="retailer" radius="5,10,25" expand="container"]
[htax_distance_field taxonomy="retailer" radius="5,10,25" expand="container,hierarchy"]
```

**Geolocation UX (`input="zip,geolocation"` or `input="geolocation"`):**

When `input` includes `geolocation`, a crosshair icon appears inside the right edge of the postal input. Clicking it requests browser location permission. On success, the input is populated with the detected postal code (when available) or city name, and the icon turns gray — subsequent searches use the precise coordinates rather than the postal centroid. If the user then edits or clears the input, the icon reactivates and the stored coordinates are cleared so the typed postal code is used instead.

When `input="geolocation"` only (no postal input), a standalone "Find nearby" button triggers an immediate search using coordinates.

#### Controllers — Dynamic Field Population

Controllers let users change what appears in other fields by making a selection. Useful for "show me tags relevant to this category" patterns.

```
[ht_search_controller type="radio" name="content_type" label="Content Type"]
    [ht_search_option value="news" label="News" default="true"]
        [ht_option_action type="include_group_options" target="tags" groups="1,2,3"]
    [/ht_search_option]
    [ht_search_option value="tutorials" label="Tutorials"]
        [ht_option_action type="include_group_options" target="tags" groups="4,5,6"]
    [/ht_search_option]
[/ht_search_controller]

[ht_pills_field name="tags" taxonomy="post_tag"]
```

**`[ht_search_controller]` parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `type` | UI type: `radio`, `checkbox`, `toggle`, `dropdown`, `link`, `button` | `radio` |
| `name` | Unique field name (used by `[ht_option_action]` `target`) | — |
| `label` | Label shown above the controller | — |
| `required` | Show a `*` indicator (radio and dropdown only; for checkbox/toggle use `min_items` instead) | `false` |
| `min_items` | Minimum selections required (checkbox/toggle only); when ≥ 1 shows `*` automatically | 0 |
| `max_items` | Maximum selections allowed (checkbox/toggle only; 0 = unlimited) | 0 |
| `min_items_message` | Message shown when fewer than `min_items` options are selected (checkbox/toggle only) | — |
| `max_items_message` | Message shown when `max_items` is reached (checkbox/toggle only) | — |
| `show_when` | Show only when another field has a value | — |
| `enabled` | Whether the controller is initially enabled (`true`/`false`) | `true` |
| `inline` | Render options inline (`true`/`false`) | `false` |
| `class` | Additional CSS classes | — |

**`[ht_search_option]` parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `value` | The value submitted when this option is selected | — |
| `label` | Display label | — |
| `default` | Pre-select this option on load (`true`/`false`) | `false` |

**`[ht_option_action]`** places an action inside an `[ht_search_option]`. When the option is selected the action fires; when it is deselected the action automatically reverses. Multiple actions per option are allowed and executed in a fixed priority order.

**Option pool actions** — restrict which terms appear as options in the target field (pills and hidden fields; a checklist's options are fixed when the page renders):
`include_term_options`, `include_group_options`, `exclude_term_options`, `exclude_group_options`

**Selection actions** — add or remove items from the target field's selection:
`add_terms`, `add_groups`, `add_descendants`, `remove_terms`, `remove_groups`, `remove_descendants`

`add_groups` / `remove_groups` act on the groups' member terms in the field's taxonomy (for `taxonomy_type="term"` fields, on the groups themselves); membership is resolved when the page renders. `add_*` actions only accept terms that are already in the target field's declared option pool; terms outside the pool are silently skipped (configure `not_found_message` to surface this). `remove_*` actions always succeed. `add_descendants` / `remove_descendants` expand each group ID to its descendant terms (checklist: traverses `data-parent-id` hierarchy; pills: uses the prefetched group map). Each action type is the automatic reversal of the other.

**Constraint actions:** `set_min_items`, `set_max_items`

**Visibility actions:** `set_show_when`, `enable`, `disable`

**Reset actions:** `clear`, `clearOptions`, `resetOptions`, `reset`, `resetAll`

**`[ht_option_action]` parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `type` | Action type (see below) | — |
| `target` | Name of the field to act upon | — |
| `terms` | Comma-separated term IDs (for term-based actions) | — |
| `groups` | Comma-separated group IDs (for group-based actions) | — |
| `value` | Value to set (for `set_min_items`, `set_max_items`, `set_show_when`) | — |
| `not_found_message` | Message shown on this controller when an `add_terms` / `add_groups` / `add_descendants` action skips one or more terms not in the target field's option pool. Cleared automatically when the action reverses. | — |

#### Complete Example

```
[ht_search_form state="collapsed" ajax="search-results" orderby="relevance:desc,date:desc"]
    [ht_text_field name="keyword" placeholder="Search..."]
    [ht_search_toggle text_collapsed="Show filters" text_expanded="Hide filters"]
    [ht_pills_field name="tags" taxonomy="post_tag" label="Tags"
        placeholder="Type to search..." show_icons="when_available" show_when="expanded"]
    [ht_checklist_field name="categories" taxonomy="category"
        label="Categories" hierarchical="true" max_depth="2" show_when="expanded"]
    [ht_search_submit text="Search"]
[/ht_search_form]

[ht_search_results id="search-results"]
```

---

### [ht_power_search] — PowerSearch Form

Standalone text search with relevance scoring and field operators. Uses a shared search index that respects term inclusion relationships, redirect targets, and hidden term settings.

**Parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `id` | Form container ID (auto-generated if not specified) | — |
| `placeholder` | Input placeholder text | `"Search..."` |
| `button_text` | Submit button text (empty = no button; Enter submits) | — |
| `min_length` | Minimum query length | 3 |
| `match` | `any` (OR logic) or `all` (AND logic) | `any` |
| `orderby` | Sort order — see below | `relevance:desc,date:desc` |
| `posts_per_page` | Results per page. Defaults to the WordPress **Blog pages show at most** setting (Settings → Reading). | WP setting |
| `max_results` | Hard cap on total results across all pages (0 = no cap). | `0` |
| `search_in` | Fields to search: `all`, or comma-separated: `title`, `content`, `excerpt`, `tags`, `categories`, `terms`, or a custom taxonomy slug | `all` |
| `ajax` | Container ID for AJAX mode (empty = POST submission). When set, place `[ht_search_results id="…"]` with the same ID on the page. | — |
| `min_score` | Minimum overall relevance score (0–1) | 0.01 |
| `min_title_score` | Minimum title match score | 0 |
| `min_content_score` | Minimum content match score | 0 |
| `min_excerpt_score` | Minimum excerpt match score | 0 |
| `min_tags_score` | Minimum tag match score | 0 |
| `min_categories_score` | Minimum category match score | 0 |
| `min_terms_score` | Minimum score across all enabled taxonomies | 0 |
| `near_country` | ISO 3166-1 country code for `near:` postal code validation and centroid lookup (e.g. `CA`, `GB`). Default `US`. | `US` |
| `class` | Additional CSS classes | — |

Display attributes (thumbnail, format, columns, prepend/append, etc.) are configured on the `[ht_search_results]` container, not on this shortcode.

**How scoring works:** A plain search term (no field operator) contributes simultaneously to the title, content, excerpt, and taxonomy scores. Each taxonomy match counts once: in that taxonomy's own field when the search has one (tags and categories when the search also uses `tags:`, `categories:`, or `terms:`; a custom taxonomy when the search uses its operator, e.g. `colors:`), otherwise in the terms score. A term reached through several relationships (for example, directly and through a group) counts once, at its strongest. `min_terms_score` compares the total across all taxonomies. Multiple matching terms in the same field are additive — two title matches score higher than one. Scores are floating-point values in the 0–1+ range and can exceed 1.0 when many terms match. When multiple `min_*_score` thresholds are set, all non-zero thresholds must be met (AND logic): a post must satisfy every threshold to appear in results.

**Exact term name matching:** When a taxonomy field operator is used (`tags:target`, `categories:news`) or a general term is quoted (`"target"`), PowerSearch checks whether the search string exactly matches any term name — if so, that term receives the *Explicit Selection* weight (the same weight as clicking a term in the Search Widget), which is higher than a partial FULLTEXT match. A tag named "Target" therefore scores higher for `tags:target` than a tag named "Target Gift Cards", even though both contain the word. An unquoted general term (`target`) does not trigger this bonus — it could be searching post content, not a term label.

**Sort order:** Comma-separated `field:direction` pairs. Available fields: `relevance`, `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `ID`. Direction defaults to `desc` if omitted. Example: `relevance:desc,date:desc,title:asc`.

**Query syntax:**

| Syntax | Example | Meaning |
|--------|---------|---------|
| Plain word | `wordpress` | Match anywhere (title, content, excerpt, enabled taxonomies) |
| `title:word` | `title:react` | Match in post title only |
| `content:word` or `body:word` | `content:tutorial` | Match in post content only |
| `excerpt:word` | `excerpt:summary` | Match in post excerpt only |
| `tags:word` | `tags:javascript` | Match posts whose tag names/descriptions contain the word (FULLTEXT) |
| `tag:slug` | `tag:react` | Match posts carrying a tag with exactly this slug |
| `categories:word` | `categories:news` | Match posts whose category names/descriptions contain the word (FULLTEXT) |
| `category:slug` | `category:opinion` | Match posts in a category with exactly this slug |
| `terms:word` | `terms:photography` | Match in all enabled taxonomy term names/descriptions (FULLTEXT) |
| `term:slug` | `term:downtown` | Match posts carrying any enabled taxonomy term with exactly this slug |
| `ProductCategories:word` | `product_categories:blue` | Custom taxonomy **plural** label (spaces → underscores, lowercase) — FULLTEXT in term names/descriptions |
| `ProductCategory:slug` | `product_category:blue-widgets` | Custom taxonomy **singular** label or slug — exact slug match |
| `+word` | `+wordpress` | Required — must appear somewhere in results |
| `+field:word` | `+tags:amazon` | Required in that specific field — posts without a matching term are excluded even if the text matches. Multiple required terms in the same field (`+tags:A +tags:B`) find posts carrying all of them. |
| `-word` | `-stock` | Excluded — posts where the word appears in any text field (title, content, excerpt) or in any taxonomy term name/description are removed from results |
| `-field:word` | `-tags:stock` | Excluded in that specific field — posts with a matching term in that taxonomy are removed; post text is not checked |
| `"phrase"` | `"getting started"` | Match exact phrase in post text or taxonomy term names/descriptions. Also triggers exact name matching: if the quoted string exactly equals a term name, that term receives the higher *Explicit Selection* weight. A single quoted word (`"target"`) works the same way for term name matching. Combine with `+` to make it required: `+"air canada"` |

**Proximity operators** — require the [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) plugin active, with locations attached in ATCF to the terms you reference:

| Syntax | Example | Meaning |
|--------|---------|---------|
| `near:taxonomy:slug[/r]` | `near:post_tag:downtown/5` | Show posts whose terms have ATCF locations within *r* of the named term's location(s), including WP child terms. *r* is in the site's distance unit (As the Crow Flies settings); default 10. |
| `near:X[/r]` | `near:97201/10` or `near:ny/50` | X is first tested as a postal code (uppercase, validated against the country's format). If it matches, the postal centroid is used. If not, X is treated as a term slug and the locations of any matching terms across all enabled taxonomies are used as reference points. |
| `near:me[/r]` | `near:me/25` | Use the visitor's location — see *Where `near:me` gets the location* below. |
| `-near:...` | `-near:post_tag:downtown/5` | Negation: remove in-range posts from results. |

**`near:X` disambiguation in detail:**

1. X is converted to uppercase and tested against the configured country's postal code format (a regex pattern). If it matches, the postal centroid for that code is used as the reference point.
2. If X doesn't match the postal format — or if no format is configured for the country — X is lowercased and looked up as a term slug across all enabled taxonomies.
3. If the slug exists in more than one taxonomy (e.g. `ny` is a tag and a category), the locations from all matching terms are unioned together. A post is "in range" if any of its terms' locations fall within the radius of any of those combined reference locations.
4. If the slug matches nothing, the clause is silently skipped — all posts appear as if the operator were not there.

`near:` is always a filter on the rest of the query, whatever the `match` mode: it narrows the posts the other terms find. Several `near:` clauses are combined as a union — `near:post_tag:downtown/5 near:post_tag:airport/5` keeps posts near either place. A clause whose reference can't be resolved (e.g. `near:me` with no detected location) is ignored; when none resolves, there is no location filter. `-near:` clauses always remove in-range posts.

Only posts with a location in range are kept: posts whose location is unknown — no terms in a location-enabled taxonomy, or none of their terms has a location — are left out of `near:` results. Locations of hidden terms count only for visitors who can see hidden terms. `near:` works the same way in the search widget's `[ht_text_field]`.

The **country context** for postal code validation defaults to US; set `near_country="CA"` (etc.) on the `[ht_power_search]` shortcode or HT PowerSearch block for other countries.

A query made only of `near:` operators (e.g. `near:me/10`, `near:97201/5`) lists every post with a location in range. Its relevance is the distance, so with the default sort order (`relevance:desc,date:desc`) results come nearest first, and posts at the same distance newest first. A different configured `orderby`, or a visitor's choice in a sort picker, takes precedence over distance. The same applies to a search widget's text field holding only `near:` operators.

**Where `near:me` gets the location:**

1. **The browser.** When the query contains `near:me`, the search asks the browser for the visitor's position (the browser asks the visitor's permission once per site; HTTPS required). The last position the browser gave is remembered on that device for 7 days and used if the visitor later declines or the browser can't provide one.
2. **The site's CDN.** Behind Cloudflare with the "Add visitor location headers" managed transform on, the visitor's location comes from Cloudflare's request headers.
3. **IP geolocation**, if enabled in As the Crow Flies (Settings → As the Crow Flies → Advanced).
4. **The fallback location** set in As the Crow Flies (Settings → Advanced) — e.g. the business's own location. The visitor is told that results are near the default location.

When none of these is available, `near:me` is ignored and the visitor is told that the results aren't filtered by distance. The same message appears for a `near:` reference that has no location (e.g. a place name with no matching term). In AJAX mode the message appears above the results; on POST-mode results pages it appears above the theme's result list.

**Examples:**

```
[ht_power_search placeholder="Search posts..." button_text="Search" posts_per_page="20"]

[ht_power_search match="all" placeholder="All words must match..."]

[ht_power_search min_title_score="0.5" placeholder="Search by title..."]

[ht_power_search ajax="search-results" min_length="2"]
[ht_search_results id="search-results"]

[ht_power_search ajax="search-results-cap" posts_per_page="5" max_results="5"]
[ht_search_results id="search-results-cap" columns="2"]
```

For AJAX mode, pair with `[ht_search_results id="…"]` — see the **[[ht_search_results]](#ht_search_results)** section below.

### [ht_search_results] — Shared AJAX Results Container

Used by both `[ht_search_form]` and `[ht_power_search]` in AJAX mode. Pagination, result count, and query scope are controlled by the form shortcode; this container controls display only.

Place it anywhere on the page. Set `ajax="container-id"` on the form shortcode and `id="container-id"` here to link them.

| Parameter | Description | Default |
|-----------|-------------|---------|
| `id` | Must match `ajax=` on the form shortcode that targets this container | — |
| `template` | CSS template modifier applied to each result card | `default` |
| `show_thumbnail` | Show featured images (`div` format only) | `true` |
| `show_excerpt` | Show post excerpts (`div` format only) | `true` |
| `show_meta` | Show post metadata — date and author (`div` format only) | `true` |
| `image_size` | WordPress registered image size for thumbnails | `medium` |
| `columns` | Number of columns. `div` format: CSS grid. `ul`/`ol` format: CSS multi-column text flow. Collapses to 2 on tablet (≤ 768 px), 1 on mobile (≤ 480 px). | `1` |
| `format` | Output format: `div` (full article cards), `ul` (unordered title-link list), or `ol` (ordered title-link list) | `div` |
| `prepend` | HTML template inserted before each result item. Supports post placeholders: `{id}`, `{title}`, `{url}`, `{date}`, `{modified}`, `{author}`, `{excerpt}`, `{comment_count}`, `{thumbnail}`, `{thumbnail:size}`. | — |
| `append` | HTML template inserted after each result item. Same placeholders as `prepend`. | — |
| `no_results_message` | HTML shown when no results are found | — |
| `sort_order_options` | Comma-separated field slugs for a sort order picker. Changing the sort re-runs the search. Empty = no picker. | — |
| `sort_order_align` | Alignment of the sort picker: `left`, `right`, or `center`. | — |
| `filters` | Inline filter panel keywords (see §10). | — |
| `filter_options` | Per-position options aligned to `filters` (see §10). | — |
| `filters_align` | Alignment of the filter panel: `left`, `right`, or `center`. | — |
| `class` | Additional CSS classes | — |

### HT PowerSearch Block

The **HT PowerSearch** block is the block-editor equivalent of `[ht_power_search]`. Find it in the inserter under **HyperTaxonomy → HT PowerSearch**, or search for "hypertaxonomy".

The block renders a live preview of the search form in the editor via server-side render. Inspector panels:

- **Search Form** — placeholder text, button label (empty = Enter key only), match mode (any word / all words), and minimum query length.
- **Results** — which fields to search (`search_in`), posts per page, max results cap, sort order, and minimum relevance score.
- **AJAX Mode** — optional container ID. When set, results load via AJAX into the container rendered by an `[ht_search_results id="…"]` shortcode placed elsewhere on the page. Display options (thumbnail, columns, format, etc.) are configured on that shortcode. Leave empty for POST mode (results rendered by the theme's search template).
- **Advanced** — additional CSS class.

---

### POST mode results pages

A search widget or PowerSearch form without an `ajax` container submits the search to the page (POST mode) and shows the results with the theme's search template. The submission is answered with a redirect to a results URL carrying a short token — `…/?htax_q=…` — that holds the search. Because the results page is an ordinary link:

- the theme's page links (`/page/2/?htax_q=…`) page through the whole result set, using the form's `posts_per_page` and `max_results`;
- Back, Refresh, bookmarks, shared links and "open in new tab" all work, with no "confirm form resubmission" prompt;
- page caches can serve results pages.

Most searches fit in the token itself, so their links keep working indefinitely. Very large searches (many selected terms) are stored on the server instead and their links expire after 30 days. A `near:me` search keeps the location it was run with, so a shared link shows results near the person who shared it. The search text appears wherever the theme shows the search query (e.g. "Search Results for …"). WordPress's own search box is prefilled with it when **Site Search Integration** is on (that box then runs the same engine); otherwise it is left empty, since submitting it would run WordPress's own, different search.

### Native WordPress Search Override

HyperTaxonomy can replace WordPress's built-in search (`?s=…`) with the **PowerSearch** engine, which applies relevance scoring, term relationship expansion, and field operators to all site searches.

**Enable globally:** Settings > HyperTaxonomy > Search > **Site Search Integration** — check **Use PowerSearch for WordPress site search**. Every `?s=` request on the site then uses the PowerSearch engine instead of WordPress's default SQL `LIKE` query.

**Enable per-search:** Append `?htax_search` to any `?s=` URL (e.g. `?s=react&htax_search`) to use PowerSearch for that request only, without enabling it globally. Useful for testing before committing.

**What changes:**
- WordPress search results pages use PowerSearch's MySQL FULLTEXT engine (content processed by HyperTaxonomy's richer text extraction — link URLs preserved as `[URL]`, image alt text and abbreviation expansions included, no false positives from HTML attributes) instead of the default SQL `LIKE` query
- Results are ranked by relevance score (configurable field weights) with date as a tiebreaker
- Term inclusion relationships are applied: posts tagged with an included term appear alongside posts tagged with the searched term
- Hidden terms are excluded for unauthorized users
- Sort order can be controlled via `?htax_orderby=` — the `[ht_sort_order_options action="navigate"]` block works on the search results page

**What stays the same:** The URL structure (`?s=query`) and the template hierarchy (`search.php`). Existing search templates work without modification.

Results are paged the usual way (`/page/2/?s=…`): each page fetches just its own results, and the theme's pagination reflects the full result count. Search feeds (`?s=…&feed=rss2`) use the **Syndication feeds show the most recent** setting for their size. An empty search (`?s=`) keeps WordPress's default of listing every post. `get_search_query()` / `the_search_query()` return the query as usual.

---

### Search Method Comparison

| Feature | Native WP | Text Field | Post List `search=` | PowerSearch |
|---------|-----------|------------|---------------------|---------------------|
| Engine | WordPress LIKE | MySQL FULLTEXT | MySQL FULLTEXT | MySQL FULLTEXT |
| Boolean operators | No | Yes (`+`, `-`, `"..."`) | Yes | Yes |
| Field operators | No | No | Yes (`title:`, `content:`, `excerpt:`) | Yes (`title:`, `tags:`, etc.) |
| Taxonomy field operators | No | No | No (terms supplied explicitly) | Yes |
| Search scope | Title + content | Post fields only | Post fields only | Posts + taxonomies |
| Filters out non-matching posts | Yes | Yes | No (boost only) | Yes |
| Field groups | N/A | Supported | N/A | N/A |
| Best for | Basic search | Faceted filter forms | Term-browsing lists with relevance boost | Standalone advanced search |

---

