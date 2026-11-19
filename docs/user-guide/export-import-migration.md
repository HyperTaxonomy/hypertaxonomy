---
title: Export, Import & Migration
parent: User Guide
nav_order: 14
---

# Export, Import & Migration
{: .no_toc }

Back up HyperTaxonomy's data or move it to another site as a JSON file, and migrate groups from Tag Groups.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Import & Export

**[Settings&nbsp;→ HyperTaxonomy&nbsp;→ Export / Import](plugin-settings.html#export--import-tab)** has four subtabs: **Export**, **Import** (HyperTaxonomy JSON), **Migrate** (Tag Groups import), and **Sync** (how HyperTaxonomy stays in step with Tag Groups afterward).

### Export

1. Select which taxonomies to include
2. Optionally check **Include term-to-post assignments** (a WordPress export already covers these)
3. Click **Download Export File**

The JSON file contains the terms, their [relationships](term-relationships.html), redirects, [group memberships](term-groups.html), and term settings for the selected taxonomies. Terms and posts are identified by slug for cross-site compatibility.

### Import

1. Under **Select Export File**, choose your export file
2. Choose your options:
   - **Update existing terms** (recommended) updates terms that already exist with the file's data
   - **Import term-to-post assignments** applies them, if the file includes them
3. Click **Preview Import** and review what will be imported
4. Click **Start Import**

**What transfers:** terms and their hierarchy (in any order in the file; parents are ignored on flat taxonomies), inclusion relationships, exclusion relationships, redirects, group memberships, custom relationship types with their relationships, and term settings: visibility, show description, posts per page, maximum sticky posts, and sort order.

**What depends on the destination site:**

- **Sticky posts** are matched by post slug. A post with the same slug must already exist there; otherwise that pin is skipped. Import your posts first.
- **Term icons** aren't copied. The file only records each icon's URL, and the import links the term to a media library image with the same filename, so IDs don't need to match and the image's address on the old site doesn't matter. To keep icons on another site, get the images into its media library first, for example with the WordPress exporter and importer, and keep their filenames. A file renamed on the way in, such as `php-1.png`, won't match. If there's no match, the term is imported without its icon, with no warning. If a different image on that site has the same filename, the term is linked to that image instead. Check the icons after importing.
- **Term-to-post assignments** are also matched by post slug.

---

## Migrating from Tag Groups

Tag Groups was rebranded "PublishPress Tag Groups" in October 2026 (same plugin, same database format) — this section uses "Tag Groups" throughout, and applies equally to installs running either name.

The importer copies Tag Groups data into HyperTaxonomy's [Term Groups](term-groups.html). You can run it as often as you like: it tracks which Tag Groups group each HyperTaxonomy group came from, so later runs pick up renames and, depending on the mode you choose, removals, instead of creating duplicates.

### What Gets Imported

| Tag Groups | HyperTaxonomy |
|------------|---------------|
| Groups (including Pro's two-level parent/child structure, where present) | Term Groups, with native parent/child seeded to match **once**, when a group is first created or matched — see note below |
| Group names | Preserved — a rename in Tag Groups is picked up on the next import run |
| Term-to-group assignments | Transferred, for any taxonomy with Group Assignment enabled (not limited to Tags/Categories) |

**Hierarchy is a one-time starting point, not an ongoing sync.** HyperTaxonomy's Term Groups support unlimited nesting depth; Tag Groups Pro's parent-group feature is capped at two levels. Re-applying Tag Groups' structure on every run would mean any deeper organization you build in HyperTaxonomy keeps getting flattened back to Tag Groups' two-level limit — so once a group's parent is set from Tag Groups data, HyperTaxonomy never touches that field again on later runs. Reorganize it however you like in HyperTaxonomy afterward; nothing will revert it.

### Before You Import

Enable Group Assignment for each taxonomy you want to import:

1. **Settings&nbsp;→ HyperTaxonomy&nbsp;→ Features**
2. Enable **Group Assignment** for each taxonomy whose group assignments you want imported (not limited to Tags and Categories)
3. Save settings

### Import Steps

1. **Settings&nbsp;→ HyperTaxonomy&nbsp;→ Export / Import&nbsp;→ Migrate**
2. Review the summary (groups and term counts found, and — on a re-run — how many are new since your last import)
3. Click **Migrate Tag Groups Now**
4. Watch real-time progress
5. Review final statistics
6. Click **View Term Groups**

Each run asks how removals are handled (see [Mirror vs. Keep](#mirror-vs-keep) below). Ongoing sync behavior is set on the **Sync** subtab (see [Mapped Group Editing and Continuous Sync](#mapped-group-editing-and-continuous-sync) below).

### Mirror vs. Keep

On a re-run, a term may have lost a group assignment on the Tag Groups side since your last import. The import modal asks which of two modes to use for that run. It defaults to the mode continuous sync uses if that is on, otherwise Keep:

- **Mirror.** Makes a term's Tag-Groups-sourced group memberships match Tag Groups' current state exactly, including removals.
- **Keep (default, recommended).** Never removes a Tag-Groups-sourced assignment, even if Tag Groups no longer reports it.

Either way, **any group membership Tag Groups has no knowledge of is always preserved** — this choice only ever affects assignments that originally came from Tag Groups. If you've used HyperTaxonomy's own Term Groups UI to assign a term to a group independently of Tag Groups, re-running the importer (in either mode) never touches that assignment.

### Mapped Group Editing and Continuous Sync

On the **Sync** subtab, choose how mapped groups are treated, then save with **Save Sync Settings**:

- **Independent (default).** Group names and membership stay fully editable in HyperTaxonomy. However, if they were migrated from Tag Groups, or if a group with the same name exists there, a subsequent migration can overwrite edits made here. The migration screen warns you when this applies.
- **Locked.** Recommended for admins who plan to use Tag Groups as their primary group management tool. Only mapped groups' names and term membership are locked in HyperTaxonomy, so edits made here are not lost during a later migration. Unmapped groups (created manually or imported rather than migrated, with names that do not clash with migrated groups) are not locked and are not affected by future migrations. Changes made in Tag Groups are pulled in only when you run the migration.

Tag Groups Pro parent group hierarchy is not kept in sync. It is applied once, when a group is first migrated, and changes made in Tag Groups afterward are not carried over.

Under **Locked**, a separate **Sync continuously** checkbox applies Tag Groups changes as they happen, without re-running the migration. The checkbox is unavailable under Independent. It can be turned on before Tag Groups is activated; nothing syncs until it is. Enabling it runs one full resync first, to catch up on anything that changed while it was off.

### Locked Fields on Mapped Groups

In Locked mode, a group imported from Tag Groups shows a lock icon (in the group list table, and on pills/checklist items across HyperTaxonomy's admin screens) and can't have its **name** or Tag-Groups-managed **membership** changed through HyperTaxonomy's own UI — the next sync reasserts whatever Tag Groups currently reports, so an edit made here would just be silently discarded otherwise. Make those changes in Tag Groups instead. Everything else about a mapped group — hierarchy (after its one-time seed, see above), inclusion/reverse-inclusion relationships, and HyperTaxonomy-only group memberships on other taxonomies — is fully editable in HyperTaxonomy as normal.

Deleting a mapped group isn't blocked, but its own edit screen notes what happens: the next migration recreates it with its Tag Groups members, and any HyperTaxonomy-only members and relationships on it are lost.

When Locked mode is selected and Tag Groups is active, a term's edit screen swaps which group field is shown up front: Tag Groups' own field (the one that actually changes Tag-Groups-managed membership) takes the spot HyperTaxonomy's Term Groups selector normally occupies, and the read-only Term Groups selector moves into the "More options" section instead. With Independent mode, or when Tag Groups isn't active, HyperTaxonomy's own selector stays up front as usual.

### Import Statistics

The summary at the end of a migration reports:

| Shown as | Meaning |
|----------|---------|
| *Created N groups* | New Term Groups created |
| *N already existed* | Pre-existing groups matched and left as-is |
| *N renamed* | Existing Term Groups renamed to match a Tag Groups label change |
| *Hierarchy applied to N* | Parent/child relationships seeded from Tag Groups Pro's parent-group data, for newly created or newly matched groups only (see the note above) |
| *Group mappings cleared (deleted in Tag Groups): N* | The Tag Groups group was deleted since your last import, so its mapping was cleared — the HyperTaxonomy group itself is never deleted, only its link back to Tag Groups |
| *N assigned, N skipped* | Term-to-group assignments created or changed; skipped ones already matched, or their taxonomy has Group Assignment off |
| *N terms could not be updated*, *Groups that could not be created* | Failed operations, with the groups listed; normally absent |

It also lists groups that were matched to an existing HyperTaxonomy group by name, and groups renamed to tell apart ones that share a label (see below).

### Troubleshooting Import Issues

**"No Tag Groups Data Found"**
The `term_groups` option is empty or missing. Verify Tag Groups plugin was used and groups were created. Check that `term_groups` and `term_group_labels` exist in `wp_options`.

**Terms skipped, or a taxonomy's assignments missing**
Terms in a taxonomy with Group Assignment off are counted as skipped, with no separate message. Enable Group Assignment for the taxonomy in **Settings&nbsp;→ HyperTaxonomy&nbsp;→ Features**, save, then re-run the migration.

**Import times out**
The migration runs in small batches, so a single large site rarely hits a limit. If a batch does time out, increase PHP `max_execution_time` in php.ini and run the migration again; it picks up where the data stands.

**Duplicate groups created**
Only possible on the *first* import of a given group — the name didn't match any existing HyperTaxonomy group exactly. On every run after that, groups are matched by their tracked Tag Groups origin, not by name, so a later rename in Tag Groups updates the existing group instead of creating a duplicate. Delete the duplicate manually if it happens on first import.

**Group named with a parent or number**
Tag Groups Pro allows the same label under different parent groups, but each HyperTaxonomy group maps to one Tag Groups group. When a label is already used by a different mapped group, the new one is named with its parent's label in brackets (for example, *Other (Region A)*), or with a number (*Other (2)*) if it has no parent. The migration summary lists these names, and the name is kept on later runs.

**Migrating to a different site?** If you move HyperTaxonomy data to another WordPress install that also runs Tag Groups on its own (through a database export, a staging-to-production clone, or a site-migration plugin), the two sites won't confuse each other's groups: the importer tracks each group's origin per site. On the new site, a group whose origin isn't recognized is matched by name on the next import run, as in a first-time import.

### After Import

**Verify:**
1. **Terms&nbsp;→ Term Groups** — check all expected groups are present
2. Click into groups to verify term assignments
3. Test frontend with `[ht_term_groups]` shortcode

**Update templates** if your theme used Tag Groups functions. These class names are unchanged under the PublishPress Tag Groups rebrand, so the "old" code below still runs — but once you've migrated, replace it with HyperTaxonomy's equivalents rather than keeping Tag Groups active just for template calls:

```php
// Old (Tag Groups / PublishPress Tag Groups)
$groups = (new TagGroups_Term(123))->get_groups();
$terms = (new TagGroups_Group(5))->get_group_terms();

// New (HyperTaxonomy)
$groups = htax_get_term_groups(123);
$terms = htax_get_terms_in_groups([5], 'post_tag');
```

**Cleanup** (only when confident everything works):

1. Keep Tag Groups active temporarily to verify
2. Test all pages, widgets, and shortcodes
3. Back up the database
4. Deactivate Tag Groups
5. Delete plugin if no longer needed

Tag Groups data in `wp_options` and `wp_termmeta` is never touched and can coexist indefinitely.

### Limitations

- Group Assignment must be enabled per taxonomy before importing
- Group display order/position is not imported — HyperTaxonomy's Term Groups don't currently have an equivalent custom-order concept to import it into
- Tag Groups Free allows only one group per term; a term's group memberships on a Free install reflect that same limit after import, which is a faithful mirror of what Free itself stores, not an import restriction
