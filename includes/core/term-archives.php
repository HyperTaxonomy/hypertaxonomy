<?php
/**
 * Archive and Frontend Display Logic
 *
 * Handles modification of taxonomy archive pages on the frontend:
 * - Archive query expansion (including related terms)
 * - Archive title and description display
 * - Sticky posts on archive pages
 * - Redirect handling for moved terms
 *
 * Note: Admin table filtering logic is in includes/admin/term-filters.php
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Return the taxonomy of the current archive if it is enabled for HyperTaxonomy.
 *
 * Centralises the repeated pattern: get_queried_object() â†’ validate taxonomy â†’
 * htax_is_taxonomy_enabled(). Returns false when any check fails so callers can
 * do a simple `if ( false === ( $taxonomy = htax_get_enabled_archive_taxonomy() ) ) return;`.
 *
 * @since 1.0.0
 * @return string|false Taxonomy slug, or false if not a valid/enabled archive.
 */
function htax_get_enabled_archive_taxonomy() {
    $queried_object = get_queried_object();
    if ( ! $queried_object || ! isset( $queried_object->taxonomy ) ) {
        return false;
    }

    $taxonomy = $queried_object->taxonomy;
    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return false;
    }

    return $taxonomy;
}

/**
 * Build the expanded tax_query for a term, adding HT-contributed terms.
 *
 * Resolves all HT-contributed term IDs for $term_id via htax_get_included_term_ids(),
 * which handles direct inclusions, group-based inclusions, container-group inclusions, and
 * exclusions â€” each gated by the relevant taxonomy feature flags:
 *
 *   - All inclusions, exclusions, and group-based relationships: gated by the
 *     'term_relationships' feature flag. Callers are expected to check this flag before
 *     calling (htax_modify_archive_query and htax_expand_secondary_query both do so).
 *   - WP descendants of each included term are added for hierarchical taxonomies.
 *   - Hidden terms are removed for users who cannot see them.
 *
 * Returns a tax_query structured as an OR of the original term clause (with native
 * include_children for WP's own hierarchy walk) and the HT-contributed terms clause
 * (already fully expanded, include_children = false).
 *
 * Returns null when there are no HT contributions after all filtering, so callers
 * can skip the tax_query rewrite entirely.
 *
 * @since 1.0.0
 * @param int          $term_id  Source term ID.
 * @param string       $taxonomy Taxonomy slug.
 * @param WP_Term|null $term_obj Optional pre-fetched WP_Term object to avoid a redundant get_term() call.
 * @return array|null Expanded tax_query array, or null if no HT contributions.
 */
function htax_build_expanded_tax_query( int $term_id, string $taxonomy, ?WP_Term $term_obj = null ): ?array {
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
        return null;
    }

    if ( null === $term_obj ) {
        $term_obj = htax_get_valid_term( $term_id );
    }
    if ( ! $term_obj ) {
        return null;
    }

    $all_included_ids = htax_get_included_term_ids( $term_obj );

    if ( empty( $all_included_ids ) ) {
        return null;
    }

    if ( ! htax_can_see_hidden_terms( $taxonomy ) ) {
        $hidden_set       = array_flip( htax_get_all_hidden_term_ids() );
        $all_included_ids = array_values( array_filter( $all_included_ids, function ( $included_term_id ) use ( $hidden_set ) {
            return ! isset( $hidden_set[ $included_term_id ] );
        } ) );
    }

    if ( empty( $all_included_ids ) ) {
        return null;
    }

    return array(
        'relation' => 'OR',
        array(
            'taxonomy'         => $taxonomy,
            'field'            => 'term_id',
            'terms'            => $term_id,
            'include_children' => is_taxonomy_hierarchical( $taxonomy ),
        ),
        array(
            'taxonomy'         => $taxonomy,
            'field'            => 'term_id',
            'terms'            => $all_included_ids,
            'include_children' => false,
        ),
    );
}

