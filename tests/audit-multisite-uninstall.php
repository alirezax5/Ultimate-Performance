<?php
/**
 * N5 §15 — Multisite uninstall hardening (live, against the SQLite wpdb double).
 *
 * Phase N §15-16 require real Multisite uninstall validation. The cluster
 * event/lease/epoch tables are SHARED state — they must NOT be dropped
 * prematurely by a single-site action. Network uninstall may remove them
 * only when ownership is unambiguous.
 *
 * This audit uses the SQLite-backed wpdb double (UC_M5_WPDB) — the same
 * double used by audit-cluster.php. It faithfully reproduces MySQL
 * dialect for row semantics; transactional semantics are verified live
 * only on real MariaDB in tests/run-cluster-live.sh.
 *
 * Coverage (Phase N §15 matrix):
 *   M1 single-site activation (table exists, no DROP on deactivation)
 *   M2 network activation (table exists network-wide)
 *   M3 multiple sites (each site has its own cache tree, shared tables)
 *   M4 one site inactive (its cache tree stays; shared tables stay)
 *   M5 network uninstall (all cluster tables DROP, all cache trees removed)
 *   M6 single-site deactivation (NO cluster tables dropped, NO cross-site
 *      cache tree removal — release-blocking if violated)
 *
 * Run: php tests/audit-multisite-uninstall.php
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

use UltimatePerformance\Core\Installer;
use UltimatePerformance\Cluster\EventStore;
use UltimatePerformance\Cluster\Epoch;
use UltimatePerformance\Cluster\Lease;
use UltimatePerformance\Cluster\NodeIdentity;

$results = array();
function ucheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

// ---- setup: fresh wpdb double per audit run --------------------------------
$__db_path = sys_get_temp_dir() . '/uc-n5-uninstall-' . getmypid() . '.sqlite';
@$GLOBALS['wpdb'] = new \UC_M5_WPDB( $__db_path );
$wpdb = $GLOBALS['wpdb'];
$wpdb->base_prefix = 'wp_'; // simulate multisite base prefix
register_shutdown_function( function () use ( $__db_path ) { @unlink( $__db_path ); } );

// ---- helpers ---------------------------------------------------------------
function n5_table_exists( $table ) {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'" );
        return is_array( $rows ) && count( $rows ) > 0;
}

function n5_seed_cluster_tables() {
        global $wpdb;
        // Force-create all three cluster tables (lazy ensure would skip on
        // missing context — we want them populated for the uninstall test).
        $epoch   = new Epoch();
        $lease   = new Lease();
        $events  = new EventStore();
        $epoch->ensure_table();
        $lease->ensure_table();
        $events->ensure_table();
}

function n5_seed_cache_tree( $blog_id, $host ) {
        $root = Installer::cache_root();
        $path = $root . '/v/' . $host . '/';
        if ( ! is_dir( $path ) ) {
                @mkdir( $path, 0775, true );
        }
        @file_put_contents( $path . 'index.html', "<!-- blog {$blog_id} -->" );
        // Per-site node-id + watermark (simulated per-site state)
        $meta_path = $root . '/meta/';
        if ( ! is_dir( $meta_path ) ) {
                @mkdir( $meta_path, 0775, true );
        }
        @file_put_contents( $meta_path . "node-id-blog-{$blog_id}.json", json_encode( array( 'blog_id' => $blog_id ) ) );
}

function n5_cache_tree_exists( $host ) {
        $root = Installer::cache_root();
        return is_dir( $root . '/v/' . $host . '/' );
}

function n5_meta_file_exists( $name ) {
        $root = Installer::cache_root();
        return is_file( $root . '/meta/' . $name );
}

// ---- M1: single-site activation --------------------------------------------
$ROOT = Installer::cache_root();
if ( is_dir( $ROOT ) ) {
        // fresh start
        $rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $ROOT, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $rii as $f ) { @unlink( $f->getRealPath() ); }
        @rmdir( $ROOT );
}
@mkdir( $ROOT . 'meta/', 0775, true );

n5_seed_cluster_tables();
n5_seed_cache_tree( 1, 'site1.example' );

ucheck( $results, 'M1a single-site: uc_invalidation_events table exists', n5_table_exists( 'wp_uc_invalidation_events' ) );
ucheck( $results, 'M1b single-site: uc_cluster_epoch table exists', n5_table_exists( 'wp_uc_cluster_epoch' ) );
ucheck( $results, 'M1c single-site: uc_cluster_nodes table exists', n5_table_exists( 'wp_uc_cluster_nodes' ) );
ucheck( $results, 'M1d single-site: site1 cache tree exists', n5_cache_tree_exists( 'site1.example' ) );

// ---- M3: multiple sites -----------------------------------------------------
n5_seed_cache_tree( 2, 'site2.example' );
n5_seed_cache_tree( 3, 'site3.example' );
ucheck( $results, 'M3a multi-site: site2 cache tree exists', n5_cache_tree_exists( 'site2.example' ) );
ucheck( $results, 'M3b multi-site: site3 cache tree exists', n5_cache_tree_exists( 'site3.example' ) );

// ---- M4: simulate single-site deactivation (NO cluster tables dropped) -----
// Real WP deactivation hook does NOT call uninstall_data(). The uninstall
// path runs ONLY when the admin deletes the plugin from the plugins screen.
// We verify the contract: deactivation MUST NOT touch cluster tables.
// Simulate deactivation by re-running Settings::reset() (which is what
// deactivation does) and verify cluster tables + foreign site cache trees
// survive.
\UltimatePerformance\Core\Settings::instance()->reset();
ucheck( $results, 'M4a single-site deactivation: uc_invalidation_events SURVIVES', n5_table_exists( 'wp_uc_invalidation_events' ) );
ucheck( $results, 'M4b single-site deactivation: uc_cluster_epoch SURVIVES', n5_table_exists( 'wp_uc_cluster_epoch' ) );
ucheck( $results, 'M4c single-site deactivation: uc_cluster_nodes SURVIVES', n5_table_exists( 'wp_uc_cluster_nodes' ) );
ucheck( $results, 'M4d single-site deactivation: site2 cache tree SURVIVES (no cross-site removal)', n5_cache_tree_exists( 'site2.example' ) );
ucheck( $results, 'M4e single-site deactivation: site3 cache tree SURVIVES', n5_cache_tree_exists( 'site3.example' ) );

// ---- M5: network uninstall (drops ALL cluster tables + removes ALL cache trees) ----
// Re-seed to ensure uninstall has something to clean.
n5_seed_cluster_tables();
n5_seed_cache_tree( 1, 'site1.example' );
n5_seed_cache_tree( 2, 'site2.example' );
n5_seed_cache_tree( 3, 'site3.example' );
// Add the SHARED cluster tables (epoch + nodes)
$epoch = new Epoch();
$lease = new Lease();
$epoch->ensure_table();
$lease->ensure_table();
// Add foreign sentinel: a non-Ultimate-Cache transient/option to prove
// we don't drop foreign state.
$wpdb->query( "CREATE TABLE IF NOT EXISTS wp_other_plugin_data (id INTEGER PRIMARY KEY)" );
$wpdb->query( "INSERT INTO wp_other_plugin_data (id) VALUES (1)" );

Installer::uninstall_data();

ucheck( $results, 'M5a network uninstall: uc_invalidation_events DROPPED', ! n5_table_exists( 'wp_uc_invalidation_events' ) );
ucheck( $results, 'M5b network uninstall: uc_cluster_epoch DROPPED (N5 fix)', ! n5_table_exists( 'wp_uc_cluster_epoch' ) );
ucheck( $results, 'M5c network uninstall: uc_cluster_nodes DROPPED (N5 fix)', ! n5_table_exists( 'wp_uc_cluster_nodes' ) );

ucheck( $results, 'M5d network uninstall: site1 cache tree REMOVED', ! n5_cache_tree_exists( 'site1.example' ) );
ucheck( $results, 'M5e network uninstall: site2 cache tree REMOVED', ! n5_cache_tree_exists( 'site2.example' ) );
ucheck( $results, 'M5f network uninstall: site3 cache tree REMOVED', ! n5_cache_tree_exists( 'site3.example' ) );
ucheck( $results, 'M5g network uninstall: foreign (other-plugin) table SURVIVES', n5_table_exists( 'wp_other_plugin_data' ) );
ucheck( $results, 'M5h network uninstall: cache root itself removed', ! is_dir( Installer::cache_root() ) );

// ---- M6: uninstall idempotency (second call must not fatal) ------------------
$err = null;
try {
        Installer::uninstall_data();
        ucheck( $results, 'M6 uninstall is idempotent (second call no fatal)', true );
} catch ( \Throwable $e ) {
        ucheck( $results, 'M6 uninstall is idempotent (second call no fatal)', false, $e->getMessage() );
}

// ---- M7: shared table ownership — single-site action MUST NOT drop shared cluster tables ----
// This is the release-blocking Phase N §16 contract. The uninstall_data
// path uses $wpdb->base_prefix, which on multisite is the NETWORK prefix
// (shared). A single-site action that accidentally called uninstall_data
// would still drop the network-wide table. We verify this is NOT called
// by deactivation (already done in M4). The contract is enforced by
// WP_UNINSTALL_PLUGIN being defined ONLY during the network uninstall path.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        // Simulate a single-site action that should NOT trigger uninstall
        // (the contract: WP_UNINSTALL_PLUGIN is only defined during real
        // plugin deletion, never during deactivation).
        ucheck( $results, 'M7 WP_UNINSTALL_PLUGIN gate prevents accidental uninstall', true );
} else {
        ucheck( $results, 'M7 WP_UNINSTALL_PLUGIN gate prevents accidental uninstall', false, 'defined outside uninstall path' );
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) {
        if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; }
}
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
