<?php
/**
 * As the Crow Flies integration.
 *
 * Detected via function_exists('atcf_get_term_ids_within_radius').
 *
 * Provides the HT-side features that consume ATCF location data:
 *  - Search widget distance filter field type and field resolution
 *  - near: proximity condition for PowerSearch and the search widget
 *  - ATCF location rows exposed via HT's htax_term_locations filter (REST API)
 *
 * Term-merge location migration lives in ATCF itself (ATCF_HT_Integration),
 * since ATCF owns the location data lifecycle.
 *
 * @package HyperTaxonomy
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HyperTaxonomy_ATCF_Integration {

    public static function init(): void {
        // Always register the shortcode so it renders a notice when ATCF is
        // not active rather than outputting raw shortcode text.
        add_action( 'init', array( __CLASS__, 'register_distance_shortcode' ), 20 );

        // Always register distance as a known field type so saved widget configurations
        // that include a distance field remain valid even when ATCF is temporarily inactive.
        // The resolver below is only registered when ATCF is active, so unresolved distance
        // fields produce no terms and are silently skipped by get_posts_by_custom_sql().
        add_filter( 'htax_allowed_field_types', array( __CLASS__, 'add_distance_field_type' ), 10, 1 );

        if ( ! function_exists( 'atcf_get_term_ids_within_radius' ) ) {
            return;
        }

        // Search widget: resolve distance fields pre-query.
        add_filter( 'htax_pre_search_fields', array( __CLASS__, 'resolve_distance_fields' ), 10, 1 );

        // PowerSearch + search widget: near: proximity condition, applied before LIMIT.
        add_filter( 'htax_search_proximity_where', array( __CLASS__, 'build_proximity_where' ), 10, 3 );
        add_filter( 'htax_search_proximity_relevance', array( __CLASS__, 'build_proximity_relevance' ), 10, 3 );

        // Provide ATCF location rows via HT's term locations filter.
        // Used by HT's REST API (schema:location field) when share_locations is enabled
        // per taxonomy in Settings → HyperTaxonomy → Features.
        add_filter( 'htax_term_locations', array( __CLASS__, 'provide_term_locations' ), 10, 2 );

        // Bridge ATCF location identity into TermConnect's cross-site URI matching:
        // contribute a normalized-address URI per term so peer/FOAF terms with
        // different slugs match by shared physical location. Harmless when
        // TermConnect is not active (the filter never fires; the action self-guards).
        add_filter( 'tc_index_extra_uris', array( __CLASS__, 'add_location_uris' ), 10, 2 );
        add_action( 'atcf_term_location_changed', array( __CLASS__, 'refresh_tc_uri_index' ) );
    }

    // --------------------------------------------------------------------------
    // TermConnect bridge: normalized-address URIs for cross-site location matching
    // --------------------------------------------------------------------------

    /**
     * Contribute normalized-address URIs for a term to TermConnect's URI index.
     *
     * ATCF defines the normalized address identity (country + postal code +
     * normalized address line) via ATCF_Locations::get_normalized_addresses();
     * we encode each as a stable urn so two sites describing the same address
     * produce the same URI and their terms match regardless of slug.
     *
     * @since 1.0.0
     * @param string[] $uris    Accumulated URIs.
     * @param int      $term_id Term ID.
     * @return string[]
     */
    public static function add_location_uris( array $uris, int $term_id ): array {
        if ( ! is_callable( array( 'ATCF_Locations', 'get_normalized_addresses' ) ) ) {
            return $uris;
        }
        $term = get_term( $term_id );
        if ( ! $term || is_wp_error( $term ) ) {
            return $uris;
        }
        if ( ! class_exists( 'ATCF_Admin' ) || ! ATCF_Admin::is_taxonomy_enabled( $term->taxonomy ) ) {
            return $uris;
        }
        foreach ( ATCF_Locations::get_normalized_addresses( $term_id ) as $addr ) {
            $uris[] = 'urn:atcf:place:'
                . rawurlencode( $addr['country'] ) . ':'
                . rawurlencode( $addr['postal_code'] ) . ':'
                . rawurlencode( $addr['address'] );
        }
        return $uris;
    }

    /**
     * Rebuild TermConnect's URI index when a term's locations change, so the
     * normalized-address URIs stay current.
     *
     * @since 1.0.0
     * @param int $term_id Term ID.
     */
    public static function refresh_tc_uri_index( $term_id ): void {
        if ( function_exists( 'tc_rebuild_uri_index' ) ) {
            tc_rebuild_uri_index( (int) $term_id );
        }
    }

    // --------------------------------------------------------------------------
    // Search widget: distance field resolution
    // --------------------------------------------------------------------------

    /**
     * Register [htax_distance_field] shortcode for use in search widget forms.
     */
    public static function register_distance_shortcode(): void {
        add_shortcode( 'htax_distance_field', array( __CLASS__, 'render_distance_shortcode' ) );
    }

    /**
     * Render the distance filter form field.
     *
     * Attributes:
     * - name          Field name used to pass postal_code to the search.
     * - radius        Comma-separated radius options, e.g. "5,10,25,50".
     * - unit          'miles' (default) or 'km'.
     * - country       Country code, default 'US'.
     * - taxonomy      Taxonomy slug to scope the distance filter.
     * - no_location   'include' (pass-through) or 'exclude' (strict).
     * - input         'zip', 'geolocation', or 'zip,geolocation'.
     * - expand        'all' (default) = use all types enabled by taxonomy feature flags;
     *                 'none' = direct matches only; or comma-separated: container, hierarchy, redirect.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML.
     */
    public static function render_distance_shortcode( array $atts ): string {
        if ( ! function_exists( 'atcf_get_term_ids_within_radius' ) ) {
            if ( current_user_can( 'activate_plugins' ) ) {
                return '<div class="notice notice-warning inline"><p>'
                    . esc_html__( 'The distance filter field requires the As the Crow Flies plugin to be active.', 'hypertaxonomy' )
                    . '</p></div>';
            }
            return '';
        }

        $a = shortcode_atts( array(
            'name'                       => 'htax_distance',
            'radius'                     => '5,10,25,50',
            'unit'                       => 'miles',
            'country'                    => 'US',
            'taxonomy'                   => '',
            'filterby'                   => '', // alias for taxonomy
            'no_location'                => 'include',
            'input'                      => 'zip,geolocation',
            // Option pool — restrict which terms are eligible to match.
            'include_term_options'       => '', // comma-separated term IDs or slugs
            'exclude_term_options'       => '',
            'include_group_options'      => '', // comma-separated group term IDs
            'exclude_group_options'      => '',
            'include_descendant_options' => '', // root term ID or slug
            'exclude_descendant_options' => '',
            'expand'                     => 'all', // 'all' = taxonomy feature defaults; 'none' = direct only; or comma-list
        ), $atts, 'htax_distance_field' );

        // Normalise: filterby is an alias for taxonomy.
        if ( '' === $a['taxonomy'] && '' !== $a['filterby'] ) {
            $a['taxonomy'] = $a['filterby'];
        }

        $radius_options = array_filter( array_map( 'absint', explode( ',', $a['radius'] ) ) );
        if ( empty( $radius_options ) ) {
            $radius_options = array( 5, 10, 25, 50 );
        }

        $input_types    = array_map( 'trim', explode( ',', $a['input'] ) );
        $show_zip       = in_array( 'zip', $input_types, true );
        $show_geo       = in_array( 'geolocation', $input_types, true );

        // Ensure ATCF's frontend CSS (which styles .atcf-postal-wrap, .atcf-geo-btn, etc.) is loaded.
        wp_enqueue_style( 'atcf-frontend' );
        wp_enqueue_script( 'atcf-frontend' );

        ob_start();
        ?>
        <div class="htax-field-wrapper htax-field-distance"
             data-atcf-distance="1"
             data-taxonomy="<?php echo esc_attr( $a['taxonomy'] ); ?>"
             data-unit="<?php echo esc_attr( $a['unit'] ); ?>"
             data-country="<?php echo esc_attr( strtoupper( $a['country'] ) ); ?>"
             data-no-location="<?php echo esc_attr( $a['no_location'] ); ?>"
             <?php if ( $a['include_term_options'] ) : ?>data-include-term-options="<?php echo esc_attr( $a['include_term_options'] ); ?>"<?php endif; ?>
             <?php if ( $a['exclude_term_options'] ) : ?>data-exclude-term-options="<?php echo esc_attr( $a['exclude_term_options'] ); ?>"<?php endif; ?>
             <?php if ( $a['include_group_options'] ) : ?>data-include-group-options="<?php echo esc_attr( $a['include_group_options'] ); ?>"<?php endif; ?>
             <?php if ( $a['exclude_group_options'] ) : ?>data-exclude-group-options="<?php echo esc_attr( $a['exclude_group_options'] ); ?>"<?php endif; ?>
             <?php if ( $a['include_descendant_options'] ) : ?>data-include-descendant-options="<?php echo esc_attr( $a['include_descendant_options'] ); ?>"<?php endif; ?>
             <?php if ( $a['exclude_descendant_options'] ) : ?>data-exclude-descendant-options="<?php echo esc_attr( $a['exclude_descendant_options'] ); ?>"<?php endif; ?>
             <?php if ( 'all' !== $a['expand'] ) : ?>data-expand-types="<?php echo esc_attr( $a['expand'] ); ?>"<?php endif; ?>>

            <div class="atcf-finder-controls">
                <span class="htax-distance-label"><?php esc_html_e( 'Within', 'hypertaxonomy' ); ?></span>

                <select name="<?php echo esc_attr( $a['name'] ); ?>[radius]"
                        class="atcf-radius-select"
                        aria-label="<?php esc_attr_e( 'Search radius', 'hypertaxonomy' ); ?>">
                    <?php foreach ( $radius_options as $r ) : ?>
                        <option value="<?php echo esc_attr( $r ); ?>">
                            <?php
                            /* translators: 1: radius, 2: unit */
                            echo esc_html( sprintf( __( '%1$d %2$s', 'hypertaxonomy' ), $r, $a['unit'] ) );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ( $show_zip || $show_geo ) : ?>
                    <span class="htax-distance-of"><?php esc_html_e( 'of', 'hypertaxonomy' ); ?></span>
                <?php endif; ?>

                <?php if ( $show_zip ) : ?>
                    <div class="atcf-postal-wrap">
                        <input type="text"
                               class="atcf-postal-input"
                               name="<?php echo esc_attr( $a['name'] ); ?>[postal_code]"
                               placeholder="<?php esc_attr_e( 'Postal code', 'hypertaxonomy' ); ?>"
                               aria-label="<?php esc_attr_e( 'Enter postal code', 'hypertaxonomy' ); ?>"
                               autocomplete="off" />
                        <?php if ( $show_geo ) : ?>
                            <button type="button"
                                    class="atcf-geo-btn"
                                    title="<?php esc_attr_e( 'Share current location', 'hypertaxonomy' ); ?>"
                                    aria-label="<?php esc_attr_e( 'Share current location', 'hypertaxonomy' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polygon points="3 11 22 2 13 21 11 13 3 11"/>
                                </svg>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php elseif ( $show_geo ) : ?>
                    <button type="button"
                            class="atcf-geo-btn"
                            title="<?php esc_attr_e( 'Share current location', 'hypertaxonomy' ); ?>"
                            aria-label="<?php esc_attr_e( 'Share current location', 'hypertaxonomy' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polygon points="3 11 22 2 13 21 11 13 3 11"/>
                        </svg>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Option pool parameters — passed through to server-side resolution -->
            <?php foreach ( array(
                'taxonomy',
                'unit',
                'country',
                'no_location',
                'include_term_options',
                'exclude_term_options',
                'include_group_options',
                'exclude_group_options',
                'include_descendant_options',
                'exclude_descendant_options',
            ) as $pool_key ) : ?>
                <?php if ( '' !== $a[ $pool_key ] ) : ?>
                    <input type="hidden"
                           name="<?php echo esc_attr( $a['name'] ); ?>[<?php echo esc_attr( $pool_key ); ?>]"
                           value="<?php echo esc_attr( $a[ $pool_key ] ); ?>" />
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="hidden" name="<?php echo esc_attr( $a['name'] ); ?>[type]" value="distance" />

            <!-- Resolved term IDs populated server-side before SQL query -->
            <input type="hidden"
                   name="<?php echo esc_attr( $a['name'] ); ?>[term_ids]"
                   class="htax-distance-term-ids"
                   value="" />
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Add 'distance' to the search widget's allowed field types.
     *
     * @param string[] $types Allowed field type slugs.
     * @return string[]
     */
    public static function add_distance_field_type( array $types ): array {
        $types[] = 'distance';
        return $types;
    }

    /**
     * Resolve distance fields before get_posts_by_custom_sql runs.
     *
     * Detects any field with type='distance' in the $fields array,
     * calls atcf_get_term_ids_within_radius(), and replaces the distance
     * field with a hidden field containing the resolved term IDs.
     * If no terms are found, injects post__in=0 to return empty results.
     *
     * @param array $query Search query spec {relation, fields, groups}.
     * @return array Modified query spec.
     */
    public static function resolve_distance_fields( array $query ): array {
        if ( ! isset( $query['fields'] ) || ! is_array( $query['fields'] ) ) {
            return $query;
        }

        foreach ( $query['fields'] as $name => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            if ( ( $field['type'] ?? '' ) !== 'distance' ) {
                continue;
            }

            $postal_code = sanitize_text_field( $field['postal_code'] ?? '' );
            $radius      = max( 0.1, (float) ( $field['radius'] ?? 10 ) );
            $unit        = in_array( $field['unit'] ?? 'miles', array( 'miles', 'km' ), true ) ? $field['unit'] : 'miles';
            $country     = strtoupper( sanitize_text_field( $field['country'] ?? 'US' ) );
            $taxonomy    = sanitize_key( $field['taxonomy'] ?? '' );
            $no_location = $field['no_location'] ?? 'include';

            $term_ids = array();

            // Browser coordinates (the field's location button) are more accurate than a postal
            // centroid. A postal code the visitor typed wins over every other source, though:
            // they asked for that place, not for wherever their IP or the site's fallback is.
            $user_loc = self::get_user_location();
            if ( $user_loc && '' !== $postal_code && 'browser' !== $user_loc['source'] ) {
                $user_loc = null;
            }

            // No location signal at all: skip the field rather than forcing empty results.
            // This separates "user didn't enter a location" from "location provided but nothing nearby".
            if ( ! $user_loc && '' === $postal_code ) {
                unset( $query['fields'][ $name ] );
                continue;
            }

            if ( $user_loc ) {
                $radius_km = 'km' === $unit ? $radius : $radius * 1.60934;
                $term_ids  = self::get_in_range_term_ids(
                    array( array( $user_loc['lat'], $user_loc['lng'] ) ),
                    $radius_km,
                    $taxonomy
                );
            } elseif ( '' !== $postal_code ) {
                // Fall back to the original postal-code path.
                $term_ids = atcf_get_term_ids_within_radius( $postal_code, $radius, $unit, $taxonomy, $country );
            }

            // Apply option pool restrictions.
            $allowed_pool = self::resolve_option_pool_ids( $field, $taxonomy );
            if ( null !== $allowed_pool && ! empty( $term_ids ) ) {
                $term_ids = array_values( array_intersect( $term_ids, $allowed_pool ) );
            }

            // Remove the distance field.
            unset( $query['fields'][ $name ] );

            if ( empty( $term_ids ) ) {
                if ( 'exclude' === $no_location ) {
                    // No matching terms + strict mode → force empty results.
                    $query['fields'][ $name . '_empty_distance' ] = array(
                        'type'  => 'hidden',
                        'terms' => array( 0 ),
                        'match' => 'all',
                    );
                }
                // 'include' mode with empty results: pass through, no filter applied.
                continue;
            }

            // When no_location="include", merge in terms that have no location data
            // so their posts always appear alongside the in-range results.
            if ( 'include' === $no_location && $taxonomy ) {
                $no_loc_ids = self::get_no_location_term_ids( $taxonomy, $allowed_pool );
                if ( ! empty( $no_loc_ids ) ) {
                    $term_ids = array_values( array_unique( array_merge( $term_ids, $no_loc_ids ) ) );
                }
            }

            // Inject resolved term IDs as a hidden field.
            if ( $taxonomy ) {
                $resolved = array(
                    'type'     => 'hidden',
                    'taxonomy' => $taxonomy,
                    'terms'    => $term_ids,
                    'match'    => 'any',
                );

                // Pass the raw expand value through — normalization happens in
                // HyperTaxonomy_Search_Widget::normalize_expand_types() at query time.
                // 'all' (or absent) defers to taxonomy feature-flag defaults.
                if ( isset( $field['expand_types'] ) && 'all' !== $field['expand_types'] ) {
                    $resolved['expand_types'] = $field['expand_types'];
                }

                $query['fields'][ $name . '_resolved' ] = $resolved;
            }
        }

        return $query;
    }

    /**
     * Resolve option pool attributes to a set of allowed term IDs.
     *
     * Returns null when no pool restrictions are configured (all terms allowed).
     * Returns an array (possibly empty) when at least one restriction is set.
     *
     * @param array  $field    Field data including option pool attributes.
     * @param string $taxonomy Taxonomy slug for slug-to-ID resolution.
     * @return int[]|null
     */
    private static function resolve_option_pool_ids( array $field, string $taxonomy ): ?array {
        $allowed  = null;

        // include_term_options: comma-separated IDs or slugs.
        if ( ! empty( $field['include_term_options'] ) ) {
            $allowed = array();
            foreach ( explode( ',', $field['include_term_options'] ) as $ref ) {
                $ref = trim( $ref );
                if ( is_numeric( $ref ) ) {
                    $allowed[] = (int) $ref;
                } elseif ( $taxonomy ) {
                    $t = get_term_by( 'slug', $ref, $taxonomy );
                    if ( $t ) {
                        $allowed[] = $t->term_id;
                    }
                }
            }
        }

        // include_group_options: comma-separated group term IDs.
        if ( ! empty( $field['include_group_options'] ) ) {
            $group_members = array();
            foreach ( explode( ',', $field['include_group_options'] ) as $gid ) {
                $gid = (int) trim( $gid );
                if ( ! $gid ) {
                    continue;
                }
                $group_members = array_merge( $group_members, array_keys( htax_get_terms_in_groups( array( $gid ), $taxonomy ) ) );
            }
            if ( ! empty( $group_members ) ) {
                $allowed = null !== $allowed
                    ? array_values( array_intersect( $allowed, $group_members ) )
                    : $group_members;
            }
        }

        // include_descendant_options: root term ID or slug.
        if ( ! empty( $field['include_descendant_options'] ) && $taxonomy ) {
            $root_ref = trim( $field['include_descendant_options'] );
            $root_id  = is_numeric( $root_ref ) ? (int) $root_ref : 0;
            if ( ! $root_id ) {
                $t = get_term_by( 'slug', $root_ref, $taxonomy );
                if ( $t ) {
                    $root_id = $t->term_id;
                }
            }
            if ( $root_id ) {
                $descendants = get_term_children( $root_id, $taxonomy );
                if ( ! is_wp_error( $descendants ) ) {
                    $descendants[] = $root_id;
                    $allowed = null !== $allowed
                        ? array_values( array_intersect( $allowed, $descendants ) )
                        : $descendants;
                }
            }
        }

        // exclude_group_options: remove group members from the allowed set.
        if ( ! empty( $field['exclude_group_options'] ) && null !== $allowed ) {
            $excluded_group_members = array();
            foreach ( explode( ',', $field['exclude_group_options'] ) as $gid ) {
                $gid = (int) trim( $gid );
                if ( ! $gid ) {
                    continue;
                }
                $excluded_group_members = array_merge( $excluded_group_members, array_keys( htax_get_terms_in_groups( array( $gid ), $taxonomy ) ) );
            }
            if ( ! empty( $excluded_group_members ) ) {
                $allowed = array_values( array_diff( $allowed, $excluded_group_members ) );
            }
        }

        // exclude_term_options.
        if ( ! empty( $field['exclude_term_options'] ) ) {
            $excluded = array();
            foreach ( explode( ',', $field['exclude_term_options'] ) as $ref ) {
                $ref = trim( $ref );
                if ( is_numeric( $ref ) ) {
                    $excluded[] = (int) $ref;
                } elseif ( $taxonomy ) {
                    $t = get_term_by( 'slug', $ref, $taxonomy );
                    if ( $t ) {
                        $excluded[] = $t->term_id;
                    }
                }
            }
            if ( ! empty( $excluded ) ) {
                if ( null !== $allowed ) {
                    $allowed = array_values( array_diff( $allowed, $excluded ) );
                }
                // If no include pool was set, we can't efficiently exclude without
                // fetching all terms first — leave unrestricted and let the caller handle it.
            }
        }

        // exclude_descendant_options.
        if ( ! empty( $field['exclude_descendant_options'] ) && $taxonomy ) {
            $root_ref = trim( $field['exclude_descendant_options'] );
            $root_id  = is_numeric( $root_ref ) ? (int) $root_ref : 0;
            if ( ! $root_id ) {
                $t = get_term_by( 'slug', $root_ref, $taxonomy );
                if ( $t ) {
                    $root_id = $t->term_id;
                }
            }
            if ( $root_id && null !== $allowed ) {
                $descendants = get_term_children( $root_id, $taxonomy );
                if ( ! is_wp_error( $descendants ) ) {
                    $descendants[] = $root_id;
                    $allowed = array_values( array_diff( $allowed, $descendants ) );
                }
            }
        }

        return $allowed;
    }

    // --------------------------------------------------------------------------
    // PowerSearch + search widget: near: proximity condition
    // --------------------------------------------------------------------------

    /**
     * Build the SQL condition that applies near: proximity tokens to a search query.
     *
     * Hook: htax_search_proximity_where (priority 10, 3 args). Applied in the WHERE clause,
     * before ORDER BY / LIMIT, so pagination and totals reflect the filtered set.
     *
     * A post passes when at least one of its terms in an eligible taxonomy (public
     * taxonomies enabled in ATCF) has a location in range of any positive near: clause —
     * positive clauses are unioned. Posts whose location is unknown (no eligible terms, or
     * none with a location) do not pass. Clauses whose reference can't be resolved (e.g.
     * near:me without a location) are ignored; when none resolves there is no positive
     * constraint. Negated clauses (-near:) drop every post in range of any of them. Hidden
     * terms' locations count only for viewers who can see hidden terms.
     *
     * @since 1.0.0
     * @param string $where       Condition from earlier callbacks ('' = none).
     * @param array  $proximity   Parsed proximity tokens from htax_parse_search_query().
     * @param string $post_column SQL expression for the post ID column (e.g. 'pt.post_id').
     * @return string SQL condition without a leading AND, or '' for no constraint.
     */
    public static function build_proximity_where( string $where, array $proximity, string $post_column ): string {
        if ( empty( $proximity ) ) {
            return $where;
        }
        $taxonomies = self::get_eligible_taxonomies();
        if ( empty( $taxonomies ) ) {
            return $where;
        }

        $resolved = self::resolve_proximity( $proximity );

        $parts = array();
        if ( '' !== $where ) {
            $parts[] = '(' . $where . ')';
        }
        if ( null !== $resolved['positive'] ) {
            $parts[] = self::posts_with_terms_sql( $post_column, $taxonomies, array_keys( $resolved['positive'] ) );
        }
        if ( ! empty( $resolved['negated'] ) ) {
            $parts[] = 'NOT ' . self::posts_with_terms_sql( $post_column, $taxonomies, $resolved['negated'] );
        }
        return implode( ' AND ', $parts );
    }

    /**
     * Build the relevance expression for a search made only of near: tokens: posts closer
     * to the reference point(s) score higher, so relevance ordering is nearest first.
     *
     * Hook: htax_search_proximity_relevance (priority 10, 3 args). A post's score is
     * 1 / (1 + d), with d the distance in km from the nearest reference point to the
     * nearest location among its in-range terms (in (0, 1]; 1 at distance 0).
     *
     * @since 1.0.0
     * @param string $sql         Expression from earlier callbacks ('0' = none).
     * @param array  $proximity   Parsed proximity tokens.
     * @param string $post_column SQL expression for the post ID column.
     * @return string SQL expression.
     */
    public static function build_proximity_relevance( string $sql, array $proximity, string $post_column ): string {
        $taxonomies = self::get_eligible_taxonomies();
        $resolved   = $taxonomies ? self::resolve_proximity( $proximity ) : array( 'positive' => null );
        if ( empty( $resolved['positive'] ) ) {
            return $sql;
        }
        $scores = array(); // term_taxonomy_id => score
        foreach ( self::term_taxonomy_ids( $taxonomies, array_keys( $resolved['positive'] ) ) as $tt_id => $term_id ) {
            $scores[ $tt_id ] = round( 1 / ( 1 + $resolved['positive'][ $term_id ] ), 6 );
        }
        if ( empty( $scores ) ) {
            return $sql;
        }
        global $wpdb;
        $cases = '';
        foreach ( $scores as $tt_id => $score ) {
            $cases .= ' WHEN ' . (int) $tt_id . ' THEN ' . sprintf( '%.6F', $score );
        }
        return "COALESCE( ( SELECT MAX( CASE tr.term_taxonomy_id{$cases} END ) FROM {$wpdb->term_relationships} tr
                WHERE tr.object_id = {$post_column} AND tr.term_taxonomy_id IN (" . implode( ',', array_keys( $scores ) ) . ') ), 0 )';
    }

    /**
     * Resolve near: clauses once per request: in-range terms of the positive clauses (with
     * the distance to each) and of the negated ones, hidden terms removed for viewers who
     * can't see them. Unresolvable clauses add a visitor notice.
     *
     * @since 1.0.0
     * @param array $proximity Parsed proximity tokens.
     * @return array{positive: array<int, float>|null, negated: int[]} positive is null when no
     *         positive clause resolved; otherwise term ID => nearest distance in km.
     */
    private static function resolve_proximity( array $proximity ): array {
        static $memo = array();
        $key = md5( serialize( $proximity ) . '|' . (int) htax_can_see_hidden_terms() );
        if ( isset( $memo[ $key ] ) ) {
            return $memo[ $key ];
        }
        $hidden   = array_flip( htax_can_see_hidden_terms() ? array() : htax_get_all_hidden_term_ids() );
        $positive = null;
        $negated  = array();
        foreach ( $proximity as $clause ) {
            $ref_points = self::resolve_reference_points( $clause );
            if ( empty( $ref_points ) ) {
                self::add_unresolved_notice( $clause );
                continue;
            }
            $distances = self::get_in_range_term_distances( $ref_points, self::clause_radius_km( (float) $clause['radius'] ) );
            if ( $clause['negated'] ) {
                $negated = array_merge( $negated, array_keys( $distances ) );
                continue;
            }
            $positive = $positive ?? array();
            foreach ( $distances as $term_id => $km ) {
                $positive[ $term_id ] = isset( $positive[ $term_id ] ) ? min( $positive[ $term_id ], $km ) : $km;
            }
        }
        if ( null !== $positive ) {
            $positive = array_diff_key( $positive, $hidden );
        }
        $memo[ $key ] = array(
            'positive' => $positive,
            'negated'  => array_values( array_diff( array_unique( $negated ), array_keys( $hidden ) ) ),
        );
        return $memo[ $key ];
    }

    /**
     * SQL condition: the post carries at least one of $term_ids in an eligible taxonomy.
     *
     * The term IDs are first resolved to term_taxonomy_ids, so the condition is a
     * correlated EXISTS over term_relationships' (object_id, term_taxonomy_id) primary key:
     * each candidate post is checked with an index lookup, and an ORDER BY / LIMIT query can
     * stop as soon as it has its page. IDs are interpolated as integers, so the fragment
     * contains no placeholders and can be embedded in SQL that is prepared later.
     *
     * @since 1.0.0
     * @param string   $post_column SQL expression for the post ID column.
     * @param string[] $taxonomies  Eligible taxonomy slugs.
     * @param int[]    $term_ids    Term IDs.
     * @return string
     */
    private static function posts_with_terms_sql( string $post_column, array $taxonomies, array $term_ids ): string {
        global $wpdb;
        $tt_ids = array_keys( self::term_taxonomy_ids( $taxonomies, $term_ids ) );
        if ( empty( $tt_ids ) ) {
            return '(1 = 0)';
        }
        return "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr
                WHERE tr.object_id = {$post_column} AND tr.term_taxonomy_id IN (" . implode( ',', $tt_ids ) . '))';
    }

    /**
     * Tell the visitor that a near: clause was ignored because its reference has no location.
     *
     * @since 1.0.0
     * @param array $clause Parsed proximity clause.
     */
    private static function add_unresolved_notice( array $clause ): void {
        if ( ! function_exists( 'htax_add_search_notice' ) ) {
            return;
        }
        if ( 'me' === $clause['location_type'] ) {
            htax_add_search_notice( 'near_me_unknown', __( "We couldn't determine your location, so the results aren't filtered by distance from you.", 'hypertaxonomy' ) );
            return;
        }
        $reference = 'term' === $clause['location_type'] ? (string) $clause['term_slug'] : (string) $clause['postal_code'];
        htax_add_search_notice(
            'near_unknown_' . $reference,
            /* translators: %s: place, postal code or term slug from a near: search operator */
            sprintf( __( 'No location found for "%s", so the results aren\'t filtered by distance from it.', 'hypertaxonomy' ), $reference )
        );
    }

    /**
     * Resolve a near: clause to a list of [lat, lng] reference points.
     *
     * Returns an empty array when the reference cannot be determined (clause is skipped).
     *
     * @param array $clause Parsed proximity clause from htax_parse_search_query().
     * @return array<int, array{float, float}> List of [lat, lng] pairs.
     */
    private static function resolve_reference_points( array $clause ): array {
        if ( 'me' === $clause['location_type'] ) {
            $loc = self::get_user_location();
            if ( null === $loc ) {
                return array();
            }
            if ( 'fallback' === $loc['source'] && function_exists( 'htax_add_search_notice' ) ) {
                htax_add_search_notice( 'near_me_fallback', __( "We couldn't determine your location, so the results are near the default location instead.", 'hypertaxonomy' ) );
            }
            return array( array( $loc['lat'], $loc['lng'] ) );
        }

        if ( 'postal_code' === $clause['location_type'] ) {
            $raw     = $clause['postal_code'] ?? '';
            $country = strtoupper( $clause['country'] ?? 'US' );
            $format  = function_exists( 'atcf_get_postal_code_format' )
                ? atcf_get_postal_code_format( $country )
                : '/^\d{5}(-\d{4})?$/'; // US fallback

            // Uppercase the value and check against the country's postal format.
            // If it matches, look up the centroid. If not, treat as a term slug.
            if ( null !== $format && preg_match( $format, strtoupper( $raw ) ) ) {
                $centroid = atcf_get_postal_centroid( strtoupper( $raw ), $country );
                if ( $centroid ) {
                    return array( array( $centroid['lat'], $centroid['lng'] ) );
                }
                return array();
            }

            // No format defined or value doesn't match → treat as term slug.
            $slug   = strtolower( $raw );
            $points = array();
            $hidden_ids = htax_get_all_hidden_term_ids();
            foreach ( self::get_eligible_taxonomies() as $tax ) {
                $term = get_term_by( 'slug', $slug, $tax );
                if ( ! $term || is_wp_error( $term ) ) {
                    continue;
                }
                $inclusion_ids = htax_get_included_term_ids( $term );
                $locs = ATCF_Locations::get_aggregated(
                    $term->term_id,
                    $tax,
                    $hidden_ids,
                    $inclusion_ids
                );
                foreach ( $locs as $loc ) {
                    [ $lat, $lng ] = self::resolve_location_coords( $loc );
                    if ( null !== $lat ) {
                        $points[] = array( $lat, $lng );
                    }
                }
            }
            return $points;
        }

        // 'term' location type: find the term's ATCF locations, aggregating
        // all WP descendants, HT inclusions, and excluding HT hidden terms.
        $taxonomy  = $clause['taxonomy'] ?? '';
        $term_slug = $clause['term_slug'] ?? '';
        if ( '' === $taxonomy || '' === $term_slug ) {
            return array();
        }

        $term = get_term_by( 'slug', $term_slug, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return array();
        }

        $hidden_ids      = htax_get_all_hidden_term_ids();
        $inclusion_ids   = htax_get_included_term_ids( $term );

        $locations = ATCF_Locations::get_aggregated(
            $term->term_id,
            $taxonomy,
            $hidden_ids,
            $inclusion_ids
        );
        if ( empty( $locations ) ) {
            return array();
        }

        $points = array();
        foreach ( $locations as $loc ) {
            [ $lat, $lng ] = self::resolve_location_coords( $loc );
            if ( null !== $lat ) {
                $points[] = array( $lat, $lng );
            }
        }
        return $points;
    }

    /**
     * Resolve a location row to [lat, lng], falling back to postal centroid.
     *
     * Returns [lat, lng] on success, [null, null] when coordinates cannot be
     * determined (no explicit coords and no matching centroid).
     *
     * @param object $loc Location row from ATCF_Locations.
     * @return array{float|null, float|null}
     */
    private static function resolve_location_coords( object $loc ): array {
        $lat = isset( $loc->lat ) ? (float) $loc->lat : null;
        $lng = isset( $loc->lng ) ? (float) $loc->lng : null;

        if ( ( null === $lat || null === $lng ) && ! empty( $loc->postal_code ) && ! empty( $loc->country ) ) {
            $centroid = atcf_get_postal_centroid( $loc->postal_code, $loc->country );
            if ( $centroid ) {
                $lat = $centroid['lat'];
                $lng = $centroid['lng'];
            }
        }

        return array( $lat, $lng );
    }

    /**
     * Return the visitor's current location, from ATCF's location chain
     * (atcf_get_visitor_location()): browser coordinates sent with the request
     * (_atcf_lat / _atcf_lng — the search scripts ask the browser for them when a query uses
     * near:me), CDN location headers, IP geolocation, then the site's fallback location.
     *
     * @return array{lat: float, lng: float, source: string}|null source is 'browser', 'cdn',
     *         'ip' or 'fallback'; null when no location is available.
     */
    public static function get_user_location(): ?array {
        $loc = function_exists( 'atcf_get_visitor_location' ) ? atcf_get_visitor_location() : null;
        return $loc ? array( 'lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'], 'source' => (string) $loc['source'] ) : null;
    }

    /**
     * Return the slugs of all taxonomies enabled in TermConnect.
     *
     * When TC is not active the option is absent and an empty array is returned,
     * making the proximity filter a no-op — correct, since proximity filtering
     * requires TC's link type infrastructure.
     *
     * Memoized per request.
     *
     * @return string[]
     */
    private static function get_eligible_taxonomies(): array {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }
        if ( ! class_exists( 'ATCF_Admin' ) ) {
            $cache = array();
            return $cache;
        }
        $cache = array_values( array_filter(
            array_keys( get_taxonomies( array( 'public' => true ) ) ),
            static fn( string $t ) => ATCF_Admin::is_taxonomy_enabled( $t )
        ) );
        return $cache;
    }




    /**
     * Convert a near: clause radius (expressed in the site's configured
     * distance unit) to kilometres. The proximity tokens carry a bare number, so
     * the unit is the ATCF display unit (atcf_distance_units), not always miles.
     *
     * @since 1.0.0
     * @param float $radius Radius in the site's display unit.
     * @return float
     */
    private static function clause_radius_km( float $radius ): float {
        return 'km' === get_option( 'atcf_distance_units', 'miles' ) ? $radius : $radius * 1.60934;
    }

    /**
     * Map term IDs to their term_taxonomy_ids within the eligible taxonomies.
     *
     * @since 1.0.0
     * @param string[] $taxonomies Eligible taxonomy slugs.
     * @param int[]    $term_ids   Term IDs.
     * @return array<int, int> term_taxonomy_id => term_id
     */
    private static function term_taxonomy_ids( array $taxonomies, array $term_ids ): array {
        global $wpdb;
        $term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
        if ( empty( $term_ids ) || empty( $taxonomies ) ) {
            return array();
        }
        $tax_ph  = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
        $term_ph = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($tax_ph) AND term_id IN ($term_ph)",
            array_merge( $taxonomies, $term_ids )
        ) );
        $map = array();
        foreach ( (array) $rows as $row ) {
            $map[ (int) $row->term_taxonomy_id ] = (int) $row->term_id;
        }
        return $map;
    }

    /**
     * Return the terms whose ATCF locations are within $radius_km of any reference point,
     * with the distance from the nearest reference point to each term's nearest location.
     *
     * Delegates to atcf_get_locations_within_radius() — no direct ATCF table access.
     *
     * @since 1.0.0
     * @param array<int, array{float, float}> $ref_points Reference [lat, lng] pairs.
     * @param float  $radius_km Radius in kilometres.
     * @param string $taxonomy  Taxonomy slug to restrict results (empty = all taxonomies).
     * @return array<int, float> term ID => distance in km
     */
    private static function get_in_range_term_distances( array $ref_points, float $radius_km, string $taxonomy = '' ): array {
        $distances = array();
        foreach ( $ref_points as [ $lat, $lng ] ) {
            $args = array( 'lat' => $lat, 'lng' => $lng );
            if ( '' !== $taxonomy ) {
                $args['taxonomy'] = $taxonomy;
            }
            foreach ( atcf_get_locations_within_radius( '', $radius_km, 'km', $args ) as $loc ) {
                $loc     = (array) $loc;
                $term_id = (int) $loc['term_id'];
                $km      = (float) ( $loc['distance_km'] ?? 0 );
                $distances[ $term_id ] = isset( $distances[ $term_id ] ) ? min( $distances[ $term_id ], $km ) : $km;
            }
        }
        return $distances;
    }

    /**
     * Return term IDs whose ATCF locations are within $radius_km of any reference point.
     *
     * Delegates entirely to atcf_get_locations_within_radius() — no direct ATCF table access.
     *
     * @param array<int, array{float, float}> $ref_points Reference [lat, lng] pairs.
     * @param float  $radius_km  Radius in kilometres.
     * @param string $taxonomy   Taxonomy slug to restrict results (empty = all taxonomies).
     * @return int[]
     */
    public static function get_in_range_term_ids( array $ref_points, float $radius_km, string $taxonomy = '' ): array {
        return array_keys( self::get_in_range_term_distances( $ref_points, $radius_km, $taxonomy ) );
    }


    /**
     * Return term IDs in $taxonomy that have no rows in wp_atcf_locations.
     *
     * When $allowed_pool is set, only IDs present in that pool are returned.
     *
     * @param string   $taxonomy     Taxonomy slug.
     * @param int[]|null $allowed_pool Restrict to these IDs; null = no restriction.
     * @return int[]
     */
    private static function get_no_location_term_ids( string $taxonomy, ?array $allowed_pool ): array {
        global $wpdb;
        $table   = $wpdb->prefix . 'atcf_locations';
        $located = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT term_id FROM {$table} WHERE taxonomy = %s",
            $taxonomy
        ) ) );
        $all = get_terms( array(
            'taxonomy'   => $taxonomy,
            'fields'     => 'ids',
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $all ) ) {
            return array();
        }
        $no_loc = array_values( array_diff( (array) $all, $located ) );
        if ( null !== $allowed_pool ) {
            $no_loc = array_values( array_intersect( $no_loc, $allowed_pool ) );
        }
        return $no_loc;
    }

    // --------------------------------------------------------------------------
    // Term locations for REST API (htax_term_locations filter)
    // --------------------------------------------------------------------------

    /**
     * Provide ATCF location rows for a term via HT's htax_term_locations filter.
     *
     * @param array[] $locations Accumulated locations from other hooks.
     * @param int     $term_id   Term ID.
     * @return array[]
     */
    public static function provide_term_locations( array $locations, int $term_id ): array {
        if ( ! class_exists( 'ATCF_Locations' ) ) {
            return $locations;
        }
        $term = get_term( $term_id );
        if ( ! $term || is_wp_error( $term ) ) {
            return $locations;
        }
        if ( ! class_exists( 'ATCF_Admin' ) || ! ATCF_Admin::is_taxonomy_enabled( $term->taxonomy ) ) {
            return $locations;
        }
        // Map ATCF's stored rows (objects, ATCF column names) to the associative-array
        // contract documented for the htax_term_locations filter: label, street_address,
        // locality, region, postal_code, country, lat, lng. Consumers (HT REST, WP-SKOS)
        // read these keys, so the mapping must happen here rather than leaking ATCF's
        // schema (e.g. address_line) through the generic filter.
        foreach ( (array) ATCF_Locations::get_by_term_id( $term_id ) as $row ) {
            $row    = (object) $row;
            $street = trim( (string) ( $row->address_line ?? '' ) );
            $line2  = trim( (string) ( $row->address_line2 ?? '' ) );
            if ( '' !== $line2 ) {
                $street = '' !== $street ? $street . ', ' . $line2 : $line2;
            }
            $locations[] = array(
                'label'          => (string) ( $row->label ?? '' ),
                'street_address' => $street,
                'locality'       => (string) ( $row->locality ?? '' ),
                'region'         => (string) ( $row->region ?? '' ),
                'postal_code'    => (string) ( $row->postal_code ?? '' ),
                'country'        => (string) ( $row->country ?? '' ),
                'lat'            => ( isset( $row->lat ) && null !== $row->lat ) ? (float) $row->lat : null,
                'lng'            => ( isset( $row->lng ) && null !== $row->lng ) ? (float) $row->lng : null,
            );
        }

        return $locations;
    }
}

// Initialize on plugins_loaded after all plugins have had a chance to load.
add_action( 'plugins_loaded', array( 'HyperTaxonomy_ATCF_Integration', 'init' ), 20 );
