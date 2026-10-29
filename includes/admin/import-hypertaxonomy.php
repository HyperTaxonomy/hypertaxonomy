<?php
/**
 * HyperTaxonomy Import Handler
 *
 * Imports term groups, relationships, and metadata from JSON format
 * exported by htax_generate_export_data().
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validate import data structure
 *
 * @param array $data Import data to validate
 * @return true|WP_Error True if valid, WP_Error if invalid
 */
function htax_validate_import_data( $data ) {
    // Check required fields
    if ( ! isset( $data['version'] ) ) {
        return new WP_Error( 'invalid_format', __( 'Missing version field', 'hypertaxonomy' ) );
    }

    if ( ! isset( $data['terms'] ) || ! is_array( $data['terms'] ) ) {
        return new WP_Error( 'invalid_format', __( 'Missing or invalid terms array', 'hypertaxonomy' ) );
    }

    if ( ! isset( $data['relationships'] ) || ! is_array( $data['relationships'] ) ) {
        return new WP_Error( 'invalid_format', __( 'Missing or invalid relationships array', 'hypertaxonomy' ) );
    }

    // Validate version compatibility
    if ( ! is_scalar( $data['version'] ) ) {
        return new WP_Error( 'invalid_format', __( 'Invalid version format.', 'hypertaxonomy' ) );
    }

    $max_version = '1.0';
    if ( version_compare( (string) $data['version'], $max_version, '>' ) ) {
        return new WP_Error(
            'incompatible_version',
            sprintf(
                /* translators: 1: export file version, 2: highest supported version */
                __( 'Export file version %s is not compatible with this importer (supports up to %s)', 'hypertaxonomy' ),
                $data['version'],
                $max_version
            )
        );
    }

    return true;
}

/**
 * Preview import data
 *
 * Analyzes import file and returns summary of what will be imported
 *
 * @param array $data Import data
 * @return array Preview summary
 */
function htax_preview_import( $data ) {
    $validation = htax_validate_import_data( $data );
    if ( is_wp_error( $validation ) ) {
        return array(
            'valid' => false,
            'error' => $validation->get_error_message(),
        );
    }

    // Collect slugs per taxonomy in a single pass, then batch-fetch to avoid N+1
    $slugs_by_tax = array();
    foreach ( $data['terms'] as $term_data ) {
        if ( ! isset( $term_data['taxonomy'], $term_data['slug'] ) ) {
            continue;
        }
        $slugs_by_tax[ $term_data['taxonomy'] ][] = $term_data['slug'];
    }

    $existing_slugs_by_tax = array();
    foreach ( $slugs_by_tax as $tax => $slugs ) {
        $found = get_terms( array(
            'taxonomy'   => $tax,
            'slug'       => $slugs,
            'hide_empty' => false,
            'fields'     => 'slugs',
        ) );
        $existing_slugs_by_tax[ $tax ] = is_wp_error( $found ) ? array() : array_flip( $found );
    }

    // Count items by taxonomy using the prefetched slug map
    $taxonomies = array();
    foreach ( $data['terms'] as $term_data ) {
        if ( ! isset( $term_data['taxonomy'], $term_data['slug'] ) ) {
            continue;
        }
        $tax = $term_data['taxonomy'];
        if ( ! isset( $taxonomies[ $tax ] ) ) {
            $taxonomies[ $tax ] = array(
                'total'    => 0,
                'existing' => 0,
                'new'      => 0,
            );
        }

        $taxonomies[ $tax ]['total']++;

        if ( isset( $existing_slugs_by_tax[ $tax ][ $term_data['slug'] ] ) ) {
            $taxonomies[ $tax ]['existing']++;
        } else {
            $taxonomies[ $tax ]['new']++;
        }
    }

    // Count relationships by type
    $relationship_types = array();
    foreach ( $data['relationships'] as $rel_data ) {
        if ( ! isset( $rel_data['type'] ) ) {
            continue;
        }
        $type = $rel_data['type'];
        if ( ! isset( $relationship_types[ $type ] ) ) {
            $relationship_types[ $type ] = 0;
        }
        $relationship_types[ $type ]++;
    }

    return array(
        'valid'              => true,
        'exported_from'      => isset( $data['site_url'] ) ? $data['site_url'] : __( 'Unknown', 'hypertaxonomy' ),
        'exported_at'        => isset( $data['exported_at'] ) ? $data['exported_at'] : __( 'Unknown', 'hypertaxonomy' ),
        'total_terms'        => count( $data['terms'] ),
        'taxonomies'         => $taxonomies,
        'total_relationships' => count( $data['relationships'] ),
        'relationship_types' => $relationship_types,
        'has_term_posts'     => isset( $data['term_post_assignments'] ) && ! empty( $data['term_post_assignments'] ),
    );
}

