<?php
/**
 * Map provider contract.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the server-side contract for frontend map providers.
 */
interface Map_Provider_Interface {

	/** Provider identifier. */
	public function get_id();

	/** Human-readable label. */
	public function get_label();

	/** Provider capability flags. */
	public function get_capabilities();

	/** Whether this provider can render with current settings. */
	public function is_configured( $locator_config = array() );

	/** Public configuration safe to expose in a Locator payload. */
	public function get_public_config( $locator_config = array() );

	/** External service disclosure data. */
	public function get_external_service_info( $locator_config = array() );
}
