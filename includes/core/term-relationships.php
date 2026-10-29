<?php
/**
 * Term Relationship Management
 * Handles synonyms, includes, redirects, and cleanup
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 *
 * RELATIONSHIP ARCHITECTURE:
 * =========================
 *
 * All relationships stored in unified wp_htax_term_relationships table:
 * - source_term_id: The term that initiates the relationship
 * - target_term_id: The term that receives the relationship
 * - relationship_type: 'includes', 'member_of', 'redirect'
 * - source_taxonomy / target_taxonomy: Taxonomy context
 *
 * RELATIONSHIP TYPES:
 * ===================
 *
 * 1. DIRECT INCLUSION (A includes B):
 *    Storage: source=A, target=B, type='includes'
 *    Effect: Posts from B appear in A's archive
 *
 * 2. GROUP MEMBERSHIP (A is member of GroupX):
 *    Storage: source=A, target=GroupX, type='member_of'
 *    Effect: A can be queried as part of GroupX
 *
 * 3. INCLUDED GROUPS (A includes GroupX):
 *    Storage: source=A, target=GroupX, type='includes', target_taxonomy='group'
 *    Effect: All members of GroupX appear in A's archive (expanded via JOIN)
 *
 * 4. CONTAINER GROUPS (A included into GroupX members):
 *    Storage: source=GroupX, target=A, type='includes', source_taxonomy='group'
 *    Effect: A appears in all GroupX members' archives (REVERSE includes)
 *
 * 5. REDIRECTS (A redirects to B):
 *    Storage: source=A, target=B, type='redirect'
 *    Effect: 301 redirect from A's archive to B's archive
 *
 * QUERY EXPANSION:
 * ================
 * - Relationships stored as INTENT, expanded dynamically at query time
 * - Uses SQL JOINs to expand group relationships
 * - Ancestor + descendant filtering upfront, then SQL exclusion (cross-branch only)
 * - Results cached by WordPress object cache (1 hour TTL)
 *
 * FILE ORGANIZATION:
 * ==================
 * SECTION 1: Core Getters & Cache (Lines ~20-270)
 * SECTION 2: Bidirectional Relationship Helpers (Lines ~271-400)
 * SECTION 3: Validation & Circular Detection (Lines ~401-550)
 * SECTION 4: Redirect Chain Management (Lines ~551-750)
 * SECTION 5: Save Logic & Bidirectional Sync (Lines ~751-1250)
 * SECTION 6: Term Groups Integration Helpers (Lines ~1251-1450)
 * SECTION 7: Cleanup & Deletion Handlers (Lines ~1451-1750)
 * SECTION 8: Hierarchy Conflict Detection (Lines ~1751-2500)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Sentinel value for htax_get_term_relationship_ids() $extra_where to match
 * rows where the column is not 'group' (i.e. column != 'group').
 */
define( 'HTAX_NOT_GROUP', '__htax_not_group__' );

/**
 * Generic database query helper for term relationships
 *
 * Retrieves term IDs from the relationships table based on flexible WHERE conditions.
 * This is a low-level helper used by various relationship getter functions.
 *
 * @since 1.0.0
 *
 * @param int    $term_id          Term ID to query for
 * @param string $id_column        Column to SELECT (e.g., 'source_term_id' or 'target_term_id')
 * @param string $where_column     Column to filter by term_id (e.g., 'source_term_id' or 'target_term_id')
 * @param string $relationship_type Relationship type (e.g., 'includes', 'member_of', 'redirect')
 * @param array  $extra_where      Additional WHERE conditions as column => value pairs.
 *                                 Use HTAX_NOT_GROUP as value for a != 'group' condition.
 * @return int[] Array of term IDs
 *
 * @example
 * // Get included term IDs from groups
 * htax_get_term_relationship_ids( $term_id, 'target_term_id', 'source_term_id', 'includes',
 *     array('source_taxonomy' => 'group') );
 */
