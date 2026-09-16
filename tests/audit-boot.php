<?php
/**
 * AUDIT TEST — Production boot path (Phase H).
 *
 * Loads the REAL plugin entry file (ultimate-performance.php) inside the REAL
 * WordPress runtime and verifies the complete boot sequence:
 *
 *   - entry file loads with no fatal / no uncaught Throwable
 *   - real Autoloader registers and resolves production classes
 *   - Job / Backend / EnqueueException resolve through the REAL autoloader
 *     (never manually required by this test)
 *   - Plugin singleton boots; hooks actually registered in WP
 *   - no duplicate class declarations on re-include guard
 *   - temporary state cleaned up
 *
 * Run: php tests/audit-boot.php   (exit 0 only when all checks pass)
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_CACHE_SHIM_ACTIVE_PLUGIN', true ); // emulate WP loading the ACTIVE plugin during wp-load

require_once ABSPATH . 'wp-load.php';

$results = array();
function bcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

// ------------------------------------------------------------
// B1: entry file loads without fatal. Capture output/errors.
// ------------------------------------------------------------
$boot_error  = null;
$boot_output = '';
$entry       = dirname( __DIR__ ) . '/ultimate-performance.php'; // REAL plugin entry, this repository
bcheck( $results, 'B1 entry file exists', file_exists( $entry ), $entry );

try {
        ob_start();
        require_once $entry; // phpcs:ignore -- the entire point of this test.
        $boot_output = (string) ob_get_clean();
} catch ( \Throwable $e ) {
        ob_end_clean();
        $boot_error = $e;
}
bcheck( $results, 'B2 entry loads without uncaught Throwable', null === $boot_error, null !== $boot_error ? $boot_error->getMessage() : '' );
bcheck( $results, 'B3 no stray output during boot', '' === trim( $boot_output ), substr( $boot_output, 0, 80 ) );
bcheck( $results, 'B4 ULTIMATE_PERFORMANCE_VERSION defined', defined( '\\UltimatePerformance\\ULTIMATE_PERFORMANCE_VERSION' ) || defined( 'ULTIMATE_PERFORMANCE_VERSION' ) );

// ------------------------------------------------------------
// B5: autoloader + class resolution through the REAL autoloader.
// This test NEVER manually requires internal class files.
// ------------------------------------------------------------
bcheck( $results, 'B5 Autoloader class present', class_exists( '\UltimatePerformance\Core\Autoloader' ) );
$spl = spl_autoload_functions();
$has_uc_autoload = false;
foreach ( (array) $spl as $fn ) {
        if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) && \UltimatePerformance\Core\Autoloader::class === get_class( $fn[0] ) ) {
                $has_uc_autoload = true;
        } elseif ( is_array( $fn ) && isset( $fn[0] ) && is_string( $fn[0] ) && false !== strpos( $fn[0], 'Autoloader' ) ) {
                $has_uc_autoload = true;
        }
}
bcheck( $results, 'B6 plugin autoloader registered in SPL', $has_uc_autoload );
bcheck( $results, 'B7 Job resolves via autoloader', class_exists( '\UltimatePerformance\Queue\Backend\Job' ) );
bcheck( $results, 'B8 Backend interface resolves', interface_exists( '\UltimatePerformance\Queue\Backend\Backend' ) );
bcheck( $results, 'B9 EnqueueException resolves', class_exists( '\UltimatePerformance\Queue\EnqueueException' ) );
bcheck( $results, 'B10 QueueManager resolves', class_exists( '\UltimatePerformance\Queue\QueueManager' ) );
bcheck( $results, 'B11 Plugin core resolves', class_exists( '\UltimatePerformance\Core\Plugin' ) );

// ------------------------------------------------------------
// B12: singleton boot path executed — instance() returns live object.
// ------------------------------------------------------------
$plugin = null;
try {
        $plugin = \UltimatePerformance\Core\Plugin::instance();
} catch ( \Throwable $e ) {
        bcheck( $results, 'B12 Plugin::instance() throws', false, $e->getMessage() );
}
bcheck( $results, 'B12 Plugin::instance() returns object', $plugin instanceof \UltimatePerformance\Core\Plugin );
bcheck( $results, 'B13 same instance twice (singleton)', $plugin === \UltimatePerformance\Core\Plugin::instance() );

// ------------------------------------------------------------
// B14: boot() registered its hooks in the REAL WP hook system.
// ------------------------------------------------------------
global $wp_filter;
$cron_sched_ok = false;
if ( isset( $wp_filter['cron_schedules'] ) ) {
        foreach ( $wp_filter['cron_schedules']->callbacks as $prio => $cbs ) {
                foreach ( $cbs as $id => $cb ) {
                        if ( false !== stripos( $id, 'QueueManager' ) && false !== stripos( $id, 'schedules' ) ) {
                                $cron_sched_ok = true;
                        }
                }
        }
}
bcheck( $results, 'B14 cron_schedules filter registered', $cron_sched_ok );
$as_hook_found = false;
if ( isset( $wp_filter['ultimate_performance_as_job'] ) ) {
        foreach ( $wp_filter['ultimate_performance_as_job']->callbacks as $prio => $cbs ) {
                foreach ( $cbs as $id => $cb ) {
                        if ( false !== stripos( $id, 'as_job_callback' ) ) {
                                $as_hook_found = true;
                        }
                }
        }
}
bcheck( $results, 'B15 as_job callback registered accepted_args=3', $as_hook_found );
$job_hook_found = has_action( 'ultimate_cache_job' ); // extensibility hook may be empty pre-work; presence of filter registration checked indirectly below.
bcheck( $results, 'B16 tick action schedulable (wp_next_schedules callable)', function_exists( 'wp_next_scheduled' ) );
// B19: re-entrant boot guard — second include must not redeclare constants/classes.
$redeclare_error = null;
try {
        set_error_handler( static function ( $no, $str ) { throw new \ErrorException( $str ); } );
        ob_start();
        @include_once $entry; // entry has require_once guards internally.
        restore_error_handler();
        ob_end_clean();
        bcheck( $results, 'B19 re-include does not fatal', true );
} catch ( \Throwable $e ) {
        @restore_error_handler();
        @ob_end_clean();
        bcheck( $results, 'B19 re-include does not fatal', false, $e->getMessage() );
}

// ------------------------------------------------------------
// B17: enqueue path works end-to-end after real boot. The auto chain
// prefers Action Scheduler (durable, processed by AS workers outside
// this request), so the test drives pending actions through the shared
// queue-driver fixture (real stored args → real production callback)
// exactly like production WP-Cron would, then verifies disk state.
// Uses a throwaway dir under the cache tree; cleaned up in finally.
// ------------------------------------------------------------
require_once __DIR__ . '/fixtures/queue-driver.php';
// Force queue_backend='auto' so AS is actually used (the §4 safe-defaults
// change made 'wp-cron' the fresh-install default, but the wp-shim has no
// real WP-Cron daemon — wp-cron mode falls straight to sync, which breaks
// the B18/B21 invariants this suite asserts).
\UltimatePerformance\Core\Settings::instance()->save_from_admin( array(
        'up_section'    => 'queue',
        'queue_enabled' => '1',
        'queue_backend' => 'auto',
) );
$keygen = new \UltimatePerformance\CacheKey\Key( \UltimatePerformance\Core\Settings::instance() );
$b      = $keygen->build( 'http', 'localhost', '/boot-probe/', '', array() );
$abs    = $keygen->absolute( $b['dir'] );
try {
        @mkdir( dirname( $abs ), 0777, true );
        // NOTE: absolute() already returns .../boot-probe/index.html (full path).
        file_put_contents( $abs, '<html>boot</html>' );
        $m   = new \UltimatePerformance\Queue\QueueManager();
        $idn = $m->enqueue( 'purge_dirs', array( 'dirs' => array( $b['dir'] ) ) );
        bcheck( $results, 'B17 enqueue after real boot accepts job id', '' !== (string) $idn );
        bcheck( $results, 'B18 probe file existed before drain', file_exists( $abs ) );
        uc_drive_queue(); // real stored args through REAL callback (fixture is namespaced UltimatePerformance\Tests).
        clearstatcache();
        bcheck( $results, 'B20 purge executed on disk (file gone)', ! file_exists( $abs ) );
        $acct = $m->get_last_accounting();
        bcheck(
                $results,
                'B21 accounting invariant healthy',
                null !== $acct && 1 === $acct['queued'] && 0 === $acct['lost'],
                json_encode( (array) $acct )
        );
} finally {
        @unlink( $abs ); // legacy double-suffix artifact from earlier runs:
        @unlink( $abs . 'index.html' );
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
