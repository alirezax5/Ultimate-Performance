<?php
/**
 * Cluster invalidation — M5. Event store: one shared-DB table is the cluster
 * coordination point (membership = shared DB; no coordinator, no registration).
 * Table created lazily (CREATE TABLE IF NOT EXISTS) — multisite-safe via the
 * base prefix. See docs/PHASE-M-CLUSTER-INVALIDATION.md for the full schema.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Cluster;

defined( 'ABSPATH' ) || exit;

final class EventStore {

        /**
         * N1: schema 2 adds `gen_epoch` — the shared purge generation this
         * event was produced under (docs/PHASE-N-EPOCH-DESIGN.md §4.2).
         * Schema-1 rows (Phase M) remain valid: targeted execution, no
         * checkpoint involvement, M5 TTL bound for their loss.
         */
        const SCHEMA_VERSION = 2;
        const BATCH_CAP      = 200; // bounded consumption per tick (filterable)
        const RETENTION_SEC  = 86400; // consumed rows pruned after 24h by the janitor
        /** Positive ensure_table proof TTL (sec) — dropped/restored tables re-probe. */
        const ENSURE_TTL_SEC = 60;

        /** @var string Table name (base prefix — network-wide). */
        private $table;

        /** @var int|null Per-instance positive table-proven-until timestamp
         * (unix sec). POSITIVE results cache for a bounded TTL only (a
         * dropped/restored table must re-probe and self-heal); NEGATIVE
         * results are never cached (outage re-probes). */
        private $table_ok_until;

        public function __construct() {
                global $wpdb;
                $this->table          = $wpdb->base_prefix . 'up_invalidation_events';
                $this->table_ok_until = null;
        }

        /** Lazily create the table; true when the table exists afterwards. */
        public function ensure_table() {
                global $wpdb;
                if ( null !== $this->table_ok_until && microtime( true ) < $this->table_ok_until ) {
                        return true;
                }
                $t   = $this->table;
                $cs  = $wpdb->get_charset_collate();
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
                if ( $t !== $exists ) {
                        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                        dbDelta(
                                "CREATE TABLE {$t} (
                                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        event_id CHAR(36) NOT NULL,
                                        schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
                                        origin CHAR(36) NOT NULL,
                                        epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                        gen_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                        scope VARCHAR(24) NOT NULL,
                                        payload TEXT NOT NULL,
                                        created BIGINT UNSIGNED NOT NULL,
                                        consumed TINYINT UNSIGNED NOT NULL DEFAULT 0,
                                        PRIMARY KEY  (id),
                                        UNIQUE KEY event_id (event_id),
                                        KEY consumed_idx (consumed, id),
                                        KEY gen_idx (gen_epoch)
                                ) {$cs};"
                        );
                }
                $ok = ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) );
                if ( $ok ) {
                        $this->table_ok_until = microtime( true ) + self::ENSURE_TTL_SEC;
                }
                return $ok;
        }

        /**
         * Publish one event. Local-first design: the caller performs its local
         * purge BEFORE this; a publish failure is counted, never fatal.
         *
         * @param string $scope    purge_dirs|purge_all
         * @param array  $payload  scope payload
         * @param int    $epoch    chain epoch (M5 mirror, unchanged)
         * @param int    $gen_epoch shared purge generation from Epoch::bump()
         *                          (0 only for legacy fallback callers — recorded
         *                          as a schema-2 row with gen 0, treated v1-like)
         * @return string event_id or '' on failure
         */
        public function publish( $scope, array $payload, $epoch = 0, $gen_epoch = 0 ) {
                global $wpdb;
                if ( ! $this->ensure_table() ) {
                        $this->metric( 'failures' ); // M5 §6: write failures are counted, never fatal
                        return '';
                }
                $event_id = \UltimatePerformance\Core\Uuid7::generate();
                // M5-D3: an empty payload must encode as the design-contracted
                // JSON OBJECT '{}' (§3 purge_all), not PHP's '[]' — consumers and
                // future schema versions parse payloads as objects.
                $encoded = empty( $payload ) ? '{}' : (string) wp_json_encode( $payload );
                $ok = false !== $wpdb->insert(
                        $this->table,
                        array(
                                'event_id'       => $event_id,
                                'schema_version' => self::SCHEMA_VERSION,
                                'origin'         => NodeIdentity::id(),
                                'epoch'          => (int) $epoch,
                                'gen_epoch'      => max( 0, (int) $gen_epoch ),
                                'scope'          => (string) $scope,
                                'payload'        => $encoded,
                                'created'        => (int) round( microtime( true ) * 1000 ),
                                'consumed'       => 0,
                        ),
                        array( '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%d' )
                );
                $this->metric( $ok ? 'published' : 'failures' );
                return $ok ? $event_id : '';
        }

        /**
         * Best-effort metric recording (M5 §6). Telemetry must NEVER throw
         * into the purge path: any State failure is swallowed by design.
         *
         * @param string $counter One of State::COUNTERS.
         */
        private function metric( $counter ) {
                try {
                        if ( class_exists( '\UltimatePerformance\Cluster\State' ) ) {
                                ( new State() )->bump( array( (string) $counter => 1 ) );
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }
        }

        /**
         * Fetch pending events from OTHER nodes in strict id order, bounded.
         *
         * @param string $my_node_id
         * @param int    $batch
         * @return array<int,array<string,mixed>>
         */
        public function pending_for( $my_node_id, $batch = self::BATCH_CAP ) {
                global $wpdb;
                if ( ! $this->ensure_table() ) {
                        return array();
                }
                $t = $this->table;
                return (array) $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id, event_id, schema_version, origin, epoch, gen_epoch, scope, payload, created
                                 FROM {$t} WHERE consumed = 0 AND origin <> %s AND id > 0 ORDER BY id ASC LIMIT %d",
                                (string) $my_node_id,
                                max( 1, min( 1000, (int) $batch ) )
                        ),
                        ARRAY_A
                );
        }

        /**
         * Count pending rows with a shared generation strictly greater than
         * the given checkpoint — the end-of-round discriminator that
         * separates a normal backlog (count > 0 → keep draining targeted)
         * from genuinely LOST generations (count 0 with S > checkpoint →
         * reconcile). N1 design §4.5. ALL origins count: a generation whose
         * only surviving row is this node's own publish is accounted
         * differently (the producer claims its own coverage at publish),
         * but a crashed producer must not mask a foreign lost tail either.
         *
         * @param string $my_node_id unused since N1 (kept for call compat)
         * @param int    $checkpoint
         * @return int
         */
        public function count_pending_future( $my_node_id, $checkpoint ) {
                global $wpdb;
                if ( ! $this->ensure_table() ) {
                        return 0;
                }
                $t = $this->table;
                return (int) $wpdb->get_var(
                        $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$t} WHERE consumed = 0 AND gen_epoch > %d",
                                max( 0, (int) $checkpoint )
                        )
                );
        }

        /** Mark one event consumed (idempotent). */
        public function mark_consumed( $id ) {
                global $wpdb;
                return false !== $wpdb->update( $this->table, array( 'consumed' => 1 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
        }

        /**
         * Janitor: prune consumed rows past retention (bounded table). N1:
         * own-origin rows past retention are pruned EVEN when pending — the
         * producer claimed its own coverage at publish (local-first), peers
         * had the whole retention window to consume, and the checkpoint
         * floor covers any latercomer with a reconcile. This keeps the
         * table bounded even for single-node cluster mode where an own row
         * would otherwise stay pending forever.
         *
         * @param string|null $my_node_id when given, own pending rows past
         *                                    retention are pruned too
         * @return int rows deleted
         */
        public function prune( $my_node_id = null ) {
                global $wpdb;
                if ( ! $this->ensure_table() ) {
                        return 0;
                }
                $cutoff = (int) round( microtime( true ) * 1000 ) - self::RETENTION_SEC * 1000;
                $n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE consumed = 1 AND created < %d", $cutoff ) );
                if ( null !== $my_node_id && '' !== (string) $my_node_id ) {
                        $n += (int) $wpdb->query(
                                $wpdb->prepare(
                                        "DELETE FROM {$this->table} WHERE consumed = 0 AND origin = %s AND created < %d",
                                        (string) $my_node_id,
                                        $cutoff
                                )
                        );
                }
                return $n;
        }
}
