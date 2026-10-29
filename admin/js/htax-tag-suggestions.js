/**
 * Content-based tag suggestions panels for the Gutenberg editor.
 *
 * For every flat taxonomy that HyperTaxonomy has enabled and that is visible
 * in the block editor, registers a PluginDocumentSettingPanel with content-
 * based suggestions. htax-post-edit.js repositions each panel immediately
 * after its corresponding taxonomy section in the sidebar.
 *
 * Also suppresses the native "most used" section for each covered taxonomy by
 * intercepting the orderby=count request and returning an empty array.
 */
( function () {
	'use strict';

	var settings = window.htaxTagSuggestions || {};
	if ( ! settings.restUrl ) { return; }
	if ( ! settings.taxonomies || ! settings.taxonomies.length ) { return; }
	if ( ! window.wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.apiFetch ) { return; }

	var PluginDocumentSettingPanel =
		( wp.editor   && wp.editor.PluginDocumentSettingPanel   ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! PluginDocumentSettingPanel ) { return; }

	var registerPlugin = wp.plugins.registerPlugin;
	var Fragment       = wp.element.Fragment;
	var el             = wp.element.createElement;
	var useState       = wp.element.useState;
	var useEffect      = wp.element.useEffect;
	var useSelect      = wp.data.useSelect;
	var useDispatch    = wp.data.useDispatch;

	// ── Suppress native "most used" for all covered flat taxonomies ──────────
	// Returning [] prevents Gutenberg from rendering the "Choose from the most
	// used …" toggle (it only renders when mostUsedTerms.length > 0).

	var suppressedBases = settings.taxonomies.map( function ( t ) {
		return '/wp/v2/' + t.restBase;
	} );

	wp.apiFetch.use( function ( options, next ) {
		var pathOrUrl = ( typeof options.path === 'string' ) ? options.path
		              : ( typeof options.url  === 'string' ) ? options.url
		              : '';
		var isMostUsed = suppressedBases.some( function ( base ) {
			return pathOrUrl.indexOf( base ) !== -1;
		} ) && pathOrUrl.indexOf( 'orderby=count' ) !== -1;

		return isMostUsed ? Promise.resolve( [] ) : next( options );
	} );

	// ── Shared suggestion list component ─────────────────────────────────────

	function TagSuggestionsList( props ) {
		var tax = props.tax;

		var sState       = useState( [] );
		var suggestions  = sState[ 0 ];
		var setSuggestions = sState[ 1 ];

		var lState     = useState( true );
		var loading    = lState[ 0 ];
		var setLoading = lState[ 1 ];

		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		} );

		// Current terms are stored under the taxonomy's REST base as the attribute key.
		var currentTerms = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( tax.restBase ) || [];
		} );

		var editorDispatch = useDispatch( 'core/editor' );

		useEffect( function () {
			if ( ! postId ) { setLoading( false ); return; }
			setLoading( true );
			wp.apiFetch( {
				url:     settings.restUrl + '?post_id=' + postId
				         + '&taxonomy=' + tax.slug
				         + '&per_page=10',
				headers: { 'X-WP-Nonce': settings.nonce },
			} ).then( function ( data ) {
				setSuggestions( Array.isArray( data ) ? data : [] );
				setLoading( false );
			} ).catch( function () {
				setSuggestions( [] );
				setLoading( false );
			} );
		}, [ postId, tax.slug ] );

		if ( loading ) {
			return el( 'p', { style: { margin: 0, fontSize: '12px', color: '#757575' } }, '…' );
		}

		var available = suggestions.filter( function ( term ) {
			return currentTerms.indexOf( term.id ) === -1;
		} );

		if ( ! available.length ) {
			return el( 'p', { style: { margin: 0, fontSize: '12px', color: '#757575' } },
				'No content-based suggestions.'
			);
		}

		return el( 'div', { className: 'htax-tag-suggestion-list' },
			available.map( function ( term ) {
				return el( 'button', {
					key:       term.id,
					className: 'components-button is-link',
					style:     { display: 'inline-block', margin: '0 8px 6px 0', fontSize: '13px' },
					onClick:   function () {
						var updated = {};
						updated[ tax.restBase ] = currentTerms.concat( [ term.id ] );
						editorDispatch.editPost( updated );
					},
				}, term.name );
			} )
		);
	}

	// ── Register one panel per flat taxonomy ─────────────────────────────────

	registerPlugin( 'htax-tag-suggestions', {
		render: function () {
			return el( Fragment, null,
				settings.taxonomies.map( function ( tax ) {
					return el( PluginDocumentSettingPanel, {
						key:       tax.slug,
						name:      'htax-tag-suggestions-panel-' + tax.slug,
						title:     'Suggested ' + tax.label,
						className: 'htax-tag-suggestions-panel htax-tag-suggestions-panel-' + tax.slug,
					}, el( TagSuggestionsList, { tax: tax } ) );
				} )
			);
		},
	} );
} )();
