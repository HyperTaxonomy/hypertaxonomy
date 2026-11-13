---
title: Modeling Guide
parent: User Guide
nav_order: 16
---

# Modeling Guide
{: .no_toc }

Modeling guide for site architects and developers. Covers how to think about content relationships in HyperTaxonomy and when it is the right tool. For how it fits alongside ACF, FacetWP, Relevanssi, Elementor and other plugins, see [Working with Other Plugins](other-plugins.html).

For feature descriptions and shortcode references see the rest of the [User Guide](./). For the PHP API and integration hooks see the [Developer Reference](../developer-reference/). For a comparison with Tag Groups specifically see [Feature Comparison](../comparison.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## The Core Abstraction

The single most important concept: **HyperTaxonomy operates at the type level, not the instance level.**

A WordPress term is a concept — a class of posts. "React" is not a post; it is a label that represents a set of posts. Every relationship you define in HyperTaxonomy is a relationship between two concepts, and therefore it applies automatically and uniformly to every post in both sets — now and in the future.

```
"JavaScript" INCLUDES "React"
→ every React post appears in the JavaScript archive
→ every future React post will also appear, with no further action
```

This is the scaling advantage: define a relationship once, and it governs the entire corpus. It is also the fundamental limitation: HyperTaxonomy cannot model relationships between individual posts, because individual posts are not the unit of organization.

The practical test for any relationship you are considering:

> **Is this relationship true for ALL posts with term X and ALL posts with term Y?**
>
> If yes → use HyperTaxonomy.
> If no, or if it depends on the specific post → use post-level metadata (ACF, custom fields, post-to-post plugins).

---

## Transitive vs. Non-Transitive Inclusion

Before choosing a modeling pattern, it helps to understand the fundamental choice HyperTaxonomy makes about how inclusions chain together.

A relationship is **transitive** if A→B and B→C automatically implies A→C. WordPress's native category hierarchy is transitive: a post in a child category automatically appears in every ancestor category's archive, all the way up the tree.

HyperTaxonomy inclusions are **non-transitive**: if A includes B, and B includes C, A does **not** automatically include C. Each link must be explicitly defined.

```
"React" INCLUDES "Component Architecture"
"Component Architecture" INCLUDES "Design Patterns"

Transitive:     React archive → React + Component Architecture + Design Patterns  (broad)
Non-transitive: React archive → React + Component Architecture                    (focused)
                Component Architecture archive → Component Architecture + Design Patterns
```

**Why non-transitive?** Transitive inclusion produces unbounded cascade — one connection deep in the graph can silently pull in large, unrelated portions of the corpus. Non-transitive inclusion keeps each archive focused: you decide exactly which content is relevant in each context, independent of what other relationships exist further along the chain.

**When to use native hierarchy (transitive) instead:**
If A is genuinely a broader category and B should always be subordinate — and you want every future sub-category of A to automatically include B — use WordPress's category parent/child relationship. Native hierarchy is the right tool for universally accepted, stable organizational trees (Country → State → City, Family → Genus → Species).

**When to use HyperTaxonomy inclusions (non-transitive):**
- Relationships that are selective or contextual ("React benefits from Component Architecture context, but not everything Component Architecture relates to")
- Cross-branch connections that cut across the primary hierarchy
- Connections where the transitivity of native hierarchy would produce results that are too broad

The patterns below all use non-transitive inclusions unless noted otherwise.

---

## Modeling Patterns


### Pattern 1 — Broader / Narrower (Contextual Inclusion)

**Use when:** Term A is contextually enriched by including content from Term B, but the reverse is not true or would dilute A's focus.

```
"React" INCLUDES "Component Architecture"   ← React posts + contextual posts about architecture
"JavaScript" INCLUDES "React"               ← JavaScript posts + React posts
                                            ← JavaScript does NOT include Component Architecture
                                              (not defined; non-transitive by design)
```

Each inclusion is an editorial decision: you are saying "when someone reads about React, also showing them Component Architecture posts adds value." You are not saying "anything true of Component Architecture propagates to React."

**Depth of chain:** Because inclusions are non-transitive, you control exactly which relationships exist. A three-level chain requires three explicit inclusions. This is intentional — it prevents one broad term from inadvertently sweeping in unrelated content through a long chain of intermediate terms.

**When native hierarchy is better:** If A is genuinely a broader category and B is a narrower sub-category — and you want all future sub-categories of A to automatically include B — use WordPress's native category parent/child relationship instead. Native hierarchy is transitive and automatic. HyperTaxonomy inclusions are appropriate when the relationship is selective, contextual, or cross-branch.

---

### Pattern 2 — Synonyms (Bidirectional Inclusion)

**Use when:** Two terms name the same concept, or nearly the same concept, and content about one should always surface when browsing the other.

A synonym is simply two inclusions pointing at each other. Each term retains its independent identity — its own position in the taxonomy hierarchy, its own archive URL, its own tagging — but each archive also shows the other term's posts.

```
"United States" INCLUDES "USA"
"USA" INCLUDES "United States"
```

**Archives are not necessarily identical.** Mutual inclusion means each term's archive includes the other's posts, but each term may also have its own additional inclusions. If "United States" also includes "American Politics" but "USA" does not, the two archives will differ:

```
"United States" archive → posts tagged United States + USA + American Politics
"USA" archive           → posts tagged USA + United States  (not American Politics)
```

This is often intentional — the canonical form of a term can have a richer inclusion set than its alias. If you want truly interchangeable archives, ensure both terms have identical inclusion sets.

**Design note:** Synonyms do not need to be grammatically identical. They can be abbreviation ↔ full form, formal ↔ informal, British ↔ American spelling, or slightly overlapping concepts you want to treat as equivalent for browsing purposes.

**When NOT to use synonyms:** If the two terms genuinely differ in scope — one is broader — use a one-directional inclusion (Pattern 1) instead. A visitor browsing the narrower term should not automatically see everything in the broader term.

---

### Pattern 3 — Polyhierarchy (Multiple Perspectives)

**Use when:** A term legitimately belongs to multiple branches of a hierarchy, but WordPress's tree structure only allows one parent.

WordPress native categories allow only one parent. A post about React can be in `Frontend > React` but cannot simultaneously be in `JavaScript Ecosystem > React` without duplicating the category. HyperTaxonomy solves this at the term level rather than the post level.

**Option A — Inclusions from multiple parents:**
```
"Frontend Development" INCLUDES "React"
"JavaScript Ecosystem" INCLUDES "React"
```
React posts appear in both `Frontend Development` and `JavaScript Ecosystem` archives. React itself lives in one primary location in the tree. The relationship is maintained at the term level — no post needs to be double-tagged.

**Option B — Term Groups:**
Create a "JavaScript" group and a "Frontend" group. Add React to both. Use `[ht_post_list include_groups="..."]` or `[ht_term_list include_groups="..."]` when querying by group membership, and the native category tree for archive browsing.

**Choosing between them:** Use inclusions when the overlap needs to affect archive queries (visiting `/category/javascript-ecosystem/` should show React posts). Use Term Groups when the overlap is for editorial organization or for powering shortcode displays, without modifying what archive pages show.

---

### Pattern 4 — Dynamic Group Inclusion

**Use when:** A term should include all the content from a managed, changing set of related terms.

A direct inclusion names specific terms and is static — adding a new term to your vocabulary does not update it automatically. A group-based inclusion names a group instead: every current and future member of that group is included automatically.

```
Group: "Chest Pain Conditions" → [Angina, Myocardial Infarction, GERD, Costochondritis]

"Emergency Cardiology" INCLUDES GROUP "Chest Pain Conditions"
→ Emergency Cardiology archive shows posts from all four member terms
```

When "Pericarditis" is later added to the "Chest Pain Conditions" group, it immediately appears in the Emergency Cardiology archive — no inclusion needs to be touched. When a term is removed from the group, it stops appearing. The group is the authoritative source of the included set; the inclusion relationship just points to it.

Groups are hierarchical: including a parent group automatically includes all its descendant subgroups and their members as well.

This is the right pattern whenever the set of related terms is actively growing, curated by editorial staff, or shared across multiple including terms (several archives can each include the same group, all updating in sync).

---

### Pattern 5 — Container Groups (Reverse Inclusion)

**Use when:** A term's content is universally relevant to a defined group of terms, and that term should push its posts into each of those terms' archives rather than each one individually including it.

This is the mirror image of Pattern 4. Instead of an archive term pulling in a group's members, a term lists certain groups in its **Container Groups** field. The system then pushes that term's posts into the archive of every term that belongs to those groups.

```
Group: "Pet Species" → [Dogs, Cats, Birds, Rabbits]

"Vaccination Requirements" — Container Groups: [Pet Species]
→ Vaccination Requirements posts appear in the Dogs archive
→ Vaccination Requirements posts appear in the Cats archive
→ Vaccination Requirements posts appear in the Birds archive
→ Vaccination Requirements posts appear in the Rabbits archive
```

"Vaccination Requirements" does not include any of those terms — it is the other way around. Each pet species' archive automatically gains the vaccination content because of the container relationship. When a new species "Ferrets" is added to the "Pet Species" group, the Ferrets archive immediately gains the vaccination content too, with no changes to the container term itself.

**When to use container vs. group inclusion:** The choice is directional and depends on where the editorial ownership sits.

- Use group inclusion (Pattern 4) when you own the including term and want to pull in a dynamic set: "I manage Emergency Cardiology and want it to cover everything in Chest Pain Conditions."
- Use container groups (Pattern 5) when you own the content term and want it to push into a set of archives: "I manage Vaccination Requirements and it belongs in every pet species archive — now and in the future."

The two patterns run in opposite directions, so they don't produce the same result: Pattern 4 adds every member's posts to one term's archive, and Pattern 5 adds one term's posts to every member's archive. Choose by which archives should change.

---

### Pattern 6 — Cross-Taxonomy Alignment (Redirects)

**Use when:** The same concept exists under different taxonomy types (e.g., as both a category and a tag), and you want archives to converge.

A 301 redirect is a statement that one term IS another term — the source term is now an alias for the target, and its archive URL permanently redirects to the target's archive. HyperTaxonomy collapses redirect chains to always point directly to the ultimate target.

Typical use cases:
- **Merging duplicates:** "javascript" (tag) and "JavaScript" (tag) both exist due to inconsistent entry; redirect the less-used one to the canonical form.
- **Cross-taxonomy synonym:** A "Machine Learning" category and a "machine-learning" tag should point to the same archive.
- **Renaming a term:** The old "Big Data" tag is being replaced by "Data Engineering". Redirect Big Data → Data Engineering so old archive URLs and search engine links are preserved.

**Redirect vs. Inclusion:** A redirect completely replaces the source term's archive — there is no longer a separate browsable archive for the source. An inclusion keeps both archives alive. Use redirects when you want to consolidate; use inclusions when you want both terms to remain browsable.

---

### Pattern 7 — Faceted Attributes (Multiple Taxonomies)

**Use when:** Content has multiple independent dimensions that users browse independently.

WordPress supports multiple taxonomies per post type. HyperTaxonomy enhances each taxonomy independently with its own inclusions, redirects, groups, and hidden terms. The faceted search widget can combine filters across taxonomies in a single query.

```
Post type: Product
Taxonomies: product_category, product_brand, product_material, product_color

HyperTaxonomy per-taxonomy:
  product_category: inclusions (Summer → [Swimwear, Sunscreen, Sandals])
  product_brand:    redirects (old brand name → current name after acquisition)
  product_material: groups (Natural Fibers → [Cotton, Linen, Wool, Silk])
  product_color:    hidden terms (internal color codes invisible to customers)
```

Each taxonomy dimension is independent. A visitor can filter by brand AND material AND category simultaneously; HyperTaxonomy's search widget handles the intersection.

---

### Pattern 8 — Access-Gated Content (Hidden Terms)

**Use when:** A term is meaningful to editors or administrators but should not be surfaced to general readers.

A hidden term is invisible on all frontend output (archives, shortcodes, search results) to any role that does not have view permission. For authorized roles the term behaves exactly as a normal term — it appears in archives, shortcodes, and search results. The visibility check happens at query time, not by removing posts from the database.

```
Editorial taxonomy: editorial_status
Terms: Needs Update (hidden — visible to users who can edit posts)
       Awaiting Fact-Check (hidden)
       Tied to Annual Event (hidden)
```

Editors can browse the "Tied to Annual Event" archive to pull up every post that needs revisiting before a recurring event — a conference, a product launch cycle, a legislative deadline. Readers never see these terms in tag clouds, search results, or anywhere else on the frontend. The posts themselves are fully public; only the editorial grouping label is hidden.

Hidden terms are also useful for staging content under a provisional topic label before it goes live under a canonical term, or for tracking posts that need attention without that status leaking into the public taxonomy.

---

## Taxonomy Design Principles

### Design for concepts, not for posts

A taxonomy term is a concept that a category of content belongs to. When you create a term, ask: "Would I be comfortable saying that every post assigned to this term is about this concept?" If the answer is "only sometimes" or "it depends," that is a signal that the term is too coarse, or that some posts should be re-tagged.

Well-defined term semantics make inclusion relationships easy to reason about. Vague or mixed-purpose terms produce inclusions that feel wrong in some contexts.

### Prefer richer, more specific terms over generic catch-alls

Fewer, broader terms reduce the surface area for inclusion relationships and make archives too generic. More specific terms give HyperTaxonomy more to work with. A term like "Web Development" is so broad it includes almost everything; "React Server Components" is specific enough that an inclusion relationship from "React" has a clear meaning.

That said, you do not need a term for every concept that could ever be a tag — only for concepts that recur across multiple posts and that you want to manage explicitly.

### Plan inclusions before they grow large

A few dozen carefully chosen inclusion relationships that reflect genuine semantic connections between your core topics are far more valuable than hundreds of speculative inclusions. Each inclusion adds posts to an archive; if an inclusion is too loose, visitors arriving at that archive will find the content mix confusing.

Review inclusions periodically. As your content grows, some inclusions may become too broad. Removing an inclusion does not delete posts — it only changes which posts surface in which archives.

### Keep taxonomy count manageable

A site with 10 taxonomies per post type is hard to govern. Each new taxonomy adds potential conflicts in search and filter queries. Two to four taxonomies per post type is a practical limit for most sites. HyperTaxonomy's groups provide additional organizational structure without requiring a new taxonomy. Unlike flat metadata fields, groups are HyperTaxonomy's built-in taxonomy, with their own browsable archive pages — a group can serve as a named collection with a dedicated URL without adding to your taxonomy count.

### Do not duplicate relationships

If a term's native parent/child hierarchy already expresses a relationship, there is no need to also define an inclusion for it — HyperTaxonomy's search index already pre-computes hierarchical post sets. Define inclusions for cross-branch relationships, not for re-stating what hierarchy already handles. Inclusions stay within one taxonomy, so to connect terms in different taxonomies, use a redirect or a Term Group.

### Terms can define their own browsing context

WordPress's default archive ordering is date descending — the most recently published post appears first. This is appropriate for news and blogs, but many terms represent concepts where recency is not the right primary axis. HyperTaxonomy lets you set a default sort order and sticky posts per term, independently of every other term in the same taxonomy.

A term like `advanced-algebra` can be configured with `title:asc` ordering, so its archive reads as a structured syllabus rather than a reverse-chronological stream. A course overview or prerequisite list, published as an ordinary post, can be pinned to the top of that archive as a sticky post — it appears first regardless of its publish date or any other sort criteria.

```
Tag: advanced-algebra
  Default sort:  title:asc       (A–Z by post title — works as a reading list)
  Sticky post:   "Course Overview and Prerequisites"  (always first)

Tag: machine-learning (same taxonomy, different behavior)
  Default sort:  date:desc       (latest research and tutorials first)
  Sticky post:   none
```

The effect is that two tags in the same taxonomy can provide completely different browsing experiences, tuned to the nature of the content. Visitors can override the default sort at runtime if a sort control is present; the per-term default applies when they arrive without a sort preference.

Sticky posts remain pinned regardless of sort order — whether the term's configured default or a visitor's runtime sort selection. They are fetched and prepended separately from the main query, so the sort criteria only applies to non-sticky posts below them. Sticky posts appear on page 1 only and are excluded from subsequent pages.

This pattern also applies to categories and any custom taxonomy. A product category like `reference-manuals` sorted alphabetically serves differently from a `new-arrivals` category sorted by date. A `featured` tag with a sticky editorial intro post acts as a curated landing archive. The post type and taxonomy are unchanged; only the term's archive behavior differs.

---

## Complex Modeling Scenarios

### Knowledge Base / Technical Documentation

A knowledge base typically has a main topic taxonomy and cross-cutting attribute taxonomies (difficulty, product version, content type).

```
Taxonomy: kb_topic
  Topics: Installation, Configuration, Troubleshooting, API Reference, Performance

Taxonomy: kb_product
  Products: Community Edition, Enterprise Edition

Taxonomy: kb_content_type (flat: Tutorial, How-To, Concept, Reference)
```

**HyperTaxonomy setup:**
- "Troubleshooting" INCLUDES "Configuration" — config-related articles are contextually relevant when someone is troubleshooting
- "Enterprise Edition" INCLUDES GROUP "Enterprise Add-ons" (other `kb_product` terms) — articles about each add-on automatically appear in the Enterprise Edition archive
- "Deprecated API" redirects to "API Reference" — old API pages land in the current reference archive
- Search widget with `kb_topic` + `kb_product` + `kb_content_type` facets lets users narrow to "Enterprise Edition → How-To" in two clicks

**What HyperTaxonomy does NOT handle here:** tracking which specific article supersedes which other specific article, or modeling that a specific article applies to specific version numbers. Those are instance-level relationships — add ACF fields (`superseded_by`, `applies_to_versions`) for those.

---

### Editorial / News Site

```
Taxonomy: topic (flat tags: Climate, Economy, Politics, Technology...)
Taxonomy: region (hierarchical: World > Europe > Germany)
Taxonomy: content_format (flat: Analysis, Breaking News, Feature, Opinion)
```

**HyperTaxonomy setup:**
- "Climate" and "Economy" each INCLUDE "Climate Policy" — climate policy coverage surfaces in both archives
- "Technology" and "Politics" each INCLUDE "AI Regulation" — genuine cross-topic coverage
- "Eurozone" INCLUDES GROUP "Eurozone Members" (Germany, France, Italy, etc., all `region` terms) — as a new member state is added to the group, its coverage automatically surfaces in the Eurozone archive
- "Covid" redirects to "Public Health" — legacy archive URLs preserved
- Hidden term "Needs Legal Review" — an internal flag editors can browse. The posts stay public, so a hidden term can't hold content back: use drafts or scheduling for embargoed posts

**What HyperTaxonomy does NOT handle here:** tracking that a specific journalist wrote a specific article (that is the WordPress `author` field or an ACF relationship), or that a specific article is a follow-up to a specific earlier article (ACF field `related_article`).

---

### Academic Institution

```
Post types: course, faculty, department, research_project
Taxonomies: subject_area, research_method, degree_level
```

This scenario illustrates the type-level / instance-level boundary most clearly.

**Type-level relationships → HyperTaxonomy:**
- "Statistics" INCLUDES "Machine Learning" — courses about ML should surface in Statistics archives; the relationship holds for every ML course
- "Master's" INCLUDES "PhD" — doctoral-level content is also relevant to master's students
- GROUP "Quantitative Methods" → [Statistics, Econometrics, Machine Learning] — query posts in this group for any "methods" listing page

**Instance-level relationships → post-level metadata (ACF/CPT UI):**
- Professor Smith teaches Course 101 ← an individual faculty post is related to a specific course post
- Course 101 requires Course 050 as a prerequisite ← a specific course-to-course relationship
- Research Project X is led by Professor Y ← specific post-to-post link

The correct architecture is layered: HyperTaxonomy governs the semantic structure of subjects and methods across the curriculum, while ACF post-to-post relationships govern specific faculty/course/project connections. They operate on different dimensions of the same content and do not conflict.

A course archive page can combine both: HyperTaxonomy-expanded `[ht_post_list]` for "courses in the same subject area," and a custom Elementor Loop block reading ACF's `related_courses` field for "courses that list this one as a prerequisite."

---

### E-commerce Product Catalog

```
Post type: product (or WooCommerce product)
Taxonomies: product_category, product_brand, product_material, product_color
```

**HyperTaxonomy setup:**
- GROUP "Natural Fibers" → [Cotton, Linen, Wool, Silk, Hemp] — all natural-fiber products queryable as a group; new materials added to the group automatically appear
- "Swimwear" INCLUDES GROUP "Summer Essentials" — swimwear archive also shows sunscreen, sandals, and hats without each needing to be cross-tagged
- "Acme Corp" redirects to "Acme (acquired)" — brand archive preserved after acquisition/rename
- Hidden material terms for internal codes not surfaced to customers

**What HyperTaxonomy does NOT handle:** product variant relationships (Size M of this product is related to Size L), bundle relationships (Product A is sold together with Product B), or cross-sell/upsell links between specific products. Those require WooCommerce's own relationship system or ACF fields.