function htax_get_term_relationship_ids( $term_id, $id_column, $where_column, $relationship_type, $extra_where = array() ) {
    if ( ! $term_id ) {
        return array();
    }

    $valid_columns = array( 'source_term_id', 'target_term_id', 'source_taxonomy', 'target_taxonomy', 'relationship_type' );
    if ( ! in_array( $id_column, $valid_columns, true ) || ! in_array( $where_column, $valid_columns, true ) ) {
        return array();
    }
    foreach ( array_keys( $extra_where ) as $extra_col ) {
        if ( ! in_array( $extra_col, $valid_columns, true ) ) {
            return array();
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Build WHERE clause
    $where_parts = array(
        $wpdb->prepare( "{$where_column} = %d", $term_id ),
        $wpdb->prepare( "relationship_type = %s", $relationship_type )
    );

    // Add extra WHERE conditions
    foreach ( $extra_where as $column => $value ) {
        if ( HTAX_NOT_GROUP === $value ) {
            $where_parts[] = "{$column} != 'group'";
        } else {
            $where_parts[] = $wpdb->prepare( "{$column} = %s", $value );
        }
    }

    $where_clause = implode( ' AND ', $where_parts );

    $ids = $wpdb->get_col(
        "SELECT DISTINCT {$id_column} FROM {$table_name}
        WHERE {$where_clause}"
    );

    return ! empty( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Parse and sanitize term IDs from a POST field
 *
 * Extracts term IDs from a comma-separated POST field, sanitizes them,
 * and returns an array of integers. Empty values are filtered out.
 *
 * @since 1.0.0
 *
 * @param string $field_key POST field key to parse
 * @return int[] Array of sanitized term IDs (empty if field missing or invalid)
 *
 * @example
 * // Parse included term IDs from POST
 * $included_ids = htax_parse_post_field_ids( 'htax_includes' );
 * // Returns [1, 2, 3] from POST value "1,2,3"
 * // Returns [] if field missing or empty
 */
function htax_parse_post_field_ids( $field_key ) {
    if ( ! isset( $_POST[ $field_key ] ) ) {
        return array();
    }

    $val = wp_unslash( $_POST[ $field_key ] );

    // Checklist checkboxes submit as an array (name="field[]"); hidden inputs submit as
    // a comma-separated string. Handle both.
    if ( is_array( $val ) ) {
        return array_filter( array_map( 'intval', array_map( 'sanitize_text_field', $val ) ) );
    }

    return array_filter(
        array_map(
            'intval',
            explode( ',', sanitize_text_field( $val ) )
        )
    );
}

// ============================================================================
// SECTION 1: CORE GETTERS & CACHE
// ============================================================================
// Functions for retrieving term relationships:
// - htax_get_ids_of_redirects_to() - Get terms redirecting to target (multi-taxonomy, cross-taxonomy)
// - htax_get_included_terms() - Get included terms as WP_Term objects (same-taxonomy only)
// - htax_get_container_terms() - Get container terms as WP_Term objects (same-taxonomy only)
// ============================================================================

/**
 * Get term IDs that redirect to a given target
 *
 * Queries the htax_term_relationships table for terms that have a redirect relationship to the target.
 *
 * @param int $target_id Target term ID
 * @param array|string|null $taxonomies Taxonomy names (array), single taxonomy (string), or null for all
 * @return array Array of term IDs that redirect to the target
 */
function htax_get_ids_of_redirects_to( $target_id, $taxonomies = null ) {
    if ( ! $target_id ) {
        return array();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    if ( null === $taxonomies ) {
        // Get all redirects to this term regardless of taxonomy
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT source_term_id
            FROM {$table_name}
            WHERE target_term_id = %d
            AND relationship_type = 'redirect'",
            $target_id
        ) );
    } else {
        // Filter by taxonomy
        if ( ! is_array( $taxonomies ) ) {
            $taxonomies = array( $taxonomies );
        }

        $placeholders = htax_sql_placeholders( count( $taxonomies ), 's' );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT source_term_id
            FROM {$table_name}
            WHERE target_term_id = %d
            AND relationship_type = 'redirect'
            AND source_taxonomy IN ($placeholders)",
            array_merge( array( $target_id ), $taxonomies )
        ) );
    }

    return ! empty( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Get included terms for a given term
 *
 * Builds the full inclusion set, then subtracts all exclusions:
 *
 * Inclusions:
 *   - Members of included groups, with descendant subgroup expansion
 *   - Directly included individual terms
 *   - Terms with container groups that this term is a member of
 *
 * Exclusions (applied last, cannot be overridden):
 *   - Directly excluded individual terms
 *   - Self + ancestors + descendants (hierarchical taxonomies only — cross-branch only)
 *
 * Returns an empty set when the term is a redirect, because redirect terms have no
 * archive of their own and their relationships are inactive. Pass $include_redirect_data = true
 * to bypass this check (admin display only).
 *
 * Returns an empty array when the term's taxonomy has the term_relationships feature
 * disabled — a disabled taxonomy has no active relationships for any consumer. The
 * cached data itself is feature-independent; the gate is applied on read, so toggling
 * the feature needs no cache rebuild.
 *
 * @param int|WP_Term $term                  Term ID or WP_Term object.
 * @param bool        $include_redirect_data When true, return data even if the term is a redirect.
 * @param bool        $include_disabled      When true, return data even if term_relationships is
 *                                           disabled for the taxonomy (search index only — it stores
 *                                           relationships regardless and gates at query time).
 * @return WP_Term[] Array of WP_Term objects indexed by term_id
 */
function htax_get_included_terms( $term, bool $include_redirect_data = false, bool $include_disabled = false ) {
    // Accept either term ID or WP_Term object
    if ( is_object( $term ) && isset( $term->term_id ) ) {
        $term_id = $term->term_id;
        $term_obj = $term;
    } else {
        $term_id = $term;
        $term_obj = htax_get_valid_term( $term_id );
    }

    if ( ! $term_id || ! $term_obj ) {
        return array();
    }

    if ( ! $include_redirect_data && htax_is_redirect( $term_id )
         && htax_taxonomy_has_feature( $term_obj->taxonomy, 'redirects' ) ) {
        return array();
    }

    if ( ! $include_disabled && ! htax_taxonomy_has_feature( $term_obj->taxonomy, 'term_relationships' ) ) {
        return array();
    }

    // Check cache first
    $cache_key = 'htax_inc_' . $term_id;
    $cached    = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        htax_trace( "INC tid={$term_id} CACHE_HIT cnt=" . count( $cached ) );
        return $cached;
    }
    htax_trace( "INC tid={$term_id} DB_QUERY" );

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Single query to get all configured relationships for this term.
    // target_taxonomy is constrained to same taxonomy or 'group' — cross-taxonomy rows are ignored.
    // Expands both core types to include all user-defined mapped slugs.
    $includes_in = htax_relationship_type_sql_in( 'includes' );
    $excludes_in = htax_relationship_type_sql_in( 'excludes' );
    $relationships = $wpdb->get_results( $wpdb->prepare(
        "SELECT target_term_id, target_taxonomy, relationship_type
        FROM {$table_name}
        WHERE source_term_id = %d
        AND ({$includes_in} OR {$excludes_in})
        AND target_taxonomy IN (%s, 'group')",
        $term_id,
        $term_obj->taxonomy
    ), ARRAY_A );

    $groups_to_include = array();
    $terms_to_include  = array();
    $terms_to_exclude  = array();

    $includes_slugs = htax_get_relationship_type_slugs( 'includes' );

    foreach ( $relationships as $rel ) {
        $is_group   = ( 'group' === $rel['target_taxonomy'] );
        $is_include = in_array( $rel['relationship_type'], $includes_slugs, true );

        if ( $is_group && $is_include ) {
            $groups_to_include[] = (int) $rel['target_term_id'];
        } elseif ( ! $is_group && $is_include ) {
            $terms_to_include[] = (int) $rel['target_term_id'];
        } elseif ( ! $is_group && ! $is_include ) {
            $terms_to_exclude[] = (int) $rel['target_term_id'];
        }
    }

    // Build inclusion set
    $result_ids = array();

    // Included groups → expand to member terms (with subgroup hierarchy).
    if ( ! empty( $groups_to_include ) ) {
        $group_member_terms = htax_get_terms_in_groups( $groups_to_include, $term_obj->taxonomy );
        if ( ! empty( $group_member_terms ) ) {
            $result_ids = array_keys( $group_member_terms );
        }
    }

    // Directly included terms
    if ( ! empty( $terms_to_include ) ) {
        $result_ids = array_unique( array_merge( $result_ids, $terms_to_include ) );
    }

    // Container group inclusions: terms that have this term's member groups (or their subgroups) as container groups.
    // Expand member groups downward: being a member of a parent group means container group
    // relationships on any subgroup of that parent also apply.
    $member_groups = htax_get_term_group_ids( $term_id );
    if ( ! empty( $member_groups ) ) {
        $expanded_member_groups = htax_get_group_hierarchy( $member_groups );
        $container_included = htax_get_terms_with_container_groups( $expanded_member_groups, $term_obj->taxonomy );
        if ( ! empty( $container_included ) ) {
            $result_ids = array_unique( array_merge( $result_ids, $container_included ) );
        }
    }

    // Apply exclusions: direct excludes + self + ancestors + descendants.
    // Ancestors are excluded to prevent semantic loops (A including its own parent would
    // expand the archive far beyond A's intended scope).
    // Descendants are excluded because they are already in the archive via WP's native
    // include_children behavior — an explicit HT row for a descendant is redundant and
    // would overlap with the native hierarchy rather than expressing a cross-branch
    // relationship. Both exclusions keep the HT inclusion set strictly cross-branch.
    $never_include = array( $term_id );
    if ( is_taxonomy_hierarchical( $term_obj->taxonomy ) ) {
        $ancestor_ids = get_ancestors( $term_id, $term_obj->taxonomy, 'taxonomy' );
        if ( ! empty( $ancestor_ids ) ) {
            $never_include = array_merge( $never_include, $ancestor_ids );
        }
        $descendant_ids = get_term_children( $term_id, $term_obj->taxonomy );
        if ( ! is_wp_error( $descendant_ids ) && ! empty( $descendant_ids ) ) {
            $never_include = array_merge( $never_include, $descendant_ids );
        }
    }

    $all_exclusions = array_unique( array_merge( $terms_to_exclude, $never_include ) );

    if ( ! empty( $result_ids ) ) {
        $result_ids = array_diff( $result_ids, $all_exclusions );
    }

    if ( empty( $result_ids ) ) {
        htax_set_transient( $cache_key, array(), 0 );
        return array();
    }

    // Convert to WP_Term objects
    // Batch fetch all included terms in single query to avoid N+1 problem
    $term_objects = array();
    $fetched_terms = get_terms( array(
        'taxonomy' => $term_obj->taxonomy,
        'include'  => $result_ids,
        'hide_empty' => false,
    ) );

    if ( ! is_wp_error( $fetched_terms ) ) {
        foreach ( $fetched_terms as $included_term ) {
            $term_objects[ $included_term->term_id ] = $included_term;
        }
    }

    // Cache result — no TTL; rely on explicit invalidation via htax_delete_term_transients().
    htax_set_transient( $cache_key, $term_objects, 0 );
    htax_trace( "INC tid={$term_id} DB_DONE cnt=" . count( $term_objects ) );

    return $term_objects;
}

/**
 * Get container terms for a given term
 *
 * Containers are terms that include this term in their archives.
 * Mirrors htax_get_included_terms() structure: inclusions built first, exclusions applied last.
 *
 * Inclusions (three paths):
 * 1. Direct containers — terms that directly include this term
 * 2. Group inclusion — this term is member of GroupY, find terms that include GroupY
 * 3. Container group members — this term has container_group X; X is expanded upward to
 *    ancestor groups, then all members of those groups are included as containers
 *
 * Exclusions (applied last):
 * - Self + descendants (descendants cannot be containers; they can't include their ancestors)
 * - Containers that explicitly exclude this term (applies to Path 2)
 *
 * Returns an empty array when the term's taxonomy has the term_relationships feature
 * disabled — a disabled taxonomy has no active relationships for any consumer. The
 * cached data itself is feature-independent; the gate is applied on read, so toggling
 * the feature needs no cache rebuild.
 *
 * @param int|WP_Term $term             Term ID or WP_Term object
 * @param bool        $include_disabled When true, return data even if term_relationships is disabled
 *                                      for the taxonomy (search index only).
 * @return WP_Term[] Array of WP_Term objects indexed by term_id
 */
function htax_get_container_terms( $term, bool $include_disabled = false ) {
    // Accept either term ID or WP_Term object
    if ( is_object( $term ) && isset( $term->term_id ) ) {
        $term_id = $term->term_id;
        $term_obj = $term;
    } else {
        $term_id = $term;
        $term_obj = htax_get_valid_term( $term_id );
    }

    if ( ! $term_id || ! $term_obj ) {
        return array();
    }

    if ( ! $include_disabled && ! htax_taxonomy_has_feature( $term_obj->taxonomy, 'term_relationships' ) ) {
        return array();
    }

    // Check cache first (lazy-load pattern - no eager reload)
    $cache_key = 'htax_cont_' . $term_id;
    $cached    = htax_get_transient( $cache_key );

    if ( false !== $cached ) {
        htax_trace( "CONT tid={$term_id} CACHE_HIT cnt=" . count( $cached ) );
        return $cached;
    }
    htax_trace( "CONT tid={$term_id} DB_QUERY" );

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Path 3: Container group members — pre-compute in PHP so we can expand container groups
    // upward to ancestors. If B's container group is GroupChild, members of GroupParent
    // (ancestor of GroupChild) are also containers for B.
    $container_group_ids = htax_get_reverse_rel_group_ids( $term_id, 'includes' );
    $path3_fragment = '';
    $path3_params   = array();
    if ( ! empty( $container_group_ids ) ) {
        $expanded_container_groups = htax_get_group_ancestors( $container_group_ids );
        $cg_placeholders = htax_sql_placeholders( count( $expanded_container_groups ) );
        $path3_fragment = "
            UNION

            -- Path 3: Container group members (with ancestor group expansion)
            SELECT source_term_id AS container_term_id, source_taxonomy AS container_taxonomy
            FROM {$table_name}
            WHERE target_term_id IN ({$cg_placeholders})
            AND relationship_type = 'member_of'
            AND source_taxonomy = %s
            AND target_taxonomy = 'group'
        ";
        $path3_params = array_merge( $expanded_container_groups, array( $term_obj->taxonomy ) );
    }

    // Exclude self + descendants + ancestors.
    // Descendants cannot be containers for A because htax_get_included_terms() now
    // excludes ancestors, so no descendant of A can have an include row pointing to A.
    // Ancestors are excluded for symmetry with htax_get_included_terms: a term should
    // not appear as a container for its own ancestor, since ancestors already include
    // its posts via WP's native include_children. Both exclusions keep the container
    // set strictly cross-branch, matching skos:related semantics.
    $descendant_ids = array();
    $ancestor_ids   = array();
    if ( is_taxonomy_hierarchical( $term_obj->taxonomy ) ) {
        $children = get_term_children( $term_id, $term_obj->taxonomy );
        if ( ! is_wp_error( $children ) ) {
            $descendant_ids = $children;
        }
        $ancestor_ids = get_ancestors( $term_id, $term_obj->taxonomy, 'taxonomy' );
    }
    $excluded_ids          = array_merge( array( $term_id ), $descendant_ids, $ancestor_ids );
    $excluded_placeholders = htax_sql_placeholders( count( $excluded_ids ) );

    // Query: direct containers + group inclusion containers + container group members.
    // Mirrors htax_get_included_terms order: direct, group-based, container-group-based.
    // Path 1 - Direct: source=A, target=B, type='includes' → A is container of B
    // Path 2 - Group inclusion: B is member of GroupY, find terms that include GroupY
    // Path 3 - Container group: B has container_group X (+ ancestors), find members of those groups
    $includes_in = htax_relationship_type_sql_in( 'includes' );

    $query = "
        SELECT DISTINCT container_term_id, container_taxonomy
        FROM (
            -- Path 1: Direct containers (terms that directly include this term)
            SELECT source_term_id AS container_term_id, source_taxonomy AS container_taxonomy
            FROM {$table_name}
            WHERE target_term_id = %d
            AND {$includes_in}
            AND source_taxonomy = target_taxonomy
            AND target_taxonomy != 'group'

            UNION

            -- Path 2: Group inclusion (this term is member of groups that other terms include)
            SELECT r1.source_term_id AS container_term_id, r1.source_taxonomy AS container_taxonomy
            FROM {$table_name} AS r1
            INNER JOIN {$table_name} AS r2
                ON r1.target_term_id = r2.target_term_id
                AND r1.target_taxonomy = 'group'
                AND r1.{$includes_in}
                AND r1.source_taxonomy = %s
            WHERE r2.source_term_id = %d
            AND r2.relationship_type = 'member_of'
            AND r2.source_taxonomy = %s
            AND r2.target_taxonomy = 'group'
            {$path3_fragment}
        ) AS all_containers
        WHERE container_term_id NOT IN ({$excluded_placeholders})
        AND NOT EXISTS (
            SELECT 1 FROM {$table_name} AS excl
            WHERE excl.source_term_id = container_term_id
            AND excl.target_term_id = %d
            AND excl.relationship_type = 'excludes'
            AND excl.source_taxonomy = excl.target_taxonomy
            AND excl.target_taxonomy != 'group'
        )
        AND NOT EXISTS (
            SELECT 1 FROM {$table_name} AS redir
            WHERE redir.source_term_id = container_term_id
            AND redir.relationship_type = 'redirect'
        )
    ";

    $query_params = array_merge(
        array(
            $term_id,                    // Path 1: target_term_id
            $term_obj->taxonomy,         // Path 2: r1.source_taxonomy (source term's taxonomy)
            $term_id,                    // Path 2: r2.source_term_id
            $term_obj->taxonomy,         // Path 2: r2.source_taxonomy (term's taxonomy)
        ),
        $path3_params,                   // Path 3: expanded container group IDs + taxonomy (may be empty)
        $excluded_ids,                   // WHERE NOT IN: self + ancestors
        array( $term_id )                // NOT EXISTS: excl.target_term_id
    );
    $results = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

    if ( empty( $results ) ) {
        // Cache empty result — no TTL; rely on explicit invalidation via htax_delete_term_transients().
        htax_set_transient( $cache_key, array(), 0 );
        return array();
    }

    // Convert to WP_Term objects
    // All containers guaranteed to be from same taxonomy (filtered in query)
    $term_objects = array();
    $container_ids = array_column( $results, 'container_term_id' );
    $container_ids = array_map( 'intval', $container_ids );

    // Batch fetch all container terms in single query (all same taxonomy)
    $fetched_terms = get_terms( array(
        'taxonomy'   => $term_obj->taxonomy,
        'include'    => $container_ids,
        'hide_empty' => false,
    ) );

    if ( ! is_wp_error( $fetched_terms ) ) {
        foreach ( $fetched_terms as $container_term ) {
            $term_objects[ $container_term->term_id ] = $container_term;
        }
    }

    // Cache result — no TTL; rely on explicit invalidation via htax_delete_term_transients().
    htax_set_transient( $cache_key, $term_objects, 0 );
    htax_trace( "CONT tid={$term_id} DB_DONE cnt=" . count( $term_objects ) );

    return $term_objects;
}


/**
 * Get synonym terms for a term.
 *
 * Returns all terms that have a bidirectional includes relationship with the given term —
 * i.e. terms B where both (A → includes → B) and (B → includes → A) exist. These terms
 * contribute each other's posts to their respective archives and are effectively synonyms.
 *
 * Intended for display and UI use cases. For archive expansion, htax_get_included_terms()
 * is sufficient and does not require the relationship to be bidirectional.
 *
 * @param int|WP_Term $term Term ID or WP_Term object.
 * @return WP_Term[] Array of WP_Term objects indexed by term_id.
 */
function htax_get_synonym_terms( $term, bool $include_redirect_data = false ) {
    if ( is_object( $term ) && isset( $term->term_id ) ) {
        $term_id  = $term->term_id;
        $term_obj = $term;
    } else {
        $term_id  = $term;
        $term_obj = htax_get_valid_term( $term_id );
    }

    if ( ! $term_id || ! $term_obj ) {
        return array();
    }

    // A redirect term has no active inclusions, so it cannot have synonyms.
    if ( ! $include_redirect_data && htax_is_redirect( $term_id )
         && htax_taxonomy_has_feature( $term_obj->taxonomy, 'redirects' ) ) {
        return array();
    }

    // Synonyms are inclusions; none are active while term_relationships is disabled.
    if ( ! htax_taxonomy_has_feature( $term_obj->taxonomy, 'term_relationships' ) ) {
        return array();
    }

    // Two-level cache: per-request (handles repeated calls within one request)
    // then transient (TTL=0, persists across requests). Both are invalidated by
    // htax_delete_term_transients().
    $static_cache = &htax_request_cache( 'synonyms' );

    if ( isset( $static_cache[ $term_id ] ) ) {
        return $static_cache[ $term_id ];
    }

    $cache_key = 'htax_syno_' . $term_id;
    $cached    = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        $static_cache[ $term_id ] = $cached;
        return $cached;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Find terms B where A includes B (r1) and B includes A (r2).
    // Exclude group taxonomy on both sides — synonyms are vocabulary terms only.
    $includes_in = htax_relationship_type_sql_in( 'includes' );

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT r1.target_term_id AS synonym_term_id
             FROM {$table_name} AS r1
             INNER JOIN {$table_name} AS r2
                 ON r1.target_term_id  = r2.source_term_id
                 AND r2.target_term_id = r1.source_term_id
                 AND r2.{$includes_in}
                 AND r2.source_taxonomy != 'group'
                 AND r2.target_taxonomy != 'group'
             WHERE r1.source_term_id    = %d
             AND r1.{$includes_in}
             AND r1.source_taxonomy    != 'group'
             AND r1.target_taxonomy    != 'group'",
            $term_id
        ),
        ARRAY_A
    );

    if ( empty( $results ) ) {
        htax_set_transient( $cache_key, array(), 0 );
        $static_cache[ $term_id ] = array();
        return array();
    }

    $synonym_ids = array_map( 'intval', array_column( $results, 'synonym_term_id' ) );

    // Strip redirect terms — their stored rows are preserved but they have no active inclusions.
    if ( htax_taxonomy_has_feature( $term_obj->taxonomy, 'redirects' ) ) {
        $redirect_ids = htax_get_all_redirect_term_ids();
        if ( ! empty( $redirect_ids ) ) {
            $synonym_ids = array_values( array_diff( $synonym_ids, $redirect_ids ) );
        }
    }

    if ( empty( $synonym_ids ) ) {
        htax_set_transient( $cache_key, array(), 0 );
        $static_cache[ $term_id ] = array();
        return array();
    }

    $fetched_terms = get_terms( array(
        'taxonomy'   => $term_obj->taxonomy,
        'include'    => $synonym_ids,
        'hide_empty' => false,
    ) );

    $term_objects = array();
    if ( ! is_wp_error( $fetched_terms ) ) {
        foreach ( $fetched_terms as $synonym_term ) {
            $term_objects[ $synonym_term->term_id ] = $synonym_term;
        }
    }

    htax_set_transient( $cache_key, $term_objects, 0 );
    $static_cache[ $term_id ] = $term_objects;

    return $term_objects;
}


/**
 * Get direct forward target IDs for a relationship type (non-group targets only).
 *
 * Returns the raw T→U rows for the given type slug, excluding group targets.
 * Cached as `htax_direct_rel_{slug}_{term_id}`.
 *
 * Valid for 'includes', 'excludes', and non-core types. Do NOT pass 'member_of'
 * (always T→G, use htax_get_term_groups()) or 'redirect' (use htax_get_redirect()).
 *
 * @since 1.0.0
 * @param int    $term_id Term ID.
 * @param string $slug    Relationship type slug (e.g. 'includes').
 * @return int[] Array of directly targeted term IDs.
 */
