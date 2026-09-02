<?php
/**
 * WordPress Location repository.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Repositories;

use VeloxPlugins\VeloxMapLocator\Content\Post_Types;
use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;
use VeloxPlugins\VeloxMapLocator\Domain\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores Location domain records in WordPress posts, meta and terms.
 */
final class WP_Location_Repository implements Location_Repository_Interface {

	/**
	 * Find a Location by ID.
	 *
	 * @param int $location_id Location ID.
	 * @return Location|null
	 */
	public function find( $location_id ) {
		$post = get_post( absint( $location_id ) );

		if ( ! $post || Post_Types::LOCATION !== $post->post_type ) {
			return null;
		}

		return $this->hydrate( $post );
	}

	/**
	 * Query Locations.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public function query( $args = array() ) {
		$defaults = array(
			'page'       => 1,
			'per_page'   => 20,
			'search'     => '',
			'status'     => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'type_id'    => 0,
			'group_id'   => 0,
			'country'    => '',
			'city'       => '',
			'orderby'    => 'modified',
			'order'      => 'DESC',
			'author'     => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'              => Post_Types::LOCATION,
			'post_status'            => $args['status'],
			'posts_per_page'         => max( 1, min( 100, absint( $args['per_page'] ) ) ),
			'paged'                  => max( 1, absint( $args['page'] ) ),
			's'                      => sanitize_text_field( $args['search'] ),
			'orderby'                => $this->sanitize_orderby( $args['orderby'] ),
			'order'                  => 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);

		if ( ! empty( $args['author'] ) ) {
			$query_args['author'] = absint( $args['author'] );
		}

		$tax_query = array();

		if ( ! empty( $args['type_id'] ) ) {
			$tax_query[] = array(
				'taxonomy' => Taxonomies::TYPE,
				'field'    => 'term_id',
				'terms'    => array( absint( $args['type_id'] ) ),
			);
		}

		if ( ! empty( $args['group_id'] ) ) {
			$tax_query[] = array(
				'taxonomy'         => Taxonomies::GROUP,
				'field'            => 'term_id',
				'terms'            => array( absint( $args['group_id'] ) ),
				'include_children' => true,
			);
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- User-facing filters require taxonomy queries.
		}

		$meta_query = array();

		if ( ! empty( $args['country'] ) ) {
			$meta_query[] = array(
				'key'     => '_velomalo_country_code',
				'value'   => strtoupper( sanitize_text_field( $args['country'] ) ),
				'compare' => '=',
			);
		}

		if ( ! empty( $args['city'] ) ) {
			$meta_query[] = array(
				'key'     => '_velomalo_city',
				'value'   => sanitize_text_field( $args['city'] ),
				'compare' => '=',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Structured Location filters require registered meta queries in v1 storage model.
		}

		$query = new \WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->hydrate( $post );
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => (int) $query_args['paged'],
			'per_page'    => (int) $query_args['posts_per_page'],
		);
	}

	/**
	 * Create a Location.
	 *
	 * @param array<string,mixed> $data Normalized Location data.
	 * @return Location|\WP_Error
	 */
	public function create( $data ) {
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => Post_Types::LOCATION,
					'post_title'   => $data['name'],
					'post_excerpt' => $data['description'],
					'post_status'  => $data['status'],
					'menu_order'   => $data['menu_order'],
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$result = $this->persist_related_data( $post_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_delete_post( $post_id, true );
			return $result;
		}

		return $this->find( $post_id );
	}

