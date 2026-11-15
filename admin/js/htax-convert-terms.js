/**
 * Convert Terms admin page JS.
 *
 * Loads the term checklist via AJAX when the source taxonomy changes, keeps
 * the target taxonomy select in sync (disabling the selected source option),
 * and validates the form before submission.
 */
/* global htaxConvertTerms, jQuery */
jQuery( document ).ready( function ( $ ) {

	var $form     = $( '#htax-convert-terms-form' );
	var $fromTax  = $( '#htax_from_taxonomy' );
	var $toTax    = $( '#htax_to_taxonomy' );
	var $container = $( '#htax-term-list-container' );

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// -------------------------------------------------------------------------
	// Term list rendering
	// -------------------------------------------------------------------------

	function renderTermItem( term ) {
		return '<li><label>'
			+ '<input type="checkbox" name="htax_convert_term_ids[]" value="' + term.id + '" /> '
			+ escHtml( term.name )
			+ ' <span class="description">(' + escHtml( term.count_label ) + ')</span>'
			+ '</label></li>';
	}

	function renderLevel( terms, parentId ) {
		var html = '';
		$.each( terms, function ( _, term ) {
			if ( term.parent !== parentId ) {
				return;
			}
			html += '<li>';
			html += renderTermItem( term );
			var children = renderLevel( terms, term.id );
			if ( children ) {
				html += '<ul style="margin:0 0 0 20px;">' + children + '</ul>';
			}
			html += '</li>';
		} );
		return html;
	}

	function renderChecklist( terms, hierarchical ) {
		if ( ! terms.length ) {
			return '<p class="description">' + escHtml( htaxConvertTerms.i18n.noTerms ) + '</p>';
		}

		var inner = hierarchical ? renderLevel( terms, 0 ) : '';
		if ( ! hierarchical ) {
			$.each( terms, function ( _, term ) {
				inner += renderTermItem( term );
			} );
		}

		return '<p class="description">'
			+ '<a href="#" class="htax-select-all-terms">' + escHtml( htaxConvertTerms.i18n.selectAll ) + '</a>'
			+ ' &nbsp;|&nbsp; '
			+ '<a href="#" class="htax-deselect-all-terms">' + escHtml( htaxConvertTerms.i18n.deselectAll ) + '</a>'
			+ '</p>'
			+ '<ul class="htax-term-checklist" style="margin:0;max-height:320px;overflow-y:auto;border:1px solid #ddd;padding:8px 8px 8px 16px;background:#fff;">'
			+ inner
			+ '</ul>';
	}

	// -------------------------------------------------------------------------
	// Load terms via AJAX when source taxonomy changes
	// -------------------------------------------------------------------------

	function loadTerms( taxonomy ) {
		if ( ! taxonomy ) {
			$container.html( '<p class="description">' + escHtml( htaxConvertTerms.i18n.selectFrom ) + '</p>' );
			return;
		}

		$container.html( '<p class="description">' + escHtml( htaxConvertTerms.i18n.loading ) + '</p>' );

		$.post(
			htaxConvertTerms.ajaxUrl,
			{
				action:   'htax_get_terms_for_conversion',
				nonce:    htaxConvertTerms.nonce,
				taxonomy: taxonomy
			},
			function ( response ) {
				if ( ! response.success ) {
					$container.html( '<p class="description">' + escHtml( htaxConvertTerms.i18n.noTerms ) + '</p>' );
					return;
				}
				$container.html( renderChecklist( response.data.terms, response.data.hierarchical ) );
			}
		).fail( function () {
			$container.html( '<p class="description">' + escHtml( htaxConvertTerms.i18n.noTerms ) + '</p>' );
		} );
	}

	$fromTax.on( 'change', function () {
		var selected = $( this ).val();

		// Keep the target select in sync: disable the option that is now the source.
		$toTax.find( 'option' ).prop( 'disabled', false );
		if ( selected ) {
			$toTax.find( 'option[value="' + selected + '"]' ).prop( 'disabled', true );
			if ( $toTax.val() === selected ) {
				$toTax.val( '' );
			}
		}

		loadTerms( selected );
	} );

	// -------------------------------------------------------------------------
	// Select / Deselect all
	// -------------------------------------------------------------------------

	$container.on( 'click', '.htax-select-all-terms', function ( e ) {
		e.preventDefault();
		$container.find( 'input[type="checkbox"]' ).prop( 'checked', true );
	} );

	$container.on( 'click', '.htax-deselect-all-terms', function ( e ) {
		e.preventDefault();
		$container.find( 'input[type="checkbox"]' ).prop( 'checked', false );
	} );

	// -------------------------------------------------------------------------
	// Form validation
	// -------------------------------------------------------------------------

	$form.on( 'submit', function ( e ) {
		var from    = $fromTax.val();
		var to      = $toTax.val();
		var checked = $container.find( 'input[type="checkbox"]:checked' ).length;

		if ( ! from ) {
			e.preventDefault();
			window.alert( htaxConvertTerms.i18n.selectFrom );
			$fromTax.focus();
			return;
		}
		if ( ! checked ) {
			e.preventDefault();
			window.alert( htaxConvertTerms.i18n.selectSome );
			return;
		}
		if ( ! to ) {
			e.preventDefault();
			window.alert( htaxConvertTerms.i18n.selectDest );
			$toTax.focus();
			return;
		}
		if ( from === to ) {
			e.preventDefault();
			window.alert( htaxConvertTerms.i18n.sameTax );
			return;
		}
	} );

} );
