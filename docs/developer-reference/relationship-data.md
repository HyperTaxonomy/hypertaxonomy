---
title: Relationship Data API
parent: Developer Reference
nav_order: 3
---

# Relationship Data API
{: .no_toc }

If you need to build a `tax_query` from a term's expanded post set, react to archive changes, or adjust term counts in facet navigation, start with the [Query Expansion API](query-expansion.html). The functions below expose HyperTaxonomy's internal relationship model directly and are intended for plugins that need raw relationship data — custom admin UIs, relationship-based features, or building tooling on top of the graph.

What each relationship type does is described in [Term Relationships](../user-guide/term-relationships.html) in the User Guide. All public functions are prefixed `htax_`. Check `function_exists()` before calling if your code must run without HyperTaxonomy active.

On front-end, REST and AJAX read requests (GET and HEAD), terms hidden from the current viewer are left out for callers in other plugins and themes by `htax_get_included_terms()`, `htax_get_container_terms()`, `htax_get_synonym_terms()`, `htax_get_related_terms()`, `htax_get_reverse_related_terms()` and `htax_get_terms_in_groups()`. See [Handling Hidden Terms](hidden-terms.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Term Relationships

### Included terms

`htax_get_included_terms( int|WP_Term $term, bool $include_redirect_data = false, bool $include_disabled = false ): array`

Returns the terms whose posts contribute to the given term's archive via HyperTaxonomy inclusions, after applying exclusions. The result is restricted to **cross-branch** relationships: for hierarchical taxonomies, ancestors and descendants are excluded even if explicit inclusion rows exist for them. Ancestors are excluded to prevent semantic loops; descendants are excluded because they are already in the archive via WP's native `include_children` and their presence in the HyperTaxonomy inclusion set would be redundant. Archive expansion (`htax_get_expanded_term_ids`) reunites HyperTaxonomy inclusions and WP descendants at query time; this function returns only the HyperTaxonomy-managed portion.

```php
$included = htax_get_included_terms( $term_id );
// Returns array of WP_Term objects — cross-branch only, no WP ancestors or descendants

foreach ( $included as $term ) {
    echo $term->name;
}
```

Returns an empty array if the **Term Relationships** feature (`term_relationships`) is not enabled for the term's taxonomy, if no inclusions are configured, or if the term is a redirect (its relationships are preserved in the database but inactive). Pass `true` as the second argument to retrieve the stored data regardless of redirect status, as the admin UI does. The third argument, `$include_disabled`, returns the stored relationships even while the feature is disabled; only the search index uses it (it stores relationships regardless and gates at query time).

Ancestor/descendant exclusion is enforced at four levels: the term edit save handler strips them before writing; `htax_cleanup_hierarchy_conflicts()` removes conflicts when a term is reparented; `htax_merge_terms()` removes conflicts after a merge; and `htax_repair_hierarchy_conflicts()` sweeps on plugin activation. This function's read-time filtering is a final safety net for rows written directly to the database.

### Terms that include a term

`htax_get_container_terms( int|WP_Term $term, bool $include_disabled = false ): array`

Returns all terms that include this term's posts in their archives — the inverse view of inclusions. For hierarchical taxonomies, both descendants and ancestors are excluded: descendants cannot be containers (they cannot include their own ancestors), and ancestors are excluded for symmetry — a term's posts are already visible in ancestor archives via `include_children`, so an ancestor as a container would be redundant. Results are restricted to cross-branch relationships, indexed by term ID.

```php
$containers = htax_get_container_terms( $term_id );
// Returns array of WP_Term objects, indexed by term_id
```

Returns an empty array when the **Term Relationships** feature is disabled for the term's taxonomy; `$include_disabled = true` bypasses that (search index only).

### Synonyms

`htax_get_synonym_terms( int|WP_Term $term, bool $include_redirect_data = false ): array`

Returns the terms that the given term includes and that include it in turn: the intersection of its effective inclusions (`htax_get_included_terms()`) and its effective containers (`htax_get_container_terms()`). Each direction can come from any inclusion path — a direct inclusion, an inclusion-based custom type, a group inclusion or a container group — and exclusions apply. Synonyms contribute each other's directly assigned posts to their archives, but not their other inclusions; see [Term Relationships → Synonyms](../user-guide/term-relationships.html#synonyms).

```php
$synonyms = htax_get_synonym_terms( $term_id );
// Returns array of WP_Term objects

foreach ( $synonyms as $synonym ) {
    echo $synonym->name; // e.g. "JavaScript" when called on the "JS" term
}
```

Returns an empty array when the **Term Relationships** feature is disabled for the term's taxonomy, if the term has no synonyms, or if the term is a redirect, since a redirect's inclusions are inactive (unless `$include_redirect_data` is `true`). Redirect terms are also filtered from the results: if term B is a redirect, it will not appear as a synonym of A, since a redirect is never a container. For archive expansion purposes `htax_get_included_terms()` is sufficient — this function is intended for display and UI use cases where the symmetric nature of the relationship is relevant.

Synonyms have no cache of their own: they are computed from the cached inclusions and containers, so they stay current whenever those caches do.

### Public related term IDs

`htax_get_public_related_term_ids( WP_Term $term, string $kind ): int[]`

The IDs of a term's related terms with hidden terms left out for everyone, the same answer for every viewer. `$kind` is `'includes'` (effective inclusions, after exclusions), `'containers'` (terms whose archives include this term's posts), `'synonyms'` or `'redirect'` (the redirect target as a one-item list). Use it for data you show or share publicly; it's what the [REST relationship fields](rest-api.html#hidden-terms-and-rest) and the [term meta keys](term-meta.html) use. The getters above follow the viewer's permissions instead.

