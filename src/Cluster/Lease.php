<?php
/**
 * Cluster invalidation — N3. Node identity hardening (design §5):
 * per-node uuid7 + persistent boot_secret + a shared-DB lease with a
 * CONCURRENT-INSTANCE PROBE (docs/PHASE-N-EPOCH-DESIGN.md §5).
 *
 * Threat model: a cloned/restored filesystem (VM snapshot, container
 * image copy, cp -r of the cache root) silently duplicates the node id.
 * Two live processes sharing one id corrupt the per-origin watermark
 * semantics and hide events from each other (origin == my exclusion).
 * Detection must NOT depend on random probability alone:
 *
 *  1. POST-REGISTRATION clone (secret already in the DB when the clone
 *     boots): the lease row's boot_secret differs from the clone's file
 *     secret → regenerate.
 *  2. CONCURRENT clone (both processes carry the same id+secret — the
 *     pre-registration copy case): each process holds an EPHEMERAL
 *     per-boot instance_nonce (memory only). Every verify() upserts
 *     (secret, instance, now). A row showing a DIFFERENT instance with
 *     last_ms inside the freshness window means another LIVE process is
 *     using this id → regenerate.
 *  3. Non-concurrent reuse (node restored while the original is OFF) is
 *     legitimate id reuse — harmless, documented.
 *
 * Bounded rows: the janitor prunes lease rows older than RETENTION_SEC —
 * fleet-sized, never unbounded. Non-cluster deployments never touch this
 * table (no new DB dependency).
 *
 * @package UltimatePerformance\Cluster
 */

namespace UltimatePerformance\Cluster;

defined( 'ABSPATH' ) || exit;

final class Lease {

	const TABLE_NAME   = 'up_cluster_nodes';
	const RETENTION_SEC = 2592000; // 30 days — lease rows older than this are pruned
	/** Freshness window (sec) for the concurrent-instance probe. */
	const FRESH_WINDOW_SEC = 10;

	/** @var string */
	private $table;

	/** @var bool|null per-instance positive table proof (same discipline as Epoch) */
	private $table_ok_until;

	public function __construct() {
		global $wpdb;
		$this->table          = $wpdb->base_prefix . self::TABLE_NAME;
		$this->table_ok_until = null;
	}

	/** Verify (and upsert) this node's lease. Returns true when the identity is CLEAN. */
	public function verify( $node_id, $boot_secret, $instance_nonce, $now_ms ) {
		global $wpdb;
		if ( ! $this->ensure_table() ) {
			return true; // no lease table → no cluster probe possible; identity unchanged
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT boot_secret, last_instance, last_ms FROM ' . $this->table . ' WHERE node_id = %s',
				(string) $node_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			// First registration for this id.
			$this->upsert( $node_id, $boot_secret, $instance_nonce, $now_ms );
			return true;
		}
		if ( (string) ( $row['boot_secret'] ?? '' ) !== (string) $boot_secret ) {
			return false; // CASE 1: post-registration clone detected
		}
		$last_ms = (int) ( $row['last_ms'] ?? 0 );
		if ( (string) ( $row['last_instance'] ?? '' ) !== (string) $instance_nonce
			&& $now_ms - $last_ms < self::FRESH_WINDOW_SEC * 1000
			&& $last_ms > 0 ) {
			return false; // CASE 2: concurrent live clone detected
		}
		$this->upsert( $node_id, $boot_secret, $instance_nonce, $now_ms );
		return true;
	}

	/** Upsert the lease (UPDATE first, INSERT on zero rows; race-safe retry). */
	private function upsert( $node_id, $boot_secret, $instance_nonce, $now_ms ) {
		global $wpdb;
		$upd = $wpdb->update(
			$this->table,
			array(
				'boot_secret'   => (string) $boot_secret,
				'last_instance' => (string) $instance_nonce,
				'last_ms'       => (int) $now_ms,
			),
			array( 'node_id' => (string) $node_id ),
			array( '%s', '%s', '%d' ),
			array( '%s' )
		);
		if ( 0 !== (int) $upd ) {
			return;
		}
		$ins = $wpdb->insert(
			$this->table,
			array(
				'node_id'       => (string) $node_id,
				'boot_secret'   => (string) $boot_secret,
				'last_instance' => (string) $instance_nonce,
				'first_ms'      => (int) $now_ms,
				'last_ms'       => (int) $now_ms,
			),
			array( '%s', '%s', '%s', '%d', '%d' )
		);
		if ( false === $ins ) {
			// Concurrent first-registration lost the PK race — retry the update.
			$wpdb->update(
				$this->table,
				array(
					'boot_secret'   => (string) $boot_secret,
					'last_instance' => (string) $instance_nonce,
					'last_ms'       => (int) $now_ms,
				),
				array( 'node_id' => (string) $node_id ),
				array( '%s', '%s', '%d' ),
				array( '%s' )
			);
		}
	}

	/** Janitor: prune lease rows not refreshed within retention. */
	public function prune( $now_ms ) {
		global $wpdb;
		if ( ! $this->ensure_table() ) {
			return 0;
		}
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $this->table . ' WHERE last_ms < %d',
				(int) $now_ms - self::RETENTION_SEC * 1000
			)
		);
	}

	/** Lazily create the lease table; true when it exists afterwards. */
	public function ensure_table() {
		global $wpdb;
		if ( null !== $this->table_ok_until && microtime( true ) < $this->table_ok_until ) {
			return true;
		}
		$t      = $this->table;
		$cs     = $wpdb->get_charset_collate();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		if ( $t !== $exists ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta(
				"CREATE TABLE {$t} (
					node_id CHAR(36) NOT NULL,
					boot_secret CHAR(32) NOT NULL,
					last_instance CHAR(32) NOT NULL DEFAULT '',
					first_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
					last_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
					PRIMARY KEY  (node_id)
				) {$cs};"
			);
		}
		$ok = ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) );
		if ( $ok ) {
			$this->table_ok_until = microtime( true ) + 60;
		}
		return $ok;
	}
}
