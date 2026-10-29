---
title: Installation & Configuration
parent: User Guide
nav_order: 2
---

## 3. Requirements & Installation

| Requirement | Minimum Version |
|-------------|----------------|
| WordPress | 5.9 |
| PHP | 7.4 |
| MySQL | 5.7 (or MariaDB 10.3) |

No additional plugins required. All features including Term Groups are built-in.

### Automatic Installation

1. Log into your WordPress admin
2. Go to **Plugins > Add New**
3. Search for "HyperTaxonomy"
4. Click **Install Now**, then **Activate**

### Manual Installation

1. Upload the plugin folder to `/wp-content/plugins/hypertaxonomy/`
2. Activate through the Plugins screen
3. Go to **Settings > HyperTaxonomy** to configure

---

## 4. Configuration

All settings are at **Settings > HyperTaxonomy**, organized into tabs.

### Features Tab

The main configuration hub. A **General** subtab controls which taxonomies HyperTaxonomy manages; additional subtabs appear for each enabled taxonomy (Tags, Categories, Term Groups, and any custom taxonomies).

**General subtab** — Enable or disable HyperTaxonomy for each available taxonomy via a checklist. Enabling a taxonomy activates its term edit fields and makes it available for all features below.

**Per-taxonomy subtabs** — Configure which features are active for that taxonomy:

| Feature | What it enables |
|---------|----------------|
| Search Enhancement | Include this taxonomy in relevance-scored search, weighted by relationship type (direct assignment, child term, inclusion, redirect target). Enables per-taxonomy weight configuration in the Search tab. |
| Term Icons | Icon upload per term; icons appear in search widgets, term archives, and post meta (theme support required). |
| Visibility | Hide specific terms from post meta or restrict them to editors only. |
| Redirects | 301 redirect support for term archive pages. |
| Group Assignment | Term Groups membership for this taxonomy (required for Tag Groups / PublishPress Tag Groups import). |
| Term Relationships | Inclusion/container relationships, group-based relationships, and custom relationship types. When disabled, no archive expansion occurs for that taxonomy. |
| Archive Customization | Per-term archive description display, sticky posts, posts per page, and sort order. |
| Share list of locations | When the [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) (ATCF) plugin is active, HyperTaxonomy includes location data from ATCF in REST responses for this taxonomy (`schema:location`) — used by TermConnect's peer site matching and proximity-enhanced search. This row only appears when ATCF is active. |

### Display Tab

- **Archive Titles** — Optionally show which terms are contributing to an archive's title (e.g. "JavaScript (including: React)")
- **Archive Description** — Global gate for the per-term "Show description on archive" setting. When disabled, no term descriptions are output regardless of per-term preferences; saved per-term data is preserved.
- **See Also Section** — *(Requires TermConnect)* Managed by the TermConnect plugin. When TermConnect is active, this controls whether the "See also" section appears on term archive pages with external knowledge-base links. Configure format and label in Settings → TermConnect.
- **Sticky Posts** — Global gate: when disabled, no posts are pinned regardless of per-term or per-post settings. When enabled, configure: *Disable default sticky post styles* (if your theme provides its own) and *Maximum sticky posts per archive* (first page only, default 10).
- **Hidden Terms** — Control which roles can see hidden terms on the frontend.
- **Max Term Hierarchy Depth** — Default maximum nesting depth for `[ht_term_list]`, the Term List block, and the search widget's hierarchical checklists (`-1` = unlimited, `0` = top level only). Each can override this with its own `max_depth` attribute/setting.
- **Frontend Styles** — Disable the default frontend stylesheet (`assets/css/htax-shortcodes.css`), which applies to both shortcodes and blocks. Check this if providing your own CSS via a theme or custom stylesheet.

### Icons Tab

The Icons tab has a **General** subtab plus one subtab per taxonomy that has the Icons feature enabled.

**General subtab** — Theme integration reference: PHP code examples for displaying term pills with icons in single.php or archive.php, and a list of available template functions (`htax_the_term_pills`, `htax_the_archive_icon`, `htax_get_term_icon_html`, etc.).

**Per-taxonomy subtabs:**

- **Icon Uploads** — Enable or disable icon uploads for that taxonomy.
- **Icon Dimensions** — Width and height in pixels (16–512). These are the required upload dimensions — images smaller than this cannot be used; images larger trigger a crop dialog. Also configures Medium size (%) and Small size (%) as percentages of the full size, generated automatically on upload. Changing width or height clears existing icon assignments for that taxonomy; image files are preserved. Changing medium/small percentages regenerates those sizes from existing uploads.
- **Corner Style** — Border-radius preset for icons and pills: Square (0), Rounded (4px), Extra Rounded (8px), Circular (50%), or Custom (per-corner sliders 0–100%). A live preview shows small, medium, and full sizes at the current dimensions and corner style.
- **Icon Display** — Controls whether placeholder color squares appear when a term has no icon: Always show placeholders / Only when icon exists (default) / Never show icons. The shortcode `show_icons="always"` overrides this per-instance.
- **Delete All Icons** — Permanently deletes all uploaded icons for that taxonomy from the media library.

