<?php
/**
 * Location application service.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Domain\Location;
use VeloxPlugins\VeloxMapLocator\Repositories\Location_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates Location validation, business rules and persistence.
 */
final class Location_Service {

	/**
	 * Repository.
	 *
	 * @var Location_Repository_Interface
	 */
	private $repository;

	/**
	 * Validator.
	 *
	 * @var Location_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Location_Repository_Interface $repository Location repository.
	 * @param Location_Validator            $validator  Location validator.
	 */
	public function __construct( Location_Repository_Interface $repository, Location_Validator $validator ) {
		$this->repository = $repository;
		$this->validator  = $validator;
	}

	/**
	 * Find a Location.
	 *
	 * @param int $location_id Location ID.
	 * @return Location|null
	 */
	public function find( $location_id ) {
		return $this->repository->find( $location_id );
	}

	/**
	 * Query Locations.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public function query( $args = array() ) {
		return $this->repository->query( $args );
	}

	/**
	 * Create a Location.
	 *
	 * @param array<string,mixed> $input Untrusted input.
	 * @return Location|\WP_Error
	 */
	public function create( $input ) {
		$data = $this->validator->normalize( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$unique = $this->validate_external_id( $data['external_id'] );
		if ( is_wp_error( $unique ) ) {
			return $unique;
		}

		$location = $this->repository->create( $data );

		if ( ! is_wp_error( $location ) && $location ) {
			do_action( 'velox_map_locator_location_created', $location->get_id(), $location->to_array() );
		}

		return $location;
	}

	/**
	 * Update a Location.
	 *
	 * @param int                 $location_id Location ID.
	 * @param array<string,mixed> $input       Untrusted input.
	 * @return Location|\WP_Error
	 */
	public function update( $location_id, $input ) {
		$location_id = absint( $location_id );
		$existing    = $this->repository->find( $location_id );

		if ( ! $existing ) {
			return new \WP_Error( 'velomalo_location_not_found', __( 'Location not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		$data = $this->validator->normalize( $input, $existing->to_array() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$unique = $this->validate_external_id( $data['external_id'], $location_id );
		if ( is_wp_error( $unique ) ) {
			return $unique;
		}

		$location = $this->repository->update( $location_id, $data );

		if ( ! is_wp_error( $location ) && $location ) {
			do_action( 'velox_map_locator_location_updated', $location->get_id(), $location->to_array() );
		}

		return $location;
	}

	/**
	 * Duplicate a Location as a new Draft.
	 *
	 * @param int $location_id Source Location ID.
	 * @return Location|\WP_Error
	 */
	public function duplicate( $location_id ) {
		$location_id = absint( $location_id );
		$existing    = $this->repository->find( $location_id );

		if ( ! $existing ) {
			return new \WP_Error( 'velomalo_location_not_found', __( 'Location not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		$data                = $existing->to_array();
		/* translators: %s: Original Location name. */
		$data['name']        = sprintf( __( '%s — Copy', 'velox-map-locator' ), $existing->get_name() ? $existing->get_name() : __( 'Untitled Location', 'velox-map-locator' ) );
		$data['status']      = 'draft';
		$data['external_id'] = '';

		unset( $data['id'], $data['modified_gmt'] );

		$duplicate = $this->create( $data );

		if ( ! is_wp_error( $duplicate ) && $duplicate ) {
			do_action( 'velox_map_locator_location_duplicated', $duplicate->get_id(), $location_id, $duplicate->to_array() );
		}

		return $duplicate;
	}

	/**
	 * Move a Location to Trash.
	 *
	 * @param int $location_id Location ID.
	 * @return bool|\WP_Error
	 */
	public function trash( $location_id ) {
		$location_id = absint( $location_id );
		$result      = $this->repository->trash( $location_id );

		if ( true === $result ) {
			do_action( 'velox_map_locator_location_deleted', $location_id );
		}

		return $result;
	}

	/**
	 * Restore a trashed Location.
	 *
	 * @param int $location_id Location ID.
	 * @return Location|\WP_Error
	 */
	public function restore( $location_id ) {
		$location_id = absint( $location_id );
		$location    = $this->repository->restore( $location_id );

		if ( ! is_wp_error( $location ) && $location ) {
			do_action( 'velox_map_locator_location_restored', $location->get_id(), $location->to_array() );
		}

		return $location;
	}

	/**
	 * Ensure a non-empty external ID is unique.
	 *
	 * @param string $external_id External ID.
	 * @param int    $exclude_id  Optional Location ID to exclude.
	 * @return true|\WP_Error
	 */
	private function validate_external_id( $external_id, $exclude_id = 0 ) {
		if ( '' === $external_id ) {
			return true;
		}

		$match = $this->repository->find_by_external_id( $external_id, $exclude_id );
		if ( $match ) {
			return new \WP_Error(
				'velomalo_duplicate_external_id',
				__( 'Another Location already uses this external ID.', 'velox-map-locator' ),
				array(
					'status' => 409,
					'field'  => 'external_id',
				)
			);
		}

		return true;
	}
}
