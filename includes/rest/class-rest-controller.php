<?php
/**
 * Shared REST controller helpers.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base helpers for Velox admin REST endpoints.
 */
abstract class Rest_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'velox-map-locator/v1';

	/**
	 * Return a standard permission error.
	 *
	 * @return \WP_Error
	 */
	protected function permission_error() {
		return new \WP_Error(
			'velomalo_rest_forbidden',
			__( 'You do not have permission to perform this action.', 'velox-map-locator' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Convert domain errors into REST responses when needed.
	 *
	 * @param mixed $result Result value.
	 * @return mixed
	 */
	protected function response( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
