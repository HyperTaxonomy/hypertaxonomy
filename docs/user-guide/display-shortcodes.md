---
title: Display Shortcodes
parent: User Guide
nav_order: 7
---

## 9. Display Shortcodes

Four built-in shortcodes for displaying terms on any page or widget. No dependencies required.

**Default styles:** A minimal stylesheet is automatically included when shortcodes or blocks are rendered. Disable in Settings > HyperTaxonomy > Display (Frontend Styles) if providing custom styles. The stylesheet is at `assets/css/htax-shortcodes.css`.

### Common Parameters

All four shortcodes share these parameters:

> **Term and group attributes accept slugs or IDs.** `include_terms` / `exclude_terms` accept a comma-separated list of term IDs **or** slugs (resolved within the shortcode's `taxonomy`); `include_groups` / `exclude_groups` accept group IDs or slugs (resolved within the `group` taxonomy). IDs and slugs may be mixed; a purely numeric token is treated as an ID.

**Content selection:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `taxonomy` | Taxonomy to display (`post_tag`, `category`, or custom slug) | `post_tag` |
| `hide_empty` | Hide terms with no posts | 0 |
| `include_groups` | Comma-separated Term Group IDs — only show terms from these groups | — |
| `exclude_groups` | Comma-separated Term Group IDs to exclude | — |
| `include_terms` | Comma-separated term IDs to include | — |
| `exclude_terms` | Comma-separated term IDs to exclude | — |
| `min_count` | Minimum post count | 0 |

Inclusion/exclusion uses a 4-step process distinct from term relationship processing: (1) expand included groups to member terms, (2) remove members of excluded groups, (3) add individually included terms (overrides step 2 exclusions), (4) remove individually excluded terms. Shortcodes have no step 5 self/ancestor filter — terms are displayed as specified.

**Template:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `header` | Header text; supports `{group_names}`, `{term_count}`, `{total_count}` | — |
| `header_link_behavior` | How group names appear in `{group_names}`: `none`, `link`, `toggle` | none |
| `group_name_delimiter` | Delimiter between group names in `{group_names}` | `, ` |
| `prepend` | Template prepended to each term | — |
| `append` | Template appended to each term | — |
| `show_icons` | Controls `{icon}` placeholder output: `when_available` — icon only; `always` — icon, or colored icon-shaped placeholder when no icon is set | `when_available` |
| `class` | Additional CSS classes | — |

**Append/prepend placeholders:**

| Placeholder | Value |
|-------------|-------|
| `{id}` | Term ID |
| `{name}` | Term name (HTML-escaped) |
| `{url}` | Term archive URL |
| `{slug}` | Term slug |
| `{description}` | Term description (HTML preserved) |
| `{count}` | Term post count |
| `{depth_level}` | Hierarchy depth level (0 = top level) |
| `{icon}` | Term icon `<img>` at small size, or colored icon-shaped placeholder if `show_icons="always"` and no icon is set; empty string otherwise |
| `{icon_src}` | Term icon URL at small size, empty string if none |
| `{icon_src_medium}` | Term icon URL at medium size, empty string if none |
| `{icon_src_large}` | Term icon URL at large size, empty string if none |
| `{icon_width}` | Icon width in pixels (small size) |
| `{icon_height}` | Icon height in pixels (small size) |
| `{icon_width_medium}` | Icon width in pixels (medium size) |
| `{icon_height_medium}` | Icon height in pixels (medium size) |
| `{icon_width_large}` | Icon width in pixels (large size) |
| `{icon_height_large}` | Icon height in pixels (large size) |
| `{is_redirect}` | `1` or `0` |
| `{target}` | Redirect target name |
| `{redirect_target_link}` | Redirect target URL |
| `{redirect_target_anchor}` | Redirect target anchor `id` |
| `{is_redirect?yes_text:no_text}` | Conditional output |

**Redirect display:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `exclude_redirects` | Exclude redirect terms entirely | 0 |
| `hide_empty_redirects` | Hide redirects with 0 posts | 1 |
| `italicize_redirects` | Italicize redirect terms | true |
| `redirect_link_behavior` | `original`, `target`, `anchor`, or `none` | original |
| `redirect_div_class` | Additional CSS class applied to redirect term elements | — |
| `custom_title_for_redirects` | Tooltip/title attribute for redirects with exactly 1 post | — |
| `custom_title_zero_for_redirects` | Tooltip/title attribute for redirects with 0 posts | — |
| `custom_title_plural_for_redirects` | Tooltip/title attribute for redirects with 2+ posts | — |

---

### [ht_term_list]

Hierarchical or flat term list with optional depth control, indentation, and collapsible sections.

**Additional parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `orderby` | Sort by: `name`, `count`, `term_id`, `slug` | `name` |
| `order` | `ASC` or `DESC` | `ASC` |
| `max_depth` | Max hierarchy depth (`-1` = use setting, `0+` = specific depth) | `-1` |
| `font_size` | Base font size in pixels | 12 |
| `indent_style` | `padding`, `dash`, or `none` | `padding` |
| `indent_size` | Pixels for padding indent | 20 |
| `separator` | HTML inserted between terms in flat (non-hierarchical) mode | — |
| `generate_anchors` | Generate anchor `id` attributes for each term element | `true` |
| `collapsible` | `false`, `true` (starts expanded), or `collapsed` | `false` |
| `columns` | 1–4 columns (responsive on small screens) | 1 |
| `div_class` | Additional CSS class for the container `<div>` | — |
| `div_id` | CSS `id` for the container `<div>` | — |

**Examples:**

```
[ht_term_list taxonomy="post_tag"]

[ht_term_list taxonomy="category" max_depth="2" orderby="count" order="DESC"]

[ht_term_list taxonomy="post_tag" include_groups="5,12"
    header="{group_names} — {term_count} terms, {total_count} posts"]

[ht_term_list taxonomy="post_tag" collapsible="true" header="Tags ({term_count})"]

[ht_term_list taxonomy="post_tag" include_groups="10" exclude_groups="15" exclude_terms="42"]

[ht_term_list append='{is_redirect?" → {target}":" ({count})"}']
```

### HT Term List Block

The **HT Term List** block is the block-editor equivalent of `[ht_term_list]`. Find it in the inserter under **HyperTaxonomy → HT Term List**, or search for "hypertaxonomy".

The **Term Source** panel configures the taxonomy, hide-empty, include/exclude groups, and include/exclude terms. The **Display** panel covers sort order and direction, max depth, indent style, columns, collapsible behavior, header link behavior (`none`, `link`, `toggle`), minimum post count filter, and optional header text (supports `{total_count}` and `{group_names}` placeholders).

---

### [ht_term_index]

Alphabetical index with A–Z navigation. Terms are always sorted alphabetically.

**Additional parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `show_navigation` | Show A–Z navigation bar | `true` |
| `show_letter_headers` | Show letter section headers | `true` |
| `navigation_position` | `top`, `bottom`, or `both` | `top` |
| `show_count` | Show post count per term | `true` |
| `uppercase_letters` | Uppercase letter headers | `true` |
| `empty_message` | Message to show for letters that have no terms | — |
| `format` | Render terms as `ul` (unordered list), `ol` (ordered list), or `div` (inline with separator between items) | `ul` |
| `separator` | HTML separator between terms when `format="div"` | — |
| `div_class` | Additional CSS class for the container element | — |
| `div_id` | CSS `id` for the container element | — |
| `columns` | 1–4 columns | 1 |

**Examples:**

```
[ht_term_index taxonomy="post_tag"]

[ht_term_index taxonomy="category" columns="2" navigation_position="both"]

[ht_term_index include_groups="5,8" show_count="false"]
```

### HT Term Index Block

The **HT Term Index** block is the block-editor equivalent of `[ht_term_index]`. Find it in the inserter under **HyperTaxonomy → HT Term Index**, or search for "hypertaxonomy".

The **Term Source** panel configures taxonomy and include/exclude filters. The **Display** panel controls A–Z navigation, letter headers, navigation position, columns, post count visibility, letter case, empty letter message, list format (`ul`/`ol`/`div`), separator (for `div` format), minimum post count filter, container class/id, and optional header text (supports `{term_count}`, `{total_count}`, and `{group_names}` placeholders).

---

### [ht_term_cloud]

Tag cloud with font sizes proportional to post counts.

**Additional parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `smallest` | Smallest font size | 12 |
| `largest` | Largest font size | 22 |
| `unit` | `pt`, `px`, `em`, or `rem` | `pt` |
| `max_results` | Maximum terms to show (`0` = all) | 0 |
| `separator` | Separator between terms | space |
| `div_class` | Additional CSS class for the container element | — |
| `div_id` | CSS `id` for the container element | — |

**Examples:**

```
[ht_term_cloud taxonomy="post_tag"]

[ht_term_cloud taxonomy="category" smallest="14" largest="28" unit="px"]

[ht_term_cloud include_groups="3,7" max_results="50"
    header="{group_names} — {total_count} posts total"]
```

### HT Term Cloud Block

The **HT Term Cloud** block is the block-editor equivalent of `[ht_term_cloud]`. Find it in the inserter under **HyperTaxonomy → HT Term Cloud**, or search for "hypertaxonomy".

The **Term Source** panel configures taxonomy and include/exclude filters. The **Display** panel controls font size range, unit, maximum number of terms shown, minimum post count filter, and optional header text (supports `{term_count}`, `{total_count}`, and `{group_names}` placeholders).

---

### [ht_term_groups]

Collapsible tree structure showing Term Groups with member terms nested inside. Uniquely supports multiple taxonomies simultaneously.

**Note:** This shortcode uses `taxonomies` (plural) instead of `taxonomy`, and `include_groups` selects which root-level groups to display rather than filtering term membership.

**Additional parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `taxonomies` | Comma-separated taxonomy slugs | `post_tag` |
| `collapsible` | `false`, `true` (expanded), or `collapsed` | `true` |
| `auto_collapse` | Auto-collapse siblings when expanding a group | `false` |
| `show_empty_groups` | Show groups with no terms | `true` |
| `show_group_count` | Show term count per group | `true` |
| `show_term_count` | Show post count per term | `true` |
| `group_icon` | Icon for groups — emoji or HTML | `📁` |
| `term_icon` | Icon for terms — emoji or HTML; used as fallback when `show_term_icons="auto"` | `📄` |
| `show_term_icons` | `never` — always use `term_icon` text; `auto` — use uploaded HyperTaxonomy icon per term, fall back to `term_icon` | `never` |

**Examples:**

```
[ht_term_groups taxonomies="post_tag"]

[ht_term_groups taxonomies="post_tag,category" include_groups="1,5"]

[ht_term_groups taxonomies="post_tag" collapsible="collapsed" auto_collapse="true"]
```

### HT Term Groups Block

The **HT Term Groups** block is the block-editor equivalent of `[ht_term_groups]`. Find it in the inserter under **HyperTaxonomy → HT Term Groups**, or search for "hypertaxonomy".

The **Term Source** panel accepts one or more taxonomy slugs (equivalent to the `taxonomies` attribute) and include/exclude group filters. The **Display** panel controls collapsible behavior, auto-collapse, empty group visibility, and group/term count display.

---