/**
 * Process import
 *
 * @param array $data Import data
 * @param array $options Import options
 * @return array Import results
 */
function htax_process_import( $data, $options = array() ) {
    global $wpdb;

    // Default options
    $defaults = array(
        'update_existing' => true,
        'import_term_posts' => false,
    );
    $options = wp_parse_args( $options, $defaults );

    // Validate data
    $validation = htax_validate_import_data( $data );
    if ( is_wp_error( $validation ) ) {
        return array(
            'success' => false,
            'error'   => $validation->get_error_message(),
        );
    }

    // Statistics
    $stats = array(
        'terms_created'       => 0,
        'terms_updated'       => 0,
        'terms_skipped'       => 0,
        'relationships_created' => 0,
        'relationships_skipped' => 0,
        'posts_assigned'      => 0,
        'posts_skipped'       => 0,
        'errors'              => array(),
    );

    // Term ID mapping (slug+taxonomy => term_id)
    $term_map = array();

    // Import terms
    foreach ( $data['terms'] as $term_data ) {
        if ( ! isset( $term_data['taxonomy'], $term_data['slug'], $term_data['name'] ) ) {
            $stats['terms_skipped']++;
            continue;
        }
        $taxonomy = $term_data['taxonomy'];
        $slug = $term_data['slug'];

        // Check if taxonomy exists
        if ( ! taxonomy_exists( $taxonomy ) ) {
            $stats['errors'][] = sprintf(
                __( 'Taxonomy "%s" does not exist, skipping term "%s"', 'hypertaxonomy' ),
                $taxonomy,
                $slug
            );
            $stats['terms_skipped']++;
            continue;
        }

        // Check if term exists
        $existing_term = get_term_by( 'slug', $slug, $taxonomy );

        if ( $existing_term ) {
            // Update existing term
            if ( $options['update_existing'] ) {
                $args = array(
                    'name'        => $term_data['name'],
                    'description' => isset( $term_data['description'] ) ? $term_data['description'] : '',
                );

                // Handle parent
                if ( isset( $term_data['parent_slug'] ) && $term_data['parent_slug'] ) {
                    $parent_term = get_term_by( 'slug', $term_data['parent_slug'], $taxonomy );
                    if ( $parent_term ) {
                        $args['parent'] = $parent_term->term_id;
                    }
                } else {
                    $args['parent'] = 0;
                }

                $result = wp_update_term( $existing_term->term_id, $taxonomy, $args );

                if ( is_wp_error( $result ) ) {
                    $stats['errors'][] = sprintf(
                        __( 'Error updating term "%s" in %s: %s', 'hypertaxonomy' ),
                        $slug,
                        $taxonomy,
                        $result->get_error_message()
                    );
                    $stats['terms_skipped']++;
                    continue;
                }

                $term_id = $existing_term->term_id;
                $stats['terms_updated']++;
            } else {
                $term_id = $existing_term->term_id;
                $stats['terms_skipped']++;
            }
        } else {
            // Create new term
            $args = array(
                'slug'        => $slug,
                'description' => isset( $term_data['description'] ) ? $term_data['description'] : '',
            );

            // Handle parent
            if ( isset( $term_data['parent_slug'] ) && $term_data['parent_slug'] ) {
                $parent_term = get_term_by( 'slug', $term_data['parent_slug'], $taxonomy );
                if ( $parent_term ) {
                    $args['parent'] = $parent_term->term_id;
                }
            }

            $result = wp_insert_term( $term_data['name'], $taxonomy, $args );

            if ( is_wp_error( $result ) ) {
                $stats['errors'][] = sprintf(
                    __( 'Error creating term "%s" in %s: %s', 'hypertaxonomy' ),
                    $slug,
                    $taxonomy,
                    $result->get_error_message()
                );
                $stats['terms_skipped']++;
                continue;
            }

            $term_id = $result['term_id'];
            $stats['terms_created']++;
        }

        // Store term ID mapping
        $term_map[ $taxonomy . ':' . $slug ] = $term_id;

        // Import metadata
        if ( isset( $term_data['meta'] ) && is_array( $term_data['meta'] ) ) {
            foreach ( $term_data['meta'] as $meta_key => $meta_value ) {
                // Special handling for icon URL
                if ( '_htax_icon_url' === $meta_key ) {
                    $icon_id = htax_import_icon_from_url( $meta_value );
                    if ( $icon_id ) {
                        update_term_meta( $term_id, '_htax_icon_id', $icon_id );
                    }
                    continue;
                }

                // Special handling for sticky posts (slugs to IDs).
                // Each sticky assignment is stored as its own postmeta row:
                // _htax_sticky_{term_id} = 1 on each affected post.
                if ( '_htax_sticky_posts' === $meta_key && is_array( $meta_value ) ) {
                    foreach ( $meta_value as $post_slug ) {
                        $post = get_page_by_path( $post_slug, OBJECT, htax_get_indexed_post_types() );
                        if ( ! $post ) {
                            continue;
                        }
                        update_post_meta( $post->ID, htax_get_sticky_assignment_key( (int) $term_id ), 1 );
                    }
                    continue;
                }

                // Import regular metadata (only known plugin-owned keys)
                $allowed_meta_keys = array(
                    '_htax_is_hidden',
                    '_htax_show_description',
                    '_htax_posts_per_page',
                    '_htax_max_sticky_posts',
                    '_htax_sort_order',
                    '_htax_placeholder_color',
                    '_htax_auto_redirect',
                );
                if ( ! in_array( $meta_key, $allowed_meta_keys, true ) ) {
                    continue;
                }

                // Clamp numeric meta to the same range enforced by the normal save path.
                if ( in_array( $meta_key, array( '_htax_posts_per_page', '_htax_max_sticky_posts' ), true ) ) {
                    $meta_value = max( 1, min( 100, intval( $meta_value ) ) );
                }

                update_term_meta( $term_id, $meta_key, $meta_value );
            }
        }
    }

    // Register any custom relationship types from the export that are not yet known locally.
    // Skips core types and types already registered.
    if ( ! empty( $data['relationship_types'] ) && is_array( $data['relationship_types'] ) ) {
        foreach ( $data['relationship_types'] as $slug => $type_args ) {
            if ( ! is_string( $slug ) || ! is_array( $type_args ) ) {
                continue;
            }
            if ( htax_get_relationship_type( $slug ) ) {
                continue; // Already registered (built-in or previously imported).
            }
            $result = htax_set_custom_relationship_type( $slug, $type_args );
            if ( is_wp_error( $result ) ) {
                $stats['errors'][] = sprintf(
                    __( 'Could not recreate relationship type "%s": %s', 'hypertaxonomy' ),
                    $slug,
                    $result->get_error_message()
                );
            }
        }
    }

    // Import relationships
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    foreach ( $data['relationships'] as $rel_data ) {
        if ( ! isset( $rel_data['type'], $rel_data['source_taxonomy'], $rel_data['source_slug'], $rel_data['target_taxonomy'], $rel_data['target_slug'] ) ) {
            $stats['relationships_skipped']++;
            continue;
        }

        // Verify the relationship type is registered before inserting.
        if ( ! htax_get_relationship_type( $rel_data['type'] ) ) {
            $stats['relationships_skipped']++;
            continue;
        }

        // Both taxonomies must exist on this site before writing to the database.
        if ( ! taxonomy_exists( $rel_data['source_taxonomy'] ) ) {
            $stats['errors'][] = sprintf(
                __( 'Source taxonomy "%s" does not exist, skipping relationship: %s → %s', 'hypertaxonomy' ),
                $rel_data['source_taxonomy'],
                $rel_data['source_slug'],
                $rel_data['target_slug']
            );
            $stats['relationships_skipped']++;
            continue;
        }

        if ( ! taxonomy_exists( $rel_data['target_taxonomy'] ) ) {
            $stats['errors'][] = sprintf(
                __( 'Target taxonomy "%s" does not exist, skipping relationship: %s → %s', 'hypertaxonomy' ),
                $rel_data['target_taxonomy'],
                $rel_data['source_slug'],
                $rel_data['target_slug']
            );
            $stats['relationships_skipped']++;
            continue;
        }

        $source_key = $rel_data['source_taxonomy'] . ':' . $rel_data['source_slug'];
        $target_key = $rel_data['target_taxonomy'] . ':' . $rel_data['target_slug'];

        // Resolve source term: from import batch or fall back to DB lookup.
        if ( ! isset( $term_map[ $source_key ] ) ) {
            $existing = get_term_by( 'slug', $rel_data['source_slug'], $rel_data['source_taxonomy'] );
            if ( $existing ) {
                $term_map[ $source_key ] = $existing->term_id;
            } else {
                $stats['errors'][] = sprintf(
                    /* translators: 1: source term slug, 2: target term slug */
                    __( 'Source term not found, skipping relationship: %s → %s', 'hypertaxonomy' ),
                    $rel_data['source_slug'],
                    $rel_data['target_slug']
                );
                $stats['relationships_skipped']++;
                continue;
            }
        }

        // Resolve target term: from import batch or fall back to DB lookup.
        if ( ! isset( $term_map[ $target_key ] ) ) {
            $existing = get_term_by( 'slug', $rel_data['target_slug'], $rel_data['target_taxonomy'] );
            if ( $existing ) {
                $term_map[ $target_key ] = $existing->term_id;
            } else {
                $stats['errors'][] = sprintf(
                    /* translators: 1: source term slug, 2: target term slug */
                    __( 'Target term not found, skipping relationship: %s → %s', 'hypertaxonomy' ),
                    $rel_data['source_slug'],
                    $rel_data['target_slug']
                );
                $stats['relationships_skipped']++;
                continue;
            }
        }

        $source_id = $term_map[ $source_key ];
        $target_id = $term_map[ $target_key ];

        // Check if relationship already exists
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name}
            WHERE source_term_id = %d
            AND target_term_id = %d
            AND relationship_type = %s
            AND source_taxonomy = %s
            AND target_taxonomy = %s",
            $source_id,
            $target_id,
            $rel_data['type'],
            $rel_data['source_taxonomy'],
            $rel_data['target_taxonomy']
        ) );

        if ( $exists ) {
            $stats['relationships_skipped']++;
            continue;
        }

        // Insert relationship
        $result = $wpdb->insert(
            $table_name,
            array(
                'source_term_id'    => $source_id,
                'target_term_id'    => $target_id,
                'relationship_type' => $rel_data['type'],
                'source_taxonomy'   => $rel_data['source_taxonomy'],
                'target_taxonomy'   => $rel_data['target_taxonomy'],
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            $stats['errors'][] = sprintf(
                __( 'Error creating relationship from %s to %s: %s', 'hypertaxonomy' ),
                $rel_data['source_slug'],
                $rel_data['target_slug'],
                $wpdb->last_error
            );
            $stats['relationships_skipped']++;
        } else {
            $stats['relationships_created']++;
        }
    }

    // Import term-post assignments (if requested)
    if ( $options['import_term_posts'] && isset( $data['term_post_assignments'] ) ) {
        foreach ( $data['term_post_assignments'] as $assignment ) {
            if ( ! isset( $assignment['taxonomy'], $assignment['term_slug'], $assignment['post_slugs'] ) ) {
                continue;
            }

            $term_key = $assignment['taxonomy'] . ':' . $assignment['term_slug'];

            if ( ! isset( $term_map[ $term_key ] ) ) {
                continue;
            }

            $term_id = $term_map[ $term_key ];

            foreach ( $assignment['post_slugs'] as $post_slug ) {
                $post = get_page_by_path( $post_slug, OBJECT, htax_get_indexed_post_types() );

                if ( $post ) {
                    $result = wp_set_object_terms( $post->ID, $term_id, $assignment['taxonomy'], true );

                    if ( ! is_wp_error( $result ) ) {
                        $stats['posts_assigned']++;
                    } else {
                        $stats['posts_skipped']++;
                    }
                } else {
                    $stats['posts_skipped']++;
                }
            }
        }
    }

    // Clear caches
    htax_clear_all_caches();

    return array(
        'success' => true,
        'stats'   => $stats,
    );
}

