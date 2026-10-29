---
title: Best Practices & Modeling
parent: User Guide
nav_order: 11
---

# HyperTaxonomy Best Practices

Modeling guide for site architects and developers. Covers how to think about content relationships in HyperTaxonomy, when it is the right tool, and how it fits alongside ACF, FacetWP, Relevanssi, Elementor, and custom solutions.

For feature descriptions and shortcode references see the rest of the [User Guide](index.html). For the PHP API and integration hooks see the [Developer Reference](../developer-reference/). For a comparison with Tag Groups specifically see [vs. Tag Groups](../comparison.html).

---

## 1. The Core Abstraction

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

## 2. Transitive vs. Non-Transitive Inclusion

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
If A is genuinely a broader category and B should always be subordinate — and you want every future sub-category of A to automatically include B — use WordPress's category parent/child relationship. Native hierarchy is the right tool for universally accepted, stable organizational trees (Country → State → City, Species → Genus → Family).

**When to use HyperTaxonomy inclusions (non-transitive):**
- Relationships that are selective or contextual ("React benefits from Component Architecture context, but not everything Component Architecture relates to")
- Cross-branch connections that cut across the primary hierarchy
- Connections where the transitivity of native hierarchy would produce results that are too broad

The patterns below all use non-transitive inclusions unless noted otherwise.

---

## 3. Modeling Patterns


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

Both patterns produce the same query result. Use whichever framing matches the natural editorial workflow for your content.

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
Terms: Needs Update (hidden — visible to editors and admins only)
       Awaiting Fact-Check (hidden)
       Tied to Annual Event (hidden)
