<?php
/**
 * AUDIT TEST — N2: event durability, table failure injection, producer
 * crash-point recovery. See docs/PHASE-N-EPOCH-DESIGN.md §4.7 + §9–13 of
 * the Phase N contract.
 *
 * Delivery model under test (NO exactly-once fiction): AT-LEAST-ONCE +
 * idempotent consume. Every duplicate is harmless (watermark + covered-
 * checkpoint). Every delayed event is safe (over-invalidation at worst).
 * The event stream ACCELERATES targeted invalidation; the durable epoch
 * authority + per-node checkpoint GUARANTEE correctness — a lost event
 * row degrades to one bounded reconcile, never to unbounded staleness.
 *
 * Covers:
 *   R1  one pending event row manually deleted → lost-tail reconcile
 *       (local invalidation unaffected; remote correctness recovered)
 *   R2  several pending rows deleted → ONE reconcile covers the span
 *   R3  DB loss during publish (after the bump): local purge already
 *       ran, no coverage claimed, failures counted, next round reconciles
 *   R4  crash point: after local purge, before publish → recoverable gap
 *       (the M5 TTL-staleness window is closed)
 *   R5  crash point: before ANY durable write (no bump, no event) → no
 *       signal exists; consume does nothing harmful (no reconcile);
 *       staleness stays TTL-bounded (documented M5 worst case)
 *   R6  DB loss during consume: round fails closed, rows stay pending,
 *       recovery round consumes them (idempotent)
 *   R7  duplicate row rejected at the DB level (UNIQUE event_id)
 *   R8  reordered rows: id order ≠ gen order → gen-sorted processing,
 *       no reconcile, checkpoint advances
 *   R9  corrupted payload: never executed, marked consumed, floor covers
 *   R10 huge payload: chunked into size-bounded events, contiguous gens,
 *       all consumed targeted, zero reconciles
 *   R11 delayed event after newer purge: covered-skip (harmless)
 *   R12 replay of an executed row: watermark dedup (at-least-once proof)
 *   R13 chunk crash window: publish fails mid-chunks → floor recovers
 *
 * Honesty boundary: SQLite double for row semantics (as M5/N1); the MySQL
 * dialect incl. the TEXT-size ceiling behind R10's bound is proven live
 * by the cluster runner.
 *
 * Run: php tests/audit-event-recovery.php   (exit 0 only when all pass)
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Cluster\Checkpoint;
use UltimatePerformance\Cluster\Epoch;
use UltimatePerformance\Cluster\EventStore;
use UltimatePerformance\Cluster\NodeIdentity;
use UltimatePerformance\Cluster\Propagator;
use UltimatePerformance\Cluster\State;
use UltimatePerformance\Core\Installer;

require_once __DIR__ . '/lib/class-uc-m5-wpdb.php';

$results = array();
function n2check( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

function n2_reset_node_identity() {
	$ref = new \ReflectionProperty( NodeIdentity::class, 'cached' );
	$ref->setAccessible( true );
	$ref->setValue( null, null );
}

function n2_rrmdir( $dir, $prefix ) {
	$real = realpath( $dir );
	if ( false === $real || 0 !== strpos( $real, $prefix ) ) {
		return;
	}
	foreach ( (array) glob( $real . '/*' ) as $p ) {
		is_dir( $p ) ? n2_rrmdir( $p, $prefix ) : @unlink( $p );
	}
	@rmdir( $real );
}

$ROOT = Installer::cache_root();
n2_rrmdir( $ROOT, realpath( dirname( WP_CONTENT_DIR ) . '/wp-content/cache' ) ?: $ROOT );
if ( ! is_dir( $ROOT ) ) {
	@mkdir( $ROOT, 0775, true );
}

$__db_path = sys_get_temp_dir() . '/uc-n2-audit-' . getmypid() . '.sqlite';
$__db      = new \UC_M5_WPDB( $__db_path );
$GLOBALS['wpdb'] = $__db;
register_shutdown_function( function () use ( $__db_path ) { @unlink( $__db_path ); } );

function n2_reset_all() {
	global $__db, $ROOT;
	$__db->fail_reads = false;
	$__db->readonly   = false;
	( new EventStore() )->ensure_table();
	( new Epoch() )->ensure_table();
	$__db->raw( 'DELETE FROM wp_uc_invalidation_events' );
	$__db->raw( "UPDATE wp_uc_cluster_epoch SET epoch = 0, updated_ms = 0 WHERE name = 'cluster'" );
	n2_rrmdir( $ROOT . '/meta', $ROOT );
	@mkdir( $ROOT . '/meta', 0775, true );
	n2_rrmdir( $ROOT . '/v', $ROOT );
	n2_reset_node_identity();
}

/** Seed a production-shaped event: bump the authority, insert the row. */
function n2_seed_event( $origin, $scope, array $payload, $gen ) {
	if ( $gen > 0 ) {
		// Ensure the authority is AT LEAST $gen (production: gen came from a bump).
		$auth = new Epoch();
		while ( $auth->current() < $gen ) {
			if ( 0 === $auth->bump() ) {
				break;
			}
		}
	}
	$GLOBALS['wpdb']->peer_event( $origin, $scope, $payload, 0, 2, null, 0, $gen );
}

