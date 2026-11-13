---
title: Developer Reference
nav_order: 5
has_children: true
has_toc: false
---

# HyperTaxonomy Developer Reference

For theme developers and plugin authors who integrate with HyperTaxonomy via its PHP API, hooks, and REST endpoints.

For guidance on building or extending HyperTaxonomy itself, see the [Contributing](../architecture/contributing.html). For internal plugin architecture and implementation details, see the [Architecture](../architecture/) section.

The custom capabilities are described for site owners in [Roles & Permissions](../user-guide/roles-permissions.html).

This guide is split into the following pages:

- **[Custom Taxonomy Integration](custom-taxonomy.html)** — registering taxonomies for HyperTaxonomy, querying with inclusions, and custom relationship types.
- **[PHP API](php-api.html)** — the integration API, term icons API, hidden terms, and low-level relationship data.
- **[Sort & Filter Controls](sort-filter-controls.html)** — rendering the sort picker and filter panel from PHP, their HTML structures, and their JavaScript events.
- **[Actions & Filters](actions-filters.html)** — every action HyperTaxonomy fires and every filter it exposes.
- **[REST API](rest-api.html)** — the presence header, term and post endpoint enrichment, scored collections, and relationship-type endpoints.
- **[Term Meta](term-meta.html)** — HyperTaxonomy's relationships, groups, identifiers, locations and icons as read-only `get_term_meta()` keys, for GraphQL plugins, page builders and themes.
- **[As the Crow Flies Integration](atcf.html)** — the built-in ATCF integration: distance field, proximity operators, location REST fields, and helper functions.
