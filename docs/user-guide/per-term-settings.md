---
title: Per-Term Settings
parent: User Guide
nav_order: 4
---

## 6. Per-Term Settings

Settings that apply to individual terms independently of their relationships — visibility, icons, descriptions, sticky posts, and archive overrides.

### Term Visibility

Mark individual terms as **hidden** to use them for internal organization without public exposure.

**What hidden terms do:**

- Their archive URLs return 404 to unauthorized users (prevents term discovery)
- Excluded from term list and term index display shortcodes for **all users** (these are public navigation surfaces)
- Excluded from search widget option lists (pills, checkboxes) and filter panels for **all users**
- Don't match in PowerSearch or post list results for unauthorized users (a search for the hidden term finds nothing, while posts carrying it are still found through their other terms); privileged users can search by hidden terms
- For unauthorized users, treated as if the term did not exist in Query Loop blocks, Latest Posts blocks, the REST API, and all other frontend post queries: filtering by a hidden term finds nothing, but posts that carry a hidden term still appear through their other terms (and in lists that don't filter by term)
- Excluded from REST API and XML sitemaps for unauthorized users (exception: when the visibility setting is "all", sitemaps include hidden terms because sitemaps are not per-user)
- HyperTaxonomy inclusion relationships do not surface posts from hidden terms in other terms' archives for unauthorized users
- Inclusion relationships and redirects continue working normally for users with permission
- Always visible in the WordPress admin regardless of the visibility setting

**Visibility setting** (Settings > HyperTaxonomy > Display > Hidden Terms):

| Setting | Who can see hidden terms |
|---------|-------------------------|
| `editors` (default) | Users with `edit_posts` capability |
| `all` | Everyone (useful for testing only) |
| `none` | No one (admin can still edit terms but they appear hidden everywhere) |

**Hierarchy cascade:** When a term is hidden, HyperTaxonomy automatically marks all its WP descendants as hidden too. This keeps visibility explicit — no runtime ancestor traversal is needed and the hidden state is reliable across archives, search, post lists, and REST API responses.

When making a hidden term visible again (via the term edit screen, Quick Edit, or the Make Visible bulk action), if the term has hidden descendants a confirmation modal appears: **"N descendant terms are also hidden. Make them visible too?"** Confirming cascades the make-visible operation to all descendants; declining leaves descendants hidden individually. The descendants are not restored automatically without this confirmation.

**Reparent and create cascade:** If a term is moved to be a child of a hidden parent, the term and all its own descendants are automatically marked hidden at save time. Similarly, creating a new term under a hidden parent immediately marks the new term hidden. No confirmation is requested in either case — making additional terms hidden is always safe.

**Enable per taxonomy:** Settings > HyperTaxonomy > Features > [taxonomy] > Visibility.

**Setting visibility:**
- **Term edit screen** — Check or uncheck the **Hidden** checkbox in the HyperTaxonomy fields.
- **Quick Edit** — Click Quick Edit on any term row to toggle the "Hidden from public display" checkbox inline, without leaving the list.
- **Bulk actions** — Select multiple terms and choose **Make Hidden** or **Make Visible** from the Bulk Actions dropdown.

While any ancestor term is hidden, the Hidden checkbox on its descendants is disabled in the UI — make the ancestor visible first before you can individually reveal a descendant.

**Limitation — native WordPress category hierarchy:**

HyperTaxonomy's own inclusion expansion correctly excludes hidden terms for unauthorized users. The remaining gap is WordPress's native parent→child behavior for hierarchical taxonomies (categories): WordPress automatically folds child terms into a parent's archive query and HyperTaxonomy cannot intercept this. If a category is hidden, its posts still appear in its visible parent's archive — the category name is never exposed anywhere, but the content is reachable.

This limitation does not affect flat taxonomies (tags) or non-hierarchical custom taxonomies.

**Workaround:** Hide the parent category as well. A hidden category's archive returns 404, which prevents the content from being reached through that route.

---

### Term Icons

Upload a custom icon image for any term to display alongside it in shortcodes, search widgets, and theme templates.

**Setup:**

1. Configure icon dimensions in **Settings > HyperTaxonomy > Icons** for each taxonomy (width, height, border-radius style)
2. Edit any term (tag, category, or custom taxonomy term)
3. Click **Upload Icon** in the Term Icon field
4. Select or upload an image (see size rules below)
5. Save the term

**Image size rules:**
- **Exact dimensions** — accepted immediately.
- **Larger than configured dimensions** — a crop dialog opens. Drag the fixed-size selection rectangle to choose which region to use, then click **Crop & use**. The crop cannot be resized — only repositioned. Cancelling leaves the previous icon unchanged.
- **Smaller than configured dimensions** — rejected with an error message. Images smaller than the required size are not shown in the media library picker at all. Upload a larger source image.
- **SVG** — accepted at any size. If WordPress can read the SVG's natural dimensions (from `width`/`height` attributes or `viewBox`) and the proportions differ from the configured slot, a warning is shown — you can proceed or cancel. SVGs with unknown dimensions are accepted silently.

**Automatic file naming:** Icons are named after the term slug (e.g., `php.png` for a "PHP" tag). On name collision, the taxonomy is appended (`php-category.png`). Icons are automatically renamed when you change a term's slug or convert it to a different taxonomy.

**Icon sizes:** Three sizes are generated from your configured dimensions. The configured dimensions are the large (full) size; WordPress generates the smaller variants automatically after upload or crop. Medium and small size percentages default to 50% and 25% but can be configured independently per taxonomy in **Settings → HyperTaxonomy → Icons**. Changing the percentages regenerates the medium and small files from the existing uploaded icons without requiring re-upload:
- Large (configured dimensions) — for archive headers
- Medium (default 50% of large) — for lists
- Small (default 25% of large) — for pills and inline display

**Changing dimensions:** Changing icon dimensions in Settings clears all existing icon assignments for that taxonomy (image files are preserved; you need to re-assign them).

**Bulk actions:** Select multiple terms on the term list screen and choose **Remove Icon** to clear icons from all selected terms at once.

**Sortable columns:** The **Redirect**, **Icon**, **Visibility**, and **Groups** columns in the term list table are sortable. The first three default to descending on first click — putting terms *with* that attribute first — so you can immediately see all redirect terms, all terms with icons, or all hidden terms without relying on the filter dropdowns. The **Groups** column defaults to ascending (ungrouped terms first, then alphabetically by group name). The **Icon** and **Visibility** columns are hidden by default; enable them via **Screen Options** (top-right of the term list screen).

**Filter dropdowns:** The term list table provides filter dropdowns above the table. The **Redirect** dropdown offers: *All Terms*, *Non-Redirects* (terms that are not redirect sources — includes redirect targets), *Redirects*, *Auto-redirects*, and *Targets* (redirect targets only). The **Icon** dropdown offers: *All Icons*, *With Icons*, and *No Icons*. The **Visibility** dropdown offers: *All Visibility*, *Visible*, and *Hidden*. The **Groups** dropdown is a searchable checklist (with a native browser × to clear the search) that also appears in the **Assign to Term Groups** bulk action modal. Filter dropdowns for columns that are hidden via Screen Options are automatically hidden as well.

---

### Archive Descriptions

Enable **Always Show Description** on any term to display its description as formatted HTML below the archive title. This allows rich, styled introductory text on term archive pages.

To enable: Edit a term, expand **More options**, and check **Always Show Description**. The term's description field (standard WordPress) is then rendered as HTML on the archive page — above any sticky posts, if present. Requires **Archive Customization** enabled for the taxonomy (Settings > HyperTaxonomy > Features).

The description field appears in the **More options** collapsible on both the Add New Term and Edit Term screens. Its helper text explains that the description is also indexed for PowerSearch autocomplete and relevance matching.

---

### Peer Sites

External knowledge-base identifiers, WordPress peer site connections, RSS/Atom feed link types, and the "See also" display on term archives are managed by the **[TermConnect](https://wordpress.org/plugins/termconnect/)** plugin. Install TermConnect and go to **Terms → Peer Sites** to configure link types for Wikidata, GeoNames, Wikipedia, peer WordPress sites, and other knowledge bases. The External Links panel on term edit screens and the "See also" section on term archives are provided by TermConnect when it is active.

See the [TermConnect User Guide](https://wordpress.org/plugins/termconnect/) for full documentation.

**REST API:** External identifier data (Wikidata, GeoNames, etc.) is exposed via TermConnect's own `tc_links` field on term REST endpoints, gated by TermConnect's **Share identifiers with other sites** setting. HyperTaxonomy does not duplicate this in its own REST fields.

---

### Sticky Posts

Pin specific posts to appear at the top of a term's archive page, regardless of date or relevance order. Works with any enabled taxonomy (tags, categories, or custom taxonomies) and any indexed post type (posts, pages, or custom post types).

**How it works:**
- Sticky posts are pinned at the top of page 1 and excluded from subsequent pages
- At most the term's **Maximum sticky posts** (or the global setting) are pinned; any further sticky posts appear in their normal position. Pagination accounts for the pinned posts, so no regular post is skipped
- Stickiness is per-term — a post can be sticky for specific terms without being sticky everywhere
- The post must actually be assigned to that term to be sticky in its archive
- Enable per taxonomy: Settings > HyperTaxonomy > Features > Archive Customization

**To make a post sticky for specific terms:**
1. Edit the post in the block editor
2. Enable **Sticky Pushpins** in the ··· menu → HyperTaxonomy group (if not already on)
3. Click the 📌 pushpin next to each term where this post should appear at the top — it turns filled when active
4. Update the post

**Maximum sticky posts per term:** Configurable per term in the term edit screen (Archive Settings → Maximum sticky posts). Leave blank to use the global setting (Settings → HyperTaxonomy, default 10). The number pinned never exceeds the archive's posts per page.

---

### Archive Settings

Per-term archive overrides available on any enabled taxonomy's term edit screen (the **Archive Settings** section, shown when Archive Customization is enabled for that taxonomy):

| Setting | What it does |
|---------|-------------|
| **Posts Per Page** | Override the global WordPress posts-per-page for this term's archive. Leave blank to use the WordPress default. |
| **Max Sticky Posts** | Maximum number of sticky posts shown at the top of this term's archive. Leave blank to use the global maximum from Settings > HyperTaxonomy > Features. |
| **Sort Order** | Default sort order for this term's archive, e.g. `date:desc` or `date:desc,title:asc`. Leave blank to use the site default. Can be overridden at runtime via the `?htax_orderby=` URL parameter (e.g. from a `[ht_sort_order_options action="navigate"]` picker on the archive page). |

These settings apply to any enabled taxonomy (tags, categories, or custom taxonomies) — not just built-in ones.

---

### Term Restructuring

Two operations for permanently changing a term's identity rather than just its relationships:

| Operation | What changes | Term deleted? |
|-----------|-------------|---------------|
| **Merge** | Posts and relationships move from source term(s) to a target term | Yes — source terms deleted |
| **Taxonomy conversion** | An existing term moves from one taxonomy to another | No — same term, new taxonomy |

Both operations can create **redirect stubs** so old archive URLs continue to work, and both transfer **group memberships** to the appropriate destination.

#### Merge Terms

Permanently consolidate duplicate or outdated terms into a single canonical term. All posts, group memberships, and relationships from the source terms are transferred to the target, and the source terms are deleted.

**How to merge:**

1. Go to **Terms > Merge Terms**
2. Select the **Taxonomy** the terms belong to
3. Type and select one or more **Source terms** (the terms to be deleted)
4. Type and select a **Target term** (the term to keep)
5. Choose whether to **Create 301 redirect stubs** for the deleted terms (recommended)
6. Click **Merge Terms**

**What happens:**

- All posts assigned to source terms are reassigned to the target term
- Group memberships from source terms are added to the target term
- Inclusion and exclusion relationships involving source terms are transferred to the target term
- Source terms are deleted

**Redirect stubs (recommended):** When enabled, a new redirect term is created with each source term's name, pointing to the target. Old archive URLs (e.g. `/tag/old-name/`) then 301-redirect to the target's archive, preserving SEO value and preventing broken links. If the target term is itself a redirect, stubs point directly to the chain's final destination.

**Warning:** This operation is permanent and cannot be undone. Back up your database before merging.

**Comparison with redirects:** Redirects leave both terms in place and redirect the archive URL. Merging physically reassigns all post relationships and deletes the source term. Use redirects for temporary or reversible consolidation; use merge for permanent cleanup.

#### Convert Terms

**Terms > Convert Terms** moves terms from one taxonomy to another. A fresh term is created in the target taxonomy and all post assignments are moved to it. What happens to the old term depends on whether you choose to leave a redirect stub.

**How to convert:**

1. Open **Terms > Convert Terms**.
2. Choose a source taxonomy. The term list loads automatically.
3. Check the terms you want to convert. Use **Select all / Deselect all** as needed.
4. Choose the target taxonomy.
5. Optionally check **Create redirect stubs** (see below).
6. Click **Convert Selected Terms**.

**Limitations before converting:**

- If a term with the same name already exists in the target taxonomy, that term is skipped with an error — use **Merge Terms** instead.
- Terms that are already redirect stubs cannot be converted — remove the redirect on the term edit screen first.
- The site's default category cannot be converted — change the default in Settings > Writing first.

**With redirect stubs (default):**

The old term stays in the old taxonomy and becomes the redirect stub — it keeps its original slug so existing archive URLs (`/category/example/`) continue to work and redirect to the new taxonomy archive. The stub retains all its own metadata: icon, group memberships, inclusion relationships, and parent-child position in the hierarchy. The new term in the target taxonomy starts clean with no inherited metadata, and its sticky post assignments are copied from the old term.

**Without redirect stubs:**

The old term is deleted and its original URL slug is assigned to the new term, giving the new taxonomy archive a clean URL. The new term starts with no inherited metadata. Old archive URLs become 404s.

**Reversible conversions (redirect case only):** Converting a term back to the original taxonomy automatically detects the redirect stub and unwinds the conversion — posts are moved back to the stub (which regains its original role as a normal term), and the replacement term is deleted.

**Slugs:** The new term's slug is auto-generated when a redirect stub is created (the stub needs the original slug for URL routing). Without a redirect stub, the new term receives the original slug.

**Integration with other tools.** HyperTaxonomy also tracks conversions made by the [Categories to Tags Converter](https://wordpress.org/plugins/wpcat2tag-importer/) and other plugins that use the standard WordPress term-creation and term-deletion hooks. For those conversions, redirect stubs and sticky post transfers are applied automatically. Direct database manipulation bypasses all hooks and is not tracked.

---

