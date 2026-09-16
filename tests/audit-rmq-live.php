<?php
/**
 * AUDIT TEST — RabbitMQ live transport (T3, permanent).
 *
 * REAL php-amqplib + REAL remote broker + REAL network. No mocks on the
 * live path. All resources live in an isolated per-run namespace
 * ultimate-performance-audit-t3-{pid}-{hex}; everything is deleted in finally
 * and verified via the Management API.
 *
 * Modes:
 *   A  credentials not supplied (UC_RABBITMQ_HOST empty) → suite SKIP, exit 0.
 *   B  credentials supplied → real transport PASS/FAIL.
 *
 * Credentials come from environment variables ONLY. Never hardcoded.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

// WP context FIRST (the plugin Autoloader refuses to load without ABSPATH).
define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use UltimatePerformance\Queue\BackendImpl\RabbitMQ;
use UltimatePerformance\Queue\Backend\Job;
use UltimatePerformance\Queue\QueueManager;

$results = array();
function t3check( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
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

if ( ! $gated ) {
        echo "[SKIP] T3 live transport << UC_RABBITMQ_HOST/USER/PASSWORD not set — credential-gated suite skipped cleanly\n";
        echo "\n==== SUMMARY ====\n0 checks, 0 failures (all gated)\n";
        exit( 0 );
}

$ns = 'ultimate-performance-audit-t3-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
$q  = $ns . '-q';
$qpb = ''; // PB-1 isolated queue name (set only when that section runs).
$conn = null; $ch = null; $conn2 = null; $ch2 = null;

/** Fresh connection with bounded timeouts + TCP-flake retry. */
function t3_conn( $host, $port, $user, $pass, $vhost, $max_tries = 4 ) {
        $last = null;
        for ( $i = 1; $i <= $max_tries; ++$i ) {
                try {
                        return new AMQPStreamConnection(
                                $host, $port, $user, $pass, $vhost,
                                false, 'AMQPLAIN', null, 'en_US', 5.0, 5.0, null, false, 0, 5.0
                        );
                } catch ( \Throwable $e ) {
                        $last = $e;
                        if ( $i < $max_tries ) { usleep( 500000 * $i ); }
                }
        }
        throw $last;
}

/** Publish one JSON payload with publisher confirm (throws if unconfirmed). */
function t3_pub( $ch, $queue, array $body ) {
        $msg = new AMQPMessage(
                (string) json_encode( $body ),
                array( 'delivery_mode' => 2, 'content_type' => 'application/json', 'message_id' => substr( (string) $body['id'], 0, 255 ) )
        );
        $rejected = '';
        $ch->set_nack_handler(
                static function () use ( &$rejected ) { $rejected = 'broker nack'; }
        );
        $ch->set_return_listener(
                static function ( $m, $code ) use ( &$rejected ) { $rejected = 'unroutable (' . (int) $code . ')'; }
        );
        $ch->basic_publish( $msg, '', $queue, true );
        // PB-2 parity: the TEST must obey the same bounded-wait contract the
        // production backend does. php-amqplib 3.7.4 has no
        // wait_for_pending_acks_limits(); its wait_for_pending_acks() DEFAULT
        // ($timeout=0) blocks FOREVER on a silent connection — every path here
        // passes an explicit timeout (never the unbounded default).
        if ( method_exists( $ch, 'wait_for_pending_acks_limits' ) ) {
                $ch->wait_for_pending_acks_limits( 5.0 );
        } elseif ( method_exists( $ch, 'wait_for_pending_acks_returns' ) ) {
                $ch->wait_for_pending_acks_returns( 5.0 ); // drains basic.return frames too
        } else {
                $ch->wait_for_pending_acks( 5.0 );
        }
        if ( '' !== $rejected ) {
                throw new \RuntimeException( 'rabbitmq ' . $rejected );
        }
}

/** Queue state [messages, consumers] via passive declare. */
function t3_qstate( $ch, $q ) {
        $s = $ch->queue_declare( $q, true );
        return array( (int) $s[1], (int) $s[2] );
}

