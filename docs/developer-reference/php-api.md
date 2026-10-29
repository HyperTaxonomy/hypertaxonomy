---
title: PHP API
parent: Developer Reference
nav_order: 3
---

## 3. Integration API

`includes/core/integration-api.php` provides a stable public surface for third-party plugins that need to work with HyperTaxonomy's archive expansion logic. Internal cache-invalidation infrastructure (`htax_fire_term_archive_changed`, `htax_fire_archive_changed_on_hierarchy`) lives in `cache.php`.

### `htax_get_expanded_term_ids( int $term_id, bool $include_redirect_sources = false ): array`

Returns the complete flat list of term IDs whose posts appear in a term's archive: the term itself, WordPress descendants (for hierarchical taxonomies), and all HyperTaxonomy-contributed terms (inclusions, group memberships, container groups). Returns an empty array if the term does not exist. When the `term_relationships` feature is disabled for its taxonomy, only the term itself and its visible WordPress descendants are returned (no HyperTaxonomy contributions). WordPress term IDs are globally unique, so no taxonomy parameter is needed.

Pass `$include_redirect_sources = true` to also fold in terms that redirect (directly or transitively) to this term — so a query on a redirect *target* also matches posts tagged with the *redirecting* terms, matching the relevance index used by search and `[ht_related_posts]`. This is the default-off behavior because archive expansion proper does not follow redirects (a redirect 301s the archive request); discovery surfaces such as `[tc_peer_posts]`/`[tc_peer_browse]` and the WP-SKOS document endpoint pass `true`. The redirect inclusion is gated by the taxonomy's Redirects feature and filtered for term visibility.

Applies the `htax_expanded_term_ids` filter before returning.

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

Setting `include_children => false` is important: the list already contains all descendant IDs, so letting WordPress re-expand would double-count them.

### `htax_build_expanded_tax_query( int $term_id, string $taxonomy, ?WP_Term $term_obj = null ): ?array`

Builds the OR `tax_query` array for a term's archive expansion. Returns `null` if HyperTaxonomy has no contributions to add (i.e. the expansion result equals native WordPress behavior alone), or if the `term_relationships` feature is not enabled for the taxonomy. Pass a `WP_Term` object as the third argument to avoid a redundant database lookup.

This is the lower-level function used internally by both `htax_modify_archive_query` (main archive) and `htax_expand_secondary_query` (secondary queries). Use `htax_get_expanded_term_ids()` for most integration needs; use this function when you need direct access to the tax_query array structure.

### Expanding secondary queries: `htax_expand_secondary_query`

A `pre_get_posts` handler (priority 10) that expands single-term tax_query clauses for enabled taxonomies in secondary and third-party queries. It skips the main archive query, which is already handled by `htax_modify_archive_query`.

**Per-query control via the `htax_expand_terms` query var:**

```php
// Suppress expansion for a specific query:
$query->set( 'htax_expand_terms', false );

// Force expansion regardless of the global setting:
$query->set( 'htax_expand_terms', true );
```

**Global on/off:** Enable via **Settings → Search → Third-Party Query Expansion**. Defaults to off. When on, secondary queries using a single-term tax_query clause for a HyperTaxonomy-enabled taxonomy are automatically expanded.

Only single-term clauses are expanded. Multi-term clauses are treated as facet filters chosen by the user, not archive expansions.

### `htax_adjust_term_counts`

A `get_terms` filter that replaces native WordPress term counts with expansion-aware counts. Active on the frontend only, and only when `expand_secondary_queries` is enabled. This ensures that term counts shown in facet navigation (WooCommerce layered nav, custom filter panels) reflect the expanded post set rather than direct assignments alone.

Per-term counts can be further customized via the `htax_term_post_count` filter.

### `htax_term_archive_changed` action

Fires whenever a term's archive composition changes — relationships, redirects, hierarchy, or post assignments. Use it to invalidate derived data (custom caches, pre-computed counts, external indexes):

```php
add_action( 'htax_term_archive_changed', function( $term_id, $taxonomy ) {
    // The archive for $term_id in $taxonomy has changed — clear any derived data
    my_plugin_clear_term_cache( $term_id, $taxonomy );
}, 10, 2 );
```

