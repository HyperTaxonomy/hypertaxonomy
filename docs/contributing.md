---
title: Contributing
nav_order: 6
---

# Contributing to HyperTaxonomy

For developers contributing to HyperTaxonomy.

For integrating HyperTaxonomy in your theme or plugin, see the [Developer Reference](developer-reference/). For internal plugin architecture and implementation details, see the [Architecture](architecture/) section.

---

## Table of Contents

1. [Design Principles](#1-design-principles)
2. [Coding Standards](#2-coding-standards)
3. [Implementation Patterns](#3-implementation-patterns)
4. [Building Block Assets](#4-building-block-assets)
5. [Error Handling](#5-error-handling)
6. [Debug & Diagnostics](#6-debug--diagnostics)

---

## 1. Design Principles

- **Separation of Concerns** — Core logic in `includes/core/`, admin UI in `includes/admin/`, frontend in `assets/`. Admin files load only in admin context, with two exceptions loaded unconditionally: `admin/post-edit.php` (the Gutenberg sidebar panel runs through the REST API, which executes outside `is_admin()`) and `admin/term-icons.php` (term icons are also rendered on the frontend).
- **Dependency Injection via Hooks** — All hooks are registered after functions are defined. No global state dependencies; hook callbacks are easy to replace or override.
- **Caching Strategy** — Three layers: static request-scope cache (unbounded per-request arrays; `htax_get_valid_term()` uses a bounded variant capped at 500 entries with FIFO eviction), transients (database-backed, survive page loads), object cache (Redis/Memcached when available). Selective invalidation — narrow keys are cleared on specific events rather than broad cache flushes.
- **Graceful Degradation** — Term Groups is built-in; no external plugin dependency. Features gate themselves via `htax_taxonomy_has_feature()` so disabling a feature at the settings level has immediate effect without code changes.
- **Security** — ABSPATH checks on every file, nonce verification on all AJAX and form saves, `current_user_can()` / HyperTaxonomy capability checks before writes, `$wpdb->prepare()` everywhere, `wp_unslash()` before sanitization on all `$_POST` reads.
- **Atomicity** — Functions that modify multiple database rows acquire all MySQL named locks first, perform all writes inside a transaction, release locks in a `finally` block. Either everything succeeds or nothing does.

---

## 2. Coding Standards

### Naming Conventions

- **Functions:** `htax_snake_case()`
  - Output functions: `htax_the_*()` — e.g. `htax_the_term_pills()`
  - Return functions: `htax_get_*()` — e.g. `htax_get_term_pill()`
  - Action/callback functions: `htax_verb_*()` — e.g. `htax_save_*()`, `htax_filter_*()`, `htax_display_*()`
  - Boolean checks: `htax_is_*()` or `htax_has_*()` — e.g. `htax_is_redirect()`
- **Classes:** `HyperTaxonomy_Pascal_Case`
- **Constants:** `HTAX_UPPER_SNAKE_CASE`
- **Hooks:** `htax_hook_name`
- **Meta keys:** `_htax_meta_key` (underscore prefix = hidden from UI)

### File Header

Every PHP file starts with:

```php
<?php
/**
 * Brief Description
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

### Function Documentation

```php
/**
 * Brief description.
 *
 * Longer explanation when the logic is non-obvious.
 *
 * @since 1.0.0
 * @param type  $param  Description.
 * @return type Description.
 */
```

---

## 3. Implementation Patterns

### AJAX-Compatible Taxonomy Detection

When adding custom columns to term list tables, the taxonomy must be detected from either the screen object (standard page load) or the request parameters (AJAX operations):

```php
function htax_add_custom_column( $columns ) {
    $current_screen = get_current_screen();
    if ( $current_screen && isset( $current_screen->taxonomy ) ) {
        $taxonomy = $current_screen->taxonomy;
    } elseif ( isset( $_REQUEST['taxonomy'] ) ) {
        $taxonomy = sanitize_key( $_REQUEST['taxonomy'] );
    } else {
        return $columns;
    }

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return $columns;
    }

    $columns['custom_column'] = __( 'Column Name', 'hypertaxonomy' );
    return $columns;
}
```

`get_current_screen()` returns null during AJAX requests; WordPress passes `taxonomy` in `$_REQUEST` instead. Without this pattern, custom columns appear blank when adding terms via the inline AJAX form. Used in: `htax_add_icon_column()`, `htax_add_hidden_column()`, `htax_add_redirect_column()`, `htax_add_term_groups_column()` (`includes/admin/tables.php`).

### Dual Nonce Verification

When saving term metadata, requests can arrive from the edit form or the AJAX quick-add form. Each uses a different nonce:

```php
$nonce_verified = false;

if ( isset( $_POST['_wpnonce'] ) ) {
    $nonce_verified = wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
        'update-tag_' . $term_id
    );
}

if ( ! $nonce_verified && isset( $_POST['_wpnonce_add-tag'] ) ) {
    $nonce_verified = wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['_wpnonce_add-tag'] ) ),
        'add-tag'
    );
}

if ( ! $nonce_verified ) {
    return;
}
```

Edit form: `_wpnonce` / action `update-tag_{term_id}`. AJAX add form: `_wpnonce_add-tag` / action `add-tag`. See `htax_save_term_relationships()` in `includes/core/term-relationships.php`.

### Early Hook Priority for AJAX Compatibility

WordPress fires `create_{$taxonomy}` before the AJAX response is built, and `created_{$taxonomy}` after the term is fully created. Registering at both priorities ensures metadata is available in all contexts:

```php
// Priority 5: runs before the AJAX response is assembled
add_action( "create_{$taxonomy}", 'htax_save_term_data', 5, 2 );

// Priority 20: runs after full creation (compatible with Tag Groups — now "PublishPress Tag
// Groups" as of its Oct 2026 rebrand — which saves at the default priority 10)
add_action( "created_{$taxonomy}", 'htax_save_term_data', 20, 2 );
```

Using only `created_{$taxonomy}` causes metadata to be missing in AJAX responses. See `includes/hooks/actions.php`.

### Screen Options Column Visibility Sync

Custom filter dropdowns should hide when their corresponding columns are hidden via Screen Options. Use a `MutationObserver` on column header class changes to keep them in sync:

```javascript
var columnFilterMap = {
    'column-htax_redirect':     '#htax_redirect_filter',
    'column-htax_icon':         '#htax_icon_filter',
    'column-htax_hidden':       '#htax_visibility_filter',
    'column-htax_term_groups':  '#htax_group_filter'
};

function syncFilterVisibility() {
    $.each( columnFilterMap, function( columnClass, filterId ) {
        var $col = $( '.wp-list-table thead th.' + columnClass );
        var isHidden = $col.hasClass( 'hidden' ) || $col.css( 'display' ) === 'none';
        $( filterId ).toggle( ! isHidden );
    } );
}

new MutationObserver( function( mutations ) {
    if ( mutations.some( m => m.attributeName === 'class' ) ) {
        syncFilterVisibility();
    }
} ).observe( document.body, { attributes: true, subtree: true } );
```

See `admin/js/htax-tables.js`.

### Relationship Type Registry

All `relationship_type` SQL queries throughout HyperTaxonomy use `htax_relationship_type_sql_in()` rather than a hard-coded equality check. This ensures that user-defined types mapped to a core type automatically participate in archive expansion, index queries, and admin display.

**Registering a type in PHP** (hook into `htax_relationship_types`, which fires at init priority 0 after all built-in types are registered):

```php
add_action( 'htax_relationship_types', function () {
    htax_register_relationship_type( 'wrote', array(
        'label'           => __( 'Wrote', 'my-plugin' ),
        'inverse_label'   => __( 'Written by', 'my-plugin' ),
        'core_type'       => 'includes', // archive behavior: "wrote" posts appear in author term's archive
        'show_in_ui'      => true,
    ) );
} );
```

**Registering via the admin UI** — **Terms → Term Relationship Types** lets editors add, edit, and delete user-defined types without code. Stored in `wp_options` under `htax_custom_relationship_types`.

**`core_type` mapping** — only `'includes'` is user-mappable. A type mapped to `'includes'` causes posts tagged with the *target* term to appear in the *source* term's archive, identical to a direct `includes` relationship. The target may be an ordinary term or a group — if a group, every member term's posts are included, same as group inclusion.

**SQL expansion** — every query site that previously used `relationship_type = 'includes'` now uses `htax_relationship_type_sql_in( 'includes' )`, which returns a fragment like `relationship_type IN ('includes','wrote')`. The fragment is safe to embed directly in `$wpdb->prepare()` strings because slugs come from the in-memory registry and are individually passed through `esc_sql()`.

**Saving / deleting rows** for user-defined types:

```php
// Save a relationship row (validates taxonomy constraints, fires htax_relationship_saved)
$result = htax_add_term_relationship( $source_term_id, $target_term_id, 'wrote' );
if ( is_wp_error( $result ) ) {
    // handle error
}

// Delete a relationship row (fires htax_relationship_deleted)
htax_remove_term_relationship( $source_term_id, $target_term_id, 'wrote' );
```

Both actions (`htax_relationship_saved`, `htax_relationship_deleted`) automatically queue a search index rebuild for both terms. Core relationship types (`includes`, `member_of`, `redirect`, `excludes`) are managed by the built-in save handlers and cannot be written via these helpers.

### Transients

Use `htax_get_transient()`, `htax_set_transient()`, and `htax_delete_transient()` for every HyperTaxonomy transient — never the core `get_transient()` family directly. The wrappers make bulk invalidation work under persistent external object caches, where the `DELETE … LIKE` clears in `includes/core/cache.php` can't reach cached entries. Give new transients an `htax_` prefix; if a new prefix needs its own bulk clear, add it to `htax_transient_family()` and end the clear with `htax_bump_cache_family()`. See [Database Schema → caching](architecture/database-schema.html).

### Walker Tree Type and Max Depth

For hierarchical term rendering with WordPress's `Walker` class, use `'term'` (not `'category'`) as the tree type, and `0` (not `-1`) as max depth:

```php
class HyperTaxonomy_Walker_Checklist extends Walker {
    public $tree_type = 'term';   // works for all taxonomies
    public $db_fields = array(
        'parent' => 'parent',
        'id'     => 'term_id',
    );
}

// Walker::walk( $elements, $max_depth, ...$args )
// 0 = show all levels; -1 causes hierarchy-detection issues
$walker->walk( $terms, 0, $args );
```

Order terms by `'parent'` for proper hierarchical display. Used in `HyperTaxonomy_Walker_Checklist` (`includes/classes/class-hierarchical-checklist.php`).

---

## 4. Building Block Assets

Nine Gutenberg block bundles are compiled from source files in `blocks/` using `@wordpress/scripts` (webpack). The compiled output in each block's `build/` directory is committed to the repository and is what WordPress loads at runtime. Each entry point is declared in `webpack.config.js` — add a new entry there when adding a new block.

| Block name | Title | Shortcode equivalent |
|---|---|---|
| `hypertaxonomy/power-search` | HT PowerSearch | `[ht_power_search]` |
| `hypertaxonomy/post-list` | HT Post List | `[ht_post_list]` |
| `hypertaxonomy/related-posts` | HT Related Posts | `[ht_related_posts]` |
| `hypertaxonomy/term-list` | HT Term List | `[ht_term_list]` |
| `hypertaxonomy/term-cloud` | HT Term Cloud | `[ht_term_cloud]` |
| `hypertaxonomy/term-index` | HT Term Index | `[ht_term_index]` |
| `hypertaxonomy/term-groups` | HT Term Groups | `[ht_term_groups]` |
| `hypertaxonomy/sort-order-options` | HT Sort Order Options | `[ht_sort_order_options]` |
| `hypertaxonomy/query-group-filter` | *(block editor extension, no inserter entry)* | — |

### Prerequisites

Node.js and npm must be installed. Install dependencies once from the plugin root:

```bash
npm install
```

### Build commands

```bash
# Production build (minified, with asset manifests)
npm run build

# Development watch mode (unminified, rebuilds on save)
npm start

# Lint JS source files
npm run lint:js
```

**Shared components** (`blocks/shared/components.js`): exports `TermTokenField` (pill-based term picker with search-as-you-type for any taxonomy), `GroupTokenField` (same for `htax_term_group`), `SortOrderBuilder` (composite sort order editor — field + direction rows with add/remove/reorder), `SORT_FIELDS`, and `SORT_FIELD_VALUES`. Imported by the post-list factory, the term-display factory, the individual sort-order-options and power-search blocks, and the query-group-filter extension.

**Post-list family** (`htax-post-list`, `htax-related-posts`): each `src/index.js` calls `createEdit(blockName, opts)` from `post-list-edit.js`. Pass `{ showTermSourceFilters: false }` for Related Posts to hide include/exclude term/group controls.

**External posts blocks** (`termconnect/peer-posts`, `termconnect/peer-browse`): these blocks are defined in the TermConnect plugin (its own `blocks/` directory), built with its own webpack config. See TermConnect developer documentation.

**Term-display family** (`htax-term-list`, `htax-term-cloud`, `htax-term-index`, `htax-term-groups`): each `src/index.js` defines a small `DisplayPanel` component for its block-specific options and calls `createEdit(blockName, DisplayPanel, opts)` from `term-display-edit.js`. The shared factory handles the Term Source panel (taxonomy selector, hide-empty, include/exclude) for all four. Pass `{ isTermGroups: true }` for the Term Groups block to switch the taxonomy selector to a multi-taxonomy field and hide individual term filters.

**Sort-order-options** (`htax-sort-order-options`): standalone block with its own `Edit` component — no shared factory. Uses `useSelect('core/edit-site')` to detect FSE template context and shows an info notice when the block is placed outside a valid archive/search template. The PHP render callback (`render_sort_order_options`) guards on `htax_get_enabled_archive_taxonomy() || is_search()` and returns empty string outside those contexts; action is hardcoded to `navigate`.

**Fulltext-search** (`htax-power-search`): standalone block with its own `Edit` component — no shared factory. Uses `ServerSideRender` + `Disabled` to show a live preview of the search form in the editor. Inspector panels: Search Form (placeholder, button label, match mode, minimum query length), Results (posts per page, sort order, minimum relevance score, show thumbnail/excerpt/meta toggles), AJAX Mode (container ID), and Advanced (CSS class). The PHP render callback (`render_power_search`) calls `HyperTaxonomy_Power_Search::get_instance()->search_form_shortcode($atts)` with attributes mapped directly — identical output to the shortcode.

**Query-group-filter** (`htax-query-group-filter`): not a standalone block — a block editor plugin that extends both `core/query` and `core/latest-posts`. Uses `addFilter('blocks.registerBlockType')` to add `htax_filter_groups` attribute to the schema of both blocks, and `addFilter('editor.BlockEdit')` + `createHigherOrderComponent` to inject a `GroupTokenField` inspector panel into both block types. Has `"supports": {"inserter": false}` so it never appears in the block inserter. The `register_block_type` call in `class-blocks.php` has no render callback — registration exists solely to auto-enqueue the `editorScript`. The PHP query modifications are handled server-side in `includes/core/native-blocks.php`: `htax_query_loop_group_filter()` hooked to `query_loop_block_query_vars` (WP 6.1+) for `core/query`, and `htax_latest_posts_group_filter()` hooked to `pre_render_block` (WP 5.9+) for `core/latest-posts`.

### When to rebuild

Run `npm run build` after modifying any file under `blocks/`. The compiled `build/` files must be committed alongside source changes — WordPress loads the compiled output, not the source.

When adding a new block, also add its directory name to the `blocks` array in `webpack.config.js` — the build system does not auto-discover new entries.

---

## 5. Error Handling

### The `$throw_on_failure` Pattern

Functions that perform cleanup or relationship updates accept an optional `$throw_on_failure` parameter:

```php
function htax_cleanup_operation( $params, $throw_on_failure = false ) {
    if ( $failure_occurred ) {
        if ( $throw_on_failure ) {
            throw new Exception( 'Operation failed: reason' );
        } else {
            htax_log_error( 'Operation failed but continuing', $context );
            return false;
        }
    }
    return true;
}
```

**Use `$throw_on_failure = true`** for user-initiated operations that should fail atomically (admin form saves, AJAX actions, bulk operations).

**Use `$throw_on_failure = false`** (default) for hook-triggered operations that must be resilient (edited_term callbacks, cache invalidation, background cleanup). Never throw exceptions from WordPress hook callbacks.

### 3-Phase Atomic Locking

```php
$acquired_locks = array();

// PHASE 1: Acquire ALL locks or fail atomically
foreach ( $items as $item ) {
    $lock_key = "htax_redirect_{$item}";
    if ( ! htax_acquire_lock( $lock_key, 5 ) ) {
        foreach ( $acquired_locks as $key ) { htax_release_lock( $key ); }
        if ( $throw_on_failure ) {
            throw new Exception( 'Failed to acquire lock: ' . $item );
        }
        return false;
    }
    $acquired_locks[] = $lock_key;
}

// PHASE 2: Perform all updates (all locks held)
try {
    // ... all database updates ...
} finally {
    // PHASE 3: Release ALL locks (always runs, even on exception)
    foreach ( $acquired_locks as $key ) { htax_release_lock( $key ); }
}
```

Used in `htax_save_redirect()` and `htax_save_term_relationships()` (MySQL `START TRANSACTION` + `ROLLBACK` on failure).

### Bulk Operations

Bulk operations continue on individual failures and report a count of successes. Complex operations requiring atomic cleanup (e.g., removing redirects) are not available as bulk actions — they must go through the term edit interface to ensure data integrity.

### Admin Notices

```php
htax_add_admin_notice( __( 'Saved.', 'hypertaxonomy' ), 'success' );
htax_add_admin_notice( sprintf( __( 'Failed: %s', 'hypertaxonomy' ), $e->getMessage() ), 'error' );
// 'info' — for non-critical notifications (e.g. conflict auto-cleanup)
```

---

## 6. Debug & Diagnostics

### Debug Logging

Enable detailed logging by adding to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'HTAX_DEBUG_VERBOSE', true ); // Adds backtraces to log entries
define( 'HTAX_QUERY_MONITOR', true ); // Logs slow queries to debug.log
```

Logs are written to `wp-content/debug.log`.

### Log Helpers

Within plugin code, use:

```php
htax_log_debug( 'Message', [ 'context_key' => $value ] );
htax_log_error( 'Error message', [ 'term_id' => $term_id, 'error' => $e->getMessage() ] );
```

`htax_log_debug()` only writes when `WP_DEBUG` is true. `htax_log_error()` always writes.

### Request-Level Timing Traces

Enable per-request lifecycle tracing by adding to `wp-config.php`:

```php
define( 'HTAX_TIMING', true );
```

Each trace line is written to `debug.log` with a millisecond offset from the first PHP request timestamp:

```
[HTAX +123ms] INC tid=42 DB_QUERY
[HTAX +145ms] INC tid=42 DB_DONE cnt=3
[HTAX +146ms] CRON_SPAWN non-blocking url=...
[HTAX +147ms] REBUILD_QUEUE_ASYNC n=2 tids=42,55
[HTAX +148ms] QUEUE_REBUILD tid=42 via=htax_delete_term_transients < htax_reload_term_caches_on_edit < ...
```

Key trace points: `INC`/`CONT` cache hits and DB queries (relationship resolution), `CRON_SPAWN`, `REBUILD_QUEUE_ASYNC`/`START`/`DONE` (async cache rebuild lifecycle), `QUEUE_REBUILD` with 5-frame call stack (who enqueued a term for rebuild), `DELETE_TERM_TRANSIENTS` with call stack (who cleared a term's cache), `AS_QUEUE_START`/`DONE` (Action Scheduler), `REST_*` (REST request boundaries), `SHUTDOWN` with URL.

Remove `define('HTAX_TIMING', true)` before production deployment. The `htax_trace()` function is always compiled in but is a no-op when the constant is absent.

### Archive Debug Info

Append `?htax_debug=1` to any term archive URL while logged in as an administrator to display a debug panel showing:

- Active term and taxonomy
- Included term IDs and their source relationships
- Container terms
- Applied exclusions
- Whether each relationship was served from cache

### Rebuilding the Search Index

The search index covers all post types returned by `htax_get_indexed_post_types()` (default: posts and pages; configurable via Settings → Search → General). The rebuild processes all published posts of those types.

The index can be rebuilt from:

- **Admin UI:** Settings > HyperTaxonomy > Advanced > Rebuild Search Index
- **AJAX action:** `wp_ajax_htax_rebuild_indexes` (truncates both tables, returns total post count) / `wp_ajax_htax_bulk_rebuild` (processes one batch of 50 posts through both tables)

To trigger a single-post reindex programmatically:

```php
htax_build_post_search_index( $post_id );
```
