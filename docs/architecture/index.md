---
title: Architecture
nav_order: 7
has_children: true
has_toc: false
---

# HyperTaxonomy Plugin Architecture

For contributors and advanced integrators who need HyperTaxonomy's internal reference: file structure, functions and hooks, data storage, caching, how features and search are built, and contributing guidelines.

This reference is split into the following pages:

- **[File Structure & Loading](file-structure.html)** — directory layout, loading sequence, block build pipeline, and the dependency map.
- **[Function Reference](function-reference.html)** — the public API and the functions called across files, by module.
- **[Hook Reference](hook-reference.html)** — where hooks are registered, and their execution order where it matters.
- **[Data Storage](data-storage.html)** — term/post meta keys, options, native-table indexes, custom tables, and transients that hold state.
- **[Caching](caching.html)** — the cache layers, how transients are stored under an object cache, every cache and what clears it, and the post list cache.
- **[Feature Internals](feature-internals.html)** — how individual features are built: feature-flag enforcement, group relationships, native block group filter, redirects, display depth, term icons, hidden terms, term conversion, term merge, and conflict detection.
- **[Search Internals](search-internals.html)** — search query architecture, the search widget and its fields, PowerSearch scoring, results pages, index management, strength models, and performance.
- **[Contributing](contributing.html)** — design principles, coding standards, implementation patterns, block builds, error handling, and debug tools.
