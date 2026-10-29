<?php
/**
 * Term Edit Locking
 *
 * Prevents concurrent editing of terms and custom relationship types by multiple
 * admins, using the same mechanism as WordPress post locking: a heartbeat-refreshed
 * lock with a take-over flow.
 *
 * Term locks:              _htax_edit_lock term meta  — "{timestamp}:{user_id}"
 * Relationship type locks: htax_rt_lock_{slug} transient — "{timestamp}:{user_id}"
 *
 * Applies to every term edit screen regardless of whether the taxonomy is
 * HyperTaxonomy-enabled (groups, custom taxonomies, built-in taxonomies).
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HTAX_LOCK_TIMEOUT', 150 ); // seconds, matches WordPress post lock

// ============================================================================
// CORE LOCK FUNCTIONS — TERMS
// ============================================================================

/**
 * Set or refresh the edit lock for a term to the current user.
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 */
function htax_set_term_edit_lock( int $term_id ): void {
    update_term_meta( $term_id, '_htax_edit_lock', time() . ':' . get_current_user_id() );
}

/**
 * Check whether a term is locked by another user.
 *
 * Returns the locking user, or false if: no lock exists, lock belongs to the
 * current user, or lock has expired.
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 * @return WP_User|false
 */
function htax_check_term_edit_lock( int $term_id ): WP_User|false {
    $lock = get_term_meta( $term_id, '_htax_edit_lock', true );
    if ( ! $lock ) {
        return false;
    }
    [ $time, $user_id ] = array_pad( explode( ':', $lock, 2 ), 2, 0 );
    $user_id = (int) $user_id;
    if ( get_current_user_id() === $user_id ) {
        return false;
    }
    if ( time() - (int) $time > HTAX_LOCK_TIMEOUT ) {
        return false;
    }
    $user = get_userdata( $user_id );
    return $user ? $user : false;
}

// ============================================================================
// CORE LOCK FUNCTIONS — RELATIONSHIP TYPES
// ============================================================================

/**
 * Set or refresh the edit lock for a custom relationship type slug.
 *
 * @since 1.0.0
 * @param string $slug Relationship type slug.
 */
function htax_set_rt_edit_lock( string $slug ): void {
    htax_set_transient(
        'htax_rt_lock_' . $slug,
        time() . ':' . get_current_user_id(),
        HTAX_LOCK_TIMEOUT + 30
    );
}

/**
 * Check whether a relationship type is locked by another user.
 *
 * @since 1.0.0
 * @param string $slug Relationship type slug.
 * @return WP_User|false
 */
function htax_check_rt_edit_lock( string $slug ): WP_User|false {
    $lock = htax_get_transient( 'htax_rt_lock_' . $slug );
    if ( false === $lock ) {
        return false;
    }
    [ $time, $user_id ] = array_pad( explode( ':', $lock, 2 ), 2, 0 );
    $user_id = (int) $user_id;
    if ( get_current_user_id() === $user_id ) {
        return false;
    }
    if ( time() - (int) $time > HTAX_LOCK_TIMEOUT ) {
        return false;
    }
    $user = get_userdata( $user_id );
    return $user ? $user : false;
}

// ============================================================================
// HEARTBEAT HANDLER
// ============================================================================

/**
 * Handle heartbeat lock refresh for both term and relationship type locks.
 *
 * The client sends htax_term_lock_refresh or htax_rt_lock_refresh on each tick.
 * If the lock has been taken by someone else, the response signals the client to
 * show the displacement dialog. Otherwise the lock timestamp is refreshed.
 *
 * @since 1.0.0
 * @param array $response Heartbeat response data.
 * @param array $data     Heartbeat request data.
 * @return array
 */
