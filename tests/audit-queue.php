<?php
/**
 * QueueManager audit — permanent Phase H regression.
 *
 * Verifies the ZERO SILENT WORK LOSS contract of the queue abstraction
 * WITHOUT any live transport: backend shadows (tests/fixtures/qm-shadows.php)
 * replace the three transport backends inside the production namespace so
 * QueueManager's real chain-walk, receipt validation, fallback and
 * accounting run unmodified against controlled behavior.
 *
 * Sections:
 *   S1  chunking A–H: lossless multiset equality, determinism, budget,
 *       order preservation, oversized single item
 *   S2  backend matrix: primary/fallback/all-dead/full-sync
 *   S3  false receipts: '' '0' 0 false null → failure, chain continues
 *   S4  partial failure isolation: success chunks never retried/duplicated
 *   S5  complete async failure → FULL synchronous processing (60..950)
 *   S6  accounting invariants
 *   S7  large payloads 60/120/200/363/364/950/5000 incl. localhost/(root)
 *       through validation → chunking → real disk purge (sync path)
 *   S8  Action Scheduler 8000-byte boundary: every chunk's encoded payload
 *       ≤ CHUNK_BYTES (proven via shadow-captured payloads)
 *   S9  WP-Cron overflow semantics: fail-not-drop at the abstraction AND
 *       with the REAL WPCron backend class
 *   S10 idempotency: double submit + duplicate delivery drain
 *   S11 failure injection matrix + work() boundedness under complete() throw
 *   S12 performance sanity (5000-item chunking)
 *
 * Documented accounting invariant (from src/Queue/QueueManager.php):
 *   submitted = queued + processed + failed + remaining
 *   lost mirrors failed (sync-executed units whose handler threw); every
 *   lost>0 is accompanied by a uc_queue_last_failure transient.
 *   Healthy completion: remaining = 0 AND lost = 0. A job id alone is never
 *   proof of success.
 *
 * Run: php tests/audit-queue.php   (exit 0 only when all checks pass)
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
// Shadows BEFORE wp-load: real transport classes must never load here.
require_once __DIR__ . '/fixtures/qm-shadows.php';
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\Queue\Backend\Job;
use UltimatePerformance\Queue\QueueManager;

$results = array();

function qcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

/** Reset all shadow state between scenarios. */
function ucq_reset() {
        $GLOBALS['UCQ_RMQ_MODE']    = 'dead';
        $GLOBALS['UCQ_AS_MODE']     = 'dead';
        $GLOBALS['UCQ_WPCRON_MODE'] = 'dead';
        unset( $GLOBALS['UCQ_SEQ'], $GLOBALS['UCQ_COMPLETE_THROW'], $GLOBALS['UCQ_CLAIMED'] );
        foreach ( array( 'rabbitmq', 'action-scheduler', 'wp-cron' ) as $b ) {
                $GLOBALS['UCQ_ENQUEUED'][ $b ] = array();
        }
        delete_transient( 'up_queue_last_failure' );
        delete_transient( 'up_queue_last_fallback' );
}

/** Fresh manager per scenario (clears dead-backend cache + sync counter). */
function ucq_manager() {
        return new QueueManager();
}

$settings = Settings::instance();
// Force queue_backend='auto' so the chain-walking logic (rabbitmq→AS→wp-cron)
// is actually exercised. The §4 safe-defaults change made 'wp-cron' the
// fresh-install default, which would short-circuit the chain probe and
// cause every S2/S3/S4 row to fall to sync mode.
$settings->save_from_admin( array(
        'up_section'    => 'queue',
        'queue_enabled' => '1',
        'queue_backend' => 'auto',
) );
$keygen   = new Key( $settings );

/** Deterministic realistic rel dirs (same charset production emits). */
function ucq_dirs( $n, $prefix = 'q' ) {
        global $keygen;
        $out = array();
        for ( $i = 0; $i < $n; ++$i ) {
                $b     = $keygen->build( 'http', 'localhost', '/' . $prefix . '/p' . $i . '/', '', array() );
                $out[] = is_array( $b ) ? $b['dir'] : '/q/p' . $i . '/';
        }
        if ( $n > 0 ) {
                $root   = $keygen->build( 'http', 'localhost', '/', '', array() );
                $out[0] = is_array( $root ) ? $root['dir'] : '(root)';
        }
        return $out;
}

