---
title: Archive & Results Controls
parent: User Guide
nav_order: 8
---

## 10. Archive & Results Controls

### [ht_sort_order_options]

A standalone pill-based sort order selector. Users can add, remove, reorder, and toggle the direction of sort criteria. Primarily used on archive pages (where changing the sort order navigates to a new URL) or inside `[ht_search_form]` (where it submits the form with the updated order).

| Parameter | Description | Default |
|-----------|-------------|---------|
| `fields` | Comma-separated field slugs to offer, in display order. Valid: `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `ID`, `relevance` | `date,modified,title,author,comment_count` |
| `state` | Initial state: `expanded` or `collapsed` | `expanded` |
| `action` | What happens when sort changes: `navigate` (rewrites URL with `?htax_orderby=…`), `submit` (submits ancestor `<form>`), or empty (fires `htax:sort-change` event only) | `navigate` |
| `default` | Initial value, e.g. `date:desc,title:asc`. For `action="navigate"`, auto-populated from `?htax_orderby=` in the current URL when empty | — |
| `input_name` | `name` attribute on the hidden value input | `htax_orderby` |
| `text_collapsed` | Toggle button label when collapsed | `Sort` |
| `text_expanded` | Toggle button label when expanded | `Sort` |
| `class` | Additional CSS classes on the root element | — |

Fields already in `default` appear as active pills on first load; remaining fields are available to add. When all fields have been added the `+` button is hidden.

**Archive use (navigate mode):**
```
[ht_sort_order_options fields="date,modified,title" action="navigate"]
```
Place anywhere on an archive template. Changing the sort order appends `?htax_orderby=field:dir,…` to the URL and navigates. The archive query automatically applies the sort (fields are validated against the archive-valid field list before use).

**Inside a search form (submit mode):**
```
[ht_search_form ajax="" orderby="relevance:desc,date:desc"]
    [ht_pills_field ...]
    [ht_sort_order_options fields="relevance,date,title" action="submit" state="collapsed"]
    [ht_search_submit text="Search"]
[/ht_search_form]
```

---

### HT Sort Order Options Block

The **HT Sort Order Options** Gutenberg block is the block-editor equivalent of `[ht_sort_order_options action="navigate"]`. It is designed for FSE (Full Site Editing) archive and search templates where you want to give visitors a sort control without writing shortcode.

Find it in the block inserter under **HyperTaxonomy → HT Sort Order Options**, or search for "hypertaxonomy".

**When it renders:** The block only produces output on HyperTaxonomy-enabled taxonomy archives (category, tag, or custom taxonomy) and on WordPress search results pages — the two contexts where the `?htax_orderby=` URL parameter is consumed. On any other page type the block renders nothing.

**Inspector panels:**

*Sort Fields* — Check or uncheck each field to include in the picker. Available fields: Date, Modified, Title, Author, Comments, Latest Comment, Latest Activity. Display order follows the canonical field order regardless of the order you check them.

*Display* — Set the initial state (expanded or collapsed), a default sort value (e.g. `date:desc`), custom button labels for the collapsed and expanded states, and an additional CSS class.

**Action mode:** Always `navigate`. Changing the sort order rewrites the current URL with `?htax_orderby=…` and navigates. For `submit` mode (search forms) or event mode (AJAX post lists) use the `[ht_sort_order_options]` shortcode directly.

**Context notice:** When editing in the block editor on a regular post or page (not an FSE archive/search template), the block canvas shows an info notice reminding you that it will only render in the appropriate context. No notice is shown when editing a valid FSE template.

---

### Filter Options Panel

A collapsible filter panel that lets visitors narrow results by taxonomy terms, date range, or author. Works on term archives, post list shortcodes, and search result containers.

### [ht_filter_options] — Full Form

Wrap child filter shortcodes inside `[ht_filter_options]` to create a filter panel:

```
[ht_filter_options action="navigate" state="collapsed" label="Filter"]
    [ht_filter_terms taxonomy="post_tag" label="Tags" match="any" primary="true"
        include_group_options="3"]
    [ht_filter_terms taxonomy="category" label="Categories" match="any"]
    [ht_filter_date_published label="Date" type="range"]
    [ht_filter_author label="Author"]
