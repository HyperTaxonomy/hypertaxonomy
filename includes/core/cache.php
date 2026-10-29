<?php
/**
 * Cache Management Functions
 *
 * Handles cache invalidation for HyperTaxonomy transients.
 * These caches are needed because our custom calculations (contributing terms,
 * redirected counts, group membership) are complex and don't map to WordPress's
 * native term cache.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// LIFECYCLE TRACING — define( 'HTAX_TIMING', true ) in wp-config.php to enable
// ============================================================================

/**
 * Write a timestamped trace line to the debug log.
 *
 * Always defined so call sites need no conditional guards. When HTAX_TIMING is
 * not set the function returns immediately with zero visible overhead.
 *
 * @since 1.0.0
 * @param string $msg Human-readable message.
 */
function htax_trace( string $msg ): void {
    if ( ! defined( 'HTAX_TIMING' ) || ! HTAX_TIMING ) {
        return;
    }
    $t0      = $GLOBALS['htax_req_t0'] ?? $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true );
    $elapsed = round( ( microtime( true ) - $t0 ) * 1000 );
    error_log( "[HTAX +{$elapsed}ms] {$msg}" );
}

if ( defined( 'HTAX_TIMING' ) && HTAX_TIMING ) {

    // Capture PHP's own request-received timestamp — set before WordPress loads.
    $GLOBALS['htax_req_t0'] = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true );

    // Detect when WordPress spawns a loopback cron request.
    add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
        if ( false !== strpos( $url, 'wp-cron.php' ) ) {
            $mode = empty( $args['blocking'] ) ? 'non-blocking' : 'BLOCKING';
            htax_trace( "CRON_SPAWN {$mode} url={$url}" );
        }
        return $pre;
    }, 1, 3 );

    // Action Scheduler queue boundaries.
    add_action( 'action_scheduler_before_process_queue', function () {
        $GLOBALS['htax_as_t0'] = microtime( true );
        htax_trace( 'AS_QUEUE_START' );
    } );
    add_action( 'action_scheduler_after_process_queue', function () {
        $ms = isset( $GLOBALS['htax_as_t0'] )
            ? round( ( microtime( true ) - $GLOBALS['htax_as_t0'] ) * 1000 ) : 0;
        htax_trace( "AS_QUEUE_DONE {$ms}ms" );
    } );

    // Per-action timing.
    add_action( 'action_scheduler_before_execute', function ( $id ) {
        $GLOBALS['htax_as_action_t0'] = microtime( true );
        htax_trace( "AS_ACTION_START id={$id}" );
    }, 10, 1 );
    add_action( 'action_scheduler_after_execute', function ( $id ) {
        $ms = isset( $GLOBALS['htax_as_action_t0'] )
            ? round( ( microtime( true ) - $GLOBALS['htax_as_action_t0'] ) * 1000 ) : 0;
        htax_trace( "AS_ACTION_DONE id={$id} {$ms}ms" );
    }, 10, 1 );

    // REST request boundaries: start (pre_dispatch) and end (post_dispatch).
    add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
        $GLOBALS['htax_rest_t0']    = microtime( true );
        $GLOBALS['htax_rest_route'] = $request->get_route();
        $qs = $request->get_query_params();
        $ctx = isset( $qs['context'] ) ? '?context=' . $qs['context'] : '';
        htax_trace( 'REST_START ' . $request->get_route() . $ctx );
        return $result;
    }, 1, 3 );
    add_filter( 'rest_post_dispatch', function ( $result, $server, $request ) {
        $ms    = isset( $GLOBALS['htax_rest_t0'] )
            ? round( ( microtime( true ) - $GLOBALS['htax_rest_t0'] ) * 1000 ) : 0;
        $code  = is_wp_error( $result ) ? 'err' : $result->get_status();
        $route = $GLOBALS['htax_rest_route'] ?? $request->get_route();
        htax_trace( "REST_END [{$code}] {$route} {$ms}ms" );
        return $result;
    }, PHP_INT_MAX, 3 );

    // Shutdown: total PHP time from first byte.
    add_action( 'shutdown', function () {
        htax_trace( 'SHUTDOWN url=' . ( $_SERVER['REQUEST_URI'] ?? '?' ) );
    }, PHP_INT_MAX );
}

// ============================================================================
// TRANSIENT WRAPPERS & EXTERNAL OBJECT CACHE GENERATIONS
// ============================================================================
//
// Bulk invalidation (by prefix or key pattern) is done with DELETE ... LIKE on
// wp_options. With a persistent external object cache (Redis, Memcached), transients
// never reach wp_options, so those deletes cannot reach them — and many HyperTaxonomy
// caches have no TTL. All HyperTaxonomy code therefore reads and writes transients
// through htax_get_transient() / htax_set_transient() / htax_delete_transient(),
// which, under an external object cache only, append a generation token per cache
// family to every htax_* key. Bulk clears bump the family's generation, so every
// previously written key in that family becomes unreachable and is evicted by the
// cache's own LRU/TTL policy. Without an external object cache keys are unchanged.

/**
 * Map an htax_* transient key to its invalidation family.
 *
 * Families mirror the granularity of the bulk clear functions; a bulk clear of any
 * prefix or pattern within a family bumps the whole family.
 *
 * @since 1.0.0
 * @param string $key Transient key.
 * @return string Family name.
 */
function htax_transient_family( string $key ): string {
    static $families = array(
        'htax_contrib_'        => 'contrib',
        'htax_pl_'             => 'pl',
        'htax_total_'          => 'total',
        'htax_count_'          => 'total',
        'htax_ac_'             => 'ac',
        'htax_sticky_'         => 'sticky',
        'htax_inc_'            => 'rel',
        'htax_cont_'           => 'rel',
        'htax_excl_'           => 'rel',
        'htax_redir_'          => 'rel',
        'htax_redirect_terms'  => 'rel',
        'htax_groups_'         => 'rel',
        'htax_syno_'           => 'rel',
        'htax_direct_rel_'     => 'rel',
        'htax_reverse_rel_'    => 'rel',
        'htax_crel_'           => 'rel',
        'htax_grp_'            => 'rel',
        'htax_hidden'          => 'rel',
        'htax_term_data_'      => 'rel',
    );
    foreach ( $families as $prefix => $family ) {
        if ( 0 === strpos( $key, $prefix ) ) {
            return $family;
        }
    }
    return 'misc';
}

/**
 * Get (or bump) the current generation token for a cache family.
 *
 * Tokens are random rather than counters, so a token evicted from the object cache
 * is replaced by a new one and can never re-validate entries written under an older one.
 *
 * @since 1.0.0
 * @param string $family Family name ('all' for the global generation).
 * @param bool   $bump   True to start a new generation.
 * @return string Generation token.
 */
function htax_cache_generation( string $family, bool $bump = false ): string {
    static $memo = array();
    if ( ! $bump && isset( $memo[ $family ] ) ) {
        return $memo[ $family ];
    }
    $token = $bump ? false : wp_cache_get( $family, 'htax_cache_generation' );
    if ( false === $token ) {
        $token = substr( md5( uniqid( $family, true ) ), 0, 8 );
        wp_cache_set( $family, $token, 'htax_cache_generation' );
    }
    $memo[ $family ] = (string) $token;
    return $memo[ $family ];
}

/**
 * Finish a bulk transient clear. Called after every DELETE ... LIKE on wp_options.
 *
 * With an external object cache, starts a new generation for the family (the
 * database delete never reached the cached entries). Without one, the rows are
 * gone from the database but WordPress's runtime options cache still holds them
 * for the rest of the request, so that cache is dropped and reloads on next read.
 *
 * @since 1.0.0
 * @param string $family Family name, or 'all' for every HyperTaxonomy transient.
 */
function htax_bump_cache_family( string $family ): void {
    if ( wp_using_ext_object_cache() ) {
        htax_cache_generation( $family, true );
        return;
    }
    if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
        wp_cache_flush_group( 'options' );
    } else {
        wp_cache_delete( 'alloptions', 'options' );
    }
}

/**
 * Resolve the storage key for an HyperTaxonomy transient.
 *
 * @since 1.0.0
 * @param string $key Logical transient key.
 * @return string Key actually passed to the transients API.
 */
function htax_transient_key( string $key ): string {
    if ( 0 !== strpos( $key, 'htax_' ) || ! wp_using_ext_object_cache() ) {
        return $key;
    }
    return $key . '~' . htax_cache_generation( 'all' ) . htax_cache_generation( htax_transient_family( $key ) );
}

/**
 * htax_get_transient() for HyperTaxonomy keys (see htax_transient_key()).
 *
 * @since 1.0.0
 * @param string $key Transient key.
 * @return mixed Value, or false when absent.
 */
