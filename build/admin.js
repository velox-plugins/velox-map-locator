
( function () {
	'use strict';

	const { createElement: h, Fragment, useEffect, useMemo, useRef, useState } = wp.element;
	const { Button, Spinner, Notice, Modal } = wp.components;
	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const boot = window.VelomaloAdmin || {};
	const namespace = boot.restNamespace || '/velox-map-locator/v1';
	const days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
	const dayLabels = {
		monday: __( 'Monday', 'velox-map-locator' ),
		tuesday: __( 'Tuesday', 'velox-map-locator' ),
		wednesday: __( 'Wednesday', 'velox-map-locator' ),
		thursday: __( 'Thursday', 'velox-map-locator' ),
		friday: __( 'Friday', 'velox-map-locator' ),
		saturday: __( 'Saturday', 'velox-map-locator' ),
		sunday: __( 'Sunday', 'velox-map-locator' ),
	};

	function cx( ...values ) {
		return values.filter( Boolean ).join( ' ' );
	}

	function clone( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}


	function deepMerge( base, override ) {
		const output = clone( base || {} );
		Object.entries( override || {} ).forEach( ( [ key, value ] ) => {
			if ( value && typeof value === 'object' && ! Array.isArray( value ) ) output[ key ] = deepMerge( output[ key ] || {}, value );
			else output[ key ] = clone( value );
		} );
		return output;
	}

	function setNested( object, path, value ) {
		const next = clone( object );
		const parts = path.split( '.' );
		let cursor = next;
		parts.forEach( ( part, index ) => {
			if ( index === parts.length - 1 ) cursor[ part ] = value;
			else { cursor[ part ] = cursor[ part ] && typeof cursor[ part ] === 'object' ? cursor[ part ] : {}; cursor = cursor[ part ]; }
		} );
		return next;
	}

	function escapePath( value ) {
		return encodeURIComponent( String( value ) );
	}

	function queryString( values ) {
		const params = new URLSearchParams();
		Object.entries( values || {} ).forEach( ( [ key, value ] ) => {
			if ( value !== '' && value !== null && value !== undefined ) {
				params.set( key, value );
			}
		} );
		return params.toString();
	}

	let adminGoogleLoaderPromise = null;
	let adminGoogleLoaderKey = '';

	function googleAdminReady() {
		return Boolean( window.google && window.google.maps && typeof window.google.maps.importLibrary === 'function' );
	}

	function loadGoogleAdmin( config = {} ) {
		if ( googleAdminReady() ) return Promise.resolve( window.google.maps );
		const apiKey = String( config.apiKey || config.api_key || '' ).trim();
		if ( ! apiKey ) return Promise.reject( new Error( __( 'A Google Maps API key is required.', 'velox-map-locator' ) ) );
		if ( adminGoogleLoaderPromise ) return adminGoogleLoaderKey === apiKey ? adminGoogleLoaderPromise : Promise.reject( new Error( __( 'Google Maps is already loading with a different API key.', 'velox-map-locator' ) ) );
		adminGoogleLoaderKey = apiKey;
		adminGoogleLoaderPromise = new Promise( ( resolve, reject ) => {
			const existing = document.querySelector( 'script[src*="maps.googleapis.com/maps/api/js"]' );
			let settled = false;
			const finish = () => { if ( ! settled && googleAdminReady() ) { settled = true; resolve( window.google.maps ); } };
			const fail = () => { if ( ! settled ) { settled = true; reject( new Error( __( 'Google Maps JavaScript API could not load. Check the key, referrer restrictions and enabled APIs.', 'velox-map-locator' ) ) ); } };
			if ( existing ) {
				finish();
				if ( ! settled ) {
					existing.addEventListener( 'load', finish, { once: true } );
					existing.addEventListener( 'error', fail, { once: true } );
					let checks = 0;
					const timer = window.setInterval( () => { checks += 1; finish(); if ( settled || checks >= 100 ) { window.clearInterval( timer ); if ( ! settled ) fail(); } }, 100 );
				}
				return;
			}
			const callback = `__vmlAdminGoogleReady${ Date.now() }`;
			window[ callback ] = () => { try { delete window[ callback ]; } catch ( error ) { window[ callback ] = undefined; } finish(); };
			const params = new URLSearchParams( { key: apiKey, v: 'weekly', loading: 'async', callback } );
			const region = String( config.region || '' ).trim();
			if ( region && region.toLowerCase() !== 'auto' ) params.set( 'region', region.toUpperCase() );
			const script = document.createElement( 'script' );
			script.src = `https://maps.googleapis.com/maps/api/js?${ params.toString() }`;
			script.async = true;
			script.dataset.vmlGoogleMaps = 'admin';
			const nonceSource = document.querySelector( 'script[nonce]' );
			if ( nonceSource && nonceSource.nonce ) script.nonce = nonceSource.nonce;
			script.addEventListener( 'error', fail, { once: true } );
			document.head.appendChild( script );
		} );
		return adminGoogleLoaderPromise;
	}

	async function fetchLocations( args = {} ) {
		const response = await apiFetch( {
			path: `${ namespace }/admin/locations?${ queryString( args ) }`,
			parse: false,
		} );
		const items = await response.json();
		return {
			items,
			total: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
			totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) || 0 ),
		};
	}

	async function fetchLocators( args = {} ) {
		const response = await apiFetch( { path: `${ namespace }/admin/locators?${ queryString( args ) }`, parse: false } );
		const items = await response.json();
		return { items, total: Number( response.headers.get( 'X-WP-Total' ) || 0 ), totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) || 0 ) };
	}

	function getErrorMessage( error ) {
		if ( error && error.message ) {
			return error.message;
		}
		return __( 'Something went wrong. Please try again.', 'velox-map-locator' );
	}

	function getErrorField( error ) {
		if ( error && error.data && typeof error.data.field === 'string' ) {
			return error.data.field;
		}
		if ( error && error.data && error.data.params ) {
			const keys = Object.keys( error.data.params );
			return keys.length ? keys[ 0 ] : '';
		}
		return '';
	}

	function builderSectionForField( field ) {
		if ( typeof field !== 'string' ) return '';
		const match = field.match( /^config\.(source|layout|map|search|filters|content|appearance|behaviour|privacy)(?:\.|$)/ );
		return match ? match[ 1 ] : '';
	}

	function formatDate( value ) {
		if ( ! value ) {
			return '—';
		}
		const date = new Date( `${ value.replace( ' ', 'T' ) }Z` );
		if ( Number.isNaN( date.getTime() ) ) {
			return value;
		}
		return new Intl.DateTimeFormat( undefined, { month: 'short', day: 'numeric', year: 'numeric' } ).format( date );
	}

	function Icon( { name = 'pin', size = 20 } ) {
		const paths = {
			pin: 'M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 10.25A3.25 3.25 0 1 1 12 5.75a3.25 3.25 0 0 1 0 6.5Z',
		search: 'M10.5 4a6.5 6.5 0 1 0 4.02 11.61L20 21.1l1.1-1.1-5.49-5.48A6.5 6.5 0 0 0 10.5 4Zm0 1.6a4.9 4.9 0 1 1 0 9.8 4.9 4.9 0 0 1 0-9.8Z',
		plus: 'M11.2 4h1.6v7.2H20v1.6h-7.2V20h-1.6v-7.2H4v-1.6h7.2V4Z',
		chevron: 'm8.7 6.8 5.2 5.2-5.2 5.2 1.1 1.1 6.3-6.3-6.3-6.3-1.1 1.1Z',
		trash: 'M8 5V3h8v2h5v1.6H3V5h5Zm-2 3h12l-.7 13H6.7L6 8Zm3 2v8h1.5v-8H9Zm4.5 0v8H15v-8h-1.5Z',
		edit: 'm16.9 3.2 3.9 3.9L8.2 19.7 3 21l1.3-5.2L16.9 3.2Zm0 2.3L5.7 16.7l-.5 2.1 2.1-.5L18.5 7.1l-1.6-1.6Z',
		clock: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 1.7a8.3 8.3 0 1 1 0 16.6 8.3 8.3 0 0 1 0-16.6Zm-.8 3.5h1.6v4.4l3.5 2-.8 1.4-4.3-2.5V7.2Z',
		building: 'M5 2h11v5h3v15H5V2Zm2 2v16h3v-3h2v3h2V4H7Zm2 3h2v2H9V7Zm4 0h2v2h-2V7Zm-4 4h2v2H9v-2Zm4 0h2v2h-2v-2Z',
		filter: 'M3 5h18l-7 8v6l-4 2v-8L3 5Zm3.5 1.6 5.1 5.8v5.9l.8-.4v-5.5l5.1-5.8h-11Z',
		more: 'M6 10a2 2 0 1 1 0 4 2 2 0 0 1 0-4Zm6 0a2 2 0 1 1 0 4 2 2 0 0 1 0-4Zm6 0a2 2 0 1 1 0 4 2 2 0 0 1 0-4Z',
		image: 'M4 4h16v16H4V4Zm1.6 1.6v10.6l3.8-3.8 2.9 2.9 1.9-1.9 4.2 4.2v-12H5.6Zm3.2 2.2a2 2 0 1 1 0 4 2 2 0 0 1 0-4Z',
		check: 'm5 12.5 4.1 4.1L19.3 6.4l1.2 1.2L9.1 19 3.8 13.7 5 12.5Z',
		close: 'm6.3 5.2 5.7 5.7 5.7-5.7 1.1 1.1-5.7 5.7 5.7 5.7-1.1 1.1-5.7-5.7-5.7 5.7-1.1-1.1 5.7-5.7-5.7-5.7 1.1-1.1Z',
	};
		return h( 'svg', { width: size, height: size, viewBox: '0 0 24 24', fill: 'currentColor', 'aria-hidden': 'true', focusable: 'false' },
			h( 'path', { d: paths[ name ] || paths.pin } )
		);
	}

	function MarkerGlyph( { icon = 'pin', color = '#2563eb', iconColor = '#ffffff', size = 'medium' } ) {
		const glyphs = {
			pin: '•', office: 'O', store: 'S', building: 'B', 'shopping-bag': 'S', warehouse: 'W', service: 'S', tools: 'T', clinic: '+', education: 'E', restaurant: 'R', atm: '$', dealer: 'D', star: '★',
		};
		return h( 'span', {
			className: cx( 'vml-marker-glyph', `is-${ size }` ),
			style: { '--vml-marker-color': color || '#2563eb', '--vml-marker-icon-color': iconColor || '#ffffff' },
			'aria-hidden': 'true',
		}, h( 'span', null, glyphs[ icon ] || '•' ) );
	}

	function AppShell( { children } ) {
		const route = boot.route || 'overview';
		const nav = [
			{ id: 'overview', label: __( 'Overview', 'velox-map-locator' ), url: boot.urls && boot.urls.overview },
			{ id: 'locations', label: __( 'Locations', 'velox-map-locator' ), url: boot.urls && boot.urls.locations },
			{ id: 'locators', label: __( 'Locators', 'velox-map-locator' ), url: boot.urls && boot.urls.locators },
			{ id: 'classifications', label: __( 'Types & Groups', 'velox-map-locator' ), url: boot.urls && boot.urls.classifications },
			boot.capabilities && boot.capabilities.manageProviders ? { id: 'providers', label: __( 'Map Providers', 'velox-map-locator' ), url: boot.urls && boot.urls.providers } : null,
			boot.capabilities && ( boot.capabilities.importLocations || boot.capabilities.exportLocations ) ? { id: 'import-export', label: __( 'Import / Export', 'velox-map-locator' ), url: boot.urls && boot.urls.importExport } : null,
			boot.capabilities && boot.capabilities.manageSettings ? { id: 'settings', label: __( 'Settings', 'velox-map-locator' ), url: boot.urls && boot.urls.settings } : null,
			{ id: 'help', label: __( 'Help', 'velox-map-locator' ), url: boot.urls && boot.urls.help },
		].filter( Boolean );
		return h( 'div', {
			className: cx( 'vml-app', `vml-density-${ boot.adminDensity || 'comfortable' }` ),
			'data-vml-admin-theme': boot.adminAppearance || 'system',
		},
			h( 'header', { className: 'vml-app-header' },
				h( 'div', { className: 'vml-brand-row' },
					h( 'div', { className: 'vml-brand' },
						h( 'span', { className: 'vml-brand-mark' }, h( Icon, { name: 'pin', size: 20 } ) ),
						h( 'div', null,
							h( 'strong', null, __( 'Velox Map Locator', 'velox-map-locator' ) ),
							h( 'span', null, __( 'Location management', 'velox-map-locator' ) )
						)
					),
					h( 'span', { className: 'vml-build-badge' }, __( '1.0.0', 'velox-map-locator' ) )
				),
				h( 'nav', { className: 'vml-app-nav', 'aria-label': __( 'Velox Map Locator', 'velox-map-locator' ) },
					nav.map( ( item ) => h( 'a', { key: item.id, href: item.url, className: cx( 'vml-nav-link', route === item.id && 'is-active' ), 'aria-current': route === item.id ? 'page' : undefined }, item.label ) )
				)
			),
			h( 'main', { className: 'vml-app-main' }, children )
		);
	}

	function PageHeader( { title, description, children, eyebrow } ) {
		return h( 'div', { className: 'vml-page-header' },
			h( 'div', { className: 'vml-page-heading' },
				eyebrow && h( 'div', { className: 'vml-eyebrow' }, eyebrow ),
				h( 'h1', null, title ),
				description && h( 'p', null, description )
			),
			children && h( 'div', { className: 'vml-page-actions' }, children )
		);
	}

	function LoadingScreen() {
		return h( 'div', { className: 'vml-loading-screen' }, h( Spinner ), h( 'span', null, __( 'Loading Velox Map Locator…', 'velox-map-locator' ) ) );
	}

	function EmptyState( { title, description, action } ) {
		return h( 'div', { className: 'vml-empty-state' },
			h( 'span', { className: 'vml-empty-icon' }, h( Icon, { name: 'pin', size: 30 } ) ),
			h( 'h3', null, title ),
			h( 'p', null, description ),
			action
		);
	}

	function StatCard( { label, value, meta, icon } ) {
		return h( 'div', { className: 'vml-stat-card' },
			h( 'div', { className: 'vml-stat-icon' }, h( Icon, { name: icon || 'pin', size: 20 } ) ),
			h( 'div', null,
				h( 'strong', null, value ),
				h( 'span', null, label ),
				meta && h( 'small', null, meta )
			)
		);
	}

	function StatusBadge( { status, operational } ) {
		let label = status;
		let tone = 'neutral';
		if ( operational ) {
			const map = {
				normal: [ __( 'Normal', 'velox-map-locator' ), 'success' ],
				temporarily_closed: [ __( 'Temporarily closed', 'velox-map-locator' ), 'warning' ],
				coming_soon: [ __( 'Coming soon', 'velox-map-locator' ), 'info' ],
			};
			[ label, tone ] = map[ operational ] || [ operational, 'neutral' ];
		} else {
			const map = {
				publish: [ __( 'Published', 'velox-map-locator' ), 'success' ],
				draft: [ __( 'Draft', 'velox-map-locator' ), 'neutral' ],
				trash: [ __( 'Trash', 'velox-map-locator' ), 'danger' ],
				pending: [ __( 'Pending', 'velox-map-locator' ), 'warning' ],
				private: [ __( 'Private', 'velox-map-locator' ), 'info' ],
				future: [ __( 'Scheduled', 'velox-map-locator' ), 'info' ],
			};
			[ label, tone ] = map[ status ] || [ status, 'neutral' ];
		}
		return h( 'span', { className: cx( 'vml-status-badge', `is-${ tone }` ) }, h( 'span', { className: 'vml-status-dot', 'aria-hidden': 'true' } ), label );
	}

	function Overview() {
		const [ data, setData ] = useState( null );
		const [ error, setError ] = useState( '' );

		useEffect( () => {
			let alive = true;
			Promise.all( [
				fetchLocations( { per_page: 5, page: 1, orderby: 'modified', order: 'DESC' } ),
				fetchLocations( { per_page: 1, status: 'publish' } ),
				fetchLocations( { per_page: 1, status: 'draft' } ),
				apiFetch( { path: `${ namespace }/admin/types` } ),
				apiFetch( { path: `${ namespace }/admin/groups` } ),
				fetchLocators( { per_page: 1 } ),
			] ).then( ( [ all, published, drafts, types, groups, locators ] ) => {
				if ( alive ) setData( { all, published, drafts, types, groups, locators } );
			} ).catch( ( err ) => alive && setError( getErrorMessage( err ) ) );
			return () => { alive = false; };
		}, [] );

		return h( Fragment, null,
			h( PageHeader, {
				title: __( 'Overview', 'velox-map-locator' ),
				description: __( 'Manage the location data that will power your Velox locator experiences.', 'velox-map-locator' ),
			}, boot.capabilities && boot.capabilities.createLocations && h( Button, { variant: 'primary', href: boot.urls.addLocation, icon: h( Icon, { name: 'plus', size: 18 } ) }, __( 'Add Location', 'velox-map-locator' ) ) ),
			error && h( Notice, { status: 'error', isDismissible: false }, error ),
			! data ? h( LoadingScreen ) : h( Fragment, null,
				h( 'div', { className: 'vml-stats-grid' },
					h( StatCard, { label: __( 'Locations', 'velox-map-locator' ), value: data.all.total, meta: __( 'All active records', 'velox-map-locator' ), icon: 'pin' } ),
					h( StatCard, { label: __( 'Published', 'velox-map-locator' ), value: data.published.total, meta: __( 'Ready for locators', 'velox-map-locator' ), icon: 'check' } ),
					h( StatCard, { label: __( 'Location Types', 'velox-map-locator' ), value: data.types.length, meta: __( 'Classification options', 'velox-map-locator' ), icon: 'building' } ),
					h( StatCard, { label: __( 'Locators', 'velox-map-locator' ), value: data.locators.total, meta: __( 'Reusable experiences', 'velox-map-locator' ), icon: 'building' } )
				),
				h( 'div', { className: 'vml-dashboard-grid' },
					h( 'section', { className: 'vml-panel' },
						h( 'div', { className: 'vml-panel-header' },
							h( 'div', null, h( 'h2', null, __( 'Recent Locations', 'velox-map-locator' ) ), h( 'p', null, __( 'Recently updated records.', 'velox-map-locator' ) ) ),
							h( 'a', { href: boot.urls.locations, className: 'vml-text-link' }, __( 'View all', 'velox-map-locator' ), ' ', h( Icon, { name: 'chevron', size: 14 } ) )
					),
					data.all.items.length ? h( 'div', { className: 'vml-recent-list' }, data.all.items.map( ( item ) => h( 'a', { key: item.id, href: `${ boot.urls.editLocationBase }&location_id=${ item.id }`, className: 'vml-recent-item' },
						h( 'span', { className: 'vml-recent-marker' }, h( MarkerGlyph, { size: 'small' } ) ),
						h( 'span', { className: 'vml-recent-copy' }, h( 'strong', null, item.name || __( 'Untitled Location', 'velox-map-locator' ) ), h( 'small', null, item.address && ( item.address.city || item.address.display_address ) || __( 'No address yet', 'velox-map-locator' ) ) ),
						h( StatusBadge, { status: item.status } )
					) ) ) : h( EmptyState, { title: __( 'No locations yet', 'velox-map-locator' ), description: __( 'Add your first office, store, branch or other physical location.', 'velox-map-locator' ), action: boot.capabilities.createLocations && h( Button, { variant: 'primary', href: boot.urls.addLocation }, __( 'Add Location', 'velox-map-locator' ) ) } )
				),
					h( 'aside', { className: 'vml-panel vml-quick-start-panel' },
						h( 'div', { className: 'vml-panel-header' }, h( 'div', null, h( 'h2', null, __( 'Quick Start', 'velox-map-locator' ) ), h( 'p', null, __( 'Jump to the most common setup and management tasks.', 'velox-map-locator' ) ) ) ),
						h( 'div', { className: 'vml-quick-start-list' },
						boot.capabilities.createLocations && h( QuickStartLink, { href: boot.urls.addLocation, icon: 'plus', title: __( 'Add Location', 'velox-map-locator' ), description: __( 'Create a store, office, branch or other place.', 'velox-map-locator' ) } ),
						boot.capabilities.createLocators && h( QuickStartLink, { href: boot.urls.addLocator, icon: 'pin', title: __( 'Create Locator', 'velox-map-locator' ), description: __( 'Build a reusable map and location finder.', 'velox-map-locator' ) } ),
						boot.capabilities.manageProviders && h( QuickStartLink, { href: boot.urls.providers, icon: 'building', title: __( 'Map Providers', 'velox-map-locator' ), description: __( 'Configure OpenStreetMap or Google Maps.', 'velox-map-locator' ) } ),
						boot.capabilities.importLocations && h( QuickStartLink, { href: boot.urls.importExport, icon: 'plus', title: __( 'Import Locations', 'velox-map-locator' ), description: __( 'Bring location data into Velox from CSV.', 'velox-map-locator' ) } ),
						h( QuickStartLink, { href: boot.urls.help, icon: 'chevron', title: __( 'Help & Guide', 'velox-map-locator' ), description: __( 'Learn the recommended setup and publishing workflow.', 'velox-map-locator' ) } )
					)
				)
			)
		) );
	}

	function QuickStartLink( { href, icon, title, description } ) {
		return h( 'a', { href, className: 'vml-quick-start-link' },
			h( 'span', { className: 'vml-quick-start-icon', 'aria-hidden': 'true' }, h( Icon, { name: icon, size: 16 } ) ),
			h( 'span', { className: 'vml-quick-start-copy' }, h( 'strong', null, title ), h( 'small', null, description ) ),
			h( Icon, { name: 'chevron', size: 14 } )
		);
	}

	function LocationsList() {
		const [ result, setResult ] = useState( { items: [], total: 0, totalPages: 0 } );
		const [ types, setTypes ] = useState( [] );
		const [ groups, setGroups ] = useState( [] );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( '' );
		const [ notice, setNotice ] = useState( '' );
		const [ searchInput, setSearchInput ] = useState( '' );
		const [ filters, setFilters ] = useState( { search: '', status: '', type_id: '', group_id: '', page: 1 } );

		useEffect( () => {
			const timer = window.setTimeout( () => setFilters( ( current ) => ( { ...current, search: searchInput, page: 1 } ) ), 220 );
			return () => window.clearTimeout( timer );
		}, [ searchInput ] );

		const loadTerms = () => Promise.all( [ apiFetch( { path: `${ namespace }/admin/types` } ), apiFetch( { path: `${ namespace }/admin/groups` } ) ] ).then( ( [ nextTypes, nextGroups ] ) => { setTypes( nextTypes ); setGroups( nextGroups ); } );

		const loadLocations = () => {
			setLoading( true ); setError( '' );
			return fetchLocations( { ...filters, per_page: 20, orderby: 'modified', order: 'DESC' } )
				.then( setResult )
				.catch( ( err ) => setError( getErrorMessage( err ) ) )
				.finally( () => setLoading( false ) );
		};

		useEffect( () => { loadTerms().catch( ( err ) => setError( getErrorMessage( err ) ) ); }, [] );
		useEffect( () => { loadLocations(); }, [ filters.search, filters.status, filters.type_id, filters.group_id, filters.page ] );

		const typeMap = useMemo( () => Object.fromEntries( types.map( ( item ) => [ item.id, item ] ) ), [ types ] );

		const changeFilter = ( key, value ) => setFilters( ( current ) => ( { ...current, [ key ]: value, page: 1 } ) );
		const reset = () => { setSearchInput( '' ); setFilters( { search: '', status: '', type_id: '', group_id: '', page: 1 } ); };
		const hasFilters = Boolean( searchInput || filters.status || filters.type_id || filters.group_id );

		const trashLocation = async ( item ) => {
			if ( ! window.confirm( sprintf( __( 'Move “%s” to Trash?', 'velox-map-locator' ), item.name || __( 'Untitled Location', 'velox-map-locator' ) ) ) ) return;
			try {
				await apiFetch( { path: `${ namespace }/admin/locations/${ item.id }`, method: 'DELETE' } );
				setNotice( __( 'Location moved to Trash.', 'velox-map-locator' ) );
				loadLocations();
			} catch ( err ) { setError( getErrorMessage( err ) ); }
		};

		const restoreLocation = async ( item ) => {
			try {
				await apiFetch( { path: `${ namespace }/admin/locations/${ item.id }/restore`, method: 'POST' } );
				setNotice( __( 'Location restored.', 'velox-map-locator' ) );
				loadLocations();
			} catch ( err ) { setError( getErrorMessage( err ) ); }
		};

		const duplicateLocation = async ( item ) => {
			try {
				const duplicate = await apiFetch( { path: `${ namespace }/admin/locations/${ item.id }/duplicate`, method: 'POST' } );
				if ( duplicate && duplicate.id ) {
					window.location.assign( `${ boot.urls.editLocationBase }&location_id=${ duplicate.id }` );
					return;
				}
				setNotice( __( 'Location duplicated as a new Draft.', 'velox-map-locator' ) );
				await loadLocations();
			} catch ( err ) { setError( getErrorMessage( err ) ); }
		};

		return h( Fragment, null,
			h( PageHeader, { title: __( 'Locations', 'velox-map-locator' ), description: __( 'Manage the offices, stores, branches and other physical locations available to your locators.', 'velox-map-locator' ) },
			boot.capabilities && boot.capabilities.createLocations && h( Button, { variant: 'primary', href: boot.urls.addLocation, icon: h( Icon, { name: 'plus', size: 18 } ) }, __( 'Add Location', 'velox-map-locator' ) )
		),
			notice && h( Notice, { status: 'success', onRemove: () => setNotice( '' ) }, notice ),
			error && h( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ),
			h( 'section', { className: 'vml-panel vml-locations-panel' },
				h( 'div', { className: 'vml-filter-bar' },
					h( 'label', { className: 'vml-search-field' }, h( 'span', { className: 'screen-reader-text' }, __( 'Search locations', 'velox-map-locator' ) ), h( Icon, { name: 'search', size: 18 } ), h( 'input', { type: 'search', value: searchInput, placeholder: __( 'Search locations…', 'velox-map-locator' ), onChange: ( event ) => setSearchInput( event.target.value ) } ) ),
					h( FilterSelect, { label: __( 'Status', 'velox-map-locator' ), value: filters.status, onChange: ( value ) => changeFilter( 'status', value ), options: [ [ '', __( 'All statuses', 'velox-map-locator' ) ], [ 'publish', __( 'Published', 'velox-map-locator' ) ], [ 'draft', __( 'Draft', 'velox-map-locator' ) ], [ 'trash', __( 'Trash', 'velox-map-locator' ) ] ] } ),
					h( FilterSelect, { label: __( 'Type', 'velox-map-locator' ), value: filters.type_id, onChange: ( value ) => changeFilter( 'type_id', value ), options: [ [ '', __( 'All types', 'velox-map-locator' ) ], ...types.map( ( item ) => [ String( item.id ), item.name ] ) ] } ),
					h( FilterSelect, { label: __( 'Group', 'velox-map-locator' ), value: filters.group_id, onChange: ( value ) => changeFilter( 'group_id', value ), options: [ [ '', __( 'All groups', 'velox-map-locator' ) ], ...groups.map( ( item ) => [ String( item.id ), item.name ] ) ] } ),
					hasFilters && h( Button, { variant: 'tertiary', onClick: reset }, __( 'Reset', 'velox-map-locator' ) )
			),
				h( 'div', { className: 'vml-table-summary' }, h( 'strong', null, sprintf( _n( '%d location', '%d locations', result.total, 'velox-map-locator' ), result.total ) ), loading && h( Spinner ) ),
				loading && ! result.items.length ? h( LoadingScreen ) : result.items.length ? h( Fragment, null,
					h( 'div', { className: 'vml-table-scroll' },
						h( 'table', { className: 'vml-data-table' },
							h( 'thead', null, h( 'tr', null,
								h( 'th', null, __( 'Location', 'velox-map-locator' ) ),
								h( 'th', null, __( 'Type', 'velox-map-locator' ) ),
								h( 'th', { className: 'vml-col-city' }, __( 'City', 'velox-map-locator' ) ),
								h( 'th', null, __( 'Operational', 'velox-map-locator' ) ),
								h( 'th', null, __( 'Publication', 'velox-map-locator' ) ),
								h( 'th', { className: 'vml-col-updated' }, __( 'Updated', 'velox-map-locator' ) ),
								h( 'th', { className: 'vml-actions-cell' }, h( 'span', { className: 'screen-reader-text' }, __( 'Actions', 'velox-map-locator' ) ) )
						) ),
							h( 'tbody', null, result.items.map( ( item ) => h( LocationRow, { key: item.id, item, typeMap, onTrash: trashLocation, onRestore: restoreLocation, onDuplicate: duplicateLocation } ) ) )
						)
					),
					h( 'div', { className: 'vml-location-cards' }, result.items.map( ( item ) => h( LocationCard, { key: item.id, item, typeMap, onTrash: trashLocation, onRestore: restoreLocation, onDuplicate: duplicateLocation } ) ) )
				) : h( EmptyState, { title: hasFilters ? __( 'No locations match your filters', 'velox-map-locator' ) : __( 'No locations yet', 'velox-map-locator' ), description: hasFilters ? __( 'Try a different search or clear the current filters.', 'velox-map-locator' ) : __( 'Add your first office, store, branch or other physical location.', 'velox-map-locator' ), action: hasFilters ? h( Button, { variant: 'secondary', onClick: reset }, __( 'Clear Filters', 'velox-map-locator' ) ) : boot.capabilities.createLocations && h( Button, { variant: 'primary', href: boot.urls.addLocation }, __( 'Add Location', 'velox-map-locator' ) ) } ),
				result.totalPages > 1 && h( Pagination, { page: filters.page, pages: result.totalPages, onChange: ( page ) => setFilters( ( current ) => ( { ...current, page } ) ) } )
			)
		);
	}

		function FilterSelect( { label, value, onChange, options } ) {
		return h( 'label', { className: 'vml-filter-select' }, h( 'span', { className: 'screen-reader-text' }, label ), h( 'select', { value, onChange: ( event ) => onChange( event.target.value ) }, options.map( ( option ) => h( 'option', { key: option[ 0 ], value: option[ 0 ] }, option[ 1 ] ) ) ) );
	}

	function LocationRow( { item, typeMap, onTrash, onRestore, onDuplicate } ) {
		const primaryType = typeMap[ item.primary_type_id ] || typeMap[ item.type_ids && item.type_ids[ 0 ] ];
		const marker = item.marker && item.marker.override ? item.marker : ( primaryType && primaryType.marker || {} );
		const editUrl = `${ boot.urls.editLocationBase }&location_id=${ item.id }`;
		return h( 'tr', null,
			h( 'td', { className: 'vml-location-cell' },
				h( 'span', { className: 'vml-row-marker' }, h( MarkerGlyph, { icon: marker.icon || 'pin', color: marker.color || '#2563eb', iconColor: marker.icon_color || '#ffffff', size: 'small' } ) ),
				h( 'span', { className: 'vml-location-copy' },
					h( 'a', { href: editUrl, className: 'vml-location-name' }, item.name || __( 'Untitled Location', 'velox-map-locator' ) ),
					h( 'small', null, item.address && ( item.address.display_address || item.address.line_1 ) || __( 'No address yet', 'velox-map-locator' ) )
			)
		),
			h( 'td', null, primaryType ? h( 'span', { className: 'vml-subtle-chip' }, primaryType.name ) : '—' ),
			h( 'td', { className: 'vml-col-city' }, item.address && item.address.city || '—' ),
			h( 'td', null, h( StatusBadge, { operational: item.operational && item.operational.status || 'normal' } ) ),
			h( 'td', null, h( StatusBadge, { status: item.status } ) ),
			h( 'td', { className: 'vml-muted-cell vml-col-updated' }, formatDate( item.modified_gmt ) ),
			h( 'td', { className: 'vml-actions-cell' },
				item.status === 'trash' ? h( Button, { variant: 'tertiary', onClick: () => onRestore( item ) }, __( 'Restore', 'velox-map-locator' ) ) : h( Fragment, null,
					h( Button, { variant: 'tertiary', href: editUrl, label: __( 'Edit location', 'velox-map-locator' ), icon: h( Icon, { name: 'edit', size: 17 } ) } ),
					boot.capabilities.createLocations && h( Button, { variant: 'tertiary', onClick: () => onDuplicate( item ), label: __( 'Duplicate location', 'velox-map-locator' ), icon: h( Icon, { name: 'plus', size: 17 } ) } ),
					boot.capabilities.deleteLocations && h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => onTrash( item ), label: __( 'Move location to Trash', 'velox-map-locator' ), icon: h( Icon, { name: 'trash', size: 17 } ) } )
				)
		)
		);
	}

	function LocationCard( { item, typeMap, onTrash, onRestore, onDuplicate } ) {
		const primaryType = typeMap[ item.primary_type_id ] || typeMap[ item.type_ids && item.type_ids[ 0 ] ];
		const marker = item.marker && item.marker.override ? item.marker : ( primaryType && primaryType.marker || {} );
		const editUrl = `${ boot.urls.editLocationBase }&location_id=${ item.id }`;
		const address = item.address && ( item.address.display_address || item.address.line_1 ) || __( 'No address yet', 'velox-map-locator' );

		return h( 'article', { className: 'vml-location-card' },
			h( 'div', { className: 'vml-location-card-main' },
				h( 'span', { className: 'vml-location-card-marker' }, h( MarkerGlyph, { icon: marker.icon || 'pin', color: marker.color || '#2563eb', iconColor: marker.icon_color || '#ffffff', size: 'small' } ) ),
				h( 'div', { className: 'vml-location-card-copy' },
					h( 'a', { href: editUrl, className: 'vml-location-card-title' }, item.name || __( 'Untitled Location', 'velox-map-locator' ) ),
					h( 'p', null, address ),
					primaryType && h( 'span', { className: 'vml-subtle-chip' }, primaryType.name )
				)
			),
			h( 'div', { className: 'vml-location-card-statuses' },
				h( StatusBadge, { operational: item.operational && item.operational.status || 'normal' } ),
				h( StatusBadge, { status: item.status } )
			),
			h( 'div', { className: 'vml-location-card-actions' },
				item.status === 'trash' ? h( Button, { variant: 'secondary', onClick: () => onRestore( item ) }, __( 'Restore', 'velox-map-locator' ) ) : h( Fragment, null,
					h( Button, { variant: 'secondary', href: editUrl, icon: h( Icon, { name: 'edit', size: 16 } ) }, __( 'Edit', 'velox-map-locator' ) ),
					boot.capabilities.createLocations && h( Button, { variant: 'tertiary', onClick: () => onDuplicate( item ), icon: h( Icon, { name: 'plus', size: 16 } ) }, __( 'Duplicate', 'velox-map-locator' ) ),
					boot.capabilities.deleteLocations && h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => onTrash( item ), icon: h( Icon, { name: 'trash', size: 16 } ), label: __( 'Move location to Trash', 'velox-map-locator' ) } )
				)
			)
		);
	}

	function Pagination( { page, pages, onChange } ) {
		const candidates = [];
		for ( let current = Math.max( 1, page - 2 ); current <= Math.min( pages, page + 2 ); current++ ) candidates.push( current );
		return h( 'nav', { className: 'vml-pagination', 'aria-label': __( 'Locations pagination', 'velox-map-locator' ) },
			h( Button, { variant: 'secondary', disabled: page <= 1, onClick: () => onChange( page - 1 ) }, __( 'Previous', 'velox-map-locator' ) ),
			candidates.map( ( current ) => h( Button, { key: current, variant: current === page ? 'primary' : 'tertiary', onClick: () => onChange( current ), 'aria-current': current === page ? 'page' : undefined }, String( current ) ) ),
			h( Button, { variant: 'secondary', disabled: page >= pages, onClick: () => onChange( page + 1 ) }, __( 'Next', 'velox-map-locator' ) )
		);
	}

	function defaultWeek() {
		return Object.fromEntries( days.map( ( day ) => [ day, { closed: true, all_day: false, intervals: [] } ] ) );
	}

	function defaultLocation() {
		return {
			id: 0,
			name: '', description: '', status: 'draft', menu_order: 0, featured_image_id: 0,
			address: { line_1: '', line_2: '', city: '', region: '', postal_code: '', country_code: '', display_address: '', latitude: null, longitude: null, timezone: '' },
			contact: { phone: '', email: '', website: '', contact_name: '', directions_url: '' },
			weekly_hours: {}, special_hours: [], operational: { status: 'normal', label: '', note: '' },
			type_ids: [], group_ids: [], primary_type_id: 0,
			marker: { override: false, icon: 'pin', media_id: 0, color: '', icon_color: '', size: 'medium' },
			extra_fields: [], external_id: '',
		};
	}

	function LocationEditor() {
		const requestedId = Number( boot.locationId || 0 );
		const [ location, setLocation ] = useState( defaultLocation() );
		const [ initial, setInitial ] = useState( null );
		const [ types, setTypes ] = useState( [] );
		const [ groups, setGroups ] = useState( [] );
		const [ loading, setLoading ] = useState( true );
		const [ saving, setSaving ] = useState( false );
		const [ savedLabel, setSavedLabel ] = useState( '' );
		const [ error, setError ] = useState( '' );
		const [ errorField, setErrorField ] = useState( '' );
		const [ hoursEnabled, setHoursEnabled ] = useState( false );
		const [ termDialog, setTermDialog ] = useState( null );
		const [ mediaUrls, setMediaUrls ] = useState( { featured: '', marker: '' } );
		const [ geocoding, setGeocoding ] = useState( false );
		const [ geocodeStatus, setGeocodeStatus ] = useState( '' );
		const sectionRefs = useRef( {} );
		const allowLeaveRef = useRef( false );

		const loadTerms = async () => {
			const [ nextTypes, nextGroups ] = await Promise.all( [ apiFetch( { path: `${ namespace }/admin/types` } ), apiFetch( { path: `${ namespace }/admin/groups` } ) ] );
			setTypes( nextTypes ); setGroups( nextGroups );
			return { types: nextTypes, groups: nextGroups };
		};

		useEffect( () => {
			let alive = true;
			( async () => {
				try {
					await loadTerms();
					let next = defaultLocation();
					if ( requestedId ) next = await apiFetch( { path: `${ namespace }/admin/locations/${ requestedId }` } );
					if ( ! alive ) return;
					if ( ! next.weekly_hours || ! Object.keys( next.weekly_hours ).length ) next.weekly_hours = defaultWeek();
					setHoursEnabled( Boolean( requestedId && next.weekly_hours && Object.values( next.weekly_hours ).some( ( value ) => value && ( ! value.closed || value.all_day || ( value.intervals && value.intervals.length ) ) ) ) || Boolean( requestedId && next.weekly_hours && Object.keys( next.weekly_hours ).length && JSON.stringify( next.weekly_hours ) !== JSON.stringify( defaultWeek() ) ) );
					setLocation( next ); setInitial( clone( next ) );
					loadMediaPreview( next.featured_image_id, 'featured' );
					loadMediaPreview( next.marker && next.marker.media_id, 'marker' );
				} catch ( err ) { if ( alive ) setError( getErrorMessage( err ) ); }
				finally { if ( alive ) setLoading( false ); }
			} )();
			return () => { alive = false; };
		}, [ requestedId ] );

		const comparable = ( value ) => {
			const next = clone( value || defaultLocation() );
			delete next.modified_gmt; delete next.geocode_source; delete next.geocoded_at;
			if ( ! hoursEnabled ) next.weekly_hours = {};
			return next;
		};
		const dirty = initial ? JSON.stringify( comparable( location ) ) !== JSON.stringify( comparable( initial ) ) : false;

		useEffect( () => {
			const handler = ( event ) => { if ( dirty && ! allowLeaveRef.current ) { event.preventDefault(); event.returnValue = ''; } };
			window.addEventListener( 'beforeunload', handler );
			return () => window.removeEventListener( 'beforeunload', handler );
		}, [ dirty ] );

		const update = ( path, value ) => {
			setLocation( ( current ) => {
				const next = clone( current );
				const parts = path.split( '.' );
				let cursor = next;
				parts.slice( 0, -1 ).forEach( ( part ) => { if ( ! cursor[ part ] || typeof cursor[ part ] !== 'object' ) cursor[ part ] = {}; cursor = cursor[ part ]; } );
				cursor[ parts[ parts.length - 1 ] ] = value;
				return next;
			} );
			setSavedLabel( '' );
			if ( errorField && ( errorField === path || errorField.startsWith( path ) || path.startsWith( errorField ) ) ) { setError( '' ); setErrorField( '' ); }
		};

		const findOnMap = async () => {
			const googleConfig = boot.googleMaps || {};
			const address = location.address || {};
			const query = String( address.display_address || [ address.line_1, address.line_2, address.city, address.region, address.postal_code, address.country_code ].filter( Boolean ).join( ', ' ) ).trim();
			if ( ! googleConfig.configured ) {
				setGeocodeStatus( __( 'Configure Google Maps under Map Providers before using Find on Map.', 'velox-map-locator' ) );
				return;
			}
			if ( ! query ) {
				setGeocodeStatus( __( 'Enter an address before using Find on Map.', 'velox-map-locator' ) );
				return;
			}
			setGeocoding( true ); setGeocodeStatus( '' );
			try {
				const maps = await loadGoogleAdmin( googleConfig );
				const { Geocoder } = await maps.importLibrary( 'geocoding' );
				const request = { address: query };
				if ( /^[A-Za-z]{2}$/.test( String( address.country_code || '' ) ) ) request.componentRestrictions = { country: String( address.country_code ).toUpperCase() };
				const response = await ( new Geocoder() ).geocode( request );
				const result = response && response.results && response.results[ 0 ];
				if ( ! result || ! result.geometry || ! result.geometry.location ) throw new Error( __( 'Google Maps could not find coordinates for this address.', 'velox-map-locator' ) );
				const latitude = result.geometry.location.lat();
				const longitude = result.geometry.location.lng();
				update( 'address.latitude', Number( latitude.toFixed( 7 ) ) );
				update( 'address.longitude', Number( longitude.toFixed( 7 ) ) );
				setGeocodeStatus( result.formatted_address ? sprintf( __( 'Coordinates found for %s', 'velox-map-locator' ), result.formatted_address ) : __( 'Coordinates found. Review them before saving.', 'velox-map-locator' ) );
			} catch ( err ) {
				setGeocodeStatus( getErrorMessage( err ) );
			} finally { setGeocoding( false ); }
		};

		const save = async ( targetStatus ) => {
			if ( saving ) return;
			setSaving( true ); setError( '' ); setErrorField( '' ); setSavedLabel( '' );
			try {
				const payload = clone( location );
				payload.status = targetStatus || location.status || 'draft';
				payload.weekly_hours = hoursEnabled ? payload.weekly_hours : {};
				delete payload.id; delete payload.modified_gmt; delete payload.geocode_source; delete payload.geocoded_at;
				const next = await apiFetch( { path: requestedId ? `${ namespace }/admin/locations/${ requestedId }` : `${ namespace }/admin/locations`, method: requestedId ? 'PUT' : 'POST', data: payload } );
				if ( ! requestedId ) {
					allowLeaveRef.current = true;
					window.location.href = `${ boot.urls.editLocationBase }&location_id=${ next.id }&velomalo_saved=1`;
					return;
				}
				if ( ! next.weekly_hours || ! Object.keys( next.weekly_hours ).length ) next.weekly_hours = defaultWeek();
				setLocation( next ); setInitial( clone( next ) ); setSavedLabel( __( 'Saved', 'velox-map-locator' ) );
			} catch ( err ) {
				setError( getErrorMessage( err ) ); setErrorField( getErrorField( err ) );
				window.setTimeout( () => {
					const field = getErrorField( err );
					const section = field.startsWith( 'address' ) ? 'address' : field.startsWith( 'contact' ) ? 'contact' : field.startsWith( 'weekly_hours' ) || field.startsWith( 'special_hours' ) ? 'hours' : field.startsWith( 'marker' ) ? 'appearance' : field.startsWith( 'type' ) || field.startsWith( 'group' ) || field === 'primary_type_id' ? 'classification' : 'general';
					if ( sectionRefs.current[ section ] ) sectionRefs.current[ section ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}, 50 );
			} finally { setSaving( false ); }
		};

		useEffect( () => {
			const handler = ( event ) => {
				if ( ( event.ctrlKey || event.metaKey ) && event.key.toLowerCase() === 's' ) { event.preventDefault(); save( location.status === 'publish' ? 'publish' : 'draft' ); }
			};
			window.addEventListener( 'keydown', handler );
			return () => window.removeEventListener( 'keydown', handler );
		} );

		const selectMedia = ( kind ) => {
			if ( ! wp.media ) return;
			const frame = wp.media( { title: kind === 'featured' ? __( 'Choose Location Image', 'velox-map-locator' ) : __( 'Choose Custom Marker', 'velox-map-locator' ), button: { text: __( 'Use this image', 'velox-map-locator' ) }, multiple: false, library: { type: 'image' } } );
			frame.on( 'select', () => {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				const url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
				if ( kind === 'featured' ) update( 'featured_image_id', attachment.id ); else update( 'marker.media_id', attachment.id );
				setMediaUrls( ( current ) => ( { ...current, [ kind ]: url } ) );
			} );
			frame.open();
		};

		const loadMediaPreview = ( id, kind ) => {
			if ( ! id || ! wp.media || ! wp.media.attachment ) return;
			const attachment = wp.media.attachment( id );
			attachment.fetch().then( () => { const json = attachment.toJSON(); const url = json.sizes && json.sizes.thumbnail ? json.sizes.thumbnail.url : json.url; setMediaUrls( ( current ) => ( { ...current, [ kind ]: url || '' } ) ); } ).catch( () => {} );
		};

		const onTermCreated = async ( kind, term ) => {
			await loadTerms();
			if ( kind === 'type' ) {
				const ids = Array.from( new Set( [ ...( location.type_ids || [] ), term.id ] ) ); update( 'type_ids', ids ); if ( ! location.primary_type_id ) update( 'primary_type_id', term.id );
			} else { update( 'group_ids', Array.from( new Set( [ ...( location.group_ids || [] ), term.id ] ) ) ); }
			setTermDialog( null );
		};

		if ( loading ) return h( Fragment, null, h( PageHeader, { title: requestedId ? __( 'Edit Location', 'velox-map-locator' ) : __( 'Add Location', 'velox-map-locator' ), description: __( 'Loading location data…', 'velox-map-locator' ) } ), h( LoadingScreen ) );

		const selectedPrimary = types.find( ( item ) => item.id === Number( location.primary_type_id ) );
		const inheritedMarker = selectedPrimary && selectedPrimary.marker || { icon: 'pin', color: '#2563eb', icon_color: '#ffffff' };
		const displayMarker = location.marker.override ? location.marker : inheritedMarker;
		const canPublish = boot.capabilities && boot.capabilities.publishLocations;
		const isPublished = location.status === 'publish';

		return h( Fragment, null,
			h( PageHeader, { eyebrow: h( 'a', { href: boot.urls.locations, className: 'vml-back-link' }, '← ', __( 'Locations', 'velox-map-locator' ) ), title: location.name || ( requestedId ? __( 'Untitled Location', 'velox-map-locator' ) : __( 'Add Location', 'velox-map-locator' ) ), description: requestedId ? sprintf( __( 'Location #%d', 'velox-map-locator' ), requestedId ) : __( 'Create a new location record.', 'velox-map-locator' ) },
			h( 'div', { className: 'vml-save-actions' }, savedLabel && h( 'span', { className: 'vml-saved-state' }, h( Icon, { name: 'check', size: 15 } ), savedLabel ), dirty && ! savedLabel && h( 'span', { className: 'vml-dirty-state' }, __( 'Unsaved changes', 'velox-map-locator' ) ),
				! isPublished && h( Button, { variant: 'secondary', isBusy: saving, disabled: saving, onClick: () => save( 'draft' ) }, __( 'Save Draft', 'velox-map-locator' ) ),
				isPublished && h( Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: () => save( 'publish' ) }, __( 'Save Changes', 'velox-map-locator' ) ),
				! isPublished && canPublish && h( Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: () => save( 'publish' ) }, __( 'Publish', 'velox-map-locator' ) )
			)
		),
			error && h( Notice, { status: 'error', isDismissible: true, onRemove: () => { setError( '' ); setErrorField( '' ); } }, errorField ? h( Fragment, null, h( 'strong', null, __( 'Please review the highlighted section. ', 'velox-map-locator' ) ), error ) : error ),
			h( 'div', { className: 'vml-editor-layout' },
				h( 'nav', { className: 'vml-section-nav', 'aria-label': __( 'Location editor sections', 'velox-map-locator' ) }, [
					[ 'general', __( 'General', 'velox-map-locator' ) ], [ 'address', __( 'Address & Position', 'velox-map-locator' ) ], [ 'contact', __( 'Contact', 'velox-map-locator' ) ], [ 'hours', __( 'Business Hours', 'velox-map-locator' ) ], [ 'classification', __( 'Classification', 'velox-map-locator' ) ], [ 'appearance', __( 'Appearance', 'velox-map-locator' ) ], [ 'additional', __( 'Additional Information', 'velox-map-locator' ) ], [ 'status', __( 'Status', 'velox-map-locator' ) ],
			].map( ( [ id, label ] ) => h( 'button', { key: id, type: 'button', className: cx( errorField && sectionHasError( id, errorField ) && 'has-error' ), onClick: () => sectionRefs.current[ id ] && sectionRefs.current[ id ].scrollIntoView( { behavior: 'smooth', block: 'start' } ) }, label ) ) ),
				h( 'div', { className: 'vml-editor-content' },
					h( EditorSection, { id: 'general', title: __( 'General', 'velox-map-locator' ), description: __( 'Core identity and descriptive information.', 'velox-map-locator' ), sectionRefs },
						h( Field, { label: __( 'Location Name', 'velox-map-locator' ), required: isPublished, error: fieldMatches( errorField, 'name' ) }, h( 'input', { type: 'text', value: location.name, maxLength: 200, onChange: ( event ) => update( 'name', event.target.value ), placeholder: __( 'e.g. Dubai Head Office', 'velox-map-locator' ) } ) ),
						h( Field, { label: __( 'Short Description', 'velox-map-locator' ), hint: __( 'Optional. Keep this concise; locators may use it in expanded details.', 'velox-map-locator' ) }, h( 'textarea', { rows: 4, value: location.description, maxLength: 2000, onChange: ( event ) => update( 'description', event.target.value ) } ) ),
						h( 'div', { className: 'vml-field-grid two' },
							h( Field, { label: __( 'External ID', 'velox-map-locator' ), hint: __( 'Optional unique ID for future imports and integrations.', 'velox-map-locator' ), error: fieldMatches( errorField, 'external_id' ) }, h( 'input', { type: 'text', value: location.external_id || '', onChange: ( event ) => update( 'external_id', event.target.value ), placeholder: 'DXB-001' } ) ),
							h( Field, { label: __( 'Manual Sort Order', 'velox-map-locator' ), hint: __( 'Lower values appear first when manual ordering is used.', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 0, value: location.menu_order || 0, onChange: ( event ) => update( 'menu_order', Number( event.target.value || 0 ) ) } ) )
					),
						h( MediaField, { label: __( 'Location Image', 'velox-map-locator' ), imageUrl: mediaUrls.featured, onChoose: () => selectMedia( 'featured' ), onRemove: () => { update( 'featured_image_id', 0 ); setMediaUrls( ( current ) => ( { ...current, featured: '' } ) ); } } )
				),
					h( EditorSection, { id: 'address', title: __( 'Address & Position', 'velox-map-locator' ), description: __( 'Structured address data and precise map coordinates.', 'velox-map-locator' ), sectionRefs, hasError: errorField.startsWith( 'address' ) },
						h( Field, { label: __( 'Address Line 1', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: location.address.line_1, onChange: ( event ) => update( 'address.line_1', event.target.value ) } ) ),
						h( Field, { label: __( 'Address Line 2', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: location.address.line_2, onChange: ( event ) => update( 'address.line_2', event.target.value ) } ) ),
						h( 'div', { className: 'vml-field-grid two' },
							h( Field, { label: __( 'City', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: location.address.city, onChange: ( event ) => update( 'address.city', event.target.value ) } ) ),
							h( Field, { label: __( 'Region / State', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: location.address.region, onChange: ( event ) => update( 'address.region', event.target.value ) } ) ),
							h( Field, { label: __( 'Postal Code', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: location.address.postal_code, onChange: ( event ) => update( 'address.postal_code', event.target.value ) } ) ),
							h( Field, { label: __( 'Country Code', 'velox-map-locator' ), hint: __( 'Two-letter ISO code, e.g. AE, GB or US.', 'velox-map-locator' ), error: fieldMatches( errorField, 'address.country_code' ) }, h( 'input', { type: 'text', value: location.address.country_code, maxLength: 2, onChange: ( event ) => update( 'address.country_code', event.target.value.toUpperCase() ), placeholder: 'AE' } ) )
					),
						h( Field, { label: __( 'Display Address', 'velox-map-locator' ), hint: __( 'Optional formatted address shown to visitors instead of assembling the structured fields.', 'velox-map-locator' ) }, h( 'textarea', { rows: 2, value: location.address.display_address, onChange: ( event ) => update( 'address.display_address', event.target.value ) } ) ),
						h( 'div', { className: 'vml-geocode-panel' },
							h( 'div', null, h( 'strong', null, __( 'Find coordinates with Google Maps', 'velox-map-locator' ) ), h( 'span', null, boot.googleMaps && boot.googleMaps.configured ? __( 'Uses the address above only when you click Find on Map. No autocomplete requests are made while typing.', 'velox-map-locator' ) : __( 'Google Maps is not configured yet. Manual latitude/longitude entry remains available.', 'velox-map-locator' ) ) ),
							boot.googleMaps && boot.googleMaps.configured ? h( Button, { type: 'button', variant: 'secondary', isBusy: geocoding, disabled: geocoding, onClick: findOnMap }, __( 'Find on Map', 'velox-map-locator' ) ) : boot.urls && boot.urls.providers && h( 'a', { href: boot.urls.providers }, __( 'Configure Google Maps', 'velox-map-locator' ) ),
							geocodeStatus && h( 'small', { className: 'vml-geocode-status' }, geocodeStatus )
						),
						h( 'div', { className: 'vml-field-grid two' },
							h( Field, { label: __( 'Latitude', 'velox-map-locator' ), required: isPublished, error: fieldMatches( errorField, 'address.latitude' ) || errorField === 'address' }, h( 'input', { type: 'number', step: 'any', min: -90, max: 90, value: location.address.latitude === null ? '' : location.address.latitude, onChange: ( event ) => update( 'address.latitude', event.target.value === '' ? null : Number( event.target.value ) ) } ) ),
							h( Field, { label: __( 'Longitude', 'velox-map-locator' ), required: isPublished, error: fieldMatches( errorField, 'address.longitude' ) || errorField === 'address' }, h( 'input', { type: 'number', step: 'any', min: -180, max: 180, value: location.address.longitude === null ? '' : location.address.longitude, onChange: ( event ) => update( 'address.longitude', event.target.value === '' ? null : Number( event.target.value ) ) } ) )
					),
						h( Field, { label: __( 'Timezone', 'velox-map-locator' ), hint: __( 'Used for accurate opening/closing status across regions.', 'velox-map-locator' ), error: fieldMatches( errorField, 'address.timezone' ) }, h( 'input', { type: 'text', list: 'vml-timezones', value: location.address.timezone, onChange: ( event ) => update( 'address.timezone', event.target.value ), placeholder: 'Asia/Dubai' } ), h( 'datalist', { id: 'vml-timezones' }, ( boot.timezones || [] ).map( ( timezone ) => h( 'option', { key: timezone, value: timezone } ) ) ) )
				),
					h( EditorSection, { id: 'contact', title: __( 'Contact', 'velox-map-locator' ), description: __( 'Optional public-facing contact details.', 'velox-map-locator' ), sectionRefs, hasError: errorField.startsWith( 'contact' ) },
						h( 'div', { className: 'vml-field-grid two' },
							h( Field, { label: __( 'Phone', 'velox-map-locator' ) }, h( 'input', { type: 'tel', value: location.contact.phone, onChange: ( event ) => update( 'contact.phone', event.target.value ) } ) ),
							h( Field, { label: __( 'Email', 'velox-map-locator' ), error: fieldMatches( errorField, 'contact.email' ) }, h( 'input', { type: 'email', value: location.contact.email, onChange: ( event ) => update( 'contact.email', event.target.value ) } ) ),
							h( Field, { label: __( 'Website', 'velox-map-locator' ), hint: __( 'Use a complete https:// URL.', 'velox-map-locator' ), error: fieldMatches( errorField, 'contact.website' ) }, h( 'input', { type: 'url', value: location.contact.website, onChange: ( event ) => update( 'contact.website', event.target.value ), placeholder: 'https://example.com' } ) ),
							h( Field, { label: __( 'Contact Person', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: location.contact.contact_name, onChange: ( event ) => update( 'contact.contact_name', event.target.value ) } ) )
					),
						h( Field, { label: __( 'Custom Directions URL', 'velox-map-locator' ), hint: __( 'Optional. Overrides the generated directions link for this Location.', 'velox-map-locator' ), error: fieldMatches( errorField, 'contact.directions_url' ) }, h( 'input', { type: 'url', value: location.contact.directions_url, onChange: ( event ) => update( 'contact.directions_url', event.target.value ), placeholder: 'https://…' } ) )
				),
					h( EditorSection, { id: 'hours', title: __( 'Business Hours', 'velox-map-locator' ), description: __( 'Structured weekly hours with optional special-date overrides.', 'velox-map-locator' ), sectionRefs, hasError: errorField.startsWith( 'weekly_hours' ) || errorField.startsWith( 'special_hours' ) },
						h( 'label', { className: 'vml-switch-row' }, h( 'input', { type: 'checkbox', checked: hoursEnabled, onChange: ( event ) => { setHoursEnabled( event.target.checked ); if ( event.target.checked && ( ! location.weekly_hours || ! Object.keys( location.weekly_hours ).length ) ) update( 'weekly_hours', defaultWeek() ); } } ), h( 'span', { className: 'vml-switch' } ), h( 'span', null, h( 'strong', null, __( 'Set business hours', 'velox-map-locator' ) ), h( 'small', null, __( 'Leave disabled if operating hours should not be shown or calculated.', 'velox-map-locator' ) ) ) ),
					hoursEnabled && h( BusinessHoursEditor, { value: location.weekly_hours || defaultWeek(), onChange: ( value ) => update( 'weekly_hours', value ) } ),
					h( SpecialHoursEditor, { value: location.special_hours || [], onChange: ( value ) => update( 'special_hours', value ) } )
				),
					h( EditorSection, { id: 'classification', title: __( 'Classification', 'velox-map-locator' ), description: __( 'Assign Types for filtering/marker inheritance and Groups for organisation.', 'velox-map-locator' ), sectionRefs, hasError: errorField.startsWith( 'type' ) || errorField.startsWith( 'group' ) || errorField === 'primary_type_id' },
						h( ClassificationPicker, { title: __( 'Location Types', 'velox-map-locator' ), terms: types, selected: location.type_ids || [], onChange: ( ids ) => { update( 'type_ids', ids ); if ( location.primary_type_id && ! ids.includes( Number( location.primary_type_id ) ) ) update( 'primary_type_id', ids[ 0 ] || 0 ); }, onAdd: boot.capabilities.manageTerms ? () => setTermDialog( { kind: 'type' } ) : null } ),
						( location.type_ids || [] ).length > 0 && h( Field, { label: __( 'Primary Type', 'velox-map-locator' ), hint: __( 'Controls the inherited marker when no Location-specific override is set.', 'velox-map-locator' ), error: errorField === 'primary_type_id' }, h( 'select', { value: location.primary_type_id || '', onChange: ( event ) => update( 'primary_type_id', Number( event.target.value ) ) }, ( location.type_ids || [] ).map( ( id ) => { const type = types.find( ( item ) => item.id === id ); return type ? h( 'option', { key: id, value: id }, type.name ) : null; } ) ) ),
						h( ClassificationPicker, { title: __( 'Groups', 'velox-map-locator' ), terms: groups, selected: location.group_ids || [], onChange: ( ids ) => update( 'group_ids', ids ), hierarchical: true, onAdd: boot.capabilities.manageTerms ? () => setTermDialog( { kind: 'group' } ) : null } )
				),
					h( EditorSection, { id: 'appearance', title: __( 'Appearance', 'velox-map-locator' ), description: __( 'Inherit the primary Type marker or override it for this Location.', 'velox-map-locator' ), sectionRefs, hasError: errorField.startsWith( 'marker' ) },
						h( 'div', { className: 'vml-marker-editor' },
							h( 'div', { className: 'vml-marker-preview-card' }, h( MarkerGlyph, { icon: displayMarker.icon || 'pin', color: displayMarker.color || '#2563eb', iconColor: displayMarker.icon_color || '#ffffff', size: displayMarker.size || 'medium' } ), h( 'strong', null, location.marker.override ? __( 'Location marker', 'velox-map-locator' ) : __( 'Inherited marker', 'velox-map-locator' ) ), h( 'small', null, ! location.marker.override && selectedPrimary ? sprintf( __( 'From %s', 'velox-map-locator' ), selectedPrimary.name ) : __( 'Preview', 'velox-map-locator' ) ) ),
							h( 'div', { className: 'vml-marker-controls' },
								h( 'label', { className: 'vml-switch-row compact' }, h( 'input', { type: 'checkbox', checked: Boolean( location.marker.override ), onChange: ( event ) => update( 'marker.override', event.target.checked ) } ), h( 'span', { className: 'vml-switch' } ), h( 'span', null, h( 'strong', null, __( 'Override marker for this Location', 'velox-map-locator' ) ) ) ),
								location.marker.override && h( Fragment, null,
									h( 'div', { className: 'vml-field-grid two' },
										h( Field, { label: __( 'Icon', 'velox-map-locator' ) }, h( 'select', { value: location.marker.icon || 'pin', onChange: ( event ) => update( 'marker.icon', event.target.value ) }, ( boot.markerIcons || [] ).map( ( icon ) => h( 'option', { key: icon, value: icon }, ( boot.markerIconLabels && boot.markerIconLabels[ icon ] ) || humanize( icon ) ) ) ) ),
										h( Field, { label: __( 'Size', 'velox-map-locator' ) }, h( 'select', { value: location.marker.size || 'medium', onChange: ( event ) => update( 'marker.size', event.target.value ) }, ( boot.markerSizes || [] ).map( ( size ) => h( 'option', { key: size, value: size }, ( boot.markerSizeLabels && boot.markerSizeLabels[ size ] ) || humanize( size ) ) ) ) ),
										h( ColorField, { label: __( 'Marker Colour', 'velox-map-locator' ), value: location.marker.color || '#2563eb', onChange: ( value ) => update( 'marker.color', value ) } ),
										h( ColorField, { label: __( 'Icon Colour', 'velox-map-locator' ), value: location.marker.icon_color || '#ffffff', onChange: ( value ) => update( 'marker.icon_color', value ) } )
									),
									h( MediaField, { label: __( 'Custom Marker Image', 'velox-map-locator' ), imageUrl: mediaUrls.marker, hint: __( 'Optional PNG, JPEG or WebP. When set, it can replace the built-in icon in later map rendering stages.', 'velox-map-locator' ), onChoose: () => selectMedia( 'marker' ), onRemove: () => { update( 'marker.media_id', 0 ); setMediaUrls( ( current ) => ( { ...current, marker: '' } ) ); } } )
								)
						)
					)
				),
					h( EditorSection, { id: 'additional', title: __( 'Additional Information', 'velox-map-locator' ), description: __( 'Add structured details such as parking, WhatsApp or accessibility notes.', 'velox-map-locator' ), sectionRefs, hasError: errorField.startsWith( 'extra_fields' ) },
						h( ExtraFieldsEditor, { value: location.extra_fields || [], onChange: ( value ) => update( 'extra_fields', value ) } )
				),
					h( EditorSection, { id: 'status', title: __( 'Operational Status', 'velox-map-locator' ), description: __( 'This is separate from opening hours and can override the normal public status.', 'velox-map-locator' ), sectionRefs, hasError: errorField.startsWith( 'operational' ) },
						h( 'div', { className: 'vml-segmented-control', role: 'radiogroup', 'aria-label': __( 'Operational status', 'velox-map-locator' ) }, [ [ 'normal', __( 'Normal', 'velox-map-locator' ) ], [ 'temporarily_closed', __( 'Temporarily Closed', 'velox-map-locator' ) ], [ 'coming_soon', __( 'Coming Soon', 'velox-map-locator' ) ] ].map( ( [ value, label ] ) => h( 'button', { type: 'button', key: value, role: 'radio', 'aria-checked': location.operational.status === value, className: cx( location.operational.status === value && 'is-selected' ), onClick: () => update( 'operational.status', value ) }, label ) ) ),
						location.operational.status !== 'normal' && h( Fragment, null,
							h( Field, { label: __( 'Public Label', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: location.operational.label || '', onChange: ( event ) => update( 'operational.label', event.target.value ), placeholder: location.operational.status === 'coming_soon' ? __( 'Coming Soon', 'velox-map-locator' ) : __( 'Temporarily Closed', 'velox-map-locator' ) } ) ),
							h( Field, { label: __( 'Optional Note', 'velox-map-locator' ) }, h( 'textarea', { rows: 3, value: location.operational.note || '', onChange: ( event ) => update( 'operational.note', event.target.value ), placeholder: __( 'e.g. Closed for renovation until September.', 'velox-map-locator' ) } ) )
						)
				)
			),
				h( 'aside', { className: 'vml-editor-aside' },
					h( 'section', { className: 'vml-panel vml-position-card' },
						h( 'div', { className: 'vml-panel-header' }, h( 'div', null, h( 'h2', null, __( 'Location Position', 'velox-map-locator' ) ), h( 'p', null, __( 'Coordinate preview', 'velox-map-locator' ) ) ) ),
						h( 'div', { className: 'vml-coordinate-preview' }, h( 'div', { className: 'vml-map-grid', 'aria-hidden': 'true' } ), h( 'span', { className: 'vml-preview-pin' }, h( Icon, { name: 'pin', size: 28 } ) ),
						location.address.latitude !== null && location.address.longitude !== null ? h( 'div', { className: 'vml-coordinate-value' }, h( 'strong', null, Number( location.address.latitude ).toFixed( 6 ) ), h( 'span', null, Number( location.address.longitude ).toFixed( 6 ) ) ) : h( 'div', { className: 'vml-coordinate-empty' }, __( 'Enter latitude and longitude to position this Location.', 'velox-map-locator' ) )
					),
						h( 'p', { className: 'vml-aside-note' }, boot.googleMaps && boot.googleMaps.configured ? __( 'Use Find on Map in Address & Position to resolve an address into coordinates, or edit latitude and longitude manually.', 'velox-map-locator' ) : __( 'Enter latitude and longitude manually, or configure Google Maps to enable Find on Map.', 'velox-map-locator' ) )
				),
					h( 'section', { className: 'vml-panel vml-record-summary' },
						h( 'div', { className: 'vml-panel-header' }, h( 'div', null, h( 'h2', null, __( 'Record Summary', 'velox-map-locator' ) ) ) ),
						h( SummaryRow, { label: __( 'Publication', 'velox-map-locator' ), value: h( StatusBadge, { status: location.status } ) } ),
						h( SummaryRow, { label: __( 'Operational', 'velox-map-locator' ), value: h( StatusBadge, { operational: location.operational.status } ) } ),
						h( SummaryRow, { label: __( 'Types', 'velox-map-locator' ), value: String( ( location.type_ids || [] ).length ) } ),
						h( SummaryRow, { label: __( 'Groups', 'velox-map-locator' ), value: String( ( location.group_ids || [] ).length ) } ),
						requestedId && h( SummaryRow, { label: __( 'Record ID', 'velox-map-locator' ), value: `#${ requestedId }` } )
				)
			)
		),
		termDialog && h( TermDialog, { kind: termDialog.kind, groups, onClose: () => setTermDialog( null ), onSaved: ( term ) => onTermCreated( termDialog.kind, term ) } )
		);
	}

	function sectionHasError( id, field ) {
		if ( id === 'address' ) return field.startsWith( 'address' );
		if ( id === 'contact' ) return field.startsWith( 'contact' );
		if ( id === 'hours' ) return field.startsWith( 'weekly_hours' ) || field.startsWith( 'special_hours' );
		if ( id === 'classification' ) return field.startsWith( 'type' ) || field.startsWith( 'group' ) || field === 'primary_type_id';
		if ( id === 'appearance' ) return field.startsWith( 'marker' );
		if ( id === 'additional' ) return field.startsWith( 'extra_fields' );
		if ( id === 'status' ) return field.startsWith( 'operational' );
		return ! field.includes( '.' ) && ! [ 'external_id', 'name' ].some( ( value ) => field === value ) ? false : [ 'name', 'external_id', 'status', 'featured_image_id' ].includes( field );
	}

	function fieldMatches( errorField, path ) {
		return Boolean( errorField && ( errorField === path || errorField.startsWith( `${ path }.` ) ) );
	}

	function EditorSection( { id, title, description, sectionRefs, hasError, children } ) {
		return h( 'section', { id: `vml-section-${ id }`, ref: ( node ) => { sectionRefs.current[ id ] = node; }, className: cx( 'vml-panel', 'vml-editor-section', hasError && 'has-error' ) },
			h( 'div', { className: 'vml-panel-header' }, h( 'div', null, h( 'h2', null, title ), description && h( 'p', null, description ) ), hasError && h( 'span', { className: 'vml-error-indicator' }, '!' ) ),
			h( 'div', { className: 'vml-section-body' }, children )
		);
	}

	function Field( { label, hint, required, error, children } ) {
		return h( 'label', { className: cx( 'vml-field', error && 'has-error' ) }, h( 'span', { className: 'vml-field-label' }, label, required && h( 'span', { className: 'vml-required', 'aria-hidden': 'true' }, ' *' ) ), children, hint && h( 'small', { className: 'vml-field-hint' }, hint ) );
	}

	function MediaField( { label, hint, imageUrl, onChoose, onRemove } ) {
		return h( 'div', { className: 'vml-field' }, h( 'span', { className: 'vml-field-label' }, label ), h( 'div', { className: 'vml-media-field' },
			imageUrl ? h( 'img', { src: imageUrl, alt: '' } ) : h( 'span', { className: 'vml-media-placeholder' }, h( Icon, { name: 'image', size: 25 } ) ),
			h( 'div', { className: 'vml-media-actions' }, h( Button, { variant: 'secondary', onClick: onChoose }, imageUrl ? __( 'Replace Image', 'velox-map-locator' ) : __( 'Choose Image', 'velox-map-locator' ) ), imageUrl && h( Button, { variant: 'tertiary', isDestructive: true, onClick: onRemove }, __( 'Remove', 'velox-map-locator' ) ), hint && h( 'small', null, hint ) )
		) );
	}

	function SummaryRow( { label, value } ) {
		return h( 'div', { className: 'vml-summary-row' }, h( 'span', null, label ), h( 'strong', null, value ) );
	}

	function BusinessHoursEditor( { value, onChange } ) {
		const week = { ...defaultWeek(), ...( value || {} ) };
		const changeDay = ( day, nextDay ) => onChange( { ...week, [ day ]: nextDay } );
		const setMode = ( day, mode ) => {
			const current = week[ day ] || { closed: true, all_day: false, intervals: [] };
			if ( mode === 'closed' ) changeDay( day, { closed: true, all_day: false, intervals: [] } );
			if ( mode === 'all_day' ) changeDay( day, { closed: false, all_day: true, intervals: [] } );
			if ( mode === 'open' ) changeDay( day, { closed: false, all_day: false, intervals: current.intervals && current.intervals.length ? current.intervals : [ { open: '09:00', close: '17:00' } ] } );
		};
		const copyMonday = () => {
			const monday = clone( week.monday ); const next = { ...week };
			[ 'tuesday', 'wednesday', 'thursday', 'friday' ].forEach( ( day ) => { next[ day ] = clone( monday ); } ); onChange( next );
		};
		return h( 'div', { className: 'vml-hours-editor' },
			h( 'div', { className: 'vml-hours-toolbar' }, h( Button, { variant: 'secondary', onClick: copyMonday }, __( 'Copy Monday to Tue–Fri', 'velox-map-locator' ) ) ),
			days.map( ( day ) => h( DayHoursRow, { key: day, day, value: week[ day ], onMode: ( mode ) => setMode( day, mode ), onChange: ( next ) => changeDay( day, next ) } ) )
		);
	}

	function DayHoursRow( { day, value, onMode, onChange } ) {
		const current = value || { closed: true, all_day: false, intervals: [] };
		const mode = current.closed ? 'closed' : current.all_day ? 'all_day' : 'open';
		const intervals = current.intervals || [];
		const changeInterval = ( index, key, nextValue ) => { const next = clone( intervals ); next[ index ][ key ] = nextValue; onChange( { ...current, intervals: next } ); };
		const removeInterval = ( index ) => onChange( { ...current, intervals: intervals.filter( ( _, currentIndex ) => currentIndex !== index ) } );
		const addInterval = () => { if ( intervals.length < 4 ) onChange( { ...current, intervals: [ ...intervals, { open: '09:00', close: '17:00' } ] } ); };
		return h( 'div', { className: 'vml-hours-row' },
			h( 'strong', { className: 'vml-day-label' }, dayLabels[ day ] ),
			h( 'select', { className: 'vml-hours-mode', value: mode, onChange: ( event ) => onMode( event.target.value ), 'aria-label': sprintf( __( '%s hours mode', 'velox-map-locator' ), dayLabels[ day ] ) }, h( 'option', { value: 'open' }, __( 'Open', 'velox-map-locator' ) ), h( 'option', { value: 'closed' }, __( 'Closed', 'velox-map-locator' ) ), h( 'option', { value: 'all_day' }, __( '24 Hours', 'velox-map-locator' ) ) ),
			mode === 'open' && h( 'div', { className: 'vml-interval-list' }, intervals.map( ( interval, index ) => h( 'div', { key: index, className: 'vml-time-interval' }, h( 'input', { type: 'time', value: interval.open, onChange: ( event ) => changeInterval( index, 'open', event.target.value ), 'aria-label': sprintf( __( '%s opening time', 'velox-map-locator' ), dayLabels[ day ] ) } ), h( 'span', null, '–' ), h( 'input', { type: 'time', value: interval.close, onChange: ( event ) => changeInterval( index, 'close', event.target.value ), 'aria-label': sprintf( __( '%s closing time', 'velox-map-locator' ), dayLabels[ day ] ) } ), intervals.length > 1 && h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => removeInterval( index ), label: __( 'Remove interval', 'velox-map-locator' ), icon: h( Icon, { name: 'close', size: 14 } ) } ) ) ), intervals.length < 4 && h( Button, { variant: 'tertiary', onClick: addInterval }, __( '+ Add interval', 'velox-map-locator' ) ) )
		);
	}

	function SpecialHoursEditor( { value, onChange } ) {
		const entries = Array.isArray( value ) ? value : [];
		const add = () => onChange( [ ...entries, { date: '', label: '', closed: true, all_day: false, intervals: [] } ] );
		const updateEntry = ( index, patch ) => { const next = clone( entries ); next[ index ] = { ...next[ index ], ...patch }; onChange( next ); };
		const remove = ( index ) => onChange( entries.filter( ( _, current ) => current !== index ) );
		return h( 'div', { className: 'vml-special-hours' },
			h( 'div', { className: 'vml-subsection-heading' }, h( 'div', null, h( 'h3', null, __( 'Special Hours', 'velox-map-locator' ) ), h( 'p', null, __( 'Override weekly hours for holidays or exceptional dates.', 'velox-map-locator' ) ) ), h( Button, { variant: 'secondary', onClick: add, icon: h( Icon, { name: 'plus', size: 15 } ) }, __( 'Add Date', 'velox-map-locator' ) ) ),
			entries.length ? h( 'div', { className: 'vml-special-list' }, entries.map( ( entry, index ) => h( SpecialHoursRow, { key: `${ entry.date }-${ index }`, entry, onChange: ( patch ) => updateEntry( index, patch ), onRemove: () => remove( index ) } ) ) ) : h( 'p', { className: 'vml-muted-copy' }, __( 'No special-date overrides configured.', 'velox-map-locator' ) )
		);
	}

	function SpecialHoursRow( { entry, onChange, onRemove } ) {
		const mode = entry.closed ? 'closed' : entry.all_day ? 'all_day' : 'open';
		const intervals = entry.intervals || [];
		const setMode = ( nextMode ) => {
			if ( nextMode === 'closed' ) onChange( { closed: true, all_day: false, intervals: [] } );
			if ( nextMode === 'all_day' ) onChange( { closed: false, all_day: true, intervals: [] } );
			if ( nextMode === 'open' ) onChange( { closed: false, all_day: false, intervals: intervals.length ? intervals : [ { open: '09:00', close: '17:00' } ] } );
		};
		const changeInterval = ( index, key, nextValue ) => { const next = clone( intervals ); next[ index ][ key ] = nextValue; onChange( { intervals: next } ); };
		return h( 'div', { className: 'vml-special-row' },
			h( 'div', { className: 'vml-special-primary' }, h( 'input', { type: 'date', value: entry.date || '', onChange: ( event ) => onChange( { date: event.target.value } ), 'aria-label': __( 'Special date', 'velox-map-locator' ) } ), h( 'input', { type: 'text', value: entry.label || '', onChange: ( event ) => onChange( { label: event.target.value } ), placeholder: __( 'Label, e.g. National Day', 'velox-map-locator' ), 'aria-label': __( 'Special hours label', 'velox-map-locator' ) } ), h( 'select', { value: mode, onChange: ( event ) => setMode( event.target.value ), 'aria-label': __( 'Special hours mode', 'velox-map-locator' ) }, h( 'option', { value: 'closed' }, __( 'Closed', 'velox-map-locator' ) ), h( 'option', { value: 'open' }, __( 'Special Hours', 'velox-map-locator' ) ), h( 'option', { value: 'all_day' }, __( '24 Hours', 'velox-map-locator' ) ) ), h( Button, { variant: 'tertiary', isDestructive: true, onClick: onRemove, label: __( 'Remove special date', 'velox-map-locator' ), icon: h( Icon, { name: 'trash', size: 16 } ) } ) ),
			mode === 'open' && h( 'div', { className: 'vml-special-intervals' }, intervals.map( ( interval, index ) => h( 'div', { key: index, className: 'vml-time-interval' }, h( 'input', { type: 'time', value: interval.open, onChange: ( event ) => changeInterval( index, 'open', event.target.value ) } ), h( 'span', null, '–' ), h( 'input', { type: 'time', value: interval.close, onChange: ( event ) => changeInterval( index, 'close', event.target.value ) } ), intervals.length > 1 && h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange( { intervals: intervals.filter( ( _, current ) => current !== index ) } ), icon: h( Icon, { name: 'close', size: 14 } ), label: __( 'Remove interval', 'velox-map-locator' ) } ) ) ), intervals.length < 4 && h( Button, { variant: 'tertiary', onClick: () => onChange( { intervals: [ ...intervals, { open: '09:00', close: '17:00' } ] } ) }, __( '+ Add interval', 'velox-map-locator' ) ) )
		);
	}

	function ClassificationPicker( { title, terms, selected, onChange, hierarchical, onAdd } ) {
		const selectedSet = new Set( selected.map( Number ) );
		const toggle = ( id ) => onChange( selectedSet.has( id ) ? selected.filter( ( current ) => Number( current ) !== id ) : [ ...selected, id ] );
		const ordered = hierarchical ? hierarchyOrder( terms ) : terms.map( ( term ) => ( { ...term, depth: 0 } ) );
		return h( 'div', { className: 'vml-classification-picker' },
			h( 'div', { className: 'vml-subsection-heading compact' }, h( 'h3', null, title ), onAdd && h( Button, { variant: 'tertiary', onClick: onAdd, icon: h( Icon, { name: 'plus', size: 14 } ) }, __( 'Create', 'velox-map-locator' ) ) ),
			ordered.length ? h( 'div', { className: 'vml-checkbox-grid' }, ordered.map( ( term ) => h( 'label', { key: term.id, className: 'vml-check-card', style: hierarchical ? { '--vml-depth': term.depth } : undefined }, h( 'input', { type: 'checkbox', checked: selectedSet.has( term.id ), onChange: () => toggle( term.id ) } ), term.marker && h( MarkerGlyph, { icon: term.marker.icon || 'pin', color: term.marker.color || '#2563eb', iconColor: term.marker.icon_color || '#ffffff', size: 'small' } ), h( 'span', null, term.name ) ) ) ) : h( 'p', { className: 'vml-muted-copy' }, __( 'No classifications created yet.', 'velox-map-locator' ) )
		);
	}

	function hierarchyOrder( terms ) {
		const children = {};
		terms.forEach( ( term ) => { const parent = Number( term.parent || 0 ); if ( ! children[ parent ] ) children[ parent ] = []; children[ parent ].push( term ); } );
		const output = []; const visited = new Set();
		function walk( parent, depth ) { ( children[ parent ] || [] ).forEach( ( term ) => { if ( visited.has( term.id ) ) return; visited.add( term.id ); output.push( { ...term, depth } ); walk( term.id, depth + 1 ); } ); }
		walk( 0, 0 ); terms.forEach( ( term ) => { if ( ! visited.has( term.id ) ) output.push( { ...term, depth: 0 } ); } ); return output;
	}

	function ColorField( { label, value, onChange } ) {
		return h( 'div', { className: 'vml-field' }, h( 'span', { className: 'vml-field-label' }, label ), h( 'div', { className: 'vml-color-field' }, h( 'input', { type: 'color', value: value || '#2563eb', onChange: ( event ) => onChange( event.target.value ), 'aria-label': label } ), h( 'input', { type: 'text', value: value || '', maxLength: 7, onChange: ( event ) => onChange( event.target.value ), placeholder: '#2563EB', 'aria-label': label } ) ) );
	}

	function ExtraFieldsEditor( { value, onChange } ) {
		const items = Array.isArray( value ) ? value : [];
		const add = () => onChange( [ ...items, { id: `field-${ Date.now() }`, label: '', type: 'text', value: '' } ] );
		const update = ( index, key, nextValue ) => { const next = clone( items ); next[ index ][ key ] = nextValue; if ( key === 'label' && ( ! next[ index ].id || next[ index ].id.startsWith( 'field-' ) ) ) next[ index ].id = slugify( nextValue ) || next[ index ].id; onChange( next ); };
		const remove = ( index ) => onChange( items.filter( ( _, current ) => current !== index ) );
		return h( 'div', { className: 'vml-extra-editor' },
			items.length ? h( 'div', { className: 'vml-extra-list' }, items.map( ( item, index ) => h( 'div', { key: `${ item.id }-${ index }`, className: 'vml-extra-row' }, h( 'input', { type: 'text', value: item.label || '', onChange: ( event ) => update( index, 'label', event.target.value ), placeholder: __( 'Label', 'velox-map-locator' ), 'aria-label': __( 'Additional field label', 'velox-map-locator' ) } ), h( 'select', { value: item.type || 'text', onChange: ( event ) => update( index, 'type', event.target.value ), 'aria-label': __( 'Additional field type', 'velox-map-locator' ) }, [ 'text', 'phone', 'email', 'url' ].map( ( type ) => h( 'option', { key: type, value: type }, extraTypeLabel( type ) ) ) ), h( 'input', { type: item.type === 'email' ? 'email' : item.type === 'url' ? 'url' : item.type === 'phone' ? 'tel' : 'text', value: item.value || '', onChange: ( event ) => update( index, 'value', event.target.value ), placeholder: __( 'Value', 'velox-map-locator' ), 'aria-label': __( 'Additional field value', 'velox-map-locator' ) } ), h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => remove( index ), label: __( 'Remove field', 'velox-map-locator' ), icon: h( Icon, { name: 'trash', size: 16 } ) } ) ) ) ) : h( 'p', { className: 'vml-muted-copy' }, __( 'No additional information fields.', 'velox-map-locator' ) ),
			h( Button, { variant: 'secondary', onClick: add, icon: h( Icon, { name: 'plus', size: 15 } ) }, __( 'Add Field', 'velox-map-locator' ) )
		);
	}

	function extraTypeLabel( value ) {
		const labels = { text: __( 'Text', 'velox-map-locator' ), phone: __( 'Phone', 'velox-map-locator' ), email: __( 'Email', 'velox-map-locator' ), url: __( 'URL', 'velox-map-locator' ) };
		return labels[ value ] || value;
	}

	function slugify( value ) {
		return String( value || '' ).toLowerCase().trim().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 80 );
	}

	function humanize( value ) {
		return String( value || '' ).replace( /[-_]/g, ' ' ).replace( /\b\w/g, ( letter ) => letter.toUpperCase() );
	}


	function allowLocatorBuilderNavigation( id ) { window.location.assign( `${ boot.urls.editLocatorBase }&locator_id=${ Number( id ) }` ); }

	function LocatorsList() {
		const [ result, setResult ] = useState( { items: [], total: 0, totalPages: 0 } );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( '' );
		const [ notice, setNotice ] = useState( '' );
		const [ search, setSearch ] = useState( '' );
		const [ status, setStatus ] = useState( '' );
		const [ page, setPage ] = useState( 1 );
		const [ createOpen, setCreateOpen ] = useState( boot.action === 'create' );

		const load = () => {
			setLoading( true ); setError( '' );
			return fetchLocators( { page, per_page: 20, search, status } ).then( setResult ).catch( ( err ) => setError( getErrorMessage( err ) ) ).finally( () => setLoading( false ) );
		};
		useEffect( () => { const timer = window.setTimeout( load, 180 ); return () => window.clearTimeout( timer ); }, [ search, status, page ] );

		const updateStatus = async ( item, nextStatus ) => {
			try {
				await apiFetch( { path: `${ namespace }/admin/locators/${ item.id }`, method: 'PUT', data: { status: nextStatus } } );
				setNotice( nextStatus === 'publish' ? __( 'Locator published.', 'velox-map-locator' ) : __( 'Locator moved to Draft.', 'velox-map-locator' ) );
				load();
			} catch ( err ) { setError( getErrorMessage( err ) ); }
		};
		const trash = async ( item ) => {
			if ( ! window.confirm( sprintf( __( 'Move “%s” to Trash?', 'velox-map-locator' ), item.name || __( 'Untitled Locator', 'velox-map-locator' ) ) ) ) return;
			try { await apiFetch( { path: `${ namespace }/admin/locators/${ item.id }`, method: 'DELETE' } ); setNotice( __( 'Locator moved to Trash.', 'velox-map-locator' ) ); load(); } catch ( err ) { setError( getErrorMessage( err ) ); }
		};
		const restore = async ( item ) => {
			try { await apiFetch( { path: `${ namespace }/admin/locators/${ item.id }/restore`, method: 'POST' } ); setNotice( __( 'Locator restored.', 'velox-map-locator' ) ); load(); } catch ( err ) { setError( getErrorMessage( err ) ); }
		};
		const copyShortcode = async ( item ) => {
			try { await navigator.clipboard.writeText( item.shortcode ); setNotice( __( 'Shortcode copied.', 'velox-map-locator' ) ); } catch ( err ) { window.prompt( __( 'Copy this shortcode:', 'velox-map-locator' ), item.shortcode ); }
		};

		return h( Fragment, null,
			h( PageHeader, { title: __( 'Locators', 'velox-map-locator' ), description: __( 'Create and configure reusable location experiences with the full visual Locator Builder.', 'velox-map-locator' ) }, boot.capabilities.createLocators && h( Button, { variant: 'primary', onClick: () => setCreateOpen( true ), icon: h( Icon, { name: 'plus', size: 18 } ) }, __( 'Create Locator', 'velox-map-locator' ) ) ),
			notice && h( Notice, { status: 'success', onRemove: () => setNotice( '' ) }, notice ),
			error && h( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ),
			h( 'section', { className: 'vml-panel vml-locators-panel' },
				h( 'div', { className: 'vml-filter-bar vml-locator-filter-bar' },
					h( 'label', { className: 'vml-search-field' }, h( 'span', { className: 'screen-reader-text' }, __( 'Search locators', 'velox-map-locator' ) ), h( Icon, { name: 'search', size: 18 } ), h( 'input', { type: 'search', value: search, placeholder: __( 'Search locators…', 'velox-map-locator' ), onChange: ( event ) => { setSearch( event.target.value ); setPage( 1 ); } } ) ),
					h( FilterSelect, { label: __( 'Status', 'velox-map-locator' ), value: status, onChange: ( value ) => { setStatus( value ); setPage( 1 ); }, options: [ [ '', __( 'All statuses', 'velox-map-locator' ) ], [ 'publish', __( 'Published', 'velox-map-locator' ) ], [ 'draft', __( 'Draft', 'velox-map-locator' ) ], [ 'trash', __( 'Trash', 'velox-map-locator' ) ] ] } )
				),
				loading ? h( LoadingScreen ) : result.items.length ? h( 'div', { className: 'vml-locator-list' }, result.items.map( ( item ) => h( 'article', { className: 'vml-locator-row', key: item.id },
					h( 'div', { className: 'vml-locator-row-main' }, h( 'span', { className: 'vml-locator-icon' }, h( Icon, { name: 'pin', size: 19 } ) ), h( 'div', null, h( 'a', { className: 'vml-locator-title-link', href: `${ boot.urls.editLocatorBase }&locator_id=${ item.id }` }, item.name || __( 'Untitled Locator', 'velox-map-locator' ) ), h( 'small', null, item.config && item.config.source && item.config.source.mode === 'all' ? __( 'All published Locations', 'velox-map-locator' ) : humanize( item.config && item.config.source && item.config.source.mode || 'all' ) ) ) ),
					h( 'div', { className: 'vml-locator-row-meta' }, h( StatusBadge, { status: item.status } ), h( 'span', { className: 'vml-subtle-chip' }, humanize( item.config && item.config.layout && item.config.layout.mode || 'split' ) ) ),
					h( 'code', { className: 'vml-shortcode' }, item.shortcode ),
					h( 'div', { className: 'vml-locator-row-actions' },
						h( Button, { variant: 'secondary', href: `${ boot.urls.editLocatorBase }&locator_id=${ item.id }` }, __( 'Edit', 'velox-map-locator' ) ),
						h( Button, { variant: 'secondary', onClick: () => copyShortcode( item ) }, __( 'Copy Shortcode', 'velox-map-locator' ) ),
						item.status === 'trash' ? h( Button, { variant: 'tertiary', onClick: () => restore( item ) }, __( 'Restore', 'velox-map-locator' ) ) : h( Fragment, null,
							item.status === 'publish' ? h( Button, { variant: 'tertiary', onClick: () => updateStatus( item, 'draft' ) }, __( 'Move to Draft', 'velox-map-locator' ) ) : boot.capabilities.publishLocators && h( Button, { variant: 'tertiary', onClick: () => updateStatus( item, 'publish' ) }, __( 'Publish', 'velox-map-locator' ) ),
							boot.capabilities.deleteLocators && h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => trash( item ), icon: h( Icon, { name: 'trash', size: 16 } ), label: __( 'Move Locator to Trash', 'velox-map-locator' ) } )
						)
					)
				) ) ) : h( EmptyState, { title: __( 'No Locators yet', 'velox-map-locator' ), description: __( 'Create a Locator, publish it, then place its shortcode on any page or page-builder layout.', 'velox-map-locator' ), action: boot.capabilities.createLocators && h( Button, { variant: 'primary', onClick: () => setCreateOpen( true ) }, __( 'Create Locator', 'velox-map-locator' ) ) } ),
				result.totalPages > 1 && h( Pagination, { page, pages: result.totalPages, onChange: setPage } )
			),
			createOpen && h( CreateLocatorModal, { onClose: () => setCreateOpen( false ), onCreated: ( item ) => { allowLocatorBuilderNavigation( item.id ); } } )
		);
	}

	function CreateLocatorModal( { onClose, onCreated } ) {
		const [ name, setName ] = useState( '' );
		const [ layout, setLayout ] = useState( 'split' );
		const [ mapLoadMode, setMapLoadMode ] = useState( 'inherit' );
		const [ mapProvider, setMapProvider ] = useState( 'osm' );
		const initialXyzProfile = boot.mapProviderProfiles && boot.mapProviderProfiles.length ? boot.mapProviderProfiles[ 0 ].id : '';
		const [ xyzProfile, setXyzProfile ] = useState( initialXyzProfile );
		const [ saving, setSaving ] = useState( false );
		const [ error, setError ] = useState( '' );
		const submit = async ( event ) => {
			event.preventDefault(); setSaving( true ); setError( '' );
			const config = { layout: { mode: layout }, privacy: { map_load_mode: mapLoadMode }, map: { provider: mapProvider, provider_profile_id: mapProvider === 'xyz' ? xyzProfile : '' } };
			try { const item = await apiFetch( { path: `${ namespace }/admin/locators`, method: 'POST', data: { name, status: 'draft', config } } ); onCreated( item ); } catch ( err ) { setError( getErrorMessage( err ) ); } finally { setSaving( false ); }
		};
		return h( Modal, { title: __( 'Create Locator', 'velox-map-locator' ), onRequestClose: onClose, className: 'vml-term-modal', overlayClassName: cx( 'vml-term-modal-overlay', `vml-term-modal-overlay--${ boot.adminAppearance || 'system' }` ), shouldCloseOnClickOutside: true },
			h( 'form', { onSubmit: submit },
				h( 'p', { className: 'vml-modal-intro' }, __( 'Choose a starting layout and provider. After creation, the full Locator Builder opens for detailed configuration.', 'velox-map-locator' ) ),
				error && h( Notice, { status: 'error', isDismissible: false }, error ),
				h( 'div', { className: 'vml-modal-body' },
					h( Field, { label: __( 'Locator Name', 'velox-map-locator' ), required: true, hint: __( 'Use a descriptive internal name, such as UAE Offices or Service Centres.', 'velox-map-locator' ) }, h( 'input', { className: 'vml-locator-name-input', autoFocus: true, type: 'text', required: true, value: name, onChange: ( event ) => setName( event.target.value ), placeholder: __( 'e.g. UAE Offices', 'velox-map-locator' ) } ) ),
					h( Field, { label: __( 'Layout', 'velox-map-locator' ), hint: __( 'Split is the recommended map-and-list layout for most locators.', 'velox-map-locator' ) }, h( 'select', { value: layout, onChange: ( event ) => setLayout( event.target.value ) },
						h( 'option', { value: 'split' }, __( 'Split — Map + Locations', 'velox-map-locator' ) ),
						h( 'option', { value: 'map_only' }, __( 'Map Only', 'velox-map-locator' ) ),
						h( 'option', { value: 'list_only' }, __( 'List Only', 'velox-map-locator' ) )
					) ),
					layout !== 'list_only' && h( Field, { label: __( 'Map Provider', 'velox-map-locator' ), hint: __( 'OpenStreetMap is ready immediately. Google Maps requires an API key and Map ID. Custom XYZ uses a reusable profile.', 'velox-map-locator' ) }, h( 'select', { value: mapProvider, onChange: ( event ) => setMapProvider( event.target.value ) },
						h( 'option', { value: 'osm' }, __( 'OpenStreetMap Standard', 'velox-map-locator' ) ),
						h( 'option', { value: 'google', disabled: ! ( boot.googleMaps && boot.googleMaps.configured ) }, boot.googleMaps && boot.googleMaps.configured ? __( 'Google Maps', 'velox-map-locator' ) : __( 'Google Maps — configure first', 'velox-map-locator' ) ),
						h( 'option', { value: 'xyz' }, __( 'Custom XYZ', 'velox-map-locator' ) )
					) ),
					layout !== 'list_only' && mapProvider === 'xyz' && ( boot.mapProviderProfiles && boot.mapProviderProfiles.length ? h( Field, { label: __( 'XYZ Profile', 'velox-map-locator' ), required: true, hint: __( 'The selected tile profile is shared by any Locators that reference it.', 'velox-map-locator' ) }, h( 'select', { value: xyzProfile, required: true, onChange: ( event ) => setXyzProfile( event.target.value ) }, boot.mapProviderProfiles.map( ( profile ) => h( 'option', { key: profile.id, value: profile.id }, profile.name ) ) ) ) : h( 'div', { className: 'vml-inline-callout is-warning' }, h( 'strong', null, __( 'No XYZ profiles configured', 'velox-map-locator' ) ), h( 'span', null, __( 'Create a Custom XYZ profile under Map Providers before using this provider.', 'velox-map-locator' ) ), boot.urls && boot.urls.providers && h( 'a', { href: boot.urls.providers }, __( 'Open Map Providers', 'velox-map-locator' ) ) ) ),
					layout !== 'list_only' && h( Field, { label: __( 'Map Loading', 'velox-map-locator' ), hint: __( 'Interaction mode keeps external map tiles disconnected until the visitor chooses Load Map.', 'velox-map-locator' ) }, h( 'select', { value: mapLoadMode, onChange: ( event ) => setMapLoadMode( event.target.value ) },
						h( 'option', { value: 'inherit' }, __( 'Use global default', 'velox-map-locator' ) ),
						h( 'option', { value: 'immediate' }, __( 'Load map when visible', 'velox-map-locator' ) ),
						h( 'option', { value: 'interaction' }, __( 'Require visitor to click Load Map', 'velox-map-locator' ) )
					) )
				),
				h( 'div', { className: 'vml-modal-footer' }, h( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'velox-map-locator' ) ), h( Button, { type: 'submit', variant: 'primary', isBusy: saving, disabled: saving || ! name.trim() || ( layout !== 'list_only' && mapProvider === 'xyz' && ! xyzProfile ) || ( layout !== 'list_only' && mapProvider === 'google' && ! ( boot.googleMaps && boot.googleMaps.configured ) ) }, __( 'Create Locator', 'velox-map-locator' ) ) )
			)
		);
	}


	function BuilderToggle( { label, description, checked, onChange, disabled = false } ) {
		return h( 'label', { className: cx( 'vml-builder-toggle', disabled && 'is-disabled' ) },
			h( 'span', { className: 'vml-builder-toggle-copy' }, h( 'strong', null, label ), description && h( 'small', null, description ) ),
			h( 'span', { className: 'vml-builder-toggle-control' }, h( 'input', { type: 'checkbox', checked: Boolean( checked ), disabled, onChange: ( event ) => onChange( event.target.checked ) } ), h( 'span', { 'aria-hidden': 'true' } ) )
		);
	}

	function BuilderSection( { id, title, description, active, onActivate, children } ) {
		return h( 'section', { className: cx( 'vml-builder-section', active && 'is-active' ), 'data-builder-section': id },
			h( 'button', { type: 'button', className: 'vml-builder-section-heading', onClick: onActivate, 'aria-expanded': active ? 'true' : 'false' },
				h( 'span', null, h( 'strong', null, title ), description && h( 'small', null, description ) ),
				h( Icon, { name: 'chevron', size: 16 } )
			),
			active && h( 'div', { className: 'vml-builder-section-body' }, children )
		);
	}

	function BuilderChoice( { name, value, current, title, description, onChange, disabled = false } ) {
		return h( 'label', { className: cx( 'vml-builder-choice', current === value && 'is-selected', disabled && 'is-disabled' ) },
			h( 'input', { type: 'radio', name, value, checked: current === value, disabled, onChange: () => onChange( value ) } ),
			h( 'span', null, h( 'strong', null, title ), description && h( 'small', null, description ) )
		);
	}

	function BuilderCheckboxes( { options, value, onChange } ) {
		const current = Array.isArray( value ) ? value : [];
		const toggle = ( option, checked ) => onChange( checked ? [ ...current, option ].filter( ( item, index, list ) => list.indexOf( item ) === index ) : current.filter( ( item ) => item !== option ) );
		return h( 'div', { className: 'vml-builder-checkbox-grid' }, options.map( ( option ) => h( 'label', { key: option[ 0 ], className: cx( 'vml-builder-check', current.includes( option[ 0 ] ) && 'is-selected' ) }, h( 'input', { type: 'checkbox', checked: current.includes( option[ 0 ] ), onChange: ( event ) => toggle( option[ 0 ], event.target.checked ) } ), h( 'span', null, option[ 1 ] ) ) ) );
	}

	function LocationMultiPicker( { locations, selectedIds, onChange, label, compact = false } ) {
		const [ search, setSearch ] = useState( '' );
		const selected = new Set( ( selectedIds || [] ).map( Number ) );
		const query = search.trim().toLocaleLowerCase();
		const visible = ( locations || [] ).filter( ( item ) => ! query || `${ item.name || '' } ${ item.address && ( item.address.display_address || item.address.line_1 ) || '' } ${ item.address && item.address.city || '' }`.toLocaleLowerCase().includes( query ) ).slice( 0, compact ? 40 : 80 );
		const toggle = ( id, checked ) => {
			const next = new Set( selected );
			if ( checked ) next.add( Number( id ) ); else next.delete( Number( id ) );
			onChange( Array.from( next ) );
		};
		return h( 'div', { className: cx( 'vml-location-picker', compact && 'is-compact' ) },
			label && h( 'strong', { className: 'vml-picker-label' }, label ),
			h( 'label', { className: 'vml-picker-search' }, h( Icon, { name: 'search', size: 16 } ), h( 'input', { type: 'search', value: search, placeholder: __( 'Search published locations…', 'velox-map-locator' ), onChange: ( event ) => setSearch( event.target.value ) } ) ),
			h( 'div', { className: 'vml-location-picker-list' }, visible.length ? visible.map( ( item ) => {
				const address = item.address && ( item.address.display_address || item.address.line_1 || item.address.city ) || '';
				return h( 'label', { key: item.id, className: cx( 'vml-location-picker-row', selected.has( Number( item.id ) ) && 'is-selected' ) },
					h( 'input', { type: 'checkbox', checked: selected.has( Number( item.id ) ), onChange: ( event ) => toggle( item.id, event.target.checked ) } ),
					h( 'span', null, h( 'strong', null, item.name || __( 'Untitled Location', 'velox-map-locator' ) ), address && h( 'small', null, address ) )
				);
			} ) : h( 'p', { className: 'vml-muted-copy' }, __( 'No published locations match this search.', 'velox-map-locator' ) ) )
		);
	}

	function SelectedLocationOrder( { locations, ids, onChange } ) {
		const map = {};
		( locations || [] ).forEach( ( item ) => { map[ Number( item.id ) ] = item; } );
		const ordered = ( ids || [] ).map( Number ).filter( ( id, index, list ) => id && list.indexOf( id ) === index );
		const move = ( index, delta ) => {
			const target = index + delta;
			if ( target < 0 || target >= ordered.length ) return;
			const next = [ ...ordered ];
			[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
			onChange( next );
		};
		return h( 'div', { className: 'vml-selected-order' },
			h( 'div', { className: 'vml-builder-subhead' }, h( 'strong', null, __( 'Manual order', 'velox-map-locator' ) ), h( 'span', null, __( 'Use the arrows as an accessible alternative to drag-and-drop.', 'velox-map-locator' ) ) ),
			ordered.length ? ordered.map( ( id, index ) => h( 'div', { className: 'vml-selected-order-row', key: id },
				h( 'span', { className: 'vml-order-index' }, index + 1 ),
				h( 'span', { className: 'vml-order-name' }, map[ id ] ? map[ id ].name : sprintf( __( 'Location #%d', 'velox-map-locator' ), id ) ),
				h( 'div', { className: 'vml-order-actions' },
					h( Button, { variant: 'tertiary', disabled: index === 0, onClick: () => move( index, -1 ), label: __( 'Move up', 'velox-map-locator' ) }, '↑' ),
					h( Button, { variant: 'tertiary', disabled: index === ordered.length - 1, onClick: () => move( index, 1 ), label: __( 'Move down', 'velox-map-locator' ) }, '↓' ),
					h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => onChange( ordered.filter( ( current ) => current !== id ) ), label: __( 'Remove', 'velox-map-locator' ), icon: h( Icon, { name: 'close', size: 14 } ) } )
				)
			) ) : h( 'p', { className: 'vml-muted-copy' }, __( 'Select at least one Location to define a manual order.', 'velox-map-locator' ) )
		);
	}

	function DynamicConditionsEditor( { value, types, groups, onChange } ) {
		const conditions = Array.isArray( value ) ? value : [];
		const add = () => onChange( [ ...conditions, { field: 'type', value: types && types.length ? Number( types[ 0 ].id ) : '' } ] );
		const update = ( index, patch ) => { const next = clone( conditions ); next[ index ] = { ...next[ index ], ...patch }; if ( patch.field ) next[ index ].value = ''; onChange( next ); };
		const remove = ( index ) => onChange( conditions.filter( ( _, current ) => current !== index ) );
		return h( 'div', { className: 'vml-condition-editor' },
			conditions.map( ( condition, index ) => h( 'div', { key: index, className: 'vml-condition-row' },
				h( 'select', { value: condition.field || 'type', onChange: ( event ) => { const field = event.target.value; const value = field === 'type' && types && types.length ? Number( types[ 0 ].id ) : field === 'group' && groups && groups.length ? Number( groups[ 0 ].id ) : ''; update( index, { field, value } ); }, 'aria-label': __( 'Condition field', 'velox-map-locator' ) },
					h( 'option', { value: 'type' }, __( 'Type', 'velox-map-locator' ) ), h( 'option', { value: 'group' }, __( 'Group', 'velox-map-locator' ) ), h( 'option', { value: 'country' }, __( 'Country code', 'velox-map-locator' ) ), h( 'option', { value: 'city' }, __( 'City', 'velox-map-locator' ) )
				),
				condition.field === 'type' ? h( 'select', { value: condition.value || '', onChange: ( event ) => update( index, { value: Number( event.target.value ) || '' } ) }, h( 'option', { value: '' }, __( 'Choose Type…', 'velox-map-locator' ) ), ( types || [] ).map( ( term ) => h( 'option', { key: term.id, value: term.id }, term.name ) ) ) :
				condition.field === 'group' ? h( 'select', { value: condition.value || '', onChange: ( event ) => update( index, { value: Number( event.target.value ) || '' } ) }, h( 'option', { value: '' }, __( 'Choose Group…', 'velox-map-locator' ) ), hierarchyOrder( groups || [] ).map( ( term ) => h( 'option', { key: term.id, value: term.id }, `${ '— '.repeat( term.depth || 0 ) }${ term.name }` ) ) ) :
				h( 'input', { type: 'text', value: condition.value || '', placeholder: condition.field === 'country' ? 'AE' : __( 'e.g. Dubai', 'velox-map-locator' ), maxLength: condition.field === 'country' ? 2 : 120, onChange: ( event ) => update( index, { value: event.target.value } ) } ),
				h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => remove( index ), label: __( 'Remove condition', 'velox-map-locator' ), icon: h( Icon, { name: 'trash', size: 15 } ) } )
			) ),
			h( Button, { variant: 'secondary', onClick: add, disabled: conditions.length >= 20, icon: h( Icon, { name: 'plus', size: 15 } ) }, __( 'Add Condition', 'velox-map-locator' ) )
		);
	}

	function LocatorPreview( { html, loading, error, device } ) {
		const mountRef = useRef( null );
		useEffect( () => {
			const mount = mountRef.current;
			if ( ! mount || ! html ) return;
			mount.querySelectorAll( '.vml-locator[data-vml-instance]' ).forEach( ( root ) => { if ( root.vmlMap && typeof root.vmlMap.destroy === 'function' ) root.vmlMap.destroy(); } );
			mount.innerHTML = html;
			const initializePreview = () => mount.querySelectorAll( '.vml-locator[data-vml-instance]' ).forEach( ( root ) => {
				if ( window.VelomaloFrontend && window.VelomaloFrontend.initializeRoot ) window.VelomaloFrontend.initializeRoot( root );
				if ( window.VelomaloMapLeaflet && window.VelomaloMapLeaflet.initializeRoot ) window.VelomaloMapLeaflet.initializeRoot( root );
				if ( window.VelomaloMapGoogle && window.VelomaloMapGoogle.initializeRoot ) window.VelomaloMapGoogle.initializeRoot( root );
			} );
			initializePreview();
			const retry = window.setTimeout( initializePreview, 120 );
			return () => { window.clearTimeout( retry ); mount.querySelectorAll( '.vml-locator[data-vml-instance]' ).forEach( ( root ) => { if ( root.vmlMap && typeof root.vmlMap.destroy === 'function' ) root.vmlMap.destroy(); } ); };
		}, [ html ] );
		return h( 'div', { className: 'vml-builder-preview-panel' },
			h( 'div', { className: 'vml-builder-preview-head' }, h( 'div', null, h( 'strong', null, __( 'Live Preview', 'velox-map-locator' ) ), h( 'small', null, __( 'Rendered by the same frontend engine used by the shortcode.', 'velox-map-locator' ) ) ), loading && h( Spinner ) ),
			error && h( 'div', { className: 'vml-preview-error' }, error ),
			h( 'div', { className: cx( 'vml-builder-device-stage', `is-${ device }` ) }, h( 'div', { className: 'vml-builder-preview-mount', ref: mountRef }, ! html && ! loading && h( 'div', { className: 'vml-preview-placeholder' }, __( 'Preview will appear here.', 'velox-map-locator' ) ) ) )
		);
	}

	function LocatorBuilder() {
		const locatorId = Number( boot.locatorId || 0 );
		const [ locator, setLocator ] = useState( null );
		const [ form, setForm ] = useState( null );
		const [ savedSnapshot, setSavedSnapshot ] = useState( '' );
		const [ locations, setLocations ] = useState( [] );
		const [ types, setTypes ] = useState( [] );
		const [ groups, setGroups ] = useState( [] );
		const [ loading, setLoading ] = useState( true );
		const [ saving, setSaving ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ notice, setNotice ] = useState( '' );
		const [ activeSection, setActiveSection ] = useState( 'source' );
		const [ previewHtml, setPreviewHtml ] = useState( '' );
		const [ previewError, setPreviewError ] = useState( '' );
		const [ previewLoading, setPreviewLoading ] = useState( false );
		const [ device, setDevice ] = useState( 'desktop' );
		const allowLeave = useRef( false );

		const snapshot = ( value ) => value ? JSON.stringify( { name: value.name, status: value.status, config: value.config } ) : '';
		const dirty = Boolean( form && savedSnapshot && snapshot( form ) !== savedSnapshot );
		const updateConfig = ( path, value ) => setForm( ( current ) => ( { ...current, config: setNested( current.config, path, value ) } ) );

		useEffect( () => {
			if ( ! locatorId ) { setError( __( 'A Locator ID is required to open the Builder.', 'velox-map-locator' ) ); setLoading( false ); return; }
			let cancelled = false;
			async function load() {
				setLoading( true ); setError( '' );
				try {
					const [ item, nextTypes, nextGroups ] = await Promise.all( [ apiFetch( { path: `${ namespace }/admin/locators/${ locatorId }` } ), apiFetch( { path: `${ namespace }/admin/types` } ), apiFetch( { path: `${ namespace }/admin/groups` } ) ] );
					let page = 1; let allLocations = []; let pages = 1;
					do { const result = await fetchLocations( { page, per_page: 100, status: 'publish', orderby: 'title', order: 'ASC' } ); allLocations = allLocations.concat( result.items ); pages = result.totalPages || 1; page += 1; } while ( page <= pages && page <= 20 );
					if ( cancelled ) return;
					const nextForm = { name: item.name || '', status: item.status || 'draft', config: deepMerge( boot.locatorDefaults || {}, item.config || {} ) };
					setLocator( item ); setForm( nextForm ); setSavedSnapshot( snapshot( nextForm ) ); setTypes( nextTypes || [] ); setGroups( nextGroups || [] ); setLocations( allLocations );
				} catch ( err ) { if ( ! cancelled ) setError( getErrorMessage( err ) ); }
				finally { if ( ! cancelled ) setLoading( false ); }
			}
			load(); return () => { cancelled = true; };
		}, [ locatorId ] );

		useEffect( () => {
			const handler = ( event ) => { if ( dirty && ! allowLeave.current ) { event.preventDefault(); event.returnValue = ''; } };
			window.addEventListener( 'beforeunload', handler ); return () => window.removeEventListener( 'beforeunload', handler );
		}, [ dirty ] );

		useEffect( () => {
			const handler = ( event ) => { if ( ( event.ctrlKey || event.metaKey ) && event.key.toLocaleLowerCase() === 's' ) { event.preventDefault(); if ( form && ! saving ) save(); } };
			window.addEventListener( 'keydown', handler ); return () => window.removeEventListener( 'keydown', handler );
		} );

		useEffect( () => {
			if ( ! form || ! locatorId ) return;
			const timer = window.setTimeout( async () => {
				setPreviewLoading( true ); setPreviewError( '' );
				try {
					const result = await apiFetch( { path: `${ namespace }/admin/locators/${ locatorId }/preview`, method: 'POST', data: { name: form.name, config: form.config } } );
					setPreviewHtml( result && result.html || '' );
					} catch ( err ) {
						setPreviewError( getErrorMessage( err ) );
						const section = builderSectionForField( getErrorField( err ) );
						if ( section ) setActiveSection( section );
					}
				finally { setPreviewLoading( false ); }
			}, 450 );
			return () => window.clearTimeout( timer );
		}, [ locatorId, form && form.name, form && JSON.stringify( form.config ) ] );

		async function save( statusOverride ) {
			if ( ! form ) return;
			setSaving( true ); setError( '' ); setNotice( '' );
			try {
				const targetStatus = statusOverride || form.status;
				const item = await apiFetch( { path: `${ namespace }/admin/locators/${ locatorId }`, method: 'PUT', data: { name: form.name, status: targetStatus, config: form.config } } );
				const nextForm = { name: item.name, status: item.status, config: deepMerge( boot.locatorDefaults || {}, item.config || {} ) };
				setLocator( item ); setForm( nextForm ); setSavedSnapshot( snapshot( nextForm ) ); setNotice( targetStatus === 'publish' ? __( 'Locator saved and published.', 'velox-map-locator' ) : __( 'Locator changes saved.', 'velox-map-locator' ) );
			} catch ( err ) {
				setError( getErrorMessage( err ) );
				const section = builderSectionForField( getErrorField( err ) );
				if ( section ) setActiveSection( section );
			}
			finally { setSaving( false ); }
		}

		if ( loading ) return h( LoadingScreen );
		if ( error && ! form ) return h( Fragment, null, h( PageHeader, { title: __( 'Locator Builder', 'velox-map-locator' ), description: __( 'Configure the public Locator experience.', 'velox-map-locator' ) } ), h( Notice, { status: 'error', isDismissible: false }, error ) );
		if ( ! form ) return null;

		const source = form.config.source || {};
		const layout = form.config.layout || {};
		const map = form.config.map || {};
		const search = form.config.search || {};
		const filters = form.config.filters || {};
		const content = form.config.content || {};
		const appearance = form.config.appearance || {};
		const behaviour = form.config.behaviour || {};
		const privacy = form.config.privacy || {};
		const xyzProfileExists = Boolean( map.provider_profile_id && ( boot.mapProviderProfiles || [] ).some( ( profile ) => profile.id === map.provider_profile_id ) );
		const providerIssue = layout.mode !== 'list_only' && map.provider === 'google' && ! ( boot.googleMaps && boot.googleMaps.configured ) ? __( 'Google Maps is selected but its API key and Map ID are not fully configured.', 'velox-map-locator' ) :
			layout.mode !== 'list_only' && map.provider === 'xyz' && ! xyzProfileExists ? __( 'Custom XYZ is selected but no valid XYZ profile is assigned.', 'velox-map-locator' ) : '';
		const selectedOrder = ( source.manual_order && source.manual_order.length ? source.manual_order : source.selected_ids || [] ).filter( ( id ) => ( source.selected_ids || [] ).map( Number ).includes( Number( id ) ) );
		const sections = [ [ 'source', __( 'Source', 'velox-map-locator' ) ], [ 'layout', __( 'Layout', 'velox-map-locator' ) ], [ 'map', __( 'Map', 'velox-map-locator' ) ], [ 'search', __( 'Search', 'velox-map-locator' ) ], [ 'filters', __( 'Filters', 'velox-map-locator' ) ], [ 'content', __( 'Content', 'velox-map-locator' ) ], [ 'appearance', __( 'Appearance', 'velox-map-locator' ) ], [ 'behaviour', __( 'Behaviour', 'velox-map-locator' ) ], [ 'privacy', __( 'Privacy', 'velox-map-locator' ) ] ];
		const contentOptions = [ [ 'image', __( 'Featured image', 'velox-map-locator' ) ], [ 'address', __( 'Address', 'velox-map-locator' ) ], [ 'status', __( 'Open / operational status', 'velox-map-locator' ) ], [ 'phone', __( 'Phone', 'velox-map-locator' ) ], [ 'email', __( 'Email', 'velox-map-locator' ) ], [ 'website', __( 'Website', 'velox-map-locator' ) ], [ 'contact', __( 'Contact person', 'velox-map-locator' ) ], [ 'hours', __( 'Business hours', 'velox-map-locator' ) ], [ 'description', __( 'Description', 'velox-map-locator' ) ], [ 'directions', __( 'Directions', 'velox-map-locator' ) ], [ 'extra_fields', __( 'Additional fields', 'velox-map-locator' ) ], [ 'type', __( 'Primary Type', 'velox-map-locator' ) ] ];
		const searchOptions = [ [ 'name', __( 'Name', 'velox-map-locator' ) ], [ 'address', __( 'Address', 'velox-map-locator' ) ], [ 'city', __( 'City', 'velox-map-locator' ) ], [ 'region', __( 'Region', 'velox-map-locator' ) ], [ 'country', __( 'Country', 'velox-map-locator' ) ], [ 'type', __( 'Type', 'velox-map-locator' ) ], [ 'group', __( 'Group', 'velox-map-locator' ) ], [ 'description', __( 'Description', 'velox-map-locator' ) ], [ 'extra_fields', __( 'Additional fields', 'velox-map-locator' ) ] ];

		return h( Fragment, null,
			h( 'div', { className: 'vml-builder-topbar' },
				h( 'div', { className: 'vml-builder-title' }, h( 'a', { href: boot.urls.locators, className: 'vml-builder-back', onClick: () => { allowLeave.current = ! dirty; } }, '← ', __( 'Locators', 'velox-map-locator' ) ), h( 'input', { className: 'vml-builder-name', value: form.name, onChange: ( event ) => setForm( { ...form, name: event.target.value } ), 'aria-label': __( 'Locator name', 'velox-map-locator' ) } ), h( StatusBadge, { status: form.status } ), dirty && h( 'span', { className: 'vml-unsaved-badge' }, __( 'Unsaved', 'velox-map-locator' ) ) ),
				h( 'div', { className: 'vml-builder-actions' }, locator && h( 'code', { className: 'vml-shortcode' }, locator.shortcode ), h( Button, { variant: 'secondary', isBusy: saving, disabled: saving || ! dirty, onClick: () => save() }, __( 'Save Changes', 'velox-map-locator' ) ), form.status === 'publish' ? h( Button, { variant: 'tertiary', disabled: saving, onClick: () => save( 'draft' ) }, __( 'Move to Draft', 'velox-map-locator' ) ) : boot.capabilities.publishLocators && h( Button, { variant: 'primary', isBusy: saving, disabled: saving || ! form.name.trim() || Boolean( providerIssue ), onClick: () => save( 'publish' ) }, __( 'Publish', 'velox-map-locator' ) ) )
			),
			notice && h( Notice, { status: 'success', onRemove: () => setNotice( '' ) }, notice ),
			error && h( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ),
			providerIssue && h( Notice, { status: 'warning', isDismissible: false }, h( Fragment, null, providerIssue, ' ', boot.urls && boot.urls.providers && h( 'a', { href: boot.urls.providers }, __( 'Open Map Providers', 'velox-map-locator' ) ) ) ),
			h( 'div', { className: 'vml-builder-device-bar' }, h( 'span', null, __( 'Preview device', 'velox-map-locator' ) ), [ [ 'desktop', __( 'Desktop', 'velox-map-locator' ) ], [ 'tablet', __( 'Tablet', 'velox-map-locator' ) ], [ 'mobile', __( 'Mobile', 'velox-map-locator' ) ] ].map( ( item ) => h( Button, { key: item[ 0 ], variant: device === item[ 0 ] ? 'primary' : 'tertiary', onClick: () => setDevice( item[ 0 ] ) }, item[ 1 ] ) ) ),
			h( 'div', { className: 'vml-builder-layout' },
				h( 'aside', { className: 'vml-builder-nav', 'aria-label': __( 'Locator Builder sections', 'velox-map-locator' ) }, sections.map( ( item ) => h( 'button', { type: 'button', key: item[ 0 ], className: cx( activeSection === item[ 0 ] && 'is-active' ), onClick: () => setActiveSection( item[ 0 ] ) }, item[ 1 ] ) ) ),
				h( 'div', { className: 'vml-builder-settings' },
					h( BuilderSection, { id: 'source', title: __( 'Location Source', 'velox-map-locator' ), description: __( 'Choose which published Locations belong to this Locator.', 'velox-map-locator' ), active: activeSection === 'source', onActivate: () => setActiveSection( 'source' ) },
						h( 'div', { className: 'vml-builder-choice-grid' },
							h( BuilderChoice, { name: 'source-mode', value: 'all', current: source.mode, title: __( 'All Locations', 'velox-map-locator' ), description: __( 'Automatically include every published Location.', 'velox-map-locator' ), onChange: ( value ) => updateConfig( 'source.mode', value ) } ),
							h( BuilderChoice, { name: 'source-mode', value: 'selected', current: source.mode, title: __( 'Selected Locations', 'velox-map-locator' ), description: __( 'Choose exact Locations and control their order.', 'velox-map-locator' ), onChange: ( value ) => updateConfig( 'source.mode', value ) } ),
							h( BuilderChoice, { name: 'source-mode', value: 'dynamic', current: source.mode, title: __( 'Dynamic Rules', 'velox-map-locator' ), description: __( 'Build a set from Type, Group, country or city rules.', 'velox-map-locator' ), onChange: ( value ) => updateConfig( 'source.mode', value ) } )
						),
						source.mode === 'selected' && h( Fragment, null,
							h( LocationMultiPicker, { locations, selectedIds: source.selected_ids || [], onChange: ( ids ) => setForm( ( current ) => { const next = setNested( current.config, 'source.selected_ids', ids ); const order = ( next.source.manual_order || [] ).map( Number ).filter( ( id ) => ids.map( Number ).includes( id ) ); ids.map( Number ).forEach( ( id ) => { if ( ! order.includes( id ) ) order.push( id ); } ); next.source.manual_order = order; return { ...current, config: next }; } ) } ),
							h( SelectedLocationOrder, { locations, ids: selectedOrder, onChange: ( ids ) => setForm( ( current ) => { const next = setNested( current.config, 'source.manual_order', ids ); next.source.selected_ids = ids; return { ...current, config: next }; } ) } )
						),
						source.mode === 'dynamic' && h( Fragment, null,
							h( Field, { label: __( 'Match Rules', 'velox-map-locator' ), hint: __( 'All requires every condition; Any includes a Location when at least one condition matches.', 'velox-map-locator' ) }, h( 'select', { value: source.dynamic && source.dynamic.match || 'all', onChange: ( event ) => updateConfig( 'source.dynamic.match', event.target.value ) }, h( 'option', { value: 'all' }, __( 'Match all conditions', 'velox-map-locator' ) ), h( 'option', { value: 'any' }, __( 'Match any condition', 'velox-map-locator' ) ) ) ),
							h( DynamicConditionsEditor, { value: source.dynamic && source.dynamic.conditions || [], types, groups, onChange: ( value ) => updateConfig( 'source.dynamic.conditions', value ) } ),
							h( LocationMultiPicker, { compact: true, label: __( 'Always exclude these Locations', 'velox-map-locator' ), locations, selectedIds: source.dynamic && source.dynamic.exclude_ids || [], onChange: ( ids ) => updateConfig( 'source.dynamic.exclude_ids', ids ) } )
						)
					),
					h( BuilderSection, { id: 'layout', title: __( 'Layout', 'velox-map-locator' ), description: __( 'Control map/list composition and responsive ordering.', 'velox-map-locator' ), active: activeSection === 'layout', onActivate: () => setActiveSection( 'layout' ) },
						h( 'div', { className: 'vml-builder-choice-grid' }, [ [ 'split', __( 'Split', 'velox-map-locator' ), __( 'Map and Locations side by side.', 'velox-map-locator' ) ], [ 'map_only', __( 'Map Only', 'velox-map-locator' ), __( 'Map first with an accessible list fallback.', 'velox-map-locator' ) ], [ 'list_only', __( 'List Only', 'velox-map-locator' ), __( 'No map engine or map-provider requests.', 'velox-map-locator' ) ] ].map( ( item ) => h( BuilderChoice, { key: item[ 0 ], name: 'layout-mode', value: item[ 0 ], current: layout.mode, title: item[ 1 ], description: item[ 2 ], onChange: ( value ) => updateConfig( 'layout.mode', value ) } ) ) ),
						h( 'div', { className: 'vml-field-grid two' },
							h( Field, { label: __( 'Desktop Height', 'velox-map-locator' ), hint: '300–1200 px' }, h( 'input', { type: 'number', min: 300, max: 1200, value: layout.height || 620, onChange: ( event ) => updateConfig( 'layout.height', Number( event.target.value ) ) } ) ),
							h( Field, { label: __( 'Sidebar Width', 'velox-map-locator' ), hint: __( '20–50%. Velox defaults to 25% for a map-first Split layout.', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 20, max: 50, value: layout.sidebar_width || 25, onChange: ( event ) => updateConfig( 'layout.sidebar_width', Number( event.target.value ) ) } ) ),
							h( Field, { label: __( 'Sidebar Position', 'velox-map-locator' ) }, h( 'select', { value: layout.sidebar_position || 'auto', onChange: ( event ) => updateConfig( 'layout.sidebar_position', event.target.value ) }, h( 'option', { value: 'auto' }, __( 'Automatic / RTL-aware', 'velox-map-locator' ) ), h( 'option', { value: 'left' }, __( 'Left', 'velox-map-locator' ) ), h( 'option', { value: 'right' }, __( 'Right', 'velox-map-locator' ) ) ) ),
							h( Field, { label: __( 'Mobile Order', 'velox-map-locator' ) }, h( 'select', { value: layout.mobile_order || 'map_first', onChange: ( event ) => updateConfig( 'layout.mobile_order', event.target.value ) }, h( 'option', { value: 'map_first' }, __( 'Map first', 'velox-map-locator' ) ), h( 'option', { value: 'locations_first' }, __( 'Locations first', 'velox-map-locator' ) ) ) )
						)
					),
					h( BuilderSection, { id: 'map', title: __( 'Map', 'velox-map-locator' ), description: __( 'Choose provider, initial view and interaction controls.', 'velox-map-locator' ), active: activeSection === 'map', onActivate: () => setActiveSection( 'map' ) },
						layout.mode === 'list_only' ? h( 'div', { className: 'vml-inline-callout' }, h( 'strong', null, __( 'Map disabled by layout', 'velox-map-locator' ) ), h( 'span', null, __( 'List Only does not load Leaflet, Google Maps, OSM tiles or XYZ tiles.', 'velox-map-locator' ) ) ) : h( Fragment, null,
							h( Field, { label: __( 'Map Provider', 'velox-map-locator' ) }, h( 'select', { value: map.provider || 'osm', onChange: ( event ) => updateConfig( 'map.provider', event.target.value ) }, h( 'option', { value: 'osm' }, __( 'OpenStreetMap Standard', 'velox-map-locator' ) ), h( 'option', { value: 'google', disabled: ! ( boot.googleMaps && boot.googleMaps.configured ) }, boot.googleMaps && boot.googleMaps.configured ? __( 'Google Maps', 'velox-map-locator' ) : __( 'Google Maps — configure first', 'velox-map-locator' ) ), h( 'option', { value: 'xyz' }, __( 'Custom XYZ', 'velox-map-locator' ) ) ) ),
							map.provider === 'xyz' && h( Field, { label: __( 'XYZ Profile', 'velox-map-locator' ), required: true }, h( 'select', { value: map.provider_profile_id || '', onChange: ( event ) => updateConfig( 'map.provider_profile_id', event.target.value ) }, h( 'option', { value: '' }, __( 'Choose a profile…', 'velox-map-locator' ) ), ( boot.mapProviderProfiles || [] ).map( ( profile ) => h( 'option', { key: profile.id, value: profile.id }, profile.name ) ) ) ),
							map.provider === 'xyz' && ! ( boot.mapProviderProfiles || [] ).length && h( 'div', { className: 'vml-inline-callout is-warning' }, h( 'strong', null, __( 'No XYZ profiles configured', 'velox-map-locator' ) ), h( 'span', null, __( 'Create a Custom XYZ profile under Map Providers before publishing this Locator.', 'velox-map-locator' ) ) ),
							h( 'div', { className: 'vml-field-grid two' },
								h( Field, { label: __( 'Initial View', 'velox-map-locator' ) }, h( 'select', { value: map.initial_view || 'fit', onChange: ( event ) => updateConfig( 'map.initial_view', event.target.value ) }, h( 'option', { value: 'fit' }, __( 'Fit visible Locations', 'velox-map-locator' ) ), h( 'option', { value: 'fixed' }, __( 'Fixed centre and zoom', 'velox-map-locator' ) ) ) ),
								h( Field, { label: __( 'Single Location Zoom', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 1, max: 22, value: map.single_location_zoom || 14, onChange: ( event ) => updateConfig( 'map.single_location_zoom', Number( event.target.value ) ) } ) )
							),
							map.initial_view === 'fixed' && h( 'div', { className: 'vml-field-grid three' }, h( Field, { label: __( 'Latitude', 'velox-map-locator' ) }, h( 'input', { type: 'number', step: 'any', min: -90, max: 90, value: map.fixed_latitude ?? 0, onChange: ( event ) => updateConfig( 'map.fixed_latitude', event.target.value ) } ) ), h( Field, { label: __( 'Longitude', 'velox-map-locator' ) }, h( 'input', { type: 'number', step: 'any', min: -180, max: 180, value: map.fixed_longitude ?? 0, onChange: ( event ) => updateConfig( 'map.fixed_longitude', event.target.value ) } ) ), h( Field, { label: __( 'Zoom', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 1, max: 22, value: map.fixed_zoom || 10, onChange: ( event ) => updateConfig( 'map.fixed_zoom', Number( event.target.value ) ) } ) ) ),
							h( 'div', { className: 'vml-builder-toggle-list' }, h( BuilderToggle, { label: __( 'Home control', 'velox-map-locator' ), description: __( 'Returns the map to the configured initial view.', 'velox-map-locator' ), checked: map.home_control !== false, onChange: ( value ) => updateConfig( 'map.home_control', value ) } ), h( BuilderToggle, { label: __( 'Fit All control', 'velox-map-locator' ), description: __( 'Fits the currently visible locations into view.', 'velox-map-locator' ), checked: map.fit_control !== false, onChange: ( value ) => updateConfig( 'map.fit_control', value ) } ), h( BuilderToggle, { label: __( 'Zoom controls', 'velox-map-locator' ), checked: map.zoom_controls !== false, onChange: ( value ) => updateConfig( 'map.zoom_controls', value ) } ), h( BuilderToggle, { label: __( 'Zoom level indicator', 'velox-map-locator' ), checked: map.zoom_level_control !== false, onChange: ( value ) => updateConfig( 'map.zoom_level_control', value ) } ), h( BuilderToggle, { label: __( 'Scale', 'velox-map-locator' ), checked: map.scale_control !== false, onChange: ( value ) => updateConfig( 'map.scale_control', value ) } ), h( BuilderToggle, { label: __( 'Fullscreen control', 'velox-map-locator' ), checked: map.fullscreen !== false, onChange: ( value ) => updateConfig( 'map.fullscreen', value ) } ), h( BuilderToggle, { label: __( 'Scroll-wheel zoom', 'velox-map-locator' ), description: __( 'Off by default to avoid trapping normal page scrolling.', 'velox-map-locator' ), checked: map.scroll_zoom === true, onChange: ( value ) => updateConfig( 'map.scroll_zoom', value ) } ), h( BuilderToggle, { label: __( 'Refit after search/filter', 'velox-map-locator' ), checked: map.refit_on_filter !== false, onChange: ( value ) => updateConfig( 'map.refit_on_filter', value ) } ) ),
							h( Field, { label: __( 'Clustering', 'velox-map-locator' ), hint: __( 'Group nearby markers to keep dense maps readable. Auto enables clustering for larger result sets and coincident locations.', 'velox-map-locator' ) }, h( 'select', { value: map.clustering || 'auto', onChange: ( event ) => updateConfig( 'map.clustering', event.target.value ) }, h( 'option', { value: 'auto' }, __( 'Auto', 'velox-map-locator' ) ), h( 'option', { value: 'enabled' }, __( 'Enabled', 'velox-map-locator' ) ), h( 'option', { value: 'disabled' }, __( 'Disabled', 'velox-map-locator' ) ) ) )
						)
					),
					h( BuilderSection, { id: 'search', title: __( 'Search', 'velox-map-locator' ), description: __( 'Control visitor search and searchable fields.', 'velox-map-locator' ), active: activeSection === 'search', onActivate: () => setActiveSection( 'search' ) },
						h( BuilderToggle, { label: __( 'Enable search', 'velox-map-locator' ), checked: search.enabled !== false, onChange: ( value ) => updateConfig( 'search.enabled', value ) } ),
						search.enabled !== false && h( Fragment, null, h( Field, { label: __( 'Placeholder', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: search.placeholder || '', onChange: ( event ) => updateConfig( 'search.placeholder', event.target.value ) } ) ), h( 'div', { className: 'vml-builder-subhead' }, h( 'strong', null, __( 'Searchable fields', 'velox-map-locator' ) ) ), h( BuilderCheckboxes, { options: searchOptions, value: search.fields || [], onChange: ( value ) => updateConfig( 'search.fields', value ) } ) )
					),
					h( BuilderSection, { id: 'filters', title: __( 'Filters', 'velox-map-locator' ), description: __( 'Expose useful dimensions without showing redundant controls.', 'velox-map-locator' ), active: activeSection === 'filters', onActivate: () => setActiveSection( 'filters' ) },
						h( Field, { label: __( 'Filter Style', 'velox-map-locator' ) }, h( 'select', { value: filters.style || 'pills', onChange: ( event ) => updateConfig( 'filters.style', event.target.value ) }, h( 'option', { value: 'pills' }, __( 'Pills when practical', 'velox-map-locator' ) ), h( 'option', { value: 'dropdown' }, __( 'Dropdowns', 'velox-map-locator' ) ) ) ),
						h( 'div', { className: 'vml-builder-subhead' }, h( 'strong', null, __( 'Filter dimensions', 'velox-map-locator' ) ) ),
						h( BuilderCheckboxes, { options: [ [ 'type', __( 'Type', 'velox-map-locator' ) ], [ 'group', __( 'Group', 'velox-map-locator' ) ], [ 'country', __( 'Country', 'velox-map-locator' ) ], [ 'city', __( 'City', 'velox-map-locator' ) ] ], value: filters.dimensions || [], onChange: ( value ) => updateConfig( 'filters.dimensions', value ) } ),
						h( BuilderToggle, { label: __( 'Show result count', 'velox-map-locator' ), checked: filters.show_result_count !== false, onChange: ( value ) => updateConfig( 'filters.show_result_count', value ) } )
					),
					h( BuilderSection, { id: 'content', title: __( 'Content', 'velox-map-locator' ), description: __( 'Choose what cards and map popups disclose to visitors.', 'velox-map-locator' ), active: activeSection === 'content', onActivate: () => setActiveSection( 'content' ) },
						h( 'div', { className: 'vml-builder-subhead' }, h( 'strong', null, __( 'Location card fields', 'velox-map-locator' ) ), h( 'span', null, __( 'Used in Split and List Only layouts.', 'velox-map-locator' ) ) ), h( BuilderCheckboxes, { options: contentOptions, value: content.card_fields || [], onChange: ( value ) => updateConfig( 'content.card_fields', value ) } ),
						h( 'div', { className: 'vml-builder-subhead' }, h( 'strong', null, __( 'Map popup / detail fields', 'velox-map-locator' ) ), h( 'span', null, __( 'Only configured fields enter the public Locator payload.', 'velox-map-locator' ) ) ), h( BuilderCheckboxes, { options: contentOptions, value: content.popup_fields || [], onChange: ( value ) => updateConfig( 'content.popup_fields', value ) } )
					),
					h( BuilderSection, { id: 'appearance', title: __( 'Appearance', 'velox-map-locator' ), description: __( 'Apply a theme family, mode, typography and accent.', 'velox-map-locator' ), active: activeSection === 'appearance', onActivate: () => setActiveSection( 'appearance' ) },
						h( 'div', { className: 'vml-field-grid two' }, h( Field, { label: __( 'Theme', 'velox-map-locator' ) }, h( 'select', { value: appearance.theme || 'velox', onChange: ( event ) => updateConfig( 'appearance.theme', event.target.value ) }, [ 'velox', 'slate', 'azure', 'forest' ].map( ( value ) => h( 'option', { key: value, value }, humanize( value ) ) ) ) ), h( Field, { label: __( 'Colour Mode', 'velox-map-locator' ) }, h( 'select', { value: appearance.mode || 'light', onChange: ( event ) => updateConfig( 'appearance.mode', event.target.value ) }, h( 'option', { value: 'light' }, __( 'Light', 'velox-map-locator' ) ), h( 'option', { value: 'dark' }, __( 'Dark', 'velox-map-locator' ) ), h( 'option', { value: 'auto' }, __( 'Auto', 'velox-map-locator' ) ) ) ), h( Field, { label: __( 'Typography', 'velox-map-locator' ) }, h( 'select', { value: appearance.typography || 'inherit', onChange: ( event ) => updateConfig( 'appearance.typography', event.target.value ) }, [ [ 'inherit', __( 'Inherit Site', 'velox-map-locator' ) ], [ 'modern-sans', __( 'Modern Sans', 'velox-map-locator' ) ], [ 'humanist-sans', __( 'Humanist', 'velox-map-locator' ) ], [ 'classic-sans', __( 'Classic Sans', 'velox-map-locator' ) ], [ 'serif', __( 'Serif', 'velox-map-locator' ) ] ].map( ( item ) => h( 'option', { key: item[ 0 ], value: item[ 0 ] }, item[ 1 ] ) ) ) ), h( Field, { label: __( 'Density', 'velox-map-locator' ) }, h( 'select', { value: appearance.density || 'comfortable', onChange: ( event ) => updateConfig( 'appearance.density', event.target.value ) }, [ 'compact', 'comfortable', 'spacious' ].map( ( value ) => h( 'option', { key: value, value }, humanize( value ) ) ) ) ) ),
						h( ColorField, { label: __( 'Accent Colour', 'velox-map-locator' ), value: appearance.accent || '#2563eb', onChange: ( value ) => updateConfig( 'appearance.accent', value ) } )
					),
					h( BuilderSection, { id: 'behaviour', title: __( 'Behaviour', 'velox-map-locator' ), description: __( 'Control location selection, distance and deep linking.', 'velox-map-locator' ), active: activeSection === 'behaviour', onActivate: () => setActiveSection( 'behaviour' ) },
						h( 'div', { className: 'vml-builder-toggle-list' }, h( BuilderToggle, { label: __( 'Near Me', 'velox-map-locator' ), description: __( 'Requests browser geolocation only after the visitor clicks Near Me.', 'velox-map-locator' ), checked: behaviour.near_me !== false, onChange: ( value ) => updateConfig( 'behaviour.near_me', value ) } ), h( BuilderToggle, { label: __( 'Deep linking', 'velox-map-locator' ), checked: behaviour.deep_linking !== false, onChange: ( value ) => updateConfig( 'behaviour.deep_linking', value ) } ), h( BuilderToggle, { label: __( 'Pan map on selection', 'velox-map-locator' ), checked: behaviour.pan_on_select !== false, onChange: ( value ) => updateConfig( 'behaviour.pan_on_select', value ) } ), h( BuilderToggle, { label: __( 'Open popup on selection', 'velox-map-locator' ), checked: behaviour.open_popup_on_select !== false, onChange: ( value ) => updateConfig( 'behaviour.open_popup_on_select', value ) } ) ),
						h( Field, { label: __( 'Distance Unit', 'velox-map-locator' ) }, h( 'select', { value: behaviour.distance_unit || 'auto', onChange: ( event ) => updateConfig( 'behaviour.distance_unit', event.target.value ) }, h( 'option', { value: 'auto' }, __( 'Automatic by visitor locale', 'velox-map-locator' ) ), h( 'option', { value: 'kilometres' }, __( 'Kilometres', 'velox-map-locator' ) ), h( 'option', { value: 'miles' }, __( 'Miles', 'velox-map-locator' ) ) ) )
					),
					h( BuilderSection, { id: 'privacy', title: __( 'Privacy', 'velox-map-locator' ), description: __( 'Control when external map services may receive browser requests.', 'velox-map-locator' ), active: activeSection === 'privacy', onActivate: () => setActiveSection( 'privacy' ) },
						h( Field, { label: __( 'Map Loading', 'velox-map-locator' ), hint: __( 'Interaction mode leaves the Location list available while delaying Google/OSM/XYZ requests until Load Map is clicked.', 'velox-map-locator' ) }, h( 'select', { value: privacy.map_load_mode || 'inherit', onChange: ( event ) => updateConfig( 'privacy.map_load_mode', event.target.value ) }, h( 'option', { value: 'inherit' }, __( 'Use global default', 'velox-map-locator' ) ), h( 'option', { value: 'immediate' }, __( 'Load when Locator is near viewport', 'velox-map-locator' ) ), h( 'option', { value: 'interaction' }, __( 'Require visitor to click Load Map', 'velox-map-locator' ) ) ) ),
						h( 'div', { className: 'vml-inline-callout' }, h( 'strong', null, __( 'Privacy note', 'velox-map-locator' ) ), h( 'span', null, __( 'This setting controls external map loading; it does not claim legal compliance for any particular jurisdiction.', 'velox-map-locator' ) ) )
					)
				),
				h( LocatorPreview, { html: previewHtml, loading: previewLoading, error: previewError, device } )
			)
		);
	}


	function MapProviders() {
		const [ data, setData ] = useState( null );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( '' );
		const [ notice, setNotice ] = useState( '' );
		const [ dialog, setDialog ] = useState( null );
		const [ googleOpen, setGoogleOpen ] = useState( false );
		const load = () => {
			setLoading( true ); setError( '' );
			return apiFetch( { path: `${ namespace }/admin/providers` } ).then( setData ).catch( ( err ) => setError( getErrorMessage( err ) ) ).finally( () => setLoading( false ) );
		};
		useEffect( () => { load(); }, [] );
		const removeProfile = async ( profile ) => {
			if ( ! window.confirm( sprintf( __( 'Delete XYZ profile “%s”?', 'velox-map-locator' ), profile.name ) ) ) return;
			try {
				await apiFetch( { path: `${ namespace }/admin/providers/xyz/${ escapePath( profile.id ) }`, method: 'DELETE' } );
				setNotice( __( 'XYZ profile deleted.', 'velox-map-locator' ) );
				load();
			} catch ( err ) { setError( getErrorMessage( err ) ); }
		};
		return h( Fragment, null,
			h( PageHeader, { title: __( 'Map Providers', 'velox-map-locator' ), description: __( 'Manage the map services available to Locators. Provider profiles are reusable and do not load on unrelated pages.', 'velox-map-locator' ) }, boot.capabilities.manageProviders && h( Button, { variant: 'primary', onClick: () => setDialog( {} ), icon: h( Icon, { name: 'plus', size: 18 } ) }, __( 'Add XYZ Profile', 'velox-map-locator' ) ) ),
			notice && h( Notice, { status: 'success', onRemove: () => setNotice( '' ) }, notice ),
			error && h( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ),
			loading ? h( LoadingScreen ) : h( Fragment, null,
				h( 'div', { className: 'vml-provider-grid' },
					h( 'section', { className: 'vml-panel vml-provider-card' },
						h( 'div', { className: 'vml-provider-card-head' }, h( 'span', { className: 'vml-provider-icon' }, h( Icon, { name: 'pin', size: 20 } ) ), h( 'div', null, h( 'h2', null, __( 'OpenStreetMap Standard', 'velox-map-locator' ) ), h( 'p', null, __( 'Built-in raster tiles rendered with the locally bundled Leaflet engine.', 'velox-map-locator' ) ) ), h( 'span', { className: 'vml-status-badge is-success' }, h( 'span', { className: 'vml-status-dot' } ), __( 'Ready', 'velox-map-locator' ) ) ),
						h( 'div', { className: 'vml-provider-meta' }, h( 'span', null, __( 'No API key', 'velox-map-locator' ) ), h( 'span', null, __( 'Leaflet 1.9.4', 'velox-map-locator' ) ), h( 'span', null, __( 'External tile service', 'velox-map-locator' ) ) ),
						h( 'p', { className: 'vml-provider-note' }, __( 'OpenStreetMap Standard is suitable for testing and modest usage. Site owners remain responsible for following the tile service usage policy.', 'velox-map-locator' ) )
					),
					h( 'section', { className: 'vml-panel vml-provider-card' },
						h( 'div', { className: 'vml-provider-card-head' }, h( 'span', { className: 'vml-provider-icon' }, h( Icon, { name: 'pin', size: 20 } ) ), h( 'div', null, h( 'h2', null, __( 'Google Maps', 'velox-map-locator' ) ), h( 'p', null, __( 'Google Maps JavaScript API with Advanced Markers and optional address geocoding in the Location editor.', 'velox-map-locator' ) ) ), h( 'span', { className: cx( 'vml-status-badge', data && data.google && data.google.configured ? 'is-success' : 'is-neutral' ) }, h( 'span', { className: 'vml-status-dot' } ), data && data.google && data.google.configured ? __( 'Ready', 'velox-map-locator' ) : __( 'Needs setup', 'velox-map-locator' ) ) ),
						h( 'div', { className: 'vml-provider-meta' }, h( 'span', null, data && data.google && data.google.has_api_key ? ( data.google.api_key_source === 'constant' ? __( 'API key from wp-config.php', 'velox-map-locator' ) : data.google.api_key_masked ) : __( 'API key required', 'velox-map-locator' ) ), h( 'span', null, data && data.google && data.google.map_id ? sprintf( __( 'Map ID: %s', 'velox-map-locator' ), data.google.map_id ) : __( 'Map ID required', 'velox-map-locator' ) ), h( 'span', null, __( 'Advanced Markers', 'velox-map-locator' ) ) ),
						h( 'p', { className: 'vml-provider-note' }, __( 'The browser API key is necessarily visible to visitors when Google Maps loads. Restrict it to your websites and only the Maps Platform APIs your site needs.', 'velox-map-locator' ) ),
						boot.capabilities.manageProviders && h( 'div', { className: 'vml-provider-card-actions' }, h( Button, { variant: 'secondary', onClick: () => setGoogleOpen( true ) }, data && data.google && data.google.configured ? __( 'Configure Google Maps', 'velox-map-locator' ) : __( 'Set Up Google Maps', 'velox-map-locator' ) ) )
					),
					h( 'section', { className: 'vml-panel vml-provider-card' },
						h( 'div', { className: 'vml-provider-card-head' }, h( 'span', { className: 'vml-provider-icon' }, h( Icon, { name: 'building', size: 20 } ) ), h( 'div', null, h( 'h2', null, __( 'Custom XYZ', 'velox-map-locator' ) ), h( 'p', null, __( 'Connect any compatible raster tile service using a reusable URL-template profile.', 'velox-map-locator' ) ) ), h( 'span', { className: cx( 'vml-status-badge', data && data.xyz_profiles && data.xyz_profiles.length ? 'is-success' : 'is-neutral' ) }, h( 'span', { className: 'vml-status-dot' } ), data && data.xyz_profiles && data.xyz_profiles.length ? sprintf( _n( '%d profile', '%d profiles', data.xyz_profiles.length, 'velox-map-locator' ), data.xyz_profiles.length ) : __( 'Not configured', 'velox-map-locator' ) ) ),
						h( 'div', { className: 'vml-provider-meta' }, h( 'span', null, __( 'Leaflet engine', 'velox-map-locator' ) ), h( 'span', null, __( 'TMS optional', 'velox-map-locator' ) ), h( 'span', null, __( 'Retina optional', 'velox-map-locator' ) ) ),
						h( 'p', { className: 'vml-provider-note' }, __( 'Tile URLs are sent to the visitor’s browser. Do not place private server-side secrets in an XYZ template.', 'velox-map-locator' ) )
					)
				),
				h( 'section', { className: 'vml-panel vml-xyz-panel' },
					h( 'div', { className: 'vml-panel-header' }, h( 'div', null, h( 'h2', null, __( 'XYZ Profiles', 'velox-map-locator' ) ), h( 'p', null, __( 'Each profile stores a tile URL template, attribution and rendering limits. A profile can be reused by multiple Locators.', 'velox-map-locator' ) ) ) ),
					data && data.xyz_profiles && data.xyz_profiles.length ? h( 'div', { className: 'vml-xyz-list' }, data.xyz_profiles.map( ( profile ) => h( 'article', { className: 'vml-xyz-row', key: profile.id },
						h( 'div', { className: 'vml-xyz-main' }, h( 'strong', null, profile.name ), h( 'small', null, tileHost( profile.tile_url ) || profile.tile_url ) ),
						h( 'div', { className: 'vml-xyz-meta' }, h( 'span', { className: 'vml-subtle-chip' }, sprintf( __( 'Zoom %1$d–%2$d', 'velox-map-locator' ), profile.min_zoom, profile.max_zoom ) ), profile.tms && h( 'span', { className: 'vml-subtle-chip' }, 'TMS' ), profile.detect_retina && h( 'span', { className: 'vml-subtle-chip' }, __( 'Retina', 'velox-map-locator' ) ), h( 'span', { className: 'vml-subtle-chip' }, sprintf( _n( '%d Locator', '%d Locators', Number( profile.usage_count || 0 ), 'velox-map-locator' ), Number( profile.usage_count || 0 ) ) ) ),
						h( 'div', { className: 'vml-xyz-actions' }, h( Button, { variant: 'secondary', onClick: () => setDialog( { profile } ) }, __( 'Edit', 'velox-map-locator' ) ), h( Button, { variant: 'tertiary', isDestructive: true, disabled: Number( profile.usage_count || 0 ) > 0, onClick: () => removeProfile( profile ) }, __( 'Delete', 'velox-map-locator' ) ) )
					) ) ) : h( EmptyState, { title: __( 'No Custom XYZ profiles yet', 'velox-map-locator' ), description: __( 'Add a profile when you have a compatible XYZ raster tile URL such as https://tiles.example.com/{z}/{x}/{y}.png.', 'velox-map-locator' ), action: boot.capabilities.manageProviders && h( Button, { variant: 'secondary', onClick: () => setDialog( {} ) }, __( 'Add XYZ Profile', 'velox-map-locator' ) ) } )
				)
			),
			dialog && h( XYZProfileDialog, { profile: dialog.profile, onClose: () => setDialog( null ), onSaved: ( saved ) => { setDialog( null ); setNotice( dialog.profile ? __( 'XYZ profile updated.', 'velox-map-locator' ) : sprintf( __( 'XYZ profile “%s” created.', 'velox-map-locator' ), saved.name ) ); load(); } } ),
			googleOpen && h( GoogleSettingsDialog, { settings: data && data.google || {}, onClose: () => setGoogleOpen( false ), onSaved: () => { setGoogleOpen( false ); setNotice( __( 'Google Maps settings saved.', 'velox-map-locator' ) ); load(); } } )
		);
	}

	function GoogleSettingsDialog( { settings, onClose, onSaved } ) {
		const usesConstant = settings && settings.api_key_source === 'constant';
		const [ form, setForm ] = useState( { api_key: '', map_id: settings && settings.map_id || '', region: settings && settings.region || 'auto', clear_api_key: false } );
		const [ saving, setSaving ] = useState( false );
		const [ testing, setTesting ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ testMessage, setTestMessage ] = useState( '' );
		const update = ( key, value ) => setForm( ( current ) => ( { ...current, [ key ]: value } ) );
		const effectiveKey = String( form.api_key || ( boot.googleMaps && boot.googleMaps.apiKey ) || '' ).trim();
		const submit = async ( event ) => {
			event.preventDefault(); setSaving( true ); setError( '' ); setTestMessage( '' );
			try {
				const saved = await apiFetch( { path: `${ namespace }/admin/providers/google`, method: 'PUT', data: form } );
				if ( boot.googleMaps ) {
					if ( ! usesConstant ) {
						if ( form.clear_api_key ) boot.googleMaps.apiKey = '';
						else if ( String( form.api_key || '' ).trim() ) boot.googleMaps.apiKey = String( form.api_key ).trim();
					}
					boot.googleMaps.mapId = saved && saved.map_id || String( form.map_id || '' ).trim();
					boot.googleMaps.region = saved && saved.region || String( form.region || 'auto' ).trim();
					boot.googleMaps.configured = Boolean( saved && saved.configured );
				}
				onSaved( saved );
			} catch ( err ) { setError( getErrorMessage( err ) ); } finally { setSaving( false ); }
		};
		const testConnection = async () => {
			setTesting( true ); setError( '' ); setTestMessage( '' );
			try {
				const maps = await loadGoogleAdmin( { apiKey: effectiveKey, region: form.region } );
				await maps.importLibrary( 'maps' );
				await maps.importLibrary( 'marker' );
				setTestMessage( __( 'Google Maps JavaScript API and Advanced Markers loaded successfully in this browser.', 'velox-map-locator' ) );
			} catch ( err ) { setError( getErrorMessage( err ) ); } finally { setTesting( false ); }
		};
		return h( Modal, { title: __( 'Google Maps Settings', 'velox-map-locator' ), onRequestClose: onClose, className: 'vml-term-modal vml-provider-modal', overlayClassName: cx( 'vml-term-modal-overlay', `vml-term-modal-overlay--${ boot.adminAppearance || 'system' }` ), shouldCloseOnClickOutside: true },
			h( 'form', { onSubmit: submit },
				h( 'p', { className: 'vml-modal-intro' }, __( 'Google Maps loads only for Locators that use this provider, and Privacy Mode can delay the external request until the visitor chooses Load Map.', 'velox-map-locator' ) ),
				error && h( Notice, { status: 'error', isDismissible: false }, error ),
				testMessage && h( Notice, { status: 'success', isDismissible: false }, testMessage ),
				h( 'div', { className: 'vml-modal-body' },
					usesConstant ? h( 'div', { className: 'vml-inline-callout' }, h( 'strong', null, __( 'API key supplied by wp-config.php', 'velox-map-locator' ) ), h( 'span', null, __( 'VELOX_MAP_LOCATOR_GOOGLE_API_KEY is defined, so the database key field is intentionally disabled.', 'velox-map-locator' ) ) ) : h( Field, { label: __( 'Browser API Key', 'velox-map-locator' ), required: ! settings.has_api_key, hint: settings.has_api_key ? sprintf( __( 'Current key: %s. Leave this blank to keep it.', 'velox-map-locator' ), settings.api_key_masked || '••••••' ) : __( 'Use a Google Maps Platform browser key restricted to your website.', 'velox-map-locator' ) }, h( 'input', { type: 'password', autoComplete: 'new-password', value: form.api_key, onChange: ( event ) => update( 'api_key', event.target.value ), placeholder: settings.has_api_key ? __( 'Leave blank to keep current key', 'velox-map-locator' ) : 'AIza…' } ) ),
					! usesConstant && settings.has_api_key && h( 'label', { className: 'vml-switch-row compact' }, h( 'input', { type: 'checkbox', checked: Boolean( form.clear_api_key ), onChange: ( event ) => update( 'clear_api_key', event.target.checked ) } ), h( 'span', { className: 'vml-switch' } ), h( 'span', null, h( 'strong', null, __( 'Clear saved API key', 'velox-map-locator' ) ) ) ),
					h( Field, { label: __( 'Map ID', 'velox-map-locator' ), required: true, hint: __( 'Advanced Markers require a Google Maps map ID. DEMO_MAP_ID may be used for temporary testing only.', 'velox-map-locator' ) }, h( 'input', { className: 'vml-locator-name-input', type: 'text', required: true, spellCheck: false, value: form.map_id, onChange: ( event ) => update( 'map_id', event.target.value ), placeholder: 'YOUR_MAP_ID' } ) ),
					h( Field, { label: __( 'Region', 'velox-map-locator' ), hint: __( 'Optional two-letter region hint such as AE, GB or US. Leave Auto unless you need region-specific behavior.', 'velox-map-locator' ) }, h( 'input', { type: 'text', maxLength: 4, value: form.region, onChange: ( event ) => update( 'region', event.target.value.toUpperCase() ), placeholder: 'AUTO' } ) ),
					h( 'div', { className: 'vml-provider-disclosure' }, h( 'h3', null, __( 'Security & billing', 'velox-map-locator' ) ), h( 'p', null, __( 'Google Maps Platform usage can incur charges. Restrict browser keys by authorized websites and by the Maps Platform APIs actually used by the site.', 'velox-map-locator' ) ) ),
					h( 'div', { className: 'vml-inline-callout' }, h( 'strong', null, __( 'Client-side key visibility', 'velox-map-locator' ) ), h( 'span', null, __( 'A browser API key cannot be kept secret from visitors. Security comes from Google Cloud application/API restrictions, not from hiding the key in WordPress.', 'velox-map-locator' ) ) )
				),
				h( 'div', { className: 'vml-modal-footer' }, h( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'velox-map-locator' ) ), h( Button, { type: 'button', variant: 'secondary', isBusy: testing, disabled: testing || ! effectiveKey || ! String( form.map_id || '' ).trim(), onClick: testConnection }, __( 'Test Connection', 'velox-map-locator' ) ), h( Button, { type: 'submit', variant: 'primary', isBusy: saving, disabled: saving || ! String( form.map_id || '' ).trim() || ( ! usesConstant && ! settings.has_api_key && ! String( form.api_key || '' ).trim() ) }, __( 'Save Google Maps', 'velox-map-locator' ) ) )
			)
		);
	}

	function tileHost( value ) {
		try { return new URL( String( value || '' ).replace( /\{[^}]+\}/g, '0' ) ).host; } catch ( err ) { return ''; }
	}

	function XYZProfileDialog( { profile, onClose, onSaved } ) {
		const [ form, setForm ] = useState( profile ? clone( profile ) : { name: '', tile_url: '', attribution: '', min_zoom: 0, max_zoom: 19, subdomains: [], tms: false, detect_retina: false, service_url: '', terms_url: '', privacy_url: '' } );
		const [ saving, setSaving ] = useState( false );
		const [ error, setError ] = useState( '' );
		const update = ( key, value ) => setForm( ( current ) => ( { ...current, [ key ]: value } ) );
		const submit = async ( event ) => {
			event.preventDefault(); setSaving( true ); setError( '' );
			const payload = { ...form, subdomains: Array.isArray( form.subdomains ) ? form.subdomains : String( form.subdomains || '' ).split( ',' ).map( ( item ) => item.trim() ).filter( Boolean ) };
			try {
				const path = profile ? `${ namespace }/admin/providers/xyz/${ escapePath( profile.id ) }` : `${ namespace }/admin/providers/xyz`;
				const saved = await apiFetch( { path, method: profile ? 'PUT' : 'POST', data: { profile: payload } } );
				onSaved( saved );
			} catch ( err ) { setError( getErrorMessage( err ) ); } finally { setSaving( false ); }
		};
		const subdomainText = Array.isArray( form.subdomains ) ? form.subdomains.join( ', ' ) : String( form.subdomains || '' );
		return h( Modal, { title: profile ? __( 'Edit XYZ Profile', 'velox-map-locator' ) : __( 'Add XYZ Profile', 'velox-map-locator' ), onRequestClose: onClose, className: 'vml-term-modal vml-provider-modal', overlayClassName: cx( 'vml-term-modal-overlay', `vml-term-modal-overlay--${ boot.adminAppearance || 'system' }` ), shouldCloseOnClickOutside: true },
			h( 'form', { onSubmit: submit },
				h( 'p', { className: 'vml-modal-intro' }, __( 'Velox stores this profile locally and sends the configured tile URL to the browser only when a Locator using it loads its map.', 'velox-map-locator' ) ),
				error && h( Notice, { status: 'error', isDismissible: false }, error ),
				h( 'div', { className: 'vml-modal-body' },
					h( Field, { label: __( 'Profile Name', 'velox-map-locator' ), required: true, hint: __( 'Internal label such as Company Tiles or Carto Light.', 'velox-map-locator' ) }, h( 'input', { className: 'vml-locator-name-input', autoFocus: true, type: 'text', required: true, value: form.name || '', onChange: ( event ) => update( 'name', event.target.value ) } ) ),
					h( Field, { label: __( 'Tile URL Template', 'velox-map-locator' ), required: true, hint: __( 'Must include {z}, {x}, and {y} or {-y}. {s} and {r} are optional Leaflet placeholders.', 'velox-map-locator' ) }, h( 'input', { type: 'text', required: true, spellCheck: false, value: form.tile_url || '', onChange: ( event ) => update( 'tile_url', event.target.value ), placeholder: 'https://tiles.example.com/{z}/{x}/{y}.png' } ) ),
					h( Field, { label: __( 'Attribution', 'velox-map-locator' ), hint: __( 'HTML links are allowed and will appear in the map attribution control. Follow your tile provider’s attribution requirements.', 'velox-map-locator' ) }, h( 'textarea', { rows: 3, value: form.attribution || '', onChange: ( event ) => update( 'attribution', event.target.value ), placeholder: '© Map data provider' } ) ),
					h( 'div', { className: 'vml-field-grid two' }, h( Field, { label: __( 'Minimum Zoom', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 0, max: 22, value: form.min_zoom ?? 0, onChange: ( event ) => update( 'min_zoom', Number( event.target.value ) ) } ) ), h( Field, { label: __( 'Maximum Zoom', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 0, max: 22, value: form.max_zoom ?? 19, onChange: ( event ) => update( 'max_zoom', Number( event.target.value ) ) } ) ) ),
					h( Field, { label: __( 'Subdomains', 'velox-map-locator' ), hint: __( 'Optional comma-separated values used by the {s} placeholder, for example a, b, c.', 'velox-map-locator' ) }, h( 'input', { type: 'text', value: subdomainText, onChange: ( event ) => update( 'subdomains', event.target.value ), placeholder: 'a, b, c' } ) ),
					h( 'div', { className: 'vml-provider-switches' },
						h( 'label', { className: 'vml-switch-row compact' }, h( 'input', { type: 'checkbox', checked: Boolean( form.tms ), onChange: ( event ) => update( 'tms', event.target.checked ) } ), h( 'span', { className: 'vml-switch' } ), h( 'span', null, h( 'strong', null, __( 'TMS tile numbering', 'velox-map-locator' ) ), h( 'small', null, __( 'Invert the Y axis for TMS-compatible services.', 'velox-map-locator' ) ) ) ),
						h( 'label', { className: 'vml-switch-row compact' }, h( 'input', { type: 'checkbox', checked: Boolean( form.detect_retina ), onChange: ( event ) => update( 'detect_retina', event.target.checked ) } ), h( 'span', { className: 'vml-switch' } ), h( 'span', null, h( 'strong', null, __( 'Retina detection', 'velox-map-locator' ) ), h( 'small', null, __( 'Let Leaflet request higher-density tiles when the profile supports them.', 'velox-map-locator' ) ) ) )
					),
					h( 'div', { className: 'vml-provider-disclosure' }, h( 'h3', null, __( 'Service disclosure', 'velox-map-locator' ) ), h( 'p', null, __( 'Optional links help site owners document the external tile service they chose.', 'velox-map-locator' ) ),
						h( Field, { label: __( 'Service URL', 'velox-map-locator' ) }, h( 'input', { type: 'url', value: form.service_url || '', onChange: ( event ) => update( 'service_url', event.target.value ) } ) ),
						h( Field, { label: __( 'Terms / Usage Policy URL', 'velox-map-locator' ) }, h( 'input', { type: 'url', value: form.terms_url || '', onChange: ( event ) => update( 'terms_url', event.target.value ) } ) ),
						h( Field, { label: __( 'Privacy URL', 'velox-map-locator' ) }, h( 'input', { type: 'url', value: form.privacy_url || '', onChange: ( event ) => update( 'privacy_url', event.target.value ) } ) )
					),
					h( 'div', { className: 'vml-inline-callout' }, h( 'strong', null, __( 'Public configuration', 'velox-map-locator' ) ), h( 'span', null, __( 'Anything embedded in the tile URL, including access tokens, is visible to visitors because their browsers request the tiles directly.', 'velox-map-locator' ) ) )
				),
				h( 'div', { className: 'vml-modal-footer' }, h( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'velox-map-locator' ) ), h( Button, { type: 'submit', variant: 'primary', isBusy: saving, disabled: saving || ! String( form.name || '' ).trim() || ! String( form.tile_url || '' ).trim() }, profile ? __( 'Save Profile', 'velox-map-locator' ) : __( 'Add Profile', 'velox-map-locator' ) ) )
			)
		);
	}

	function Classifications() {
		const [ tab, setTab ] = useState( 'type' );
		const [ types, setTypes ] = useState( [] );
		const [ groups, setGroups ] = useState( [] );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( '' );
		const [ notice, setNotice ] = useState( '' );
		const [ dialog, setDialog ] = useState( null );
		const load = () => { setLoading( true ); return Promise.all( [ apiFetch( { path: `${ namespace }/admin/types` } ), apiFetch( { path: `${ namespace }/admin/groups` } ) ] ).then( ( [ nextTypes, nextGroups ] ) => { setTypes( nextTypes ); setGroups( nextGroups ); } ).catch( ( err ) => setError( getErrorMessage( err ) ) ).finally( () => setLoading( false ) ); };
		useEffect( () => { load(); }, [] );
		const current = tab === 'type' ? types : hierarchyOrder( groups );
		const removeTerm = async ( item ) => {
			if ( ! window.confirm( sprintf( __( 'Delete “%s”? Locations will remain, but this classification will be removed.', 'velox-map-locator' ), item.name ) ) ) return;
			try { await apiFetch( { path: `${ namespace }/admin/${ tab === 'type' ? 'types' : 'groups' }/${ item.id }`, method: 'DELETE' } ); setNotice( __( 'Classification deleted.', 'velox-map-locator' ) ); load(); } catch ( err ) { setError( getErrorMessage( err ) ); }
		};
		return h( Fragment, null,
			h( PageHeader, { title: __( 'Types & Groups', 'velox-map-locator' ), description: __( 'Create reusable location classifications for filtering, marker inheritance and organisation.', 'velox-map-locator' ) }, boot.capabilities.manageTerms && h( Button, { variant: 'primary', onClick: () => setDialog( { kind: tab } ), icon: h( Icon, { name: 'plus', size: 18 } ) }, tab === 'type' ? __( 'Add Type', 'velox-map-locator' ) : __( 'Add Group', 'velox-map-locator' ) ) ),
			notice && h( Notice, { status: 'success', onRemove: () => setNotice( '' ) }, notice ), error && h( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ),
			h( 'section', { className: 'vml-panel vml-classifications-panel' },
				h( 'div', { className: 'vml-tabs', role: 'tablist' }, h( 'button', { type: 'button', role: 'tab', 'aria-selected': tab === 'type', className: cx( tab === 'type' && 'is-active' ), onClick: () => setTab( 'type' ) }, __( 'Types', 'velox-map-locator' ), h( 'span', null, types.length ) ), h( 'button', { type: 'button', role: 'tab', 'aria-selected': tab === 'group', className: cx( tab === 'group' && 'is-active' ), onClick: () => setTab( 'group' ) }, __( 'Groups', 'velox-map-locator' ), h( 'span', null, groups.length ) ) ),
				loading ? h( LoadingScreen ) : current.length ? h( 'div', { className: 'vml-term-list' }, current.map( ( item ) => h( 'div', { key: item.id, className: 'vml-term-row', style: tab === 'group' ? { '--vml-depth': item.depth || 0 } : undefined },
					tab === 'type' ? h( MarkerGlyph, { icon: item.marker && item.marker.icon || 'pin', color: item.marker && item.marker.color || '#2563eb', iconColor: item.marker && item.marker.icon_color || '#ffffff', size: 'small' } ) : h( 'span', { className: 'vml-group-branch', 'aria-hidden': 'true' }, item.depth ? '↳' : '●' ),
					h( 'div', { className: 'vml-term-copy' }, h( 'strong', null, item.name ), h( 'small', null, item.description || ( tab === 'type' ? __( 'Location type', 'velox-map-locator' ) : __( 'Location group', 'velox-map-locator' ) ) ) ),
					h( 'span', { className: 'vml-count-chip' }, sprintf( _n( '%d location', '%d locations', item.count, 'velox-map-locator' ), item.count ) ),
					boot.capabilities.manageTerms && h( 'div', { className: 'vml-term-actions' }, h( Button, { variant: 'tertiary', onClick: () => setDialog( { kind: tab, term: item } ), icon: h( Icon, { name: 'edit', size: 16 } ), label: __( 'Edit classification', 'velox-map-locator' ) } ), h( Button, { variant: 'tertiary', isDestructive: true, onClick: () => removeTerm( item ), icon: h( Icon, { name: 'trash', size: 16 } ), label: __( 'Delete classification', 'velox-map-locator' ) } ) )
				) ) ) : h( EmptyState, { title: tab === 'type' ? __( 'No Location Types yet', 'velox-map-locator' ) : __( 'No Groups yet', 'velox-map-locator' ), description: tab === 'type' ? __( 'Types classify locations such as Office, Store or Service Centre and can define marker defaults.', 'velox-map-locator' ) : __( 'Groups organise locations into hierarchies such as Middle East → UAE → Dubai.', 'velox-map-locator' ), action: boot.capabilities.manageTerms && h( Button, { variant: 'primary', onClick: () => setDialog( { kind: tab } ) }, tab === 'type' ? __( 'Add Type', 'velox-map-locator' ) : __( 'Add Group', 'velox-map-locator' ) ) } )
			),
			dialog && h( TermDialog, { kind: dialog.kind, term: dialog.term, groups, onClose: () => setDialog( null ), onSaved: () => { setDialog( null ); setNotice( dialog.term ? __( 'Classification updated.', 'velox-map-locator' ) : __( 'Classification created.', 'velox-map-locator' ) ); load(); } } )
		);
	}

	function TermDialog( { kind, term, groups, onClose, onSaved } ) {
		const isType = kind === 'type';
		const [ form, setForm ] = useState( term ? clone( term ) : { name: '', slug: '', description: '', parent: 0, marker: { icon: 'pin', color: '#2563eb', icon_color: '#ffffff', media_id: 0 } } );
		const [ saving, setSaving ] = useState( false ); const [ error, setError ] = useState( '' );
		const update = ( key, value ) => setForm( ( current ) => key.startsWith( 'marker.' ) ? { ...current, marker: { ...( current.marker || {} ), [ key.split( '.' )[ 1 ] ]: value } } : { ...current, [ key ]: value } );
		const submit = async ( event ) => {
			event.preventDefault(); setSaving( true ); setError( '' );
			try {
				const resource = isType ? 'types' : 'groups'; const path = `${ namespace }/admin/${ resource }${ term ? `/${ term.id }` : '' }`;
				const payload = { name: form.name, slug: form.slug || '', description: form.description || '' };
				if ( isType ) payload.marker = form.marker || {}; else payload.parent = Number( form.parent || 0 );
				const saved = await apiFetch( { path, method: term ? 'PUT' : 'POST', data: payload } ); onSaved( saved );
			} catch ( err ) { setError( getErrorMessage( err ) ); } finally { setSaving( false ); }
		};
		return h( Modal, {
			title: term ? ( isType ? __( 'Edit Type', 'velox-map-locator' ) : __( 'Edit Group', 'velox-map-locator' ) ) : ( isType ? __( 'Create Type', 'velox-map-locator' ) : __( 'Create Group', 'velox-map-locator' ) ),
			onRequestClose: onClose,
			className: 'vml-term-modal',
			overlayClassName: cx( 'vml-term-modal-overlay', `vml-term-modal-overlay--${ boot.adminAppearance || 'system' }` ),
			shouldCloseOnClickOutside: true,
		},
			h( 'form', { onSubmit: submit },
				h( 'p', { className: 'vml-modal-intro' }, isType ? __( 'Types classify locations and can define marker defaults.', 'velox-map-locator' ) : __( 'Groups provide a reusable organisational hierarchy.', 'velox-map-locator' ) ),
				error && h( Notice, { status: 'error', isDismissible: false }, error ),
				h( 'div', { className: 'vml-modal-body' },
					h( Field, { label: __( 'Name', 'velox-map-locator' ), required: true }, h( 'input', { autoFocus: true, type: 'text', required: true, value: form.name || '', onChange: ( event ) => update( 'name', event.target.value ) } ) ),
					h( Field, { label: __( 'Description', 'velox-map-locator' ) }, h( 'textarea', { rows: 3, value: form.description || '', onChange: ( event ) => update( 'description', event.target.value ) } ) ),
					! isType && h( Field, { label: __( 'Parent Group', 'velox-map-locator' ) }, h( 'select', { value: form.parent || 0, onChange: ( event ) => update( 'parent', Number( event.target.value ) ) }, h( 'option', { value: 0 }, __( 'No parent', 'velox-map-locator' ) ), hierarchyOrder( ( groups || [] ).filter( ( group ) => ! term || group.id !== term.id ) ).map( ( group ) => h( 'option', { key: group.id, value: group.id }, `${ '— '.repeat( group.depth || 0 ) }${ group.name }` ) ) ) ),
					isType && h( 'div', { className: 'vml-type-marker-form' },
						h( 'div', { className: 'vml-marker-preview-card compact' }, h( MarkerGlyph, { icon: form.marker && form.marker.icon || 'pin', color: form.marker && form.marker.color || '#2563eb', iconColor: form.marker && form.marker.icon_color || '#ffffff', size: 'medium' } ), h( 'span', null, __( 'Marker preview', 'velox-map-locator' ) ) ),
						h( 'div', { className: 'vml-field-grid two' }, h( Field, { label: __( 'Marker Icon', 'velox-map-locator' ) }, h( 'select', { value: form.marker && form.marker.icon || 'pin', onChange: ( event ) => update( 'marker.icon', event.target.value ) }, ( boot.markerIcons || [] ).map( ( icon ) => h( 'option', { key: icon, value: icon }, ( boot.markerIconLabels && boot.markerIconLabels[ icon ] ) || humanize( icon ) ) ) ) ), h( ColorField, { label: __( 'Marker Colour', 'velox-map-locator' ), value: form.marker && form.marker.color || '#2563eb', onChange: ( value ) => update( 'marker.color', value ) } ), h( ColorField, { label: __( 'Icon Colour', 'velox-map-locator' ), value: form.marker && form.marker.icon_color || '#ffffff', onChange: ( value ) => update( 'marker.icon_color', value ) } ) )
					)
				),
				h( 'div', { className: 'vml-modal-footer' }, h( Button, { variant: 'tertiary', onClick: onClose }, __( 'Cancel', 'velox-map-locator' ) ), h( Button, { type: 'submit', variant: 'primary', isBusy: saving, disabled: saving || ! String( form.name || '' ).trim() }, term ? __( 'Save Changes', 'velox-map-locator' ) : __( 'Create', 'velox-map-locator' ) ) )
			)
		);
	}



	function ImportExportScreen() {
		const [ upload, setUpload ] = useState( null );
		const [ mapping, setMapping ] = useState( {} );
		const [ mode, setMode ] = useState( 'upsert' );
		const [ createTerms, setCreateTerms ] = useState( true );
		const [ validation, setValidation ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ result, setResult ] = useState( null );
		const [ progress, setProgress ] = useState( 0 );
		const fileRef = useRef( null );

		const uploadFile = async () => {
			const file = fileRef.current && fileRef.current.files && fileRef.current.files[ 0 ];
			if ( ! file ) { setError( __( 'Choose a CSV file first.', 'velox-map-locator' ) ); return; }
			setBusy( true ); setError( '' ); setValidation( null ); setResult( null ); setProgress( 0 );
			try {
				const body = new FormData(); body.append( 'file', file );
				const staged = await apiFetch( { path: `${ namespace }/admin/import/upload`, method: 'POST', body } );
				setUpload( staged ); setMapping( staged.suggested_mapping || {} ); setCreateTerms( staged.can_create_terms !== false );
			} catch ( err ) { setError( getErrorMessage( err ) ); }
			finally { setBusy( false ); }
		};

		const setMap = ( source, target ) => {
			setValidation( null );
			setMapping( ( current ) => {
				const next = { ...current };
				Object.keys( next ).forEach( ( key ) => { if ( key !== source && target && next[ key ] === target ) next[ key ] = ''; } );
				next[ source ] = target;
				return next;
			} );
		};

		const validateImport = async () => {
			if ( ! upload ) return;
			setBusy( true ); setError( '' ); setValidation( null );
			try {
				const checked = await apiFetch( { path: `${ namespace }/admin/import/${ escapePath( upload.session ) }/validate`, method: 'POST', data: { mapping, mode, create_terms: createTerms } } );
				setValidation( checked );
			} catch ( err ) { setError( getErrorMessage( err ) ); }
			finally { setBusy( false ); }
		};

		const commitImport = async () => {
			if ( ! upload || ! validation || ! validation.can_commit ) return;
			setBusy( true ); setError( '' ); setResult( { created: 0, updated: 0, failed: 0, failures: [] } ); setProgress( 0 );
			let offset = 0;
			let totals = { created: 0, updated: 0, failed: 0, failures: [] };
			try {
				for ( let guard = 0; guard < 10000; guard += 1 ) {
					const chunk = await apiFetch( { path: `${ namespace }/admin/import/${ escapePath( upload.session ) }/commit`, method: 'POST', data: { offset } } );
					totals = { created: totals.created + Number( chunk.created || 0 ), updated: totals.updated + Number( chunk.updated || 0 ), failed: totals.failed + Number( chunk.failed || 0 ), failures: totals.failures.concat( chunk.failures || [] ) };
					setResult( { ...totals } );
					offset = Number( chunk.next_offset || offset );
					setProgress( chunk.total ? Math.min( 100, Math.round( ( offset / Number( chunk.total ) ) * 100 ) ) : 100 );
					if ( chunk.done ) break;
					if ( ! chunk.processed ) throw new Error( __( 'The import stopped because no rows were processed.', 'velox-map-locator' ) );
				}
				setProgress( 100 );
			} catch ( err ) { setError( getErrorMessage( err ) ); }
			finally { setBusy( false ); }
		};

		const resetImport = () => { setUpload( null ); setMapping( {} ); setValidation( null ); setResult( null ); setError( '' ); setProgress( 0 ); if ( fileRef.current ) fileRef.current.value = ''; };
		const mappedCount = Object.values( mapping ).filter( Boolean ).length;
		const columnEntries = upload ? Object.entries( upload.columns || {} ) : [];

		const downloadTemplate = () => {
			const header = columnEntries.length ? columnEntries.map( ( [ key ] ) => key ) : [ 'external_id', 'name', 'status', 'display_address', 'latitude', 'longitude', 'phone', 'website', 'type_slugs', 'group_paths' ];
			const blob = new Blob( [ `\uFEFF${ header.join( ',' ) }\r\n` ], { type: 'text/csv;charset=utf-8' } );
			const url = URL.createObjectURL( blob ); const anchor = document.createElement( 'a' ); anchor.href = url; anchor.download = 'velox-map-locator-import-template.csv'; document.body.appendChild( anchor ); anchor.click(); anchor.remove(); URL.revokeObjectURL( url );
		};

		return h( Fragment, null,
			h( PageHeader, { title: __( 'Import / Export', 'velox-map-locator' ), description: __( 'Move Location data safely in and out of Velox Map Locator using a portable CSV format.', 'velox-map-locator' ) }, boot.capabilities.exportLocations && h( Button, { variant: 'secondary', href: boot.urls.exportLocations }, __( 'Export Locations CSV', 'velox-map-locator' ) ) ),
			error && h( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ),
			h( 'div', { className: 'vml-transfer-grid' },
				boot.capabilities.importLocations && h( 'section', { className: 'vml-panel vml-transfer-panel vml-import-panel' },
					h( 'div', { className: 'vml-transfer-panel__head' }, h( 'div', null, h( 'h2', null, __( 'Import Locations', 'velox-map-locator' ) ), h( 'p', null, __( 'Upload a CSV, map its columns, validate every row, then confirm the import. Nothing is written during preview.', 'velox-map-locator' ) ) ), upload && h( Button, { variant: 'tertiary', onClick: resetImport, disabled: busy }, __( 'Start over', 'velox-map-locator' ) ) ),
					! upload ? h( 'div', { className: 'vml-import-upload' },
						h( 'label', { className: 'vml-file-drop' }, h( 'strong', null, __( 'Choose a CSV file', 'velox-map-locator' ) ), h( 'span', null, __( 'Up to 20 MB and 50,000 data rows per file by default. These are technical safety limits, not product-tier limits.', 'velox-map-locator' ) ), h( 'input', { ref: fileRef, type: 'file', accept: '.csv,text/csv' } ) ),
						h( 'div', { className: 'vml-import-actions' }, h( Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: uploadFile }, __( 'Upload & Inspect', 'velox-map-locator' ) ), h( Button, { variant: 'secondary', onClick: downloadTemplate }, __( 'Download Template', 'velox-map-locator' ) ) )
					) : h( Fragment, null,
						h( 'div', { className: 'vml-import-file-summary' }, h( 'strong', null, upload.file_name ), h( 'span', null, sprintf( _n( '%d data row', '%d data rows', upload.row_count, 'velox-map-locator' ), upload.row_count ) ), h( 'span', null, sprintf( __( '%d columns detected', 'velox-map-locator' ), upload.headers.length ) ) ),
						h( 'div', { className: 'vml-import-options' },
							h( Field, { label: __( 'Import mode', 'velox-map-locator' ), hint: __( 'Update or Create matches existing Locations only by External ID. Rows without an External ID are created.', 'velox-map-locator' ) }, h( 'select', { value: mode, onChange: ( e ) => { setMode( e.target.value ); setValidation( null ); } }, h( 'option', { value: 'upsert' }, __( 'Update or Create (External ID)', 'velox-map-locator' ) ), h( 'option', { value: 'create' }, __( 'Create only', 'velox-map-locator' ) ) ) ),
							upload.can_create_terms && h( BuilderToggle, { label: __( 'Create missing Types and Groups', 'velox-map-locator' ), description: __( 'Missing Type slugs and hierarchical Group paths are created during the confirmed import.', 'velox-map-locator' ), checked: createTerms, onChange: ( value ) => { setCreateTerms( value ); setValidation( null ); } } )
						),
						h( 'div', { className: 'vml-mapping-head' }, h( 'div', null, h( 'h3', null, __( 'Column Mapping', 'velox-map-locator' ) ), h( 'p', null, sprintf( __( '%d columns mapped. Unmapped source columns are ignored.', 'velox-map-locator' ), mappedCount ) ) ) ),
						h( 'div', { className: 'vml-mapping-table-wrap' }, h( 'table', { className: 'vml-mapping-table' }, h( 'thead', null, h( 'tr', null, h( 'th', null, __( 'CSV column', 'velox-map-locator' ) ), h( 'th', null, __( 'Example', 'velox-map-locator' ) ), h( 'th', null, __( 'Location field', 'velox-map-locator' ) ) ) ), h( 'tbody', null, upload.headers.map( ( source ) => h( 'tr', { key: source }, h( 'td', null, h( 'strong', null, source ) ), h( 'td', null, h( 'code', null, upload.sample && upload.sample[ 0 ] && upload.sample[ 0 ][ source ] ? String( upload.sample[ 0 ][ source ] ).slice( 0, 70 ) : '—' ) ), h( 'td', null, h( 'select', { value: mapping[ source ] || '', onChange: ( e ) => setMap( source, e.target.value ) }, h( 'option', { value: '' }, __( 'Do not import', 'velox-map-locator' ) ), columnEntries.map( ( [ key, label ] ) => h( 'option', { key, value: key }, label ) ) ) ) ) ) ) ) ),
						h( 'div', { className: 'vml-import-actions' }, h( Button, { variant: 'primary', isBusy: busy && ! result, disabled: busy || mappedCount < 1, onClick: validateImport }, __( 'Validate Import', 'velox-map-locator' ) ), h( Button, { variant: 'secondary', onClick: downloadTemplate }, __( 'Download Template', 'velox-map-locator' ) ) ),
						validation && h( 'div', { className: cx( 'vml-validation-summary', validation.valid ? 'is-valid' : 'is-invalid' ) },
							h( 'div', { className: 'vml-validation-stats' }, h( 'span', null, h( 'strong', null, validation.creates ), __( ' Create', 'velox-map-locator' ) ), h( 'span', null, h( 'strong', null, validation.updates ), __( ' Update', 'velox-map-locator' ) ), h( 'span', null, h( 'strong', null, ( validation.errors || [] ).length ), __( ' Errors', 'velox-map-locator' ) ), h( 'span', null, h( 'strong', null, ( validation.warnings || [] ).length ), __( ' Warnings', 'velox-map-locator' ) ) ),
							( validation.errors || [] ).length > 0 && h( 'div', { className: 'vml-issue-list is-error' }, h( 'h4', null, __( 'Fix these rows before import', 'velox-map-locator' ) ), validation.errors.slice( 0, 50 ).map( ( issue, i ) => h( 'div', { key: `${ issue.row }-${ i }` }, h( 'strong', null, sprintf( __( 'Row %d:', 'velox-map-locator' ), issue.row ) ), ' ', issue.message ) ), validation.errors.length > 50 && h( 'p', null, sprintf( __( 'Plus %d more errors.', 'velox-map-locator' ), validation.errors.length - 50 ) ) ),
							( validation.warnings || [] ).length > 0 && h( 'div', { className: 'vml-issue-list is-warning' }, h( 'h4', null, __( 'Warnings', 'velox-map-locator' ) ), validation.warnings.slice( 0, 30 ).map( ( issue, i ) => h( 'div', { key: `${ issue.row }-w-${ i }` }, h( 'strong', null, sprintf( __( 'Row %d:', 'velox-map-locator' ), issue.row ) ), ' ', issue.message ) ) ),
							validation.valid && h( Fragment, null,
								h( 'div', { className: 'vml-preview-table-wrap' }, h( 'table', { className: 'vml-preview-table' }, h( 'thead', null, h( 'tr', null, h( 'th', null, __( 'Row', 'velox-map-locator' ) ), h( 'th', null, __( 'Action', 'velox-map-locator' ) ), h( 'th', null, __( 'Name', 'velox-map-locator' ) ), h( 'th', null, __( 'External ID', 'velox-map-locator' ) ), h( 'th', null, __( 'Status', 'velox-map-locator' ) ) ) ), h( 'tbody', null, ( validation.preview || [] ).map( ( item ) => h( 'tr', { key: item.row }, h( 'td', null, item.row ), h( 'td', null, h( StatusBadge, { status: item.action === 'update' ? 'pending' : 'draft' } ), ' ', item.action === 'update' ? __( 'Update', 'velox-map-locator' ) : __( 'Create', 'velox-map-locator' ) ), h( 'td', null, item.name || '—' ), h( 'td', null, item.external_id || '—' ), h( 'td', null, item.status ) ) ) ) ) ),
								! result && h( Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: commitImport }, sprintf( __( 'Confirm Import — %d Rows', 'velox-map-locator' ), validation.row_count ) )
							)
						),
						result && h( 'div', { className: 'vml-import-progress' }, h( 'div', { className: 'vml-progress-track', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': progress }, h( 'span', { style: { width: `${ progress }%` } } ) ), h( 'p', null, busy ? sprintf( __( 'Importing… %d%%', 'velox-map-locator' ), progress ) : __( 'Import complete.', 'velox-map-locator' ) ), h( 'div', { className: 'vml-validation-stats' }, h( 'span', null, h( 'strong', null, result.created ), __( ' Created', 'velox-map-locator' ) ), h( 'span', null, h( 'strong', null, result.updated ), __( ' Updated', 'velox-map-locator' ) ), h( 'span', null, h( 'strong', null, result.failed ), __( ' Failed', 'velox-map-locator' ) ) ), ! busy && h( Button, { variant: 'secondary', href: boot.urls.locations }, __( 'View Locations', 'velox-map-locator' ) ) )
					)
				),
				boot.capabilities.exportLocations && h( 'aside', { className: 'vml-panel vml-transfer-panel vml-export-panel' },
					h( 'div', { className: 'vml-transfer-panel__head' }, h( 'div', null, h( 'h2', null, __( 'Export Locations', 'velox-map-locator' ) ), h( 'p', null, __( 'Download all active Draft and Published Locations in the canonical Velox CSV format.', 'velox-map-locator' ) ) ) ),
					h( 'ul', { className: 'vml-export-features' }, h( 'li', null, __( 'External IDs for safe update/create imports', 'velox-map-locator' ) ), h( 'li', null, __( 'Portable Type slugs and hierarchical Group paths', 'velox-map-locator' ) ), h( 'li', null, __( 'Business hours, special hours and additional fields preserved as JSON', 'velox-map-locator' ) ), h( 'li', null, __( 'Spreadsheet formula-injection protection applied to exported cells', 'velox-map-locator' ) ) ),
					h( Button, { variant: 'primary', href: boot.urls.exportLocations }, __( 'Download Locations CSV', 'velox-map-locator' ) ),
					h( 'p', { className: 'vml-transfer-note' }, __( 'Featured image and custom marker media IDs are included for same-site round trips. Velox does not download remote media during CSV import.', 'velox-map-locator' ) )
				)
			)
		);
	}

	function HelpStepList( { steps } ) {
		return h( 'ol', { className: 'vml-help-steps' }, steps.map( ( step, index ) => h( 'li', { key: index }, step ) ) );
	}

	function HelpTopic( { topic, open = false } ) {
		return h( 'details', { className: 'vml-help-topic', open },
			h( 'summary', null,
				h( 'span', null, h( 'strong', null, topic.title ), topic.summary && h( 'small', null, topic.summary ) ),
				h( Icon, { name: 'chevron', size: 16 } )
			),
			h( 'div', { className: 'vml-help-topic__body' },
				topic.intro && h( 'p', null, topic.intro ),
				topic.steps && h( HelpStepList, { steps: topic.steps } ),
				topic.notes && topic.notes.length > 0 && h( 'div', { className: 'vml-help-notes' }, topic.notes.map( ( note, index ) => h( 'p', { key: index }, note ) ) ),
				topic.links && topic.links.length > 0 && h( 'div', { className: 'vml-help-links' }, topic.links.map( ( link, index ) => h( 'a', { key: index, href: link.url }, link.label, ' ', h( Icon, { name: 'chevron', size: 13 } ) ) ) )
			)
		);
	}

	function HelpScreen() {
		const [ tab, setTab ] = useState( 'workflow' );
		const [ query, setQuery ] = useState( '' );
		const normalizedQuery = query.trim().toLocaleLowerCase();

		const workflow = [
			{ title: __( '1. Configure a map provider', 'velox-map-locator' ), text: __( 'OpenStreetMap works without an API key. Google Maps requires a restricted browser API key and Map ID. Custom XYZ lets you use another compatible raster tile service.', 'velox-map-locator' ), url: boot.urls.providers, label: __( 'Open Map Providers', 'velox-map-locator' ) },
			{ title: __( '2. Set your global defaults', 'velox-map-locator' ), text: __( 'Choose the default provider, distance units, 25% sidebar width, map height, controls, appearance and privacy behaviour. These defaults seed new Locators without overwriting existing custom Locators.', 'velox-map-locator' ), url: boot.urls.settings, label: __( 'Open Settings', 'velox-map-locator' ) },
			{ title: __( '3. Plan Types and Groups', 'velox-map-locator' ), text: __( 'Use Types for what a Location is, such as Office, Store, Dealer or Service Centre. Use hierarchical Groups for how you want to organise Locations, such as UAE → Dubai or Retail → Flagship.', 'velox-map-locator' ), url: boot.urls.classifications, label: __( 'Manage Types & Groups', 'velox-map-locator' ) },
			{ title: __( '4. Add and publish Locations', 'velox-map-locator' ), text: __( 'Create each physical place with coordinates, address, contact details, timezone, hours, classification and marker styling. Published Locations require a valid name and latitude/longitude.', 'velox-map-locator' ), url: boot.urls.addLocation, label: __( 'Add a Location', 'velox-map-locator' ) },
			{ title: __( '5. Create a Locator', 'velox-map-locator' ), text: __( 'A Locator is the reusable public experience. Choose Split, Map Only or List Only, then choose the provider and map-loading behaviour. After creation, use the full Builder for detailed configuration.', 'velox-map-locator' ), url: boot.urls.locators, label: __( 'Open Locators', 'velox-map-locator' ) },
			{ title: __( '6. Configure the Locator Builder', 'velox-map-locator' ), text: __( 'Work through Source, Layout, Map, Search, Filters, Content, Appearance, Behaviour and Privacy. Use the live Desktop, Tablet and Mobile preview before publishing.', 'velox-map-locator' ), url: boot.urls.locators, label: __( 'Open Locator Builder', 'velox-map-locator' ) },
			{ title: __( '7. Embed the Locator', 'velox-map-locator' ), text: __( 'Add the Velox Map Locator Gutenberg block and select a published Locator, or use its shortcode. Both methods render the same Locator configuration and update automatically when that Locator changes.', 'velox-map-locator' ) },
			{ title: __( '8. Test the public experience', 'velox-map-locator' ), text: __( 'Check marker ↔ card selection, search, filters, Near Me, business status, Home/Fit All, fullscreen, clustering, mobile stacking, privacy loading and directions on the actual page where the Locator is embedded.', 'velox-map-locator' ) },
			{ title: __( '9. Use CSV for larger datasets', 'velox-map-locator' ), text: __( 'Export a canonical CSV, edit it carefully, validate it before writing, and use External ID when you need predictable updates instead of duplicates.', 'velox-map-locator' ), url: boot.urls.importExport, label: __( 'Open Import / Export', 'velox-map-locator' ) },
			{ title: __( '10. Maintain rather than duplicate', 'velox-map-locator' ), text: __( 'Update shared Locations, Types, Groups, provider profiles and Locators in their central screens. Pages using the block or shortcode automatically use the updated Locator instead of storing independent copies.', 'velox-map-locator' ) },
		];

		const guides = [
			{
				title: __( 'First-time setup', 'velox-map-locator' ),
				summary: __( 'A practical setup sequence for a new installation.', 'velox-map-locator' ),
				steps: [
					__( 'Open Map Providers and decide whether OpenStreetMap, Google Maps or a Custom XYZ service will be your default basemap.', 'velox-map-locator' ),
					__( 'Open Settings and choose global defaults for distance units, map size, controls, appearance and privacy.', 'velox-map-locator' ),
					__( 'Create the Types and Groups you expect to reuse across many Locations.', 'velox-map-locator' ),
					__( 'Add a small representative set of Locations manually before importing a large CSV. This lets you confirm your classification and display model first.', 'velox-map-locator' ),
					__( 'Create one test Locator, configure it in the Builder and embed it on a private/test page.', 'velox-map-locator' ),
					__( 'Only after the public behaviour looks right should you bulk-import the remaining Locations or create additional Locators.', 'velox-map-locator' ),
				],
				links: [ { label: __( 'Map Providers', 'velox-map-locator' ), url: boot.urls.providers }, { label: __( 'Settings', 'velox-map-locator' ), url: boot.urls.settings } ],
			},
			{
				title: __( 'Map Providers', 'velox-map-locator' ),
				summary: __( 'Choose and configure the service that draws the basemap.', 'velox-map-locator' ),
				steps: [
					__( 'Open Map Providers.', 'velox-map-locator' ),
					__( 'Use OpenStreetMap when you want the built-in no-key option. Public OSM tiles are an external service and remain subject to OpenStreetMap tile usage policy.', 'velox-map-locator' ),
					__( 'For Google Maps, enter a browser API key and Map ID. Restrict the key to your site/referrers and only the APIs you require. Use Test Connection before creating Google Locators.', 'velox-map-locator' ),
					__( 'For Custom XYZ, create a reusable profile containing the tile URL template, attribution and zoom/service options. Tokens embedded in a browser tile URL are visible to visitors.', 'velox-map-locator' ),
					__( 'Do not delete an XYZ profile while a Locator still references it; Velox blocks this to protect published maps.', 'velox-map-locator' ),
				],
				links: [ { label: __( 'Open Map Providers', 'velox-map-locator' ), url: boot.urls.providers } ],
			},
			{
				title: __( 'Global Settings', 'velox-map-locator' ),
				summary: __( 'Set sensible defaults before creating many Locators.', 'velox-map-locator' ),
				steps: [
					__( 'Choose Auto, Kilometres or Miles for the default distance unit.', 'velox-map-locator' ),
					__( 'Choose the default basemap/provider and, for XYZ, the default profile.', 'velox-map-locator' ),
					__( 'Set the Split sidebar width. The recommended default is 25% so the map remains visually dominant.', 'velox-map-locator' ),
					__( 'Choose map height, single-location zoom and which map controls new Locators should receive.', 'velox-map-locator' ),
					__( 'Choose default theme, colour mode, typography, density and accent.', 'velox-map-locator' ),
					__( 'Choose whether external maps load near the viewport or require an explicit visitor click.', 'velox-map-locator' ),
					__( 'Leave “Delete plugin data on uninstall” disabled unless you intentionally want destructive cleanup when the plugin is deleted.', 'velox-map-locator' ),
				],
				notes: [ __( 'Global settings seed newly created Locators. A Locator can then override those values in its Builder.', 'velox-map-locator' ) ],
				links: [ { label: __( 'Open Settings', 'velox-map-locator' ), url: boot.urls.settings } ],
			},
			{
				title: __( 'Types, Groups and Primary Type', 'velox-map-locator' ),
				summary: __( 'Build a classification model that stays useful as the dataset grows.', 'velox-map-locator' ),
				steps: [
					__( 'Create Types for the kind or function of a place: Office, Store, Warehouse, Dealer, ATM, Clinic and similar classifications.', 'velox-map-locator' ),
					__( 'Create Groups for organisational or geographic collections. Groups can be hierarchical, for example UAE → Dubai → Downtown.', 'velox-map-locator' ),
					__( 'A Location can belong to multiple Types and multiple Groups.', 'velox-map-locator' ),
					__( 'Choose one assigned Type as the Primary Type when you want its marker styling to be inherited by the Location.', 'velox-map-locator' ),
					__( 'Configure Type marker defaults only when the category genuinely benefits from a visual distinction; avoid creating a rainbow of markers without meaning.', 'velox-map-locator' ),
				],
				links: [ { label: __( 'Manage Types & Groups', 'velox-map-locator' ), url: boot.urls.classifications } ],
			},
			{
				title: __( 'Creating and editing a Location', 'velox-map-locator' ),
				summary: __( 'Enter the data for one physical place.', 'velox-map-locator' ),
				steps: [
					__( 'Enter a clear public name.', 'velox-map-locator' ),
					__( 'Enter the structured address and latitude/longitude. Coordinates are required before a Location can be published.', 'velox-map-locator' ),
					__( 'If Google Maps is configured, use Find on Map when you want Google geocoding to resolve the address into coordinates. Typing alone does not send geocoding requests.', 'velox-map-locator' ),
					__( 'Add phone, email, website, contact person and directions URL as appropriate.', 'velox-map-locator' ),
					__( 'Set the IANA timezone so Open/Closed status is calculated in the Location’s local time.', 'velox-map-locator' ),
					__( 'Assign Types and Groups, select a Primary Type if useful, and configure a Location marker override only when it should differ from inherited styling.', 'velox-map-locator' ),
					__( 'Use Draft while data is incomplete. Publish only after the Location has a valid name and coordinates.', 'velox-map-locator' ),
				],
				links: [ { label: __( 'Add Location', 'velox-map-locator' ), url: boot.urls.addLocation }, { label: __( 'View Locations', 'velox-map-locator' ), url: boot.urls.locations } ],
			},
			{
				title: __( 'Business hours and operational status', 'velox-map-locator' ),
				summary: __( 'Show accurate weekly and exceptional opening information.', 'velox-map-locator' ),
				steps: [
					__( 'Set the Location timezone first.', 'velox-map-locator' ),
					__( 'For each weekday, mark it Closed, All Day, or add one or more opening intervals.', 'velox-map-locator' ),
					__( 'Use multiple intervals for split shifts, such as 09:00–13:00 and 16:00–20:00.', 'velox-map-locator' ),
					__( 'Overnight intervals are supported, so a closing time can fall on the following day.', 'velox-map-locator' ),
					__( 'Use Special Hours for date-specific closures or exceptional hours.', 'velox-map-locator' ),
					__( 'Use Operational Status for Temporarily Closed or Coming Soon when that state is more important than normal weekly hours.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Marker inheritance', 'velox-map-locator' ),
				summary: __( 'Understand which marker style wins when several levels define one.', 'velox-map-locator' ),
				steps: [
					__( 'First priority: a Location-specific marker override.', 'velox-map-locator' ),
					__( 'Second priority: the marker configured on the Location’s Primary Type.', 'velox-map-locator' ),
					__( 'Third priority: the Locator’s default marker configuration.', 'velox-map-locator' ),
					__( 'Final fallback: the active theme/default marker.', 'velox-map-locator' ),
					__( 'Use Media Library raster markers only when a branded/custom icon is necessary. Velox does not enable arbitrary SVG uploads.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Creating a Locator', 'velox-map-locator' ),
				summary: __( 'Create the reusable map/list experience that pages will embed.', 'velox-map-locator' ),
				steps: [
					__( 'Open Locators and choose Create Locator.', 'velox-map-locator' ),
					__( 'Give it a descriptive internal name such as “UAE Service Centres” rather than a generic “Map 1”.', 'velox-map-locator' ),
					__( 'Choose Split for the standard map + list experience, Map Only for a visual map, or List Only when a map is unnecessary.', 'velox-map-locator' ),
					__( 'Choose the provider for a map-enabled Locator.', 'velox-map-locator' ),
					__( 'Choose whether the Locator follows the global loading default, loads near the viewport, or requires visitor interaction.', 'velox-map-locator' ),
					__( 'Create the Locator, then open the full Builder to control its source, display and behaviour.', 'velox-map-locator' ),
				],
				links: [ { label: __( 'Open Locators', 'velox-map-locator' ), url: boot.urls.locators } ],
			},
			{
				title: __( 'Locator Builder: Source', 'velox-map-locator' ),
				summary: __( 'Choose which published Locations belong to a Locator.', 'velox-map-locator' ),
				steps: [
					__( 'Use All Locations for a directory that should automatically include every eligible published Location.', 'velox-map-locator' ),
					__( 'Use Selected Locations when you need an explicit curated set and manual ordering.', 'velox-map-locator' ),
					__( 'Use Dynamic Rules to include Locations based on Type, Group, Country or City without manually maintaining the list.', 'velox-map-locator' ),
					__( 'Use exclusions when a Location matches the source rule but should not appear in this particular Locator.', 'velox-map-locator' ),
					__( 'Prefer Dynamic Rules for growing networks and Selected Locations for tightly curated pages.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Locator Builder: Layout and Map', 'velox-map-locator' ),
				summary: __( 'Control geometry, provider, initial view and map controls.', 'velox-map-locator' ),
				steps: [
					__( 'Choose Split, Map Only or List Only.', 'velox-map-locator' ),
					__( 'For Split, 25% sidebar width is the recommended starting point. Adjust height and sidebar position only when the page layout needs it.', 'velox-map-locator' ),
					__( 'Choose Map First or Locations First for mobile stacking.', 'velox-map-locator' ),
					__( 'Choose the map provider and use Fit visible Locations for most Locator use cases.', 'velox-map-locator' ),
					__( 'Use Fixed centre and zoom when the map must always open on a deliberate geographic context, even if visible Locations occupy a smaller area.', 'velox-map-locator' ),
					__( 'Enable Home, Fit All, Zoom, Scale, Fullscreen and the zoom-level indicator according to the experience you want.', 'velox-map-locator' ),
					__( 'Leave scroll-wheel zoom off unless the map is large enough that accidental page-scroll capture is unlikely.', 'velox-map-locator' ),
					__( 'Choose Auto clustering for normal use. Force Enabled for dense data or Disabled when individual markers should remain separate; coincident Locations still remain accessible.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Locator Builder: Search and Filters', 'velox-map-locator' ),
				summary: __( 'Help visitors narrow large Location sets quickly.', 'velox-map-locator' ),
				steps: [
					__( 'Enable search when visitors are likely to know a name, address, city or other searchable field.', 'velox-map-locator' ),
					__( 'Choose only meaningful searchable fields; searching everything can produce confusing matches.', 'velox-map-locator' ),
					__( 'Enable Type, Group, Country or City filters according to the dataset.', 'velox-map-locator' ),
					__( 'Use pills for a small, stable number of choices and dropdowns when the dimension can grow large.', 'velox-map-locator' ),
					__( 'Keep result count enabled for larger directories so visitors know how much the search/filter changed the dataset.', 'velox-map-locator' ),
					__( 'When Refit on Filter is enabled, the map reframes the currently visible results automatically.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Locator Builder: Content and Appearance', 'velox-map-locator' ),
				summary: __( 'Decide what visitors see and how the Locator fits the site.', 'velox-map-locator' ),
				steps: [
					__( 'Choose card fields separately from popup/details fields. Only enabled public fields are included in the public Locator payload.', 'velox-map-locator' ),
					__( 'Show the minimum information required for scanning cards quickly; move secondary information into details/popups.', 'velox-map-locator' ),
					__( 'Choose Velox, Slate, Azure or Forest as a visual base.', 'velox-map-locator' ),
					__( 'Use Inherit Site typography when you want the Locator to blend naturally with the current WordPress theme.', 'velox-map-locator' ),
					__( 'Use Light/Dark/Auto according to the surrounding page and choose Compact/Comfortable/Spacious density based on content volume.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Locator Builder: Behaviour and Privacy', 'velox-map-locator' ),
				summary: __( 'Configure visitor interaction, location awareness and external service loading.', 'velox-map-locator' ),
				steps: [
					__( 'Enable Near Me when distance sorting is useful. Browser geolocation is requested only after the visitor explicitly activates the feature and Velox does not persist the coordinates.', 'velox-map-locator' ),
					__( 'Enable deep linking when individual Locations should be addressable with a URL parameter.', 'velox-map-locator' ),
					__( 'Keep Pan on Select and Popup on Select enabled for strong card ↔ map synchronization unless your design calls for a quieter map.', 'velox-map-locator' ),
					__( 'Use Auto distance units for a general audience or force Kilometres/Miles when the business requires one standard.', 'velox-map-locator' ),
					__( 'Use Require visitor to click Load Map when you want external map services held back until explicit interaction. The local list remains available before the map loads.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Map controls on the public Locator', 'velox-map-locator' ),
				summary: __( 'Understand the purpose of each public map control.', 'velox-map-locator' ),
				steps: [
					__( 'Home restores the Locator’s original context: its configured fixed view, or the overall Locator Locations when automatic fitting is used.', 'velox-map-locator' ),
					__( 'Fit All frames the Locations that are currently visible after search/filtering.', 'velox-map-locator' ),
					__( 'Zoom +/− changes map zoom without requiring a wheel or gesture.', 'velox-map-locator' ),
					__( 'Zoom Level displays the current numeric zoom for users who need map-scale awareness.', 'velox-map-locator' ),
					__( 'Scale shows an approximate real-world distance reference.', 'velox-map-locator' ),
					__( 'Fullscreen expands the map experience where browser/provider support permits it.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Embedding with Gutenberg', 'velox-map-locator' ),
				summary: __( 'Use the dynamic block in the WordPress Block Editor.', 'velox-map-locator' ),
				steps: [
					__( 'Edit the target page or post in the Block Editor.', 'velox-map-locator' ),
					__( 'Insert the Velox Map Locator block.', 'velox-map-locator' ),
					__( 'Select a published Locator.', 'velox-map-locator' ),
					__( 'Review the server-rendered editor preview and use Wide/Full alignment when supported by the theme.', 'velox-map-locator' ),
					__( 'Publish/update the page. The block stores the Locator ID, so later Builder changes automatically appear on the page.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'Embedding with shortcode', 'velox-map-locator' ),
				summary: __( 'Use a Locator in classic editors, widgets or page builders.', 'velox-map-locator' ),
				steps: [
					__( 'Copy the shortcode from the Locators list or Builder.', 'velox-map-locator' ),
					__( 'Paste it into a Shortcode block/module or another area that executes WordPress shortcodes.', 'velox-map-locator' ),
					__( 'The format is [velox_map_locator id="123"], where 123 is the Locator ID.', 'velox-map-locator' ),
					__( 'Do not copy the generated HTML. Keep the shortcode so future Locator configuration changes stay centralized.', 'velox-map-locator' ),
				],
			},
			{
				title: __( 'CSV Import / Export', 'velox-map-locator' ),
				summary: __( 'Manage larger Location datasets safely.', 'velox-map-locator' ),
				steps: [
					__( 'Export Locations when you want the canonical Velox column format or a round-trip backup/editing file.', 'velox-map-locator' ),
					__( 'For import, upload the CSV and review automatic column mapping.', 'velox-map-locator' ),
					__( 'Choose Update or Create using External ID when the CSV should update known records; choose Create Only when overwriting must never occur.', 'velox-map-locator' ),
					__( 'Validate before confirming. Resolve errors first and review warnings such as missing Types/Groups.', 'velox-map-locator' ),
					__( 'Enable creation of missing Types/Groups only when the CSV taxonomy values are trustworthy.', 'velox-map-locator' ),
					__( 'Confirm the import and allow the 100-row chunk workflow to complete.', 'velox-map-locator' ),
					__( 'Use stable External IDs from your source system when you expect recurring updates.', 'velox-map-locator' ),
				],
				links: [ { label: __( 'Open Import / Export', 'velox-map-locator' ), url: boot.urls.importExport } ],
			},
		];

		const troubleshooting = [
			{ title: __( 'The map area is blank or shows an error', 'velox-map-locator' ), body: __( 'Confirm the Locator is map-enabled, has at least one published Location with valid coordinates, and uses a configured provider. For Google Maps, re-run Test Connection and confirm the key restrictions and Map ID. For XYZ, verify the tile URL and attribution/profile. The Location list should remain usable even when the map provider fails.', 'velox-map-locator' ) },
			{ title: __( 'Google Maps will not load', 'velox-map-locator' ), body: __( 'Check that the Maps JavaScript API is enabled for the Google Cloud project, the browser API key is restricted to the correct site/referrer, the Map ID is valid, billing/account requirements are satisfied, and Test Connection succeeds. If a key constant is defined in wp-config.php it overrides the database key.', 'velox-map-locator' ) },
			{ title: __( 'OpenStreetMap tiles do not appear', 'velox-map-locator' ), body: __( 'Check general internet access, browser console/network errors and whether the site or security layer blocks tile.openstreetmap.org. Velox bundles Leaflet locally, but the OSM basemap tiles themselves are an external service.', 'velox-map-locator' ) },
			{ title: __( 'A Custom XYZ basemap is blank', 'velox-map-locator' ), body: __( 'Verify the URL contains the required {z}/{x}/{y} placeholders, check subdomains/TMS/retina settings, confirm the service allows browser access from your site, and review its attribution/token requirements.', 'velox-map-locator' ) },
			{ title: __( 'A Location is missing from a Locator', 'velox-map-locator' ), body: __( 'Confirm the Location is published, has valid coordinates for map layouts, and matches the Locator Source rules. Check Selected Locations, Dynamic conditions, exclusions, Types/Groups and country/city values. A deep link is only accepted when the Location actually belongs to that Locator.', 'velox-map-locator' ) },
			{ title: __( 'Search or filters return no results', 'velox-map-locator' ), body: __( 'Clear all active filters, confirm the Locator Source contains the expected Locations, and verify that the corresponding Type/Group/Country/City values exist on those Locations. Search only checks the fields enabled in the Builder.', 'velox-map-locator' ) },
			{ title: __( 'Near Me does not work', 'velox-map-locator' ), body: __( 'The browser must support geolocation, the page should normally be served over HTTPS, and the visitor must grant permission after clicking Near Me. If permission is denied, Velox keeps the normal Locator usable and does not persist location data.', 'velox-map-locator' ) },
			{ title: __( 'Open/Closed status looks wrong', 'velox-map-locator' ), body: __( 'Check the Location timezone first, then weekly intervals, overnight hours and Special Hours. Also check Operational Status; Temporarily Closed or Coming Soon intentionally overrides normal weekly-state presentation.', 'velox-map-locator' ) },
			{ title: __( 'Markers overlap each other', 'velox-map-locator' ), body: __( 'Use Clustering Auto or Enabled for dense datasets. Locations sharing the same coordinates are protected with a chooser even when normal clustering is disabled, so each record remains accessible.', 'velox-map-locator' ) },
			{ title: __( 'The Split sidebar is too wide or too narrow', 'velox-map-locator' ), body: __( 'Open the Locator Builder → Layout and adjust Sidebar Width. Velox defaults to 25% to keep the map dominant. Narrow containers and mobile layouts automatically stack rather than forcing the desktop ratio.', 'velox-map-locator' ) },
			{ title: __( 'CSV validation reports an External ID problem', 'velox-map-locator' ), body: __( 'External ID is the import update key and must be unique within the CSV. In Update/Create mode, a matching External ID updates that record. In Create Only mode, an existing External ID is a conflict. Do not use names or addresses as substitute identifiers.', 'velox-map-locator' ) },
			{ title: __( 'Changes are saved but the public page still looks old', 'velox-map-locator' ), body: __( 'First reload without cache. Then clear any page cache, optimization/minification cache, CDN cache or server cache used by the site. Velox asset versions are file/version based, but an external optimization layer can still serve an older page or asset bundle.', 'velox-map-locator' ) },
		];

		const glossary = [
			[ __( 'Basemap', 'velox-map-locator' ), __( 'The geographic background drawn underneath Velox markers, such as OpenStreetMap, Google Maps or a Custom XYZ tile service.', 'velox-map-locator' ) ],
			[ __( 'Map Provider', 'velox-map-locator' ), __( 'The service/adapter Velox uses to display the interactive map. Current provider families are OpenStreetMap, Google Maps and Custom XYZ.', 'velox-map-locator' ) ],
			[ __( 'OpenStreetMap (OSM)', 'velox-map-locator' ), __( 'The built-in no-key map option. Velox bundles Leaflet locally and requests the selected OSM raster tiles from the external tile service when the map loads.', 'velox-map-locator' ) ],
			[ __( 'Leaflet', 'velox-map-locator' ), __( 'The locally bundled open-source JavaScript mapping library used by Velox for OpenStreetMap and Custom XYZ maps.', 'velox-map-locator' ) ],
			[ __( 'Custom XYZ', 'velox-map-locator' ), __( 'A raster tile provider that follows the common z/x/y URL pattern. Velox stores reusable XYZ profiles containing the endpoint and related settings.', 'velox-map-locator' ) ],
			[ __( 'XYZ Profile', 'velox-map-locator' ), __( 'A reusable Custom XYZ configuration containing its tile URL, attribution, zoom limits, subdomains and optional service/privacy information.', 'velox-map-locator' ) ],
			[ __( 'Tile URL Template', 'velox-map-locator' ), __( 'The URL pattern used to request raster map tiles, normally containing placeholders such as {z}, {x} and {y}.', 'velox-map-locator' ) ],
			[ __( 'TMS', 'velox-map-locator' ), __( 'Tile Map Service coordinate convention in which the tile Y axis is flipped relative to the common web XYZ convention.', 'velox-map-locator' ) ],
			[ __( 'Retina Tiles', 'velox-map-locator' ), __( 'Higher-pixel-density map tiles intended for high-DPI displays when the chosen tile service supports them.', 'velox-map-locator' ) ],
			[ __( 'Google API Key', 'velox-map-locator' ), __( 'The browser credential used for Google Maps requests. Because the Maps JavaScript API runs in the browser, the key must be protected with appropriate referrer and API restrictions rather than treated as a secret.', 'velox-map-locator' ) ],
			[ __( 'Map ID', 'velox-map-locator' ), __( 'A Google Maps identifier associated with map configuration. Velox requires it for the Advanced Marker implementation.', 'velox-map-locator' ) ],
			[ __( 'Geocoding', 'velox-map-locator' ), __( 'Converting a human-readable address into geographic coordinates. Velox can explicitly use Google geocoding through Find on Map when Google is configured.', 'velox-map-locator' ) ],
			[ __( 'Location', 'velox-map-locator' ), __( 'One physical place stored in Velox, such as an office, store, kiosk, branch, dealer, ATM or service centre.', 'velox-map-locator' ) ],
			[ __( 'Locator', 'velox-map-locator' ), __( 'A reusable public map/list experience that selects Locations and controls their layout, search, filters, content, appearance, behaviour and privacy.', 'velox-map-locator' ) ],
			[ __( 'Type', 'velox-map-locator' ), __( 'A non-hierarchical classification describing what a Location is or does, such as Office, Store, Dealer or Clinic.', 'velox-map-locator' ) ],
			[ __( 'Group', 'velox-map-locator' ), __( 'A hierarchical classification used to organise Locations into nested collections, regions, brands or other structures.', 'velox-map-locator' ) ],
			[ __( 'Primary Type', 'velox-map-locator' ), __( 'The one assigned Type a Location uses as its main classification for inherited marker styling. A Location may still have multiple Types.', 'velox-map-locator' ) ],
			[ __( 'Marker Override', 'velox-map-locator' ), __( 'Location-specific marker styling that takes precedence over Primary Type, Locator and theme marker defaults.', 'velox-map-locator' ) ],
			[ __( 'Marker Inheritance', 'velox-map-locator' ), __( 'The styling fallback sequence: Location override → Primary Type → Locator default → theme/default marker.', 'velox-map-locator' ) ],
			[ __( 'Operational Status', 'velox-map-locator' ), __( 'A high-level Location state such as Normal, Temporarily Closed or Coming Soon.', 'velox-map-locator' ) ],
			[ __( 'Weekly Hours', 'velox-map-locator' ), __( 'The recurring opening schedule for Monday through Sunday, supporting closed days, all-day operation, split shifts and overnight intervals.', 'velox-map-locator' ) ],
			[ __( 'Special Hours', 'velox-map-locator' ), __( 'Date-specific business-hour overrides used for holidays, exceptional openings or exceptional closures.', 'velox-map-locator' ) ],
			[ __( 'Timezone', 'velox-map-locator' ), __( 'The IANA timezone assigned to a Location so live business status is calculated using that place’s local time.', 'velox-map-locator' ) ],
			[ __( 'External ID', 'velox-map-locator' ), __( 'A stable identifier used by CSV import to decide whether a row updates an existing Location or creates a new one.', 'velox-map-locator' ) ],
			[ __( 'Split Layout', 'velox-map-locator' ), __( 'The flagship public layout that shows both the Location list and interactive map. The default desktop sidebar width is 25%.', 'velox-map-locator' ) ],
			[ __( 'Map Only', 'velox-map-locator' ), __( 'A Locator layout focused on the interactive map. Velox still keeps server-rendered information available for graceful/progressive behaviour.', 'velox-map-locator' ) ],
			[ __( 'List Only', 'velox-map-locator' ), __( 'A Locator layout with no interactive map. Map-specific SDKs and coordinates are not loaded/exposed merely to render the list.', 'velox-map-locator' ) ],
			[ __( 'Source', 'velox-map-locator' ), __( 'The Locator Builder rules that determine which published Locations belong to that Locator.', 'velox-map-locator' ) ],
			[ __( 'All Locations Source', 'velox-map-locator' ), __( 'A Source mode that automatically includes every eligible published Location.', 'velox-map-locator' ) ],
			[ __( 'Selected Locations Source', 'velox-map-locator' ), __( 'A Source mode that uses an explicit curated list of Locations and supports manual ordering.', 'velox-map-locator' ) ],
			[ __( 'Dynamic Source', 'velox-map-locator' ), __( 'A Source mode that selects Locations from rules such as Type, Group, Country or City and can apply exclusions.', 'velox-map-locator' ) ],
			[ __( 'Clustering', 'velox-map-locator' ), __( 'Combining nearby markers into an aggregate marker at broader zoom levels to keep dense maps readable and performant.', 'velox-map-locator' ) ],
			[ __( 'Coincident Locations', 'velox-map-locator' ), __( 'Two or more Locations with the same or effectively identical coordinates. Velox exposes them through a chooser so they remain individually accessible.', 'velox-map-locator' ) ],
			[ __( 'Home', 'velox-map-locator' ), __( 'A map control that restores the Locator’s original configured/overall geographic context.', 'velox-map-locator' ) ],
			[ __( 'Fit All', 'velox-map-locator' ), __( 'A map control that fits the currently visible Locations, including the result set after search or filtering.', 'velox-map-locator' ) ],
			[ __( 'Fixed Initial View', 'velox-map-locator' ), __( 'A deliberate map centre and zoom used instead of automatically fitting the visible Locations when the Locator first loads.', 'velox-map-locator' ) ],
			[ __( 'Single-location Zoom', 'velox-map-locator' ), __( 'The zoom level used when only one Location needs to be framed and bounds fitting would otherwise zoom too far in/out.', 'velox-map-locator' ) ],
			[ __( 'Near Me', 'velox-map-locator' ), __( 'An optional visitor action that requests browser geolocation, calculates straight-line distance locally and can sort Locations by proximity. Velox does not persist the visitor coordinates.', 'velox-map-locator' ) ],
			[ __( 'Deep Link', 'velox-map-locator' ), __( 'A URL that opens/selects a specific Location in a Locator, currently using the vml-location query parameter after validating that the Location belongs to that Locator.', 'velox-map-locator' ) ],
			[ __( 'Privacy Mode', 'velox-map-locator' ), __( 'The map-loading mode that keeps external map services unloaded until the visitor explicitly clicks Load Map. The local Location list remains usable.', 'velox-map-locator' ) ],
			[ __( 'Map Load Mode', 'velox-map-locator' ), __( 'The rule controlling when a map provider is initialized: follow the global default, load near the viewport, or require explicit interaction.', 'velox-map-locator' ) ],
			[ __( 'Progressive Enhancement', 'velox-map-locator' ), __( 'Velox renders useful Location list content on the server first, then enhances it with search, filters, geolocation and maps when JavaScript is available.', 'velox-map-locator' ) ],
			[ __( 'Live Preview', 'velox-map-locator' ), __( 'The Locator Builder preview generated through the same validator/query/renderer path as the public Locator rather than a separate mock representation.', 'velox-map-locator' ) ],
			[ __( 'Container-responsive Layout', 'velox-map-locator' ), __( 'Responsive behaviour based on the Locator’s available parent/container width as well as viewport fallbacks, useful inside narrow page-builder columns.', 'velox-map-locator' ) ],
			[ __( 'Dynamic Gutenberg Block', 'velox-map-locator' ), __( 'The Velox block that stores a Locator ID and renders that Locator on the server, so Builder changes automatically update embedded blocks.', 'velox-map-locator' ) ],
			[ __( 'Shortcode', 'velox-map-locator' ), __( 'The [velox_map_locator id="123"] embed syntax used where WordPress shortcodes are supported.', 'velox-map-locator' ) ],
			[ __( 'Attribution', 'velox-map-locator' ), __( 'Required acknowledgement text/links for a map data or tile provider. Do not remove attribution required by the selected service.', 'velox-map-locator' ) ],
			[ __( 'Refit on Filter', 'velox-map-locator' ), __( 'A map behaviour that recalculates the viewport after search/filter changes so the visible result markers remain framed.', 'velox-map-locator' ) ],
			[ __( 'Distance Unit: Auto', 'velox-map-locator' ), __( 'Lets Velox choose kilometres or miles based on visitor locale rather than forcing one unit for every visitor.', 'velox-map-locator' ) ],
		];

		const searchText = ( value ) => String( value || '' ).toLocaleLowerCase();
		const matches = ( parts ) => ! normalizedQuery || parts.map( searchText ).join( ' ' ).includes( normalizedQuery );
		const filteredWorkflow = workflow.filter( ( item ) => matches( [ item.title, item.text, item.label ] ) );
		const filteredGuides = guides.filter( ( topic ) => matches( [ topic.title, topic.summary, topic.intro, ...( topic.steps || [] ), ...( topic.notes || [] ) ] ) );
		const filteredTroubleshooting = troubleshooting.filter( ( item ) => matches( [ item.title, item.body ] ) );
		const filteredGlossary = glossary.filter( ( item ) => matches( item ) );

		const tabs = [
			[ 'workflow', __( 'Recommended Workflow', 'velox-map-locator' ) ],
			[ 'guides', __( 'Step-by-Step Guides', 'velox-map-locator' ) ],
			[ 'troubleshooting', __( 'Troubleshooting', 'velox-map-locator' ) ],
			[ 'glossary', __( 'Glossary', 'velox-map-locator' ) ],
		];

		return h( Fragment, null,
			h( PageHeader, {
				title: __( 'Help & Workflow Guide', 'velox-map-locator' ),
				description: __( 'Learn the recommended Velox workflow, find task-based instructions, troubleshoot common problems and look up plugin terminology.', 'velox-map-locator' ),
			}, h( Button, { variant: 'secondary', onClick: () => window.print() }, __( 'Print Guide', 'velox-map-locator' ) ) ),
			h( 'div', { className: 'vml-help-hero vml-panel' },
				h( 'div', null,
					h( 'strong', null, __( 'New to Velox Map Locator?', 'velox-map-locator' ) ),
					h( 'p', null, __( 'Start with the Recommended Workflow. The core idea is simple: configure providers and defaults → create reusable classifications → add Locations → build a Locator → embed and test it. Import/Export is best introduced after that model is proven.', 'velox-map-locator' ) )
				),
				h( 'div', { className: 'vml-help-search' },
					h( Icon, { name: 'search', size: 17 } ),
					h( 'label', { className: 'screen-reader-text', htmlFor: 'vml-help-search' }, __( 'Search help', 'velox-map-locator' ) ),
					h( 'input', { id: 'vml-help-search', type: 'search', value: query, placeholder: __( 'Search help and glossary…', 'velox-map-locator' ), onChange: ( event ) => setQuery( event.target.value ) } ),
					query && h( 'button', { type: 'button', onClick: () => setQuery( '' ) }, __( 'Clear', 'velox-map-locator' ) )
				)
			),
			h( 'div', { className: 'vml-help-tabs', role: 'tablist', 'aria-label': __( 'Help sections', 'velox-map-locator' ) }, tabs.map( ( item ) => h( 'button', { key: item[ 0 ], id: `vml-help-tab-${ item[ 0 ] }`, type: 'button', role: 'tab', 'aria-selected': tab === item[ 0 ], 'aria-controls': `vml-help-panel-${ item[ 0 ] }`, tabIndex: tab === item[ 0 ] ? 0 : -1, className: tab === item[ 0 ] ? 'is-active' : '', onClick: () => { setTab( item[ 0 ] ); setQuery( '' ); } }, item[ 1 ] ) ) ),
			tab === 'workflow' && h( 'div', { className: 'vml-help-workflow', role: 'tabpanel', id: 'vml-help-panel-workflow', 'aria-labelledby': 'vml-help-tab-workflow' },
				h( 'div', { className: 'vml-help-section-intro' }, h( 'h2', null, __( 'Recommended end-to-end workflow', 'velox-map-locator' ) ), h( 'p', null, __( 'This sequence minimizes rework and keeps one source of truth for Locations and Locators.', 'velox-map-locator' ) ) ),
				filteredWorkflow.length ? filteredWorkflow.map( ( item ) => h( 'article', { key: item.title, className: 'vml-help-workflow-card' },
					h( 'span', { className: 'vml-help-workflow-number', 'aria-hidden': 'true' }, String( workflow.indexOf( item ) + 1 ).padStart( 2, '0' ) ),
					h( 'div', null, h( 'h3', null, item.title.replace( /^\d+\.\s*/, '' ) ), h( 'p', null, item.text ), item.url && h( 'a', { href: item.url }, item.label, ' ', h( Icon, { name: 'chevron', size: 13 } ) ) )
				) ) : h( 'div', { className: 'vml-help-empty' }, __( 'No workflow steps match your search.', 'velox-map-locator' ) )
			),
			tab === 'guides' && h( 'div', { className: 'vml-help-topic-list', role: 'tabpanel', id: 'vml-help-panel-guides', 'aria-labelledby': 'vml-help-tab-guides' },
				h( 'div', { className: 'vml-help-section-intro' }, h( 'h2', null, __( 'Task-based guides', 'velox-map-locator' ) ), h( 'p', null, __( 'Open a topic for detailed steps. Search filters the guide topics locally.', 'velox-map-locator' ) ) ),
				filteredGuides.length ? filteredGuides.map( ( topic, index ) => h( HelpTopic, { key: topic.title, topic, open: Boolean( normalizedQuery ) || index === 0 } ) ) : h( 'div', { className: 'vml-help-empty' }, __( 'No guide topics match your search.', 'velox-map-locator' ) )
			),
			tab === 'troubleshooting' && h( 'div', { className: 'vml-help-topic-list', role: 'tabpanel', id: 'vml-help-panel-troubleshooting', 'aria-labelledby': 'vml-help-tab-troubleshooting' },
				h( 'div', { className: 'vml-help-section-intro' }, h( 'h2', null, __( 'Troubleshooting', 'velox-map-locator' ) ), h( 'p', null, __( 'Common symptoms and the first things to check before deeper debugging.', 'velox-map-locator' ) ) ),
				filteredTroubleshooting.length ? filteredTroubleshooting.map( ( item ) => h( 'details', { key: item.title, className: 'vml-help-topic', open: Boolean( normalizedQuery ) }, h( 'summary', null, h( 'span', null, h( 'strong', null, item.title ) ), h( Icon, { name: 'chevron', size: 16 } ) ), h( 'div', { className: 'vml-help-topic__body' }, h( 'p', null, item.body ) ) ) ) : h( 'div', { className: 'vml-help-empty' }, __( 'No troubleshooting topics match your search.', 'velox-map-locator' ) )
			),
			tab === 'glossary' && h( 'div', { className: 'vml-help-glossary-wrap', role: 'tabpanel', id: 'vml-help-panel-glossary', 'aria-labelledby': 'vml-help-tab-glossary' },
				h( 'div', { className: 'vml-help-section-intro' }, h( 'h2', null, __( 'Glossary', 'velox-map-locator' ) ), h( 'p', null, sprintf( __( '%d Velox and mapping terms explained in plain language.', 'velox-map-locator' ), glossary.length ) ) ),
				filteredGlossary.length ? h( 'dl', { className: 'vml-help-glossary' }, filteredGlossary.map( ( item ) => h( 'div', { key: item[ 0 ], className: 'vml-help-glossary-item' }, h( 'dt', null, item[ 0 ] ), h( 'dd', null, item[ 1 ] ) ) ) ) : h( 'div', { className: 'vml-help-empty' }, __( 'No glossary terms match your search.', 'velox-map-locator' ) )
			)
		);
	}


	function SettingsScreen() {
		const [ form, setForm ] = useState( clone( boot.globalSettings || {} ) );
		const [ saved, setSaved ] = useState( JSON.stringify( boot.globalSettings || {} ) );
		const [ loading, setLoading ] = useState( true );
		const [ saving, setSaving ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ notice, setNotice ] = useState( '' );
		const dirty = JSON.stringify( form || {} ) !== saved;
		const update = ( path, value ) => setForm( ( current ) => setNested( current || {}, path, value ) );

		useEffect( () => {
			let cancelled = false;
			apiFetch( { path: `${ namespace }/admin/settings` } ).then( ( settings ) => {
				if ( cancelled ) return;
				setForm( settings ); setSaved( JSON.stringify( settings ) );
			} ).catch( ( err ) => { if ( ! cancelled ) setError( getErrorMessage( err ) ); } ).finally( () => { if ( ! cancelled ) setLoading( false ); } );
			return () => { cancelled = true; };
		}, [] );

		async function save() {
			setSaving( true ); setError( '' ); setNotice( '' );
			try {
				const result = await apiFetch( { path: `${ namespace }/admin/settings`, method: 'PUT', data: { settings: form } } );
				setForm( result ); setSaved( JSON.stringify( result ) );
				setNotice( __( 'Settings saved. New Locator defaults will use these values.', 'velox-map-locator' ) );
			} catch ( err ) { setError( getErrorMessage( err ) ); }
			finally { setSaving( false ); }
		}

		if ( loading ) return h( LoadingScreen );
		const general = form.general || {};
		const mapDefaults = form.map_defaults || {};
		const appearance = form.appearance || {};
		const admin = form.admin_interface || {};
		const privacy = form.privacy_defaults || {};
		const data = form.data || {};
		const xyzProfiles = boot.mapProviderProfiles || [];
		const provider = general.default_map_provider || 'osm';
		const googleConfigured = Boolean( boot.googleMaps && boot.googleMaps.configured );

		return h( Fragment, null,
			h( PageHeader, { title: __( 'Settings', 'velox-map-locator' ), description: __( 'Set sensible defaults for new Locators and control plugin-wide privacy, administration and data behaviour.', 'velox-map-locator' ) }, h( Button, { variant: 'primary', isBusy: saving, disabled: saving || ! dirty || ( provider === 'xyz' && ! general.default_xyz_profile_id ), onClick: save }, __( 'Save Settings', 'velox-map-locator' ) ) ),
			notice && h( Notice, { status: 'success', onRemove: () => setNotice( '' ) }, notice ),
			error && h( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ),
			h( 'div', { className: 'vml-settings-grid' },
				h( 'section', { className: 'vml-panel vml-settings-panel' },
					h( 'div', { className: 'vml-settings-panel__head' }, h( 'div', null, h( 'h2', null, __( 'Locator Defaults', 'velox-map-locator' ) ), h( 'p', null, __( 'These values seed newly created Locators. Existing Locator-specific choices remain intact.', 'velox-map-locator' ) ) ) ),
					h( 'div', { className: 'vml-field-grid two' },
						h( Field, { label: __( 'Default Distance Unit', 'velox-map-locator' ) }, h( 'select', { value: general.default_distance_unit || 'auto', onChange: ( e ) => update( 'general.default_distance_unit', e.target.value ) }, h( 'option', { value: 'auto' }, __( 'Automatic by visitor locale', 'velox-map-locator' ) ), h( 'option', { value: 'kilometres' }, __( 'Kilometres', 'velox-map-locator' ) ), h( 'option', { value: 'miles' }, __( 'Miles', 'velox-map-locator' ) ) ) ),
						h( Field, { label: __( 'Default Basemap / Provider', 'velox-map-locator' ), hint: provider === 'google' && ! googleConfigured ? __( 'Google Maps is not fully configured under Map Providers.', 'velox-map-locator' ) : '' }, h( 'select', { value: provider, onChange: ( e ) => update( 'general.default_map_provider', e.target.value ) }, h( 'option', { value: 'osm' }, __( 'OpenStreetMap', 'velox-map-locator' ) ), h( 'option', { value: 'google' }, __( 'Google Maps', 'velox-map-locator' ) ), h( 'option', { value: 'xyz', disabled: ! xyzProfiles.length }, __( 'Custom XYZ', 'velox-map-locator' ) ) ) ),
						provider === 'xyz' && h( Field, { label: __( 'Default XYZ Profile', 'velox-map-locator' ), required: true }, h( 'select', { value: general.default_xyz_profile_id || '', onChange: ( e ) => update( 'general.default_xyz_profile_id', e.target.value ) }, h( 'option', { value: '' }, __( 'Choose a profile', 'velox-map-locator' ) ), xyzProfiles.map( ( profile ) => h( 'option', { key: profile.id, value: profile.id }, profile.name ) ) ) ),
						h( Field, { label: __( 'Split Sidebar Width', 'velox-map-locator' ), hint: __( 'Velox uses 25% by default to keep the map visually dominant.', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 20, max: 50, value: mapDefaults.sidebar_width || 25, onChange: ( e ) => update( 'map_defaults.sidebar_width', Number( e.target.value ) ) } ) ),
						h( Field, { label: __( 'Default Map Height', 'velox-map-locator' ), hint: '300–1200 px' }, h( 'input', { type: 'number', min: 300, max: 1200, value: mapDefaults.height || 620, onChange: ( e ) => update( 'map_defaults.height', Number( e.target.value ) ) } ) ),
						h( Field, { label: __( 'Single-location Zoom', 'velox-map-locator' ) }, h( 'input', { type: 'number', min: 1, max: 22, value: mapDefaults.single_location_zoom || 14, onChange: ( e ) => update( 'map_defaults.single_location_zoom', Number( e.target.value ) ) } ) )
					)
				),
				h( 'section', { className: 'vml-panel vml-settings-panel' },
					h( 'div', { className: 'vml-settings-panel__head' }, h( 'div', null, h( 'h2', null, __( 'Default Map Controls', 'velox-map-locator' ) ), h( 'p', null, __( 'Provider-neutral defaults for new map-enabled Locators.', 'velox-map-locator' ) ) ) ),
					h( 'div', { className: 'vml-builder-toggle-list' },
						h( BuilderToggle, { label: __( 'Home', 'velox-map-locator' ), description: __( 'Return to the initial map view.', 'velox-map-locator' ), checked: mapDefaults.home_control !== false, onChange: ( value ) => update( 'map_defaults.home_control', value ) } ),
						h( BuilderToggle, { label: __( 'Fit All', 'velox-map-locator' ), description: __( 'Fit visible locations into the map.', 'velox-map-locator' ), checked: mapDefaults.fit_control !== false, onChange: ( value ) => update( 'map_defaults.fit_control', value ) } ),
						h( BuilderToggle, { label: __( 'Zoom controls', 'velox-map-locator' ), checked: mapDefaults.zoom_controls !== false, onChange: ( value ) => update( 'map_defaults.zoom_controls', value ) } ),
						h( BuilderToggle, { label: __( 'Zoom level indicator', 'velox-map-locator' ), checked: mapDefaults.zoom_level_control !== false, onChange: ( value ) => update( 'map_defaults.zoom_level_control', value ) } ),
						h( BuilderToggle, { label: __( 'Scale', 'velox-map-locator' ), checked: mapDefaults.scale_control !== false, onChange: ( value ) => update( 'map_defaults.scale_control', value ) } ),
						h( BuilderToggle, { label: __( 'Fullscreen', 'velox-map-locator' ), checked: mapDefaults.fullscreen !== false, onChange: ( value ) => update( 'map_defaults.fullscreen', value ) } ),
						h( BuilderToggle, { label: __( 'Scroll-wheel zoom', 'velox-map-locator' ), checked: mapDefaults.scroll_zoom === true, onChange: ( value ) => update( 'map_defaults.scroll_zoom', value ) } ),
						h( BuilderToggle, { label: __( 'Refit after search/filter', 'velox-map-locator' ), checked: mapDefaults.refit_on_filter !== false, onChange: ( value ) => update( 'map_defaults.refit_on_filter', value ) } )
					)
				),
				h( 'section', { className: 'vml-panel vml-settings-panel' },
					h( 'div', { className: 'vml-settings-panel__head' }, h( 'div', null, h( 'h2', null, __( 'Appearance Defaults', 'velox-map-locator' ) ), h( 'p', null, __( 'Visual defaults applied when a new Locator is created.', 'velox-map-locator' ) ) ) ),
					h( 'div', { className: 'vml-field-grid two' },
						h( Field, { label: __( 'Theme', 'velox-map-locator' ) }, h( 'select', { value: general.default_theme || 'velox', onChange: ( e ) => update( 'general.default_theme', e.target.value ) }, [ 'velox', 'slate', 'azure', 'forest' ].map( ( value ) => h( 'option', { key: value, value }, humanize( value ) ) ) ) ),
						h( Field, { label: __( 'Colour Mode', 'velox-map-locator' ) }, h( 'select', { value: general.default_colour_mode || 'light', onChange: ( e ) => update( 'general.default_colour_mode', e.target.value ) }, h( 'option', { value: 'light' }, __( 'Light', 'velox-map-locator' ) ), h( 'option', { value: 'dark' }, __( 'Dark', 'velox-map-locator' ) ), h( 'option', { value: 'auto' }, __( 'Auto', 'velox-map-locator' ) ) ) ),
						h( Field, { label: __( 'Typography', 'velox-map-locator' ) }, h( 'select', { value: general.default_typography || 'inherit', onChange: ( e ) => update( 'general.default_typography', e.target.value ) }, [ [ 'inherit', __( 'Inherit Site', 'velox-map-locator' ) ], [ 'modern-sans', __( 'Modern Sans', 'velox-map-locator' ) ], [ 'humanist-sans', __( 'Humanist', 'velox-map-locator' ) ], [ 'classic-sans', __( 'Classic Sans', 'velox-map-locator' ) ], [ 'serif', __( 'Serif', 'velox-map-locator' ) ] ].map( ( item ) => h( 'option', { key: item[ 0 ], value: item[ 0 ] }, item[ 1 ] ) ) ) ),
						h( Field, { label: __( 'Density', 'velox-map-locator' ) }, h( 'select', { value: appearance.density || 'comfortable', onChange: ( e ) => update( 'appearance.density', e.target.value ) }, [ 'compact', 'comfortable', 'spacious' ].map( ( value ) => h( 'option', { key: value, value }, humanize( value ) ) ) ) )
					),
					h( ColorField, { label: __( 'Default Accent Colour', 'velox-map-locator' ), value: appearance.accent || '#2563eb', onChange: ( value ) => update( 'appearance.accent', value ) } )
				),
				h( 'section', { className: 'vml-panel vml-settings-panel' },
					h( 'div', { className: 'vml-settings-panel__head' }, h( 'div', null, h( 'h2', null, __( 'Privacy & Administration', 'velox-map-locator' ) ), h( 'p', null, __( 'Control external map loading and the Velox administration interface.', 'velox-map-locator' ) ) ) ),
					h( 'div', { className: 'vml-field-grid two' },
						h( Field, { label: __( 'Global Map Loading', 'velox-map-locator' ), hint: __( 'Locators set to “Use global default” inherit this choice.', 'velox-map-locator' ) }, h( 'select', { value: privacy.map_load_mode || 'immediate', onChange: ( e ) => update( 'privacy_defaults.map_load_mode', e.target.value ) }, h( 'option', { value: 'immediate' }, __( 'Load when Locator is near viewport', 'velox-map-locator' ) ), h( 'option', { value: 'interaction' }, __( 'Require visitor to click Load Map', 'velox-map-locator' ) ) ) ),
						h( Field, { label: __( 'Admin Appearance', 'velox-map-locator' ), hint: __( 'Applies after this admin page is reloaded.', 'velox-map-locator' ) }, h( 'select', { value: admin.appearance || 'system', onChange: ( e ) => update( 'admin_interface.appearance', e.target.value ) }, h( 'option', { value: 'system' }, __( 'Follow System', 'velox-map-locator' ) ), h( 'option', { value: 'light' }, __( 'Light', 'velox-map-locator' ) ), h( 'option', { value: 'dark' }, __( 'Dark', 'velox-map-locator' ) ) ) ),
						h( Field, { label: __( 'Admin Density', 'velox-map-locator' ) }, h( 'select', { value: admin.density || 'comfortable', onChange: ( e ) => update( 'admin_interface.density', e.target.value ) }, h( 'option', { value: 'comfortable' }, __( 'Comfortable', 'velox-map-locator' ) ), h( 'option', { value: 'compact' }, __( 'Compact', 'velox-map-locator' ) ) ) )
					)
				),
				h( 'section', { className: 'vml-panel vml-settings-panel is-danger-zone' },
					h( 'div', { className: 'vml-settings-panel__head' }, h( 'div', null, h( 'h2', null, __( 'Data on Uninstall', 'velox-map-locator' ) ), h( 'p', null, __( 'Velox preserves Locations, Locators and settings by default when the plugin is deleted.', 'velox-map-locator' ) ) ) ),
					h( BuilderToggle, { label: __( 'Delete plugin data on uninstall', 'velox-map-locator' ), description: __( 'Destructive. Media Library attachments are never deleted automatically.', 'velox-map-locator' ), checked: data.delete_data_on_uninstall === true, onChange: ( value ) => update( 'data.delete_data_on_uninstall', value ) } )
				)
			)
		);
	}

	function App() {
		let content;
		if ( boot.route === 'locations' && boot.action === 'edit' ) content = h( LocationEditor );
		else if ( boot.route === 'locations' ) content = h( LocationsList );
		else if ( boot.route === 'locators' && boot.action === 'edit' ) content = h( LocatorBuilder );
		else if ( boot.route === 'locators' ) content = h( LocatorsList );
		else if ( boot.route === 'classifications' ) content = h( Classifications );
		else if ( boot.route === 'providers' ) content = h( MapProviders );
		else if ( boot.route === 'import-export' ) content = h( ImportExportScreen );
		else if ( boot.route === 'settings' ) content = h( SettingsScreen );
		else if ( boot.route === 'help' ) content = h( HelpScreen );
		else content = h( Overview );
		return h( AppShell, null, content );
	}

	const mount = document.getElementById( 'vml-admin-app' );
	if ( mount ) {
		if ( wp.element.createRoot ) wp.element.createRoot( mount ).render( h( App ) );
		else wp.element.render( h( App ), mount );
	}
}() );
