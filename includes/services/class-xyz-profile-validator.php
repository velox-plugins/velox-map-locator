<?php
/**
 * Custom XYZ provider profile validation.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes user-defined raster tile profiles without performing remote requests.
 */
final class XYZ_Profile_Validator {

	/**
	 * Normalize a profile.
	 *
	 * @param array<string,mixed> $input      Submitted profile.
	 * @param array<string,mixed> $existing   Existing profile for partial updates.
	 * @param string              $forced_id  Stable ID supplied by the controller.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function normalize( $input, $existing = array(), $forced_id = '' ) {
		if ( ! is_array( $input ) ) {
			return $this->error( 'velomalo_invalid_xyz_profile', __( 'XYZ provider profile must be an object.', 'velox-map-locator' ), 'profile' );
		}

		$existing = is_array( $existing ) ? $existing : array();
		$profile  = array(
			'id'            => isset( $existing['id'] ) ? sanitize_key( (string) $existing['id'] ) : '',
			'name'          => isset( $existing['name'] ) ? (string) $existing['name'] : '',
			'tile_url'      => isset( $existing['tile_url'] ) ? (string) $existing['tile_url'] : '',
			'attribution'   => isset( $existing['attribution'] ) ? (string) $existing['attribution'] : '',
			'min_zoom'      => isset( $existing['min_zoom'] ) ? absint( $existing['min_zoom'] ) : 0,
			'max_zoom'      => isset( $existing['max_zoom'] ) ? absint( $existing['max_zoom'] ) : 19,
			'subdomains'    => isset( $existing['subdomains'] ) && is_array( $existing['subdomains'] ) ? $existing['subdomains'] : array(),
			'tms'           => ! empty( $existing['tms'] ),
			'detect_retina' => ! empty( $existing['detect_retina'] ),
			'service_url'   => isset( $existing['service_url'] ) ? (string) $existing['service_url'] : '',
			'terms_url'     => isset( $existing['terms_url'] ) ? (string) $existing['terms_url'] : '',
			'privacy_url'   => isset( $existing['privacy_url'] ) ? (string) $existing['privacy_url'] : '',
		);

		if ( '' !== $forced_id ) {
			$profile['id'] = sanitize_key( $forced_id );
		} elseif ( array_key_exists( 'id', $input ) ) {
			$profile['id'] = sanitize_key( is_scalar( $input['id'] ) ? (string) $input['id'] : '' );
		}

		if ( array_key_exists( 'name', $input ) ) {
			$profile['name'] = $this->text( $input['name'], 120 );
		}
		if ( '' === $profile['name'] ) {
			return $this->error( 'velomalo_xyz_name_required', __( 'XYZ profile name is required.', 'velox-map-locator' ), 'name' );
		}

		if ( array_key_exists( 'tile_url', $input ) ) {
			$profile['tile_url'] = $this->tile_url( $input['tile_url'] );
		}
		if ( '' === $profile['tile_url'] ) {
			return $this->error( 'velomalo_xyz_tile_url_required', __( 'A valid HTTP or HTTPS tile URL template is required.', 'velox-map-locator' ), 'tile_url' );
		}
		if ( false === strpos( $profile['tile_url'], '{z}' ) || false === strpos( $profile['tile_url'], '{x}' ) || ( false === strpos( $profile['tile_url'], '{y}' ) && false === strpos( $profile['tile_url'], '{-y}' ) ) ) {
			return $this->error( 'velomalo_xyz_tile_url_placeholders', __( 'Tile URL must include {z}, {x}, and {y} (or {-y}) placeholders.', 'velox-map-locator' ), 'tile_url' );
		}

		if ( array_key_exists( 'attribution', $input ) ) {
			$profile['attribution'] = wp_kses(
				(string) $input['attribution'],
				array(
					'a' => array(
						'href'   => true,
						'title'  => true,
						'target' => true,
						'rel'    => true,
					),
					'span' => array( 'class' => true ),
				)
			);
		}

		if ( array_key_exists( 'min_zoom', $input ) ) {
			$profile['min_zoom'] = max( 0, min( 22, absint( $input['min_zoom'] ) ) );
		}
		if ( array_key_exists( 'max_zoom', $input ) ) {
			$profile['max_zoom'] = max( 0, min( 22, absint( $input['max_zoom'] ) ) );
		}
		if ( $profile['max_zoom'] < $profile['min_zoom'] ) {
			return $this->error( 'velomalo_xyz_zoom_range', __( 'Maximum zoom must be greater than or equal to minimum zoom.', 'velox-map-locator' ), 'max_zoom' );
		}

		if ( array_key_exists( 'subdomains', $input ) ) {
			$profile['subdomains'] = $this->subdomains( $input['subdomains'] );
		}
		if ( array_key_exists( 'tms', $input ) ) {
			$profile['tms'] = $this->boolean( $input['tms'], false );
		}
		if ( array_key_exists( 'detect_retina', $input ) ) {
			$profile['detect_retina'] = $this->boolean( $input['detect_retina'], false );
		}

		foreach ( array( 'service_url', 'terms_url', 'privacy_url' ) as $url_key ) {
			if ( array_key_exists( $url_key, $input ) ) {
				$profile[ $url_key ] = $this->optional_url( $input[ $url_key ] );
			}
		}

		return $profile;
	}

	/** Validate a tile URL while preserving Leaflet placeholders. */
	private function tile_url( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( '' === $value || strlen( $value ) > 2000 || ! preg_match( '#^https?://#i', $value ) ) {
			return '';
		}
		$probe = strtr(
			$value,
			array(
				'{z}'  => '0',
				'{x}'  => '0',
				'{y}'  => '0',
				'{-y}' => '0',
				'{s}'  => 'a',
				'{r}'  => '',
			)
		);
		$parts = wp_parse_url( $probe );
		return is_array( $parts ) && ! empty( $parts['host'] ) ? $value : '';
	}

	/** Normalize optional service URLs. */
	private function optional_url( $value ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return '';
		}
		$url = esc_url_raw( trim( (string) $value ) );
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/** Normalize a comma-separated or array subdomain list. */
	private function subdomains( $value ) {
		$items = is_array( $value ) ? $value : preg_split( '/\s*,\s*/', is_scalar( $value ) ? (string) $value : '' );
		$output = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item = strtolower( trim( is_scalar( $item ) ? (string) $item : '' ) );
			if ( preg_match( '/^[a-z0-9-]{1,20}$/', $item ) && ! in_array( $item, $output, true ) ) {
				$output[] = $item;
			}
			if ( count( $output ) >= 8 ) {
				break;
			}
		}
		return $output;
	}

	/** Normalize a plain text value. */
	private function text( $value, $max_length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
	}

	/** Normalize a boolean without accepting arbitrary strings as true. */
	private function boolean( $value, $fallback ) {
		$normalized = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $normalized ? (bool) $fallback : $normalized;
	}

	/** Build a validation error. */
	private function error( $code, $message, $field ) {
		return new \WP_Error( $code, $message, array( 'status' => 400, 'field' => $field ) );
	}
}