function htax_get_transient( $key ) {
    return get_transient( htax_transient_key( (string) $key ) );
}

/**
 * htax_set_transient() for HyperTaxonomy keys (see htax_transient_key()).
 *
 * @since 1.0.0
 * @param string $key        Transient key.
 * @param mixed  $value      Value to store.
 * @param int    $expiration Seconds until expiration; 0 for none.
 * @return bool
 */
function htax_set_transient( $key, $value, $expiration = 0 ) {
    return set_transient( htax_transient_key( (string) $key ), $value, $expiration );
}

/**
 * htax_delete_transient() for HyperTaxonomy keys (see htax_transient_key()).
 *
 * @since 1.0.0
 * @param string $key Transient key.
 * @return bool
 */
function htax_delete_transient( $key ) {
    return delete_transient( htax_transient_key( (string) $key ) );
}

/**
 * Clear all HyperTaxonomy caches
 *
 * Deletes all transients with htax_ prefix. This is the nuclear option used when:
 * - Settings are changed
 * - Theme is switched
 * - WordPress cache is flushed
 * - User manually clicks "Clear Cache" button
 *
 * @since 1.0.0
 */
function htax_clear_all_caches() {
    global $wpdb;

    // Delete all transients with htax_ prefix
    // WordPress stores transients in wp_options with _transient_ and _transient_timeout_ prefixes
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE %s
        OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_htax_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_htax_' ) . '%'
    ) );
    htax_bump_cache_family( 'all' );

    // The transients are gone; drop the per-request copies of the ones that have them too.
    htax_clear_request_cache( 'redirect_target' );
    htax_clear_request_cache( 'redirect_term_ids' );
    htax_clear_request_cache( 'synonyms' );

    // Note: wp_cache_flush() is intentionally absent. It would evict every plugin's
    // cached data, not just HyperTaxonomy's. With a persistent object cache, the
    // generation bump above makes every existing htax_* key unreachable instead.
}

/**
 * Clear HyperTaxonomy caches when any per-taxonomy feature flag changes.
 *
 * Relationship caches (inclusions, containers, synonyms, custom types) are
 * feature-independent — the relationship getters apply the flags on read — but
 * rendered outputs such as post-list results and contributing terms are computed
 * with the flags applied, so a flag change invalidates them.
 *
 * @since 1.0.0
 * @param mixed $old_value Previous htax_settings value.
 * @param mixed $new_value New htax_settings value.
 */
function htax_clear_caches_on_feature_change( $old_value, $new_value ) {
    $old_features = is_array( $old_value ) && isset( $old_value['taxonomy_features'] ) ? $old_value['taxonomy_features'] : array();
    $new_features = is_array( $new_value ) && isset( $new_value['taxonomy_features'] ) ? $new_value['taxonomy_features'] : array();
    if ( $old_features != $new_features ) { // Loose: order-insensitive, bool/'1' tolerant.
        htax_clear_all_caches();
    }
}
add_action( 'update_option_htax_settings', 'htax_clear_caches_on_feature_change', 10, 2 );

/**
 * Clear only relationship transients (inclusions, containers, redirects, groups, custom types).
 *
 * @since 1.0.0
 */
function htax_clear_relationship_caches() {
    global $wpdb;

    $like_pairs = array(
        '_transient_htax_inc_',          '_transient_timeout_htax_inc_',
        '_transient_htax_cont_',         '_transient_timeout_htax_cont_',
        '_transient_htax_excl_',         '_transient_timeout_htax_excl_',
        '_transient_htax_redir_',        '_transient_timeout_htax_redir_',
        '_transient_htax_redirect_terms', '_transient_timeout_htax_redirect_terms',
        '_transient_htax_groups_',       '_transient_timeout_htax_groups_',
        '_transient_htax_syno_',         '_transient_timeout_htax_syno_',
        '_transient_htax_direct_rel_',   '_transient_timeout_htax_direct_rel_',
        '_transient_htax_reverse_rel_',  '_transient_timeout_htax_reverse_rel_',
        '_transient_htax_crel_',         '_transient_timeout_htax_crel_',
        '_transient_htax_grp_',          '_transient_timeout_htax_grp_',
        '_transient_htax_hidden',        '_transient_timeout_htax_hidden',
        '_transient_htax_term_data_',    '_transient_timeout_htax_term_data_',
    );

    $clauses = array();
    foreach ( $like_pairs as $prefix ) {
        $clauses[] = $wpdb->prepare( 'option_name LIKE %s', $wpdb->esc_like( $prefix ) . '%' );
    }
    $wpdb->query( 'DELETE FROM ' . $wpdb->options . ' WHERE ' . implode( ' OR ', $clauses ) );
    htax_bump_cache_family( 'rel' );

    // The transients are gone; drop the per-request copies of the ones that have them too.
    htax_clear_request_cache( 'redirect_target' );
    htax_clear_request_cache( 'redirect_term_ids' );
    htax_clear_request_cache( 'synonyms' );
}

/**
 * Clear only search/archive contributing-term transients (htax_contrib_*).
 *
 * @since 1.0.0
 */
function htax_clear_search_caches() {
    htax_delete_transients_by_prefix( 'htax_contrib_' );
}

/**
 * Clear only autocomplete transients (htax_ac_*).
 *
 * @since 1.0.0
 */
function htax_clear_autocomplete_caches() {
    htax_delete_transients_by_prefix( 'htax_ac_' );
}

/**
 * Clear only sticky post transients (htax_sticky_*).
 *
 * @since 1.0.0
 */
function htax_clear_sticky_caches() {
    htax_delete_transients_by_prefix( 'htax_sticky_' );
}

/**
 * Clear only shortcode output transients (htax_pl_*, htax_total_*).
 *
 * @since 1.0.0
 */
function htax_clear_shortcode_caches() {
    htax_delete_transients_by_prefix( 'htax_pl_' );
    htax_delete_transients_by_prefix( 'htax_total_' );
}

/**
 * Batch-delete the core relationship transients for multiple terms in two queries.
 *
 * Replaces N×12 individual htax_delete_transient() calls (one per prefix per term) with
 * a single DELETE ... WHERE option_name IN (...) for the six named prefixes, and one
 * combined DELETE ... WHERE OR ... for contrib caches. Per-term custom-rel caches and
 * post-list cache invalidation still run individually — they're fewer rows and pattern-
 * based (LIKE), making batch IN less practical.
 *
 * @since 1.0.0
 * @param int[] $term_ids Term IDs to invalidate.
 */
function htax_delete_term_transients_batch( array $term_ids ): void {
    if ( empty( $term_ids ) ) {
        return;
    }
    $term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

    global $wpdb;

    // Build all named transient option names for all terms (12 per term: value + timeout).
    $prefixes = array( 'htax_inc_', 'htax_excl_', 'htax_redir_', 'htax_groups_', 'htax_cont_', 'htax_syno_' );
    $names    = array();
    foreach ( $term_ids as $tid ) {
        foreach ( $prefixes as $prefix ) {
            $names[] = '_transient_' . $prefix . $tid;
            $names[] = '_transient_timeout_' . $prefix . $tid;
        }
    }
    if ( ! empty( $names ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . $wpdb->options . ' WHERE option_name IN (' . $placeholders . ')',
            $names
        ) );
    }
    if ( wp_using_ext_object_cache() ) {
        foreach ( $term_ids as $tid ) {
            foreach ( $prefixes as $prefix ) {
                htax_delete_transient( $prefix . $tid );
            }
        }
    }

    // The htax_redir_ / htax_syno_ transients were deleted above; drop their per-request copies too.
    foreach ( $term_ids as $tid ) {
        htax_clear_request_cache( 'redirect_target', $tid );
        htax_clear_request_cache( 'synonyms', $tid );
    }

    // Contrib caches: a single broad clear is equivalent here because a group membership
    // change affects archives across many terms. Per-term LIKE queries (one per term) would
    // be ~20ms each × N terms; one prefix delete is one query regardless of count.
    // Custom rel caches: omitted — group membership changes don't affect custom relationship
    // types (sections C and D of htax_invalidate_group_relationship_caches handle those).
    // Post list caches: one broad prefix clear covers all affected terms at once.
    htax_delete_transients_by_prefix( 'htax_contrib_' );
    htax_delete_transients_by_prefix( 'htax_pl_' );
    foreach ( $term_ids as $tid ) {
        htax_queue_term_cache_rebuild( $tid );
    }
}

/**
 * Delete all HyperTaxonomy transients for a single term.
 *
 * @param int $term_id Term ID.
 */
