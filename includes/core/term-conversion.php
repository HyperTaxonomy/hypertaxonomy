<?php
/**
 * Term Taxonomy Conversion
 *
 * Provides the native HyperTaxonomy term conversion engine and hooks that keep
 * HT state consistent when any plugin converts terms (e.g. the WordPress
 * Categories to Tags Converter).
 *
 * Two conversion paths exist:
 *
 * 1. Native (htax_convert_term / htax_convert_terms_batch)
 *
 *    With redirect stub (create_redirect = true):
 *      - New term created in target taxonomy (fresh, no inherited metadata).
 *      - Post assignments moved via direct UPDATE on wp_term_relationships.
 *      - Old term stays in old taxonomy and becomes the redirect stub, keeping
 *        its original slug so /old-tax/old-slug correctly triggers the HT
 *        redirect. All old metadata (icon, groups, relationships) stays on the
 *        stub — it is in the correct taxonomy for it.
 *
 *    Without redirect stub (create_redirect = false):
 *      - Old term deleted first, releasing its slug.
 *      - New term created in target taxonomy with the original slug for a clean
 *        URL. New term starts with no inherited metadata.
 *
 *    Reverse conversion (user converts the new term back to the old taxonomy):
 *      - Detected via htax_find_auto_redirect_to_term().
 *      - Posts moved back to the stub, redirect removed, new term deleted.
 *      - Original term restored to its exact pre-conversion state.
 *
 * 2. External plugin detection (create-new + delete-old pattern)
 *    Plugins such as the WordPress Categories to Tags Converter create a new
 *    term in the target taxonomy, then delete the old term. HT detects this
 *    by watching created_term and pre_delete_term/delete_term in the same
 *    request and creates a redirect stub in the old taxonomy using the now-free
 *    original slug.
 *
 * @package HyperTaxonomy
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// NATIVE CONVERSION PUBLIC API
// ---------------------------------------------------------------------------

/**
 * Sort a list of term IDs so that parent terms come before their children.
 *
 * Uses DFS-based topological ordering. Only terms whose parent is also in
 * the list need ordering; terms with no in-list parent are treated as roots.
 * Terms not found in the given taxonomy are passed through unchanged.
 *
 * @since 1.0
 *
 * @param int[]  $term_ids Term IDs to sort (any order).
 * @param string $taxonomy Taxonomy these terms belong to.
 * @return int[] Sorted term IDs, parents before children.
 */
function htax_sort_term_ids_parent_first( array $term_ids, string $taxonomy ): array {
	$in_set    = array_flip( $term_ids );
	$parent_of = array();
	foreach ( $term_ids as $id ) {
		$term = get_term( $id, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			$parent_of[ $id ] = (int) $term->parent;
		}
	}

	$ordered = array();
	$visited = array();

	$visit = function ( int $id ) use ( &$visit, &$ordered, &$visited, $parent_of, $in_set ): void {
		if ( isset( $visited[ $id ] ) ) {
			return;
		}
		$visited[ $id ] = true;
		$parent         = $parent_of[ $id ] ?? 0;
		if ( $parent > 0 && isset( $in_set[ $parent ] ) ) {
			$visit( $parent );
		}
		$ordered[] = $id;
	};

	foreach ( $term_ids as $id ) {
		$visit( $id );
	}

	return $ordered;
}

/**
 * Convert a term to a different taxonomy.
 *
 * With $create_redirect = true (default):
 *   The old term stays in the old taxonomy and becomes a redirect stub pointing
 *   to the freshly created term in the target taxonomy. The stub keeps its
 *   original slug so existing archive URLs trigger HT's redirect handler.
 *
 * With $create_redirect = false:
 *   The old term is deleted, releasing its slug. The new term is created with
 *   the original slug in the target taxonomy.
 *
 * Returns an array on success:
 *   'converted'          => 1
 *   'redirect_created'   => bool
 *   'reverse_conversion' => bool
 *
 * @since 1.0
 *
 * @param int    $term_id         Term to convert.
 * @param string $target_taxonomy Target taxonomy slug.
 * @param bool   $create_redirect Whether to leave a redirect stub in the old taxonomy.
 * @return array|WP_Error
 */