// ============================================================
// S1 — CHUNKING (pure static method, no backends involved)
// ============================================================
echo "--- S1 chunking ---\n";
ucq_reset();

function ucq_chunk_invariants( &$results, $name, array $dirs, $budget = 4096 ) {
        $c1 = QueueManager::chunk_dirs( $dirs, $budget );
        $c2 = QueueManager::chunk_dirs( $dirs, $budget );

        // Deterministic: identical output on repeated calls.
        qcheck( $results, "$name deterministic", $c1 === $c2 );

        // Lossless exact multiset equality (counts matter, not just membership).
        $flat = array();
        foreach ( $c1 as $c ) { foreach ( $c as $d ) { $flat[] = $d; } }
        sort( $flat );
        $want = $dirs; sort( $want );
        qcheck( $results, "$name lossless multiset", $flat === $want, count( $flat ) . ' vs ' . count( $want ) );

        // Budget respected: multi-item chunks always within budget; single-item
        // chunks may exceed ONLY when the item itself exceeds the budget.
        $over = 0;
        foreach ( $c1 as $c ) {
                $b = strlen( (string) wp_json_encode( $c ) );
                if ( $b > $budget && count( $c ) > 1 ) { ++$over; }
        }
        // Single-item over-budget chunks must each contain an item bigger than budget.
        foreach ( $c1 as $c ) {
                $b = strlen( (string) wp_json_encode( $c ) );
                if ( $b > $budget && 1 === count( $c ) && strlen( (string) wp_json_encode( $c[0] ) ) <= $budget ) { ++$over; }
        }
        qcheck( $results, "$name budget respected", 0 === $over, "over-budget chunks: $over" );

        // Relative order preserved across concatenation.
        $seq_ok = true;
        $pos    = 0;
        foreach ( $c1 as $c ) {
                foreach ( $c as $d ) {
                        if ( ! isset( $dirs[ $pos ] ) || $d !== $dirs[ $pos ] ) { $seq_ok = false; break 2; }
                        ++$pos;
                }
        }
        qcheck( $results, "$name order preserved", $seq_ok );
}

// A. empty input
qcheck( $results, 'A empty input zero chunks', array() === QueueManager::chunk_dirs( array(), 4096 ) );
// B. one small item
ucq_chunk_invariants( $results, 'B one item', array( 'localhost/(root)' ) );
// C. multiple small items
ucq_chunk_invariants( $results, 'C many small', ucq_dirs( 40, 's1' ) );
// D/E boundary shapes: items sized so two nearly fill / exceed the budget.
ucq_chunk_invariants( $results, 'D exact boundary', array( str_repeat( 'a', 4092 ), str_repeat( 'a', 4092 ) ) );
ucq_chunk_invariants( $results, 'E over boundary', array( str_repeat( 'b', 4095 ), str_repeat( 'b', 4095 ) ) );
// F. many items
ucq_chunk_invariants( $results, 'F 500 items', ucq_dirs( 500, 'f5' ) );
// G. very large workload
ucq_chunk_invariants( $results, 'G 5000 items', ucq_dirs( 5000, 'g7' ) );
// H. oversized single item (>budget) → own chunk, verbatim.
{
        $big   = str_repeat( 'z', 6000 );
        $small = 'localhost/(root)';
        $c     = QueueManager::chunk_dirs( array( $big, $small ), 4096 );
        qcheck( $results, 'H oversized own-chunk', 2 === count( $c ) && array( $big ) === $c[0] && array( $small ) === $c[1], json_encode( array_map( 'count', $c ) ) );
        qcheck( $results, 'H oversized verbatim', $big === $c[0][0] && strlen( $c[0][0] ) === 6000 );
}

// ============================================================
// S2 — BACKEND MATRIX
// ============================================================
echo "--- S2 backend matrix ---\n";

// 2a: A succeeds → B,C untouched.
ucq_reset();
$GLOBALS['UCQ_RMQ_MODE']    = 'ok';
$GLOBALS['UCQ_AS_MODE']     = 'ok';
$GLOBALS['UCQ_WPCRON_MODE'] = 'ok';
$m                          = ucq_manager();
$id                         = $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 5, 'm1' ) ) );
$acct                       = $m->get_last_accounting();
qcheck(
        $results,
        'S2 primary success uses A only',
        '' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] )
                && 0 === count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] )
                && 0 === count( $GLOBALS['UCQ_ENQUEUED']['wp-cron'] ),
        json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) )
);
qcheck( $results, 'S2 primary success accounting queued=5', null !== $acct && 5 === $acct['queued'] && 0 === $acct['lost'], json_encode( (array) $acct ) );

