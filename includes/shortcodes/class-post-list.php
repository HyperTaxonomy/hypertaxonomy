<?php
/**
 * Post List Shortcodes
 *
 * [ht_post_list]     — Display posts for configured term/group IDs.
 * [ht_related_posts] — Display posts sharing taxonomy terms with the current post.
 *
 * Both shortcodes render server-side HTML using the same CSS class structure as
 * the search widget AJAX results (htax-result-item, htax-result-content, etc.)
 * so the search widget stylesheet covers them without extra CSS.
 *
 * Pagination loads additional pages via AJAX, replacing the posts and navigation
 * within the output block. No URL changes or page reloads occur.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ===========================================================================
// [htax_post_list]
// ===========================================================================

class HyperTaxonomy_Shortcode_Post_List extends HyperTaxonomy_Shortcode_Base {

    public function __construct() {
        add_shortcode( 'ht_post_list', array( $this, 'shortcode_handler' ) );

        // Register AJAX actions once (static flag prevents duplicate registration
        // when HyperTaxonomy_Shortcode_Related_Posts is also instantiated).
        static $ajax_registered = false;
        if ( ! $ajax_registered ) {
            add_action( 'wp_ajax_htax_post_list_page',        array( $this, 'ajax_load_page' ) );
            add_action( 'wp_ajax_nopriv_htax_post_list_page', array( $this, 'ajax_load_page' ) );
            $ajax_registered = true;
        }
    }

    protected function get_default_atts() {
        return array(
            // Term/group selection — same 4-step logic as term display shortcodes
            'taxonomy'       => 'post_tag',
            'include_terms'  => '',
            'include_groups' => '',
            'exclude_terms'  => '',
            'exclude_groups' => '',

            // Filters (AND subqueries — restrict results without affecting scoring)
            // filter_terms:     ALL listed term IDs must be present in the post
            // filter_groups:    at least one term from EACH listed group must be present
            // filter_out_terms: posts with ANY of these term IDs are excluded
            // filter_out_groups: posts with any term from ANY of these groups are excluded
            'filter_terms'      => '',
            'filter_groups'     => '',
            'filter_out_terms'  => '',
            'filter_out_groups' => '',

            // Query
            'posts_per_page' => '10',
            'max_results'    => '0',  // 0 = no cap; set equal to posts_per_page to prevent pagination
            'post_type'      => 'post',

            // Sort — same format as htax_search_form orderby: "field:dir,field:dir"
            // Valid fields: date, modified, title, author, comment_count, ID, relevance
            'orderby'        => 'date:desc',

            // Display — same options as htax_search_results shortcode
            'show_thumbnail' => 'true',
            'show_excerpt'   => 'true',
            'meta'           => '{date} by {author}',
            'template'       => 'default',
            'format'         => 'ul',     // ul | ol | div. ul/ol: <li> list with title link only. div: full article card with thumbnail/excerpt/meta.
            'class'          => '',       // for div format: added to wrapper; for ul/ol: added to each <li>

            // Shown when no results are found; empty = render nothing
            'no_results_message' => '',

            // Optional keyword search — when set, posts are scored by FULLTEXT MATCH
            // in addition to term relevance. Supports field operators: title:, content:,
            // excerpt: (same syntax as the search widget). Unqualified terms match all fields.
            'search' => '',

            // Image size for show_thumbnail in div format (any registered WP size).
            'image_size' => 'medium',

            // Number of columns for multi-column layout (div: CSS grid; ul/ol: column-count).
            'columns' => '1',

            // Content injected before/after each result item.
            // Supports post placeholders: {id}, {title}, {url}, {date}, {modified},
            // {comment_count}, {post_type}, {slug}, {author}, {excerpt},
            // {excerpt_text}, {image_src}, {image_alt}, {image_width}, {image_height},
            // {thumbnail}, {thumbnail:size}.
            // HTML allowed (wp_kses_post filtered).
            'prepend' => '',
            'append'  => '',

            // Sort order picker — comma-separated field slugs in display order.
            // Empty = no picker rendered. e.g. "date,modified,title"
            'sort_order_options' => '',

            // Inline filter panel — simplified alternative to [htax_filter_options].
            // filters: comma-separated keywords (date_published, date_modified, author, taxonomy slug).
            // filter_options: comma-separated positional options aligned to filters.
            'filters'        => '',
            'filter_options' => '',

            // Horizontal alignment of each control within the controls bar.
            // Accepted values: '' (default/left), 'left', 'right', 'center'.
            'sort_order_align' => '',
            'filters_align'    => '',
        );
    }

    /**
     * Shortcode handler.
     */
    public function shortcode_handler( $atts ) {
        $this->enqueue_styles();
        $this->enqueue_scripts();
        $this->htax_atts = shortcode_atts( $this->get_default_atts(), $atts );

        $taxonomy         = $this->sanitize_taxonomy_att( $this->htax_atts['taxonomy'] );
        $term_ids         = $this->get_query_term_ids( $this->htax_atts );
        $exclude_post_ids = $this->get_exclude_post_ids();

        if ( empty( $term_ids ) ) {
            return $this->render_no_results();
        }

        // When the query terms come from a source post (related posts), weight each
        // term by how central it is to that post so results sharing its main topics
        // rank above those sharing an incidental tag. Plain [ht_post_list] has no
        // source post (id 0) and uses equal weights.
        $source_post_id = $this->get_source_post_id();
        $term_weights   = $source_post_id
            ? htax_get_post_term_strengths( $source_post_id, $term_ids )
            : array();

        // Query page 1
        $result = $this->query_posts_by_term_ids( $term_ids, $taxonomy, $this->htax_atts, $exclude_post_ids, 1, $term_weights );
        if ( empty( $result['post_ids'] ) ) {
            return $this->render_no_results();
        }

        // Fetch WP_Post objects in the SQL-determined order
        $query = new WP_Query( array(
            'post__in'               => $result['post_ids'],
            'orderby'                => 'post__in',
            'posts_per_page'         => count( $result['post_ids'] ),
            'post_type'              => sanitize_key( $this->htax_atts['post_type'] ),
            'post_status'            => 'publish',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ) );

        return $this->render_post_list( $query->posts, $term_ids, $exclude_post_ids, 1, $result['max_pages'], $source_post_id );
    }

    /**
     * Return post IDs to exclude from results.
     *
     * Returns empty for [htax_post_list]. Overridden by Related Posts to exclude the
     * current post from its own related posts block.
     *
     * @return int[]
     */
    protected function get_exclude_post_ids() {
        return array();
    }

    /**
     * Source post whose term centrality weights the query, or 0 when there is none.
     *
     * [ht_post_list] queries author-specified terms with equal weight (0). Related
     * Posts overrides this to return the current post so its terms are weighted by
     * how central each is to that post.
     *
     * @return int
     */
    protected function get_source_post_id() {
        return 0;
    }

    /**
     * Resolve term IDs to query from shortcode attributes.
     *
     * Uses the same 4-step include/exclude logic as the search widget field
     * and term display shortcodes:
     *   1. Include groups  → 2. Exclude groups  → 3. Include terms  → 4. Exclude terms
     *
     * If no include filter is specified at all, returns empty (avoids dumping all
     * site posts when the shortcode has no terms configured).
     *
     * Overridden by HyperTaxonomy_Shortcode_Related_Posts to derive terms from the
     * current post context instead of from hardcoded attributes.
     *
     * @param  array $atts Merged shortcode attributes.
     * @return int[]       Term IDs to query.
     */
    protected function get_query_term_ids( $atts ) {
        $taxonomy = sanitize_key( $atts['taxonomy'] );

        $resolved = $this->resolve_term_ids( $atts, $taxonomy );

        // No include filter at all — return empty rather than showing all posts
        if ( ! $resolved['include_groups_specified'] && ! $resolved['include_terms_specified'] ) {
            return array();
        }

        // Include filter was specified but resulted in no terms
        if ( empty( $resolved['include_ids'] ) ) {
            return array();
        }

        return $resolved['include_ids'];
    }

    /**
     * Sanitize a taxonomy attribute that may be a single slug, a CSV of slugs, or
     * empty (meaning "all"). Returns a clean CSV (or empty string), preserving the
     * empty/all and multi-taxonomy cases that a bare sanitize_key() would mangle.
     *
     * @param  mixed $value Raw taxonomy attribute.
     * @return string       Clean CSV of taxonomy slugs, or empty string.
     */
    protected function sanitize_taxonomy_att( $value ) {
        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return '';
        }
        $slugs = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $value ) ) ) ) );
        return implode( ',', array_unique( $slugs ) );
    }

    /**
     * The relationship-type paths that count toward scoring for one taxonomy,
     * gated by that taxonomy's enabled features. Hierarchy only applies to
     * hierarchical taxonomies; container/redirect_target follow the per-taxonomy
     * feature flags. Direct assignment always counts.
     *
     * @param  string $taxonomy Taxonomy slug.
     * @return string[]         Allowed relationship_type values.
     */
    protected function compute_allowed_rel_types( $taxonomy ) {
        $allowed = array( 'direct' );
        if ( is_taxonomy_hierarchical( $taxonomy ) ) {
            $allowed[] = 'hierarchy';
        }
        if ( htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
            $allowed[] = 'container';
        }
        if ( htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
            $allowed[] = 'redirect_target';
        }
        return $allowed;
    }

    /**
     * Resolve the set of taxonomies a query spans, for relationship-type gating.
     *
     * A single explicit slug or a CSV returns those taxonomies. An empty value
     * derives the distinct taxonomies of the query term IDs (WP term IDs are
     * globally unique, so this is authoritative) — letting [ht_related_posts]
     * with taxonomy="" span every taxonomy the post has terms in, and keeping
     * AJAX pagination correct without threading a taxonomy list.
     *
     * @param  string|array $taxonomy Taxonomy slug, CSV, array, or empty.
     * @param  int[]        $term_ids Query term IDs (used when taxonomy is empty).
     * @return string[]               Distinct, existing taxonomy slugs.
     */
    protected function parse_query_taxonomies( $taxonomy, $term_ids ) {
        $list = array();
        if ( is_array( $taxonomy ) ) {
            $list = $taxonomy;
        } elseif ( is_string( $taxonomy ) && '' !== trim( $taxonomy ) ) {
            $list = explode( ',', $taxonomy );
        }
        $list = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', $list ) ) ) );

        if ( ! empty( $list ) ) {
            return array_values( array_unique( $list ) );
        }

        // Empty → derive the distinct taxonomies of the query term IDs.
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', (array) $term_ids ) ) );
        if ( empty( $ids ) ) {
            return array();
        }
        $ids_str = implode( ',', $ids );
        $found   = $wpdb->get_col( "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id IN ($ids_str)" );
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $found ) ) ) );
    }

    /**
     * Query post IDs from the search index with relevance scoring.
     *
     * The search index (wp_htax_search_terms) is pre-computed and stores one row per
     * (post, term, source, relationship_type) for every relationship path. Per-term
     * relevance combines all rows for a (post, term) pair via noisy-OR — combined =
     * 1 - Π(1 - strength) — so multiple paths to the same term (multiple source terms,
     * or one source reached via more than one relationship type) raise the term's score
     * monotonically while staying strictly below 1.0, the ceiling any single direct row
     * can reach. A term reached by exactly one path is unaffected (noisy-OR of one value
     * returns that value unchanged). Per-post relevance is then a flat SUM of these
     * per-term scores across distinct query terms — that breadth (matching more of the
     * selected terms) stays additive. Relationship-type weights are baked into strength
     * at index time and gated per taxonomy at query time.
     *
     * @param  int[]            $term_ids         Term IDs to query.
     * @param  string|string[]  $taxonomy         Taxonomy slug, CSV, or '' (derive from term IDs) — used for the per-taxonomy relationship-type gate.
     * @param  array            $atts             Merged shortcode attributes.
     * @param  int[]            $exclude_post_ids Post IDs to exclude from results.
     * @param  int              $page             Page number (1-based).
     * @param  array<int,float> $term_weights     Optional per-term query weights (e.g. source-post centrality); each term's aggregated strength is multiplied by its weight.
     * @return array { post_ids: int[], total: int, max_pages: int }
     */
    protected function query_posts_by_term_ids( $term_ids, $taxonomy, $atts, $exclude_post_ids = array(), $page = 1, $term_weights = array() ) {
        global $wpdb;

        if ( empty( $term_ids ) ) {
            return array( 'post_ids' => array(), 'total' => 0, 'max_pages' => 0 );
        }

        $term_ids       = array_map( 'intval', $term_ids );
        $term_ids_str   = implode( ',', $term_ids );

        // Taxonomies in play for relationship-type gating and group expansion. A
        // single explicit slug → that taxonomy; a CSV → those taxonomies; empty →
        // derived from the query term IDs (so [ht_related_posts] taxonomy="" spans
        // every taxonomy the post has terms in, and AJAX pagination stays correct).
        $gate_taxonomies = $this->parse_query_taxonomies( $taxonomy, $term_ids );

        $posts_per_page = max( 1, intval( $atts['posts_per_page'] ) );
        $max_results    = max( 0, intval( $atts['max_results'] ?? 0 ) );
        $page           = max( 1, (int) $page );
        $offset         = ( $page - 1 ) * $posts_per_page;

        // Cap LIMIT so the query never returns results beyond max_results
        $effective_limit = $posts_per_page;
        if ( $max_results > 0 ) {
            if ( $offset >= $max_results ) {
                return array( 'post_ids' => array(), 'total' => 0, 'max_pages' => 0 );
            }
            $effective_limit = min( $posts_per_page, $max_results - $offset );
        }

        // --- Resolve filter terms early (needed for both cache key and SQL) ---

        // filter_terms: ALL listed term IDs must appear in the post
        $filter_term_ids = array();
        if ( ! empty( $atts['filter_terms'] ) ) {
            $filter_term_ids = array_values( array_filter( array_map( 'intval', htax_parse_term_ids( $atts['filter_terms'], $taxonomy ) ) ) );
        }

        // filter_groups: expand each group to its member term IDs (AND across groups, OR within each group)
        $filter_group_term_sets = array();
        if ( ! empty( $atts['filter_groups'] ) ) {
            $filter_group_ids = array_values( array_filter( array_map( 'intval', htax_parse_term_ids( $atts['filter_groups'], 'group' ) ) ) );
            foreach ( $filter_group_ids as $fg_id ) {
                $members = array();
                foreach ( $gate_taxonomies as $gt ) {
                    $group_terms = htax_get_terms_in_groups( array( $fg_id ), $gt );
                    if ( ! empty( $group_terms ) ) {
                        $members = array_merge( $members, array_map( 'intval', array_keys( $group_terms ) ) );
                    }
                }
                if ( ! empty( $members ) ) {
                    $filter_group_term_sets[ $fg_id ] = array_values( array_unique( $members ) );
                }
            }
        }

        // filter_out_terms: posts containing ANY of these term IDs are excluded
        $filter_out_term_ids = array();
        if ( ! empty( $atts['filter_out_terms'] ) ) {
            $filter_out_term_ids = array_values( array_filter( array_map( 'intval', htax_parse_term_ids( $atts['filter_out_terms'], $taxonomy ) ) ) );
        }

        // filter_out_groups: expand each group; posts with any term from any of these groups are excluded
        $filter_out_all_term_ids = $filter_out_term_ids;
        if ( ! empty( $atts['filter_out_groups'] ) ) {
            $filter_out_group_ids = array_values( array_filter( array_map( 'intval', htax_parse_term_ids( $atts['filter_out_groups'], 'group' ) ) ) );
            foreach ( $filter_out_group_ids as $fog_id ) {
                foreach ( $gate_taxonomies as $gt ) {
                    $group_terms = htax_get_terms_in_groups( array( $fog_id ), $gt );
                    if ( ! empty( $group_terms ) ) {
                        $filter_out_all_term_ids = array_merge( $filter_out_all_term_ids, array_map( 'intval', array_keys( $group_terms ) ) );
                    }
                }
            }
            $filter_out_all_term_ids = array_values( array_unique( $filter_out_all_term_ids ) );
        }

        // Sorted filter_out_groups IDs for cache key (group IDs, not expanded term IDs)
        $sorted_filter_out_group_ids = array();
        if ( ! empty( $atts['filter_out_groups'] ) ) {
            $sorted_filter_out_group_ids = array_values( array_filter( array_map( 'intval', htax_parse_term_ids( $atts['filter_out_groups'], 'group' ) ) ) );
            sort( $sorted_filter_out_group_ids );
        }

        // --- Build cache key ---
        $sorted_term_ids = $term_ids;
        sort( $sorted_term_ids );

        // Individual filter term IDs use f{id} segments.
        $sorted_filter_term_ids = $filter_term_ids;
        sort( $sorted_filter_term_ids );

        // Filter groups use g{group_id} segments — the GROUP IDs themselves, not the expanded
        // term IDs. Two configurations with the same member-term union but different group
        // boundaries produce different SQL (AND-across-groups vs. single OR list), so they must
        // have distinct cache keys. Group IDs are also the correct invalidation target:
        // htax_delete_term_transients($group_id) → htax_invalidate_post_list_cache([$group_id])
        // → matches g{group_id} patterns.
        $sorted_filter_group_ids = array_keys( $filter_group_term_sets );
        sort( $sorted_filter_group_ids );

        $exclude_ids_int = array_values( array_filter( array_map( 'intval', (array) $exclude_post_ids ) ) );
        sort( $exclude_ids_int );

        // Compact orderby key: first char of field + D/A for direction, segments joined by dash.
        // e.g. "date:DESC,title:ASC" → "dD-tA", "relevance:DESC" → "rD", "date" → "d".
        $orderby_raw  = sanitize_text_field( $atts['orderby'] ?? 'date' );
        $ob_parts     = array();
        foreach ( explode( ',', $orderby_raw ) as $ob_seg ) {
            $ob_seg = trim( strtolower( $ob_seg ) );
            if ( strpos( $ob_seg, ':' ) !== false ) {
                list( $ob_field, $ob_dir ) = explode( ':', $ob_seg, 2 );
                $ob_parts[] = substr( $ob_field, 0, 1 ) . ( 'desc' === $ob_dir ? 'D' : 'A' );
            } else {
                $ob_parts[] = substr( $ob_seg, 0, 1 );
            }
        }
        $orderby_key = implode( '-', array_filter( $ob_parts ) ) ?: 'd';

        $sorted_filter_out_term_ids = $filter_out_term_ids;
        sort( $sorted_filter_out_term_ids );

        $search_string = isset( $atts['search'] ) ? trim( $atts['search'] ) : '';

        $cache_key = 'htax_pl_t' . implode( '_t', $sorted_term_ids )
                   . ( ! empty( $sorted_filter_term_ids ) ? '_f' . implode( '_f', $sorted_filter_term_ids ) : '' )
                   . ( ! empty( $sorted_filter_group_ids ) ? '_g' . implode( '_g', $sorted_filter_group_ids ) : '' )
                   . ( ! empty( $sorted_filter_out_term_ids ) ? '_fo' . implode( '_fo', $sorted_filter_out_term_ids ) : '' )
                   . ( ! empty( $sorted_filter_out_group_ids ) ? '_fog' . implode( '_fog', $sorted_filter_out_group_ids ) : '' )
                   . '_p' . $page
                   . '_n' . $posts_per_page
                   . '_m' . $max_results
                   . '_o' . $orderby_key
                   . '_y' . sanitize_key( $atts['post_type'] ?? 'post' )
                   . ( ! empty( $exclude_ids_int ) ? '_x' . implode( '-', $exclude_ids_int ) : '' )
                   . ( '' !== $search_string ? '_s' . substr( md5( $search_string ), 0, 8 ) : '' )
                   . ( htax_can_see_hidden_terms() ? 'H' : '' );

        // Never cache results shaped by a user-driven inline filter panel (htax_filter /
        // user_filter_sql) — the cache key above has no representation of that state, so a
        // filtered request can collide with (and silently return) an already-cached
        // differently-filtered or unfiltered result for the same taxonomy/terms/page/sort.
        // Ad-hoc filter combinations are also numerous and poorly suited to this cache's
        // per-term invalidation strategy, so skipping the cache here (rather than growing
        // the key) is both the correct fix and the simpler one.
        $use_cache = strlen( $cache_key ) <= 172 && empty( $atts['user_filter_sql'] );

        if ( $use_cache ) {
            $cached = htax_get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $options = get_option( 'htax_settings', array() );

        // Build the relationship-type gate per taxonomy. The search index stores a
        // taxonomy on every row, so each taxonomy's enabled relationship features
        // gate its own rows. Single-taxonomy callers ([ht_post_list]) yield one
        // branch; [ht_related_posts] may span several taxonomies (taxonomy="" or a
        // CSV) and OR their gates together.
        $tax_gate_parts  = array();
        $tax_gate_params = array();
        foreach ( $gate_taxonomies as $gt ) {
            $allowed = $this->compute_allowed_rel_types( $gt );
            $ph      = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
            $tax_gate_parts[]  = "(st.taxonomy = %s AND st.relationship_type IN ($ph))";
            $tax_gate_params[] = $gt;
            foreach ( $allowed as $rt ) {
                $tax_gate_params[] = $rt;
            }
        }
        if ( empty( $tax_gate_parts ) ) {
            return array( 'post_ids' => array(), 'total' => 0, 'max_pages' => 0 );
        }
        $rel_type_clause = $wpdb->prepare( ' AND (' . implode( ' OR ', $tax_gate_parts ) . ')', ...$tax_gate_params );

        // Relationship-type weights are baked into the strength column at index time.

        // Optional keyword scoring — adds per-field FULLTEXT MATCH expressions,
        // supporting the same operator syntax as the search widget (title:, excerpt:, etc.).
        // Unqualified terms are applied to all three post fields.
        // Each field's score is weighted by the global relevance_multipliers settings.
        $keyword_select = '';
        $keyword_params = array();
        $relevance_col  = 'term_relevance';
        if ( '' !== $search_string ) {
            $title_weight   = floatval( $options['relevance_multipliers']['title']   ?? 1.0 );
            $content_weight = floatval( $options['relevance_multipliers']['content'] ?? 0.5 );
            $excerpt_weight = floatval( $options['relevance_multipliers']['excerpt'] ?? 0.7 );

            $parsed  = htax_parse_search_query( $search_string );
            $general = $parsed['general'];

            $field_defs = array(
                array( 'col' => 'pt.title',   'terms' => $parsed['title'],   'weight' => $title_weight   ),
                array( 'col' => 'pt.body',    'terms' => $parsed['body'],    'weight' => $content_weight ),
                array( 'col' => 'pt.excerpt', 'terms' => $parsed['excerpt'], 'weight' => $excerpt_weight ),
            );

            foreach ( $field_defs as $fd ) {
                // Include this field if it has field-specific terms OR there are general terms.
                if ( empty( $fd['terms'] ) && empty( $general ) ) {
                    continue;
                }
                $bq = htax_build_boolean_query_string( $fd['terms'], $general );
                if ( '' === $bq ) {
                    continue;
                }
                $keyword_select   .= sprintf( ' + COALESCE(MATCH(%s) AGAINST(%%s IN BOOLEAN MODE) * %F, 0)', $fd['col'], $fd['weight'] );
                $keyword_params[]  = $bq;
            }

            if ( '' !== $keyword_select ) {
                $relevance_col = 'combined_relevance';
            }
        }

        // Exclude specific post IDs (e.g. current post for related posts)
        $exclude_clause = '';
        if ( ! empty( $exclude_ids_int ) ) {
            $safe_ids       = implode( ',', $exclude_ids_int );
            $exclude_clause = " AND st.post_id NOT IN ($safe_ids)";
        }

        // Exclude hidden terms; privileged users get a separate cache variant (see cache key above)
        $hidden_clause = htax_get_hidden_term_filter_clause( 'st.is_hidden' );

        // The relevance query aggregates strength, so it reads one strength model only.
        $model_clause = htax_strength_model_sql( 'st', 'related' );

        // Build AND-filter subqueries (restrict results without affecting scoring).
        $filter_clause_parts = htax_build_term_filter_clauses(
            $filter_term_ids,
            $filter_group_term_sets,
            $filter_out_all_term_ids
        );

        // User filter state from the inline filter panel, pre-built by the AJAX handler.
        if ( ! empty( $atts['user_filter_sql'] ) ) {
            $filter_clause_parts[] = $atts['user_filter_sql'];
        }

        $filter_sql = implode( "\n\t\t\t ", $filter_clause_parts );

        // Build ORDER BY — use combined_relevance column name when keyword scoring is active
        $orderby_parsed = htax_parse_orderby( $atts['orderby'] );
        $order_by_sql   = htax_build_orderby_sql( $orderby_parsed, 'pt', $relevance_col );

        // Restrict to the list's post type in SQL, before LIMIT, so pages and totals match
        // what the WP_Query that loads the posts returns.
        $post_type_clause = $wpdb->prepare( ' AND pt.post_type = %s', sanitize_key( $atts['post_type'] ?? 'post' ) );

        // Count query: same WHERE/JOIN as main query, no ORDER BY or LIMIT
        $ptt       = $wpdb->prefix . 'htax_post_text';
        $count_sql = "SELECT COUNT(DISTINCT st.post_id)
             FROM {$wpdb->prefix}htax_search_terms st
             INNER JOIN {$ptt} pt ON pt.post_id = st.post_id AND pt.post_status = 'publish'
             WHERE st.term_id IN ($term_ids_str)
               $rel_type_clause
               $hidden_clause
               $exclude_clause
               $model_clause
               $post_type_clause
               $filter_sql";

        $total = (int) $wpdb->get_var( $count_sql );

        if ( htax_handle_db_error( 'htax_post_list_count', array() ) !== false ) {
            return array( 'post_ids' => array(), 'total' => 0, 'max_pages' => 0 );
        }

        // Main query: per-term relevance combines all rows for a (post, term) pair via
        // noisy-OR (1 - Π(1 - strength)) — see the docblock above. Per-post relevance is
        // a flat SUM of those per-term scores across distinct query terms. Post-level
        // filters and keyword FULLTEXT scoring join the post-text table in the outer
        // query. When a keyword search is active its scores fold into combined_relevance
        // for ORDER BY. Optional per-term query weights (source-post centrality): scale
        // each term's combined strength before summing. Empty = equal weights.
        // LEAST(1, ...) bounds (1 - strength): every stored strength is in [0,1] (built-in
        // formula, and htax_set_term_strengths() rejects out-of-range scores), so this only
        // guards against a negative value written to the table directly, which would push
        // the noisy-OR product above 1 and yield a negative combined score.
        $weight_mult = htax_build_term_weight_multiplier( (array) $term_weights, 'term_id' );

        $sql = $wpdb->prepare(
            "SELECT s.post_id,
                    s.term_relevance{$keyword_select} AS {$relevance_col}
             FROM (
                 SELECT post_id, SUM(term_score{$weight_mult}) AS term_relevance
                 FROM (
                     SELECT st.post_id AS post_id, st.term_id AS term_id,
                            1 - EXP(SUM(LN(LEAST(1, GREATEST(1 - st.strength, 0.000001))))) AS term_score
                     FROM {$wpdb->prefix}htax_search_terms st
                     WHERE st.term_id IN ($term_ids_str)
                       $rel_type_clause
                       $hidden_clause
                       $exclude_clause
                       $model_clause
                     GROUP BY st.post_id, st.term_id
                 ) AS per_term
                 GROUP BY post_id
             ) AS s
             INNER JOIN {$ptt} pt ON pt.post_id = s.post_id AND pt.post_status = 'publish'
             WHERE 1=1
               $post_type_clause
               $filter_sql
             ORDER BY $order_by_sql
             LIMIT %d OFFSET %d",
            ...array_merge( $keyword_params, array( $effective_limit, $offset ) )
        );

        $rows = $wpdb->get_results( $sql );

        if ( htax_handle_db_error( 'htax_post_list_query', array() ) !== false ) {
            return array( 'post_ids' => array(), 'total' => 0, 'max_pages' => 0 );
        }
        $post_ids = array_map( function( $row ) { return (int) $row->post_id; }, $rows );

        $effective_total = ( $max_results > 0 ) ? min( $total, $max_results ) : $total;

        $result = array(
            'post_ids'  => $post_ids,
            'total'     => $effective_total,
            'max_pages' => $posts_per_page > 0 ? (int) ceil( $effective_total / $posts_per_page ) : 1,
        );

        if ( $use_cache ) {
            htax_set_transient( $cache_key, $result, htax_get_cache_duration( 'shortcode' ) );
        }

        return $result;
    }

    /**
     * Render the complete shortcode output, wrapped in a container that holds AJAX
     * data attributes for pagination. The inner posts and nav are replaced in-place
     * on subsequent page loads without a URL change.
     *
     * @param  WP_Post[] $posts            Posts to display.
     * @param  int[]     $term_ids         Pre-resolved term IDs (stored in data attrs for AJAX).
     * @param  int[]     $exclude_post_ids Post IDs excluded from results (stored for AJAX).
     * @param  int       $current_page     Current page number.
     * @param  int       $max_pages        Total number of pages.
     * @return string
     */
    protected function render_post_list( $posts, $term_ids, $exclude_post_ids, $current_page, $max_pages, $source_post_id = 0 ) {
        if ( empty( $posts ) ) {
            return $this->render_no_results();
        }

        $template = sanitize_html_class( $this->htax_atts['template'] );
        $format   = $this->sanitize_format( $this->htax_atts['format'] );
        $classes  = array( 'htax-post-list', 'htax-template-' . $template );
        // For ul/ol formats the user's class applies to each <li>, not the wrapper.
        if ( 'div' === $format && ! empty( $this->htax_atts['class'] ) ) {
            $classes = array_merge( $classes, $this->sanitize_class_parameter( $this->htax_atts['class'] ) );
        }

        // AJAX data attributes — encode pre-resolved query parameters on the wrapper
        // so the JS pagination handler can re-issue identical queries for other pages.
        $data_attrs = sprintf(
            ' data-term-ids="%s" data-exclude-ids="%s" data-taxonomy="%s"'
            . ' data-posts-per-page="%d" data-max-results="%d" data-post-type="%s" data-orderby="%s"'
            . ' data-filter-terms="%s" data-filter-groups="%s"'
            . ' data-filter-out-terms="%s" data-filter-out-groups="%s"'
            . ' data-show-thumbnail="%s" data-show-excerpt="%s" data-meta="%s"'
            . ' data-template="%s" data-format="%s" data-class="%s"'
            . ' data-search="%s"'
            . ' data-prepend="%s" data-append="%s"'
            . ' data-no-results-message="%s"'
            . ' data-image-size="%s" data-columns="%d" data-source-post-id="%d"',
            esc_attr( implode( ',', array_map( 'intval', $term_ids ) ) ),
            esc_attr( implode( ',', array_map( 'intval', $exclude_post_ids ) ) ),
            esc_attr( $this->sanitize_taxonomy_att( $this->htax_atts['taxonomy'] ) ),
            max( 1, intval( $this->htax_atts['posts_per_page'] ) ),
            max( 0, intval( $this->htax_atts['max_results'] ) ),
            esc_attr( sanitize_key( $this->htax_atts['post_type'] ) ),
            esc_attr( $this->htax_atts['orderby'] ),
            esc_attr( $this->htax_atts['filter_terms'] ),
            esc_attr( $this->htax_atts['filter_groups'] ),
            esc_attr( $this->htax_atts['filter_out_terms'] ),
            esc_attr( $this->htax_atts['filter_out_groups'] ),
            esc_attr( $this->htax_atts['show_thumbnail'] ),
            esc_attr( $this->htax_atts['show_excerpt'] ),
            esc_attr( $this->htax_atts['meta'] ),
            esc_attr( $template ),
            esc_attr( $format ),
            esc_attr( $this->htax_atts['class'] ),
            esc_attr( $this->htax_atts['search'] ?? '' ),
            esc_attr( $this->htax_atts['prepend'] ?? '' ),
            esc_attr( $this->htax_atts['append'] ?? '' ),
            esc_attr( $this->htax_atts['no_results_message'] ),
            esc_attr( sanitize_key( $this->htax_atts['image_size'] ?? 'medium' ) ),
            max( 1, intval( $this->htax_atts['columns'] ?? 1 ) ),
            max( 0, (int) $source_post_id )
        );

        $posts_per_page = max( 1, intval( $this->htax_atts['posts_per_page'] ) );
        $start_index    = ( $current_page - 1 ) * $posts_per_page + 1;

        $sort_fields = ! empty( $this->htax_atts['sort_order_options'] )
            ? trim( $this->htax_atts['sort_order_options'] )
            : '';

        $columns     = max( 1, intval( $this->htax_atts['columns'] ?? 1 ) );
        $col_style   = $columns > 1 ? ' style="--htax-columns:' . $columns . '"' : '';
        $wrapper  = '<div class="htax-post-list-wrapper"' . $data_attrs . '>';
        $wrapper .= '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $col_style . '>';
        $wrapper .= $this->render_posts_inner( $posts, $start_index );
        $wrapper .= '</div>'; // .htax-post-list
        $wrapper .= $this->render_pagination_nav( $current_page, $max_pages );
        $wrapper .= '</div>'; // .htax-post-list-wrapper

        // Inline filter panel.
        $filter_html  = '';
        $filter_class = ! empty( $this->htax_atts['filters_align'] )
            ? 'htax-align-' . sanitize_html_class( $this->htax_atts['filters_align'] )
            : '';
        if ( ! empty( $this->htax_atts['filters'] ) ) {
            $raw_filter_configs = htax_parse_inline_filters(
                $this->htax_atts['filters'],
                $this->htax_atts['filter_options']
            );
            if ( ! empty( $raw_filter_configs ) ) {
                // Seed primary filter's initial selection from the shortcode's static
                // filter_terms so the filter panel UI matches the initial query result.
                $primary_seed_ids = array();
                if ( ! empty( $this->htax_atts['filter_terms'] ) ) {
                    $primary_seed_ids = array_values( array_filter( array_map( 'intval', htax_parse_term_ids( $this->htax_atts['filter_terms'], $this->sanitize_taxonomy_att( $this->htax_atts['taxonomy'] ) ) ) ) );
                }
                $resolved    = htax_resolve_filter_configs( $raw_filter_configs, array(), null, $primary_seed_ids );
                $filter_html = htax_render_filter_options( $resolved, array( 'action' => '', 'class' => $filter_class ), array() );
            }
        }

        if ( $sort_fields || $filter_html ) {
            $picker = '';
            if ( $sort_fields ) {
                $sort_class = ! empty( $this->htax_atts['sort_order_align'] )
                    ? 'htax-align-' . sanitize_html_class( $this->htax_atts['sort_order_align'] )
                    : '';
                htax_enqueue_sort_order_assets();
                $picker = htax_render_sort_order_picker( array(
                    'fields'        => $sort_fields,
                    'state'         => 'expanded',
                    'action'        => '',
                    'default_value' => sanitize_text_field( $this->htax_atts['orderby'] ),
                    'class'         => $sort_class,
                ) );
            }
            $controls_bar = '<div class="htax-controls-bar">' . $filter_html . $picker . '</div>';
            $output = '<div class="htax-post-list-block">' . $controls_bar . $wrapper . '</div>';
        } else {
            $output = $wrapper;
        }

        return $output;
    }

    /**
     * Render post items as HTML. Used by both the initial shortcode render and
     * the AJAX page handler so that the markup is always identical.
     *
     * For ul/ol formats: renders a <ul> or <ol> containing a <li> per post with
     * just the title linked. For ol, $start_index sets the HTML start attribute so
     * numbering continues correctly across pagination pages.
     *
     * For div format: renders full article cards with optional thumbnail/excerpt/meta.
     *
     * @param  WP_Post[] $posts
     * @param  int       $start_index  1-based index of the first post (used for ol start= attribute).
     * @return string
     */
    protected function render_posts_inner( $posts, $start_index = 1 ) {
        $format = $this->sanitize_format( $this->htax_atts['format'] ?? 'ul' );

        $prepend_tpl = $this->htax_atts['prepend'] ?? '';
        $append_tpl  = $this->htax_atts['append']  ?? '';

        if ( 'div' !== $format ) {
            $attrs    = ( 'ol' === $format && $start_index > 1 ) ? ' start="' . intval( $start_index ) . '"' : '';
            $li_class = ! empty( $this->htax_atts['class'] )
                ? ' class="' . esc_attr( implode( ' ', $this->sanitize_class_parameter( $this->htax_atts['class'] ) ) ) . '"'
                : '';
            $output   = '<' . $format . $attrs . '>';
            foreach ( $posts as $post ) {
                $output .= '<li' . $li_class . '>';
                if ( '' !== $prepend_tpl ) {
                    $output .= htax_process_post_placeholders( $prepend_tpl, $post );
                }
                $output .= '<a href="' . esc_url( get_permalink( $post ) ) . '">'
                    . esc_html( get_the_title( $post ) )
                    . '</a>';
                if ( '' !== $append_tpl ) {
                    $output .= htax_process_post_placeholders( $append_tpl, $post );
                }
                $output .= '</li>';
            }
            $output .= '</' . $format . '>';
            return $output;
        }

        $template       = sanitize_html_class( $this->htax_atts['template'] );
        $show_thumbnail = ( $this->htax_atts['show_thumbnail'] === 'true' );
        $show_excerpt   = ( $this->htax_atts['show_excerpt']   === 'true' );
        $meta_tpl       = $this->htax_atts['meta'] ?? '';
        $image_size     = sanitize_key( $this->htax_atts['image_size'] ?? 'medium' );

        $output = '';
        foreach ( $posts as $post ) {
            $output .= $this->render_post( $post, $template, $show_thumbnail, $show_excerpt, $meta_tpl, $prepend_tpl, $append_tpl, $image_size );
        }
        return $output;
    }

    /**
     * Render a single post article.
     *
     * @param  WP_Post $post
     * @param  string  $template
     * @param  bool    $show_thumbnail
     * @param  bool    $show_excerpt
     * @param  string  $meta_tpl     Meta line template. Supports {date}, {author}, {modified}.
     *                               Empty string = hide the meta line.
     * @param  string  $prepend_tpl  Raw prepend template (placeholders not yet replaced).
     * @param  string  $append_tpl   Raw append template (placeholders not yet replaced).
     * @return string
     */
    protected function render_post( $post, $template, $show_thumbnail, $show_excerpt, $meta_tpl = '{date} by {author}', $prepend_tpl = '', $append_tpl = '', $image_size = 'medium' ) {
        $permalink = esc_url( get_permalink( $post ) );
        $title     = esc_html( get_the_title( $post ) );

        $html = '<article class="htax-result-item htax-result-' . esc_attr( $template ) . '">';

        if ( '' !== $prepend_tpl ) {
            $html .= htax_process_post_placeholders( $prepend_tpl, $post );
        }

        if ( $show_thumbnail && has_post_thumbnail( $post ) ) {
            $html .= '<div class="htax-result-thumbnail">';
            $html .= '<a href="' . $permalink . '">';
            $html .= get_the_post_thumbnail( $post, $image_size );
            $html .= '</a></div>';
        }

        $html .= '<div class="htax-result-content">';
        $html .= '<h3 class="htax-result-title">';
        $html .= '<a href="' . $permalink . '">' . $title . '</a>';
        $html .= '</h3>';

        if ( '' !== $meta_tpl ) {
            $html .= '<div class="htax-result-meta">' . htax_process_post_placeholders( $meta_tpl, $post ) . '</div>';
        }

        if ( $show_excerpt ) {
            $excerpt = get_the_excerpt( $post );
            if ( $excerpt ) {
                $html .= '<div class="htax-result-excerpt">' . wp_kses_post( $excerpt ) . '</div>';
            }
        }

        $html .= '</div>'; // .htax-result-content

        if ( '' !== $append_tpl ) {
            $html .= htax_process_post_placeholders( $append_tpl, $post );
        }

        $html .= '</article>';
        return $html;
    }

    /**
     * Render the prev/next pagination nav.
     *
     * Returns an empty string when there is only one page — the nav element is
     * omitted entirely rather than rendered hidden.
     *
     * @param  int $current_page
     * @param  int $max_pages
     * @return string
     */
    protected function render_pagination_nav( $current_page, $max_pages ) {
        if ( $max_pages <= 1 ) {
            return '';
        }

        $html = '<nav class="htax-post-list-nav" aria-label="'
              . esc_attr__( 'Post list navigation', 'hypertaxonomy' ) . '">';

        if ( $current_page > 1 ) {
            $html .= '<button type="button" class="htax-post-list-prev" data-page="' . ( $current_page - 1 ) . '">'
                   . esc_html__( '« Previous', 'hypertaxonomy' )
                   . '</button>';
        }

        $html .= '<span class="htax-post-list-page-info">'
               . sprintf(
                   /* translators: 1: current page number, 2: total page count */
                   esc_html__( 'Page %1$d of %2$d', 'hypertaxonomy' ),
                   $current_page,
                   $max_pages
                 )
               . '</span>';

        if ( $current_page < $max_pages ) {
            $html .= '<button type="button" class="htax-post-list-next" data-page="' . ( $current_page + 1 ) . '">'
                   . esc_html__( 'Next »', 'hypertaxonomy' )
                   . '</button>';
        }

        $html .= '</nav>';
        return $html;
    }

    /**
     * AJAX handler for pagination page loads.
     *
     * Accepts pre-resolved term IDs and query parameters from the client (stored as
     * data attributes by the initial render), runs the same query, and returns the
     * rendered posts HTML and pagination nav as JSON.
     */
    public function ajax_load_page() {
        check_ajax_referer( 'htax_post_list', 'nonce' );

        $page         = max( 1, intval( wp_unslash( $_POST['page'] ?? 1 ) ) );
        $taxonomy     = $this->sanitize_taxonomy_att( wp_unslash( $_POST['taxonomy'] ?? 'post_tag' ) );
        $term_ids     = array_values( array_filter( array_map(
            'intval',
            explode( ',', sanitize_text_field( wp_unslash( $_POST['term_ids'] ?? '' ) ) )
        ) ) );
        $exclude_ids  = array_values( array_filter( array_map(
            'intval',
            explode( ',', sanitize_text_field( wp_unslash( $_POST['exclude_ids'] ?? '' ) ) )
        ) ) );
        $source_post_id = (int) wp_unslash( $_POST['source_post_id'] ?? 0 );

        if ( empty( $term_ids ) ) {
            wp_send_json_error( array( 'message' => 'No term IDs' ), 400 );
        }

        // Re-derive source-post term weights for paginated pages (related posts).
        $term_weights = $source_post_id > 0
            ? htax_get_post_term_strengths( $source_post_id, $term_ids )
            : array();

        // User filter state from the inline filter panel (event-mode [htax_filter_options]
        // or the filters= shorthand). Sent as a JSON-encoded htax_filter POST param by
        // htax-filter-options.js when it triggers an AJAX re-render. POST-only: navigate
        // and submit action modes do not connect to [htax_post_list] and never reach here.
        $user_filter_sql = '';
        if ( ! empty( $_POST['htax_filter'] ) ) {
            $filter_state    = htax_parse_filter_state_ajax( wp_unslash( $_POST['htax_filter'] ) );
            $user_filter_sql = htax_build_filter_sql( $filter_state );
        }

        $this->htax_atts = array(
            'taxonomy'        => $taxonomy,
            'posts_per_page'  => max( 1, intval( wp_unslash( $_POST['posts_per_page'] ?? 10 ) ) ),
            'max_results'     => max( 0, intval( wp_unslash( $_POST['max_results'] ?? 0 ) ) ),
            'post_type'       => sanitize_key( wp_unslash( $_POST['post_type'] ?? 'post' ) ),
            'orderby'         => sanitize_text_field( wp_unslash( $_POST['orderby'] ?? 'date:desc' ) ),
            'filter_terms'      => sanitize_text_field( wp_unslash( $_POST['filter_terms'] ?? '' ) ),
            'filter_groups'     => sanitize_text_field( wp_unslash( $_POST['filter_groups'] ?? '' ) ),
            'filter_out_terms'  => sanitize_text_field( wp_unslash( $_POST['filter_out_terms'] ?? '' ) ),
            'filter_out_groups' => sanitize_text_field( wp_unslash( $_POST['filter_out_groups'] ?? '' ) ),
            'show_thumbnail'  => sanitize_text_field( wp_unslash( $_POST['show_thumbnail'] ?? 'true' ) ),
            'show_excerpt'    => sanitize_text_field( wp_unslash( $_POST['show_excerpt'] ?? 'true' ) ),
            'meta'            => sanitize_text_field( wp_unslash( $_POST['meta'] ?? '{date} by {author}' ) ),
            'template'        => sanitize_html_class( wp_unslash( $_POST['template'] ?? 'default' ) ),
            'format'          => $this->sanitize_format( sanitize_key( wp_unslash( $_POST['format'] ?? 'ul' ) ) ),
            'class'           => sanitize_text_field( wp_unslash( $_POST['class'] ?? '' ) ),
            'search'          => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
            'prepend'         => wp_kses_post( wp_unslash( $_POST['prepend'] ?? '' ) ),
            'append'          => wp_kses_post( wp_unslash( $_POST['append'] ?? '' ) ),
            'image_size'      => sanitize_key( wp_unslash( $_POST['image_size'] ?? 'medium' ) ),
            'columns'         => (string) max( 1, intval( wp_unslash( $_POST['columns'] ?? 1 ) ) ),
            'user_filter_sql' => $user_filter_sql,
        );

        $result = $this->query_posts_by_term_ids( $term_ids, $taxonomy, $this->htax_atts, $exclude_ids, $page, $term_weights );

        if ( empty( $result['post_ids'] ) ) {
            wp_send_json_success( array( 'posts' => '', 'nav' => '' ) );
            return;
        }

        $wp_query = new WP_Query( array(
            'post__in'               => $result['post_ids'],
            'orderby'                => 'post__in',
            'posts_per_page'         => count( $result['post_ids'] ),
            'post_type'              => $this->htax_atts['post_type'],
            'post_status'            => 'publish',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ) );

        $start_index = ( $page - 1 ) * $this->htax_atts['posts_per_page'] + 1;

        wp_send_json_success( array(
            'posts' => $this->render_posts_inner( $wp_query->posts, $start_index ),
            'nav'   => $this->render_pagination_nav( $page, $result['max_pages'] ),
        ) );
    }

    /**
     * Enqueue the post list pagination script.
     *
     * Called on each shortcode render; skips if already enqueued.
     */
    protected function enqueue_scripts() {
        if ( wp_script_is( 'htax-post-list', 'enqueued' ) || wp_script_is( 'htax-post-list', 'done' ) ) {
            return;
        }

        wp_enqueue_script(
            'htax-post-list',
            HTAX_PLUGIN_URL . 'assets/js/htax-post-list.js',
            array( 'jquery' ),
            HTAX_VERSION,
            true
        );

        wp_localize_script( 'htax-post-list', 'HTAX_PostList', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'htax_post_list' ),
        ) );
    }

    /**
     * Sanitize and validate the format attribute.
     *
     * @param  string $format  Raw format value from shortcode attribute or POST data.
     * @return string  'ul', 'ol', or 'div'
     */
    protected function sanitize_format( $format ) {
        return in_array( $format, array( 'ul', 'ol', 'div' ), true ) ? $format : 'ul';
    }

    /**
     * Return the "no results" output: the no_results_message wrapped in a container
     * (if a message is configured), or an empty string.
     *
     * @return string
     */
    protected function render_no_results() {
        if ( empty( $this->htax_atts['no_results_message'] ) ) {
            return '';
        }

        $classes = array( 'htax-post-list', 'htax-no-results' );
        if ( ! empty( $this->htax_atts['class'] ) ) {
            $classes = array_merge( $classes, $this->sanitize_class_parameter( $this->htax_atts['class'] ) );
        }

        return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">'
             . wp_kses_post( $this->htax_atts['no_results_message'] )
             . '</div>';
    }
}


