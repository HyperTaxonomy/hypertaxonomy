import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	Disabled,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import ServerSideRender from '@wordpress/server-side-render';
import { SortOrderBuilder } from '../../shared/components';
import metadata from '../block.json';


function Edit( { attributes, setAttributes } ) {
	const {
		placeholder,
		button_text,
		match,
		min_length,
		search_in,
		posts_per_page,
		max_results,
		orderby,
		min_score,
		ajax,
		near_country,
		class: cssClass,
	} = attributes;

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Search Form', 'hypertaxonomy' ) }>
					<TextControl
						label={ __( 'Placeholder text', 'hypertaxonomy' ) }
						value={ placeholder }
						onChange={ ( val ) => setAttributes( { placeholder: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Button label (empty = Enter key only)', 'hypertaxonomy' ) }
						value={ button_text }
						onChange={ ( val ) => setAttributes( { button_text: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Match mode', 'hypertaxonomy' ) }
						value={ match }
						options={ [
							{ label: __( 'Any word (OR)', 'hypertaxonomy' ),  value: 'any' },
							{ label: __( 'All words (AND)', 'hypertaxonomy' ), value: 'all' },
						] }
						onChange={ ( val ) => setAttributes( { match: val } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Minimum query length', 'hypertaxonomy' ) }
						type="number"
						value={ min_length }
						onChange={ ( val ) => setAttributes( { min_length: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody title={ __( 'Results', 'hypertaxonomy' ) } initialOpen={ false }>
					<SelectControl
						label={ __( 'Search in', 'hypertaxonomy' ) }
						value={ search_in }
						options={ [
							{ label: __( 'All fields', 'hypertaxonomy' ),    value: 'all' },
							{ label: __( 'Title only', 'hypertaxonomy' ),    value: 'title' },
							{ label: __( 'Content only', 'hypertaxonomy' ),  value: 'content' },
							{ label: __( 'Excerpt only', 'hypertaxonomy' ),  value: 'excerpt' },
							{ label: __( 'Tags only', 'hypertaxonomy' ),     value: 'tags' },
							{ label: __( 'Categories only', 'hypertaxonomy' ), value: 'categories' },
						] }
						onChange={ ( val ) => setAttributes( { search_in: val } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Posts per page', 'hypertaxonomy' ) }
						type="number"
						value={ posts_per_page }
						onChange={ ( val ) => setAttributes( { posts_per_page: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Max results across all pages (0 = no cap)', 'hypertaxonomy' ) }
						type="number"
						value={ max_results }
						onChange={ ( val ) => setAttributes( { max_results: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<p className="components-base-control__label">
						{ __( 'Sort order', 'hypertaxonomy' ) }
					</p>
					<SortOrderBuilder
						value={ orderby }
						onChange={ ( val ) => setAttributes( { orderby: val } ) }
					/>
					<TextControl
						label={ __( 'Minimum relevance score (0–1)', 'hypertaxonomy' ) }
						type="number"
						value={ min_score }
						onChange={ ( val ) => setAttributes( { min_score: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<p style={ { fontSize: '12px', color: '#757575', marginTop: '8px' } }>
						{ __( 'Display options (thumbnail, columns, format, etc.) are configured on the [ht_search_results] shortcode placed separately on the page.', 'hypertaxonomy' ) }
					</p>
				</PanelBody>
				<PanelBody title={ __( 'AJAX Mode', 'hypertaxonomy' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'AJAX container ID', 'hypertaxonomy' ) }
						help={ __( 'If set, results load via AJAX into this container ID. Place a [ht_search_results id="…"] shortcode with the same ID elsewhere on the page. Leave empty for POST mode (results appear inline below the form).', 'hypertaxonomy' ) }
						value={ ajax }
						onChange={ ( val ) => setAttributes( { ajax: val } ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody title={ __( 'Advanced', 'hypertaxonomy' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Country code for near: operator (ISO 3166-1, e.g. US, CA, GB)', 'hypertaxonomy' ) }
						help={ __( 'Used to validate postal codes in near:XXXXX queries and look up the correct centroid. Default: US.', 'hypertaxonomy' ) }
						value={ near_country }
						onChange={ ( val ) => setAttributes( { near_country: val.toUpperCase() } ) }
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
				<Disabled>
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
					/>
				</Disabled>
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
