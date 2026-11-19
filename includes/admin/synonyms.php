<?php
/**
 * Synonyms admin page: every synonym pair in HyperTaxonomy-enabled taxonomies, and how each formed.
 *
 * Two terms are synonyms when each is in the other's effective inclusions
 * (htax_load_synonym_terms()). The page lists each ordered pair on its own row and explains,
 * for both directions, every path behind the inclusion: direct, custom inclusion type, group
 * inclusion, or container group (reverse inclusion).
 *
 * @package HyperTaxonomy
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Per-user rows-per-page option, set through Screen Options. */
const HTAX_SYNONYMS_PER_PAGE_OPTION = 'htax_synonyms_per_page';

/**
 * Register the Synonyms submenu under Terms.
 *
 * @since 1.0.0
 */
function htax_add_synonyms_page(): void {
    $hook = add_submenu_page(
        'htax-terms',
        __( 'Synonyms', 'hypertaxonomy' ),
        __( 'Synonyms', 'hypertaxonomy' ),
        'manage_term_relationships',
        'htax-synonyms',
        'htax_render_synonyms_page'
    );
    if ( $hook ) {
        add_action( "load-{$hook}", 'htax_synonyms_screen_options' );
    }
}
add_action( 'admin_menu', 'htax_add_synonyms_page', 12 );

/**
 * Add the rows-per-page Screen Option.
 *
 * @since 1.0.0
 */
function htax_synonyms_screen_options(): void {
    add_screen_option( 'per_page', array(
        'label'   => __( 'Rows per page', 'hypertaxonomy' ),
        'default' => 20,
        'option'  => HTAX_SYNONYMS_PER_PAGE_OPTION,
    ) );
}

/**
 * Save the rows-per-page Screen Option.
 *
 * @since 1.0.0
 * @param mixed  $status Value to save, or false.
 * @param string $option Option name.
 * @param int    $value  Submitted value.
 * @return mixed
 */
function htax_synonyms_save_screen_option( $status, $option, $value ) {
    return HTAX_SYNONYMS_PER_PAGE_OPTION === $option ? max( 1, min( 999, (int) $value ) ) : $status;
}
add_filter( 'set_screen_option_' . HTAX_SYNONYMS_PER_PAGE_OPTION, 'htax_synonyms_save_screen_option', 10, 3 );

/**
 * Terms in a taxonomy that can have synonyms: those that include at least one other term and
 * are included by at least one.
 *
 * A term includes others when it is the source of an inclusion row (to a term or a group),
 * or a member of a group, or of a parent of a group, that is the source of an inclusion row
 * (a container group). It is included when it is the target of an inclusion row (from a term
 * or a container group), or a member of an included group or of one of its subgroups.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy.
 * @return int[] Term IDs.
 */
function htax_get_synonym_candidate_ids( string $taxonomy ): array {
    global $wpdb;
    $table       = $wpdb->prefix . 'htax_term_relationships';
    $includes_in = htax_relationship_type_sql_in( 'includes' );

    $sources = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT source_term_id FROM {$table} WHERE {$includes_in} AND source_taxonomy = %s",
        $taxonomy
    ) );

    $container_groups = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT source_term_id FROM {$table} WHERE {$includes_in} AND source_taxonomy = 'group' AND target_taxonomy = %s",
        $taxonomy
    ) );
    $members = array();
    if ( $container_groups && htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        htax_prime_all_group_terms();
        $groups       = array_map( 'intval', htax_get_group_ancestors( array_map( 'intval', $container_groups ) ) );
        $placeholders = htax_sql_placeholders( count( $groups ) );
        $members      = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT source_term_id FROM {$table}
             WHERE target_term_id IN ({$placeholders})
             AND relationship_type = 'member_of'
             AND source_taxonomy = %s",
            ...array_merge( $groups, array( $taxonomy ) )
        ) );
    }

    $includers = array_unique( array_map( 'intval', array_merge( $sources, $members ) ) );
    if ( ! $includers ) {
        return array();
    }

    // A synonym is also included by something: the target of a term-to-term row, a target of
    // a container group, or a member of an included group or its subgroups. Only terms on
    // both sides need their caches loaded.
    $targets = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT target_term_id FROM {$table} WHERE {$includes_in} AND target_taxonomy = %s",
        $taxonomy
    ) );
    $included_members = array();
    if ( htax_taxonomy_has_feature( $taxonomy, 'group_assignment' ) ) {
        $included_groups = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT target_term_id FROM {$table} WHERE {$includes_in} AND source_taxonomy = %s AND target_taxonomy = 'group'",
            $taxonomy
        ) );
        if ( $included_groups ) {
            $groups           = array_map( 'intval', htax_get_group_hierarchy( array_map( 'intval', $included_groups ) ) );
            $included_members = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT source_term_id FROM {$table}
                 WHERE target_term_id IN (" . htax_sql_placeholders( count( $groups ) ) . ")
                 AND relationship_type = 'member_of'
                 AND source_taxonomy = %s",
                ...array_merge( $groups, array( $taxonomy ) )
            ) );
        }
    }
    $included = array_flip( array_map( 'intval', array_merge( $targets, $included_members ) ) );

    return array_values( array_filter( $includers, static fn( $id ) => isset( $included[ $id ] ) ) );
}

