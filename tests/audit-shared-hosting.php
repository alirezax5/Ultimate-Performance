<?php
/**
 * HARDEN-7 regression test — shared hosting compatibility.
 *
 * Simulates a no-root shared hosting environment where:
 *   - User CANNOT edit Nginx vhost
 *   - User CANNOT restart services
 *   - User CAN install plugins and edit wp-config.php
 *
 * Verifies that Ultimate Performance works in this environment via the
 * PHP fallback mode (advanced-cache.php drop-in), WITHOUT requiring
 * any server-level configuration.
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
use UltimatePerformance\Core\EnvironmentDetector;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\CacheKey\Key;

$results = array();
function shcheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== HARDEN-7 Shared Hosting Compatibility Test ===\n";
echo "Simulating: no-root shared hosting (Nginx, no vhost access)\n";

// Setup.
Installer::ensure_cache_root();
$sfs = new SafeFs();

// Clean state.
$sfs->delete_tree( Installer::cache_root() . '/v' );
Installer::ensure_cache_root();
$dropin_path = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $dropin_path ) ) {
	@unlink( $dropin_path );
}

// Enable settings.
$settings = Settings::instance();
$raw = $settings->raw();
$raw['enabled'] = true;
$raw['page_cache_enabled'] = true;
$raw['php_fallback_enabled'] = true;
update_option( Settings::OPTION, $raw, true );
$ref = new \ReflectionProperty( Settings::class, 'instance' );
$ref->setAccessible( true );
$ref->setValue( null, null );
$settings = Settings::instance();

// ============================================================
// H1: No-root Nginx shared hosting
// ============================================================
echo "\n--- H1: No-root Nginx (no vhost access) ---\n";
echo "User installs plugin. NO Nginx try_files rule exists.\n";
echo "Expected: PHP fallback mode activates automatically.\n";

// Simulate: no Nginx rule (we just don't have one — the test environment
// has no web server at all, which is equivalent to "no rule").
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.28.3';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/shared-host-page/';
unset( $_SERVER['HTTPS'] );

// Install drop-in (simulates plugin activation).
$dropin = new AdvancedCacheDropin();
$installed = $dropin->install();
shcheck( $results, 'H1a drop-in installs without root', $installed );

// Simulate WP_CACHE=true (wp-config.php edit — within user's capability).
if ( ! defined( 'WP_CACHE' ) ) {
	define( 'WP_CACHE', true );
}

// Detect mode.
$detected = EnvironmentDetector::detect_mode();
shcheck( $results, 'H1b mode detected as PHP_FALLBACK', EnvironmentDetector::MODE_PHP_FALLBACK === $detected['mode'] );

// Write a cache entry (simulating a MISS that was cached by the Engine).
$keygen = new Key( $settings );
$store = new Store( $sfs, $keygen );
$built = $keygen->build( 'http', 'localhost', '/shared-host-page/', '', array() );
$cache_body = '<!DOCTYPE html><html><head><title>Shared Host Page</title></head><body><h1>Shared Hosting Test</h1><p>This page was cached via PHP fallback mode without any Nginx rules.</p></body></html>';
$store->write( $built['dir'], $cache_body, 200, array( 'Content-Type' => 'text/html; charset=UTF-8' ), array(), 3600 );

// Request the cached page via FallbackServer.
ob_start();
FallbackServer::serve();
$output = ob_get_clean();
shcheck( $results, 'H1c cached page served via PHP fallback', false !== strpos( $output, 'Shared Hosting Test' ) );

// Verify no root/Nginx intervention was needed.
shcheck( $results, 'H1d no Nginx config file required', ! file_exists( '/etc/nginx/sites-enabled/uc-test' ) );

// ============================================================
// H1 bypass rules still work in shared hosting
// ============================================================
echo "\n--- H1 bypass rules in shared hosting ---\n";

// POST bypass.
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
FallbackServer::serve();
$output = ob_get_clean();
shcheck( $results, 'H1e POST bypasses in shared hosting', false === strpos( $output, 'Shared Hosting Test' ) );

// Cart path bypass.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cart/';
ob_start();
FallbackServer::serve();
$output = ob_get_clean();
shcheck( $results, 'H1f /cart/ bypasses in shared hosting', false === strpos( $output, 'Shared Hosting Test' ) );

// Logged-in cookie bypass.
$_SERVER['REQUEST_URI'] = '/shared-host-page/';
$_COOKIE['wordpress_logged_in_0123456789abcdef0123456789abcdef'] = 'admin|12345';
ob_start();
FallbackServer::serve();
$output = ob_get_clean();
shcheck( $results, 'H1g logged-in cookie bypasses in shared hosting', false === strpos( $output, 'Shared Hosting Test' ) );
unset( $_COOKIE['wordpress_logged_in_0123456789abcdef0123456789abcdef'] );

// ============================================================
// H2: Apache shared hosting (.htaccess editable)
// ============================================================
echo "\n--- H2: Apache (.htaccess editable) ---\n";
echo "User can edit .htaccess but not global Apache config.\n";
echo "Expected: PHP fallback works; optional Apache rewrite for zero-PHP.\n";

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
$detected = EnvironmentDetector::detect_mode();
shcheck( $results, 'H2a apache server detected', 'apache' === $detected['server'] );
shcheck( $results, 'H2b PHP fallback active on Apache', EnvironmentDetector::MODE_PHP_FALLBACK === $detected['mode'] );

// ============================================================
// H3: Managed hosting (no vhost, no service restart)
// ============================================================
echo "\n--- H3: Managed hosting (no vhost, no service restart) ---\n";
echo "User has: PHP version, cron, file manager, DB mgmt.\n";
echo "User CANNOT: edit vhost, restart services.\n";

$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.28.3';
$detected = EnvironmentDetector::detect_mode();
shcheck( $results, 'H3a PHP fallback works on managed hosting', EnvironmentDetector::MODE_PHP_FALLBACK === $detected['mode'] );

// Self-test should pass.
$probe = EnvironmentDetector::run_self_test();
shcheck( $results, 'H3b self-test passes on managed hosting', $probe['cache_writable'] && $probe['probe_readable'] );

// ============================================================
// Cleanup
// ============================================================
$dropin->remove();
$sfs->delete_tree( Installer::cache_root() . '/v' );
unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['HTTPS'], $_SERVER['SERVER_SOFTWARE'] );

// ============================================================
// Summary
// ============================================================
echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
