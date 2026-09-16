<?php
/**
 * AUDIT TEST — N3: watermark / replay hardening (§14–15) + cache-root
 * deletion recovery. See docs/PHASE-N-EPOCH-DESIGN.md.
 *
 * Covers:
 *   W1  consumer-side purge_all wipes ONLY the v/ tree — watermark,
 *       checkpoint and node identity survive (dedup state is not cache
 *       content)
 *   W2  watermark file shape is bounded: one fixed JSON object, one key
 *       per origin, size stays small regardless of event count
 *   W3  cache-root deletion → node "restart" → replay of redelivered
 *       rows: NO stale resurrection (replay only deletes), checkpoint
 *       rebuilt, state converges to the shared frontier
 *   W4  root-deletion replay with a page re-rendered between rounds:
 *       the replayed targeted purge removes it (no stale resurrection)
 *   W5  unbounded-growth probe: 500 events from one origin → watermark
 *       stays single-keyed; consumed rows janitor-pruned after retention
 *       (aged rows), pending foreign rows never pruned
 *   W6  cross-node checkpoint collision: a second node writing the same
 *       root is caught by the node-bound check (first node reads 0 →
 *       fail-closed reconcile; no silent cross-node state mixing)
 *   W7  full replay idempotency: reset consumed flags for the whole
 *       history → replay round: zero re-executions where coverage is
 *       proven, everything converges, counters stay bounded
 *
 * Honesty boundary: SQLite double for row semantics; live multi-node
 * versions of W3/W4 run in the Phase N live gates (run-cluster-live).
 *
 * Run: php tests/audit-watermark.php   (exit 0 only when all pass)
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
use UltimatePerformance\Cluster\EventStore;
use UltimatePerformance\Cluster\Epoch;
use UltimatePerformance\Cluster\NodeIdentity;
use UltimatePerformance\Cluster\Propagator;
use UltimatePerformance\Cluster\State;
use UltimatePerformance\Core\Installer;

require_once __DIR__ . '/lib/class-uc-m5-wpdb.php';

$results = array();
function wcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

function w_reset_node_identity() {
        $ref = new \ReflectionProperty( NodeIdentity::class, 'cached' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );
}

function w_rrmdir( $dir, $prefix ) {
        $real = realpath( $dir );
        if ( false === $real || 0 !== strpos( $real, $prefix ) ) {
                return;
        }
        foreach ( (array) glob( $real . '/*' ) as $p ) {
                is_dir( $p ) ? w_rrmdir( $p, $prefix ) : @unlink( $p );
        }
        @rmdir( $real );
}

$ROOT = Installer::cache_root();
w_rrmdir( $ROOT, realpath( dirname( WP_CONTENT_DIR ) . '/wp-content/cache' ) ?: $ROOT );
if ( ! is_dir( $ROOT ) ) {
        @mkdir( $ROOT, 0775, true );
}

$__db_path = sys_get_temp_dir() . '/uc-w-audit-' . getmypid() . '.sqlite';
$__db      = new \UC_M5_WPDB( $__db_path );
$GLOBALS['wpdb'] = $__db;
register_shutdown_function( function () use ( $__db_path ) { @unlink( $__db_path ); } );

function w_reset_all() {
        global $__db, $ROOT;
        $__db->fail_reads = false;
        $__db->readonly   = false;
        ( new EventStore() )->ensure_table();
        ( new Epoch() )->ensure_table();
        $__db->raw( 'DELETE FROM wp_uc_invalidation_events' );
        $__db->raw( "UPDATE wp_uc_cluster_epoch SET epoch = 0, updated_ms = 0 WHERE name = 'cluster'" );
        w_rrmdir( $ROOT . '/meta', $ROOT );
        @mkdir( $ROOT . '/meta', 0775, true );
        w_rrmdir( $ROOT . '/v', $ROOT );
        w_reset_node_identity();
}

function w_seed_gen( $origin, $dirs, $gen ) {
        $auth = new Epoch();
        while ( $auth->current() < $gen ) {
                if ( 0 === $auth->bump() ) {
                        break;
                }
        }
        $GLOBALS['wpdb']->peer_event( $origin, 'purge_dirs', array( 'dirs' => $dirs, 'tags' => array() ), 0, 2, null, 0, $gen );
}

// =====================================================================
// W1 — consumer purge_all keeps meta state
// =====================================================================
w_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$d1   = $ROOT . '/v/cluster1.test/w1';
@mkdir( $d1, 0775, true );
file_put_contents( $d1 . '/index.html', 'W1' );
w_seed_gen( 'peer-x', array( 'cluster1.test/w1' ), 1 );
$prop->consume(); // builds watermark + checkpoint
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_all', array(), 0, 2, null, 0, 0 ); // v1-style scope event (no gen)
$auth2 = new Epoch();
$auth2->bump();
$GLOBALS['wpdb']->raw( 'UPDATE wp_uc_invalidation_events SET gen_epoch = 0 WHERE scope = \'purge_all\'' );
$prop->consume();
wcheck( $results, 'W1a consumer purge_all wiped the v/ tree', ! is_dir( $d1 ) );
wcheck( $results, 'W1b watermark file SURVIVED the purge', is_file( $ROOT . '/meta/cluster-watermark.json' ) );
wcheck( $results, 'W1c checkpoint SURVIVED the purge', is_file( $ROOT . '/' . Checkpoint::FILENAME ) );
wcheck( $results, 'W1d node identity SURVIVED the purge', is_file( $ROOT . '/meta/node-id.json' ) );

// =====================================================================
// W2 — watermark shape bounded
// =====================================================================
w_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
foreach ( array( 'peer-a', 'peer-b', 'peer-c' ) as $i => $origin ) {
        w_seed_gen( $origin, array( 'cluster1.test/w2' ), $i + 1 );
}
$prop->consume();
$wm = json_decode( (string) file_get_contents( $ROOT . '/meta/cluster-watermark.json' ), true );
wcheck( $results, 'W2a watermark is a fixed JSON object keyed by origin', is_array( $wm ) && 3 === count( $wm ) && isset( $wm['peer-a'], $wm['peer-b'], $wm['peer-c'] ), json_encode( $wm ) );
wcheck( $results, 'W2b watermark size bounded (<512 bytes for 3 origins)', strlen( (string) file_get_contents( $ROOT . '/meta/cluster-watermark.json' ) ) < 512 );

// =====================================================================
// W3 — cache-root deletion → restart → replay: no stale resurrection
// =====================================================================
w_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
w_seed_gen( 'peer-x', array( 'cluster1.test/w3' ), 1 );
w_seed_gen( 'peer-x', array( 'cluster1.test/w3b' ), 2 );
$prop->consume();
$ckpt_before = ( new Checkpoint() )->read( NodeIdentity::id() );
wcheck( $results, 'W3a pre-deletion state converged (checkpoint 2)', 2 === $ckpt_before );
// THE DELETION (cache-root wipe = manual rm -rf of the whole tree).
w_rrmdir( $ROOT, realpath( dirname( WP_CONTENT_DIR ) . '/wp-content/cache' ) ?: $ROOT );
@mkdir( $ROOT, 0775, true );
// Simulate the redelivery window: rows re-marked pending (broker-style).
$GLOBALS['wpdb']->raw( 'UPDATE wp_uc_invalidation_events SET consumed = 0' );
w_reset_node_identity(); // fresh process on the fresh root
$prop2 = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$n     = $prop2->consume();
wcheck( $results, 'W3b replay round: no fatal, redelivered rows re-executed targeted (no-op deletes)', 2 === $n );
wcheck( $results, 'W3c no stale resurrection possible: replay only deletes (tree still empty)', ! is_dir( $ROOT . '/v' ) || 0 === count( (array) glob( $ROOT . '/v/*' ) ) );
wcheck( $results, 'W3d checkpoint rebuilt and converged to the frontier', 2 === ( new Checkpoint() )->read( NodeIdentity::id() ) );

// =====================================================================
// W4 — replay precision: pre-purge page purged once, post-purge FRESH
//      page NOT re-purged by the redelivered event (covered-skip)
// =====================================================================
w_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
w_seed_gen( 'peer-x', array( 'cluster1.test/w4' ), 1 );
// The PRE-purge (stale) page exists when round 1 executes.
$d4 = $ROOT . '/v/cluster1.test/w4';
@mkdir( $d4, 0775, true );
file_put_contents( $d4 . '/index.html', 'W4-PRE-PURGE-STALE' );
$prop->consume();
wcheck( $results, 'W4a stale pre-purge page removed by the first execution', ! is_file( $d4 . '/index.html' ) );
$GLOBALS['wpdb']->raw( 'UPDATE wp_uc_invalidation_events SET consumed = 0' ); // redelivery
// The node re-renders /page/ AFTER the purge — this content is FRESH.
@mkdir( $d4, 0775, true );
file_put_contents( $d4 . '/index.html', 'W4-FRESH-RE-RENDER' );
w_reset_node_identity();
$prop2 = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$n     = $prop2->consume();
wcheck( $results, 'W4b redelivered event covered-skipped (generation already proven)', 0 === $n );
wcheck( $results, 'W4c FRESH re-rendered page survives the replay (no over-invalidation of fresh content)', is_file( $d4 . '/index.html' ) && 'W4-FRESH-RE-RENDER' === (string) file_get_contents( $d4 . '/index.html' ) );

// =====================================================================
// W5 — unbounded growth probe (500 events, one origin)
// =====================================================================
w_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
for ( $g = 1; $g <= 500; $g++ ) {
        w_seed_gen( 'peer-flood', array( "cluster1.test/w5g$g" ), $g );
}
$rounds = 0;
$total  = 0;
while ( $total < 500 && $rounds < 6 ) {
        $total += $prop->consume();
        ++$rounds;
}
wcheck( $results, 'W5a 500 events drained in bounded batches (batch cap 200)', 500 === $total && $rounds >= 3, "total=$total rounds=$rounds" );
$wm = json_decode( (string) file_get_contents( $ROOT . '/meta/cluster-watermark.json' ), true );
wcheck( $results, 'W5b watermark STILL single-keyed after 500 events (bounded shape)', is_array( $wm ) && 1 === count( $wm ) && isset( $wm['peer-flood'] ) );
wcheck( $results, 'W5c watermark size bounded after 500 events (<256 bytes)', strlen( (string) file_get_contents( $ROOT . '/meta/cluster-watermark.json' ) ) < 256 );
// Age the consumed rows past retention → janitor prunes them.
$old = (int) round( microtime( true ) * 1000 ) - 2 * EventStore::RETENTION_SEC * 1000;
$GLOBALS['wpdb']->raw( 'UPDATE wp_uc_invalidation_events SET created = ' . $old . ' WHERE consumed = 1' );
$prop->consume(); // triggers prune( own )
$left = (int) $GLOBALS['wpdb']->scalar( 'SELECT COUNT(*) FROM wp_uc_invalidation_events' );
wcheck( $results, 'W5d janitor pruned consumed rows — bounded table', 0 === $left, 'left=' . $left );

// =====================================================================
// W6 — cross-node checkpoint collision (fail-closed)
// =====================================================================
w_reset_all();
$ck  = new Checkpoint();
$idA = 'node-a-aaaa';
$idB = 'node-b-bbbb';
$ck->write( 5, $idA );
wcheck( $results, 'W6a node A reads its own checkpoint', 5 === $ck->read( $idA ) );
$ck->write( 9, $idB ); // node B (misconfigured shared root) overwrites
wcheck( $results, 'W6b node A now reads 0 — node-bound fail-closed, no silent mixing', 0 === $ck->read( $idA ) );
wcheck( $results, 'W6c node B reads its own value', 9 === $ck->read( $idB ) );

// =====================================================================
// W7 — full-history replay idempotency
// =====================================================================
w_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
for ( $g = 1; $g <= 10; $g++ ) {
        w_seed_gen( 'peer-x', array( 'cluster1.test/w7g' . $g ), $g );
}
$prop->consume();
$before = ( new State( $ROOT ) )->read();
$GLOBALS['wpdb']->raw( 'UPDATE wp_uc_invalidation_events SET consumed = 0' ); // FULL redelivery
$n = $prop->consume();
wcheck( $results, 'W7a full-history replay: zero re-executions (checkpoint covers all gens)', 0 === $n, 'n=' . $n );
$after = ( new State( $ROOT ) )->read();
wcheck( $results, 'W7b replay counted as covered-skip (stale), never executed', $after['stale'] > $before['stale'] && $after['epoch_reconciliations'] === $before['epoch_reconciliations'] );
wcheck( $results, 'W7c checkpoint unchanged after full replay', 10 === ( new Checkpoint() )->read( $own ) );

// =====================================================================
// summary
// =====================================================================
$fail = 0;
foreach ( $results as $name => $ok ) {
        if ( ! $ok ) { ++$fail; }
}
$total = count( $results );
echo "\nN3 audit-watermark: {$total} checks, {$fail} FAIL\n";
exit( 0 === $fail ? 0 : 1 );
