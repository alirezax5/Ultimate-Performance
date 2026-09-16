<?php
/**
 * AUDIT TEST — N1: durable epoch authority, checkpoint reconciliation,
 * event-loss recovery (unit-level). See docs/PHASE-N-EPOCH-DESIGN.md.
 *
 * Covers:
 *   E1  authority basics: lazy table, current()=0 fresh, bump monotonic
 *       unique values, available() probe, ensure idempotent
 *   E2  two independent Epoch instances (simulated concurrent producers)
 *       each receive their OWN unique generation (connection-scoped idiom)
 *   E3  runtime-only vacuity closure: chain epoch 0 (no persistent
 *       backends) — a foreign gen-1 event still executes AND the
 *       checkpoint advances (the Phase M `0 < 0` guard is replaced by a
 *       durable floor that does not depend on backend persistence)
 *   E4  contiguous v2 chain (gen 1..3) drains targeted in one round,
 *       checkpoint = 3, ZERO reconciles (burst contract intact)
 *   E5  gap detection: gen 1,3 with 2 lost → mid-batch reconcile, stale
 *       page wiped, checkpoint claims gen-1..2 coverage, counters recorded
 *   E6  lost-tail: S advanced with NO surviving rows → end-of-round
 *       reconcile + checkpoint = S
 *   E7  covered-skip: gen ≤ checkpoint → stale++, never re-executed
 *   E8  backlog discriminator: pending rows exist for (C, S] → NO
 *       reconcile (targeted drain continues across ticks)
 *   E9  authority outage (wpdb degrades, never throws): no checkpoint
 *       advance beyond proven, reconcile up to last OBSERVED frontier,
 *       authority_failures counted; recovery after outage re-probes
 *   E10 authority reset/regression (S < C): forced reconcile + rebaseline
 *   E11 producer boundary: before-hook bumps BEFORE local purge; the
 *       after-hook publishes with the REMEMBERED generation (row's
 *       gen_epoch == bumped value; exactly one bump per operation)
 *   E12 crash window closure: bump-without-publish (generation exists,
 *       no event row) → next consumer round reconciles (the M5
 *       "crash after local purge before publish" TTL window is gone)
 *   E13 claim-at-publish: the producer's checkpoint equals its own
 *       generation → its OWN tick does NOT fake a lost-tail reconcile
 *   E14 publish failure → NO coverage claimed (fail-closed)
 *   E15 v1 compatibility: schema-1 rows (Phase M) execute targeted;
 *       schema-2/gen-0 (legacy fallback) executes; future schema-3 rows
 *       never execute and their generations floor-reconcile
 *   E16 checkpoint file: node-bound (foreign node reads 0), corrupt
 *       reads 0, atomic write shape
 *   E17 manual reconcile: idempotent, wipes own tree, checkpoint = S
 *   E18 janitor: consumed rows past retention pruned; own pending rows
 *       past retention pruned (claimed); FOREIGN pending rows NEVER
 *   E19 watermark-reset counter (harmless replay disclosure)
 *   E20 State schema lock: exactly the 10 fixed counters; unknown keys
 *       dropped; telemetry SCHEMA 3 carries all of them
 *
 * Honesty boundary: the EventStore/Epoch SQL here runs on real SQLite
 * through the real classes; the MySQL DIALECT (LAST_INSERT_ID bump,
 * dbDelta DDL) is proven LIVE on real MariaDB by tests/run-cluster-live.sh
 * — this suite does NOT substitute for the live gate.
 *
 * Run: php tests/audit-cluster-epoch.php   (exit 0 only when all pass)
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
use UltimatePerformance\Core\Telemetry;

require_once __DIR__ . '/lib/class-uc-m5-wpdb.php'; // global-namespace test double

$results = array();
function n1check( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

/** Reset NodeIdentity's per-process static (simulates a fresh process). */
function n1_reset_node_identity() {
        $ref = new \ReflectionProperty( NodeIdentity::class, 'cached' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );
}

/** Recursive delete confined to an explicit path prefix. */
function n1_rrmdir( $dir, $prefix ) {
        $real = realpath( $dir );
        if ( false === $real || 0 !== strpos( $real, $prefix ) ) {
                return;
        }
        foreach ( (array) glob( $real . '/*' ) as $p ) {
                is_dir( $p ) ? n1_rrmdir( $p, $prefix ) : @unlink( $p );
        }
        @rmdir( $real );
}

const N1_UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

