<?php
/**
 * Integration API
 *
 * Exposes HT's archive expansion to third-party plugins (facet plugins, search
 * plugins, WooCommerce layered nav, etc.) without requiring those plugins to be
 * HT-aware.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the complete flat list of term IDs whose posts appear in a term's archive.
 *
 * Returns the term itself, its WP descendants (via get_term_children), and all
 * HT-contributed terms resolved by htax_build_expanded_tax_query. The HT-contributed
 * clause is already fully expanded (descendants of included terms are included), so
 * include_children = false is correct when using the returned list in a tax_query.
 *
 * Consumer pattern:
 *   $ids = htax_get_expanded_term_ids( $term_id );
 *   // tax_query clause: [ 'terms' => $ids, 'operator' => 'IN', 'include_children' => false ]
 *
 * By default this mirrors archive expansion (inclusions, groups, hierarchy).
 * Discovery surfaces that want parity with the search index — where querying a
 * redirect target also surfaces the redirecting terms' posts — can pass
 * $include_redirect_sources = true to fold in terms that redirect to this one
 * (feature-gated by the taxonomy's Redirects feature, visibility-filtered).
 *
 * @since 1.0.0
 * @param int  $term_id Term ID. The taxonomy is resolved automatically via get_term().
 * @param bool $include_redirect_sources When true, also include terms that redirect
 *               (directly or transitively) to this term. Default false.
 * @return int[] Deduplicated array of term IDs cast to int. Empty array if the term
 *               does not exist or the taxonomy is not HT-enabled.
 */
function htax_get_expanded_term_ids( int $term_id, bool $include_redirect_sources = false ): array {
    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return array();
    }
    $taxonomy = $term->taxonomy;

    // Start with the term itself and its own WP descendants (visible only).
    // These must always be added explicitly — htax_get_included_term_ids only
    // pre-expands descendants of *included* terms, not of the primary term.
    $ids = array( $term_id );
    if ( is_taxonomy_hierarchical( $taxonomy ) ) {
        $ids = array_merge( $ids, htax_get_visible_wp_descendants( $term_id, $taxonomy ) );
    }

    // Add HT-contributed terms (inclusions + their WP descendants) when present.
    $expanded_tax_query = htax_build_expanded_tax_query( $term_id, $taxonomy, $term );
    if ( null !== $expanded_tax_query ) {
        $ids = array_merge( $ids, (array) $expanded_tax_query[1]['terms'] );
    }

    // Redirect sources: terms that redirect to this one. Their posts belong to this
    // term for discovery (the weakest indexed relationship), matching the search
    // index. Gated by the Redirects feature and filtered for visibility.
    if ( $include_redirect_sources
        && function_exists( 'htax_get_redirect_sources' )
        && htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        $sources = htax_get_redirect_sources( $term_id );
        if ( ! empty( $sources ) && ! htax_can_see_hidden_terms( $taxonomy ) ) {
            $hidden_set = array_flip( htax_get_all_hidden_term_ids() );
            $sources    = array_values( array_filter( $sources, static function ( $sid ) use ( $hidden_set ) {
                return ! isset( $hidden_set[ $sid ] );
            } ) );
        }
        $ids = array_merge( $ids, array_map( 'intval', $sources ) );
    }

    $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

    /**
     * Filters the expanded list of term IDs for a term's archive.
     *
     * @since 1.0.0
     * @param int[]  $ids      Expanded term ID list.
     * @param int    $term_id  Source term ID.
     * @param string $taxonomy Taxonomy slug.
     */
    return apply_filters( 'htax_expanded_term_ids', $ids, $term_id, $taxonomy );
}

/**
 * pre_get_posts handler for secondary/third-party query expansion.
 *
 * Expands single-term tax_query clauses for HT-enabled taxonomies with the
 * inclusion feature, mirroring what htax_modify_archive_query does for the main
 * archive query. Designed for facet plugins, WooCommerce layered nav, and other
 * secondary queries that issue their own WP_Query calls.
 *
 * Expansion can be suppressed per-query by setting the 'htax_expand_terms' query
 * variable to false, or forced on by setting it to true (bypassing the global
 * setting check).
 *
 * Only single-term clauses are expanded (count($terms) === 1). Multi-term clauses
 * represent facet filters where expansion is not appropriate.
 *
 * @since 1.0.0
 * @param WP_Query $query The query object passed by pre_get_posts.
 * @return void
 */
