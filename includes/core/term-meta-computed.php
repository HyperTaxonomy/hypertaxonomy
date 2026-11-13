<?php
/**
 * Computed term meta: HyperTaxonomy's term data, readable with get_term_meta().
 *
 * GraphQL plugins, page builders and themes can read term meta but not HyperTaxonomy's REST
 * fields. So HyperTaxonomy answers get_term_meta() for the keys below with values computed
 * from its existing caches. Nothing is stored in wp_termmeta, and nothing new needs clearing:
 * when the underlying caches change, these values change with them.
 *
 * Hidden terms are left out for every viewer, as in the REST relationship fields, so every
 * viewer gets the same answer whatever the request method. A hidden term returns nothing.
 *
 * The REST fields stay as the suite's peer protocol: they carry slugs, strengths and synonym
 * flags that TermConnect needs from peer sites, so these keys aren't added to REST.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The computed keys: key => true when the value is a list (of term IDs, URIs or location rows),
 * false when it is one value.
 *
 * @since 1.0.0
 * @return array<string,bool>
 */
function htax_computed_term_meta_keys(): array {
    return array(
        'htax_includes'         => true,
        'htax_containers'       => true,
        'htax_synonyms'         => true,
        'htax_groups'           => true,
        'htax_archive_term_ids' => true,
        'htax_term_uris'        => true,
        'htax_locations'        => true,
        'htax_sticky_post_ids'  => true,
        'htax_redirect'         => false,
        'htax_icon_url'         => false,
        'htax_icon_url_small'   => false,
        'htax_icon_url_medium'  => false,
        'htax_icon_url_large'   => false,
    );
}

/**
 * Compute one key's value for a term.
 *
 * @since 1.0.0
 * @param WP_Term $term Term.
 * @param string  $key  A key from htax_computed_term_meta_keys().
 * @return array|int|string A list for list keys (term IDs; URI strings for htax_term_uris;
 *                          location rows for htax_locations; post IDs for htax_sticky_post_ids);
 *                          a term ID (0 for none) for
 *                          htax_redirect; a URL ('' for none) for the icon keys.
 */
function htax_compute_term_meta( WP_Term $term, string $key ) {
    switch ( $key ) {
        case 'htax_includes':
            return htax_remove_hidden_term_ids( array_keys( htax_load_included_terms( $term, false, false ) ) );

        case 'htax_containers':
            return htax_remove_hidden_term_ids( array_keys( htax_load_container_terms( $term, false ) ) );

        case 'htax_synonyms':
            return htax_remove_hidden_term_ids( array_keys( htax_load_synonym_terms( $term, false ) ) );

        case 'htax_groups':
            // Groups the term is explicitly assigned to; not the ancestors of those groups.
            $group_ids = array();
            foreach ( htax_get_term_groups( $term->term_id ) as $group ) {
                $group_ids[] = (int) $group->term_id;
            }
            return htax_remove_hidden_term_ids( $group_ids );

        case 'htax_archive_term_ids':
            return htax_remove_hidden_term_ids( htax_get_expanded_term_ids( $term->term_id ) );

        case 'htax_term_uris':
            // Every external identifier any provider gives the term: TermConnect's links,
            // As the Crow Flies' address URIs, and so on.
            return array_values( array_unique( array_filter( array_map( 'strval', htax_get_term_uris( $term->term_id ) ) ) ) );

        case 'htax_locations':
            // Same gate as the htax_locations REST field: shared only when the taxonomy's
            // "Share list of locations" feature is on. Flat rows (label, street_address,
            // locality, region, postal_code, country, lat, lng), easier for meta-reading
            // tools than the REST field's nested Schema.org objects.
            if ( ! htax_taxonomy_has_feature( $term->taxonomy, 'share_locations' ) ) {
                return array();
            }
            return array_values( htax_get_term_locations( $term->term_id ) );

        case 'htax_sticky_post_ids':
            // Posts pinned to the top of the term's archive, newest first, as the archive
            // shows them: published posts only, and only when the taxonomy's Customize
            // Archives feature is on, the same gate as the archive itself.
            if ( ! htax_taxonomy_has_feature( $term->taxonomy, 'customize_archives' ) ) {
                return array();
            }
            return array_map( 'intval', wp_list_pluck( htax_get_sticky_posts_for_term( $term->term_id ), 'ID' ) );

        case 'htax_redirect':
            $target = htax_get_redirect_target( $term->term_id );
            if ( ! $target ) {
                return 0;
            }
            $ids = htax_remove_hidden_term_ids( array( $target->term_id ) );
            return $ids ? $ids[0] : 0;

        default:
            // Icon keys. htax_icon_url is the large size, the taxonomy's configured icon size.
            $icon = htax_rest_build_icon_data( $term );
            $size = 'htax_icon_url' === $key ? 'large' : substr( $key, strlen( 'htax_icon_url_' ) );
            return isset( $icon[ $size ]['url'] ) ? (string) $icon[ $size ]['url'] : '';
    }
}

/**
 * Answer get_term_meta() for the computed keys.
 *
 * Hooked to get_term_metadata. Reads with no key (all of a term's meta) fall through, so
 * plugins that copy or export a term's meta don't pick these up.
 *
 * @since 1.0.0
 * @param mixed  $value    Null, or a value short-circuiting the lookup.
 * @param int    $term_id  Term ID.
 * @param string $meta_key Meta key.
 * @param bool   $single   Whether a single value was requested.
 * @return mixed The computed value in get_metadata()'s format, or $value to fall through.
 */