function htax_delete_term_transients( $term_id ) {
    global $wpdb;
    if ( defined( 'HTAX_TIMING' ) && HTAX_TIMING ) {
        $frames = array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 ), 1 );
        $via    = implode( ' < ', array_map(
            fn( $f ) => ( $f['function'] ?? '?' ),
            $frames
        ) );
        htax_trace( "DELETE_TERM_TRANSIENTS tid={$term_id} via={$via}" );
    }
    htax_delete_transient( 'htax_inc_' . $term_id );
    htax_delete_transient( 'htax_excl_' . $term_id );
    htax_clear_redirect_target_cache( (int) $term_id );
    htax_delete_transient( 'htax_groups_' . $term_id );
    htax_delete_transient( 'htax_cont_' . $term_id );
    htax_delete_transient( 'htax_syno_' . $term_id );
    htax_clear_request_cache( 'synonyms', (int) $term_id );
    htax_invalidate_contrib_cache( (int) $term_id );
    htax_delete_custom_rel_caches_for_term( (int) $term_id );
    // Invalidate post list shortcode caches that depend on this term.
    htax_invalidate_post_list_cache( array( (int) $term_id ) );
    // Queue a rebuild at shutdown so the cache is warm for the next request.
    // Rebuilding at shutdown (not inline) ensures the DB write has fully committed.
    htax_queue_term_cache_rebuild( (int) $term_id );
}

/**
 * Queue a term's htax_inc_* and htax_cont_* caches for rebuild at end of request.
 *
 * Multiple calls per request are collapsed: the global buffer deduplicates by term ID,
 * and WordPress deduplicates the shutdown hook registration for the same callback string.
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 */
function htax_queue_term_cache_rebuild( int $term_id ): void {
    if ( ! isset( $GLOBALS['htax_term_rebuild_queue'] ) ) {
        $GLOBALS['htax_term_rebuild_queue'] = array();
    }
    if ( defined( 'HTAX_TIMING' ) && HTAX_TIMING && ! isset( $GLOBALS['htax_term_rebuild_queue'][ $term_id ] ) ) {
        $frames = array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ), 1 );
        $via    = implode( ' < ', array_map( fn( $f ) => ( $f['function'] ?? '?' ), $frames ) );
        htax_trace( "QUEUE_REBUILD tid={$term_id} via={$via}" );
    }
    $GLOBALS['htax_term_rebuild_queue'][ $term_id ] = true;
    add_action( 'shutdown', 'htax_rebuild_queued_term_caches', 5 );
}

/**
 * Schedule a background cron event to rebuild caches for all queued term IDs.
 *
 * Runs once at shutdown. Instead of blocking the current request with potentially
 * hundreds of relationship DB reads, it hands the work off to a WP-Cron single
 * event that fires on the next cron run (typically within a second or two).
 *
 * @since 1.0.0
 */
function htax_rebuild_queued_term_caches(): void {
    if ( empty( $GLOBALS['htax_term_rebuild_queue'] ) ) {
        return;
    }
    $queue                              = array_keys( $GLOBALS['htax_term_rebuild_queue'] );
    $GLOBALS['htax_term_rebuild_queue'] = array();

    htax_trace( 'REBUILD_QUEUE_ASYNC n=' . count( $queue ) . ' tids=' . implode( ',', $queue ) );
    wp_schedule_single_event( time(), 'htax_async_rebuild_term_caches', array( $queue ) );
}

/**
 * Async cron handler: rebuild inclusion, container, and synonym caches for queued terms.
 *
 * @since 1.0.0
 *
 * @param int[] $queue Term IDs to rebuild.
 */
function htax_do_rebuild_term_caches( array $queue ): void {
    htax_trace( 'REBUILD_QUEUE_START n=' . count( $queue ) . ' tids=' . implode( ',', $queue ) );
    $t0 = microtime( true );

    foreach ( $queue as $tid ) {
        $term = get_term( (int) $tid );
        if ( ! $term || is_wp_error( $term ) ) {
            continue;
        }
        htax_get_included_terms( (int) $tid );
        htax_get_container_terms( (int) $tid );
        htax_get_synonym_terms( (int) $tid );
    }

    htax_trace( 'REBUILD_QUEUE_DONE ' . round( ( microtime( true ) - $t0 ) * 1000 ) . 'ms' );
}
add_action( 'htax_async_rebuild_term_caches', 'htax_do_rebuild_term_caches' );

/**
 * Delete all relationship type transients for a single term.
 *
 * Iterates every registered type (including 'includes') and removes the forward,
 * reverse, and group-target/source direct-ID transients. Core types like 'member_of'
 * and 'redirect' use their own named keys cleared elsewhere; the slug-based keys
 * deleted here simply don't exist for those types and the delete calls are no-ops.
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 */
function htax_delete_custom_rel_caches_for_term( int $term_id ): void {
    $types = htax_get_registered_relationship_types();
    foreach ( $types as $slug => $def ) {
        htax_delete_transient( 'htax_crel_' . $slug . '_' . $term_id );
        htax_delete_transient( 'htax_crel_rev_' . $slug . '_' . $term_id );
        htax_delete_transient( 'htax_direct_rel_' . $slug . '_' . $term_id );
        htax_delete_transient( 'htax_reverse_rel_' . $slug . '_' . $term_id );
        htax_delete_transient( 'htax_direct_rel_grp_' . $slug . '_' . $term_id );
        htax_delete_transient( 'htax_reverse_rel_grp_' . $slug . '_' . $term_id );
    }
}

/**
 * Invalidate inclusion and custom relationship caches for all terms that include
 * or are related to the specified groups (or their ancestor/descendant groups).
 *
 * Called whenever a group's effective member set changes: direct membership edits
 * (from group-functions.php or group-admin.php) and group reparenting.
 *
 * Fwd direction (T→G): only ancestor groups can widen T's expansion — descendants
 * cannot, because htax_get_terms_in_groups expands downward from what T explicitly
 * points to. Uses $groups_with_ancestors.
 *
 * Rev/container direction (G→T): M joining G makes M's archive include T for every
 * container row where G or any *descendant* of G → T (because M's expanded groups
 * reach descendant groups). Uses the full union of ancestors + descendants.
 *
 * @since 1.0.0
 * @param int[] $group_ids Directly affected group IDs (before expansion).
 */
