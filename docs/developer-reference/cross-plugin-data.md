---
title: Cross-Plugin Data
parent: Developer Reference
nav_order: 7
---

# Cross-Plugin Data
{: .no_toc }

External knowledge-base identifiers (Wikidata, Wikipedia, GeoNames, and more) attached to terms are managed by **TermConnect**. The Term Links API — `tconn_get_term_links()`, `tconn_get_link_uri()`, `tconn_get_terms_by_uri()`, `tconn_get_platform_link_types()`, and related functions — is documented in the [TermConnect Developer Reference](https://hypertaxonomy.org/termconnect/developer-reference/term-links-api.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

HyperTaxonomy exposes four PHP abstraction filters. Provider plugins hook these to make their data available to same-site consumers ([WP-SKOS](https://hypertaxonomy.org/wp-skos/developer-reference/hypertaxonomy-integration.html), search, etc.) without those consumers needing to know the source plugin — and consumers read the matching `htax_get_*()` getter without knowing the provider:

## Term URIs

`htax_term_uris` — [TermConnect](https://hypertaxonomy.org/termconnect/developer-reference/actions-filters.html#term-uris-for-other-plugins) always populates this with URIs from all enabled link types, regardless of TermConnect's REST API guard. Same-site plugins like WP-SKOS consume it via PHP without any REST call:

```php
add_filter( 'htax_term_uris', function( array $uris, int $term_id ): array {
    // Add your own URIs here if not using TC.
    return $uris;
}, 10, 2 );
```

`htax_term_uris` is a same-site PHP filter only — HyperTaxonomy does not expose it as a REST field. External identifier data in REST responses is TermConnect's responsibility, exposed via `tconn_links` on TermConnect-enabled term endpoints.

## Term locations

`htax_term_locations` — HyperTaxonomy's built-in As the Crow Flies integration populates this with physical location rows while that plugin is active, for PHP consumers to read through `htax_get_term_locations()`. It doesn't feed REST responses: those get locations from `ATCF_REST_API::format_term_locations()`, gated by the per-taxonomy **Share list of locations** setting. As the Crow Flies' `atcf_expose_locations_api` setting controls that plugin's own REST endpoints:

```php
add_filter( 'htax_term_locations', function( array $locations, int $term_id ): array {
    // Each row: label, street_address, locality, region, postal_code, country, lat, lng.
    return $locations;
}, 10, 2 );
```

## External posts

`htax_term_external_posts` — related posts from external/peer sources. [TermConnect](https://hypertaxonomy.org/termconnect/developer-reference/external-documents.html) populates this with posts fetched from configured peer sites and feeds (explicit per-term links plus shared-identifier/synonym auto-matching, and FOAF when requested). Consumers read it via the getter `htax_get_term_external_posts( int $term_id, array $args = [] ): array`, which **enforces term visibility**: it returns `[]` for a term hidden from the current user (and does not even fire the filter), so providers and consumers never reimplement visibility. Each post is a normalized array (`url`, `title`, `excerpt`, `date_gmt`, `modified_gmt`, `thumbnail_url`, `origin`, `score`, …).

`$args` is forwarded opaquely to providers as scope hints (HyperTaxonomy does not interpret it); TermConnect understands `platforms` (e.g. `['wordpress','feed']`; default all supported), `foaf_depth` (default `0` = direct peers only), `peers` (restrict to specific link-type slugs; omit to keep the peer topology private), and `per_page`.

```php
add_filter( 'htax_term_external_posts', function( array $posts, int $term_id, array $args ): array {
    // Append normalized post rows for $term_id from your source.
    return $posts;
}, 10, 3 );

// Consumer side (e.g. WP-SKOS) — gated, source-agnostic; scope is opaque to peers:
$posts = htax_get_term_external_posts( $term_id, array( 'platforms' => array( 'wordpress', 'feed' ), 'foaf_depth' => 0 ) );
```

## Peer sites

`htax_peer_sites` — read-only registry of the peer sites known to this installation. HyperTaxonomy defines the concept of a "peer site" but stores and manages nothing; providers populate the registry and consumers read it to offer peer-scoped options. [TermConnect](https://hypertaxonomy.org/termconnect/developer-reference/actions-filters.html#peer-site-registry) populates it with its configured WordPress peers and feeds. Consumers read it via the getter `htax_get_peer_sites(): array`, which returns the descriptors keyed by slug (an empty list when no provider is active). Each descriptor is an associative array with `slug` (stable identifier — the provider's link-type slug), `label` (human-readable name), `platform` (e.g. `wordpress`, `feed`), and `origin` (site origin `scheme://host` or feed URL, when known).

```php
add_filter( 'htax_peer_sites', function( array $sites ): array {
    // Append peer-site descriptors keyed by slug.
    $sites['my_peer'] = array(
        'slug'     => 'my_peer',
        'label'    => 'My Peer Site',
        'platform' => 'wordpress',
        'origin'   => 'https://peer.example',
    );
    return $sites;
} );

// Consumer side — source-agnostic; HT stores nothing:
$peers = htax_get_peer_sites();
```

## Local post scores

`htax_get_local_post_scores( array $term_ids, array $args = [] ): array` — relevance scores for local posts against a set of query terms, returned as a `post_id => score` map. For each query term it combines the post's search-index strengths over that term's relationship paths with noisy-OR, then sums across terms — the scoring HyperTaxonomy's search uses — reading the strength model selected for related posts. Relationship paths are limited to the taxonomy's enabled relationship features, and hidden terms are left out for viewers who can't see them. A consumer (e.g. TermConnect's blended peer view) calls it to rank this site's own posts consistently with HyperTaxonomy before merging them with peer results. `$args` accepts `exclude` (an array of post IDs to omit) and `term_weights` (`term_id => weight`, multiplying that term's score; terms not listed weigh 1.0 — TermConnect passes the current post's own term strengths, so posts sharing its most central terms rank first). Returns an empty array when the search index table is unavailable.

```php
// e.g. rank local posts for the terms a peer query is about, minus the current post:
$scores = htax_get_local_post_scores( $term_ids, array( 'exclude' => array( get_the_ID() ) ) );
arsort( $scores );
```

## Querying terms by URI

`htax_term_uri` — a term collection query parameter, registered by TermConnect (not a filter):

TermConnect also registers [`htax_term_uri`](https://hypertaxonomy.org/termconnect/developer-reference/rest-api.html#query-filter-htax_term_uri) as a REST query param on term collection endpoints for TermConnect-enabled taxonomies. It is not a HyperTaxonomy-defined filter — it is a TermConnect-side REST feature that allows querying terms by their stored external link identifier:

```
GET /wp-json/wp/v2/tags?htax_term_uri=https://www.wikidata.org/wiki/Q28865&_fields=id
```

HyperTaxonomy does not define or handle this param. It is provided by TermConnect on any site where TermConnect is installed, enabling automatic cross-site term resolution by shared knowledge-base identifier without manual slug configuration.
