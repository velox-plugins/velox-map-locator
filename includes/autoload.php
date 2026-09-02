<?php
/**
 * Lightweight internal autoloader.
 *
 * @package VeloxMapLocator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'VeloxPlugins\\VeloxMapLocator\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$parts          = explode( '\\', $relative_class );
		$short_name     = array_pop( $parts );
		$is_interface   = '_Interface' === substr( $short_name, -10 );

		if ( $is_interface ) {
			$short_name = substr( $short_name, 0, -10 );
		}

		$directories = array_map(
			static function ( $part ) {
				return strtolower( str_replace( '_', '-', $part ) );
			},
			$parts
		);

		$filename = ( $is_interface ? 'interface-' : 'class-' ) . strtolower( str_replace( '_', '-', $short_name ) ) . '.php';
		$path     = VELOX_MAP_LOCATOR_PATH . 'includes/';

		if ( ! empty( $directories ) ) {
			$path .= implode( '/', $directories ) . '/';
		}

		$path .= $filename;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
