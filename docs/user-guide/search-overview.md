---
title: Search Overview
parent: User Guide
nav_order: 9.5
---

# Search Overview
{: .no_toc }

HyperTaxonomy offers two search forms: [PowerSearch](powersearch.html), a text search with field operators, and the [Search Widget](search-widget.html), a faceted form where visitors pick terms. Post lists can also boost posts that match keywords. This page compares them, and covers what the two forms share: the results container for AJAX mode, and results pages for POST mode.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Search Method Comparison

| Feature | Native WP | PowerSearch | Search Widget | Post List `search=` |
|---------|-----------|-------------|---------------|---------------------|
| Engine | WordPress LIKE | MySQL FULLTEXT | MySQL FULLTEXT | MySQL FULLTEXT |
| Multiple words | All must match | Any word by default; all with `match="all"` or the [Default Match Mode](plugin-settings.html#search-tab) setting | Text field: any word by default; all with `match="all"` or the Default Match Mode setting. Term fields: any, all or none of the selected terms, per field (`match`); field groups combine with AND or OR | Any word |
| Boolean operators | `-`, `"..."` (`+` isn't needed: every word is required) | `+` (needed only when matching any word), `-`, `"..."` | `+` (needed only when matching any word), `-`, `"..."` in the text field | `+` (decides which posts get the boost), `-`, `"..."` |
| Post field operators | No | Yes (`title:`, `content:`, `excerpt:`) | No; the text field's `search_in` setting sets the post fields instead | Yes (`title:`, `content:`, `excerpt:`) |
| Taxonomy operators | No | Yes (`tags:`, `tag:`, `terms:`, `category:`, etc.) | No; terms are selected in the form's fields, if it has any | No (terms supplied explicitly) |
| Search scope | Title, excerpt, content | Posts + taxonomies | Text field: the post fields chosen with `search_in`. Term fields: the terms and groups each field offers | Post fields only |
| Location filter | No | `near:` operators (needs As the Crow Flies) | [`[ht_distance_field]`](search-widget.html#ht_distance_field--location-based-distance-filter) in the same form (needs As the Crow Flies) | No |
| Filters out non-matching posts | Yes | Yes | Yes | No (boost only) |
| Nested field groups | N/A | N/A | Supported | N/A |
| Best for | Basic search | Standalone advanced search | Faceted filter forms | Term-browsing lists with relevance boost |
{: .search-methods}

For how PowerSearch and the Search Widget build their queries and score results, see [Search Internals](../architecture/search-internals.html).

## [ht_search_results] — Shared AJAX Results Container

Used by both `[ht_search_form]` and `[ht_power_search]` in AJAX mode. Pagination, result count, and query scope are controlled by the form shortcode; this container controls display only.

Place it anywhere on the page. Set `ajax="container-id"` on the form shortcode and `id="container-id"` here to link them. Display parameters such as `format`, `show_thumbnail` and `image_size` mean the same as in [Post Lists&nbsp;→ Common Parameters](post-lists.html#common-parameters).

| Parameter | Description | Default |
|-----------|-------------|---------|
| `id` | Must match `ajax=` on the form shortcode that targets this container | — |
| `template` | CSS template modifier applied to each result card | `default` |
| `show_thumbnail` | Show featured images (`div` format only) | `true` |
| `show_excerpt` | Show post excerpts (`div` format only) | `true` |
| `show_meta` | Show post metadata — date and author (`div` format only) | `true` |
| `image_size` | WordPress registered image size for thumbnails | `medium` |
| `columns` | Number of columns. `div` format: CSS grid. `ul`/`ol` format: CSS multi-column text flow. Collapses to 2 on tablet (≤&nbsp;768 px), 1 on mobile (≤&nbsp;480 px). | `1` |
| `format` | Output format: `div` (full article cards), `ul` (unordered title-link list), or `ol` (ordered title-link list) | `div` |
| `prepend` | HTML template inserted before each result item. Supports post placeholders: `{id}`, `{title}`, `{url}`, `{date}`, `{modified}`, `{author}`, `{excerpt}`, `{comment_count}`, `{thumbnail}`, `{thumbnail:size}`. | — |
| `append` | HTML template inserted after each result item. Same placeholders as `prepend`. | — |
| `no_results_message` | HTML shown when no results are found | — |
| `sort_order_options` | Comma-separated field slugs for a [sort order picker](archive-controls.html#ht_sort_order_options). Changing the sort re-runs the search. Empty = no picker. | — |
| `sort_order_align` | Alignment of the sort picker: `left`, `right`, or `center`. | — |
| `filters` | Inline filter panel keywords (see [Archive & Results Controls](archive-controls.html)). | — |
| `filter_options` | Per-position options aligned to `filters` (see [Archive & Results Controls](archive-controls.html)). | — |
| `filters_align` | Alignment of the filter panel: `left`, `right`, or `center`. | — |
| `class` | Additional CSS classes | — |

## POST mode results pages

A search widget or PowerSearch form without an `ajax` container submits the search to the page (POST mode) and shows the results with the theme's search template. The submission is answered with a redirect to a results URL carrying a short token — `…/?htax_q=…` — that holds the search. Because the results page is an ordinary link:

- the theme's page links (`/page/2/?htax_q=…`) page through the whole result set, using the form's `posts_per_page` and `max_results`;
- Back, Refresh, bookmarks, shared links and "open in new tab" all work, with no "confirm form resubmission" prompt;
- page caches can serve results pages.

Most searches fit in the token itself, so their links keep working indefinitely. Very large searches (many selected terms) are stored on the server instead and their links expire after 30 days. A `near:me` search keeps the location it was run with, so a shared link shows results near the person who shared it. The search text appears wherever the theme shows the search query (e.g. "Search Results for …"). WordPress's own search box is prefilled with it when **Site Search Integration** is on (that box then runs the same engine); otherwise it is left empty, since submitting it would run WordPress's own, different search.
