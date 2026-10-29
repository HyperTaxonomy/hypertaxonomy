<?php
/**
 * Search state tokens for POST-mode search forms (Post/Redirect/Get).
 *
 * A search widget or PowerSearch form without an AJAX container submits by POST. The
 * submission is verified once, its state is packed into a token, and the browser is
 * redirected (303) to the same URL with `?htax_q=<token>`. Results pages are then plain
 * GET requests: WordPress keeps the query string in its pagination links, so every
 * page re-runs the same search; back/refresh, bookmarks, shared links and new tabs
 * work; and page caches can serve them. On a request carrying a token, the state is
 * restored into $_POST before anything reads it, so the existing POST-mode handlers
 * and form-restoration code run unchanged.
 *
 * Token formats:
 *   z<base64url(deflate(json))>  — the state itself, used when it is short enough
 *                                  for a URL (durable: needs no storage)
 *   k<16 hex>                    — key of a transient holding a larger state
 *                                  (content-addressed; expires after HTAX_SEARCH_STATE_TTL)
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Query parameter carrying the search state token. */
const HTAX_SEARCH_STATE_PARAM = 'htax_q';

/** Longest inline token; larger states are stored server-side. */
const HTAX_SEARCH_STATE_MAX_INLINE = 1500;

/** Lifetime of a stored (k-type) search state, in seconds. */
const HTAX_SEARCH_STATE_TTL = 30 * DAY_IN_SECONDS;

/**
 * Encode a search state as a token.
 *
 * @since 1.0.0
 * @param array $state Unslashed request fields.
 * @return string Token.
 */
function htax_search_state_encode( array $state ): string {
    $json   = (string) wp_json_encode( $state );
    $inline = 'z' . rtrim( strtr( base64_encode( (string) gzdeflate( $json, 9 ) ), '+/', '-_' ), '=' );
    if ( strlen( $inline ) <= HTAX_SEARCH_STATE_MAX_INLINE ) {
        return $inline;
    }

    $key = substr( md5( $json ), 0, 16 );
    htax_set_transient( 'htax_ss_' . $key, $state, HTAX_SEARCH_STATE_TTL );
    return 'k' . $key;
}

/**
 * Decode a search state token.
 *
 * @since 1.0.0
 * @param string $token Token from htax_search_state_encode().
 * @return array|null Unslashed request fields, or null when the token is malformed or
 *                    its stored state has expired.
 */
function htax_search_state_decode( string $token ): ?array {
    $type = substr( $token, 0, 1 );
    $body = substr( $token, 1 );

    if ( 'z' === $type && '' !== $body && strlen( $body ) <= HTAX_SEARCH_STATE_MAX_INLINE ) {
        $raw = base64_decode( strtr( $body, '-_', '+/' ), true );
        // Cap the inflated size: a crafted token must not expand without limit.
        $json  = false !== $raw ? @gzinflate( $raw, 65536 ) : false;
        $state = false !== $json ? json_decode( $json, true ) : null;
        return is_array( $state ) ? $state : null;
    }

    if ( 'k' === $type && preg_match( '/^[0-9a-f]{16}$/', $body ) ) {
        $state = htax_get_transient( 'htax_ss_' . $body );
        return is_array( $state ) ? $state : null;
    }

    return null;
}

/**
 * Whether the current request's search state was restored from a token.
 *
 * Such requests were verified (nonce) when the form was submitted; the POST-mode
 * handlers skip their own nonce check for them, since a results URL is opened later,
 * possibly by another visitor, after the nonce has expired.
 *
 * @since 1.0.0
 * @param bool|null $set Internal: mark the request (used by htax_restore_search_state()).
 * @return bool
 */
function htax_is_search_state_request( ?bool $set = null ): bool {
    static $restored = false;
    if ( null !== $set ) {
        $restored = $set;
    }
    return $restored;
}

