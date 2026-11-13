---
title: Settings Reference
parent: User Guide
nav_order: 12
---

# Settings Reference
{: .no_toc }

All settings are at **Settings > HyperTaxonomy**, organized into tabs.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Features Tab

The main configuration hub. A **General** subtab controls which taxonomies HyperTaxonomy manages; additional subtabs appear for each enabled taxonomy (Tags, Categories, Term Groups, and any custom taxonomies).

**General subtab** — Enable or disable HyperTaxonomy for each available taxonomy via a checklist. Enabling a taxonomy activates its term edit fields and makes it available for all features below.

**Per-taxonomy subtabs** — Configure which features are active for that taxonomy. Each feature is a checkbox on screen, such as *Enable relationships for Tags*:

| Feature | What it enables |
|---------|----------------|
| Search Enhancement | Include this taxonomy in relevance-scored search, weighted by relationship type (direct assignment, child term, inclusion, redirect target). Enables per-taxonomy weight configuration in the Search tab. |
| Term Icons | Icon upload per term; icons appear in search widgets, term archives, and post meta (theme support required). |
| Visibility | Hide specific terms from post meta or restrict them to users who can edit posts. |
| Redirects | 301 redirect support for term archive pages. |
| Group Assignment | Term Groups membership for this taxonomy (required for Tag Groups / PublishPress Tag Groups import). |
| Term Relationships | Inclusion/container relationships, group-based relationships, and custom relationship types. When disabled, no archive expansion occurs for that taxonomy. |
| Archive Customization | Per-term archive description display, sticky posts, posts per page, and sort order. |
| Share list of locations | When the [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) (ATCF) plugin is active, HyperTaxonomy includes location data from ATCF in REST responses for this taxonomy (`htax_locations`) — used by TermConnect's peer site matching and proximity-enhanced search. This row only appears when ATCF is active. |

## Display Tab

