<?php
/**
 * Location input normalization and validation.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;
use VeloxPlugins\VeloxMapLocator\Domain\Business_Hours;
use VeloxPlugins\VeloxMapLocator\Domain\Coordinates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts untrusted Location input into the canonical application schema.
 */
final class Location_Validator {

	/**
	 * Supported operational states.
	 *
	 * @var string[]
	 */
	const OPERATIONAL_STATUSES = array( 'normal', 'temporarily_closed', 'coming_soon' );

	/**
	 * Supported marker sizes.
	 *
	 * @var string[]
	 */
	const MARKER_SIZES = array( 'small', 'medium', 'large' );

	/**
	 * Supported built-in marker icon identifiers.
	 *
	 * @var string[]
	 */
	const MARKER_ICONS = array(
		'pin',
		'office',
		'store',
		'building',
		'shopping-bag',
		'warehouse',
		'service',
		'tools',
		'clinic',
		'education',
		'restaurant',
		'atm',
		'dealer',
		'star',
	);

	/**
	 * Normalize a Location payload.
	 *
	 * @param array<string,mixed> $input    Submitted values.
	 * @param array<string,mixed> $existing Existing canonical values for partial updates.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function normalize( $input, $existing = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = is_array( $existing ) ? $existing : array();
		$data     = $this->defaults( $existing );

		if ( array_key_exists( 'name', $input ) ) {
			$data['name'] = $this->plain_string( $input['name'], 200 );
		}

		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = $this->textarea_string( $input['description'], 2000 );
		}

		if ( array_key_exists( 'status', $input ) ) {
			$status = sanitize_key( (string) $input['status'] );
			if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
				return $this->error( 'velomalo_invalid_status', __( 'Location status must be Draft or Published.', 'velox-map-locator' ), 'status' );
			}
			$data['status'] = $status;
		}

		if ( array_key_exists( 'menu_order', $input ) ) {
			$data['menu_order'] = max( 0, (int) $input['menu_order'] );
		}

		if ( array_key_exists( 'featured_image_id', $input ) ) {
			$image_id = absint( $input['featured_image_id'] );
			if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
				return $this->error( 'velomalo_invalid_featured_image', __( 'The selected Location image must be a valid WordPress image attachment.', 'velox-map-locator' ), 'featured_image_id' );
			}
			$data['featured_image_id'] = $image_id;
		}

		$address_result = $this->normalize_address( isset( $input['address'] ) ? $input['address'] : null, $data['address'], array_key_exists( 'address', $input ) );
		if ( is_wp_error( $address_result ) ) {
			return $address_result;
		}
		$data['address'] = $address_result;

		$contact_result = $this->normalize_contact( isset( $input['contact'] ) ? $input['contact'] : null, $data['contact'], array_key_exists( 'contact', $input ) );
		if ( is_wp_error( $contact_result ) ) {
			return $contact_result;
		}
		$data['contact'] = $contact_result;

		if ( array_key_exists( 'weekly_hours', $input ) ) {
			if ( array() === $input['weekly_hours'] ) {
				$data['weekly_hours'] = array();
			} else {
				$hours = Business_Hours::normalize_weekly( $input['weekly_hours'] );
				if ( null === $hours ) {
					return $this->error( 'velomalo_invalid_weekly_hours', __( 'Weekly business hours are not valid.', 'velox-map-locator' ), 'weekly_hours' );
				}
				$data['weekly_hours'] = $hours;
			}
		}

		if ( array_key_exists( 'special_hours', $input ) ) {
			$hours = Business_Hours::normalize_special( $input['special_hours'] );
			if ( null === $hours ) {
				return $this->error( 'velomalo_invalid_special_hours', __( 'Special business hours are not valid.', 'velox-map-locator' ), 'special_hours' );
			}
			$data['special_hours'] = $this->sanitize_special_hour_labels( $hours );
		}

		$operational_result = $this->normalize_operational( isset( $input['operational'] ) ? $input['operational'] : null, $data['operational'], array_key_exists( 'operational', $input ) );
		if ( is_wp_error( $operational_result ) ) {
			return $operational_result;
		}
		$data['operational'] = $operational_result;

		$type_ids_provided         = array_key_exists( 'type_ids', $input );
		$primary_type_id_provided = array_key_exists( 'primary_type_id', $input );

		if ( $type_ids_provided ) {
			$type_ids = $this->normalize_term_ids( $input['type_ids'], Taxonomies::TYPE, 'type_ids' );
			if ( is_wp_error( $type_ids ) ) {
				return $type_ids;
			}
			$data['type_ids'] = $type_ids;
		}

		if ( array_key_exists( 'group_ids', $input ) ) {
			$group_ids = $this->normalize_term_ids( $input['group_ids'], Taxonomies::GROUP, 'group_ids' );
			if ( is_wp_error( $group_ids ) ) {
				return $group_ids;
			}
			$data['group_ids'] = $group_ids;
		}

		if ( $primary_type_id_provided ) {
			$data['primary_type_id'] = absint( $input['primary_type_id'] );
		} elseif ( $type_ids_provided && $data['primary_type_id'] && ! in_array( $data['primary_type_id'], $data['type_ids'], true ) ) {
			$data['primary_type_id'] = ! empty( $data['type_ids'] ) ? (int) reset( $data['type_ids'] ) : 0;
		}

		if ( $data['primary_type_id'] && ! in_array( $data['primary_type_id'], $data['type_ids'], true ) ) {
			return $this->error( 'velomalo_invalid_primary_type', __( 'The primary Location Type must also be assigned to the Location.', 'velox-map-locator' ), 'primary_type_id' );
		}

		if ( ! $data['primary_type_id'] && ! empty( $data['type_ids'] ) ) {
			$data['primary_type_id'] = (int) reset( $data['type_ids'] );
		}

		$marker_result = $this->normalize_marker( isset( $input['marker'] ) ? $input['marker'] : null, $data['marker'], array_key_exists( 'marker', $input ) );
		if ( is_wp_error( $marker_result ) ) {
			return $marker_result;
		}
		$data['marker'] = $marker_result;

		if ( array_key_exists( 'extra_fields', $input ) ) {
			$extra_fields = $this->normalize_extra_fields( $input['extra_fields'] );
			if ( is_wp_error( $extra_fields ) ) {
				return $extra_fields;
			}
			$data['extra_fields'] = $extra_fields;
		}

		if ( array_key_exists( 'external_id', $input ) ) {
			$data['external_id'] = $this->plain_string( $input['external_id'], 191 );
		}

		if ( array_key_exists( 'geocode_source', $input ) ) {
			$data['geocode_source'] = sanitize_key( (string) $input['geocode_source'] );
		}

		if ( array_key_exists( 'geocoded_at', $input ) ) {
			$data['geocoded_at'] = $this->plain_string( $input['geocoded_at'], 40 );
		}

		if ( 'publish' === $data['status'] ) {
			if ( '' === $data['name'] ) {
				return $this->error( 'velomalo_location_name_required', __( 'A Location name is required before publication.', 'velox-map-locator' ), 'name' );
			}

			if ( null === Coordinates::from_values( $data['address']['latitude'], $data['address']['longitude'] ) ) {
				return $this->error( 'velomalo_coordinates_required', __( 'Valid latitude and longitude are required before a Location can be published.', 'velox-map-locator' ), 'address' );
			}
		}

		return $data;
	}

	/**
	 * Return canonical defaults, preserving stored values where supplied.
	 *
	 * @param array<string,mixed> $existing Existing values.
	 * @return array<string,mixed>
	 */
	private function defaults( $existing ) {
		$defaults = array(
			'name'              => '',
			'description'       => '',
			'status'            => 'draft',
			'menu_order'        => 0,
			'featured_image_id' => 0,
			'address'           => array(
				'line_1'          => '',
				'line_2'          => '',
				'city'            => '',
				'region'          => '',
				'postal_code'     => '',
				'country_code'    => '',
				'display_address' => '',
				'latitude'        => null,
				'longitude'       => null,
				'timezone'        => '',
			),
			'contact'           => array(
				'phone'          => '',
				'email'          => '',
				'website'        => '',
				'contact_name'   => '',
				'directions_url' => '',
			),
			'weekly_hours'      => array(),
			'special_hours'     => array(),
			'operational'       => array(
				'status' => 'normal',
				'label'  => '',
				'note'   => '',
			),
			'type_ids'          => array(),
			'group_ids'         => array(),
			'primary_type_id'   => 0,
			'marker'            => array(
				'override'   => false,
				'icon'       => 'pin',
				'media_id'   => 0,
				'color'      => '',
				'icon_color' => '',
				'size'       => 'medium',
			),
			'extra_fields'       => array(),
			'external_id'        => '',
			'geocode_source'     => 'manual',
			'geocoded_at'        => '',
		);

		return array_replace_recursive( $defaults, $existing );
	}

