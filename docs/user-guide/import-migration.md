---
title: Import & Migration
parent: User Guide
nav_order: 9
---

## 12. Import & Export

**Settings > HyperTaxonomy > Export / Import** has four subtabs: **Export**, **Import** (HyperTaxonomy JSON), **Migrate** (Tag Groups import), and **Sync** (how HyperTaxonomy stays in step with Tag Groups afterward).

### Export

1. Select which taxonomies to include
2. Click **Download Export File**
3. A JSON file downloads containing all relationships, redirects, and group memberships for the selected taxonomies

Terms are identified by slug for cross-site compatibility.

### Import

1. Click **Upload JSON File** and select your export file
2. Review the preview showing what will be imported
3. Click **Import**

What transfers: inclusion relationships, exclusion relationships, redirects, group memberships.

What does not transfer: term icons, sticky post assignments, per-term settings (show description, posts per page override).

---

## 13. Migrating from Tag Groups

Tag Groups was rebranded "PublishPress Tag Groups" in October 2026 (same plugin, same database format) — this section uses "Tag Groups" throughout, and applies equally to installs running either name.

The importer transfers Tag Groups plugin data into HyperTaxonomy's Term Groups system, and is designed to be re-run as needed rather than used only once — it tracks each group's Tag Groups origin internally, so repeated runs correctly pick up renames and (depending on the mode you choose) removals, instead of just piling up duplicates.

### What Gets Imported

