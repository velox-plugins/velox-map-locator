/* Velox Map Locator — on-demand map runtime loader. */
( function () {
	'use strict';

	const promises = new Map();

	function canonicalUrl( value ) {
		try {
			return new URL( String( value || '' ), document.baseURI ).href;
		} catch ( error ) {
			return String( value || '' );
		}
	}

	function existingAsset( selector, url ) {
		const expected = canonicalUrl( url );
		return Array.from( document.querySelectorAll( selector ) ).find( ( node ) => canonicalUrl( node.src || node.href ) === expected ) || null;
	}

	function loadStyle( url, key ) {
		if ( ! url ) return Promise.reject( new Error( 'Map stylesheet URL is unavailable.' ) );
		const promiseKey = `style:${ key }`;
		if ( promises.has( promiseKey ) ) return promises.get( promiseKey );
		if ( existingAsset( 'link[rel="stylesheet"][href]', url ) ) return Promise.resolve();

		const promise = new Promise( ( resolve, reject ) => {
			const link = document.createElement( 'link' );
			link.rel = 'stylesheet';
			link.href = url;
			link.dataset.vmlRuntimeStyle = key;
			link.addEventListener( 'load', resolve, { once: true } );
			link.addEventListener( 'error', () => {
				link.remove();
				reject( new Error( 'Map stylesheet could not load.' ) );
			}, { once: true } );
			document.head.appendChild( link );
		} ).catch( ( error ) => {
			promises.delete( promiseKey );
			throw error;
		} );
		promises.set( promiseKey, promise );
		return promise;
	}

	function loadScript( url, key, ready ) {
		if ( typeof ready === 'function' && ready() ) return Promise.resolve();
		if ( ! url ) return Promise.reject( new Error( 'Map script URL is unavailable.' ) );
		const promiseKey = `script:${ key }`;
		if ( promises.has( promiseKey ) ) return promises.get( promiseKey );

		const existing = existingAsset( 'script[src]', url );
		const promise = new Promise( ( resolve, reject ) => {
			const finish = () => {
				if ( ! ready || ready() ) resolve();
				else reject( new Error( 'Map script loaded but did not initialize.' ) );
			};
			const fail = () => reject( new Error( 'Map script could not load.' ) );

			if ( existing ) {
				if ( ! ready || ready() ) {
					resolve();
					return;
				}
				existing.addEventListener( 'load', finish, { once: true } );
				existing.addEventListener( 'error', fail, { once: true } );
				return;
			}

			const script = document.createElement( 'script' );
			script.src = url;
			script.async = true;
			script.dataset.vmlRuntimeScript = key;
			const nonceSource = document.querySelector( 'script[nonce]' );
			if ( nonceSource && nonceSource.nonce ) script.nonce = nonceSource.nonce;
			script.addEventListener( 'load', finish, { once: true } );
			script.addEventListener( 'error', () => {
				script.remove();
				fail();
			}, { once: true } );
			document.head.appendChild( script );
		} ).catch( ( error ) => {
			promises.delete( promiseKey );
			throw error;
		} );
		promises.set( promiseKey, promise );
		return promise;
	}

	function parsePayload( root ) {
		const node = root.querySelector( '.vml-locator__data' );
		if ( ! node ) return null;
		try {
			return JSON.parse( node.textContent || '{}' );
		} catch ( error ) {
			return null;
		}
	}

	function initializeAdapter( root, engine ) {
		if ( engine === 'leaflet' && window.VelomaloMapLeaflet ) {
			window.VelomaloMapLeaflet.initializeRoot( root );
			return true;
		}
		if ( engine === 'google' && window.VelomaloMapGoogle ) {
			window.VelomaloMapGoogle.initializeRoot( root );
			return true;
		}
		return false;
	}

	function load( root, button, assets ) {
		const payload = parsePayload( root );
		const engine = payload && payload.map_provider && payload.map_provider.engine;
		root.dataset.vmlInteractionApproved = 'true';

		let runtime;
		if ( engine === 'leaflet' ) {
			runtime = Promise.all( [
				loadStyle( assets.leafletCss, 'leaflet' ),
				loadScript( assets.leafletJs, 'leaflet', () => Boolean( window.L ) ),
			] )
				.then( () => loadScript( assets.leafletAdapter, 'leaflet-adapter', () => Boolean( window.VelomaloMapLeaflet ) ) )
				.then( () => initializeAdapter( root, engine ) );
		} else if ( engine === 'google' ) {
			runtime = loadScript( assets.googleAdapter, 'google-adapter', () => Boolean( window.VelomaloMapGoogle ) )
				.then( () => initializeAdapter( root, engine ) );
		} else {
			runtime = Promise.reject( new Error( 'Unsupported lazy map engine.' ) );
		}

		return Promise.resolve( runtime ).then( ( initialized ) => {
			if ( ! initialized ) throw new Error( 'Map adapter could not initialize.' );
			root.dataset.vmlLazyRuntimeLoading = 'false';
			return true;
		} );
	}

	window.VelomaloLazyMapRuntime = { load };
}() );
