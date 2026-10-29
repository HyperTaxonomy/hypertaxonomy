<?php
/**
 * Term Edit Screen Functions
 * Handles assets, form fields, and save handlers for term edit screens
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// TERM EDIT SCREEN ASSETS
// ============================================================================

/**
 * Enqueue admin assets on taxonomy screens
 */
function htax_enqueue_admin_assets( $hook ) {
    if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
        return;
    }

    $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';

    // Always enqueue hierarchical checklist assets if group assignment is enabled for this taxonomy
    // This must happen BEFORE the valid taxonomy check so checkboxes work even if other features aren't enabled
    $has_group_assignment = htax_taxonomy_has_feature( $taxonomy, 'group_assignment' );

    if ( $has_group_assignment ) {
        wp_enqueue_style( 'htax-hierarchical-checklist-css', HTAX_PLUGIN_URL . 'admin/css/htax-hierarchical-checklist.css', array(), HTAX_VERSION );
        wp_enqueue_script( 'htax-hierarchical-checklist-js', HTAX_PLUGIN_URL . 'admin/js/htax-hierarchical-checklist.js', array( 'jquery' ), HTAX_VERSION, true );
    }

    // Only enqueue other assets if taxonomy has HyperTaxonomy features enabled
    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return;
    }

    wp_enqueue_style( 'htax-sort-order-css', HTAX_PLUGIN_URL . 'assets/css/htax-sort-order.css', array(), HTAX_VERSION );
    wp_enqueue_style( 'htax-term-edit-css', HTAX_PLUGIN_URL . 'admin/css/htax-term-edit.css', array( 'htax-sort-order-css' ), HTAX_VERSION );
    wp_enqueue_script( 'htax-sort-order-js', HTAX_PLUGIN_URL . 'assets/js/htax-sort-order.js', array( 'jquery' ), HTAX_VERSION, true );
    wp_enqueue_style( 'htax-hierarchical-checklist-css', HTAX_PLUGIN_URL . 'admin/css/htax-hierarchical-checklist.css', array(), HTAX_VERSION );
    wp_enqueue_script( 'htax-hierarchical-checklist-js', HTAX_PLUGIN_URL . 'admin/js/htax-hierarchical-checklist.js', array( 'jquery' ), HTAX_VERSION, true );
    wp_enqueue_script( 'htax-term-edit-js', HTAX_PLUGIN_URL . 'admin/js/htax-term-edit.js', array( 'jquery', 'jquery-ui-autocomplete', 'htax-sort-order-js', 'htax-hierarchical-checklist-js' ), HTAX_VERSION, true );

    $localize_data = array(
        'ajax_url'            => admin_url( 'admin-ajax.php' ),
        'nonce'               => wp_create_nonce( 'htax-autocomplete' ),

        'taxonomy'            => $taxonomy,
        'min_search_length'   => 2,
        'error_message'       => __( 'An error occurred. Please try again.', 'hypertaxonomy' ),
        'group_edit_base_url' => admin_url( 'term.php?taxonomy=group&tag_ID=' ),
    );

    if ( 'term.php' === $hook && isset( $_GET['tag_ID'] ) ) {
        $term_id = intval( $_GET['tag_ID'] );

        // Add visibility data for the make-visible cascade prompt and parent-hidden guard.
        if ( htax_taxonomy_has_feature( $taxonomy, 'visibility' ) && is_taxonomy_hierarchical( $taxonomy ) ) {
            $localize_data['visibilityNonce']       = wp_create_nonce( 'htax-visibility' );
            $localize_data['isHierarchical']        = true;
            $localize_data['isCurrentlyHidden']     = htax_is_term_hidden( $term_id );
            $hidden_set                             = array_flip( htax_get_all_hidden_term_ids() );
            $descendants                            = get_term_children( $term_id, $taxonomy );
            $localize_data['hiddenDescendantCount'] = is_array( $descendants )
                ? count( array_filter( $descendants, function ( $id ) use ( $hidden_set ) {
                    return isset( $hidden_set[ $id ] );
                } ) )
                : 0;

            // Determine whether any ancestor is hidden so the checkbox can be disabled.
            $term_obj     = get_term( $term_id, $taxonomy );
            $parent_hidden = false;
            if ( $term_obj && ! is_wp_error( $term_obj ) && $term_obj->parent ) {
                $ancestor = $term_obj->parent;
                while ( $ancestor ) {
                    if ( isset( $hidden_set[ $ancestor ] ) ) {
                        $parent_hidden = true;
                        break;
                    }
                    $anc_obj  = get_term( $ancestor, $taxonomy );
                    $ancestor = ( $anc_obj && ! is_wp_error( $anc_obj ) ) ? (int) $anc_obj->parent : 0;
                }
            }
            $localize_data['parentIsHidden'] = $parent_hidden;
            $localize_data['parentHiddenMsg'] = __( 'Cannot make visible: a parent term is hidden. Make the parent term visible first.', 'hypertaxonomy' );
        }

        // Add terms from included groups for synonym detection
        $terms_from_groups = htax_get_terms_from_included_groups( $term_id, $taxonomy, true );
        if ( ! empty( $terms_from_groups ) ) {
            $localize_data['terms_from_groups'] = $terms_from_groups;
        }

        // Add group terms mapping for enhanced synonym detection
        if ( taxonomy_exists( 'group' ) ) {
            $included_group_ids = htax_get_direct_rel_group_ids( $term_id, 'includes' );

            if ( ! empty( $included_group_ids ) ) {
                $group_terms = array();

                foreach ( $included_group_ids as $gid ) {
                    $members = htax_get_terms_in_groups( array( $gid ), $taxonomy );
                    $group_terms[ $gid ] = array_values( array_map(
                        function ( $t ) {
                            return array( 'id' => $t->term_id, 'name' => $t->name );
                        },
                        $members
                    ) );
                }

                if ( ! empty( $group_terms ) ) {
                    $localize_data['group_terms'] = $group_terms;
                }

                // Group names for tooltip rendering of dynamically created indirect pills.
                $group_objs = get_terms( array( 'taxonomy' => 'group', 'include' => $included_group_ids, 'hide_empty' => false ) );
                if ( ! is_wp_error( $group_objs ) ) {
                    $group_names = array();
                    foreach ( $group_objs as $g ) {
                        $group_names[ (int) $g->term_id ] = $g->name;
                    }
                    $localize_data['group_names'] = $group_names;
                }
            }
        }
    }

    wp_localize_script( 'htax-term-edit-js', 'HTAX_Ajax', $localize_data );
}

// ============================================================================
// TERM EDIT FORM FIELDS
// ============================================================================

/**
 * Open the collapsible section in the Add New Term form (priority 9).
 *
 * Outputs a toggle link and an initially-hidden wrapper div. Everything
 * rendered at priorities 10–18 lands inside this div and is revealed when
 * the user clicks the toggle. Carries `data-promote-native-groups` so the JS
 * reorder (see htax-tables.js) knows whether to swap Tag Groups' own field
 * with HT's Term Groups selector — see htax_tag_groups_native_field_promoted().
 *
 * @since 1.0.0
 */
function htax_add_form_collapsible_start(): void {
    ?>
    <div class="htax-add-form-collapsible-section" data-promote-native-groups="<?php echo htax_tag_groups_native_field_promoted() ? '1' : '0'; ?>">
        <button type="button" class="button-link htax-add-form-toggle" aria-expanded="false">
            <span class="dashicons dashicons-arrow-down-alt2 htax-add-form-toggle-icon" aria-hidden="true"></span>
            <span class="htax-add-form-toggle-label"><?php esc_html_e( 'More options', 'hypertaxonomy' ); ?></span>
        </button>
        <div class="htax-add-form-collapsible" hidden>
    <?php
}

/**
 * Close the collapsible section in the Add New Term form (priority 19).
 *
 * @since 1.0.0
 */
function htax_add_form_collapsible_end(): void {
    echo '</div></div>'; // .htax-add-form-collapsible + .htax-add-form-collapsible-section
}

/**
 * Output the toggle row for the Edit Term form collapsible (priority 9).
 *
 * Renders a table row with the "More options" button. JavaScript treats this
 * row as the boundary: all <tr> siblings that follow (until the end-marker row
 * output by htax_add_edit_form_collapsible_end) are hidden by default and
 * revealed when the user clicks the toggle. The native Description row is also
 * moved into the collapsible zone by the same JavaScript. Carries
 * `data-promote-native-groups` — see htax_tag_groups_native_field_promoted().
 *
 * @since 1.0.0
 */
function htax_add_edit_form_collapsible_start(): void {
    ?>
    <tr class="htax-edit-form-toggle-row" data-promote-native-groups="<?php echo htax_tag_groups_native_field_promoted() ? '1' : '0'; ?>">
        <th scope="row" colspan="2" class="htax-edit-form-toggle-cell">
            <button type="button" class="button-link htax-edit-form-toggle" aria-expanded="false">
                <span class="dashicons dashicons-arrow-down-alt2 htax-add-form-toggle-icon" aria-hidden="true"></span>
                <span class="htax-add-form-toggle-label"><?php esc_html_e( 'More options', 'hypertaxonomy' ); ?></span>
            </button>
        </th>
    </tr>
    <?php
}

/**
 * Output the end-marker row for the Edit Term form collapsible (priority 19).
 *
 * @since 1.0.0
 */
function htax_add_edit_form_collapsible_end(): void {
    echo '<tr class="htax-edit-form-collapse-end"></tr>';
}

/**
 * Add description display field to term edit screens
 */
function htax_add_description_display_field( $term_or_taxonomy ) {
    $is_add_form = htax_is_add_form( $term_or_taxonomy );
    $context     = htax_extract_term_edit_context( $term_or_taxonomy );
    $taxonomy    = $context['taxonomy'];
    $term_id     = $context['term_id'];

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) || ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        return;
    }

    $show_description = $term_id ? get_term_meta( $term_id, '_htax_show_description', true ) : false;
    $taxonomy_obj  = get_taxonomy( $taxonomy );
    $taxonomy_name = $taxonomy_obj ? mb_strtolower( $taxonomy_obj->labels->singular_name, 'UTF-8' ) : __( 'term', 'hypertaxonomy' );

    if ( $is_add_form ) : ?>
    <div class="form-field htax-description-field">
        <label for="htax_show_description"><?php esc_html_e( 'Show Description', 'hypertaxonomy' ); ?></label>
    <?php else : ?>
    <tr class="form-field htax-description-field">
        <th scope="row" valign="top">
            <label for="htax_show_description"><?php esc_html_e( 'Show Description', 'hypertaxonomy' ); ?></label>
        </th>
        <td>
    <?php endif; ?>
            <label for="htax_show_description">
                <input type="hidden" name="htax_show_description" value="0" />
                <input type="checkbox" id="htax_show_description" name="htax_show_description" value="1" <?php checked( $show_description, '1' ); ?> />
                <?php esc_html_e( 'Display description below archive heading', 'hypertaxonomy' ); ?>
            </label>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: taxonomy singular name */
                    esc_html__( 'Show the term description, above sticky posts for this %s if any, using functionality provided by the theme when available. HTML tags allowed: strong, em, a, code, br, p, ul, ol, li, blockquote, h3, h4, span.', 'hypertaxonomy' ),
                    esc_html( $taxonomy_name )
                );
                ?>
            </p>
    <?php if ( $is_add_form ) : ?>
    </div>
    <?php else : ?>
        </td>
    </tr>
    <?php endif;
}

/**
 * Add hidden checkbox field to term edit screens
 */
