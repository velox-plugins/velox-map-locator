<?php
/**
 * Plugin deactivation lifecycle.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation is deliberately non-destructive.
 */
final class Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally empty. User data and configuration must survive deactivation.
	}
}