function htax_expand_secondary_query( WP_Query $query ): void {
    if ( is_admin() ) {
        return;
    }

    // Skip the main archive query — already handled by htax_modify_archive_query.
    if ( $query->is_main_query() && ( $query->is_tag() || $query->is_category() || $query->is_tax() ) ) {
        return;
    }

    $expand_flag = $query->get( 'htax_expand_terms', null );

    if ( false === $expand_flag ) {
        return;
    }

    if ( true !== $expand_flag ) {
        $settings = get_option( 'htax_settings', array() );
        if ( empty( $settings['expand_secondary_queries'] ) ) {
            return;
        }
    }

    $tax_query = $query->get( 'tax_query' );
    if ( empty( $tax_query ) || ! is_array( $tax_query ) ) {
        return;
    }

    $modified = false;

    foreach ( $tax_query as $index => $clause ) {
        // Skip the 'relation' key and nested OR/AND groups.
        if ( ! is_array( $clause ) || isset( $clause['relation'] ) ) {
            continue;
        }

        if ( empty( $clause['taxonomy'] ) ) {
            continue;
        }

        $clause_taxonomy = $clause['taxonomy'];

        if ( ! htax_is_taxonomy_enabled( $clause_taxonomy ) ) {
            continue;
        }

        if ( ! htax_taxonomy_has_feature( $clause_taxonomy, 'term_relationships' ) ) {
            continue;
        }

        // Only expand single-term clauses; multi-term clauses are facet filters.
        $terms = isset( $clause['terms'] ) ? (array) $clause['terms'] : array();
        if ( count( $terms ) !== 1 ) {
            continue;
        }

        $single_term_id = (int) reset( $terms );

        $expanded = htax_build_expanded_tax_query( $single_term_id, $clause_taxonomy );
        if ( null === $expanded ) {
            continue;
        }

        $tax_query[ $index ] = $expanded;
        $modified            = true;
    }

    if ( $modified ) {
        $query->set( 'tax_query', $tax_query );
    }
}

/**
 * Adjust term counts for frontend get_terms() calls to reflect HT archive expansion.
 *
 * Hooked to 'get_terms' filter. For each term in an HT-enabled taxonomy with the
 * inclusion feature, replaces the native WP count (which only counts direct post
 * assignments) with a count that includes all posts visible in the term's expanded
 * archive (i.e., posts assigned to any of the HT-contributed term IDs).
 *
 * Skipped entirely in admin and AJAX contexts, and when the expand_secondary_queries
 * global setting is not enabled.
 *
 * @since 1.0.0
 * @param WP_Term[]|int[]|string[] $terms      Array of term objects, IDs, or names.
 * @param string[]|null            $taxonomies Array of taxonomy slugs, or null when not yet resolved.
 * @param array                    $args       get_terms() arguments.
 * @return WP_Term[]|int[]|string[] Possibly modified $terms array.
 */
