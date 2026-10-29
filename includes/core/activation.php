<?php
/**
 * Activation, Deactivation, and Installation Functions
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add security headers for search requests.
 *
 * @since 1.0.0
 */
function htax_add_security_headers() {
    if ( ! is_admin() && isset( $_GET['htax_search'] ) ) {
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'X-XSS-Protection: 1; mode=block' );
    }
}

/**
 * Activation handler with multisite support.
 *
 * @since 1.0.0
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 */
function htax_activate( $network_wide = false ) {
    if ( is_multisite() && $network_wide ) {
        // Network activation.
        htax_activate_network();
    } else {
        // Single site activation.
        htax_activate_single_site();
    }
}

/**
 * Network activation.
 *
 * @since 1.0.0
 */
function htax_activate_network() {
    $sites = get_sites();
    foreach ( $sites as $site ) {
        switch_to_blog( $site->blog_id );
        htax_activate_single_site();
        restore_current_blog();
    }
}

/**
 * Single site activation.
 *
 * @since 1.0.0
 */
function htax_activate_single_site() {
    // Set default options if not already set.
    if ( ! get_option( 'htax_settings' ) ) {
        add_option(
            'htax_settings',
            array(
                'enabled_taxonomies'          => array(
                    'post_tag' => true,
                    'category' => true,
                ),
                'show_redirect_column'        => array(
                    'post_tag' => true,
                    'category' => true,
                ),
                'show_contributing_terms'     => true,
            )
        );
    }

    // Set initial version.
    add_option( 'htax_version', HTAX_VERSION );

    // Run the activation class method.
    HyperTaxonomy_Activator::activate();

    // Set up capabilities.
    if ( class_exists( 'HyperTaxonomy_Capabilities' ) ) {
        HyperTaxonomy_Capabilities::add_capabilities();
    }

    // Repair state that may have drifted while the plugin was inactive.
    htax_repair_visibility_cascade();
    htax_repair_orphaned_term_relationships();
    htax_repair_orphaned_sticky_meta();
    htax_repair_hierarchy_conflicts();

    // Schedule search index build after repairs are complete.
    HyperTaxonomy_Activator::schedule_index_rebuild();

    // Register taxonomies before flushing rewrite rules to ensure group archives work.
    htax_register_group_taxonomy();

    // Flush rewrite rules.
    flush_rewrite_rules();

    // Create search terms table.
    htax_create_search_term_index_table();
}

/**
 * Remove direct inclusion, container, and exclusion rows that place an ancestor
 * or descendant of a term in its HT relationship sets.
 *
 * Runs on activation to catch conflicts that accumulated while the plugin was
 * inactive (e.g. another plugin reparented terms, or a bulk import changed the
 * taxonomy hierarchy). The same row-removal logic runs at save time and on the
 * edited_term hook for individual edits; this is the activation-time sweep.
 *
 * @since 1.0.0
 * @return int Number of terms that had conflict rows removed.
 */
function htax_repair_hierarchy_conflicts(): int {
    $repaired = 0;
    foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
        if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
            continue;
        }
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) ) {
            continue;
        }
        foreach ( $terms as $term ) {
            if ( ! $term->parent ) {
                continue; // Root terms have no ancestors — no conflicts possible.
            }
            if ( htax_remove_hierarchy_conflict_rows( $term->term_id, $taxonomy ) ) {
                htax_delete_term_transients( $term->term_id );
                ++$repaired;
            }
        }
    }
    return $repaired;
}

/**
 * Handle new site creation in multisite.
 *
 * @since 1.0.0
 * @param WP_Site $new_site Newly created site object.
 */
function htax_new_site_created( $new_site ) {
    if ( is_plugin_active_for_network( HTAX_PLUGIN_BASENAME ) ) {
        switch_to_blog( $new_site->blog_id );
        htax_activate_single_site();
        restore_current_blog();
    }
}

/**
 * Deactivation handler with multisite support.
 *
 * @since 1.0.0
 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
 */
function htax_deactivate( $network_wide = false ) {
    if ( is_multisite() && $network_wide ) {
        // Network deactivation.
        htax_deactivate_network();
    } else {
        // Single site deactivation.
        htax_deactivate_single_site();
    }
}

/**
 * Network deactivation.
 *
 * @since 1.0.0
 */
function htax_deactivate_network() {
    $sites = get_sites();
    foreach ( $sites as $site ) {
        switch_to_blog( $site->blog_id );
        htax_deactivate_single_site();
        restore_current_blog();
    }
}

/**
 * Single site deactivation.
 *
 * @since 1.0.0
 */
function htax_deactivate_single_site() {
    // Clear transients.
    htax_clear_all_caches();

    // Remove capabilities.
    if ( class_exists( 'HyperTaxonomy_Capabilities' ) ) {
        HyperTaxonomy_Capabilities::remove_capabilities();
    }

    // Remove the group taxonomy's archive rewrite rules. Not flush_rewrite_rules(): the plugin's rules are still registered during this request, so flush_rewrite_rules() would rebuild them; dropping the stored rules makes WordPress rebuild them without this plugin on the next request.
    delete_option( 'rewrite_rules' );
}
