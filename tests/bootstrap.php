<?php
/**
 * PHPUnit bootstrap for tests that do not require a running WordPress instance.
 */

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
}

require_once $root . '/includes/domain/class-coordinates.php';
require_once $root . '/includes/domain/class-business-hours.php';