$ROOT = Installer::cache_root();
n1_rrmdir( $ROOT, realpath( dirname( WP_CONTENT_DIR ) . '/wp-content/cache' ) ?: $ROOT );
if ( ! is_dir( $ROOT ) ) {
        @mkdir( $ROOT, 0775, true );
}

// Swap in the SQLite-backed $wpdb double.
$__db_path = sys_get_temp_dir() . '/uc-n1-audit-' . getmypid() . '.sqlite';
$__db      = new \UC_M5_WPDB( $__db_path );
$GLOBALS['wpdb'] = $__db;
register_shutdown_function( function () use ( $__db_path ) { @unlink( $__db_path ); } );

/** Wipe ALL durable cluster state (DB rows + authority + local files) between scenarios. */
function n1_reset_all() {
        global $__db, $ROOT;
        $__db->fail_reads = false;
        $__db->readonly   = false;
        // Ensure both tables exist before wiping (first scenario: nothing yet).
        ( new EventStore() )->ensure_table();
        ( new Epoch() )->ensure_table();
        $__db->raw( 'DELETE FROM wp_uc_invalidation_events' );
        $__db->raw( "UPDATE wp_uc_cluster_epoch SET epoch = 0, updated_ms = 0 WHERE name = 'cluster'" );
        n1_rrmdir( $ROOT . '/meta', $ROOT );
        @mkdir( $ROOT . '/meta', 0775, true );
        n1_rrmdir( $ROOT . '/v', $ROOT );
        n1_reset_node_identity();
}

$own = null; // this node's id, refreshed per scenario

// =====================================================================
// E1 — authority basics
// =====================================================================
n1_reset_all();
$auth = new Epoch();
n1check( $results, 'E1a ensure_table creates the single-row authority (idempotent)', true === $auth->ensure_table() && true === $auth->ensure_table() );
n1check( $results, 'E1b current() = 0 on a fresh authority', 0 === $auth->current() );
n1check( $results, 'E1c available() true after ensure', true === $auth->available() );
n1check( $results, 'E1d bump() returns 1 (first generation)', 1 === $auth->bump() );
n1check( $results, 'E1e bumps are strictly monotonic (2, 3)', 2 === $auth->bump() && 3 === $auth->bump() );
n1check( $results, 'E1f current() observes 3', 3 === $auth->current() );

// =====================================================================
// E2 — simulated concurrent producers get unique generations
// =====================================================================
n1_reset_all();
$a1 = new Epoch();
$a2 = new Epoch();
$g1 = $a1->bump();
$g2 = $a2->bump();
$g3 = $a1->bump();
n1check( $results, 'E2a concurrent-instance bumps receive unique values', 1 === $g1 && 2 === $g2 && 3 === $g3, "g=$g1,$g2,$g3" );
n1check( $results, 'E2b both instances observe the same frontier', 3 === $a1->current() && 3 === $a2->current() );

// =====================================================================
// E3 — runtime-only vacuity closure (the Phase M 0 < 0 hole)
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d3   = $ROOT . '/v/cluster1.test/e3';
@mkdir( $d3, 0775, true );
file_put_contents( $d3 . '/index.html', 'N1-E3-STALE' );
( new Epoch() )->bump(); // production order: the generation EXISTS before its event
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/e3' ), 'tags' => array() ), 0, 2, null, 0, 1 );
$n = $prop->consume();
n1check( $results, 'E3a runtime-only node (chain epoch 0) EXECUTES the gen-1 event', 1 === $n && ! is_file( $d3 . '/index.html' ), 'n=' . $n );
n1check( $results, 'E3b checkpoint advanced to 1 WITHOUT any persistent backend', 1 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );
n1check( $results, 'E3c no reconcile was needed (targeted coverage)', 0 === ( new State( $ROOT ) )->read()['epoch_reconciliations'] );

// =====================================================================
// E4 — contiguous chain drains targeted, no reconcile
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
for ( $g = 1; $g <= 3; $g++ ) {
        ( new Epoch() )->bump(); // gen g
        $GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( "cluster1.test/e4g$g" ), 'tags' => array() ), 0, 2, null, 0, $g );
}
$st_before = ( new State( $ROOT ) )->read();
$n = $prop->consume();
n1check( $results, 'E4a all three contiguous events executed in one round', 3 === $n, 'n=' . $n );
n1check( $results, 'E4b checkpoint = 3 (proven coverage)', 3 === ( new Checkpoint() )->read( $own ) );
n1check( $results, 'E4c ZERO reconciles for a contiguous backlog', ( new State( $ROOT ) )->read()['epoch_reconciliations'] === $st_before['epoch_reconciliations'] );

