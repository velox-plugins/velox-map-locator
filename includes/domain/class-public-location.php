<?php
/**
 * Public Location data transfer object.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purpose-built Location data safe for public rendering.
 */
final class Public_Location {

	/**
	 * Public data.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Public Location data.
	 */
	public function __construct( $data ) {
		$this->data = is_array( $data ) ? $data : array();
	}

	/**
	 * Return public-safe data.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return $this->data;
	}
}
