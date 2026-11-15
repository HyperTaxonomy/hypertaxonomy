import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import metadata from '../block.json';
import { createEdit, toBool, fromBool } from '../../shared/term-display-edit.js';

// htax-term-groups uses `taxonomies` (plural, multi-taxonomy) and operates
// on groups rather than individual terms — pass isTermGroups: true.
function DisplayPanel( { attributes, setAttributes } ) {
	const {
		collapsible,
		auto_collapse,
		show_empty_groups,
		show_group_count,
		show_term_count,
		show_term_icons,
		class: cssClass,
	} = attributes;

	return (
		<PanelBody title={ __( 'Display', 'hypertaxonomy' ) } initialOpen={ false }>
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
			<ToggleControl
				label={ __( 'Auto-collapse siblings', 'hypertaxonomy' ) }
				checked={ toBool( auto_collapse ) }
				onChange={ ( val ) => setAttributes( { auto_collapse: fromBool( val ) } ) }
			/>
			<ToggleControl
				label={ __( 'Show empty groups', 'hypertaxonomy' ) }
				checked={ toBool( show_empty_groups ) }
				onChange={ ( val ) => setAttributes( { show_empty_groups: fromBool( val ) } ) }
			/>
			<ToggleControl
				label={ __( 'Show group term count', 'hypertaxonomy' ) }
				checked={ toBool( show_group_count ) }
				onChange={ ( val ) => setAttributes( { show_group_count: fromBool( val ) } ) }
			/>
			<ToggleControl
				label={ __( 'Show term post count', 'hypertaxonomy' ) }
				checked={ toBool( show_term_count ) }
				onChange={ ( val ) => setAttributes( { show_term_count: fromBool( val ) } ) }
			/>
			<SelectControl
				label={ __( 'Term icons', 'hypertaxonomy' ) }
				help={ __( 'Use uploaded icons for each term; falls back to the term icon emoji when no icon is set.', 'hypertaxonomy' ) }
				value={ show_term_icons }
				options={ [
					{ label: __( 'Emoji / text only', 'hypertaxonomy' ),            value: 'never' },
					{ label: __( 'Icons, with emoji fallback', 'hypertaxonomy' ), value: 'auto' },
				] }
				onChange={ ( val ) => setAttributes( { show_term_icons: val } ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Additional CSS class', 'hypertaxonomy' ) }
				value={ cssClass }
				onChange={ ( val ) => setAttributes( { class: val } ) }
			/>
		</PanelBody>
	);
}

registerBlockType( metadata.name, {
	edit: createEdit( metadata.name, DisplayPanel, { isTermGroups: true } ),
	save: () => null,
} );
