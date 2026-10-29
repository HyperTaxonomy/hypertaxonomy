---
title: Post List Shortcodes
parent: User Guide
nav_order: 6
---

## 8. Post List Shortcodes

Two shortcodes for displaying posts from the local search index. For fetching posts from peer WordPress sites and RSS/Atom feeds, see the `[tc_peer_posts]` shortcode in the TermConnect documentation.

> **Term and group attributes accept slugs or IDs.** Every term/group attribute below (`include_terms`, `exclude_terms`, `filter_terms`, `filter_out_terms`, `include_groups`, `exclude_groups`, `filter_groups`, `filter_out_groups`) accepts a comma-separated list of either numeric IDs **or** slugs, and the two may be mixed (e.g. `include_terms="news,42,featured"`). Term slugs resolve within the shortcode's `taxonomy`; group slugs resolve within the `group` taxonomy. A purely numeric token is always treated as an ID.

### Common Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| `taxonomy` | Taxonomy to query | `post_tag` |
| `posts_per_page` | Posts per page | `10` |
| `max_results` | Hard cap on total results across all pages (0 = no cap). Set equal to `posts_per_page` to disable pagination entirely | `0` |
| `post_type` | Post type | `post` |
| `orderby` | Comma-separated `field:dir` pairs. Fields: `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `ID`, `relevance`. Direction defaults to `desc` if omitted. | see each shortcode |
| `sort_order_options` | Comma-separated field slugs to offer in a sort order picker rendered above results. Valid fields: `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `ID`, `relevance`. Order in the attribute controls the display order in the picker. Fields already in `orderby` appear as active pills on first load; remaining fields are available to add. Empty = no picker. | — |
| `sort_order_align` | Horizontal alignment of the sort picker in the controls bar: `left`, `right`, or `center`. Empty = default (left). Controls bar renders when sort picker or filter panel is present; both controls appear inline in a flex row. | — |
| `show_thumbnail` | Show featured image (`div` format only) | `true` |
| `show_excerpt` | Show post excerpt (`div` format only) | `true` |
| `meta` | Template string for the meta line rendered below the post title in `div` format. Supports tokens: `{date}`, `{author}`, `{modified}`. Leave empty to hide the meta line entirely. | `{date} by {author}` |
| `image_size` | WordPress registered image size used for the thumbnail in `div` format. Any size registered via `add_image_size()` or the built-in sizes (`thumbnail`, `medium`, `medium_large`, `large`, `full`) is accepted. | `medium` |
| `columns` | Number of columns. For `div` format: CSS grid layout. For `ul`/`ol` format: CSS multi-column text flow. Responsive — automatically collapses to 2 columns on tablet (≤ 768 px) and 1 column on mobile (≤ 480 px). | `1` |
| `format` | Output format: `ul` (unordered list), `ol` (ordered list), or `div` (full article cards). `ul`/`ol` render a `<ul>`/`<ol>` with one `<li>` per post containing only the title linked. `div` renders full article cards with thumbnail, excerpt, and meta. For `ol` with pagination, numbering continues across pages (page 2 starts at `posts_per_page + 1`). | `ul` |
| `template` | CSS template modifier appended to `htax-result-{template}` (applies to `div` format only) | `default` |
| `class` | For `div` format: additional CSS class on the container. For `ul`/`ol` format: CSS class added to each `<li>` element. | — |
| `no_results_message` | HTML shown when no results are found; empty = render nothing | — |
| `filter_terms` | Comma-separated term IDs; ALL must be present in each result post (AND logic, does not affect scoring) | — |
| `filter_groups` | Comma-separated Term Group IDs; at least one term from EACH group must be present — AND across groups, OR within each group (does not affect scoring) | — |
| `filter_out_terms` | Comma-separated term IDs; posts associated with ANY of these terms are excluded from results | — |
| `filter_out_groups` | Comma-separated Term Group IDs; posts associated with any term from any of these groups are excluded from results | — |
| `filters` | Comma-separated keywords to generate an inline filter panel. Shorthand alternative to a separate `[ht_filter_options]` shortcode. Keywords: `date_published`, `date_modified`, `author`, or any taxonomy slug. First taxonomy slug becomes the primary filter (seeded from `filter_terms` if present). | — |
| `filter_options` | Comma-separated per-position options aligned to `filters`. For taxonomy filters: `group:N` (pool from group N). For date filters: `single` (single date picker). Empty position = all defaults. | — |
| `filters_align` | Horizontal alignment of the filter panel in the controls bar: `left`, `right`, or `center`. Empty = default (left). | — |
| `search` | Keyword string that boosts relevance for matching posts. Does not remove non-matching posts — it only raises their score. Supports field operators: `title:word`, `content:word`, `excerpt:word`. Prefix a term with `+` to make it required, `-` to penalize it. Boolean phrases in `"double quotes"` are matched as a phrase. | — |
| `prepend` | HTML template inserted before each post item in the list. Supports post placeholders (see table below). | — |
| `append` | HTML template inserted after each post item in the list. Supports post placeholders (see table below). | — |

