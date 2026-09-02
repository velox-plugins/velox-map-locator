<?php
/**
 * Map provider registry.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Providers\Google_Provider;
use VeloxPlugins\VeloxMapLocator\Providers\Map_Provider_Interface;
use VeloxPlugins\VeloxMapLocator\Providers\OSM_Provider;
use VeloxPlugins\VeloxMapLocator\Providers\XYZ_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and resolves provider implementations.
 */
final class Provider_Registry {

	/** @var array<string,Map_Provider_Interface>|null */
	private $providers;

	/** Return all providers keyed by identifier. */
	public function all() {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		$providers = array(
			'osm'    => new OSM_Provider(),
			'google' => new Google_Provider(),
			'xyz'    => new XYZ_Provider(),
		);

		/**
		 * Filter registered map providers.
		 *
		 * @param array<string,Map_Provider_Interface> $providers Providers.
		 */
		$providers = apply_filters( 'velox_map_locator_registered_providers', $providers );
		$this->providers = array();
		foreach ( is_array( $providers ) ? $providers : array() as $id => $provider ) {
			if ( $provider instanceof Map_Provider_Interface ) {
				$this->providers[ sanitize_key( $id ) ] = $provider;
			}
		}
		return $this->providers;
	}

	/** Resolve one provider. */
	public function get( $id ) {
		$id        = sanitize_key( (string) $id );
		$providers = $this->all();
		return isset( $providers[ $id ] ) ? $providers[ $id ] : null;
	}
}