/**
 * Import icon from URL
 *
 * Attempts to find existing attachment by filename, or returns 0 if not found
 *
 * @param string $url Icon URL from export
 * @return int Attachment ID or 0 if not found
 */
function htax_import_icon_from_url( $url ) {
    // Extract filename from URL
    $filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );

    if ( ! $filename ) {
        return 0;
    }

    // Try to find attachment by filename
    global $wpdb;
    $attachment_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
        WHERE meta_key = '_wp_attached_file'
        AND meta_value LIKE %s
        LIMIT 1",
        '%' . $wpdb->esc_like( $filename )
    ) );

    return $attachment_id ? (int) $attachment_id : 0;
}

/**
 * Process import with HTML output
 *
 * Renders the HyperTaxonomy JSON import progress and results
 *
 * @param array $data Import data
 * @param array $options Import options
 */
function htax_process_import_with_output( $data, $options = array() ) {
    // Disable time limit for large imports
    set_time_limit( 0 );

    echo '<h3 style="margin-top: 0;">' . esc_html__( 'Import Progress', 'hypertaxonomy' ) . '</h3>';

    // Validate data
    $validation = htax_validate_import_data( $data );
    if ( is_wp_error( $validation ) ) {
        echo '<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px; margin: 15px 0;">';
        echo '<p style="margin: 0;"><strong>' . esc_html__( 'Error:', 'hypertaxonomy' ) . '</strong> ';
        echo esc_html( $validation->get_error_message() ) . '</p></div>';
        return;
    }

    echo '<p>' . sprintf(
        esc_html__( 'Importing from: %s', 'hypertaxonomy' ),
        esc_html( isset( $data['site_url'] ) ? $data['site_url'] : __( 'Unknown', 'hypertaxonomy' ) )
    ) . '</p>';

    echo '<p>' . sprintf(
        esc_html__( 'Found %d terms and %d relationships to process...', 'hypertaxonomy' ),
        count( $data['terms'] ),
        count( $data['relationships'] )
    ) . '</p>';

    // Process the import
    $result = htax_process_import( $data, $options );

    if ( ! $result['success'] ) {
        echo '<div style="background: #f8d7da; border-left: 4px solid #d63638; padding: 12px; margin: 15px 0;">';
        echo '<p style="margin: 0;"><strong>' . esc_html__( 'Error:', 'hypertaxonomy' ) . '</strong> ';
        echo esc_html( $result['error'] ) . '</p></div>';
        return;
    }

    $stats = $result['stats'];

    // Show errors if any
    if ( ! empty( $stats['errors'] ) ) {
        echo '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0; max-height: 200px; overflow-y: auto;">';
        echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__( 'Warnings:', 'hypertaxonomy' ) . '</strong></p>';
        echo '<ul style="margin: 0; padding-left: 20px; font-size: 12px;">';
        foreach ( $stats['errors'] as $error ) {
            echo '<li>' . esc_html( $error ) . '</li>';
        }
        echo '</ul></div>';
    }

    // Show final statistics
    echo '<div style="background: #d1f0d1; border-left: 4px solid #46b450; padding: 12px; margin: 15px 0;">';
    echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__( 'Import Complete!', 'hypertaxonomy' ) . '</strong></p>';
    echo '<table style="margin: 0; border-collapse: collapse;">';

    echo '<tr><td style="padding: 2px 10px 2px 0;"><strong>' . esc_html__( 'Terms Created:', 'hypertaxonomy' ) . '</strong></td><td>' . (int) $stats['terms_created'] . '</td></tr>';
    echo '<tr><td style="padding: 2px 10px 2px 0;"><strong>' . esc_html__( 'Terms Updated:', 'hypertaxonomy' ) . '</strong></td><td>' . (int) $stats['terms_updated'] . '</td></tr>';
    echo '<tr><td style="padding: 2px 10px 2px 0;"><strong>' . esc_html__( 'Terms Skipped:', 'hypertaxonomy' ) . '</strong></td><td>' . (int) $stats['terms_skipped'] . '</td></tr>';
    echo '<tr><td style="padding: 2px 10px 2px 0;"><strong>' . esc_html__( 'Relationships Created:', 'hypertaxonomy' ) . '</strong></td><td>' . (int) $stats['relationships_created'] . '</td></tr>';
    echo '<tr><td style="padding: 2px 10px 2px 0;"><strong>' . esc_html__( 'Relationships Skipped:', 'hypertaxonomy' ) . '</strong></td><td>' . (int) $stats['relationships_skipped'] . '</td></tr>';

    if ( isset( $stats['posts_assigned'] ) && $stats['posts_assigned'] > 0 ) {
        echo '<tr><td style="padding: 2px 10px 2px 0;"><strong>' . esc_html__( 'Posts Assigned:', 'hypertaxonomy' ) . '</strong></td><td>' . (int) $stats['posts_assigned'] . '</td></tr>';
    }

    if ( count( $stats['errors'] ) > 0 ) {
        echo '<tr><td style="padding: 2px 10px 2px 0; color: #856404;"><strong>' . esc_html__( 'Warnings:', 'hypertaxonomy' ) . '</strong></td><td style="color: #856404;">' . count( $stats['errors'] ) . '</td></tr>';
    }

    echo '</table>';
    echo '</div>';
}
