/**
 * Shared reusable block editor components.
 *
 * @package HyperTaxonomy
 */

import { useState, useMemo, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { Button, FormTokenField, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

const toArray = ( str ) =>
	str ? str.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) : [];

/**
 * Merge two arrays of WP term objects by id, later entries winning.
 *
 * @param {Array} a
 * @param {Array} b
 * @return {Array}
 */
function mergeById( a, b ) {
	const map = new Map();
	[ ...a, ...b ].forEach( ( t ) => map.set( t.id, t ) );
	return [ ...map.values() ];
}

// ---------------------------------------------------------------------------
// TermTokenField
// ---------------------------------------------------------------------------

/**
 * FormTokenField with search-as-you-type for a single WP taxonomy.
 *
 * Stores a comma-separated list of term IDs as a string; displays term names
 * as tokens. Preloads up to 100 terms for quick browsing and augments with
 * live search results (≥ 2 characters) via the WordPress REST API.
 *
 * @param {Object}   props
 * @param {string}   props.taxonomy  Taxonomy slug to search.
 * @param {string}   props.value     Comma-separated term IDs (attribute value).
 * @param {Function} props.onChange  Called with a new comma-separated ID string.
 * @param {string}   props.label     FormTokenField label.
 * @param {string}   [props.help]    Optional help text.
 */
export function TermTokenField( { taxonomy, value, onChange, label, help } ) {
	const [ input, setInput ] = useState( '' );

	// Stable array of currently stored IDs — used as a dep for the targeted fetch.
	const storedIds = useMemo( () => toArray( value ), [ value ] );

	const { preloaded, searched, targeted } = useSelect(
		( select ) => {
			const store = select( coreStore );
			return {
				preloaded: taxonomy
					? store.getEntityRecords( 'taxonomy', taxonomy, {
							per_page: 100,
							hide_empty: false,
					  } ) ?? []
					: [],
				searched:
					input.length >= 2 && taxonomy
						? store.getEntityRecords( 'taxonomy', taxonomy, {
								search: input,
								per_page: 20,
								hide_empty: false,
						  } ) ?? []
						: [],
				// Targeted fetch for stored IDs: null = still loading, [] = nothing to fetch.
				// Lets us distinguish "term exists but not in first 100" from "term deleted".
				targeted: storedIds.length && taxonomy
					? store.getEntityRecords( 'taxonomy', taxonomy, {
							include: storedIds,
							per_page: storedIds.length,
							hide_empty: false,
					  } )
					: [],
			};
		},
		[ taxonomy, input, storedIds ]
	);

	// Merge all three sources so byId/byName cover preloaded + targeted + search results.
	const known = useMemo(
		() => mergeById( mergeById( preloaded, targeted ?? [] ), searched ),
		[ preloaded, searched, targeted ]
	);

	const byName = useMemo(
		() => Object.fromEntries( known.map( ( t ) => [ t.name, t.id ] ) ),
		[ known ]
	);
	const byId = useMemo(
		() => Object.fromEntries( known.map( ( t ) => [ String( t.id ), t.name ] ) ),
		[ known ]
	);

	// Drop deleted term IDs once the targeted fetch resolves.
	// Idempotent: after write-back storedIds === valid, so onChange is not called again.
	useEffect( () => {
		if ( targeted === null ) return;
		const existingIds = new Set( targeted.map( ( t ) => String( t.id ) ) );
		const valid = storedIds.filter( ( id ) => existingIds.has( id ) );
		if ( valid.length < storedIds.length ) {
			onChange( valid.join( ',' ) );
		}
	}, [ targeted, storedIds, onChange ] );

	// While targeted is loading (null), show raw IDs as fallback to avoid empty flash.
	// Once resolved, silently drop IDs that don't exist.
	const tokenValues = targeted === null
		? toArray( value ).map( ( id ) => byId[ id ] ?? id )
		: toArray( value ).filter( ( id ) => byId[ id ] !== undefined ).map( ( id ) => byId[ id ] );

	const suggestions = known.map( ( t ) => t.name );

	return (
		<FormTokenField
			label={ label }
			help={ help }
			value={ tokenValues }
			suggestions={ suggestions }
			onInputChange={ setInput }
			onChange={ ( names ) => {
				const ids = names
					.map( ( name ) =>
						byName[ name ] !== undefined ? String( byName[ name ] ) : null
					)
					.filter( Boolean )
					.join( ',' );
				onChange( ids );
			} }
		/>
	);
}

// ---------------------------------------------------------------------------
// SortOrderBuilder
// ---------------------------------------------------------------------------

export const SORT_FIELDS = [
	{ label: __( 'Date published', 'hypertaxonomy' ),  value: 'date' },
	{ label: __( 'Date modified', 'hypertaxonomy' ),   value: 'modified' },
	{ label: __( 'Title', 'hypertaxonomy' ),           value: 'title' },
	{ label: __( 'Author', 'hypertaxonomy' ),          value: 'author' },
	{ label: __( 'Comment count', 'hypertaxonomy' ),   value: 'comment_count' },
	{ label: __( 'Latest comment', 'hypertaxonomy' ),  value: 'last_comment' },
	{ label: __( 'Latest activity', 'hypertaxonomy' ), value: 'last_activity' },
	{ label: __( 'ID', 'hypertaxonomy' ),              value: 'ID' },
	{ label: __( 'Relevance', 'hypertaxonomy' ),       value: 'relevance' },
];

export const SORT_FIELD_VALUES = SORT_FIELDS.map( ( f ) => f.value );

const parseOrderby = ( str ) =>
	( str ? str.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) : [] )
		.map( ( pair ) => {
			const [ field, dir = 'desc' ] = pair.split( ':' );
			return { field: field.trim(), dir: dir.trim() };
		} );

