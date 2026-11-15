---
title: Query Expansion API
parent: Developer Reference
nav_order: 2
---

# Query Expansion API
{: .no_toc }

`includes/core/integration-api.php` provides a stable public surface for third-party plugins that need to work with HyperTaxonomy's archive expansion logic. Internal cache-invalidation infrastructure (`htax_fire_term_archive_changed`, `htax_fire_archive_changed_on_hierarchy`) lives in `cache.php`. What inclusions, groups and redirects add to an archive is described in [Term Relationships](../user-guide/term-relationships.html) in the User Guide. For how expansion is built and which feature flags gate it, see [Feature Internals](../architecture/feature-internals.html#feature-flag-enforcement-for-archive-expansion).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Get a term's expanded term IDs

`htax_get_expanded_term_ids( int $term_id, bool $include_redirect_sources = false ): array`

Returns the complete flat list of term IDs whose posts appear in a term's archive: the term itself, WordPress descendants (for hierarchical taxonomies), and all HyperTaxonomy-contributed terms (inclusions, group memberships, container groups). Returns an empty array if the term does not exist. When the `term_relationships` feature is disabled for its taxonomy, only the term itself and its visible WordPress descendants are returned (no HyperTaxonomy contributions). WordPress term IDs are globally unique, so no taxonomy parameter is needed.

Pass `$include_redirect_sources = true` to also fold in terms that redirect (directly or transitively) to this term — so a query on a redirect *target* also matches posts tagged with the *redirecting* terms, matching the relevance index used by search and `[ht_related_posts]`. It's off by default because archive expansion proper does not follow redirects (a redirect 301s the archive request); discovery surfaces such as `[tc_peer_posts]`/`[tc_peer_browse]` and the WP-SKOS document endpoint pass `true`. The redirect inclusion is gated by the taxonomy's Redirects feature and filtered for term visibility.

Applies the `htax_expanded_term_ids` filter before returning. The same list, without hidden terms, is readable as the `htax_archive_term_ids` [term meta key](term-meta.html).

**Consumer pattern — build a tax_query from the expanded list:**

```php
$ids = htax_get_expanded_term_ids( $term_id );

if ( ! empty( $ids ) ) {
    $query->set( 'tax_query', [ [
        'taxonomy'         => 'post_tag',
        'field'            => 'term_id',
        'terms'            => $ids,
        'include_children' => false, // expansion is already flat — do not re-expand
    ] ] );
}
```

Setting `include_children => false` is important: the list already contains every descendant HyperTaxonomy counts, and letting WordPress re-expand would add back child terms it leaves out (hidden ones, for viewers who can't see them) and cost extra queries.

## Build a term's expanded tax_query

`htax_build_expanded_tax_query( int $term_id, string $taxonomy, ?WP_Term $term_obj = null ): ?array`

Builds the OR `tax_query` array for a term's archive expansion. Returns `null` if HyperTaxonomy has no contributions to add (i.e. the expansion result equals native WordPress behavior alone), or if the `term_relationships` feature is not enabled for the taxonomy. Pass a `WP_Term` object as the third argument to avoid a redundant database lookup.

This is the lower-level function used internally by both `htax_modify_archive_query` (main archive) and `htax_expand_secondary_query` (secondary queries). Use `htax_get_expanded_term_ids()` for most integration needs; use this function when you need direct access to the tax_query array structure.

## Expanding secondary queries

`htax_expand_secondary_query`

A `pre_get_posts` handler (priority 10) that expands single-term tax_query clauses for enabled taxonomies in secondary and third-party queries. It skips the main archive query, which is already handled by `htax_modify_archive_query`.

**Per-query control via the `htax_expand_terms` query var:**

```php
// Suppress expansion for a specific query:
$query->set( 'htax_expand_terms', false );

// Force expansion regardless of the global setting:
$query->set( 'htax_expand_terms', true );
```

**Global on/off:** Enable via **[Settings → Search](../user-guide/plugin-settings.html#search-tab) → Third-Party Query Expansion**. Defaults to off. When on, secondary queries using a single-term tax_query clause for a HyperTaxonomy-enabled taxonomy are automatically expanded.

Only single-term clauses are expanded. Multi-term clauses are treated as facet filters chosen by the user, not archive expansions.

**Front end only:** the handler doesn't run in wp-admin. Front-end requests to `admin-ajax.php` (load more, live filtering) are expanded like any other front-end query; an AJAX request sent from a wp-admin screen, or one without a referer, counts as wp-admin and isn't expanded, not even with `htax_expand_terms` set to `true` (the referer test is `htax_is_admin_request()`).

## Expansion-aware term counts

`htax_adjust_term_counts`

A `get_terms` filter that replaces native WordPress term counts with expansion-aware counts. Active on the frontend only, and only when **Third-Party Query Expansion** is on (`expand_secondary_queries`). Term lists and filter panels that show WordPress's own term counts then reflect the expanded post set rather than direct assignments alone. Like WordPress's counts, they count published posts of the post types the taxonomy is registered for. Each term's count is cached (one copy for viewers who can see hidden terms, one for those who can't) and cleared whenever the term's archive changes (`htax_term_archive_changed`: relationship changes, post assignments, publishing); the `htax_expanded_term_ids` filter is applied when a count is computed, so its result is cached with it. Facet plugins that count from their own index (FacetWP, WP Grid Builder) keep their own counts. It doesn't run in wp-admin or in any `admin-ajax.php` request, front-end ones included, where recounting each term would add a query per term to lookups such as the search widget's suggestions.

It doesn't apply to a post's own terms (`wp_get_object_terms()`, `get_the_terms()`), which keep their native counts. If a count query fails, the term keeps its native count.

Per-term counts can be further customized via the `htax_term_post_count` filter.

## Reacting to archive changes

`htax_term_archive_changed` (action)

Fires whenever a term's archive composition changes — relationships, redirects, hierarchy, or post assignments. Use it to invalidate derived data (custom caches, pre-computed counts, external indexes):

```php
add_action( 'htax_term_archive_changed', function( $term_id, $taxonomy ) {
    // The archive for $term_id in $taxonomy has changed — clear any derived data
    my_plugin_clear_term_cache( $term_id, $taxonomy );
}, 10, 2 );
```

See the `htax_term_archive_changed` entry in [Actions Fired by HyperTaxonomy](actions-filters.html#actions-fired-by-hypertaxonomy) for the full list of triggers.
