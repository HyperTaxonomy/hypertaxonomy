<?php
/**
 * Strength models
 *
 * wp_htax_search_terms stores one strength per (post, term, source term,
 * relationship type, model). HyperTaxonomy's built-in TF heuristic is the
 * HTAX_DEFAULT_STRENGTH_MODEL model and always covers every relationship.
 * Other plugins register their own models and submit scores for a post's
 * directly assigned terms through htax_set_term_strengths(); HyperTaxonomy
 * derives the hierarchy, container, and redirect rows from those scores the
 * same way it does for its own model. Settings → Search selects the model that
 * strength-ranked reads use, gated on full coverage.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Access the in-memory strength model registry.
 *
 * @since 1.0.0
 *
 * @return array<string,array> Registry, by reference.
 */
function &htax_strength_model_registry(): array {
    static $models = null;
    if ( null === $models ) {
        $models = array(
            HTAX_DEFAULT_STRENGTH_MODEL => array(
                'label'       => __( 'HyperTaxonomy (term frequency)', 'hypertaxonomy' ),
                'description' => __( 'Built-in model: how prominently the term name appears in the post title, excerpt, and body.', 'hypertaxonomy' ),
            ),
        );
    }
    return $models;
}

/**
 * Register an external strength model.
 *
 * Call on 'init' (or earlier) on every request, like register_post_type().
 * Stored scores of a model that is no longer registered stay in the index but
 * are never read: a purpose whose selected model is unregistered falls back to
 * the built-in model.
 *
 * The key must change whenever the scores would stop being comparable, e.g.
 * when an embedding model changes — include the provider and model name.
 *
 * @since 1.0.0
 *
 * @param string $key  Model key: 1–64 chars of a-z, 0-9, '_', '.', ':', '-'.
 *                     Keys starting with 'htax_' are reserved.
 * @param array  $args {
 *     @type string $label       Name shown in Settings → Search.
 *     @type string $description Optional one-line description.
 * }
 * @return bool True on success, false if the key is invalid or reserved.
 */
function htax_register_strength_model( string $key, array $args = array() ): bool {
    if ( ! htax_validate_strength_model_key( $key ) || 0 === strpos( $key, 'htax_' ) ) {
        return false;
    }

    $registry         = &htax_strength_model_registry();
    $registry[ $key ] = array(
        'label'       => isset( $args['label'] ) ? (string) $args['label'] : $key,
        'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
    );
    return true;
}

/**
 * Unregister an external strength model.
 *
 * Its stored scores stay in the index (remove them with
 * htax_remove_term_strengths()) but are no longer read: if it was the
 * selected model, reads fall back to the built-in model.
 *
 * @since 1.0.0
 *
 * @param string $key Model key.
 * @return bool True if the model was registered and is now removed; false for
 *              unknown keys and for the built-in or other reserved ('htax_') keys.
 */
function htax_unregister_strength_model( string $key ): bool {
    if ( HTAX_DEFAULT_STRENGTH_MODEL === $key || 0 === strpos( $key, 'htax_' ) ) {
        return false;
    }

    $registry = &htax_strength_model_registry();
    if ( ! isset( $registry[ $key ] ) ) {
        return false;
    }
    unset( $registry[ $key ] );
    return true;
}

/**
 * Get all registered strength models, including the built-in one.
 *
 * @since 1.0.0
 *
 * @return array<string,array{label:string,description:string}> Model key => args.
 */
function htax_get_strength_models(): array {
    return htax_strength_model_registry();
}

/**
 * Validate the format of a strength model key.
 *
 * @since 1.0.0
 *
 * @param string $key Model key.
 * @return bool
 */
function htax_validate_strength_model_key( string $key ): bool {
    return 1 === preg_match( '/^[a-z0-9_.:-]{1,64}$/', $key );
}

