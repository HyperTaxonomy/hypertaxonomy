---
title: Working with Other Plugins
parent: User Guide
nav_order: 16
---

# Working with Other Plugins
{: .no_toc }

HyperTaxonomy operates at the vocabulary level — it defines what taxonomy terms mean in relation to each other. The plugins in this section operate at other levels: post structure, custom fields, page building, e-commerce, content automation, and search. Because they address different problems, they are genuinely complementary.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## The Core Gap in Content-Modeling Frameworks

CPT UI, ACF, Pods, and JetEngine are content-modeling frameworks: they define post types, custom fields, and post-to-post relationships. They all share the same limitation at the vocabulary level. They answer questions like:

- What fields does a *Faculty* post have?
- Which specific *Courses* does *Professor Smith* teach?
- What *Projects* is *Dr. Lee* associated with?

None of them answer a different class of question:

- What does the *subject area* "Machine Learning" *mean* in relation to the subject area "Statistics"?
- Should a visitor browsing the "JavaScript" tag archive automatically see React, Vue, and TypeScript content — without any individual post being double-tagged?
- What archive URL should the deprecated "Big Data" tag redirect to?

These are questions about the *vocabulary* — the taxonomy terms themselves — not about individual posts. HyperTaxonomy is purpose-built for this layer. The frameworks below have no mechanism for it; HyperTaxonomy provides it.

---

## CPT UI

**What it does:** Registers custom post types and taxonomies through an admin UI. No code required.

**Limitation:** CPT UI creates vanilla WordPress taxonomies. Once a taxonomy exists, terms in it are flat, unconnected labels. "Algorithms" and "Machine Learning" are both terms in `subject_area`, but the system has no knowledge that they are related, or that browsing "Computer Science" should encompass both.

**How HyperTaxonomy fills the gap:** Everything HyperTaxonomy provides — inclusions, groups, redirects, term icons, per-term archive settings, sticky posts, hidden terms, the full-text search index — works directly on any taxonomy CPT UI registers. HyperTaxonomy lists registered taxonomies under **Settings > HyperTaxonomy > Features**; enable the taxonomy there.

**Combined, no-code:** CPT UI defines the structure; HyperTaxonomy defines the meaning. A site architect can define a `subject_area` taxonomy in CPT UI, then build a semantic graph of inclusions, groups, and redirects in HyperTaxonomy's term edit screens — all without writing a line of PHP. The Post List block makes it possible to surface those relationships in any page or template, also without coding.

---

## ACF (Advanced Custom Fields)

**What it does:** Defines custom fields for posts, taxonomy terms, and users. The Relationship field stores arrays of related post IDs and renders them as selectable lists on the post edit screen.

**Limitations:**

1. **Post-to-post relationships are instance-level.** Adding a React post to the "Related articles" ACF field on a JavaScript post does not make that React post appear in the JavaScript taxonomy archive, and does not affect any other JavaScript post. The relationship exists only for that one pair.

2. **Maintenance cost scales with content volume.** A site with 500 posts about JavaScript frameworks would require 500 post edit screens to be visited and manually updated to maintain ACF relationship fields between them. New posts are not related automatically.

3. **ACF relationships are not relevance-scored.** All linked posts are equal. There is no way to surface "posts that share more terms" above "posts that share one term."

4. **No effect on taxonomy archive pages.** A visitor browsing `/tag/javascript/` sees only posts directly tagged JavaScript, regardless of how many ACF relationships exist among those posts.

**How HyperTaxonomy fills the gap:** Term inclusions are defined once at the vocabulary level and apply to every current and future post without maintenance. Visiting `/tag/javascript/` shows React, Vue, and TypeScript content automatically. The Related Posts block on any single post page surfaces content related via shared taxonomy terms, relevance-scored, with no field to maintain.