```php
$public_includes = htax_get_public_related_term_ids( $term, 'includes' ); // [ 12, 34 ]
```

### Whether a term redirects

`htax_is_redirect( int $term_id ): bool`

Returns true if the term has a 301 redirect configured.

```php
if ( htax_is_redirect( $term_id ) ) {
    // This term redirects elsewhere — it has no accessible archive
}
```

### Redirect target

`htax_get_redirect_target( int $term_id ): WP_Term|false`

Returns the redirect target as a `WP_Term` object, or false if the term has no redirect.

```php
$target = htax_get_redirect_target( $term_id );
if ( $target ) {
    $url = get_term_link( $target->term_id, $target->taxonomy );
}
```

### Terms that redirect to a term

`htax_get_redirect_sources( int $target_term_id ): int[]`

The reverse of `htax_get_redirect_target()`: returns every term ID that redirects to the given target, following redirect chains (`A → B → Y`) with a cycle guard. Posts assigned a redirecting term belong to the target for discovery, so this backs the `$include_redirect_sources` option of `htax_get_expanded_term_ids()`. Returns an empty array when nothing redirects to the term.

```php
$sources = htax_get_redirect_sources( $target_term_id ); // e.g. [ 1001 ]
```

### All redirecting terms

`htax_get_all_redirect_term_ids(): int[]`

Returns all term IDs that have a redirect row in the database, across all taxonomies. The result is cached in a transient and invalidated automatically when any redirect is added or removed.

```php
$redirect_ids = htax_get_all_redirect_term_ids();
// Use for bulk filtering — e.g. strip redirect terms from a list of candidates.
$filtered = array_diff( $candidate_ids, $redirect_ids );
```

Returns raw database state — does not filter by taxonomy feature flag. If you use the result to suppress redirect terms, check `htax_taxonomy_has_feature( $taxonomy, 'redirects' )` separately before applying the filter, so terms with stale rows in a redirects-disabled taxonomy are not incorrectly treated as active redirects.

## Term Groups

### A term's groups

`htax_get_term_groups( int $term_id ): WP_Term[]`

Returns the groups a term is assigned to, ordered by name. Returns an empty array if the `group_assignment` feature is not enabled for the term's taxonomy, or if the term doesn't exist.

```php
$groups = htax_get_term_groups( $term_id );
foreach ( $groups as $group ) {
    echo $group->name;
}
```

Results are cached in a transient (`htax_groups_{term_id}`).

### Terms in groups

`htax_get_terms_in_groups( array $group_ids, string $taxonomy = 'post_tag' ): WP_Term[]`

Returns all member terms of the given groups, indexed by term ID. Group hierarchy is expanded automatically — passing a parent group ID also returns terms belonging to its child groups, mirroring how WordPress expands `child_of` for `get_terms()`. Returns an empty array when the `group_assignment` feature is not enabled for `$taxonomy`: its stored memberships are kept but ignored.

```php
$terms = htax_get_terms_in_groups( array( 5, 12 ), 'post_tag' );
// Returns WP_Term[] indexed by term_id, including terms in child groups of 5 and 12
```

Each group is cached separately (`htax_grp_{group_id}_{taxonomy}`), so invalidating one group's membership doesn't require rebuilding the others.

### Set a term's groups

`htax_set_term_groups( int $term_id, int[] $group_ids ): bool|WP_Error`

Replaces all group assignments for a term with the given set of group IDs. The taxonomy is derived from the term. Returns `true` on success, or a `WP_Error` if the term doesn't exist, the `group_assignment` feature is disabled for its taxonomy, or the term is itself a group (groups cannot be assigned to other groups via group membership — use the native WordPress parent field for group hierarchy instead).

```php
$result = htax_set_term_groups( $term_id, array( 5, 12 ) );
if ( is_wp_error( $result ) ) {
    echo $result->get_error_message();
}
```

### Group counts

A group's stored `count` (on its `WP_Term` and in the REST response) is the number of terms directly in the group, kept current as memberships change. Like a category's post count, it leaves out subgroups: a group whose members are all in subgroups has a count of 0. So `get_terms()` with `hide_empty` and `hierarchical => false`, and `GET /wp/v2/group?hide_empty=true`, leave such a group out, exactly as WordPress does for a parent category with posts only in subcategories. With the default `hierarchical => true`, `get_terms()` keeps it. To find out whether a group's archive has content, call `htax_group_archive_has_posts( int $group_id ): bool`, which runs the archive's own query for one published post. XML sitemaps list only groups for which it returns true.

## Sticky Posts

### A term's sticky posts

`htax_get_sticky_posts_for_term( int $term_id, bool $include_redirect_data = false ): WP_Post[]`

Returns the posts pinned to the top of a term's archive. Returns an empty array if the term is a redirect and the `redirects` feature is enabled for the taxonomy (a redirect term has no archive of its own to pin posts to), unless `$include_redirect_data` is `true`.

```php
$sticky_posts = htax_get_sticky_posts_for_term( $term_id );
foreach ( $sticky_posts as $post ) {
    echo $post->post_title;
}
```

Results are cached in a transient (`htax_sticky_{term_id}`).
