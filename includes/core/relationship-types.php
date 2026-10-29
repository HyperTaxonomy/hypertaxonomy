<?php
/**
 * Relationship Type Registry
 *
 * Manages built-in and user-defined relationship types. Built-in types are
 * registered on init at priority 0. User-defined types are loaded from
 * wp_options and registered on init at priority 5.
 *
 * The registry and slug-lookup cache are globals so they can be invalidated
 * without PHP closure tricks. htax_get_relationship_type_slugs() never
 * queries the database.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// GLOBALS (in-memory registry + slug cache)
// ============================================================================

// Slug-keyed registry of all registered types.
$htax_relationship_type_registry = array();

// Cache for htax_get_relationship_type_slugs(), keyed by core_type_slug.
$htax_relationship_type_slugs_cache = array();

// ============================================================================
// REGISTRATION API
// ============================================================================

/**
 * Register a relationship type.
 *
 * Called on init. Built-in types use priority 0; user types use priority 5.
 *
 * @since 1.0.0
 * @param string $slug Unique slug (lowercase, hyphens allowed, max 80 chars).
 * @param array  $args Type definition. {
 *     @type string   $label             Human-readable forward label (e.g. "Wrote").
 *     @type string   $inverse_label     Reverse label (e.g. "Written by"). '' = no inverse.
 *     @type bool     $is_core           True for built-in base types. Prevents deletion/slug change.
 *     @type string|null $core_type      Base types: own slug. Mapped user types: base type slug. null = no archive behavior.
 *     @type bool     $user_mappable     True if user-defined types may map to this base type.
 *     @type bool     $show_in_ui        Appears in term edit screen relationship selector.
 *     @type string[] $source_taxonomies Allowed source taxonomy slugs. Empty = any.
 *     @type string[] $target_taxonomies Allowed target taxonomy slugs. Empty = any.
 * }
 */
function htax_register_relationship_type( string $slug, array $args ): void {
    global $htax_relationship_type_registry, $htax_relationship_type_slugs_cache;

    $defaults = array(
        'label'              => $slug,
        'inverse_label'      => '',
        'is_core'            => false,
        'core_type'          => null,
        'user_mappable'      => false,
        'show_in_ui'         => true,
        'source_taxonomies'  => array(),
        'target_taxonomies'  => array(),
        'description'        => '',    // Optional human-readable description of the relationship type.
        'source_site'        => '',    // URL of peer site that originated this type; empty = locally owned.
        'is_private'         => false, // When true, excluded from peer relationship delegation REST responses.
    );

    $htax_relationship_type_registry[ $slug ] = array_merge( $defaults, $args );
    $htax_relationship_type_slugs_cache       = array(); // invalidate on every registration
}

/**
 * Returns all registered type definitions as slug => args array.
 *
 * @since 1.0.0
 * @param bool $skip_private When true, omits custom types whose is_private flag is set.
 *                           Built-in types are never private and are always included.
 * @return array<string, array>
 */
function htax_get_registered_relationship_types( bool $skip_private = false ): array {
    global $htax_relationship_type_registry;
    if ( ! $skip_private ) {
        return $htax_relationship_type_registry;
    }
    return array_filter(
        $htax_relationship_type_registry,
        static fn( $t ) => ! empty( $t['is_core'] ) || empty( $t['is_private'] )
    );
}

/**
 * Returns args for a single registered type, or null if not registered.
 *
 * @since 1.0.0
 * @param string $slug Relationship type slug to look up.
 * @return array|null
 */
function htax_get_relationship_type( string $slug ): ?array {
    global $htax_relationship_type_registry;
    return $htax_relationship_type_registry[ $slug ] ?? null;
}

/**
 * Returns all slugs whose core_type matches the given slug.
 *
 * Base types are self-referencing (core_type = own slug), so they always
 * include themselves. Built from the in-memory registry — no database query.
 *
 * @since 1.0.0
 * @param string $core_type_slug Base type slug to expand (e.g. 'includes').
 * @return string[]
 */
function htax_get_relationship_type_slugs( string $core_type_slug ): array {
    global $htax_relationship_type_registry, $htax_relationship_type_slugs_cache;

    if ( isset( $htax_relationship_type_slugs_cache[ $core_type_slug ] ) ) {
        return $htax_relationship_type_slugs_cache[ $core_type_slug ];
    }

    if ( ! is_array( $htax_relationship_type_registry ) ) {
        return array();
    }

    $slugs = array();
    foreach ( $htax_relationship_type_registry as $slug => $args ) {
        if ( isset( $args['core_type'] ) && $args['core_type'] === $core_type_slug ) {
            $slugs[] = $slug;
        }
    }

    $htax_relationship_type_slugs_cache[ $core_type_slug ] = $slugs;
    return $slugs;
}

/**
 * Returns an SQL fragment matching all slugs mapped to a core type.
 *
 * Example: "relationship_type IN ('includes','wrote')"
 *
 * Safe to embed directly in a prepared query — slugs come from the registry
 * and are passed through esc_sql().
 *
 * @since 1.0.0
 * @param string $core_type_slug Base type slug to expand (e.g. 'includes').
 * @return string
 */