// ===========================================================================
// [ht_related_posts]
// ===========================================================================

class HyperTaxonomy_Shortcode_Related_Posts extends HyperTaxonomy_Shortcode_Post_List {

    public function __construct() {
        add_shortcode( 'ht_related_posts', array( $this, 'shortcode_handler' ) );
    }

    protected function get_default_atts() {
        // Default orderby for related posts puts most-relevant first, then newest
        return array_merge( parent::get_default_atts(), array(
            'orderby' => 'relevance:desc,date:desc',
        ) );
    }

    /**
     * Only render on singular pages; silently return empty string elsewhere.
     *
     * Automatically passes the current post title as the `search` keyword so that
     * local and remote results are scored by both term overlap and title similarity.
     * An explicit `search=""` attribute (empty string) disables this behavior.
     * Any other explicit `search` value overrides the title.
     */
    public function shortcode_handler( $atts ) {
        if ( ! is_singular() || ! get_the_ID() ) {
            return '';
        }
        $atts = $this->inject_search_default( $atts );

        return parent::shortcode_handler( $atts );
    }

    /**
     * Inject the current post title as the `search` keyword unless the caller
     * explicitly set a `search` attribute (including an empty string to disable).
     *
     * shortcode_atts() has not run yet, so the raw $atts array is inspected.
     *
     * @param  mixed $atts Raw shortcode/block attributes.
     * @return array
     */
    private function inject_search_default( $atts ) {
        if ( ! is_array( $atts ) || ! array_key_exists( 'search', $atts ) ) {
            $title = get_the_title( get_the_ID() );
            if ( $title ) {
                $atts           = is_array( $atts ) ? $atts : array();
                $atts['search'] = $title;
            }
        }
        return is_array( $atts ) ? $atts : array();
    }