// 2b: A dead → B used.
ucq_reset();
$GLOBALS['UCQ_AS_MODE'] = 'ok';
$id                     = ucq_manager()->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 5, 'm2' ) ) );
qcheck( $results, 'S2 A-dead falls to B', '' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] ) && 0 === count( $GLOBALS['UCQ_ENQUEUED']['wp-cron'] ), json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) ) );

// 2c: A+B dead → C used.
ucq_reset();
$GLOBALS['UCQ_WPCRON_MODE'] = 'ok';
$m                          = ucq_manager();
$id                         = $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 5, 'm3' ) ) );
$acct                       = $m->get_last_accounting();
qcheck( $results, 'S2 A,B-dead falls to C', '' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['wp-cron'] ), json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) ) );
qcheck( $results, 'S2 C-success lost=0', null !== $acct && 0 === $acct['lost'] && 5 === $acct['queued'], json_encode( (array) $acct ) );

// 2d: ALL async dead → full synchronous processing.
ucq_reset();
$m    = ucq_manager();
$id   = $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 12, 'm4' ) ) );
$acct = $m->get_last_accounting();
qcheck( $results, 'S2 all-dead sync processes fully', '' !== $id && null !== $acct && 12 === $acct['processed'] && 0 === $acct['queued'] && 0 === $acct['remaining'] && 0 === $acct['lost'], json_encode( (array) $acct ) );

// ============================================================
// S3 — FALSE RECEIPTS
// ============================================================
echo "--- S3 false receipts ---\n";
foreach ( array( 'empty', 'zero', 'int0', 'false', 'null' ) as $mode ) {
        ucq_reset();
        // First AS attempt returns the bad receipt; later AS attempts succeed.
        $GLOBALS['UCQ_SEQ']     = array( 'action-scheduler' => array( $mode ) );
        $GLOBALS['UCQ_AS_MODE'] = 'ok';
        $m                      = ucq_manager();
        $id                     = $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 3, 'r-' . $mode ) ) );
        $acct                   = $m->get_last_accounting();
        // Chain per unit: RMQ dead → AS attempt#1 bad receipt (rejected) →
        // WPCron dead → sync. The bad receipt must NEVER count as queued.
        $as_calls = count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] );
        qcheck(
                $results,
                "S3 receipt '$mode' rejected, unit fell to sync",
                '' !== $id && 1 === $as_calls && null !== $acct && 0 === $acct['queued'] && 3 === $acct['processed'] && 0 === $acct['lost'],
                "as=$as_calls acct=" . json_encode( $acct ?? array() )
        );
}

// ============================================================
// S4 — PARTIAL FAILURE ISOLATION
// ============================================================
echo "--- S4 partial failure isolation ---\n";
// 32 dirs of ~490 chars (validator caps dirs at 512) → greedy budgeting
// yields 4 chunks of 8 items each.
$items = array();
for ( $i = 0; $i < 32; ++$i ) {
        $pad     = max( 1, 485 - strlen( (string) $i ) );
        $items[] = 'localhost/x/p' . $i . '/' . str_repeat( 'u', $pad );
}

// 4a: ok, ok, throw, throw on AS (RMQ permanently dead).
ucq_reset();
$GLOBALS['UCQ_AS_MODE'] = 'ok';
$GLOBALS['UCQ_SEQ']     = array( 'action-scheduler' => array( 'ok', 'ok', 'throw', 'throw' ) );
$m                      = ucq_manager();
$id                     = $m->enqueue( 'purge_dirs', array( 'dirs' => $items ) );
$acct                   = $m->get_last_accounting();
$as_calls               = count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] );
qcheck(
        $results,
        'S4 each chunk attempted exactly once (no retry/dup)',
        4 === $as_calls,
        "AS attempts=$as_calls"
);
qcheck(
        $results,
        'S4 chunk3/4 fell back to sync',
        null !== $acct && 16 === $acct['queued'] && 16 === $acct['processed'] && 0 === $acct['failed'] && 0 === $acct['lost'] && 0 === $acct['remaining'],
        json_encode( (array) $acct )
);
qcheck( $results, 'S4 four attempts recorded', null !== $acct && 4 === count( $acct['attempts'] ), json_encode( $acct['attempts'] ?? array() ) );