/**
 * Get the strength model whose values a search-index read should use.
 *
 * Rows of the built-in model always form the complete structural set; other
 * models' rows are a subset that differ only in strength. Queries that only
 * test which posts reach a term are therefore correct without a model filter,
 * but any query that reads or aggregates strength must restrict to one model,
 * or duplicate rows would be counted once per model. Under an external model,
 * post–term pairs it has not scored contribute nothing to strength-ranked reads.
 *
 * @since 1.0.0
 *
 * @param string $purpose What the strengths are used for: 'search' (PowerSearch,
 *                        search widget, htax_term_strengths REST field, WP-SKOS
 *                        document search) or 'related' ([ht_post_list],
 *                        [ht_related_posts], htax_get_local_post_scores(),
 *                        htax_get_post_term_strengths()). Both currently use the
 *                        model selected in Settings → Search; callers pass the
 *                        purpose so they can be selected separately later.
 * @return string Model key; the built-in model when the selected one is unregistered.
 */
function htax_get_strength_model( string $purpose = 'search' ): string {
    $options  = get_option( 'htax_settings', array() );
    $selected = isset( $options['strength_model'] ) ? (string) $options['strength_model'] : '';

    if ( '' === $selected || ! isset( htax_get_strength_models()[ $selected ] ) ) {
        return HTAX_DEFAULT_STRENGTH_MODEL;
    }
    return $selected;
}

/**
 * Build an SQL condition restricting search-index rows to one strength model.
 *
 * @since 1.0.0
 *
 * @param string $alias   Table alias, or '' for an unaliased table.
 * @param string $purpose Passed to htax_get_strength_model().
 * @return string Condition with a leading ' AND ', safe to interpolate.
 */
function htax_strength_model_sql( string $alias = '', string $purpose = 'search' ): string {
    $column = '' === $alias ? 'model' : $alias . '.model';
    return " AND {$column} = '" . esc_sql( htax_get_strength_model( $purpose ) ) . "'";
}

/**
 * Derive an indirect row's strength from its source term's direct strength.
 *
 * Indirect rows (hierarchy, container, redirect_target) store the source
 * term's strength multiplied by the taxonomy's explicit weight for that
 * relationship type. Shared by every model.
 *
 * @since 1.0.0
 *
 * @param float  $source_strength   Direct strength of the source term.
 * @param string $taxonomy          Taxonomy of the indexed row (used to look up weights).
 * @param string $relationship_type 'hierarchy', 'container', or 'redirect_target'.
 * @return float Strength in [0.0, 1.0].
 */
function htax_derive_indirect_strength( float $source_strength, string $taxonomy, string $relationship_type ): float {
    $type_key    = ( 'redirect_target' === $relationship_type ) ? 'redirect' : $relationship_type;
    $weights     = htax_get_relevance_weights( $taxonomy );
    $propagation = isset( $weights[ $type_key ]['explicit'] ) ? (float) $weights[ $type_key ]['explicit'] : 1.0;

    return $source_strength * $propagation;
}

/**
 * Build INSERT placeholders and values for one model's rows of a post.
 *
 * Direct rows take the strength from $direct_strengths; indirect rows derive it
 * from their source term's direct strength. Rows whose source term has no
 * direct strength are skipped when $skip_unscored is true (external models:
 * unscored pairs are not stored) and get a neutral 1.0 otherwise.
 *
 * @since 1.0.0
 *
 * @param int    $post_id          Post ID.
 * @param array  $relationships    Rows with 'term_id', 'source_term_id', 'taxonomy', 'relationship_type', 'is_hidden'.
 * @param array  $direct_strengths Map of directly assigned term_id => strength.
 * @param string $model            Model key.
 * @param bool   $skip_unscored    Skip rows whose source term is missing from $direct_strengths.
 * @param array  $placeholders     Appended by reference.
 * @param array  $values           Appended by reference.
 */
