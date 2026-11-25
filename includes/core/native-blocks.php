<?php
/**
 * Native Block Extensions
 *
 * Integrates HyperTaxonomy features into core WordPress blocks such as
 * the Query Loop block. These integrations work by filtering WordPress core
 * hooks rather than replacing the blocks.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Inject a Term Group filter into the Latest Posts block's WP_Query.
 *
 * Called by the pre_render_block filter. When the block's htax_filter_groups
 * attribute is set, this function registers a one-shot pre_get_posts action that
 * injects the resolved tax_query before the block's WP_Query executes. The
 * callback removes itself immediately after firing so it does not affect any
 * other query on the page.
 *
 * core/latest-posts always queries the 'post' post type.
 *
 * @since 1.0.0
 *
 * @param string|null $pre_render Null to let the block render normally.
 * @param array       $parsed_block Parsed block data including attributes.
 * @return string|null Unchanged $pre_render.
 */
function htax_latest_posts_group_filter( $pre_render, $parsed_block ) {
    if ( 'core/latest-posts' !== $parsed_block['blockName'] ) {
        return $pre_render;
    }

    $group_ids_str = $parsed_block['attrs']['htax_filter_groups'] ?? '';
    if ( ! $group_ids_str ) {
        return $pre_render;
    }

    $group_ids = array_values(
        array_filter(
            array_map( 'intval', is_array( $group_ids_str ) ? $group_ids_str : explode( ',', (string) $group_ids_str ) )
        )
    );

    if ( empty( $group_ids ) ) {
        return $pre_render;
    }

    $tax_query = htax_build_group_tax_query( $group_ids, 'post' );

    if ( count( $tax_query ) <= 1 ) {
        return $pre_render;
    }

    // One-shot pre_get_posts: fires once when core/latest-posts calls new WP_Query(),
    // injects the tax_query clause, then immediately removes itself.
    $callback = null;
    $callback = function ( $query ) use ( $tax_query, &$callback ) {
        remove_action( 'pre_get_posts', $callback );
        $existing = $query->get( 'tax_query' );
        if ( ! empty( $existing ) ) {
            $query->set( 'tax_query', array(
                'relation' => 'AND',
                $existing,
                $tax_query,
            ) );
        } else {
            $query->set( 'tax_query', $tax_query );
        }
    };

    add_action( 'pre_get_posts', $callback );

    return $pre_render;
}

/**
 * Inject a Term Group filter into the Query Loop block's WP_Query args.
 *
 * Called by the query_loop_block_query_vars filter (WordPress 6.1+). When the
 * block's htax_filter_groups attribute is set, this function resolves the group
 * IDs to term IDs across all HyperTaxonomy-enabled taxonomies associated with
 * the query's post type, then adds a tax_query clause that restricts results
 * to posts tagged with at least one of those terms.
 *
 * Multiple groups use OR logic: the union of term IDs from all selected groups
 * and their subgroups is computed, so a post needs a term from ANY group to
 * be included. Across multiple HT-enabled taxonomies, OR logic also applies —
 * a post qualifies if it has matching terms in any single taxonomy.
 *
 * @since 1.0.0
 *
 * @param array    $query_vars The WP_Query arguments being built for this block.
 * @param WP_Block $block      The block instance.
 * @param int      $page       Current pagination page.
 * @return array Modified query vars.
 */
