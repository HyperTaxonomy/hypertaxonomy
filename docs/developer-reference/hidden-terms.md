---
title: Handling Hidden Terms
parent: Developer Reference
nav_order: 4
---

# Handling Hidden Terms
{: .no_toc }

Site owners mark terms hidden on the term edit screen and choose who can see them in Settings (see [Term Visibility](../user-guide/per-term-settings.html#term-visibility) in the User Guide). For viewers who can't see them, hidden terms don't exist as far as your plugin or theme is concerned. On read requests (GET and HEAD) outside wp-admin, `get_term()` and `get_term_by()` find nothing for them, and `get_terms()`, `get_tags()`, `get_categories()`, `wp_get_object_terms()`, `get_the_terms()` and `wp_count_terms()` leave them out, so you need no special handling. On writes and any other POST request, in wp-admin, and in cron and CLI you get the real terms. That includes plugins that serve terms to visitors through POST requests, such as most [GraphQL plugins](../user-guide/other-plugins.html#graphql-plugins-wpgraphql-gato-graphql), which send queries that way: if yours does, check `htax_is_term_hidden()` and `htax_can_see_hidden_terms()` before returning a term.

Posts are different: a post carrying a hidden term is never excluded, though `WP_Query` won't find posts *by* a hidden term for these viewers. `get_term_link()` returns a `WP_Error` and `term_exists()` returns nothing for a hidden term, unless you pass `get_term_link()` a term object you already hold. `has_term()` still answers for a hidden term, and `get_term_children()` and `get_ancestors()` can return its ID.

If a user who can't see a hidden term saves a post carrying it, the term stays on the post.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Automatic Filtering in Native Blocks

For users who cannot see hidden terms, every frontend post query — the Query Loop block, the Latest Posts block, REST `/wp/v2/posts`, and any custom `WP_Query` — treats hidden terms as if they did not exist. The `htax_hide_terms_in_tax_query` hook (`parse_tax_query`) removes hidden terms from the query's taxonomy criteria: a query for a hidden term matches nothing, `include_children` skips hidden descendants, and `NOT IN` clauses are left as written. Posts that carry a hidden term are **not** removed — they are still found through their other terms and in unfiltered lists. No extra code is needed for standard block and shortcode usage.

## Filtering Hidden Terms in Custom Queries

If you build custom queries that should respect hidden term visibility, use the provided helpers:

```php
// Before using term IDs from user input or URL parameters
$safe_ids = htax_get_visible_term_ids( $raw_term_ids );

// Check visibility for a single term
if ( htax_is_term_hidden( $term_id ) && ! htax_can_see_hidden_terms() ) {
    wp_redirect( home_url() );
    exit;
}
```

## Hidden Terms in the Search Index

Hidden terms are indexed like any other term and kept current on the same events. The search index (`wp_htax_search_terms`) records an `is_hidden` flag per row: set when the row's term or the term it came from is hidden. Hiding or unhiding a term updates the flags without a reindex. PowerSearch and the search widget add `AND is_hidden = 0` to their queries for viewers who can't see hidden terms, so editors can still search by them.

If you query the search index directly in a plugin, apply the same filter with `htax_get_hidden_term_filter_clause()`, which returns `' AND is_hidden = 0'` or an empty string for the current viewer:

```php
global $wpdb;
$hidden_clause = htax_get_hidden_term_filter_clause();

$results = $wpdb->get_results( $wpdb->prepare(
    "SELECT DISTINCT post_id FROM {$wpdb->prefix}htax_search_terms
     WHERE taxonomy = %s AND term_id IN (" . implode( ',', array_map( 'intval', $term_ids ) ) . ")
     {$hidden_clause}",
    $taxonomy
) );
```

## Hidden Terms and REST API

A hidden term's own REST record is excluded for users who cannot see it (based on the global visibility setting), and shown to those who can. HyperTaxonomy's relationship fields (`htax_includes`, `htax_synonyms`, `htax_relationships`, `htax_redirect`, `htax_term_strengths`) leave hidden terms out for everyone, and so do the [computed term meta keys](term-meta.html). See [REST API → Hidden terms and REST](rest-api.html#hidden-terms-and-rest).