/**
 * Modify archive query to include related terms
 *
 * @param WP_Query $query The WordPress query object
 */
function htax_modify_archive_query( $query ) {
    // Only modify main query on frontend archive pages
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    // Check if this is a taxonomy archive (tags, categories, or custom taxonomies)
    if ( ! ( $query->is_tag() || $query->is_category() || $query->is_tax() ) ) {
        return;
    }

    $taxonomy = htax_get_enabled_archive_taxonomy();
    if ( false === $taxonomy ) {
        return;
    }

    // Check if taxonomy has inclusion feature enabled
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
        return;
    }

    $term_id        = get_queried_object_id();
    $queried_object = get_queried_object();
    if ( ! $term_id ) {
        return;
    }

    // Check if this is a redirect term (shouldn't happen as redirect handles earlier)
    $redirect_term = htax_get_redirect_target( $term_id );
    if ( $redirect_term ) {
        return; // Will be handled by redirect function
    }

    $expanded_tax_query = htax_build_expanded_tax_query( $term_id, $taxonomy, $queried_object );

    if ( null !== $expanded_tax_query ) {
        // Derive $all_included_ids from the expanded query for debug output below.
        $all_included_ids = isset( $expanded_tax_query[1]['terms'] ) ? $expanded_tax_query[1]['terms'] : array();

        $query->set( 'tax_query', $expanded_tax_query );

        // Clear per-taxonomy query vars that WP would use to build its own tax_query
        // clause on top of our expanded one. Do NOT clear 'taxonomy' — it is used by
        // breadcrumb plugins, SEO plugins, and is_tax() to identify the archive context.
        $query->set( 'tag_id', '' );
        $query->set( 'cat', '' );
        $query->set( 'category_name', '' );
        $query->set( 'tag', '' );
        $query->set( 'term', '' );
    } else {
        $all_included_ids = array();
    }

    // Apply sort order: user GET param overrides term meta, term meta overrides default.
    // Stored/transmitted as "field:dir,field:dir"; parsed into a WP_Query array orderby.
    $archive_fields     = array( 'date', 'modified', 'title', 'author', 'comment_count' );
    $user_orderby_raw   = isset( $_GET['htax_orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['htax_orderby'] ) ) : '';
    $user_orderby       = '';
    if ( $user_orderby_raw ) {
        $parsed_user = htax_parse_orderby( $user_orderby_raw );
        $valid_pairs = array();
        foreach ( $parsed_user as $field => $dir ) {
            if ( in_array( $field, $archive_fields, true ) ) {
                $valid_pairs[ $field ] = $dir;
            }
        }
        $user_orderby = $valid_pairs;
    }

    if ( ! empty( $user_orderby ) ) {
        $query->set( 'orderby', $user_orderby );
    } else {
        if ( htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
            $sort_order_str = htax_get_term_sort_order( $term_id );
            if ( ! empty( $sort_order_str ) ) {
                $query->set( 'orderby', $sort_order_str );
            }
        }
        if ( ! $query->get( 'orderby' ) ) {
            $query->set( 'orderby', 'date' );
            $query->set( 'order', 'DESC' );
        }
    }

    // 5. Apply posts per page override if set
    if ( htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        $posts_per_page = get_term_meta( $term_id, '_htax_posts_per_page', true );
        if ( $posts_per_page && intval( $posts_per_page ) > 0 ) {
            $query->set( 'posts_per_page', intval( $posts_per_page ) );
        }
    }

    // 6. Debug mode
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_GET['htax_debug'] ) && current_user_can( 'manage_options' ) ) {
        add_action( 'wp_footer', function() use ( $term_id, $all_included_ids, $taxonomy ) {
            echo '<div style="background:#fff;border:2px solid #333;padding:20px;margin:20px;">';
            echo '<h3>HyperTaxonomy Debug Info</h3>';
            echo '<p><strong>Term ID:</strong> ' . esc_html( $term_id ) . '</p>';
            echo '<p><strong>Taxonomy:</strong> ' . esc_html( $taxonomy ) . '</p>';
            echo '<p><strong>Included Terms:</strong> ' . esc_html( implode( ', ', $all_included_ids ) ) . '</p>';

            // Show term names for included IDs
            if ( ! empty( $all_included_ids ) ) {
                echo '<p><strong>Included Term Names:</strong><br>';
                // Batch fetch all included terms to avoid N+1 problem
                $included_terms = get_terms( array(
                    'taxonomy' => $taxonomy,
                    'include'  => $all_included_ids,
                    'hide_empty' => false,
                ) );

                if ( ! is_wp_error( $included_terms ) ) {
                    foreach ( $included_terms as $inc_term ) {
                        echo esc_html( $inc_term->name ) . ' (ID: ' . $inc_term->term_id . ')<br>';
                    }
                }
                echo '</p>';
            }

            global $wp_query;
            echo '<p><strong>Found Posts:</strong> ' . $wp_query->found_posts . '</p>';
            echo '<details><summary>SQL Query</summary><pre>' . esc_html( $wp_query->request ) . '</pre></details>';
            echo '</div>';
        });
    }
}

