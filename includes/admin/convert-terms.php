<?php
/**
 * Convert Terms Admin Page
 *
 * Provides an admin form to move terms from one post taxonomy to another.
 * Uses a two-step UI: select a source taxonomy, check the terms to convert,
 * choose a target taxonomy, then submit. The term checklist is loaded via
 * AJAX so the page does not need a separate "load terms" submit step.
 *
 * Conversion is handled by htax_convert_term() / htax_convert_terms_batch()
 * in includes/core/term-conversion.php.
 *
 * @package HyperTaxonomy
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Menu registration
// ---------------------------------------------------------------------------

/**
 * Register the Convert Terms submenu at priority 11 — before Merge Terms (12)
 * and before Peer Sites (13).
 *
 * @since 1.0
 */
function htax_add_convert_terms_page(): void {
	add_submenu_page(
		'htax-terms',
		__( 'Convert Terms', 'hypertaxonomy' ),
		__( 'Convert Terms', 'hypertaxonomy' ),
		'manage_options',
		'htax-convert-terms',
		'htax_render_convert_terms_page'
	);
}
add_action( 'admin_menu', 'htax_add_convert_terms_page', 11 );

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------

/**
 * Enqueue JS on the Convert Terms page.
 *
 * @since 1.0
 *
 * @param string $hook Current admin page hook.
 */
function htax_enqueue_convert_terms_assets( string $hook ): void {
	if ( false === strpos( $hook, 'htax-convert-terms' ) ) {
		return;
	}

	wp_enqueue_script(
		'htax-convert-terms',
		HTAX_PLUGIN_URL . 'admin/js/htax-convert-terms.js',
		array( 'jquery' ),
		HTAX_VERSION,
		true
	);

	wp_localize_script( 'htax-convert-terms', 'htaxConvertTerms', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'htax_convert_terms' ),
		'i18n'    => array(
			'loading'    => __( 'Loading terms…', 'hypertaxonomy' ),
			'noTerms'    => __( 'No terms found in this taxonomy.', 'hypertaxonomy' ),
			'selectFrom' => __( 'Please select a source taxonomy.', 'hypertaxonomy' ),
			'selectSome' => __( 'Please select at least one term to convert.', 'hypertaxonomy' ),
			'selectDest' => __( 'Please select a target taxonomy.', 'hypertaxonomy' ),
			'sameTax'    => __( 'Source and target taxonomies must be different.', 'hypertaxonomy' ),
			'selectAll'  => __( 'Select all', 'hypertaxonomy' ),
			'deselectAll'=> __( 'Deselect all', 'hypertaxonomy' ),
		),
	) );
}
add_action( 'admin_enqueue_scripts', 'htax_enqueue_convert_terms_assets' );

// ---------------------------------------------------------------------------
// AJAX: load term checklist for a taxonomy
// ---------------------------------------------------------------------------

/**
 * Return a flat list of terms for the given taxonomy, used to populate the
 * term checklist dynamically.
 *
 * @since 1.0
 */
function htax_ajax_get_terms_for_conversion(): void {
	if ( ! check_ajax_referer( 'htax_convert_terms', 'nonce', false )
		|| ! current_user_can( 'manage_options' )
	) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hypertaxonomy' ) ) );
	}

	$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';

	if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid taxonomy.', 'hypertaxonomy' ) ) );
	}

	$terms = get_terms( array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'orderby'    => 'name',
		'order'      => 'ASC',
		'number'     => 0,
	) );

	if ( is_wp_error( $terms ) ) {
		wp_send_json_error( array( 'message' => $terms->get_error_message() ) );
	}

	$tax_obj = get_taxonomy( $taxonomy );
	$data    = array();
	foreach ( $terms as $term ) {
		$count  = (int) $term->count;
		$data[] = array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'count'       => $count,
			/* translators: %d: post count */
			'count_label' => sprintf( _n( '%d post', '%d posts', $count, 'hypertaxonomy' ), $count ),
			'parent'      => (int) $term->parent,
		);
	}

	wp_send_json_success( array(
		'terms'        => $data,
		'hierarchical' => $tax_obj ? (bool) $tax_obj->hierarchical : false,
	) );
}
add_action( 'wp_ajax_htax_get_terms_for_conversion', 'htax_ajax_get_terms_for_conversion' );

// ---------------------------------------------------------------------------
// Page renderer
// ---------------------------------------------------------------------------

/**
 * Render the Convert Terms admin page.
 *
 * @since 1.0
 */
