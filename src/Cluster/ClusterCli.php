<?php
/**
 * Cluster CLI command surface (Phase N §19).
 *
 * Implements the planned `wp ultimate-performance cluster status|epoch|events|reconcile`
 * surface. Reuses existing services (Epoch, Checkpoint, Propagator, State) —
 * no business logic duplication. Supports bounded JSON output where appropriate.
 *
 * No credentials, raw cache contents, or sensitive paths are emitted.
 *
 * NOTE: this is a PHP-side command class, not a WP-CLI registration. The
 * actual `wp` binary registration is the responsibility of a WP-CLI command
 * file (ultimate-performance-cli.php) that wires this class to the WP-CLI
 * `before WP_CLI::add_command` API. That file is intentionally not
 * committed in this phase — wp-cli is not part of the regression suite.
 *
 * Usage from PHP:
 *   $cli = new ClusterCli( $epoch, $lease, $events, $propagator, $state );
 *   $cli->status();   // prints JSON
 *   $cli->epoch();    // prints JSON
 *   $cli->events();   // prints JSON (bounded to last 50)
 *   $cli->reconcile();  // executes reconcile, prints JSON
 *
 * @package UltimatePerformance\Cluster
 */

namespace UltimatePerformance\Cluster;

defined( 'ABSPATH' ) || exit;

final class ClusterCli {

        /** @var Epoch */
        private $epoch;

        /** @var Lease */
        private $lease;

        /** @var EventStore */
        private $events;

        /** @var Propagator|null */
        private $propagator;

        /** @var State|null */
        private $state;

        /** @var int Max events to display in events() (bounded). */
        const EVENTS_LIMIT = 50;

        public function __construct( Epoch $epoch, Lease $lease, EventStore $events, $propagator = null, $state = null ) {
                $this->epoch      = $epoch;
                $this->lease      = $lease;
                $this->events     = $events;
                $this->propagator = $propagator;
                $this->state      = $state;
        }

        /**
         * Show node ID, epoch, lease state.
         *
         * Output (JSON):
         *   {
         *     "node_id": "01234567-...",
         *     "node_id_short": "01234567",
         *     "epoch": 42,
         *     "checkpoint": 41,
         *     "lease": { "registered": true, "boot_secret_tail": "abc12345" },
         *     "events_pending": 3
         *   }
         */
        public function status() {
                $node_id = NodeIdentity::id();
                $epoch_v = $this->epoch->current();
                $ck      = new Checkpoint();
                $ck_v    = $ck->read();
                $lease_r = $this->read_lease_row( $node_id );
                $pending = $this->count_pending_events();

                $out = array(
                        'node_id'         => $node_id,
                        'node_id_short'   => substr( $node_id, 0, 8 ),
                        'epoch'           => $epoch_v,
                        'checkpoint'      => $ck_v,
                        'lease'           => array(
                                'registered'        => null !== $lease_r,
                                'boot_secret_tail'  => $lease_r ? substr( (string) ( $lease_r['boot_secret'] ?? '' ), -8 ) : null,
                                'last_ms'           => $lease_r ? (int) ( $lease_r['last_ms'] ?? 0 ) : null,
                        ),
                        'events_pending'  => $pending,
                        'epoch_lag'       => max( 0, $epoch_v - $ck_v ),
                );
                return $this->emit_json( $out );
        }

        /**
         * Show / bump epoch.
         *
         * Without --bump: print current epoch value (read-only).
         * With --bump: atomically bump the epoch (forces every consumer to reconcile).
         */
        public function epoch( $bump = false ) {
                if ( $bump ) {
                        $new = $this->epoch->bump();
                        return $this->emit_json( array(
                                'action'    => 'bump',
                                'previous'  => $new - 1,
                                'current'   => $new,
                                'effect'    => 'all consumers will reconcile on next tick',
                        ) );
                }
                $v = $this->epoch->current();
                return $this->emit_json( array(
                        'action'  => 'show',
                        'current' => $v,
                ) );
        }

        /**
         * Show pending events for this node (bounded to EVENTS_LIMIT).
         *
         * Output:
         *   {
         *     "count": 12,
         *     "showing": 12,
         *     "limit": 50,
         *     "events": [ ... ]
         *   }
         *
         * No payload contents are emitted — only metadata (id, gen_epoch, scope,
         * origin, created_ms). Payloads may contain URLs that operators have not
         * consented to expose.
         */
        public function events() {
                $rows = $this->events->pending_for( NodeIdentity::id(), self::EVENTS_LIMIT );
                $out  = array(
                        'count'   => count( $rows ),
                        'showing' => count( $rows ),
                        'limit'   => self::EVENTS_LIMIT,
                        'events'  => array(),
                );
                foreach ( $rows as $r ) {
                        $out['events'][] = array(
                                'id'         => $r->id ?? null,
                                'gen_epoch'  => $r->gen_epoch ?? null,
                                'schema'     => $r->schema_version ?? 1,
                                'origin'     => isset( $r->origin ) ? substr( (string) $r->origin, 0, 8 ) : null,
                                'scope'      => $r->scope ?? null,
                                'created_ms' => $r->created_ms ?? null,
                        );
                }
                return $this->emit_json( $out );
        }

        /**
         * Force a local reconcile.
         *
         * Idempotent, scope-validated, accurately reported. NEVER silently
         * purges every node — only the LOCAL node's cache tree is purged.
         */
        public function reconcile() {
                if ( null === $this->propagator ) {
                        return $this->emit_json( array(
                                'action'  => 'reconcile',
                                'result'  => 'skipped',
                                'reason'  => 'propagator not available (cluster subsystem not initialized)',
                        ) );
                }
                $t0    = microtime( true );
                $count = $this->propagator->reconcile();
                $ms    = (int) ( ( microtime( true ) - $t0 ) * 1000 );
                return $this->emit_json( array(
                        'action'           => 'reconcile',
                        'result'           => 'ok',
                        'scope'            => 'local',
                        'events_processed' => $count,
                        'ms'               => $ms,
                ) );
        }

        // --- helpers --------------------------------------------------------------

        /**
         * Emit JSON. Returns the JSON string for testability.
         *
         * @param array $data
         * @return string
         */
        private function emit_json( $data ) {
                $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
                echo $json . "\n";
                return $json;
        }

        /**
         * Count pending events for the local node (bounded, no payload).
         *
         * @return int
         */
        private function count_pending_events() {
                $rows = $this->events->pending_for( NodeIdentity::id(), 1 );
                return count( $rows );
        }

        /**
         * Read the raw lease row for a node (read-only; never writes).
         *
         * Lease::verify() has a side effect (upsert). We bypass it for the
         * read-only status path — the CLI status command must NOT mutate
         * the lease table.
         *
         * @param string $node_id
         * @return array|null
         */
        private function read_lease_row( $node_id ) {
                global $wpdb;
                if ( ! $this->lease->ensure_table() ) {
                        return null;
                }
                $table = $wpdb->base_prefix . Lease::TABLE_NAME;
                $row   = $wpdb->get_row(
                        $wpdb->prepare(
                                'SELECT boot_secret, last_instance, last_ms FROM ' . $table . ' WHERE node_id = %s',
                                (string) $node_id
                        ),
                        ARRAY_A
                );
                return is_array( $row ) ? $row : null;
        }
}
