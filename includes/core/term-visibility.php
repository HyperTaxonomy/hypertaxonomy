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
 * Check whether the current request comes from wp-admin rather than the front end.
 *
 * AJAX and REST requests serve both, so they're judged by the page that sent them. One with
 * no referring page, such as an API client, isn't a front-end visitor and counts as admin.
 *
 * @since 1.0.0
 *
 * @return bool True for wp-admin screens, requests sent from them, and API clients.
 */
function htax_is_admin_request() {
    if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return is_admin();
    }

    $referer = wp_get_raw_referer();
    if ( ! $referer ) {
        return true;
    }

    $referer_path = (string) wp_parse_url( $referer, PHP_URL_PATH );
    $admin_path   = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
    return 0 === strpos( $referer_path, $admin_path );
}

/**
 * Check if current user can see hidden terms
 *
 * On the front end, the site-wide hidden_terms_visibility setting alone decides, so "Hidden
 * from all users" hides terms from administrators too. In wp-admin, the
 * manage_term_visibility capability also grants access, so those who manage hidden terms
 * can always work with them there.
 *
 * @since 1.0.0
 * @param string $taxonomy Optional. Taxonomy slug (reserved for filter hooks).
 * @return bool True if user can see hidden terms, false otherwise.
 */
function htax_can_see_hidden_terms( $taxonomy = '' ) {
    if ( htax_is_admin_request() && current_user_can( 'manage_term_visibility' ) ) {
        return true;
    }

    $options    = get_option( 'htax_settings', array() );
    $visibility = isset( $options['hidden_terms_visibility'] ) ? $options['hidden_terms_visibility'] : 'post_editors';

    switch ( $visibility ) {
        case 'all':
            return true;
        case 'post_editors':
            return current_user_can( 'edit_posts' );
        case 'none':
        default:
            return false;
    }
}

/**
 * Get the term IDs to hide from the code currently asking for terms.
 *
 * Hidden terms don't exist for a viewer who can't see them, including for plugins that know
 * nothing about hidden terms. Applies only to front-end, REST and AJAX read requests: writes
 * need the real terms, as do wp-admin, cron and CLI.
 *
 * Some callers keep the real terms:
 * - HyperTaxonomy itself, which applies visibility on its own and builds caches shared
 *   between viewers.
 * - WordPress's term queries and object term cache, which write to what get_term() returns
 *   (a null there is a fatal error) and store full term lists shared between viewers:
 *   the per-post term cache, and the {taxonomy}_children option that holds each taxonomy's
 *   hierarchy. get_the_terms() results are filtered afterward by htax_filter_get_the_terms().
 * - Core functions that change data, which can run during a read request.
 * - The queried object and tax queries, so that hidden archives still 404 and posts carrying
 *   hidden terms are never excluded.
 * - Core functions walking a visible term's ancestors, which read each ancestor without checking:
 *   get_ancestors(), Walker_Category, and get_term_link() and get_term_parents_list() (fatal on a
 *   null ancestor). Asking those last two about the hidden term itself finds nothing.
 *
 * @since 1.0.0
 * @param WP_Term|null $term The term being looked up, when the caller is a single-term lookup.
 * @return int[] Term IDs to hide; empty when nothing should be hidden.
 */
function htax_get_term_ids_hidden_from_caller( ?WP_Term $term = null ): array {
    if ( ! did_action( 'init' ) || wp_doing_cron() || ( is_admin() && ! wp_doing_ajax() ) ) {
        return array();
    }
    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
    if ( 'GET' !== $method && 'HEAD' !== $method ) {
        return array();
    }

    // Reads its list directly from the database, so this can't recurse into a term query.
    $hidden = htax_get_all_hidden_term_ids();
    if ( empty( $hidden ) || htax_can_see_hidden_terms() ) {
        return array();
    }

    $real_term_callers = array(
        'populate_terms',
        'get_object_term_cache',
        'update_object_term_cache',
        'get_the_terms',
        'is_object_in_term',
        '_get_term_hierarchy',
        'get_queried_object',
        'get_ancestors',
        'transform_query',
        'wp_insert_term',
        'wp_update_term',
        'wp_delete_term',
        'wp_insert_post',
        'wp_set_object_terms',
        'wp_remove_object_terms',
        'wp_delete_object_term_relationships',
        '_update_term_count_on_transition_post_status',
    );
    $core_dir          = wp_normalize_path( ABSPATH . WPINC );
    $plugin_dir        = wp_normalize_path( HTAX_PLUGIN_DIR );

    // Arguments are only needed for a single-term lookup, which reaches here only for a hidden term.
    foreach ( array_slice( debug_backtrace( $term ? 0 : DEBUG_BACKTRACE_IGNORE_ARGS, 16 ), 1 ) as $frame ) {
        if ( in_array( $frame['function'], $real_term_callers, true ) ) {
            return array();
        }
        if ( 'Walker_Category' === ( $frame['class'] ?? '' ) ) {
            return array();
        }
        if ( $term && 'get_term_link' === $frame['function'] && ! htax_is_requested_term( $term, $frame['args'][0] ?? null ) ) {
            return array();
        }
        if ( $term && 'get_term_parents_list' === $frame['function'] ) {
            $ancestors = htax_request_cache( 'visible_term_ancestors' );
            if ( isset( $ancestors[ (int) $term->term_id ] ) ) {
                return array();
            }
        }
        // The first file outside WordPress core is the code asking.
        $file = isset( $frame['file'] ) ? wp_normalize_path( $frame['file'] ) : $core_dir;
        if ( 0 !== strpos( $file, $core_dir ) ) {
            return 0 === strpos( $file, $plugin_dir ) ? array() : $hidden;
        }
    }
    return $hidden;
}

