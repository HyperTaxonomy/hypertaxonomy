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
            array_map( 'intval', explode( ',', $group_ids_str ) )
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
    $group_ids_str = $block->parsed_block['attrs']['htax_filter_groups'] ?? '';
    if ( ! $group_ids_str ) {
        return $query_vars;
    }

    $group_ids = array_values(
        array_filter(
            array_map( 'intval', explode( ',', $group_ids_str ) )
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
