---
title: Developer Reference
nav_order: 4
has_children: true
---

# HyperTaxonomy Developer Reference

For theme developers and plugin authors who integrate with HyperTaxonomy via its PHP API, hooks, and REST endpoints.

For guidance on building or extending HyperTaxonomy itself, see the [Contributing](../contributing.html). For internal plugin architecture and implementation details, see the [Architecture](../architecture/) section.

This guide is split into the following pages:

- **[Custom Taxonomy Integration](custom-taxonomy.html)** — registering taxonomies for HyperTaxonomy, querying with inclusions, and custom relationship types.
- **[Capabilities](capabilities.html)** — the six custom capabilities and how to grant them.
- **[PHP API](php-api.html)** — the integration API, term icons API, sort/filter controls, hidden terms, and low-level relationship data.
- **[Actions & Filters](actions-filters.html)** — every action HyperTaxonomy fires and every filter it exposes.
- **[REST API](rest-api.html)** — the presence header, term and post endpoint enrichment, scored collections, and relationship-type endpoints.
- **[As the Crow Flies Integration](atcf.html)** — the built-in ATCF integration: distance field, proximity operators, location REST fields, and helper functions.
