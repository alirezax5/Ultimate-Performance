<?php
/**
 * Child worker for audit-oc-sqlite-file — cross-process atomicity.
 *
 * Usage: php oc-sqlite-incr-child.php <plugin-dir> <dbfile> <key> <iterations> <group>
 *
 * Increments <key> <iterations> times through the REAL SqliteBackend
 * (transactional, WAL) — no direct SQL, no mocks.
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/../wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

list( , $plugin_dir, $dbfile, $key, $iters, $group ) = $argv;

require_once rtrim( $plugin_dir, '/' ) . '/src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

$backend = new \UltimatePerformance\ObjectCache\SqliteBackend( $dbfile );
for ( $i = 0; $i < (int) $iters; ++$i ) {
	$backend->incr( $key, 1, $group );
}
$backend->close();
echo 'DONE';