function htax_convert_term( int $term_id, string $target_taxonomy, bool $create_redirect = true ) {
	global $wpdb;

	if ( ! taxonomy_exists( $target_taxonomy ) ) {
		return new WP_Error( 'invalid_taxonomy', __( 'Invalid target taxonomy.', 'hypertaxonomy' ) );
	}

	$term = get_term( $term_id );
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'term_not_found', __( 'Term not found.', 'hypertaxonomy' ) );
	}

	$old_taxonomy = $term->taxonomy;
	$old_tt_id    = (int) $term->term_taxonomy_id;

	if ( $old_taxonomy === $target_taxonomy ) {
		return new WP_Error( 'same_taxonomy', __( 'Term is already in the target taxonomy.', 'hypertaxonomy' ) );
	}

	if ( 'group' === $old_taxonomy || 'group' === $target_taxonomy ) {
		return new WP_Error(
			'group_not_supported',
			__( 'Conversions involving the group taxonomy are not supported.', 'hypertaxonomy' )
		);
	}

	// Reverse conversion check must come before name/slug collision guards: if an
	// auto-redirect stub for this term already exists in the target taxonomy, the
	// "collision" is intentional — it IS the stub we are restoring.
	$existing_stub = htax_find_auto_redirect_to_term( $term_id, $target_taxonomy );
	if ( $existing_stub ) {
		return htax_restore_converted_term( $term_id, $old_taxonomy, $target_taxonomy, $existing_stub );
	}

	// Name collision in target taxonomy.
	$name_collision = get_term_by( 'name', $term->name, $target_taxonomy );
	if ( $name_collision && (int) $name_collision->term_id !== $term_id ) {
		$target_tax_obj = get_taxonomy( $target_taxonomy );
		$target_label   = $target_tax_obj ? mb_strtolower( $target_tax_obj->labels->name, 'UTF-8' ) : $target_taxonomy;
		return new WP_Error(
			'name_collision',
			sprintf(
				/* translators: 1: term name, 2: target taxonomy label */
				__( 'A term named "%1$s" already exists in %2$s. Rename this term or the existing one first, then retry the conversion.', 'hypertaxonomy' ),
				$term->name,
				$target_label
			)
		);
	}

	// Slug collisions in the target taxonomy are handled by WordPress automatically:
	// wp_insert_term appends a numeric suffix (e.g. "test-2") when the slug is taken,
	// matching the same behavior as the native Add Term UI. No pre-conversion guard
	// is needed. For the no-redirect path the code additionally attempts to claim
	// the original slug after the old term is deleted — if it is still taken by a
	// different target-taxonomy term at that point, WordPress keeps the suffixed slug.

	// Block conversion of the site's default category.
	if ( 'category' === $old_taxonomy && (int) get_option( 'default_category' ) === $term_id ) {
		return new WP_Error(
			'default_term',
			__( 'This is the site\'s default category. Change the default category under Settings > Writing before converting this term.', 'hypertaxonomy' )
		);
	}

	// Block when wp_terms.term_group would collide in the target taxonomy.
	// term_group is a legacy WordPress field used by some translation plugins to
	// link synonymous terms; duplicating its value in the target taxonomy would
	// corrupt those groupings.
	if ( $term->term_group > 0 ) {
		$group_collision = $wpdb->get_var( $wpdb->prepare(
			"SELECT t.term_id
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			WHERE t.term_group = %d AND tt.taxonomy = %s AND t.term_id != %d
			LIMIT 1",
			$term->term_group,
			$target_taxonomy,
			$term_id
		) );
		if ( $group_collision ) {
			$target_tax_obj = get_taxonomy( $target_taxonomy );
			$target_label   = $target_tax_obj ? mb_strtolower( $target_tax_obj->labels->name, 'UTF-8' ) : $target_taxonomy;
			return new WP_Error(
				'term_group_collision',
				sprintf(
					/* translators: 1: term_group value, 2: target taxonomy label */
					__( 'This term shares a term_group value (%1$d) with another term already in %2$s. This typically signals a translation plugin\'s synonym grouping — converting would create a conflict. Change the term_group first.', 'hypertaxonomy' ),
					$term->term_group,
					$target_label
				)
			);
		}
	}

	// Block conversion of terms that are already redirect stubs. A redirect term
	// has no posts of its own and no active archive, so converting it would
	// overwrite the existing redirect silently. Remove the redirect on the term
	// edit screen before converting.
	if ( htax_get_redirect_target( $term_id ) ) {
		return new WP_Error(
			'term_is_redirect',
			__( 'This term is already a redirect. Remove the redirect on the term edit screen before converting.', 'hypertaxonomy' )
		);
	}

	// -----------------------------------------------------------------------
	// Create the new term in the target taxonomy.
	// For the redirect case the slug is auto-generated (old term keeps its slug
	// as the stub). For the no-redirect case we release the old slug first.
	// -----------------------------------------------------------------------

	$new_term_args = array( 'description' => $term->description );

	if ( $create_redirect ) {
		// Old term keeps its slug as the stub. WordPress assigns a slug to the new
		// term using its standard per-taxonomy uniqueness rules — same slug in a
		// different taxonomy is allowed and expected.
		$new_term = wp_insert_term( $term->name, $target_taxonomy, $new_term_args );
	} else {
		// No-redirect path. Mirrors wpcat2tag's hook sequence — wp_insert_term
		// fires created_term first, then wp_delete_term fires delete_term — so
		// that plugins (WPML, Polylang, etc.) which detect conversions by watching
		// those standard WordPress hooks work with HT's native converter too.
		// A flag prevents HT's own external-detection hooks from double-firing.
		$old_slug      = $term->slug;
		$old_is_hidden = htax_is_term_hidden( $term_id );
		$old_icon_id   = (int) get_term_meta( $term_id, '_htax_icon_id', true );

		// Sticky rows must be captured before wp_delete_term wipes the postmeta.
		$old_sticky_key  = htax_get_sticky_assignment_key( $term_id );
		$old_sticky_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$old_sticky_key
		) );

		// Suppress HT's external-detection hooks for the duration of this sequence.
		$GLOBALS['htax_native_conversion_in_progress'] = true;

		// 1. Create new term — fires created_term. Old slug is still taken so
		// WordPress generates a unique one; we fix it after the old term is deleted.
		$new_term = wp_insert_term( $term->name, $target_taxonomy, $new_term_args );

		if ( is_wp_error( $new_term ) ) {
			unset( $GLOBALS['htax_native_conversion_in_progress'] );
			return new WP_Error(
				'insert_failed',
				__( 'Failed to create the term in the target taxonomy.', 'hypertaxonomy' )
			);
		}

		$new_term_id = (int) $new_term['term_id'];
		$new_tt_id   = (int) $new_term['term_taxonomy_id'];

		// 2. Move post assignments before deletion (same as wpcat2tag).
		$wpdb->update(
			$wpdb->term_relationships,
			array( 'term_taxonomy_id' => $new_tt_id ),
			array( 'term_taxonomy_id' => $old_tt_id ),
			array( '%d' ),
			array( '%d' )
		);
		$post_ids_to_move = $wpdb->get_col( $wpdb->prepare(
			"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
			$new_tt_id
		) );

		// 3. Delete old term (fires delete_term — WPML/Polylang hook here to
		//    transfer translation links to the new term).
		wp_delete_term( $term_id, $old_taxonomy );

		unset( $GLOBALS['htax_native_conversion_in_progress'] );

		// 4. Claim the original slug now that it is free.
		wp_update_term( $new_term_id, $target_taxonomy, array( 'slug' => $old_slug ) );

		// _update_post_term_count (called by wp_update_term_count*) expects
		// term_taxonomy_ids, not term_ids — pass $new_tt_id directly.
		wp_update_term_count_now( array( $new_tt_id ), $target_taxonomy );

		// Apply concept-level attributes to the new term.
		if ( $old_is_hidden ) {
			update_term_meta( $new_term_id, '_htax_is_hidden', '1' );
			do_action( 'htax_term_visibility_changed', $new_term_id, $target_taxonomy );
		}
		if ( $old_icon_id && htax_should_transfer_icon( $old_taxonomy, $target_taxonomy ) ) {
			update_term_meta( $new_term_id, '_htax_icon_id', $old_icon_id );
		}
		$new_sticky_key = htax_get_sticky_assignment_key( $new_term_id );
		foreach ( $old_sticky_rows as $row ) {
			update_post_meta( (int) $row->post_id, $new_sticky_key, $row->meta_value );
		}
		if ( ! empty( $old_sticky_rows ) ) {
			htax_delete_transient( 'htax_sticky_' . $new_term_id );
		}

		htax_update_nav_menu_items_for_converted_term( $term_id, $old_taxonomy, $new_term_id, $target_taxonomy );
		clean_term_cache( $new_term_id, $target_taxonomy );
		htax_clear_ajax_caches( $new_term_id );

		if ( ! empty( $post_ids_to_move ) ) {
			htax_get_search_index()->queue_post_reindex( $post_ids_to_move );
		}

		do_action( 'htax_term_archive_changed', $new_term_id, $target_taxonomy );

		/**
		 * Fires after a term is successfully converted to a different taxonomy.
		 *
		 * In the no-redirect path $old_term_id is the ID of the deleted source
		 * term and $new_term_id is the freshly created replacement. In the
		 * redirect path they differ in the same way; for reverse conversions the
		 * stub is restored so $new_term_id === $old_term_id.
		 *
		 * @since 1.0
		 *
		 * @param int    $new_term_id  Term ID now in the new taxonomy.
		 * @param string $old_taxonomy Taxonomy before conversion.
		 * @param string $new_taxonomy Taxonomy after conversion.
		 * @param int    $old_term_id  Original term ID (same as new_term_id for
		 *                             native redirect conversions).
		 */
		do_action( 'htax_term_converted', $new_term_id, $old_taxonomy, $target_taxonomy, $term_id );

		return array(
			'converted'          => 1,
			'redirect_created'   => false,
			'reverse_conversion' => false,
		);
	}

	// -----------------------------------------------------------------------
	// Redirect case: new term was inserted above; now move posts and make old
	// term into the redirect stub.
	// -----------------------------------------------------------------------

	if ( is_wp_error( $new_term ) ) {
		return new WP_Error(
			'insert_failed',
			__( 'Failed to create the term in the target taxonomy.', 'hypertaxonomy' )
		);
	}

	$new_term_id = (int) $new_term['term_id'];
	$new_tt_id   = (int) $new_term['term_taxonomy_id'];

	// Move all post assignments from old term to new term in one query.
	$wpdb->update(
		$wpdb->term_relationships,
		array( 'term_taxonomy_id' => $new_tt_id ),
		array( 'term_taxonomy_id' => $old_tt_id ),
		array( '%d' ),
		array( '%d' )
	);

	wp_update_term_count_now( array( $old_tt_id ),  $old_taxonomy   );
	wp_update_term_count_now( array( $new_tt_id ),  $target_taxonomy );

	// Transfer concept-level attributes to the new term.
	// Hidden status and icon follow the concept; sticky posts are moved (the stub
	// has no active archive so stickies there would never be displayed).
	if ( htax_is_term_hidden( $term_id ) ) {
		update_term_meta( $new_term_id, '_htax_is_hidden', '1' );
		do_action( 'htax_term_visibility_changed', $new_term_id, $target_taxonomy );
	}
	$old_icon_id = (int) get_term_meta( $term_id, '_htax_icon_id', true );
	if ( $old_icon_id && htax_should_transfer_icon( $old_taxonomy, $target_taxonomy ) ) {
		update_term_meta( $new_term_id, '_htax_icon_id', $old_icon_id );
	}

	// Convert old term to redirect stub.
	htax_set_redirect( $term_id, $new_term_id );
	update_term_meta( $term_id, '_htax_auto_redirect', '1' );

	// Queue search reindex for all posts now on the new term.
	$post_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
		$new_tt_id
	) );
	if ( ! empty( $post_ids ) ) {
		htax_get_search_index()->queue_post_reindex( $post_ids );
	}

	htax_transfer_sticky_posts_on_convert( $term_id, $new_term_id );
	htax_update_nav_menu_items_for_converted_term( $term_id, $old_taxonomy, $new_term_id, $target_taxonomy );

	clean_term_cache( $term_id,     $old_taxonomy   );
	clean_term_cache( $new_term_id, $target_taxonomy );
	htax_reload_term_caches_on_edit( $term_id, $old_tt_id );
	htax_clear_ajax_caches( $term_id );

	do_action( 'htax_term_archive_changed', $term_id, $old_taxonomy );
	do_action( 'htax_term_converted', $new_term_id, $old_taxonomy, $target_taxonomy, $term_id );

	return array(
		'converted'          => 1,
		'redirect_created'   => true,
		'reverse_conversion' => false,
	);
}