function htax_relationship_type_sql_in( string $core_type_slug ): string {
    $slugs  = htax_get_relationship_type_slugs( $core_type_slug );
    if ( empty( $slugs ) ) {
        return '1=0';
    }
    $quoted = array_map(
        function ( string $s ): string {
            return "'" . esc_sql( $s ) . "'";
        },
        $slugs
    );
    return 'relationship_type IN (' . implode( ', ', $quoted ) . ')';
}

// ============================================================================
// PERSISTENCE (USER-DEFINED TYPES)
// ============================================================================

/** Options key for persistent storage of user-defined relationship types. */
define( 'HTAX_CUSTOM_REL_TYPES_OPTION', 'htax_custom_relationship_types' );

/**
 * Persist a new or updated user-defined relationship type.
 *
 * Writes to wp_options (autoloaded) and updates the in-memory registry.
 *
 * @since 1.0.0
 * @param string $slug   Unique slug. Lowercase letters, digits, hyphens. Max 80 chars.
 * @param array  $args   Same keys as htax_register_relationship_type(). is_core is forced false.
 * @param bool   $is_new True when creating a new type, false when editing an existing one.
 * @return true|WP_Error
 */
function htax_set_custom_relationship_type( string $slug, array $args, bool $is_new = false ) {
    if ( ! preg_match( '/^[a-z0-9-]{1,80}$/', $slug ) ) {
        return new WP_Error( 'invalid_slug', __( 'Slug must be 1–80 lowercase letters, digits, or hyphens.', 'hypertaxonomy' ) );
    }

    $existing = htax_get_relationship_type( $slug );

    // Prevent overwriting core types.
    if ( $existing && ! empty( $existing['is_core'] ) ) {
        return new WP_Error( 'core_type', __( 'Built-in relationship types cannot be modified.', 'hypertaxonomy' ) );
    }

    if ( $is_new && $existing ) {
        return new WP_Error( 'slug_exists', __( 'A relationship type with this slug already exists.', 'hypertaxonomy' ) );
    }

    if ( ! $is_new && ! $existing ) {
        return new WP_Error( 'not_found', __( 'Relationship type not found.', 'hypertaxonomy' ) );
    }

    // Lock core_type on edit — changing it would silently alter archive behavior.
    if ( $existing ) {
        $args['core_type'] = $existing['core_type'];
    }

    // Validate core_type if provided.
    if ( ! empty( $args['core_type'] ) ) {
        $base = htax_get_relationship_type( $args['core_type'] );
        if ( ! $base ) {
            return new WP_Error( 'invalid_core_type', __( 'The specified core type is not registered.', 'hypertaxonomy' ) );
        }
        if ( empty( $base['user_mappable'] ) ) {
            return new WP_Error( 'not_mappable', __( 'That base type is not user-mappable.', 'hypertaxonomy' ) );
        }
    }

    // Sanitize taxonomies (group is always implicitly allowed — strip from explicit constraints).
    $core_type    = ! empty( $args['core_type'] ) ? sanitize_key( $args['core_type'] ) : null;
    $source_taxes = array_values( array_diff( array_map( 'sanitize_key', (array) ( $args['source_taxonomies'] ?? array() ) ), array( 'group' ) ) );
    $target_taxes = array_values( array_diff( array_map( 'sanitize_key', (array) ( $args['target_taxonomies'] ?? array() ) ), array( 'group' ) ) );

    // Inclusion-based types must have exactly one taxonomy shared by source and target.
    if ( 'includes' === $core_type ) {
        if ( 1 !== count( $source_taxes ) || $source_taxes !== $target_taxes ) {
            return new WP_Error(
                'taxonomy_required',
                __( 'Inclusion-based types require exactly one taxonomy, the same for source and target.', 'hypertaxonomy' )
            );
        }
    }

    // Symmetric types (non-inclusion-based only) use the forward label as the inverse label.
    // The label_equals_inverse check is bypassed for them — equality is intentional.
    $symmetric = ( null === $core_type ) && ! empty( $args['symmetric'] );

    $clean = array(
        'label'             => sanitize_text_field( $args['label'] ?? $slug ),
        'inverse_label'     => sanitize_text_field( $args['inverse_label'] ?? '' ),
        'description'       => sanitize_textarea_field( $args['description'] ?? '' ),
        'is_core'           => false,
        'core_type'         => $core_type,
        'symmetric'         => $symmetric,
        'user_mappable'     => false,
        'show_in_ui'        => (bool) ( $args['show_in_ui'] ?? true ),
        'source_taxonomies' => $source_taxes,
        'target_taxonomies' => $target_taxes,
        'source_site'       => esc_url_raw( $args['source_site'] ?? '' ),
        'is_private'        => (bool) ( $args['is_private'] ?? false ),
    );

    if ( $symmetric ) {
        $clean['inverse_label'] = $clean['label'];
    }

    // Label and inverse label must be distinct from each other (skipped for symmetric types).
    if ( ! $symmetric && '' !== $clean['inverse_label'] && strtolower( $clean['label'] ) === strtolower( $clean['inverse_label'] ) ) {
        return new WP_Error( 'label_equals_inverse', __( 'Label and inverse label must be different.', 'hypertaxonomy' ) );
    }

    // Label and inverse label must be unique across all registered types (case-insensitive).
    $new_label = strtolower( $clean['label'] );
    $new_inv   = strtolower( $clean['inverse_label'] );
    foreach ( htax_get_registered_relationship_types() as $existing_slug => $existing ) {
        if ( $existing_slug === $slug ) {
            continue; // Skip the type being updated.
        }
        $ex_label = strtolower( $existing['label'] );
        $ex_inv   = strtolower( $existing['inverse_label'] );

        if ( $new_label === $ex_label || ( $ex_inv !== '' && $new_label === $ex_inv ) ) {
            return new WP_Error(
                'duplicate_label',
                sprintf(
                    /* translators: 1: submitted label, 2: existing type label */
                    __( 'The label "%1$s" is already used by the "%2$s" type.', 'hypertaxonomy' ),
                    $clean['label'],
                    $existing['label']
                )
            );
        }
        if ( $new_inv !== '' && ( $new_inv === $ex_label || ( $ex_inv !== '' && $new_inv === $ex_inv ) ) ) {
            return new WP_Error(
                'duplicate_inverse_label',
                sprintf(
                    /* translators: 1: submitted inverse label, 2: existing type label */
                    __( 'The inverse label "%1$s" is already used by the "%2$s" type.', 'hypertaxonomy' ),
                    $clean['inverse_label'],
                    $existing['label']
                )
            );
        }
    }

    $stored          = get_option( HTAX_CUSTOM_REL_TYPES_OPTION, array() );
    $stored[ $slug ] = $clean;
    update_option( HTAX_CUSTOM_REL_TYPES_OPTION, $stored, true );

    // Sync in-memory registry (also clears slug cache via htax_register_relationship_type).
    htax_register_relationship_type( $slug, $clean );

    // Clear all cached relationship data for this type (definition may have changed).
    htax_delete_transients_by_prefix( 'htax_crel_' . $slug . '_' );
    htax_delete_transients_by_prefix( 'htax_crel_rev_' . $slug . '_' );

    return true;
}

