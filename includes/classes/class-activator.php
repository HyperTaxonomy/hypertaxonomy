<?php
/**
 * Plugin activation handler
 */
class HyperTaxonomy_Activator {
    
    /**
     * Run on plugin activation
     */
    public static function activate() {
        self::create_tables();
        self::add_indexes();
        self::set_version();
    }
    
    /**
     * Create custom tables
     */
    private static function create_tables() {
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

        $table_name = $wpdb->prefix . 'htax_term_relationships';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            source_term_id bigint(20) NOT NULL,
            target_term_id bigint(20) NOT NULL,
            relationship_type varchar(100) NOT NULL,
            source_taxonomy varchar(32) NOT NULL,
            target_taxonomy varchar(32) NOT NULL,
            PRIMARY KEY (source_term_id, target_term_id, relationship_type),
            KEY target_lookup (target_term_id, relationship_type),
            KEY source_taxonomy (source_taxonomy),
            KEY target_taxonomy (target_taxonomy),
            KEY relationship_type (relationship_type)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
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

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            post_id           BIGINT(20) UNSIGNED NOT NULL,
            post_status       VARCHAR(20) NOT NULL DEFAULT 'publish',
            post_type         VARCHAR(20) NOT NULL DEFAULT 'post',
            post_date_gmt     DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_modified_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_author       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            comment_count     BIGINT(20) NOT NULL DEFAULT 0,
            last_comment_gmt  DATETIME DEFAULT NULL,
            title             TEXT NOT NULL,
            body              LONGTEXT NOT NULL,
            excerpt           TEXT NOT NULL,
            PRIMARY KEY (post_id),
            KEY htax_pt_status_type  (post_status, post_type),
            KEY htax_pt_date         (post_date_gmt),
            KEY htax_pt_modified     (post_modified_gmt),
            KEY htax_pt_comments     (comment_count),
            KEY htax_pt_last_comment (last_comment_gmt),
            FULLTEXT KEY htax_ft_pt_title   (title),
            FULLTEXT KEY htax_ft_pt_body    (body),
            FULLTEXT KEY htax_ft_pt_excerpt (excerpt),
            FULLTEXT KEY htax_ft_pt_all     (title, body, excerpt)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
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
            // 1. wp_posts date indexes — help WP_Query sort archives by date/modified.
            // Post text FULLTEXT indexes are on wp_htax_post_text, not wp_posts.
            $posts_indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->posts}" );
            $existing_keys = array();
            if ( is_array( $posts_indexes ) ) {
                foreach ( $posts_indexes as $idx ) {
                    $existing_keys[ $idx->Key_name ] = true;
                }
            }

            if ( ! isset( $existing_keys['htax_post_date'] ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->posts} ADD INDEX htax_post_date (post_date, post_status, post_type)" );
            }

            if ( ! isset( $existing_keys['htax_post_modified'] ) ) {
                $wpdb->query(
                    "ALTER TABLE {$wpdb->posts} ADD INDEX htax_post_modified (post_modified, post_status, post_type)"
                );
            }

            // 2. Term meta indexes for HyperTaxonomy queries

            // Index for icon lookups
            $wpdb->query( "ALTER TABLE {$wpdb->termmeta}
                          ADD INDEX htax_icons (meta_key(50), term_id)" );

            // General index to help get_term_meta() calls
            $wpdb->query( "ALTER TABLE {$wpdb->termmeta}
                          ADD INDEX htax_term_meta (term_id, meta_key(50))" );

            // 3. FULLTEXT indexes for taxonomy term text search.
            // wp_terms.name and wp_term_taxonomy.description are the canonical sources —
            // one row per unique term, so both FT indexes are tiny (~750 rows each) and
            // stay warm in the buffer pool.  Search uses a two-branch UNION so each index
            // is the primary access path for its respective field.
            $terms_indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->terms}" );
            $existing_terms_keys = array();
            if ( is_array( $terms_indexes ) ) {
                foreach ( $terms_indexes as $idx ) {
                    $existing_terms_keys[ $idx->Key_name ] = true;
                }
            }
            if ( ! isset( $existing_terms_keys['htax_ft_term_name'] ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->terms} ADD FULLTEXT INDEX htax_ft_term_name (name)" );
            }

            $tt_indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->term_taxonomy}" );
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
     * Set plugin version
     */
    private static function set_version() {
        update_option( 'htax_version', HTAX_VERSION );
        update_option( 'htax_db_version', '1.0.0' );
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