<?php
/**
 * Handle database upgrades between versions
 */
class HyperTaxonomy_Upgrader {
    
    /**
     * Check and run upgrades if needed
     */
    public static function check_upgrades() {
        $current_db_version = get_option( 'htax_db_version', '0.0.0' );

        if ( version_compare( $current_db_version, '1.0.0', '<' ) ) {
            self::upgrade_to_1_0_0();
        }

        if ( ! get_option( 'htax_cleaned_panel_prefs_2' ) ) {
            self::clean_stale_panel_prefs();
        }
    }

    /**
     * Remove stale Gutenberg panel IDs from all users' persisted block-editor preferences
     * (user meta {table prefix}persisted_preferences).
     *
     * Panels removed during development leave their names in the openPanels
     * preference array, which Gutenberg uses to determine sidebar render order.
     * Stale entries can push active panels to the wrong position.
     *
     * The editor also keeps a copy in the browser's localStorage and uses whichever copy
     * has the newer `_modified` timestamp, so the cleaned copy is stamped with the current
     * time — otherwise a browser copy could restore the stale entries.
     */
    private static function clean_stale_panel_prefs() {
        $stale = array(
            'htax-sticky-terms/htax-sticky-terms',
            'htax-tag-suggestions/htax-tag-suggestions-panel',
        );

        global $wpdb;
        $prefs_key = $wpdb->get_blog_prefix() . 'persisted_preferences';
        $users     = get_users( array( 'fields' => 'ID', 'meta_key' => $prefs_key ) );
        foreach ( $users as $user_id ) {
            $prefs = get_user_meta( $user_id, $prefs_key, true );
            if ( ! is_array( $prefs ) || empty( $prefs['core']['openPanels'] ) ) {
                continue;
            }
            $before = $prefs['core']['openPanels'];
            $after  = array_values( array_diff( $before, $stale ) );
            if ( $after !== $before ) {
                $prefs['core']['openPanels'] = $after;
                $prefs['_modified']          = gmdate( 'Y-m-d\TH:i:s.000\Z' );
                update_user_meta( $user_id, $prefs_key, $prefs );
            }
        }

        update_option( 'htax_cleaned_panel_prefs_2', true );
    }

    /**
     * Upgrade to version 1.0.0
     */
    private static function upgrade_to_1_0_0() {
        HyperTaxonomy_Activator::add_indexes();
        update_option( 'htax_db_version', '1.0.0' );
    }
    
    
}