/**
 * Handle term redirects
 */
function htax_handle_redirects() {
    if ( is_admin() ) {
        return;
    }

    if ( ! ( is_tag() || is_category() || is_tax() ) ) {
        return;
    }

    $taxonomy = htax_get_enabled_archive_taxonomy();
    if ( false === $taxonomy ) {
        return;
    }

    $term_id = get_queried_object_id();

    // Check if this term is hidden and user doesn't have permission to view it
    $is_hidden = get_term_meta( $term_id, '_htax_is_hidden', true );
    if ( '1' === $is_hidden && ! htax_can_see_hidden_terms( $taxonomy ) ) {
        // Return 404 for hidden terms that the current user cannot access
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
        return;
    }

    // Check if redirects feature is enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        return;
    }
    $redirect_term = htax_get_redirect_target( $term_id );

    if ( $redirect_term ) {
        $redirect_url = get_term_link( $redirect_term );

        if ( ! is_wp_error( $redirect_url ) ) {
            htax_log_debug( 'Performing redirect', array(
                'from_term_id' => $term_id,
                'to_term_id' => $redirect_term->term_id,
                'url' => $redirect_url
            ) );

            wp_safe_redirect( $redirect_url, 301 );
            exit;
        }
    }
}

/**
 * Get contributing terms for the current page
 *
 * @return array Array of term names
 */
