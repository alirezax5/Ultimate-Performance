<?php
/**
 * Child process for audit-rmq-fixes PB-3 scenarios.
 * Boots a minimal WP shim, reports OK/ERR for no-vendor / no-dir cases.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', dirname( __DIR__ ) . '/wp-shim/' ); // portable WP shim
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

$case = $argv[1] ?? '';

require_once __DIR__ . '/../../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require ABSPATH . 'wp-load.php';

if ( 'no_vendor' === $case ) {
	// Simulate a deployment without the vendored library.
	define( 'ULTIMATE_PERFORMANCE_NO_VENDOR', true );
	define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', __DIR__ ) . '/../../' );
} elseif ( 'no_dir' === $case ) {
	// ULTIMATE_PERFORMANCE_DIR intentionally NOT defined (production entrypoint
	// always defines it; this exercises the guard branch).
}

use UltimatePerformance\Queue\BackendImpl\RabbitMQ;

try {
	$b     = new RabbitMQ();
	$avail = $b->available();
	// Both cases must be unavailable WITHOUT fatal.
	echo ( false === $avail ) ? 'OK' : 'MISMATCH avail=' . var_export( $avail, true );
} catch ( Throwable $e ) {
	echo 'THROW ' . get_class( $e ) . ': ' . $e->getMessage();
}
