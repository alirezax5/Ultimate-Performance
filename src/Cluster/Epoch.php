<?php
/**
 * Cluster invalidation — N1. Durable shared purge-generation authority
 * (docs/PHASE-N-EPOCH-DESIGN.md §4.1).
 *
 * A single-row shared-DB counter is the authoritative purge generation:
 * monotonic, cluster-visible, generation-immune, bounded (one row),
 * restart/root-deletion safe, independent of request-local Memory state,
 * and never consulted on the HIT path (design invariants I1–I10).
 *
 * The bump uses the MySQL connection-scoped `LAST_INSERT_ID(expr)` idiom
 * so concurrent producers each receive the UNIQUE value their own
 * increment produced. The SQLite unit double emulates the pair
 * atomically; the real MySQL dialect is proven live by the cluster
 * runner (same dialect split as M5's dbDelta — unit double never
 * substitutes for live evidence).
 *
 * @package UltimatePerformance\Cluster
 */

namespace UltimatePerformance\Cluster;

defined( 'ABSPATH' ) || exit;

final class Epoch {

        const TABLE_NAME = 'up_cluster_epoch';
        const ROW_NAME   = 'cluster';

        /** @var string Table name (base prefix — network-wide). */
        private $table;

        /** @var bool|null Per-INSTANCE table-existence cache (null = unproven).
         * Deliberately NOT a method-static: an outage must not poison every
         * future instance in a long-lived process — recovery re-probes. */
        private $table_ok;

        public function __construct() {
                global $wpdb;
                $this->table    = $wpdb->base_prefix . self::TABLE_NAME;
                $this->table_ok = null;
        }

        /** Lazily create the single-row table; true when it exists afterwards. */
        public function ensure_table() {
                global $wpdb;
                if ( null !== $this->table_ok ) {
                        return $this->table_ok;
                }
                $t      = $this->table;
                $cs     = $wpdb->get_charset_collate();
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
                if ( $t !== $exists ) {
                        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                        dbDelta(
                                "CREATE TABLE {$t} (
                                        name CHAR(32) NOT NULL DEFAULT 'cluster',
                                        epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                        updated_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                        PRIMARY KEY  (name)
                                ) {$cs};"
                        );
                        // Row ensure (idempotent; PK protects against double-insert races).
                        $wpdb->query(
                                $wpdb->prepare(
                                        'INSERT IGNORE INTO ' . $t . " (name, epoch, updated_ms) VALUES (%s, %d, %d)",
                                        self::ROW_NAME,
                                        0,
                                        0
                                )
                        );
                }
                $ok = ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) );
                $this->table_ok = $ok;
                return $ok;
        }

        /**
         * Current shared purge generation. 0 when the authority does not exist
         * yet (fresh install — bump() creates it). Never throws. ALWAYS a fresh
         * read (invariant I2 — cluster visibility: a cached frontier would hide
         * other nodes' bumps from this instance; the single-row SELECT is the
         * recheck cost, never paid on the HIT path).
         *
         * @return int
         */
        public function current() {
                global $wpdb;
                try {
                        if ( ! $this->ensure_table() ) {
                                return 0;
                        }
                        $v = $wpdb->get_var(
                                $wpdb->prepare( 'SELECT epoch FROM ' . $this->table . ' WHERE name = %s', self::ROW_NAME )
                        );
                        return max( 0, (int) $v );
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        return 0;
                }
        }

        /**
         * Atomically increment and return THIS caller's unique new value.
         * Returns 0 (never negative) when the authority cannot be written —
         * callers treat 0 as "no generation signal exists" (fail-closed:
         * coverage must not be claimed).
         *
         * @return int
         */
        public function bump() {
                global $wpdb;
                try {
                        if ( ! $this->ensure_table() ) {
                                return 0;
                        }
                        $now = (int) round( microtime( true ) * 1000 );
                        // Atomic connection-scoped increment (MySQL/MariaDB idiom). The
                        // paired SELECT LAST_INSERT_ID() below is intercepted by the unit
                        // double and executed natively (same connection) on real servers.
                        $upd = $wpdb->query(
                                $wpdb->prepare(
                                        'UPDATE ' . $this->table . " SET epoch = LAST_INSERT_ID(epoch + 1), updated_ms = %d WHERE name = %s",
                                        $now,
                                        self::ROW_NAME
                                )
                        );
                        if ( false === $upd || 0 === (int) $upd ) {
                                // Row vanished (external reset) — recreate at 1: this bump IS
                                // generation 1 of the new authority era. Counted as an
                                // authority failure by the caller's telemetry when relevant.
                                $ins = $wpdb->query(
                                        $wpdb->prepare(
                                                'INSERT IGNORE INTO ' . $this->table . ' (name, epoch, updated_ms) VALUES (%s, 1, %d)',
                                                self::ROW_NAME,
                                                $now
                                        )
                                );
                                if ( false === $ins ) {
                                        return 0;
                                }
                                return 1;
                        }
                        $v = $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
                        if ( null === $v || (int) $v < 1 ) {
                                // Dialect/authority failure — fail closed (no generation claimed).
                                return 0;
                        }
                        return (int) $v;
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        return 0;
                }
        }

        /** True when the authority row exists and is readable (availability probe). */
        public function available() {
                global $wpdb;
                try {
                        if ( ! $this->ensure_table() ) {
                                return false;
                        }
                        $v = $wpdb->get_var(
                                $wpdb->prepare( 'SELECT epoch FROM ' . $this->table . ' WHERE name = %s', self::ROW_NAME )
                        );
                        return null !== $v;
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        return false;
                }
        }
}
