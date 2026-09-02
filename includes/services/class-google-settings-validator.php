<?php
/**
 * Google Maps provider settings validation.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes the small Google Maps provider settings surface.
 */
final class Google_Settings_Validator {

	/**
	 * Normalize Google Maps settings.
	 *
	 * @param mixed               $input    Submitted settings.
	 * @param array<string,mixed> $existing Existing settings.
	 * @return array<string,string>|\WP_Error
	 */
	public function normalize( $input, $existing = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = is_array( $existing ) ? $existing : array();

		$output = array(
			'api_key' => isset( $existing['api_key'] ) ? $this->clean_secret( $existing['api_key'] ) : '',
			'map_id'  => isset( $existing['map_id'] ) ? $this->clean_value( $existing['map_id'], 128 ) : '',
			'region'  => isset( $existing['region'] ) ? $this->normalize_region( $existing['region'] ) : 'auto',
		);

		if ( array_key_exists( 'api_key', $input ) ) {
			$api_key = $this->clean_secret( $input['api_key'] );
			if ( '' !== $api_key && preg_match( '/\s/', $api_key ) ) {
				return $this->error( 'velomalo_google_api_key_invalid', __( 'The Google Maps API key cannot contain whitespace.', 'velox-map-locator' ), 'api_key' );
			}
			$output['api_key'] = $api_key;
		}

		if ( array_key_exists( 'map_id', $input ) ) {
			$map_id = $this->clean_value( $input['map_id'], 128 );
			if ( '' !== $map_id && preg_match( '/\s/', $map_id ) ) {
				return $this->error( 'velomalo_google_map_id_invalid', __( 'The Google Maps map ID cannot contain whitespace.', 'velox-map-locator' ), 'map_id' );
			}
			$output['map_id'] = $map_id;
		}

		if ( array_key_exists( 'region', $input ) ) {
			$region = $this->normalize_region( $input['region'] );
			if ( false === $region ) {
				return $this->error( 'velomalo_google_region_invalid', __( 'Google Maps region must be Auto or a two-letter region code such as AE, GB or US.', 'velox-map-locator' ), 'region' );
			}
			$output['region'] = $region;
		}

		return $output;
	}

	/** Normalize API-key input without exposing it to URL/HTML sanitizers. */
	private function clean_secret( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		return substr( (string) $value, 0, 255 );
	}

	/** Normalize a plain provider identifier/value. */
	private function clean_value( $value, $max_length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return substr( sanitize_text_field( (string) $value ), 0, $max_length );
	}

	/** Normalize the optional two-letter region hint. */
	private function normalize_region( $value ) {
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		$value = strtoupper( trim( (string) $value ) );
		if ( '' === $value || 'AUTO' === $value ) {
			return 'auto';
		}
		return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : false;
	}

	/** Build a REST-friendly validation error. */
	private function error( $code, $message, $field ) {
		return new \WP_Error( $code, $message, array( 'status' => 400, 'field' => $field ) );
	}
}
