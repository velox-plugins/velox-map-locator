<?php
/**
 * Global settings registration and defaults.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Content;

use VeloxPlugins\VeloxMapLocator\Services\Google_Settings_Validator;
use VeloxPlugins\VeloxMapLocator\Services\XYZ_Profile_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines system-level plugin settings.
 */
final class Settings {

	const OPTION_SETTINGS  = 'velomalo_settings';
	const OPTION_PROVIDERS = 'velomalo_provider_settings';

	/**
	 * Register options with WordPress.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'velomalo_settings_group',
			self::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'default'           => self::defaults(),
				'show_in_rest'      => false,
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
			)
		);

		register_setting(
			'velomalo_provider_settings_group',
			self::OPTION_PROVIDERS,
			array(
				'type'              => 'array',
				'default'           => self::provider_defaults(),
				'show_in_rest'      => false,
				'sanitize_callback' => array( self::class, 'sanitize_provider_settings' ),
			)
		);
	}

	/**
	 * Install defaults without overwriting existing settings.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION_SETTINGS, false ) ) {
			add_option( self::OPTION_SETTINGS, self::defaults(), '', false );
		}

		if ( false === get_option( self::OPTION_PROVIDERS, false ) ) {
			add_option( self::OPTION_PROVIDERS, self::provider_defaults(), '', false );
		}
	}

	/**
	 * Global defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'general'          => array(
				'default_distance_unit' => 'auto',
				'default_map_provider'  => 'osm',
				'default_xyz_profile_id'=> '',
				'default_theme'         => 'velox',
				'default_colour_mode'   => 'light',
				'default_typography'    => 'inherit',
			),
			'map_defaults'     => array(
				'height'                 => 620,
				'sidebar_width'          => 25,
				'single_location_zoom'   => 14,
				'home_control'           => true,
				'fit_control'            => true,
				'zoom_controls'          => true,
				'zoom_level_control'     => true,
				'scale_control'          => true,
				'fullscreen'             => true,
				'scroll_zoom'            => false,
				'refit_on_filter'        => true,
			),
			'appearance'       => array(
				'radius'  => 10,
				'density' => 'comfortable',
				'shadow'  => 'soft',
				'accent'  => '#2563eb',
			),
			'admin_interface'  => array(
				'appearance' => 'system',
				'density'    => 'comfortable',
			),
			'privacy_defaults' => array(
				'map_load_mode' => 'immediate',
			),
			'data'             => array(
				'delete_data_on_uninstall' => false,
			),
			'advanced'         => array(),
		);
	}

	/**
	 * Provider defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function provider_defaults() {
		return array(
			'google'       => array(
				'api_key' => '',
				'map_id'  => '',
				'region'  => 'auto',
			),
			'xyz_profiles' => array(),
		);
	}

	/**
	 * Sanitize global settings using explicit allowlists.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $value ) {
		$value    = is_array( $value ) ? $value : array();
		$defaults = self::defaults();
		$output   = $defaults;

		$general = isset( $value['general'] ) && is_array( $value['general'] ) ? $value['general'] : array();
		$output['general']['default_distance_unit'] = self::allow_value( $general, 'default_distance_unit', array( 'auto', 'kilometres', 'miles' ), $defaults['general']['default_distance_unit'] );
		$output['general']['default_map_provider']  = self::allow_value( $general, 'default_map_provider', array( 'osm', 'google', 'xyz' ), $defaults['general']['default_map_provider'] );
		$output['general']['default_xyz_profile_id'] = isset( $general['default_xyz_profile_id'] ) && is_scalar( $general['default_xyz_profile_id'] ) ? substr( sanitize_key( (string) $general['default_xyz_profile_id'] ), 0, 100 ) : '';
		$output['general']['default_theme']         = self::allow_value( $general, 'default_theme', array( 'velox', 'slate', 'azure', 'forest' ), $defaults['general']['default_theme'] );
		$output['general']['default_colour_mode']   = self::allow_value( $general, 'default_colour_mode', array( 'light', 'dark', 'auto' ), $defaults['general']['default_colour_mode'] );
		$output['general']['default_typography']    = self::allow_value( $general, 'default_typography', array( 'inherit', 'modern-sans', 'humanist-sans', 'classic-sans', 'serif' ), $defaults['general']['default_typography'] );


		$map_defaults = isset( $value['map_defaults'] ) && is_array( $value['map_defaults'] ) ? $value['map_defaults'] : array();
		$output['map_defaults']['height']               = isset( $map_defaults['height'] ) ? max( 300, min( 1200, absint( $map_defaults['height'] ) ) ) : $defaults['map_defaults']['height'];
		$output['map_defaults']['sidebar_width']        = isset( $map_defaults['sidebar_width'] ) ? max( 20, min( 50, absint( $map_defaults['sidebar_width'] ) ) ) : $defaults['map_defaults']['sidebar_width'];
		$output['map_defaults']['single_location_zoom'] = isset( $map_defaults['single_location_zoom'] ) ? max( 1, min( 22, absint( $map_defaults['single_location_zoom'] ) ) ) : $defaults['map_defaults']['single_location_zoom'];
		foreach ( array( 'home_control', 'fit_control', 'zoom_controls', 'zoom_level_control', 'scale_control', 'fullscreen', 'scroll_zoom', 'refit_on_filter' ) as $boolean_key ) {
			$output['map_defaults'][ $boolean_key ] = array_key_exists( $boolean_key, $map_defaults ) ? ! empty( $map_defaults[ $boolean_key ] ) : $defaults['map_defaults'][ $boolean_key ];
		}

		$appearance = isset( $value['appearance'] ) && is_array( $value['appearance'] ) ? $value['appearance'] : array();
		$output['appearance']['radius']  = isset( $appearance['radius'] ) ? min( 24, max( 0, absint( $appearance['radius'] ) ) ) : $defaults['appearance']['radius'];
		$output['appearance']['density'] = self::allow_value( $appearance, 'density', array( 'compact', 'comfortable', 'spacious' ), $defaults['appearance']['density'] );
		$output['appearance']['shadow']  = self::allow_value( $appearance, 'shadow', array( 'none', 'soft', 'medium' ), $defaults['appearance']['shadow'] );
		$output['appearance']['accent']  = isset( $appearance['accent'] ) ? sanitize_hex_color( $appearance['accent'] ) : $defaults['appearance']['accent'];

		if ( empty( $output['appearance']['accent'] ) ) {
			$output['appearance']['accent'] = $defaults['appearance']['accent'];
		}

		$admin = isset( $value['admin_interface'] ) && is_array( $value['admin_interface'] ) ? $value['admin_interface'] : array();
		$output['admin_interface']['appearance'] = self::allow_value( $admin, 'appearance', array( 'system', 'light', 'dark' ), $defaults['admin_interface']['appearance'] );
		$output['admin_interface']['density']    = self::allow_value( $admin, 'density', array( 'comfortable', 'compact' ), $defaults['admin_interface']['density'] );

		$privacy = isset( $value['privacy_defaults'] ) && is_array( $value['privacy_defaults'] ) ? $value['privacy_defaults'] : array();
		$output['privacy_defaults']['map_load_mode'] = self::allow_value( $privacy, 'map_load_mode', array( 'immediate', 'interaction' ), $defaults['privacy_defaults']['map_load_mode'] );

		$data = isset( $value['data'] ) && is_array( $value['data'] ) ? $value['data'] : array();
		$output['data']['delete_data_on_uninstall'] = ! empty( $data['delete_data_on_uninstall'] );

		return $output;
	}

	/**
	 * Sanitize provider settings while preserving the initial provider schema.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize_provider_settings( $value ) {
		$value    = is_array( $value ) ? $value : array();
		$defaults = self::provider_defaults();
		$output   = $defaults;
		$google   = isset( $value['google'] ) && is_array( $value['google'] ) ? $value['google'] : array();
		$normalized_google = ( new Google_Settings_Validator() )->normalize( $google, $defaults['google'] );
		if ( ! is_wp_error( $normalized_google ) ) {
			$output['google'] = $normalized_google;
		}

		$profiles  = isset( $value['xyz_profiles'] ) && is_array( $value['xyz_profiles'] ) ? array_slice( array_values( $value['xyz_profiles'] ), 0, 50 ) : array();
		$validator = new XYZ_Profile_Validator();
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$id = isset( $profile['id'] ) ? sanitize_key( (string) $profile['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			$normalized = $validator->normalize( $profile, array(), $id );
			if ( ! is_wp_error( $normalized ) ) {
				$output['xyz_profiles'][] = $normalized;
			}
		}

		return $output;
	}

	/**
	 * Return an allowlisted setting or fallback.
	 *
	 * @param array<string,mixed> $source   Source array.
	 * @param string              $key      Key to inspect.
	 * @param string[]            $allowed  Allowed values.
	 * @param string              $fallback Fallback value.
	 * @return string
	 */
	private static function allow_value( $source, $key, $allowed, $fallback ) {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return $fallback;
		}

		$value = sanitize_key( (string) $source[ $key ] );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