/**
 * Remove a user-defined relationship type.
 *
 * Returns false if the slug is a core type or is not found.
 *
 * @since 1.0.0
 * @param string $slug Relationship type slug to delete.
 * @return bool
 */
function htax_remove_custom_relationship_type( string $slug ): bool {
    $existing = htax_get_relationship_type( $slug );
    if ( ! $existing || ! empty( $existing['is_core'] ) ) {
        return false;
    }

    $stored = get_option( HTAX_CUSTOM_REL_TYPES_OPTION, array() );
    unset( $stored[ $slug ] );
    update_option( HTAX_CUSTOM_REL_TYPES_OPTION, $stored, true );

    global $htax_relationship_type_registry, $htax_relationship_type_slugs_cache;
    unset( $htax_relationship_type_registry[ $slug ] );
    $htax_relationship_type_slugs_cache = array();

    // Delete all relationship instances that used this type.
    global $wpdb;
    $wpdb->delete(
        $wpdb->prefix . 'htax_term_relationships',
        array( 'relationship_type' => $slug ),
        array( '%s' )
    );

    // Clear all cached relationship data for the deleted type.
    htax_delete_transients_by_prefix( 'htax_crel_' . $slug . '_' );
    htax_delete_transients_by_prefix( 'htax_crel_rev_' . $slug . '_' );

    return true;
}

/**
 * Detach a remote-delegated relationship type from its source, making it locally owned.
 *
 * Clears source_site so the type is treated as locally created going forward.
 * The slug, labels, and all existing relationship rows are preserved unchanged.
 * This action is irreversible.
 *
 * Returns false if the slug does not exist or is a core type.
 *
 * @since 1.0.0
 * @param string $slug Relationship type slug.
 * @return bool
 */
function htax_make_relationship_type_local( string $slug ): bool {
    $existing = htax_get_relationship_type( $slug );
    if ( ! $existing || ! empty( $existing['is_core'] ) ) {
        return false;
    }

    $stored = get_option( HTAX_CUSTOM_REL_TYPES_OPTION, array() );
    if ( ! isset( $stored[ $slug ] ) ) {
        return false;
    }

    $stored[ $slug ]['source_site'] = '';
    update_option( HTAX_CUSTOM_REL_TYPES_OPTION, $stored, true );

    // Update in-memory registry.
    global $htax_relationship_type_registry;
    if ( isset( $htax_relationship_type_registry[ $slug ] ) ) {
        $htax_relationship_type_registry[ $slug ]['source_site'] = '';
    }

    return true;
}

// ============================================================================
// QUERY API
// ============================================================================

