<?php
/**
 * Child worker for audit-object-cache-live S5 — cross-process atomicity.
 *
 * Usage: php oc-incr-child.php <plugin-dir> <host> <port> <key> <iterations>
 *
 * Increments <key> <iterations> times through the REAL
 * Manager(RedisBackend) stack — no direct Redis calls, no mocks.
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/../wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

list( , $plugin_dir, $host, $port, $key, $iters ) = $argv;

require_once rtrim( $plugin_dir, '/' ) . '/src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

$backend = new \UltimatePerformance\ObjectCache\RedisBackend( array( 'host' => $host, 'port' => (int) $port, 'timeout' => 1.0 ) );
$mgr     = new \UltimatePerformance\ObjectCache\Manager( $backend );

for ( $i = 0; $i < (int) $iters; ++$i ) {
	$mgr->incr( $key, 1, 'g' );
}
$backend->close();
echo 'DONE';
