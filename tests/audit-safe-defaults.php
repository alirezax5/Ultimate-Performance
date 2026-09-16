<?php
/**
 * Regression test for safe defaults (§23).
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

$results = array();
function dcheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== Safe Defaults Regression ===\n";

$d = Settings::defaults();

// §2.2: Fresh install must have page cache ON
dcheck( $results, 'D1 page_cache_enabled = true', true === $d['page_cache_enabled'] );
dcheck( $results, 'D2 enabled = true', true === $d['enabled'] );
dcheck( $results, 'D3 php_fallback_enabled = true', true === $d['php_fallback_enabled'] );

// §2: Object cache OFF by default
dcheck( $results, 'D4 object_cache_enabled = false', false === $d['object_cache_enabled'] );

// §2.1: RabbitMQ not active by default — queue_backend must NOT be 'auto' or 'rabbitmq'
dcheck( $results, 'D5 queue_backend != auto', 'auto' !== $d['queue_backend'] );
dcheck( $results, 'D6 queue_backend != rabbitmq', 'rabbitmq' !== $d['queue_backend'] );
dcheck( $results, 'D7 queue_backend = wp-cron', 'wp-cron' === $d['queue_backend'] );

// §2.2: Invalidation and herd protection ON
dcheck( $results, 'D8 invalidation_enabled = true', true === $d['invalidation_enabled'] );
dcheck( $results, 'D9 herd_protection = true', true === $d['herd_protection'] );

// §1.5/§2: Nginx fields empty by default
dcheck( $results, 'D10 nginx.origin = empty', '' === $d['nginx']['origin'] );
dcheck( $results, 'D11 nginx.listen = empty', '' === $d['nginx']['listen'] );

// §2.2: TTL has sensible default
dcheck( $results, 'D12 ttl = 3600', 3600 === $d['ttl'] );

// §2.2: SWR enabled by default
dcheck( $results, 'D13 swr_enabled = true', true === $d['swr_enabled'] );

// §2.1: AMQP defaults present but NOT active (settings exist but queue_backend is wp-cron)
dcheck( $results, 'D14 amqp.host exists but not active', isset( $d['amqp']['host'] ) && 'wp-cron' === $d['queue_backend'] );

// §2.2: Redis defaults present but object_cache_enabled = false (not used)
dcheck( $results, 'D15 redis defaults exist but object cache off', isset( $d['redis']['host'] ) && false === $d['object_cache_enabled'] );

echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
