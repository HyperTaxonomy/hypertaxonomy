<?php
/**
 * Merge Terms Admin Page
 *
 * Provides an admin form to permanently merge one or more source terms into a
 * target term within the same taxonomy. Submits via POST (no AJAX) and
 * displays a result summary after processing.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the Merge Terms submenu page.
 */
function htax_add_merge_terms_page() {
    add_submenu_page(
        'htax-terms',
        __( 'Merge Terms', 'hypertaxonomy' ),
        __( 'Merge Terms', 'hypertaxonomy' ),
        'manage_options',
        'htax-merge-terms',
        'htax_render_merge_terms_page'
    );
}
add_action( 'admin_menu', 'htax_add_merge_terms_page', 12 );

/**
 * Enqueue autocomplete assets on the Merge Terms page.
 *
 * @param string $hook Current admin page hook.
 */
function htax_enqueue_merge_terms_assets( $hook ) {
    if ( false === strpos( $hook, 'htax-merge-terms' ) ) {
        return;
    }

    wp_enqueue_style( 'htax-term-edit-css', HTAX_PLUGIN_URL . 'admin/css/htax-term-edit.css', array(), HTAX_VERSION );
    wp_enqueue_script( 'htax-term-edit-js', HTAX_PLUGIN_URL . 'admin/js/htax-term-edit.js', array( 'jquery', 'jquery-ui-autocomplete' ), HTAX_VERSION, true );
    wp_localize_script( 'htax-term-edit-js', 'HTAX_Ajax', array(
        'ajax_url'          => admin_url( 'admin-ajax.php' ),
        'nonce'             => wp_create_nonce( 'htax-autocomplete' ),
        'min_search_length' => 2,
        'error_message'     => __( 'An error occurred. Please try again.', 'hypertaxonomy' ),
    ) );
}
add_action( 'admin_enqueue_scripts', 'htax_enqueue_merge_terms_assets' );

/**
 * Render the Merge Terms page.
 */
