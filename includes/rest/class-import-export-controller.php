<?php
/**
 * CSV import REST controller.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Rest;

use VeloxPlugins\VeloxMapLocator\Services\Import_Export_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability-protected import workflow endpoints.
 */
final class Import_Export_Controller extends Rest_Controller {

	/** @var Import_Export_Service */
	private $service;

	/** Constructor. */
	public function __construct( Import_Export_Service $service ) {
		$this->service = $service;
	}

	/** Register routes. */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/import/upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'import_permissions_check' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/import/(?P<session>[a-zA-Z0-9_-]+)/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_import' ),
				'permission_callback' => array( $this, 'import_permissions_check' ),
				'args'                => array(
					'session'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'mapping'      => array( 'type' => 'object', 'required' => true ),
					'mode'         => array( 'type' => 'string', 'default' => 'upsert', 'enum' => array( 'create', 'upsert' ) ),
					'create_terms' => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/import/(?P<session>[a-zA-Z0-9_-]+)/commit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'commit_import' ),
				'permission_callback' => array( $this, 'import_permissions_check' ),
				'args'                => array(
					'session' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'offset'  => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/** Stage uploaded CSV. */
	public function upload( $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) ? $files['file'] : array();
		return $this->response( $this->service->stage_upload( $file ) );
	}

	/** Validate mapping and all rows without writes. */
	public function validate_import( $request ) {
		return $this->response(
			$this->service->validate_session(
				$request['session'],
				$request->get_param( 'mapping' ),
				$request->get_param( 'mode' ),
				(bool) $request->get_param( 'create_terms' )
			)
		);
	}

	/** Commit next chunk. */
	public function commit_import( $request ) {
		return $this->response( $this->service->commit_chunk( $request['session'], $request['offset'] ) );
	}

	/** Import capability check. */
	public function import_permissions_check() {
		return current_user_can( 'import_velomalo_locations' ) ? true : $this->permission_error();
	}
}