| Tag Groups | HyperTaxonomy |
|------------|---------------|
| Groups (including Pro's two-level parent/child structure, where present) | Term Groups, with native parent/child seeded to match **once**, when a group is first created or matched — see note below |
| Group names | Preserved — a rename in Tag Groups is picked up on the next import run |
| Term-to-group assignments | Transferred, for any taxonomy with Group Assignment enabled (not limited to Tags/Categories) |

**Hierarchy is a one-time starting point, not an ongoing sync.** HyperTaxonomy's Term Groups support unlimited nesting depth; Tag Groups Pro's parent-group feature is capped at two levels. Re-applying Tag Groups' structure on every run would mean any deeper organization you build in HyperTaxonomy keeps getting flattened back to Tag Groups' two-level limit — so once a group's parent is set from Tag Groups data, HyperTaxonomy never touches that field again on later runs. Reorganize it however you like in HyperTaxonomy afterward; nothing will revert it.

### Before You Import

Enable Group Assignment for each taxonomy you want to import:

1. **Settings > HyperTaxonomy > Features**
2. Enable **Group Assignment** for each taxonomy whose group assignments you want imported (not limited to Tags and Categories)
3. Save settings

### Import Steps

1. **Settings > HyperTaxonomy > Export / Import > Migrate**
2. Review the summary (groups and term counts found, and — on a re-run — how many are new since your last import)
3. Click **Migrate Tag Groups Now**
4. Watch real-time progress
5. Review final statistics
6. Click **View Term Groups**

Each run asks how removals are handled (see "Mirror vs. Keep" below). Ongoing sync behavior is set on the **Sync** subtab (see "Mapped Group Editing and Continuous Sync" below).

### Mirror vs. Keep

On a re-run, a term may have lost a group assignment on the Tag Groups side since your last import. The import modal asks which of two modes to use for that run. It defaults to the mode continuous sync uses if that is on, otherwise Keep:

- **Mirror.** Makes a term's Tag-Groups-sourced group memberships match Tag Groups' current state exactly, including removals.
- **Keep (default, recommended).** Never removes a Tag-Groups-sourced assignment, even if Tag Groups no longer reports it.

Either way, **any group membership Tag Groups has no knowledge of is always preserved** — this choice only ever affects assignments that originally came from Tag Groups. If you've used HyperTaxonomy's own Term Groups UI to assign a term to a group independently of Tag Groups, re-running the importer (in either mode) never touches that assignment.

### Mapped Group Editing and Continuous Sync

On the **Sync** subtab, choose how mapped groups are treated, then save with **Save Sync Settings**:

Tag Groups Pro parent group hierarchy is not kept in sync. It is applied once, when a group is first migrated, and changes made in Tag Groups afterward are not carried over.

- **Independent (default).** Group names and membership stay fully editable in HyperTaxonomy. However, if they were migrated from Tag Groups, or if a group with the same name exists there, a subsequent migration can overwrite edits made here. The migration screen warns you when this applies.
- **Locked.** Recommended for admins who plan to use Tag Groups as their primary group management tool. Only mapped groups' names and term membership are locked in HyperTaxonomy, so edits made here are not lost during a later migration. Unmapped groups (created manually or imported rather than migrated, with names that do not clash with migrated groups) are not locked and are not affected by future migrations. Changes made in Tag Groups are pulled in only when you run the migration.

Under **Locked**, a separate **Sync continuously** checkbox applies Tag Groups changes as they happen, without re-running the migration. The checkbox is unavailable under Independent. It can be turned on before Tag Groups is activated; nothing syncs until it is. Enabling it runs one full resync first, to catch up on anything that changed while it was off.

### Locked Fields on Mapped Groups

In Locked mode, a group imported from Tag Groups shows a lock icon (in the group list table, and on pills/checklist items across HT's admin screens) and can't have its **name** or Tag-Groups-managed **membership** changed through HyperTaxonomy's own UI — the next sync reasserts whatever Tag Groups currently reports, so an edit made here would just be silently discarded otherwise. Make those changes in Tag Groups instead. Everything else about a mapped group — hierarchy (after its one-time seed, see above), inclusion/reverse-inclusion relationships, and HT-only group memberships on other taxonomies — is fully editable in HT as normal.

Deleting a mapped group isn't blocked, but its own edit screen notes what happens: the next migration recreates it with its Tag Groups members, and any HyperTaxonomy-only members and relationships on it are lost.

When Locked mode is selected and Tag Groups is active, a term's edit screen swaps which group field is shown up front: Tag Groups' own field (the one that actually changes Tag-Groups-managed membership) takes the spot HT's Term Groups selector normally occupies, and the read-only Term Groups selector moves into the "More options" section instead. With Independent mode, or when Tag Groups isn't active, HT's own selector stays up front as usual.

### Import Statistics

| Statistic | Meaning |
|-----------|---------|
| Groups Created | New Term Groups created |
| Groups Already Existing | Pre-existing groups matched and left as-is |
| Groups Renamed | Existing Term Groups renamed to match a Tag Groups label change |
| Hierarchy Applied | Parent/child relationships seeded from Tag Groups Pro's parent-group data, for newly-created or newly-matched groups only (see note below) |
| Groups Orphaned | Group mappings cleared because the Tag Groups group was deleted since your last import — the HT group itself is never deleted, only its link back to Tag Groups |
| Terms Assigned | Term-to-group assignments created or changed |
| Terms Skipped | Already matched the target state, or feature disabled for that taxonomy |
| Errors | Failed operations (should be 0) |

### Troubleshooting Import Issues

**"No Tag Groups Data Found"**
The `term_groups` option is empty or missing. Verify Tag Groups plugin was used and groups were created. Check that `term_groups` and `term_group_labels` exist in `wp_options`.

**"Group Assignment feature not enabled"**
Enable Group Assignment for the relevant taxonomy in Settings > Features, save, then re-run the migration.

**Import times out**
Increase PHP `max_execution_time` in php.ini.

**Duplicate groups created**
Only possible on the *first* import of a given group — the name didn't match any existing HT group exactly. On every run after that, groups are matched by their tracked Tag Groups origin, not by name, so a later rename in Tag Groups updates the existing group instead of creating a duplicate. Delete the duplicate manually if it happens on first import.

**Group named with a parent or number**
Tag Groups Pro allows the same label under different parent groups, but each HyperTaxonomy group maps to one Tag Groups group. When a label is already used by a different mapped group, the new one is named with its parent's label in brackets (for example, *Other (Region A)*), or with a number (*Other (2)*) if it has no parent. The migration summary lists these names, and the name is kept on later runs.

**Migrating to a different site?** If you move HyperTaxonomy data to another WordPress install (database export/import, a staging→production clone, a site-migration plugin) that also runs Tag Groups independently, the importer's internal origin-tracking is scoped per site specifically so it can't confuse the two installs' groups with each other — a group that doesn't recognize itself as local falls back to matching by name on that site's next import run, same as a first-time import.

### After Import

**Verify:**
1. **Terms > Term Groups** — check all expected groups are present
2. Click into groups to verify term assignments
3. Test frontend with `[ht_term_groups]` shortcode

**Update templates** if your theme used Tag Groups functions. These class names are unchanged under the PublishPress Tag Groups rebrand, so the "old" code below still runs — but once you've migrated, replace it with HyperTaxonomy's equivalents rather than keeping Tag Groups active just for template calls:

```php
// Old (Tag Groups / PublishPress Tag Groups)
$groups = (new TagGroups_Term(123))->get_groups();
$terms = (new TagGroups_Group(5))->get_all_term_ids_in_group();

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
- Group display order/position is not imported — HT's Term Groups don't currently have an equivalent custom-order concept to import it into
- Tag Groups Free allows only one group per term; a term's group memberships on a Free install reflect that same limit after import, which is a faithful mirror of what Free itself stores, not an import restriction

---

