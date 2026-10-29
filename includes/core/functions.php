<?php
/**
 * Core Utility Functions
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generate safe transient key
 *
 * @param string $prefix Transient prefix
 * @param string $unique Unique identifier
 * @param int $max_length Maximum key length (default 172 for transients)
 * @return string Safe transient key
 */
function htax_generate_transient_key( $prefix, $unique, $max_length = 172 ) {
    $key = $prefix . $unique;

    // If key is too long, use hash for the unique part
    if ( strlen( $key ) > $max_length ) {
        // Preserve prefix for easy identification, hash the rest
        $max_unique_length = $max_length - strlen( $prefix ) - 1; // -1 for separator

        if ( $max_unique_length < 32 ) {
            // If not enough space, just hash everything (SHA-256 provides 2^128 collision resistance)
            $key = $prefix . substr( hash( 'sha256', $unique ), 0, $max_length - strlen( $prefix ) );
        } else {
            // Use partial original + hash (16 chars from SHA-256 = 2^64 possibilities)
            $hash = substr( hash( 'sha256', $unique ), 0, 16 );
            $remaining = $max_unique_length - strlen( $hash ) - 1;
            $key = $prefix . substr( $unique, 0, $remaining ) . '_' . $hash;
        }
    }

    return $key;
}

/**
 * Health check for HyperTaxonomy
 *
 * @return array Array of issues found
 */
function htax_health_check() {
    $issues = array();

    // Check Term Groups
    if ( ! taxonomy_exists( 'group' ) ) {
        $issues[] = 'Term Groups taxonomy not registered';
    }

    // Check database tables
    global $wpdb;
    $term_meta_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->termmeta ) ) );

    $error = htax_handle_db_error( 'health_check_tables', null );
    if ( false !== $error ) {
        $issues[] = 'Database error during table check';
        return $issues;
    }

    if ( ! $term_meta_exists ) {
        $issues[] = 'Term meta table missing';
    }

    // Check PHP version
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        $issues[] = 'PHP version below 7.4';
    }

    // Log any issues found
    if ( ! empty( $issues ) ) {
        htax_log_debug( 'Health check found issues', $issues );
    }

    return $issues;
}

/**
 * Load text domain for translations
 */
function htax_load_textdomain() {
    load_plugin_textdomain( 'hypertaxonomy', false, dirname( HTAX_PLUGIN_BASENAME ) . '/languages' );
}

/**
 * Add settings link on plugin page
 */