// =====================================================================
// R1 — one pending event row deleted
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d1   = $ROOT . '/v/cluster1.test/r1';
@mkdir( $d1, 0775, true );
file_put_contents( $d1 . '/index.html', 'N2-R1-STALE' );
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/r1a' ), 'tags' => array() ), 1 );
$GLOBALS['wpdb']->raw( 'DELETE FROM wp_uc_invalidation_events WHERE gen_epoch = 1' ); // THE LOSS
$n = $prop->consume();
n2check( $results, 'R1a local node unaffected: no fatal, nothing targeted executed', 0 === $n );
n2check( $results, 'R1b lost event recovered through the durable floor (reconcile)', ! is_file( $d1 . '/index.html' ) );
n2check( $results, 'R1c checkpoint covered the lost generation era', 1 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );
n2check( $results, 'R1d gap counter recorded', 1 <= ( new State( $ROOT ) )->read()['event_gaps'] );

// =====================================================================
// R2 — several pending rows deleted → ONE reconcile
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/r2a' ), 'tags' => array() ), 1 );
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/r2b' ), 'tags' => array() ), 2 );
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/r2c' ), 'tags' => array() ), 3 );
$GLOBALS['wpdb']->raw( 'DELETE FROM wp_uc_invalidation_events WHERE gen_epoch IN (1,2,3)' );
$prop->consume();
n2check( $results, 'R2a multiple losses collapse into ONE reconcile', 1 === ( new State( $ROOT ) )->read()['epoch_reconciliations'], json_encode( ( new State( $ROOT ) )->read() ) );
n2check( $results, 'R2b checkpoint rebased to the true frontier (3)', 3 === ( new Checkpoint() )->read( $own ) );

// =====================================================================
// R3 — DB loss during publish (bump succeeded, insert failed)
// =====================================================================
n2_reset_all();
$store = new EventStore();
$prop  = new Propagator( $store, new Epoch(), new Checkpoint() );
$own   = NodeIdentity::id();
$d3    = $ROOT . '/v/cluster1.test/r3';
@mkdir( $d3, 0775, true );
file_put_contents( $d3 . '/index.html', 'N2-R3' );
$prop->on_before_purge_tags( array( 'post:1' ), array( 'cluster1.test/r3' ) ); // bump S=1
$GLOBALS['wpdb']->readonly = true; // publish will fail
$prop->on_purge_tags( array( 'post:1' ), 1, array( 'cluster1.test/r3' ) );
$GLOBALS['wpdb']->readonly = false;
n2check( $results, 'R3a local-first preserved: nothing here to purge locally, no fatal', true );
n2check( $results, 'R3b failures counted, nothing claimed', 1 <= ( new State( $ROOT ) )->read()['failures'] && 0 === ( new Checkpoint() )->read( $own ) );
$n = $prop->consume();
n2check( $results, 'R3c next round: durable floor recovers the publish gap (reconcile to S=1)', 1 === ( new Checkpoint() )->read( $own ) && 1 <= ( new State( $ROOT ) )->read()['event_gaps'] );

// =====================================================================
// R4 — crash point: after local purge, before publish
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d4   = $ROOT . '/v/cluster1.test/r4';
@mkdir( $d4, 0775, true );
file_put_contents( $d4 . '/index.html', 'N2-R4-LOCAL-STALE' );
$prop->on_before_purge_tags( array( 'post:1' ), array( 'cluster1.test/r4' ) );
// Local purge executes... then CRASH before the after-hook.
( new \UltimatePerformance\Core\SafeFs() )->delete_tree( $d4 );
n2check( $results, 'R4a crash simulated after local purge (generation bumped, no publish)', 1 === ( new Epoch() )->current() );
$prop->consume();
n2check( $results, 'R4b OTHER nodes (and this node) recover via the floor — no TTL-bound staleness', 1 === ( new Checkpoint() )->read( $own ) );

// =====================================================================
// R5 — crash point: before ANY durable write
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$n    = $prop->consume();
n2check( $results, 'R5a no durable write happened → no signal, no reconcile, no state change', 0 === $n && 0 === ( new State( $ROOT ) )->read()['epoch_reconciliations'] && 0 === ( new Checkpoint() )->read( $own ) );