function htax_invalidate_group_relationship_caches( array $group_ids ): void {
    $group_ids = array_values( array_filter( array_map( 'intval', $group_ids ) ) );
    if ( empty( $group_ids ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';

    // Fwd direction uses ancestors only; rev/container uses ancestors + descendants.
    $groups_with_ancestors = htax_get_group_ancestors( $group_ids );
    $groups_all            = array_unique( array_merge( $groups_with_ancestors, htax_get_group_hierarchy( $group_ids ) ) );

    // --- A+B. Collect all affected term IDs in two queries, then batch-invalidate. ---
    // Two SQL queries collect the affected IDs, then two batch DELETE queries clear
    // every term's cache at once.
    $affected_term_ids = array();
    $includes_in       = htax_relationship_type_sql_in( 'includes' );

    // A. Inclusions fwd: T → group (ancestors) — terms whose archives include these groups.
    if ( ! empty( $groups_with_ancestors ) ) {
        $placeholders = htax_sql_placeholders( count( $groups_with_ancestors ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT source_term_id
                 FROM {$table}
                 WHERE target_term_id IN ({$placeholders})
                 AND target_taxonomy = 'group'
                 AND {$includes_in}",
                $groups_with_ancestors
            ),
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            $affected_term_ids[] = (int) $row['source_term_id'];
        }
    }

    // B. Inclusions rev/container: group → T — terms included by these groups.
    if ( ! empty( $groups_all ) ) {
        $placeholders = htax_sql_placeholders( count( $groups_all ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT target_term_id
                 FROM {$table}
                 WHERE source_term_id IN ({$placeholders})
                 AND source_taxonomy = 'group'
                 AND {$includes_in}",
                $groups_all
            ),
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            $affected_term_ids[] = (int) $row['target_term_id'];
        }
    }

    // Batch-delete transients for all affected terms (2 queries regardless of count).
    if ( ! empty( $affected_term_ids ) ) {
        htax_delete_term_transients_batch( array_unique( $affected_term_ids ) );
    }

    // --- C. Custom rel fwd: T → group (ancestors) → clear htax_crel_{slug}_T ---
    if ( ! empty( $groups_with_ancestors ) ) {
        $placeholders = htax_sql_placeholders( count( $groups_with_ancestors ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT source_term_id, relationship_type
                 FROM {$table}
                 WHERE target_term_id IN ({$placeholders})
                 AND target_taxonomy = 'group'",
                $groups_with_ancestors
            ),
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            $type_def = htax_get_relationship_type( $row['relationship_type'] );
            if ( $type_def && empty( $type_def['is_core'] ) ) {
                htax_delete_transient( 'htax_crel_' . $row['relationship_type'] . '_' . (int) $row['source_term_id'] );
            }
        }
    }

    // --- D. Custom rel rev/container: group (ancestors+descendants) → T → clear htax_crel_rev_{slug}_T ---
    if ( ! empty( $groups_all ) ) {
        $placeholders = htax_sql_placeholders( count( $groups_all ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT target_term_id, relationship_type
                 FROM {$table}
                 WHERE source_term_id IN ({$placeholders})
                 AND source_taxonomy = 'group'",
                $groups_all
            ),
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            $type_def = htax_get_relationship_type( $row['relationship_type'] );
            if ( $type_def && empty( $type_def['is_core'] ) ) {
                htax_delete_transient( 'htax_crel_rev_' . $row['relationship_type'] . '_' . (int) $row['target_term_id'] );
            }
        }
    }
}

/**
 * Clear a specific transient for all members of the given groups.
 *
 * Called when a group row changes without a membership change: the directly
 * affected term's own caches are cleared elsewhere; this handles the member side.
 *
 * Expansion rules:
 * - 'inc': expand to ancestors (htax_get_group_ancestors). G→T container row changes
 *   require clearing htax_inc_M for members of G AND ancestor groups, because path 3
 *   of htax_get_included_terms expands member groups downward, so ancestor-group
 *   members have G in their expanded set and reach the changed G→T row.
 * - 'cont': expand to descendants (htax_get_group_hierarchy). T→G included-group
 *   row changes require clearing htax_cont_M for members of G and descendant groups,
 *   matching htax_get_terms_in_groups downward expansion.
 * - 'crel' / 'crel_rev': expand to descendants (htax_get_group_hierarchy), as
 *   htax_get_terms_in_groups always expands downward for custom rel group expansion.
 *
 * @since 1.0.0
 * @param int[]  $group_ids Changed group IDs (not yet expanded).
 * @param string $mode      'inc' | 'cont' | 'crel' | 'crel_rev'.
 * @param string $cslug     Required for 'crel' and 'crel_rev' modes.
 */
function htax_clear_member_caches_for_groups( array $group_ids, string $mode, string $cslug = '' ): void {
    if ( empty( $group_ids ) ) {
        return;
    }

    $expanded = ( 'inc' === $mode )
        ? htax_get_group_ancestors( $group_ids )
        : htax_get_group_hierarchy( $group_ids );

    if ( empty( $expanded ) ) {
        return;
    }

    global $wpdb;
    $table        = $wpdb->prefix . 'htax_term_relationships';
    $placeholders = htax_sql_placeholders( count( $expanded ) );

    $member_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT source_term_id FROM {$table}
             WHERE target_term_id IN ({$placeholders})
             AND relationship_type = 'member_of'",
            $expanded
        )
    );

    foreach ( $member_ids as $member_id ) {
        $mid = (int) $member_id;
        switch ( $mode ) {
            case 'inc':
                htax_delete_transient( 'htax_inc_' . $mid );
                break;
            case 'cont':
                htax_delete_transient( 'htax_cont_' . $mid );
                break;
            case 'crel':
                htax_delete_transient( 'htax_crel_' . $cslug . '_' . $mid );
                break;
            case 'crel_rev':
                htax_delete_transient( 'htax_crel_rev_' . $cslug . '_' . $mid );
                break;
        }
    }
}

/**
 * Invalidate inclusion and custom relationship caches when a group's WP parent changes.
 *
 * When group G moves from old_parent to new_parent, any term T that includes
 * old_parent (or its ancestors) used to reach G's members via hierarchy expansion.
 * Similarly, T including new_parent now gains G's members. Both old and new parent
 * chains must be invalidated.
 *
 * Runs at priority 12 on edited_term (after htax_reload_term_caches_on_edit at 10
 * and htax_fire_archive_changed_on_hierarchy at 11, which skips group taxonomy).
 *
 * @since 1.0.0
 * @param int    $term_id  Edited term ID.
 * @param int    $tt_id    Term taxonomy ID (used to look up pre-edit parent).
 * @param string $taxonomy Taxonomy slug.
 */
function htax_invalidate_caches_on_group_reparent( int $term_id, int $tt_id, string $taxonomy ): void {
    if ( 'group' !== $taxonomy ) {
        return;
    }

    $old_data   = htax_get_transient( 'htax_term_data_' . $tt_id );
    $old_parent = isset( $old_data['parent'] ) ? (int) $old_data['parent'] : 0;

    $term = get_term( $term_id, 'group' );
    if ( ! $term || is_wp_error( $term ) ) {
        return;
    }
    $new_parent = (int) $term->parent;

    if ( $old_parent === $new_parent ) {
        return;
    }

    // Collect affected groups: both old and new parent chains.
    // htax_invalidate_group_relationship_caches handles ancestor expansion internally,
    // so passing [old_parent, new_parent] is sufficient.
    $affected = array_values( array_filter( array( $old_parent, $new_parent ) ) );
    if ( ! empty( $affected ) ) {
        htax_invalidate_group_relationship_caches( $affected );

        // Members of the old and new parent groups (and their ancestor groups) have
        // htax_inc_M stale: path 3 of htax_get_included_terms expands member groups
        // downward, so before reparent old-parent members reached G via that expansion,
        // and after reparent new-parent members gain G in their expansion.
        htax_clear_member_caches_for_groups( $affected, 'inc' );
    }
}

/**
 * Clear the site-wide hidden terms cache when a term's visibility meta changes.
 *
 * Hooked to added_term_meta/updated_term_meta/deleted_term_meta so the cache
 * is only invalidated when _htax_is_hidden actually changes, not on every term edit.
 *
 * @since 1.0.0
 *
 * @param int|string $meta_id  Meta ID (or array of IDs for deleted_term_meta).
 * @param int        $term_id  Term ID.
 * @param string     $meta_key Meta key.
 */
function htax_clear_hidden_terms_cache_on_meta( $meta_id, $term_id, $meta_key ) {
    if ( '_htax_is_hidden' === $meta_key ) {
        htax_delete_transient( 'htax_hidden_terms' );
    }
}

/**
 * Invalidate/update term-related caches when a term is deleted
 *
 * Strategy:
 * - UPDATE caches that just need this term removed from an array (surgical)
 * - DELETE this term's own caches (term won't exist anymore)
 * - DELETE caches for terms that reference this term (too complex to update)
 *
 * @since 1.0.0
 *
 * @param int|WP_Term $term_id_or_term Term ID or WP_Term object.
 * @param int         $tt_id           Term taxonomy ID (optional, used by hooks).
 * @param string      $taxonomy        Taxonomy slug (optional if WP_Term passed).
 */
