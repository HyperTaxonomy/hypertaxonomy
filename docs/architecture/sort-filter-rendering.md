---
title: Sort & Filter Rendering (DOM Structures)
parent: Architecture
nav_order: 6
---

# Sort & Filter Controls — Rendering, DOM Structures & Events

This page documents the PHP rendering functions, the HTML/DOM structures they emit, and the associated JavaScript events for the sort order picker and the filter options panel. These DOM-tree dumps are low-level structural reference for theme developers.

Both the sort order picker and the filter panel are rendered via PHP functions that theme templates can call directly, and both fire JavaScript events that custom code can listen to.

### Sort Order Picker

#### Rendering from PHP

Call `htax_render_sort_order_picker()` anywhere in a template. The function enqueues its own CSS and JS.

```php
echo htax_render_sort_order_picker( array(
    'fields'        => 'date,modified,title,author,relevance', // comma string or array
    'state'         => 'expanded',   // 'expanded' | 'collapsed'
    'action'        => 'navigate',   // 'navigate' | 'submit' | ''
    'default_value' => 'date:desc',  // initial serialized value
    'input_name'    => 'htax_orderby',
    'text_collapsed' => __( 'Sort', 'my-theme' ),
    'text_expanded'  => __( 'Sort', 'my-theme' ),
    'class'         => 'my-sort-picker',
) );
```

| Argument | Description |
|----------|-------------|
| `fields` | Fields to offer. Valid: `date`, `modified`, `title`, `author`, `comment_count`, `last_comment`, `last_activity`, `relevance`, `ID`. |
| `state` | Initial visible state. |
| `action` | `navigate` — appends `?htax_orderby=…` and navigates. `submit` — submits the ancestor `<form>`. Empty — fires `htax:sort-change` event only (use for AJAX contexts). |
| `default_value` | Initial value, e.g. `date:desc,title:asc`. In navigate mode this is also auto-read from `$_GET['htax_orderby']` when empty. |
| `input_name` | The hidden `<input>` name forwarded on form submit. |
| `class` | Extra CSS class on the root element. |

#### HTML structure

```
div.htax-sort-order-root[data-htax-sort-root][data-htax-sort-action][data-state]
  button.htax-sort-toggle
    span.htax-sort-toggle-text
  div.htax-sort-order-container[data-htax-sort-container]
    <!-- active sort pills rendered by JS -->
    button.htax-sort-order-add[data-htax-sort-add]
  div.htax-sort-order-picker[data-htax-sort-picker]  ← field picker popover
    button.htax-sort-picker-pill[data-field]  ← one per available field
  input[type=hidden][data-htax-sort-value]    ← serialized current value
```

Collapsed/expanded state is toggled via `data-state="collapsed|expanded"` on the root. Default styles live in `assets/css/htax-sort-order.css`; override any rule by targeting `.htax-sort-order-root` in your theme stylesheet.

#### JavaScript event

When the sort value changes, the picker fires a jQuery event on the root element:

```javascript
$(document).on('htax:sort-change', '[data-htax-sort-root]', function(e, newValue) {
    // newValue — serialized sort string, e.g. "date:desc,title:asc"
    console.log('Sort changed to:', newValue);
});
```

`newValue` is the full serialized value (same format as the `orderby` shortcode attribute). In navigate and submit modes this event fires before the navigation/submit, so you can `e.stopImmediatePropagation()` to suppress the built-in behavior and implement your own.

#### Reading the current value from JS

```javascript
const root  = document.querySelector('[data-htax-sort-root]');
const value = root.querySelector('[data-htax-sort-value]').value; // "date:desc"
```

Or via the JS API (if `htax-sort-order.js` is loaded):

```javascript
const instance = window.htaxSortOrder.init(root, { /* opts */ });
const value    = instance.getValue(); // "date:desc"
instance.setValue('title:asc');       // set programmatically
```

---

### Filter Options Panel

#### Rendering from PHP

Call `htax_render_filter_options()` with resolved configs. For most use cases, build configs with `htax_resolve_filter_configs()` first.

```php
// 1. Define raw configs (same structure as the child shortcodes produce).
$raw_configs = array(
    array(
        'type'     => 'terms',
        'taxonomy' => 'post_tag',
        'label'    => __( 'Tags', 'my-theme' ),
        'match'    => 'any',
        'primary'  => true,
        'include_group_options' => '',
        'exclude_group_options' => '',
        'include_term_options'  => '',
        'exclude_term_options'  => '',
        'include_groups'        => '',
        'exclude_groups'        => '',
        'include_terms'         => '',
        'exclude_terms'         => '',
        'display_type'          => '', // '' = auto (pills for flat, checklist for hierarchical)
    ),
    array(
        'type'      => 'date_published',
        'label'     => __( 'Date', 'my-theme' ),
        'date_type' => 'range', // 'range' | 'single'
    ),
    array(
        'type'  => 'author',
        'label' => __( 'Author', 'my-theme' ),
    ),
);

// 2. Read current filter state from URL (navigate mode) or pass empty array.
$filter_state = function_exists( 'htax_parse_filter_state_navigate' )
    ? htax_parse_filter_state_navigate( $_GET['htax_filter'] ?? array() )
    : array();

// 3. Resolve configs (fetches terms, applies pool rules, merges state).
$configs = htax_resolve_filter_configs(
    $raw_configs,
    $filter_state,
    null,        // $archive_exclude_term — pass get_queried_object() on archive pages
    array()      // $primary_seed_ids — term IDs to pre-select in the primary filter
);

// 4. Render.
echo htax_render_filter_options( $configs, array(
    'action'        => 'navigate', // 'navigate' | 'submit' | ''
    'state'         => 'expanded',
    'label'         => __( 'Filter', 'my-theme' ),
    'text_collapsed' => __( 'Filter', 'my-theme' ),
    'text_expanded'  => __( 'Filter', 'my-theme' ),
    'class'         => 'my-filter-panel',
), $filter_state );
```

