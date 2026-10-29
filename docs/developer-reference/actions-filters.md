---
title: Actions & Filters
parent: Developer Reference
nav_order: 4
---

## 4. Actions & Filters

### Actions Fired by HyperTaxonomy

These actions fire when relationship data changes. Hook into them to react to changes — for example, to invalidate your own caches or trigger custom processing.

**Important:** All these actions pass both old and new values. Cache is eagerly reloaded before actions fire, so reading relationship data within a callback will return the **new** state.

#### `htax_includes_changed`

Fires when a term's included terms change.

```php
add_action( 'htax_includes_changed', function( $term_id, $taxonomy, $new_included, $old_included ) {
    // $term_id      — int, the term whose inclusions changed
    // $taxonomy     — string
    // $new_included — array of term IDs now included
    // $old_included — array of term IDs previously included
}, 10, 4 );
```

#### `htax_containers_changed`

Fires when the set of terms that contain this term changes — i.e. when another term starts or stops including this term's posts in its archive.

```php
add_action( 'htax_containers_changed', function( $term_id, $taxonomy, $new_containers, $old_containers ) {
    // $term_id        — int, the term whose container set changed
    // $taxonomy       — string
    // $new_containers — array of term IDs that now include this term
    // $old_containers — array of term IDs that previously included this term
}, 10, 4 );
```

#### `htax_excludes_changed`

Fires when the set of terms explicitly excluded from a term's archive changes. Excluded terms are suppressed even when they would otherwise be pulled in by inclusions or group memberships.

```php
add_action( 'htax_excludes_changed', function( $term_id, $taxonomy, $new_excluded, $old_excluded ) {
    // $term_id      — int, the term whose exclusion list changed
    // $taxonomy     — string
    // $new_excluded — array of term IDs now excluded
    // $old_excluded — array of term IDs previously excluded
}, 10, 4 );
```

#### `htax_included_groups_changed`

Fires when the Term Groups a term includes change.

```php
add_action( 'htax_included_groups_changed', function( $term_id, $taxonomy, $new_groups, $old_groups ) {
    // $new_groups / $old_groups — arrays of group term IDs
}, 10, 4 );
```

#### `htax_custom_inclusions_changed`

