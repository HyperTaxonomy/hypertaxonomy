<?php
/**
 * Uninstall script for HyperTaxonomy
 *
 * Settings, internal state, scheduled hooks, transients and per-user state are always
 * removed. Term data (term/post meta, the group taxonomy, HT's tables and the indexes HT
 * added to core tables) is removed only when "Delete all data on uninstall" is enabled
 * (Settings → HyperTaxonomy). On multisite every site is cleaned with its own setting.
 *
 * HyperTaxonomy's code is not loaded here, so its taxonomies (e.g. `group`) are not
 * registered: term data is removed with direct SQL rather than get_terms()/wp_delete_term(),
 * which refuse unregistered taxonomies.
 *
 * @package HyperTaxonomy
 * @since 1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up the current site.
 */
function htax_uninstall_cleanup() {
    global $wpdb;

    $options     = get_option( 'htax_settings', array() );
    $delete_data = isset( $options['delete_on_uninstall'] ) && $options['delete_on_uninstall'];

    // Always remove plugin settings and internal state options.
    delete_option( 'htax_settings' );
    delete_option( 'htax_version' );
    delete_option( 'htax_db_version' );
    delete_option( 'htax_custom_relationship_types' );
    delete_option( 'htax_pending_reindex' );
    delete_option( 'htax_cleaned_panel_prefs_2' );
    delete_option( 'htax_invalidate_all_contrib_caches' );

    // Tag Groups import / live-sync state.
    delete_option( 'htax_tag_groups_integration_mode' );
    delete_option( 'htax_tag_groups_sync_mode' );
    delete_option( 'htax_tag_groups_live_sync' );
    delete_option( 'htax_tag_groups_site_token' );
    delete_option( 'htax_tag_groups_last_import' );

    // Always clean up scheduled hooks.
    wp_clear_scheduled_hook( 'htax_rebuild_indexes' );
    wp_clear_scheduled_hook( 'htax_process_reindex_batch' );
    wp_clear_scheduled_hook( 'htax_async_rebuild_term_caches' );
    wp_clear_scheduled_hook( 'htax_async_group_propagate' );

    // Always remove HT transients — caches, plus stored POST-mode search states (htax_ss_*).
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\_transient\_htax\_%'
            OR option_name LIKE '\_transient\_timeout\_htax\_%'"
    );

    // Per-user block-editor preferences: HT's display-preference scope, and HT sidebar
    // panels in the core open/inactive panel lists. WordPress stores these per site,
    // under the site's table prefix.
    $prefs_key = $wpdb->get_blog_prefix() . 'persisted_preferences';
    // blog_id 0: every user holding the key, including network admins who aren't site members.
    foreach ( get_users( array( 'fields' => 'ID', 'meta_key' => $prefs_key, 'blog_id' => 0 ) ) as $user_id ) {
        $prefs = get_user_meta( $user_id, $prefs_key, true );
        if ( ! is_array( $prefs ) ) {
            continue;
        }
        $before = $prefs;
        unset( $prefs['htax/post-edit'] );
        foreach ( array( 'openPanels', 'inactivePanels' ) as $list ) {
            if ( isset( $prefs['core'][ $list ] ) && is_array( $prefs['core'][ $list ] ) ) {
                $prefs['core'][ $list ] = array_values( array_filter(
                    $prefs['core'][ $list ],
                    static fn( $panel ) => 0 !== strpos( (string) $panel, 'htax-' )
                ) );
            }
        }
        if ( $prefs !== $before ) {
            // Newer than any browser copy the editor keeps, so that copy can't restore them.
            $prefs['_modified'] = gmdate( 'Y-m-d\TH:i:s.000\Z' );
            update_user_meta( $user_id, $prefs_key, $prefs );
        }
    }

    // Only delete term data and tables if the user opted in.
    if ( $delete_data ) {
        // The group taxonomy: its terms, their relationships and their meta.
        $group_tt_ids   = array_map( 'intval', $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'group'" ) );
        $group_term_ids = array_map( 'intval', $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'group'" ) );
        if ( $group_tt_ids ) {
            $tt_list = implode( ',', $group_tt_ids );
            $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($tt_list)" );
            $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($tt_list)" );
        }
        if ( $group_term_ids ) {
            $term_list = implode( ',', $group_term_ids );
            $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ($term_list)" );
            // A term row can be shared by another taxonomy (pre-4.2 shared terms); keep those.
            $wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ($term_list) AND term_id NOT IN ( SELECT term_id FROM {$wpdb->term_taxonomy} )" );
        }

        // HT term meta on every taxonomy (archive settings, icons, hidden flag, redirects,
        // sort order, count caches, Tag Groups mapping, …) and post meta (sticky assignments).
        $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\_htax\_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_htax\_%'" );

        // Drop HyperTaxonomy custom tables.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}htax_search_terms" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}htax_term_relationships" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}htax_post_text" );

        // Remove indexes added to WordPress core tables.
        $index_drops = array(
            $wpdb->posts         => array( 'htax_post_date', 'htax_post_modified' ),
            $wpdb->terms         => array( 'htax_ft_term_name' ),
            $wpdb->term_taxonomy => array( 'htax_ft_term_desc' ),
            $wpdb->termmeta      => array( 'htax_icons', 'htax_term_meta' ),
        );
        foreach ( $index_drops as $table => $indexes ) {
            foreach ( $indexes as $index_name ) {
                $exists = $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE table_schema = DATABASE()
                         AND table_name = %s AND index_name = %s',
                        $table,
                        $index_name
                    )
                );
                if ( $exists ) {
                    $safe_table = '`' . esc_sql( $table ) . '`';
                    $safe_index = '`' . esc_sql( $index_name ) . '`';
                    $wpdb->query( "ALTER TABLE $safe_table DROP INDEX $safe_index" );
                }
            }
        }
    }
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $site_id ) {
        switch_to_blog( $site_id );
        htax_uninstall_cleanup();
        restore_current_blog();
    }
} else {
    htax_uninstall_cleanup();
}

// Per-user state that isn't per site: dismissed admin notices.
foreach ( array( '_htax_dismissed_tag_groups_notice', '_htax_dismissed_taxopress_merge_notice', '_htax_prefs_callout_dismissed' ) as $user_meta_key ) {
    delete_metadata( 'user', 0, $user_meta_key, '', true );
}

wp_cache_flush();
