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

	/** Whether lazy map runtime bootstrap was already added for this request. */
	private static $lazy_runtime_configured = false;

	/** Register handles. */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	/** Register public assets without loading them globally. */
	public static function register_assets() {
		$css_path             = VELOX_MAP_LOCATOR_PATH . 'build/frontend.css';
		$js_path              = VELOX_MAP_LOCATOR_PATH . 'build/frontend.js';
		$polish_css_path      = VELOX_MAP_LOCATOR_PATH . 'build/frontend-1-1.css';
		$polish_js_path       = VELOX_MAP_LOCATOR_PATH . 'build/frontend-1-1.js';
		$stability_js_path    = VELOX_MAP_LOCATOR_PATH . 'build/frontend-1-1-stability.js';
		$map_js_path          = VELOX_MAP_LOCATOR_PATH . 'build/map-leaflet.js';
		$google_map_js        = VELOX_MAP_LOCATOR_PATH . 'build/map-google.js';
		$lazy_map_js          = VELOX_MAP_LOCATOR_PATH . 'build/lazy-map-runtime.js';
		$leaflet_js           = VELOX_MAP_LOCATOR_PATH . 'assets/vendor/leaflet/leaflet.min.js';
		$leaflet_css          = VELOX_MAP_LOCATOR_PATH . 'assets/vendor/leaflet/leaflet.min.css';

		wp_register_style(
			'velomalo-frontend',
			VELOX_MAP_LOCATOR_URL . 'build/frontend.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : VELOX_MAP_LOCATOR_VERSION
		);

		if ( file_exists( $polish_css_path ) && filesize( $polish_css_path ) > 0 ) {
			wp_register_style(
				'velomalo-frontend-1-1',
				VELOX_MAP_LOCATOR_URL . 'build/frontend-1-1.css',
				array( 'velomalo-frontend' ),
				(string) filemtime( $polish_css_path )
			);
		}

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

		if ( file_exists( $polish_js_path ) && filesize( $polish_js_path ) > 0 ) {
			wp_register_script(
				'velomalo-frontend-1-1',
				VELOX_MAP_LOCATOR_URL . 'build/frontend-1-1.js',
				array( 'velomalo-frontend' ),
				(string) filemtime( $polish_js_path ),
				true
			);
		}

		if ( file_exists( $stability_js_path ) && filesize( $stability_js_path ) > 0 ) {
			wp_register_script(
				'velomalo-frontend-1-1-stability',
				VELOX_MAP_LOCATOR_URL . 'build/frontend-1-1-stability.js',
				array( 'velomalo-frontend-1-1' ),
				(string) filemtime( $stability_js_path ),
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

		if ( file_exists( $lazy_map_js ) && filesize( $lazy_map_js ) > 0 ) {
			wp_register_script(
				'velomalo-lazy-map-runtime',
				VELOX_MAP_LOCATOR_URL . 'build/lazy-map-runtime.js',
				array(),
				(string) filemtime( $lazy_map_js ),
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
		if ( wp_style_is( 'velomalo-frontend-1-1', 'registered' ) ) {
			wp_enqueue_style( 'velomalo-frontend-1-1' );
		}
		if ( wp_script_is( 'velomalo-frontend', 'registered' ) ) {
			wp_enqueue_script( 'velomalo-frontend' );
		}
		if ( wp_script_is( 'velomalo-frontend-1-1', 'registered' ) ) {
			wp_enqueue_script( 'velomalo-frontend-1-1' );
		}
		if ( wp_script_is( 'velomalo-frontend-1-1-stability', 'registered' ) ) {
			wp_enqueue_script( 'velomalo-frontend-1-1-stability' );
		}
	}

	/**
	 * Enqueue one map engine only when a map-capable Locator renders.
	 *
	 * Interaction Privacy Mode registers the map runtime but does not request
	 * it until a visitor explicitly chooses to load the map.
	 *
	 * @param string $provider  Provider identifier.
	 * @param string $load_mode Resolved map load mode.
	 */
	public static function enqueue_map( $provider, $load_mode = 'immediate' ) {
		if ( ! wp_script_is( 'velomalo-map-leaflet', 'registered' ) && ! wp_script_is( 'velomalo-map-google', 'registered' ) ) {
			self::register_assets();
		}

		$provider  = sanitize_key( (string) $provider );
		$load_mode = sanitize_key( (string) $load_mode );

		if ( 'interaction' === $load_mode ) {
			self::configure_lazy_map_runtime();
			return;
		}

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

	/** Add one tiny click bootstrap for all gated Locators on the page. */
	private static function configure_lazy_map_runtime() {
		if ( self::$lazy_runtime_configured || ! wp_script_is( 'velomalo-frontend', 'registered' ) ) {
			return;
		}

		$assets = array(
			'loader'          => self::versioned_asset_url( 'build/lazy-map-runtime.js' ),
			'leafletJs'       => self::versioned_asset_url( 'assets/vendor/leaflet/leaflet.min.js', '1.9.4' ),
			'leafletCss'      => self::versioned_asset_url( 'assets/vendor/leaflet/leaflet.min.css', '1.9.4' ),
			'leafletAdapter'  => self::versioned_asset_url( 'build/map-leaflet.js' ),
			'googleAdapter'   => self::versioned_asset_url( 'build/map-google.js' ),
		);

		wp_add_inline_script( 'velomalo-frontend', self::lazy_map_bootstrap( $assets ), 'after' );
		self::$lazy_runtime_configured = true;
	}

	/** Return the click bootstrap used before any map-specific request is made. */
	private static function lazy_map_bootstrap( $assets ) {
		$json = wp_json_encode( $assets );
		if ( ! $json ) {
			return '';
		}

		return '(function(a){"use strict";if(window.VelomaloLazyMapBootstrap){window.VelomaloLazyMapBootstrap.assets=a;return;}var p=null;function d(r){var n=r.querySelector(".vml-locator__data");if(!n)return null;try{return JSON.parse(n.textContent||"{}");}catch(e){return null;}}function f(r,b,x){var s=x&&x.strings||{};r.dataset.vmlLazyRuntimeLoading="false";b.disabled=false;b.classList.remove("is-busy");b.setAttribute("aria-busy","false");b.textContent=s.retry_map||"Try again";var g=r.querySelector("[data-vml-map-privacy]");if(g){var c=Array.prototype.slice.call(g.children).find(function(n){return n.tagName==="SPAN"&&!n.classList.contains("vml-map-state__icon")&&!n.classList.contains("vml-map-state__provider");});if(c)c.textContent=s.map_load_failed||"The map resources could not be loaded. Please try again.";}}function l(){if(window.VelomaloLazyMapRuntime)return Promise.resolve();if(p)return p;p=new Promise(function(y,n){var e=document.createElement("script");e.src=a.loader;e.async=true;e.dataset.vmlLazyMapRuntime="true";var q=document.querySelector("script[nonce]");if(q&&q.nonce)e.nonce=q.nonce;e.onload=function(){window.VelomaloLazyMapRuntime?y():n(new Error("Lazy map runtime did not initialize."));};e.onerror=function(){e.remove();n(new Error("Lazy map runtime could not load."));};document.head.appendChild(e);}).catch(function(e){p=null;throw e;});return p;}function h(e){var b=e.target&&e.target.closest?e.target.closest("[data-vml-load-map]"):null;if(!b)return;var r=b.closest(".vml-locator[data-vml-instance]");if(!r)return;var x=d(r);if(!x||x.map_load_mode!=="interaction"||!x.map_provider)return;var m=x.map_provider.engine;if(m==="leaflet"&&window.VelomaloMapLeaflet){r.dataset.vmlInteractionApproved="true";window.VelomaloMapLeaflet.initializeRoot(r);return;}if(m==="google"&&window.VelomaloMapGoogle){r.dataset.vmlInteractionApproved="true";window.VelomaloMapGoogle.initializeRoot(r);return;}if(r.dataset.vmlLazyRuntimeLoading==="true"){e.preventDefault();return;}e.preventDefault();r.dataset.vmlInteractionApproved="true";r.dataset.vmlLazyRuntimeLoading="true";b.disabled=true;b.classList.add("is-busy");b.setAttribute("aria-busy","true");b.textContent=(x.strings&&x.strings.loading_map)||"Loading map…";l().then(function(){return window.VelomaloLazyMapRuntime.load(r,b,a);}).catch(function(){f(r,b,x);});}window.VelomaloLazyMapBootstrap={assets:a};document.addEventListener("click",h,true);})( ' . $json . ' );';
	}

	/** Return a versioned local plugin asset URL for dynamic loading. */
	private static function versioned_asset_url( $relative_path, $version = '' ) {
		$relative_path = ltrim( (string) $relative_path, '/' );
		$path          = VELOX_MAP_LOCATOR_PATH . $relative_path;
		$url           = VELOX_MAP_LOCATOR_URL . $relative_path;
		$version       = $version ? (string) $version : ( file_exists( $path ) ? (string) filemtime( $path ) : VELOX_MAP_LOCATOR_VERSION );
		return add_query_arg( 'ver', $version, $url );
	}

	/** Whether the local Leaflet runtime is physically present. */
	public static function leaflet_available() {
		return is_readable( VELOX_MAP_LOCATOR_PATH . 'assets/vendor/leaflet/leaflet.min.js' )
			&& is_readable( VELOX_MAP_LOCATOR_PATH . 'assets/vendor/leaflet/leaflet.min.css' );
	}
}