function htax_add_hidden_term_field( $term_or_taxonomy ) {
    $is_add_form = htax_is_add_form( $term_or_taxonomy );
    $context     = htax_extract_term_edit_context( $term_or_taxonomy );
    $taxonomy    = $context['taxonomy'];
    $term_id     = $context['term_id'];

    if ( ! htax_taxonomy_has_feature( $taxonomy, 'visibility' ) ) {
        return;
    }

    $is_hidden = $term_id ? get_term_meta( $term_id, '_htax_is_hidden', true ) : false;

    // Determine whether an ancestor is hidden (edit form only; new terms have no parent yet).
    $parent_is_hidden = false;
    if ( ! $is_add_form && $term_id && is_taxonomy_hierarchical( $taxonomy ) ) {
        $hidden_ids  = array_flip( htax_get_all_hidden_term_ids() );
        $_t          = get_term( $term_id, $taxonomy );
        $ancestor    = ( $_t && ! is_wp_error( $_t ) ) ? (int) $_t->parent : 0;
        while ( $ancestor ) {
            if ( isset( $hidden_ids[ $ancestor ] ) ) {
                $parent_is_hidden = true;
                break;
            }
            $anc_obj  = get_term( $ancestor, $taxonomy );
            $ancestor = ( $anc_obj && ! is_wp_error( $anc_obj ) ) ? (int) $anc_obj->parent : 0;
        }
    }

    if ( $is_add_form ) : ?>
    <div class="form-field htax-hidden-field">
        <label for="htax_is_hidden"><?php esc_html_e( 'Visibility', 'hypertaxonomy' ); ?></label>
    <?php else : ?>
    <tr class="form-field htax-hidden-field">
        <th scope="row" valign="top">
            <label for="htax_is_hidden"><?php esc_html_e( 'Visibility', 'hypertaxonomy' ); ?></label>
        </th>
        <td>
    <?php endif; ?>
            <label for="htax_is_hidden"<?php if ( $parent_is_hidden ) echo ' title="' . esc_attr__( 'Cannot make visible: a parent term is hidden. Make the parent term visible first.', 'hypertaxonomy' ) . '"'; ?>>
                <?php /* A disabled checkbox is not submitted; post "1" so the term stays hidden. */ ?>
                <input type="hidden" name="htax_is_hidden" value="<?php echo $parent_is_hidden ? '1' : '0'; ?>" />
                <input type="checkbox" id="htax_is_hidden" name="htax_is_hidden" value="1"
                    <?php checked( $is_hidden, '1' ); ?>
                    <?php if ( $parent_is_hidden ) echo 'disabled="disabled"'; ?>
                />
                <?php esc_html_e( 'Hide this term from public view', 'hypertaxonomy' ); ?>
            </label>
            <?php if ( $parent_is_hidden ) : ?>
            <p class="description htax-notice-warning">
                <?php esc_html_e( 'Cannot make visible: a parent term is hidden. Make the parent term visible first.', 'hypertaxonomy' ); ?>
            </p>
            <?php else : ?>
            <p class="description">
                <?php if ( 'group' === $taxonomy ) : ?>
                    <?php esc_html_e( 'The group archive returns a 404 to unauthorized users and the group is excluded from REST API responses and sitemaps. Subgroups are hidden automatically. Does not affect posts appearing in term archives that include this group — group expansion is unchanged.', 'hypertaxonomy' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'For users who can\'t see hidden terms (Settings → HyperTaxonomy → Display → Hidden Terms), the term archive returns a 404 and the term is treated as if it didn\'t exist: it\'s hidden from post footers, widgets, and public term lists, and can\'t be used to find posts. Posts that carry it still appear through their other terms.', 'hypertaxonomy' ); ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
    <?php if ( $is_add_form ) : ?>
    </div>
    <?php else : ?>
        </td>
    </tr>
    <?php endif;
}

/**
 * Add included terms field to term edit screens
 *
 * @param object $term Term object
 */
/**
 * Determine whether the current term field hook was fired from the Add Term form.
 *
 * WordPress passes the taxonomy slug (a string) to `{taxonomy}_add_form_fields` hooks,
 * and a WP_Term object to `{taxonomy}_edit_form_fields` hooks. Checking for a string
 * therefore distinguishes add from edit without needing to inspect the request.
 *
 * @since 1.0.0
 * @param string|WP_Term $term_or_taxonomy Taxonomy slug on add form; WP_Term on edit form.
 * @return bool True when on the Add Term form, false when editing an existing term.
 */
function htax_is_add_form( $term_or_taxonomy ) {
    return is_string( $term_or_taxonomy );
}

/**
 * Resolve the taxonomy and term a term-form field renderer is working on.
 *
 * Renderers hooked to both `{taxonomy}_add_form_fields` and `{taxonomy}_edit_form_fields`
 * receive a taxonomy slug or a WP_Term respectively; this normalizes the two. On the
 * edit form the taxonomy is taken from the term itself.
 *
 * @since 1.0.0
 * @param string|WP_Term $term_or_taxonomy Taxonomy slug on add form; WP_Term on edit form.
 * @return array{taxonomy: string, term_id: int, term: WP_Term|null} term_id is 0 and term
 *                                                                    null on the add form.
 */
function htax_extract_term_edit_context( $term_or_taxonomy ): array {
    if ( htax_is_add_form( $term_or_taxonomy ) ) {
        return array(
            'taxonomy' => $term_or_taxonomy,
            'term_id'  => 0,
            'term'     => null,
        );
    }

    return array(
        'taxonomy' => $term_or_taxonomy->taxonomy,
        'term_id'  => (int) $term_or_taxonomy->term_id,
        'term'     => $term_or_taxonomy,
    );
}

/**
 * Add redirect field to term edit screens
 *
 * @param object $term Term object
 */
function htax_add_redirect_field( $term_or_taxonomy ) {
    $is_add_form = htax_is_add_form( $term_or_taxonomy );
    $context     = htax_extract_term_edit_context( $term_or_taxonomy );
    $taxonomy    = $context['taxonomy'];
    $term_id     = $context['term_id'];

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return;
    }

    // Check if taxonomy has redirects feature enabled
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        return;
    }

    // Get taxonomy object for proper labels
    $tax_obj = get_taxonomy( $taxonomy );
    $taxonomy_name = $tax_obj ? mb_strtolower( $tax_obj->labels->singular_name, 'UTF-8' ) : $taxonomy;

    // Get redirect data from database with taxonomy info
    $redirect_term = $term_id ? htax_get_redirect_target( $term_id ) : false;
    $redirect_to = $redirect_term ? $redirect_term->term_id : false;
    $redirect_taxonomy = $redirect_term ? $redirect_term->taxonomy : $taxonomy;
    $is_redirect = ! empty( $redirect_to );

    // Get all taxonomies with redirect feature enabled
    $enabled_taxonomies = htax_get_enabled_taxonomies();
    $redirect_taxonomies = array();
    foreach ( $enabled_taxonomies as $tax_slug ) {
        if ( htax_taxonomy_has_feature( $tax_slug, 'redirects' ) ) {
            $tax = get_taxonomy( $tax_slug );
            if ( $tax ) {
                $redirect_taxonomies[ $tax_slug ] = $tax->labels->singular_name;
            }
        }
    }

    if ( $is_add_form ) {
        // ADD FORM VERSION
        ?>
        <div class="form-field htax-redirect-section">
            <label for="htax_is_redirect"><?php esc_html_e( 'Redirect', 'hypertaxonomy' ); ?></label>
        <?php
    } else {
        // EDIT FORM VERSION
        ?>
        <tr class="form-field htax-redirect-section">
            <th scope="row" valign="top">
                <label for="htax_is_redirect"><?php esc_html_e( 'Redirect', 'hypertaxonomy' ); ?></label>
            </th>
            <td>
        <?php
    }

    // SHARED CONTENT FOR BOTH FORMS
    ?>
            <label for="htax_is_redirect">
                <input type="checkbox" id="htax_is_redirect" name="htax_is_redirect" value="1" <?php checked( $is_redirect ); ?> />
                <?php printf( esc_html__( 'Redirect this %s to another term', 'hypertaxonomy' ), esc_html( $taxonomy_name ) ); ?>
            </label>

            <div class="htax-redirect-target" style="margin-top: 10px; <?php echo $is_redirect ? '' : 'display: none;'; ?>">
                <div style="margin-bottom: 8px;">
                    <label for="htax_redirect_taxonomy" style="display: inline-block; margin-right: 8px;">
                        <?php esc_html_e( 'Target taxonomy:', 'hypertaxonomy' ); ?>
                    </label>
                    <select id="htax_redirect_taxonomy" name="htax_redirect_taxonomy" style="min-width: 150px;">
                        <?php foreach ( $redirect_taxonomies as $tax_slug => $tax_label ) : ?>
                            <option value="<?php echo esc_attr( $tax_slug ); ?>" <?php selected( $redirect_taxonomy, $tax_slug ); ?>>
                                <?php echo esc_html( $tax_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="htax-tag-container htax-redirect-container" data-current="<?php echo esc_attr( $term_id ); ?>" data-taxonomy="<?php echo esc_attr( $redirect_taxonomy ); ?>" data-source-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
                    <?php if ( $redirect_to && $is_redirect ) :
                        $redirect_term = get_term( (int) $redirect_to, $redirect_taxonomy );
                        if ( $redirect_term && ! is_wp_error( $redirect_term ) ) : ?>
                            <span class="htax-pill" data-id="<?php echo esc_attr( $redirect_to ); ?>" tabindex="0">
                                <?php echo esc_html( $redirect_term->name ); ?>
                                <button type="button" class="htax-remove" aria-label="<?php esc_attr_e( 'Remove Redirect Target', 'hypertaxonomy' ); ?>" tabindex="-1">
                                    <span class="screen-reader-text"><?php esc_html_e( 'Remove redirect target:', 'hypertaxonomy' ); ?></span>
                                    <span aria-hidden="true">×</span>
                                </button>
                            </span>
                        <?php endif; endif; ?>
                    <button type="button" class="htax-term-add-btn"
                            title="<?php esc_attr_e( 'Set redirect target', 'hypertaxonomy' ); ?>"
                            <?php echo $is_redirect ? 'style="display:none"' : ''; ?>>
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php esc_html_e( 'Set redirect target', 'hypertaxonomy' ); ?></span>
                    </button>
                    <input type="text" class="htax-search" data-target="redirect"
                           placeholder="<?php esc_attr_e( 'Choose redirect target...', 'hypertaxonomy' ); ?>"
                           data-placeholder-choose="<?php esc_attr_e( 'Choose redirect target...', 'hypertaxonomy' ); ?>"
                           data-placeholder-change="<?php esc_attr_e( 'Change redirect target...', 'hypertaxonomy' ); ?>"
                           style="display:none" />
                    <input type="hidden" name="htax_redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
                </div>
            </div>
    <?php
    if ( $is_add_form ) {
        // CLOSE ADD FORM VERSION
        ?>
        </div>
        <?php
    } else {
        // CLOSE EDIT FORM VERSION
        ?>
            </td>
        </tr>
        <?php
    }
}

/**
 * Add archive settings field (pagination and sticky posts)
 */
