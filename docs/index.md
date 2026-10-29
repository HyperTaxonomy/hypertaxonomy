---
title: Home
nav_order: 1
---

# HyperTaxonomy

*Structure the unstructured*
{: .fs-6 .fw-300 }

Semantic term relationships for WordPress: inclusions, synonyms, redirects, term groups, rich archives, and faceted search — define a relationship once, and it propagates everywhere. Term inclusions and synonyms mean a post tagged "React" automatically surfaces in archives for "JSX", "hooks", and any other included term, without re-tagging. A denormalized search index keeps archives and search fast regardless of relationship depth, and the same relationships govern archive queries, the related-posts shortcode, the search widget, and the REST API.

## Part of the HyperTaxonomy suite

One of five interoperable WordPress plugins for richer, connected taxonomies. Each works on its own; together they share a common term model. Learn more at [hypertaxonomy.org](https://hypertaxonomy.org).

- **HyperTaxonomy** (this plugin) — the core: term groups, inclusions, synonyms, redirects, icons, relevance-scored search, and enhanced archives.
- **[TermConnect](https://hypertaxonomy.org/termconnect/)** — link terms to knowledge-base identifiers (Wikidata, GeoNames, LCSH…) and peer sites; delegated identity, shared vocabularies, and cross-site related content.
- **[Social Hashtags & Mentions](https://hypertaxonomy.org/social-hashtags-mentions/)** — inline #hashtags and @mentions as taxonomy terms, linked to the social networks you enable (built on TermConnect).
- **[As the Crow Flies](https://hypertaxonomy.org/as-the-crow-flies/)** — attach physical locations to terms and filter content by distance/proximity.
- **[WP-SKOS](https://hypertaxonomy.org/wp-skos/)** — expose your taxonomies as SKOS linked data via a Skosmos-compatible REST API.

## Documentation map

- **[Features](features.html)** — what HyperTaxonomy does, at a glance.
- **[User Guide](user-guide/)** — for site admins: features, configuration, shortcodes, search, import/migration, and a best-practices modeling guide.
- **[Developer Reference](developer-reference/)** — for theme and plugin developers: the PHP API, actions, filters, REST endpoints, capabilities, and the As the Crow Flies integration.
- **[Architecture](architecture/)** — for contributors: file structure, database schema, caching, hook execution order, search internals, and conflict detection.
- **[Contributing](contributing.html)** — for HyperTaxonomy contributors: design principles, coding standards, building block assets, and debug tooling.
- **[HyperTaxonomy vs. Tag Groups](comparison.html)** — a feature-by-feature comparison of HyperTaxonomy with the PublishPress Tag Groups plugin.

## Requirements

| Item | Version |
|------|---------|
| WordPress | 5.9+ |
| PHP | 7.4+ |
| MySQL | 5.7+ (MariaDB 10.3+) |

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
