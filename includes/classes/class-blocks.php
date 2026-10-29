<?php
/**
 * Gutenberg Block Registration
 *
 * Registers all HyperTaxonomy blocks. Each block is a dynamic (server-side
 * rendered) block whose render callback delegates to the corresponding
 * shortcode handler, so there is no duplication of query or render logic.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_Blocks {

    /**
     * Register the HyperTaxonomy block category so all HT blocks appear grouped
     * in the block inserter panel when users browse by category.
     *
     * @param array $categories Existing block categories.
     * @return array Categories with HyperTaxonomy appended after native groups.
     */
    public static function register_category( $categories ) {
        return array_merge(
            $categories,
            array(
                array(
                    'slug'  => 'hypertaxonomy',
                    'title' => 'HyperTaxonomy',
                    'icon'  => null,
                ),
            )
        );
    }

    /**
     * Register all blocks.
     * Hooked to init so block.json metadata and script handles resolve correctly.
     */
    public static function register() {
        register_block_type(
            HTAX_PLUGIN_DIR . 'blocks/ht-post-list',
            array(
                'render_callback' => array( __CLASS__, 'render_post_list' ),
            )
        );

        register_block_type(
            HTAX_PLUGIN_DIR . 'blocks/ht-related-posts',
            array(
                'render_callback' => array( __CLASS__, 'render_related_posts' ),
            )
        );

        register_block_type(
            HTAX_PLUGIN_DIR . 'blocks/ht-term-list',
            array(
                'render_callback' => array( __CLASS__, 'render_term_list' ),
            )
        );

        register_block_type(
            HTAX_PLUGIN_DIR . 'blocks/ht-term-cloud',
            array(
                'render_callback' => array( __CLASS__, 'render_term_cloud' ),
            )
        );

        register_block_type(
            HTAX_PLUGIN_DIR . 'blocks/ht-term-index',
            array(
                'render_callback' => array( __CLASS__, 'render_term_index' ),
            )
        );

        register_block_type(
            HTAX_PLUGIN_DIR . 'blocks/ht-term-groups',
            array(
                'render_callback' => array( __CLASS__, 'render_term_groups' ),
            )
        );

        register_block_type(
            HTAX_PLUGIN_DIR . 'blocks/ht-sort-order-options',
            array(
                'render_callback' => array( __CLASS__, 'render_sort_order_options' ),
            )
        );

        register_block_type(
            HTAX_PLUGIN_DIR . 'blocks/ht-power-search',
            array(
                'render_callback' => array( __CLASS__, 'render_power_search' ),
            )
        );

        // Not a standalone block — extends core/query via block editor filters.
        // Registered solely to auto-enqueue the editorScript declared in block.json.
        register_block_type( HTAX_PLUGIN_DIR . 'blocks/ht-query-group-filter' );

        // Point each block's editor-script translations at the plugin's bundled
        // languages/ directory. Because block.json declares a textdomain, core
        // already calls wp_set_script_translations() without a path — which only
        // resolves wp-content/languages/. Re-registering with the explicit path
        // makes the bundled <domain>-<locale>-<handle>.json files load.
        $registry = WP_Block_Type_Registry::get_instance();
        foreach ( $registry->get_all_registered() as $block_type ) {
            if ( strpos( (string) $block_type->name, 'hypertaxonomy/' ) !== 0 ) {
                continue;
            }
            foreach ( (array) $block_type->editor_script_handles as $handle ) {
                wp_set_script_translations( $handle, 'hypertaxonomy', HTAX_PLUGIN_DIR . 'languages' );
            }
        }
    }

    /**
     * Render callback for the hypertaxonomy/post-list block.
     *
     * Delegates entirely to the existing shortcode handler so query logic,
     * caching, AJAX pagination, and output are identical to [ht_post_list].
     *
     * @param array  $attributes Block attributes (mapped from block.json schema).
     * @param string $content    Inner block content (unused — no inner blocks).
     * @return string Rendered HTML.
     */
    public static function render_post_list( $attributes, $content = '' ) {
        $instance = new HyperTaxonomy_Shortcode_Post_List();
        return $instance->shortcode_handler( $attributes, $content, 'ht_post_list' );
    }

    /**
     * Render callback for the hypertaxonomy/related-posts block.
     *
     * Sets up the post context from $block->context['postId'] so the shortcode's
     * get_the_ID() calls work correctly in both the block editor (ServerSideRender
     * preview via REST) and in FSE post templates. Delegates to render_for_block()
     * which bypasses the is_singular() guard (not meaningful outside template context).
     *
     * @param array    $attributes Block attributes.
     * @param string   $content    Inner block content (unused).
     * @param WP_Block $block      Block instance, provides context['postId'].
     * @return string Rendered HTML.
     */
    public static function render_related_posts( $attributes, $content, $block ) {
        $post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : get_the_ID();
        if ( ! $post_id ) {
            return '';
        }

        // Set up global $post so get_the_ID() and wp_get_object_terms() work
        // in the shortcode's get_query_term_ids() and get_exclude_post_ids().
        global $post;
        $saved_post = $post;
        $post       = get_post( $post_id );
        if ( ! $post ) {
            $post = $saved_post;
            return '';
        }
        setup_postdata( $post );

        $instance = new HyperTaxonomy_Shortcode_Related_Posts();
        $output   = $instance->render_for_block( $attributes );

        wp_reset_postdata();
        $post = $saved_post;

        return $output;
    }

    /**
     * Render callback for the hypertaxonomy/term-list block.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Unused.
     * @return string Rendered HTML.
     */
    public static function render_term_list( $attributes, $content = '' ) {
        $instance = new HyperTaxonomy_Term_List();
        return $instance->shortcode_handler( $attributes );
    }

    /**
     * Render callback for the hypertaxonomy/term-cloud block.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Unused.
     * @return string Rendered HTML.
     */
    public static function render_term_cloud( $attributes, $content = '' ) {
        $instance = new HyperTaxonomy_Cloud();
        return $instance->shortcode_handler( $attributes );
    }

    /**
     * Render callback for the hypertaxonomy/term-index block.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Unused.
     * @return string Rendered HTML.
     */
    public static function render_term_index( $attributes, $content = '' ) {
        $instance = new HyperTaxonomy_Term_Index();
        return $instance->shortcode_handler( $attributes );
    }

    /**
     * Render callback for the hypertaxonomy/term-groups block.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Unused.
     * @return string Rendered HTML.
     */
    public static function render_term_groups( $attributes, $content = '' ) {
        $instance = new HyperTaxonomy_Term_Groups();
        return $instance->shortcode_handler( $attributes );
    }

    /**
     * Render callback for the hypertaxonomy/sort-order-options block.
     *
     * Only renders on HyperTaxonomy-enabled taxonomy archives and search results
     * pages — the two contexts where $_GET['htax_orderby'] is consumed. Returns
     * empty string everywhere else so the block has no output on regular posts or
     * pages even if accidentally placed there.
     *
     * action is hardcoded to 'navigate': the block is intended for standalone use
     * on archive/search templates. Post-list AJAX sort integration uses the
     * sort_order_options= attribute on [htax_post_list] instead.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Unused.
     * @return string Rendered HTML, or empty string outside valid contexts.
     */
    public static function render_sort_order_options( $attributes, $content = '' ) {
        if ( ! htax_get_enabled_archive_taxonomy() && ! is_search() ) {
            return '';
        }

        // Pre-populate default from URL so the picker reflects the active sort.
        $sort_default = ! empty( $attributes['sort_default'] ) ? $attributes['sort_default'] : '';
        if ( '' === $sort_default && isset( $_GET['htax_orderby'] ) ) {
            $sort_default = sanitize_text_field( wp_unslash( $_GET['htax_orderby'] ) );
        }

        return htax_render_sort_order_picker( array(
            'fields'         => ! empty( $attributes['fields'] ) ? $attributes['fields'] : 'date,modified,title,author,comment_count',
            'state'          => ! empty( $attributes['state'] ) ? $attributes['state'] : 'expanded',
            'action'         => 'navigate',
            'default_value'  => $sort_default,
            'text_collapsed' => ! empty( $attributes['text_collapsed'] ) ? $attributes['text_collapsed'] : __( 'Sort', 'hypertaxonomy' ),
            'text_expanded'  => ! empty( $attributes['text_expanded'] ) ? $attributes['text_expanded'] : __( 'Sort', 'hypertaxonomy' ),
            'class'          => ! empty( $attributes['class'] ) ? $attributes['class'] : '',
        ) );
    }
    /**
     * Render callback for the hypertaxonomy/power-search block.
     *
     * Delegates to HyperTaxonomy_Power_Search::search_form_shortcode() so all
     * query logic, scoring, and output are identical to [htax_power_search].
     * The search shortcode auto-reads $_POST / $_GET for the query, so POST mode
     * results display inline and AJAX mode loads into the specified container.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Unused.
     * @return string Rendered HTML.
     */
    public static function render_power_search( $attributes, $content = '' ) {
        $atts = array(
            'placeholder'    => ! empty( $attributes['placeholder'] )    ? $attributes['placeholder']    : '',
            'button_text'    => ! empty( $attributes['button_text'] )    ? $attributes['button_text']    : '',
            'match'          => ! empty( $attributes['match'] )          ? $attributes['match']          : 'any',
            'min_length'     => ! empty( $attributes['min_length'] )     ? $attributes['min_length']     : '3',
            'search_in'      => ! empty( $attributes['search_in'] )      ? $attributes['search_in']      : 'all',
            'posts_per_page' => ! empty( $attributes['posts_per_page'] ) ? $attributes['posts_per_page'] : '10',
            'max_results'    => ! empty( $attributes['max_results'] ) ? $attributes['max_results'] : '0',
            'orderby'        => ! empty( $attributes['orderby'] )        ? $attributes['orderby']        : 'relevance:desc,date:desc',
            'min_score'      => ! empty( $attributes['min_score'] )      ? $attributes['min_score']      : '0.01',
            'ajax'           => ! empty( $attributes['ajax'] )           ? $attributes['ajax']           : '',
            'class'          => ! empty( $attributes['class'] )          ? $attributes['class']          : '',
            'near_country'   => ! empty( $attributes['near_country'] )   ? $attributes['near_country']   : 'US',
        );
        $instance = HyperTaxonomy_Power_Search::get_instance();
        return $instance->search_form_shortcode( $atts );
    }
}

add_action( 'init', array( 'HyperTaxonomy_Blocks', 'register' ) );
add_filter( 'block_categories_all', array( 'HyperTaxonomy_Blocks', 'register_category' ), 10, 1 );