	/**
	 * Normalize structured address fields.
	 *
	 * @param mixed               $input    Address input.
	 * @param array<string,mixed> $existing Existing address.
	 * @param bool                $provided Whether address was submitted.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_address( $input, $existing, $provided ) {
		if ( ! $provided ) {
			return $existing;
		}

		if ( ! is_array( $input ) ) {
			return $this->error( 'velomalo_invalid_address', __( 'Location address data must be an object.', 'velox-map-locator' ), 'address' );
		}

		$output = $existing;
		$fields = array( 'line_1', 'line_2', 'city', 'region', 'postal_code', 'display_address' );
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$output[ $field ] = $this->plain_string( $input[ $field ], 'display_address' === $field ? 500 : 200 );
			}
		}

		if ( array_key_exists( 'country_code', $input ) ) {
			if ( null !== $input['country_code'] && ! is_scalar( $input['country_code'] ) ) {
				return $this->error( 'velomalo_invalid_country_code', __( 'Country code must contain exactly two letters.', 'velox-map-locator' ), 'address.country_code' );
			}

			$country = null === $input['country_code'] ? '' : strtoupper( trim( (string) $input['country_code'] ) );
			if ( '' !== $country && 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
				return $this->error( 'velomalo_invalid_country_code', __( 'Country code must contain exactly two letters.', 'velox-map-locator' ), 'address.country_code' );
			}
			$output['country_code'] = $country;
		}

		foreach ( array( 'latitude', 'longitude' ) as $coordinate ) {
			if ( ! array_key_exists( $coordinate, $input ) ) {
				continue;
			}

			if ( null === $input[ $coordinate ] || '' === $input[ $coordinate ] ) {
				$output[ $coordinate ] = null;
				continue;
			}

			$valid = 'latitude' === $coordinate ? Coordinates::is_valid_latitude( $input[ $coordinate ] ) : Coordinates::is_valid_longitude( $input[ $coordinate ] );
			if ( ! $valid ) {
				return $this->error( 'velomalo_invalid_coordinates', __( 'Latitude or longitude is outside its valid range.', 'velox-map-locator' ), 'address.' . $coordinate );
			}
			$output[ $coordinate ] = (float) $input[ $coordinate ];
		}

		if ( array_key_exists( 'timezone', $input ) ) {
			if ( null !== $input['timezone'] && ! is_scalar( $input['timezone'] ) ) {
				return $this->error( 'velomalo_invalid_timezone', __( 'The selected Location timezone is not valid.', 'velox-map-locator' ), 'address.timezone' );
			}
			$timezone = null === $input['timezone'] ? '' : $this->plain_string( $input['timezone'], 100 );
			if ( '' !== $timezone && ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
				return $this->error( 'velomalo_invalid_timezone', __( 'The selected Location timezone is not valid.', 'velox-map-locator' ), 'address.timezone' );
			}
			$output['timezone'] = $timezone;
		}

		return $output;
	}

	/**
	 * Normalize contact information.
	 *
	 * @param mixed               $input    Contact input.
	 * @param array<string,mixed> $existing Existing contact data.
	 * @param bool                $provided Whether contact was submitted.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_contact( $input, $existing, $provided ) {
		if ( ! $provided ) {
			return $existing;
		}

		if ( ! is_array( $input ) ) {
			return $this->error( 'velomalo_invalid_contact', __( 'Location contact data must be an object.', 'velox-map-locator' ), 'contact' );
		}

		$output = $existing;

		if ( array_key_exists( 'phone', $input ) ) {
			$output['phone'] = $this->plain_string( $input['phone'], 80 );
		}
		if ( array_key_exists( 'contact_name', $input ) ) {
			$output['contact_name'] = $this->plain_string( $input['contact_name'], 200 );
		}
		if ( array_key_exists( 'email', $input ) ) {
			if ( null !== $input['email'] && ! is_scalar( $input['email'] ) ) {
				return $this->error( 'velomalo_invalid_email', __( 'The Location email address is not valid.', 'velox-map-locator' ), 'contact.email' );
			}
			$email_input = null === $input['email'] ? '' : trim( (string) $input['email'] );
			$email       = sanitize_email( $email_input );
			if ( '' !== $email_input && ( '' === $email || ! is_email( $email ) ) ) {
				return $this->error( 'velomalo_invalid_email', __( 'The Location email address is not valid.', 'velox-map-locator' ), 'contact.email' );
			}
			$output['email'] = $email;
		}

		foreach ( array( 'website', 'directions_url' ) as $url_field ) {
			if ( ! array_key_exists( $url_field, $input ) ) {
				continue;
			}

			$url = $this->normalize_http_url( $input[ $url_field ] );
			if ( is_wp_error( $url ) ) {
				return $this->error( 'velomalo_invalid_url', __( 'A Location URL must be a complete HTTP or HTTPS URL.', 'velox-map-locator' ), 'contact.' . $url_field );
			}
			$output[ $url_field ] = $url;
		}

		return $output;
	}

	/**
	 * Normalize operational state.
	 *
	 * @param mixed               $input    Operational state.
	 * @param array<string,mixed> $existing Existing state.
	 * @param bool                $provided Whether data was submitted.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_operational( $input, $existing, $provided ) {
		if ( ! $provided ) {
			return $existing;
		}

		if ( ! is_array( $input ) ) {
			return $this->error( 'velomalo_invalid_operational_status', __( 'Operational status data must be an object.', 'velox-map-locator' ), 'operational' );
		}

		$output = $existing;
		if ( array_key_exists( 'status', $input ) ) {
			if ( ! is_scalar( $input['status'] ) ) {
				return $this->error( 'velomalo_invalid_operational_status', __( 'Operational status is not supported.', 'velox-map-locator' ), 'operational.status' );
			}
			$status = sanitize_key( (string) $input['status'] );
			if ( ! in_array( $status, self::OPERATIONAL_STATUSES, true ) ) {
				return $this->error( 'velomalo_invalid_operational_status', __( 'Operational status is not supported.', 'velox-map-locator' ), 'operational.status' );
			}
			$output['status'] = $status;
		}
		if ( array_key_exists( 'label', $input ) ) {
			$output['label'] = $this->plain_string( $input['label'], 120 );
		}
		if ( array_key_exists( 'note', $input ) ) {
			$output['note'] = $this->textarea_string( $input['note'], 500 );
		}

		return $output;
	}

	/**
	 * Normalize marker override data.
	 *
	 * @param mixed               $input    Marker input.
	 * @param array<string,mixed> $existing Existing marker.
	 * @param bool                $provided Whether marker was submitted.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_marker( $input, $existing, $provided ) {
		if ( ! $provided ) {
			return $existing;
		}

		if ( ! is_array( $input ) ) {
			return $this->error( 'velomalo_invalid_marker', __( 'Marker settings must be an object.', 'velox-map-locator' ), 'marker' );
		}

		$output = $existing;
		if ( array_key_exists( 'override', $input ) ) {
			$boolean = $this->normalize_boolean( $input['override'] );
			if ( null === $boolean ) {
				return $this->error( 'velomalo_invalid_marker_override', __( 'Marker override must be a boolean value.', 'velox-map-locator' ), 'marker.override' );
			}
			$output['override'] = $boolean;
		}
		if ( array_key_exists( 'icon', $input ) ) {
			if ( ! is_scalar( $input['icon'] ) ) {
				return $this->error( 'velomalo_invalid_marker_icon', __( 'Marker icon is not supported.', 'velox-map-locator' ), 'marker.icon' );
			}
			$icon = sanitize_key( (string) $input['icon'] );
			if ( ! in_array( $icon, self::MARKER_ICONS, true ) ) {
				return $this->error( 'velomalo_invalid_marker_icon', __( 'Marker icon is not supported.', 'velox-map-locator' ), 'marker.icon' );
			}
			$output['icon'] = $icon;
		}
		if ( array_key_exists( 'size', $input ) ) {
			if ( ! is_scalar( $input['size'] ) ) {
				return $this->error( 'velomalo_invalid_marker_size', __( 'Marker size is not supported.', 'velox-map-locator' ), 'marker.size' );
			}
			$size = sanitize_key( (string) $input['size'] );
			if ( ! in_array( $size, self::MARKER_SIZES, true ) ) {
				return $this->error( 'velomalo_invalid_marker_size', __( 'Marker size is not supported.', 'velox-map-locator' ), 'marker.size' );
			}
			$output['size'] = $size;
		}
		foreach ( array( 'color', 'icon_color' ) as $color_field ) {
			if ( ! array_key_exists( $color_field, $input ) ) {
				continue;
			}
			if ( null !== $input[ $color_field ] && ! is_scalar( $input[ $color_field ] ) ) {
				return $this->error( 'velomalo_invalid_marker_color', __( 'Marker colors must use hexadecimal color values.', 'velox-map-locator' ), 'marker.' . $color_field );
			}
			$color_input = null === $input[ $color_field ] ? '' : (string) $input[ $color_field ];
			$color       = sanitize_hex_color( $color_input );
			if ( '' !== $color_input && ! $color ) {
				return $this->error( 'velomalo_invalid_marker_color', __( 'Marker colors must use hexadecimal color values.', 'velox-map-locator' ), 'marker.' . $color_field );
			}
			$output[ $color_field ] = $color ? $color : '';
		}
		if ( array_key_exists( 'media_id', $input ) ) {
			if ( null !== $input['media_id'] && '' !== $input['media_id'] && ! is_numeric( $input['media_id'] ) ) {
				return $this->error( 'velomalo_invalid_marker_media', __( 'Custom markers must be PNG, JPEG, or WebP Media Library images.', 'velox-map-locator' ), 'marker.media_id' );
			}
			$media_id = absint( $input['media_id'] );
			if ( $media_id ) {
				$mime = get_post_mime_type( $media_id );
				if ( 'attachment' !== get_post_type( $media_id ) || ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
					return $this->error( 'velomalo_invalid_marker_media', __( 'Custom markers must be PNG, JPEG, or WebP Media Library images.', 'velox-map-locator' ), 'marker.media_id' );
				}
			}
			$output['media_id'] = $media_id;
		}

		return $output;
	}

	/**
	 * Normalize additional information fields.
	 *
	 * @param mixed $input Additional fields.
	 * @return array<int,array<string,string>>|\WP_Error
	 */
	private function normalize_extra_fields( $input ) {
		if ( ! is_array( $input ) || count( $input ) > 50 ) {
			return $this->error( 'velomalo_invalid_extra_fields', __( 'Additional Information must contain no more than 50 fields.', 'velox-map-locator' ), 'extra_fields' );
		}

		$output   = array();
		$seen_ids = array();
		$allowed  = array( 'text', 'phone', 'email', 'url' );

		foreach ( $input as $field ) {
			if ( ! is_array( $field ) ) {
				return $this->error( 'velomalo_invalid_extra_field', __( 'Each Additional Information entry must be an object.', 'velox-map-locator' ), 'extra_fields' );
			}

			if ( ( isset( $field['id'] ) && ! is_scalar( $field['id'] ) ) || ( isset( $field['label'] ) && ! is_scalar( $field['label'] ) ) || ( isset( $field['type'] ) && ! is_scalar( $field['type'] ) ) || ( isset( $field['value'] ) && ! is_scalar( $field['value'] ) ) ) {
				return $this->error( 'velomalo_invalid_extra_field', __( 'Additional Information fields require scalar values.', 'velox-map-locator' ), 'extra_fields' );
			}

			$id    = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			$label = isset( $field['label'] ) ? $this->plain_string( $field['label'], 100 ) : '';
			$type  = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
			$value = isset( $field['value'] ) ? (string) $field['value'] : '';

			if ( '' === $id || '' === $label || ! in_array( $type, $allowed, true ) || isset( $seen_ids[ $id ] ) ) {
				return $this->error( 'velomalo_invalid_extra_field', __( 'Additional Information fields require a unique ID, label, and supported type.', 'velox-map-locator' ), 'extra_fields' );
			}

			$seen_ids[ $id ] = true;

			if ( 'email' === $type ) {
				$value           = trim( $value );
				$sanitized_value = sanitize_email( $value );
				if ( '' !== $value && ( '' === $sanitized_value || ! is_email( $sanitized_value ) ) ) {
					return $this->error( 'velomalo_invalid_extra_field_email', __( 'An Additional Information email value is not valid.', 'velox-map-locator' ), 'extra_fields' );
				}
			} elseif ( 'url' === $type ) {
				$sanitized_value = $this->normalize_http_url( $value );
				if ( is_wp_error( $sanitized_value ) ) {
					return $this->error( 'velomalo_invalid_extra_field_url', __( 'An Additional Information URL must be a complete HTTP or HTTPS URL.', 'velox-map-locator' ), 'extra_fields' );
				}
			} else {
				$sanitized_value = $this->plain_string( $value, 1000 );
			}

			$output[] = array(
				'id'    => $id,
				'label' => $label,
				'type'  => $type,
				'value' => $sanitized_value,
			);
		}

		return $output;
	}

