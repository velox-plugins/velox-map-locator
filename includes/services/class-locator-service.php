<?php
/**
 * Locator application service.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Domain\Locator;
use VeloxPlugins\VeloxMapLocator\Repositories\Locator_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates Locator validation, business rules and persistence.
 */
final class Locator_Service {

	/** @var Locator_Repository_Interface */
	private $repository;

	/** @var Locator_Validator */
	private $validator;

	/** @var Provider_Registry */
	private $providers;

	/** Constructor. */
	public function __construct( Locator_Repository_Interface $repository, Locator_Validator $validator, Provider_Registry $providers = null ) {
		$this->repository = $repository;
		$this->validator  = $validator;
		$this->providers  = $providers ? $providers : new Provider_Registry();
	}

	/** Find one Locator. */
	public function find( $locator_id ) {
		return $this->repository->find( $locator_id );
	}

	/** Query Locators. */
	public function query( $args = array() ) {
		return $this->repository->query( $args );
	}

	/** Create a Locator. */
	public function create( $input ) {
		$data = $this->validator->normalize( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$provider_error = $this->validate_provider_for_publication( $data );
		if ( is_wp_error( $provider_error ) ) {
			return $provider_error;
		}
		$locator = $this->repository->create( $data );
		if ( ! is_wp_error( $locator ) && $locator ) {
			do_action( 'velox_map_locator_locator_created', $locator->get_id(), $locator->to_array() );
		}
		return $locator;
	}

	/** Update a Locator. */
	public function update( $locator_id, $input ) {
		$locator_id = absint( $locator_id );
		$existing   = $this->repository->find( $locator_id );
		if ( ! $existing ) {
			return new \WP_Error( 'velomalo_locator_not_found', __( 'Locator not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}
		$data = $this->validator->normalize( $input, $existing->to_array() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$provider_error = $this->validate_provider_for_publication( $data );
		if ( is_wp_error( $provider_error ) ) {
			return $provider_error;
		}
		$locator = $this->repository->update( $locator_id, $data );
		if ( ! is_wp_error( $locator ) && $locator ) {
			do_action( 'velox_map_locator_locator_updated', $locator->get_id(), $locator->to_array() );
		}
		return $locator;
	}


	/**
	 * Build a normalized in-memory Locator for the admin live preview without persisting changes.
	 *
	 * @param int                 $locator_id Locator ID.
	 * @param array<string,mixed> $input      Unsaved Locator values.
	 * @return Locator|\WP_Error
	 */
	public function preview( $locator_id, $input ) {
		$locator_id = absint( $locator_id );
		$existing   = $this->repository->find( $locator_id );
		if ( ! $existing ) {
			return new \WP_Error( 'velomalo_locator_not_found', __( 'Locator not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		$data = $this->validator->normalize( is_array( $input ) ? $input : array(), $existing->to_array() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$existing_data = $existing->to_array();
		$data['id'] = $locator_id;
		$data['modified_gmt'] = isset( $existing_data['modified_gmt'] ) ? $existing_data['modified_gmt'] : '';
		return new Locator( $data );
	}

	/** Trash a Locator. */
	public function trash( $locator_id ) {
		$locator_id = absint( $locator_id );
		$result     = $this->repository->trash( $locator_id );
		if ( true === $result ) {
			do_action( 'velox_map_locator_locator_deleted', $locator_id );
		}
		return $result;
	}

	/** Restore a Locator. */
	public function restore( $locator_id ) {
		$locator = $this->repository->restore( absint( $locator_id ) );
		if ( ! is_wp_error( $locator ) && $locator ) {
			do_action( 'velox_map_locator_locator_restored', $locator->get_id(), $locator->to_array() );
		}
		return $locator;
	}

	/** Return defaults for admin creation. */
	public function default_config() {
		return $this->validator->defaults();
	}

	/**
	 * Prevent a published map Locator from referencing an unavailable provider configuration.
	 *
	 * Drafts remain saveable so an administrator can finish provider setup later.
	 *
	 * @param array<string,mixed> $data Normalized Locator data.
	 * @return true|\WP_Error
	 */
	private function validate_provider_for_publication( $data ) {
		if ( ! is_array( $data ) || 'publish' !== ( isset( $data['status'] ) ? $data['status'] : '' ) ) {
			return true;
		}

		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();
		$layout = isset( $config['layout']['mode'] ) ? $config['layout']['mode'] : 'list_only';
		if ( 'list_only' === $layout ) {
			return true;
		}

		$provider_id = isset( $config['map']['provider'] ) ? sanitize_key( (string) $config['map']['provider'] ) : 'osm';
		$provider    = $this->providers->get( $provider_id );
		if ( ! $provider ) {
			return new \WP_Error(
				'velomalo_locator_provider_unavailable',
				__( 'The selected map provider is not available.', 'velox-map-locator' ),
				array( 'status' => 400, 'field' => 'config.map.provider' )
			);
		}

		if ( ! $provider->is_configured( $config ) ) {
			$field = 'xyz' === $provider_id ? 'config.map.provider_profile_id' : 'config.map.provider';
			return new \WP_Error(
				'velomalo_locator_provider_not_configured',
				sprintf(
					/* translators: %s: map provider name. */
					__( '%s is not fully configured. Complete its Map Providers settings before publishing this Locator.', 'velox-map-locator' ),
					$provider->get_label()
				),
				array( 'status' => 400, 'field' => $field )
			);
		}

		return true;
	}
}
