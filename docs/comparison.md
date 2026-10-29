---
title: vs. PublishPress Tag Groups
nav_order: 7
---

# Feature Comparison: HyperTaxonomy vs. Tag Groups

Comparing **HyperTaxonomy** with **Tag Groups** (rebranded "PublishPress Tag Groups" in October 2026) — a popular plugin, commonly used for taxonomy term organization and management.

**Legend:** ✓ Included · ~ Partial/limited · ✗ Not supported

---

## Term Grouping

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| Term groups | ✓ | ✓ |
| Term may belong to multiple groups | ✓ | ✓ *(TG Pro)* |
| Hierarchical groups (parent/child) | ✓ | ✓ *(TG Pro)* |
| Supports categories | ✓ | ~ *(TG, experimental)* |
| Supports custom taxonomies | ✓ | ✓ |
| Groups implemented as native WordPress taxonomy | ✓ | ✗ |
| Group archive pages | ✓ | ✗ |

**Term may belong to multiple groups.** HyperTaxonomy supports a term belonging to multiple groups simultaneously. Tag Groups Pro also supports this; the Free version silently discards all but the first group assignment.

**Hierarchical groups.** HyperTaxonomy uses WordPress's native term hierarchy for group parent/child relationships. Tag Groups Pro adds a parent group UI and expands parent groups in shortcode filtering.

**Categories.** HyperTaxonomy fully supports the built-in Categories taxonomy. Tag Groups treats hierarchical taxonomies (including Categories) as experimental: they can be enabled via a settings checkbox, but are explicitly flagged as unsupported in both Free and Pro.

**Groups as a native WordPress taxonomy.** HyperTaxonomy Term Groups is a standard WordPress taxonomy. Groups are full terms with IDs, slugs, descriptions, and a native hierarchy — automatically available through the WordPress REST API and queryable like any taxonomy. WordPress's built-in enforcement of acyclic hierarchies means group structures are guaranteed loop-free.

**Group archive pages.** Since Term Groups is a native taxonomy, each group has a browsable archive URL that automatically surfaces posts from all its member terms — the same query expansion applied to standard taxonomy archives. A group like "JavaScript Ecosystem" can serve as a standalone collection page without any custom code. Tag Groups has no equivalent: groups have no WordPress term representation and therefore no archive URLs.

Tag Groups stores groups as plugin-specific data outside the WordPress taxonomy system, with its own custom REST API rather than the standard WordPress taxonomy endpoints.

---

## Term Relationships

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| Non-transitive term inclusion | ✓ | ✗ |
| Term synonyms (bidirectional inclusion) | ✓ | ✗ |
| Term group inclusion | ✓ | ✗ |
| Reverse term inclusion/container groups | ✓ | ✗ |
| Term redirects | ✓ | ✗ |
| Custom relationship type registry | ✓ | ✗ |

**Term Relationships.** Semantic relationships between terms are a unique feature of HyperTaxonomy, which goes beyond simply grouping related terms for organizational purposes. Besides structural relationships based on inclusion/exclusion, HyperTaxonomy supports behavioral relationships such as redirects, and provides a Relationship Type Registry that plugin authors can use to define additional types.

**Custom relationship type registry.** Domain-specific relationship types may be created without modifying the plugin code. Two kinds are supported: *inclusion-based* types automatically participate in archive expansion and search indexing logic, identically to the built-in inclusion/exclusion — while *custom* types store the association, leaving behavior implementation to the registering plugin.

**Redirect term handling in display.** In all term display blocks, redirect terms are italicized by default and can be shown, hidden, or styled independently. Click behavior is configurable: navigate to the redirect stub (which then 301-redirects), jump directly to the target, scroll to an anchor, or do nothing.

---

## Search & Filtering

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| MySQL FULLTEXT search for posts | ✓ | ✗ |
| Indexes and searches by term name or description | ✓ | ✗ |
| Search automatically extends to related terms | ✓ | ✗ |
| Enhance native WordPress search | ✓ | ✗ |
| Use taxonomy terms as search filters | ✓ | ~ *(TG Pro)* |
| User-facing sort and filter controls | ✓ | ~ *(TG Pro)* |

**Term name and description search.** Searching matches posts whose assigned term names or descriptions contain the query word, in addition to title and body content.

**Enhance native WordPress search.** HyperTaxonomy can optionally replace WordPress's built-in search with its PowerSearch engine, applying relevance scoring, term-based expansion, and field operators to all site searches without any theme changes.