Fires after a term save changes rows of an inclusion-based custom relationship type (one registered with `core_type => 'includes'`). These rows feed the same inclusion and container sets as built-in inclusions, so `$affected_term_ids` lists the terms whose container sets changed — the included side of every changed row (for group rows, the group's member terms). HyperTaxonomy's search index reindexes posts with those terms.

```php
add_action( 'htax_custom_inclusions_changed', function( $term_id, $taxonomy, $affected_term_ids ) {
    // $term_id           — int, the term that was saved
    // $affected_term_ids — int[], terms whose container sets changed
}, 10, 3 );
```

#### `htax_container_groups_changed`

Fires when the set of Term Groups whose members' posts appear in this term's archive changes. Terms in a container group contribute their posts to the archive of any term that references that group as a container.

```php
add_action( 'htax_container_groups_changed', function( $term_id, $taxonomy, $new_groups, $old_groups ) {
    // $term_id    — int, the term whose container groups changed
    // $taxonomy   — string
    // $new_groups — array of group term IDs now set as container groups
    // $old_groups — array of group term IDs previously set as container groups
}, 10, 4 );
```

#### `htax_term_groups_changed`

Fires when a term's own group memberships change — i.e. the groups the term belongs to as a member, which determines which terms' archives include it via group-based inclusion.

```php
add_action( 'htax_term_groups_changed', function( $term_id, $taxonomy, $new_groups, $old_groups ) {
    // $term_id    — int, the term whose group memberships changed
    // $taxonomy   — string
    // $new_groups — array of group term IDs the term now belongs to
    // $old_groups — array of group term IDs the term previously belonged to
}, 10, 4 );
```

#### `htax_redirect_changed`

Fires when a term's redirect target is set or changed. `$old_target` is `null` the first time a redirect is set; on subsequent target changes it is the previous `WP_Term` object.

```php
add_action( 'htax_redirect_changed', function( $term_id, $taxonomy, $new_target_id, $new_target_taxonomy, $old_target ) {
    // $old_target — WP_Term object of the previous target, or null if no previous redirect
}, 10, 5 );
```

#### `htax_redirect_removed`

Fires when a term's redirect is removed and its archive is restored.

```php
add_action( 'htax_redirect_removed', function( $term_id, $taxonomy, $old_target_id, $old_target_taxonomy ) {
    // $term_id              — int, the term that is no longer a redirect
    // $taxonomy             — string
    // $old_target_id        — int, the term ID that was being redirected to
    // $old_target_taxonomy  — string, the taxonomy of the old target
}, 10, 4 );
```

#### `htax_term_visibility_changed`

Fires when a term's hidden/visible status changes. During a cascade (hiding or revealing a term with descendants), this action fires **once per affected descendant** — not once for the whole operation. If your callback does expensive work, guard against repeated calls for the same term within a single request.

```php
add_action( 'htax_term_visibility_changed', function( $term_id, $taxonomy ) {
    // Term visibility has changed — update any derived data
}, 10, 2 );
```

#### `htax_before_merge_terms`

Fires immediately before HyperTaxonomy begins merging source terms into a target term — before post reassignment, relationship transfer, and deletion. This hook fires **before** HT's database transaction starts.

Use this hook to snapshot any per-term data you want to migrate to the target. This includes both term meta (which `wp_delete_term()` deletes during the transaction) and data in separate tables (which survives `wp_delete_term()` but will be cleaned up by your own `deleted_term` handler). Snapshot everything here, before any of it is touched.

```php
add_action( 'htax_before_merge_terms', function( $source_ids, $target_id, $taxonomy, $create_redirect ) {
    // $source_ids      — int[], source term IDs about to be merged and deleted
    // $target_id       — int, target term ID
    // $taxonomy        — string
    // $create_redirect — bool, whether redirect stubs will be created for each source
}, 10, 4 );
```

#### `htax_after_merge_terms`

Fires after a successful merge. Post reassignments, HyperTaxonomy relationship transfers, source term deletions, redirect stubs, search index requeue, and cache invalidation are all complete at this point.

Important: source terms have been permanently deleted and their WordPress term meta is gone. Data stored in separate database tables (not term meta) is still present and can be moved to the target term.

```php
add_action( 'htax_after_merge_terms', function( $source_ids, $target_id, $taxonomy, $result ) {
    // $source_ids — int[], source term IDs (now deleted; term meta is gone)
    // $target_id  — int, target term ID
    // $taxonomy   — string
    // $result     — array {
    //     posts_reassigned  int  Total post assignments moved.
    //     terms_deleted     int  Number of source terms deleted.
    //     redirects_created int  Number of redirect stubs created.
    //     target_id         int  Actual target term ID used.
    // }
}, 10, 4 );
```

**Typical pattern for companion plugins:** snapshot source data in `htax_before_merge_terms`, then apply it to the target in `htax_after_merge_terms`. Register a `deleted_term` handler for all term deletes — source terms deleted during a merge fire `deleted_term` the same as a standalone delete, so no suppression is needed. Also register a `delete_term_taxonomy` handler at an earlier priority for bulk taxonomy operations, where `deleted_term` does not fire.

#### `htax_term_archive_changed`

Fires whenever a term's archive composition changes. Use it to invalidate any derived data that depends on a term's archive.

```php
add_action( 'htax_term_archive_changed', function( $term_id, $taxonomy ) {
    // Archive composition for $term_id in $taxonomy has changed
}, 10, 2 );
```

**When it fires:**
- Any HyperTaxonomy relationship saved or deleted (inclusions, exclusions, container groups, included groups)
- A term first becomes a redirect (its own archive disappears); changing an existing redirect's target does not fire — the target's archive is unaffected and the source had no archive already
- A redirect is removed (the term's archive is restored)
- A term's WordPress parent changes (fires for old and new parent ancestor chains)
- A post is assigned to or removed from a term (fires for the term and its containers/ancestors)
- A post is published or unpublished (same expansion)
- A post's sticky-term assignments change

HyperTaxonomy uses this action internally to invalidate the `htax_contrib_` paginated archive cache.

#### `htax_term_strength_missing`

Fires when a post is (re)indexed and a registered external strength model has no strength for some of the post's directly assigned terms — a newly published post, a newly assigned term, or a model registered after the post was last scored. A model's provider hooks this to score the terms and submit them with `htax_set_term_strengths()`. See [Strength Models](php-api.html#strength-models).

```php
add_action( 'htax_term_strength_missing', function( $post_id, $term_ids, $model ) {
    if ( 'myplugin:my-model' !== $model ) {
        return;
    }
    // Fires during background reindexes and full rebuilds: defer slow work
    // (e.g. remote API calls) instead of scoring inline.
    wp_schedule_single_event( time(), 'myplugin_score_post', array( $post_id, $term_ids ) );
}, 10, 3 );
```

**Parameters:**
- `$post_id` (`int`)
- `$term_ids` (`int[]`) — directly assigned term IDs without a strength from `$model`
- `$model` (`string`) — the registered model key

#### `htax_term_strengths_updated`

Fires after an external model's strengths are stored with `htax_set_term_strengths()` or removed with `htax_remove_term_strengths()` (including Settings → Search → Clear Scores). Use it to invalidate anything that caches strength-based results.

```php
add_action( 'htax_term_strengths_updated', function( $post_id, $model, $term_ids ) {
    // $post_id is 0 when the model's strengths were removed for every post;
    // $term_ids is empty when all of the post's (or every post's) terms were affected.
}, 10, 3 );
```

### Available Filters

#### `htax_show_hidden_terms`

Override the hidden terms visibility check. Return true to force visibility, false to force hidden, or the passed `$can_see` value to defer to the default logic.

```php
add_filter( 'htax_show_hidden_terms', function( $can_see, $taxonomy ) {
    // Return true/false or pass through $can_see
    return $can_see;
}, 10, 2 );
```

#### `htax_archive_description_append`

Filters HTML appended inside HyperTaxonomy's archive description block (`.htax-archive-description`), after the term description. Applies when HyperTaxonomy renders the description itself (the taxonomy has **Archive Customization** enabled and the term has **Show Description** checked); TermConnect uses it for its "See also" links and the link-suggestion form. The value is output as-is — like `the_content` filter output, escape everything you add.

```php
add_filter( 'htax_archive_description_append', function( $append, $term ) {
    return $append . '<p class="my-note">' . esc_html( $term->name ) . '</p>';
}, 10, 2 );
```

#### `htax_expanded_term_ids`

Filter the expanded term ID list returned by `htax_get_expanded_term_ids()`. See [Integration API](#3-integration-api) for full documentation.

```php
add_filter( 'htax_expanded_term_ids', function( $ids, $term_id, $taxonomy ) {
    return $ids;
}, 10, 3 );
```

#### `htax_term_post_count`

Filter the expansion-aware post count for a single term, fired by the `get_terms` filter when `expand_secondary_queries` is enabled. See [Integration API](#3-integration-api) for full documentation.

```php
add_filter( 'htax_term_post_count', function( $count, $term_id, $taxonomy, $query_context ) {
    return $count;
}, 10, 4 );
```

#### `htax_strength_field_weights`

Override the TF formula coefficients used by `htax_calculate_direct_term_strength()` for the built-in strength model (`htax_tf`). To supply strengths from another source, register a separate model instead — see [Strength Models](php-api.html#strength-models). Coefficients must sum to ≤ 1.0 so the maximum achievable strength equals 1.0.

```php
add_filter( 'htax_strength_field_weights', function( $weights ) {
    // Increase title importance, keep floor the same.
    $weights['title']   = 0.30;
    $weights['excerpt'] = 0.15;
    $weights['body']    = 0.35;
    // floor + title + excerpt + body = 0.20 + 0.30 + 0.15 + 0.35 = 1.00 ✓
    return $weights;
} );
```

**Default values:** `['floor' => 0.20, 'body' => 0.40, 'excerpt' => 0.20, 'title' => 0.20]`

#### `htax_search_proximity_where`

SQL condition for `near:` tokens, added to the WHERE clause of PowerSearch and search widget queries before ORDER BY / LIMIT. The As the Crow Flies integration builds the location condition through it. Signature `( string $where, array $proximity, string $post_column ): string` — see [As the Crow Flies integration](atcf.html#htax_search_proximity_where-filter).

#### `htax_search_proximity_relevance`

SQL expression scoring a post for a search made only of `near:` tokens (nearest first). The As the Crow Flies integration supplies distance scores. Signature `( string $sql, array $proximity, string $post_column ): string` — see [As the Crow Flies integration](atcf.html#htax_search_proximity_relevance-filter).

#### `htax_pre_search_fields`

Filters the search query spec before `get_posts_by_custom_sql()` runs in the AJAX search handler. Use this to resolve or transform custom field data — for example, converting a distance field into a set of term IDs before the SQL query is built.

The `$query` parameter is a hierarchical group spec:

```
[
    'relation'    => 'AND',           // top-level AND/OR
    'fields'      => [                // direct child fields (field_name => field_data)
        'my_field' => [
            'type'     => 'distance', // 'hidden', 'pills', 'checklist', or custom type
            'taxonomy' => 'post_tag',
            // ... field-specific keys
        ],
    ],
    'groups'      => [                // nested [ht_field_group] children (recursive)
        [ 'relation' => 'OR', 'fields' => [...], 'groups' => [...] ],
    ],
    'text_search' => [                // present only when a text field has a value
        'value'     => 'search text',
        'search_in' => 'all',
        'min_length' => 3,
    ],
]
```

```php
add_filter( 'htax_pre_search_fields', function( array $query ): array {
    if ( isset( $query['fields']['htax_distance'] ) ) {
        $field    = $query['fields']['htax_distance'];
        $term_ids = my_plugin_get_term_ids_within_radius(
            $field['postal_code'],
            $field['radius'],
            $field['unit']
        );
        unset( $query['fields']['htax_distance'] );
        if ( ! empty( $term_ids ) ) {
            $query['fields']['my_distance_resolved'] = [
                'type'     => 'hidden',
                'taxonomy' => $field['taxonomy'],
                'terms'    => $term_ids,
                'match'    => 'any',
            ];
        }
    }
    return $query;
} );
```

**Parameters:** `array $query` — the search query spec.
**Returns:** `array` — the modified query spec.

#### `htax_allowed_field_types`

Filters the list of allowed field type slugs in the search widget AJAX handler. The handler rejects any submitted field whose type is not in this list. Use this filter to register custom field types so the AJAX handler accepts them.

```php
add_filter( 'htax_allowed_field_types', function( array $types ): array {
    $types[] = 'distance';
    return $types;
} );
```

**Parameters:** `string[] $types` — the current list of allowed type slugs.
**Default types:** `['pills', 'checklist', 'text', 'hidden']`
**Returns:** `string[]` — the modified list.

---

