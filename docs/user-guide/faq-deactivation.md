---
title: FAQ & Deactivation
parent: User Guide
nav_order: 10
---

## 14. FAQ & Troubleshooting

### Choosing Features

Common decision points:

**Inclusion vs synonym?** An inclusion is one-directional: A includes B means B's posts appear in A's archive, but A's posts don't appear in B's. A synonym is mutual: add A to B's included list AND B to A's included list. Both archives then show posts from either term. Use inclusions for broader/narrower relationships (Programming → JavaScript); use synonyms for terms that cover the same concept from different angles (JS → JavaScript).

**Inclusion vs redirect?** Inclusion expands an archive — both terms remain visible and functional, each with its own URL and archive. A redirect replaces an archive — visitors to the old URL are sent to the target term's archive, and the original term no longer shows in navigation (for unauthorized users). Use inclusions when both terms should remain first-class navigation items; use redirects when you are consolidating or renaming and want the old URL to become an alias.

**Redirect vs merge?** Both consolidate terms. A redirect keeps both terms in the database and creates a permanent 301 forward — reversible, the original term still exists. A merge physically reassigns all post relationships and deletes the source term — irreversible. Use redirects for ongoing aliases; use merge for permanent cleanup of duplicate terms.

**Group vs tag cloud?** A Term Group is a structural container — a named set of terms, used in inclusions, in post list shortcodes, and in the Query Loop block filter. A tag cloud (`[ht_term_cloud]`) is a display component — it renders terms with size proportional to post count. These serve entirely different purposes and are often used together.

**Sticky post vs sort order override?** A sticky post pins one specific post to the top of one specific term archive — useful for editorial choices ("always show the glossary at the top of this tag"). A sort order override changes the ordering algorithm for all results on a term archive — useful for making comment activity or modification date the primary ranking signal. They can coexist: the sticky appears first, then the sort order applies to everything else.

**PowerSearch vs Search Widget?** PowerSearch is text-driven: a user types a query with field operators and gets relevance-ranked results across post content and taxonomy names. The Search Widget is facet-driven: a user selects taxonomy terms from pills or checklists and narrows results by tag, category, or custom taxonomy. They can appear on the same page and complement each other.

**Hidden term vs draft posts?** A hidden term controls taxonomy navigation visibility — unauthorized users don't see the term in archives, shortcodes, or search results, but the posts themselves remain publicly accessible through direct URLs. Draft posts hide the posts themselves. Use hidden terms for curator-only filing systems (internal categories, editorial flags) where the posts are public but the organizational structure isn't.

---

### FAQ

**How do I create a relationship between terms?**
Edit any tag or category and scroll to the HyperTaxonomy section. Use the autocomplete fields to add inclusions, groups, or redirects, then save.

**What's the difference between Included Terms and Container Terms?**
Two views of the same relationship. "Included" lists terms whose posts appear in this term's archive. "Container" lists terms that pull this term's posts into their archives. Adding A to B's included list automatically makes B a container of A.

**Are inverse relationships created automatically?**
Yes. Adding Term B to Term A's included list simultaneously makes Term A a container of Term B — and vice versa. You only ever configure the relationship from one side; the other direction is always kept in sync automatically.

**What are Term Groups?**
Term Groups is a built-in hierarchical taxonomy for organizing terms into named groups that span multiple taxonomies. Including a group in a term's archive pulls in all current and future members of that group automatically — you don't need to update the inclusion whenever the group's membership changes. Groups have their own archive pages showing posts from all member terms.

**Do I need additional plugins?**
No. All features — Term Groups, display shortcodes, search widget, relationship management, import/export — are built in. No external dependencies are required.

**Why doesn't indirect inclusion work (A includes B includes C)?**
By design — to prevent unintended content cascade. Each relationship must be explicitly defined. The only exception: including a Term Group includes all current and future members of that group.

**How do synonyms work?**
Add Term B to Term A's included list AND Term A to Term B's included list. Both archives then show content from either term. Synonyms are marked with the ⇆ icon in the admin interface. Circular inclusion (A includes B, B includes A) is explicitly supported — it differs from circular redirects, which are blocked.

**How do redirects work?**
When a visitor goes to a redirect term's archive URL, they are automatically sent to the target term's archive with a 301 permanent redirect. The redirect term has no accessible archive of its own. Redirect chains collapse automatically — if A redirects to B and you later redirect B to C, A updates to point directly to C.

**How are redirects different from synonyms?**
A redirect sends visitors away from Term A's archive to Term B's archive (301 HTTP). A synonym makes both archives show content from both terms without redirecting anyone.

**How do exclusions work?**
Exclusions remove specific terms from the included set after all inclusions have been gathered. The order: (1) expand included groups, (2) add directly included terms, (3) add terms via container groups, (4) remove directly excluded terms, (5) remove self (always) and remove ancestors and descendants (hierarchical taxonomies only). Exclusions always win — no inclusion source can override them. Exclusions never affect WordPress's native parent/child category relationships.