function htax_get_contributing_terms_for_current_page() {
    global $wp_query;

    if ( ! ( is_tag() || is_category() || is_tax() ) || empty( $wp_query->posts ) ) {
        return array();
    }

    $taxonomy = htax_get_enabled_archive_taxonomy();
    if ( false === $taxonomy ) {
        return array();
    }

    if ( ! htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
        return array();
    }

    $queried_object  = get_queried_object();
    $current_term_id = get_queried_object_id();
    $page            = get_query_var( 'paged' ) ?: 1;
    $cache_key       = htax_generate_transient_key( 'htax_contrib_', $current_term_id . '_p' . $page . ( htax_can_see_hidden_terms( $taxonomy ) ? 'H' : '' ) );

    $cached = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }
    // Pass WP_Term object instead of ID to avoid redundant get_term() call
    $included_ids = array_keys( htax_get_included_terms( $queried_object ) );

    if ( empty( $included_ids ) ) {
        htax_set_transient( $cache_key, array(), htax_get_cache_duration( 'search' ) );
        return array();
    }

    // Batch load all terms for all posts at once
    $post_ids = wp_list_pluck( $wp_query->posts, 'ID' );
    $all_terms = wp_get_object_terms( $post_ids, $taxonomy, array( 'fields' => 'all_with_object_id' ) );

    // Group terms by post ID
    $terms_by_post = array();
    foreach ( $all_terms as $term_rel ) {
        if ( ! isset( $terms_by_post[ $term_rel->object_id ] ) ) {
            $terms_by_post[ $term_rel->object_id ] = array();
        }
        $terms_by_post[ $term_rel->object_id ][] = $term_rel->term_id;
    }

    $contributing_terms = array();

    // Check if user can see hidden terms for this taxonomy
    $can_see_hidden = htax_can_see_hidden_terms( $taxonomy );

    // First pass: collect all unique contributing term IDs
    $unique_contributing_ids = array();
    foreach ( $post_ids as $post_id ) {
        $post_terms = isset( $terms_by_post[ $post_id ] ) ? $terms_by_post[ $post_id ] : array();
        $post_has_main_term = in_array( $current_term_id, $post_terms, true );

        if ( ! $post_has_main_term ) {
            $post_contributing = array_intersect( $post_terms, $included_ids );
            foreach ( $post_contributing as $term_id ) {
                $unique_contributing_ids[ $term_id ] = true;
            }
        }
    }

    // Batch fetch all contributing terms to avoid N+1 problem
    if ( ! empty( $unique_contributing_ids ) ) {
        $contributing_term_objects = get_terms( array(
            'taxonomy' => $taxonomy,
            'include'  => array_keys( $unique_contributing_ids ),
            'hide_empty' => false,
        ) );

        if ( ! is_wp_error( $contributing_term_objects ) ) {
            // Build a lookup set once rather than per-term get_term_meta() calls.
            $hidden_set = $can_see_hidden ? array() : array_flip( htax_get_all_hidden_term_ids() );

            foreach ( $contributing_term_objects as $term ) {
                if ( ! $can_see_hidden && isset( $hidden_set[ $term->term_id ] ) ) {
                    continue;
                }

                $contributing_terms[ $term->term_id ] = $term->name;
            }
        }
    }

    htax_set_transient( $cache_key, array_values( $contributing_terms ), htax_get_cache_duration( 'search' ) );

    return array_values( $contributing_terms );
}

/**
 * Extract a single sticky-eligible term from a REST tax_query.
 *
 * Returns ['term_id' => int, 'taxonomy' => string] when the tax_query has
 * exactly one IN/AND inclusion entry with a single term ID in a taxonomy that
 * has customize_archives enabled. Returns null for any other shape (multiple
 * terms, exclusion-only query, non-sticky taxonomy, etc.).
 *
 * @param WP_Query $query
 * @return array|null
 */
function htax_get_sticky_term_from_tax_query( WP_Query $query ) {
    $tax_query = $query->get( 'tax_query' );
    if ( empty( $tax_query ) || ! is_array( $tax_query ) ) {
        return null;
    }

    $include_entries = array();
    foreach ( $tax_query as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue; // skip the 'relation' string key
        }
        $operator = isset( $entry['operator'] ) ? strtoupper( $entry['operator'] ) : 'IN';
        if ( in_array( $operator, array( 'NOT IN', 'NOT EXISTS', 'EXISTS' ), true ) ) {
            continue; // exclusion entries are not the subject term
        }
        $include_entries[] = $entry;
    }

    if ( 1 !== count( $include_entries ) ) {
        return null;
    }

    $entry    = $include_entries[0];
    $taxonomy = isset( $entry['taxonomy'] ) ? $entry['taxonomy'] : '';
    $terms    = isset( $entry['terms'] )
        ? array_values( array_filter( array_map( 'intval', (array) $entry['terms'] ) ) )
        : array();

    if ( ! $taxonomy || 1 !== count( $terms ) || $terms[0] <= 0 ) {
        return null;
    }

    if ( ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        return null;
    }

    return array( 'term_id' => $terms[0], 'taxonomy' => $taxonomy );
}

