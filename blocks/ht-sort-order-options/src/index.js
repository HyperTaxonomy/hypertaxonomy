import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	Disabled,
	PanelBody,
	CheckboxControl,
	SelectControl,
	TextControl,
	Notice,
} from '@wordpress/components';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { SortOrderBuilder } from '../../shared/components';
import '../../shared/editor.css';
// The front-end picker styles, so the editor shows the control as visitors see it.
import '../../../assets/css/htax-sort-order.css';
import metadata from '../block.json';

// Fields available in this block.
const SORT_FIELDS = [
	{ value: 'date',          label: __( 'Date',            'hypertaxonomy' ) },
	{ value: 'modified',      label: __( 'Modified',        'hypertaxonomy' ) },
	{ value: 'title',         label: __( 'Title',           'hypertaxonomy' ) },
	{ value: 'author',        label: __( 'Author',          'hypertaxonomy' ) },
	{ value: 'comment_count', label: __( 'Comments',        'hypertaxonomy' ) },
	{ value: 'last_comment',  label: __( 'Latest Comment',  'hypertaxonomy' ) },
	{ value: 'last_activity', label: __( 'Latest Activity', 'hypertaxonomy' ) },
	{ value: 'relevance',     label: __( 'Relevance',       'hypertaxonomy' ) },
	{ value: 'ID',            label: __( 'ID',              'hypertaxonomy' ) },
];

const FIELD_LABELS = Object.fromEntries( SORT_FIELDS.map( ( f ) => [ f.value, f.label ] ) );

/**
 * Static copy of the front-end picker (same markup and classes as
 * htax_render_sort_order_picker() and the pills htax-sort-order.js adds), so the editor
 * shows the control as visitors see it. The default sort appears as pills; the picker
 * of offered fields is shown open, as after clicking "+".
 */