**Term-based filtering.** Searches can be scoped to specific fields (title, body, terms, categories, and more). The Advanced Search Widget lets visitors select taxonomy terms as facets — checklist, pills, or dropdown — combined with keyword search.

**Sort and filter controls.** Post list and search result shortcodes support a pill-based sort picker and a collapsible filter panel (taxonomy terms, date range, author), with AJAX re-query on change. Tag Groups Pro's shuffle box filters client-side on pre-rendered HTML — no sort controls, no keyword integration, and a practical limit on result-set size.

---

## Display Shortcodes & Blocks

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| Term list | ✓ | ✓ |
| Term cloud | ✓ | ✓ |
| Term index (A–Z with letter anchors) | ✓ | ✓ |
| Term group hierarchy | ✓ | ✓ *(TG Pro)* |
| Post list by specified terms or groups | ✓ | ✗ |
| Related posts | ✓ | ✗ |
| Tabbed display layout | ✗ | ✓ |
| Accordion display layout | ✗ | ✓ |
| Table layout | ✗ | ✓ *(TG Pro)* |
| Search/filter within term display | ✗ | ✓ *(TG Pro)* |

**Term display.** Four shortcodes cover standard term presentation: `[ht_term_list]`, `[ht_term_cloud]`, `[ht_term_index]` (A–Z with letter anchors), and `[ht_term_groups]` (collapsible group tree). All support icons and redirect-term handling; each has a Gutenberg block. HyperTaxonomy does not have Tag Groups' tabbed, accordion, or table layouts, or client-side filter within term display.

**Post list and related posts.** `[ht_post_list]` displays posts for any combination of terms or Term Groups; `[ht_related_posts]` derives the term set from the current post. Both factor in term inclusion relationships so semantically related posts surface without exact tag matches, and support relevance-based sort where posts that centrally cover the queried term rank above posts that mention it only in passing. Tag Groups has no equivalent.

---

## Archive & Query Behavior

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| Include related terms in archive query | ✓ | ✗ |
| Sticky posts specific to term archive | ✓ | ✗ |
| Custom term archive description | ✓ | ✗ |
| Custom term archive pagination | ✓ | ✗ |
| Custom default sort order per term archive | ✓ | ✗ |
| User-facing sort and filter controls on archive pages | ✓ | ✗ |
| Post count threshold for term display | ✓ | ✓ |
| Term Group filter panel on native Query Loop and Latest Posts blocks | ✓ | ✗ |

**Related terms in archive query.** When an archive shows posts from included terms, HyperTaxonomy updates the archive heading to list the contributing terms. Tag Groups does not expand archive queries.

**Per-term archive customization.** Four per-term controls: sticky posts (pin specific posts regardless of sort order), archive description, per-term pagination, and default sort order (overridable by visitors at runtime). None exist in Tag Groups.

**Sort and filter controls on archive pages.** A sort picker and collapsible filter panel can be placed on any archive template, giving visitors runtime control over what they see. Tag Groups has no equivalent.

**Query Loop and Latest Posts block integration.** HyperTaxonomy adds a Term Group filter panel to the inspector of the native Query Loop and Latest Posts blocks, restricting the query to posts from selected groups (OR logic, subgroup-inclusive). Tag Groups has no block inspector integration.

---

## Term Management

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| Hidden terms with role-based visibility controls | ✓ | ✗ |
| Quick Edit (term list table) | ✓ | ✗ |
| Bulk actions on terms | ✓ | ✗ |
| Merge terms | ✓ | ✗ |
| Handle term taxonomy conversion | ✓ | ✗ |
| Concurrent term edit locking with take-over flow | ✓ | ✗ |
| Unsaved-changes warning when navigating away from term edit | ✓ | ✗ |
| Content-based tag suggestions in post editor | ✓ | ✗ |
| Prevent accidental term creation in post editor | ✓ | ✗ |
| Term icons (media library images and placeholders) | ✓ | ✗ |

**Hidden terms.** HyperTaxonomy allows admins to hide individual terms. Visibility is role-based: configured roles can see hidden terms while others cannot. Hidden terms remain accessible to authorized users and still appear in archives for those users.

**Quick Edit (term list table).** HyperTaxonomy extends the WordPress term list table Quick Edit row with a visibility checkbox and a Term Groups checklist, both pre-populated from the term's current values.

**Bulk actions on terms.** WordPress core provides bulk delete on every term list table. HyperTaxonomy adds bulk actions for its own metadata: bulk set/clear visibility, bulk remove icons, and bulk assign to Term Groups (the last via a modal group selector that opens inline).

