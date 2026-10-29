---
title: Term Relationships
parent: User Guide
nav_order: 3
---

## 5. Term Relationships

All relationship configuration is done on individual term edit screens — edit any tag, category, or custom taxonomy term and scroll to the HyperTaxonomy section. HyperTaxonomy also adds a top-level **Terms** menu to the admin sidebar containing **Term Groups**, **Merge Terms**, **Convert Terms**, and **Term Relationship Types**. When TermConnect is active, **Peer Sites** also appears in this menu (managed by TermConnect).

Term edit screens are protected against simultaneous editing: if another admin already has a term open, a blocking overlay appears with their name and **Go Back** / **Take Over** options. Locks expire automatically after 150 seconds of inactivity.

If you navigate away from a term edit screen with unsaved changes, the browser shows a confirmation prompt before leaving the page.

### Term Groups

**Creating groups:**

1. Go to **Terms > Term Groups**
2. Create and organize groups like categories — they support parent/child hierarchy

**Assigning terms to groups:**

- **Term edit screen** — Use the **Term Groups** checklist to assign the term to one or more groups.
- **Quick Edit** — Click Quick Edit on any term row to update group assignments inline via a scrollable checklist, without leaving the list.
- **Bulk actions** — Select multiple terms and choose **Assign to Term Groups…** to open a group selector modal. The checklist uses three states (Gmail-style): a filled dash `−` means the group is currently assigned to *some but not all* selected terms (no change on save); `✓` means add this group to all selected terms; `☐` means remove this group from all selected terms. Click a checkbox to cycle through the states. The **Apply** button activates only when at least one group has been changed from its initial state. Existing group memberships not mentioned in the modal are always preserved.

**Group archives:**

Each group has an archive page at its own URL, showing posts from all member terms across all enabled taxonomies.

**Managing group members from the group side:**

The Term Groups admin screen (**Terms > Term Groups**, then click a group to edit) shows the following fields:

