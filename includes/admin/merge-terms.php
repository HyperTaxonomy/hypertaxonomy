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

    wp_enqueue_style( 'htax-term-picker', HTAX_PLUGIN_URL . 'assets/css/htax-term-picker.css', array(), HTAX_VERSION );
    wp_enqueue_style( 'htax-term-edit-css', HTAX_PLUGIN_URL . 'admin/css/htax-term-edit.css', array( 'htax-term-picker' ), HTAX_VERSION );
    wp_enqueue_script( 'htax-term-edit-js', HTAX_PLUGIN_URL . 'admin/js/htax-term-edit.js', array( 'jquery', 'jquery-ui-autocomplete', 'wp-i18n' ), HTAX_VERSION, true );
    wp_set_script_translations( 'htax-term-edit-js', 'hypertaxonomy', HTAX_PLUGIN_DIR . 'languages' );
    wp_localize_script( 'htax-term-edit-js', 'HTAX_Ajax', array(
        'ajax_url'          => admin_url( 'admin-ajax.php' ),
        'nonce'             => wp_create_nonce( 'htax-autocomplete' ),
        'min_search_length' => 2,
        'error_message'     => __( 'An error occurred. Please try again.', 'hypertaxonomy' ),
    ) );

    htax_enqueue_operation_check( 'htax_merge_terms_check', 'htax_do_merge', 'htax-term-edit-js' );
}
add_action( 'admin_enqueue_scripts', 'htax_enqueue_merge_terms_assets' );

/**
 * AJAX: check a merge before it runs, so other plugins can say what it will do to their
 * data and offer choices (step 2 of the Merge Terms page). With nothing to show, the page
 * merges straight away.
 *
 * Returns { sections: [...] }, as provided by the htax_merge_terms_check filter. An invalid
 * selection returns no sections: the merge itself then reports the problem.
 *
 * @since 1.0.0
 */
