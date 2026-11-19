---
title: Actions & Filters
parent: Developer Reference
nav_order: 8
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

**Important:** The affected caches are cleared before these actions reach other listeners, so reading relationship data within a callback returns the **new** state. The `*_changed` relationship actions pass old and new values when the term edit screen saves. `htax_includes_changed`, `htax_containers_changed`, and `htax_excludes_changed` pass `null` for both when the change comes from `htax_add_term_relationship()`, `htax_remove_term_relationship()`, a merge, or a change to a term's or Term Group's parent, so a callback that uses those arrays must handle `null`.

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

Fires when a term's own group memberships change — i.e. the groups the term belongs to as a member, which determines which terms' archives include it via group-based inclusion. Saving a group's member list fires it once for each term that joined or left the group, and a merge fires it for the target term when it gains the source terms' groups.

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

Fires when a term's redirect target is set or changed. `$old_target` is `false` the first time a redirect is set; on subsequent target changes it is the previous `WP_Term` object.

```php
add_action( 'htax_redirect_changed', function( $term_id, $taxonomy, $new_target_id, $new_target_taxonomy, $old_target ) {
    // $old_target — WP_Term object of the previous target, or false if no previous redirect
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

#### How plugins take part in merges and conversions

A plugin that keeps data on terms can take part in merges and conversions in two independent ways:

- **Act on the operation**, with the before and after actions below: keep the data of terms that are about to be deleted, then put it where it belongs. Every plugin with data on terms needs these, since many operations never show the Merge Terms or Convert Terms page: merges and conversions run from code (another plugin, WP-CLI) and conversions by other tools, such as the Categories to Tags Converter.
- **Ask the admin first** (optional), with the [check step](#check-step-before-a-merge-or-conversion): when the pages are used, show notices about what will happen to the plugin's data, and offer choices. A plugin with nothing to ask returns nothing there, and still acts on the operation as usual. When no plugin returns anything, the page doesn't show the step, and the operation runs straight away.

So the choices made on the page are only one input: read them from `$options` when they are there, and apply your own defaults when they aren't. Code that runs an operation without the page can pass the operation-wide preference `$options['htax']['carry']` instead (see the check step). The events, in order:

| Merge | Conversion |
|---|---|
| `htax_merge_terms_check`: only on the Merge Terms page, before the form is submitted | `htax_convert_terms_check`: only on the Convert Terms page, before the form is submitted |
| `htax_before_merge_terms`: all sources, with `$options`. Keep the sources' data here: they are deleted inside the merge's database transaction | `htax_before_convert_terms`: the batch, with `$options` (HyperTaxonomy's own conversions only) |
| The merge; redirect stubs are created after its transaction commits | For each term: `htax_before_convert_term` (the term and its meta still exist), the conversion, then `htax_after_convert_term`. These two also fire for conversions by other tools, and when a term is converted back to its redirect stub |
| `htax_after_merge_terms`: with the result, including each source's stub in `redirect_stubs` | `htax_after_convert_terms`: the batch's result |
| `htax_merge_terms_messages`: on the Merge Terms page, after the merge | `htax_convert_terms_messages`: on the Convert Terms page, after the conversion |

Check the choices again when the operation runs: the redirect stub setting can change after the check step, and in a merge a stub can fail to be created (a term with its name may exist). `htax_carry_choice()` does this for the shared stub/move/copy/drop choice, per term.

#### Before a merge

`htax_before_merge_terms`

Fires immediately before HyperTaxonomy begins merging source terms into a target term — before post reassignment, relationship transfer, and deletion. This hook fires **before** HyperTaxonomy's database transaction starts.

Use this hook to snapshot any per-term data you want to migrate to the target. This includes both term meta (which `wp_delete_term()` deletes after the transaction commits) and data in separate tables (which survives `wp_delete_term()` but will be cleaned up by your own `deleted_term` handler). Snapshot everything here, before any of it is touched.

```php
add_action( 'htax_before_merge_terms', function( $source_ids, $target_id, $taxonomy, $create_redirect, $options ) {
    // $source_ids      — int[], source term IDs about to be merged and deleted
    // $target_id       — int, target term ID
    // $taxonomy        — string
    // $create_redirect — bool, whether redirect stubs will be created for each source
    // $options         — array, choices made on the check step, keyed by section ID
    //                    (see "Check step before a merge or conversion"); for a merge
    //                    started outside the Merge Terms page, only what its caller
    //                    passed, such as the operation-wide preference, or nothing
}, 10, 5 );
```

#### After a merge

`htax_after_merge_terms`

Fires after a successful merge. Post reassignments, HyperTaxonomy relationship transfers, source term deletions, redirect stubs, search index requeue, and cache invalidation are all complete at this point.

Important: source terms have been permanently deleted and their WordPress term meta is gone. Data stored in separate database tables (not term meta) is still present and can be moved to the target term.

```php
add_action( 'htax_after_merge_terms', function( $source_ids, $target_id, $taxonomy, $result, $options ) {
    // $source_ids — int[], source term IDs (now deleted; term meta is gone)
    // $target_id  — int, target term ID
    // $taxonomy   — string
    // $result     — array {
    //     posts_reassigned  int   Total post assignments moved.
    //     terms_deleted     int   Number of source terms deleted.
    //     redirects_created int   Number of redirect stubs created.
    //     redirect_stubs    int[] Stub term ID by source term ID, for the stubs created.
    //     target_id         int   Actual target term ID used.
    // }
    // $options    — array, as for htax_before_merge_terms
}, 10, 5 );
```

**Typical pattern for companion plugins:** snapshot source data in `htax_before_merge_terms`, then apply it to the target in `htax_after_merge_terms`. Register a `deleted_term` handler for all term deletes — source terms deleted during a merge fire `deleted_term` the same as a standalone delete, so no suppression is needed. Also register a `delete_term_taxonomy` handler at an earlier priority for bulk taxonomy operations, where `deleted_term` does not fire. Code that needs a deleted term's meta must run on `pre_delete_term`: `wp_delete_term()` deletes the meta before it fires `delete_term_taxonomy`. For conversions, use `htax_before_convert_term` instead, which fires only for terms being converted.

#### Before and after a conversion

`htax_before_convert_terms` / `htax_after_convert_terms`

Fire around a batch conversion (`htax_convert_terms_batch()`, used by the Convert Terms page). Each term converted in between fires `htax_before_convert_term` and `htax_after_convert_term`. Conversions made by other tools, such as the Categories to Tags Converter, fire only those two.

```php
add_action( 'htax_before_convert_terms', function( $term_ids, $from_taxonomy, $to_taxonomy, $create_redirect, $options ) {
    // $term_ids        — int[], terms about to be converted
    // $from_taxonomy   — string
    // $to_taxonomy     — string
    // $create_redirect — bool, whether redirect stubs will be left in $from_taxonomy
    // $options         — array, choices made on the check step, keyed by section ID
}, 10, 5 );

