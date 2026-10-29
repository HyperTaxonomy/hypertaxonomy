<?php
/**
 * Capability Management for HyperTaxonomy
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

class HyperTaxonomy_Capabilities {

    /**
     * Custom capabilities
     */
    const CAPABILITIES = array(
        'manage_hypertaxonomy_settings',  // Admin only - plugin settings
        'manage_term_relationships',       // Admin + Editor - inclusion/container
        'manage_term_redirects',           // Admin + Editor - redirects
        'manage_term_icons',               // Admin + Editor - term icons
        'manage_term_visibility',          // Admin + Editor - hide/show terms
        'manage_term_sticky_posts',        // Admin + Editor - sticky posts
    );

    /**
     * Initialize capability management
     */
    public static function init() {
        // Hook to plugin activation
        register_activation_hook( HTAX_PLUGIN_BASENAME, array( __CLASS__, 'add_capabilities' ) );
        register_deactivation_hook( HTAX_PLUGIN_BASENAME, array( __CLASS__, 'remove_capabilities' ) );

        // Add capability checks to existing functions
        add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_capabilities' ), 10, 3 );
    }

    /**
     * Add capabilities to roles on activation
     */
    public static function add_capabilities() {
        // Add to administrator (all capabilities)
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::CAPABILITIES as $cap ) {
                $admin->add_cap( $cap );
            }
        }

        // Add to editor (all except settings)
        $editor = get_role( 'editor' );
        if ( $editor ) {
            $editor->add_cap( 'manage_term_relationships' );
            $editor->add_cap( 'manage_term_redirects' );
            $editor->add_cap( 'manage_term_icons' );
            $editor->add_cap( 'manage_term_visibility' );
            $editor->add_cap( 'manage_term_sticky_posts' );
        }
    }

    /**
     * Remove capabilities on deactivation
     */
    public static function remove_capabilities() {
        $roles = array( 'administrator', 'editor' );

        foreach ( $roles as $role_name ) {
            $role = get_role( $role_name );
            if ( $role ) {
                foreach ( self::CAPABILITIES as $cap ) {
                    $role->remove_cap( $cap );
                }
            }
        }
    }

    /**
     * Filter user capabilities - fallback to WordPress native capabilities
     */
    public static function filter_user_capabilities( $allcaps, $caps, $args ) {
        // Map manage_categories to editor-level custom capabilities (not settings).
        // manage_hypertaxonomy_settings is administrator-only; it must not be granted
        // to editors or any other role that holds manage_categories but not manage_options.
        if ( isset( $allcaps['manage_categories'] ) && $allcaps['manage_categories'] ) {
            $allcaps['manage_term_relationships'] = true;
            $allcaps['manage_term_redirects'] = true;
            $allcaps['manage_term_icons'] = true;
            $allcaps['manage_term_visibility'] = true;
        }

        // Map manage_categories (Editor+) to sticky posts capability, matching the
        // documented "Admin + Editor" role model. Contributors/Authors also hold
        // edit_posts but must not get this capability.
        if ( isset( $allcaps['manage_categories'] ) && $allcaps['manage_categories'] ) {
            $allcaps['manage_term_sticky_posts'] = true;
        }

        return $allcaps;
    }

    /**
     * Check if current user has a specific HyperTaxonomy capability
     */
    public static function current_user_can( $capability ) {
        // Fallback to WordPress native capabilities if our custom cap doesn't exist
        $fallback_caps = array(
            'manage_hypertaxonomy_settings' => 'manage_options',
            'manage_term_relationships' => 'manage_categories',
            'manage_term_redirects' => 'manage_categories',
            'manage_term_icons' => 'manage_categories',
            'manage_term_visibility' => 'manage_categories',
            'manage_term_sticky_posts' => 'manage_categories',
        );

        if ( current_user_can( $capability ) ) {
            return true;
        }

        // Check fallback capability
        if ( isset( $fallback_caps[ $capability ] ) ) {
            return current_user_can( $fallback_caps[ $capability ] );
        }

        return false;
    }
}

// Initialize
HyperTaxonomy_Capabilities::init();
