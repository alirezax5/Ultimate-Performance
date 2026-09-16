<?php
/**
 * AUDIT TEST — M5: cluster page-cache invalidation (unit-level).
 *
 * Covers (see docs/PHASE-M-CLUSTER-INVALIDATION.md):
 *   N1  node identity: uuid7 file shape, PERSISTENCE across processes
 *       (M5-D1 regression: canonical dashed-uuid validation), corruption regen
 *   N2  producer/consumer hook wiring (after_purge_tags, after_purge_all, tick)
 *   N3  producer: tags → dirs derivation via the REAL Registry, dedup across
 *       tags, epoch recorded, empty-members no-publish, purge_all publish
 *   N4  EventStore row contract on a SQLite-backed $wpdb double (REAL SQL:
 *       insert shape, origin exclusion, id order, LIMIT, mark_consumed)
 *   N5  consumer executes purge_dirs on its OWN tree (real files deleted)
 *   N6  hostile-dir containment (hash-mapped INSIDE the tree; outside files
 *       untouched) — consumer-side re-validation by construction
 *   N7  per-origin watermark dedup (bounded, idempotent; duplicates counter)
 *   N8  unknown schema_version: skipped, never executed, marked consumed
 *   N9  unknown scope: skipped, never executed, marked consumed
 *   N10 epoch guard (§4.6): event epoch < consumer epoch → stale-skipped
 *       (counted, never executed, marked consumed); equal epoch executes
 *   N11 batch cap is filterable and bounded (200 default; 2 via filter)
 *   N12 purge_all consumer wipes its own v/ tree, marks consumed
 *   N13 metrics (§6): schema-locked Cluster\State counters + lag gauge,
 *       surfaced in Telemetry snapshot (schema 2) + Prometheus exposition
 *   N14 janitor: prunes consumed rows past retention; NEVER prunes pending
 *   N15 publish failure counted (failures), never fatal
 *   N16 ensure_table idempotent
 *
 * Honesty boundary: the EventStore SQL here runs on real SQLite through the
 * real EventStore code; the MySQL DIALECT of ensure_table() is proven live
 * on real MariaDB by tests/run-cluster-live.sh (C1-C8) — this suite does
 * NOT substitute for the live gate.
 *
 * Run: php tests/audit-cluster.php   (exit 0 only when all checks pass)
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\CacheTag\Registry;
use UltimatePerformance\Cluster\EventStore;
use UltimatePerformance\Cluster\NodeIdentity;
use UltimatePerformance\Cluster\Propagator;
use UltimatePerformance\Cluster\State;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Telemetry;

require_once __DIR__ . '/lib/class-uc-m5-wpdb.php'; // global-namespace test double

$results = array();
function c5check( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

/** Reset NodeIdentity's per-process static (simulates a fresh process). */
function c5_reset_node_identity() {
        $ref = new \ReflectionProperty( NodeIdentity::class, 'cached' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );
}

/** Recursive delete confined to an explicit path prefix. */
function c5_rrmdir( $dir, $prefix ) {
        $real = realpath( $dir );
        if ( false === $real || 0 !== strpos( $real, $prefix ) ) {
                return; // refuse anything outside the expected root
        }
        foreach ( (array) glob( $real . '/*' ) as $p ) {
                is_dir( $p ) ? c5_rrmdir( $p, $prefix ) : @unlink( $p );
        }
        @rmdir( $real );
}

const C5_UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

$ROOT = Installer::cache_root();
c5_rrmdir( $ROOT, realpath( dirname( WP_CONTENT_DIR ) . '/wp-content/cache' ) ?: $ROOT );
if ( ! is_dir( $ROOT ) ) {
        @mkdir( $ROOT, 0775, true );
}

// Swap in the SQLite-backed $wpdb double (global $wpdb, resolved at call time).
$__db_path = sys_get_temp_dir() . '/uc-m5-audit-' . getmypid() . '.sqlite';
@$wpdb_double = new \UC_M5_WPDB( $__db_path );
$GLOBALS['wpdb'] = $wpdb_double;
register_shutdown_function( function () use ( $__db_path ) { @unlink( $__db_path ); } );