function htax_add_archive_settings_field( $term_or_taxonomy ) {
    $is_add_form = htax_is_add_form( $term_or_taxonomy );
    $context     = htax_extract_term_edit_context( $term_or_taxonomy );
    $taxonomy    = $context['taxonomy'];
    $term_id     = $context['term_id'];

    if ( ! htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        return;
    }

    // Get current values
    $posts_per_page = $term_id ? get_term_meta( $term_id, '_htax_posts_per_page', true ) : '';
    $max_sticky_posts = $term_id ? get_term_meta( $term_id, '_htax_max_sticky_posts', true ) : '';
    $sort_order = $term_id ? get_term_meta( $term_id, '_htax_sort_order', true ) : '';

    // Get global settings
    $options = get_option( 'htax_settings', array() );
    $global_max_sticky = isset( $options['max_sticky_posts'] ) ? intval( $options['max_sticky_posts'] ) : 10;

    // Get WordPress default posts per page
    $wp_posts_per_page = get_option( 'posts_per_page', 10 );

    // Opening wrapper
    if ( $is_add_form ) {
        // ADD FORM VERSION
        ?>
        <div class="form-field">
            <label><?php esc_html_e( 'Archive Settings', 'hypertaxonomy' ); ?></label>
        <?php
    } else {
        // EDIT FORM VERSION
        ?>
        <tr class="form-field htax-archive-settings-field">
            <th scope="row" valign="top">
                <label><?php esc_html_e( 'Archive Settings', 'hypertaxonomy' ); ?></label>
            </th>
            <td>
        <?php
    }

    // SHARED CONTENT FOR BOTH FORMS
    ?>
    <div style="margin-bottom: 12px;">
        <label>
            <?php esc_html_e( 'Posts per page:', 'hypertaxonomy' ); ?>
            <input type="number"
                   name="htax_posts_per_page"
                   value="<?php echo esc_attr( $posts_per_page ); ?>"
                   min="1"
                   max="100"
                   placeholder="<?php echo esc_attr( $wp_posts_per_page ); ?>"
                   style="width: 80px; margin-left: 8px;" />
        </label>
        <p class="description" style="margin-top: 4px;">
            <?php
            if ( 'post_tag' === $taxonomy ) {
                $term_label = __( 'tag', 'hypertaxonomy' );
            } else {
                $tax_obj    = get_taxonomy( $taxonomy );
                $term_label = $tax_obj ? mb_strtolower( $tax_obj->labels->singular_name, 'UTF-8' ) : __( 'term', 'hypertaxonomy' );
            }
            printf(
                esc_html__( 'Override WordPress Reading Settings for this %s\'s archive. Leave empty to use default (%d posts).', 'hypertaxonomy' ),
                esc_html( $term_label ),
                intval( $wp_posts_per_page )
            );
            ?>
        </p>
    </div>

    <?php if ( ! $term_id || ! htax_is_redirect( $term_id ) ) : ?>
    <div style="margin-bottom: 12px;">
        <label>
            <?php esc_html_e( 'Maximum sticky posts:', 'hypertaxonomy' ); ?>
            <input type="number"
                   name="htax_max_sticky_posts"
                   value="<?php echo esc_attr( $max_sticky_posts ); ?>"
                   min="1"
                   max="100"
                   placeholder="<?php echo esc_attr( $global_max_sticky ); ?>"
                   style="width: 80px; margin-left: 8px;" />
        </label>
        <p class="description" style="margin-top: 4px;">
            <?php
            printf(
                esc_html__( 'Maximum sticky posts for this %s\'s archive. Leave empty to use global setting (%d posts). Automatically adjusted down if posts per page is lower.', 'hypertaxonomy' ),
                esc_html( $term_label ),
                intval( $global_max_sticky )
            );
            ?>
        </p>
    </div>
    <?php endif; ?>

    <div data-htax-sort-root>
        <p style="margin: 0 0 6px 0;"><strong><?php esc_html_e( 'Sort order:', 'hypertaxonomy' ); ?></strong></p>
        <div class="htax-sort-order-container"
             data-htax-sort-container
             data-label-remove="<?php esc_attr_e( 'Remove', 'hypertaxonomy' ); ?>"
             data-label-asc="<?php esc_attr_e( 'Ascending — click to toggle', 'hypertaxonomy' ); ?>"
             data-label-desc="<?php esc_attr_e( 'Descending — click to toggle', 'hypertaxonomy' ); ?>">
            <?php if ( empty( $sort_order ) ) : ?>
            <span class="htax-sort-empty-hint" style="color:#8c8f94;font-style:italic;font-size:13px;margin-right:6px;"><?php esc_html_e( 'site default', 'hypertaxonomy' ); ?></span>
            <?php endif; ?>
            <button type="button" class="htax-sort-order-add" data-htax-sort-add
                    title="<?php esc_attr_e( 'Add sort criterion', 'hypertaxonomy' ); ?>"
                    aria-label="<?php esc_attr_e( 'Add sort criterion', 'hypertaxonomy' ); ?>">
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            </button>
        </div>
        <div class="htax-sort-order-picker" data-htax-sort-picker style="display:none;">
            <div class="htax-sort-picker-pill" data-field="date"><?php esc_html_e( 'Date', 'hypertaxonomy' ); ?></div>
            <div class="htax-sort-picker-pill" data-field="modified"><?php esc_html_e( 'Last modified', 'hypertaxonomy' ); ?></div>
            <div class="htax-sort-picker-pill" data-field="title"><?php esc_html_e( 'Title', 'hypertaxonomy' ); ?></div>
            <div class="htax-sort-picker-pill" data-field="author"><?php esc_html_e( 'Author', 'hypertaxonomy' ); ?></div>
            <div class="htax-sort-picker-pill" data-field="comment_count"><?php esc_html_e( 'Comments', 'hypertaxonomy' ); ?></div>
        </div>
        <input type="hidden" name="htax_sort_order" id="htax-sort-order-value"
               data-htax-sort-value value="<?php echo esc_attr( $sort_order ); ?>">
        <p class="description" style="margin-top: 6px;">
            <?php esc_html_e( 'Override the default post sort order for this term\'s archive. Click + to add criteria; drag pills to reorder.', 'hypertaxonomy' ); ?>
        </p>
    </div>
    <?php

    // Closing wrapper
    if ( $is_add_form ) {
        // CLOSE ADD FORM VERSION
        ?>
        </div>
        <?php
    } else {
        // CLOSE EDIT FORM VERSION
        ?>
            </td>
        </tr>
        <?php
    }
}

// ============================================================================
// SAVE HANDLERS
// ============================================================================

/**
 * Save hidden term status
 *
 * @param int $term_id Term ID
 */
function htax_save_hidden_term( $term_id ) {
    // Note: Validation (nonce, permissions, taxonomy) is done by htax_save_term_data() orchestrator

    // Get term to access taxonomy
    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        return;
    }
    $taxonomy = $term->taxonomy;

    // The visibility checkbox is paired with a hidden "0" input, so an absent field means the
    // form did not render it (feature disabled, programmatic save) — keep the stored value.
    if ( ! isset( $_POST['htax_is_hidden'] ) ) {
        return;
    }

    // Check current value
    $old_value = get_term_meta( $term_id, '_htax_is_hidden', true );
    $new_value = ( isset( $_POST['htax_is_hidden'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['htax_is_hidden'] ) ) ) ? '1' : '';

    // Block un-hiding a term whose ancestor is hidden. A term under a hidden parent
    // must remain hidden — revealing it would create a visible term inside a hidden
    // subtree, breaking the visibility invariant.
    if ( '' === $new_value && '1' === $old_value && is_taxonomy_hierarchical( $taxonomy ) ) {
        $hidden_ids  = array_flip( htax_get_all_hidden_term_ids() );
        $_t          = get_term( $term_id, $taxonomy );
        $ancestor    = ( $_t && ! is_wp_error( $_t ) ) ? (int) $_t->parent : 0;
        while ( $ancestor ) {
            if ( isset( $hidden_ids[ $ancestor ] ) ) {
                htax_add_admin_notice(
                    __( 'Cannot make term visible: a parent term is hidden. Make the parent term visible first.', 'hypertaxonomy' ),
                    'error',
                    true
                );
                return;
            }
            $anc_obj  = get_term( $ancestor, $taxonomy );
            $ancestor = ( $anc_obj && ! is_wp_error( $anc_obj ) ) ? (int) $anc_obj->parent : 0;
        }
    }

    // Only update if value changed
    $changed = ( $old_value !== $new_value );

    if ( '1' === $new_value ) {
        update_term_meta( $term_id, '_htax_is_hidden', '1' );
        htax_log_debug( 'Term marked as hidden', array(
            'term_id' => $term_id,
        ) );
        // Cascade hidden flag to descendants so they are hidden at the term level,
        // not inferred at runtime. Each newly-hidden descendant fires its own
        // htax_term_visibility_changed action for cache and index updates.
        htax_set_descendant_visibility( $term_id, $taxonomy, true );
    } else {
        delete_term_meta( $term_id, '_htax_is_hidden' );
        // Optionally cascade visible to descendants when admin confirmed.
        if ( $changed && isset( $_POST['htax_cascade_visible'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['htax_cascade_visible'] ) ) ) {
            htax_set_descendant_visibility( $term_id, $taxonomy, false );
        }
    }

    // Trigger reindex if visibility changed
    if ( $changed ) {
        do_action( 'htax_term_visibility_changed', $term_id, $taxonomy );
    }

    // Show success notice if value changed
    if ( $changed ) {
        htax_add_admin_notice(
            __( 'Term visibility settings saved successfully.', 'hypertaxonomy' ),
            'success',
            true
        );
    }
}

/**
 * Save archive settings (pagination and sticky posts)
 *
 * @param int $term_id Term ID
 */
function htax_save_archive_settings( $term_id ) {
    // Note: Validation (nonce, permissions, taxonomy) is done by htax_save_term_data() orchestrator

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return;
    }
    $taxonomy = $term->taxonomy;

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return;
    }

    // Each field below is saved only when the form actually submitted it (the description
    // checkbox is paired with a hidden "0" input). Absent fields — Quick Edit, the max-sticky
    // field omitted on redirect terms — keep their stored values.

    // Save show description setting
    if ( isset( $_POST['htax_show_description'] ) ) {
        if ( '1' === sanitize_text_field( wp_unslash( $_POST['htax_show_description'] ) ) ) {
            update_term_meta( $term_id, '_htax_show_description', '1' );
        } else {
            delete_term_meta( $term_id, '_htax_show_description' );
        }
    }

    // Save archive customization settings only when the feature is enabled.
    // When disabled, skip entirely to preserve existing values for when it's re-enabled.
    if ( htax_taxonomy_has_feature( $taxonomy, 'customize_archives' ) ) {
        if ( isset( $_POST['htax_posts_per_page'] ) ) {
            $old_posts_per_page = get_term_meta( $term_id, '_htax_posts_per_page', true );
            $new_posts_per_page = '';

            if ( '' !== $_POST['htax_posts_per_page'] ) {
                $new_posts_per_page = intval( wp_unslash( $_POST['htax_posts_per_page'] ) );
                $new_posts_per_page = max( 1, min( 100, $new_posts_per_page ) );
                update_term_meta( $term_id, '_htax_posts_per_page', $new_posts_per_page );
            } else {
                delete_term_meta( $term_id, '_htax_posts_per_page' );
            }

            // Invalidate contributing terms cache if posts_per_page changed
            // Page boundaries shift when posts_per_page changes, invalidating cached contributing terms
            if ( (string) $old_posts_per_page !== (string) $new_posts_per_page ) {
                htax_invalidate_contrib_cache( $term_id );
            }
        }

        if ( isset( $_POST['htax_max_sticky_posts'] ) ) {
            if ( '' !== $_POST['htax_max_sticky_posts'] ) {
                $max_sticky = intval( wp_unslash( $_POST['htax_max_sticky_posts'] ) );
                $max_sticky = max( 1, min( 100, $max_sticky ) );
                update_term_meta( $term_id, '_htax_max_sticky_posts', $max_sticky );
            } else {
                delete_term_meta( $term_id, '_htax_max_sticky_posts' );
            }
        }

        if ( ! isset( $_POST['htax_sort_order'] ) ) {
            return;
        }

        // Save sort order — stored as "field:dir,field:dir" (htax_parse_orderby format).
        // Parse through htax_parse_orderby() to strip invalid tokens, then re-serialize
        // keeping only the archive-valid fields (excludes relevance, etc.).
        $sort_order_raw = sanitize_text_field( wp_unslash( $_POST['htax_sort_order'] ) );
        $archive_fields = array( 'date', 'modified', 'title', 'author', 'comment_count' );
        if ( $sort_order_raw ) {
            $parsed      = htax_parse_orderby( $sort_order_raw );
            $valid_pairs = array();
            foreach ( $parsed as $field => $dir ) {
                if ( in_array( $field, $archive_fields, true ) ) {
                    $valid_pairs[] = $field . ':' . strtolower( $dir );
                }
            }
            if ( ! empty( $valid_pairs ) ) {
                update_term_meta( $term_id, '_htax_sort_order', implode( ',', $valid_pairs ) );
            } else {
                delete_term_meta( $term_id, '_htax_sort_order' );
            }
        } else {
            delete_term_meta( $term_id, '_htax_sort_order' );
        }
    }
}

