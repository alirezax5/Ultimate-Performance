<?php
/**
 * RabbitMQ queue backend (optional; php-amqplib required).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Queue\BackendImpl;

use UltimatePerformance\Core\Settings;
use UltimatePerformance\Queue\Backend\Backend;
use UltimatePerformance\Queue\Backend\Job;
use UltimatePerformance\Queue\EnqueueException;

defined( 'ABSPATH' ) || exit;

/**
 * AMQP backend. Available only when php-amqplib present AND broker reachable
 * within a short timeout. Publisher confirms are mandatory: basic_publish()
 * alone is async fire-and-forget — a broker that silently drops a message
 * would otherwise look like success. With confirm_select() + wait_for_confirms()
 * every publish is either durably accepted by the broker or throws.
 */
final class RabbitMQ implements Backend {

        /** @var bool|null */
        private $available;

        /** @var \PhpAmqpLib\Connection\AMQPStreamConnection|null */
        private $conn;

        public function name() {
                return 'rabbitmq';
        }

        public function supports_worker() {
                return true;
        }

        public function available() {
                if ( null !== $this->available ) {
                        return $this->available;
                }
                $this->available = false;
                // PB-3: production entrypoint does NOT load the plugin-local composer
                // autoloader; the backend must resolve php-amqplib itself or the
                // class_exists() probe below would fail even on a correctly vendored
                // deployment (vendor present but autoload never required).
                if ( ! $this->ensure_amqp_lib() ) {
                        return false; // no fatal: backend unavailable → chain falls through.
                }
                $cfg = $this->amqp_config();
                try {
                        $this->conn = new \PhpAmqpLib\Connection\AMQPStreamConnection(
                                substr( $cfg['host'], 0, 255 ),
                                max( 1, min( 65535, (int) $cfg['port'] ) ),
                                substr( (string) $cfg['user'], 0, 128 ),
                                (string) $cfg['pass'],
                                substr( (string) $cfg['vhost'], 0, 128 ),
                                false,
                                'AMQPLAIN',
                                null,
                                'en_US',
                                (float) $cfg['timeout'],
                                (float) $cfg['timeout'],
                                null,
                                false, // keepalive
                                0,
                                1.5    // read timeout seconds — bounded probe
                        );
                        $this->conn->channel();
                        $this->available = true;
                } catch ( \Throwable $e ) {
                        $this->conn      = null;
                        $this->available = false;
                }
                return $this->available;
        }

        /**
         * PB-3: guarded, idempotent resolution of the vendored php-amqplib.
         * vendor present → autoload required once, class probe succeeds.
         * vendor absent/broken → false, never a fatal (boot must stay healthy;
         * QueueManager falls through to the next backend).
         *
         * @return bool
         */
        private function ensure_amqp_lib() {
                if ( class_exists( '\\PhpAmqpLib\\Connection\\AMQPStreamConnection' ) ) {
                        return true;
                }
                // Test hook: simulates a deployment without the vendored library.
                if ( defined( 'ULTIMATE_PERFORMANCE_NO_VENDOR' ) ) {
                        return false;
                }
                if ( ! defined( 'ULTIMATE_PERFORMANCE_DIR' ) ) {
                        return false;
                }
                $autoload = ULTIMATE_PERFORMANCE_DIR . 'vendor/autoload.php';
                if ( is_string( $autoload ) && is_readable( $autoload ) ) {
                        try {
                                require_once $autoload; // composer autoload is require_once-safe.
                        } catch ( \Throwable $e ) {
                                return false;
                        }
                }
                return class_exists( '\\PhpAmqpLib\\Connection\\AMQPStreamConnection' );
        }