- **Archive Titles** — Optionally show which terms are contributing to an archive's title (e.g. "JavaScript (including: React)")
- **Archive Description** — Global gate for the per-term **Show Description** setting. When disabled, no term descriptions are output regardless of per-term preferences; saved per-term data is preserved.
- **Sticky Posts** — Global gate: when disabled, no posts are pinned regardless of per-term or per-post settings. When enabled, configure: *Disable default sticky post styles* (if your theme provides its own) and *Maximum sticky posts per archive* (first page only, default 10).
- **Hidden Terms** — Control who can see hidden terms on the frontend: hidden from all users, including Administrators; visible only to users who can edit posts (default); or visible to all users. Some places hide them from everyone regardless, such as term lists, search option lists and the related terms shared with other tools; see [Term Visibility](per-term-settings.html#term-visibility).
- **Max Term Hierarchy Depth** — Default maximum nesting depth for `[ht_term_list]`, the Term List block, and the search widget's hierarchical checklists (`-1` = unlimited, `0` = top level only). Each can override this with its own `max_depth` attribute/setting.
- **Frontend Styles** — Disable the default frontend stylesheet (`assets/css/htax-shortcodes.css`), which applies to both shortcodes and blocks. Check this if providing your own CSS via a theme or custom stylesheet.

## Icons Tab

The Icons tab has a **General** subtab plus one subtab per taxonomy that has the Icons feature enabled.

**General subtab** — Theme integration reference: PHP code examples for displaying term pills with icons in single.php or archive.php, and a list of available template functions, documented in [PHP API → Term Icons API](../developer-reference/php-api.html#term-icons-api) (`htax_the_term_pills`, `htax_the_archive_icon`, `htax_get_term_icon_html`, etc.).

**Per-taxonomy subtabs:**

- **Icon Uploads** — Enable or disable icon uploads for that taxonomy.
- **Icon Dimensions** — Width and height in pixels (16–512). These are the required upload dimensions — images smaller than this cannot be used; images larger trigger a crop dialog. Also configures Medium size (%) and Small size (%) as percentages of the full size, generated automatically on upload. Changing width or height clears existing icon assignments for that taxonomy; image files are preserved. Changing medium/small percentages regenerates those sizes from existing uploads.
- **Corner Style** — Border-radius preset for icons and pills: Square (0), Rounded (4px), Extra Rounded (8px), Circular (50%), or Custom (per-corner sliders 0–100%). A live preview shows small, medium, and full sizes at the current dimensions and corner style.
- **Icon Display** — Controls whether placeholder color squares appear when a term has no icon: Always show placeholders / Only when icon exists (default) / Never show icons. The shortcode `show_icons="always"` overrides this per-instance.
- **Delete All Icons** — Permanently deletes all uploaded icons for that taxonomy from the media library.

## Search Tab

The Search tab is only available when at least one taxonomy has Search Enhancement enabled in the Features tab. It has a **General** subtab plus one subtab per enabled taxonomy (and Custom Flat / Custom Hierarchical templates for custom taxonomies).

**General subtab:**

- **Indexed Post Types** — Which post types are included in the PowerSearch index and participate in HyperTaxonomy search features. Default: posts and pages (matching WordPress native search). Custom post types registered as public appear here and can be enabled individually.

- **Site Search Integration** — Replace WordPress's default `/?s=…` search with the PowerSearch engine. Applies relevance scoring, term relationship expansion, and field operators automatically. Disable if using Relevanssi, SearchWP, or another dedicated search plugin for the `/?s=` role.

- **Default Match Mode** — Controls how multi-word queries are treated when no `+` or `-` operators are used:
  - **Any (OR)** — posts matching at least one term are returned; more matches rank higher. Suitable for the advanced search widget where users explicitly build queries.
  - **All (AND)** — posts must contain every search term. Consistent with native WordPress search behavior; recommended for sites using HyperTaxonomy to replace native search. In All mode the `+` operator is redundant (every term is already required); only `-` exclusions and quoted phrases add behavior beyond a plain search.

  Applies to native WordPress search and to the PowerSearch shortcode when no `match=` attribute is set. The shortcode's `match=` attribute overrides this setting per-instance.

- **Search Result Order** — Default sort order for enhanced search results. Comma-separated `field:direction` pairs, e.g. `relevance:desc,date:desc`. Valid fields: `relevance`, `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `ID`.

- **Content Relevance Weights** — Multipliers (0–1) applied when scoring keyword matches against post fields:

  | Field | Default |
  |-------|---------|
  | Title match | 1.0 |
  | Content match | 0.8 |
  | Excerpt match | 0.7 |

  These weights multiply keyword matches in each post field when PowerSearch, site search, or a post list with keywords ranks results by relevance. Higher values give more importance to matches in that field.

- **Rate Limiting** — Maximum PowerSearch and search widget requests per minute (10–100), counted per logged-in user and, for visitors, per IP address. A request over the limit gets an error asking the visitor to wait. Protects against search abuse. On a site behind a proxy or CDN that hides visitors' addresses, all visitors share one limit unless a developer passes the real address with the `htax_rate_limit_client_ip` filter.

- **Third-Party Query Expansion** — When enabled, HyperTaxonomy automatically expands term queries in secondary and third-party queries (such as WooCommerce layered nav or custom facet plugins) to reflect inclusion relationships. Also enables expansion-aware term counts in those same widgets. Disabled by default; enable if a facet or layered-navigation plugin is displaying counts or results that do not account for HyperTaxonomy inclusions.
- **Strength Model** — Which model's term strengths (how central each term is to a post) rank term-based search results and related posts. The built-in model, *HyperTaxonomy (term frequency)*, scores a term by how prominently its name appears in the post's title, excerpt, and body. Other plugins can provide their own models; each appears here with the share of post–term pairs it has scored, and can only be selected once it has scored all of them. While a provider's model is selected, pairs it hasn't scored yet (for example on a newly published post) don't contribute to term-based ranking until the provider scores them. The dropdown is disabled when only the built-in model is available. If the selected provider's plugin is deactivated, the built-in model is used automatically and a note under the dropdown says so; the provider's model takes effect again when its plugin is reactivated, and saving the Search tab meanwhile doesn't change the selection unless you change the dropdown. Models whose plugin is no longer active but which still have stored scores are listed as *inactive, stored scores only* — they can't be selected, but choosing one shows its coverage and a Clear Scores button so leftover scores can be removed. Relevance weights apply to every model.
- **Index Coverage** — For the built-in model: how many published posts are indexed, and the number of post–term pairs (directly assigned terms) in total and per taxonomy, with the number of index rows. For a provider's model: how many of those post–term pairs it has scored, in total and per taxonomy.
- **Index Rebuild** — For the built-in model: rebuilds the search index if results seem incorrect. A full rebuild keeps scores stored by providers' models. For a provider's model this becomes **Clear Scores**, which removes every score stored for that model (and switches the selection back to the built-in model if it was selected) — HyperTaxonomy can't recompute a provider's scores, so rescoring has to be done with that provider plugin's own tools.

**Relevance Weights sub-tabs** (one per enabled taxonomy, plus Custom Flat and Custom Hierarchical templates):

Each taxonomy's subtab shows a weight table with rows for each applicable relationship type. Which rows appear depends on the taxonomy:

| Row | Appears when |
|-----|-------------|
| Direct assignment | Always |
| Child term | Hierarchical taxonomies only |
| Non-transitive inclusion | Always (disabled if Term Relationships not enabled) |
| Redirect target | Redirects enabled for that taxonomy only |

Columns depend on the taxonomy type:

| Column | Purpose | Shown for |
|--------|---------|-----------|
| Explicit Selection | Propagation factor stored in the search index at index time. Determines how much of a direct term's relevance score flows to indirect relationships (hierarchy, inclusion, redirect). Saving a change automatically queues a background reindex of the affected posts (processed by WP-Cron); no manual rebuild is needed. | All taxonomies |
| Name Match | Weight applied dynamically when query text-matches the term name. Takes effect immediately without a rebuild. | Tags and Categories only |
| Description Match | Weight applied dynamically when query text-matches the term description. Hidden when "Match term descriptions" is unchecked. Takes effect immediately without a rebuild. | Tags and Categories only |

For custom taxonomies, Name Match and Description Match weights are configured in the **Custom (Flat)** and **Custom (Hierarchical)** template tabs rather than in the per-taxonomy subtab, which only shows Explicit Selection weights.

**Match term descriptions** checkbox (per taxonomy): when checked, search queries also match against term descriptions stored in `wp_term_taxonomy.description`, scored with the Description Match weights. When unchecked, the description branch is skipped in the query entirely — only term names are matched. Defaults to **off**. The Custom Flat and Custom Hierarchical template tabs set the default for newly enabled custom taxonomies; individual taxonomy tabs can override it.

Description Match weights are configured independently from Name Match weights. A common pattern is to use lower description weights (the defaults are roughly 70% of the corresponding name weight) since term names are typically a more precise signal than descriptions.

## Cache Tab

**Internal Caches**

- **Cache Durations** — Configurable transient expiration times. 0 = never expire (relies on automatic cache invalidation on save). Each row has an inline **Clear** button for that type:
  - *Search cache* — contributing-term computation for taxonomy archive pages (default 30 min)
  - *Autocomplete cache* — tag/term autocomplete suggestions in the admin post editor (default 5 min)
  - *Relationship cache* — redirect targets, hidden terms, group memberships, custom relationship type lookups (default: never expire)
  - *Sticky archive cache* — sticky post lists per term archive page (default: never expire)
  - *Shortcode output cache* — `[ht_post_list]` and `[ht_related_posts]` rendered results (default 1 hour)
- **Clear All Caches** — Purges every HyperTaxonomy transient at once.

## Export / Import Tab

- **Export** — JSON-based backup of HyperTaxonomy data (relationships, redirects, group memberships) for selected taxonomies
- **Import** — Restore from a previously exported JSON file
- **Migrate** — Import from Tag Groups (PublishPress Tag Groups). See [Import & Migration](import-migration.html).
- **Sync** — How HyperTaxonomy stays in step with Tag Groups after migrating

## Advanced Tab

- **Concurrent Edit Locking** — Enable/disable the simultaneous-edit warning overlay on term edit screens. Uses the WordPress heartbeat API. Disable if it conflicts with another plugin that also hooks into the heartbeat.
- **Post Editor Term Loading** — Load a post's assigned tags from PHP, so the block editor shows them as soon as the Tags field appears and doesn't request each one over REST. Without this, typing in the field before the tags load replaces them. Disable if a plugin adds custom fields to terms in the block editor.
- **Post Editor Restrictions** — Optionally restrict editors to existing tags in the post editor, guarding against misspelled or duplicate tags. Existing tags can be picked from autocomplete, typed, or pasted, including several at once. In the block editor, a new tag is refused with a message and removed from the field, and any existing tags entered with it are still added. In the Classic Editor, existing tags save normally and new ones are left off when the post is saved, with a notice listing them. A name must match an existing tag exactly: "OBrien" counts as new even if "O'Brien" exists. In the block editor, a tag with a backslash in its name has to be picked from autocomplete. Quick Edit, Bulk Edit, the Tags screen, imports, other plugins, and other REST API clients can still create tags.
- **Content-Based Tag Suggestions** — When enabled (default), replaces WordPress's "most used tags" list in the Gutenberg editor sidebar with tags matched to the current post's content. Suggestions are drawn from tags not already assigned to the post, ranked by how often each tag's name appears in the title (shown first), excerpt (shown second), or body (shown third), with count descending within each group. A **Words to ignore** field accepts a comma-separated list of words that are stripped from the post text before matching — useful for excluding generic terms like "post" or "page" that would otherwise produce false matches.
- **Search Assets** — *Always load search assets* forces the Search Widget and PowerSearch CSS/JS to load on every page. Enable only when using page builders or AJAX-loaded content where the shortcode may not be present on initial load.
- **HyperTaxonomy Presence Header** — Add the `X-HyperTaxonomy` header to REST API responses. Peer sites, including TermConnect on remote installs, use it to detect HyperTaxonomy. Disable to opt out of being discovered as a vocabulary peer.
- **Term Inclusion Relationships** — Include inclusion and synonym data in REST term responses. Disable on sites with deep relationship graphs where REST performance is a concern, or to keep vocabulary structure private.
- **Custom Term Relationships and Relationship Types** — Expose custom relationship type definitions over REST, and include custom term relationships in REST term responses.
- **Uninstall** — Option to delete all HyperTaxonomy data when the plugin is deleted. See [Deactivation & Uninstall](deactivation-uninstall.html#uninstall).
- **Health Check** — System status report: database table presence, search index state, and configuration validity.