function htax_invalidate_term_caches_on_delete( $term_id_or_term, $tt_id = 0, $taxonomy = '' ) {
    // Accept either term ID or WP_Term object
    if ( is_object( $term_id_or_term ) && isset( $term_id_or_term->term_id ) ) {
        $term_id = $term_id_or_term->term_id;
        if ( empty( $taxonomy ) ) {
            $taxonomy = $term_id_or_term->taxonomy;
        }
    } else {
        $term_id = (int) $term_id_or_term;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Collect the slugs of all non-core registered relationship types once.
    $non_core_slugs = array();
    foreach ( htax_get_registered_relationship_types() as $slug => $def ) {
        if ( empty( $def['is_core'] ) ) {
            $non_core_slugs[] = $slug;
        }
    }

    // === PRE-CLEAR: read T's outgoing data while caches are still valid ===
    // Outgoing data drives step 3b (clear inverse caches of terms T pointed to).
    // Direct caches have no TTL and are cleared together as a group, so if the two core
    // caches are warm the per-slug ones will be warm too — but we verify individually.
    $out_inc_ids     = htax_get_transient( 'htax_direct_rel_includes_' . $term_id ); // T→U core includes
    $out_inc_grp_ids = htax_get_transient( 'htax_direct_rel_grp_includes_' . $term_id ); // T→G core includes
    $out_crel_ids    = array(); // slug => int[]  (T→U per custom type)
    $out_crel_grp_ids = array(); // slug => int[] (T→G per custom type)

    $outgoing_from_cache = ( false !== $out_inc_ids && false !== $out_inc_grp_ids );
    if ( $outgoing_from_cache ) {
        foreach ( $non_core_slugs as $slug ) {
            $v = htax_get_transient( 'htax_direct_rel_' . $slug . '_' . $term_id );
            if ( false === $v ) {
                $outgoing_from_cache = false;
                break;
            }
            $out_crel_ids[ $slug ] = $v;
            $gv                    = htax_get_transient( 'htax_direct_rel_grp_' . $slug . '_' . $term_id );
            if ( false === $gv ) {
                $outgoing_from_cache = false;
                break;
            }
            $out_crel_grp_ids[ $slug ] = $gv;
        }
    }

    if ( ! $outgoing_from_cache ) {
        // One or more caches cold; run one broad outgoing query and categorize results.
        $out_inc_ids     = array();
        $out_inc_grp_ids = array();
        $out_crel_ids    = array();
        $out_crel_grp_ids = array();

        $includes_slugs = htax_get_relationship_type_slugs( 'includes' );
        $outgoing_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT target_term_id, target_taxonomy, relationship_type FROM {$table_name}
             WHERE source_term_id = %d",
            $term_id
        ), ARRAY_A );

        foreach ( $outgoing_rows as $row ) {
            $is_group  = ( 'group' === $row['target_taxonomy'] );
            $target_id = (int) $row['target_term_id'];
            $type_def  = htax_get_relationship_type( $row['relationship_type'] );
            $is_inc    = in_array( $row['relationship_type'], $includes_slugs, true );

            if ( $is_inc ) {
                if ( $is_group ) {
                    $out_inc_grp_ids[] = $target_id;
                } else {
                    $out_inc_ids[] = $target_id;
                }
            } elseif ( $type_def && empty( $type_def['is_core'] ) ) {
                if ( $is_group ) {
                    $out_crel_grp_ids[ $row['relationship_type'] ][] = $target_id;
                } else {
                    $out_crel_ids[ $row['relationship_type'] ][] = $target_id;
                }
            }
            // redirect and member_of outgoing rows require no inverse cache clearing.
        }
    }
    // === END PRE-CLEAR ===

    // 1. DELETE this term's own caches (term won't exist anymore)
    htax_delete_term_transients( $term_id );
    // Clear site-wide hidden terms cache — deleted term may have been hidden.
    htax_delete_transient( 'htax_hidden_terms' );
    // Clear sticky posts cache for this term (its archive no longer exists).
    htax_delete_transient( 'htax_sticky_' . (int) $term_id );

    // 2. Find member groups this term belongs to
    $member_groups = $wpdb->get_col( $wpdb->prepare(
        "SELECT target_term_id FROM {$table_name}
         WHERE source_term_id = %d
         AND target_taxonomy = 'group'
         AND relationship_type = 'member_of'",
        $term_id
    ) );

    // Surgically remove deleted term from group member caches.
    $all_member_groups = array();
    if ( ! empty( $member_groups ) ) {
        $all_member_groups = htax_get_group_ancestors( $member_groups );

        foreach ( $all_member_groups as $group_id ) {
            $cache_key      = 'htax_grp_' . $group_id . '_' . $taxonomy;
            $cached_members = htax_get_transient( $cache_key );
            if ( false !== $cached_members && is_array( $cached_members ) ) {
                unset( $cached_members[ $term_id ] );
                htax_set_transient( $cache_key, $cached_members, htax_get_cache_duration( 'relationship' ) );
            }
        }
    }

    // 3. Reload caches for all terms that had a relationship row pointing TO this term.
    // htax_reload_term_caches_on_edit covers every transient for those terms.
    // For group sources (G→T container rows), also clear group-member logical caches.
    $direct_references = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT source_term_id, source_taxonomy, relationship_type FROM {$table_name}
         WHERE target_term_id = %d",
        $term_id
    ), ARRAY_A );

    foreach ( $direct_references as $row ) {
        htax_reload_term_caches_on_edit( (int) $row['source_term_id'] );
        // When source is a group (G→T container row), members of G and G's ancestor groups
        // had T in their htax_inc_M via path 3 of htax_get_included_terms.
        if ( 'group' === $row['source_taxonomy'] ) {
            $type_def = htax_get_relationship_type( $row['relationship_type'] );
            if ( $type_def && ! empty( $type_def['is_core'] ) ) {
                htax_clear_member_caches_for_groups( array( (int) $row['source_term_id'] ), 'inc' );
            }
        }
    }

    // 3b. Clear inverse caches for terms this term pointed to (using pre-read outgoing data).
    foreach ( $out_inc_ids as $target_id ) {
        htax_delete_transient( 'htax_cont_' . $target_id );
        htax_delete_transient( 'htax_reverse_rel_includes_' . $target_id );
    }
    foreach ( $out_inc_grp_ids as $grp_id ) {
        // Clear the group's own direct-container cache: T (now deleted) was a non-group source pointing to G.
        htax_delete_transient( 'htax_reverse_rel_includes_' . $grp_id );
        htax_clear_member_caches_for_groups( array( $grp_id ), 'cont' );
    }
    foreach ( $out_crel_ids as $slug => $target_ids ) {
        foreach ( $target_ids as $target_id ) {
            htax_delete_transient( 'htax_crel_rev_' . $slug . '_' . $target_id );
            htax_delete_transient( 'htax_reverse_rel_' . $slug . '_' . $target_id );
        }
    }
    foreach ( $out_crel_grp_ids as $slug => $grp_ids ) {
        foreach ( $grp_ids as $grp_id ) {
            // Clear the group's own direct non-group-source rev cache: T (now deleted) pointed to G.
            htax_delete_transient( 'htax_reverse_rel_' . $slug . '_' . $grp_id );
            htax_clear_member_caches_for_groups( array( $grp_id ), 'crel_rev', $slug );
        }
    }

    // 4. DELETE caches for terms affected via group membership
    if ( ! empty( $all_member_groups ) ) {
        $placeholders = htax_sql_placeholders( count( $all_member_groups ) );
        $affected_via_groups = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT source_term_id FROM {$table_name}
             WHERE target_term_id IN ({$placeholders})",
            ...$all_member_groups
        ) );

        foreach ( $affected_via_groups as $affected_id ) {
            htax_delete_transient( 'htax_inc_' . $affected_id );
            htax_invalidate_contrib_cache( (int) $affected_id );
            // Under group-expanded custom rel caches, this term's fwd cache included
            // the deleted term via its groups. Clear all custom rel caches for it.
            htax_delete_custom_rel_caches_for_term( (int) $affected_id );
        }
    }

    // Clear count cache for deleted term
    if ( ! empty( $taxonomy ) ) {
        htax_clear_count_cache( array( $term_id ), $taxonomy );
    }
}

/**
 * Reload term-related caches when a term is edited
 *
 * Used for cache invalidation during term deletion and other non-relationship changes.
 * For relationship changes, cache is reloaded inside htax_save_term_relationships() BEFORE
 * actions fire, ensuring index handlers read NEW state.
 *
 * This function only invalidates caches - no eager reload needed here since:
 * - Relationship changes: Reloaded in htax_save_term_relationships() before actions fire
 * - Term deletion: Term no longer exists, no point reloading
 * - Other edits: Cache will lazy-load on next access
 *
 * @since 1.0.0
 *
 * @param int|WP_Term $term_id_or_term Term ID or WP_Term object.
 * @param int         $tt_id           Term taxonomy ID (optional, used by hooks).
 */
function htax_reload_term_caches_on_edit( $term_id_or_term, $tt_id = 0 ) {
    // Accept either term ID or WP_Term object
    if ( is_object( $term_id_or_term ) && isset( $term_id_or_term->term_id ) ) {
        $term_id = $term_id_or_term->term_id;
    } else {
        $term_id = (int) $term_id_or_term;
    }

    // Invalidate all caches for this term
    htax_delete_term_transients( $term_id );

    // Note: No eager reload here - cache reloaded in htax_save_term_relationships() or lazy-loads on access
}

/**
 * Surgical cache updates when term group membership changes
 *
 * When a term joins/leaves groups, this handler:
 * 1. Updates term's own group membership cache (invalidate + reload)
 * 2. Surgically updates group member caches for affected groups + ancestors
 * 3. Invalidates container cache (lazy-load pattern)
 * 4. Reloads term's other caches via htax_reload_term_caches_on_edit
 *
 * @since 1.0.0
 *
 * @param int    $term_id    Term ID
 * @param string $taxonomy   Taxonomy slug
 * @param int[]  $new_groups Array of new group IDs
 * @param int[]  $old_groups Array of old group IDs
 */
