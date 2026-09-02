<?php
/**
 * Plugin Name:       Velox Map Locator
 * Description:       Build polished, reusable location directories and interactive map locators for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Velox Plugins
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       velox-map-locator
 *
 * @package VeloxMapLocator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VELOX_MAP_LOCATOR_VERSION', '1.0.0' );
define( 'VELOX_MAP_LOCATOR_DATA_VERSION', 3 );
define( 'VELOX_MAP_LOCATOR_FILE', __FILE__ );
define( 'VELOX_MAP_LOCATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'VELOX_MAP_LOCATOR_URL', plugin_dir_url( __FILE__ ) );

require_once VELOX_MAP_LOCATOR_PATH . 'includes/autoload.php';

register_activation_hook( VELOX_MAP_LOCATOR_FILE, array( 'VeloxPlugins\\VeloxMapLocator\\Activator', 'activate' ) );
register_deactivation_hook( VELOX_MAP_LOCATOR_FILE, array( 'VeloxPlugins\\VeloxMapLocator\\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		VeloxPlugins\VeloxMapLocator\Plugin::instance()->run();
	}
);
