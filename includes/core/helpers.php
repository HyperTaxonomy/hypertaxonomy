<?php
/**
 * Core Helper Functions
 *
 * General-purpose utilities used throughout HyperTaxonomy:
 * - SQL utilities (placeholders, ID sanitization)
 * - Term validation and retrieval
 * - Data parsing and formatting
 * - POST data handling
 * - Admin notices
 * - Nonce verification
 *
 * For domain-specific helpers, see:
 * - includes/core/term-visibility.php (hidden term logic)
 * - includes/core/search.php (all search-related functions)
 * - includes/core/cache.php (cache helper functions)
 * - includes/core/term-relationships.php (relationship helper functions)
 * - includes/admin/term-filters.php (filter helper functions)
 * - includes/admin/ajax.php (autocomplete helper functions)
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================================================
// SQL & DATA SANITIZATION
// ==================================================

/**
 * Generate SQL placeholders for prepared statements
 *
 * Creates a comma-separated string of SQL placeholders (%d, %s, etc.) for use
 * in wpdb->prepare() statements with arrays of values.
 *
 * @since 1.0.0
 *
 * @param int    $count Number of placeholders to generate
 * @param string $type  Placeholder type: 'd' for %d (integer), 's' for %s (string), 'f' for %f (float)
 * @return string Comma-separated placeholders (e.g., "%d,%d,%d"), empty string if count < 1
 *
 * @example
 * // Generate placeholders for array of term IDs
 * $term_ids = array(1, 2, 3, 4, 5);
 * $placeholders = htax_sql_placeholders( count( $term_ids ) ); // Returns "%d,%d,%d,%d,%d"
 * $sql = "SELECT * FROM table WHERE id IN ($placeholders)";
 * $wpdb->get_results( $wpdb->prepare( $sql, ...$term_ids ) );
 */
function htax_sql_placeholders( $count, $type = 'd' ) {
    if ( $count < 1 ) {
        return '';
    }

    // Validate type
    $valid_types = array( 'd', 's', 'f' );
    if ( ! in_array( $type, $valid_types, true ) ) {
        $type = 'd'; // Default to integer
    }

    return implode( ',', array_fill( 0, $count, '%' . $type ) );
}

/**
 * Sanitize and validate an array of term IDs
 *
 * Converts an array of term IDs to integers, removes any invalid values (0, negative, non-numeric),
 * removes duplicates, and returns a clean array of positive integers.
 *
 * This is a common pattern used throughout the plugin when handling arrays of term IDs
 * from user input, database queries, or function parameters.
 *
 * @since 1.0.0
 *
 * @param array $term_ids Array of term IDs (can be strings, ints, or mixed)
 * @return array Array of unique positive integers, empty array if no valid IDs
 *
 * @example
 * // Clean user input
 * $input = array( '1', '2', '0', '-5', 'abc', '2', 3 );
 * $clean = htax_sanitize_term_ids( $input ); // Returns array( 1, 2, 3 )
 *
 * @example
 * // Safe to use in database queries
 * $term_ids = htax_sanitize_term_ids( $_POST['included_terms'] );
 * if ( ! empty( $term_ids ) ) {
 *     $placeholders = htax_sql_placeholders( count( $term_ids ) );
 *     $sql = "SELECT * FROM table WHERE term_id IN ($placeholders)";
 *     $results = $wpdb->get_results( $wpdb->prepare( $sql, ...$term_ids ) );
 * }
 */
function htax_sanitize_term_ids( $term_ids ) {
    if ( ! is_array( $term_ids ) ) {
        return array();
    }

    // Convert to integers and filter out invalid values (0, negative, non-numeric)
    $sanitized = array_filter( array_map( 'intval', $term_ids ), function( $id ) {
        return $id > 0;
    } );

    // Remove duplicates and re-index array
    return array_values( array_unique( $sanitized ) );
}

/**
 * Parse a comma-separated term list into term IDs.
 *
 * Numeric tokens are treated as term IDs. When $taxonomy is given, non-numeric
 * tokens are resolved as term slugs within that taxonomy (slugs are unique per
 * taxonomy), so callers may accept slugs as an alternative to IDs. Without a
 * taxonomy, only numeric IDs are kept (non-numeric tokens are dropped) — the
 * original behavior. Slugs are resolved to IDs here, before any cache key is
 * built from the result, so caching stays consistent.
 *
 * @since 1.0.0
 * @param string $meta_value Comma-separated term IDs and/or slugs.
 * @param string $taxonomy   Taxonomy to resolve slugs against. Empty = IDs only.
 * @return int[] Array of term IDs (empty array if none valid).
 *
 * @example
 * $ids = htax_parse_term_ids( '1,2,3' );                 // [1, 2, 3]
 * $ids = htax_parse_term_ids( '1,news', 'category' );    // [1, <news term ID>]
 * $ids = htax_parse_term_ids( 'news' );                  // [] (no taxonomy → slug dropped)
 */
