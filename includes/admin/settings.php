<?php
/**
 * HyperTaxonomy Settings Page with Tabs
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

class HyperTaxonomy_Settings {

    /**
     * Singleton instance
     */
    private static $instance = null;

    private static $cached_options = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the module (private constructor for singleton)
     */
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_scripts' ) );

        // Clear cache when options are updated
        add_action( 'update_option_htax_settings', array( $this, 'clear_options_cache' ) );
    }
    
    /**
     * Get default settings
     */
    private function get_defaults() {
        return array(
            // General
            'enabled_taxonomies' => array( 'post_tag' => true, 'category' => true ),
            'taxonomy_features' => array(
                'post_tag' => array(
                    'redirects'          => true,
                    'term_relationships' => true,
                    'group_assignment'   => true,
                    'icons'              => true,
                    'visibility'         => true,
                    'search_enhancement' => true,
                    'customize_archives' => false,
                ),
                'category' => array(
                    'redirects'          => true,
                    'term_relationships' => true,
                    'group_assignment'   => true,
                    'icons'              => true,
                    'visibility'         => true,
                    'search_enhancement' => true,
                    'customize_archives' => false,
                ),
                'group' => array(
                    'redirects'          => false,
                    'term_relationships' => false,
                    'group_assignment'   => false,
                    'icons'              => true,
                    'visibility'         => false,
                    'search_enhancement' => false,
                    'customize_archives' => false,
                ),
            ),
            // Display
            'show_contributing_terms'    => true,
            'contributing_terms_template' => __( ' (including: %s)', 'hypertaxonomy' ),
            'hidden_terms_visibility'    => 'editors',  // Options: 'all', 'editors', 'none'
            'show_archive_description'   => true,   // Global gate for per-term _htax_show_description
            'show_sticky_posts'          => true,   // Global display gate for sticky post archives
            'disable_sticky_styles'      => false,
            'max_sticky_posts'           => 10,     // Maximum number of sticky posts to show on first page of archives
            'expand_secondary_queries' => false,

            // Icons - per-taxonomy settings
            'icon_settings' => array(
                'post_tag' => array(
                    'enabled' => true,
                    'display' => 'when_available', // 'always', 'when_available', 'never'
                    'width' => 96,   // Large size width in pixels (medium and small generated from this)
                    'height' => 96,  // Large size height in pixels
                    'border_radius' => array(
                        'style' => 'rounded',
                        'top_left' => 20,
                        'top_right' => 20,
                        'bottom_left' => 20,
                        'bottom_right' => 20,
                    ),
                ),
                'category' => array(
                    'enabled' => true,
                    'display' => 'when_available',
                    'width' => 24,
                    'height' => 24,
                    'border_radius' => array(
                        'style' => 'rounded',
                        'top_left' => 20,
                        'top_right' => 20,
                        'bottom_left' => 20,
                        'bottom_right' => 20,
                    ),
                ),
            ),

            // Term Links

            // Search
            'indexed_post_types' => array( 'post', 'page' ),
            'always_enhance_search' => false,
            'enhanced_search_orderby' => 'relevance:desc,date:desc',
            'always_load_search_assets' => false,
            'rate_limit' => 30,
            'strength_model' => HTAX_DEFAULT_STRENGTH_MODEL,
            'relevance_multipliers' => array(
                'title' => 1.0,
                'content' => 0.8,
                'excerpt' => 0.7,
                'taxonomies' => array(
                    'post_tag' => array(
                        'direct' => array(
                            'explicit' => 1.0,
                            'text' => 0.6,
                        ),
                        'container' => array(
                            'explicit' => 0.75,
                            'text' => 0.5,
                        ),
                        'redirect' => array(
                            'explicit' => 0.6,
                            'text' => 0.35,
                        ),
                    ),
                    'category' => array(
                        'direct' => array(
                            'explicit' => 1.0,
                            'text' => 0.5,
                        ),
                        'hierarchy' => array(
                            'explicit' => 0.7,
                            'text' => 0.4,
                        ),
                        'container' => array(
                            'explicit' => 0.6,
                            'text' => 0.35,
                        ),
                        'redirect' => array(
                            'explicit' => 0.5,
                            'text' => 0.3,
                        ),
                    ),
                ),
                'custom_taxonomies_text' => array(
                    'flat' => array(
                        'direct' => 0.6,
                        'container' => 0.5,
                    ),
                    'hierarchical' => array(
                        'direct' => 0.5,
                        'hierarchy' => 0.4,
                        'container' => 0.35,
                    ),
                ),
            ),
            
            // Performance
            'cache_settings' => array(
                'search_cache_duration'       => 30 * MINUTE_IN_SECONDS,
                'autocomplete_cache_duration' => 5 * MINUTE_IN_SECONDS,
                'relationship_cache_duration' => 0,
                'sticky_cache_duration'       => 0,
                'shortcode_cache_duration'    => 0,
            ),
            'max_depth' => -1,

            // Shortcodes
            'disable_shortcode_styles' => false,

            'preload_editor_terms'            => true,
            'prevent_tag_creation'            => false,
            'edit_lock_enabled'               => true,
            'delete_on_uninstall'             => false,
            'advertise_ht_presence'           => true,
            'rest_include_relationship_data'  => true,
            'rest_peer_sync_enabled'          => true,
        );
    }
    
    /**
     * Get plugin options with caching
     */
    public function get_options() {
        if ( null === self::$cached_options ) {
            $saved    = get_option( 'htax_settings', array() );
            $defaults = $this->get_defaults();
            $merged   = wp_parse_args( $saved, $defaults );

            // Deep-merge taxonomy_features: apply per-feature defaults for every
            // taxonomy that has defaults, whether or not it already exists in saved
            // data. This ensures e.g. 'group' appears with its defaults even when
            // taxonomy_features['group'] was never written to the DB.
            if ( isset( $defaults['taxonomy_features'] ) ) {
                foreach ( $defaults['taxonomy_features'] as $taxonomy => $default_features ) {
                    $saved_tax = isset( $merged['taxonomy_features'][ $taxonomy ] )
                        ? $merged['taxonomy_features'][ $taxonomy ]
                        : array();
                    $merged['taxonomy_features'][ $taxonomy ] = wp_parse_args(
                        $saved_tax,
                        $default_features
                    );
                }
            }

            // Deep-merge cache_settings so new duration keys added after a site's
            // first install are filled in from defaults rather than silently absent.
            if ( isset( $defaults['cache_settings'] ) ) {
                $merged['cache_settings'] = wp_parse_args(
                    isset( $merged['cache_settings'] ) ? $merged['cache_settings'] : array(),
                    $defaults['cache_settings']
                );
            }

            // Deep-merge icon_settings: per-taxonomy → then border_radius within each.
            if ( isset( $defaults['icon_settings'] ) ) {
                foreach ( $defaults['icon_settings'] as $taxonomy => $default_tax_icons ) {
                    $saved_tax = isset( $merged['icon_settings'][ $taxonomy ] )
                        ? $merged['icon_settings'][ $taxonomy ]
                        : array();
                    $merged_tax = wp_parse_args( $saved_tax, $default_tax_icons );

                    // One more level for border_radius sub-array.
                    if ( isset( $default_tax_icons['border_radius'] ) ) {
                        $merged_tax['border_radius'] = wp_parse_args(
                            isset( $saved_tax['border_radius'] ) ? $saved_tax['border_radius'] : array(),
                            $default_tax_icons['border_radius']
                        );
                    }

                    $merged['icon_settings'][ $taxonomy ] = $merged_tax;
                }
            }

            // Deep-merge relevance_multipliers so new match types (e.g. 'redirect'
            // added after a site's first save) are filled in from defaults.
            if ( isset( $defaults['relevance_multipliers'] ) ) {
                $def_mults  = $defaults['relevance_multipliers'];
                $saved_mults = isset( $merged['relevance_multipliers'] )
                    ? $merged['relevance_multipliers']
                    : array();

                // Merge top-level scalar keys (title, content, excerpt).
                $merged_mults = wp_parse_args( $saved_mults, $def_mults );

                // Deep-merge per-taxonomy match types.
                if ( isset( $def_mults['taxonomies'] ) ) {
                    foreach ( $def_mults['taxonomies'] as $taxonomy => $def_tax ) {
                        $saved_tax   = isset( $saved_mults['taxonomies'][ $taxonomy ] )
                            ? $saved_mults['taxonomies'][ $taxonomy ]
                            : array();
                        $merged_tax  = wp_parse_args( $saved_tax, $def_tax );

                        // Merge explicit/text within each match type.
                        foreach ( $def_tax as $match_type => $def_sources ) {
                            $saved_sources = isset( $saved_tax[ $match_type ] )
                                ? $saved_tax[ $match_type ]
                                : array();
                            $merged_tax[ $match_type ] = wp_parse_args( $saved_sources, $def_sources );
                        }

                        $merged_mults['taxonomies'][ $taxonomy ] = $merged_tax;
                    }
                }

                // Deep-merge custom_taxonomies_text (flat/hierarchical).
                if ( isset( $def_mults['custom_taxonomies_text'] ) ) {
                    foreach ( $def_mults['custom_taxonomies_text'] as $kind => $def_kind ) {
                        $saved_kind = isset( $saved_mults['custom_taxonomies_text'][ $kind ] )
                            ? $saved_mults['custom_taxonomies_text'][ $kind ]
                            : array();
                        $merged_mults['custom_taxonomies_text'][ $kind ] = wp_parse_args( $saved_kind, $def_kind );
                    }
                }

                $merged['relevance_multipliers'] = $merged_mults;
            }

            self::$cached_options = $merged;
        }

        return self::$cached_options;
    }
    
    /**
     * Clear options cache and process any queued icon thumbnail regenerations.
     * Fires on update_option_htax_settings so new settings are already in the DB.
     */
    public function clear_options_cache() {
        self::$cached_options = null;

        // Static flag prevents double-run: WP's register_setting() calls the sanitize
        // callback twice per save (once before update_option, once inside it via
        // sanitize_option filter), and the update_option_htax_settings hook fires a third
        // time. Only the first execution should process the regeneration queue.
        static $regen_ran = false;
        if ( $regen_ran ) {
            return;
        }
        $regen_ran = true;

        $pending = htax_get_transient( 'htax_icon_regen_pending' );
        if ( is_array( $pending ) && ! empty( $pending ) ) {
            htax_delete_transient( 'htax_icon_regen_pending' );
            $icons = HyperTaxonomy_Term_Icons::get_instance();
            foreach ( array_unique( $pending ) as $taxonomy ) {
                $count = $icons->regenerate_thumbnail_sizes( $taxonomy );
                if ( $count > 0 ) {
                    add_settings_error(
                        'htax_settings',
                        'htax_icons_regenerated',
                        sprintf(
                            /* translators: 1: taxonomy slug, 2: count of icons regenerated */
                            __( 'Icon sizes regenerated for %1$s (%2$d icons updated).', 'hypertaxonomy' ),
                            $taxonomy,
                            $count
                        ),
                        'success'
                    );
                }
            }
        }
    }
    
    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page() {
        add_options_page(
            __( 'HyperTaxonomy Settings', 'hypertaxonomy' ),
            __( 'HyperTaxonomy', 'hypertaxonomy' ),
            'manage_options',
            'hypertaxonomy',
            array( $this, 'render_settings_page' )
        );
    }
    
    /**
     * Render settings page with tabs
     */
    public function render_settings_page() {
        // Get current tab
        $valid_tabs = array( 'features', 'display', 'icons', 'search', 'cache', 'export-import', 'advanced' );
        $requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        $active_tab    = in_array( $requested_tab, $valid_tabs, true ) ? $requested_tab : 'features';

        // Define tabs
        $tabs = array(
            'features'          => __( 'Features', 'hypertaxonomy' ),
            'display'           => __( 'Display', 'hypertaxonomy' ),
            'icons'             => __( 'Icons', 'hypertaxonomy' ),
            'search'            => __( 'Search', 'hypertaxonomy' ),
            'cache'             => __( 'Cache', 'hypertaxonomy' ),
            'export-import'     => __( 'Export / Import', 'hypertaxonomy' ),
            'advanced'          => __( 'Advanced', 'hypertaxonomy' ),
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <nav class="nav-tab-wrapper htax-nav-tab-wrapper">
                <?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
                    <a href="?page=hypertaxonomy&tab=<?php echo esc_attr( $tab_key ); ?>" 
                       class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            
            <form method="post" action="options.php" class="htax-settings-form">
                <?php
                settings_fields( 'htax_settings_group' );

                // Hidden fields to track current tab and subtab for redirect after save
                echo '<input type="hidden" name="htax_current_tab" value="' . esc_attr( $active_tab ) . '" />';

                $general_subtab = isset( $_GET['general_subtab'] ) ? sanitize_key( wp_unslash( $_GET['general_subtab'] ) ) : '';
                $search_subtab = isset( $_GET['search_subtab'] ) ? sanitize_key( wp_unslash( $_GET['search_subtab'] ) ) : '';
                $icons_subtab = isset( $_GET['icons_subtab'] ) ? sanitize_key( wp_unslash( $_GET['icons_subtab'] ) ) : '';

                if ( $general_subtab ) {
                    echo '<input type="hidden" name="htax_current_subtab" value="general_subtab=' . esc_attr( $general_subtab ) . '" />';
                } elseif ( $search_subtab ) {
                    echo '<input type="hidden" name="htax_current_subtab" value="search_subtab=' . esc_attr( $search_subtab ) . '" />';
                } elseif ( $icons_subtab ) {
                    echo '<input type="hidden" name="htax_current_subtab" value="icons_subtab=' . esc_attr( $icons_subtab ) . '" />';
                }

                // Render tab content
                echo '<div class="htax-tab-content htax-tab-' . esc_attr( $active_tab ) . '">';

                $has_content = true; // Track if tab has saveable content
                switch ( $active_tab ) {
                    case 'features':
                        $this->render_features_tab();
                        break;
                    case 'display':
                        $this->render_display_tab();
                        break;
                    case 'search':
                        $has_content = $this->render_search_tab();
                        break;
                    case 'icons':
                        $has_content = $this->render_icons_tab();
                        break;
                    case 'cache':
                        $this->render_cache_tab();
                        break;
                    case 'export-import':
                        $has_content = false;
                        $this->render_export_import_tab();
                        break;
                    case 'advanced':
                        $this->render_advanced_tab();
                        break;
                }

                echo '</div>';

                // Only show Save button if there's content to save
                if ( $has_content ) {
                    submit_button();
                }
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render General tab
     */
    private function render_features_tab() {
        $options = $this->get_options();
        $available_taxonomies = htax_get_available_taxonomies();

        // Get current sub-tab - default to 'overview'
        $general_subtab = isset( $_GET['general_subtab'] ) ? sanitize_key( wp_unslash( $_GET['general_subtab'] ) ) : 'overview';

        // Build subtabs list - Overview first, then only enabled taxonomies
        $subtabs = array();
        $subtabs['overview'] = __( 'General', 'hypertaxonomy' );

        // Add Term Groups first (always present as it's built-in and always enabled)
        $subtabs['group'] = __( 'Term Groups', 'hypertaxonomy' );

        // Add Tags if it exists and is enabled
        if ( isset( $available_taxonomies['post_tag'] ) ) {
            $is_enabled = isset( $options['enabled_taxonomies']['post_tag'] ) && $options['enabled_taxonomies']['post_tag'];
            if ( $is_enabled ) {
                $subtabs['post_tag'] = __( 'Tags', 'hypertaxonomy' );
            }
        }

        // Add Categories if it exists and is enabled
        if ( isset( $available_taxonomies['category'] ) ) {
            $is_enabled = isset( $options['enabled_taxonomies']['category'] ) && $options['enabled_taxonomies']['category'];
            if ( $is_enabled ) {
                $subtabs['category'] = __( 'Categories', 'hypertaxonomy' );
            }
        }

        // Add custom taxonomies individually if enabled
        foreach ( $available_taxonomies as $tax_slug => $tax_obj ) {
            if ( ! in_array( $tax_slug, array( 'post_tag', 'category', 'group' ), true ) ) {
                $is_enabled = isset( $options['enabled_taxonomies'][$tax_slug] ) && $options['enabled_taxonomies'][$tax_slug];
                if ( $is_enabled ) {
                    $subtabs[$tax_slug] = $tax_obj->label;
                }
            }
        }
        ?>
        <input type="hidden" name="htax_settings[_general_tab_present]" value="1" />

        <!-- Subtabs Navigation -->
        <ul class="htax-general-subtabs">
            <?php foreach ( $subtabs as $subtab_key => $subtab_label ) : ?>
            <li>
                <a href="?page=hypertaxonomy&tab=features&general_subtab=<?php echo esc_attr( $subtab_key ); ?>"
                   class="<?php echo $general_subtab === $subtab_key ? 'current' : ''; ?>">
                    <?php echo esc_html( $subtab_label ); ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <!-- Subtab Content -->
        <?php
        // Validate that the requested subtab is valid and enabled
        if ( 'overview' === $general_subtab ) {
            $this->render_general_overview( $available_taxonomies );
        } elseif ( isset( $subtabs[$general_subtab] ) ) {
            // Subtab exists and taxonomy is enabled, render it
            $this->render_general_taxonomy_settings( $general_subtab, $available_taxonomies );
        } else {
            // Requested subtab doesn't exist or taxonomy is disabled, redirect to overview
            ?>
            <script type="text/javascript">
                window.location.href = '?page=hypertaxonomy&tab=features&general_subtab=overview';
            </script>
            <?php
        }
        ?>
        <?php
    }

    /**
     * Render General overview with taxonomy enable/disable checklist
     */
    private function render_general_overview( $available_taxonomies ) {
        $options = $this->get_options();
        ?>
        <!-- Hidden field to indicate we're on the overview subtab -->
        <input type="hidden" name="htax_settings[_general_overview_present]" value="1" />

        <!-- Hidden fields to track which taxonomies are being edited in this overview -->
        <?php foreach ( $available_taxonomies as $tax_slug => $tax_obj ) : ?>
            <input type="hidden" name="htax_settings[_editing_taxonomies][]" value="<?php echo esc_attr( $tax_slug ); ?>" />
        <?php endforeach; ?>

        <h3><?php esc_html_e( 'Enable HyperTaxonomy Features', 'hypertaxonomy' ); ?></h3>
        <p><?php esc_html_e( 'Select which taxonomies should have HyperTaxonomy features enabled. Configure specific features for each taxonomy in their individual tabs.', 'hypertaxonomy' ); ?></p>

        <table class="form-table" role="presentation">
            <tbody>
                <?php
                // Term Groups is always enabled — the checkbox below is checked+disabled.
                ?>
                <tr>
                    <th scope="row">
                        <span><?php esc_html_e( 'Term Groups', 'hypertaxonomy' ); ?></span>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="htax_settings[enabled_taxonomies][group]"
                                   value="1"
                                   checked
                                   disabled />
                            <strong><?php esc_html_e( 'Required for HyperTaxonomy features to work in other taxonomies', 'hypertaxonomy' ); ?></strong>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Used by: terms (from other taxonomies)', 'hypertaxonomy' ); ?>
                        </p>
                        <!-- Hidden field to ensure group is always enabled -->
                        <input type="hidden" name="htax_settings[enabled_taxonomies][group]" value="1" />
                    </td>
                </tr>

                <?php
                // Show Tags
                if ( isset( $available_taxonomies['post_tag'] ) ) :
                    $tax_obj = $available_taxonomies['post_tag'];
                    $info = htax_get_taxonomy_info( 'post_tag' );
                    $enabled = isset( $options['enabled_taxonomies']['post_tag'] ) && $options['enabled_taxonomies']['post_tag'];
                ?>
                <tr>
                    <th scope="row">
                        <label for="htax_enable_post_tag"><?php esc_html_e( 'Tags', 'hypertaxonomy' ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   id="htax_enable_post_tag"
                                   class="htax-enable-taxonomy"
                                   name="htax_settings[enabled_taxonomies][post_tag]"
                                   value="1"
                                   data-taxonomy="post_tag"
                                   <?php checked( $enabled ); ?> />
                            <?php esc_html_e( 'Enable HyperTaxonomy features for tags', 'hypertaxonomy' ); ?>
                        </label>
                        <p class="description">
                            <?php
                            if ( $info && ! empty( $info['object_types'] ) ) {
                                printf( esc_html__( 'Used by: %s', 'hypertaxonomy' ), esc_html( implode( ', ', $info['object_types'] ) ) );
                            }
                            ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php
                // Show Categories
                if ( isset( $available_taxonomies['category'] ) ) :
                    $tax_obj = $available_taxonomies['category'];
                    $info = htax_get_taxonomy_info( 'category' );
                    $enabled = isset( $options['enabled_taxonomies']['category'] ) && $options['enabled_taxonomies']['category'];
                ?>
                <tr>
                    <th scope="row">
                        <label for="htax_enable_category"><?php esc_html_e( 'Categories', 'hypertaxonomy' ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   id="htax_enable_category"
                                   class="htax-enable-taxonomy"
                                   name="htax_settings[enabled_taxonomies][category]"
                                   value="1"
                                   data-taxonomy="category"
                                   <?php checked( $enabled ); ?> />
                            <?php esc_html_e( 'Enable HyperTaxonomy features for categories', 'hypertaxonomy' ); ?>
                        </label>
                        <p class="description">
                            <?php
                            if ( $info && ! empty( $info['object_types'] ) ) {
                                printf( esc_html__( 'Used by: %s', 'hypertaxonomy' ), esc_html( implode( ', ', $info['object_types'] ) ) );
                            }
                            ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php
                // Show custom taxonomies
                foreach ( $available_taxonomies as $tax_slug => $tax_obj ) :
                    if ( in_array( $tax_slug, array( 'post_tag', 'category', 'group' ), true ) ) {
                        continue;
                    }
                    $info = htax_get_taxonomy_info( $tax_slug );
                    $enabled = isset( $options['enabled_taxonomies'][$tax_slug] ) && $options['enabled_taxonomies'][$tax_slug];
                    $field_id = 'htax_enable_' . $tax_slug;
                ?>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $tax_obj->label ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   id="<?php echo esc_attr( $field_id ); ?>"
                                   class="htax-enable-taxonomy"
                                   name="htax_settings[enabled_taxonomies][<?php echo esc_attr( $tax_slug ); ?>]"
                                   value="1"
                                   data-taxonomy="<?php echo esc_attr( $tax_slug ); ?>"
                                   <?php checked( $enabled ); ?> />
                            <?php printf( esc_html__( 'Enable HyperTaxonomy features for %s', 'hypertaxonomy' ), esc_html( mb_strtolower( $tax_obj->label, 'UTF-8' ) ) ); ?>
                        </label>
                        <p class="description">
                            <code><?php echo esc_html( $tax_slug ); ?></code>
                            <?php
                            if ( $info ) {
                                if ( ! empty( $info['object_types'] ) ) {
                                    echo ' &bull; ' . esc_html__( 'Used by:', 'hypertaxonomy' ) . ' ' . esc_html( implode( ', ', $info['object_types'] ) );
                                }
                                if ( $info['hierarchical'] ) {
                                    echo ' &bull; ' . esc_html__( 'Hierarchical', 'hypertaxonomy' );
                                }
                            }
                            ?>
                        </p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render general settings for a specific taxonomy (post_tag or category)
     */
    private function render_general_taxonomy_settings( $tax_slug, $available_taxonomies ) {
        $options = $this->get_options();

        // Handle 'group' taxonomy specially (it's not in available_taxonomies from htax_get_available_taxonomies)
        if ( 'group' === $tax_slug ) {
            $tax_obj = get_taxonomy( 'group' );
            if ( ! $tax_obj ) {
                return;
            }
        } else {
            if ( ! isset( $available_taxonomies[$tax_slug] ) ) {
                return;
            }
            $tax_obj = $available_taxonomies[$tax_slug];
        }

        $info = htax_get_taxonomy_info( $tax_slug );
        if ( ! $info ) {
            return;
        }

        $features = isset( $options['taxonomy_features'][$tax_slug] ) ? $options['taxonomy_features'][$tax_slug] : array();

        // Get rewrite slug
        $rewrite_slug = '';
        if ( isset( $tax_obj->rewrite ) && is_array( $tax_obj->rewrite ) && isset( $tax_obj->rewrite['slug'] ) ) {
            $rewrite_slug = $tax_obj->rewrite['slug'];
        }
        ?>

        <!-- Hidden field to track which taxonomy is being edited -->
        <input type="hidden" name="htax_settings[_editing_taxonomy]" value="<?php echo esc_attr( $tax_slug ); ?>" />

        <div class="htax-taxonomy-info" style="background: #f9f9f9; padding: 12px 15px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
            <p style="margin: 0; font-size: 13px; color: #646970;">
                <strong><?php esc_html_e( 'Taxonomy:', 'hypertaxonomy' ); ?></strong>
                <code><?php echo esc_html( $tax_slug ); ?></code>
                <?php if ( $rewrite_slug ) : ?>
                    &nbsp;|&nbsp;
                    <strong><?php esc_html_e( 'URL slug:', 'hypertaxonomy' ); ?></strong>
                    <code><?php echo esc_html( $rewrite_slug ); ?></code>
                <?php endif; ?>
                &nbsp;|&nbsp;
                <strong><?php esc_html_e( 'Used by:', 'hypertaxonomy' ); ?></strong>
                <?php
                if ( empty( $info['object_types'] ) ) {
                    // Special case for Term Groups or other taxonomies with no object types
                    if ( 'group' === $tax_slug ) {
                        esc_html_e( 'Terms (from other taxonomies)', 'hypertaxonomy' );
                    } else {
                        esc_html_e( 'None', 'hypertaxonomy' );
                    }
                } else {
                    echo esc_html( implode( ', ', $info['object_types'] ) );
                }
                ?>
                <?php if ( $info['hierarchical'] ) : ?>
                    &nbsp;|&nbsp;
                    <span class="dashicons dashicons-networking" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px;" title="<?php esc_attr_e( 'Hierarchical', 'hypertaxonomy' ); ?>"></span>
                    <strong><?php esc_html_e( 'Hierarchical', 'hypertaxonomy' ); ?></strong>
                <?php endif; ?>
            </p>
        </div>

        <?php if ( 'group' === $tax_slug ) : ?>
            <!-- Term Groups specific settings -->
            <h3><?php esc_html_e( 'Term Groups Features', 'hypertaxonomy' ); ?></h3>
            <p class="description">
                <?php esc_html_e( 'Used by: terms (from other taxonomies)', 'hypertaxonomy' ); ?>
            </p>
            <p><?php esc_html_e( 'Term Groups is a hierarchical taxonomy provided by this plugin for organizing terms from other taxonomies. It is also used for group inclusion and for container groups functionality - for managing relationships between terms. As such, groups cannot participate in term relationships directly, even though technically groups themselves are also terms. Some other features are not applicable because groups do not apply directly to posts. However, groups do have archive pages, which may be redirected or serve as targets for other redirects, and may have custom icons.', 'hypertaxonomy' ); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Features', 'hypertaxonomy' ); ?>
                    </th>
                    <td>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][group][icons]"
                                   value="1"
                                   <?php checked( isset( $features['icons'] ) && $features['icons'] ); ?> />
                            <?php esc_html_e( 'Enable Term Group icons', 'hypertaxonomy' ); ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php esc_html_e( 'Allow setting custom icons for term groups (used in group archives and in displaying Term Groups if supported by your theme).', 'hypertaxonomy' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   class="htax-enable-redirects"
                                   name="htax_settings[taxonomy_features][group][redirects]"
                                   value="1"
                                   data-taxonomy="group"
                                   <?php checked( isset( $features['redirects'] ) && $features['redirects'] ); ?> />
                            <?php esc_html_e( 'Allow Term Group redirects', 'hypertaxonomy' ); ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php esc_html_e( 'Term Group archive pages can redirect to other term groups\' archives - this allows to consolidate content, reorganize group hierarchies or rename groups without invalidating the original URLs.', 'hypertaxonomy' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][group][visibility]"
                                   value="1"
                                   <?php checked( isset( $features['visibility'] ) && $features['visibility'] ); ?> />
                            <?php esc_html_e( 'Enable Term Group visibility options', 'hypertaxonomy' ); ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php esc_html_e( 'Allow hiding individual groups and their subgroups from public view. Hidden group archives return 404 to unauthorized users and are excluded from REST API responses and sitemaps. Does not affect which posts appear in term archives that include the group — group expansion behavior is unchanged.', 'hypertaxonomy' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][group][customize_archives]"
                                   value="1"
                                   <?php checked( isset( $features['customize_archives'] ) && $features['customize_archives'] ); ?> />
                            <?php esc_html_e( 'Archive customization', 'hypertaxonomy' ); ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php esc_html_e( 'Per-group archive description display, sticky posts, posts per page, and sort order.', 'hypertaxonomy' ); ?>
                            </p>
                        </label>
                    </td>
                </tr>
            </table>

        <?php else : ?>
            <!-- Regular taxonomy settings (Tags, Categories, Custom) -->
            <h3><?php esc_html_e( 'Taxonomy Features', 'hypertaxonomy' ); ?></h3>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Features', 'hypertaxonomy' ); ?>
                    </th>
                    <td>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][<?php echo esc_attr( $tax_slug ); ?>][group_assignment]"
                                   value="1"
                                   data-taxonomy="<?php echo esc_attr( $tax_slug ); ?>"
                                   <?php checked( isset( $features['group_assignment'] ) && $features['group_assignment'] ); ?> />
                            <?php esc_html_e( 'Enable Term Groups assignment', 'hypertaxonomy' ); ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php
                                    $plural = isset( $tax_obj->labels->name ) ? $tax_obj->labels->name : ucfirst( $tax_slug );
                                    printf( esc_html__( 'Assign %s to hierarchical term groups for organizational purposes and for aggregating content into group archives.', 'hypertaxonomy' ), esc_html( mb_strtolower( $plural, 'UTF-8' ) ) );
                                ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][<?php echo esc_attr( $tax_slug ); ?>][term_relationships]"
                                   value="1"
                                   <?php checked( isset( $features['term_relationships'] ) && $features['term_relationships'] ); ?> />
                            <?php
                                $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                printf( esc_html__( 'Enable relationships for %s', 'hypertaxonomy' ), esc_html( $plural ) );
                            ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php
                                    $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                    printf( esc_html__( 'Enable inclusion/container relationships, group-based relationships, and custom relationship types for %s. Allows them to include or be included by other terms and to participate in custom relationships.', 'hypertaxonomy' ), esc_html( $plural ) );
                                ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   class="htax-enable-redirects"
                                   name="htax_settings[taxonomy_features][<?php echo esc_attr( $tax_slug ); ?>][redirects]"
                                   value="1"
                                   data-taxonomy="<?php echo esc_attr( $tax_slug ); ?>"
                                   <?php checked( isset( $features['redirects'] ) && $features['redirects'] ); ?> />
                            <?php
                                $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                printf( esc_html__( 'Allow redirects for %s', 'hypertaxonomy' ), esc_html( $plural ) );
                            ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php
                                    $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                    printf( esc_html__( 'Archive pages for %s can redirect to other terms\' archives - this allows consolidating content, renaming them or converting them to other taxonomy terms without invalidating the original URLs.', 'hypertaxonomy' ), esc_html( $plural ) );
                                ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][<?php echo esc_attr( $tax_slug ); ?>][icons]"
                                   value="1"
                                   <?php checked( isset( $features['icons'] ) && $features['icons'] ); ?> />
                            <?php
                                $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                printf( esc_html__( 'Enable icons for %s', 'hypertaxonomy' ), esc_html( $plural ) );
                            ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php
                                    $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                    printf( esc_html__( 'Allow setting custom icons for %s (used in search widgets, their archives and post meta, if supported by your theme).', 'hypertaxonomy' ), esc_html( $plural ) );
                                ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][<?php echo esc_attr( $tax_slug ); ?>][visibility]"
                                   value="1"
                                   <?php checked( isset( $features['visibility'] ) && $features['visibility'] ); ?> />
                            <?php
                                $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                printf( esc_html__( 'Enable Term visibility options for %s', 'hypertaxonomy' ), esc_html( $plural ) );
                            ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php
                                    $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                    printf( esc_html__( 'Allow hiding specific %s from post meta or show them to editors only.', 'hypertaxonomy' ), esc_html( $plural ) );
                                ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][<?php echo esc_attr( $tax_slug ); ?>][customize_archives]"
                                   value="1"
                                   <?php checked( isset( $features['customize_archives'] ) && $features['customize_archives'] ); ?> />
                            <?php
                                $plural = isset( $tax_obj->labels->name ) ? $tax_obj->labels->name : ucfirst( $tax_slug );
                                printf( esc_html__( 'Enable archive customization for %s', 'hypertaxonomy' ), esc_html( mb_strtolower( $plural, 'UTF-8' ) ) );
                            ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php
                                    $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                    printf( esc_html__( 'Archive description display, sticky posts, posts per page, and sort order — configurable per term for %s.', 'hypertaxonomy' ), esc_html( $plural ) );
                                ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][<?php echo esc_attr( $tax_slug ); ?>][search_enhancement]"
                                   value="1"
                                   <?php checked( isset( $features['search_enhancement'] ) && $features['search_enhancement'] ); ?> />
                            <?php
                                $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                printf( esc_html__( 'Use %s for search enhancement', 'hypertaxonomy' ), esc_html( $plural ) );
                            ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php
                                    $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                    printf( esc_html__( 'Consider %s for relevance-scored search based on configurable weights for various scenarios.', 'hypertaxonomy' ), esc_html( $plural ) );
                                ?>
                            </p>
                        </label>


                        <?php if ( class_exists( 'ATCF_Admin' ) ) : ?>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="htax_settings[taxonomy_features][<?php echo esc_attr( $tax_slug ); ?>][share_locations]"
                                   value="1"
                                   <?php checked( isset( $features['share_locations'] ) && $features['share_locations'] ); ?> />
                            <?php
                                $plural = isset( $tax_obj->labels->name ) ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $tax_slug;
                                printf( esc_html__( 'Share list of locations for %s', 'hypertaxonomy' ), esc_html( $plural ) );
                            ?>
                            <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                <?php esc_html_e( 'Include location data from As the Crow Flies in REST responses for this taxonomy — used by TermConnect peer site matching and proximity-enhanced search.', 'hypertaxonomy' ); ?>
                            </p>
                        </label>
                        <?php endif; ?>

                    </td>
                </tr>

            </table>
        <?php endif; ?>

        <?php
    }

    
    /**
     * Render Display tab
     */
    private function render_display_tab() {
        $options = $this->get_options();
        ?>
        <input type="hidden" name="htax_settings[_display_tab_present]" value="1" />
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Archive Titles', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="htax_settings[show_contributing_terms]" 
                               value="1" 
                               id="htax-show-contributing"
                               <?php checked( ! empty( $options['show_contributing_terms'] ) ); ?> />
                        <?php esc_html_e( 'Show contributing terms in archive titles', 'hypertaxonomy' ); ?>
                    </label>
                    
                    <div id="htax-template-field" style="margin-top: 10px; <?php echo empty( $options['show_contributing_terms'] ) ? 'display:none;' : ''; ?>">
                        <label>
                            <?php esc_html_e( 'Template:', 'hypertaxonomy' ); ?><br>
                            <input type="text" 
                                   name="htax_settings[contributing_terms_template]" 
                                   value="<?php echo esc_attr( $options['contributing_terms_template'] ); ?>" 
                                   class="regular-text" />
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Use %s where the comma-separated list of contributing terms should appear.', 'hypertaxonomy' ); ?>
                        </p>
                    </div>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php esc_html_e( 'Archive Description', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[show_archive_description]"
                               value="1"
                               id="htax-show-archive-description"
                               <?php checked( ! empty( $options['show_archive_description'] ) ); ?> />
                        <?php esc_html_e( 'Show term description on archive pages', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When disabled, no term descriptions are output regardless of per-term preferences; saved per-term data is preserved.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Sticky Posts', 'hypertaxonomy' ); ?></th>
                <td>
                    <?php $show_sticky = ! empty( $options['show_sticky_posts'] ); ?>
                    <label style="display:block;margin-bottom:10px;">
                        <input type="checkbox"
                               name="htax_settings[show_sticky_posts]"
                               value="1"
                               id="htax-show-sticky-posts"
                               <?php checked( $show_sticky ); ?> />
                        <?php esc_html_e( 'Show sticky posts on term archive pages', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description" style="margin-top:0;margin-bottom:12px;">
                        <?php esc_html_e( 'Global on/off gate for sticky post pinning. When disabled, no posts are pinned regardless of per-term or per-post sticky settings; saved data is preserved.', 'hypertaxonomy' ); ?>
                    </p>

                    <div id="htax-sticky-suboptions" style="<?php echo $show_sticky ? '' : 'display:none;'; ?>">
                        <label style="display:block;margin-bottom:12px;">
                            <input type="checkbox"
                                   name="htax_settings[disable_sticky_styles]"
                                   value="1"
                                   <?php checked( ! empty( $options['disable_sticky_styles'] ) ); ?> />
                            <?php esc_html_e( 'Disable default sticky post styles', 'hypertaxonomy' ); ?>
                        </label>
                        <p class="description" style="margin-top:0;margin-bottom:12px;">
                            <?php esc_html_e( 'Check this if your theme provides its own sticky post styling.', 'hypertaxonomy' ); ?>
                        </p>

                        <label style="display:block;margin-bottom:8px;">
                            <?php esc_html_e( 'Maximum sticky posts per archive (first page only)', 'hypertaxonomy' ); ?>
                            <input type="number"
                                   name="htax_settings[max_sticky_posts]"
                                   value="<?php echo esc_attr( isset( $options['max_sticky_posts'] ) ? intval( $options['max_sticky_posts'] ) : 10 ); ?>"
                                   min="1"
                                   max="100"
                                   style="width:80px;margin-left:8px;" />
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Maximum number of sticky posts to display on the first page of term archives. Automatically adjusted down if posts-per-page is lower.', 'hypertaxonomy' ); ?>
                        </p>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Hidden Terms', 'hypertaxonomy' ); ?></th>
                <td>
                    <fieldset>
                        <legend><?php esc_html_e( 'Hidden terms visibility:', 'hypertaxonomy' ); ?></legend>

                        <label>
                            <input type="radio"
                                   name="htax_settings[hidden_terms_visibility]"
                                   value="none"
                                   <?php checked( $options['hidden_terms_visibility'], 'none' ); ?> />
                            <?php esc_html_e( 'Hidden from all users', 'hypertaxonomy' ); ?>
                        </label><br>

                        <label>
                            <input type="radio"
                                   name="htax_settings[hidden_terms_visibility]"
                                   value="editors"
                                   <?php checked( $options['hidden_terms_visibility'], 'editors' ); ?> />
                            <?php esc_html_e( 'Visible to editors and above only', 'hypertaxonomy' ); ?>
                        </label><br>

                        <label>
                            <input type="radio"
                                   name="htax_settings[hidden_terms_visibility]"
                                   value="all"
                                   <?php checked( $options['hidden_terms_visibility'], 'all' ); ?> />
                            <?php esc_html_e( 'Visible to all users', 'hypertaxonomy' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Max Term Hierarchy Depth', 'hypertaxonomy' ); ?></th>
                <td>
                    <input type="number"
                           name="htax_settings[max_depth]"
                           value="<?php echo esc_attr( isset( $options['max_depth'] ) ? $options['max_depth'] : -1 ); ?>"
                           min="-1" max="99" step="1" style="width: 80px;" />
                    <p class="description">
                        <?php esc_html_e( 'Default maximum nesting depth for the [ht_term_list] shortcode, the Term List block, and the search widget\'s hierarchical taxonomy checklists. Each can override this with its own max_depth attribute/setting. -1 = unlimited; 0 = top level only; 1-99 = limit to that many levels.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Frontend Styles', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[disable_shortcode_styles]"
                               value="1"
                               <?php checked( ! empty( $options['disable_shortcode_styles'] ) ); ?> />
                        <?php esc_html_e( 'Disable default frontend styles', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Check this to provide your own CSS for shortcodes and blocks. Stylesheet is at assets/css/htax-shortcodes.css.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>


        </table>
        <script>
        (function() {
            var stickyCheck   = document.getElementById('htax-show-sticky-posts');
            var stickyOptions = document.getElementById('htax-sticky-suboptions');
            if ( stickyCheck && stickyOptions ) {
                stickyCheck.addEventListener('change', function() {
                    stickyOptions.style.display = stickyCheck.checked ? '' : 'none';
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Render Icons tab
     *
     * @return bool True if there's content to save, false if empty state
     */
    private function render_icons_tab() {
        $options = $this->get_options();

        // Get taxonomies with icons feature enabled, using already-loaded $options
        // so defaults (e.g. group icons=true) are applied even before a first save.
        $enabled_taxonomies = htax_get_enabled_taxonomies();
        $icon_taxonomies = array();
        foreach ( $enabled_taxonomies as $tax ) {
            $tax_features = isset( $options['taxonomy_features'][ $tax ] ) ? $options['taxonomy_features'][ $tax ] : array();
            if ( ! empty( $tax_features['icons'] ) ) {
                $icon_taxonomies[] = $tax;
            }
        }

        // Sort: post_tag first, then category, then others
        $sorted_taxonomies = array();
        if ( in_array( 'post_tag', $icon_taxonomies, true ) ) {
            $sorted_taxonomies[] = 'post_tag';
        }
        if ( in_array( 'category', $icon_taxonomies, true ) ) {
            $sorted_taxonomies[] = 'category';
        }
        foreach ( $icon_taxonomies as $tax ) {
            if ( ! in_array( $tax, $sorted_taxonomies, true ) ) {
                $sorted_taxonomies[] = $tax;
            }
        }

        // Get current sub-tab (default to 'general')
        $icons_subtab = isset( $_GET['icons_subtab'] ) ? sanitize_key( wp_unslash( $_GET['icons_subtab'] ) ) : 'general';

        ?>
        <input type="hidden" name="htax_settings[_icons_tab_present]" value="1" />

        <?php if ( empty( $sorted_taxonomies ) ) : ?>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php esc_html_e( 'No taxonomies with icons feature enabled.', 'hypertaxonomy' ); ?></strong>
                </p>
                <p>
                    <?php
                    printf(
                        /* translators: %s: link to Features tab */
                        esc_html__( 'To configure icon settings, first enable the "Icons" feature for a taxonomy in the %s.', 'hypertaxonomy' ),
                        '<a href="?page=hypertaxonomy&tab=features">' . esc_html__( 'Features', 'hypertaxonomy' ) . '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php
            return false; // No content to save
            ?>
        <?php else : ?>
        <ul class="htax-icons-subtabs">
            <!-- General subtab first -->
            <li>
                <a href="?page=hypertaxonomy&tab=icons&icons_subtab=general"
                   class="<?php echo 'general' === $icons_subtab ? 'current' : ''; ?>">
                    <?php esc_html_e( 'General', 'hypertaxonomy' ); ?>
                </a>
            </li>
            <?php foreach ( $sorted_taxonomies as $taxonomy ) :
                $tax_obj = get_taxonomy( $taxonomy );
                if ( ! $tax_obj ) continue;
            ?>
            <li>
                <a href="?page=hypertaxonomy&tab=icons&icons_subtab=<?php echo esc_attr( $taxonomy ); ?>"
                   class="<?php echo $icons_subtab === $taxonomy ? 'current' : ''; ?>">
                    <?php echo esc_html( $tax_obj->labels->name ); ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ( 'general' === $icons_subtab ) : ?>
            <!-- General subtab content -->
            <h3><?php esc_html_e( 'Theme Integration', 'hypertaxonomy' ); ?></h3>
            <p><?php esc_html_e( 'To display term icons in your theme, you need to add code to your theme files. Below are code examples you can copy and paste into your theme.', 'hypertaxonomy' ); ?></p>

            <h4><?php esc_html_e( 'Display Terms as Styled Pills', 'hypertaxonomy' ); ?></h4>
            <p><?php esc_html_e( 'Add this code to your single.php, archive.php, or wherever you want to display terms as styled pills with icons:', 'hypertaxonomy' ); ?></p>
            <pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto; font-size: 13px; line-height: 1.5;"><code>&lt;?php
// Display tags as pills
if ( function_exists( 'htax_the_term_pills' ) ) {
    htax_the_term_pills( get_the_ID(), 'post_tag', array(
        'before' => '&lt;div class="post-tags"&gt;',
        'after' => '&lt;/div&gt;',
        'separator' => ' ',
        'show_placeholder' => false, // Set to true to show icon-shaped color placeholder when no icon
    ) );
}

// Display categories as pills
if ( function_exists( 'htax_the_term_pills' ) ) {
    htax_the_term_pills( get_the_ID(), 'category', array(
        'before' => '&lt;div class="post-categories"&gt;',
        'after' => '&lt;/div&gt;',
        'separator' => ' ',
        'show_placeholder' => false,
    ) );
}
?&gt;</code></pre>

            <h4><?php esc_html_e( 'Display Icons in Term Archives', 'hypertaxonomy' ); ?></h4>
            <p><?php esc_html_e( 'Add this code to your taxonomy.php or archive.php to display the icon for the current term:', 'hypertaxonomy' ); ?></p>
            <pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto; font-size: 13px; line-height: 1.5;"><code>&lt;?php
// Display icon for current term archive (large size by default)
if ( function_exists( 'htax_the_archive_icon' ) ) {
    echo '&lt;div class="archive-header-icon"&gt;';
    htax_the_archive_icon( 'large', array( 'class' => 'term-archive-icon' ) );
    echo '&lt;/div&gt;';
}
?&gt;</code></pre>

            <h4><?php esc_html_e( 'Available Functions', 'hypertaxonomy' ); ?></h4>
            <ul style="list-style-type: disc; margin-left: 2em;">
                <li><code>htax_the_term_pills( $post_id, $taxonomy, $args )</code> - <?php esc_html_e( 'Output all post terms as styled pills with icons', 'hypertaxonomy' ); ?></li>
                <li><code>htax_get_term_pill( $term, $args )</code> - <?php esc_html_e( 'Get HTML for a single term as a styled pill', 'hypertaxonomy' ); ?></li>
                <li><code>htax_the_archive_icon( $size, $attr )</code> - <?php esc_html_e( 'Output the icon for the current term archive page', 'hypertaxonomy' ); ?></li>
                <li><code>htax_get_the_archive_icon( $size, $attr )</code> - <?php esc_html_e( 'Get the icon HTML for the current term archive page', 'hypertaxonomy' ); ?></li>
                <li><code>htax_get_term_icon_html( $term, $size, $attr )</code> - <?php esc_html_e( 'Get the icon HTML for any specific term', 'hypertaxonomy' ); ?></li>
                <li><code>htax_add_icon_to_term_link( $term_link, $term, $show_icons )</code> - <?php esc_html_e( 'Transform an existing term link into a styled pill', 'hypertaxonomy' ); ?></li>
            </ul>

            <h4><?php esc_html_e( 'Styling Pills', 'hypertaxonomy' ); ?></h4>
            <p><?php esc_html_e( 'Pills automatically inherit border-radius from your icon settings. The border-radius applies to both the icon and the pill container, creating a cohesive visual style. Icons and placeholders have a 1px border that matches their shape.', 'hypertaxonomy' ); ?></p>

        <?php elseif ( in_array( $icons_subtab, $sorted_taxonomies, true ) ) :
            // Per-taxonomy icon settings
            $taxonomy = $icons_subtab;
            $tax_obj = get_taxonomy( $taxonomy );
            if ( ! $tax_obj ) {
                echo '<p>' . esc_html__( 'Invalid taxonomy.', 'hypertaxonomy' ) . '</p>';
                return;
            }

            // Get current settings for this taxonomy
            $icon_settings = isset( $options['icon_settings'][$taxonomy] )
                ? $options['icon_settings'][$taxonomy]
                : array(
                    'enabled' => true,
                    'display' => 'when_available',
                    'border_radius' => array(
                        'style' => 'rounded',
                        'top_left' => 20,
                        'top_right' => 20,
                        'bottom_left' => 20,
                        'bottom_right' => 20,
                    ),
                );

            $enabled    = isset( $icon_settings['enabled'] ) ? $icon_settings['enabled'] : true;
            $display    = isset( $icon_settings['display'] ) ? $icon_settings['display'] : 'when_available';
            $width      = isset( $icon_settings['width'] ) ? $icon_settings['width'] : 96;
            $height     = isset( $icon_settings['height'] ) ? $icon_settings['height'] : 96;
            $small_pct  = isset( $icon_settings['small_pct'] ) ? $icon_settings['small_pct'] : 25;
            $medium_pct = isset( $icon_settings['medium_pct'] ) ? $icon_settings['medium_pct'] : 50;
            $border_radius = isset( $icon_settings['border_radius'] ) ? $icon_settings['border_radius'] : array(
                'style' => 'rounded',
                'top_left' => 20,
                'top_right' => 20,
                'bottom_left' => 20,
                'bottom_right' => 20,
            );
            ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Icon Uploads', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][enabled]"
                               value="1"
                               <?php checked( $enabled ); ?> />
                        <?php esc_html_e( 'Enable icon uploads', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php printf(
                            esc_html__( 'Allow uploading custom icons for %s.', 'hypertaxonomy' ),
                            mb_strtolower( esc_html( $tax_obj->labels->name ), 'UTF-8' )
                        ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Icon Dimensions', 'hypertaxonomy' ); ?></th>
                <td>
                    <div style="margin-bottom: 20px;">
                        <label for="htax-icon-width-<?php echo esc_attr( $taxonomy ); ?>" style="display: inline-block; width: 100px;">
                            <?php esc_html_e( 'Width (px):', 'hypertaxonomy' ); ?>
                        </label>
                        <input type="number"
                               id="htax-icon-width-<?php echo esc_attr( $taxonomy ); ?>"
                               name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][width]"
                               value="<?php echo esc_attr( $width ); ?>"
                               min="16"
                               max="512"
                               style="width: 80px;" />
                        <span style="margin-left: 20px; color: #666;">
                            <?php esc_html_e( 'Min: 16px, Max: 512px', 'hypertaxonomy' ); ?>
                        </span>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label for="htax-icon-height-<?php echo esc_attr( $taxonomy ); ?>" style="display: inline-block; width: 100px;">
                            <?php esc_html_e( 'Height (px):', 'hypertaxonomy' ); ?>
                        </label>
                        <input type="number"
                               id="htax-icon-height-<?php echo esc_attr( $taxonomy ); ?>"
                               name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][height]"
                               value="<?php echo esc_attr( $height ); ?>"
                               min="16"
                               max="512"
                               style="width: 80px;" />
                        <span style="margin-left: 20px; color: #666;">
                            <?php esc_html_e( 'Min: 16px, Max: 512px', 'hypertaxonomy' ); ?>
                        </span>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label for="htax-icon-medium-pct-<?php echo esc_attr( $taxonomy ); ?>" style="display: inline-block; width: 150px;">
                            <?php esc_html_e( 'Medium size (%):', 'hypertaxonomy' ); ?>
                        </label>
                        <input type="number"
                               id="htax-icon-medium-pct-<?php echo esc_attr( $taxonomy ); ?>"
                               name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][medium_pct]"
                               value="<?php echo esc_attr( $medium_pct ); ?>"
                               min="1"
                               max="99"
                               style="width: 60px;" />
                        <span style="margin-left: 8px; color: #666;"><?php esc_html_e( '% of full size — for lists', 'hypertaxonomy' ); ?></span>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label for="htax-icon-small-pct-<?php echo esc_attr( $taxonomy ); ?>" style="display: inline-block; width: 150px;">
                            <?php esc_html_e( 'Small size (%):', 'hypertaxonomy' ); ?>
                        </label>
                        <input type="number"
                               id="htax-icon-small-pct-<?php echo esc_attr( $taxonomy ); ?>"
                               name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][small_pct]"
                               value="<?php echo esc_attr( $small_pct ); ?>"
                               min="1"
                               max="99"
                               style="width: 60px;" />
                        <span style="margin-left: 8px; color: #666;"><?php esc_html_e( '% of full size — for pills and inline display', 'hypertaxonomy' ); ?></span>
                    </div>

                    <div style="background: #fff3cd; border-left: 4px solid #ffb900; padding: 12px; margin-top: 15px;">
                        <p style="margin: 0; font-weight: 600; color: #856404;">
                            ⚠ <?php esc_html_e( 'Warning:', 'hypertaxonomy' ); ?>
                        </p>
                        <p style="margin: 8px 0 0 0; color: #856404;">
                            <?php esc_html_e( 'Changing icon dimensions will clear all previously assigned icons for this taxonomy. Image files will be preserved in the media library, but you will need to re-assign them to terms. Changing medium or small percentages will regenerate those sizes from the existing uploaded icons.', 'hypertaxonomy' ); ?>
                        </p>
                    </div>

                    <p class="description" style="margin-top: 15px;">
                        <?php esc_html_e( 'Upload icons at exactly the full size dimensions shown above (larger images would be cropped). Medium and small sizes are generated automatically at the configured percentages.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Corner Style', 'hypertaxonomy' ); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">
                            <span><?php esc_html_e( 'Icon corner style', 'hypertaxonomy' ); ?></span>
                        </legend>

                        <?php
                        $corner_styles = array(
                            'square'        => __( 'Square (sharp corners)', 'hypertaxonomy' ),
                            'rounded'       => __( 'Rounded (4px radius)', 'hypertaxonomy' ),
                            'extra_rounded' => __( 'Extra Rounded (8px radius)', 'hypertaxonomy' ),
                            'circular'      => __( 'Circular (50% radius)', 'hypertaxonomy' ),
                            'custom'        => __( 'Custom (specify each corner)', 'hypertaxonomy' ),
                        );
                        foreach ( $corner_styles as $style_value => $style_label ) :
                            $style_mb = 'custom' === $style_value ? '12px' : '8px';
                        ?>
                        <label style="display: block; margin-bottom: <?php echo esc_attr( $style_mb ); ?>;">
                            <input type="radio"
                                   name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][border_radius][style]"
                                   value="<?php echo esc_attr( $style_value ); ?>"
                                   class="htax-border-style"
                                   <?php checked( $border_radius['style'], $style_value ); ?> />
                            <?php echo esc_html( $style_label ); ?>
                        </label>
                        <?php endforeach; ?>

                        <div id="htax-custom-corners" style="margin-left: 24px; <?php echo 'custom' !== $border_radius['style'] ? 'display:none;' : ''; ?>">
                            <?php
                            $corner_fields = array(
                                'top_left'     => array( 'label' => __( 'Top Left:', 'hypertaxonomy' ),    'id' => 'tl' ),
                                'top_right'    => array( 'label' => __( 'Top Right:', 'hypertaxonomy' ),   'id' => 'tr' ),
                                'bottom_left'  => array( 'label' => __( 'Bottom Left:', 'hypertaxonomy' ),  'id' => 'bl' ),
                                'bottom_right' => array( 'label' => __( 'Bottom Right:', 'hypertaxonomy' ), 'id' => 'br' ),
                            );
                            ?>
                            <table class="htax-corners-table">
                                <?php foreach ( $corner_fields as $corner_key => $corner ) : ?>
                                <tr>
                                    <td style="padding: 8px 12px 8px 0;">
                                        <label for="htax-corner-<?php echo esc_attr( $corner['id'] ); ?>">
                                            <?php echo esc_html( $corner['label'] ); ?>
                                        </label>
                                    </td>
                                    <td style="padding: 8px 0;">
                                        <input type="range"
                                               id="htax-corner-<?php echo esc_attr( $corner['id'] ); ?>"
                                               name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][border_radius][<?php echo esc_attr( $corner_key ); ?>]"
                                               min="0"
                                               max="100"
                                               value="<?php echo esc_attr( $border_radius[ $corner_key ] ); ?>"
                                               class="htax-corner-slider"
                                               style="width: 200px; vertical-align: middle;" />
                                        <span class="htax-corner-value" style="margin-left: 12px; min-width: 40px; display: inline-block;">
                                            <?php echo esc_html( $border_radius[ $corner_key ] ); ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                            <p class="description" style="margin-top: 8px;">
                                <?php esc_html_e( '0% = sharp corner, 100% = fully rounded (quarter circle)', 'hypertaxonomy' ); ?>
                            </p>
                        </div>

                        <?php
                        // Calculate preview sizes based on actual configured dimensions
                        // Small: 0.5x, Medium: 1x (actual), Large: 2x
                        // Preserves aspect ratio - no artificial minimums/maximums

                        $small_width  = (int) round( $width  * $small_pct  / 100 );
                        $small_height = (int) round( $height * $small_pct  / 100 );
                        $medium_width  = (int) round( $width  * $medium_pct / 100 );
                        $medium_height = (int) round( $height * $medium_pct / 100 );
                        $preview_br = htax_resolve_border_radius_css( $border_radius );
                        ?>
                        <div id="htax-icon-preview-box" style="margin-top: 20px; padding: 20px; background: #f5f5f5; border-radius: 4px; display: inline-block;">
                            <p style="margin: 0 0 12px 0; font-weight: 600;">
                                <?php esc_html_e( 'Preview:', 'hypertaxonomy' ); ?>
                            </p>
                            <div style="display: flex; gap: 20px; align-items: center;">
                                <div>
                                    <div class="htax-preview-icon htax-preview-small" style="width: <?php echo esc_attr( $small_width ); ?>px; height: <?php echo esc_attr( $small_height ); ?>px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: <?php echo esc_attr( $preview_br ); ?>;"></div>
                                    <p style="margin: 8px 0 0 0; font-size: 11px; color: #666;"><?php printf( esc_html__( 'Small (%d×%d)', 'hypertaxonomy' ), $small_width, $small_height ); ?></p>
                                </div>
                                <div>
                                    <div class="htax-preview-icon htax-preview-medium" style="width: <?php echo esc_attr( $medium_width ); ?>px; height: <?php echo esc_attr( $medium_height ); ?>px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: <?php echo esc_attr( $preview_br ); ?>;"></div>
                                    <p style="margin: 8px 0 0 0; font-size: 11px; color: #666;"><?php printf( esc_html__( 'Medium (%d×%d)', 'hypertaxonomy' ), $medium_width, $medium_height ); ?></p>
                                </div>
                                <div>
                                    <div class="htax-preview-icon htax-preview-large" style="width: <?php echo esc_attr( $width ); ?>px; height: <?php echo esc_attr( $height ); ?>px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: <?php echo esc_attr( $preview_br ); ?>;"></div>
                                    <p style="margin: 8px 0 0 0; font-size: 11px; color: #666;"><?php printf( esc_html__( 'Full (%d×%d)', 'hypertaxonomy' ), $width, $height ); ?></p>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Icon Display', 'hypertaxonomy' ); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">
                            <span><?php esc_html_e( 'When to display icons', 'hypertaxonomy' ); ?></span>
                        </legend>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio"
                                   name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][display]"
                                   value="always"
                                   <?php checked( $display, 'always' ); ?> />
                            <?php esc_html_e( 'Always show placeholders', 'hypertaxonomy' ); ?>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio"
                                   name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][display]"
                                   value="when_available"
                                   <?php checked( $display, 'when_available' ); ?> />
                            <?php esc_html_e( 'Only when icon exists', 'hypertaxonomy' ); ?>
                        </label>

                        <label style="display: block; margin-bottom: 12px;">
                            <input type="radio"
                                   name="htax_settings[icon_settings][<?php echo esc_attr( $icons_subtab ); ?>][display]"
                                   value="never"
                                   <?php checked( $display, 'never' ); ?> />
                            <?php esc_html_e( 'Never show icons', 'hypertaxonomy' ); ?>
                        </label>
                    </fieldset>

                    <p class="description">
                        <?php esc_html_e( 'Widget shortcodes can override these settings using:', 'hypertaxonomy' ); ?>
                        <br><code>show_icons="always"</code>
                    </p>

                    <div style="margin-top: 15px;">
                        <strong><?php esc_html_e( 'Color Preview:', 'hypertaxonomy' ); ?></strong>
                        <div style="display: flex; gap: 8px; margin-top: 10px; align-items: center;">
                            <?php
                            $colors = array( '#e53935', '#1e88e5', '#43a047', '#fdd835', '#6d4c41' );
                            foreach ( $colors as $color ) :
                            ?>
                                <div class="htax-placeholder-square" style="width: <?php echo esc_attr( $small_width ); ?>px; height: <?php echo esc_attr( $small_height ); ?>px; background: <?php echo esc_attr( $color ); ?>; border-radius: <?php echo esc_attr( $preview_br ); ?>;"></div>
                            <?php endforeach; ?>
                            <span style="line-height: <?php echo esc_attr( $small_height ); ?>px; margin-left: 5px;">
                                <?php
                                $more = max( 0, HyperTaxonomy_Term_Icons::get_palette_size() - count( $colors ) );
                                printf(
                                    /* translators: %d: number of additional placeholder colors */
                                    esc_html( _n( '+ %d more color', '+ %d more colors', $more, 'hypertaxonomy' ) ),
                                    (int) $more
                                );
                                ?>
                            </span>
                        </div>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <?php esc_html_e( 'Delete All Icons', 'hypertaxonomy' ); ?>
                </th>
                <td>
                    <button type="button"
                            class="button button-secondary htax-delete-all-icons"
                            data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
                            data-taxonomy-label="<?php echo esc_attr( $tax_obj->labels->name ); ?>">
                        <?php esc_html_e( 'Delete All Icons for This Taxonomy', 'hypertaxonomy' ); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e( 'Permanently delete all uploaded icons for this taxonomy. This action cannot be undone.', 'hypertaxonomy' ); ?>
                    </p>
                    <div class="htax-delete-icons-status" style="margin-top: 10px;"></div>
                </td>
            </tr>
        </table>

        <?php else : // Requested subtab doesn't have icons enabled or doesn't exist ?>
            <script type="text/javascript">
                // Redirect to first available taxonomy with icons or back to general tab
                <?php if ( ! empty( $sorted_taxonomies ) ) : ?>
                window.location.href = '?page=hypertaxonomy&tab=icons&icons_subtab=<?php echo esc_js( $sorted_taxonomies[0] ); ?>';
                <?php else : ?>
                window.location.href = '?page=hypertaxonomy&tab=features';
                <?php endif; ?>
            </script>
        <?php endif; // End of icon settings conditional ?>

        <?php
        return 'general' !== $icons_subtab; // General subtab is informational only — no save needed
    }

    /**
     * Render Performance tab
     */
    private function render_search_tab() {
        $options = $this->get_options();

        // Get current sub-tab
        $search_subtab = isset( $_GET['search_subtab'] ) ? sanitize_key( wp_unslash( $_GET['search_subtab'] ) ) : 'general';

        // Get taxonomies with search enhancement enabled
        $search_taxonomies = htax_get_enabled_taxonomies_with_search();

        // Check if we have custom taxonomies with search enhancement
        $has_custom_flat_search = false;
        $has_custom_hierarchical_search = false;
        foreach ( $search_taxonomies as $taxonomy ) {
            if ( ! in_array( $taxonomy, array( 'post_tag', 'category' ), true ) ) {
                if ( is_taxonomy_hierarchical( $taxonomy ) ) {
                    $has_custom_hierarchical_search = true;
                } else {
                    $has_custom_flat_search = true;
                }
            }
        }
        ?>
        <input type="hidden" name="htax_settings[_search_tab_present]" value="1" />

        <?php if ( empty( $search_taxonomies ) ) : ?>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php esc_html_e( 'No taxonomies with search enhancement enabled.', 'hypertaxonomy' ); ?></strong>
                </p>
                <p>
                    <?php
                    printf(
                        /* translators: %s: link to Features tab */
                        esc_html__( 'To configure search settings, first enable the "Search enhancement" feature for a taxonomy in the %s.', 'hypertaxonomy' ),
                        '<a href="?page=hypertaxonomy&tab=features">' . esc_html__( 'Features', 'hypertaxonomy' ) . '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php
            return false; // No content to save
            ?>
        <?php else : ?>
        <ul class="htax-search-subtabs">
            <li>
                <a href="?page=hypertaxonomy&tab=search&search_subtab=general"
                   class="<?php echo 'general' === $search_subtab ? 'current' : ''; ?>">
                    <?php esc_html_e( 'General', 'hypertaxonomy' ); ?>
                </a>
            </li>
            <?php foreach ( $search_taxonomies as $taxonomy ) :
                $tax_obj = get_taxonomy( $taxonomy );
                if ( ! $tax_obj ) continue;
            ?>
            <li>
                <a href="?page=hypertaxonomy&tab=search&search_subtab=<?php echo esc_attr( $taxonomy ); ?>"
                   class="<?php echo $search_subtab === $taxonomy ? 'current' : ''; ?>">
                    <?php echo esc_html( $tax_obj->labels->name ); ?>
                </a>
            </li>
            <?php endforeach; ?>
            <?php if ( $has_custom_flat_search ) : ?>
            <li>
                <a href="?page=hypertaxonomy&tab=search&search_subtab=custom_flat"
                   class="<?php echo 'custom_flat' === $search_subtab ? 'current' : ''; ?>">
                    <?php esc_html_e( 'Custom (Flat)', 'hypertaxonomy' ); ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if ( $has_custom_hierarchical_search ) : ?>
            <li>
                <a href="?page=hypertaxonomy&tab=search&search_subtab=custom_hierarchical"
                   class="<?php echo 'custom_hierarchical' === $search_subtab ? 'current' : ''; ?>">
                    <?php esc_html_e( 'Custom (Hierarchical)', 'hypertaxonomy' ); ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>

        <?php if ( 'general' === $search_subtab ) : ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Indexed Post Types', 'hypertaxonomy' ); ?></th>
                <td>
                    <?php
                    $indexed     = isset( $options['indexed_post_types'] ) ? (array) $options['indexed_post_types'] : array( 'post', 'page' );
                    $post_types  = get_post_types( array( 'public' => true ), 'objects' );
                    $skip_types  = array( 'attachment' );
                    foreach ( $post_types as $pt_slug => $pt_obj ) :
                        if ( in_array( $pt_slug, $skip_types, true ) ) {
                            continue;
                        }
                        ?>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox"
                                   name="htax_settings[indexed_post_types][]"
                                   value="<?php echo esc_attr( $pt_slug ); ?>"
                                   <?php checked( in_array( $pt_slug, $indexed, true ) ); ?> />
                            <?php echo esc_html( $pt_obj->labels->singular_name ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php esc_html_e( 'Post types to include in the HyperTaxonomy search index and term archive features. Defaults to Posts and Pages, matching WordPress native search behavior.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Site Search Integration', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[always_enhance_search]"
                               value="1"
                               <?php checked( ! empty( $options['always_enhance_search'] ) ); ?> />
                        <?php esc_html_e( 'Use PowerSearch for WordPress site search', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Replaces WordPress\'s default search (/?s=…) with the PowerSearch engine on all site searches. Applies relevance scoring, term relationship expansion, and field operators automatically. Disable if using Relevanssi, SearchWP, or another dedicated search plugin for the /?s= role.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Default Match Mode', 'hypertaxonomy' ); ?></th>
                <td>
                    <?php $default_match = isset( $options['default_match_mode'] ) ? $options['default_match_mode'] : 'any'; ?>
                    <label style="margin-right: 16px;">
                        <input type="radio"
                               name="htax_settings[default_match_mode]"
                               value="any"
                               <?php checked( $default_match, 'any' ); ?> />
                        <?php esc_html_e( 'Any (OR) — posts matching at least one term are returned; more matches rank higher', 'hypertaxonomy' ); ?>
                    </label><br>
                    <label>
                        <input type="radio"
                               name="htax_settings[default_match_mode]"
                               value="all"
                               <?php checked( $default_match, 'all' ); ?> />
                        <?php esc_html_e( 'All (AND) — posts must contain every search term; consistent with native WordPress search behavior', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Applies to native WordPress search and to the PowerSearch shortcode when no match= attribute is set. In All mode the + operator is redundant (every term is already required); only - exclusions and quoted phrases add behavior beyond a plain search.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Term Relationship Expansion', 'hypertaxonomy' ); ?></th>
                <td>
                    <?php
                    $expand_types = isset( $options['search_expand_types'] )
                        ? (array) $options['search_expand_types']
                        : array( 'container', 'hierarchy', 'redirect_target' );
                    ?>
                    <label style="display: block; margin-bottom: 4px;">
                        <input type="checkbox"
                               name="htax_settings[search_expand_types][]"
                               value="container"
                               <?php checked( in_array( 'container', $expand_types, true ) ); ?> />
                        <?php esc_html_e( 'Container (posts tagged with included terms)', 'hypertaxonomy' ); ?>
                    </label>
                    <label style="display: block; margin-bottom: 4px;">
                        <input type="checkbox"
                               name="htax_settings[search_expand_types][]"
                               value="hierarchy"
                               <?php checked( in_array( 'hierarchy', $expand_types, true ) ); ?> />
                        <?php esc_html_e( 'Hierarchy (posts tagged with child terms)', 'hypertaxonomy' ); ?>
                    </label>
                    <label style="display: block; margin-bottom: 4px;">
                        <input type="checkbox"
                               name="htax_settings[search_expand_types][]"
                               value="redirect_target"
                               <?php checked( in_array( 'redirect_target', $expand_types, true ) ); ?> />
                        <?php esc_html_e( 'Redirects (posts reachable via redirect terms)', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Which relationship types are included when expanding a search term beyond its direct posts. Direct matches are always included. These defaults apply to PowerSearch and search widgets; individual field shortcodes can override with the expand= attribute.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Search Result Order', 'hypertaxonomy' ); ?></th>
                <td>
                    <input type="text"
                           name="htax_settings[enhanced_search_orderby]"
                           value="<?php echo esc_attr( ! empty( $options['enhanced_search_orderby'] ) ? $options['enhanced_search_orderby'] : 'relevance:desc,date:desc' ); ?>"
                           class="regular-text"
                           placeholder="relevance:desc,date:desc" />
                    <p class="description">
                        <?php esc_html_e( 'Default sort order for enhanced search: relevance:desc,date:desc', 'hypertaxonomy' ); ?><br/>
                        <?php esc_html_e( 'Valid fields: relevance, date, modified, title, author, comment_count, last_comment, last_activity, ID', 'hypertaxonomy' ); ?><br/>
                        <?php esc_html_e( 'Valid orders: asc, desc (defaults to desc if omitted)', 'hypertaxonomy' ); ?><br/>
                        <?php esc_html_e( 'Examples: "date:desc,title:asc", "comment_count:desc", "modified:desc"', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>


            <tr>
                <th scope="row"><?php esc_html_e( 'Content Relevance Weights', 'hypertaxonomy' ); ?></th>
                <td>
                    <table class="htax-relevance-table">
                        <tr>
                            <td><?php esc_html_e( 'Title match:', 'hypertaxonomy' ); ?></td>
                            <td>
                                <input type="number"
                                       name="htax_settings[relevance_multipliers][title]"
                                       value="<?php echo esc_attr( isset( $options['relevance_multipliers']['title'] ) ? $options['relevance_multipliers']['title'] : 1.0 ); ?>"
                                       min="0" max="1" step="0.05" style="width: 60px;" />
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Content match:', 'hypertaxonomy' ); ?></td>
                            <td>
                                <input type="number"
                                       name="htax_settings[relevance_multipliers][content]"
                                       value="<?php echo esc_attr( isset( $options['relevance_multipliers']['content'] ) ? $options['relevance_multipliers']['content'] : 0.8 ); ?>"
                                       min="0" max="1" step="0.05" style="width: 60px;" />
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Excerpt match:', 'hypertaxonomy' ); ?></td>
                            <td>
                                <input type="number"
                                       name="htax_settings[relevance_multipliers][excerpt]"
                                       value="<?php echo esc_attr( isset( $options['relevance_multipliers']['excerpt'] ) ? $options['relevance_multipliers']['excerpt'] : 0.7 ); ?>"
                                       min="0" max="1" step="0.05" style="width: 60px;" />
                            </td>
                        </tr>
                    </table>
                    <p class="description">
                        <?php esc_html_e( 'These weights control how matches are scored in text search mode. The search system supports multiple sorting modes (controlled by the orderby parameter): (1) Text Relevance Mode (orderby=text_relevance) - sorts by keyword match quality using these weights, with selected terms acting as filters; (2) Term Relevance Mode (orderby=relevance) - sorts by taxonomy term match strength, with keyword text acting as a filter; (3) Other sorting (orderby=date, title, etc.) - standard WordPress sorting. Weight values range from 0 to 1.0. Higher values give more importance to matches in that field.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php esc_html_e( 'Rate Limiting', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <?php esc_html_e( 'Requests per minute:', 'hypertaxonomy' ); ?>
                        <input type="number" 
                               name="htax_settings[rate_limit]" 
                               value="<?php echo esc_attr( $options['rate_limit'] ); ?>" 
                               min="10" max="100" style="width: 60px;" />
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Limit AJAX search requests per user (10-100).', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php esc_html_e( 'Third-Party Query Expansion', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[expand_secondary_queries]"
                               value="1"
                               <?php checked( ! empty( $options['expand_secondary_queries'] ) ); ?> />
                        <?php esc_html_e( 'Expand secondary queries from third-party plugins', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When enabled, single-term taxonomy queries issued by facet plugins, WooCommerce layered navigation, page builders, and other third-party plugins are automatically expanded to include HyperTaxonomy inclusions. Also adjusts term counts shown in facet widgets to reflect the expanded post set. Disable if a specific plugin conflicts.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <?php
            // Strength model selection, coverage, and maintenance. The coverage and
            // maintenance rows render one panel per model; JS shows the panel of the
            // model chosen in the dropdown. Models that are no longer registered but
            // still have stored scores are listed as inactive so their scores can be
            // cleared; choosing one is never saved (see sanitize_settings()).
            $strength_models = htax_get_strength_models();
            foreach ( htax_get_stored_strength_models() as $stored_key ) {
                if ( ! isset( $strength_models[ $stored_key ] ) ) {
                    $strength_models[ $stored_key ] = array(
                        'label'       => $stored_key,
                        'description' => '',
                        'inactive'    => true,
                    );
                }
            }
            $selected_model    = htax_get_strength_model();
            $saved_model       = isset( $options['strength_model'] ) ? (string) $options['strength_model'] : HTAX_DEFAULT_STRENGTH_MODEL;
            $external_coverage = array();
            foreach ( $strength_models as $model_key => $model_args ) {
                if ( HTAX_DEFAULT_STRENGTH_MODEL === $model_key ) {
                    continue;
                }
                $by_taxonomy = htax_get_strength_model_coverage( $model_key );
                $external_coverage[ $model_key ] = array(
                    'by_taxonomy' => $by_taxonomy,
                    'scored'      => (int) array_sum( wp_list_pluck( $by_taxonomy, 'scored' ) ),
                    'total'       => (int) array_sum( wp_list_pluck( $by_taxonomy, 'total' ) ),
                );
            }
            $panel_style = function ( $model_key ) use ( $selected_model ) {
                return $model_key === $selected_model ? '' : 'display:none;';
            };
            ?>
            <tr>
                <th scope="row"><label for="htax-strength-model"><?php esc_html_e( 'Strength Model', 'hypertaxonomy' ); ?></label></th>
                <td>
                    <select name="htax_settings[strength_model]" id="htax-strength-model" <?php disabled( count( $strength_models ) < 2 ); ?>>
                        <?php foreach ( $strength_models as $model_key => $model_args ) :
                            $label = $model_args['label'];
                            if ( ! empty( $model_args['inactive'] ) ) {
                                $label = sprintf(
                                    /* translators: %s: strength model key */
                                    __( '%s — inactive, stored scores only', 'hypertaxonomy' ),
                                    $label
                                );
                            } elseif ( isset( $external_coverage[ $model_key ] ) ) {
                                $cov   = $external_coverage[ $model_key ];
                                $label = sprintf(
                                    /* translators: 1: strength model name, 2: percentage of post-term pairs scored */
                                    __( '%1$s — %2$d%% scored', 'hypertaxonomy' ),
                                    $label,
                                    $cov['total'] > 0 ? (int) floor( $cov['scored'] / $cov['total'] * 100 ) : 0
                                );
                            }
                            ?>
                            <option value="<?php echo esc_attr( $model_key ); ?>" <?php selected( $selected_model, $model_key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php // Set to 1 by JS when the dropdown changes; an untouched dropdown never changes the saved selection. ?>
                    <input type="hidden" name="htax_settings[strength_model_changed]" id="htax-strength-model-changed" value="0" />
                    <?php if ( $saved_model !== $selected_model ) : ?>
                        <p class="description" style="color:#996800;">
                            <?php echo esc_html( sprintf(
                                /* translators: %s: strength model key */
                                __( 'The selected model "%s" is inactive (its plugin may be deactivated), so the built-in model is in use. It takes effect again when its plugin is reactivated.', 'hypertaxonomy' ),
                                $saved_model
                            ) ); ?>
                        </p>
                    <?php endif; ?>
                    <p class="description">
                        <?php esc_html_e( 'Which model\'s term strengths (how central each term is to a post) rank term-based search results and related posts. Other plugins can provide models. A provider\'s model can only be selected once it has scored every post–term pair; while it is selected, pairs it hasn\'t scored yet don\'t contribute to term-based ranking. Relevance weights apply to every model.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Index Coverage', 'hypertaxonomy' ); ?></th>
                <td>
                    <div class="htax-strength-model-panel" data-model="<?php echo esc_attr( HTAX_DEFAULT_STRENGTH_MODEL ); ?>" style="<?php echo esc_attr( $panel_style( HTAX_DEFAULT_STRENGTH_MODEL ) ); ?>">
                    <?php
                    global $wpdb;
                    $indexed_types     = htax_get_indexed_post_types();
                    $type_placeholders = implode( ',', array_fill( 0, count( $indexed_types ), '%s' ) );
                    $eligible          = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts}
                         WHERE post_status = 'publish' AND post_type IN ({$type_placeholders})",
                        ...$indexed_types
                    ) );
                    $indexed           = (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}htax_post_text
                         WHERE post_status = 'publish'"
                    );
                    $term_rows         = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}htax_search_terms WHERE model = %s",
                        HTAX_DEFAULT_STRENGTH_MODEL
                    ) );
                    // Directly assigned post–term pairs per taxonomy — the same measure the
                    // external models' panels report coverage against.
                    $pairs_by_taxonomy = $wpdb->get_results( $wpdb->prepare(
                        "SELECT taxonomy, COUNT(*) AS pairs FROM {$wpdb->prefix}htax_search_terms
                         WHERE model = %s AND relationship_type = 'direct'
                         GROUP BY taxonomy ORDER BY taxonomy",
                        HTAX_DEFAULT_STRENGTH_MODEL
                    ) );
                    $total_pairs       = (int) array_sum( wp_list_pluck( (array) $pairs_by_taxonomy, 'pairs' ) );
                    $missing           = max( 0, $eligible - $indexed );
                    $pct               = $eligible > 0 ? (int) floor( $indexed / $eligible * 100 ) : 100;
                    $color             = $missing === 0 ? '#46b450' : ( $missing < 10 ? '#ffb900' : '#d63638' );
                    ?>
                    <span style="color:<?php echo esc_attr( $color ); ?>; font-weight:600;">
                        <?php echo esc_html( number_format_i18n( $indexed ) ); ?> / <?php echo esc_html( number_format_i18n( $eligible ) ); ?>
                        <?php echo esc_html( sprintf( __( 'posts indexed (%d%%)', 'hypertaxonomy' ), $pct ) ); ?>
                    </span>
                    <br><span style="font-weight:600;">
                        <?php echo esc_html( sprintf(
                            /* translators: 1: number of post-term pairs, 2: number of rows in the term relationship index */
                            _n( '%1$s post–term pair (%2$s index rows)', '%1$s post–term pairs (%2$s index rows)', $total_pairs, 'hypertaxonomy' ),
                            number_format_i18n( $total_pairs ),
                            number_format_i18n( $term_rows )
                        ) ); ?>
                    </span>
                    <?php foreach ( (array) $pairs_by_taxonomy as $pairs_row ) :
                        $pairs_tax_obj = get_taxonomy( $pairs_row->taxonomy );
                        ?>
                        <br><span style="color:#666;font-size:12px;">
                            <?php echo esc_html( sprintf(
                                /* translators: 1: taxonomy name, 2: number of post-term pairs */
                                __( '%1$s: %2$s', 'hypertaxonomy' ),
                                $pairs_tax_obj ? $pairs_tax_obj->labels->name : $pairs_row->taxonomy,
                                number_format_i18n( (int) $pairs_row->pairs )
                            ) ); ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if ( $missing > 0 ) : ?>
                        <br><span style="color:#d63638;">
                            <?php echo esc_html( sprintf(
                                _n( '%d post is missing from the index — rebuild to include it.', '%d posts are missing from the index — rebuild to include them.', $missing, 'hypertaxonomy' ),
                                $missing
                            ) ); ?>
                        </span>
                    <?php endif; ?>
                    </div>

                    <?php foreach ( $external_coverage as $model_key => $cov ) :
                        $unscored  = max( 0, $cov['total'] - $cov['scored'] );
                        $model_pct = $cov['total'] > 0 ? (int) floor( $cov['scored'] / $cov['total'] * 100 ) : 0;
                        $color     = 0 === $unscored && $cov['total'] > 0 ? '#46b450' : '#d63638';
                        ?>
                        <div class="htax-strength-model-panel" data-model="<?php echo esc_attr( $model_key ); ?>" style="<?php echo esc_attr( $panel_style( $model_key ) ); ?>">
                            <span style="color:<?php echo esc_attr( $color ); ?>; font-weight:600;">
                                <?php echo esc_html( sprintf(
                                    /* translators: 1: scored post-term pairs, 2: total post-term pairs, 3: percentage */
                                    __( '%1$s / %2$s post–term pairs scored (%3$d%%)', 'hypertaxonomy' ),
                                    number_format_i18n( $cov['scored'] ),
                                    number_format_i18n( $cov['total'] ),
                                    $model_pct
                                ) ); ?>
                            </span>
                            <?php foreach ( $cov['by_taxonomy'] as $cov_taxonomy => $counts ) :
                                $cov_tax_obj = get_taxonomy( $cov_taxonomy );
                                ?>
                                <br><span style="color:#666;font-size:12px;">
                                    <?php echo esc_html( sprintf(
                                        /* translators: 1: taxonomy name, 2: scored pairs, 3: total pairs */
                                        __( '%1$s: %2$s / %3$s', 'hypertaxonomy' ),
                                        $cov_tax_obj ? $cov_tax_obj->labels->name : $cov_taxonomy,
                                        number_format_i18n( $counts['scored'] ),
                                        number_format_i18n( $counts['total'] )
                                    ) ); ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if ( $unscored > 0 ) : ?>
                                <br><span style="color:#d63638;">
                                    <?php echo esc_html( sprintf(
                                        /* translators: %s: number of unscored post-term pairs */
                                        _n( '%s post–term pair is not scored yet.', '%s post–term pairs are not scored yet.', $unscored, 'hypertaxonomy' ),
                                        number_format_i18n( $unscored )
                                    ) ); ?>
                                    <?php if ( ! empty( $strength_models[ $model_key ]['inactive'] ) ) : ?>
                                        <?php esc_html_e( 'This model is inactive and can\'t be selected.', 'hypertaxonomy' ); ?>
                                    <?php elseif ( $model_key !== $selected_model ) : ?>
                                        <?php esc_html_e( 'This model can be selected once every pair is scored.', 'hypertaxonomy' ); ?>
                                    <?php else : ?>
                                        <?php esc_html_e( 'Unscored pairs don\'t contribute to term-based ranking until the provider scores them.', 'hypertaxonomy' ); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Index Rebuild', 'hypertaxonomy' ); ?></th>
                <td>
                    <div class="htax-strength-model-panel" data-model="<?php echo esc_attr( HTAX_DEFAULT_STRENGTH_MODEL ); ?>" style="<?php echo esc_attr( $panel_style( HTAX_DEFAULT_STRENGTH_MODEL ) ); ?>">
                        <button type="button" class="button" id="htax-rebuild-index">
                            <?php esc_html_e( 'Rebuild Search Index', 'hypertaxonomy' ); ?>
                        </button>
                        <div id="htax-rebuild-progress" style="display:none;max-width:500px;margin-top:8px;">
                            <div style="background:#e0e0e0;border-radius:4px;height:20px;overflow:hidden">
                                <div id="htax-rebuild-bar" style="background:#2271b1;height:100%;width:0%;transition:width .3s" aria-valuenow="0"></div>
                            </div>
                            <p id="htax-rebuild-label" style="margin:4px 0 0 0;font-size:12px;color:#666">0%</p>
                        </div>
                        <p class="description">
                            <?php esc_html_e( 'Rebuild the search index if results seem incorrect.', 'hypertaxonomy' ); ?>
                        </p>
                    </div>

                    <?php foreach ( $external_coverage as $model_key => $cov ) : ?>
                        <div class="htax-strength-model-panel" data-model="<?php echo esc_attr( $model_key ); ?>" style="<?php echo esc_attr( $panel_style( $model_key ) ); ?>">
                            <button type="button" class="button htax-clear-strength-model" data-model="<?php echo esc_attr( $model_key ); ?>">
                                <?php esc_html_e( 'Clear Scores', 'hypertaxonomy' ); ?>
                            </button>
                            <span class="htax-clear-strength-model-status" style="margin-left:8px;"></span>
                            <p class="description">
                                <?php echo esc_html( sprintf(
                                    /* translators: %s: strength model name */
                                    __( 'Scores for "%s" are produced by the plugin that provides this model, so HyperTaxonomy can\'t rebuild them — to rescore posts, use that plugin\'s own tools. Clear Scores removes every score stored for this model; if it is the selected strength model, the selection switches back to the built-in model.', 'hypertaxonomy' ),
                                    $strength_models[ $model_key ]['label']
                                ) ); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <?php elseif ( in_array( $search_subtab, $search_taxonomies, true ) ) :
            // Per-taxonomy relevance weights sub-tab
            $taxonomy = $search_subtab;
            $tax_obj = get_taxonomy( $taxonomy );
            if ( ! $tax_obj ) {
                echo '<p>' . esc_html__( 'Invalid taxonomy.', 'hypertaxonomy' ) . '</p>';
                return;
            }

            // Get current weights for this taxonomy
            $tax_weights = isset( $options['relevance_multipliers']['taxonomies'][$taxonomy] )
                ? $options['relevance_multipliers']['taxonomies'][$taxonomy]
                : array( 'text_match' => 0.6, 'widget_selected' => 1.2 );
            ?>

        <table class="form-table" role="presentation">
            <?php if ( htax_taxonomy_has_feature( $taxonomy, 'redirects' ) && htax_taxonomy_has_feature( $taxonomy, 'search_enhancement' ) ) :
            ?>

            <tr>
                <th scope="row"><?php esc_html_e( 'Relevance Weights', 'hypertaxonomy' ); ?></th>
                <td>
                    <p class="description" style="margin-top: 0;">
                        <?php printf(
                            esc_html__( 'Configure how much weight to give to %s matches in search results.', 'hypertaxonomy' ),
                            mb_strtolower( esc_html( $tax_obj->labels->name ), 'UTF-8' )
                        ); ?>
                    </p>
                    <?php
                    // Get weights with proper defaults
                    $is_hierarchical = is_taxonomy_hierarchical( $taxonomy );
                    $has_term_relationships = htax_taxonomy_has_feature( $taxonomy, 'term_relationships' );
                    $has_term_groups = htax_taxonomy_has_feature( $taxonomy, 'group_assignment' );
                    $has_redirects = htax_taxonomy_has_feature( $taxonomy, 'redirects' );
                    $is_core_taxonomy = in_array( $taxonomy, array( 'post_tag', 'category' ), true );
                    $show_text_column = $is_core_taxonomy; // Only show name/description weight columns for core taxonomies

                    $use_description = isset( $tax_weights['use_description'] ) ? (bool) $tax_weights['use_description'] : false;

                    $direct_weights = isset( $tax_weights['direct'] ) ? $tax_weights['direct'] : array( 'explicit' => 1.0, 'text' => 0.6, 'description' => 0.4 );

                    if ( $is_hierarchical ) {
                        $hierarchy_weights = isset( $tax_weights['hierarchy'] ) ? $tax_weights['hierarchy'] : array( 'explicit' => 0.7, 'text' => 0.4, 'description' => 0.3 );
                        $container_weights = isset( $tax_weights['container'] ) ? $tax_weights['container'] : array( 'explicit' => 0.6, 'text' => 0.35, 'description' => 0.25 );
                        $redirect_weights  = isset( $tax_weights['redirect'] )  ? $tax_weights['redirect']  : array( 'explicit' => 0.5, 'text' => 0.3,  'description' => 0.2  );
                    } else {
                        $container_weights = isset( $tax_weights['container'] ) ? $tax_weights['container'] : array( 'explicit' => 0.75, 'text' => 0.5,  'description' => 0.35 );
                        $redirect_weights  = isset( $tax_weights['redirect'] )  ? $tax_weights['redirect']  : array( 'explicit' => 0.6,  'text' => 0.35, 'description' => 0.25 );
                    }
                    // Ensure description key exists in loaded weights (upgrade path for saved settings without it).
                    foreach ( array( 'direct_weights', 'container_weights', 'redirect_weights' ) as $wvar ) {
                        if ( ! isset( $$wvar['description'] ) ) {
                            $$wvar['description'] = round( (float) ( $$wvar['text'] ?? 0.5 ) * 0.7, 2 );
                        }
                    }
                    if ( $is_hierarchical && ! isset( $hierarchy_weights['description'] ) ) {
                        $hierarchy_weights['description'] = round( (float) ( $hierarchy_weights['text'] ?? 0.4 ) * 0.7, 2 );
                    }
                    ?>
                    <p>
                        <label>
                            <input type="checkbox"
                                   id="htax-use-desc-<?php echo esc_attr( $taxonomy ); ?>"
                                   name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][use_description]"
                                   value="1"
                                   <?php checked( $use_description ); ?> />
                            <?php esc_html_e( 'Match term descriptions in PowerSearch', 'hypertaxonomy' ); ?>
                        </label>
                    </p>
                    <table class="htax-relevance-table" style="border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Match Type', 'hypertaxonomy' ); ?></th>
                                <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Explicit Selection', 'hypertaxonomy' ); ?></th>
                                <?php if ( $show_text_column ) : ?>
                                <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Name Match', 'hypertaxonomy' ); ?></th>
                                <th class="htax-desc-col" style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;<?php echo $use_description ? '' : ' display:none;'; ?>"><?php esc_html_e( 'Description Match', 'hypertaxonomy' ); ?></th>
                                <?php endif; ?>
                                <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Direct assignment -->
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Direct assignment', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][direct][explicit]"
                                           value="<?php echo esc_attr( $direct_weights['explicit'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <?php if ( $show_text_column ) : ?>
                                <td style="padding: 8px;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][direct][text]"
                                           value="<?php echo esc_attr( $direct_weights['text'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px;<?php echo $use_description ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][direct][description]"
                                           value="<?php echo esc_attr( $direct_weights['description'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <?php endif; ?>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Term directly assigned to post', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>

                            <!-- Hierarchy tier (hierarchical taxonomies only) -->
                            <?php if ( $is_hierarchical ) : ?>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Child term', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][hierarchy][explicit]"
                                           value="<?php echo esc_attr( $hierarchy_weights['explicit'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <?php if ( $show_text_column ) : ?>
                                <td style="padding: 8px;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][hierarchy][text]"
                                           value="<?php echo esc_attr( $hierarchy_weights['text'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px;<?php echo $use_description ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][hierarchy][description]"
                                           value="<?php echo esc_attr( $hierarchy_weights['description'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <?php endif; ?>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Child term via WordPress hierarchy', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <!-- Container tier -->
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Non-transitive inclusion', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][container][explicit]"
                                           value="<?php echo esc_attr( $container_weights['explicit'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;"
                                           <?php echo $has_term_relationships ? '' : 'disabled'; ?> />
                                    <?php if ( ! $has_term_relationships ) : ?>
                                        <span style="color: #dc3232; margin-left: 5px;">✗</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ( $show_text_column ) : ?>
                                <td style="padding: 8px;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][container][text]"
                                           value="<?php echo esc_attr( $container_weights['text'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;"
                                           <?php echo $has_term_relationships ? '' : 'disabled'; ?> />
                                    <?php if ( ! $has_term_relationships ) : ?>
                                        <span style="color: #dc3232; margin-left: 5px;">✗</span>
                                    <?php endif; ?>
                                </td>
                                <td class="htax-desc-col" style="padding: 8px;<?php echo $use_description ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][container][description]"
                                           value="<?php echo esc_attr( $container_weights['description'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;"
                                           <?php echo $has_term_relationships ? '' : 'disabled'; ?> />
                                    <?php if ( ! $has_term_relationships ) : ?>
                                        <span style="color: #dc3232; margin-left: 5px;">✗</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td style="padding: 8px;" class="description">
                                    <?php
                                    esc_html_e( 'Term included into a directly assigned term (container)', 'hypertaxonomy' );
                                    if ( ! $has_term_relationships ) {
                                        echo ' <em style="color: #dc3232;">' . esc_html__( '(Enable Inclusion/Container relationships to use)', 'hypertaxonomy' ) . '</em>';
                                    }
                                    ?>
                                </td>
                            </tr>

                            <!-- Redirect tier (if redirects enabled) -->
                            <?php if ( $has_redirects ) : ?>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Redirect target', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][redirect][explicit]"
                                           value="<?php echo esc_attr( $redirect_weights['explicit'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <?php if ( $show_text_column ) : ?>
                                <td style="padding: 8px;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][redirect][text]"
                                           value="<?php echo esc_attr( $redirect_weights['text'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px;<?php echo $use_description ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][taxonomies][<?php echo esc_attr( $taxonomy ); ?>][redirect][description]"
                                           value="<?php echo esc_attr( $redirect_weights['description'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <?php endif; ?>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Post assigned to redirect target term (when redirect term is selected or text-matched)', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top: 12px;">
                        <strong><?php esc_html_e( 'Explicit Selection:', 'hypertaxonomy' ); ?></strong> <?php esc_html_e( 'User selected term in search widget.', 'hypertaxonomy' ); ?><br>
                        <?php if ( $show_text_column ) : ?>
                        <strong><?php esc_html_e( 'Name Match:', 'hypertaxonomy' ); ?></strong> <?php esc_html_e( 'Search text matches the term name.', 'hypertaxonomy' ); ?><br>
                        <strong><?php esc_html_e( 'Description Match:', 'hypertaxonomy' ); ?></strong> <?php esc_html_e( 'Search text matches the term description (only when "Match term descriptions" is checked above).', 'hypertaxonomy' ); ?><br>
                        <?php else : ?>
                        <em><?php esc_html_e( 'Note: Name match and description match weights for custom taxonomies are configured in the "Custom (Flat)" and "Custom (Hierarchical)" tabs.', 'hypertaxonomy' ); ?></em><br>
                        <?php endif; ?>
                        <?php esc_html_e( 'Weight values range from 0 to 1.0. Higher values give more relevance. Use lower weights for indirect relationships. Setting a weight to 0 will effectively exclude that relationship type from search results (score of 0).', 'hypertaxonomy' ); ?><br>
                        <strong><?php esc_html_e( 'Note:', 'hypertaxonomy' ); ?></strong> <?php esc_html_e( 'Explicit weights for child-term, inclusion, and redirect-target matches (used for taxonomy-filter searches) are stored in the search index; when you save a change, the affected posts are reindexed automatically in the background. Text weights (used for keyword searches) apply immediately.', 'hypertaxonomy' ); ?><br>
                        <strong><?php esc_html_e( 'Text Search Matching:', 'hypertaxonomy' ); ?></strong> <?php esc_html_e( 'Posts are indexed with all their associated terms (direct assignments, hierarchy, inclusions, redirects). When a user searches for text, MySQL\'s FULLTEXT engine matches against all indexed term names and automatically scores higher when the search term appears in multiple places. Our tier weights then multiply these base scores to calculate final relevance.', 'hypertaxonomy' ); ?>
                    </p>
                    <?php if ( $show_text_column ) : ?>
                    <script>
                    (function() {
                        var cb = document.getElementById('htax-use-desc-<?php echo esc_js( $taxonomy ); ?>');
                        if ( ! cb ) { return; }
                        cb.addEventListener('change', function() {
                            var cols = document.querySelectorAll('.htax-relevance-table .htax-desc-col');
                            for (var i = 0; i < cols.length; i++) {
                                cols[i].style.display = this.checked ? '' : 'none';
                            }
                        });
                    })();
                    </script>
                    <?php endif; ?>
                    <button type="button"
                            class="button htax-reset-weights"
                            data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
                            data-hierarchical="<?php echo $is_hierarchical ? 'true' : 'false'; ?>"
                            data-inclusion="<?php echo $has_term_relationships ? 'true' : 'false'; ?>"
                            data-tag-groups="<?php echo $has_term_groups ? 'true' : 'false'; ?>"
                            data-show-text="<?php echo $show_text_column ? 'true' : 'false'; ?>"
                            style="margin-top: 12px;">
                        <?php esc_html_e( 'Reset to Default Values', 'hypertaxonomy' ); ?>
                    </button>

                </td>
            </tr>
            <?php endif; ?>
        </table>

        <?php elseif ( 'custom_flat' === $search_subtab ) :
            // Custom flat taxonomies text match weights
            $cft = isset( $options['relevance_multipliers']['custom_taxonomies_text']['flat'] )
                ? $options['relevance_multipliers']['custom_taxonomies_text']['flat']
                : array();
            $custom_flat_use_desc = isset( $cft['use_description'] ) ? (bool) $cft['use_description'] : false;
            $custom_flat_weights  = array(
                'direct'    => $cft['direct']    ?? 0.6,
                'container' => $cft['container'] ?? 0.5,
                'redirect'  => $cft['redirect']  ?? 0.35,
            );
            $custom_flat_desc = isset( $cft['description'] ) ? $cft['description'] : array();
            $custom_flat_desc_weights = array(
                'direct'    => $custom_flat_desc['direct']    ?? round( $custom_flat_weights['direct']    * 0.7, 2 ),
                'container' => $custom_flat_desc['container'] ?? round( $custom_flat_weights['container'] * 0.7, 2 ),
                'redirect'  => $custom_flat_desc['redirect']  ?? round( $custom_flat_weights['redirect']  * 0.7, 2 ),
            );
            ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Text Match Weights', 'hypertaxonomy' ); ?></th>
                <td>
                    <p class="description" style="margin-top: 0;">
                        <?php esc_html_e( 'These weights apply to text search matches for all custom flat (non-hierarchical) taxonomies. For explicit widget selection weights, configure each taxonomy individually in its own tab.', 'hypertaxonomy' ); ?>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox"
                                   id="htax-use-desc-custom-flat"
                                   name="htax_settings[relevance_multipliers][custom_taxonomies_text][flat][use_description]"
                                   value="1"
                                   <?php checked( $custom_flat_use_desc ); ?> />
                            <?php esc_html_e( 'Match term descriptions in PowerSearch', 'hypertaxonomy' ); ?>
                        </label>
                    </p>
                    <table class="htax-relevance-table" style="border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Match Type', 'hypertaxonomy' ); ?></th>
                                <th style="text-align: center; padding: 8px; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Name Match', 'hypertaxonomy' ); ?></th>
                                <th class="htax-desc-col" style="text-align: center; padding: 8px; border-bottom: 2px solid #ddd;<?php echo $custom_flat_use_desc ? '' : ' display:none;'; ?>"><?php esc_html_e( 'Description Match', 'hypertaxonomy' ); ?></th>
                                <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Direct assignment', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px; text-align: center;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][flat][direct]"
                                           value="<?php echo esc_attr( $custom_flat_weights['direct'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px; text-align: center;<?php echo $custom_flat_use_desc ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][flat][description][direct]"
                                           value="<?php echo esc_attr( $custom_flat_desc_weights['direct'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Term directly assigned to post', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Non-transitive inclusion', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px; text-align: center;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][flat][container]"
                                           value="<?php echo esc_attr( $custom_flat_weights['container'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px; text-align: center;<?php echo $custom_flat_use_desc ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][flat][description][container]"
                                           value="<?php echo esc_attr( $custom_flat_desc_weights['container'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Term included into a directly assigned term', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Redirect target', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px; text-align: center;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][flat][redirect]"
                                           value="<?php echo esc_attr( $custom_flat_weights['redirect'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px; text-align: center;<?php echo $custom_flat_use_desc ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][flat][description][redirect]"
                                           value="<?php echo esc_attr( $custom_flat_desc_weights['redirect'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Post assigned to redirect target term', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top: 12px;">
                        <?php esc_html_e( 'These weights apply globally to all custom flat taxonomies during indexed text search. Explicit selection weights are configured per-taxonomy. The "Match term descriptions" checkbox can be overridden per-taxonomy in each taxonomy\'s own tab.', 'hypertaxonomy' ); ?>
                    </p>
                    <script>
                    (function() {
                        var cb = document.getElementById('htax-use-desc-custom-flat');
                        if ( ! cb ) { return; }
                        cb.addEventListener('change', function() {
                            var cols = document.querySelectorAll('.htax-relevance-table .htax-desc-col');
                            for (var i = 0; i < cols.length; i++) {
                                cols[i].style.display = this.checked ? '' : 'none';
                            }
                        });
                    })();
                    </script>
                </td>
            </tr>
        </table>

        <?php elseif ( 'custom_hierarchical' === $search_subtab ) :
            // Custom hierarchical taxonomies text match weights
            $cht = isset( $options['relevance_multipliers']['custom_taxonomies_text']['hierarchical'] )
                ? $options['relevance_multipliers']['custom_taxonomies_text']['hierarchical']
                : array();
            $custom_hier_use_desc = isset( $cht['use_description'] ) ? (bool) $cht['use_description'] : false;
            $custom_hier_weights  = array(
                'direct'    => $cht['direct']    ?? 0.5,
                'hierarchy' => $cht['hierarchy'] ?? 0.4,
                'container' => $cht['container'] ?? 0.35,
                'redirect'  => $cht['redirect']  ?? 0.3,
            );
            $custom_hier_desc = isset( $cht['description'] ) ? $cht['description'] : array();
            $custom_hier_desc_weights = array(
                'direct'    => $custom_hier_desc['direct']    ?? round( $custom_hier_weights['direct']    * 0.7, 2 ),
                'hierarchy' => $custom_hier_desc['hierarchy'] ?? round( $custom_hier_weights['hierarchy'] * 0.7, 2 ),
                'container' => $custom_hier_desc['container'] ?? round( $custom_hier_weights['container'] * 0.7, 2 ),
                'redirect'  => $custom_hier_desc['redirect']  ?? round( $custom_hier_weights['redirect']  * 0.7, 2 ),
            );
            ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Text Match Weights', 'hypertaxonomy' ); ?></th>
                <td>
                    <p class="description" style="margin-top: 0;">
                        <?php esc_html_e( 'These weights apply to text search matches for all custom hierarchical taxonomies. For explicit widget selection weights, configure each taxonomy individually in its own tab.', 'hypertaxonomy' ); ?>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox"
                                   id="htax-use-desc-custom-hier"
                                   name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][use_description]"
                                   value="1"
                                   <?php checked( $custom_hier_use_desc ); ?> />
                            <?php esc_html_e( 'Match term descriptions in PowerSearch', 'hypertaxonomy' ); ?>
                        </label>
                    </p>
                    <table class="htax-relevance-table" style="border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Match Type', 'hypertaxonomy' ); ?></th>
                                <th style="text-align: center; padding: 8px; border-bottom: 2px solid #ddd;"><?php esc_html_e( 'Name Match', 'hypertaxonomy' ); ?></th>
                                <th class="htax-desc-col" style="text-align: center; padding: 8px; border-bottom: 2px solid #ddd;<?php echo $custom_hier_use_desc ? '' : ' display:none;'; ?>"><?php esc_html_e( 'Description Match', 'hypertaxonomy' ); ?></th>
                                <th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Direct assignment', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px; text-align: center;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][direct]"
                                           value="<?php echo esc_attr( $custom_hier_weights['direct'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px; text-align: center;<?php echo $custom_hier_use_desc ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][description][direct]"
                                           value="<?php echo esc_attr( $custom_hier_desc_weights['direct'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Term directly assigned to post', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Child term', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px; text-align: center;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][hierarchy]"
                                           value="<?php echo esc_attr( $custom_hier_weights['hierarchy'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px; text-align: center;<?php echo $custom_hier_use_desc ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][description][hierarchy]"
                                           value="<?php echo esc_attr( $custom_hier_desc_weights['hierarchy'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Child term via hierarchy', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Non-transitive inclusion', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px; text-align: center;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][container]"
                                           value="<?php echo esc_attr( $custom_hier_weights['container'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px; text-align: center;<?php echo $custom_hier_use_desc ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][description][container]"
                                           value="<?php echo esc_attr( $custom_hier_desc_weights['container'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Term included into a directly assigned term', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; font-weight: 600;"><?php esc_html_e( 'Redirect target', 'hypertaxonomy' ); ?></td>
                                <td style="padding: 8px; text-align: center;">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][redirect]"
                                           value="<?php echo esc_attr( $custom_hier_weights['redirect'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td class="htax-desc-col" style="padding: 8px; text-align: center;<?php echo $custom_hier_use_desc ? '' : ' display:none;'; ?>">
                                    <input type="number"
                                           name="htax_settings[relevance_multipliers][custom_taxonomies_text][hierarchical][description][redirect]"
                                           value="<?php echo esc_attr( $custom_hier_desc_weights['redirect'] ); ?>"
                                           min="0" max="1" step="0.05" style="width: 60px;" />
                                </td>
                                <td style="padding: 8px;" class="description">
                                    <?php esc_html_e( 'Post assigned to redirect target term', 'hypertaxonomy' ); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top: 12px;">
                        <?php esc_html_e( 'These weights apply globally to all custom hierarchical taxonomies during indexed text search. Explicit selection weights are configured per-taxonomy. The "Match term descriptions" checkbox can be overridden per-taxonomy in each taxonomy\'s own tab.', 'hypertaxonomy' ); ?>
                    </p>
                    <script>
                    (function() {
                        var cb = document.getElementById('htax-use-desc-custom-hier');
                        if ( ! cb ) { return; }
                        cb.addEventListener('change', function() {
                            var cols = document.querySelectorAll('.htax-relevance-table .htax-desc-col');
                            for (var i = 0; i < cols.length; i++) {
                                cols[i].style.display = this.checked ? '' : 'none';
                            }
                        });
                    })();
                    </script>
                </td>
            </tr>
        </table>

        <?php else : // Invalid search subtab - redirect to general ?>
            <script type="text/javascript">
                window.location.href = '?page=hypertaxonomy&tab=search&search_subtab=general';
            </script>
        <?php endif; ?>
        <?php
        return true; // Has content to save
    }


    private function render_cache_tab() {
        $options = $this->get_options();
        $cache_settings = $options['cache_settings'];
        ?>
        <input type="hidden" name="htax_settings[_cache_tab_present]" value="1" />
        <?php
        $type_nonce = wp_create_nonce( 'htax_clear_cache_type' );
        $type_base  = admin_url( 'options-general.php?page=hypertaxonomy&tab=cache&action=clear_cache_type&_wpnonce=' . $type_nonce . '&cache_type=' );
        ?>
        <h3 style="margin-top:1.5em;"><?php esc_html_e( 'Internal Caches', 'hypertaxonomy' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Cache Durations', 'hypertaxonomy' ); ?></th>
                <td>
                    <table class="htax-cache-table">
                        <tr>
                            <td style="padding-right: 12px;">
                                <?php esc_html_e( 'Search cache:', 'hypertaxonomy' ); ?>
                            </td>
                            <td>
                                <input type="number"
                                       name="htax_settings[cache_settings][search_cache_duration]"
                                       value="<?php echo esc_attr( $cache_settings['search_cache_duration'] ); ?>"
                                       min="60" max="86400" style="width: 80px;" />
                                <?php esc_html_e( 'seconds', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px; color: #646970; font-size: 12px;">
                                <?php esc_html_e( 'Contributing-term computation for taxonomy archive pages.', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px;">
                                <a href="<?php echo esc_url( $type_base . 'search' ); ?>" class="button button-small"><?php esc_html_e( 'Clear', 'hypertaxonomy' ); ?></a>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding-right: 12px;">
                                <?php esc_html_e( 'Autocomplete cache:', 'hypertaxonomy' ); ?>
                            </td>
                            <td>
                                <input type="number"
                                       name="htax_settings[cache_settings][autocomplete_cache_duration]"
                                       value="<?php echo esc_attr( $cache_settings['autocomplete_cache_duration'] ?? 300 ); ?>"
                                       min="60" max="86400" style="width: 80px;" />
                                <?php esc_html_e( 'seconds', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px; color: #646970; font-size: 12px;">
                                <?php esc_html_e( 'Tag and term autocomplete suggestions in the admin post editor.', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px;">
                                <a href="<?php echo esc_url( $type_base . 'autocomplete' ); ?>" class="button button-small"><?php esc_html_e( 'Clear', 'hypertaxonomy' ); ?></a>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding-right: 12px;">
                                <?php esc_html_e( 'Relationship cache:', 'hypertaxonomy' ); ?>
                            </td>
                            <td>
                                <input type="number"
                                       name="htax_settings[cache_settings][relationship_cache_duration]"
                                       value="<?php echo esc_attr( $cache_settings['relationship_cache_duration'] ?? 0 ); ?>"
                                       min="0" max="2592000" style="width: 80px;" />
                                <?php esc_html_e( 'seconds', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px; color: #646970; font-size: 12px;">
                                <?php esc_html_e( 'Inclusions, containers, redirects, hidden terms, group memberships, and custom relationship lookups. 0 = never expire (cleared on save).', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px;">
                                <a href="<?php echo esc_url( $type_base . 'relationship' ); ?>" class="button button-small"><?php esc_html_e( 'Clear', 'hypertaxonomy' ); ?></a>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding-right: 12px;">
                                <?php esc_html_e( 'Sticky archive cache:', 'hypertaxonomy' ); ?>
                            </td>
                            <td>
                                <input type="number"
                                       name="htax_settings[cache_settings][sticky_cache_duration]"
                                       value="<?php echo esc_attr( $cache_settings['sticky_cache_duration'] ?? 0 ); ?>"
                                       min="0" max="2592000" style="width: 80px;" />
                                <?php esc_html_e( 'seconds', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px; color: #646970; font-size: 12px;">
                                <?php esc_html_e( 'Sticky post lists shown on taxonomy archive pages. 0 = never expire (cleared when pins change).', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px;">
                                <a href="<?php echo esc_url( $type_base . 'sticky' ); ?>" class="button button-small"><?php esc_html_e( 'Clear', 'hypertaxonomy' ); ?></a>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding-right: 12px;">
                                <?php esc_html_e( 'Shortcode output cache:', 'hypertaxonomy' ); ?>
                            </td>
                            <td>
                                <input type="number"
                                       name="htax_settings[cache_settings][shortcode_cache_duration]"
                                       value="<?php echo esc_attr( $cache_settings['shortcode_cache_duration'] ?? 0 ); ?>"
                                       min="0" max="2592000" style="width: 80px;" />
                                <?php esc_html_e( 'seconds', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px; color: #646970; font-size: 12px;">
                                <?php esc_html_e( '[ht_post_list] and [ht_related_posts] rendered output. 0 = never expire (use Clear below when posts change).', 'hypertaxonomy' ); ?>
                            </td>
                            <td style="padding-left: 12px;">
                                <a href="<?php echo esc_url( $type_base . 'shortcode' ); ?>" class="button button-small"><?php esc_html_e( 'Clear', 'hypertaxonomy' ); ?></a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Clear All Caches', 'hypertaxonomy' ); ?></th>
                <td>
                    <button type="button" class="button button-primary" onclick="if(confirm('<?php esc_attr_e( 'Clear all HyperTaxonomy caches?', 'hypertaxonomy' ); ?>')) { window.location='<?php echo wp_nonce_url( admin_url( 'options-general.php?page=hypertaxonomy&tab=cache&action=clear_cache' ), 'htax_clear_cache' ); ?>'; }">
                        <?php esc_html_e( 'Clear All', 'hypertaxonomy' ); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e( 'Clears all HyperTaxonomy transients at once.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

        </table>
        <?php
    }

    /**
     * Render Export / Import tab with its subtabs (Export, Import, Migrate, Sync).
     */
    private function render_export_import_tab() {
        $valid_subtabs = array( 'export', 'import', 'migrate', 'sync' );
        $requested     = isset( $_GET['export_import_subtab'] ) ? sanitize_key( wp_unslash( $_GET['export_import_subtab'] ) ) : '';
        $active        = in_array( $requested, $valid_subtabs, true ) ? $requested : 'export';

        $subtabs = array(
            'export'  => __( 'Export', 'hypertaxonomy' ),
            'import'  => __( 'Import', 'hypertaxonomy' ),
            'migrate' => __( 'Migrate', 'hypertaxonomy' ),
            'sync'    => __( 'Sync', 'hypertaxonomy' ),
        );
        ?>
        <ul class="htax-export-import-subtabs">
            <?php foreach ( $subtabs as $subtab_key => $subtab_label ) : ?>
            <li>
                <a href="?page=hypertaxonomy&tab=export-import&export_import_subtab=<?php echo esc_attr( $subtab_key ); ?>"
                   class="<?php echo $active === $subtab_key ? 'current' : ''; ?>">
                    <?php echo esc_html( $subtab_label ); ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php
        switch ( $active ) {
            case 'export':
                require_once HTAX_PLUGIN_DIR . 'includes/admin/export-ui.php';
                break;
            case 'import':
                require_once HTAX_PLUGIN_DIR . 'includes/admin/import-ui.php';
                break;
            case 'migrate':
                require_once HTAX_PLUGIN_DIR . 'includes/admin/tag-groups-migrate-ui.php';
                break;
            case 'sync':
                require_once HTAX_PLUGIN_DIR . 'includes/admin/tag-groups-sync-ui.php';
                break;
        }
    }

    /**
     * Clear icon metadata for all terms in a taxonomy
     * Note: Does NOT delete the actual image files, only the term meta
     */
    private function render_advanced_tab() {
        $options = $this->get_options();
        ?>
        <input type="hidden" name="htax_settings[_advanced_tab_present]" value="1" />
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Concurrent Edit Locking', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[edit_lock_enabled]"
                               value="1"
                               <?php checked( ! empty( $options['edit_lock_enabled'] ) ); ?> />
                        <?php esc_html_e( 'Show a warning when multiple editors edit the same term or relationship type simultaneously', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Uses the WordPress heartbeat API. Disable if this conflicts with another plugin that also hooks into the heartbeat.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Post Editor Term Loading', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[preload_editor_terms]"
                               value="1"
                               <?php checked( ! isset( $options['preload_editor_terms'] ) || ! empty( $options['preload_editor_terms'] ) ); ?> />
                        <?php esc_html_e( 'Pre-load assigned tag data from PHP to avoid per-term REST requests', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'On page load, HyperTaxonomy injects the currently assigned terms into the block editor\'s data store so Gutenberg does not need to fetch each one individually via REST. Uncheck if a plugin adds custom fields to taxonomy terms in the block editor (such as ACF with REST API support) — disabling this allows those fields to be included in the native REST response.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Post Editor Restrictions', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[prevent_tag_creation]"
                               value="1"
                               <?php checked( ! empty( $options['prevent_tag_creation'] ) ); ?> />
                        <?php esc_html_e( 'Disallow creating new tags in post editor', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Editors can only select existing tags in the post editor, not create new ones. Tags can still be created via the Terms admin screens.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Content-Based Tag Suggestions', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               id="htax-content-tag-suggestions"
                               name="htax_settings[content_tag_suggestions]"
                               value="1"
                               <?php checked( ! isset( $options['content_tag_suggestions'] ) || ! empty( $options['content_tag_suggestions'] ) ); ?> />
                        <?php esc_html_e( 'Replace "most used tags" suggestions in the post editor with suggestions based on the post content', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Suggests tags whose names match words in the post title, excerpt, or body, ordered by number of matches. Only tags not already assigned to the post are shown.', 'hypertaxonomy' ); ?>
                    </p>

                    <div style="margin-top: 8px;">
                        <label for="htax-ignore-words">
                            <?php esc_html_e( 'Words to ignore:', 'hypertaxonomy' ); ?>
                        </label>
                        <input type="text"
                               id="htax-ignore-words"
                               name="htax_settings[tag_suggestion_ignore_words]"
                               class="regular-text"
                               value="<?php echo esc_attr( $options['tag_suggestion_ignore_words'] ?? '' ); ?>"
                               placeholder="<?php esc_attr_e( 'e.g. post, page, article, the, a', 'hypertaxonomy' ); ?>"
                               <?php disabled( isset( $options['content_tag_suggestions'] ) && empty( $options['content_tag_suggestions'] ) ); ?> />
                        <p class="description">
                            <?php esc_html_e( 'Comma-separated. Occurrences of these words are removed from the post text before matching.', 'hypertaxonomy' ); ?>
                        </p>
                    </div>
                    <script>
                    ( function() {
                        var cb    = document.getElementById( 'htax-content-tag-suggestions' );
                        var input = document.getElementById( 'htax-ignore-words' );
                        if ( cb && input ) {
                            cb.addEventListener( 'change', function() {
                                input.disabled = ! this.checked;
                            } );
                        }
                    } )();
                    </script>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Search Assets', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[always_load_search_assets]"
                               value="1"
                               <?php checked( ! empty( $options['always_load_search_assets'] ) ); ?> />
                        <?php esc_html_e( 'Always load search assets (Search Widget and PowerSearch)', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Enable if using page builders or dynamic content where search shortcodes may be loaded conditionally.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'HyperTaxonomy Presence Header', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[advertise_ht_presence]"
                               value="1"
                               <?php checked( ! isset( $options['advertise_ht_presence'] ) || ! empty( $options['advertise_ht_presence'] ) ); ?> />
                        <?php esc_html_e( 'Add X-HyperTaxonomy header to REST API responses', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Peer sites (including TermConnect on remote WordPress installs) use this header to detect HyperTaxonomy and enable scored requests, vocabulary sync, and cross-site term matching. Disable to opt out of being discovered as a vocabulary peer.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Term Inclusion Relationships', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[rest_include_relationship_data]"
                               value="1"
                               <?php checked( ! isset( $options['rest_include_relationship_data'] ) || ! empty( $options['rest_include_relationship_data'] ) ); ?> />
                        <?php esc_html_e( 'Include term inclusion and synonym relationships in REST term responses', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Exposes inclusion and synonym data for each term. Used by themes and headless clients that display related terms. Disable on sites with deep relationship graphs where REST performance is a concern, or to avoid exposing vocabulary structure publicly. htax_relationships requires Custom Term Relationships and Relationship Types to also be enabled.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Custom Term Relationships and Relationship Types', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[rest_peer_sync_enabled]"
                               value="1"
                               <?php checked( ! isset( $options['rest_peer_sync_enabled'] ) || ! empty( $options['rest_peer_sync_enabled'] ) ); ?> />
                        <?php esc_html_e( 'Expose custom relationship type definitions via REST and include custom term relationships in REST term responses', 'hypertaxonomy' ); ?>
                    </label>
                    <p class="description">
                        <?php
                        printf(
                            wp_kses(
                                /* translators: %s: REST endpoint paths */
                                __( 'Registers <code>GET htax/v1/relationship-types</code> and <code>GET htax/v1/term/{id}/relationships</code>, and adds the <code>htax_relationships</code> field to standard REST term responses. TermConnect uses these to sync custom relationship type definitions and term relationships from peer sites. Private types and remote-delegated types are always excluded regardless of this setting.', 'hypertaxonomy' ),
                                array( 'code' => array() )
                            )
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Uninstall', 'hypertaxonomy' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="htax_settings[delete_on_uninstall]"
                               value="1"
                               <?php checked( ! empty( $options['delete_on_uninstall'] ) ); ?> />
                        <?php esc_html_e( 'Delete all HyperTaxonomy data when plugin is uninstalled', 'hypertaxonomy' ); ?>
                    </label>

                    <p class="description" style="color: #d63638;">
                        <strong><?php esc_html_e( 'Warning:', 'hypertaxonomy' ); ?></strong>
                        <?php esc_html_e( 'This will permanently delete all HyperTaxonomy term data: relationships, groups, redirects, icons, hidden flags, sticky posts and the search index. Settings are removed on uninstall either way.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Health Check', 'hypertaxonomy' ); ?></th>
                <td>
                    <?php
                    $issues = htax_health_check();
                    if ( empty( $issues ) ) {
                        echo '<p style="color: green;">' . esc_html__( '✓ All systems operational', 'hypertaxonomy' ) . '</p>';
                    } else {
                        echo '<p style="color: orange;">' . esc_html__( '⚠ Issues detected:', 'hypertaxonomy' ) . '</p>';
                        echo '<ul>';
                        foreach ( $issues as $issue ) {
                            echo '<li>' . esc_html( $issue ) . '</li>';
                        }
                        echo '</ul>';
                    }
                    ?>
                    <p class="description">
                        <?php esc_html_e( 'System status report: database table presence, search index state, and configuration validity.', 'hypertaxonomy' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Term Links tab.
     *
     * @since 1.0.0
     */
    /**
     * Render External Links tab.
     */
    public function register_settings() {
        register_setting(
            'htax_settings_group',
            'htax_settings',
            array( $this, 'sanitize_settings' )
        );
    }

    /**
     * Sanitize settings before saving
     */
    public function sanitize_settings( $input ) {
        // Get existing options first to preserve unchanged values
        $existing = $this->get_options();
        
        // Start with existing values
        $sanitized = $existing;
        
        // Sanitize taxonomy settings - only update fields that were actually submitted
        if ( ! empty( $input['_general_tab_present'] ) ) {
            // Only update settings for taxonomies that are present in the form submission
            // Preserve existing values for taxonomies not in this submission

            if ( ! isset( $sanitized['enabled_taxonomies'] ) ) {
                $sanitized['enabled_taxonomies'] = array();
            }
            if ( ! isset( $sanitized['taxonomy_features'] ) ) {
                $sanitized['taxonomy_features'] = array();
            }
            if ( ! isset( $sanitized['show_redirect_counts'] ) ) {
                $sanitized['show_redirect_counts'] = array();
            }

            // Get list of taxonomies being edited in this form submission
            $editing_taxonomies = array();
            if ( isset( $input['_editing_taxonomy'] ) ) {
                // Single taxonomy (post_tag or category)
                $editing_taxonomies[] = sanitize_key( $input['_editing_taxonomy'] );
            }
            if ( isset( $input['_editing_taxonomies'] ) && is_array( $input['_editing_taxonomies'] ) ) {
                // Multiple custom taxonomies
                foreach ( $input['_editing_taxonomies'] as $tax ) {
                    $editing_taxonomies[] = sanitize_key( $tax );
                }
            }

            // Determine if we're on the overview subtab
            $is_overview = ! empty( $input['_general_overview_present'] );

            // Process enabled_taxonomies - ONLY if we're on the overview subtab
            // Individual taxonomy subtabs don't have enable/disable checkboxes, only feature checkboxes
            if ( $is_overview ) {
                foreach ( $editing_taxonomies as $taxonomy ) {
                    // If checkbox is present in input, it's checked; otherwise it's unchecked
                    $sanitized['enabled_taxonomies'][$taxonomy] = isset( $input['enabled_taxonomies'][$taxonomy] ) && ! empty( $input['enabled_taxonomies'][$taxonomy] );
                }
            }

            // Sanitize taxonomy features - ONLY if we're NOT on the overview subtab
            // Overview subtab doesn't have feature checkboxes, only enable/disable checkboxes
            if ( ! $is_overview ) {
                foreach ( $editing_taxonomies as $taxonomy ) {
                // Get submitted features for this taxonomy (might be empty if all unchecked)
                $features = isset( $input['taxonomy_features'][$taxonomy] ) && is_array( $input['taxonomy_features'][$taxonomy] )
                    ? $input['taxonomy_features'][$taxonomy]
                    : array();

				// Get feature states (checkboxes not submitted = false)
				$search_enhancement = ! empty( $features['search_enhancement'] );
				$icons              = ! empty( $features['icons'] );
				$visibility         = ! empty( $features['visibility'] );
				$term_relationships = ! empty( $features['term_relationships'] );
				$group_assignment   = ! empty( $features['group_assignment'] );
				$redirects          = ! empty( $features['redirects'] );
				$customize_archives = ! empty( $features['customize_archives'] );

				// Check if term_relationships is being re-enabled and has non-zero container weights.
				$old_term_relationships = isset( $existing['taxonomy_features'][$taxonomy]['term_relationships'] ) ? $existing['taxonomy_features'][$taxonomy]['term_relationships'] : false;
				if ( ! $old_term_relationships && $term_relationships ) {
					$multipliers = isset( $existing['relevance_multipliers']['taxonomies'][$taxonomy] ) ? $existing['relevance_multipliers']['taxonomies'][$taxonomy] : array();
                    $has_container_weight = ( isset( $multipliers['container']['explicit'] ) && $multipliers['container']['explicit'] > 0 ) ||
                                           ( isset( $multipliers['container']['text'] ) && $multipliers['container']['text'] > 0 );

                    if ( $has_container_weight ) {
                        add_settings_error(
                            'htax_settings',
                            'htax_term_relationships_reindex_needed',
                            sprintf(
                                __( 'Term relationships have been enabled for %s. You may need to rebuild the search index for inclusion/container relationships to be searchable.', 'hypertaxonomy' ),
                                mb_strtolower( get_taxonomy( $taxonomy )->labels->name, 'UTF-8' )
                            ),
                            'warning'
                        );
                    }
                }

				// Groups cannot participate in term relationships or be assigned to other groups.
				if ( 'group' === $taxonomy ) {
					$term_relationships = false;
					$group_assignment   = false;
					// Note: Groups CAN have redirects, icons, visibility, and archive customization.
				}

				$sanitized['taxonomy_features'][$taxonomy] = array(
					'search_enhancement' => $search_enhancement,
					'icons'              => $icons,
					'visibility'         => $visibility,
					'term_relationships' => $term_relationships,
					'group_assignment'   => $group_assignment,
					'redirects'          => $redirects,
					'customize_archives' => $customize_archives,
					'share_locations'    => ! empty( $features['share_locations'] ),
				);
                } // end foreach editing_taxonomies
            } // end if not overview

        }
        
        // Sanitize display settings
        if ( ! empty( $input['_display_tab_present'] ) ) {
            $sanitized['show_contributing_terms'] = ! empty( $input['show_contributing_terms'] );
            
            // Sanitize template - ensure it contains %s
            // The template is appended directly to the archive title, so a leading space is
            // significant; sanitize_text_field() trims it, so restore one when present.
            $raw_template = isset( $input['contributing_terms_template'] ) ? (string) $input['contributing_terms_template'] : '';
            $template     = sanitize_text_field( $raw_template );
            if ( '' !== $template && preg_match( '/^\s/', $raw_template ) ) {
                $template = ' ' . $template;
            }
            if ( false === strpos( $template, '%s' ) ) {
                $template = __( ' (including: %s)', 'hypertaxonomy' );
            }
            $sanitized['contributing_terms_template'] = $template;

            $valid_visibility = array( 'all', 'editors', 'none' );
            $sanitized['hidden_terms_visibility'] = isset( $input['hidden_terms_visibility'] ) &&
                in_array( $input['hidden_terms_visibility'], $valid_visibility ) ?
                $input['hidden_terms_visibility'] : 'editors';

            $sanitized['disable_sticky_styles'] = ! empty( $input['disable_sticky_styles'] );

            // Sanitize max sticky posts - must be between 1 and 100
            $max_sticky = isset( $input['max_sticky_posts'] ) ? intval( $input['max_sticky_posts'] ) : 10;
            $max_sticky = max( 1, min( 100, $max_sticky ) );
            $sanitized['max_sticky_posts']         = $max_sticky;
            $sanitized['disable_shortcode_styles']   = ! empty( $input['disable_shortcode_styles'] );
            $sanitized['show_archive_description']   = ! empty( $input['show_archive_description'] );
            $sanitized['show_sticky_posts']          = ! empty( $input['show_sticky_posts'] );
        }

        // Sanitize icons - per-taxonomy structure
        if ( ! empty( $input['_icons_tab_present'] ) ) {
            $valid_styles = array( 'square', 'rounded', 'extra_rounded', 'circular', 'custom' );
            $valid_display = array( 'always', 'when_available', 'never' );

            if ( ! isset( $sanitized['icon_settings'] ) ) {
                $sanitized['icon_settings'] = array();
            }

            // Get old settings to detect dimension changes (use already-loaded $existing, not a second get_option).
            $old_icon_settings = isset( $existing['icon_settings'] ) ? $existing['icon_settings'] : array();

            // Process icon settings for each taxonomy
            if ( isset( $input['icon_settings'] ) && is_array( $input['icon_settings'] ) ) {
                foreach ( $input['icon_settings'] as $taxonomy => $settings ) {
                    $taxonomy = sanitize_key( $taxonomy );

                    if ( ! is_array( $settings ) ) {
                        continue;
                    }

                    // Initialize taxonomy settings array
                    $sanitized['icon_settings'][$taxonomy] = array();

                    // Sanitize enabled checkbox
                    $sanitized['icon_settings'][$taxonomy]['enabled'] = ! empty( $settings['enabled'] );

                    // Sanitize display setting
                    if ( isset( $settings['display'] ) && in_array( $settings['display'], $valid_display, true ) ) {
                        $sanitized['icon_settings'][$taxonomy]['display'] = $settings['display'];
                    } else {
                        $sanitized['icon_settings'][$taxonomy]['display'] = 'when_available';
                    }

                    // Sanitize width and height (min: 16px, max: 512px)
                    $new_width  = isset( $settings['width'] )  ? max( 16, min( 512, intval( $settings['width'] ) ) )  : 96;
                    $new_height = isset( $settings['height'] ) ? max( 16, min( 512, intval( $settings['height'] ) ) ) : 96;

                    $sanitized['icon_settings'][$taxonomy]['width']  = $new_width;
                    $sanitized['icon_settings'][$taxonomy]['height'] = $new_height;

                    // Sanitize small/medium size percentages (1–99)
                    $new_medium_pct = isset( $settings['medium_pct'] ) ? max( 1, min( 99, intval( $settings['medium_pct'] ) ) ) : 50;
                    $new_small_pct  = isset( $settings['small_pct'] )  ? max( 1, min( 99, intval( $settings['small_pct'] ) ) )  : 25;

                    $sanitized['icon_settings'][$taxonomy]['medium_pct'] = $new_medium_pct;
                    $sanitized['icon_settings'][$taxonomy]['small_pct']  = $new_small_pct;

                    // Check if dimensions changed — clear all icon assignments for this taxonomy
                    $dimensions_changed = false;
                    if ( isset( $old_icon_settings[$taxonomy] ) ) {
                        $old_width  = isset( $old_icon_settings[$taxonomy]['width'] )  ? $old_icon_settings[$taxonomy]['width']  : 96;
                        $old_height = isset( $old_icon_settings[$taxonomy]['height'] ) ? $old_icon_settings[$taxonomy]['height'] : 96;

                        if ( $old_width !== $new_width || $old_height !== $new_height ) {
                            $dimensions_changed = true;
                            $this->clear_taxonomy_icon_metadata( $taxonomy );

                            add_settings_error(
                                'htax_settings',
                                'htax_icons_cleared',
                                sprintf(
                                    __( 'Icon dimensions changed for %s. All icon assignments have been cleared (image files preserved).', 'hypertaxonomy' ),
                                    mb_strtolower( get_taxonomy( $taxonomy )->labels->name, 'UTF-8' )
                                ),
                                'warning'
                            );
                        }
                    }

                    // Check if only percentages changed — queue thumbnail regeneration
                    if ( ! $dimensions_changed && isset( $old_icon_settings[$taxonomy] ) ) {
                        $old_medium_pct = isset( $old_icon_settings[$taxonomy]['medium_pct'] ) ? $old_icon_settings[$taxonomy]['medium_pct'] : 50;
                        $old_small_pct  = isset( $old_icon_settings[$taxonomy]['small_pct'] )  ? $old_icon_settings[$taxonomy]['small_pct']  : 25;

                        if ( $old_medium_pct !== $new_medium_pct || $old_small_pct !== $new_small_pct ) {
                            $pending   = htax_get_transient( 'htax_icon_regen_pending' );
                            $pending   = is_array( $pending ) ? $pending : array();
                            $pending[] = $taxonomy;
                            htax_set_transient( 'htax_icon_regen_pending', $pending, 300 );
                        }
                    }

                    // Sanitize border radius
                    $sanitized['icon_settings'][$taxonomy]['border_radius'] = array(
                        'style' => isset( $settings['border_radius']['style'] ) &&
                                   in_array( $settings['border_radius']['style'], $valid_styles, true ) ?
                                   $settings['border_radius']['style'] : 'rounded',
                        'top_left' => isset( $settings['border_radius']['top_left'] ) ?
                                      max( 0, min( 100, intval( $settings['border_radius']['top_left'] ) ) ) : 20,
                        'top_right' => isset( $settings['border_radius']['top_right'] ) ?
                                       max( 0, min( 100, intval( $settings['border_radius']['top_right'] ) ) ) : 20,
                        'bottom_left' => isset( $settings['border_radius']['bottom_left'] ) ?
                                         max( 0, min( 100, intval( $settings['border_radius']['bottom_left'] ) ) ) : 20,
                        'bottom_right' => isset( $settings['border_radius']['bottom_right'] ) ?
                                          max( 0, min( 100, intval( $settings['border_radius']['bottom_right'] ) ) ) : 20,
                    );
                }
            }
        }
        
        // Strength model — validated whenever submitted, not only from the Search tab,
        // so code can switch it back with update_option() (e.g. after clearing a model).
        // A provider's model is accepted only once it has full coverage, unless it is
        // already the selected model.
        // The Search tab form also sends strength_model_changed: 0 means the dropdown
        // was left as displayed. It shows the model in effect, which is the built-in
        // model while the saved one is inactive, so saving it unchanged would otherwise
        // replace an inactive provider's selection for good.
        $strength_model_untouched = isset( $input['strength_model_changed'] ) && empty( $input['strength_model_changed'] );
        if ( isset( $input['strength_model'] ) && ! $strength_model_untouched ) {
            $model   = sanitize_text_field( (string) $input['strength_model'] );
            $current = isset( $existing['strength_model'] ) ? (string) $existing['strength_model'] : HTAX_DEFAULT_STRENGTH_MODEL;
            if ( ! isset( htax_get_strength_models()[ $model ] ) ) {
                // Inactive models are listed only so their scores can be cleared.
                $model = $current;
            } elseif ( $model !== $current && ! htax_strength_model_has_full_coverage( $model ) ) {
                add_settings_error(
                    'htax_settings',
                    'htax_strength_model_coverage',
                    sprintf(
                        /* translators: %s: strength model name */
                        __( '"%s" can\'t be selected as the strength model yet: it hasn\'t scored every post–term pair. See Index Coverage on the Search tab.', 'hypertaxonomy' ),
                        htax_get_strength_models()[ $model ]['label']
                    )
                );
                $model = $current;
            }
            $sanitized['strength_model'] = $model;
        }

        // Sanitize search settings
        if ( ! empty( $input['_search_tab_present'] ) ) {
            // Indexed post types
            $valid_post_types = array_keys( get_post_types( array( 'public' => true ) ) );
            $new_indexed      = array();
            if ( isset( $input['indexed_post_types'] ) && is_array( $input['indexed_post_types'] ) ) {
                foreach ( $input['indexed_post_types'] as $pt ) {
                    $pt = sanitize_key( $pt );
                    if ( in_array( $pt, $valid_post_types, true ) && 'attachment' !== $pt ) {
                        $new_indexed[] = $pt;
                    }
                }
            }
            $sanitized['indexed_post_types'] = ! empty( $new_indexed ) ? $new_indexed : array( 'post', 'page' );

            $sanitized['always_enhance_search'] = ! empty( $input['always_enhance_search'] );
            $sanitized['expand_secondary_queries'] = ! empty( $input['expand_secondary_queries'] );

            $sanitized['default_match_mode'] = ( isset( $input['default_match_mode'] ) && 'all' === $input['default_match_mode'] ) ? 'all' : 'any';

            $allowed_expand_types = array( 'container', 'hierarchy', 'redirect_target' );
            if ( isset( $input['search_expand_types'] ) && is_array( $input['search_expand_types'] ) ) {
                $sanitized['search_expand_types'] = array_values( array_intersect( (array) $input['search_expand_types'], $allowed_expand_types ) );
            } else {
                $sanitized['search_expand_types'] = $allowed_expand_types;
            }

            // Sanitize and validate orderby
            if ( isset( $input['enhanced_search_orderby'] ) && ! empty( $input['enhanced_search_orderby'] ) ) {
                $orderby = sanitize_text_field( $input['enhanced_search_orderby'] );
                // Validate by parsing it - if it parses successfully, it's valid
                $parsed = htax_parse_orderby( $orderby );
                if ( ! empty( $parsed ) ) {
                    $sanitized['enhanced_search_orderby'] = $orderby;
                } else {
                    $sanitized['enhanced_search_orderby'] = 'relevance:desc,date:desc';
                }
            } else {
                $sanitized['enhanced_search_orderby'] = 'relevance:desc,date:desc';
            }

            $sanitized['rate_limit'] = isset( $input['rate_limit'] ) ? max( 10, min( 100, intval( $input['rate_limit'] ) ) ) : 30;

            // Sanitize per-taxonomy relevance weights (2D matrix: match_type × source_type)
            if ( isset( $input['relevance_multipliers']['taxonomies'] ) && is_array( $input['relevance_multipliers']['taxonomies'] ) ) {
                foreach ( $input['relevance_multipliers']['taxonomies'] as $taxonomy => $match_types ) {
                    if ( ! is_array( $match_types ) ) {
                        continue;
                    }
                    $taxonomy = sanitize_key( $taxonomy );

                    // Checkbox: present = checked, absent = unchecked.
                    $sanitized['relevance_multipliers']['taxonomies'][ $taxonomy ]['use_description'] =
                        ! empty( $match_types['use_description'] );

                    // Each match type has source types: explicit, text, description
                    foreach ( array( 'direct', 'container', 'hierarchy', 'redirect' ) as $match_type ) {
                        if ( isset( $match_types[ $match_type ] ) && is_array( $match_types[ $match_type ] ) ) {
                            foreach ( array( 'explicit', 'text', 'description' ) as $source_type ) {
                                if ( isset( $match_types[ $match_type ][ $source_type ] ) ) {
                                    $value = floatval( $match_types[ $match_type ][ $source_type ] );
                                    $sanitized['relevance_multipliers']['taxonomies'][ $taxonomy ][ $match_type ][ $source_type ] = max( 0, min( 1.0, $value ) );
                                }
                            }
                        }
                    }
                }
            }

            // Sanitize content field relevance weights (title, content, excerpt)
            foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
                if ( isset( $input['relevance_multipliers'][$field] ) ) {
                    $value = floatval( $input['relevance_multipliers'][$field] );
                    $sanitized['relevance_multipliers'][$field] = max( 0, min( 1.0, $value ) );
                }
            }

            // Sanitize custom taxonomy text-match relevance weights
            if ( isset( $input['relevance_multipliers']['custom_taxonomies_text'] ) &&
                 is_array( $input['relevance_multipliers']['custom_taxonomies_text'] ) ) {
                foreach ( array( 'flat', 'hierarchical' ) as $kind ) {
                    $kind_input = $input['relevance_multipliers']['custom_taxonomies_text'][ $kind ] ?? null;
                    if ( ! is_array( $kind_input ) ) {
                        continue;
                    }
                    // Checkbox: present = checked, absent = unchecked.
                    $sanitized['relevance_multipliers']['custom_taxonomies_text'][ $kind ]['use_description'] =
                        ! empty( $kind_input['use_description'] );
                    // Per-rel-type name match weights (scalar values).
                    foreach ( array( 'direct', 'container', 'hierarchy', 'redirect' ) as $rel_type ) {
                        if ( isset( $kind_input[ $rel_type ] ) && ! is_array( $kind_input[ $rel_type ] ) ) {
                            $sanitized['relevance_multipliers']['custom_taxonomies_text'][ $kind ][ $rel_type ] =
                                max( 0, min( 1.0, floatval( $kind_input[ $rel_type ] ) ) );
                        }
                    }
                    // Per-rel-type description match weights (nested under 'description').
                    if ( isset( $kind_input['description'] ) && is_array( $kind_input['description'] ) ) {
                        foreach ( array( 'direct', 'container', 'hierarchy', 'redirect' ) as $rel_type ) {
                            if ( isset( $kind_input['description'][ $rel_type ] ) ) {
                                $sanitized['relevance_multipliers']['custom_taxonomies_text'][ $kind ]['description'][ $rel_type ] =
                                    max( 0, min( 1.0, floatval( $kind_input['description'][ $rel_type ] ) ) );
                            }
                        }
                    }
                }
            }
        }

        // Sanitize cache settings
        if ( ! empty( $input['_cache_tab_present'] ) ) {
            if ( ! empty( $input['cache_settings'] ) && is_array( $input['cache_settings'] ) ) {
                foreach ( $input['cache_settings'] as $key => $value ) {
                    // 0 = never expire (explicit invalidation only); max matches the UI's 30-day cap.
                    $sanitized['cache_settings'][$key] = max( 0, min( 2592000, absint( $value ) ) );
                }
            }

            // Sanitize max_depth (hierarchical taxonomy display)
            if ( isset( $input['max_depth'] ) ) {
                $depth = intval( $input['max_depth'] );
                // Allow -1 for unlimited, or 1-99 for limited depth
                if ( -1 === $depth ) {
                    $sanitized['max_depth'] = -1;
                } else {
                    $sanitized['max_depth'] = max( 1, min( 99, $depth ) );
                }
            }
        }

        // Sanitize advanced settings
        if ( ! empty( $input['_advanced_tab_present'] ) ) {
            $sanitized['preload_editor_terms']         = ! empty( $input['preload_editor_terms'] );
            $sanitized['edit_lock_enabled']           = ! empty( $input['edit_lock_enabled'] );
            $sanitized['prevent_tag_creation']        = ! empty( $input['prevent_tag_creation'] );
            $sanitized['content_tag_suggestions']     = ! empty( $input['content_tag_suggestions'] );
            $sanitized['tag_suggestion_ignore_words'] = isset( $input['tag_suggestion_ignore_words'] )
                ? sanitize_text_field( $input['tag_suggestion_ignore_words'] )
                : '';
            $sanitized['delete_on_uninstall']            = ! empty( $input['delete_on_uninstall'] );
            $sanitized['always_load_search_assets']      = ! empty( $input['always_load_search_assets'] );
            $sanitized['advertise_ht_presence']          = ! empty( $input['advertise_ht_presence'] );
            $sanitized['rest_include_relationship_data'] = ! empty( $input['rest_include_relationship_data'] );
            $sanitized['rest_peer_sync_enabled']         = ! empty( $input['rest_peer_sync_enabled'] );
        }

        // Clear cache after settings change
        htax_clear_all_caches();

        // Clear the cached options
        $this->clear_options_cache();

        // Add success notice
        add_settings_error(
            'htax_settings',
            'htax_settings_saved',
            __( 'Settings saved successfully.', 'hypertaxonomy' ),
            'success'
        );

        return $sanitized;
    }


    public function enqueue_settings_scripts( $hook ) {
        if ( 'settings_page_hypertaxonomy' !== $hook ) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'htax-settings-css',
            HTAX_PLUGIN_URL . 'admin/css/htax-settings.css',
            array(),
            HTAX_VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'htax-settings-js',
            HTAX_PLUGIN_URL . 'admin/js/htax-settings.js',
            array( 'jquery' ),
            HTAX_VERSION,
            true
        );

        // Localize script with settings data
        wp_localize_script(
            'htax-settings-js',
            'htaxSettings',
            array(
                'rebuildIndexNonce'       => wp_create_nonce( 'htax-rebuild-index' ),
                'indexRebuildSuccess'     => __( 'Search index rebuilt successfully!', 'hypertaxonomy' ),
                'indexRebuildError'       => __( 'Error rebuilding search index. Please try again.', 'hypertaxonomy' ),
                'clearStrengthModelNonce' => wp_create_nonce( 'htax-clear-strength-model' ),
                'confirmClearStrengthModel' => __( 'Remove every score stored for this strength model? The plugin that provides it will have to rescore all posts. This cannot be undone.', 'hypertaxonomy' ),
                'clearingStrengthModel'   => __( 'Clearing…', 'hypertaxonomy' ),
                'clearStrengthModelError' => __( 'Error clearing scores. Please try again.', 'hypertaxonomy' ),
                'deleteAllIconsNonce'     => wp_create_nonce( 'htax-delete-all-icons' ),
                'confirmDeleteAllIcons'   => __( 'Are you sure you want to delete all icons for %s? This action cannot be undone.', 'hypertaxonomy' ),
                'deletingIcons'           => __( 'Deleting icons...', 'hypertaxonomy' ),
                'iconsDeletedSuccess'     => __( 'Icons deleted successfully!', 'hypertaxonomy' ),
                'iconsDeleteError'        => __( 'Error deleting icons. Please try again.', 'hypertaxonomy' ),
                'confirmResetWeights'     => __( 'Are you sure you want to reset all relevance weights to their default values?', 'hypertaxonomy' ),
                'weightsResetSuccess'     => __( 'Relevance weights have been reset to default values.', 'hypertaxonomy' ),
            )
        );
    }

    /**
     * Render Export / Import tab.
     */
    private function clear_taxonomy_icon_metadata( $taxonomy ) {
        // Get all terms with icons for this taxonomy
        $terms = get_terms( array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'meta_query' => array(
                array(
                    'key' => '_htax_icon_id',
                    'compare' => 'EXISTS'
                )
            ),
            'fields' => 'ids'
        ) );

        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term_id ) {
                // Delete icon metadata (but keep the attachment file)
                delete_term_meta( $term_id, '_htax_icon_id' );

                // Clear icon cache for this term
                HyperTaxonomy_Term_Icons::get_instance()->clear_icon_cache( $term_id );
            }
        }
    }

}

HyperTaxonomy_Settings::get_instance();

// Handle cache clearing
add_action( 'admin_init', function() {
    if ( isset( $_GET['page'] ) && 'hypertaxonomy' === $_GET['page'] &&
         isset( $_GET['action'] ) && 'clear_cache' === $_GET['action'] ) {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'htax_clear_cache' ) ) {
            wp_die( __( 'Security check failed.', 'hypertaxonomy' ) );
        }

        htax_clear_all_caches();

        wp_safe_redirect( admin_url( 'options-general.php?page=hypertaxonomy&tab=cache&cache_cleared=1' ) );
        exit;
    }

    // Per-type cache clearing
    if ( isset( $_GET['page'] ) && 'hypertaxonomy' === $_GET['page'] &&
         isset( $_GET['action'] ) && 'clear_cache_type' === $_GET['action'] ) {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'hypertaxonomy' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'htax_clear_cache_type' ) ) {
            wp_die( __( 'Security check failed.', 'hypertaxonomy' ) );
        }

        $type = isset( $_GET['cache_type'] ) ? sanitize_key( $_GET['cache_type'] ) : '';
        switch ( $type ) {
            case 'relationship':  htax_clear_relationship_caches();  break;
            case 'search':        htax_clear_search_caches();         break;
            case 'autocomplete':  htax_clear_autocomplete_caches();   break;
            case 'sticky':        htax_clear_sticky_caches();         break;
            case 'shortcode':     htax_clear_shortcode_caches();      break;
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=hypertaxonomy&tab=cache&cache_cleared=1' ) );
        exit;
    }
});

// Show cache cleared notice
add_action( 'admin_notices', function() {
    $screen = get_current_screen();
    if ( $screen && 'settings_page_hypertaxonomy' === $screen->id && isset( $_GET['cache_cleared'] ) && '1' === $_GET['cache_cleared'] ) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'All caches cleared successfully!', 'hypertaxonomy' ); ?></p>
        </div>
        <?php
    }
});