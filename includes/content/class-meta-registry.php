<?php
/**
 * Metadata schema registration.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry for Location, Locator and Type metadata.
 */
final class Meta_Registry {

	/**
	 * Register all plugin metadata.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_location_meta();
		self::register_locator_meta();
		self::register_type_meta();
	}

	/**
	 * Register Location fields.
	 *
	 * @return void
	 */
	private static function register_location_meta() {
		$string_fields = array(
			'_velomalo_address_line_1',
			'_velomalo_address_line_2',
			'_velomalo_city',
			'_velomalo_region',
			'_velomalo_postal_code',
			'_velomalo_display_address',
			'_velomalo_timezone',
			'_velomalo_phone',
			'_velomalo_contact_name',
			'_velomalo_status_label',
			'_velomalo_status_note',
			'_velomalo_external_id',
			'_velomalo_geocoded_at',
		);

		$key_fields = array(
			'_velomalo_operational_status',
			'_velomalo_marker_icon',
			'_velomalo_marker_size',
			'_velomalo_geocode_source',
		);

		foreach ( $string_fields as $meta_key ) {
			register_post_meta( Post_Types::LOCATION, $meta_key, self::post_meta_args( 'string', array( self::class, 'sanitize_string' ) ) );
		}

		foreach ( $key_fields as $meta_key ) {
			register_post_meta( Post_Types::LOCATION, $meta_key, self::post_meta_args( 'string', 'sanitize_key' ) );
		}

		register_post_meta( Post_Types::LOCATION, '_velomalo_country_code', self::post_meta_args( 'string', array( self::class, 'sanitize_country_code' ) ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_email', self::post_meta_args( 'string', 'sanitize_email' ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_website', self::post_meta_args( 'string', 'esc_url_raw' ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_directions_url', self::post_meta_args( 'string', 'esc_url_raw' ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_marker_color', self::post_meta_args( 'string', array( self::class, 'sanitize_color' ) ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_marker_icon_color', self::post_meta_args( 'string', array( self::class, 'sanitize_color' ) ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_latitude', self::post_meta_args( 'number', array( self::class, 'sanitize_number' ) ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_longitude', self::post_meta_args( 'number', array( self::class, 'sanitize_number' ) ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_primary_type_id', self::post_meta_args( 'integer', 'absint' ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_marker_override', self::post_meta_args( 'boolean', array( self::class, 'sanitize_boolean' ) ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_marker_media_id', self::post_meta_args( 'integer', 'absint' ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_weekly_hours', self::post_meta_args( 'array', array( self::class, 'sanitize_structured_array' ) ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_special_hours', self::post_meta_args( 'array', array( self::class, 'sanitize_structured_array' ) ) );
		register_post_meta( Post_Types::LOCATION, '_velomalo_extra_fields', self::post_meta_args( 'array', array( self::class, 'sanitize_structured_array' ) ) );
	}

	/**
	 * Register Locator configuration metadata.
	 *
	 * @return void
	 */
	private static function register_locator_meta() {
		register_post_meta(
			Post_Types::LOCATOR,
			'_velomalo_config',
			self::post_meta_args( 'array', array( self::class, 'sanitize_structured_array' ) )
		);
	}

	/**
	 * Register Type marker defaults.
	 *
	 * @return void
	 */
	private static function register_type_meta() {
		$fields = array(
			'_velomalo_marker_icon'       => 'sanitize_key',
			'_velomalo_marker_color'      => array( self::class, 'sanitize_color' ),
			'_velomalo_marker_icon_color' => array( self::class, 'sanitize_color' ),
		);

		foreach ( $fields as $meta_key => $sanitize_callback ) {
			register_term_meta(
				Taxonomies::TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => false,
					'sanitize_callback' => $sanitize_callback,
					'auth_callback'     => array( self::class, 'can_manage_terms' ),
				)
			);
		}

		register_term_meta(
			Taxonomies::TYPE,
			'_velomalo_marker_media_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( self::class, 'can_manage_terms' ),
			)
		);
	}

	/**
	 * Build common post-meta arguments.
	 *
	 * @param string   $type              Registered meta type.
	 * @param callable $sanitize_callback Sanitization callback.
	 * @return array<string,mixed>
	 */
	private static function post_meta_args( $type, $sanitize_callback ) {
		$args = array(
			'type'              => $type,
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => $sanitize_callback,
			'auth_callback'     => array( self::class, 'can_edit_post_meta' ),
		);

		if ( 'array' === $type ) {
			$args['default'] = array();
		} elseif ( 'integer' === $type ) {
			$args['default'] = 0;
		} elseif ( 'boolean' === $type ) {
			$args['default'] = false;
		} elseif ( 'string' === $type ) {
			$args['default'] = '';
		}

		return $args;
	}

	/**
	 * Sanitize a plain string value.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function sanitize_string( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize an ISO-style country code.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function sanitize_country_code( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );

		return substr( $value, 0, 2 );
	}

	/**
	 * Sanitize a hexadecimal color value.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function sanitize_color( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$color = sanitize_hex_color( (string) $value );

		return $color ? $color : '';
	}

	/**
	 * Normalize a numeric metadata value.
	 *
	 * Domain validators enforce coordinate ranges before persistence through
	 * Velox application services. This callback provides a final type boundary.
	 *
	 * @param mixed $value Input value.
	 * @return float|null
	 */
	public static function sanitize_number( $value ) {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Normalize a boolean value.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	public static function sanitize_boolean( $value ) {
		$sanitized = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return null === $sanitized ? false : $sanitized;
	}

	/**
	 * Recursively sanitize structured arrays used by foundation schemas.
	 *
	 * More restrictive domain allowlists are applied by service validators when
	 * those services are introduced in later implementation slices.
	 *
	 * @param mixed $value Input value.
	 * @return array<mixed>
	 */
	public static function sanitize_structured_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $value as $key => $item ) {
			$safe_key = is_int( $key ) ? $key : sanitize_key( $key );

			if ( is_array( $item ) ) {
				$sanitized[ $safe_key ] = self::sanitize_structured_array( $item );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				$sanitized[ $safe_key ] = $item;
			} elseif ( is_scalar( $item ) ) {
				$sanitized[ $safe_key ] = sanitize_text_field( (string) $item );
			}
		}

		return $sanitized;
	}

	/**
	 * Check whether the current user may edit metadata for a post.
	 *
	 * @param bool   $allowed   Existing authorization result.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post ID.
	 * @return bool
	 */
	public static function can_edit_post_meta( $allowed, $meta_key, $object_id ) {
		unset( $allowed, $meta_key );

		return current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * Check whether the current user may manage Type metadata.
	 *
	 * @return bool
	 */
	public static function can_manage_terms() {
		return current_user_can( 'manage_velomalo_terms' );
	}
}