**Do exclusions affect category hierarchy?**
No. Exclusions only affect HyperTaxonomy-managed inclusion results. WordPress native parent-child relationships are never modified.

**Does this work with custom taxonomies?**
Yes. Enable custom taxonomies in Settings > HyperTaxonomy > Features. All features (relationships, display shortcodes, search widget, PowerSearch) support custom taxonomies via the `taxonomy` parameter.

**How do I make a post sticky for specific terms?**
Edit the post, enable **Sticky Pushpins** in the block editor's ··· menu → HyperTaxonomy group, then click the 📌 pushpin next to each term. The post appears at the top of those term archives on page 1 and is excluded from subsequent pages.

**Can I export term relationships to another site?**
Yes, via Settings > HyperTaxonomy > Export / Import. The JSON export uses term slugs for cross-site compatibility.

**Is this compatible with caching plugins?**
Yes — WP Rocket, W3 Total Cache, WP Super Cache. HyperTaxonomy uses transients and clears them on data changes.

### Troubleshooting

| Symptom | Solution |
|---------|---------|
| Relationships not working | Check for hierarchy conflicts — terms from hierarchical taxonomies cannot include their ancestors |
| Redirect not working | Verify redirect feature is enabled for the source taxonomy and the target term exists |
| Sticky posts missing | Verify the post has the term assigned; sticky posts only appear on page 1 |
| Icons not displaying | Check icon feature is enabled for the taxonomy in Settings → HyperTaxonomy → Features, then configure dimensions in the Icons tab |
| Frontend styles missing | Settings > HyperTaxonomy > Display — ensure "Disable default frontend styles" is unchecked |
| Search widget not loading | Enable "Always load search widget assets" if using a page builder |
| Tag Groups / PublishPress Tag Groups import shows "No data found" | Verify `term_groups` and `term_group_labels` options exist in `wp_options` |
| Archive shows unexpected terms | Enable debug mode and append `?htax_debug=1` to the archive URL (admin only) |

**Debug logging:**

```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'HTAX_DEBUG_VERBOSE', true ); // Adds backtraces to log entries
```

---

## 15. Deactivation & Uninstall

### What Happens When You Deactivate

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

HyperTaxonomy-specific capabilities (`manage_term_visibility`, `manage_term_icons`, `manage_term_redirects`, etc.) are removed from all roles on deactivation.

**Search**

The `wp_htax_search_terms` and `wp_htax_post_text` tables remain in place. Native WordPress search continues to work normally, but HyperTaxonomy's PowerSearch and search widget shortcodes are not available.

### Reactivation

On reactivation, HyperTaxonomy:

- Restores capabilities to their configured roles
- Re-registers the `group` taxonomy and flushes rewrite rules (restoring group archive URLs)
- Scans all hidden terms and re-propagates hidden status to any descendants that may have drifted (e.g. if terms were reparented while the plugin was deactivated)
- Removes any orphaned relationship rows for terms that were deleted while the plugin was inactive, and clears any stale redirect metadata left behind by those deletions
- Re-resolves hierarchy conflicts (e.g. a term that became both included and excluded through group changes made while inactive)
- Rebuilds the search index in the background

All previously configured relationships, redirects, group memberships, and settings resume immediately with no manual intervention.

### Uninstall

Uninstalling HyperTaxonomy (Plugins screen → Delete) always removes:

- All HyperTaxonomy settings and internal state (`htax_settings`, custom relationship types, the reindex queue, Tag Groups import/sync state)
- Scheduled background tasks and cached data (transients, including stored search-results links)
- Per-user state: dismissed admin notices, and HyperTaxonomy's post-editor display preferences and sidebar panel positions

When **Settings > HyperTaxonomy > Advanced > Delete all HyperTaxonomy data when plugin is uninstalled** is checked, it also removes:

- The `wp_htax_term_relationships` table (relationships, group memberships, redirects)
- The `wp_htax_search_terms` table (search index)
- The `wp_htax_post_text` table (HTML-stripped post text for FULLTEXT search)
- All HyperTaxonomy term meta on every taxonomy (`_htax_is_hidden`, `_htax_icon_id`, archive settings, etc.)
- Sticky-post assignments (post meta `_htax_sticky_*`)
- All group terms and term taxonomy rows in the `group` taxonomy
- Custom indexes added to `wp_posts`, `wp_terms`, `wp_term_taxonomy` and `wp_termmeta` by HyperTaxonomy

When the setting is off, tables and term data survive a reinstall intact. On multisite, every site is cleaned according to its own setting.

**What is not removed:** standard WordPress term data (names, slugs, descriptions, parent/child relationships, post-term assignments) is never touched. Your terms and their assignments to posts are always preserved regardless of what HyperTaxonomy does.