/**
 * Explain every path by which one term includes another.
 *
 * Each path is an array with 'kind' ('direct', 'group' or 'container_group'), 'slug' (the
 * inclusion-based relationship type: 'includes' or a custom type) and, for the group kinds,
 * 'group' (WP_Term). Exclusions and
 * the hierarchy rule are not checked here: callers pass pairs already in the effective set.
 *
 * @since 1.0.0
 * @param WP_Term $term     The including term.
 * @param WP_Term $included The included term.
 * @return array[] Paths, possibly empty.
 */
function htax_get_inclusion_paths( WP_Term $term, WP_Term $included ): array {
    $paths    = array();
    $taxonomy = $term->taxonomy;
    $groups   = 'group' !== $taxonomy
        && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' )
        && htax_taxonomy_has_feature( $taxonomy, 'group_assignment' );

    // The included term's groups, with their ancestors: a member of a subgroup is a member
    // of its parent groups too.
    $included_groups = array();
    // The including term's groups, for the container group path.
    $term_groups = array();
    if ( $groups ) {
        $included_groups = array_map( 'intval', htax_get_group_ancestors( htax_get_term_group_ids( (int) $included->term_id ) ) );
        $term_groups     = htax_get_term_group_ids( (int) $term->term_id );
    }

    foreach ( htax_get_relationship_type_slugs( 'includes' ) as $slug ) {
        // Term → term row.
        if ( in_array( (int) $included->term_id, htax_get_direct_rel_term_ids( (int) $term->term_id, $slug ), true ) ) {
            $paths[] = array( 'kind' => 'direct', 'slug' => $slug );
        }
        if ( ! $groups ) {
            continue;
        }
        // Term → group row: the included term is a member of that group or of a subgroup of it.
        // One path per chain, from the included group down to the member's group.
        foreach ( htax_get_direct_rel_group_ids( (int) $term->term_id, $slug ) as $group_id ) {
            if ( ! in_array( (int) $group_id, $included_groups, true ) ) {
                continue;
            }
            foreach ( htax_get_term_group_ids( (int) $included->term_id ) as $member_group ) {
                $up = htax_group_chain_up( (int) $member_group, (int) $group_id );
                if ( $up ) {
                    $paths[] = array( 'kind' => 'group', 'slug' => $slug, 'group' => get_term( (int) $group_id, 'group' ), 'chain' => array_reverse( $up ) );
                }
            }
        }
        // Group → included term row (a container group): the including term is a member of that
        // group or of one of its parent groups. One path per chain, from the container group up
        // to the including term's group.
        foreach ( htax_get_reverse_rel_group_ids( (int) $included->term_id, $slug ) as $group_id ) {
            foreach ( $term_groups as $member_group ) {
                $up = htax_group_chain_up( (int) $group_id, (int) $member_group );
                if ( $up ) {
                    $paths[] = array( 'kind' => 'container_group', 'slug' => $slug, 'group' => get_term( (int) $group_id, 'group' ), 'chain' => $up );
                }
            }
        }
    }

    return array_values( array_filter( $paths, static fn( $p ) => ! isset( $p['group'] ) || $p['group'] instanceof WP_Term ) );
}

/**
 * The chain of groups from one group up through its parents to another, inclusive.
 *
 * @since 1.0.0
 * @param int $from Group to start from.
 * @param int $to   Group to stop at: $from itself or one of its parent groups.
 * @return WP_Term[] Groups from $from to $to, or an empty array if $to is not on the way up.
 */
function htax_group_chain_up( int $from, int $to ): array {
    $chain = array();
    $ids   = array_merge( array( $from ), array_map( 'intval', get_ancestors( $from, 'group', 'taxonomy' ) ) );
    foreach ( $ids as $id ) {
        $group = get_term( $id, 'group' );
        if ( ! $group instanceof WP_Term ) {
            return array();
        }
        $chain[] = $group;
        if ( $id === $to ) {
            return $chain;
        }
    }
    return array();
}

