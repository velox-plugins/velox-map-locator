<?php
/**
 * Geographic coordinate value object.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a validated latitude/longitude pair.
 */
final class Coordinates {

	/**
	 * Latitude.
	 *
	 * @var float
	 */
	private $latitude;

	/**
	 * Longitude.
	 *
	 * @var float
	 */
	private $longitude;

	/**
	 * Constructor.
	 *
	 * @param float $latitude  Latitude.
	 * @param float $longitude Longitude.
	 */
	private function __construct( $latitude, $longitude ) {
		$this->latitude  = (float) $latitude;
		$this->longitude = (float) $longitude;
	}

	/**
	 * Build a coordinate pair when both values are valid.
	 *
	 * @param mixed $latitude  Latitude input.
	 * @param mixed $longitude Longitude input.
	 * @return Coordinates|null
	 */
	public static function from_values( $latitude, $longitude ) {
		if ( ! self::is_valid_latitude( $latitude ) || ! self::is_valid_longitude( $longitude ) ) {
			return null;
		}

		return new self( (float) $latitude, (float) $longitude );
	}

	/**
	 * Whether a latitude is valid.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	public static function is_valid_latitude( $value ) {
		return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= -90.0 && (float) $value <= 90.0;
	}

	/**
	 * Whether a longitude is valid.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	public static function is_valid_longitude( $value ) {
		return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= -180.0 && (float) $value <= 180.0;
	}

	/**
	 * Get latitude.
	 *
	 * @return float
	 */
	public function get_latitude() {
		return $this->latitude;
	}

	/**
	 * Get longitude.
	 *
	 * @return float
	 */
	public function get_longitude() {
		return $this->longitude;
	}

	/**
	 * Convert to an array.
	 *
	 * @return array<string,float>
	 */
	public function to_array() {
		return array(
			'latitude'  => $this->latitude,
			'longitude' => $this->longitude,
		);
	}
}
