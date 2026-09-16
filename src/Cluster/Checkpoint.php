<?php
/**
 * Cluster invalidation — N1. Per-node durable checkpoint: the highest
 * shared purge generation this node has PROVEN coverage of
 * (docs/PHASE-N-EPOCH-DESIGN.md §4.3).
 *
 * Storage: meta/cluster-checkpoint.json — inside the cache root's meta
 * dir, which is OUTSIDE the purgeable v/ tree (the consumer purge-all
 * deletes v/ only). The file carries TWO integers:
 *
 *   checkpoint — proven coverage (targeted contiguous chain or reconcile)
 *   observed   — frontier of shared-epoch OBSERVATIONS (may exceed the
 *                checkpoint while a backlog drains; the authority-outage
 *                path reconciles up to this value because bumps are
 *                impossible while the authority is down).
 *
 * Loss of the file (cache-root deletion, purge_site) degrades both to 0
 * → one self-healing reconcile — bounded over-invalidation, never
 * staleness (invariants I6/I8).
 *
 * The file is NODE-BOUND: a checkpoint carrying a different node id is
 * invalid (clone-regeneration path) and reads as 0 (fail-closed).
 *
 * @package UltimatePerformance\Cluster
 */

namespace UltimatePerformance\Cluster;

defined( 'ABSPATH' ) || exit;

final class Checkpoint {

        const FILENAME = 'meta/cluster-checkpoint.json';

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
         * Read the PROVEN-coverage checkpoint. Missing/corrupt/foreign-node
         * file → 0 (never throws; fail-closed).
         *
         * @param string|null $node_id When given, a foreign-node file reads as 0.
         * @return int
         */
        public function read( $node_id = null ) {
                $row = $this->read_row( $node_id );
                return null === $row ? 0 : max( 0, (int) ( $row['checkpoint'] ?? 0 ) );
        }

        /**
         * Read the observation frontier (last shared epoch this node actually
         * SAW). Same failure semantics as read(): 0 on absent/corrupt/foreign.
         *
         * @param string|null $node_id
         * @return int
         */
        public function observed( $node_id = null ) {
                $row = $this->read_row( $node_id );
                return null === $row ? 0 : max( 0, (int) ( $row['observed'] ?? 0 ) );
        }

        /**
         * Atomically write the checkpoint (temp + rename, 0640). The node id is
         * bound into the file at write time; the observation frontier moves up
         * to at least the coverage value (coverage implies observation).
         *
         * @param int    $value   Proven-coverage value.
         * @param string $node_id Owner node id.
         * @return bool
         */
        public function write( $value, $node_id ) {
                $prev          = $this->read_row( $node_id );
                $prev_observed = null === $prev ? 0 : max( 0, (int) ( $prev['observed'] ?? 0 ) );
                return $this->write_all( max( 0, (int) $value ), max( $prev_observed, (int) $value ), $node_id );
        }

        /**
         * Atomically write BOTH integers (coverage + observation frontier).
         *
         * @param int    $coverage
                 * @param int    $observed
         * @param string $node_id
         * @return bool
         */
        public function write_all( $coverage, $observed, $node_id ) {
                $dir = $this->root . '/meta';
                if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
                        return false;
                }
                $out = array(
                        'checkpoint' => max( 0, (int) $coverage ),
                        'observed'   => max( max( 0, (int) $coverage ), max( 0, (int) $observed ) ),
                        'updated_ms' => (int) round( microtime( true ) * 1000 ),
                        'node'       => (string) $node_id,
                );
                $tmp   = $dir . '/.cluster-checkpoint.tmp-' . getmypid();
                $final = $this->root . '/' . self::FILENAME;
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

        /** Last-updated timestamp (ms) for health display; 0 when absent. */
        public function updated_ms() {
                $row = $this->read_row( null );
                return null === $row ? 0 : max( 0, (int) ( $row['updated_ms'] ?? 0 ) );
        }

        /**
         * @param string|null $node_id
         * @return array<string,mixed>|null decoded row or null
         */
        private function read_row( $node_id ) {
                $file = $this->root . '/' . self::FILENAME;
                if ( ! is_readable( $file ) || is_link( $file ) ) {
                        return null;
                }
                $dec = json_decode( (string) file_get_contents( $file ), true );
                if ( ! is_array( $dec ) ) {
                        return null;
                }
                if ( null !== $node_id && (string) ( $dec['node'] ?? '' ) !== (string) $node_id ) {
                        return null; // cloned/restored node — reconcile from zero
                }
                return $dec;
        }
}