function SortPickerPreview( { fields, state, sortDefault, textCollapsed, textExpanded } ) {
	const collapsed = 'collapsed' === state;
	const pills = ( sortDefault || '' )
		.split( ',' )
		.map( ( part ) => part.trim().split( ':' ) )
		.filter( ( [ field ] ) => FIELD_LABELS[ field ] )
		.map( ( [ field, dir ] ) => ( { field, dir: 'asc' === dir ? 'asc' : 'desc' } ) );
	const inUse = pills.map( ( p ) => p.field );
	const offered = fields.filter( ( f ) => FIELD_LABELS[ f ] && ! inUse.includes( f ) );
	const toggleText = ( collapsed ? textCollapsed : textExpanded ) || __( 'Sort', 'hypertaxonomy' );

	return (
		<div className="htax-sort-order-root" data-state={ collapsed ? 'collapsed' : 'expanded' }>
			<button type="button" className="htax-sort-toggle">
				<span className="htax-sort-toggle-text">{ toggleText }</span>
			</button>
			<div className="htax-sort-order-container">
				{ pills.map( ( { field, dir } ) => (
					<div className="htax-sort-order-pill" key={ field }>
						<span className="htax-sort-drag-handle" aria-hidden="true">&#8942;</span>
						<span className="htax-sort-label">{ FIELD_LABELS[ field ] }</span>
						<span className="htax-sort-dir" aria-hidden="true">
							{ 'asc' === dir ? '↑' : '↓' }
						</span>
						<button type="button" className="htax-remove">
							<span aria-hidden="true">&times;</span>
						</button>
					</div>
				) ) }
				{ offered.length > 0 && (
					<button type="button" className="htax-sort-order-add">+</button>
				) }
			</div>
			{ offered.length > 0 && (
				<div className="htax-sort-order-picker">
					{ offered.map( ( field ) => (
						<button type="button" className="htax-sort-picker-pill" key={ field }>
							{ FIELD_LABELS[ field ] }
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}

function Edit( { attributes, setAttributes } ) {
	const {
		fields,
		state,
		sort_default,
		text_collapsed,
		text_expanded,
		class: cssClass,
	} = attributes;

	const blockProps = useBlockProps();

	const fieldArray = fields
		? fields.split( ',' ).map( ( f ) => f.trim() ).filter( Boolean )
		: [];

	// Detect whether we are editing a template that will actually render this block.
	// Valid contexts: taxonomy archive, category, tag, search results, blog home.
	// Outside FSE (core/edit-site store absent) we are in a post/page editor — not valid.
	const isValidContext = useSelect( ( select ) => {
		const editSite = select( 'core/edit-site' );
		if ( ! editSite ) {
			return false;
		}
		const templateId = editSite.getEditedPostId?.() ?? '';
		return /\/(archive|category|tag|taxonomy|search|home)/.test( templateId );
	} );

	// Toggle a field on/off, preserving the canonical display order.
	const toggleField = ( fieldValue, checked ) => {
		const order = SORT_FIELDS.map( ( f ) => f.value );
		let updated = fieldArray.filter( ( f ) => order.includes( f ) );
		if ( checked && ! updated.includes( fieldValue ) ) {
			updated = order.filter( ( v ) => updated.includes( v ) || v === fieldValue );
		} else if ( ! checked ) {
			updated = updated.filter( ( f ) => f !== fieldValue );
		}
		setAttributes( { fields: updated.join( ',' ) } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Sort Fields', 'hypertaxonomy' ) }>
					{ SORT_FIELDS.map( ( { value, label } ) => (
						<CheckboxControl
							key={ value }
							label={ label }
							checked={ fieldArray.includes( value ) }
							onChange={ ( checked ) => toggleField( value, checked ) }
							__nextHasNoMarginBottom
						/>
					) ) }
				</PanelBody>
				<PanelBody title={ __( 'Display', 'hypertaxonomy' ) } initialOpen={ false }>
					<SelectControl
						label={ __( 'Initial state', 'hypertaxonomy' ) }
						value={ state }
						options={ [
							{ label: __( 'Expanded', 'hypertaxonomy' ), value: 'expanded' },
							{ label: __( 'Collapsed', 'hypertaxonomy' ), value: 'collapsed' },
						] }
						onChange={ ( val ) => setAttributes( { state: val } ) }
						__nextHasNoMarginBottom
					/>
					<p className="components-base-control__label">
						{ __( 'Default sort', 'hypertaxonomy' ) }
					</p>
					<SortOrderBuilder
						value={ sort_default }
						onChange={ ( val ) => setAttributes( { sort_default: val } ) }
						fields={ fieldArray.length
							? SORT_FIELDS.filter( ( f ) => fieldArray.includes( f.value ) )
							: SORT_FIELDS
						}
					/>
					<TextControl
						label={ __( 'Button label (collapsed)', 'hypertaxonomy' ) }
						value={ text_collapsed }
						placeholder={ __( 'Sort', 'hypertaxonomy' ) }
						onChange={ ( val ) => setAttributes( { text_collapsed: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Button label (expanded)', 'hypertaxonomy' ) }
						value={ text_expanded }
						placeholder={ __( 'Sort', 'hypertaxonomy' ) }
						onChange={ ( val ) => setAttributes( { text_expanded: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Additional CSS class', 'hypertaxonomy' ) }
						value={ cssClass }
						onChange={ ( val ) => setAttributes( { class: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ ! isValidContext && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'Renders on taxonomy archive and search results pages only.', 'hypertaxonomy' ) }
					</Notice>
				) }
				{ fieldArray.length ? (
					<Disabled>
						<SortPickerPreview
							fields={ fieldArray }
							state={ state }
							sortDefault={ sort_default }
							textCollapsed={ text_collapsed }
							textExpanded={ text_expanded }
						/>
					</Disabled>
				) : (
					<p>{ __( '(no fields selected)', 'hypertaxonomy' ) }</p>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
