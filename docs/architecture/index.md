---
title: Architecture
nav_order: 5
has_children: true
---

# HyperTaxonomy Plugin Architecture

For contributors and advanced integrators who need HyperTaxonomy's internal reference: file structure, database schema, caching, hook execution, and search internals.

This reference is split into the following pages:

- **[File Structure & Loading](file-structure.html)** — directory layout, loading sequence, block build pipeline, and the dependency map.
- **[Function & Hook Reference](function-hook-reference.html)** — function organization by module, hook registration, and hook execution order.
- **[Database Schema](database-schema.html)** — term/post meta keys, options, native-table indexes, custom tables, and transient caching.
- **[Feature Implementation](feature-implementation.html)** — feature-flag enforcement, container groups, redirects, term icons, hidden terms, and conflict detection.
- **[Search Internals](search-internals.html)** — search query architecture, the search widget, performance architecture, native block group filter, AJAX endpoints, and security.
- **[Sort & Filter Rendering (DOM Structures)](sort-filter-rendering.html)** — PHP rendering functions, the emitted HTML/DOM trees, and JavaScript events for the sort order picker and filter options panel.
