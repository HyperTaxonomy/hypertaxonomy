/**
 * HT Query Group Filter — block editor extension.
 *
 * Adds a "Term Group Filter" panel to the core/query and core/latest-posts
 * blocks' inspector controls. The selected group IDs are stored in the
 * htax_filter_groups attribute and are consumed server-side.
 *
 * OR logic: posts matching terms from ANY of the selected groups are included.
 * The taxonomy is auto-detected from the block's post type setting.
 */

import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { GroupTokenField } from '../../shared/components';

// ---------------------------------------------------------------------------
// 1. Extend core/query block attributes schema
// ---------------------------------------------------------------------------

addFilter(
	'blocks.registerBlockType',
	'hypertaxonomy/query-group-filter/add-attribute',
	( settings, name ) => {
		if ( name !== 'core/query' && name !== 'core/latest-posts' ) {
			return settings;
		}
		return {
			...settings,
			attributes: {
				...settings.attributes,
				htax_filter_groups: {
					type: 'string',
					default: '',
				},
			},
		};
	}
);

// ---------------------------------------------------------------------------
// 2. Inject inspector panel into core/query block editor UI
// ---------------------------------------------------------------------------

const withGroupFilterControls = createHigherOrderComponent(
	( BlockEdit ) => {
		return function HtaxQueryGroupFilterEdit( props ) {
			if ( props.name !== 'core/query' && props.name !== 'core/latest-posts' ) {
				return <BlockEdit { ...props } />;
			}

			const { attributes, setAttributes } = props;
			const { htax_filter_groups } = attributes;

			return (
				<>
					<BlockEdit { ...props } />
					<InspectorControls>
						<PanelBody
							title={ __( 'HT Term Group Filter', 'hypertaxonomy' ) }
							initialOpen={ false }
						>
							<GroupTokenField
								label={ __( 'Filter by groups', 'hypertaxonomy' ) }
								help={ __(
									'Show only posts that have at least one term from any of the selected groups. Leave empty to show all posts. Multiple groups use OR logic — the union of all matched terms.',
									'hypertaxonomy'
								) }
								value={ htax_filter_groups }
								onChange={ ( val ) =>
									setAttributes( { htax_filter_groups: val } )
								}
							/>
						</PanelBody>
					</InspectorControls>
				</>
			);
		};
	},
	'withHtaxGroupFilterControls'
);

addFilter(
	'editor.BlockEdit',
	'hypertaxonomy/query-group-filter/inject-controls',
	withGroupFilterControls
);
