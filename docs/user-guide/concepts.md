---
title: Concepts
parent: User Guide
nav_order: 4
---

# Concepts
{: .no_toc }

The ideas HyperTaxonomy is built on: what terms are, the relationships between them, and how to choose between features. For the problem it solves, with an example, see [Why HyperTaxonomy](../why-hypertaxonomy.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Terms and Taxonomies

A **term** in WordPress is a label that represents a concept — an idea, topic, entity, or subject. Terms are grouped into **taxonomies**: a taxonomy is a named vocabulary of terms. WordPress ships with two built-in taxonomies — *tags* (flat, unordered) and *categories* (parent-child tree) — and allows site owners to define additional custom taxonomies for any content domain.

When a content editor assigns a term to a post, they declare that the post belongs to that concept. That association can be strong or weak: a dedicated guide to React hooks has "React" as its central topic; a broad JavaScript trends article may mention React only in passing. WordPress treats both identically — a term defines a set of posts, with no record of how centrally each post covers the concept. HyperTaxonomy measures that centrality: each term-to-post relationship in the search index carries a **strength score** derived from how prominently the term's name appears in the post's title, excerpt, and body. A post that is primarily about React scores higher for the React tag than one that only mentions it in passing, and that difference flows directly into search ranking and related-post ordering.

A term is best understood as a concept, not a post list. "React" does not mean "these particular posts"; it means the concept React, and every post tagged with it is a current member of that set. New posts join automatically; the concept's meaning does not change. This distinction matters for HyperTaxonomy: a relationship between two terms applies uniformly to all posts in both sets, now and in the future. The usefulness of that relationship depends directly on how consistently and precisely terms have been applied.

## Relationship Types

**Inclusion** — Term A includes Term B: every post tagged with Term B automatically appears in Term A's archive. Defined from either direction — adding B to A's "included terms" is identical to adding A to B's "container terms." Inclusions are **non-transitive**: A including B and B including C does not make A include C. Each link is explicit and independent, so every archive stays focused on exactly the content you chose.

**Synonyms** — When Term A includes Term B and Term B includes Term A, they become synonyms. Posts from either term appear in both archives. Both terms retain separate identities, archive URLs, and positions in the hierarchy.

**Group Inclusions** — Instead of including individual terms one by one, a term can include an entire Term Group. All current and future group members from the term's own taxonomy are included automatically — no maintenance as the group grows.

**Container Groups** — The reverse of group inclusions. A term specifies Term Groups as its "containers," and its posts flow into the archives of the members of those groups. Useful when it is more natural to configure from the "child" side.

**Exclusions** — Any term can be explicitly removed from the included set. Exclusions always win regardless of which inclusion source added the term.

**Redirects** — Term A 301-redirects to Term B. Visitors to Term A's archive URL are sent to Term B's archive. Term A has no accessible archive of its own; all stored relationships resume if the redirect is later removed.

**Custom Relationship Types** — User-defined relationships beyond the built-in types. Inclusion-based custom types participate in archive expansion automatically (e.g. "Supersedes"). Other custom types store the relationship only and leave behavior to your theme or plugin (e.g. "Related"). Custom types can be **symmetric** (same label in both directions, single unified list in the term editor) or **asymmetric** (distinct forward and inverse labels, two separate panels).

## Design Philosophy

Inclusions are non-transitive by design: unlike WordPress's native category hierarchy (where a post in a child category automatically appears all the way up the tree), HyperTaxonomy keeps each archive focused on exactly the content you defined for it. Two terms that include each other mutually form synonyms, sharing content while remaining independent.

HyperTaxonomy inclusion and container relationships are also restricted to **cross-branch** terms. For hierarchical taxonomies, a term's ancestors and descendants are excluded from its included and container sets — WordPress's native hierarchy already ensures those posts appear in the right archives, and duplicating that with HyperTaxonomy would be redundant. HyperTaxonomy relationships are for connecting terms that are not already related through the taxonomy tree.

For a full discussion of when to use inclusions vs. native hierarchy, how to model common content structures, and how HyperTaxonomy fits alongside other plugins — see the [Modeling Guide](modeling-guide.html).

---

## Term Groups

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

## Choosing Features

Common decision points:

**Inclusion vs synonym?** An inclusion is one-directional: A includes B means B's posts appear in A's archive, but A's posts don't appear in B's. A synonym is mutual: add A to B's included list AND B to A's included list. Both archives then show posts from either term. Use inclusions for broader/narrower relationships (Programming → JavaScript); use synonyms for terms that cover the same concept from different angles (JS → JavaScript).

**Inclusion vs redirect?** Inclusion expands an archive — both terms remain visible and functional, each with its own URL and archive. A redirect replaces an archive — visitors to the old URL are sent to the target term's archive. The redirect term still appears in term lists, in italics by default, and links to wherever `redirect_link_behavior` sends it; `exclude_redirects` leaves it out. Use inclusions when both terms should remain first-class navigation items; use redirects when you are consolidating or renaming and want the old URL to become an alias.

**Redirect vs merge?** Both consolidate terms. A redirect keeps both terms in the database and creates a permanent 301 forward — reversible, the original term still exists. A merge physically reassigns all post relationships and deletes the source term — irreversible. Use redirects for ongoing aliases; use merge for permanent cleanup of duplicate terms.

**Group vs tag cloud?** A Term Group is a structural container — a named set of terms, used in inclusions, in post list shortcodes, and in the Query Loop block filter. A tag cloud (`[ht_term_cloud]`) is a display component — it renders terms with size proportional to post count. These serve entirely different purposes and are often used together.

**Sticky post vs sort order override?** A sticky post pins one specific post to the top of one specific term archive — useful for editorial choices ("always show the glossary at the top of this tag"). A sort order override changes the ordering algorithm for all results on a term archive — useful for making comment activity or modification date the primary ranking signal. They can coexist: the sticky appears first, then the sort order applies to everything else.

**PowerSearch vs Search Widget?** PowerSearch is text-driven: a user types a query with field operators and gets relevance-ranked results across post content and taxonomy names. The Search Widget is facet-driven: a user selects taxonomy terms from pills or checklists and narrows results by tag, category, or custom taxonomy. They can appear on the same page and complement each other.

**Hidden term vs draft posts?** A hidden term controls taxonomy navigation visibility — unauthorized users don't see the term in archives, shortcodes, or search results, but the posts themselves remain publicly accessible through direct URLs. Draft posts hide the posts themselves. Use hidden terms for curator-only filing systems (internal categories, editorial flags) where the posts are public but the organizational structure isn't.
