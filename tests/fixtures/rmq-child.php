<?php
/**
 * Child process for audit-rmq-connection scenarios.
 * Boots a minimal WP shim, applies runtime AMQP config, reports OK/ERR.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', dirname( __DIR__ ) . '/wp-shim/' ); // portable WP shim
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

$scenario = json_decode( (string) base64_decode( $argv[1] ?? '' ), true );
$expect   = ( '1' === ( $argv[2] ?? '0' ) );

require_once __DIR__ . '/../../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require ABSPATH . 'wp-load.php';

if ( empty( $scenario['no_vendor'] ) ) {
	$va = __DIR__ . '/../../vendor/autoload.php';
	if ( file_exists( $va ) ) {
		require_once $va;
	}
}

use UltimatePerformance\Queue\BackendImpl\RabbitMQ;

// Runtime-only credential injection — never persisted, never hardcoded.
add_filter(
	'ultimate_cache_amqp_config',
	static function () use ( $scenario ) {
		return array(
			'host'    => (string) ( $scenario['host'] ?? '127.0.0.1' ),
			'port'    => (int) ( $scenario['port'] ?? 1 ),
			'user'    => (string) ( $scenario['user'] ?? 'guest' ),
			'pass'    => (string) ( $scenario['pass'] ?? '' ),
			'vhost'   => (string) ( $scenario['vhost'] ?? '/' ),
			'timeout' => 3.0,
		);
	},
	11
);

try {
	$b      = new RabbitMQ();
	$avail  = $b->available();
	echo ( $avail === $expect ) ? 'OK' : 'MISMATCH avail=' . var_export( $avail, true );
} catch ( \Throwable $e ) {
	echo 'THROW ' . get_class( $e );
}
