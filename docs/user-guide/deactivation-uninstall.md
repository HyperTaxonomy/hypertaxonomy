---
title: Deactivation & Uninstall
parent: User Guide
nav_order: 15
---

# Deactivation & Uninstall

What changes while HyperTaxonomy is deactivated, what reactivating it restores, and what uninstalling it deletes. Your terms and their assignments to posts are never removed.

## What Happens When You Deactivate

Deactivating HyperTaxonomy (without uninstalling) leaves all your data intact — relationships, group memberships, redirects, icons, and settings are all preserved and will be available immediately when you reactivate. The plugin simply stops running its hooks. Here is what changes while it is deactivated:

**Term archives**

Native WordPress term archives (categories, tags, custom taxonomies) continue to work and serve their archive URLs normally. However, HyperTaxonomy's inclusion relationships, container groups, and group memberships are not applied. Archives will only show posts that are directly tagged with that specific term — no included terms, no group-based expansion.

**Redirects**

Redirect terms still exist but the 301 redirect logic does not run. A visitor navigating to a redirect term's archive will see a standard WordPress archive for that term (which may have few or no posts, since the expectation was that it would redirect). The redirect metadata is preserved and will be active again on reactivation.

**Hidden terms**

The `_htax_is_hidden` metadata remains on terms, but HyperTaxonomy's filtering hooks are not active. Hidden terms become fully public — their archives are accessible to anyone, they appear in tag clouds, term lists, REST API responses, and search results, just like any ordinary term.

**Term Groups**

The `group` custom taxonomy is registered by HyperTaxonomy. When deactivated, the taxonomy is no longer registered: group archive URLs (`/group/my-group/`) will 404 and groups will not appear in the admin. Group terms remain in the database and all group memberships are preserved — they are immediately restored on reactivation.

**Blocks and shortcodes**

HyperTaxonomy's Gutenberg blocks will not render on the frontend (they output nothing). Shortcodes such as `[ht_post_list]`, `[ht_term_list]`, and `[ht_power_search]` are not registered and will display as raw shortcode text in page content. The group filter attribute is stored in blocks but has no effect — Query Loop and Latest Posts blocks fall back to their native behavior.

**Sticky posts**

Per-term sticky post pinning does not apply. Posts continue to display in default archive order.

**Term icons**

Icon display hooks do not run. Icons are not shown in archives or shortcode output.

**Capabilities**

HyperTaxonomy-specific capabilities (`manage_term_visibility`, `manage_term_icons`, `manage_term_redirects`, etc.) are removed from all roles on deactivation. See [Roles & Permissions](roles-permissions.html) for the full list.

**Search**

The `wp_htax_search_terms` and `wp_htax_post_text` tables remain in place. Native WordPress search continues to work normally, but HyperTaxonomy's PowerSearch and search widget shortcodes are not available.

## Reactivation

On reactivation, HyperTaxonomy:

- Restores capabilities to their configured roles
- Re-registers the `group` taxonomy and flushes rewrite rules (restoring group archive URLs)
- Scans all hidden terms and re-propagates hidden status to any descendants that may have drifted (e.g. if terms were reparented while the plugin was deactivated)
- Removes any orphaned relationship rows and sticky post assignments for terms that were deleted while the plugin was inactive, and clears any stale redirect metadata left behind by those deletions
- Re-resolves hierarchy conflicts (e.g. a term that became both included and excluded through group changes made while inactive)
- Rebuilds the search index in the background

All previously configured relationships, redirects, group memberships, and settings resume immediately with no manual intervention.

## Uninstall

Uninstalling HyperTaxonomy (Plugins screen → Delete) always removes:

- All HyperTaxonomy settings and internal state (`htax_settings`, custom relationship types, the reindex queue, Tag Groups import/sync state)
- Scheduled background tasks and cached data (transients, including stored search-results links)
- Per-user state: dismissed admin notices, and HyperTaxonomy's post-editor display preferences and sidebar panel positions

When **Settings > HyperTaxonomy > Advanced > Delete all HyperTaxonomy data when the plugin is deleted** is checked, it also removes:

- The `wp_htax_term_relationships` table (relationships, group memberships, redirects)
- The `wp_htax_search_terms` table (search index)
- The `wp_htax_post_text` table (HTML-stripped post text for FULLTEXT search)
- All HyperTaxonomy term meta on every taxonomy (`_htax_is_hidden`, `_htax_icon_id`, archive settings, etc.)
- Sticky-post assignments (post meta `_htax_sticky_*`)
- All group terms and term taxonomy rows in the `group` taxonomy
- Custom indexes added to `wp_posts`, `wp_terms`, `wp_term_taxonomy` and `wp_termmeta` by HyperTaxonomy

When the setting is off, tables and term data survive a reinstall intact. On multisite, every site is cleaned according to its own setting.

**What is not removed:** standard WordPress term data (names, slugs, descriptions, parent/child relationships, post-term assignments) is never touched. Your terms and their assignments to posts are always preserved regardless of what HyperTaxonomy does.
