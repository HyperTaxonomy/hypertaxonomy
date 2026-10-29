import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import metadata from '../block.json';
import { createEdit } from '../../shared/term-display-edit.js';

function DisplayPanel( { attributes, setAttributes } ) {
	const {
		orderby,
		order,
		max_depth,
		indent_style,
		columns,
		collapsible,
		header_link_behavior,
		min_count,
		header,
		class: cssClass,
	} = attributes;

	return (
		<PanelBody title={ __( 'Display', 'hypertaxonomy' ) } initialOpen={ false }>
			<SelectControl
				label={ __( 'Sort by', 'hypertaxonomy' ) }
				value={ orderby }
				options={ [
					{ label: __( 'Name', 'hypertaxonomy' ),         value: 'name' },
					{ label: __( 'Post count', 'hypertaxonomy' ),   value: 'count' },
					{ label: __( 'Term ID', 'hypertaxonomy' ),      value: 'id' },
					{ label: __( 'Slug', 'hypertaxonomy' ),         value: 'slug' },
					{ label: __( 'Custom order', 'hypertaxonomy' ), value: 'term_order' },
				] }
				onChange={ ( val ) => setAttributes( { orderby: val } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Sort direction', 'hypertaxonomy' ) }
				value={ order }
				options={ [
					{ label: __( 'Ascending', 'hypertaxonomy' ),  value: 'ASC' },
					{ label: __( 'Descending', 'hypertaxonomy' ), value: 'DESC' },
				] }
				onChange={ ( val ) => setAttributes( { order: val } ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Max depth (-1 = global setting, 0 = unlimited)', 'hypertaxonomy' ) }
				type="number"
				value={ max_depth }
				onChange={ ( val ) => setAttributes( { max_depth: val } ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Indent style', 'hypertaxonomy' ) }
				value={ indent_style }
				options={ [
					{ label: __( 'Padding', 'hypertaxonomy' ), value: 'padding' },
					{ label: __( 'Dash', 'hypertaxonomy' ),    value: 'dash' },
					{ label: __( 'None', 'hypertaxonomy' ),    value: 'none' },
				] }
				onChange={ ( val ) => setAttributes( { indent_style: val } ) }
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
			<SelectControl
				label={ __( 'Collapsible', 'hypertaxonomy' ) }
				value={ collapsible }
				options={ [
					{ label: __( 'No', 'hypertaxonomy' ),             value: 'false' },
					{ label: __( 'Yes (expanded)', 'hypertaxonomy' ),  value: 'true' },
					{ label: __( 'Yes (collapsed)', 'hypertaxonomy' ), value: 'collapsed' },
				] }
				onChange={ ( val ) => setAttributes( { collapsible: val } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Header link behavior', 'hypertaxonomy' ) }
				value={ header_link_behavior }
				options={ [
					{ label: __( 'None', 'hypertaxonomy' ),   value: 'none' },
					{ label: __( 'Link', 'hypertaxonomy' ),   value: 'link' },
					{ label: __( 'Toggle', 'hypertaxonomy' ), value: 'toggle' },
				] }
				onChange={ ( val ) => setAttributes( { header_link_behavior: val } ) }
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
				help={ __( 'Supports {total_count} and {group_names} placeholders.', 'hypertaxonomy' ) }
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