    /**
     * Render for Gutenberg block context.
     *
     * The block render callback already sets up global $post from block context,
     * so get_the_ID() works. The is_singular() guard in shortcode_handler() is
     * not meaningful in a REST/block-renderer context and is intentionally skipped.
     *
     * @param array $atts Block attributes.
     * @return string Rendered HTML.
     */
    public function render_for_block( $atts ) {
        return parent::shortcode_handler( $this->inject_search_default( $atts ) );
    }

    /**
     * Derive term IDs from the current post's direct taxonomy terms.
     *
     * The search index already stores all relationship expansions bottom-up, so
     * querying by these direct term IDs retrieves all posts that share (directly
     * or via inclusions / hierarchy / redirects) the same taxonomy terms —
     * matching the archive expansion behavior without any additional expansion here.
     *
     * include_terms / include_groups / exclude_terms / exclude_groups are ignored
     * for related posts; the term set is derived entirely from the current post.
     *
     * The `taxonomy` attribute may name one taxonomy (default `post_tag`), a CSV of
     * taxonomies, or be empty to span every taxonomy the post has terms in. Terms
     * from all selected taxonomies are pooled; the query then scores by their
     * combined relationship strength (a post sharing terms across taxonomies ranks
     * higher), with each taxonomy's relationship-type features gated independently.
     *
     * @param  array $atts Merged shortcode attributes.
     * @return int[]       Direct term IDs of the current post across the selected taxonomies.
     */
    protected function get_query_term_ids( $atts ) {
        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return array();
        }

