<?php
/**
 * Locator REST API controller.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Rest;

use VeloxPlugins\VeloxMapLocator\Content\Post_Types;
use VeloxPlugins\VeloxMapLocator\Domain\Locator;
use VeloxPlugins\VeloxMapLocator\Frontend\Renderer;
use VeloxPlugins\VeloxMapLocator\Services\Locator_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability-protected Locator CRUD endpoints.
 */
final class Locators_Controller extends Rest_Controller {

	/** @var Locator_Service */
	private $service;

	/** Constructor. */
	public function __construct( Locator_Service $service ) {
		$this->service = $service;
	}

	/** Register routes. */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/locators',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->collection_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->locator_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/locators/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array_merge( array( 'id' => $this->id_arg() ), $this->locator_args() ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
			)
		);


		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/locators/(?P<id>\d+)/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array_merge( array( 'id' => $this->id_arg() ), $this->locator_args() ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/locators/(?P<id>\d+)/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_item' ),
				'permission_callback' => array( $this, 'restore_item_permissions_check' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
	}

	/** List Locators. */
	public function get_items( $request ) {
		$args = array(
			'page'     => $request['page'],
			'per_page' => $request['per_page'],
			'search'   => $request['search'],
			'status'   => $request['status'] ? array( $request['status'] ) : array( 'publish', 'draft', 'private', 'pending', 'future' ),
		);
		if ( ! current_user_can( 'edit_others_velomalo_locators' ) ) {
			$args['author'] = get_current_user_id();
		}
		$result   = $this->service->query( $args );
		$response = rest_ensure_response( array_map( array( $this, 'prepare_locator' ), $result['items'] ) );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) $result['total_pages'] );
		return $response;
	}

	/** Get one Locator. */
	public function get_item( $request ) {
		$locator = $this->service->find( $request['id'] );
		return $locator ? rest_ensure_response( $this->prepare_locator( $locator ) ) : new \WP_Error( 'velomalo_locator_not_found', __( 'Locator not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
	}

	/** Create Locator. */
	public function create_item( $request ) {
		$data = $this->request_locator_data( $request );
		if ( ! isset( $data['config'] ) ) {
			$data['config'] = $this->service->default_config();
		}
		$locator = $this->service->create( $data );
		if ( is_wp_error( $locator ) ) {
			return $locator;
		}
		$response = rest_ensure_response( $this->prepare_locator( $locator ) );
		$response->set_status( 201 );
		return $response;
	}

	/** Update Locator. */
	public function update_item( $request ) {
		$locator = $this->service->update( $request['id'], $this->request_locator_data( $request ) );
		return is_wp_error( $locator ) ? $locator : rest_ensure_response( $this->prepare_locator( $locator ) );
	}


	/** Render the unsaved Locator configuration using the real public renderer. */
	public function preview_item( $request ) {
		$locator = $this->service->preview( $request['id'], $this->request_locator_data( $request ) );
		if ( is_wp_error( $locator ) ) {
			return $locator;
		}
		return rest_ensure_response(
			array(
				'html' => Renderer::render_preview_locator( $locator ),
			)
		);
	}

	/** Trash Locator. */
	public function delete_item( $request ) {
		$result = $this->service->trash( $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true, 'id' => absint( $request['id'] ) ) );
	}

	/** Restore Locator. */
	public function restore_item( $request ) {
		$locator = $this->service->restore( $request['id'] );
		return is_wp_error( $locator ) ? $locator : rest_ensure_response( $this->prepare_locator( $locator ) );
	}

	/** Permissions: collection. */
	public function get_items_permissions_check() {
		return current_user_can( 'edit_velomalo_locators' ) ? true : $this->permission_error();
	}

	/** Permissions: create. */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'create_velomalo_locators' ) ) {
			return $this->permission_error();
		}
		if ( 'publish' === $request->get_param( 'status' ) && ! current_user_can( 'publish_velomalo_locators' ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/** Permissions: read. */
	public function get_item_permissions_check( $request ) {
		$locator = $this->service->find( $request['id'] );
		if ( ! $locator ) {
			return true;
		}
		return current_user_can( 'edit_post', $locator->get_id() ) ? true : $this->permission_error();
	}

	/** Permissions: update. */
	public function update_item_permissions_check( $request ) {
		$locator = $this->service->find( $request['id'] );
		if ( ! $locator || ! current_user_can( 'edit_post', $locator->get_id() ) ) {
			return $this->permission_error();
		}
		if ( 'publish' === $request->get_param( 'status' ) && ! current_user_can( 'publish_velomalo_locators' ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/** Permissions: delete. */
	public function delete_item_permissions_check( $request ) {
		$locator = $this->service->find( $request['id'] );
		return $locator && current_user_can( 'delete_post', $locator->get_id() ) ? true : $this->permission_error();
	}

	/** Permissions: restore. */
	public function restore_item_permissions_check( $request ) {
		$locator = $this->service->find( $request['id'] );
		return $locator && current_user_can( 'delete_post', $locator->get_id() ) ? true : $this->permission_error();
	}

	/** Prepare response. */
	public function prepare_locator( Locator $locator ) {
		$data = $locator->to_array();
		return array(
			'id'           => $locator->get_id(),
			'name'         => $locator->get_name(),
			'status'       => $locator->get_status(),
			'config'       => $locator->get_config(),
			'modified_gmt' => isset( $data['modified_gmt'] ) ? $data['modified_gmt'] : '',
			'shortcode'    => '[velox_map_locator id="' . $locator->get_id() . '"]',
		);
	}

	/** Pull known body data. */
	private function request_locator_data( $request ) {
		$data = array();
		foreach ( array( 'name', 'status', 'config' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}
		return $data;
	}

	/** Collection args. */
	private function collection_args() {
		return array(
			'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint' ),
			'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint' ),
			'search'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'status'   => array( 'type' => 'string', 'default' => '', 'enum' => array( '', 'publish', 'draft', 'trash' ), 'sanitize_callback' => 'sanitize_key' ),
		);
	}

	/** Locator body args. */
	private function locator_args() {
		return array(
			'name'   => array( 'type' => 'string' ),
			'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish' ) ),
			'config' => array( 'type' => 'object' ),
		);
	}

	/** ID argument. */
	private function id_arg() {
		return array( 'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' );
	}
}