function htax_query_loop_group_filter( $query_vars, $block, $page ) {
    // WordPress calls this for the blocks inside the Query Loop (post template, pagination),
    // which see the loop's settings only through the `query` context.
    // htax_query_loop_group_context() copies the attribute there.
    $group_ids_str = $block->context['query']['htax_filter_groups'] ?? ( $block->parsed_block['attrs']['htax_filter_groups'] ?? '' );
    if ( ! $group_ids_str ) {
        return $query_vars;
    }

    $group_ids = array_values(
        array_filter(
            array_map( 'intval', is_array( $group_ids_str ) ? $group_ids_str : explode( ',', (string) $group_ids_str ) )
        )
    );

    if ( empty( $group_ids ) ) {
        return $query_vars;
    }

    $post_type = isset( $query_vars['post_type'] ) ? $query_vars['post_type'] : 'post';
    if ( is_array( $post_type ) ) {
        // Multiple post types: use the first one for taxonomy detection.
        $post_type = reset( $post_type );
    }

    $tax_query = htax_build_group_tax_query( $group_ids, $post_type );

    if ( count( $tax_query ) <= 1 ) {
        return $query_vars;
    }

    // Merge with any existing tax_query (e.g. from a native category filter).
    if ( ! empty( $query_vars['tax_query'] ) ) {
        $query_vars['tax_query'] = array(
            'relation' => 'AND',
            $query_vars['tax_query'],
            $tax_query,
        );
    } else {
        $query_vars['tax_query'] = $tax_query;
    }

    return $query_vars;
}

/**
 * Pass a Query Loop's Term Group filter to its inner blocks.
 *
 * The htax_filter_groups attribute belongs to the core/query block, but the query is
 * built by the blocks inside it, which only see the loop's `query` context. Copying the
 * groups into the `query` attribute while the block renders makes them part of that
 * context; nothing is saved.
 *
 * @since 1.0.0
 *
 * @param array $parsed_block The block being rendered.
 * @return array The block, with the groups in its query when set.
 */
function htax_query_loop_group_context( $parsed_block ) {
    if ( 'core/query' !== ( $parsed_block['blockName'] ?? '' ) || empty( $parsed_block['attrs']['htax_filter_groups'] ) ) {
        return $parsed_block;
    }
    if ( ! isset( $parsed_block['attrs']['query'] ) || ! is_array( $parsed_block['attrs']['query'] ) ) {
        $parsed_block['attrs']['query'] = array();
    }
    $parsed_block['attrs']['query']['htax_filter_groups'] = $parsed_block['attrs']['htax_filter_groups'];
    return $parsed_block;
}

/**
 * Apply a Term Group filter to REST post collection requests from the block editor.
 *
 * The Query Loop block's editor preview fetches its posts through the REST API and
 * passes extra query keys along, including the htax_filter_groups that
 * HyperTaxonomy adds to its context. Honoring it here filters the preview the same
 * way as the published page. Limited to users who can edit posts, since only the
 * editor sends it.
 *
 * @since 1.0.0
 *
 * @param array           $args    WP_Query arguments for the request.
 * @param WP_REST_Request $request The REST request.
 * @return array Modified arguments.
 */
function htax_rest_posts_group_filter( $args, $request ) {
    $groups = $request->get_param( 'htax_filter_groups' );
    if ( empty( $groups ) || ! current_user_can( 'edit_posts' ) ) {
        return $args;
    }

    $group_ids = array_values( array_filter( array_map( 'intval', is_array( $groups ) ? $groups : explode( ',', (string) $groups ) ) ) );
    if ( empty( $group_ids ) ) {
        return $args;
    }

    $post_type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
    if ( is_array( $post_type ) ) {
        $post_type = reset( $post_type );
    }

    $tax_query = htax_build_group_tax_query( $group_ids, $post_type );
    if ( count( $tax_query ) <= 1 ) {
        return $args;
    }

    $args['tax_query'] = empty( $args['tax_query'] )
        ? $tax_query
        : array(
            'relation' => 'AND',
            $args['tax_query'],
            $tax_query,
        );

    return $args;
}

/**
 * Register htax_rest_posts_group_filter() for every post type in the REST API.
 *
 * @since 1.0.0
 */
function htax_register_rest_posts_group_filter() {
    foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $post_type ) {
        add_filter( "rest_{$post_type}_query", 'htax_rest_posts_group_filter', 10, 2 );
    }
}