// ============================================================================
// TERM GROUPS ASSIGNMENT
// ============================================================================

/**
 * Add Term Groups assignment field to term edit form
 *
 * @since 1.0
 *
 * @param WP_Term|string $term_or_taxonomy Current taxonomy term object or taxonomy slug (for add form).
 */
function htax_add_term_groups_field( $term_or_taxonomy ) {
    $is_add_form = htax_is_add_form( $term_or_taxonomy );
    $context     = htax_extract_term_edit_context( $term_or_taxonomy );
    $taxonomy    = $context['taxonomy'];
    $term_id     = $context['term_id'];

    // Skip for group taxonomy itself
    if ( 'group' === $taxonomy ) {
        return;
    }

    // Check if feature enabled for this taxonomy
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        return;
    }

    // Determine which groups to hide from this user.
    $hidden_group_ids = array();
    if ( ! htax_can_see_hidden_terms( 'group' ) ) {
        $all_hidden     = htax_get_all_hidden_term_ids();
        $hidden_set     = array_flip( $all_hidden );
        $hidden_group_ids = array_values( array_filter( $all_hidden, function ( $id ) use ( $hidden_set ) {
            $t = get_term( $id );
            return $t && ! is_wp_error( $t ) && 'group' === $t->taxonomy;
        } ) );
    }

    // Get all available groups
    $get_terms_args = array(
        'taxonomy'   => 'group',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    );
    if ( ! empty( $hidden_group_ids ) ) {
        $get_terms_args['exclude'] = $hidden_group_ids;
    }
    $all_groups = get_terms( $get_terms_args );

    if ( empty( $all_groups ) || is_wp_error( $all_groups ) ) {
        return; // No groups available
    }

    // Get currently assigned groups
    $assigned_group_ids = array();
    if ( ! $is_add_form ) {
        $assigned_group_ids = htax_get_term_group_ids( $term_id );
        // Strip hidden groups from the visible assignment list.
        if ( ! empty( $hidden_group_ids ) ) {
            $hidden_group_set   = array_flip( $hidden_group_ids );
            $assigned_group_ids = array_values( array_filter(
                $assigned_group_ids,
                function ( $id ) use ( $hidden_group_set ) { return ! isset( $hidden_group_set[ $id ] ); }
            ) );
        }
    }

    // Build name lookup for pill rendering
    $groups_by_id = array();
    foreach ( $all_groups as $g ) {
        $groups_by_id[ $g->term_id ] = $g;
    }

    // Groups Tag Groups owns the membership data for on this taxonomy — a
    // mirror-mode re-run reasserts these regardless of what's edited here, so
    // reflect that in the UI rather than let an edit silently not stick.
    $locked_group_ids = array();
    if ( function_exists( 'htax_tag_groups_taxonomy_is_managed' )
        && htax_tag_groups_taxonomy_is_managed( $taxonomy )
    ) {
        foreach ( $all_groups as $g ) {
            if ( get_term_meta( $g->term_id, '_htax_tag_groups_source_id', true ) ) {
                $locked_group_ids[] = $g->term_id;
            }
        }
    }
    $locked_group_set = array_flip( $locked_group_ids );

    $taxonomy_obj  = get_taxonomy( $taxonomy );
    $taxonomy_name = mb_strtolower( $taxonomy_obj->labels->singular_name, 'UTF-8' );

    if ( $is_add_form ) {
        // ADD FORM VERSION
        ?>
        <div class="form-field htax-term-groups-section">
            <label><?php esc_html_e( 'Term Groups', 'hypertaxonomy' ); ?></label>
        <?php
    } else {
        // EDIT FORM VERSION
        ?>
        <tr class="form-field htax-term-groups-section">
            <th scope="row" valign="top">
                <label><?php esc_html_e( 'Term Groups', 'hypertaxonomy' ); ?></label>
            </th>
            <td>
        <?php
    }

    // SHARED CONTENT FOR BOTH FORMS
    ?>
            <div class="htax-member-pills-container">
                <?php foreach ( $assigned_group_ids as $gid ) :
                    $g = isset( $groups_by_id[ $gid ] ) ? $groups_by_id[ $gid ] : null;
                    if ( ! $g ) : continue; endif;
                    $is_locked = isset( $locked_group_set[ $gid ] );
                ?>
                    <span class="htax-pill htax-member-group-pill<?php echo $is_locked ? ' htax-pill-locked' : ''; ?>" data-id="<?php echo esc_attr( $gid ); ?>" tabindex="0"
                        <?php if ( $is_locked ) : ?>
                            title="<?php esc_attr_e( 'Managed by Tag Groups — edit this group\'s membership there instead.', 'hypertaxonomy' ); ?>"
                        <?php endif; ?>
                    >
                        <?php if ( $is_locked ) : ?>
                            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php echo esc_html( $g->name ); ?>
                        <?php if ( ! $is_locked ) : ?>
                            <button type="button" class="htax-remove" aria-label="<?php echo esc_attr( sprintf( __( 'Remove from group %s', 'hypertaxonomy' ), $g->name ) ); ?>" tabindex="-1">
                                <span class="screen-reader-text"><?php esc_html_e( 'Remove:', 'hypertaxonomy' ); ?></span>
                                <span aria-hidden="true">&times;</span>
                            </button>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
                <button type="button" class="htax-group-add-btn"
                        data-dropdown="htax-member-grp-dd"
                        title="<?php esc_attr_e( 'Add to groups', 'hypertaxonomy' ); ?>">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php esc_html_e( 'Add to groups', 'hypertaxonomy' ); ?></span>
                </button>
            </div>
            <div id="htax-member-grp-dd" class="htax-group-dropdown">
                <?php
                htax_hierarchical_checklist( array(
                    'taxonomy'       => 'group',
                    'selected_terms' => $assigned_group_ids,
                    'name'           => 'htax_groups',
                    'id'             => 'htax-groups-checklist',
                    'show_search'    => true,
                    'show_tabs'      => false,
                    'checked_ontop'  => true,
                    'echo'           => true,
                    'max_height'     => 220,
                    'exclude'        => $hidden_group_ids,
                    'disabled_terms' => $locked_group_ids,
                    'disabled_title' => __( 'Managed by Tag Groups — edit this group\'s membership there instead.', 'hypertaxonomy' ),
                ) );
                ?>
            </div>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: taxonomy singular name */
                    esc_html__( 'Assign this %s to one or more groups. Group members are displayed together via HyperTaxonomy blocks and shortcodes, and group membership and relationship participation drives archive logic and PowerSearch relevance.', 'hypertaxonomy' ),
                    $taxonomy_name
                );
                ?>
            </p>
    <?php
    if ( $is_add_form ) {
        // CLOSE ADD FORM VERSION
        ?>
        </div>
        <?php
    } else {
        // CLOSE EDIT FORM VERSION
        ?>
            </td>
        </tr>
        <?php
    }
}

/**
 * Save Term Groups assignment
 *
 * @since 1.0
 *
 * @param int $term_id Term ID.
 */
function htax_save_term_groups( $term_id ) {
    // Get taxonomy from term
    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        return;
    }

    $taxonomy = $term->taxonomy;

    // Skip for group taxonomy itself
    if ( 'group' === $taxonomy ) {
        return;
    }

    // Check if feature enabled
    if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        return;
    }

    // Only act on explicit form submissions. Programmatic wp_update_term() calls
    // (e.g. during import, bulk actions) do not include htax_groups in $_POST,
    // so we must not overwrite existing assignments with an empty set.
    if ( ! isset( $_POST['htax_groups'] ) ) {
        return;
    }

    // Get submitted groups and filter out the hidden 0 value
    $submitted_groups = array_map( 'intval', wp_unslash( (array) $_POST['htax_groups'] ) );
    $submitted_groups = array_filter( $submitted_groups, function( $group_id ) {
        return $group_id > 0; // Exclude the hidden 0 value
    } );

    // If Tag Groups has written membership data for this taxonomy, a mirror-mode
    // re-run would reassert it — don't let this save silently get overwritten
    // later by discarding it here instead. Keep the Tag-Groups-mapped subset of
    // this term's groups exactly as it currently is; the admin's submission is
    // only honored for groups Tag Groups has no knowledge of.
    if ( function_exists( 'htax_tag_groups_taxonomy_is_managed' )
        && htax_tag_groups_taxonomy_is_managed( $taxonomy )
    ) {
        $existing_split  = htax_split_term_groups_by_provenance( htax_get_term_group_ids( $term_id ) );
        $submitted_split = htax_split_term_groups_by_provenance( $submitted_groups );
        $submitted_groups = array_values( array_unique( array_merge( $existing_split['mapped'], $submitted_split['ht_only'] ) ) );
    }

    // Save groups
    htax_set_term_groups( $term_id, $submitted_groups );
}
// Priorities run before htax_save_term_data (edited_{taxonomy} / create_{taxonomy}, 20 / 5),
// which validates relationships against the group membership saved here.
add_action( 'edited_term', 'htax_save_term_groups', 10, 1 );
add_action( 'create_term', 'htax_save_term_groups', 4, 1 );

