<?php
/**
 * Role capability management.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grants and removes Velox Map Locator capabilities.
 */
final class Capabilities {

	/**
	 * Grant capabilities to the default WordPress roles.
	 *
	 * @return void
	 */
	public static function grant_default_roles() {
		self::grant_to_role( 'administrator', self::administrator_capabilities() );
		self::grant_to_role( 'editor', self::editor_capabilities() );
	}

	/**
	 * Remove all plugin capabilities from default roles.
	 *
	 * @return void
	 */
	public static function remove_default_roles() {
		$capabilities = array_unique(
			array_merge(
				self::administrator_capabilities(),
				self::editor_capabilities()
			)
		);

		self::remove_from_role( 'administrator', $capabilities );
		self::remove_from_role( 'editor', $capabilities );
	}

	/**
	 * Administrator capabilities.
	 *
	 * @return string[]
	 */
	public static function administrator_capabilities() {
		return array_values(
			array_unique(
				array_merge(
					self::content_capabilities(),
					array(
						'manage_velomalo_terms',
						'manage_velomalo_settings',
						'manage_velomalo_providers',
						'import_velomalo_locations',
						'export_velomalo_locations',
					)
				)
			)
		);
	}

	/**
	 * Editor capabilities.
	 *
	 * @return string[]
	 */
	public static function editor_capabilities() {
		return array_values(
			array_unique(
				array_merge(
					self::content_capabilities(),
					array( 'manage_velomalo_terms' )
				)
			)
		);
	}

	/**
	 * Capabilities shared by Location and Locator content.
	 *
	 * @return string[]
	 */
	private static function content_capabilities() {
		return array_values(
			array_unique(
				array_merge(
					array_values( Post_Types::location_capabilities() ),
					array_values( Post_Types::locator_capabilities() )
				)
			)
		);
	}

	/**
	 * Grant capabilities to a role when it exists.
	 *
	 * @param string   $role_name    Role slug.
	 * @param string[] $capabilities Capabilities to grant.
	 * @return void
	 */
	private static function grant_to_role( $role_name, $capabilities ) {
		$role = get_role( $role_name );

		if ( ! $role ) {
			return;
		}

		foreach ( $capabilities as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * Remove capabilities from a role when it exists.
	 *
	 * @param string   $role_name    Role slug.
	 * @param string[] $capabilities Capabilities to remove.
	 * @return void
	 */
	private static function remove_from_role( $role_name, $capabilities ) {
		$role = get_role( $role_name );

		if ( ! $role ) {
			return;
		}

		foreach ( $capabilities as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}