function htax_heartbeat_refresh_lock( array $response, array $data ): array {
    $options = HyperTaxonomy_Settings::get_instance()->get_options();
    if ( empty( $options['edit_lock_enabled'] ) ) {
        return $response;
    }

    // --- Term lock ---
    if ( ! empty( $data['htax_term_lock_refresh'] ) ) {
        $payload = $data['htax_term_lock_refresh'];
        $term_id = (int) ( $payload['term_id'] ?? 0 );
        $nonce   = sanitize_text_field( wp_unslash( $payload['nonce'] ?? '' ) );

        if ( $term_id
            && wp_verify_nonce( $nonce, 'htax_lock_term_' . $term_id )
            && current_user_can( 'manage_categories' )
        ) {
            $locked_by = htax_check_term_edit_lock( $term_id );
            if ( $locked_by ) {
                $response['htax_term_lock'] = array(
                    'lock_error' => true,
                    'name'       => $locked_by->display_name,
                    'avatar'     => get_avatar_url( $locked_by->ID, array( 'size' => 64 ) ),
                );
            } else {
                htax_set_term_edit_lock( $term_id );
                $response['htax_term_lock'] = array( 'lock_refreshed' => true );
            }
        }
    }

    // --- Relationship type lock ---
    if ( ! empty( $data['htax_rt_lock_refresh'] ) ) {
        $payload = $data['htax_rt_lock_refresh'];
        $slug    = sanitize_key( wp_unslash( $payload['slug'] ?? '' ) );
        $nonce   = sanitize_text_field( wp_unslash( $payload['nonce'] ?? '' ) );

        if ( $slug
            && wp_verify_nonce( $nonce, 'htax_lock_rt_' . $slug )
            && current_user_can( 'manage_options' )
        ) {
            $locked_by = htax_check_rt_edit_lock( $slug );
            if ( $locked_by ) {
                $response['htax_rt_lock'] = array(
                    'lock_error' => true,
                    'name'       => $locked_by->display_name,
                    'avatar'     => get_avatar_url( $locked_by->ID, array( 'size' => 64 ) ),
                );
            } else {
                htax_set_rt_edit_lock( $slug );
                $response['htax_rt_lock'] = array( 'lock_refreshed' => true );
            }
        }
    }

    return $response;
}
add_filter( 'heartbeat_received', 'htax_heartbeat_refresh_lock', 10, 2 );

// ============================================================================
// SHARED HELPERS
// ============================================================================

/**
 * Enqueue the lock JS and pass configuration data to the client.
 *
 * @since 1.0.0
 * @param array $config Script configuration. {
 *     @type int|null    $term_id   Term ID (for term locks).
 *     @type string|null $rt_slug   Relationship type slug (for RT locks).
 *     @type string      $nonce     Per-item nonce.
 *     @type array|false $locked    Locking user data (name, avatar, take_over_url, back_url),
 *                                  or false if current user holds the lock.
 *     @type string      $list_url  URL of the relevant list screen.
 * }
 */
function htax_enqueue_lock_script( array $config ): void {
    wp_enqueue_script(
        'htax-term-edit-lock',
        HTAX_PLUGIN_URL . 'admin/js/htax-term-edit-lock.js',
        array( 'jquery', 'heartbeat' ),
        HTAX_VERSION,
        true
    );
    $defaults = array(
        'term_id'  => null,
        'rt_slug'  => null,
        'locked'   => false,
        'list_url' => admin_url( 'edit-tags.php' ),
        'i18n'     => array(
            'taken_over_suffix'  => __( 'has taken over editing this term.', 'hypertaxonomy' ),
            'taken_over_changes' => __( 'Your unsaved changes have been lost.', 'hypertaxonomy' ),
            'go_to_list'         => __( 'Go to list', 'hypertaxonomy' ),
        ),
    );
    // Merge i18n sub-arrays separately so callers can override individual strings.
    $merged = array_merge( $defaults, $config );
    if ( isset( $config['i18n'] ) ) {
        $merged['i18n'] = array_merge( $defaults['i18n'], $config['i18n'] );
    }
    wp_localize_script( 'htax-term-edit-lock', 'HTAXLock', $merged );
}

/**
 * Render the lock dialog overlay.
 *
 * Always outputs both message sections: the "currently being edited" section
 * (shown at page load when locked, hidden otherwise) and the "taken over"
 * section (always hidden at page load, shown by JS on heartbeat displacement).
 *
 * @since 1.0.0
 * @param WP_User|false $locked_by Locking user, or false if current user has the lock.
 * @param string        $back_url  URL for the "Go Back" / "Go to list" button.
 * @param string        $take_url  Nonce URL that forcibly takes the lock.
 */