function htax_collect_strength_rows( int $post_id, array $relationships, array $direct_strengths, string $model, bool $skip_unscored, array &$placeholders, array &$values ): void {
    foreach ( $relationships as $rel ) {
        if ( empty( $rel['term_id'] ) || empty( $rel['source_term_id'] ) || empty( $rel['taxonomy'] ) || empty( $rel['relationship_type'] ) ) {
            continue;
        }

        $source_id = (int) $rel['source_term_id'];
        if ( ! isset( $direct_strengths[ $source_id ] ) && $skip_unscored ) {
            continue;
        }
        $source_strength = isset( $direct_strengths[ $source_id ] ) ? (float) $direct_strengths[ $source_id ] : 1.0;

        $strength = 'direct' === $rel['relationship_type']
            ? $source_strength
            : htax_derive_indirect_strength( $source_strength, $rel['taxonomy'], $rel['relationship_type'] );

        $values[]       = $post_id;
        $values[]       = (int) $rel['term_id'];
        $values[]       = $source_id;
        $values[]       = $rel['taxonomy'];
        $values[]       = $rel['relationship_type'];
        $values[]       = $model;
        $values[]       = ! empty( $rel['is_hidden'] ) ? 1 : 0;
        $values[]       = $strength;
        $placeholders[] = '(%d, %d, %d, %s, %s, %s, %d, %f)';
    }
}

/**
 * Write collected strength rows, replacing strength and is_hidden on existing keys.
 *
 * Runs as one statement, so either every row is written or none is.
 *
 * @since 1.0.0
 *
 * @param array $placeholders From htax_collect_strength_rows().
 * @param array $values       From htax_collect_strength_rows().
 * @return bool True on success (or nothing to write), false on database error.
 */
function htax_write_strength_rows( array $placeholders, array $values ): bool {
    global $wpdb;

    if ( empty( $placeholders ) ) {
        return true;
    }

    $result = $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}htax_search_terms
         (post_id, term_id, source_term_id, taxonomy, relationship_type, model, is_hidden, strength)
         VALUES " . implode( ', ', $placeholders ) . "
         ON DUPLICATE KEY UPDATE strength = VALUES(strength), is_hidden = VALUES(is_hidden)",
        $values
    ) );

    return false !== $result;
}

/**
 * Get direct strengths stored by external models for a post.
 *
 * @since 1.0.0
 *
 * @param int $post_id Post ID.
 * @return array<string,array<int,float>> Model key => (term_id => strength).
 */
function htax_get_external_direct_strengths( int $post_id ): array {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT model, term_id, strength
         FROM {$wpdb->prefix}htax_search_terms
         WHERE post_id = %d AND relationship_type = 'direct' AND model <> %s",
        $post_id,
        HTAX_DEFAULT_STRENGTH_MODEL
    ) );

    $out = array();
    foreach ( (array) $rows as $row ) {
        $out[ $row->model ][ (int) $row->term_id ] = (float) $row->strength;
    }
    return $out;
}

/**
 * Set an external model's strengths for terms directly assigned to a post.
 *
 * Scores must already be calibrated to [0, 1] — HyperTaxonomy stores them as
 * given and does not rescale them. The hierarchy, container, and
 * redirect_target rows derived from each term are written for the same model,
 * using the taxonomy's explicit relationship weights. Every submitted term
 * must be directly assigned to the post in the search index (the post must be
 * published and of an indexed post type). The call is all-or-nothing: if any
 * score or term is invalid, nothing is written.
 *
 * Stored scores survive reindexing of the post for as long as the term stays
 * assigned. When a reindex finds assigned terms a registered model has not
 * scored, it fires 'htax_term_strength_missing'.
 *
 * @since 1.0.0
 *
 * @param string $model     Registered model key (not the built-in model).
 * @param int    $post_id   Post ID.
 * @param array  $strengths Map of term_id => strength in [0, 1].
 * @return true|WP_Error
 */
