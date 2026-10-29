---
title: File Structure & Loading
parent: Architecture
nav_order: 1
---

# File Structure & Loading

## File Structure

```
hypertaxonomy/
├── hypertaxonomy.php              # Main plugin file
├── package.json                   # npm dependencies (@wordpress/scripts)
├── README.md                      # Entry point with feature list and doc links
├── readme.txt                     # WordPress.org readme
├── uninstall.php                  # Uninstall handler
├── webpack.config.js              # Multi-entry webpack config (one entry per block)
│
├── admin/                         # Admin assets
│   ├── css/
│   │   ├── htax-hierarchical-checklist.css  # Hierarchical checklist UI component styles
│   │   ├── htax-post-edit.css          # Post edit screen styles
│   │   ├── htax-settings.css           # Settings page styles
│   │   ├── htax-tables.css             # List table styles
│   │   └── htax-term-edit.css          # Term edit screen styles
│   └── js/
│       ├── htax-group-admin.js         # Term Groups admin UI logic
│       ├── htax-hierarchical-checklist.js  # Hierarchical checklist UI component
│       ├── htax-post-edit.js           # Post edit screen logic
│       ├── htax-settings.js            # Settings page logic
│       ├── htax-tables.js              # List table filter logic, Screen Options sync, bulk group assignment
│       └── htax-term-edit.js           # Term edit screen logic
│
├── assets/                        # Frontend assets
│   ├── css/
│   │   ├── htax-filter-options.css     # Filter options panel styles
│   │   ├── htax-search.css             # PowerSearch result styles
│   │   ├── htax-search-widget.css      # Advanced search widget styles
│   │   ├── htax-shortcodes.css         # Shared shortcode/block output styles
│   │   ├── htax-sort-order.css         # Sort order picker component styles
│   │   └── htax-term-icons.css         # Term icon styles
│   └── js/
│       ├── htax-filter-options.js      # Filter options panel logic
│       ├── htax-power-search.js        # PowerSearch AJAX + sort-change listener
│       ├── htax-post-list.js           # Post list AJAX pagination + sort-change listener
│       ├── htax-search-widget.js       # Advanced search widget AJAX logic
│       ├── htax-sort-order.js          # Sort order picker component (pill UI, drag-and-drop, collapse)
│       └── htax-term-icons.js          # Term icon upload, crop dialog
│
├── docs/                          # Technical documentation (Just-the-Docs / Jekyll site)
│   ├── _config.yml                     # Jekyll / Just-the-Docs configuration
│   ├── index.md                        # Docs home / landing page
│   ├── user-guide/                     # User Guide section (parent + child pages)
│   ├── developer-reference/              # Developer Reference section (PHP API, hooks, REST, capabilities)
│   ├── architecture/                   # Architecture section (this reference, split into child pages)
│   └── contributing.md              # Guide for HyperTaxonomy contributors (Design Principles, Patterns, Blocks, Debug)
│
└── includes/                      # All PHP functionality
    │
    ├── bootstrap.php              # Orchestrates file loading
    │
    ├── admin/                     # Admin-only code
    │   ├── admin-menu.php         # Top-level "Terms" admin menu registration and highlighting
    │   ├── ajax.php               # AJAX handlers (includes autocomplete helper functions)
    │   ├── bulk-actions.php       # Bulk actions for term lists (visibility, icons, redirects)
    │   ├── convert-terms.php      # Convert Terms admin UI and handler (cross-taxonomy term conversion)
    │   ├── export-hypertaxonomy.php  # HyperTaxonomy export functionality
    │   ├── export-ui.php          # Export / Import tab: Export subtab
    │   ├── group-admin.php        # Term Groups admin UI and management
    │   ├── import-hypertaxonomy.php  # HyperTaxonomy import functionality
    │   ├── import-tag-groups-handler.php  # Tag Groups mapping, re-run locks, integration mode helpers
    │   ├── import-ui.php          # Export / Import tab: Import subtab
    │   ├── tag-groups-live-sync.php  # Tag Groups live sync hooks (Live integration mode only)
    │   ├── tag-groups-migrate-ui.php # Export / Import tab: Migrate subtab (Tag Groups import)
    │   ├── tag-groups-sync-ui.php    # Export / Import tab: Sync subtab (integration mode, removal handling)
    │   ├── merge-terms.php        # Merge Terms admin UI and handler
    │   ├── post-edit.php          # Post edit screen: assets, meta boxes, save handlers
    │   ├── relationship-types-admin.php  # Term Relationship Types admin page: Custom and Inclusion-based 
    │   ├── settings.php           # Settings page UI (HyperTaxonomy_Settings class)
    │   ├── tables.php             # List tables: assets, columns, filters, row classes
    │   ├── term-edit.php          # Term edit screen: assets, form fields, save handlers
    │   ├── term-edit-lock.php     # Heartbeat-based concurrent edit locking (all taxonomies + RT edit page)
    │   ├── term-filters.php       # Term filtering functionality (includes filter helper functions)
    │   └── term-icons.php         # Term icon upload UI (HyperTaxonomy_Term_Icons class)
    │
    ├── classes/                   # Infrastructure classes
    │   ├── class-activator.php       # Plugin activation
    │   ├── class-blocks.php       # HyperTaxonomy_Blocks: registers all Gutenberg blocks (+ native block extensions)
    │   ├── class-capabilities.php    # Capability management
    │   ├── class-power-search.php    # PowerSearch form, AJAX endpoint, relevance scoring
    │   ├── class-hierarchical-checklist.php  # Hierarchical term checklist UI component (Walker + render functions)
    │   ├── class-search-index.php    # Search index builder and sync
    │   ├── class-search-widget.php   # Advanced search widget system with 9 shortcodes & AJAX handlers
    │   └── class-upgrader.php        # Database upgrades
    │
    ├── core/                      # Core functionality
    │   ├── activation.php         # Activation/deactivation (10 functions)
    │   ├── cache.php              # Caching & locks (26 functions - includes cache helper functions)
    │   ├── compatibility.php      # Environment checks (6 functions)
    │   ├── frontend.php           # Frontend display: hidden terms, CSS, icons, pills, archive display
    │   ├── functions.php          # Core infrastructure (19 functions - logging, cache, plugin lifecycle, taxonomy config, tag creation gate)
    │   ├── group-functions.php    # Term Groups membership functions (group assignment to terms)
    │   ├── helpers.php            # Core utilities (13 functions - SQL, term validation, POST handling, notices, nonce, term description cleaning)
    │   ├── integration-api.php    # Public integration API for third-party plugins
    │   ├── relationship-types.php      # Relationship Type Registry: registration API, SQL helpers, persistence
    │   ├── relationship-types-rest.php # REST endpoints: GET htax/v1/relationship-types, GET htax/v1/term/{id}/relationships (peer sync — public)
    │   ├── post-editor-rest.php        # REST endpoints: GET htax/v1/suggested-tags, GET htax/v1/term-meta (editor UI — require edit_posts)
    │   ├── rest-api.php                # REST field extensions on term/post objects + X-HyperTaxonomy header + sitemap filter
    │   ├── search.php             # All search functions (24 functions - query handling, post text sync, AJAX formatting, utilities)
    │   ├── taxonomies.php         # Custom taxonomy registration (registers 'group' taxonomy)
    │   ├── term-archives.php      # Archive queries & sticky posts (12 functions - includes group archives)
    │   ├── term-conversion.php    # Term taxonomy conversion (14 functions): native engine,  icon-transfer check, sticky transfer, redirect stub creation
    │   ├── term-groups.php        # Term Groups inclusion/container functions
    │   ├── term-merge.php         # Term merge logic (transfer posts and relationships, optional redirect stub)
    │   ├── term-relationships.php # Synonyms/includes/redirects (36 functions - includes relationship helpers)
    │   ├── native-blocks.php      # Native block extensions: group filter for core/query and core/latest-posts
    │   └── term-visibility.php    # Term visibility logic (10 functions - hidden terms, write-time cascade, frontend query filtering)
    │
    ├── hooks/                     # WordPress hooks
    │   ├── actions.php            # Action hook registrations
    │   └── filters.php            # Filter hook registrations
    │
    ├── integrations/              # Third-party plugin integrations (loaded conditionally)
    │   ├── class-atcf-integration.php  # As the Crow Flies integration
    │   └── class-taxopress-integration.php  # PublishPress Taxonomies (TaxoPress) integration
    │
    └── shortcodes/                # Shortcode system
        ├── class-filter-options.php     # [ht_filter_options] + child filter shortcodes + htax_build_filter_sql()
        ├── class-post-list.php          # [ht_post_list] and [ht_related_posts] shortcodes
        ├── class-shortcode-base.php     # Base class + shortcode helper functions
        ├── class-sort-order.php         # [ht_sort_order_options] + htax_render_sort_order_picker()
        ├── class-term-cloud.php         # [ht_term_cloud] shortcode
        ├── class-term-display-base.php  # Base class for term display shortcodes
        ├── class-term-groups.php        # [ht_term_groups] shortcode
        ├── class-term-index.php         # [ht_term_index] shortcode
        └── class-term-list.php          # [ht_term_list] shortcode

blocks/                            # Gutenberg block build source and output
├── shared/
│   ├── post-list-edit.js          # Shared React Edit factory for post-list/related-posts; exports createEdit(blockName, opts)
│   ├── term-display-edit.js       # Shared React Edit factory for term-display blocks; exports createEdit(blockName, DisplayPanel, opts) + helpers
│   ├── components.js              # Shared React components: TermTokenField, GroupTokenField, SortOrderBuilder
│   └── editor.css                 # Editor-only styles (sort order builder row layout)
├── ht-post-list/
│   ├── block.json                 # Block metadata: name, attributes, editorScript, editorStyle
│   ├── src/
│   │   └── index.js               # Thin wrapper: registers hypertaxonomy/post-list via createEdit()
│   └── build/                     # Webpack output (committed — plugin works without npm)
│       ├── index.js               # Compiled + minified editor JS
│       ├── index.asset.php        # Auto-generated dependency list for wp_enqueue_script
│       ├── ht-post-list.css       # Extracted editor CSS
│       └── ht-post-list-rtl.css   # RTL variant (auto-generated by @wordpress/scripts)
├── ht-related-posts/
│   ├── block.json                 # Block metadata: usesContext postId/postType; orderby default relevance:desc,date:desc
│   ├── src/
│   │   └── index.js               # registers hypertaxonomy/related-posts via createEdit({showTermSourceFilters:false, allowAllTaxonomies:true})
│   └── build/
│       ├── index.js
│       ├── index.asset.php
│       ├── ht-related-posts.css
│       └── ht-related-posts-rtl.css
├── ht-term-list/                  # same structure for ht-term-cloud, ht-term-index, ht-term-groups
│   ├── block.json
│   ├── src/
│   │   └── index.js               # defines DisplayPanel, calls createEdit(blockName, DisplayPanel) from term-display-edit.js
│   └── build/
│       ├── index.js
│       ├── index.asset.php
│       ├── ht-term-list.css
│       └── ht-term-list-rtl.css
├── ht-sort-order-options/
│   ├── block.json
│   ├── src/
│   │   └── index.js               # standalone Edit component; no shared factory; context-aware notice
│   └── build/
│       ├── index.js
│       └── index.asset.php        # no extracted CSS for this block
├── ht-power-search/
│   ├── block.json
│   ├── src/
│   │   └── index.js               # standalone Edit component; ServerSideRender preview; no shared factory
│   └── build/
│       ├── index.js
│       └── index.asset.php        # no extracted CSS for this block
├── ht-query-group-filter/         # NOT a standalone block — extends core/query and core/latest-posts
│   ├── block.json                 # supports.inserter:false; editorScript only (no render callback)
│   ├── src/
│   │   └── index.js               # addFilter(blocks.registerBlockType) adds htax_filter_groups attr
│   │                              # addFilter(editor.BlockEdit) injects GroupTokenField inspector panel
│   └── build/
│       ├── index.js
│       └── index.asset.php

```