// 4b alternating: ok, throw, ok, throw.
ucq_reset();
$GLOBALS['UCQ_AS_MODE'] = 'ok';
$GLOBALS['UCQ_SEQ']     = array( 'action-scheduler' => array( 'ok', 'throw', 'ok', 'throw' ) );
$m                      = ucq_manager();
$id                     = $m->enqueue( 'purge_dirs', array( 'dirs' => $items ) );
$acct                   = $m->get_last_accounting();
qcheck( $results, 'S4 alternate: 2 queued + 2 synced', null !== $acct && 16 === $acct['queued'] && 16 === $acct['processed'] && 0 === $acct['lost'] && 0 === $acct['remaining'], json_encode( (array) $acct ) );
qcheck( $results, 'S4 alternate: AS attempted exactly 4x', 4 === count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] ), 'attempts=' . count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] ) );

// 4c handler throws during sync fallback → explicit failed+lost+transient.
ucq_reset();
add_action( 'ultimate_cache_job', function ( $job ) {
        throw new \RuntimeException( 'ucq injected handler failure' );
}, 10, 1 );
$m    = ucq_manager();
$id   = $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 6, 'hfail' ) ) );
$acct = $m->get_last_accounting();
qcheck(
        $results,
        'S4 handler-throw explicit failed/lost',
        null !== $acct && 6 === $acct['failed'] && 6 === $acct['lost'] && 0 === $acct['processed'] && 0 === $acct['queued'] && 0 === $acct['remaining'],
        json_encode( (array) $acct )
);
qcheck( $results, 'S4 handler-throw recorded transient', false !== get_transient( 'up_queue_last_failure' ) );
remove_all_actions( 'ultimate_cache_job' );
delete_transient( 'up_queue_last_failure' );

// ============================================================
// S5 — COMPLETE ASYNC FAILURE → FULL SYNC (60/120/200/950)
// ============================================================
echo "--- S5 full sync fallback ---\n";
foreach ( array( 60, 120, 200, 950 ) as $n ) {
        ucq_reset();
        $m    = ucq_manager();
        $id   = $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( $n, "sync$n" ) ) );
        $acct = $m->get_last_accounting();
        $ok   = '' !== $id && null !== $acct
                && $acct['submitted'] === $n && $acct['processed'] === $n
                && 0 === $acct['queued'] && 0 === $acct['failed'] && 0 === $acct['remaining'] && 0 === $acct['lost'];
        qcheck( $results, "S5 n=$n full-sync submitted==processed lost=0", $ok, json_encode( (array) $acct ) );
}

// ============================================================
// S6 — ACCOUNTING INVARIANTS across scenario shapes
// ============================================================
echo "--- S6 accounting invariants ---\n";
$shapes = array();
foreach ( array( 7, 63, 250 ) as $n ) {
        // all-ok
        ucq_reset();
        $GLOBALS['UCQ_RMQ_MODE'] = 'ok';
        $m                       = ucq_manager();
        $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( $n, "acc$n" ) ) );
        $a    = $m->get_last_accounting();
        $sum  = $a['queued'] + $a['processed'] + $a['failed'] + $a['remaining'];
        qcheck( $results, "S6 all-ok n=$n invariant", $a['submitted'] === $sum && $sum > 0, json_encode( $a ) );
        // all-dead
        ucq_reset();
        $m    = ucq_manager();
        $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( $n, "acc$n" ) ) );
        $a    = $m->get_last_accounting();
        $sum  = $a['queued'] + $a['processed'] + $a['failed'] + $a['remaining'];
        qcheck( $results, "S6 all-dead n=$n invariant", $a['submitted'] === $sum && $sum > 0, json_encode( $a ) );
        // mixed via sequence
        ucq_reset();
        $GLOBALS['UCQ_RMQ_MODE'] = 'dead';
        $GLOBALS['UCQ_AS_MODE']  = 'ok';
        $GLOBALS['UCQ_SEQ']      = array( 'action-scheduler' => array_fill( 0, max( 1, (int) ceil( $n / 30 ) ), 'ok' ) );
        for ( $i = 0; $i < max( 1, (int) ceil( $n / 30 ) - 1 ); ++$i ) { $GLOBALS['UCQ_SEQ']['action-scheduler'][ $i ] = 'throw'; } // last attempt only succeeds... simpler: alternate
        $GLOBALS['UCQ_SEQ']['action-scheduler'] = array();
        $chunks_est                             = max( 1, (int) ceil( $n / 30 ) );
        for ( $i = 0; $i < $chunks_est; ++$i ) { $GLOBALS['UCQ_SEQ']['action-scheduler'][] = ( 0 === $i % 2 ) ? 'ok' : 'throw'; }
        $m    = ucq_manager();
        $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( $n, "acc$n" ) ) );
        $a    = $m->get_last_accounting();
        $sum  = $a['queued'] + $a['processed'] + $a['failed'] + $a['remaining'];
        qcheck( $results, "S6 mixed n=$n invariant", $a['submitted'] === $sum && $sum > 0, json_encode( $a ) );
}