function htax_set_term_strengths( string $model, int $post_id, array $strengths ) {
    global $wpdb;

    if ( HTAX_DEFAULT_STRENGTH_MODEL === $model ) {
        return new WP_Error( 'htax_reserved_model', __( 'The built-in strength model is maintained by HyperTaxonomy and cannot be set directly.', 'hypertaxonomy' ) );
    }
    if ( ! isset( htax_get_strength_models()[ $model ] ) ) {
        return new WP_Error( 'htax_unknown_model', sprintf(
            /* translators: %s: strength model key */
            __( 'Strength model "%s" is not registered. Call htax_register_strength_model() first.', 'hypertaxonomy' ),
            $model
        ) );
    }
    if ( $post_id <= 0 || empty( $strengths ) ) {
        return new WP_Error( 'htax_no_strengths', __( 'A post ID and at least one term strength are required.', 'hypertaxonomy' ) );
    }

    $clean = array();
    foreach ( $strengths as $term_id => $strength ) {
        $term_id = (int) $term_id;
        if ( $term_id <= 0 || ! is_numeric( $strength ) ) {
            return new WP_Error( 'htax_invalid_strength', __( 'Term strengths must map positive term IDs to numbers.', 'hypertaxonomy' ), array( 'term_id' => $term_id ) );
        }
        $strength = (float) $strength;
        if ( is_nan( $strength ) || $strength < 0.0 || $strength > 1.0 ) {
            return new WP_Error( 'htax_strength_out_of_range', __( 'Term strengths must be calibrated to the range 0–1.', 'hypertaxonomy' ), array( 'term_id' => $term_id, 'strength' => $strength ) );
        }
        $clean[ $term_id ] = $strength;
    }

    // Structure comes from the built-in model's rows, which always form the complete set.
    $id_list = implode( ',', array_keys( $clean ) );
    $rows    = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, source_term_id, taxonomy, relationship_type, is_hidden
         FROM {$wpdb->prefix}htax_search_terms
         WHERE post_id = %d AND model = %s AND source_term_id IN ($id_list)",
        $post_id,
        HTAX_DEFAULT_STRENGTH_MODEL
    ), ARRAY_A );
    if ( $wpdb->last_error ) {
        return new WP_Error( 'htax_db_error', __( 'Could not read the search index.', 'hypertaxonomy' ) );
    }

    $assigned = array();
    foreach ( (array) $rows as $row ) {
        if ( 'direct' === $row['relationship_type'] ) {
            $assigned[] = (int) $row['term_id'];
        }
    }
    $unassigned = array_values( array_diff( array_keys( $clean ), $assigned ) );
    if ( ! empty( $unassigned ) ) {
        return new WP_Error(
            'htax_term_not_assigned',
            __( 'Every term must be directly assigned to the post in the search index (published post of an indexed post type).', 'hypertaxonomy' ),
            array( 'term_ids' => $unassigned )
        );
    }

    $placeholders = array();
    $values       = array();
    htax_collect_strength_rows( $post_id, (array) $rows, $clean, $model, true, $placeholders, $values );

    if ( ! htax_write_strength_rows( $placeholders, $values ) ) {
        htax_handle_db_error( 'htax_set_term_strengths' );
        return new WP_Error( 'htax_db_error', __( 'Could not write term strengths.', 'hypertaxonomy' ) );
    }

    /**
     * Fires after an external model's strengths are stored or removed.
     *
     * @since 1.0.0
     *
     * @param int    $post_id  Post ID, or 0 when a model's strengths were removed for all posts.
     * @param string $model    Model key.
     * @param int[]  $term_ids Directly assigned term IDs whose strengths changed; empty = all.
     */
    do_action( 'htax_term_strengths_updated', $post_id, $model, array_keys( $clean ) );

    return true;
}

/**
 * Remove an external model's stored strengths.
 *
 * Removing a term's direct strength also removes the rows derived from it.
 * Unregistered models can be cleaned up too.
 *
 * @since 1.0.0
 *
 * @param string $model    Model key (not the built-in model).
 * @param int    $post_id  Post ID, or 0 for every post.
 * @param int[]  $term_ids Directly assigned term IDs to remove; empty = all of the post's terms.
 * @return int|WP_Error Number of rows removed.
 */
