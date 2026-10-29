---
title: Introduction & Concepts
parent: User Guide
nav_order: 1
---

## 1. Introduction

### Terms and Taxonomies

A **term** in WordPress is a label that represents a concept — an idea, topic, entity, or subject. Terms are grouped into **taxonomies**: a taxonomy is a named vocabulary of terms. WordPress ships with two built-in taxonomies — *tags* (flat, unordered) and *categories* (parent-child tree) — and allows site owners to define additional custom taxonomies for any content domain.

When a content editor assigns a term to a post, they declare that the post belongs to that concept. That association can be strong or weak: a dedicated guide to React hooks has "React" as its central topic; a broad JavaScript trends article may mention React only in passing. WordPress treats both identically — a term defines a set of posts, with no record of how centrally each post covers the concept. HyperTaxonomy measures that centrality: each term-to-post relationship in the search index carries a **strength score** derived from how prominently the term's name appears in the post's title, excerpt, and body. A post that is primarily about React scores higher for the React tag than one that only mentions it in passing, and that difference flows directly into search ranking and related-post ordering.

A term is best understood as a concept, not a post list. "React" does not mean "these particular posts"; it means the concept React, and every post tagged with it is a current member of that set. New posts join automatically; the concept's meaning does not change. This distinction matters for HyperTaxonomy: a relationship between two terms applies uniformly to all posts in both sets, now and in the future. The usefulness of that relationship depends directly on how consistently and precisely terms have been applied.

---

### The Problem

WordPress taxonomies are limited to two organizational models, each with fundamental constraints.

**Flat taxonomies (tags):** Terms exist independently with no relationships. For example, posts tagged "React" won't appear in the "JavaScript" archive — even though React is a JavaScript framework. You must manually tag every React post with both terms, creating maintenance overhead and inconsistency.

**Hierarchical taxonomies (categories):** Terms can only have one parent, forming a strict tree. The only relationship is parent-child, with child categories being narrower than their parents. A topic can legitimately belong to multiple organizational perspectives simultaneously — and a post may be in multiple categories — but you cannot express that posts in "React" category should automatically appear within both "Frontend Development" and "JavaScript Ecosystem".

**Custom taxonomies** offer no escape: any custom taxonomy is also either flat or hierarchical — there are no additional relationship types.

At a deeper level, the problem is semantic as much as structural: how posts get tagged is left to the content creator and carries no enforced meaning. Topics overlap, terms relate in multiple ways simultaneously, and rigid hierarchies force either duplicate tagging or a compromised information architecture.

### The Solution

HyperTaxonomy adds **explicit term-to-term relationships** that work alongside — never replacing — WordPress's existing taxonomies:

- **Inclusion relationships** — Any term can include any other term. "JavaScript" can include "React"; "Frontend Development" can include both.
- **Bidirectional inclusion (synonyms)** — "USA" and "United States" can share all content in either archive, becoming interchangeable.
- **Term Groups** — Built-in group taxonomy for organizing terms across multiple hierarchies and managing bulk inclusions.
- **Custom relationship types** — Define domain-specific named relationships between terms (e.g. "translated into", "supersedes", "component of").
- **Redirects** — 301 redirects for outdated or redundant terms, preserving SEO value.
- **Relationship strength** — Each term-to-post relationship in the search index carries a score reflecting how centrally the post covers that term. Posts where the term dominates the content rank above posts where it appears only in passing.
- **Archive enhancements** — Term icons, sticky posts, rich descriptions, hidden terms.
- **Search** — PowerSearch with relevance scoring and a modular faceted search widget.

### Key Benefits

- **Better content discovery** — Related content appears in multiple relevant archives without duplicate tagging
- **Clean organization** — Multiple organizational perspectives coexist without breaking any hierarchy
- **No coding required** — Term lists, tag clouds, related posts, sort controls, etc., are available both as Gutenberg blocks, as well as shortcodes
- **Flexible search** — Build faceted search interfaces without coding
- **Improved SEO** — 301 redirects preserve link equity when consolidating terms

---

