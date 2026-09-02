<?php
/**
 * Locator domain model.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable-by-convention representation of a stored Locator.
 */
final class Locator {

	/**
	 * Locator data.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Locator data.
	 */
	public function __construct( $data ) {
		$this->data = is_array( $data ) ? $data : array();
	}

	/**
	 * Get Locator ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return isset( $this->data['id'] ) ? (int) $this->data['id'] : 0;
	}

	/**
	 * Get Locator name.
	 *
	 * @return string
	 */
	public function get_name() {
		return isset( $this->data['name'] ) ? (string) $this->data['name'] : '';
	}

	/**
	 * Get publication status.
	 *
	 * @return string
	 */
	public function get_status() {
		return isset( $this->data['status'] ) ? (string) $this->data['status'] : 'draft';
	}

	/**
	 * Get normalized Locator configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config() {
		return isset( $this->data['config'] ) && is_array( $this->data['config'] ) ? $this->data['config'] : array();
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
