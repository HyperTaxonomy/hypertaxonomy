/**
 * Shared Edit component for htax-post-list and htax-related-posts blocks.
 *
 * Both blocks share identical inspector controls. The only difference is that
 * htax-related-posts derives its term set from the current post automatically,
 * so include_terms / exclude_terms / include_groups / exclude_groups are hidden
 * (they are ignored by the shortcode). Pass `{ showTermSourceFilters: false }`
 * to createEdit() for that variant.
 */

import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
	FormTokenField,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { TermTokenField, GroupTokenField, SortOrderBuilder, SORT_FIELD_VALUES } from './components';
import './editor.css';

// Known keyword filters (beyond arbitrary taxonomy slugs).
const KNOWN_FILTER_KEYWORDS = [
	'date_published',
	'date_modified',
	'author',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const toArray = ( str ) =>
	str ? str.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) : [];

// Boolean string toggle helpers.
const toBool   = ( str ) => 'true' === str;
const fromBool = ( b )   => ( b ? 'true' : 'false' );

// ---------------------------------------------------------------------------
// createEdit factory
// ---------------------------------------------------------------------------

/**
 * Returns an Edit component for a post-list variant block.
 *
 * @param {string}  blockName              Fully-qualified block name for ServerSideRender.
 * @param {Object}  [opts]                 Options.
 * @param {boolean} [opts.showTermSourceFilters=true]
 *   When false, the include/exclude term and group controls are hidden.
 *   Used by htax-related-posts because that block derives its term set from
 *   the current post automatically — those attributes are ignored.
 * @param {boolean} [opts.allowAllTaxonomies=false]
 *   When true, the empty taxonomy option means "all taxonomies the post has
 *   terms in" (relabeled accordingly). Used by htax-related-posts.
 */