// =====================================================================
// E5 — gap detection (gen 2 lost mid-chain)
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d5   = $ROOT . '/v/cluster1.test/e5';
@mkdir( $d5, 0775, true );
file_put_contents( $d5 . '/index.html', 'N1-E5-STALE-FROM-LOST-GEN' );
$__auth = new Epoch();
for ( $g = 1; $g <= 3; $g++ ) { $__auth->bump(); } // gens 1..3; gen 2's ROW is lost
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/e5a' ), 'tags' => array() ), 0, 2, null, 0, 1 );
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/e5c' ), 'tags' => array() ), 0, 2, null, 0, 3 ); // gen 2 NEVER published (lost row)
$n = $prop->consume();
n1check( $results, 'E5a gap detected: reconcile fired (both events marked consumed)', 0 === (int) $GLOBALS['wpdb']->scalar( 'SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE consumed = 0' ) );
n1check( $results, 'E5b the stale page from the LOST generation era was wiped by the reconcile', ! is_file( $d5 . '/index.html' ) );
n1check( $results, 'E5c counters: exactly one gap + one reconciliation', 1 === ( new State( $ROOT ) )->read()['event_gaps'] && 1 === ( new State( $ROOT ) )->read()['epoch_reconciliations'], json_encode( ( new State( $ROOT ) )->read() ) );
n1check( $results, 'E5d checkpoint claims coverage up to gen 3', 3 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );

// =====================================================================
// E6 — lost tail: S advanced, no surviving rows
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d6   = $ROOT . '/v/cluster1.test/e6';
@mkdir( $d6, 0775, true );
file_put_contents( $d6 . '/index.html', 'N1-E6-STALE' );
$auth = new Epoch();
$auth->bump(); $auth->bump(); // two generations happened; their events are GONE
$n = $prop->consume();
n1check( $results, 'E6a lost-tail reconcile fired and wiped the stale tree', ! is_file( $d6 . '/index.html' ) );
n1check( $results, 'E6b checkpoint rebased to S=2', 2 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );
n1check( $results, 'E6c gap counter recorded', 1 <= ( new State( $ROOT ) )->read()['event_gaps'] );

// =====================================================================
// E7 — covered-skip (gen ≤ checkpoint never re-executes)
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
( new Epoch() )->bump(); ( new Epoch() )->bump(); ( new Epoch() )->bump(); ( new Epoch() )->bump(); ( new Epoch() )->bump(); // S=5
( new Checkpoint() )->write( 5, $own );
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/e7' ), 'tags' => array() ), 0, 2, null, 0, 3 );
$n = $prop->consume();
n1check( $results, 'E7a covered event skipped (return 0)', 0 === $n, 'n=' . $n );
n1check( $results, 'E7b counted stale, never executed, marked consumed', 1 <= ( new State( $ROOT ) )->read()['stale'] && 0 === (int) $GLOBALS['wpdb']->scalar( 'SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE consumed = 0' ) );
n1check( $results, 'E7c no reconcile for a covered generation', 0 === ( new State( $ROOT ) )->read()['epoch_reconciliations'] );

// =====================================================================
// E8 — backlog discriminator: pending rows for (C, S] → NO reconcile
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d8   = $ROOT . '/v/cluster1.test/e8keep';
@mkdir( $d8, 0775, true );
file_put_contents( $d8 . '/index.html', 'N1-E8-UNRELATED-PAGE' );
for ( $g = 1; $g <= 5; $g++ ) {
        ( new Epoch() )->bump();
        $GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( "cluster1.test/e8g$g" ), 'tags' => array() ), 0, 2, null, 0, $g );
}
$GLOBALS['wpdb']->fail_reads = true;  // simulate the S read failing is NOT needed here;
$GLOBALS['wpdb']->fail_reads = false; // the point below is the batch cap
$n1 = $prop->consume(); // batch cap 200 → all 5 targeted, S == 5, no gap
n1check( $results, 'E8a backlog drains targeted with zero reconciles', 5 === $n1 && 0 === ( new State( $ROOT ) )->read()['epoch_reconciliations'] );
n1check( $results, 'E8b unrelated page untouched (no full purge happened)', is_file( $d8 . '/index.html' ) );