/**
 * Redirect a verified POST-mode search submission to its tokenized results URL.
 *
 * Hook: init (priority 1). Stores every submitted field except nonces, the referer and
 * the pills' term-label data (the server rebuilds labels from the term IDs). Submissions
 * with an invalid nonce are left alone and rejected by the handlers as before. The
 * redirect target is the URL the form posted to, minus any /page/N/ and previous token,
 * so a new search starts on page 1.
 *
 * @since 1.0.0
 */
function htax_redirect_search_submission(): void {
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || wp_doing_ajax() || is_admin() ) {
        return;
    }

    if ( ! empty( $_POST['htax_post_search'] ) ) {
        $nonce_field  = 'htax_search_nonce';
        $nonce_action = 'htax-search-widget';
    } elseif ( ! empty( $_POST['htax_power_post_search'] ) ) {
        $nonce_field  = 'htax_power_search_nonce';
        $nonce_action = 'htax-power-search';
    } else {
        return;
    }

    $nonce = isset( $_POST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
        return;
    }

    $state = array();
    foreach ( wp_unslash( $_POST ) as $key => $value ) {
        if ( in_array( $key, array( $nonce_field, '_wp_http_referer' ), true ) || false !== strpos( (string) $key, '_termdata' ) ) {
            continue;
        }
        $state[ $key ] = $value;
    }

    $path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
    $path = (string) preg_replace( '#/page/\d+/?$#', '/', $path );
    $args = array();
    parse_str( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ), $args );
    unset( $args[ HTAX_SEARCH_STATE_PARAM ], $args['paged'] );
    $args[ HTAX_SEARCH_STATE_PARAM ] = htax_search_state_encode( $state );

    wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), home_url( $path ) ), 303 );
    exit;
}

/**
 * Restore a tokenized search state into $_POST.
 *
 * Hook: init (priority 0). Runs after WordPress has slashed the request superglobals, so
 * the restored values are slashed to match what a real POST would contain. An invalid or
 * expired token is ignored and the page renders as if no search had been submitted.
 *
 * @since 1.0.0
 */
function htax_restore_search_state(): void {
    if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_GET[ HTAX_SEARCH_STATE_PARAM ] ) || is_admin() ) {
        return;
    }
    $state = htax_search_state_decode( sanitize_text_field( wp_unslash( $_GET[ HTAX_SEARCH_STATE_PARAM ] ) ) );
    if ( null === $state || ( empty( $state['htax_post_search'] ) && empty( $state['htax_power_post_search'] ) ) ) {
        return;
    }
    foreach ( wp_slash( $state ) as $key => $value ) {
        $_POST[ $key ]    = $value;
        $_REQUEST[ $key ] = $value;
    }
    htax_is_search_state_request( true );
}

/**
 * Show one page of search results in a main query, with the search's true total.
 *
 * The search engine has already applied every filter and the page's LIMIT, so the query
 * loads exactly these posts (from row 0, no sticky posts, every indexed post type) and
 * reports $total as found_posts, from which WordPress computes max_num_pages
 * for the theme's pagination links. See htax_search_page_post_limits() and
 * htax_search_page_found_posts().
 *
 * @since 1.0.0
 * @param WP_Query $query          Main query.
 * @param int[]    $post_ids       Post IDs of the current page, in order.
 * @param int      $total          Total results across all pages.
 * @param int      $posts_per_page Page size.
 * @param int      $paged          Current page number.
 */
function htax_apply_search_page_to_query( WP_Query $query, array $post_ids, int $total, int $posts_per_page, int $paged ): void {
    $query->set( 'post_type', htax_get_indexed_post_types() );
    $query->set( 'post_status', 'publish' );
    $query->set( 'ignore_sticky_posts', true );
    if ( empty( $post_ids ) ) {
        $query->set( 'post__in', array( 0 ) );
        return;
    }
    $query->set( 'post__in', $post_ids );
    $query->set( 'orderby', 'post__in' );
    $query->set( 'posts_per_page', max( 1, $posts_per_page ) );
    $query->set( 'paged', max( 1, $paged ) );
    $query->set( 'htax_search_total', max( 0, $total ) );
}