const serializeOrderby = ( rows ) =>
	rows.map( ( r ) => `${ r.field }:${ r.dir }` ).join( ',' );

/**
 * Composite sort order builder.
 *
 * Renders a list of field+direction rows. Each row has a field dropdown,
 * asc/desc toggle, move-up/down buttons, and a remove button. An "+ Add"
 * button appends a new row (up to the number of available fields).
 *
 * @param {Object}   props
 * @param {string}   props.value     Serialized sort spec, e.g. "date:desc,title:asc".
 * @param {Function} props.onChange  Called with the updated serialized string.
 * @param {Array}    [props.fields]  Array of { label, value } options. Defaults to SORT_FIELDS.
 */
export function SortOrderBuilder( { value, onChange, fields = SORT_FIELDS } ) {
	const rows = parseOrderby( value );

	const update = ( index, field, dir ) =>
		onChange( serializeOrderby( rows.map( ( r, i ) => ( i === index ? { field, dir } : r ) ) ) );

	const remove = ( index ) =>
		onChange( serializeOrderby( rows.filter( ( _, i ) => i !== index ) ) );

	const move = ( index, delta ) => {
		const next = [ ...rows ];
		const swap = index + delta;
		if ( swap < 0 || swap >= next.length ) return;
		[ next[ index ], next[ swap ] ] = [ next[ swap ], next[ index ] ];
		onChange( serializeOrderby( next ) );
	};

	const add = () => {
		const used = new Set( rows.map( ( r ) => r.field ) );
		const next = fields.find( ( f ) => ! used.has( f.value ) )?.value ?? fields[ 0 ].value;
		onChange( serializeOrderby( [ ...rows, { field: next, dir: 'desc' } ] ) );
	};

	return (
		<div className="htax-sort-order-builder">
			{ rows.map( ( row, i ) => (
				<div key={ i } className="htax-sort-row">
					<SelectControl
						value={ row.field }
						options={ fields }
						onChange={ ( f ) => update( i, f, row.dir ) }
						__nextHasNoMarginBottom
					/>
					<Button
						size="small"
						variant="secondary"
						title={
							'asc' === row.dir
								? __( 'Ascending — click to toggle', 'hypertaxonomy' )
								: __( 'Descending — click to toggle', 'hypertaxonomy' )
						}
						onClick={ () => update( i, row.field, 'asc' === row.dir ? 'desc' : 'asc' ) }
					>
						{ 'asc' === row.dir ? '▲' : '▼' }
					</Button>
					<Button
						size="small"
						icon="arrow-up-alt2"
						label={ __( 'Move up', 'hypertaxonomy' ) }
						disabled={ 0 === i }
						onClick={ () => move( i, -1 ) }
					/>
					<Button
						size="small"
						icon="arrow-down-alt2"
						label={ __( 'Move down', 'hypertaxonomy' ) }
						disabled={ i === rows.length - 1 }
						onClick={ () => move( i, 1 ) }
					/>
					<Button
						size="small"
						icon="no-alt"
						label={ __( 'Remove', 'hypertaxonomy' ) }
						isDestructive
						onClick={ () => remove( i ) }
					/>
				</div>
			) ) }
			<Button
				variant="secondary"
				size="small"
				onClick={ add }
				disabled={ rows.length >= fields.length }
			>
				{ __( '+ Add sort field', 'hypertaxonomy' ) }
			</Button>
		</div>
	);
}