## Loading Sequence

### 1. Main File (hypertaxonomy.php)
```php
1. Define constants (HTAX_PLUGIN_DIR, HTAX_VERSION, etc.)
2. Load compatibility.php
3. Check environment (htax_can_load())
4. If compatible, load bootstrap.php
5. Register activation/deactivation hooks
```

### 2. Compatibility Check (includes/core/compatibility.php)
```php
1. Check PHP version (>= 7.4)
2. Check WordPress version (>= 5.0)
3. Check required PHP extensions (json, mysqli)
4. Check database connectivity
5. Verify termmeta table exists
6. Check file integrity (all required files present)
7. Display admin notices if issues found
```

### 3. Bootstrap (includes/bootstrap.php)
```php
Load order (dependencies verified):

1. CORE FUNCTIONALITY (no dependencies)
   - core/functions.php (core infrastructure)
   - core/helpers.php (core utilities)
   - core/relationship-types.php (relationship type registry — must load before term-relationships.php)
   - core/relationship-types-rest.php (peer sync REST endpoints)
   - core/post-editor-rest.php (editor REST endpoints)
   - core/term-visibility.php (visibility logic - depends on helpers)
   - core/cache.php (includes cache helpers)
   - core/search.php (all search functions - depends on term-visibility)
   - core/taxonomies.php (registers 'group' taxonomy)
   - core/group-functions.php (group membership - _htax_groups meta)
   - core/term-relationships.php (includes relationship helpers)
   - core/term-groups.php (included/container groups)
   - core/term-conversion.php (term taxonomy conversion — native engine, external-plugin detection, redirect stub creation, sticky transfer)
   - core/term-merge.php (term merge logic)

2. INFRASTRUCTURE CLASSES - Early Load
   - classes/class-capabilities.php (auto-initializes, used by other classes)
   - classes/class-hierarchical-checklist.php (hierarchical term UI component)

3. INFRASTRUCTURE CLASSES - Continued
   - classes/class-search-index.php (uses term-relationships functions)
   - classes/class-activator.php
   - classes/class-upgrader.php

4. ADDITIONAL CLASSES
   - classes/class-search-widget.php (advanced taxonomy search widget)
   - classes/class-power-search.php (PowerSearch form with field-specific query parsing and relevance scoring)

5. CORE FEATURES (use functions, cache, classes)
   - core/term-archives.php
   - core/integration-api.php
   - core/rest-api.php
   - core/frontend.php
   - core/native-blocks.php  (Query Loop + Latest Posts group filter extension)
   - core/activation.php

6. UNCONDITIONAL ADMIN (loaded always, including REST API context)
   - admin/post-edit.php                (post edit screen: assets, meta boxes, save handlers;
                                         also registers htax_sticky_terms REST field used by
                                         the Gutenberg sidebar panel — must be outside is_admin()
                                         because REST API requests do not pass is_admin())

7. ALWAYS (after post-edit.php, before admin block)
   - admin/term-icons.php               (HyperTaxonomy_Term_Icons class — icon retrieval, placeholder
                                         color, image-size registration needed on frontend for
                                         htax_get_term_icon_html() and shortcode {icon} placeholder)

8. ADMIN (only if is_admin(), uses all core)
   - admin/admin-menu.php               (top-level Terms menu + submenus)
   - admin/relationship-types-admin.php (Term Relationships admin page + form handlers)
   - admin/settings.php                 (settings page UI)
   - admin/term-edit.php                (term edit screen: assets, form fields, save handlers)
   - admin/term-edit-lock.php           (heartbeat-based concurrent edit locking for terms + RT edit)
   - admin/tables.php                   (list tables: assets, columns, filters, row classes)
   - admin/term-filters.php             (backend filtering logic for admin tables)
   - admin/bulk-actions.php             (bulk operations for term lists)
   - admin/group-admin.php              (Term Groups admin UI)
   - admin/merge-terms.php              (Merge Terms admin page)
   - admin/convert-terms.php           (Convert Terms admin page + AJAX term loader)

9. AJAX (if is_admin() or wp_doing_ajax())
   - admin/ajax.php           (AJAX handlers)

10. SHORTCODES (load after core functions)
   - shortcodes/class-shortcode-base.php
   - shortcodes/class-term-display-base.php
   - shortcodes/class-term-list.php
   - shortcodes/class-term-cloud.php
   - shortcodes/class-term-groups.php
   - shortcodes/class-term-index.php
   - shortcodes/class-sort-order.php  (must load before class-post-list.php — defines htax_render_sort_order_picker())
   - shortcodes/class-filter-options.php  (must load before class-post-list.php — defines htax_build_filter_sql(), htax_parse_inline_filters(), htax_resolve_filter_configs(), htax_render_filter_options(); also registers pre_get_posts hook htax_apply_archive_filter at priority 11)
   - shortcodes/class-post-list.php

10. GUTENBERG BLOCKS (load after shortcodes — render callbacks instantiate shortcode classes)
    - classes/class-blocks.php  (only loaded when register_block_type() exists; hooks HyperTaxonomy_Blocks::register() to init; hooks HyperTaxonomy_Blocks::register_category() to block_categories_all to prepend the "HyperTaxonomy" inserter category)

11. HOOKS (loaded last, after all functions defined)
    - hooks/actions.php
    - hooks/filters.php
```

