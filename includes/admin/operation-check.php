<?php
/**
 * Check step for the Merge Terms and Convert Terms pages.
 *
 * Before such a form is submitted, htax-operation-check.js sends it to the page's check
 * (an AJAX action), where other plugins can say what the operation will do to their data
 * and offer choices. With nothing to show, the form is submitted straight away; otherwise
 * a second step shows the notices and choices, with the redirect stub setting, which can
 * still be changed there. The choices are submitted as htax_operation_options[section][field].
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue the check step on a page.
 *
 * @since 1.0.0
 * @param string $action      AJAX action of the page's check.
 * @param string $submit_name Name of the form's submit button, which the page's handler checks.
 * @param string $after       Script handle of the page's own form script, whose submit
 *                            checks must run first.
 */
function htax_enqueue_operation_check( string $action, string $submit_name, string $after ): void {
    wp_enqueue_style( 'htax-operation-check', HTAX_PLUGIN_URL . 'admin/css/htax-operation-check.css', array(), HTAX_VERSION );
    wp_enqueue_script( 'htax-operation-check', HTAX_PLUGIN_URL . 'admin/js/htax-operation-check.js', array( 'jquery', 'wp-i18n', $after ), HTAX_VERSION, true );
    wp_set_script_translations( 'htax-operation-check', 'hypertaxonomy', HTAX_PLUGIN_DIR . 'languages' );
    wp_localize_script( 'htax-operation-check', 'htaxOperationCheck', array(
        'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
        'action'     => $action,
        'nonce'      => wp_create_nonce( $action ),
        'submitName' => $submit_name,
    ) );
}

/**
 * Print the check step's container, inside the page's form. The page wraps its own fields
 * in #htax-check-step1 and its submit button in #htax-check-step1-submit, and names its
 * redirect stub checkbox's ID htax_create_redirect.
 *
 * @since 1.0.0
 * @param string $stub_label    Label of the redirect stub setting.
 * @param string $confirm_label Label of the button that runs the operation.
 */
function htax_render_operation_check_step( string $stub_label, string $confirm_label ): void {
    ?>
    <div id="htax-check-step2" class="htax-check-step2" hidden>
        <h2><?php esc_html_e( 'Before you continue', 'hypertaxonomy' ); ?></h2>
        <p><?php esc_html_e( 'Other plugins keep data connected to these terms. Review how it may be affected.', 'hypertaxonomy' ); ?></p>
        <p>
            <label>
                <input type="checkbox" id="htax-check-stub" />
                <?php echo esc_html( $stub_label ); ?>
            </label>
        </p>
        <div id="htax-check-sections"></div>
        <p class="submit">
            <button type="button" class="button" id="htax-check-back"><?php esc_html_e( 'Back', 'hypertaxonomy' ); ?></button>
            <button type="submit" class="button button-primary" id="htax-check-confirm"><?php echo esc_html( $confirm_label ); ?></button>
        </p>
    </div>
    <?php
}

/**
 * Shape the choices submitted from the check step: keyed by section ID, then field name.
 * Keys and values are slugs; anything else is dropped.
 *
 * @since 1.0.0
 * @param mixed $raw Submitted value, already sanitized with sanitize_key().
 * @return array<string, array<string, string>>
 */
function htax_shape_operation_options( $raw ): array {
    $raw     = is_array( $raw ) ? $raw : array();
    $options = array();
    foreach ( $raw as $section => $fields ) {
        if ( ! is_array( $fields ) ) {
            continue;
        }
        foreach ( $fields as $field => $value ) {
            if ( is_scalar( $value ) ) {
                $options[ sanitize_key( $section ) ][ sanitize_key( $field ) ] = sanitize_key( (string) $value );
            }
        }
    }
    return $options;
}

/**
 * Send a check's sections, as returned by its filter, keeping only well-formed ones.
 *
 * @since 1.0.0
 * @param mixed $sections Filter result.
 */
function htax_send_operation_check( $sections ): void {
    wp_send_json_success( array( 'sections' => array_values( array_filter( (array) $sections, 'is_array' ) ) ) );
}

/**
 * Print the messages plugins return for a finished operation (plain text).
 *
 * @since 1.0.0
 * @param mixed $messages Filter result.
 */
function htax_render_operation_messages( $messages ): void {
    foreach ( (array) $messages as $message ) {
        echo '<p>' . esc_html( (string) $message ) . '</p>';
    }
}