function htax_render_merged_pill_container( array $args ): void {
    $term_id       = (int) $args['term_id'];
    $taxonomy      = $args['taxonomy'];
    $field_terms   = $args['field_terms'];
    $field_groups  = $args['field_groups'] ?? '';
    $direct_ids    = $args['direct_term_ids'] ?? array();
    $group_ids     = $args['group_ids'] ?? array();
    $via_group_map       = $args['via_group_map'] ?? array();
    $via_container_map   = $args['via_container_map'] ?? array();
    $custom_type_paths   = $args['custom_type_paths'] ?? array(); // [term_id => [['label', 'dir'], ...]]
    $redirected_term_ids = $args['redirected_term_ids'] ?? array();
    // Callers may override panel explicitly; otherwise derive from field_terms.
    $panel_override      = $args['panel'] ?? null;
    $placeholder        = $args['placeholder'] ?? __( 'Add…', 'hypertaxonomy' );
    $disabled      = ! empty( $args['disabled'] );
    $excluded_set  = array_flip( array_map( 'intval', (array) ( $args['excluded_ids'] ?? array() ) ) );

    $tax_obj  = get_taxonomy( $taxonomy );
    $tax_name = $tax_obj ? mb_strtolower( $tax_obj->labels->singular_name, 'UTF-8' ) : $taxonomy;

    // Batch-load all term objects we'll need.
    $all_term_ids = array_unique( array_merge( $direct_ids, array_keys( $via_group_map ), array_keys( $via_container_map ), array_keys( $custom_type_paths ) ) );
    $term_objects = array();
    if ( ! empty( $all_term_ids ) ) {
        $fetched = get_terms( array( 'taxonomy' => $taxonomy, 'include' => $all_term_ids, 'hide_empty' => false ) );
        if ( ! is_wp_error( $fetched ) ) {
            foreach ( $fetched as $t ) {
                $term_objects[ $t->term_id ] = $t;
            }
        }
    }

    // Batch-load group objects (direct groups rendered as editable pills).
    $group_objects = array();
    if ( ! empty( $group_ids ) ) {
        $fetched_groups = get_terms( array( 'taxonomy' => 'group', 'include' => $group_ids, 'hide_empty' => false ) );
        if ( ! is_wp_error( $fetched_groups ) ) {
            foreach ( $fetched_groups as $g ) {
                $group_objects[ $g->term_id ] = $g;
            }
        }
    }

    // Batch-load group objects needed for tooltip names (via_group_map + via_container_map groups).
    $indirect_group_ids = array_unique( array_merge(
        array_merge( ...( ! empty( $via_group_map ) ? array_values( $via_group_map ) : array( array() ) ) ),
        array_merge( ...( ! empty( $via_container_map ) ? array_values( $via_container_map ) : array( array() ) ) )
    ) );
    $indirect_group_objects = array();
    if ( ! empty( $indirect_group_ids ) ) {
        $fetched_indirect = get_terms( array( 'taxonomy' => 'group', 'include' => $indirect_group_ids, 'hide_empty' => false ) );
        if ( ! is_wp_error( $fetched_indirect ) ) {
            foreach ( $fetched_indirect as $g ) {
                $indirect_group_objects[ $g->term_id ] = $g;
            }
        }
    }

    // Combined name map for inline script injection (direct + indirect groups).
    $all_group_name_map = array();
    foreach ( $group_objects as $gid => $g ) {
        $all_group_name_map[ $gid ] = $g->name;
    }
    foreach ( $indirect_group_objects as $gid => $g ) {
        $all_group_name_map[ $gid ] = $g->name;
    }

    $ajax_action    = 'htax_search_terms'; // Groups are added via checklist dropdown, not autocomplete
    $extra_taxonomies = array_values( array_unique( array_merge( array( $taxonomy ), (array) ( $args['taxonomies'] ?? array() ) ) ) );
    $disabled_attr  = $disabled ? ' disabled' : '';
    $disabled_style = $disabled ? ' style="opacity:0.6;pointer-events:none;"' : '';
    $panel          = $panel_override ?? ( ( 'htax_containers' === $field_terms ) ? 'containers' : 'includes' );

    // Build path map: term_id => { direct, via_groups, via_container_groups, via_custom }
    $path_map = array();
    $empty_entry = array( 'direct' => false, 'via_groups' => array(), 'via_container_groups' => array(), 'via_custom' => array() );
    foreach ( $direct_ids as $tid ) {
        $path_map[ $tid ] = array_merge( $empty_entry, array( 'direct' => true ) );
    }
    foreach ( $via_group_map as $tid => $gids ) {
        if ( ! isset( $path_map[ $tid ] ) ) { $path_map[ $tid ] = $empty_entry; }
        $path_map[ $tid ]['via_groups'] = array_merge( $path_map[ $tid ]['via_groups'], $gids );
    }
    foreach ( $via_container_map as $tid => $gids ) {
        if ( ! isset( $path_map[ $tid ] ) ) { $path_map[ $tid ] = $empty_entry; }
        $path_map[ $tid ]['via_container_groups'] = array_merge( $path_map[ $tid ]['via_container_groups'], $gids );
    }
    foreach ( $custom_type_paths as $tid => $entries ) {
        $tid = (int) $tid;
        if ( ! isset( $path_map[ $tid ] ) ) { $path_map[ $tid ] = $empty_entry; }
        $path_map[ $tid ]['via_custom'] = array_merge( $path_map[ $tid ]['via_custom'], $entries );
    }
    ?>
    <div class="htax-tag-container htax-merged-container"
         data-current="<?php echo esc_attr( $term_id ); ?>"
         data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
         data-taxonomies="<?php echo esc_attr( implode( ',', array_map( 'sanitize_key', $extra_taxonomies ) ) ); ?>"
         data-action="<?php echo esc_attr( $ajax_action ); ?>"
         data-panel="<?php echo esc_attr( $panel ); ?>"
         <?php echo $disabled_style; ?>>

        <?php
        $dropdown_id = 'htax-grp-dd-' . sanitize_key( $field_groups ?: $field_terms );
        if ( ! empty( $group_ids ) || ! empty( $field_groups ) ) : ?>
        <span class="htax-section-label htax-section-groups">
            <?php esc_html_e( 'Groups:', 'hypertaxonomy' ); ?>
        </span>
        <?php foreach ( $group_ids as $gid ) :
            $g = $group_objects[ $gid ] ?? null;
            if ( ! $g ) { continue; }
        ?>
            <span class="htax-pill htax-group-pill" data-id="<?php echo esc_attr( $gid ); ?>" tabindex="0">
                <?php echo esc_html( $g->name ); ?>
                <button type="button" class="htax-remove" aria-label="<?php echo esc_attr( sprintf( __( 'Remove group %s', 'hypertaxonomy' ), $g->name ) ); ?>" tabindex="-1">
                    <span class="screen-reader-text"><?php esc_html_e( 'Remove group:', 'hypertaxonomy' ); ?></span>
                    <span aria-hidden="true">&times;</span>
                </button>
            </span>
        <?php endforeach; ?>
        <?php if ( ! empty( $field_groups ) && ! $disabled ) : ?>
        <button type="button" class="htax-group-add-btn"
                data-dropdown="<?php echo esc_attr( $dropdown_id ); ?>"
                title="<?php esc_attr_e( 'Add group', 'hypertaxonomy' ); ?>">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e( 'Add group', 'hypertaxonomy' ); ?></span>
        </button>
        <?php endif; ?>
        <span class="htax-section-label htax-section-terms">
            <?php echo esc_html( ucfirst( $tax_obj ? $tax_obj->labels->name : $taxonomy ) . ':' ); ?>
        </span>
        <?php else : ?>
        <span class="htax-section-label htax-section-terms" style="display:none"></span>
        <?php endif; ?>

        <?php
        // Split into direct (editable) and indirect-only (read-only), each sorted by name.
        $direct_path_map   = array_filter( $path_map, fn( $p ) => $p['direct'] );
        // Indirect pills for excluded terms are not shown — the term is not actually in the archive.
        $indirect_path_map = array_filter(
            $path_map,
            fn( $p, $tid ) => ! $p['direct'] && ! isset( $excluded_set[ $tid ] ),
            ARRAY_FILTER_USE_BOTH
        );

        $sort_by_name = function ( $tid_a, $tid_b ) use ( $term_objects ) {
            $a = isset( $term_objects[ $tid_a ] ) ? $term_objects[ $tid_a ]->name : '';
            $b = isset( $term_objects[ $tid_b ] ) ? $term_objects[ $tid_b ]->name : '';
            return strcasecmp( $a, $b );
        };
        uksort( $direct_path_map, $sort_by_name );
        uksort( $indirect_path_map, $sort_by_name );

        // Redirect terms have their inclusions suspended; fetch once and dim their pills in both panels.
        $redirect_ids = htax_taxonomy_has_feature( $taxonomy, 'redirects' )
            ? htax_get_all_redirect_term_ids()
            : array();

        // Helper to render a single term pill.
        $render_pill = function ( int $tid, array $paths ) use ( $term_objects, $all_group_name_map, $tax_name, $excluded_set, $redirect_ids, $redirected_term_ids, $panel ) {
            $t = $term_objects[ $tid ] ?? null;
            if ( ! $t ) { return; }

            $is_direct      = $paths['direct'];
            $via_gids       = $paths['via_groups'];
            $via_cgids      = $paths['via_container_groups'];
            $via_custom     = $paths['via_custom'] ?? array();
            $total_paths    = (int) $is_direct + count( $via_gids ) + count( $via_cgids ) + count( $via_custom );
            $is_overridden  = $is_direct && isset( $excluded_set[ $tid ] );
            $is_custom_only = ! $is_direct && empty( $via_gids ) && empty( $via_cgids ) && ! empty( $via_custom );

            $data_attrs  = ' data-paths="' . esc_attr( $total_paths ) . '"';
            if ( $is_direct ) {
                $data_attrs .= ' data-direct="1"';
            }
            if ( $is_overridden ) {
                $data_attrs .= ' data-overridden="1"';
            }
            if ( ! empty( $via_gids ) ) {
                $data_attrs .= ' data-via-groups="' . esc_attr( implode( ',', $via_gids ) ) . '"';
            }
            if ( ! empty( $via_cgids ) ) {
                $data_attrs .= ' data-via-container-groups="' . esc_attr( implode( ',', $via_cgids ) ) . '"';
            }

            // Build structured tooltip data rendered as a custom overlay by JS.
            // Groups get clickable links to their edit page (with term_context param).
            // Custom types get tab-switch links (same page, no navigation).
            $tooltip_data = array();
            // via_gids: membership path — includes panel uses subgroup expansion (descendants),
            // containers panel uses ancestor expansion, so the group navigated to is the one
            // the term is directly in.
            $vgid_dir = ( 'containers' === $panel ) ? 'ancestors' : 'descendants';
            foreach ( $via_gids as $gid ) {
                $tooltip_data[] = array(
                    'type' => 'group',
                    'name' => $all_group_name_map[ $gid ] ?? ( '#' . $gid ),
                    'dir'  => 'fwd',
                    'url'  => esc_url( add_query_arg(
                        array( 'term_context' => $tid, 'context_dir' => $vgid_dir, 'context_rel' => 'member_of' ),
                        get_edit_term_link( $gid, 'group' )
                    ) ),
                );
            }
            // via_cgids: includes-relationship path. The direction is known from the panel:
            //   includes panel  → group includes the pill term  (context_rel=includes_fwd)
            //   containers panel → pill term includes the group (context_rel=includes_rev)
            $vcgid_rel = ( 'containers' === $panel ) ? 'includes_rev' : 'includes_fwd';
            foreach ( $via_cgids as $gid ) {
                $tooltip_data[] = array(
                    'type' => 'group',
                    'name' => $all_group_name_map[ $gid ] ?? ( '#' . $gid ),
                    'dir'  => 'rev',
                    'url'  => esc_url( add_query_arg(
                        array( 'term_context' => $tid, 'context_dir' => 'ancestors', 'context_rel' => $vcgid_rel ),
                        get_edit_term_link( $gid, 'group' )
                    ) ),
                );
            }
            foreach ( $via_custom as $entry ) {
                $tooltip_data[] = array(
                    'type'  => 'custom',
                    'slug'  => $entry['slug'] ?? '',
                    'label' => $entry['label'],
                    'dir'   => $entry['dir'] ?? 'fwd',
                );
            }
            if ( $is_overridden ) {
                $tooltip_data[] = array(
                    'type' => 'text',
                    'text' => __( 'Overridden — this term is also excluded, so this direct include has no effect on the archive.', 'hypertaxonomy' ),
                );
            }

            $is_redirect_term_pill = ! empty( $redirect_ids ) && in_array( $tid, $redirect_ids, true );
            $redirects_to_current  = $is_redirect_term_pill && ! empty( $redirected_term_ids ) && in_array( $tid, $redirected_term_ids, true );
            $dim_as_redirect       = $is_redirect_term_pill && ( 'containers' === $panel );
            ?>
            <span class="htax-pill<?php echo $is_direct ? '' : ' htax-indirect'; ?><?php echo $is_custom_only ? ' htax-via-custom' : ''; ?><?php echo $is_overridden ? ' htax-overridden' : ''; ?><?php echo $dim_as_redirect ? ' is-redirect-term' : ''; ?>"
                  data-id="<?php echo esc_attr( $tid ); ?>"
                  tabindex="0"<?php echo $data_attrs; ?>
                  <?php if ( ! empty( $tooltip_data ) ) : ?>
                  data-tooltip="<?php echo esc_attr( wp_json_encode( $tooltip_data ) ); ?>"
                  <?php endif ?>>
                <?php if ( $total_paths > 1 ) : ?>
                    <span class="htax-badge"><?php echo esc_html( $total_paths ); ?></span>
                <?php endif; ?>
                <?php echo esc_html( $t->name ); ?>
                <?php if ( $redirects_to_current ) : ?>
                    <span class="redirect-indicator" aria-label="<?php esc_attr_e( 'Redirects here', 'hypertaxonomy' ); ?>">&#x21A9;</span>
                <?php elseif ( $is_redirect_term_pill ) : ?>
                    <span class="redirect-indicator" aria-label="<?php echo $dim_as_redirect ? esc_attr__( 'Redirects elsewhere — inclusions not in effect', 'hypertaxonomy' ) : esc_attr__( 'Redirects elsewhere', 'hypertaxonomy' ); ?>">&#x21AA;</span>
                <?php endif; ?>
                <?php if ( $is_direct ) : ?>
                    <button type="button" class="htax-remove"
                            aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'hypertaxonomy' ), $tax_name ) ); ?>"
                            tabindex="-1">
                        <span class="screen-reader-text"><?php esc_html_e( 'Remove term:', 'hypertaxonomy' ); ?></span>
                        <span aria-hidden="true">&times;</span>
                    </button>
                <?php endif; ?>
            </span>
            <?php
        };

        foreach ( $direct_path_map as $tid => $paths ) {
            $render_pill( $tid, $paths );
        }

        // Term + button focuses the search input; sits at the end of the direct terms row.
        if ( ! $disabled ) : ?>
        <button type="button" class="htax-term-add-btn" title="<?php echo esc_attr( sprintf( __( 'Add %s', 'hypertaxonomy' ), $tax_name ) ); ?>">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Add %s', 'hypertaxonomy' ), $tax_name ) ); ?></span>
        </button>
        <?php endif; ?>
        <input type="text" class="htax-search htax-merged-search"
               placeholder="<?php echo esc_attr( $placeholder ); ?>"
               style="display:none"
               <?php echo $disabled_attr; ?> />
        <input type="hidden" name="<?php echo esc_attr( $field_terms ); ?>"
               value="<?php echo esc_attr( implode( ',', $direct_ids ) ); ?>" />
        <?php if ( ! empty( $field_groups ) ) : ?>
        <input type="hidden" name="<?php echo esc_attr( $field_groups ); ?>"
               value="<?php echo esc_attr( implode( ',', $group_ids ) ); ?>" />
        <?php endif; ?>

        <span class="htax-section-label htax-section-indirect"<?php echo empty( $indirect_path_map ) ? ' style="display:none"' : ''; ?>>
            <?php esc_html_e( 'via groups:', 'hypertaxonomy' ); ?>
        </span>

        <?php foreach ( $indirect_path_map as $tid => $paths ) {
            $render_pill( $tid, $paths );
        } ?>
    </div>
    <?php if ( ! empty( $field_groups ) ) : ?>
    <div id="<?php echo esc_attr( $dropdown_id ); ?>" class="htax-group-dropdown">
        <?php htax_hierarchical_checklist( array(
            'taxonomy'       => 'group',
            'selected_terms' => $group_ids,
            'name'           => '_htax_dd_' . $field_groups,
            'id'             => 'htax-grp-cl-' . sanitize_key( $field_groups ),
            'show_search'    => true,
            'show_tabs'      => false,
            'checked_ontop'  => true,
            'echo'           => true,
            'max_height'     => 220,
        ) ); ?>
    </div>
    <?php endif; ?>
    <?php
}

