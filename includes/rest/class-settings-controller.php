<?php
/**
 * Global plugin settings REST controller.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Rest;

use VeloxPlugins\VeloxMapLocator\Content\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability-protected global settings management.
 */
final class Settings_Controller extends Rest_Controller {

	/** Register settings routes. */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'settings' => array( 'type' => 'object', 'required' => true ),
					),
				),
			)
		);
	}

	/** Return normalized settings. */
	public function get_item() {
		return rest_ensure_response( $this->settings() );
	}

	/** Save normalized settings. */
	public function update_item( $request ) {
		$input = $request->get_param( 'settings' );
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'velomalo_invalid_settings', __( 'Settings must be an object.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}
		$settings = Settings::sanitize_settings( $input );
		$profile_error = $this->validate_default_xyz_profile( $settings );
		if ( is_wp_error( $profile_error ) ) {
			return $profile_error;
		}
		update_option( Settings::OPTION_SETTINGS, $settings, false );
		return rest_ensure_response( $settings );
	}

	/** Settings management remains administrator-level by default. */
	public function permissions_check() {
		return current_user_can( 'manage_velomalo_settings' ) ? true : $this->permission_error();
	}

	/** Read normalized current settings. */
	private function settings() {
		return Settings::sanitize_settings( get_option( Settings::OPTION_SETTINGS, Settings::defaults() ) );
	}

	/** Require the selected global XYZ default to reference a saved profile. */
	private function validate_default_xyz_profile( $settings ) {
		if ( 'xyz' !== $settings['general']['default_map_provider'] ) {
			return true;
		}
		$profile_id = sanitize_key( (string) $settings['general']['default_xyz_profile_id'] );
		if ( '' === $profile_id ) {
			return new \WP_Error( 'velomalo_default_xyz_profile_required', __( 'Choose a Custom XYZ profile before making Custom XYZ the default map provider.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'general.default_xyz_profile_id' ) );
		}
		$provider_settings = Settings::sanitize_provider_settings( get_option( Settings::OPTION_PROVIDERS, Settings::provider_defaults() ) );
		foreach ( $provider_settings['xyz_profiles'] as $profile ) {
			if ( is_array( $profile ) && $profile_id === sanitize_key( isset( $profile['id'] ) ? (string) $profile['id'] : '' ) ) {
				return true;
			}
		}
		return new \WP_Error( 'velomalo_default_xyz_profile_missing', __( 'The selected default XYZ profile no longer exists.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'general.default_xyz_profile_id' ) );
	}
}
