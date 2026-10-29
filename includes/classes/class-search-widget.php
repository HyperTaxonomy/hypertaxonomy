<?php
/**
 * HyperTaxonomy Advanced Search Widget System
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_Search_Widget {

    /**
     * Singleton instance
     */
    private static $instance = null;

    private static $instance_counter = 0;
    private static $forms = array();
    private static $current_controller = null;
    private static $current_option = null;
    private static $current_group = null;
    private static $current_form_id = null;

    /** @var bool Whether assets should be printed in the footer via print_deferred_assets(). */
    private $should_load_assets = false;

    /** @var bool Whether enqueue_assets() has already run during this request. */
    private $assets_enqueued = false;

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
        // Register shortcodes
        add_shortcode( 'ht_search_form', array( $this, 'form_shortcode' ) );
        add_shortcode( 'ht_pills_field',     array( $this, 'pills_field_shortcode' ) );
        add_shortcode( 'ht_checklist_field', array( $this, 'checklist_field_shortcode' ) );
        add_shortcode( 'ht_hidden_field',    array( $this, 'hidden_field_shortcode' ) );
        add_shortcode( 'ht_text_field',      array( $this, 'text_field_shortcode' ) );
        add_shortcode( 'ht_field_group', array( $this, 'field_group_shortcode' ) );
        add_shortcode( 'ht_search_controller', array( $this, 'controller_shortcode' ) );
        add_shortcode( 'ht_search_option', array( $this, 'option_shortcode' ) );
        add_shortcode( 'ht_option_action', array( $this, 'option_action_shortcode' ) );
        add_shortcode( 'ht_search_submit', array( $this, 'submit_shortcode' ) );
        add_shortcode( 'ht_search_toggle', array( $this, 'toggle_shortcode' ) );
        add_shortcode( 'ht_search_results', array( $this, 'results_shortcode' ) );
        
        // Frontend assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        add_action( 'wp_footer', array( $this, 'print_deferred_assets' ), 999 );

        // AJAX handlers
        add_action( 'wp_ajax_htax_search_posts', array( $this, 'ajax_search_posts' ) );
        add_action( 'wp_ajax_nopriv_htax_search_posts', array( $this, 'ajax_search_posts' ) );
        add_action( 'wp_ajax_htax_prefetch_options', array( $this, 'ajax_prefetch_options' ) );
        add_action( 'wp_ajax_nopriv_htax_prefetch_options', array( $this, 'ajax_prefetch_options' ) );
        add_action( 'wp_ajax_htax_autocomplete_options', array( $this, 'ajax_autocomplete_options' ) );
        add_action( 'wp_ajax_nopriv_htax_autocomplete_options', array( $this, 'ajax_autocomplete_options' ) );
    }

    /**
     * Main form container shortcode
     */
    public function form_shortcode( $atts, $content = null ) {
        $defaults = array(
            'id' => 'htax-search-' . ++self::$instance_counter,
            'state' => 'collapsed', // collapsed or expanded
            'ajax' => '', // ID of results container for AJAX mode; if empty, form submits via POST
            'action' => '',
            'class' => '',
            'orderby' => 'relevance:desc,date:desc', // Format: field:order,field:order or just field,field (defaults to DESC)
            'min_score' => '', // Minimum overall relevance score (empty = auto: taxonomies act as filters)
            'posts_per_page' => htax_get_search_results_per_page(),
            'max_results' => 0, // Hard cap across all pages (0 = no cap)
        );

        $atts = shortcode_atts( $defaults, $atts );

        // Determine if AJAX mode
        // ajax="" (empty) → POST submission
        // ajax="results-container-id" → AJAX with specific container
        $is_ajax = ! empty( $atts['ajax'] );
        $results_id = $is_ajax ? $atts['ajax'] : '';

        // Ensure assets are enqueued when shortcode is rendered
        // Mark that we need to load assets
        $this->should_load_assets = true;
        $this->enqueue_assets();

        // Set current form context for field validation
        self::$current_form_id = $atts['id'];

        // Store form configuration
        self::$forms[ $atts['id'] ] = array(
            'atts' => $atts,
            'fields' => array(),
            'controllers' => array(),
        );

        $classes = array( 'htax-search-form' );
        $classes[] = 'htax-state-' . $atts['state'];
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }

        $output = '<form class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '" ';
        $output .= 'data-state="' . esc_attr( $atts['state'] ) . '" ';
        $output .= 'data-ajax="' . esc_attr( $is_ajax ? 'true' : 'false' ) . '" ';
        $output .= 'data-orderby="' . esc_attr( $atts['orderby'] ) . '" ';
        $output .= 'data-posts-per-page="' . max( 1, intval( $atts['posts_per_page'] ) ) . '" ';
        $output .= 'data-max-results="' . max( 0, intval( $atts['max_results'] ) ) . '" ';
        if ( '' !== $atts['min_score'] ) {
            $output .= 'data-min-score="' . esc_attr( $atts['min_score'] ) . '" ';
        }
        if ( ! empty( $results_id ) ) {
            $output .= 'data-results="' . esc_attr( $results_id ) . '" ';
        }

        // For non-AJAX forms, set method and action
        if ( ! $is_ajax ) {
            // Always set method to POST
            $output .= 'method="post" ';
            // Set action URL if specified, otherwise POST to current page
            if ( ! empty( $atts['action'] ) ) {
                $output .= 'action="' . esc_url( $atts['action'] ) . '" ';
            }
        }

        $output .= '>';

        // Add nonce field
        $output .= wp_nonce_field( 'htax-search-widget', 'htax_search_nonce', true, false );

        // Hidden fields for POST submissions: identify search and carry all form-level config
        if ( ! $is_ajax ) {
            $output .= '<input type="hidden" name="htax_post_search" value="1" />';
            $ui_state = isset( $_POST['htax_post_search'], $_POST['htax_ui_state'] )
                ? wp_unslash( $_POST['htax_ui_state'] )
                : '';
            $output .= '<input type="hidden" name="htax_ui_state" value="' . esc_attr( $ui_state ) . '" />';
            $output .= '<input type="hidden" name="htax_orderby" value="' . esc_attr( $atts['orderby'] ) . '" />';
            $output .= '<input type="hidden" name="htax_posts_per_page" value="' . max( 1, intval( $atts['posts_per_page'] ) ) . '" />';
            $output .= '<input type="hidden" name="htax_max_results" value="' . max( 0, intval( $atts['max_results'] ) ) . '" />';
            if ( '' !== $atts['min_score'] ) {
                $output .= '<input type="hidden" name="htax_min_score" value="' . esc_attr( $atts['min_score'] ) . '" />';
            }
        }

        // Process nested shortcodes (field groups are paired by depth — see render_form_content()).
        $output .= $this->render_form_content( $content );
        $output .= $this->render_group_members_map( $atts['id'] );

        // Generate validation warnings if any
        $validation_warnings = $this->get_validation_warnings( $atts['id'] );
        if ( ! empty( $validation_warnings ) ) {
            $output .= '<div class="htax-validation-warnings" style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin: 10px 0; border-radius: 4px;">';
            foreach ( $validation_warnings as $warning ) {
                $output .= '<p style="margin: 5px 0; color: #856404;"><strong>⚠ HyperTaxonomy:</strong> ' . esc_html( $warning ) . '</p>';
            }
            $output .= '</div>';
        }

        $output .= '</form>';

        // Clear form context
        self::$current_form_id = null;

        return $output;
    }
    
    /**
     * Defaults shared by pills and checklist fields.
     */
    private function base_field_defaults(): array {
        return array(
            'id'                => '',
            'name'              => '',
            'taxonomy'          => 'post_tag',
            'label'             => '',
            'show_when'         => 'always',
            'enabled'           => 'true',
            'match'             => 'any',
            'min_items'         => '0',
            'max_items'         => '0',
            'min_items_message' => '',
            'max_items_message' => '',
            'class'             => '',
            'expand'            => 'all',
        );
    }

    /**
     * Fold the add_groups / remove_groups selection attributes into add_terms / remove_terms.
     *
     * In post mode a group stands for its member terms in the field's taxonomy; in term
     * mode (taxonomy_type="term") the options are groups, so the IDs are used as-is.
     * The *_groups attributes are cleared afterwards — selection is carried by *_terms only.
     *
     * @since 1.0.0
     * @param array $atts Parsed field attributes.
     * @return array
     */
    private function fold_group_selection_atts( array $atts ): array {
        $term_mode = 'term' === ( $atts['taxonomy_type'] ?? 'post' );
        foreach ( array( 'add', 'remove' ) as $op ) {
            if ( empty( $atts[ $op . '_groups' ] ) ) {
                continue;
            }
            $group_ids = htax_parse_term_ids( $atts[ $op . '_groups' ], 'group' );
            $ids       = $term_mode ? $group_ids : array_keys( htax_get_terms_in_groups( $group_ids, $atts['taxonomy'] ) );
            $existing  = ! empty( $atts[ $op . '_terms' ] ) ? htax_parse_term_ids( $atts[ $op . '_terms' ], $atts['taxonomy'] ) : array();

            $atts[ $op . '_terms' ]  = implode( ',', array_unique( array_merge( $existing, array_map( 'intval', $ids ) ) ) );
            $atts[ $op . '_groups' ] = '';
        }
        return $atts;
    }

    /**
     * Render the member terms of the groups that controller actions name, per target field.
     *
     * Controller add_groups / remove_groups / include_group_options / exclude_group_options
     * actions carry group IDs only; pills and checklist fields resolve them in the browser
     * from this map, rendered once after all fields and controllers are known. Post mode:
     * a group's member terms in the field's taxonomy (descendant groups included); term
     * mode: the group itself. Pills also get names/icons for the members, since a pill may
     * be added for a term the field hasn't prefetched. Hidden fields resolve groups from
     * their own pool map and are not included. Hidden terms are left out for viewers who
     * can't see them.
     *
     * @since 1.0.0
     * @param string $form_id Form ID.
     * @return string JSON script element, or '' when no field needs one.
     */
    private function render_group_members_map( string $form_id ): string {
        $actions = self::$forms[ $form_id ]['group_actions'] ?? array();
        $fields  = self::$forms[ $form_id ]['fields'] ?? array();
        $hidden  = htax_can_see_hidden_terms() ? array() : array_flip( htax_get_all_hidden_term_ids() );
        $map     = array();

        foreach ( $actions as $target => $group_ids ) {
            $field = $fields[ $target ] ?? null;
            if ( ! $field || ! in_array( $field['type'] ?? '', array( 'pills', 'checklist' ), true ) ) {
                continue;
            }
            $term_mode = 'term' === ( $field['taxonomy_type'] ?? 'post' );
            $entry     = array( 'groups' => array(), 'terms' => array() );
            $all_ids   = array();
            foreach ( array_unique( array_map( 'intval', $group_ids ) ) as $gid ) {
                $ids = $term_mode
                    ? array( $gid )
                    : array_map( 'intval', array_keys( htax_get_terms_in_groups( array( $gid ), $field['taxonomy'] ) ) );
                $ids = array_values( array_filter( $ids, static fn( $id ) => ! isset( $hidden[ $id ] ) ) );

                $entry['groups'][ $gid ] = $ids;
                $all_ids                 = array_merge( $all_ids, $ids );
            }

            if ( 'pills' === $field['type'] && $all_ids ) {
                $terms = get_terms( array(
                    'taxonomy'   => $field['taxonomy'],
                    'include'    => array_values( array_unique( $all_ids ) ),
                    'hide_empty' => false,
                ) );
                if ( ! is_wp_error( $terms ) && $terms ) {
                    $entry['terms'] = $this->build_terms_data( $terms, $field['taxonomy'], ! empty( $field['show_icons'] ) ? $field['show_icons'] : 'never' );
                }
            }
            $map[ $target ] = $entry;
        }

        if ( empty( $map ) ) {
            return '';
        }
        return '<script type="application/json" class="htax-group-members">'
            . wp_json_encode( $map, JSON_HEX_TAG | JSON_HEX_AMP )
            . '</script>';
    }

    /**
     * Shared post-parse processing: ID generation, depth resolution, form registration.
     */
    private function process_base_field_atts( array $atts ): array {
        if ( empty( $atts['id'] ) ) {
            $atts['id'] = 'htax-field-' . sanitize_key( $atts['name'] ) . '-' . self::$instance_counter;
        }
        if ( isset( $atts['max_depth'] ) && intval( $atts['max_depth'] ) === -1 ) {
            $options           = get_option( 'htax_settings', array() );
            $atts['max_depth'] = isset( $options['max_depth'] ) ? intval( $options['max_depth'] ) : -1;
        }
        $form_id = $this->get_current_form_id();
        if ( $form_id ) {
            self::$forms[ $form_id ]['fields'][ $atts['name'] ] = $atts;
        }
        if ( isset( $atts['enabled'] ) ) {
            $atts['enabled'] = $this->get_saved_enabled( 'field', $atts['name'], $atts['enabled'] );
        }
        return $atts;
    }

    /**
     * Pills field shortcode [ht_pills_field]
     */
    public function pills_field_shortcode( $atts ) {
        $defaults = array_merge( $this->base_field_defaults(), array(
            'placeholder'                => '',
            // Option pool
            'include_term_options'          => '',
            'include_group_options'         => '',
            'include_descendant_options'    => '',
            'exclude_term_options'          => '',
            'exclude_group_options'         => '',
            'exclude_descendant_options'    => '',
            'filterby'                      => '',
            'taxonomy_type'                 => 'post',
            // Initial selection
            'add_terms'                     => '',
            'add_groups'                    => '',
            'add_descendants'               => '',
            'remove_terms'                  => '',
            'remove_groups'                 => '',
            'remove_descendants'            => '',
            // Display
            'show_count'                    => 'false',
            'show_icons'                    => '',
            'max_rows'                      => 0,
            'prefetch'                      => 'true',
            'suggest_on_focus'              => 'true',
        ) );
        $atts         = shortcode_atts( $defaults, $atts, 'ht_pills_field' );
        $atts['type'] = 'pills';
        $atts         = $this->process_base_field_atts( $atts );
        $atts         = $this->fold_group_selection_atts( $atts );
        return $this->render_pills_field( $atts );
    }

    /**
     * Checklist field shortcode [ht_checklist_field]
     */
    public function checklist_field_shortcode( $atts ) {
        $defaults = array_merge( $this->base_field_defaults(), array(
            'placeholder'                   => '',
            // Option pool
            'include_term_options'          => '',
            'include_group_options'         => '',
            'include_descendant_options'    => '',
            'exclude_term_options'          => '',
            'exclude_group_options'         => '',
            'exclude_descendant_options'    => '',
            'filterby'                      => '',
            'taxonomy_type'                 => 'post',
            // Initial selection
            'add_terms'                  => '',
            'add_groups'                 => '',
            'add_descendants'            => '',
            'remove_terms'               => '',
            'remove_groups'              => '',
            'remove_descendants'         => '',
            // Display
            'show_count'                 => 'false',
            'show_icons'                 => '',
            'hierarchical'               => 'true',
            'max_depth'                  => -1,
            'max_group_depth'            => -1,
            'max_rows'                   => 0,
        ) );
        $atts         = shortcode_atts( $defaults, $atts, 'ht_checklist_field' );
        $atts['type'] = 'checklist';
        $atts         = $this->process_base_field_atts( $atts );
        $atts         = $this->fold_group_selection_atts( $atts );
        return $this->render_checklist_field( $atts );
    }

    /**
     * Hidden field shortcode [ht_hidden_field]
     */
    public function hidden_field_shortcode( $atts ) {
        $defaults = array(
            'id'                         => '',
            'name'                       => '',
            'taxonomy'                   => 'post_tag',
            'taxonomy_type'              => 'post',
            'match'                      => 'any',
            'filterby'                   => '',
            'include_group_options'      => '',
            'include_descendant_options' => '',
            'include_term_options'       => '',
            'exclude_term_options'       => '',
            'exclude_group_options'      => '',
            'exclude_descendant_options' => '',
            'add_terms'                  => '',
            'add_groups'                 => '',
            'add_descendants'            => '',
            'remove_terms'               => '',
            'remove_groups'              => '',
            'remove_descendants'         => '',
            'min_items'                  => '0',
            'max_items'                  => '0',
            'min_items_message'          => '',
            'max_items_message'          => '',
            'expand'                     => 'all',
        );
        $atts         = shortcode_atts( $defaults, $atts, 'ht_hidden_field' );
        $atts['type'] = 'hidden';
        $atts         = $this->process_base_field_atts( $atts );

        // Expand *_descendants → descendant group IDs → fold into *_groups.
        if ( ! empty( $atts['add_descendants'] ) ) {
            $branch_ids         = htax_parse_term_ids( $atts['add_descendants'], 'group' );
            $desc_ids           = array_values( array_diff( htax_get_group_hierarchy( $branch_ids ), $branch_ids ) );
            $existing           = ! empty( $atts['add_groups'] ) ? htax_parse_term_ids( $atts['add_groups'], 'group' ) : array();
            $atts['add_groups'] = implode( ',', array_unique( array_merge( $existing, $desc_ids ) ) );
        }
        if ( ! empty( $atts['remove_descendants'] ) ) {
            $branch_ids            = htax_parse_term_ids( $atts['remove_descendants'], 'group' );
            $desc_ids              = array_values( array_diff( htax_get_group_hierarchy( $branch_ids ), $branch_ids ) );
            $existing              = ! empty( $atts['remove_groups'] ) ? htax_parse_term_ids( $atts['remove_groups'], 'group' ) : array();
            $atts['remove_groups'] = implode( ',', array_unique( array_merge( $existing, $desc_ids ) ) );
        }
        if ( ! empty( $atts['exclude_descendant_options'] ) ) {
            $branch_ids                  = htax_parse_term_ids( $atts['exclude_descendant_options'], 'group' );
            $desc_ids                    = array_values( array_diff( htax_get_group_hierarchy( $branch_ids ), $branch_ids ) );
            $existing                    = ! empty( $atts['exclude_group_options'] ) ? htax_parse_term_ids( $atts['exclude_group_options'], 'group' ) : array();
            $atts['exclude_group_options'] = implode( ',', array_unique( array_merge( $existing, $desc_ids ) ) );
        }

        $atts = $this->fold_group_selection_atts( $atts );

        // Build pool groups map (rendered inline; JS resolves controller group actions from it).
        // Group exclusions are resolved server-side: excluded groups are simply absent from the map.
        $pool_group_ids = ! empty( $atts['include_group_options'] )
            ? htax_parse_term_ids( $atts['include_group_options'], 'group' )
            : array();
        if ( ! empty( $atts['include_descendant_options'] ) ) {
            $root_ids       = htax_parse_term_ids( $atts['include_descendant_options'], 'group' );
            $desc_ids       = array_values( array_diff( htax_get_group_hierarchy( $root_ids ), $root_ids ) );
            $pool_group_ids = array_unique( array_merge( $pool_group_ids, $desc_ids ) );
        }
        if ( ! empty( $atts['exclude_group_options'] ) ) {
            $exclude_gids   = htax_parse_term_ids( $atts['exclude_group_options'], 'group' );
            $pool_group_ids = array_values( array_diff( $pool_group_ids, $exclude_gids ) );
        }

        $exclude_term_ids = ! empty( $atts['exclude_term_options'] )
            ? array_flip( htax_parse_term_ids( $atts['exclude_term_options'], $atts['taxonomy'] ) )
            : array();

        $groups_map = array();
        foreach ( $pool_group_ids as $gid ) {
            $member_terms = htax_get_terms_in_groups( array( $gid ), $atts['taxonomy'] );
            if ( ! empty( $member_terms ) ) {
                $member_ids = array_map( 'intval', array_keys( $member_terms ) );
                if ( ! empty( $exclude_term_ids ) ) {
                    $member_ids = array_values( array_diff_key( array_flip( $member_ids ), $exclude_term_ids ) );
                }
                if ( ! empty( $member_ids ) ) {
                    $groups_map[ $gid ] = $member_ids;
                }
            }
        }

        $atts['_group_ids']          = $pool_group_ids;
        $atts['_groups_map']         = $groups_map;
        $atts['_include_term_ids']   = ! empty( $atts['include_term_options'] )
            ? htax_parse_term_ids( $atts['include_term_options'], $atts['taxonomy'] )
            : array();

        return $this->render_hidden_field( $atts );
    }

    /**
     * Text search field shortcode [ht_text_field]
     */
    public function text_field_shortcode( $atts ) {
        $defaults = array(
            'id'            => '',
            'name'          => '',
            'label'         => '',
            'placeholder'   => '',
            'show_when'     => 'always',
            'enabled'       => 'true',
            'class'         => '',
            'min_length'         => '3',
            'min_length_message' => '',
            'max_length'         => '0',
            'default_value'      => '',
            'search_in'          => 'all',
        );
        $atts         = shortcode_atts( $defaults, $atts, 'ht_text_field' );
        $atts['type'] = 'text';
        $atts         = $this->process_base_field_atts( $atts );
        return $this->render_text_field( $atts );
    }

    /**
     * Field group shortcode for AND/OR grouping
     */
    public function field_group_shortcode( $atts, $content = null ) {
        $defaults = array(
            'relation' => 'AND', // AND or OR
            'name' => '',        // Optional identifier for targeting
            'show_when' => 'always', // always, expanded, collapsed, never
            'class' => '',
        );

        $atts = shortcode_atts( $defaults, $atts );

        // Normalize relation to uppercase
        $atts['relation'] = strtoupper( $atts['relation'] );
        if ( ! in_array( $atts['relation'], array( 'AND', 'OR' ), true ) ) {
            $atts['relation'] = 'AND';
        }

        $classes = array( 'htax-field-group' );
        $this->apply_show_when_classes( $classes, $atts['show_when'] );
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }

        // Set current group context for nested controllers/fields
        $previous_group = self::$current_group;
        self::$current_group = ! empty( $atts['name'] ) ? $atts['name'] : null;

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'data-group-relation="' . esc_attr( $atts['relation'] ) . '" ';
        if ( ! empty( $atts['name'] ) ) {
            $output .= 'data-group-name="' . esc_attr( $atts['name'] ) . '" ';
        }
        $output .= '>';
        $output .= $this->render_form_content( $content );
        $output .= '</div>';

        // Restore previous group context
        self::$current_group = $previous_group;

        return $output;
    }

    /**
     * Controller shortcode with nested options
     */
    public function controller_shortcode( $atts, $content = null ) {
        $defaults = array(
            'id' => '',
            'name' => '',
            'type' => 'radio', // radio, checkbox, toggle, dropdown, link, button
            'label' => '',
            'required' => 'false', // For radio and dropdown: shows * indicator.

            // Constraints (for checkbox/toggle type)
            'min_items' => 0,
            'max_items' => 0,
            'min_items_message' => '',
            'max_items_message' => '',

            // Display settings
            'show_when' => 'always',
            'enabled' => 'true',

            // CSS
            'class' => '',
            'inline' => 'false',
        );
        
        $atts = shortcode_atts( $defaults, $atts );
        $atts['show_when'] = $this->get_saved_show_when( 'controller', $atts['name'], $atts['show_when'] );
        $atts['enabled']   = $this->get_saved_enabled( 'controller', $atts['name'], $atts['enabled'] );

        // Generate ID if not provided
        if ( empty( $atts['id'] ) ) {
            $atts['id'] = 'htax-controller-' . sanitize_key( $atts['name'] ) . '-' . self::$instance_counter;
        }
        
        // Store controller configuration
        $form_id = $this->get_current_form_id();
        if ( $form_id ) {
            self::$forms[ $form_id ]['controllers'][ $atts['name'] ] = $atts;
        }
        
        // Handle link and button types differently - just wrap the content
        if ( 'link' === $atts['type'] ) {
            return $this->render_link_controller( $atts, $content );
        }
        if ( 'button' === $atts['type'] ) {
            return $this->render_button_controller( $atts, $content );
        }
        
        // For other types, process nested options
        self::$current_controller = array(
            'atts' => $atts,
            'options' => array()
        );
        
        // Process nested shortcodes (htax_search_option)
        if ( ! empty( $content ) ) {
            do_shortcode( $content );
        }
        
        // Build output based on type with collected options
        $output = '';
        switch ( $atts['type'] ) {
            case 'radio':
                $output = $this->render_radio_controller( $atts, self::$current_controller['options'] );
                break;
            case 'checkbox':
                $output = $this->render_checkbox_controller( $atts, self::$current_controller['options'] );
                break;
            case 'toggle':
                $output = $this->render_toggle_controller( $atts, self::$current_controller['options'] );
                break;
            case 'dropdown':
                $output = $this->render_dropdown_controller( $atts, self::$current_controller['options'] );
                break;
        }
        
        // Clear controller context
        self::$current_controller = null;
        
        return $output;
    }
    
    /**
     * Controller option shortcode
     */
    public function option_shortcode( $atts, $content = null ) {
        if ( ! self::$current_controller ) {
            return '<!-- htax_search_option must be inside htax_search_controller -->';
        }
        
        $defaults = array(
            'value' => '',
            'label' => '',
            'default' => 'false',
        );
        
        $atts = shortcode_atts( $defaults, $atts );

        if ( empty( $atts['value'] ) && ! empty( $atts['label'] ) ) {
            $atts['value'] = sanitize_key( $atts['label'] );
        }

        // Create option structure
        $option = array(
            'value' => $atts['value'],
            'label' => $atts['label'],
            'default' => 'true' === $atts['default'],
            'actions' => array()
        );
        
        // Set current option context for nested actions
        self::$current_option = &$option;
        
        // Process nested action shortcodes
        if ( ! empty( $content ) ) {
            do_shortcode( $content );
        }
        
        // Add option to current controller
        self::$current_controller['options'][] = $option;
        
        // Clear option context
        self::$current_option = null;
        
        // No output - data is collected for parent controller
        return '';
    }
    
    /**
     * Option action shortcode
     */
    public function option_action_shortcode( $atts ) {
        if ( ! self::$current_option ) {
            return '<!-- htax_option_action must be inside htax_search_option -->';
        }
        
        $defaults = array(
            'type'              => '', // Multiple types - see validation below
            'target'            => '', // Target field or controller name
            'terms'             => '', // Comma-separated term IDs (e.g., "1,2,5")
            'groups'            => '', // Comma-separated group IDs (e.g., "3,7")
            'value'             => '', // For set_min_items, set_max_items, set_show_when
            'not_found_message' => '', // Shown on the controller when requested terms are absent from the option pool
        );
        
        $atts = shortcode_atts( $defaults, $atts );
        
        // Validate action type
        $valid_types = array(
            // Option pool actions
            'include_term_options', 'include_group_options',
            'exclude_term_options', 'exclude_group_options',
            // Selection actions
            'add_terms', 'add_groups',
            'remove_terms', 'remove_groups',
            'add_descendants', 'remove_descendants',
            // Constraint actions
            'set_min_items', 'set_max_items',
            // Visibility actions
            'set_show_when', // Sets to: always, expanded, collapsed, never
            'enable', 'disable',
            // Clear/Reset actions
            'clear',          // Clear current selections (text, pills, checkboxes, hidden)
            'clearOptions',   // Remove all options from pills/checklist
            'resetOptions',   // Reset options to field's initial defaults
            'reset',          // Reset selection to default AND reset options (for fields) OR reset to default state (for controllers)
            'resetAll'        // Reset all controllers and fields to their defaults (no target needed)
        );
        
        if ( ! in_array( $atts['type'], $valid_types, true ) ) {
            return '<!-- Invalid action type: ' . esc_html( $atts['type'] ) . ' -->';
        }
        
        // Build action structure
        $action = array(
            'type' => $atts['type'],
            'target' => $atts['target']
        );
        
        // Add parameters based on action type
        if ( in_array( $atts['type'], array( 'include_term_options', 'exclude_term_options', 'add_terms', 'remove_terms' ), true ) ) {
            $action['terms'] = $this->parse_id_list( $atts['terms'] );
        }

        if ( in_array( $atts['type'], array( 'include_group_options', 'exclude_group_options', 'add_groups', 'remove_groups', 'add_descendants', 'remove_descendants' ), true ) ) {
            $action['groups'] = $this->parse_id_list( $atts['groups'] );
        }
        
        if ( in_array( $atts['type'], array( 'set_min_items', 'set_max_items' ), true ) ) {
            $action['value'] = intval( $atts['value'] );
        }
        
        if ( 'set_show_when' === $atts['type'] ) {
            // Validate show_when values
            $valid_values = array( 'always', 'expanded', 'collapsed', 'never' );
            if ( in_array( $atts['value'], $valid_values, true ) ) {
                $action['value'] = $atts['value'];
            } else {
                return '<!-- Invalid show_when value: ' . esc_html( $atts['value'] ) . ' -->';
            }
        }
        
        // Emit notFoundMessage for selection actions that add terms/groups.
        $add_action_types = array( 'add_terms', 'add_groups', 'add_descendants' );
        if ( in_array( $atts['type'], $add_action_types, true ) && ! empty( $atts['not_found_message'] ) ) {
            $action['notFoundMessage'] = $atts['not_found_message'];
        }

        // Remember which groups each target field is acted on with; their members are
        // rendered once at the end of the form (render_group_members_map()).
        if ( ! empty( $action['groups'] ) && in_array( $atts['type'], array( 'include_group_options', 'exclude_group_options', 'add_groups', 'remove_groups' ), true ) ) {
            $form_id = $this->get_current_form_id();
            if ( $form_id ) {
                $known = self::$forms[ $form_id ]['group_actions'][ $atts['target'] ] ?? array();
                self::$forms[ $form_id ]['group_actions'][ $atts['target'] ] = array_merge( $known, $action['groups'] );
            }
        }

        // Add action to current option
        self::$current_option['actions'][] = $action;
        
        // No output - data is collected for parent option
        return '';
    }
    
    /**
     * Parse comma-separated list of positive integer IDs.
     * Returns an array of unique positive integers.
     */
    private function parse_id_list( $input ) {
        if ( empty( $input ) ) {
            return array();
        }

        $ids = array();
        foreach ( explode( ',', $input ) as $part ) {
            $id = intval( trim( $part ) );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return array_unique( $ids );
    }
    
    /**
     * Render text field for search queries
     */
    private function render_text_field( $atts ) {
        // Track field type for validation
        $this->track_field_type( 'text', $atts['name'] );

        $classes = array( 'htax-field-wrapper', 'htax-field-text' );

        // Unified show_when handling
        $this->apply_show_when_classes( $classes, $atts['show_when'] );
        
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }
        
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-field-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-field-type="text" ';
        $output .= 'data-search-in="' . esc_attr( $atts['search_in'] ) . '" ';
        if ( ! empty( $atts['min_length_message'] ) ) {
            $output .= 'data-min-length-message="' . esc_attr( $atts['min_length_message'] ) . '" ';
        }
        $output .= '>';
        
        // Label
        if ( ! empty( $atts['label'] ) ) {
            $output .= '<label class="htax-field-label" for="' . esc_attr( $atts['id'] ) . '">';
            $output .= esc_html( $atts['label'] );
            $output .= '</label>';
        }

        // Preserve value after POST submission
        $field_value = $atts['default_value'] ?? '';
        if ( isset( $_POST['htax_post_search'] ) && isset( $_POST[ $atts['name'] ] ) ) {
            $field_value = sanitize_text_field( wp_unslash( $_POST[ $atts['name'] ] ) );
        }

        // Text input
        $output .= '<input type="text" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '" ';
        $output .= 'name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'class="htax-text-input" ';
        $output .= 'value="' . esc_attr( $field_value ) . '" ';
        $output .= 'placeholder="' . esc_attr( $atts['placeholder'] ?: __( 'Enter search terms...', 'hypertaxonomy' ) ) . '" ';
        if ( isset( $atts['min_length'] ) ) {
            $output .= 'data-min-length="' . intval( $atts['min_length'] ) . '" ';
        }
        $max_length = intval( $atts['max_length'] ?? 0 );
        if ( $max_length > 0 ) {
            $output .= 'maxlength="' . $max_length . '" ';
        }
        if ( 'true' !== $atts['enabled'] ) {
            $output .= 'disabled ';
        }
        $output .= '/>';
        $output .= '<div class="htax-validation-message" style="display:none;"></div>';
        $output .= '</div>';

        return $output;
    }

    private function apply_show_when_classes( &$classes, $show_when ) {
        switch ( $show_when ) {
            case 'never':
                $classes[] = 'htax-hidden';
                break;
            case 'expanded':
                $classes[] = 'htax-show-expanded';
                break;
            case 'collapsed':
                $classes[] = 'htax-show-collapsed';
                break;
            // 'always' needs no special class
        }
    }
    
    /**
     * Render pills field (tag selector with autocomplete)
     */
    private function render_pills_field( $atts ) {
        // Get current form ID to make field names unique across multiple forms
        $form_id = $this->get_current_form_id();
        $field_suffix = $form_id ? '_' . sanitize_key( $form_id ) : '';

        // Determine icon display setting
        // Priority: shortcode attribute > global setting for taxonomy
        if ( ! empty( $atts['show_icons'] ) ) {
            $show_icons = $atts['show_icons'];
        } else {
            // Use helper function to get taxonomy-specific setting
            $show_icons = htax_get_icon_display_for_taxonomy( $atts['taxonomy'] );
        }

        $classes = array( 'htax-field-wrapper', 'htax-field-pills' );

        // Handle show_when consistently
        switch ( $atts['show_when'] ) {
            case 'never':
                $classes[] = 'htax-hidden';
                break;
            case 'expanded':
                $classes[] = 'htax-show-expanded';
                break;
            case 'collapsed':
                $classes[] = 'htax-show-collapsed';
                break;
            // 'always' needs no special class
        }
        
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }
        
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-field-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-field-type="pills" ';
        $output .= 'data-taxonomy="' . esc_attr( $atts['taxonomy'] ) . '" ';
        $output .= 'data-show-when="' . esc_attr( $atts['show_when'] ) . '" ';
        
        // Add option pool configuration
        if ( ! empty( $atts['include_term_options'] ) ) {
            $output .= 'data-include-term-options="' . esc_attr( $atts['include_term_options'] ) . '" ';
        }
        if ( ! empty( $atts['include_group_options'] ) ) {
            $output .= 'data-include-group-options="' . esc_attr( $atts['include_group_options'] ) . '" ';
        }
        if ( ! empty( $atts['include_descendant_options'] ) ) {
            $output .= 'data-include-descendant-options="' . esc_attr( $atts['include_descendant_options'] ) . '" ';
        }
        if ( ! empty( $atts['exclude_term_options'] ) ) {
            $output .= 'data-exclude-term-options="' . esc_attr( $atts['exclude_term_options'] ) . '" ';
        }
        if ( ! empty( $atts['exclude_group_options'] ) ) {
            $output .= 'data-exclude-group-options="' . esc_attr( $atts['exclude_group_options'] ) . '" ';
        }
        if ( ! empty( $atts['exclude_descendant_options'] ) ) {
            $output .= 'data-exclude-descendant-options="' . esc_attr( $atts['exclude_descendant_options'] ) . '" ';
        }
        if ( ! empty( $atts['filterby'] ) ) {
            $output .= 'data-filterby="' . esc_attr( $atts['filterby'] ) . '" ';
        }
        if ( 'term' === $atts['taxonomy_type'] ) {
            $output .= 'data-taxonomy-type="term" ';
        }

        // Add initial selection configuration
        if ( ! empty( $atts['add_terms'] ) ) {
            $output .= 'data-add-terms="' . esc_attr( $atts['add_terms'] ) . '" ';
        }
        if ( ! empty( $atts['add_descendants'] ) ) {
            $output .= 'data-add-descendants="' . esc_attr( $atts['add_descendants'] ) . '" ';
        }
        if ( ! empty( $atts['remove_terms'] ) ) {
            $output .= 'data-remove-terms="' . esc_attr( $atts['remove_terms'] ) . '" ';
        }
        if ( ! empty( $atts['remove_descendants'] ) ) {
            $output .= 'data-remove-descendants="' . esc_attr( $atts['remove_descendants'] ) . '" ';
        }
        
        // Add constraints
        $output .= 'data-min-items="' . intval( $atts['min_items'] ) . '" ';
        $output .= 'data-max-items="' . intval( $atts['max_items'] ) . '" ';

        // Icon display setting
        $output .= 'data-show-icons="' . esc_attr( $atts['show_icons'] ) . '" ';

        $has_groups = ! empty( $atts['include_group_options'] ) || ! empty( $atts['include_descendant_options'] );
        if ( 'true' !== $atts['prefetch'] || ! $has_groups ) {
            $output .= 'data-prefetch="false" ';
        }
        if ( 'true' !== $atts['suggest_on_focus'] ) {
            $output .= 'data-suggest-on-focus="false" ';
        }

        // Match parameter (how to match selected terms: any/all/none)
        $output .= 'data-match="' . esc_attr( $atts['match'] ) . '" ';
        if ( 'all' !== $atts['expand'] ) {
            $output .= 'data-expand-types="' . esc_attr( $atts['expand'] ) . '" ';
        }

        if ( ! empty( $atts['min_items_message'] ) ) {
            $output .= 'data-min-items-message="' . esc_attr( $atts['min_items_message'] ) . '" ';
        }
        if ( ! empty( $atts['max_items_message'] ) ) {
            $output .= 'data-max-items-message="' . esc_attr( $atts['max_items_message'] ) . '" ';
        }

        $output .= '>';

        // Label — associated with the autocomplete input via its id.
        if ( ! empty( $atts['label'] ) ) {
            $output .= '<label class="htax-field-label" for="' . esc_attr( $atts['id'] ) . '-input">';
            $output .= esc_html( $atts['label'] );
            if ( intval( $atts['min_items'] ) >= 1 ) {
                $output .= ' <span class="htax-required">*</span>';
            }
            $output .= '</label>';
        }

        // Pills container with autocomplete input inside.
        $output .= '<div class="htax-tag-container htax-pills-container"';
        if ( ! empty( $atts['max_rows'] ) ) {
            $output .= ' data-max-rows="' . intval( $atts['max_rows'] ) . '"';
        }
        $output .= '>';
        // Pills are added dynamically by JS before the input.
        $output .= '<input type="text" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-input" ';
        $output .= 'class="htax-search htax-pills-input" ';
        $output .= 'placeholder="' . esc_attr( $atts['placeholder'] ?: __( 'Type to search...', 'hypertaxonomy' ) ) . '" ';
        if ( 'true' !== $atts['enabled'] ) {
            $output .= 'disabled ';
        }
        $output .= '/>';
        $output .= '</div>';

        // Validation message (shown by JS when min/max constraints not met)
        $output .= '<div class="htax-validation-message" aria-live="polite"></div>';

        // Preserve value after POST submission
        // Use form-specific field names to prevent conflicts between multiple forms
        $pills_field_name = $atts['name'] . $field_suffix;
        $pills_value = '';
        if ( isset( $_POST['htax_post_search'] ) && isset( $_POST[ $pills_field_name ] ) ) {
            $pills_value = sanitize_text_field( wp_unslash( $_POST[ $pills_field_name ] ) );
        }

        // Hidden field to store selected values
        $output .= '<input type="hidden" ';
        $output .= 'name="' . esc_attr( $pills_field_name ) . '" ';
        $output .= 'class="htax-pills-value" ';
        $output .= 'value="' . esc_attr( $pills_value ) . '" />';

        // Hidden field to store available terms pool (for POST restoration)
        $available_field_name = $atts['name'] . '_available' . $field_suffix;
        $available_terms_value = '';
        if ( isset( $_POST['htax_post_search'] ) && isset( $_POST[ $available_field_name ] ) ) {
            $available_terms_value = sanitize_text_field( wp_unslash( $_POST[ $available_field_name ] ) );
        }
        $output .= '<input type="hidden" ';
        $output .= 'name="' . esc_attr( $available_field_name ) . '" ';
        $output .= 'class="htax-pills-available" ';
        $output .= 'value="' . esc_attr( $available_terms_value ) . '" />';

        // Hidden field to store term metadata (for POST restoration)
        $termdata_field_name = $atts['name'] . '_termdata' . $field_suffix;
        $term_data_value = '';
        if ( isset( $_POST['htax_post_search'] ) && isset( $_POST[ $termdata_field_name ] ) ) {
            // Get raw value and stripslashes (WordPress may add magic quotes)
            $term_data_value = wp_unslash( $_POST[ $termdata_field_name ] );
        } elseif ( ! empty( $atts['add_terms'] ) ) {
            // Seed names/icons for the initial selection so its pills are labeled even
            // when there is no prefetched option pool to supply them.
            $seed_ids = htax_parse_term_ids( $atts['add_terms'], $atts['taxonomy'] );
            $seed     = $seed_ids ? get_terms( array(
                'taxonomy'   => $atts['taxonomy'],
                'include'    => $seed_ids,
                'hide_empty' => false,
            ) ) : array();
            if ( ! is_wp_error( $seed ) && $seed ) {
                $term_data_value = wp_json_encode( $this->build_terms_data( $seed, $atts['taxonomy'], $atts['show_icons'] ? $atts['show_icons'] : 'never' ) );
            }
        }
        $output .= '<input type="hidden" ';
        $output .= 'name="' . esc_attr( $termdata_field_name ) . '" ';
        $output .= 'class="htax-pills-termdata" ';
        // Use htmlspecialchars to properly escape for HTML attribute
        $output .= 'value="' . htmlspecialchars( $term_data_value, ENT_QUOTES, 'UTF-8' ) . '" />';

        $output .= '</div>';

        return $output;
    }
    
    /**
     * Render checklist field (hierarchical checkboxes)
     */
    private function render_checklist_field( $atts ) {
        // Get current form ID to make field names unique across multiple forms
        $form_id = $this->get_current_form_id();
        $field_suffix = $form_id ? '_' . sanitize_key( $form_id ) : '';

        // Determine icon display setting
        // Priority: shortcode attribute > global setting for taxonomy
        if ( ! empty( $atts['show_icons'] ) ) {
            $show_icons = $atts['show_icons'];
        } else {
            // Use helper function to get taxonomy-specific setting
            $show_icons = htax_get_icon_display_for_taxonomy( $atts['taxonomy'] );
        }

        $classes = array( 'htax-field-wrapper', 'htax-field-checklist' );
        // Match pills field: use htax-hidden for never (htax-show-never has no CSS rule).
        switch ( $atts['show_when'] ) {
            case 'never':
                $classes[] = 'htax-hidden';
                break;
            case 'expanded':
                $classes[] = 'htax-show-expanded';
                break;
            case 'collapsed':
                $classes[] = 'htax-show-collapsed';
                break;
        }
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }

        // Route to descendant-options renderer (term mode) or expand into include_group_options (post mode).
        if ( ! empty( $atts['include_descendant_options'] ) ) {
            if ( 'term' === $atts['taxonomy_type'] ) {
                return $this->render_descendant_options_checklist( $atts, $classes, $field_suffix );
            }
            // Post mode: options are member terms of the descendant groups, not the groups themselves.
            // Expand descendant group IDs and fold them into include_group_options so the regular
            // renderer fetches their members.
            $root_ids = htax_parse_term_ids( $atts['include_descendant_options'], 'group' );
            $desc_ids = array_values( array_diff( htax_get_group_hierarchy( $root_ids ), $root_ids ) );
            if ( ! empty( $atts['exclude_descendant_options'] ) ) {
                $branch_ids     = htax_parse_term_ids( $atts['exclude_descendant_options'], 'group' );
                $subtree_remove = array();
                foreach ( $branch_ids as $bid ) {
                    $ch = get_term_children( $bid, 'group' );
                    if ( ! is_wp_error( $ch ) ) {
                        $subtree_remove = array_merge( $subtree_remove, array_map( 'intval', $ch ) );
                    }
                }
                $desc_ids = array_values( array_diff( $desc_ids, $subtree_remove ) );
            }
            $existing               = ! empty( $atts['include_group_options'] ) ? htax_parse_term_ids( $atts['include_group_options'], 'group' ) : array();
            $atts['include_group_options'] = implode( ',', array_unique( array_merge( $existing, $desc_ids ) ) );
        }

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-field-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-field-type="checklist" ';
        $output .= 'data-taxonomy="' . esc_attr( $atts['taxonomy'] ) . '" ';
        $output .= 'data-show-when="' . esc_attr( $atts['show_when'] ) . '" ';
        $output .= 'data-show-icons="' . esc_attr( $show_icons ) . '" ';
        $output .= 'data-match="' . esc_attr( $atts['match'] ) . '" ';
        if ( 'all' !== $atts['expand'] ) {
            $output .= 'data-expand-types="' . esc_attr( $atts['expand'] ) . '" ';
        }
        if ( ! empty( $atts['filterby'] ) ) {
            $output .= 'data-filterby="' . esc_attr( $atts['filterby'] ) . '" ';
        }
        if ( 'term' === $atts['taxonomy_type'] ) {
            $output .= 'data-taxonomy-type="term" ';
        }
        if ( ! empty( $atts['add_descendants'] ) ) {
            $output .= 'data-add-descendants="' . esc_attr( $atts['add_descendants'] ) . '" ';
        }
        if ( ! empty( $atts['remove_descendants'] ) ) {
            $output .= 'data-remove-descendants="' . esc_attr( $atts['remove_descendants'] ) . '" ';
        }
        $output .= '>';
        
        // A checklist is a group of checkboxes with no single target input, so the field
        // label is rendered as a <p> with an id rather than a <label> (which would trigger
        // "label not associated" browser warnings).  The checklist container uses
        // role="group" + aria-labelledby to associate the heading semantically.
        if ( ! empty( $atts['label'] ) ) {
            $output .= '<p id="' . esc_attr( $atts['id'] ) . '-label" class="htax-field-label">';
            $output .= esc_html( $atts['label'] );
            if ( intval( $atts['min_items'] ) >= 1 ) {
                $output .= ' <span class="htax-required">*</span>';
            }
            $output .= '</p>';
        }

        // Get terms
        $terms = $this->get_field_terms( $atts );

        // Add icon display setting and field suffix to atts for sub-rendering
        $atts['_show_icons'] = $show_icons;
        $atts['_field_suffix'] = $field_suffix;

        if ( ! empty( $terms ) ) {
            $output .= '<div class="htax-checklist-container" ';
            $output .= 'role="group" ';
            if ( ! empty( $atts['label'] ) ) {
                $output .= 'aria-labelledby="' . esc_attr( $atts['id'] ) . '-label" ';
            }
            $output .= 'data-min-items="' . intval( $atts['min_items'] ) . '" ';
            $output .= 'data-max-items="' . intval( $atts['max_items'] ) . '" ';
            if ( ! empty( $atts['max_rows'] ) ) {
                $output .= 'data-max-rows="' . intval( $atts['max_rows'] ) . '" ';
            }
            $output .= '>';

            if ( 'true' === $atts['hierarchical'] && is_taxonomy_hierarchical( $atts['taxonomy'] ) ) {
                $output .= $this->render_hierarchical_checklist( $terms, $atts );
            } else {
                $output .= $this->render_flat_checklist( $terms, $atts );
            }
            
            $output .= '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Get the selected term IDs for a checklist field.
     *
     * Returns add_terms minus remove_terms from the shortcode attributes, overridden by
     * POST data when a post-search form was submitted.
     *
     * @param array $atts Field attributes (uses 'add_terms', 'remove_terms', 'name', '_field_suffix').
     * @return int[] Array of selected term IDs.
     */
    private function get_default_terms_for_field( $atts ) {
        $default_terms = array_values( array_diff(
            htax_parse_term_ids( $atts['add_terms'], $atts['taxonomy'] ),
            htax_parse_term_ids( $atts['remove_terms'] ?? '', $atts['taxonomy'] )
        ) );

        $field_suffix = isset( $atts['_field_suffix'] ) ? $atts['_field_suffix'] : '';
        $field_name   = $atts['name'] . $field_suffix;

        if ( isset( $_POST['htax_post_search'] ) && isset( $_POST[ $field_name ] ) && is_array( $_POST[ $field_name ] ) ) {
            $default_terms = array_map( 'intval', $_POST[ $field_name ] );
        }

        return $default_terms;
    }

    /**
     * Render hierarchical checklist
     */
    private function render_hierarchical_checklist( $terms, $atts, $parent = 0, $depth = 0 ) {
        $output = '';
        $max_depth = intval( $atts['max_depth'] );

        // Check depth limit: -1 = unlimited, 0 = no subcategories, >0 = limited
        if ( $max_depth >= 0 && $depth >= $max_depth ) {
            return $output;
        }

        $default_terms = $this->get_default_terms_for_field( $atts );
        $field_suffix  = isset( $atts['_field_suffix'] ) ? $atts['_field_suffix'] : '';
        $field_name    = $atts['name'] . $field_suffix;

        // At the top level, a term whose parent is not among the options (e.g. an
        // include_term_options list of subcategories) is a root, not an orphan to drop.
        $present = array();
        if ( 0 === $depth ) {
            foreach ( $terms as $t ) {
                $present[ (int) $t->term_id ] = true;
            }
        }

        foreach ( $terms as $term ) {
            if ( 0 === $depth ) {
                if ( $term->parent && isset( $present[ (int) $term->parent ] ) ) {
                    continue;
                }
            } elseif ( $term->parent != $parent ) {
                continue;
            }

            $checked = in_array( $term->term_id, $default_terms ) ? 'checked' : '';
            $indent = $depth * 20;

            $output .= '<div class="htax-checklist-item"';
            $output .= ' data-term-id="' . esc_attr( $term->term_id ) . '"';
            $output .= ' data-parent-id="' . esc_attr( $term->parent ) . '"';
            $output .= ' style="padding-left: ' . $indent . 'px;">';
            $output .= '<label>';
            $output .= '<input type="checkbox" ';
            $output .= 'name="' . esc_attr( $field_name ) . '[]" ';
            $output .= 'value="' . esc_attr( $term->term_id ) . '" ';
            $output .= 'data-term-name="' . esc_attr( $term->name ) . '" ';
            $output .= $checked . ' ';
            if ( 'true' !== $atts['enabled'] ) {
                $output .= 'disabled ';
            }
            $output .= '/>';
            $output .= $this->render_checklist_item_icon( $term, isset( $atts['_show_icons'] ) ? $atts['_show_icons'] : 'never' );
            $output .= ' ' . esc_html( $term->name );
            if ( 'true' === $atts['show_count'] ) {
                $output .= ' <span class="htax-count">(' . $term->count . ')</span>';
            }
            $output .= '</label>';
            $output .= '</div>';

            // Render children (after closing parent div, not nested inside)
            $output .= $this->render_hierarchical_checklist( $terms, $atts, $term->term_id, $depth + 1 );
        }
        
        return $output;
    }
    
    /**
     * Render flat checklist
     */
    private function render_flat_checklist( $terms, $atts ) {
        $output = '';

        $default_terms = $this->get_default_terms_for_field( $atts );
        $field_suffix  = isset( $atts['_field_suffix'] ) ? $atts['_field_suffix'] : '';
        $field_name    = $atts['name'] . $field_suffix;

        foreach ( $terms as $term ) {
            $checked = in_array( $term->term_id, $default_terms ) ? 'checked' : '';

            // data-term-id identifies the item for controller selection actions (as in the hierarchical renderer).
            $output .= '<div class="htax-checklist-item" data-term-id="' . esc_attr( $term->term_id ) . '">';
            $output .= '<label>';
            $output .= '<input type="checkbox" ';
            $output .= 'name="' . esc_attr( $field_name ) . '[]" ';
            $output .= 'value="' . esc_attr( $term->term_id ) . '" ';
            $output .= 'data-term-name="' . esc_attr( $term->name ) . '" ';
            $output .= $checked . ' ';
            if ( 'true' !== $atts['enabled'] ) {
                $output .= 'disabled ';
            }
            $output .= '/>';
            $output .= $this->render_checklist_item_icon( $term, isset( $atts['_show_icons'] ) ? $atts['_show_icons'] : 'never' );
            $output .= ' ' . esc_html( $term->name );
            if ( 'true' === $atts['show_count'] ) {
                $output .= ' <span class="htax-count">(' . $term->count . ')</span>';
            }
            $output .= '</label>';
            $output .= '</div>';
        }

        return $output;
    }

    /**
     * Render a checklist where options are group descendants.
     * Used when include_descendant_options is set.
     *
     * include_descendant_options — root group ID(s); all descendants become options (roots excluded).
     * exclude_term_options       — specific group term IDs to exclude from the displayed set.
     * hierarchical               — 'true' renders a nested tree; 'false' renders a flat sorted list.
     * filterby                   — comma-separated taxonomy slugs; submitted group IDs are expanded
     *                              to member terms in these taxonomies at query time.
     */
    private function render_descendant_options_checklist( $atts, $classes, $field_suffix ) {
        $root_ids    = htax_parse_term_ids( $atts['include_descendant_options'], 'group' );
        $exclude_ids = ! empty( $atts['exclude_term_options'] ) ? htax_parse_term_ids( $atts['exclude_term_options'], $atts['taxonomy'] ) : array();

        if ( empty( $root_ids ) ) {
            return '';
        }

        // All descendants of the root groups, excluding the roots themselves
        $all_ids = array_values( array_diff( htax_get_group_hierarchy( $root_ids ), $root_ids ) );

        // Prune branches: keep the specified nodes but remove everything below them
        if ( ! empty( $atts['exclude_descendant_options'] ) ) {
            $branch_ids    = htax_parse_term_ids( $atts['exclude_descendant_options'], 'group' );
            $subtree_remove = array();
            foreach ( $branch_ids as $branch_id ) {
                $branch_children = get_term_children( $branch_id, $atts['taxonomy'] );
                if ( ! is_wp_error( $branch_children ) ) {
                    $subtree_remove = array_merge( $subtree_remove, array_map( 'intval', $branch_children ) );
                }
            }
            if ( ! empty( $subtree_remove ) ) {
                $all_ids = array_values( array_diff( $all_ids, $subtree_remove ) );
            }
        }

        if ( ! empty( $exclude_ids ) ) {
            $all_ids = array_values( array_diff( $all_ids, $exclude_ids ) );
        }

        if ( empty( $all_ids ) ) {
            return '';
        }

        $group_terms = get_terms( array(
            'taxonomy'   => $atts['taxonomy'],
            'include'    => $all_ids,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );

        if ( is_wp_error( $group_terms ) || empty( $group_terms ) ) {
            return '';
        }

        // POST state restoration
        $field_name  = $atts['name'] . $field_suffix;
        $post_values = ( isset( $_POST['htax_post_search'] ) && isset( $_POST[ $field_name ] ) )
            ? array_map( 'intval', (array) wp_unslash( $_POST[ $field_name ] ) )
            : null;

        $output  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-field-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-field-type="checklist" ';
        $output .= 'data-taxonomy="' . esc_attr( $atts['taxonomy'] ) . '" ';
        $output .= 'data-match="' . esc_attr( $atts['match'] ) . '" ';
        $output .= 'data-show-when="' . esc_attr( $atts['show_when'] ) . '"';
        $output .= ' data-taxonomy-type="term"';
        if ( ! empty( $atts['filterby'] ) ) {
            $output .= ' data-filterby="' . esc_attr( $atts['filterby'] ) . '"';
        }
        if ( ! empty( $atts['add_descendants'] ) ) {
            $output .= ' data-add-descendants="' . esc_attr( $atts['add_descendants'] ) . '"';
        }
        if ( ! empty( $atts['remove_descendants'] ) ) {
            $output .= ' data-remove-descendants="' . esc_attr( $atts['remove_descendants'] ) . '"';
        }
        $output .= '>';

        if ( ! empty( $atts['label'] ) ) {
            $output .= '<p id="' . esc_attr( $atts['id'] ) . '-label" class="htax-field-label">';
            $output .= esc_html( $atts['label'] );
            $output .= '</p>';
        }

        $output .= '<div class="htax-checklist-container" role="group"';
        if ( ! empty( $atts['label'] ) ) {
            $output .= ' aria-labelledby="' . esc_attr( $atts['id'] ) . '-label"';
        }
        $output .= ' data-min-items="' . intval( $atts['min_items'] ) . '"';
        $output .= ' data-max-items="' . intval( $atts['max_items'] ) . '"';
        if ( ! empty( $atts['max_rows'] ) ) {
            $output .= ' data-max-rows="' . intval( $atts['max_rows'] ) . '"';
        }
        $output .= '>';

        if ( 'true' === $atts['hierarchical'] ) {
            $all_id_set = array_flip( $all_ids );
            $by_parent  = array();
            foreach ( $group_terms as $g ) {
                $parent_key = isset( $all_id_set[ $g->parent ] ) ? $g->parent : 0;
                $by_parent[ $parent_key ][] = $g;
            }
            foreach ( isset( $by_parent[0] ) ? $by_parent[0] : array() as $g ) {
                $output .= $this->render_descendant_option_tree( $g, $by_parent, $field_name, $post_values, 0 );
            }
        } else {
            foreach ( $group_terms as $g ) {
                $output .= $this->render_descendant_option_item( $g, $field_name, $post_values );
            }
        }

        $output .= '</div>';
        $output .= '</div>';
        return $output;
    }

    /**
     * Recursively render a descendant group and its children as a nested checklist.
     */
    private function render_descendant_option_tree( $group, $by_parent, $field_name, $post_values, $depth ) {
        $output   = $this->render_descendant_option_item( $group, $field_name, $post_values, $depth );
        $children = isset( $by_parent[ $group->term_id ] ) ? $by_parent[ $group->term_id ] : array();
        foreach ( $children as $child ) {
            $output .= $this->render_descendant_option_tree( $child, $by_parent, $field_name, $post_values, $depth + 1 );
        }
        return $output;
    }

    /**
     * Render a single descendant group as a checkbox item.
     */
    private function render_descendant_option_item( $group, $field_name, $post_values, $depth = 0 ) {
        $checked = ( null !== $post_values && in_array( $group->term_id, $post_values, true ) ) ? 'checked ' : '';
        $indent  = $depth * 16;
        $output  = '<div class="htax-checklist-item"';
        $output .= ' data-term-id="' . esc_attr( $group->term_id ) . '"';
        $output .= ' data-parent-id="' . esc_attr( $group->parent ) . '"';
        if ( $indent ) {
            $output .= ' style="padding-left:' . $indent . 'px"';
        }
        $output .= '><label>';
        $output .= '<input type="checkbox" ';
        $output .= 'name="' . esc_attr( $field_name ) . '[]" ';
        $output .= 'value="' . esc_attr( $group->term_id ) . '" ';
        $output .= $checked . '/>';
        $output .= ' ' . esc_html( $group->name );
        $output .= '</label></div>';
        return $output;
    }

    /**
     * Expand a list of group IDs to their descendants up to $max_depth levels deep.
     * depth=0 returns the input IDs unchanged; depth=1 adds direct children; -1 = unlimited.
     */
    private function expand_groups_to_depth( $group_ids, $max_depth, $current_depth = 0 ) {
        if ( 0 === $max_depth ) {
            return array_unique( $group_ids );
        }
        $hierarchy = _get_term_hierarchy( 'group' );
        $result    = $group_ids;
        foreach ( $group_ids as $gid ) {
            $children = isset( $hierarchy[ $gid ] ) ? $hierarchy[ $gid ] : array();
            if ( ! empty( $children ) ) {
                $result = array_merge( $result, $children );
                $next   = ( $max_depth > 0 ) ? $max_depth - 1 : -1;
                $result = array_merge( $result, $this->expand_groups_to_depth( $children, $next, $current_depth + 1 ) );
            }
        }
        return array_unique( $result );
    }

    /**
     * Render icon HTML for a single checklist item.
     *
     * Returns an <img> for terms that have an icon set, a colored placeholder span
     * when show_icons is 'always', or an empty string when nothing should display.
     *
     * @param WP_Term $term       Term object.
     * @param string  $show_icons 'when_available', 'always', or 'never'.
     * @return string Icon HTML or empty string.
     */
    private function render_checklist_item_icon( $term, $show_icons ) {
        if ( 'never' === $show_icons ) {
            return '';
        }
        if ( ! htax_is_taxonomy_enabled( $term->taxonomy ) || ! htax_taxonomy_has_feature( $term->taxonomy, 'icons' ) ) {
            return '';
        }

        $icons     = HyperTaxonomy_Term_Icons::get_instance();
        $size_name = HyperTaxonomy_Term_Icons::get_size_name( $term->taxonomy, 'small' );
        $icon_data = $icons->get_term_icon_data( $term->term_id, $size_name, false, $term->taxonomy );

        if ( $icon_data['has_icon'] ) {
            $dimensions = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, 'small' );
            return sprintf(
                '<img src="%s" class="htax-pill-icon" alt="" data-taxonomy="%s" width="%d" height="%d" />',
                esc_url( $icon_data['url'] ),
                esc_attr( $term->taxonomy ),
                intval( $dimensions['width'] ),
                intval( $dimensions['height'] )
            );
        }

        if ( 'always' === $show_icons ) {
            $placeholder_color = HyperTaxonomy_Term_Icons::get_color_for_slug( $term->slug );
            $dimensions        = HyperTaxonomy_Term_Icons::get_icon_dimensions( $term->taxonomy, 'small' );
            return htax_get_placeholder_icon_html( $placeholder_color, $dimensions, $term->taxonomy );
        }

        return '';
    }

    /**
     * Build terms_data array (name, icon_url, placeholder_color) for a list of WP_Term objects.
     * Used by the AJAX prefetch methods and to seed the pills field's initial selection.
     */
    private function build_terms_data( array $terms, string $taxonomy, string $show_icons ): array {
        $terms_data = array();
        $has_icons  = 'never' !== $show_icons
                      && htax_is_taxonomy_enabled( $taxonomy )
                      && htax_taxonomy_has_feature( $taxonomy, 'icons' );
        $icons      = $has_icons ? HyperTaxonomy_Term_Icons::get_instance() : null;
        $size_name  = $icons ? HyperTaxonomy_Term_Icons::get_size_name( $taxonomy, 'small' ) : null;

        foreach ( $terms as $term ) {
            $icon_url          = null;
            $placeholder_color = null;
            if ( $icons ) {
                $icon_data = $icons->get_term_icon_data( $term->term_id, $size_name, false, $taxonomy );
                if ( $icon_data['has_icon'] ) {
                    $icon_url = $icon_data['url'];
                } elseif ( 'always' === $show_icons ) {
                    $placeholder_color = HyperTaxonomy_Term_Icons::get_color_for_slug( $term->slug );
                }
            }
            $terms_data[ $term->term_id ] = array(
                'name'              => wp_specialchars_decode( $term->name, ENT_QUOTES ),
                'count'             => (int) $term->count,
                'icon_url'          => $icon_url,
                'placeholder_color' => $placeholder_color,
            );
        }

        return $terms_data;
    }

    /**
     * Render hidden field
     */
    private function render_hidden_field( $atts ) {
        $min_items = intval( $atts['min_items'] );
        $max_items = intval( $atts['max_items'] );

        $output = '<input type="hidden" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '" ';
        $output .= 'name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'value="" ';
        $output .= 'class="htax-field-wrapper" ';
        $output .= 'data-field-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-field-type="hidden" ';
        $output .= 'data-taxonomy="' . esc_attr( $atts['taxonomy'] ) . '" ';
        $output .= 'data-match="' . esc_attr( $atts['match'] ) . '" ';
        if ( 'all' !== $atts['expand'] ) {
            $output .= 'data-expand-types="' . esc_attr( $atts['expand'] ) . '" ';
        }
        if ( ! empty( $atts['filterby'] ) ) {
            $output .= 'data-filterby="' . esc_attr( $atts['filterby'] ) . '" ';
        }
        if ( $min_items > 0 ) {
            $output .= 'data-min-items="' . $min_items . '" ';
        }
        if ( $max_items > 0 ) {
            $output .= 'data-max-items="' . $max_items . '" ';
        }

        if ( ! empty( $atts['add_terms'] ) ) {
            $output .= 'data-add-terms="' . esc_attr( $atts['add_terms'] ) . '" ';
        }
        if ( ! empty( $atts['remove_terms'] ) ) {
            $output .= 'data-remove-terms="' . esc_attr( $atts['remove_terms'] ) . '" ';
        }
        if ( ! empty( $atts['_include_term_ids'] ) ) {
            $output .= 'data-include-term-options="' . esc_attr( implode( ',', $atts['_include_term_ids'] ) ) . '" ';
        }
        if ( ! empty( $atts['_group_ids'] ) ) {
            $output .= 'data-group-ids="' . esc_attr( implode( ',', $atts['_group_ids'] ) ) . '" ';
        }
        if ( ! empty( $atts['_groups_map'] ) ) {
            $output .= "data-groups-map='" . esc_attr( wp_json_encode( $atts['_groups_map'] ) ) . "' ";
        }

        $output .= '/>';

        // Validation message span — visible sibling for min/max constraint messages.
        $has_messages = $min_items > 0 || $max_items > 0;
        if ( $has_messages ) {
            $output .= '<span class="htax-validation-message htax-hidden-field-message" style="display:none;"';
            if ( ! empty( $atts['min_items_message'] ) ) {
                $output .= ' data-min-items-message="' . esc_attr( $atts['min_items_message'] ) . '"';
            }
            if ( ! empty( $atts['max_items_message'] ) ) {
                $output .= ' data-max-items-message="' . esc_attr( $atts['max_items_message'] ) . '"';
            }
            $output .= '></span>';
        }

        return $output;
    }
    
    /**
     * Render link controller - just wraps content with proper attributes
     */
    private function render_link_controller( $atts, $content ) {
        $classes = array( 'htax-controller-wrapper', 'htax-controller-link' );
        $classes[] = 'htax-show-' . $atts['show_when'];
        if ( 'never' === $atts['show_when'] ) {
            $classes[] = 'htax-hidden';
        }
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }
        
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-controller-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-controller-type="link" ';
        $output .= $this->get_group_data_attr();
        $output .= '>';

        // Process the content (which should contain links with data-actions)
        $output .= do_shortcode( $content );
        
        $output .= '</div>';
        
        return $output;
    }

    /**
     * Render button controller - similar to link but wraps buttons with proper attributes
     */
    private function render_button_controller( $atts, $content ) {
        $classes = array( 'htax-controller-wrapper', 'htax-controller-button' );
        $classes[] = 'htax-show-' . $atts['show_when'];
        if ( 'never' === $atts['show_when'] ) {
            $classes[] = 'htax-hidden';
        }
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-controller-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-controller-type="button" ';
        $output .= $this->get_group_data_attr();
        $output .= '>';

        // Process the content (which should contain buttons with data-actions)
        $output .= do_shortcode( $content );

        $output .= '</div>';

        return $output;
    }

    /**
     * Get group data attribute if controller is inside a group
     */
    private function get_group_data_attr() {
        if ( ! empty( self::$current_group ) ) {
            return 'data-group-name="' . esc_attr( self::$current_group ) . '" ';
        }
        return '';
    }

    /**
     * Render radio controller
     */
    private function render_radio_controller( $atts, $options ) {
        $classes = array( 'htax-controller-wrapper', 'htax-controller-radio' );
        $classes[] = 'htax-show-' . $atts['show_when'];
        if ( 'never' === $atts['show_when'] ) {
            $classes[] = 'htax-hidden';
        }
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( 'true' === $atts['inline'] ) {
            $classes[] = 'htax-inline';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }
        
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-controller-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-controller-type="radio" ';
        $output .= $this->get_group_data_attr();
        $output .= '>';

        // Label
        if ( ! empty( $atts['label'] ) ) {
            $output .= '<div class="htax-controller-label">';
            $output .= esc_html( $atts['label'] );
            if ( 'true' === $atts['required'] ) {
                $output .= ' <span class="htax-required">*</span>';
            }
            $output .= '</div>';
        }
        
        $output .= '<div class="htax-radio-group">';
        
        foreach ( $options as $option ) {
            $option_id = $atts['id'] . '-' . sanitize_key( $option['value'] );
            $checked = $option['default'] ? 'checked' : '';
            
            $output .= '<div class="htax-radio-item">';
            $output .= '<label for="' . esc_attr( $option_id ) . '">';
            $output .= '<input type="radio" ';
            $output .= 'id="' . esc_attr( $option_id ) . '" ';
            $output .= 'name="' . esc_attr( $atts['name'] ) . '" ';
            $output .= 'value="' . esc_attr( $option['value'] ) . '" ';
            if ( ! empty( $option['actions'] ) ) {
                $output .= 'data-actions=\'' . esc_attr( json_encode( array( 'actions' => $option['actions'] ) ) ) . '\' ';
            }
            $output .= $checked . ' ';
            if ( 'true' !== $atts['enabled'] ) {
                $output .= 'disabled ';
            }
            $output .= '/>';
            $output .= ' ' . esc_html( $option['label'] );
            $output .= '</label>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Parse and cache the htax_ui_state JSON submitted with a POST search.
     * Returns the full decoded array, or an empty array when not a POST search or JSON is invalid.
     */
    private function get_saved_ui_state(): array {
        static $parsed = false;
        static $saved  = array();

        if ( ! $parsed ) {
            $parsed = true;
            if ( isset( $_POST['htax_post_search'], $_POST['htax_ui_state'] ) ) {
                $decoded = json_decode( wp_unslash( $_POST['htax_ui_state'] ), true );
                if ( is_array( $decoded ) ) {
                    $valid_show_when = array( 'always', 'expanded', 'collapsed', 'never' );
                    foreach ( $decoded as $key => $value ) {
                        if ( ! is_string( $key ) || ! is_string( $value ) ) {
                            continue;
                        }
                        if ( strpos( $key, 'enabled:' ) === 0 ) {
                            if ( 'true' === $value || 'false' === $value ) {
                                $saved[ $key ] = $value;
                            }
                        } elseif ( in_array( $value, $valid_show_when, true ) ) {
                            $saved[ $key ] = $value;
                        }
                    }
                }
            }
        }

        return $saved;
    }

    /**
     * Return the saved show_when value for a controller or field from the submitted htax_ui_state,
     * falling back to $default when no saved value exists.
     */
    private function get_saved_show_when( string $type, string $name, string $default ): string {
        $saved = $this->get_saved_ui_state();
        return $saved[ $type . ':' . $name ] ?? $default;
    }

    /**
     * Return the saved enabled value ('true'/'false') for a controller or field from
     * the submitted htax_ui_state, falling back to $default when no saved value exists.
     */
    private function get_saved_enabled( string $type, string $name, string $default ): string {
        $saved = $this->get_saved_ui_state();
        return $saved[ 'enabled:' . $type . ':' . $name ] ?? $default;
    }

    /**
     * Return the submitted values for a checkbox/toggle controller, or null if not a POST search.
     * Distinguishes "submitted but nothing checked" (empty array) from "not a POST search" (null).
     */
    private function get_post_controller_values( string $name ): ?array {
        if ( ! isset( $_POST['htax_post_search'] ) ) {
            return null;
        }
        if ( isset( $_POST[ $name ] ) ) {
            return array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ $name ] ) );
        }
        // Sentinel: controller was rendered and submitted but all options were unchecked.
        $submitted = isset( $_POST['htax_ctrl_submitted'] )
            ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['htax_ctrl_submitted'] ) )
            : array();
        return in_array( $name, $submitted, true ) ? array() : null;
    }

    /**
     * Render checkbox controller
     */
    private function render_checkbox_controller( $atts, $options ) {
        $classes = array( 'htax-controller-wrapper', 'htax-controller-checkbox' );
        $classes[] = 'htax-show-' . $atts['show_when'];
        if ( 'never' === $atts['show_when'] ) {
            $classes[] = 'htax-hidden';
        }
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( 'true' === $atts['inline'] ) {
            $classes[] = 'htax-inline';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-controller-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-controller-type="checkbox" ';
        $output .= 'data-min-items="' . intval( $atts['min_items'] ) . '" ';
        $output .= 'data-max-items="' . intval( $atts['max_items'] ) . '" ';
        if ( ! empty( $atts['min_items_message'] ) ) {
            $output .= 'data-min-items-message="' . esc_attr( $atts['min_items_message'] ) . '" ';
        }
        if ( ! empty( $atts['max_items_message'] ) ) {
            $output .= 'data-max-items-message="' . esc_attr( $atts['max_items_message'] ) . '" ';
        }
        $output .= $this->get_group_data_attr();
        $output .= '>';

        // Label — auto-asterisk when min_items >= 1
        if ( ! empty( $atts['label'] ) ) {
            $output .= '<div class="htax-controller-label">';
            $output .= esc_html( $atts['label'] );
            if ( intval( $atts['min_items'] ) >= 1 ) {
                $output .= ' <span class="htax-required">*</span>';
            }
            $output .= '</div>';
        }

        $output .= '<div class="htax-checkbox-group">';
        $output .= '<input type="hidden" name="htax_ctrl_submitted[]" value="' . esc_attr( $atts['name'] ) . '" />';

        $post_controller = $this->get_post_controller_values( $atts['name'] );

        foreach ( $options as $option ) {
            $option_id = $atts['id'] . '-' . sanitize_key( $option['value'] );
            if ( $post_controller !== null ) {
                $checked = in_array( $option['value'], $post_controller, true ) ? 'checked' : '';
            } else {
                $checked = $option['default'] ? 'checked' : '';
            }

            $output .= '<div class="htax-checkbox-item htax-option-item">';
            $output .= '<label for="' . esc_attr( $option_id ) . '">';
            $output .= '<input type="checkbox" ';
            $output .= 'id="' . esc_attr( $option_id ) . '" ';
            $output .= 'name="' . esc_attr( $atts['name'] ) . '[]" ';
            $output .= 'value="' . esc_attr( $option['value'] ) . '" ';
            if ( ! empty( $option['actions'] ) ) {
                $output .= 'data-actions=\'' . esc_attr( json_encode( array( 'actions' => $option['actions'] ) ) ) . '\' ';
            }
            $output .= $checked . ' ';
            if ( 'true' !== $atts['enabled'] ) {
                $output .= 'disabled ';
            }
            $output .= '/>';
            $output .= ' ' . esc_html( $option['label'] );
            $output .= '</label>';
            $output .= '</div>';
        }

        $output .= '</div>';
        $output .= '<div class="htax-validation-message" aria-live="polite"></div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render toggle controller — identical behavior to checkbox, visually shown as toggle switches
     */
    private function render_toggle_controller( $atts, $options ) {
        $classes = array( 'htax-controller-wrapper', 'htax-controller-toggle' );
        $classes[] = 'htax-show-' . $atts['show_when'];
        if ( 'never' === $atts['show_when'] ) {
            $classes[] = 'htax-hidden';
        }
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( 'true' === $atts['inline'] ) {
            $classes[] = 'htax-inline';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-controller-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-controller-type="toggle" ';
        $output .= 'data-min-items="' . intval( $atts['min_items'] ) . '" ';
        $output .= 'data-max-items="' . intval( $atts['max_items'] ) . '" ';
        if ( ! empty( $atts['min_items_message'] ) ) {
            $output .= 'data-min-items-message="' . esc_attr( $atts['min_items_message'] ) . '" ';
        }
        if ( ! empty( $atts['max_items_message'] ) ) {
            $output .= 'data-max-items-message="' . esc_attr( $atts['max_items_message'] ) . '" ';
        }
        $output .= $this->get_group_data_attr();
        $output .= '>';

        // Label — auto-asterisk when min_items >= 1
        if ( ! empty( $atts['label'] ) ) {
            $output .= '<div class="htax-controller-label">';
            $output .= esc_html( $atts['label'] );
            if ( intval( $atts['min_items'] ) >= 1 ) {
                $output .= ' <span class="htax-required">*</span>';
            }
            $output .= '</div>';
        }

        $output .= '<div class="htax-toggle-group">';
        $output .= '<input type="hidden" name="htax_ctrl_submitted[]" value="' . esc_attr( $atts['name'] ) . '" />';

        $post_controller = $this->get_post_controller_values( $atts['name'] );

        foreach ( $options as $option ) {
            $option_id = $atts['id'] . '-' . sanitize_key( $option['value'] );
            if ( $post_controller !== null ) {
                $checked = in_array( $option['value'], $post_controller, true ) ? 'checked' : '';
            } else {
                $checked = $option['default'] ? 'checked' : '';
            }

            $output .= '<div class="htax-toggle-item htax-option-item">';
            $output .= '<label for="' . esc_attr( $option_id ) . '">';
            $output .= '<input type="checkbox" ';
            $output .= 'id="' . esc_attr( $option_id ) . '" ';
            $output .= 'name="' . esc_attr( $atts['name'] ) . '[]" ';
            $output .= 'value="' . esc_attr( $option['value'] ) . '" ';
            if ( ! empty( $option['actions'] ) ) {
                $output .= 'data-actions=\'' . esc_attr( json_encode( array( 'actions' => $option['actions'] ) ) ) . '\' ';
            }
            $output .= $checked . ' ';
            if ( 'true' !== $atts['enabled'] ) {
                $output .= 'disabled ';
            }
            $output .= '/>';
            $output .= '<span class="htax-toggle-switch" aria-hidden="true"></span>';
            $output .= ' ' . esc_html( $option['label'] );
            $output .= '</label>';
            $output .= '</div>';
        }

        $output .= '</div>';
        $output .= '<div class="htax-validation-message" aria-live="polite"></div>';
        $output .= '</div>';

        return $output;
    }
    
    /**
     * Render dropdown controller
     */
    private function render_dropdown_controller( $atts, $options ) {
        $classes = array( 'htax-controller-wrapper', 'htax-controller-dropdown' );
        $classes[] = 'htax-show-' . $atts['show_when'];
        if ( 'never' === $atts['show_when'] ) {
            $classes[] = 'htax-hidden';
        }
        if ( 'true' !== $atts['enabled'] ) {
            $classes[] = 'htax-disabled';
        }
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }
        
        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '-wrapper" ';
        $output .= 'data-controller-name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'data-controller-type="dropdown" ';
        $output .= $this->get_group_data_attr();
        $output .= '>';

        // Label
        if ( ! empty( $atts['label'] ) ) {
            $output .= '<label for="' . esc_attr( $atts['id'] ) . '" class="htax-controller-label">';
            $output .= esc_html( $atts['label'] );
            if ( 'true' === $atts['required'] ) {
                $output .= ' <span class="htax-required">*</span>';
            }
            $output .= '</label>';
        }
        
        $output .= '<select id="' . esc_attr( $atts['id'] ) . '" ';
        $output .= 'name="' . esc_attr( $atts['name'] ) . '" ';
        $output .= 'class="htax-dropdown" ';
        if ( 'true' !== $atts['enabled'] ) {
            $output .= 'disabled ';
        }
        $output .= '>';
        
        // Add default empty option if no option has default=true
        $has_default = false;
        foreach ( $options as $option ) {
            if ( $option['default'] ) {
                $has_default = true;
                break;
            }
        }
        
        if ( ! $has_default ) {
            $output .= '<option value="">' . __( 'Select...', 'hypertaxonomy' ) . '</option>';
        }
        
        foreach ( $options as $option ) {
            $selected = $option['default'] ? 'selected' : '';
            $output .= '<option value="' . esc_attr( $option['value'] ) . '" ';
            if ( ! empty( $option['actions'] ) ) {
                $output .= 'data-actions=\'' . esc_attr( json_encode( array( 'actions' => $option['actions'] ) ) ) . '\' ';
            }
            $output .= $selected . '>';
            $output .= esc_html( $option['label'] );
            $output .= '</option>';
        }
        
        $output .= '</select>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Submit button shortcode
     */
    public function submit_shortcode( $atts ) {
        $defaults = array(
            'text' => __( 'Search', 'hypertaxonomy' ),
            'class' => '',
            'show_when' => 'always',
        );
        
        $atts = shortcode_atts( $defaults, $atts );
        
        $classes = array( 'htax-submit-button' );
        $classes[] = 'htax-show-' . $atts['show_when'];
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }
        
        $output = '<button type="submit" class="' . esc_attr( implode( ' ', $classes ) ) . '">';
        $output .= esc_html( $atts['text'] );
        $output .= '</button>';
        
        return $output;
    }
    
    /**
     * Toggle button shortcode
     */
    public function toggle_shortcode( $atts ) {
        $defaults = array(
            'text_collapsed' => __( 'Show advanced options', 'hypertaxonomy' ),
            'text_expanded' => __( 'Hide advanced options', 'hypertaxonomy' ),
            'class' => '',
        );
        
        $atts = shortcode_atts( $defaults, $atts );
        
        $classes = array( 'htax-toggle-button' );
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }
        
        $output = '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '">';
        $output .= '<span class="htax-text-collapsed">' . esc_html( $atts['text_collapsed'] ) . '</span>';
        $output .= '<span class="htax-text-expanded">' . esc_html( $atts['text_expanded'] ) . '</span>';
        $output .= '</button>';
        
        return $output;
    }
    
    /**
     * Results container shortcode
     */
    public function results_shortcode( $atts ) {
        // Get form's orderby
        $form_orderby = 'relevance:desc,date:desc'; // Default
        $form_id = $this->get_current_form_id();
        if ( $form_id && isset( self::$forms[ $form_id ]['atts']['orderby'] ) ) {
            $form_orderby = self::$forms[ $form_id ]['atts']['orderby'];
        }

        $defaults = array(
            'id'                 => 'htax-results-' . self::$instance_counter,
            'template'           => 'default',
            'show_thumbnail'     => 'true',
            'show_excerpt'       => 'true',
            'show_meta'          => 'true',
            'format'             => 'div', // div | ul | ol
            'image_size'         => 'medium',
            'columns'            => '1',
            'prepend'            => '',
            'append'             => '',
            'class'              => '',
            'no_results_message' => '',
            'sort_order_options' => '',
            'filters'            => '',
            'filter_options'     => '',
            'sort_order_align'   => '',
            'filters_align'      => '',
        );

        $atts = shortcode_atts( $defaults, $atts );

        $classes = array( 'htax-search-results' );
        $classes[] = 'htax-template-' . $atts['template'];
        if ( ! empty( $atts['class'] ) ) {
            $classes[] = $atts['class'];
        }

        $columns_int = max( 1, intval( $atts['columns'] ) );
        $col_style   = $columns_int > 1 ? ' style="--htax-columns:' . $columns_int . '"' : '';

        $output = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" ';
        $output .= 'id="' . esc_attr( $atts['id'] ) . '" ';
        $output .= 'data-orderby="' . esc_attr( $form_orderby ) . '" ';
        $output .= 'data-template="' . esc_attr( $atts['template'] ) . '" ';
        $output .= 'data-show-thumbnail="' . esc_attr( $atts['show_thumbnail'] ) . '" ';
        $output .= 'data-show-excerpt="' . esc_attr( $atts['show_excerpt'] ) . '" ';
        $output .= 'data-show-meta="' . esc_attr( $atts['show_meta'] ) . '" ';
        $output .= 'data-image-size="' . esc_attr( sanitize_key( $atts['image_size'] ) ) . '" ';
        $output .= 'data-columns="' . $columns_int . '" ';
        $output .= 'data-prepend="' . esc_attr( $atts['prepend'] ) . '" ';
        $output .= 'data-append="' . esc_attr( $atts['append'] ) . '" ';
        $output .= 'data-no-results-message="' . esc_attr( $atts['no_results_message'] ) . '" ';
        $output .= 'data-format="' . esc_attr( in_array( $atts['format'], array( 'div', 'ul', 'ol' ), true ) ? $atts['format'] : 'div' ) . '"';
        $output .= $col_style . '>';

        $output .= '<div class="htax-results-container"></div>';
        $output .= '<div class="htax-loading" style="display: none;">' . __( 'Loading...', 'hypertaxonomy' ) . '</div>';
        $output .= '<nav class="htax-search-results-nav" aria-label="' . esc_attr__( 'Search results navigation', 'hypertaxonomy' ) . '"></nav>';

        $output .= '</div>'; // .htax-search-results

        // Build sort picker HTML (placed in the controls bar above the results div).
        $sort_html = '';
        if ( ! empty( $atts['sort_order_options'] ) ) {
            htax_enqueue_sort_order_assets();
            $sort_class = ! empty( $atts['sort_order_align'] )
                ? 'htax-align-' . sanitize_html_class( $atts['sort_order_align'] )
                : '';
            $sort_html = htax_render_sort_order_picker( array(
                'fields'        => trim( $atts['sort_order_options'] ),
                'state'         => 'expanded',
                'action'        => '',
                'default_value' => $form_orderby,
                'class'         => $sort_class,
            ) );
        }

        // Build inline filter panel HTML.
        $filter_html = '';
        if ( ! empty( $atts['filters'] ) ) {
            $raw_filter_configs = htax_parse_inline_filters( $atts['filters'], $atts['filter_options'] );
            if ( ! empty( $raw_filter_configs ) ) {
                $filter_class = ! empty( $atts['filters_align'] )
                    ? 'htax-align-' . sanitize_html_class( $atts['filters_align'] )
                    : '';
                $resolved    = htax_resolve_filter_configs( $raw_filter_configs, array(), null );
                $filter_html = htax_render_filter_options( $resolved, array( 'action' => '', 'class' => $filter_class ), array() );
            }
        }

        // Wrap sort picker and/or filter panel in a controls bar above the results div.
        if ( $sort_html || $filter_html ) {
            $controls_bar = '<div class="htax-controls-bar">' . $filter_html . $sort_html . '</div>';
            $output = '<div class="htax-search-results-block">' . $controls_bar . $output . '</div>';
        }

        return $output;
    }

    /**
     * Get terms for field based on configuration
     */
    private function get_field_terms( $atts ) {
        // CRITICAL: Limit term loading during AJAX to prevent memory exhaustion
        $max_terms = ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 100 : 5000;

        $args = array(
            'taxonomy' => $atts['taxonomy'],
            'hide_empty' => false,
            'number' => $max_terms,
            'update_term_meta_cache' => false, // Don't load metadata
        );

        // Order of operations: Include groups → Exclude groups → Include terms → Exclude terms
        // Note: group exclusions are applied mid-way, so individual term includes (step 3) can
        // override group exclusions. Explicit term excludes (step 4) always win.

        $include_ids = array();
        $exclude_ids = array();
        $include_groups_specified = false;
        $include_terms_specified = false;

        // Step 1: Include groups (with hierarchy expansion)
        if ( ! empty( $atts['include_group_options'] ) ) {
            $include_groups_specified = true;
            $group_ids = htax_parse_term_ids( $atts['include_group_options'], 'group' );
            $group_terms = htax_get_terms_in_groups( $group_ids, $atts['taxonomy'] );
            if ( ! empty( $group_terms ) ) {
                $include_ids = array_keys( $group_terms );
            }
        }

        // Step 2: Exclude groups (with hierarchy expansion)
        if ( ! empty( $atts['exclude_group_options'] ) ) {
            $exclude_group_ids = htax_parse_term_ids( $atts['exclude_group_options'], 'group' );
            $exclude_group_terms = htax_get_terms_in_groups( $exclude_group_ids, $atts['taxonomy'] );
            if ( ! empty( $exclude_group_terms ) ) {
                $exclude_ids = array_merge( $exclude_ids, array_keys( $exclude_group_terms ) );
            }
        }

        // Apply exclusions to includes from step 1
        if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
            $include_ids = array_diff( $include_ids, $exclude_ids );
        }

        // Step 3: Include individual terms (overrides group exclusions)
        if ( ! empty( $atts['include_term_options'] ) ) {
            $include_terms_specified = true;
            $individual_include_ids = htax_parse_term_ids( $atts['include_term_options'], $atts['taxonomy'] );
            if ( ! empty( $individual_include_ids ) ) {
                $include_ids = array_unique( array_merge( $include_ids, $individual_include_ids ) );
            }
        }

        // Step 4: Exclude individual terms (final override)
        if ( ! empty( $atts['exclude_term_options'] ) ) {
            $individual_exclude_ids = htax_parse_term_ids( $atts['exclude_term_options'], $atts['taxonomy'] );
            if ( ! empty( $individual_exclude_ids ) ) {
                $exclude_ids = array_merge( $exclude_ids, $individual_exclude_ids );
            }
        }

        // Apply final exclusions
        if ( ! empty( $include_ids ) && ! empty( $exclude_ids ) ) {
            $include_ids = array_diff( $include_ids, $exclude_ids );
        }

        // Apply final include/exclude to get_terms args
        if ( ! empty( $include_ids ) ) {
            $args['include'] = $include_ids;
        } elseif ( ! empty( $exclude_ids ) ) {
            $args['exclude'] = $exclude_ids;
        }

        // Return empty if include filter was specified but resulted in no terms
        // (either invalid IDs or all terms were excluded)
        if ( ( $include_groups_specified || $include_terms_specified ) && empty( $include_ids ) ) {
            return array();
        }

        $terms = get_terms( $args );

        // Always filter out hidden terms from frontend widget term option lists
        if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
            $terms = array_filter( $terms, function( $term ) {
                return ! htax_is_term_hidden( $term->term_id );
            });
        }

        return $terms;
    }

    /**
     * Resolve fields over a term-organizing taxonomy (taxonomy_type="term", e.g. group)
     * into fields over the post taxonomies whose terms belong to the submitted groups.
     *
     * Such taxonomies are attached to no post type, so the search index has no rows for
     * them. Submitted IDs are expanded (with descendant groups) via htax_get_terms_in_groups()
     * into member terms of every taxonomy in the field's 'filterby' list — or every indexed
     * HT taxonomy when 'filterby' is empty. A post is "in" a group when it carries a member
     * term in any of those taxonomies.
     *
     * - match any / none: one field over the members of all selected groups (in place for a
     *   single target taxonomy, an OR sub-group of one field per taxonomy otherwise).
     * - match all: the post must be in every selected group — an AND sub-group with one
     *   any-match member field (or OR sub-group) per selected group.
     *
     * A group with no members matches nothing (term 0) rather than dropping the field.
     *
     * @since 1.0.0
     * @param array $structure Field structure {relation, fields, groups, ...}.
     * @return array
     */
    private function expand_term_type_fields( $structure ) {
        if ( ! is_array( $structure ) ) {
            return $structure;
        }

        if ( isset( $structure['fields'] ) && is_array( $structure['fields'] ) ) {
            foreach ( $structure['fields'] as $field_name => $field ) {
                if ( empty( $field['terms'] ) || empty( $field['taxonomy'] ) ) {
                    continue;
                }
                $tax_obj = get_taxonomy( $field['taxonomy'] );
                if ( ! $tax_obj || ! empty( $tax_obj->object_type ) ) {
                    continue;
                }

                $targets = ! empty( $field['filterby'] )
                    ? array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $field['filterby'] ) ) ) )
                    : array_values( array_filter( htax_get_enabled_taxonomies(), function ( $tax ) {
                        $obj = get_taxonomy( $tax );
                        return $obj && ! empty( $obj->object_type );
                    } ) );
                $group_ids = array_values( array_unique( array_map( 'intval', (array) $field['terms'] ) ) );
                unset( $field['filterby'] );

                if ( 'all' === ( $field['match'] ?? 'any' ) ) {
                    $field['match'] = 'any';
                    $all            = array( 'relation' => 'AND', 'fields' => array(), 'groups' => array() );
                    foreach ( $group_ids as $gid ) {
                        $all['groups'][] = $this->group_member_structure( $field, $field_name . '_g' . $gid, array( $gid ), $targets );
                    }
                    unset( $structure['fields'][ $field_name ] );
                    $structure['groups']   = isset( $structure['groups'] ) && is_array( $structure['groups'] ) ? $structure['groups'] : array();
                    $structure['groups'][] = $all;
                    continue;
                }

                $members = $this->group_member_structure( $field, $field_name, $group_ids, $targets );
                if ( 1 === count( $members['fields'] ) ) {
                    $structure['fields'][ $field_name ] = reset( $members['fields'] );
                    continue;
                }
                unset( $structure['fields'][ $field_name ] );
                $structure['groups']   = isset( $structure['groups'] ) && is_array( $structure['groups'] ) ? $structure['groups'] : array();
                $structure['groups'][] = $members;
            }
        }

        if ( isset( $structure['groups'] ) && is_array( $structure['groups'] ) ) {
            foreach ( $structure['groups'] as $i => $group ) {
                $structure['groups'][ $i ] = $this->expand_term_type_fields( $group );
            }
        }

        return $structure;
    }

    /**
     * Build an OR sub-structure matching posts that carry a member term of $group_ids.
     *
     * One field per target taxonomy that has members; when none has, a single field over
     * term 0 so the selection matches nothing.
     *
     * @since 1.0.0
     * @param array    $field      Original field data (taxonomy, match, expand_types, ...).
     * @param string   $field_name Base name for the generated fields.
     * @param int[]    $group_ids  Group IDs.
     * @param string[] $targets    Post taxonomies to resolve members in.
     * @return array{relation: string, fields: array, groups: array}
     */
    private function group_member_structure( array $field, string $field_name, array $group_ids, array $targets ): array {
        $per_tax = array();
        foreach ( $targets as $tax ) {
            $members = array_map( 'intval', array_keys( htax_get_terms_in_groups( $group_ids, $tax ) ) );
            if ( ! empty( $members ) ) {
                $per_tax[ $tax ] = $members;
            }
        }

        $fields = array();
        if ( empty( $per_tax ) ) {
            $fields[ $field_name ] = array_merge( $field, array(
                'taxonomy' => $targets ? reset( $targets ) : $field['taxonomy'],
                'terms'    => array( 0 ),
            ) );
        } elseif ( 1 === count( $per_tax ) ) {
            $fields[ $field_name ] = array_merge( $field, array( 'taxonomy' => key( $per_tax ), 'terms' => reset( $per_tax ) ) );
        } else {
            foreach ( $per_tax as $tax => $members ) {
                $fields[ $field_name . '_' . $tax ] = array_merge( $field, array( 'taxonomy' => $tax, 'terms' => $members ) );
            }
        }

        return array( 'relation' => 'OR', 'fields' => $fields, 'groups' => array() );
    }

    /**
     * Render form or field-group content, pairing nested [ht_field_group] tags by depth.
     *
     * WordPress's shortcode parser closes an enclosing shortcode at the first matching
     * closing tag, so [ht_field_group] nested inside [ht_field_group] would be split
     * incorrectly by do_shortcode(). The enclosing form receives its raw content intact,
     * so groups are matched here with a depth counter and rendered through
     * field_group_shortcode() (which recurses back into this method for its own content);
     * everything between groups goes through do_shortcode() as usual.
     *
     * @since 1.0.0
     * @param string|null $content Raw shortcode content.
     * @return string Rendered HTML.
     */
    private function render_form_content( $content ) {
        $content = (string) $content;
        $pattern = '/(?<!\[)\[(\/?)ht_field_group\b([^\]]*)\](?!\])/';
        if ( ! preg_match_all( $pattern, $content, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return do_shortcode( $content );
        }

        $output = '';
        $cursor = 0;
        $depth  = 0;
        $open   = null; // [ offset after the opening tag, attribute string, offset of the opening tag ]

        foreach ( $tags as $tag ) {
            $offset = $tag[0][1];
            if ( '/' !== $tag[1][0] ) {
                if ( 0 === $depth ) {
                    $open = array( $offset + strlen( $tag[0][0] ), $tag[2][0], $offset );
                }
                $depth++;
                continue;
            }
            if ( 0 === $depth ) {
                continue; // Stray closing tag — left for do_shortcode().
            }
            $depth--;
            if ( 0 === $depth ) {
                $output .= do_shortcode( substr( $content, $cursor, $open[2] - $cursor ) );
                $atts    = shortcode_parse_atts( trim( $open[1] ) );
                $output .= $this->field_group_shortcode( is_array( $atts ) ? $atts : array(), substr( $content, $open[0], $offset - $open[0] ) );
                $cursor  = $offset + strlen( $tag[0][0] );
            }
        }

        // Anything after the last complete group (including an unclosed one) renders as usual.
        return $output . do_shortcode( substr( $content, $cursor ) );
    }

    /**
     * Recursively flatten field structure to extract all fields for scoring
     *
     * match='none' (exclusion) fields contribute WHERE NOT IN conditions rather than scoring
     * subqueries, so they are left out unless $include_exclusions is true.
     *
     * @param array $structure          Hierarchical field structure
     * @param bool  $include_exclusions Include match='none' fields.
     * @return array Flat array of field_name => field_data
     */
    private function flatten_field_structure_for_scoring( $structure, bool $include_exclusions = false ) {
        $flat_fields = array();

        // Process direct fields at this level.
        if ( isset( $structure['fields'] ) && is_array( $structure['fields'] ) ) {
            foreach ( $structure['fields'] as $field_name => $field_data ) {
                if ( ! empty( $field_data['terms'] ) && ! empty( $field_data['taxonomy'] ) ) {
                    $match = isset( $field_data['match'] ) ? $field_data['match'] : 'any';
                    if ( $include_exclusions || 'none' !== $match ) {
                        $flat_fields[ $field_name ] = $field_data;
                    }
                }
            }
        }

        // Process nested groups recursively
        if ( isset( $structure['groups'] ) && is_array( $structure['groups'] ) ) {
            foreach ( $structure['groups'] as $group ) {
                $group_fields = $this->flatten_field_structure_for_scoring( $group, $include_exclusions );
                $flat_fields = array_merge( $flat_fields, $group_fields );
            }
        }

        return $flat_fields;
    }

    /**
     * Reduce a user-supplied field name to an identifier-safe token.
     *
     * Field names are emitted as bare SQL column aliases (e.g. {$field}_score) and
     * referenced again in HAVING, so they must not contain anything other than
     * word characters. Legitimate field names are already alphanumeric/underscore,
     * so this is a no-op for them and does not change which posts match.
     *
     * @since 1.0.0
     * @param string $field_name Raw field name.
     * @return string Identifier-safe field name.
     */
    private function sanitize_field_identifier( $field_name ) {
        return preg_replace( '/[^a-z0-9_]/i', '', (string) $field_name );
    }

    /**
     * Recursively build HAVING clause from hierarchical field structure
     *
     * @param array $structure Hierarchical field structure
     * @return string SQL HAVING condition
     */
    private function build_having_clause_recursive( $structure ) {
        $conditions = array();
        $relation = isset( $structure['relation'] ) ? strtoupper( $structure['relation'] ) : 'AND';

        // Add conditions for direct fields at this level.
        // match='none' (exclusion) fields generate no HAVING condition — they are
        // enforced via WHERE NOT IN before LIMIT and must not appear here.
        if ( isset( $structure['fields'] ) && is_array( $structure['fields'] ) ) {
            foreach ( $structure['fields'] as $field_name => $field_data ) {
                $match = isset( $field_data['match'] ) ? $field_data['match'] : 'any';
                if ( ! empty( $field_data['terms'] ) && 'none' !== $match ) {
                    // Must match the identifier-safe alias built in get_posts_by_custom_sql().
                    $safe_field_name = $this->sanitize_field_identifier( $field_name );
                    $conditions[] = sprintf( "%s_score > 0", $safe_field_name );
                }
            }
        }

        // Process nested groups recursively
        if ( isset( $structure['groups'] ) && is_array( $structure['groups'] ) ) {
            foreach ( $structure['groups'] as $group ) {
                $group_condition = $this->build_having_clause_recursive( $group );
                if ( ! empty( $group_condition ) ) {
                    $conditions[] = "($group_condition)";
                }
            }
        }

        // Combine conditions with the relation operator
        if ( empty( $conditions ) ) {
            return '';
        }

        if ( 1 === count( $conditions ) ) {
            return $conditions[0];
        }

        return implode( " $relation ", $conditions );
    }

    /**
     * Normalize an expand value (string from JS or array from resolve step) to a list of
     * relationship_type DB values. Returns null to defer to taxonomy feature-flag defaults.
     *
     * Valid string tokens: all, none, container, hierarchy, redirect.
     * Mixing none/all with specific types is invalid and returns null (all behavior).
     *
     * @param mixed $raw String from JS or pre-normalized array from resolve step.
     * @return string[]|null Normalized types, or null for feature-flag defaults.
     */
    private static function normalize_expand_types( $raw ): ?array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        $str = trim( (string) $raw );
        if ( '' === $str || 'all' === $str ) {
            return null;
        }
        if ( 'none' === $str ) {
            return array( 'direct' );
        }
        $map    = array( 'container' => 'container', 'hierarchy' => 'hierarchy', 'redirect' => 'redirect_target' );
        $tokens = array_map( 'trim', explode( ',', $str ) );
        if ( in_array( 'none', $tokens, true ) || in_array( 'all', $tokens, true ) ) {
            return null;
        }
        $allowed = array( 'direct' );
        foreach ( $tokens as $token ) {
            if ( isset( $map[ $token ] ) ) {
                $allowed[] = $map[ $token ];
            }
        }
        return count( $allowed ) > 1 ? array_values( array_unique( $allowed ) ) : null;
    }

    /**
     * Get posts using custom SQL query with taxonomy term relevance scoring and text search
     *
     * Handles three search scenarios:
     * 1. Taxonomy terms only - weighted relevance scoring by relationship types
     * 2. Text search only - LIKE matching on title, content, excerpt
     * 3. Combined - text search as filter + taxonomy relevance scoring
     *
     * Supports dynamic ORDER BY for any combination of fields (relevance, date, title, etc.)
     *
     * @param array $query Search query: hierarchical group spec {relation, fields, groups}
     * @param array $orderby_parsed Parsed orderby parameters
     * @param int $posts_per_page Results per page
     * @param int $page Current page number
     * @param string $text_search_query Optional text search query
     * @param float $min_score Minimum overall relevance score (default 0)
     * @param string $search_in   Comma-separated list of fields to search (title,content,excerpt or 'all')
     * @param string $extra_where Additional SQL WHERE fragment (with leading AND) appended to the query.
     * @param int    $max_results Hard cap on total results across all pages (0 = no cap).
     * @return array{results: int[], total: int}
     */
    public function get_posts_by_custom_sql( $query, $orderby_parsed, $posts_per_page, $page, $text_search_query = '', $min_score = 0, $search_in = 'all', $extra_where = '', $max_results = 0 ) {
        global $wpdb;

        // Proximity tokens (near:) extracted by htax_parse_search_query() below.
        $proximity_tokens = array();
        $boolean_query    = '';

        $query = $this->expand_term_type_fields( $query );

        $flat_fields = $this->flatten_field_structure_for_scoring( $query );

        // Build SQL WHERE conditions for excluded (match='none') and required-all (match='all') fields.
        // These must run before LIMIT so pagination counts are accurate.
        // Both use pre-prepared SQL — no %s added to $query_params, same pattern as PowerSearch.
        $pre_where_parts = array();
        $pt_table        = $wpdb->prefix . 'htax_post_text';

        // Helper: build the relationship_type IN list for a taxonomy, respecting site settings and feature flags.
        $build_rel_list = function( $taxonomy ) {
            $types = htax_get_expand_types_for_taxonomy( $taxonomy );
            return "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
        };

        foreach ( $this->flatten_field_structure_for_scoring( $query, true ) as $field_name => $field_data ) {
            if ( empty( $field_data['terms'] ) || empty( $field_data['taxonomy'] ) ) {
                continue;
            }
            $match     = isset( $field_data['match'] ) ? $field_data['match'] : 'any';
            $taxonomy  = sanitize_key( $field_data['taxonomy'] );
            $term_ids  = array_map( 'intval', $field_data['terms'] );
            if ( empty( $term_ids ) ) {
                continue;
            }
            $id_list      = implode( ',', $term_ids );
            $normalized   = isset( $field_data['expand_types'] )
                ? self::normalize_expand_types( $field_data['expand_types'] )
                : null;
            $rel_list     = null !== $normalized
                ? "'" . implode( "','", array_map( 'esc_sql', $normalized ) ) . "'"
                : $build_rel_list( $taxonomy );
            $hidden       = htax_get_hidden_term_filter_clause();
            $esc_taxonomy = esc_sql( $taxonomy );

            if ( 'none' === $match ) {
                // Exclusion: post must NOT be indexed with any of these terms.
                $pre_where_parts[] = "pt.post_id NOT IN (
                    SELECT post_id FROM {$wpdb->prefix}htax_search_terms
                    WHERE taxonomy = '{$esc_taxonomy}'
                      AND relationship_type IN ({$rel_list})
                      AND term_id IN ({$id_list}){$hidden}
                )";
            } elseif ( 'all' === $match ) {
                // Required-all: post must be indexed with EVERY selected term individually.
                $n = count( $term_ids );
                $pre_where_parts[] = "pt.post_id IN (
                    SELECT post_id FROM {$wpdb->prefix}htax_search_terms
                    WHERE taxonomy = '{$esc_taxonomy}'
                      AND relationship_type IN ({$rel_list})
                      AND term_id IN ({$id_list}){$hidden}
                    GROUP BY post_id
                    HAVING COUNT(DISTINCT term_id) >= {$n}
                )";
            }
        }

        // Build strength-based scoring subqueries — one per field.
        // Relationship-type weights are baked into the strength column at index time,
        // so a single subquery per field suffices; no per-type weighting at query time.
        $score_subqueries = array();
        $query_params     = array();
        $field_names      = array();

        foreach ( $flat_fields as $field_name => $field_data ) {
            if ( empty( $field_data['terms'] ) || empty( $field_data['taxonomy'] ) ) {
                continue;
            }
            // Exclusion fields are handled via WHERE NOT IN above; skip scoring for them.
            if ( isset( $field_data['match'] ) && 'none' === $field_data['match'] ) {
                continue;
            }

            $taxonomy          = sanitize_key( $field_data['taxonomy'] );
            $selected_term_ids = array_map( 'intval', $field_data['terms'] );

            if ( ! in_array( $field_name, $field_names, true ) ) {
                $field_names[] = $field_name;
            }

            // Collect the relationship types active for this taxonomy.
            // Fields with an explicit expand_types value override the feature-flag computation.
            $normalized_expand  = isset( $field_data['expand_types'] )
                ? self::normalize_expand_types( $field_data['expand_types'] )
                : null;
            $relationship_types = null !== $normalized_expand
                ? $normalized_expand
                : htax_get_expand_types_for_taxonomy( $taxonomy );

            $rel_types_in  = "'" . implode( "','", array_map( 'esc_sql', $relationship_types ) ) . "'";
            $term_ids_str  = implode( ',', $selected_term_ids );
            $hidden_clause = htax_get_hidden_term_filter_clause();

            // Per-term score combines all rows for a (post, term) pair via noisy-OR
            // (1 - Π(1 - strength)) — multiple paths to the same term (multiple source
            // terms, or one source reached via more than one relationship type) raise the
            // term's score monotonically while staying strictly below 1.0, the ceiling a
            // single direct row can reach. A term reached by exactly one path is
            // unaffected. SUM then keeps the breadth of distinct query terms as the
            // field's total relevance. Relationship-type weights are already encoded in
            // strength at index build time.
            // LEAST(1, ...) bounds (1 - strength): every stored strength is in [0,1] (built-in
            // formula, and htax_set_term_strengths() rejects out-of-range scores), so this only
            // guards against a negative value written to the table directly, which would push
            // the noisy-OR product above 1 and yield a negative combined score.
            $score_subqueries[] = sprintf(
                "(SELECT post_id,
                  '%s' as field_name,
                  SUM(term_score) as field_score
                 FROM (
                   SELECT post_id, term_id,
                          1 - EXP(SUM(LN(LEAST(1, GREATEST(1 - strength, 0.000001))))) as term_score
                   FROM {$wpdb->prefix}htax_search_terms
                   WHERE taxonomy = %%s
                   AND relationship_type IN ($rel_types_in)
                   AND term_id IN ($term_ids_str)%s
                   GROUP BY post_id, term_id
                 ) t
                 GROUP BY post_id)",
                $this->sanitize_field_identifier( $field_name ),
                $hidden_clause . htax_strength_model_sql()
            );
            $query_params[] = $taxonomy;
        }

        // If no taxonomy terms but has text search, we can still proceed
        // (will use text search filter in WHERE clause without relevance scoring)
        // (exclusion-only queries also proceed: every post except the excluded ones).
        if ( empty( $score_subqueries ) && empty( $text_search_query ) && empty( $pre_where_parts ) ) {
            return array( 'results' => array(), 'total' => 0 );
        }

        // Union all field scores (may be empty if text-only search)
        $scores_union = ! empty( $score_subqueries ) ? implode( ' UNION ALL ', $score_subqueries ) : '';

        // Calculate pagination offset and effective page limit
        $offset = ( $page - 1 ) * $posts_per_page;
        $max_results = max( 0, (int) $max_results );
        if ( $max_results > 0 && $offset >= $max_results ) {
            return array( 'results' => array(), 'total' => 0 );
        }
        $effective_limit = ( $max_results > 0 ) ? min( $posts_per_page, $max_results - $offset ) : $posts_per_page;

        // Build WHERE clause for post filtering
        $indexed_post_types = htax_get_indexed_post_types();
        $post_type_list     = implode( "','", array_map( 'esc_sql', $indexed_post_types ) );
        $where_parts = array( "pt.post_type IN ('$post_type_list')", "pt.post_status = 'publish'" );

        // Add text search filter if present
        if ( ! empty( $text_search_query ) ) {
            // Parse search_in parameter to determine which fields to search
            // Default: 'all' (title, content, excerpt)
            $search_in_values = array_map( 'trim', explode( ',', $search_in ) );

            // Build list of fields to search based on search_in parameter
            $search_fields = array();

            if ( in_array( 'all', $search_in_values, true ) ) {
                // Search all text fields
                $search_fields = array( 'title', 'content', 'excerpt' );
            } else {
                // Parse individual values
                foreach ( $search_in_values as $value ) {
                    if ( in_array( $value, array( 'title', 'content', 'excerpt' ), true ) ) {
                        $search_fields[] = $value;
                    }
                }
            }

            // Build WHERE clause using FULLTEXT search with boolean operators
            if ( ! empty( $search_fields ) ) {
                // Parse query — field operators (title:, content:, excerpt:) are merged with
                // general terms so any field-qualified keyword still reaches the MATCH expression.
                // Taxonomy operators (tags:, terms:, etc.) are irrelevant here and silently ignored.
                $parsed           = htax_parse_search_query( $text_search_query );
                $proximity_tokens = $parsed['proximity'] ?? array();
                $all_terms        = array_merge(
                    $parsed['general'],
                    $parsed['title'],
                    $parsed['body'],
                    $parsed['excerpt']
                );
                $boolean_query = htax_build_boolean_query_string( $all_terms );

                if ( ! empty( $boolean_query ) ) {
                    $pt_col_map       = array(
                        'title'   => 'pt.title',
                        'content' => 'pt.body',
                        'excerpt' => 'pt.excerpt',
                    );
                    $field_conditions = array();
                    foreach ( $search_fields as $field ) {
                        $column             = $pt_col_map[ $field ] ?? 'pt.body';
                        $field_conditions[] = "MATCH($column) AGAINST(%s IN BOOLEAN MODE)";
                        $query_params[]     = $boolean_query;
                    }
                    $where_parts[] = '(' . implode( ' OR ', $field_conditions ) . ')';
                }
            }
        }

        // Merge exclusion/required-all conditions (built before LIMIT) into the WHERE clause.
        if ( ! empty( $pre_where_parts ) ) {
            $where_parts = array_merge( $where_parts, $pre_where_parts );
        }

        $where_clause = implode( ' AND ', $where_parts );

        if ( ! empty( $extra_where ) ) {
            $where_clause .= ' ' . $extra_where;
        }

        // near: tokens become a WHERE condition, applied before LIMIT so pages and totals
        // reflect the filtered set.
        if ( ! empty( $proximity_tokens ) ) {
            $proximity_where = (string) apply_filters( 'htax_search_proximity_where', '', $proximity_tokens, 'pt.post_id' );
            if ( '' !== $proximity_where ) {
                $where_clause .= ' AND ' . $proximity_where;
            }
        }

        // Main query: sum all field scores per post, filter, order, paginate
        if ( ! empty( $scores_union ) ) {
            // Has taxonomy terms - use relevance scoring with per-field tracking

            // Build CASE statements for each field to track per-field scores
            $field_score_selects = array();

            foreach ( $field_names as $field_name ) {
                // Field names are used both as a string literal (compared against the
                // field_name column) and as a bare SQL column alias (%s_score). Reduce to
                // an identifier-safe form so the alias cannot inject SQL; the same form is
                // stored in the field_name column above, so the comparison still matches.
                $safe_field_name = $this->sanitize_field_identifier( $field_name );
                // Build a score column for this field using CASE statement
                $field_score_selects[] = sprintf(
                    "MAX(CASE WHEN scores.field_name = '%s' THEN scores.field_score ELSE 0 END) as %s_score",
                    $safe_field_name,
                    $safe_field_name
                );
            }

            $main_sql = "
                SELECT pt.post_id,
                       " . implode( ",\n                       ", $field_score_selects ) . ",
                       COALESCE(SUM(scores.field_score), 0) as total_relevance
                FROM {$pt_table} pt
                LEFT JOIN ($scores_union) AS scores ON scores.post_id = pt.post_id
                WHERE $where_clause
                GROUP BY pt.post_id
            ";

            // Build HAVING clause recursively to preserve field group structure
            $having_condition = $this->build_having_clause_recursive( $query );

            if ( $min_score > 0 && ! empty( $having_condition ) ) {
                $having_clause = sprintf( "total_relevance >= %f AND (%s)", floatval( $min_score ), $having_condition );
            } elseif ( $min_score > 0 ) {
                $having_clause = sprintf( "total_relevance >= %f", floatval( $min_score ) );
            } elseif ( ! empty( $having_condition ) ) {
                $having_clause = $having_condition;
            } else {
                $having_clause = '1=1'; // No filtering
            }

            $main_sql .= " HAVING " . $having_clause;
        } else {
            // Text-only search - no relevance scoring needed. A text field holding only near:
            // tokens is scored by distance, so relevance ordering is nearest first.
            $text_only_relevance = ( ! empty( $proximity_tokens ) && '' === $boolean_query )
                ? (string) apply_filters( 'htax_search_proximity_relevance', '0', $proximity_tokens, 'pt.post_id' )
                : '0';
            $main_sql = "
                SELECT pt.post_id,
                       {$text_only_relevance} as relevance
                FROM {$pt_table} pt
                WHERE $where_clause
            ";
        }

        // Build dynamic ORDER BY clause
        $relevance_column = ! empty( $scores_union ) ? 'total_relevance' : 'relevance';
        if ( ! empty( $orderby_parsed ) ) {
            $main_sql .= " ORDER BY " . htax_build_orderby_sql( $orderby_parsed, 'pt', $relevance_column );
        } else {
            // No valid orderby: default to relevance first, then date
            $main_sql .= " ORDER BY $relevance_column DESC, pt.post_date_gmt DESC, pt.post_id DESC";
        }

        // Add pagination
        $main_sql .= sprintf( " LIMIT %d OFFSET %d", $effective_limit, $offset );

        // Execute main query
        $prepared_sql = ! empty( $query_params ) ? $wpdb->prepare( $main_sql, ...$query_params ) : $main_sql;

        $results = $wpdb->get_results( $prepared_sql );

        // Get total count (same query without LIMIT)
        if ( ! empty( $scores_union ) ) {
            // Has taxonomy terms - use relevance filtering.
            // The inner SELECT must expose the same per-field _score aliases as the main
            // query so the recursive HAVING ($having_condition) can be applied identically;
            // otherwise AND-grouped fields produce a count larger than the actual result
            // set (ghost pages). The relevance column is named 'relevance' here, so the
            // overall min_score threshold uses that name.
            $count_sql = "
                SELECT COUNT(*) as total
                FROM (
                    SELECT pt.post_id,
                           " . implode( ",\n                           ", $field_score_selects ) . ",
                           COALESCE(SUM(scores.field_score), 0) as relevance
                    FROM {$pt_table} pt
                    LEFT JOIN ($scores_union) AS scores ON scores.post_id = pt.post_id
                    WHERE $where_clause
                    GROUP BY pt.post_id
            ";

            // Mirror the main query's HAVING exactly (with total_relevance -> relevance)
            // so the count matches the displayed result set. $having_condition must be
            // applied even when a text search is present, or found_posts/max_pages would
            // overstate AND-grouped fields (ghost pages).
            if ( $min_score > 0 && ! empty( $having_condition ) ) {
                $count_having = sprintf( "relevance >= %f AND (%s)", floatval( $min_score ), $having_condition );
            } elseif ( $min_score > 0 ) {
                $count_having = sprintf( "relevance >= %f", floatval( $min_score ) );
            } elseif ( ! empty( $having_condition ) ) {
                $count_having = $having_condition;
            } elseif ( empty( $text_search_query ) ) {
                // No per-field HAVING and no text search: the main query's default ORDER BY
                // relevance with 1=1 returns 0-score rows last, so the count includes only
                // scored rows to match.
                $count_having = "relevance > 0";
            } else {
                $count_having = ''; // Text search only: WHERE already filters.
            }

            if ( '' !== $count_having ) {
                $count_sql .= " HAVING " . $count_having;
            }

            $count_sql .= ") AS filtered_posts";
        } else {
            // Text-only search - simple count
            $count_sql = "
                SELECT COUNT(*) as total
                FROM {$pt_table} pt
                WHERE $where_clause
            ";
        }

        $total_count = $wpdb->get_var( $wpdb->prepare( $count_sql, ...$query_params ) );

        // Format results
        $post_ids = array_map( function( $row ) {
            return (int) $row->post_id;
        }, $results );

        return array(
            'results' => $post_ids,
            'total'   => intval( $total_count ),
        );
    }

    /**
     * Get current form ID from context
     */
    private function get_current_form_id() {
        // Return the last form ID being processed
        if ( ! empty( self::$forms ) ) {
            $keys = array_keys( self::$forms );
            return end( $keys );
        }
        return null;
    }

    /**
     * AJAX handler for getting terms from configuration
     */
    /**
     * AJAX handler: return all option terms for a set of groups, grouped by group ID.
     * Used by PillsField to prefetch the full option universe on page load so that
     * subsequent autocomplete and controller-driven filtering can run client-side.
     *
     * Request params: taxonomy, groups[] (group IDs), show_icons, nonce.
     * Response: { terms: { id: {name, icon_url, placeholder_color} }, groups: { groupId: [termIds] } }
     */
    public function ajax_prefetch_options() {
        if ( ! $this->validate_ajax_request() ) {
            return;
        }

        $taxonomy      = isset( $_GET['taxonomy'] )      ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) )      : 'post_tag';
        $taxonomy_type = isset( $_GET['taxonomy_type'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy_type'] ) ) : 'post';
        $show_icons    = isset( $_GET['show_icons'] )    ? sanitize_key( wp_unslash( $_GET['show_icons'] ) )    : 'never';

        if ( isset( $_GET['roots'] ) ) {
            // Descendant mode: roots[] drives which subtree to expand.
            // taxonomy_type controls whether descendant groups or their member terms become options.
            $root_ids         = array_map( 'intval', (array) wp_unslash( $_GET['roots'] ) );
            $exclude_desc_ids = isset( $_GET['exclude_descendants'] ) ? array_map( 'intval', (array) wp_unslash( $_GET['exclude_descendants'] ) ) : array();
            $this->send_prefetch_descendant_options( $taxonomy, $root_ids, $show_icons, $exclude_desc_ids, $taxonomy_type );
            return;
        }

        $group_ids = isset( $_GET['groups'] ) ? array_map( 'intval', (array) wp_unslash( $_GET['groups'] ) ) : array();

        if ( 'term' === $taxonomy_type ) {
            // Direct term mode: the group IDs themselves are the options (no member expansion).
            $this->send_prefetch_direct_options( $taxonomy, $group_ids, $show_icons );
            return;
        }

        if ( empty( $group_ids ) || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_success( array( 'terms' => new stdClass(), 'groups' => new stdClass() ) );
            return;
        }

        $all_term_ids = array();
        $groups_map   = array();

        foreach ( $group_ids as $gid ) {
            $members                = htax_get_terms_in_groups( array( $gid ), $taxonomy );
            $member_ids             = array_keys( $members );
            $groups_map[ $gid ]     = $member_ids;
            $all_term_ids           = array_merge( $all_term_ids, $member_ids );
        }

        $all_term_ids = array_unique( $all_term_ids );

        // Remove hidden terms
        $all_term_ids = array_values( array_filter( $all_term_ids, function( $id ) {
            return ! htax_is_term_hidden( $id );
        } ) );

        // Propagate hidden-term removal into groups map
        $visible_set = array_flip( $all_term_ids );
        foreach ( $groups_map as &$member_ids ) {
            $member_ids = array_values( array_filter( $member_ids, function( $id ) use ( $visible_set ) {
                return isset( $visible_set[ $id ] );
            } ) );
        }
        unset( $member_ids );

        if ( empty( $all_term_ids ) ) {
            wp_send_json_success( array( 'terms' => new stdClass(), 'groups' => $groups_map ) );
            return;
        }

        $terms = get_terms( array(
            'taxonomy'               => $taxonomy,
            'include'                => $all_term_ids,
            'hide_empty'             => false,
            'update_term_meta_cache' => false,
        ) );

        $terms_data = ! is_wp_error( $terms ) ? $this->build_terms_data( $terms, $taxonomy, $show_icons ) : array();

        wp_send_json_success( array(
            'terms'  => empty( $terms_data ) ? new stdClass() : $terms_data,
            'groups' => $groups_map,
        ) );
    }

    /**
     * Build and send the prefetch response for include_descendant_options.
     *
     * taxonomy_type=term: descendant group terms ARE the options; groups_map maps each
     *   group to the descendants within the universe (for controller narrowing).
     * taxonomy_type=post: member terms of the descendant groups are the options; groups_map
     *   maps each descendant group to its member terms (controller include_group_options
     *   activates a group to restrict which members are shown).
     */
    private function send_prefetch_descendant_options( $taxonomy, $root_ids, $show_icons, $exclude_desc_ids = array(), $taxonomy_type = 'post' ) {
        if ( empty( $root_ids ) || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_success( array( 'terms' => new stdClass(), 'groups' => new stdClass() ) );
            return;
        }

        // Collect all descendants across all roots (roots themselves excluded).
        $all_descendant_ids = array();
        foreach ( $root_ids as $root_id ) {
            $children = get_term_children( $root_id, $taxonomy );
            if ( ! is_wp_error( $children ) ) {
                $all_descendant_ids = array_merge( $all_descendant_ids, array_map( 'intval', $children ) );
            }
        }
        $all_descendant_ids = array_unique( $all_descendant_ids );

        // Prune branches: keep specified nodes but remove their entire subtrees.
        if ( ! empty( $exclude_desc_ids ) ) {
            $subtree_remove = array();
            foreach ( $exclude_desc_ids as $branch_id ) {
                $branch_children = get_term_children( $branch_id, $taxonomy );
                if ( ! is_wp_error( $branch_children ) ) {
                    $subtree_remove = array_merge( $subtree_remove, array_map( 'intval', $branch_children ) );
                }
            }
            if ( ! empty( $subtree_remove ) ) {
                $all_descendant_ids = array_values( array_diff( $all_descendant_ids, $subtree_remove ) );
            }
        }

        if ( empty( $all_descendant_ids ) ) {
            wp_send_json_success( array( 'terms' => new stdClass(), 'groups' => new stdClass() ) );
            return;
        }

        if ( 'term' === $taxonomy_type ) {
            // Options are the descendant group terms themselves.
            $universe_set = array_flip( $all_descendant_ids );
            $groups_map   = array();
            foreach ( array_merge( $root_ids, $all_descendant_ids ) as $gid ) {
                $desc = get_term_children( $gid, $taxonomy );
                if ( ! is_wp_error( $desc ) ) {
                    $in_universe = array_values( array_filter( array_map( 'intval', $desc ), function( $id ) use ( $universe_set ) {
                        return isset( $universe_set[ $id ] );
                    } ) );
                    if ( ! empty( $in_universe ) ) {
                        $groups_map[ $gid ] = $in_universe;
                    }
                }
            }
            $option_terms = get_terms( array(
                'taxonomy'               => $taxonomy,
                'include'                => $all_descendant_ids,
                'hide_empty'             => false,
                'orderby'                => 'name',
                'order'                  => 'ASC',
                'update_term_meta_cache' => false,
            ) );
        } else {
            // Post mode: options are the member terms of the descendant groups.
            $all_term_ids = array();
            $groups_map   = array();
            foreach ( $all_descendant_ids as $gid ) {
                $members            = htax_get_terms_in_groups( array( $gid ), $taxonomy );
                $member_ids         = array_keys( $members );
                $groups_map[ $gid ] = $member_ids;
                $all_term_ids       = array_merge( $all_term_ids, $member_ids );
            }
            $all_term_ids = array_unique( $all_term_ids );

            // Remove hidden terms and propagate removal into groups_map.
            $all_term_ids = array_values( array_filter( $all_term_ids, function( $id ) {
                return ! htax_is_term_hidden( $id );
            } ) );
            $visible_set = array_flip( $all_term_ids );
            foreach ( $groups_map as &$member_ids ) {
                $member_ids = array_values( array_filter( $member_ids, function( $id ) use ( $visible_set ) {
                    return isset( $visible_set[ $id ] );
                } ) );
            }
            unset( $member_ids );

            $option_terms = get_terms( array(
                'taxonomy'               => $taxonomy,
                'include'                => $all_term_ids,
                'hide_empty'             => false,
                'update_term_meta_cache' => false,
            ) );
        }

        $terms_data = ! is_wp_error( $option_terms ) ? $this->build_terms_data( $option_terms, $taxonomy, $show_icons ) : array();

        wp_send_json_success( array(
            'terms'  => empty( $terms_data ) ? new stdClass() : $terms_data,
            'groups' => empty( $groups_map )  ? new stdClass() : $groups_map,
        ) );
    }

    /**
     * Build and send the prefetch response for include_group_options with taxonomy_type=term.
     *
     * The specified group IDs are the options themselves (no member expansion).
     * groups_map maps each group ID to itself so controller include_group_options actions
     * correctly narrow the visible set to specific groups.
     */
    private function send_prefetch_direct_options( $taxonomy, $group_ids, $show_icons ) {
        if ( empty( $group_ids ) || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_success( array( 'terms' => new stdClass(), 'groups' => new stdClass() ) );
            return;
        }

        $option_terms = get_terms( array(
            'taxonomy'               => $taxonomy,
            'include'                => $group_ids,
            'hide_empty'             => false,
            'update_term_meta_cache' => false,
        ) );

        $terms_data = ! is_wp_error( $option_terms ) ? $this->build_terms_data( $option_terms, $taxonomy, $show_icons ) : array();
        $groups_map = array();
        foreach ( array_keys( $terms_data ) as $term_id ) {
            $groups_map[ $term_id ] = array( $term_id );
        }

        wp_send_json_success( array(
            'terms'  => empty( $terms_data ) ? new stdClass() : $terms_data,
            'groups' => empty( $groups_map )  ? new stdClass() : $groups_map,
        ) );
    }

    /**
     * AJAX handler: search-as-you-type term suggestions for pills fields with prefetch=false.
     *
     * Request params: taxonomy, groups (comma-separated group IDs to search within),
     *   search (user-typed string), show_icons, nonce.
     * Response: [ { id, name, count, icon_url, placeholder_color }, ... ] — max 20 results.
     * When search is empty, returns top terms by count. When groups is empty, searches all
     * terms in the taxonomy.
     */
    public function ajax_autocomplete_options() {
        if ( ! $this->validate_ajax_request() ) {
            return;
        }

        $taxonomy   = isset( $_GET['taxonomy'] )   ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) )           : 'post_tag';
        $search     = isset( $_GET['search'] )     ? sanitize_text_field( wp_unslash( $_GET['search'] ) )       : '';
        $show_icons = isset( $_GET['show_icons'] ) ? sanitize_key( wp_unslash( $_GET['show_icons'] ) )          : 'never';
        $groups_raw = isset( $_GET['groups'] )     ? sanitize_text_field( wp_unslash( $_GET['groups'] ) )       : '';

        if ( ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_success( array() );
            return;
        }

        // Resolve group IDs to the set of allowed term IDs.
        $include_ids = array();
        if ( $groups_raw !== '' ) {
            $group_ids = array_filter( array_map( 'intval', explode( ',', $groups_raw ) ) );
            foreach ( $group_ids as $gid ) {
                $members     = htax_get_terms_in_groups( array( $gid ), $taxonomy );
                $include_ids = array_merge( $include_ids, array_map( 'intval', array_keys( $members ) ) );
            }
            $include_ids = array_unique( $include_ids );

            // Filter out hidden terms.
            $include_ids = array_values( array_filter( $include_ids, function ( $id ) {
                return ! htax_is_term_hidden( $id );
            } ) );

            if ( empty( $include_ids ) ) {
                wp_send_json_success( array() );
                return;
            }
        }

        $args = array(
            'taxonomy'               => $taxonomy,
            'hide_empty'             => false,
            'number'                 => 20,
            'update_term_meta_cache' => false,
        );

        if ( $search !== '' ) {
            $args['search']  = $search;
            $args['orderby'] = 'name';
        } else {
            $args['orderby'] = 'count';
            $args['order']   = 'DESC';
        }

        if ( ! empty( $include_ids ) ) {
            $args['include'] = $include_ids;
        }

        $terms      = get_terms( $args );
        $terms_data = ! is_wp_error( $terms ) ? $this->build_terms_data( $terms, $taxonomy, $show_icons ) : array();

        $result = array();
        foreach ( $terms_data as $id => $data ) {
            $result[] = array_merge( array( 'id' => (int) $id ), $data );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler for post search
     */
    public function ajax_search_posts() {
        if ( ! $this->validate_ajax_request() ) {
            return;
        }

        $query = isset( $_POST['fields'] ) ? wp_unslash( (array) $_POST['fields'] ) : array();

        if ( ! is_array( $query ) ) {
            wp_send_json_error( 'Invalid fields format' );
            return;
        }

        /**
         * Filters the list of allowed field type slugs.
         * Integrations (e.g. ATCF distance filter) use this to register extra types.
         *
         * @since 1.0.0
         * @param string[] $types Allowed type slugs.
         */
        $allowed_types = apply_filters( 'htax_allowed_field_types', array( 'pills', 'checklist', 'text', 'hidden' ) );
        if ( ! $this->validate_field_types_recursive( $query, $allowed_types ) ) {
            wp_send_json_error( 'Invalid field type' );
            return;
        }
        
        $page           = isset( $_POST['page'] )           ? intval( $_POST['page'] )                        : 1;
        $posts_per_page = isset( $_POST['posts_per_page'] ) ? intval( $_POST['posts_per_page'] )               : htax_get_search_results_per_page();
        $max_results    = isset( $_POST['max_results'] )    ? max( 0, intval( $_POST['max_results'] ) )        : 0;
        $min_score      = isset( $_POST['min_score'] )      ? max( 0.0, floatval( $_POST['min_score'] ) )      : 0.0;
        $image_size     = isset( $_POST['image_size'] )     ? sanitize_key( wp_unslash( $_POST['image_size'] ) ) : 'medium';

        // Parse orderby parameter early so it's available for text search
        $orderby_string = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'relevance:desc,date:desc';
        $orderby_parsed = htax_parse_orderby( $orderby_string );

        // Extract text search using helper method
        $text_search_data  = $this->extract_text_search_from_fields( $query );
        $text_search_query = $text_search_data['query'];
        $text_search_in    = $text_search_data['search_in'];
        $has_text_search   = ! empty( $text_search_query );

        $has_taxonomy_terms = $this->has_taxonomy_terms_in_fields( $query );

        // Build extra WHERE from filter state
        $extra_where = '';
        $raw_filter = isset( $_POST['htax_filter'] ) ? wp_unslash( $_POST['htax_filter'] ) : '';
        if ( '' !== $raw_filter ) {
            $filter_state = htax_parse_filter_state_ajax( $raw_filter );
            if ( ! empty( $filter_state ) ) {
                $extra_where = htax_build_filter_sql( $filter_state );
            }
        }

        /**
         * Filters the search widget fields array before the SQL query runs.
         *
         * Integrations can use this to resolve special field types (e.g. distance
         * fields) into standard term ID sets before get_posts_by_custom_sql runs.
         *
         * @since 1.0.0
         * @param array $query The search query spec {relation, fields, groups, text_search}.
         */
        $query = apply_filters( 'htax_pre_search_fields', $query );

        $search_result = $this->get_posts_by_custom_sql(
            $query,
            $orderby_parsed,
            $posts_per_page,
            $page,
            $text_search_query,
            $min_score,
            $text_search_in,
            $extra_where,
            $max_results
        );
        $all_post_ids = $search_result['results'];
        $total_found = $search_result['total'];

        if ( empty( $all_post_ids ) ) {
            wp_send_json_success( array(
                'posts'        => array(),
                'found_posts'  => 0,
                'max_pages'    => 0,
                'current_page' => $page,
                'notices'      => htax_get_search_notices(),
            ) );
            return;
        }

        // Get full post objects in the order returned by SQL. The SQL already applied every
        // filter and the page LIMIT, so this query must not drop or add posts: it covers all
        // indexed post types and ignores sticky posts (an argument-less query is "home",
        // where WordPress would prepend every sticky post).
        $query = new WP_Query( array(
            'post__in'            => $all_post_ids,
            'orderby'             => 'post__in',
            'posts_per_page'      => count( $all_post_ids ),
            'post_type'           => htax_get_indexed_post_types(),
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ) );

        $effective_total = ( $max_results > 0 ) ? min( $total_found, $max_results ) : $total_found;

        $results = array(
            'posts'        => array(),
            'found_posts'  => $effective_total,
            'max_pages'    => $posts_per_page > 0 ? (int) ceil( $effective_total / $posts_per_page ) : 1,
            'current_page' => $page,
            'notices'      => htax_get_search_notices(),
        );

        foreach ( $query->posts as $post ) {
            $results['posts'][] = $this->format_post_for_ajax( $post, $image_size );
        }

        wp_send_json_success( $results );

    }

    /**
     * Format a post object for AJAX response
     *
     * @param WP_Post $post Post object to format
     * @return array Formatted post data
     */
    private function format_post_for_ajax( $post, $image_size = 'medium' ) {
        return htax_format_search_result_post( $post, $image_size );
    }

    /**
     * Extract text search query and search_in parameter from a query spec.
     *
     * @param array $query            Search query spec {relation, fields, groups, text_search}.
     * @param int   $default_min_length Default minimum search length (default 3).
     * @return array Array with 'query' and 'search_in' keys.
     */
    public function extract_text_search_from_fields( $query, $default_min_length = 3 ) {
        if ( empty( $query ) || ! isset( $query['text_search'] ) || ! is_array( $query['text_search'] ) ) {
            return array( 'query' => '', 'search_in' => 'all' );
        }

        $ts         = $query['text_search'];
        $value      = isset( $ts['value'] ) ? trim( $ts['value'] ) : '';
        $min_length = isset( $ts['min_length'] ) ? intval( $ts['min_length'] ) : $default_min_length;
        if ( empty( $value ) || mb_strlen( $value ) < $min_length ) {
            return array( 'query' => '', 'search_in' => 'all' );
        }
        return array(
            'query'     => sanitize_text_field( $value ),
            'search_in' => isset( $ts['search_in'] ) ? $ts['search_in'] : 'all',
        );
    }

    /**
     * Check if a query spec contains any taxonomy terms.
     *
     * @param array $query Search query spec {relation, fields, groups}.
     * @return bool True if taxonomy terms present.
     */
    public function has_taxonomy_terms_in_fields( $query ) {
        if ( empty( $query ) ) {
            return false;
        }

        if ( ! empty( $query['fields'] ) && is_array( $query['fields'] ) ) {
            foreach ( $query['fields'] as $field ) {
                if ( ! empty( $field['terms'] ) ) {
                    return true;
                }
            }
        }

        if ( ! empty( $query['groups'] ) ) {
            foreach ( $query['groups'] as $group ) {
                if ( $this->has_taxonomy_terms_in_fields( $group ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Recursively validate that every field's type is in the allow-list.
     *
     * Mirrors has_taxonomy_terms_in_fields()'s recursion so fields nested inside
     * groups are validated, not just top-level fields.
     *
     * @since 1.0.0
     * @param array    $query         Hierarchical field structure (fields + groups).
     * @param string[] $allowed_types Allowed field type slugs.
     * @return bool True if all fields are valid, false otherwise.
     */
    private function validate_field_types_recursive( $query, $allowed_types ) {
        if ( ! empty( $query['fields'] ) && is_array( $query['fields'] ) ) {
            foreach ( $query['fields'] as $field_data ) {
                if ( ! is_array( $field_data ) ) {
                    return false;
                }
                if ( isset( $field_data['type'] ) && ! in_array( $field_data['type'], $allowed_types, true ) ) {
                    return false;
                }
            }
        }

        if ( ! empty( $query['groups'] ) && is_array( $query['groups'] ) ) {
            foreach ( $query['groups'] as $group ) {
                if ( ! is_array( $group ) ) {
                    return false;
                }
                if ( ! $this->validate_field_types_recursive( $group, $allowed_types ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check rate limit
     */
    /**
     * Verify nonce and rate limit for AJAX handlers.
     * Sends a JSON error and returns false if either check fails.
     *
     * @return bool True if request is valid, false if error was already sent.
     */
    private function validate_ajax_request(): bool {
        if ( ! check_ajax_referer( 'htax-search-widget', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
            return false;
        }
        if ( ! $this->check_rate_limit() ) {
            wp_send_json_error( array( 'message' => 'Rate limit exceeded' ), 429 );
            return false;
        }
        return true;
    }

    private function check_rate_limit() {
        $options   = get_option( 'htax_settings', array() );
        $limit     = isset( $options['rate_limit'] ) ? max( 10, intval( $options['rate_limit'] ) ) : 30;

        $user_key  = is_user_logged_in() ? 'user_' . get_current_user_id() : 'ip_' . $this->get_client_ip();
        $unique    = md5( $user_key . ( isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '' ) );
        $cache_key = 'htax_rate_' . $unique;

        $count = wp_cache_incr( $cache_key, 1, 'htax_rate_limits' );
        if ( false === $count ) {
            wp_cache_set( $cache_key, 1, 'htax_rate_limits', MINUTE_IN_SECONDS );
            $count = 1;
        }

        return $count <= $limit;
    }
    
    private function get_client_ip() {
        if ( isset( $_SERVER['REMOTE_ADDR'] ) && filter_var( $_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP ) ) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return '0.0.0.0';
    }

    /**
     * Enqueue widget scripts and styles.
     *
     * @param bool $in_footer Pass false when already printing in the footer.
     */
    private function enqueue_assets( $in_footer = true ) {
        if ( $this->assets_enqueued ) {
            return;
        }
        $this->assets_enqueued = true;

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'jquery-ui-autocomplete' );

        wp_enqueue_style(
            'jquery-ui',
            'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.min.css',
            array(),
            '1.13.2'
        );

        // near:me uses As the Crow Flies' browser-location helpers when ATCF is active.
        wp_enqueue_script( 'htax-geo', HTAX_PLUGIN_URL . 'assets/js/htax-geo.js', function_exists( 'atcf_get_visitor_location' ) ? array( 'atcf-frontend' ) : array(), HTAX_VERSION, $in_footer );
        wp_enqueue_script(
            'htax-search-widget',
            HTAX_PLUGIN_URL . 'assets/js/htax-search-widget.js',
            array( 'jquery', 'jquery-ui-autocomplete', 'htax-geo' ),
            HTAX_VERSION,
            $in_footer
        );

        wp_enqueue_style(
            'htax-search-widget',
            HTAX_PLUGIN_URL . 'assets/css/htax-search-widget.css',
            array( 'jquery-ui' ),
            HTAX_VERSION
        );

        wp_localize_script( 'htax-search-widget', 'HTAX_Search', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'htax-search-widget' ),
            'i18n'       => array(
                'loading'    => __( 'Loading...', 'hypertaxonomy' ),
                'no_results' => __( 'No results found', 'hypertaxonomy' ),
                'error'      => __( 'An error occurred', 'hypertaxonomy' ),
                'min_chars'          => __( 'Please enter at least 2 characters', 'hypertaxonomy' ),
                /* translators: {n} is replaced with the minimum character count */
                'min_length_message' => __( 'Please enter at least {n} characters.', 'hypertaxonomy' ),
                /* translators: pagination previous button label */
                'prev'       => __( 'Previous', 'hypertaxonomy' ),
                /* translators: pagination next button label */
                'next'           => __( 'Next', 'hypertaxonomy' ),
                /* translators: 1: current page, 2: total pages */
                'page_of'        => __( 'Page %1$d of %2$d', 'hypertaxonomy' ),
                'currentLocation' => __( 'Current Location', 'hypertaxonomy' ),
                /* translators: %s: post author display name, shown after the date in search result meta */
                'by_author'       => __( 'by %s', 'hypertaxonomy' ),
            ),
        ));
    }
    
    /**
     * Enqueue frontend scripts and styles
     *
     * Only loads globally when 'always_load_search_assets' is enabled in settings.
     * When disabled (default), assets are enqueued on-demand by the shortcode itself.
     */
    public function enqueue_frontend_scripts() {
        $options = get_option( 'htax_settings', array() );
        if ( ! empty( $options['always_load_search_assets'] ) ) {
            $this->enqueue_assets();
        }
    }

    /**
     * Print assets in footer if shortcode was used but assets weren't printed yet.
     *
     * enqueue_frontend_scripts() already enqueues assets via wp_enqueue_scripts.
     * This method only needs to force-print them when they are queued but not yet output
     * (e.g., shortcode rendered in a widget after wp_footer began).
     */
    public function print_deferred_assets() {
        if ( ! empty( $this->should_load_assets ) && ! wp_script_is( 'htax-search-widget', 'done' ) ) {
            // Only call enqueue_assets() if assets haven't been enqueued yet (e.g., no
            // wp_enqueue_scripts hook ran). If already enqueued, skip straight to printing.
            if ( ! wp_script_is( 'htax-search-widget', 'enqueued' ) ) {
                $this->enqueue_assets( false );
            }
            wp_print_scripts( 'htax-search-widget' );
            wp_print_styles( 'htax-search-widget' );
        }
    }

    /**
     * Track field type for validation
     *
     * @param string $type Field type (text, pills, checklist)
     * @param string $name Field name
     */
    private function track_field_type( $type, $name ) {
        if ( empty( self::$current_form_id ) || ! isset( self::$forms[ self::$current_form_id ] ) ) {
            return;
        }

        // Use existing fields array structure
        if ( ! isset( self::$forms[ self::$current_form_id ]['fields'][ $name ] ) ) {
            self::$forms[ self::$current_form_id ]['fields'][ $name ] = array();
        }

        self::$forms[ self::$current_form_id ]['fields'][ $name ] = array(
            'type' => $type,
            'name' => $name,
            'in_group' => ! empty( self::$current_group )
        );
    }

    /**
     * Get validation warnings for a form
     *
     * @param string $form_id Form ID
     * @return array Array of warning messages
     */
    private function get_validation_warnings( $form_id ) {
        $warnings = array();

        if ( empty( self::$forms[ $form_id ]['fields'] ) ) {
            return $warnings;
        }

        $all_fields = self::$forms[ $form_id ]['fields'];

        // Group fields by type for validation
        $text_fields = array();

        foreach ( $all_fields as $field ) {
            if ( 'text' === $field['type'] ) {
                $text_fields[] = $field;
            }
        }

        // Check for multiple text fields
        if ( ! empty( $text_fields ) ) {
            if ( count( $text_fields ) > 1 ) {
                $field_names = array_column( $text_fields, 'name' );
                $warnings[] = 'Multiple text fields detected (' . implode( ', ', $field_names ) . '). Only the first text field will be used. Consider using a single text field or the PowerSearch shortcode for multiple search inputs.';
            }
        }

        return $warnings;
    }

    /**
     * Parse text search query for boolean operators and quoted phrases
     *
     * @param string $query Search query string
     * @return array Array of term data with 'term', 'required', 'excluded' keys
     */
}


// Initialize the search widget system
HyperTaxonomy_Search_Widget::get_instance();
