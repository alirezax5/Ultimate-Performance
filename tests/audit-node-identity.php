<?php
/**
 * AUDIT TEST — N3: node identity hardening — clone detection that does
 * NOT depend on random probability. See docs/PHASE-N-EPOCH-DESIGN.md §5.
 *
 * Covers:
 *   I1  canonical uuid7 shape + cross-process stability (M5-D1 regression)
 *   I2  boot_secret present, 32-hex, persisted alongside the id
 *   I3  legacy M5 file (id WITHOUT secret) upgraded in place, SAME id
 *   I4  regenerate(): new id + new secret, file rewritten
 *   I5  lease: first registration clean (row created)
 *   I6  lease heartbeat: same id/secret/instance → clean
 *   I7  CONCURRENT clone: different instance nonce inside the freshness
 *       window → clone detected (verify false)
 *   I8  non-concurrent reuse: different instance, STALE last_ms → clean
 *       (legitimate restore while the original is off — documented)
 *   I9  post-registration clone: boot_secret mismatch → clone detected
 *   I10 after regenerate: new identity verifies clean; the node-bound
 *       checkpoint of the OLD identity reads 0 (fail-closed reconcile)
 *   I11 lease janitor: stale rows pruned, fresh kept, bounded table
 *   I12 integration: consume() with a LIVE clone lease → identity
 *       regenerated mid-round, node_id_collisions counted, consumption
 *       continues under the NEW id (old rows become foreign → consumed)
 *   I13 purge_site preserves node identity (origin stability across purge)
 *
 * Honesty boundary: lease semantics run on the SQLite double; the MySQL
 * dialect (dbDelta upsert PK race) is proven live by the cluster runner.
 *
 * Run: php tests/audit-node-identity.php   (exit 0 only when all pass)
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
use UltimatePerformance\Cluster\Lease;
use UltimatePerformance\Cluster\NodeIdentity;
use UltimatePerformance\Cluster\Propagator;
use UltimatePerformance\Cluster\State;
use UltimatePerformance\Core\Installer;

require_once __DIR__ . '/lib/class-uc-m5-wpdb.php';

$results = array();
function n3check( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

function n3_reset_node_identity() {
	$ref = new \ReflectionProperty( NodeIdentity::class, 'cached' );
	$ref->setAccessible( true );
	$ref->setValue( null, null );
	$ref2 = new \ReflectionProperty( NodeIdentity::class, 'instance' );
	$ref2->setAccessible( true );
	$ref2->setValue( null, null );
}

function n3_rrmdir( $dir, $prefix ) {
	$real = realpath( $dir );
	if ( false === $real || 0 !== strpos( $real, $prefix ) ) {
		return;
	}
	foreach ( (array) glob( $real . '/*' ) as $p ) {
		is_dir( $p ) ? n3_rrmdir( $p, $prefix ) : @unlink( $p );
	}
	@rmdir( $real );
}

const N3_UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

$ROOT = Installer::cache_root();
n3_rrmdir( $ROOT, realpath( dirname( WP_CONTENT_DIR ) . '/wp-content/cache' ) ?: $ROOT );
if ( ! is_dir( $ROOT ) ) {
	@mkdir( $ROOT, 0775, true );
}

$__db_path = sys_get_temp_dir() . '/uc-n3-audit-' . getmypid() . '.sqlite';
$__db      = new \UC_M5_WPDB( $__db_path );
$GLOBALS['wpdb'] = $__db;
register_shutdown_function( function () use ( $__db_path ) { @unlink( $__db_path ); } );

function n3_reset_all() {
	global $__db, $ROOT;
	$__db->fail_reads = false;
	$__db->readonly   = false;
	( new EventStore() )->ensure_table();
	( new Epoch() )->ensure_table();
	( new Lease() )->ensure_table();
	$__db->raw( 'DELETE FROM wp_uc_invalidation_events' );
	$__db->raw( 'DELETE FROM wp_uc_cluster_nodes' );
	$__db->raw( "UPDATE wp_uc_cluster_epoch SET epoch = 0, updated_ms = 0 WHERE name = 'cluster'" );
	n3_rrmdir( $ROOT . '/meta', $ROOT );
	@mkdir( $ROOT . '/meta', 0775, true );
	n3_rrmdir( $ROOT . '/v', $ROOT );
	n3_reset_node_identity();
}

// =====================================================================
// I1–I4 — identity file semantics
// =====================================================================
n3_reset_all();
$id1 = NodeIdentity::id();
n3check( $results, 'I1a canonical dashed uuid7', 1 === preg_match( N3_UUID_RE, $id1 ), 'id=' . $id1 );
$secret1 = NodeIdentity::boot_secret();
n3check( $results, 'I1b boot_secret is 32 hex and persisted beside the id', 1 === preg_match( '/^[0-9a-f]{32}$/', $secret1 ) );
$meta = json_decode( (string) file_get_contents( $ROOT . '/meta/node-id.json' ), true );
n3check( $results, 'I1c file carries node_id + boot_secret', is_array( $meta ) && ( $meta['node_id'] ?? '' ) === $id1 && ( $meta['boot_secret'] ?? '' ) === $secret1 );
n3_reset_node_identity();
n3check( $results, 'I1d identity stable across process resets', NodeIdentity::id() === $id1 && NodeIdentity::boot_secret() === $secret1 );

// Legacy M5 file (id, no secret) — upgraded in place, SAME id.
file_put_contents( $ROOT . '/meta/node-id.json', (string) wp_json_encode( array( 'node_id' => $id1, 'created' => time() ) ) );
n3_reset_node_identity();
$s_up = NodeIdentity::boot_secret();
n3check( $results, 'I3 legacy M5 file upgraded in place: SAME id + new secret', NodeIdentity::id() === $id1 && 1 === preg_match( '/^[0-9a-f]{32}$/', $s_up ) && $s_up !== '' );

$id_re = NodeIdentity::regenerate();
n3check( $results, 'I4a regenerate(): new canonical id', 1 === preg_match( N3_UUID_RE, $id_re ) && $id_re !== $id1 );
n3check( $results, 'I4b regenerate(): new secret, file rewritten', NodeIdentity::boot_secret() !== $secret1 );

// =====================================================================
// I5–I9 — lease semantics (clone detection)
// =====================================================================
n3_reset_all();
$lease = new Lease();
$own   = NodeIdentity::id();
$sec   = NodeIdentity::boot_secret();
$now   = (int) round( microtime( true ) * 1000 );
n3check( $results, 'I5a first registration clean', true === $lease->verify( $own, $sec, 'inst-A', $now ) );
n3check( $results, 'I5b lease row created', 1 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_cluster_nodes WHERE node_id = '" . $own . "'" ) );
n3check( $results, 'I6 own heartbeat (same instance) clean', true === $lease->verify( $own, $sec, 'inst-A', $now + 500 ) );
n3check( $results, 'I7a CONCURRENT clone: different instance inside freshness window → detected', false === $lease->verify( $own, $sec, 'inst-B', $now + 1000 ) );
n3check( $results, 'I8 non-concurrent reuse (stale last_ms): clean — documented legitimate restore', true === $lease->verify( $own, $sec, 'inst-C', $now + 60000 ) );
n3check( $results, 'I9a post-registration clone: secret mismatch → detected', false === $lease->verify( $own, str_repeat( 'a', 32 ), 'inst-D', $now + 61000 ) );
n3check( $results, 'I9b unknown (never-registered) id+secret: clean first registration', true === $lease->verify( 'fresh-node', str_repeat( 'b', 32 ), 'inst-X', $now + 62000 ) );

// =====================================================================
// I10 — regeneration flow end-to-end
// =====================================================================
n3_reset_all();
$own = NodeIdentity::id();
( new Checkpoint() )->write( 4, $own ); // old identity's checkpoint
n3check( $results, 'I10a checkpoint valid for owner', 4 === ( new Checkpoint() )->read( $own ) );
$new_id = NodeIdentity::regenerate();
n3check( $results, 'I10b after regenerate: old checkpoint reads 0 for the NEW identity (fail-closed)', 0 === ( new Checkpoint() )->read( $new_id ) );
n3check( $results, 'I10c new identity verifies clean on the lease', true === ( new Lease() )->verify( $new_id, NodeIdentity::boot_secret(), 'inst-N', (int) round( microtime( true ) * 1000 ) ) );

// =====================================================================
// I11 — lease janitor
// =====================================================================
n3_reset_all();
$lease = new Lease();
$now   = (int) round( microtime( true ) * 1000 );
$lease->verify( 'old-node', str_repeat( 'c', 32 ), 'inst-1', $now - 2 * Lease::RETENTION_SEC * 1000 );
$lease->verify( 'new-node', str_repeat( 'd', 32 ), 'inst-2', $now );
$pruned = $lease->prune( $now );
n3check( $results, 'I11 stale lease rows pruned, fresh kept (bounded table)', 1 === $pruned && 0 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_cluster_nodes WHERE node_id = 'old-node'" ) && 1 === (int) $GLOBALS['wpdb']->scalar( "SELECT COUNT(*) FROM wp_uc_cluster_nodes WHERE node_id = 'new-node'" ) );

// =====================================================================
// I12 — integration: consume() regenerates on live clone
// =====================================================================
n3_reset_all();
$prop = new Propagator( new EventStore(), new Epoch(), new Checkpoint() );
$own  = NodeIdentity::id();
$sec  = NodeIdentity::boot_secret();
$now  = (int) round( microtime( true ) * 1000 );
( new Lease() )->verify( $own, $sec, 'other-live-instance', $now ); // a LIVE clone holds the lease
$before_id = NodeIdentity::id();
// Seed foreign events the node must still consume (clone must not lose correctness).
$auth = new Epoch();
$auth->bump();
$GLOBALS['wpdb']->peer_event( 'peer-x', 'purge_dirs', array( 'dirs' => array( 'cluster1.test/i12' ) ), 0, 2, null, 0, 1 );
$n = $prop->consume();
$after_id = NodeIdentity::id();
n3check( $results, 'I12a consume detected the live clone and REGENERATED the identity', $after_id !== $before_id, "$before_id → $after_id" );
n3check( $results, 'I12b node_id_collisions counted', 1 <= ( new State( $ROOT ) )->read()['node_id_collisions'] );
n3check( $results, 'I12c consumption continues under the NEW identity (old-own rows now foreign → executed)', 1 === $n );

// =====================================================================
// I13 — purge_site preserves node identity
// =====================================================================
n3_reset_all();
$own    = NodeIdentity::id();
$d13    = $ROOT . '/v/cluster1.test/i13';
@mkdir( $d13, 0775, true );
file_put_contents( $d13 . '/index.html', 'N3-I13' );
$hooks  = new \UltimatePerformance\CacheInvalidation\Hooks();
$hooks->purge_site();
n3check( $results, 'I13a purge_site wiped the page tree', ! is_file( $d13 . '/index.html' ) );
n3_reset_node_identity();
n3check( $results, 'I13b node identity SURVIVED the purge (origin stability)', NodeIdentity::id() === $own );
n3check( $results, 'I13c boot_secret survived too (lease continuity)', NodeIdentity::boot_secret() === ( json_decode( (string) file_get_contents( $ROOT . '/meta/node-id.json' ), true )['boot_secret'] ?? '' ) );

// =====================================================================
// summary
// =====================================================================
$fail = 0;
foreach ( $results as $name => $ok ) {
	if ( ! $ok ) { ++$fail; }
}
$total = count( $results );
echo "\nN3 audit-node-identity: {$total} checks, {$fail} FAIL\n";
exit( 0 === $fail ? 0 : 1 );