function htax_adjust_term_counts( array $terms, ?array $taxonomies, array $args ): array {
    // Guard against reentrant calls: htax_get_included_terms() and htax_get_terms_in_groups()
    // both call get_terms() internally to fetch WP_Term objects, which would re-trigger this
    // filter before the transients are set — causing infinite recursion and OOM.
    static $running = false;
    if ( $running ) {
        return $terms;
    }

    if ( is_admin() || wp_doing_ajax() || null === $taxonomies ) {
        return $terms;
    }

    $settings = get_option( 'htax_settings', array() );
    if ( empty( $settings['expand_secondary_queries'] ) ) {
        return $terms;
    }

    $running = true;

    global $wpdb;

    // First pass: collect terms that need expansion and compute their expanded ID sets.
    // Grouped by taxonomy so we can issue one query per taxonomy instead of one per term.
    // Key structure: $by_taxonomy[ $taxonomy ][ $index ] = [ 'term' => WP_Term, 'expanded_ids' => int[] ]
    $by_taxonomy = array();

    foreach ( $terms as $index => $term ) {
        if ( ! ( $term instanceof WP_Term ) ) {
            continue;
        }

        if ( ! htax_is_taxonomy_enabled( $term->taxonomy ) ) {
            continue;
        }

        if ( ! htax_taxonomy_has_feature( $term->taxonomy, 'term_relationships' ) ) {
            continue;
        }

        // Skip terms with no HT contributions — keep the native WP count.
        $expanded_tax_query = htax_build_expanded_tax_query( $term->term_id, $term->taxonomy );
        if ( null === $expanded_tax_query ) {
            continue;
        }

        // Build the expanded ID set from the already-computed tax query result,
        // mirroring htax_get_expanded_term_ids() without a second call to htax_build_expanded_tax_query.
        $expanded_ids = array( $term->term_id );

        if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
            $children = get_term_children( $term->term_id, $term->taxonomy );
            if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
                $expanded_ids = array_merge( $expanded_ids, $children );
            }
        }

        if ( isset( $expanded_tax_query[1]['terms'] ) ) {
            $expanded_ids = array_merge( $expanded_ids, (array) $expanded_tax_query[1]['terms'] );
        }

        $expanded_ids = array_values( array_unique( array_map( 'intval', $expanded_ids ) ) );

        /** This filter is documented in htax_get_expanded_term_ids(). */
        $expanded_ids = apply_filters( 'htax_expanded_term_ids', $expanded_ids, $term->term_id, $term->taxonomy );

        if ( empty( $expanded_ids ) ) {
            continue;
        }

        $by_taxonomy[ $term->taxonomy ][ $index ] = array(
            'term'         => $term,
            'expanded_ids' => $expanded_ids,
        );
    }

    if ( empty( $by_taxonomy ) ) {
        $running = false;
        return $terms;
    }

    // Second pass: one batched COUNT query per taxonomy using a UNION ALL derived table
    // that maps each source term to its expanded term IDs. Compatible with MySQL 5.7+ / MariaDB 10.2+.
    $counts = array(); // $index => post count

    foreach ( $by_taxonomy as $taxonomy => $entries ) {
        // Build reverse map for fast lookup after the query.
        $term_id_to_index = array();
        $union_rows       = array();
        $union_args       = array();

        foreach ( $entries as $index => $entry ) {
            $term_id_to_index[ $entry['term']->term_id ] = $index;
            foreach ( $entry['expanded_ids'] as $expanded_id ) {
                $union_rows[] = 'SELECT %d AS source_term_id, %d AS expanded_id';
                $union_args[] = $entry['term']->term_id;
                $union_args[] = $expanded_id;
            }
        }

        $union_sql    = implode( ' UNION ALL ', $union_rows );
        $union_args[] = $taxonomy;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.source_term_id, COUNT(DISTINCT tr.object_id) AS post_count
                 FROM ( {$union_sql} ) AS m
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = m.expanded_id AND tt.taxonomy = %s
                 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 GROUP BY m.source_term_id",
                $union_args
            )
        );

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $source_term_id = (int) $row->source_term_id;
                if ( isset( $term_id_to_index[ $source_term_id ] ) ) {
                    $counts[ $term_id_to_index[ $source_term_id ] ] = (int) $row->post_count;
                }
            }
        }
    }

    // Third pass: write counts back to the term objects and apply per-term filter.
    foreach ( $by_taxonomy as $taxonomy => $entries ) {
        foreach ( $entries as $index => $entry ) {
            $count = isset( $counts[ $index ] ) ? $counts[ $index ] : 0;

            /**
             * Filters the expansion-aware post count for a term.
             *
             * @since 1.0.0
             * @param int    $count         Computed expanded post count.
             * @param int    $term_id       Term ID.
             * @param string $taxonomy      Taxonomy slug.
             * @param null   $query_context Reserved for future use; currently null.
             */
            $entry['term']->count = (int) apply_filters( 'htax_term_post_count', $count, $entry['term']->term_id, $taxonomy, null );
        }
    }

    $running = false;
    return $terms;
}