/**
 * Convert multiple terms to a different taxonomy.
 *
 * Terms that fail are recorded in 'errors'; the rest are converted.
 * Returns a summary array:
 *   'converted'           => int
 *   'redirects_created'   => int
 *   'reverse_conversions' => int
 *   'errors'              => string[]
 *
 * @since 1.0
 *
 * @param int[]  $term_ids        Term IDs to convert (must all be in $from_taxonomy).
 * @param string $from_taxonomy   Source taxonomy slug (used for validation).
 * @param string $to_taxonomy     Target taxonomy slug.
 * @param bool   $create_redirect Whether to leave redirect stubs.
 * @return array
 */
function htax_convert_terms_batch( array $term_ids, string $from_taxonomy, string $to_taxonomy, bool $create_redirect = true ): array {
	$summary = array(
		'converted'           => 0,
		'redirects_created'   => 0,
		'reverse_conversions' => 0,
		'errors'              => array(),
		'failed_term_ids'     => array(),
	);

	// Process parents before children so that stub parent chains are built
	// correctly in hierarchical taxonomies.
	if ( is_taxonomy_hierarchical( $from_taxonomy ) ) {
		$term_ids = htax_sort_term_ids_parent_first( $term_ids, $from_taxonomy );
	}

	foreach ( $term_ids as $term_id ) {
		$term_id = (int) $term_id;

		$term = get_term( $term_id, $from_taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			/* translators: %d: term ID */
			$summary['errors'][] = sprintf( __( 'Term #%d not found in source taxonomy.', 'hypertaxonomy' ), $term_id );
			continue;
		}

		$result = htax_convert_term( $term_id, $to_taxonomy, $create_redirect );

		if ( is_wp_error( $result ) ) {
			$summary['errors'][]         = esc_html( $term->name ) . ': ' . $result->get_error_message();
			$summary['failed_term_ids'][] = $term_id;
		} else {
			$summary['converted']++;
			if ( ! empty( $result['redirect_created'] ) ) {
				$summary['redirects_created']++;
			}
			if ( ! empty( $result['reverse_conversion'] ) ) {
				$summary['reverse_conversions']++;
			}
		}
	}

	return $summary;
}