// ============================================================
// S7 — LARGE PAYLOADS through REAL validation→chunk→handler(disk)
// ============================================================
echo "--- S7 large payloads (real disk, sync fallback) ---\n";
$fs    = new SafeFs();
$store = new Store( $fs, $keygen );
foreach ( array( 60, 120, 200, 363, 364, 950, 5000 ) as $n ) {
        ucq_reset();
        $dirs = ucq_dirs( $n, "lp$n" );
        // Seed REAL cache files for first min(n,400) entries (HDD-bounded).
        $seed_n = min( $n, 400 );
        for ( $i = 0; $i < $seed_n; ++$i ) {
                $store->write( $dirs[ $i ], "<html>$i</html>", 200, array(), array( "tag-lp$n" ), 3600 );
        }
        $m         = ucq_manager();
        $id        = $m->enqueue( 'purge_dirs', array( 'dirs' => $dirs ) );
        $acct      = $m->get_last_accounting();
        $survivors = 0;
        for ( $i = 0; $i < $seed_n; ++$i ) {
                $f = $keygen->absolute( $dirs[ $i ] );
                if ( false !== $f && file_exists( $f ) ) { ++$survivors; }
        }
        $has_root  = in_array( 'localhost/(root)', $dirs, true );
        $root_gone = ! $has_root || ! file_exists( (string) $keygen->absolute( 'localhost/(root)' ) );
        qcheck(
                $results,
                "S7 n=$n survivors=0 lost=0",
                '' !== $id && 0 === $survivors && null !== $acct && $acct['submitted'] === $n && 0 === $acct['lost'] && 0 === $acct['remaining'],
                json_encode( array( 'surv' => $survivors, 'acct' => $acct ) )
        );
        qcheck( $results, "S7 n=$n (root) purged intact", $root_gone );
}

// ============================================================
// S8 — ACTION SCHEDULER 8000-BYTE BOUNDARY (captured payloads)
// ============================================================
echo "--- S8 AS byte boundary ---\n";
ucq_reset();
$GLOBALS['UCQ_AS_MODE'] = 'ok';
$dirs950                = ucq_dirs( 950, 'bb' );
$m                      = ucq_manager();
$m->enqueue( 'purge_dirs', array( 'dirs' => $dirs950 ) );
$max_bytes  = 0;
$total_dirs = 0;
foreach ( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] as $job ) {
        $enc = strlen( (string) wp_json_encode( $job->payload ) ); // what DBStore would persist in extended_args
        $max_bytes  = max( $max_bytes, $enc );
        $total_dirs += isset( $job->payload['dirs'] ) ? count( $job->payload['dirs'] ) : 0;
}
qcheck( $results, 'S8 every chunk ≤ 4096B encoded (≪8000 ceiling)', $max_bytes > 0 && $max_bytes <= 4096, "max=$max_bytes" );
qcheck( $results, 'S8 950 dirs preserved across chunks', $total_dirs === 950, "got=$total_dirs" );