For inline shorthand (when building the panel alongside a result shortcode), use `htax_parse_inline_filters()` to convert a `filters=`/`filter_options=` string into raw configs:

```php
$raw_configs = htax_parse_inline_filters( 'post_tag,date_published,author', 'group:3,,' );
```

#### On archive pages

Pass the current queried object as `$archive_exclude_term` so the archive's own term is automatically removed from the option pool of any filter whose taxonomy matches the archive's:

```php
$configs = htax_resolve_filter_configs(
    $raw_configs,
    $filter_state,
    get_queried_object(), // WP_Term — auto-excluded from its own taxonomy's filter pool
    array()
);
```

#### HTML structure

```
div.htax-filter-options.htax-filter-state-{expanded|collapsed}[data-htax-filter-root]
  div.htax-filter-header
    button.htax-filter-toggle
    button.htax-filter-clear          ← hidden when state matches initial state

  div.htax-filter-body
    ── term filter (pills mode) ──
    div.htax-filter-group.htax-filter-terms.htax-filter-pills-mode[data-type=terms][data-taxonomy]
      div.htax-filter-group-label
      div.htax-filter-pills
        button.htax-filter-pill[.htax-active][data-term-id]
      input[type=hidden].htax-filter-value-input   ← comma-separated active term IDs

    ── term filter (checklist mode) ──
    div.htax-filter-group.htax-filter-terms.htax-filter-checklist-mode[data-type=terms][data-taxonomy]
      div.htax-filter-group-label
      div.htax-filter-checklist
        label.htax-filter-checklist-item
          input[type=checkbox].htax-filter-checkbox[data-term-id]
          span.htax-filter-term-name

    ── date filter ──
    div.htax-filter-group.htax-filter-date-group[data-type=date_published|date_modified]
      div.htax-filter-group-label
      div.htax-filter-date-inputs
        input[type=date].htax-filter-date-from
        span.htax-filter-date-sep
        input[type=date].htax-filter-date-to

    ── author filter ──
    div.htax-filter-group.htax-filter-author-group[data-type=author]
      div.htax-filter-group-label
      div.htax-filter-checklist
        label.htax-filter-checklist-item
          input[type=checkbox].htax-filter-checkbox[data-author-id]
          span.htax-filter-author-name
```

Default styles live in `assets/css/htax-filter-options.css`. Target `.htax-filter-options` in your theme stylesheet to override.

The panel's initial and current state are stored as JSON in `data-htax-filter-initial` and `data-htax-filter-state` on the root element and are read by `htax-filter-options.js` on init.

#### JavaScript event

When any filter changes, the panel fires a jQuery event on the root element:

```javascript
$(document).on('htax:filter-change', '[data-htax-filter-root]', function(e, newState) {
    // newState — plain object, e.g.:
    // {
    //   post_tag:           { terms: [5, 12], match: 'any' },
    //   date_from:          '2024-01-01',
    //   date_to:            '2024-12-31',
    //   date_modified_from: '',
    //   date_modified_to:   '',
    //   author:             [1, 3],
    // }
    console.log('Filter changed:', newState);
});
```

In navigate and submit modes this fires before navigation/submit. In event-only mode (empty `action`) you handle the event yourself — e.g. to run a custom AJAX query:

```javascript
$(document).on('htax:filter-change', '.my-filter-panel[data-htax-filter-root]', function(e, state) {
    $.post( myAjax.url, {
        action:      'my_custom_search',
        nonce:       myAjax.nonce,
        htax_filter: JSON.stringify( state ),
    }, function( response ) {
        $('#my-results').html( response.data.html );
    } );
} );
```

#### Reading and writing filter state from JS

```javascript
// Read current state from data attribute (before any interaction):
const root  = document.querySelector('[data-htax-filter-root]');
const state = JSON.parse( root.dataset.htaxFilterState || '{}' );

// The FilterPanel instance is not exposed globally, but the state object
// on the element is kept in sync by the JS and is always current.
```

#### Applying filter state server-side

Use `htax_build_filter_sql()` to convert a parsed filter state into SQL WHERE clauses for custom queries. `pt` must be the alias for `wp_htax_post_text` in your query.

```php
$raw    = isset( $_POST['htax_filter'] ) ? wp_unslash( $_POST['htax_filter'] ) : '';
$state  = htax_parse_filter_state_ajax( $raw );  // from AJAX POST
// or:
$state  = htax_parse_filter_state_navigate( $_GET['htax_filter'] ?? array() ); // from URL

$extra_where = htax_build_filter_sql( $state );
// e.g. "AND pt.post_id IN (SELECT post_id FROM wp_htax_search_terms WHERE term_id IN (5,12))
//       AND pt.post_date_gmt >= '2024-01-01 00:00:00'"

// Append to your query's WHERE clause (pt must alias wp_htax_post_text):
$ptt = $wpdb->prefix . 'htax_post_text';
$sql = "SELECT pt.post_id FROM {$ptt} pt WHERE pt.post_type = 'post' $extra_where";
```

For archive pages (navigate mode), filter state is applied automatically via `pre_get_posts`. You do not need to call `htax_build_filter_sql()` in that context.

---