// ---------------------------------------------------------------------------
// GroupTokenField
// ---------------------------------------------------------------------------

/**
 * FormTokenField with search-as-you-type for htax_term_group.
 *
 * Stores a comma-separated list of group IDs as a string; displays group names
 * as tokens. Preloads all groups (typically a small set) and augments with
 * live search results (≥ 2 characters) via the WordPress REST API.
 *
 * @param {Object}   props
 * @param {string}   props.value     Comma-separated group IDs (attribute value).
 * @param {Function} props.onChange  Called with a new comma-separated ID string.
 * @param {string}   props.label     FormTokenField label.
 * @param {string}   [props.help]    Optional help text.
 */
export function GroupTokenField( { value, onChange, label, help } ) {
	const [ input, setInput ] = useState( '' );

	const { preloaded, searched } = useSelect(
		( select ) => {
			const store = select( coreStore );
			return {
				// No ?? [] fallback: null = still loading, array = complete list.
				// Groups use per_page:-1 so an array means we have everything.
				preloaded:
					store.getEntityRecords( 'taxonomy', 'htax_term_group', {
						per_page: -1,
						hide_empty: false,
					} ),
				searched:
					input.length >= 2
						? store.getEntityRecords( 'taxonomy', 'htax_term_group', {
								search: input,
								per_page: 20,
								hide_empty: false,
						  } ) ?? []
						: [],
			};
		},
		[ input ]
	);

	const known = useMemo(
		() => mergeById( preloaded ?? [], searched ),
		[ preloaded, searched ]
	);

	const byName = useMemo(
		() => Object.fromEntries( known.map( ( g ) => [ g.name, g.id ] ) ),
		[ known ]
	);
	const byId = useMemo(
		() => Object.fromEntries( known.map( ( g ) => [ String( g.id ), g.name ] ) ),
		[ known ]
	);

	// Drop deleted group IDs once the complete list has loaded.
	// Idempotent: after write-back value contains only valid IDs, so onChange is not called again.
	useEffect( () => {
		if ( preloaded === null ) return;
		const ids = toArray( value );
		const valid = ids.filter( ( id ) => byId[ id ] !== undefined );
		if ( valid.length < ids.length ) {
			onChange( valid.join( ',' ) );
		}
	}, [ preloaded, value, byId, onChange ] );

	// While loading (preloaded===null), fall back to raw IDs so the field doesn't
	// flash empty. Once loaded, only show names for IDs that actually exist.
	const tokenValues = preloaded === null
		? toArray( value ).map( ( id ) => byId[ id ] ?? id )
		: toArray( value ).filter( ( id ) => byId[ id ] !== undefined ).map( ( id ) => byId[ id ] );

	const suggestions = known.map( ( g ) => g.name );

	return (
		<FormTokenField
			label={ label }
			help={ help }
			value={ tokenValues }
			suggestions={ suggestions }
			onInputChange={ setInput }
			onChange={ ( names ) => {
				const ids = names
					.map( ( name ) =>
						byName[ name ] !== undefined ? String( byName[ name ] ) : null
					)
					.filter( Boolean )
					.join( ',' );
				onChange( ids );
			} }
		/>
	);
}