/**
 * Return all external URIs attached to a term, sourced from any active plugin
 * that registers on this filter (e.g. TermConnect provides link type URIs).
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 * @return string[] Flat list of external URI strings.
 */
function htax_get_term_uris( int $term_id ): array {
    return apply_filters( 'htax_term_uris', array(), $term_id );
}

/**
 * Return location rows attached to a term, sourced from any active plugin
 * that registers on this filter (e.g. As the Crow Flies provides location rows).
 *
 * Each row is an associative array with keys:
 *   label (string), street_address (string), locality (string), region (string),
 *   postal_code (string), country (string), lat (float|null), lng (float|null).
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 * @return array[] Location rows.
 */
function htax_get_term_locations( int $term_id ): array {
    return (array) apply_filters( 'htax_term_locations', array(), $term_id );
}

/**
 * Return external/related posts attached to a term, sourced from any active
 * plugin that registers on this filter (e.g. TermConnect provides related posts
 * fetched from peer sites and feeds).
 *
 * This is the generic hub point for cross-source post aggregation: providers
 * contribute posts here without knowing which consumer will read them, and
 * consumers read here without knowing which provider supplied them.
 *
 * Visibility is enforced by HyperTaxonomy: when the term is hidden from the
 * current user, this returns an empty array and the provider filter is not even
 * fired. (Term visibility is an HT-only concept, so the gate is inert on sites
 * that don't use it.)
 *
 * The optional `$args` bag is forwarded opaquely to providers as scope hints —
 * HyperTaxonomy does not interpret it. TermConnect understands:
 *   - 'platforms'  string[]|string  Peer platform types to include (e.g.
 *                                   'wordpress', 'feed'); default: all supported.
 *   - 'foaf_depth' int              FOAF traversal depth; 0 = direct peers only.
 *   - 'peers'      string|string[]  Restrict to specific peer link-type slugs
 *                                   (omit for all). Consumers that should not
 *                                   expose the peer topology simply leave it unset.
 *   - 'per_page'   int              Hint for how many posts to fetch.
 *
 * Each post is a normalized array (the shape produced by TermConnect's
 * tc_external_normalize_post(): keys include url, title, excerpt, date_gmt,
 * modified_gmt, thumbnail_url, origin, score, …).
 *
 * @since 1.0.0
 * @param int   $term_id Term ID.
 * @param array $args    Opaque scope hints forwarded to providers.
 * @return array[] Normalized post rows.
 */
function htax_get_term_external_posts( int $term_id, array $args = array() ): array {
    if ( htax_is_term_hidden( $term_id ) ) {
        $term     = get_term( $term_id );
        $taxonomy = ( $term instanceof WP_Term ) ? $term->taxonomy : '';
        if ( ! htax_can_see_hidden_terms( $taxonomy ) ) {
            return array();
        }
    }

    return (array) apply_filters( 'htax_term_external_posts', array(), $term_id, $args );
}

/**
 * Return the peer sites known to this installation, contributed by any active
 * plugin (e.g. TermConnect provides its configured WordPress peers and feeds).
 *
 * HyperTaxonomy defines the concept of a "peer site" but stores and manages
 * nothing — this is a read-only registry. Consumers (e.g. the search widget)
 * read it to offer peer-scoped options; providers populate it via the
 * `htax_peer_sites` filter. Returns an empty list when no provider is active.
 *
 * Each entry is an associative array keyed by slug:
 *   - 'slug'     string  Stable identifier (the provider's link-type slug).
 *   - 'label'    string  Human-readable name.
 *   - 'platform' string  Peer platform type (e.g. 'wordpress', 'feed').
 *   - 'origin'   string  Site origin (scheme://host) or feed URL, when known.
 *
 * @since 1.0.0
 * @return array[] Peer-site descriptors keyed by slug.
 */