add_action( 'htax_after_convert_terms', function( $term_ids, $from_taxonomy, $to_taxonomy, $summary, $options ) {
    // $summary — htax_convert_terms_batch() result: converted, redirects_created,
    //            reverse_conversions, errors, failed_term_ids
}, 10, 5 );
```

#### Before and after a term is converted

`htax_before_convert_term` / `htax_after_convert_term`

Fire around each term's conversion to another taxonomy: by `htax_convert_term()` (including a term converted back to its redirect stub), and by other tools that HyperTaxonomy detects, such as the Categories to Tags Converter. `htax_before_convert_term` fires while the term and its meta still exist, so keep there what you need afterwards when the term is deleted: in a conversion without a redirect stub, or by another tool. In a conversion by another tool, the new term already exists by then and its posts may have moved.

```php
add_action( 'htax_before_convert_term', function( $term_id, $from_taxonomy, $to_taxonomy ) {
    // $term_id       — int, the term being converted
    // $from_taxonomy — string
    // $to_taxonomy   — string
}, 10, 3 );

add_action( 'htax_after_convert_term', function( $new_term_id, $old_taxonomy, $new_taxonomy, $old_term_id ) {
    // $new_term_id  — int, the term now in $new_taxonomy
    // $old_taxonomy — string
    // $new_taxonomy — string
    // $old_term_id  — int, the term that was converted: the redirect stub left in
    //                 $old_taxonomy, or a deleted term. When a term is converted back
    //                 to its stub, $new_term_id is the restored stub.
}, 10, 4 );
```

#### Relationship row added or removed

`htax_relationship_saved` / `htax_relationship_deleted`

Fire after `htax_add_term_relationship()` adds a row or `htax_remove_term_relationship()` removes one. For types mapped to `includes`, HyperTaxonomy has already updated its caches and search index by then, through the matching change actions. See [Custom Relationship Types](custom-taxonomy.html#custom-relationship-types).

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

Fires at the bottom of the **Terms&nbsp;→ Term Relationship Types** admin page. Use it to add your own UI.

#### Archive contents changed

`htax_term_archive_changed`

Fires whenever a term's archive composition changes. Use it to invalidate any derived data that depends on a term's archive.

```php
add_action( 'htax_term_archive_changed', function( $term_id, $taxonomy ) {
    // Archive composition for $term_id in $taxonomy has changed
}, 10, 2 );
```

**When it fires:**
- Any HyperTaxonomy relationship saved or deleted (inclusions, exclusions, container groups, included groups), for the terms whose archives change: the including side of an inclusion or exclusion, the containers added or removed when a term's containers change, and the members of the container groups added or removed (and of their parent groups)
- A term first becomes a redirect (its own archive disappears); changing an existing redirect's target does not fire — the target's archive is unaffected and the source had no archive already
- A redirect is removed (the term's archive is restored)
- A term's WordPress parent changes (fires for old and new parent ancestor chains, and the terms that include any of them)
- A post is assigned to or removed from a term (fires for the term, its containers, its ancestors and the terms that include them)
- A post is published or unpublished (same expansion)
- A post's sticky-term assignments change
- A term is converted to another taxonomy, or a converted term is restored

HyperTaxonomy uses this action internally to invalidate the `htax_contrib_` paginated archive cache and the term's cached post lists.

#### Strengths missing for a post

`htax_term_strength_missing`

Fires when a post is (re)indexed and a registered external strength model has no strength for some of the post's directly assigned terms — a newly published post, a newly assigned term, or a model registered after the post was last scored. A model's provider hooks this to score the terms and submit them with `htax_set_term_strengths()`. See [Strength Models](search-ranking.html#strength-models).

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

Fires after an external model's strengths are stored with `htax_set_term_strengths()` or removed with `htax_remove_term_strengths()` (including Settings&nbsp;→ Search&nbsp;→ Clear Scores). Use it to invalidate anything that caches strength-based results.

```php
add_action( 'htax_term_strengths_updated', function( $post_id, $model, $term_ids ) {
    // $post_id is 0 when the model's strengths were removed for every post;
    // $term_ids is empty when all of the post's (or every post's) terms were affected.
}, 10, 3 );
```

#### External URIs or locations changed

`htax_term_uris_changed` / `htax_term_locations_changed`

Fire with `int $term_id` when a term's external URIs or locations change. HyperTaxonomy re-fires these from TermConnect's [`tconn_term_uris_changed`](https://hypertaxonomy.org/termconnect/developer-reference/actions-filters.html#external-links-changed) and As the Crow Flies' [`atcf_term_location_changed`](https://hypertaxonomy.org/as-the-crow-flies/developer-reference/actions-filters.html#actions), so a plugin that reads `htax_get_term_uris()` or `htax_get_term_locations()` can clear its caches without depending on either plugin.

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

Filters HTML appended inside HyperTaxonomy's archive description block (`.htax-archive-description`), after the term description. Applies when HyperTaxonomy renders the description itself (the taxonomy has **Archive Customization** enabled, **Archive Description** is on in the Display tab, and the term has **Show Description** checked). The block is printed when either the description or the appended HTML has content, so appended content shows even for a term without a description; TermConnect uses it for its "See also" links and the link-suggestion form. The value is printed through `wp_kses()` with `htax_allowed_form_html()`: forms, inputs, buttons, links, images and lists are kept, while scripts and other tags are stripped (their text is kept, so a `<script>` added here prints its code on the page — enqueue scripts instead). Escape everything you add.

```php
add_filter( 'htax_archive_description_append', function( $append, $term ) {
    return $append . '<p class="my-note">' . esc_html( $term->name ) . '</p>';
}, 10, 2 );
```

#### Expanded term IDs

`htax_expanded_term_ids`

Filter the expanded term ID list returned by `htax_get_expanded_term_ids()`. See [Query Expansion API](query-expansion.html) for full documentation.

```php
add_filter( 'htax_expanded_term_ids', function( $ids, $term_id, $taxonomy ) {
    return $ids;
}, 10, 3 );
```

#### Expansion-aware post count

`htax_term_post_count`

Filter the expansion-aware post count for a single term, fired by the `get_terms` filter when **Third-Party Query Expansion** is on (`expand_secondary_queries`). It runs on every request, after the cached count is read. See [Query Expansion API](query-expansion.html) for full documentation.

```php
add_filter( 'htax_term_post_count', function( $count, $term_id, $taxonomy, $query_context ) {
    return $count;
}, 10, 4 );
```

#### Built-in strength weights

`htax_strength_field_weights`

Override the TF formula coefficients used by `htax_calculate_direct_term_strength()` for the built-in strength model (`htax_tf`). To supply strengths from another source, register a separate model instead — see [Strength Models](search-ranking.html#strength-models). Coefficients must sum to ≤&nbsp;1.0 so the maximum achievable strength equals 1.0.

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

Handles the **Sync** action for a remote relationship type on the Term Relationship Types admin page, so the fetch can live in another plugin ([TermConnect hooks it](https://hypertaxonomy.org/termconnect/developer-reference/actions-filters.html#syncing-remote-relationship-types)). Receives a result array and the source site URL; return `[ 'success' => bool, 'message' => string ]`.

#### Cross-plugin data filters

`htax_term_uris`, `htax_term_locations`, `htax_term_external_posts`, `htax_peer_sites`

Filters that let a provider plugin supply external URIs, locations, external posts, and peer sites to other plugins on the same site. See [Cross-Plugin Data](cross-plugin-data.html).

#### Proximity search condition

`htax_search_proximity_where`

SQL condition for `near:` tokens, added to the WHERE clause of PowerSearch queries before ORDER BY / LIMIT. The As the Crow Flies integration builds the location condition through it. Signature `( string $where, array $proximity, string $post_column ): string` — see [Locations & Proximity Search](locations-proximity.html#proximity-search-condition).

#### Proximity search scoring

`htax_search_proximity_relevance`

SQL expression scoring a post for a location-only search, with no search words, only `near:` tokens (nearest first). The As the Crow Flies integration supplies distance scores. Signature `( string $sql, array $proximity, string $post_column ): string` — see [Locations & Proximity Search](locations-proximity.html#proximity-search-scoring).

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

#### Check step before a merge or conversion

`htax_merge_terms_check` / `htax_convert_terms_check`

When the Merge Terms or Convert Terms form is submitted, these filters are applied first (by AJAX), so a plugin that keeps data on the terms can say what the operation will do with it and offer choices, with defaults it computes for these terms. When no plugin returns anything, the operation runs straight away. Answering is optional: a plugin with nothing to ask still acts on the operation through the before and after actions (see [How plugins take part in merges and conversions](#how-plugins-take-part-in-merges-and-conversions)). Otherwise the page shows the returned notices and choices, with the redirect stub setting, which can still be changed there.

```php
add_filter( 'htax_merge_terms_check', function( $sections, $source_ids, $target_id, $taxonomy, $create_redirect ) {
    $sections[] = array(
        'id'      => 'my_plugin',                   // Key of the choices in $options.
        'title'   => 'My Plugin',
        'notices' => array(
            array( 'type' => 'info', 'text' => 'Notes on the source terms are copied to the target.' ),
        ),
        'fields'  => array(
            array(
                'name'            => 'badge_12',    // $options['my_plugin']['badge_12'].
                'label'           => 'Badge of Old Term',
                'choices'         => array(
                    array( 'value' => 'stub', 'label' => 'Keep it on the redirect stub', 'description' => '', 'stub' => 'required' ),
                    array( 'value' => 'drop', 'label' => 'Delete it', 'description' => '', 'stub' => '' ),
                ),
                'default'         => 'stub',        // Selected with redirect stubs.
                'default_no_stub' => 'drop',        // Selected without.
            ),
        ),
    );
    return $sections;
}, 10, 5 );