/**
 * Get related terms for a term by relationship type slug (forward direction).
 *
 * For non-core types, group targets are expanded to their member terms (using
 * the source term's taxonomy). Returns WP_Term objects indexed by term_id.
 * Cached as htax_crel_{slug}_{term_id}; invalidated by group membership changes
 * via htax_invalidate_group_relationship_caches().
 *
 * Filters by the literal slug — no core_type expansion. To query all
 * includes-mapped types use htax_get_relationship_type_slugs('includes').
 *
 * @since 1.0.0
 * @param int    $term_id           Source term ID.
 * @param string $type_slug         Exact registered type slug.
 * @param bool   $include_redirect_data Include relationships even when the source term is a redirect.
 * @param bool   $skip_if_private   Return empty array if the type has is_private set. Default true.
 * @return WP_Term[] Related terms indexed by term_id.
 */
function htax_get_related_terms( int $term_id, string $type_slug, bool $include_redirect_data = false, bool $skip_if_private = true ): array {
    // Core built-in types (includes, container, member_of, redirect, excludes) each have
    // a dedicated function that handles caching, group expansion, never_include, etc.
    // Querying them through this generic API bypasses all that logic.
    $type_def = htax_get_relationship_type( $type_slug );
    if ( $type_def && ! empty( $type_def['is_core'] ) ) {
        return array();
    }
    if ( $skip_if_private && $type_def && ! empty( $type_def['is_private'] ) ) {
        return array();
    }

    $term_for_check = get_term( $term_id );
    if ( ! ( $term_for_check instanceof WP_Term ) ) {
        return array();
    }

    // Custom relationship types are gated by the term_relationships feature, like core ones.
    if ( ! htax_taxonomy_has_feature( $term_for_check->taxonomy, 'term_relationships' ) ) {
        return array();
    }

    if ( ! $include_redirect_data && htax_is_redirect( $term_id )
         && htax_taxonomy_has_feature( $term_for_check->taxonomy, 'redirects' ) ) {
        return array();
    }

    $cache_key = 'htax_crel_' . $type_slug . '_' . $term_id;
    $cached    = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';
    $rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT target_term_id, target_taxonomy FROM {$table}
             WHERE source_term_id = %d AND relationship_type = %s",
            $term_id,
            $type_slug
        ),
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        htax_set_transient( $cache_key, array(), htax_get_cache_duration( 'relationship' ) );
        return array();
    }

    // Type is guaranteed non-core past the guard above; group targets are expanded.
    $result     = array();
    $ids_by_tax = array();
    $group_ids  = array();

    foreach ( $rows as $row ) {
        if ( 'group' === $row['target_taxonomy'] ) {
            $group_ids[] = (int) $row['target_term_id'];
        } else {
            $ids_by_tax[ $row['target_taxonomy'] ][] = (int) $row['target_term_id'];
        }
    }

    if ( ! empty( $group_ids ) ) {
        $source_term = get_term( $term_id );
        $taxonomy    = ( $source_term && ! is_wp_error( $source_term ) ) ? $source_term->taxonomy : '';
        if ( $taxonomy ) {
            foreach ( htax_get_terms_in_groups( $group_ids, $taxonomy ) as $member ) {
                $result[ $member->term_id ] = $member;
            }
        }
    }

    foreach ( $ids_by_tax as $taxonomy => $ids ) {
        $fetched = get_terms( array(
            'taxonomy'   => $taxonomy,
            'include'    => array_unique( $ids ),
            'hide_empty' => false,
        ) );
        if ( ! is_wp_error( $fetched ) ) {
            foreach ( $fetched as $term ) {
                $result[ $term->term_id ] = $term;
            }
        }
    }

    htax_set_transient( $cache_key, $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}

/**
 * Get terms that have a given relationship type pointing TO this term (reverse direction).
 *
 * For non-core types, group sources are expanded to their member terms (using
 * the target term's taxonomy). Returns WP_Term objects indexed by term_id.
 * Cached as htax_crel_rev_{slug}_{term_id}; invalidated by group membership
 * changes via htax_invalidate_group_relationship_caches().
 *
 * @since 1.0.0
 * @param int    $term_id   Target term ID.
 * @param string $type_slug Exact registered type slug.
 * @return WP_Term[] Source terms indexed by term_id.
 */
function htax_get_reverse_related_terms( int $term_id, string $type_slug ): array {
    // Core built-in types have dedicated functions; this API is for non-core custom types only.
    $type_def = htax_get_relationship_type( $type_slug );
    if ( $type_def && ! empty( $type_def['is_core'] ) ) {
        return array();
    }

    // Gated by the term_relationships feature, like htax_get_related_terms().
    $term_for_check = get_term( $term_id );
    if ( ! ( $term_for_check instanceof WP_Term )
         || ! htax_taxonomy_has_feature( $term_for_check->taxonomy, 'term_relationships' ) ) {
        return array();
    }

    $cache_key = 'htax_crel_rev_' . $type_slug . '_' . $term_id;
    $cached    = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';
    $rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT source_term_id, source_taxonomy FROM {$table}
             WHERE target_term_id = %d AND relationship_type = %s",
            $term_id,
            $type_slug
        ),
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        htax_set_transient( $cache_key, array(), htax_get_cache_duration( 'relationship' ) );
        return array();
    }

    $type_def   = htax_get_relationship_type( $type_slug );
    $is_core    = ! $type_def || ! empty( $type_def['is_core'] );
    $result     = array();
    $ids_by_tax = array();
    $group_ids  = array();

    foreach ( $rows as $row ) {
        if ( ! $is_core && 'group' === $row['source_taxonomy'] ) {
            $group_ids[] = (int) $row['source_term_id'];
        } else {
            $ids_by_tax[ $row['source_taxonomy'] ][] = (int) $row['source_term_id'];
        }
    }

    if ( ! empty( $group_ids ) ) {
        $target_term = get_term( $term_id );
        $taxonomy    = ( $target_term && ! is_wp_error( $target_term ) ) ? $target_term->taxonomy : '';
        if ( $taxonomy ) {
            foreach ( htax_get_terms_in_groups( $group_ids, $taxonomy ) as $member ) {
                $result[ $member->term_id ] = $member;
            }
        }
    }

    foreach ( $ids_by_tax as $taxonomy => $ids ) {
        $fetched = get_terms( array(
            'taxonomy'   => $taxonomy,
            'include'    => array_unique( $ids ),
            'hide_empty' => false,
        ) );
        if ( ! is_wp_error( $fetched ) ) {
            foreach ( $fetched as $term ) {
                $result[ $term->term_id ] = $term;
            }
        }
    }

    htax_set_transient( $cache_key, $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}

