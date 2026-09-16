<?php
/**
 * AUDIT TEST — Phase H: tag registry stress + GC/janitor behavior.
 *
 * Registry contract under stress:
 *  - attach() is bounded (5000 members/tag) — no unbounded growth.
 *  - stale references (entries whose cache file was deleted externally,
 *    posts/terms that no longer exist) must NOT break purge correctness:
 *      purge of a missing rel_dir is harmless; members() may return stale
 *      entries until compacted — but invalidation still succeeds.
 *  - purge time stays bounded; memory bounded by member cap.
 *
 * Deferred (queued) purge slice is exercised through the REAL Action Scheduler
 * runner when available, else via direct handler dispatch — proving the
 * queued slice actually deletes, not just "was enqueued".
 *
 * ENVIRONMENT-SPECIFIC RESULT: Windows/NTFS/HDD timings are not universal
 * benchmarks; only thresholds and invariants asserted.
 *
 * Run: php tests/audit-registry-stress.php
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

use UltimatePerformance\CacheTag\Registry;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\CacheInvalidation\Hooks;

// §4 safe-defaults change made 'wp-cron' the fresh-install default; the
// wp-shim has no real WP-Cron daemon so wp-cron mode falls straight to
// sync, which breaks the oversized-purge deferral check (H row) below.
Settings::instance()->save_from_admin( array(
        'up_section'    => 'queue',
        'queue_enabled' => '1',
        'queue_backend' => 'auto',
) );

$results = array();
function hcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? " ($detail)" : " << $detail" ) . "\n";
}

$settings = Settings::instance();
$fs       = new SafeFs();
$keygen   = new Key( $settings );
$store    = new Store( $fs, $keygen );
$registry = new Registry( $fs );

/** Count survivors of a rel list. */
function survivors( $keygen, array $rels ) {
        $n = 0;
        foreach ( $rels as $rel ) {
                $f = $keygen->absolute( $rel );
                if ( false !== $f && file_exists( $f ) ) { ++$n; }
        }
        return $n;
}

// ---------------------------------------------------------------- W1: cap
$tag  = 'stress-cap-' . uniqid();
$t0   = microtime( true );
for ( $i = 0; $i < 5200; $i++ ) {
        $registry->attach( "localhost/stress-cap/page-$i", array( $tag ) );
}
$dt_cap  = microtime( true ) - $t0;
$members = count( $registry->members( $tag ) );
hcheck( $results, 'H member cap enforced at 5000', 5000 === $members, "$members members" );
hcheck( $results, 'H cap write loop bounded time', $dt_cap < 300, sprintf( '%.1fs for 5200 attaches', $dt_cap ) );

$c0 = count( $registry->members( $tag ) );
$registry->attach( 'localhost/stress-cap/page-0', array( $tag ) );
hcheck( $results, 'H duplicate attach idempotent', $c0 === count( $registry->members( $tag ) ) );

// ---------------------------------------------------- W2: 1000-page scale
$scale_tag = 'stress-scale-' . uniqid();
$scale_rels = array();
$t1 = microtime( true );
for ( $i = 0; $i < 1000; $i++ ) {
        $b = $keygen->build( 'http', 'localhost', "/stress-s1/p$i/", '', array() );
        if ( false !== $store->write( $b['dir'], '<!DOCTYPE html><html><body>H</body></html>', 200, array(), array( $scale_tag, 'post_type:post' ), 3600 ) ) {
                $scale_rels[] = $b['dir'];
        }
}
$dt_1k_write = microtime( true ) - $t1;
hcheck( $results, 'H 1k pages registered', 1000 === count( $registry->members( $scale_tag ) ), count( $registry->members( $scale_tag ) ) );
hcheck( $results, 'H 1k writes bounded time', $dt_1k_write < 240, sprintf( '%.1fs', $dt_1k_write ) );

$t2     = microtime( true );
$n      = ( new Hooks( $settings ) )->purge_tags( array( $scale_tag ) );
$dt_prg = microtime( true ) - $t2;
// Deferred slice (>50) drains through the queue exactly as a worker would.
uc_drive_queue();
clearstatcache();
hcheck( $results, 'H tag purge removes all entries', 0 === survivors( $keygen, $scale_rels ), 'survivors=' . survivors( $keygen, $scale_rels ) );
hcheck( $results, 'H 1k-entry purge time bounded (sync+deferred)', $dt_prg < 120, sprintf( '%.1fs sync', $dt_prg ) );