**Post placeholders** (for `prepend` and `append`):

| Placeholder | Value |
|-------------|-------|
| `{id}` | Post ID |
| `{title}` | Post title (HTML-escaped) |
| `{url}` | Post permalink |
| `{date}` | Publish date |
| `{modified}` | Last modified date |
| `{author}` | Author display name (HTML-escaped) |
| `{excerpt}` | Post excerpt with HTML preserved |
| `{excerpt_text}` | Post excerpt as plain text, HTML stripped |
| `{comment_count}` | Number of approved comments |
| `{post_type}` | Post type slug |
| `{slug}` | Post slug (URL-safe name) |
| `{image_src}` | Featured image URL at full size, empty string if none |
| `{image_alt}` | Featured image alt text, empty string if none |
| `{image_width}` | Featured image width in pixels, `0` if none |
| `{image_height}` | Featured image height in pixels, `0` if none |
| `{thumbnail}` | Featured image as a full `<img>` element at `medium` size (includes `srcset` and `sizes`), empty string if none |
| `{thumbnail:size}` | Same as `{thumbnail}` but at the specified WP registered size — e.g. `{thumbnail:large}`, `{thumbnail:full}`, `{thumbnail:thumbnail}` |

`{image_src}` always returns the full-size URL; use `{thumbnail:size}` to get a resized `<img>` with responsive attributes. These placeholders are most useful with `format=ul` or `format=ol`, where `show_thumbnail` has no effect, or when you need to position the image differently in `format=div` (set `show_thumbnail=false` and place `{thumbnail}` in `prepend` or `append`). Using `{thumbnail}` and `show_thumbnail=true` together will render the image twice.

**Matching logic is ANY (OR), not ALL (AND).** The `include_terms` / `include_groups` selection uses OR — a post appears in results if it is associated with at least one of the resolved term IDs. Posts matching more terms accumulate a higher relevance score and rank first when `orderby` includes `relevance:desc`. Use `filter_terms` or `filter_groups` to add hard AND constraints on top of this.

**Pagination.** When results span more than one page, Previous / Next navigation is rendered inside `.htax-post-list-nav` directly below the post list. Clicking a navigation button fetches the requested page via AJAX and replaces the post list and nav within the same output block — no URL change or page reload occurs. Because pagination is self-contained within the output block, multiple shortcodes on the same page each paginate independently.

**Relevance scoring:** each post-term relationship carries a strength score reflecting how centrally the post covers that term (based on how prominently the term name appears in the title, excerpt, and body). Direct assignments also receive a higher base contribution than indirect relationships such as inclusions and hierarchy, controlled by the per-taxonomy weights in Settings → Search → Relevance Weights. Together, strength and relationship-type weights determine result ordering when `orderby` includes `relevance:desc`.

---

### [ht_post_list]

Displays posts that belong to the specified terms or term groups. Uses the search index — so if term A includes term B, posts tagged with B appear automatically when term A is specified, with appropriate relevance weighting.