[/ht_filter_options]
```

**`[ht_filter_options]` parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `action` | What happens when a filter changes: `navigate` (rewrites URL with `?htax_filter[…]`), `submit` (submits ancestor `<form>`), or empty (fires `htax:filter-change` event for AJAX targets) | `navigate` |
| `state` | Initial state: `expanded` or `collapsed` | `expanded` |
| `label` | Toggle button label | `"Filter"` |
| `text_collapsed` | Button label when collapsed (defaults to `label`) | — |
| `text_expanded` | Button label when expanded (defaults to `label`) | — |
| `class` | Additional CSS classes on the root element | — |

#### [ht_filter_terms]

Adds a term-based filter group. One `[ht_filter_terms]` per taxonomy per panel.

| Parameter | Description | Default |
|-----------|-------------|---------|
| `taxonomy` | Taxonomy slug (required) | — |
| `label` | Group label | taxonomy label |
| `type` | Display style: `pills` or `checklist` | auto (pills for flat, checklist for hierarchical) |
| `match` | Filter logic: `any` (OR), `all` (AND), `none` (NOT IN) | `any` |
| `primary` | `true` to mark as the primary filter; initial selection seeded from the result shortcode's `filter_terms` / `filter_groups` | `false` (first `[ht_filter_terms]` is implicit primary) |
| `include_group_options` | Group IDs; limits option pool to terms from those groups | — |
| `exclude_group_options` | Group IDs; removes their terms from the option pool | — |
| `include_term_options` | Term IDs to force into the pool | — |
| `exclude_term_options` | Term IDs to force out of the pool | — |
| `include_groups` | Group IDs to pre-select in the initial state | — |
| `exclude_groups` | Group IDs to pre-select as excluded | — |
| `include_terms` | Term IDs to pre-select | — |
| `exclude_terms` | Term IDs to pre-select as excluded | — |

**Primary filter rules:**
- At most one `[ht_filter_terms]` may have `primary="true"`. If none is marked, the first one is treated as primary.
- The primary filter's initial selection is seeded from the parent result shortcode's static `filter_terms` / `filter_groups` (if any), in addition to any explicit `include_terms` / `include_groups` on the filter itself.
- Seeded terms are forced into the option pool even if the pool definition does not cover them.
- `primary="true"` is invalid with `match="none"` (silently demoted to non-primary).
- An empty selection is a no-op — removing all selected terms shows all posts.

**Archive term exclusion:** On a term archive page, the archive's own term is automatically excluded from the option pool for any `[ht_filter_terms]` whose `taxonomy` matches the archive's taxonomy. No explicit `exclude_term_options` needed.

#### [ht_filter_date_published] and [ht_filter_date_modified]

| Parameter | Description | Default |
|-----------|-------------|---------|
| `label` | Group label | `"Date"` / `"Modified"` |
| `type` | `range` (from/to date pickers) or `single` (exact date) | `range` |

Empty date fields = no-op (no restriction applied).

#### [ht_filter_author]

| Parameter | Description | Default |
|-----------|-------------|---------|
| `label` | Group label | `"Author"` |

Option pool is loaded dynamically from post authors matching the current result set. Empty selection = no-op.

### Inline Filter Panel (simplified form)

Instead of a full `[ht_filter_options]` shortcode, use the `filters` and `filter_options` attributes directly on `[ht_post_list]` or `[ht_search_results]`:

```
[ht_post_list include_groups="3"
    filters="date_published,author,post_tag,category"
    filter_options=",,group:2,group:5"]