function htax_render_merge_terms_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'hypertaxonomy' ) );
    }

    $result  = null;
    $errors  = array();

    // Process form submission.
    if ( isset( $_POST['htax_do_merge'] ) && check_admin_referer( 'htax_merge_terms' ) ) {
        $taxonomy        = isset( $_POST['htax_merge_taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['htax_merge_taxonomy'] ) ) : '';
        $source_ids_raw  = isset( $_POST['htax_source_term_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_source_term_ids'] ) ) : '';
        $target_id       = isset( $_POST['htax_target_term_id'] ) ? intval( wp_unslash( $_POST['htax_target_term_id'] ) ) : 0;
        $create_redirect = ! empty( $_POST['htax_create_redirect'] );

        // Parse comma-separated source IDs.
        $source_ids = array_filter( array_map( 'intval', explode( ',', $source_ids_raw ) ) );

        if ( empty( $taxonomy ) ) {
            $errors[] = __( 'Please select a taxonomy.', 'hypertaxonomy' );
        } elseif ( empty( $source_ids ) ) {
            $errors[] = __( 'Please specify at least one source term.', 'hypertaxonomy' );
        } elseif ( ! $target_id ) {
            $errors[] = __( 'Please specify a target term.', 'hypertaxonomy' );
        } else {
            $result = htax_merge_terms( $source_ids, $target_id, $taxonomy, $create_redirect );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
                $result   = null;
            }
        }
    }

    // Build taxonomy options (all HT-enabled taxonomies except 'group').
    $enabled = array_diff( htax_get_enabled_taxonomies(), array( 'group' ) );
    $taxonomy_options = array();
    foreach ( $enabled as $slug ) {
        $tax_obj = get_taxonomy( $slug );
        if ( $tax_obj ) {
            $taxonomy_options[ $slug ] = $tax_obj->labels->name;
        }
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Merge Terms', 'hypertaxonomy' ); ?></h1>

        <p><?php esc_html_e( 'Permanently move all posts from one or more source terms to a target term, transfer group memberships and relationships, then delete the source terms.', 'hypertaxonomy' ); ?></p>
        <p style="color:#d63638;"><strong><?php esc_html_e( 'Warning: this operation cannot be undone. Back up your database before proceeding.', 'hypertaxonomy' ); ?></strong></p>

        <?php if ( class_exists( 'SimpleTags_Plugin' ) ) : ?>
            <div class="notice notice-warning inline" style="margin: 0 0 15px 0;">
                <p>
                    <?php esc_html_e( 'TaxoPress is active and has its own term-merge tool. If you came from there: it does not transfer HyperTaxonomy relationship data (group memberships, inclusions) for the deleted term — use this tool instead to merge terms that have HyperTaxonomy relationships.', 'hypertaxonomy' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php foreach ( $errors as $error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endforeach; ?>

        <?php if ( $result ) : ?>
            <div class="notice notice-success">
                <p><strong><?php esc_html_e( 'Merge complete.', 'hypertaxonomy' ); ?></strong></p>
            </div>
            <table class="widefat" style="max-width:400px;margin-bottom:24px;">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Posts reassigned:', 'hypertaxonomy' ); ?></strong></td>
                        <td><?php echo (int) $result['posts_reassigned']; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Source terms deleted:', 'hypertaxonomy' ); ?></strong></td>
                        <td><?php echo (int) $result['terms_deleted']; ?></td>
                    </tr>
                    <?php if ( $result['redirects_created'] > 0 ) : ?>
                    <tr>
                        <td><strong><?php esc_html_e( 'Redirect stubs created:', 'hypertaxonomy' ); ?></strong></td>
                        <td><?php echo (int) $result['redirects_created']; ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" id="htax-merge-terms-form">
            <?php wp_nonce_field( 'htax_merge_terms' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="htax_merge_taxonomy"><?php esc_html_e( 'Taxonomy', 'hypertaxonomy' ); ?></label>
                    </th>
                    <td>
                        <select name="htax_merge_taxonomy" id="htax_merge_taxonomy">
                            <option value=""><?php esc_html_e( '— Select taxonomy —', 'hypertaxonomy' ); ?></option>
                            <?php foreach ( $taxonomy_options as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"
                                    <?php selected( isset( $_POST['htax_merge_taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['htax_merge_taxonomy'] ) ) : ( isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '' ), $slug ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="htax_source_term_names"><?php esc_html_e( 'Source terms', 'hypertaxonomy' ); ?></label>
                    </th>
                    <td>
                        <!-- Visible autocomplete input; IDs are stored in the hidden field. -->
                        <input type="text"
                            id="htax_source_term_names"
                            class="regular-text htax-term-autocomplete-multi"
                            placeholder="<?php esc_attr_e( 'Type a term name…', 'hypertaxonomy' ); ?>"
                            data-taxonomy-field="htax_merge_taxonomy"
                            data-id-field="htax_source_term_ids"
                            autocomplete="off" />
                        <input type="hidden" name="htax_source_term_ids" id="htax_source_term_ids" />
                        <p class="description"><?php esc_html_e( 'Add one or more terms to merge (type and select). Their posts and relationships will be moved to the target term.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="htax_target_term_name"><?php esc_html_e( 'Target term', 'hypertaxonomy' ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                            id="htax_target_term_name"
                            class="regular-text htax-term-autocomplete"
                            placeholder="<?php esc_attr_e( 'Type a term name…', 'hypertaxonomy' ); ?>"
                            data-taxonomy-field="htax_merge_taxonomy"
                            data-id-field="htax_target_term_id"
                            autocomplete="off" />
                        <input type="hidden" name="htax_target_term_id" id="htax_target_term_id" />
                        <p class="description"><?php esc_html_e( 'The term that source posts and relationships will be merged into.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Redirect stubs', 'hypertaxonomy' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="htax_create_redirect" value="1"
                                <?php checked( ! isset( $_POST['htax_do_merge'] ) || ! empty( $_POST['htax_create_redirect'] ) ); ?> />
                            <?php esc_html_e( 'Create 301 redirect stubs for deleted terms', 'hypertaxonomy' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Keeps old archive URLs working by creating redirect stubs pointing to the target term\'s archive.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="htax_do_merge" class="button button-primary">
                    <?php esc_html_e( 'Merge Terms', 'hypertaxonomy' ); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}
