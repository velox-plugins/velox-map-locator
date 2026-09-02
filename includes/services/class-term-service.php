<?php
/**
 * Type and Group application service.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages Location classification terms and Type marker defaults.
 */
final class Term_Service {

	/**
	 * List terms for a Velox taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function query( $taxonomy ) {
		if ( ! $this->is_supported_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'velomalo_invalid_taxonomy', __( 'Unsupported Velox taxonomy.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array_map( array( $this, 'prepare_term' ), $terms );
	}

	/**
	 * Find one classification term.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $term_id  Term ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function find( $taxonomy, $term_id ) {
		if ( ! $this->is_supported_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'velomalo_invalid_taxonomy', __( 'Unsupported Velox taxonomy.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		$term = get_term( absint( $term_id ), $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'velomalo_term_not_found', __( 'Classification not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		return $this->prepare_term( $term );
	}

	/**
	 * Create a term.
	 *
	 * @param string              $taxonomy Taxonomy name.
	 * @param array<string,mixed> $input    Submitted values.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create( $taxonomy, $input ) {
		$normalized = $this->normalize( $taxonomy, $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$args = array(
			'description' => $normalized['description'],
		);

		if ( '' !== $normalized['slug'] ) {
			$args['slug'] = $normalized['slug'];
		}
		if ( Taxonomies::GROUP === $taxonomy ) {
			$args['parent'] = $normalized['parent'];
		}

		$result = wp_insert_term( $normalized['name'], $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];
		$this->persist_type_marker( $taxonomy, $term_id, $normalized['marker'] );

		return $this->find( $taxonomy, $term_id );
	}

	/**
	 * Update a term.
	 *
	 * @param string              $taxonomy Taxonomy name.
	 * @param int                 $term_id  Term ID.
	 * @param array<string,mixed> $input    Submitted values.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update( $taxonomy, $term_id, $input ) {
		$term_id = absint( $term_id );
		$existing = $this->find( $taxonomy, $term_id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$normalized = $this->normalize( $taxonomy, array_replace_recursive( $existing, is_array( $input ) ? $input : array() ) );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		if ( Taxonomies::GROUP === $taxonomy ) {
			$parent_check = $this->validate_group_parent( $term_id, $normalized['parent'] );
			if ( is_wp_error( $parent_check ) ) {
				return $parent_check;
			}
		}

		$args = array(
			'name'        => $normalized['name'],
			'slug'        => $normalized['slug'],
			'description' => $normalized['description'],
		);

		if ( Taxonomies::GROUP === $taxonomy ) {
			$args['parent'] = $normalized['parent'];
		}

		$result = wp_update_term( $term_id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->persist_type_marker( $taxonomy, $term_id, $normalized['marker'] );

		return $this->find( $taxonomy, $term_id );
	}

	/**
	 * Delete a classification term.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $term_id  Term ID.
	 * @return true|\WP_Error
	 */
	public function delete( $taxonomy, $term_id ) {
		if ( ! $this->is_supported_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'velomalo_invalid_taxonomy', __( 'Unsupported Velox taxonomy.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		$term_id = absint( $term_id );
		$existing = $this->find( $taxonomy, $term_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$affected_locations = array();
		if ( Taxonomies::TYPE === $taxonomy ) {
			$objects = get_objects_in_term( $term_id, Taxonomies::TYPE );
			if ( is_wp_error( $objects ) ) {
				return $objects;
			}
			$affected_locations = array_map( 'absint', $objects );
		}

		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error( 'velomalo_term_not_found', __( 'Classification not found.', 'velox-map-locator' ), array( 'status' => 404 ) );
		}

		if ( Taxonomies::TYPE === $taxonomy ) {
			foreach ( $affected_locations as $location_id ) {
				$this->repair_primary_type( $location_id, $term_id );
			}
		}

		return true;
	}

	/**
	 * Normalize term input.
	 *
	 * @param string              $taxonomy Taxonomy name.
	 * @param array<string,mixed> $input    Submitted values.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize( $taxonomy, $input ) {
		if ( ! $this->is_supported_taxonomy( $taxonomy ) || ! is_array( $input ) ) {
			return new \WP_Error( 'velomalo_invalid_taxonomy_input', __( 'Classification data is not valid.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		if ( isset( $input['name'] ) && ! is_scalar( $input['name'] ) ) {
			return new \WP_Error( 'velomalo_term_name_required', __( 'A classification name is required.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'name' ) );
		}

		$name = isset( $input['name'] ) ? $this->limit_string( sanitize_text_field( (string) $input['name'] ), 200 ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'velomalo_term_name_required', __( 'A classification name is required.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'name' ) );
		}

		$parent = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;
		if ( Taxonomies::GROUP === $taxonomy && $parent && ! term_exists( $parent, Taxonomies::GROUP ) ) {
			return new \WP_Error( 'velomalo_invalid_group_parent', __( 'The selected parent Group does not exist.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'parent' ) );
		}

		$marker        = isset( $input['marker'] ) && is_array( $input['marker'] ) ? $input['marker'] : array();
		$marker_result = $this->normalize_type_marker( $taxonomy, $marker );
		if ( is_wp_error( $marker_result ) ) {
			return $marker_result;
		}

		return array(
			'name'        => $name,
			'slug'        => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
			'description' => isset( $input['description'] ) ? $this->limit_string( sanitize_textarea_field( (string) $input['description'] ), 2000 ) : '',
			'parent'      => $parent,
			'marker'      => $marker_result,
		);
	}

	/**
	 * Ensure a Group parent does not introduce a hierarchy cycle.
	 *
	 * @param int $term_id   Group being updated.
	 * @param int $parent_id Proposed parent Group.
	 * @return true|\WP_Error
	 */
	private function validate_group_parent( $term_id, $parent_id ) {
		if ( ! $parent_id ) {
			return true;
		}

		if ( $term_id === $parent_id ) {
			return new \WP_Error( 'velomalo_invalid_group_parent', __( 'A Group cannot be its own parent.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'parent' ) );
		}

		$children = get_term_children( $term_id, Taxonomies::GROUP );
		if ( is_wp_error( $children ) ) {
			return $children;
		}

		if ( in_array( $parent_id, array_map( 'absint', $children ), true ) ) {
			return new \WP_Error( 'velomalo_group_cycle', __( 'A Group cannot be moved beneath one of its own child Groups.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'parent' ) );
		}

		return true;
	}

	/**
	 * Normalize Type marker defaults.
	 *
	 * @param string              $taxonomy Taxonomy name.
	 * @param array<string,mixed> $marker   Marker values.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_type_marker( $taxonomy, $marker ) {
		if ( Taxonomies::TYPE !== $taxonomy ) {
			return array();
		}

		if ( isset( $marker['icon'] ) && ! is_scalar( $marker['icon'] ) ) {
			return new \WP_Error( 'velomalo_invalid_marker_icon', __( 'Marker icon is not supported.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'marker.icon' ) );
		}

		$icon = isset( $marker['icon'] ) ? sanitize_key( (string) $marker['icon'] ) : 'pin';
		if ( ! in_array( $icon, Location_Validator::MARKER_ICONS, true ) ) {
			return new \WP_Error( 'velomalo_invalid_marker_icon', __( 'Marker icon is not supported.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'marker.icon' ) );
		}

		if ( ( isset( $marker['color'] ) && ! is_scalar( $marker['color'] ) ) || ( isset( $marker['icon_color'] ) && ! is_scalar( $marker['icon_color'] ) ) ) {
			return new \WP_Error( 'velomalo_invalid_marker_color', __( 'Marker colors must use hexadecimal color values.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'marker.color' ) );
		}

		$color      = isset( $marker['color'] ) ? sanitize_hex_color( (string) $marker['color'] ) : '';
		$icon_color = isset( $marker['icon_color'] ) ? sanitize_hex_color( (string) $marker['icon_color'] ) : '';
		if ( ! empty( $marker['color'] ) && ! $color ) {
			return new \WP_Error( 'velomalo_invalid_marker_color', __( 'Marker color must be a hexadecimal color value.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'marker.color' ) );
		}
		if ( ! empty( $marker['icon_color'] ) && ! $icon_color ) {
			return new \WP_Error( 'velomalo_invalid_marker_color', __( 'Marker icon color must be a hexadecimal color value.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'marker.icon_color' ) );
		}

		if ( isset( $marker['media_id'] ) && '' !== $marker['media_id'] && ! is_numeric( $marker['media_id'] ) ) {
			return new \WP_Error( 'velomalo_invalid_marker_media', __( 'Type markers must be PNG, JPEG, or WebP Media Library images.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'marker.media_id' ) );
		}

		$media_id = isset( $marker['media_id'] ) ? absint( $marker['media_id'] ) : 0;
		if ( $media_id ) {
			$mime = get_post_mime_type( $media_id );
			if ( 'attachment' !== get_post_type( $media_id ) || ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
				return new \WP_Error( 'velomalo_invalid_marker_media', __( 'Type markers must be PNG, JPEG, or WebP Media Library images.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'marker.media_id' ) );
			}
		}

		return array(
			'icon'       => $icon,
			'media_id'   => $media_id,
			'color'      => $color ? $color : '',
			'icon_color' => $icon_color ? $icon_color : '',
		);
	}

	/**
	 * Persist marker defaults for Type terms.
	 *
	 * @param string              $taxonomy Taxonomy name.
	 * @param int                 $term_id  Term ID.
	 * @param array<string,mixed> $marker   Marker values.
	 * @return void
	 */
	private function persist_type_marker( $taxonomy, $term_id, $marker ) {
		if ( Taxonomies::TYPE !== $taxonomy ) {
			return;
		}

		$mapping = array(
			'_velomalo_marker_icon'       => $marker['icon'],
			'_velomalo_marker_media_id'   => $marker['media_id'],
			'_velomalo_marker_color'      => $marker['color'],
			'_velomalo_marker_icon_color' => $marker['icon_color'],
		);

		foreach ( $mapping as $key => $value ) {
			if ( '' === $value || 0 === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}
	}

	/**
	 * Repair a Location primary-Type reference after a Type is deleted.
	 *
	 * @param int $location_id     Location post ID.
	 * @param int $deleted_type_id Deleted Type term ID.
	 * @return void
	 */
	private function repair_primary_type( $location_id, $deleted_type_id ) {
		$location_id = absint( $location_id );
		if ( (int) get_post_meta( $location_id, '_velomalo_primary_type_id', true ) !== $deleted_type_id ) {
			return;
		}

		$remaining = wp_get_object_terms( $location_id, Taxonomies::TYPE, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $remaining ) || empty( $remaining ) ) {
			delete_post_meta( $location_id, '_velomalo_primary_type_id' );
			return;
		}

		update_post_meta( $location_id, '_velomalo_primary_type_id', absint( reset( $remaining ) ) );
	}

	/**
	 * Convert a term to API data.
	 *
	 * @param \WP_Term $term Term object.
	 * @return array<string,mixed>
	 */
	private function prepare_term( $term ) {
		$data = array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
		);

		if ( Taxonomies::TYPE === $term->taxonomy ) {
			$data['marker'] = array(
				'icon'       => (string) get_term_meta( $term->term_id, '_velomalo_marker_icon', true ),
				'media_id'   => (int) get_term_meta( $term->term_id, '_velomalo_marker_media_id', true ),
				'color'      => (string) get_term_meta( $term->term_id, '_velomalo_marker_color', true ),
				'icon_color' => (string) get_term_meta( $term->term_id, '_velomalo_marker_icon_color', true ),
			);

			if ( '' === $data['marker']['icon'] ) {
				$data['marker']['icon'] = 'pin';
			}
		}

		return $data;
	}

	/**
	 * Cap a string without requiring mbstring.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private function limit_string( $value, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Whether a taxonomy belongs to this service.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	private function is_supported_taxonomy( $taxonomy ) {
		return in_array( $taxonomy, array( Taxonomies::TYPE, Taxonomies::GROUP ), true );
	}
}
