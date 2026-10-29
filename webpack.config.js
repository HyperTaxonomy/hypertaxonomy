const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// Add an entry here for each new block.
const blocks = [
	'ht-post-list',
	'ht-related-posts',
	'ht-term-list',
	'ht-term-cloud',
	'ht-term-index',
	'ht-term-groups',
	'ht-sort-order-options',
	'ht-power-search',
	'ht-query-group-filter',
];

// Each block gets its own config so output goes to its own build/ directory.
// JS: build/index.js  |  CSS: build/{block-name}.css  |  Asset: build/index.asset.php
module.exports = blocks.map( ( block ) => ( {
	...defaultConfig,
	entry: {
		[ block ]: path.resolve( __dirname, `blocks/${ block }/src/index.js` ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, `blocks/${ block }/build` ),
		filename: 'index.js',
	},
} ) );
