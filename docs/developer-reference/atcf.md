---
title: As the Crow Flies Integration
parent: Developer Reference
nav_order: 6
---

# As the Crow Flies Integration
{: .no_toc }

HyperTaxonomy ships a built-in integration with the [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) (ATCF) plugin. ATCF attaches physical locations to taxonomy terms and provides radius-based proximity search. The integration is defined in `includes/integrations/class-atcf-integration.php` and is loaded automatically — no configuration required.

Locations of a hidden term are left out for viewers who can't see it: ATCF skips locations whose term doesn't resolve, and `get_term()` doesn't resolve a hidden term for them.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Detection

The integration class checks `function_exists( 'atcf_get_term_ids_within_radius' )` on `plugins_loaded`. Hook registrations that require ATCF are only registered when ATCF is active. The `[ht_distance_field]` shortcode is registered unconditionally so the tag is recognized in page content at all times; if ATCF is inactive when the shortcode renders, site administrators see a notice and other users see nothing.

## [ht_distance_field] Shortcode

Registered by `HyperTaxonomy_ATCF_Integration::register_distance_shortcode()` (handler `render_distance_shortcode()`). When the form is submitted, the integration hooks `htax_pre_search_fields` to intercept the field before the SQL query runs and replace it with a resolved list of matching term IDs.

**Location resolution order:**
1. JS-provided coordinates (`_atcf_lat` / `_atcf_lng` POST/GET params, populated by the geolocation button JS)
2. When the visitor typed a postal code: its centroid (`atcf_get_term_ids_within_radius()`) — a typed place always wins over the sources below
3. Otherwise `atcf_get_visitor_location()`: CDN location headers, IP geolocation, then the ATCF fallback location

