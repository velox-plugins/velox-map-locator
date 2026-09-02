/* Velox Map Locator — Leaflet map adapter. */
( function () {
	'use strict';

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

	function markerElement( location, selected ) {
		const marker = location.marker || {};
		const size = markerSize( marker );
		const shell = document.createElement( 'span' );
		shell.className = `vml-map-marker${ selected ? ' is-selected' : '' }`;
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

	class LeafletLocatorMap {
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
			this.tileLoads = 0;
			this.tileErrors = 0;
			this.ready = false;
		}

		init() {
			if ( this.ready || ! this.canvas || ! window.L || ! this.provider.tile_url ) return;
			try {
				this.canvas.hidden = false;
				this.map = window.L.map( this.canvas, {
					zoomControl: this.mapConfig.zoom_controls !== false,
					scrollWheelZoom: this.mapConfig.scroll_zoom === true,
					keyboard: true,
					attributionControl: true,
				} );

				const tileOptions = {
					attribution: this.provider.attribution || '',
					minZoom: Number( this.provider.min_zoom ?? 0 ),
					maxZoom: Number( this.provider.max_zoom ?? 19 ),
					tms: this.provider.tms === true,
					detectRetina: this.provider.detect_retina === true,
				};
				if ( Array.isArray( this.provider.subdomains ) && this.provider.subdomains.length ) {
					tileOptions.subdomains = this.provider.subdomains;
				}
				this.tiles = window.L.tileLayer( this.provider.tile_url, tileOptions ).addTo( this.map );
				this.tiles.on( 'tileload', () => { this.tileLoads += 1; } );
				this.tiles.on( 'tileerror', () => {
					this.tileErrors += 1;
					if ( this.tileLoads === 0 && this.tileErrors >= 6 ) this.showFailure();
				} );

				( this.payload.locations || [] ).forEach( ( location ) => this.addMarker( location ) );
				this.bind();
				this.addFullscreenControl();
				this.addUtilityControls();
				this.addScaleControl();
				this.applyInitialView();
				this.ready = true;
				this.root.classList.add( 'vml-map-ready' );
				this.rebuildClusters();
				window.setTimeout( () => this.map && this.map.invalidateSize(), 60 );

				const selected = this.root.querySelector( '.vml-location-card.is-selected' );
				if ( selected ) this.selectMarker( Number( selected.dataset.vmlLocationId || 0 ), false );
			} catch ( error ) {
				this.showFailure();
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
			tools.className = 'vml-map-tools vml-map-tools--leaflet';
			tools.dataset.vmlMapTools = 'true';
			if ( this.mapConfig.zoom_controls !== false ) tools.classList.add( 'is-below-zoom' );
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
				fit.addEventListener( 'click', () => this.fitVisible( true ) );
				tools.appendChild( fit );
			}
			if ( showZoomLevel ) {
				const zoom = document.createElement( 'span' );
				zoom.className = 'vml-map-zoom-level';
				zoom.dataset.vmlZoomLevel = 'true';
				tools.appendChild( zoom );
				this.zoomLevelNode = zoom;
				this.map.on( 'zoomend', () => this.updateZoomLevel() );
			}
			pane.appendChild( tools );
			this.updateZoomLevel();
		}

		addScaleControl() {
			if ( this.mapConfig.scale_control === false || ! window.L || ! window.L.control || ! window.L.control.scale ) return;
			const unit = this.config.behaviour && this.config.behaviour.distance_unit || 'auto';
			window.L.control.scale( {
				position: 'bottomleft',
				maxWidth: 110,
				metric: unit !== 'miles',
				imperial: unit !== 'kilometres',
				updateWhenIdle: true,
			} ).addTo( this.map );
		}

		updateZoomLevel() {
			if ( ! this.zoomLevelNode || ! this.map ) return;
			const zoom = Math.round( Number( this.map.getZoom() || 0 ) * 10 ) / 10;
			this.zoomLevelNode.textContent = `Z${ zoom }`;
			const template = this.payload.strings && this.payload.strings.map_zoom_level || 'Zoom level %d';
			this.zoomLevelNode.setAttribute( 'aria-label', template.replace( '%d', String( zoom ) ) );
		}


		addFullscreenControl() {
			if ( this.mapConfig.fullscreen === false || ! document.fullscreenEnabled ) return;
			const pane = this.root.querySelector( '[data-vml-map-pane]' );
			if ( ! pane || pane.querySelector( '[data-vml-fullscreen]' ) ) return;
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'vml-map-fullscreen';
			button.dataset.vmlFullscreen = 'true';
			const strings = this.payload.strings || {};
			button.setAttribute( 'aria-label', strings.map_fullscreen_label || 'Toggle fullscreen map' );
			button.title = strings.map_fullscreen_label || 'Toggle fullscreen map';
			button.innerHTML = '<svg class="vml-map-fullscreen__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><path d="M8 3H3v5"/><path d="M16 3h5v5"/><path d="M21 16v5h-5"/><path d="M3 16v5h5"/></svg>';
			button.addEventListener( 'click', () => {
				if ( document.fullscreenElement === pane ) document.exitFullscreen();
				else pane.requestFullscreen().catch( () => {} );
			} );
			this.fullscreenHandler = () => { if ( this.map ) window.setTimeout( () => this.map.invalidateSize(), 40 ); };
			document.addEventListener( 'fullscreenchange', this.fullscreenHandler );
			pane.appendChild( button );
		}

		applyInitialView() {
			if ( this.mapConfig.initial_view === 'fixed' ) {
				const lat = validCoordinate( this.mapConfig.fixed_latitude, -90, 90 );
				const lng = validCoordinate( this.mapConfig.fixed_longitude, -180, 180 );
				if ( lat !== null && lng !== null ) {
					this.map.setView( [ lat, lng ], Number( this.mapConfig.fixed_zoom || 10 ), { animate: false } );
					return;
				}
			}
			this.fitAllLocations( false );
		}

		fitAllLocations( animated ) {
			const points = [];
			this.markers.forEach( ( entry ) => points.push( entry.marker.getLatLng() ) );
			if ( ! points.length ) return;
			const reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			if ( points.length === 1 ) {
				this.map.setView( points[ 0 ], Number( this.mapConfig.single_location_zoom || 14 ), { animate: animated && ! reduced } );
				return;
			}
			this.map.fitBounds( window.L.latLngBounds( points ), { padding: [ 44, 44 ], maxZoom: 16, animate: animated && ! reduced } );
		}

		destroy() {
			if ( this.clusterTimer ) window.clearTimeout( this.clusterTimer );
			if ( this.fullscreenHandler ) document.removeEventListener( 'fullscreenchange', this.fullscreenHandler );
			this.clearClusters();
			try { if ( this.map ) this.map.remove(); } catch ( error ) {}
			this.markers.clear();
			this.clusterByLocation.clear();
			this.ready = false;
		}

		addMarker( location ) {
			const lat = validCoordinate( location.latitude, -90, 90 );
			const lng = validCoordinate( location.longitude, -180, 180 );
			if ( lat === null || lng === null ) return;
			const id = Number( location.id );
			const icon = this.iconFor( location, false );
			const marker = window.L.marker( [ lat, lng ], {
				icon,
				title: location.name || '',
				keyboard: true,
				riseOnHover: true,
			} );
			marker.bindPopup( popupElement( location, this.root ), { className: 'vml-leaflet-popup', maxWidth: 340, minWidth: 240 } );
			marker.on( 'click', () => {
				if ( this.root.vmlController ) this.root.vmlController.selectLocationById( id, { source: 'map', scroll: true } );
			} );
			const attachImmediately = ! this.clusteringActive();
			if ( attachImmediately ) marker.addTo( this.map );
			this.markers.set( id, { marker, location, attached: attachImmediately } );
		}

		iconFor( location, selected ) {
			const size = markerSize( location.marker || {} );
			return window.L.divIcon( {
				className: 'vml-leaflet-div-icon',
				html: markerElement( location, selected ),
				iconSize: [ size, size + Math.round( size * 0.28 ) ],
				iconAnchor: [ size / 2, size + Math.round( size * 0.28 ) ],
				popupAnchor: [ 0, -size ],
			} );
		}

		bind() {
			this.map.on( 'zoomend', () => this.scheduleClusters() );
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
				previous.marker.setIcon( this.iconFor( previous.location, false ) );
				previous.marker.setZIndexOffset( 0 );
			}
			this.selectedId = id;
			this.rebuildClusters();
			const current = this.markers.get( id );
			current.marker.setIcon( this.iconFor( current.location, true ) );
			current.marker.setZIndexOffset( 1000 );
			if ( moveMap && this.config.behaviour && this.config.behaviour.pan_on_select !== false ) {
				const latlng = current.marker.getLatLng();
				if ( ! this.map.getBounds().pad( -0.15 ).contains( latlng ) ) this.map.panTo( latlng );
			}
			if ( this.config.behaviour && this.config.behaviour.open_popup_on_select !== false ) current.marker.openPopup();
		}

		setVisible( ids ) {
			this.visibleIds = new Set( ( ids || [] ).map( Number ) );
			if ( this.selectedId && ! this.visibleIds.has( this.selectedId ) && this.markers.has( this.selectedId ) ) {
				const previous = this.markers.get( this.selectedId );
				previous.marker.setIcon( this.iconFor( previous.location, false ) );
				previous.marker.setZIndexOffset( 0 );
				previous.marker.closePopup();
				this.selectedId = null;
			}
			this.rebuildClusters();
			if ( this.mapConfig.refit_on_filter !== false ) this.fitVisible( true );
		}

		fitVisible( animated ) {
			const points = [];
			this.markers.forEach( ( entry, id ) => {
				if ( this.visibleIds.has( id ) ) points.push( entry.marker.getLatLng() );
			} );
			if ( ! points.length ) return;
			const reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			if ( points.length === 1 ) {
				this.map.setView( points[ 0 ], Number( this.mapConfig.single_location_zoom || 14 ), { animate: animated && ! reduced } );
				return;
			}
			this.map.fitBounds( window.L.latLngBounds( points ), { padding: [ 44, 44 ], maxZoom: 16, animate: animated && ! reduced } );
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
				const point = entry.marker.getLatLng();
				const key = `${ Number( point.lat ).toFixed( 7 ) }:${ Number( point.lng ).toFixed( 7 ) }`;
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
			}, 40 );
		}

		clearClusters() {
			this.clusters.forEach( ( cluster ) => {
				try { cluster.marker.removeFrom( this.map ); } catch ( error ) {}
			} );
			this.clusters.clear();
			this.clusterByLocation.clear();
		}

		clusterLabel( count ) {
			const strings = this.payload.strings || {};
			const template = strings.cluster_locations || '%d locations';
			return template.replace( '%d', String( count ) );
		}

		clusterIcon( count ) {
			const label = this.clusterLabel( count );
			return window.L.divIcon( {
				className: 'vml-leaflet-div-icon vml-leaflet-cluster-icon',
				html: `<span class="vml-map-cluster" aria-hidden="true"><span>${ Number( count ) }</span></span>`,
				iconSize: [ 42, 42 ],
				iconAnchor: [ 21, 21 ],
				tooltipAnchor: [ 0, -24 ],
				ariaLabel: label,
			} );
		}

		rebuildClusters() {
			if ( ! this.map ) return;
			this.clearClusters();
			const useClusters = this.clusteringActive();
			const zoom = Number.isFinite( this.map.getZoom() ) ? this.map.getZoom() : 2;
			const groups = new Map();

			this.markers.forEach( ( entry, id ) => {
				const visible = this.visibleIds.has( id );
				if ( ! visible ) {
					if ( entry.attached ) entry.marker.removeFrom( this.map );
					entry.attached = false;
					return;
				}

				if ( ! useClusters || id === this.selectedId ) {
					if ( ! entry.attached ) entry.marker.addTo( this.map );
					entry.attached = true;
					return;
				}

				const point = this.map.project( entry.marker.getLatLng(), zoom );
				const grid = zoom >= 17 ? 34 : 72;
				const key = `${ Math.floor( point.x / grid ) }:${ Math.floor( point.y / grid ) }`;
				if ( ! groups.has( key ) ) groups.set( key, [] );
				groups.get( key ).push( { id, entry } );
			} );

			groups.forEach( ( members, key ) => {
				if ( members.length === 1 ) {
					const entry = members[ 0 ].entry;
					if ( ! entry.attached ) entry.marker.addTo( this.map );
					entry.attached = true;
					return;
				}

				const points = members.map( ( member ) => member.entry.marker.getLatLng() );
				const lat = points.reduce( ( total, point ) => total + point.lat, 0 ) / points.length;
				const lng = points.reduce( ( total, point ) => total + point.lng, 0 ) / points.length;
				members.forEach( ( member ) => {
					if ( member.entry.attached ) member.entry.marker.removeFrom( this.map );
					member.entry.attached = false;
					this.clusterByLocation.set( member.id, key );
				} );
				const marker = window.L.marker( [ lat, lng ], {
					icon: this.clusterIcon( members.length ),
					title: this.clusterLabel( members.length ),
					alt: this.clusterLabel( members.length ),
					keyboard: true,
					riseOnHover: true,
					zIndexOffset: 500,
				} ).addTo( this.map );
				const cluster = { marker, members, points };
				marker.on( 'click', () => this.openCluster( cluster ) );
				this.clusters.set( key, cluster );
			} );
		}

		openCluster( cluster ) {
			if ( ! cluster || ! cluster.points || ! cluster.points.length ) return;
			const bounds = window.L.latLngBounds( cluster.points );
			const samePoint = bounds.getSouthWest().equals( bounds.getNorthEast(), 1e-9 );
			if ( ! samePoint && this.map.getZoom() < 17 ) {
				this.map.fitBounds( bounds, { padding: [ 56, 56 ], maxZoom: Math.min( 17, this.map.getZoom() + 3 ) } );
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
					this.map.closePopup();
				} );
				list.appendChild( button );
			} );
			chooser.appendChild( list );
			window.L.popup( { className: 'vml-leaflet-popup', maxWidth: 340, minWidth: 240 } )
				.setLatLng( cluster.marker.getLatLng() )
				.setContent( chooser )
				.openOn( this.map );
		}

		showFailure() {
			if ( this.runtimeError ) this.runtimeError.hidden = false;
			if ( this.canvas ) this.canvas.classList.add( 'has-runtime-error' );
		}
	}

	function initializeRoot( root ) {
		if ( root.dataset.vmlLeafletInitialized === 'true' ) return;
		const payload = safeJsonParse( root.querySelector( '.vml-locator__data' ) );
		if ( ! payload || ! payload.map_provider || payload.map_provider.engine !== 'leaflet' ) return;
		const canvas = root.querySelector( '[data-vml-map-canvas]' );
		if ( ! canvas ) return;
		root.dataset.vmlLeafletInitialized = 'true';
		const locatorMap = new LeafletLocatorMap( root, payload );
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

	window.VelomaloMapLeaflet = { initialize, initializeRoot };
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', () => initialize() );
	else initialize();
}() );