See the `htax_term_archive_changed` entry in [Actions Fired by HyperTaxonomy](#actions-fired-by-hypertaxonomy) for the full list of triggers.

### Post Editor Term Badges

When editors assign tags or categories in the Gutenberg post editor, HyperTaxonomy adds contextual relationship indicators (badges and visual styling) to each term pill or list item. These badges help editors understand the semantic relationships of the terms they're adding.

The post editor displays the following visual cues:

- **Includes badge** (`⇉ [N]`): Light blue circle with a rightward-double-arrow symbol and count. Indicates this term's archive effectively includes N other terms' posts (via direct inclusion, inclusion groups, or container-group relationships).
- **Container badge** (`⇇ [N]`): Light green circle with a leftward-double-arrow symbol and count. Indicates N other terms include this term's posts in their archives.
- **Redirect styling** (*italic text*): When a term is a redirect, its pill/list item is displayed in italics. An additional hook symbol (↪, U+21AA) is appended to the term name as an extra visual cue.
- **Hidden styling** (*opacity fade*): Terms marked as hidden are displayed with reduced opacity (45%).

**Badge colors:**

- Includes badge: `#ddeeff` background, `#7ab8e8` border, `#003366` text (light blue)
- Container badge: `#eef7ef` background, `#a5d6a7` border, `#2e7d32` text (light green)

Badge rendering is handled by `admin/js/htax-post-edit.js` and `admin/css/htax-post-edit.css`. Badge metadata is provided to the editor via the `htaxTermMeta` JS global (keyed by term ID), populated by `htax_enqueue_post_editor_assets()` in `includes/admin/post-edit.php`.

**Which features appear is governed by two gates, both of which must be on:**

1. **Per-user preference** toggled in the Gutenberg block editor's **··· menu (More options) → HyperTaxonomy group**. These are stored in the `core/preferences` store under the `htax/post-edit` scope and persist per user. Defaults:
   - Inclusion Badges — off by default
   - Container Badges — off by default
   - Redirect Indicators — on by default
   - Hidden Terms — on by default
   - Sticky Pushpins — off by default
2. The corresponding feature enabled for that taxonomy (e.g. `redirects`, `term_relationships`, `visibility`, `customize_archives`) in **Settings → HyperTaxonomy → Features**.

**JS globals emitted alongside `htaxTermMeta`:**

- `htaxPreloadedTerms` — assigned term objects injected into the Gutenberg Redux store before the block editor initializes, eliminating per-term REST calls. Not present in the Classic Editor.
- `htaxTaxonomyFeatures` — `{[taxonomy]: {inclusions, containers, redirects, visibility}}` taxonomy-level feature gates. User display preferences are applied client-side and not reflected here.
- `htaxFlatTaxonomies` — `[{slug, restBase}]` for flat HT-enabled REST-exposed taxonomies; drives the autocomplete subscriber that calls `GET /wp-json/htax/v1/term-meta` when a term is added mid-session.
- `htaxStickyConfig` — `[{name, label, restBase}]` for taxonomies with the `customize_archives` feature; always emitted in the block editor when at least one eligible taxonomy exists.

---


## 5. Term Icons API

HyperTaxonomy provides a complete PHP API for displaying terms as styled pills with icons. All icon functions accept either a WP_Term object or a term ID.

### Upload rules

Icons are raster images (PNG, JPEG, WebP) or SVGs stored as standard WordPress media attachments. The attachment ID is saved in `_htax_icon_id` term meta.

**Dimension enforcement** is applied at selection time in the admin UI (`htax-term-icons.js`):

| Image dimensions | Behaviour |
|---|---|
| Equal to configured large size | Accepted as-is; attachment ID stored directly. |
| Larger in either dimension | Crop dialog opens. The user positions a fixed-size selection (no resize, only pan). On confirm, `wp_ajax_htax_crop_term_icon` calls `wp_crop_image()` and creates a new child attachment; the child's ID is stored as the icon. |
| Smaller in either dimension | Rejected. Images below the minimum are filtered out of the media library grid and cannot be selected. |
| SVG (`image/svg+xml`) | Accepted at any size; no pixel dimension check. |

**`wp_ajax_htax_crop_term_icon`** — requires nonce (`htax_crop_term_icon`) and `manage_term_icons` capability. Accepts `id`, `x`, `y`, `w`, `h`, `dst_w`, `dst_h`. Creates a child attachment registered under the original's `post_parent`. All registered `htax-icon-*` sizes are generated via `wp_generate_attachment_metadata()`.

**Programmatic assignment** — to set a term's icon from code, store the attachment ID directly:
```php
update_term_meta( $term_id, '_htax_icon_id', $attachment_id );
```
No dimension validation is applied on the PHP side; that is a UI-only guard.

---

### Archive Header Icon

#### `htax_the_archive_icon( string $size = 'large', array $attr = [] ): void`
#### `htax_get_the_archive_icon( string $size = 'large', array $attr = [] ): string`

Outputs (or returns) a responsive `<img>` for the icon of the current taxonomy term archive. Resolves the queried term automatically — no need to call `get_queried_object()` in the template.

Returns an empty string and outputs nothing when:
- The current page is not a taxonomy term archive (date, author, search, post-type archives are silently ignored).
- The current term has no icon set.

This means the call requires no conditional wrapper:

```php
// In archive.php or taxonomy.php — safe to call unconditionally:
htax_the_archive_icon();
```

The `<img>` includes a `srcset` built from all three registered htax sizes (small, medium, large) for the term's taxonomy, so the browser downloads the most appropriate one. The default `sizes` value is the pixel width of the requested size; pass a custom value when the theme layout requires it:

```php
// Use medium as the default src; tell the browser the image fills 50vw on small screens
htax_the_archive_icon( 'medium', [ 'sizes' => '(max-width: 600px) 50vw, 200px' ] );
```

**Parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$size` | `string` | `'large'` | Size shortcut: `'small'`, `'medium'`, or `'large'`. Also accepts full htax size names. |
| `$attr` | `array` | `[]` | Extra `<img>` attributes. `class` is merged with `htax-archive-icon`. `alt` defaults to the term name. `sizes` overrides the default sizes hint. Any other key is passed through. |

**CSS class:** `htax-archive-icon`

```php
// Minimal — large icon, term name as alt:
htax_the_archive_icon();

// Custom class and sizes:
htax_the_archive_icon( 'large', [
    'class' => 'my-archive-header-icon',
    'sizes' => '(max-width: 768px) 64px, 128px',
] );

// Return rather than echo:
$icon = htax_get_the_archive_icon( 'large' );
if ( $icon ) {
    echo '<div class="archive-header">' . $icon . '</div>';
}
```

---

### Getting Icon URLs and HTML

#### `htax_get_term_icon_url( WP_Term|int $term, string $size = 'htax-icon-small' ): string`

Returns the URL of the term's icon at the given size, or an empty string if no icon is set.

```php
$url = htax_get_term_icon_url( $term, 'small' );
// Size shortcuts: 'small', 'medium', 'large'
// Full names: 'htax-icon-small-post_tag', 'htax-icon-medium-post_tag', etc.
```

#### `htax_get_term_icon_html( WP_Term|int $term, string $size = 'htax-icon-small', array $attr = [] ): string`

Returns an `<img>` tag for the term's icon, or an empty string if no icon is set. Works on both admin and frontend.

```php
$img = htax_get_term_icon_html( $term, 'small', [ 'class' => 'term-icon' ] );
echo $img;
```

#### `HyperTaxonomy_Term_Icons::get_color_for_slug( string $slug ): string`

Returns the deterministic hex placeholder color for a given term slug (e.g. `'#1e88e5'`). Uses `crc32` hash mapped to a fixed 20-color palette — same slug always yields the same color, independent of term ID or taxonomy. Useful when building custom icon-list UIs that need to match the placeholder color used by HyperTaxonomy shortcodes.

```php
$color = HyperTaxonomy_Term_Icons::get_color_for_slug( $term->slug );
echo '<span style="background:' . esc_attr( $color ) . '"></span>';
```

### Term Pills

Pills are styled `<a>` or `<span>` elements that combine the term name with its icon (or a colored placeholder when no icon is set). Border-radius is inherited from the taxonomy's icon settings.

#### `htax_get_term_pill( WP_Term $term, array $args = [] ): string`

Returns HTML for a single term pill.

```php
$pill = htax_get_term_pill( $term, [
    'icon_size'        => 'small',       // 'small', 'medium', 'large'
    'show_placeholder' => true,          // Show icon-shaped color placeholder when no icon
    'link'             => true,          // false = non-clickable <span>
    'class'            => 'htax-pill',   // Additional CSS class
] );
echo $pill;
```

#### `htax_the_term_pills( int $post_id, string $taxonomy, array $args = [] ): void`

Outputs all term pills for a post's terms in the given taxonomy. Useful for displaying tags or categories with icons in theme templates.

```php
// In a theme template (e.g., single.php, content.php):
if ( function_exists( 'htax_the_term_pills' ) ) {
    htax_the_term_pills( get_the_ID(), 'post_tag', [
        'before'           => '<div class="post-tags">',
        'after'            => '</div>',
        'separator'        => ' ',
        'show_placeholder' => false,
        'icon_size'        => 'small',
    ] );
}
```

#### `htax_add_icon_to_term_link( string $term_link, WP_Term $term, string $show_icons = 'when_available', bool $is_hidden = false ): string`

Wraps an existing `<a>` tag with icon HTML. Useful for filtering WordPress's native term link output.

```php
// Transform native term links into icon-enhanced links
add_filter( 'term_links-post_tag', function( $links ) {
    $terms = get_the_terms( get_the_ID(), 'post_tag' );
    if ( ! $terms || is_wp_error( $terms ) ) {
        return $links;
    }

    $result = [];
    foreach ( $terms as $term ) {
        $link       = '<a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a>';
        $result[]   = htax_add_icon_to_term_link( $link, $term, 'when_available' );
    }
    return $result;
} );
```

`$show_icons` options: `'never'`, `'when_available'`, `'always'` (always shows a placeholder if no icon).

### Icon Sizes

The configured dimensions are the **large** (full) size. Upload at large size; WordPress generates medium and small automatically. The medium and small percentages default to 50% and 25% but are configurable per taxonomy in **Settings → HyperTaxonomy → Icons**. Changing percentages regenerates the smaller sizes from existing uploads without requiring re-upload.

| Shortcut | Full name pattern | Default fraction | Use case |
|----------|------------------|------------------|----------|
| `'small'` | `htax-icon-small-{taxonomy}` | 25% of large (configurable) | Pills, inline text |
| `'medium'` | `htax-icon-medium-{taxonomy}` | 50% of large (configurable) | Lists |
| `'large'` | `htax-icon-large-{taxonomy}` | 100% (configured) | Archive headers; upload size |

API functions accept both the shortcut (`'small'`) and the full name, and resolve the taxonomy-specific dimensions automatically.

### Pill Styling

Pills inherit border-radius from **Settings > HyperTaxonomy > Icons** per-taxonomy configuration. The border-radius applies to both the icon/placeholder and the pill container, creating a seamless visual style. Default CSS class is `htax-pill`; the plugin stylesheet provides base styling.

---


> **Sort & Filter Controls (PHP rendering, DOM structure, JS events)** are documented in the Architecture section: [Sort & Filter Rendering](../architecture/sort-filter-rendering.html).

## 7. Hidden Terms

### Automatic Filtering in Native Blocks

For users who cannot see hidden terms, every frontend post query — the Query Loop block, the Latest Posts block, REST `/wp/v2/posts`, and any custom `WP_Query` — treats hidden terms as if they did not exist. The `htax_hide_terms_in_tax_query` hook (`parse_tax_query`) removes hidden terms from the query's taxonomy criteria: a query for a hidden term matches nothing, `include_children` skips hidden descendants, and `NOT IN` clauses are left as written. Posts that carry a hidden term are **not** removed — they are still found through their other terms and in unfiltered lists. No extra code is needed for standard block and shortcode usage.

### Filtering Hidden Terms in Custom Queries

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

### Hidden Terms in the Search Index

The search index (`wp_htax_search_terms`) tracks an `is_hidden` flag per row. The PowerSearch and search widget shortcodes apply `AND is_hidden = 0` to all queries for unauthorized users automatically.

If you query the search index directly in a plugin, apply the same filter:

```php
global $wpdb;
$hidden_clause = htax_can_see_hidden_terms() ? '' : ' AND is_hidden = 0';

$results = $wpdb->get_results( $wpdb->prepare(
    "SELECT post_id FROM {$wpdb->prefix}htax_search_terms
     WHERE taxonomy = %s AND term_id IN (" . implode( ',', $term_ids ) . ")
     {$hidden_clause}",
    $taxonomy
) );
```

### Strength Models

Every post–term relationship in the search index carries a **strength**: how central the term is to the post, from 0 to 1. Strength ranks term-based search results and related posts. HyperTaxonomy's built-in model, `htax_tf`, derives it from how prominently the term name appears in the post's title, excerpt, and body. Another plugin — for example one that computes semantic similarity with embeddings — can register its own model and supply scores; a site owner then selects it in **Settings → Search → Strength Model**.

**How a provider integrates:**

1. Register the model on `init`, on every request. The key must change whenever scores stop being comparable (e.g. a different embedding model), so include the provider and model name.
2. Submit scores for a post's **directly assigned** terms with `htax_set_term_strengths()`. Scores must already be calibrated to 0–1; HyperTaxonomy stores them as given. It writes the rows derived from each term (broader terms, inclusions, redirects) for the same model, using the site's relevance weights.
3. Hook `htax_term_strength_missing` to score terms HyperTaxonomy reports as unscored (new posts, newly assigned terms).
4. Before your plugin is uninstalled (or when the model is retired), remove its scores with `htax_remove_term_strengths( $model )`.

```php
add_action( 'init', function () {
    if ( function_exists( 'htax_register_strength_model' ) ) {
        htax_register_strength_model( 'myplugin:embed-v2', array(
            'label'       => 'My Plugin (embeddings v2)',
            'description' => 'Cosine similarity between post and term embeddings.',
        ) );
    }
} );

// After computing scores for a post:
$result = htax_set_term_strengths( 'myplugin:embed-v2', $post_id, array(
    65  => 0.83,   // term_id => calibrated score in [0, 1]
    246 => 0.41,
) );
if ( is_wp_error( $result ) ) {
    error_log( $result->get_error_message() );
}

// In your uninstall routine:
if ( function_exists( 'htax_remove_term_strengths' ) ) {
    htax_remove_term_strengths( 'myplugin:embed-v2' );
}
```

**What site owners see:** the model appears in Settings → Search → Strength Model with its coverage (how many directly assigned post–term pairs it has scored). It can only be selected once coverage is complete. While it is selected, pairs it hasn't scored yet don't contribute to term-based ranking — HyperTaxonomy never mixes its own scores into another model's ranking. Settings → Search → Index Rebuild is replaced by **Clear Scores** for external models, because only the provider can rescore posts.

**Scores survive reindexing.** When HyperTaxonomy reindexes a post (on save, after term or relationship changes, or in a full rebuild), it keeps each model's scores for terms that are still directly assigned and re-derives the dependent rows. Scores for unassigned terms are dropped.

**Unregistering does not delete scores.** Registration is per request, so a temporarily deactivated provider keeps its scores; they are simply not read, and the selection falls back to `htax_tf` until the model is registered again (the saved selection is kept). Delete them explicitly with `htax_remove_term_strengths()` — e.g. in your uninstall routine — or let the site owner use Clear Scores: Settings → Search lists models that still have stored scores but aren't registered as *inactive*, with their coverage and Clear Scores, without allowing them to be selected.

#### Functions

**`htax_register_strength_model( string $key, array $args = [] ): bool`** — registers a model. `$key`: 1–64 characters of `a-z 0-9 _ . : -`. `$args`: `label`, `description`. Returns `false` for an invalid key or a reserved one: `htax_tf` and every other key starting with `htax_` belong to HyperTaxonomy and can't be registered.

**`htax_unregister_strength_model( string $key ): bool`** — removes a model from the registry for the rest of the request. Returns `false` for unknown keys and for reserved keys — the built-in model can't be unregistered. Stored scores are kept.

**`htax_get_strength_models(): array`** — registered models, including `htax_tf`, as `key => [ 'label' => …, 'description' => … ]`.

**`htax_set_term_strengths( string $model, int $post_id, array $strengths ): true|WP_Error`** — stores `term_id => score` for a registered external model. All-or-nothing: if any score is outside 0–1, or any term isn't directly assigned to the post in the search index (the post must be published and of an indexed post type), nothing is written. Error codes: `htax_reserved_model`, `htax_unknown_model`, `htax_no_strengths`, `htax_invalid_strength`, `htax_strength_out_of_range`, `htax_term_not_assigned` (error data lists the term IDs), `htax_db_error`. Fires `htax_term_strengths_updated`.

**`htax_remove_term_strengths( string $model, int $post_id = 0, array $term_ids = [] ): int|WP_Error`** — removes a model's stored scores: for every post (`$post_id = 0`), for one post, or for specific terms of a post, together with their derived rows. Works for unregistered models too. Returns the number of rows removed; refuses `htax_tf`. Fires `htax_term_strengths_updated`.

**`htax_get_stored_strength_models(): string[]`** — keys of external models that have scores stored, registered or not.

**`htax_get_strength_model_coverage( string $model ): array`** — per taxonomy, `[ 'scored' => int, 'total' => int ]` directly assigned post–term pairs. **`htax_strength_model_has_full_coverage( string $model ): bool`** — whether every pair is scored.

**`htax_get_strength_model( string $purpose = 'search' ): string`** — the model strength-ranked reads use: the one selected in Settings → Search if it is registered, otherwise `htax_tf`. `$purpose` is `'search'` or `'related'`; both currently read the same selection.

**`htax_strength_model_sql( string $alias = '', string $purpose = 'search' ): string`** — returns ` AND {alias}.model = '…'` for that model, safe to interpolate.

See also the actions [`htax_term_strength_missing`](actions-filters.html#htax_term_strength_missing) and [`htax_term_strengths_updated`](actions-filters.html#htax_term_strengths_updated).

#### Querying strength directly

If your query only asks *which* posts reach a term (like the hidden-terms example above), no model filter is needed — every relationship has an `htax_tf` row. If it reads or aggregates `strength`, restrict it to one model, or each relationship would be counted once per model:

```php
$results = $wpdb->get_results(
    "SELECT post_id, SUM(strength) AS score
     FROM {$wpdb->prefix}htax_search_terms st
     WHERE st.term_id IN (" . implode( ',', $term_ids ) . ")
     {$hidden_clause}" . htax_strength_model_sql( 'st' ) . "
     GROUP BY post_id"
);
```

### Hidden Terms and REST API

Hidden terms are excluded from REST API responses for users who cannot see them (based on the global visibility setting). This means REST API consumers will not receive hidden terms unless the requesting user has the required capability.

---


## 8. Low-Level Relationship Data

> **Integration use cases?** If you need to build a `tax_query` from a term's expanded post set, react to archive changes, or adjust term counts in facet navigation, start with [Section 3: Integration API](#3-integration-api) above. The functions below expose HyperTaxonomy's internal relationship model directly and are intended for plugins that need raw relationship data — custom admin UIs, relationship-based features, or building tooling on top of the graph.

All public functions are prefixed `htax_`. Check `function_exists()` before calling if your code must run without HyperTaxonomy active.

### Term Relationships

#### `htax_get_included_terms( int|WP_Term $term, bool $include_redirect_data = false, bool $include_disabled = false ): array`

Returns the terms whose posts contribute to the given term's archive via HyperTaxonomy inclusions, after applying exclusions. The result is restricted to **cross-branch** relationships: for hierarchical taxonomies, ancestors and descendants are excluded even if explicit inclusion rows exist for them. Ancestors are excluded to prevent semantic loops; descendants are excluded because they are already in the archive via WP's native `include_children` and their presence in the HyperTaxonomy inclusion set would be redundant. Archive expansion (`htax_get_expanded_term_ids`) reunites HyperTaxonomy inclusions and WP descendants at query time; this function returns only the HyperTaxonomy-managed portion.

```php
$included = htax_get_included_terms( $term_id );
// Returns array of WP_Term objects — cross-branch only, no WP ancestors or descendants

foreach ( $included as $term ) {
    echo $term->name;
}
```

Returns an empty array if the **Term Relationships** feature (`term_relationships`) is not enabled for the term's taxonomy, if no inclusions are configured, or if the term is a redirect (its relationships are preserved in the database but inactive). Pass `true` as the second argument to retrieve the stored data regardless of redirect status — the admin UI does this to display relationships grayed out. The third argument, `$include_disabled`, returns the stored relationships even while the feature is disabled; only the search index uses it (it stores relationships regardless and gates at query time).

Ancestor/descendant exclusion is enforced at four levels: the term edit save handler strips them before writing; `htax_cleanup_hierarchy_conflicts()` removes conflicts when a term is reparented; `htax_merge_terms()` removes conflicts after a merge; and `htax_repair_hierarchy_conflicts()` sweeps on plugin activation. This function's read-time filtering is a final safety net for rows written directly to the database.

#### `htax_get_container_terms( int|WP_Term $term, bool $include_disabled = false ): array`

Returns all terms that include this term's posts in their archives — the inverse view of inclusions. For hierarchical taxonomies, both descendants and ancestors are excluded: descendants cannot be containers (they cannot include their own ancestors), and ancestors are excluded for symmetry — a term's posts are already visible in ancestor archives via `include_children`, so an ancestor as a container would be redundant. Results are restricted to cross-branch relationships, indexed by term ID.

```php
$containers = htax_get_container_terms( $term_id );
// Returns array of WP_Term objects, indexed by term_id
```

Returns an empty array when the **Term Relationships** feature is disabled for the term's taxonomy; `$include_disabled = true` bypasses that (search index only).

#### `htax_get_synonym_terms( int|WP_Term $term, bool $include_redirect_data = false ): array`

Returns all terms that have a bidirectional `includes` relationship with the given term — i.e. terms where both `(A → includes → B)` and `(B → includes → A)` exist. These terms are effectively synonyms: each contributes the other's posts to its archive.

```php
$synonyms = htax_get_synonym_terms( $term_id );
// Returns array of WP_Term objects

foreach ( $synonyms as $synonym ) {
    echo $synonym->name; // e.g. "JavaScript" when called on the "JS" term
}
```

Returns an empty array when the **Term Relationships** feature is disabled for the term's taxonomy, or when the term is a redirect (unless `$include_redirect_data` is `true`).

Returns an empty array if no bidirectional inclusions are configured, or if the term is a redirect (a redirect's inclusions are inactive, so no valid synonym relationship exists). Redirect terms are also filtered from the results: if term B is a redirect, it will not appear as a synonym of A even if the DB rows exist. For archive expansion purposes `htax_get_included_terms()` is sufficient — this function is intended for display and UI use cases where the symmetric nature of the relationship is relevant.

Results are cached in a two-tier cache: a static per-request array and a persistent `htax_syno_{term_id}` transient (TTL=0). The transient is invalidated by `htax_delete_term_transients()` and re-warmed by `htax_rebuild_queued_term_caches()` at shutdown.

#### `htax_is_redirect( int $term_id ): bool`

Returns true if the term has a 301 redirect configured.

```php
if ( htax_is_redirect( $term_id ) ) {
    // This term redirects elsewhere — it has no accessible archive
}
```

#### `htax_get_redirect_target( int $term_id ): WP_Term|false`

Returns the redirect target as a `WP_Term` object, or false if the term has no redirect.

```php
$target = htax_get_redirect_target( $term_id );
if ( $target ) {
    $url = get_term_link( $target->term_id, $target->taxonomy );
}
```

#### `htax_get_redirect_sources( int $target_term_id ): int[]`

The reverse of `htax_get_redirect_target()`: returns every term ID that redirects to the given target, following redirect chains (`A → B → Y`) with a cycle guard. Posts assigned a redirecting term belong to the target for discovery, so this backs the `$include_redirect_sources` option of `htax_get_expanded_term_ids()`. Returns an empty array when nothing redirects to the term.

```php
$sources = htax_get_redirect_sources( $target_term_id ); // e.g. [ 1001 ]
```

#### `htax_get_all_redirect_term_ids(): int[]`

Returns all term IDs that have a redirect row in the database, across all taxonomies. The result is cached in a transient and invalidated automatically when any redirect is added or removed.

```php
$redirect_ids = htax_get_all_redirect_term_ids();
// Use for bulk filtering — e.g. strip redirect terms from a list of candidates.
$filtered = array_diff( $candidate_ids, $redirect_ids );
```

Returns raw database state — does not filter by taxonomy feature flag. If you use the result to suppress redirect terms, check `htax_taxonomy_has_feature( $taxonomy, 'redirects' )` separately before applying the filter, so terms with stale rows in a redirects-disabled taxonomy are not incorrectly treated as active redirects.

### Term Groups

#### `htax_get_term_groups( int $term_id ): WP_Term[]`

Returns the groups a term is assigned to, ordered by name. Returns an empty array if the `group_assignment` feature is not enabled for the term's taxonomy, or if the term doesn't exist.

```php
$groups = htax_get_term_groups( $term_id );
foreach ( $groups as $group ) {
    echo $group->name;
}
```

Results are cached in a transient (`htax_groups_{term_id}`).

#### `htax_get_terms_in_groups( array $group_ids, string $taxonomy = 'post_tag' ): WP_Term[]`

Returns all member terms of the given groups, indexed by term ID. Group hierarchy is expanded automatically — passing a parent group ID also returns terms belonging to its child groups, mirroring how WordPress expands `child_of` for `get_terms()`.

```php
$terms = htax_get_terms_in_groups( array( 5, 12 ), 'post_tag' );
// Returns WP_Term[] indexed by term_id, including terms in child groups of 5 and 12
```

Each group is cached separately (`htax_grp_{group_id}_{taxonomy}`), so invalidating one group's membership doesn't require rebuilding the others.

#### `htax_set_term_groups( int $term_id, int[] $group_ids ): bool|WP_Error`

Replaces all group assignments for a term with the given set of group IDs. The taxonomy is derived from the term. Returns `true` on success, or a `WP_Error` if the term doesn't exist, the `group_assignment` feature is disabled for its taxonomy, or the term is itself a group (groups cannot be assigned to other groups via group membership — use the native WordPress parent field for group hierarchy instead).

```php
$result = htax_set_term_groups( $term_id, array( 5, 12 ) );
if ( is_wp_error( $result ) ) {
    echo $result->get_error_message();
}
```

### Search notices

Messages for the visitor about how a search was run — e.g. that `near:me` was ignored because the visitor's location is unknown. Integrations that adjust a search in a way the visitor should know about can add their own.

```php
htax_add_search_notice( string $code, string $message ): void // same $code replaces an earlier notice
htax_get_search_notices(): string[]                            // notices recorded during this request
```

Search widget and PowerSearch AJAX responses include them as `notices` (rendered above the results as `<p class="htax-search-notice">`); POST-mode and native search results pages print them above the theme's result list (`loop_start` / `loop_no_results`, main search query). Messages are plain text.

### Sticky Posts

#### `htax_get_sticky_posts_for_term( int $term_id, bool $include_redirect_data = false ): WP_Post[]`

Returns the posts pinned to the top of a term's archive. Returns an empty array if the term is a redirect and the `redirects` feature is enabled for the taxonomy (a redirect term has no archive of its own to pin posts to), unless `$include_redirect_data` is `true`.

```php
$sticky_posts = htax_get_sticky_posts_for_term( $term_id );
foreach ( $sticky_posts as $post ) {
    echo $post->post_title;
}
```

Results are cached in a transient (`htax_sticky_{term_id}`).

### Term Links

External knowledge-base identifiers (Wikidata, Wikipedia, GeoNames, and more) attached to terms are managed by **TermConnect**. The Term Links API — `tc_get_term_links()`, `tc_get_term_link_uri()`, `tc_get_terms_by_link()`, `tc_get_platform_link_types()`, and related functions — is documented in the [TermConnect Developer Reference](https://wordpress.org/plugins/termconnect/).

HyperTaxonomy exposes three PHP abstraction filters. Provider plugins hook these to make their data available to same-site consumers (WP-SKOS, search, etc.) without those consumers needing to know the source plugin — and consumers read the matching `htax_get_*()` getter without knowing the provider:

**`htax_term_uris`** — TermConnect always populates this with URIs from all enabled link types, regardless of TermConnect's REST API guard. Same-site plugins like WP-SKOS consume it via PHP without any REST call:

```php
add_filter( 'htax_term_uris', function( array $uris, int $term_id ): array {
    // Add your own URIs here if not using TC.
    return $uris;
}, 10, 2 );
```

**`htax_term_locations`** — HT's built-in ATCF integration populates this with physical location rows when ATCF is active. ATCF's `atcf_expose_locations_api` setting controls ATCF's own REST endpoints. HyperTaxonomy reads the data via PHP (bypassing ATCF's REST guard) and applies its own per-taxonomy **Share list of locations** gate before including it in REST responses:

```php
add_filter( 'htax_term_locations', function( array $locations, int $term_id ): array {
    // Each row: label, street_address, locality, region, postal_code, country, lat, lng.
    return $locations;
}, 10, 2 );
```

**`htax_term_external_posts`** — related posts from external/peer sources. TermConnect populates this with posts fetched from configured peer sites and feeds (explicit per-term links plus shared-identifier/synonym auto-matching, and FOAF when requested). Consumers read it via the getter `htax_get_term_external_posts( int $term_id, array $args = [] ): array`, which **enforces term visibility**: it returns `[]` for a term hidden from the current user (and does not even fire the filter), so providers and consumers never reimplement visibility. Each post is a normalized array (`url`, `title`, `excerpt`, `date_gmt`, `modified_gmt`, `thumbnail_url`, `origin`, `score`, …).

`$args` is forwarded opaquely to providers as scope hints (HT does not interpret it); TermConnect understands `platforms` (e.g. `['wordpress','feed']`; default all supported), `foaf_depth` (default `0` = direct peers only), `peers` (restrict to specific link-type slugs; omit to keep the peer topology private), and `per_page`.

```php
add_filter( 'htax_term_external_posts', function( array $posts, int $term_id, array $args ): array {
    // Append normalized post rows for $term_id from your source.
    return $posts;
}, 10, 3 );

// Consumer side (e.g. WP-SKOS) — gated, source-agnostic; scope is opaque to peers:
$posts = htax_get_term_external_posts( $term_id, array( 'platforms' => array( 'wordpress', 'feed' ), 'foaf_depth' => 0 ) );
```

**`htax_peer_sites`** — read-only registry of the peer sites known to this installation. HyperTaxonomy defines the concept of a "peer site" but stores and manages nothing; providers populate the registry and consumers (e.g. the search widget) read it to offer peer-scoped options. TermConnect populates it with its configured WordPress peers and feeds. Consumers read it via the getter `htax_get_peer_sites(): array`, which returns the descriptors keyed by slug (an empty list when no provider is active). Each descriptor is an associative array with `slug` (stable identifier — the provider's link-type slug), `label` (human-readable name), `platform` (e.g. `wordpress`, `feed`), and `origin` (site origin `scheme://host` or feed URL, when known).

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

**`htax_get_local_post_scores( array $term_ids, array $args = [] ): array`** — relevance scores for local posts against a set of query terms, returned as a `post_id => score` map. It sums the search-index strength (relationship weight × TF, baked at index time) over each term's relationship paths, gated by the per-taxonomy enabled relationship features and by term visibility — the same relevance signal the archives and search use. A consumer (e.g. TermConnect's blended peer view) calls it to rank this site's own posts consistently with HyperTaxonomy before merging them with peer results. `$args` accepts `exclude` (an array of post IDs to omit). Returns an empty array when the search index table is unavailable.

```php
// e.g. rank local posts for the terms a peer query is about, minus the current post:
$scores = htax_get_local_post_scores( $term_ids, array( 'exclude' => array( get_the_ID() ) ) );
arsort( $scores );
```

Note: `htax_term_uris` is a same-site PHP filter only — HT does not expose it as a REST field. External identifier data in REST responses is TermConnect's responsibility, exposed via `tc_links` on TC-enabled term endpoints.

**`htax_term_uri` — term collection query parameter** (registered by TermConnect, not a filter):

TermConnect also registers `htax_term_uri` as a REST query param on term collection endpoints for TC-enabled taxonomies. It is not an HT-defined filter — it is a TC-side REST feature that allows querying terms by their stored external link identifier:

```
GET /wp-json/wp/v2/tags?htax_term_uri=https://www.wikidata.org/wiki/Q28865&_fields=id
```

HT does not define or handle this param. It is provided by TC on any site where TC is installed, enabling automatic cross-site term resolution by shared knowledge-base identifier without manual slug configuration.

---

