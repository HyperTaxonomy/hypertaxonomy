/**
 * Shared Edit component factory for term-display blocks:
 *   ht-term-list, ht-term-cloud, ht-term-index, ht-term-groups
 *
 * All four blocks share the same Term Source panel (taxonomy selector,
 * hide_empty, include/exclude groups and terms). Block-specific display
 * options are provided by a DisplayPanel component passed by each block's
 * src/index.js.
 *
 * Usage:
 *   import { createEdit } from '../../shared/term-display-edit.js';
 *   registerBlockType( metadata.name, {
 *       edit: createEdit( metadata.name, DisplayPanel ),
 *       save: () => null,
 *   } );
 *
 * For ht-term-groups pass `{ isTermGroups: true }`:
 *   edit: createEdit( metadata.name, DisplayPanel, { isTermGroups: true } )
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
// FormTokenField kept for the Taxonomies field (isTermGroups); TermTokenField/GroupTokenField for term/group pickers.
import ServerSideRender from '@wordpress/server-side-render';
import { TermTokenField, GroupTokenField } from './components';
import './editor.css';

// ---------------------------------------------------------------------------
// Helpers — exported so block index.js files can use them in DisplayPanel
// ---------------------------------------------------------------------------

export const toBool  = ( str ) => 'true' === str;
export const fromBool = ( b )  => ( b ? 'true' : 'false' );
export const toArray  = ( str ) =>
	str ? str.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) : [];

// ---------------------------------------------------------------------------
// createEdit factory
// ---------------------------------------------------------------------------

/**
 * Returns an Edit component for a term-display block.
 *
 * @param {string}   blockName    Fully-qualified block name for ServerSideRender.
 * @param {Function} DisplayPanel Component: ({ attributes, setAttributes }) => JSX.
 *                                Renders the block-specific Display panel.
 * @param {Object}   [opts]
 * @param {boolean}  [opts.isTermGroups=false]
 *   When true, replaces the single-taxonomy SelectControl with a
 *   FormTokenField for multiple taxonomy slugs (the `taxonomies` attribute)
 *   and hides the include/exclude terms fields — ht_term_groups operates
 *   on groups rather than individual terms.
 */
export function createEdit( blockName, DisplayPanel, opts = {} ) {
	const { isTermGroups = false } = opts;

	return function Edit( { attributes, setAttributes } ) {
		const {
			taxonomy,
			taxonomies,
			hide_empty,
			include_groups,
			exclude_groups,
			include_terms,
			exclude_terms,
			prepend,
			append,
			show_icons,
		} = attributes;

		const { allTaxonomies } = useSelect( ( select ) => ( {
			allTaxonomies:
				select( coreStore ).getTaxonomies( { per_page: -1, show_in_rest: true } ) ?? [],
		} ), [] );

		const taxonomyOptions = [
			{ label: __( '— Select taxonomy —', 'hypertaxonomy' ), value: '' },
			...allTaxonomies.map( ( t ) => ( { label: t.name, value: t.slug } ) ),
		];

		const taxonomySuggestions = allTaxonomies.map( ( t ) => t.slug );

		const blockProps = useBlockProps();

		return (
			<>
				<InspectorControls>

					{ /* ── Term Source ─────────────────────────────────── */ }
					<PanelBody
						title={ __( 'Term Source', 'hypertaxonomy' ) }
						initialOpen={ true }
					>
						{ isTermGroups ? (
							<FormTokenField
								label={ __( 'Taxonomies', 'hypertaxonomy' ) }
								help={ __(
									'One or more taxonomy slugs. Terms from all listed taxonomies are shown.',
									'hypertaxonomy'
								) }
								value={ toArray( taxonomies ) }
								suggestions={ taxonomySuggestions }
								onChange={ ( vals ) =>
									setAttributes( { taxonomies: vals.join( ',' ) } )
								}
							/>
						) : (
							<SelectControl
								label={ __( 'Taxonomy', 'hypertaxonomy' ) }
								value={ taxonomy }
								options={ taxonomyOptions }
								onChange={ ( val ) => setAttributes( { taxonomy: val } ) }
								__nextHasNoMarginBottom
							/>
						) }

						<ToggleControl
							label={ __( 'Hide empty terms', 'hypertaxonomy' ) }
							checked={ '1' === hide_empty }
							onChange={ ( val ) =>
								setAttributes( { hide_empty: val ? '1' : '0' } )
							}
						/>

						{ ! isTermGroups && (
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
							</>
						) }

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
					</PanelBody>

					{ /* ── Display (block-specific) ─────────────────────── */ }
					<DisplayPanel
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>

					{ /* ── Template ───────────────────────────────────── */ }
					<PanelBody
						title={ __( 'Template', 'hypertaxonomy' ) }
						initialOpen={ false }
					>
						<TextControl
							label={ __( 'Prepend', 'hypertaxonomy' ) }
							help={ __( 'HTML/text before each term. Supports {icon}, {count}, {depth_level}, {is_redirect}, {target}.', 'hypertaxonomy' ) }
							value={ prepend || '' }
							onChange={ ( val ) => setAttributes( { prepend: val } ) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'Append', 'hypertaxonomy' ) }
							help={ __( 'HTML/text after each term. Same placeholders as Prepend.', 'hypertaxonomy' ) }
							value={ append || '' }
							onChange={ ( val ) => setAttributes( { append: val } ) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Icon fallback', 'hypertaxonomy' ) }
							help={ __( 'Controls {icon}: whether to show a colored square when no icon is uploaded for a term.', 'hypertaxonomy' ) }
							value={ show_icons || 'when_available' }
							options={ [
								{ label: __( 'Empty when no icon', 'hypertaxonomy' ),  value: 'when_available' },
								{ label: __( 'Colored placeholder', 'hypertaxonomy' ), value: 'always' },
							] }
							onChange={ ( val ) => setAttributes( { show_icons: val } ) }
							__nextHasNoMarginBottom
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
