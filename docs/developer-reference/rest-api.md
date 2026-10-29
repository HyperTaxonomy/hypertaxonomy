---
title: REST API
parent: Developer Reference
nav_order: 5
---

## 9. REST API

### `X-HyperTaxonomy` response header

When the **Presence Header** setting is enabled (the default), HyperTaxonomy adds an `X-HyperTaxonomy: {version}` header to every WordPress REST API response. Peer sites use this to detect HT presence (e.g. via `HEAD /wp-json/`) without a dedicated discovery endpoint.

Can be disabled under **Settings → HyperTaxonomy → Advanced → HyperTaxonomy Presence Header**. Disabling opts this site out of peer discovery entirely — remote TermConnect installs will not detect HT and will not attempt scored requests or vocabulary sync.

### Term endpoint enrichment

HyperTaxonomy hooks `rest_prepare_{taxonomy}` for all public `show_in_rest` taxonomies. All HT-added fields respect the standard WP REST `_fields` parameter — if a caller supplies `_fields`, only the requested fields are computed (unrequested fields are skipped, not just stripped). `htax_includes` and `htax_synonyms` can be disabled site-wide under **Settings → HyperTaxonomy → Advanced → Term Inclusion Relationships**. `htax_relationships` is additionally disabled when either **Term Inclusion Relationships** or **Custom Term Relationships and Relationship Types** is off.

The following fields are available. All can be requested via `_fields`; availability on individual vs. peer collection responses is shown:

| Field | Individual | Peer collection | Notes |
|-------|-----------|-----------------|-------|
| `htax_is_hidden` | ✓ | ✓ | |
| `htax_icon` | ✓ | ✓ | Icons feature must be enabled |
| `htax_redirect` | ✓ | ✓ | |
| `htax_includes` | ✓ | ✓ | Gated by Term Inclusion Relationships setting |
| `htax_synonyms` | ✓ | ✓ | Gated by Term Inclusion Relationships setting |
| `htax_locations` | ✓ | ✓ | Requires ATCF active + share_locations feature enabled |
| `htax_relationships` | ✓ | ✓ | Storage-only custom types only; disabled if either Term Inclusion Relationships or Custom Term Relationships and Relationship Types setting is off |

Collection endpoints (`GET /wp/v2/{taxonomy}?slug=…`) without the `X-HyperTaxonomy` header receive no HT enrichment.

### Peer collection requests (`X-HyperTaxonomy` request header)

When a request includes the `X-HyperTaxonomy` header, HyperTaxonomy treats it as a peer site request. All fields in the table above are available on peer collection responses when requested in `_fields`. Callers should include only the fields they need.

TermConnect `_fields` per request type:
- **Vocabulary sync** — `id,slug,name,parent,description,link,htax_redirect` (identifiers via TC's own `tc_links` field on the TC-side endpoint)
- **Relationship sync** — `id,slug,htax_includes,htax_relationships,htax_redirect`

**`htax_includes`** in peer responses: WP children (hierarchical) + all inclusion-based relationships (built-in `includes` and custom `core_type = 'includes'`), as term stubs. Each stub carries a `strength` — the relationship's explicit weight (hierarchy weight for WP descendants, container weight for inclusions) — so consumers can weight included terms exactly as HyperTaxonomy's own index does. Gated by the **Term Inclusion Relationships** setting.

**`htax_relationships`** in peer responses: effective outbound storage-only custom relationships (`core_type = null`, non-private). Format: `[{ relationship_type, target_slug, target_taxonomy }]`. Inclusion-based types excluded (they travel via `htax_includes`). Disabled if either **Term Inclusion Relationships** or **Custom Term Relationships and Relationship Types** is off — this prevents sync failures caused by relationship rows that reference type definitions the receiving site cannot register.

**`htax_locations`** in peer responses: Schema.org Place objects with address and optional geo coordinates. Same structure as individual response. Format: `[{ "@type": "schema:Place", "schema:name": "...", "schema:address": {...}, "schema:geo": {...} }]`. Gated by `share_locations` feature flag.

### Scored post collection (`GET /wp/v2/posts`)

HyperTaxonomy extends the standard WordPress post collection endpoint with term-based scoring. This is the mechanism TermConnect uses to fetch relevance-ranked posts from HT-enabled peer sites.

**Query parameters** (all optional; none applied unless `htax_includes` is present):

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `htax_includes[{primary_id}]` | comma-separated int | — | Map of local primary term ID → comma-separated list of IDs to include. Keys are primary terms; values are their transitive inclusions. Multiple primary terms = multiple keys. |
| `htax_match` | `any` \| `all` | `any` | `any`: return posts matching at least one primary term. `all`: return only posts that match at least one ID in every primary group (AND mode). |
| `htax_term_weights[{term_id}]` | float | `1.0` | Per-term score weight, keyed by any term ID appearing in `htax_includes` (primary or included). The requester computes these from its own per-taxonomy relevance weights (e.g. direct, container) and sends them so the peer scores by the *requester's* weighting without knowing its relationship structure. Terms without an entry default to `1.0`. |
| `htax_expand` | `0` \| `1` | `0` | When `1`, the peer also matches the requested terms through its *own* relationship graph (not just direct assignments), surfacing more of its content; non-direct matches to a primary term are discounted by `0.6`. When `0`, only direct term assignments count. |

**Scoring formula:** for each post, take the best matched weight per requested term (with the expand discount applied to non-direct primary matches when `htax_expand=1`), sum those, and divide by the sum of the primary-term weights, clamped to 1.0. Results are ordered by score descending. The standard `tags=`/`categories=` params remain active and AND with the scored filter.

**Response fields on each post object** (available via `_fields`):

| Field | Type | Description |
|-------|------|-------------|
| `htax_score` | float \| null | Relevance score 0–1, or null if this post was not scored. |
| `htax_matched_term_slugs` | string[] \| null | Remote slugs of the primary terms that contributed to this post's score, or null if not scored. These are the slugs as they appear on the remote (peer) site. |
| `htax_term_peers` | object \| null | Registered by TermConnect (when active). Per-term peer connection data for all terms on the returned posts; used by TC for FOAF peer discovery without a separate term lookup round-trip. See TermConnect documentation. |
| `htax_term_strengths` | object \| null | **Opt-in — request explicitly via `_fields`.** Map of the post's directly-assigned term IDs → association strength (0–1, how central each term is to the post, from the strength model selected in Settings → Search; terms that model hasn't scored are omitted). Keyed by the term IDs the post already lists natively, so it adds the strength without duplicating term data. Visibility-filtered. `null` unless requested (the per-post index lookup runs only when the field is named in `_fields`). |

