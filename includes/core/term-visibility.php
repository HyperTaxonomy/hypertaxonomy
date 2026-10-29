<?php
/**
 * Term Visibility Functions
 *
 * Handles term visibility logic, including hidden terms and user permissions.
 *
 * @package HyperTaxonomy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check if a term is marked as hidden
 *
 * @since 1.0.0
 * @param int $term_id Term ID to check.
 * @return bool True if term is hidden, false otherwise.
 */
function htax_is_term_hidden( $term_id ) {
    return (bool) get_term_meta( $term_id, '_htax_is_hidden', true );
}

/**
 * Check if current user can see hidden terms
 *
 * Returns true if the user holds the manage_term_visibility capability (explicit
 * admin grant) OR meets the site-wide hidden_terms_visibility role threshold.
 * Either condition is sufficient — capability takes priority.
 *
 * @since 1.0.0
 * @param string $taxonomy Optional. Taxonomy slug (reserved for filter hooks).
 * @return bool True if user can see hidden terms, false otherwise.
 */
function htax_can_see_hidden_terms( $taxonomy = '' ) {
    // Explicit capability always grants access.
    if ( HyperTaxonomy_Capabilities::current_user_can( 'manage_term_visibility' ) ) {
        return true;
    }

    // Fall back to the site-wide settings-based role threshold.
    $options    = get_option( 'htax_settings', array() );
    $visibility = isset( $options['hidden_terms_visibility'] ) ? $options['hidden_terms_visibility'] : 'editors';

    switch ( $visibility ) {
        case 'all':
            return true;
        case 'editors':
            return current_user_can( 'edit_posts' );
        case 'none':
        default:
            return false;
    }
}

/**
 * Get all hidden term IDs from the database
 *
 * Returns every term that has been marked as hidden, regardless of the current
 * user. Use htax_get_visible_term_ids() when you need to apply permission
 * checks per user.
 *
 * @since 1.0.0
 * @return int[] Array of term IDs, empty array on error or when none exist.
 */
function htax_get_all_hidden_term_ids() {
    $cached = htax_get_transient( 'htax_hidden_terms' );
    if ( false !== $cached ) {
        return $cached;
    }

    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT term_id FROM {$wpdb->termmeta}
         WHERE meta_key = '_htax_is_hidden'
         AND meta_value = '1'"
    );

    if ( htax_handle_db_error( 'get_all_hidden_term_ids', array() ) !== false ) {
        return array();
    }

    $result = array_map( 'intval', $ids );
    htax_set_transient( 'htax_hidden_terms', $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}

/**
 * Get visible WP taxonomy descendants of a term for the current user.
 *
 * For authorized users, returns all descendants (identical to get_term_children).
 * For others, returns only descendants that are not marked hidden. Because hidden
 * is propagated to descendants at write time (htax_propagate_hidden_to_descendants),
 * a simple flat exclude is sufficient — no ancestor-chain traversal needed.
 *
 * @since 1.0.0
 * @param int    $term_id  Parent term ID whose descendants to retrieve.
 * @param string $taxonomy Taxonomy slug.
 * @return int[] Visible descendant term IDs (flat list, cast to int).
 */
function htax_get_visible_wp_descendants( int $term_id, string $taxonomy ): array {
    $children = get_term_children( $term_id, $taxonomy );
    if ( is_wp_error( $children ) || empty( $children ) ) {
        return array();
    }

    if ( htax_can_see_hidden_terms( $taxonomy ) ) {
        return array_map( 'intval', $children );
    }

    $hidden_set = array_flip( htax_get_all_hidden_term_ids() );
    return array_values( array_filter(
        array_map( 'intval', $children ),
        function ( $id ) use ( $hidden_set ) {
            return ! isset( $hidden_set[ $id ] );
        }
    ) );
}

