---
title: FAQ & Troubleshooting
parent: User Guide
nav_order: 18
---

# FAQ & Troubleshooting

## FAQ

**How do I create a relationship between terms?**
Edit any tag or category. In its **Term Relationships** section, add terms or groups to the **Included**, **Container** or **Excluded** fields, which search as you type. To redirect the term, check **Redirect this tag to another term** (named for the taxonomy) and choose the target. Then save. To put the term in groups, use the **Term Groups** checklist just above that section. See [Term Relationships](term-relationships.html).

**What's the difference between the Included and Container fields?**
Two views of the same relationship. "Included" lists terms whose posts appear in this term's archive. "Container" lists terms that pull this term's posts into their archives. Adding A to B's included list automatically makes B a container of A, and the other way round, so you only ever set the relationship up on one side.

**What are Term Groups?**
Term Groups is a hierarchical taxonomy supplied by this plugin for organizing terms into named groups that span multiple taxonomies. Including a group in a term's archive pulls in all current and future members of that group from the term's own taxonomy automatically — you don't need to update the inclusion whenever the group's membership changes. Groups have their own archive pages showing posts from all member terms. See [Term Groups](term-groups.html).

**Do I need additional plugins?**
No. All features — Term Groups, display shortcodes, search widget, relationship management, import/export — are built in.

**Why doesn't indirect inclusion work (A includes B includes C)?**
By design — to prevent unintended content cascade. Each relationship must be explicitly defined. To include a changing set of terms without listing each one, include a Term Group: its current and future members are all included.

**How do synonyms work?**
Add Term B to Term A's included list AND Term A to Term B's included list. Both archives then show the posts assigned to either term. Two terms also become synonyms when they include each other through groups. Synonyms share posts, not relationships: if one of them includes other terms, only its own archive shows their posts. Synonyms are marked with the ⇆ icon in the admin interface, and **Terms → Synonyms** lists every pair, how it came about, and whether the two archives differ. See [Term Relationships → Synonyms](term-relationships.html#synonyms). Mutual inclusion (A includes B, B includes A) is explicitly supported — it differs from circular redirects, which are blocked, and from including an ancestor in a hierarchy, which is disregarded.

**How do redirects work?**
When a visitor goes to a redirect term's archive URL, they are automatically sent to the target term's archive with a 301 permanent redirect. The redirect term has no accessible archive of its own. Redirect chains collapse automatically — if A redirects to B and you later redirect B to C, A updates to point directly to C.

**How are redirects different from synonyms?**
A redirect sends visitors away from Term A's archive to Term B's archive (301 HTTP). A synonym makes both archives show content from both terms without redirecting anyone.

**How do exclusions work?**
Exclusions remove specific terms from the included set after all inclusions have been gathered. The order: (1) expand included groups, (2) add directly included terms, (3) add terms via container groups, (4) remove directly excluded terms, (5) remove self (always) and remove ancestors and descendants (hierarchical taxonomies only). Exclusions always win — no inclusion source can override them.

**Do exclusions affect category hierarchy?**
No. Exclusions only affect HyperTaxonomy-managed inclusion results. WordPress native parent-child relationships are never modified.

**Does this work with custom taxonomies?**
Yes. Enable custom taxonomies in [Settings > HyperTaxonomy > Features](plugin-settings.html#features-tab). Relationships, term lists, post lists and the search widget's fields then work with them (shortcodes and blocks take a `taxonomy` parameter), and PowerSearch gets [operators](powersearch.html#query-syntax) for each enabled taxonomy, named after its labels.

**What are hidden terms?**
Terms you use for internal organization without showing them publicly. Turn on **Enable Term visibility options** for the taxonomy in [Settings > HyperTaxonomy > Features](plugin-settings.html#features-tab), then hide a term on its edit screen, in Quick Edit, or with the **Make Hidden** bulk action; its descendants are hidden too. For visitors who can't see hidden terms, the term's archive returns 404 and the term is left out everywhere, but posts carrying it stay findable through their other terms. Who can see them is set in **Settings > HyperTaxonomy > Display**. See [Per-Term Settings → Term Visibility](per-term-settings.html#term-visibility).

**How do I make a post sticky for specific terms?**
Edit the post, enable **[Sticky Pushpins](editing-posts.html#sticky-pushpins)** in the block editor's ··· menu → HyperTaxonomy group, then click the 📌 pushpin next to each term. The post appears at the top of those term archives on page 1 and is excluded from subsequent pages.

**Can I export term relationships to another site?**
Yes, via [Settings > HyperTaxonomy > Export / Import](export-import-migration.html#import--export). The JSON export uses term slugs for cross-site compatibility.

**Is this compatible with caching plugins?**
HyperTaxonomy keeps its own data in transients and clears them when data changes, and it has no special integration with page caching plugins. A page cache can keep showing an archive or list after you change a relationship, so purge it after making changes. Page caches can serve POST-mode search results pages, whose URLs hold the search.

## Troubleshooting

| Symptom | Solution |
|---------|---------|
| Relationships not working | Check that **Term Relationships** is enabled for the taxonomy, and look for hierarchy conflicts — terms from hierarchical taxonomies cannot include their ancestors or descendants |
| Redirect not working | Verify redirect feature is enabled for the source taxonomy and the target term exists |
| [Sticky posts](per-term-settings.html#sticky-posts) missing | Sticky posts appear only on page 1 of the term's archive. Check that **Show sticky posts on term archive pages** is on (Settings > HyperTaxonomy > Display), that the post is published, still has the term assigned directly and is of an indexed post type, that the term isn't a redirect, and that the term's sticky-post limit isn't already reached |
| Icons not displaying | Check the icon feature is enabled for the taxonomy in Settings → HyperTaxonomy → Features, and its dimensions are set in the Icons tab. The uploaded image must be at least as large as those dimensions; smaller images can't be used, larger ones open a crop dialog |
| Frontend styles missing | Settings > HyperTaxonomy > Display — make sure **Disable default frontend styles** is unchecked |
| Search widget not loading | Enable **Always load search assets** (Settings > HyperTaxonomy > Advanced) if using a page builder |
| Tag Groups migration shows "No Tag Groups Data Found" | The migration found no groups in Tag Groups' `term_groups` option in `wp_options`. Check that Tag Groups' data is in this site's database (group names come from `term_group_labels`, term assignments from `_cm_term_group_array` term meta) |
| Archive shows unexpected terms | As an administrator, append `?htax_debug=1` to the archive URL to see a debug panel (with `WP_DEBUG` on, a second panel adds the SQL query) |

**Debug logging:**

```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'HTAX_DEBUG_VERBOSE', true ); // Adds backtraces to log entries
```