// ============================================================
// S9 — WP-CRON OVERFLOW SEMANTICS
// ============================================================
echo "--- S9 WPCron overflow ---\n";
// Abstraction level: an overflowing backend FAILS its unit; the chain
// continues; nothing is dropped.
ucq_reset();
$GLOBALS['UCQ_AS_MODE']     = 'throw';
$GLOBALS['UCQ_WPCRON_MODE'] = 'throw'; // buffer-full manifests as enqueue throw
$m                          = ucq_manager();
$id                         = $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 1000, 'ovf' ) ) );
$acct                       = $m->get_last_accounting();
qcheck(
        $results,
        'S9 overflow fail→full sync nothing dropped',
        '' !== $id && null !== $acct && 1000 === $acct['submitted'] && 1000 === $acct['processed'] && 0 === $acct['lost'],
        json_encode( (array) $acct )
);
// Real-backend level: buffer refuses entry #501 instead of dropping oldest.
// The shadow fixture occupies the WPCron classname, so the REAL backend is
// loaded under an alias namespace via eval (same source, real option store).
ucq_reset();
delete_option( 'ultimate_cache_queue_cron' );
$wc_src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/Queue/BackendImpl/WPCron.php' );
$wc_src = str_replace(
        array( 'namespace UltimatePerformance\\Queue\\BackendImpl;', 'final class WPCron' ),
        array( 'namespace UCRealBackend;', 'final class WPCronReal' ),
        $wc_src
);
eval( '?>' . $wc_src ); // phpcs:ignore -- test-only alias of production source.
$wc_cls = '\\UCRealBackend\\WPCronReal';
$wc     = new $wc_cls();
$over   = 0;
for ( $i = 0; $i < 501; ++$i ) {
        try {
                $wc->enqueue( new Job( 'purge_dirs', array( 'dirs' => array( 'localhost/ovf/p' . $i . '/' ) ), 'ovf-' . $i ) );
        } catch ( \Throwable $e ) { ++$over; }
}
qcheck( $results, 'S9 real WPCron throws at 501 (fail not drop)', 1 === $over, "throws=$over" );
$buf = get_option( 'ultimate_cache_queue_cron' );
qcheck( $results, 'S9 buffer holds exactly 500 after overflow attempt', is_array( $buf ) && 500 === count( $buf ), 'held=' . count( (array) $buf ) );
delete_option( 'ultimate_cache_queue_cron' );

// ============================================================
// S10 — IDEMPOTENCY (double submit + duplicate delivery)
// ============================================================
echo "--- S10 idempotency ---\n";
ucq_reset();
$GLOBALS['UCQ_AS_MODE'] = 'ok';
$dirs_i                 = ucq_dirs( 30, 'idem' );
$store->write( $dirs_i[5], '<html>keep</html>', 200, array(), array( 'tag-idem' ), 3600 );
$m   = ucq_manager();
$id1 = $m->enqueue( 'purge_dirs', array( 'dirs' => $dirs_i ) );
$id2 = $m->enqueue( 'purge_dirs', array( 'dirs' => $dirs_i ) );
$ok  = '' !== $id1 && '' !== $id2 && 2 === count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] );
// Duplicate delivery: hand BOTH jobs to work(); purge is unlink-based so the
// second delivery is a harmless no-op on already-deleted entries.
$GLOBALS['UCQ_CLAIMED'] = $GLOBALS['UCQ_ENQUEUED']['action-scheduler'];
$m->work( 100 );
$GLOBALS['UCQ_CLAIMED'] = $GLOBALS['UCQ_ENQUEUED']['action-scheduler'];
$m->work( 100 );
$f = $keygen->absolute( $dirs_i[5] );
$a10 = $m->get_last_accounting();
qcheck( $results, 'S10 double submit enqueues cleanly (no fatal/negative acct)', $ok && ( null === $a10 || $a10['failed'] >= 0 ), json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) ) );
qcheck( $results, 'S10 post-drain file gone, second drain no-op', false === $f || ! file_exists( (string) $f ) );

// ============================================================
// S11 — FAILURE INJECTION MATRIX
// ============================================================
echo "--- S11 failure injection ---\n";
// 11.1 malformed payloads rejected without touching any backend.
$bad_payloads = array(
        'unknown type'        => array( 'nope', array( 'dirs' => array( 'x' ) ) ),
        'bad dirs type'       => array( 'purge_dirs', array( 'dirs' => 'notarray' ) ),
        'traversal dir'       => array( 'purge_dirs', array( 'dirs' => array( '..%2fetc' ) ) ),
        'dotdot dir'          => array( 'purge_dirs', array( 'dirs' => array( 'localhost/../secrets/' ) ) ),
        'illegal charset dir' => array( 'purge_dirs', array( 'dirs' => array( 'localhost/a b' ) ) ),
        'oversize dir'        => array( 'purge_dirs', array( 'dirs' => array( str_repeat( 'a', 600 ) ) ) ),
        'url w/o scheme'      => array( 'preload_url', array( 'url' => 'example.com/x' ) ),
        'url with @'          => array( 'preload_url', array( 'url' => 'http://user@host/x' ) ),
        'huge url'            => array( 'regenerate', array( 'url' => 'http://' . str_repeat( 'a', 2100 ) ) ),
        'empty payload'       => array( 'preload_url', array() ),
);
foreach ( $bad_payloads as $label => $args ) {
        ucq_reset();
        $GLOBALS['UCQ_RMQ_MODE'] = 'ok';
        $id                      = ucq_manager()->enqueue( $args[0], $args[1] );
        qcheck( $results, "S11 reject: $label", '' === $id && 0 === count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] ) );
}