/**
 * Propagate a visibility change to all WP descendants of a term.
 *
 * When $hide is true, marks every non-hidden descendant as hidden and fires
 * htax_term_visibility_changed for each. This keeps the hidden state explicit
 * at the term level so that htax_get_all_hidden_term_ids() is always a complete set.
 *
 * When $hide is false, unmarks every hidden descendant. Descendants remain hidden
 * after an ancestor is made visible unless this function is called explicitly —
 * the cascade does not reverse automatically.
 *
 * @since 1.0.0
 * @param int    $term_id  The term whose descendants should be updated.
 * @param string $taxonomy Taxonomy slug.
 * @param bool   $hide     True to mark descendants hidden; false to make them visible.
 * @return int Number of descendants whose visibility was changed.
 */
function htax_set_descendant_visibility( int $term_id, string $taxonomy, bool $hide ): int {
    if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
        return 0;
    }

    $descendants = get_term_children( $term_id, $taxonomy );
    if ( is_wp_error( $descendants ) || empty( $descendants ) ) {
        return 0;
    }

    $count = 0;
    foreach ( $descendants as $descendant_id ) {
        $is_hidden = '1' === get_term_meta( $descendant_id, '_htax_is_hidden', true );
        if ( $hide && ! $is_hidden ) {
            update_term_meta( $descendant_id, '_htax_is_hidden', '1' );
            do_action( 'htax_term_visibility_changed', $descendant_id, $taxonomy );
            ++$count;
        } elseif ( ! $hide && $is_hidden ) {
            delete_term_meta( $descendant_id, '_htax_is_hidden' );
            do_action( 'htax_term_visibility_changed', $descendant_id, $taxonomy );
            ++$count;
        }
    }

    return $count;
}

/**
 * Propagate hidden flag when a term is moved under a hidden parent.
 *
 * Runs on edited_term at priority 21 (after htax_cleanup_hierarchy_conflicts at 20).
 * Uses the pre-edit parent cached at edit_term priority 5 to detect whether the
 * parent actually changed. When the new parent is hidden, cascades the hidden flag
 * to the term and all its descendants.
 *
 * @since 1.0.0
 * @param int    $term_id  Term ID that was edited.
 * @param int    $tt_id    Term taxonomy ID (key for the pre-edit data transient).
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function htax_cascade_hidden_on_reparent( int $term_id, int $tt_id, string $taxonomy ): void {
    if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
        return;
    }

    $old_data   = htax_get_transient( 'htax_term_data_' . $tt_id );
    $old_parent = isset( $old_data['parent'] ) ? (int) $old_data['parent'] : 0;

    $term = get_term( $term_id, $taxonomy );
    if ( ! $term || is_wp_error( $term ) ) {
        return;
    }

    $new_parent = (int) $term->parent;

    // Only act when the parent actually changed to a hidden term.
    if ( $old_parent === $new_parent || ! $new_parent ) {
        return;
    }

    if ( ! htax_is_term_hidden( $new_parent ) ) {
        return;
    }

    // Mark the term itself hidden if not already.
    if ( '1' !== get_term_meta( $term_id, '_htax_is_hidden', true ) ) {
        update_term_meta( $term_id, '_htax_is_hidden', '1' );
        do_action( 'htax_term_visibility_changed', $term_id, $taxonomy );
    }

    // Cascade to all descendants.
    htax_set_descendant_visibility( $term_id, $taxonomy, true );
}

/**
 * Propagate hidden flag when a new term is created under a hidden parent.
 *
 * Runs on created_term at priority 21. Unlike the reparent case there is no
 * before/after comparison — we simply check whether the new term's parent is
 * hidden and, if so, hide the term. The term has no descendants at creation
 * time so no further cascade is needed.
 *
 * @since 1.0.0
 * @param int    $term_id  Term ID that was created.
 * @param int    $tt_id    Term taxonomy ID (unused; present to match hook signature).
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function htax_cascade_hidden_on_create( int $term_id, int $tt_id, string $taxonomy ): void {
    if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
        return;
    }

    $term = get_term( $term_id, $taxonomy );
    if ( ! $term || is_wp_error( $term ) || ! $term->parent ) {
        return;
    }

    if ( ! htax_is_term_hidden( $term->parent ) ) {
        return;
    }

    update_term_meta( $term_id, '_htax_is_hidden', '1' );
    do_action( 'htax_term_visibility_changed', $term_id, $taxonomy );
}

/**
 * Scan all hierarchical HT-enabled taxonomies and cascade hidden status to any
 * descendants of hidden terms that are not themselves marked hidden.
 *
 * Called on plugin activation to repair visibility state that may have drifted
 * while HT was inactive (e.g. hierarchy restructured via WP admin, WP-CLI, or
 * a third-party importer that does not fire HT's term hooks).
 *
 * Safe to call multiple times — it is idempotent: terms already marked hidden
 * are skipped, and the update only fires for terms whose state actually changes.
 *
 * @since 1.0.0
 * @return int Total number of terms whose hidden status was corrected.
 */