$prop = new Propagator( new EventStore() );
$state = new State( $ROOT );

// =====================================================================
// N1 — node identity
// =====================================================================
c5_reset_node_identity();
$id1 = NodeIdentity::id();
c5check( $results, 'N1a node id is a canonical dashed uuid7', 1 === preg_match( C5_UUID_RE, (string) $id1 ), 'id=' . (string) $id1 );
$idfile = $ROOT . '/meta/node-id.json';
$meta   = is_readable( $idfile ) ? json_decode( (string) file_get_contents( $idfile ), true ) : null;
c5check( $results, 'N1b node id persisted to meta/node-id.json with matching value', is_array( $meta ) && ( $meta['node_id'] ?? '' ) === $id1, json_encode( $meta ) );
c5_reset_node_identity(); // fresh "process"
$id2 = NodeIdentity::id();
c5check( $results, 'N1c node id STABLE across processes (M5-D1 regression)', $id2 === $id1, "$id1 vs $id2" );
// Corrupted (non-canonical) stored id must regenerate, not be reused.
file_put_contents( $idfile, (string) wp_json_encode( array( 'node_id' => str_repeat( 'a', 36 ), 'created' => time() ) ) );
c5_reset_node_identity();
$id3 = NodeIdentity::id();
c5check( $results, 'N1d corrupted stored id rejected and regenerated', str_repeat( 'a', 36 ) !== $id3 && 1 === preg_match( C5_UUID_RE, $id3 ), 'id=' . $id3 );

// =====================================================================
// N2 — hook wiring
// =====================================================================
$prop->register();
c5check( $results, 'N2a producer bound to ultimate_performance_after_purge_tags', has_action( 'ultimate_performance_after_purge_tags' ) );
c5check( $results, 'N2b producer bound to ultimate_performance_after_purge_all', has_action( 'ultimate_performance_after_purge_all' ) );
c5check( $results, 'N2c consumer bound to ultimate_performance_tick', has_action( 'ultimate_performance_tick' ) );

