<?php
/**
 * Term Groups Admin UI
 *
 * Handles admin interface customizations for the Term Groups taxonomy.
 *
 * @package HyperTaxonomy
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sort an enabled-taxonomies array: post_tag first, category second, rest alphabetically.
 *
 * @param array $taxonomies Associative array of taxonomy_slug => label
 * @return array Sorted array
 */
function htax_sort_enabled_taxonomies( $taxonomies ) {
    if ( empty( $taxonomies ) ) {
        return $taxonomies;
    }
    $ordered = array();
    if ( isset( $taxonomies['post_tag'] ) ) {
        $ordered['post_tag'] = $taxonomies['post_tag'];
        unset( $taxonomies['post_tag'] );
    }
    if ( isset( $taxonomies['category'] ) ) {
        $ordered['category'] = $taxonomies['category'];
        unset( $taxonomies['category'] );
    }
    ksort( $taxonomies );
    return array_merge( $ordered, $taxonomies );
}

/**
 * Prevent 'group' taxonomy from being assigned to posts
 *
 * Groups are only for organizing terms, not posts. This removes the group
 * taxonomy meta box from post edit screens.
 *
 * @since 1.0
 */
function htax_prevent_group_post_assignment() {
    // Get all post types
    $post_types = get_post_types( array( 'public' => true ), 'names' );

    foreach ( $post_types as $post_type ) {
        unregister_taxonomy_for_object_type( 'group', $post_type );
    }
}
add_action( 'init', 'htax_prevent_group_post_assignment', 999 );


/**
 * Add custom columns to group list table
 *
 * @since 1.0
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function htax_group_custom_columns( $columns ) {
    // Remove default posts count column
    if ( isset( $columns['posts'] ) ) {
        unset( $columns['posts'] );
    }

    // Add custom columns
    $columns['terms_count'] = __( 'Terms', 'hypertaxonomy' );
    $columns['taxonomies']  = __( 'Taxonomies', 'hypertaxonomy' );

    return $columns;
}
add_filter( 'manage_edit-group_columns', 'htax_group_custom_columns' );

/**
 * Populate custom columns in group list table
 *
 * @since 1.0
 *
 * @param string $content     Column content (empty by default).
 * @param string $column_name Column name.
 * @param int    $term_id     Term ID.
 * @return string Column content.
 */
function htax_group_custom_column_content( $content, $column_name, $term_id ) {
    // Cache per term_id: one query serves both columns
    static $cache = array();

    if ( ! isset( $cache[ $term_id ] ) ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'htax_term_relationships';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_term_id, source_taxonomy
                FROM {$table_name}
                WHERE target_term_id = %d
                AND target_taxonomy = 'group'
                AND relationship_type = 'member_of'",
                $term_id
            )
        );

        $taxonomies = array();
        foreach ( (array) $rows as $row ) {
            $taxonomies[ $row->source_taxonomy ] = true;
        }

        $cache[ $term_id ] = array(
            'count'      => count( (array) $rows ),
            'taxonomies' => array_keys( $taxonomies ),
        );
    }

    if ( 'terms_count' === $column_name ) {
        $content = number_format_i18n( $cache[ $term_id ]['count'] );
    } elseif ( 'taxonomies' === $column_name ) {
        $taxonomies = $cache[ $term_id ]['taxonomies'];
        if ( empty( $taxonomies ) ) {
            $content = '—';
        } else {
            $tax_labels = array();
            foreach ( $taxonomies as $taxonomy ) {
                $tax_obj = get_taxonomy( $taxonomy );
                if ( $tax_obj ) {
                    $tax_labels[] = esc_html( $tax_obj->labels->name );
                }
            }
            $content = implode( ', ', $tax_labels );
        }

        // At-a-glance indicator for a Tag-Groups-mapped group, so the field
        // locks on its edit screen don't come as a surprise.
        if ( get_term_meta( $term_id, '_htax_tag_groups_source_id', true ) ) {
            $content = '<span class="dashicons dashicons-lock" aria-hidden="true" title="' .
                esc_attr__( 'Synced from Tag Groups', 'hypertaxonomy' ) . '"></span> ' . $content;
        }
    }

    return $content;
}
add_filter( 'manage_group_custom_column', 'htax_group_custom_column_content', 10, 3 );

/**
 * Render the taxonomy-looped term-pill section shared by the three group edit fields.
 *
 * @param array  $enabled_taxonomies slug => label pairs (already sorted).
 * @param array  $terms_by_taxonomy  slug => WP_Term[] of current relationship terms.
 * @param string $section_css_class  CSS class suffix for the taxonomy div (e.g. 'members').
 * @param string $data_target        data-target attribute value for the search input.
 * @param string $field_name         Base hidden-input name (e.g. 'htax_group_members').
 * @param array  $locked_taxonomies  Taxonomy slugs whose section is read-only here because
 *                                   another tool owns that data for this group. Default array().
 */
function htax_render_group_term_section( $enabled_taxonomies, $terms_by_taxonomy, $section_css_class, $data_target, $field_name, $locked_taxonomies = array() ) {
    foreach ( $enabled_taxonomies as $tax_slug => $tax_label ) :
        $tax_obj       = get_taxonomy( $tax_slug );
        $taxonomy_name = $tax_obj ? strtolower( $tax_obj->labels->singular_name ) : $tax_slug;
        $is_locked     = in_array( $tax_slug, $locked_taxonomies, true );
        ?>
        <div class="htax-taxonomy-<?php echo esc_attr( $section_css_class ); ?>-section<?php echo $is_locked ? ' htax-taxonomy-section-locked' : ''; ?>" data-taxonomy="<?php echo esc_attr( $tax_slug ); ?>" style="margin-bottom: 20px;">
            <h4 style="margin: 10px 0 8px 0; font-size: 13px; font-weight: 600;">
                <?php echo esc_html( $tax_label ); ?>
                <?php if ( $is_locked ) : ?>
                    <span class="dashicons dashicons-lock" aria-hidden="true" title="<?php esc_attr_e( 'Managed by Tag Groups — edit membership there instead.', 'hypertaxonomy' ); ?>"></span>
                <?php endif; ?>
            </h4>

            <?php if ( $is_locked ) : ?>
                <p class="description" style="margin: 0 0 8px 0;">
                    <?php esc_html_e( 'Managed by Tag Groups — edit this taxonomy\'s membership there instead. Changes made here would be reverted on the next migration.', 'hypertaxonomy' ); ?>
                </p>
            <?php endif; ?>

            <div class="htax-tag-container" data-taxonomy="<?php echo esc_attr( $tax_slug ); ?>" data-current="0">
                <?php
                if ( isset( $terms_by_taxonomy[ $tax_slug ] ) ) {
                    foreach ( $terms_by_taxonomy[ $tax_slug ] as $rel_term ) {
                        ?>
                        <span class="htax-pill<?php echo $is_locked ? ' htax-pill-locked' : ''; ?>" data-id="<?php echo esc_attr( $rel_term->term_id ); ?>" tabindex="0">
                            <?php echo esc_html( $rel_term->name ); ?>
                            <?php if ( ! $is_locked ) : ?>
                                <button type="button" class="htax-remove" aria-label="<?php esc_attr_e( 'Remove', 'hypertaxonomy' ); ?>" tabindex="-1">
                                    <span class="screen-reader-text"><?php esc_html_e( 'Remove term:', 'hypertaxonomy' ); ?></span>
                                    <span aria-hidden="true">×</span>
                                </button>
                            <?php endif; ?>
                        </span>
                        <?php
                    }
                }
                ?>

                <?php if ( ! $is_locked ) : ?>
                    <input type="text" class="htax-search"
                           id="htax-search-<?php echo esc_attr( $data_target . '-' . $tax_slug ); ?>"
                           aria-label="<?php echo esc_attr( sprintf( __( 'Add %s...', 'hypertaxonomy' ), $taxonomy_name ) ); ?>"
                           data-target="<?php echo esc_attr( $data_target ); ?>"
                           placeholder="<?php echo esc_attr( sprintf( __( 'Add %s...', 'hypertaxonomy' ), $taxonomy_name ) ); ?>" />
                <?php endif; ?>
                <input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $tax_slug ); ?>]" value="<?php
                    if ( isset( $terms_by_taxonomy[ $tax_slug ] ) ) {
                        echo esc_attr( implode( ',', wp_list_pluck( $terms_by_taxonomy[ $tax_slug ], 'term_id' ) ) );
                    }
                ?>" <?php echo $is_locked ? 'disabled' : ''; ?> />
            </div>
        </div>
        <?php
    endforeach;
}

