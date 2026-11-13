---
title: Actions & Filters
parent: Developer Reference
nav_order: 4
---

# Actions & Filters
{: .no_toc }

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Actions Fired by HyperTaxonomy

These actions fire when relationship data changes. Hook into them to react to changes — for example, to invalidate your own caches or trigger custom processing.

**Important:** Cache is reloaded before these actions fire, so reading relationship data within a callback returns the **new** state. The `*_changed` relationship actions pass old and new values when the term edit screen saves. `htax_includes_changed`, `htax_containers_changed`, and `htax_excludes_changed` pass `null` for both when the change comes from `htax_add_term_relationship()`, `htax_remove_term_relationship()`, a merge, or hierarchy cleanup, so a callback that uses those arrays must handle `null`.

#### Inclusions changed

`htax_includes_changed`

Fires when a term's included terms change.

```php
add_action( 'htax_includes_changed', function( $term_id, $taxonomy, $new_included, $old_included ) {
    // $term_id      — int, the term whose inclusions changed
    // $taxonomy     — string
    // $new_included — array of term IDs now included
    // $old_included — array of term IDs previously included
}, 10, 4 );
```

#### Terms including this term changed

`htax_containers_changed`

Fires when the set of terms that contain this term changes — i.e. when another term starts or stops including this term's posts in its archive.

```php
add_action( 'htax_containers_changed', function( $term_id, $taxonomy, $new_containers, $old_containers ) {
    // $term_id        — int, the term whose container set changed
    // $taxonomy       — string
    // $new_containers — array of term IDs that now include this term
    // $old_containers — array of term IDs that previously included this term
}, 10, 4 );
```

#### Exclusions changed

`htax_excludes_changed`

Fires when the set of terms explicitly excluded from a term's archive changes. Excluded terms are suppressed even when they would otherwise be pulled in by inclusions or group memberships.

```php
add_action( 'htax_excludes_changed', function( $term_id, $taxonomy, $new_excluded, $old_excluded ) {
    // $term_id      — int, the term whose exclusion list changed
    // $taxonomy     — string
    // $new_excluded — array of term IDs now excluded
    // $old_excluded — array of term IDs previously excluded
}, 10, 4 );
```

#### Included groups changed

`htax_included_groups_changed`

Fires when the Term Groups a term includes change. Saving a group's edit screen fires it once for each term whose inclusion of that group changed.

```php
add_action( 'htax_included_groups_changed', function( $term_id, $taxonomy, $new_groups, $old_groups ) {
    // $new_groups / $old_groups — arrays of group term IDs
}, 10, 4 );
```

#### Custom inclusion types changed

`htax_custom_inclusions_changed`

