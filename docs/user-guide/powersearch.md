---
title: PowerSearch
parent: User Guide
nav_order: 10
---

# PowerSearch
{: .no_toc }

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## [ht_power_search] — PowerSearch Form

Standalone text search with relevance scoring and field operators. Uses a shared search index that respects term inclusion relationships, redirect targets, and hidden term settings.

**Parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `id` | Form container ID (auto-generated if not specified) | — |
| `placeholder` | Input placeholder text | `"Search..."` |
| `button_text` | Submit button text (empty = no button; Enter submits) | — |
| `min_length` | Minimum query length | 3 |
| `match` | `any` (OR logic) or `all` (AND logic) | **Default Match Mode** setting (Search tab) |
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

**Exact term name matching:** When a taxonomy field operator is used (`tags:python`, `categories:news`) or a general term is quoted (`"python"`), PowerSearch checks whether the search string exactly matches any term name — if so, that term receives the *Explicit Selection* weight (the same weight as clicking a term in the Search Widget), which is higher than a partial FULLTEXT match. A tag named "Python" therefore scores higher for `tags:python` than a tag named "Python Tutorials", even though both contain the word. An unquoted general term (`python`) does not trigger this bonus — it could be searching post content, not a term label.

**Sort order:** Comma-separated `field:direction` pairs. Available fields: `relevance`, `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `ID`. Direction defaults to `desc` if omitted. Example: `relevance:desc,date:desc,title:asc`.

### Query syntax

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
| `+field:word` | `+tags:react` | Required in that specific field — posts without a matching term are excluded even if the text matches. Multiple required terms in the same field (`+tags:A +tags:B`) find posts carrying all of them. |
| `-word` | `-stock` | Excluded — posts where the word appears in any text field (title, content, excerpt) or in any taxonomy term name/description are removed from results |
| `-field:word` | `-tags:stock` | Excluded in that specific field — posts with a matching term in that taxonomy are removed; post text is not checked |
| `"phrase"` | `"getting started"` | Match exact phrase in post text or taxonomy term names/descriptions. Also triggers exact name matching: if the quoted string exactly equals a term name, that term receives the higher *Explicit Selection* weight. A single quoted word (`"python"`) works the same way for term name matching. Combine with `+` to make it required: `+"getting started"` |

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

For AJAX mode, pair with `[ht_search_results id="…"]` — see the **[[ht_search_results]](search-widget.html#ht_search_results--shared-ajax-results-container)** section on the Search Widget page.

## HT PowerSearch Block

The **HT PowerSearch** block is the block-editor equivalent of `[ht_power_search]`. Find it in the inserter under **HyperTaxonomy → HT PowerSearch**, or search for "hypertaxonomy".

The block renders a live preview of the search form in the editor via server-side render. Inspector panels:

- **Search Form** — placeholder text, button label (empty = Enter key only), match mode (any word / all words), and minimum query length.
- **Results** — which fields to search (`search_in`), posts per page, max results cap, sort order, and minimum relevance score.
- **AJAX Mode** — optional container ID. When set, results load via AJAX into the container rendered by an `[ht_search_results id="…"]` shortcode placed elsewhere on the page. Display options (thumbnail, columns, format, etc.) are configured on that shortcode. Leave empty for POST mode (results rendered by the theme's search template).
- **Advanced** — additional CSS class.

## Native WordPress Search Override

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

## Search Method Comparison

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

For how the Search Widget and PowerSearch build their queries and score results, see [Search Internals](../architecture/search-internals.html).