// =====================================================================
// E9 — authority outage (wpdb degrades like real wpdb, never throws)
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d9   = $ROOT . '/v/cluster1.test/e9';
@mkdir( $d9, 0775, true );
file_put_contents( $d9 . '/index.html', 'N1-E9-STALE' );
( new Epoch() )->bump(); // S=1; gen 1's event below
// Build history: observe S=2, claim coverage of gen 1 only (gap of gen 2 outstanding).
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/e9a' ), 'tags' => array() ), 0, 2, null, 0, 1 );
$prop->consume(); // round 1: C=1, observed=1, S=1 (stable)
$auth = new Epoch();
$auth->bump(); // gen 2 happens; its event row is LOST; NOBODY has observed 2 yet
// Observed frontier is still 1 (coverage 1).
$before9 = ( new Checkpoint() )->read( $own );
$GLOBALS['wpdb']->fail_reads = true; // THE OUTAGE
$n = $prop->consume();
$GLOBALS['wpdb']->fail_reads = false;
n1check( $results, 'E9a outage round: no fatal, NO coverage fabricated beyond the observed frontier', 0 === $n && $before9 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );
n1check( $results, 'E9b no unprovable reconcile during the outage (fail-closed)', ( new State( $ROOT ) )->read()['epoch_reconciliations'] === 0, json_encode( ( new State( $ROOT ) )->read() ) );
$n2 = $prop->consume(); // recovery: re-probes (instance cache must not be poisoned)
n1check( $results, 'E9c recovery: lost-tail reconcile wiped the stale page from the lost generation', ! is_file( $d9 . '/index.html' ) );
n1check( $results, 'E9d checkpoint now covers the recovered frontier (2)', 2 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );

// =====================================================================
// E10 — authority reset/regression (S < C)
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d10  = $ROOT . '/v/cluster1.test/e10';
@mkdir( $d10, 0775, true );
file_put_contents( $d10 . '/index.html', 'N1-E10-STALE' );
( new Checkpoint() )->write( 5, $own ); // node believes gen 5 covered
$GLOBALS['wpdb']->raw( "UPDATE wp_uc_cluster_epoch SET epoch = 1 WHERE name = 'cluster'" ); // authority RESET (restore-from-backup)
$n = $prop->consume();
n1check( $results, 'E10a regression detected: forced reconcile wiped the tree', ! is_file( $d10 . '/index.html' ) );
n1check( $results, 'E10b checkpoint rebaselined to the new authority era (1)', 1 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );
n1check( $results, 'E10c authority failure counted', 1 <= ( new State( $ROOT ) )->read()['epoch_authority_failures'] );

// =====================================================================
// E11 — producer boundary: bump BEFORE purge, remembered gen published
// =====================================================================
n1_reset_all();
$store = new EventStore();
$prop  = new Propagator( $store, new Epoch(), new Checkpoint() );
$own   = NodeIdentity::id();
$auth  = new Epoch();
$s0    = $auth->current();
$prop->on_before_purge_tags( array( 'post:1' ), array( 'cluster1.test/e11' ) );
$s1 = $auth->current();
n1check( $results, 'E11a before-hook ALREADY bumped the shared generation', $s1 === $s0 + 1, "s0=$s0 s1=$s1" );
$prop->on_purge_tags( array( 'post:1' ), 1, array( 'cluster1.test/e11' ) );
$row = (array) $GLOBALS['wpdb']->get_results( "SELECT gen_epoch, epoch, schema_version FROM wp_uc_invalidation_events ORDER BY id DESC LIMIT 1" );
$row = $row[0] ?? array();
n1check( $results, 'E11b published row carries the REMEMBERED generation (not a second bump)', (int) ( $row['gen_epoch'] ?? -1 ) === $s1 && (int) ( $row['schema_version'] ?? 0 ) === 2, json_encode( $row ) );
n1check( $results, 'E11c exactly ONE bump per operation (after-hook did not bump again)', $auth->current() === $s1 );
n1check( $results, 'E11d producer claimed its own coverage at publish', $s1 === ( new Checkpoint() )->read( $own ) );

// =====================================================================
// E12 — crash window: bump without publish → recoverable gap
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d12  = $ROOT . '/v/cluster1.test/e12';
@mkdir( $d12, 0775, true );
file_put_contents( $d12 . '/index.html', 'N1-E12-STALE' );
$prop->on_before_purge_tags( array( 'post:1' ), array( 'cluster1.test/e12' ) );
// CRASH: the after-hook (publish) never fires; the local purge never ran.
$n = $prop->consume();
n1check( $results, 'E12a crash window recovered: the local tree was reconciled', ! is_file( $d12 . '/index.html' ) );
n1check( $results, 'E12b checkpoint covers the crashed generation', 1 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );

// =====================================================================
// E13 — claim-at-publish: producer's own tick does NOT fake a lost tail
// =====================================================================
n1_reset_all();
$store = new EventStore();
$prop  = new Propagator( $store, new Epoch(), new Checkpoint() );
$own   = NodeIdentity::id();
$d13   = $ROOT . '/v/cluster1.test/e13';
@mkdir( $d13, 0775, true );
file_put_contents( $d13 . '/index.html', 'N1-E13-KEEP' );
$prop->on_before_purge_tags( array( 'post:1' ), array( 'cluster1.test/e13x' ) );
$prop->on_purge_tags( array( 'post:1' ), 1, array( 'cluster1.test/e13x' ) ); // published, claimed
$before = ( new State( $ROOT ) )->read();
$n = $prop->consume(); // own row pending but origin-excluded; S == C → no reconcile
n1check( $results, 'E13a producer tick: zero reconciles after own publish', ( new State( $ROOT ) )->read()['epoch_reconciliations'] === $before['epoch_reconciliations'] );
n1check( $results, 'E13b unrelated page untouched', is_file( $d13 . '/index.html' ) );

// =====================================================================
// E14 — publish failure → no coverage claimed (fail-closed)
// =====================================================================
n1_reset_all();
$store = new EventStore();
$prop  = new Propagator( $store, new Epoch(), new Checkpoint() );
$own   = NodeIdentity::id();
$GLOBALS['wpdb']->readonly = true; // publish will fail
$prop->on_before_purge_tags( array( 'post:1' ), array( 'cluster1.test/e14' ) );
$prop->on_purge_tags( array( 'post:1' ), 1, array( 'cluster1.test/e14' ) );
$GLOBALS['wpdb']->readonly = false;
n1check( $results, 'E14a failed publish claims NOTHING (checkpoint stays 0)', 0 === ( new Checkpoint() )->read( $own ), 'ckpt=' . ( new Checkpoint() )->read( $own ) );
n1check( $results, 'E14b publish failure counted', 1 <= ( new State( $ROOT ) )->read()['failures'] );

// =====================================================================
// E15 — v1 compatibility + future schema safety
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d15a = $ROOT . '/v/cluster1.test/e15a';
@mkdir( $d15a, 0775, true );
file_put_contents( $d15a . '/index.html', 'N1-E15A' );
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/e15a' ), 'tags' => array() ) ); // schema 1 (Phase M), gen 0
$n = $prop->consume();
n1check( $results, 'E15a Phase M schema-1 row executes targeted', 1 === $n && ! is_file( $d15a . '/index.html' ), 'n=' . $n );
n1check( $results, 'E15b gen-0 rows do NOT move the checkpoint (M5 TTL bound documented)', 0 === ( new Checkpoint() )->read( $own ) );
$d15b = $ROOT . '/v/cluster1.test/e15b';
@mkdir( $d15b, 0775, true );
file_put_contents( $d15b . '/index.html', 'N1-E15B' );
( new Epoch() )->bump(); // S=1: the future-schema row CLAIMS gen 1
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/e15b' ), 'tags' => array() ), 0, 3, null, 0, 1 ); // FUTURE schema 3
$n = $prop->consume();
n1check( $results, 'E15c future schema-3 row NEVER executes targeted', 0 === $n, 'n=' . $n );
n1check( $results, 'E15d future-format generation stays unaccounted → floor reconciles (fail-closed)', ! is_file( $d15b . '/index.html' ) && 1 <= ( new State( $ROOT ) )->read()['event_gaps'], json_encode( ( new State( $ROOT ) )->read() ) );

// =====================================================================
// E16 — checkpoint file semantics
// =====================================================================
n1_reset_all();
$own = NodeIdentity::id();
$ck  = new Checkpoint();
n1check( $results, 'E16a missing checkpoint reads 0', 0 === $ck->read( $own ) );
$ck->write( 7, $own );
n1check( $results, 'E16b atomic write then read-back', 7 === $ck->read( $own ) && 7 === $ck->observed( $own ) );
n1check( $results, 'E16c foreign-node checkpoint reads 0 (clone/restore fail-closed)', 0 === $ck->read( 'not-this-node' ) );
file_put_contents( $ROOT . '/' . Checkpoint::FILENAME, '{corrupt' );
n1check( $results, 'E16d corrupt checkpoint reads 0 (never throws)', 0 === $ck->read( $own ) );