Fires after a term save changes rows of an inclusion-based custom relationship type (one registered with `core_type => 'includes'`). These rows feed the same inclusion and container sets as built-in inclusions, so `$affected_term_ids` lists the terms whose container sets changed — the included side of every changed row (for group rows, the group's member terms). HyperTaxonomy's search index reindexes posts with those terms.

```php
add_action( 'htax_custom_inclusions_changed', function( $term_id, $taxonomy, $affected_term_ids ) {
    // $term_id           — int, the term that was saved
    // $affected_term_ids — int[], terms whose container sets changed
}, 10, 3 );
```

#### Container groups changed

`htax_container_groups_changed`

Fires when a term's container groups change. A term's posts appear in the archives of the members of its container groups. Saving a group's edit screen fires it once for each term added to or removed from that group's container rows.

```php
add_action( 'htax_container_groups_changed', function( $term_id, $taxonomy, $new_groups, $old_groups ) {
    // $term_id    — int, the term whose container groups changed
    // $taxonomy   — string
    // $new_groups — array of group term IDs now set as container groups
    // $old_groups — array of group term IDs previously set as container groups
}, 10, 4 );
```

#### Group memberships changed

`htax_term_groups_changed`

Fires when a term's own group memberships change — i.e. the groups the term belongs to as a member, which determines which terms' archives include it via group-based inclusion. Saving a group's member list fires it once for each term that joined or left the group.

```php
add_action( 'htax_term_groups_changed', function( $term_id, $taxonomy, $new_groups, $old_groups ) {
    // $term_id    — int, the term whose group memberships changed
    // $taxonomy   — string
    // $new_groups — array of group term IDs the term now belongs to
    // $old_groups — array of group term IDs the term previously belonged to
}, 10, 4 );
```

#### Redirect set or changed

`htax_redirect_changed`

Fires when a term's redirect target is set or changed. `$old_target` is `null` the first time a redirect is set; on subsequent target changes it is the previous `WP_Term` object.

```php
add_action( 'htax_redirect_changed', function( $term_id, $taxonomy, $new_target_id, $new_target_taxonomy, $old_target ) {
    // $old_target — WP_Term object of the previous target, or null if no previous redirect
}, 10, 5 );
```

#### Redirect removed

`htax_redirect_removed`

Fires when a term's redirect is removed and its archive is restored.

```php
add_action( 'htax_redirect_removed', function( $term_id, $taxonomy, $old_target_id, $old_target_taxonomy ) {
    // $term_id              — int, the term that is no longer a redirect
    // $taxonomy             — string
    // $old_target_id        — int, the term ID that was being redirected to
    // $old_target_taxonomy  — string, the taxonomy of the old target
}, 10, 4 );
```

#### Visibility changed

`htax_term_visibility_changed`

Fires when a term's hidden/visible status changes. During a cascade (hiding or revealing a term with descendants), this action fires **once per affected descendant** — not once for the whole operation. If your callback does expensive work, guard against repeated calls for the same term within a single request.

```php
add_action( 'htax_term_visibility_changed', function( $term_id, $taxonomy ) {
    // Term visibility has changed — update any derived data
}, 10, 2 );
```

#### Before a merge

`htax_before_merge_terms`

Fires immediately before HyperTaxonomy begins merging source terms into a target term — before post reassignment, relationship transfer, and deletion. This hook fires **before** HT's database transaction starts.

Use this hook to snapshot any per-term data you want to migrate to the target. This includes both term meta (which `wp_delete_term()` deletes after the transaction commits) and data in separate tables (which survives `wp_delete_term()` but will be cleaned up by your own `deleted_term` handler). Snapshot everything here, before any of it is touched.

```php
add_action( 'htax_before_merge_terms', function( $source_ids, $target_id, $taxonomy, $create_redirect ) {
    // $source_ids      — int[], source term IDs about to be merged and deleted
    // $target_id       — int, target term ID
    // $taxonomy        — string
    // $create_redirect — bool, whether redirect stubs will be created for each source
}, 10, 4 );
```

#### After a merge

`htax_after_merge_terms`

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

#### Term converted to another taxonomy

`htax_term_converted`

Fires when a term finishes converting to another taxonomy, or when a converted term is restored. `$old_term_id` is the redirect stub or the deleted term, which may no longer exist. Use it to update records keyed by taxonomy.

```php
add_action( 'htax_term_converted', function( $new_term_id, $old_taxonomy, $new_taxonomy, $old_term_id ) {
    // $new_term_id  — int, the term now in $new_taxonomy
    // $old_taxonomy — string
    // $new_taxonomy — string
    // $old_term_id  — int
}, 10, 4 );
```

#### Relationship row added or removed

`htax_relationship_saved` / `htax_relationship_deleted`

Fire after `htax_add_term_relationship()` adds a row or `htax_remove_term_relationship()` removes one. For types mapped to `includes`, HyperTaxonomy queues a search index update for both terms. See [Custom Relationship Types](custom-taxonomy.html#custom-relationship-types).

```php
add_action( 'htax_relationship_saved', function( $source_term_id, $target_term_id, $type_slug ) {
    // Same arguments for htax_relationship_deleted
}, 10, 3 );
```

#### Register relationship types

`htax_relationship_types`

Fires on `init` at priority 0, after the built-in relationship types are registered. Call `htax_register_relationship_type()` here. See [Custom Relationship Types](custom-taxonomy.html#custom-relationship-types).

#### Relationship Types page footer

`htax_relationship_types_admin_footer`

Fires at the bottom of the **Terms → Term Relationship Types** admin page. Use it to add your own UI.

#### Archive contents changed

`htax_term_archive_changed`

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

#### Strengths missing for a post

`htax_term_strength_missing`

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

#### Strengths stored or removed

`htax_term_strengths_updated`

Fires after an external model's strengths are stored with `htax_set_term_strengths()` or removed with `htax_remove_term_strengths()` (including Settings → Search → Clear Scores). Use it to invalidate anything that caches strength-based results.

```php
add_action( 'htax_term_strengths_updated', function( $post_id, $model, $term_ids ) {
    // $post_id is 0 when the model's strengths were removed for every post;
    // $term_ids is empty when all of the post's (or every post's) terms were affected.
}, 10, 3 );
```

#### External URIs or locations changed

`htax_term_uris_changed` / `htax_term_locations_changed`

Fire with `int $term_id` when a term's external URIs or locations change. HyperTaxonomy re-fires these from TermConnect's `tc_term_uris_changed` and As the Crow Flies' `atcf_term_location_changed`, so a plugin that reads `htax_get_term_uris()` or `htax_get_term_locations()` can clear its caches without depending on either plugin.

## Available Filters

#### Show hidden terms

`htax_show_hidden_terms`

Override whether hidden terms appear in a post's displayed terms and term links (`get_the_terms` and the term link filters). Return true to show them, false to hide them, or the passed `$can_see` value to defer to the default logic. It doesn't change search, archives, or the REST API, which use `htax_can_see_hidden_terms()` directly.

```php
add_filter( 'htax_show_hidden_terms', function( $can_see, $taxonomy ) {
    // Return true/false or pass through $can_see
    return $can_see;
}, 10, 2 );
```

#### Hidden terms in a post's term list

`htax_remove_hidden_terms_from_list`

Whether a hidden term is removed from a post's term list (`get_the_terms`) for a viewer who can't see it. Defaults to `true`. Return `false` to keep the term in the list; it still carries an `is_htax_hidden` property.

```php
add_filter( 'htax_remove_hidden_terms_from_list', function( $remove, $term, $taxonomy ) {
    return $remove;
}, 10, 3 );
```

#### Append to the archive description

`htax_archive_description_append`

Filters HTML appended inside HyperTaxonomy's archive description block (`.htax-archive-description`), after the term description. Applies when HyperTaxonomy renders the description itself (the taxonomy has **Archive Customization** enabled and the term has **Show Description** checked); TermConnect uses it for its "See also" links and the link-suggestion form. The value is output as-is — like `the_content` filter output, escape everything you add.

```php
add_filter( 'htax_archive_description_append', function( $append, $term ) {
    return $append . '<p class="my-note">' . esc_html( $term->name ) . '</p>';
}, 10, 2 );
```

#### Expanded term IDs

`htax_expanded_term_ids`

Filter the expanded term ID list returned by `htax_get_expanded_term_ids()`. See [Integration API](php-api.html#integration-api) for full documentation.

```php
add_filter( 'htax_expanded_term_ids', function( $ids, $term_id, $taxonomy ) {
    return $ids;
}, 10, 3 );
```

#### Expansion-aware post count

`htax_term_post_count`

Filter the expansion-aware post count for a single term, fired by the `get_terms` filter when `expand_secondary_queries` is enabled. See [Integration API](php-api.html#integration-api) for full documentation.

```php
add_filter( 'htax_term_post_count', function( $count, $term_id, $taxonomy, $query_context ) {
    return $count;
}, 10, 4 );
```

#### Built-in strength weights

`htax_strength_field_weights`

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

#### Turn off front-end styles

`htax_disable_frontend_styles`

Return `true` to stop HyperTaxonomy printing its inline front-end styles, for a theme that styles those elements itself.

```php
add_filter( 'htax_disable_frontend_styles', '__return_true' );
```

#### Front-end styles

`htax_frontend_styles`

Filters the inline CSS HyperTaxonomy prints on the front end. HTML tags are stripped from the result.

```php
add_filter( 'htax_frontend_styles', function( $css ) {
    return $css . ' .htax-redirect-term { opacity: 1; }';
} );
```

#### HTML allowed in term descriptions

`htax_allowed_description_html`

Filters the HTML tags and attributes allowed in term descriptions (the `wp_kses` allowed-tags array).

```php
add_filter( 'htax_allowed_description_html', function( $allowed_tags ) {
    $allowed_tags['figure'] = array( 'class' => true );
    return $allowed_tags;
} );
```

#### Sync a remote relationship type

`htax_sync_remote_relationship_types`

Handles the **Sync** action for a remote relationship type on the Term Relationship Types admin page, so the fetch can live in another plugin (TermConnect hooks it). Receives a result array and the source site URL; return `[ 'success' => bool, 'message' => string ]`.

#### Provider filters

`htax_term_uris`, `htax_term_locations`, `htax_term_external_posts`, `htax_peer_sites`

Filters that let a provider plugin supply external URIs, locations, external posts, and peer sites to other plugins on the same site. See [Provider Filters](php-api.html#provider-filters).

#### Proximity search condition

`htax_search_proximity_where`

SQL condition for `near:` tokens, added to the WHERE clause of PowerSearch and search widget queries before ORDER BY / LIMIT. The As the Crow Flies integration builds the location condition through it. Signature `( string $where, array $proximity, string $post_column ): string` — see [As the Crow Flies integration](atcf.html#proximity-search-condition).

#### Proximity search scoring

`htax_search_proximity_relevance`

SQL expression scoring a post for a search made only of `near:` tokens (nearest first). The As the Crow Flies integration supplies distance scores. Signature `( string $sql, array $proximity, string $post_column ): string` — see [As the Crow Flies integration](atcf.html#proximity-search-scoring).

#### Search fields before the query runs

`htax_pre_search_fields`

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

#### Allowed search field types

`htax_allowed_field_types`

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

#### Visitor IP for rate limiting

`htax_rate_limit_client_ip`

Filters the IP address that PowerSearch and search widget requests from visitors are rate-limited by (logged-in users are limited per user). By default it is `REMOTE_ADDR`. Behind a reverse proxy or CDN that doesn't pass the visitor's address in `REMOTE_ADDR`, every visitor shares the proxy's address and therefore one limit; return the address from the header your proxy sets, after checking that the request came from the proxy.

```php
add_filter( 'htax_rate_limit_client_ip', function( string $ip, string $action ): string {
    $trusted_proxies = array( '203.0.113.10' );
    if ( in_array( $ip, $trusted_proxies, true ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $forwarded = trim( explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )[0] );
        return filter_var( $forwarded, FILTER_VALIDATE_IP ) ? $forwarded : $ip;
    }
    return $ip;
}, 10, 2 );
```

**Parameters:** `string $ip` — `REMOTE_ADDR`, or `''` when it isn't a valid IP address; `string $action` — the AJAX action.
**Returns:** `string` — the address to count requests against.