**Merge terms.** HyperTaxonomy can permanently reassign posts from source terms to a target term and delete the sources. Group memberships and inclusion/exclusion relationships are transferred to the target, and optional redirects can be created so old archive URLs continue to work.

**Term taxonomy conversion.** HyperTaxonomy converts terms between any two post taxonomies. The original term optionally stays in the old taxonomy as a redirect stub, keeping its slug, icon, group memberships, and hierarchy position intact so old archive URLs continue to work and the conversion is reversible. Sticky post assignments are moved to the new term. Tag Groups has no equivalent — group assignments are lost on conversion.

**Content-based tag suggestions.** In the Gutenberg post editor, HyperTaxonomy replaces WordPress's site-wide "most used tags" suggestions with tags matched to the current post's content. Suggestions are drawn from unassigned tags whose names appear in the post's title (shown first), excerpt, then body, each group ordered by occurrence count. A configurable ignore-words list excludes generic terms from matching.

**Prevent accidental term creation.** An optional setting blocks new terms from being created via the post editor's tag input. Existing terms can still be assigned freely; only creation of new ones is blocked.

**Term icons.** HyperTaxonomy allows assigning a media library image to any term. Where no image is assigned, a color placeholder is shown instead. Icons appear in term lists, clouds, and index displays, and alongside term labels in search widget filter controls.

---

## Import & Migration

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| JSON export | ✓ | ~ *(TG Pro)* |
| JSON import | ✓ | ~ *(TG Pro)* |
| Import term groups from other plugins | ✓ | ✗ |

HyperTaxonomy's JSON format covers groups, memberships, inclusions, exclusions, and redirects. It uses term slugs rather than IDs for cross-site portability, and includes a dry-run preview before import.

**Tag Groups Pro export.** Tag Groups Pro can export its group configuration and term assignments as JSON, but the format is specific to its own data model and cannot be imported into HyperTaxonomy directly.

**Import from other plugins.** HyperTaxonomy can read group and membership data left behind by Tag Groups — even if that plugin has been deactivated — to facilitate migration.

---

## Developer Integration

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| Term Icons API for theme templates | ✓ | ✗ |
| Term Groups via standard WordPress REST API | ✓ | ✗ |
| PHP API for relationships, groups, and visibility | ✓ | ~ |
| Automatic expansion of third-party WP_Query instances | ✓ | ✗ |
| Term relationship and visibility data in standard WordPress REST responses | ✓ | ✗ |
| Custom relationship types via shared table | ✓ | ✗ |
| Actions and filters for relationship changes | ✓ | ~ |

**Term Icons in theme templates.** A PHP API allows displaying term icons anywhere in a theme — post templates, archive headers, sidebars — without using shortcodes. Icons can also be injected into WordPress's native term link output without modifying theme markup.

**Term Groups via the WordPress REST API.** Because Term Groups is a native WordPress taxonomy, all standard WordPress REST API conventions apply automatically — no custom endpoints needed. HyperTaxonomy also enriches standard term REST responses with additional fields for icons, redirects, inclusions, and visibility, present only when they carry a value so existing REST clients are unaffected.

**Automatic expansion of third-party queries.** When enabled, HyperTaxonomy automatically applies term relationship expansion to queries from third-party plugins — faceted navigation, page builder loops, and similar — so they reflect the same semantic relationships as native archives. Tag Groups' query modifications are limited to the main WordPress archive query.