- **Group Members** — terms directly assigned to this group, organized by taxonomy (Tags, Categories, custom taxonomies). Add or remove members here without editing each term individually.
- **Container For** — terms for which this group acts as a container group. Their posts appear in the archives of all group members belonging to the same taxonomy. Adding a term here is equivalent to setting this group as that term's container group on its own edit screen.
- **Included By** — terms whose archives include all posts from members of this group (same taxonomy). Adding a term here adds this group to that term's included groups.
- **Term Icon** — upload a custom icon for the group, used in group archive headings and shortcodes (requires *Enable Term Group icons* in **Settings > HyperTaxonomy > Features > Term Groups**).
- **Hidden** — hide this group from public view (requires *Enable Term Group visibility options* in **Settings > HyperTaxonomy > Features > Term Groups**). See [Group Visibility](#group-visibility) below.
- **Always Show Description** — checkbox to show the group's description below the archive heading.

When you follow a group link from a term pill tooltip, the group edit page highlights the relevant pill and, for indirect associations, shows a context panel just above the **Group Members** section. If the association is direct (the term is an immediate member, or directly included/including), a blue border is drawn around the relevant pill in the **Group Members**, **Container For**, or **Included By** field — no extra notice is needed since the relationship is already visible there. If the association is indirect (the term is in a subgroup, a parent group, or a related ancestor group), the context panel names each intermediate group as a clickable link so you can navigate directly to where the relationship is defined.

**Group Visibility**

When *Enable Term Group visibility options* is turned on in **Settings > HyperTaxonomy > Features > Term Groups**, a **Hidden** checkbox appears on each group's edit screen. Hiding a group:

- Returns a **404** to unauthorized users who visit the group's archive URL directly.
- **Excludes** the group from WordPress REST API group listings and XML sitemaps.
- **Cascades automatically** to all child groups — subgroups are hidden without requiring individual edits. Making a parent visible does not automatically un-hide its children; each must be made visible separately.
- **Does not affect archive expansion.** Posts continue to appear in term archives that include or use the group for expansion. Group membership behavior is shared across all users; only the group's own archive page is gated.

The hidden terms role threshold (**Settings > HyperTaxonomy > Display > Hidden Terms**) controls which roles can still see hidden group archives.

---

### Term Relationships

**Built-in and custom relationship types** are listed at **Terms > Term Relationship Types**. The built-in table shows the four core types (includes, excludes, redirect, member_of) and their properties. Below it, user-defined types are listed with an **Edit / Delete** link for each.

**Creating a new type.** Two buttons are shown at the bottom of the page:

- **New Inclusion-based Relationship Type** — participates in archive expansion exactly like the built-in Includes type. Source and target must be terms (or groups) from the same single taxonomy you select when creating the type. Groups on either side are expanded to their members in that taxonomy; group members from other taxonomies are ignored. Self-inclusions are rejected. The type and its taxonomy cannot be changed after creation.
- **New Custom Relationship Type** — stores the relationship only. HyperTaxonomy provides the infrastructure (term edit UI, storage, API); behavior is implemented externally by your theme or plugin. No archive expansion occurs. Source and target taxonomies are independent and optional.

Neither kind can be converted to the other after creation. The slug is also locked after first save and cannot be edited.

**Label uniqueness.** Every type — built-in and custom — must have a unique label and inverse label. The four built-in labels are: *Includes*, *Is included into*, *Excludes*, *Is excluded from*, *Redirects to*, *Target of redirect from*, *Member of*, *Contains member*. Attempting to save a custom type whose label or inverse label matches any of these (or any other existing custom type's labels) will produce a validation error.

**Groups and custom types.** Term Groups can be used on either side of inclusion-based custom relationships, just like the built-in Includes type. Storage-only custom types do not support groups, because group expansion is tied to archive query behavior which storage-only types do not implement.

**Symmetric relationships.** When creating a custom type, checking **Symmetric** means the relationship has no distinct direction — "A is Related to B" and "B is Related to A" are the same thing. For symmetric types:

- The term edit screen shows a **single unified list** instead of separate forward and reverse sections.
- Each pill shows a small direction arrow: **→** means this term added the link (from this page); **←** means the other term added it (managed from that term's edit page). You can remove links from either direction without leaving the current page.
- HyperTaxonomy prevents duplicates automatically. A term already linked in either direction will not appear in the autocomplete suggestions. If the same pair somehow ends up stored in both directions (for example after a direct database import), the duplicate is removed on plugin activation.

The term edit screen shows all relationship fields in the **Term Relationships** section (label on the left-hand sidebar of the edit form). The **Term Groups** field is separate, directly above it.

**Panel order within Term Relationships:**
The built-in panels appear in this order: **Container Tags** → **Included Tags** → **Excluded Tags**. For custom relationship types, the reverse direction (what points *to* this term) comes first, then the forward direction. This order puts the "what uses this term" perspective at the top consistently.

**Admin UI color coding by panel:**

*Term Groups panel (groups this term belongs to):*

| Color | Meaning |
|-------|---------|
| White | Groups this term is assigned to (removable) |

*Includes panel — removable pills:*

| Color | Meaning |
|-------|---------|
| Blue | Included groups |
| Gray | Directly included terms |
| Light-blue/gray gradient | Direct terms that are also members of included groups |
| Light-green/gray gradient | Direct terms that are also pulled in via container groups |

*Includes panel — non-removable pills (indirect):*

| Color | Meaning |
|-------|---------|
| Light-blue | Terms included as members of an included group (forward path) |
| Light-green | Terms included via container group relationship (reverse path) |
| Light-blue/light-green | Terms reachable via both indirect paths simultaneously |
| Tan | Terms included via an inclusion-based custom relationship type (no group path) |
| Other + tan tooltip | Terms reachable via a group path AND a custom type; tan is shown in the tooltip only |

*Containers panel — removable pills:*

| Color | Meaning |
|-------|---------|
| Gray | Direct container terms |
| Green | Container groups |
| Light-green/gray gradient | Direct containers also in this term's container groups |
| Light-blue/gray gradient | Direct containers that also include a group this term is a member of |

*Containers panel — non-removable pills (indirect):*

| Color | Meaning |
|-------|---------|
| Light-green | Terms in this term's container groups (forward path) |
| Light-blue | Terms that include a group this term is a member of (reverse path) |
| Light-blue/light-green | Terms reachable via both indirect paths simultaneously |
| Tan | Terms whose archives include this term via an inclusion-based custom relationship type (no group path) |
| Other + tan tooltip | Reachable via a group path AND a custom type; custom type shown in tooltip only |

*Symbols (all panels):*

| Symbol | Meaning |
|--------|---------|
| ⇆ | Synonym — this pill's term also includes the current term |
| ↩ | Redirects here — this pill's term redirects to the current term (informational) |
| ↪ | Redirects elsewhere — this pill's term has no standalone archive; dimmed on containers (relationship inactive), informational on includes |
| Strikethrough | Included but overridden by an exclusion rule — has no effect at runtime |

*Tooltip path indicators (shown on hover for multi-path or indirect pills):*

| Indicator | Meaning |
|-----------|---------|
| `→ via: GroupName` | Included as a member of the named group (forward group path) — click the name to open that group |
| `← via: GroupName` | Pulled in via a container group relationship (reverse group path) — click the name to open that group |
| `⇢ Type Label` | Included via the named inclusion-based custom relationship type (forward direction) — click to switch to that relationship's tab |
| `⇠ Type Label` | This term's archive is included via the named custom type from the pill's term (reverse direction) — click to switch to that relationship's tab |

Group name links open that group's edit page with context. `→ via:` links follow a membership path (the pill's term is a member of the linked group or one of its subgroups); `← via:` links follow an includes path (the linked group includes the pill's term, or the pill's term includes the linked group). On the group edit page, if the association is direct the relevant pill is highlighted with a blue border. If it is indirect — the term is in a subgroup, a parent group, or an ancestor group — a context panel just above **Group Members** names each intermediate group as a further link so you can navigate to where the relationship is defined.

#### Direct Inclusions

Posts assigned to the **included term** appear in this term's archive.

**To add an included term:** Use the **Included Terms** autocomplete field. Type to search, select a term, save.

**Constraints:**
- Inclusions are direct only, never recursive. If A includes B and B includes C, A does **not** include C.
- Terms from different flat taxonomies cannot directly include each other (cross-taxonomy is only supported for redirects and group memberships).
- Terms from hierarchical taxonomies cannot include their ancestors (parent, grandparent) — WordPress hierarchy already handles that direction; including ancestors would create infinite loops in queries.

#### Containers

A **container** is the inverse view of an inclusion. When term B lists term A as a container, B's posts appear in A's archive — exactly the same as A including B, just configured from the other direction.

Both directions produce identical results. Use whichever is more natural for your workflow:
- "What terms should appear in my archive?" → use Included Terms
- "Which archives should my posts appear in?" → use Container Terms

#### Synonyms

When two terms include each other (A includes B **and** B includes A), they become synonyms. Posts from either term appear in both archives. Synonyms are marked with the ⇆ icon on their pills in the admin interface.

**To create a synonym:** Add Term B to Term A's Included Terms, and Term A to Term B's Included Terms, then save each term.

Circular inclusion (A includes B, B includes A) is explicitly supported and useful. It differs from circular redirects, which are prevented.

#### Group Inclusions

Instead of including individual terms one by one, include an entire Term Group. All current and future members of that group are automatically included — no manual maintenance as the group grows.

**To add a group inclusion:** Use the **Included Groups** field on the term edit screen. Select one or more Term Groups.

**Example:** "Emergency Conditions" includes group "Chest Pain Causes". When a new condition is added to that group, it automatically appears in Emergency Conditions archives.

Groups are hierarchical: including a parent group includes all terms from that group and all its descendant groups.

#### Container Groups

The reverse of group inclusions. A term specifies which Term Groups it belongs to as a **container** — meaning this term's posts appear in the archives of every term that is a member of those groups.

**To add a container group:** Use the **Container Groups** field. When this term is added to a container group, its posts flow into the archive of every term that is a member of that group.

**Example:** "Pneumonia" has container groups "Chest Pain Causes" and "Fever Causes". Any term whose archive includes those groups will automatically show Pneumonia posts.

Container group terms appear with a green background in the admin interface, and the terms included as a result (via container groups) appear in light-green and cannot be removed individually — remove them by changing the term's container group membership instead.

#### Path count badge

Direct includes (editable, with × button) are listed first, sorted A–Z. Indirect includes (via groups, read-only) follow under a **via groups:** label, also sorted A–Z.

When a term is reachable via more than one path (directly included, via one or more included groups, and/or via container groups), a small **N** badge (white circle) appears on its pill. Hover anywhere on the pill to see a tooltip listing the exact paths:

- **→ via: Group A, Group B** — groups through which this term is included (from the Included Groups field)
- **← via: Group C** — groups through which a reverse container-group path brings this term in

A term with no badge has exactly one path. Remove or change group memberships to reduce path count.

#### Hiding indirect and reverse relationships

When a term belongs to large groups, the non-removable indirect pills can become unwieldy. A **Hide indirect and reverse relationships** toggle (eye icon, 👁) appears in the Included Terms and Container Terms field headers whenever group-based indirect terms are present for that specific term. Click it to switch between the two views.

- **Unchecked (default):** all indirect group-expanded pills are shown individually (current full view).
- **Checked:** individual indirect pills are hidden; each sourcing group is shown as a compact group-name pill instead — making it clear which groups are the source without listing every member term.

The state is saved per-term so terms with large groups can be collapsed while others stay expanded. The preference persists across page loads in the browser (stored locally; not tied to your account).

#### Exclusions

Exclusions remove specific terms or groups from the included set without affecting native WordPress parent/child relationships.

**Processing order (applied to term archives):**

All inclusions are gathered first, then all exclusions are applied. Exclusions always win — no inclusion source can override them.

| Phase | Step | Action |
|-------|------|--------|
| Inclusions | 1 | Expand all included groups to member terms (with descendant subgroup expansion) |
| | 2 | Add directly included terms |
| | 3 | Add terms via container groups — this term's member groups (expanded to subgroups) matched against container group relationships |
| Exclusions | 4 | Remove directly excluded terms |
| | 5 | Remove self (all taxonomies) + ancestors and descendants (hierarchical taxonomies only — prevents overlap with native WP hierarchy) |

**Example:**
```
"Modern Web Dev" includes Group "JS Frameworks" + Group "CSS Tools" + Term "TypeScript"
"Modern Web Dev" excludes Term "CoffeeScript"

Result: All JS Frameworks + CSS Tools terms + TypeScript, minus CoffeeScript
```

Exclusions never affect native WordPress parent/child hierarchy. A child category always appears in its parent's archive regardless of any exclusion settings.

**Effect on the Includes panel:** When a term appears in both the includes panel and the excludes panel, the admin interface signals the conflict:

- **Direct includes** that are also excluded are shown with strikethrough text and reduced opacity. The × button remains active so the redundant direct include can be removed. The exclusion wins at runtime regardless.
- **Indirect includes** (added via groups) that are excluded are suppressed entirely from the includes panel — they are not in the archive and showing them would be misleading. The group pill itself is unaffected; remove the exclusion to restore the indirect pill.

**To add exclusions:** Use the **Exclude Terms** field on the term edit screen.

> **Note for display shortcodes:** The `[ht_term_list]`, `[ht_term_cloud]`, `[ht_term_groups]`, and `[ht_term_index]` shortcodes have their own `exclude_groups` attribute that removes group members from the displayed term list. This is a shortcode display filter, separate from the term archive processing described above.

#### Redirects

Set a 301 redirect from this term to another term. Visitors going to this term's archive URL are redirected to the target term's archive.

**Behavior:**
- `/tag/old-name/` → 301 redirect → `/tag/new-name/`
- Posts tagged with "old-name" still appear in the target's archive
- This term has no accessible archive of its own
- Redirect terms are shown in italics in shortcodes and term lists by default

**Redirect chain resolution:** If Tag A redirects to Tag B, and you later set Tag B to redirect to Tag C, Tag A automatically updates to redirect directly to Tag C. No chains are stored.

**Circular redirect resolution:** If A→B exists and you set B→A, the plugin automatically resolves this by removing A→B and setting B→A (flipping direction), updating any other terms that pointed to B.

**Circular inclusion vs. circular redirects:**
- Circular inclusion (A includes B, B includes A) = valid synonyms
- Circular redirects (A→B→A) = invalid, automatically resolved

**To set a redirect:** Use the **Redirect To** field on the term edit screen. Select the target term and taxonomy.

**Relationships while redirecting:** All relationships set on a redirect term — inclusions, exclusions, external links (Wikidata etc.), sticky posts, and custom relationship types — are preserved in the database but have no effect on archives, search results, or `[ht_post_list]` while the redirect is active. The redirect term has no accessible archive of its own, so there is nothing to expand into. The inclusions section is not shown in the term edit screen while a redirect is active; it reappears when the redirect is removed.

For hierarchical taxonomies (categories and hierarchical custom taxonomies), posts assigned to descendants of a redirect term are also suspended from matching searches for that term or any of its ancestors, because the path runs through the redirect. This too is restored automatically when the redirect is removed.

---