function htax_get_direct_rel_term_ids( int $term_id, string $slug ): array {
    if ( 'member_of' === $slug || 'redirect' === $slug ) {
        _doing_it_wrong( __FUNCTION__, "Use the dedicated API for '{$slug}' relationships.", '1.0.0' );
        return array();
    }
    $cached = htax_get_transient( 'htax_direct_rel_' . $slug . '_' . $term_id );
    if ( false !== $cached ) {
        return $cached;
    }
    $result = htax_get_term_relationship_ids(
        $term_id,
        'target_term_id',
        'source_term_id',
        $slug,
        array( 'target_taxonomy' => HTAX_NOT_GROUP )
    );
    htax_set_transient( 'htax_direct_rel_' . $slug . '_' . $term_id, $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}

/**
 * Get direct reverse source IDs for a relationship type (non-group sources only).
 *
 * Returns the raw A→T rows for the given type slug, excluding group sources.
 * Cached as `htax_reverse_rel_{slug}_{term_id}`.
 *
 * Valid for 'includes', 'excludes', and non-core types. Do NOT pass 'member_of'
 * or 'redirect' — use their dedicated APIs instead.
 *
 * @since 1.0.0
 * @param int    $term_id Term ID.
 * @param string $slug    Relationship type slug (e.g. 'includes').
 * @return int[] Array of terms that have a direct relationship of this type pointing to this term.
 */
function htax_get_reverse_rel_term_ids( int $term_id, string $slug ): array {
    if ( 'member_of' === $slug || 'redirect' === $slug ) {
        _doing_it_wrong( __FUNCTION__, "Use the dedicated API for '{$slug}' relationships.", '1.0.0' );
        return array();
    }
    $cached = htax_get_transient( 'htax_reverse_rel_' . $slug . '_' . $term_id );
    if ( false !== $cached ) {
        return $cached;
    }
    $result = htax_get_term_relationship_ids(
        $term_id,
        'source_term_id',
        'target_term_id',
        $slug,
        array( 'source_taxonomy' => HTAX_NOT_GROUP )
    );
    htax_set_transient( 'htax_reverse_rel_' . $slug . '_' . $term_id, $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}

/**
 * Get direct forward group target IDs for a relationship type (group targets only).
 *
 * Returns the raw T→G rows for the given type slug where the target is a group term.
 * Cached as `htax_direct_rel_grp_{slug}_{term_id}`.
 *
 * Valid for 'includes', 'excludes', and non-core types. Do NOT pass 'member_of'
 * (use htax_get_term_groups()) or 'redirect' (use htax_get_redirect()).
 *
 * @since 1.0.0
 * @param int    $term_id Term ID.
 * @param string $slug    Relationship type slug (e.g. 'includes').
 * @return int[] Array of directly targeted group IDs.
 */
function htax_get_direct_rel_group_ids( int $term_id, string $slug ): array {
    if ( 'member_of' === $slug || 'redirect' === $slug ) {
        _doing_it_wrong( __FUNCTION__, "Use the dedicated API for '{$slug}' relationships.", '1.0.0' );
        return array();
    }
    $cached = htax_get_transient( 'htax_direct_rel_grp_' . $slug . '_' . $term_id );
    if ( false !== $cached ) {
        return $cached;
    }
    $result = htax_get_term_relationship_ids(
        $term_id,
        'target_term_id',
        'source_term_id',
        $slug,
        array( 'target_taxonomy' => 'group' )
    );
    htax_set_transient( 'htax_direct_rel_grp_' . $slug . '_' . $term_id, $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}

/**
 * Get direct reverse group source IDs for a relationship type (group sources only).
 *
 * Returns the raw G→T rows for the given type slug where the source is a group term.
 * Cached as `htax_reverse_rel_grp_{slug}_{term_id}`.
 *
 * Valid for 'includes', 'excludes', and non-core types. Do NOT pass 'member_of'
 * or 'redirect' — use their dedicated APIs instead.
 *
 * @since 1.0.0
 * @param int    $term_id Term ID.
 * @param string $slug    Relationship type slug (e.g. 'includes').
 * @return int[] Array of group IDs that have a direct relationship of this type pointing to this term.
 */
function htax_get_reverse_rel_group_ids( int $term_id, string $slug ): array {
    if ( 'member_of' === $slug || 'redirect' === $slug ) {
        _doing_it_wrong( __FUNCTION__, "Use the dedicated API for '{$slug}' relationships.", '1.0.0' );
        return array();
    }
    $cached = htax_get_transient( 'htax_reverse_rel_grp_' . $slug . '_' . $term_id );
    if ( false !== $cached ) {
        return $cached;
    }
    $result = htax_get_term_relationship_ids(
        $term_id,
        'source_term_id',
        'target_term_id',
        $slug,
        array( 'source_taxonomy' => 'group' )
    );
    htax_set_transient( 'htax_reverse_rel_grp_' . $slug . '_' . $term_id, $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}


/**
 * Get excluded individual term IDs for a term.
 *
 * Returns term IDs that should be removed from the included set.
 * Does not affect native hierarchical relationships.
 *
 * Returns an empty array when the term is a redirect unless $include_redirect_data is true.
 *
 * @param int  $term_id              Term ID to get exclusions for.
 * @param bool $include_redirect_data When true, return data even if the term is a redirect.
 * @return array Array of excluded term IDs
 */
function htax_get_excluded_term_ids( $term_id, bool $include_redirect_data = false ) {
    if ( ! $include_redirect_data && htax_is_redirect( $term_id ) ) {
        $term_for_check = get_term( $term_id );
        if ( $term_for_check instanceof WP_Term
             && htax_taxonomy_has_feature( $term_for_check->taxonomy, 'redirects' ) ) {
            return array();
        }
    }

    $cached = htax_get_transient( 'htax_excl_' . $term_id );
    if ( false !== $cached ) {
        return $cached;
    }
    $result = htax_get_term_relationship_ids(
        $term_id,
        'target_term_id',
        'source_term_id',
        'excludes',
        array( 'target_taxonomy' => HTAX_NOT_GROUP )
    );
    htax_set_transient( 'htax_excl_' . $term_id, $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}

/**
 * Get group ancestors (expand hierarchy upward)
 *
 * Given a group or array of groups, returns all parent groups up the hierarchy.
 * Uses WordPress native get_ancestors() since 'group' taxonomy is hierarchical.
 *
 * @param int|array $group_ids Group ID(s) to expand upward
 * @return array Flattened array of group IDs (includes input groups)
 */
function htax_get_group_ancestors( $group_ids ) {
    if ( empty( $group_ids ) ) {
        return array();
    }

    if ( ! is_array( $group_ids ) ) {
        $group_ids = array( $group_ids );
    }

    $all_groups = $group_ids;

    // Get ancestors for each group using native WordPress function
    foreach ( $group_ids as $group_id ) {
        $ancestors = get_ancestors( $group_id, 'group', 'taxonomy' );
        if ( ! empty( $ancestors ) ) {
            $all_groups = array_merge( $all_groups, $ancestors );
        }
    }

    return array_unique( $all_groups );
}

/**
 * Get terms that have any of the specified groups as container groups
 *
 * Finds terms where the given groups are set as container groups.
 * These terms "want" to be included in members of those groups.
 * In the normalized architecture: source=GroupX, target=TermA means TermA has GroupX as container.
 *
 * @param array  $group_ids Array of group IDs to check
 * @param string $taxonomy  Taxonomy to filter by
 * @return array Array of term IDs that have these groups as containers
 */
function htax_get_terms_with_container_groups( $group_ids, $taxonomy ) {
    if ( empty( $group_ids ) ) {
        return array();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Query for all groups at once
    $placeholders = htax_sql_placeholders( count( $group_ids ) );

    $includes_in = htax_relationship_type_sql_in( 'includes' );

    $query = "SELECT DISTINCT target_term_id
              FROM {$table_name}
              WHERE source_term_id IN ({$placeholders})
                AND {$includes_in}
                AND source_taxonomy = 'group'
                AND target_taxonomy = %s";

    $params = array_merge( $group_ids, array( $taxonomy ) );
    $term_ids = $wpdb->get_col( $wpdb->prepare( $query, ...$params ) );

    return array_map( 'intval', $term_ids );
}

// ============================================================================
// SECTION 2: RELATIONSHIP MANAGEMENT
// ============================================================================
// Removing relationship rows that conflict with the WordPress hierarchy.
// ============================================================================

/**
 * Delete every direct inclusion and exclusion row that places an ancestor or
 * descendant of $term_id in the term's inclusion/container/exclusion set.
 *
 * Called from:
 *   - htax_cleanup_hierarchy_conflicts()  after a term is reparented
 *   - htax_merge_terms()                  after the merge may have introduced new conflicts
 *   - htax_repair_hierarchy_conflicts()   during activation to catch drift while inactive
 *
 * Does not fire htax_includes_changed — callers must handle cache invalidation.
 *
 * @since 1.0.0
 * @param int    $term_id  Term whose conflict rows to remove.
 * @param string $taxonomy Taxonomy slug (must be hierarchical).
 * @return bool  True if any rows were removed.
 */
function htax_remove_hierarchy_conflict_rows( int $term_id, string $taxonomy ): bool {
    $ancestors   = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
    $descendants = get_term_children( $term_id, $taxonomy );
    $relatives   = array_unique( array_merge(
        $ancestors,
        is_wp_error( $descendants ) ? array() : $descendants
    ) );

    if ( empty( $relatives ) ) {
        return false;
    }

    global $wpdb;
    $table       = $wpdb->prefix . 'htax_term_relationships';
    $includes_in = htax_relationship_type_sql_in( 'includes' );
    $excludes_in = htax_relationship_type_sql_in( 'excludes' );

    $removed = false;
    foreach ( $relatives as $relative_id ) {
        foreach ( array( array( $relative_id, $term_id ), array( $term_id, $relative_id ) ) as [ $src, $tgt ] ) {
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE source_term_id = %d AND target_term_id = %d AND {$includes_in}",
                    $src,
                    $tgt
                )
            );
            if ( $deleted > 0 ) {
                $removed = true;
                htax_delete_term_transients( $src );
                htax_delete_term_transients( $tgt );
            }
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE source_term_id = %d AND target_term_id = %d AND {$excludes_in}",
                    $src,
                    $tgt
                )
            );
            if ( $deleted > 0 ) {
                $removed = true;
                htax_delete_term_transients( $src );
                htax_delete_term_transients( $tgt );
            }
        }
    }
    return $removed;
}

// ============================================================================
// SECTION 3: REDIRECT MANAGEMENT
// ============================================================================
// Functions for managing redirects:
// - htax_remove_redirect() - Remove redirect status from term
// - htax_set_redirect() - Set or update redirect, auto-resolves chains to ultimate target
// ============================================================================

/**
 * Remove redirect status from term
 *
 * Removes redirect relationship from the htax_term_relationships table.
 *
 * @param int $term_id Term ID to remove redirect from
 * @return bool True on success, false on failure
 */
function htax_remove_redirect( $term_id ) {
    // Get old redirect target before removing (for action hook)
    $old_target = htax_get_redirect_target( $term_id );

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    $wpdb->delete(
        $table_name,
        array(
            'source_term_id' => $term_id,
            'relationship_type' => 'redirect',
        ),
        array( '%d', '%s' )
    );

    // Clear auto-redirect metadata (used by term conversion feature)
    delete_term_meta( $term_id, '_htax_auto_redirect' );

    // Eager reload redirect cache BEFORE firing action (reload will set to no redirect)
    // This ensures index handlers read NEW state from cache
    if ( $old_target ) {
        htax_clear_redirect_target_cache( (int) $term_id );
        htax_get_redirect_target( $term_id ); // Will return false (no redirect)

        // Fire action for cache invalidation and search index updates
        // Cache now contains NEW state (no redirect) - index handlers can use it
        $term = get_term( $term_id );
        if ( $term && ! is_wp_error( $term ) ) {
            do_action( 'htax_redirect_removed', $term_id, $term->taxonomy, $old_target->term_id, $old_target->taxonomy );
        }
    }

    return true;
}


/**
 * Set redirect for a term
 *
 * Automatically resolves redirect chains by following the target to the ultimate
 * non-redirect term. Prevents circular redirects and self-redirects.
 *
 * @param int|WP_Term $term Source term ID or WP_Term object
 * @param int|WP_Term $target Target term ID or WP_Term object (if redirect, follows chain)
 * @return bool True on success, false on failure (circular redirect, self-redirect, or invalid)
 */
function htax_set_redirect( $term, $target ) {
    // Accept either term ID or WP_Term object for source
    if ( is_object( $term ) && isset( $term->term_id ) ) {
        $term_obj = $term;
        $term_id = $term->term_id;
    } else {
        $term_id = $term;
        $term_obj = htax_get_valid_term( $term_id );
    }

    if ( ! $term_obj ) {
        return false;
    }

    // Accept either term ID or WP_Term object for target
    if ( is_object( $target ) && isset( $target->term_id ) ) {
        $target_term = $target;
        $target_id = $target->term_id;
    } else {
        $target_id = $target;
        $target_term = htax_get_valid_term( $target_id );
    }

    if ( ! $target_term ) {
        return false;
    }

    // Prevent self-redirect
    if ( (int) $term_id === (int) $target_id ) {
        htax_log_debug( 'Cannot redirect term to itself', array(
            'term_id' => $term_id,
            'target_id' => $target_id
        ) );
        return false;
    }

    // Resolve redirect chains: follow chain to ultimate non-redirect target
    $ultimate_target = $target_id;
    $seen_ids = array( $term_id ); // Track visited IDs to detect circular redirects
    $max_depth = HTAX_MAX_REDIRECT_DEPTH;
    $depth = 0;

    while ( htax_is_redirect( $ultimate_target ) && $depth < $max_depth ) {
        if ( in_array( $ultimate_target, $seen_ids, true ) ) {
            // Circular redirect detected in chain
            htax_log_error( 'Circular redirect chain detected', array(
                'term_id' => $term_id,
                'original_target' => $target_id,
                'chain' => $seen_ids
            ) );
            return false;
        }

        $seen_ids[] = $ultimate_target;
        $next_target = htax_get_redirect_target( $ultimate_target );

        if ( ! $next_target ) {
            break;
        }

        $ultimate_target = $next_target->term_id;
        $depth++;
    }

    if ( $depth >= $max_depth ) {
        htax_log_error( 'Redirect chain too deep (max 10 levels)', array(
            'term_id' => $term_id,
            'original_target' => $target_id,
            'chain_length' => $depth
        ) );
        return false;
    }

    // Check if ultimate target resolves to self (would create circular redirect)
    if ( (int) $ultimate_target === (int) $term_id ) {
        // AUTO-RESOLVE: Instead of rejecting, remove the conflicting redirect
        // Example: A → B exists, user sets B → A
        // Solution: Remove A's redirect, allow B → A, main cascade updates Y → B to Y → A

        htax_log_debug( 'Circular redirect detected - auto-resolving by removing conflicting redirect', array(
            'term_id' => $term_id,
            'original_target' => $target_id,
            'action' => 'removing redirect from term being redirected to'
        ) );

        // Remove the conflicting redirect from the target term
        // This makes target_id a normal term, breaking the circular chain
        htax_remove_redirect( $target_id );

        // After removing the redirect, target_id is now a normal term
        // Set ultimate_target to target_id (no more chain to follow)
        $ultimate_target = $target_id;

        // Clear the chain tracking since we've resolved the circular issue
        $seen_ids = array( $term_id );
        $depth = 0;
    }

    // Use ultimate target if we followed a chain
    if ( $ultimate_target !== $target_id ) {
        htax_log_debug( 'Resolved redirect chain to ultimate target', array(
            'term_id' => $term_id,
            'original_target' => $target_id,
            'ultimate_target' => $ultimate_target,
            'chain_length' => $depth
        ) );

        // Update target_id and target_term to ultimate target
        $target_id = $ultimate_target;
        $target_term = htax_get_valid_term( $target_id );

        if ( ! $target_term ) {
            htax_log_error( 'Ultimate redirect target is invalid', array(
                'term_id' => $term_id,
                'ultimate_target' => $ultimate_target
            ) );
            return false;
        }
    }

    // Get old redirect target before changing (for action hook)
    $old_target = htax_get_redirect_target( $term_id );

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Delete old redirect
    $wpdb->delete(
        $table_name,
        array(
            'source_term_id' => $term_id,
            'relationship_type' => 'redirect',
        ),
        array( '%d', '%s' )
    );

    // Insert new redirect
    $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$table_name}
        (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
        VALUES (%d, %d, %s, %s, %s)",
        $term_id,
        $target_id,
        'redirect',
        $term_obj->taxonomy,
        $target_term->taxonomy
    ) );

    // CASCADE: Update any terms that were redirecting TO this term
    // If X → A and we just set A → B, update X to redirect to B instead
    // This prevents redirect chains from forming in the database
    $incoming_redirects = htax_get_ids_of_redirects_to( $term_id );
    if ( ! empty( $incoming_redirects ) ) {
        htax_log_debug( 'Cascading redirect update to incoming redirects', array(
            'term_id' => $term_id,
            'new_target_id' => $target_id,
            'incoming_redirect_count' => count( $incoming_redirects ),
            'incoming_redirect_ids' => $incoming_redirects
        ) );

        foreach ( $incoming_redirects as $source_id ) {
            // Skip if the incoming redirect is the target itself (prevent circular)
            if ( (int) $source_id === (int) $target_id ) {
                htax_log_debug( 'Skipping cascade to target term itself', array(
                    'source_id' => $source_id,
                    'target_id' => $target_id
                ) );
                continue;
            }

            $source_term = htax_get_valid_term( $source_id );
            if ( $source_term ) {
                // Recursively call htax_set_redirect - this will:
                // 1. Resolve any chain if target_term is itself a redirect
                // 2. Handle circular detection
                // 3. Update the database
                // 4. Fire appropriate actions
                htax_set_redirect( $source_term, $target_term );

                htax_log_debug( 'Cascaded redirect update', array(
                    'source_id' => $source_id,
                    'old_target_id' => $term_id,
                    'new_target_id' => $target_id
                ) );
            }
        }
    }

    // Eager reload redirect cache BEFORE firing action
    // This ensures index handlers read NEW state from cache
    htax_clear_redirect_target_cache( (int) $term_id );
    htax_get_redirect_target( $term_id );

    // Fire action for cache invalidation and search index updates
    // Cache now contains NEW state - index handlers can use it
    do_action( 'htax_redirect_changed', $term_id, $term_obj->taxonomy, $target_id, $target_term->taxonomy, $old_target );

    return true;
}