/**
 * Modify archive query for sticky posts.
 *
 * Handles both regular term archives (main query) and REST API requests
 * filtered by a single HT-enabled term.
 *
 * @param WP_Query $query The WordPress query object
 */
function htax_modify_archive_query_for_stickies( $query ) {
    if ( is_admin() ) {
        return;
    }

    $options = get_option( 'htax_settings', array() );
    if ( empty( $options['show_sticky_posts'] ) ) {
        return;
    }

    $is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;

    if ( $is_rest ) {
        $term_context = htax_get_sticky_term_from_tax_query( $query );
        if ( ! $term_context ) {
            return;
        }
        $term_id  = $term_context['term_id'];
        $taxonomy = $term_context['taxonomy'];
    } else {
        if ( ! $query->is_main_query() ) {
            return;
        }
        if ( ! ( $query->is_tag() || $query->is_category() || $query->is_tax() ) ) {
            return;
        }
        $queried  = get_queried_object();
        $taxonomy = isset( $queried->taxonomy ) ? $queried->taxonomy : '';
        $term_id  = isset( $queried->term_id ) ? $queried->term_id : 0;
        if ( ! $taxonomy || ! $term_id || ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
            return;
        }
    }

    $sticky_posts = htax_get_sticky_posts_for_term( $term_id );

    if ( empty( $sticky_posts ) ) {
        return;
    }

    $posts_per_page = (int) $query->get( 'posts_per_page' );
    if ( 0 === $posts_per_page ) {
        $posts_per_page = (int) get_option( 'posts_per_page', 10 );
    }
    if ( -1 === $posts_per_page ) {
        return;
    }

    // The pinned set is computed identically on every page, so page 2+ can account
    // for exactly the posts page 1 pinned. Stickies beyond the cap are not pinned and
    // stay in their natural position.
    $term_max_sticky   = get_term_meta( $term_id, '_htax_max_sticky_posts', true );
    $global_max_sticky = isset( $options['max_sticky_posts'] ) ? intval( $options['max_sticky_posts'] ) : 10;
    $max_sticky_posts  = $term_max_sticky ? intval( $term_max_sticky ) : $global_max_sticky;
    $max_sticky_posts  = min( $max_sticky_posts, $posts_per_page );
    $sticky_posts      = array_slice( $sticky_posts, 0, $max_sticky_posts );
    $sticky_ids        = wp_list_pluck( $sticky_posts, 'ID' );
    $sticky_count      = count( $sticky_posts );
    $existing_excl     = array_filter( array_map( 'intval', (array) $query->get( 'post__not_in' ) ) );

    // Pinned posts are removed from the regular result set on every page, and counted
    // back into found_posts (htax_sticky_found_posts) and max_num_pages (htax_prepend_sticky_posts).
    $query->set( 'post__not_in', array_values( array_unique( array_merge( $existing_excl, $sticky_ids ) ) ) );
    $query->set( 'htax_sticky_count', $sticky_count );
    $query->set( 'htax_original_posts_per_page', $posts_per_page );

    $paged = max( 1, (int) $query->get( 'paged' ) );

    // Pages 2+: page 1 showed only (posts_per_page - sticky_count) regular posts, so
    // shift the window back by sticky_count to avoid skipping those posts.
    if ( $paged > 1 ) {
        $query->set( 'offset', ( $paged - 1 ) * $posts_per_page - $sticky_count );
        return;
    }

    // Page 1: pin stickies at the top.
    $query->set( 'htax_sticky_posts', $sticky_posts );

    $adjusted_posts_per_page = $posts_per_page - $sticky_count;

    // Stickies fill the entire page. Still run the regular query (one row, discarded in
    // htax_prepend_sticky_posts) so found_posts reflects the regular posts on later pages.
    if ( $adjusted_posts_per_page < 1 ) {
        $query->set( 'htax_limit_sticky_only', true );
        $adjusted_posts_per_page = 1;
    }

    $query->set( 'posts_per_page', $adjusted_posts_per_page );
}

