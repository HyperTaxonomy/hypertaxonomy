---
title: Term Icons & Badges
parent: Developer Reference
nav_order: 5
---

# Term Icons & Badges
{: .no_toc }

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Term Icons API

HyperTaxonomy provides a complete PHP API for displaying terms as styled pills with icons. Site owners upload icons on the term edit screen (see [Term Icons](../user-guide/per-term-settings.html#term-icons) in the User Guide). All icon functions accept either a WP_Term object or a term ID. Icon URLs are also readable as [term meta](term-meta.html) (`htax_icon_url` and one key per size).

### Upload rules

Icons are images from the media library, raster or SVG, stored as standard WordPress attachments. The attachment ID is saved in `_htax_icon_id` term meta.

**Dimension enforcement** is applied at selection time in the admin UI (`htax-term-icons.js`):

| Image dimensions | Behavior |
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

#### Show the archive's term icon

`htax_the_archive_icon( string $size = 'large', array $attr = [] ): void`  
`htax_get_the_archive_icon( string $size = 'large', array $attr = [] ): string`

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

#### Term icon URL

`htax_get_term_icon_url( WP_Term|int $term, string $size = 'htax-icon-small' ): string`

Returns the URL of the term's icon at the given size, or an empty string if no icon is set.

```php
$url = htax_get_term_icon_url( $term, 'small' );
// Size shortcuts: 'small', 'medium', 'large'
// Full names: 'htax-icon-small-post_tag', 'htax-icon-medium-post_tag', etc.
```

#### Term icon image tag

`htax_get_term_icon_html( WP_Term|int $term, string $size = 'htax-icon-small', array $attr = [] ): string`

Returns an `<img>` tag for the term's icon, or an empty string if no icon is set. Works on both admin and frontend.

```php
$img = htax_get_term_icon_html( $term, 'small', [ 'class' => 'term-icon' ] );
echo $img;
```

#### Placeholder color for a term

`HyperTaxonomy_Term_Icons::get_color_for_slug( string $slug ): string`

Returns the deterministic hex placeholder color for a given term slug (e.g. `'#1e88e5'`). Uses `crc32` hash mapped to a fixed 20-color palette — same slug always yields the same color, independent of term ID or taxonomy. Useful when building custom icon-list UIs that need to match the placeholder color used by HyperTaxonomy shortcodes.

```php
$color = HyperTaxonomy_Term_Icons::get_color_for_slug( $term->slug );
echo '<span style="background:' . esc_attr( $color ) . '"></span>';
```

### Term Pills

Pills are styled `<a>` or `<span>` elements that combine the term name with its icon (or a colored placeholder when no icon is set). Border-radius is inherited from the taxonomy's icon settings.

#### One term pill

`htax_get_term_pill( WP_Term $term, array $args = [] ): string`

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

#### All term pills for a post

`htax_the_term_pills( int $post_id, string $taxonomy, array $args = [] ): void`

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

#### Add an icon to a term link

`htax_add_icon_to_term_link( string $term_link, WP_Term $term, string $show_icons = 'when_available', bool $is_hidden = false ): string`

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

The configured dimensions are the **large** (full) size. Upload at large size; WordPress generates medium and small automatically. The medium and small percentages default to 50% and 25% but are configurable per taxonomy in **Settings&nbsp;→ HyperTaxonomy&nbsp;→ Icons**. Changing percentages regenerates the smaller sizes from existing uploads without requiring re-upload.

| Shortcut | Full name pattern | Default fraction | Use case |
|----------|------------------|------------------|----------|
| `'small'` | `htax-icon-small-{taxonomy}` | 25% of large (configurable) | Pills, inline text |
| `'medium'` | `htax-icon-medium-{taxonomy}` | 50% of large (configurable) | Lists |
| `'large'` | `htax-icon-large-{taxonomy}` | 100% (configured) | Archive headers; upload size |

API functions accept both the shortcut (`'small'`) and the full name, and resolve the taxonomy-specific dimensions automatically.

### Pill Styling

Pills inherit border-radius from **[Settings&nbsp;→ HyperTaxonomy&nbsp;→ Icons](../user-guide/plugin-settings.html#icons-tab)** per-taxonomy configuration. The border-radius applies to both the icon/placeholder and the pill container, creating a seamless visual style. Default CSS class is `htax-pill`; the plugin stylesheet provides base styling.

---

## Post Editor Term Badges

When editors assign tags or categories in the Gutenberg post editor, HyperTaxonomy adds contextual relationship indicators (badges and visual styling) to each term pill or list item. These badges help editors understand the semantic relationships of the terms they're adding.

The post editor displays the following visual cues:

- **Includes badge** (`⇉ [N]`): Light blue circle with a rightward-double-arrow symbol and count. Indicates this term's archive effectively includes N other terms' posts (via direct inclusion, inclusion groups, or container-group relationships).
- **Container badge** (`⇇ [N]`): Light green circle with a leftward-double-arrow symbol and count. Indicates N other terms include this term's posts in their archives.
- **Redirect styling** (*italic text*): When a term is a redirect, its pill/list item is displayed in italics. An additional hook symbol (↪, U+21AA) is appended to the term name as an extra visual cue.
- **Hidden styling** (<span style="opacity: .45">opacity fade</span>): Terms marked as hidden are displayed with reduced opacity (45%).

**Badge colors:**

- Includes badge: `#ddeeff` background, `#7ab8e8` border, `#003366` text (light blue)
- Container badge: `#eef7ef` background, `#a5d6a7` border, `#2e7d32` text (light green)

Badge rendering is handled by `admin/js/htax-post-edit.js` and `admin/css/htax-post-edit.css`. Badge metadata is provided to the editor via the `htaxTermMeta` JS global (keyed by term ID), populated by `htax_enqueue_post_editor_assets()` in `includes/admin/post-edit.php`.

**Which features appear is governed by two gates, both of which must be on:**

1. **Per-user preference** toggled in the Gutenberg block editor's **··· menu (More options)&nbsp;→ HyperTaxonomy group** ([Editing Posts](../user-guide/editing-posts.html) describes each toggle). These are stored in the `core/preferences` store under the `htax/post-edit` scope and persist per user. Defaults:
   - Inclusion Badges — off by default
   - Container Badges — off by default
   - Redirect Indicators — on by default
   - Hidden Terms — on by default
   - Sticky Pushpins — off by default
2. The corresponding feature enabled for that taxonomy (e.g. `redirects`, `term_relationships`, `visibility`, `customize_archives`) in **[Settings&nbsp;→ HyperTaxonomy&nbsp;→ Features](../user-guide/plugin-settings.html#features-tab)**.

**JS globals emitted alongside `htaxTermMeta`:**

- `htaxPreloadedTerms` — assigned term objects injected into the Gutenberg Redux store before the block editor initializes, eliminating per-term REST calls. Not present in the Classic Editor.
- `htaxTaxonomyFeatures` — `{[taxonomy]: {inclusions, containers, redirects, visibility}}` taxonomy-level feature gates. User display preferences are applied client-side and not reflected here.
- `htaxFlatTaxonomies` — `[{slug, restBase}]` for flat HyperTaxonomy-enabled REST-exposed taxonomies; drives the autocomplete subscriber that calls `GET /wp-json/htax/v1/term-meta` when a term is added mid-session.
- `htaxStickyConfig` — `[{name, label, restBase}]` for taxonomies with the `customize_archives` feature; always emitted in the block editor when at least one eligible taxonomy exists.
