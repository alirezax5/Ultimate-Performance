<?php
/**
 * AUDIT TEST — Multisite object-cache isolation against a REAL backend (J-4).
 *
 * Repeats the release-blocking isolation rows (N2/N4/N5 class) with a real
 * persistent backend: Redis or Memcached (chosen by env), through the REAL
 * Manager. Cross-blog leakage on a real daemon = release blocker.
 *
 * Env: UC_MS_LIVE_BACKEND=redis|memcached + the backend's UC_REDIS_* /
 * UC_MEMCACHED_* config. Run under the php8.4 stack for ext availability
 * (redis ext also present in the static 8.3 build — both supported).
 *
 * Run: php tests/audit-oc-ms-live.php   (exit 0 only when all checks pass)
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\ObjectCache\Manager;
use UltimatePerformance\ObjectCache\MemcachedBackend;
use UltimatePerformance\ObjectCache\RedisBackend;

$results = array();
function mcheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$backend_name = getenv( 'UC_MS_LIVE_BACKEND' ) ?: '';
$backend      = null;
$chosen       = 'none';

if ( 'redis' === $backend_name && RedisBackend::configured() ) {
	$backend = new RedisBackend();
	$chosen  = 'redis';
} elseif ( 'memcached' === $backend_name && MemcachedBackend::configured() ) {
	$backend = new MemcachedBackend();
	$chosen  = 'memcached';
} elseif ( RedisBackend::configured() ) {
	$backend = new RedisBackend();
	$chosen  = 'redis';
} elseif ( MemcachedBackend::configured() ) {
	$backend = new MemcachedBackend();
	$chosen  = 'memcached';
}

if ( null === $backend ) {
	echo "[SKIP] audit-oc-ms-live << no real backend configured (UC_MS_LIVE_BACKEND + UC_REDIS_*/UC_MEMCACHED_*)\n";
	echo "\n==== SUMMARY ====\n0 checks executed, all skipped — environment-gated\n";
	exit( 0 );
}

$mgr = new Manager( array( $backend ) );
mcheck( $results, "M0 real backend healthy ({$chosen})", true === $mgr->backendHealthy() );

$blog2 = wpmu_create_blog( 'example.com', '/shop2/', 'Live blog 2', 1 );
$blog3 = wpmu_create_blog( 'live3.example.com', '/', 'Live blog 3 (subdomain)', 1 );
mcheck( $results, 'M0 blogs registered', 2 === $blog2 && 3 === $blog3 );

// M1: cross-blog write isolation on the real daemon.
switch_to_blog( 2 );
$mgr->set( 'ms-key', 'value-blog2', 'options' );
switch_to_blog( 3 );
$mgr->flushRuntime();
$mgr->get( 'ms-key', 'options', false, $f3 );
mcheck( $results, 'M1 blog 3 cannot read blog 2 value (real backend)', false === $f3 );
$mgr->set( 'ms-key', 'value-blog3', 'options' );
switch_to_blog( 2 );
$mgr->flushRuntime();
$v2 = $mgr->get( 'ms-key', 'options', false, $f2 );
mcheck( $results, 'M1 blog 2 value intact and distinct on the real backend', true === $f2 && 'value-blog2' === $v2 );

// M2: global group shared across blogs on the real backend.
$mgr->addGlobalGroups( 'live-ms-global' );
$mgr->set( 'net', 'shared-net', 'live-ms-global' );
switch_to_blog( 3 );
$mgr->flushRuntime();
$v3 = $mgr->get( 'net', 'live-ms-global', false, $f_glob );
mcheck( $results, 'M2 global group visible from blog 3 (real backend)', true === $f_glob && 'shared-net' === $v3 );
restore_current_blog();

// M3: flushGroup scoped — blog 3 flush must not touch blog 2's group.
switch_to_blog( 3 );
$mgr->set( 'g', 'b3-data', 'widgets' );
switch_to_blog( 2 );
$mgr->set( 'g', 'b2-data', 'widgets' );
switch_to_blog( 3 );
$mgr->flushRuntime();
$mgr->flushGroup( 'widgets' );
$mgr->get( 'g', 'widgets', false, $f_b3gone );
switch_to_blog( 2 );
$mgr->flushRuntime();
$v2b = $mgr->get( 'g', 'widgets', false, $f_b2keep );
mcheck( $results, 'M3 flushGroup in blog 3 cleared blog 3 data (real O(1))', false === $f_b3gone );
mcheck( $results, 'M3 flushGroup in blog 3 left blog 2 data intact (no cross-blog flush)', true === $f_b2keep && 'b2-data' === $v2b );

$mgr->flush();

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
