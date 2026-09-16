<?php
/**
 * N5 §19 — Cluster CLI command audit.
 *
 * Phase N §19 requires the planned WP-CLI cluster operations:
 *   wp ultimate-performance cluster status
 *   wp ultimate-performance cluster epoch
 *   wp ultimate-performance cluster events
 *   wp ultimate-performance cluster reconcile
 *
 * These reuse existing services (Epoch, Checkpoint, Propagator, State) — no
 * business logic duplication. This audit exercises the ClusterCli class
 * directly (no wp binary required for the contract).
 *
 * Run: php tests/audit-cluster-cli.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';
require_once __DIR__ . '/lib/class-uc-m5-wpdb.php';

use UltimatePerformance\Cluster\ClusterCli;
use UltimatePerformance\Cluster\Epoch;
use UltimatePerformance\Cluster\Lease;
use UltimatePerformance\Cluster\EventStore;
use UltimatePerformance\Cluster\Propagator;
use UltimatePerformance\Cluster\NodeIdentity;

$results = array();
function ccheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

// ---- setup: fresh wpdb double + fresh cache root --------------------------
$__db_path = sys_get_temp_dir() . '/uc-n5-cli-' . getmypid() . '.sqlite';
@$GLOBALS['wpdb'] = new \UC_M5_WPDB( $__db_path );
$wpdb = $GLOBALS['wpdb'];
$wpdb->base_prefix = 'wp_';
register_shutdown_function( function () use ( $__db_path ) { @unlink( $__db_path ); } );

$ROOT = \UltimatePerformance\Core\Installer::cache_root();
if ( is_dir( $ROOT ) ) {
        $rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $ROOT, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $rii as $f ) { @unlink( $f->getRealPath() ); }
        @rmdir( $ROOT );
}
@mkdir( $ROOT . '/meta/', 0775, true );

$epoch      = new Epoch();
$lease      = new Lease();
$events     = new EventStore();
$propagator = new Propagator( $events );
$state      = new \UltimatePerformance\Cluster\State( $ROOT );

$cli = new ClusterCli( $epoch, $lease, $events, $propagator, $state );

// ---- C1: status emits valid JSON with expected fields -----------------------
ob_start();
$json = $cli->status();
$out  = ob_get_clean();
$data = json_decode( $json, true );
ccheck( $results, 'C1a status emits valid JSON', is_array( $data ), 'json=' . substr( (string) $json, 0, 100 ) );
ccheck( $results, 'C1b status has node_id', isset( $data['node_id'] ) && is_string( $data['node_id'] ) && strlen( $data['node_id'] ) >= 8 );
ccheck( $results, 'C1c status has node_id_short (8 chars)', isset( $data['node_id_short'] ) && 8 === strlen( (string) $data['node_id_short'] ) );
ccheck( $results, 'C1d status has epoch (int)', isset( $data['epoch'] ) && is_int( $data['epoch'] ) );
ccheck( $results, 'C1e status has checkpoint (int)', isset( $data['checkpoint'] ) && is_int( $data['checkpoint'] ) );
ccheck( $results, 'C1f status has lease object', isset( $data['lease'] ) && is_array( $data['lease'] ) );
ccheck( $results, 'C1g status has events_pending (int)', isset( $data['events_pending'] ) && is_int( $data['events_pending'] ) );
ccheck( $results, 'C1h status has epoch_lag (int)', isset( $data['epoch_lag'] ) && is_int( $data['epoch_lag'] ) );

// ---- C2: epoch show ---------------------------------------------------------
ob_start();
$json = $cli->epoch( false );
$out  = ob_get_clean();
$data = json_decode( $json, true );
ccheck( $results, 'C2a epoch show emits valid JSON', is_array( $data ) );
ccheck( $results, 'C2b epoch show action=show', 'show' === ( $data['action'] ?? '' ) );
ccheck( $results, 'C2c epoch show has current (int)', isset( $data['current'] ) && is_int( $data['current'] ) );

// ---- C3: epoch bump ---------------------------------------------------------
$before = $epoch->current();
ob_start();
$json = $cli->epoch( true );
$out  = ob_get_clean();
$data = json_decode( $json, true );
$after = $epoch->current();
ccheck( $results, 'C3a epoch bump emits valid JSON', is_array( $data ) );
ccheck( $results, 'C3b epoch bump action=bump', 'bump' === ( $data['action'] ?? '' ) );
ccheck( $results, 'C3c epoch bump previous = before', $before === ( $data['previous'] ?? -1 ) );
ccheck( $results, 'C3d epoch bump current = after', $after === ( $data['current'] ?? -1 ) );
ccheck( $results, 'C3e epoch bump effect documented', isset( $data['effect'] ) && is_string( $data['effect'] ) );

// ---- C4: events (empty initially) -------------------------------------------
ob_start();
$json = $cli->events();
$out  = ob_get_clean();
$data = json_decode( $json, true );
ccheck( $results, 'C4a events emits valid JSON', is_array( $data ) );
ccheck( $results, 'C4b events count = 0 (fresh)', 0 === ( $data['count'] ?? -1 ) );
ccheck( $results, 'C4c events limit = 50 (bounded)', 50 === ( $data['limit'] ?? 0 ) );
ccheck( $results, 'C4d events array present', isset( $data['events'] ) && is_array( $data['events'] ) );

// ---- C5: reconcile (manual, local-only, idempotent) ------------------------
ob_start();
$json1 = $cli->reconcile();
$out1   = ob_get_clean();
ob_start();
$json2 = $cli->reconcile();
$out2   = ob_get_clean();
$d1     = json_decode( $json1, true );
$d2     = json_decode( $json2, true );
ccheck( $results, 'C5a reconcile emits valid JSON', is_array( $d1 ) );
ccheck( $results, 'C5b reconcile action=reconcile', 'reconcile' === ( $d1['action'] ?? '' ) );
ccheck( $results, 'C5c reconcile result=ok', 'ok' === ( $d1['result'] ?? '' ) );
ccheck( $results, 'C5d reconcile scope=local (NEVER every-node)', 'local' === ( $d1['scope'] ?? '' ) );
ccheck( $results, 'C5e reconcile events_processed (int)', isset( $d1['events_processed'] ) && is_int( $d1['events_processed'] ) );
ccheck( $results, 'C5f reconcile ms (int, bounded)', isset( $d1['ms'] ) && is_int( $d1['ms'] ) && $d1['ms'] >= 0 );
ccheck( $results, 'C5g reconcile idempotent (second call returns ok)', 'ok' === ( $d2['result'] ?? '' ) );

// ---- C6: secret-free — no credentials / cache contents / sensitive paths ----
// Inspect every emitted JSON for forbidden content. The field NAME
// 'boot_secret_tail' contains the word 'secret' — that's the field name,
// NOT a leaked credential. We check the VALUE only.
$all_json = $json1 . $json2 . $cli->status() . $cli->epoch( false ) . $cli->events();
$forbidden_patterns = array(
        '@wp-content/cache/ultimate-performance@',  // local cache path leak
        '@\.env\b@',
        '@wp-config\.php@',
        '@password\s*[:=]@i',
        '@apikey\s*[:=]@i',
        '@api_key\s*[:=]@i',
        '@[a-f0-9]{32}@i',  // 32-hex boot secret FULL value (tail is only 8)
);
$leaked = false;
foreach ( $forbidden_patterns as $pat ) {
        if ( preg_match( $pat, $all_json ) ) {
                $leaked = $pat;
                break;
        }
}
ccheck( $results, 'C6 secret-free: no credentials / paths / 32-hex secret in output', false === $leaked, "leaked pattern: $leaked" );

// ---- C7: boot_secret_tail is ONLY the last 8 chars (never the full secret) --
ob_start();
$sjson = $cli->status();
ob_get_clean();
$sdata = json_decode( $sjson, true );
if ( isset( $sdata['lease']['boot_secret_tail'] ) && null !== $sdata['lease']['boot_secret_tail'] ) {
        $tail = (string) $sdata['lease']['boot_secret_tail'];
        ccheck( $results, 'C7 boot_secret_tail is at most 8 chars (NEVER full secret)', strlen( $tail ) <= 8 );
} else {
        ccheck( $results, 'C7 boot_secret_tail absent (lease not registered — null is honest)', true );
}

// ---- C8: origin is shortened to 8 chars (NEVER full UUID) -------------------
// Publish an event FROM A FOREIGN ORIGIN (different node_id) so it shows
// up in pending_for() for the local node. The local node's own publishes
// are filtered out of pending_for() (they're already accounted for at
// publish time — see EventStore::pending_for).
$foreign_node_id = 'ffffffff-ffff-7fff-8fff-ffffffffffff';
$epoch_v = $epoch->current();
// Insert directly as a foreign-origin event (bypass publish() which uses
// the local node id).
$wpdb->insert(
        $wpdb->base_prefix . 'up_invalidation_events',
        array(
                'event_id'       => \UltimatePerformance\Core\Uuid7::generate(),
                'schema_version' => 2,
                'origin'         => $foreign_node_id,
                'epoch'          => (int) $epoch_v,
                'gen_epoch'      => (int) $epoch_v,
                'scope'          => 'purge:tags',
                'payload'        => '{}',
                'created'        => (int) round( microtime( true ) * 1000 ),
                'consumed'       => 0,
        ),
        array( '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%d' )
);
ob_start();
$ejson = $cli->events();
ob_get_clean();
$edata = json_decode( $ejson, true );
$origin_ok = true;
if ( isset( $edata['events'] ) && is_array( $edata['events'] ) ) {
        foreach ( $edata['events'] as $ev ) {
                $origin = (string) ( $ev['origin'] ?? '' );
                if ( strlen( $origin ) > 8 ) {
                        $origin_ok = false;
                        break;
                }
        }
}
ccheck( $results, 'C8 events origin truncated to 8 chars (NEVER full UUID published)', $origin_ok );
ccheck( $results, 'C9 events count = 1 after foreign-origin publish', 1 === ( $edata['count'] ?? 0 ), 'count=' . ( $edata['count'] ?? '?' ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