**The two tools are genuinely complementary.** Use ACF for what it is uniquely good at — instance-specific facts (Professor Smith's office number, Course 101's credit count, Product SKU-A's dimensions) and editorially curated, intentional post-to-post links ("This article was followed up by..."). Use HyperTaxonomy for the semantic structure that scales automatically.

**No-code display:** ACF data can be displayed in Elementor via Dynamic Tags or in the block editor via ACF's native blocks. HyperTaxonomy data is displayed via the Post List block and Related Posts block. Both are configured without coding.

**Stack responsibilities (CPT UI + ACF + HyperTaxonomy):**

| Layer | Tool | Responsible for |
|-------|------|----------------|
| Post type & taxonomy registration | CPT UI | Define post types and taxonomies with no code |
| Semantic vocabulary | HyperTaxonomy | Inclusions, groups, redirects, per-term settings |
| Instance data & relationships | ACF | Post-specific fields, editorially curated post-to-post links |
| Query expansion | HyperTaxonomy via `pre_get_posts` | Taxonomy queries include semantically related terms |
| Display — taxonomy content | Post List block / Related Posts block | No-code block editor configuration |
| Display — ACF content | ACF blocks / Elementor Dynamic Tags | Post-specific field display |
| Search & filter UI | HyperTaxonomy Search Widget | Faceted search across taxonomies |

**Practical rule:** If you are creating a relationship that would be true for every future post in one of the involved categories, define it in HyperTaxonomy. If you are creating a relationship that is specific to one particular post, define it in ACF.

---

## Pods

**What it does:** A full relational data modeling framework. Defines custom post types, taxonomies, and fields with a schema-like approach. Supports custom database tables. The Relationships feature creates typed, bidirectional connections between post types (e.g., `Faculty.teaches ↔ Course.taught_by`).

**Limitations:**

1. **Relationships are still post-to-post.** The schema says "a Faculty can teach many Courses" — but each individual faculty post must still be linked to each individual course post through the Pods interface. The schema defines the structure; someone must still populate every instance.

2. **Taxonomy terms are not enriched.** Pods has no mechanism for "the subject area 'Data Science' includes the subject areas 'Statistics', 'Machine Learning', and 'Database Systems'." Terms in a Pods-registered taxonomy are as unconnected as in any other WordPress taxonomy.

3. **Pods relationship queries bypass taxonomy archives.** A Pods query for "all courses linked to Professor X" is a custom lookup, not a standard taxonomy archive. It does not produce a URL at `/subject/data-science/` that includes related subject matter.

**How HyperTaxonomy fills the gap:** HyperTaxonomy handles the vocabulary layer that Pods does not model. Pods governs how individual faculty, courses, and projects are linked to each other. HyperTaxonomy governs how the subject area taxonomy is semantically structured — which subjects include which others, how the group hierarchy is organized, what gets surfaced in a subject archive.

**Combined architecture example (academic site):**
- Pods: defines Faculty, Course, Department post types; Faculty.teaches ↔ Course schema
- CPT UI (or Pods): registers `subject_area` taxonomy
- HyperTaxonomy: "Computer Science includes Algorithms, Data Structures, Machine Learning"; GROUP "STEM Subjects" → [Computer Science, Mathematics, Physics]
- A subject archive at `/subject/computer-science/` shows all post types tagged with Computer Science or any included subject — powered by HyperTaxonomy
- A course's sidebar shows "taught by Professor Y" — powered by Pods
- A faculty profile page shows "related courses in the same subject area" — powered by HyperTaxonomy's Related Posts block

---

## JetEngine

**What it does:** The most comprehensive content-modeling toolkit in the WordPress ecosystem. Custom post types, fields, query builder, Smart Filters (faceted filtering), Dynamic Listings (Elementor-based loop templates), and the Relations module — which creates fully managed many-to-many relationships between post types with editable metadata on each relationship instance ("Professor Smith teaches Course 101 as Lead Instructor, starting Fall 2023").

**Limitations:**

1. **Relations are still post-to-post.** Despite being the most powerful instance-level relationship system in WordPress, JetEngine Relations cannot express "every post in the Machine Learning subject area is contextually related to every post in the Statistics subject area." That relationship belongs to the vocabulary layer, not the instance layer.

2. **Smart Filters operate on the result set, not the vocabulary.** A JetEngine Smart Filter showing a "Subject Area" checkbox list will let users narrow to a specific subject — but clicking "Machine Learning" only returns posts directly tagged with Machine Learning. Posts tagged with Statistics or Data Engineering that HyperTaxonomy would include are not surfaced unless those subjects are also selected.

3. **Dynamic Listings use secondary WP_Query instances.** JetEngine Dynamic Listings instantiate their own `WP_Query` objects inside templates. HyperTaxonomy's `pre_get_posts` expansion applies only to the main WordPress query, so JetEngine loop widgets do not receive term expansion automatically.

**How HyperTaxonomy fills the gap:**

- Taxonomy-level semantic relationships: HyperTaxonomy defines what the subject area vocabulary *means*; JetEngine defines how specific posts relate to each other
- Archive query expansion: HyperTaxonomy enriches the main WordPress taxonomy archive query; the standard archive page for a subject therefore shows semantically related content even when JetEngine widgets are present on the same page
- Search integration: HyperTaxonomy's faceted search widget surfaces content across semantically related subjects; JetEngine Smart Filters refine content within a specific subject

JetEngine Relations handle instance-level connections (faculty ↔ course ↔ project); HyperTaxonomy handles term-level connections (subject ↔ subject ↔ method group). For JetEngine Dynamic Listings that need inclusion-expanded results, three options:

**Option 1 — Enable automatic expansion (simplest):** Turn on **Settings → Search → Third-Party Query Expansion**. JetEngine listings that issue a single-term taxonomy query will automatically receive HyperTaxonomy inclusion expansion. Disable if a specific listing conflicts.

**Option 2 — Manual integration:** Call `htax_get_expanded_term_ids( $term_id )` to get the full expanded term ID set, then inject it into the listing's query parameters. Use this if you need expansion on some listings but not others.

**Option 3 — Use Post List block:** Replace the listing with an HT Post List block, which is inclusion-aware by default and requires no code.

JetEngine Smart Filters and HyperTaxonomy's faceted filter panel serve different needs on the same site:
- HyperTaxonomy filter panel: best for filtering within a HyperTaxonomy-expanded result set (post list or search results), including the semantically related content
- JetEngine Smart Filters: best for filtering over custom JetEngine query results or ACF-indexed fields

Avoid placing both on the same result block. Each can be used independently on different sections of the same page.

---

## Elementor

HyperTaxonomy's `pre_get_posts` expansion applies to the main WordPress query only. The standard taxonomy archive page therefore shows inclusion-expanded results automatically. Elementor Loop Builder widgets use secondary `WP_Query` instances and do not receive this expansion by default — an Elementor loop configured to show "posts in category: JavaScript" will show only directly tagged posts.

**Option 1 — Enable automatic expansion (simplest):** Turn on **Settings → Search → Third-Party Query Expansion**. Elementor Loop widgets that issue a single-term taxonomy query will automatically receive HyperTaxonomy inclusion expansion.

**Option 2 — Manual integration:** Call `htax_get_expanded_term_ids( $term_id )` to get the full expanded term ID set and pass it to the loop's query.

**Option 3 — Use Post List block:** Replace the loop with an HT Post List block, which is inclusion-aware by default and integrates natively with the Gutenberg editor.

For post-to-post relationships managed by ACF, Elementor's Dynamic Tags read ACF fields directly and can display related post data in any Loop Builder template.

**No-code pattern for a content hub page:**
1. Drop the HT Post List block — configure taxonomy, terms, sort order, and filters in the inspector panel with live preview
2. Drop an Elementor Loop widget below it for ACF-relationship-based "featured content" — configure with Dynamic Tags
3. No PHP required anywhere in the page

---

## WooCommerce

HyperTaxonomy works with WooCommerce taxonomies (`product_cat`, `product_tag`, and any custom attribute taxonomy registered as a standard taxonomy). Term inclusions, redirects, and hidden terms apply to product archives exactly as they would to post archives.

The Post List block with `post_type="product"` provides a configurable product listing with inclusion-aware filtering, relevance sorting, and AJAX pagination — no template code required.

HyperTaxonomy does not interact with WooCommerce's product variation or cross-sell/upsell systems. Those are WooCommerce post-level features; use WooCommerce's own tools or ACF for them.

---

## Term Icons and Other Plugins That Attach Images to Terms

HyperTaxonomy stores term icons as media library attachments, referenced by `_htax_icon_id` term meta, and generates three responsive sizes per term. Several other plugins store similar data under different meta keys. The two systems do not interfere at the database level — they write to separate meta keys and neither reads the other's data. Conflicts are entirely **template-level**: they occur only if a theme or template file outputs both a HyperTaxonomy icon and another plugin's term image for the same term in the same location.

**WooCommerce product category thumbnails.** WooCommerce stores a `thumbnail_id` term meta on `product_cat` terms and displays it in two places:
- The shop page (`/shop/`) — in subcategory tiles via the `woocommerce_before_subcategory_title` hook, auto-injected by WooCommerce's own template. HyperTaxonomy does not inject anything here, so no conflict occurs automatically.
- Product category archive headers — displayed by themes that call `woocommerce_get_product_thumbnail()` or read `get_term_meta($category->term_id, 'thumbnail_id')` directly in their archive templates.

A conflict happens if the same archive template also calls `htax_the_archive_icon()`. The practical resolution:

*Option A — Use only WooCommerce thumbnails for `product_cat`.* Do not enable the HyperTaxonomy Icons feature for the `product_cat` taxonomy in **Settings → HyperTaxonomy → Features**. All icon functionality is per-taxonomy, so other taxonomies are unaffected.

*Option B — Use only HyperTaxonomy icons for `product_cat`.* Remove the WooCommerce category image display from the theme template (or disable it in the WooCommerce template you are overriding). HyperTaxonomy icons give you three responsive sizes, configurable border-radius, and a colored placeholder when no icon is set — often a suitable replacement.

*Option C — Gate by term.* If some terms have a WooCommerce thumbnail and others have a HyperTaxonomy icon, check which is set before calling either output function:

```php
$wc_thumbnail_id = get_term_meta( $term->term_id, 'thumbnail_id', true );
if ( $wc_thumbnail_id ) {
    echo wp_get_attachment_image( $wc_thumbnail_id, 'medium' );
} else {
    htax_the_archive_icon( 'medium' );
}
```

**Other term image plugins.** Taxonomy Images, WP Term Images, and Categories Images each store their own image for a term and display it through their own functions, filters, or shortcodes. Check each plugin's documentation for the current names. HyperTaxonomy doesn't read their data, and they don't read its data. The conflict is the same in each case: it only arises if a template outputs both images in the same place. Choose one system per taxonomy, or check which has an image before output. Taxonomy Images is still listed on WordPress.org but hasn't been updated in years, so it's worth avoiding on new sites.

**WooCommerce brand taxonomies.** WooCommerce's Brands extension and other brand plugins register a brand taxonomy and store a logo per term, often in the same `thumbnail_id` term meta as product categories. The same three options apply: use one system per taxonomy, or check which is set.

**ACF image fields on taxonomy terms.** ACF can add an image field to taxonomy term edit screens. Like the above, the images live in separate term meta and only conflict when displayed from the same template location alongside `htax_the_archive_icon()`. Gate with `if ( function_exists('get_field') && get_field('my_term_image', $taxonomy . '_' . $term_id) )` if mixing.

**General rule.** `htax_the_archive_icon()` is silent when no icon is set for the current term and returns an empty string, making it safe to call unconditionally in a template. If another plugin's term image is also set, the safe approach is to check `htax_get_term_icon_url( get_queried_object() )` first and fall back to the other plugin's image when HyperTaxonomy has none — or the reverse, depending on which system you designate as primary.

---

## TaxoPress

**Note:** TaxoPress was rebranded "PublishPress Taxonomies" in September 2026 (same plugin, same company); this section uses "TaxoPress" throughout for brevity, matching its long-standing name.

**What it does:** Content automation — auto-assigns terms to posts by scanning post content (Auto Terms), auto-links term names in post body text (Auto Links), and provides AI-assisted term suggestions at publish time. Also includes a taxonomy builder for registering custom taxonomies without code.

**Limitations:** TaxoPress operates entirely at the assignment layer. It answers "which posts should have this term?" It has no mechanism for "what does this term mean relative to other terms?" — no inclusions, no archive expansion, no term redirects, no groups.

**How HyperTaxonomy fills the gap:** Terms assigned by TaxoPress are ordinary WordPress term assignments — they receive the full HyperTaxonomy treatment automatically. A term that TaxoPress auto-assigns to posts also participates in HyperTaxonomy inclusions, appears in the search index, respects redirect logic, and benefits from any group memberships or per-term archive settings. No integration code is needed; TaxoPress writes via standard `wp_set_object_terms()` and HyperTaxonomy responds to those writes the same way it responds to any manual assignment.

**Combined:** TaxoPress handles the question of what gets tagged; HyperTaxonomy handles the question of what the tags mean — and how strongly each tag applies. Auto-tagging addresses concept sparsity (manually tagged posts typically have 3–5 terms; auto-tagged posts can have dozens), which makes HyperTaxonomy's archive expansion and relevance scoring more effective. A post auto-tagged with "React", "Component Architecture", and "TypeScript" will surface in the JavaScript archive without any editor touching it — because HyperTaxonomy already knows those terms are related. HyperTaxonomy also records how centrally each auto-assigned term covers the post (by measuring term-name frequency in title, excerpt, and body), so a post that is primarily about React ranks above a post that merely mentions it, even when both are tagged identically.

**Co-tagging vs. inclusions.** TaxoPress Pro "Linked Terms" (co-tagging) adds Term B to a post at save time when Term A is applied. HyperTaxonomy inclusions operate at query time: Term A's archive dynamically shows Term B's posts without modifying any post's term assignments. For the same end result, inclusions are zero-maintenance and retroactive; co-tagging is an explicit assignment that accumulates in the database.

**Do not use TaxoPress (or any external tool) to merge terms when HyperTaxonomy relationships are in use.** TaxoPress's merge feature reassigns posts via `wp_set_object_terms()` then deletes the source term via `wp_delete_term()`. The deletion fires HyperTaxonomy's cleanup hooks, which remove the source term's relationship rows — but nobody transfers them to the merge target. Group memberships, relationships, and sticky posts that belonged to the source term are permanently lost. HyperTaxonomy's own merge screen (**Terms → Merge Terms**) performs the relationship transfer atomically before deletion. If you need to script merges programmatically, use `htax_merge_terms()` directly, or hook into `htax_before_merge_terms` / `htax_after_merge_terms` to extend the built-in merge flow.

HyperTaxonomy also surfaces this warning directly where it matters: on TaxoPress's own Manage Terms → Merge Terms screen, whenever the taxonomy currently selected there is HyperTaxonomy-enabled, a dismissible notice names what would be lost and links to HyperTaxonomy's own **Merge Terms** page (Terms → Merge Terms) instead, with the same taxonomy preselected. The dismissal is per-admin-user and persists until TaxoPress is deactivated (and reactivated), at which point the notice reappears for everyone until dismissed again.

**Tag Groups.** TaxoPress and Tag Groups (rebranded "PublishPress Tag Groups" in October 2026) are both published by PublishPress as of their respective rebrands, but they are independent plugins with no cross-dependency. Running HyperTaxonomy + TaxoPress (without Tag Groups) is a natural combination for sites that need both semantic taxonomy management and content automation. Tag Groups (free) provides tabbed and accordion display layouts for term lists; Tag Groups Pro adds a table layout and live in-display search. If those display layouts are a hard requirement, evaluate Tag Groups on its own terms.

HyperTaxonomy can also run alongside Tag Groups rather than replace it. Running both without any bridge between them does create overlapping responsibilities — two separate group systems with no shared data. The Export/Import → Migrate and Sync subtabs are that bridge: they read Tag Groups' group and term-assignment data directly from its database tables and map it onto HyperTaxonomy's native `group` taxonomy, either as a one-time migration or continuously. Under **Locked** integration mode, Tag Groups stays the system of record for group management — mapped groups are locked against edits in HyperTaxonomy, and the term edit screen promotes Tag Groups' own field to the spot HyperTaxonomy's group selector normally occupies — while HyperTaxonomy's archive pages, search indexing, hierarchy, and relationships apply to the synced result. See [Import & Migration](import-migration.html) for the full reference.

TaxoPress features HyperTaxonomy does not replicate: auto-linking term names in post content, automatic term assignment from post content, AI-assisted term suggestions at publish time, and related posts via co-occurrence. HyperTaxonomy features TaxoPress does not replicate: archive query expansion, semantic inclusions, FULLTEXT search, term redirects, sticky posts, term icons, a denormalized search index, and per-relationship strength scoring.

**Moving from, or alongside, Tag Groups:** HyperTaxonomy's importer reads Tag Groups' (or PublishPress Tag Groups' — same database format) group and term-assignment data directly from its database tables — no export step, and Tag Groups doesn't need to stay active for a one-time migration. Leaving Tag Groups behind: run the migration once, then deactivate it. Keeping Tag Groups as your group-management tool: choose Locked mode with continuous sync instead, so HyperTaxonomy's groups stay current automatically.

See [Feature Comparison](../comparison.html) for a detailed side-by-side.

---

## Relevanssi

Both plugins can optionally replace WordPress's native keyword search. Enable only one for that role.

**Recommended split:** Use Relevanssi for keyword-driven search (fuzzy matching, phrase proximity, partial-word search, custom field indexing). Use HyperTaxonomy for archive browsing, term relationships, and the taxonomy-based faceted search widget (which does not conflict with Relevanssi's keyword search). Turn off HyperTaxonomy's **Site Search Integration** setting when Relevanssi is active.

This gives you linguistically rich keyword search (Relevanssi) plus relationship-aware taxonomy browsing and archive expansion (HyperTaxonomy) — the two capabilities are genuinely additive.

---

## FacetWP

FacetWP excels at faceted filtering over custom post meta fields (prices, dates, ACF relationship facets, numeric fields). HyperTaxonomy excels at semantic enrichment of the base taxonomy result set.

**Automatic expansion:** FacetWP's index refresh query and its archive `WP_Query` both fire `pre_get_posts`. Enabling **Settings → Search → Third-Party Query Expansion** will expand single-term FacetWP taxonomy clauses to include HyperTaxonomy inclusions, and will adjust the facet counts displayed in FacetWP's taxonomy facets to reflect the expanded post set. This is the recommended approach for sites where both plugins filter the same result set.

**When to keep them separate:** If FacetWP is used exclusively for post-meta–based facets (prices, dates, numeric fields) without any taxonomy facets, there is no interaction and no configuration needed. Use them in separate contexts — FacetWP for post-meta filtering, HyperTaxonomy for taxonomy-based semantic filtering — and expansion has no effect on FacetWP's meta facets.

**Note on multi-term clauses:** Expansion applies only to single-term taxonomy clauses. When a visitor selects multiple terms in a FacetWP taxonomy facet (multi-select), HyperTaxonomy treats those as explicit user filters and does not expand them. Expansion is for archive-context queries, not interactive multi-choice filter selections.

---

## SearchWP

SearchWP is a premium WordPress search plugin, commonly used alongside or instead of Relevanssi for more control over which content surfaces and how it is weighted. The conflict with HyperTaxonomy is the same as with Relevanssi: both can replace WordPress's native `?s=` search. Enable only one for that role — turn off HyperTaxonomy's **Site Search Integration** setting when SearchWP is active.

Unlike Relevanssi (pure keyword TF-IDF), SearchWP can also index custom fields, taxonomy terms, and custom database tables. This means SearchWP's taxonomy search — when configured — may partially overlap with HyperTaxonomy's faceted search widget. The recommended division is the same: SearchWP handles keyword relevance and document scoring; HyperTaxonomy handles archive browsing, term relationship expansion, and the taxonomy facet UI.

---

## WP Grid Builder

WP Grid Builder is a Gutenberg-native grid and faceted filtering plugin. Its taxonomy facets issue `WP_Query` calls that fire `pre_get_posts`. The interaction with HyperTaxonomy is identical to FacetWP: enable **Settings → Search → Third-Party Query Expansion** to have single-term taxonomy clauses automatically expanded to include HyperTaxonomy inclusions, and to have facet counts reflect the expanded post set. Multi-term selections are not expanded (same reasoning as FacetWP — those are explicit user filter choices, not archive expansions).

---

## Yoast SEO and Rank Math

Both SEO plugins can mark empty or thin term archives as `noindex` or redirect them away. Neither conflicts with HyperTaxonomy in practice:

- **HyperTaxonomy redirects** run on `template_redirect` through `wp_safe_redirect()`, then exit. The page is never rendered, so neither Yoast nor Rank Math outputs any meta tags for redirect terms.
- **`noindex` for thin archives** is a meta tag output during rendering, which redirect terms never reach. For other terms, HyperTaxonomy's expansion can add posts to an archive, which may change whether an SEO plugin treats it as thin.
- **Manual redirect rules** in Yoast SEO Premium's redirect manager or Rank Math's redirect module are stored separately. If one covers a URL that HyperTaxonomy also redirects, whichever runs first wins. Remove the duplicate to avoid confusion.

### Yoast / Rank Math and term conversion

Yoast SEO indexes term archives in `wp_yoast_indexable`, keyed by `object_type = 'term'`, `object_sub_type = taxonomy_slug`, and `object_id = term_id`. When a term moves to a different taxonomy, this record needs to be considered. Rank Math isn't covered here; check how it stores term data.

**With a redirect stub** (HyperTaxonomy's default): the old term stays in the old taxonomy as the stub with the same `term_id`. Its existing Yoast record remains correctly attached, because `object_sub_type` still matches the stub's taxonomy. The new term gets its own record from Yoast. No action required.

**Without a redirect stub** (or when using an external converter that deletes the old term): the old term is deleted, orphaning its Yoast record. The record references a `term_id` that no longer exists in the old taxonomy. Hook `htax_term_converted` to update it. This sample hasn't been tested against a current Yoast version, so try it on a staging site first:

```php
add_action( 'htax_term_converted', function(
    int    $new_term_id,
    string $old_taxonomy,
    string $new_taxonomy,
    int    $old_term_id
) {
    // In the redirect path the old term (stub) is still in $old_taxonomy —
    // its Yoast record remains valid. Only act when the old term was deleted.
    $still_exists = get_term( $old_term_id, $old_taxonomy );
    if ( $still_exists && ! is_wp_error( $still_exists ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'yoast_indexable';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return;
    }

    $wpdb->update(
        $table,
        array( 'object_sub_type' => $new_taxonomy, 'object_id' => $new_term_id ),
        array( 'object_type' => 'term', 'object_sub_type' => $old_taxonomy, 'object_id' => $old_term_id )
    );
}, 10, 4 );
```

---

## WPML and Polylang

### WPML

WPML stores translation relationships in `wp_icl_translations` keyed by `element_type = 'tax_{taxonomy}'` and `element_id = term_taxonomy_id` (the row in `wp_term_taxonomy`, not the `term_id`). When a term moves to a different taxonomy:

**With a redirect stub**: the old term stays in the old taxonomy with the same `term_taxonomy_id`. WPML's translation links remain valid. The new term in the target taxonomy has no translation links; those must be set up manually in the WPML interface.

**Without a redirect stub**: the old `term_taxonomy_id` row is deleted before `htax_term_converted` fires, so the old `element_id` cannot be looked up at hook time. WPML's translation links for the old term are broken.

The practical recommendation: **keep redirect stubs enabled when WPML is active**. The stub retains the original taxonomy and the same `term_taxonomy_id`, so all WPML translation links survive intact. The new term needs its translations set up in WPML, because one translation group can't hold terms from two taxonomies. WPML's `wpml_set_element_language_details` action sets a term's language and translation group from code. These notes haven't been tested with WPML, so try conversions on a staging site first.

### Polylang

Polylang stores each term's language and its translation links with the term itself. These notes haven't been tested with Polylang. When a term converts:

- **With redirect stub**: the stub is the same term, so its language assignment and translation links survive unchanged. The new term has no language until you set one.
- **Without redirect stub**: the old term is deleted, so its language assignment and translation links are lost. The new term appears as unassigned in the Polylang interface.

**Practical recommendation**: use redirect stubs when Polylang is active. If a no-redirect conversion is necessary, set the new term's language in the Polylang term editor afterward.

### wp\_terms.term\_group

The `term_group` column in `wp_terms` is a legacy WordPress mechanism that some older multilingual systems use to group synonymous terms (e.g., link "Science" in English to "Wissenschaft" in German by giving both the same `term_group` value). HyperTaxonomy checks for `term_group` collisions before allowing a conversion: if the target taxonomy already contains a different term with the same non-zero `term_group` value, the conversion is blocked with an error. This prevents silently creating duplicate `term_group` pairings within a taxonomy that would confuse those multilingual systems.

---

## YARPP and Jetpack Related Posts

Both use content-based algorithms to suggest related posts — YARPP via co-occurrence and text similarity, Jetpack via its cloud API. HyperTaxonomy's Related Posts block uses taxonomy relationship similarity (shared terms, inclusions, and relevance weighting from the search index). The approaches are complementary rather than conflicting: YARPP and Jetpack surface textually or semantically proximate content; HT Related Posts surfaces content that belongs to the same or related taxonomy vocabulary.

A third option is the TermConnect plugin's `[tc_peer_posts]`, which extends the same taxonomy vocabulary across to peer WordPress sites and RSS/Atom feeds. Where the Related Posts block finds content on the same site by shared terms, `[tc_peer_posts]` finds content on configured peer sites by mapped terms — posts authored by practitioners with a shared vocabulary but a different readership. The three can coexist: HT Related Posts for same-site taxonomy-driven content, YARPP or Jetpack for content-driven discovery, and TermConnect for network-sourced related reading.

A common pattern: HT Related Posts for "more on this topic" (taxonomy-driven, this site), and YARPP or Jetpack for "you might also like" (content-driven) — two distinct discovery paths on one page.

## GraphQL Plugins (WPGraphQL, Gato GraphQL)

GraphQL plugins build their own schema and don't read HyperTaxonomy's REST fields. They can read term meta, so HyperTaxonomy offers each term's relationships, groups, archive term set, pinned posts, external identifiers, shared locations, redirect and icon URLs as read-only term meta keys, with hidden terms left out for everyone. Gato GraphQL reads them once the keys are allowed in its settings; WPGraphQL needs a short snippet to add them as fields. See [Term Meta](../developer-reference/term-meta.html).

Page builders with dynamic term fields can read the same keys.

## No-Code Quick Reference

One of the advantages of HyperTaxonomy's Gutenberg block integration is that the relationship layer it adds does not require coding to use on frontend pages.

| Task | No-code tool |
|------|-------------|
| Define taxonomy vocabulary and terms | WordPress admin (any taxonomy) |
| Register custom post types and taxonomies | CPT UI / TaxoPress / Pods |
| Create inclusion relationships between terms | HyperTaxonomy term edit screen |
| Assign terms to groups | HyperTaxonomy term edit screen (or Quick Edit) |
| Configure redirects, icons, sticky posts, per-term sort | HyperTaxonomy term edit screen |
| Auto-assign terms from post content | TaxoPress (Auto Terms) |
| Auto-link term names in post content | TaxoPress (Auto Links) |
| Display a filtered post list on any page | **Post List block** (block editor inspector) |
| Display related posts on a post template | **Related Posts block** (block editor / FSE template) |
| Display related posts from peer WordPress sites | **`[tc_peer_posts]`** shortcode (TermConnect) |
| Build faceted search interfaces | HyperTaxonomy Search Widget (shortcodes) |
| Add a keyword search form | **HT PowerSearch block** |
| Display ACF relationship fields | Elementor Dynamic Tags / ACF blocks |
| Display JetEngine Relations | JetEngine Dynamic Listings |

The Post List and Related Posts blocks in particular bridge the gap between HyperTaxonomy's relationship data and editorial teams who build pages in the block editor. All query and display parameters — taxonomy, terms, sort order, filters, display options — are configurable in the inspector panel with a live server-side preview. No shortcode syntax or developer involvement is needed to create a content hub that reflects the full semantic structure of your taxonomy vocabulary.
