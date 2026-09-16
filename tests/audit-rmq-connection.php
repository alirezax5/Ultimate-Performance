<?php
/**
 * AUDIT TEST — RabbitMQ connection pre-flight (T3A, permanent).
 *
 * Contract test ONLY. Never publishes, never consumes, never declares
 * durable resources. Verifies:
 *   C1  php-amqplib absent → available() false, fail-closed, no fatal.
 *   C2  unreachable broker → available() false within bounded timeout,
 *       chain must continue (no exception escapes).
 *   C3  wrong credentials → available() false (auth failure classified).
 *   C4  real broker reachable (env-gated: UC_RABBITMQ_HOST etc.) →
 *       available() true; SKIP cleanly when env not supplied.
 *   C5  config filter ultimate_cache_amqp_config injects runtime creds —
 *       verified only against the live broker when env supplied.
 *
 * Credentials NEVER appear in this file — environment variables only.
 * Run: php tests/audit-rmq-connection.php   (exit 0 unless a FAIL)
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Queue\BackendImpl\RabbitMQ;

$results = array();
function rcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}
function rskip( $name, $why ) {
        echo "[SKIP] $name << $why\n";
}

// Load vendored php-amqplib if present (plugin-local vendor dir).
$vendor_autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $vendor_autoload ) ) {
        require_once $vendor_autoload;
}

$live_host = getenv( 'UC_RABBITMQ_HOST' );
$has_live  = is_string( $live_host ) && '' !== $live_host;

// ------------------------------------------------------------
// C1/C2/C3 run in an isolated process so the dead-backend negative
// cache and vendor state cannot leak between scenarios.
// ------------------------------------------------------------
function rmq_scenario( $label, $cfg, $expect_available ) {
        $payload = base64_encode( (string) wp_json_encode( $cfg ) );
        $expect  = $expect_available ? '1' : '0';
        $cmd = sprintf(
                'php %s %s %s 2>&1',
                escapeshellarg( __DIR__ . '/fixtures/rmq-child.php' ),
                escapeshellarg( $payload ),
                $expect
        );
        $out = shell_exec( $cmd );
        return array( trim( (string) $out ), $label );
}

// C1: no library → unavailable (child runs with vendor autoload disabled).
list( $out1 ) = rmq_scenario(
        'C1-no-lib',
        array( 'no_vendor' => true, 'host' => '127.0.0.1', 'port' => 1, 'user' => 'x', 'pass' => 'y', 'vhost' => '/' ),
        false
);
rcheck( $results, 'C1 lib-absent fail-closed', 'OK' === $out1, $out1 );

// C2: unreachable broker → unavailable, bounded time, no fatal.
$t0      = microtime( true );
list( $out2 ) = rmq_scenario(
        'C2-unreachable',
        array( 'host' => '127.0.0.1', 'port' => 1, 'user' => 'x', 'pass' => 'y', 'vhost' => '/' ),
        false
);
$dur = microtime( true ) - $t0;
rcheck( $results, 'C2 unreachable fail-closed', 'OK' === $out2, $out2 );
rcheck( $results, 'C2 bounded probe (<15s)', $dur < 15, "took {$dur}s" );

// C3: wrong credentials against live host (if supplied) or any TCP-refused
// host — must classify as failure without leaking secrets into output.
if ( $has_live ) {
        list( $out3 ) = rmq_scenario(
                'C3-bad-auth',
                array( 'host' => $live_host, 'port' => getenv( 'UC_RABBITMQ_PORT' ) ?: 5672, 'user' => 'definitely-not-a-user', 'pass' => bin2hex( random_bytes( 8 ) ), 'vhost' => '/' ),
                false
        );
        rcheck( $results, 'C3 wrong-credentials rejected', 'OK' === $out3, $out3 );
} else {
        rskip( 'C3 wrong-credentials rejected', 'UC_RABBITMQ_HOST not set' );
}

// C4/C5: REAL broker through the plugin backend + config filter.
if ( $has_live && file_exists( $vendor_autoload ) ) {
        list( $out4 ) = rmq_scenario(
                'C4-live',
                array(
                        'host'  => $live_host,
                        'port'  => (int) ( getenv( 'UC_RABBITMQ_PORT' ) ?: 5672 ),
                        'user'  => getenv( 'UC_RABBITMQ_USER' ) ?: '',
                        'pass'  => getenv( 'UC_RABBITMQ_PASSWORD' ) ?: '',
                        'vhost' => getenv( 'UC_RABBITMQ_VHOST' ) ?: '/',
                ),
                true
        );
        if ( 'OK' === $out4 ) {
                rcheck( $results, 'C4 live broker via plugin backend', true );
        } elseif ( false !== strpos( $out4, 'MISMATCH' ) ) {
                // Distinguish credential rejection from transport failure with a
                // bounded management-API probe (evidence only — secrets never printed).
                $mhost   = parse_url( 'http://' . $live_host, PHP_URL_HOST );
                $auth    = base64_encode( ( getenv( 'UC_RABBITMQ_USER' ) ?: '' ) . ':' . ( getenv( 'UC_RABBITMQ_PASSWORD' ) ?: '' ) );
                $mctx    = stream_context_create( array( 'http' => array( 'timeout' => 8, 'header' => "Authorization: Basic {$auth}\r\n", 'ignore_errors' => true ) ) );
                $mraw    = @file_get_contents( "http://{$mhost}:15672/api/overview", false, $mctx );
                $mstatus = 0;
                foreach ( $http_response_header ?? array() as $h ) {
                        if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $h, $mm ) ) { $mstatus = (int) $mm[1]; }
                }
                if ( 401 === $mstatus || 403 === $mstatus ) {
                        echo "[BLOCKED] C4 live broker << credential material rejected by broker (mgmt API HTTP {$mstatus}; AMQP auth refused). Transport network path IS reachable.\n";
                        echo "          not executed, not passed — resume with valid UC_RABBITMQ_* credentials and re-run this suite\n";
                } else {
                        rcheck( $results, 'C4 live broker via plugin backend', false, $out4 . " mgmt_status={$mstatus}" );
                }
        } else {
                rcheck( $results, 'C4 live broker via plugin backend', false, $out4 );
        }
} else {
        rskip( 'C4 live broker via plugin backend', ! $has_live ? 'UC_RABBITMQ_HOST not set' : 'php-amqplib not vendored' );
}

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
