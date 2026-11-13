---
title: Term Meta
parent: Developer Reference
nav_order: 6
---

# Term Meta

GraphQL plugins, page builders and themes can read term meta but not HyperTaxonomy's REST fields. So HyperTaxonomy answers `get_term_meta()` for the keys below with values computed from its own caches. Nothing is stored in the term meta table, and the values change as soon as a term's relationships do.

## Keys

Available for terms in every HyperTaxonomy-enabled taxonomy, except Term Groups themselves.

| Key | Value |
|---|---|
| `htax_includes` | IDs of the terms whose posts this term's archive includes: direct inclusions, group inclusions and container-group inclusions, after exclusions. Not WordPress child terms, which WordPress already provides. |
| `htax_containers` | IDs of the terms whose archives include this term's posts. |
| `htax_synonyms` | IDs of this term's synonyms: terms it includes that also include it. |
| `htax_groups` | IDs of the Term Groups this term is assigned to. Only direct assignments, not the parent groups of those groups. |
| `htax_archive_term_ids` | IDs of every term whose posts this term's archive shows: the term itself, its child terms and its inclusions. Use them in one tax query to get the archive's posts. |
| `htax_redirect` | ID of the term this term redirects to, or `0`. |
| `htax_icon_url` | URL of the term's icon at the large size, the taxonomy's configured icon size. `''` when there's no icon. |
| `htax_icon_url_small`, `htax_icon_url_medium`, `htax_icon_url_large` | URL of the icon at each size the taxonomy's icon settings generate. |

Each relationship key is empty when that feature is turned off for the taxonomy, as in the archive itself.

```php
$included = get_term_meta( $term_id, 'htax_includes', true ); // [ 12, 34 ]
$icon     = get_term_meta( $term_id, 'htax_icon_url', true ); // 'https://…' or ''
```

With `$single = false`, a list key returns its IDs as separate values, like any meta key with several values, and a single-value key returns a one-item array, or an empty array when there's no value.

## Hidden terms

Hidden terms are left out of these values **for every viewer**, editors included, and a hidden term returns nothing for every key. So every viewer gets the same answer, whether the request is a GET or a POST, and responses are safe for page and GraphQL caches to share. This is the same rule as HyperTaxonomy's [REST relationship fields](rest-api.html#hidden-terms-and-rest).

Most GraphQL plugins send queries as POST requests, which WordPress's own term functions don't filter for hidden terms (see [PHP API → Hidden Terms](php-api.html#hidden-terms)). These keys are filtered regardless.

## Read-only

Writing to these keys with `add_term_meta()`, `update_term_meta()` or `delete_term_meta()` does nothing and returns `false`. Relationships are edited on the term edit screen or with the [PHP API](php-api.html).

Reading all of a term's meta at once (`get_term_meta( $term_id )`) returns stored meta only, so plugins that copy or export a term's meta don't pick these keys up.

The keys are registered with `register_term_meta()` for each enabled taxonomy, so tools that list registered meta can find them. They aren't added to the REST API, which already carries the same relationships in its own fields (below).

## How the keys compare with the REST fields

Several keys overlap with HyperTaxonomy's [REST fields](rest-api.html#term-endpoint-enrichment), in a different shape. Both leave hidden terms out for everyone.

| Term meta key | REST field | Difference |
|---|---|---|
| `htax_includes` | `htax_includes` | The REST field lists each term with its name, slug and link, plus its `strength` and a `synonym` flag, and for hierarchical taxonomies also the WordPress child terms. The meta key holds IDs of HyperTaxonomy's inclusions only; child terms are in `htax_archive_term_ids`. |
| `htax_synonyms` | `htax_synonyms` | REST gives each synonym's ID, slug, name and taxonomy; meta gives IDs. |
| `htax_redirect` | `htax_redirect` | REST gives the target's ID, name, slug and link; meta gives its ID, or `0`. |
| `htax_icon_url`, `htax_icon_url_small` / `_medium` / `_large` | `htax_icon` | REST gives each size's URL, width and height in one object; meta gives one URL per key. |
| `htax_containers`, `htax_groups`, `htax_archive_term_ids` | — | Term meta only. |
| — | `htax_relationships`, `htax_locations`, `htax_is_hidden` | REST only. |

Use the REST fields to get related terms' names and slugs in one request, as TermConnect does between peer sites. Use the meta keys from tools that can't read REST fields, or from PHP.

## Using them with GraphQL

**Gato GraphQL** reads term meta through its `metaValue` field once the keys are allowed. Add them to the allowlist under its taxonomy meta settings (empty by default), then query, for example, `metaValue(key: "htax_includes")` on a term.

**WPGraphQL** doesn't expose term meta by default. Add fields for the keys you need, for example in your theme's `functions.php` or a small plugin:

```php
add_action( 'graphql_register_types', function () {
    $lists = array(
        'htaxIncludes'       => 'htax_includes',
        'htaxContainers'     => 'htax_containers',
        'htaxSynonyms'       => 'htax_synonyms',
        'htaxGroups'         => 'htax_groups',
        'htaxArchiveTermIds' => 'htax_archive_term_ids',
    );
    foreach ( $lists as $field => $key ) {
        register_graphql_field( 'TermNode', $field, array(
            'type'    => array( 'list_of' => 'Int' ),
            'resolve' => function ( $term ) use ( $key ) {
                return get_term_meta( $term->databaseId, $key, true );
            },
        ) );
    }
    register_graphql_field( 'TermNode', 'htaxIconUrl', array(
        'type'    => 'String',
        'resolve' => function ( $term ) {
            return get_term_meta( $term->databaseId, 'htax_icon_url', true ) ?: null;
        },
    ) );
} );
```

```graphql
{
  tag(id: "12", idType: DATABASE_ID) {
    name
    htaxIncludes
    htaxIconUrl
  }
}
```

The keys hold term IDs, so a second query fetches the related terms' names, for example `tags(where: { include: [34, 56] }) { nodes { name uri } }`.