**Additional parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `orderby` | Sort order (see common parameters above) | `date:desc` |
| `include_terms` | Comma-separated term IDs | — |
| `include_groups` | Comma-separated Term Group IDs (expands to all member terms) | — |
| `exclude_terms` | Comma-separated term IDs to remove | — |
| `exclude_groups` | Comma-separated Term Group IDs to remove | — |

Include/exclude uses the same 4-step logic as the term display shortcodes: (1) include groups, (2) exclude groups, (3) include individual terms, (4) exclude individual terms.

If no `include_terms` or `include_groups` is specified, the shortcode renders nothing — this prevents accidentally displaying all posts on the site.

**Keyword boosting with `search`:** The `search` attribute adjusts relevance scores for posts that match the given text, but does not remove non-matching posts from the results. Pair it with `orderby="relevance:desc,date:desc"` to float matching posts to the top. Field operators (`title:`, `content:`, `excerpt:`) restrict matching to a single field; unqualified terms match across all three. Use `+term` to require a term (posts that don't match it score zero for that component) and `-term` to penalize it.

**Examples:**

```
[ht_post_list include_terms="15,22"]

[ht_post_list taxonomy="category" include_groups="3" posts_per_page="5" orderby="date:desc"]

[ht_post_list include_groups="7" exclude_groups="12" show_thumbnail="false"
    orderby="relevance:desc,date:desc"]

[ht_post_list include_terms="42" no_results_message="<p>No posts found.</p>"]

[ht_post_list include_groups="5" filter_terms="18" orderby="date:desc"]

[ht_post_list include_groups="5" filter_groups="9,12" orderby="relevance:desc,date:desc"]

[ht_post_list include_groups="3" format="ul" orderby="title:asc"]

[ht_post_list include_terms="15" format="ol" orderby="title:asc" posts_per_page="10"]

[ht_post_list include_groups="7" format="div" show_thumbnail="true" show_excerpt="true"]

[ht_post_list include_groups="3" search="title:wordpress tutorial" orderby="relevance:desc,date:desc"]

[ht_post_list include_terms="15" search="beginner guide" orderby="relevance:desc,date:desc"
    prepend='<span class="htax-date">{date}</span> ' append='<small>by {author}</small>']
```

---

### HT Post List Block

The **HT Post List** Gutenberg block is the block-editor equivalent of `[ht_post_list]`. It produces identical output — the same queries, pagination, AJAX, relevance scoring, and template rendering — because the block's render callback delegates directly to the shortcode handler.

Find it in the block inserter under **HyperTaxonomy → HT Post List**, or search for "hypertaxonomy".

The block has four inspector panels:

**Term Source**
Configure which posts to retrieve. Select the taxonomy, then pick include/exclude terms using an autocomplete token field (type term names — IDs are stored automatically). Include/exclude groups accept comma-separated Term Group IDs.

**Query**
Select post type from a live dropdown. Set posts per page and max results. The **Sort order** builder lets you add, remove, and reorder sort criteria with a ▲/▼ direction toggle — the same fields available in `[ht_sort_order_options]`: date, modified, title, author, comment count, latest comment, latest activity, ID, and relevance.

**Filters**
Add hard AND constraints on top of the include/exclude selection. *Restrict to terms* requires all listed terms to match (equivalent to `filter_terms`). *Restrict to groups* requires at least one term from each group (equivalent to `filter_groups`). *Exclude terms* removes posts with any of the listed terms (equivalent to `filter_out_terms`). *Exclude groups* removes posts with any term from any of those groups (equivalent to `filter_out_groups`). *Inline filter panel* accepts the same keyword list as the `filters` shortcode attribute (`date_published`, `date_modified`, `author`, or any taxonomy slug) with optional positional `filter_options`.

**Display**
Toggle thumbnail and excerpt. Set the meta line template (tokens: `{date}`, `{author}`, `{modified}`; empty = hide). Set the template slug (maps to `htax-template-{name}` CSS class), no-posts message, additional CSS class, and the fields available in the optional frontend sort picker.

The editor canvas shows a **live server-side preview** that updates as attributes change. Pagination controls, filter panels, and interactive elements are disabled in the preview.

No shortcode is needed — the block renders everything the shortcode does, configured entirely through the block settings panel.

---

### HT Related Posts Block

The **HT Related Posts** Gutenberg block is the block-editor equivalent of `[ht_related_posts]`. It works in any post editor and in FSE post templates — anywhere WordPress provides a post context.

Find it in the block inserter under **HyperTaxonomy → HT Related Posts**, or search for "hypertaxonomy". To display related content from peer WordPress sites and RSS/Atom feeds alongside local posts, use TermConnect's **Peer Posts** block instead.

The block has the same Query, Filters, and Display inspector panels as the Post List block, including the meta line template field. The **Term Source** panel shows only the taxonomy selector — include/exclude term and group controls are not present because the term set is derived automatically from the current post. The taxonomy selector offers an **— All taxonomies —** choice (the empty value) that pools the current post's terms across every taxonomy it belongs to; pick a single taxonomy to restrict matching to it. (For a fixed subset of taxonomies, use the shortcode with a comma-separated `taxonomy` list.)

The editor canvas shows a live server-side preview rendered for the post being edited.

---

### [ht_related_posts]

Displays posts that share taxonomy terms with the current post. Intended for single post pages (silently renders nothing elsewhere).

Term IDs are derived automatically from the current post's direct taxonomy term assignments. The search index then finds all posts related to those terms through any path (direct assignment, inclusion, hierarchy, or redirect). The current post is always excluded from the results.

`include_terms`, `include_groups`, `exclude_terms`, and `exclude_groups` are not used — the term set is determined by the current post.

**Taxonomy scope.** By default `taxonomy="post_tag"` — related posts are matched on the current post's tags only. You can target a different single taxonomy (`taxonomy="category"`), several at once with a comma-separated list (`taxonomy="post_tag,category"`), or **every taxonomy the post has terms in** by leaving it empty (`taxonomy=""`). When more than one taxonomy is in play, the post's terms from all of them are pooled and scored together: a post that shares both a tag **and** a category with the current post sums both contributions and ranks higher than one sharing only a tag. Each taxonomy's relationship-type features (hierarchy, inclusions, redirects) are still applied independently, according to that taxonomy's settings. How a tag match compares to a category match depends on the relevance weights you've configured per taxonomy.

The default sort order is `relevance:desc,date:desc`: posts that share more (or higher-weighted) terms rank first, with date as a tiebreaker. Each of the current post's terms is also weighted by **how central it is to that post** (its TF-based association strength), so results sharing the post's main subjects rank above those sharing only an incidental tag.

**Automatic title boosting:** By default, the current post's title is injected as a `search` keyword to further boost posts whose content matches the title. To disable this, pass an explicit empty value: `search=""`.

```
[ht_related_posts taxonomy="post_tag"]

[ht_related_posts taxonomy="post_tag" posts_per_page="4" show_excerpt="false"]

[ht_related_posts taxonomy="category" orderby="date:desc" meta=""]

[ht_related_posts taxonomy="post_tag" no_results_message="<p>No related posts found.</p>"]

[ht_related_posts taxonomy="post_tag" search="" orderby="relevance:desc,date:desc"]

[ht_related_posts taxonomy="post_tag,category"]

[ht_related_posts taxonomy=""]

[ht_related_posts taxonomy="post_tag" format="div"
    prepend='<span class="htax-date">{date}</span> ']
```

#### Peer/feed content

`[ht_related_posts]` shows only local posts. To blend in related content from peer WordPress sites and RSS/Atom feeds — matched to the current post's terms, including shared-identifier and synonym matching, and grouped by source — use TermConnect's `[tc_peer_posts]` shortcode or **Peer Posts** block. See the TermConnect documentation for details.

---

