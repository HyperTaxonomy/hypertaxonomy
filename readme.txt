=== HyperTaxonomy – Semantic Term Relationships for WordPress - tag/category/custom term groups, inclusions, synonyms, redirects, rich archives, and more ===
Tags: tags, categories, taxonomy, relationships, archive, organization, synonyms, redirect, sticky posts, icons, search, SEO
Contributors: eugenevv
Author: Eugene Weinstein
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Model any semantic connection in your domain — better search, archive expansion, and more. A foundation for TermConnect, WP-SKOS, and other plugins.

== Description ==

*A modicum of order for a chaotic world*.

In WordPress, a post reaches a tag archive only if it carries exactly the right tag — so building a comprehensive site means either constantly re-tagging posts or accepting gaps. HyperTaxonomy solves this at the taxonomy level: define once how your terms relate to each other, and every post with a related term automatically surfaces in the right archives, search results, and content blocks — past posts and future posts alike, with no re-tagging and no maintenance overhead.

Build inclusions (any term can pull in another's posts), synonyms (bidirectional inclusions), term groups for bulk management, and 301-safe redirects. Enrich term archives with descriptions, sticky posts, and custom icons. Build faceted search interfaces with AJAX results, and publish term lists or post grids as shortcodes or Gutenberg blocks — all through the WordPress admin, no coding required.

For cross-site post fetching, RSS/Atom feed aggregation, and external identifiers per term, see the companion plugin **TermConnect**.

For a detailed walk-through of the concepts behind HyperTaxonomy — why WordPress taxonomies fall short, how the relationship model is designed, and when to use each feature — see the [User Guide](https://hypertaxonomy.org/hypertaxonomy/user-guide/).

= Core Features =

**Relationship Management**
* **Term Groups** — Built-in group taxonomy for term organization and bulk inclusion
* **Includes** — Tag and category archives can include posts with related terms
* **Excludes** — Override inclusion for select terms without changing group composition
* **Synonyms** — Make tags or categories interchangeable via mutual inclusion
* **Relationship Types** — Define additional relationship types to express semantic connections between terms

**Term Management**
* **Redirects** — Seamlessly redirect terms with 301 status and automatic chain resolution to preferred terms
* **Merge Terms** — Permanently consolidate duplicate or outdated terms, transferring all posts and relationships to the target term with optional 301 redirect stubs
* **Taxonomy Conversion** — Move terms between taxonomies; the original term optionally stays as a 301 redirect stub in the old taxonomy, keeping old archive URLs working and the conversion reversible
* **TaxoPress Merge Warning** — When [TaxoPress](https://wordpress.org/plugins/simple-tags/) (PublishPress Taxonomies) is active, a dismissible notice on its own Merge Terms screen warns before merging a HyperTaxonomy-enabled taxonomy there, since TaxoPress's merge doesn't carry over relationships, hidden-term status, icons, or connected-plugin data — and points to HyperTaxonomy's own Merge Terms instead

**Archive Enhancements**
* **Rich Archive Descriptions** — Display HTML-formatted descriptions below archive titles
* **Term-Specific Sticky Posts** — Pin posts to the top of any term archive
* **Custom Archive Pagination** — Override the global posts-per-page setting per individual term
* **Smart Archive Headings** — Show which terms contributed content to current page (pagination-aware)
* **Hidden Terms** — Mark terms for internal organization without public display
* **Term Icons** — Upload custom icons with configurable dimensions for visual term identification
* **Third-Party Query Expansion** — Automatically expands single-term taxonomy queries from FacetWP, WooCommerce layered nav, page builders, and other plugins to include HyperTaxonomy inclusions; adjusts term counts to match.

**Search Enhancements**
* **Search Index** — Denormalized index covering configured post types (default: posts and pages); powers the search widget and PowerSearch with inclusion-aware results and relevance scoring
* **PowerSearch** — Full-text search shortcode with field operators (`title:`, `tags:`, `content:`, custom taxonomy slugs), required/optional/excluded term modifiers, AND/OR match modes, and per-field score thresholds; proximity operators (`near:`, `-near:`) when [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) is active — `near:me` uses the visitor's browser location, with CDN, IP and site-default fallbacks, and says so when it can't locate the visitor
* **Shareable, paginated results pages** — search forms without an AJAX results container redirect to a results link that pages through the full result set and works with Back, Refresh, bookmarks, sharing and page caching
* **Site Search Integration** — Optionally replace WordPress's built-in `?s=` search with PowerSearch (relevance scoring, relationship expansion, field operators, HTML-stripped content)
* **Relevance Scoring** — Content-aware term-to-post strength scoring (how central each tag is to the post) combined with configurable relationship-type weights. Other plugins can provide their own strength models (for example, semantic similarity), selectable in Settings → Search once they have scored every post–term pair
* **Smart Ranking** — Relevance score for primary result ordering, date published for secondary (default sort order)
* **Custom Sort Order** — Control result ordering by any combination of relevance, date, modified, title, author, or number of comments — ascending or descending

**Advanced Search Widget**
* **Custom Search Builder** — Create complex search forms with nested conditions
* **Dynamic Field Types** — `text`, `pills` (taxonomy term selectors with autocomplete), `checklist`, and `hidden` fields; conditional controllers with `radio`, `checkbox`, and `dropdown` modes
* **Conditional Display Logic** — Show/hide fields, update their values and the range of allowed values — based on user actions
* **Per-Field Match Logic** — Each term field independently supports any-of, all-of, or none-of matching against selected terms
* **Nested Field Groups** — Combine fields into groups, nest groups within other groups, assign custom group level match logic
* **Term Icon Support** — Display term icons in search pills (with optional color-coded placeholders)
* **Auto-complete** — Smart suggestions for term selection
* **AJAX Results** — Live search without page reloads in AJAX mode
* **Geo Distance Filter** — Filter results by distance from a postal code or the visitor's current location (requires [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/)); configurable radius options, miles/km, and pass-through or strict mode for terms with no location data

Post list, display, PowerSearch, and sort-order shortcodes each have a corresponding Gutenberg block with a live editor preview. The Advanced Search Widget is a composed system of nested shortcodes; it has no single block equivalent and must be built in markup or a text block. See the dedicated sections below for syntax and parameters.

**Native Block Extensions**
* **Query Loop — HT Term Group Filter** — Adds a Term Group filter panel to every core Query Loop block; select one or more groups to restrict results to posts with matching terms (OR logic, subgroup-inclusive, taxonomy auto-detected from post type)
* **Latest Posts — HT Term Group Filter** — Same Term Group filter panel on every core Latest Posts block; filters the block's post list to terms from the selected groups

**Admin Interface**
* **Term Inclusion Relationship Editor** — Intuitive interface with color-coded pill-based term selection
* **Indirect Synonym Detection** — Automatically detects synonyms created through group inclusion
* **Enhanced Term Lists** — Term lists show icon, visibility status, and redirect status with filters; Redirect, Icon, Visibility, and Term Groups columns are sortable so you can instantly see all redirect terms or all hidden terms for a taxonomy
* **Term Groups Admin** — Hierarchical group management with member term assignment
* **Bulk Actions** — Context-aware bulk actions for visibility, icons, and group assignment; the group assignment modal uses three-state checkboxes (add / remove / no change) so mixed memberships across selected terms are handled non-destructively
* **Post Editor Indicators** — Per-user toggles in the block editor's ··· menu show contextual information for each assigned term without leaving the editor: Inclusion Badges (⇉N), Container Badges (⇇N), Redirect Indicators (italics + ↪), Hidden Terms (faded), and Sticky Pushpins (📌 toggle). Each toggle is independent and persists per user.
* **Content-Based Tag Suggestions** — Replaces WordPress's "most used tags" suggestions in the Gutenberg editor with tags matched to the current post's content; suggestions are ranked by how prominently each tag appears in the title, excerpt, and body, with an optional ignore-words list
* **Tag Creation Control** — Optionally prevent accidental creation of new tags in native post editor
* **Concurrent Edit Locking** — Heartbeat-based lock with take-over flow prevents two admins from editing the same term or relationship type simultaneously (can be disabled in Advanced settings if it conflicts with another plugin); navigating away with unsaved changes triggers a browser confirmation prompt
* **Tabbed Settings** — Control cache expiration, permitted AJAX request frequency, frontend styles, and more

**Developer Tools**
* **PHP API, hooks & REST extensions** — Helper functions, actions/filters, an integration API for expansion-aware queries, and HyperTaxonomy-specific fields on the WordPress REST API. Full reference in the [Developer Reference](https://hypertaxonomy.org/hypertaxonomy/developer-reference/).
* **Debug Mode** — Add ?htax_debug=1 to any archive URL (admin only). Additional internal diagnostics in the output require `WP_DEBUG` to be enabled.

= Key Benefits =

* **Clean Organization** — Model how your topics actually relate — no duplicate tagging, no maintenance overhead.
* **Automatic Content Discovery** — Posts appear in every archive where they belong, automatically — past and future posts alike, across every taxonomy.
* **Flexible Search** — Build faceted search forms with term filtering, AJAX results, and relevance scoring.
* **Shortcodes & Blocks** — Display term lists, tag clouds, alphabetical indexes, and post grids anywhere — with live Gutenberg editor previews.
* **Improved SEO** — Automatic 301 redirects preserve search rankings when consolidating or renaming terms.
* **Cross-Site Content** — Fetch related posts from peer WordPress sites and RSS/Atom feeds via the **TermConnect** companion plugin.

= How It Works =

**Relationship Types:**
1. **Inclusion** — Term A includes Term B (equivalently: Term B specifies Term A as a container) → Posts with Term B appear in Term A's archive. The relationship can be defined from either direction and is stored as a single record.
2. **Group Inclusion** — Term A includes Term Group G → Posts with any member term of G appear in Term A's archive
3. **Container Groups** — Term B specifies Term Group H as one of its container groups → Posts with Term B appear in the archive of every term that's a member of H
4. **Bidirectional Inclusion (Synonyms)** — Term A includes Term B and Term B includes Term A → Posts with either term appear in both archives
5. **Redirects** — Term A redirects to Term B → Visiting Term A's archive redirects to Term B's archive with 301 status
6. **Hidden Terms** — Terms that exist in the taxonomy for organizational purposes, but are shown only to admins or editors
7. **Custom Relationship Types** — Two kinds: *Inclusion-based* types participate in archive expansion and search indexing automatically (same taxonomy required for source and target, groups expanded to their members); *Custom* types store the relationship only and leave behavior to your theme or plugin

**Non-transitive by design:** Inclusion relationships do not chain — if A includes B and B includes C, A does not automatically include C. Each inclusion is explicit and intentional. Use native category hierarchy where cascading inclusion is appropriate.

**Taxonomy-Specific Constraints:**
* **Term relationships controlled per taxonomy** — All relationship features (inclusion/container relationships, group-based relationships, custom relationship types) are enabled or disabled together per taxonomy in Settings → HyperTaxonomy → Features.
* **Archive customization controlled per taxonomy** — Sticky posts, per-term posts-per-page, and per-term sort order are enabled or disabled together per taxonomy. When disabled, existing settings are preserved and restored on re-enable.
* **Ancestor-descendant relationships are excluded** — For hierarchical taxonomies, HyperTaxonomy inclusion and container relationships are restricted to cross-branch terms. Ancestors and descendants (parent, child, grandparent, etc.) are excluded from both the included set and the container set because WordPress hierarchy already handles their archive overlap. Terms that share a common ancestor but are not directly related (e.g. siblings) may freely have HyperTaxonomy inclusion or container relationships.
* **Category hierarchy expanded** — When a parent category is included (directly or via groups), all its subcategories are automatically included in searches and archives.

**Redirect Behavior:**
* **Archive redirect** — Visiting a redirect's archive URL (e.g., /tag/ml/) redirects to its target (e.g., /tag/machine-learning/). The redirect term has no archive of its own.
* **Independent from relationships** — Redirect target can be any term in the same taxonomy (except self). Inclusion and container relationships are independent of redirect status.
* **No redirect chains** — If Tag A redirects to Tag B, and Tag B is later updated to redirect to Tag C, Tag A automatically updates to redirect directly to Tag C.
* **Circular redirects blocked** — If A redirects to B and B redirects to A, the second redirect is prevented; circular chains would create infinite loops.
* **Optional search expansion** — Search results can optionally include redirect targets and their inclusions.

= Quick Start Examples =

**Content Organization:**
* "Fruits" tag includes "Apples", "Bananas", "Grapes"
* "Lunch Items" includes "Apples", "Pancakes", "Yogurt"
* Posts tagged with "Apples" appear in both "Fruits" and "Lunch Items" archives without duplicate tagging

**Group-Based Inclusion:**
* "Fruits" tag includes entire "Berries" group — posts from all berry tags appear in Fruits archive
* Add "All Brands" tag to "Brands" container group — "All Brands" appears in Nike, Adidas, Puma archives
* Parent groups automatically expand to child groups for seamless hierarchy support

**Exclusions (Fine-Grained Control):**
* "JavaScript" includes "Frameworks" group, excludes "Legacy" term — modern frameworks only
* "Beginner CSS" includes "CSS Frameworks" group, excludes "Tailwind" term — too advanced
* Exclusions always win — no inclusion source (including container groups) can override a direct exclusion

**Bidirectional Inclusion (Synonyms):**
* Add "Tangerine" to "Clementine" included list — and add "Clementine" to "Tangerine" included list
* Results in synonyms, eliminating the need to add redundant tags to the same posts
* Both terms show each other in the Included Tags section, each marked with the ⇆ icon indicating the bidirectional relationship

**Content Consolidation:**
* Redirect outdated terms to current ones, e.g., /tag/old-tag/ to /tag/new-tag/
* Maintain SEO value through 301 permanent redirects
* Redirected terms appear in italics by default

**Content Prioritization:**
* Make announcements sticky for specific terms in any enabled taxonomy
* Sticky posts pinned at top of page 1; excluded from subsequent pages

== Installation ==

= Automatic Installation =

1. Log into your WordPress admin
2. Go to **Plugins > Add New**
3. Search for "HyperTaxonomy"
4. Click **Install Now** and then **Activate**

= Manual Installation =

1. Upload plugin files to `/wp-content/plugins/hypertaxonomy/`
2. Activate through 'Plugins' screen
3. Go to Settings → HyperTaxonomy to configure
4. Edit any tag/category to start creating relationships

= No Dependencies Required =

All core features work out of the box with no additional plugins required. The built-in Term Groups taxonomy provides group management functionality.

== Visual Indicators in Admin UI ==

The admin interface uses color-coded pills and icons to show relationship status at a glance: white pills for group membership, blue/gray pills (and gradients) for inclusions, green/gray pills for containers, plus symbols for synonyms (⇆), redirects (↩/↪), and excluded-but-included terms (strikethrough).

For the complete color and symbol reference — including custom relationship type indicators and tooltip path details — see [User Guide → Term Relationships](https://hypertaxonomy.org/hypertaxonomy/user-guide/term-relationships.html).

== Term Icons ==

Upload a custom icon for any term, with configurable dimensions and border-radius style per taxonomy. Icons appear in display shortcodes and search widget pills according to the per-taxonomy icon display setting; a colored placeholder (deterministic per term slug) can be shown when no icon is set.

```php
htax_the_term_pills( get_the_ID(), 'post_tag', array(
    'icon_size'        => 'small',
    'show_placeholder' => true,
) );
```

For upload/crop rules, file naming, and bulk actions, see [User Guide → Per-Term Settings](https://hypertaxonomy.org/hypertaxonomy/user-guide/per-term-settings.html). For the complete PHP API (placeholder colors, pill and icon functions), see [Developer Reference → PHP API](https://hypertaxonomy.org/hypertaxonomy/developer-reference/php-api.html).

== Hidden Terms ==

Mark individual terms as hidden for internal organization without public display. Enable per taxonomy in **Settings → HyperTaxonomy → Features**.

**Setting visibility:** Term edit screen (Hidden checkbox), Quick Edit inline, or **Make Hidden** / **Make Visible** bulk action. When a term is hidden, all its descendants are automatically hidden too.

**Who can see hidden terms** (Settings → HyperTaxonomy → Display → Hidden Terms):

* **Visible to editors and above only** (default) — users with `edit_posts` capability
* **Hidden from all users** — everyone excluded, including editors
* **Visible to all users** — primarily for testing

**Unauthorized users:** archives return 404, and hidden terms are treated as if they did not exist — excluded from shortcodes, search, the REST API, and post queries, so they can't be used to find posts. Posts that carry a hidden term stay discoverable through their other terms. Hidden terms are always visible in the WordPress admin.

For full behavior details, cascade rules, and known limitations with native category hierarchy, see [User Guide → Per-Term Settings](https://hypertaxonomy.org/hypertaxonomy/user-guide/per-term-settings.html).

== Search Widget Configuration ==

The HyperTaxonomy search widget builds faceted search forms from nested shortcodes — a container (`[ht_search_form]`), input fields (`[ht_pills_field]`, `[ht_checklist_field]`, `[ht_text_field]`, `[ht_hidden_field]`, `[htax_distance_field]` for geo distance filtering with [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/)), dynamic controllers (`[ht_search_controller]` / `[ht_search_option]` / `[ht_option_action]`), and an AJAX results container (`[ht_search_results]`).

**Example (POST mode):**
```
[ht_search_form state="expanded" orderby="relevance:desc,date:desc"]
    [ht_pills_field taxonomy="post_tag" label="Tags"]
    [ht_text_field name="keyword" placeholder="Search..."]
    [ht_search_submit]
[/ht_search_form]
```

For AJAX mode, geo distance filtering, dynamic controllers, and the full parameter reference for every field, see [User Guide → Search & PowerSearch](https://hypertaxonomy.org/hypertaxonomy/user-guide/search.html#advanced-search-widget).

== PowerSearch Shortcodes ==

`[ht_power_search]` renders a full-text search form using MySQL FULLTEXT indexes, with field operators (`title:`, `content:`, `tags:`, custom taxonomy slugs), required/optional/excluded modifiers (`+`, `-`), AND/OR match modes, and relevance scoring. `[ht_search_results]` provides the AJAX results container. Proximity operators (`near:`, `-near:`) are available when [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) is active. Hidden terms are excluded from results for unauthorized users.

**Example:**
```
[ht_power_search placeholder="Search posts..." button_text="Search" posts_per_page="20"]
```

For the complete query syntax, all shortcode parameters, and proximity search examples, see [User Guide → Search & PowerSearch](https://hypertaxonomy.org/hypertaxonomy/user-guide/search.html#ht_power_search--powersearch-form).

== Post List Shortcodes ==

`[ht_post_list]` displays posts for specified terms or Term Groups, with AJAX pagination, filtering, and sorting. `[ht_related_posts]` displays posts related to the current post by shared taxonomy terms. Each has a corresponding Gutenberg block. For peer site post fetching, see the `[tc_peer_posts]` shortcode in the **TermConnect** plugin.

**Examples:**
```
[ht_post_list include_terms="15,22" posts_per_page="5" orderby="date:desc"]

[ht_related_posts taxonomy="post_tag" posts_per_page="4" format="ul"]
```

For all parameters and template placeholders, see [User Guide → Post List Shortcodes](https://hypertaxonomy.org/hypertaxonomy/user-guide/post-list-shortcodes.html).

== Display Shortcodes ==

Four shortcodes for displaying taxonomy terms, each with a corresponding Gutenberg block: `[ht_term_list]` (hierarchical/flat lists), `[ht_term_index]` (alphabetical A–Z index), `[ht_term_cloud]` (tag cloud), and `[ht_term_groups]` (collapsible group tree, multiple taxonomies at once). All support Term Groups filtering, include/exclude by group or term ID, redirect handling, and custom template placeholders.

**Example:**
```
[ht_term_list taxonomy="post_tag" collapsible="collapsed" header="Tags ({term_count})"]
```

For all parameters and examples, see [User Guide → Display Shortcodes](https://hypertaxonomy.org/hypertaxonomy/user-guide/display-shortcodes.html).

== Sort Order Picker ==

= [ht_sort_order_options] - Sort Order Picker =

A pill-based sort order selector — users add, remove, drag-and-drop reorder, and toggle direction of sort criteria. Supports `date`, `modified`, `title`, `author`, `comment_count`, `last_comment` (latest comment date), `last_activity` (latest of post modified or comment), `ID`, and `relevance` fields.

```
[ht_sort_order_options fields="date,modified,title" action="navigate"]
```

The `sort_order_options` attribute on `[ht_post_list]` and search result shortcodes renders the picker inline above results. The **HT Sort Order Options** Gutenberg block is the FSE-template equivalent. For the full parameter reference, see [User Guide → Archive & Results Controls](https://hypertaxonomy.org/hypertaxonomy/user-guide/archive-controls.html).

== Frequently Asked Questions ==

= What are Term Groups? =

Term Groups is a built-in hierarchical taxonomy for organizing terms into named groups. Include an entire group in a term's archive and all current and future group members are included automatically — no maintenance as the group grows.

= Do I need additional plugins? =

No. All features — Term Groups, display shortcodes, search widget, relationship management, import/export — are built in. No external dependencies.

= Does this work with custom taxonomies? =

Yes. Enable custom taxonomies in Settings → HyperTaxonomy → Features. All features (relationships, display shortcodes, search widget, PowerSearch) support custom taxonomies via the `taxonomy` parameter.

= Does this work with caching plugins? =

Yes — WP Rocket, W3 Total Cache, WP Super Cache. HyperTaxonomy uses transients and clears them on data changes.

= Where can I find the full FAQ? =

See [FAQ & Troubleshooting](https://hypertaxonomy.org/hypertaxonomy/user-guide/faq-deactivation.html) in the User Guide for questions about how inclusions, synonyms, exclusions, redirects, and sticky posts work.

== Developer Reference ==

For the complete developer API — PHP functions, actions, filters, REST endpoints, and capabilities — see the [Developer Reference](https://hypertaxonomy.org/hypertaxonomy/developer-reference/).

== Troubleshooting ==

**Relationships seem broken:** Check for hierarchy conflicts - terms cannot include their ancestors (parent/grandparent terms)

**Redirect not working:** Ensure redirect feature is enabled for the source taxonomy and target term exists

**Sticky posts not appearing:** Verify post has the term assigned; sticky posts only appear on page 1

**Icons not displaying:** Check that term icons are enabled in settings and the uploaded image is at least as large as the configured dimensions for that taxonomy (images smaller than the configured size cannot be used; larger images trigger a crop dialog)

**Frontend styles not applying:** Check Settings → HyperTaxonomy → Display to ensure "Disable default frontend styles" is unchecked

**Search widget not loading:** Enable "Always load search widget assets" if using page builders

**Debug Mode:** To enable debug logging, add to wp-config.php:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'HTAX_DEBUG_VERBOSE', true ); // Optional: adds backtrace
```

== Compatibility ==

**Required:** WordPress 6.6+, PHP 7.4+, MySQL 5.7+ (or MariaDB 10.3+)
**Editors:** Classic Editor, Gutenberg
**Multisite:** Fully supported
**No Plugin Dependencies:** Works standalone

== Screenshots ==

1. Term Edit — inclusion/container relationships and redirect controls with color-coded pills
2. Term Groups — hierarchical group management with member term assignment
3. Term Archive — contributing terms in heading, term icon, term description, and a sticky post
4. Search widget with taxonomy pills/term icons, checklist filters and nested field groups
5. Alphabetical term list shortcode rendered on the frontend, including italicized term redirects
6. Settings → Features tab (Tags subtab), showing per-taxonomy feature toggles
7. Settings → Search tab (Categories subtab), showing relevance weight configuration
8. Post Editor top right menu expanded — custom panels for enabling editor enhancements
9. Post Editor enhancements — inclusion/container badges, redirect indicators, etc.
10. HyperTaxonomy Gutenberg block group in Post Editor's left column, showing HT Related Posts preview

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Support ==

For support, feature requests, and bug reports, visit the plugin's GitHub repository or WordPress.org support forum.

== Credits ==

* Built with WordPress coding standards
* UI inspired by WordPress native taxonomy management
* Autocomplete features use jQuery UI
* Icons from Dashicons library
