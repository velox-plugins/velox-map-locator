<?php
/**
 * Builds public-safe Locator data.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;
use VeloxPlugins\VeloxMapLocator\Domain\Location;
use VeloxPlugins\VeloxMapLocator\Domain\Locator;
use VeloxPlugins\VeloxMapLocator\Domain\Public_Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts internal Location records into the exact fields a Locator needs.
 */
final class Public_Locator_Builder {

	/** @var Locator_Query_Service */
	private $query_service;

	/** Constructor. */
	public function __construct( Locator_Query_Service $query_service ) {
		$this->query_service = $query_service;
	}

	/**
	 * Build public Locator payload.
	 *
	 * @param Locator $locator Locator.
	 * @return array<string,mixed>
	 */
	public function build( Locator $locator ) {
		$config = $locator->get_config();

		/**
		 * Filter public Locator config before it is used for rendering.
		 *
		 * @param array<string,mixed> $config  Locator config.
		 * @param int                 $locator_id Locator ID.
		 */
		$config = apply_filters( 'velox_map_locator_locator_public_config', $config, $locator->get_id() );
		$config = is_array( $config ) ? $config : array();

		$locations = array();
		foreach ( $this->query_service->resolve( $locator ) as $location ) {
			$public = $this->build_location( $location, $config, $locator->get_id() );
			if ( $public ) {
				$locations[] = $public->to_array();
			}
		}

		return array(
			'id'        => $locator->get_id(),
			'name'      => $locator->get_name(),
			'config'    => $config,
			'locations' => $locations,
			'count'     => count( $locations ),
		);
	}

	/** Build one public Location. */
	private function build_location( Location $location, $config, $locator_id ) {
		$data       = $location->to_array();
		$card       = isset( $config['content']['card_fields'] ) && is_array( $config['content']['card_fields'] ) ? $config['content']['card_fields'] : array();
		$popup      = isset( $config['content']['popup_fields'] ) && is_array( $config['content']['popup_fields'] ) ? $config['content']['popup_fields'] : array();
		$search     = isset( $config['search']['fields'] ) && is_array( $config['search']['fields'] ) ? $config['search']['fields'] : array();
		$filters    = isset( $config['filters']['dimensions'] ) && is_array( $config['filters']['dimensions'] ) ? $config['filters']['dimensions'] : array();
		$layout     = isset( $config['layout']['mode'] ) ? sanitize_key( $config['layout']['mode'] ) : 'list_only';
		$visual     = 'map_only' === $layout ? $popup : array_merge( $card, $popup );
		$needed     = array_values( array_unique( array_merge( $visual, $search, $filters ) ) );
		$address    = isset( $data['address'] ) && is_array( $data['address'] ) ? $data['address'] : array();
		$contact    = isset( $data['contact'] ) && is_array( $data['contact'] ) ? $data['contact'] : array();
		$operational = isset( $data['operational'] ) && is_array( $data['operational'] ) ? $data['operational'] : array();

		$output = array(
			'id'   => $location->get_id(),
			'name' => $location->get_name(),
		);

		if ( in_array( $layout, array( 'split', 'map_only' ), true ) ) {
			$output['latitude']  = isset( $address['latitude'] ) ? $address['latitude'] : null;
			$output['longitude'] = isset( $address['longitude'] ) ? $address['longitude'] : null;
			$output['marker']    = $this->resolve_marker( $data );
		}

		if ( in_array( 'address', $needed, true ) ) {
			$output['address'] = $this->display_address( $address );
		}
		if ( in_array( 'city', $needed, true ) ) {
			$output['city'] = isset( $address['city'] ) ? (string) $address['city'] : '';
		}
		if ( in_array( 'region', $needed, true ) ) {
			$output['region'] = isset( $address['region'] ) ? (string) $address['region'] : '';
		}
		if ( in_array( 'country', $needed, true ) ) {
			$output['country_code'] = isset( $address['country_code'] ) ? (string) $address['country_code'] : '';
		}
		if ( in_array( 'description', $needed, true ) ) {
			$output['description'] = isset( $data['description'] ) ? (string) $data['description'] : '';
		}
		if ( in_array( 'phone', $needed, true ) ) {
			$output['phone'] = isset( $contact['phone'] ) ? (string) $contact['phone'] : '';
		}
		if ( in_array( 'email', $needed, true ) ) {
			$output['email'] = isset( $contact['email'] ) ? (string) $contact['email'] : '';
		}
		if ( in_array( 'website', $needed, true ) ) {
			$output['website'] = isset( $contact['website'] ) ? (string) $contact['website'] : '';
		}
		if ( in_array( 'contact', $needed, true ) ) {
			$output['contact_name'] = isset( $contact['contact_name'] ) ? (string) $contact['contact_name'] : '';
		}
		if ( in_array( 'status', $needed, true ) ) {
			$output['operational'] = array(
				'status' => isset( $operational['status'] ) && $operational['status'] ? (string) $operational['status'] : 'normal',
				'label'  => isset( $operational['label'] ) ? (string) $operational['label'] : '',
				'note'   => isset( $operational['note'] ) ? (string) $operational['note'] : '',
			);
		}
		if ( in_array( 'hours', $needed, true ) || in_array( 'status', $needed, true ) ) {
			$output['timezone']      = isset( $address['timezone'] ) ? (string) $address['timezone'] : '';
			$output['weekly_hours']  = isset( $data['weekly_hours'] ) && is_array( $data['weekly_hours'] ) ? $data['weekly_hours'] : array();
			$output['special_hours'] = isset( $data['special_hours'] ) && is_array( $data['special_hours'] ) ? $data['special_hours'] : array();
		}
		if ( in_array( 'extra_fields', $needed, true ) ) {
			$output['extra_fields'] = isset( $data['extra_fields'] ) && is_array( $data['extra_fields'] ) ? $data['extra_fields'] : array();
		}
		if ( in_array( 'image', $needed, true ) && ! empty( $data['featured_image_id'] ) ) {
			$image = wp_get_attachment_image_src( absint( $data['featured_image_id'] ), 'medium' );
			if ( is_array( $image ) && ! empty( $image[0] ) ) {
				$output['image'] = array( 'url' => $image[0], 'width' => (int) $image[1], 'height' => (int) $image[2] );
			}
		}

		if ( in_array( 'type', $needed, true ) || in_array( 'type', $filters, true ) ) {
			$output['types'] = $this->term_data( isset( $data['type_ids'] ) ? $data['type_ids'] : array(), Taxonomies::TYPE );
		}
		if ( in_array( 'group', $needed, true ) || in_array( 'group', $filters, true ) ) {
			$output['groups'] = $this->term_data( isset( $data['group_ids'] ) ? $data['group_ids'] : array(), Taxonomies::GROUP );
		}

		if ( in_array( 'directions', $needed, true ) ) {
			$output['directions_url'] = $this->directions_url( $data );
		}

		/**
		 * Filter one public Location payload.
		 *
		 * @param array<string,mixed> $output     Public data.
		 * @param int                 $location_id Location ID.
		 * @param int                 $locator_id  Locator ID.
		 */
		if ( ! empty( $output['marker']['media_id'] ) ) {
			$marker_url = wp_get_attachment_image_url( absint( $output['marker']['media_id'] ), 'thumbnail' );
			if ( $marker_url ) {
				$output['marker']['media_url'] = $marker_url;
			}
		}

		$output = apply_filters( 'velox_map_locator_location_public_data', $output, $location->get_id(), $locator_id );
		return is_array( $output ) ? new Public_Location( $output ) : null;
	}