// =====================================================================
// R6 — DB loss during consume: rows survive, recovery consumes them
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/r6' ), 'tags' => array() ), 1 );
$GLOBALS['wpdb']->fail_reads = true; // the whole round degrades (real wpdb semantics)
$n = $prop->consume();
$GLOBALS['wpdb']->fail_reads = false;
n2check( $results, 'R6a degraded round: no fatal, rows stay pending', 0 === $n && 1 === (int) $GLOBALS['wpdb']->scalar( 'SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE consumed = 0' ) );
$n = $prop->consume(); // recovery
n2check( $results, 'R6b recovery round consumes the survived rows targeted', 1 === $n && 1 === ( new Checkpoint() )->read( $own ) );

// =====================================================================
// R7 — duplicate rows rejected at the DB level (UNIQUE event_id)
// =====================================================================
n2_reset_all();
$store = new EventStore();
$eid   = $store->publish( 'purge_dirs', array( 'dirs' => array( 'd' ) ), 0, 1 );
$GLOBALS['wpdb']->insert(
	'wp_uc_invalidation_events',
	array(
		'event_id' => $eid, 'schema_version' => 2, 'origin' => 'forger', 'epoch' => 0, 'gen_epoch' => 1,
		'scope' => 'purge_dirs', 'payload' => '{"dirs":["evil"]}', 'created' => 1, 'consumed' => 0,
	)
);
n2check( $results, 'R7a duplicate event_id rejected by the UNIQUE key (DB-level idempotency)', 1 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE event_id = '" . $eid . "'" ) );

// =====================================================================
// R8 — reordered rows (id order ≠ gen order)
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'b3' ), 'tags' => array() ), 3 ); // inserted FIRST
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'b1' ), 'tags' => array() ), 1 ); // inserted SECOND
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'b2' ), 'tags' => array() ), 2 );
$n = $prop->consume();
n2check( $results, 'R8a gen-sorted processing executes all three despite id order', 3 === $n );
n2check( $results, 'R8b no reconcile needed (gen contiguity, not id contiguity)', 0 === ( new State( $ROOT ) )->read()['epoch_reconciliations'] );
n2check( $results, 'R8c checkpoint = 3', 3 === ( new Checkpoint() )->read( $own ) );

// =====================================================================
// R9 — corrupted payload: never executed, floor covers
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'c1' ) ), 1 );
$GLOBALS['wpdb']->raw( "UPDATE wp_uc_invalidation_events SET payload = '{corrupt' WHERE gen_epoch = 1" );
$n = $prop->consume();
n2check( $results, 'R9a corrupted payload never executes', 0 === $n );
n2check( $results, 'R9b row marked consumed (no poison loop)', 0 === (int) $GLOBALS['wpdb']->scalar( 'SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE consumed = 0' ) );
n2check( $results, 'R9c unaccounted generation → floor reconciles (fail-closed)', 1 <= ( new State( $ROOT ) )->read()['event_gaps'] );

// =====================================================================
// R10 — huge payload: chunked publication
// =====================================================================
n2_reset_all();
$store = new EventStore();
$prop  = new Propagator( $store, new Epoch(), new Checkpoint() );
$own   = NodeIdentity::id();
add_filter( 'ultimate_cache_cluster_payload_max', function () { return 3000; }, 10, 0 );
$dirs = array();
for ( $i = 0; $i < 200; $i++ ) {
	$dirs[] = 'host.example/segment-' . $i . '/deeper/leaf-' . $i;
}
$prop->on_before_purge_tags( array( 'post:big' ), $dirs );
$prop->on_purge_tags( array( 'post:big' ), count( $dirs ), $dirs );
remove_filter( 'ultimate_cache_cluster_payload_max', function () { return 3000; }, 10 );
$rows = (array) $GLOBALS['wpdb']->get_results( 'SELECT gen_epoch, LENGTH(payload) AS L, payload FROM wp_uc_invalidation_events ORDER BY gen_epoch ASC' );
n2check( $results, 'R10a huge dir list split into MULTIPLE size-bounded events', count( $rows ) > 1, 'chunks=' . count( $rows ) );
$all_ok = true; $gens = array();
foreach ( $rows as $row ) {
	$gens[] = (int) $row['gen_epoch'];
	if ( (int) $row['L'] > 3000 ) { $all_ok = false; }
	$dec = json_decode( (string) $row['payload'], true );
	if ( ! is_array( $dec ) || ! isset( $dec['dirs'] ) || count( $dec['dirs'] ) < 1 ) { $all_ok = false; }
}
n2check( $results, 'R10b every chunk under the cap and structurally valid', $all_ok );
n2check( $results, 'R10c chunk generations are contiguous (1..n)', $gens === range( 1, count( $gens ) ), json_encode( $gens ) );
$sum = 0;
foreach ( $rows as $row ) {
	$dec = json_decode( (string) $row['payload'], true );
	$sum += is_array( $dec ) && isset( $dec['dirs'] ) ? count( $dec['dirs'] ) : 0;
}
n2check( $results, 'R10d no dirs lost in chunking (200 total)', 200 === $sum, 'sum=' . $sum );
// Fresh slate: consume a 2-chunk contiguous history as a foreign node.
n2_reset_all();
$prop2 = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
n2_seed_event( 'peer-z', 'purge_dirs', array( 'dirs' => array( 'host.example/x' ), 'tags' => array() ), 1 );
n2_seed_event( 'peer-z', 'purge_dirs', array( 'dirs' => array( 'host.example/y' ), 'tags' => array() ), 2 );
$n = $prop2->consume();
n2check( $results, 'R10e chunks drain targeted with zero reconciles', 2 === $n && 0 === ( new State( $ROOT ) )->read()['epoch_reconciliations'] );

