<?php
/**
 * Relationship Types Admin Page
 *
 * Admin UI for viewing built-in relationship types and managing user-defined types.
 * Registered as the "Term Relationship Types" submenu under the Terms top-level menu.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// AJAX HANDLERS
// ============================================================================

/**
 * Count existing relationship rows that would become ineligible if taxonomy
 * constraints on a type definition are narrowed.
 *
 * Expects POST: htax_rt_slug, htax_rt_source_taxonomies[], htax_rt_target_taxonomies[], nonce.
 * Returns JSON: { count: N }
 *
 * @since 1.0.0
 */
function htax_ajax_count_ineligible_custom_relationships(): void {
    check_ajax_referer( 'htax_relationship_type_save', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    global $wpdb;

    $slug = isset( $_POST['htax_rt_slug'] ) ? sanitize_key( wp_unslash( $_POST['htax_rt_slug'] ) ) : '';
    if ( ! $slug ) {
        wp_send_json_success( array( 'count' => 0 ) );
    }

    $new_source         = isset( $_POST['htax_rt_source_taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['htax_rt_source_taxonomies'] ) ) : array();
    $new_target         = isset( $_POST['htax_rt_target_taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['htax_rt_target_taxonomies'] ) ) : array();
    $core_type          = isset( $_POST['htax_rt_core_type'] ) ? sanitize_key( wp_unslash( $_POST['htax_rt_core_type'] ) ) : '';
    $is_includes_mapped = ( 'includes' === $core_type );

    $table              = $wpdb->prefix . 'htax_term_relationships';
    $invalid_conditions = array();

    // Group+group rows are never valid.
    $invalid_conditions[] = "(source_taxonomy = 'group' AND target_taxonomy = 'group')";

    // Taxonomy constraint violations — exempt when the relevant side is a group.
    if ( ! empty( $new_source ) ) {
        $placeholders         = implode( ', ', array_fill( 0, count( $new_source ), '%s' ) );
        $invalid_conditions[] = $wpdb->prepare(
            "(source_taxonomy != 'group' AND source_taxonomy NOT IN ($placeholders))",
            ...$new_source
        );
    }
    if ( ! empty( $new_target ) ) {
        $placeholders         = implode( ', ', array_fill( 0, count( $new_target ), '%s' ) );
        $invalid_conditions[] = $wpdb->prepare(
            "(target_taxonomy != 'group' AND target_taxonomy NOT IN ($placeholders))",
            ...$new_target
        );
    }

    // Includes-mapped: cross-taxonomy rows (both non-group) are invalid.
    if ( $is_includes_mapped ) {
        $invalid_conditions[] = "(source_taxonomy != 'group' AND target_taxonomy != 'group' AND source_taxonomy != target_taxonomy)";
    }

    $type_cond = $wpdb->prepare( 'relationship_type = %s', $slug );
    $where_sql = $type_cond . ' AND (' . implode( ' OR ', $invalid_conditions ) . ')';

    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}" );

    $message = $count > 0
        ? sprintf(
            /* translators: %d: number of relationship rows that will be deleted */
            _n(
                '%d existing relationship row uses this type but does not match the new taxonomy constraints. It will be deleted. Proceed?',
                '%d existing relationship rows use this type but do not match the new taxonomy constraints. They will be deleted. Proceed?',
                $count,
                'hypertaxonomy'
            ),
            $count
        )
        : '';

    wp_send_json_success(
        array(
            'count'   => $count,
            'message' => $message,
        )
    );
}

// ============================================================================
// FORM HANDLERS
// ============================================================================

/**
 * Handle save/delete form submissions for custom relationship types.
 *
 * Hooked to admin_post_htax_save_relationship_type and
 * admin_post_htax_remove_relationship_type.
 *
 * @since 1.0.0
 */
function htax_handle_save_relationship_type(): void {
    check_admin_referer( 'htax_relationship_type_save' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage relationship types.', 'hypertaxonomy' ) );
    }

    $slug          = isset( $_POST['htax_rt_slug'] ) ? sanitize_key( wp_unslash( $_POST['htax_rt_slug'] ) ) : '';
    $label         = isset( $_POST['htax_rt_label'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_rt_label'] ) ) : '';
    $inverse_label = isset( $_POST['htax_rt_inverse_label'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_rt_inverse_label'] ) ) : '';
    $description   = isset( $_POST['htax_rt_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['htax_rt_description'] ) ) : '';
    $is_new        = ! empty( $_POST['htax_rt_is_new'] );
    $core_type     = isset( $_POST['htax_rt_core_type'] ) ? sanitize_key( wp_unslash( $_POST['htax_rt_core_type'] ) ) : '';

    // Symmetric flag — only meaningful for non-inclusion-based types.
    // When set, the forward label doubles as the inverse label.
    $symmetric = ( 'includes' !== $core_type ) && ! empty( $_POST['htax_rt_symmetric'] );
    if ( $symmetric ) {
        $inverse_label = $label;
    }

    if ( empty( $slug ) || empty( $label ) || empty( $inverse_label ) ) {
        htax_add_admin_notice( __( 'Slug, label, and inverse label are required.', 'hypertaxonomy' ), 'error' );
        wp_safe_redirect( admin_url( 'admin.php?page=htax-term-relationships' ) );
        exit;
    }

    // Inclusion-based path: single shared taxonomy field → both source and target.
    if ( 'includes' === $core_type ) {
        $taxonomy          = isset( $_POST['htax_rt_taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['htax_rt_taxonomy'] ) ) : '';
        $source_taxonomies = $taxonomy ? array( $taxonomy ) : array();
        $target_taxonomies = $source_taxonomies;
    } else {
        $source_taxonomies = isset( $_POST['htax_rt_source_taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['htax_rt_source_taxonomies'] ) ) : array();
        $target_taxonomies = isset( $_POST['htax_rt_target_taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['htax_rt_target_taxonomies'] ) ) : array();
    }

    $args = array(
        'label'             => $label,
        'inverse_label'     => $inverse_label,
        'description'       => $description,
        'core_type'         => $core_type ?: null,
        'symmetric'         => $symmetric,
        'show_in_ui'        => ! empty( $_POST['htax_rt_show_in_ui'] ),
        'is_private'        => ! empty( $_POST['htax_rt_is_private'] ),
        'source_taxonomies' => $source_taxonomies,
        'target_taxonomies' => $target_taxonomies,
    );

    $result = htax_set_custom_relationship_type( $slug, $args, $is_new );

    if ( is_wp_error( $result ) ) {
        htax_add_admin_notice( $result->get_error_message(), 'error' );
    } else {
        htax_remove_invalid_term_relationships( $slug );
        htax_add_admin_notice( __( 'Relationship type saved.', 'hypertaxonomy' ), 'success' );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=htax-term-relationships' ) );
    exit;
}
add_action( 'admin_post_htax_save_relationship_type', 'htax_handle_save_relationship_type' );

/**
 * Handle delete form submission for a custom relationship type.
 *
 * @since 1.0.0
 */
function htax_handle_remove_relationship_type(): void {
    check_admin_referer( 'htax_remove_relationship_type' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage relationship types.', 'hypertaxonomy' ) );
    }

    $slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';

    if ( ! $slug ) {
        wp_safe_redirect( admin_url( 'admin.php?page=htax-term-relationships' ) );
        exit;
    }

    $deleted = htax_remove_custom_relationship_type( $slug );

    if ( $deleted ) {
        htax_add_admin_notice( __( 'Relationship type deleted.', 'hypertaxonomy' ), 'success' );
    } else {
        htax_add_admin_notice( __( 'Could not delete that relationship type.', 'hypertaxonomy' ), 'error' );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=htax-term-relationships' ) );
    exit;
}
add_action( 'admin_post_htax_remove_relationship_type', 'htax_handle_remove_relationship_type' );

/**
 * Handle "Make local" form submission for a remote relationship type.
 *
 * Clears source_site, transferring ownership to this site. Irreversible.
 *
 * @since 1.0.0
 */
function htax_handle_make_relationship_type_local(): void {
    check_admin_referer( 'htax_make_relationship_type_local' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage relationship types.', 'hypertaxonomy' ) );
    }

    $slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';

    if ( $slug && htax_make_relationship_type_local( $slug ) ) {
        htax_add_admin_notice( __( 'Relationship type is now locally owned. You can edit it from the local types list.', 'hypertaxonomy' ), 'success' );
    } else {
        htax_add_admin_notice( __( 'Could not make that relationship type local.', 'hypertaxonomy' ), 'error' );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=htax-term-relationships' ) );
    exit;
}
add_action( 'admin_post_htax_make_relationship_type_local', 'htax_handle_make_relationship_type_local' );

/**
 * AJAX: save a custom relationship type (from the floating edit pane).
 *
 * @since 1.0.0
 */
function htax_ajax_save_relationship_type_pane(): void {
    check_ajax_referer( 'htax_relationship_type_save', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hypertaxonomy' ) ), 403 );
    }

    $slug          = isset( $_POST['htax_rt_slug'] ) ? sanitize_key( wp_unslash( $_POST['htax_rt_slug'] ) ) : '';
    $label         = isset( $_POST['htax_rt_label'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_rt_label'] ) ) : '';
    $inverse_label = isset( $_POST['htax_rt_inverse_label'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_rt_inverse_label'] ) ) : '';
    $description   = isset( $_POST['htax_rt_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['htax_rt_description'] ) ) : '';
    $core_type     = isset( $_POST['htax_rt_core_type'] ) ? sanitize_key( wp_unslash( $_POST['htax_rt_core_type'] ) ) : '';

    $symmetric = ( 'includes' !== $core_type ) && ! empty( $_POST['htax_rt_symmetric'] );
    if ( $symmetric ) {
        $inverse_label = $label;
    }

    if ( empty( $slug ) || empty( $label ) || ( empty( $inverse_label ) && ! $symmetric ) ) {
        wp_send_json_error( array( 'message' => __( 'Slug, label, and inverse label are required.', 'hypertaxonomy' ) ) );
    }

    if ( 'includes' === $core_type ) {
        $taxonomy          = isset( $_POST['htax_rt_taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['htax_rt_taxonomy'] ) ) : '';
        $source_taxonomies = $taxonomy ? array( $taxonomy ) : array();
        $target_taxonomies = $source_taxonomies;
    } else {
        $source_taxonomies = isset( $_POST['htax_rt_source_taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['htax_rt_source_taxonomies'] ) ) : array();
        $target_taxonomies = isset( $_POST['htax_rt_target_taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['htax_rt_target_taxonomies'] ) ) : array();
    }

    $result = htax_set_custom_relationship_type( $slug, array(
        'label'             => $label,
        'inverse_label'     => $inverse_label,
        'description'       => $description,
        'core_type'         => $core_type ?: null,
        'symmetric'         => $symmetric,
        'show_in_ui'        => ! empty( $_POST['htax_rt_show_in_ui'] ),
        'is_private'        => ! empty( $_POST['htax_rt_is_private'] ),
        'source_taxonomies' => $source_taxonomies,
        'target_taxonomies' => $target_taxonomies,
    ), ! empty( $_POST['htax_rt_is_new'] ) );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    htax_remove_invalid_term_relationships( $slug );
    wp_send_json_success();
}
add_action( 'wp_ajax_htax_save_relationship_type_pane', 'htax_ajax_save_relationship_type_pane' );

/**
 * AJAX: delete a custom relationship type (inline, without page reload).
 *
 * @since 1.0.0
 */
function htax_ajax_remove_relationship_type(): void {
    check_ajax_referer( 'htax_remove_relationship_type', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hypertaxonomy' ) ), 403 );
    }

    $slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

    if ( ! $slug || ! htax_remove_custom_relationship_type( $slug ) ) {
        wp_send_json_error( array( 'message' => __( 'Could not delete that relationship type.', 'hypertaxonomy' ) ) );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_htax_remove_relationship_type_ajax', 'htax_ajax_remove_relationship_type' );

/**
 * AJAX: re-sync a remote relationship type from its source site.
 *
 * Fires the htax_sync_remote_relationship_types filter so TermConnect (or any
 * other plugin) can perform the actual fetch-and-update without a hard dependency
 * from HyperTaxonomy onto TermConnect.
 *
 * @since 1.0.0
 */
function htax_ajax_sync_remote_relationship_type(): void {
    check_ajax_referer( 'htax_sync_remote_rt', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hypertaxonomy' ) ), 403 );
    }

    $source_site = isset( $_POST['source_site'] ) ? esc_url_raw( wp_unslash( $_POST['source_site'] ) ) : '';

    if ( ! $source_site ) {
        wp_send_json_error( array( 'message' => __( 'No source site specified.', 'hypertaxonomy' ) ) );
    }

    $result = apply_filters( 'htax_sync_remote_relationship_types', array( 'success' => false, 'message' => __( 'No sync handler active — TermConnect may not be installed.', 'hypertaxonomy' ) ), $source_site );

    if ( ! empty( $result['success'] ) ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }
}
add_action( 'wp_ajax_htax_sync_remote_relationship_type', 'htax_ajax_sync_remote_relationship_type' );

// ============================================================================
// PAGE RENDERER
// ============================================================================

/**
 * Render the Term Relationships admin page.
 *
 * Replaces the stub in admin-menu.php once this file is loaded.
 *
 * @since 1.0.0
 */
function htax_render_term_relationships_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'hypertaxonomy' ) );
    }

    $all_types    = htax_get_registered_relationship_types();
    $core_types   = array_filter( $all_types, fn( $t ) => ! empty( $t['is_core'] ) );
    $custom_types = array_filter( $all_types, fn( $t ) => empty( $t['is_core'] ) );
    $remote_types = array_filter( $custom_types, fn( $t ) => ! empty( $t['source_site'] ) );
    $local_types  = array_filter( $custom_types, fn( $t ) => empty( $t['source_site'] ) );

    // Build slug => label map for taxonomy options, excluding the internal 'group' taxonomy.
    $taxonomy_options = array();
    foreach ( htax_get_enabled_taxonomies() as $tax_slug ) {
        if ( 'group' === $tax_slug ) {
            continue;
        }
        $tax_obj                       = get_taxonomy( $tax_slug );
        $taxonomy_options[ $tax_slug ] = $tax_obj ? $tax_obj->label : $tax_slug;
    }

    $local_namespace = wp_parse_url( home_url(), PHP_URL_HOST ) ?: home_url();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Term Relationship Types', 'hypertaxonomy' ); ?></h1>

        <?php htax_display_queued_notices(); ?>

        <h2><?php esc_html_e( 'Built-in Relationship Types', 'hypertaxonomy' ); ?></h2>
        <p class="description"><?php esc_html_e( 'These types are provided by HyperTaxonomy and cannot be modified or deleted.', 'hypertaxonomy' ); ?></p>

        <table class="wp-list-table widefat fixed striped" style="max-width:800px">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Slug', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Label', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Inverse label', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'User-mappable', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Namespace', 'hypertaxonomy' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $core_types as $slug => $type ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $slug ); ?></code></td>
                    <td><?php echo esc_html( $type['label'] ); ?></td>
                    <td><?php echo esc_html( $type['inverse_label'] ?: '—' ); ?></td>
                    <td><?php echo ! empty( $type['user_mappable'] ) ? esc_html__( 'Yes', 'hypertaxonomy' ) : esc_html__( 'No', 'hypertaxonomy' ); ?></td>
                    <td><code>hypertaxonomy.org</code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $remote_types ) ) : ?>
        <hr style="margin:30px 0">

        <h2><?php esc_html_e( 'Remote Relationship Types', 'hypertaxonomy' ); ?></h2>
        <p class="description"><?php esc_html_e( 'These types were synced from peer sites via TermConnect. They are read-only while delegated. Making a type local detaches it from its source — it will no longer be updated automatically.', 'hypertaxonomy' ); ?></p>

        <table class="wp-list-table widefat fixed striped" style="max-width:900px;margin-bottom:20px">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Slug', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Label', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Inverse label', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Namespace', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'hypertaxonomy' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $remote_types as $slug => $type ) :
                    $desc = $type['description'] ?? '';
                ?>
                <tr>
                    <td>
                        <code<?php echo $desc ? ' title="' . esc_attr( $desc ) . '"' : ''; ?>>
                            <?php echo esc_html( $slug ); ?>
                        </code>
                    </td>
                    <td><?php echo esc_html( $type['label'] ); ?></td>
                    <td><?php echo esc_html( $type['inverse_label'] ?: '—' ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( $type['source_site'] ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( wp_parse_url( $type['source_site'], PHP_URL_HOST ) ?: $type['source_site'] ); ?>
                        </a>
                    </td>
                    <td style="white-space:nowrap">
                        <button type="button" class="button-link htax-rt-sync-btn"
                                data-source-site="<?php echo esc_attr( $type['source_site'] ); ?>">
                            <?php esc_html_e( 'Sync', 'hypertaxonomy' ); ?>
                        </button>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'htax_make_relationship_type_local', 'slug' => $slug ), admin_url( 'admin-post.php' ) ), 'htax_make_relationship_type_local' ) ); ?>"
                           onclick="return confirm('<?php echo esc_js( __( 'Make this type local? This cannot be undone. The type definition will become editable and its namespace will change to this site. It will no longer be updated from the source.', 'hypertaxonomy' ) ); ?>')">
                            <?php esc_html_e( 'Make local', 'hypertaxonomy' ); ?>
                        </a>
                        &nbsp;|&nbsp;
                        <button type="button" class="button-link htax-rt-delete-btn"
                                data-slug="<?php echo esc_attr( $slug ); ?>">
                            <?php esc_html_e( 'Delete', 'hypertaxonomy' ); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <hr style="margin:30px 0">

        <h2><?php esc_html_e( 'Local Relationship Types', 'hypertaxonomy' ); ?></h2>

        <?php if ( ! empty( $local_types ) ) : ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:900px;margin-bottom:20px">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Slug', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Label', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Inverse label', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Kind', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Private', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Namespace', 'hypertaxonomy' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'hypertaxonomy' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $local_types as $slug => $type ) :
                    $bound_taxes    = array_merge( $type['source_taxonomies'] ?? array(), $type['target_taxonomies'] ?? array() );
                    $inactive_taxes = array_filter( array_unique( $bound_taxes ), fn( $t ) => ! taxonomy_exists( $t ) );
                    $row_style      = $inactive_taxes ? ' style="opacity:0.6"' : '';
                    $row_title      = $inactive_taxes
                        ? ' title="' . esc_attr( sprintf(
                            /* translators: %s: comma-separated list of taxonomy slugs */
                            __( 'Inactive — taxonomy not registered: %s', 'hypertaxonomy' ),
                            implode( ', ', $inactive_taxes )
                          ) ) . '"'
                        : '';
                    $type_json = wp_json_encode( array(
                        'slug'              => $slug,
                        'label'             => $type['label'],
                        'inverse_label'     => $type['inverse_label'],
                        'description'       => $type['description'] ?? '',
                        'core_type'         => $type['core_type'] ?? '',
                        'symmetric'         => ! empty( $type['symmetric'] ),
                        'show_in_ui'        => isset( $type['show_in_ui'] ) ? (bool) $type['show_in_ui'] : true,
                        'is_private'        => ! empty( $type['is_private'] ),
                        'source_taxonomies' => $type['source_taxonomies'] ?? array(),
                        'target_taxonomies' => $type['target_taxonomies'] ?? array(),
                    ) );
                ?>
                <tr<?php echo $row_style . $row_title; ?>>
                    <td><code><?php echo esc_html( $slug ); ?></code></td>
                    <td><?php echo esc_html( $type['label'] ); ?></td>
                    <td><?php echo esc_html( $type['inverse_label'] ?: '—' ); ?></td>
                    <td><?php echo 'includes' === ( $type['core_type'] ?? null ) ? esc_html__( 'Inclusion-based', 'hypertaxonomy' ) : esc_html__( 'Custom', 'hypertaxonomy' ); ?></td>
                    <td><?php echo ! empty( $type['is_private'] ) ? esc_html__( 'Yes', 'hypertaxonomy' ) : '—'; ?></td>
                    <td><code><?php echo esc_html( $local_namespace ); ?></code></td>
                    <td style="white-space:nowrap">
                        <button type="button" class="button-link htax-rt-edit-btn"
                                data-type="<?php echo esc_attr( $type_json ); ?>">
                            <?php esc_html_e( 'Edit', 'hypertaxonomy' ); ?>
                        </button>
                        &nbsp;|&nbsp;
                        <button type="button" class="button-link htax-rt-delete-btn"
                                data-slug="<?php echo esc_attr( $slug ); ?>">
                            <?php esc_html_e( 'Delete', 'hypertaxonomy' ); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p><?php esc_html_e( 'No local relationship types yet.', 'hypertaxonomy' ); ?></p>
        <?php endif; ?>

        <?php
        /**
         * Fires after all relationship type tables on the admin page.
         *
         * TermConnect hooks here to inject the "Discover from peer site" UI
         * for importing remote relationship type definitions. Only fires when
         * HyperTaxonomy is active (this page belongs to HT).
         *
         * @since 1.0.0
         */
        do_action( 'htax_relationship_types_admin_footer' );
        ?>

        <h3><?php esc_html_e( 'Add New Relationship Type', 'hypertaxonomy' ); ?></h3>
        <p>
            <button type="button" class="button button-primary htax-rt-new-btn" data-kind="inclusion">
                <?php esc_html_e( 'New Inclusion-based Relationship Type', 'hypertaxonomy' ); ?>
            </button>
            &nbsp;&nbsp;
            <button type="button" class="button htax-rt-new-btn" data-kind="custom">
                <?php esc_html_e( 'New Custom Relationship Type', 'hypertaxonomy' ); ?>
            </button>
        </p>
        <p class="description">
            <?php esc_html_e( 'Inclusion-based types participate in archive expansion — source and target must be from the same taxonomy, and groups on either side are expanded to their members in that taxonomy. Custom types store the relationship only; behavior is implemented externally.', 'hypertaxonomy' ); ?>
        </p>

        <!-- Floating edit pane (always rendered, shown on demand) -->
        <div id="htax-rt-pane-backdrop" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9998"></div>
        <div id="htax-rt-pane" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;z-index:9999;width:620px;max-height:85vh;overflow-y:auto;box-shadow:0 5px 20px rgba(0,0,0,0.3);border-radius:3px;padding:24px" role="dialog" aria-modal="true" aria-labelledby="htax-rt-pane-title">

            <h3 id="htax-rt-pane-title" style="margin-top:0"></h3>

            <p id="htax-rt-pane-slug-display-row">
                <strong><?php esc_html_e( 'Slug:', 'hypertaxonomy' ); ?></strong>
                <code id="htax-rt-pane-slug-display"></code>
                <input type="hidden" id="htax-rt-pane-slug" value="">
                <input type="hidden" id="htax-rt-pane-core-type" value="">
            </p>
            <div id="htax-rt-pane-slug-new-row" style="display:none;margin-bottom:12px">
                <label for="htax-rt-pane-slug-input"><strong><?php esc_html_e( 'Slug:', 'hypertaxonomy' ); ?></strong></label><br>
                <input type="text" id="htax-rt-pane-slug-input" class="regular-text" style="margin-top:4px"
                       pattern="[a-z0-9-]{1,80}"
                       placeholder="<?php esc_attr_e( 'e.g. wrote', 'hypertaxonomy' ); ?>">
                <br><span class="description"><?php esc_html_e( 'Lowercase letters, digits, hyphens. Cannot be changed after save.', 'hypertaxonomy' ); ?></span>
            </div>

            <table class="form-table" style="margin:0">
                <tr>
                    <th scope="row"><label for="htax-rt-pane-label"><?php esc_html_e( 'Label', 'hypertaxonomy' ); ?></label></th>
                    <td>
                        <input type="text" id="htax-rt-pane-label" class="regular-text">
                        <p class="description"><?php esc_html_e( 'Forward label, e.g. "Wrote".', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
                <tr id="htax-rt-pane-symmetric-row">
                    <th scope="row"><?php esc_html_e( 'Symmetric', 'hypertaxonomy' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="htax-rt-pane-symmetric">
                            <?php esc_html_e( 'Symmetric relationship — source and target are interchangeable', 'hypertaxonomy' ); ?>
                        </label>
                    </td>
                </tr>
                <tr id="htax-rt-pane-inverse-row">
                    <th scope="row"><label for="htax-rt-pane-inverse-label"><?php esc_html_e( 'Inverse label', 'hypertaxonomy' ); ?></label></th>
                    <td>
                        <input type="text" id="htax-rt-pane-inverse-label" class="regular-text"
                               placeholder="<?php esc_attr_e( 'e.g. Written by', 'hypertaxonomy' ); ?>">
                    </td>
                </tr>
                <?php if ( ! empty( $taxonomy_options ) ) : ?>
                <tr id="htax-rt-pane-taxonomy-row" style="display:none">
                    <th scope="row"><label for="htax-rt-pane-taxonomy"><?php esc_html_e( 'Taxonomy', 'hypertaxonomy' ); ?></label></th>
                    <td>
                        <select id="htax-rt-pane-taxonomy">
                            <option value=""><?php esc_html_e( '— Select taxonomy —', 'hypertaxonomy' ); ?></option>
                            <?php foreach ( $taxonomy_options as $tax_slug => $tax_label ) : ?>
                            <option value="<?php echo esc_attr( $tax_slug ); ?>"><?php echo esc_html( $tax_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Both source and target must be terms (or groups) from this taxonomy.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
                <tr id="htax-rt-pane-source-row">
                    <th scope="row"><?php esc_html_e( 'Source taxonomies', 'hypertaxonomy' ); ?></th>
                    <td id="htax-rt-pane-source-taxes">
                        <?php htax_render_taxonomy_checkboxes( 'htax_rt_source_taxonomies', array(), $taxonomy_options ); ?>
                        <p class="description"><?php esc_html_e( 'Leave all unchecked to allow any.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
                <tr id="htax-rt-pane-target-row">
                    <th scope="row"><?php esc_html_e( 'Target taxonomies', 'hypertaxonomy' ); ?></th>
                    <td id="htax-rt-pane-target-taxes">
                        <?php htax_render_taxonomy_checkboxes( 'htax_rt_target_taxonomies', array(), $taxonomy_options ); ?>
                        <p class="description"><?php esc_html_e( 'Leave all unchecked to allow any.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><label for="htax-rt-pane-description"><?php esc_html_e( 'Description', 'hypertaxonomy' ); ?></label></th>
                    <td>
                        <textarea id="htax-rt-pane-description" class="large-text" rows="3"></textarea>
                        <p class="description"><?php esc_html_e( 'Optional explanation of what this relationship type represents.', 'hypertaxonomy' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show in UI', 'hypertaxonomy' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="htax-rt-pane-show-in-ui">
                            <?php esc_html_e( 'Show in term edit screen relationship selector', 'hypertaxonomy' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Private', 'hypertaxonomy' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="htax-rt-pane-is-private">
                            <?php esc_html_e( 'Exclude from peer sharing — this type and its relationships will not appear in REST responses to peer sites', 'hypertaxonomy' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p id="htax-rt-pane-error" style="color:#d63638;display:none;margin-top:12px"></p>

            <p style="margin-top:16px;padding-top:16px;border-top:1px solid #ddd">
                <button type="button" id="htax-rt-pane-save" class="button button-primary">
                    <?php esc_html_e( 'Update Relationship Type', 'hypertaxonomy' ); ?>
                </button>
                &nbsp;
                <button type="button" id="htax-rt-pane-cancel" class="button">
                    <?php esc_html_e( 'Cancel', 'hypertaxonomy' ); ?>
                </button>
            </p>
        </div>

        <script>
        (function($) {
            var nonces = {
                save:   '<?php echo esc_js( wp_create_nonce( 'htax_relationship_type_save' ) ); ?>',
                remove: '<?php echo esc_js( wp_create_nonce( 'htax_remove_relationship_type' ) ); ?>',
                sync:   '<?php echo esc_js( wp_create_nonce( 'htax_sync_remote_rt' ) ); ?>'
            };

            // ---- Pane ----

            var paneIsNew = false;

            function updatePaneSymmetric() {
                var sym = $('#htax-rt-pane-symmetric').is(':checked');
                $('#htax-rt-pane-inverse-row').toggle( ! sym );
            }

            function openPane( type ) {
                paneIsNew = false;
                var isInclusion = ( 'includes' === type.core_type );

                $( '#htax-rt-pane-title' ).text(
                    isInclusion
                        ? '<?php echo esc_js( __( 'Edit Inclusion-based Relationship Type', 'hypertaxonomy' ) ); ?>'
                        : '<?php echo esc_js( __( 'Edit Custom Relationship Type', 'hypertaxonomy' ) ); ?>'
                );

                $( '#htax-rt-pane-slug-display-row' ).show();
                $( '#htax-rt-pane-slug-new-row' ).hide();
                $( '#htax-rt-pane-slug-display' ).text( type.slug );
                $( '#htax-rt-pane-slug' ).val( type.slug );
                $( '#htax-rt-pane-core-type' ).val( type.core_type || '' );
                $( '#htax-rt-pane-label' ).val( type.label || '' );
                $( '#htax-rt-pane-inverse-label' ).val( type.inverse_label || '' );
                $( '#htax-rt-pane-description' ).val( type.description || '' );
                $( '#htax-rt-pane-symmetric' ).prop( 'checked', !! type.symmetric );
                $( '#htax-rt-pane-show-in-ui' ).prop( 'checked', !! type.show_in_ui );
                $( '#htax-rt-pane-is-private' ).prop( 'checked', !! type.is_private );
                $( '#htax-rt-pane-error' ).hide().text( '' );

                if ( isInclusion ) {
                    $( '#htax-rt-pane-symmetric-row' ).hide();
                    $( '#htax-rt-pane-inverse-row' ).show();
                    $( '#htax-rt-pane-taxonomy-row' ).show();
                    $( '#htax-rt-pane-source-row, #htax-rt-pane-target-row' ).hide();
                    var tax = type.source_taxonomies && type.source_taxonomies[0] ? type.source_taxonomies[0] : '';
                    $( '#htax-rt-pane-taxonomy' ).val( tax );
                } else {
                    $( '#htax-rt-pane-symmetric-row' ).show();
                    $( '#htax-rt-pane-taxonomy-row' ).hide();
                    $( '#htax-rt-pane-source-row, #htax-rt-pane-target-row' ).show();

                    $( '#htax-rt-pane-source-taxes input[type="checkbox"]' ).prop( 'checked', false );
                    $( '#htax-rt-pane-target-taxes input[type="checkbox"]' ).prop( 'checked', false );

                    if ( type.source_taxonomies ) {
                        type.source_taxonomies.forEach( function( t ) {
                            $( '#htax-rt-pane-source-taxes input[value="' + t + '"]' ).prop( 'checked', true );
                        } );
                    }
                    if ( type.target_taxonomies ) {
                        type.target_taxonomies.forEach( function( t ) {
                            $( '#htax-rt-pane-target-taxes input[value="' + t + '"]' ).prop( 'checked', true );
                        } );
                    }
                }

                updatePaneSymmetric();

                $( '#htax-rt-pane-save' ).text( '<?php echo esc_js( __( 'Update Relationship Type', 'hypertaxonomy' ) ); ?>' );
                $( '#htax-rt-pane-backdrop, #htax-rt-pane' ).show();
                $( '#htax-rt-pane-label' ).trigger( 'focus' );
            }

            function openPaneNew( kind ) {
                paneIsNew = true;
                var isInclusion = ( 'inclusion' === kind );

                $( '#htax-rt-pane-title' ).text(
                    isInclusion
                        ? '<?php echo esc_js( __( 'New Inclusion-based Relationship Type', 'hypertaxonomy' ) ); ?>'
                        : '<?php echo esc_js( __( 'New Custom Relationship Type', 'hypertaxonomy' ) ); ?>'
                );

                $( '#htax-rt-pane-slug-display-row' ).hide();
                $( '#htax-rt-pane-slug-new-row' ).show();
                $( '#htax-rt-pane-slug-input' ).val( '' );
                $( '#htax-rt-pane-slug' ).val( '' );
                $( '#htax-rt-pane-core-type' ).val( isInclusion ? 'includes' : '' );
                $( '#htax-rt-pane-label' ).val( '' );
                $( '#htax-rt-pane-inverse-label' ).val( '' );
                $( '#htax-rt-pane-description' ).val( '' );
                $( '#htax-rt-pane-symmetric' ).prop( 'checked', false );
                $( '#htax-rt-pane-show-in-ui' ).prop( 'checked', true );
                $( '#htax-rt-pane-is-private' ).prop( 'checked', false );
                $( '#htax-rt-pane-error' ).hide().text( '' );

                if ( isInclusion ) {
                    $( '#htax-rt-pane-symmetric-row' ).hide();
                    $( '#htax-rt-pane-inverse-row' ).show();
                    $( '#htax-rt-pane-taxonomy-row' ).show();
                    $( '#htax-rt-pane-source-row, #htax-rt-pane-target-row' ).hide();
                    $( '#htax-rt-pane-taxonomy' ).val( '' );
                } else {
                    $( '#htax-rt-pane-symmetric-row' ).show();
                    $( '#htax-rt-pane-taxonomy-row' ).hide();
                    $( '#htax-rt-pane-source-row, #htax-rt-pane-target-row' ).show();
                    $( '#htax-rt-pane-source-taxes input[type="checkbox"]' ).prop( 'checked', false );
                    $( '#htax-rt-pane-target-taxes input[type="checkbox"]' ).prop( 'checked', false );
                }

                updatePaneSymmetric();

                $( '#htax-rt-pane-save' ).text( '<?php echo esc_js( __( 'Add Relationship Type', 'hypertaxonomy' ) ); ?>' );
                $( '#htax-rt-pane-backdrop, #htax-rt-pane' ).show();
                $( '#htax-rt-pane-label' ).trigger( 'focus' );
            }

            function closePane() {
                $( '#htax-rt-pane-backdrop, #htax-rt-pane' ).hide();
            }

            $( '#htax-rt-pane-symmetric' ).on( 'change', updatePaneSymmetric );

            $( document ).on( 'click', '.htax-rt-edit-btn', function() {
                try {
                    openPane( JSON.parse( $( this ).attr( 'data-type' ) ) );
                } catch ( e ) {
                    alert( '<?php echo esc_js( __( 'Could not open editor.', 'hypertaxonomy' ) ); ?>' );
                }
            } );

            $( document ).on( 'click', '.htax-rt-new-btn', function() {
                openPaneNew( $( this ).data( 'kind' ) );
            } );

            $( '#htax-rt-pane-backdrop, #htax-rt-pane-cancel' ).on( 'click', closePane );

            $( document ).on( 'keydown', function( e ) {
                if ( 27 === e.which && $( '#htax-rt-pane' ).is( ':visible' ) ) {
                    closePane();
                }
            } );

            // ---- Pane save ----

            $( '#htax-rt-pane-save' ).on( 'click', function() {
                var btn      = $( this );
                var slug     = paneIsNew
                    ? $( '#htax-rt-pane-slug-input' ).val().trim()
                    : $( '#htax-rt-pane-slug' ).val();
                var coreType = $( '#htax-rt-pane-core-type' ).val();
                var label    = $( '#htax-rt-pane-label' ).val().trim();
                var sym      = $( '#htax-rt-pane-symmetric' ).is( ':checked' );
                var invLabel = sym ? label : $( '#htax-rt-pane-inverse-label' ).val().trim();
                var sourceTaxes, targetTaxes;

                if ( paneIsNew && ! slug ) {
                    $( '#htax-rt-pane-error' ).show().text( '<?php echo esc_js( __( 'Slug is required.', 'hypertaxonomy' ) ); ?>' );
                    return;
                }

                if ( ! label || ( ! sym && ! invLabel ) ) {
                    $( '#htax-rt-pane-error' ).show().text( '<?php echo esc_js( __( 'Label and inverse label are required.', 'hypertaxonomy' ) ); ?>' );
                    return;
                }

                if ( 'includes' === coreType ) {
                    var tax = $( '#htax-rt-pane-taxonomy' ).val();
                    sourceTaxes = tax ? [ tax ] : [];
                    targetTaxes = sourceTaxes;
                } else {
                    sourceTaxes = $( '#htax-rt-pane-source-taxes input:checked' ).map( function() { return this.value; } ).get();
                    targetTaxes = $( '#htax-rt-pane-target-taxes input:checked' ).map( function() { return this.value; } ).get();
                }

                btn.prop( 'disabled', true );

                function doSave() {
                    var fd = new FormData();
                    fd.append( 'action', 'htax_save_relationship_type_pane' );
                    fd.append( 'nonce', nonces.save );
                    fd.append( 'htax_rt_slug', slug );
                    fd.append( 'htax_rt_core_type', coreType );
                    fd.append( 'htax_rt_label', label );
                    fd.append( 'htax_rt_inverse_label', invLabel );
                    fd.append( 'htax_rt_description', $( '#htax-rt-pane-description' ).val() );
                    fd.append( 'htax_rt_show_in_ui', $( '#htax-rt-pane-show-in-ui' ).is( ':checked' ) ? '1' : '' );
                    fd.append( 'htax_rt_is_private', $( '#htax-rt-pane-is-private' ).is( ':checked' ) ? '1' : '' );
                    if ( sym ) { fd.append( 'htax_rt_symmetric', '1' ); }
                    if ( paneIsNew ) { fd.append( 'htax_rt_is_new', '1' ); }
                    if ( 'includes' === coreType ) {
                        fd.append( 'htax_rt_taxonomy', sourceTaxes[0] || '' );
                    } else {
                        sourceTaxes.forEach( function( t ) { fd.append( 'htax_rt_source_taxonomies[]', t ); } );
                        targetTaxes.forEach( function( t ) { fd.append( 'htax_rt_target_taxonomies[]', t ); } );
                    }

                    fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
                        .then( function( r ) { return r.json(); } )
                        .then( function( json ) {
                            btn.prop( 'disabled', false );
                            if ( json.success ) {
                                closePane();
                                location.reload();
                            } else {
                                $( '#htax-rt-pane-error' ).show().text(
                                    json.data && json.data.message
                                        ? json.data.message
                                        : '<?php echo esc_js( __( 'Save failed.', 'hypertaxonomy' ) ); ?>'
                                );
                            }
                        } )
                        .catch( function() {
                            btn.prop( 'disabled', false );
                            $( '#htax-rt-pane-error' ).show().text( '<?php echo esc_js( __( 'Request failed.', 'hypertaxonomy' ) ); ?>' );
                        } );
                }

                // New types have no existing rows — skip the ineligible-rows check.
                if ( paneIsNew ) {
                    doSave();
                    return;
                }

                // Check for ineligible rows before saving.
                var countFd = new FormData();
                countFd.append( 'action', 'htax_count_ineligible_custom_relationships' );
                countFd.append( 'nonce', nonces.save );
                countFd.append( 'htax_rt_slug', slug );
                countFd.append( 'htax_rt_core_type', coreType );
                sourceTaxes.forEach( function( t ) { countFd.append( 'htax_rt_source_taxonomies[]', t ); } );
                targetTaxes.forEach( function( t ) { countFd.append( 'htax_rt_target_taxonomies[]', t ); } );

                fetch( ajaxurl, { method: 'POST', body: countFd, credentials: 'same-origin' } )
                    .then( function( r ) { return r.json(); } )
                    .then( function( json ) {
                        if ( json.success && json.data.count > 0 ) {
                            var msg = json.data.message;
                            if ( ! window.confirm( msg ) ) {
                                btn.prop( 'disabled', false );
                                return;
                            }
                        }
                        doSave();
                    } )
                    .catch( function() { doSave(); } );
            } );

            // ---- Sync button ----

            $( document ).on( 'click', '.htax-rt-sync-btn', function() {
                var btn        = $( this );
                var sourceSite = btn.data( 'source-site' );
                btn.prop( 'disabled', true ).text( '<?php echo esc_js( __( 'Syncing…', 'hypertaxonomy' ) ); ?>' );
                $.post( ajaxurl, {
                    action:      'htax_sync_remote_relationship_type',
                    nonce:       nonces.sync,
                    source_site: sourceSite
                } ).done( function( r ) {
                    if ( r.success ) {
                        location.reload();
                    } else {
                        alert( r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'Sync failed.', 'hypertaxonomy' ) ); ?>' );
                        btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Sync', 'hypertaxonomy' ) ); ?>' );
                    }
                } ).fail( function() {
                    alert( '<?php echo esc_js( __( 'Request failed.', 'hypertaxonomy' ) ); ?>' );
                    btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Sync', 'hypertaxonomy' ) ); ?>' );
                } );
            } );

            // ---- Delete button ----

            $( document ).on( 'click', '.htax-rt-delete-btn', function() {
                var btn  = $( this );
                var slug = btn.data( 'slug' );
                if ( ! window.confirm( '<?php echo esc_js( __( 'Delete this relationship type? All existing relationship rows using this type will also be deleted.', 'hypertaxonomy' ) ); ?>' ) ) {
                    return;
                }
                btn.prop( 'disabled', true );
                $.post( ajaxurl, {
                    action: 'htax_remove_relationship_type_ajax',
                    nonce:  nonces.remove,
                    slug:   slug
                } ).done( function( r ) {
                    if ( r.success ) {
                        btn.closest( 'tr' ).fadeOut( 200, function() { $( this ).remove(); } );
                    } else {
                        alert( r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'Delete failed.', 'hypertaxonomy' ) ); ?>' );
                        btn.prop( 'disabled', false );
                    }
                } ).fail( function() {
                    alert( '<?php echo esc_js( __( 'Request failed.', 'hypertaxonomy' ) ); ?>' );
                    btn.prop( 'disabled', false );
                } );
            } );

        })(jQuery);
        </script>

    </div>
    <?php
}

// ============================================================================
// HELPERS
// ============================================================================

/**
 * Render a set of taxonomy checkboxes for source/target taxonomy constraints.
 *
 * @since 1.0.0
 * @param string   $field_name   The form field name (array input).
 * @param string[] $selected     Currently selected taxonomy slugs.
 * @param array    $taxonomies   Available taxonomies as slug => label.
 */
function htax_render_taxonomy_checkboxes( string $field_name, array $selected, array $taxonomies ): void {
    foreach ( $taxonomies as $tax_slug => $tax_label ) {
        $checked = in_array( $tax_slug, $selected, true );
        printf(
            '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="%s[]" value="%s"%s> %s</label>',
            esc_attr( $field_name ),
            esc_attr( $tax_slug ),
            $checked ? ' checked' : '',
            esc_html( $tax_label )
        );
    }
}
