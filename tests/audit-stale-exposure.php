<?php
/**
 * BENCH-D6 regression test — stale exposure on post edit.
 *
 * HARDEN-4: Verifies that when a post is edited, its own permalink cache
 * is purged SYNCHRONOUSLY (stale exposure ≈ 0 seconds for the edited page).
 *
 * The hybrid design:
 *   - sync_purge_permalink() runs immediately on save_post, purging the
 *     post's own cache entry via purge_url() → purge_dir()
 *   - The broader tag-based purge (archives, front_page, terms) goes
 *     through purge_tags() → QueueManager (async, for secondary pages)
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

use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\CacheInvalidation\Hooks;

$results = array();
function scheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== BENCH-D6 Stale Exposure Regression Test ===\n";

// Setup.
Installer::ensure_cache_root();
$sfs = new SafeFs();
$settings = Settings::instance();
$keygen = new Key( $settings );
$store = new Store( $sfs, $keygen );

// Clean state.
$sfs->delete_tree( Installer::cache_root() . '/v' );
Installer::ensure_cache_root();

// Register invalidation hooks (simulates Plugin::late_boot).
Hooks::register();

// ============================================================
// Test 1: Simulate V1 → edit → V2 with sync purge
// ============================================================
echo "\n--- Test 1: V1 cached, edit to V2, verify V1 purged synchronously ---\n";

$host = 'localhost'; // Must match home_url() in the shim.
$path = '/test-page/';
$permalink = 'http://' . $host . $path;

// Register a post so get_permalink() resolves correctly.
$post_id = wp_insert_post( array(
        'post_title'  => 'Test Page',
        'post_status' => 'publish',
        'post_type'   => 'page',
        'post_name'   => 'test-page',
) );

// Build the cache dir for this URL.
$built = $keygen->build( 'http', $host, $path, '', array() );
$rel_dir = $built['dir'];

// Write V1 cache.
$v1_marker = 'V1_MARKER_' . uniqid();
$v1_html = '<!DOCTYPE html><html><body><h1>' . $v1_marker . '</h1><p>Original content.</p></body></html>';
$store->write( $rel_dir, $v1_html, 200, array( 'Content-Type' => 'text/html; charset=UTF-8' ), array( 'post:' . $post_id ), 3600 );

// Verify V1 is cached.
$lookup = $store->lookup( $rel_dir );
scheck( $results, 'S1a V1 cache written', $lookup['found'] && $lookup['fresh'], "found={$lookup['found']} fresh={$lookup['fresh']}" );
scheck( $results, 'S1b V1 body matches', false !== strpos( $lookup['body'], $v1_marker ) );

// Get the actual post object.
$post = get_post( $post_id );

// Monkey-patch: the shim's get_permalink uses the post_name.
// We set up the shim to resolve /test-page/ for post ID $post_id.
// Since the shim is minimal, we call purge_post directly with our post object.
$hooks = new Hooks( $settings );

// Before purge: V1 should be in cache.
$before = $store->lookup( $rel_dir );
scheck( $results, 'S1c V1 still cached before edit', $before['found'] && $before['fresh'] );

// Call purge_post (simulates save_post hook firing on edit).
// This should sync-purge the permalink cache entry.
$hooks->purge_post( $post_id, $post );

// After purge: V1 should be GONE (synchronous purge).
$after = $store->lookup( $rel_dir );
scheck( $results, 'S1d V1 cache PURGED synchronously after edit', ! $after['found'] || ! $after['fresh'] || $after['body'] === '', "found={$after['found']} fresh={$after['fresh']}" );

// ============================================================
// Test 2: V2 written after purge, fresh cache
// ============================================================
echo "\n--- Test 2: V2 written after sync purge ---\n";

$v2_marker = 'V2_MARKER_' . uniqid();
$v2_html = '<!DOCTYPE html><html><body><h1>' . $v2_marker . '</h1><p>Updated content.</p></body></html>';
$store->write( $rel_dir, $v2_html, 200, array( 'Content-Type' => 'text/html; charset=UTF-8' ), array( 'post:' . $post_id ), 3600 );

$lookup2 = $store->lookup( $rel_dir );
scheck( $results, 'S2a V2 cache written', $lookup2['found'] && $lookup2['fresh'] );
scheck( $results, 'S2b V2 body matches (no V1 leak)', false !== strpos( $lookup2['body'], $v2_marker ) && false === strpos( $lookup2['body'], $v1_marker ) );

// ============================================================
// Test 3: Stale exposure window measurement
// ============================================================
echo "\n--- Test 3: Stale exposure window ---\n";
echo "Simulating: V1 cached → edit → immediate poll\n";

// Re-write V1.
$store->write( $rel_dir, $v1_html, 200, array( 'Content-Type' => 'text/html; charset=UTF-8' ), array( 'post:' . $post_id ), 3600 );

// Measure: timestamp before purge, purge, then immediately check.
$t_before = microtime( true );
$hooks->purge_post( $post_id, $post );
$t_after_purge = microtime( true );
$lookup3 = $store->lookup( $rel_dir );
$t_after_lookup = microtime( true );

$stale_exposure = 0;
if ( $lookup3['found'] && $lookup3['fresh'] && false !== strpos( $lookup3['body'], $v1_marker ) ) {
        $stale_exposure = $t_after_lookup - $t_before;
        scheck( $results, 'S3a stale exposure window', false, "V1 STILL served after purge (stale exposure = {$stale_exposure}s)" );
} else {
        $stale_exposure = $t_after_lookup - $t_before;
        scheck( $results, 'S3a stale exposure ≈ 0 (V1 purged synchronously)', true, "window={$stale_exposure}s" );
}

// ============================================================
// Summary
// ============================================================
echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";

// Cleanup.
$sfs->delete_tree( Installer::cache_root() . '/v' );

exit( $pass_count === $total ? 0 : 1 );
