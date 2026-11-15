---
title: Caching
parent: Architecture
nav_order: 5
---

# Caching
{: .no_toc }

HyperTaxonomy keeps its relationships, index and settings in the database (see [Data Storage](data-storage.html)) and caches what it derives from them in transients. This page covers how those transients are keyed, what each one holds, and what clears it.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Cache layers

A common concern when combining multiple plugins is query overhead. HyperTaxonomy is designed to minimize this.

`[ht_post_list]`, Related Posts block, and search queries run against the pre-computed `wp_htax_search_terms` index, where term inclusions are computed into the index at write time. Term archives are standard WordPress queries with an expanded `tax_query`, using cached lookups of each term's included terms.

Two caching layers sit above the database:

1. **Per-request static cache** — relationship lookups and term data resolved during a single page load are held in static request-scope arrays. Most are unbounded for the duration of the request; `htax_get_valid_term()` specifically uses a bounded cache capped at 500 entries with FIFO eviction.

2. **WordPress transients** — `[ht_post_list]` and Related Posts block results are cached as transients (for 1 hour by default) with targeted invalidation. A cached post list result is invalidated when the term assignments, term relationships, or group memberships that affect it change, not on every post save.

These layers keep the number of extra queries low on cached page loads. PowerSearch adds its own queries per search request.

## How transients are stored

**External object caches:** all HyperTaxonomy code reads and writes transients through `htax_get_transient()` / `htax_set_transient()` / `htax_delete_transient()`, never the core functions directly. Bulk invalidation is a `DELETE … LIKE` on `wp_options`, which cannot reach transients held by a persistent external object cache (Redis, Memcached) — and many of these caches have no TTL. So when `wp_using_ext_object_cache()` is true, `htax_transient_key()` appends a generation token to every `htax_*` key: one global token plus one per family (`htax_transient_family()`: `rel` for the relationship caches, `contrib`, `pl`, `total`, `ac`, `sticky`, `misc`). Every bulk clear ends with `htax_bump_cache_family()`, which starts a new generation for the family (`all` from `htax_clear_all_caches()`), making every existing key in it unreachable; the cache's own eviction reclaims them. Pattern clears that are per-term in the database (`htax_invalidate_contrib_cache()`, `htax_invalidate_post_list_cache()`, `htax_delete_count_cache_for_ids()`) bump the whole family, since object-cache keys can't be matched by pattern. Tokens are random, so an evicted token is replaced rather than reset and never revalidates old entries. Without an external object cache keys are unchanged, and `htax_bump_cache_family()` instead drops WordPress's runtime `options` cache group so code later in the same request doesn't read the deleted values.

**Autoloading:** a transient stored without expiry is normally autoloaded, so WordPress loads it on every request. HyperTaxonomy keeps one cache per term and family (inclusions, containers, exclusions, redirect target, groups, direct relationship rows), so their number grows with the vocabulary. Without an external object cache, `htax_set_transient()` and the batch writer store these per-object caches (`htax_is_per_object_transient()`: inclusions, containers, groups and counts always; exclusions, redirect targets and relationship rows while the relationship cache duration is 0, the default; sticky posts while the sticky duration is 0 — families that can have an expiry otherwise keep the transients API and its timeout check) with autoload off, and `htax_get_transient()` reads them with `get_option()`, skipping the timeout lookup they never have. Code that reads many of them at once loads them in one query first with `htax_prime_transients()` / `htax_prime_term_transients()`: the expanded term counts (`htax_adjust_term_counts()`), the term list screens (`htax_prime_term_list_caches()`), group member lookups (`htax_load_terms_in_groups()`) and the Synonyms page. Site-wide caches (such as `htax_hidden_terms`) and group member lists (`htax_grp_*`, which grow with the number of groups, not terms) stay autoloaded.

**Sweeps once per request:** the pattern deletes for a term's post lists (`htax_invalidate_post_list_cache()`) and contributing terms and count (`htax_invalidate_contrib_cache()`) remember the terms they've swept in the request (`post_lists_swept`, `contrib_swept` request caches) and skip repeats, since several handlers reach the same term in one save. Writing such a cache in the same request resets the record (`htax_set_transient()`), so a later change sweeps again.