### Block Build Pipeline

The editor JS for each block is compiled with `@wordpress/scripts` (webpack). Source files live in `blocks/{block-name}/src/`; output goes to `blocks/{block-name}/build/`. Build output is committed to the repository so the plugin works without a local `npm run build` step.

`block.json` declares `editorScript` and `editorStyle` as `file:./build/…` paths. WordPress reads these during `register_block_type()` and auto-enqueues the handles with the correct dependencies from `index.asset.php`.

All nine blocks are **dynamic (server-side rendered)**: `save()` returns `null` and each PHP render callback delegates to the corresponding shortcode handler, so query logic, caching, and output are identical to the shortcode. (`hypertaxonomy/query-group-filter` is a block editor extension with no render callback; it is excluded from this count.)

**`hypertaxonomy/post-list`** — render callback calls `HyperTaxonomy_Shortcode_Post_List::shortcode_handler()` directly. Works anywhere (posts, pages, templates).

**`hypertaxonomy/related-posts`** — requires post context. Declares `"usesContext": ["postId", "postType"]` in `block.json` so it receives the current post ID in both the block editor and FSE post templates. The render callback (`HyperTaxonomy_Blocks::render_related_posts()`) sets up `global $post` from `$block->context['postId']`, then calls `HyperTaxonomy_Shortcode_Related_Posts::render_for_block()` which bypasses the `is_singular()` guard (not meaningful in a REST/block-renderer context).