// =====================================================================
// N3 — producer derivation via the REAL Registry
// =====================================================================
$before = $state->read();
$reg    = new Registry( new SafeFs() );
$reg->attach( 'cluster1.test/n3a', array( 'uc:tag:n3t1', 'uc:tag:n3t2' ) ); // same dir under two tags
$prop->on_purge_tags( array( 'uc:tag:n3t1', 'uc:tag:n3t2' ) );
$after = $state->read();
$row   = $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events" );
$last  = $GLOBALS['wpdb']->get_results( "SELECT * FROM wp_uc_invalidation_events ORDER BY id DESC LIMIT 1" );
$last  = $last[0] ?? array();
c5check( $results, 'N3a tags → deduped dirs event published (same dir under 2 tags → 1 dir)', 1 === (int) $row && 'purge_dirs' === (string) ( $last['scope'] ?? '' ), 'rows=' . $row );
$payload = json_decode( (string) ( $last['payload'] ?? '' ), true );
c5check( $results, 'N3b payload carries the deduped dir + source tags', ( array( 'cluster1.test/n3a' ) === ( $payload['dirs'] ?? null ) ) && 2 === count( (array) ( $payload['tags'] ?? array() ) ), json_encode( $payload ) );
c5check( $results, 'N3c publish counters recorded (published ≥1, no failures)', ( $after['published'] - $before['published'] ) >= 1 && $after['failures'] === $before['failures'], json_encode( array( $before, $after ) ) );
$rows_before = (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events" );
$prop->on_purge_tags( array( 'uc:tag:missing' ) ); // no members → no event
c5check( $results, 'N3d no members → no publish', $rows_before === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events" ) );
$prop->on_purge_all();
$last = $GLOBALS['wpdb']->get_results( "SELECT scope, payload FROM wp_uc_invalidation_events ORDER BY id DESC LIMIT 1" );
c5check( $results, 'N3e purge_all published with empty payload', 'purge_all' === (string) ( $last[0]['scope'] ?? '' ) && '{}' === (string) ( $last[0]['payload'] ?? '' ) );
// M5-D5 regression: the DECIDED dirs arrive as the third argument and are
// used even when the registry is ALREADY empty (the local purge detached
// them — the synchronous-queue race that silently lost every live event).
$prop->on_purge_tags( array( 'uc:tag:post-detach' ), 1, array( 'cluster1.test/n3z' ) );
$last = $GLOBALS['wpdb']->get_results( "SELECT scope, payload FROM wp_uc_invalidation_events ORDER BY id DESC LIMIT 1" );
$payload = json_decode( (string) ( $last[0]['payload'] ?? '' ), true );
c5check( $results, 'N3f decided dirs (3rd arg) published even with an EMPTY registry (M5-D5)', 'purge_dirs' === (string) ( $last[0]['scope'] ?? '' ) && array( 'cluster1.test/n3z' ) === ( $payload['dirs'] ?? null ), json_encode( $payload ?? null ) );

// =====================================================================
// N4 — EventStore row contract (real SQL on the sqlite double)
// =====================================================================
$eid = ( new EventStore() )->publish( 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n4' ), 'tags' => array() ), 0 );
c5check( $results, 'N4a publish returns a dashed uuid7 event id', 1 === preg_match( C5_UUID_RE, (string) $eid ), 'id=' . (string) $eid );
$own = NodeIdentity::id();
$pending_own = ( new EventStore() )->pending_for( $own, 200 );
c5check( $results, 'N4b pending_for EXCLUDES own-origin events', 0 === count( (array) $pending_own ), 'count=' . count( (array) $pending_own ) );
// Foreign-origin events exactly the way a peer node's publish() would write them.
$GLOBALS['wpdb']->peer_event( 'peer-a', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n4x' ), 'tags' => array() ) );
$GLOBALS['wpdb']->peer_event( 'peer-a', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n4y' ), 'tags' => array() ) );
$pend = ( new EventStore() )->pending_for( $own, 200 );
$ids  = array_map( 'intval', array_column( (array) $pend, 'id' ) );
$sorted = $ids; sort( $sorted );
c5check( $results, 'N4c pending_for returns foreign events in strict id order', count( $ids ) >= 2 && $ids === $sorted, json_encode( $ids ) );
$one = ( new EventStore() )->pending_for( $own, 1 );
c5check( $results, 'N4d batch LIMIT honored (cap 1 → 1 row)', 1 === count( (array) $one ) );
$row1 = (array) ( $one[0] ?? array() );
c5check( $results, 'N4e row shape: schema_version/epoch/created present, row pending in DB', isset( $row1['schema_version'], $row1['epoch'], $row1['created'] ) && (int) $row1['schema_version'] === 1 && 1 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE id = " . (int) ( $row1['id'] ?? 0 ) . " AND consumed = 0" ), json_encode( array_keys( $row1 ) ) );

// =====================================================================
// N5 — consumer executes purge_dirs on its OWN tree
// =====================================================================
$prop->consume(); // harness: drain N4's leftover peer events first (measured round below)
$d5 = $ROOT . '/v/cluster1.test/n5a';
@mkdir( $d5, 0775, true );
file_put_contents( $d5 . '/index.html', 'M5-N5-BODY' );
file_put_contents( $d5 . '/index.html.meta.json', '{}' );
$before = $state->read();
$GLOBALS['wpdb']->peer_event( 'peer-a', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n5a' ), 'tags' => array() ) );
$n = $prop->consume();
$after = $state->read();
c5check( $results, 'N5a consume executes the event (returns 1)', 1 === $n, 'n=' . $n );
c5check( $results, 'N5b own stale copy deleted (body + meta)', ! is_file( $d5 . '/index.html' ) && ! is_file( $d5 . '/index.html.meta.json' ) );
c5check( $results, 'N5c consumed counter recorded + lag gauge sampled (≥0)', ( $after['consumed'] - $before['consumed'] ) >= 1 && $after['lag_ms'] >= 0, json_encode( array( $before, $after ) ) );
$wm = is_readable( $ROOT . '/meta/cluster-watermark.json' ) ? json_decode( (string) file_get_contents( $ROOT . '/meta/cluster-watermark.json' ), true ) : array();
c5check( $results, 'N5d per-origin watermark advanced to the consumed id', is_array( $wm ) && ( (int) ( $wm['peer-a'] ?? 0 ) ) >= 1, json_encode( $wm ) );

// =====================================================================
// N6 — hostile-dir containment (hash-mapped INSIDE the tree)
// =====================================================================
$outside = sys_get_temp_dir() . '/uc-m5-outside';
@mkdir( $outside, 0775, true );
$golden  = $outside . '/uc-m5-golden.txt';
file_put_contents( $golden, 'OUTSIDE-GOLDEN' );
$GLOBALS['wpdb']->peer_event( 'peer-a', 'purge_dirs', array( 'dirs' => array( '../../uc-m5-golden.txt' ), 'tags' => array() ) );
$prop->consume();
$mapped = (string) ( new \UltimatePerformance\CacheKey\Key( \UltimatePerformance\Core\Settings::instance() ) )->absolute( '../../uc-m5-golden.txt' );
c5check( $results, 'N6a file OUTSIDE the cache tree untouched', is_file( $golden ) && 'OUTSIDE-GOLDEN' === (string) file_get_contents( $golden ) );
c5check( $results, 'N6b hostile dir hash-mapped INSIDE the tree (containment by construction)', '' !== $mapped && 0 === strpos( $mapped, Installer::cache_root() . '/v/' ), 'mapped=' . $mapped );

// =====================================================================
// N7 — per-origin watermark dedup (idempotent replay protection)
// =====================================================================
$GLOBALS['wpdb']->peer_event( 'peer-b', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n7' ), 'tags' => array() ) );
$ev_id = (int) $GLOBALS['wpdb']->scalar( "SELECT MAX(id) FROM wp_uc_invalidation_events" );
$wm_file = $ROOT . '/meta/cluster-watermark.json';
$wm = json_decode( (string) file_get_contents( $wm_file ), true );
$wm['peer-b'] = $ev_id; // pretend this event was already executed
file_put_contents( $wm_file, (string) wp_json_encode( $wm ) );
$before = $state->read();
$n = $prop->consume();
$after = $state->read();
c5check( $results, 'N7a replayed event: NO re-execution (returns 0)', 0 === $n, 'n=' . $n );
c5check( $results, 'N7b duplicates counter incremented', ( $after['duplicates'] - $before['duplicates'] ) >= 1, json_encode( array( $before['duplicates'], $after['duplicates'] ) ) );
c5check( $results, 'N7c replayed row marked consumed (never re-fetched)', 0 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE id = {$ev_id} AND consumed = 0" ) );

// =====================================================================
// N8 — unknown schema_version: skip, never execute
// =====================================================================
$d8 = $ROOT . '/v/cluster1.test/n8';
@mkdir( $d8, 0775, true );
file_put_contents( $d8 . '/index.html', 'M5-N8-BODY' );
$GLOBALS['wpdb']->peer_event( 'peer-c', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n8' ), 'tags' => array() ), 0, 99 );
$n = $prop->consume();
c5check( $results, 'N8a unknown schema: no execution', 0 === $n && is_file( $d8 . '/index.html' ), 'n=' . $n );
c5check( $results, 'N8b unknown schema row marked consumed (no poison loop)', 0 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE origin = 'peer-c' AND schema_version = 99 AND consumed = 0" ) );

// =====================================================================
// N9 — unknown scope: skip, never execute
// =====================================================================
$GLOBALS['wpdb']->peer_event( 'peer-c', 'purge_nope', array() );
$n = $prop->consume();
c5check( $results, 'N9 unknown scope: no execution, marked consumed', 0 === $n && 0 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE scope = 'purge_nope' AND consumed = 0" ) );

// =====================================================================
// N10 — epoch guard (§4.6) via the filter seam (unit level)
// =====================================================================
$c_epoch_guard = function () { return 5; };
add_filter( 'ultimate_cache_cluster_epoch', $c_epoch_guard );
$d10 = $ROOT . '/v/cluster1.test/n10stale';
@mkdir( $d10, 0775, true );
file_put_contents( $d10 . '/index.html', 'M5-N10-STALE' );
$before = $state->read();
$GLOBALS['wpdb']->peer_event( 'peer-d', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n10stale' ), 'tags' => array() ), 0 ); // epoch 0 < 5
$n = $prop->consume();
$after = $state->read();
c5check( $results, 'N10a stale-epoch event NOT executed (file stays)', 0 === $n && is_file( $d10 . '/index.html' ), 'n=' . $n );
c5check( $results, 'N10b stale counter incremented', ( $after['stale'] - $before['stale'] ) >= 1, json_encode( array( $before['stale'], $after['stale'] ) ) );
$d10b = $ROOT . '/v/cluster1.test/n10fresh';
@mkdir( $d10b, 0775, true );
file_put_contents( $d10b . '/index.html', 'M5-N10-FRESH' );
$GLOBALS['wpdb']->peer_event( 'peer-d', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n10fresh' ), 'tags' => array() ), 5 ); // equal epoch executes
$n = $prop->consume();
c5check( $results, 'N10c equal-epoch event EXECUTES (guard is strictly <)', 1 === $n && ! is_file( $d10b . '/index.html' ), 'n=' . $n );
remove_filter( 'ultimate_cache_cluster_epoch', $c_epoch_guard );

// =====================================================================
// N11 — batch cap is filterable + bounded
// =====================================================================
$c_batch_guard = function () { return 2; };
add_filter( 'ultimate_cache_cluster_batch', $c_batch_guard );
for ( $i = 1; $i <= 5; $i++ ) {
        $GLOBALS['wpdb']->peer_event( 'peer-e', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/n11-' . $i ), 'tags' => array() ) );
}
$n1 = $prop->consume();
$pending_mid = (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE consumed = 0 AND origin <> '" . NodeIdentity::id() . "'" );
$n2 = $prop->consume();
$n3 = $prop->consume();
remove_filter( 'ultimate_cache_cluster_batch', $c_batch_guard );
c5check( $results, 'N11a batch cap 2 enforced per round (2 → 3 pending → 2 → 1)', 2 === $n1 && 3 === $pending_mid && 2 === $n2 && 1 === $n3, "n1=$n1 mid=$pending_mid n2=$n2 n3=$n3" );
c5check( $results, 'N11b all events drained across rounds (bounded, never dropped)', 0 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE origin = 'peer-e' AND consumed = 0" ) );

// =====================================================================
// N12 — purge_all consumer wipes its own tree
// =====================================================================
$d12 = $ROOT . '/v/cluster1.test/n12';
@mkdir( $d12, 0775, true );
file_put_contents( $d12 . '/index.html', 'M5-N12' );
$GLOBALS['wpdb']->peer_event( 'peer-f', 'purge_all', array() );
$n = $prop->consume();
c5check( $results, 'N12a purge_all wipes the whole local v/ tree', 1 === $n && ! is_dir( $ROOT . '/v/cluster1.test/n12' ) );
c5check( $results, 'N12b purge_all event marked consumed (own-origin N3e row stays pending BY DESIGN)', 0 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE scope = 'purge_all' AND origin = 'peer-f' AND consumed = 0" ) );

// =====================================================================
// N13 — metrics: schema-locked State + Telemetry snapshot + Prometheus
// =====================================================================
$cur = $state->read();
c5check( $results, 'N13a counters are ints ≥0 on the fixed schema', isset( $cur['published'], $cur['consumed'], $cur['duplicates'], $cur['failures'], $cur['stale'], $cur['lag_ms'] ) && $cur['consumed'] >= 1 && $cur['duplicates'] >= 1 && $cur['stale'] >= 1, json_encode( $cur ) );
$state->bump( array( 'bogus_key' => 100, 'published' => -50 ) ); // hostile shape
$cur2 = $state->read();
c5check( $results, 'N13b schema lock: unknown keys dropped, negatives clamped', ! isset( $cur2['bogus_key'] ) && $cur2['published'] === $cur['published'], json_encode( array( $cur, $cur2 ) ) );
$tel = new Telemetry();
$snap = $tel->snapshot();
c5check( $results, 'N13c snapshot (schema 3) carries the eleven cluster keys', 3 === $snap['schema'] && isset( $snap['cluster_events_published'], $snap['cluster_events_consumed'], $snap['cluster_events_duplicates'], $snap['cluster_events_failures'], $snap['cluster_events_stale'], $snap['cluster_epoch_reconciliations'], $snap['cluster_epoch_authority_failures'], $snap['cluster_event_gaps'], $snap['cluster_watermark_resets'], $snap['cluster_node_id_collisions'], $snap['cluster_lag_ms'] ), 'schema=' . $snap['schema'] );
c5check( $results, 'N13d telemetry mirrors the State counters (same root)', $snap['cluster_events_consumed'] === $cur2['consumed'] && $snap['cluster_events_stale'] === $cur2['stale'], json_encode( array( $snap, $cur2 ) ) );
$prom = $tel->render_prometheus( $snap );
$cluster_lines = array_values( preg_grep( '/^uc_cluster_/', explode( "\n", $prom ) ) );
$bad = array();
foreach ( $cluster_lines as $ln ) {
        if ( '' === $ln || 0 === strpos( (string) $ln, '#' ) ) { continue; }
        if ( ! preg_match( '/^uc_[a-z_]+(_total)? \d+$/', (string) $ln ) ) { $bad[] = $ln; }
}
c5check( $results, 'N13e Prometheus exposes uc_cluster_* with fixed names/grammar', count( $cluster_lines ) >= 6 && 0 === count( $bad ), json_encode( $bad ) );

// =====================================================================
// N14 — janitor: consumed+old pruned; fresh consumed and OLD PENDING kept
// =====================================================================
$old_ms = (int) round( microtime( true ) * 1000 ) - 2 * EventStore::RETENTION_SEC * 1000;
$GLOBALS['wpdb']->peer_event( 'peer-g', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/old-c' ), 'tags' => array() ), 0, 1, $old_ms, 1 ); // old + consumed → pruned
$GLOBALS['wpdb']->peer_event( 'peer-g', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/new-c' ), 'tags' => array() ), 0, 1, null, 1 );  // fresh consumed → kept
$GLOBALS['wpdb']->peer_event( 'peer-g', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/old-p' ), 'tags' => array() ), 0, 1, $old_ms, 0 ); // OLD PENDING → NEVER pruned
$pruned = ( new EventStore() )->prune();
$old_c   = (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE payload LIKE '%old-c%' AND consumed = 1" );
$new_c   = (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE payload LIKE '%new-c%' AND consumed = 1" );
$old_p   = (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE payload LIKE '%old-p%' AND consumed = 0" );
c5check( $results, 'N14a prune deletes consumed rows past retention', $pruned >= 1 && 0 === $old_c, "pruned=$pruned old_c=$old_c" );
c5check( $results, 'N14b fresh consumed rows kept', 1 === $new_c );
c5check( $results, 'N14c pending rows NEVER pruned (even when old)', 1 === $old_p );

// =====================================================================
// N15 — publish failure counted, never fatal
// =====================================================================
$before = $state->read();
$GLOBALS['wpdb']->readonly = true;
$failed_id = ( new EventStore() )->publish( 'purge_dirs', array( 'dirs' => array( 'cluster1.test/x' ), 'tags' => array() ), 0 );
$GLOBALS['wpdb']->readonly = false;
$after = $state->read();
c5check( $results, 'N15a publish failure returns empty id (never fatal)', '' === $failed_id );
c5check( $results, 'N15b failures counter incremented', ( $after['failures'] - $before['failures'] ) >= 1, json_encode( array( $before['failures'], $after['failures'] ) ) );

// =====================================================================
// N16 — ensure_table idempotent
// =====================================================================
$es1 = new EventStore();
$a = $es1->ensure_table();
$b = ( new EventStore() )->ensure_table();
c5check( $results, 'N16 ensure_table idempotent (true, twice)', true === $a && true === $b );

// =====================================================================
// N17 — M6 uninstall regression (complete data removal, idempotent)
// =====================================================================
c5check( $results, 'N17a uninstall.php exists, guarded, wired to Installer', is_file( ULTIMATE_PERFORMANCE_DIR . 'uninstall.php' ) && false !== strpos( (string) file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'uninstall.php' ), 'WP_UNINSTALL_PLUGIN' ) && false !== strpos( (string) file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'uninstall.php' ), 'Installer::uninstall_data' ) );
// Seed EVERYTHING the uninstall must remove.
update_option( 'ultimate_performance_settings', array( 'enabled' => true, 'page_cache_enabled' => true ) );
set_transient( 'up_env_snapshot', array( 'x' => 1 ), 60 );
set_transient( 'up_oc_dropin_status', 'ok', 60 );
set_transient( 'up_registry_overflow', 1, 60 );
set_transient( 'up_settings_errors', array(), 60 );
$d17 = $ROOT . '/v/cluster1.test/uninstall';
@mkdir( $d17, 0775, true );
file_put_contents( $d17 . '/index.html', 'M5-N17' );
NodeIdentity::id(); // ensure node-id.json exists inside the tree
( new EventStore() )->publish( 'purge_dirs', array( 'dirs' => array( 'cluster1.test/uninstall' ), 'tags' => array() ), 0 );
$table_before = $GLOBALS['wpdb']->get_var( "SHOW TABLES LIKE 'wp_uc_invalidation_events'" );
c5check( $results, 'N17b seeded state present before uninstall (option, rows, tree)', '' !== (string) $table_before && is_file( $ROOT . '/meta/node-id.json' ) && get_option( 'ultimate_performance_settings', false ) !== false );
// THE uninstall (WP defines WP_UNINSTALL_PLUGIN for the real file; the
// Installer entry point is what the real file calls).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        define( 'WP_UNINSTALL_PLUGIN', 'ultimate-performance/ultimate-performance.php' );
}
\UltimatePerformance\Core\Installer::uninstall_data();
c5check( $results, 'N17c settings option deleted', false === get_option( 'ultimate_performance_settings', false ) );
c5check( $results, 'N17d all four plugin transients deleted', false === get_transient( 'up_env_snapshot' ) && false === get_transient( 'up_oc_dropin_status' ) && false === get_transient( 'up_registry_overflow' ) && false === get_transient( 'up_settings_errors' ) );
c5check( $results, 'N17e cache tree GONE (node-id, v/, page files — incl. cluster state/watermark)', ! is_file( $ROOT . '/meta/node-id.json' ) && ! is_file( $d17 . '/index.html' ) && ! is_file( $ROOT . '/meta/cluster-state.json' ) );
$GLOBALS['wpdb']->readonly = false; // ensure the DROP runs for real
c5check( $results, 'N17f shared cluster events table DROPPED', null === $GLOBALS['wpdb']->get_var( "SHOW TABLES LIKE 'wp_uc_invalidation_events'" ) );
\UltimatePerformance\Core\Installer::uninstall_data(); // idempotency: no fatal, no resurrection
c5check( $results, 'N17g uninstall idempotent (second call, table stays gone)', null === $GLOBALS['wpdb']->get_var( "SHOW TABLES LIKE 'wp_uc_invalidation_events'" ) );

// =====================================================================
// summary
// =====================================================================
$fail = 0;
foreach ( $results as $name => $ok ) {
        if ( ! $ok ) { ++$fail; }
}
$total = count( $results );
echo "\nM5 audit-cluster: {$total} checks, {$fail} FAIL\n";
exit( 0 === $fail ? 0 : 1 );
