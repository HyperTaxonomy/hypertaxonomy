import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import metadata from '../block.json';
import { createEdit, toBool, fromBool } from '../../shared/term-display-edit.js';

function DisplayPanel( { attributes, setAttributes } ) {
	const {
		show_navigation,
		show_letter_headers,
		columns,
		navigation_position,
		show_count,
		min_count,
		header,
		class: cssClass,
	} = attributes;

	return (
		<PanelBody title={ __( 'Display', 'hypertaxonomy' ) } initialOpen={ false }>
			<ToggleControl
				label={ __( 'Show A\u2013Z navigation', 'hypertaxonomy' ) }
				checked={ toBool( show_navigation ) }
				onChange={ ( val ) => setAttributes( { show_navigation: fromBool( val ) } ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'Show letter headers', 'hypertaxonomy' ) }
				checked={ toBool( show_letter_headers ) }
				onChange={ ( val ) => setAttributes( { show_letter_headers: fromBool( val ) } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Navigation position', 'hypertaxonomy' ) }
				value={ navigation_position }
				options={ [
					{ label: __( 'Top', 'hypertaxonomy' ),          value: 'top' },
					{ label: __( 'Bottom', 'hypertaxonomy' ),        value: 'bottom' },
					{ label: __( 'Top & bottom', 'hypertaxonomy' ),  value: 'both' },
				] }
				onChange={ ( val ) => setAttributes( { navigation_position: val } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Columns', 'hypertaxonomy' ) }
				value={ columns }
				options={ [
					{ label: '1', value: '1' },
					{ label: '2', value: '2' },
					{ label: '3', value: '3' },
					{ label: '4', value: '4' },
				] }
				onChange={ ( val ) => setAttributes( { columns: val } ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'Show post count', 'hypertaxonomy' ) }
				checked={ toBool( show_count ) }
				onChange={ ( val ) => setAttributes( { show_count: fromBool( val ) } ) }
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
