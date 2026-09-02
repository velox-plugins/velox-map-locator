<?php
/**
 * WordPress Locator repository.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Repositories;

use VeloxPlugins\VeloxMapLocator\Content\Post_Types;
use VeloxPlugins\VeloxMapLocator\Domain\Locator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores Locator records in WordPress posts and post meta.
 */
final class WP_Locator_Repository implements Locator_Repository_Interface {

	/**
	 * Find a Locator.
	 *
	 * @param int $locator_id Locator ID.
	 * @return Locator|null
	 */
	public function find( $locator_id ) {
		$post = get_post( absint( $locator_id ) );
		if ( ! $post || Post_Types::LOCATOR !== $post->post_type ) {
			return null;
		}

		return $this->hydrate( $post );
	}

	/**
	 * Query Locators.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public function query( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 20,
				'search'   => '',
				'status'   => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'author'   => 0,
			)
		);

		$query_args = array(
			'post_type'              => Post_Types::LOCATOR,
			'post_status'            => $args['status'],
			'posts_per_page'         => max( 1, min( 100, absint( $args['per_page'] ) ) ),
			'paged'                  => max( 1, absint( $args['page'] ) ),
			's'                      => sanitize_text_field( $args['search'] ),
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
		);

		if ( ! empty( $args['author'] ) ) {
			$query_args['author'] = absint( $args['author'] );
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
	 * Create a Locator.
	 *
	 * @param array<string,mixed> $data Normalized data.
	 * @return Locator|\WP_Error
	 */
	public function create( $data ) {
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => Post_Types::LOCATOR,
					'post_title'  => $data['name'],
					'post_status' => $data['status'],
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! update_post_meta( $post_id, '_velomalo_config', $data['config'] ) ) {
			wp_delete_post( $post_id, true );
			return new \WP_Error( 'velomalo_locator_config_save_failed', __( 'Locator configuration could not be saved.', 'velox-map-locator' ) );
		}

		return $this->find( $post_id );
	}

	/**
	 * Update a Locator.
	 *
	 * @param int                 $locator_id Locator ID.
	 * @param array<string,mixed> $data       Normalized data.
	 * @return Locator|\WP_Error
	 */
	public function update( $locator_id, $data ) {
		$locator_id = absint( $locator_id );
		if ( ! $this->find( $locator_id ) ) {
			return new \WP_Error( 'velomalo_locator_not_found', __( 'Locator not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		$post_id = wp_update_post(
			wp_slash(
				array(
					'ID'          => $locator_id,
					'post_title'  => $data['name'],
					'post_status' => $data['status'],
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $locator_id, '_velomalo_config', $data['config'] );
		return $this->find( $locator_id );
	}

	/**
	 * Trash a Locator.
	 *
	 * @param int $locator_id Locator ID.
	 * @return bool|\WP_Error
	 */
	public function trash( $locator_id ) {
		$locator_id = absint( $locator_id );
		$locator    = $this->find( $locator_id );
		if ( ! $locator ) {
			return new \WP_Error( 'velomalo_locator_not_found', __( 'Locator not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}
		if ( 'trash' === $locator->get_status() ) {
			return true;
		}
		return false !== wp_trash_post( $locator_id ) ? true : new \WP_Error( 'velomalo_locator_trash_failed', __( 'The Locator could not be moved to Trash.', 'velox-map-locator' ) );
	}

	/**
	 * Restore a Locator.
	 *
	 * @param int $locator_id Locator ID.
	 * @return Locator|\WP_Error
	 */
	public function restore( $locator_id ) {
		$locator_id = absint( $locator_id );
		$locator    = $this->find( $locator_id );
		if ( ! $locator ) {
			return new \WP_Error( 'velomalo_locator_not_found', __( 'Locator not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}
		if ( 'trash' !== $locator->get_status() ) {
			return new \WP_Error( 'velomalo_locator_not_trashed', __( 'Only trashed Locators can be restored.', 'velox-map-locator' ), array( 'status' => 409 ) );
		}
		$result = wp_untrash_post( $locator_id );
		if ( false === $result || null === $result ) {
			return new \WP_Error( 'velomalo_locator_restore_failed', __( 'The Locator could not be restored.', 'velox-map-locator' ) );
		}
		return $this->find( $locator_id );
	}

	/**
	 * Convert a WP_Post into the domain model.
	 *
	 * @param \WP_Post $post Post record.
	 * @return Locator
	 */
	private function hydrate( $post ) {
		$config = get_post_meta( $post->ID, '_velomalo_config', true );
		return new Locator(
			array(
				'id'           => (int) $post->ID,
				'name'         => $post->post_title,
				'status'       => $post->post_status,
				'config'       => is_array( $config ) ? $config : array(),
				'modified_gmt' => $post->post_modified_gmt,
			)
		);
	}
}