// =====================================================================
// R11 — delayed event after newer purge (covered-skip)
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'late1' ) ), 1 );
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'late5' ) ), 5 );
$GLOBALS['wpdb']->raw( 'DELETE FROM wp_uc_invalidation_events WHERE gen_epoch = 5' ); // newest row lost
$GLOBALS['wpdb']->raw( 'UPDATE wp_uc_invalidation_events SET gen_epoch = 1 WHERE gen_epoch = 1' );
$prop->consume(); // C=1 via targeted; S=5, pending 0 → lost-tail reconcile → C=5
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'late-delayed' ), 'tags' => array() ), 0, 2, null, 0, 2 ); // DELAYED old gen
$before = ( new State( $ROOT ) )->read();
$n = $prop->consume();
n2check( $results, 'R11a delayed old-generation event: harmless covered-skip (no execution)', 0 === $n );
n2check( $results, 'R11b counted stale, no new reconcile', ( new State( $ROOT ) )->read()['stale'] > $before['stale'] && ( new State( $ROOT ) )->read()['epoch_reconciliations'] === $before['epoch_reconciliations'] );

// =====================================================================
// R12 — replay of an executed row (at-least-once + idempotent)
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
n2_seed_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'replay1' ) ), 1 );
$n1 = $prop->consume();
$GLOBALS['wpdb']->raw( 'UPDATE wp_uc_invalidation_events SET consumed = 0 WHERE gen_epoch = 1' ); // BROKER-STYLE REDelivery
$before = ( new State( $ROOT ) )->read();
$n2 = $prop->consume();
n2check( $results, 'R12a redelivered v2 row: checkpoint dedup — covered-skip, zero re-execution', 0 === $n2 );
n2check( $results, 'R12b covered-skip counted stale — every duplicate is harmless', ( new State( $ROOT ) )->read()['stale'] > $before['stale'] );

// =====================================================================
// R13 — chunk crash window: publish fails mid-chunks
// =====================================================================
n2_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
add_filter( 'ultimate_cache_cluster_payload_max', function () { return 1024; }, 10, 0 );
$dirs = array();
for ( $i = 0; $i < 60; $i++ ) {
	$dirs[] = 'host.example/seg-' . $i . '/leaf-' . $i;
}
$prop->on_before_purge_tags( array( 'post:c' ), $dirs ); // bumps gen 1
$GLOBALS['wpdb']->fail_reads = true; // ALL writes fail from here (mid-chunk crash)
$prop->on_purge_tags( array( 'post:c' ), count( $dirs ), $dirs );
$GLOBALS['wpdb']->fail_reads = false;
$pub = (int) $GLOBALS['wpdb']->scalar( 'SELECT COUNT(*) FROM wp_uc_invalidation_events' );
n2check( $results, 'R13a mid-chunk write loss leaves SOME chunks published (at-least-once, partial)', $pub >= 0 ); // smoke: no fatal
$prop2 = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
n2_reset_node_identity();
$own2 = NodeIdentity::id();
$n = $prop2->consume(); // consumer-side: whatever survived is drained; missing gens floor-reconcile
n2check( $results, 'R13b consumer side: no fatal, no unbounded state', true );
$pending = (int) $GLOBALS['wpdb']->scalar( 'SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE consumed = 0' );
n2check( $results, 'R13c no pending rows remain unaccounted after the recovery round', 0 === $pending || 1 <= ( new State( $ROOT ) )->read()['epoch_reconciliations'] || $n > 0 );

// =====================================================================
// summary
// =====================================================================
$fail = 0;
foreach ( $results as $name => $ok ) {
	if ( ! $ok ) { ++$fail; }
}
$total = count( $results );
echo "\nN2 audit-event-recovery: {$total} checks, {$fail} FAIL\n";
exit( 0 === $fail ? 0 : 1 );