/**
 * Restore a term that was previously converted via htax_convert_term.
 *
 * The stub (original term, now a redirect in old_taxonomy) is restored to its
 * pre-conversion state: posts are moved back, the redirect is removed, and the
 * replacement term in new_taxonomy is deleted.
 *
 * @since 1.0
 *
 * @param int     $new_term_id   ID of the replacement term (currently in new_taxonomy).
 * @param string  $new_taxonomy  Taxonomy the replacement term is in.
 * @param string  $old_taxonomy  Original taxonomy (where the stub lives).
 * @param WP_Term $stub          Auto-redirect stub to restore.
 * @return array
 */
function htax_restore_converted_term( int $new_term_id, string $new_taxonomy, string $old_taxonomy, WP_Term $stub ): array {
	global $wpdb;

	$stub_term_id = (int) $stub->term_id;
	$stub_tt_id   = (int) $stub->term_taxonomy_id;

	$new_term = get_term( $new_term_id, $new_taxonomy );
	if ( ! $new_term || is_wp_error( $new_term ) ) {
		return array( 'converted' => 0, 'redirect_created' => false, 'reverse_conversion' => false );
	}
	$new_tt_id = (int) $new_term->term_taxonomy_id;

	// Move posts back from new term to stub.
	$wpdb->update(
		$wpdb->term_relationships,
		array( 'term_taxonomy_id' => $stub_tt_id ),
		array( 'term_taxonomy_id' => $new_tt_id ),
		array( '%d' ),
		array( '%d' )
	);

	wp_update_term_count_now( array( $stub_tt_id ), $old_taxonomy  );
	wp_update_term_count_now( array( $new_tt_id ),  $new_taxonomy  );

	// Remove the redirect from the stub so it is a normal term again.
	htax_remove_redirect( $stub_term_id );
	delete_term_meta( $stub_term_id, '_htax_auto_redirect' );

	// Delete the replacement term in the new taxonomy.
	wp_delete_term( $new_term_id, $new_taxonomy );

	// Restore hidden status on stub to match what it was (it kept its meta, so
	// nothing to do) — but clear it from the now-deleted new term's meta just in
	// case the delete_term cleanup missed it.

	// Queue search reindex.
	$post_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
		$stub_tt_id
	) );
	if ( ! empty( $post_ids ) ) {
		htax_get_search_index()->queue_post_reindex( $post_ids );
	}

	htax_update_nav_menu_items_for_converted_term( $new_term_id, $new_taxonomy, $stub_term_id, $old_taxonomy );

	clean_term_cache( $stub_term_id, $old_taxonomy );
	htax_reload_term_caches_on_edit( $stub_term_id, $stub_tt_id );
	htax_clear_ajax_caches( $stub_term_id );

	do_action( 'htax_term_archive_changed', $stub_term_id, $old_taxonomy );
	do_action( 'htax_term_converted', $stub_term_id, $new_taxonomy, $old_taxonomy, $new_term_id );

	return array(
		'converted'          => 1,
		'redirect_created'   => false,
		'reverse_conversion' => true,
	);
}