function htax_repair_visibility_cascade(): int {
    $enabled = htax_get_enabled_taxonomies();
    $total   = 0;

    foreach ( $enabled as $taxonomy ) {
        if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
            continue;
        }

        $hidden_ids = htax_get_all_hidden_term_ids();
        if ( empty( $hidden_ids ) ) {
            continue;
        }

        foreach ( $hidden_ids as $hidden_id ) {
            $term = get_term( $hidden_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $total += htax_set_descendant_visibility( $hidden_id, $taxonomy, true );
        }
    }

    // Bust the hidden-terms transient so the next read reflects any changes.
    if ( $total > 0 ) {
        htax_delete_transient( 'htax_hidden_terms' );
    }

    return $total;
}

/**
 * Treat hidden terms as non-existent in frontend post queries for unprivileged users.
 *
 * Hooked to parse_tax_query, after WordPress has turned every taxonomy query var
 * (tag, cat, tag__in, taxonomy=term, tax_query, REST filters) into tax clauses. For
 * users who cannot see hidden terms, hidden terms are removed from every positive
 * clause (IN / AND), as if they did not exist:
 *
 * - A clause whose terms were all hidden matches nothing, as a query for a
 *   nonexistent term would; an AND clause requiring a hidden term matches nothing.
 * - include_children on a hierarchical clause is expanded to visible descendants
 *   only, so a visible parent doesn't reach posts through a hidden child.
 * - NOT IN / EXISTS / NOT EXISTS clauses are left unchanged.
 *
 * Posts are never excluded for carrying a hidden term: they remain discoverable
 * through their other terms and in unfiltered lists.
 *
 * @since 1.0.0
 * @param WP_Query $query The current query.
 * @return void
 */
function htax_hide_terms_in_tax_query( WP_Query $query ): void {
    if ( is_admin() || htax_can_see_hidden_terms() ) {
        return;
    }
    if ( ! ( $query->tax_query instanceof WP_Tax_Query ) || empty( $query->tax_query->queries ) ) {
        return;
    }

    $hidden_ids = htax_get_all_hidden_term_ids();
    if ( empty( $hidden_ids ) ) {
        return;
    }

    $query->tax_query->queries = htax_strip_hidden_terms_from_tax_clauses(
        $query->tax_query->queries,
        array_flip( array_map( 'intval', $hidden_ids ) )
    );
}

/**
 * Remove hidden terms from the positive clauses of a (nested) tax query array.
 *
 * See htax_hide_terms_in_tax_query() for the rules.
 *
 * @since 1.0.0
 * @param array $clauses    Tax query clauses (WP_Tax_Query::$queries format).
 * @param array $hidden_set Hidden term IDs as array keys.
 * @return array
 */
