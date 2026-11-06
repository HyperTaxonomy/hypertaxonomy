<?php
/**
 * Capability Management for HyperTaxonomy
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
}

// Initialize
HyperTaxonomy_Capabilities::init();
