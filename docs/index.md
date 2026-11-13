---
title: Home
nav_order: 1
---

# HyperTaxonomy

*Structure the unstructured*
{: .fs-6 .fw-300 }

Semantic term relationships for WordPress: inclusions, synonyms, redirects, term groups, rich archives, and faceted search — define a relationship once, and it propagates everywhere. Term inclusions and synonyms mean a post tagged "React" automatically surfaces in archives for "JSX", "hooks", and any other term that includes it, without re-tagging. A denormalized search index keeps archives and search fast, and the same relationships govern archive queries, the related-posts shortcode, the search widget, and the REST API.

Part of the [HyperTaxonomy Suite](https://hypertaxonomy.org) of interoperable WordPress taxonomy plugins, and its core.

## Features

HyperTaxonomy adds term organization, relationships, and relevance-scored search to any WordPress taxonomy — all built in, with no external plugin required.

- **Term Groups** — Organize terms into hierarchical groups.
- **Term Inclusions** — Include another term's posts in an archive; bidirectional inclusions act as synonyms.
- **Term Relationships** — Semantic connections between terms, optionally mapped to core inclusion.
- **Term Redirects** — 301 redirects with automatic chain collapsing and circular-reference detection.
- **Term Icons** — Media-library icons with colored icon-shaped placeholders; SVG support.
- **Search Widget** — Modular form builder (pills, checklists, text fields, geo distance filter) with AJAX/POST modes; `[ht_distance_field]` adds postal-code and geolocation filtering when [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) is active.
- **PowerSearch** — Field operators (`title:`, `tags:`, …), required, optional, and excluded term modifiers, any/all match modes, and configurable scope; proximity operator (`near:`, with `-near:` to exclude) when As the Crow Flies is active.
- **Content-Based Tag Suggestions** — The block editor suggests unassigned tags based on post content, ranked by prominence.
- **Sticky Posts** — Pin posts to specific term archives.
- **Per-Term Archive Settings** — Set a description, pagination, and default sort order for each term's archive.
- **Archive Sort & Filter Controls** — Let visitors re-sort and filter posts on archive pages.
- **Hidden Terms** — Role-based term visibility, fully integrated with the search index.
- **Merge Terms** — Consolidate duplicate terms, transferring posts and relationships with optional 301 redirect stubs.
- **Taxonomy Conversion** — Move terms between taxonomies with optional 301 redirect stubs so old archive URLs keep working.
- **Display Shortcodes & Blocks** — Term lists, tag clouds, alphabetical indexes, and group hierarchies for any taxonomy.
- **Post List Shortcodes & Blocks** — Filtered post lists and related posts via shortcodes or Gutenberg blocks.
- **Native Block Extensions** — A Term Group filter on the core Query Loop and Latest Posts blocks.
- **External Posts** — Fetch related posts from peer WordPress sites and RSS/Atom feeds via [TermConnect](https://hypertaxonomy.org/termconnect/) (`[tc_peer_posts]`).
- **Integration API** — Automatic inclusion expansion for third-party queries, plus REST endpoints and extra term fields in standard REST responses.
- **Import / Export** — JSON-based data migration, with a Tag Groups migrator included.

## Coming from Tag Groups?

Tag Groups (rebranded "PublishPress Tag Groups" in October 2026) is a popular plugin for taxonomy term organization. For a side-by-side breakdown of how HyperTaxonomy compares — including what it adds beyond grouping — see **[Comparing to Tag Groups](comparison.html)**.

Switching away from Tag Groups and keeping it alongside HyperTaxonomy are both supported: the importer can migrate your group structure once, or sync it continuously while Tag Groups stays your group-management tool. See [Import & Migration](user-guide/import-migration.html).

## Documentation map

- **[Why HyperTaxonomy](why-hypertaxonomy.html)** — the problem with tags and category trees, and how one term can belong to many structures.
- **[Getting Started](getting-started.html)** — requirements, installation, and first steps.
- **[User Guide](user-guide/)** — for site admins: settings, roles, import and migration, concepts, relationships, per-term settings, display and search shortcodes, the post editor, and modeling guidance.
- **[Developer Reference](developer-reference/)** — for theme and plugin developers: custom taxonomy integration, the PHP API, sort and filter controls, actions, filters, REST endpoints, and the As the Crow Flies integration.
- **[Architecture](architecture/)** — for contributors: file structure, functions, hooks, data storage, feature implementation, search internals, and contributing guidelines.
- **[Feature Comparison](comparison.html)** — a feature-by-feature comparison of HyperTaxonomy with the PublishPress Tag Groups plugin.

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