function htax_remove_term_strengths( string $model, int $post_id = 0, array $term_ids = array() ) {
    global $wpdb;

    if ( HTAX_DEFAULT_STRENGTH_MODEL === $model ) {
        return new WP_Error( 'htax_reserved_model', __( 'The built-in strength model is maintained by HyperTaxonomy and cannot be removed.', 'hypertaxonomy' ) );
    }

    $where = $wpdb->prepare( 'model = %s', $model );
    if ( $post_id > 0 ) {
        $where .= $wpdb->prepare( ' AND post_id = %d', $post_id );
    }
    $ids = array_values( array_filter( array_map( 'intval', $term_ids ) ) );
    if ( ! empty( $ids ) ) {
        $where .= ' AND source_term_id IN (' . implode( ',', $ids ) . ')';
    }

    $removed = $wpdb->query( "DELETE FROM {$wpdb->prefix}htax_search_terms WHERE {$where}" );
    if ( false === $removed ) {
        htax_handle_db_error( 'htax_remove_term_strengths' );
        return new WP_Error( 'htax_db_error', __( 'Could not remove term strengths.', 'hypertaxonomy' ) );
    }

    /** This action is documented in includes/core/strength-models.php */
    do_action( 'htax_term_strengths_updated', $post_id, $model, $ids );

    return (int) $removed;
}

/**
 * Get how much of the index a model has scored, per taxonomy.
 *
 * Counts directly assigned post–term pairs of the built-in model (the complete
 * set) and how many of them the given model has a direct strength for.
 * Derived rows follow automatically, so direct pairs are the full measure.
 *
 * @since 1.0.0
 *
 * @param string $model Model key.
 * @return array<string,array{scored:int,total:int}> Taxonomy => counts.
 */
function htax_get_strength_model_coverage( string $model ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'htax_search_terms';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT d.taxonomy, COUNT(*) AS total, COUNT(m.post_id) AS scored
         FROM {$table} d
         LEFT JOIN {$table} m
           ON m.post_id = d.post_id AND m.term_id = d.term_id AND m.source_term_id = d.source_term_id
          AND m.relationship_type = 'direct' AND m.model = %s
         WHERE d.model = %s AND d.relationship_type = 'direct'
         GROUP BY d.taxonomy",
        $model,
        HTAX_DEFAULT_STRENGTH_MODEL
    ) );
    htax_handle_db_error( 'htax_get_strength_model_coverage' );

    $out = array();
    foreach ( (array) $rows as $row ) {
        $out[ $row->taxonomy ] = array(
            'scored' => (int) $row->scored,
            'total'  => (int) $row->total,
        );
    }
    return $out;
}

/**
 * Get the keys of external models that have scores stored in the index.
 *
 * Includes models that are no longer registered (e.g. their plugin is
 * deactivated or was removed), whose scores stay stored until removed.
 *
 * @since 1.0.0
 *
 * @return string[] Model keys, excluding the built-in model.
 */
function htax_get_stored_strength_models(): array {
    global $wpdb;

    $models = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT model FROM {$wpdb->prefix}htax_search_terms WHERE model <> %s ORDER BY model",
        HTAX_DEFAULT_STRENGTH_MODEL
    ) );
    htax_handle_db_error( 'htax_get_stored_strength_models' );

    return array_map( 'strval', (array) $models );
}

/**
 * Check whether a model has scored every directly assigned post–term pair.
 *
 * @since 1.0.0
 *
 * @param string $model Model key.
 * @return bool
 */
function htax_strength_model_has_full_coverage( string $model ): bool {
    if ( HTAX_DEFAULT_STRENGTH_MODEL === $model ) {
        return true;
    }
    foreach ( htax_get_strength_model_coverage( $model ) as $counts ) {
        if ( $counts['scored'] < $counts['total'] ) {
            return false;
        }
    }
    return true;
}
