<?php
/**
 * Locator repository contract.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Repositories;

use VeloxPlugins\VeloxMapLocator\Domain\Locator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence boundary for Locator records.
 */
interface Locator_Repository_Interface {

	/**
	 * Find a Locator by ID.
	 *
	 * @param int $locator_id Locator ID.
	 * @return Locator|null
	 */
	public function find( $locator_id );

	/**
	 * Query Locators.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public function query( $args = array() );

	/**
	 * Create a Locator.
	 *
	 * @param array<string,mixed> $data Normalized data.
	 * @return Locator|\WP_Error
	 */
	public function create( $data );

	/**
	 * Update a Locator.
	 *
	 * @param int                 $locator_id Locator ID.
	 * @param array<string,mixed> $data       Normalized data.
	 * @return Locator|\WP_Error
	 */
	public function update( $locator_id, $data );

	/**
	 * Move a Locator to Trash.
	 *
	 * @param int $locator_id Locator ID.
	 * @return bool|\WP_Error
	 */
	public function trash( $locator_id );

	/**
	 * Restore a trashed Locator.
	 *
	 * @param int $locator_id Locator ID.
	 * @return Locator|\WP_Error
	 */
	public function restore( $locator_id );
}
