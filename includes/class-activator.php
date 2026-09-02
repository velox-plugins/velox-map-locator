<?php
/**
 * Plugin activation lifecycle.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator;

use VeloxPlugins\VeloxMapLocator\Content\Capabilities;
use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Services\Migration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation without creating user-facing content.
 */
final class Activator {

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}

			return;
		}

		self::activate_site();
	}

	/**
	 * Initialize plugin defaults when a new multisite site is created under network activation.
	 *
	 * @param \WP_Site $new_site Newly initialized site.
	 * @return void
	 */
	public static function initialize_new_site( $new_site ) {
		if ( ! is_multisite() || ! $new_site instanceof \WP_Site ) {
			return;
		}

		$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
		$plugin_file     = plugin_basename( VELOX_MAP_LOCATOR_FILE );

		if ( ! is_array( $network_plugins ) || ! isset( $network_plugins[ $plugin_file ] ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		self::activate_site();
		restore_current_blog();
	}

	/**
	 * Install per-site defaults and capabilities.
	 *
	 * @return void
	 */
	public static function activate_site() {
		$has_existing_data = Migration_Service::has_existing_data();

		if ( $has_existing_data ) {
			Migration_Service::maybe_migrate();
		}

		Settings::install_defaults();
		Capabilities::grant_default_roles();

		if ( ! $has_existing_data ) {
			add_option( 'velomalo_data_version', VELOX_MAP_LOCATOR_DATA_VERSION, '', false );
		}
	}
}
