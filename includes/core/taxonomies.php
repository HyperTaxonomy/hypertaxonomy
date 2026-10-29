<?php
/**
 * Taxonomy Registration
 *
 * Registers custom taxonomies used by HyperTaxonomy.
 *
 * @package HyperTaxonomy
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the Term Groups taxonomy
 *
 * This taxonomy is used to organize terms from other taxonomies (tags, categories, custom)
 * into hierarchical groups. Unlike standard taxonomies, this one applies to terms rather
 * than posts, using wp_term_relationships with term IDs in the object_id column.
 *
 * @since 1.0
 */
function htax_register_group_taxonomy() {
    $labels = array(
        'name'                       => _x( 'Term Groups', 'taxonomy general name', 'hypertaxonomy' ),
        'singular_name'              => _x( 'Term Group', 'taxonomy singular name', 'hypertaxonomy' ),
        'search_items'               => __( 'Search Term Groups', 'hypertaxonomy' ),
        'popular_items'              => __( 'Popular Term Groups', 'hypertaxonomy' ),
        'all_items'                  => __( 'All Term Groups', 'hypertaxonomy' ),
        'parent_item'                => __( 'Parent Term Group', 'hypertaxonomy' ),
        'parent_item_colon'          => __( 'Parent Term Group:', 'hypertaxonomy' ),
        'edit_item'                  => __( 'Edit Term Group', 'hypertaxonomy' ),
        'view_item'                  => __( 'View Term Group', 'hypertaxonomy' ),
        'update_item'                => __( 'Update Term Group', 'hypertaxonomy' ),
        'add_new_item'               => __( 'Add New Term Group', 'hypertaxonomy' ),
        'new_item_name'              => __( 'New Term Group Name', 'hypertaxonomy' ),
        'separate_items_with_commas' => __( 'Separate term groups with commas', 'hypertaxonomy' ),
        'add_or_remove_items'        => __( 'Add or remove term groups', 'hypertaxonomy' ),
        'choose_from_most_used'      => __( 'Choose from the most used term groups', 'hypertaxonomy' ),
        'not_found'                  => __( 'No term groups found.', 'hypertaxonomy' ),
        'no_terms'                   => __( 'No term groups', 'hypertaxonomy' ),
        'menu_name'                  => __( 'Term Groups', 'hypertaxonomy' ),
        'items_list_navigation'      => __( 'Term Groups list navigation', 'hypertaxonomy' ),
        'items_list'                 => __( 'Term Groups list', 'hypertaxonomy' ),
        'most_used'                  => _x( 'Most Used', 'term groups', 'hypertaxonomy' ),
        'back_to_items'              => __( '&larr; Back to Term Groups', 'hypertaxonomy' ),
    );

    $args = array(
        'labels'                => $labels,
        'description'           => __( 'Hierarchical groups for organizing terms from tags, categories, and custom taxonomies.', 'hypertaxonomy' ),
        'public'                => true,
        'publicly_queryable'    => true,
        'hierarchical'          => true,
        'show_ui'               => true,
        'show_in_menu'          => false,
        'show_in_nav_menus'     => false,
        'show_in_rest'          => true,
        'rest_base'             => 'groups',
        'rest_controller_class' => 'WP_REST_Terms_Controller',
        'show_tagcloud'         => false,
        'show_in_quick_edit'    => false,
        'show_admin_column'     => false,
        'meta_box_cb'           => false, // Prevent automatic meta box on post edit screens
        'capabilities'          => array(
            'manage_terms' => 'manage_categories',
            'edit_terms'   => 'manage_categories',
            'delete_terms' => 'manage_categories',
            'assign_terms' => 'edit_posts',
        ),
        'rewrite'               => array(
            'slug'         => 'group',
            'with_front'   => false,
            'hierarchical' => true,
        ),
        'query_var'             => 'group',
        'update_count_callback' => 'htax_update_group_term_count',
    );

    // Register taxonomy with no object types - we manage term-to-term relationships manually
    register_taxonomy( 'group', array(), $args );
}
add_action( 'init', 'htax_register_group_taxonomy', 0 );

/**
 * Custom term count callback for group taxonomy
 *
 * Counts terms assigned to each group rather than posts.
 * WordPress default counts posts, but groups organize terms via term meta.
 *
 * @since 1.0
 *
 * @param int[]  $terms    Array of term taxonomy IDs.
 * @param object $taxonomy Taxonomy object. Required by WordPress callback signature but not used.
 */
function htax_update_group_term_count( $terms, $taxonomy ) {
    global $wpdb;

    foreach ( (array) $terms as $term_taxonomy_id ) {
        // Get the term_id from term_taxonomy_id
        $group_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                $term_taxonomy_id
            )
        );

        if ( ! $group_id ) {
            continue;
        }

        // Count terms across all taxonomies that belong to this group using member_of relationships
        $table_name = $wpdb->prefix . 'htax_term_relationships';
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT source_term_id)
                FROM {$table_name}
                WHERE target_term_id = %d
                AND target_taxonomy = 'group'
                AND relationship_type = 'member_of'",
                $group_id
            )
        );

        do_action( 'edit_term_taxonomy', $term_taxonomy_id, $taxonomy->name );
        $wpdb->update( $wpdb->term_taxonomy, array( 'count' => $count ), array( 'term_taxonomy_id' => $term_taxonomy_id ) );
        do_action( 'edited_term_taxonomy', $term_taxonomy_id, $taxonomy->name );
    }
}
