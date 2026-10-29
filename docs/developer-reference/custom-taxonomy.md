---
title: Custom Taxonomy Integration
parent: Developer Reference
nav_order: 1
---

## 1. Custom Taxonomy Integration

### Registering a Custom Taxonomy for HyperTaxonomy

Register your taxonomy normally with `register_taxonomy()`, then enable HyperTaxonomy features for it via Settings > HyperTaxonomy > General. HyperTaxonomy detects all registered public taxonomies automatically.

Features can also be enabled programmatically via the `htax_settings` option:

```php
// Enable inclusions and icons for a custom taxonomy
add_action( 'init', function() {
    $options = get_option( 'htax_settings', [] );

    // Enable taxonomy
    $options['enabled_taxonomies']['product_category'] = 1;

    // Enable features
    $options['taxonomy_features']['product_category']['term_relationships'] = 1;
    $options['taxonomy_features']['product_category']['icons'] = 1;
    $options['taxonomy_features']['product_category']['redirects'] = 1;

    update_option( 'htax_settings', $options );
}, 5 ); // Priority 5 to run before HyperTaxonomy reads settings
```

### Querying with Inclusions

When you want a custom query to respect HyperTaxonomy inclusion relationships, use `htax_get_expanded_term_ids()` to get the full flat list (term itself, WordPress descendants, and all HyperTaxonomy-contributed terms) and build the tax_query from it:

```php
$term_id = get_queried_object_id();

$ids = htax_get_expanded_term_ids( $term_id );

if ( ! empty( $ids ) ) {
    $query->set( 'tax_query', [ [
        'taxonomy'         => $taxonomy,
        'field'            => 'term_id',
        'terms'            => $ids,
        'include_children' => false, // list is already fully expanded
    ] ] );
}
```

HyperTaxonomy handles this automatically on native term archives when inclusions are enabled. This pattern is for custom queries in themes or plugins.

