<?php
/**
 * Plugin Name: HyperTaxonomy
 * Plugin URI:  https://github.com/HyperTaxonomy/hypertaxonomy
 * Description: Advanced taxonomy relationships: tag & category groups, inclusions, synonyms, redirects, icons, relevance-scored search, enhanced archives & term-specific sticky posts
 * Version:     1.0.0
 * Author:      Eugene Weinstein
 * Author URI:  https://hypertaxonomy.org
 * Requires at least: 5.9
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hypertaxonomy
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'HTAX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HTAX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HTAX_VERSION', '1.0.0' );
define( 'HTAX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Performance and caching constants
define( 'HTAX_AJAX_SEARCH_LIMIT', 100 );      // Max search results returned via AJAX
define( 'HTAX_BACKTRACE_FRAME_LIMIT', 5 );    // Max backtrace frames to log in debug mode
define( 'HTAX_MAX_REDIRECT_DEPTH', 10 );      // Max redirect chain depth to prevent infinite loops
define( 'HTAX_DEFAULT_STRENGTH_MODEL', 'htax_tf' ); // Built-in TF strength model key in wp_htax_search_terms.model

// Load compatibility checks first
require_once HTAX_PLUGIN_DIR . 'includes/core/compatibility.php';

// Verify environment meets requirements before loading
if ( htax_can_load() ) {

    // Load the bootstrap file which handles all includes
    require_once HTAX_PLUGIN_DIR . 'includes/bootstrap.php';

    // Register activation and deactivation hooks after functions are loaded
    register_activation_hook( __FILE__, 'htax_activate' );
    register_deactivation_hook( __FILE__, 'htax_deactivate' );
}