/**
 * Orchestrator function for saving all term metadata
 *
 * Handles validation once, then calls individual save functions.
 * This centralizes nonce checking, permission checking, and taxonomy validation
 * to avoid duplication across multiple save functions.
 *
 * @since 1.0.0
 * @param int $term_id Term ID
 * @param int $tt_id Term taxonomy ID (provided by WordPress hook)
 */
function htax_save_term_data( $term_id, $tt_id = 0 ) {
    // ============================================
    // SHARED VALIDATION (runs once for all saves)
    // ============================================

    // 1. Verify nonce
    // Only attempt nonce verification for recognized WP term-edit form submissions.
    // Programmatic calls (REST, WP-CLI, importers like wpcat2tag) don't send the
    // WP term-edit action and should pass through silently — they have nothing to save.
    // Note: Quick Edit uses action 'inline-save-tax' with _inline_edit nonce.
    $post_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
    if ( ! in_array( $post_action, array( 'editedtag', 'add-tag', 'inline-save-tax' ), true ) ) {
        return;
    }

    if ( ! htax_verify_term_nonce( $term_id ) ) {
        htax_log_debug( 'Nonce verification failed for term metadata save', array( 'term_id' => $term_id ) );
        htax_add_admin_notice( __( 'Security verification failed. Please refresh the page and try again.', 'hypertaxonomy' ), 'error' );
        return;
    }

    // 2. Get term and validate taxonomy
    // (needed to determine which features are enabled for this taxonomy)
    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        htax_log_error( 'Invalid term ID for metadata save', array( 'term_id' => $term_id ) );
        return;
    }

    $taxonomy = $term->taxonomy;

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        htax_log_debug( 'Invalid taxonomy for metadata save', array( 'taxonomy' => $taxonomy ) );
        return;
    }

    // 3. Determine which features will be saved and check corresponding permissions
    // Note: This orchestrator is only registered for taxonomies where HyperTaxonomy is enabled,
    // so we don't need to check enabled_taxonomies again. We only check feature-specific settings.
    $missing_caps = array();
    $features = htax_get_taxonomy_features( $taxonomy );

    // Check visibility permission (if visibility feature enabled and the submitted value would change it)
    // OR if archive settings data present (archive settings controlled by top-level HyperTaxonomy enable checkbox).
    // The visibility checkbox is paired with a hidden "0" input, so presence alone only means the
    // field was rendered; a permission is needed only when the stored value would actually change.
    $visibility_enabled = isset( $features['visibility'] ) && $features['visibility'];
    $has_visibility_data = isset( $_POST['htax_is_hidden'] )
        && ( '1' === sanitize_text_field( wp_unslash( $_POST['htax_is_hidden'] ) ) ) !== ( '1' === get_term_meta( $term_id, '_htax_is_hidden', true ) );
    $has_archive_data = isset( $_POST['htax_show_description'] ) ||
                       isset( $_POST['htax_posts_per_page'] ) ||
                       isset( $_POST['htax_max_sticky_posts'] );

    if ( ( $visibility_enabled && $has_visibility_data ) || $has_archive_data ) {
        if ( ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_visibility' ) ) {
            $missing_caps[] = __( 'Manage Term Visibility', 'hypertaxonomy' );
        }
    }

    // Check icons permission (if icons feature enabled and form data present)
    $icons_enabled = isset( $features['icons'] ) && $features['icons'];
    $has_icon_data = isset( $_POST['htax_icon_id'] );

    if ( $icons_enabled && $has_icon_data && ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_icons' ) ) {
        $missing_caps[] = __( 'Manage Term Icons', 'hypertaxonomy' );
    }

    // Check relationships permission (if relationships enabled and form data present)
    $redirects_enabled          = isset( $features['redirects'] ) && $features['redirects'];
    $term_relationships_enabled = isset( $features['term_relationships'] ) && $features['term_relationships'];

    // The redirect section's hidden htax_redirect_to input is always rendered with it; a
    // permission is needed only when the submitted redirect differs from the stored one.
    $has_redirect_data = false;
    if ( isset( $_POST['htax_redirect_to'] ) ) {
        $posted_redirect   = ( isset( $_POST['htax_is_redirect'] ) && '1' === wp_unslash( $_POST['htax_is_redirect'] ) )
            ? intval( wp_unslash( $_POST['htax_redirect_to'] ) )
            : 0;
        $stored_redirect   = htax_get_redirect_target( $term_id );
        $has_redirect_data = $posted_redirect !== ( $stored_redirect ? (int) $stored_redirect->term_id : 0 );
    }
    $has_inclusion_data = isset( $_POST['htax_includes'] ) || isset( $_POST['htax_containers'] );
    $has_group_data     = isset( $_POST['htax_incl_groups'] ) || isset( $_POST['htax_cont_groups'] );

    // Custom type tab fields (htax_cust_{slug}_fwd_terms / _rev_terms).
    $has_custom_rel_data = false;
    if ( $term_relationships_enabled ) {
        foreach ( htax_get_relationship_types_for_taxonomy( $taxonomy ) as $cslug => $cdef ) {
            if ( isset( $_POST[ 'htax_cust_' . $cslug . '_fwd_terms' ] ) || isset( $_POST[ 'htax_cust_' . $cslug . '_rev_terms' ] ) ) {
                $has_custom_rel_data = true;
                break;
            }
        }
    }

    $needs_relationship_permission = ( $redirects_enabled && $has_redirect_data ) ||
                                     ( $term_relationships_enabled && ( $has_inclusion_data || $has_group_data || $has_custom_rel_data ) );

    if ( $needs_relationship_permission && ! HyperTaxonomy_Capabilities::current_user_can( 'manage_term_relationships' ) ) {
        $missing_caps[] = __( 'Manage Term Relationships', 'hypertaxonomy' );
    }

    // If ANY required capability is missing, block ALL saves and show clear error
    if ( ! empty( $missing_caps ) ) {
        htax_log_debug( 'User lacks required permissions for term metadata save', array(
            'term_id' => $term_id,
            'missing_capabilities' => $missing_caps
        ) );

        htax_add_admin_notice(
            sprintf(
                __( 'You do not have the required permissions to edit term settings. Missing: %s', 'hypertaxonomy' ),
                implode( ', ', $missing_caps )
            ),
            'error'
        );
        return;
    }



    // ============================================
    // CALL INDIVIDUAL SAVE FUNCTIONS
    // ============================================
    // Each function is responsible for its own locking and error handling
    // Nonce and permission validation already done above

    // 1. Hidden term and archive settings (includes/admin/term-edit.php)
    htax_save_hidden_term( $term_id );
    htax_save_archive_settings( $term_id );

    // 2. Term icons (includes/admin/term-icons.php - class method)
    HyperTaxonomy_Term_Icons::get_instance()->save_term_icon( $term_id );

    // 3. Group membership is already saved by htax_save_term_groups() in admin/term-edit.php
    // which hooks into edited_{$taxonomy} and created_{$taxonomy} before this orchestrator runs.
    // No additional group membership saving needed here - it's already up-to-date in wp_htax_term_relationships table.

    // 4. Redirects and relationships (this file - term-relationships.php)
    // Group membership is already current, so we can safely sync group-based relationships
    htax_save_redirect( $term_id, $tt_id );
    // htax_save_term_relationships handles core types, group memberships, and custom types all in one
    // transaction, including their cache invalidation and change-detection for admin notices.
    htax_save_term_relationships( $term_id, $tt_id );
}

/**
 * Save redirect settings when a term is edited or created
 *
 * Handles the redirect checkbox and target term from the admin form.
 * Validates the redirect target and auto-resolves redirect chains.
 *
 * Called by htax_save_term_data() orchestrator.
 * Note: Nonce and permission checks are done by orchestrator.
 *
 * @since 1.0.0
 * @param int $term_id Term ID
 * @param int $tt_id Term taxonomy ID (provided by WordPress hook)
 */
