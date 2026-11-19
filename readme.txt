=== HyperTaxonomy – Semantic Term Relationships ===
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

For cross-site post fetching, RSS/Atom feed aggregation, and external identifiers per term, see the companion plugin [TermConnect](https://hypertaxonomy.org/termconnect/).

For a detailed walk-through of the concepts behind HyperTaxonomy — why WordPress taxonomies fall short, how the relationship model is designed, and when to use each feature — see the [User Guide](https://hypertaxonomy.org/hypertaxonomy/user-guide/).

= Core Features =

**Relationship Management**
* **Term Groups** — Built-in group taxonomy for term organization and bulk inclusion
* **Includes** — Tag and category archives can include posts with related terms
* **Excludes** — Override inclusion for select terms without changing group composition
* **Synonyms** — Two terms that include each other, directly or through groups, show each other's posts in their archives
* **Relationship Types** — Define additional relationship types to express semantic connections between terms

**Term Management**
* **Redirects** — Seamlessly redirect terms with 301 status and automatic chain resolution to preferred terms
* **Merge Terms** — Permanently consolidate duplicate or outdated terms, transferring all posts and relationships to the target term with optional 301 redirect stubs
* **Taxonomy Conversion** — Move terms between taxonomies; the original term optionally stays as a 301 redirect stub in the old taxonomy, keeping old archive URLs working and the conversion reversible

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

Post lists, term lists, tag clouds, A–Z indexes, PowerSearch and sort-order controls are available as shortcodes and as Gutenberg blocks with a live editor preview. The Advanced Search Widget is a composed system of nested shortcodes; it has no single block equivalent and must be built in markup or a text block. Shortcode syntax and parameters: [Search Widget](https://hypertaxonomy.org/hypertaxonomy/user-guide/search-widget.html), [PowerSearch](https://hypertaxonomy.org/hypertaxonomy/user-guide/powersearch.html), [Post Lists](https://hypertaxonomy.org/hypertaxonomy/user-guide/post-lists.html), [Term Lists & Clouds](https://hypertaxonomy.org/hypertaxonomy/user-guide/term-lists.html) and [Archive & Results Controls](https://hypertaxonomy.org/hypertaxonomy/user-guide/archive-controls.html).

**Native Block Extensions**
* **Query Loop — HT Term Group Filter** — Adds a Term Group filter panel to every core Query Loop block; select one or more groups to restrict results to posts with matching terms (OR logic, subgroup-inclusive, taxonomy auto-detected from post type)
* **Latest Posts — HT Term Group Filter** — Same Term Group filter panel on every core Latest Posts block; filters the block's post list to terms from the selected groups

**Admin Interface**
* **Term Inclusion Relationship Editor** — Intuitive interface with color-coded pill-based term selection
* **Synonyms Page** — Terms → Synonyms lists every synonym pair, including pairs formed through group inclusions and container groups, explains how each pair formed, flags pairs whose archives differ, and links to a pre-filled Merge Terms page
* **Enhanced Term Lists** — Term lists show icon, visibility status, and redirect status with filters; Redirect, Icon, Visibility, and Term Groups columns are sortable so you can instantly see all redirect terms or all hidden terms for a taxonomy
* **Term Groups Admin** — Hierarchical group management with member term assignment
* **Bulk Actions** — Context-aware bulk actions for visibility, icons, and group assignment; the group assignment modal uses three-state checkboxes (add / remove / no change) so mixed memberships across selected terms are handled non-destructively
* **Post Editor Indicators** — Per-user toggles in the block editor's ··· menu show contextual information for each assigned term without leaving the editor: Inclusion Badges (⇉N), Container Badges (⇇N), Redirect Indicators (italics + ↪), Hidden Terms (faded), and Sticky Pushpins (📌 toggle). Each toggle is independent and persists per user.
* **Content-Based Tag Suggestions** — Replaces WordPress's "most used tags" suggestions in the Gutenberg editor with tags matched to the current post's content; suggestions are ranked by how prominently each tag appears in the title, excerpt, and body, with an optional ignore-words list
* **Tag Creation Control** — Optionally prevent accidental creation of new tags in native post editor
* **Reliable Tags Field** — Block editor tags aren't lost when entered during a slow server check or before the post's tags load
* **Concurrent Edit Locking** — Heartbeat-based lock with take-over flow prevents two admins from editing the same term or relationship type simultaneously (can be disabled in Advanced settings if it conflicts with another plugin); navigating away with unsaved changes triggers a browser confirmation prompt
* **Tabbed Settings** — Control cache expiration, permitted AJAX request frequency, frontend styles, and more

**Developer Tools**
* **PHP API, hooks & REST extensions** — Helper functions, actions/filters, an integration API for expansion-aware queries, and HyperTaxonomy-specific fields on the WordPress REST API. Relationships, groups, pinned posts, identifiers, locations and icons are also readable as term meta, for GraphQL plugins and page builders, and optionally in REST responses. Full reference in the [Developer Reference](https://hypertaxonomy.org/hypertaxonomy/developer-reference/).
* **Debug Mode** — Add ?htax_debug=1 to any archive URL (admin only). Additional internal diagnostics in the output require `WP_DEBUG` to be enabled.

= How It Works =

**Relationship Types:**
1. **Inclusion** — Term A includes Term B (equivalently: Term B specifies Term A as a container) → Posts with Term B appear in Term A's archive. The relationship can be defined from either direction and is stored as a single record.
2. **Group Inclusion** — Term A includes Term Group G → Posts with any member term of G in A's taxonomy appear in Term A's archive
3. **Container Groups** — Term B specifies Term Group H as one of its container groups → Every member of H in B's taxonomy includes Term B (reverse inclusion), so posts with Term B appear in each member's archive
4. **Bidirectional Inclusion (Synonyms)** — Term A includes Term B and Term B includes Term A → Posts with either term appear in both archives
5. **Redirects** — Term A redirects to Term B → Visiting Term A's archive redirects to Term B's archive with 301 status
6. **Hidden Terms** — Terms that exist in the taxonomy for organizational purposes, but are shown only to users who can edit posts
7. **Custom Relationship Types** — Two kinds: *Inclusion-based* types participate in archive expansion and search indexing automatically (same taxonomy required for source and target, groups expanded to their members); *Custom* types store the relationship only and leave behavior to your theme or plugin

**Non-transitive by design:** Inclusion relationships do not chain — if A includes B and B includes C, A does not automatically include C. Each inclusion is explicit and intentional. Use native category hierarchy where cascading inclusion is appropriate.

**Taxonomy-Specific Constraints:**
* **Term relationships controlled per taxonomy** — All relationship features (inclusion/container relationships, group-based relationships, custom relationship types) are enabled or disabled together per taxonomy in Settings → HyperTaxonomy → Features.
* **Archive customization controlled per taxonomy** — Sticky posts, per-term posts-per-page, and per-term sort order are enabled or disabled together per taxonomy. When disabled, existing settings are preserved and restored on re-enable.
* **Ancestor-descendant relationships are excluded** — For hierarchical taxonomies, HyperTaxonomy inclusion and container relationships are restricted to cross-branch terms. Ancestors and descendants (parent, child, grandparent, etc.) are excluded from both the included set and the container set: descendants' posts already appear in a term's archive through the WordPress native hierarchy, and including an ancestor would result in an infinite loop. They are also disregarded in group inclusions and container groups. Terms that share a common ancestor but are not directly related (e.g. siblings) may freely have HyperTaxonomy inclusion or container relationships.
* **Category hierarchy expanded** — When a parent category is included (directly or via groups), all its subcategories are automatically included in searches and archives.

**Redirect Behavior:**
* **Archive redirect** — Visiting a redirect's archive URL (e.g., /tag/ml/) redirects to its target (e.g., /tag/machine-learning/). The redirect term has no archive of its own.
* **Independent from relationships** — Redirect target can be any other term, in the same or another taxonomy. Inclusion and container relationships are independent of redirect status: the target's archive shows the redirecting term's posts only if the target includes that term.
* **No redirect chains** — If Tag A redirects to Tag B, and Tag B is later updated to redirect to Tag C, Tag A automatically updates to redirect directly to Tag C.
* **No circular redirects** — If A redirects to B and you then redirect B to A, A's redirect is removed so the new one can be saved, and a notice says so.
* **Search expansion** — A search for a term also finds posts tagged with terms that redirect to it (on by default; can be turned off in the search settings).

= Quick Start Examples =

**Content Organization:**
* "Fruits" includes "Apples", "Bananas" and "Grapes"; "Lunch Items" includes "Apples", "Pancakes" and "Yogurt"
* A post tagged "Apples" appears in both archives, with no extra tags

**Group-Based Inclusion:**
* "Fruits" includes the "Berries" group, so posts with any berry tag appear in the Fruits archive
* Add a "Store-Wide Sale" tag to the "Brands" container group, and its posts appear in every brand's archive
* Parent groups automatically expand to their child groups

**Exclusions (Fine-Grained Control):**
* "JavaScript" includes the "Frameworks" group but excludes the "Legacy" term, for modern frameworks only
* Exclusions always win: no inclusion, including from a container group, can override a direct exclusion

**Bidirectional Inclusion (Synonyms):**
* Add "Tangerine" and "Clementine" to each other's included terms, and a post with either tag appears in both archives
* Both terms show each other in the Included Tags section, marked with ⇆
* Synonyms share posts, not relationships: if Tangerine also includes Mandarin, only the Tangerine archive shows Mandarin posts

**Content Consolidation:**
* Redirect an outdated term to its replacement (/tag/old-tag/ → /tag/new-tag/) with a 301, preserving SEO value
* Redirecting terms appear in italics by default

**Content Prioritization:**
* Pin announcements to the top of page 1 of any term's archive

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

= Dependencies =

All core features work out of the box with no additional plugins required. The Term Groups taxonomy, supplied by this plugin, provides group management. The only exceptions are the geographic search features — PowerSearch's `near:` operators and the search widget's Geo Distance Filter — which need the free [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) plugin for term locations; without it they are simply not offered.

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

For upload/crop rules, file naming, and bulk actions, see [User Guide → Per-Term Settings](https://hypertaxonomy.org/hypertaxonomy/user-guide/per-term-settings.html). For the icon and pill functions (placeholder colors included), see [Developer Reference → Term Icons & Badges](https://hypertaxonomy.org/hypertaxonomy/developer-reference/term-icons.html).

== Frequently Asked Questions ==

= What are Term Groups? =

Term Groups is a hierarchical taxonomy supplied by this plugin for organizing terms into named groups. Include an entire group in a term's archive and all current and future group members are included automatically — no maintenance as the group grows. See [User Guide → Term Groups](https://hypertaxonomy.org/hypertaxonomy/user-guide/term-groups.html).

= Do I need additional plugins? =

No, with one exception. Term Groups, display shortcodes, the search widget, relationship management and import/export are all built in. The geographic search features (PowerSearch's `near:` operators and the Geo Distance Filter) need the free [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) plugin, which supplies term locations; without it those features are hidden and everything else works.

= Does this work with custom taxonomies? =

Yes. Enable custom taxonomies in Settings → HyperTaxonomy → Features. Relationships, term lists, post lists and the search widget's fields then work with them (shortcodes and blocks take a `taxonomy` parameter), and PowerSearch gets operators for each enabled taxonomy, named after its labels.

= Does this work with caching plugins? =

HyperTaxonomy keeps its own data in transients and clears them when data changes. It has no special integration with page caching plugins, so a cached page can keep showing an archive or list after you change a relationship: purge the page cache after making changes. POST-mode search results pages can be cached, since their URLs hold the search.

= How do hidden terms work? =

Mark a term as hidden to use it for internal organization without showing it publicly. Enable this per taxonomy in **Settings → HyperTaxonomy → Features**, then hide a term on its edit screen (Hidden checkbox), in Quick Edit, or with the **Make Hidden** / **Make Visible** bulk actions. Hiding a term also hides all its descendants.

Who can see hidden terms is set in **Settings → HyperTaxonomy → Display → Hidden Terms**:

* **Visible only to users who can edit posts** (default) — users with the `edit_posts` capability: Contributors, Authors, Editors and Administrators
* **Hidden from all users** — everyone excluded, including editors
* **Visible to all users** — primarily for testing

For everyone else, a hidden term's archive returns 404, and the term is treated as if it didn't exist: it's left out of shortcodes, search, the REST API and post queries, so it can't be used to find posts. Posts that carry a hidden term stay discoverable through their other terms. Hidden terms are always visible in the WordPress admin.

Some places leave hidden terms out for everyone, whatever the setting: term lists and clouds, search option lists and filter panels, and the related terms HyperTaxonomy shares with other tools (REST relationship fields, and term meta for GraphQL plugins and page builders). For cascade rules and known limitations with native category hierarchy, see [User Guide → Per-Term Settings](https://hypertaxonomy.org/hypertaxonomy/user-guide/per-term-settings.html).

= Relationships seem broken. What should I check? =

Check that **Term Relationships** is enabled for the taxonomy in Settings → HyperTaxonomy → Features, and look for hierarchy conflicts: in a hierarchical taxonomy, a term can't include its own ancestors or descendants.

= Why isn't a redirect working? =

Make sure the Redirects feature is enabled for the source term's taxonomy, and that the target term exists.

= Why aren't sticky posts appearing? =

Sticky posts appear only on page 1 of the term's archive. Check that **Show sticky posts on term archive pages** is on (Settings → HyperTaxonomy → Display), that the post is published, still has the term assigned directly and is of an indexed post type, that the term isn't a redirect, and that the term's sticky-post limit isn't already reached.

= Why aren't term icons displaying? =

Check that term icons are enabled for the taxonomy, and that the uploaded image is at least as large as the dimensions configured for it. Smaller images can't be used; larger ones open a crop dialog.

= Why aren't the frontend styles applied? =

In **Settings → HyperTaxonomy → Display**, make sure **Disable default frontend styles** is unchecked.

= Why doesn't the search widget load? =

If you build pages with a page builder, turn on **Always load search assets** in **Settings → HyperTaxonomy → Advanced**.

= An archive shows terms I didn't expect. How do I see why? =

As an administrator, add `?htax_debug=1` to the archive URL. A debug panel lists the terms the archive includes, the resulting taxonomy query, the number of posts found, and which terms contributed the posts on the page; with `WP_DEBUG` on, a second panel shows the SQL query.

= How do I turn on debug logging? =

Add this to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'HTAX_DEBUG_VERBOSE', true ); // Optional: adds a backtrace
```

= Where can I find the full FAQ? =

See [FAQ & Troubleshooting](https://hypertaxonomy.org/hypertaxonomy/user-guide/faq.html) in the User Guide for questions about how inclusions, synonyms, exclusions, redirects, and sticky posts work.

== Developer Reference ==

For the complete developer API — PHP functions, actions, filters, REST endpoints, and capabilities — see the [Developer Reference](https://hypertaxonomy.org/hypertaxonomy/developer-reference/).

== Compatibility ==

**Required:** WordPress 6.6+, PHP 7.4+, MySQL 5.7+ (or MariaDB 10.3+)
**Editors:** Classic Editor, Gutenberg
**Multisite:** Fully supported
**Plugin Dependencies:** None required; geographic search features need [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/)

== Screenshots ==

1. Term Edit — the Term Relationships panel: container, included and excluded tags, the tags included through groups, tabs for custom relationship types, and the redirect option
2. Term Archive — contributing terms in heading, term icon, term description, and a sticky post
3. Post Editor enhancements — inclusion/container badges, sticky pushpins, redirect indicators and faded hidden terms
4. Editing a term group — its parent group, visibility, and member tags
5. Terms → Synonyms — pairs of terms that include each other, with every inclusion path (direct, custom type, through nested groups and container groups), how their archives differ, and links to merge them or edit each relationship
6. Tags list — the Groups, Redirect, Icon and Visibility columns HyperTaxonomy adds, with group, icon and visibility filters
7. Search widget with taxonomy pills/term icons, checklist filters and nested field groups
8. HyperTaxonomy blocks in the post editor — an HT Related Posts block with its settings, including the date window, and its live preview
9. Alphabetical term list shortcode rendered on the frontend, including italicized term redirects
10. Post Editor options menu — the panels for turning HyperTaxonomy's editor enhancements on and off
11. Settings → Features tab (Tags subtab), showing per-taxonomy feature toggles
12. Settings → Search tab (Categories subtab), showing relevance weight configuration

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