export function createEdit( blockName, opts = {} ) {
	const { showTermSourceFilters = true, allowAllTaxonomies = false } = opts;

	return function Edit( { attributes, setAttributes } ) {
		const {
			taxonomy,
			include_terms,
			exclude_terms,
			include_groups,
			exclude_groups,
			filter_terms,
			filter_groups,
			filter_out_terms,
			filter_out_groups,
			filters,
			filter_options,
			post_type,
			posts_per_page,
			max_results,
			orderby,
			show_thumbnail,
			show_excerpt,
			meta,
			template,
			format,
			class: cssClass,
			no_results_message,
			sort_order_options,
			sort_order_align,
			filters_align,
			search,
			prepend,
			append,
			image_size,
			columns,
		} = attributes;

		const { taxonomies, postTypes } = useSelect(
			( select ) => {
				const store = select( coreStore );
				return {
					taxonomies:
						store.getTaxonomies( { per_page: -1, show_in_rest: true } ) ?? [],
					postTypes:
						store.getPostTypes( { per_page: -1, show_in_rest: true } ) ?? [],
				};
			},
			[]
		);

		const taxonomyOptions = [
			{
				label: allowAllTaxonomies
					? __( '— All taxonomies —', 'hypertaxonomy' )
					: __( '— Select taxonomy —', 'hypertaxonomy' ),
				value: '',
			},
			...taxonomies.map( ( t ) => ( { label: t.name, value: t.slug } ) ),
		];

		const postTypeOptions = postTypes.length
			? postTypes.map( ( pt ) => ( { label: pt.name, value: pt.slug } ) )
			: [ { label: post_type, value: post_type } ];

		// Suggestions for the inline filter panel keyword field.
		const filterKeywordSuggestions = [
			...KNOWN_FILTER_KEYWORDS,
			...taxonomies.map( ( t ) => t.slug ),
		];

		const blockProps = useBlockProps();

		return (
			<>
				<InspectorControls>

					{ /* ── Term Source ───────────────────────────────────── */ }
					<PanelBody
						title={ __( 'Term Source', 'hypertaxonomy' ) }
						initialOpen={ true }
					>
						<SelectControl
							label={ __( 'Taxonomy', 'hypertaxonomy' ) }
							value={ taxonomy }
							options={ taxonomyOptions }
							onChange={ ( val ) => setAttributes( { taxonomy: val } ) }
						/>
						{ showTermSourceFilters && (
							<>
								<TermTokenField
									label={ __( 'Include terms', 'hypertaxonomy' ) }
									taxonomy={ taxonomy }
									value={ include_terms }
									onChange={ ( val ) => setAttributes( { include_terms: val } ) }
								/>
								<TermTokenField
									label={ __( 'Exclude terms', 'hypertaxonomy' ) }
									taxonomy={ taxonomy }
									value={ exclude_terms }
									onChange={ ( val ) => setAttributes( { exclude_terms: val } ) }
								/>
								<GroupTokenField
									label={ __( 'Include groups', 'hypertaxonomy' ) }
									value={ include_groups }
									onChange={ ( val ) => setAttributes( { include_groups: val } ) }
								/>
								<GroupTokenField
									label={ __( 'Exclude groups', 'hypertaxonomy' ) }
									value={ exclude_groups }
									onChange={ ( val ) => setAttributes( { exclude_groups: val } ) }
								/>
							</>
						) }
					</PanelBody>

					{ /* ── Query ─────────────────────────────────────────── */ }
					<PanelBody
						title={ __( 'Query', 'hypertaxonomy' ) }
						initialOpen={ false }
					>
						<SelectControl
							label={ __( 'Post type', 'hypertaxonomy' ) }
							value={ post_type }
							options={ postTypeOptions }
							onChange={ ( val ) => setAttributes( { post_type: val } ) }
						/>
						<TextControl
							label={ __( 'Posts per page', 'hypertaxonomy' ) }
							type="number"
							value={ posts_per_page }
							onChange={ ( val ) => setAttributes( { posts_per_page: val } ) }
						/>
						<TextControl
							label={ __( 'Max results (0 = no cap)', 'hypertaxonomy' ) }
							type="number"
							value={ max_results }
							onChange={ ( val ) => setAttributes( { max_results: val } ) }
						/>
						<p className="components-base-control__label">
							{ __( 'Sort order', 'hypertaxonomy' ) }
						</p>
						<SortOrderBuilder
							value={ orderby }
							onChange={ ( val ) => setAttributes( { orderby: val } ) }
						/>
						{ showTermSourceFilters && (
							<TextControl
								label={ __( 'Keyword search', 'hypertaxonomy' ) }
								help={ __(
									'Boosts relevance for posts matching this text. Supports field operators: title:word, content:word, excerpt:word. Prefix with + to require or - to penalize. Does not filter out non-matching posts.',
									'hypertaxonomy'
								) }
								value={ search }
								onChange={ ( val ) => setAttributes( { search: val } ) }
							/>
						) }
					</PanelBody>

					{ /* ── Filters ─────────────────────────────────────────── */ }
					<PanelBody
						title={ __( 'Filters', 'hypertaxonomy' ) }
						initialOpen={ false }
					>
						<TermTokenField
							label={ __( 'Restrict to terms (all must match)', 'hypertaxonomy' ) }
							help={ __(
								'Posts must have ALL of these terms. Uses the selected taxonomy for autocomplete.',
								'hypertaxonomy'
							) }
							taxonomy={ taxonomy }
							value={ filter_terms }
							onChange={ ( val ) => setAttributes( { filter_terms: val } ) }
						/>
						<GroupTokenField
							label={ __( 'Restrict to groups', 'hypertaxonomy' ) }
							help={ __(
								'Posts must have at least one term from each listed group.',
								'hypertaxonomy'
							) }
							value={ filter_groups }
							onChange={ ( val ) => setAttributes( { filter_groups: val } ) }
						/>
						<TermTokenField
							label={ __( 'Exclude by terms', 'hypertaxonomy' ) }
							help={ __(
								'Posts with ANY of these terms are excluded.',
								'hypertaxonomy'
							) }
							taxonomy={ taxonomy }
							value={ filter_out_terms }
							onChange={ ( val ) => setAttributes( { filter_out_terms: val } ) }
						/>
						<GroupTokenField
							label={ __( 'Exclude by groups', 'hypertaxonomy' ) }
							help={ __(
								'Posts with any term from ANY of these groups are excluded.',
								'hypertaxonomy'
							) }
							value={ filter_out_groups }
							onChange={ ( val ) => setAttributes( { filter_out_groups: val } ) }
						/>
						<FormTokenField
							label={ __( 'Inline filter panel', 'hypertaxonomy' ) }
							help={ __(
								'Renders a frontend filter UI. Add keywords: date_published, date_modified, author, or any taxonomy slug.',
								'hypertaxonomy'
							) }
							value={ toArray( filters ) }
							suggestions={ filterKeywordSuggestions }
							onChange={ ( vals ) =>
								setAttributes( { filters: vals.join( ',' ) } )
							}
						/>
						{ toArray( filters ).length > 0 && (
							<TextControl
								label={ __( 'Filter options (positional, comma-separated)', 'hypertaxonomy' ) }
								value={ filter_options }
								onChange={ ( val ) =>
									setAttributes( { filter_options: val } )
								}
							/>
						) }
					</PanelBody>

					{ /* ── Display ─────────────────────────────────────────── */ }
					<PanelBody
						title={ __( 'Display', 'hypertaxonomy' ) }
						initialOpen={ false }
					>
						<ToggleControl
							label={ __( 'Show thumbnail', 'hypertaxonomy' ) }
							checked={ toBool( show_thumbnail ) }
							onChange={ ( val ) =>
								setAttributes( { show_thumbnail: fromBool( val ) } )
							}
						/>
						<ToggleControl
							label={ __( 'Show excerpt', 'hypertaxonomy' ) }
							checked={ toBool( show_excerpt ) }
							onChange={ ( val ) =>
								setAttributes( { show_excerpt: fromBool( val ) } )
							}
						/>
						<TextControl
							label={ __( 'Meta line', 'hypertaxonomy' ) }
							help={ __(
								'Template for the meta line below the title. Tokens: {date}, {author}, {modified}. Leave empty to hide.',
								'hypertaxonomy'
							) }
							value={ meta }
							onChange={ ( val ) => setAttributes( { meta: val } ) }
						/>
						<SelectControl
							label={ __( 'Format', 'hypertaxonomy' ) }
							help={ __( 'ul/ol: title-only list. div: full card with thumbnail/excerpt/meta.', 'hypertaxonomy' ) }
							value={ format }
							options={ [
								{ label: __( 'Unordered list (ul)', 'hypertaxonomy' ), value: 'ul' },
								{ label: __( 'Ordered list (ol)', 'hypertaxonomy' ),   value: 'ol' },
								{ label: __( 'Article cards (div)', 'hypertaxonomy' ), value: 'div' },
							] }
							onChange={ ( val ) => setAttributes( { format: val } ) }
						/>
						{ 'div' === format && (
							<SelectControl
								label={ __( 'Thumbnail size', 'hypertaxonomy' ) }
								value={ image_size }
								options={ [
									{ label: __( 'Thumbnail', 'hypertaxonomy' ),        value: 'thumbnail' },
									{ label: __( 'Medium (default)', 'hypertaxonomy' ), value: 'medium' },
									{ label: __( 'Medium large', 'hypertaxonomy' ),      value: 'medium_large' },
									{ label: __( 'Large', 'hypertaxonomy' ),             value: 'large' },
									{ label: __( 'Full', 'hypertaxonomy' ),              value: 'full' },
								] }
								onChange={ ( val ) => setAttributes( { image_size: val } ) }
							/>
						) }
						<TextControl
							label={ __( 'Columns', 'hypertaxonomy' ) }
							help={ __( 'Number of columns (div: CSS grid; ul/ol: multi-column text flow).', 'hypertaxonomy' ) }
							type="number"
							min="1"
							max="6"
							value={ columns }
							onChange={ ( val ) => setAttributes( { columns: val } ) }
						/>
						<TextControl
							label={ __( 'Template', 'hypertaxonomy' ) }
							help={ __(
								'CSS class slug appended as htax-template-{name}. Leave "default" unless your theme provides a custom template.',
								'hypertaxonomy'
							) }
							value={ template }
							onChange={ ( val ) => setAttributes( { template: val } ) }
						/>
						<TextControl
							label={ __( 'No-results message', 'hypertaxonomy' ) }
							help={ __( 'Shown when the query returns no results. Leave empty to render nothing.', 'hypertaxonomy' ) }
							value={ no_results_message }
							onChange={ ( val ) =>
								setAttributes( { no_results_message: val } )
							}
						/>
						<FormTokenField
							label={ __( 'Frontend sort picker fields', 'hypertaxonomy' ) }
							help={ __(
								'Fields shown in the frontend sort-order picker. Leave empty to hide the picker.',
								'hypertaxonomy'
							) }
							value={ toArray( sort_order_options ) }
							suggestions={ SORT_FIELD_VALUES }
							onChange={ ( vals ) =>
								setAttributes( { sort_order_options: vals.join( ',' ) } )
							}
						/>
						{ toArray( sort_order_options ).length > 0 && (
							<SelectControl
								label={ __( 'Sort picker alignment', 'hypertaxonomy' ) }
								value={ sort_order_align }
								options={ [
									{ label: __( 'Default (left)', 'hypertaxonomy' ), value: '' },
									{ label: __( 'Left', 'hypertaxonomy' ),            value: 'left' },
									{ label: __( 'Right', 'hypertaxonomy' ),           value: 'right' },
									{ label: __( 'Center', 'hypertaxonomy' ),          value: 'center' },
								] }
								onChange={ ( val ) => setAttributes( { sort_order_align: val } ) }
								__nextHasNoMarginBottom
							/>
						) }
						{ toArray( filters ).length > 0 && (
							<SelectControl
								label={ __( 'Filter panel alignment', 'hypertaxonomy' ) }
								value={ filters_align }
								options={ [
									{ label: __( 'Default (left)', 'hypertaxonomy' ), value: '' },
									{ label: __( 'Left', 'hypertaxonomy' ),            value: 'left' },
									{ label: __( 'Right', 'hypertaxonomy' ),           value: 'right' },
									{ label: __( 'Center', 'hypertaxonomy' ),          value: 'center' },
								] }
								onChange={ ( val ) => setAttributes( { filters_align: val } ) }
								__nextHasNoMarginBottom
							/>
						) }
						<TextControl
							label={ __( 'Prepend HTML (per post)', 'hypertaxonomy' ) }
							help={ __(
								'HTML inserted before each post item. Supports placeholders: {id}, {title}, {url}, {date}, {modified}, {author}, {excerpt}, {comment_count}, {thumbnail}, {thumbnail:large}.',
								'hypertaxonomy'
							) }
							value={ prepend }
							onChange={ ( val ) => setAttributes( { prepend: val } ) }
						/>
						<TextControl
							label={ __( 'Append HTML (per post)', 'hypertaxonomy' ) }
							help={ __(
								'HTML inserted after each post item. Same placeholders as prepend.',
								'hypertaxonomy'
							) }
							value={ append }
							onChange={ ( val ) => setAttributes( { append: val } ) }
						/>
						<TextControl
							label={ __( 'Additional CSS class', 'hypertaxonomy' ) }
							value={ cssClass }
							onChange={ ( val ) => setAttributes( { class: val } ) }
						/>
					</PanelBody>


				</InspectorControls>

				<div { ...blockProps }>
					<Disabled>
						<ServerSideRender
							block={ blockName }
							attributes={ attributes }
						/>
					</Disabled>
				</div>
			</>
		);
	};
}
