import { registerBlockType } from '@wordpress/blocks';
import { createEdit } from '../../shared/post-list-edit.js';
import metadata from '../block.json';

registerBlockType( metadata.name, {
	edit: createEdit( metadata.name ),
	save: () => null,
} );
