<?php
/**
 * Relationship Types REST API
 *
 * Exposes two read-only endpoints under the htax/v1 namespace for peer sites
 * (primarily TermConnect) to sync relationship type definitions and term relationships.
 *
 * GET /wp-json/htax/v1/relationship-types
 *   Returns locally-owned custom types + built-in types, each with a computed
 *   namespace. Private types and remote-delegated types are always excluded.
 *   Inclusion-based custom types (core_type = 'includes') are excluded by default
 *   because they travel via htax_includes in the peer collection response rather
 *   than as named types. Pass include_inclusion_based=1 to include them.
 *
 * GET /wp-json/htax/v1/term/{id}/relationships
 *   Returns effective (group-expanded) outbound relationships for a term, scoped
 *   to non-core, non-private custom types. Inclusion-based types are excluded by
 *   default (same reason as above). Pass include_inclusion_based=1 to include them.
 *
 * Both endpoints are public (no authentication required) since they expose the
 * same semantic information available on term archives.
 *
 * @package HyperTaxonomy
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'htax_register_relationship_type_rest_routes' );

/**
 * Register the relationship type REST routes.
 *
 * Skipped when the Peer Relationship Delegation API setting is disabled
 * (Settings → HyperTaxonomy → Advanced → Peer Relationship Delegation API).
 *
 * @since 1.0.0
 */
function htax_register_relationship_type_rest_routes(): void {
    $opts = get_option( 'htax_settings', array() );
    if ( isset( $opts['rest_peer_sync_enabled'] ) && empty( $opts['rest_peer_sync_enabled'] ) ) {
        return;
    }

    $inclusion_arg = array(
        'default'           => false,
        'type'              => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'description'       => 'When true, inclusion-based custom types (core_type = includes) are included in the response. Defaults to false — they travel via htax_includes in peer collection responses.',
    );

    register_rest_route( 'htax/v1', '/relationship-types', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'htax_rest_get_relationship_types',
        'permission_callback' => '__return_true',
        'args'                => array(
            'include_inclusion_based' => $inclusion_arg,
        ),
    ) );

    register_rest_route( 'htax/v1', '/term/(?P<id>\d+)/relationships', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'htax_rest_get_term_relationships',
        'permission_callback' => '__return_true',
        'args'                => array(
            'id' => array(
                'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                'sanitize_callback' => 'absint',
            ),
            'include_inclusion_based' => $inclusion_arg,
        ),
    ) );
}

/**
 * REST callback: list relationship type definitions.
 *
 * Returns built-in types (namespace = hypertaxonomy.org) and local/promoted
 * custom types (namespace = home_url()). Remote-delegated types and private
 * types are always excluded. Inclusion-based custom types are excluded by
 * default; pass include_inclusion_based=1 to include them.
 *
 * @since 1.0.0
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function htax_rest_get_relationship_types( WP_REST_Request $request ): WP_REST_Response {
    $local_namespace         = home_url();
    $htax_namespace          = 'https://hypertaxonomy.org';
    $include_inclusion_based = (bool) $request->get_param( 'include_inclusion_based' );
    $all_types               = htax_get_registered_relationship_types( true ); // skip private
    $response_types          = array();

    foreach ( $all_types as $slug => $def ) {
        // Exclude remote-delegated types — they have a canonical source elsewhere.
        if ( ! empty( $def['source_site'] ) ) {
            continue;
        }
        // Exclude inclusion-based custom types by default — they travel via htax_includes.
        if ( ! $include_inclusion_based
             && empty( $def['is_core'] )
             && 'includes' === ( $def['core_type'] ?? null ) ) {
            continue;
        }

        $namespace = ! empty( $def['is_core'] ) ? $htax_namespace : $local_namespace;

        $response_types[] = array(
            'slug'              => $slug,
            'namespace'         => $namespace,
            'label'             => $def['label'],
            'inverse_label'     => $def['inverse_label'],
            'description'       => $def['description'] ?? '',
            'core_type'         => $def['core_type'],
            'show_in_ui'        => (bool) ( $def['show_in_ui'] ?? true ),
            'symmetric'         => (bool) ( $def['symmetric'] ?? false ),
            'source_taxonomies' => $def['source_taxonomies'] ?? array(),
            'target_taxonomies' => $def['target_taxonomies'] ?? array(),
        );
    }

    return new WP_REST_Response( $response_types, 200 );
}

/**
 * REST callback: get effective outbound relationships for a term.
 *
 * Returns expanded (group targets resolved to member terms) relationships for
 * non-core, non-private custom types. Built-in types are excluded — they are
 * handled by Shared Vocabularies. Inclusion-based custom types are excluded by
 * default (they travel via htax_includes); pass include_inclusion_based=1 to
 * include them.
 *
 * @since 1.0.0
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function htax_rest_get_term_relationships( WP_REST_Request $request ) {
    $term_id = (int) $request['id'];
    $term    = get_term( $term_id );

    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'htax_not_found', __( 'Term not found.', 'hypertaxonomy' ), array( 'status' => 404 ) );
    }

    $include_inclusion_based = (bool) $request->get_param( 'include_inclusion_based' );

    $custom_slugs = array_keys( array_filter(
        htax_get_registered_relationship_types(),
        static function( $def ) use ( $include_inclusion_based ) {
            if ( ! empty( $def['is_core'] ) || ! empty( $def['is_private'] ) ) {
                return false;
            }
            if ( ! $include_inclusion_based && 'includes' === ( $def['core_type'] ?? null ) ) {
                return false;
            }
            return true;
        }
    ) );

    $response_data = array();

    foreach ( $custom_slugs as $type_slug ) {
        $related_terms = htax_get_related_terms( $term_id, $type_slug );
        if ( empty( $related_terms ) ) {
            continue;
        }

        foreach ( $related_terms as $related ) {
            $response_data[] = array(
                'relationship_type' => $type_slug,
                'target_term_id'    => $related->term_id,
                'target_slug'       => $related->slug,
                'target_taxonomy'   => $related->taxonomy,
            );
        }
    }

    return new WP_REST_Response( $response_data, 200 );
}