/**
 * Get user-defined relationship types visible for a given source taxonomy.
 *
 * Returns registered types where is_core is false, show_in_ui is true, and
 * source_taxonomies is either empty (any) or contains the given taxonomy.
 *
 * @since 1.0.0
 * @param string $taxonomy Source taxonomy slug.
 * @return array<string, array> Slug-keyed type definitions.
 */
function htax_get_relationship_types_for_taxonomy( string $taxonomy ): array {
    $all   = htax_get_registered_relationship_types();
    $types = array();
    foreach ( $all as $slug => $args ) {
        if ( ! empty( $args['is_core'] ) ) {
            continue;
        }
        if ( empty( $args['show_in_ui'] ) ) {
            continue;
        }
        if ( ! empty( $args['source_taxonomies'] ) && ! in_array( $taxonomy, $args['source_taxonomies'], true ) ) {
            continue;
        }
        $types[ $slug ] = $args;
    }
    return $types;
}

// ============================================================================
// RELATIONSHIP ROW MANAGEMENT
// ============================================================================

/**
 * Save a relationship row between two terms.
 *
 * Valid for 'includes', 'excludes', and all non-core types. Do NOT pass
 * 'redirect' (use htax_save_redirect() — chain collapse and circular detection
 * are required) or 'member_of' (use htax_set_term_groups()).
 *
 * Enforces taxonomy constraints, invalidates affected caches, fires the
 * appropriate relationship-change actions, and fires htax_relationship_saved.
 *
 * @since 1.0.0
 * @param int    $source_term_id Source term ID.
 * @param int    $target_term_id Target term ID.
 * @param string $type_slug      Registered relationship type slug.
 * @return true|WP_Error
 */