function htax_save_redirect( $term_id, $tt_id = 0 ) {
    // Note: Validation (nonce, permissions, taxonomy) is done by htax_save_term_data() orchestrator

    // Get term and taxonomy
    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        htax_log_error( 'Invalid term ID for redirect save', array( 'term_id' => $term_id ) );
        return;
    }
    $taxonomy = $term->taxonomy;

    // The redirect section always renders the hidden htax_redirect_to input. When it is absent
    // (Quick Edit, redirects feature disabled), the form carried no redirect data — leave it as is.
    if ( ! isset( $_POST['htax_redirect_to'] ) ) {
        return;
    }

    // Database operations are atomic - no locking needed
    try {
        // Get redirect settings from POST
        $is_redirect =isset( $_POST['htax_is_redirect'] ) && '1' === wp_unslash( $_POST['htax_is_redirect'] );
        $redirect_to = isset( $_POST['htax_redirect_to'] ) ? intval( wp_unslash( $_POST['htax_redirect_to'] ) ) : 0;
        $redirect_taxonomy = isset( $_POST['htax_redirect_taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['htax_redirect_taxonomy'] ) ) : $taxonomy;

        // Get old redirect value from database
        $old_redirect_term = htax_get_redirect_target( $term_id );
        $old_redirect_to = $old_redirect_term ? $old_redirect_term->term_id : false;
        $old_is_redirect = ! empty( $old_redirect_to );

        // Early return when nothing has changed — avoids DB writes, cache busts, and action fires.
        if ( ! $is_redirect && ! $old_is_redirect ) {
            return;
        }
        if ( $is_redirect && $redirect_to > 0 && (int) $redirect_to === (int) $old_redirect_to ) {
            return;
        }

        htax_log_debug( 'Saving redirect', array(
            'term_id' => $term_id,
            'taxonomy' => $taxonomy,
            'is_redirect' => $is_redirect,
            'redirect_to' => $redirect_to,
            'old_redirect_to' => $old_redirect_to
        ) );

        // Handle redirect logic
        if ( $is_redirect ) {
            // Basic validation: not empty, not self-redirect
            if ( ! $redirect_to || $redirect_to === $term_id ) {
                htax_log_debug( 'Invalid redirect: empty or self-redirect', array(
                    'term_id' => $term_id,
                    'redirect_to' => $redirect_to
                ) );

                htax_add_admin_notice( __( 'Invalid redirect: cannot redirect to self or empty target.', 'hypertaxonomy' ), 'warning' );
            } else {
                // Verify target exists in the selected taxonomy
                $target = htax_get_valid_term( $redirect_to );
                if ( ! $target ) {
                    htax_add_admin_notice( __( 'Invalid redirect target.', 'hypertaxonomy' ), 'error' );

                    htax_log_error( 'Invalid redirect target', array(
                        'term_id' => $term_id,
                        'redirect_to' => $redirect_to
                    ) );
                } else {
                    // Pre-call analysis — detect side-effects to explain in admin notices.

                    // 1. Will the target's chain lead back to us? (circular)
                    $will_be_circular = false;
                    $check            = $target->term_id;
                    $visited          = array( $term_id, $check );
                    while ( htax_is_redirect( $check ) ) {
                        $next = htax_get_redirect_target( $check );
                        if ( ! $next ) { break; }
                        if ( (int) $next->term_id === $term_id ) { $will_be_circular = true; break; }
                        if ( in_array( (int) $next->term_id, $visited, true ) ) { break; }
                        $visited[] = (int) $next->term_id;
                        $check     = $next->term_id;
                    }

                    // 2. Is the target itself a redirect (chain follow)?
                    $target_is_chain = htax_is_redirect( $target->term_id ) && ! $will_be_circular;

                    // 3. Terms currently redirecting to us (will be re-collapsed).
                    $incoming_cascades = htax_get_ids_of_redirects_to( $term_id );

                    // Valid redirect - save it atomically
                    // htax_set_redirect auto-resolves redirect chains to ultimate target
                    // Pass WP_Term objects to avoid redundant get_term() calls
                    $result = htax_set_redirect( $term, $target );

                    if ( $result ) {
                        htax_log_debug( 'Redirect saved successfully', array(
                            'term_id' => $term_id,
                            'redirect_to' => $redirect_to,
                            'was_new_redirect' => ( ! $old_is_redirect )
                        ) );

                        htax_add_admin_notice( __( 'Redirect saved.', 'hypertaxonomy' ), 'success' );

                        // Circular resolution notice.
                        if ( $will_be_circular ) {
                            htax_add_admin_notice(
                                sprintf(
                                    /* translators: %s: term name */
                                    __( 'Circular redirect resolved: "%s" was previously redirecting here, so its redirect has been removed.', 'hypertaxonomy' ),
                                    esc_html( $target->name )
                                ),
                                'info'
                            );
                        }

                        // Chain-follow notice: user picked a redirect target; we jumped to its final destination.
                        if ( $target_is_chain ) {
                            $actual = htax_get_redirect_target( $term_id );
                            if ( $actual && (int) $actual->term_id !== (int) $target->term_id ) {
                                htax_add_admin_notice(
                                    sprintf(
                                        /* translators: 1: chosen target name, 2: ultimate target name */
                                        __( '"%1$s" is itself a redirect, so this term now redirects directly to "%2$s".', 'hypertaxonomy' ),
                                        esc_html( $target->name ),
                                        esc_html( $actual->name )
                                    ),
                                    'info'
                                );
                            }
                        }

                        // Cascade notice: other terms re-pointed.
                        // Exclude the circular term — its redirect was removed, not cascaded.
                        if ( $will_be_circular ) {
                            $incoming_cascades = array_filter(
                                $incoming_cascades,
                                fn( $id ) => (int) $id !== (int) $target->term_id
                            );
                        }
                        if ( ! empty( $incoming_cascades ) ) {
                            $cascade_names = array();
                            foreach ( $incoming_cascades as $src_id ) {
                                $src = htax_get_valid_term( $src_id );
                                if ( $src ) { $cascade_names[] = $src->name; }
                            }
                            if ( ! empty( $cascade_names ) ) {
                                $actual_target = htax_get_redirect_target( $term_id );
                                $dest_name     = $actual_target ? $actual_target->name : $target->name;
                                htax_add_admin_notice(
                                    sprintf(
                                        /* translators: 1: comma-separated term names, 2: destination term name */
                                        _n(
                                            '1 other redirect updated: "%1$s" now redirects directly to "%2$s".',
                                            '%3$d other redirects updated: "%1$s" now redirect directly to "%2$s".',
                                            count( $cascade_names ),
                                            'hypertaxonomy'
                                        ),
                                        esc_html( implode( '", "', $cascade_names ) ),
                                        esc_html( $dest_name ),
                                        count( $cascade_names )
                                    ),
                                    'info'
                                );
                            }
                        }
                    } else {
                        htax_add_admin_notice( __( 'Failed to save redirect. Check for circular redirects.', 'hypertaxonomy' ), 'error' );
                    }
                }
            }
        } else {
            // Not a redirect - remove redirect relationship
            if ( $old_is_redirect ) {
                htax_remove_redirect( $term_id );

                htax_log_debug( 'Redirect removed', array( 'term_id' => $term_id ) );

                htax_add_admin_notice( __( 'Redirect removed successfully.', 'hypertaxonomy' ), 'success' );
            }
        }

    } catch ( Exception $e ) {
        htax_log_error( 'Database error during redirect save', array(
            'term_id' => $term_id,
            'taxonomy' => $taxonomy,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ) );

        // Database operations are atomic - no rollback needed

        htax_add_admin_notice(
            sprintf(
                __( 'Error saving redirect for term #%d: %s', 'hypertaxonomy' ),
                $term_id,
                $e->getMessage()
            ),
            'error'
        );
    }
}

/**
 * Helper: Check whether two arrays differ (bidirectional).
 *
 * @param array $a First array.
 * @param array $b Second array.
 * @return bool True if the arrays have any elements not present in the other.
 */
function htax_arrays_differ( array $a, array $b ): bool {
    return ! empty( array_diff( $a, $b ) ) || ! empty( array_diff( $b, $a ) );
}

/**
 * Helper: Save group relationships (container or included groups)
 *
 * @param int    $term_id               Term ID.
 * @param string $taxonomy              Term taxonomy.
 * @param string $relationship_direction 'container' or 'included'.
 * @param string $type_slug             Relationship type slug. Default 'includes'.
 * @return array ['new_groups' => array, 'old_groups' => array, 'changed' => bool]
 */
function htax_save_group_relationships( $term_id, $taxonomy, $relationship_direction, $type_slug = 'includes', $raw_field_value = null ) {
    $field_name = ( 'container' === $relationship_direction ) ? 'htax_cont_groups' : 'htax_incl_groups';
    $group_type = htax_get_group_type( $taxonomy );

    // Get new group IDs from the provided value or from POST. An absent POST field means the
    // form did not render this group picker — report no change and leave the stored rows alone.
    if ( null === $raw_field_value && ! isset( $_POST[ $field_name ] ) ) {
        $old_groups = ( 'container' === $relationship_direction )
            ? htax_get_reverse_rel_group_ids( $term_id, $type_slug )
            : htax_get_direct_rel_group_ids( $term_id, $type_slug );
        return array(
            'new_groups' => $old_groups,
            'old_groups' => $old_groups,
            'changed'    => false,
        );
    }

    if ( null !== $raw_field_value ) {
        $new_groups = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $raw_field_value ) ) ) ) );
    } else {
        $new_groups = htax_parse_post_field_ids( $field_name );
    }

    // Get old group IDs from database for comparison.
    if ( 'container' === $relationship_direction ) {
        $old_groups = htax_get_reverse_rel_group_ids( $term_id, $type_slug );
    } else {
        $old_groups = htax_get_direct_rel_group_ids( $term_id, $type_slug );
    }

    // Validate for ALL types of invalid relationships BEFORE saving
    $validation_result = htax_validate_and_filter_group_relationships( $term_id, $new_groups, $taxonomy, $relationship_direction );
    $new_groups = $validation_result['valid_groups'];

    // Log and notify user about invalid groups that were removed
    if ( ! empty( $validation_result['invalid_groups'] ) ) {
        htax_log_error( "Removed invalid {$relationship_direction} groups before saving", array(
            'term_id' => $term_id,
            'taxonomy' => $taxonomy,
            'group_type' => $group_type,
            'invalid_groups' => $validation_result['invalid_groups']
        ) );

        $reasons = array_unique( array_values( $validation_result['invalid_groups'] ) );
        htax_add_admin_notice(
            sprintf(
                __( 'Some %s groups were not added: %s', 'hypertaxonomy' ),
                $relationship_direction,
                implode( ', ', $reasons )
            ),
            'warning'
        );
    }

    // Store relationships in database
    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Determine relationship direction in database
    // Container groups: source=GroupX, target=Term (reverse)
    // Included groups: source=Term, target=GroupX (direct)
    $is_container = ( 'container' === $relationship_direction );

    // Remove old group relationships
    $removed_groups = array_diff( $old_groups, $new_groups );
    foreach ( $removed_groups as $group_id ) {
        $wpdb->delete(
            $table_name,
            $is_container ? array(
                'source_term_id'    => $group_id,
                'target_term_id'    => $term_id,
                'relationship_type' => $type_slug,
                'source_taxonomy'   => 'group',
            ) : array(
                'source_term_id'    => $term_id,
                'target_term_id'    => $group_id,
                'relationship_type' => $type_slug,
                'target_taxonomy'   => 'group',
            ),
            array( '%d', '%d', '%s', '%s' )
        );
    }

    // Add new group relationships
    $added_groups = array_diff( $new_groups, $old_groups );
    foreach ( $added_groups as $group_id ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table_name}
            (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
            VALUES (%d, %d, %s, %s, %s)",
            $is_container ? $group_id : $term_id,
            $is_container ? $term_id : $group_id,
            $type_slug,
            $is_container ? 'group' : $taxonomy,
            $is_container ? $taxonomy : 'group'
        ) );
    }

    $changed = htax_arrays_differ( $new_groups, $old_groups );

    return array(
        'new_groups' => $new_groups,
        'old_groups' => $old_groups,
        'changed' => $changed
    );
}

/**
 * Save relationship settings when a term is edited or created
 *
 * Handles includes/containers and container groups.
 * Maintains bidirectional relationships and validates hierarchy conflicts.
 *
 * Called by htax_save_term_data() orchestrator.
 * Note: Nonce and permission checks are done by orchestrator.
 *
 * @since 1.0.0
 * @param int $term_id Term ID
 * @param int $tt_id Term taxonomy ID (provided by WordPress hook)
 */