/**
 * Count pinned sticky posts back into found_posts.
 *
 * Pinned posts are excluded from the regular archive query on every page (see
 * htax_modify_archive_query_for_stickies), so the raw count is short by the
 * number pinned on page 1.
 *
 * @since 1.0.0
 * @param int      $found_posts Number of posts found by the regular query.
 * @param WP_Query $query       The query.
 * @return int
 */
function htax_sticky_found_posts( $found_posts, $query ) {
    $sticky_count = (int) $query->get( 'htax_sticky_count' );
    return $sticky_count > 0 ? $found_posts + $sticky_count : $found_posts;
}

/**
 * Get sticky posts for a term.
 *
 * Returns an empty array when the term is a redirect unless $include_redirect_data is true.
 * The taxonomy is derived from the term.
 *
 * @param int  $term_id              The term ID.
 * @param bool $include_redirect_data When true, return data even if the term is a redirect.
 * @return WP_Post[] Sticky posts, newest first.
 */
function htax_get_sticky_posts_for_term( $term_id, bool $include_redirect_data = false ) {
    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        return array();
    }
    $term_id  = (int) $term->term_id;
    $taxonomy = $term->taxonomy;

    if ( ! $include_redirect_data && htax_is_redirect( $term_id )
         && htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        return array();
    }

    $indexed_post_types = htax_get_indexed_post_types();
    if ( empty( $indexed_post_types ) ) {
        return array();
    }

    // The cache holds only the validated post IDs (an empty list is cached too); post
    // objects are loaded fresh below, so edits and status changes are always current.
    // htax_invalidate_sticky_cache_*() clears it whenever sticky meta, a sticky post's
    // status, or a sticky post's terms change.
    $cache_key     = 'htax_sticky_' . (int) $term_id;
    $validated_ids = htax_get_transient( $cache_key );

    if ( false === $validated_ids ) {
        global $wpdb;

        // Posts marked sticky for this term via its per-term key (exact meta_key match,
        // uses the postmeta meta_key index).
        $post_type_placeholders = implode( ',', array_fill( 0, count( $indexed_post_types ), '%s' ) );
        $sticky_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
             AND pm.meta_value = '1'
             AND p.post_status = 'publish'
             AND p.post_type IN ($post_type_placeholders)
             ORDER BY pm.post_id DESC",
            htax_get_sticky_assignment_key( $term_id ),
            ...$indexed_post_types
        ) );

        if ( htax_handle_db_error( 'get_sticky_posts_for_term', array() ) !== false ) {
            return array();
        }

        // Keep only posts that still carry the term (batch-fetched to avoid N+1).
        $validated_ids = array();
        if ( ! empty( $sticky_ids ) ) {
            $all_terms = wp_get_object_terms( $sticky_ids, $taxonomy, array( 'fields' => 'all_with_object_id' ) );
            if ( ! is_wp_error( $all_terms ) ) {
                foreach ( $all_terms as $term_obj ) {
                    if ( (int) $term_obj->term_id === (int) $term_id ) {
                        $validated_ids[] = (int) $term_obj->object_id;
                    }
                }
            }
            $validated_ids = array_values( array_unique( $validated_ids ) );
        }

        htax_set_transient( $cache_key, $validated_ids, htax_get_cache_duration( 'sticky' ) );
    }

    if ( empty( $validated_ids ) ) {
        return array();
    }

    // Cap at 100 — a sanity limit; more sticky posts per term is a content modeling problem.
    $sticky_posts = get_posts( array(
        'post__in'       => $validated_ids,
        'post_type'      => $indexed_post_types,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 100,
        'post_status'    => 'publish',
    ) );

    foreach ( $sticky_posts as $sticky_post ) {
        $sticky_post->is_htax_sticky       = true;
        $sticky_post->htax_sticky_term_id  = $term_id;
        $sticky_post->htax_sticky_taxonomy = $taxonomy;
    }

    return $sticky_posts;
}

