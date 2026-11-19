/**
 * Check step of the Merge Terms and Convert Terms pages.
 *
 * Before the form is submitted, sends it to the page's check (htaxOperationCheck.action),
 * where other plugins can say what the operation will do to their data. If none does, the
 * form is submitted as is. If one does, its notices and choices are shown, with the redirect
 * stub setting from the form, which can still be changed here: choices that need a stub, or
 * no stub, follow it.
 *
 * Bound when the document is ready, after the page's own form script, so that script's
 * checks run first: a submission it cancels isn't checked.
 */
/* global htaxOperationCheck, jQuery */
jQuery( function () {
	'use strict';

	var __     = wp.i18n.__;
	var config = window.htaxOperationCheck;
	var step2  = document.getElementById( 'htax-check-step2' );
	var form   = step2 ? step2.closest( 'form' ) : null;
	if ( ! form || ! config ) {
		return;
	}

	var step1       = document.getElementById( 'htax-check-step1' );
	var step1Submit = document.getElementById( 'htax-check-step1-submit' );
	var stub1       = document.getElementById( 'htax_create_redirect' );
	var stub2       = document.getElementById( 'htax-check-stub' );
	var sectionsEl  = document.getElementById( 'htax-check-sections' );
	var checkingEl  = form.querySelector( '.htax-check-checking' );
	var slug        = /^[a-z0-9_-]+$/;
	var confirmed   = false;
	var busy        = false;
	var fields      = []; // { inputs: [ { input, choice, row, note } ], def, defNoStub }

	function submitNow() {
		confirmed = true;
		// form.submit() leaves out the clicked button, whose name the page's handler checks.
		if ( ! form.querySelector( 'input[type="hidden"][name="' + config.submitName + '"]' ) ) {
			var flag   = document.createElement( 'input' );
			flag.type  = 'hidden';
			flag.name  = config.submitName;
			flag.value = '1';
			form.appendChild( flag );
		}
		HTMLFormElement.prototype.submit.call( form );
	}

	function element( tag, className, text ) {
		var el = document.createElement( tag );
		if ( className ) {
			el.className = className;
		}
		if ( text ) {
			el.textContent = String( text );
		}
		return el;
	}

	function isAvailable( choice, withStub ) {
		return withStub ? 'none' !== choice.stub : 'required' !== choice.stub;
	}

	// Enable the choices that fit the stub setting; replace a selection that no longer fits.
	function applyStub() {
		var withStub = stub2.checked;
		if ( stub1 ) {
			stub1.checked = withStub;
		}
		fields.forEach( function ( field ) {
			var selected = null;
			field.inputs.forEach( function ( item ) {
				var available = isAvailable( item.choice, withStub );
				item.input.disabled = ! available;
				item.row.classList.toggle( 'is-unavailable', ! available );
				item.note.textContent = available ? '' : ( 'required' === item.choice.stub
					? __( 'Needs a redirect stub.', 'hypertaxonomy' )
					: __( 'Not available with a redirect stub.', 'hypertaxonomy' ) );
				if ( item.input.checked && available ) {
					selected = item;
				}
			} );
			if ( selected ) {
				return;
			}
			// The default for this stub setting, or else the first choice available.
			var wanted    = withStub ? field.def : field.defNoStub;
			var available = field.inputs.filter( function ( item ) {
				return ! item.input.disabled;
			} );
			var pick = available.filter( function ( item ) {
				return item.choice.value === wanted;
			} )[ 0 ] || available[ 0 ];
			field.inputs.forEach( function ( item ) {
				item.input.checked = item === pick;
			} );
		} );
	}

	function renderField( section, field ) {
		if ( ! slug.test( String( field.name || '' ) ) || ! Array.isArray( field.choices ) ) {
			return;
		}
		var fieldset = element( 'fieldset', 'htax-check-field' );
		fieldset.appendChild( element( 'legend', '', field.label ) );
		var entry = { inputs: [], def: field.default, defNoStub: field.default_no_stub };
		field.choices.forEach( function ( choice ) {
			if ( ! slug.test( String( choice.value || '' ) ) ) {
				return;
			}
			var row   = element( 'label', 'htax-check-choice' );
			var input = document.createElement( 'input' );
			input.type  = 'radio';
			input.name  = 'htax_operation_options[' + section.id + '][' + field.name + ']';
			input.value = choice.value;
			row.appendChild( input );
			row.appendChild( document.createTextNode( ' ' ) );
			row.appendChild( element( 'span', 'htax-check-choice-label', choice.label ) );
			if ( choice.description ) {
				row.appendChild( element( 'span', 'description htax-check-choice-description', choice.description ) );
			}
			var note = element( 'span', 'description htax-check-choice-note' );
			row.appendChild( note );
			fieldset.appendChild( row );
			entry.inputs.push( { input: input, choice: choice, row: row, note: note } );
		} );
		if ( entry.inputs.length ) {
			fields.push( entry );
			sectionsEl.appendChild( fieldset );
		}
	}

	function showStep2( sections ) {
		sectionsEl.textContent = '';
		fields = [];
		sections.forEach( function ( section ) {
			if ( ! section || ! slug.test( String( section.id || '' ) ) ) {
				return;
			}
			if ( section.title ) {
				sectionsEl.appendChild( element( 'h3', '', section.title ) );
			}
			( Array.isArray( section.notices ) ? section.notices : [] ).forEach( function ( notice ) {
				var box = element( 'div', 'notice inline ' + ( 'warning' === notice.type ? 'notice-warning' : 'notice-info' ) );
				box.appendChild( element( 'p', '', notice.text ) );
				sectionsEl.appendChild( box );
			} );
			( Array.isArray( section.fields ) ? section.fields : [] ).forEach( function ( field ) {
				renderField( section, field );
			} );
		} );
		stub2.checked = stub1 ? stub1.checked : false;
		applyStub();
		step1.hidden       = true;
		step1Submit.hidden = true;
		step2.hidden       = false;
		step2.scrollIntoView( { block: 'start' } );
	}

	stub2.addEventListener( 'change', applyStub );

	document.getElementById( 'htax-check-back' ).addEventListener( 'click', function () {
		step2.hidden       = true;
		step1.hidden       = false;
		step1Submit.hidden = false;
		sectionsEl.textContent = ''; // Choices are asked for again after a change.
		fields = [];
	} );

	form.addEventListener( 'submit', function ( event ) {
		if ( confirmed || event.defaultPrevented ) {
			return; // Submitting for real, or cancelled by the page's own checks.
		}
		event.preventDefault();
		if ( ! step2.hidden ) {
			submitNow(); // The button on the check step.
			return;
		}
		if ( busy ) {
			return;
		}

		busy = true;
		checkingEl.textContent = __( 'Checking…', 'hypertaxonomy' );

		var body = new FormData( form );
		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				busy = false;
				checkingEl.textContent = '';
				var sections = response && response.success && response.data && Array.isArray( response.data.sections ) ? response.data.sections : [];
				if ( sections.length ) {
					showStep2( sections );
				} else {
					submitNow();
				}
			} )
			.catch( function () {
				// Without the check, the operation still runs: plugins apply their defaults.
				busy = false;
				checkingEl.textContent = '';
				submitNow();
			} );
	} );
} );
