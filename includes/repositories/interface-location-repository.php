<?php
/**
 * Location repository contract.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Repositories;

use VeloxPlugins\VeloxMapLocator\Domain\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence boundary for Location records.
 */
interface Location_Repository_Interface {

	/**
	 * Find a Location by ID.
	 *
	 * @param int $location_id Location ID.
	 * @return Location|null
	 */
	public function find( $location_id );

	/**
	 * Query Locations.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public function query( $args = array() );

	/**
	 * Create a Location.
	 *
	 * @param array<string,mixed> $data Normalized Location data.
	 * @return Location|\WP_Error
	 */
	public function create( $data );

	/**
	 * Update a Location.
	 *
	 * @param int                 $location_id Location ID.
	 * @param array<string,mixed> $data        Normalized Location data.
	 * @return Location|\WP_Error
	 */
	public function update( $location_id, $data );

	/**
	 * Move a Location to Trash.
	 *
	 * @param int $location_id Location ID.
	 * @return bool|\WP_Error
	 */
	public function trash( $location_id );

	/**
	 * Restore a trashed Location.
	 *
	 * @param int $location_id Location ID.
	 * @return Location|\WP_Error
	 */
	public function restore( $location_id );

	/**
	 * Find a Location with a matching external ID.
	 *
	 * @param string $external_id External identifier.
	 * @param int    $exclude_id  Optional Location ID to exclude.
	 * @return Location|null
	 */
	public function find_by_external_id( $external_id, $exclude_id = 0 );
}