	/** Resolve display address with structured fallback. */
	private function display_address( $address ) {
		if ( ! empty( $address['display_address'] ) ) {
			return (string) $address['display_address'];
		}
		$parts = array();
		foreach ( array( 'line_1', 'line_2', 'city', 'region', 'postal_code', 'country_code' ) as $key ) {
			if ( ! empty( $address[ $key ] ) ) {
				$parts[] = (string) $address[ $key ];
			}
		}
		return implode( ', ', $parts );
	}

	/** Resolve marker inheritance. */
	private function resolve_marker( $data ) {
		$marker = isset( $data['marker'] ) && is_array( $data['marker'] ) ? $data['marker'] : array();
		if ( ! empty( $marker['override'] ) ) {
			return array(
				'icon'       => ! empty( $marker['icon'] ) ? (string) $marker['icon'] : 'pin',
				'color'      => ! empty( $marker['color'] ) ? (string) $marker['color'] : '#2563eb',
				'icon_color' => ! empty( $marker['icon_color'] ) ? (string) $marker['icon_color'] : '#ffffff',
				'size'       => ! empty( $marker['size'] ) ? (string) $marker['size'] : 'medium',
				'media_id'   => isset( $marker['media_id'] ) ? absint( $marker['media_id'] ) : 0,
			);
		}

		$primary_type = isset( $data['primary_type_id'] ) ? absint( $data['primary_type_id'] ) : 0;
		if ( $primary_type ) {
			return array(
				'icon'       => (string) get_term_meta( $primary_type, '_velomalo_marker_icon', true ) ?: 'pin',
				'color'      => (string) get_term_meta( $primary_type, '_velomalo_marker_color', true ) ?: '#2563eb',
				'icon_color' => (string) get_term_meta( $primary_type, '_velomalo_marker_icon_color', true ) ?: '#ffffff',
				'size'       => 'medium',
				'media_id'   => (int) get_term_meta( $primary_type, '_velomalo_marker_media_id', true ),
			);
		}

		return array( 'icon' => 'pin', 'color' => '#2563eb', 'icon_color' => '#ffffff', 'size' => 'medium', 'media_id' => 0 );
	}

	/** Convert term IDs to public name/slug data. */
	private function term_data( $ids, $taxonomy ) {
		$output = array();
		foreach ( array_map( 'absint', is_array( $ids ) ? $ids : array() ) as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$output[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug );
			}
		}
		return $output;
	}

	/** Build explicit directions link without contacting a service until clicked. */
	private function directions_url( $data ) {
		$contact = isset( $data['contact'] ) && is_array( $data['contact'] ) ? $data['contact'] : array();
		if ( ! empty( $contact['directions_url'] ) ) {
			return (string) $contact['directions_url'];
		}
		$address = isset( $data['address'] ) && is_array( $data['address'] ) ? $data['address'] : array();
		if ( isset( $address['latitude'], $address['longitude'] ) && is_numeric( $address['latitude'] ) && is_numeric( $address['longitude'] ) ) {
			$query = rawurlencode( (string) $address['latitude'] . ',' . (string) $address['longitude'] );
			return 'https://www.google.com/maps/search/?api=1&query=' . $query;
		}
		$display = $this->display_address( $address );
		return $display ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $display ) : '';
	}
}
