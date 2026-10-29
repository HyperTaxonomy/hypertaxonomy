<?php
/**
 * Term Icons functionality for HyperTaxonomy
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

class HyperTaxonomy_Term_Icons {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get icon sizes for a specific taxonomy
     * Returns array of size_name => [width, height, crop]
     * The configured dimensions are the large (full) size; medium = 50%, small = 25%.
     * Users upload at large size; WordPress generates the smaller sizes on upload.
     */
    private static function get_settings(): array {
        static $settings = null;
        if ( null === $settings ) {
            $settings = get_option( 'htax_settings', array() );
        }
        return $settings;
    }

    private static function get_icon_sizes_for_taxonomy( $taxonomy ) {
        $options       = self::get_settings();
        $icon_settings = $options['icon_settings'][$taxonomy] ?? array();

        $large_width   = isset( $icon_settings['width'] )      ? intval( $icon_settings['width'] )      : 96;
        $large_height  = isset( $icon_settings['height'] )     ? intval( $icon_settings['height'] )     : 96;
        $medium_pct    = isset( $icon_settings['medium_pct'] ) ? intval( $icon_settings['medium_pct'] ) : 50;
        $small_pct     = isset( $icon_settings['small_pct'] )  ? intval( $icon_settings['small_pct'] )  : 25;

        return array(
            "htax-icon-small-{$taxonomy}"  => array( (int) round( $large_width * $small_pct / 100 ),  (int) round( $large_height * $small_pct / 100 ),  true ),
            "htax-icon-medium-{$taxonomy}" => array( (int) round( $large_width * $medium_pct / 100 ), (int) round( $large_height * $medium_pct / 100 ), true ),
            "htax-icon-large-{$taxonomy}"  => array( $large_width, $large_height, true ),
        );
    }

    /**
     * Get all icon sizes for all enabled taxonomies
     */
    private static function get_all_icon_sizes() {
        $all_sizes = array();
        $enabled_taxonomies = htax_get_enabled_taxonomies();

        foreach ( $enabled_taxonomies as $taxonomy ) {
            if ( htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
                $all_sizes = array_merge( $all_sizes, self::get_icon_sizes_for_taxonomy( $taxonomy ) );
            }
        }

        return $all_sizes;
    }

    /**
     * Get taxonomy-specific size name for a given size type
     *
     * @param string $taxonomy Taxonomy name
     * @param string $size_type Size type: 'small', 'medium', or 'large'
     * @return string Size name like 'htax-icon-small-post_tag'
     */
    public static function get_size_name( $taxonomy, $size_type = 'small' ) {
        return "htax-icon-{$size_type}-{$taxonomy}";
    }

    /**
     * Get icon dimensions for a taxonomy
     *
     * @param string $taxonomy Taxonomy name
     * @param string $size_type Size type: 'small', 'medium', or 'large'
     * @return array ['width' => int, 'height' => int]
     */
    public static function get_icon_dimensions( $taxonomy, $size_type = 'small' ) {
        $options       = self::get_settings();
        $icon_settings = $options['icon_settings'][$taxonomy] ?? array();

        $large_width  = isset( $icon_settings['width'] )      ? intval( $icon_settings['width'] )      : 96;
        $large_height = isset( $icon_settings['height'] )     ? intval( $icon_settings['height'] )     : 96;
        $medium_pct   = isset( $icon_settings['medium_pct'] ) ? intval( $icon_settings['medium_pct'] ) : 50;
        $small_pct    = isset( $icon_settings['small_pct'] )  ? intval( $icon_settings['small_pct'] )  : 25;

        if ( 'large' === $size_type ) {
            return array( 'width' => $large_width, 'height' => $large_height );
        } elseif ( 'medium' === $size_type ) {
            return array( 'width' => (int) round( $large_width * $medium_pct / 100 ), 'height' => (int) round( $large_height * $medium_pct / 100 ) );
        }
        // 'small' default
        return array( 'width' => (int) round( $large_width * $small_pct / 100 ), 'height' => (int) round( $large_height * $small_pct / 100 ) );
    }

    /**
     * Color palette for icon-shaped placeholders
     */
    private static $color_palette = array(
        '#e53935', '#d81b60', '#8e24aa', '#5e35b1',
        '#3949ab', '#1e88e5', '#039be5', '#00acc1',
        '#00897b', '#43a047', '#7cb342', '#c0ca33',
        '#fdd835', '#ffb300', '#fb8c00', '#f4511e',
        '#6d4c41', '#757575', '#546e7a', '#424242'
    );

    /**
     * Number of colors in the placeholder palette.
     *
     * @since 1.0.0
     * @return int
     */
    public static function get_palette_size(): int {
        return count( self::$color_palette );
    }

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
        // Check if feature is enabled for any taxonomy
        if ( ! self::are_icons_enabled() ) {
            return;
        }
        
        // Register custom image sizes
        add_action( 'init', array( $this, 'register_image_sizes' ) );
        
        // Admin UI - register for all enabled taxonomies dynamically (priority 13)
        add_action( 'init', function() {
            $enabled_taxonomies = htax_get_enabled_taxonomies();
            foreach ( $enabled_taxonomies as $taxonomy ) {
                add_action( "{$taxonomy}_edit_form_fields", array( $this, 'add_icon_field' ), 13 );
                add_action( "{$taxonomy}_add_form_fields", array( $this, 'add_icon_field_new' ), 13 );
            }
        }, 999 );

        // Note: save_term_icon is now called by htax_save_term_data orchestrator
        // No need for direct hook registration here

        // Admin scripts
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        
        // AJAX handlers
        add_action( 'wp_ajax_htax_get_term_icons', array( $this, 'ajax_get_term_icons' ) );
        add_action( 'wp_ajax_htax_delete_all_icons', array( $this, 'ajax_delete_all_icons' ) );
        add_action( 'wp_ajax_htax_crop_term_icon',   array( $this, 'ajax_crop_term_icon' ) );

        // Handle slug changes - rename icon attachment to match
        add_action( 'edited_term', array( $this, 'handle_slug_change' ), 10, 3 );

        // Cleanup on term deletion
        add_action( 'delete_term', array( $this, 'htax_cleanup_term_icon' ), 10, 3 );
    }
    
    public static function are_icons_enabled( $taxonomy = '' ) {
        $options = self::get_settings();
        $icon_settings = $options['icon_settings'] ?? array();

        // If specific taxonomy requested, check just that one
        if ( ! empty( $taxonomy ) ) {
            return ! empty( $icon_settings[ $taxonomy ]['enabled'] );
        }

        // Otherwise check if icons are enabled for ANY taxonomy
        static $any_enabled = null;
        if ( null === $any_enabled ) {
            $any_enabled = false;
            foreach ( $icon_settings as $tax => $settings ) {
                if ( ! empty( $settings['enabled'] ) ) {
                    $any_enabled = true;
                    break;
                }
            }
        }
        return $any_enabled;
    }
    
    /**
     * Register custom image sizes
     */
    public function register_image_sizes() {
        $all_sizes = self::get_all_icon_sizes();
        foreach ( $all_sizes as $name => $size ) {
            add_image_size( $name, $size[0], $size[1], $size[2] );
        }
    }
    
    /**
     * Add icon field to term edit form
     */
    public function add_icon_field( $term ) {
        $current_screen = get_current_screen();
        $taxonomy = $current_screen ? $current_screen->taxonomy : '';

        if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
            return;
        }

        // Check if icons feature is enabled for this taxonomy
        if ( ! htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
            return;
        }
        
        $term_id = is_object( $term ) ? $term->term_id : 0;
        $term_slug = is_object( $term ) ? $term->slug : '';
        $icon_id = get_term_meta( $term_id, '_htax_icon_id', true );
        $icon_url = '';
        
        if ( $icon_id ) {
            $icon_url = wp_get_attachment_image_url( $icon_id, "htax-icon-medium-{$taxonomy}" );
        }
        ?>
        <tr class="form-field term-icon-wrap">
            <th scope="row">
                <label for="htax-term-icon"><?php esc_html_e( 'Term Icon', 'hypertaxonomy' ); ?></label>
            </th>
            <td>
                <div id="htax-icon-container" data-term-slug="<?php echo esc_attr( $term_slug ); ?>">
                    
                    <div id="htax-icon-preview" class="htax-icon-preview"<?php echo $icon_url ? '' : ' style="display:none;"'; ?>>
                        <?php if ( $icon_url ) : ?>
                            <img src="<?php echo esc_url( $icon_url ); ?>"
                                 alt="<?php esc_attr_e( 'Term icon', 'hypertaxonomy' ); ?>"
                                 class="htax-term-icon"
                                 data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>" />
                        <?php endif; ?>
                    </div>
                    
                    <input type="hidden" id="htax-icon-id" name="htax_icon_id" 
                           value="<?php echo esc_attr( $icon_id ); ?>" />
                    
                    <div class="htax-icon-buttons">
                        <button type="button" class="button htax-upload-icon-button" id="htax-upload-icon">
                            <?php echo $icon_id ? esc_html__( 'Replace Icon', 'hypertaxonomy' ) : esc_html__( 'Upload Icon', 'hypertaxonomy' ); ?>
                        </button>

                        <button type="button" class="button" id="htax-remove-icon"
                                <?php echo $icon_id ? '' : 'style="display:none;"'; ?>>
                            <?php esc_html_e( 'Remove Icon', 'hypertaxonomy' ); ?>
                        </button>
                    </div>

                    <p class="description">
                        <?php
                        $dimensions = self::get_icon_dimensions( $taxonomy, 'large' );
                        printf(
                            /* translators: 1: required width px, 2: required height px */
                            esc_html__( 'Required: %1$d×%2$d px exactly, or larger (you will be prompted to crop). Images smaller than this are not accepted. SVG accepted at any size; a warning is shown if the SVG\'s natural proportions differ from the slot.', 'hypertaxonomy' ),
                            $dimensions['width'],
                            $dimensions['height']
                        );
                        ?>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Add icon field to new term form
     */
    public function add_icon_field_new( $taxonomy ) {
        if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
            return;
        }

        // Check if icons feature is enabled for this taxonomy
        if ( ! htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
            return;
        }
        ?>
        <div class="form-field term-icon-wrap">
            <label for="htax-term-icon"><?php esc_html_e( 'Term Icon', 'hypertaxonomy' ); ?></label>
            
            <div id="htax-icon-container-new">
                <div id="htax-icon-preview" class="htax-icon-preview" style="display:none;"></div>
                
                <input type="hidden" id="htax-icon-id" name="htax_icon_id" value="" />
                
                <div class="htax-icon-buttons">
                    <button type="button" class="button" id="htax-upload-icon">
                        <?php esc_html_e( 'Upload Icon', 'hypertaxonomy' ); ?>
                    </button>
                    
                    <button type="button" class="button" id="htax-remove-icon" style="display:none;">
                        <?php esc_html_e( 'Remove Icon', 'hypertaxonomy' ); ?>
                    </button>
                </div>

                <p class="description">
                    <?php
                    $dimensions = self::get_icon_dimensions( $taxonomy, 'large' );
                    printf(
                        /* translators: 1: required width px, 2: required height px */
                        esc_html__( 'Required: %1$d×%2$d px exactly, or larger (you will be prompted to crop). Images smaller than this are not accepted. SVG accepted at any size; a warning is shown if the SVG\'s natural proportions differ from the slot.', 'hypertaxonomy' ),
                        $dimensions['width'],
                        $dimensions['height']
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get icon data for multiple terms in a single query with caching
     * 
     * @param array $term_ids Array of term IDs
     * @param string $size Image size
     * @return array Keyed by term_id with icon data
     */
    public function get_batch_term_icon_data( $term_ids, $size = 'htax-icon-small', $taxonomy = '' ) {
        if ( empty( $term_ids ) ) {
            return array();
        }
        
        // Sanitize and ensure unique term IDs
        $term_ids = htax_sanitize_term_ids( $term_ids );
        
        // Try to get as many as possible from individual caches first
        $results = array();
        $uncached_term_ids = array();
        
        foreach ( $term_ids as $term_id ) {
            $individual_cache_key = 'htax_icon_' . $term_id . '_' . $size;
            $cached_data = wp_cache_get( $individual_cache_key, 'hypertaxonomy' );

            if ( false !== $cached_data ) {
                // Ensure taxonomy is set (for old cached data)
                if ( ! isset( $cached_data['taxonomy'] ) ) {
                    $cached_data['taxonomy'] = $taxonomy;
                }
                $results[ $term_id ] = $cached_data;
            } else {
                $uncached_term_ids[] = $term_id;
            }
        }
        
        // If all were cached, return early
        if ( empty( $uncached_term_ids ) ) {
            return $results;
        }
        
        // Check batch cache for uncached items.
        // Fold the icons version into the key so clear_icon_cache() invalidation applies.
        $icons_version   = wp_cache_get( 'htax_icons_version', 'hypertaxonomy' );
        if ( false === $icons_version ) {
            $icons_version = 1;
        }
        $batch_cache_key = 'htax_icons_batch_' . md5( implode( '_', $uncached_term_ids ) . '_' . $size . '_v' . $icons_version );
        $batch_cached = wp_cache_get( $batch_cache_key, 'hypertaxonomy' );
        
        if ( false !== $batch_cached ) {
            // Merge batch cache with individual cache results
            return array_merge( $results, $batch_cached );
        }
        
        // Fetch uncached data from database
        global $wpdb;
        
        // Build placeholders for SQL IN clause
        $placeholders = htax_sql_placeholders( count( $uncached_term_ids ) );
        
        // Fetch all icon meta and term slugs in one query
        $term_data = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.term_id, t.slug, tm.meta_value as icon_id
             FROM {$wpdb->terms} t
             LEFT JOIN {$wpdb->termmeta} tm ON t.term_id = tm.term_id 
                 AND tm.meta_key = '_htax_icon_id'
             WHERE t.term_id IN ($placeholders)",
            ...$uncached_term_ids
        ) );
        
        // Build maps for quick lookup
        $term_slugs = array();
        $term_attachment_map = array();
        $attachment_ids = array();
        
        foreach ( $term_data as $row ) {
            $term_id = intval( $row->term_id );
            $term_slugs[ $term_id ] = $row->slug;
            
            if ( ! empty( $row->icon_id ) ) {
                $attachment_id = intval( $row->icon_id );
                $term_attachment_map[ $term_id ] = $attachment_id;
                $attachment_ids[] = $attachment_id;
            }
        }
        
        // Handle any term IDs that weren't found (shouldn't happen, but be safe)
        foreach ( $uncached_term_ids as $term_id ) {
            if ( ! isset( $term_slugs[ $term_id ] ) ) {
                // Fallback: fetch individual term
                $term = htax_get_valid_term( $term_id );
                if ( $term ) {
                    $term_slugs[ $term_id ] = $term->slug;
                } else {
                    // Use ID as fallback slug
                    $term_slugs[ $term_id ] = 'term-' . $term_id;
                }
            }
        }
        
        // Batch fetch all attachment URLs if we have any
        $attachment_urls = array();
        if ( ! empty( $attachment_ids ) ) {
            $attachment_ids = array_unique( $attachment_ids );
            
            // Try to get attachments from cache first
            $uncached_attachments = array();
            foreach ( $attachment_ids as $attachment_id ) {
                $attach_cache_key = 'htax_attach_url_' . $attachment_id . '_' . $size;
                $cached_url = wp_cache_get( $attach_cache_key, 'hypertaxonomy' );
                
                if ( false !== $cached_url ) {
                    $attachment_urls[ $attachment_id ] = $cached_url;
                } else {
                    $uncached_attachments[] = $attachment_id;
                }
            }
            
            // Fetch uncached attachments
            if ( ! empty( $uncached_attachments ) ) {
                $attachments = get_posts( array(
                    'post_type' => 'attachment',
                    'post__in' => $uncached_attachments,
                    'posts_per_page' => count( $uncached_attachments ), // Use exact count
                    'post_status' => 'any',
                    'fields' => 'ids',
                    'no_found_rows' => true,
                ) );
                
                // Get URLs for each attachment
                foreach ( $attachments as $attachment_id ) {
                    $image = wp_get_attachment_image_src( $attachment_id, $size );
                    if ( $image && ! empty( $image[0] ) ) {
                        $url = $image[0];
                        $attachment_urls[ $attachment_id ] = $url;
                        
                        // Cache individual attachment URL
                        $attach_cache_key = 'htax_attach_url_' . $attachment_id . '_' . $size;
                        wp_cache_set( $attach_cache_key, $url, 'hypertaxonomy', 7200 ); // 2 hours
                    }
                }
            }
        }
        
        // Build results for uncached terms
        $batch_results = array();
        
        foreach ( $uncached_term_ids as $term_id ) {
            $icon_data = array(
                'has_icon' => false,
                'url' => '',
                'placeholder_color' => '',
                'taxonomy' => $taxonomy
            );

            // Check if this term has an icon
            if ( isset( $term_attachment_map[ $term_id ] ) ) {
                $attachment_id = $term_attachment_map[ $term_id ];

                if ( isset( $attachment_urls[ $attachment_id ] ) ) {
                    $icon_data['has_icon'] = true;
                    $icon_data['url'] = $attachment_urls[ $attachment_id ];
                }
            }

            // Generate placeholder color if no icon (using SLUG for consistency)
            if ( ! $icon_data['has_icon'] && isset( $term_slugs[ $term_id ] ) ) {
                $icon_data['placeholder_color'] = $this->get_term_color( $term_slugs[ $term_id ] );
            }

            $batch_results[ $term_id ] = $icon_data;
            
            // Cache individual term icon data
            $individual_cache_key = 'htax_icon_' . $term_id . '_' . $size;
            wp_cache_set( $individual_cache_key, $icon_data, 'hypertaxonomy', 3600 ); // 1 hour
        }
        
        // Cache the batch result
        wp_cache_set( $batch_cache_key, $batch_results, 'hypertaxonomy', 1800 ); // 30 minutes
        
        // Merge and return all results
        return array_merge( $results, $batch_results );
    }
    
    /**
     * Get placeholder color for a term (deterministic based on term SLUG for consistency)
     * 
     * @param int $term_id Term ID
     * @param string $slug Optional slug if already known (for optimization)
     * @return string Hex color code
     */
    private function get_placeholder_color( $term_id, $slug = null ) {
        if ( null === $slug ) {
            // Only fetch term if slug not provided (single operations)
            $term = htax_get_valid_term( $term_id );
            if ( ! $term ) {
                // Fallback: create pseudo-slug from ID
                $slug = 'term-' . $term_id;
            } else {
                $slug = $term->slug;
            }
        }
        
        return $this->get_term_color( $slug );
    }
    
    /**
     * Clear attachment URL cache for all icon sizes of a taxonomy.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $taxonomy      Taxonomy slug.
     */
    private function clear_attachment_url_caches( $attachment_id, $taxonomy ) {
        $sizes = array_keys( self::get_icon_sizes_for_taxonomy( $taxonomy ) );
        foreach ( $sizes as $size ) {
            wp_cache_delete( 'htax_attach_url_' . $attachment_id . '_' . $size, 'hypertaxonomy' );
        }
    }

    /**
     * Clear icon cache when icon is updated or deleted
     *
     * @param int $term_id Term ID
     */
    public function clear_icon_cache( $term_id ) {
        // Get term to determine taxonomy
        $term = htax_get_valid_term( $term_id );
        if ( ! $term ) {
            return;
        }

        // Clear all size variations for this term's taxonomy
        $sizes = array_keys( self::get_icon_sizes_for_taxonomy( $term->taxonomy ) );
        foreach ( $sizes as $size ) {
            wp_cache_delete( 'htax_icon_' . $term_id . '_' . $size, 'hypertaxonomy' );
        }

        // Also clear any batch caches that might contain this term
        // This is harder to do precisely, so we use a version key approach
        $version_key = 'htax_icons_version';
        $version = wp_cache_get( $version_key, 'hypertaxonomy' );
        if ( false === $version ) {
            $version = 1;
        } else {
            $version++;
        }
        wp_cache_set( $version_key, $version, 'hypertaxonomy', 86400 ); // 24 hours
    }
    
    /**
     * Save term icon with strict validation
     */
    public function save_term_icon( $term_id ) {
        // Note: Validation (nonce, permissions) is done by htax_save_term_data() orchestrator

        if ( ! isset( $_POST['htax_icon_id'] ) ) {
            return;
        }

        // Get taxonomy from term_id
        $term = htax_get_valid_term( $term_id );
        if ( ! $term ) {
            return;
        }
        $taxonomy = $term->taxonomy;

        // Check if icons feature is enabled for this taxonomy
        if ( ! htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
            return;
        }

        $attachment_id = intval( wp_unslash( $_POST['htax_icon_id'] ) );
        $old_attachment_id = (int) get_term_meta( $term_id, '_htax_icon_id', true );

        // Early return when icon hasn't changed — avoids image validation, resize, rename,
        // meta write, and cache clear on every term save that doesn't touch the icon.
        if ( $attachment_id === $old_attachment_id ) {
            return;
        }

        if ( $attachment_id ) {
            // Validate that it's an image
            if ( ! wp_attachment_is_image( $attachment_id ) ) {
                htax_add_admin_notice( 
                    __( 'Invalid file format. Please upload an image file in one of the supported formats.', 'hypertaxonomy' ),
                    'error' 
                );
                return;
            }
            
            // Validate exact dimensions - check against taxonomy-specific dimensions
            if ( ! $this->validate_image_dimensions( $attachment_id, $taxonomy ) ) {
                // Don't save the icon if dimensions don't match
                $dimensions = self::get_icon_dimensions( $taxonomy );

                htax_add_admin_notice(
                    sprintf(
                        __( 'Image must be at least %1$d×%2$d pixels. Please upload an image at least this size.', 'hypertaxonomy' ),
                        $dimensions['width'],
                        $dimensions['height']
                    ),
                    'error'
                );
                return;
            }
            
            // Process the icon
            $this->process_uploaded_icon( $attachment_id, $term_id );

            // Rename attachment to match term slug
            $this->rename_icon_attachment( $attachment_id, $term_id );

            update_term_meta( $term_id, '_htax_icon_id', $attachment_id );

        } else {
            delete_term_meta( $term_id, '_htax_icon_id' );
        }
        $this->clear_icon_cache( $term_id );
    }
    
    /**
     * Validate that image dimensions exactly match taxonomy settings
     *
     * @param int $attachment_id Attachment ID
     * @param string $taxonomy Taxonomy name
     * @return bool True if dimensions match exactly, false otherwise
     */
    private function validate_image_dimensions( $attachment_id, $taxonomy ) {
        // SVGs are vector — no pixel dimensions to validate.
        if ( 'image/svg+xml' === get_post_mime_type( $attachment_id ) ) {
            return true;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! $metadata || ! isset( $metadata['width'] ) || ! isset( $metadata['height'] ) ) {
            return false;
        }

        $img_width = $metadata['width'];
        $img_height = $metadata['height'];

        // Validate against the large (uploaded) size — the configured dimensions
        $dimensions = self::get_icon_dimensions( $taxonomy, 'large' );

        // Require at least the configured (large) dimensions; larger is accepted and cropped.
        return ( (int) $img_width >= (int) $dimensions['width'] && (int) $img_height >= (int) $dimensions['height'] );
    }
    
    /**
     * Process uploaded icon - regenerate thumbnails for taxonomy-specific sizes
     *
     * @param int $attachment_id Attachment ID
     * @param int $term_id Term ID
     */
    private function process_uploaded_icon( $attachment_id, $term_id ) {
        // SVGs are vector — no raster thumbnail generation needed.
        if ( 'image/svg+xml' === get_post_mime_type( $attachment_id ) ) {
            return;
        }

        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path ) {
            return;
        }

        // Get term taxonomy to determine which sizes to generate
        $term = htax_get_valid_term( $term_id );
        if ( ! $term ) {
            return;
        }

        $taxonomy = $term->taxonomy;
        $metadata = wp_get_attachment_metadata( $attachment_id );

        // Generate sizes for this taxonomy
        $icon_sizes = self::get_icon_sizes_for_taxonomy( $taxonomy );

        foreach ( $icon_sizes as $size_name => $size_data ) {
            $resized = image_make_intermediate_size(
                $file_path,
                $size_data[0],
                $size_data[1],
                $size_data[2]
            );

            if ( $resized ) {
                $metadata['sizes'][ $size_name ] = $resized;
            }
        }

        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    /**
     * Regenerate small and medium thumbnail sizes for all icons in a taxonomy.
     * Called after the small_pct or medium_pct settings change (large/original is preserved).
     *
     * @param string $taxonomy Taxonomy slug.
     * @return int Number of attachments regenerated.
     */
    public function regenerate_thumbnail_sizes( string $taxonomy ): int {
        $term_ids = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'ids',
        ) );

        if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
            return 0;
        }

        $new_sizes   = self::get_icon_sizes_for_taxonomy( $taxonomy );
        $size_keys   = array( "htax-icon-small-{$taxonomy}", "htax-icon-medium-{$taxonomy}" );
        $regenerated = 0;
        $seen_ids    = array();

        foreach ( $term_ids as $term_id ) {
            $attachment_id = (int) get_term_meta( $term_id, '_htax_icon_id', true );
            if ( ! $attachment_id || isset( $seen_ids[ $attachment_id ] ) ) {
                continue;
            }
            $seen_ids[ $attachment_id ] = true;

            $file_path = get_attached_file( $attachment_id );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                continue;
            }

            $metadata = wp_get_attachment_metadata( $attachment_id );
            if ( ! is_array( $metadata ) ) {
                $metadata = array();
            }

            // Delete old small/medium files before regenerating with new dimensions.
            foreach ( $size_keys as $size_name ) {
                if ( isset( $metadata['sizes'][ $size_name ]['file'] ) ) {
                    $old_file = path_join( dirname( $file_path ), $metadata['sizes'][ $size_name ]['file'] );
                    if ( $old_file !== $file_path && file_exists( $old_file ) ) {
                        wp_delete_file( $old_file );
                    }
                    unset( $metadata['sizes'][ $size_name ] );
                }
            }

            // Generate new small/medium at the updated percentages.
            foreach ( $size_keys as $size_name ) {
                if ( ! isset( $new_sizes[ $size_name ] ) ) {
                    continue;
                }
                $size_data = $new_sizes[ $size_name ];
                $resized   = image_make_intermediate_size( $file_path, $size_data[0], $size_data[1], $size_data[2] );
                if ( $resized ) {
                    $metadata['sizes'][ $size_name ] = $resized;
                }
            }

            wp_update_attachment_metadata( $attachment_id, $metadata );
            ++$regenerated;
        }

        return $regenerated;
    }

    /**
     * Get term icon data
     * 
     * @param int $term_id Term ID
     * @param string $size Size name
     * @param bool $include_placeholder Whether to generate placeholder color
     * @return array Icon data
     */
    public function get_term_icon_data( $term_id, $size = 'htax-icon-small', $include_placeholder = false, $taxonomy = '' ) {
        if ( ! $term_id ) {
            return array(
                'has_icon' => false,
                'url' => '',
                'placeholder_color' => $include_placeholder ? $this->get_placeholder_color( 'default' ) : '',
                'taxonomy' => $taxonomy
            );
        }

        // Check cache first
        $cache_key = 'htax_icon_' . $term_id . '_' . $size;
        $cached = wp_cache_get( $cache_key, 'hypertaxonomy' );


        if ( false !== $cached ) {
            // Ensure taxonomy is set (for old cached data)
            if ( ! isset( $cached['taxonomy'] ) ) {
                $cached['taxonomy'] = $taxonomy;
            }
            return $cached;
        }

        // Get icon attachment ID
        $attachment_id = get_term_meta( $term_id, '_htax_icon_id', true );

        $data = array(
            'has_icon' => false,
            'url' => '',
            'placeholder_color' => '',
            'taxonomy' => $taxonomy
        );

        if ( $attachment_id ) {
            $url = wp_get_attachment_image_url( $attachment_id, $size );
            if ( $url ) {
                $data['has_icon'] = true;
                $data['attachment_id'] = $attachment_id;
                $data['url'] = $url;
            }
        }

        // Generate placeholder color if requested and no icon exists
        if ( $include_placeholder && ! $data['has_icon'] ) {
            $data['placeholder_color'] = $this->get_placeholder_color( $term_id );
        }

        // Cache for 1 hour
        wp_cache_set( $cache_key, $data, 'hypertaxonomy', HOUR_IN_SECONDS );

        return $data;
    }
    
    /**
     * Generate consistent color for term based on slug
     */
    private function get_term_color( $slug ) {
        return self::get_color_for_slug( $slug );
    }

    /**
     * Return the placeholder hex color for a term slug.
     * Color is deterministic: same slug always produces the same color.
     *
     * @param string $slug Term slug.
     * @return string Hex color (e.g. '#1e88e5').
     */
    public static function get_color_for_slug( $slug ) {
        $index = abs( crc32( $slug ) ) % count( self::$color_palette );
        return self::$color_palette[ $index ];
    }
    
    /**
     * AJAX handler to get multiple term icons
     */
    public function ajax_get_term_icons() {
        // Basic security check
        if ( ! check_ajax_referer( 'htax-search-widget', 'nonce', false ) ) {
            wp_send_json_error( 'Security check failed' );
            return;
        }

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'hypertaxonomy' ) ), 403 );
        }

        $term_ids = isset( $_POST['term_ids'] ) ? array_map( 'intval', wp_unslash( (array) $_POST['term_ids'] ) ) : array();
        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : 'post_tag';
        $size = isset( $_POST['size'] ) ? sanitize_key( wp_unslash( $_POST['size'] ) ) : 'htax-icon-small';
        
        if ( empty( $term_ids ) ) {
            wp_send_json_error( 'No term IDs provided' );
            return;
        }
        
        // Get taxonomy-specific icon display setting
        $show_icons = htax_get_icon_display_for_taxonomy( $taxonomy );

        // Prime the term-meta cache once so the per-term get_term_icon_data() calls
        // below hit cache instead of querying _htax_icon_id per term (these IDs come
        // from the request, so they are not already lazy-load queued).
        update_termmeta_cache( $term_ids );

        $result = array();

        foreach ( $term_ids as $term_id ) {
            $data = $this->get_term_icon_data( $term_id, $size );
            
            if ( 'never' === $show_icons ) {
                $result[ $term_id ] = array(
                    'has_icon' => false,
                    'show_placeholder' => false
                );
            } elseif ( 'always' === $show_icons ) {
                $result[ $term_id ] = $data;
                $result[ $term_id ]['show_placeholder'] = ! $data['has_icon'];
            } else { // when_available
                $result[ $term_id ] = $data;
                $result[ $term_id ]['show_placeholder'] = false;
            }
        }
        
        wp_send_json_success( $result );
    }
    
    /**
     * Cleanup icon when term is deleted
     */
    public function htax_cleanup_term_icon( $term_id, $tt_id, $taxonomy ) {
        // Get the attachment ID before deletion
        $attachment_id = get_term_meta( $term_id, '_htax_icon_id', true );

        if ( $attachment_id ) {
            htax_log_debug( 'Starting term icon cleanup', array(
                'term_id' => $term_id,
                'taxonomy' => $taxonomy,
                'attachment_id' => $attachment_id
            ) );

            $other_users = get_terms( array(
                'meta_key'   => '_htax_icon_id',
                'meta_value' => (string) $attachment_id,
                'fields'     => 'ids',
                'hide_empty' => false,
                'exclude'    => array( $term_id ),
                'number'     => 1,
            ) );

            $deleted = false;
            if ( ! empty( $other_users ) && ! is_wp_error( $other_users ) ) {
                // Another term uses this attachment — skip deletion.
            } else {
                $deleted = wp_delete_attachment( $attachment_id, true );
            }

            // Clear attachment URL cache for this taxonomy's sizes
            $this->clear_attachment_url_caches( $attachment_id, $taxonomy );

            htax_log_debug( 'Completed term icon cleanup', array(
                'term_id' => $term_id,
                'taxonomy' => $taxonomy,
                'attachment_deleted' => ! empty( $deleted ),
                'cleared_size_caches' => count( self::get_icon_sizes_for_taxonomy( $taxonomy ) ),
            ) );
        }

        // Delete the term meta
        delete_term_meta( $term_id, '_htax_icon_id' );

        // Clear term icon cache
        $this->clear_icon_cache( $term_id );
    }

    /**
     * AJAX handler: crop an image to the configured icon dimensions and create
     * a new media attachment from the result.
     *
     * Expects POST: id (attachment ID), x/y/w/h (crop rect in original px),
     *               dst_w/dst_h (output dimensions), nonce.
     */
    public function ajax_crop_term_icon() {
        check_ajax_referer( 'htax_crop_term_icon', 'nonce' );

        if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_icons' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hypertaxonomy' ) ) );
        }

        $attachment_id = absint( wp_unslash( $_POST['id']    ?? 0 ) );
        $x             = absint( wp_unslash( $_POST['x']     ?? 0 ) );
        $y             = absint( wp_unslash( $_POST['y']     ?? 0 ) );
        $w             = absint( wp_unslash( $_POST['w']     ?? 0 ) );
        $h             = absint( wp_unslash( $_POST['h']     ?? 0 ) );
        $dst_w         = absint( wp_unslash( $_POST['dst_w'] ?? 0 ) );
        $dst_h         = absint( wp_unslash( $_POST['dst_h'] ?? 0 ) );

        if ( ! $attachment_id || ! $w || ! $h || ! $dst_w || ! $dst_h ) {
            wp_send_json_error( array( 'message' => __( 'Invalid crop parameters.', 'hypertaxonomy' ) ) );
        }

        if ( ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'hypertaxonomy' ) ) );
        }

        $cropped = wp_crop_image( $attachment_id, $x, $y, $w, $h, $dst_w, $dst_h );
        if ( is_wp_error( $cropped ) ) {
            htax_log_error( 'Icon crop failed for attachment ' . $attachment_id . ': ' . $cropped->get_error_message() );
            wp_send_json_error( array( 'message' => __( 'Crop failed. Please try again.', 'hypertaxonomy' ) ) );
        }

        $new_id = $this->create_attachment_from_cropped_file( $cropped, $attachment_id );
        if ( is_wp_error( $new_id ) ) {
            htax_log_error( 'Icon crop attachment creation failed: ' . $new_id->get_error_message() );
            wp_send_json_error( array( 'message' => __( 'Crop failed. Please try again.', 'hypertaxonomy' ) ) );
        }

        $thumb_url = wp_get_attachment_image_url( $new_id, 'thumbnail' )
            ?: wp_get_attachment_url( $new_id );

        wp_send_json_success( array(
            'id'            => $new_id,
            'url'           => wp_get_attachment_url( $new_id ),
            'thumbnail_url' => $thumb_url,
        ) );
    }

    /**
     * Register a cropped file as a child media attachment and generate sizes.
     *
     * @param string $file_path Absolute path returned by wp_crop_image().
     * @param int    $parent_id Original attachment ID (used as post_parent).
     * @return int|WP_Error New attachment ID or error.
     */
    private function create_attachment_from_cropped_file( $file_path, $parent_id ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $mime = wp_check_filetype( basename( $file_path ), null );
        if ( ! $mime['type'] ) {
            return new WP_Error( 'invalid_mime', __( 'Could not determine the file format of the cropped image.', 'hypertaxonomy' ) );
        }

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => $mime['type'],
                'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_path ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $parent_id,
            ),
            $file_path,
            $parent_id
        );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata( $attachment_id, $file_path )
        );

        return $attachment_id;
    }

    /**
     * AJAX handler to delete all icons for a taxonomy
     */
    public function ajax_delete_all_icons() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'htax-delete-all-icons' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'hypertaxonomy' ) ) );
            return;
        }

        // Check capabilities
        if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_hypertaxonomy_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'hypertaxonomy' ) ) );
            return;
        }

        // Get taxonomy
        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
        if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid taxonomy.', 'hypertaxonomy' ) ) );
            return;
        }

        htax_log_debug( 'Starting bulk icon deletion', array( 'taxonomy' => $taxonomy ) );

        // Get all terms with icons for this taxonomy
        $terms = get_terms( array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'meta_query' => array(
                array(
                    'key' => '_htax_icon_id',
                    'compare' => 'EXISTS'
                )
            )
        ) );

        if ( is_wp_error( $terms ) ) {
            htax_log_error( 'Failed to get terms for icon deletion', array(
                'taxonomy' => $taxonomy,
                'error' => $terms->get_error_message()
            ) );
            wp_send_json_error( array( 'message' => __( 'Failed to retrieve terms.', 'hypertaxonomy' ) ) );
            return;
        }

        $deleted_count = 0;
        $attachment_count = 0;

        // Prime the WP object cache so each get_term_meta() below is a cache hit.
        update_meta_cache( 'term', wp_list_pluck( $terms, 'term_id' ) );

        foreach ( $terms as $term ) {
            $attachment_id = get_term_meta( $term->term_id, '_htax_icon_id', true );

            if ( $attachment_id ) {
                $other_users = get_terms( array(
                    'meta_key'   => '_htax_icon_id',
                    'meta_value' => (string) $attachment_id,
                    'fields'     => 'ids',
                    'hide_empty' => false,
                    'exclude'    => array( $term->term_id ),
                    'number'     => 1,
                ) );

                if ( ! empty( $other_users ) && ! is_wp_error( $other_users ) ) {
                    // Another term uses this attachment — skip deletion.
                } else {
                    $deleted = wp_delete_attachment( $attachment_id, true );
                    if ( $deleted ) {
                        $attachment_count++;
                    }
                }

                // Clear attachment URL cache for this taxonomy's sizes
                $this->clear_attachment_url_caches( $attachment_id, $taxonomy );
            }

            // Delete the term meta
            delete_term_meta( $term->term_id, '_htax_icon_id' );

            // Clear term icon cache
            $this->clear_icon_cache( $term->term_id );

            $deleted_count++;
        }

        htax_log_debug( 'Completed bulk icon deletion', array(
            'taxonomy' => $taxonomy,
            'terms_processed' => $deleted_count,
            'attachments_deleted' => $attachment_count
        ) );

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: number of icons deleted */
                _n( 'Successfully deleted %d icon.', 'Successfully deleted %d icons.', $deleted_count, 'hypertaxonomy' ),
                $deleted_count
            ),
            'deleted_count' => $deleted_count
        ) );
    }

    /**
     * Generate unique icon filename based on term slug
     *
     * Attempts to create a unique filename following this priority:
     * 1. slug.ext (e.g., "php.png")
     * 2. slug-taxonomy.ext (e.g., "php-tag.png")
     * 3. slug-taxonomy-N.ext (e.g., "php-tag-2.png") where N starts at 2
     *
     * @param string $slug Term slug
     * @param string $taxonomy Taxonomy name
     * @param string $extension File extension (e.g., "png", "svg")
     * @return string Unique filename
     */
    public static function generate_unique_icon_filename( $slug, $taxonomy, $extension ) {
        // Sanitize inputs
        $slug = sanitize_file_name( $slug );
        $extension = strtolower( sanitize_file_name( $extension ) );

        // Get uploads directory
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // Try 1: slug.ext
        $filename = $slug . '.' . $extension;
        if ( ! self::file_exists_in_uploads( $filename, $base_dir ) ) {
            return $filename;
        }

        // Try 2: slug-taxonomy.ext (use taxonomy singular label)
        $tax_obj = get_taxonomy( $taxonomy );
        $tax_suffix = $tax_obj && isset( $tax_obj->labels->singular_name )
            ? sanitize_file_name( strtolower( $tax_obj->labels->singular_name ) )
            : sanitize_file_name( $taxonomy );

        $filename = $slug . '-' . $tax_suffix . '.' . $extension;
        if ( ! self::file_exists_in_uploads( $filename, $base_dir ) ) {
            return $filename;
        }

        // Try 3: slug-taxonomy-N.ext (starting with 2)
        $counter = 2;
        while ( $counter < 100 ) { // Safety limit
            $filename = $slug . '-' . $tax_suffix . '-' . $counter . '.' . $extension;
            if ( ! self::file_exists_in_uploads( $filename, $base_dir ) ) {
                return $filename;
            }
            $counter++;
        }

        // Fallback: append timestamp if we somehow hit the limit
        return $slug . '-' . $tax_suffix . '-' . time() . '.' . $extension;
    }

    /**
     * Check if a file exists anywhere in the uploads directory tree
     *
     * Searches recursively through uploads directory for a matching filename.
     *
     * @param string $filename Filename to search for
     * @param string $base_dir Base uploads directory path
     * @return bool True if file exists, false otherwise
     */
    private static function file_exists_in_uploads( $filename, $base_dir ) {
        global $wpdb;

        // Query postmeta for any attachment with this filename
        // This is more reliable than filesystem checks since WP organizes by date
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             AND meta_value LIKE %s",
            '%' . $wpdb->esc_like( $filename )
        ) );

        return ( $exists > 0 );
    }

    /**
     * Handle slug changes - rename icon attachment if slug changed
     *
     * Called on edited_term hook to detect slug changes and rename icon accordingly.
     *
     * @param int    $term_id  Term ID
     * @param int    $tt_id    Term taxonomy ID
     * @param string $taxonomy Taxonomy slug
     */
    public function handle_slug_change( $term_id, $tt_id, $taxonomy ) {
        // Only process if icons enabled for this taxonomy
        if ( ! htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
            return;
        }

        // Check if term has an icon
        $icon_id = get_term_meta( $term_id, '_htax_icon_id', true );
        if ( ! $icon_id ) {
            return;
        }

        // Get old slug from transient (set by htax_cache_term_taxonomy_before_edit)
        // We can't use that same transient since it's taxonomy-focused, so we need our own
        $old_slug_key = 'htax_old_slug_' . $term_id;
        $old_slug = htax_get_transient( $old_slug_key );

        // Get current term to compare slugs
        $term = htax_get_valid_term( $term_id );
        if ( ! $term ) {
            return;
        }

        // If we don't have old slug cached, can't detect change
        // This is OK - rename will happen on next edit
        if ( ! $old_slug ) {
            // Cache current slug for next time
            htax_set_transient( $old_slug_key, $term->slug, 300 ); // 5 minutes
            return;
        }

        // Clean up transient
        htax_delete_transient( $old_slug_key );

        // Check if slug actually changed
        if ( $old_slug === $term->slug ) {
            // No change - just re-cache for next edit
            htax_set_transient( $old_slug_key, $term->slug, 300 );
            return;
        }

        // Slug changed - rename the icon attachment
        $this->rename_icon_attachment( $icon_id, $term_id, $old_slug );

        // Cache new slug for next edit
        htax_set_transient( $old_slug_key, $term->slug, 300 );
    }

    /**
     * Rename an existing attachment file to match term slug
     *
     * Updates both the file on disk and all WordPress metadata references.
     *
     * @param int    $attachment_id Attachment ID to rename
     * @param int    $term_id       Term ID (for getting slug and taxonomy)
     * @param string $old_slug      Old term slug (optional, for comparison)
     * @return bool True on success, false on failure
     */
    public function rename_icon_attachment( $attachment_id, $term_id, $old_slug = '' ) {
        // Get term details
        $term = htax_get_valid_term( $term_id );
        if ( ! $term ) {
            return false;
        }

        // Get current file path
        $old_file_path = get_attached_file( $attachment_id );
        if ( ! $old_file_path || ! file_exists( $old_file_path ) ) {
            return false;
        }

        // Get file info
        $path_info = pathinfo( $old_file_path );
        $extension = isset( $path_info['extension'] ) ? $path_info['extension'] : 'png';
        $upload_dir = wp_upload_dir();

        // Generate new unique filename
        $new_filename = self::generate_unique_icon_filename( $term->slug, $term->taxonomy, $extension );

        // If filename hasn't changed, no need to rename
        $old_filename = basename( $old_file_path );
        if ( $old_filename === $new_filename ) {
            return true; // Already correctly named
        }

        // Build new file path (same directory, new filename)
        $new_file_path = $path_info['dirname'] . '/' . $new_filename;

        // Rename the main file
        if ( ! rename( $old_file_path, $new_file_path ) ) {
            return false;
        }

        // Update attachment metadata
        $metadata = wp_get_attachment_metadata( $attachment_id );

        // Rename all size variations
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! isset( $size_data['file'] ) ) {
                    continue;
                }

                $old_size_file = $path_info['dirname'] . '/' . $size_data['file'];

                // Generate new size filename (preserves dimension suffix)
                // e.g., "old-24x24.png" → "new-24x24.png"
                $size_path_info = pathinfo( $size_data['file'] );
                $size_basename = $size_path_info['filename'];

                // Extract dimension suffix if present (e.g., "-24x24")
                if ( preg_match( '/-(\d+x\d+)$/', $size_basename, $matches ) ) {
                    $dimension_suffix = '-' . $matches[1];
                    $new_size_filename = str_replace( '.' . $extension, '', $new_filename ) . $dimension_suffix . '.' . $extension;
                } else {
                    $new_size_filename = $new_filename;
                }

                $new_size_file = $path_info['dirname'] . '/' . $new_size_filename;

                // Rename size variation file; only update metadata if rename succeeded
                if ( file_exists( $old_size_file ) && ! rename( $old_size_file, $new_size_file ) ) {
                    continue;
                }

                // Update metadata
                $metadata['sizes'][ $size_name ]['file'] = $new_size_filename;
            }
        }

        // Update the metadata's own 'file' key to reflect the new path.
        // This key stores the year/month-relative path (e.g. "2026/05/gap-gift-cards.png")
        // and is used by wp_get_attachment_image_src to resolve intermediate size URLs.
        $upload_basedir = trailingslashit( $upload_dir['basedir'] );
        $relative_new   = str_replace( $upload_basedir, '', $new_file_path );
        if ( $relative_new && $relative_new !== $new_file_path ) {
            $metadata['file'] = $relative_new;
        }

        // Update file metadata in database
        update_attached_file( $attachment_id, $new_file_path );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // Clear caches
        clean_attachment_cache( $attachment_id );
        $this->clear_icon_cache( $term_id );

        return true;
    }

    /**
     * Enqueue admin scripts for icon upload
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
            return;
        }

        $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
        if ( ! htax_is_taxonomy_enabled( $taxonomy ) || ! htax_taxonomy_has_feature( $taxonomy, 'icons' ) ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script( 'imgareaselect' );
        wp_enqueue_style( 'imgareaselect' );

        wp_enqueue_style(
            'htax-term-icons-css',
            HTAX_PLUGIN_URL . 'assets/css/htax-term-icons.css',
            array(),
            HTAX_VERSION
        );
        wp_enqueue_script(
            'htax-term-icons-js',
            HTAX_PLUGIN_URL . 'assets/js/htax-term-icons.js',
            array( 'jquery', 'imgareaselect', 'media-editor' ),
            HTAX_VERSION,
            true
        );

        $dims       = self::get_icon_dimensions( $taxonomy, 'large' );
        $dims_small = self::get_icon_dimensions( $taxonomy, 'small' );
        wp_localize_script(
            'htax-term-icons-js',
            'HTAX_Icons',
            array(
                'upload_title'       => __( 'Select Icon', 'hypertaxonomy' ),
                'upload_button'      => __( 'Use as icon', 'hypertaxonomy' ),
                /* translators: %s: singular taxonomy name, e.g. "tag" or "category" */
                'remove_confirm'     => sprintf( __( 'Remove this icon? The image will remain in the Media Library, but will no longer be associated with this %s.', 'hypertaxonomy' ), mb_strtolower( get_taxonomy( $taxonomy )->labels->singular_name ?? __( 'term', 'hypertaxonomy' ), 'UTF-8' ) ),
                'crop_title'         => __( 'Crop Icon', 'hypertaxonomy' ),
                'crop_button'        => __( 'Crop & use', 'hypertaxonomy' ),
                'crop_cancel'        => __( 'Cancel', 'hypertaxonomy' ),
                'crop_saving'        => __( 'Saving…', 'hypertaxonomy' ),
                'crop_error'         => __( 'Crop failed. Please try again.', 'hypertaxonomy' ),
                /* translators: 1: required width px, 2: required height px */
                'too_small'          => sprintf(
                    __( 'Image is too small. Required minimum: %1$d×%2$d px. Please upload a larger image.', 'hypertaxonomy' ),
                    $dims['width'],
                    $dims['height']
                ),
                /* translators: 1: SVG natural width, 2: SVG natural height, 3: configured icon width, 4: configured icon height */
                'svg_ratio_warning'  => __( 'This SVG\'s natural size is %1$d×%2$d px, but the icon slot is %3$d×%4$d px — the proportions differ. It may appear stretched or letterboxed. Use it anyway?', 'hypertaxonomy' ),
                /* translators: 1: required width px, 2: required height px */
                'crop_instructions'  => sprintf(
                    __( 'Drag the selection to choose which %1$d×%2$d px region to use. The selection size is fixed — only its position can be moved.', 'hypertaxonomy' ),
                    $dims['width'],
                    $dims['height']
                ),
                'target_w'           => $dims['width'],
                'target_h'           => $dims['height'],
                'preview_w'          => $dims_small['width'],
                'preview_h'          => $dims_small['height'],
                'taxonomy'           => $taxonomy,
                'upload_label'       => __( 'Upload Icon', 'hypertaxonomy' ),
                'replace_label'      => __( 'Replace Icon', 'hypertaxonomy' ),
                'nonce'              => wp_create_nonce( 'htax_crop_term_icon' ),
                'ajax_url'           => admin_url( 'admin-ajax.php' ),
            )
        );
    }
}

// Initialize the module
HyperTaxonomy_Term_Icons::get_instance();