function htax_add_term_relationship( int $source_term_id, int $target_term_id, string $type_slug ) {
    if ( 'redirect' === $type_slug || 'member_of' === $type_slug ) {
        _doing_it_wrong( __FUNCTION__, "Use the dedicated API for '{$type_slug}' relationships.", '1.0.0' );
        return new WP_Error( 'reserved_type', __( 'Use the dedicated API for this relationship type.', 'hypertaxonomy' ) );
    }

    $type = htax_get_relationship_type( $type_slug );
    if ( ! $type ) {
        return new WP_Error( 'invalid_type', __( 'Relationship type not registered.', 'hypertaxonomy' ) );
    }

    $source_term = get_term( $source_term_id );
    $target_term = get_term( $target_term_id );

    if ( ! $source_term || is_wp_error( $source_term ) ) {
        return new WP_Error( 'invalid_source', __( 'Source term ID is invalid.', 'hypertaxonomy' ) );
    }
    if ( ! $target_term || is_wp_error( $target_term ) ) {
        return new WP_Error( 'invalid_target', __( 'Target term ID is invalid.', 'hypertaxonomy' ) );
    }

    $source_is_group    = ( 'group' === $source_term->taxonomy );
    $target_is_group    = ( 'group' === $target_term->taxonomy );
    $is_includes_mapped = ( 'includes' === ( $type['core_type'] ?? null ) );

    // Groups cannot be on both sides.
    if ( $source_is_group && $target_is_group ) {
        return new WP_Error( 'group_both_sides', __( 'A relationship cannot have group terms on both source and target.', 'hypertaxonomy' ) );
    }

    // Self-inclusion: a term cannot be related to itself.
    if ( $is_includes_mapped && $source_term_id === $target_term_id ) {
        return new WP_Error( 'self_inclusion', __( 'A term cannot include itself.', 'hypertaxonomy' ) );
    }

    // Taxonomy constraints are skipped when that side is a group term (groups are always allowed).
    if ( ! $source_is_group && ! empty( $type['source_taxonomies'] ) && ! in_array( $source_term->taxonomy, $type['source_taxonomies'], true ) ) {
        return new WP_Error( 'taxonomy_mismatch', __( 'Source term taxonomy is not allowed for this relationship type.', 'hypertaxonomy' ) );
    }
    if ( ! $target_is_group && ! empty( $type['target_taxonomies'] ) && ! in_array( $target_term->taxonomy, $type['target_taxonomies'], true ) ) {
        return new WP_Error( 'taxonomy_mismatch', __( 'Target term taxonomy is not allowed for this relationship type.', 'hypertaxonomy' ) );
    }

    // Includes-mapped types require source and target to be in the same taxonomy (unless one side is a group).
    if ( $is_includes_mapped && ! $source_is_group && ! $target_is_group && $source_term->taxonomy !== $target_term->taxonomy ) {
        return new WP_Error( 'taxonomy_mismatch', __( 'Includes-type relationships must connect terms in the same taxonomy.', 'hypertaxonomy' ) );
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'htax_term_relationships';
    $result = $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
             VALUES (%d, %d, %s, %s, %s)",
            $source_term_id,
            $target_term_id,
            $type_slug,
            $source_term->taxonomy,
            $target_term->taxonomy
        )
    );

    if ( false === $result ) {
        return new WP_Error( 'db_error', __( 'Database error saving relationship.', 'hypertaxonomy' ) );
    }

    // Invalidate per-term caches for both sides.
    htax_delete_transient( 'htax_crel_' . $type_slug . '_' . $source_term_id );
    htax_delete_transient( 'htax_crel_rev_' . $type_slug . '_' . $target_term_id );
    htax_delete_transient( 'htax_direct_rel_' . $type_slug . '_' . $source_term_id );
    htax_delete_transient( 'htax_reverse_rel_' . $type_slug . '_' . $target_term_id );
    htax_delete_transient( 'htax_direct_rel_grp_' . $type_slug . '_' . $source_term_id );
    htax_delete_transient( 'htax_reverse_rel_grp_' . $type_slug . '_' . $target_term_id );

    // Type-specific cache invalidation and change actions.
    if ( $is_includes_mapped ) {
        if ( $target_is_group ) {
            htax_delete_transient( 'htax_inc_' . $source_term_id );
            do_action( 'htax_includes_changed', $source_term_id, $source_term->taxonomy, null, null );
            htax_clear_member_caches_for_groups( array( $target_term_id ), 'cont' );
        } elseif ( $source_is_group ) {
            htax_delete_transient( 'htax_cont_' . $target_term_id );
            do_action( 'htax_containers_changed', $target_term_id, $target_term->taxonomy, null, null );
            htax_clear_member_caches_for_groups( array( $source_term_id ), 'inc' );
        } else {
            htax_delete_transient( 'htax_inc_' . $source_term_id );
            htax_delete_transient( 'htax_cont_' . $target_term_id );
            do_action( 'htax_includes_changed', $source_term_id, $source_term->taxonomy, null, null );
            do_action( 'htax_containers_changed', $target_term_id, $target_term->taxonomy, null, null );
        }
    } elseif ( 'excludes' === $type_slug ) {
        htax_delete_transient( 'htax_excl_' . $source_term_id );
        do_action( 'htax_excludes_changed', $source_term_id, $source_term->taxonomy, null, null );
    }

    /**
     * Fires after a relationship row is saved.
     *
     * @since 1.0.0
     * @param int    $source_term_id Source term ID.
     * @param int    $target_term_id Target term ID.
     * @param string $type_slug      Relationship type slug.
     */
    do_action( 'htax_relationship_saved', $source_term_id, $target_term_id, $type_slug );

    return true;
}

/**
 * Delete a relationship row between two terms.
 *
 * Valid for 'includes', 'excludes', and all non-core types. 'redirect' and
 * 'member_of' are blocked — use their dedicated APIs instead.
 * Fires htax_relationship_deleted on success.
 *
 * @since 1.0.0
 * @param int    $source_term_id Source term ID.
 * @param int    $target_term_id Target term ID.
 * @param string $type_slug      Relationship type slug.
 * @return bool True if a row was deleted.
 */
