const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '../..' );

describe( 'Core development workspace contract', () => {
	test( 'package version matches the plugin header', () => {
		const pkg = JSON.parse( fs.readFileSync( path.join( root, 'package.json' ), 'utf8' ) );
		const plugin = fs.readFileSync( path.join( root, 'velox-map-locator.php' ), 'utf8' );
		expect( plugin ).toContain( `Version:           ${ pkg.version }` );
	} );

	test( 'all JavaScript build entry points exist', () => {
		[
			'src/admin/index.js',
			'src/frontend/index.js',
			'src/frontend/map-google.js',
			'src/frontend/map-leaflet.js',
			'src/block/index.js',
		].forEach( ( relativePath ) => {
			expect( fs.existsSync( path.join( root, relativePath ) ) ).toBe( true );
		} );
	} );

	test( 'block metadata identifies the published block', () => {
		const block = JSON.parse(
			fs.readFileSync( path.join( root, 'src/block/block.json' ), 'utf8' )
		);
		expect( block.name ).toBe( 'velox/map-locator' );
		expect( block.editorScript ).toBe( 'file:./index.js' );
	} );
} );