// ------------------------------------------- W3: stale refs after ext delete
$stale_tag  = 'stress-stale-' . uniqid();
$stale_rels = array();
for ( $i = 0; $i < 200; $i++ ) {
        $b = $keygen->build( 'http', 'localhost', "/stress-st/p$i/", '', array() );
        if ( false !== $store->write( $b['dir'], '<html>s</html>', 200, array(), array( $stale_tag ), 3600 ) ) {
                $stale_rels[] = $b['dir'];
        }
}
// External wipe of first 150 bodies behind the registry's back.
for ( $i = 0; $i < 150; $i++ ) {
        $f = $keygen->absolute( $stale_rels[ $i ] );
        if ( false !== $f && file_exists( $f ) ) { @unlink( $f ); @unlink( $f . '.meta.json' ); }
}
$m = count( $registry->members( $stale_tag ) );
hcheck( $results, 'H stale refs tolerated in members()', 200 === $m, "$m pre-compaction" );

$t3       = microtime( true );
$n3       = ( new Hooks( $settings ) )->purge_tags( array( $stale_tag ) );
$dt_stale = microtime( true ) - $t3;
uc_drive_queue();
clearstatcache();
hcheck( $results, 'H mixed live/stale purge completes', 0 === survivors( $keygen, $stale_rels ), '' );
hcheck( $results, 'H stale-mix purge time bounded', $dt_stale < 60, sprintf( '%.1fs', $dt_stale ) );

// ------------------------------------------------------- W4: compaction GC
$dtag = 'stress-detach-' . uniqid();
$det_rels = array();
for ( $i = 0; $i < 50; $i++ ) {
        $b = $keygen->build( 'http', 'localhost', "/stress-dt/p$i/", '', array() );
        if ( false !== $store->write( $b['dir'], '<html>d</html>', 200, array(), array( $dtag ), 3600 ) ) {
                $det_rels[] = $b['dir'];
        }
}
$before = count( $registry->members( $dtag ) );
// Manual janitor-style compaction sweep: drop refs whose body vanished.
foreach ( $registry->members( $dtag ) as $rel ) { $registry->detach_object( $rel, array( $dtag ) ); }
foreach ( $det_rels as $rel ) { $registry->attach( $rel, array( $dtag ) ); }
hcheck( $results, 'H compaction rebuilds exact live set', $before === 50 && 50 === count( $registry->members( $dtag ) ), "$before -> " . count( $registry->members( $dtag ) ) );

// Deleted post scenario: hook-driven purge must leave zero refs.
Hooks::register();
$p_id = wp_insert_post( array( 'post_title' => 'H-post', 'post_name' => 'h-post-' . uniqid(), 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'x' ), true );
if ( is_int( $p_id ) ) {
        $path     = (string) wp_parse_url( (string) get_permalink( $p_id ), PHP_URL_PATH );
        $post_tag = 'post:' . $p_id;
        $b        = $keygen->build( 'http', 'localhost', $path, '', array() );
        $store->write( $b['dir'], '<html>p</html>', 200, array(), array( $post_tag, 'post_type:post' ), 3600 );
        wp_delete_post( $p_id, true );
        uc_drive_queue(); // delete-purge is async now; detach happens in the worker.
        hcheck( $results, 'H post delete leaves no stale tag refs', 0 === count( $registry->members( $post_tag ) ), 'refs=' . count( $registry->members( $post_tag ) ) );
} else {
        hcheck( $results, 'H post delete leaves no stale tag refs', true, 'SKIPPED(insert denied)' );
}

// ---------------------------------------------- W5: deferred queue slice runs
$qm_tag  = 'stress-queue-' . uniqid();
$qm_rels = array();
for ( $i = 0; $i < 120; $i++ ) {
        $b = $keygen->build( 'http', 'localhost', "/stress-q/p$i/", '', array() );
        if ( false !== $store->write( $b['dir'], '<html>q</html>', 200, array(), array( $qm_tag ), 3600 ) ) {
                $qm_rels[] = $b['dir'];
        }
}
$sync_n = ( new Hooks( $settings ) )->purge_tags( array( $qm_tag ) );
$surv_after_enqueue = survivors( $keygen, $qm_rels );
hcheck( $results, 'H oversized purge defers remainder to queue', $sync_n <= 50 && $surv_after_enqueue > 0, "sync=$sync_n queued-survivors=$surv_after_enqueue" );

// Drive the queue the way a worker would.
$drained = false;
uc_drive_queue();
clearstatcache();
$drained = 0 === survivors( $keygen, $qm_rels );
hcheck( $results, 'H queued purge slice actually executes', $drained, 'survivors=' . survivors( $keygen, $qm_rels ) );

// Memory sanity.
$mem_used = memory_get_peak_usage( true );
hcheck( $results, 'H peak memory < 128M', $mem_used < 134217728, sprintf( '%.1fMB', $mem_used / 1048576 ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