/**
 * Clear the sticky cache of every term a post is pinned to.
 *
 * Hooked to post status transitions and to term assignment changes, which change
 * whether a pinned post qualifies (published, still carries the term).
 *
 * @since 1.0.0
 * @param int $post_id Post ID.
 */
function htax_invalidate_sticky_cache_for_post( $post_id ) {
    $prefix = '_htax_sticky_';
    foreach ( array_keys( (array) get_post_meta( (int) $post_id ) ) as $meta_key ) {
        if ( 0 === strpos( $meta_key, $prefix ) ) {
            $term_id = (int) substr( $meta_key, strlen( $prefix ) );
            if ( $term_id > 0 ) {
                htax_delete_transient( 'htax_sticky_' . $term_id );
            }
        }
    }
}

/**
 * Clear a term's sticky cache whenever one of its sticky meta keys is written.
 *
 * Covers every writer (post editor, REST, imports, WP-CLI, other plugins) in one place.
 * Hooked to added_post_meta / updated_post_meta / deleted_post_meta.
 *
 * @since 1.0.0
 * @param int|int[] $meta_id   Meta ID(s) (unused).
 * @param int       $object_id Post ID (unused).
 * @param string    $meta_key  Meta key.
 */
function htax_invalidate_sticky_cache_on_meta( $meta_id, $object_id, $meta_key ) {
    $prefix = '_htax_sticky_';
    if ( 0 === strpos( (string) $meta_key, $prefix ) ) {
        $term_id = (int) substr( $meta_key, strlen( $prefix ) );
        if ( $term_id > 0 ) {
            htax_delete_transient( 'htax_sticky_' . $term_id );
        }
    }
}
add_action( 'added_post_meta', 'htax_invalidate_sticky_cache_on_meta', 10, 3 );
add_action( 'updated_post_meta', 'htax_invalidate_sticky_cache_on_meta', 10, 3 );
add_action( 'deleted_post_meta', 'htax_invalidate_sticky_cache_on_meta', 10, 3 );
add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) {
    if ( $new_status !== $old_status && $post instanceof WP_Post ) {
        htax_invalidate_sticky_cache_for_post( $post->ID );
    }
}, 10, 3 );
add_action( 'set_object_terms', function ( $object_id ) {
    htax_invalidate_sticky_cache_for_post( $object_id );
} );
add_action( 'deleted_term_relationships', function ( $object_id ) {
    htax_invalidate_sticky_cache_for_post( $object_id );
} );

/**
 * Prepend sticky posts to query results.
 *
 * Handles both regular term archives (main query) and REST API requests.
 * Query vars are stored on the query object itself (via $query->set/get) so
 * this works correctly for REST queries that are not the global $wp_query.
 *
 * @param array    $posts Array of post objects
 * @param WP_Query $query The WordPress query object
 * @return array Modified array of post objects
 */
function htax_prepend_sticky_posts( $posts, $query ) {
    if ( is_admin() ) {
        return $posts;
    }

    $is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;

    if ( ! $is_rest ) {
        if ( ! $query->is_main_query() ) {
            return $posts;
        }
        if ( ! ( $query->is_tag() || $query->is_category() || $query->is_tax() ) ) {
            return $posts;
        }
    }

    // WP derives max_num_pages from the (reduced) page-1 posts_per_page; recompute it
    // from the full page size so every page reports the same page count.
    $original_posts_per_page = (int) $query->get( 'htax_original_posts_per_page' );
    if ( $query->get( 'htax_sticky_count' ) && $original_posts_per_page > 0 ) {
        $query->max_num_pages = (int) ceil( $query->found_posts / $original_posts_per_page );
    }

    // Only on first page.
    if ( $query->get( 'paged' ) > 1 ) {
        return $posts;
    }

    $sticky_posts = $query->get( 'htax_sticky_posts' );
    if ( empty( $sticky_posts ) ) {
        return $posts;
    }

    if ( $query->get( 'htax_limit_sticky_only' ) ) {
        return $sticky_posts;
    }

    $merged_posts = array_merge( $sticky_posts, $posts );

    if ( $original_posts_per_page && count( $merged_posts ) > $original_posts_per_page ) {
        $merged_posts = array_slice( $merged_posts, 0, $original_posts_per_page );
    }

    return $merged_posts;
}