function htax_update_group_membership_caches( $term_id, $taxonomy, $new_groups, $old_groups ) {
    // 1. Invalidate and reload term's own group membership cache
    htax_delete_transient( 'htax_groups_' . $term_id );
    htax_get_term_group_ids( $term_id );

    // 2. Surgical update of group member caches
    $all_affected_groups = array_unique( array_merge( $new_groups, $old_groups ) );

    // Expand to ancestors (upward expansion)
    // When term joins Group B (child of Group A), both A and B caches need updating
    $groups_with_ancestors = htax_get_group_ancestors( $all_affected_groups );

    // Get term object for surgical updates
    $term = get_term( $term_id, $taxonomy );
    if ( is_wp_error( $term ) ) {
        return;
    }

    // Pre-compute ancestors for each new/old group once to avoid repeated lookups
    // inside the per-group iteration below.
    $new_ancestors_map = array();
    foreach ( $new_groups as $gid ) {
        $new_ancestors_map[ $gid ] = get_ancestors( $gid, 'group', 'taxonomy' );
    }
    $old_ancestors_map = array();
    foreach ( $old_groups as $gid ) {
        $old_ancestors_map[ $gid ] = get_ancestors( $gid, 'group', 'taxonomy' );
    }

    // Surgically update each affected group's member cache
    foreach ( $groups_with_ancestors as $group_id ) {
        $cache_key = 'htax_grp_' . $group_id . '_' . $taxonomy;
        $cached_members = htax_get_transient( $cache_key );

        if ( false === $cached_members ) {
            continue; // Cache doesn't exist yet, will be lazy-loaded later
        }

        // Determine if term should be in this group's cache
        // For direct groups: check if in new_groups
        // For ancestor groups: check if in new_groups (descendants already expanded in cache)
        $is_member_of_new  = in_array( $group_id, $new_groups, true );
        $was_member_of_old = in_array( $group_id, $old_groups, true );

        // For ancestor groups, need to check if term is member via any child
        if ( ! $is_member_of_new && ! $was_member_of_old ) {
            foreach ( $new_ancestors_map as $ancestors ) {
                if ( in_array( $group_id, $ancestors, true ) ) {
                    $is_member_of_new = true;
                    break;
                }
            }
            foreach ( $old_ancestors_map as $ancestors ) {
                if ( in_array( $group_id, $ancestors, true ) ) {
                    $was_member_of_old = true;
                    break;
                }
            }
        }

        // Surgical update
        if ( $is_member_of_new && ! isset( $cached_members[ $term_id ] ) ) {
            // Add term to cache
            $cached_members[ $term_id ] = $term;
            htax_set_transient( $cache_key, $cached_members, htax_get_cache_duration( 'relationship' ) ); // No TTL
        } elseif ( ! $is_member_of_new && isset( $cached_members[ $term_id ] ) ) {
            // Remove term from cache
            unset( $cached_members[ $term_id ] );
            htax_set_transient( $cache_key, $cached_members, htax_get_cache_duration( 'relationship' ) ); // No TTL
        }
        // else: No change needed for this group
    }

    // 3. Invalidate container cache (lazy-load pattern)
    // Group inclusion affects containers via Path 3
    htax_delete_transient( 'htax_cont_' . $term_id );

    // 4. Invalidate term's other caches (includes, redirects)
    // These will lazy-load on next access
    htax_reload_term_caches_on_edit( $term_id, 0 );

    // 5. Propagate the group membership change to all terms connected via the affected
    // groups. This involves querying every term that includes or is included by these
    // groups (potentially hundreds of terms) and invalidating their relationship caches.
    // Deferred to a background cron event so the save response is not blocked.
    wp_schedule_single_event( time(), 'htax_async_group_propagate', array( $all_affected_groups ) );
}

/**
 * Clear total post count cache
 *
 * Clears htax_total_* caches used for {total_count} shortcode placeholders.
 * Performs targeted invalidation when called with term IDs, or broad clear otherwise.
 *
 * @since 1.0.0
 *
 * @param array  $term_ids Array of term IDs to clear cache for
 * @param string $taxonomy Taxonomy name
 */
function htax_clear_count_cache( $term_ids = array(), $taxonomy = '' ) {
    // Broad clear if no terms provided
    if ( empty( $term_ids ) || empty( $taxonomy ) ) {
        htax_delete_transients_by_prefix( 'htax_total_' );
        return;
    }

    // Collect all affected term IDs and group IDs
    $affected_term_ids = (array) $term_ids;
    $affected_group_ids = array();

    foreach ( $affected_term_ids as $term_id ) {
        // If taxonomy is hierarchical (category, group), include ancestors
        if ( is_taxonomy_hierarchical( $taxonomy ) && function_exists( 'get_ancestors' ) ) {
            $ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
            if ( ! empty( $ancestors ) ) {
                $affected_term_ids = array_merge( $affected_term_ids, $ancestors );
            }
        }

        // If this term is a group, add to affected groups
        if ( 'group' === $taxonomy ) {
            $affected_group_ids[] = $term_id;
        }

        // Get all groups this term belongs to (+ their ancestors)
        $direct_groups = htax_get_term_groups( $term_id );
        foreach ( $direct_groups as $group ) {
            $affected_group_ids[] = $group->term_id;

            // Get ancestor groups
            $ancestors = get_ancestors( $group->term_id, 'group', 'taxonomy' );
            $affected_group_ids = array_merge( $affected_group_ids, $ancestors );
        }
    }

    // Deduplicate term IDs after adding ancestors
    $affected_term_ids = array_unique( $affected_term_ids );

    $affected_group_ids = array_unique( $affected_group_ids );

    // Delete any cache key containing any of these IDs
    htax_delete_count_cache_for_ids( $affected_group_ids, $affected_term_ids, $taxonomy );
}

/**
 * Wrapper for htax_clear_count_cache() for edited_{taxonomy} hooks
 *
 * @since 1.0.0
 *
 * @param int $term_id Term ID
 * @param int $tt_id Term taxonomy ID
 */
function htax_clear_count_cache_on_save( $term_id, $tt_id ) {
    // Get taxonomy from tt_id
    global $wpdb;
    $taxonomy = $wpdb->get_var( $wpdb->prepare(
        "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
        $tt_id
    ) );

    if ( $taxonomy ) {
        htax_clear_count_cache( array( $term_id ), $taxonomy );
    } else {
        // Fallback to broad clear
        htax_clear_count_cache();
    }
}

/**
 * Clear AJAX autocomplete caches when terms are edited or deleted
 *
 * Clears cached autocomplete results so users see updated term lists.
 * Note: These caches are short-lived (5 minutes by default) so this is
 * optional, but ensures immediate consistency.
 *
 * @since 1.0.0
 *
 * @param int $term_id Term ID.
 */
function htax_clear_ajax_caches( $term_id ) {
    // Clear autocomplete cache (htax_ac_*)
    htax_delete_transients_by_prefix( 'htax_ac_' );
}

// ==================================================
// CACHE HELPER FUNCTIONS
// ==================================================

/**
 * Delete transients matching a prefix pattern
 *
 * Deletes all WordPress transients that start with the specified prefix.
 * This is a generic helper for clearing caches by pattern.
 *
 * @since 1.0.0
 *
 * @param string $prefix Transient prefix to match (e.g., 'htax_count_').
 * @return int|false Number of rows deleted, or false if prefix empty.
 *
 * @example
 * // Clear all count caches
 * htax_delete_transients_by_prefix( 'htax_count_' );
 */
function htax_delete_transients_by_prefix( $prefix ) {
    global $wpdb;

    if ( empty( $prefix ) ) {
        return false;
    }

    // Delete both the transient and timeout records
    $result = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE %s
            OR option_name LIKE %s",
            '_transient_' . $wpdb->esc_like( $prefix ) . '%',
            '_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
        )
    );
    htax_bump_cache_family( htax_transient_family( $prefix ) );

    return $result;
}

/**
 * Delete count cache transients containing specific group or term IDs
 *
 * Performs targeted cache invalidation by clearing caches that contain ANY of the
 * specified group IDs or term IDs anywhere in their key (included or excluded position).
 * Pattern matching: %gX_% matches both igX_ and egX_, %tX_% matches both itX_ and etX_.
 *
 * @since 1.0.0
 *
 * @param array  $group_ids Array of group IDs to match
 * @param array  $term_ids  Array of term IDs to match
 * @param string $taxonomy  Taxonomy name
 * @return int|false Number of rows deleted, or false on error
 */