function htax_save_term_relationships( $term_id, $tt_id = 0 ) {
    // Note: Validation (nonce, permissions, taxonomy) is done by htax_save_term_data() orchestrator

    // Get term and taxonomy
    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        htax_log_error( 'Invalid term ID for relationship save', array( 'term_id' => $term_id ) );
        return;
    }
    $taxonomy = $term->taxonomy;

    // Redirect terms preserve their relationship data in the database, but the admin form
    // disables those fields so no POST data arrives. Skip the save entirely to avoid wiping
    // the stored rows. htax_save_redirect() has already run, so htax_is_redirect() reflects
    // the current (post-save) state at this point.
    if ( htax_is_redirect( $term_id ) && htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
        return;
    }

    // Declared before the try block: the catch block's ROLLBACK needs it even when an
    // exception is thrown before the transaction starts.
    global $wpdb;

    // Database operations are atomic - no locking needed
    try {

        // Get relationship data from POST
        $new_included = htax_parse_post_field_ids( 'htax_includes' );
        $new_containers = htax_parse_post_field_ids( 'htax_containers' );

        // Group terms cannot directly include/exclude other group terms.
        // Groups relate to each other via native WordPress parent-child hierarchy only.
        if ( 'group' === $taxonomy ) {
            $new_included   = array();
            $new_containers = array();
        }

        // Get old direct relationship IDs for change detection.
        // Using direct (non-expanded) getters avoids false positives from comparing
        // a direct ID list against an expanded set that includes group members.
        $old_included   = htax_get_direct_rel_term_ids( $term_id, 'includes' );
        $old_containers = htax_get_reverse_rel_term_ids( $term_id, 'includes' );

        // Each relationship field is a hidden input rendered with the relationships section.
        // An absent field means the form never showed it (Quick Edit, feature disabled, user
        // without manage_term_relationships) — keep the stored rows rather than clearing them.
        $save_included   = isset( $_POST['htax_includes'] );
        $save_containers = isset( $_POST['htax_containers'] );
        $save_excluded   = isset( $_POST['htax_excludes'] );

        // Validate target IDs belong to the source taxonomy — drop any that don't.
        if ( ! empty( $new_included ) || ! empty( $new_containers ) ) {
            $all_ids   = array_unique( array_merge( $new_included, $new_containers ) );
            $valid_ids = get_terms( array(
                'taxonomy'   => $taxonomy,
                'include'    => $all_ids,
                'fields'     => 'ids',
                'hide_empty' => false,
            ) );
            $valid_ids      = is_wp_error( $valid_ids ) ? array() : array_map( 'intval', $valid_ids );
            $new_included   = array_values( array_intersect( $new_included,   $valid_ids ) );
            $new_containers = array_values( array_intersect( $new_containers, $valid_ids ) );
        }

        // Remove self-referential relationships (prevent term from including/containing itself)
        $new_included   = array_diff( $new_included,   array( $term_id ) );
        $new_containers = array_diff( $new_containers, array( $term_id ) );

        // Strip ancestors and descendants from inclusions and containers.
        // These are already handled by WP's native include_children and must not
        // appear as direct HT rows — the stored sets should be strictly cross-branch.
        // $hierarchy_ids is also used for exclusions below; initialize here so it is
        // always defined regardless of whether the taxonomy is hierarchical.
        $hierarchy_ids = array();
        if ( is_taxonomy_hierarchical( $taxonomy ) ) {
            $hierarchy_ids = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
            $desc_ids      = get_term_children( $term_id, $taxonomy );
            if ( ! is_wp_error( $desc_ids ) ) {
                $hierarchy_ids = array_merge( $hierarchy_ids, $desc_ids );
            }
            if ( ! empty( $hierarchy_ids ) ) {
                $new_included   = array_values( array_diff( $new_included,   $hierarchy_ids ) );
                $new_containers = array_values( array_diff( $new_containers, $hierarchy_ids ) );
            }
        }

        // Save includes relationships to custom table
        // Database handles bidirectional automatically - no manual sync needed!
        $table_name = $wpdb->prefix . 'htax_term_relationships';

        $wpdb->query( 'START TRANSACTION' );

        // Delete old direct includes relationships (non-group)
        if ( $save_included ) {
            $wpdb->delete(
                $table_name,
                array(
                    'source_term_id' => $term_id,
                    'relationship_type' => 'includes',
                    'target_taxonomy' => $taxonomy,
                ),
                array( '%d', '%s', '%s' )
            );
        } else {
            $new_included = $old_included;
        }

        // Insert new includes relationships
        if ( $save_included && ! empty( $new_included ) ) {
            $values = array();
            foreach ( $new_included as $target_id ) {
                $values[] = $wpdb->prepare(
                    '(%d, %d, %s, %s, %s)',
                    $term_id,
                    $target_id,
                    'includes',
                    $taxonomy,
                    $taxonomy
                );
            }
            $wpdb->query( "INSERT IGNORE INTO {$table_name}
                (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
                VALUES " . implode( ',', $values ) );
        }

        // Delete old direct container relationships (other terms that include this term,
        // same taxonomy, non-group). Bidirectional: these are the same rows viewed from
        // the other side of includes — editing containers here modifies the source terms' data too.
        if ( $save_containers ) {
            $wpdb->delete(
                $table_name,
                array(
                    'target_term_id'    => $term_id,
                    'relationship_type' => 'includes',
                    'source_taxonomy'   => $taxonomy,
                    'target_taxonomy'   => $taxonomy,
                ),
                array( '%d', '%s', '%s', '%s' )
            );
        } else {
            $new_containers = $old_containers;
        }

        // Insert new container relationships (container_term includes this term)
        if ( $save_containers && ! empty( $new_containers ) ) {
            $values = array();
            foreach ( $new_containers as $container_id ) {
                $values[] = $wpdb->prepare(
                    '(%d, %d, %s, %s, %s)',
                    $container_id,
                    $term_id,
                    'includes',
                    $taxonomy,
                    $taxonomy
                );
            }
            $wpdb->query( "INSERT IGNORE INTO {$table_name}
                (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
                VALUES " . implode( ',', $values ) );
        }

        // Handle container groups (Term Groups)
        // Hierarchical taxonomies are allowed - validation will check for hierarchy conflicts
        // Block for 'group' taxonomy itself (groups can't be in relationships)
        $container_groups_result = null;
        if ( 'group' !== $taxonomy && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
            $container_groups_result = htax_save_group_relationships( $term_id, $taxonomy, 'container' );
        }

        // Handle included groups (Term Groups)
        // Hierarchical taxonomies are allowed - validation will check for hierarchy conflicts
        // Block for 'group' taxonomy itself (groups can't be in relationships)
        $included_groups_result = null;
        if ( 'group' !== $taxonomy && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
            $included_groups_result = htax_save_group_relationships( $term_id, $taxonomy, 'included' );
        }

        // Handle excluded individual terms
        $new_excluded = htax_parse_post_field_ids( 'htax_excludes' );

        // Group terms cannot exclude other group terms (same rule as includes above).
        if ( 'group' === $taxonomy ) {
            $new_excluded = array();
        }

        // Validate excluded target IDs belong to the source taxonomy — drop any that don't.
        if ( ! empty( $new_excluded ) ) {
            $valid_ids = get_terms( array(
                'taxonomy'   => $taxonomy,
                'include'    => $new_excluded,
                'fields'     => 'ids',
                'hide_empty' => false,
            ) );
            $new_excluded = is_wp_error( $valid_ids ) ? array() : array_values( array_map( 'intval', $valid_ids ) );
        }

        // Get old excluded terms from database
        $old_excluded = htax_get_excluded_term_ids( $term_id );

        // Remove self-referential exclusions
        $new_excluded = array_diff( $new_excluded, array( $term_id ) );

        // Strip ancestors and descendants — excluding a hierarchical relative is meaningless
        // since they cannot appear in the included set to begin with.
        if ( is_taxonomy_hierarchical( $taxonomy ) && ! empty( $hierarchy_ids ) ) {
            $new_excluded = array_values( array_diff( $new_excluded, $hierarchy_ids ) );
        }

        // Delete old direct exclusion relationships (non-group)
        if ( $save_excluded ) {
            $wpdb->delete(
                $table_name,
                array(
                    'source_term_id' => $term_id,
                    'relationship_type' => 'excludes',
                    'target_taxonomy' => $taxonomy,
                ),
                array( '%d', '%s', '%s' )
            );
        } else {
            $new_excluded = $old_excluded;
        }

        // Insert new exclusion relationships
        if ( $save_excluded && ! empty( $new_excluded ) ) {
            $values = array();
            foreach ( $new_excluded as $target_id ) {
                $values[] = $wpdb->prepare(
                    '(%d, %d, %s, %s, %s)',
                    $term_id,
                    $target_id,
                    'excludes',
                    $taxonomy,
                    $taxonomy
                );
            }
            $wpdb->query( "INSERT IGNORE INTO {$table_name}
                (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy)
                VALUES " . implode( ',', $values ) );
        }

        // Handle custom relationship type terms and groups (edit form only — add form sends nothing).
        $custom_types_changed           = false;
        $custom_inclusion_term_ids      = array(); // terms whose container sets changed via inclusion-based custom types
        $custom_rel_changed_fwd         = array(); // slug => changed target IDs (rev cache of each target needs clearing)
        $custom_rel_changed_rev         = array(); // slug => changed source IDs (fwd cache of each source needs clearing)
        $custom_rel_changed_fwd_groups  = array(); // slug => changed group IDs for T→G rows (member rev caches need clearing)
        $custom_rel_changed_rev_groups  = array(); // slug => changed group IDs for G→T rows (member fwd caches need clearing)
        if ( 'group' !== $taxonomy && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
            $all_type_defs = htax_get_registered_relationship_types();
            $includes_slugs = htax_get_relationship_type_slugs( 'includes' );

            foreach ( $all_type_defs as $cslug => $cdef ) {
                if ( ! empty( $cdef['is_core'] ) ) {
                    continue; // core types handled above.
                }
                $fwd_field = 'htax_cust_' . $cslug . '_fwd_terms';
                $rev_field = 'htax_cust_' . $cslug . '_rev_terms';

                // Skip if neither forward nor reverse was submitted (field not present = not the new edit form).
                if ( ! isset( $_POST[ $fwd_field ] ) && ! isset( $_POST[ $rev_field ] ) ) {
                    continue;
                }

                $new_fwd = htax_parse_post_field_ids( $fwd_field );
                $new_rev = htax_parse_post_field_ids( $rev_field );

                // Validate IDs exist in any taxonomy (custom types allow cross-taxonomy targets).
                // Batch JOIN to get both term_id and taxonomy in one query, avoiding
                // one get_term() call per added ID in the INSERT loops below.
                $all_custom_ids   = array_unique( array_merge( $new_fwd, $new_rev ) );
                $valid_custom_ids = array();
                $custom_tax_map   = array(); // term_id => taxonomy
                if ( ! empty( $all_custom_ids ) ) {
                    $placeholders_c = htax_sql_placeholders( count( $all_custom_ids ) );
                    $rows           = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT t.term_id, tt.taxonomy
                             FROM {$wpdb->terms} t
                             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                             WHERE t.term_id IN ({$placeholders_c})",
                            $all_custom_ids
                        )
                    );
                    foreach ( (array) $rows as $row ) {
                        $valid_custom_ids[]              = (int) $row->term_id;
                        $custom_tax_map[ $row->term_id ] = $row->taxonomy;
                    }
                }
                $new_fwd = array_values( array_intersect( $new_fwd, $valid_custom_ids ) );
                $new_rev = array_values( array_intersect( $new_rev, $valid_custom_ids ) );

                // Remove self-references.
                $new_fwd = array_diff( $new_fwd, array( $term_id ) );
                $new_rev = array_diff( $new_rev, array( $term_id ) );

                // For symmetric types, prevent A→B + B→A duplicates.
                // If a term already appears in new_rev (the reverse row exists), skip creating
                // the corresponding fwd row — the single reverse row is sufficient.
                if ( ! empty( $cdef['symmetric'] ) ) {
                    $new_fwd = array_values( array_diff( $new_fwd, $new_rev ) );
                }

                // Fetch old forward rows (source=term_id).
                $old_fwd = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                    "SELECT target_term_id FROM `{$table_name}` WHERE source_term_id = %d AND relationship_type = %s AND source_taxonomy = %s",
                    $term_id, $cslug, $taxonomy
                ) ) );

                // Fetch old reverse rows (target=term_id).
                $old_rev = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                    "SELECT source_term_id FROM `{$table_name}` WHERE target_term_id = %d AND relationship_type = %s AND target_taxonomy = %s",
                    $term_id, $cslug, $taxonomy
                ) ) );

                // Sync forward rows.
                foreach ( array_diff( $old_fwd, $new_fwd ) as $rid ) {
                    $wpdb->delete( $table_name, array( 'source_term_id' => $term_id, 'target_term_id' => $rid, 'relationship_type' => $cslug ), array( '%d', '%d', '%s' ) );
                }
                foreach ( array_diff( $new_fwd, $old_fwd ) as $rid ) {
                    $target_tax = $custom_tax_map[ $rid ] ?? null;
                    if ( $target_tax ) {
                        $wpdb->query( $wpdb->prepare(
                            "INSERT IGNORE INTO `{$table_name}` (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy) VALUES (%d, %d, %s, %s, %s)",
                            $term_id, $rid, $cslug, $taxonomy, $target_tax
                        ) );
                    }
                }

                // Sync reverse rows.
                foreach ( array_diff( $old_rev, $new_rev ) as $rid ) {
                    $wpdb->delete( $table_name, array( 'source_term_id' => $rid, 'target_term_id' => $term_id, 'relationship_type' => $cslug ), array( '%d', '%d', '%s' ) );
                }
                foreach ( array_diff( $new_rev, $old_rev ) as $rid ) {
                    $source_tax = $custom_tax_map[ $rid ] ?? null;
                    if ( $source_tax ) {
                        $wpdb->query( $wpdb->prepare(
                            "INSERT IGNORE INTO `{$table_name}` (source_term_id, target_term_id, relationship_type, source_taxonomy, target_taxonomy) VALUES (%d, %d, %s, %s, %s)",
                            $rid, $term_id, $cslug, $source_tax, $taxonomy
                        ) );
                    }
                }

                // Groups (includes-mapped types only).
                if ( in_array( $cslug, $includes_slugs, true ) && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
                    $fwd_grp_field = 'htax_cust_' . $cslug . '_fwd_groups';
                    $rev_grp_field = 'htax_cust_' . $cslug . '_rev_groups';

                    if ( isset( $_POST[ $fwd_grp_field ] ) ) {
                        $fwd_grp_result = htax_save_group_relationships( $term_id, $taxonomy, 'included', $cslug, wp_unslash( $_POST[ $fwd_grp_field ] ) );
                        if ( $fwd_grp_result['changed'] ) {
                            $custom_types_changed = true;
                            $changed_fwd_g = array_unique( array_merge(
                                array_diff( $fwd_grp_result['new_groups'], $fwd_grp_result['old_groups'] ),
                                array_diff( $fwd_grp_result['old_groups'], $fwd_grp_result['new_groups'] )
                            ) );
                            $custom_rel_changed_fwd_groups[ $cslug ] = array_unique( array_merge(
                                $custom_rel_changed_fwd_groups[ $cslug ] ?? array(),
                                $changed_fwd_g
                            ) );
                        }
                    }
                    if ( isset( $_POST[ $rev_grp_field ] ) ) {
                        $rev_grp_result = htax_save_group_relationships( $term_id, $taxonomy, 'container', $cslug, wp_unslash( $_POST[ $rev_grp_field ] ) );
                        if ( $rev_grp_result['changed'] ) {
                            $custom_types_changed = true;
                            $changed_rev_g = array_unique( array_merge(
                                array_diff( $rev_grp_result['new_groups'], $rev_grp_result['old_groups'] ),
                                array_diff( $rev_grp_result['old_groups'], $rev_grp_result['new_groups'] )
                            ) );
                            $custom_rel_changed_rev_groups[ $cslug ] = array_unique( array_merge(
                                $custom_rel_changed_rev_groups[ $cslug ] ?? array(),
                                $changed_rev_g
                            ) );
                        }
                    }
                }

                if ( htax_arrays_differ( $new_fwd, $old_fwd ) || htax_arrays_differ( $new_rev, $old_rev ) ) {
                    $custom_types_changed = true;
                    $custom_rel_changed_fwd[ $cslug ] = array_unique( array_merge(
                        array_diff( $new_fwd, $old_fwd ),
                        array_diff( $old_fwd, $new_fwd )
                    ) );
                    $custom_rel_changed_rev[ $cslug ] = array_unique( array_merge(
                        array_diff( $new_rev, $old_rev ),
                        array_diff( $old_rev, $new_rev )
                    ) );
                }
            }
        }

        $wpdb->query( 'COMMIT' );


        // Only show success notice if relationships actually changed
        $includes_changed   = htax_arrays_differ( $new_included,   $old_included );
        $containers_changed = htax_arrays_differ( $new_containers, $old_containers );
        $excludes_changed   = htax_arrays_differ( $new_excluded,   $old_excluded );

        // Check if group relationships changed (if applicable)
        $container_groups_changed = ( $container_groups_result && $container_groups_result['changed'] );
        $included_groups_changed = ( $included_groups_result && $included_groups_result['changed'] );
        $groups_changed = ( $container_groups_changed || $included_groups_changed );

        if ( $includes_changed || $containers_changed || $excludes_changed || $groups_changed || $custom_types_changed ) {
            htax_add_admin_notice(
                __( 'Term relationships saved successfully.', 'hypertaxonomy' ),
                'success',
                true // Queue for next page load
            );
        }

        // Eager reload caches BEFORE firing actions
        // This ensures index handlers read NEW state from cache
        if ( $includes_changed || $containers_changed || $excludes_changed || $groups_changed || $custom_types_changed ) {
            // Invalidate all caches for this term
            htax_delete_term_transients( $term_id );

            // Clear other-side custom rel caches: changed fwd targets' rev caches,
            // changed rev sources' fwd caches. The edited term's own caches were
            // already cleared above via htax_delete_term_transients.
            foreach ( $custom_rel_changed_fwd as $cslug => $tids ) {
                foreach ( $tids as $tid ) {
                    htax_delete_transient( 'htax_crel_rev_' . $cslug . '_' . $tid );
                    htax_delete_transient( 'htax_reverse_rel_' . $cslug . '_' . $tid );
                    htax_delete_transient( 'htax_reverse_rel_grp_' . $cslug . '_' . $tid );
                }
            }
            foreach ( $custom_rel_changed_rev as $cslug => $tids ) {
                foreach ( $tids as $tid ) {
                    htax_delete_transient( 'htax_crel_' . $cslug . '_' . $tid );
                    htax_delete_transient( 'htax_direct_rel_' . $cslug . '_' . $tid );
                    htax_delete_transient( 'htax_direct_rel_grp_' . $cslug . '_' . $tid );
                }
            }

            // T→G included-group rows changed → htax_cont_M stale for members of changed
            // groups (htax_get_container_terms path 2 matches exact group membership).
            if ( $included_groups_changed ) {
                $changed_incl_g = array_unique( array_merge(
                    array_diff( $included_groups_result['new_groups'], $included_groups_result['old_groups'] ),
                    array_diff( $included_groups_result['old_groups'], $included_groups_result['new_groups'] )
                ) );
                if ( ! empty( $changed_incl_g ) ) {
                    htax_clear_member_caches_for_groups( $changed_incl_g, 'cont' );
                }
            }

            // G→T container-group rows changed → htax_inc_M stale for members of changed
            // groups and their ancestors (path 3 expands member groups downward, so
            // ancestor-group members also see these container rows).
            if ( $container_groups_changed ) {
                $changed_cont_g = array_unique( array_merge(
                    array_diff( $container_groups_result['new_groups'], $container_groups_result['old_groups'] ),
                    array_diff( $container_groups_result['old_groups'], $container_groups_result['new_groups'] )
                ) );
                if ( ! empty( $changed_cont_g ) ) {
                    htax_clear_member_caches_for_groups( $changed_cont_g, 'inc' );
                }
            }

            // Custom-type T→G / G→T group rows changed → M's rev/fwd caches stale.
            foreach ( $custom_rel_changed_fwd_groups as $cslug => $gids ) {
                if ( ! empty( $gids ) ) {
                    htax_clear_member_caches_for_groups( array_unique( $gids ), 'crel_rev', $cslug );
                }
            }
            foreach ( $custom_rel_changed_rev_groups as $cslug => $gids ) {
                if ( ! empty( $gids ) ) {
                    htax_clear_member_caches_for_groups( array_unique( $gids ), 'crel', $cslug );
                }
            }

            // Inclusion-based custom types feed the same inclusion/container sets (and search
            // index container rows) as core 'includes'. Collect the terms whose container sets
            // changed — the included side of each changed row — and invalidate both sides.
            $custom_inclusion_term_ids = array();
            $custom_includes_slugs     = htax_get_relationship_type_slugs( 'includes' );
            foreach ( $custom_includes_slugs as $cslug ) {
                // T→U rows: U's containers changed.
                foreach ( $custom_rel_changed_fwd[ $cslug ] ?? array() as $tid ) {
                    htax_delete_term_transients( (int) $tid );
                    $custom_inclusion_term_ids[] = (int) $tid;
                }
                // U→T rows: U's inclusions and T's containers changed.
                foreach ( $custom_rel_changed_rev[ $cslug ] ?? array() as $tid ) {
                    htax_delete_term_transients( (int) $tid );
                    $custom_inclusion_term_ids[] = (int) $term_id;
                }
                // T→G rows: containers of G's members changed.
                if ( ! empty( $custom_rel_changed_fwd_groups[ $cslug ] ) ) {
                    $gids = array_unique( $custom_rel_changed_fwd_groups[ $cslug ] );
                    htax_clear_member_caches_for_groups( $gids, 'cont' );
                    $custom_inclusion_term_ids = array_merge(
                        $custom_inclusion_term_ids,
                        array_map( 'intval', array_keys( htax_get_terms_in_groups( $gids, $taxonomy ) ) )
                    );
                }
                // G→T rows: inclusions of G's members changed, and T's containers.
                if ( ! empty( $custom_rel_changed_rev_groups[ $cslug ] ) ) {
                    htax_clear_member_caches_for_groups( array_unique( $custom_rel_changed_rev_groups[ $cslug ] ), 'inc' );
                    $custom_inclusion_term_ids[] = (int) $term_id;
                }
            }
            $custom_inclusion_term_ids = array_values( array_unique( $custom_inclusion_term_ids ) );

            // Eager reload relationship caches with NEW state from DB
            htax_get_included_terms( $term_id );
            htax_get_redirect_target( $term_id );
            htax_get_term_group_ids( $term_id );
            htax_get_container_terms( $term_id ); // Eager reload containers - needed by index handlers
        }

        // Fire actions for relationship changes (for search index updates, cache invalidation, etc.)
        // Cache now contains NEW state - index handlers can use it
        if ( $includes_changed ) {
            do_action( 'htax_includes_changed', $term_id, $taxonomy, $new_included, $old_included );
        }
        if ( $containers_changed ) {
            do_action( 'htax_containers_changed', $term_id, $taxonomy, $new_containers, $old_containers );
        }
        if ( $excludes_changed ) {
            do_action( 'htax_excludes_changed', $term_id, $taxonomy, $new_excluded, $old_excluded );
        }
        if ( $container_groups_changed ) {
            do_action( 'htax_container_groups_changed', $term_id, $taxonomy, $container_groups_result['new_groups'], $container_groups_result['old_groups'] );
        }
        if ( $included_groups_changed ) {
            do_action( 'htax_included_groups_changed', $term_id, $taxonomy, $included_groups_result['new_groups'], $included_groups_result['old_groups'] );
        }
        if ( ! empty( $custom_inclusion_term_ids ) ) {
            /**
             * Fires after inclusion-based custom relationship rows change on a term save.
             *
             * @since 1.0.0
             * @param int    $term_id           Term that was saved.
             * @param string $taxonomy          Its taxonomy.
             * @param int[]  $affected_term_ids Terms whose container sets changed (posts with
             *                                  these terms need their container index rows rebuilt).
             */
            do_action( 'htax_custom_inclusions_changed', $term_id, $taxonomy, $custom_inclusion_term_ids );
        }

    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );

        htax_log_error( 'Exception during term save - rolled back all changes', array(
            'term_id' => $term_id,
            'taxonomy' => $taxonomy,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ) );

        // Add admin notice for the error
        htax_add_admin_notice(
            sprintf(
                __( 'Database error saving relationships for term #%d: %s', 'hypertaxonomy' ),
                $term_id,
                $e->getMessage()
            ),
            'error'
        );
    }
}

