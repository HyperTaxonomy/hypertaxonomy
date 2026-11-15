---
title: Concepts
parent: User Guide
nav_order: 1
---

# Concepts
{: .no_toc }

The ideas HyperTaxonomy is built on: what terms are, how term groups organize them, the relationships between them, and which to use when. For the problem it solves, with an example, see [Why HyperTaxonomy](../why-hypertaxonomy.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Terms and Taxonomies

A **term** in WordPress is a label that represents a concept — an idea, topic, entity, or subject. Terms are grouped into **taxonomies**: a taxonomy is a named vocabulary of terms. WordPress ships with two built-in taxonomies — *tags* (flat, unordered) and *categories* (parent-child tree) — and allows site owners to define additional custom taxonomies for any content domain.

When a content editor assigns a term to a post, they declare that the post belongs to that concept. That association can be strong or weak: a dedicated guide to React hooks has "React" as its central topic; a broad JavaScript trends article may mention React only in passing. WordPress treats both identically — a term defines a set of posts, with no record of how centrally each post covers the concept. HyperTaxonomy measures that centrality: each term-to-post relationship in the search index carries a **strength score** derived from how prominently the term's name appears in the post's title, excerpt, and body. A post that is primarily about React scores higher for the React tag than one that only mentions it in passing, and that difference flows directly into search ranking and related-post ordering.

A term is best understood as a concept, not a post list. "React" does not mean "these particular posts"; it means the concept React, and every post tagged with it is a current member of that set. New posts join automatically; the concept's meaning does not change. This distinction matters for HyperTaxonomy: a relationship between two terms applies uniformly to all posts in both sets, now and in the future. The usefulness of that relationship depends directly on how consistently and precisely terms have been applied.

## Term Groups

Term Groups is a hierarchical taxonomy supplied by this plugin for organizing terms into groups that span taxonomies and organizational perspectives. Unlike Tags and Categories — which are post taxonomies that live under **Posts** in the admin sidebar — Term Groups is a *term* taxonomy: it classifies and organizes terms, not posts directly. Accordingly, it lives under **Terms > Term Groups** rather than under Posts.

**What Term Groups do:**

- Group tags, categories, and custom taxonomy terms from any taxonomy into named groups
- Each group has its own archive page, showing posts from all member terms
- Terms can belong to multiple groups simultaneously
- Groups support parent/child hierarchy: terms that are members of a child group are automatically treated as members of its parent group as well
- A term can include a whole group, which includes every member from the term's own taxonomy
- A term can also have container groups: its posts then appear in the archive of every member of those groups and of their parent groups, in the term's own taxonomy (reverse inclusion)

**The key insight:** Term Groups let the same term exist in multiple organizational structures simultaneously without changing its primary hierarchy.

**Example — Development topics:**

```
Primary hierarchy (categories): Frontend > React, Vue
                                Backend  > Node.js, Django

Term Groups (alternative perspectives):
  JavaScript Ecosystem: React, Vue, Node.js  ← spans both primary categories
  Python Ecosystem: Django
```

"React" is a child of "Frontend" (the layer view) and a member of "JavaScript Ecosystem" (the language view). Both perspectives coexist independently. The `/category/frontend/` archive shows frontend content; `/group/javascript-ecosystem/` shows content across the JavaScript ecosystem.

To create groups and assign terms to them, see [Term Groups](term-groups.html).

## Relationship Types

Relationships are set up on the term edit screen; [Term Relationships](term-relationships.html) covers each type in detail.

**[Inclusion / Container](term-relationships.html#inclusions-and-containers)** — Term A includes Term B: every post tagged with Term B automatically appears in Term A's archive. Seen from B, A is its container. It's one relationship, set up from either term: adding B to A's "included terms" is identical to adding A to B's "container terms." Inclusions are **non-transitive**: A including B and B including C does not make A include C. Each link is explicit and independent, so every archive stays focused on exactly the content you chose.

**[Synonyms](term-relationships.html#synonyms)** — When Term A includes Term B and Term B includes Term A, directly or through groups, they become synonyms. Each archive shows the posts assigned to the other term, but not the posts of terms that only the other one includes: synonyms share posts, not relationships. Both terms retain separate identities, archive URLs, and positions in the hierarchy. **Terms → Synonyms** lists every pair.

**[Group Inclusions](term-relationships.html#group-inclusions)** — Instead of including individual terms one by one, a term can include an entire Term Group. All current and future group members from the term's own taxonomy are included automatically — no maintenance as the group grows.

**[Container Groups](term-relationships.html#container-groups)** — A term specifies Term Groups as its "containers," and each current and future member of those groups in the term's own taxonomy then includes it, so its posts flow into their archives (reverse inclusion). This is a separate relationship from a group inclusion, pointing the other way: a group inclusion brings every member's posts into one term's archive, while a container group puts one term's posts into every member's archive. Useful when one term's posts belong in many archives (see [Which to Use When](#which-to-use-when)).

**[Exclusions](term-relationships.html#exclusions)** — Any term can be explicitly removed from the included set. Exclusions always win regardless of which inclusion source added the term.

**[Redirects](term-relationships.html#redirects)** — Term A 301-redirects to Term B. Visitors to Term A's archive URL are sent to Term B's archive. Term A has no accessible archive of its own; all stored relationships resume if the redirect is later removed.

**[Custom Relationship Types](term-relationships.html#custom-relationship-types)** — User-defined relationships beyond the built-in types. Inclusion-based custom types participate in archive expansion automatically (e.g. "Alternate name"). Other custom types store the relationship only and leave behavior to your theme or plugin (e.g. "Related"). Storage-only types can be **symmetric** (same label in both directions, single unified list in the term editor) or **asymmetric** (distinct forward and inverse labels, two separate panels).

## How Inclusions Behave

Inclusions are non-transitive by design: unlike WordPress's native category hierarchy (where a post in a child category automatically appears all the way up the tree), HyperTaxonomy keeps each archive focused on exactly the content you defined for it. Two terms that include each other mutually form synonyms, sharing content while remaining independent.

HyperTaxonomy inclusion and container relationships connect terms within one taxonomy and, in a hierarchical taxonomy, only across branches. A term can't include itself, its own ancestors or descendants: its descendants' posts already appear in its archive through the WordPress native hierarchy, and including an ancestor would result in an infinite loop. Such terms are also disregarded in group inclusions and container groups. See [Term Relationships](term-relationships.html#inclusions-and-containers) for the full rules.

---

## Which to Use When

Several of these tools can produce similar results. Here is how to choose between them:

**Inclusion vs parent term?** Both bring one term's posts into another term's archive. A parent does it through a hierarchical taxonomy's tree: each term has one parent, and the parent's archive shows the posts of all its descendants. An inclusion works in any taxonomy, including flat ones like tags, which have no parents at all; in a hierarchical taxonomy it connects terms in different branches. A term can be included in any number of archives. Inclusions must be explicit: if A includes B and B includes C, A's archive doesn't show C's posts. To include a whole set of terms, include a Term Group instead of each term: every current and future member from the same taxonomy is included. Use a parent for narrower kinds of a broader topic (Frontend > React). Use an inclusion for tags (a JavaScript tag including React and Node.js tags), or when a category also belongs under a topic in another branch (a Full-Stack category including React and Node.js). Include a group when that topic is defined by a set you keep up to date (Full-Stack including the JavaScript Ecosystem group).

**Including a term into multiple terms vs setting a container group?** Sometimes one term's posts belong in many archives: posts in a JavaScript Testing category are useful in the React, Vue and Node.js archives. You could add JavaScript Testing to each of those terms' included terms, but every framework added later needs the same inclusion, and a framework you stop covering keeps it until someone removes it. A container group does it in one setting: give JavaScript Testing the container group JavaScript Ecosystem, and its posts appear in the archive of every member from the same taxonomy. When a new framework joins the group, its archive gains them; when a term leaves the group, its archive loses them. Use a container group when the archives that need a term's posts are already a group, or are worth making one; include the term individually when only a few unrelated archives need it. See [Modeling Guide → Container Groups](modeling-guide.html#pattern-5--container-groups-reverse-inclusion).

**Inclusion vs synonym?** An inclusion is one-directional: A includes B means B's posts appear in A's archive, but A's posts don't appear in B's. A synonym is mutual: add A to B's included list AND B to A's included list. Both archives then show posts from either term. Use inclusions for broader/narrower relationships (Programming → JavaScript); use synonyms for terms that name the same concept (JS and JavaScript).

**Inclusion vs redirect?** Inclusion expands an archive — both terms remain visible and functional, each with its own URL and archive. A [redirect](term-relationships.html#redirects) replaces an archive — visitors to the old URL are sent to the target term's archive. The redirect term still appears on posts and in term lists (in *italics* by default); [term list settings](term-lists.html#common-parameters) decide where it links, or can leave it out. Use inclusions when both terms should remain first-class navigation items; use redirects when you are consolidating or renaming and want the old URL to become an alias.

**Redirect vs merge?** Both consolidate terms. A redirect keeps both terms in the database and creates a permanent 301 forward — reversible; the original term still exists. A [merge](merge-convert.html#merge-terms) physically reassigns all post relationships and deletes the source term — irreversible. Use redirects for ongoing aliases; use merge for permanent cleanup of duplicate terms.

**Sticky post vs sort order override?** A [sticky post](per-term-settings.html#sticky-posts) is pinned to the top of one specific term archive — useful for editorial choices ("always show the glossary at the top of this tag"). If what you want at the top is introductory text rather than a post, [show the term's description](per-term-settings.html#archive-descriptions) on its archive instead: it isn't a post, so it doesn't appear in feeds, search results or other archives. A [sort order override](per-term-settings.html#archive-settings) changes the ordering algorithm for all results on a term archive — useful for making comment activity or modification date the primary ranking signal. They can coexist: the sticky appears first, then the sort order applies to everything else.

**PowerSearch vs Search Widget?** [PowerSearch](powersearch.html) is text-driven: a user types a query with field operators and gets relevance-ranked results across post content and taxonomy names. The [Search Widget](search-widget.html) is facet-driven: a user selects taxonomy terms from pills or checklists and narrows results by tag, category, or custom taxonomy. They can appear on the same page and complement each other.

**Hidden term vs draft posts?** A [hidden term](per-term-settings.html#term-visibility) controls taxonomy navigation visibility — unauthorized users don't see the term in archives, shortcodes, or search results, but the posts themselves remain publicly accessible through direct URLs. Draft posts hide the posts themselves. Use hidden terms for curator-only filing systems (internal categories, editorial flags) where the posts are public but the organizational structure isn't.
