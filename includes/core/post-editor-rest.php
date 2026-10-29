<?php
/**
 * Editor REST endpoints (htax/v1)
 *
 * Standalone endpoints that serve the block editor. Both require edit_posts
 * capability and are not intended for public or peer-site consumption.
 *
 * GET htax/v1/suggested-tags  — content-based tag suggestions for the post editor
 * GET htax/v1/term-meta       — badge metadata (redirect, hidden, inc/cont counts) for term pills
 *
 * @package HyperTaxonomy
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'htax_register_term_meta_route' );

// htax_register_tag_suggestions_rest_route is hooked in includes/hooks/filters.php.

// ============================================================================
// SUGGESTED TAGS
// ============================================================================

/**
 * Register the content-based tag suggestions REST endpoint.
 *
 * @since 1.0.0
 */
function htax_register_tag_suggestions_rest_route(): void {
    register_rest_route( 'htax/v1', '/suggested-tags', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'htax_rest_suggested_tags',
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'args' => array(
            'post_id'  => array( 'required' => true, 'sanitize_callback' => 'absint' ),
            'taxonomy' => array( 'default' => 'post_tag', 'sanitize_callback' => 'sanitize_key' ),
            'per_page' => array( 'default' => 10, 'sanitize_callback' => 'absint' ),
        ),
    ) );
}

/**
 * Return tags not assigned to the post, ranked by how often their name
 * appears in the post's title, excerpt, and body.
 *
 * Ignore words (comma-separated, from settings) are stripped from the post
 * text before matching. Tags with zero matches are not returned. Response
 * format mirrors /wp/v2/tags so Gutenberg can consume it directly.
 *
 * @since 1.0.0
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function htax_rest_suggested_tags( WP_REST_Request $request ) {
    global $wpdb;

    $post_id  = $request['post_id'];
    $taxonomy = $request['taxonomy'];
    $per_page = min( 50, max( 1, (int) $request['per_page'] ) );

    // Fetch stored post text; fall back to live post if not yet indexed.
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT title, body, excerpt FROM {$wpdb->prefix}htax_post_text WHERE post_id = %d",
        $post_id
    ), ARRAY_A );

    if ( ! $row ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'invalid_post', __( 'Post not found.', 'hypertaxonomy' ), array( 'status' => 404 ) );
        }
        $row = array(
            'title'   => $post->post_title,
            'body'    => htax_extract_post_body( $post->post_content ),
            'excerpt' => $post->post_excerpt,
        );
    }

    $options    = get_option( 'htax_settings', array() );
    $ignore_raw = trim( $options['tag_suggestion_ignore_words'] ?? '' );

    $apply_ignore = function( string $text ) use ( $ignore_raw ): string {
        if ( $ignore_raw === '' ) {
            return $text;
        }
        $words = array_filter( array_map( 'trim', explode( ',', strtolower( $ignore_raw ) ) ) );
        foreach ( $words as $word ) {
            if ( $word !== '' ) {
                $text = preg_replace( '/\b' . preg_quote( $word, '/' ) . '\b/u', ' ', $text );
            }
        }
        return $text;
    };

    $title_text   = $apply_ignore( strtolower( $row['title'] ) );
    $excerpt_text = $apply_ignore( strtolower( $row['excerpt'] ) );
    $body_text    = $apply_ignore( strtolower( $row['body'] ) );

    if ( trim( $title_text . $excerpt_text . $body_text ) === '' ) {
        return rest_ensure_response( array() );
    }

    $assigned = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
    if ( is_wp_error( $assigned ) ) {
        $assigned = array();
    }

    $terms = get_terms( array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'exclude'    => $assigned,
        'number'     => 0,
    ) );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return rest_ensure_response( array() );
    }

    $scored = array();
    foreach ( $terms as $term ) {
        $name          = strtolower( $term->name );
        $title_count   = substr_count( $title_text, $name );
        $excerpt_count = substr_count( $excerpt_text, $name );
        $body_count    = substr_count( $body_text, $name );

        if ( $title_count > 0 ) {
            $group = 0;
            $count = $title_count;
        } elseif ( $excerpt_count > 0 ) {
            $group = 1;
            $count = $excerpt_count;
        } elseif ( $body_count > 0 ) {
            $group = 2;
            $count = $body_count;
        } else {
            continue;
        }

        $scored[] = array( 'term' => $term, 'group' => $group, 'count' => $count );
    }

    if ( empty( $scored ) ) {
        return rest_ensure_response( array() );
    }

    usort( $scored, function( $a, $b ) {
        if ( $a['group'] !== $b['group'] ) {
            return $a['group'] - $b['group'];
        }
        return $b['count'] - $a['count'];
    } );
    $scored = array_slice( $scored, 0, $per_page );

    $controller  = new WP_REST_Terms_Controller( $taxonomy );
    $context_req = new WP_REST_Request( 'GET' );
    $context_req->set_param( 'context', 'view' );
    $data = array();
    foreach ( $scored as $item ) {
        $prepared = $controller->prepare_item_for_response( $item['term'], $context_req );
        $data[]   = $controller->prepare_response_for_collection( $prepared );
    }

    return rest_ensure_response( $data );
}

// ============================================================================
// TERM META (editor badge data)
// ============================================================================

/**
 * Register GET htax/v1/term-meta.
 *
 * @since 1.0.0
 */