function htax_render_convert_terms_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'hypertaxonomy' ) );
	}

	$results = null;
	$errors  = array();

	// -----------------------------------------------------------------------
	// Process form submission
	// -----------------------------------------------------------------------

	if ( isset( $_POST['htax_do_convert'] ) && check_admin_referer( 'htax_convert_terms' ) ) {
		$from_taxonomy   = isset( $_POST['htax_from_taxonomy'] )
			? sanitize_key( wp_unslash( $_POST['htax_from_taxonomy'] ) ) : '';
		$to_taxonomy     = isset( $_POST['htax_to_taxonomy'] )
			? sanitize_key( wp_unslash( $_POST['htax_to_taxonomy'] ) ) : '';
		$create_redirect = ! empty( $_POST['htax_create_redirect'] );
		$raw_ids         = isset( $_POST['htax_convert_term_ids'] )
			? (array) wp_unslash( $_POST['htax_convert_term_ids'] ) : array();
		$term_ids        = array_filter( array_map( 'intval', $raw_ids ) );

		if ( ! $from_taxonomy ) {
			$errors[] = __( 'Please select a source taxonomy.', 'hypertaxonomy' );
		} elseif ( ! $to_taxonomy ) {
			$errors[] = __( 'Please select a target taxonomy.', 'hypertaxonomy' );
		} elseif ( $from_taxonomy === $to_taxonomy ) {
			$errors[] = __( 'Source and target taxonomies must be different.', 'hypertaxonomy' );
		} elseif ( empty( $term_ids ) ) {
			$errors[] = __( 'Please select at least one term to convert.', 'hypertaxonomy' );
		} else {
			$results = htax_convert_terms_batch( $term_ids, $from_taxonomy, $to_taxonomy, $create_redirect );
			if ( ! empty( $results['errors'] ) ) {
				$errors = array_merge( $errors, $results['errors'] );
			}
		}
	}

	// -----------------------------------------------------------------------
	// Build taxonomy option list (all HT-enabled post taxonomies, no group)
	// -----------------------------------------------------------------------

	$enabled      = array_diff( htax_get_enabled_taxonomies(), array( 'group' ) );
	$tax_options  = array();
	foreach ( $enabled as $slug ) {
		$tax_obj = get_taxonomy( $slug );
		if ( $tax_obj ) {
			$tax_options[ $slug ] = $tax_obj->labels->name;
		}
	}

	// Restore previous form values so they survive a POST with errors.
	$prev_from = isset( $_POST['htax_from_taxonomy'] )
		? sanitize_key( wp_unslash( $_POST['htax_from_taxonomy'] ) ) : '';
	$prev_to   = isset( $_POST['htax_to_taxonomy'] )
		? sanitize_key( wp_unslash( $_POST['htax_to_taxonomy'] ) ) : '';

	$redirect_checked = isset( $_POST['htax_do_convert'] )
		? ! empty( $_POST['htax_create_redirect'] )
		: true;

	// Pre-load server-side term list when we know the source taxonomy (e.g.
	// after a POST with errors). JS will load it when the select changes.
	$prefill_terms        = array();
	$prefill_hierarchical = false;
	if ( $prev_from && taxonomy_exists( $prev_from ) ) {
		$prefill_terms = get_terms( array(
			'taxonomy'   => $prev_from,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'number'     => 0,
		) );
		if ( is_wp_error( $prefill_terms ) ) {
			$prefill_terms = array();
		}
		$tax_obj              = get_taxonomy( $prev_from );
		$prefill_hierarchical = $tax_obj ? (bool) $tax_obj->hierarchical : false;
	}

	// Pre-check only the terms that failed to convert — successfully converted terms
	// should not remain checked (redirect stubs still appear in the source list).
	$prev_term_ids = array();
	if ( $results && ! empty( $results['failed_term_ids'] ) ) {
		$prev_term_ids = $results['failed_term_ids'];
	} elseif ( ! isset( $_POST['htax_do_convert'] ) && isset( $_POST['htax_convert_term_ids'] ) ) {
		// Validation error (no batch ran yet) — restore all checked IDs.
		$prev_term_ids = array_map( 'intval', (array) wp_unslash( $_POST['htax_convert_term_ids'] ) );
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Convert Terms', 'hypertaxonomy' ); ?></h1>

		<p><?php esc_html_e( 'Move terms from one taxonomy to another. Post assignments are preserved — no posts are removed from the site.', 'hypertaxonomy' ); ?></p>
		<p><?php
			printf(
				/* translators: %s: link to Merge Terms page */
				esc_html__( 'If a term with the same name already exists in the target taxonomy, use %s instead.', 'hypertaxonomy' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=htax-merge-terms' ) ) . '">' . esc_html__( 'Merge Terms', 'hypertaxonomy' ) . '</a>'
			);
		?></p>
		<p style="color:#d63638;"><strong><?php esc_html_e( 'Warning: this operation cannot be undone. Back up your database before proceeding.', 'hypertaxonomy' ); ?></strong></p>
		<p><?php esc_html_e( 'Note: HyperTaxonomy tracks conversions made through this page or plugins that use WordPress term creation and deletion hooks (such as the Categories to Tags Converter). Direct database manipulation bypasses this integration.', 'hypertaxonomy' ); ?></p>

		<?php foreach ( $errors as $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endforeach; ?>

		<?php if ( $results ) :
			$has_errors  = ! empty( $results['errors'] );
			$has_success = $results['converted'] > 0;
		?>
		<?php if ( $has_success ) : ?>
			<div class="notice <?php echo $has_errors ? 'notice-warning' : 'notice-success'; ?>">
				<p><strong><?php echo $has_errors
					? esc_html__( 'Conversion complete with errors — some terms were skipped.', 'hypertaxonomy' )
					: esc_html__( 'Conversion complete.', 'hypertaxonomy' );
				?></strong></p>
			</div>
		<?php elseif ( $has_errors ) : ?>
			<div class="notice notice-error">
				<p><strong><?php esc_html_e( 'No terms were converted.', 'hypertaxonomy' ); ?></strong></p>
			</div>
		<?php endif; ?>
		<?php if ( $has_success || $has_errors ) : ?>
			<table class="widefat" style="max-width:400px;margin-bottom:24px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Terms converted:', 'hypertaxonomy' ); ?></strong></td>
						<td><?php echo (int) $results['converted']; ?></td>
					</tr>
					<?php if ( $results['redirects_created'] > 0 ) : ?>
					<tr>
						<td><strong><?php esc_html_e( 'Redirect stubs created:', 'hypertaxonomy' ); ?></strong></td>
						<td><?php echo (int) $results['redirects_created']; ?></td>
					</tr>
					<?php endif; ?>
					<?php if ( $results['reverse_conversions'] > 0 ) : ?>
					<tr>
						<td><strong><?php esc_html_e( 'Reverse conversions (stubs removed):', 'hypertaxonomy' ); ?></strong></td>
						<td><?php echo (int) $results['reverse_conversions']; ?></td>
					</tr>
					<?php endif; ?>
					<?php if ( $has_errors ) : ?>
					<tr>
						<td><strong><?php esc_html_e( 'Skipped (see errors above):', 'hypertaxonomy' ); ?></strong></td>
						<td><?php echo count( $results['errors'] ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php endif; ?>

		<form method="post" id="htax-convert-terms-form">
			<?php wp_nonce_field( 'htax_convert_terms' ); ?>

			<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Step 1 — Select terms to convert', 'hypertaxonomy' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="htax_from_taxonomy"><?php esc_html_e( 'From taxonomy', 'hypertaxonomy' ); ?></label>
					</th>
					<td>
						<select name="htax_from_taxonomy" id="htax_from_taxonomy">
							<option value=""><?php esc_html_e( '— Select taxonomy —', 'hypertaxonomy' ); ?></option>
							<?php foreach ( $tax_options as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"
									<?php selected( $prev_from, $slug ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Terms', 'hypertaxonomy' ); ?></th>
					<td>
						<div id="htax-term-list-container">
							<?php if ( ! empty( $prefill_terms ) ) : ?>
								<?php echo htax_render_term_checklist( $prefill_terms, $prefill_hierarchical, $prev_term_ids ); ?>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Select a source taxonomy to load terms.', 'hypertaxonomy' ); ?></p>
							<?php endif; ?>
						</div>
					</td>
				</tr>
			</table>

			<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Step 2 — Convert to', 'hypertaxonomy' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="htax_to_taxonomy"><?php esc_html_e( 'To taxonomy', 'hypertaxonomy' ); ?></label>
					</th>
					<td>
						<select name="htax_to_taxonomy" id="htax_to_taxonomy">
							<option value=""><?php esc_html_e( '— Select taxonomy —', 'hypertaxonomy' ); ?></option>
							<?php foreach ( $tax_options as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"
									<?php selected( $prev_to, $slug ); ?>
									<?php echo ( $slug === $prev_from ) ? 'disabled' : ''; ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect stubs', 'hypertaxonomy' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="htax_create_redirect" value="1"
								<?php checked( $redirect_checked ); ?> />
							<?php esc_html_e( 'Create 301 redirect stubs in the old taxonomy', 'hypertaxonomy' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Keeps old archive URLs working by leaving a redirect stub that points browsers and search engines to the new taxonomy archive. Stubs can be removed later from the term edit screen.', 'hypertaxonomy' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="htax_do_convert" class="button button-primary">
					<?php esc_html_e( 'Convert Selected Terms', 'hypertaxonomy' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Helper: render the term checklist (used server-side for prefill on POST)
// ---------------------------------------------------------------------------

/**
 * Build the HTML for a term checklist.
 *
 * Returns a safe HTML string ready to echo inside the form. Hierarchical
 * taxonomies render as nested lists; flat taxonomies render as a single list.
 *
 * @since 1.0
 *
 * @param WP_Term[] $terms        Terms to list.
 * @param bool      $hierarchical Whether to render as a nested hierarchy.
 * @param int[]     $checked_ids  Term IDs that should be pre-checked.
 * @return string HTML.
 */
function htax_render_term_checklist( array $terms, bool $hierarchical, array $checked_ids = array() ): string {
	if ( empty( $terms ) ) {
		return '<p class="description">' . esc_html__( 'No terms found in this taxonomy.', 'hypertaxonomy' ) . '</p>';
	}

	$html  = '<p class="description">';
	$html .= '<a href="#" class="htax-select-all-terms">' . esc_html__( 'Select all', 'hypertaxonomy' ) . '</a>';
	$html .= ' &nbsp;|&nbsp; ';
	$html .= '<a href="#" class="htax-deselect-all-terms">' . esc_html__( 'Deselect all', 'hypertaxonomy' ) . '</a>';
	$html .= '</p>';
	$html .= '<ul class="htax-term-checklist" style="margin:0;max-height:320px;overflow-y:auto;border:1px solid #ddd;padding:8px 8px 8px 16px;background:#fff;">';

	if ( $hierarchical ) {
		$html .= htax_render_term_checklist_level( $terms, 0, $checked_ids );
	} else {
		foreach ( $terms as $term ) {
			$html .= htax_render_term_checklist_item( $term, $checked_ids );
		}
	}

	$html .= '</ul>';
	return $html;
}

/**
 * Recursively render one level of a hierarchical term checklist.
 *
 * @since 1.0
 *
 * @param WP_Term[] $terms       All terms (flat array).
 * @param int       $parent_id   Parent term ID whose children to render.
 * @param int[]     $checked_ids Pre-checked term IDs.
 * @return string HTML fragment.
 */
function htax_render_term_checklist_level( array $terms, int $parent_id, array $checked_ids ): string {
	$html = '';
	foreach ( $terms as $term ) {
		if ( (int) $term->parent !== $parent_id ) {
			continue;
		}
		$html    .= '<li>';
		$html    .= htax_render_term_checklist_item( $term, $checked_ids );
		$children = htax_render_term_checklist_level( $terms, $term->term_id, $checked_ids );
		if ( $children ) {
			$html .= '<ul style="margin:0 0 0 20px;">' . $children . '</ul>';
		}
		$html .= '</li>';
	}
	return $html;
}

/**
 * Render a single checkbox item.
 *
 * @since 1.0
 *
 * @param WP_Term $term        Term to render.
 * @param int[]   $checked_ids Pre-checked term IDs.
 * @return string HTML.
 */
function htax_render_term_checklist_item( WP_Term $term, array $checked_ids ): string {
	$checked = in_array( $term->term_id, $checked_ids, true ) ? ' checked' : '';
	$count   = (int) $term->count;
	$label   = 1 === $count
		? esc_html__( '1 post', 'hypertaxonomy' )
		: sprintf(
			/* translators: %d: post count */
			esc_html( _n( '%d post', '%d posts', $count, 'hypertaxonomy' ) ),
			$count
		);

	return '<li><label>'
		. '<input type="checkbox" name="htax_convert_term_ids[]" value="'
		. esc_attr( (string) $term->term_id ) . '"' . $checked . ' /> '
		. esc_html( $term->name )
		. ' <span class="description">(' . $label . ')</span>'
		. '</label></li>';
}