	/**
	 * Update a Location.
	 *
	 * @param int                 $location_id Location ID.
	 * @param array<string,mixed> $data        Normalized Location data.
	 * @return Location|\WP_Error
	 */
	public function update( $location_id, $data ) {
		$location_id = absint( $location_id );
		$existing    = $this->find( $location_id );

		if ( ! $existing ) {
			return new \WP_Error( 'velomalo_location_not_found', __( 'Location not found.', 'velox-map-locator' ) );
		}

		$post_id = wp_update_post(
			wp_slash(
				array(
					'ID'           => $location_id,
					'post_title'   => $data['name'],
					'post_excerpt' => $data['description'],
					'post_status'  => $data['status'],
					'menu_order'   => $data['menu_order'],
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$result = $this->persist_related_data( $location_id, $data );

		if ( is_wp_error( $result ) ) {
			$this->restore_snapshot( $location_id, $existing );
			return $result;
		}

		return $this->find( $location_id );
	}

	/**
	 * Move a Location to Trash.
	 *
	 * @param int $location_id Location ID.
	 * @return bool|\WP_Error
	 */
	public function trash( $location_id ) {
		$location_id = absint( $location_id );
		$location    = $this->find( $location_id );

		if ( ! $location ) {
			return new \WP_Error( 'velomalo_location_not_found', __( 'Location not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		if ( 'trash' === $location->get_status() ) {
			return true;
		}

		$result = wp_trash_post( $location_id );

		if ( false === $result ) {
			return new \WP_Error( 'velomalo_location_trash_failed', __( 'The Location could not be moved to Trash.', 'velox-map-locator' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Restore a trashed Location.
	 *
	 * @param int $location_id Location ID.
	 * @return Location|\WP_Error
	 */
	public function restore( $location_id ) {
		$location_id = absint( $location_id );
		$location    = $this->find( $location_id );

		if ( ! $location ) {
			return new \WP_Error( 'velomalo_location_not_found', __( 'Location not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		if ( 'trash' !== $location->get_status() ) {
			return new \WP_Error( 'velomalo_location_not_trashed', __( 'Only trashed Locations can be restored.', 'velox-map-locator' ), array( 'status' => 409 ) );
		}

		$result = wp_untrash_post( $location_id );
		if ( false === $result || null === $result ) {
			return new \WP_Error( 'velomalo_location_restore_failed', __( 'The Location could not be restored.', 'velox-map-locator' ), array( 'status' => 500 ) );
		}

		$restored = $this->find( $location_id );
		return $restored ? $restored : new \WP_Error( 'velomalo_location_restore_failed', __( 'The Location could not be loaded after restoration.', 'velox-map-locator' ), array( 'status' => 500 ) );
	}

	/**
	 * Find a Location by external ID.
	 *
	 * @param string $external_id External identifier.
	 * @param int    $exclude_id  Optional Location ID to exclude.
	 * @return Location|null
	 */
	public function find_by_external_id( $external_id, $exclude_id = 0 ) {
		$external_id = trim( (string) $external_id );

		if ( '' === $external_id ) {
			return null;
		}

		$args = array(
			'post_type'              => Post_Types::LOCATION,
			'post_status'            => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
			'posts_per_page'         => $exclude_id ? 2 : 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- External ID is the defined import/update key in the v1 storage model.
				array(
					'key'     => '_velomalo_external_id',
					'value'   => $external_id,
					'compare' => '=',
				),
			),
		);

		$ids        = get_posts( $args );
		$exclude_id = absint( $exclude_id );
		foreach ( $ids as $id ) {
			if ( $exclude_id && $exclude_id === absint( $id ) ) {
				continue;
			}
			return $this->find( $id );
		}

		return null;
	}

	/**
	 * Best-effort rollback when a related-data write fails during update.
	 *
	 * @param int      $location_id Location ID.
	 * @param Location $snapshot    Pre-update Location snapshot.
	 * @return void
	 */
	private function restore_snapshot( $location_id, Location $snapshot ) {
		$data = $snapshot->to_array();

		wp_update_post(
			wp_slash(
				array(
					'ID'           => absint( $location_id ),
					'post_title'   => $data['name'],
					'post_excerpt' => $data['description'],
					'post_status'  => $data['status'],
					'menu_order'   => $data['menu_order'],
				)
			),
			true
		);

		$this->persist_related_data( absint( $location_id ), $data );
	}

	/**
	 * Persist metadata, terms and media relationships.
	 *
	 * @param int                 $location_id Location ID.
	 * @param array<string,mixed> $data        Normalized data.
	 * @return true|\WP_Error
	 */
	private function persist_related_data( $location_id, $data ) {
		$meta = $this->flatten_meta( $data );

		foreach ( $meta as $key => $value ) {
			if ( null === $value || '' === $value || ( is_array( $value ) && empty( $value ) ) ) {
				delete_post_meta( $location_id, $key );
				continue;
			}

			update_post_meta( $location_id, $key, $value );
		}

		$type_result = wp_set_object_terms( $location_id, $data['type_ids'], Taxonomies::TYPE, false );
		if ( is_wp_error( $type_result ) ) {
			return $type_result;
		}

		$group_result = wp_set_object_terms( $location_id, $data['group_ids'], Taxonomies::GROUP, false );
		if ( is_wp_error( $group_result ) ) {
			return $group_result;
		}

		if ( ! empty( $data['featured_image_id'] ) ) {
			set_post_thumbnail( $location_id, absint( $data['featured_image_id'] ) );
		} else {
			delete_post_thumbnail( $location_id );
		}

		clean_post_cache( $location_id );

		return true;
	}

	/**
	 * Convert canonical Location data into registered meta keys.
	 *
	 * @param array<string,mixed> $data Normalized Location data.
	 * @return array<string,mixed>
	 */
	private function flatten_meta( $data ) {
		return array(
			'_velomalo_address_line_1'   => $data['address']['line_1'],
			'_velomalo_address_line_2'   => $data['address']['line_2'],
			'_velomalo_city'             => $data['address']['city'],
			'_velomalo_region'           => $data['address']['region'],
			'_velomalo_postal_code'      => $data['address']['postal_code'],
			'_velomalo_country_code'     => $data['address']['country_code'],
			'_velomalo_display_address'  => $data['address']['display_address'],
			'_velomalo_latitude'         => $data['address']['latitude'],
			'_velomalo_longitude'        => $data['address']['longitude'],
			'_velomalo_timezone'         => $data['address']['timezone'],
			'_velomalo_phone'            => $data['contact']['phone'],
			'_velomalo_email'            => $data['contact']['email'],
			'_velomalo_website'          => $data['contact']['website'],
			'_velomalo_contact_name'     => $data['contact']['contact_name'],
			'_velomalo_directions_url'   => $data['contact']['directions_url'],
			'_velomalo_weekly_hours'     => $data['weekly_hours'],
			'_velomalo_special_hours'    => $data['special_hours'],
			'_velomalo_operational_status' => $data['operational']['status'],
			'_velomalo_status_label'       => $data['operational']['label'],
			'_velomalo_status_note'        => $data['operational']['note'],
			'_velomalo_primary_type_id'    => $data['primary_type_id'],
			'_velomalo_marker_override'    => $data['marker']['override'],
			'_velomalo_marker_icon'        => $data['marker']['icon'],
			'_velomalo_marker_media_id'    => $data['marker']['media_id'],
			'_velomalo_marker_color'       => $data['marker']['color'],
			'_velomalo_marker_icon_color'  => $data['marker']['icon_color'],
			'_velomalo_marker_size'        => $data['marker']['size'],
			'_velomalo_extra_fields'       => $data['extra_fields'],
			'_velomalo_external_id'        => $data['external_id'],
			'_velomalo_geocode_source'     => $data['geocode_source'],
			'_velomalo_geocoded_at'        => $data['geocoded_at'],
		);
	}

	/**
	 * Hydrate a post into a Location domain object.
	 *
	 * @param \WP_Post $post Location post.
	 * @return Location
	 */
	private function hydrate( $post ) {
		$type_ids  = wp_get_object_terms( $post->ID, Taxonomies::TYPE, array( 'fields' => 'ids' ) );
		$group_ids = wp_get_object_terms( $post->ID, Taxonomies::GROUP, array( 'fields' => 'ids' ) );

		$type_ids  = is_wp_error( $type_ids ) ? array() : array_map( 'absint', $type_ids );
		$group_ids = is_wp_error( $group_ids ) ? array() : array_map( 'absint', $group_ids );

		$data = array(
			'id'                => (int) $post->ID,
			'name'              => $post->post_title,
			'description'       => $post->post_excerpt,
			'status'            => $post->post_status,
			'menu_order'        => (int) $post->menu_order,
			'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
			'address'           => array(
				'line_1'          => (string) get_post_meta( $post->ID, '_velomalo_address_line_1', true ),
				'line_2'          => (string) get_post_meta( $post->ID, '_velomalo_address_line_2', true ),
				'city'            => (string) get_post_meta( $post->ID, '_velomalo_city', true ),
				'region'          => (string) get_post_meta( $post->ID, '_velomalo_region', true ),
				'postal_code'     => (string) get_post_meta( $post->ID, '_velomalo_postal_code', true ),
				'country_code'    => (string) get_post_meta( $post->ID, '_velomalo_country_code', true ),
				'display_address' => (string) get_post_meta( $post->ID, '_velomalo_display_address', true ),
				'latitude'        => $this->nullable_float_meta( $post->ID, '_velomalo_latitude' ),
				'longitude'       => $this->nullable_float_meta( $post->ID, '_velomalo_longitude' ),
				'timezone'        => (string) get_post_meta( $post->ID, '_velomalo_timezone', true ),
			),
			'contact'           => array(
				'phone'          => (string) get_post_meta( $post->ID, '_velomalo_phone', true ),
				'email'          => (string) get_post_meta( $post->ID, '_velomalo_email', true ),
				'website'        => (string) get_post_meta( $post->ID, '_velomalo_website', true ),
				'contact_name'   => (string) get_post_meta( $post->ID, '_velomalo_contact_name', true ),
				'directions_url' => (string) get_post_meta( $post->ID, '_velomalo_directions_url', true ),
			),
			'weekly_hours'      => $this->array_meta( $post->ID, '_velomalo_weekly_hours' ),
			'special_hours'     => $this->array_meta( $post->ID, '_velomalo_special_hours' ),
			'operational'       => array(
				'status' => (string) get_post_meta( $post->ID, '_velomalo_operational_status', true ),
				'label'  => (string) get_post_meta( $post->ID, '_velomalo_status_label', true ),
				'note'   => (string) get_post_meta( $post->ID, '_velomalo_status_note', true ),
			),
			'type_ids'          => $type_ids,
			'group_ids'         => $group_ids,
			'primary_type_id'   => (int) get_post_meta( $post->ID, '_velomalo_primary_type_id', true ),
			'marker'            => array(
				'override'   => (bool) get_post_meta( $post->ID, '_velomalo_marker_override', true ),
				'icon'       => (string) get_post_meta( $post->ID, '_velomalo_marker_icon', true ),
				'media_id'   => (int) get_post_meta( $post->ID, '_velomalo_marker_media_id', true ),
				'color'      => (string) get_post_meta( $post->ID, '_velomalo_marker_color', true ),
				'icon_color' => (string) get_post_meta( $post->ID, '_velomalo_marker_icon_color', true ),
				'size'       => (string) get_post_meta( $post->ID, '_velomalo_marker_size', true ),
			),
			'extra_fields'       => $this->array_meta( $post->ID, '_velomalo_extra_fields' ),
			'external_id'        => (string) get_post_meta( $post->ID, '_velomalo_external_id', true ),
			'geocode_source'     => (string) get_post_meta( $post->ID, '_velomalo_geocode_source', true ),
			'geocoded_at'        => (string) get_post_meta( $post->ID, '_velomalo_geocoded_at', true ),
			'modified_gmt'       => $post->post_modified_gmt,
		);

		return new Location( $data );
	}

	/**
	 * Read an array-valued meta field safely.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return array<mixed>
	 */
	private function array_meta( $post_id, $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Read a numeric meta field while preserving absence as null.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return float|null
	 */
	private function nullable_float_meta( $post_id, $meta_key ) {
		if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
			return null;
		}

		$value = get_post_meta( $post_id, $meta_key, true );
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Allow supported ordering only.
	 *
	 * @param mixed $orderby Requested orderby value.
	 * @return string
	 */
	private function sanitize_orderby( $orderby ) {
		$allowed = array( 'modified', 'date', 'title', 'menu_order', 'ID' );
		$value   = is_scalar( $orderby ) ? (string) $orderby : 'modified';
		return in_array( $value, $allowed, true ) ? $value : 'modified';
	}
}
