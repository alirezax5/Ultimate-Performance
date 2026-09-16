<?php
/**
 * AUDIT TEST — RabbitMQ Phase H fixes regression (PB-1/PB-2/PB-3, permanent).
 *
 * Verifies the three T6 production fixes on the REAL backend source:
 *
 *   PB-3  autoload resolution:
 *     A1  vendor present (production path, NO manual autoload) → lib class
 *         resolves through backend's own ensure_amqp_lib().
 *     A2  ULTIMATE_PERFORMANCE_NO_VENDOR → available() false, no fatal.
 *     A3  no ULTIMATE_PERFORMANCE_DIR → false, no fatal.
 *
 *   PB-1  receipt contract (live, env-gated UC_RABBITMQ_*):
 *     B1  FRESH queue (never pre-declared by the test) → backend enqueue()
 *         → message REALLY in queue (AMQP passive-declare depth == 1) → consume.
 *         This is the exact silent-loss scenario: before the fix the broker
 *         confirmed the publish but the message was unroutable and dropped.
 *     B2  closed-channel publish → EnqueueException, never a fake receipt.
 *     B3  cleanup: isolated queue deleted after the test.
 *
 *   PB-2  bounded confirm wait:
 *     C1  closed-channel publish path throws within bounded time (live).
 *     C2  source audit: every wait_for_pending_acks* call in production
 *         source passes an explicit timeout > 0 (no unbounded wait).
 *
 * Credentials: environment variables ONLY (UC_RABBITMQ_*). Never hardcoded.
 * All queues are per-run isolated (pid + random hex) and deleted in finally.
 * Run: php tests/audit-rmq-fixes.php [--live]   (exit 0 unless a FAIL)
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

if ( PHP_SAPI !== 'cli' ) {
        exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Queue\BackendImpl\RabbitMQ;
use UltimatePerformance\Queue\Backend\Job;

$results = array();
function fcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}
function fskip( $name, $why ) {
        echo "[SKIP] $name << $why\n";
}

$host  = getenv( 'UC_RABBITMQ_HOST' );
$port  = (int) ( getenv( 'UC_RABBITMQ_PORT' ) ?: 5672 );
$user  = getenv( 'UC_RABBITMQ_USER' );
$pass  = getenv( 'UC_RABBITMQ_PASSWORD' );
$vhost = getenv( 'UC_RABBITMQ_VHOST' ) ?: '/';
if ( '' === (string) $vhost ) {
        $vhost = '/';
}
$gated = is_string( $host ) && '' !== $host && is_string( $user ) && '' !== $user && is_string( $pass );
$live  = in_array( '--live', $argv ?? array(), true ) && $gated;

if ( in_array( '--live', $argv ?? array(), true ) && ! $gated ) {
        echo "[SKIP] live section << UC_RABBITMQ_HOST/USER/PASSWORD not set — skipping cleanly\n";
}

// ==========================================================================
// PB-3 — autoload resolution (runs WITHOUT manual vendor autoload)
// ==========================================================================
echo "--- PB-3 autoload resolution ---\n";

// A1: production path — backend must resolve the vendored lib ITSELF.
// NOTE: this suite deliberately does NOT require vendor/autoload.php.
$b1             = new RabbitMQ();
$lib_ok_before  = class_exists( '\PhpAmqpLib\Connection\AMQPStreamConnection' );
$avail1         = $b1->available();
$lib_ok_after   = class_exists( '\PhpAmqpLib\Connection\AMQPStreamConnection' );
if ( $lib_ok_before ) {
        // Lib already loaded by another suite in this process — resolution trivially true.
        fskip( 'A1 backend resolves vendored lib itself', 'lib already loaded in this process' );
} else {
        fcheck( $results, 'A1 backend resolves vendored lib itself', $lib_ok_after, 'class still missing after available()' );
        // available() = vendor resolution AND reachable+authenticating broker. The
        // PB-3 concern is ONLY the autoload path; broker acceptance is covered by
        // audit-rmq-connection C4. Two environment outcomes must NOT be reported
        // as autoload failures:
        //   1. No UC_RABBITMQ_* credentials configured  -> there is no broker this
        //      row could authenticate against; available()=false is CORRECT
        //      fail-closed production behavior, so the availability row is
        //      SKIPped (environment absent) — never failed, never counted PASS.
        //   2. Credentials configured but broker rejects them (mgmt 401/403)
        //      -> BLOCKED with evidence.
        // Any other mgmt status with credentials configured remains a hard FAIL.
        if ( ! $avail1 ) {
                if ( '' === (string) getenv( 'UC_RABBITMQ_HOST' ) ) {
                        fskip( 'A1 available() true (vendor present)', 'no UC_RABBITMQ_* credentials configured — broker availability not executable; PB-3 autoload resolution PASSED above' );
                } else {
                        $mhost   = parse_url( 'http://' . (string) getenv( 'UC_RABBITMQ_HOST' ), PHP_URL_HOST );
                        $auth    = base64_encode( (string) ( getenv( 'UC_RABBITMQ_USER' ) ?: '' ) . ':' . (string) ( getenv( 'UC_RABBITMQ_PASSWORD' ) ?: '' ) );
                        $mctx    = stream_context_create( array( 'http' => array( 'timeout' => 8, 'header' => "Authorization: Basic {$auth}\r\n", 'ignore_errors' => true ) ) );
                        $mraw    = @file_get_contents( "http://{$mhost}:15672/api/overview", false, $mctx );
                        $mstatus = 0;
                        foreach ( $http_response_header ?? array() as $h ) {
                                if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $h, $mm ) ) { $mstatus = (int) $mm[1]; }
                        }
                        if ( 401 === $mstatus || 403 === $mstatus ) {
                                echo "[BLOCKED] A1 available()=true row << broker rejects credentials (mgmt HTTP {$mstatus}); PB-3 autoload resolution itself PASSED\n";
                        } else {
                                fcheck( $results, 'A1 available() true (vendor present)', false, 'lib resolved but available()=false; mgmt_status=' . $mstatus );
                        }
                }
        } else {
                fcheck( $results, 'A1 available() true (vendor present)', $avail1, 'available=' . var_export( $avail1, true ) );
        }
}

// A2/A3: no-vendor hook / no plugin dir → false, no fatal (child processes).
function fx_child( $script, array $env ) {
        $cmd = 'php ' . escapeshellarg( $script );
        foreach ( $env as $k => $v ) {
                $cmd .= ' ' . escapeshellarg( $k . '=' . $v );
        }
        return trim( (string) shell_exec( $cmd . ' 2>&1' ) );
}
$out2 = fx_child( __DIR__ . '/fixtures/rmq-fixes-child.php', array( 'UCFX_CASE' => 'no_vendor' ) );
fcheck( $results, 'A2 no-vendor hook: available()=false, no fatal', 'OK' === $out2, $out2 );

$out3 = fx_child( __DIR__ . '/fixtures/rmq-fixes-child.php', array( 'UCFX_CASE' => 'no_dir' ) );
fcheck( $results, 'A3 no ULTIMATE_PERFORMANCE_DIR: available()=false, no fatal', 'OK' === $out3, $out3 );

// ==========================================================================
// PB-2 — source audit: no unbounded confirm wait in production source
// ==========================================================================
echo "--- PB-2 bounded wait (source audit) ---\n";
$src = (string) file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/Queue/BackendImpl/RabbitMQ.php' );
// Strip comments BEFORE scanning: a documented comment such as "its
// wait_for_pending_acks() defaults to $timeout=0" mentions the API but is not
// an executed call. The audit must count REAL call sites only.
$src_nc = preg_replace( '/\/\*.*?\*\//s', '', $src );
$src_nc = preg_replace( '/(^|\s)\/\/[^\n]*/', '', $src_nc );
preg_match_all( '/wait_for_pending_acks(?:_limits|_returns)?\s*\(([^)]*)\)/', (string) $src_nc, $mm );
$unbounded = 0;
foreach ( $mm[1] as $args ) {
        if ( ! preg_match( '/\d/', $args ) ) {
                ++$unbounded; // no numeric timeout in the call.
        }
}
fcheck( $results, 'C2 every confirm wait passes explicit timeout', 0 === $unbounded, 'unbounded calls: ' . $unbounded );
fcheck( $results, 'C2 timeout ≈ 3s present in source', (bool) preg_match( '/wait_for_pending_acks[^\n]*\( *3\.0 *\)/', (string) $src_nc ) );

