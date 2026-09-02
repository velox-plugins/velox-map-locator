<?php
/**
 * Custom XYZ raster provider.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Providers;

use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Services\XYZ_Profile_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders user-defined XYZ raster tile profiles through the bundled Leaflet engine.
 */
final class XYZ_Provider implements Map_Provider_Interface {

	/** {@inheritDoc} */
	public function get_id() {
		return 'xyz';
	}

	/** {@inheritDoc} */
	public function get_label() {
		return __( 'Custom XYZ', 'velox-map-locator' );
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
		return null !== $this->profile_for_locator( $locator_config );
	}

	/** {@inheritDoc} */
	public function get_public_config( $locator_config = array() ) {
		$profile = $this->profile_for_locator( $locator_config );
		if ( ! $profile ) {
			return array(
				'id'     => 'xyz',
				'engine' => 'leaflet',
			);
		}

		return array(
			'id'            => 'xyz',
			'profile_id'    => $profile['id'],
			'profile_name'  => $profile['name'],
			'engine'        => 'leaflet',
			'tile_url'      => $profile['tile_url'],
			'attribution'   => $profile['attribution'],
			'min_zoom'      => $profile['min_zoom'],
			'max_zoom'      => $profile['max_zoom'],
			'subdomains'    => $profile['subdomains'],
			'tms'           => $profile['tms'],
			'detect_retina' => $profile['detect_retina'],
		);
	}

	/** {@inheritDoc} */
	public function get_external_service_info( $locator_config = array() ) {
		$profile = $this->profile_for_locator( $locator_config );
		return $profile ? array(
			'name'        => $profile['name'],
			'service_url' => $profile['service_url'],
			'policy_url'  => $profile['terms_url'],
			'privacy_url' => $profile['privacy_url'],
		) : array();
	}

	/** Resolve and revalidate one configured profile. */
	private function profile_for_locator( $locator_config ) {
		$profile_id = isset( $locator_config['map']['provider_profile_id'] ) ? sanitize_key( (string) $locator_config['map']['provider_profile_id'] ) : '';
		if ( '' === $profile_id ) {
			return null;
		}

		$settings = get_option( Settings::OPTION_PROVIDERS, Settings::provider_defaults() );
		$profiles = isset( $settings['xyz_profiles'] ) && is_array( $settings['xyz_profiles'] ) ? $settings['xyz_profiles'] : array();
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) || $profile_id !== sanitize_key( isset( $profile['id'] ) ? (string) $profile['id'] : '' ) ) {
				continue;
			}
			$validated = ( new XYZ_Profile_Validator() )->normalize( $profile, array(), $profile_id );
			return is_wp_error( $validated ) ? null : $validated;
		}
		return null;
	}
}