/**
 * Load every group into the term cache in one query, once per request, so the get_term()
 * and get_ancestors() calls on groups that follow don't query one by one.
 *
 * @since 1.0.0
 */
function htax_prime_all_group_terms(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    get_terms( array(
        'taxonomy'               => 'group',
        'hide_empty'             => false,
        'update_term_meta_cache' => false,
    ) );
}

/**
 * Every synonym pair in the HyperTaxonomy-enabled taxonomies, as ordered rows.
 *
 * Built from the cached inclusion and container ID sets only. The paths behind each pair are
 * added separately (htax_add_synonym_paths()), for just the rows that need them.
 *
 * @since 1.0.0
 * @return array[] Rows: 'term', 'synonym' (WP_Term), 'taxonomy' (string), 'differ' (bool: one
 *                 of the two includes terms the other doesn't), with 'term_only' and
 *                 'synonym_only' (int[]: the terms only that side includes, ordered by name).
 */
function htax_get_synonym_rows(): array {
    $rows = array();
    foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
        if ( 'group' === $taxonomy || ! htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
            continue;
        }
        $candidates = htax_get_synonym_candidate_ids( $taxonomy );
        if ( ! $candidates ) {
            continue;
        }
        // Every synonym is itself a candidate, so these cover both sides of every pair.
        _prime_term_caches( $candidates, false );
        htax_prime_term_transients( $candidates, array( 'htax_inc_', 'htax_cont_', 'htax_redir_', 'htax_groups_' ) );

        foreach ( $candidates as $term_id ) {
            $synonym_ids = htax_load_synonym_term_ids( $term_id, false );
            $term        = $synonym_ids ? get_term( $term_id, $taxonomy ) : null;
            if ( ! $term instanceof WP_Term ) {
                continue;
            }
            // Each other's posts aside, do the two archives include the same terms?
            $own = htax_load_included_term_ids( $term, false, false );
            foreach ( $synonym_ids as $synonym_id ) {
                $synonym = get_term( $synonym_id, $taxonomy );
                if ( ! $synonym instanceof WP_Term ) {
                    continue;
                }
                $other        = htax_load_included_term_ids( $synonym, false, false );
                $term_only    = array_values( array_diff( $own, $other, array( (int) $synonym_id ) ) );
                $synonym_only = array_values( array_diff( $other, $own, array( (int) $term_id ) ) );
                $rows[]       = array(
                    'term'         => $term,
                    'synonym'      => $synonym,
                    'taxonomy'     => $taxonomy,
                    'differ'       => $term_only || $synonym_only,
                    'term_only'    => $term_only,
                    'synonym_only' => $synonym_only,
                );
            }
        }
    }
    return $rows;
}

/**
 * Add the inclusion paths of both directions to synonym rows: 'forward' (term includes
 * synonym) and 'backward' (synonym includes term).
 *
 * @since 1.0.0
 * @param array[] $rows Rows from htax_get_synonym_rows().
 * @return array[] The rows, with paths.
 */
function htax_add_synonym_paths( array $rows ): array {
    static $paths = array();
    if ( $rows ) {
        htax_prime_all_group_terms();
        // The direct and group relationship caches of both terms of every row, per inclusion type.
        $keys = array();
        foreach ( $rows as $row ) {
            foreach ( array( $row['term']->term_id, $row['synonym']->term_id ) as $id ) {
                foreach ( htax_get_relationship_type_slugs( 'includes' ) as $slug ) {
                    $keys[] = 'htax_direct_rel_' . $slug . '_' . $id;
                    $keys[] = 'htax_direct_rel_grp_' . $slug . '_' . $id;
                    $keys[] = 'htax_reverse_rel_grp_' . $slug . '_' . $id;
                }
            }
        }
        htax_prime_transients( $keys );
    }
    foreach ( $rows as &$row ) {
        $forward  = $row['term']->term_id . '>' . $row['synonym']->term_id;
        $backward = $row['synonym']->term_id . '>' . $row['term']->term_id;
        $paths[ $forward ]  ??= htax_get_inclusion_paths( $row['term'], $row['synonym'] );
        $paths[ $backward ] ??= htax_get_inclusion_paths( $row['synonym'], $row['term'] );
        $row['forward']  = $paths[ $forward ];
        $row['backward'] = $paths[ $backward ];
    }
    unset( $row );
    return $rows;
}

/**
 * One inclusion path in compact form: a symbol for its kind, then what it goes through.
 *
 * → direct, ⇢ direct by a custom type, ⇒ group inclusion with its chain of groups
 * (Group › Subgroup), ⇐ container group with its chain (Subgroup ‹ Group); a custom type's
 * label follows group paths in parentheses.
 *
 * @since 1.0.0
 * @param array $path Path from htax_get_inclusion_paths().
 * @return string HTML.
 */
