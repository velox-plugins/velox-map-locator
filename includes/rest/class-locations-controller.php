<?php
/**
 * Location REST API controller.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Rest;

use VeloxPlugins\VeloxMapLocator\Content\Post_Types;
use VeloxPlugins\VeloxMapLocator\Domain\Location;
use VeloxPlugins\VeloxMapLocator\Services\Location_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability-protected CRUD endpoints used by the Velox admin application.
 */
final class Locations_Controller extends Rest_Controller {

	/**
	 * Location service.
	 *
	 * @var Location_Service
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param Location_Service $service Location service.
	 */
	public function __construct( Location_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Register Location routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/locations',
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
					'args'                => $this->location_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/locations/(?P<id>\d+)',
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
					'args'                => array_merge( array( 'id' => $this->id_arg() ), $this->location_args() ),
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
			'/admin/locations/(?P<id>\d+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_item' ),
				'permission_callback' => array( $this, 'duplicate_item_permissions_check' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/locations/(?P<id>\d+)/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_item' ),
				'permission_callback' => array( $this, 'restore_item_permissions_check' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
	}

	/**
	 * List Locations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$query_args = array(
			'page'     => $request['page'],
			'per_page' => $request['per_page'],
			'search'   => $request['search'],
			'status'   => $request['status'] ? array( $request['status'] ) : array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'type_id'  => $request['type_id'],
			'group_id' => $request['group_id'],
			'country'  => $request['country'],
			'city'     => $request['city'],
			'orderby'  => $request['orderby'],
			'order'    => $request['order'],
		);

		if ( ! current_user_can( 'edit_others_velomalo_locations' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$result = $this->service->query( $query_args );

		$items = array_map( array( $this, 'prepare_location' ), $result['items'] );
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) $result['total_pages'] );

		return $response;
	}

	/**
	 * Get one Location.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$location = $this->service->find( $request['id'] );
		if ( ! $location ) {
			return new \WP_Error( 'velomalo_location_not_found', __( 'Location not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_location( $location ) );
	}

	/**
	 * Create a Location.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$location = $this->service->create( $this->request_location_data( $request ) );
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$response = rest_ensure_response( $this->prepare_location( $location ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Update a Location.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$location = $this->service->update( $request['id'], $this->request_location_data( $request ) );
		return is_wp_error( $location ) ? $location : rest_ensure_response( $this->prepare_location( $location ) );
	}

	/**
	 * Trash a Location.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$result = $this->service->trash( $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true, 'id' => absint( $request['id'] ) ) );
	}

	/**
	 * Duplicate a Location as a new Draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function duplicate_item( $request ) {
		$location = $this->service->duplicate( $request['id'] );
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$response = rest_ensure_response( $this->prepare_location( $location ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Restore a trashed Location.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_item( $request ) {
		$location = $this->service->restore( $request['id'] );
		return is_wp_error( $location ) ? $location : rest_ensure_response( $this->prepare_location( $location ) );
	}

	/**
	 * Collection permission check.
	 *
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check() {
		return current_user_can( 'edit_velomalo_locations' ) ? true : $this->permission_error();
	}

	/**
	 * Create permission check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'create_velomalo_locations' ) ) {
			return $this->permission_error();
		}

		if ( 'publish' === $request->get_param( 'status' ) && ! current_user_can( 'publish_velomalo_locations' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Single-item read permission check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->item_permission( $request['id'], 'edit_post' );
	}

	/**
	 * Update permission check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$permission = $this->item_permission( $request['id'], 'edit_post' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$post = get_post( absint( $request['id'] ) );
		if ( $post && 'publish' !== $post->post_status && 'publish' === $request->get_param( 'status' ) && ! current_user_can( 'publish_velomalo_locations' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Delete permission check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->item_permission( $request['id'], 'delete_post' );
	}

	/**
	 * Duplicate permission check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function duplicate_item_permissions_check( $request ) {
		if ( ! current_user_can( 'create_velomalo_locations' ) ) {
			return $this->permission_error();
		}

		return $this->item_permission( $request['id'], 'edit_post' );
	}

	/**
	 * Restore permission check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function restore_item_permissions_check( $request ) {
		return $this->item_permission( $request['id'], 'delete_post' );
	}

	/**
	 * Check a primitive post capability for a Location only.
	 *
	 * @param int    $location_id Location ID.
	 * @param string $capability  Meta capability.
	 * @return true|\WP_Error
	 */
	private function item_permission( $location_id, $capability ) {
		$post = get_post( absint( $location_id ) );
		if ( ! $post || Post_Types::LOCATION !== $post->post_type ) {
			return new \WP_Error( 'velomalo_location_not_found', __( 'Location not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		return current_user_can( $capability, $post->ID ) ? true : $this->permission_error();
	}

	/**
	 * Prepare a Location for admin API output.
	 *
	 * @param Location $location Domain object.
	 * @return array<string,mixed>
	 */
	public function prepare_location( Location $location ) {
		return $location->to_array();
	}

	/**
	 * Extract only recognized Location fields from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function request_location_data( $request ) {
		$data = array();
		foreach ( array_keys( $this->location_args() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}
		return $data;
	}

	/**
	 * Collection request arguments.
	 *
	 * @return array<string,mixed>
	 */
	private function collection_args() {
		return array(
			'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint' ),
			'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint' ),
			'search'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'status'   => array( 'type' => 'string', 'default' => '', 'enum' => array( '', 'publish', 'draft', 'trash' ), 'sanitize_callback' => 'sanitize_key' ),
			'type_id'  => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'sanitize_callback' => 'absint' ),
			'group_id' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'sanitize_callback' => 'absint' ),
			'country'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => array( $this, 'validate_country' ) ),
			'city'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'orderby'  => array( 'type' => 'string', 'default' => 'modified', 'enum' => array( 'modified', 'date', 'title', 'menu_order', 'ID' ) ),
			'order'    => array( 'type' => 'string', 'default' => 'DESC', 'enum' => array( 'ASC', 'DESC' ) ),
		);
	}

	/**
	 * Location body arguments. Domain validation remains authoritative.
	 *
	 * @return array<string,mixed>
	 */
	private function location_args() {
		return array(
			'name'              => array( 'type' => 'string' ),
			'description'       => array( 'type' => 'string' ),
			'status'            => array( 'type' => 'string', 'enum' => array( 'draft', 'publish' ) ),
			'menu_order'        => array( 'type' => 'integer', 'minimum' => 0 ),
			'featured_image_id' => array( 'type' => 'integer', 'minimum' => 0 ),
			'address'           => array( 'type' => 'object' ),
			'contact'           => array( 'type' => 'object' ),
			'weekly_hours'      => array( 'type' => 'object' ),
			'special_hours'     => array( 'type' => 'array' ),
			'operational'       => array( 'type' => 'object' ),
			'type_ids'          => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'group_ids'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'primary_type_id'   => array( 'type' => 'integer', 'minimum' => 0 ),
			'marker'            => array( 'type' => 'object' ),
			'extra_fields'      => array( 'type' => 'array' ),
			'external_id'       => array( 'type' => 'string' ),
		);
	}

	/**
	 * Validate an optional two-letter country code filter.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function validate_country( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value || 1 === preg_match( '/^[A-Za-z]{2}$/', $value );
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
