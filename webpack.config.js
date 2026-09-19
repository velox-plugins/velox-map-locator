const fs = require( 'fs' );
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const packageJson = require( './package.json' );

class VeloxBuildMetadataPlugin {
	apply( compiler ) {
		compiler.hooks.afterEmit.tap( 'VeloxBuildMetadataPlugin', () => {
			const outputPath = compiler.options.output.path;
			const blockOutput = path.join( outputPath, 'block' );
			fs.mkdirSync( blockOutput, { recursive: true } );

			fs.copyFileSync(
				path.resolve( __dirname, 'src/block/block.json' ),
				path.join( blockOutput, 'block.json' )
			);
			fs.copyFileSync(
				path.resolve( __dirname, 'src/block/editor.css' ),
				path.join( blockOutput, 'editor.css' )
			);

			const phpAsset = ( dependencies ) =>
				`<?php\nreturn array(\n\t'dependencies' => array( ${ dependencies.map( ( item ) => `'${ item }'` ).join( ', ' ) } ),\n\t'version'      => '${ packageJson.version }',\n);\n`;

			fs.writeFileSync(
				path.join( outputPath, 'admin.asset.php' ),
				phpAsset( [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'media-editor' ] )
			);
			fs.writeFileSync(
				path.join( blockOutput, 'index.asset.php' ),
				phpAsset( [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-block-editor', 'wp-server-side-render' ] )
			);
		} );
	}
}

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.js' ),
		frontend: path.resolve( __dirname, 'src/frontend/index.js' ),
		'map-google': path.resolve( __dirname, 'src/frontend/map-google.js' ),
		'map-leaflet': path.resolve( __dirname, 'src/frontend/map-leaflet.js' ),
		'block/index': path.resolve( __dirname, 'src/block/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		filename: '[name].js',
		clean: true,
	},
	plugins: [
		...defaultConfig.plugins,
		new VeloxBuildMetadataPlugin(),
	],
	optimization: {
		...defaultConfig.optimization,
		minimize: false,
	},
	devtool: process.env.NODE_ENV === 'production' ? false : 'source-map',
};
