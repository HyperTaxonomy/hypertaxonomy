---
title: Contributing
nav_order: 7
parent: Architecture
---

# Contributing to HyperTaxonomy

For developers contributing to HyperTaxonomy.

For integrating HyperTaxonomy in your theme or plugin, see the [Developer Reference](../developer-reference/). For internal plugin architecture and implementation details, see the [Architecture](./) section.

---

## Table of Contents

1. [Design Principles](#design-principles)
2. [Coding Standards](#coding-standards)
3. [Implementation Patterns](#implementation-patterns)
4. [Building Block Assets](#building-block-assets)
5. [Error Handling](#error-handling)
6. [Debug & Diagnostics](#debug--diagnostics)

---

## Design Principles

- **Separation of Concerns** — Core logic in `includes/core/`, admin UI in `includes/admin/`, shortcodes in `includes/shortcodes/`, frontend PHP in `includes/core/frontend.php`, frontend CSS and JS in `assets/`. Admin files load only in admin context, with two exceptions loaded unconditionally: `admin/post-edit.php` (the Gutenberg sidebar panel runs through the REST API, which executes outside `is_admin()`) and `admin/term-icons.php` (term icons are also rendered on the frontend).
- **Hooks Registered Last** — `includes/bootstrap.php` loads `includes/hooks/actions.php` and `includes/hooks/filters.php` after the files that define their callbacks. Most callbacks are named functions, so other code can remove or replace them.
- **Caching Strategy** — Three layers: static request-scope cache (unbounded per-request arrays; `htax_get_valid_term()` uses a bounded variant capped at 500 entries with FIFO eviction), transients (database-backed, survive page loads), object cache (Redis/Memcached when available). Selective invalidation — narrow keys are cleared on specific events rather than broad cache flushes.
- **Graceful Degradation** — Term Groups is built-in; no external plugin dependency. Features gate themselves via `htax_taxonomy_has_feature()` so disabling a feature at the settings level has immediate effect without code changes.
- **Security** — ABSPATH checks on every file, nonce verification on AJAX and form saves, `current_user_can()` checks with HyperTaxonomy's capabilities before writes, `$wpdb->prepare()` for SQL that includes variable values, and `wp_unslash()` before sanitizing `$_POST` strings.
- **Atomicity** — Functions that replace related rows do their writes inside a MySQL transaction and check for a database error after each write. If any write fails, the transaction rolls back. See [Transactions](#transactions).

---

## Coding Standards

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

## Implementation Patterns

### AJAX-Compatible Taxonomy Detection

When adding custom columns to term list tables, the taxonomy must be detected from either the screen object (standard page load) or the request parameters (AJAX operations):

```php
function htax_add_custom_column( $columns ) {
    $taxonomy = htax_get_current_taxonomy();
    if ( false === $taxonomy ) {
        return $columns;
    }

    $columns['custom_column'] = __( 'Column Name', 'hypertaxonomy' );
    return $columns;
}
```

`get_current_screen()` returns null during AJAX requests; WordPress passes `taxonomy` in `$_REQUEST` instead. Without this check, custom columns appear blank when adding terms via the inline AJAX form. `htax_get_current_taxonomy()` handles both cases and returns false for a taxonomy HyperTaxonomy doesn't manage. Used in: `htax_add_icon_column()`, `htax_add_hidden_column()`, `htax_add_redirect_column()`, `htax_add_term_groups_column()` (`includes/admin/tables.php`).

### Term Save Nonce Verification

Term metadata saves can come from the edit form, the AJAX quick-add form, or Quick Edit. Each uses a different nonce, and `htax_verify_term_nonce( $term_id )` (`includes/core/helpers.php`) accepts any of them:

| Source | Field | Action |
|---|---|---|
| Edit form | `_wpnonce` | `update-tag_{term_id}` |
| AJAX add form | `_wpnonce_add-tag` | `add-tag` |
| Quick Edit | `_inline_edit` | `taxinlineeditnonce` |

`htax_save_term_data()` in `includes/core/term-relationships.php` verifies the nonce once, then calls the individual save functions. It returns silently for requests that aren't one of these form submissions, such as REST, WP-CLI, and importers.

### Early Hook Priority for AJAX Compatibility

`wp_ajax_add_tag` builds the new table row between `wp_insert_term()` and `created_{$taxonomy}`, so metadata saved on `created_{$taxonomy}` would be missing from that row. New terms are therefore saved early on `create_{$taxonomy}`, while edits run late so that Tag Groups has already saved its group assignments:

```php
// Priority 5: runs before the AJAX response is assembled
add_action( "create_{$taxonomy}", 'htax_save_term_data', 5, 2 );

// Priority 20: runs after Tag Groups, which saves at the default priority 10
add_action( "edited_{$taxonomy}", 'htax_save_term_data', 20, 2 );
```

See `includes/hooks/actions.php`.

### Screen Options Column Visibility Sync

Custom filter dropdowns should hide when their corresponding columns are hidden via Screen Options. `syncFilterVisibility()` in `admin/js/htax-tables.js` maps each column to its filter, then runs on page load, when a `MutationObserver` on the table header sees a class change, and when the Screen Options controls are used:

```javascript
var columnFilterMap = {
    'column-htax_redirect':    '#htax_redirect_filter',
    'column-htax_icon':        '#htax_icon_filter',
    'column-htax_hidden':      '#htax_visibility_filter',
    'column-htax_term_groups': '.htax-group-filter-wrapper'
};
```

### Relationship Type Registry

Queries for inclusions use `htax_relationship_type_sql_in( 'includes' )` rather than a hard-coded equality check. This ensures that user-defined types mapped to `includes` automatically participate in archive expansion, index queries, and admin display. Types with no user-defined mappings, such as `member_of` and `redirect`, use plain equality.

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

**SQL expansion** — query sites match inclusions with `htax_relationship_type_sql_in( 'includes' )` rather than `relationship_type = 'includes'`, which returns a fragment like `relationship_type IN ('includes','wrote')`. The fragment is safe to embed directly in `$wpdb->prepare()` strings because slugs come from the in-memory registry and are individually passed through `esc_sql()`.

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

For types mapped to `includes`, both actions (`htax_relationship_saved`, `htax_relationship_deleted`) queue a search index rebuild for both terms. These helpers also accept `includes` and `excludes`. They reject `redirect` and `member_of`, which must go through `htax_set_redirect()` and `htax_set_term_groups()`.

### Transients

Use `htax_get_transient()`, `htax_set_transient()`, and `htax_delete_transient()` for every HyperTaxonomy transient — never the core `get_transient()` family directly. The wrappers make bulk invalidation work under persistent external object caches, where the `DELETE … LIKE` clears in `includes/core/cache.php` can't reach cached entries. Give new transients an `htax_` prefix; if a new prefix needs its own bulk clear, add it to `htax_transient_family()` and end the clear with `htax_bump_cache_family()`. See [Database Schema → caching](database-schema.html).

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
// 0 = show all levels; -1 displays every term flat, with no hierarchy
$walker->walk( $terms, 0, $args );
```

Order terms by `'parent'` for proper hierarchical display. Used in `HyperTaxonomy_Walker_Checklist` (`includes/classes/class-hierarchical-checklist.php`).

---

## Building Block Assets

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

For how each block renders and how the shared editor components fit together, see [Block Build Pipeline](file-structure.html#block-build-pipeline).

### When to rebuild

Run `npm run build` after modifying any file under `blocks/`. The compiled `build/` files must be committed alongside source changes — WordPress loads the compiled output, not the source.

When adding a new block, also add its directory name to the `blocks` array in `webpack.config.js` — the build system does not auto-discover new entries.

---

## Error Handling

### Choosing an Approach

| Approach | Use for | Examples |
|---|---|---|
| Exceptions caught in the same function | Transactions, where a failed write must trigger a rollback | `htax_save_term_relationships()` |
| `WP_Error` | API-facing functions, and functions that need to return error messages | `htax_set_term_groups()`, `htax_merge_terms()` |
| `false` | Internal helpers and validation, where the caller doesn't need details | `htax_set_redirect()` |

Don't mix approaches within a function. Never let an exception escape a WordPress hook callback: catch it, log it with `htax_log_error()`, and show the user an admin notice.

### Transactions

`$wpdb` doesn't throw on query errors. It sets `$wpdb->last_error`, and the next query clears it, so **check after every write**. A single check at the end only sees the last query, and an earlier failure would be committed. `htax_throw_on_db_error()` throws when the last query failed, and logs the SQL error:

```php
global $wpdb;

try {
    $wpdb->query( 'START TRANSACTION' );

    $wpdb->delete( $table, $where );
    htax_throw_on_db_error( 'delete old rows' );

    $wpdb->query( $insert_sql );
    htax_throw_on_db_error( 'insert new rows' );

    $wpdb->query( 'COMMIT' );
} catch ( Exception $e ) {
    $wpdb->query( 'ROLLBACK' );
    htax_add_admin_notice( sprintf( __( 'Failed: %s', 'hypertaxonomy' ), $e->getMessage() ), 'error' );
    return;
}

// Clear caches and fire actions only after the commit.
```

Functions that return `WP_Error` can check `$wpdb->last_error` after each write instead, as `htax_set_term_groups()` does, or keep the first error, as `htax_merge_terms()` does.

MySQL commits an open transaction when another one starts, so don't call a function that starts its own transaction from inside a transaction. Transactions are used in `htax_save_term_relationships()`, `htax_set_redirect()`, `htax_set_term_groups()`, `htax_merge_terms()`, and the group screen saves in `includes/admin/group-admin.php`. In a merge, the source terms are deleted after the commit, so a failure at that point can't roll back the moved posts and relationships.

### Bulk Operations

Bulk operations continue on individual failures and report a count of successes. Complex operations requiring atomic cleanup (e.g., removing redirects) are not available as bulk actions — they must go through the term edit interface to ensure data integrity.

### Admin Notices

```php
htax_add_admin_notice( __( 'Saved.', 'hypertaxonomy' ), 'success' );
htax_add_admin_notice( sprintf( __( 'Failed: %s', 'hypertaxonomy' ), $e->getMessage() ), 'error' );
// 'info' — for non-critical notifications (e.g. conflict auto-cleanup)
```

---

## Debug & Diagnostics

### Debug Logging

Enable detailed logging by adding to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'HTAX_DEBUG_VERBOSE', true ); // Adds backtraces to errors, and to debug messages that request one
define( 'HTAX_QUERY_MONITOR', true ); // Logs queries over 10ms that mention htax or term
```

Logs are written to `wp-content/debug.log`.

### Log Helpers

Within plugin code, use:

```php
htax_log_debug( 'Message', [ 'context_key' => $value ] );
htax_log_error( 'Error message', [ 'term_id' => $term_id, 'error' => $e->getMessage() ] );
```

`htax_log_debug()` only writes when `WP_DEBUG` and `WP_DEBUG_LOG` are both true. `htax_log_error()` always writes. `HTAX_QUERY_MONITOR` starts monitoring at the first `htax_log_debug()` call in a request.

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

Append `?htax_debug=1` to any term archive URL while logged in as an administrator to display a debug panel at the bottom of the page showing:

- Current term
- Included term IDs and names
- The archive's tax query
- Posts found
- Contributing terms on the current page
- Sticky post IDs

When `WP_DEBUG` is on, a second panel adds the term ID, taxonomy, expanded term IDs and names, posts found, and the SQL query.

### Rebuilding the Search Index

The search index covers all post types returned by `htax_get_indexed_post_types()` (default: posts and pages; configurable via Settings → Search → General). The rebuild processes all published posts of those types.

The index can be rebuilt from:

- **Admin UI:** Settings > HyperTaxonomy > Search > General > Rebuild Search Index
- **AJAX action:** `wp_ajax_htax_rebuild_indexes` (empties `wp_htax_post_text` and the built-in model's rows in `wp_htax_search_terms`, returns total post count) / `wp_ajax_htax_bulk_rebuild` (processes one batch of 50 posts through both tables)

To trigger a single-post reindex programmatically:

```php
htax_build_post_search_index( $post_id );
```
