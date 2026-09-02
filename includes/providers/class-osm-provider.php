<?php
/**
 * OpenStreetMap Standard provider.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenStreetMap Standard raster tiles rendered through Leaflet.
 */
final class OSM_Provider implements Map_Provider_Interface {

	/** {@inheritDoc} */
	public function get_id() {
		return 'osm';
	}

	/** {@inheritDoc} */
	public function get_label() {
		return __( 'OpenStreetMap', 'velox-map-locator' );
	}

	/** {@inheritDoc} */
	public function get_capabilities() {
		return array(
			'markers'        => true,
			'custom_markers' => true,
			'clustering'     => true,
			'geocoding'      => false,
			'dark_map'       => false,
			'fullscreen'     => false,
			'fit_bounds'     => true,
			'drag_marker'    => true,
		);
	}

	/** {@inheritDoc} */
	public function is_configured( $locator_config = array() ) {
		return true;
	}

	/** {@inheritDoc} */
	public function get_public_config( $locator_config = array() ) {
		return array(
			'id'          => 'osm',
			'engine'      => 'leaflet',
			'tile_url'    => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			'min_zoom'    => 0,
			'max_zoom'    => 19,
		);
	}

	/** {@inheritDoc} */
	public function get_external_service_info( $locator_config = array() ) {
		return array(
			'name'        => 'OpenStreetMap Standard tile service',
			'service_url' => 'https://tile.openstreetmap.org/',
			'policy_url'  => 'https://operations.osmfoundation.org/policies/tiles/',
			'privacy_url' => 'https://osmfoundation.org/wiki/Privacy_Policy',
		);
	}
}