function htax_remove_term_relationship( int $source_term_id, int $target_term_id, string $type_slug ): bool {
    if ( 'redirect' === $type_slug || 'member_of' === $type_slug ) {
        _doing_it_wrong( __FUNCTION__, "Use the dedicated API for '{$type_slug}' relationships.", '1.0.0' );
        return false;
    }

    // Fetch terms before delete so we have taxonomy/group info for cache actions.
    $type                    = htax_get_relationship_type( $type_slug );
    $is_includes_mapped      = $type && ( 'includes' === ( $type['core_type'] ?? null ) );
    $needs_type_invalidation = ( $is_includes_mapped || 'excludes' === $type_slug );
    if ( $needs_type_invalidation ) {
        $source_term     = get_term( $source_term_id );
        $target_term     = get_term( $target_term_id );
        $source_is_group = ( $source_term && ! is_wp_error( $source_term ) && 'group' === $source_term->taxonomy );
        $target_is_group = ( $target_term && ! is_wp_error( $target_term ) && 'group' === $target_term->taxonomy );
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'htax_term_relationships';
    $result = $wpdb->delete(
        $table,
        array(
            'source_term_id'    => $source_term_id,
            'target_term_id'    => $target_term_id,
            'relationship_type' => $type_slug,
        ),
        array( '%d', '%d', '%s' )
    );

    if ( $result ) {
        // Invalidate per-term caches for both sides.
        htax_delete_transient( 'htax_crel_' . $type_slug . '_' . $source_term_id );
        htax_delete_transient( 'htax_crel_rev_' . $type_slug . '_' . $target_term_id );
        htax_delete_transient( 'htax_direct_rel_' . $type_slug . '_' . $source_term_id );
        htax_delete_transient( 'htax_reverse_rel_' . $type_slug . '_' . $target_term_id );
        htax_delete_transient( 'htax_direct_rel_grp_' . $type_slug . '_' . $source_term_id );
        htax_delete_transient( 'htax_reverse_rel_grp_' . $type_slug . '_' . $target_term_id );

        // Type-specific cache invalidation and change actions.
        if ( $is_includes_mapped ) {
            if ( $target_is_group ) {
                htax_delete_transient( 'htax_inc_' . $source_term_id );
                do_action( 'htax_includes_changed', $source_term_id, $source_term->taxonomy, null, null );
                htax_clear_member_caches_for_groups( array( $target_term_id ), 'cont' );
            } elseif ( $source_is_group ) {
                htax_delete_transient( 'htax_cont_' . $target_term_id );
                do_action( 'htax_containers_changed', $target_term_id, $target_term->taxonomy, null, null );
                htax_clear_member_caches_for_groups( array( $source_term_id ), 'inc' );
            } else {
                htax_delete_transient( 'htax_inc_' . $source_term_id );
                htax_delete_transient( 'htax_cont_' . $target_term_id );
                do_action( 'htax_includes_changed', $source_term_id, $source_term->taxonomy, null, null );
                do_action( 'htax_containers_changed', $target_term_id, $target_term->taxonomy, null, null );
            }
        } elseif ( 'excludes' === $type_slug ) {
            htax_delete_transient( 'htax_excl_' . $source_term_id );
            do_action( 'htax_excludes_changed', $source_term_id, $source_term->taxonomy, null, null );
        }

        /**
         * Fires after a relationship row is deleted.
         *
         * @since 1.0.0
         * @param int    $source_term_id Source term ID.
         * @param int    $target_term_id Target term ID.
         * @param string $type_slug      Relationship type slug.
         */
        do_action( 'htax_relationship_deleted', $source_term_id, $target_term_id, $type_slug );
        return true;
    }

    return false;
}

/**
 * Delete relationship rows that violate a type's constraints.
 *
 * Applies to both core and custom types. Removes rows where any of the following are true:
 * - Both source and target are 'group' terms (never valid for any type).
 * - source_taxonomy violates the type's source_taxonomies constraint (group side is exempt).
 * - target_taxonomy violates the type's target_taxonomies constraint (group side is exempt).
 * - For includes/excludes: both non-group sides are in different taxonomies, or same term.
 * - For member_of: source is a group term, or target is not a group term.
 * - For redirect: self-redirect (source = target), or chain row (target is itself a redirect
 *   source). Chain rows use a JOIN-based DELETE since MySQL forbids same-table subqueries.
 *
 * @since 1.0.0
 * @param string $slug Relationship type slug.
 * @return int Number of rows deleted.
 */
function htax_remove_invalid_term_relationships( string $slug ): int {
    $type = htax_get_relationship_type( $slug );
    if ( ! $type ) {
        return 0;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';

    $core_type    = $type['core_type'] ?? null;
    $source_taxes = $type['source_taxonomies'] ?? array();
    $target_taxes = $type['target_taxonomies'] ?? array();

    // includes and excludes both require source and target to share a taxonomy.
    $requires_same_taxonomy = in_array( $core_type, array( 'includes', 'excludes' ), true );

    $invalid_conditions = array();

    // Group+group rows are never valid.
    $invalid_conditions[] = "(source_taxonomy = 'group' AND target_taxonomy = 'group')";

    // Source taxonomy constraint — exempt if source is 'group'.
    if ( ! empty( $source_taxes ) ) {
        $placeholders         = implode( ', ', array_fill( 0, count( $source_taxes ), '%s' ) );
        $invalid_conditions[] = $wpdb->prepare(
            "(source_taxonomy != 'group' AND source_taxonomy NOT IN ($placeholders))",
            ...$source_taxes
        );
    }

    // Target taxonomy constraint — exempt if target is 'group'.
    if ( ! empty( $target_taxes ) ) {
        $placeholders         = implode( ', ', array_fill( 0, count( $target_taxes ), '%s' ) );
        $invalid_conditions[] = $wpdb->prepare(
            "(target_taxonomy != 'group' AND target_taxonomy NOT IN ($placeholders))",
            ...$target_taxes
        );
    }

    // includes and excludes: cross-taxonomy rows (both non-group) are invalid.
    if ( $requires_same_taxonomy ) {
        $invalid_conditions[] = "(source_taxonomy != 'group' AND target_taxonomy != 'group' AND source_taxonomy != target_taxonomy)";
        // Self-relations are never valid.
        $invalid_conditions[] = 'source_term_id = target_term_id';
    }

    // member_of: source must not be a group; target must be a group.
    if ( 'member_of' === $core_type ) {
        $invalid_conditions[] = "source_taxonomy = 'group'";
        $invalid_conditions[] = "target_taxonomy != 'group'";
    }

    // redirect: self-redirect rows are invalid (chains handled separately below).
    if ( 'redirect' === $core_type ) {
        $invalid_conditions[] = 'source_term_id = target_term_id';
    }

    $type_cond = $wpdb->prepare( 'relationship_type = %s', $slug );
    $where_sql = $type_cond . ' AND (' . implode( ' OR ', $invalid_conditions ) . ')';

    $affected_rows = $wpdb->get_results(
        "SELECT source_term_id, target_term_id FROM `{$table}` WHERE {$where_sql}",
        ARRAY_A
    );

    $deleted = $wpdb->query( "DELETE FROM `{$table}` WHERE {$where_sql}" );

    // redirect: delete chain rows where the target is itself a redirect source.
    // MySQL forbids referencing the target table in a subquery within DELETE, so use a JOIN.
    if ( 'redirect' === $core_type ) {
        $chain_affected = $wpdb->get_results(
            "SELECT r1.source_term_id, r1.target_term_id
             FROM `{$table}` r1
             INNER JOIN `{$table}` r2
                 ON r2.source_term_id = r1.target_term_id
                AND r2.relationship_type = 'redirect'
             WHERE r1.relationship_type = 'redirect'",
            ARRAY_A
        );
        $chain_deleted = $wpdb->query(
            "DELETE r1 FROM `{$table}` r1
             INNER JOIN `{$table}` r2
                 ON r2.source_term_id = r1.target_term_id
                AND r2.relationship_type = 'redirect'
             WHERE r1.relationship_type = 'redirect'"
        );
        if ( ! empty( $chain_affected ) ) {
            $affected_rows = array_merge( $affected_rows, $chain_affected );
        }
        $deleted = ( false === $deleted ? 0 : (int) $deleted )
                 + ( false === $chain_deleted ? 0 : (int) $chain_deleted );
    }

    if ( $deleted && ! empty( $affected_rows ) ) {
        $term_ids = array_unique( array_merge(
            array_column( $affected_rows, 'source_term_id' ),
            array_column( $affected_rows, 'target_term_id' )
        ) );
        foreach ( $term_ids as $term_id ) {
            htax_delete_term_transients( (int) $term_id );
        }
    }

    return ( false === $deleted ) ? 0 : (int) $deleted;
}

// ============================================================================
// INITIALIZATION
// ============================================================================

/**
 * Register built-in relationship types.
 *
 * Runs on init at priority 0 — before user-defined types (priority 5).
 *
 * @since 1.0.0
 */
function htax_register_builtin_relationship_types(): void {
    htax_register_relationship_type( 'member_of', array(
        'label'            => __( 'Member of', 'hypertaxonomy' ),
        'inverse_label'    => __( 'Contains member', 'hypertaxonomy' ),
        'is_core'          => true,
        'core_type'        => 'member_of',
        'user_mappable'    => false,
        'show_in_ui'       => false,
    ) );

    htax_register_relationship_type( 'includes', array(
        'label'            => __( 'Includes', 'hypertaxonomy' ),
        'inverse_label'    => __( 'Is included into', 'hypertaxonomy' ),
        'is_core'          => true,
        'core_type'        => 'includes',
        'user_mappable'    => true,
        'show_in_ui'       => false,
    ) );

    htax_register_relationship_type( 'excludes', array(
        'label'            => __( 'Excludes', 'hypertaxonomy' ),
        'inverse_label'    => __( 'Is excluded from', 'hypertaxonomy' ),
        'is_core'          => true,
        'core_type'        => 'excludes',
        'user_mappable'    => false,
        'show_in_ui'       => false,
    ) );

    htax_register_relationship_type( 'redirect', array(
        'label'            => __( 'Redirects to', 'hypertaxonomy' ),
        'inverse_label'    => __( 'Target of redirect from', 'hypertaxonomy' ),
        'is_core'          => true,
        'core_type'        => 'redirect',
        'user_mappable'    => false,
        'show_in_ui'       => false,
    ) );

    /**
     * Fires after built-in relationship types are registered.
     *
     * Plugins may call htax_register_relationship_type() here to add types in PHP.
     *
     * @since 1.0.0
     */
    do_action( 'htax_relationship_types' );
}
add_action( 'init', 'htax_register_builtin_relationship_types', 0 );

/**
 * Load user-defined relationship types from wp_options into the in-memory registry.
 *
 * Runs on init at priority 5 — after built-in types (priority 0).
 *
 * @since 1.0.0
 */
function htax_load_custom_relationship_types(): void {
    $stored = get_option( HTAX_CUSTOM_REL_TYPES_OPTION, array() );
    if ( ! is_array( $stored ) ) {
        return;
    }
    foreach ( $stored as $slug => $args ) {
        if ( is_string( $slug ) && is_array( $args ) ) {
            htax_register_relationship_type( $slug, $args );
        }
    }
}
add_action( 'init', 'htax_load_custom_relationship_types', 5 );
