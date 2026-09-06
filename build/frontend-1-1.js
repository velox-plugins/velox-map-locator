/* Velox Map Locator — 1.1 frontend polish layer. */
( function () {
	'use strict';

	function normalize( value ) {
		return String( value || '' )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLocaleLowerCase()
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function formatTemplate( template, value ) {
		return String( template || '' ).replace( /%1\$[sd]|%[sd]/, String( value ) );
	}

	function locationCountLabel( count, strings ) {
		if ( count === 1 ) return strings.location_one || '1 location';
		return formatTemplate( strings.locations_many || '%d locations', count );
	}

	function parseHex( value ) {
		const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec( String( value || '' ).trim() );
		if ( ! match ) return null;
		let hex = match[ 1 ];
		if ( hex.length === 3 ) hex = hex.split( '' ).map( ( part ) => part + part ).join( '' );
		return {
			r: parseInt( hex.slice( 0, 2 ), 16 ),
			g: parseInt( hex.slice( 2, 4 ), 16 ),
			b: parseInt( hex.slice( 4, 6 ), 16 ),
		};
	}

	function luminance( rgb ) {
		const channel = ( value ) => {
			const normalized = value / 255;
			return normalized <= 0.03928 ? normalized / 12.92 : Math.pow( ( normalized + 0.055 ) / 1.055, 2.4 );
		};
		return ( 0.2126 * channel( rgb.r ) ) + ( 0.7152 * channel( rgb.g ) ) + ( 0.0722 * channel( rgb.b ) );
	}

	function contrastRatio( first, second ) {
		const light = Math.max( first, second );
		const dark = Math.min( first, second );
		return ( light + 0.05 ) / ( dark + 0.05 );
	}

	function applyAppearance( root, config ) {
		const appearance = config.appearance || {};
		const radius = Math.max( 0, Math.min( 24, Number( appearance.radius ?? 10 ) ) );
		root.style.setProperty( '--vml-radius', `${ radius }px` );

		const shadows = {
			none: 'none',
			soft: '0 6px 18px rgb(16 24 40 / 6%)',
			medium: '0 10px 28px rgb(16 24 40 / 12%)',
		};
		root.style.setProperty( '--vml-shadow', shadows[ appearance.shadow ] || shadows.soft );

		const accent = parseHex( appearance.accent );
		if ( accent ) {
			const accentLuminance = luminance( accent );
			const whiteContrast = contrastRatio( accentLuminance, 1 );
			const darkContrast = contrastRatio( accentLuminance, luminance( { r: 17, g: 24, b: 39 } ) );
			root.style.setProperty( '--vml-accent-contrast', whiteContrast >= darkContrast ? '#ffffff' : '#111827' );
		}
	}

	function enhancePrivacyGate( root, config ) {
		const gate = root.querySelector( '[data-vml-map-privacy]' );
		if ( ! gate ) return;
		const presentation = config.presentation || {};
		const strings = presentation.strings || {};
		const title = gate.querySelector( 'strong' );
		const body = gate.querySelector( 'span:not(.vml-map-state__icon)' );
		const button = gate.querySelector( '[data-vml-load-map]' );
		if ( title ) title.textContent = strings.privacy_title || 'Map not loaded yet';
		if ( body ) body.textContent = strings.privacy_body || 'The location directory works without contacting the map provider. No map request has been made yet.';
		if ( button ) button.textContent = strings.load_map || 'Load interactive map';

		let providerNote = gate.querySelector( '[data-vml-privacy-provider]' );
		if ( ! providerNote ) {
			providerNote = document.createElement( 'span' );
			providerNote.className = 'vml-map-state__provider';
			providerNote.dataset.vmlPrivacyProvider = 'true';
			if ( button ) gate.insertBefore( providerNote, button );
			else gate.appendChild( providerNote );
		}
		const providerName = presentation.provider_name || 'the configured map provider';
		providerNote.textContent = formatTemplate( strings.privacy_provider || 'Loading this map will connect your browser to %s.', providerName );
		if ( presentation.provider_host ) providerNote.title = presentation.provider_host;
	}

	function enhanceCards( root, controller ) {
		controller.locations.forEach( ( location ) => {
			const card = controller.cardById && controller.cardById.get( Number( location.id ) );
			if ( ! card ) return;

			if ( location.postal_code ) {
				location._search = `${ location._search || '' } ${ normalize( location.postal_code ) }`.trim();
			}

			const type = Array.isArray( location.types ) && location.types[ 0 ] ? location.types[ 0 ] : null;
			const heading = card.querySelector( '.vml-location-card__heading' );
			if ( type && type.name && heading && ! heading.querySelector( '.vml-location-card__type' ) ) {
				const badge = document.createElement( 'span' );
				badge.className = 'vml-location-card__type';
				badge.textContent = type.name;
				heading.appendChild( badge );
			}
		} );
	}

	function enhanceResultCount( controller, config ) {
		if ( controller.vml11ResultCountEnhanced ) return;
		controller.vml11ResultCountEnhanced = true;
		const original = controller.updateResultCount.bind( controller );
		const presentation = config.presentation || {};
		const strings = presentation.strings || {};

		controller.updateResultCount = function ( count ) {
			original( count );
			if ( ! this.state.distances ) return;
			const label = locationCountLabel( count, strings );
			const sorted = formatTemplate( strings.sorted_by_distance || '%s sorted by distance', label );
			if ( this.resultCount ) this.resultCount.textContent = sorted;
		};
	}

	function syncNearMeState( button, controller ) {
		const active = Boolean( controller.state && controller.state.distances );
		button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		button.classList.toggle( 'is-selected', active );
	}

	function enhanceNearMe( root, controller, config ) {
		const button = root.querySelector( '[data-vml-near-me]' );
		if ( ! button || controller.vml11NearMeEnhanced ) return;
		controller.vml11NearMeEnhanced = true;
		button.setAttribute( 'aria-pressed', 'false' );

		const presentation = config.presentation || {};
		if ( controller.config && controller.config.behaviour && controller.config.behaviour.distance_unit === 'auto' && presentation.auto_distance_unit ) {
			controller.config.behaviour.distance_unit = presentation.auto_distance_unit;
		}

		const original = controller.nearMe.bind( controller );
		controller.nearMe = function ( nearButton ) {
			if ( this.state && this.state.distances ) {
				this.state.distances = null;
				this.root.querySelectorAll( '[data-vml-distance]' ).forEach( ( item ) => {
					item.hidden = true;
					item.textContent = '';
				} );
				this.apply();
				syncNearMeState( nearButton, this );
				return;
			}

			original( nearButton );
			let checks = 0;
			const sync = () => {
				checks += 1;
				syncNearMeState( nearButton, this );
				if ( nearButton.disabled && checks < 220 ) window.setTimeout( sync, 50 );
			};
			window.setTimeout( sync, 0 );
		};

		syncNearMeState( button, controller );
	}

	function enhanceOpenNow( root, controller, config ) {
		if ( controller.vml11OpenNowEnhanced ) return;
		const supportsHours = controller.locations.some( ( location ) => location && location.timezone && location.weekly_hours && Object.keys( location.weekly_hours ).length );
		if ( ! supportsHours ) return;
		const controls = root.querySelector( '.vml-locator__controls' );
		if ( ! controls ) return;
		controller.vml11OpenNowEnhanced = true;
		controller.vml11OpenNowActive = false;

		const originalVisible = controller.visibleLocations.bind( controller );
		controller.visibleLocations = function () {
			const visible = originalVisible();
			if ( ! this.vml11OpenNowActive ) return visible;
			return visible.filter( ( location ) => {
				const card = this.cardById && this.cardById.get( Number( location.id ) );
				const status = card && card.querySelector( '[data-vml-live-status]' );
				return Boolean( status && ! status.hidden && status.classList.contains( 'is-success' ) );
			} );
		};

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'vml-locator__open-now';
		button.dataset.vmlOpenNow = 'true';
		button.setAttribute( 'aria-pressed', 'false' );
		button.textContent = ( config.presentation && config.presentation.strings && config.presentation.strings.open_now ) || 'Open now';
		button.addEventListener( 'click', () => {
			controller.vml11OpenNowActive = ! controller.vml11OpenNowActive;
			button.classList.toggle( 'is-selected', controller.vml11OpenNowActive );
			button.setAttribute( 'aria-pressed', controller.vml11OpenNowActive ? 'true' : 'false' );
			controller.apply();
		} );

		const near = controls.querySelector( '[data-vml-near-me]' );
		if ( near ) controls.insertBefore( button, near );
		else controls.appendChild( button );

		const originalStatuses = controller.updateLiveStatuses.bind( controller );
		controller.updateLiveStatuses = function () {
			originalStatuses();
			if ( this.vml11OpenNowActive ) this.apply();
		};
	}

	function enhanceFullscreenIcon( root ) {
		const apply = () => {
			root.querySelectorAll( '.vml-map-fullscreen' ).forEach( ( button ) => {
				if ( button.dataset.vml11Icon === 'true' ) return;
				button.dataset.vml11Icon = 'true';
				button.innerHTML = '<svg class="vml-map-fullscreen__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 4H4v5"/><path d="m4 4 6 6"/><path d="M15 4h5v5"/><path d="m20 4-6 6"/><path d="M9 20H4v-5"/><path d="m4 20 6-6"/><path d="M15 20h5v-5"/><path d="m20 20-6-6"/></svg>';
			} );
		};
		apply();
		window.setTimeout( apply, 100 );
		window.setTimeout( apply, 500 );
	}

	function buildLegend( root, controller ) {
		const pane = root.querySelector( '[data-vml-map-pane]' );
		if ( ! pane || pane.querySelector( '[data-vml-map-legend]' ) ) return;
		const entries = new Map();
		controller.locations.forEach( ( location ) => {
			const type = Array.isArray( location.types ) && location.types[ 0 ] ? location.types[ 0 ] : null;
			if ( ! type || ! type.name ) return;
			const color = location.marker && location.marker.color ? location.marker.color : '#2563eb';
			const key = String( type.id || type.slug || type.name );
			if ( ! entries.has( key ) ) entries.set( key, { name: type.name, color } );
		} );
		if ( entries.size < 2 || entries.size > 8 ) return;

		const legend = document.createElement( 'div' );
		legend.className = 'vml-map-legend';
		legend.dataset.vmlMapLegend = 'true';
		legend.setAttribute( 'aria-label', 'Map legend' );
		entries.forEach( ( entry ) => {
			const item = document.createElement( 'span' );
			item.className = 'vml-map-legend__item';
			const swatch = document.createElement( 'span' );
			swatch.className = 'vml-map-legend__swatch';
			swatch.style.backgroundColor = entry.color;
			swatch.setAttribute( 'aria-hidden', 'true' );
			const label = document.createElement( 'span' );
			label.textContent = entry.name;
			item.appendChild( swatch );
			item.appendChild( label );
			legend.appendChild( item );
		} );
		pane.appendChild( legend );
	}

	function enhanceKeyboardPopupFocus( root ) {
		root.addEventListener( 'vml:location-selected', ( event ) => {
			if ( ! event.detail || event.detail.source !== 'keyboard' ) return;
			window.setTimeout( () => {
				const popup = root.querySelector( '.leaflet-popup-content, .gm-style-iw-d' );
				if ( ! popup ) return;
				const focusable = popup.querySelector( 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])' );
				if ( focusable ) focusable.focus( { preventScroll: true } );
				else {
					popup.setAttribute( 'tabindex', '-1' );
					popup.focus( { preventScroll: true } );
				}
			}, 80 );
		} );
	}

	function enhanceRoot( root ) {
		if ( ! root || root.dataset.vml11Enhanced === 'true' ) return;
		const controller = root.vmlController;
		if ( ! controller || ! controller.payload ) {
			window.setTimeout( () => enhanceRoot( root ), 30 );
			return;
		}
		root.dataset.vml11Enhanced = 'true';
		const config = controller.config || controller.payload.config || {};
		applyAppearance( root, config );
		enhancePrivacyGate( root, config );
		enhanceCards( root, controller );
		enhanceResultCount( controller, config );
		enhanceNearMe( root, controller, config );
		enhanceOpenNow( root, controller, config );
		enhanceFullscreenIcon( root );
		buildLegend( root, controller );
		enhanceKeyboardPopupFocus( root );
		controller.apply();
	}

	function enhanceAll( scope = document ) {
		scope.querySelectorAll( '.vml-locator[data-vml-instance]' ).forEach( enhanceRoot );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', () => enhanceAll() );
	else enhanceAll();

	const observer = new MutationObserver( ( mutations ) => {
		for ( const mutation of mutations ) {
			for ( const node of mutation.addedNodes ) {
				if ( ! node || node.nodeType !== 1 ) continue;
				if ( node.matches && node.matches( '.vml-locator[data-vml-instance]' ) ) enhanceRoot( node );
				else if ( node.querySelectorAll ) enhanceAll( node );
			}
		}
	} );
	observer.observe( document.documentElement, { childList: true, subtree: true } );
}() );