function htax_register_term_meta_route(): void {
    register_rest_route( 'htax/v1', '/term-meta', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'htax_rest_term_meta',
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'args' => array(
            'taxonomy' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'taxonomy_exists',
            ),
            'ids' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ) );
}

/**
 * Return badge metadata for the requested term IDs.
 *
 * Only terms with at least one piece of HT metadata (redirect, hidden,
 * inc_count, cont_count) are included; terms with nothing to show are omitted.
 *
 * @since 1.0.0
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function htax_rest_term_meta( WP_REST_Request $request ): WP_REST_Response {
    $taxonomy = $request->get_param( 'taxonomy' );
    $ids      = array_values( array_filter( array_map( 'absint', explode( ',', $request->get_param( 'ids' ) ) ) ) );

    if ( empty( $ids ) ) {
        return rest_ensure_response( array( 'terms' => array() ) );
    }

    $tax_has_rels   = htax_taxonomy_has_feature( $taxonomy, 'term_relationships' );
    $tax_has_redir  = htax_taxonomy_has_feature( $taxonomy, 'redirects' );
    $tax_has_hidden = htax_taxonomy_has_feature( $taxonomy, 'visibility' );
    $tax_has_sticky = htax_taxonomy_has_feature( $taxonomy, 'customize_archives' );

    $redirect_set = $tax_has_redir  ? array_flip( htax_get_all_redirect_term_ids() ) : array();
    $hidden_set   = $tax_has_hidden ? array_flip( htax_get_all_hidden_term_ids()   ) : array();

    $terms = get_terms( array(
        'taxonomy'   => $taxonomy,
        'include'    => $ids,
        'hide_empty' => false,
        'number'     => 50,
    ) );

    if ( is_wp_error( $terms ) ) {
        return rest_ensure_response( array( 'terms' => array() ) );
    }

    $result = array();
    foreach ( $terms as $term ) {
        $tid        = (int) $term->term_id;
        $is_redir   = isset( $redirect_set[ $tid ] );
        $is_hidden  = isset( $hidden_set[ $tid ] );
        $included   = array();
        $containers = array();
        $inc_count  = 0;
        $cont_count = 0;

        if ( $tax_has_rels ) {
            $included = htax_get_transient( 'htax_inc_' . $tid );
            if ( false === $included ) {
                $included = htax_get_included_terms( $tid );
            }
            $containers = htax_get_transient( 'htax_cont_' . $tid );
            if ( false === $containers ) {
                $containers = htax_get_container_terms( $tid );
            }
            $inc_count  = count( $included );
            $cont_count = count( $containers );
        }

        if ( ! $inc_count && ! $cont_count && ! $is_redir && ! $is_hidden && ! $tax_has_sticky ) {
            continue;
        }

        $redir_target_name = '';
        if ( $is_redir ) {
            $redir_target = htax_get_redirect_target( $tid );
            if ( $redir_target instanceof WP_Term ) {
                $redir_target_name = $redir_target->name;
            }
        }

        $result[] = array(
            'id'                   => $tid,
            'name'                 => $term->name,
            'slug'                 => $term->slug,
            'is_redirect'          => $is_redir,
            'is_hidden'            => $is_hidden,
            'inc_count'            => $inc_count,
            'cont_count'           => $cont_count,
            'included_names'       => array_values( wp_list_pluck( $included, 'name' ) ),
            'container_names'      => array_values( wp_list_pluck( $containers, 'name' ) ),
            'redirect_target_name' => $redir_target_name,
        );
    }

    return rest_ensure_response( array( 'terms' => $result ) );
}