```

Editors can browse the "Tied to Annual Event" archive to pull up every post that needs revisiting before a recurring event — a conference, a product launch cycle, a legislative deadline. Readers never see these terms in tag clouds, search results, or anywhere else on the frontend. The posts themselves are fully public; only the editorial grouping label is hidden.

Hidden terms are also useful for staging content under a provisional topic label before it goes live under a canonical term, or for tracking posts that need attention without that status leaking into the public taxonomy.

---

## 4. Taxonomy Design Principles

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

A site with 10 taxonomies per post type is hard to govern. Each new taxonomy adds potential conflicts in search and filter queries. Two to four taxonomies per post type is a practical limit for most sites. HyperTaxonomy's groups provide additional organizational structure without requiring a new taxonomy. Unlike flat metadata fields, groups are a built-in WordPress taxonomy with their own browsable archive pages — a group can serve as a named collection with a dedicated URL without adding to your taxonomy count.

### Do not duplicate relationships

If a term's native parent/child hierarchy already expresses a relationship, there is no need to also define an inclusion for it — HyperTaxonomy's search index already pre-computes hierarchical post sets. Define inclusions for cross-branch and cross-taxonomy relationships, not for re-stating what hierarchy already handles.

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

## 5. Complex Modeling Scenarios

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
- "Enterprise Edition" INCLUDES GROUP "Enterprise Features" — all articles tagged with enterprise-specific feature terms automatically appear in the Enterprise Edition archive
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
- "Climate Policy" INCLUDES "Climate" and "Economy" — climate policy coverage surfaces in both archives
- "AI Regulation" INCLUDES "Technology" and "Politics" — genuine cross-topic coverage
- "European Economy" INCLUDES GROUP "Eurozone Members" (Germany, France, Italy, etc.) — as coverage of new member-states is tagged, it automatically surfaces in the European Economy archive
- "Covid" redirects to "Public Health" — legacy archive URLs preserved
- Hidden term "EMBARGOED" — assigned to posts that should not yet be visible to the public; removed at publish time

**What HyperTaxonomy does NOT handle here:** tracking that a specific journalist wrote a specific article (that is the WordPress `author` field or an ACF relationship), or that a specific article is a follow-up to a specific earlier article (ACF field `related_article`).

---

### Academic Institution

```
Post types: course, faculty, department, research_project
Taxonomies: subject_area, research_method, degree_level
```

This scenario illustrates the type-level / instance-level boundary most clearly.

**Type-level relationships → HyperTaxonomy:**
- "Machine Learning" INCLUDES "Statistics" — courses about ML should surface in Statistics archives; the relationship holds for every ML course
- "PhD" INCLUDES "Master's" — doctoral-level content is also relevant to master's students
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

---

## 6. Working Alongside Other Plugins

HyperTaxonomy operates at the vocabulary level — it defines what taxonomy terms mean in relation to each other. The plugins in this section operate at other levels: post structure, custom fields, page building, e-commerce, content automation, and search. Because they address different problems, they are genuinely complementary.

### The Core Gap in Content-Modeling Frameworks

CPT UI, ACF, Pods, and JetEngine are content-modeling frameworks: they define post types, custom fields, and post-to-post relationships. They all share the same limitation at the vocabulary level. They answer questions like:

- What fields does a *Faculty* post have?
- Which specific *Courses* does *Professor Smith* teach?
- What *Projects* is *Dr. Lee* associated with?

None of them answer a different class of question:

- What does the *subject area* "Machine Learning" *mean* in relation to the subject area "Statistics"?
- Should a visitor browsing the "JavaScript" tag archive automatically see React, Vue, and TypeScript content — without any individual post being double-tagged?
- What archive URL should the deprecated "Big Data" tag redirect to?

These are questions about the *vocabulary* — the taxonomy terms themselves — not about individual posts. HyperTaxonomy is purpose-built for this layer. The frameworks below have no mechanism for it; HyperTaxonomy provides it.

---

### CPT UI

**What it does:** Registers custom post types and taxonomies through an admin UI. No code required.

**Limitation:** CPT UI creates vanilla WordPress taxonomies. Once a taxonomy exists, terms in it are flat, unconnected labels. "Algorithms" and "Machine Learning" are both terms in `subject_area`, but the system has no knowledge that they are related, or that browsing "Computer Science" should encompass both.

**How HyperTaxonomy fills the gap:** Everything HyperTaxonomy provides — inclusions, groups, redirects, term icons, per-term archive settings, sticky posts, hidden terms, the full-text search index — works directly on any taxonomy CPT UI registers. No configuration is needed; HyperTaxonomy discovers all registered taxonomies automatically.

**Combined, no-code:** CPT UI defines the structure; HyperTaxonomy defines the meaning. A site architect can define a `subject_area` taxonomy in CPT UI, then build a semantic graph of inclusions, groups, and redirects in HyperTaxonomy's term edit screens — all without writing a line of PHP. The Post List block makes it possible to surface those relationships in any page or template, also without coding.

---

### ACF (Advanced Custom Fields)

**What it does:** Defines custom fields for posts, taxonomy terms, and users. The Relationship field stores arrays of related post IDs and renders them as selectable lists on the post edit screen.

**Limitations:**

1. **Post-to-post relationships are instance-level.** Adding a React post to the "Related articles" ACF field on a JavaScript post does not make that React post appear in the JavaScript taxonomy archive, and does not affect any other JavaScript post. The relationship exists only for that one pair.

2. **Maintenance cost scales with content volume.** A site with 500 posts about JavaScript frameworks would require 500 post edit screens to be visited and manually updated to maintain ACF relationship fields between them. New posts are not related automatically.

3. **ACF relationships are not relevance-scored.** All linked posts are equal. There is no way to surface "posts that share more terms" above "posts that share one term."

4. **No effect on taxonomy archive pages.** A visitor browsing `/tag/javascript/` sees only posts directly tagged JavaScript, regardless of how many ACF relationships exist among those posts.

**How HyperTaxonomy fills the gap:** Term inclusions are defined once at the vocabulary level and apply to every current and future post without maintenance. Visiting `/tag/javascript/` shows React, Vue, and TypeScript content automatically. The Related Posts block on any single post page surfaces content related via shared taxonomy terms, relevance-scored, with no field to maintain.

**The two tools are genuinely complementary.** Use ACF for what it is uniquely good at — instance-specific facts (Professor Smith's office number, Course 101's credit count, Product SKU-A's dimensions) and editorially curated, intentional post-to-post links ("This article was followed up by..."). Use HyperTaxonomy for the semantic structure that scales automatically.

**No-code display:** ACF data can be displayed in Elementor via Dynamic Tags or in the block editor via ACF's native blocks. HyperTaxonomy data is displayed via the Post List block and Related Posts block. Both are configured without coding.

**Stack responsibilities (CPT UI + ACF + HyperTaxonomy):**

| Layer | Tool | Responsible for |
|-------|------|----------------|
| Post type & taxonomy registration | CPT UI | Define post types and taxonomies with no code |
| Semantic vocabulary | HyperTaxonomy | Inclusions, groups, redirects, per-term settings |
| Instance data & relationships | ACF | Post-specific fields, editorially curated post-to-post links |
| Query expansion | HyperTaxonomy via `pre_get_posts` | Taxonomy queries include semantically related terms |
| Display — taxonomy content | Post List block / Related Posts block | No-code block editor configuration |
| Display — ACF content | ACF blocks / Elementor Dynamic Tags | Post-specific field display |
| Search & filter UI | HyperTaxonomy Search Widget | Faceted search across taxonomies |

**Practical rule:** If you are creating a relationship that would be true for every future post in one of the involved categories, define it in HyperTaxonomy. If you are creating a relationship that is specific to one particular post, define it in ACF.

---

### Pods

**What it does:** A full relational data modeling framework. Defines custom post types, taxonomies, and fields with a schema-like approach. Supports custom database tables. The Relationships feature creates typed, bidirectional connections between post types (e.g., `Faculty.teaches ↔ Course.taught_by`).

**Limitations:**

1. **Relationships are still post-to-post.** The schema says "a Faculty can teach many Courses" — but each individual faculty post must still be linked to each individual course post through the Pods interface. The schema defines the structure; someone must still populate every instance.

2. **Taxonomy terms are not enriched.** Pods has no mechanism for "the subject area 'Data Science' includes the subject areas 'Statistics', 'Machine Learning', and 'Database Systems'." Terms in a Pods-registered taxonomy are as unconnected as in any other WordPress taxonomy.

3. **Pods relationship queries bypass taxonomy archives.** A Pods query for "all courses linked to Professor X" is a custom lookup, not a standard taxonomy archive. It does not produce a URL at `/subject/data-science/` that includes related subject matter.

**How HyperTaxonomy fills the gap:** HyperTaxonomy handles the vocabulary layer that Pods does not model. Pods governs how individual faculty, courses, and projects are linked to each other. HyperTaxonomy governs how the subject area taxonomy is semantically structured — which subjects include which others, how the group hierarchy is organized, what gets surfaced in a subject archive.

**Combined architecture example (academic site):**
- Pods: defines Faculty, Course, Department post types; Faculty.teaches ↔ Course schema
- CPT UI (or Pods): registers `subject_area` taxonomy
- HyperTaxonomy: "Computer Science includes Algorithms, Data Structures, Machine Learning"; GROUP "STEM Subjects" → [Computer Science, Mathematics, Physics]
- A subject archive at `/subject/computer-science/` shows all post types tagged with Computer Science or any included subject — powered by HyperTaxonomy
- A course's sidebar shows "taught by Professor Y" — powered by Pods
- A faculty profile page shows "related courses in the same subject area" — powered by HyperTaxonomy's Related Posts block

---

### JetEngine

**What it does:** The most comprehensive content-modeling toolkit in the WordPress ecosystem. Custom post types, fields, query builder, Smart Filters (faceted filtering), Dynamic Listings (Elementor-based loop templates), and the Relations module — which creates fully managed many-to-many relationships between post types with editable metadata on each relationship instance ("Professor Smith teaches Course 101 as Lead Instructor, starting Fall 2023").

**Limitations:**

1. **Relations are still post-to-post.** Despite being the most powerful instance-level relationship system in WordPress, JetEngine Relations cannot express "every post in the Machine Learning subject area is contextually related to every post in the Statistics subject area." That relationship belongs to the vocabulary layer, not the instance layer.

2. **Smart Filters operate on the result set, not the vocabulary.** A JetEngine Smart Filter showing a "Subject Area" checkbox list will let users narrow to a specific subject — but clicking "Machine Learning" only returns posts directly tagged with Machine Learning. Posts tagged with Statistics or Data Engineering that HyperTaxonomy would include are not surfaced unless those subjects are also selected.

3. **Dynamic Listings use secondary WP_Query instances.** JetEngine Dynamic Listings instantiate their own `WP_Query` objects inside templates. HyperTaxonomy's `pre_get_posts` expansion applies only to the main WordPress query, so JetEngine loop widgets do not receive term expansion automatically.

**How HyperTaxonomy fills the gap:**

- Taxonomy-level semantic relationships: HyperTaxonomy defines what the subject area vocabulary *means*; JetEngine defines how specific posts relate to each other
- Archive query expansion: HyperTaxonomy enriches the main WordPress taxonomy archive query; the standard archive page for a subject therefore shows semantically related content even when JetEngine widgets are present on the same page
- Search integration: HyperTaxonomy's faceted search widget surfaces content across semantically related subjects; JetEngine Smart Filters refine content within a specific subject

JetEngine Relations handle instance-level connections (faculty ↔ course ↔ project); HyperTaxonomy handles term-level connections (subject ↔ subject ↔ method group). For JetEngine Dynamic Listings that need inclusion-expanded results, three options:

**Option 1 — Enable automatic expansion (simplest):** Turn on **Settings → Search → Third-Party Query Expansion**. JetEngine listings that issue a single-term taxonomy query will automatically receive HyperTaxonomy inclusion expansion. Disable if a specific listing conflicts.

**Option 2 — Manual integration:** Call `htax_get_expanded_term_ids( $term_id )` to get the full expanded term ID set, then inject it into the listing's query parameters. Use this if you need expansion on some listings but not others.

**Option 3 — Use Post List block:** Replace the listing with an HT Post List block, which is inclusion-aware by default and requires no code.

JetEngine Smart Filters and HyperTaxonomy's faceted filter panel serve different needs on the same site:
- HyperTaxonomy filter panel: best for filtering within a HyperTaxonomy-expanded result set (post list or search results), including the semantically related content
- JetEngine Smart Filters: best for filtering over custom JetEngine query results or ACF-indexed fields

Avoid placing both on the same result block. Each can be used independently on different sections of the same page.

---

### Elementor

HyperTaxonomy's `pre_get_posts` expansion applies to the main WordPress query only. The standard taxonomy archive page therefore shows inclusion-expanded results automatically. Elementor Loop Builder widgets use secondary `WP_Query` instances and do not receive this expansion by default — an Elementor loop configured to show "posts in category: JavaScript" will show only directly tagged posts.

**Option 1 — Enable automatic expansion (simplest):** Turn on **Settings → Search → Third-Party Query Expansion**. Elementor Loop widgets that issue a single-term taxonomy query will automatically receive HyperTaxonomy inclusion expansion.

**Option 2 — Manual integration:** Call `htax_get_expanded_term_ids( $term_id )` to get the full expanded term ID set and pass it to the loop's query.

**Option 3 — Use Post List block:** Replace the loop with an HT Post List block, which is inclusion-aware by default and integrates natively with the Gutenberg editor.

For post-to-post relationships managed by ACF, Elementor's Dynamic Tags read ACF fields directly and can display related post data in any Loop Builder template.

**No-code pattern for a content hub page:**
1. Drop the HT Post List block — configure taxonomy, terms, sort order, and filters in the inspector panel with live preview
2. Drop an Elementor Loop widget below it for ACF-relationship-based "featured content" — configure with Dynamic Tags
3. No PHP required anywhere in the page

---

### WooCommerce

HyperTaxonomy works with WooCommerce taxonomies (`product_cat`, `product_tag`, and any custom attribute taxonomy registered as a standard taxonomy). Term inclusions, redirects, and hidden terms apply to product archives exactly as they would to post archives.

The Post List block with `post_type="product"` provides a configurable product listing with inclusion-aware filtering, relevance sorting, and AJAX pagination — no template code required.

HyperTaxonomy does not interact with WooCommerce's product variation or cross-sell/upsell systems. Those are WooCommerce post-level features; use WooCommerce's own tools or ACF for them.

---

### Term Icons and Other Plugins That Attach Images to Terms

HyperTaxonomy stores term icons as media library attachments in `_htax_icon_*` term meta and generates three responsive sizes per term. Several other plugins store similar data under different meta keys. The two systems do not interfere at the database level — they write to separate meta keys and neither reads the other's data. Conflicts are entirely **template-level**: they occur only if a theme or template file outputs both a HyperTaxonomy icon and another plugin's term image for the same term in the same location.

**WooCommerce product category thumbnails.** WooCommerce stores a `thumbnail_id` term meta on `product_cat` terms and displays it in two places:
- The shop page (`/shop/`) — in subcategory tiles via the `woocommerce_before_subcategory_title` hook, auto-injected by WooCommerce's own template. HyperTaxonomy does not inject anything here, so no conflict occurs automatically.
- Product category archive headers — displayed by themes that call `woocommerce_get_product_thumbnail()` or read `get_term_meta($category->term_id, 'thumbnail_id')` directly in their archive templates.

A conflict happens if the same archive template also calls `htax_the_archive_icon()`. The practical resolution:

*Option A — Use only WooCommerce thumbnails for `product_cat`.* Do not enable the HyperTaxonomy Icons feature for the `product_cat` taxonomy in **Settings → HyperTaxonomy → Features**. All icon functionality is per-taxonomy, so other taxonomies are unaffected.

*Option B — Use only HyperTaxonomy icons for `product_cat`.* Remove the WooCommerce category image display from the theme template (or disable it in the WooCommerce template you are overriding). HyperTaxonomy icons give you three responsive sizes, configurable border-radius, and a colored placeholder when no icon is set — often a suitable replacement.

*Option C — Gate by term.* If some terms have a WooCommerce thumbnail and others have a HyperTaxonomy icon, check which is set before calling either output function:

```php
$wc_thumbnail_id = get_term_meta( $term->term_id, 'thumbnail_id', true );
if ( $wc_thumbnail_id ) {
    echo wp_get_attachment_image( $wc_thumbnail_id, 'medium' );
} else {
    htax_the_archive_icon( 'medium' );
}
```

**Taxonomy Images plugin.** The `taxonomy-images` plugin attaches images to terms of any taxonomy, stored under `_wptaxonomy_images` term meta. It is no longer listed on WordPress.org and is not actively maintained. It provides template functions such as `taxonomy_image_plugin_get_image()`. Like HyperTaxonomy icons, these must be explicitly called in templates — neither plugin auto-injects on archive pages. If both plugins are active for the same taxonomy, both can be set on a term without conflict. A conflict only arises if a template calls both output functions for the same location. The recommendation is the same: choose one system per taxonomy, or gate by checking which has data.

**WP Term Images.** A dedicated term image plugin (by John James Jacoby, former WordPress core committer) that stores images as `_wp_term_image` term meta and provides `wp_term_image_url()`. Same template-level conflict pattern — no automatic injection, only a conflict when the theme explicitly outputs both.

**Categories Images.** The `categories-images` plugin (60,000+ active installs, by WPOnlineSupport) adds an image field to any taxonomy's term edit screens, stored as `taxonomy-image-id` term meta. Again, no auto-injection: the conflict only occurs if a template outputs both.

**WooCommerce brand taxonomies.** Various brand plugins for WooCommerce (Perfect Brands for WooCommerce, Brand for WooCommerce, and WooCommerce's own Brands extension) register a brand taxonomy and store the brand logo using the same `thumbnail_id` term meta pattern as product categories. The same three-option resolution applies: use one system per taxonomy, or gate by checking which is set.

**ACF image fields on taxonomy terms.** ACF can add an image field to taxonomy term edit screens. Like the above, the images live in separate term meta and only conflict when displayed from the same template location alongside `htax_the_archive_icon()`. Gate with `if ( function_exists('get_field') && get_field('my_term_image', $taxonomy . '_' . $term_id) )` if mixing.

**General rule.** `htax_the_archive_icon()` is silent when no icon is set for the current term and returns an empty string, making it safe to call unconditionally in a template. If another plugin's term image is also set, the safe approach is to check `htax_get_term_icon_url( get_queried_object(), 'large' )` first and fall back to the other plugin's image when HyperTaxonomy has none — or the reverse, depending on which system you designate as primary.

---

### TaxoPress

**Note:** TaxoPress was rebranded "PublishPress Taxonomies" in September 2026 (same plugin, same company); this section uses "TaxoPress" throughout for brevity, matching its long-standing name.

**What it does:** Content automation — auto-assigns terms to posts by scanning post content (Auto Terms), auto-links term names in post body text (Auto Links), and provides AI-assisted term suggestions at publish time. Also includes a taxonomy builder for registering custom taxonomies without code.

**Limitations:** TaxoPress operates entirely at the assignment layer. It answers "which posts should have this term?" It has no mechanism for "what does this term mean relative to other terms?" — no inclusions, no archive expansion, no term redirects, no groups.

**How HyperTaxonomy fills the gap:** Terms assigned by TaxoPress are ordinary WordPress term assignments — they receive the full HyperTaxonomy treatment automatically. A term that TaxoPress auto-assigns to posts also participates in HyperTaxonomy inclusions, appears in the search index, respects redirect logic, and benefits from any group memberships or per-term archive settings. No integration code is needed; TaxoPress writes via standard `wp_set_object_terms()` and HyperTaxonomy responds to those writes the same way it responds to any manual assignment.

**Combined:** TaxoPress handles the question of what gets tagged; HyperTaxonomy handles the question of what the tags mean — and how strongly each tag applies. Auto-tagging addresses concept sparsity (manually tagged posts typically have 3–5 terms; auto-tagged posts can have dozens), which makes HyperTaxonomy's archive expansion and relevance scoring more effective. A post auto-tagged with "React", "Component Architecture", and "TypeScript" will surface in the JavaScript archive without any editor touching it — because HyperTaxonomy already knows those terms are related. HyperTaxonomy also records how centrally each auto-assigned term covers the post (by measuring term-name frequency in title, excerpt, and body), so a post that is primarily about React ranks above a post that merely mentions it, even when both are tagged identically.

**Co-tagging vs. inclusions.** TaxoPress Pro "Linked Terms" (co-tagging) adds Term B to a post at save time when Term A is applied. HyperTaxonomy inclusions operate at query time: Term A's archive dynamically shows Term B's posts without modifying any post's term assignments. For the same end result, inclusions are zero-maintenance and retroactive; co-tagging is an explicit assignment that accumulates in the database.

**Do not use TaxoPress (or any external tool) to merge terms when HyperTaxonomy relationships are in use.** TaxoPress's merge feature reassigns posts via `wp_set_object_terms()` then deletes the source term via `wp_delete_term()`. The deletion fires HyperTaxonomy's cleanup hooks, which remove the source term's relationship rows — but nobody transfers them to the merge target. Group memberships, inclusions, and exclusions that belonged to the source term are permanently lost. HyperTaxonomy's own merge UI (**Terms → [taxonomy] → Merge**) performs the relationship transfer atomically before deletion. If you need to script merges programmatically, use `htax_merge_terms()` directly, or hook into `htax_before_merge_terms` / `htax_after_merge_terms` to extend the built-in merge flow.

HyperTaxonomy also surfaces this warning directly where it matters: on TaxoPress's own Manage Terms → Merge Terms screen, whenever the taxonomy currently selected there is HyperTaxonomy-enabled, a dismissible notice names what would be lost and links to HyperTaxonomy's own **Merge Terms** page (Terms → Merge Terms) instead, with the same taxonomy preselected. The dismissal is per-admin-user and persists until TaxoPress is deactivated (and reactivated), at which point the notice reappears for everyone until dismissed again.

**Tag Groups.** TaxoPress and Tag Groups (rebranded "PublishPress Tag Groups" in October 2026) are both published by PublishPress as of their respective rebrands, but they are independent plugins with no cross-dependency. Running HyperTaxonomy + TaxoPress (without Tag Groups) is a natural combination for sites that need both semantic taxonomy management and content automation. Tag Groups (free) provides tabbed and accordion display layouts for term lists; Tag Groups Pro adds a table layout and live in-display search. If those display layouts are a hard requirement, evaluate Tag Groups on its own terms.

HyperTaxonomy can also run alongside Tag Groups rather than replace it. Running both without any bridge between them does create overlapping responsibilities — two separate group systems with no shared data. The Export/Import → Migrate and Sync subtabs are that bridge: they read Tag Groups' group and term-assignment data directly from its database tables and map it onto HyperTaxonomy's native `group` taxonomy, either as a one-time migration or continuously. Under **Locked** integration mode, Tag Groups stays the system of record for group management — mapped groups are locked against edits in HyperTaxonomy, and the term edit screen promotes Tag Groups' own field to the spot HyperTaxonomy's group selector normally occupies — while HyperTaxonomy's archive pages, search indexing, hierarchy, and relationships apply to the synced result. See [Import & Migration](import-migration.html) for the full reference.

TaxoPress features HyperTaxonomy does not replicate: auto-linking term names in post content, automatic term assignment from post content, AI-assisted term suggestions at publish time, and related posts via co-occurrence. HyperTaxonomy features TaxoPress does not replicate: archive query expansion, semantic inclusions, FULLTEXT search, term redirects, sticky posts, term icons, a denormalized search index, and per-relationship strength scoring.

**Moving from, or alongside, Tag Groups:** HyperTaxonomy's importer reads Tag Groups' (or PublishPress Tag Groups' — same database format) group and term-assignment data directly from its database tables — no export step, and Tag Groups doesn't need to stay active for a one-time migration. Leaving Tag Groups behind: run the migration once, then deactivate it. Keeping Tag Groups as your group-management tool: choose Locked mode with continuous sync instead, so HyperTaxonomy's groups stay current automatically.

See [vs. Tag Groups](../comparison.html) for a detailed side-by-side.

---

### Relevanssi

Both plugins can optionally replace WordPress's native keyword search. Enable only one for that role.

**Recommended split:** Use Relevanssi for keyword-driven search (fuzzy matching, phrase proximity, partial-word search, custom field indexing). Use HyperTaxonomy for archive browsing, term relationships, and the taxonomy-based faceted search widget (which does not conflict with Relevanssi's keyword search). Disable HyperTaxonomy's "replace WordPress search" option when Relevanssi is active.

This gives you linguistically rich keyword search (Relevanssi) plus relationship-aware taxonomy browsing and archive expansion (HyperTaxonomy) — the two capabilities are genuinely additive.

---

### FacetWP

FacetWP excels at faceted filtering over custom post meta fields (prices, dates, ACF relationship facets, numeric fields). HyperTaxonomy excels at semantic enrichment of the base taxonomy result set.

**Automatic expansion:** FacetWP's index refresh query and its archive `WP_Query` both fire `pre_get_posts`. Enabling **Settings → Search → Third-Party Query Expansion** will expand single-term FacetWP taxonomy clauses to include HyperTaxonomy inclusions, and will adjust the facet counts displayed in FacetWP's taxonomy facets to reflect the expanded post set. This is the recommended approach for sites where both plugins filter the same result set.

**When to keep them separate:** If FacetWP is used exclusively for post-meta–based facets (prices, dates, numeric fields) without any taxonomy facets, there is no interaction and no configuration needed. Use them in separate contexts — FacetWP for post-meta filtering, HyperTaxonomy for taxonomy-based semantic filtering — and expansion has no effect on FacetWP's meta facets.

**Note on multi-term clauses:** Expansion applies only to single-term taxonomy clauses. When a visitor selects multiple terms in a FacetWP taxonomy facet (multi-select), HyperTaxonomy treats those as explicit user filters and does not expand them. Expansion is for archive-context queries, not interactive multi-choice filter selections.

---

### SearchWP

SearchWP is a premium WordPress search plugin, commonly used alongside or instead of Relevanssi for more control over which content surfaces and how it is weighted. The conflict with HyperTaxonomy is the same as with Relevanssi: both can replace WordPress's native `?s=` search. Enable only one for that role — disable HyperTaxonomy's **Enhance WordPress search globally** setting when SearchWP is active.

Unlike Relevanssi (pure keyword TF-IDF), SearchWP can also index custom fields, taxonomy terms, and custom database tables. This means SearchWP's taxonomy search — when configured — may partially overlap with HyperTaxonomy's faceted search widget. The recommended division is the same: SearchWP handles keyword relevance and document scoring; HyperTaxonomy handles archive browsing, term relationship expansion, and the taxonomy facet UI.

---

### WP Grid Builder

WP Grid Builder is a Gutenberg-native grid and faceted filtering plugin. Its taxonomy facets issue `WP_Query` calls that fire `pre_get_posts`. The interaction with HyperTaxonomy is identical to FacetWP: enable **Settings → Search → Third-Party Query Expansion** to have single-term taxonomy clauses automatically expanded to include HyperTaxonomy inclusions, and to have facet counts reflect the expanded post set. Multi-term selections are not expanded (same reasoning as FacetWP — those are explicit user filter choices, not archive expansions).

---

### Yoast SEO and Rank Math

Both SEO plugins can mark empty or thin term archives as `noindex` or redirect them away. Neither conflicts with HyperTaxonomy in practice:

- **HyperTaxonomy redirects** fire via `wp_redirect()` and `exit` at PHP level on the `wp` action. The page is never rendered, so neither Yoast nor Rank Math outputs any headers or meta tags for redirect terms. No interaction occurs.
- **`noindex` for thin archives** is a meta-tag output — it fires during template rendering, which redirect terms never reach. For non-redirect terms, HyperTaxonomy's archive expansion typically adds enough posts to a term's archive that the Yoast/Rank Math "thin content" threshold is not met; but if both HyperTaxonomy and an SEO plugin have independently stored redirect rules for the same URL, HyperTaxonomy's `wp_redirect()` fires first and the SEO plugin's rule is never evaluated.
- **Manual redirect rules** in Yoast SEO Premium's redirect manager or Rank Math's redirect module are URL-level rules stored in a separate table. If a developer manually creates a redirect for a URL that HyperTaxonomy also redirects, both point to the same destination but HyperTaxonomy fires first and the SEO plugin's rule is never reached. No visible effect; clean them up to avoid confusion.

#### Yoast / Rank Math and term conversion

Yoast SEO and Rank Math index term archives in `wp_yoast_indexable` (and an equivalent table for Rank Math) keyed by `object_type = 'term'`, `object_sub_type = taxonomy_slug`, `object_id = term_id`. When a term moves to a different taxonomy, this record needs to be considered:

**With a redirect stub** (HyperTaxonomy's default): the old term stays in the old taxonomy as the stub with the same `term_id`. Its existing Yoast/Rank Math record remains correctly attached — `object_sub_type = 'category'` still matches the stub's taxonomy. The new term in the target taxonomy gets indexed fresh on the next Yoast/Rank Math crawl. No action required.

**Without a redirect stub** (or when using an external converter that deletes the old term): the old term is deleted, orphaning its Yoast/Rank Math record. The record references a `term_id` that no longer exists in the old taxonomy. Hook `htax_term_converted` to update it proactively:

```php
add_action( 'htax_term_converted', function(
    int    $new_term_id,
    string $old_taxonomy,
    string $new_taxonomy,
    int    $old_term_id
) {
    // In the redirect path the old term (stub) is still in $old_taxonomy —
    // its Yoast record remains valid. Only act when the old term was deleted.
    $still_exists = get_term( $old_term_id, $old_taxonomy );
    if ( $still_exists && ! is_wp_error( $still_exists ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'yoast_indexable';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return;
    }

    $wpdb->update(
        $table,
        array( 'object_sub_type' => $new_taxonomy, 'object_id' => $new_term_id ),
        array( 'object_type' => 'term', 'object_sub_type' => $old_taxonomy, 'object_id' => $old_term_id )
    );
}, 10, 4 );
```

---

### WPML and Polylang

#### WPML

WPML stores translation relationships in `wp_icl_translations` keyed by `element_type = 'tax_{taxonomy}'` and `element_id = term_taxonomy_id` (the row in `wp_term_taxonomy`, not the `term_id`). When a term moves to a different taxonomy:

**With a redirect stub**: the old term stays in the old taxonomy with the same `term_taxonomy_id`. WPML's translation links remain valid. The new term in the target taxonomy has no translation links; those must be set up manually in the WPML interface.

**Without a redirect stub**: the old `term_taxonomy_id` row is deleted before `htax_term_converted` fires, so the old `element_id` cannot be looked up at hook time. WPML's translation links for the old term are broken.

The practical recommendation: **keep redirect stubs enabled when WPML is active**. The stub retains the original taxonomy and the same `term_taxonomy_id`, so all WPML translation links survive intact. If a no-redirect conversion is necessary, run WPML's built-in translation integrity repair (`wpml_repair_translations`) afterward, or hook `pre_delete_term` to capture and update the WPML record before WordPress removes the `wp_term_taxonomy` row.

```php
// Redirect-path fix only: update WPML element_type when the stub's term_taxonomy_id persists.
add_action( 'htax_term_converted', function(
    int    $new_term_id,
    string $old_taxonomy,
    string $new_taxonomy,
    int    $old_term_id
) {
    global $wpdb;
    $table = $wpdb->prefix . 'icl_translations';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return;
    }

    // Only act when the stub still exists (redirect path).
    $still_exists = get_term( $old_term_id, $old_taxonomy );
    if ( ! $still_exists || is_wp_error( $still_exists ) ) {
        return;
    }

    // Find the new term's term_taxonomy_id so WPML can link translations to it.
    $new_tt_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
        WHERE term_id = %d AND taxonomy = %s",
        $new_term_id,
        $new_taxonomy
    ) );
    if ( ! $new_tt_id ) {
        return;
    }

    // Find the translation group trid from the stub's record.
    $old_tt_id = (int) $still_exists->term_taxonomy_id;
    $trid = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT trid FROM {$table}
        WHERE element_type = %s AND element_id = %d",
        'tax_' . $old_taxonomy,
        $old_tt_id
    ) );
    if ( ! $trid ) {
        return;
    }

    // Insert a new translation record for the target-taxonomy term under the same trid.
    $wpdb->insert( $table, array(
        'element_type'    => 'tax_' . $new_taxonomy,
        'element_id'      => $new_tt_id,
        'trid'            => $trid,
        'language_code'   => get_bloginfo( 'language' ),
        'source_language_code' => null,
    ) );
}, 10, 4 );
```

#### Polylang

Polylang stores language associations as term relationships via a custom `language` taxonomy (each content term is assigned a language term). These associations are stored in `wp_term_relationships` keyed by `term_taxonomy_id`, not `term_id`. When a term converts:

- **With redirect stub**: the stub keeps the old `term_taxonomy_id` — its language assignment survives unchanged.
- **Without redirect stub**: the old `term_taxonomy_id` is deleted; the language assignment is lost. The new term inherits no language assignment and will appear as unassigned in the Polylang interface.

Polylang translation links (connecting translated versions of the same term in different languages) are stored similarly. If both the source and translated term are converted simultaneously, their translation links may survive if both stubs keep their `term_taxonomy_id`s. If only one is converted without a stub, the link breaks.

**Practical recommendation**: use redirect stubs when Polylang is active. If no-redirect conversion is necessary, reassign the language to the new term via the Polylang term editor after conversion.

#### wp\_terms.term\_group

The `term_group` column in `wp_terms` is a legacy WordPress mechanism that some older multilingual systems use to group synonymous terms (e.g., link "Science" in English to "Wissenschaft" in German by giving both the same `term_group` value). HyperTaxonomy checks for `term_group` collisions before allowing a conversion: if the target taxonomy already contains a different term with the same non-zero `term_group` value, the conversion is blocked with an error. This prevents silently creating duplicate `term_group` pairings within a taxonomy that would confuse those multilingual systems.

---

### YARPP and Jetpack Related Posts

Both use content-based algorithms to suggest related posts — YARPP via co-occurrence and text similarity, Jetpack via its cloud API. HyperTaxonomy's Related Posts block uses taxonomy relationship similarity (shared terms, inclusions, and relevance weighting from the search index). The approaches are complementary rather than conflicting: YARPP and Jetpack surface textually or semantically proximate content; HT Related Posts surfaces content that belongs to the same or related taxonomy vocabulary.

A third option is the TermConnect plugin's `[tc_peer_posts]`, which extends the same taxonomy vocabulary across to peer WordPress sites and RSS/Atom feeds. Where the Related Posts block finds content on the same site by shared terms, `[tc_peer_posts]` finds content on configured peer sites by mapped terms — posts authored by practitioners with a shared vocabulary but a different readership. The three can coexist: HT Related Posts for same-site taxonomy-driven content, YARPP or Jetpack for content-driven discovery, and TermConnect for network-sourced related reading.

A common pattern: HT Related Posts for "more on this topic" (taxonomy-driven, this site), and YARPP or Jetpack for "you might also like" (content-driven) — two distinct discovery paths on one page.

---

## 7. Integrating from a Plugin or Theme

For plugin and theme authors building their own HyperTaxonomy-aware code, HyperTaxonomy exposes three main extension points:

**Expansion-aware queries.** `htax_get_expanded_term_ids( $term_id )` returns the flat list of term IDs whose posts appear in a term's archive. Use it to build a `tax_query` clause that respects HyperTaxonomy inclusions in any custom `WP_Query`. Set the `htax_expand_terms` query variable to `true` or `false` on individual `WP_Query` instances to force or suppress automatic expansion per-query, overriding the global setting.

**Cache invalidation.** `htax_term_archive_changed` fires after every relationship save, relationship delete, redirect save, or redirect delete. Hook it to invalidate derived caches in your plugin without tracking each fine-grained action individually.

**Custom relationship types.** The Relationship Type Registry (`includes/core/relationship-types.php`) lets plugin authors define two kinds of user-defined type. **Inclusion-based** types (`core_type = 'includes'`) automatically participate in archive expansion, post list queries, and the search index — all query sites use `htax_relationship_type_sql_in('includes')` rather than a hard-coded equality check. **Custom** types (`core_type = null`) store the relationship only; behavior is implemented by the registering plugin. Use `htax_register_relationship_type()` (hook into `htax_relationship_types`) for code registration, or the **Terms → Term Relationship Types** admin page (two creation buttons: Inclusion-based / Custom). Use `htax_add_term_relationship()` / `htax_remove_term_relationship()` rather than raw `$wpdb` calls. Cleanup on term deletion is handled automatically — no `delete_term` hook needed. See [Custom Relationship Types](../developer-reference/custom-taxonomy.html#custom-relationship-types) for the full API.

See the [Developer Reference](../developer-reference/) for full function references, action and filter documentation, REST endpoints, and the capabilities API.

---

## 8. No-Code Quick Reference

One of the advantages of HyperTaxonomy's Gutenberg block integration is that the relationship layer it adds does not require coding to use on frontend pages.

| Task | No-code tool |
|------|-------------|
| Define taxonomy vocabulary and terms | WordPress admin (any taxonomy) |
| Register custom post types and taxonomies | CPT UI / TaxoPress / Pods |
| Create inclusion relationships between terms | HyperTaxonomy term edit screen |
| Assign terms to groups | HyperTaxonomy term edit screen (or Quick Edit) |
| Configure redirects, icons, sticky posts, per-term sort | HyperTaxonomy term edit screen |
| Auto-assign terms from post content | TaxoPress (Auto Terms) |
| Auto-link term names in post content | TaxoPress (Auto Links) |
| Display a filtered post list on any page | **Post List block** (block editor inspector) |
| Display related posts on a post template | **Related Posts block** (block editor / FSE template) |
| Display related posts from peer WordPress sites | **`[tc_peer_posts]`** shortcode (TermConnect) |
| Build faceted search interfaces | HyperTaxonomy Search Widget (block or shortcode) |
| Display ACF relationship fields | Elementor Dynamic Tags / ACF blocks |
| Display JetEngine Relations | JetEngine Dynamic Listings |

The Post List and Related Posts blocks in particular bridge the gap between HyperTaxonomy's relationship data and editorial teams who build pages in the block editor. All query and display parameters — taxonomy, terms, sort order, filters, display options — are configurable in the inspector panel with a live server-side preview. No shortcode syntax or developer involvement is needed to create a content hub that reflects the full semantic structure of your taxonomy vocabulary.

---

## 9. Performance Considerations

### Adding HyperTaxonomy to an existing stack does not multiply database queries

A common concern when combining multiple plugins is query overhead. HyperTaxonomy is designed to minimize this.

Archive queries and `[ht_post_list]` / Related Posts block queries run against the pre-computed `wp_htax_search_terms` index — a single indexed table lookup with a GROUP BY, not a multi-table JOIN across the core taxonomy tables at runtime. Term inclusion expansion is pre-computed into the index at write time, not at query time.

Two caching layers sit above the database:

1. **Per-request static cache** — relationship lookups and term data resolved during a single page load are held in static request-scope arrays. Most are unbounded for the duration of the request; `htax_get_valid_term()` specifically uses a bounded cache capped at 500 entries with FIFO eviction. If ACF, JetEngine, and HyperTaxonomy all reference the same term during the same request, only one database call is made.

2. **WordPress transients** — `[ht_post_list]` and Related Posts block results are cached as transients with targeted invalidation. A cached post list result is invalidated only when the specific term assignments, term relationships, or group memberships that affect it change — not on every post save globally. Repeated page loads of a content hub do not re-run queries.

In practice, adding HyperTaxonomy alongside ACF or JetEngine typically adds zero extra database calls on cached page loads, and one indexed query on uncached loads. The PowerSearch adds one additional query per search request.

---

### The search index is the performance foundation

All term archive queries, `[ht_post_list]` queries, and relevance scores run against the pre-computed `wp_htax_search_terms` index. Each row in the index carries a **strength score** — how centrally the post covers that term — which directly affects search ranking. Keeping this index healthy and up to date is the most important operational concern.

The index is updated incrementally (via WP Cron) as posts are created, edited, or have their term assignments changed. For large bulk imports (hundreds or thousands of posts), trigger a full rebuild from **Settings → Search → Rebuild Search Index** after the import completes. Running bulk imports with the index rebuild disabled (import then rebuild once) is faster than rebuilding after every post.

**Explicit relationship weights and strength.** The Explicit Selection weights in Settings → Search → Relevance Weights control how much of a direct term's strength propagates to indirect relationships (hierarchy, inclusions, redirects). These weights are baked into the strength score at index build time. When you save a change to them, HyperTaxonomy automatically queues every post in the affected taxonomy for reindexing in the background (via WP Cron), so taxonomy-filter searches pick up the new weights within a few minutes — no manual rebuild needed. Text Search (keyword) weights remain dynamic and apply immediately.

### Avoid very large inclusion sets on high-traffic archives

Each inclusion expands the term set for archive queries. An archive term that directly includes 50 other terms will produce a query spanning all 50 sets. The search index handles this in a single indexed lookup (the expansion is pre-computed into the index), but very large sets will produce large result pages. Use `max_results` or `posts_per_page` to bound result sizes.

### Cache behavior

`[ht_post_list]` and `[ht_related_posts]` cache their full query result (ordered post IDs + total count) with no TTL. The cache is invalidated precisely when the post–term assignments, term relationships, or group memberships that affect the result change — not globally. This means adding an inclusion to a term will invalidate caches for archives and shortcodes that include that term, which is the correct behavior.

For very large sites with high write volume (many post updates per hour), monitor transient table size. The cache keys are hashed and scoped to affected term sets, so the number of distinct cache entries grows with the number of distinct term combinations — not with the number of posts.

### Term count at scale

