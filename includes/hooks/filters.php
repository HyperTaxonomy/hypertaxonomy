<?php
/**
 * Register all WordPress filter hooks
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// PLUGIN META AND LINKS
// ==============================================================================

add_filter( 'plugin_action_links_' . HTAX_PLUGIN_BASENAME, 'htax_add_settings_link' );
add_filter( 'plugin_row_meta', 'htax_add_plugin_meta_links', 10, 2 );

// ==============================================================================
// TERM VISIBILITY (DYNAMIC REGISTRATION)
// ==============================================================================

// Register filters dynamically for all enabled taxonomies.
// Deferred to init so custom taxonomies registered on init are included.
add_action( 'init', function() {
    $enabled_taxonomies = htax_get_enabled_taxonomies();

    // Filter term links to hide hidden terms.
    foreach ( $enabled_taxonomies as $taxonomy ) {
        add_filter( "term_links-{$taxonomy}", function( $term_links ) use ( $taxonomy ) {
            return htax_filter_term_links( $term_links, $taxonomy );
        }, 10, 1 );
    }
}, 99 );

// Filter get_the_terms to mark hidden terms
add_filter( 'get_the_terms', 'htax_filter_get_the_terms', 10, 3 );

// Filter tag cloud to exclude hidden terms
add_filter( 'wp_tag_cloud', 'htax_filter_tag_cloud', 10, 2 );

// ==============================================================================
// ADMIN COLUMNS (DYNAMIC REGISTRATION)
// ==============================================================================

// Register columns on admin_init to ensure correct priority order
// (Term Groups column is registered in tables.php on admin_init priority 999)
add_action( 'admin_init', function() {
    $enabled_taxonomies = htax_get_enabled_taxonomies();

    foreach ( $enabled_taxonomies as $taxonomy ) {
        // Redirect column (priority 13 - after Term Groups at 12)
        add_filter( "manage_edit-{$taxonomy}_columns", 'htax_add_redirect_column', 13 );
        add_filter( "manage_{$taxonomy}_custom_column", 'htax_display_redirect_column', 10, 3 );
        add_filter( "manage_edit-{$taxonomy}_sortable_columns", 'htax_register_sortable_columns' );

        // Icon column (priority 14 - after Redirect)
        add_filter( "manage_edit-{$taxonomy}_columns", 'htax_add_icon_column', 14 );
        add_filter( "manage_{$taxonomy}_custom_column", 'htax_render_icon_column', 10, 3 );

        // Hidden/Visibility column (priority 15 - after Icon)
        add_filter( "manage_edit-{$taxonomy}_columns", 'htax_add_hidden_column', 15 );
        add_filter( "manage_{$taxonomy}_custom_column", 'htax_display_hidden_column', 10, 3 );

        // Hide Icon and Visibility columns by default.
        add_filter( 'default_hidden_columns', function( $hidden, $screen ) use ( $taxonomy ) {
            if ( $screen && "edit-{$taxonomy}" === $screen->id ) {
                $hidden[] = 'htax_icon';
                $hidden[] = 'htax_hidden';
            }
            return $hidden;
        }, 10, 2 );

        // Bulk actions
        add_filter( "bulk_actions-edit-{$taxonomy}", 'htax_add_bulk_actions' );
        add_filter( "handle_bulk_actions-edit-{$taxonomy}", 'htax_handle_bulk_actions', 10, 3 );
    }
}, 999 );

// Admin notice for bulk actions
add_action( 'admin_notices', 'htax_bulk_action_admin_notice' );

// ==============================================================================
// QUERY FILTERING (ADMIN ONLY)
// ==============================================================================

if ( is_admin() ) {
    // Filter terms by redirect status in admin
    add_filter( 'get_terms_args', 'htax_filter_terms_by_redirect', 10, 2 );

    // Filter terms by icon presence in admin
    add_filter( 'get_terms_args', 'htax_filter_terms_by_icon', 10, 2 );

    // Filter terms by visibility in admin
    add_filter( 'get_terms_args', 'htax_filter_terms_by_visibility', 10, 2 );

    // Filter terms by group membership in admin
    add_filter( 'get_terms_args', 'htax_filter_terms_by_group', 10, 2 );
}

// ==============================================================================
// ARCHIVE DISPLAY
// ==============================================================================

// Filter archive title to show contributing terms
add_filter( 'get_the_archive_title', 'htax_filter_title' );

// ==============================================================================
// POST STYLING
// ==============================================================================

// Add CSS classes to sticky posts
add_filter( 'post_class', 'htax_filter_post_class', 10, 3 );

// ==============================================================================
// THE_POSTS FILTER
// ==============================================================================

// Prepend sticky posts to archives
add_filter( 'the_posts', 'htax_prepend_sticky_posts', 10, 2 );

// Count pinned sticky posts back into the archive's found_posts
add_filter( 'found_posts', 'htax_sticky_found_posts', 10, 2 );

// Search results pages: load exactly the engine's page and report its true total
add_filter( 'post_limits', 'htax_search_page_post_limits', 10, 2 );
add_filter( 'found_posts', 'htax_search_page_found_posts', 10, 2 );

// Show the text a HyperTaxonomy search ran for in search titles
add_filter( 'get_search_query', 'htax_filter_search_query_text' );
add_action( 'pre_get_search_form', 'htax_track_search_form_rendering' );
add_filter( 'get_search_form', 'htax_track_search_form_rendering' );
add_filter( 'pre_render_block', 'htax_track_search_form_rendering', 10, 2 );
add_filter( 'render_block_core/search', 'htax_track_search_form_rendering' );

// ==============================================================================
// ADMIN TERM LINKS WITH STICKY INDICATORS (DYNAMIC REGISTRATION)
// ==============================================================================

// Add sticky indicators to term links in admin for all enabled taxonomies.
// Deferred to admin_init so custom taxonomies registered on init are included.
add_action( 'admin_init', function() {
    $enabled_taxonomies = htax_get_enabled_taxonomies();
    foreach ( $enabled_taxonomies as $taxonomy ) {
        add_filter( "term_links-{$taxonomy}", function( $term_links ) use ( $taxonomy ) {
            global $post;
            if ( ! $post ) {
                return $term_links;
            }
            return htax_add_sticky_indicators( $term_links, $taxonomy, $post->ID );
        }, 10, 1 );
    }
}, 999 );

// ==============================================================================
// ADMIN STYLING
// ==============================================================================

// Add class to redirect term rows
add_filter( 'tag_row_class', 'htax_tag_row_class', 10, 2 );

// ==============================================================================
// NATIVE BLOCK EXTENSIONS — TERM GROUP FILTER
// ==============================================================================

// Modify WP_Query args for the Query Loop block when htax_filter_groups is set.
// Requires WordPress 6.1+. Safe to register unconditionally — the filter simply
// receives no calls on older WordPress versions.
add_filter( 'query_loop_block_query_vars', 'htax_query_loop_group_filter', 10, 3 );

// Modify WP_Query args for the Latest Posts block when htax_filter_groups is set.
// Uses a one-shot pre_get_posts action injected in pre_render_block.
add_filter( 'pre_render_block', 'htax_latest_posts_group_filter', 10, 2 );

// ==============================================================================
// FRONTEND STYLES CUSTOMIZATION
// ==============================================================================

// Allow themes to filter frontend styles
// Applied in htax_frontend_styles() function via: apply_filters( 'htax_frontend_styles', $css )

// Allow themes to disable plugin styles
// Applied in htax_frontend_styles() function via: apply_filters( 'htax_disable_frontend_styles', false )

// ==============================================================================
// DESCRIPTION HTML
// ==============================================================================

// Filter allowed HTML tags for term descriptions
// Applied in htax_get_allowed_description_html() via: apply_filters( 'htax_allowed_description_html', $allowed_tags )

// ==============================================================================
// REST API
// ==============================================================================

add_action( 'rest_api_init', 'htax_register_rest_term_filters' );
add_action( 'rest_api_init', 'htax_rest_register_post_extensions' );
add_action( 'rest_api_init', 'htax_register_tag_suggestions_rest_route' );

// WP stores term names HTML-encoded in the DB (e.g. "H&amp;M"). Gutenberg's tag
// autocomplete sends raw text via REST, so "h&m" never matches "H&amp;M".
// Normalize the search arg to encoded form for the duration of REST requests only.
add_action( 'rest_api_init', function () {
    add_filter( 'get_terms_args', function ( $args, $taxonomies ) {
        if ( isset( $args['search'] ) && is_string( $args['search'] ) && $args['search'] !== '' ) {
            $decoded        = html_entity_decode( $args['search'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $args['search'] = htmlspecialchars( $decoded, ENT_QUOTES, 'UTF-8' );
        }
        return $args;
    }, 10, 2 );
} );
$htax_options = get_option( 'htax_settings', array() );
if ( ! isset( $htax_options['advertise_ht_presence'] ) || ! empty( $htax_options['advertise_ht_presence'] ) ) {
    add_filter( 'rest_post_dispatch', 'htax_rest_add_version_header' );
}
unset( $htax_options );

// ==============================================================================
// SITEMAPS
// ==============================================================================

add_filter( 'wp_sitemaps_taxonomies_query_args', 'htax_filter_sitemap_terms_query', 10, 2 );

add_filter( 'pre_insert_term', 'htax_maybe_prevent_tag_creation', 10, 2 );

// ==============================================================================
// DYNAMIC FILTERS (Applied Conditionally)
// ==============================================================================

// Query monitoring (applied in htax_log_debug when HTAX_QUERY_MONITOR is defined)
// - add_filter( 'query', ... )
// - add_filter( 'query_results', ... )
