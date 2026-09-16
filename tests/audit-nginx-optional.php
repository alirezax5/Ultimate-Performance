<?php
/**
 * UX regression test — Nginx settings must be optional (empty = valid).
 *
 * Verifies that saving settings with empty Nginx origin/listen fields
 * succeeds, and that EnvironmentDetector does not confuse Nginx detection
 * with working server acceleration.
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

use UltimatePerformance\Core\Settings;
use UltimatePerformance\Core\EnvironmentDetector;

$results = array();
function ucheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== UX Fix: Nginx Settings Optional ===\n";

// Reset settings
$s = Settings::instance();
$defaults = $s->defaults();
$defaults['enabled'] = true;
$defaults['page_cache_enabled'] = true;
update_option( Settings::OPTION, $defaults, true );
$ref = new \ReflectionProperty( Settings::class, 'instance' );
$ref->setAccessible( true );
$ref->setValue( null, null );
$s = Settings::instance();

echo "\n--- Test 1: Save with empty Nginx fields ---\n";
$input = array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '3600',
        'nginx' => array(
                'origin' => '',
                'listen' => '',
        ),
);
// C1: swr_grace and preload.batch are intentionally NOT submitted.
// The validator must NOT error on missing fields (partial save).
$errors = $s->save_from_admin( $input );
ucheck( $results, 'U1a empty nginx fields: save succeeds (no errors)', empty( $errors ), 'errors=' . json_encode( $errors ) );

$s2 = Settings::instance();
ucheck( $results, 'U1b nginx.origin is empty after save', '' === $s2->get( 'nginx.origin' ) );
ucheck( $results, 'U1c nginx.listen is empty after save', '' === $s2->get( 'nginx.listen' ) );
ucheck( $results, 'U1d page_cache_enabled still true', true === $s2->get( 'page_cache_enabled' ) );

echo "\n--- Test 2: Unrelated settings change with empty Nginx ---\n";
$input2 = array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '7200',
        'nginx' => array( 'origin' => '', 'listen' => '' ),
);
$errors2 = $s2->save_from_admin( $input2 );
ucheck( $results, 'U2a TTL change with empty nginx: no errors', empty( $errors2 ) );
$s3 = Settings::instance();
ucheck( $results, 'U2b TTL saved correctly', 7200 === (int) $s3->get( 'ttl' ) );

echo "\n--- Test 3: Valid Nginx fields also save ---\n";
$input3 = array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '3600',
        'nginx' => array( 'origin' => '127.0.0.1:8080', 'listen' => '127.0.0.1:80' ),
);
$errors3 = $s3->save_from_admin( $input3 );
ucheck( $results, 'U3a valid nginx fields: no errors', empty( $errors3 ) );
$s4 = Settings::instance();
ucheck( $results, 'U3b nginx.origin saved', '127.0.0.1:8080' === $s4->get( 'nginx.origin' ) );

echo "\n--- Test 4: Invalid TTL still fails (validation not weakened) ---\n";
$input4 = array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '5', // too low
        'nginx' => array( 'origin' => '', 'listen' => '' ),
);
$errors4 = $s4->save_from_admin( $input4 );
ucheck( $results, 'U4a invalid TTL still produces error', ! empty( $errors4 ) && isset( $errors4['ttl'] ) );

echo "\n--- Test 5: EnvironmentDetector on Nginx without drop-in ---\n";
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.28.3';
$ref->setValue( null, null );
$s5 = Settings::instance();
$d5 = $s5->raw();
$d5['enabled'] = true;
$d5['page_cache_enabled'] = true;
update_option( Settings::OPTION, $d5, true );
$ref->setValue( null, null );

$detected = EnvironmentDetector::detect_mode();
ucheck( $results, 'U5a Nginx detected without drop-in: NOT SERVER_ACCELERATED', EnvironmentDetector::MODE_SERVER_ACCELERATED !== $detected['mode'], 'mode=' . $detected['mode'] );
ucheck( $results, 'U5b Nginx detected without drop-in: MISCONFIGURED', EnvironmentDetector::MODE_MISCONFIGURED === $detected['mode'], 'mode=' . $detected['mode'] );
ucheck( $results, 'U5c server detected as nginx', 'nginx' === $detected['server'] );

echo "\n--- Test 6: EnvironmentDetector with drop-in = PHP_FALLBACK ---\n";
// Simulate our drop-in being present
$ac_path = WP_CONTENT_DIR . '/advanced-cache.php';
@file_put_contents( $ac_path, '<?php /* Ultimate Performance advanced-cache.php v1 */ ?>' );
if ( ! defined( 'WP_CACHE' ) ) {
        define( 'WP_CACHE', true );
}
$ref->setValue( null, null );
$detected2 = EnvironmentDetector::detect_mode();
ucheck( $results, 'U6a with drop-in: PHP_FALLBACK', EnvironmentDetector::MODE_PHP_FALLBACK === $detected2['mode'], 'mode=' . $detected2['mode'] );
@unlink( $ac_path );

echo "\n--- Test 7: PHP fallback available after saving empty Nginx ---\n";
// Reset and save with empty fields
$ref->setValue( null, null );
$s7 = Settings::instance();
$s7->save_from_admin( array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '3600',
        'nginx' => array( 'origin' => '', 'listen' => '' ),
) );
$ref->setValue( null, null );
$s8 = Settings::instance();
ucheck( $results, 'U7a page_cache_enabled after empty nginx save', true === $s8->get( 'page_cache_enabled' ) );
ucheck( $results, 'U7b php_fallback_enabled after empty nginx save', true === $s8->get( 'php_fallback_enabled' ) );
ucheck( $results, 'U7c nginx.origin still empty', '' === $s8->get( 'nginx.origin' ) );

// Cleanup
unset( $_SERVER['SERVER_SOFTWARE'] );

echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