## 2. Core Concepts

### Relationship Types

**Inclusion** — Term A includes Term B: every post tagged with Term B automatically appears in Term A's archive. Defined from either direction — adding B to A's "included terms" is identical to adding A to B's "container terms." Inclusions are **non-transitive**: A including B and B including C does not make A include C. Each link is explicit and independent, so every archive stays focused on exactly the content you chose.

**Synonyms** — When Term A includes Term B and Term B includes Term A, they become synonyms. Posts from either term appear in both archives. Both terms retain separate identities, archive URLs, and positions in the hierarchy.

**Group Inclusions** — Instead of including individual terms one by one, a term can include an entire Term Group. All current and future group members are included automatically — no maintenance as the group grows.

**Container Groups** — The reverse of group inclusions. A term specifies Term Groups as its "containers," and its posts flow into the archives of every term that includes those groups. Useful when it is more natural to configure from the "child" side.

**Exclusions** — Any term or group of terms can be explicitly removed from the included set. Exclusions always win regardless of which inclusion source added the term.

**Redirects** — Term A 301-redirects to Term B. Visitors to Term A's archive URL are sent to Term B's archive. Term A has no accessible archive of its own; all stored relationships resume if the redirect is later removed.

**Custom Relationship Types** — User-defined relationships beyond the four built-in types. Inclusion-based custom types participate in archive expansion automatically (e.g. "Supersedes"). Other custom types store the relationship only and leave behavior to your theme or plugin (e.g. "Related"). Custom types can be **symmetric** (same label in both directions, single unified list in the term editor) or **asymmetric** (distinct forward and inverse labels, two separate panels).

### Design Philosophy

Inclusions are non-transitive by design: unlike WordPress's native category hierarchy (where a post in a child category automatically appears all the way up the tree), HyperTaxonomy keeps each archive focused on exactly the content you defined for it. Two terms that include each other mutually form synonyms, sharing content while remaining independent.

HyperTaxonomy inclusion and container relationships are also restricted to **cross-branch** terms. For hierarchical taxonomies, a term's ancestors and descendants are excluded from its included and container sets — WordPress's native hierarchy already ensures those posts appear in the right archives, and duplicating that with HyperTaxonomy would be redundant. HyperTaxonomy relationships are for connecting terms that are not already related through the taxonomy tree.

For a full discussion of when to use inclusions vs. native hierarchy, how to model common content structures, and how HyperTaxonomy fits alongside other plugins — see [Best Practices](best-practices.html).

---

### Term Groups

Term Groups is a built-in hierarchical taxonomy for organizing terms into groups that span taxonomies and organizational perspectives. Unlike Tags and Categories — which are post taxonomies that live under **Posts** in the admin sidebar — Term Groups is a *term* taxonomy: it classifies and organizes terms, not posts directly. Accordingly, it lives under **Terms > Term Groups** rather than under Posts.

**What Term Groups do:**

- Group tags, categories, and custom taxonomy terms from any taxonomy into named groups
- Each group has its own archive page, showing posts from all member terms
- Terms can belong to multiple groups simultaneously
- Groups support parent/child hierarchy: terms that are members of child groups are automatically treated as if they were members of parent group as well
- Groups (meaning, all group members that belong to the same taxonomy as a given term) can be included in bulk when configuring term relationships
- Terms may also have Container Groups, effectively getting included into every member of these groups/their parent groups 

**The key insight:** Term Groups let the same term exist in multiple organizational structures simultaneously without changing its primary hierarchy.

**Example — Product categories:**

```
Primary hierarchy (categories): Sporting Goods > Fishing, Camping, Skiing
                                  Home & Garden > Pool Supplies

Term Groups (alternative perspectives):
  Summer Products: Fishing, Camping, Pool Supplies  ← spans both primary categories
  Winter Products: Skiing
```

"Fishing" is a child of "Sporting Goods" (department view) and a member of "Summer Products" (seasonal view). Both perspectives coexist independently. The `/category/sporting-goods/` archive shows department content; `/group/summer-products/` shows seasonal content.

---

