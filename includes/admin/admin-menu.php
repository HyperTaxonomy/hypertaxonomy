<?php
/**
 * Admin Menu Registration
 *
 * Registers the top-level "Terms" admin menu and its submenus.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the Terms top-level admin menu and submenus.
 *
 * The top-level slug is "htax-terms" (a clean, query-string-free slug) so WordPress
 * generates a valid CSS class for the menu item. The slug "edit-tags.php?taxonomy=group"
 * would produce the class "toplevel_page_edit-tags?taxonomy=group" which contains "?"
 * and breaks WordPress's own menu JavaScript (querySelector rejects it as a CSS selector).
 *
 * Clicking the top-level "Terms" menu item redirects to edit-tags.php?taxonomy=group.
 * All submenus are registered under "htax-terms" so WordPress auto-generates correct
 * admin.php?page=... URLs for them.
 *
 * Position 6 places Terms directly below the native Posts menu (position 5).
 *
 * @since 1.0.0
 */
function htax_register_admin_menu() {
    // Top-level "Terms" menu — clean slug avoids CSS-class issues from "?" in query strings.
    add_menu_page(
        __( 'Terms', 'hypertaxonomy' ),
        __( 'Terms', 'hypertaxonomy' ),
        'manage_options',
        'htax-terms',
        'htax_terms_menu_redirect',
        'dashicons-tag',
        6
    );

    // Term Groups — default landing page. Slug contains ".php" so WordPress uses it as the
    // direct URL (edit-tags.php?taxonomy=group) rather than wrapping it in admin.php?page=.
    add_submenu_page(
        'htax-terms',
        __( 'Term Groups', 'hypertaxonomy' ),
        __( 'Term Groups', 'hypertaxonomy' ),
        'manage_options',
        'edit-tags.php?taxonomy=group'
    );

    // Remove the auto-added duplicate "Terms" submenu entry WordPress creates.
    remove_submenu_page( 'htax-terms', 'htax-terms' );
}
add_action( 'admin_menu', 'htax_register_admin_menu' );

/**
 * Redirect the htax-terms landing page to the Term Groups taxonomy screen.
 *
 * This callback is only reached if a user navigates directly to admin.php?page=htax-terms,
 * which should not happen in normal use (the Term Groups submenu link goes straight to
 * edit-tags.php?taxonomy=group). The redirect is a safety net.
 *
 * @since 1.0.0
 */
function htax_terms_menu_redirect(): void {
    wp_safe_redirect( admin_url( 'edit-tags.php?taxonomy=group' ) );
    exit;
}

/**
 * Register the Term Relationship Types submenu at priority 15 so it appears
 * after Peer Sites (priority 13) — maintaining alphabetical order.
 *
 * @since 1.0.0
 */
function htax_register_relationship_types_submenu() {
    add_submenu_page(
        'htax-terms',
        __( 'Term Relationship Types', 'hypertaxonomy' ),
        __( 'Term Relationship Types', 'hypertaxonomy' ),
        'manage_options',
        'htax-term-relationships',
        'htax_render_term_relationships_page'
    );
}
add_action( 'admin_menu', 'htax_register_relationship_types_submenu', 15 );

/**
 * Sort all htax-terms submenus alphabetically after every plugin has registered.
 * Term Groups is always pinned first; everything else is sorted case-insensitively
 * with spaces treated as sorting after all alphanumeric characters — so "TermConnect …"
 * (no space at position 5) sorts before "Term Locations" (space at position 5).
 * Running at priority 999 ensures ATCF and TC have finished registering their items.
 */
function htax_sort_terms_submenu(): void {
    global $submenu;
    if ( empty( $submenu['htax-terms'] ) ) {
        return;
    }

    $groups_item = null;
    $rest        = array();

    foreach ( array_values( $submenu['htax-terms'] ) as $item ) {
        if ( isset( $item[2] ) && 'edit-tags.php?taxonomy=group' === $item[2] ) {
            $groups_item = $item;
        } else {
            $rest[] = $item;
        }
    }

    // Normalize spaces to '~' (ASCII 126, after all letters) so that e.g.
    // "TermConnect Peer Sites" (no space at pos 4) sorts before "Term Locations" (space at pos 4).
    $sort_key = static function ( string $label ): string {
        return strtolower( str_replace( ' ', '~', $label ) );
    };

    usort( $rest, static function ( array $a, array $b ) use ( $sort_key ): int {
        return strcmp( $sort_key( $a[0] ?? '' ), $sort_key( $b[0] ?? '' ) );
    } );

    $submenu['htax-terms'] = $groups_item
        ? array_merge( array( $groups_item ), $rest )
        : $rest;
}
add_action( 'admin_menu', 'htax_sort_terms_submenu', 999 );

add_action( 'admin_head', function () {
    echo '<style>#adminmenu a[href*="htax-term-relationships"], #adminmenu a[href*="htax-link-types"] { white-space: nowrap; }</style>';
} );

/**
 * Set the active parent menu to "Terms" when browsing Term Groups pages.
 *
 * When on edit-tags.php?taxonomy=group, $pagenow = "edit-tags.php" and WordPress
 * cannot automatically match "htax-terms" as the parent. This filter corrects the
 * highlighted state for both the term list and the edit-term screens.
 *
 * @since 1.0.0
 * @param string $parent_file Current parent file.
 * @return string
 */
function htax_terms_menu_parent_file( $parent_file ) {
    global $pagenow, $taxnow;

    if ( ( 'edit-tags.php' === $pagenow || 'term.php' === $pagenow ) && 'group' === $taxnow ) {
        return 'htax-terms';
    }

    return $parent_file;
}
add_filter( 'parent_file', 'htax_terms_menu_parent_file' );

/**
 * Set the active submenu item to "Term Groups" when browsing Term Groups pages.
 *
 * @since 1.0.0
 * @param string $submenu_file Current submenu file.
 * @return string
 */
function htax_terms_menu_submenu_file( $submenu_file ) {
    global $pagenow, $taxnow;

    if ( ( 'edit-tags.php' === $pagenow || 'term.php' === $pagenow ) && 'group' === $taxnow ) {
        return 'edit-tags.php?taxonomy=group';
    }

    return $submenu_file;
}
add_filter( 'submenu_file', 'htax_terms_menu_submenu_file' );