// ============================================================================
// TERM GROUPS ARCHIVE HANDLER
// ============================================================================

/**
 * Handle Term Groups archive pages
 *
 * When viewing a group archive (e.g., /group/colors/), display posts from all
 * terms assigned to that group across all enabled taxonomies.
 *
 * @since 1.0
 *
 * @param WP_Query $query The WordPress query object.
 */
function htax_handle_group_archives( $query ) {
    // Only modify main query on frontend
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    // Check if this is a group taxonomy archive
    if ( ! $query->is_tax( 'group' ) ) {
        return;
    }

    $group_id = get_queried_object_id();
    if ( ! $group_id ) {
        return;
    }

    $tax_query = htax_build_group_tax_query( array( $group_id ) );

    if ( count( $tax_query ) > 1 ) {
        $query->set( 'tax_query', $tax_query );
    } else {
        // No terms in this group â€” show no posts.
        $query->set( 'post__in', array( 0 ) );
    }
}
add_action( 'pre_get_posts', 'htax_handle_group_archives' );

/**
 * Clean up sticky post metadata when a post is deleted
 *
 * This function is hooked to 'delete_post' and can be triggered from admin,
 * WP-Cron, or frontend contexts, so it must be loaded globally.
 *
 * @since 1.0.0
 * @param int $post_id Post ID being deleted
 */
function htax_cleanup_sticky_on_delete( $post_id ) {
    $cleaned    = 0;
    $prefix     = '_htax_sticky_';
    $prefix_len = strlen( $prefix );
    $all_meta   = get_post_meta( $post_id );

    foreach ( $all_meta as $meta_key => $values ) {
        if ( 0 !== strpos( $meta_key, $prefix ) ) {
            continue;
        }
        $term_id = (int) substr( $meta_key, $prefix_len );
        if ( $term_id <= 0 ) {
            continue;
        }
        delete_post_meta( $post_id, $meta_key );
        htax_delete_transient( 'htax_sticky_' . $term_id );
        ++$cleaned;
    }

    if ( $cleaned > 0 ) {
        htax_log_debug( 'Cleaned up sticky post assignments on delete', array(
            'post_id'             => $post_id,
            'assignments_removed' => $cleaned,
        ) );
    }
}

/**
 * Remove sticky post meta whose term no longer exists.
 *
 * Called on activation to catch orphaned meta left by term deletions that bypassed the
 * delete_term hook (WP-CLI, direct DB operations, etc.).
 *
 * Safe to call multiple times â€” idempotent.
 *
 * @since 1.0.0
 * @return int Number of postmeta rows deleted.
 */
function htax_repair_orphaned_sticky_meta(): int {
    global $wpdb;
    $deleted    = 0;
    $prefix     = '_htax_sticky_';
    $prefix_len = strlen( $prefix );

    $keys = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( $prefix ) . '%'
    ) );

    foreach ( $keys as $meta_key ) {
        $term_id = (int) substr( $meta_key, $prefix_len );
        if ( $term_id <= 0 ) {
            continue;
        }
        $term = get_term( $term_id );
        if ( $term && ! is_wp_error( $term ) ) {
            continue;
        }
        $rows     = $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
        $deleted += false === $rows ? 0 : (int) $rows;
        htax_delete_transient( 'htax_sticky_' . $term_id );
    }

    return $deleted;
}

// htax_rest_update_sticky_terms) is defined in includes/admin/post-edit.php, which is