function htax_ajax_merge_terms_check(): void {
    check_ajax_referer( 'htax_merge_terms_check', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( null, 403 );
    }

    // The page's form fields, as the merge itself reads them.
    $taxonomy        = isset( $_POST['htax_merge_taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['htax_merge_taxonomy'] ) ) : '';
    $source_ids      = isset( $_POST['htax_source_term_ids'] ) ? array_values( array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['htax_source_term_ids'] ) ) ) ) ) ) : array();
    $target_id       = isset( $_POST['htax_target_term_id'] ) ? absint( $_POST['htax_target_term_id'] ) : 0;
    $create_redirect = ! empty( $_POST['htax_create_redirect'] );

    $valid = $taxonomy && $source_ids && $target_id && htax_is_taxonomy_enabled( $taxonomy )
        && ! in_array( $target_id, $source_ids, true ) && get_term( $target_id, $taxonomy ) instanceof WP_Term;
    foreach ( $source_ids as $source_id ) {
        $valid = $valid && get_term( $source_id, $taxonomy ) instanceof WP_Term;
    }
    if ( ! $valid ) {
        htax_send_operation_check( array() );
    }

    /**
     * Filters what the Merge Terms page shows before a merge: notices about what the merge
     * will do to a plugin's data, and choices that are passed to htax_merge_terms() as
     * $options[ section id ][ field name ], and on to the merge actions. When no section is
     * returned, the merge runs without this step.
     *
     * Each section: {
     *     id      string Section ID (a slug), the key of its choices in $options.
     *     title   string Heading.
     *     notices array  Of { type: 'info'|'warning', text: string }.
     *     fields  array  Of {
     *         name            string Field name (a slug).
     *         label           string Field label.
     *         choices         array  Of { value: slug, label: string, description: string,
     *                                stub: 'required'|'none'|'' }. 'required': available only
     *                                with redirect stubs; 'none': only without.
     *         default         string Choice selected with redirect stubs.
     *         default_no_stub string Choice selected without.
     *     }
     * }
     *
     * Text is shown as plain text. Both defaults are given because the stub setting can
     * still change on this step. The merge must check the choices again, and apply its own
     * defaults when there are none (a merge started outside this page has only what its
     * caller passed, such as the operation-wide preference in htax_carry_choice()).
     *
     * @since 1.0.0
     *
     * @param array  $sections        Sections so far.
     * @param int[]  $source_ids      Source term IDs.
     * @param int    $target_id       Target term ID.
     * @param string $taxonomy        Taxonomy slug.
     * @param bool   $create_redirect Whether redirect stubs are selected.
     */
    htax_send_operation_check( apply_filters( 'htax_merge_terms_check', array(), $source_ids, $target_id, $taxonomy, $create_redirect ) );
}
add_action( 'wp_ajax_htax_merge_terms_check', 'htax_ajax_merge_terms_check' );

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
        $merge_options   = htax_shape_operation_options( isset( $_POST['htax_operation_options'] ) ? map_deep( wp_unslash( $_POST['htax_operation_options'] ), 'sanitize_key' ) : array() );

        // Parse comma-separated source IDs.
        $source_ids = array_filter( array_map( 'intval', explode( ',', $source_ids_raw ) ) );

        if ( empty( $taxonomy ) ) {
            $errors[] = __( 'Please select a taxonomy.', 'hypertaxonomy' );
        } elseif ( empty( $source_ids ) ) {
            $errors[] = __( 'Please specify at least one source term.', 'hypertaxonomy' );
        } elseif ( ! $target_id ) {
            $errors[] = __( 'Please specify a target term.', 'hypertaxonomy' );
        } else {
            $result = htax_merge_terms( $source_ids, $target_id, $taxonomy, $create_redirect, $merge_options );
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

    // Pre-fill from a link (e.g. a row on the Synonyms page): ?taxonomy=&source=&target=.
    // Only on first load, and only for real terms in that taxonomy; the merge still needs
    // the form to be submitted.
    $prefill = array( 'source' => null, 'target' => null );
    if ( empty( $_POST ) && isset( $_GET['taxonomy'] ) ) {
        $prefill_taxonomy = sanitize_key( wp_unslash( $_GET['taxonomy'] ) );
        if ( isset( $taxonomy_options[ $prefill_taxonomy ] ) ) {
            foreach ( array_keys( $prefill ) as $side ) {
                $prefill_term = empty( $_GET[ $side ] ) ? null : get_term( absint( $_GET[ $side ] ), $prefill_taxonomy );
                $prefill[ $side ] = $prefill_term instanceof WP_Term ? $prefill_term : null;
            }
            if ( $prefill['source'] && $prefill['target'] && $prefill['source']->term_id === $prefill['target']->term_id ) {
                $prefill['target'] = null;
            }
        }
    }
    $prefill_source = $prefill['source'];
    $prefill_target = $prefill['target'];

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
                <?php
                /**
                 * Filters messages shown after a merge on the Merge Terms page, so plugins
                 * can report what they did with their data (plain text, one per line).
                 *
                 * @since 1.0.0
                 * @param string[] $messages Messages.
                 * @param array    $result   htax_merge_terms() result.
                 */
                htax_render_operation_messages( apply_filters( 'htax_merge_terms_messages', array(), $result ) );
                ?>
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

            <div id="htax-check-step1">
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
                            placeholder="<?php echo esc_attr( $prefill_source ? $prefill_source->name : __( 'Type a term name…', 'hypertaxonomy' ) ); ?>"
                            data-taxonomy-field="htax_merge_taxonomy"
                            data-id-field="htax_source_term_ids"
                            <?php if ( $prefill_source ) : ?>data-htax-names="<?php echo esc_attr( wp_json_encode( array( $prefill_source->name ) ) ); ?>"<?php endif; ?>
                            autocomplete="off" />
                        <input type="hidden" name="htax_source_term_ids" id="htax_source_term_ids" value="<?php echo $prefill_source ? (int) $prefill_source->term_id : ''; ?>" />
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
                            value="<?php echo esc_attr( $prefill_target ? $prefill_target->name : '' ); ?>"
                            autocomplete="off" />
                        <input type="hidden" name="htax_target_term_id" id="htax_target_term_id" value="<?php echo $prefill_target ? (int) $prefill_target->term_id : ''; ?>" />
                        <p class="description"><?php esc_html_e( 'The term that source posts and relationships will be merged into.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Redirect stubs', 'hypertaxonomy' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="htax_create_redirect" id="htax_create_redirect" value="1"
                                <?php checked( ! isset( $_POST['htax_do_merge'] ) || ! empty( $_POST['htax_create_redirect'] ) ); ?> />
                            <?php esc_html_e( 'Create 301 redirect stubs for deleted terms', 'hypertaxonomy' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Keeps old archive URLs working by creating redirect stubs pointing to the target term\'s archive.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
            </table>
            </div>

            <p class="submit" id="htax-check-step1-submit">
                <button type="submit" name="htax_do_merge" class="button button-primary">
                    <?php esc_html_e( 'Merge Terms', 'hypertaxonomy' ); ?>
                </button>
                <span class="htax-check-checking" aria-live="polite"></span>
            </p>

            <?php htax_render_operation_check_step( __( 'Create 301 redirect stubs for deleted terms', 'hypertaxonomy' ), __( 'Merge Terms', 'hypertaxonomy' ) ); ?>
        </form>
    </div>
    <?php
}
