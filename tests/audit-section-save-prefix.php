<?php
/**
 * §2-3: Section-aware settings save + Object Cache prefix tests.
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
use UltimatePerformance\ObjectCache\Manager;

$results = array();
function scheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== Section-Aware Save + Object Cache Prefix ===\n";

// Helper: reset settings
function reset_settings() {
	$s = Settings::instance();
	$d = Settings::defaults();
	update_option( Settings::OPTION, $d, true );
	$ref = new \ReflectionProperty( Settings::class, 'instance' );
	$ref->setAccessible( true );
	$ref->setValue( null, null );
}

// ===== §2: Section-aware partial save =====

echo "\n--- Test A: Object Cache save preserves page cache ---\n";
reset_settings();
$s = Settings::instance();
// Verify initial state
scheck( $results, 'A1 initial page_cache=true', true === $s->get( 'page_cache_enabled' ) );
scheck( $results, 'A2 initial enabled=true', true === $s->get( 'enabled' ) );
scheck( $results, 'A3 initial object_cache=false', false === $s->get( 'object_cache_enabled' ) );

// Save Object Cache section only
$s->save_from_admin( array(
	'up_section' => 'object-cache',
	'object_cache_enabled' => '1',
) );
$ref = new \ReflectionProperty( Settings::class, 'instance' );
$ref->setAccessible( true );
$ref->setValue( null, null );
$s2 = Settings::instance();

scheck( $results, 'A4 after OC save: enabled still true', true === $s2->get( 'enabled' ), 'enabled=' . var_export( $s2->get( 'enabled' ), true ) );
scheck( $results, 'A5 after OC save: page_cache still true', true === $s2->get( 'page_cache_enabled' ), 'page_cache=' . var_export( $s2->get( 'page_cache_enabled' ), true ) );
scheck( $results, 'A6 after OC save: object_cache now true', true === $s2->get( 'object_cache_enabled' ) );

// ===== Test B: Saving Page Cache preserves queue_enabled =====
echo "\n--- Test B: Page Cache save preserves queue_enabled ---\n";
reset_settings();
$s = Settings::instance();
// Manually set queue_enabled=false
$d = $s->raw();
$d['queue_enabled'] = false;
update_option( Settings::OPTION, $d, true );
$ref->setValue( null, null );

$s = Settings::instance();
scheck( $results, 'B1 initial queue_enabled=false', false === $s->get( 'queue_enabled' ) );

// Save Page Cache section
$s->save_from_admin( array(
	'up_section' => 'page-cache',
	'enabled' => '1',
	'page_cache_enabled' => '1',
	'ttl' => '3600',
) );
$ref->setValue( null, null );
$s2 = Settings::instance();
scheck( $results, 'B2 after PC save: queue_enabled still false', false === $s2->get( 'queue_enabled' ), 'queue=' . var_export( $s2->get( 'queue_enabled' ), true ) );

// ===== Test C: Saving Redis settings preserves page_cache_enabled =====
echo "\n--- Test C: Redis save preserves page_cache_enabled ---\n";
reset_settings();
$s = Settings::instance();
scheck( $results, 'C1 initial page_cache=true', true === $s->get( 'page_cache_enabled' ) );

// Save Object Cache section with Redis config
$s->save_from_admin( array(
	'up_section' => 'object-cache',
	'redis' => array( 'host' => '10.0.0.1', 'port' => '6390' ),
) );
$ref->setValue( null, null );
$s2 = Settings::instance();
scheck( $results, 'C2 after Redis save: page_cache still true', true === $s2->get( 'page_cache_enabled' ) );
scheck( $results, 'C3 redis.host saved', '10.0.0.1' === $s2->get( 'redis.host' ) );

// ===== Test D: Saving Queue preserves object_cache_enabled =====
echo "\n--- Test D: Queue save preserves object_cache_enabled ---\n";
reset_settings();
$s = Settings::instance();
$d = $s->raw();
$d['object_cache_enabled'] = true;
update_option( Settings::OPTION, $d, true );
$ref->setValue( null, null );
$s = Settings::instance();
scheck( $results, 'D1 initial object_cache=true', true === $s->get( 'object_cache_enabled' ) );

$s->save_from_admin( array(
	'up_section' => 'queue',
	'queue_enabled' => '1',
	'queue_backend' => 'wp-cron',
) );
$ref->setValue( null, null );
$s2 = Settings::instance();
scheck( $results, 'D2 after Queue save: object_cache still true', true === $s2->get( 'object_cache_enabled' ) );

// ===== Test E: Unchecked checkbox in submitted section = false =====
echo "\n--- Test E: Unchecked checkbox in submitted section ---\n";
reset_settings();
$s = Settings::instance();
$d = $s->raw();
$d['object_cache_enabled'] = true;
update_option( Settings::OPTION, $d, true );
$ref->setValue( null, null );
$s = Settings::instance();
scheck( $results, 'E1 initial object_cache=true', true === $s->get( 'object_cache_enabled' ) );

// Submit Object Cache section WITHOUT object_cache_enabled checkbox
$s->save_from_admin( array(
	'up_section' => 'object-cache',
) );
$ref->setValue( null, null );
$s2 = Settings::instance();
scheck( $results, 'E2 unchecked in submitted section: object_cache=false', false === $s2->get( 'object_cache_enabled' ) );

// ===== Test F: Unchecked checkbox outside submitted section = preserved =====
echo "\n--- Test F: Unchecked checkbox outside submitted section ---\n";
reset_settings();
$s = Settings::instance();
scheck( $results, 'F1 initial page_cache=true', true === $s->get( 'page_cache_enabled' ) );

// Submit Object Cache section (page_cache_enabled absent)
$s->save_from_admin( array(
	'up_section' => 'object-cache',
) );
$ref->setValue( null, null );
$s2 = Settings::instance();
scheck( $results, 'F2 page_cache preserved (not in submitted section)', true === $s2->get( 'page_cache_enabled' ) );

// ===== §1: Object Cache Prefix tests =====
echo "\n--- Object Cache Prefix Generation ---\n";

scheck( $results, 'P1 woolena.ir → woolena', 'woolena' === Manager::generate_prefix_from_host( 'woolena.ir' ) );
scheck( $results, 'P2 www.example.com → example', 'example' === Manager::generate_prefix_from_host( 'www.example.com' ) );
scheck( $results, 'P3 shop.example.com → shop-example', 'shop-example' === Manager::generate_prefix_from_host( 'shop.example.com' ) );
scheck( $results, 'P4 SHOP.Example.COM:443 → shop-example', 'shop-example' === Manager::generate_prefix_from_host( 'SHOP.Example.COM:443' ) );
scheck( $results, 'P5 www.shop.example.com:443 → shop-example', 'shop-example' === Manager::generate_prefix_from_host( 'www.shop.example.com:443' ) );
scheck( $results, 'P6 example.co.uk → example', 'example' === Manager::generate_prefix_from_host( 'example.co.uk' ) );
scheck( $results, 'P7 shop.example.co.uk → shop-example', 'shop-example' === Manager::generate_prefix_from_host( 'shop.example.co.uk' ) );
scheck( $results, 'P8 localhost → localhost', 'localhost' === Manager::generate_prefix_from_host( 'localhost' ) );
scheck( $results, 'P9 empty → uc-default', 'uc-default' === Manager::generate_prefix_from_host( '' ) );

// ===== §1.7: Prefix validation =====
echo "\n--- Prefix Validation ---\n";
reset_settings();
$s = Settings::instance();
// Valid custom prefix
$e = $s->save_from_admin( array( 'up_section' => 'object-cache', 'object_cache_prefix' => 'my-site-cache' ) );
scheck( $results, 'V1 valid prefix my-site-cache: no errors', empty( $e ), json_encode( $e ) );
$ref->setValue( null, null );
scheck( $results, 'V2 prefix saved correctly', 'my-site-cache' === Settings::instance()->get( 'object_cache_prefix' ) );

// Invalid prefix (spaces)
reset_settings();
$s = Settings::instance();
$e = $s->save_from_admin( array( 'up_section' => 'object-cache', 'object_cache_prefix' => 'has spaces' ) );
scheck( $results, 'V3 invalid prefix (spaces): error', ! empty( $e ) && isset( $e['object_cache_prefix'] ) );

// Empty prefix = auto
reset_settings();
$s = Settings::instance();
$e = $s->save_from_admin( array( 'up_section' => 'object-cache', 'object_cache_prefix' => '' ) );
scheck( $results, 'V4 empty prefix: no error', empty( $e ) );
$ref->setValue( null, null );
scheck( $results, 'V5 empty prefix stored as empty', '' === Settings::instance()->get( 'object_cache_prefix' ) );

echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