function htax_render_inclusion_path_compact( array $path ): string {
    $type   = htax_get_relationship_type( $path['slug'] );
    $custom = ( 'includes' !== $path['slug'] && $type )
        /* translators: %s: custom relationship type label */
        ? esc_html( sprintf( __( '“%s”', 'hypertaxonomy' ), $type['label'] ) )
        : '';

    if ( 'direct' === $path['kind'] ) {
        return $custom
            ? '<span aria-hidden="true">&#8674;</span> ' . $custom
            : '<span aria-hidden="true">&rarr;</span> ' . esc_html__( 'direct', 'hypertaxonomy' );
    }

    $links = array();
    foreach ( $path['chain'] ?? array( $path['group'] ) as $chain_group ) {
        $link    = get_edit_term_link( $chain_group->term_id, 'group' );
        $links[] = $link
            ? '<a href="' . esc_url( $link ) . '">' . esc_html( $chain_group->name ) . '</a>'
            : esc_html( $chain_group->name );
    }
    $is_container = 'container_group' === $path['kind'];
    return '<span aria-hidden="true">' . ( $is_container ? '&lArr;' : '&rArr;' ) . '</span> '
        . implode( $is_container ? ' &lsaquo; ' : ' &rsaquo; ', $links )
        . ( $custom ? ' (' . $custom . ')' : '' );
}

/**
 * Describe one inclusion path for display.
 *
 * @since 1.0.0
 * @param array $path Path from htax_get_inclusion_paths().
 * @return string HTML.
 */