// ==========================================================================
// PB-1/PB-2 live section (env-gated, isolated per-run queue)
// ==========================================================================
if ( $live ) {

        echo "--- PB-1 live receipt contract (real broker, isolated queue) ---\n";

        $ns     = 'ultimate-performance-audit-pb-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
        $test_q = $ns . '-jobs'; // production queue_name() convention: {exchange}-jobs.

        // Credential-rejection gate: if the broker refuses the supplied credentials
        // the live section is BLOCKED (not executed, not passed) — evidence printed.
        $auth_ok = false;
        try {
                $probe = new \PhpAmqpLib\Connection\AMQPStreamConnection( $host, $port, $user, $pass, $vhost, false, 'AMQPLAIN', null, 'en_US', 5.0, 5.0, null, false, 0, 5.0 );
                $probe->close();
                $auth_ok = true;
        } catch ( \Throwable $pe ) {
                $is_auth = ( $pe instanceof \PhpAmqpLib\Exception\AMQPAuthException )
                        || false !== strpos( (string) $pe->getMessage(), 'ACCESS_REFUSED' )
                        || false !== strpos( (string) $pe->getMessage(), '401' )
                        || false !== strpos( (string) $pe->getMessage(), '403' );
                if ( $is_auth ) {
                        echo "[BLOCKED] B live receipt contract << broker rejects credentials (AMQP auth failure after handshake; not a network failure)\n";
                        echo "          evidence: " . get_class( $pe ) . " — host=" . $host . " port=" . $port . " (secrets never printed)\n";
                        echo "          resume: provide valid UC_RABBITMQ_* credentials and re-run: php tests/audit-rmq-fixes.php --live\n";
                } else {
                        throw $pe;
                }
        }

        if ( $auth_ok ) {

        // Point the backend at the isolated namespace via Settings (memory only).
        $s    = \UltimatePerformance\Core\Settings::instance();
        $prop = new \ReflectionProperty( $s, 'data' );
        $prop->setAccessible( true );
        $data                     = $prop->getValue( $s );
        $data['amqp']['exchange'] = $ns;
        $prop->setValue( $s, $data );

        add_filter(
                'ultimate_cache_amqp_config',
                static function () use ( $host, $port, $user, $pass, $vhost ) {
                        return array(
                                'host' => $host, 'port' => $port, 'user' => $user,
                                'pass' => $pass, 'vhost' => $vhost, 'timeout' => 5.0,
                        );
                },
                100,
                0
        );

        $conn = null; $ch = null;
        try {
                // CASE A: FRESH queue — the test does NOT pre-declare it. Before the
                // PB-1 fix the broker confirmed this publish and the message was lost.
                $backend = null;
                for ( $i = 0; $i < 8 && null === $backend; ++$i ) {
                        $b = new RabbitMQ();
                        if ( $b->available() ) {
                                $backend = $b;
                        } else {
                                usleep( 700000 );
                        }
                }
                fcheck( $results, 'B0 backend available on live broker', null !== $backend );

                $id = $backend->enqueue( new Job( 'purge_dirs', array( 'dirs' => array( 'localhost/pb1/p0/' ) ), $ns . '-m1' ) );
                fcheck( $results, 'B1 CASE A: enqueue on FRESH (undeclared) queue returns receipt', is_string( $id ) && '' !== $id && $id === $ns . '-m1', var_export( $id, true ) );

                // The receipt is only honest if the message is REALLY in the queue.
                $conn = new \PhpAmqpLib\Connection\AMQPStreamConnection( $host, $port, $user, $pass, $vhost, false, 'AMQPLAIN', null, 'en_US', 5.0, 5.0, null, false, 0, 5.0 );
                $ch   = $conn->channel();
                $st   = $ch->queue_declare( $test_q, true ); // passive: must exist now (backend declared it).
                $depth = (int) $st[1];
                fcheck( $results, 'B1 CASE A: queue exists (backend declared it), depth==1', 1 === $depth, "depth=$depth" );

                $got = $ch->basic_get( $test_q, false );
                $d   = $got ? json_decode( $got->getBody(), true ) : null;
                fcheck( $results, 'B1 CASE A: message really in queue, id matches', is_array( $d ) && ( $d['id'] ?? '' ) === $id );
                if ( $got ) { $ch->basic_ack( $got->getDeliveryTag() ); }

                // CASE B: publish failure → must throw EnqueueException, never fake receipt.
                // TB-2 FIX: the original premise closed a channel on the TEST's own
                // stream connection, which the backend never uses — the backend's own
                // connection was healthy, the publish legitimately succeeded, and no
                // exception could occur (this row was previously credential-gated and
                // had never been live-executed). The corrected case kills the BACKEND's
                // real cached connection, forcing the production publish path through
                // an actual connection-level failure. Assertions are strictly stronger:
                // EnqueueException thrown AND backend fails closed (available()=false).
                $connProp = new \ReflectionProperty( $backend, 'conn' );
                $connProp->setAccessible( true );
                $backendConn = $connProp->getValue( $backend );
                fcheck( $results, 'B2 CASE B: backend holds a live connection to kill', $backendConn instanceof \PhpAmqpLib\Connection\AMQPStreamConnection );
                if ( $backendConn instanceof \PhpAmqpLib\Connection\AMQPStreamConnection ) {
                        // OS-level shutdown: amqp graceful close() proved ineffective as a
                        // kill seam (the stream stayed writable), so terminate the socket
                        // the way a real network failure would and observe production code.
                        $sock = $backendConn->getIO()->getSocket();
                        if ( is_resource( $sock ) && function_exists( 'stream_socket_shutdown' ) ) {
                                stream_socket_shutdown( $sock, STREAM_SHUT_RDWR );
                                fclose( $sock );
                        }
                        $thrown = null;
                        try {
                                $backend->enqueue( new Job( 'purge_dirs', array( 'dirs' => array( 'localhost/pb1/p1/' ) ), $ns . '-m2' ) );
                        } catch ( \Throwable $e ) {
                                $thrown = $e;
                        }
                        fcheck( $results, 'B2 CASE B: publish failure → EnqueueException (no fake receipt)', $thrown instanceof \UltimatePerformance\Queue\EnqueueException, null === $thrown ? 'no exception (publish faked success)' : get_class( $thrown ) );
                        fcheck( $results, 'B2 CASE B: backend fails closed after connection death', false === $backend->available() );
                }

                // CASE C: cleanup — isolated queue deleted.
                $ch->queue_delete( $test_q );
                $gone = false;
                try { $ch->queue_declare( $test_q, true ); } catch ( \Throwable $e ) { $gone = true; }
                fcheck( $results, 'B3 CASE C: isolated queue deleted (passive 404)', $gone );

                // PB-2 live: closed-channel confirm wait throws (bounded failure shape).
                $dead2   = $conn->channel();
                $dead2->close();
                $t0      = microtime( true );
                $thrown2 = null;
                try {
                        $dead2->basic_publish( new \PhpAmqpLib\Message\AMQPMessage( '{"x":1}', array( 'delivery_mode' => 2 ) ), '', $test_q, true );
                        $dead2->wait_for_pending_acks_returns( 3.0 );
                } catch ( \Throwable $e ) {
                        $thrown2 = $e;
                }
                $dur = microtime( true ) - $t0;
                fcheck( $results, 'C1 closed-channel confirm throws, bounded', null !== $thrown2 && $dur < 5, "dur={$dur}s" );
        } catch ( \Throwable $e ) {
                $safe = str_replace( (string) $pass, '[PW]', get_class( $e ) . ': ' . $e->getMessage() );
                fcheck( $results, 'live section completed without fatal', false, substr( $safe, 0, 200 ) );
        } finally {
                try {
                        if ( isset( $ch ) && $ch instanceof \PhpAmqpLib\Channel\AbstractChannel && $ch->is_open() ) {
                                try { $ch->queue_delete( $test_q ); } catch ( \Throwable $ignored ) {}
                                $ch->close();
                        }
                } catch ( \Throwable $ignored ) {}
                try {
                        if ( isset( $conn ) && $conn instanceof \PhpAmqpLib\Connection\AMQPStreamConnection && $conn->isConnected() ) {
                                $conn->close();
                        }
                } catch ( \Throwable $ignored ) {}
        }
        } else {
                fskip( 'B live receipt contract', 'BLOCKED: broker rejected credentials' );
                fskip( 'C1 closed-channel confirm', 'BLOCKED: broker rejected credentials' );
        }
} else {
        fskip( 'B live receipt contract', 'UC_RABBITMQ_* not set or --live not passed' );
        fskip( 'C1 closed-channel confirm', 'live section skipped' );
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) {
        if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; }
}
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
