<?php
/**
 * Velox Map Locator admin application entry points.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the application shell pages.
 */
final class Menu {

	const SLUG                 = 'velox-map-locator';
	const LOCATIONS_SLUG       = 'velox-map-locator-locations';
	const LOCATORS_SLUG        = 'velox-map-locator-locators';
	const CLASSIFICATIONS_SLUG = 'velox-map-locator-classifications';
	const PROVIDERS_SLUG       = 'velox-map-locator-providers';
	const IMPORT_EXPORT_SLUG   = 'velox-map-locator-import-export';
	const SETTINGS_SLUG        = 'velox-map-locator-settings';
	const HELP_SLUG            = 'velox-map-locator-help';

	/**
	 * Register top-level and active Velox pages.
	 *
	 * @return void
	 */
	public static function register() {
		add_menu_page(
			__( 'Velox Map Locator', 'velox-map-locator' ),
			__( 'Velox Map Locator', 'velox-map-locator' ),
			'edit_velomalo_locations',
			self::SLUG,
			array( self::class, 'render_app' ),
			'dashicons-location-alt',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Overview', 'velox-map-locator' ),
			__( 'Overview', 'velox-map-locator' ),
			'edit_velomalo_locations',
			self::SLUG,
			array( self::class, 'render_app' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Locations', 'velox-map-locator' ),
			__( 'Locations', 'velox-map-locator' ),
			'edit_velomalo_locations',
			self::LOCATIONS_SLUG,
			array( self::class, 'render_app' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Locators', 'velox-map-locator' ),
			__( 'Locators', 'velox-map-locator' ),
			'edit_velomalo_locators',
			self::LOCATORS_SLUG,
			array( self::class, 'render_app' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Types & Groups', 'velox-map-locator' ),
			__( 'Types & Groups', 'velox-map-locator' ),
			'edit_velomalo_locations',
			self::CLASSIFICATIONS_SLUG,
			array( self::class, 'render_app' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Map Providers', 'velox-map-locator' ),
			__( 'Map Providers', 'velox-map-locator' ),
			'manage_velomalo_providers',
			self::PROVIDERS_SLUG,
			array( self::class, 'render_app' )
		);



		add_submenu_page(
			self::SLUG,
			__( 'Import / Export', 'velox-map-locator' ),
			__( 'Import / Export', 'velox-map-locator' ),
			'import_velomalo_locations',
			self::IMPORT_EXPORT_SLUG,
			array( self::class, 'render_app' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'velox-map-locator' ),
			__( 'Settings', 'velox-map-locator' ),
			'manage_velomalo_settings',
			self::SETTINGS_SLUG,
			array( self::class, 'render_app' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Help', 'velox-map-locator' ),
			__( 'Help', 'velox-map-locator' ),
			'edit_velomalo_locations',
			self::HELP_SLUG,
			array( self::class, 'render_app' )
		);
	}

	/**
	 * Render the React application mount point.
	 *
	 * @return void
	 */
	public static function render_app() {
		if ( ! current_user_can( 'edit_velomalo_locations' ) && ! current_user_can( 'edit_velomalo_locators' ) && ! current_user_can( 'manage_velomalo_providers' ) && ! current_user_can( 'manage_velomalo_settings' ) && ! current_user_can( 'import_velomalo_locations' ) && ! current_user_can( 'export_velomalo_locations' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'velox-map-locator' ) );
		}
		?>
		<div id="vml-admin-app" class="vml-admin-root">
			<noscript>
				<div class="notice notice-error inline">
					<p><?php echo esc_html__( 'Velox Map Locator requires JavaScript to use its administration interface.', 'velox-map-locator' ); ?></p>
				</div>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Build an admin.php URL for a Velox page.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function page_url( $slug ) {
		return admin_url( 'admin.php?page=' . sanitize_key( $slug ) );
	}
}