function htax_parse_term_ids( $meta_value, $taxonomy = '' ) {
    if ( empty( $meta_value ) || ! is_string( $meta_value ) ) {
        return array();
    }

    // Fast path: no taxonomy context, or a pure numeric list — keep IDs only.
    if ( '' === $taxonomy || ctype_digit( str_replace( array( ',', ' ' ), '', $meta_value ) ) ) {
        return array_values( array_filter( array_map( 'intval', explode( ',', $meta_value ) ), function( $id ) { return $id > 0; } ) );
    }

    // Slug-aware: numeric tokens are IDs; everything else is a slug in $taxonomy.
    $ids = array();
    foreach ( explode( ',', $meta_value ) as $token ) {
        $token = trim( $token );
        if ( '' === $token ) {
            continue;
        }
        if ( ctype_digit( $token ) ) {
            $id = (int) $token;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
            continue;
        }
        $term = get_term_by( 'slug', $token, $taxonomy );
        if ( $term instanceof WP_Term ) {
            $ids[] = (int) $term->term_id;
        }
    }

    return array_values( array_unique( $ids ) );
}

// ==================================================
// TERM VALIDATION
// ==================================================

/**
 * Safely get and validate a term
 *
 * Retrieves a term and validates it's not a WP_Error. This is a common pattern
 * throughout the plugin to safely fetch term objects.
 *
 * @since 1.0.0
 *
 * @param int $term_id Term ID.
 * @return WP_Term|false Valid term object or false if invalid/not found.
 *
 * @example
 * // Get a specific term
 * $term = htax_get_valid_term( 123 );
 * if ( $term ) {
 *     echo $term->name;
 * }
 */
function htax_get_valid_term( $term_id ) {
    if ( empty( $term_id ) || ! is_numeric( $term_id ) ) {
        return false;
    }

    // Per-request memo cache, bounded to keep memory flat on pages that touch very
    // many terms (large archives, bulk index rebuilds). 500 covers the working set
    // of a typical request; when full, the oldest entry is evicted (simple FIFO).
    // Entries are dropped when WordPress cleans a term's cache (update or delete),
    // so a term changed or deleted earlier in the request is never served stale.
    $term_cache = &htax_request_cache( 'valid_term' );

    if ( isset( $term_cache[ $term_id ] ) ) {
        return $term_cache[ $term_id ];
    }

    if ( count( $term_cache ) >= 500 ) {
        reset( $term_cache );
        unset( $term_cache[ key( $term_cache ) ] );
    }

    $term   = get_term( $term_id );
    $result = ( $term && ! is_wp_error( $term ) ) ? $term : false;

    $term_cache[ $term_id ] = $result;
    return $result;
}

add_action( 'clean_term_cache', function ( $ids ) {
    foreach ( (array) $ids as $id ) {
        htax_clear_request_cache( 'valid_term', (int) $id );
    }
} );

/**
 * Access a named per-request cache.
 *
 * Use instead of a function-level static when the cached data can change later in
 * the same request (a save handler that writes and then re-reads, a bulk action,
 * index handlers running after an update): code that invalidates the underlying
 * data — or its transient — also calls htax_clear_request_cache() for the group,
 * which a function-level static can't offer.
 *
 * @since 1.0.0
 *
 * @param string $group Cache name.
 * @return array The group's entries, by reference.
 */
function &htax_request_cache( string $group ): array {
    static $groups = array();
    if ( ! isset( $groups[ $group ] ) ) {
        $groups[ $group ] = array();
    }
    return $groups[ $group ];
}

/**
 * Clear one entry, or all entries, of a named per-request cache.
 *
 * @since 1.0.0
 *
 * @param string          $group Cache name.
 * @param int|string|null $key   Entry key, or null to clear the whole group.
 */
function htax_clear_request_cache( string $group, $key = null ): void {
    $cache = &htax_request_cache( $group );
    if ( null === $key ) {
        $cache = array();
    } else {
        unset( $cache[ $key ] );
    }
}

// ==================================================
// ADMIN NOTICES
// ==================================================

/**
 * Add an admin notice to be displayed
 *
 * Adds a WordPress admin notice with consistent formatting. Supports both immediate display
 * (current request via admin_notices hook) and queued display (next request via transient).
 * Queued notices persist across page reloads, useful after redirects.
 *
 * @since 1.0.0
 *
 * @param string $message Message to display (will be escaped with esc_html)
 * @param string $type Notice type: 'error', 'warning', 'success', 'info' (default: 'error')
 * @param bool $queue Whether to queue the notice for next page load (default: false)
 *
 * @example
 * // Display error immediately
 * htax_add_admin_notice( 'Security check failed', 'error' );
 *
 * // Queue success message for display after redirect
 * htax_add_admin_notice( 'Settings saved successfully', 'success', true );
 */
