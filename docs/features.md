---
title: Features
nav_order: 2
---

# Features

HyperTaxonomy adds term organization, relationships, and relevance-scored search to any WordPress taxonomy — all built in, with no external plugin required.

- **Term Groups** — Organize terms into hierarchical groups (built-in, no external plugin required).
- **Term Inclusions** — Include another term's posts in an archive; bidirectional inclusions act as synonyms.
- **Term Relationships** — Semantic connections between terms, optionally mapped to core inclusion.
- **Term Redirects** — 301 redirects with automatic chain collapsing and circular-reference detection.
- **Term Icons** — Media-library icons with colored icon-shaped placeholders; SVG support.
- **Search Widget** — Modular form builder (pills, checklists, text fields, geo distance filter) with AJAX/POST modes; `[htax_distance_field]` adds postal-code and geolocation filtering when [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) is active.
- **PowerSearch** — Field operators (`title:`, `tags:`, …), boolean operators, and configurable scope; proximity operator (`near:`, with `-near:` to exclude) when As the Crow Flies is active.
- **Content-Based Tag Suggestions** — The block editor suggests unassigned tags based on post content, ranked by prominence.
- **Sticky Posts** — Pin posts to specific term archives.
- **Hidden Terms** — Role-based term visibility, fully integrated with the search index.
- **Merge Terms** — Consolidate duplicate terms, transferring posts and relationships with optional 301 redirect stubs.
- **Taxonomy Conversion** — Move terms between taxonomies with optional 301 redirect stubs so old archive URLs keep working.
- **Display Shortcodes & Blocks** — Term lists, tag clouds, alphabetical indexes, and group hierarchies for any taxonomy.
- **Post List Shortcodes & Blocks** — Filtered post lists and related posts via shortcodes or Gutenberg blocks.
- **External Posts** — Fetch related posts from peer WordPress sites and RSS/Atom feeds via [TermConnect](https://hypertaxonomy.org/termconnect/) (`[tc_peer_posts]`).
- **Integration API** — Automatic inclusion expansion for third-party queries, plus a REST endpoint for programmatic access.
- **Import / Export** — JSON-based data migration, with a Tag Groups migrator included.

## Coming from Tag Groups?

Tag Groups (rebranded "PublishPress Tag Groups" in October 2026) is the plugin most commonly used for taxonomy term organization. For a side-by-side breakdown of how HyperTaxonomy compares — including what it adds beyond grouping — see **[HyperTaxonomy vs. PublishPress Tag Groups](comparison.html)**.

Switching away from Tag Groups and keeping it alongside HyperTaxonomy are both supported: the importer can migrate your group structure once, or sync it continuously while Tag Groups stays your group-management tool. See [Import & Migration](user-guide/import-migration.html).