function htax_delete_count_cache_for_ids( $group_ids, $term_ids, $taxonomy ) {
    global $wpdb;

    if ( empty( $group_ids ) && empty( $term_ids ) ) {
        return false;
    }

    // Build LIKE clauses for each ID
    // Pattern: %gX_% matches both igX_ (include group) and egX_ (exclude group)
    // Pattern: %tX_% matches both itX_ (include term) and etX_ (exclude term)
    $like_clauses = array();
    $prepare_values = array();

    // Add patterns for groups: g{id}_
    $prefix_escaped         = $wpdb->esc_like( '_transient_htax_total_' );
    $timeout_prefix_escaped = $wpdb->esc_like( '_transient_timeout_htax_total_' );
    $sep_escaped            = $wpdb->esc_like( '_' );
    $taxonomy_escaped       = $wpdb->esc_like( $taxonomy );
    $timeout_clauses        = array();
    foreach ( $group_ids as $group_id ) {
        $like_clauses[]    = "option_name LIKE %s";
        $timeout_clauses[] = "option_name LIKE %s";
        $suffix = '%g' . intval( $group_id ) . $sep_escaped . '%' . $sep_escaped . $taxonomy_escaped;
        $prepare_values[]  = $prefix_escaped . $suffix;
        $prepare_values[]  = $timeout_prefix_escaped . $suffix;
    }

    // Add patterns for terms: t{id}_
    foreach ( $term_ids as $term_id ) {
        $like_clauses[]    = "option_name LIKE %s";
        $timeout_clauses[] = "option_name LIKE %s";
        $suffix = '%t' . intval( $term_id ) . $sep_escaped . '%' . $sep_escaped . $taxonomy_escaped;
        $prepare_values[]  = $prefix_escaped . $suffix;
        $prepare_values[]  = $timeout_prefix_escaped . $suffix;
    }

    // Build combined WHERE clause
    $all_clauses = array_merge( $like_clauses, $timeout_clauses );
    $full_where  = implode( ' OR ', $all_clauses );

    // Execute deletion
    $result = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE $full_where",
            $prepare_values
        )
    );
    htax_bump_cache_family( 'total' );

    return $result;
}

/**
 * Invalidate contributing terms cache for a specific term
 *
 * The contributing terms cache is page-based (htax_contrib_{term_id}_p{page}).
 * When posts_per_page changes, page boundaries shift, invalidating all pages.
 *
 * @since 1.0.0
 *
 * @param int $term_id Term ID to clear cache for
 */
function htax_invalidate_contrib_cache( $term_id ) {
    static $cleared = array();

    $term_id = (int) $term_id;
    if ( isset( $cleared[ $term_id ] ) ) {
        return; // Already cleared this request — e.g. htax_delete_term_transients and
                // htax_term_archive_changed both fire for the same term on edited_term.
    }
    $cleared[ $term_id ] = true;

    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
         OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_htax_contrib_' . $term_id ) . '%',
        $wpdb->esc_like( '_transient_timeout_htax_contrib_' . $term_id ) . '%'
    ) );
    // Contrib keys are not enumerable per term in an object cache; bump the family.
    htax_bump_cache_family( 'contrib' );
}


/**
 * Expand a set of directly-affected term IDs to all archive terms whose
 * htax_contrib_ cache must be invalidated when posts are assigned to or
 * removed from those terms.
 *
 * A post in term T appears in the archives of:
 * - T itself
 * - Every term that directly includes T (htax_get_container_terms), which
 *   queries the relationships table directly and is always current
 * - Every hierarchical ancestor of T (via WP's include_children behavior)
 *
 * Does NOT use the search index because the index is rebuilt asynchronously
 * at shutdown — it reflects pre-change state when this runs.
 *
 * @since 1.0.0
 *
 * @param int[]  $term_ids Direct term IDs affected by the post assignment change.
 * @param string $taxonomy Taxonomy slug of those terms.
 * @return int[] Expanded set of term IDs whose contrib caches should be cleared.
 */
function htax_expand_term_ids_for_contrib_invalidation( $term_ids, $taxonomy ) {
    $all_ids = array_values( array_filter( array_unique( array_map( 'intval', (array) $term_ids ) ) ) );

    foreach ( $all_ids as $term_id ) {
        $containers = htax_get_container_terms( $term_id );
        if ( ! empty( $containers ) ) {
            $all_ids = array_merge( $all_ids, array_keys( $containers ) );
        }
        if ( is_taxonomy_hierarchical( $taxonomy ) ) {
            $ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
            if ( ! empty( $ancestors ) ) {
                $all_ids = array_merge( $all_ids, $ancestors );
            }
        }
    }

    return array_values( array_unique( $all_ids ) );
}

/**
 * Invalidate all contributing terms caches
 *
 * Called when global posts_per_page setting changes.
 * This affects all term archives, so clear all contrib caches.
 *
 * @since 1.0.0
 */
function htax_invalidate_all_contrib_caches() {
    global $wpdb;

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
         OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_htax_contrib_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_htax_contrib_' ) . '%'
    ) );
    htax_bump_cache_family( 'contrib' );
}

/**
 * Fire htax_term_archive_changed for parent terms when a term's WP parent changes.
 *
 * When T moves from P_old to P_new, T's posts enter/leave those parents' archives
 * (and their ancestors') via WP's include_children behavior. The fine-grained HT
 * relationship actions don't cover this case because it is a WP taxonomy change.
 *
 * Runs at priority 11 on edited_term, after htax_reload_term_caches_on_edit (10)
 * and the search index handler, both of which need the old parent data from the
 * htax_term_data_ transient.
 *
 * @since 1.0.0
 * @param int    $term_id  Term ID whose parent may have changed.
 * @param int    $tt_id    Term taxonomy ID (key for pre-edit data transient).
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function htax_fire_archive_changed_on_hierarchy( int $term_id, int $tt_id, string $taxonomy ): void {
    if ( ! is_taxonomy_hierarchical( $taxonomy ) || 'group' === $taxonomy ) {
        return;
    }

    $old_data   = htax_get_transient( 'htax_term_data_' . $tt_id );
    $old_parent = isset( $old_data['parent'] ) ? (int) $old_data['parent'] : 0;

    $term = get_term( $term_id, $taxonomy );
    if ( ! $term || is_wp_error( $term ) ) {
        return;
    }
    $new_parent = (int) $term->parent;

    if ( $old_parent === $new_parent ) {
        return;
    }

    $affected = array();
    foreach ( array( $old_parent, $new_parent ) as $parent_id ) {
        if ( $parent_id > 0 ) {
            $affected[] = $parent_id;
            $ancestors  = get_ancestors( $parent_id, $taxonomy, 'taxonomy' );
            if ( ! empty( $ancestors ) ) {
                $affected = array_merge( $affected, $ancestors );
            }
        }
    }

    foreach ( array_unique( $affected ) as $affected_id ) {
        // Clear inclusion/container caches for all affected ancestors. When the reparented
        // term becomes a new descendant of these ancestors, the never_include list inside
        // htax_get_included_terms changes — any indirect group inclusion of the reparented
        // term must be excluded. Clearing the transient forces a fresh read that applies
        // the updated never_include set. htax_invalidate_contrib_cache (via the action
        // below) is not enough: it does not touch htax_inc_* or htax_cont_*.
        htax_delete_transient( 'htax_inc_' . $affected_id );
        htax_delete_transient( 'htax_cont_' . $affected_id );
        do_action( 'htax_term_archive_changed', $affected_id, $taxonomy );
    }
}

/**
 * Fire the htax_term_archive_changed action.
 *
 * Named callback used by add_action() registrations in actions.php to bridge
 * fine-grained relationship change actions (htax_includes_changed, etc.) to the
 * single unified signal consumed by cache invalidation and external integrations.
 *
 * @since 1.0.0
 * @param int    $term_id  Term ID whose archive changed.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function htax_fire_term_archive_changed( int $term_id, string $taxonomy ): void {
    /**
     * Fires after any change that affects a term's archive composition.
     *
     * @since 1.0.0
     * @param int    $term_id  Term ID whose archive changed.
     * @param string $taxonomy Taxonomy slug.
     */
    do_action( 'htax_term_archive_changed', $term_id, $taxonomy );
}

// ==================================================
// POST LIST SHORTCODE CACHE
// ==================================================

/**
 * Invalidate post list shortcode transients that contain any of the given term IDs.
 *
 * Post list cache keys embed term IDs directly as `t{id}_` segments (e.g.
 * `htax_pl_t3_t7_t12_<params_hash>`), so targeted invalidation is done with
 * LIKE patterns — no reverse-index lookup required.
 *
 * Pattern `%t{id}_%` may produce minor over-invalidation (e.g. t1 matches t10)
 * but this is acceptable: it mirrors the approach used by htax_delete_count_cache_for_ids().
 *
 * @since 1.0.0
 *
 * @param int[] $term_ids Term IDs whose dependent post list caches should be cleared.
 */
