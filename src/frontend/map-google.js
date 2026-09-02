/* Velox Map Locator — Google Maps adapter. */
( function () {
	'use strict';

	let loaderPromise = null;
	let loaderKey = '';
	const authFailureHandlers = new Set();
	let authFailureInstalled = false;

	function safeJsonParse( node ) {
		if ( ! node ) return null;
		try { return JSON.parse( node.textContent || '{}' ); } catch ( error ) { return null; }
	}

	function validCoordinate( value, min, max ) {
		const number = Number( value );
		return Number.isFinite( number ) && number >= min && number <= max ? number : null;
	}

	function markerSize( marker ) {
		const value = marker && marker.size;
		if ( value === 'small' ) return 28;
		if ( value === 'large' ) return 40;
		return 34;
	}

	function markerElement( location ) {
		const marker = location.marker || {};
		const size = markerSize( marker );
		const shell = document.createElement( 'span' );
		shell.className = 'vml-map-marker vml-google-marker';
		shell.style.setProperty( '--vml-marker-color', marker.color || '#2563eb' );
		shell.style.setProperty( '--vml-marker-icon-color', marker.icon_color || '#ffffff' );
		shell.style.setProperty( '--vml-marker-size', `${ size }px` );
		shell.dataset.vmlMarkerIcon = marker.icon || 'pin';

		if ( marker.media_url ) {
			const image = document.createElement( 'img' );
			image.src = marker.media_url;
			image.alt = '';
			image.className = 'vml-map-marker__image';
			shell.appendChild( image );
		} else {
			const glyph = document.createElement( 'span' );
			glyph.className = 'vml-map-marker__glyph';
			glyph.setAttribute( 'aria-hidden', 'true' );
			shell.appendChild( glyph );
		}
		return shell;
	}

	function popupElement( location, root ) {
		const popup = document.createElement( 'div' );
		popup.className = 'vml-map-popup';
		const title = document.createElement( 'strong' );
		title.className = 'vml-map-popup__title';
		title.textContent = location.name || '';
		popup.appendChild( title );

		if ( location.address ) {
			const address = document.createElement( 'span' );
			address.className = 'vml-map-popup__address';
			address.textContent = location.address;
			popup.appendChild( address );
		}

		const card = root.querySelector( `[data-vml-location-id="${ Number( location.id ) }"]` );
		const statusNode = card && card.querySelector( '[data-vml-status-label]' );
		if ( statusNode && statusNode.textContent.trim() ) {
			const status = document.createElement( 'span' );
			status.className = 'vml-map-popup__status';
			status.textContent = statusNode.textContent.trim();
			popup.appendChild( status );
		}

		if ( location.phone ) {
			const phone = document.createElement( 'a' );
			phone.href = `tel:${ location.phone.replace( /[^+\d]/g, '' ) }`;
			phone.textContent = location.phone;
			phone.className = 'vml-map-popup__link';
			popup.appendChild( phone );
		}

		if ( location.directions_url ) {
			const actions = document.createElement( 'div' );
			actions.className = 'vml-map-popup__actions';
			const directions = document.createElement( 'a' );
			directions.href = location.directions_url;
			directions.target = '_blank';
			directions.rel = 'noopener noreferrer';
			directions.className = 'vml-map-popup__button';
			directions.textContent = ( root.vmlController && root.vmlController.strings && root.vmlController.strings.directions ) || 'Directions';
			actions.appendChild( directions );
			popup.appendChild( actions );
		}
		return popup;
	}

	function mercatorPixel( lat, lng, zoom ) {
		const sin = Math.sin( Math.max( -85.05112878, Math.min( 85.05112878, lat ) ) * Math.PI / 180 );
		const scale = 256 * Math.pow( 2, zoom );
		return {
			x: ( 0.5 + lng / 360 ) * scale,
			y: ( 0.5 - Math.log( ( 1 + sin ) / ( 1 - sin ) ) / ( 4 * Math.PI ) ) * scale,
		};
	}

	function installAuthFailureBridge() {
		if ( authFailureInstalled ) return;
		authFailureInstalled = true;
		const previous = window.gm_authFailure;
		window.gm_authFailure = function () {
			authFailureHandlers.forEach( ( handler ) => {
				try { handler(); } catch ( error ) {}
			} );
			if ( typeof previous === 'function' ) previous();
		};
	}

	function googleReady() {
		return Boolean( window.google && window.google.maps && typeof window.google.maps.importLibrary === 'function' );
	}

	function loadGoogleMaps( provider ) {
		if ( googleReady() ) return Promise.resolve( window.google.maps );
		const apiKey = String( provider.api_key || '' ).trim();
		if ( ! apiKey ) return Promise.reject( new Error( 'Google Maps API key is missing.' ) );
		if ( loaderPromise ) {
			return loaderKey === apiKey ? loaderPromise : Promise.reject( new Error( 'Google Maps is already loading with a different API key.' ) );
		}

		loaderKey = apiKey;
		installAuthFailureBridge();
		loaderPromise = new Promise( ( resolve, reject ) => {
			let settled = false;
			const finish = () => {
				if ( settled ) return;
				if ( googleReady() ) {
					settled = true;
					resolve( window.google.maps );
				}
			};
			const fail = () => {
				if ( settled ) return;
				settled = true;
				reject( new Error( 'Google Maps JavaScript API could not load.' ) );
			};
			const existing = document.querySelector( 'script[src*="maps.googleapis.com/maps/api/js"]' );
			if ( existing ) {
				finish();
				if ( ! settled ) {
					existing.addEventListener( 'load', finish, { once: true } );
					existing.addEventListener( 'error', fail, { once: true } );
					let checks = 0;
					const timer = window.setInterval( () => {
						checks += 1; finish();
						if ( settled || checks >= 100 ) {
							window.clearInterval( timer );
							if ( ! settled ) fail();
						}
					}, 100 );
				}
				return;
			}

			const callback = `__vmlGoogleMapsReady${ Date.now() }`;
			window[ callback ] = function () {
				try { delete window[ callback ]; } catch ( error ) { window[ callback ] = undefined; }
				finish();
			};
			const params = new URLSearchParams( {
				key: apiKey,
				v: provider.version || 'weekly',
				loading: 'async',
				callback,
			} );
			if ( provider.region ) params.set( 'region', String( provider.region ).toUpperCase() );
			const script = document.createElement( 'script' );
			script.src = `https://maps.googleapis.com/maps/api/js?${ params.toString() }`;
			script.async = true;
			script.dataset.vmlGoogleMaps = 'true';
			const nonceSource = document.querySelector( 'script[nonce]' );
			if ( nonceSource && nonceSource.nonce ) script.nonce = nonceSource.nonce;
			script.addEventListener( 'error', fail, { once: true } );
			document.head.appendChild( script );
		} );
		return loaderPromise;
	}

	class GoogleLocatorMap {
		constructor( root, payload ) {
			this.root = root;
			this.payload = payload;
			this.config = payload.config || {};
			this.mapConfig = this.config.map || {};
			this.provider = payload.map_provider || {};
			this.canvas = root.querySelector( '[data-vml-map-canvas]' );
			this.runtimeError = root.querySelector( '[data-vml-map-runtime-error]' );
			this.markers = new Map();
			this.clusters = new Map();
			this.clusterByLocation = new Map();
			this.clusterTimer = null;
			this.visibleIds = new Set( ( payload.locations || [] ).map( ( item ) => Number( item.id ) ) );
			this.selectedId = null;
			this.ready = false;
			this.initializing = false;
			this.authFailureHandler = () => this.showFailure();
			authFailureHandlers.add( this.authFailureHandler );
		}

		async init() {
			if ( this.ready || this.initializing || ! this.canvas || ! this.provider.api_key || ! this.provider.map_id ) return;
			this.initializing = true;
			try {
				this.canvas.hidden = false;
				const maps = await loadGoogleMaps( this.provider );
				const mapsLibrary = await maps.importLibrary( 'maps' );
				const markerLibrary = await maps.importLibrary( 'marker' );
				const coreLibrary = await maps.importLibrary( 'core' );
				this.MapClass = mapsLibrary.Map;
				this.InfoWindowClass = mapsLibrary.InfoWindow;
				this.AdvancedMarkerElement = markerLibrary.AdvancedMarkerElement;
				this.LatLngBounds = coreLibrary.LatLngBounds;
				this.map = new this.MapClass( this.canvas, {
					center: { lat: 0, lng: 0 },
					zoom: 2,
					mapId: this.provider.map_id,
					zoomControl: this.mapConfig.zoom_controls !== false,
					scaleControl: this.mapConfig.scale_control !== false,
					fullscreenControl: this.mapConfig.fullscreen !== false,
					streetViewControl: false,
					mapTypeControl: false,
					scrollwheel: this.mapConfig.scroll_zoom === true,
					gestureHandling: this.mapConfig.scroll_zoom === true ? 'greedy' : 'cooperative',
					keyboardShortcuts: true,
				} );
				( this.payload.locations || [] ).forEach( ( location ) => this.addMarker( location ) );
				this.bind();
				this.addUtilityControls();
				this.applyInitialView();
				this.ready = true;
				this.root.classList.add( 'vml-map-ready' );
				this.rebuildClusters();
				const selected = this.root.querySelector( '.vml-location-card.is-selected' );
				if ( selected ) this.selectMarker( Number( selected.dataset.vmlLocationId || 0 ), false );
			} catch ( error ) {
				this.showFailure();
			} finally {
				this.initializing = false;
			}
		}


		addUtilityControls() {
			const showHome = this.mapConfig.home_control !== false;
			const showFit = this.mapConfig.fit_control !== false;
			const showZoomLevel = this.mapConfig.zoom_level_control !== false;
			if ( ! showHome && ! showFit && ! showZoomLevel ) return;
			const pane = this.root.querySelector( '[data-vml-map-pane]' );
			if ( ! pane || pane.querySelector( '[data-vml-map-tools]' ) ) return;
			const strings = this.payload.strings || {};
			const tools = document.createElement( 'div' );
			tools.className = 'vml-map-tools vml-map-tools--google';
			tools.dataset.vmlMapTools = 'true';
			tools.setAttribute( 'role', 'group' );
			tools.setAttribute( 'aria-label', strings.map_tools_label || 'Map controls' );
			if ( showHome ) {
				const home = document.createElement( 'button' );
				home.type = 'button';
				home.className = 'vml-map-tool';
				home.setAttribute( 'aria-label', strings.map_home_label || 'Return to the initial map view' );
				home.title = strings.map_home || 'Home';
				home.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 10.5 12 4l8 6.5"/><path d="M6.5 9.5V20h11V9.5"/><path d="M10 20v-6h4v6"/></svg>';
				home.addEventListener( 'click', () => this.applyInitialView() );
				tools.appendChild( home );
			}
			if ( showFit ) {
				const fit = document.createElement( 'button' );
				fit.type = 'button';
				fit.className = 'vml-map-tool';
				fit.setAttribute( 'aria-label', strings.map_fit_all_label || 'Fit visible locations in the map' );
				fit.title = strings.map_fit_all || 'Fit All';
				fit.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 4H4v4"/><path d="M16 4h4v4"/><path d="M20 16v4h-4"/><path d="M4 16v4h4"/></svg>';
				fit.addEventListener( 'click', () => this.fitVisible() );
				tools.appendChild( fit );
			}
			if ( showZoomLevel ) {
				const zoom = document.createElement( 'span' );
				zoom.className = 'vml-map-zoom-level';
				zoom.dataset.vmlZoomLevel = 'true';
				tools.appendChild( zoom );
				this.zoomLevelNode = zoom;
				this.map.addListener( 'zoom_changed', () => this.updateZoomLevel() );
			}
			pane.appendChild( tools );
			this.updateZoomLevel();
		}

		updateZoomLevel() {
			if ( ! this.zoomLevelNode || ! this.map ) return;
			const zoom = Math.round( Number( this.map.getZoom() || 0 ) * 10 ) / 10;
			this.zoomLevelNode.textContent = `Z${ zoom }`;
			const template = this.payload.strings && this.payload.strings.map_zoom_level || 'Zoom level %d';
			this.zoomLevelNode.setAttribute( 'aria-label', template.replace( '%d', String( zoom ) ) );
		}


		applyInitialView() {
			if ( this.mapConfig.initial_view === 'fixed' ) {
				const lat = validCoordinate( this.mapConfig.fixed_latitude, -90, 90 );
				const lng = validCoordinate( this.mapConfig.fixed_longitude, -180, 180 );
				if ( lat !== null && lng !== null ) {
					this.map.setCenter( { lat, lng } );
					this.map.setZoom( Number( this.mapConfig.fixed_zoom || 10 ) );
					return;
				}
			}
			this.fitAllLocations();
		}

		fitAllLocations() {
			if ( ! this.map || ! this.LatLngBounds ) return;
			const points = [];
			this.markers.forEach( ( entry ) => points.push( entry.marker.position ) );
			if ( ! points.length ) return;
			if ( points.length === 1 ) {
				this.map.setCenter( points[ 0 ] );
				this.map.setZoom( Number( this.mapConfig.single_location_zoom || 14 ) );
				return;
			}
			const bounds = new this.LatLngBounds();
			points.forEach( ( point ) => bounds.extend( point ) );
			this.map.fitBounds( bounds, 44 );
		}

		destroy() {
			if ( this.clusterTimer ) window.clearTimeout( this.clusterTimer );
			this.clearClusters();
			this.markers.forEach( ( entry ) => { entry.info.close(); entry.marker.map = null; } );
			this.markers.clear();
			this.clusterByLocation.clear();
			authFailureHandlers.delete( this.authFailureHandler );
			this.ready = false;
			this.map = null;
		}

		addMarker( location ) {
			const lat = validCoordinate( location.latitude, -90, 90 );
			const lng = validCoordinate( location.longitude, -180, 180 );
			if ( lat === null || lng === null ) return;
			const id = Number( location.id );
			const element = markerElement( location );
			const attachImmediately = ! this.clusteringActive();
			const marker = new this.AdvancedMarkerElement( {
				map: attachImmediately ? this.map : null,
				position: { lat, lng },
				title: location.name || '',
				gmpClickable: true,
				zIndex: 1,
			} );
			marker.appendChild( element );
			const info = new this.InfoWindowClass( {
				content: popupElement( location, this.root ),
				ariaLabel: location.name || '',
				maxWidth: 340,
			} );
			marker.addListener( 'click', () => {
				if ( this.root.vmlController ) this.root.vmlController.selectLocationById( id, { source: 'map', scroll: true } );
			} );
			this.markers.set( id, { marker, location, element, info, attached: attachImmediately } );
		}

		bind() {
			this.map.addListener( 'zoom_changed', () => this.scheduleClusters() );
			this.root.addEventListener( 'vml:location-selected', ( event ) => {
				const id = Number( event.detail && event.detail.id || 0 );
				this.selectMarker( id, event.detail && event.detail.source !== 'map' );
			} );
			this.root.addEventListener( 'vml:visible-locations', ( event ) => {
				this.setVisible( event.detail && event.detail.ids || [] );
			} );
		}

		selectMarker( id, moveMap = true ) {
			if ( ! id || ! this.markers.has( id ) ) return;
			if ( this.selectedId && this.markers.has( this.selectedId ) ) {
				const previous = this.markers.get( this.selectedId );
				previous.element.classList.remove( 'is-selected' );
				previous.marker.zIndex = 1;
				previous.info.close();
			}
			this.selectedId = id;
			this.rebuildClusters();
			const current = this.markers.get( id );
			current.element.classList.add( 'is-selected' );
			current.marker.zIndex = 1000;
			if ( moveMap && this.config.behaviour && this.config.behaviour.pan_on_select !== false ) {
				const bounds = this.map.getBounds();
				if ( ! bounds || ! bounds.contains( current.marker.position ) ) this.map.panTo( current.marker.position );
			}
			if ( this.config.behaviour && this.config.behaviour.open_popup_on_select !== false ) {
				current.info.open( { map: this.map, anchor: current.marker, shouldFocus: false } );
			}
		}

		setVisible( ids ) {
			this.visibleIds = new Set( ( ids || [] ).map( Number ) );
			if ( this.selectedId && ! this.visibleIds.has( this.selectedId ) && this.markers.has( this.selectedId ) ) {
				const previous = this.markers.get( this.selectedId );
				previous.element.classList.remove( 'is-selected' );
				previous.marker.zIndex = 1;
				previous.info.close();
				this.selectedId = null;
			}
			this.rebuildClusters();
			if ( this.mapConfig.refit_on_filter !== false ) this.fitVisible();
		}

		fitVisible() {
			if ( ! this.map || ! this.LatLngBounds ) return;
			const points = [];
			this.markers.forEach( ( entry, id ) => {
				if ( this.visibleIds.has( id ) ) points.push( entry.marker.position );
			} );
			if ( ! points.length ) return;
			if ( points.length === 1 ) {
				this.map.setCenter( points[ 0 ] );
				this.map.setZoom( Number( this.mapConfig.single_location_zoom || 14 ) );
				return;
			}
			const bounds = new this.LatLngBounds();
			points.forEach( ( point ) => bounds.extend( point ) );
			this.map.fitBounds( bounds, 44 );
		}

		clusteringActive() {
			const mode = this.mapConfig.clustering || 'auto';
			if ( mode === 'disabled' ) return this.hasCoincidentVisibleLocations();
			if ( mode === 'enabled' ) return true;
			return this.visibleIds.size >= 20 || this.hasCoincidentVisibleLocations();
		}

		hasCoincidentVisibleLocations() {
			const seen = new Set();
			let duplicate = false;
			this.markers.forEach( ( entry, id ) => {
				if ( duplicate || ! this.visibleIds.has( id ) ) return;
				const lat = validCoordinate( entry.location.latitude, -90, 90 );
				const lng = validCoordinate( entry.location.longitude, -180, 180 );
				if ( lat === null || lng === null ) return;
				const key = `${ lat.toFixed( 7 ) }:${ lng.toFixed( 7 ) }`;
				if ( seen.has( key ) ) duplicate = true;
				seen.add( key );
			} );
			return duplicate;
		}

		scheduleClusters() {
			if ( this.clusterTimer ) window.clearTimeout( this.clusterTimer );
			this.clusterTimer = window.setTimeout( () => {
				this.clusterTimer = null;
				this.rebuildClusters();
			}, 50 );
		}

		clearClusters() {
			this.clusters.forEach( ( cluster ) => {
				if ( cluster.info ) cluster.info.close();
				cluster.marker.map = null;
			} );
			this.clusters.clear();
			this.clusterByLocation.clear();
		}

		clusterLabel( count ) {
			const strings = this.payload.strings || {};
			const template = strings.cluster_locations || '%d locations';
			return template.replace( '%d', String( count ) );
		}

		clusterElement( count ) {
			const shell = document.createElement( 'span' );
			shell.className = 'vml-map-cluster vml-google-cluster';
			shell.setAttribute( 'aria-hidden', 'true' );
			const label = document.createElement( 'span' );
			label.textContent = String( count );
			shell.appendChild( label );
			return shell;
		}

		rebuildClusters() {
			if ( ! this.map || ! this.AdvancedMarkerElement ) return;
			this.clearClusters();
			const useClusters = this.clusteringActive();
			const zoom = Math.round( Number( this.map.getZoom() || 2 ) );
			const groups = new Map();

			this.markers.forEach( ( entry, id ) => {
				const visible = this.visibleIds.has( id );
				if ( ! visible ) {
					entry.info.close();
					entry.marker.map = null;
					entry.attached = false;
					return;
				}

				if ( ! useClusters || id === this.selectedId ) {
					entry.marker.map = this.map;
					entry.attached = true;
					return;
				}

				const lat = validCoordinate( entry.location.latitude, -90, 90 );
				const lng = validCoordinate( entry.location.longitude, -180, 180 );
				if ( lat === null || lng === null ) return;
				const point = mercatorPixel( lat, lng, zoom );
				const grid = zoom >= 17 ? 34 : 72;
				const key = `${ Math.floor( point.x / grid ) }:${ Math.floor( point.y / grid ) }`;
				if ( ! groups.has( key ) ) groups.set( key, [] );
				groups.get( key ).push( { id, entry, lat, lng } );
			} );

			groups.forEach( ( members, key ) => {
				if ( members.length === 1 ) {
					const entry = members[ 0 ].entry;
					entry.marker.map = this.map;
					entry.attached = true;
					return;
				}

				const lat = members.reduce( ( total, member ) => total + member.lat, 0 ) / members.length;
				const lng = members.reduce( ( total, member ) => total + member.lng, 0 ) / members.length;
				members.forEach( ( member ) => {
					member.entry.info.close();
					member.entry.marker.map = null;
					member.entry.attached = false;
					this.clusterByLocation.set( member.id, key );
				} );
				const marker = new this.AdvancedMarkerElement( {
					map: this.map,
					position: { lat, lng },
					title: this.clusterLabel( members.length ),
					gmpClickable: true,
					zIndex: 500,
				} );
				marker.appendChild( this.clusterElement( members.length ) );
				const cluster = { marker, members, position: { lat, lng }, info: null };
				marker.addListener( 'click', () => this.openCluster( cluster ) );
				this.clusters.set( key, cluster );
			} );
		}

		openCluster( cluster ) {
			if ( ! cluster || ! cluster.members || ! cluster.members.length || ! this.LatLngBounds ) return;
			const bounds = new this.LatLngBounds();
			cluster.members.forEach( ( member ) => bounds.extend( { lat: member.lat, lng: member.lng } ) );
			const northEast = bounds.getNorthEast();
			const southWest = bounds.getSouthWest();
			const samePoint = Math.abs( northEast.lat() - southWest.lat() ) < 1e-9 && Math.abs( northEast.lng() - southWest.lng() ) < 1e-9;
			if ( ! samePoint && Number( this.map.getZoom() || 0 ) < 17 ) {
				this.map.fitBounds( bounds, 56 );
				return;
			}
			this.openClusterChooser( cluster );
		}

		openClusterChooser( cluster ) {
			const chooser = document.createElement( 'div' );
			chooser.className = 'vml-cluster-chooser';
			const heading = document.createElement( 'strong' );
			heading.className = 'vml-cluster-chooser__title';
			heading.textContent = this.clusterLabel( cluster.members.length );
			chooser.appendChild( heading );
			const list = document.createElement( 'div' );
			list.className = 'vml-cluster-chooser__list';
			cluster.members.slice( 0, 50 ).forEach( ( member ) => {
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'vml-cluster-chooser__item';
				button.textContent = member.entry.location.name || '';
				button.addEventListener( 'click', () => {
					if ( this.root.vmlController ) this.root.vmlController.selectLocationById( member.id, { source: 'map', scroll: true } );
					if ( cluster.info ) cluster.info.close();
				} );
				list.appendChild( button );
			} );
			chooser.appendChild( list );
			cluster.info = new this.InfoWindowClass( {
				content: chooser,
				ariaLabel: this.clusterLabel( cluster.members.length ),
				position: cluster.position,
				maxWidth: 340,
			} );
			cluster.info.open( { map: this.map, shouldFocus: true } );
		}

		showFailure() {
			if ( this.runtimeError ) this.runtimeError.hidden = false;
			if ( this.canvas ) this.canvas.classList.add( 'has-runtime-error' );
		}
	}

	function initializeRoot( root ) {
		if ( root.dataset.vmlGoogleInitialized === 'true' ) return;
		const payload = safeJsonParse( root.querySelector( '.vml-locator__data' ) );
		if ( ! payload || ! payload.map_provider || payload.map_provider.engine !== 'google' ) return;
		const canvas = root.querySelector( '[data-vml-map-canvas]' );
		if ( ! canvas ) return;
		root.dataset.vmlGoogleInitialized = 'true';
		const locatorMap = new GoogleLocatorMap( root, payload );
		root.vmlMap = locatorMap;
		const load = () => {
			const privacy = root.querySelector( '[data-vml-map-privacy]' );
			if ( privacy ) privacy.hidden = true;
			canvas.hidden = false;
			locatorMap.init();
		};
		const loadButton = root.querySelector( '[data-vml-load-map]' );
		if ( payload.map_load_mode === 'interaction' && loadButton ) {
			loadButton.addEventListener( 'click', load, { once: true } );
			return;
		}
		if ( 'IntersectionObserver' in window ) {
			const observer = new IntersectionObserver( ( entries ) => {
				if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
					observer.disconnect();
					load();
				}
			}, { rootMargin: '250px 0px' } );
			observer.observe( root );
		} else {
			load();
		}
	}

	function initialize( scope = document ) {
		scope.querySelectorAll( '.vml-locator[data-vml-instance]' ).forEach( initializeRoot );
	}

	window.VelomaloMapGoogle = { initialize, initializeRoot };
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', () => initialize() );
	else initialize();
}() );