/**
 * Notice on a Tag-Groups-mapped group's own edit screen: what deleting it
 * actually does, and that its locked fields are editable in Tag Groups instead.
 *
 * Mapped groups aren't protected from deletion — deleting one is a legitimate
 * action (e.g. cleaning up after a Tag Groups taxonomy is dropped) — this only
 * makes the consequence visible before an admin acts, rather than blocking it.
 *
 * @since 1.0.0
 * @param WP_Term $term Current taxonomy term object.
 */
function htax_group_tag_groups_mapping_notice( WP_Term $term ): void {
    if ( 'group' !== $term->taxonomy ) {
        return;
    }
    if ( ! get_term_meta( $term->term_id, '_htax_tag_groups_source_id', true ) ) {
        return;
    }
    $locked = 'locked' === htax_tag_groups_integration_mode();
    $live   = htax_tag_groups_live_sync_enabled();
    ?>
    <tr class="htax-tag-groups-mapping-notice">
        <th scope="row" valign="top">
            <label><?php esc_html_e( 'Tag Groups', 'hypertaxonomy' ); ?></label>
        </th>
        <td>
            <div class="notice notice-info inline" style="margin: 0;">
                <?php if ( $locked ) : ?>
                    <p>
                        <?php
                        if ( $live ) {
                            esc_html_e( 'This group was created via migration from Tag Groups. Per current settings, its name and member terms are kept in continuous sync with that plugin and aren\'t editable here.', 'hypertaxonomy' );
                        } else {
                            esc_html_e( 'This group was created via migration from Tag Groups. Per current settings, its name and member terms are synced with that plugin during each migration run and aren\'t editable here.', 'hypertaxonomy' );
                        }
                        ?>
                    </p>
                <?php endif; ?>
                <p>
                    <?php esc_html_e( 'Deleting this group is not blocked, but note: the next migration from Tag Groups recreates it with its Tag Groups members, and any HyperTaxonomy-only members and relationships on it (inclusion, reverse-inclusion, custom relationship types) are lost.', 'hypertaxonomy' ); ?>
                </p>
            </div>
        </td>
    </tr>
    <?php
}
add_action( 'group_edit_form_fields', 'htax_group_tag_groups_mapping_notice', 5 );

/**
 * Add term members field to group edit form
 * Shows pills of all terms directly assigned to this group, organized by taxonomy
 *
 * @since 1.0
 *
 * @param WP_Term $term Current taxonomy term object.
 */
function htax_add_terms_in_group_field( $term ) {
    if ( 'group' !== $term->taxonomy ) {
        return;
    }

    // Get all term IDs assigned to this group using member_of relationships
    global $wpdb;
    $relationships_table = $wpdb->prefix . 'htax_term_relationships';

    $term_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT source_term_id FROM {$relationships_table}
        WHERE target_term_id = %d
        AND target_taxonomy = 'group'
        AND relationship_type = 'member_of'",
        $term->term_id
    ) );

    // Group terms by taxonomy
    $terms_by_taxonomy = array();

    if ( ! empty( $term_ids ) ) {
        $fetched_terms = get_terms( array(
            'include'    => $term_ids,
            'hide_empty' => false,
        ) );
        if ( ! is_wp_error( $fetched_terms ) ) {
            foreach ( $fetched_terms as $term_obj ) {
                $taxonomy = $term_obj->taxonomy;

                // Only show taxonomies that have group assignment feature enabled
                if ( htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
                    if ( ! isset( $terms_by_taxonomy[ $taxonomy ] ) ) {
                        $terms_by_taxonomy[ $taxonomy ] = array();
                    }
                    $terms_by_taxonomy[ $taxonomy ][] = $term_obj;
                }
            }
        }
    }

    // Get all taxonomies with group assignment enabled for the autocomplete
    $enabled_taxonomies = array();
    $all_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

    foreach ( $all_taxonomies as $tax_slug => $tax_obj ) {
        if ( 'group' !== $tax_slug && htax_taxonomy_has_feature( $tax_slug, 'group_assignment' ) ) {
            $enabled_taxonomies[ $tax_slug ] = $tax_obj->labels->name;
        }
    }

    // Sort taxonomies: post_tag first, category second, then custom taxonomies alphabetically
    $enabled_taxonomies = htax_sort_enabled_taxonomies( $enabled_taxonomies );

    // Taxonomies Tag Groups owns this group's membership data for — a
    // mirror-mode re-run reasserts these, so membership here is read-only.
    $locked_taxonomies = array();
    if ( function_exists( 'htax_tag_groups_taxonomy_is_managed' )
        && get_term_meta( $term->term_id, '_htax_tag_groups_source_id', true )
    ) {
        foreach ( array_keys( $enabled_taxonomies ) as $tax_slug ) {
            if ( htax_tag_groups_taxonomy_is_managed( $tax_slug ) ) {
                $locked_taxonomies[] = $tax_slug;
            }
        }
    }

    ?>
    <tr class="form-field htax-group-members-field">
        <th scope="row" valign="top">
            <label><?php esc_html_e( 'Group Members', 'hypertaxonomy' ); ?></label>
        </th>
        <td>
            <p class="description" style="margin-top: 0;">
                <?php esc_html_e( 'Terms directly assigned to this group. To add a term, type its name below and select it from the suggestions.', 'hypertaxonomy' ); ?>
            </p>

            <?php htax_render_group_term_section( $enabled_taxonomies, $terms_by_taxonomy, 'members', 'group-members', 'htax_group_members', $locked_taxonomies ); ?>

            <?php if ( empty( $enabled_taxonomies ) ) : ?>
                <p class="description">
                    <?php esc_html_e( 'No taxonomies have group assignment enabled. Enable group assignment in Settings → HyperTaxonomy.', 'hypertaxonomy' ); ?>
                </p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}