function htax_invalidate_post_list_cache( $term_ids ) {
    global $wpdb;

    $term_ids = array_values( array_filter( array_unique( array_map( 'intval', (array) $term_ids ) ) ) );
    if ( empty( $term_ids ) ) {
        return;
    }

    $clauses = array();
    $values  = array();
    foreach ( $term_ids as $tid ) {
        // Match as a main term (t{id}_), individual filter term (f{id}_),
        // or filter group (g{id}_). Group IDs are passed via htax_delete_term_transients
        // when a group is edited, so g{id} entries are invalidated automatically.
        $sep = $wpdb->esc_like( '_' );
        $clauses[] = 'option_name LIKE %s';
        $clauses[] = 'option_name LIKE %s';
        $clauses[] = 'option_name LIKE %s';
        $clauses[] = 'option_name LIKE %s';
        $clauses[] = 'option_name LIKE %s';
        $clauses[] = 'option_name LIKE %s';
        $values[]  = '_transient_htax_pl_%t' . $tid . $sep . '%';
        $values[]  = '_transient_timeout_htax_pl_%t' . $tid . $sep . '%';
        $values[]  = '_transient_htax_pl_%f' . $tid . $sep . '%';
        $values[]  = '_transient_timeout_htax_pl_%f' . $tid . $sep . '%';
        $values[]  = '_transient_htax_pl_%g' . $tid . $sep . '%';
        $values[]  = '_transient_timeout_htax_pl_%g' . $tid . $sep . '%';
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE " . implode( ' OR ', $clauses ),
            $values
        )
    );
    htax_bump_cache_family( 'pl' );
}

/**
 * Convert term taxonomy IDs to term IDs.
 *
 * @since 1.0.0
 *
 * @param  int[]|mixed $tt_ids Term taxonomy IDs.
 * @return int[]
 */
function htax_tt_ids_to_term_ids( $tt_ids ) {
    global $wpdb;
    $tt_ids = array_values( array_filter( array_map( 'intval', (array) $tt_ids ) ) );
    if ( empty( $tt_ids ) ) {
        return array();
    }
    $placeholders = htax_sql_placeholders( count( $tt_ids ) );
    return array_map(
        'intval',
        $wpdb->get_col( $wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($placeholders)",
            $tt_ids
        ) )
    );
}

/**
 * Return all term IDs currently in the search index for a given post.
 *
 * The search index pre-computes every relationship type (direct, container,
 * hierarchy, redirect_target), so this covers all terms whose post lists
 * would include this post — without needing a separate container expansion.
 *
 * @since 1.0.0
 *
 * @param  int $post_id Post ID.
 * @return int[]
 */
function htax_get_post_index_term_ids( $post_id ) {
    global $wpdb;
    return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT term_id FROM {$wpdb->prefix}htax_search_terms WHERE post_id = %d",
        (int) $post_id
    ) ) );
}

/**
 * Invalidate post list caches when post-term assignments change.
 *
 * Hooks on set_object_terms (fires at the END of wp_set_object_terms) with 6 args
 * so we get both the new ($tt_ids) and the previous ($old_tt_ids) term taxonomy IDs
 * in a single call, covering both added and removed terms without a separate lookup.
 *
 * Merges direct term IDs with the search-index term IDs for this post (which already
 * includes all container, hierarchy, and redirect-target terms) so that post lists
 * for indirectly-associated terms are also invalidated.
 *
 * @since 1.0.0
 *
 * @param int    $object_id  Post ID.
 * @param array  $_terms     Raw terms being set (IDs, slugs, or names — not used here).
 * @param int[]  $tt_ids     New term taxonomy IDs after the update.
 * @param string $taxonomy   Taxonomy slug.
 * @param bool   $_append    Whether terms were appended (vs. replaced) — not used here.
 * @param int[]  $old_tt_ids Term taxonomy IDs before the update.
 */
function htax_post_list_cache_on_object_terms_set( $object_id, $_terms, $tt_ids, $taxonomy, $_append, $old_tt_ids ) {
    $indexed_types = htax_get_indexed_post_types();
    $tax_obj       = get_taxonomy( $taxonomy );
    if ( ! $tax_obj || empty( array_intersect( $indexed_types, (array) $tax_obj->object_type ) ) ) {
        return;
    }
    $post = get_post( $object_id );
    if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, $indexed_types, true ) ) {
        return;
    }

    $new_term_ids = htax_tt_ids_to_term_ids( $tt_ids );
    $old_term_ids = htax_tt_ids_to_term_ids( $old_tt_ids );
    $direct_ids   = array_unique( array_merge( $new_term_ids, $old_term_ids ) );
    if ( empty( $direct_ids ) ) {
        return;
    }

    $index_ids    = htax_get_post_index_term_ids( $object_id );
    $all_term_ids = array_unique( array_merge( $direct_ids, $index_ids ) );
    htax_invalidate_post_list_cache( $all_term_ids );
    foreach ( htax_expand_term_ids_for_contrib_invalidation( $direct_ids, $taxonomy ) as $affected_id ) {
        do_action( 'htax_term_archive_changed', $affected_id, $taxonomy );
    }
}

/**
 * Invalidate post list caches when term relationships are deleted directly,
 * i.e. via wp_remove_object_terms() rather than wp_set_object_terms().
 *
 * When called within a wp_set_object_terms flow, set_object_terms fires at the
 * end of that function and handles the complete old-vs-new delta — so we skip
 * here to avoid double invalidation.
 *
 * @since 1.0.0
 *
 * @param int    $object_id Post ID.
 * @param int[]  $tt_ids    Removed term taxonomy IDs.
 * @param string $taxonomy  Taxonomy slug.
 */
function htax_post_list_cache_on_term_relationships_deleted( $object_id, $tt_ids, $taxonomy ) {
    // set_object_terms handles this case when we're inside wp_set_object_terms.
    if ( doing_action( 'set_object_terms' ) ) {
        return;
    }

    $indexed_types = htax_get_indexed_post_types();
    $tax_obj       = get_taxonomy( $taxonomy );
    if ( ! $tax_obj || empty( array_intersect( $indexed_types, (array) $tax_obj->object_type ) ) ) {
        return;
    }

    $term_ids = htax_tt_ids_to_term_ids( $tt_ids );
    if ( empty( $term_ids ) ) {
        return;
    }

    $index_ids    = htax_get_post_index_term_ids( $object_id );
    $all_term_ids = array_unique( array_merge( $term_ids, $index_ids ) );
    htax_invalidate_post_list_cache( $all_term_ids );
    foreach ( htax_expand_term_ids_for_contrib_invalidation( $term_ids, $taxonomy ) as $affected_id ) {
        do_action( 'htax_term_archive_changed', $affected_id, $taxonomy );
    }
}

/**
 * Invalidate post list caches when a post is published or unpublished.
 *
 * When publish state changes, all post list caches for the post's terms become
 * stale (the post now appears in or disappears from those archives). The search
 * index already records all direct and indirect term associations, so querying
 * it covers container, hierarchy, and redirect-target terms without separate expansion.
 *
 * @since 1.0.0
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 */
function htax_post_list_cache_on_post_status_change( $new_status, $old_status, $post ) {
    if ( ( 'publish' === $new_status ) === ( 'publish' === $old_status ) ) {
        return; // Published state did not change.
    }

    // Post list shortcode cache: use the search index (covers containers + hierarchy).
    $index_ids = htax_get_post_index_term_ids( $post->ID );
    if ( ! empty( $index_ids ) ) {
        htax_invalidate_post_list_cache( $index_ids );
    }

    // Contrib cache: index may be empty on first publish (built async at shutdown),
    // so read actual term assignments and expand term-centrically.
    $enabled_taxonomies = htax_get_enabled_taxonomies();
    if ( empty( $enabled_taxonomies ) ) {
        return;
    }
    $assigned_terms = wp_get_object_terms( $post->ID, $enabled_taxonomies, array( 'fields' => 'all' ) );
    if ( is_wp_error( $assigned_terms ) || empty( $assigned_terms ) ) {
        return;
    }
    $terms_by_taxonomy = array();
    foreach ( $assigned_terms as $term_obj ) {
        $terms_by_taxonomy[ $term_obj->taxonomy ][] = $term_obj->term_id;
    }
    foreach ( $terms_by_taxonomy as $taxonomy => $tids ) {
        foreach ( htax_expand_term_ids_for_contrib_invalidation( $tids, $taxonomy ) as $affected_id ) {
            do_action( 'htax_term_archive_changed', $affected_id, $taxonomy );
        }
    }
}
