<?php
/**
 * Map provider administration REST controller.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Rest;

use VeloxPlugins\VeloxMapLocator\Content\Post_Types;
use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Providers\Google_Provider;
use VeloxPlugins\VeloxMapLocator\Services\Google_Settings_Validator;
use VeloxPlugins\VeloxMapLocator\Services\Provider_Registry;
use VeloxPlugins\VeloxMapLocator\Services\XYZ_Profile_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability-protected Custom XYZ profile management.
 */
final class Providers_Controller extends Rest_Controller {

	/** Register routes. */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/providers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/providers/google',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_google' ),
				'permission_callback' => array( $this, 'manage_permissions_check' ),
				'args'                => array(
					'api_key'       => array( 'type' => 'string', 'required' => false ),
					'clear_api_key' => array( 'type' => 'boolean', 'required' => false ),
					'map_id'        => array( 'type' => 'string', 'required' => false ),
					'region'        => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/providers/xyz',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_profile' ),
				'permission_callback' => array( $this, 'manage_permissions_check' ),
				'args'                => array( 'profile' => array( 'type' => 'object', 'required' => true ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/providers/xyz/(?P<id>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_profile' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'id'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						'profile' => array( 'type' => 'object', 'required' => true ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_profile' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array( 'id' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ) ),
				),
			)
		);
	}

	/** Return provider cards and XYZ profiles. */
	public function get_items() {
		$settings = $this->settings();
		$profiles = array();
		foreach ( $settings['xyz_profiles'] as $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$profile['usage_count'] = $this->usage_count( isset( $profile['id'] ) ? $profile['id'] : '' );
			$profiles[] = $profile;
		}

		$registry = new Provider_Registry();
		$osm      = $registry->get( 'osm' );
		$google   = $registry->get( 'google' );
		return rest_ensure_response(
			array(
				'osm'          => array(
					'id'               => 'osm',
					'label'            => $osm ? $osm->get_label() : __( 'OpenStreetMap', 'velox-map-locator' ),
					'configured'       => true,
					'engine'           => 'leaflet',
					'external_service' => $osm ? $osm->get_external_service_info() : array(),
				),
				'google'       => $this->google_admin_config( $google ),
				'xyz_profiles' => $profiles,
			)
		);
	}

	/** Save Google Maps browser-provider settings. */
	public function update_google( $request ) {
		$settings = $this->settings();
		$current  = isset( $settings['google'] ) && is_array( $settings['google'] ) ? $settings['google'] : Settings::provider_defaults()['google'];
		$input    = array(
			'map_id' => null !== $request->get_param( 'map_id' ) ? $request->get_param( 'map_id' ) : $current['map_id'],
			'region' => null !== $request->get_param( 'region' ) ? $request->get_param( 'region' ) : $current['region'],
		);

		if ( ! Google_Provider::uses_constant_api_key() ) {
			if ( true === $request->get_param( 'clear_api_key' ) ) {
				$input['api_key'] = '';
			} else {
				$submitted_key = $request->get_param( 'api_key' );
				$input['api_key'] = is_scalar( $submitted_key ) && '' !== trim( (string) $submitted_key ) ? $submitted_key : $current['api_key'];
			}
		} else {
			$input['api_key'] = $current['api_key'];
		}

		$google = ( new Google_Settings_Validator() )->normalize( $input, $current );
		if ( is_wp_error( $google ) ) {
			return $google;
		}
		$settings['google'] = $google;
		$this->save( $settings );
		return rest_ensure_response( $this->google_admin_config( ( new Provider_Registry() )->get( 'google' ) ) );
	}

	/** Create a profile with a stable generated ID. */
	public function create_profile( $request ) {
		$input = $request->get_param( 'profile' );
		$name  = is_array( $input ) && isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		$id    = $this->unique_id( sanitize_title( $name ) );
		if ( '' === $id ) {
			$id = $this->unique_id( 'xyz-profile' );
		}
		$profile = ( new XYZ_Profile_Validator() )->normalize( $input, array(), $id );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$settings                  = $this->settings();
		$settings['xyz_profiles'][] = $profile;
		$this->save( $settings );
		$profile['usage_count'] = 0;
		$response = rest_ensure_response( $profile );
		$response->set_status( 201 );
		return $response;
	}

	/** Update one profile without changing its ID. */
	public function update_profile( $request ) {
		$id       = sanitize_key( $request['id'] );
		$settings = $this->settings();
		$index    = $this->profile_index( $settings['xyz_profiles'], $id );
		if ( null === $index ) {
			return new \WP_Error( 'velomalo_xyz_profile_not_found', __( 'XYZ profile not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}
		$profile = ( new XYZ_Profile_Validator() )->normalize( $request->get_param( 'profile' ), $settings['xyz_profiles'][ $index ], $id );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$settings['xyz_profiles'][ $index ] = $profile;
		$this->save( $settings );
		$profile['usage_count'] = $this->usage_count( $id );
		return rest_ensure_response( $profile );
	}

	/** Delete an unused profile. */
	public function delete_profile( $request ) {
		$id       = sanitize_key( $request['id'] );
		$settings = $this->settings();
		$index    = $this->profile_index( $settings['xyz_profiles'], $id );
		if ( null === $index ) {
			return new \WP_Error( 'velomalo_xyz_profile_not_found', __( 'XYZ profile not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}
		$global_settings = Settings::sanitize_settings( get_option( Settings::OPTION_SETTINGS, Settings::defaults() ) );
		if ( 'xyz' === $global_settings['general']['default_map_provider'] && $id === sanitize_key( (string) $global_settings['general']['default_xyz_profile_id'] ) ) {
			return new \WP_Error( 'velomalo_xyz_profile_is_default', __( 'This XYZ profile is the global default map provider. Choose a different default under Settings before deleting it.', 'velox-map-locator' ), array( 'status' => 409 ) );
		}
		$usage = $this->usage_count( $id );
		if ( $usage > 0 ) {
			return new \WP_Error( 'velomalo_xyz_profile_in_use', __( 'This XYZ profile is used by one or more Locators. Update those Locators before deleting it.', 'velox-map-locator' ), array( 'status' => 409 ) );
		}
		array_splice( $settings['xyz_profiles'], $index, 1 );
		$this->save( $settings );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	/** Read permission: Locator editors can inspect configured profile choices. */
	public function read_permissions_check() {
		return current_user_can( 'edit_velomalo_locators' ) ? true : $this->permission_error();
	}

	/** Write permission: provider management remains administrator-level by default. */
	public function manage_permissions_check() {
		return current_user_can( 'manage_velomalo_providers' ) ? true : $this->permission_error();
	}

	/** Return normalized stored provider settings. */
	private function settings() {
		return Settings::sanitize_provider_settings( get_option( Settings::OPTION_PROVIDERS, Settings::provider_defaults() ) );
	}

	/** Persist non-autoloaded provider settings. */
	private function save( $settings ) {
		update_option( Settings::OPTION_PROVIDERS, Settings::sanitize_provider_settings( $settings ), false );
	}

	/** Find a profile array index by ID. */
	private function profile_index( $profiles, $id ) {
		foreach ( is_array( $profiles ) ? $profiles : array() as $index => $profile ) {
			if ( is_array( $profile ) && $id === sanitize_key( isset( $profile['id'] ) ? (string) $profile['id'] : '' ) ) {
				return $index;
			}
		}
		return null;
	}

	/** Generate a unique profile ID. */
	private function unique_id( $base ) {
		$base     = substr( sanitize_key( $base ), 0, 60 );
		$base     = '' === $base ? 'xyz-profile' : $base;
		$settings = $this->settings();
		$used     = array();
		foreach ( $settings['xyz_profiles'] as $profile ) {
			if ( is_array( $profile ) && ! empty( $profile['id'] ) ) {
				$used[] = sanitize_key( $profile['id'] );
			}
		}
		$id = $base;
		for ( $i = 2; in_array( $id, $used, true ); ++$i ) {
			$id = substr( $base, 0, 55 ) . '-' . $i;
		}
		return $id;
	}

	/** Return Google settings without exposing the stored browser key to admin REST responses. */
	private function google_admin_config( $provider = null ) {
		$settings  = $this->settings();
		$google    = isset( $settings['google'] ) && is_array( $settings['google'] ) ? $settings['google'] : Settings::provider_defaults()['google'];
		$api_key   = Google_Provider::resolved_api_key( $google );
		$constant  = Google_Provider::uses_constant_api_key();
		return array(
			'id'               => 'google',
			'label'            => $provider ? $provider->get_label() : __( 'Google Maps', 'velox-map-locator' ),
			'configured'       => '' !== $api_key && '' !== (string) $google['map_id'],
			'has_api_key'      => '' !== $api_key,
			'api_key_masked'   => $this->mask_secret( $api_key ),
			'api_key_source'   => $constant ? 'constant' : 'database',
			'map_id'           => (string) $google['map_id'],
			'region'           => (string) $google['region'],
			'engine'           => 'google',
			'external_service' => $provider ? $provider->get_external_service_info() : array(),
		);
	}

	/** Mask a browser API key in admin API responses. */
	private function mask_secret( $value ) {
		$value = (string) $value;
		$length = strlen( $value );
		if ( 0 === $length ) {
			return '';
		}
		if ( $length <= 10 ) {
			return str_repeat( '•', $length );
		}
		return substr( $value, 0, 6 ) . str_repeat( '•', max( 4, $length - 10 ) ) . substr( $value, -4 );
	}

	/** Count Locators that reference a profile. */
	private function usage_count( $profile_id ) {
		$profile_id = sanitize_key( (string) $profile_id );
		if ( '' === $profile_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::LOCATOR,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$count = 0;
		foreach ( $ids as $locator_id ) {
			$config = get_post_meta( $locator_id, '_velomalo_config', true );
			if ( is_array( $config ) && isset( $config['map']['provider'], $config['map']['provider_profile_id'] ) && 'xyz' === $config['map']['provider'] && $profile_id === sanitize_key( (string) $config['map']['provider_profile_id'] ) ) {
				++$count;
			}
		}
		return $count;
	}
}