**PHP API for relationships.** Functions give plugin authors read access to the full relationship graph — included terms, group memberships, sticky posts, visibility state. Group assignments and visibility can also be written programmatically. See the [Developer Reference](https://hypertaxonomy.org/hypertaxonomy/developer-reference/) for the full reference.

**Custom relationship types.** Third-party plugins can register additional relationship types without modifying HyperTaxonomy. Inclusion-based types automatically inherit archive expansion and search behavior; custom types store the association only, leaving behavior to the registering plugin. Types can be registered in code or through the admin screen. See [Custom Relationship Types](https://hypertaxonomy.org/hypertaxonomy/developer-reference/custom-taxonomy.html#custom-relationship-types) in the Developer Reference.

---

## Performance Architecture

| Feature | HyperTaxonomy | Tag Groups |
|---------|:---:|:---:|
| Denormalized search index for archive and post list queries | ✓ | ✗ |
| Per-relationship strength score (how centrally each post covers the term) | ✓ | ✗ |
| Pre-computed indirect post–term relationships (container, hierarchy, redirect) | ✓ | ✗ |
| Incremental index updates on term assignment change | ✓ | ✗ |
| Targeted transient invalidation (no TTL, event-driven) | ✓ | ~ |
| Per-request static cache (avoids repeated DB calls within a page load) | ✓ | ~ |
| Post list shortcode result caching with targeted invalidation | ✓ | ✗ |

**Denormalized search index.** HyperTaxonomy pre-computes and stores post–term relationships in a dedicated index, including indirect relationships (hierarchy, inclusions, redirects). Each relationship row also carries a **strength score** — a measure of how centrally the post covers that term, derived from term-name frequency in the post's title, excerpt, and body. Search ranking and post-list ordering use this score so a post that is primarily about a topic ranks above one that only mentions it in passing. Archive and post list queries read directly from this index rather than joining across the core WordPress taxonomy tables at runtime. Tag Groups relies on WordPress's native query infrastructure.

**Incremental index updates.** The index is kept current in real time as post–term assignments change — only affected entries are updated. A full reindex is needed only on first activation or after data migrations.

**Targeted cache invalidation.** Caches are keyed per-term, so only the caches for affected terms are cleared when relationships change — nothing is flushed globally. Tag Groups uses broader-scope time-based expiry.

**Post list result caching.** Post list and related posts results are cached indefinitely and invalidated precisely when the underlying assignments, relationships, or group memberships change.

---

## Complementary Plugins

The plugins below address capabilities outside the scope of this comparison and work alongside any of the plugins compared above.

**Taxonomy and post type registration.** HyperTaxonomy enriches existing taxonomies, but does not provide a mechanism to register new ones. [PublishPress Taxonomies](https://publishpress.com/publishpress-taxonomies/) (rebranded from "TaxoPress" in September 2026) includes a taxonomy builder and content automation (auto-tagging, auto-linking, AI suggestions) and can work naturally alongside HyperTaxonomy. [Custom Post Type UI (CPTUI)](https://wordpress.org/plugins/custom-post-type-ui/) is a dedicated free plugin for registering custom post types and taxonomies. [Pods](https://wordpress.org/plugins/pods/) covers the same ground and additionally supports custom fields on posts, pages, and taxonomy terms in a single interface. [ACF](https://www.advancedcustomfields.com/) can also register taxonomies alongside its field management.

**Custom fields on term edit screens.** None of the plugins in this comparison add custom metadata fields to the taxonomy term edit screen natively. [ACF](https://www.advancedcustomfields.com/) and [Pods](https://wordpress.org/plugins/pods/) both support field groups targeting taxonomy terms and are compatible with any taxonomy plugin.

---

## Recommendation

**Choose HyperTaxonomy** when taxonomy is a core part of the content model. The features where it leads — semantic term relationships, FULLTEXT search, archive query expansion, per-term archive customization, and a denormalized search index — are foundational: they affect every archive page and search result across the site. A well-built taxonomy compounds in value over time as inclusions, synonyms, and group memberships make it progressively more meaningful without manual re-tagging.

**Choose Tag Groups** when rich display layouts — tabbed panels, accordions, a live-filter search box inside the term list — are the primary requirement and semantic relationships are not needed.

**Using both together.** This isn't necessarily an either/or choice. HyperTaxonomy's Migrate and Sync tools (Export/Import → Migrate / Sync) read Tag Groups' group and term-assignment data directly from its database tables and keep HyperTaxonomy's native Term Groups taxonomy in step with it — as a one-time migration, or continuously. Under Locked integration mode, Tag Groups can remain the day-to-day tool for creating groups and assigning terms to them (its own field is promoted to the term edit screen's primary group-assignment spot), while HyperTaxonomy layers archive pages, search indexing, hierarchy, and relationships on top of the same group structure. See [Import & Migration](https://hypertaxonomy.org/hypertaxonomy/user-guide/import-migration.html) for the integration modes and sync options.

**TaxoPress** addresses a different problem entirely and is a natural complement to HyperTaxonomy rather than an alternative. TaxoPress (now PublishPress Taxonomies) and Tag Groups (now PublishPress Tag Groups) are both published by PublishPress as of their respective late-2026 rebrands, but remain independent plugins with no cross-dependency.

For guidance on combining HyperTaxonomy with other plugins, see [Best Practices § Working Alongside Other Plugins](https://hypertaxonomy.org/hypertaxonomy/user-guide/best-practices.html#6-working-alongside-other-plugins).