/**
 * Clean up relationships when a term is deleted.
 *
 * @since 1.0.0
 * @param int $term_id Term ID
 * @param int $tt_id Term taxonomy ID
 */
function htax_cleanup_deleted_term( $term_id, $tt_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    // Before removing rows where this term is the target, clean up any source terms
    // that were redirecting TO this term — their _htax_auto_redirect flag becomes stale
    // once the target no longer exists and the redirect row is removed.
    $redirect_sources = $wpdb->get_col( $wpdb->prepare(
        "SELECT source_term_id FROM {$table_name}
         WHERE target_term_id = %d AND relationship_type = 'redirect'",
        $term_id
    ) );
    foreach ( $redirect_sources as $source_id ) {
        delete_term_meta( (int) $source_id, '_htax_auto_redirect' );
    }

    // If this term was itself a redirect, the bulk redirect-terms cache must be cleared.
    // This function bypasses htax_remove_redirect() (and its hooks) intentionally — calling
    // it would re-fire htax_redirect_changed on a term that no longer exists. The cache
    // is cleared directly here instead.
    if ( ! empty( $wpdb->get_var( $wpdb->prepare(
        "SELECT 1 FROM {$table_name} WHERE source_term_id = %d AND relationship_type = 'redirect' LIMIT 1",
        $term_id
    ) ) ) ) {
        htax_clear_redirect_term_ids_cache();
    }

    // Delete all relationships where this term is the source
    $wpdb->delete(
        $table_name,
        array( 'source_term_id' => $term_id ),
        array( '%d' )
    );

    // Delete all relationships where this term is the target
    $wpdb->delete(
        $table_name,
        array( 'target_term_id' => $term_id ),
        array( '%d' )
    );

    // Clean up non-relationship metadata
    delete_term_meta( $term_id, '_htax_show_description' );
    delete_term_meta( $term_id, '_htax_is_hidden' );

    // Clean up sticky post assignments for this term.
    $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => htax_get_sticky_assignment_key( $term_id ) ), array( '%s' ) );
    htax_delete_transient( 'htax_sticky_' . (int) $term_id );
}

/**
 * Remove redirect rows where a single source term has more than one redirect target.
 *
 * The schema's PRIMARY KEY (source_term_id, target_term_id, relationship_type) allows
 * a source to have multiple redirect rows pointing to different targets. Write-time code
 * always deletes before inserting, so duplicates can only arise via direct DB manipulation.
 * When found, keeps the row with the lowest target_term_id (deterministic tiebreaker) and
 * deletes the others.
 *
 * Safe to call multiple times — idempotent.
 *
 * @since 1.0.0
 * @return int Number of duplicate redirect rows deleted.
 */
function htax_remove_duplicate_redirects(): int {
    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';

    $deleted = $wpdb->query(
        "DELETE r1 FROM `{$table}` r1
         INNER JOIN (
             SELECT source_term_id, MIN(target_term_id) AS keep_target
             FROM `{$table}`
             WHERE relationship_type = 'redirect'
             GROUP BY source_term_id
             HAVING COUNT(*) > 1
         ) dups ON r1.source_term_id = dups.source_term_id
         WHERE r1.relationship_type = 'redirect'
           AND r1.target_term_id != dups.keep_target"
    );

    return false === $deleted ? 0 : (int) $deleted;
}

/**
 * Remove orphaned rows from wp_htax_term_relationships and clean up stale redirect meta.
 *
 * Called on activation to repair state that may have drifted while HT was inactive —
 * for example, if terms were deleted via WP-CLI, a direct DB import, or another plugin
 * that does not fire WordPress's delete_term hook.
 *
 * Five passes:
 * 1. Delete relationship rows whose source or target term ID no longer exists in wp_terms.
 * 2. Delete group membership rows pointing to deleted groups.
 * 3. Delete _htax_auto_redirect meta from any term that has no corresponding redirect row.
 * 4. Enforce taxonomy constraints for all core and custom relationship types.
 * 5. Remove duplicate redirect rows for any source with more than one target.
 *
 * Safe to call multiple times — idempotent.
 *
 * @since 1.0.0
 * @return int Total number of orphaned or invalid rows removed.
 */
function htax_repair_orphaned_term_relationships(): int {
    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';

    // Pass 1: remove rows where the source term no longer exists.
    $deleted_source = (int) $wpdb->query(
        "DELETE r FROM {$table} r
         LEFT JOIN {$wpdb->terms} t ON r.source_term_id = t.term_id
         WHERE t.term_id IS NULL"
    );

    // Pass 2: remove rows where the target term no longer exists.
    $deleted_target = (int) $wpdb->query(
        "DELETE r FROM {$table} r
         LEFT JOIN {$wpdb->terms} t ON r.target_term_id = t.term_id
         WHERE t.term_id IS NULL"
    );

    // Pass 3: remove stale _htax_auto_redirect meta from any term that has no redirect row.
    // This covers meta left behind by pass 1 deletions and any prior missed cleanups.
    $stale_meta_ids = $wpdb->get_col(
        "SELECT tm.term_id
         FROM {$wpdb->termmeta} tm
         LEFT JOIN {$table} r
           ON r.source_term_id = tm.term_id AND r.relationship_type = 'redirect'
         WHERE tm.meta_key = '_htax_auto_redirect'
           AND r.source_term_id IS NULL"
    );
    foreach ( $stale_meta_ids as $term_id ) {
        delete_term_meta( (int) $term_id, '_htax_auto_redirect' );
    }

    // Pass 4: enforce taxonomy constraints for all relationship types (core and custom).
    // Covers drift that occurred while HT was inactive (direct DB imports, WP-CLI, etc.).
    $deleted_invalid = 0;
    foreach ( array( 'includes', 'excludes', 'member_of', 'redirect' ) as $type_slug ) {
        $deleted_invalid += htax_remove_invalid_term_relationships( $type_slug );
    }
    $custom_types = get_option( HTAX_CUSTOM_REL_TYPES_OPTION, array() );
    foreach ( array_keys( $custom_types ) as $type_slug ) {
        $deleted_invalid += htax_remove_invalid_term_relationships( $type_slug );
    }

    // Pass 5: remove duplicate redirect rows (multiple targets per source term).
    $deleted_duplicate_redirects = htax_remove_duplicate_redirects();

    // Pass 6: remove bidirectional duplicates for symmetric custom relationship types.
    $deleted_sym_dupes = htax_cleanup_symmetric_relationship_duplicates();

    return $deleted_source + $deleted_target + $deleted_invalid + $deleted_duplicate_redirects + $deleted_sym_dupes;
}

/**
 * Remove A→B + B→A duplicates for symmetric custom relationship types.
 *
 * For each symmetric type, finds pairs of rows (A→B) and (B→A) and deletes the one
 * where source_term_id > target_term_id, keeping a single canonical row per pair.
 *
 * @since 1.0.0
 * @return int Number of rows deleted.
 */
function htax_cleanup_symmetric_relationship_duplicates(): int {
    global $wpdb;
    $table        = $wpdb->prefix . 'htax_term_relationships';
    $custom_types = get_option( HTAX_CUSTOM_REL_TYPES_OPTION, array() );
    $deleted      = 0;

    foreach ( $custom_types as $slug => $def ) {
        if ( empty( $def['symmetric'] ) ) {
            continue;
        }
        $slug = sanitize_key( $slug );
        // Delete the row with the higher source_term_id when a reverse row also exists.
        $result = $wpdb->query( $wpdb->prepare(
            "DELETE r1 FROM `{$table}` r1
             INNER JOIN `{$table}` r2
                 ON r2.relationship_type = r1.relationship_type
                 AND r2.source_term_id   = r1.target_term_id
                 AND r2.target_term_id   = r1.source_term_id
             WHERE r1.relationship_type = %s
               AND r1.source_term_id > r1.target_term_id",
            $slug
        ) );
        if ( $result ) {
            $deleted += (int) $result;
        }
    }

    return $deleted;
}

function htax_cleanup_bulk_deleted_terms( $tt_id ) {
    // Get term from tt_id (hook fires BEFORE deletion, so term still exists)
    $term = get_term_by( 'term_taxonomy_id', $tt_id );

    if ( ! $term || is_wp_error( $term ) ) {
        htax_log_debug( 'Could not get term for bulk cleanup', array(
            'tt_id' => $tt_id
        ) );
        return;
    }

    $term_id = $term->term_id;
    $taxonomy = $term->taxonomy;

    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        htax_log_debug( 'Skipping bulk cleanup for disabled taxonomy', array(
            'taxonomy' => $taxonomy,
            'tt_id' => $tt_id
        ) );
        return;
    }

    if ( $term_id ) {
        // Invalidate caches for this term and all terms that referenced it.
        // delete_term hook is not fired during bulk operations, so we must call this
        // manually before htax_cleanup_deleted_term removes the relationship rows.
        htax_invalidate_term_caches_on_delete( $term, $tt_id, $taxonomy );

        // Clean up relationships (main cleanup function)
        htax_cleanup_deleted_term( $term_id, $tt_id );

        // Clean up icon (delete_term hook is not fired during bulk operations)
        HyperTaxonomy_Term_Icons::get_instance()->htax_cleanup_term_icon( $term_id, $tt_id, $taxonomy );
    }
}