// ---------------------------------------------------------------------------
// TERM EDIT CACHE (used by search index for parent-change detection)
// ---------------------------------------------------------------------------

/**
 * Cache hierarchy data before a term edit so the search index can detect
 * parent changes.
 *
 * Stores the term's current parent and direct children in a short-lived
 * transient keyed by term_taxonomy_id. The search index (class-search-index.php)
 * reads htax_term_data_{tt_id} inside its edited_term handlers to determine
 * whether the parent actually changed before triggering a reindex.
 *
 * Hooked to edit_term at priority 5 (before all save handlers).
 *
 * @since 1.0
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Current taxonomy.
 */
function htax_cache_term_taxonomy_before_edit( $term_id, $tt_id, $taxonomy ) {
	global $wpdb;

	if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
		return;
	}

	$term = get_term( $term_id, $taxonomy );
	if ( ! is_wp_error( $term ) && $term ) {
		$children = $wpdb->get_col( $wpdb->prepare(
			"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent = %d",
			$taxonomy,
			$term_id
		) );
		htax_set_transient( 'htax_term_data_' . $tt_id, array(
			'parent'   => (int) $term->parent,
			'children' => array_map( 'intval', $children ),
		), 60 );
	}
}

// ---------------------------------------------------------------------------
// EXTERNAL CONVERSION DETECTION (wpcat2tag and similar plugins)
// ---------------------------------------------------------------------------

/**
 * Track newly created terms so htax_detect_external_conversion_pre_delete can
 * recognize the create-new + delete-old pattern used by external converters.
 *
 * Hooked to created_term at priority 1 (before any other HT hook).
 *
 * @since 1.0
 *
 * @param int    $term_id  Newly created term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy of the new term.
 */
function htax_track_created_term_for_conversion( int $term_id, int $tt_id, string $taxonomy ): void {
	if ( 'group' === $taxonomy ) {
		return;
	}

	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}

	$key = strtolower( $term->name );
	$GLOBALS['htax_recently_created_terms'][ $key ] = array(
		'term_id'  => $term_id,
		'taxonomy' => $taxonomy,
	);
}

/**
 * Before an external converter deletes the old term, capture the HT state we
 * need to preserve: group memberships, hierarchy, and icon ID.
 *
 * Hooked to pre_delete_term at priority 5.
 *
 * @since 1.0
 *
 * @param int    $term_id  Term about to be deleted.
 * @param string $taxonomy Taxonomy of the term being deleted.
 */
