<?php
/**
 * Base class for term display shortcodes
 *
 * Provides common functionality for shortcodes that display terms from a single taxonomy:
 * - ht_term_list
 * - ht_term_cloud
 * - ht_term_index
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class HyperTaxonomy_Term_Display_Base extends HyperTaxonomy_Shortcode_Base {

    /**
     * Tracks rendered term IDs to prevent duplicates.
     * Keyed by term_id for O(1) lookups.
     */
    protected $processed_terms = array();

    /**
     * Get default attributes for term display shortcodes
     */
    protected function get_default_atts() {
        return array_merge( parent::get_default_atts(), array(
            // Display parameters
            'taxonomy' => 'post_tag',
            'hide_empty' => '0',

            // Term Groups parameters
            'include_groups' => '', // Comma-separated group IDs to include terms from
            'exclude_groups' => '', // Comma-separated group IDs to exclude terms from
            'include_terms' => '', // Comma-separated term IDs to include (overrides group exclusions)
            'exclude_terms' => '', // Comma-separated term IDs to exclude (final override)
        ) );
    }

    /**
     * Get terms based on shortcode parameters
     *
     * Applies inclusion/exclusion logic with standardized order of operations:
     * 1. Include groups
     * 2. Exclude groups (only removes terms from included groups)
     * 3. Include individual terms (overrides group exclusions)
     * 4. Exclude individual terms (final override)
     *
     * @param string $taxonomy Taxonomy name
     * @param array $additional_args Additional arguments to merge with defaults
     * @return array Array of term objects
     */
    protected function get_terms( $taxonomy, $additional_args = array() ) {
        $args = array(
            'taxonomy' => $taxonomy,
            'hide_empty' => $this->htax_atts['hide_empty'] === '1',
        );

        // Merge any additional arguments (e.g., orderby/order for term-list)
        $args = array_merge( $args, $additional_args );

        // Apply inclusion/exclusion logic with standardized 4-step order
        $resolved = $this->resolve_term_ids( $this->htax_atts, $taxonomy );

        // Return empty if include filter was specified but resulted in no terms
        if ( ( $resolved['include_groups_specified'] || $resolved['include_terms_specified'] ) && empty( $resolved['include_ids'] ) ) {
            return array();
        }

        if ( ! empty( $resolved['include_ids'] ) ) {
            $args['include'] = $resolved['include_ids'];
        } elseif ( ! empty( $resolved['exclude_ids'] ) ) {
            $args['exclude'] = $resolved['exclude_ids'];
        }

        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            htax_log_error( 'Failed to get terms for shortcode', $terms->get_error_message() );
            return array();
        }

        return $terms;
    }
}