**Example request** (two primary terms, any-match, per-term weights, peer expansion on):

```
GET /wp-json/wp/v2/posts
  ?htax_includes[42]=42,101,102
  &htax_includes[55]=55,201
  &htax_match=any
  &htax_term_weights[42]=1.0
  &htax_term_weights[55]=1.0
  &htax_term_weights[101]=0.7
  &htax_term_weights[201]=0.7
  &htax_expand=1
  &_fields=id,title,link,htax_score,htax_matched_term_slugs
  &per_page=20
  &orderby=score
```

The `orderby=score` value is valid only when `htax_includes` is present; otherwise the request is rejected.

### Relationship type endpoints (`htax/v1`)

Two read-only endpoints under the `htax/v1` namespace expose relationship type definitions and term relationships for peer syncing. Both are public (no authentication required).

**`GET /wp-json/htax/v1/relationship-types`**

Returns locally-owned relationship type definitions. Remote-delegated and private types are always excluded. **Inclusion-based custom types** (`core_type = "includes"`) are excluded by default — they travel via `htax_includes` in the peer collection response. Pass `include_inclusion_based=1` to include them when the full type vocabulary is needed. Each entry includes a `namespace` field:

| Field | Description |
|-------|-------------|
| `slug` | Type slug |
| `namespace` | `https://hypertaxonomy.org` for built-in types; `home_url()` for local custom types |
| `label` | Forward label |
| `inverse_label` | Reverse label (empty string if none) |
| `core_type` | `"includes"` for inclusion-based; `null` for plain custom |
| `show_in_ui` | Whether the type appears in term edit screens |
| `symmetric` | Whether the relationship is directionless |
| `source_taxonomies` | Allowed source taxonomies (empty = any) |
| `target_taxonomies` | Allowed target taxonomies (empty = any) |

Built-in types and locally-created/promoted types are included. Remote-delegated types (`source_site` set) are excluded — they are accessible at the originating site's endpoint. Private types (`is_private = true`) are also excluded.

**`GET /wp-json/htax/v1/term/{id}/relationships`**

Returns effective (group-expanded) outbound custom relationships for a term. Built-in and private types are always excluded. Inclusion-based custom types are excluded by default — they already appear in `htax_includes` in the peer collection response. Pass `include_inclusion_based=1` to include them as named entries alongside storage-only types. Returns an empty array if the term has no matching relationships, or a 404 if the term ID does not exist.

Each entry:

```json
{
  "relationship_type": "wrote",
  "target_term_id": 42,
  "target_slug": "django",
  "target_taxonomy": "post_tag"
}
```

**Privacy.** Mark a type private in **Terms → Term Relationships → Local types → Edit → Private** to exclude it and all its relationship rows from both endpoints. Private types remain fully functional locally.

Both endpoints can be disabled entirely under **Settings → HyperTaxonomy → Advanced → Peer Relationship Delegation API** — the routes are not registered when this is off.

The full enrichment fields (icon, includes, synonyms) are deliberately excluded from peer collection responses — they are expensive per-term and not needed for identity resolution.

### Hidden terms and REST

Hidden terms are fully protected:
- **Collection** (`GET /wp/v2/{taxonomy}`): excluded via the `exclude` parameter; unauthorized users never see them in lists.
- **Individual** (`GET /wp/v2/{taxonomy}/{id}`): returns `404 Term not found` for unauthorized users.
- Authorized users (determined by `htax_can_see_hidden_terms()`) see hidden terms normally and receive an `htax_is_hidden: true` field.

---

## 10. External Posts Platform API

This API is part of the **TermConnect** plugin. Peer site fetching, RSS/Atom feed aggregation, FOAF discovery, and cross-site scoring functions (`tc_external_*`) are documented in the [TermConnect Developer Reference](https://wordpress.org/plugins/termconnect/).

Cross-site matching of peer/FOAF terms by physical location is handled through TermConnect's shared-identifier (URI) matching — the same path used for knowledge-base identifiers (Wikidata, GeoNames) and Social Hashtags & Mentions handles. When As the Crow Flies is active, HyperTaxonomy contributes a canonical-address URI (e.g. `urn:atcf:place:US:62704:123-main-street`) to each term's TermConnect `_tc_uris` index via TermConnect's `tc_index_extra_uris` filter, derived from `ATCF_Locations::get_canonical_addresses()`. The same place therefore matches across sites even when the term slugs and labels differ. See [As the Crow Flies Integration](atcf.html) for details.

---