For secondary queries in third-party plugins that should automatically expand alongside archive queries, see `htax_expand_secondary_query` in the [Integration API](#3-integration-api) section.

### Category Hierarchy in Searches

When a parent category is included (directly or via groups), all its subcategories are automatically included. This is handled inside `htax_get_included_terms()` — no extra code needed.

### Custom Relationship Types

HyperTaxonomy includes a Relationship Type Registry (`includes/core/relationship-types.php`) that manages both built-in types (`includes`, `member_of`, `redirect`, `excludes`) and user-defined types. There are two distinct kinds of user-defined type:

- **Inclusion-based** (`core_type = 'includes'`) — participates in archive expansion and the search index exactly like the built-in `includes` type. Source and target must be from the same taxonomy; groups on either side are expanded to their members in that taxonomy.
- **Custom** (`core_type = null`) — stores the relationship only; behavior is implemented externally. Source and target taxonomies are independent and unconstrained.

The registry is the correct integration point — avoid inserting raw rows into `wp_htax_term_relationships` directly.

**Registering a type from code** — hook into `htax_relationship_types`, which fires at `init` priority 0 after all built-in types are registered:

```php
add_action( 'htax_relationship_types', function () {
    // Directional type — distinct forward and inverse labels
    htax_register_relationship_type( 'co_occurrence', array(
        'label'         => __( 'Co-occurrence', 'my-plugin' ),
        'inverse_label' => __( 'Also occurs with', 'my-plugin' ),
        'core_type'     => null,
        'show_in_ui'    => true,
    ) );

    // Symmetric type — forward label used in both directions; inverse_label is ignored
    htax_register_relationship_type( 'co_cited_with', array(
        'label'         => __( 'Co-cited with', 'my-plugin' ),
        'inverse_label' => '',      // ignored when symmetric = true
        'core_type'     => null,
        'symmetric'     => true,    // non-inclusion-based types only
        'show_in_ui'    => true,
    ) );
} );
```

The `symmetric` key is valid only for non-inclusion-based types (`core_type => null`). When `true`, the forward label is stored as the inverse label and the label-must-differ validation is bypassed. The `symmetric` flag is persisted on the type record and readable via `htax_get_registered_relationship_types()`.

**Mapping to `includes` for archive behavior** — a type mapped to `'includes'` causes posts tagged with the *target* term to appear in the *source* term's archive, identical to a direct inclusion. The target may be an ordinary term or a group:

```php
htax_register_relationship_type( 'wrote', array(
    'label'            => __( 'Wrote', 'my-plugin' ),
    'inverse_label'    => __( 'Written by', 'my-plugin' ),
    'core_type'        => 'includes',
    'show_in_ui'       => true,
) );
```

**Saving and deleting rows** — use the provided helpers rather than raw `$wpdb->insert()`. They validate taxonomy constraints, handle `INSERT IGNORE` idempotency, and fire the `htax_relationship_saved` / `htax_relationship_deleted` actions (which trigger search index rebuilds for both terms):

```php
// Save a relationship row
$result = htax_add_term_relationship( $source_term_id, $target_term_id, 'co_occurrence' );
if ( is_wp_error( $result ) ) {
    // handle error: invalid type, taxonomy mismatch, DB error
}

// Delete a relationship row
htax_remove_term_relationship( $source_term_id, $target_term_id, 'co_occurrence' );
```

`htax_add_term_relationship()` enforces group-aware validation:
- Group terms (`'group'` taxonomy) are implicitly allowed on either side, regardless of the type's configured `source_taxonomies` / `target_taxonomies` constraints.
- A row where **both** sides are group terms is rejected (`group_both_sides` error).
- For includes-mapped types, both non-group sides must be in the same taxonomy; rows with one group side are allowed regardless.
- For includes-mapped types, a term cannot be related to itself (`self_inclusion` error).

**Deleting ineligible rows after a type definition change** — `htax_remove_invalid_term_relationships( string $slug ): int` deletes all rows for the named type that violate its current constraints (group+group rows, source/target taxonomy violations with group exemption, cross-taxonomy rows for includes-mapped types, self-inclusion rows for includes-mapped types). It pre-reads affected source and target term IDs before deleting and calls `htax_delete_term_transients()` for each, so caches are never left stale after the cleanup. It is called automatically after a type is saved via the admin UI. Call it manually after programmatic type updates if you tighten source/target taxonomy constraints on an existing type.

**Querying relationships:**

```php
// WP_Term objects related to this term (outgoing direction), indexed by term_id
$terms = htax_get_related_terms( $term_id, 'co_occurrence' );

// WP_Term objects that point to this term (incoming direction), indexed by term_id
$terms = htax_get_reverse_related_terms( $term_id, 'co_occurrence' );
```

Both functions are for non-core custom types only — passing a built-in slug (`includes`, `container`, `member_of`, `redirect`, `excludes`) returns an empty array immediately. Those types each have a dedicated function with proper caching, group expansion, and `never_include` logic. Group targets/sources for non-core types are resolved to their member `WP_Term` objects. Both return an empty array when the **Term Relationships** feature is disabled for the term's taxonomy, matching the built-in relationship getters. `htax_get_related_terms()` also returns an empty array for redirect terms (outgoing relationships are inactive); `htax_get_reverse_related_terms()` is unaffected by redirects.

`htax_get_related_terms()` accepts two optional boolean flags:
- `$include_redirect_data` (third arg, default `false`) — pass `true` to bypass the redirect check.
- `$skip_if_private` (fourth arg, default `true`) — pass `false` to include results even when the type has `is_private` set. Callers that legitimately need private type data (local admin UI) pass `false`; external-facing code (REST endpoints) uses the default.

**Cleanup on term deletion.** `htax_cleanup_deleted_term()` already deletes all rows in `wp_htax_term_relationships` where the term appears as source or target — this covers custom relationship rows automatically. No separate `delete_term` hook is needed.

**Registering via the admin UI.** **Terms → Term Relationships** lists three sections:

- **Built-in types** — read-only.
- **Remote types** — types synced from peer sites via TermConnect (`source_site` field non-empty); read-only while delegated. **Make local** detaches a type from its source: `source_site` is cleared, the type becomes fully editable, and its namespace in REST responses changes to this site's URL. This action is irreversible and requires confirmation.
- **Local types** — locally created or promoted types. Two creation forms are available:
  - **New Inclusion-based** — single shared taxonomy, `core_type = 'includes'`.
  - **New Custom** — `core_type = null`, independent source/target constraints.
  - Both forms include a **Private** checkbox — when checked, `is_private = true` and the type is excluded from peer-sharing REST endpoints.

Types created there are stored in `wp_options` under `htax_custom_relationship_types` and loaded into the registry on `init` priority 5. The `'group'` taxonomy is always excluded from `source_taxonomies` / `target_taxonomies` when saving via the admin UI — group terms are implicitly allowed through the group exemption in `htax_add_term_relationship()` and do not need to be listed.

`htax_set_custom_relationship_type( $slug, $args, $is_new )` accepts two additional fields in `$args`:
- `source_site` (string) — URL of the peer site that originated this type. TermConnect sets this when syncing remote types. Empty = locally owned.
- `is_private` (bool) — when `true`, the type is excluded from peer-sharing REST responses.

`$is_new` distinguishes create from edit: passing `true` returns `WP_Error('slug_exists')` if the slug is already in use; passing `false` returns `WP_Error('not_found')` if the slug does not exist.

`htax_make_relationship_type_local( $slug )` clears `source_site` on a remote type, making it locally owned. Returns `false` for core types or unknown slugs.

`htax_get_registered_relationship_types( $skip_private = false )` accepts an optional bool; when `true`, custom types with `is_private` set are omitted from the returned array. Built-in types are never private and always included.

`core_type` is locked on edit — the passed value is ignored and the existing value is preserved. No conversion between inclusion-based and plain custom types is possible after creation. The code-level `htax_register_relationship_type()` API does not enforce this lock and accepts any `core_type` value, giving plugin authors full control.

**Label uniqueness.** All type labels and inverse labels — including those of the four built-in types — must be unique across the registry, checked case-insensitively. `htax_set_custom_relationship_type()` returns `WP_Error('duplicate_label')` or `WP_Error('duplicate_inverse_label')` if either clashes with any existing type's label or inverse label. For non-symmetric types a type's label and inverse label must also differ from each other (`WP_Error('label_equals_inverse')`); for symmetric types this check is skipped because equality is intentional. The built-in types and their labels are: Includes / Is included into, Excludes / Is excluded from, Redirects to / Target of redirect from, Member of / Contains member.

**Relationship rows and term taxonomy conversion.** When a term is converted to a different taxonomy via `wp_update_term()`, HyperTaxonomy silently updates its relationship rows to the new taxonomy and deletes any rows that are no longer valid (cross-taxonomy includes rows, rows that violate source/target constraints under the new taxonomy). There is no pre-conversion warning — the cleanup happens automatically at the `edited_term` hook.

**Extending the relationship types admin page.** The `htax_relationship_types_admin_footer` action fires at the bottom of the **Terms → Term Relationships** admin page, after all three table sections (built-in, remote, local) have rendered. Hook here to append additional UI — for example, a "discover types from a peer site" panel.

```php
add_action( 'htax_relationship_types_admin_footer', function () {
    echo '<div class="my-plugin-panel">';
    // ... render custom UI ...
    echo '</div>';
} );
```

---

