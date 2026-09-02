<?php
/**
 * Streamed CSV export handler.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Admin;

use VeloxPlugins\VeloxMapLocator\Repositories\WP_Location_Repository;
use VeloxPlugins\VeloxMapLocator\Services\Import_Export_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams Location exports without buffering the full dataset in memory.
 */
final class Export_Handler {

	/** Register admin-post hook. */
	public static function register() {
		add_action( 'admin_post_velomalo_export_locations', array( self::class, 'download' ) );
	}

	/** Stream the CSV and end the request. */
	public static function download() {
		if ( ! current_user_can( 'export_velomalo_locations' ) ) {
			wp_die( esc_html__( 'You do not have permission to export Locations.', 'velox-map-locator' ), esc_html__( 'Export forbidden', 'velox-map-locator' ), array( 'response' => 403 ) );
		}
		check_admin_referer( 'velomalo_export_locations' );

		$repository = new WP_Location_Repository();
		$filename   = 'velox-map-locator-locations-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// UTF-8 BOM improves spreadsheet compatibility without changing CSV semantics.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary BOM for a CSV download, not HTML.
		echo "\xEF\xBB\xBF";

		$columns = Import_Export_Service::columns();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 4180 CSV data is encoded for the download response, not HTML.
		echo Import_Export_Service::csv_line( array_keys( $columns ) );

		$page = 1;
		do {
			$result = $repository->query(
				array(
					'page'     => $page,
					'per_page' => 100,
					'status'   => array( 'publish', 'draft' ),
					'orderby'  => 'ID',
					'order'    => 'ASC',
				)
			);
			foreach ( $result['items'] as $location ) {
				$row = Import_Export_Service::export_row( $location->to_array() );
				$ordered = array();
				foreach ( array_keys( $columns ) as $column ) {
					$ordered[] = Import_Export_Service::protect_csv_cell( isset( $row[ $column ] ) ? $row[ $column ] : '' );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 4180 CSV data is encoded for the download response, not HTML.
				echo Import_Export_Service::csv_line( $ordered );
			}
			++$page;
		} while ( $page <= (int) $result['total_pages'] );

		exit;
	}
}