function htax_strip_hidden_terms_from_tax_clauses( array $clauses, array $hidden_set ): array {
    foreach ( $clauses as $key => $clause ) {
        if ( 'relation' === $key || ! is_array( $clause ) ) {
            continue;
        }
        if ( ! isset( $clause['taxonomy'] ) && ! isset( $clause['terms'] ) ) {
            $clauses[ $key ] = htax_strip_hidden_terms_from_tax_clauses( $clause, $hidden_set );
            continue;
        }

        $operator = strtoupper( $clause['operator'] ?? 'IN' );
        $taxonomy = (string) ( $clause['taxonomy'] ?? '' );
        if ( ! in_array( $operator, array( 'IN', 'AND' ), true ) || '' === $taxonomy || empty( $clause['terms'] ) ) {
            continue;
        }

        $field  = (string) ( $clause['field'] ?? 'term_id' );
        $tokens = is_array( $clause['terms'] ) ? $clause['terms'] : preg_split( '/[,\s]+/', (string) $clause['terms'] );
        $ids    = array();
        foreach ( $tokens as $token ) {
            if ( 'term_id' === $field || 'id' === $field ) {
                $term_id = (int) $token;
            } else {
                $term    = get_term_by( 'term_taxonomy_id' === $field ? 'term_taxonomy_id' : $field, $token, $taxonomy );
                $term_id = $term instanceof WP_Term ? (int) $term->term_id : 0;
            }
            if ( $term_id > 0 ) {
                $ids[] = $term_id;
            }
        }

        $visible = array_values( array_filter( $ids, function ( $id ) use ( $hidden_set ) {
            return ! isset( $hidden_set[ $id ] );
        } ) );
        $removed = count( $visible ) < count( $ids );

        $expand = ! empty( $clause['include_children'] ) && is_taxonomy_hierarchical( $taxonomy );
        if ( ! $removed && ! $expand ) {
            continue;
        }

        if ( $expand ) {
            $with_children = array();
            $has_hidden    = false;
            foreach ( $visible as $id ) {
                $with_children[] = $id;
                $children        = get_term_children( $id, $taxonomy );
                if ( ! is_wp_error( $children ) ) {
                    foreach ( $children as $child_id ) {
                        if ( isset( $hidden_set[ (int) $child_id ] ) ) {
                            $has_hidden = true;
                        } else {
                            $with_children[] = (int) $child_id;
                        }
                    }
                }
            }
            if ( ! $has_hidden && ! $removed ) {
                continue; // No hidden term involved — WordPress's own child expansion is identical.
            }
            $visible = array_values( array_unique( $with_children ) );
        }

        // An AND clause that required a hidden term, or a clause left with no terms,
        // matches nothing — just like a query for a term that doesn't exist.
        $matches_nothing = empty( $visible ) || ( 'AND' === $operator && $removed );

        $clauses[ $key ]['field']            = 'term_id';
        $clauses[ $key ]['terms']            = $matches_nothing ? array( 0 ) : $visible;
        $clauses[ $key ]['operator']         = $matches_nothing ? 'IN' : $operator;
        $clauses[ $key ]['include_children'] = false;
    }

    return $clauses;
}

/**
 * Return only the visible term IDs from an array, based on user permissions
 *
 * Removes term IDs that are hidden and that the current user doesn't have
 * permission to see. Used to sanitize user-submitted term IDs.
 *
 * @since 1.0.0
 * @param array $term_ids Array of term IDs to filter.
 * @return array Array of term IDs with hidden terms removed if user can't see them.
 */
function htax_get_visible_term_ids( $term_ids ) {
    if ( empty( $term_ids ) || ! is_array( $term_ids ) ) {
        return $term_ids;
    }

    // If user can see hidden terms, no filtering needed
    if ( htax_can_see_hidden_terms() ) {
        return $term_ids;
    }

    // Filter out hidden terms
    return array_filter( $term_ids, function ( $term_id ) {
        return ! htax_is_term_hidden( $term_id );
    });
}
