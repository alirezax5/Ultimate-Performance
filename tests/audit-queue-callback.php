<?php
/**
 * AUDIT TEST — Phase H: Action Scheduler callback failure semantics.
 *
 * Proves the REAL production lifecycle when a queued job's handler throws:
 *
 *   as_job_callback() must NOT swallow handler exceptions. Swallowing lets
 *   Action Scheduler mark the action complete → silent loss / false success.
 *   Re-throwing lets the REAL runner mark the action FAILED with a log entry
 *   (verified against the bundled WooCommerce AS store) — observable truth.
 *
 * Uses the real AS store against the real DB. No shadows.
 *
 * Run: php tests/audit-queue-callback.php  (exit 0 only when all pass)
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once __DIR__ . '/fixtures/queue-driver.php'; // uc_drive_queue()
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Queue\QueueManager;

$results = array();
function ccheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$store = \ActionScheduler_Store::instance();

// ------------------------------------------------------------
// C1: spread shape (do_action_ref_array style: type, payload, id).
// Handler throws → as_job_callback must rethrow.
// ------------------------------------------------------------
add_action( 'ultimate_cache_job', function () { throw new \RuntimeException( 'cb-spread-fail' ); }, 10, 1 );
$id1 = \as_enqueue_async_action(
	'ultimate_performance_as_job',
	array( 'type' => 'purge_dirs', 'payload' => array( 'dirs' => array( 'localhost/cb/spread/' ) ), 'id' => 'cb-spread-1' ),
	'ultimate-performance'
);
ccheck( $results, 'C1 enqueued', is_int( $id1 ) && $id1 > 0, var_export( $id1, true ) );
$action1 = $store->fetch_action( (int) $id1 );
$a1      = $action1->get_args();
$thrown1 = false;
try {
	QueueManager::as_job_callback( (string) $a1['type'], (array) $a1['payload'], (string) $a1['id'] );
} catch ( \Throwable $e ) {
	$thrown1 = true;
}
ccheck( $results, 'C1 handler exception propagates out of as_job_callback', $thrown1 );
remove_all_actions( 'ultimate_cache_job' );

// ------------------------------------------------------------
// C2: single-array delivery shape (defensive branch).
// ------------------------------------------------------------
$id2 = \as_enqueue_async_action(
	'ultimate_performance_as_job',
	array( array( 'type' => 'purge_dirs', 'payload' => array( 'dirs' => array( 'localhost/cb/single/' ) ), 'id' => 'cb-single-2' ) ),
	'ultimate-performance'
);
ccheck( $results, 'C2 enqueued (single-array args)', is_int( $id2 ) && $id2 > 0, var_export( $id2, true ) );
$a2      = $store->fetch_action( (int) $id2 )->get_args();
$single  = ( isset( $a2[0] ) && is_array( $a2[0] ) && isset( $a2[0]['type'] ) ) ? $a2[0] : array();
$thrown2 = false;
if ( $single ) {
	// Fresh throwing handler for THIS call (C1's was already removed).
	add_action( 'ultimate_cache_job', function () { throw new \RuntimeException( 'cb-single-fail' ); }, 10, 1 );
	try {
		QueueManager::as_job_callback( $single );
	} catch ( \Throwable $e ) {
		$thrown2 = true;
	}
	remove_all_actions( 'ultimate_cache_job' );
}
ccheck(
	$results,
	'C2 single-array shape propagates handler failure too',
	$single && $thrown2,
	'args=' . substr( json_encode( $a2 ), 0, 120 )
);
remove_all_actions( 'ultimate_cache_job' );

// ------------------------------------------------------------
// C3: full runner path — rethrow makes the REAL runner mark FAILED.
// ------------------------------------------------------------
add_action( 'ultimate_cache_job', function () { throw new \RuntimeException( 'cb-runner-fail' ); }, 10, 1 );
$id3 = \as_enqueue_async_action(
	'ultimate_performance_as_job',
	array( 'type' => 'purge_dirs', 'payload' => array( 'dirs' => array( 'localhost/cb/runner/' ) ), 'id' => 'cb-runner-3' ),
	'ultimate-performance'
);
ccheck( $results, 'C3 enqueued for runner pass', is_int( $id3 ) && $id3 > 0, var_export( $id3, true ) );
uc_drive_queue(); // real stored args through real callback; AS store marks failed on throw
sleep( 1 );
$status3 = $store->get_status( (int) $id3 );
ccheck( $results, 'C3 runner marks action FAILED (not false-complete)', 'failed' === $status3, "status=$status3" );
global $wpdb;
$log3 = $wpdb->get_results( $wpdb->prepare( "SELECT message FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id=%d ORDER BY log_id DESC LIMIT 2", (int) $id3 ) );
$has_fail_log = false;
foreach ( (array) $log3 as $l ) { if ( false !== strpos( (string) $l->message, 'failed' ) ) { $has_fail_log = true; } }
ccheck( $results, 'C3 failure visible in AS logs', $has_fail_log, json_encode( wp_list_pluck( (array) $log3, 'message' ) ) );
remove_all_actions( 'ultimate_cache_job' );

// ------------------------------------------------------------
// C4: healthy handler completes normally (control).
// ------------------------------------------------------------
$keygen_ctl = new \UltimatePerformance\CacheKey\Key( \UltimatePerformance\Core\Settings::instance() );
$b3         = $keygen_ctl->build( 'http', 'localhost', '/cb/control/', '', array() );
$fs3        = new \UltimatePerformance\Core\SafeFs();
( new \UltimatePerformance\PageCache\Store( $fs3, $keygen_ctl ) )->write( $b3['dir'], '<html>ctl</html>', 200, array(), array(), 3600 );
ccheck( $results, 'C4 control file exists pre-drain', file_exists( (string) $keygen_ctl->absolute( $b3['dir'] ) ) );
$id4 = \as_enqueue_async_action(
	'ultimate_performance_as_job',
	array( 'type' => 'purge_dirs', 'payload' => array( 'dirs' => array( $b3['dir'] ) ), 'id' => 'cb-control-4' ),
	'ultimate-performance'
);
uc_drive_queue();
sleep( 1 );
$status4 = $store->get_status( (int) $id4 );
ccheck( $results, 'C4 control action COMPLETE', 'complete' === $status4, "status=$status4" );
ccheck( $results, 'C4 control file purged by handler', ! file_exists( (string) $keygen_ctl->absolute( $b3['dir'] ) ) );

// ------------------------------------------------------------
// C5: malformed/poison payload — dropped without fatal, no crash.
// ------------------------------------------------------------
$id5 = \as_enqueue_async_action(
	'ultimate_performance_as_job',
	array( 'type' => 'purge_dirs', 'payload' => array( 'evil' => "\xff\xfe" ), 'id' => 'cb-poison-5' ),
	'ultimate-performance'
);
uc_drive_queue();
sleep( 1 );
$status5 = $store->get_status( (int) $id5 );
ccheck( $results, 'C5 poison payload no fatal; terminal state recorded', 'failed' === $status5 || 'complete' === $status5, "status=$status5" );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
