<?php
/**
 * Resolves the Locations selected by a Locator configuration.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Domain\Location;
use VeloxPlugins\VeloxMapLocator\Domain\Locator;
use VeloxPlugins\VeloxMapLocator\Repositories\Location_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves All, Selected and Dynamic source modes.
 */
final class Locator_Query_Service {

	/** @var Location_Repository_Interface */
	private $locations;

	/** Constructor. */
	public function __construct( Location_Repository_Interface $locations ) {
		$this->locations = $locations;
	}

	/**
	 * Resolve published Locations for a Locator.
	 *
	 * @param Locator $locator Locator.
	 * @return Location[]
	 */
	public function resolve( Locator $locator ) {
		$config = $locator->get_config();
		$source = isset( $config['source'] ) && is_array( $config['source'] ) ? $config['source'] : array();
		$mode   = isset( $source['mode'] ) ? sanitize_key( $source['mode'] ) : 'all';

		if ( 'selected' === $mode ) {
			$items = $this->resolve_selected( $source );
		} elseif ( 'dynamic' === $mode ) {
			$items = $this->resolve_dynamic( $source );
		} else {
			$items = $this->fetch_all(
				array(
					'status'  => array( 'publish' ),
					'orderby' => 'menu_order',
					'order'   => 'ASC',
				)
			);
		}

		/**
		 * Filter resolved Locator Locations.
		 *
		 * @param Location[] $items   Locations.
		 * @param Locator    $locator Locator.
		 */
		$items = apply_filters( 'velox_map_locator_locator_locations', $items, $locator );
		return is_array( $items ) ? array_values( array_filter( $items, array( $this, 'is_location' ) ) ) : array();
	}

	/** Resolve selected mode while preserving manual order. */
	private function resolve_selected( $source ) {
		$selected = isset( $source['selected_ids'] ) && is_array( $source['selected_ids'] ) ? array_values( array_unique( array_map( 'absint', $source['selected_ids'] ) ) ) : array();
		$order    = isset( $source['manual_order'] ) && is_array( $source['manual_order'] ) ? array_values( array_unique( array_map( 'absint', $source['manual_order'] ) ) ) : $selected;
		$order    = array_values( array_unique( array_merge( array_intersect( $order, $selected ), $selected ) ) );
		$items    = array();
		foreach ( $order as $location_id ) {
			$location = $this->locations->find( $location_id );
			if ( $location && 'publish' === $location->get_status() ) {
				$items[] = $location;
			}
		}
		return $items;
	}

	/** Resolve dynamic mode by combining condition result sets. */
	private function resolve_dynamic( $source ) {
		$dynamic    = isset( $source['dynamic'] ) && is_array( $source['dynamic'] ) ? $source['dynamic'] : array();
		$conditions = isset( $dynamic['conditions'] ) && is_array( $dynamic['conditions'] ) ? $dynamic['conditions'] : array();
		$match      = isset( $dynamic['match'] ) && 'any' === $dynamic['match'] ? 'any' : 'all';
		$exclude    = isset( $dynamic['exclude_ids'] ) && is_array( $dynamic['exclude_ids'] ) ? array_map( 'absint', $dynamic['exclude_ids'] ) : array();

		if ( empty( $conditions ) ) {
			$items = $this->fetch_all( array( 'status' => array( 'publish' ), 'orderby' => 'menu_order', 'order' => 'ASC' ) );
			return array_values( array_filter( $items, static function ( $location ) use ( $exclude ) {
				return ! in_array( $location->get_id(), $exclude, true );
			} ) );
		}

		$sets     = array();
		$pool     = array();
		foreach ( $conditions as $condition ) {
			$args = array( 'status' => array( 'publish' ), 'orderby' => 'menu_order', 'order' => 'ASC' );
			$field = isset( $condition['field'] ) ? sanitize_key( $condition['field'] ) : '';
			$value = isset( $condition['value'] ) ? $condition['value'] : '';
			if ( 'type' === $field ) {
				$args['type_id'] = absint( $value );
			} elseif ( 'group' === $field ) {
				$args['group_id'] = absint( $value );
			} elseif ( 'country' === $field ) {
				$args['country'] = (string) $value;
			} elseif ( 'city' === $field ) {
				$args['city'] = (string) $value;
			} else {
				continue;
			}

			$matches = $this->fetch_all( $args );
			$ids     = array();
			foreach ( $matches as $location ) {
				$ids[]                         = $location->get_id();
				$pool[ $location->get_id() ] = $location;
			}
			$sets[] = array_values( array_unique( $ids ) );
		}

		if ( empty( $sets ) ) {
			return array();
		}

		$ids = array_shift( $sets );
		foreach ( $sets as $set ) {
			$ids = 'any' === $match ? array_values( array_unique( array_merge( $ids, $set ) ) ) : array_values( array_intersect( $ids, $set ) );
		}
		$ids = array_values( array_diff( $ids, $exclude ) );

		$items = array();
		foreach ( $ids as $id ) {
			if ( isset( $pool[ $id ] ) ) {
				$items[] = $pool[ $id ];
			}
		}

		usort( $items, array( $this, 'sort_locations' ) );
		return $items;
	}

	/** Fetch all pages from the existing Location repository contract. */
	private function fetch_all( $args ) {
		$page  = 1;
		$items = array();
		do {
			$result = $this->locations->query( array_merge( $args, array( 'page' => $page, 'per_page' => 100 ) ) );
			foreach ( $result['items'] as $location ) {
				$items[] = $location;
			}
			$total_pages = isset( $result['total_pages'] ) ? (int) $result['total_pages'] : 1;
			++$page;
		} while ( $page <= $total_pages );
		return $items;
	}

	/** Stable default sort for dynamic results. */
	private function sort_locations( Location $left, Location $right ) {
		$left_data  = $left->to_array();
		$right_data = $right->to_array();
		$left_order = isset( $left_data['menu_order'] ) ? (int) $left_data['menu_order'] : 0;
		$right_order = isset( $right_data['menu_order'] ) ? (int) $right_data['menu_order'] : 0;
		if ( $left_order !== $right_order ) {
			return $left_order <=> $right_order;
		}
		return strcasecmp( $left->get_name(), $right->get_name() );
	}

	/** Verify filter output stays within the domain type. */
	private function is_location( $value ) {
		return $value instanceof Location;
	}
}
