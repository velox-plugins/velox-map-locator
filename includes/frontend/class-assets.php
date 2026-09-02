<?php
/**
 * Public locator assets.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and conditionally enqueues frontend assets.
 */
final class Assets {

	/** Register handles. */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	/** Register public assets without loading them globally. */
	public static function register_assets() {
		$css_path       = VELOX_MAP_LOCATOR_PATH . 'build/frontend.css';
		$js_path        = VELOX_MAP_LOCATOR_PATH . 'build/frontend.js';
		$map_js_path    = VELOX_MAP_LOCATOR_PATH . 'build/map-leaflet.js';
		$google_map_js  = VELOX_MAP_LOCATOR_PATH . 'build/map-google.js';
		$leaflet_js     = VELOX_MAP_LOCATOR_PATH . 'assets/vendor/leaflet/leaflet.min.js';
		$leaflet_css    = VELOX_MAP_LOCATOR_PATH . 'assets/vendor/leaflet/leaflet.min.css';

		wp_register_style(
			'velomalo-frontend',
			VELOX_MAP_LOCATOR_URL . 'build/frontend.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : VELOX_MAP_LOCATOR_VERSION
		);

		if ( file_exists( $leaflet_css ) && filesize( $leaflet_css ) > 0 ) {
			wp_register_style(
				'velomalo-leaflet',
				VELOX_MAP_LOCATOR_URL . 'assets/vendor/leaflet/leaflet.min.css',
				array(),
				'1.9.4'
			);
		}

		if ( file_exists( $leaflet_js ) && filesize( $leaflet_js ) > 0 ) {
			wp_register_script(
				'velomalo-leaflet',
				VELOX_MAP_LOCATOR_URL . 'assets/vendor/leaflet/leaflet.min.js',
				array(),
				'1.9.4',
				true
			);
		}

		if ( file_exists( $js_path ) && filesize( $js_path ) > 0 ) {
			wp_register_script(
				'velomalo-frontend',
				VELOX_MAP_LOCATOR_URL . 'build/frontend.js',
				array(),
				(string) filemtime( $js_path ),
				true
			);
		}

		if ( file_exists( $map_js_path ) && filesize( $map_js_path ) > 0 ) {
			wp_register_script(
				'velomalo-map-leaflet',
				VELOX_MAP_LOCATOR_URL . 'build/map-leaflet.js',
				array( 'velomalo-leaflet', 'velomalo-frontend' ),
				(string) filemtime( $map_js_path ),
				true
			);
		}

		if ( file_exists( $google_map_js ) && filesize( $google_map_js ) > 0 ) {
			wp_register_script(
				'velomalo-map-google',
				VELOX_MAP_LOCATOR_URL . 'build/map-google.js',
				array( 'velomalo-frontend' ),
				(string) filemtime( $google_map_js ),
				true
			);
		}
	}

	/** Enqueue base Locator assets only when a Locator renders. */
	public static function enqueue() {
		if ( ! wp_style_is( 'velomalo-frontend', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'velomalo-frontend' );
		if ( wp_script_is( 'velomalo-frontend', 'registered' ) ) {
			wp_enqueue_script( 'velomalo-frontend' );
		}
	}

	/**
	 * Enqueue one map engine only when a map-capable Locator renders.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function enqueue_map( $provider ) {
		if ( ! wp_script_is( 'velomalo-map-leaflet', 'registered' ) && ! wp_script_is( 'velomalo-map-google', 'registered' ) ) {
			self::register_assets();
		}

		$provider = sanitize_key( (string) $provider );
		if ( 'google' === $provider ) {
			if ( wp_script_is( 'velomalo-map-google', 'registered' ) ) {
				wp_enqueue_script( 'velomalo-map-google' );
			}
			return;
		}

		if ( ! in_array( $provider, array( 'osm', 'xyz' ), true ) ) {
			return;
		}

		if ( wp_style_is( 'velomalo-leaflet', 'registered' ) ) {
			wp_enqueue_style( 'velomalo-leaflet' );
		}
		if ( wp_script_is( 'velomalo-map-leaflet', 'registered' ) ) {
			wp_enqueue_script( 'velomalo-map-leaflet' );
		}
	}

	/** Whether the local Leaflet runtime is physically present. */
	public static function leaflet_available() {
		return is_readable( VELOX_MAP_LOCATOR_PATH . 'assets/vendor/leaflet/leaflet.min.js' )
			&& is_readable( VELOX_MAP_LOCATOR_PATH . 'assets/vendor/leaflet/leaflet.min.css' );
	}
}