// 11.2 valid preload flows through chain normally.
ucq_reset();
$GLOBALS['UCQ_RMQ_MODE'] = 'ok';
$id                      = ucq_manager()->enqueue( 'preload_url', array( 'url' => 'http://localhost/wordpress/' ) );
qcheck( $results, 'S11 preload_url accepted via chain', '' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] ) );

// 11.3 repeated all-dead enqueues still full-sync (negative-cache path).
ucq_reset();
$m    = ucq_manager();
$m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 20, 'neg' ) ) );
$id2  = $m->enqueue( 'purge_dirs', array( 'dirs' => ucq_dirs( 20, 'neg2' ) ) );
$a11  = $m->get_last_accounting();
qcheck( $results, 'S11 repeat all-dead still full sync', '' !== $id2 && null !== $a11 && 20 === $a11['processed'] && 0 === $a11['lost'], json_encode( (array) $a11 ) );

// 11.4 completion-tracking throw inside work(): loop stays bounded, jobs
// processed, no fatal. H3 silent-loss fix: the swallowed complete() throw is
// now RECORDED via the failure transient — the lost job never vanishes.
//
// Path proof (temporary diag, deleted): with a SUCCEEDING handler, the shadow's
// complete(true) never throws (UCQ_COMPLETE_THROW only fires on !$success), so
// a healthy batch correctly leaves the transient empty. To exercise path C —
// handler fails AND completion tracking throws on the retry bookkeeping — at
// least one job must FAIL its handler. Both jobs below fail; each then hits
// complete(false) which THROWS; production catch records the failure.
ucq_reset();
delete_transient( 'up_queue_last_failure' );
$GLOBALS['UCQ_WPCRON_MODE']    = 'ok';
$GLOBALS['UCQ_COMPLETE_THROW'] = true;
$GLOBALS['UCQ_CLAIMED']        = array(
        new Job( 'purge_dirs', array( 'dirs' => array( 'localhost/ct/a/' ) ), 'ct-1' ),
        new Job( 'purge_dirs', array( 'dirs' => array( 'localhost/ct/b/' ) ), 'ct-2' ),
);
add_action( 'ultimate_cache_job', function () { throw new \RuntimeException( 's11-handler-fail' ); }, 10, 1 );
$n_done                        = ucq_manager()->work( 10 );
remove_all_actions( 'ultimate_cache_job' );
qcheck( $results, 'S11 work() bounded despite complete() throwing', 2 === $n_done, "done=$n_done" );
$ct_fail = get_transient( 'up_queue_last_failure' );
qcheck(
        $results,
        'S11 complete()-throw recorded as explicit failure (no silent loss)',
        is_array( $ct_fail ) && isset( $ct_fail['error'], $ct_fail['id'] ) && '' !== (string) $ct_fail['id'],
        json_encode( (array) $ct_fail )
);
delete_transient( 'up_queue_last_failure' );

// ============================================================
// S12 — PERFORMANCE SANITY (chunking 5000)
// ============================================================
echo "--- S12 perf sanity ---\n";
ucq_reset();
$big5k  = ucq_dirs( 5000, 'perf' );
$t0     = microtime( true );
$chunks = QueueManager::chunk_dirs( $big5k, 4096 );
$dt     = microtime( true ) - $t0;
qcheck( $results, 'S12 5000-item chunking < 5s', $dt < 5.0, sprintf( '%.3fs', $dt ) );
echo sprintf( "  [info] 5000 items -> %d chunks in %.3fs, peak %.1fMB\n", count( $chunks ), $dt, memory_get_peak_usage( true ) / 1048576 );

// ---- SUMMARY ----
echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