function htax_describe_inclusion_path( array $path ): string {
    $type       = htax_get_relationship_type( $path['slug'] );
    $type_label = ( 'includes' !== $path['slug'] && $type ) ? $type['label'] : '';

    if ( isset( $path['group'] ) ) {
        // The whole chain of groups: down from the included group (A › B), or up from the
        // container group (C ‹ B); each group links to its edit screen.
        $links = array();
        foreach ( $path['chain'] ?? array( $path['group'] ) as $chain_group ) {
            $link    = get_edit_term_link( $chain_group->term_id, 'group' );
            $links[] = $link
                ? '<a href="' . esc_url( $link ) . '">' . esc_html( $chain_group->name ) . '</a>'
                : esc_html( $chain_group->name );
        }
        $group = implode( 'container_group' === $path['kind'] ? ' &lsaquo; ' : ' &rsaquo; ', $links );
    }

    switch ( $path['kind'] ) {
        case 'direct':
            return $type_label
                /* translators: %s: custom relationship type label */
                ? esc_html( sprintf( __( 'directly, by the custom relationship “%s”', 'hypertaxonomy' ), $type_label ) )
                : esc_html__( 'directly', 'hypertaxonomy' );
        case 'group':
            return $type_label
                /* translators: 1: group name (linked), 2: custom relationship type label */
                ? sprintf( __( 'via group %1$s (group inclusion, custom relationship “%2$s”)', 'hypertaxonomy' ), $group, esc_html( $type_label ) )
                /* translators: %s: group name (linked) */
                : sprintf( __( 'via group %s (group inclusion)', 'hypertaxonomy' ), $group );
        case 'container_group':
            return $type_label
                /* translators: 1: group name (linked), 2: custom relationship type label */
                ? sprintf( __( 'via container group %1$s (reverse inclusion, custom relationship “%2$s”)', 'hypertaxonomy' ), $group, esc_html( $type_label ) )
                /* translators: %s: group name (linked) */
                : sprintf( __( 'via container group %s (reverse inclusion)', 'hypertaxonomy' ), $group );
    }
    return '';
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for the Synonyms page.
 *
 * @since 1.0.0
 */
class HyperTaxonomy_Synonyms_Table extends WP_List_Table {

    /** @var string[] Inclusion type filter options: path kind => label. */
    private array $kind_options;

    /** @var string[] Relationship type filter options: inclusion-based type slug => label. */
    private array $type_options;

    public function __construct() {
        parent::__construct( array(
            'singular' => 'synonym',
            'plural'   => 'synonyms',
            'ajax'     => false,
        ) );
        $this->kind_options = array(
            'direct'          => __( 'Direct inclusion', 'hypertaxonomy' ),
            'group'           => __( 'Group inclusion', 'hypertaxonomy' ),
            'container_group' => __( 'Container group', 'hypertaxonomy' ),
        );
        $this->type_options = array();
        foreach ( htax_get_relationship_type_slugs( 'includes' ) as $slug ) {
            $type = htax_get_relationship_type( $slug );
            if ( $type ) {
                $this->type_options[ $slug ] = $type['label'];
            }
        }
    }

    public function get_columns() {
        return array(
            'term'     => __( 'Term', 'hypertaxonomy' ),
            'synonym'  => __( 'Synonym', 'hypertaxonomy' ),
            'taxonomy' => __( 'Taxonomy', 'hypertaxonomy' ),
            'how'      => __( 'Inclusion paths', 'hypertaxonomy' ),
            'actions'  => __( 'Actions', 'hypertaxonomy' ),
        );
    }

    protected function get_sortable_columns() {
        // Every pair appears twice (A/B and B/A), so sorting by the Synonym column would only
        // show the same rows from the other side; the Taxonomy column has its own filter.
        return array( 'term' => array( 'term', false ) );
    }

    protected function get_primary_column_name() {
        return 'term';
    }

    /**
     * Read the current filters from the request.
     *
     * @return array{taxonomy:string,kind:string,search:string}
     */
    private function filters(): array {
        $filters = array(
            'taxonomy' => isset( $_GET['htax_taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['htax_taxonomy'] ) ) : '',
            'kind'     => isset( $_GET['htax_kind'] ) ? sanitize_key( wp_unslash( $_GET['htax_kind'] ) ) : '',
            'type'     => isset( $_GET['htax_type'] ) ? sanitize_key( wp_unslash( $_GET['htax_type'] ) ) : '',
            'differ'   => ! empty( $_GET['htax_differ'] ),
            'search'   => isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '',
        );
        if ( ! isset( $this->kind_options[ $filters['kind'] ] ) ) {
            $filters['kind'] = '';
        }
        if ( ! isset( $this->type_options[ $filters['type'] ] ) ) {
            $filters['type'] = '';
        }
        return $filters;
    }

    public function prepare_items() {
        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );

        $filters = $this->filters();
        $rows    = array_filter( htax_get_synonym_rows(), static function ( $row ) use ( $filters ) {
            if ( '' !== $filters['taxonomy'] && $row['taxonomy'] !== $filters['taxonomy'] ) {
                return false;
            }
            if ( $filters['differ'] && ! $row['differ'] ) {
                return false;
            }
            if ( '' !== $filters['search']
                 && false === mb_stripos( $row['term']->name, $filters['search'] )
                 && false === mb_stripos( $row['synonym']->name, $filters['search'] ) ) {
                return false;
            }
            return true;
        } );

        // Path kind and relationship type must match on the same path, in either direction.
        // Only these filters need every row's paths; otherwise only the shown rows get them.
        $path_filter = '' !== $filters['kind'] || '' !== $filters['type'];
        if ( $path_filter ) {
            $rows = array_filter( htax_add_synonym_paths( $rows ), static function ( $row ) use ( $filters ) {
                foreach ( array_merge( $row['forward'], $row['backward'] ) as $path ) {
                    if ( ( '' === $filters['kind'] || $path['kind'] === $filters['kind'] )
                         && ( '' === $filters['type'] || $path['slug'] === $filters['type'] ) ) {
                        return true;
                    }
                }
                return false;
            } );
        }

        // Term column only, A–Z by default; ties by the synonym's name.
        $order = ( isset( $_GET['order'] ) && 'desc' === sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? -1 : 1;
        usort( $rows, static function ( $a, $b ) use ( $order ) {
            return ( strcasecmp( $a['term']->name, $b['term']->name ) ?: strcasecmp( $a['synonym']->name, $b['synonym']->name ) ) * $order;
        } );

        $per_page = $this->get_items_per_page( HTAX_SYNONYMS_PER_PAGE_OPTION, 20 );
        $total    = count( $rows );
        $this->set_pagination_args( array(
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ) );
        $this->items = array_slice( $rows, ( $this->get_pagenum() - 1 ) * $per_page, $per_page );

        // Each row shows one direction; its arrow links to the reverse row, on whichever page
        // of this sorted, filtered list it falls (the filters match both rows of a pair).
        $positions = array();
        foreach ( array_values( $rows ) as $position => $row ) {
            $positions[ $row['term']->term_id . '>' . $row['synonym']->term_id ] = $position;
        }
        foreach ( $this->items as &$item ) {
            $reverse              = $item['synonym']->term_id . '>' . $item['term']->term_id;
            $item['reverse_page'] = isset( $positions[ $reverse ] ) ? (int) floor( $positions[ $reverse ] / $per_page ) + 1 : 0;
        }
        unset( $item );
        if ( ! $path_filter ) {
            $this->items = htax_add_synonym_paths( $this->items );
        }

        // The "Archives differ" lines name up to five terms per side: load them in one query.
        $shown = array();
        foreach ( $this->items as $item ) {
            $shown = array_merge( $shown, array_slice( $item['term_only'], 0, 5 ), array_slice( $item['synonym_only'], 0, 5 ) );
        }
        if ( $shown ) {
            _prime_term_caches( array_unique( $shown ), false );
        }
    }

    /**
     * A term's name, linked to its edit screen, with its post count and hidden state.
     *
     * @param WP_Term $term Term.
     * @return string HTML.
     */
    private function term_cell( WP_Term $term ): string {
        $link = get_edit_term_link( $term->term_id, $term->taxonomy );
        $html = $link
            ? '<a class="row-title" href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>'
            : '<strong>' . esc_html( $term->name ) . '</strong>';
        /* translators: %s: number of posts */
        $html .= ' <span class="count">' . esc_html( sprintf( __( '(%s)', 'hypertaxonomy' ), number_format_i18n( (int) $term->count ) ) ) . '</span>';
        // The cached list of hidden IDs, rather than each term's meta, one query per term.
        static $hidden = null;
        $hidden ??= array_flip( array_map( 'intval', htax_get_all_hidden_term_ids() ) );
        if ( isset( $hidden[ (int) $term->term_id ] ) ) {
            $html .= ' — <span class="post-state">' . esc_html_x( 'Hidden', 'term state', 'hypertaxonomy' ) . '</span>';
        }
        return $html;
    }

    protected function column_term( $item ) {
        return $this->term_cell( $item['term'] );
    }

    /**
     * Merge link: opens Merge Terms with this row's term as the source and its synonym as the
     * target. The column exists only for users who can merge (manage_options).
     *
     * @param array $item Row.
     * @return string HTML.
     */
    protected function column_actions( $item ) {
        // A bulleted list; in each entry only the operative words are linked. Merge comes first,
        // then links to the parts of the two terms' edit screens that hold this direction of
        // the pair: the including term's Included field for direct and group inclusions (with
        // the synonym's Term Groups for the group it belongs to), the synonym's Container field
        // for container groups (with the including term's Term Groups), or a custom type's tab.
        $term    = $item['term'];
        $synonym = $item['synonym'];
        $entries = array();

        if ( current_user_can( 'manage_options' ) ) {
            $url = add_query_arg( array(
                'page'     => 'htax-merge-terms',
                'taxonomy' => $item['taxonomy'],
                'source'   => $term->term_id,
                'target'   => $synonym->term_id,
            ), admin_url( 'admin.php' ) );
            $entries[] = '<span aria-hidden="true">&#10697;</span> ' . sprintf(
                /* translators: 1: linked "Merge", 2: name of the synonym the term would be merged into */
                esc_html__( '%1$s into %2$s', 'hypertaxonomy' ),
                '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Merge', 'hypertaxonomy' ) . '</a>',
                esc_html( $synonym->name )
            );
        }

        $sections = array();
        $add      = static function ( WP_Term $owner, string $anchor, string $label ) use ( &$sections ) {
            $edit = get_edit_term_link( $owner->term_id, $owner->taxonomy );
            if ( $edit ) {
                $sections[ $edit . '#' . $anchor ] = array( $owner->name, $label );
            }
        };
        foreach ( $item['forward'] as $path ) {
            $type   = htax_get_relationship_type( $path['slug'] );
            $custom = 'includes' !== $path['slug'] && $type;
            /* translators: %s: custom relationship type label */
            $custom_label = $custom ? sprintf( __( '“%s”', 'hypertaxonomy' ), $type['label'] ) : '';
            switch ( $path['kind'] ) {
                case 'direct':
                case 'group':
                    if ( $custom ) {
                        $add( $term, 'htax-rel-' . $path['slug'], $custom_label );
                    } else {
                        $add( $term, 'htax-included', __( 'included terms', 'hypertaxonomy' ) );
                    }
                    if ( 'group' === $path['kind'] ) {
                        $add( $synonym, 'htax-term-groups', __( 'term groups', 'hypertaxonomy' ) );
                    }
                    break;
                case 'container_group':
                    if ( $custom ) {
                        $add( $synonym, 'htax-rel-' . $path['slug'], $custom_label );
                    } else {
                        $add( $synonym, 'htax-containers', __( 'container terms', 'hypertaxonomy' ) );
                    }
                    $add( $term, 'htax-term-groups', __( 'term groups', 'hypertaxonomy' ) );
                    break;
            }
        }
        foreach ( $sections as $url => $entry ) {
            $entries[] = '<span aria-hidden="true">&#9998;</span> ' . sprintf(
                /* translators: 1: term name, 2: linked part of its edit screen (e.g. "included terms") */
                esc_html__( '%1$s: %2$s', 'hypertaxonomy' ),
                esc_html( $entry[0] ),
                '<a href="' . esc_url( $url ) . '">' . esc_html( $entry[1] ) . '</a>'
            );
        }

        return '<ul class="htax-synonym-actions"><li>' . implode( '</li><li>', $entries ) . '</li></ul>';
    }

    /**
     * Anchor ID of the row for a term and its synonym.
     *
     * @param WP_Term $term    Term.
     * @param WP_Term $synonym Synonym.
     * @return string
     */
    private static function row_id( WP_Term $term, WP_Term $synonym ): string {
        return 'htax-syn-' . (int) $term->term_id . '-' . (int) $synonym->term_id;
    }

    /**
     * Render a row with an ID, for the reverse-row links.
     *
     * @param array $item Row.
     */
    public function single_row( $item ) {
        echo '<tr id="' . esc_attr( self::row_id( $item['term'], $item['synonym'] ) ) . '">';
        $this->single_row_columns( $item );
        echo '</tr>';
    }

    protected function column_synonym( $item ) {
        return $this->term_cell( $item['synonym'] );
    }

    protected function column_taxonomy( $item ) {
        $tax = get_taxonomy( $item['taxonomy'] );
        return esc_html( $tax ? $tax->labels->name : $item['taxonomy'] );
    }

    protected function column_how( $item ) {
        // One line per path, the row's term and synonym being in their own columns: → direct,
        // ⇢ custom type, ⇒ group inclusion (Group › Subgroup), ⇐ container group (Subgroup ‹
        // Group). The full wording is the line's hover text.
        $html = '';
        foreach ( $item['forward'] as $path ) {
            $html .= '<p class="htax-synonym-path" title="' . esc_attr( wp_strip_all_tags( htax_describe_inclusion_path( $path ) ) ) . '">'
                   . htax_render_inclusion_path_compact( $path ) . '</p>';
        }
        if ( '' === $html ) {
            $html = '<p class="htax-synonym-path">' . esc_html__( 'through a path no longer stored', 'hypertaxonomy' ) . '</p>';
        }

        // The other direction is the reverse row's own line: link to it.
        if ( ! empty( $item['reverse_page'] ) ) {
            $url   = add_query_arg( 'paged', $item['reverse_page'] ) . '#' . self::row_id( $item['synonym'], $item['term'] );
            /* translators: 1: synonym, 2: term */
            $label = sprintf( __( 'How %1$s includes %2$s', 'hypertaxonomy' ), $item['synonym']->name, $item['term']->name );
            $html .= '<p class="htax-synonym-path"><a class="htax-synonym-reverse" href="' . esc_url( $url ) . '" title="' . esc_attr( $label ) . '">'
                   . '<span aria-hidden="true">&#8644;</span> ' . esc_html__( 'reverse', 'hypertaxonomy' )
                   . '<span class="screen-reader-text">: ' . esc_html( $label ) . '</span></a></p>';
        }

        // Name the terms behind an "Archives differ" match, so it can be acted on.
        foreach ( array( array( $item['term'], $item['term_only'] ), array( $item['synonym'], $item['synonym_only'] ) ) as list( $term, $only ) ) {
            if ( ! $only ) {
                continue;
            }
            $names = array();
            foreach ( array_slice( $only, 0, 5 ) as $included_id ) {
                $included = get_term( $included_id );
                if ( $included instanceof WP_Term ) {
                    $names[] = esc_html( $included->name );
                }
            }
            if ( count( $only ) > 5 ) {
                /* translators: %s: number of further terms */
                $names[] = esc_html( sprintf( _n( '%s more', '%s more', count( $only ) - 5, 'hypertaxonomy' ), number_format_i18n( count( $only ) - 5 ) ) );
            }
            $html .= '<p class="htax-synonym-path description">' . sprintf(
                /* translators: 1: term name, 2: comma-separated names of the terms only it includes */
                esc_html__( 'Archives differ: only %1$s includes %2$s', 'hypertaxonomy' ),
                '<strong>' . esc_html( $term->name ) . '</strong>',
                implode( wp_get_list_item_separator(), $names )
            ) . '</p>';
        }
        return $html;
    }

    protected function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }
        $filters    = $this->filters();
        $taxonomies = array();
        foreach ( htax_get_enabled_taxonomies() as $taxonomy ) {
            if ( 'group' !== $taxonomy && htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
                $obj                     = get_taxonomy( $taxonomy );
                $taxonomies[ $taxonomy ] = $obj ? $obj->labels->name : $taxonomy;
            }
        }
        ?>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="htax-synonyms-taxonomy"><?php esc_html_e( 'Filter by taxonomy', 'hypertaxonomy' ); ?></label>
            <select name="htax_taxonomy" id="htax-synonyms-taxonomy">
                <option value=""><?php esc_html_e( 'All taxonomies', 'hypertaxonomy' ); ?></option>
                <?php foreach ( $taxonomies as $slug => $name ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['taxonomy'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
            <label class="screen-reader-text" for="htax-synonyms-kind"><?php esc_html_e( 'Filter by inclusion type', 'hypertaxonomy' ); ?></label>
            <select name="htax_kind" id="htax-synonyms-kind">
                <option value=""><?php esc_html_e( 'All inclusion types', 'hypertaxonomy' ); ?></option>
                <?php foreach ( $this->kind_options as $slug => $name ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['kind'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ( count( $this->type_options ) > 1 ) : ?>
            <label class="screen-reader-text" for="htax-synonyms-type"><?php esc_html_e( 'Filter by relationship type', 'hypertaxonomy' ); ?></label>
            <select name="htax_type" id="htax-synonyms-type">
                <option value=""><?php esc_html_e( 'All relationship types', 'hypertaxonomy' ); ?></option>
                <?php foreach ( $this->type_options as $slug => $name ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['type'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <label class="screen-reader-text" for="htax-synonyms-differ"><?php esc_html_e( 'Filter by archives', 'hypertaxonomy' ); ?></label>
            <select name="htax_differ" id="htax-synonyms-differ">
                <option value=""><?php esc_html_e( 'All archives', 'hypertaxonomy' ); ?></option>
                <option value="1" <?php selected( $filters['differ'] ); ?>><?php esc_html_e( 'Archives differ', 'hypertaxonomy' ); ?></option>
            </select>
            <?php submit_button( __( 'Filter', 'hypertaxonomy' ), '', 'filter_action', false ); ?>
        </div>
        <?php
    }

    public function no_items() {
        $filters = $this->filters();
        if ( '' !== $filters['taxonomy'] || '' !== $filters['kind'] || '' !== $filters['type'] || $filters['differ'] || '' !== $filters['search'] ) {
            esc_html_e( 'No synonyms match these filters.', 'hypertaxonomy' );
            return;
        }
        esc_html_e( 'No synonyms yet. Two terms become synonyms when each includes the other, directly or through groups.', 'hypertaxonomy' );
    }
}

/**
 * Render the Synonyms page.
 *
 * @since 1.0.0
 */
function htax_render_synonyms_page(): void {
    if ( ! current_user_can( 'manage_term_relationships' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'hypertaxonomy' ) );
    }

    $table = new HyperTaxonomy_Synonyms_Table();
    $table->prepare_items();
    ob_start();
    ?>
        .wp-list-table.synonyms .column-term,
        .wp-list-table.synonyms .column-synonym { width: 18%; }
        .wp-list-table.synonyms .column-taxonomy { width: 8%; }
        .wp-list-table.synonyms .column-actions { width: 26%; }
        .wp-list-table.synonyms .htax-synonym-actions { margin: 0; list-style: none; }
        .wp-list-table.synonyms .htax-synonym-actions li { margin: 0 0 .3em; }
        .wp-list-table.synonyms tr:target td,
        .wp-list-table.synonyms tr:target th { background: #fcf9e8; }
        .wp-list-table.synonyms .htax-synonym-reverse { text-decoration: none; }
        .wp-list-table.synonyms .htax-synonym-path { margin: 0 0 .4em; }
    <?php
    htax_print_inline_style( 'htax-synonyms', ob_get_clean() );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e( 'Synonyms', 'hypertaxonomy' ); ?></h1>
        <hr class="wp-header-end">

        <p class="htax-synonyms-intro">
            <?php esc_html_e( 'Two terms are synonyms when each includes the other, directly or through groups. Like synonyms in natural language, synonym terms are rarely interchangeable in every context: their meanings mostly overlap, but may differ in subtle ways. In HyperTaxonomy, each synonym\'s archive shows the posts directly assigned to the other term. They don\'t share relationships, though: if one synonym includes other terms and the other doesn\'t, the first synonym\'s archive also shows posts from its own inclusions, which the second synonym\'s archive doesn\'t.', 'hypertaxonomy' ); ?>
            <a href="<?php echo esc_url( 'https://hypertaxonomy.org/hypertaxonomy/user-guide/term-relationships.html#synonyms' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'hypertaxonomy' ); ?></a>
        </p>

        <form method="get">
            <input type="hidden" name="page" value="htax-synonyms" />
            <?php
            $table->search_box( __( 'Search terms', 'hypertaxonomy' ), 'htax-synonyms' );
            $table->display();
            ?>
        </form>
    </div>
    <?php
}
