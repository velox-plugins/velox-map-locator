<?php
/**
 * Incremental data migrations.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Runs safe, versioned data migrations once. */
final class Migration_Service {

	/** Whether this site already contains data created by this release line. */
	public static function has_existing_data() {
		return false !== get_option( 'velomalo_data_version', false );
	}

	/** Run pending migrations. */
	public static function maybe_migrate() {
		$stored_version = get_option( 'velomalo_data_version', false );

		if ( false === $stored_version ) {
			return;
		}

		$current = absint( $stored_version );

		if ( $current < 2 ) {
			self::migrate_sidebar_default_to_25();
		}

		update_option( 'velomalo_data_version', VELOX_MAP_LOCATOR_DATA_VERSION, false );
	}

	/**
	 * Stage 6J changed the original untouched Split sidebar default from 38% to 25%.
	 * Only the exact legacy default value is migrated; other custom widths are retained.
	 */
	private static function migrate_sidebar_default_to_25() {
		$ids = get_posts(
			array(
				'post_type'      => 'velomalo_locator',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $locator_id ) {
			$config = get_post_meta( $locator_id, '_velomalo_config', true );
			if ( ! is_array( $config ) || ! isset( $config['layout']['sidebar_width'] ) || 38 !== absint( $config['layout']['sidebar_width'] ) ) {
				continue;
			}
			$config['layout']['sidebar_width'] = 25;
			update_post_meta( $locator_id, '_velomalo_config', $config );
		}
	}
}
