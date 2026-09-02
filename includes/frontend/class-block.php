<?php
/**
 * Gutenberg block integration.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the dynamic Velox Map Locator block.
 */
final class Block {

	/** Register the block from metadata. */
	public static function register() {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
		$path = VELOX_MAP_LOCATOR_PATH . 'build/block';
		if ( ! function_exists( 'register_block_type' ) || ! is_readable( $path . '/block.json' ) ) {
			return;
		}
		register_block_type(
			$path,
			array(
				'render_callback' => array( self::class, 'render' ),
			)
		);
	}


	/** Load the normal public Locator stylesheet so ServerSideRender previews match the frontend. */
	public static function enqueue_editor_assets() {
		Assets::register_assets();
		if ( wp_style_is( 'velomalo-frontend', 'registered' ) ) {
			wp_enqueue_style( 'velomalo-frontend' );
		}
	}

	/** Render the selected Locator using the normal public renderer. */
	public static function render( $attributes ) {
		$locator_id = isset( $attributes['locatorId'] ) ? absint( $attributes['locatorId'] ) : 0;
		if ( ! $locator_id ) {
			return '';
		}
		return Renderer::render_locator( $locator_id );
	}
}
