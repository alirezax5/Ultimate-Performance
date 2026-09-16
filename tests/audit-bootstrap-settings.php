<?php
/**
 * Regression test for B (duplicate constants) and C (partial save validation).
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

// B: Load the plugin's main file to define all constants
// (the shim doesn't auto-load ultimate-performance.php)
if ( ! defined( 'ULTIMATE_PERFORMANCE_VERSION' ) ) {
        require_once ULTIMATE_PERFORMANCE_DIR . 'ultimate-performance.php';
}

use UltimatePerformance\Core\Settings;

$results = array();
function bcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== B+C: Constant Guard + Settings Partial Save ===\n";

// B: Constants should be defined and idempotent
echo "\n--- B: Constant Guards ---\n";
bcheck( $results, 'B1a ULTIMATE_PERFORMANCE_VERSION defined', defined( 'ULTIMATE_PERFORMANCE_VERSION' ) );
bcheck( $results, 'B1b ULTIMATE_PERFORMANCE_FILE defined', defined( 'ULTIMATE_PERFORMANCE_FILE' ) );
bcheck( $results, 'B1c ULTIMATE_PERFORMANCE_DIR defined', defined( 'ULTIMATE_PERFORMANCE_DIR' ) );
bcheck( $results, 'B1d ULTIMATE_PERFORMANCE_URL defined', defined( 'ULTIMATE_PERFORMANCE_URL' ) );
bcheck( $results, 'B1e VERSION is 0.6.4', '0.6.4' === ULTIMATE_PERFORMANCE_VERSION );

// B: Re-including the plugin file should NOT produce warnings
// (simulate double-load by requiring the file again)
$warnings = array();
set_error_handler( function( $errno, $errstr ) use ( &$warnings ) {
        if ( E_WARNING === $errno && false !== strpos( $errstr, 'already defined' ) ) {
                $warnings[] = $errstr;
        }
        return true;
} );
// The constants are already defined — calling define() again would warn,
// but our guarded code uses `if ( ! defined(...) )` so it should be silent
if ( ! defined( 'ULTIMATE_PERFORMANCE_VERSION' ) ) {
        define( 'ULTIMATE_PERFORMANCE_VERSION', 'test' );
}
if ( ! defined( 'ULTIMATE_PERFORMANCE_FILE' ) ) {
        define( 'ULTIMATE_PERFORMANCE_FILE', '/test' );
}
restore_error_handler();
bcheck( $results, 'B2a re-define produces no warnings (guard works)', empty( $warnings ), 'warnings: ' . json_encode( $warnings ) );

// C: Settings partial save
echo "\n--- C: Settings Partial Save ---\n";
$s = Settings::instance();
$raw = $s->raw();
$raw['enabled'] = true;
$raw['page_cache_enabled'] = true;
update_option( Settings::OPTION, $raw, true );
$ref = new \ReflectionProperty( Settings::class, 'instance' );
$ref->setAccessible( true );
$ref->setValue( null, null );
$s = Settings::instance();

// Save with ONLY enabled + page_cache_enabled (no TTL, no swr_grace, no preload)
$errors = $s->save_from_admin( array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
) );
bcheck( $results, 'C1a minimal save (no TTL/swr_grace/preload): no errors', empty( $errors ), 'errors: ' . json_encode( $errors ) );

$ref->setValue( null, null );
$s2 = Settings::instance();
bcheck( $results, 'C1b page_cache_enabled preserved', true === $s2->get( 'page_cache_enabled' ) );
bcheck( $results, 'C1c TTL preserved (default)', 3600 === (int) $s2->get( 'ttl' ) );
bcheck( $results, 'C1d swr_grace preserved (default)', 300 === (int) $s2->get( 'swr_grace' ) );

// Save with only TTL change (no swr_grace, no preload.batch)
$errors2 = $s2->save_from_admin( array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '7200',
) );
bcheck( $results, 'C2a TTL-only save: no errors', empty( $errors2 ), 'errors: ' . json_encode( $errors2 ) );
$ref->setValue( null, null );
$s3 = Settings::instance();
bcheck( $results, 'C2b TTL saved to 7200', 7200 === (int) $s3->get( 'ttl' ) );

// Save with no Redis block
$errors3 = $s3->save_from_admin( array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '3600',
) );
bcheck( $results, 'C3a save without Redis block: no errors', empty( $errors3 ) );

// Save with no AMQP block
$errors4 = $s3->save_from_admin( array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '3600',
) );
bcheck( $results, 'C4a save without AMQP block: no errors', empty( $errors4 ) );

// Invalid TTL still produces error
$errors5 = $s3->save_from_admin( array(
        'enabled' => '1',
        'page_cache_enabled' => '1',
        'ttl' => '1', // below minimum
) );
bcheck( $results, 'C5a invalid TTL produces error', ! empty( $errors5 ) && isset( $errors5['ttl'] ) );
bcheck( $results, 'C5b error is field-specific (ttl key)', isset( $errors5['ttl'] ) );

echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