/**
 * Start the LIMIT of a query set up by htax_apply_search_page_to_query() at row 0.
 *
 * Hook: post_limits. Its post__in already holds just the current page, so WordPress's
 * page offset would skip past it. The LIMIT is kept (rather than dropped) because
 * WordPress only computes max_num_pages for a query that has one.
 *
 * @since 1.0.0
 * @param string   $limits LIMIT clause.
 * @param WP_Query $query  Query.
 * @return string
 */
function htax_search_page_post_limits( $limits, $query ) {
    if ( ! ( $query instanceof WP_Query ) || '' === $query->get( 'htax_search_total' ) ) {
        return $limits;
    }
    return 'LIMIT 0, ' . max( 1, (int) $query->get( 'posts_per_page' ) );
}

/**
 * Report the search's total as found_posts for a query set up by
 * htax_apply_search_page_to_query().
 *
 * Hook: found_posts.
 *
 * @since 1.0.0
 * @param int      $found_posts Found posts.
 * @param WP_Query $query       Query.
 * @return int
 */
function htax_search_page_found_posts( $found_posts, $query ) {
    return ( $query instanceof WP_Query && '' !== $query->get( 'htax_search_total' ) ) ? (int) $query->get( 'htax_search_total' ) : $found_posts;
}

/**
 * Current results page of a main search query: /page/N/ on archives and the front page
 * sets `paged`; on a singular page URL WordPress uses `page`.
 *
 * @since 1.0.0
 * @param WP_Query $query Main query.
 * @return int
 */
function htax_get_search_page_number( WP_Query $query ): int {
    return max( 1, (int) $query->get( 'paged' ), (int) $query->get( 'page' ) );
}

/**
 * Track whether a WordPress search form (classic or core/search block) is being rendered.
 *
 * Hooks: pre_get_search_form / pre_render_block (start) and get_search_form /
 * render_block_core/search (end), all as pass-through filters.
 *
 * @since 1.0.0
 * @param mixed $value Filtered value, returned unchanged.
 * @param array $block Block being rendered (pre_render_block only).
 * @return mixed
 */
function htax_track_search_form_rendering( $value = null, $block = array() ) {
    $hook = current_action();
    if ( 'pre_get_search_form' === $hook ) {
        htax_is_rendering_search_form( true );
    } elseif ( 'pre_render_block' === $hook ) {
        if ( is_array( $block ) && 'core/search' === ( $block['blockName'] ?? '' ) ) {
            htax_is_rendering_search_form( true );
        }
    } else {
        htax_is_rendering_search_form( false );
    }
    return $value;
}

/**
 * Whether WordPress's search form is being rendered (see htax_track_search_form_rendering()).
 *
 * @since 1.0.0
 * @param bool|null $set Internal: set the state.
 * @return bool
 */
function htax_is_rendering_search_form( ?bool $set = null ): bool {
    static $rendering = false;
    if ( null !== $set ) {
        $rendering = $set;
    }
    return $rendering;
}

/**
 * Show the text a HyperTaxonomy search ran for wherever the theme displays the search
 * query (titles, "Search results for …").
 *
 * Hook: get_search_query. The search handlers clear `s` (so WordPress doesn't run its
 * own search) and keep the text in the `htax_search_text` query var. WordPress's own
 * search forms are prefilled with it on native `?s=` searches, and on PowerSearch /
 * search widget results when Site Search Integration is on (that form then runs the same
 * engine). With the integration off, submitting that form would run WordPress's own,
 * different search, so it is left empty.
 *
 * @since 1.0.0
 * @param string $search_query Current search query.
 * @return string
 */
function htax_filter_search_query_text( $search_query ) {
    global $wp_query;
    if ( htax_is_rendering_search_form() && empty( $_GET['s'] ) ) {
        $options = get_option( 'htax_settings', array() );
        if ( empty( $options['always_enhance_search'] ) ) {
            return $search_query;
        }
    }
    if ( '' === (string) $search_query && $wp_query instanceof WP_Query ) {
        $text = (string) $wp_query->get( 'htax_search_text' );
        if ( '' !== $text ) {
            return $text; // get_search_query() escapes the filtered value.
        }
    }
    return $search_query;
}
