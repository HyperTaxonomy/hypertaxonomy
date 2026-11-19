---
title: Per-Term Settings
parent: User Guide
nav_order: 4
---

# Per-Term Settings
{: .no_toc }

Settings that apply to individual terms independently of their [relationships](term-relationships.html) — group membership, visibility, icons, descriptions, sticky posts, and archive overrides — and the term list columns that show several of them at a glance.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Term List Columns

On a taxonomy's term list (for example **Posts&nbsp;→ Tags**), HyperTaxonomy adds up to four columns, each shown when its feature is enabled for the taxonomy in [Settings&nbsp;→ HyperTaxonomy&nbsp;→ Features](plugin-settings.html#features-tab):

| Column | Shows | Feature |
|--------|-------|---------|
| **Groups** | The term's [groups](#group-membership), each linked to its edit screen; — when it belongs to none | Group Assignment |
| **Redirect** | For a [redirecting](term-relationships.html#redirects) term, ↪ and its target in italics, linked to the target's edit screen ({% include dashicon.html name="update" %} instead of ↪ for an auto-redirect left by [converting terms](merge-convert.html#convert-terms)); for a redirect target, ←&nbsp;and the number of terms that redirect to it; otherwise — | Redirects |
| **Icon** | The term's [icon](#term-icons), or a faded image placeholder when it has none | Icons |
| **Visibility** | A faded eye icon ({% include dashicon.html name="visibility" faded=true %}) for a visible term (the default) and a crossed-out eye icon ({% include dashicon.html name="hidden" %}) for a [hidden](#term-visibility) one, the same icons as for [hidden groups](term-groups.html) | Visibility |

The **Icon** and **Visibility** columns are off by default; turn them on in **Screen Options** (top right of the term list screen).

**Sortable columns:** The **Redirect**, **Icon**, **Visibility**, and **Groups** columns in the term list table are sortable. The first three default to descending on first click — putting terms *with* that attribute first — so you can immediately see all redirect terms, all terms with icons, or all hidden terms without relying on the filter dropdowns. The **Groups** column defaults to ascending (ungrouped terms first, then alphabetically by group name).

![The Tags list filtered to one group, with HyperTaxonomy's Groups, Redirect, Icon and Visibility columns circled; two tags redirect to Cougar](../assets/images/screenshots/tags-list-columns.png)

**Filter dropdowns:** The term list table provides filter dropdowns above the table. The **Redirect** dropdown offers: *All Terms*, *Non-Redirects* (terms that are not redirect sources — includes redirect targets), *Redirects*, *Auto-redirects*, and *Targets* (redirect targets only). The **Icon** dropdown offers: *All Icons*, *With Icons*, and *No Icons*. The **Visibility** dropdown offers: *All Visibility*, *Visible*, and *Hidden*. The **Groups** dropdown is a searchable checklist (with a native browser ×&nbsp;to clear the search) that also appears in the **Assign to Term Groups** bulk action modal. Filter dropdowns for columns that are hidden via Screen Options are automatically hidden as well.

---

## The Term Edit Screen

HyperTaxonomy's fields sit on the term edit screen with the standard ones: the **Term Groups** checklist, the **Term Relationships** section (see [Term Relationships](term-relationships.html)), and the per-term settings described below.

Term edit screens are protected against simultaneous editing: if another admin already has a term open, a blocking overlay appears with their name and **Go Back** / **Take Over** options. Locks expire automatically after 150 seconds of inactivity. Turn this off with **Concurrent Edit Locking** in [Settings&nbsp;→ HyperTaxonomy&nbsp;→ Advanced](plugin-settings.html#advanced-tab).

If you navigate away from a term edit screen with unsaved changes, the browser shows a confirmation prompt before leaving the page.

---

## Group Membership

Assign a term to one or more [Term Groups](term-groups.html). Needs **Group Assignment** enabled for the taxonomy in [Settings&nbsp;→ HyperTaxonomy&nbsp;→ Features](plugin-settings.html#features-tab). A group's members can also be managed from the group's own edit screen; see [Term Groups&nbsp;→ Group Members](term-groups.html#group-members).

- **Term edit screen** — Use the **Term Groups** checklist to assign the term to one or more groups. It sits directly above the [Term Relationships](term-relationships.html) section.
- **Quick Edit** — Click Quick Edit on any term row to update group assignments inline via a scrollable checklist, without leaving the list.
- **Bulk actions** — Select multiple terms and choose **Assign to Term Groups…** to open a group selector modal. The checklist uses three states (Gmail-style): a filled dash `−` means the group is currently assigned to *some but not all* selected terms (no change on save); `✓` means add this group to all selected terms; `☐` means remove this group from all selected terms. Click a checkbox to cycle through the states. The **Apply** button activates only when at least one group has been changed from its initial state. Existing group memberships not mentioned in the modal are always preserved.

---

## Term Visibility

Terms are visible by default. Mark individual terms as **hidden** to use them for internal organization without public exposure.

**What hidden terms do:**

- Their archive URLs return 404 to unauthorized users (prevents term discovery)
- Excluded from the term list, term index, term cloud and term groups shortcodes and blocks for **all users** (these are public navigation surfaces)
- Excluded from archive filter panels for **all users**
- In the search widget, offered as options (pills, checkboxes, autocomplete suggestions) only to users who can see hidden terms, so editors can search with them while visitors never see them
- Left out of the related terms HyperTaxonomy shares with other tools for **all users**: the relationship fields in the REST API (included terms, synonyms, relationships, redirect targets) and the [term meta](../developer-reference/term-meta.html) that GraphQL plugins and page builders read. WP-SKOS, when active, leaves them out of its vocabularies the same way
- Don't match in PowerSearch, search widget or post list results for unauthorized users (a search for the hidden term finds nothing, while posts carrying it are still found through their other terms); users who can see hidden terms can search by them
- Stay in the search index like any other term, kept up to date on the same events: each index row records whether a hidden term is involved, and hiding or unhiding a term only updates that flag, so searches switch over immediately without a reindex
- For unauthorized users, treated as if the term did not exist in Query Loop blocks, Latest Posts blocks, the REST API, and all other frontend post queries: filtering by a hidden term finds nothing, but posts that carry a hidden term still appear through their other terms (and in lists that don't filter by term)
- Excluded from REST API and XML sitemaps for unauthorized users (exception: under "Visible to all users", sitemaps include hidden terms because sitemaps are not per-user)
- HyperTaxonomy inclusion relationships do not surface posts from hidden terms in other terms' archives for unauthorized users
- Inclusion relationships and redirects continue working normally for users with permission
- Under **Hidden from all users**, an Administrator gets the same 404 on the term's archive as a visitor, and can't preview it

Editing a post doesn't remove hidden terms the user can't see, which can happen under the "Hidden from all users" option or with a custom role: the post keeps those terms. Only someone who can see a hidden term can remove it from a post.

**Visibility setting** ([Settings&nbsp;→ HyperTaxonomy&nbsp;→ Display](plugin-settings.html#display-tab)&nbsp;→ Hidden Terms):

| Setting | Who can see hidden terms |
|---------|-------------------------|
| Visible only to users who can edit posts (default) | Users with the `edit_posts` capability (Contributors, Authors, Editors and Administrators) |
| Visible to all users | Everyone (useful for testing only) |
| Hidden from all users | No one on the front end, not even Administrators |

This setting controls the front end, except in the places marked **all users** above, which hide hidden terms from everyone whatever it says. In wp-admin, including the post editor, users with the `manage_term_visibility` or `manage_term_relationships` capability (Editors and Administrators by default) can always see hidden terms, whichever option is selected.

**Hierarchy cascade:** When a term is hidden, HyperTaxonomy automatically marks all its WP descendants as hidden too. This keeps visibility explicit — no runtime ancestor traversal is needed and the hidden state is reliable across archives, search, post lists, and REST API responses.

When making a hidden term visible again (via the term edit screen, Quick Edit, or the Make Visible bulk action), if the term has hidden descendants a confirmation modal appears: **"N descendant terms are also hidden. Make them visible too?"** Confirming makes all descendants visible too; declining leaves them hidden.

**Reparent and create cascade:** If a term is moved to be a child of a hidden parent, the term and all its own descendants are automatically marked hidden at save time. Similarly, creating a new term under a hidden parent immediately marks the new term hidden. No confirmation is requested in either case — making additional terms hidden is always safe.

**Enable per taxonomy:** Settings&nbsp;→ HyperTaxonomy&nbsp;→ Features&nbsp;→ [taxonomy]&nbsp;→ Visibility.

**Setting visibility:**
- **Term edit screen** — In the **Visibility** row, check or uncheck **Hide this term from public view**.
- **Quick Edit** — Click Quick Edit on any term row to toggle the "Hidden from public display" checkbox inline, without leaving the list.
- **Bulk actions** — Select multiple terms and choose **Make Hidden** or **Make Visible** from the Bulk Actions dropdown.

While any ancestor term is hidden, this checkbox on its descendants is disabled in the UI — make the ancestor visible first before you can individually reveal a descendant.

The term list's **Visibility** column shows each term's state: see [Term List Columns](#term-list-columns).

**Limitation — native WordPress category hierarchy:**

HyperTaxonomy's own inclusion expansion correctly excludes hidden terms for unauthorized users. The remaining gap is WordPress's native parent→child behavior for hierarchical taxonomies (categories): WordPress automatically folds child terms into a parent's archive query and HyperTaxonomy cannot intercept this. If a category is hidden, its posts still appear in its visible parent's archive — the category name is never exposed anywhere, but the content is reachable.

This limitation does not affect flat taxonomies (tags) or non-hierarchical custom taxonomies.

**Workaround:** Hide the parent category as well. A hidden category's archive returns 404, which prevents the content from being reached through that route.

---

## Term Icons

Upload a custom icon image for any term to display alongside it in shortcodes, search widgets, and theme templates.

**Setup:**

1. Configure icon dimensions in **Settings&nbsp;→ HyperTaxonomy&nbsp;→ Icons** for each taxonomy (width, height, border-radius style)
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

**Icon sizes:** Three sizes are generated from your configured dimensions. The configured dimensions are the large (full) size; WordPress generates the smaller variants automatically after upload or crop. Medium and small size percentages default to 50% and 25% but can be configured independently per taxonomy in **Settings&nbsp;→ HyperTaxonomy&nbsp;→ Icons**. Changing the percentages regenerates the medium and small files from the existing uploaded icons without requiring re-upload:
- Large (configured dimensions) — for archive headers
- Medium (default 50% of large) — for lists
- Small (default 25% of large) — for pills and inline display

**Changing dimensions:** Changing icon dimensions in Settings clears all existing icon assignments for that taxonomy (image files are preserved; you need to re-assign them).

**Bulk actions:** Select multiple terms on the term list screen and choose **Remove Icon** to clear icons from all selected terms at once.

---

## Archive Descriptions

Enable **Show Description** on any term to display its description as formatted HTML below the archive title. This allows rich, styled introductory text on term archive pages.

[![A tag archive whose heading lists the tags it includes, with the tag's icon, its description, and a pinned post](../assets/images/screenshots/term-archive.png){: .screenshot-small}](../assets/images/screenshots/term-archive.png)

To enable: Edit a term, expand **More options**, and check **Show Description**. The term's description field (standard WordPress) is then rendered as HTML on the archive page — above any sticky posts, if present. Requires **Archive Customization** enabled for the taxonomy (Settings&nbsp;→ HyperTaxonomy&nbsp;→ Features) and **Archive Description** enabled on the Display tab.

The description field appears in the **More options** collapsible on both the Add New Term and Edit Term screens. Its helper text explains that the description can also be used for autocomplete suggestions and PowerSearch relevance matching, which depends on **Match term descriptions** in the Search tab (off by default).

---

## Sticky Posts

Pin specific posts to appear at the top of a term's archive page, regardless of date or relevance order.

This is separate from WordPress's own sticky posts. WordPress's **Stick to the top of the blog** option works only for posts, and pins them only on the blog's posts page, not on tag or category archives. HyperTaxonomy's sticky posts are set per term: a post can be pinned at the top of one term's archive and appear in its normal position everywhere else. The two settings don't affect each other.

HyperTaxonomy's sticky posts work with any enabled taxonomy (tags, categories, or custom taxonomies) and any indexed post type (posts, pages, or custom post types).

**How it works:**
- Sticky posts are pinned at the top of page 1 and excluded from subsequent pages
- At most the term's **Maximum sticky posts** (or the global setting) are pinned; any further sticky posts appear in their normal position. Pagination accounts for the pinned posts, so no regular post is skipped
- The post must actually be assigned to that term to be sticky in its archive
- Enable per taxonomy: Settings&nbsp;→ HyperTaxonomy&nbsp;→ Features&nbsp;→ Archive Customization. **Sticky Posts** on the Display tab must also be on

**To make a post sticky for specific terms:**
1. Edit the post in the block editor
2. Enable **[Sticky Pushpins](editing-posts.html#sticky-pushpins)** in the ··· menu&nbsp;→ HyperTaxonomy group (if not already on)
3. Click the 📌 pushpin next to each term where this post should appear at the top — it turns filled when active
4. Update the post

**Maximum sticky posts per term:** Configurable per term in the term edit screen (Archive Settings&nbsp;→ **Maximum sticky posts**). Leave blank to use the global setting (Settings&nbsp;→ HyperTaxonomy&nbsp;→ Display, default 10). The number pinned never exceeds the archive's posts per page.

---

## Archive Settings

Per-term archive overrides available on any enabled taxonomy's term edit screen (the **Archive Settings** section, shown when Archive Customization is enabled for that taxonomy):

| Setting | What it does |
|---------|-------------|
| **Posts per page** | Override the global WordPress posts-per-page for this term's archive. Leave blank to use the WordPress default. |
| **Maximum sticky posts** | Maximum number of sticky posts shown at the top of this term's archive. Leave blank to use the global maximum from Settings&nbsp;→ HyperTaxonomy&nbsp;→ Display. |
| **Sort order** | Default sort order for this term's archive. Click **+** to add criteria and drag them to change their order. Fields are date, last modified, title, author, comments, latest comment, latest activity, relevance, and ID. Leave empty to use the site default. Can be overridden at runtime via the `?htax_orderby=` URL parameter (e.g. from a [`[ht_sort_order_options action="navigate"]`](archive-controls.html#ht_sort_order_options) picker on the archive page). |

These settings apply to any enabled taxonomy (tags, categories, or custom taxonomies) — not just built-in ones.