add_action( 'group_edit_form_fields', 'htax_add_terms_in_group_field', 16 );

/**
 * Add 'includes this group' field to group edit form
 * Shows terms that include this group
 * (where source=TermA, target=GroupX, type='includes', target_taxonomy='group')
 *
 * @since 1.0
 *
 * @param WP_Term $term Current taxonomy term object.
 */
function htax_add_terms_with_included_group_field( $term ) {
    if ( 'group' !== $term->taxonomy ) {
        return;
    }

    // Get all terms that include this group
    // These are relationships where source=TermA, target=GroupX, type='includes', target_taxonomy='group'
    global $wpdb;
    $relationships_table = $wpdb->prefix . 'htax_term_relationships';

    $includes_in = htax_relationship_type_sql_in( 'includes' );
    $term_ids    = $wpdb->get_col( $wpdb->prepare(
        "SELECT source_term_id FROM {$relationships_table}
        WHERE target_term_id = %d
        AND target_taxonomy = 'group'
        AND {$includes_in}",
        $term->term_id
    ) );

    // Group terms by taxonomy
    $terms_by_taxonomy = array();

    if ( ! empty( $term_ids ) ) {
        $fetched_terms = get_terms( array(
            'include'    => $term_ids,
            'hide_empty' => false,
        ) );
        if ( ! is_wp_error( $fetched_terms ) ) {
            foreach ( $fetched_terms as $term_obj ) {
                $taxonomy = $term_obj->taxonomy;

                // Only show enabled taxonomies
                if ( htax_is_taxonomy_enabled( $taxonomy ) && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
                    if ( ! isset( $terms_by_taxonomy[ $taxonomy ] ) ) {
                        $terms_by_taxonomy[ $taxonomy ] = array();
                    }
                    $terms_by_taxonomy[ $taxonomy ][] = $term_obj;
                }
            }
        }
    }

    // Get all enabled taxonomies with inclusion feature
    $enabled_taxonomies = array();
    $all_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

    foreach ( $all_taxonomies as $tax_slug => $tax_obj ) {
        if ( 'group' !== $tax_slug && htax_is_taxonomy_enabled( $tax_slug ) && htax_taxonomy_has_feature( $tax_slug, 'term_relationships' ) ) {
            $enabled_taxonomies[ $tax_slug ] = $tax_obj->labels->name;
        }
    }

    // Sort taxonomies: post_tag first, category second, then custom taxonomies alphabetically
    $enabled_taxonomies = htax_sort_enabled_taxonomies( $enabled_taxonomies );

    ?>
    <tr class="form-field htax-terms-with-included-group-field">
        <th scope="row" valign="top">
            <label><?php esc_html_e( 'Included By', 'hypertaxonomy' ); ?></label>
        </th>
        <td>
            <p class="description" style="margin-top: 0;">
                <?php esc_html_e( 'Terms whose archives include posts from all same-taxonomy group members. Adding a term here adds this group to that term\'s included groups.', 'hypertaxonomy' ); ?>
            </p>

            <?php htax_render_group_term_section( $enabled_taxonomies, $terms_by_taxonomy, 'terms-with-included-group', 'terms-with-included-group', 'htax_terms_with_included_group' ); ?>
        </td>
    </tr>
    <?php
}
add_action( 'group_edit_form_fields', 'htax_add_terms_with_included_group_field', 18 );

/**
 * Add container group members field to group edit form
 * Shows terms that have this group set as their container group
 * (where source=GroupX, target=TermB, type='includes', source_taxonomy='group')
 *
 * @since 1.0
 *
 * @param WP_Term $term Current taxonomy term object.
 */
function htax_add_terms_with_container_group_field( $term ) {
    if ( 'group' !== $term->taxonomy ) {
        return;
    }

    // Get all terms where this group is set as container
    // These are relationships where source=GroupX, target=TermB, type='includes', source_taxonomy='group'
    global $wpdb;
    $relationships_table = $wpdb->prefix . 'htax_term_relationships';

    $includes_in = htax_relationship_type_sql_in( 'includes' );
    $term_ids    = $wpdb->get_col( $wpdb->prepare(
        "SELECT target_term_id FROM {$relationships_table}
        WHERE source_term_id = %d
        AND source_taxonomy = 'group'
        AND {$includes_in}
        AND target_taxonomy != 'group'",
        $term->term_id
    ) );

    // Group terms by taxonomy
    $terms_by_taxonomy = array();

    if ( ! empty( $term_ids ) ) {
        $fetched_terms = get_terms( array(
            'include'    => $term_ids,
            'hide_empty' => false,
        ) );
        if ( ! is_wp_error( $fetched_terms ) ) {
            foreach ( $fetched_terms as $term_obj ) {
                $taxonomy = $term_obj->taxonomy;

                // Only show enabled taxonomies
                if ( htax_is_taxonomy_enabled( $taxonomy ) ) {
                    if ( ! isset( $terms_by_taxonomy[ $taxonomy ] ) ) {
                        $terms_by_taxonomy[ $taxonomy ] = array();
                    }
                    $terms_by_taxonomy[ $taxonomy ][] = $term_obj;
                }
            }
        }
    }

    // Get all enabled taxonomies for autocomplete
    $enabled_taxonomies = array();
    $all_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

    foreach ( $all_taxonomies as $tax_slug => $tax_obj ) {
        if ( 'group' !== $tax_slug && htax_is_taxonomy_enabled( $tax_slug ) ) {
            $enabled_taxonomies[ $tax_slug ] = $tax_obj->labels->name;
        }
    }

    // Sort taxonomies: post_tag first, category second, then custom taxonomies alphabetically
    $enabled_taxonomies = htax_sort_enabled_taxonomies( $enabled_taxonomies );

    ?>
    <tr class="form-field htax-group-container-members-field">
        <th scope="row" valign="top">
            <label><?php esc_html_e( 'Container For', 'hypertaxonomy' ); ?></label>
        </th>
        <td>
            <p class="description" style="margin-top: 0;">
                <?php esc_html_e( 'Terms whose posts appear in the archives of all same-taxonomy group members. Adding a term here sets this group as its container group.', 'hypertaxonomy' ); ?>
            </p>

            <?php htax_render_group_term_section( $enabled_taxonomies, $terms_by_taxonomy, 'container', 'container-members', 'htax_container_members' ); ?>
        </td>
    </tr>
    <?php
}
add_action( 'group_edit_form_fields', 'htax_add_terms_with_container_group_field', 17 );

