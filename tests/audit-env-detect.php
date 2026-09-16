<?php
/**
 * HARDEN-6 regression test — environment detection + self-test.
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

use UltimatePerformance\Core\EnvironmentDetector;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\Compatibility\AdvancedCacheDropin;

$results = array();
function echeck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== HARDEN-6 Environment Detection + Self-Test ===\n";

// Setup.
Installer::ensure_cache_root();

// ============================================================
// Test 1: Server detection
// ============================================================
echo "\n--- Test 1: Server detection ---\n";

$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.28.3';
echeck( $results, 'E1a nginx detected', 'nginx' === EnvironmentDetector::detect_server() );

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Debian)';
echeck( $results, 'E1b apache detected', 'apache' === EnvironmentDetector::detect_server() );

$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed/6.3';
echeck( $results, 'E1c litespeed detected', 'litespeed' === EnvironmentDetector::detect_server() );

// J3: OpenLiteSpeed must be detected as 'ols', NOT 'litespeed'
$_SERVER['SERVER_SOFTWARE'] = 'OpenLiteSpeed/1.7.19';
echeck( $results, 'E1d OLS detected as ols (not swallowed by litespeed)', 'ols' === EnvironmentDetector::detect_server() );

unset( $_SERVER['SERVER_SOFTWARE'] );
echeck( $results, 'E1e unknown when no SERVER_SOFTWARE', 'unknown' === EnvironmentDetector::detect_server() );

// ============================================================
// Test 2: Mode detection — disabled when page_cache_enabled=false
// ============================================================
echo "\n--- Test 2: Mode detection ---\n";

$settings = Settings::instance();
$raw = $settings->raw();
$raw['enabled'] = false;
update_option( Settings::OPTION, $raw, true );
$ref = new \ReflectionProperty( Settings::class, 'instance' );
$ref->setAccessible( true );
$ref->setValue( null, null );

$detected = EnvironmentDetector::detect_mode();
echeck( $results, 'E2a disabled when plugin disabled', EnvironmentDetector::MODE_DISABLED === $detected['mode'] );

// Enable plugin, disable page cache.
$settings = Settings::instance();
$raw = $settings->raw();
$raw['enabled'] = true;
$raw['page_cache_enabled'] = false;
update_option( Settings::OPTION, $raw, true );
$ref->setValue( null, null );
$detected = EnvironmentDetector::detect_mode();
echeck( $results, 'E2b disabled when page_cache disabled', EnvironmentDetector::MODE_DISABLED === $detected['mode'] );

// Enable page cache.
$settings = Settings::instance();
$raw = $settings->raw();
$raw['enabled'] = true;
$raw['page_cache_enabled'] = true;
update_option( Settings::OPTION, $raw, true );
$ref->setValue( null, null );

$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.28.3';
$detected = EnvironmentDetector::detect_mode();
echeck( $results, 'E2c nginx without dropin: MISCONFIGURED (not SERVER_ACCELERATED)', EnvironmentDetector::MODE_MISCONFIGURED === $detected['mode'] );

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$detected = EnvironmentDetector::detect_mode();
echeck( $results, 'E2d apache without dropin: MISCONFIGURED (not SERVER_ACCELERATED)', EnvironmentDetector::MODE_MISCONFIGURED === $detected['mode'] );

// ============================================================
// Test 3: PHP fallback mode detection
// ============================================================
echo "\n--- Test 3: PHP fallback mode ---\n";

// Install our drop-in.
$dropin = new AdvancedCacheDropin();
$dropin->install();

// Simulate WP_CACHE=true (normally set in wp-config.php).
if ( ! defined( 'WP_CACHE' ) ) {
        define( 'WP_CACHE', true );
}

$detected = EnvironmentDetector::detect_mode();
echeck( $results, 'E3a PHP fallback mode detected with our dropin', EnvironmentDetector::MODE_PHP_FALLBACK === $detected['mode'] );
echeck( $results, 'E3b details show our_dropin=true', ! empty( $detected['details']['our_dropin'] ) );

// ============================================================
// Test 4: Foreign drop-in conflict
// ============================================================
echo "\n--- Test 4: Foreign drop-in conflict ---\n";

$dropin->remove();
file_put_contents( WP_CONTENT_DIR . '/advanced-cache.php', '<?php /* W3 Total Cache */ ?>' );
$detected = EnvironmentDetector::detect_mode();
echeck( $results, 'E4a conflicted mode on foreign dropin', EnvironmentDetector::MODE_CONFLICTED === $detected['mode'] );

@unlink( WP_CONTENT_DIR . '/advanced-cache.php' );

// ============================================================
// Test 5: Self-test probe
// ============================================================
echo "\n--- Test 5: Self-test probe ---\n";

$probe = EnvironmentDetector::run_self_test();
echeck( $results, 'E5a self-test writes cache file', $probe['cache_writable'] );
echeck( $results, 'E5b probe written', $probe['probe_written'] );
echeck( $results, 'E5c probe readable', $probe['probe_readable'] );
echeck( $results, 'E5d probe token returned', ! empty( $probe['probe_token'] ) && strlen( $probe['probe_token'] ) === 16 );

// ============================================================
// Test 6: Status summary for admin display
// ============================================================
echo "\n--- Test 6: Status summary ---\n";

$dropin->install();
$summary = EnvironmentDetector::get_status_summary();
echeck( $results, 'E6a mode_label is non-empty', ! empty( $summary['mode_label'] ) );
echeck( $results, 'E6b mode_color is non-empty', ! empty( $summary['mode_color'] ) );
echeck( $results, 'E6c server is set', ! empty( $summary['server'] ) );

// Cleanup.
$dropin->remove();

// ============================================================
// Summary
// ============================================================
echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