function htax_add_admin_notice( $message, $type = 'error', $queue = true ) {
    if ( empty( $message ) ) {
        return;
    }

    $valid_types = array( 'error', 'warning', 'success', 'info' );
    if ( ! in_array( $type, $valid_types, true ) ) {
        $type = 'error';
    }

    if ( $queue ) {
        // Queue notice in transient for next page load
        $notices = htax_get_transient( 'htax_admin_notices' );
        if ( ! is_array( $notices ) ) {
            $notices = array();
        }

        // Prevent duplicates
        $notice_hash = md5( $message . $type );
        if ( ! isset( $notices[ $notice_hash ] ) ) {
            $notices[ $notice_hash ] = array(
                'message' => $message,
                'type' => $type,
            );
            htax_set_transient( 'htax_admin_notices', $notices, 60 ); // 60 second expiry
        }
    } else {
        // Immediate display via admin_notices hook
        add_action( 'admin_notices', function() use ( $message, $type ) {
            ?>
            <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
            <?php
        } );
    }
}

/**
 * Display queued admin notices
 *
 * Called on admin_notices hook to display notices queued from previous requests.
 * Automatically clears the transient after displaying.
 *
 * @since 1.0.0
 */
function htax_display_queued_notices() {
    $notices = htax_get_transient( 'htax_admin_notices' );

    if ( is_array( $notices ) && ! empty( $notices ) ) {
        foreach ( $notices as $notice ) {
            ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['message'] ); ?></p>
            </div>
            <?php
        }

        // Clear the transient after displaying
        htax_delete_transient( 'htax_admin_notices' );
    }
}

// ==================================================
// SECURITY/NONCE VERIFICATION
// ==================================================

/**
 * Verify WordPress term edit/add nonces
 *
 * Handles both edit form nonces and AJAX add-tag nonces in a single check.
 * This is a general-purpose nonce helper for term operations.
 *
 * @since 1.0.0
 * @param int $term_id Term ID for edit form nonce verification.
 * @return bool True if nonce is verified, false otherwise.
 *
 * @example
 * // In term edit handler
 * if ( ! htax_verify_term_nonce( $term_id ) ) {
 *     return;
 * }
 */
function htax_verify_term_nonce( $term_id = 0 ) {
    $nonce_verified = false;

    // Check edit form nonce (used when editing existing terms)
    if ( isset( $_POST['_wpnonce'] ) ) {
        $nonce_verified = wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
            'update-tag_' . $term_id
        );
    }

    // Check AJAX add-tag nonce (used when adding new terms via AJAX)
    if ( ! $nonce_verified && isset( $_POST['_wpnonce_add-tag'] ) ) {
        $nonce_verified = wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_wpnonce_add-tag'] ) ),
            'add-tag'
        );
    }

    // Check inline-edit nonce (used by Quick Edit — WP sends _inline_edit, not _wpnonce)
    if ( ! $nonce_verified && isset( $_POST['_inline_edit'] ) ) {
        $nonce_verified = wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_inline_edit'] ) ),
            'taxinlineeditnonce'
        );
    }

    return $nonce_verified;
}

// ==================================================
// LOGGING, ERROR HANDLING & META KEY HELPERS
// ==================================================

/**
 * Log debug messages
 *
 * @since 1.0.0
 * @param string $message The debug message
 * @param array $context Additional context data
 * @param bool $backtrace Whether to include backtrace (only in verbose mode)
 */
function htax_log_debug( $message, $context = array(), $backtrace = false ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        $log_message = 'HyperTaxonomy: ' . $message;

        if ( ! empty( $context ) ) {
            $log_message .= ' | Context: ' . json_encode( $context );
        }

        // Add backtrace in verbose mode
        if ( $backtrace && defined( 'HTAX_DEBUG_VERBOSE' ) && HTAX_DEBUG_VERBOSE ) {
            $trace = htax_get_filtered_backtrace();
            if ( ! empty( $trace ) ) {
                $log_message .= ' | Backtrace: ' . $trace;
            }
        }

        error_log( $log_message );
    }

    if ( defined( 'HTAX_QUERY_MONITOR' ) && HTAX_QUERY_MONITOR ) {
        // Register both filters once with shared mutable state via references.
        // Both callbacks exist exactly once regardless of how many queries run.
        static $query_monitor_registered = false;
        if ( ! $query_monitor_registered ) {
            $query_monitor_registered = true;
            $pending_start = null;
            $pending_query = null;
            add_filter( 'query', function( $query ) use ( &$pending_start, &$pending_query ) {
                if ( strpos( $query, 'htax' ) !== false || strpos( $query, 'term' ) !== false ) {
                    $pending_start = microtime( true );
                    $pending_query = $query;
                } else {
                    $pending_start = null;
                    $pending_query = null;
                }
                return $query;
            } );
            add_filter( 'query_results', function( $results ) use ( &$pending_start, &$pending_query ) {
                if ( null !== $pending_start ) {
                    $time = microtime( true ) - $pending_start;
                    if ( $time > 0.01 ) { // Log slow queries (>10ms)
                        error_log( sprintf(
                            'HTAX Slow Query (%.3fs): %s',
                            $time,
                            substr( $pending_query, 0, 200 )
                        ) );
                    }
                    $pending_start = null;
                    $pending_query = null;
                }
                return $results;
            }, 10, 1 );
        }
    }
}