**`hypertaxonomy/term-list`**, **`hypertaxonomy/term-cloud`**, **`hypertaxonomy/term-index`**, **`hypertaxonomy/term-groups`** — render callbacks instantiate the corresponding shortcode class and call `shortcode_handler($attributes)`. Block attribute names match shortcode attribute names exactly, so no remapping is needed.

The first eight blocks (all except `sort-order-options`) display a live `ServerSideRender` preview in the editor that re-fetches from the REST block renderer endpoint whenever attributes change.

**`hypertaxonomy/sort-order-options`** — render callback is `HyperTaxonomy_Blocks::render_sort_order_options()`. Guards on `htax_get_enabled_archive_taxonomy() || is_search()` and returns empty string outside those contexts (enabled taxonomy archives and search results pages are the only places `$_GET['htax_orderby']` is consumed). Calls `htax_render_sort_order_picker()` directly with `action='navigate'` hardcoded; the block's `sort_default` attribute maps to `default_value`. Does not use `ServerSideRender` in the editor — shows a static placeholder. Uses `select('core/edit-site').getEditedPostId()` to detect FSE template type and shows an info notice when outside a valid archive/search template.

**`hypertaxonomy/power-search`** — render callback is `HyperTaxonomy_Blocks::render_power_search()`. Calls `HyperTaxonomy_Power_Search::get_instance()->search_form_shortcode($atts)` with form-level attributes (query scope, pagination, scoring). Display attributes (thumbnail, columns, format, etc.) live on a separate `[ht_search_results]` container block. Uses `ServerSideRender` + `Disabled` in the editor. Has its own standalone `Edit` component; does not use a shared factory.

