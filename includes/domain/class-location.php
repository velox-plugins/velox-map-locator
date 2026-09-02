<?php
/**
 * Location domain model.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable-by-convention representation of a stored Location.
 */
final class Location {

	/**
	 * Location data.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Location data.
	 */
	public function __construct( $data ) {
		$this->data = is_array( $data ) ? $data : array();
	}

	/**
	 * Get location ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return isset( $this->data['id'] ) ? (int) $this->data['id'] : 0;
	}

	/**
	 * Get location title.
	 *
	 * @return string
	 */
	public function get_name() {
		return isset( $this->data['name'] ) ? (string) $this->data['name'] : '';
	}

	/**
	 * Get status.
	 *
	 * @return string
	 */
	public function get_status() {
		return isset( $this->data['status'] ) ? (string) $this->data['status'] : 'draft';
	}

	/**
	 * Get all domain data.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return $this->data;
	}
}
