<?php
/**
 * Google Maps JavaScript API provider.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Providers;

use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Services\Google_Settings_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Maps renderer using the Maps JavaScript API and AdvancedMarkerElement.
 */
final class Google_Provider implements Map_Provider_Interface {

	/** {@inheritDoc} */
	public function get_id() {
		return 'google';
	}

	/** {@inheritDoc} */
	public function get_label() {
		return __( 'Google Maps', 'velox-map-locator' );
	}

	/** {@inheritDoc} */
	public function get_capabilities() {
		return array(
			'markers'        => true,
			'custom_markers' => true,
			'clustering'     => true,
			'geocoding'      => true,
			'dark_map'       => false,
			'fullscreen'     => true,
			'fit_bounds'     => true,
			'drag_marker'    => true,
		);
	}

	/** {@inheritDoc} */
	public function is_configured( $locator_config = array() ) {
		$settings = self::settings();
		return '' !== self::resolved_api_key( $settings ) && '' !== $settings['map_id'];
	}

	/** {@inheritDoc} */
	public function get_public_config( $locator_config = array() ) {
		$settings = self::settings();
		$config   = array(
			'id'      => 'google',
			'engine'  => 'google',
			'version' => 'weekly',
		);

		$api_key = self::resolved_api_key( $settings );
		if ( '' !== $api_key ) {
			$config['api_key'] = $api_key;
		}
		if ( '' !== $settings['map_id'] ) {
			$config['map_id'] = $settings['map_id'];
		}
		if ( 'auto' !== $settings['region'] ) {
			$config['region'] = $settings['region'];
		}

		return $config;
	}

	/** {@inheritDoc} */
	public function get_external_service_info( $locator_config = array() ) {
		return array(
			'name'        => 'Google Maps Platform',
			'service_url' => 'https://maps.googleapis.com/',
			'policy_url'  => 'https://cloud.google.com/maps-platform/terms',
			'privacy_url' => 'https://policies.google.com/privacy',
		);
	}

	/** Resolve settings through the same allowlist used when saving. */
	public static function settings() {
		$raw        = get_option( Settings::OPTION_PROVIDERS, Settings::provider_defaults() );
		$raw_google = is_array( $raw ) && isset( $raw['google'] ) && is_array( $raw['google'] ) ? $raw['google'] : array();
		$validated  = ( new Google_Settings_Validator() )->normalize( $raw_google, Settings::provider_defaults()['google'] );
		return is_wp_error( $validated ) ? Settings::provider_defaults()['google'] : $validated;
	}

	/**
	 * Resolve the browser API key, allowing a wp-config.php override.
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return string
	 */
	public static function resolved_api_key( $settings = null ) {
		if ( defined( 'VELOX_MAP_LOCATOR_GOOGLE_API_KEY' ) && is_scalar( VELOX_MAP_LOCATOR_GOOGLE_API_KEY ) ) {
			$constant = trim( (string) VELOX_MAP_LOCATOR_GOOGLE_API_KEY );
			if ( '' !== $constant ) {
				return $constant;
			}
		}
		$settings = is_array( $settings ) ? $settings : self::settings();
		return isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : '';
	}

	/** Whether wp-config.php supplies the key. */
	public static function uses_constant_api_key() {
		return defined( 'VELOX_MAP_LOCATOR_GOOGLE_API_KEY' ) && is_scalar( VELOX_MAP_LOCATOR_GOOGLE_API_KEY ) && '' !== trim( (string) VELOX_MAP_LOCATOR_GOOGLE_API_KEY );
	}
}