function htax_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'options-general.php?page=hypertaxonomy' ) . '">' . __( 'Settings', 'hypertaxonomy' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Add additional links in plugin meta row
 */
function htax_add_plugin_meta_links( $links, $file ) {
    if ( $file === HTAX_PLUGIN_BASENAME ) {
        $row_meta = array(
            'docs' => '<a href="https://wordpress.org/plugins/hypertaxonomy/#description" target="_blank">' . __( 'Documentation', 'hypertaxonomy' ) . '</a>',
            'support' => '<a href="https://wordpress.org/support/plugin/hypertaxonomy/" target="_blank">' . __( 'Support', 'hypertaxonomy' ) . '</a>',
        );
        return array_merge( $links, $row_meta );
    }
    return $links;
}

/**
 * Check version and run upgrades if needed
 */
function htax_check_version() {
    $installed_version = get_option( 'htax_version', '0' );
    if ( version_compare( $installed_version, HTAX_VERSION, '<' ) ) {
        htax_upgrade( $installed_version, HTAX_VERSION );
        update_option( 'htax_version', HTAX_VERSION );
    }
}

/**
 * Run upgrade routines
 */
function htax_upgrade( $old_version, $new_version ) {
    // Future upgrade routines go here
    // Example: if ( version_compare( $old_version, '1.1', '<' ) ) { ... }

    htax_log_debug( 'Plugin upgraded', array(
        'old_version' => $old_version,
        'new_version' => $new_version
    ) );

    // Clear any cached data on upgrade
    wp_cache_flush();
}

/**
 * Check if HyperTaxonomy is enabled for a taxonomy
 */
function htax_is_taxonomy_enabled( $taxonomy ) {
    // Cleared when htax_settings is updated (see htax_clear_settings_request_caches()).
    $cache = &htax_request_cache( 'taxonomy_enabled' );

    if ( isset( $cache[ $taxonomy ] ) ) {
        return $cache[ $taxonomy ];
    }

    // Term Groups taxonomy is always enabled
    if ( 'group' === $taxonomy ) {
        $cache[ $taxonomy ] = true;
        return true;
    }

    // If the taxonomy is not registered (e.g. its plugin is inactive), treat it as
    // not enabled. This preserves all stored data while silently suspending HT
    // features for that taxonomy until it is re-registered.
    if ( ! taxonomy_exists( $taxonomy ) ) {
        $cache[ $taxonomy ] = false;
        return false;
    }

    $options = get_option( 'htax_settings', array() );
    $defaults = array( 'post_tag' => true, 'category' => true );
    $enabled = isset( $options['enabled_taxonomies'] ) ? $options['enabled_taxonomies'] : $defaults;

    $cache[ $taxonomy ] = ! empty( $enabled[ $taxonomy ] );
    return $cache[ $taxonomy ];
}

/**
 * Get post types that should be included in the HyperTaxonomy search index
 *
 * Defaults to 'post' and 'page', matching WordPress's own native search behavior.
 * Configurable via Settings → Search → General → Indexed Post Types.
 *
 * @since 1.0
 * @return string[] Array of post type slugs.
 */
function htax_get_indexed_post_types() {
    // Cleared when htax_settings is updated (see htax_clear_settings_request_caches()).
    $cache = &htax_request_cache( 'indexed_post_types' );

    if ( isset( $cache['types'] ) ) {
        return $cache['types'];
    }

    $options        = get_option( 'htax_settings', array() );
    $cache['types'] = isset( $options['indexed_post_types'] ) && is_array( $options['indexed_post_types'] ) && ! empty( $options['indexed_post_types'] )
        ? $options['indexed_post_types']
        : array( 'post', 'page' );

    return $cache['types'];
}

/**
 * Clear the per-request copies of values read from htax_settings.
 *
 * Runs first on update_option_htax_settings, so handlers later in a settings-save
 * request (index sync, reindex queuing) read the new settings.
 *
 * @since 1.0.0
 */
function htax_clear_settings_request_caches(): void {
    htax_clear_request_cache( 'taxonomy_enabled' );
    htax_clear_request_cache( 'indexed_post_types' );
    htax_clear_request_cache( 'enabled_taxonomies' );
}
add_action( 'update_option_htax_settings', 'htax_clear_settings_request_caches', 0, 0 );

/**
 * Get all available public taxonomies that can be used with HyperTaxonomy
 */
function htax_get_available_taxonomies() {
    $taxonomies = get_taxonomies( array(
        'public' => true,
        'show_ui' => true
    ), 'objects' );

    // Filter out nav_menu, link_category, post_format, etc.
    $excluded = array( 'nav_menu', 'link_category', 'post_format' );
    foreach ( $excluded as $slug ) {
        unset( $taxonomies[ $slug ] );
    }

    // Sort taxonomies: post_tag first, then category, then rest alphabetically
    $sorted = array();

    // Add post_tag first
    if ( isset( $taxonomies['post_tag'] ) ) {
        $sorted['post_tag'] = $taxonomies['post_tag'];
        unset( $taxonomies['post_tag'] );
    }

    // Add category second
    if ( isset( $taxonomies['category'] ) ) {
        $sorted['category'] = $taxonomies['category'];
        unset( $taxonomies['category'] );
    }

    // Add remaining taxonomies alphabetically
    $sorted = array_merge( $sorted, $taxonomies );

    return $sorted;
}

/**
 * Get taxonomy object with metadata
 */
function htax_get_taxonomy_info( $taxonomy ) {
    $tax_obj = get_taxonomy( $taxonomy );
    if ( ! $tax_obj ) {
        return null;
    }

    return array(
        'slug' => $taxonomy,
        'label' => $tax_obj->label,
        'singular_label' => $tax_obj->labels->singular_name,
        'hierarchical' => $tax_obj->hierarchical,
        'object_types' => $tax_obj->object_type,
        'public' => $tax_obj->public,
        'show_ui' => $tax_obj->show_ui,
    );
}

/**
 * Get enabled taxonomies
 */
function htax_get_enabled_taxonomies() {
    // Cache only the configured slugs (the option read), NOT the taxonomy_exists
    // filtering. This function can run before all taxonomies are registered — early
    // on init, or even before init — and a companion plugin's taxonomy (e.g. a
    // hashtag taxonomy registered at init:10) may not exist yet at the first call.
    // Caching the filtered result there would permanently drop that taxonomy for
    // the rest of the request (so its features/subtabs silently never appear), so
    // the registration check is applied per call instead.
    // Cleared when htax_settings is updated (see htax_clear_settings_request_caches()).
    $cache = &htax_request_cache( 'enabled_taxonomies' );

    if ( ! isset( $cache['configured'] ) ) {
        $options             = get_option( 'htax_settings', array() );
        $enabled             = isset( $options['enabled_taxonomies'] ) ? $options['enabled_taxonomies'] : array();
        $cache['configured'] = array_keys( array_filter( $enabled ) );
    }

    // Drop any slugs whose taxonomy is not (yet) registered. Preserves stored data;
    // suspends features until the taxonomy registers.
    $result = array_values( array_filter( $cache['configured'], 'taxonomy_exists' ) );

    // Always include 'group' even when it is not yet registered.
    if ( ! in_array( 'group', $result, true ) ) {
        $result[] = 'group';
    }

    return $result;
}

/**
 * Get taxonomy features configuration
 */
function htax_get_taxonomy_features( $taxonomy ) {
    $options = get_option( 'htax_settings', array() );
    $features = isset( $options['taxonomy_features'][$taxonomy] )
        ? $options['taxonomy_features'][$taxonomy]
        : array();

    // Default all features to true for post_tag and category (existing behavior)
    if ( in_array( $taxonomy, array( 'post_tag', 'category' ), true ) && empty( $features ) ) {
        return array(
            'redirects'          => true,
            'term_relationships' => true,
            'group_assignment'   => true,
            'icons'              => true,
            'visibility'         => true,
            'search_enhancement' => true,
            'customize_archives' => true,
        );
    }

    // For group taxonomy, merge saved features with defaults so any key absent
    // from the DB (e.g. settings saved before the group section existed, or a
    // partial save that left out a key) gets the correct default value.
    if ( 'group' === $taxonomy ) {
        return array_merge(
            array(
                'redirects'          => false,
                'term_relationships' => false,
                'group_assignment'   => false,
                'icons'              => true,
                'visibility'         => false,
                'search_enhancement' => false,
                'customize_archives' => false,
            ),
            $features
        );
    }

    return $features;
}

/**
 * Check if specific feature is enabled for taxonomy
 */
function htax_taxonomy_has_feature( $taxonomy, $feature ) {
    $features = htax_get_taxonomy_features( $taxonomy );
    return isset( $features[$feature] ) && $features[$feature];
}

/**
 * Get cache duration from settings
 */
function htax_get_cache_duration( $type = 'default' ) {
    $options = get_option( 'htax_settings', array() );
    $cache_settings = isset( $options['cache_settings'] ) ? $options['cache_settings'] : array();

    switch ( $type ) {
        case 'term':
            return isset( $cache_settings['term_cache_duration'] ) ?
                   intval( $cache_settings['term_cache_duration'] ) : (2 * HOUR_IN_SECONDS);
        case 'search':
            return isset( $cache_settings['search_cache_duration'] ) ?
                   intval( $cache_settings['search_cache_duration'] ) : (30 * MINUTE_IN_SECONDS);
        case 'autocomplete':
            return isset( $cache_settings['autocomplete_cache_duration'] ) ?
                   intval( $cache_settings['autocomplete_cache_duration'] ) : (5 * MINUTE_IN_SECONDS);
        case 'relationship':
            return isset( $cache_settings['relationship_cache_duration'] ) ?
                   intval( $cache_settings['relationship_cache_duration'] ) : 0;
        case 'sticky':
            return isset( $cache_settings['sticky_cache_duration'] ) ?
                   intval( $cache_settings['sticky_cache_duration'] ) : 0;
        case 'shortcode':
            return isset( $cache_settings['shortcode_cache_duration'] ) ?
                   intval( $cache_settings['shortcode_cache_duration'] ) : 0;
        default:
            return 0;
    }
}

// ============================================================================
// TEXT UTILITIES
// ============================================================================

/**
 * Display debug information on archive pages
 *
 * @since 1.0.0
 */
function htax_debug_info() {
    if ( ! isset( $_GET['htax_debug'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( is_tag() || is_category() || is_tax() ) {
        $term_id = get_queried_object_id();
        $term    = get_queried_object();
        if ( ! $term || ! isset( $term->taxonomy ) ) {
            return;
        }
        $taxonomy = $term->taxonomy;
        // Pass WP_Term object to avoid redundant get_term() call
        $included_terms = htax_get_included_terms( $term );

        echo '<div style="background: #fff; border: 1px solid #ccc; padding: 20px; margin: 20px; font-family: monospace;">';
        echo '<h3>HyperTaxonomy Debug Info</h3>';
        $tax_obj   = get_taxonomy( $taxonomy );
        $tax_label = $tax_obj ? $tax_obj->labels->singular_name : ucfirst( str_replace( '_', ' ', $taxonomy ) );
        echo '<p><strong>Current ' . esc_html( $tax_label ) . ':</strong> ' . esc_html( $term->name ) . ' (ID: ' . esc_html( $term_id ) . ')</p>';
        echo '<p><strong>Included IDs:</strong> ' . ( empty( $included_terms ) ? 'None' : esc_html( implode( ', ', array_keys( $included_terms ) ) ) ) . '</p>';

        if ( ! empty( $included_terms ) ) {
            echo '<p><strong>Included Names:</strong> ';
            $included_names = array();
            foreach ( $included_terms as $included_term ) {
                $included_names[] = $included_term->name;
            }
            echo esc_html( implode( ', ', $included_names ) );
            echo '</p>';
        }

        global $wp_query;

        if ( isset( $wp_query->query_vars['tax_query'] ) && is_array( $wp_query->query_vars['tax_query'] ) ) {
            echo '<p><strong>Tax Query:</strong><pre>' . esc_html( print_r( $wp_query->query_vars['tax_query'], true ) ) . '</pre></p>';
        }

        echo '<p><strong>Posts found:</strong> ' . esc_html( $wp_query->found_posts ) . '</p>';

        $contributing = htax_get_contributing_terms_for_current_page();
        echo '<p><strong>Contributing terms on current page:</strong> ' . ( empty( $contributing ) ? 'None' : esc_html( implode( ', ', $contributing ) ) ) . '</p>';

        // Show sticky posts
        $sticky_posts = htax_get_sticky_posts_for_term( $term_id );
        $sticky_post_ids = ! empty( $sticky_posts ) ? wp_list_pluck( $sticky_posts, 'ID' ) : array();
        echo '<p><strong>Sticky post IDs:</strong> ' . ( empty( $sticky_post_ids ) ? 'None' : esc_html( implode( ', ', $sticky_post_ids ) ) ) . '</p>';

        echo '</div>';
    }
}

// ============================================================================
// META KEY HELPERS
// ============================================================================

/**
 * Get group type suffix for taxonomy
 *
 * Returns the group type suffix used for HyperTaxonomy Term Groups meta keys.
 * Uses the taxonomy name with '_groups' suffix.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy name
 * @return string Group type (e.g., 'tag_groups', 'category_groups', 'product_cat_groups')
 */
function htax_get_group_type( $taxonomy ) {
    return $taxonomy . '_groups';
}

/**
 * Get the current taxonomy from the screen object or request parameter.
 * Returns false if unavailable or not a valid htax taxonomy.
 *
 * @return string|false Taxonomy slug, or false.
 */
function htax_get_current_taxonomy() {
    $current_screen = get_current_screen();
    if ( $current_screen && isset( $current_screen->taxonomy ) ) {
        $taxonomy = $current_screen->taxonomy;
    } elseif ( isset( $_REQUEST['taxonomy'] ) ) {
        $taxonomy = sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) );
    } else {
        return false;
    }
    return htax_is_taxonomy_enabled( $taxonomy ) ? $taxonomy : false;
}

/**
 * Block new post_tag term creation when the "prevent tag creation" setting is on.
 *
 * Fires on pre_insert_term, which is called by wp_insert_term() regardless of
 * whether the caller is the block editor REST API or the classic editor AJAX handler.
 * Registered here (not in settings.php) because settings.php only loads inside
 * is_admin(), but REST API requests are not admin.
 *
 * @param string $term     The term name being inserted.
 * @param string $taxonomy The taxonomy.
 * @return string|WP_Error $term unchanged, or WP_Error to block insertion.
 */
function htax_maybe_prevent_tag_creation( $term, $taxonomy ) {
    if ( 'post_tag' !== $taxonomy ) {
        return $term;
    }

    $options = get_option( 'htax_settings', array() );
    if ( empty( $options['prevent_tag_creation'] ) ) {
        return $term;
    }

    $is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
    $is_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;

    if ( $is_rest || $is_ajax ) {
        // HyperTaxonomy's own import runs as AJAX but must be allowed to create terms.
        if ( $is_ajax && isset( $_REQUEST['action'] ) && 'htax_import' === sanitize_key( $_REQUEST['action'] ) ) {
            return $term;
        }
        return new WP_Error(
            'tag_creation_disabled',
            __( 'Creating new tags is disabled. Please select from existing tags.', 'hypertaxonomy' )
        );
    }

    return $term;
}