function htax_filter_computed_term_meta( $value, $term_id, $meta_key, $single ) {
    $keys = htax_computed_term_meta_keys();
    if ( null !== $value || ! is_string( $meta_key ) || ! isset( $keys[ $meta_key ] ) ) {
        return $value;
    }

    $term = get_term( (int) $term_id );
    if ( ! $term instanceof WP_Term || 'group' === $term->taxonomy || ! htax_is_taxonomy_enabled( $term->taxonomy ) ) {
        return $value;
    }

    $is_list = $keys[ $meta_key ];

    // A hidden term has no public data.
    if ( ! htax_remove_hidden_term_ids( array( $term->term_id ) ) ) {
        $result = $is_list ? array() : ( 'htax_redirect' === $meta_key ? 0 : '' );
    } else {
        $result = htax_compute_term_meta( $term, $meta_key );
    }

    // get_metadata() returns $value[0] for a single read and $value as-is otherwise.
    if ( $single ) {
        return array( $result );
    }
    if ( $is_list ) {
        return $result; // One term ID per meta value, like multi-value meta.
    }
    return ( '' === $result || 0 === $result ) ? array() : array( $result );
}
add_filter( 'get_term_metadata', 'htax_filter_computed_term_meta', 10, 4 );

/**
 * Refuse writes to the computed keys, so no stored row can shadow a computed value.
 *
 * Hooked to add_term_metadata, update_term_metadata and delete_term_metadata.
 *
 * @since 1.0.0
 * @param mixed  $check    Null to continue, anything else to short-circuit.
 * @param int    $term_id  Term ID.
 * @param string $meta_key Meta key.
 * @return mixed False for a computed key; $check otherwise.
 */
function htax_refuse_computed_term_meta_write( $check, $term_id, $meta_key ) {
    if ( is_string( $meta_key ) && isset( htax_computed_term_meta_keys()[ $meta_key ] ) ) {
        return false;
    }
    return $check;
}
add_filter( 'add_term_metadata', 'htax_refuse_computed_term_meta_write', 10, 3 );
add_filter( 'update_term_metadata', 'htax_refuse_computed_term_meta_write', 10, 3 );
add_filter( 'delete_term_metadata', 'htax_refuse_computed_term_meta_write', 10, 3 );

/**
 * Register the computed keys for each HyperTaxonomy-enabled taxonomy, so tools that list
 * registered meta can find them. Not added to REST: the htax_* REST fields carry this data.
 *
 * @since 1.0.0
 */
function htax_register_computed_term_meta(): void {
    $descriptions = array(
        'htax_includes'         => __( 'IDs of the terms whose posts this term\'s archive includes (HyperTaxonomy inclusions, group and container-group inclusions, after exclusions). Read-only.', 'hypertaxonomy' ),
        'htax_containers'       => __( 'IDs of the terms whose archives include this term\'s posts. Read-only.', 'hypertaxonomy' ),
        'htax_synonyms'         => __( 'IDs of this term\'s synonyms. Read-only.', 'hypertaxonomy' ),
        'htax_groups'           => __( 'IDs of the Term Groups this term is assigned to. Read-only.', 'hypertaxonomy' ),
        'htax_archive_term_ids' => __( 'IDs of every term whose posts this term\'s archive shows, including the term and its child terms. Read-only.', 'hypertaxonomy' ),
        'htax_term_uris'        => __( 'External identifier URIs for this term, such as knowledge-base links. Read-only.', 'hypertaxonomy' ),
        'htax_locations'        => __( 'Locations of this term, when the taxonomy shares them. Read-only.', 'hypertaxonomy' ),
        'htax_sticky_post_ids'  => __( 'IDs of the posts pinned to the top of this term\'s archive, newest first. Read-only.', 'hypertaxonomy' ),
        'htax_redirect'         => __( 'ID of the term this term redirects to, or 0. Read-only.', 'hypertaxonomy' ),
        'htax_icon_url'         => __( 'URL of this term\'s icon at the large size. Read-only.', 'hypertaxonomy' ),
        'htax_icon_url_small'   => __( 'URL of this term\'s icon at the small size. Read-only.', 'hypertaxonomy' ),
        'htax_icon_url_medium'  => __( 'URL of this term\'s icon at the medium size. Read-only.', 'hypertaxonomy' ),
        'htax_icon_url_large'   => __( 'URL of this term\'s icon at the large size. Read-only.', 'hypertaxonomy' ),
    );

    foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
        if ( 'group' === $taxonomy ) {
            continue;
        }
        foreach ( htax_computed_term_meta_keys() as $key => $is_list ) {
            if ( registered_meta_key_exists( 'term', $key, $taxonomy ) ) {
                continue;
            }
            register_term_meta( $taxonomy, $key, array(
                'type'          => $is_list ? 'array' : ( 'htax_redirect' === $key ? 'integer' : 'string' ),
                'single'        => true,
                'description'   => $descriptions[ $key ],
                'show_in_rest'  => false,
                'auth_callback' => '__return_false',
            ) );
        }
    }
}
add_action( 'init', 'htax_register_computed_term_meta', 20 );