// =====================================================================
// E17 — manual reconcile
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d17  = $ROOT . '/v/cluster1.test/e17';
@mkdir( $d17, 0775, true );
file_put_contents( $d17 . '/index.html', 'N1-E17' );
$auth = new Epoch();
$auth->bump();
$seen = $prop->reconcile( 'manual' );
n1check( $results, 'E17a manual reconcile wipes own tree + returns S', ! is_file( $d17 . '/index.html' ) && 1 === $seen );
n1check( $results, 'E17b manual reconcile is idempotent', 1 === $prop->reconcile( 'manual' ) && 1 === ( new Checkpoint() )->read( $own ) );

// =====================================================================
// E18 — janitor retention incl. own-claimed pending rows
// =====================================================================
n1_reset_all();
$own = NodeIdentity::id();
$old = (int) round( microtime( true ) * 1000 ) - 2 * EventStore::RETENTION_SEC * 1000;
$store = new EventStore();
$store->publish( 'purge_dirs', array( 'dirs' => array( 'x' ) ), 0, 1 );           // OWN pending (recent)
$GLOBALS['wpdb']->raw( 'UPDATE wp_uc_invalidation_events SET created = ' . $old . " WHERE origin = '" . $own . "' AND gen_epoch = 1" ); // age it past retention
$GLOBALS['wpdb']->peer_event( 'peer-y', 'purge_dirs', array( 'dirs' => array( 'y' ) ), 0, 2, $old, 0, 2 ); // FOREIGN pending OLD
$GLOBALS['wpdb']->peer_event( 'peer-y', 'purge_dirs', array( 'dirs' => array( 'z' ) ), 0, 2, $old, 1, 3 ); // consumed OLD
$GLOBALS['wpdb']->peer_event( 'peer-y', 'purge_dirs', array( 'dirs' => array( 'w' ) ), 0, 2, null, 0, 4 ); // FOREIGN pending NEW
$store->prune( $own );
n1check( $results, 'E18a consumed rows past retention pruned', 0 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE gen_epoch = 3" ) );
n1check( $results, 'E18b own pending rows past retention pruned (claimed at publish)', 0 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE origin = '" . $own . "' AND gen_epoch = 1" ) );
n1check( $results, 'E18c FOREIGN pending rows NEVER pruned (old or new)', 2 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE origin = 'peer-y' AND consumed = 0" ) );

// =====================================================================
// E19 — watermark-reset counter (harmless replay disclosure)
// =====================================================================
n1_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
( new Checkpoint() )->write( 1, $own ); // node HAS cluster history
// no watermark file (wiped by a cache-root recreate) + a foreign event arrives
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/e19' ), 'tags' => array() ), 0, 2, null, 0, 1 );
$prop->consume();
n1check( $results, 'E19a watermark reset detected and counted (once per round max)', 1 === ( new State( $ROOT ) )->read()['watermark_resets'], json_encode( ( new State( $ROOT ) )->read() ) );

// =====================================================================
// E20 — State schema lock + telemetry SCHEMA 3
// =====================================================================
n1_reset_all();
$state = new State( $ROOT );
$state->bump( array( 'epoch_reconciliations' => 2, 'bogus' => 9, 'stale' => -4 ) );
$row = $state->read();
n1check( $results, 'E20a State carries EXACTLY the ten fixed counters + lag gauge', 10 === count( State::COUNTERS ) && isset( $row['epoch_reconciliations'], $row['epoch_authority_failures'], $row['event_gaps'], $row['watermark_resets'], $row['node_id_collisions'], $row['lag_ms'] ) && ! isset( $row['bogus'] ) && 2 === $row['epoch_reconciliations'] && 0 === $row['stale'], json_encode( $row ) );
$snap = ( new Telemetry() )->snapshot();
n1check( $results, 'E20b telemetry schema 3 mirrors every epoch counter', 3 === $snap['schema'] && $snap['cluster_epoch_reconciliations'] === 2 && isset( $snap['cluster_event_gaps'], $snap['cluster_node_id_collisions'] ) );

// =====================================================================
// summary
// =====================================================================
$fail = 0;
foreach ( $results as $name => $ok ) {
        if ( ! $ok ) { ++$fail; }
}
$total = count( $results );
echo "\nN1 audit-cluster-epoch: {$total} checks, {$fail} FAIL\n";
exit( 0 === $fail ? 0 : 1 );