function htax_detect_external_conversion_pre_delete( int $term_id, string $taxonomy ): void {
	global $wpdb;

	if ( 'group' === $taxonomy ) {
		return;
	}

	// Skip when HT's own native converter is running — it handles everything
	// directly and does not need the external detection path.
	if ( ! empty( $GLOBALS['htax_native_conversion_in_progress'] ) ) {
		return;
	}

	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}

	$key = strtolower( $term->name );
	if ( ! isset( $GLOBALS['htax_recently_created_terms'][ $key ] ) ) {
		return;
	}

	$created = $GLOBALS['htax_recently_created_terms'][ $key ];

	if ( $created['taxonomy'] === $taxonomy || 'group' === $created['taxonomy'] ) {
		return;
	}

	$table = $wpdb->prefix . 'htax_term_relationships';

	$groups = $wpdb->get_col( $wpdb->prepare(
		"SELECT target_term_id FROM {$table}
		WHERE source_term_id = %d AND target_taxonomy = 'group' AND relationship_type = 'member_of'",
		$term_id
	) );

	$children = array();
	if ( is_taxonomy_hierarchical( $taxonomy ) ) {
		$children = $wpdb->get_col( $wpdb->prepare(
			"SELECT term_id FROM {$wpdb->term_taxonomy}
			WHERE taxonomy = %s AND parent = %d",
			$taxonomy,
			$term_id
		) );
	}

	$redirect_target  = htax_get_redirect_target( $term_id );
	$final_target_id  = $redirect_target ? (int) $redirect_target->term_id : (int) $created['term_id'];
	$final_target_tax = $redirect_target ? $redirect_target->taxonomy       : $created['taxonomy'];

	$icon_id   = get_term_meta( $term_id, '_htax_icon_id', true );
	$is_hidden = htax_is_term_hidden( $term_id );

	// Capture sticky assignments now — htax_cleanup_deleted_term (fired via
	// delete_term_taxonomy before delete_term) will delete these rows before
	// htax_complete_external_conversion_after_delete gets a chance to transfer them.
	$sticky_key          = htax_get_sticky_assignment_key( $term_id );
	$sticky_assignments  = array();
	$sticky_rows         = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$sticky_key
	) );
	foreach ( $sticky_rows as $row ) {
		$sticky_assignments[ (int) $row->post_id ] = $row->meta_value;
	}

	$GLOBALS['htax_pending_external_conversions'][ $term_id ] = array(
		'old_taxonomy'      => $taxonomy,
		'new_term_id'       => (int) $created['term_id'],
		'new_taxonomy'      => $created['taxonomy'],
		'final_target_id'   => $final_target_id,
		'final_target_tax'  => $final_target_tax,
		'groups'            => array_map( 'intval', $groups ),
		'old_term_data'     => array(
			'parent'   => (int) $term->parent,
			'children' => array_map( 'intval', $children ),
		),
		'icon_id'            => $icon_id ? (int) $icon_id : 0,
		'is_hidden'          => $is_hidden,
		'sticky_assignments' => $sticky_assignments,
	);

	unset( $GLOBALS['htax_recently_created_terms'][ $key ] );
}

/**
 * Complete an external conversion after the old term has been deleted.
 *
 * Runs at priority 5 on delete_term. By this point htax_cleanup_deleted_term has
 * already run (via delete_term_taxonomy priority 10, which fires before delete_term),
 * so sticky assignments must be captured earlier at pre_delete_term time and stored
 * in htax_pending_external_conversions for replay here.
 *
 * @since 1.0
 *
 * @param int    $term_id  Deleted term ID.
 * @param int    $tt_id    Deleted term taxonomy ID.
 * @param string $taxonomy Taxonomy of the deleted term.
 */
function htax_complete_external_conversion_after_delete( int $term_id, int $tt_id, string $taxonomy ): void {
	global $wpdb;

	if ( ! isset( $GLOBALS['htax_pending_external_conversions'][ $term_id ] ) ) {
		return;
	}

	$conv = $GLOBALS['htax_pending_external_conversions'][ $term_id ];
	unset( $GLOBALS['htax_pending_external_conversions'][ $term_id ] );

	$new_term_id  = $conv['new_term_id'];
	$new_taxonomy = $conv['new_taxonomy'];
	$old_taxonomy = $conv['old_taxonomy'];

	// Transfer concept-level attributes to the new term.
	if ( $conv['is_hidden'] ) {
		update_term_meta( $new_term_id, '_htax_is_hidden', '1' );
		do_action( 'htax_term_visibility_changed', $new_term_id, $new_taxonomy );
	}
	if ( $conv['icon_id'] && htax_should_transfer_icon( $old_taxonomy, $new_taxonomy ) ) {
		update_term_meta( $new_term_id, '_htax_icon_id', $conv['icon_id'] );
	}

	// Group memberships are not transferred to the new term. Groups are
	// taxonomy-context scaffolding: without a redirect stub to hold them in the
	// old taxonomy, there is no safe recipient. The admin reassigns manually.
	// (Contrast: hidden status, icon, and sticky posts are concept-level and do
	// transfer — see below.)

	// Transfer sticky post assignments using the data captured at pre_delete_term time.
	// The postmeta rows are already gone by this point (htax_cleanup_deleted_term runs
	// via delete_term_taxonomy before delete_term), so we replay the captured assignments.
	if ( ! empty( $conv['sticky_assignments'] ) ) {
		$new_sticky_key = htax_get_sticky_assignment_key( $new_term_id );
		foreach ( $conv['sticky_assignments'] as $post_id => $meta_value ) {
			update_post_meta( $post_id, $new_sticky_key, $meta_value );
		}
		htax_delete_transient( 'htax_sticky_' . $new_term_id );
	}

	// Queue search reindex for posts now on the new term.
	$post_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT object_id FROM {$wpdb->term_relationships}
		WHERE term_taxonomy_id = (
			SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
			WHERE term_id = %d AND taxonomy = %s LIMIT 1
		)",
		$new_term_id,
		$new_taxonomy
	) );
	if ( ! empty( $post_ids ) ) {
		htax_get_search_index()->queue_post_reindex( $post_ids );
	}

	htax_reload_term_caches_on_edit( $new_term_id );
	htax_update_nav_menu_items_for_converted_term( $term_id, $old_taxonomy, $new_term_id, $new_taxonomy );
	do_action( 'htax_term_archive_changed', $new_term_id, $new_taxonomy );
	do_action( 'htax_term_converted', $new_term_id, $old_taxonomy, $new_taxonomy, $term_id );
}

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------

