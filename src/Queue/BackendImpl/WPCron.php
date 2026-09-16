<?php
/**
 * WP-Cron queue backend (always-available core fallback).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Queue\BackendImpl;

use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\Lock\FileLock;
use UltimatePerformance\Queue\Backend\Backend;
use UltimatePerformance\Queue\Backend\Job;
use UltimatePerformance\Queue\EnqueueException;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs buffered in one autoloaded option; drained by ultimate_performance_tick.
 * Bounded at 500 entries, retry with linear backoff up to max_attempts,
 * poison jobs discarded after 3 attempts. Idempotency: purge/preload are
 * naturally idempotent; duplicate delivery harmless.
 *
 * T5 CONCURRENCY FIX: enqueue()/claim() perform a load→modify→save cycle on
 * a single shared option row. With concurrent requests (multiple PHP-FPM/
 * mod_php workers purging at once) that cycle is a classic lost-update
 * race — proven empirically: 5 processes × 3 appends survived as only ONE
 * writer's 3 entries. Every RMW section is now serialized behind a
 * FileLock (flock, NTFS-safe). Lock acquisition is bounded and
 * non-blocking-with-retry: on failure the enqueue THROWS so QueueManager
 * falls through to the next backend — never silent loss.
 */
final class WPCron implements Backend {

        const OPT_KEY = 'ultimate_cache_queue_cron';

        /**
         * Buffer bound (autoloaded option — must stay small). When full the
         * backend FAILS instead of dropping oldest entries: silent loss is never
         * acceptable; QueueManager falls through to the next backend.
         */
        const MAX_BUFFER = 500;

        /** Bounded lock waits: total ≈ 8 × 25ms = 200ms before giving up. */
        const LOCK_TRIES   = 8;
        const LOCK_WAIT_US = 25000;

        public function name() {
                return 'wp-cron';
        }

        public function supports_worker() {
                return true;
        }

        public function available() {
                return true;
        }

        /**
         * Lock file path for the shared buffer RMW section. Lives in the plugin
         * cache root (same filesystem domain as the option's DB row — no cross-
         * machine semantics needed).
         *
         * @return string
         */
        private static function lock_file() {
                try {
                        $dir = Installer::cache_root();
                } catch ( \Throwable $e ) {
                        $dir = sys_get_temp_dir(); // pre-install contexts (tests).
                }
                if ( ! is_dir( $dir ) ) {
                        @wp_mkdir_p( $dir );
                }
                return rtrim( $dir, '/\\' ) . '/queue-cron.lock';
        }

        /**
         * Acquire the buffer lock with bounded retries.
         *
         * @return FileLock|null null when the lock could not be acquired.
         */
        private static function acquire_lock() {
                for ( $i = 0; $i < self::LOCK_TRIES; ++$i ) {
                        $lock = new FileLock( self::lock_file() );
                        if ( $lock->acquire( 30 ) ) {
                                return $lock;
                        }
                        usleep( self::LOCK_WAIT_US );
                }
                return null;
        }

        /**
         * @return array<int,array<string,mixed>>
         */
        private function load() {
                $jobs = get_option( self::OPT_KEY, array() );
                return is_array( $jobs ) ? $jobs : array();
        }

        private function save( $jobs ) {
                update_option( self::OPT_KEY, array_values( $jobs ), true );
        }