	/**
	 * Normalize and validate taxonomy term IDs.
	 *
	 * @param mixed  $input    Term IDs.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $field    Field path for validation errors.
	 * @return int[]|\WP_Error
	 */
	private function normalize_term_ids( $input, $taxonomy, $field ) {
		if ( ! is_array( $input ) ) {
			return $this->error( 'velomalo_invalid_terms', __( 'Location classifications must be supplied as a list of term IDs.', 'velox-map-locator' ), $field );
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $input ) ) ) );
		foreach ( $term_ids as $term_id ) {
			if ( ! term_exists( $term_id, $taxonomy ) ) {
				return $this->error( 'velomalo_invalid_term', __( 'One or more selected Location classifications no longer exist.', 'velox-map-locator' ), $field );
			}
		}

		return $term_ids;
	}

	/**
	 * Normalize a complete HTTP(S) URL.
	 *
	 * @param mixed $value URL input.
	 * @return string|\WP_Error
	 */
	private function normalize_http_url( $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( ! is_scalar( $value ) ) {
			return new \WP_Error( 'velomalo_invalid_http_url' );
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$url   = esc_url_raw( $value, array( 'http', 'https' ) );
		$parts = $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'velomalo_invalid_http_url' );
		}

		return $url;
	}

	/**
	 * Normalize an accepted boolean representation.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool|null
	 */
	private function normalize_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( 1 === $value || '1' === $value || ( is_string( $value ) && 'true' === strtolower( $value ) ) ) {
			return true;
		}

		if ( 0 === $value || '0' === $value || ( is_string( $value ) && in_array( strtolower( $value ), array( 'false', '' ), true ) ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Sanitize labels returned by the pure business-hours domain helper.
	 *
	 * @param array<int,array<string,mixed>> $hours Normalized special hours.
	 * @return array<int,array<string,mixed>>
	 */
	private function sanitize_special_hour_labels( $hours ) {
		foreach ( $hours as &$entry ) {
			$entry['label'] = $this->plain_string( $entry['label'], 120 );
		}
		unset( $entry );

		return $hours;
	}

	/**
	 * Sanitize a one-line value and cap its length.
	 *
	 * @param mixed $value  Input value.
	 * @param int   $length Maximum character count.
	 * @return string
	 */
	private function plain_string( $value, $length ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return $this->limit_string( $value, $length );
	}

	/**
	 * Sanitize a multiline value and cap its length.
	 *
	 * @param mixed $value  Input value.
	 * @param int   $length Maximum character count.
	 * @return string
	 */
	private function textarea_string( $value, $length ) {
		$value = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
		return $this->limit_string( $value, $length );
	}

	/**
	 * Limit a string without requiring mbstring.
	 *
	 * @param string $value  String value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private function limit_string( $value, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Build a validation error with field context.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @param string $field   Field path.
	 * @return \WP_Error
	 */
	private function error( $code, $message, $field ) {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}
}
