---
title: Why HyperTaxonomy
nav_order: 2
---

# Why HyperTaxonomy

WordPress organizes content with taxonomies, and offers two kinds: flat ones like tags, and trees like categories. Both make you choose where a term belongs, and both lose content when a term belongs in more than one place. This page shows the problem with one example, and how HyperTaxonomy solves it.

## Tags have no structure

Tags don't know about each other. A post tagged *Dog* doesn't appear in the *Canines* archive, even though every dog is a canine. To get it there, every dog post has to be tagged *Canines* too, by hand, and every editor has to remember to do it. Archives drift out of step with each other as soon as someone forgets.

## A tree answers one question

A category tree fixes that for one structure. Sort animals by descent, and *Dog* sits under *Canines*, under *Mammals*: a post filed under *Dog* appears in all three archives. The tree works because every level answers the same question: what is this animal descended from?

But a dog is also a service animal, a role it shares with miniature ponies. And a dog needs regular vaccinations, as cats and ferrets do. Those are different questions, *what work does it do?* and *how must it be cared for?*, and their answers don't fit the descent tree. Mixing them into it would break it: a miniature pony isn't a canine.

So each question needs its own tree. But a category has only one parent, so *Dog* can sit in only one of them. Every other tree gets its own duplicate *Dog*:

![Three category trees. By descent: Mammals has Canines (Dog, Wolf), Felines (Cat), Equines (Mini pony) and Mustelids (Ferret). By role: Service animals has a second Dog and a second Mini pony. By care: Pets requiring vaccination has a third Dog, a second Cat and a second Ferret. Every animal outside the first tree is a duplicate term.](assets/images/why-trees.svg)

The duplicates aren't connected. A post about guide dogs filed under the service-animal *Dog* never appears in the *Canines* archive, and a post filed under the descent tree's *Dog* never appears among service animals. To show a post everywhere it belongs, an editor has to file it under every copy, which is the tag problem again.

Custom taxonomies don't help. Each one is either flat or a tree, with the same limits.

## One term, many structures

HyperTaxonomy lets a term belong to as many structures as it needs, without copies. Keep the descent tree as it is. Add *Service animals* and *Pets requiring vaccination* as categories in the same taxonomy, and have each one **include** the terms that belong to it:

- *Service animals* includes *Dog* and *Mini pony*.
- *Pets requiring vaccination* includes *Dog*, *Cat* and *Ferret*.

There is still one *Dog*. Each structure is a set, and the sets overlap wherever the animals do:

![A Venn diagram of three overlapping sets: Canines (Dog, Wolf), Service animals (Dog, Mini pony) and Pets requiring vaccination (Dog, Cat, Ferret). Dog sits where all three overlap.](assets/images/why-venn.svg)

A post filed only under *Dog* now appears in the *Canines*, *Service animals* and *Pets requiring vaccination* archives. A post filed under *Cat* appears under *Felines* and *Pets requiring vaccination*. Each structure keeps its own question intact, and nobody re-tags anything. When vets start recommending vaccines for miniature ponies too, adding *Mini pony* to *Pets requiring vaccination* brings every existing pony post into that archive at once, and every future one.

## Beyond inclusion

Inclusion is the main tool, but HyperTaxonomy adds others for the same kind of problem:

- **Synonyms:** two terms that include each other, like *USA* and *United States*, share all their posts.
- **Term Groups:** a named set of terms that a term can include as a whole. If *Service animals* includes a *Guide animals* group, every animal added to the group joins the service-animal archive.
- **Redirects:** an outdated term sends visitors to its replacement with a permanent redirect, keeping its search ranking.
- **Your own relationship types:** name the relationships your subject needs, of two kinds.
  - An **inclusion-based** type works like inclusion under a name of your choosing: a veterinary site could say *Rabies vaccine* **protects** *Dog*, and the *Rabies vaccine* archive and search results pick up dog posts exactly as with *includes*.
  - A **custom** type only records the connection, for example *Dog* **can live with** *Cat*. HyperTaxonomy stores it, shows it in the term editor and returns it through its API, and your theme or plugin decides what it does, such as listing compatible pets on an animal's page.

  To create them, see [Relationship Types and Term Fields](user-guide/term-relationships.html#relationship-types-and-term-fields); for the API, see [Custom Relationship Types](developer-reference/custom-taxonomy.html#custom-relationship-types).
- **Relevance:** HyperTaxonomy records how central each term is to each post, so search results and related posts rank a dedicated dog-training guide above a post that mentions dogs in passing.

## Where to next

- **[Getting started](./#getting-started)** — install the plugin and set up your first inclusion.
- **[A web of concepts](https://hypertaxonomy.org/vision.html)** — the essay behind the whole suite: why connected terms matter, within a site and across the web.
- **[Concepts](user-guide/concepts.html)** — every relationship type, and how to choose between features.
- **[Modeling Guide](user-guide/modeling-guide.html)** — patterns for structuring real content.
