<?php
/**
 * Plugin activation handler
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_Activator {

    /**
     * The plugin's custom tables, without the table prefix.
     *
     * @since 1.0.0
     * @var string[]
     */
    const TABLES = array( 'htax_search_terms', 'htax_term_relationships', 'htax_post_text' );

    /**
     * Run on plugin activation.
     *
     * @since 1.0.0
     */
    public static function activate() {
        update_option( 'htax_version', HTAX_VERSION );
        self::install_schema();
    }

    /**
     * Create or update the custom tables and indexes, then record the database version.
     *
     * The version is recorded only when every table exists afterwards, so a failed
     * install is retried by HyperTaxonomy_Upgrader on the next admin page load instead
     * of being marked done.
     *
     * @since 1.0.0
     *
     * @return bool True when all tables exist.
     */
    public static function install_schema(): bool {
        self::create_tables();
        self::add_indexes();

        $missing = self::get_missing_tables();
        if ( ! empty( $missing ) ) {
            htax_log_error( 'Could not create database tables: ' . implode( ', ', $missing ), array(), false );
            return false;
        }

        update_option( 'htax_db_version', '1.0.0' );
        return true;
    }

    /**
     * Get the custom tables that don't exist.
     *
     * @since 1.0.0
     *
     * @return string[] Full names of the missing tables.
     */
    public static function get_missing_tables(): array {
        global $wpdb;

        $missing = array();
        foreach ( self::TABLES as $table ) {
            $name = $wpdb->prefix . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /**
     * Create custom tables
     */
    private static function create_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        htax_create_search_term_index_table();
        self::create_term_relationships_table();
        self::create_post_text_table();
    }

    /**
     * Create unified term relationships table
     *
     * Stores all term relationships in a single table:
     * - Direct inclusions: source=A, target=B, type='includes'
     * - Group membership: source=Term, target=Group, type='member_of'
     * - Group inclusions: source=Term, target=Group, type='includes'
     * - Container groups: source=Group, target=Term, type='includes'
     * - Redirects: source=Old, target=New, type='redirect'
     *
     * @since 1.0
     */
    private static function create_term_relationships_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'htax_term_relationships';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            source_term_id bigint(20) NOT NULL,
            target_term_id bigint(20) NOT NULL,
            relationship_type varchar(100) NOT NULL,
            source_taxonomy varchar(32) NOT NULL,
            target_taxonomy varchar(32) NOT NULL,
            PRIMARY KEY  (source_term_id, target_term_id, relationship_type),
            KEY target_lookup (target_term_id, relationship_type),
            KEY source_taxonomy (source_taxonomy),
            KEY target_taxonomy (target_taxonomy),
            KEY relationship_type (relationship_type)
        ) $charset_collate;";

        dbDelta( $sql );
    }

    /**
     * Create post plain-text table for clean FULLTEXT search and sort metadata.
     *
     * Stores stripped post content (HTML removed) alongside the columns needed
     * for sorting (post_date_gmt, post_modified_gmt, comment_count, last_comment_gmt)
     * so queries never need to JOIN wp_posts for ordering.
     *
     * Populated by HyperTaxonomy_Search_Index_Manager::rebuild_indexes() (scheduled
     * 30 seconds after activation via htax_rebuild_indexes cron event) and by the
     * admin Rebuild Index tool. Kept in sync incrementally by htax_sync_post_text_row
     * (save_post) and htax_sync_post_text_comment (wp_update_comment_count).
     *
     * @since 1.0
     */
    private static function create_post_text_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'htax_post_text';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            post_id BIGINT(20) UNSIGNED NOT NULL,
            post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
            post_type VARCHAR(20) NOT NULL DEFAULT 'post',
            post_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_modified_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_author BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            comment_count BIGINT(20) NOT NULL DEFAULT 0,
            last_comment_gmt DATETIME DEFAULT NULL,
            title TEXT NOT NULL,
            body LONGTEXT NOT NULL,
            excerpt TEXT NOT NULL,
            PRIMARY KEY  (post_id),
            KEY htax_pt_status_type (post_status, post_type),
            KEY htax_pt_date (post_date_gmt),
            KEY htax_pt_modified (post_modified_gmt),
            KEY htax_pt_comments (comment_count),
            KEY htax_pt_last_comment (last_comment_gmt),
            FULLTEXT KEY htax_ft_pt_title (title),
            FULLTEXT KEY htax_ft_pt_body (body),
            FULLTEXT KEY htax_ft_pt_excerpt (excerpt),
            FULLTEXT KEY htax_ft_pt_all (title, body, excerpt)
        ) $charset_collate;";

        dbDelta( $sql );
    }

    /**
     * Add custom indexes for better performance
     */
    private static function add_indexes() {
        global $wpdb;

        // Suppress errors during index creation (in case they already exist)
        $wpdb->suppress_errors();

        try {
            // FULLTEXT indexes for taxonomy term text search.
            // wp_terms.name and wp_term_taxonomy.description are the canonical sources —
            // one row per unique term, so both FT indexes are tiny (~750 rows each) and
            // stay warm in the buffer pool.  Search uses a two-branch UNION so each index
            // is the primary access path for its respective field.
            $terms_indexes       = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->terms}" );
            $existing_terms_keys = array();
            if ( is_array( $terms_indexes ) ) {
                foreach ( $terms_indexes as $idx ) {
                    $existing_terms_keys[ $idx->Key_name ] = true;
                }
            }
            if ( ! isset( $existing_terms_keys['htax_ft_term_name'] ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->terms} ADD FULLTEXT INDEX htax_ft_term_name (name)" );
            }

            $tt_indexes       = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->term_taxonomy}" );
            $existing_tt_keys = array();
            if ( is_array( $tt_indexes ) ) {
                foreach ( $tt_indexes as $idx ) {
                    $existing_tt_keys[ $idx->Key_name ] = true;
                }
            }
            if ( ! isset( $existing_tt_keys['htax_ft_term_desc'] ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->term_taxonomy} ADD FULLTEXT INDEX htax_ft_term_desc (description)" );
            }
        } finally {
            $wpdb->suppress_errors( false );
        }
    }

    /**
     * Schedule a full rebuild of both search indexes (wp_htax_search_terms and
     * wp_htax_post_text) as a background WP Cron event 30 seconds from now.
     *
     * The delay lets WordPress finish the activation process and gives repair
     * scans (visibility cascade, orphaned rows, hierarchy conflicts) time to
     * complete so the rebuild starts from clean data. Idempotent — skips
     * scheduling if the event is already queued.
     */
    public static function schedule_index_rebuild(): void {
        if ( ! wp_next_scheduled( 'htax_rebuild_indexes' ) ) {
            wp_schedule_single_event( time() + 30, 'htax_rebuild_indexes' );
        }
    }
}