function htax_get_peer_sites(): array {
    return (array) apply_filters( 'htax_peer_sites', array() );
}

/**
 * Score local posts by HyperTaxonomy relevance for a set of query terms.
 *
 * Sums the search-index strength (relationship weight × TF, baked at index time)
 * over each term's relationship paths, gated by the per-taxonomy enabled
 * relationship features and by term visibility. Returns the same relevance signal
 * the archives and search use, so a consumer (e.g. TermConnect's blended view)
 * can rank local results consistently with HyperTaxonomy. Returns an empty array
 * when the search index table is unavailable.
 *
 * @since 1.0.0
 * @param int[] $term_ids Query term IDs.
 * @param array $args     Optional. 'exclude' => int[] post IDs to omit.
 * @return array<int,float> Map of post_id => relevance score.
 */
function htax_get_local_post_scores( array $term_ids, array $args = array() ): array {
    global $wpdb;

    $term_ids = array_values( array_filter( array_map( 'intval', $term_ids ) ) );
    if ( empty( $term_ids ) ) {
        return array();
    }

    // Group by taxonomy so the allowed relationship types match each taxonomy's features.
    $by_tax = array();
    foreach ( $term_ids as $tid ) {
        $t = get_term( $tid );
        if ( $t instanceof WP_Term ) {
            $by_tax[ $t->taxonomy ][] = $tid;
        }
    }
    if ( empty( $by_tax ) ) {
        return array();
    }

    $st            = $wpdb->prefix . 'htax_search_terms';
    $ptt           = $wpdb->prefix . 'htax_post_text';
    $hidden_clause = htax_get_hidden_term_filter_clause( 'st.is_hidden' );
    $model_clause  = htax_strength_model_sql( 'st', 'related' );

    $exclude = array_values( array_filter( array_map( 'intval', (array) ( $args['exclude'] ?? array() ) ) ) );
    $exclude_clause = ! empty( $exclude ) ? ' AND st.post_id NOT IN (' . implode( ',', $exclude ) . ')' : '';

    // Optional per-term query weights (e.g. source-post centrality): each term's
    // aggregated strength is multiplied by its weight. Built as a CASE on term_id
    // from safe int keys and float values. Missing terms default to weight 1.0.
    $weight_mult = htax_build_term_weight_multiplier( $args['term_weights'] ?? array(), 'term_id' );

    $scores = array();
    foreach ( $by_tax as $tax => $ids ) {
        $allowed = array( 'direct' );
        if ( is_taxonomy_hierarchical( $tax ) ) {
            $allowed[] = 'hierarchy';
        }
        if ( function_exists( 'htax_taxonomy_has_feature' ) ) {
            if ( htax_taxonomy_has_feature( $tax, 'term_relationships' ) ) {
                $allowed[] = 'container';
            }
            if ( htax_taxonomy_has_feature( $tax, 'redirects' ) ) {
                $allowed[] = 'redirect_target';
            }
        }

        $id_ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rel_ph = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );

        // Per-term score combines all rows for a (post, term) pair via noisy-OR
        // (1 - Π(1 - strength)) — multiple paths to the same term (multiple sources, or
        // one source via more than one relationship type) raise the score monotonically
        // while staying strictly below 1.0, the ceiling a single direct row can reach.
        // A term reached by exactly one path is unaffected. The outer SUM then keeps the
        // breadth of distinct query terms. Matches HyperTaxonomy's own search/widget
        // scoring (and WP-SKOS's document search).
        // LEAST(1, ...) bounds (1 - strength): every stored strength is in [0,1] (built-in
        // formula, and htax_set_term_strengths() rejects out-of-range scores), so this only
        // guards against a negative value written to the table directly, which would push
        // the noisy-OR product above 1 and yield a negative combined score.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, SUM(term_score{$weight_mult}) AS score
                 FROM (
                     SELECT st.post_id, st.term_id,
                            1 - EXP(SUM(LN(LEAST(1, GREATEST(1 - st.strength, 0.000001))))) AS term_score
                     FROM {$st} st
                     INNER JOIN {$ptt} pt ON pt.post_id = st.post_id AND pt.post_status = 'publish'
                     WHERE st.term_id IN ($id_ph)
                       AND st.relationship_type IN ($rel_ph)
                       $hidden_clause
                       $exclude_clause
                       $model_clause
                     GROUP BY st.post_id, st.term_id
                 ) AS per_term
                 GROUP BY post_id",
                ...array_merge( $ids, $allowed )
            )
        );

        if ( htax_handle_db_error( 'htax_get_local_post_scores', array() ) !== false ) {
            return $scores;
        }

        foreach ( (array) $rows as $r ) {
            $pid            = (int) $r->post_id;
            $scores[ $pid ] = ( $scores[ $pid ] ?? 0.0 ) + (float) $r->score;
        }
    }

    return $scores;
}

