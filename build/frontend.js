( function () {
	'use strict';

	const DAY_NAMES = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];
	const CLOSING_SOON_MINUTES = 60;

	function normalize( value ) {
		return String( value || '' )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLocaleLowerCase()
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function toArray( value ) {
		return Array.isArray( value ) ? value : [];
	}

	function formatTemplate( template, ...values ) {
		let output = String( template || '' );
		values.forEach( ( value, index ) => {
			const numbered = new RegExp( `%${ index + 1 }\\$[sd]` );
			if ( numbered.test( output ) ) output = output.replace( numbered, String( value ) );
			else output = output.replace( /%[sd]/, String( value ) );
		} );
		return output;
	}

	function safeJsonParse( node ) {
		if ( ! node ) {
			return null;
		}
		try {
			return JSON.parse( node.textContent || '{}' );
		} catch ( error ) {
			return null;
		}
	}

	function haversineDistanceKm( firstLat, firstLng, secondLat, secondLng ) {
		const radius = 6371;
		const toRadians = ( value ) => value * Math.PI / 180;
		const deltaLat = toRadians( secondLat - firstLat );
		const deltaLng = toRadians( secondLng - firstLng );
		const a = Math.sin( deltaLat / 2 ) ** 2 + Math.cos( toRadians( firstLat ) ) * Math.cos( toRadians( secondLat ) ) * Math.sin( deltaLng / 2 ) ** 2;
		return radius * 2 * Math.atan2( Math.sqrt( a ), Math.sqrt( 1 - a ) );
	}

	function localeUsesMiles() {
		const locale = ( navigator.language || '' ).toUpperCase();
		return /(?:-|_)(US|LR|MM)(?:$|-|_)/.test( locale );
	}

	function distanceUnit( configured ) {
		if ( configured === 'miles' ) {
			return 'miles';
		}
		if ( configured === 'kilometres' ) {
			return 'kilometres';
		}
		return localeUsesMiles() ? 'miles' : 'kilometres';
	}

	function formatDistance( kilometres, configured, strings = {} ) {
		const unit = distanceUnit( configured );
		const value = unit === 'miles' ? kilometres * 0.621371 : kilometres;
		const digits = value < 10 ? 1 : 0;
		return `${ new Intl.NumberFormat( undefined, { maximumFractionDigits: digits } ).format( value ) } ${ unit === 'miles' ? ( strings.miles_abbr || 'mi' ) : ( strings.kilometres_abbr || 'km' ) }`;
	}

	function partsInTimezone( timezone, date = new Date() ) {
		if ( ! timezone ) {
			return null;
		}
		try {
			const formatter = new Intl.DateTimeFormat( 'en-CA', {
				timeZone: timezone,
				year: 'numeric', month: '2-digit', day: '2-digit',
				weekday: 'long', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
			} );
			const values = {};
			formatter.formatToParts( date ).forEach( ( part ) => {
				if ( part.type !== 'literal' ) {
					values[ part.type ] = part.value;
				}
			} );
			return {
				year: Number( values.year ),
				month: Number( values.month ),
				day: Number( values.day ),
				weekday: String( values.weekday || '' ).toLocaleLowerCase(),
				hour: Number( values.hour ),
				minute: Number( values.minute ),
				dateKey: `${ values.year }-${ values.month }-${ values.day }`,
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

	function minutes( value ) {
		const match = /^(\d{2}):(\d{2})$/.exec( String( value || '' ) );
		return match ? ( Number( match[ 1 ] ) * 60 ) + Number( match[ 2 ] ) : null;
	}

	function dayHours( location, descriptor ) {
		const special = toArray( location.special_hours ).find( ( entry ) => entry && entry.date === descriptor.dateKey );
		if ( special ) {
			return special;
		}
		const weekly = location.weekly_hours && typeof location.weekly_hours === 'object' ? location.weekly_hours : {};
		return weekly[ descriptor.weekday ] || null;
	}

	function formatClock( value ) {
		const parsed = minutes( value );
		if ( parsed === null ) {
			return value || '';
		}
		const date = new Date( Date.UTC( 2000, 0, 1, Math.floor( parsed / 60 ), parsed % 60 ) );
		return new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit', timeZone: 'UTC' } ).format( date );
	}

	function liveHoursStatus( location, strings = {}, now = new Date() ) {
		const operational = location.operational || {};
		if ( operational.status && operational.status !== 'normal' ) {
			return {
				state: operational.status === 'coming_soon' ? 'info' : 'warning',
				label: operational.label || ( operational.status === 'coming_soon' ? ( strings.coming_soon || 'Coming Soon' ) : ( strings.temporarily_closed || 'Temporarily Closed' ) ),
				locked: true,
			};
		}

		const hasWeekly = location.weekly_hours && typeof location.weekly_hours === 'object' && Object.keys( location.weekly_hours ).length > 0;
		const hasSpecial = Array.isArray( location.special_hours ) && location.special_hours.length > 0;
		if ( ! location.timezone || ( ! hasWeekly && ! hasSpecial ) ) {
			return null;
		}

		const parts = partsInTimezone( location.timezone, now );
		if ( ! parts ) {
			return null;
		}
		const currentMinutes = ( parts.hour * 60 ) + parts.minute;
		const today = dateDescriptor( parts, 0 );
		const yesterday = dateDescriptor( parts, -1 );
		const todayHours = dayHours( location, today );
		const yesterdayHours = dayHours( location, yesterday );

		if ( yesterdayHours && ! yesterdayHours.closed && ! yesterdayHours.all_day ) {
			for ( const interval of toArray( yesterdayHours.intervals ) ) {
				const open = minutes( interval.open );
				const close = minutes( interval.close );
				if ( open !== null && close !== null && close <= open && currentMinutes < close ) {
					const remaining = close - currentMinutes;
					return remaining <= CLOSING_SOON_MINUTES
						? { state: 'warning', label: formatTemplate( strings.closing_soon || 'Closing soon · %s', formatClock( interval.close ) ) }
						: { state: 'success', label: formatTemplate( strings.open_closes || 'Open · Closes %s', formatClock( interval.close ) ) };
				}
			}
		}

		if ( todayHours && todayHours.all_day ) {
			return { state: 'success', label: strings.open_24 || 'Open 24 Hours' };
		}
		if ( todayHours && ! todayHours.closed ) {
			for ( const interval of toArray( todayHours.intervals ) ) {
				const open = minutes( interval.open );
				const close = minutes( interval.close );
				if ( open === null || close === null ) {
					continue;
				}
				if ( close > open && currentMinutes >= open && currentMinutes < close ) {
					const remaining = close - currentMinutes;
					return remaining <= CLOSING_SOON_MINUTES
						? { state: 'warning', label: formatTemplate( strings.closing_soon || 'Closing soon · %s', formatClock( interval.close ) ) }
						: { state: 'success', label: formatTemplate( strings.open_closes || 'Open · Closes %s', formatClock( interval.close ) ) };
				}
				if ( close <= open && currentMinutes >= open ) {
					const remaining = ( 24 * 60 - currentMinutes ) + close;
					return remaining <= CLOSING_SOON_MINUTES
						? { state: 'warning', label: formatTemplate( strings.closing_soon || 'Closing soon · %s', formatClock( interval.close ) ) }
						: { state: 'success', label: formatTemplate( strings.open_closes || 'Open · Closes %s', formatClock( interval.close ) ) };
				}
			}
		}

		for ( let offset = 0; offset <= 7; offset += 1 ) {
			const descriptor = dateDescriptor( parts, offset );
			const candidate = dayHours( location, descriptor );
			if ( ! candidate || candidate.closed ) {
				continue;
			}
			if ( candidate.all_day ) {
				if ( offset === 0 ) {
					return { state: 'success', label: strings.open_24 || 'Open 24 Hours' };
				}
				return { state: 'neutral', label: offset === 1 ? ( strings.closed_opens_tomorrow_allday || 'Closed · Opens tomorrow' ) : formatTemplate( strings.closed_opens_day_allday || 'Closed · Opens %s', humanWeekday( descriptor.weekday, strings ) ) };
			}
			for ( const interval of toArray( candidate.intervals ) ) {
				const open = minutes( interval.open );
				if ( open === null || ( offset === 0 && open <= currentMinutes ) ) {
					continue;
				}
				const time = formatClock( interval.open );
				if ( offset === 0 ) {
					return { state: 'neutral', label: formatTemplate( strings.closed_opens || 'Closed · Opens %s', time ) };
				}
				if ( offset === 1 ) {
					return { state: 'neutral', label: formatTemplate( strings.closed_opens_tomorrow || 'Closed · Opens tomorrow %s', time ) };
				}
				return { state: 'neutral', label: formatTemplate( strings.closed_opens_day || 'Closed · Opens %1$s %2$s', humanWeekday( descriptor.weekday, strings ), time ) };
			}
		}

		return { state: 'neutral', label: strings.closed || 'Closed' };
	}

	function humanWeekday( day, strings = {} ) {
		return strings.weekdays && strings.weekdays[ day ] ? strings.weekdays[ day ] : ( day ? day.charAt( 0 ).toUpperCase() + day.slice( 1 ) : '' );
	}

	function searchableText( location, fields ) {
		const values = [];
		toArray( fields ).forEach( ( field ) => {
			switch ( field ) {
				case 'name': values.push( location.name ); break;
				case 'address': values.push( location.address ); break;
				case 'city': values.push( location.city ); break;
				case 'region': values.push( location.region ); break;
				case 'country': values.push( location.country_code ); break;
				case 'type': values.push( ...toArray( location.types ).map( ( term ) => term.name ) ); break;
				case 'group': values.push( ...toArray( location.groups ).map( ( term ) => term.name ) ); break;
				case 'description': values.push( location.description ); break;
				case 'extra_fields': values.push( ...toArray( location.extra_fields ).flatMap( ( item ) => [ item.label, item.value ] ) ); break;
			}
		} );
		return normalize( values.filter( Boolean ).join( ' ' ) );
	}

	class LocatorController {
		constructor( root ) {
			this.root = root;
			this.payload = safeJsonParse( root.querySelector( '.vml-locator__data' ) );
			if ( ! this.payload || ! Array.isArray( this.payload.locations ) ) {
				return;
			}
			this.config = this.payload.config || {};
			this.strings = this.payload.strings || {};
			this.locations = this.payload.locations.map( ( location, index ) => ( {
				...location,
				_index: index,
				_search: searchableText( location, this.config.search && this.config.search.fields ),
			} ) );
			this.state = { query: '', filters: {}, distances: null, selectedId: null };
			this.root.vmlController = this;
			this.cardById = new Map();
			this.root.querySelectorAll( '[data-vml-location-id]' ).forEach( ( card ) => this.cardById.set( Number( card.dataset.vmlLocationId ), card ) );
			this.list = root.querySelector( '.vml-locator__list' );
			this.controls = root.querySelector( '.vml-locator__interactive' );
			this.resultCount = root.querySelector( '[data-vml-result-count]' );
			this.resetButton = root.querySelector( '[data-vml-reset]' );
			this.noResults = root.querySelector( '[data-vml-no-results]' );
			this.liveRegion = root.querySelector( '[data-vml-live-region]' );
			this.bind();
			this.updateLiveStatuses();
			if ( this.root.querySelector( '[data-vml-live-status]' ) ) this.statusTimer = window.setInterval( () => this.updateLiveStatuses(), 60000 );
			this.applyDeepLink();
			if ( this.controls ) {
				this.controls.hidden = false;
			}
			this.apply();
		}

		bind() {
			const search = this.root.querySelector( '[data-vml-search]' );
			if ( search ) {
				let timer = 0;
				search.addEventListener( 'input', () => {
					window.clearTimeout( timer );
					timer = window.setTimeout( () => { this.state.query = normalize( search.value ); this.apply(); }, 180 );
				} );
			}

			this.root.querySelectorAll( '[data-vml-filter-select]' ).forEach( ( select ) => {
				select.addEventListener( 'change', () => {
					this.state.filters[ select.dataset.vmlFilterSelect ] = select.value;
					this.syncPills( select.dataset.vmlFilterSelect, select.value );
					this.apply();
				} );
			} );

			this.root.querySelectorAll( '[data-vml-filter-pill]' ).forEach( ( button ) => {
				button.addEventListener( 'click', () => {
					const dimension = button.dataset.vmlFilterDimension;
					const value = button.dataset.vmlFilterPill || '';
					this.state.filters[ dimension ] = value;
					this.root.querySelectorAll( `[data-vml-filter-dimension="${ dimension }"]` ).forEach( ( item ) => {
						const selected = ( item.dataset.vmlFilterPill || '' ) === value;
						item.classList.toggle( 'is-selected', selected );
						item.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
					} );
					this.apply();
				} );
			} );

			const reset = this.root.querySelector( '[data-vml-reset]' );
			if ( reset ) {
				reset.addEventListener( 'click', () => this.reset() );
			}
			const near = this.root.querySelector( '[data-vml-near-me]' );
			if ( near ) {
				near.addEventListener( 'click', () => this.nearMe( near ) );
			}

			this.root.querySelectorAll( '.vml-location-card__more' ).forEach( ( details ) => {
				details.addEventListener( 'toggle', () => {
					if ( details.open ) {
						this.selectCard( details.closest( '.vml-location-card' ) );
					}
				} );
			} );

			this.root.querySelectorAll( '.vml-location-card' ).forEach( ( card ) => {
				card.addEventListener( 'click', ( event ) => {
					if ( event.target.closest( 'a, button, summary, input, select, textarea' ) ) return;
					this.selectCard( card );
				} );
				card.addEventListener( 'keydown', ( event ) => {
					if ( event.target !== card || ( event.key !== 'Enter' && event.key !== ' ' ) ) return;
					event.preventDefault();
					this.selectCard( card, { source: 'keyboard' } );
				} );
			} );
		}

		syncPills( dimension, value ) {
			this.root.querySelectorAll( `[data-vml-filter-dimension="${ dimension }"]` ).forEach( ( item ) => {
				const selected = ( item.dataset.vmlFilterPill || '' ) === value;
				item.classList.toggle( 'is-selected', selected );
				item.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
			} );
		}

		selectCard( card, options = {} ) {
			if ( ! card ) return;
			const id = Number( card.dataset.vmlLocationId || 0 );
			this.root.querySelectorAll( '.vml-location-card.is-selected' ).forEach( ( item ) => item.classList.remove( 'is-selected' ) );
			card.classList.add( 'is-selected' );
			this.state.selectedId = id || null;
			this.root.dispatchEvent( new CustomEvent( 'vml:location-selected', { detail: { id, source: options.source || 'list' } } ) );
		}

		selectLocationById( id, options = {} ) {
			const card = this.cardById.get( Number( id ) );
			if ( ! card || card.hidden ) return false;
			this.selectCard( card, options );
			if ( options.openDetails ) {
				const details = card.querySelector( '.vml-location-card__more' );
				if ( details ) details.open = true;
			}
			if ( options.scroll ) {
				const reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
				card.scrollIntoView( { block: 'nearest', behavior: reduced ? 'auto' : 'smooth' } );
			}
			return true;
		}

		applyDeepLink() {
			if ( ! ( this.config.behaviour && this.config.behaviour.deep_linking ) ) return;
			const requested = Number( new URLSearchParams( window.location.search ).get( 'vml-location' ) || 0 );
			if ( ! requested || ! this.cardById.has( requested ) ) return;
			const card = this.cardById.get( requested );
			this.selectCard( card );
			const details = card.querySelector( '.vml-location-card__more' );
			if ( details ) details.open = true;
			window.requestAnimationFrame( () => {
				const reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
				card.scrollIntoView( { block: 'nearest', behavior: reduced ? 'auto' : 'smooth' } );
			} );
		}

		matchesFilter( location, dimension, value ) {
			if ( ! value ) return true;
			if ( dimension === 'type' ) return toArray( location.types ).some( ( item ) => String( item.id ) === value || item.slug === value );
			if ( dimension === 'group' ) return toArray( location.groups ).some( ( item ) => String( item.id ) === value || item.slug === value );
			if ( dimension === 'country' ) return String( location.country_code || '' ).toUpperCase() === String( value ).toUpperCase();
			if ( dimension === 'city' ) return normalize( location.city ) === normalize( value );
			return true;
		}

		visibleLocations() {
			return this.locations.filter( ( location ) => {
				if ( this.state.query && ! location._search.includes( this.state.query ) ) return false;
				return Object.entries( this.state.filters ).every( ( [ dimension, value ] ) => this.matchesFilter( location, dimension, value ) );
			} );
		}

		apply() {
			let visible = this.visibleLocations();
			if ( this.state.distances ) {
				visible = [ ...visible ].sort( ( first, second ) => ( this.state.distances.get( first.id ) ?? Infinity ) - ( this.state.distances.get( second.id ) ?? Infinity ) || first._index - second._index );
			}
			const visibleIds = new Set( visible.map( ( location ) => location.id ) );
			this.root.querySelectorAll( '.vml-location-card.is-selected' ).forEach( ( card ) => {
				const id = Number( card.dataset.vmlLocationId || 0 );
				if ( ! visibleIds.has( id ) ) {
					card.classList.remove( 'is-selected' );
					if ( this.state.selectedId === id ) this.state.selectedId = null;
					const details = card.querySelector( '.vml-location-card__more' );
					if ( details ) details.open = false;
				}
			} );
			this.locations.forEach( ( location ) => {
				const card = this.cardById.get( location.id );
				if ( card ) card.hidden = ! visibleIds.has( location.id );
			} );
			if ( this.list ) {
				visible.forEach( ( location ) => {
					const card = this.cardById.get( location.id );
					if ( card ) this.list.appendChild( card );
				} );
			}
			this.updateResultCount( visible.length );
			this.updateResetState();
			if ( this.noResults ) this.noResults.hidden = visible.length !== 0;
			this.root.dispatchEvent( new CustomEvent( 'vml:visible-locations', { detail: { ids: visible.map( ( location ) => location.id ) } } ) );
		}

		updateResetState() {
			if ( ! this.resetButton ) return;
			const hasFilter = Object.values( this.state.filters || {} ).some( Boolean );
			const active = Boolean( this.state.query || hasFilter || this.state.distances );
			this.resetButton.disabled = ! active;
			this.resetButton.setAttribute( 'aria-disabled', active ? 'false' : 'true' );
		}

		updateResultCount( count ) {
			const label = count === 1 ? ( this.strings.location_one || '1 location' ) : formatTemplate( this.strings.locations_many || '%d locations', count );
			if ( this.resultCount ) this.resultCount.textContent = this.state.distances ? formatTemplate( this.strings.near_you || '%s near you', label ) : label;
			if ( this.liveRegion ) this.liveRegion.textContent = this.state.distances ? formatTemplate( this.strings.sorted_distance || '%s sorted by distance.', label ) : formatTemplate( this.strings.found || '%s found.', label );
		}

		reset() {
			this.state.query = '';
			this.state.filters = {};
			this.state.distances = null;
			const search = this.root.querySelector( '[data-vml-search]' );
			if ( search ) search.value = '';
			this.root.querySelectorAll( '[data-vml-filter-select]' ).forEach( ( select ) => { select.value = ''; } );
			this.root.querySelectorAll( '[data-vml-filter-pill]' ).forEach( ( item ) => {
				const selected = ! item.dataset.vmlFilterPill;
				item.classList.toggle( 'is-selected', selected );
				item.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
			} );
			this.root.querySelectorAll( '[data-vml-distance]' ).forEach( ( item ) => { item.hidden = true; item.textContent = ''; } );
			this.apply();
		}

		nearMe( button ) {
			if ( ! navigator.geolocation ) {
				this.announce( this.strings.geo_unavailable || 'Location services are not available in this browser.' );
				return;
			}
			button.disabled = true;
			button.classList.add( 'is-busy' );
			const original = button.textContent;
			button.textContent = this.strings.locating || 'Locating…';
			navigator.geolocation.getCurrentPosition( ( position ) => {
				const distances = new Map();
				this.locations.forEach( ( location ) => {
					const lat = Number( location.latitude );
					const lng = Number( location.longitude );
					if ( Number.isFinite( lat ) && Number.isFinite( lng ) ) {
						distances.set( location.id, haversineDistanceKm( position.coords.latitude, position.coords.longitude, lat, lng ) );
					}
				} );
				this.state.distances = distances;
				distances.forEach( ( kilometres, id ) => {
					const card = this.cardById.get( id );
					const output = card && card.querySelector( '[data-vml-distance]' );
					if ( output ) { output.textContent = formatTemplate( this.strings.distance_away || '%s away', formatDistance( kilometres, this.config.behaviour && this.config.behaviour.distance_unit, this.strings ) ); output.hidden = false; }
				} );
				this.apply();
				this.announce( this.strings.locations_sorted || 'Locations sorted by distance.' );
				button.disabled = false; button.classList.remove( 'is-busy' ); button.textContent = original;
			}, ( error ) => {
				const denied = error && error.code === 1;
				this.announce( denied ? ( this.strings.geo_denied || 'Location access was not enabled. You can still search locations manually.' ) : ( this.strings.geo_failed || 'Your current location could not be determined. You can still search locations manually.' ) );
				button.disabled = false; button.classList.remove( 'is-busy' ); button.textContent = original;
			}, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 } );
		}

		announce( message ) {
			if ( this.liveRegion ) this.liveRegion.textContent = message;
			const feedback = this.root.querySelector( '[data-vml-feedback]' );
			if ( feedback ) { feedback.textContent = message; feedback.hidden = false; window.setTimeout( () => { feedback.hidden = true; }, 5000 ); }
		}

		updateLiveStatuses() {
			this.locations.forEach( ( location ) => {
				const status = liveHoursStatus( location, this.strings );
				const card = this.cardById.get( location.id );
				const node = card && card.querySelector( '[data-vml-live-status]' );
				if ( ! node || ! status ) return;
				node.hidden = false;
				node.classList.remove( 'is-success', 'is-warning', 'is-info', 'is-neutral' );
				node.classList.add( `is-${ status.state || 'neutral' }` );
				const label = node.querySelector( '[data-vml-status-label]' );
				if ( label ) label.textContent = status.label;
			} );
		}
	}

	function initializeRoot( root ) {
		if ( ! root || root.dataset.vmlInitialized === 'true' ) return;
		root.dataset.vmlInitialized = 'true';
		root.classList.add( 'vml-is-enhanced' );
		root.vmlController = new LocatorController( root );
	}

	function initialize( scope = document ) {
		scope.querySelectorAll( '.vml-locator[data-vml-instance]' ).forEach( initializeRoot );
	}

	window.VelomaloFrontend = { initialize, initializeRoot };
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', () => initialize() );
	else initialize();
}() );
