<?php
/**
 * Locator configuration normalization and validation.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts untrusted Locator input into the canonical v1 schema.
 */
final class Locator_Validator {

	/** Configuration schema version. */
	const SCHEMA_VERSION = 1;

	/** Supported public card/popup fields. */
	const CONTENT_FIELDS = array( 'image', 'address', 'status', 'phone', 'email', 'website', 'contact', 'hours', 'description', 'directions', 'extra_fields', 'type' );

	/** Supported searchable fields. */
	const SEARCH_FIELDS = array( 'name', 'address', 'city', 'region', 'country', 'type', 'group', 'description', 'extra_fields' );

	/** Supported filter dimensions. */
	const FILTER_DIMENSIONS = array( 'type', 'group', 'country', 'city' );

	/** Supported theme families. */
	const THEMES = array( 'velox', 'slate', 'azure', 'forest' );

	/** Supported display modes. */
	const COLOR_MODES = array( 'light', 'dark', 'auto' );

	/**
	 * Return canonical defaults.
	 *
	 * Split becomes the default now that the first production map adapter is available.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults() {
		$global = Settings::defaults();
		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( Settings::OPTION_SETTINGS, $global );
			if ( is_array( $stored ) ) {
				$global = function_exists( 'sanitize_hex_color' ) ? Settings::sanitize_settings( $stored ) : array_replace_recursive( $global, $stored );
			}
		}
		$general = isset( $global['general'] ) && is_array( $global['general'] ) ? $global['general'] : Settings::defaults()['general'];
		$map_defaults = isset( $global['map_defaults'] ) && is_array( $global['map_defaults'] ) ? $global['map_defaults'] : Settings::defaults()['map_defaults'];
		$appearance_defaults = isset( $global['appearance'] ) && is_array( $global['appearance'] ) ? $global['appearance'] : Settings::defaults()['appearance'];

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'source'         => array(
				'mode'         => 'all',
				'selected_ids' => array(),
				'manual_order' => array(),
				'dynamic'      => array(
					'match'       => 'all',
					'conditions'  => array(),
					'exclude_ids' => array(),
				),
			),
			'content'        => array(
				'card_fields'  => array( 'address', 'status', 'directions' ),
				'popup_fields' => array( 'address', 'status', 'phone', 'email', 'hours', 'directions' ),
			),
			'layout'         => array(
				'mode'             => 'split',
				'sidebar_position' => 'auto',
				'height'           => (int) $map_defaults['height'],
				'sidebar_width'    => (int) $map_defaults['sidebar_width'],
				'density'          => 'comfortable',
				'mobile_order'     => 'map_first',
			),
			'map'            => array(
				'provider'             => (string) $general['default_map_provider'],
				'provider_profile_id'  => 'xyz' === $general['default_map_provider'] ? (string) $general['default_xyz_profile_id'] : '',
				'initial_view'         => 'fit',
				'fixed_latitude'       => 0.0,
				'fixed_longitude'      => 0.0,
				'fixed_zoom'           => 10,
				'single_location_zoom' => (int) $map_defaults['single_location_zoom'],
				'clustering'           => 'auto',
				'home_control'         => ! empty( $map_defaults['home_control'] ),
				'fit_control'          => ! empty( $map_defaults['fit_control'] ),
				'zoom_controls'        => ! empty( $map_defaults['zoom_controls'] ),
				'zoom_level_control'   => ! empty( $map_defaults['zoom_level_control'] ),
				'scale_control'        => ! empty( $map_defaults['scale_control'] ),
				'fullscreen'           => ! empty( $map_defaults['fullscreen'] ),
				'scroll_zoom'          => ! empty( $map_defaults['scroll_zoom'] ),
				'refit_on_filter'      => ! empty( $map_defaults['refit_on_filter'] ),
			),
			'search'         => array(
				'enabled'     => true,
				'placeholder' => __( 'Search locations…', 'velox-map-locator' ),
				'fields'      => array( 'name', 'address', 'city', 'type', 'group' ),
			),
			'filters'        => array(
				'style'             => 'pills',
				'dimensions'        => array( 'type' ),
				'show_result_count' => true,
			),
			'appearance'     => array(
				'theme'      => (string) $general['default_theme'],
				'mode'       => (string) $general['default_colour_mode'],
				'typography' => (string) $general['default_typography'],
				'density'    => (string) $appearance_defaults['density'],
				'accent'     => (string) $appearance_defaults['accent'],
			),
			'behaviour'      => array(
				'near_me'              => true,
				'distance_unit'        => (string) $general['default_distance_unit'],
				'deep_linking'         => true,
				'pan_on_select'        => true,
				'open_popup_on_select' => true,
			),
			'privacy'        => array(
				'map_load_mode' => 'inherit',
			),
		);
	}

	/**
	 * Normalize Locator payload.
	 *
	 * @param array<string,mixed> $input    Submitted values.
	 * @param array<string,mixed> $existing Existing canonical values for partial updates.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function normalize( $input, $existing = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = is_array( $existing ) ? $existing : array();
		$defaults = $this->defaults();

		$data = array(
			'name'   => isset( $existing['name'] ) ? (string) $existing['name'] : '',
			'status' => isset( $existing['status'] ) ? (string) $existing['status'] : 'draft',
			'config' => isset( $existing['config'] ) && is_array( $existing['config'] ) ? $this->merge_config( $defaults, $existing['config'] ) : $defaults,
		);

		if ( array_key_exists( 'name', $input ) ) {
			$data['name'] = $this->plain_string( $input['name'], 200 );
		}

		if ( array_key_exists( 'status', $input ) ) {
			$status = is_scalar( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
			if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
				return $this->error( 'velomalo_invalid_locator_status', __( 'Locator status must be Draft or Published.', 'velox-map-locator' ), 'status' );
			}
			$data['status'] = $status;
		}

		if ( array_key_exists( 'config', $input ) ) {
			if ( ! is_array( $input['config'] ) ) {
				return $this->error( 'velomalo_invalid_locator_config', __( 'Locator configuration must be an object.', 'velox-map-locator' ), 'config' );
			}
			$config = $this->normalize_config( $input['config'], $data['config'] );
			if ( is_wp_error( $config ) ) {
				return $config;
			}
			$data['config'] = $config;
		}

		if ( 'publish' === $data['status'] && '' === $data['name'] ) {
			return $this->error( 'velomalo_locator_name_required', __( 'A published Locator needs a name.', 'velox-map-locator' ), 'name' );
		}

		return $data;
	}

	/**
	 * Normalize configuration.
	 *
	 * @param array<string,mixed> $input    Submitted config.
	 * @param array<string,mixed> $existing Existing config.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_config( $input, $existing ) {
		$config = $this->merge_config( $this->defaults(), $existing );

		if ( isset( $input['schema_version'] ) && self::SCHEMA_VERSION !== absint( $input['schema_version'] ) ) {
			return $this->error( 'velomalo_locator_schema_unsupported', __( 'This Locator configuration schema is not supported by the current plugin version.', 'velox-map-locator' ), 'config.schema_version' );
		}
		$config['schema_version'] = self::SCHEMA_VERSION;

		if ( array_key_exists( 'source', $input ) ) {
			$source = $this->normalize_source( $input['source'], $config['source'] );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$config['source'] = $source;
		}

		if ( array_key_exists( 'content', $input ) ) {
			if ( ! is_array( $input['content'] ) ) {
				return $this->error( 'velomalo_invalid_locator_content', __( 'Locator content settings must be an object.', 'velox-map-locator' ), 'config.content' );
			}
			if ( array_key_exists( 'card_fields', $input['content'] ) ) {
				$config['content']['card_fields'] = $this->enum_list( $input['content']['card_fields'], self::CONTENT_FIELDS );
			}
			if ( array_key_exists( 'popup_fields', $input['content'] ) ) {
				$config['content']['popup_fields'] = $this->enum_list( $input['content']['popup_fields'], self::CONTENT_FIELDS );
			}
		}

		if ( array_key_exists( 'layout', $input ) ) {
			if ( ! is_array( $input['layout'] ) ) {
				return $this->error( 'velomalo_invalid_locator_layout', __( 'Locator layout settings must be an object.', 'velox-map-locator' ), 'config.layout' );
			}
			$layout = $input['layout'];
			if ( array_key_exists( 'mode', $layout ) ) {
				$config['layout']['mode'] = $this->enum_value( $layout['mode'], array( 'split', 'map_only', 'list_only' ), 'list_only' );
			}
			if ( array_key_exists( 'sidebar_position', $layout ) ) {
				$config['layout']['sidebar_position'] = $this->enum_value( $layout['sidebar_position'], array( 'auto', 'left', 'right' ), 'auto' );
			}
			if ( array_key_exists( 'height', $layout ) ) {
				$config['layout']['height'] = max( 300, min( 1200, absint( $layout['height'] ) ) );
			}
			if ( array_key_exists( 'sidebar_width', $layout ) ) {
				$config['layout']['sidebar_width'] = max( 20, min( 50, absint( $layout['sidebar_width'] ) ) );
			}
			if ( array_key_exists( 'density', $layout ) ) {
				$config['layout']['density'] = $this->enum_value( $layout['density'], array( 'compact', 'comfortable', 'spacious' ), 'comfortable' );
			}
			if ( array_key_exists( 'mobile_order', $layout ) ) {
				$config['layout']['mobile_order'] = $this->enum_value( $layout['mobile_order'], array( 'map_first', 'locations_first' ), 'map_first' );
			}
		}

		if ( array_key_exists( 'map', $input ) ) {
			if ( ! is_array( $input['map'] ) ) {
				return $this->error( 'velomalo_invalid_locator_map', __( 'Locator map settings must be an object.', 'velox-map-locator' ), 'config.map' );
			}
			$map = $input['map'];
			if ( array_key_exists( 'provider', $map ) ) {
				$config['map']['provider'] = $this->enum_value( $map['provider'], array( 'osm', 'google', 'xyz' ), 'osm' );
			}
			if ( array_key_exists( 'provider_profile_id', $map ) ) {
				$config['map']['provider_profile_id'] = is_scalar( $map['provider_profile_id'] ) ? substr( sanitize_key( (string) $map['provider_profile_id'] ), 0, 100 ) : '';
			}
			if ( array_key_exists( 'initial_view', $map ) ) {
				$config['map']['initial_view'] = $this->enum_value( $map['initial_view'], array( 'fit', 'fixed' ), 'fit' );
			}

			if ( array_key_exists( 'fixed_latitude', $map ) ) {
				$latitude = filter_var( $map['fixed_latitude'], FILTER_VALIDATE_FLOAT );
				if ( false === $latitude || $latitude < -90 || $latitude > 90 ) {
					return $this->error( 'velomalo_invalid_locator_fixed_latitude', __( 'Fixed map latitude must be between -90 and 90.', 'velox-map-locator' ), 'config.map.fixed_latitude' );
				}
				$config['map']['fixed_latitude'] = (float) $latitude;
			}
			if ( array_key_exists( 'fixed_longitude', $map ) ) {
				$longitude = filter_var( $map['fixed_longitude'], FILTER_VALIDATE_FLOAT );
				if ( false === $longitude || $longitude < -180 || $longitude > 180 ) {
					return $this->error( 'velomalo_invalid_locator_fixed_longitude', __( 'Fixed map longitude must be between -180 and 180.', 'velox-map-locator' ), 'config.map.fixed_longitude' );
				}
				$config['map']['fixed_longitude'] = (float) $longitude;
			}
			if ( array_key_exists( 'fixed_zoom', $map ) ) {
				$config['map']['fixed_zoom'] = max( 1, min( 22, absint( $map['fixed_zoom'] ) ) );
			}
			if ( array_key_exists( 'single_location_zoom', $map ) ) {
				$config['map']['single_location_zoom'] = max( 1, min( 22, absint( $map['single_location_zoom'] ) ) );
			}
			if ( array_key_exists( 'clustering', $map ) ) {
				$config['map']['clustering'] = $this->enum_value( $map['clustering'], array( 'auto', 'enabled', 'disabled' ), 'auto' );
			}
			foreach ( array( 'home_control', 'fit_control', 'zoom_controls', 'zoom_level_control', 'scale_control', 'fullscreen', 'scroll_zoom', 'refit_on_filter' ) as $boolean_key ) {
				if ( array_key_exists( $boolean_key, $map ) ) {
					$config['map'][ $boolean_key ] = $this->boolean_value( $map[ $boolean_key ], $config['map'][ $boolean_key ] );
				}
			}
		}

		if ( array_key_exists( 'search', $input ) ) {
			if ( ! is_array( $input['search'] ) ) {
				return $this->error( 'velomalo_invalid_locator_search', __( 'Locator search settings must be an object.', 'velox-map-locator' ), 'config.search' );
			}
			if ( array_key_exists( 'enabled', $input['search'] ) ) {
				$config['search']['enabled'] = $this->boolean_value( $input['search']['enabled'], true );
			}
			if ( array_key_exists( 'placeholder', $input['search'] ) ) {
				$config['search']['placeholder'] = $this->plain_string( $input['search']['placeholder'], 120 );
			}
			if ( array_key_exists( 'fields', $input['search'] ) ) {
				$config['search']['fields'] = $this->enum_list( $input['search']['fields'], self::SEARCH_FIELDS );
			}
		}

		if ( array_key_exists( 'filters', $input ) ) {
			if ( ! is_array( $input['filters'] ) ) {
				return $this->error( 'velomalo_invalid_locator_filters', __( 'Locator filter settings must be an object.', 'velox-map-locator' ), 'config.filters' );
			}
			if ( array_key_exists( 'style', $input['filters'] ) ) {
				$config['filters']['style'] = $this->enum_value( $input['filters']['style'], array( 'pills', 'dropdown' ), 'pills' );
			}
			if ( array_key_exists( 'dimensions', $input['filters'] ) ) {
				$config['filters']['dimensions'] = $this->enum_list( $input['filters']['dimensions'], self::FILTER_DIMENSIONS );
			}
			if ( array_key_exists( 'show_result_count', $input['filters'] ) ) {
				$config['filters']['show_result_count'] = $this->boolean_value( $input['filters']['show_result_count'], true );
			}
		}

		if ( array_key_exists( 'appearance', $input ) ) {
			if ( ! is_array( $input['appearance'] ) ) {
				return $this->error( 'velomalo_invalid_locator_appearance', __( 'Locator appearance settings must be an object.', 'velox-map-locator' ), 'config.appearance' );
			}
			$appearance = $input['appearance'];
			if ( array_key_exists( 'theme', $appearance ) ) {
				$config['appearance']['theme'] = $this->enum_value( $appearance['theme'], self::THEMES, 'velox' );
			}
			if ( array_key_exists( 'mode', $appearance ) ) {
				$config['appearance']['mode'] = $this->enum_value( $appearance['mode'], self::COLOR_MODES, 'light' );
			}
			if ( array_key_exists( 'typography', $appearance ) ) {
				$config['appearance']['typography'] = $this->enum_value( $appearance['typography'], array( 'inherit', 'modern-sans', 'humanist-sans', 'classic-sans', 'serif' ), 'inherit' );
			}
			if ( array_key_exists( 'density', $appearance ) ) {
				$config['appearance']['density'] = $this->enum_value( $appearance['density'], array( 'compact', 'comfortable', 'spacious' ), 'comfortable' );
			}
			if ( array_key_exists( 'accent', $appearance ) ) {
				$accent = is_scalar( $appearance['accent'] ) ? sanitize_hex_color( (string) $appearance['accent'] ) : false;
				$config['appearance']['accent'] = $accent ? $accent : '';
			}
		}

		if ( array_key_exists( 'behaviour', $input ) ) {
			if ( ! is_array( $input['behaviour'] ) ) {
				return $this->error( 'velomalo_invalid_locator_behaviour', __( 'Locator behaviour settings must be an object.', 'velox-map-locator' ), 'config.behaviour' );
			}
			foreach ( array( 'near_me', 'deep_linking', 'pan_on_select', 'open_popup_on_select' ) as $boolean_key ) {
				if ( array_key_exists( $boolean_key, $input['behaviour'] ) ) {
					$config['behaviour'][ $boolean_key ] = $this->boolean_value( $input['behaviour'][ $boolean_key ], $config['behaviour'][ $boolean_key ] );
				}
			}
			if ( array_key_exists( 'distance_unit', $input['behaviour'] ) ) {
				$config['behaviour']['distance_unit'] = $this->enum_value( $input['behaviour']['distance_unit'], array( 'auto', 'kilometres', 'miles' ), 'auto' );
			}
		}

		if ( array_key_exists( 'privacy', $input ) ) {
			if ( ! is_array( $input['privacy'] ) ) {
				return $this->error( 'velomalo_invalid_locator_privacy', __( 'Locator privacy settings must be an object.', 'velox-map-locator' ), 'config.privacy' );
			}
			if ( array_key_exists( 'map_load_mode', $input['privacy'] ) ) {
				$config['privacy']['map_load_mode'] = $this->enum_value( $input['privacy']['map_load_mode'], array( 'inherit', 'immediate', 'interaction' ), 'inherit' );
			}
		}

		return $config;
	}

	/**
	 * Normalize source selection.
	 *
	 * @param mixed               $input    Source input.
	 * @param array<string,mixed> $existing Existing source.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_source( $input, $existing ) {
		if ( ! is_array( $input ) ) {
			return $this->error( 'velomalo_invalid_locator_source', __( 'Locator source settings must be an object.', 'velox-map-locator' ), 'config.source' );
		}

		$source = is_array( $existing ) ? $existing : $this->defaults()['source'];
		if ( array_key_exists( 'mode', $input ) ) {
			$source['mode'] = $this->enum_value( $input['mode'], array( 'all', 'selected', 'dynamic' ), 'all' );
		}
		if ( array_key_exists( 'selected_ids', $input ) ) {
			$source['selected_ids'] = $this->id_list( $input['selected_ids'] );
		}
		if ( array_key_exists( 'manual_order', $input ) ) {
			$source['manual_order'] = $this->id_list( $input['manual_order'] );
		}
		if ( array_key_exists( 'dynamic', $input ) ) {
			if ( ! is_array( $input['dynamic'] ) ) {
				return $this->error( 'velomalo_invalid_locator_dynamic_source', __( 'Dynamic source settings must be an object.', 'velox-map-locator' ), 'config.source.dynamic' );
			}
			$dynamic = $source['dynamic'];
			if ( array_key_exists( 'match', $input['dynamic'] ) ) {
				$dynamic['match'] = $this->enum_value( $input['dynamic']['match'], array( 'all', 'any' ), 'all' );
			}
			if ( array_key_exists( 'exclude_ids', $input['dynamic'] ) ) {
				$dynamic['exclude_ids'] = $this->id_list( $input['dynamic']['exclude_ids'] );
			}
			if ( array_key_exists( 'conditions', $input['dynamic'] ) ) {
				if ( ! is_array( $input['dynamic']['conditions'] ) ) {
					return $this->error( 'velomalo_invalid_locator_conditions', __( 'Dynamic Locator conditions must be a list.', 'velox-map-locator' ), 'config.source.dynamic.conditions' );
				}
				$conditions = array();
				foreach ( array_slice( array_values( $input['dynamic']['conditions'] ), 0, 20 ) as $index => $condition ) {
					$normalized = $this->normalize_condition( $condition, $index );
					if ( is_wp_error( $normalized ) ) {
						return $normalized;
					}
					$conditions[] = $normalized;
				}
				$dynamic['conditions'] = $conditions;
			}
			$source['dynamic'] = $dynamic;
		}

		if ( 'selected' === $source['mode'] && empty( $source['manual_order'] ) ) {
			$source['manual_order'] = $source['selected_ids'];
		}

		return $source;
	}

	/**
	 * Normalize one dynamic condition.
	 *
	 * @param mixed $condition Condition input.
	 * @param int   $index     Condition index.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_condition( $condition, $index ) {
		if ( ! is_array( $condition ) ) {
			return $this->error( 'velomalo_invalid_locator_condition', __( 'Each dynamic Locator condition must be an object.', 'velox-map-locator' ), 'config.source.dynamic.conditions.' . absint( $index ) );
		}
		$field = isset( $condition['field'] ) && is_scalar( $condition['field'] ) ? sanitize_key( (string) $condition['field'] ) : '';
		if ( ! in_array( $field, self::FILTER_DIMENSIONS, true ) ) {
			return $this->error( 'velomalo_invalid_locator_condition_field', __( 'Dynamic Locator condition field is not supported.', 'velox-map-locator' ), 'config.source.dynamic.conditions.' . absint( $index ) . '.field' );
		}
		$operator = isset( $condition['operator'] ) && is_scalar( $condition['operator'] ) ? sanitize_key( (string) $condition['operator'] ) : 'is';
		if ( 'is' !== $operator ) {
			return $this->error( 'velomalo_invalid_locator_condition_operator', __( 'Dynamic Locator condition operator is not supported.', 'velox-map-locator' ), 'config.source.dynamic.conditions.' . absint( $index ) . '.operator' );
		}

		$value = isset( $condition['value'] ) ? $condition['value'] : '';
		if ( in_array( $field, array( 'type', 'group' ), true ) ) {
			$value = absint( $value );
			$taxonomy = 'type' === $field ? Taxonomies::TYPE : Taxonomies::GROUP;
			if ( ! $value || ! term_exists( $value, $taxonomy ) ) {
				return $this->error( 'velomalo_invalid_locator_condition_value', __( 'Dynamic Locator condition refers to a classification that does not exist.', 'velox-map-locator' ), 'config.source.dynamic.conditions.' . absint( $index ) . '.value' );
			}
		} elseif ( 'country' === $field ) {
			$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', is_scalar( $value ) ? (string) $value : '' ) );
			if ( 2 !== strlen( $value ) ) {
				return $this->error( 'velomalo_invalid_locator_condition_value', __( 'Country conditions require a two-letter country code.', 'velox-map-locator' ), 'config.source.dynamic.conditions.' . absint( $index ) . '.value' );
			}
		} else {
			$value = $this->plain_string( $value, 120 );
			if ( '' === $value ) {
				return $this->error( 'velomalo_invalid_locator_condition_value', __( 'Dynamic Locator condition value cannot be empty.', 'velox-map-locator' ), 'config.source.dynamic.conditions.' . absint( $index ) . '.value' );
			}
		}

		return array( 'field' => $field, 'operator' => 'is', 'value' => $value );
	}

	/** Merge only known default keys recursively. */
	private function merge_config( $defaults, $values ) {
		$output = $defaults;
		if ( ! is_array( $values ) ) {
			return $output;
		}
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			if ( is_array( $default ) && $this->is_assoc( $default ) && is_array( $values[ $key ] ) ) {
				$output[ $key ] = $this->merge_config( $default, $values[ $key ] );
			} else {
				$output[ $key ] = $values[ $key ];
			}
		}
		return $output;
	}

	/** Determine whether an array is associative. */
	private function is_assoc( $value ) {
		if ( ! is_array( $value ) || array() === $value ) {
			return false;
		}
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/** Normalize list of IDs. */
	private function id_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
		return array_slice( $ids, 0, 5000 );
	}

	/** Normalize an allowlisted list. */
	private function enum_list( $value, $allowed ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$output = array();
		foreach ( $value as $item ) {
			$item = is_scalar( $item ) ? sanitize_key( (string) $item ) : '';
			if ( in_array( $item, $allowed, true ) && ! in_array( $item, $output, true ) ) {
				$output[] = $item;
			}
		}
		return $output;
	}

	/** Normalize one allowlisted scalar. */
	private function enum_value( $value, $allowed, $fallback ) {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/** Normalize a boolean without treating arbitrary strings as true. */
	private function boolean_value( $value, $fallback ) {
		$normalized = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $normalized ? (bool) $fallback : $normalized;
	}

	/** Normalize plain text. */
	private function plain_string( $value, $max_length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
	}

	/** Build validation error. */
	private function error( $code, $message, $field ) {
		return new \WP_Error( $code, $message, array( 'status' => 400, 'field' => $field ) );
	}
}
