---
title: Developer Reference
nav_order: 6
has_children: true
has_toc: false
---

# HyperTaxonomy Developer Reference

For theme developers and plugin authors who integrate with HyperTaxonomy via its PHP API, hooks, and REST endpoints.

For guidance on building or extending HyperTaxonomy itself, see [Contributing](../architecture/contributing.html). For internal plugin architecture and implementation details, see the [Architecture](../architecture/) section.

The custom capabilities are described for site owners in [Roles & Permissions](../user-guide/roles-permissions.html).

This guide is split into the following pages:

- **[Custom Taxonomy Integration](custom-taxonomy.html)** — registering taxonomies for HyperTaxonomy, querying with inclusions, and custom relationship types.
- **[Query Expansion API](query-expansion.html)** — a term's expanded term IDs and `tax_query`, expanding secondary queries, expansion-aware term counts, and reacting to archive changes.
- **[Relationship Data API](relationship-data.html)** — reading a term's inclusions, containers, synonyms and redirects, term group membership, and sticky posts.
- **[Handling Hidden Terms](hidden-terms.html)** — what your code sees for hidden terms, and how to respect them in custom queries and the search index.
- **[Term Icons & Badges](term-icons.html)** — archive header icons, icon URLs and HTML, term pills, and the relationship badges in the post editor.
- **[Search Ranking & Notices](search-ranking.html)** — supplying term strengths from your own strength model, and showing visitors notices about how a search was run.
- **[Cross-Plugin Data](cross-plugin-data.html)** — supplying term URIs, locations, external posts and peer sites to other plugins on the same site, and local post scores.
- **[Actions & Filters](actions-filters.html)** — every action HyperTaxonomy fires and every filter it exposes.
- **[REST API](rest-api.html)** — the presence header, term and post endpoint enrichment, scored collections, and relationship-type endpoints.
- **[Term Meta](term-meta.html)** — HyperTaxonomy's relationships, groups, pinned posts, identifiers, locations and icons as read-only `get_term_meta()` keys, for GraphQL plugins, page builders and themes.
- **[Sort & Filter Controls](sort-filter-controls.html)** — rendering the sort picker and filter panel from PHP, their HTML structures, and their JavaScript events.
- **[Locations & Proximity Search](locations-proximity.html)** — the built-in As the Crow Flies integration: distance field, proximity operators, location REST fields, and helper functions.
