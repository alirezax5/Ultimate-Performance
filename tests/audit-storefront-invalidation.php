<?php
/**
 * FINDING-E (Phase 15) — Storefront visibility invalidation regression test.
 *
 * Verifies that the Ultimate Performance plugin registers hooks on:
 *   - update_option_woocommerce_coming_soon
 *   - update_option_woocommerce_demo_store
 *
 * When the WooCommerce "Coming Soon" / "Live" or "Demo Store" toggle changes,
 * every cached public page becomes stale. The fix wires these option-update
 * hooks to purge_site_for_storefront_toggle(), which deletes the entire
 * v/<host>/ cache tree.
 *
 * This test is a SOURCE-LEVEL test (no production state change). It verifies:
 *   - The hooks are registered by Hooks::register()
 *   - The purge_site_for_storefront_toggle() method exists and is callable
 *   - When the action fires, the cache tree is actually deleted
 *
 * Run: php tests/audit-storefront-invalidation.php
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', __DIR__ ) . '/../' );

require_once ULTIMATE_PERFORMANCE_DIR . 'vendor/autoload.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require ABSPATH . 'wp-load.php';

use UltimatePerformance\CacheInvalidation\Hooks;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;

$results = array();
function scheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== FINDING-E (Phase 15) Storefront Invalidation Test ===\n\n";

// S1: Method exists
$ref = new \ReflectionMethod( Hooks::class, 'purge_site_for_storefront_toggle' );
scheck( $results, 'S1 purge_site_for_storefront_toggle() method exists', $ref->isPublic(), 'method missing or not public' );

// S2: Hooks are registered
Hooks::register();
$has_coming_soon = has_action( 'update_option_woocommerce_coming_soon' );
$has_demo_store  = has_action( 'update_option_woocommerce_demo_store' );
scheck( $results, 'S2 update_option_woocommerce_coming_soon has action registered', $has_coming_soon > 0, 'hook not registered' );
scheck( $results, 'S3 update_option_woocommerce_demo_store has action registered', $has_demo_store > 0, 'hook not registered' );

// S4: Source scan confirms the registration
$src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/CacheInvalidation/Hooks.php' );
scheck( $results, 'S4 source contains update_option_woocommerce_coming_soon hook', false !== strpos( $src, "add_action( 'update_option_woocommerce_coming_soon'" ) || false !== strpos( $src, 'add_action( "update_option_woocommerce_coming_soon"' ), 'hook registration not in source' );
scheck( $results, 'S5 source contains update_option_woocommerce_demo_store hook', false !== strpos( $src, "add_action( 'update_option_woocommerce_demo_store'" ) || false !== strpos( $src, 'add_action( "update_option_woocommerce_demo_store"' ), 'hook registration not in source' );

// S6: Functional test — purge_site_for_storefront_toggle() actually deletes the cache tree
// Create a fake cache artifact, then call the method, then verify it's gone.
$cache_root = Installer::cache_root();
$vroot     = $cache_root . '/v';
$test_host = 'storefront-test.example';
$test_dir  = $vroot . '/' . $test_host . '/test-page';
$test_file = $test_dir . '/index.html';
$test_meta = $test_dir . '/index.html.meta.json';

@mkdir( $test_dir, 0775, true );
file_put_contents( $test_file, '<html><body>test artifact</body></html>' );
file_put_contents( $test_meta, '{"created":' . time() . ',"ttl":3600,"status":200,"headers":{"Content-Type":"text/html"}}' );

scheck( $results, 'S6a test artifact created', file_exists( $test_file ), 'could not create test artifact' );

// Instantiate Hooks with a test Settings (cap bypassed via ULTIMATE_PERFORMANCE_TESTING define)
$settings = Settings::instance();
$hooks    = new Hooks( $settings );

// Call the storefront toggle purge method
try {
	$count = $hooks->purge_site_for_storefront_toggle();
	scheck( $results, 'S6b purge_site_for_storefront_toggle() returned non-false', false !== $count, 'method returned false' );
} catch ( \Throwable $e ) {
	scheck( $results, 'S6b purge_site_for_storefront_toggle() returned non-false', false, 'threw: ' . $e->getMessage() );
}

// S6c: test artifact should be GONE
scheck( $results, 'S6c test artifact deleted by purge_site_for_storefront_toggle()', ! file_exists( $test_file ), 'test artifact still exists' );

// S6d: meta should also be gone
scheck( $results, 'S6d meta directory deleted', ! is_dir( $vroot . '/' . $test_host ) || ! file_exists( $test_meta ), 'meta still exists' );

// S7: node-id.json preservation (cluster identity survives purge)
$node_id_path = $cache_root . '/meta/node-id.json';
@mkdir( dirname( $node_id_path ), 0775, true );
file_put_contents( $node_id_path, '{"node_id":"test-node-identity"}', LOCK_EX );
@chmod( $node_id_path, 0640 );

// Re-create test artifact
@mkdir( $test_dir, 0775, true );
file_put_contents( $test_file, '<html><body>test2</body></html>' );

$hooks->purge_site_for_storefront_toggle();
$node_id_after = file_exists( $node_id_path ) ? file_get_contents( $node_id_path ) : '';
scheck( $results, 'S7 node-id.json preserved across purge_site_for_storefront_toggle()', false !== strpos( $node_id_after, 'test-node-identity' ), 'node identity was not preserved' );

// Cleanup
@unlink( $node_id_path );

// Summary
echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) {
	if ( ! $v ) {
		++$fails;
		echo "FAIL: $k\n";
	}
}
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