/**
 * Build the "via_group_map" for a merged pill container.
 *
 * Returns term_id => [group_id, ...] for all terms reached indirectly through
 * $group_ids (the groups this term directly has as forward or reverse groups).
 * Members of those groups that are NOT also in $direct_term_ids appear as read-only pills.
 *
 * @param int[]  $group_ids       Group IDs to expand.
 * @param string $taxonomy        Taxonomy to look up members in.
 * @return array<int, int[]>
 */
function htax_build_via_group_map( array $group_ids, string $taxonomy ): array {
    $map = array();
    foreach ( $group_ids as $gid ) {
        $members = htax_get_terms_in_groups( array( $gid ), $taxonomy );
        foreach ( $members as $member ) {
            $mid = (int) $member->term_id;
            if ( ! isset( $map[ $mid ] ) ) {
                $map[ $mid ] = array();
            }
            $map[ $mid ][] = (int) $gid;
        }
    }
    return $map;
}

/**
 * Build the "via_group_map" for the containers panel, expanding container groups upward to
 * ancestors before fetching direct members — mirroring htax_get_container_terms() Path 3.
 *
 * htax_build_via_group_map() expands downward (descendants), which is correct for the
 * includes panel. The containers panel needs upward expansion: if tag 78 has container
 * group C, members of C's parent P are also containers in the frontend (via
 * htax_get_group_ancestors), so they must appear here too.
 *
 * @since 1.0.0
 * @param int[]  $cont_group_ids Container group IDs (htax_get_reverse_rel_group_ids result).
 * @param string $taxonomy       Taxonomy to filter members by.
 * @return array<int, int[]> term_id => [group_id, ...] where group_id is the specific group
 *                           (C or an ancestor of C) that the term is directly a member of.
 */
function htax_build_container_via_group_map( array $cont_group_ids, string $taxonomy ): array {
    if ( empty( $cont_group_ids ) ) {
        return array();
    }

    $expanded = htax_get_group_ancestors( $cont_group_ids );
    if ( empty( $expanded ) ) {
        return array();
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . 'htax_term_relationships';
    $placeholders = htax_sql_placeholders( count( $expanded ) );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT source_term_id, target_term_id
             FROM {$table_name}
             WHERE target_term_id IN ({$placeholders})
               AND relationship_type = 'member_of'
               AND source_taxonomy = %s
               AND target_taxonomy = 'group'",
            ...array_merge( $expanded, array( $taxonomy ) )
        ),
        ARRAY_A
    );

    $map = array();
    foreach ( $rows as $row ) {
        $tid = (int) $row['source_term_id'];
        $gid = (int) $row['target_term_id'];
        if ( ! isset( $map[ $tid ] ) ) {
            $map[ $tid ] = array();
        }
        $map[ $tid ][] = $gid;
    }
    return $map;
}

/**
 * Build a term → [group_ids] attribution map for the container-group inclusion path.
 *
 * Identical query to htax_get_terms_with_container_groups() but returns per-group
 * attribution rather than a flat term ID list, so the UI can show which specific
 * group caused each term's appearance via the container-group path.
 *
 * @since 1.0.0
 * @param int[]  $group_ids Expanded member group IDs of the current term.
 * @param string $taxonomy  Taxonomy to filter by.
 * @return array<int, int[]> term_id => [group_id, ...] map.
 */
function htax_build_container_group_attribution( array $group_ids, string $taxonomy ): array {
    if ( empty( $group_ids ) ) {
        return array();
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . 'htax_term_relationships';
    $placeholders = htax_sql_placeholders( count( $group_ids ) );
    $includes_in  = htax_relationship_type_sql_in( 'includes' );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT source_term_id, target_term_id
             FROM {$table_name}
             WHERE source_term_id IN ({$placeholders})
               AND {$includes_in}
               AND source_taxonomy = 'group'
               AND target_taxonomy = %s",
            ...array_merge( $group_ids, array( $taxonomy ) )
        ),
        ARRAY_A
    );

    $map = array();
    foreach ( $rows as $row ) {
        $tid = (int) $row['target_term_id'];
        $gid = (int) $row['source_term_id'];
        $map[ $tid ][] = $gid;
    }
    return $map;
}

/**
 * Build a term → [group_ids] attribution map for the included-group container path.
 *
 * Mirror of htax_build_container_group_attribution(): finds terms that actively
 * include the given groups (source=term, target=group, type=includes), so the UI
 * can show which group caused each term to appear as a container via that path.
 *
 * Used for the containers panel: if this term is a member of G and another term C
 * has G as an included group, C's archive includes this term — C is a container
 * arrived at through G.
 *
 * @since 1.0.0
 * @param int[]  $group_ids Expanded member group IDs of the current term.
 * @param string $taxonomy  Taxonomy to filter source terms by.
 * @return array<int, int[]> term_id => [group_id, ...] map.
 */
function htax_build_included_group_attribution( array $group_ids, string $taxonomy ): array {
    if ( empty( $group_ids ) ) {
        return array();
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . 'htax_term_relationships';
    $placeholders = htax_sql_placeholders( count( $group_ids ) );
    $includes_in  = htax_relationship_type_sql_in( 'includes' );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT source_term_id, target_term_id
             FROM {$table_name}
             WHERE target_term_id IN ({$placeholders})
               AND {$includes_in}
               AND target_taxonomy = 'group'
               AND source_taxonomy = %s",
            ...array_merge( $group_ids, array( $taxonomy ) )
        ),
        ARRAY_A
    );

    $map = array();
    foreach ( $rows as $row ) {
        $tid = (int) $row['source_term_id'];
        $gid = (int) $row['target_term_id'];
        $map[ $tid ][] = $gid;
    }
    return $map;
}


/**
 * Render the unified inclusions/relationships tabs field on the term EDIT form.
 *
 * Replaces the six separate fields (included terms, container terms, included groups,
 * container groups, excluded terms, custom relationships) with a single tabbed
 * <tr> containing merged pill containers.
 *
 * Only shown on the edit form. Not shown for the 'group' taxonomy.
 *
 * @since 1.0.0
 * @param WP_Term|string $term_or_taxonomy WP_Term on edit, taxonomy slug on add.
 */