function htax_render_lock_dialog( $locked_by, string $back_url, string $take_url, string $kind = 'term' ): void {
    $is_rt = ( 'relationship_type' === $kind );
    ?>
    <div id="htax-term-lock-dialog" class="notification-dialog-wrap"<?php if ( ! $locked_by ) : ?> style="display:none"<?php endif; ?>>
        <div class="notification-dialog-background"></div>
        <div class="notification-dialog htax-lock-dialog">

            <div id="htax-lock-message-editing"<?php if ( ! $locked_by ) : ?> style="display:none"<?php endif; ?>>
                <h2 class="htax-lock-heading"><?php
                    echo $is_rt
                        ? esc_html__( 'This relationship type is currently being edited', 'hypertaxonomy' )
                        : esc_html__( 'This term is currently being edited', 'hypertaxonomy' );
                ?></h2>
                <div class="htax-lock-user">
                    <?php if ( $locked_by ) : ?>
                        <?php echo get_avatar( $locked_by->ID, 64 ); ?>
                        <div class="htax-lock-user-info">
                            <p><?php
                                $editor_name = '<strong>' . esc_html( $locked_by->display_name ) . '</strong>';
                                /* translators: %s: editor display name */
                                printf(
                                    $is_rt
                                        ? esc_html__( '%s is currently editing this relationship type, which means you cannot make changes unless you take over.', 'hypertaxonomy' )
                                        : esc_html__( '%s is currently editing this term, which means you cannot make changes unless you take over.', 'hypertaxonomy' ),
                                    $editor_name
                                ); ?></p>
                            <p><?php esc_html_e( 'If you take over, the other editor will lose access. Any unsaved changes they have will be lost.', 'hypertaxonomy' ); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="htax-lock-buttons">
                    <a class="htax-lock-link" href="<?php echo esc_url( $take_url ); ?>"><?php esc_html_e( 'Take over', 'hypertaxonomy' ); ?></a>
                    <a class="button button-primary" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Go back', 'hypertaxonomy' ); ?></a>
                </div>
            </div>

            <div id="htax-lock-message-taken" style="display:none">
                <h2 class="htax-lock-heading"><?php esc_html_e( 'Someone else has taken over', 'hypertaxonomy' ); ?></h2>
                <div class="htax-lock-user">
                    <img id="htax-lock-taken-avatar" src="" alt="" width="64" height="64" />
                    <div class="htax-lock-user-info">
                        <p><strong id="htax-lock-taken-name"></strong> <span id="htax-lock-taken-text"></span></p>
                        <p id="htax-lock-taken-secondary"></p>
                    </div>
                </div>
                <div class="htax-lock-buttons">
                    <a class="button button-primary" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Go to list', 'hypertaxonomy' ); ?></a>
                </div>
            </div>

        </div>
    </div>
    <?php
}

// ============================================================================
// TERM EDIT SCREEN — LOAD HOOK
// ============================================================================

/**
 * Set up term edit locking when a term edit screen loads.
 *
 * Fires on load-edit-tags.php and load-term.php. Skips the add-term screen
 * (no tag_ID). Handles the take-over redirect before any output.
 *
 * @since 1.0.0
 */