/**
 * Build a SQL `* (CASE …)` multiplier that scales a per-term aggregate by a
 * caller-supplied weight map. Term IDs and weights are inlined safely (int keys,
 * float values via %F), so the result is safe to interpolate. Terms absent from
 * the map default to weight 1.0 (no change). Returns '' when the map is empty.
 *
 * @since 1.0.0
 * @param array<int,float> $weights  term_id => weight.
 * @param string           $term_col SQL column/alias holding the term ID.
 * @return string Multiplier fragment (e.g. " * (CASE term_id WHEN 5 THEN 0.8 ELSE 1 END)") or ''.
 */
function htax_build_term_weight_multiplier( array $weights, string $term_col ): string {
    $whens = '';
    foreach ( $weights as $tid => $w ) {
        $tid = (int) $tid;
        if ( $tid <= 0 ) {
            continue;
        }
        $whens .= sprintf( ' WHEN %d THEN %F', $tid, (float) $w );
    }
    if ( '' === $whens ) {
        return '';
    }
    return ' * (CASE ' . $term_col . $whens . ' ELSE 1 END)';
}

/**
 * A post's per-term association strengths, from the direct rows of the search
 * index — i.e. how central each of the post's own terms is to its content,
 * from the strength model selected for related posts. Terms that model has not
 * scored are omitted. Normalized so the strongest term is 1.0, which keeps downstream
 * scores in a familiar 0–1 band without affecting ranking (a per-post constant).
 *
 * Used to weight a source post's terms when finding related/peer content so that
 * a post sharing the source's central topics ranks above one sharing an
 * incidental tag. Returns an empty array when the index has no direct rows for
 * the post (e.g. not yet indexed) — callers then fall back to equal weights.
 *
 * @since 1.0.0
 * @param int   $post_id  Source post ID.
 * @param int[] $term_ids Optional. Restrict to these term IDs (normalization is
 *                        over the returned set). Empty = all of the post's terms.
 * @return array<int,float> Map of term_id => normalized strength (max 1.0).
 */
function htax_get_post_term_strengths( int $post_id, array $term_ids = array() ): array {
    global $wpdb;

    if ( $post_id <= 0 ) {
        return array();
    }

    $st    = $wpdb->prefix . 'htax_search_terms';
    $where = $wpdb->prepare( 'post_id = %d', $post_id ) . " AND relationship_type = 'direct'" . htax_strength_model_sql( '', 'related' );

    $ids = array_values( array_filter( array_map( 'intval', $term_ids ) ) );
    if ( ! empty( $ids ) ) {
        $where .= ' AND term_id IN (' . implode( ',', $ids ) . ')';
    }

    $rows = $wpdb->get_results( "SELECT term_id, strength FROM {$st} WHERE {$where}" );
    if ( htax_handle_db_error( 'htax_get_post_term_strengths', array() ) !== false ) {
        return array();
    }

    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[ (int) $r->term_id ] = (float) $r->strength;
    }

    if ( ! empty( $out ) ) {
        $max = max( $out );
        if ( $max > 0 ) {
            foreach ( $out as $tid => $strength ) {
                $out[ $tid ] = round( $strength / $max, 4 );
            }
        }
    }

    return $out;
}