/**
 * Note the ancestors of a visible term, which get_term_parents_list() is about to read unchecked.
 *
 * That function overwrites its own argument while looping, so a backtrace can't tell which term it
 * was asked about. It returns early for a term hidden from the viewer, so it only reaches
 * get_ancestors() for a term they can see.
 *
 * @since 1.0.0
 * @param int[]      $ancestors Ancestor IDs.
 * @param int|string $object_id Term ID.
 * @return int[] Ancestor IDs, unchanged.
 */
function htax_note_visible_term_ancestors( $ancestors, $object_id ) {
    $hidden = array_flip( array_map( 'intval', htax_get_all_hidden_term_ids() ) );
    if ( ! isset( $hidden[ (int) $object_id ] ) ) {
        $noted = &htax_request_cache( 'visible_term_ancestors' );
        foreach ( (array) $ancestors as $ancestor_id ) {
            if ( isset( $hidden[ (int) $ancestor_id ] ) ) {
                $noted[ (int) $ancestor_id ] = true;
            }
        }
    }
    return $ancestors;
}

/**
 * Check whether a term is the one get_term_link() was asked for, rather than one of its ancestors.
 *
 * @since 1.0.0
 * @param WP_Term $term  Term being looked up.
 * @param mixed   $asked get_term_link()'s first argument: a term object, ID or slug.
 * @return bool
 */
function htax_is_requested_term( WP_Term $term, $asked ): bool {
    if ( is_object( $asked ) ) {
        return (int) $asked->term_id === (int) $term->term_id;
    }
    return (string) $asked === (string) $term->term_id || (string) $asked === $term->slug;
}

/**
 * Make a hidden term not found when looked up by someone who can't see it.
 *
 * @since 1.0.0
 * @param WP_Term|mixed $term Term.
 * @return WP_Term|mixed|null The term, or null when hidden from this caller.
 */
function htax_hide_term_on_read( $term ) {
    static $hidden = null;

    if ( ! ( $term instanceof WP_Term ) || ! did_action( 'init' ) ) {
        return $term;
    }
    // A page makes thousands of lookups: rule out visible terms before anything costlier.
    if ( null === $hidden ) {
        $hidden = array_flip( array_map( 'intval', htax_get_all_hidden_term_ids() ) );
    }
    if ( isset( $hidden[ (int) $term->term_id ] ) && htax_get_term_ids_hidden_from_caller( $term ) ) {
        return null;
    }
    return $term;
}

/**
 * Leave hidden terms out of term queries for someone who can't see them.
 *
 * Covers everything built on WP_Term_Query: get_terms(), get_tags(), get_categories(),
 * wp_get_object_terms(), wp_count_terms(), get_term_by(), tag clouds and term list blocks.
 * Done in SQL because the term query cache is keyed on the SQL, so viewers don't share results.
 *
 * @since 1.0.0
 * @param array $clauses Term query clauses.
 * @return array Clauses.
 */
function htax_hide_terms_in_term_queries( $clauses ) {
    $hidden = htax_get_term_ids_hidden_from_caller();
    if ( $hidden ) {
        $clauses['where'] .= ' AND t.term_id NOT IN (' . implode( ',', array_map( 'intval', $hidden ) ) . ')';
    }
    return $clauses;
}

/**
 * Keep hidden terms on a post when someone who can't see them saves it.
 *
 * Their editor never showed those terms, so a save that replaces the post's terms would
 * drop them without anyone having chosen to. Only applies to logged-in users: cron, CLI and
 * imports remove terms deliberately. Removing a hidden term takes a user who can see it.
 *
 * @since 1.0.0
 * @param int    $object_id  Object ID.
 * @param array  $terms      Terms passed to wp_set_object_terms().
 * @param array  $tt_ids     New term taxonomy IDs.
 * @param string $taxonomy   Taxonomy.
 * @param bool   $append     Whether terms were appended.
 * @param array  $old_tt_ids Previous term taxonomy IDs.
 * @return void
 */
function htax_keep_hidden_terms_on_save( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
    global $wpdb;

    if ( $append || wp_doing_cron() || ! is_user_logged_in() || htax_can_see_hidden_terms( $taxonomy ) ) {
        return;
    }
    $removed = array_values( array_diff( array_map( 'intval', (array) $old_tt_ids ), array_map( 'intval', (array) $tt_ids ) ) );
    if ( empty( $removed ) ) {
        return;
    }

    $placeholders = implode( ',', array_fill( 0, count( $removed ), '%d' ) );
    $term_ids     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($placeholders)",
        $removed
    ) ) );
    $keep = array_values( array_intersect( $term_ids, array_map( 'intval', htax_get_all_hidden_term_ids() ) ) );
    if ( $keep ) {
        wp_add_object_terms( $object_id, $keep, $taxonomy );
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