```

| `filters` keyword | Equivalent to |
|-------------------|---------------|
| `date_published` | `[ht_filter_date_published type="range"]` |
| `date_modified` | `[ht_filter_date_modified type="range"]` |
| `author` | `[ht_filter_author]` |
| any taxonomy slug | `[ht_filter_terms taxonomy="…"]` |

`filter_options` is a comma-separated list of per-position values aligned by index to `filters`:

| `filter_options` value | Effect |
|-----------------------|--------|
| *(empty)* | All defaults for that filter |
| `single` | Date filters: single date picker |
| `group:N` | Term filters: pool = terms from group N |

**Simplified form conventions:** The first taxonomy slug in `filters` is implicitly `primary="true"`. All filters use `match="any"`. For `match="all"`, `match="none"`, or complex pool definitions, use the full `[ht_filter_options]` shortcode.

### Action modes

| Mode | Use case | How it works |
|------|----------|-------------|
| `navigate` | Archive pages | Rewrites URL with `?htax_filter[taxonomy]=ids&htax_filter[date_from]=…` and navigates. The archive query reads and applies these params automatically. |
| `submit` | Forms | Submits the ancestor `<form>`, forwarding filter state as POST fields. |
| *(empty)* | AJAX result shortcodes | Fires `htax:filter-change` event. Result shortcodes (`[ht_post_list]`, `[ht_search_results]`) listen and re-query immediately. |

When `[ht_filter_options]` is placed as a sibling to a result shortcode's output, the correct action mode is selected automatically based on the result type. The `filters=` inline form always uses the event (AJAX) mode.

### Examples

```
[ht_filter_options action="navigate"]
    [ht_filter_terms taxonomy="post_tag" label="Tags" primary="true" include_group_options="3"]
    [ht_filter_date_published label="Date"]
[/ht_filter_options]

[ht_post_list include_groups="5"
    filters="post_tag,date_published"
    filter_options="group:3,"]

[ht_power_search ajax="results"]
[ht_search_results id="results"
    filters="category,author"
    filter_options="group:7,"]
```

---

## 11. Gutenberg Block Extensions

HyperTaxonomy extends two native WordPress blocks — **Query Loop** and **Latest Posts** — with a Term Group filter panel. No new blocks are added to the inserter; the filter appears as an extra inspector panel on every existing block of either type.

### Query Loop and Latest Posts — HT Term Group Filter

**Where to find it:** Select any Query Loop or Latest Posts block → open the sidebar inspector → scroll to **HT Term Group Filter**.

**How it works:** Type two or more characters to search for groups by name, or browse the preloaded list. Select one or more groups. The query is then restricted to posts that carry at least one term belonging to any of the selected groups (OR logic — selecting groups A and B means posts from A, posts from B, or posts from both are all included). The filter automatically applies across every HyperTaxonomy-enabled taxonomy registered for the block's post type — no taxonomy selector is needed.

**Subgroup expansion:** Group hierarchy is respected. If you select a parent group, posts tagged with terms in any of its child or descendant groups are also included.

**Multiple taxonomy support:** If a post type has two HyperTaxonomy-enabled taxonomies (e.g. both `post_tag` and `category`), a post qualifies if it has a matching term in *either* taxonomy. This is intentional: Term Groups can span taxonomies.

**Combining with native block filters:** The group filter stacks on top of any native block filters (category, tag, author, keyword search on Query Loop; category and author on Latest Posts). A post must satisfy both the native filters and the HyperTaxonomy group filter.

**Filtering by custom taxonomy terms (no coding required):** WordPress's native block filters only expose category and tag pickers (Query Loop) or category and author (Latest Posts) — there is no built-in term picker for custom taxonomies or tags in Latest Posts. The HT Term Group Filter works on any enabled taxonomy. To filter a block by specific custom taxonomy terms, place those terms into a group and select that group in the HT Term Group Filter panel. No template changes or code are needed.

**Example use case:** You have a "Featured" group containing a curated set of tags. Add a Query Loop or Latest Posts block to your home page template, open the inspector, select the "Featured" group in the HT Term Group Filter panel, and the block will only show posts tagged with Featured terms.

> **Version requirements.** The Query Loop group filter requires WordPress 6.1+ (the underlying `query_loop_block_query_vars` filter). The Latest Posts group filter requires WordPress 5.9+ (`pre_render_block`). On earlier versions the attribute is stored but has no effect.

> **If HyperTaxonomy is deactivated:** The group filter has no effect on the frontend while the plugin is inactive. The configured group IDs remain stored in the block and are restored when the plugin is reactivated — provided the post has not been re-saved in the block editor during that time. Re-saving a post while HyperTaxonomy is deactivated causes WordPress to strip the `htax_filter_groups` attribute (it is no longer in any registered block schema), permanently losing the configured filter for that block.

---