// Same form; arguments: $sections, $term_ids, $from_taxonomy, $to_taxonomy, $create_redirect.
add_filter( 'htax_convert_terms_check', 'my_plugin_convert_check', 10, 5 );
```

A choice's `stub` is `'required'` (available only with redirect stubs), `'none'` (only without) or `''`. Text is shown as plain text, and IDs, names and values must be slugs. The choices reach `htax_merge_terms()` / `htax_convert_terms_batch()` as `$options[ section id ][ field name ]`, and are passed to the before and after actions. Check them again there: the stub setting or the terms may have changed, and operations started outside these pages have only what their caller passed (often nothing, or just the operation-wide preference below), so apply your own defaults.

For data that a stub, the target (or new term), or both could keep, such as TermConnect's external links and As the Crow Flies' locations, offer the same four choices, so the page reads the same for every plugin: `stub` (leave it on the redirect stubs; needs stubs; the default with stubs), `move` (to the target or new terms; the default without stubs), `copy` (and leave it on the stubs; needs stubs) and `drop` (delete it). Two functions do this for you, and are always available in these hooks:

- `htax_carry_field( array $args ): array` builds the field: `name`, `label` (what the data is), `target_name` (the merge target's name, or `''` for a conversion's new terms), `movable` (whether the target or new terms can take the data at all; without, only `stub` and `drop` are offered) and `descriptions` (by choice value).
- `htax_carry_choice( array $options, string $section, string $field, bool $has_stub, bool $movable = true ): string` resolves the choice for one term: your field's value, else the operation-wide preference, else the default, fitted to whether that term has a stub and whether the data can move.

The operation-wide preference is `$options['htax']['carry']` (one of the four values). The section ID `htax` is reserved for HyperTaxonomy. Code that runs a merge or conversion without the page passes it to say what should happen to data that plugins keep on the terms, without knowing which plugins those are:

```php
// Merge, and move everything plugins keep on the source to the target.
htax_merge_terms( array( $old_id ), $new_id, 'post_tag', true, array( 'htax' => array( 'carry' => 'move' ) ) );
```

In a merge, the source terms are deleted before their stubs are created, so keep the data in `htax_before_merge_terms` and find each source's stub in `redirect_stubs` of the result. A stub that can't be created is missing from it: resolve that source's choice with `$has_stub` false. In a conversion with stubs, the old term becomes the stub and keeps its data; without, it is deleted before `htax_after_convert_term`, so keep the data in `htax_before_convert_term`.

#### Messages after a merge or conversion

`htax_merge_terms_messages` / `htax_convert_terms_messages`

Filters the messages shown under the result on the Merge Terms or Convert Terms page, so a plugin can report what it did with its data (plain text, one per message). The second argument is the operation's result.

```php
add_filter( 'htax_merge_terms_messages', function( array $messages, array $result ): array {
    $messages[] = 'Notes copied to the target: 2.';
    return $messages;
}, 10, 2 );
```