function htax_add_inclusions_tabs_field( $term_or_taxonomy ): void {
    $is_add_form = htax_is_add_form( $term_or_taxonomy );
    $context     = htax_extract_term_edit_context( $term_or_taxonomy );
    $taxonomy    = $context['taxonomy'];
    $term_id     = $context['term_id'];

    if ( 'group' === $taxonomy ) {
        return;
    }

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return;
    }

    if ( ! htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
        return;
    }

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        return;
    }

    $tax_obj         = get_taxonomy( $taxonomy );
    $tax_name        = $tax_obj ? mb_strtolower( $tax_obj->labels->singular_name, 'UTF-8' ) : $taxonomy;
    $tax_plural      = $tax_obj ? mb_strtolower( $tax_obj->labels->name, 'UTF-8' ) : $taxonomy;
    $supports_groups = ( 'group' !== $taxonomy ) && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' );
    $has_exclusions  = htax_taxonomy_has_feature( $taxonomy, 'term_relationships' );

    // Redirect state — containers are locked while redirect is active.
    $redirect_term = htax_get_redirect_target( $term_id );
    $is_redirect   = (bool) $redirect_term;

    // ---- Core includes tab data ----
    // Direct IDs: exact 'includes' type, non-group targets/sources only (the editable/removable set).
    // Group-expanded members and reverse-path terms are purely indirect (read-only pills).
    $incl_direct_ids = htax_get_direct_rel_term_ids( $term_id, 'includes' );
    $cont_direct_ids = htax_get_reverse_rel_term_ids( $term_id, 'includes' );
    $incl_group_ids  = $supports_groups ? htax_get_direct_rel_group_ids( $term_id, 'includes' )  : array();
    $cont_group_ids  = $supports_groups ? htax_get_reverse_rel_group_ids( $term_id, 'includes' ) : array();
    $excl_ids        = $has_exclusions  ? htax_get_excluded_term_ids( $term_id, true )   : array();

    // Via-group maps — only terms that are NOT already direct get a read-only pill.
    $incl_via_map = $supports_groups ? htax_build_via_group_map( $incl_group_ids, $taxonomy ) : array();
    // Containers panel uses ancestor expansion to match htax_get_container_terms() Path 3.
    $cont_via_map = $supports_groups ? htax_build_container_via_group_map( $cont_group_ids, $taxonomy ) : array();

    // Reverse-path attribution maps using this term's own group memberships:
    //   Includes panel: terms that have this term's member groups as container groups
    //                   (those terms reverse-include themselves into this term's archive).
    //   Containers panel: terms that include this term's member groups
    //                     (their archives expand to include members of those groups, i.e. this term).
    $member_group_ids  = $supports_groups ? htax_get_term_group_ids( $term_id ) : array();
    $expanded_members  = ! empty( $member_group_ids ) ? htax_get_group_hierarchy( $member_group_ids ) : array();
    $incl_via_cont_map = ! empty( $expanded_members ) ? htax_build_container_group_attribution( $expanded_members, $taxonomy ) : array();
    $cont_via_cont_map = ! empty( $expanded_members ) ? htax_build_included_group_attribution( $expanded_members, $taxonomy ) : array();

    // Exclude the current term itself from all maps (a term never includes itself).
    unset( $incl_via_map[ $term_id ], $incl_via_cont_map[ $term_id ] );
    unset( $cont_via_map[ $term_id ], $cont_via_cont_map[ $term_id ] );

    // Terms that redirect TO this term — used to show ↩ on those pills.
    $redirected_to_here = htax_taxonomy_has_feature( $taxonomy, 'redirects' )
        ? htax_get_ids_of_redirects_to( $term_id, $taxonomy )
        : array();

    // ---- Custom types ----
    $custom_types     = htax_get_relationship_types_for_taxonomy( $taxonomy );
    $includes_slugs   = htax_get_relationship_type_slugs( 'includes' );
    $has_custom_types = ! empty( $custom_types );
    $use_tabs         = $has_custom_types; // Tabs only needed when 2+ top-level sections.

    // Custom type data per slug.
    $cust_data = array();
    foreach ( $custom_types as $cslug => $cdef ) {
        $ctype_supports_groups = in_array( $cslug, $includes_slugs, true ) && $supports_groups;

        $fwd_direct = htax_get_direct_rel_term_ids( $term_id, $cslug );
        $rev_direct = htax_get_reverse_rel_term_ids( $term_id, $cslug );

        $fwd_groups = $ctype_supports_groups ? htax_get_direct_rel_group_ids( $term_id, $cslug ) : array();
        $rev_groups = $ctype_supports_groups ? htax_get_reverse_rel_group_ids( $term_id, $cslug ) : array();

        $fwd_via_map = $ctype_supports_groups ? htax_build_via_group_map( $fwd_groups, $taxonomy ) : array();
        $rev_via_map = $ctype_supports_groups ? htax_build_via_group_map( $rev_groups, $taxonomy )  : array();
        foreach ( $fwd_direct as $id ) { unset( $fwd_via_map[ $id ] ); }
        foreach ( $rev_direct as $id ) { unset( $rev_via_map[ $id ] ); }

        $cust_data[ $cslug ] = array(
            'fwd_direct'            => $fwd_direct,
            'rev_direct'            => $rev_direct,
            'fwd_groups'            => $fwd_groups,
            'rev_groups'            => $rev_groups,
            'fwd_via_map'           => $fwd_via_map,
            'rev_via_map'           => $rev_via_map,
            'ctype_supports_groups' => $ctype_supports_groups,
        );
    }

    // Aggregate custom inclusion-type paths for the includes/containers pill containers.
    // Re-uses already-fetched $cust_data — no extra DB queries.
    // custom_incl_paths: terms appearing in this term's archive via fwd custom inclusion.
    // custom_cont_paths: terms whose archives include this term via rev custom inclusion.
    $custom_incl_paths = array();
    $custom_cont_paths = array();
    foreach ( $custom_types as $cslug => $cdef ) {
        if ( ! in_array( $cslug, $includes_slugs, true ) ) {
            continue;
        }
        $cd = $cust_data[ $cslug ];
        foreach ( array_unique( array_merge( $cd['fwd_direct'], array_keys( $cd['fwd_via_map'] ) ) ) as $tid ) {
            $custom_incl_paths[ (int) $tid ][] = array( 'label' => $cdef['label'], 'dir' => 'fwd', 'slug' => $cslug );
        }
        foreach ( array_unique( array_merge( $cd['rev_direct'], array_keys( $cd['rev_via_map'] ) ) ) as $tid ) {
            $custom_cont_paths[ (int) $tid ][] = array( 'label' => $cdef['inverse_label'], 'dir' => 'rev', 'slug' => $cslug );
        }
    }
    $has_custom_incl_types = ! empty( array_intersect( array_keys( $custom_types ), $includes_slugs ) );
    ?>

    <?php
    // Show the section-level hide-indirect toggle when any indirect pills exist.
    $has_indirect_pills = ! empty( $incl_via_map ) || ! empty( $incl_via_cont_map ) ||
                          ! empty( $cont_via_map ) || ! empty( $cont_via_cont_map );
    if ( ! $has_indirect_pills ) {
        foreach ( $cust_data as $cd ) {
            if ( ! empty( $cd['fwd_via_map'] ) || ! empty( $cd['rev_via_map'] ) ) {
                $has_indirect_pills = true;
                break;
            }
        }
    }
    ?>
    <?php if ( $is_add_form ) : ?>
    <div class="form-field htax-inclusions-field">
        <label><?php esc_html_e( 'Term Relationships', 'hypertaxonomy' ); ?></label>
    <?php else : ?>
    <tr class="form-field htax-inclusions-field">
        <th scope="row" valign="top">
            <label><?php esc_html_e( 'Term Relationships', 'hypertaxonomy' ); ?></label>
            <?php if ( $has_indirect_pills && $term_id ) : ?>
            <button type="button"
                    class="htax-via-header-btn htax-via-section-toggle"
                    data-term="<?php echo esc_attr( $term_id ); ?>"
                    aria-pressed="false"
                    title="<?php esc_attr_e( 'Hide indirect and reverse relationships', 'hypertaxonomy' ); ?>">
                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
            </button>
            <?php endif; ?>
        </th>
        <td>
    <?php endif; ?>

        <?php if ( $use_tabs ) : ?>
        <nav class="htax-rel-tabs-nav" role="tablist">
            <button type="button" class="htax-rel-tab-btn active" role="tab"
                    aria-selected="true" data-tab="includes">
                <?php esc_html_e( 'Containers / Includes', 'hypertaxonomy' ); ?>
            </button>
            <?php foreach ( $custom_types as $cslug => $cdef ) : ?>
            <button type="button" class="htax-rel-tab-btn" role="tab"
                    aria-selected="false" data-tab="<?php echo esc_attr( $cslug ); ?>">
                <?php
                if ( $cdef['label'] === $cdef['inverse_label'] ) {
                    echo esc_html( $cdef['label'] );
                } else {
                    echo esc_html( $cdef['label'] ) . ' / ' . esc_html( $cdef['inverse_label'] );
                }
                ?>
            </button>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <?php /* ===== INCLUDES TAB ===== */ ?>
        <div class="htax-rel-tab-panel<?php echo $use_tabs ? '' : ' htax-no-tabs'; ?>"
             data-panel="includes"
             <?php echo $use_tabs ? 'role="tabpanel"' : ''; ?>>

            <div class="htax-inclusions-panel">
                <h4 class="htax-container-heading">
                    <?php printf( esc_html__( 'Container %s', 'hypertaxonomy' ), esc_html( ucfirst( $tax_plural ) ) ); ?>
                </h4>
                <?php htax_render_merged_pill_container( array(
                    'term_id'             => $term_id,
                    'taxonomy'            => $taxonomy,
                    'field_terms'         => 'htax_containers',
                    'field_groups'        => $supports_groups ? 'htax_cont_groups' : '',
                    'direct_term_ids'     => $cont_direct_ids,
                    'group_ids'           => $cont_group_ids,
                    'via_group_map'       => $cont_via_map,
                    'via_container_map'   => $cont_via_cont_map,
                    'custom_type_paths'   => $custom_cont_paths,
                    'redirected_term_ids' => $redirected_to_here,
                    'placeholder'       => $supports_groups
                        ? sprintf( __( 'Add %s…', 'hypertaxonomy' ), $tax_name )
                        : sprintf( __( 'Add %s…', 'hypertaxonomy' ), $tax_name ),
                    'disabled'       => false,
                ) ); ?>
                <p class="description">
                    <?php printf(
                        esc_html__( 'Archives of each of these %1$s will include posts with this %2$s.', 'hypertaxonomy' ),
                        esc_html( $tax_plural ), esc_html( $tax_name )
                    ); ?>
                </p>
            </div>

            <div class="htax-inclusions-panel" data-htax-hides-on-redirect="1"<?php echo $is_redirect ? ' style="display:none;"' : ''; ?>>
                <h4 class="htax-container-heading">
                    <?php printf( esc_html__( 'Included %s', 'hypertaxonomy' ), esc_html( ucfirst( $tax_plural ) ) ); ?>
                </h4>
                <?php htax_render_merged_pill_container( array(
                    'term_id'              => $term_id,
                    'taxonomy'             => $taxonomy,
                    'field_terms'          => 'htax_includes',
                    'field_groups'         => $supports_groups ? 'htax_incl_groups' : '',
                    'direct_term_ids'      => $incl_direct_ids,
                    'group_ids'            => $incl_group_ids,
                    'via_group_map'        => $incl_via_map,
                    'via_container_map'    => $incl_via_cont_map,
                    'custom_type_paths'    => $custom_incl_paths,
                    'redirected_term_ids'  => $redirected_to_here,
                    'excluded_ids'      => $excl_ids,
                    'placeholder'       => $supports_groups
                        ? sprintf( __( 'Add %s…', 'hypertaxonomy' ), $tax_name )
                        : sprintf( __( 'Add %s…', 'hypertaxonomy' ), $tax_name ),
                    'disabled'          => false,
                ) ); ?>
                <p class="description">
                    <?php printf(
                        esc_html__( 'This %1$s\'s archive will include posts with any of these %2$s.', 'hypertaxonomy' ),
                        esc_html( $tax_name ), esc_html( $tax_plural )
                    ); ?>
                </p>
            </div>

            <?php if ( $has_exclusions ) : ?>
            <div class="htax-inclusions-panel htax-exclude-panel" data-htax-hides-on-redirect="1"<?php echo $is_redirect ? ' style="display:none;"' : ''; ?>>
                <h4 class="htax-container-heading">
                    <?php printf( esc_html__( 'Excluded %s', 'hypertaxonomy' ), esc_html( ucfirst( $tax_plural ) ) ); ?>
                </h4>
                <?php
                // Simple exclusions container — terms only, no groups.
                $excl_term_objects = array();
                if ( ! empty( $excl_ids ) ) {
                    $fetched_excl = get_terms( array( 'taxonomy' => $taxonomy, 'include' => $excl_ids, 'hide_empty' => false ) );
                    if ( ! is_wp_error( $fetched_excl ) ) {
                        foreach ( $fetched_excl as $et ) {
                            $excl_term_objects[ $et->term_id ] = $et;
                        }
                    }
                }
                ?>
                <div class="htax-tag-container"
                     data-current="<?php echo esc_attr( $term_id ); ?>"
                     data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
                     data-demand-search="1">
                    <?php foreach ( $excl_ids as $eid ) :
                        $et = $excl_term_objects[ $eid ] ?? null;
                        if ( ! $et ) { continue; }
                    ?>
                        <span class="htax-pill" data-id="<?php echo esc_attr( $eid ); ?>" tabindex="0">
                            <?php echo esc_html( $et->name ); ?>
                            <button type="button" class="htax-remove"
                                    aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'hypertaxonomy' ), $tax_name ) ); ?>"
                                    tabindex="-1">
                                <span class="screen-reader-text"><?php esc_html_e( 'Remove term:', 'hypertaxonomy' ); ?></span>
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </span>
                    <?php endforeach; ?>
                    <button type="button" class="htax-term-add-btn" title="<?php esc_attr_e( 'Add term', 'hypertaxonomy' ); ?>">
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php esc_html_e( 'Add term', 'hypertaxonomy' ); ?></span>
                    </button>
                    <input type="text" class="htax-search" data-target="excluded"
                           placeholder="<?php echo esc_attr( sprintf( __( 'Add %s…', 'hypertaxonomy' ), $tax_name ) ); ?>"
                           style="display:none" />
                    <input type="hidden" name="htax_excludes"
                           value="<?php echo esc_attr( implode( ',', $excl_ids ) ); ?>" />
                </div>
                <p class="description">
                    <?php printf( esc_html__( 'Remove specific %s from the included set.', 'hypertaxonomy' ), esc_html( $tax_plural ) ); ?>
                    <?php if ( $has_custom_incl_types ) : ?>
                        <?php esc_html_e( 'Also applies to custom inclusion-based relationships.', 'hypertaxonomy' ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

        </div><?php /* end includes tab panel */ ?>

        <?php /* ===== CUSTOM TYPE TAB PANELS ===== */
        foreach ( $custom_types as $cslug => $cdef ) :
            $cd                   = $cust_data[ $cslug ];
            $ctype_sup_groups     = $cd['ctype_supports_groups'];
            $fwd_placeholder      = $ctype_sup_groups
                ? sprintf( __( 'Add %s…', 'hypertaxonomy' ), $tax_name )
                : sprintf( __( 'Add %s…', 'hypertaxonomy' ), $tax_name );
            $rev_placeholder = $fwd_placeholder;

            // For non-includes-mapped types, forward searches target_taxonomies and reverse searches source_taxonomies.
            $fwd_extra_taxes  = ( ! $ctype_sup_groups && ! empty( $cdef['target_taxonomies'] ) ) ? $cdef['target_taxonomies'] : array();
            $rev_extra_taxes  = ( ! $ctype_sup_groups && ! empty( $cdef['source_taxonomies'] ) ) ? $cdef['source_taxonomies'] : array();
            $is_includes_type = in_array( $cslug, $includes_slugs, true );
        ?>
        <div class="htax-rel-tab-panel"
             data-panel="<?php echo esc_attr( $cslug ); ?>"
             <?php echo $use_tabs ? 'role="tabpanel" hidden' : ''; ?>>

            <?php $symmetric = ( ! $is_includes_type && $cdef['label'] === $cdef['inverse_label'] ); ?>

            <?php if ( $symmetric ) : ?>
            <?php
            // Symmetric storage-only type: one merged container shows both directions.
            // Rev pills (← arrow) are rows where another term points to this one.
            // Fwd pills (→ arrow) are rows where this term points to another.
            // Adding via the + button always creates a fwd row.
            $sym_all_ids   = array_values( array_unique( array_merge( $cd['rev_direct'], $cd['fwd_direct'] ) ) );
            $sym_terms     = array();
            if ( $sym_all_ids ) {
                $sym_term_objs = get_terms( array( 'taxonomy' => $taxonomy, 'include' => $sym_all_ids, 'hide_empty' => false ) );
                if ( ! is_wp_error( $sym_term_objs ) ) {
                    foreach ( $sym_term_objs as $st ) {
                        $sym_terms[ $st->term_id ] = $st;
                    }
                }
            }
            $sym_taxes = implode( ',', array_filter( array_unique( array_merge( array( $taxonomy ), $fwd_extra_taxes ) ) ) );
            ?>
            <div class="htax-inclusions-panel">
                <h4 class="htax-container-heading"><?php echo esc_html( $cdef['label'] ); ?></h4>
                <div class="htax-tag-container htax-merged-container htax-sym-container"
                     data-current="<?php echo esc_attr( $term_id ); ?>"
                     data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
                     data-taxonomies="<?php echo esc_attr( $sym_taxes ); ?>"
                     data-action="htax_search_terms"
                     data-panel="sym">
                    <?php foreach ( $cd['rev_direct'] as $rtid ) :
                        $rt = $sym_terms[ $rtid ] ?? null;
                        if ( ! $rt ) { continue; }
                    ?>
                    <span class="htax-pill" data-id="<?php echo esc_attr( $rtid ); ?>" data-direct="1" data-dir="rev">
                        <?php echo esc_html( $rt->name ); ?><span class="htax-sym-arrow" aria-hidden="true">&larr;</span>
                        <button type="button" class="htax-remove" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'hypertaxonomy' ), $rt->name ) ); ?>" tabindex="-1">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                    <?php endforeach; ?>
                    <?php foreach ( $cd['fwd_direct'] as $ftid ) :
                        $ft = $sym_terms[ $ftid ] ?? null;
                        if ( ! $ft ) { continue; }
                    ?>
                    <span class="htax-pill" data-id="<?php echo esc_attr( $ftid ); ?>" data-direct="1" data-dir="fwd">
                        <?php echo esc_html( $ft->name ); ?><span class="htax-sym-arrow" aria-hidden="true">&rarr;</span>
                        <button type="button" class="htax-remove" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'hypertaxonomy' ), $ft->name ) ); ?>" tabindex="-1">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                    <?php endforeach; ?>
                    <button type="button" class="htax-term-add-btn" title="<?php esc_attr_e( 'Add term', 'hypertaxonomy' ); ?>">
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php esc_html_e( 'Add term', 'hypertaxonomy' ); ?></span>
                    </button>
                    <input type="text" class="htax-search htax-merged-search"
                           placeholder="<?php echo esc_attr( sprintf( __( 'Add %s…', 'hypertaxonomy' ), $tax_name ) ); ?>"
                           style="display:none" />
                    <input type="hidden" name="htax_cust_<?php echo esc_attr( $cslug ); ?>_fwd_terms"
                           value="<?php echo esc_attr( implode( ',', $cd['fwd_direct'] ) ); ?>" />
                    <input type="hidden" name="htax_cust_<?php echo esc_attr( $cslug ); ?>_rev_terms"
                           value="<?php echo esc_attr( implode( ',', $cd['rev_direct'] ) ); ?>" />
                </div>
                <p class="description"><?php esc_html_e( 'Arrows show which side added the link. You can remove links from either side here.', 'hypertaxonomy' ); ?></p>
            </div>

            <?php else : // Non-symmetric (or inclusion-based): two separate direction panels. ?>

            <div class="htax-inclusions-panel">
                <h4 class="htax-container-heading"><?php echo esc_html( $cdef['inverse_label'] ); ?></h4>
                <?php htax_render_merged_pill_container( array(
                    'term_id'             => $term_id,
                    'taxonomy'            => $taxonomy,
                    'taxonomies'          => $rev_extra_taxes,
                    'field_terms'         => 'htax_cust_' . $cslug . '_rev_terms',
                    'field_groups'        => $ctype_sup_groups ? 'htax_cust_' . $cslug . '_rev_groups' : '',
                    'direct_term_ids'     => $cd['rev_direct'],
                    'group_ids'           => $cd['rev_groups'],
                    'via_group_map'       => $cd['rev_via_map'],
                    'placeholder'         => $rev_placeholder,
                    'disabled'            => false,
                    'panel'               => $is_includes_type ? 'containers' : 'custom',
                    'redirected_term_ids' => $redirected_to_here,
                ) ); ?>
            </div>

            <div class="htax-inclusions-panel"<?php echo $is_includes_type ? ' data-includes-fwd="1"' : ''; ?><?php echo ( $is_redirect && $is_includes_type ) ? ' style="display:none;"' : ''; ?>>
                <h4 class="htax-container-heading"><?php echo esc_html( $cdef['label'] ); ?></h4>
                <?php htax_render_merged_pill_container( array(
                    'term_id'             => $term_id,
                    'taxonomy'            => $taxonomy,
                    'taxonomies'          => $fwd_extra_taxes,
                    'field_terms'         => 'htax_cust_' . $cslug . '_fwd_terms',
                    'field_groups'        => $ctype_sup_groups ? 'htax_cust_' . $cslug . '_fwd_groups' : '',
                    'direct_term_ids'     => $cd['fwd_direct'],
                    'group_ids'           => $cd['fwd_groups'],
                    'via_group_map'       => $cd['fwd_via_map'],
                    'excluded_ids'        => $is_includes_type ? $excl_ids : array(),
                    'placeholder'         => $fwd_placeholder,
                    'disabled'            => false,
                    'panel'               => $is_includes_type ? 'includes' : 'custom',
                    'redirected_term_ids' => $redirected_to_here,
                ) ); ?>
            </div>

            <?php endif; ?>

        </div><?php /* end custom type tab panel */
        endforeach; ?>

    <?php if ( $is_add_form ) : ?>
    </div>
    <?php else : ?>
        </td>
    </tr>
    <?php endif; ?>
    <?php
}