/**
 * Batch-fetch taxonomy slugs for a list of term IDs.
 *
 * Returns an array keyed by term ID with the taxonomy slug as the value.
 * Terms that do not exist are omitted.
 *
 * @since 1.0
 *
 * @param int[] $term_ids List of term IDs to look up.
 * @return array<int,string> Map of term_id => taxonomy.
 */
function htax_get_taxonomy_map_for_terms( $term_ids ) {
    if ( empty( $term_ids ) ) {
        return array();
    }

    $fetched = get_terms( array(
        'include'    => array_values( $term_ids ),
        'hide_empty' => false,
    ) );

    if ( is_wp_error( $fetched ) ) {
        return array();
    }

    $map = array();
    foreach ( $fetched as $term ) {
        $map[ $term->term_id ] = $term->taxonomy;
    }
    return $map;
}

/**
 * Save term members for a group
 *
 * @since 1.0
 *
 * @param int $term_id Term ID.
 */
function htax_save_terms_in_group( $term_id ) {
    if ( ! isset( $_POST['htax_group_members'] ) || ! is_array( $_POST['htax_group_members'] ) ) {
        return;
    }

    global $wpdb;
    $relationships_table = $wpdb->prefix . 'htax_term_relationships';

    // Get all current term IDs in this group using member_of relationships
    $current_term_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT source_term_id FROM {$relationships_table}
        WHERE target_term_id = %d
        AND target_taxonomy = 'group'
        AND relationship_type = 'member_of'",
        $term_id
    ) );

    // If Tag Groups has written membership data for one of this group's
    // taxonomies, a mirror-mode re-run would reassert it for that taxonomy —
    // don't let this save silently get overwritten later by discarding it
    // here instead. Locked per taxonomy, not per group: one group can hold
    // members across several taxonomies at once, and only some may be
    // Tag-Groups-managed.
    $locked_taxonomies    = array();
    $current_taxonomy_map = array();
    if ( get_term_meta( $term_id, '_htax_tag_groups_source_id', true ) ) {
        $current_taxonomy_map = htax_get_taxonomy_map_for_terms( $current_term_ids );
        foreach ( $current_taxonomy_map as $taxonomy ) {
            if ( htax_tag_groups_taxonomy_is_managed( $taxonomy ) ) {
                $locked_taxonomies[ $taxonomy ] = true;
            }
        }
    }

    $new_term_ids = array();

    // Process each taxonomy's member list
    foreach ( $_POST['htax_group_members'] as $taxonomy => $term_ids_string ) {
        $taxonomy = sanitize_key( $taxonomy );

        // Skip if group assignment not enabled for this taxonomy
        if ( ! htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
            continue;
        }

        // Tag Groups owns this taxonomy's membership in this group — keep its
        // current members regardless of what was submitted (handled below).
        if ( isset( $locked_taxonomies[ $taxonomy ] ) ) {
            continue;
        }

        if ( ! empty( $term_ids_string ) ) {
            $term_ids = array_map( 'intval', explode( ',', $term_ids_string ) );
            $new_term_ids = array_merge( $new_term_ids, $term_ids );
        }
    }

    // Carry forward the current members of every locked taxonomy unchanged —
    // the loop above never submitted a replacement for them.
    foreach ( $current_term_ids as $current_term_id ) {
        $member_taxonomy = isset( $current_taxonomy_map[ $current_term_id ] ) ? $current_taxonomy_map[ $current_term_id ] : null;
        if ( null !== $member_taxonomy && isset( $locked_taxonomies[ $member_taxonomy ] ) ) {
            $new_term_ids[] = $current_term_id;
        }
    }

    // Remove terms that are no longer in the group
    $terms_to_remove = array_values( array_diff( $current_term_ids, $new_term_ids ) );
    if ( ! empty( $terms_to_remove ) ) {
        $placeholders = htax_sql_placeholders( count( $terms_to_remove ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$relationships_table}`
                WHERE source_term_id IN ({$placeholders})
                AND target_term_id = %d
                AND relationship_type = 'member_of'",
                array_merge( $terms_to_remove, array( $term_id ) )
            )
        );
    }

    // Add new terms
    $terms_to_add      = array_diff( $new_term_ids, $current_term_ids );
    $term_taxonomy_map = htax_get_taxonomy_map_for_terms( $terms_to_add );

    foreach ( $terms_to_add as $term_to_add ) {
        if ( ! isset( $term_taxonomy_map[ $term_to_add ] ) ) {
            continue;
        }

        // Insert the member_of relationship
        $wpdb->replace(
            $relationships_table,
            array(
                'source_term_id'    => $term_to_add,
                'target_term_id'    => $term_id,
                'relationship_type' => 'member_of',
                'source_taxonomy'   => $term_taxonomy_map[ $term_to_add ],
                'target_taxonomy'   => 'group',
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );
    }

    if ( ! empty( $terms_to_add ) || ! empty( $terms_to_remove ) ) {
        // Invalidate group member caches for this group across all taxonomies.
        // htax_get_terms_in_groups() caches per-group-per-taxonomy; stale here means
        // group expansion returns wrong members in inclusions and custom rel lookups.
        htax_delete_transients_by_prefix( 'htax_grp_' . (int) $term_id . '_' );

        // Clear each affected member term's own group-membership and inclusion caches.
        foreach ( array_unique( array_merge( $terms_to_add, $terms_to_remove ) ) as $changed_term ) {
            htax_reload_term_caches_on_edit( (int) $changed_term );
        }

        // Invalidate caches for terms that include this group (or its ancestors/descendants),
        // covering both inclusion and custom relationship type caches.
        htax_invalidate_group_relationship_caches( array( (int) $term_id ) );

        // Fire for search-index and other listeners.
        do_action( 'htax_term_groups_changed', $term_id, 'group', array(), array() );
    }
}
add_action( 'edited_group', 'htax_save_terms_in_group' );

/**
 * Save container members for a group
 *
 * @since 1.0
 *
 * @param int $term_id Group term ID.
 */
function htax_save_terms_with_container_group( $term_id ) {
    if ( ! isset( $_POST['htax_container_members'] ) || ! is_array( $_POST['htax_container_members'] ) ) {
        return;
    }

    global $wpdb;
    $relationships_table = $wpdb->prefix . 'htax_term_relationships';

    // Get all current container relationships for this group
    // (source=GroupX, target=TermB, type='includes', source_taxonomy='group')
    $includes_in      = htax_relationship_type_sql_in( 'includes' );
    $current_term_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT target_term_id FROM {$relationships_table}
        WHERE source_term_id = %d
        AND source_taxonomy = 'group'
        AND {$includes_in}
        AND target_taxonomy != 'group'",
        $term_id
    ) );

    $new_term_ids = array();

    // Process each taxonomy's container member list
    foreach ( $_POST['htax_container_members'] as $taxonomy => $term_ids_string ) {
        $taxonomy = sanitize_key( $taxonomy );

        // Skip if taxonomy not enabled
        if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
            continue;
        }

        if ( ! empty( $term_ids_string ) ) {
            $term_ids = array_map( 'intval', explode( ',', $term_ids_string ) );
            $new_term_ids = array_merge( $new_term_ids, $term_ids );
        }
    }

    // Remove terms that are no longer in the container list
    $terms_to_remove = array_values( array_diff( $current_term_ids, $new_term_ids ) );
    if ( ! empty( $terms_to_remove ) ) {
        $placeholders = htax_sql_placeholders( count( $terms_to_remove ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$relationships_table}`
                WHERE source_term_id = %d
                AND target_term_id IN ({$placeholders})
                AND {$includes_in}
                AND source_taxonomy = 'group'",
                array_merge( array( $term_id ), $terms_to_remove )
            )
        );
    }

    // Add new container relationships
    $terms_to_add      = array_diff( $new_term_ids, $current_term_ids );
    $term_taxonomy_map = htax_get_taxonomy_map_for_terms( $terms_to_add );

    foreach ( $terms_to_add as $term_to_add ) {
        if ( ! isset( $term_taxonomy_map[ $term_to_add ] ) ) {
            continue;
        }

        // Insert the container relationship (source=GroupX, target=TermB, type='includes')
        $wpdb->replace(
            $relationships_table,
            array(
                'source_term_id'    => $term_id,
                'target_term_id'    => $term_to_add,
                'relationship_type' => 'includes',
                'source_taxonomy'   => 'group',
                'target_taxonomy'   => $term_taxonomy_map[ $term_to_add ],
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );
    }

    if ( ! empty( $terms_to_add ) || ! empty( $terms_to_remove ) ) {
        // Clear each directly affected container-target term's own caches.
        foreach ( array_unique( array_merge( $terms_to_add, $terms_to_remove ) ) as $changed_term ) {
            htax_reload_term_caches_on_edit( (int) $changed_term );
        }

        // Invalidate caches for terms that include this group and members of this group
        // (members' archives now include or exclude the changed container targets).
        htax_invalidate_group_relationship_caches( array( (int) $term_id ) );

        // G→T container-group rows changed → htax_inc_M stale for members of this group
        // and ancestor groups (path 3 of htax_get_included_terms expands member groups
        // downward, so ancestor-group members also reach this group's container rows).
        htax_clear_member_caches_for_groups( array( (int) $term_id ), 'inc' );

        do_action( 'htax_term_groups_changed', $term_id, 'group', array(), array() );
    }
}
add_action( 'edited_group', 'htax_save_terms_with_container_group' );

