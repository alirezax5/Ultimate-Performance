<?php
/**
 * Cluster invalidation — M5. Bounded metric counters (design §6), stored in
 * the per-node cache root. Same discipline as Warmup\State (Phase J):
 *
 *  - FIXED schema: the counters below and nothing else — unknown keys are
 *    dropped by construction, so the file cannot grow shapelessly,
 *  - counts only: no event ids, no dirs, no tags, no URLs, no user data,
 *  - atomic write via temp file + rename (0640, hardened meta dir),
 *  - per-ROUND aggregation: the consumer batches its deltas and writes the
 *    file once per consumption round, never once per event.
 *
 * Counters are monotonic since process start and reset only when the file
 * is removed with the cache tree (purge-all semantics). `lag_ms` is the
 * last sampled end-to-end consumption lag (now − created of the last
 * consumed event); it is a gauge, not a counter.
 *
 * @package UltimatePerformance\Cluster
 */

namespace UltimatePerformance\Cluster;

defined( 'ABSPATH' ) || exit;

final class State {

        const FILENAME = 'meta/cluster-state.json';

        /**
         * Fixed counter keys. Anything else passed to bump() is IGNORED.
         * N1 adds the epoch/recovery counters (docs/PHASE-N-EPOCH-DESIGN
         * §6): reconciliations, authority failures, detected gaps,
         * watermark resets, node-id collisions. Still schema-locked —
         * no key below can be added at runtime.
         */
        const COUNTERS = array(
                'published',
                'consumed',
                'duplicates',
                'failures',
                'stale',
                'epoch_reconciliations',
                'epoch_authority_failures',
                'event_gaps',
                'watermark_resets',
                'node_id_collisions',
        );

        /** @var string cache root (no trailing slash) */
        private $root;

        /**
         * @param string|null $root Cache root override (tests).
         */
        public function __construct( $root = null ) {
                $this->root = null === $root
                        ? ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/cache/ultimate-performance'
                        : rtrim( (string) $root, '/' );
        }

        /**
         * Read the counters. Missing/corrupt file → all zeros (never throws).
         *
         * @return array<string,int>
         */
        public function read() {
                $row = array_fill_keys( self::COUNTERS, 0 );
                $row['lag_ms'] = 0;
                $file = $this->root . '/' . self::FILENAME;
                if ( is_readable( $file ) && ! is_link( $file ) ) {
                        $dec = json_decode( (string) file_get_contents( $file ), true );
                        if ( is_array( $dec ) ) {
                                foreach ( self::COUNTERS as $k ) {
                                        $row[ $k ] = (int) ( isset( $dec[ $k ] ) ? $dec[ $k ] : 0 );
                                }
                                $row['lag_ms'] = (int) ( isset( $dec['lag_ms'] ) ? $dec['lag_ms'] : 0 );
                        }
                }
                return $row;
        }

        /**
         * Atomically increment counters (schema-locked: unknown keys dropped).
         * M5-D4: the interrupted implementation clamped only the SUM at zero,
         * which let a NEGATIVE increment DECREASE a counter (3 → 0) — the
         * counters are monotonic by contract, so negative increments are
         * ignored entirely.
         *
         * @param array<string,int> $deltas Counter name → increment.
         * @return bool
         */
        public function bump( array $deltas ) {
                $row = $this->read();
                foreach ( (array) $deltas as $k => $by ) {
                        if ( in_array( (string) $k, self::COUNTERS, true ) ) {
                                $row[ (string) $k ] += max( 0, (int) $by );
                        }
                }
                return $this->write( $row );
        }

        /**
         * Record the last sampled consumption lag (gauge).
         *
         * @param int $ms Milliseconds.
         * @return bool
         */
        public function lag( $ms ) {
                $row            = $this->read();
                $row['lag_ms'] = max( 0, (int) $ms );
                return $this->write( $row );
        }

        /**
         * Atomic full write (temp + rename). Callers must pass the COMPLETE
         * fixed row (read() → modify → write); unknown keys are dropped here.
         *
         * @param array<string,int> $row
         * @return bool
         */
        private function write( array $row ) {
                $dir = $this->root . '/meta';
                if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
                        return false;
                }
                $out = array();
                foreach ( self::COUNTERS as $k ) {
                        $out[ $k ] = (int) ( isset( $row[ $k ] ) ? $row[ $k ] : 0 );
                }
                $out['lag_ms'] = (int) ( isset( $row['lag_ms'] ) ? $row['lag_ms'] : 0 );
                $tmp           = $dir . '/.cluster-state.tmp-' . getmypid();
                $final         = $this->root . '/' . self::FILENAME;
                if ( false === @file_put_contents( $tmp, (string) wp_json_encode( $out ), LOCK_EX ) ) {
                        return false;
                }
                @chmod( $tmp, 0640 );
                if ( ! @rename( $tmp, $final ) ) {
                        @unlink( $tmp );
                        return false;
                }
                return true;
        }
}