### Search Tab

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

  These weights apply in Text Relevance Mode (`orderby=text_relevance`). Higher values give more importance to matches in that field. In Term Relevance Mode (`orderby=relevance`), keyword text acts as a filter and these weights do not apply.

- **Rate Limiting** — Maximum AJAX search requests per user per minute (10–100). Protects against search abuse.

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

### Cache Tab

**Internal Caches**

- **Cache Durations** — Configurable transient expiration times. 0 = never expire (relies on automatic cache invalidation on save). Each row has an inline **Clear** button for that type:
  - *Search cache* — contributing-term computation for taxonomy archive pages (default 30 min)
  - *Autocomplete cache* — tag/term autocomplete suggestions in the admin post editor (default 5 min)
  - *Relationship cache* — redirect targets, hidden terms, group memberships, custom relationship type lookups (default: never expire)
  - *Sticky archive cache* — sticky post lists per term archive page (default: never expire)
  - *Shortcode output cache* — `[ht_post_list]` and `[ht_related_posts]` rendered results (default: never expire)
- **Clear All Caches** — Purges every HyperTaxonomy transient at once.

### Export / Import Tab

- **Export** — JSON-based backup of HyperTaxonomy data (relationships, redirects, group memberships) for selected taxonomies
- **Import** — Restore from a previously exported JSON file

### Advanced Tab

- **Concurrent Edit Locking** — Enable/disable the simultaneous-edit warning overlay on term edit screens. Uses the WordPress heartbeat API. Disable if it conflicts with another plugin that also hooks into the heartbeat.
- **Post Editor Restrictions** — Optionally prevent accidental creation of new tags in the native post editor, guarding against misspelled or duplicate tags. Editors can still create terms via the Terms admin screens.
- **Content-Based Tag Suggestions** — When enabled (default), replaces WordPress's "most used tags" list in the Gutenberg editor sidebar with tags matched to the current post's content. Suggestions are drawn from tags not already assigned to the post, ranked by how often each tag's name appears in the title (shown first), excerpt (shown second), or body (shown third), with count descending within each group. A **Words to ignore** field accepts a comma-separated list of words that are stripped from the post text before matching — useful for excluding generic terms like "post" or "page" that would otherwise produce false matches.
- **Search Widget Assets** — Force search widget CSS/JS to load on every page. Enable only when using page builders or AJAX-loaded content where the shortcode may not be present on initial load.
- **Uninstall** — Option to delete all HyperTaxonomy data when the plugin is uninstalled.
- **Health Check** — System status report: database table presence, search index state, and configuration validity.

---

## Post Editor Features

HyperTaxonomy can display contextual indicators directly in the Gutenberg and Classic post editors when you assign tags or categories to a post. Each indicator is controlled by a per-user toggle in the block editor's **··· menu** (More options) under the HyperTaxonomy group, and also requires the corresponding feature to be enabled on the taxonomy (Features → [Taxonomy]). Both the per-user toggle and the taxonomy feature must be on for an indicator to appear. Preferences persist per user and take effect immediately without saving.

On first visit to the block editor, a dismissible notice explains that these toggles exist and where to find them.

### Inclusion Badges and Container Badges

When enabled, each term pill (flat taxonomies) or checklist entry (hierarchical taxonomies) can show:

- **⇉ N** (light blue badge): This term's archive pulls in posts from N other terms. The tooltip lists their names. Appears when **Inclusion Badges** is on and the taxonomy has Inclusions & Containers enabled.
- **⇇ N** (light green badge): N other terms pull in this term's posts. The tooltip lists those terms. Appears when **Container Badges** is on and the taxonomy has Inclusions & Containers enabled.

These are informational — they help you understand the reach of a term before assigning it to a post.

### Redirect Indicators

When **Redirect Indicators** is on (default) and the taxonomy has Redirects enabled, redirect terms are displayed in italics with a ↪ symbol. Hovering the ↪ shows the redirect target name. This warns editors that assigning a redirect term points visitors away from that term's archive — the term's own archive does not serve posts.

### Hidden Terms

When **Hidden Terms** is on (default) and the taxonomy has Term Visibility enabled, terms marked as hidden are displayed at reduced opacity. This signals that the term is not publicly visible on the frontend.

### Sticky Pushpins

When **Sticky Pushpins** is enabled in the ··· menu and the taxonomy has Archive Customization enabled, a 📌 pushpin icon appears inline next to each term in the post editor's tag/category fields. Clicking the pushpin toggles the post's sticky status for that term's archive without leaving the editor.

- A filled pushpin (📌) means the post is currently pinned to that term's archive.
- A faded pushpin means the post is not pinned.
- Redirect terms are excluded — their archives redirect away, so pinning has no visible effect.
- If a term is removed from the post, it is automatically unpinned.
- The pin is per-term: a post can be sticky for one term but not another.
- The post must be published for sticky pinning to take effect on the frontend.

---