        private function queue_name() {
                $ex = preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) Settings::instance()->get( 'amqp.exchange', 'ultimate-performance' ) );
                return ( '' !== $ex ? $ex : 'ultimate-performance' ) . '-jobs';
        }

        /**
         * Runtime connection config. Defaults come from Settings; the
         * ultimate_cache_amqp_config filter exists so deployments/tests can inject
         * credentials WITHOUT hardcoding them in source or the DB. Tests must use
         * this filter or environment variables — never literal secrets.
         *
         * @return array<string,mixed>
         */
        private function amqp_config() {
                $cfg = (array) Settings::instance()->get( 'amqp', array() );
                return (array) apply_filters(
                        'ultimate_cache_amqp_config',
                        array(
                                'host'    => isset( $cfg['host'] ) ? (string) $cfg['host'] : '127.0.0.1',
                                'port'    => isset( $cfg['port'] ) ? (int) $cfg['port'] : 5672,
                                'user'    => isset( $cfg['user'] ) ? (string) $cfg['user'] : 'guest',
                                'pass'    => isset( $cfg['pass'] ) ? (string) $cfg['pass'] : '',
                                'vhost'   => isset( $cfg['vhost'] ) ? (string) $cfg['vhost'] : '/',
                                'timeout' => 3.0,
                        )
                );
        }

        public function enqueue( Job $job ) {
                if ( ! $this->available() || null === $this->conn ) {
                        throw new EnqueueException( 'rabbitmq unavailable' );
                }
                try {
                        $ch = $this->conn->channel();
                        if ( method_exists( $ch, 'confirm_select' ) ) {
                                $ch->confirm_select();
                        }
                        $q = $this->queue_name();
                        // PB-1: declare the target queue BEFORE publishing to the default
                        // exchange (durable, non-exclusive, non-auto-delete). Without this,
                        // a missing queue makes the broker CONFIRM an unroutable publish and
                        // silently drop the message — a fake receipt with real message loss.
                        if ( method_exists( $ch, 'queue_declare' ) ) {
                                $ch->queue_declare( $q, false, true, false, false );
                        }
                        $msg = new \PhpAmqpLib\Message\AMQPMessage(
                                (string) wp_json_encode(
                                        array(
                                                'id'      => $job->id,
                                                'type'    => $job->type,
                                                'payload' => $job->payload,
                                                'att'     => (int) $job->attempts,
                                        )
                                ),
                                array(
                                        'delivery_mode' => 2,
                                        'content_type'  => 'application/json',
                                        'message_id'    => substr( $job->id, 0, 255 ),
                                )
                        );
                        // PB-1: mandatory routing + return/nack listeners — an unroutable
                        // or broker-rejected publish must NEVER be recorded as success.
                        $rejected = '';
                        if ( method_exists( $ch, 'set_nack_handler' ) ) {
                                $ch->set_nack_handler( static function () use ( &$rejected ) {
                                        $rejected = 'broker nack';
                                } );
                        }
                        if ( method_exists( $ch, 'set_return_listener' ) ) {
                                $ch->set_return_listener( static function ( $m, $code ) use ( &$rejected ) {
                                        $rejected = 'unroutable (' . (int) $code . ')';
                                } );
                        }
                        $ch->basic_publish( $msg, '', $q, true ); // mandatory=true
                        // PB-2: publisher confirm wait is BOUNDED (~3s). php-amqplib 3.7.4
                        // has no wait_for_pending_acks_limits(); its wait_for_pending_acks()
                        // defaults to $timeout=0 which blocks FOREVER on a silent/half-dead
                        // connection — an explicit timeout is passed on every fallback path.
                        if ( method_exists( $ch, 'wait_for_pending_acks_limits' ) ) {
                                $ch->wait_for_pending_acks_limits( 3.0 );
                        } elseif ( method_exists( $ch, 'wait_for_pending_acks_returns' ) ) {
                                // Also drains basic.return frames emitted by mandatory publishes.
                                $ch->wait_for_pending_acks_returns( 3.0 );
                        } elseif ( method_exists( $ch, 'wait_for_pending_acks' ) ) {
                                $ch->wait_for_pending_acks( 3.0 );
                        }
                        if ( '' !== $rejected ) {
                                throw new \RuntimeException( 'rabbitmq ' . $rejected );
                        }
                } catch ( \Throwable $e ) {
                        // Connection/channel died mid-publish: force re-probe next time and
                        // propagate failure — never mask a publish failure as success.
                        $this->available = false;
                        $this->conn      = null;
                        throw new EnqueueException( 'rabbitmq publish failed: ' . $e->getMessage(), 0, $e );
                }
                return $job->id;
        }

        public function claim() {
                if ( ! $this->available() || null === $this->conn ) {
                        return null;
                }
                try {
                        $ch  = $this->conn->channel();
                        $msg = $ch->basic_get( $this->queue_name(), true ); // auto-ack: at-most-once
                } catch ( \Throwable $e ) {
                        $this->available = false;
                        return null;
                }
                if ( null === $msg ) {
                        return null;
                }
                $data = json_decode( (string) $msg->getBody(), true );
                if ( ! is_array( $data ) || empty( $data['type'] ) || ! is_array( $data['payload'] ?? null ) ) {
                        return null; // malformed → poison dropped.
                }
                $job           = new Job( (string) $data['type'], $data['payload'], isset( $data['id'] ) ? (string) $data['id'] : '' );
                $job->attempts = isset( $data['att'] ) ? max( 0, min( 99, (int) $data['att'] ) ) : 0;
                return $job;
        }

        public function complete( Job $job, $success ) {
                if ( ! $success && $job->attempts < $job->max_attempts ) {
                        $job->attempts += 1;
                        // RMQ-2 (Phase H): a failed retry-enqueue must be OBSERVABLE,
                        // never silently swallowed. The claim() path is auto-ack
                        // (at-most-once): if the retry publish fails the job is gone —
                        // rethrowing lets QueueManager::work() record it via
                        // record_failure_safe() instead of vanishing with the claim.
                        $this->enqueue( $job );
                }
        }

        public function __destruct() {
                if ( null !== $this->conn ) {
                        try {
                                $this->conn->close();
                        } catch ( \Throwable $e ) {
                        }
                }
        }
}
