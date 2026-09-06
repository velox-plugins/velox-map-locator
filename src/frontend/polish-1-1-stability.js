/* Velox Map Locator — 1.1 stability refinements. */
( function () {
	'use strict';

	const DAY_NAMES = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];

	function toArray( value ) {
		return Array.isArray( value ) ? value : [];
	}

	function minutes( value ) {
		const match = /^(\d{2}):(\d{2})$/.exec( String( value || '' ) );
		return match ? ( Number( match[ 1 ] ) * 60 ) + Number( match[ 2 ] ) : null;
	}

	function partsInTimezone( timezone, date = new Date() ) {
		if ( ! timezone ) return null;
		try {
			const formatter = new Intl.DateTimeFormat( 'en-CA', {
				timeZone: timezone,
				year: 'numeric', month: '2-digit', day: '2-digit',
				weekday: 'long', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
			} );
			const values = {};
			formatter.formatToParts( date ).forEach( ( part ) => {
				if ( part.type !== 'literal' ) values[ part.type ] = part.value;
			} );
			return {
				year: Number( values.year ),
				month: Number( values.month ),
				day: Number( values.day ),
				hour: Number( values.hour ),
				minute: Number( values.minute ),
			};
		} catch ( error ) {
			return null;
		}
	}

	function dateDescriptor( parts, offset ) {
		const anchor = Date.UTC( parts.year, parts.month - 1, parts.day );
		const shifted = new Date( anchor + ( offset * 86400000 ) );
		const year = shifted.getUTCFullYear();
		const month = String( shifted.getUTCMonth() + 1 ).padStart( 2, '0' );
		const day = String( shifted.getUTCDate() ).padStart( 2, '0' );
		return {
			dateKey: `${ year }-${ month }-${ day }`,
			weekday: DAY_NAMES[ shifted.getUTCDay() ],
		};
	}

	function dayHours( location, descriptor ) {
		const special = toArray( location.special_hours ).find( ( entry ) => entry && entry.date === descriptor.dateKey );
		if ( special ) return special;
		const weekly = location.weekly_hours && typeof location.weekly_hours === 'object' ? location.weekly_hours : {};
		return weekly[ descriptor.weekday ] || null;
	}

	function isOpenNow( location, now = new Date() ) {
		const operational = location.operational || {};
		if ( operational.status && operational.status !== 'normal' ) return false;
		const parts = partsInTimezone( location.timezone, now );
		if ( ! parts ) return false;

		const currentMinutes = ( parts.hour * 60 ) + parts.minute;
		const yesterdayHours = dayHours( location, dateDescriptor( parts, -1 ) );
		if ( yesterdayHours && ! yesterdayHours.closed && ! yesterdayHours.all_day ) {
			for ( const interval of toArray( yesterdayHours.intervals ) ) {
				const open = minutes( interval.open );
				const close = minutes( interval.close );
				if ( open !== null && close !== null && close <= open && currentMinutes < close ) return true;
			}
		}

		const todayHours = dayHours( location, dateDescriptor( parts, 0 ) );
		if ( ! todayHours || todayHours.closed ) return false;
		if ( todayHours.all_day ) return true;

		for ( const interval of toArray( todayHours.intervals ) ) {
			const open = minutes( interval.open );
			const close = minutes( interval.close );
			if ( open === null || close === null ) continue;
			if ( close > open && currentMinutes >= open && currentMinutes < close ) return true;
			if ( close <= open && currentMinutes >= open ) return true;
		}
		return false;
	}

	function preferredType( location ) {
		if ( location && location.primary_type && location.primary_type.name ) return location.primary_type;
		return location && Array.isArray( location.types ) && location.types[ 0 ] ? location.types[ 0 ] : null;
	}

	function syncTypeBadges( controller ) {
		controller.locations.forEach( ( location ) => {
			const type = preferredType( location );
			const card = controller.cardById && controller.cardById.get( Number( location.id ) );
			const heading = card && card.querySelector( '.vml-location-card__heading' );
			if ( ! type || ! type.name || ! heading ) return;
			let badge = heading.querySelector( '.vml-location-card__type' );
			if ( ! badge ) {
				badge = document.createElement( 'span' );
				badge.className = 'vml-location-card__type';
				heading.appendChild( badge );
			}
			badge.textContent = type.name;
		} );
	}

	function rebuildLegend( root, controller ) {
		const pane = root.querySelector( '[data-vml-map-pane]' );
		if ( ! pane ) return;
		const existing = pane.querySelector( '[data-vml-map-legend]' );
		if ( existing ) existing.remove();

		const entries = new Map();
		controller.locations.forEach( ( location ) => {
			const type = preferredType( location );
			if ( ! type || ! type.name ) return;
			const color = location.marker && location.marker.color ? location.marker.color : '#2563eb';
			const key = String( type.id || type.slug || type.name );
			if ( ! entries.has( key ) ) entries.set( key, { name: type.name, color } );
		} );
		if ( entries.size < 2 || entries.size > 8 ) return;

		const config = controller.config || controller.payload && controller.payload.config || {};
		const strings = config.presentation && config.presentation.strings || {};
		const legend = document.createElement( 'div' );
		legend.className = 'vml-map-legend';
		legend.dataset.vmlMapLegend = 'true';
		legend.setAttribute( 'aria-label', strings.map_legend || 'Map legend' );
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

	function hardenOpenNow( root, controller ) {
		const button = root.querySelector( '[data-vml-open-now]' );
		if ( ! button || controller.vml11OpenNowStable ) return;
		controller.vml11OpenNowStable = true;

		const previousVisible = controller.visibleLocations.bind( controller );
		controller.visibleLocations = function () {
			const active = Boolean( this.vml11OpenNowActive );
			if ( ! active ) return previousVisible();

			// The first 1.1 layer used the rendered status node. Temporarily disable
			// that wrapper so Open Now also works when Status is not a visible card field.
			this.vml11OpenNowActive = false;
			let visible;
			try {
				visible = previousVisible();
			} finally {
				this.vml11OpenNowActive = true;
			}
			return visible.filter( ( location ) => isOpenNow( location ) );
		};

		const previousResetState = controller.updateResetState.bind( controller );
		controller.updateResetState = function () {
			previousResetState();
			if ( this.vml11OpenNowActive && this.resetButton ) {
				this.resetButton.disabled = false;
				this.resetButton.setAttribute( 'aria-disabled', 'false' );
			}
		};
	}

	function hardenReset( root, controller ) {
		if ( controller.vml11ResetStable ) return;
		controller.vml11ResetStable = true;
		const previousReset = controller.reset.bind( controller );
		controller.reset = function () {
			this.vml11OpenNowActive = false;
			const openNow = root.querySelector( '[data-vml-open-now]' );
			if ( openNow ) {
				openNow.classList.remove( 'is-selected' );
				openNow.setAttribute( 'aria-pressed', 'false' );
			}
			previousReset();
			const near = root.querySelector( '[data-vml-near-me]' );
			if ( near ) {
				near.classList.remove( 'is-selected' );
				near.setAttribute( 'aria-pressed', 'false' );
			}
		};
	}

	function enhanceRoot( root ) {
		if ( ! root || root.dataset.vml11Stable === 'true' ) return;
		const controller = root.vmlController;
		if ( ! controller || root.dataset.vml11Enhanced !== 'true' ) {
			window.setTimeout( () => enhanceRoot( root ), 40 );
			return;
		}
		root.dataset.vml11Stable = 'true';
		syncTypeBadges( controller );
		rebuildLegend( root, controller );
		hardenOpenNow( root, controller );
		hardenReset( root, controller );
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
