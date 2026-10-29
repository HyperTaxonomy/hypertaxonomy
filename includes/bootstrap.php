<?php
/**
 * HyperTaxonomy Bootstrap
 * Handles all file includes in proper dependency order
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// CORE FUNCTIONALITY (Load First - No Dependencies)
// ============================================================================

require_once HTAX_PLUGIN_DIR . 'includes/core/functions.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/helpers.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/relationship-types.php';      // Registry — must load before term-relationships.php
require_once HTAX_PLUGIN_DIR . 'includes/core/relationship-types-rest.php'; // REST: htax/v1/relationship-types, htax/v1/term/{id}/relationships
require_once HTAX_PLUGIN_DIR . 'includes/core/post-editor-rest.php';        // REST: htax/v1/suggested-tags, htax/v1/term-meta (editor-only, require edit_posts)
require_once HTAX_PLUGIN_DIR . 'includes/core/term-visibility.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/cache.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/search.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/search-state.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/strength-models.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/taxonomies.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/group-functions.php';

// term-relationships.php provides functions used by classes
// Must load before class-search-index.php
require_once HTAX_PLUGIN_DIR . 'includes/core/term-relationships.php';

// Term Groups core - included_groups and container_groups functionality
require_once HTAX_PLUGIN_DIR . 'includes/core/term-groups.php';

// Term taxonomy conversion handling
require_once HTAX_PLUGIN_DIR . 'includes/core/term-conversion.php';

// Term merge
require_once HTAX_PLUGIN_DIR . 'includes/core/term-merge.php';

// ============================================================================
// INFRASTRUCTURE CLASSES (Load Early)
// ============================================================================

// class-capabilities.php auto-initializes and is used by other classes
// Must load before class-search-index.php
if ( file_exists( HTAX_PLUGIN_DIR . 'includes/classes/class-capabilities.php' ) ) {
    require_once HTAX_PLUGIN_DIR . 'includes/classes/class-capabilities.php';
}

// Hierarchical checklist component
if ( file_exists( HTAX_PLUGIN_DIR . 'includes/classes/class-hierarchical-checklist.php' ) ) {
    require_once HTAX_PLUGIN_DIR . 'includes/classes/class-hierarchical-checklist.php';
}

// ============================================================================
// INFRASTRUCTURE CLASSES (Continued)
// ============================================================================

// class-search-index.php uses functions from term-relationships.php and class-capabilities.php
// Provides htax_create_search_term_index_table() used by class-activator.php
if ( file_exists( HTAX_PLUGIN_DIR . 'includes/classes/class-search-index.php' ) ) {
    require_once HTAX_PLUGIN_DIR . 'includes/classes/class-search-index.php';
}
if ( file_exists( HTAX_PLUGIN_DIR . 'includes/classes/class-activator.php' ) ) {
    require_once HTAX_PLUGIN_DIR . 'includes/classes/class-activator.php';
}
if ( file_exists( HTAX_PLUGIN_DIR . 'includes/classes/class-upgrader.php' ) ) {
    require_once HTAX_PLUGIN_DIR . 'includes/classes/class-upgrader.php';
}

// ============================================================================
// ADDITIONAL CLASSES
// ============================================================================

// Advanced taxonomy search widget (independent from PowerSearch)
if ( file_exists( HTAX_PLUGIN_DIR . 'includes/classes/class-search-widget.php' ) ) {
    require_once HTAX_PLUGIN_DIR . 'includes/classes/class-search-widget.php';
}

// PowerSearch widget with integrated search execution
if ( file_exists( HTAX_PLUGIN_DIR . 'includes/classes/class-power-search.php' ) ) {
    require_once HTAX_PLUGIN_DIR . 'includes/classes/class-power-search.php';
}

// ============================================================================
// CORE FEATURES (Depend on Functions & Classes)
// ============================================================================

require_once HTAX_PLUGIN_DIR . 'includes/core/term-archives.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/integration-api.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/rest-api.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/frontend.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/native-blocks.php';
require_once HTAX_PLUGIN_DIR . 'includes/core/activation.php';

// Loaded unconditionally: registers htax_sticky_terms REST field used by the
// Gutenberg sidebar panel (REST API runs outside is_admin()).
require_once HTAX_PLUGIN_DIR . 'includes/admin/post-edit.php';
// Term icon class: provides HyperTaxonomy_Term_Icons on frontend (icon retrieval,
// placeholder colors) and admin (upload UI, crop, image size registration).
require_once HTAX_PLUGIN_DIR . 'includes/admin/term-icons.php';

// ============================================================================
// ADMIN FUNCTIONALITY (Only Load in Admin or AJAX)
// ============================================================================

if ( is_admin() ) {
    require_once HTAX_PLUGIN_DIR . 'includes/admin/admin-menu.php';                  // Top-level Terms menu + submenus
    require_once HTAX_PLUGIN_DIR . 'includes/admin/relationship-types-admin.php';   // Term Relationships page + form handlers
    require_once HTAX_PLUGIN_DIR . 'includes/admin/term-edit-lock.php';             // Heartbeat-based edit locking for terms and relationship types
    require_once HTAX_PLUGIN_DIR . 'includes/admin/settings.php';      // Settings page UI (Export / Import tab includes its subtab files on demand)
    require_once HTAX_PLUGIN_DIR . 'includes/admin/import-tag-groups-handler.php'; // Tag Groups mapping helpers + re-run-overwrite guards (needed on every term/group save, not just the importer's own AJAX requests)
    require_once HTAX_PLUGIN_DIR . 'includes/admin/tag-groups-live-sync.php'; // Continuous Tag Groups → HT sync, active only when enabled on the Sync subtab
    require_once HTAX_PLUGIN_DIR . 'includes/admin/term-edit.php';     // Term edit screen: assets, form fields, save handlers
    require_once HTAX_PLUGIN_DIR . 'includes/admin/tables.php';        // List tables: assets, columns, filter configs, row classes
    require_once HTAX_PLUGIN_DIR . 'includes/admin/term-filters.php';  // Backend filtering logic for admin tables
    require_once HTAX_PLUGIN_DIR . 'includes/admin/bulk-actions.php';  // Bulk actions for term list tables
    require_once HTAX_PLUGIN_DIR . 'includes/admin/group-admin.php';   // Term Groups admin UI
    require_once HTAX_PLUGIN_DIR . 'includes/admin/merge-terms.php';   // Merge Terms admin page
    require_once HTAX_PLUGIN_DIR . 'includes/admin/convert-terms.php'; // Convert Terms admin page
}

// AJAX handlers need to be loaded for both admin and frontend AJAX requests
if ( is_admin() || wp_doing_ajax() ) {
    require_once HTAX_PLUGIN_DIR . 'includes/admin/ajax.php';                    // AJAX handlers
}

// ============================================================================
// SHORTCODES (Load After Core Functions)
// ============================================================================

// Base class for all shortcodes
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-shortcode-base.php';

// Intermediary base class for term display shortcodes (term-list, term-cloud, term-index)
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-term-display-base.php';

// Individual shortcode implementations
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-term-list.php';
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-term-cloud.php';
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-term-groups.php';
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-term-index.php';

// Sort order picker component: [ht_sort_order_options] shortcode + htax_render_sort_order_picker()
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-sort-order.php';

// Filter options shortcodes: [ht_filter_options] and children; also registers
// htax_apply_archive_filter() on pre_get_posts and provides htax_build_filter_sql().
// Must load before class-post-list.php so htax_build_filter_sql() is available.
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-filter-options.php';

// Post list shortcodes: [ht_post_list] and [ht_related_posts]
require_once HTAX_PLUGIN_DIR . 'includes/shortcodes/class-post-list.php';

// Gutenberg block registration — must load after shortcodes (render callbacks depend on them).
require_once HTAX_PLUGIN_DIR . 'includes/classes/class-blocks.php';

// ============================================================================
// INTEGRATIONS (Optional third-party plugin integrations)
// ============================================================================

require_once HTAX_PLUGIN_DIR . 'includes/integrations/class-atcf-integration.php';
require_once HTAX_PLUGIN_DIR . 'includes/integrations/class-taxopress-integration.php';

// ============================================================================
// HOOKS (Load Last - After All Functions Are Defined)
// ============================================================================

require_once HTAX_PLUGIN_DIR . 'includes/hooks/actions.php';
require_once HTAX_PLUGIN_DIR . 'includes/hooks/filters.php';
