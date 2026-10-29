import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import metadata from '../block.json';
import { createEdit } from '../../shared/term-display-edit.js';

function DisplayPanel( { attributes, setAttributes } ) {
	const {
		smallest,
		largest,
		unit,
		max_results,
		min_count,
		header,
		class: cssClass,
	} = attributes;

	return (
		<PanelBody title={ __( 'Display', 'hypertaxonomy' ) } initialOpen={ false }>
			<TextControl
				label={ __( 'Smallest font size', 'hypertaxonomy' ) }
				type="number"
				value={ smallest }
				onChange={ ( val ) => setAttributes( { smallest: val } ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Largest font size', 'hypertaxonomy' ) }
				type="number"
				value={ largest }
				onChange={ ( val ) => setAttributes( { largest: val } ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Font size unit', 'hypertaxonomy' ) }
				value={ unit }
				options={ [
					{ label: 'pt', value: 'pt' },
					{ label: 'px', value: 'px' },
					{ label: 'em', value: 'em' },
					{ label: 'rem', value: 'rem' },
				] }
				onChange={ ( val ) => setAttributes( { unit: val } ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Max terms (0 = all)', 'hypertaxonomy' ) }
				type="number"
				value={ max_results }
				onChange={ ( val ) => setAttributes( { max_results: val } ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Min post count (0 = show all)', 'hypertaxonomy' ) }
				type="number"
				value={ min_count }
				onChange={ ( val ) => setAttributes( { min_count: val } ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Header text', 'hypertaxonomy' ) }
				help={ __( 'Supports {term_count}, {total_count}, and {group_names} placeholders.', 'hypertaxonomy' ) }
				value={ header }
				onChange={ ( val ) => setAttributes( { header: val } ) }
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
	);
}

registerBlockType( metadata.name, {
	edit: createEdit( metadata.name, DisplayPanel ),
	save: () => null,
} );