The search widget JS injects `_atcf_lat` / `_atcf_lng` hidden fields into the form on init and populates them when the user activates the crosshair geolocation icon. The icon sits inside the postal code input (right edge); clicking it requests browser geolocation, reverse-geocodes the result to a postal code or city name via `window.atcfGeo.reverseGeocode`, populates the input with that label, and turns gray. If the user subsequently edits the input, the icon reactivates and the stored coordinates are cleared. All icon lifecycle logic is handled by `window.atcfGeo.initGeoBtn` (exposed by ATCF's `frontend.js`); the HT search widget only provides the `onSuccess`/`onReset` callbacks that write to the hidden fields.

The `_atcf_lat` / `_atcf_lng` fields are read by `near:me` PowerSearch queries, so a page that has both a search widget and a PowerSearch block can share the resolved coordinates automatically.

`htax_allowed_field_types` is also hooked to add `'distance'` to the accepted field type list.

Full attribute reference: see [[ht_distance_field]](../user-guide/search-widget.html#ht_distance_field--location-based-distance-filter) in the User Guide.

The `expand` attribute (`all` / `none` / comma-separated `container,hierarchy,redirect`) is available on all search widget field types — `[ht_pills_field]`, `[ht_checklist_field]`, `[ht_hidden_field]`, and `[ht_distance_field]`. It controls which `relationship_type` values are included in the `wp_htax_search_terms` lookup for that field. Normalization is handled by `HyperTaxonomy_Search_Widget::normalize_expand_types()` at query time; `[ht_distance_field]` passes the raw value through unchanged when resolving term IDs.

## Location Data in REST Responses (`htax_locations`)

When **Share list of locations** is enabled for a taxonomy in Settings → HyperTaxonomy → Features, term REST responses for that taxonomy include an `htax_locations` field. It is available on both individual term responses and peer collection responses (when `htax_locations` is requested in `_fields`). The field is computed by `ATCF_REST_API::format_term_locations( int $term_id )`, which is owned by ATCF and caches results per term ID for the lifetime of the request.

```json
"htax_locations": [
  {
    "@type": "schema:Place",
    "schema:name": "Main Office",
    "schema:address": {
      "@type": "schema:PostalAddress",
      "schema:streetAddress": "123 Main St",
      "schema:addressLocality": "San Francisco",
      "schema:addressRegion": "CA",
      "schema:postalCode": "94103",
      "schema:addressCountry": "US"
    },
    "schema:geo": {
      "@type": "schema:GeoCoordinates",
      "schema:latitude": 37.7749,
      "schema:longitude": -122.4194
    }
  }
]
```

Fields within each entry are sparse — only keys with non-empty values are included. `schema:geo` is absent when lat/lng are not set. This data is exposed for peer sites and linked-data consumers; cross-site term matching by location is handled separately via canonical-address URIs (see [Canonical-Address URIs for Cross-Site Term Matching](#canonical-address-uris-for-cross-site-term-matching) below).

## PowerSearch Proximity Operator (near:)

When ATCF is active, `htax_parse_search_query()` recognizes proximity operators in PowerSearch queries and in the search widget's text field:

| Syntax | Reference point |
|---|---|
| `near:taxonomy:slug[/r]` | All ATCF locations of the named term, plus WP descendants and HT inclusions |
| `near:X[/r]` | X checked against the configured country's postal format (uppercase). Match → postal centroid; no match → slug lookup across all location-enabled taxonomies |
| `near:me[/r]` | User location — see `get_user_location()` below |
| `-near:...` | Negation — excludes posts whose terms are in range |

Location-enabled taxonomies are the public taxonomies enabled in As the Crow Flies settings. `near:X` with an ambiguous value (e.g. `near:ny`) tries the postal format first, then falls back to slug lookup — `near:ny` finds and ORs all terms with slug `ny` across the location-enabled taxonomies. The radius `r` is in the site's ATCF distance unit (`atcf_distance_units`, miles or km), and defaults to 10.

**Country context** — the `near_country` attribute on `[ht_power_search]` (default `'US'`) or the HT PowerSearch block controls which country's postal format is used for disambiguation and centroid lookup. Example: `[ht_power_search near_country="CA"]` makes `near:H3H2A9` look up a Canadian centroid.

A post passes `near:` when at least one of its terms in a location-enabled taxonomy has a location in range; posts whose location is unknown (no such terms, or none with a location) do not pass.

Multiple positive `near:` clauses are unioned regardless of the search's AND/OR mode; unresolvable clauses are ignored. `-near:` clauses always remove in-range posts. When ATCF is inactive, unrecognized `near:` text passes through to FULLTEXT unchanged.

**`near:`-only searches** — a PowerSearch query or search-widget text field holding only `near:` tokens returns every indexed, published post with a location in range (a `near:` whose location is unknown is ignored, as in any search, leaving every post). Relevance is the distance score from the `htax_search_proximity_relevance` filter, so the default order (`relevance:desc,date:desc`) is nearest first, then newest first among equal distances. A configured `orderby` (shortcode/block attribute, form setting, or `htax_orderby`) replaces that default as for any search. Text score thresholds (`min_score` etc.) don't apply.

## Proximity search scoring

`htax_search_proximity_relevance` (filter)

```php
add_filter( 'htax_search_proximity_relevance', function( string $sql, array $proximity, string $post_column ): string {
    // Return an SQL expression scoring the post for a near:-only search ('0' = none).
    return $sql;
}, 10, 3 );
```

The ATCF integration returns `1 / (1 + d)` per post, `d` being the distance in km from the nearest reference point to the nearest location among the post's in-range terms (a correlated `MAX(CASE term_taxonomy_id …)` subquery over `term_relationships`). Same SQL rules as `htax_search_proximity_where`: no `%` characters.

## Proximity search condition

`htax_search_proximity_where` (filter)

Fired by both PowerSearch (`perform_indexed_text_search()`) and the search widget (`get_posts_by_custom_sql()`) when the query contains `near:` tokens. The returned SQL condition is added to the query's WHERE clause, before ORDER BY / LIMIT, so pagination and result totals reflect it. The ATCF integration builds the location condition through this filter; other callbacks can add conditions of their own.

```php
add_filter( 'htax_search_proximity_where', function( string $where, array $proximity, string $post_column ): string {
    // $where       — condition from earlier callbacks ('' = none); keep it (AND yours onto it)
    // $proximity   — parsed near: clauses from htax_parse_search_query()
    // $post_column — SQL expression for the post ID column, e.g. 'pt.post_id'
    // Return a condition without a leading AND, or '' for no constraint. The SQL is
    // passed through $wpdb->prepare() later, so it must contain no % characters —
    // interpolate integers and esc_sql() strings.
    return $where;
}, 10, 3 );
```

Each `$proximity` element:
```php
[
    'negated'       => bool,
    'location_type' => 'me' | 'term' | 'postal_code',
    'taxonomy'      => string|null,   // set for location_type='term'
    'term_slug'     => string|null,   // set for location_type='term'
    'postal_code'   => string|null,   // set for location_type='postal_code'; may be slug — resolution deferred
    'country'       => string,        // ISO 3166-1 from near_country (default 'US')
    'radius'        => float,         // in the ATCF distance unit (atcf_distance_units)
]
```

**Note on `postal_code` / slug disambiguation:** `location_type='postal_code'` is set whenever the value has no colon and is not `me`. The actual resolution (postal format check → centroid vs. slug lookup) happens in `resolve_reference_points()`, not at parse time, so the raw value is preserved as-is.

## Current visitor's location

`HyperTaxonomy_ATCF_Integration::get_user_location()`

Public static helper that resolves the current user's location for use in `near:me` queries or custom filters:

```php
$loc = HyperTaxonomy_ATCF_Integration::get_user_location();
// Returns ['lat' => float, 'lng' => float, 'source' => 'browser'|'cdn'|'ip'|'fallback'] or null
```

It returns ATCF's `atcf_get_visitor_location()` — the single location chain shared with ATCF's own shortcodes and blocks: browser coordinates sent with the request (`_atcf_lat` / `_atcf_lng`), CDN location headers, IP geolocation, then the ATCF **Fallback location** setting. The search widget and PowerSearch scripts get the browser coordinates when a query contains `near:me` (`assets/js/htax-geo.js`: `HTAX_Geo.wantsLocation( text )`, and `HTAX_Geo.get()`, which delegates to ATCF's `atcfGeo.getBrowserLocation()` — the current position, otherwise the last one the browser gave, remembered for 7 days). In POST mode the coordinates are added to the form, so they travel in the results-page token and every page uses the same location.

When a `near:` clause can't be resolved, or `near:me` falls back to the fallback location, the integration records a visitor-facing message with `htax_add_search_notice()` (see [PHP API → Search notices](php-api.html#search-notices)).

## Terms within range of points

`HyperTaxonomy_ATCF_Integration::get_in_range_term_ids( $ref_points, $radius_km, $taxonomy )`

Public static helper that returns term IDs in `$taxonomy` whose ATCF locations are within `$radius_km` of any of the given `[[lat, lng], ...]` reference points. Useful for building custom pre-query distance filters:

```php
$ref = [[45.52, -122.68]]; // Portland OR
$term_ids = HyperTaxonomy_ATCF_Integration::get_in_range_term_ids( $ref, 16.09, 'post_tag' ); // ~10 miles
```

## As the Crow Flies functions HyperTaxonomy uses

The integration calls these ATCF functions, which are documented with the rest of ATCF's API in its [PHP API](https://hypertaxonomy.org/as-the-crow-flies/developer-reference/php-api.html):

- `atcf_get_term_ids_within_radius()` and `atcf_get_locations_within_radius()` — the distance searches behind `near:` and the distance field.
- `atcf_get_visitor_location()` — the visitor's location for `near:me`.
- `atcf_get_postal_centroid()` and `atcf_get_postal_code_format()` — resolving and validating postal codes in `near:` tokens.
- `ATCF_Locations::get_by_term_id()` — a term's locations for the `htax_term_locations` filter, mapped to that filter's keys.
- `ATCF_Locations::get_aggregated()` — a term's locations across its archive scope, with HyperTaxonomy's hidden terms excluded and its inclusions added.
- `ATCF_REST_API::format_term_locations()` — the `htax_locations` REST field. HyperTaxonomy calls it behind a `method_exists()` check, since ATCF may be inactive.

## Canonical-Address URIs for Cross-Site Term Matching

Cross-site matching of peer/FOAF terms by physical location works through **shared-identifier (URI) matching** — the same mechanism TermConnect uses for knowledge-base identifiers (Wikidata, GeoNames, LCSH) and Social Hashtags & Mentions handles.

ATCF exposes a **canonical address** per location (country + postal code + normalized address line) via `ATCF_Locations::get_normalized_addresses()`. HyperTaxonomy's ATCF integration bridges this into TermConnect by contributing a **canonical-address URI** — e.g. `urn:atcf:place:US:62704:123-main-street` — to the term's TermConnect `_tc_uris` index through TermConnect's `tc_index_extra_uris` filter.

Because the URI is derived from the canonical address rather than the term slug or label, the same place matches across sites even when the terms have different slugs and labels. As the Crow Flies itself stays unaware of TermConnect; HyperTaxonomy — which integrates with both — does the bridging.

## JavaScript Event

ATCF's admin term list script fires a jQuery event when it adds its location filter to the screen, passing the filter's container:

```javascript
jQuery( document ).on( 'atcf:termFilterInserted', function( e, $container ) {
    // $container — jQuery object for ATCF's filter elements
} );
```

HyperTaxonomy listens for it to move the filter into its own filter row. `[ht_distance_field]` doesn't fire any event.
