import { registerBlockType } from '@wordpress/blocks';
import { createEdit } from '../../shared/post-list-edit.js';
import metadata from '../block.json';

// include/exclude term source controls are hidden — htax-related-posts derives
// its term set from the current post automatically and ignores those attributes.
// allowAllTaxonomies: the empty taxonomy option means "all taxonomies the post
// has terms in" (pooled and scored together).
registerBlockType( metadata.name, {
	edit: createEdit( metadata.name, { showTermSourceFilters: false, allowAllTaxonomies: true } ),
	save: () => null,
} );