**Shared editor JS**: Three shared files handle the inspector UI for the HyperTaxonomy blocks:
- `blocks/shared/components.js` — shared React components: `TermTokenField` (pill search for taxonomy terms), `GroupTokenField` (pill search for `group` taxonomy terms), `SortOrderBuilder` (composite sort order builder), `SORT_FIELDS`, `SORT_FIELD_VALUES`. Used by all blocks that need term/group pickers or sort order configuration.
- `blocks/shared/post-list-edit.js` — exports `createEdit(blockName, opts)` for the post-list family. `htax-related-posts` passes `{ showTermSourceFilters: false, allowAllTaxonomies: true }` — hides include/exclude term/group controls (term set is derived from the current post automatically) and offers the "— All taxonomies —" option in the taxonomy selector.
- `blocks/shared/term-display-edit.js` — exports `createEdit(blockName, DisplayPanel, opts)` for the term-display family. Each block's `src/index.js` defines a small `DisplayPanel` component for its block-specific options. Pass `{ isTermGroups: true }` for the Term Groups block to switch to a multi-taxonomy field and hide individual term filters.

`htax-sort-order-options` and `htax-power-search` each have their own standalone `Edit` components and do not use a shared factory.

**Query Loop and Latest Posts block extension**: `blocks/ht-query-group-filter/` is not a standalone block (it has `"supports": {"inserter": false}` in `block.json`). It uses `addFilter('blocks.registerBlockType')` to add an `htax_filter_groups` attribute to both `core/query` and `core/latest-posts`, and `addFilter('editor.BlockEdit')` with `createHigherOrderComponent` to inject a `GroupTokenField` inspector panel into both block types. The PHP side is `includes/core/native-blocks.php` → `htax_query_loop_group_filter()` (hooked to `query_loop_block_query_vars`, WP 6.1+) and `htax_latest_posts_group_filter()` (hooked to `pre_render_block`, WP 5.9+). See [Query Loop Group Filter](#query-loop-group-filter) below.

The webpack config (`webpack.config.js`) is an array of per-block configs, each outputting to its own `build/` directory.

## Dependency Map

```
                         ┌──────────────────────────────┐
                         │        functions.php         │
                         └──────────────┬───────────────┘
                                        │
                         ┌──────────────▼───────────────┐
                         │          helpers.php         │
                         └───────┬───────────────┬──────┘
                                 │               │
           ┌─────────────────────▼───┐    ┌──────▼──────────────────┐
           │   relationship-types    │    │    taxonomies.php       │
           │     (type registry)     │    │    group-functions.php  │
           └─────────────────────┬───┘    └───────┬─────────────────┘
                                 │                │
           ┌─────────────────────▼───┐            │
           │    term-visibility      │            │
           │    cache.php            │            │
           │    search.php           │            │
           └─────────────────────┬───┘            │
                                 └───────┬────────┘
                                         │
                         ┌───────────────▼──────────────┐
                         │      term-relationships      │
                         │  (inclusions, redirects,     │
                         │   groups, custom types)      │
                         └───────┬───────────────┬──────┘
                                 │               │
           ┌─────────────────────▼───┐    ┌──────▼──────────────────┐
           │    term-groups          │    │   class-capabilities    │
           │    term-conversion      │    │   class-checklist       │
           │    term-merge           │    └──────┬──────────────────┘
           └─────────────────────┬───┘           │
                                 │    ┌──────────▼──────────────────┐
                                 │    │     class-search-index      │
                                 │    └──────────┬──────────────────┘
                                 │               │
                                 │    ┌──────────▼──────────────────┐
                                 │    │  class-activator            │
                                 │    │  class-upgrader             │
                                 │    │  class-search-widget        │
                                 │    │  class-power-search         │
                                 │    └──────────┬──────────────────┘
                                 │               │
                                 └───────┬───────┘
                                         │
           ┌─────────────────────────────▼──────────────────────────┐
           │  term-archives  ·  integration-api  ·  rest-api        │
           │  external-posts *  ·  frontend  ·  native-blocks       │
           │  activation                                            │
           └──────────┬──────────────────────┬──────────────────────┘
                      │                      │
           ┌──────────▼──────────┐  ┌────────▼────────────────────┐
           │    shortcodes/      │  │  admin/  (is_admin())       │
           └──────────┬──────────┘  └────────┬────────────────────┘
                      │                      │
           ┌──────────▼──────────┐           │
           │    class-blocks     │           │
           └──────────┬──────────┘           │
                      └──────────────┬───────┘
                                     │
                         ┌───────────▼──────────────┐
                         │    hooks/  (load last)   │
                         └──────────────────────────┘
```

`*` `external-posts.php` and `term-links.php` live in TermConnect. See TermConnect documentation for their load order.

