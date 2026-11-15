---
title: Search Ranking & Notices
parent: Developer Reference
nav_order: 6
---

# Search Ranking & Notices
{: .no_toc }

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Strength Models

Every post–term relationship in the search index carries a **strength**: how central the term is to the post, from 0 to 1. Strength ranks term-based search results and related posts. HyperTaxonomy's built-in model, `htax_tf`, derives it from how prominently the term name appears in the post's title, excerpt, and body. Another plugin — for example one that computes semantic similarity with embeddings — can register its own model and supply scores; a site owner then selects it in **[Settings → Search](../user-guide/plugin-settings.html#search-tab) → Strength Model**.

For how models are stored, selected and queried, see [Search Internals → Strength Models](../architecture/search-internals.html#strength-models-includescorestrength-modelsphp).

**How a provider integrates:**

1. Register the model on `init`, on every request. The key must change whenever scores stop being comparable (e.g. a different embedding model), so include the provider and model name.
2. Submit scores for a post's **directly assigned** terms with `htax_set_term_strengths()`. Scores must already be calibrated to 0–1; HyperTaxonomy stores them as given. It writes the rows derived from each term (broader terms, inclusions, redirects) for the same model, using the site's relevance weights.
3. Hook `htax_term_strength_missing` to score terms HyperTaxonomy reports as unscored (new posts, newly assigned terms).
4. Before your plugin is uninstalled (or when the model is retired), remove its scores with `htax_remove_term_strengths( $model )`.

```php
add_action( 'init', function () {
    if ( function_exists( 'htax_register_strength_model' ) ) {
        htax_register_strength_model( 'myplugin:embed-v2', array(
            'label'       => 'My Plugin (embeddings v2)',
            'description' => 'Cosine similarity between post and term embeddings.',
        ) );
    }
} );

// After computing scores for a post:
$result = htax_set_term_strengths( 'myplugin:embed-v2', $post_id, array(
    65  => 0.83,   // term_id => calibrated score in [0, 1]
    246 => 0.41,
) );
if ( is_wp_error( $result ) ) {
    error_log( $result->get_error_message() );
}

// In your uninstall routine:
if ( function_exists( 'htax_remove_term_strengths' ) ) {
    htax_remove_term_strengths( 'myplugin:embed-v2' );
}
```

**What site owners see:** the model appears in Settings → Search → Strength Model with its coverage (how many directly assigned post–term pairs it has scored). It can only be selected once coverage is complete. While it is selected, pairs it hasn't scored yet don't contribute to term-based ranking — HyperTaxonomy never mixes its own scores into another model's ranking. Settings → Search → Index Rebuild is replaced by **Clear Scores** for external models, because only the provider can rescore posts.

**Scores survive reindexing.** When HyperTaxonomy reindexes a post (on save, after term or relationship changes, or in a full rebuild), it keeps each model's scores for terms that are still directly assigned and re-derives the dependent rows. Scores for unassigned terms are dropped.

**Unregistering does not delete scores.** Registration is per request, so a temporarily deactivated provider keeps its scores; they are simply not read, and the selection falls back to `htax_tf` until the model is registered again (the saved selection is kept). Delete them explicitly with `htax_remove_term_strengths()` — e.g. in your uninstall routine — or let the site owner use Clear Scores: Settings → Search lists models that still have stored scores but aren't registered as *inactive*, with their coverage and Clear Scores, without allowing them to be selected.

### Functions

**`htax_register_strength_model( string $key, array $args = [] ): bool`** — registers a model. `$key`: 1–64 characters of `a-z 0-9 _ . : -`. `$args`: `label`, `description`. Returns `false` for an invalid key or a reserved one: `htax_tf` and every other key starting with `htax_` belong to HyperTaxonomy and can't be registered.

**`htax_unregister_strength_model( string $key ): bool`** — removes a model from the registry for the rest of the request. Returns `false` for unknown keys and for reserved keys — the built-in model can't be unregistered. Stored scores are kept.

**`htax_get_strength_models(): array`** — registered models, including `htax_tf`, as `key => [ 'label' => …, 'description' => … ]`.

**`htax_set_term_strengths( string $model, int $post_id, array $strengths ): true|WP_Error`** — stores `term_id => score` for a registered external model. All-or-nothing: if any score is outside 0–1, or any term isn't directly assigned to the post in the search index (the post must be published and of an indexed post type), nothing is written. Error codes: `htax_reserved_model`, `htax_unknown_model`, `htax_no_strengths`, `htax_invalid_strength`, `htax_strength_out_of_range`, `htax_term_not_assigned` (error data lists the term IDs), `htax_db_error`. Fires `htax_term_strengths_updated`.

**`htax_remove_term_strengths( string $model, int $post_id = 0, array $term_ids = [] ): int|WP_Error`** — removes a model's stored scores: for every post (`$post_id = 0`), for one post, or for specific terms of a post, together with their derived rows. Works for unregistered models too. Returns the number of rows removed; refuses `htax_tf`. Fires `htax_term_strengths_updated`.

**`htax_get_stored_strength_models(): string[]`** — keys of external models that have scores stored, registered or not.

**`htax_get_strength_model_coverage( string $model ): array`** — per taxonomy, `[ 'scored' => int, 'total' => int ]` directly assigned post–term pairs. **`htax_strength_model_has_full_coverage( string $model ): bool`** — whether every pair is scored.

**`htax_get_strength_model( string $purpose = 'search' ): string`** — the model strength-ranked reads use: the one selected in Settings → Search if it is registered, otherwise `htax_tf`. `$purpose` is `'search'` or `'related'`; both currently read the same selection.

**`htax_strength_model_sql( string $alias = '', string $purpose = 'search' ): string`** — returns ` AND {alias}.model = '…'` for that model, safe to interpolate.

See also the actions [`htax_term_strength_missing`](actions-filters.html#strengths-missing-for-a-post) and [`htax_term_strengths_updated`](actions-filters.html#strengths-stored-or-removed).

### Querying strength directly

If your query only asks *which* posts reach a term (like the search index example in [Handling Hidden Terms](hidden-terms.html#hidden-terms-in-the-search-index)), no model filter is needed — every relationship has an `htax_tf` row. If it reads or aggregates `strength`, restrict it to one model, or each relationship would be counted once per model:

```php
global $wpdb;
$hidden_clause = htax_get_hidden_term_filter_clause( 'st.is_hidden' );

$results = $wpdb->get_results(
    "SELECT post_id, SUM(strength) AS score
     FROM {$wpdb->prefix}htax_search_terms st
     WHERE st.term_id IN (" . implode( ',', array_map( 'intval', $term_ids ) ) . ")
     {$hidden_clause}" . htax_strength_model_sql( 'st' ) . "
     GROUP BY post_id"
);
```

---

## Search Notices

Messages for the visitor about how a search was run — e.g. that `near:me` was ignored because the visitor's location is unknown. Integrations that adjust a search in a way the visitor should know about can add their own.

```php
htax_add_search_notice( string $code, string $message ): void // same $code replaces an earlier notice
htax_get_search_notices(): string[]                            // notices recorded during this request
```

Search widget and PowerSearch AJAX responses include them as `notices` (rendered above the results as `<p class="htax-search-notice">`); POST-mode and native search results pages print them above the theme's result list (`loop_start` / `loop_no_results`, main search query). Messages are plain text.
