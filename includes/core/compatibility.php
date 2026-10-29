<?php
/**
 * Compatibility Checks and Requirements Validation
 * Ensures environment meets plugin requirements
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check if environment meets plugin requirements
 *
 * @since 1.0.0
 * @return array|true Returns true if compatible, array of errors if not
 */
function htax_check_compatibility() {
    $errors = array();

    // Check PHP version
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        $errors[] = sprintf(
            /* translators: %s: Current PHP version */
            __( 'HyperTaxonomy requires PHP 7.4 or higher. You are running PHP %s.', 'hypertaxonomy' ),
            PHP_VERSION
        );
    }

    // Check WordPress version
    global $wp_version;
    if ( version_compare( $wp_version, '5.0', '<' ) ) {
        $errors[] = sprintf(
            /* translators: %s: Current WordPress version */
            __( 'HyperTaxonomy requires WordPress 5.0 or higher. You are running WordPress %s.', 'hypertaxonomy' ),
            $wp_version
        );
    }

    // Check required PHP extensions
    $required_extensions = array( 'json', 'mysqli' );
    foreach ( $required_extensions as $extension ) {
        if ( ! extension_loaded( $extension ) ) {
            $errors[] = sprintf(
                /* translators: %s: PHP extension name */
                __( 'HyperTaxonomy requires the PHP %s extension to be installed.', 'hypertaxonomy' ),
                $extension
            );
        }
    }

    // Check database
    global $wpdb;
    if ( ! $wpdb ) {
        $errors[] = __( 'Database connection not available.', 'hypertaxonomy' );
    } else {
        // Check termmeta table exists
        $termmeta_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->termmeta ) ) );
        if ( ! $termmeta_exists ) {
            $errors[] = __( 'WordPress term meta table does not exist. Your WordPress installation may be incomplete.', 'hypertaxonomy' );
        }
    }

    // Return errors or true
    return empty( $errors ) ? true : $errors;
}

/**
 * Display compatibility errors as admin notice
 *
 * @since 1.0.0
 */
function htax_display_compatibility_errors() {
    $compatibility = htax_check_compatibility();

    if ( true !== $compatibility ) {
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e( 'HyperTaxonomy: Compatibility Issues Detected', 'hypertaxonomy' ); ?></strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <?php foreach ( $compatibility as $error ) : ?>
                    <li><?php echo esc_html( $error ); ?></li>
                <?php endforeach; ?>
            </ul>
            <p><?php esc_html_e( 'The plugin may not function correctly until these issues are resolved.', 'hypertaxonomy' ); ?></p>
        </div>
        <?php
    }
}

/**
 * Check file structure integrity
 *
 * @since 1.0.0
 * @return array Array of missing files
 */
function htax_check_file_integrity() {
    $required_files = array(
        'includes/bootstrap.php',
        'includes/core/functions.php',
        'includes/core/cache.php',
        'includes/core/search.php',
        'includes/core/search-state.php',
        'includes/core/term-relationships.php',
        'includes/core/term-archives.php',
        'includes/core/frontend.php',
        'includes/core/activation.php',
        'includes/admin/settings.php',
        'includes/admin/term-icons.php',
        'includes/admin/term-edit.php',
        'includes/admin/post-edit.php',
        'includes/admin/tables.php',
        'includes/admin/ajax.php',
        'includes/hooks/actions.php',
        'includes/hooks/filters.php',
    );

    $missing = array();

    foreach ( $required_files as $file ) {
        if ( ! file_exists( HTAX_PLUGIN_DIR . $file ) ) {
            $missing[] = $file;
        }
    }

    return $missing;
}

/**
 * Display file integrity errors
 *
 * @since 1.0.0
 */
function htax_display_file_integrity_errors() {
    $missing = htax_check_file_integrity();

    if ( ! empty( $missing ) ) {
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e( 'HyperTaxonomy: Missing Required Files', 'hypertaxonomy' ); ?></strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <?php foreach ( $missing as $file ) : ?>
                    <li><code><?php echo esc_html( $file ); ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p><?php esc_html_e( 'Please reinstall the plugin to restore missing files.', 'hypertaxonomy' ); ?></p>
        </div>
        <?php
    }
}

/**
 * Verify plugin can load safely
 * Called before bootstrap
 *
 * @since 1.0.0
 * @return bool True if safe to load, false otherwise
 */
function htax_can_load() {
    // Check compatibility
    $compatibility = htax_check_compatibility();
    if ( true !== $compatibility ) {
        add_action( 'admin_notices', 'htax_display_compatibility_errors' );
        return false;
    }

    // Check file integrity
    $missing = htax_check_file_integrity();
    if ( ! empty( $missing ) ) {
        add_action( 'admin_notices', 'htax_display_file_integrity_errors' );
        return false;
    }

    return true;
}

