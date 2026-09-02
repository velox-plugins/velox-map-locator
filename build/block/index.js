( function () {
	'use strict';

	if ( ! window.wp || ! wp.blocks || ! wp.element || ! wp.components || ! wp.i18n || ! wp.apiFetch ) return;

	const { registerBlockType } = wp.blocks;
	const { createElement: h, Fragment, useEffect, useMemo, useState } = wp.element;
	const { InspectorControls } = wp.blockEditor || {};
	const { PanelBody, SelectControl, Spinner, Notice, Button } = wp.components;
	const { __ } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const ServerSideRender = wp.serverSideRender;
	const namespace = '/velox-map-locator/v1';

	function editorUrl( id ) {
		return `${ window.ajaxurl ? window.ajaxurl.replace( /admin-ajax\.php.*$/, 'admin.php' ) : '/wp-admin/admin.php' }?page=velox-map-locator-locators&velomalo_action=edit&locator_id=${ Number( id ) }`;
	}

	function Edit( { attributes, setAttributes } ) {
		const locatorId = Number( attributes.locatorId || 0 );
		const [ locators, setLocators ] = useState( [] );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( '' );

		useEffect( () => {
			let cancelled = false;
			apiFetch( { path: `${ namespace }/admin/locators?per_page=100&status=publish&orderby=title&order=ASC` } )
				.then( ( items ) => { if ( ! cancelled ) setLocators( Array.isArray( items ) ? items : [] ); } )
				.catch( ( err ) => { if ( ! cancelled ) setError( err && err.message ? err.message : __( 'Published Locators could not be loaded.', 'velox-map-locator' ) ); } )
				.finally( () => { if ( ! cancelled ) setLoading( false ); } );
			return () => { cancelled = true; };
		}, [] );

		const options = useMemo( () => [ { label: __( 'Choose a Locator', 'velox-map-locator' ), value: 0 } ].concat( locators.map( ( item ) => ( { label: item.name || `#${ item.id }`, value: Number( item.id ) } ) ) ), [ locators ] );
		const selected = locators.find( ( item ) => Number( item.id ) === locatorId );
		const chooser = h( SelectControl, {
			label: __( 'Locator', 'velox-map-locator' ),
			value: locatorId,
			options,
			onChange: ( value ) => setAttributes( { locatorId: Number( value || 0 ) } ),
		} );

		return h( Fragment, null,
			InspectorControls && h( InspectorControls, null,
				h( PanelBody, { title: __( 'Velox Map Locator', 'velox-map-locator' ), initialOpen: true }, chooser, locatorId > 0 && h( Button, { variant: 'secondary', href: editorUrl( locatorId ) }, __( 'Edit Locator', 'velox-map-locator' ) ) )
			),
			h( 'div', { className: 'vml-block-editor' },
				loading ? h( 'div', { className: 'vml-block-editor__loading' }, h( Spinner ), h( 'span', null, __( 'Loading Locators…', 'velox-map-locator' ) ) ) : null,
				error ? h( Notice, { status: 'error', isDismissible: false }, error ) : null,
				! loading && ! error && ! locatorId ? h( 'div', { className: 'vml-block-editor__placeholder' }, h( 'span', { className: 'dashicons dashicons-location-alt', 'aria-hidden': 'true' } ), h( 'strong', null, __( 'Velox Map Locator', 'velox-map-locator' ) ), h( 'p', null, __( 'Choose a published Locator to place it on this page.', 'velox-map-locator' ) ), chooser ) : null,
				! loading && ! error && locatorId > 0 && selected ? h( Fragment, null,
					h( 'div', { className: 'vml-block-editor__bar' }, h( 'div', null, h( 'strong', null, selected.name ), h( 'span', null, __( 'Dynamic frontend preview', 'velox-map-locator' ) ) ), h( Button, { variant: 'tertiary', href: editorUrl( locatorId ) }, __( 'Open Builder', 'velox-map-locator' ) ) ),
					ServerSideRender ? h( ServerSideRender, { block: 'velox/map-locator', attributes: { locatorId } } ) : h( 'p', null, __( 'The selected Locator will render on the frontend.', 'velox-map-locator' ) )
				) : null,
				! loading && ! error && locatorId > 0 && ! selected ? h( Notice, { status: 'warning', isDismissible: false }, __( 'The selected Locator is not currently published or is no longer available.', 'velox-map-locator' ) ) : null
			)
		);
	}

	registerBlockType( 'velox/map-locator', {
		title: __( 'Velox Map Locator', 'velox-map-locator' ),
		category: 'widgets',
		icon: 'location-alt',
		description: __( 'Display a configured Velox Map Locator.', 'velox-map-locator' ),
		attributes: { locatorId: { type: 'integer', default: 0 } },
		edit: Edit,
		save: () => null,
	} );
}() );