function htax_term_lock_on_load(): void {
    $options = HyperTaxonomy_Settings::get_instance()->get_options();
    if ( empty( $options['edit_lock_enabled'] ) ) {
        return;
    }

    $term_id = isset( $_GET['tag_ID'] ) ? absint( wp_unslash( $_GET['tag_ID'] ) ) : 0;
    if ( ! $term_id ) {
        return; // Add screen, not edit screen.
    }

    // Handle take-over request (GET param set by the "Take Over" button).
    if ( ! empty( $_GET['htax-take-lock'] ) ) {
        check_admin_referer( 'htax_take_lock_term_' . $term_id );
        htax_set_term_edit_lock( $term_id );
        wp_safe_redirect( remove_query_arg( array( 'htax-take-lock', '_wpnonce' ) ) );
        exit;
    }

    $locked_by = htax_check_term_edit_lock( $term_id );
    if ( ! $locked_by ) {
        htax_set_term_edit_lock( $term_id );
    }

    $term     = get_term( $term_id );
    $taxonomy = ( $term && ! is_wp_error( $term ) ) ? $term->taxonomy : '';
    $list_url = $taxonomy
        ? add_query_arg( 'taxonomy', $taxonomy, admin_url( 'edit-tags.php' ) )
        : admin_url( 'edit-tags.php' );

    // Preserve any filters the user had active before navigating to this screen.
    $referer  = isset( $_GET['wp_http_referer'] )
        ? wp_validate_redirect( wp_unslash( $_GET['wp_http_referer'] ), false )
        : false;
    $back_url = $referer ?: $list_url;

    $take_url = wp_nonce_url(
        add_query_arg( 'htax-take-lock', '1' ),
        'htax_take_lock_term_' . $term_id
    );

    $locked_data = $locked_by ? array(
        'name'          => $locked_by->display_name,
        'avatar'        => get_avatar_url( $locked_by->ID, array( 'size' => 64 ) ),
        'take_over_url' => $take_url,
        'back_url'      => $back_url,
    ) : false;

    add_action( 'admin_enqueue_scripts', function () use ( $term_id, $locked_data, $back_url ) {
        htax_enqueue_lock_script( array(
            'term_id'  => $term_id,
            'nonce'    => wp_create_nonce( 'htax_lock_term_' . $term_id ),
            'locked'   => $locked_data,
            'list_url' => $back_url,
        ) );
    } );

    add_action( 'admin_footer', function () use ( $locked_by, $back_url, $take_url ) {
        htax_render_lock_dialog( $locked_by, $back_url, $take_url, 'term' );
    } );
}
add_action( 'load-edit-tags.php', 'htax_term_lock_on_load' );
add_action( 'load-term.php', 'htax_term_lock_on_load' );

// ============================================================================
// RELATIONSHIP TYPE EDIT PAGE — ADMIN INIT HOOK
// ============================================================================

/**
 * Set up relationship type edit locking during admin_init.
 *
 * Fires early enough to enqueue scripts and issue the take-over redirect
 * before any output. Only acts when on the htax-term-relationships page
 * with a valid ?edit= slug.
 *
 * @since 1.0.0
 */
function htax_rt_lock_on_admin_init(): void {
    $options = HyperTaxonomy_Settings::get_instance()->get_options();
    if ( empty( $options['edit_lock_enabled'] ) ) {
        return;
    }

    if ( ! is_admin() ) {
        return;
    }
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'htax-term-relationships' !== $page ) {
        return;
    }
    $slug = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
    if ( ! $slug ) {
        return;
    }
    $custom_types = get_option( HTAX_CUSTOM_REL_TYPES_OPTION, array() );
    if ( ! isset( $custom_types[ $slug ] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $list_url = admin_url( 'admin.php?page=htax-term-relationships' );
    $take_url = wp_nonce_url(
        add_query_arg( 'htax-rt-take-lock', '1' ),
        'htax_take_lock_rt_' . $slug
    );

    // Handle take-over request.
    if ( ! empty( $_GET['htax-rt-take-lock'] ) ) {
        check_admin_referer( 'htax_take_lock_rt_' . $slug );
        htax_set_rt_edit_lock( $slug );
        wp_safe_redirect( remove_query_arg( array( 'htax-rt-take-lock', '_wpnonce' ) ) );
        exit;
    }

    $locked_by = htax_check_rt_edit_lock( $slug );
    if ( ! $locked_by ) {
        htax_set_rt_edit_lock( $slug );
    }

    $locked_data = $locked_by ? array(
        'name'          => $locked_by->display_name,
        'avatar'        => get_avatar_url( $locked_by->ID, array( 'size' => 64 ) ),
        'take_over_url' => $take_url,
        'back_url'      => $list_url,
    ) : false;

    add_action( 'admin_enqueue_scripts', function () use ( $slug, $locked_data, $list_url ) {
        htax_enqueue_lock_script( array(
            'rt_slug'  => $slug,
            'nonce'    => wp_create_nonce( 'htax_lock_rt_' . $slug ),
            'locked'   => $locked_data,
            'list_url' => $list_url,
            'i18n'     => array(
                'taken_over_suffix' => __( 'has taken over editing this relationship type.', 'hypertaxonomy' ),
            ),
        ) );
    } );

    add_action( 'admin_footer', function () use ( $locked_by, $list_url, $take_url ) {
        htax_render_lock_dialog( $locked_by, $list_url, $take_url, 'relationship_type' );
    } );
}
add_action( 'admin_init', 'htax_rt_lock_on_admin_init' );
