<?php
/**
 * Frontend presentation compatibility for the 1.1 release line.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Frontend;

use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;
use VeloxPlugins\VeloxMapLocator\Services\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds presentation metadata and public-safe Location fields without changing
 * the stored Locator schema used by the 1.0 release.
 */
final class Frontend_Polish {

	/** Register presentation filters. */
	public static function register() {
		add_filter( 'velox_map_locator_locator_public_config', array( self::class, 'filter_config' ), 20, 2 );
		add_filter( 'velox_map_locator_location_public_data', array( self::class, 'filter_location' ), 20, 3 );
	}

	/**
	 * Add site-level presentation information to the public Locator config.
	 *
	 * @param array<string,mixed> $config     Public Locator config.
	 * @param int                 $locator_id Locator ID.
	 * @return array<string,mixed>
	 */
	public static function filter_config( $config, $locator_id ) {
		$config = is_array( $config ) ? $config : array();

		$stored   = get_option( Settings::OPTION_SETTINGS, Settings::defaults() );
		$settings = Settings::sanitize_settings( is_array( $stored ) ? $stored : array() );
		$appearance = isset( $settings['appearance'] ) && is_array( $settings['appearance'] ) ? $settings['appearance'] : Settings::defaults()['appearance'];

		if ( ! isset( $config['appearance'] ) || ! is_array( $config['appearance'] ) ) {
			$config['appearance'] = array();
		}
		$config['appearance']['radius'] = isset( $appearance['radius'] ) ? absint( $appearance['radius'] ) : 10;
		$config['appearance']['shadow'] = isset( $appearance['shadow'] ) ? sanitize_key( $appearance['shadow'] ) : 'soft';

		if ( ! isset( $config['map'] ) || ! is_array( $config['map'] ) ) {
			$config['map'] = array();
		}
		// The visible Z-level badge was useful during development but is not a
		// meaningful end-user control. Keep the stored schema backward compatible
		// while suppressing it in the public presentation.
		$config['map']['zoom_level_control'] = false;

		if ( ! isset( $config['search'] ) || ! is_array( $config['search'] ) ) {
			$config['search'] = array();
		}
		$fields = isset( $config['search']['fields'] ) && is_array( $config['search']['fields'] ) ? $config['search']['fields'] : array();
		if ( ! in_array( 'postcode', $fields, true ) ) {
			$fields[] = 'postcode';
		}
		$config['search']['fields'] = $fields;

		$old_placeholder = __( 'Search locations…', 'velox-map-locator' );
		if ( empty( $config['search']['placeholder'] ) || $old_placeholder === $config['search']['placeholder'] ) {
			$config['search']['placeholder'] = __( 'Search by name, city or postcode…', 'velox-map-locator' );
		}

		$provider_id   = isset( $config['map']['provider'] ) ? sanitize_key( (string) $config['map']['provider'] ) : 'osm';
		$provider      = ( new Provider_Registry() )->get( $provider_id );
		$provider_name = $provider ? $provider->get_label() : __( 'the configured map provider', 'velox-map-locator' );
		$service_host  = '';
		if ( $provider ) {
			$service = $provider->get_external_service_info( $config );
			if ( is_array( $service ) ) {
				if ( ! empty( $service['name'] ) ) {
					$provider_name = sanitize_text_field( (string) $service['name'] );
				}
				if ( ! empty( $service['service_url'] ) ) {
					$service_host = wp_parse_url( esc_url_raw( (string) $service['service_url'] ), PHP_URL_HOST );
					$service_host = is_string( $service_host ) ? $service_host : '';
				}
			}
		}

		$locale = determine_locale();
		if ( ! is_string( $locale ) || '' === $locale ) {
			$locale = get_locale();
		}

		$config['presentation'] = array(
			'locale'             => (string) $locale,
			'time_format'        => (string) get_option( 'time_format', 'g:i a' ),
			'auto_distance_unit' => self::distance_unit_for_locale( $locale ),
			'provider_name'      => $provider_name,
			'provider_host'      => $service_host,
			'locator_id'         => absint( $locator_id ),
			'strings'            => array(
				'privacy_title'       => __( 'Map not loaded yet', 'velox-map-locator' ),
				'privacy_body'        => __( 'The location directory works without contacting the map provider. No map request has been made yet.', 'velox-map-locator' ),
				/* translators: %s: External map provider name. */
				'privacy_provider'    => __( 'Loading this map will connect your browser to %s.', 'velox-map-locator' ),
				'load_map'            => __( 'Load interactive map', 'velox-map-locator' ),
				'sorted_by_distance'  => __( '%s sorted by distance', 'velox-map-locator' ),
				'near_me'             => __( 'Near Me', 'velox-map-locator' ),
				'open_now'            => __( 'Open now', 'velox-map-locator' ),
				'location_one'        => __( '1 location', 'velox-map-locator' ),
				/* translators: %d: Number of Locations. */
				'locations_many'      => __( '%d locations', 'velox-map-locator' ),
			),
		);

		return $config;
	}

	/**
	 * Add structured postcode and primary Type data to public Location payloads.
	 *
	 * These are already public directory fields; the filter simply makes them
	 * available consistently to the enhanced search/card presentation.
	 *
	 * @param array<string,mixed> $output      Public Location data.
	 * @param int                 $location_id Location ID.
	 * @param int                 $locator_id  Locator ID.
	 * @return array<string,mixed>
	 */
	public static function filter_location( $output, $location_id, $locator_id ) {
		$output = is_array( $output ) ? $output : array();
		$location_id = absint( $location_id );

		$postcode = get_post_meta( $location_id, '_velomalo_postal_code', true );
		if ( is_scalar( $postcode ) && '' !== trim( (string) $postcode ) ) {
			$output['postal_code'] = sanitize_text_field( (string) $postcode );
		}

		if ( empty( $output['types'] ) ) {
			$primary_type_id = absint( get_post_meta( $location_id, '_velomalo_primary_type_id', true ) );
			if ( $primary_type_id ) {
				$term = get_term( $primary_type_id, Taxonomies::TYPE );
				if ( $term && ! is_wp_error( $term ) ) {
					$output['types'] = array(
						array(
							'id'   => (int) $term->term_id,
							'name' => $term->name,
							'slug' => $term->slug,
						),
					);
				}
			}
		}

		return $output;
	}

	/** Resolve automatic distance units from the WordPress site locale. */
	private static function distance_unit_for_locale( $locale ) {
		$locale = strtoupper( str_replace( '-', '_', (string) $locale ) );
		$parts  = array_values( array_filter( explode( '_', $locale ) ) );
		$region = count( $parts ) > 1 ? end( $parts ) : '';

		return in_array( $region, array( 'US', 'LR', 'MM' ), true ) ? 'miles' : 'kilometres';
	}
}