/**
 * Save 'includes this group' relationships
 *
 * @since 1.0
 *
 * @param int $term_id Group term ID.
 */
function htax_save_terms_with_included_group( $term_id ) {
    if ( ! isset( $_POST['htax_terms_with_included_group'] ) || ! is_array( $_POST['htax_terms_with_included_group'] ) ) {
        return;
    }

    global $wpdb;
    $relationships_table = $wpdb->prefix . 'htax_term_relationships';

    // Get all current T→G includes rows (source=T, target=this group, target_taxonomy='group')
    $includes_in      = htax_relationship_type_sql_in( 'includes' );
    $current_term_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT source_term_id FROM {$relationships_table}
        WHERE target_term_id = %d
        AND target_taxonomy = 'group'
        AND {$includes_in}",
        $term_id
    ) );

    $new_term_ids = array();

    // Process each taxonomy's submitted list
    foreach ( $_POST['htax_terms_with_included_group'] as $taxonomy => $term_ids_string ) {
        $taxonomy = sanitize_key( $taxonomy );

        // Skip if inclusion not enabled for this taxonomy
        if ( ! htax_is_taxonomy_enabled( $taxonomy ) || ! htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
            continue;
        }

        if ( ! empty( $term_ids_string ) ) {
            $term_ids = array_map( 'intval', explode( ',', $term_ids_string ) );
            $new_term_ids = array_merge( $new_term_ids, $term_ids );
        }
    }

    // Remove terms that no longer include this group
    $terms_to_remove = array_values( array_diff( $current_term_ids, $new_term_ids ) );
    if ( ! empty( $terms_to_remove ) ) {
        $placeholders = htax_sql_placeholders( count( $terms_to_remove ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$relationships_table}`
                WHERE source_term_id IN ({$placeholders})
                AND target_term_id = %d
                AND {$includes_in}
                AND target_taxonomy = 'group'",
                array_merge( $terms_to_remove, array( $term_id ) )
            )
        );
    }

    // Add new T→G includes rows
    $terms_to_add      = array_diff( $new_term_ids, $current_term_ids );
    $term_taxonomy_map = htax_get_taxonomy_map_for_terms( $terms_to_add );

    foreach ( $terms_to_add as $term_to_add ) {
        if ( ! isset( $term_taxonomy_map[ $term_to_add ] ) ) {
            continue;
        }

        // Insert the inclusion relationship (source=TermA, target=GroupX, type='includes')
        $wpdb->replace(
            $relationships_table,
            array(
                'source_term_id'    => $term_to_add,
                'target_term_id'    => $term_id,
                'relationship_type' => 'includes',
                'source_taxonomy'   => $term_taxonomy_map[ $term_to_add ],
                'target_taxonomy'   => 'group',
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );
    }

    if ( ! empty( $terms_to_add ) || ! empty( $terms_to_remove ) ) {
        // Clear each directly affected source term's own caches.
        foreach ( array_unique( array_merge( $terms_to_add, $terms_to_remove ) ) as $changed_term ) {
            htax_reload_term_caches_on_edit( (int) $changed_term );
        }

        // Invalidate caches for all other terms related to this group.
        htax_invalidate_group_relationship_caches( array( (int) $term_id ) );

        // T→G included-group rows changed → htax_cont_M stale for members of this group
        // and descendant groups (htax_get_container_terms path 2 matches exact membership,
        // and htax_get_terms_in_groups expands downward when computing included terms).
        htax_clear_member_caches_for_groups( array( (int) $term_id ), 'cont' );

        do_action( 'htax_term_groups_changed', $term_id, 'group', array(), array() );
    }
}
add_action( 'edited_group', 'htax_save_terms_with_included_group' );

// ============================================================================
// CUSTOM RELATIONSHIP TYPES ON GROUP EDIT SCREEN
// ============================================================================

/**
 * Render a single pill container for one direction of a custom relationship on the group edit screen.
 *
 * Groups cannot be targets in group+group rows (always invalid), so this only handles
 * non-group term targets/sources. Term objects are loaded cross-taxonomy.
 *
 * @since 1.0.0
 * @param array $args {
 *   int      term_id          Group term ID (the term being edited).
 *   string   field_terms      Hidden input name for the comma-separated term IDs.
 *   int[]    direct_term_ids  Current direct term IDs for this direction.
 *   string[] target_taxonomies Optional taxonomy slugs to restrict the autocomplete search.
 *   string   placeholder      Input placeholder text.
 * }
 */
function htax_render_group_crel_pill_container( array $args ): void {
    $term_id         = (int) $args['term_id'];
    $field_terms     = $args['field_terms'];
    $direct_term_ids = $args['direct_term_ids'] ?? array();
    $placeholder     = $args['placeholder'] ?? __( 'Add…', 'hypertaxonomy' );
    $target_taxes    = $args['target_taxonomies'] ?? array();

    // Determine which taxonomies the autocomplete should search.
    $all_non_group = array_values( array_diff( htax_get_enabled_taxonomies(), array( 'group' ) ) );
    $search_taxes  = ! empty( $target_taxes )
        ? array_values( array_intersect( $all_non_group, $target_taxes ) )
        : $all_non_group;
    if ( empty( $search_taxes ) ) {
        $search_taxes = $all_non_group;
    }

    // Load term objects without taxonomy restriction.
    $term_objects = array();
    if ( ! empty( $direct_term_ids ) && ! empty( $all_non_group ) ) {
        $fetched = get_terms( array(
            'taxonomy'   => $all_non_group,
            'include'    => $direct_term_ids,
            'hide_empty' => false,
        ) );
        if ( ! is_wp_error( $fetched ) ) {
            foreach ( $fetched as $t ) {
                $term_objects[ $t->term_id ] = $t;
            }
        }
    }
    ?>
    <div class="htax-tag-container htax-merged-container"
         data-current="<?php echo esc_attr( $term_id ); ?>"
         data-taxonomy="group"
         data-taxonomies="<?php echo esc_attr( implode( ',', array_map( 'sanitize_key', $search_taxes ) ) ); ?>"
         data-action="htax_search_terms">
        <?php foreach ( $direct_term_ids as $tid ) :
            $t = $term_objects[ $tid ] ?? null;
            if ( ! $t ) { continue; }
            $tax_obj   = get_taxonomy( $t->taxonomy );
            $tax_label = $tax_obj ? strtolower( $tax_obj->labels->singular_name ) : $t->taxonomy;
        ?>
            <span class="htax-pill" data-id="<?php echo esc_attr( $tid ); ?>" tabindex="0">
                <?php echo esc_html( $t->name ); ?>
                <span style="color:#999;font-size:11px;">(<?php echo esc_html( $tax_label ); ?>)</span>
                <button type="button" class="htax-remove"
                        aria-label="<?php esc_attr_e( 'Remove', 'hypertaxonomy' ); ?>"
                        tabindex="-1">
                    <span class="screen-reader-text"><?php esc_html_e( 'Remove term:', 'hypertaxonomy' ); ?></span>
                    <span aria-hidden="true">&times;</span>
                </button>
            </span>
        <?php endforeach; ?>
        <input type="text" class="htax-search"
               id="htax-search-<?php echo esc_attr( $field_terms ); ?>"
               aria-label="<?php echo esc_attr( $placeholder ); ?>"
               placeholder="<?php echo esc_attr( $placeholder ); ?>" />
        <input type="hidden" name="<?php echo esc_attr( $field_terms ); ?>"
               value="<?php echo esc_attr( implode( ',', $direct_term_ids ) ); ?>" />
    </div>
    <?php
}

/**
 * Add custom relationship type tabs to the group edit form.
 *
 * Shows one tab per registered non-core relationship type. Each tab has a forward
 * panel (this group is the source) and a reverse panel (this group is the target).
 * Groups cannot appear as the other side because group+group rows are always invalid.
 *
 * @since 1.0.0
 * @param WP_Term $term Group term being edited.
 */
function htax_add_group_custom_relationships_field( WP_Term $term ): void {
    if ( 'group' !== $term->taxonomy ) {
        return;
    }

    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        return;
    }

    // Collect all non-core registered relationship types.
    $custom_types = array();
    foreach ( htax_get_registered_relationship_types() as $slug => $def ) {
        if ( empty( $def['is_core'] ) ) {
            $custom_types[ $slug ] = $def;
        }
    }

    if ( empty( $custom_types ) ) {
        return;
    }

    $term_id   = (int) $term->term_id;
    $use_tabs  = count( $custom_types ) > 1;

    // Pre-load direct IDs for all types in one pass.
    $type_data = array();
    foreach ( $custom_types as $slug => $def ) {
        $type_data[ $slug ] = array(
            'fwd' => htax_get_direct_rel_term_ids( $term_id, $slug ),
            'rev' => htax_get_reverse_rel_term_ids( $term_id, $slug ),
        );
    }
    ?>
    <tr class="form-field htax-group-custom-rels-field">
        <th scope="row" valign="top">
            <label><?php esc_html_e( 'Custom Relationships', 'hypertaxonomy' ); ?></label>
        </th>
        <td>

        <?php if ( $use_tabs ) : ?>
        <nav class="htax-rel-tabs-nav" role="tablist">
            <?php $first = true; foreach ( $custom_types as $slug => $def ) : ?>
            <button type="button" class="htax-rel-tab-btn<?php echo $first ? ' active' : ''; ?>"
                    role="tab" aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
                    data-tab="gcrel-<?php echo esc_attr( $slug ); ?>">
                <?php echo esc_html( $def['label'] ); ?> / <?php echo esc_html( $def['inverse_label'] ); ?>
            </button>
            <?php $first = false; endforeach; ?>
        </nav>
        <?php endif; ?>

        <?php $first = true; foreach ( $custom_types as $slug => $def ) :
            $cd            = $type_data[ $slug ];
            $fwd_taxes     = ! empty( $def['target_taxonomies'] ) ? $def['target_taxonomies'] : array();
            $rev_taxes     = ! empty( $def['source_taxonomies'] ) ? $def['source_taxonomies'] : array();
            $fwd_ph        = sprintf( __( 'Add %s…', 'hypertaxonomy' ), strtolower( $def['label'] ) );
            $rev_ph        = sprintf( __( 'Add %s…', 'hypertaxonomy' ), strtolower( $def['inverse_label'] ) );
            $panel_hidden  = $use_tabs && ! $first;
        ?>
        <div class="htax-rel-tab-panel<?php echo $use_tabs ? '' : ' htax-no-tabs'; ?>"
             data-panel="gcrel-<?php echo esc_attr( $slug ); ?>"
             <?php echo $use_tabs ? 'role="tabpanel"' : ''; ?>
             <?php echo $panel_hidden ? 'hidden' : ''; ?>>

            <div class="htax-inclusions-panel">
                <h4 class="htax-container-heading"><?php echo esc_html( $def['label'] ); ?></h4>
                <p class="description" style="margin-top:0;">
                    <?php esc_html_e( 'Terms this group has this relationship with (this group is the source).', 'hypertaxonomy' ); ?>
                </p>
                <?php htax_render_group_crel_pill_container( array(
                    'term_id'           => $term_id,
                    'field_terms'       => 'htax_cust_' . $slug . '_fwd_terms',
                    'direct_term_ids'   => $cd['fwd'],
                    'target_taxonomies' => $fwd_taxes,
                    'placeholder'       => $fwd_ph,
                ) ); ?>
            </div>

            <div class="htax-inclusions-panel">
                <h4 class="htax-container-heading"><?php echo esc_html( $def['inverse_label'] ); ?></h4>
                <p class="description" style="margin-top:0;">
                    <?php esc_html_e( 'Terms that have this relationship with this group (this group is the target).', 'hypertaxonomy' ); ?>
                </p>
                <?php htax_render_group_crel_pill_container( array(
                    'term_id'           => $term_id,
                    'field_terms'       => 'htax_cust_' . $slug . '_rev_terms',
                    'direct_term_ids'   => $cd['rev'],
                    'target_taxonomies' => $rev_taxes,
                    'placeholder'       => $rev_ph,
                ) ); ?>
            </div>

        </div><?php /* end panel */
        $first = false; endforeach; ?>

        </td>
    </tr>
    <?php
}
add_action( 'group_edit_form_fields', 'htax_add_group_custom_relationships_field', 19 );

/**
 * Show a context info panel when the group edit page is reached via a term-pill tooltip link.
 *
 * URL params:
 *   term_context  — ID of the originating term.
 *   context_dir   — 'descendants' (look in subgroups) or 'ancestors' (look in parent groups).
 *   context_rel   — 'member_of' (membership path) or 'includes' (includes-relationship path).
 *
 * For direct associations the panel is suppressed; a JS highlight is emitted instead so the
 * user can spot the term pill on the page without needing a redundant notice.
 * For indirect associations the panel names each intermediate group as a clickable link.
 *
 * @since 1.0.0
 * @param WP_Term $term The group term being edited.
 */
function htax_group_context_panel( WP_Term $term ): void {
    if ( 'group' !== $term->taxonomy ) {
        return;
    }
    $context_id = isset( $_GET['term_context'] ) ? (int) $_GET['term_context'] : 0;
    if ( ! $context_id ) {
        return;
    }
    $context_term = get_term( $context_id );
    if ( ! $context_term || is_wp_error( $context_term ) ) {
        return;
    }

    $context_dir = isset( $_GET['context_dir'] ) && 'ancestors' === $_GET['context_dir'] ? 'ancestors' : 'descendants';
    $raw_rel     = isset( $_GET['context_rel'] ) ? sanitize_key( $_GET['context_rel'] ) : '';
    $context_rel = in_array( $raw_rel, array( 'includes_fwd', 'includes_rev' ), true ) ? $raw_rel : 'member_of';
    $group_id    = $term->term_id;

    // Build context-preserving URL for navigating to a related group.
    $make_group_url = function ( int $gid ) use ( $context_id, $context_dir, $context_rel ): string {
        return esc_url( add_query_arg(
            array( 'term_context' => $context_id, 'context_dir' => $context_dir, 'context_rel' => $context_rel ),
            get_edit_term_link( $gid, 'group' )
        ) );
    };

    $is_direct      = false;
    $related_groups = array();

    if ( 'member_of' === $context_rel ) {
        // Membership path: context term is a member_of this group or a group reachable via context_dir.
        $member_groups = htax_get_term_group_ids( $context_id );

        if ( empty( $member_groups ) ) {
            echo '<tr><td colspan="2"><div class="notice notice-warning inline"><p>';
            echo '<strong>' . esc_html( $context_term->name ) . '</strong> ';
            esc_html_e( 'is not currently a member of any group.', 'hypertaxonomy' );
            echo '</p></div></td></tr>';
            return;
        }

        $member_set = array_flip( $member_groups );
        $is_direct  = isset( $member_set[ $group_id ] );

        if ( 'descendants' === $context_dir ) {
            $candidates     = get_term_children( $group_id, 'group' );
            $related_groups = ( ! is_wp_error( $candidates ) )
                ? array_values( array_intersect( $candidates, $member_groups ) )
                : array();
        } else {
            $candidates     = get_ancestors( $group_id, 'group', 'taxonomy' );
            $related_groups = array_values( array_intersect( $candidates, $member_groups ) );
        }
    } elseif ( 'includes_fwd' === $context_rel ) {
        // Forward includes path: this group includes the context term.
        // The term pill is in the "Container For" field on this group's page.
        $incl_fwd  = htax_get_direct_rel_term_ids( $group_id, 'includes' );
        $is_direct = in_array( $context_id, $incl_fwd, true );

        $ancestors = get_ancestors( $group_id, 'group', 'taxonomy' );
        foreach ( $ancestors as $ancestor_gid ) {
            $anc_fwd = htax_get_direct_rel_term_ids( (int) $ancestor_gid, 'includes' );
            if ( in_array( $context_id, $anc_fwd, true ) ) {
                $related_groups[] = (int) $ancestor_gid;
            }
        }
    } else {
        // Reverse includes path (includes_rev): the context term includes this group.
        // The term pill is in the "Included By" field on this group's page.
        $incl_rev  = htax_get_reverse_rel_term_ids( $group_id, 'includes' );
        $is_direct = in_array( $context_id, $incl_rev, true );

        $ancestors = get_ancestors( $group_id, 'group', 'taxonomy' );
        foreach ( $ancestors as $ancestor_gid ) {
            $anc_rev = htax_get_reverse_rel_term_ids( (int) $ancestor_gid, 'includes' );
            if ( in_array( $context_id, $anc_rev, true ) ) {
                $related_groups[] = (int) $ancestor_gid;
            }
        }
    }

    if ( ! $is_direct && empty( $related_groups ) ) {
        echo '<tr><td colspan="2"><div class="notice notice-warning inline"><p>';
        echo '<strong>' . esc_html( $context_term->name ) . '</strong> ';
        esc_html_e( 'does not appear to be associated with this group.', 'hypertaxonomy' );
        echo '</p></div></td></tr>';
        return;
    }

    // Direct-only: highlight the exact pill that shows the relationship instead of a notice.
    if ( empty( $related_groups ) ) {
        if ( 'member_of' === $context_rel ) {
            $pill_selector    = '.htax-group-members-field .htax-pill[data-id="' . $context_id . '"]';
            $section_selector = '.htax-group-members-field';
        } elseif ( 'includes_fwd' === $context_rel ) {
            $pill_selector    = '.htax-group-container-members-field .htax-pill[data-id="' . $context_id . '"]';
            $section_selector = '.htax-group-container-members-field';
        } else {
            $pill_selector    = '.htax-terms-with-included-group-field .htax-pill[data-id="' . $context_id . '"]';
            $section_selector = '.htax-terms-with-included-group-field';
        }
        printf(
            '<script>document.addEventListener("DOMContentLoaded",function(){' .
            'document.querySelectorAll(%s).forEach(function(el){el.classList.add("htax-context-direct");});' .
            'var s=document.querySelector(%s);' .
            'if(s){var t=s.getBoundingClientRect().top+window.pageYOffset-60;window.scrollTo({top:Math.max(0,t),behavior:"smooth"});}' .
            '});</script>',
            wp_json_encode( $pill_selector ),
            wp_json_encode( $section_selector )
        );
        return;
    }

    $links = array();
    foreach ( $related_groups as $gid ) {
        $g = get_term( (int) $gid, 'group' );
        if ( $g && ! is_wp_error( $g ) ) {
            $links[] = '<a href="' . $make_group_url( (int) $gid ) . '">' . esc_html( $g->name ) . '</a>';
        }
    }

    if ( ! $links ) {
        return;
    }

    if ( 'member_of' === $context_rel ) {
        if ( 'descendants' === $context_dir ) {
            $label = _n( 'member via subgroup:', 'member via subgroups:', count( $links ), 'hypertaxonomy' );
        } else {
            $label = _n( 'member via parent group:', 'member via parent groups:', count( $links ), 'hypertaxonomy' );
        }
    } else {
        $label = _n( 'associated via parent group:', 'associated via parent groups:', count( $links ), 'hypertaxonomy' );
    }

    echo '<tr><td colspan="2"><div id="htax-context-panel" class="notice notice-info inline"><p>';
    echo '<strong>' . esc_html( $context_term->name ) . '</strong> ';
    esc_html_e( 'appears here because:', 'hypertaxonomy' );
    echo ' ' . esc_html( $label ) . ' ' . implode( ', ', $links ) . '.';
    echo '</p></div></td></tr>';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){' .
         'var p=document.getElementById("htax-context-panel");' .
         'if(p){var t=p.getBoundingClientRect().top+window.pageYOffset-60;window.scrollTo({top:Math.max(0,t),behavior:"smooth"});}' .
         '});</script>';
}
add_action( 'group_edit_form_fields', 'htax_group_context_panel', 15 );

/**
 * Save custom relationship type rows submitted from the group edit form.
 *
 * Processes htax_cust_{slug}_fwd_terms and htax_cust_{slug}_rev_terms fields for
 * every non-core registered type. Groups cannot be on the other side (group+group
 * rows are always invalid), so only term IDs are handled here.
 *
 * @since 1.0.0
 * @param int $term_id Group term ID.
 */
function htax_save_group_custom_type_relationships( int $term_id ): void {
    if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        return;
    }

    $parse_ids = function( string $key ): array {
        if ( ! isset( $_POST[ $key ] ) ) {
            return array();
        }
        $raw = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
        return array_values( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) );
    };

    foreach ( htax_get_registered_relationship_types() as $slug => $def ) {
        if ( ! empty( $def['is_core'] ) ) {
            continue;
        }

        $fwd_key = 'htax_cust_' . $slug . '_fwd_terms';
        $rev_key = 'htax_cust_' . $slug . '_rev_terms';

        if ( ! isset( $_POST[ $fwd_key ] ) && ! isset( $_POST[ $rev_key ] ) ) {
            continue;
        }

        $new_fwd = $parse_ids( $fwd_key );
        $new_rev = $parse_ids( $rev_key );

        $cur_fwd = htax_get_term_relationship_ids( $term_id, 'target_term_id', 'source_term_id', $slug, array( 'target_taxonomy' => HTAX_NOT_GROUP ) );
        $cur_rev = htax_get_term_relationship_ids( $term_id, 'source_term_id', 'target_term_id', $slug, array( 'source_taxonomy' => HTAX_NOT_GROUP ) );

        foreach ( array_diff( $new_fwd, $cur_fwd ) as $tid ) {
            htax_add_term_relationship( $term_id, $tid, $slug );
        }
        foreach ( array_diff( $cur_fwd, $new_fwd ) as $tid ) {
            htax_remove_term_relationship( $term_id, $tid, $slug );
        }

        foreach ( array_diff( $new_rev, $cur_rev ) as $tid ) {
            htax_add_term_relationship( $tid, $term_id, $slug );
        }
        foreach ( array_diff( $cur_rev, $new_rev ) as $tid ) {
            htax_remove_term_relationship( $tid, $term_id, $slug );
        }
    }
}
add_action( 'edited_group', 'htax_save_group_custom_type_relationships' );

/**
 * Note: AJAX column display fix for new group terms is handled client-side
 * in admin/js/htax-group-admin.js lines 78-102 via ajaxSuccess handler.
 *
 * @since 1.0
 */

/**
 * Enqueue media uploader for group icon
 *
 * @since 1.0
 */
function htax_enqueue_group_admin_scripts( $hook ) {
    global $taxnow;

    // Only load on group taxonomy edit pages
    if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) || 'group' !== $taxnow ) {
        return;
    }

    wp_enqueue_media();

    // Enqueue jQuery UI autocomplete for term member search
    wp_enqueue_script( 'jquery-ui-autocomplete' );
    wp_enqueue_style( 'wp-jquery-ui-dialog' ); // Provides jQuery UI theme

    wp_enqueue_script(
        'htax-group-admin',
        HTAX_PLUGIN_URL . 'admin/js/htax-group-admin.js',
        array( 'jquery', 'jquery-ui-autocomplete' ),
        HTAX_VERSION,
        true
    );
}
add_action( 'admin_enqueue_scripts', 'htax_enqueue_group_admin_scripts' );

/**
 * Add help text to group taxonomy screen
 *
 * @since 1.0
 */
function htax_group_admin_help() {
    $screen = get_current_screen();

    if ( ! $screen || 'edit-group' !== $screen->id ) {
        return;
    }

    $screen->add_help_tab(
        array(
            'id'      => 'htax_group_overview',
            'title'   => __( 'Overview', 'hypertaxonomy' ),
            'content' => '<p>' . __( 'Term Groups allow you to organize terms from different taxonomies (tags, categories, custom taxonomies) into hierarchical groups.', 'hypertaxonomy' ) . '</p>' .
                '<p>' . __( 'Groups are hierarchical, meaning you can create parent and child groups. In addition to directly assigned terms, groups automatically include terms from all their child groups as well.', 'hypertaxonomy' ) . '</p>',
        )
    );

    $screen->add_help_tab(
        array(
            'id'      => 'htax_group_usage',
            'title'   => __( 'Usage', 'hypertaxonomy' ),
            'content' => '<p>' . __( 'To assign terms to groups, edit the term (tag, category, etc.) and select the groups in the "Term Groups" section.', 'hypertaxonomy' ) . '</p>' .
                '<p>' . __( 'Group archive pages (e.g., /group/colors/) will display posts from all terms assigned to that group across all enabled taxonomies.', 'hypertaxonomy' ) . '</p>',
        )
    );

    $screen->add_help_tab(
        array(
            'id'      => 'htax_group_settings',
            'title'   => __( 'Settings', 'hypertaxonomy' ),
            'content' => '<p>' . __( 'You can enable or disable group assignment for each taxonomy in Settings → HyperTaxonomy → Term Groups.', 'hypertaxonomy' ) . '</p>' .
                '<p>' . __( 'Only taxonomies with group assignment enabled will show the group selection interface on their term edit screens.', 'hypertaxonomy' ) . '</p>',
        )
    );
}
add_action( 'load-edit-tags.php', 'htax_group_admin_help' );
add_action( 'load-term.php', 'htax_group_admin_help' );

// Note: Term search autocomplete reuses the existing htax_search_terms AJAX handler from htax-term-edit.php
