---
title: Merge & Convert Terms
parent: User Guide
nav_order: 7
---

# Merge & Convert Terms

Two operations for permanently changing a term's identity rather than just its relationships:

| Operation | What changes | Term deleted? |
|-----------|-------------|---------------|
| **Merge** | Posts and relationships move from source term(s) to a target term | Yes — source terms deleted |
| **Taxonomy conversion** | An existing term moves from one taxonomy to another | No — same term, new taxonomy |

Both operations can create **redirect stubs** so old archive URLs continue to work. They treat group memberships differently: a merge transfers them to the target, while a conversion leaves them on the redirect stub.

## Merge Terms

Permanently consolidate duplicate or outdated terms into a single canonical term. Posts, group memberships, relationships, and sticky posts from the source terms are transferred to the target, and the source terms are deleted.

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
- Inclusions, exclusions, and custom relationship types involving source terms are transferred to the target term
- Posts pinned to a source term are pinned to the target term. The target's **Maximum sticky posts** still limits how many are shown at once
- Source terms are deleted. The target keeps its own icon, description, and visibility; those of the source terms aren't transferred

**Redirect stubs (recommended):** When enabled, a new redirect term is created with each source term's name and slug, pointing to the target. Old archive URLs (e.g. `/tag/old-name/`) then 301-redirect to the target's archive, preserving SEO value and preventing broken links. If the target term is itself a redirect, stubs point directly to the chain's final destination.

**Warning:** This operation is permanent and cannot be undone. Back up your database before merging.

**Comparison with redirects:** Redirects leave both terms in place and redirect the archive URL. Merging physically reassigns all post relationships and deletes the source term. Use redirects for temporary or reversible consolidation; use merge for permanent cleanup.

## Convert Terms

**Terms > Convert Terms** moves terms from one taxonomy to another. A fresh term is created in the target taxonomy and all post assignments are moved to it. What happens to the old term depends on whether you choose to leave a redirect stub.

**How to convert:**

1. Open **Terms > Convert Terms**.
2. Choose a source taxonomy. The term list loads automatically.
3. Check the terms you want to convert. Use **Select all / Deselect all** as needed.
4. Choose the target taxonomy.
5. Optionally check **Create 301 redirect stubs in the old taxonomy** (see below).
6. Click **Convert Selected Terms**.

**Limitations before converting:**

- If a term with the same name already exists in the target taxonomy, that term is skipped with an error. Rename one of them, then convert again.
- Terms that are already redirect stubs cannot be converted — remove the redirect on the term edit screen first.
- The site's default category cannot be converted — change the default in Settings > Writing first.

**With redirect stubs (default):**

The old term stays in the old taxonomy and becomes the redirect stub — it keeps its original slug so existing archive URLs (`/category/example/`) continue to work and redirect to the new taxonomy archive. The stub keeps its group memberships, inclusion relationships, and parent-child position in the hierarchy. The new term in the target taxonomy takes the old term's hidden status and sticky posts, and its icon if both taxonomies use the same icon dimensions. It has no group memberships or relationships.

**Without redirect stubs:**

The old term is deleted and its original URL slug is assigned to the new term, giving the new taxonomy archive a clean URL. The new term takes the old term's hidden status and sticky posts, and its icon if both taxonomies use the same icon dimensions. Group memberships and relationships are lost. Old archive URLs become 404s.

**Reversible conversions (redirect case only):** Converting a term back to the original taxonomy automatically detects the redirect stub and unwinds the conversion — posts are moved back to the stub (which regains its original role as a normal term), and the replacement term is deleted.

**Slugs:** The new term's slug is auto-generated when a redirect stub is created (the stub needs the original slug for URL routing). Without a redirect stub, the new term receives the original slug.

**Integration with other tools.** HyperTaxonomy also tracks conversions made by the [Categories to Tags Converter](https://wordpress.org/plugins/wpcat2tag-importer/) and other plugins that use the standard WordPress term-creation and term-deletion hooks. For those conversions, redirect stubs, hidden status, icons, and sticky posts are handled automatically. Direct database manipulation bypasses all hooks and is not tracked.
