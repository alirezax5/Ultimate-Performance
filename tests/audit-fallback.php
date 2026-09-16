<?php
/**
 * HARDEN-5 regression test — advanced-cache.php drop-in ownership + install/remove.
 *
 * Verifies:
 *   - Drop-in installs safely (no foreign drop-in overwritten)
 *   - Ownership marker detection works
 *   - Drop-in removes cleanly on deactivation
 *   - Foreign drop-in is NEVER overwritten
 *   - FallbackServer bypasses POST/logged-in/cart correctly
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

use UltimatePerformance\Compatibility\AdvancedCacheDropin;
use UltimatePerformance\Compatibility\FallbackServer;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\CacheKey\Key;

$results = array();
function fcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== HARDEN-5 Advanced-Cache Drop-in Regression Test ===\n";

// Setup.
Installer::ensure_cache_root();
$dropin_path = WP_CONTENT_DIR . '/advanced-cache.php';

// Clean any existing drop-in.
if ( file_exists( $dropin_path ) ) {
        @unlink( $dropin_path );
}

$dropin = new AdvancedCacheDropin();

// ============================================================
// Test 1: Install creates the drop-in
// ============================================================
echo "\n--- Test 1: Install creates the drop-in ---\n";
$installed = $dropin->install();
fcheck( $results, 'F1a install() returns true', $installed );
fcheck( $results, 'F1b drop-in file exists', file_exists( $dropin_path ) );
fcheck( $results, 'F1c drop-in contains ownership marker', false !== strpos( (string) file_get_contents( $dropin_path ), AdvancedCacheDropin::OWNERSHIP_MARKER ) );
fcheck( $results, 'F1d is_owned_by_us() returns true', $dropin->is_owned_by_us() );

// ============================================================
// Test 2: Re-install is idempotent (safe)
// ============================================================
echo "\n--- Test 2: Re-install is idempotent ---\n";
$installed2 = $dropin->install();
fcheck( $results, 'F2a re-install returns true', $installed2 );
fcheck( $results, 'F2b drop-in still exists', file_exists( $dropin_path ) );

// ============================================================
// Test 3: Foreign drop-in is NEVER overwritten
// ============================================================
echo "\n--- Test 3: Foreign drop-in protection ---\n";
// Simulate a foreign drop-in (e.g., W3 Total Cache).
file_put_contents( $dropin_path, "<?php /* W3 Total Cache advanced-cache.php */ echo 'w3tc';" );
fcheck( $results, 'F3a foreign drop-in detected', $dropin->is_foreign() );
fcheck( $results, 'F3b is_owned_by_us() returns false for foreign', ! $dropin->is_owned_by_us() );
$installed3 = $dropin->install();
fcheck( $results, 'F3c install() REFUSED to overwrite foreign drop-in', ! $installed3 );
fcheck( $results, 'F3d foreign drop-in content unchanged', false !== strpos( (string) file_get_contents( $dropin_path ), 'W3 Total Cache' ) );

// ============================================================
// Test 4: Remove only removes OUR drop-in
// ============================================================
echo "\n--- Test 4: Remove respects ownership ---\n";
// Try to remove the foreign drop-in → should fail.
$removed_foreign = $dropin->remove();
fcheck( $results, 'F4a remove() REFUSED to remove foreign drop-in', ! $removed_foreign );
fcheck( $results, 'F4b foreign drop-in still exists', file_exists( $dropin_path ) );

// Now install OUR drop-in and remove it.
@unlink( $dropin_path );
$dropin->install();
fcheck( $results, 'F4c our drop-in installed', $dropin->is_owned_by_us() );
$removed_ours = $dropin->remove();
fcheck( $results, 'F4d remove() succeeded for our drop-in', $removed_ours );
fcheck( $results, 'F4e drop-in file removed', ! file_exists( $dropin_path ) );

// ============================================================
// Test 5: FallbackServer bypasses non-GET methods
// ============================================================
echo "\n--- Test 5: FallbackServer bypass rules ---\n";

// Re-install drop-in for FallbackServer tests.
$dropin->install();

// Write a cache entry.
$settings = Settings::instance();
// Enable page_cache_enabled (default is false, opt-in).
$raw = $settings->raw();
$raw['enabled'] = true;
$raw['page_cache_enabled'] = true;
update_option( Settings::OPTION, $raw, true );
// Reset the Settings singleton so the new option is picked up.
$ref = new \ReflectionProperty( Settings::class, 'instance' );
$ref->setAccessible( true );
$ref->setValue( null, null );
$settings = Settings::instance();

$keygen = new Key( $settings );
$store = new Store( new SafeFs(), $keygen );

$host = 'localhost'; // Must match home_url() in the shim.
$path = '/fallback-page/';
$built = $keygen->build( 'http', $host, $path, '', array() );
$cache_body = '<!DOCTYPE html><html lang="en"><head><title>Test</title></head><body><h1>Fallback test page.</h1><p>This is a cached page for fallback mode testing.</p></body></html>';
$store->write( $built['dir'], $cache_body, 200, array( 'Content-Type' => 'text/html; charset=UTF-8' ), array(), 3600 );

// Test GET HIT.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = $host;
$_SERVER['REQUEST_URI'] = $path;
unset( $_SERVER['HTTPS'] );

ob_start();
FallbackServer::serve();
$output = ob_get_clean();
fcheck( $results, 'F5a GET on cached URL serves cached body', false !== strpos( $output, 'Fallback test page' ) );

// Test POST bypass.
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
FallbackServer::serve();
$output = ob_get_clean();
fcheck( $results, 'F5b POST bypasses fallback (no cached body served)', false === strpos( $output, 'Fallback test page' ) );

// Test logged-in cookie bypass.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_COOKIE['wordpress_logged_in_0123456789abcdef0123456789abcdef'] = 'admin|12345';
ob_start();
FallbackServer::serve();
$output = ob_get_clean();
fcheck( $results, 'F5c logged-in cookie bypasses fallback', false === strpos( $output, 'Fallback test page' ) );
unset( $_COOKIE['wordpress_logged_in_0123456789abcdef0123456789abcdef'] );

// Test cart path bypass.
$_SERVER['REQUEST_URI'] = '/cart/';
ob_start();
FallbackServer::serve();
$output = ob_get_clean();
fcheck( $results, 'F5d /cart/ path bypasses fallback', false === strpos( $output, 'Fallback test page' ) );

// Test checkout path bypass.
$_SERVER['REQUEST_URI'] = '/checkout/';
ob_start();
FallbackServer::serve();
$output = ob_get_clean();
fcheck( $results, 'F5e /checkout/ path bypasses fallback', false === strpos( $output, 'Fallback test page' ) );

// ============================================================
// Cleanup
// ============================================================
$dropin->remove();
$sfs = new SafeFs();
$sfs->delete_tree( Installer::cache_root() . '/v' );
unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['HTTPS'] );

// ============================================================
// Summary
// ============================================================
echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
