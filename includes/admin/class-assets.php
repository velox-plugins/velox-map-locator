<?php
/**
 * Velox admin application assets.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Admin;

use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Providers\Google_Provider;
use VeloxPlugins\VeloxMapLocator\Frontend\Assets as Frontend_Assets;
use VeloxPlugins\VeloxMapLocator\Services\Location_Validator;
use VeloxPlugins\VeloxMapLocator\Services\Locator_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the Velox admin application only on Velox screens.
 */
final class Assets {

	/**
	 * Register the admin enqueue hook.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue application assets on Velox screens.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::is_velox_screen() ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-components' );

		$css_path = VELOX_MAP_LOCATOR_PATH . 'build/admin.css';
		$js_path  = VELOX_MAP_LOCATOR_PATH . 'build/admin.js';

		wp_enqueue_style(
			'velomalo-admin',
			VELOX_MAP_LOCATOR_URL . 'build/admin.css',
			array( 'wp-components' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : VELOX_MAP_LOCATOR_VERSION
		);

		wp_enqueue_script(
			'velomalo-admin',
			VELOX_MAP_LOCATOR_URL . 'build/admin.js',
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'media-editor' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : VELOX_MAP_LOCATOR_VERSION,
			true
		);


		// The Locator Builder embeds the real frontend renderer for its protected live preview.
		if ( self::is_locator_builder_request() ) {
			Frontend_Assets::enqueue();
			Frontend_Assets::enqueue_map( 'osm' );
			Frontend_Assets::enqueue_map( 'google' );
		}

		$settings      = wp_parse_args( get_option( Settings::OPTION_SETTINGS, array() ), Settings::defaults() );
		$admin_settings = isset( $settings['admin_interface'] ) && is_array( $settings['admin_interface'] ) ? $settings['admin_interface'] : Settings::defaults()['admin_interface'];
		$provider_settings = Settings::sanitize_provider_settings( get_option( Settings::OPTION_PROVIDERS, Settings::provider_defaults() ) );
		$google_settings = Google_Provider::settings();
		$google_api_key  = Google_Provider::resolved_api_key( $google_settings );
		$google_maps     = array(
			'configured' => '' !== $google_api_key && '' !== $google_settings['map_id'],
			'apiKey'     => $google_api_key,
			'mapId'      => $google_settings['map_id'],
			'region'     => $google_settings['region'],
			'keySource'  => Google_Provider::uses_constant_api_key() ? 'constant' : 'database',
		);

		$xyz_profile_choices = array();
		foreach ( $provider_settings['xyz_profiles'] as $profile ) {
			if ( is_array( $profile ) && ! empty( $profile['id'] ) && ! empty( $profile['name'] ) ) {
				$xyz_profile_choices[] = array( 'id' => sanitize_key( $profile['id'] ), 'name' => sanitize_text_field( $profile['name'] ) );
			}
		}

		$boot = array(
			'version'      => VELOX_MAP_LOCATOR_VERSION,
			'route'        => self::current_route(),
			'action'       => self::current_action(), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
			'locationId'   => isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
			'locatorId'    => isset( $_GET['locator_id'] ) ? absint( wp_unslash( $_GET['locator_id'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
			'adminAppearance' => isset( $admin_settings['appearance'] ) ? sanitize_key( $admin_settings['appearance'] ) : 'system',
			'adminDensity'  => isset( $admin_settings['density'] ) ? sanitize_key( $admin_settings['density'] ) : 'comfortable',
			'urls'          => array(
				'overview'        => Menu::page_url( Menu::SLUG ),
				'locations'       => Menu::page_url( Menu::LOCATIONS_SLUG ),
				'locators'        => Menu::page_url( Menu::LOCATORS_SLUG ),
				'addLocation'     => add_query_arg( 'velomalo_action', 'edit', Menu::page_url( Menu::LOCATIONS_SLUG ) ),
				'addLocator'      => add_query_arg( 'velomalo_action', 'create', Menu::page_url( Menu::LOCATORS_SLUG ) ),
				'editLocationBase'=> add_query_arg( 'velomalo_action', 'edit', Menu::page_url( Menu::LOCATIONS_SLUG ) ),
				'editLocatorBase' => add_query_arg( 'velomalo_action', 'edit', Menu::page_url( Menu::LOCATORS_SLUG ) ),
				'classifications' => Menu::page_url( Menu::CLASSIFICATIONS_SLUG ),
				'providers'       => Menu::page_url( Menu::PROVIDERS_SLUG ),
				'importExport'    => Menu::page_url( Menu::IMPORT_EXPORT_SLUG ),
				'exportLocations' => add_query_arg( '_wpnonce', wp_create_nonce( 'velomalo_export_locations' ), admin_url( 'admin-post.php?action=velomalo_export_locations' ) ),
				'settings'        => Menu::page_url( Menu::SETTINGS_SLUG ),
				'help'            => Menu::page_url( Menu::HELP_SLUG ),
			),
			'capabilities'  => array(
				'createLocations' => current_user_can( 'create_velomalo_locations' ),
				'createLocators'  => current_user_can( 'create_velomalo_locators' ),
				'publishLocators' => current_user_can( 'publish_velomalo_locators' ),
				'deleteLocators'  => current_user_can( 'delete_velomalo_locators' ),
				'publishLocations'=> current_user_can( 'publish_velomalo_locations' ),
				'manageTerms'     => current_user_can( 'manage_velomalo_terms' ),
				'deleteLocations' => current_user_can( 'delete_velomalo_locations' ),
				'manageProviders' => current_user_can( 'manage_velomalo_providers' ),
				'manageSettings'  => current_user_can( 'manage_velomalo_settings' ),
				'importLocations' => current_user_can( 'import_velomalo_locations' ),
				'exportLocations' => current_user_can( 'export_velomalo_locations' ),
			),
			'timezones'     => timezone_identifiers_list(),
			'markerIcons'   => Location_Validator::MARKER_ICONS,
			'markerIconLabels' => array(
				'pin'          => __( 'Pin', 'velox-map-locator' ),
				'office'       => __( 'Office', 'velox-map-locator' ),
				'store'        => __( 'Store', 'velox-map-locator' ),
				'building'     => __( 'Building', 'velox-map-locator' ),
				'shopping-bag' => __( 'Shopping Bag', 'velox-map-locator' ),
				'warehouse'    => __( 'Warehouse', 'velox-map-locator' ),
				'service'      => __( 'Service', 'velox-map-locator' ),
				'tools'        => __( 'Tools', 'velox-map-locator' ),
				'clinic'       => __( 'Clinic', 'velox-map-locator' ),
				'education'    => __( 'Education', 'velox-map-locator' ),
				'restaurant'   => __( 'Restaurant', 'velox-map-locator' ),
				'atm'          => __( 'ATM', 'velox-map-locator' ),
				'dealer'       => __( 'Dealer', 'velox-map-locator' ),
				'star'         => __( 'Star', 'velox-map-locator' ),
			),
			'locatorDefaults' => ( new Locator_Validator() )->defaults(),
			'globalSettings'  => array_replace_recursive( Settings::defaults(), is_array( $settings ) ? $settings : array() ),
			'mapProviderProfiles' => $xyz_profile_choices,
			'googleMaps'    => $google_maps,
			'markerSizes'   => Location_Validator::MARKER_SIZES,
			'markerSizeLabels' => array(
				'small'  => __( 'Small', 'velox-map-locator' ),
				'medium' => __( 'Medium', 'velox-map-locator' ),
				'large'  => __( 'Large', 'velox-map-locator' ),
			),
			'restNamespace' => '/velox-map-locator/v1',
		);

		wp_add_inline_script(
			'velomalo-admin',
			'window.VelomaloAdmin = ' . wp_json_encode( $boot ) . ';',
			'before'
		);

		wp_set_script_translations( 'velomalo-admin', 'velox-map-locator', VELOX_MAP_LOCATOR_PATH . 'languages' );
	}

	/**
	 * Whether the current wp-admin request belongs to Velox Map Locator.
	 *
	 * @return bool
	 */
	private static function is_velox_screen() {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
			return false;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
		return in_array( $page, array( Menu::SLUG, Menu::LOCATIONS_SLUG, Menu::LOCATORS_SLUG, Menu::CLASSIFICATIONS_SLUG, Menu::PROVIDERS_SLUG, Menu::IMPORT_EXPORT_SLUG, Menu::SETTINGS_SLUG, Menu::HELP_SLUG ), true );
	}