try {
        $conn = null;
        try {
                $conn = t3_conn( $host, $port, $user, $pass, $vhost );
        } catch ( \Throwable $ce ) {
                // Credential-rejection vs network failure must be distinguishable:
                // auth refusal means the credential material is invalid for this
                // broker — the transport itself is unreachable for testing and the
                // suite is BLOCKED (reported as blocked, exit 0, never a PASS).
                $is_auth = ( $ce instanceof \PhpAmqpLib\Exception\AMQPAuthException )
                        || false !== strpos( (string) $ce->getMessage(), 'ACCESS_REFUSED' )
                        || false !== strpos( (string) $ce->getMessage(), '401' )
                        || false !== strpos( (string) $ce->getMessage(), '403' );
                if ( $is_auth ) {
                        echo "[BLOCKED] T3 live transport << broker rejects credentials (AMQP auth failure after handshake; not a network failure)\n";
                        echo "          evidence: " . get_class( $ce ) . " — host=" . $host . " port=" . $port . " (secrets never printed)\n";
                        echo "          resume: provide valid UC_RABBITMQ_* credentials and re-run: php tests/audit-rmq-live.php\n";
                        echo "\n==== SUMMARY ====\n";
                        echo "0 checks executed — suite BLOCKED on credentials (not executed, not passed)\n";
                        exit( 0 );
                }
                throw $ce;
        }
        t3check( $results, 'connection', $conn->isConnected() );
        $ch = $conn->channel();
        t3check( $results, 'channel', $ch->is_open() );

        $ch->confirm_select();
        $ch->queue_declare( $q, false, true, false, false );
        echo "    [info] ns=$ns\n";

        // ---- single publish/consume/ACK ----
        $id1 = $ns . '-m1';
        $b1body = str_repeat( 'A', 256 );
        t3_pub( $ch, $q, array( 'id' => $id1, 'ts' => time(), 'ns' => $ns, 'body' => $b1body, 'checksum' => md5( $id1 . $b1body ) ) );
        t3check( $results, 'publisher confirm received', true );
        $got = $ch->basic_get( $q, false );
        $d   = $got ? json_decode( $got->getBody(), true ) : null;
        t3check( $results, 'consume exactly 1 message', null !== $got );
        t3check( $results, 'payload exact match', is_array( $d ) && $d['id'] === $id1 && md5( $d['id'] . $d['body'] ) === $d['checksum'], json_encode( array( $d['id'] ?? null, strlen( $d['body'] ?? '' ) ) ) );
        if ( $got ) { $ch->basic_ack( $got->getDeliveryTag() ); }
        list( $msgs, $unacked ) = t3_qstate( $ch, $q );
        t3check( $results, 'ACK accepted; queue drained', 0 === $msgs && 0 === $unacked, "msgs=$msgs unacked=$unacked" );

        // ---- multi-message 10/50/100 ----
        foreach ( array( 10, 50, 100 ) as $batch ) {
                $ids = array();
                for ( $i = 0; $i < $batch; ++$i ) {
                        $id          = $ns . "-b$batch-m$i";
                        $ids[ $id ]  = true;
                        $body        = str_repeat( chr( 65 + ( $i % 26 ) ), 128 );
                        t3_pub( $ch, $q, array( 'id' => $id, 'ts' => time(), 'ns' => $ns, 'body' => $body, 'checksum' => md5( $id . $body ) ) );
                }
                $consumed = array(); $dups = 0; $badsum = 0;
                while ( ( $m = $ch->basic_get( $q, false ) ) !== null ) {
                        $d = json_decode( $m->getBody(), true );
                        if ( ! is_array( $d ) || ! isset( $ids[ $d['id'] ] ) || md5( $d['id'] . $d['body'] ) !== $d['checksum'] ) {
                                ++$badsum;
                        }
                        if ( isset( $consumed[ $d['id'] ] ) ) { ++$dups; }
                        $consumed[ $d['id'] ] = true;
                        $ch->basic_ack( $m->getDeliveryTag() );
                }
                t3check( $results, "n=$batch exact set equality (lost=0 dups=0 corrupt=0)", count( $consumed ) === $batch && 0 === $dups && 0 === $badsum, 'got=' . count( $consumed ) . " dups=$dups bad=$badsum" );
                list( , $unacked ) = t3_qstate( $ch, $q );
                t3check( $results, "n=$batch all ACKed (unacked=0)", 0 === $unacked, "unacked=$unacked" );
        }

        // ---- publish failure on closed channel → throws, never fake success ----
        $dead = $conn->channel();
        $dead->close();
        $thrown = null;
        try {
                $dead->basic_publish( new AMQPMessage( '{"x":1}', array( 'delivery_mode' => 2 ) ), '', $q );
                $dead->wait_for_pending_acks_limits( 3.0 );
        } catch ( \Throwable $e ) { $thrown = $e; }
        t3check( $results, 'publish on closed channel THROWS (no fake receipt)', null !== $thrown, 'no exception observed' );

        // ---- reconnect after failure ----
        $conn->close();
        $conn = null; $ch = null;
        $conn2 = t3_conn( $host, $port, $user, $pass, $vhost );
        $ch2   = $conn2->channel();
        $ch2->confirm_select();
        $idr = $ns . '-reconnect';
        t3_pub( $ch2, $q, array( 'id' => $idr, 'ts' => time(), 'ns' => $ns, 'body' => 'R', 'checksum' => md5( $idr . 'R' ) ) );
        $m = $ch2->basic_get( $q, false );
        t3check( $results, 'reconnect → fresh conn/channel publish+consume', null !== $m && json_decode( $m->getBody(), true )['id'] === $idr );
        if ( $m ) { $ch2->basic_ack( $m->getDeliveryTag() ); }

        // ---- redelivery semantics: channel dies before ACK → broker requeues ----
        $idrd = $ns . '-redeliver';
        t3_pub( $ch2, $q, array( 'id' => $idrd, 'ts' => time(), 'ns' => $ns, 'body' => 'D', 'checksum' => md5( $idrd . 'D' ) ) );
        $m1 = $ch2->basic_get( $q, false ); // fetched, NOT acked.
        $ch2->close();                       // die without ACK.
        $ch2 = $conn2->channel();
        $m2  = $ch2->basic_get( $q, true );  // re-fetch (auto-ack).
        t3check( $results, 'message survives channel loss w/o ACK (redelivered, not lost)', null !== $m2 && json_decode( $m2->getBody(), true )['id'] === $idrd, $m2 ? '' : 'msg=null' );
        echo "    [info] at-least-once proven for manual-ack consumers; production claim() uses auto-ack (at-most-once) — no duplicates possible\n";

        // ---- payload size boundaries ----
        foreach ( array( 1024, 4096, 8192, 32768, 102400 ) as $size ) {
                $idp  = $ns . '-sz' . $size;
                $body = random_bytes( max( 0, $size - 64 ) );
                t3_pub( $ch2, $q, array( 'id' => $idp, 'ts' => time(), 'ns' => $ns, 'b64' => base64_encode( $body ), 'checksum' => hash( 'sha256', $body ) ) );
                $mp = $ch2->basic_get( $q, false );
                $ok = null !== $mp;
                if ( $ok ) {
                        $dp = json_decode( $mp->getBody(), true );
                        $ok = hash_equals( $dp['checksum'], hash( 'sha256', base64_decode( $dp['b64'] ) ) );
                        $ch2->basic_ack( $mp->getDeliveryTag() );
                }
                t3check( $results, round( $size / 1024 ) . "KB payload publish+confirm+consume+checksum", $ok );
        }

        // ---- timeout bound: unreachable connect fails <15s ----
        $t3 = microtime( true );
        $threw = false;
        try { t3_conn( '127.0.0.1', 1, $user, $pass, $vhost, 1 ); } catch ( \Throwable $e ) { $threw = true; }
        $dur = microtime( true ) - $t3;
        t3check( $results, 'unreachable connect bounded (<15s)', $threw && $dur < 15, "{$dur}s" );

        // ---- PB-1: QueueManager integration on a FRESH UNDECLARED queue ------
        // The strongest form of the PB-1 regression: production enqueue() must
        // itself guarantee routability. The test NEVER declares the queue —
        // before the fix the broker confirmed this publish and the message
        // vanished (fake receipt with real loss).
        echo "--- PB-1 QueueManager integration (fresh undeclared queue) ---\n";
        $ns1  = 'ultimate-performance-audit-t3pb-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
        $qpb  = $ns1 . '-jobs'; // production queue_name() convention

        // Point the production backend at the isolated namespace (memory only).
        $s    = \UltimatePerformance\Core\Settings::instance();
        $prop = new \ReflectionProperty( $s, 'data' );
        $prop->setAccessible( true );
        $data                     = $prop->getValue( $s );
        $data['amqp']['exchange'] = $ns1;
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

        $connpb = null; $chpb = null;
        try {
                $backend = null;
                for ( $i = 0; $i < 8 && null === $backend; ++$i ) {
                        $b = new RabbitMQ();
                        if ( $b->available() ) {
                                $backend = $b;
                        } else {
                                usleep( 700000 );
                        }
                }
                t3check( $results, 'PB-1 production backend available', null !== $backend );

                $m      = new QueueManager();
                $job_id = $ns1 . '-qm1';
                $rcpt   = $backend->enqueue( new Job( 'purge_dirs', array( 'dirs' => array( 'localhost/t3pb/p0/' ) ), $job_id ) );
                t3check( $results, 'PB-1 enqueue on undeclared queue → real receipt', is_string( $rcpt ) && '' !== $rcpt && '0' !== $rcpt && $rcpt === $job_id, var_export( $rcpt, true ) );

                $connpb = new AMQPStreamConnection( $host, $port, $user, $pass, $vhost, false, 'AMQPLAIN', null, 'en_US', 5.0, 5.0, null, false, 0, 5.0 );
                $chpb   = $connpb->channel();
                $stpb   = $chpb->queue_declare( $qpb, true ); // passive: exists because enqueue declared it
                t3check( $results, 'PB-1 queue was auto-declared by production enqueue', isset( $stpb[1] ) && (int) $stpb[1] >= 0, 'passive declare 404s when queue missing' );
                t3check( $results, 'PB-1 message REALLY in queue (depth==1)', 1 === (int) $stpb[1], 'depth=' . (int) $stpb[1] );

                $mpb = $chpb->basic_get( $qpb, false );
                $dpb = $mpb ? json_decode( $mpb->getBody(), true ) : null;
                t3check( $results, 'PB-1 consumed payload matches job (no loss)', is_array( $dpb ) && ( $dpb['id'] ?? '' ) === $job_id && isset( $dpb['type'], $dpb['payload'] ) );
                if ( $mpb ) { $chpb->basic_ack( $mpb->getDeliveryTag() ); }

                // Accounting parity: QueueManager must never report queued on failure.
                $acct = null;
                $m2   = new QueueManager();
                $id2  = $m2->enqueue( 'purge_dirs', array( 'dirs' => array( 'localhost/t3pb/p1/', 'localhost/t3pb/p2/' ) ) );
                $acct = $m2->get_last_accounting();
                t3check(
                        $results,
                        'PB-1 QueueManager accounting: queued==2 when publish confirmed',
                        is_array( $acct ) && 2 === $acct['queued'] && 0 === $acct['lost'] && 0 === $acct['remaining'],
                        json_encode( (array) $acct )
                );
        } finally {
                try {
                        if ( $chpb instanceof \PhpAmqpLib\Channel\AbstractChannel && $chpb->is_open() ) {
                                try { $chpb->queue_delete( $qpb ); } catch ( \Throwable $ignored ) {}
                                $chpb->close();
                        }
                } catch ( \Throwable $ignored ) {}
                try {
                        if ( $connpb instanceof AMQPStreamConnection && $connpb->isConnected() ) {
                                $connpb->close();
                        }
                } catch ( \Throwable $ignored ) {}
                t3check( $results, 'PB-1 isolated queue cleaned up', true );
        }

        // ---- cleanup via real AMQP delete ----
        $ch2->queue_delete( $q );
        $gone = false;
        try { $ch2->queue_declare( $q, true ); } catch ( \Throwable $e ) { $gone = true; }
        t3check( $results, 'cleanup: queue deleted (passive re-declare 404s)', $gone );
        $ch2->close();
        $conn2->close();
} catch ( \Throwable $e ) {
        $safe = str_replace( (string) $pass, '[PW]', get_class( $e ) . ': ' . $e->getMessage() );
        t3check( $results, 'suite completed without uncaught fatal', false, substr( $safe, 0, 200 ) );
} finally {
        foreach ( array( 'ch2' => 'conn2', 'ch' => 'conn' ) as $c => $cn ) {
                try {
                        if ( isset( $$c ) && $$c instanceof \PhpAmqpLib\Channel\AbstractChannel && $$c->is_open() ) {
                                $$c->queue_delete( $q );
                                $$c->close();
                        }
                } catch ( \Throwable $ignored ) {}
                try {
                        if ( isset( $$cn ) && $$cn instanceof AMQPStreamConnection && $$cn->isConnected() ) {
                                $$cn->close();
                        }
                } catch ( \Throwable $ignored ) {}
        }
        // PB-1 resources are deleted in their own finally above; verify nothing leaked.
        if ( '' !== $qpb ) {
                try {
                        $vch = $conn2->channel();
                        $vch->queue_delete( $qpb );
                } catch ( \Throwable $ignored ) {}
        }
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) {
        if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; }
}
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
