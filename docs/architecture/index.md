---
title: Architecture
nav_order: 5
has_children: true
---

# HyperTaxonomy Plugin Architecture

For contributors and advanced integrators who need HyperTaxonomy's internal reference: file structure, data storage, caching, hook execution, search internals, and contributing guidelines.

This reference is split into the following pages:

- **[File Structure & Loading](file-structure.html)** — directory layout, loading sequence, block build pipeline, and the dependency map.
- **[Function Reference](function-reference.html)** — functions by module.
- **[Hook Reference](hook-reference.html)** — hook registration and execution order.
- **[Data Storage](data-storage.html)** — term/post meta keys, options, native-table indexes, custom tables, and transient caching.
- **[Feature Implementation](feature-implementation.html)** — feature-flag enforcement, container groups, redirects, display depth, term icons, hidden terms, term conversion, term merge, and conflict detection.
- **[Search Internals](search-internals.html)** — search query architecture, the search widget and its fields, PowerSearch scoring, index management, strength models, performance architecture, native block group filter, AJAX endpoints, and security.
- **[Contributing](contributing.html)** — design principles, coding standards, implementation patterns, block builds, error handling, and debug tools.