/**
 * Check whether an icon attachment can be transferred between taxonomies.
 *
 * Transfer is valid when icons are enabled for the new taxonomy AND both
 * taxonomies use the same icon dimensions (different sizes would require a
 * re-crop of the original image rather than simple reuse of the attachment).
 *
 * @since 1.0
 *
 * @param string $old_taxonomy Source taxonomy slug.
 * @param string $new_taxonomy Target taxonomy slug.
 * @return bool
 */
function htax_should_transfer_icon( string $old_taxonomy, string $new_taxonomy ): bool {
	if ( ! htax_taxonomy_has_feature( $new_taxonomy, 'icons' ) ) {
		return false;
	}
	$options = get_option( 'htax_settings', array() );
	$old_cfg = $options['icon_settings'][ $old_taxonomy ] ?? array();
	$new_cfg = $options['icon_settings'][ $new_taxonomy ] ?? array();
	return (int) ( $old_cfg['width'] ?? 96 ) === (int) ( $new_cfg['width'] ?? 96 )
		&& (int) ( $old_cfg['height'] ?? 96 ) === (int) ( $new_cfg['height'] ?? 96 );
}

/**
 * Transfer sticky post assignments from one term to another.
 *
 * Sticky assignments are stored as post meta keyed by term ID
 * (`_htax_sticky_{term_id}`). After a conversion the old term's sticky
 * assignments are orphaned because its archive redirects — they must be moved
 * to the new term so the new archive honours the sticky order.
 *
 * Called before htax_cleanup_deleted_term (priority 10) so that the postmeta
 * rows still exist when this function runs.
 *
 * @since 1.0
 *
 * @param int $old_term_id Term losing its sticky assignments.
 * @param int $new_term_id Term gaining the sticky assignments.
 */
function htax_transfer_sticky_posts_on_convert( int $old_term_id, int $new_term_id ): void {
	global $wpdb;

	$old_key = htax_get_sticky_assignment_key( $old_term_id );
	$new_key = htax_get_sticky_assignment_key( $new_term_id );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$old_key
	) );

	foreach ( $rows as $row ) {
		update_post_meta( (int) $row->post_id, $new_key, $row->meta_value );
		delete_post_meta( (int) $row->post_id, $old_key );
	}

	htax_delete_transient( 'htax_sticky_' . $old_term_id );
	htax_delete_transient( 'htax_sticky_' . $new_term_id );
}

/**
 * Update WordPress navigation menu items that reference a converted term.
 *
 * @since 1.0
 *
 * @param int    $old_term_id  Term ID before conversion.
 * @param string $old_taxonomy Taxonomy the term was in.
 * @param int    $new_term_id  Term ID after conversion.
 * @param string $new_taxonomy Taxonomy the term is now in.
 */
function htax_update_nav_menu_items_for_converted_term(
	int $old_term_id,
	string $old_taxonomy,
	int $new_term_id,
	string $new_taxonomy
): void {
	global $wpdb;

	$nav_item_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT pm_type.post_id
		FROM {$wpdb->postmeta} pm_type
		INNER JOIN {$wpdb->postmeta} pm_obj ON pm_type.post_id = pm_obj.post_id
		INNER JOIN {$wpdb->postmeta} pm_id  ON pm_type.post_id = pm_id.post_id
		WHERE pm_type.meta_key = '_menu_item_type'      AND pm_type.meta_value = 'taxonomy'
		AND   pm_obj.meta_key  = '_menu_item_object'    AND pm_obj.meta_value  = %s
		AND   pm_id.meta_key   = '_menu_item_object_id' AND pm_id.meta_value   = %d",
		$old_taxonomy,
		$old_term_id
	) );

	if ( empty( $nav_item_ids ) ) {
		return;
	}

	foreach ( $nav_item_ids as $post_id ) {
		update_post_meta( (int) $post_id, '_menu_item_object', $new_taxonomy );
		if ( $old_term_id !== $new_term_id ) {
			update_post_meta( (int) $post_id, '_menu_item_object_id', $new_term_id );
		}
		delete_post_meta( (int) $post_id, '_menu_item_url' );
	}

	wp_cache_delete( 'nav-menu-info', 'nav_menus' );
}