**Per-request copies:** some getters also keep a per-request copy of a transient's value (`htax_request_cache()` groups `redirect_target` for `htax_redir_*`, `redirect_term_ids` for `htax_redirect_terms`). Whenever one of these transients is deleted — per term in `htax_delete_term_transients()` / `htax_delete_term_transients_batch()` / `htax_clear_redirect_target_cache()`, or in bulk in `htax_clear_relationship_caches()` / `htax_clear_all_caches()` — the matching per-request copy is cleared too, so later code in the same request (notably the search index handlers on `htax_redirect_changed` / `htax_redirect_removed`) reads the new state.

## Cached data

The [computed term meta](../developer-reference/term-meta.html) reads the relationship caches below through the same loaders and keeps no cache of its own, so clearing them updates it too.

- `htax_inc_{term_id}` / `htax_cont_{term_id}` / `htax_excl_{term_id}` - The IDs of a term's included terms (ordered by name), container terms (ordered by name), and excluded terms. IDs rather than term objects, because term objects are many times larger and a page can read many of these rows; the loaders turn them into terms with one batched query when a caller needs objects, and a cached value in any other format counts as a miss
- `htax_redir_{term_id}` / `htax_redirect_terms` - A term's redirect target, and all term IDs that have a redirect
- `htax_groups_{term_id}` - The groups a term belongs to
- `htax_grp_{group_id}_{taxonomy}` - The IDs of a group's member terms for a taxonomy. IDs rather than term objects, because these rows can load on every page
- `htax_hidden_terms` - All hidden term IDs
- `htax_sticky_{term_id}` - A term's validated sticky post IDs
- `htax_total_*` - Post totals for the `{total_count}` placeholder in shortcode text
- `htax_ft_{hash}` - PowerSearch results, for 60 seconds
- `htax_tcount_{term_id}[_h]` - A term's expansion-aware post count for `htax_adjust_term_counts()` (`_h`: for viewers who can see hidden terms; `-1`: no HyperTaxonomy contributions, keep the native count). Per-term, so not autoloaded. Cleared with the term's contributing-terms cache by `htax_invalidate_contrib_cache()`, which every archive change reaches (including a change of visibility of a term whose posts the archive shows, since visitors' counts leave hidden terms out), and in bulk with it
- `htax_contrib_{term_id}_p{page}[H]` - Contributing terms by page (suffix `H` when viewer can see hidden terms). Stores an array of term name strings used to build the archive title. Invalidated via `htax_invalidate_contrib_cache()` (one term or several in one query), which is hooked to `htax_term_archive_changed` — the unified signal that covers relationship changes, first-redirect, redirect removal, term hierarchy changes (including for terms that include an old or new ancestor, since an included term's archive expansion covers its descendants), post assignments, and sticky post changes. Also cleared for the archives that show a term's posts when its visibility changes (`htax_invalidate_archives_on_visibility_change()`) or its name changes (`htax_handle_term_rename()`), since the cache stores names; those archives come from `htax_expand_term_ids_for_contrib_invalidation()`: the term, its ancestors, and the terms that include either. Also invalidated directly when a term's `posts_per_page` setting changes, and for every term when the site's Blog pages show at most setting (`posts_per_page`) changes, since page boundaries shift
- `htax_crel_{slug}_{term_id}` - Outgoing custom relationship rows for a term and type, with group targets already expanded to member terms (non-core types only). No TTL. Invalidated by: `htax_add_term_relationship` / `htax_remove_term_relationship` (source's cache); `htax_save_term_relationships` edit form path for changed sources; `htax_delete_term_transients` for the term itself; `htax_invalidate_term_caches_on_delete` for source terms that had a row pointing to the deleted term; type-prefix clear on type save/delete; `htax_invalidate_group_relationship_caches` when group membership or hierarchy changes.
- `htax_crel_rev_{slug}_{term_id}` - Incoming custom relationship rows for a term and type, with group sources already expanded to member terms (non-core types only). No TTL. Invalidated by: `htax_add_term_relationship` / `htax_remove_term_relationship` (target's cache); `htax_save_term_relationships` edit form path for changed targets; `htax_delete_term_transients` for the term itself; `htax_invalidate_term_caches_on_delete` for target terms that had a row from the deleted term; type-prefix clear on type save/delete; `htax_invalidate_group_relationship_caches` when group membership or hierarchy changes.
- `htax_direct_rel_{slug}_{term_id}` - Raw T→U rows for a relationship type, non-group targets only. Works for any slug including core `'includes'`. No TTL. Invalidated by `htax_delete_custom_rel_caches_for_term` (called from `htax_delete_term_transients`). Used by the term edit screen and the PRE-CLEAR warm-cache path in `htax_invalidate_term_caches_on_delete`.
- `htax_reverse_rel_{slug}_{term_id}` - Raw A→T rows for a relationship type, non-group sources only. Works for any slug including core `'includes'`. No TTL. Invalidated by `htax_delete_custom_rel_caches_for_term`. Used by the term edit screen and surgically by `htax_invalidate_term_caches_on_delete` to clear a target's cache when its source term is deleted.
- `htax_direct_rel_grp_{slug}_{term_id}` - Raw T→G rows for a relationship type, group targets only. Works for any slug including core `'includes'`. No TTL. Invalidated by `htax_delete_custom_rel_caches_for_term`. Used by the PRE-CLEAR warm-cache path in `htax_invalidate_term_caches_on_delete` to avoid a DB query for group targets.
- `htax_reverse_rel_grp_{slug}_{term_id}` - Raw G→T rows for a relationship type, group sources only. Works for any slug including core `'includes'`. No TTL. Invalidated by `htax_delete_custom_rel_caches_for_term`. Used by the Synonyms page to explain container-group paths; `htax_get_container_terms()` reads container groups of all inclusion types in its own query instead.
- `htax_ac_*` - Term autocomplete results on the term edit screen
- `htax_pl_*` - Post list shortcode query results; see [Post list cache](#post-list-cache-htax_pl_) below
- `htax_swmap_v{version}x{hash}[h]` - The search widget's group-members map for one field configuration (members of its groups, names, counts, icons; `h` for viewers who can see hidden terms). The checklist's initial selection is added per request. `{version}` is `htax_term_data_version`, bumped (at most once per request, again after a form caches) on term creation, edits and deletion, group membership, hidden state, icon changes, archive changes and settings saves, so older entries are never read and expire after a day
- `htax_media_months` - The media modal's month list (`media_library_months_with_files` filter, `htax_cached_media_library_months()`), so screens that load the icon picker don't rerun WordPress's query over every attachment. One day, cleared when an attachment is added or deleted
- `htax_sitemap_empty_groups` - IDs of groups whose archives show no posts, left out of the XML sitemap. 15 minutes, no other invalidation: a group gaining or losing its last post shows in the sitemap within that time

## What clears the relationship caches

These handlers have distinct purposes and can't be combined:

| Priority | Handler | Scope |
|----------|---------|-------|
| 10 on `edited_term` | `htax_handle_term_rename` | Only when the name changed (pre-edit name in `htax_term_data_{tt_id}`): the contributing-terms and count caches of the archives that show the term's posts, `htax_inc_*` of the terms including it and `htax_cont_*` of the terms it includes (lists ordered by name), and its posts are queued for reindexing (the built-in strength model scores by name). Other edits change nothing these caches hold: parent changes have their own handlers, and each term edit screen field clears what it changes when it saves |
| term edit screen save (`htax_save_term_relationships`) | — | Only for the fields that changed: the term's inclusions and archive caches (`htax_clear_term_archive_caches()`) when what it includes or excludes changed, its container list when its containers changed, its exclusions, the raw row cache of each changed field, and the other side's raw row caches for each added or removed term or group |
| API (`htax_add_term_relationship()` / `htax_remove_term_relationship()`, through `htax_handle_term_relationship_change()`) | — | The row's raw caches on both sides (outgoing to terms or to groups, incoming from terms or from groups); the source's inclusions and archive caches; the target's container list; for group rows, the members' caches. Both terms are marked fresh while the actions fire |
| 5 on `htax_includes_changed` and the other relationship actions | relationship cache handler (search index) | Side-aware, and skipped for terms marked fresh: the changed term's inclusions and archive caches on `htax_includes_changed`, `htax_excludes_changed` (plus exclusions) and `htax_included_groups_changed`, its container list on `htax_containers_changed` and `htax_container_groups_changed`; for each term added to or removed from the relationship, the other side: its container list, or its inclusions and archive caches |
| 15 on `edited_term` | `htax_clear_ajax_caches` | Autocomplete caches (broad clearing for admin UI freshness) |
| 30 on `edited_{tax}` | `htax_clear_count_cache_on_save` | Count caches (`htax_total_*`) based on final saved state |
| `added/updated/deleted_term_meta` | `htax_clear_hidden_terms_cache_on_meta` | `htax_hidden_terms` — only when `_htax_is_hidden` meta changes |
| `delete_term` | `htax_invalidate_term_caches_on_delete` | The term's caches as on edit, the caches of terms with relationships to it, `htax_sticky_{term_id}`, and `htax_hidden_terms` (the term may have been hidden) |
| REST UPDATE (`htax_rest_update_sticky_terms`) | `htax_rest_update_sticky_terms` | `_htax_sticky_{term_id}` per-term assignment keys written/deleted; `htax_sticky_{term_id}` transient cleared for each changed term |
| `delete_post` | `htax_cleanup_sticky_on_delete` | All `_htax_sticky_{term_id}` keys for the post |

## Term shortcodes: no output cache

Term shortcodes and blocks don't cache their output, because their HTML depends on terms, post counts, relationships, visibility, settings, language and third-party filters. They do read the per-term relationship transients (`htax_redir_*`, `htax_groups_*`, `htax_inc_*`), which a cold render stores for every term. `HyperTaxonomy_Transient_Batch` holds those values in memory for the duration of the render and writes them in one statement, since committing the rows individually is most of the cold cost. Reads see held values immediately, the stored rows are the same as individual writes, and a bulk clear discards anything held. It has no effect under an external object cache.

## Post list cache (`htax_pl_*`)

The `[ht_post_list]` and `[ht_related_posts]` shortcodes cache the result of `query_posts_by_term_ids()` — the search-index query that returns a paginated list of post IDs with a total count.

**Expiry:** one hour by default (`shortcode_cache_duration`; 0 = never), as a safety net behind the invalidation below.

**Invalidated by term ID when:** a post's terms change, its published state changes, or its comment count changes; a term's archive composition changes (`htax_term_archive_changed`: relationships, redirects, group membership, parent changes); a term's visibility changes; and when a reindex batch finishes, for the old and new index terms of its posts. That last step covers lists cached while the index was still out of date. More than 100 terms at once clears all `htax_pl_*` entries instead.

**What is stored:**
```php
array(
    'post_ids'  => int[],   // Ordered post IDs for the requested page
    'total'     => int,     // Total matching posts (respects max_results cap)
    'max_pages' => int,     // Total page count
)
```

**Key format:** `htax_pl_t{sorted-main-ids}_f{sorted-filter-term-ids}_g{sorted-filter-group-ids}_fo{sorted-filter-out-term-ids}_fog{sorted-filter-out-group-ids}_p{page}_n{ppp}_m{max}_o{orderby}_y{post-type}_x{exclude-ids}_s{search-hash}_w{date-hash}[H]`

- `t{id}` — one segment per main term ID (sorted ascending), e.g. `t3_t7`
- `f{id}` — one segment per individual filter term ID from `filter_terms` attr (sorted ascending); omitted if empty
- `g{id}` — one segment per filter GROUP ID from `filter_groups` attr (sorted ascending); omitted if empty. The group IDs themselves — not expanded member term IDs — are stored here, because `filter_groups` semantics are AND-across-groups/OR-within-group: merging all member term IDs into a flat list would conflate different configurations that happen to share the same union of members.
- `fo{id}` — one segment per individual exclusion term ID from `filter_out_terms` attr (sorted ascending); omitted if empty
- `fog{id}` — one segment per exclusion GROUP ID from `filter_out_groups` attr (sorted ascending); omitted if empty. Group IDs stored for same reason as `g{id}` above.
- `p{n}` — page number (visible in key so all pages for a given query can be pattern-matched)
- `n{n}` — posts_per_page
- `m{n}` — max_results (0 = unlimited)
- `o{key}` — compact orderby: first char of each field + `D`/`A` for direction, segments joined by `-`. Examples: `date:DESC` → `dD`; `date:DESC,title:ASC` → `dD-tA`; `date` → `d`
- `y{type}` — post type
- `x{ids}` — exclude post IDs joined by `-`; omitted if empty
- `s{hash}` — first 8 characters of the search string's MD5; omitted if empty
- `w{hash}` — first 8 characters of the MD5 of the `modified_after` / `modified_before` range; omitted if neither is set
- `H` — appended when the viewer can see hidden terms

Caching is skipped if the key exceeds 172 characters (WordPress transient key limit), and for lists narrowed by a visitor's filter panel, which the key doesn't represent. Hidden term visibility is permission-aware: `htax_get_hidden_term_filter_clause()` returns `AND st.is_hidden = 0` for unprivileged users and an empty string for privileged users. To prevent cross-user cache contamination, `H` is appended to the cache key when `htax_can_see_hidden_terms()` is true, so privileged users get a separate cache entry that includes hidden-term posts.

**Invalidation:**

IDs are embedded directly in the key, enabling `LIKE '%{prefix}{id}\_%'` pattern-based deletion. The `\_` escaped underscore prevents false matches (e.g. `t1` matching `t10`). Five prefix patterns are checked per ID: `t`, `f`, `g`, `fo`, `fog`.

`htax_invalidate_post_list_cache( int[] $term_ids )` deletes all matching transients. It is called from:

| Trigger | Hook | Handler | Notes |
|---------|------|---------|-------|
| Post-term assignment changed | `set_object_terms` (6 args) | `htax_post_list_cache_on_object_terms_set` | Post list: merges direct term IDs with search-index term IDs (index has container/hierarchy/redirect associations). Contrib: fires `htax_term_archive_changed` for each ID returned by `htax_expand_term_ids_for_contrib_invalidation` on the direct terms |
| Term relationships deleted outside `set_object_terms` | `deleted_term_relationships` | `htax_post_list_cache_on_term_relationships_deleted` | Skips if `set_object_terms` is currently running. Same dual strategy as above |
| Post published or unpublished | `transition_post_status` | `htax_post_list_cache_on_post_status_change` | Only fires on publish↔non-publish transitions. Post list: search-index IDs. Contrib: fires `htax_term_archive_changed` per taxonomy via `htax_expand_term_ids_for_contrib_invalidation` |
| Comment count changed | `wp_update_comment_count` | `htax_post_list_cache_on_comment_count` | Published posts only; search-index IDs |
| Term archive composition changed | `htax_term_archive_changed` | `htax_invalidate_post_list_cache` (one term ID or several) | Relationships, redirects, group membership, parent changes |
| Term visibility changed | `htax_term_visibility_changed` (priority 20) | `htax_post_list_cache_on_visibility_change` | The term and every term it shares search index rows with |
| A term's caches cleared | via `htax_delete_term_transients()` | — | Any edit that clears a term's relationship caches |
| Reindex batch finished | — | search index | Old and new index terms of the batch's posts |
| Global cache clear | `htax_clear_all_caches()` | — | Covered by the `_transient_htax_` prefix pattern |

**Post list vs contrib invalidation strategies differ by design.** Post list uses the search index (pre-computes all relationship types for the post) because it needs every term whose shortcode query would return the post. Contrib uses `htax_expand_term_ids_for_contrib_invalidation` (queries `htax_get_container_terms()` + `get_ancestors()` directly) because the search index is built asynchronously and may not yet reflect a newly-added term assignment.

**Size.** For very large sites with high write volume (many post updates per hour), monitor transient table size. The cache keys are built from the term sets they cover, so the number of distinct cache entries grows with the number of distinct term combinations — not with the number of posts.
