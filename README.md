# HyperTaxonomy

*Structure the unstructured*

Semantic term relationships for WordPress: inclusions, synonyms, redirects, term groups, rich archives, and faceted search — define once, propagates everywhere.

Term inclusions and synonyms mean a post tagged "React" automatically surfaces in archives for "JSX", "hooks", and any other term that includes it — without re-tagging. A denormalized search index keeps archives and search fast. The same relationships govern archive queries, the related posts shortcode, the search widget, and the REST API.

For cross-site content — fetching related posts from peer WordPress sites, RSS/Atom feeds, and external knowledge-base link types (Wikidata, GeoNames, etc.) — see the companion plugin [TermConnect](https://hypertaxonomy.org/termconnect/), which extends HyperTaxonomy's term vocabulary across the web.

## Requirements

| Item | Version |
|------|---------|
| WordPress | 6.6+ |
| PHP | 7.4+ |
| MySQL | 5.7+ (MariaDB 10.3+) |

## Features

- **Term Groups** — Organize terms into hierarchical groups (built-in, no external plugin required)
- **Term Inclusions** — Include another term's posts in an archive; bidirectional inclusions act as synonyms
- **Term Relationships** — Semantic connections between terms, optionally mapped to core inclusion
- **Term Redirects** — 301 redirects with automatic chain collapsing and circular detection
- **Term Icons** — Media library icons with colored icon-shaped placeholders; SVG support
- **Search Widget** — Modular form builder (pills, checklists, text fields, geo distance filter) with AJAX/POST modes; `[ht_distance_field]` adds postal code and geolocation filtering when [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) is active
- **PowerSearch** — Field operators (`title:`, `tags:`, etc.), required, optional, and excluded term modifiers, any/all match modes, configurable scope; proximity operator (`near:`, with `-near:` to exclude) when [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) is active
- **Content-Based Tag Suggestions** — Gutenberg editor suggests unassigned tags based on post content, ranked by prominence
- **Sticky Posts** — Pin posts to specific term archives
- **Per-Term Archive Settings** — Set a description, pagination, and default sort order for each term's archive
- **Archive Sort & Filter Controls** — Let visitors re-sort and filter posts on archive pages
- **Hidden Terms** — Role-based term visibility with full index integration
- **Merge Terms** — Consolidate duplicate terms, transferring posts and relationships with optional 301 redirect stubs
- **Taxonomy Conversion** — Move terms between taxonomies with optional 301 redirect stubs to keep old archive URLs working
- **Display Shortcodes & Blocks** — Term lists, tag clouds, alphabetical indexes, and group hierarchies for any taxonomy
- **Post List Shortcodes & Blocks** — Display filtered post lists and related posts using shortcodes or Gutenberg blocks
- **Native Block Extensions** — A Term Group filter on the core Query Loop and Latest Posts blocks
- **External Posts** — Fetch related posts from peer WordPress sites and RSS/Atom feeds via **TermConnect** (`[tc_peer_posts]`)
- **Integration API** — Automatic inclusion expansion for third-party queries, REST endpoints and extra term fields in standard REST responses, and relationships, groups, pinned posts, identifiers, locations and icons readable as term meta for GraphQL plugins and page builders
- **Import / Export** — JSON-based data migration; Tag Groups migrator included

## Documentation

The full documentation is published as a [Just-the-Docs](https://just-the-docs.com/) site (source under [`docs/`](docs/)):

- [Why HyperTaxonomy](docs/why-hypertaxonomy.md) — The problem with tags and category trees, and how one term can belong to many structures
- [User Guide](docs/user-guide/) — Settings, roles, import/migration, concepts, relationships, display and search shortcodes, the post editor, and modeling guidance
- [Developer Reference](docs/developer-reference/) — custom taxonomy integration, PHP API, actions/filters, REST API, term meta for GraphQL plugins and page builders, sort and filter controls, and the As the Crow Flies integration, for theme/plugin developers
- [Architecture Reference](docs/architecture/) — Internal architecture, data storage, caching, query design, hook execution, performance
- [Contributing](docs/architecture/contributing.md) — Design principles, coding standards, implementation patterns, block builds, error handling, and debug tools for HyperTaxonomy contributors

## Translations

HyperTaxonomy is translation-ready and ships with a complete translation template: `languages/hypertaxonomy.pot`.

To contribute a translation: copy the template to `languages/hypertaxonomy-<locale>.po`, translate it, and open a pull request — the build compiles it (and, for block-editor strings, generates the required JSON) automatically. See [`utils/make-pot.sh`](utils/make-pot.sh) and [`utils/make-json.py`](utils/make-json.py).

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