        $taxonomies = $this->parse_related_taxonomies( $atts['taxonomy'] ?? '', $post_id );
        if ( empty( $taxonomies ) ) {
            return array();
        }

        $term_ids = array();
        foreach ( $taxonomies as $taxonomy ) {
            $post_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $post_terms ) && ! empty( $post_terms ) ) {
                $term_ids = array_merge( $term_ids, array_map( 'intval', $post_terms ) );
            }
        }

        return array_values( array_unique( $term_ids ) );
    }

    /**
     * Resolve the taxonomies whose terms the related-posts query draws from.
     *
     * Empty `taxonomy` → every publicly viewable taxonomy registered for the
     * current post's type (the opt-in "all taxonomies" mode). A single slug or a
     * CSV → those taxonomies. Only existing taxonomies are returned.
     *
     * @param  string $taxonomy_att Raw `taxonomy` attribute.
     * @param  int    $post_id      Current post ID.
     * @return string[]             Existing taxonomy slugs.
     */
    private function parse_related_taxonomies( $taxonomy_att, $post_id ) {
        $att = is_string( $taxonomy_att ) ? trim( $taxonomy_att ) : '';

        if ( '' === $att ) {
            $post  = get_post( $post_id );
            $taxes = $post ? get_object_taxonomies( $post->post_type ) : array();
            $taxes = array_filter( $taxes, 'is_taxonomy_viewable' );
        } else {
            $taxes = array_map( 'trim', explode( ',', $att ) );
        }

        $taxes = array_filter( array_map( 'sanitize_key', $taxes ), 'taxonomy_exists' );
        return array_values( array_unique( $taxes ) );
    }

    /**
     * Exclude the current post from its own related posts block.
     *
     * The excluded post ID is encoded in the wrapper data attributes so that
     * AJAX pagination requests honor the same exclusion.
     *
     * @return int[]
     */
    protected function get_exclude_post_ids() {
        $post_id = get_the_ID();
        return $post_id ? array( (int) $post_id ) : array();
    }

    /**
     * The current post is the source whose term centrality weights the query.
     *
     * @return int
     */
    protected function get_source_post_id() {
        return (int) get_the_ID();
    }

}

// Initialize shortcodes
new HyperTaxonomy_Shortcode_Post_List();
new HyperTaxonomy_Shortcode_Related_Posts();