/**
 * Log errors
 *
 * @since 1.0.0
 * @param string $message The error message
 * @param array $context Additional context data
 * @param bool $backtrace Whether to include backtrace (only in verbose mode)
 */
function htax_log_error( $message, $context = array(), $backtrace = true ) {
    $log_message = 'HyperTaxonomy Error: ' . $message;

    if ( ! empty( $context ) ) {
        $log_message .= ' | Context: ' . json_encode( $context );
    }

    // Add backtrace in verbose mode (enabled by default for errors)
    if ( $backtrace && defined( 'HTAX_DEBUG_VERBOSE' ) && HTAX_DEBUG_VERBOSE ) {
        $trace = htax_get_filtered_backtrace();
        if ( ! empty( $trace ) ) {
            $log_message .= ' | Backtrace: ' . $trace;
        }
    }

    error_log( $log_message );
}

/**
 * Get filtered backtrace for debugging
 *
 * @since 1.0.0
 * @return string Formatted backtrace string
 */
function htax_get_filtered_backtrace() {
    $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );

    // Filter out logging functions themselves from trace
    $filtered = array();
    $skip_functions = array( 'htax_log_debug', 'htax_log_error', 'htax_get_filtered_backtrace' );

    foreach ( $trace as $frame ) {
        // Skip the logging functions themselves
        if ( isset( $frame['function'] ) && in_array( $frame['function'], $skip_functions, true ) ) {
            continue;
        }

        // Format frame
        $file = isset( $frame['file'] ) ? basename( $frame['file'] ) : '[internal]';
        $line = isset( $frame['line'] ) ? $frame['line'] : '?';
        $function = isset( $frame['function'] ) ? $frame['function'] : '[unknown]';

        if ( isset( $frame['class'] ) ) {
            $function = $frame['class'] . $frame['type'] . $function;
        }

        $filtered[] = sprintf( '%s:%s %s()', $file, $line, $function );

        // Limit to most relevant frames
        if ( count( $filtered ) >= HTAX_BACKTRACE_FRAME_LIMIT ) {
            break;
        }
    }

    return implode( ' <- ', $filtered );
}

/**
 * Handle database errors consistently
 *
 * Call immediately after a $wpdb query. Returns $default (and logs the error) if
 * $wpdb->last_error is set; returns false when there is no error. Callers use the
 * idiom `if ( false !== $err = htax_handle_db_error(...) ) { return $err; }` to
 * short-circuit on failure while treating `false` as the "all clear" signal.
 *
 * @since 1.0.0
 * @param string $operation Description of the operation (used in log / admin notice).
 * @param mixed  $default   Value to return when an error occurred. Pass null for
 *                          functions that return null on failure, array() for those
 *                          that return an empty array, etc.
 * @return mixed $default when a DB error was detected; false when no error.
 */
function htax_handle_db_error( $operation, $default = array() ) {
    global $wpdb;

    if ( $wpdb->last_error ) {
        htax_log_error( 'Database operation failed: ' . $operation, array(
            'error' => $wpdb->last_error,
            'last_query' => $wpdb->last_query
        ) );

        // Add admin notice if in admin context
        if ( is_admin() && current_user_can( 'manage_options' ) ) {
            htax_add_admin_notice(
                sprintf(
                    __( 'HyperTaxonomy database error during: %s. Check error logs for details.', 'hypertaxonomy' ),
                    $operation
                ),
                'error',
                false
            );
        }

        return $default;
    }

    return false; // No error
}

/**
 * Return the post meta key for a sticky term assignment.
 *
 * The key encodes only the term ID. Each assignment is stored with meta_value = 1,
 * which avoids LIKE queries on meta_value and enables an exact index lookup.
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 * @return string Meta key, e.g. '_htax_sticky_5'.
 */
function htax_get_sticky_assignment_key( int $term_id ): string {
    return '_htax_sticky_' . $term_id;
}