        /**
         * Run $fn while holding the buffer lock.
         *
         * @param callable $fn function( array $jobs ): array — receives the fresh
         *                     buffer, returns the updated buffer to persist.
         * @return mixed The fn's return value.
         * @throws EnqueueException When the lock cannot be acquired in bounded time.
         */
        private static function with_buffer_lock( $fn ) {
                $lock = self::acquire_lock();
                if ( null === $lock ) {
                        throw new EnqueueException( 'wp-cron buffer lock unavailable' );
                }
                try {
                        // The lock serializes writers, but each PHP process also caches
                        // options in its non-persistent object cache from boot. A stale
                        // cached read inside the lock would resurrect a lost update even
                        // with the flock held. Drop the cache entry so get_option() hits
                        // the DB row — the authoritative state the lock protects.
                        wp_cache_delete( self::OPT_KEY, 'options' );
                        wp_cache_delete( 'alloptions', 'options' );
                        $jobs = get_option( self::OPT_KEY, array() );
                        $jobs = is_array( $jobs ) ? $jobs : array();
                        $jobs = call_user_func( $fn, $jobs );
                        if ( is_array( $jobs ) ) {
                                update_option( self::OPT_KEY, array_values( $jobs ), true );
                        }
                        return $jobs;
                } finally {
                        $lock->release();
                }
        }

        public function enqueue( Job $job ) {
                self::with_buffer_lock(
                        static function ( $jobs ) use ( $job ) {
                                $jobs[] = array(
                                        'id'      => substr( $job->id, 0, 64 ),
                                        'type'    => substr( $job->type, 0, 40 ),
                                        'payload' => $job->payload,
                                        'att'     => max( 0, min( 99, (int) $job->attempts ) ),
                                        'due'     => time(),
                                );
                                if ( count( $jobs ) > self::MAX_BUFFER ) {
                                        // H3-16: dropping oldest silently loses queued work. Fail
                                        // loudly so QueueManager falls through to the next backend.
                                        throw new EnqueueException( 'wp-cron queue buffer full (' . self::MAX_BUFFER . ')' );
                                }
                                return $jobs;
                        }
                );
                if ( ! wp_next_scheduled( 'ultimate_performance_tick' ) ) {
                        wp_schedule_single_event( time() + 30, 'ultimate_performance_tick' );
                }
                return $job->id;
        }

        public function claim() {
                $claimed = null;
                self::with_buffer_lock(
                        static function ( $jobs ) use ( &$claimed ) {
                                $now = time();
                                foreach ( $jobs as $i => $entry ) {
                                        if ( ! is_array( $entry )
                                                || ! isset( $entry['type'], $entry['payload'], $entry['id'], $entry['due'] )
                                                || ! is_string( $entry['type'] )
                                                || ! is_array( $entry['payload'] )
                                                || (int) $entry['att'] >= 3 ) {
                                                if ( isset( $jobs[ $i ] ) ) {
                                                        unset( $jobs[ $i ] ); // malformed entry → discard
                                                }
                                                continue;
                                        }
                                        if ( (int) $entry['due'] <= $now ) {
                                                unset( $jobs[ $i ] );
                                                $job           = new Job( $entry['type'], $entry['payload'], (string) $entry['id'] );
                                                $job->attempts = (int) $entry['att'];
                                                $claimed       = $job;
                                                break;
                                        }
                                }
                                return $jobs;
                        }
                );
                return $claimed;
        }

        public function complete( Job $job, $success ) {
                if ( ! $success && $job->attempts + 1 < $job->max_attempts ) {
                        $retry          = new Job( $job->type, $job->payload, $job->id );
                        $retry->attempts = $job->attempts + 1;
                        $this->enqueue_delayed( $retry, 60 * $retry->attempts ); // linear backoff
                }
        }

        private function enqueue_delayed( Job $job, $delay_seconds ) {
                self::with_buffer_lock(
                        static function ( $jobs ) use ( $job, $delay_seconds ) {
                                $jobs[] = array(
                                        'id'      => substr( $job->id, 0, 64 ),
                                        'type'    => substr( $job->type, 0, 40 ),
                                        'payload' => $job->payload,
                                        'att'     => (int) $job->attempts,
                                        'due'     => time() + max( 10, (int) $delay_seconds ),
                                );
                                if ( count( $jobs ) > self::MAX_BUFFER ) {
                                        throw new EnqueueException( 'wp-cron queue buffer full (' . self::MAX_BUFFER . ')' );
                                }
                                return $jobs;
                        }
                );
        }
}
