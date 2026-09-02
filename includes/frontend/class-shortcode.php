<?php
/**
 * Velox Map Locator shortcode.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the universal page-builder-compatible embed shortcode.
 */
final class Shortcode {

	/** Register shortcode. */
	public static function register() {
		add_shortcode( 'velox_map_locator', array( self::class, 'render' ) );
	}

	/**
	 * Render shortcode.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'velox_map_locator' );
		return Renderer::render_locator( absint( $atts['id'] ) );
	}
}
