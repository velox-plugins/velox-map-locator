<?php
/**
 * Type and Group REST controller.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Rest;

use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;
use VeloxPlugins\VeloxMapLocator\Services\Term_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability-protected classification endpoints for the admin application.
 */
final class Taxonomies_Controller extends Rest_Controller {

	/**
	 * Term service.
	 *
	 * @var Term_Service
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param Term_Service $service Term service.
	 */
	public function __construct( Term_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Register taxonomy routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		foreach ( $this->routes() as $resource => $taxonomy ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/admin/' . $resource,
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function () use ( $taxonomy ) {
							return $this->response( $this->service->query( $taxonomy ) );
						},
						'permission_callback' => array( $this, 'read_permissions_check' ),
					),
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => function ( $request ) use ( $taxonomy ) {
							$result = $this->service->create( $taxonomy, $this->request_data( $request ) );
							if ( is_wp_error( $result ) ) {
								return $result;
							}
							$response = rest_ensure_response( $result );
							$response->set_status( 201 );
							return $response;
						},
						'permission_callback' => array( $this, 'manage_permissions_check' ),
						'args'                => $this->term_args( $taxonomy ),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/admin/' . $resource . '/(?P<id>\d+)',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function ( $request ) use ( $taxonomy ) {
							return $this->response( $this->service->find( $taxonomy, $request['id'] ) );
						},
						'permission_callback' => array( $this, 'read_permissions_check' ),
						'args'                => array( 'id' => $this->id_arg() ),
					),
					array(
						'methods'             => \WP_REST_Server::EDITABLE,
						'callback'            => function ( $request ) use ( $taxonomy ) {
							return $this->response( $this->service->update( $taxonomy, $request['id'], $this->request_data( $request ) ) );
						},
						'permission_callback' => array( $this, 'manage_permissions_check' ),
						'args'                => array_merge( array( 'id' => $this->id_arg() ), $this->term_args( $taxonomy ) ),
					),
					array(
						'methods'             => \WP_REST_Server::DELETABLE,
						'callback'            => function ( $request ) use ( $taxonomy ) {
							$result = $this->service->delete( $taxonomy, $request['id'] );
							return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true, 'id' => absint( $request['id'] ) ) );
						},
						'permission_callback' => array( $this, 'manage_permissions_check' ),
						'args'                => array( 'id' => $this->id_arg() ),
					),
				)
			);
		}
	}

	/**
	 * Check whether classifications may be read/assigned.
	 *
	 * @return true|\WP_Error
	 */
	public function read_permissions_check() {
		return current_user_can( 'edit_velomalo_locations' ) ? true : $this->permission_error();
	}

	/**
	 * Check term-management capability.
	 *
	 * @return true|\WP_Error
	 */
	public function manage_permissions_check() {
		return current_user_can( 'manage_velomalo_terms' ) ? true : $this->permission_error();
	}

	/**
	 * Map REST resource names to taxonomies.
	 *
	 * @return array<string,string>
	 */
	private function routes() {
		return array(
			'types'  => Taxonomies::TYPE,
			'groups' => Taxonomies::GROUP,
		);
	}

	/**
	 * Extract recognized term values.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function request_data( $request ) {
		$data = array();
		foreach ( array( 'name', 'slug', 'description', 'parent', 'marker' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}
		return $data;
	}

	/**
	 * Term body arguments.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string,mixed>
	 */
	private function term_args( $taxonomy ) {
		$args = array(
			'name'        => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
		);

		if ( Taxonomies::GROUP === $taxonomy ) {
			$args['parent'] = array( 'type' => 'integer', 'minimum' => 0 );
		}
		if ( Taxonomies::TYPE === $taxonomy ) {
			$args['marker'] = array( 'type' => 'object' );
		}

		return $args;
	}

	/**
	 * Standard ID argument.
	 *
	 * @return array<string,mixed>
	 */
	private function id_arg() {
		return array(
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);
	}
}