/**
 * Get included term IDs
 *
 * Returns direct inclusions, group-based inclusions, and container-group inclusions for the
 * given term. For hierarchical taxonomies, each included term's own children are pre-expanded
 * because the caller's query clause uses include_children => false for the included-IDs set;
 * the primary term's own children are handled separately by the query's first clause.
 *
 * Returns an empty array when the term is a redirect unless $include_redirect_data is true.
 *
 * @param int|WP_Term $term                 Term ID or WP_Term object.
 * @param bool        $include_redirect_data When true, return data even if the term is a redirect.
 * @return array Array of included term IDs (with descendant expansion for hierarchical taxonomies)
 */
function htax_get_included_term_ids( $term, bool $include_redirect_data = false ): array {
    // Accept either term ID or WP_Term object
    if ( is_object( $term ) && isset( $term->term_id ) ) {
        $term_obj = $term;
    } else {
        $term_obj = htax_get_valid_term( $term );
    }

    if ( ! $term_obj ) {
        return array();
    }

    // Pass WP_Term object to avoid redundant get_term() call
    $included_terms = htax_get_included_terms( $term_obj, $include_redirect_data );

    if ( empty( $included_terms ) ) {
        return array();
    }

    $taxonomy = $term_obj->taxonomy;
    $term_id = $term_obj->term_id;

    // For hierarchical taxonomies, expand to include children
    if ( is_taxonomy_hierarchical( $taxonomy ) ) {
        // Pre-expand children of each included term because htax_build_expanded_tax_query
        // uses include_children => false for the included-IDs clause. The primary term's
        // own children are handled separately by include_children => true in that query,
        // so they are intentionally NOT pre-expanded here.
        $expanded_ids = array();

        foreach ( $included_terms as $included_term ) {
            $expanded_ids[] = $included_term->term_id;

            $children = get_term_children( $included_term->term_id, $taxonomy );
            if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
                $expanded_ids = array_merge( $expanded_ids, $children );
            }
        }

        return htax_sanitize_term_ids( $expanded_ids );
    }

    // For flat taxonomies, return as-is
    return array_keys( $included_terms );
}



/**
 * Get the terms that redirect (directly or transitively) to a target term.
 *
 * The reverse of htax_get_redirect_target(): given a target Y, returns every term
 * X whose redirect resolves to Y, following redirect chains (A → B → Y) with a
 * cycle guard. Posts assigned a redirecting term belong to the target for
 * discovery purposes, so this lets callers expand a target's term set to include
 * its redirect sources — mirroring the `redirect_target` rows in the search index.
 *
 * @since 1.0.0
 * @param int $target_term_id Target term ID.
 * @return int[] Source term IDs redirecting to the target (empty if none).
 */
function htax_get_redirect_sources( int $target_term_id ): array {
    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    $found = array();
    $seen  = array( $target_term_id => true );
    $queue = array( $target_term_id );

    while ( ! empty( $queue ) ) {
        $current = (int) array_shift( $queue );
        $sources = $wpdb->get_col( $wpdb->prepare(
            "SELECT source_term_id FROM {$table_name}
             WHERE target_term_id = %d AND relationship_type = 'redirect'",
            $current
        ) );
        htax_handle_db_error( 'htax_get_redirect_sources' );

        foreach ( $sources as $source_id ) {
            $source_id = (int) $source_id;
            if ( ! isset( $seen[ $source_id ] ) ) {
                $seen[ $source_id ]  = true;
                $found[ $source_id ] = true;
                $queue[]             = $source_id;
            }
        }
    }

    return array_keys( $found );
}

/**
 * Clear cached redirect targets.
 *
 * With a term ID, deletes that term's htax_redir_ transient and its per-request
 * copy, so a redirect set or removed earlier in a request is seen by every later
 * lookup — including the search index handlers that run on htax_redirect_changed /
 * htax_redirect_removed. Without one, empties the per-request copies for every
 * term (callers that delete htax_redir_ transients in bulk use this).
 *
 * @since 1.0.0
 *
 * @param int $term_id Term ID, or 0 for every term's per-request copy.
 */
function htax_clear_redirect_target_cache( int $term_id = 0 ): void {
    if ( $term_id > 0 ) {
        htax_delete_transient( 'htax_redir_' . $term_id );
        htax_clear_request_cache( 'redirect_target', $term_id );
    } else {
        htax_clear_request_cache( 'redirect_target' );
    }
}

/**
 * Clear the cached list of all redirecting term IDs (transient and per-request copy).
 *
 * @since 1.0.0
 */
function htax_clear_redirect_term_ids_cache(): void {
    htax_delete_transient( 'htax_redirect_terms' );
    htax_clear_request_cache( 'redirect_term_ids' );
}

/**
 * Get redirect target for a term.
 *
 * Returns the full WP_Term object for the redirect target, which includes both
 * term_id and taxonomy - useful for cross-taxonomy redirects.
 *
 * @param int $term_id Term ID
 * @return WP_Term|false Redirect target term object or false if not a redirect
 */
function htax_get_redirect_target( $term_id ) {
    // Per-request cache — avoids repeated transient reads for the same term.
    // Invalidated together with the transient by htax_clear_redirect_target_cache().
    $static_cache = &htax_request_cache( 'redirect_target' );
    if ( array_key_exists( $term_id, $static_cache ) ) {
        return $static_cache[ $term_id ];
    }

    // Check persistent transient cache.
    $cache_key = 'htax_redir_' . $term_id;
    $cached = htax_get_transient( $cache_key );
    if ( false !== $cached ) {
        // Cache stores false as 'none' string to distinguish from cache miss
        return $static_cache[ $term_id ] = ( 'none' === $cached ) ? false : $cached;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'htax_term_relationships';

    $result = $wpdb->get_row( $wpdb->prepare(
        "SELECT target_term_id, target_taxonomy
        FROM {$table_name}
        WHERE source_term_id = %d
        AND relationship_type = 'redirect'
        LIMIT 1",
        $term_id
    ), ARRAY_A );

    if ( ! $result ) {
        // Cache negative result (no redirect, eager invalidation - no TTL)
        htax_set_transient( $cache_key, 'none', htax_get_cache_duration( 'relationship' ) );
        return $static_cache[ $term_id ] = false;
    }

    // Get full WP_Term object
    $target_term = htax_get_valid_term( intval( $result['target_term_id'] ) );

    if ( ! $target_term ) {
        // For unregistered taxonomies get_term() fails, but the redirect row still exists and
        // will be valid again when the plugin re-registers the taxonomy. Don't cache 'none' —
        // let the next request retry the DB lookup and recover automatically.
        if ( ! taxonomy_exists( $result['target_taxonomy'] ) ) {
            return false; // No static cache — let next request retry.
        }
        // Target term was deleted — permanent miss, safe to cache.
        htax_set_transient( $cache_key, 'none', htax_get_cache_duration( 'relationship' ) );
        return $static_cache[ $term_id ] = false;
    }

    // If the target taxonomy is HT-disabled (registered but turned off in settings), treat as
    // no redirect and cache 'none'. on_taxonomy_enabled_state_changed calls htax_clear_all_caches()
    // on re-enable, so the stale 'none' entry is cleared before redirects need to work again.
    // For unregistered taxonomies, leave the cached WP_Term in place so that when the plugin
    // that owns the taxonomy is reactivated the redirect resumes on the very next request.
    if ( ! htax_is_taxonomy_enabled( $target_term->taxonomy ) ) {
        if ( taxonomy_exists( $target_term->taxonomy ) ) {
            htax_set_transient( $cache_key, 'none', htax_get_cache_duration( 'relationship' ) );
        }
        return $static_cache[ $term_id ] = false;
    }

    // Cache the WP_Term object (eager invalidation - no TTL)
    htax_set_transient( $cache_key, $target_term, htax_get_cache_duration( 'relationship' ) );

    return $static_cache[ $term_id ] = $target_term;
}

/**
 * Prime the redirect transient cache for all terms that have redirects.
 *
 * Fetches all redirect relationships in a single query and populates the
 * htax_redir_* transients, converting N individual DB lookups during bulk
 * indexing into one bulk query + one query per target taxonomy.
 *
 * @since 1.0.0
 */
function htax_prewarm_redirect_cache() {
    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';

    $rows = $wpdb->get_results(
        "SELECT source_term_id, target_term_id, target_taxonomy
         FROM {$table}
         WHERE relationship_type = 'redirect'"
    );

    if ( empty( $rows ) ) {
        return;
    }

    // Collect target IDs grouped by taxonomy for batch fetching
    $by_tax = array();
    foreach ( $rows as $row ) {
        $by_tax[ $row->target_taxonomy ][] = (int) $row->target_term_id;
    }

    // Fetch all target term objects, one query per taxonomy
    $target_terms = array();
    foreach ( $by_tax as $tax => $ids ) {
        $terms = get_terms( array(
            'taxonomy'   => $tax,
            'include'    => array_unique( $ids ),
            'hide_empty' => false,
        ) );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $target_terms[ $term->term_id ] = $term;
            }
        }
    }

    // Populate transients — only where not already cached
    foreach ( $rows as $row ) {
        $cache_key = 'htax_redir_' . (int) $row->source_term_id;
        if ( false !== htax_get_transient( $cache_key ) ) {
            continue;
        }
        $target = $target_terms[ (int) $row->target_term_id ] ?? 'none';
        htax_set_transient( $cache_key, $target, htax_get_cache_duration( 'relationship' ) );
    }
}

/**
 * Check if a term is a redirect.
 *
 * @param int $term_id Term ID
 * @return bool True if term is a redirect, false otherwise
 */
function htax_is_redirect( $term_id ) {
    return (bool) htax_get_redirect_target( $term_id );
}

/**
 * Get all term IDs that have a redirect row in the database.
 *
 * Returns the source_term_id of every redirect relationship, regardless of taxonomy.
 * Result is cached per request and in a persistent transient; both are cleared by
 * htax_clear_redirect_term_ids_cache() on htax_redirect_changed and htax_redirect_removed.
 *
 * @since 1.0.0
 * @return int[] Array of term IDs, empty array when none exist.
 */
function htax_get_all_redirect_term_ids(): array {
    $request_cache = &htax_request_cache( 'redirect_term_ids' );
    if ( isset( $request_cache['all'] ) ) {
        return $request_cache['all'];
    }

    $cached = htax_get_transient( 'htax_redirect_terms' );
    if ( false !== $cached ) {
        $request_cache['all'] = $cached;
        return $cached;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';
    $ids   = $wpdb->get_col(
        "SELECT source_term_id FROM {$table} WHERE relationship_type = 'redirect'"
    );

    if ( htax_handle_db_error( 'get_all_redirect_term_ids', array() ) !== false ) {
        return array();
    }

    $result                = array_map( 'intval', $ids );
    $request_cache['all']  = $result;
    htax_set_transient( 'htax_redirect_terms', $result, htax_get_cache_duration( 'relationship' ) );
    return $result;
}


/**
 * Clean up hierarchy conflicts when native parent-child relationships are created
 *
 * Called when WordPress creates/updates a native parent relationship.
 * Cleanup order is critical:
 * 1. Remove redirects FIRST (child→parent or child→ancestor)
 * 2. Then remove inclusion/container relationships (in either direction)
 *
 * This prevents triggering cascade validation issues when removing relationships.
 *
 * @param int    $term_id   Term ID
 * @param int    $tt_id     Term taxonomy ID
 * @param string $taxonomy  Taxonomy name
 */
function htax_cleanup_hierarchy_conflicts( $term_id, $tt_id, $taxonomy ) {
    // Only process hierarchical taxonomies
    if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
        return;
    }

    // Only process enabled taxonomies
    if ( ! htax_is_taxonomy_enabled( $taxonomy ) ) {
        return;
    }

    $term = htax_get_valid_term( $term_id );
    if ( ! $term ) {
        return;
    }

    // Only act when the parent actually changed.
    // htax_cache_term_taxonomy_before_edit stores the old parent before the edit fires.
    $old_data   = htax_get_transient( 'htax_term_data_' . $tt_id );
    $old_parent = isset( $old_data['parent'] ) ? (int) $old_data['parent'] : 0;
    $new_parent = (int) $term->parent;

    if ( $old_parent === $new_parent ) {
        return; // Hierarchy unchanged — nothing to do
    }

    // Always invalidate the cache when the hierarchy changes. This covers both:
    // - Parent added/changed: previously valid includes may now be filtered as ancestors.
    // - Parent removed: previously filtered group-included terms may now be valid again.
    do_action( 'htax_includes_changed', $term_id, $taxonomy, null, null );

    // Conflict-row detection only applies when the term NOW has a parent.
    // Removing a parent creates no new conflicts (only potentially re-validates old ones).
    if ( empty( $new_parent ) ) {
        return;
    }

    $parent_id = $new_parent;

    htax_log_debug( 'Checking hierarchy conflicts', array(
        'term_id' => $term_id,
        'parent_id' => $parent_id,
        'taxonomy' => $taxonomy
    ) );

    // Delegate to the shared helper which covers inclusions, containers, and exclusions.
    $conflicts_found = htax_remove_hierarchy_conflict_rows( $term_id, $taxonomy );

    if ( $conflicts_found ) {
        htax_log_debug( 'Hierarchy conflicts cleaned up', array(
            'term_id'   => $term_id,
            'parent_id' => $new_parent,
            'taxonomy'  => $taxonomy,
        ) );

        htax_add_admin_notice(
            sprintf(
                /* translators: %d: term ID */
                __( 'HyperTaxonomy: Cleaned up conflicting relationships for term #%d due to hierarchy change. Terms cannot have HyperTaxonomy relationships with their ancestors or descendants.', 'hypertaxonomy' ),
                $term_id
            ),
            'info'
        );

        // Fire htax_includes_changed for all relatives that lost rows (ancestors and descendants).
        $ancestors   = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
        $descendants = get_term_children( $term_id, $taxonomy );
        $all_changed = array_unique( array_merge(
            array( $term_id ),
            $ancestors,
            is_wp_error( $descendants ) ? array() : $descendants
        ) );
        foreach ( $all_changed as $changed_id ) {
            do_action( 'htax_includes_changed', $changed_id, $taxonomy, null, null );
        }
    }
}