/**
 * Admin-post handler: add a custom relationship row from the term edit screen.
 *
 * @since 1.0.0
 */
function htax_handle_add_custom_relationship_from_form(): void {
    $source_id = isset( $_POST['source_term_id'] ) ? (int) $_POST['source_term_id'] : 0;

    if ( ! $source_id ) {
        wp_die( esc_html__( 'Invalid request.', 'hypertaxonomy' ) );
    }

    check_admin_referer( 'htax_add_custom_relationship_' . $source_id );

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'hypertaxonomy' ) );
    }

    $target_id  = isset( $_POST['target_term_id'] ) ? (int) wp_unslash( $_POST['target_term_id'] ) : 0;
    $type_slug  = isset( $_POST['type_slug'] ) ? sanitize_key( wp_unslash( $_POST['type_slug'] ) ) : '';
    $return_url = isset( $_POST['redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) ) : '';

    if ( ! $target_id || ! $type_slug ) {
        wp_safe_redirect( add_query_arg( 'htax_msg', 'missing_fields', $return_url ) );
        exit;
    }

    $result = htax_add_term_relationship( $source_id, $target_id, $type_slug );

    if ( is_wp_error( $result ) ) {
        wp_safe_redirect( add_query_arg( 'htax_msg', rawurlencode( $result->get_error_message() ), $return_url ) );
        exit;
    }

    wp_safe_redirect( add_query_arg( 'htax_msg', 'added', $return_url ) );
    exit;
}
add_action( 'admin_post_htax_add_custom_relationship', 'htax_handle_add_custom_relationship_from_form' );

// ============================================================================
// EXTERNAL LINKS FIELD
