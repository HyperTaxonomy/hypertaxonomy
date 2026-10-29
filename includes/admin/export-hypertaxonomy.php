<?php
/**
 * HyperTaxonomy Export Handler
 *
 * Exports term groups, relationships, and metadata to JSON format
 * for migration between WordPress instances.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generate export data for HyperTaxonomy
 *
 * @param array $taxonomies Array of taxonomy names to export (empty = all)
 * @param bool $include_term_posts Whether to include term-to-post assignments
 * @return array Export data structure
 */
function htax_generate_export_data( $taxonomies = array(), $include_term_posts = false ) {
    global $wpdb;

    // If no taxonomies specified, get all public taxonomies
    if ( empty( $taxonomies ) ) {
        $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
    }

    // Initialize export structure
    $export_data = array(
        'version'            => '1.0',
        'exported_at'        => current_time( 'mysql' ),
        'site_url'           => get_site_url(),
        'terms'              => array(),
        'relationships'      => array(),
        'relationship_types' => array(),
    );

    if ( $include_term_posts ) {
        $export_data['term_post_assignments'] = array();
    }

    // Metadata keys to export
    $meta_keys = array(
        '_htax_is_hidden',
        '_htax_show_description',
        '_htax_posts_per_page',
        '_htax_max_sticky_posts',
        '_htax_sort_order',
        '_htax_placeholder_color',
        '_htax_icon_id',
        '_htax_auto_redirect',
    );

    $all_terms = array();

    // Export terms from each taxonomy
    foreach ( $taxonomies as $taxonomy ) {
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            continue;
        }

        // Batch-fetch all parent terms to avoid N+1 queries on hierarchical taxonomies
        $parent_terms = array();
        $parent_ids   = array_filter( array_unique( wp_list_pluck( $terms, 'parent' ) ) );
        if ( ! empty( $parent_ids ) ) {
            $fetched_parents = get_terms( array(
                'taxonomy'   => $taxonomy,
                'include'    => $parent_ids,
                'hide_empty' => false,
            ) );
            if ( ! is_wp_error( $fetched_parents ) ) {
                foreach ( $fetched_parents as $parent ) {
                    $parent_terms[ $parent->term_id ] = $parent;
                }
            }
        }

        $term_ids = wp_list_pluck( $terms, 'term_id' );

        // Prime the WP meta cache for all terms in one query so the per-term
        // get_term_meta() calls inside the loop are cache hits.
        update_meta_cache( 'term', $term_ids );

        // Pre-build a term_id → [post_slugs] map with a single JOIN query so
        // we avoid one get_posts() call per term when exporting assignments.
        $posts_by_term = array();
        if ( $include_term_posts ) {
            $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
            $rows         = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tt.term_id, p.post_name
                       FROM {$wpdb->term_relationships} tr
                       JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                       JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                      WHERE tt.taxonomy = %s
                        AND tt.term_id IN (" . $placeholders . ")
                        AND p.post_status NOT IN ('auto-draft','trash')",
                    array_merge( array( $taxonomy ), $term_ids )
                )
            );
            foreach ( $rows as $row ) {
                $posts_by_term[ (int) $row->term_id ][] = $row->post_name;
            }
        }

        foreach ( $terms as $term ) {
            // Build term data
            $term_data = array(
                'slug'        => $term->slug,
                'taxonomy'    => $taxonomy,
                'name'        => $term->name,
                'description' => $term->description,
                'parent_slug' => null,
                'meta'        => array(),
            );

            // Get parent slug if exists
            if ( $term->parent && isset( $parent_terms[ $term->parent ] ) ) {
                $term_data['parent_slug'] = $parent_terms[ $term->parent ]->slug;
            }

            // Export metadata (get_term_meta() hits the WP object cache primed above)
            foreach ( $meta_keys as $meta_key ) {
                $meta_value = get_term_meta( $term->term_id, $meta_key, true );

                if ( '' !== $meta_value && false !== $meta_value ) {
                    // Special handling for icon
                    if ( '_htax_icon_id' === $meta_key && $meta_value ) {
                        $icon_url = wp_get_attachment_url( $meta_value );
                        if ( $icon_url ) {
                            $term_data['meta']['_htax_icon_url'] = $icon_url;
                        }
                    } else {
                        $term_data['meta'][ $meta_key ] = $meta_value;
                    }
                }
            }

            // Get sticky posts and convert to slugs
            $sticky_posts = htax_get_sticky_posts_for_term( $term->term_id );
            if ( ! empty( $sticky_posts ) ) {
                $term_data['meta']['_htax_sticky_posts'] = wp_list_pluck( $sticky_posts, 'post_name' );
            }

            $export_data['terms'][] = $term_data;

            // Store for relationship lookup
            $all_terms[ $term->term_id ] = array(
                'slug'     => $term->slug,
                'taxonomy' => $taxonomy,
            );

            // Use the pre-built map instead of per-term get_posts() queries.
            if ( $include_term_posts ) {
                $post_slugs = $posts_by_term[ $term->term_id ] ?? array();
                if ( ! empty( $post_slugs ) ) {
                    $export_data['term_post_assignments'][] = array(
                        'term_slug'  => $term->slug,
                        'taxonomy'   => $taxonomy,
                        'post_slugs' => $post_slugs,
                    );
                }
            }
        }
    }

    // Export relationships
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Check if table exists
    if ( $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) ) {
        $relationships = $wpdb->get_results(
            "SELECT source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy
            FROM {$table_name}
            ORDER BY source_term_id, relationship_type"
        );

        if ( ! empty( $relationships ) ) {
            foreach ( $relationships as $rel ) {
                // Only export if we have both source and target terms
                if ( isset( $all_terms[ $rel->source_term_id ] ) && isset( $all_terms[ $rel->target_term_id ] ) ) {
                    $export_data['relationships'][] = array(
                        'source_slug'     => $all_terms[ $rel->source_term_id ]['slug'],
                        'source_taxonomy' => $rel->source_taxonomy,
                        'target_slug'     => $all_terms[ $rel->target_term_id ]['slug'],
                        'target_taxonomy' => $rel->target_taxonomy,
                        'type'            => $rel->relationship_type,
                    );
                }
            }
        }
    }

    // Export user-defined relationship type definitions so they can be recreated on import.
    // Core types are not exported — they are always present after plugin activation.
    $all_types = htax_get_registered_relationship_types();
    foreach ( $all_types as $slug => $args ) {
        if ( ! empty( $args['is_core'] ) ) {
            continue;
        }
        $export_data['relationship_types'][ $slug ] = array(
            'label'             => $args['label'],
            'inverse_label'     => $args['inverse_label'],
            'core_type'         => $args['core_type'],
            'show_in_ui'        => $args['show_in_ui'],
            'source_taxonomies' => $args['source_taxonomies'],
            'target_taxonomies' => $args['target_taxonomies'],
        );
    }

    return $export_data;
}

/**
 * Download export file
 *
 * @param array $taxonomies Array of taxonomy names to export
 * @param bool $include_term_posts Whether to include term-to-post assignments
 */
function htax_download_export( $taxonomies = array(), $include_term_posts = false ) {
    // Generate export data
    $export_data = htax_generate_export_data( $taxonomies, $include_term_posts );

    // Generate filename
    $filename = 'hypertaxonomy-export-' . gmdate( 'Y-m-d-H-i-s' ) . '.json';

    // Discard any buffered output (e.g. BOM bytes from included PHP files) before sending headers.
    while ( ob_get_level() ) {
        ob_end_clean();
    }

    // Set headers for download
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    // Output JSON
    echo wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    exit;
}
