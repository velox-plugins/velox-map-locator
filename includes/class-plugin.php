<?php
/**
 * Main plugin coordinator.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator;

use VeloxPlugins\VeloxMapLocator\Admin\Assets;
use VeloxPlugins\VeloxMapLocator\Admin\Export_Handler;
use VeloxPlugins\VeloxMapLocator\Admin\Menu;
use VeloxPlugins\VeloxMapLocator\Frontend\Assets as Frontend_Assets;
use VeloxPlugins\VeloxMapLocator\Frontend\Block;
use VeloxPlugins\VeloxMapLocator\Frontend\Shortcode;
use VeloxPlugins\VeloxMapLocator\Content\Meta_Registry;
use VeloxPlugins\VeloxMapLocator\Content\Post_Types;
use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;
use VeloxPlugins\VeloxMapLocator\Repositories\WP_Location_Repository;
use VeloxPlugins\VeloxMapLocator\Repositories\WP_Locator_Repository;
use VeloxPlugins\VeloxMapLocator\Rest\Import_Export_Controller;
use VeloxPlugins\VeloxMapLocator\Rest\Locations_Controller;
use VeloxPlugins\VeloxMapLocator\Rest\Locators_Controller;
use VeloxPlugins\VeloxMapLocator\Rest\Providers_Controller;
use VeloxPlugins\VeloxMapLocator\Rest\Settings_Controller;
use VeloxPlugins\VeloxMapLocator\Rest\Taxonomies_Controller;
use VeloxPlugins\VeloxMapLocator\Services\Import_Export_Service;
use VeloxPlugins\VeloxMapLocator\Services\Location_Service;
use VeloxPlugins\VeloxMapLocator\Services\Location_Validator;
use VeloxPlugins\VeloxMapLocator\Services\Migration_Service;
use VeloxPlugins\VeloxMapLocator\Services\Locator_Service;
use VeloxPlugins\VeloxMapLocator\Services\Locator_Validator;
use VeloxPlugins\VeloxMapLocator\Services\Term_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates foundation services and hook registration.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private $running = false;

	/**
	 * Return the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->running ) {
			return;
		}

		$this->running = true;

		add_action( 'init', array( Post_Types::class, 'register' ), 8 );
		add_action( 'init', array( Taxonomies::class, 'register' ), 9 );
		add_action( 'init', array( Meta_Registry::class, 'register' ), 10 );
		add_action( 'init', array( Migration_Service::class, 'maybe_migrate' ), 11 );
		add_action( 'wp_initialize_site', array( Activator::class, 'initialize_new_site' ), 200, 1 );
		add_action( 'admin_init', array( Settings::class, 'register' ) );
		add_action( 'admin_menu', array( Menu::class, 'register' ) );
		Assets::register();
		Export_Handler::register();
		Frontend_Assets::register();
		add_action( 'init', array( Shortcode::class, 'register' ), 20 );
		add_action( 'init', array( Block::class, 'register' ), 21 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the capability-protected admin REST API.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$location_repository = new WP_Location_Repository();
		$location_service    = new Location_Service( $location_repository, new Location_Validator() );

		$locations_controller = new Locations_Controller( $location_service );
		$locations_controller->register_routes();

		$locator_repository = new WP_Locator_Repository();
		$locator_service    = new Locator_Service( $locator_repository, new Locator_Validator() );
		$locators_controller = new Locators_Controller( $locator_service );
		$locators_controller->register_routes();

		$taxonomies_controller = new Taxonomies_Controller( new Term_Service() );
		$taxonomies_controller->register_routes();

		$providers_controller = new Providers_Controller();
		$providers_controller->register_routes();

		$settings_controller = new Settings_Controller();
		$settings_controller->register_routes();

		$import_export_controller = new Import_Export_Controller(
			new Import_Export_Service( $location_service, $location_repository, new Location_Validator(), new Term_Service() )
		);
		$import_export_controller->register_routes();
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