	/** Whether the current request is the full Locator Builder. */
	private static function is_locator_builder_request() {
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
		$action = self::current_action();
		return Menu::LOCATORS_SLUG === $page && 'edit' === $action;
	}


	/** Resolve the current admin action, accepting the legacy pre-review query parameter. */
	private static function current_action() {
		if ( isset( $_GET['velomalo_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
			return sanitize_key( wp_unslash( $_GET['velomalo_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.
		}
		if ( isset( $_GET['vml_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Legacy read-only routing state.
			return sanitize_key( wp_unslash( $_GET['vml_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Legacy read-only routing state.
		}
		return '';
	}

	/**
	 * Resolve the application route from the current admin page.
	 *
	 * @return string
	 */
	private static function current_route() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : Menu::SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing state.

		if ( Menu::LOCATIONS_SLUG === $page ) {
			return 'locations';
		}
		if ( Menu::LOCATORS_SLUG === $page ) {
			return 'locators';
		}
		if ( Menu::CLASSIFICATIONS_SLUG === $page ) {
			return 'classifications';
		}
		if ( Menu::PROVIDERS_SLUG === $page ) {
			return 'providers';
		}
		if ( Menu::IMPORT_EXPORT_SLUG === $page ) {
			return 'import-export';
		}
		if ( Menu::SETTINGS_SLUG === $page ) {
			return 'settings';
		}
		if ( Menu::HELP_SLUG === $page ) {
			return 'help';
		}

		return 'overview';
	}
}