/**
 * Find an auto-generated redirect stub (in $taxonomy) pointing to $target_term_id.
 *
 * @since 1.0
 *
 * @param int    $target_term_id Target term ID.
 * @param string $taxonomy       Taxonomy to search for the redirect stub.
 * @return WP_Term|null
 */
function htax_find_auto_redirect_to_term( $target_term_id, $taxonomy ) {
	global $wpdb;

	$table = $wpdb->prefix . 'htax_term_relationships';

	$redirect_term_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT r.source_term_id
		FROM {$table} r
		INNER JOIN {$wpdb->termmeta} tm ON r.source_term_id = tm.term_id
		INNER JOIN {$wpdb->term_taxonomy} tt ON r.source_term_id = tt.term_id
		WHERE r.relationship_type = 'redirect'
		AND r.target_term_id = %d
		AND tm.meta_key = '_htax_auto_redirect'
		AND tm.meta_value = '1'
		AND tt.taxonomy = %s
		LIMIT 1",
		$target_term_id,
		$taxonomy
	) );

	if ( ! $redirect_term_id ) {
		return null;
	}

	return get_term( (int) $redirect_term_id, $taxonomy );
}

/**
 * Create a redirect stub term in $old_taxonomy pointing to the converted term.
 *
 * Used by the external conversion detection path (wpcat2tag and similar).
 * The old slug is free at this point (old term was already deleted), so the
 * stub can claim the original slug.
 *
 * @since 1.0
 *
 * @param string     $name            Term name.
 * @param string     $old_taxonomy    Taxonomy to create the stub in.
 * @param int        $target_term_id  Term ID to redirect to.
 * @param string     $target_taxonomy Taxonomy of the redirect target.
 * @param array|null $old_term_data   Hierarchy data: ['parent' => int, 'children' => int[]].
 * @return int|null  New stub term ID, or null on failure.
 */
function htax_create_redirect_term( $name, $old_taxonomy, $target_term_id, $target_taxonomy, $old_term_data = null ) {
	$existing_term = get_term_by( 'name', $name, $old_taxonomy );

	if ( $existing_term ) {
		$redirect_target = htax_get_redirect_target( $existing_term->term_id );
		if ( $redirect_target && (int) $redirect_target->term_id === (int) $target_term_id ) {
			update_term_meta( $existing_term->term_id, '_htax_auto_redirect', '1' );
			return $existing_term->term_id;
		}
		return null;
	}

	$term_args = array(
		'description' => sprintf(
			/* translators: 1: original term name, 2: target taxonomy singular label */
			__( 'Redirect to %1$s (%2$s)', 'hypertaxonomy' ),
			$name,
			htax_get_taxonomy_singular( $target_taxonomy )
		),
	);

	if ( is_taxonomy_hierarchical( $old_taxonomy )
		&& $old_term_data
		&& isset( $old_term_data['parent'] )
		&& $old_term_data['parent'] > 0
	) {
		// Verify the parent is still in the old taxonomy (may have been converted
		// in the same batch before us).
		$parent_check = get_term( (int) $old_term_data['parent'], $old_taxonomy );
		if ( $parent_check && ! is_wp_error( $parent_check ) ) {
			$term_args['parent'] = $old_term_data['parent'];
		}
	}

	$new_term = wp_insert_term( $name, $old_taxonomy, $term_args );

	if ( is_wp_error( $new_term ) ) {
		return null;
	}

	htax_set_redirect( $new_term['term_id'], $target_term_id );
	update_term_meta( $new_term['term_id'], '_htax_auto_redirect', '1' );

	if ( htax_is_term_hidden( $target_term_id ) ) {
		update_term_meta( $new_term['term_id'], '_htax_is_hidden', '1' );
		do_action( 'htax_term_visibility_changed', $new_term['term_id'], $old_taxonomy );
	}

	if ( is_taxonomy_hierarchical( $old_taxonomy )
		&& $old_term_data
		&& ! empty( $old_term_data['children'] )
	) {
		foreach ( $old_term_data['children'] as $child_id ) {
			wp_update_term( (int) $child_id, $old_taxonomy, array( 'parent' => $new_term['term_id'] ) );
		}
	}

	return $new_term['term_id'];
}

/**
 * Return the singular label for a taxonomy.
 *
 * @since 1.0
 *
 * @param string $taxonomy Taxonomy slug.
 * @return string
 */
function htax_get_taxonomy_singular( $taxonomy ) {
	$tax_obj = get_taxonomy( $taxonomy );
	if ( ! $tax_obj ) {
		return ucfirst( $taxonomy );
	}
	return isset( $tax_obj->labels->singular_name ) ? $tax_obj->labels->singular_name : ucfirst( $taxonomy );
}
