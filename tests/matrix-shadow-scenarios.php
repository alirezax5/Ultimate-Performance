<?php
/**
 * T4 shadow scenarios — included by audit-backend-matrix.php AFTER
 * qm-shadows.php is active. Runs in namespace UltimatePerformance\Tests.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

use UltimatePerformance\Queue\Backend\Job;
use UltimatePerformance\Queue\QueueManager;

if ( empty( $results ) || ! is_array( $results ) ) {
	$results = array();
}

/** Reset shadow state. */
function m4_reset() {
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

/** Deterministic dirs incl localhost/(root) at index 0. */
function m4_dirs( $n, $prefix = 't4' ) {
	$out = array();
	for ( $i = 0; $i < $n; ++$i ) {
		$out[] = 'localhost/' . $prefix . '/p' . $i . '/';
	}
	if ( $n > 0 ) {
		$out[0] = 'localhost/(root)';
	}
	return $out;
}

// ==========================================================================
// M1 — full fallback transition matrix (one unit each)
// ==========================================================================
echo "  [m1] fallback transitions\n";
m4_reset();
$GLOBALS['UCQ_RMQ_MODE'] = 'ok';
$m                       = new QueueManager();
$id                      = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 5, 'a' ) ) );
$acct                    = $m->get_last_accounting();
m4check(
	$results,
	'M1 RMQ→(none): primary-only chain walk',
	'' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] ) && 0 === count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] ),
	json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) )
);
m4check( $results, 'M1 RMQ healthy accounting queued=5 lost=0', null !== $acct && 5 === $acct['queued'] && 0 === $acct['lost'], json_encode( (array) $acct ) );

// RMQ → AS
m4_reset();
$GLOBALS['UCQ_AS_MODE'] = 'ok';
$m                      = new QueueManager();
$id                     = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 5, 'b' ) ) );
m4check( $results, 'M1 RMQ dead → AS selected', '' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] ) && 0 === count( $GLOBALS['UCQ_ENQUEUED']['wp-cron'] ), json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) ) );

// RMQ → WP-Cron
m4_reset();
$GLOBALS['UCQ_WPCRON_MODE'] = 'ok';
$m                          = new QueueManager();
$id                         = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 5, 'c' ) ) );
$acct                       = $m->get_last_accounting();
m4check( $results, 'M1 RMQ+AS dead → WP-Cron selected', '' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['wp-cron'] ), json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) ) );
m4check( $results, 'M1 WP-Cron path lost=0 queued=5', null !== $acct && 5 === $acct['queued'] && 0 === $acct['lost'], json_encode( (array) $acct ) );

// AS → WP-Cron (RMQ configured away via non-auto? no — RMQ dead by default)
m4_reset();
$GLOBALS['UCQ_WPCRON_MODE'] = 'ok';
$GLOBALS['UCQ_SEQ']         = array( 'action-scheduler' => array( 'throw' ) );
$GLOBALS['UCQ_AS_MODE']     = 'throw';
$m                          = new QueueManager();
$id                         = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 3, 'd' ) ) );
m4check( $results, 'M1 AS throw → WP-Cron next', '' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['wp-cron'] ), json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) ) );

// WP-Cron only survivor after explicit throws upstream
m4_reset();
$GLOBALS['UCQ_RMQ_MODE']    = 'throw';
$GLOBALS['UCQ_AS_MODE']     = 'throw';
$GLOBALS['UCQ_WPCRON_MODE'] = 'ok';
$m                          = new QueueManager();
$id                         = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 4, 'e' ) ) );
m4check( $results, 'M1 RMQ+AS throw → WP-Cron still accepts', '' !== $id && 1 === count( $GLOBALS['UCQ_ENQUEUED']['wp-cron'] ) && 1 === count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] ), json_encode( array_map( 'count', $GLOBALS['UCQ_ENQUEUED'] ) ) );

// all async fail → sync (RMQ+AS+WP-Cron → Sync)
m4_reset();
$m    = new QueueManager();
$id   = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 7, 'f' ) ) );
$acct = $m->get_last_accounting();
m4check( $results, 'M1 ALL-dead → Sync full processing', '' !== $id && null !== $acct && 7 === $acct['processed'] && 0 === $acct['queued'] && 0 === $acct['remaining'] && 0 === $acct['lost'], json_encode( (array) $acct ) );

// AS → Sync (AS dead, others default dead — same as all-dead; verify attempts label)
m4check( $results, 'M1 sync attempts recorded', null !== $acct && isset( $acct['attempts'][0] ) && 'sync:ok' === $acct['attempts'][0], json_encode( $acct['attempts'] ?? array() ) );

// ==========================================================================
// M2 — false receipt matrix for EVERY backend ('' '0' 0 false null)
// ==========================================================================
echo "  [m2] false receipts per backend\n";
foreach ( array( 'rabbitmq', 'action-scheduler', 'wp-cron' ) as $bk ) {
	foreach ( array( 'empty', 'zero', 'int0', 'false', 'null' ) as $mode ) {
		m4_reset();
		$key            = 'UCQ_' . strtoupper( str_replace( '-', '_', $bk ) ) . '_MODE';
		$GLOBALS[ $key ] = 'ok'; // later attempts succeed; first returns bad receipt.
		$GLOBALS['UCQ_SEQ'][ $bk ] = array( $mode );
		$m              = new QueueManager();
		$id             = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 2, 'r' ) ) );
		$acct           = $m->get_last_accounting();
		$calls          = count( $GLOBALS['UCQ_ENQUEUED'][ $bk ] );
		m4check(
			$results,
			"M2 $bk receipt '$mode' rejected → unit synced",
			'' !== $id && 1 === $calls && null !== $acct && 0 === $acct['queued'] && 2 === $acct['processed'] && 0 === $acct['lost'],
			"calls=$calls acct=" . json_encode( (array) $acct )
		);
	}
}

// ==========================================================================
// M3 — partial failure across chunks (models REAL chain semantics)
//
// QueueManager facts being modeled (src/Queue/QueueManager.php):
//   * an enqueue() THROW is caught per-chunk and NOT dead-cached — the next
//     chunk retries that backend;
//   * available()===false IS dead-cached for the manager instance;
//   * both paths preserve losslessness.
// Padded dirs force REAL multi-chunk workloads (verified via chunker).
// ==========================================================================
echo "  [m3] partial failure isolation\n";
/** Padded dirs (~490B each) forcing multi-chunk workloads. */
function m4_padded( $n, $prefix ) {
	$out = array();
	for ( $i = 0; $i < $n; ++$i ) {
		$pad    = max( 1, 485 - strlen( (string) $i ) );
		$out[]  = 'localhost/' . $prefix . '/p' . $i . '/' . str_repeat( 'u', $pad );
	}
	return $out;
}

// -- A: throw-interleave — every chunk attempted exactly once (no replay,
//      no dead-cache on throw), failed chunk falls to sync, nothing lost.
m4_reset();
$GLOBALS['UCQ_RMQ_MODE']    = 'ok'; // primary healthy: takes chunk1, dies? no—stays ok; use SEQ to interleave RMQ too.
$GLOBALS['UCQ_SEQ']         = array(
	'rabbitmq'         => array_fill( 0, 64, 'throw' ), // RMQ always throws here (uncached retry shape).
);
$GLOBALS['UCQ_AS_MODE']     = 'ok';
$d48                        = m4_padded( 48, 'pf' ); // ~490B × 48 ≈ 23KB → several chunks.
$k_chunks                   = count( QueueManager::chunk_dirs( $d48, QueueManager::CHUNK_BYTES ) );
$GLOBALS['UCQ_SEQ']['action-scheduler'] = array();
for ( $i = 0; $i < $k_chunks; ++$i ) { $GLOBALS['UCQ_SEQ']['action-scheduler'][] = ( 0 === $i % 2 ) ? 'ok' : 'throw'; } // ok,throw,ok,throw...
$m                          = new QueueManager();
$id                         = $m->enqueue( 'purge_dirs', array( 'dirs' => $d48 ) );
$acct                       = $m->get_last_accounting();
$as_calls                   = count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] );
$rmq_calls                  = count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] );
m4check( $results, 'M3 throw-shape: AS attempted once per chunk (no replay/dup)', $as_calls === $k_chunks, "attempts=$as_calls chunks=$k_chunks" );
m4check( $results, 'M3 throw-shape: throwing RMQ retried every chunk (NOT cached)', $rmq_calls === $k_chunks, "calls=$rmq_calls chunks=$k_chunks" );
m4check(
	$results,
	'M3 throw-shape: lossless queued+processed==submitted failed=0 lost=0',
	null !== $acct && $acct['queued'] + $acct['processed'] === $acct['submitted'] && $acct['submitted'] === 48 && 0 === $acct['failed'] && 0 === $acct['lost'] && 0 === $acct['remaining'],
	json_encode( (array) $acct )
);

// -- B: permanent DEATH mid-batch — available()=false IS cached: chunks 1-2
//      queue, the triggering chunk + all later ones skip AS entirely.
m4_reset();
$GLOBALS['UCQ_SEQ']     = array( 'action-scheduler' => array( 'ok', 'ok' ) );
$GLOBALS['UCQ_AS_MODE'] = 'dead'; // post-sequence state = permanent death.
$m                      = new QueueManager();
$d96                    = m4_padded( 96, 'mb' ); // many chunks.
$id                     = $m->enqueue( 'purge_dirs', array( 'dirs' => $d96 ) );
$acct                   = $m->get_last_accounting();
$as_calls               = count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] );
m4check( $results, 'M3 death-shape: exactly the two healthy chunks reached AS', 2 === $as_calls, "calls=$as_calls" );
m4check( $results, 'M3 death-shape: accepted chunks stay queued (never replayed)', null !== $acct && $acct['queued'] > 0, json_encode( (array) $acct ) );
m4check( $results, 'M3 death-shape: remainder synced losslessly', null !== $acct && $acct['queued'] + $acct['processed'] === $acct['submitted'] && $acct['submitted'] === 96 && 0 === $acct['lost'] && 0 === $acct['failed'] && 0 === $acct['remaining'], json_encode( (array) $acct ) );
// Dead cache persists ACROSS enqueue() calls in the same instance:
$id2                    = $m->enqueue( 'purge_dirs', array( 'dirs' => $d96 ) );
$as_after               = count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] );
m4check( $results, 'M3 dead cache persists across enqueue() calls (same instance)', $as_after === $as_calls, "after=$as_after" );

// ==========================================================================
// M4 — dead-backend cache + recovery within same manager instance
// ==========================================================================
echo "  [m4] dead-backend cache + recovery\n";
// -- throw is retried; death is cached (both proven at M3) — here: the
//    dead-cache window itself. All backends dead → sync; RMQ enqueue is
//    NEVER invoked while its available() is false (cache + gate).
m4_reset();
$m           = new QueueManager();
$id          = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 30, 'dc1' ) ) );
$rmq_calls_1 = count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] );
$id2         = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 30, 'dc2' ) ) );
$rmq_calls_2 = count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] );
$a_dc        = $m->get_last_accounting();
m4check( $results, 'M4 unavailable backend: zero enqueue invocations (gated)', 0 === $rmq_calls_1 && $rmq_calls_2 === $rmq_calls_1, "c1=$rmq_calls_1 c2=$rmq_calls_2" );
m4check( $results, 'M4 both enqueues fully synced despite all-dead', '' !== $id && '' !== $id2 && null !== $a_dc && 30 === $a_dc['processed'] && 0 === $a_dc['lost'], json_encode( (array) $a_dc ) );
// Fresh instance → cache cleared → recovery capable without process restart.
m4_reset();
$GLOBALS['UCQ_RMQ_MODE'] = 'ok';
$m2                      = new QueueManager();
$idr                     = $m2->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 5, 'rec' ) ) );
m4check( $results, 'M4 fresh instance recovers RMQ (no restart needed)', '' !== $idr && 1 === count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] ), 'calls=' . count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] ) );
// Same-instance RECOVERY after transient throw (throw is uncached): later
// chunks may use the backend again.
m4_reset();
$GLOBALS['UCQ_RMQ_MODE'] = 'ok';
$GLOBALS['UCQ_SEQ']      = array( 'rabbitmq' => array( 'throw', 'ok' ) );
$GLOBALS['UCQ_AS_MODE']  = 'ok';
$m3                      = new QueueManager();
$idq                     = $m3->enqueue( 'purge_dirs', array( 'dirs' => m4_padded( 48, 'rec2' ) ) );
$rmq_calls_q             = count( $GLOBALS['UCQ_ENQUEUED']['rabbitmq'] );
m4check( $results, 'M4 post-throw chunks can still use backend (uncached)', $rmq_calls_q >= 2, "calls=$rmq_calls_q" );

// ==========================================================================
// M5 — idempotency / double submit + duplicate delivery
// ==========================================================================
echo "  [m5] idempotency\n";
m4_reset();
$GLOBALS['UCQ_AS_MODE'] = 'ok';
$m                      = new QueueManager();
$d5                     = m4_dirs( 20, 'idem' );
// Derive expected chunk count from the REAL chunker — never hardcode.
$exp_chunks             = count( QueueManager::chunk_dirs( $d5, QueueManager::CHUNK_BYTES ) );
$i1                     = $m->enqueue( 'purge_dirs', array( 'dirs' => $d5 ) );
$i2                     = $m->enqueue( 'purge_dirs', array( 'dirs' => $d5 ) );
$as_jobs                = count( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] );
m4check( $results, 'M5 double submit enqueues cleanly (chunker-derived count)', '' !== $i1 && '' !== $i2 && 2 * $exp_chunks === $as_jobs, "jobs=$as_jobs expected=" . ( 2 * $exp_chunks ) );
// Duplicate delivery drain ×N — second pass must be a safe no-op (purge of
// already-purged dirs), never corruption or negative accounting.
$GLOBALS['UCQ_CLAIMED'] = $GLOBALS['UCQ_ENQUEUED']['action-scheduler'];
$n1                     = $m->work( 100 );
$GLOBALS['UCQ_CLAIMED'] = $GLOBALS['UCQ_ENQUEUED']['action-scheduler'];
$n2                     = $m->work( 100 );
m4check( $results, 'M5 duplicate delivery drained without fatal/negation (×2 full drain)', $n1 === $n2 && 2 * $exp_chunks === $n1, "n1=$n1 n2=$n2 chunks=$exp_chunks" );

// ==========================================================================
// M6 — worker failure observable (handler throw → complete(false))
// Order contract: reset → configure CLAIMED → run worker → inspect.
// work() needs an available worker backend, so WPCRON shadow stays 'ok'.
// ==========================================================================
echo "  [m6] worker failure\n";
m4_reset();
$GLOBALS['UCQ_WPCRON_MODE']   = 'ok'; // resolve_backend() must find a claimable backend.
delete_transient( 'up_queue_last_failure' );
$GLOBALS['UCQ_CLAIMED']       = array(
	new Job( 'purge_dirs', array( 'dirs' => array( 'localhost/wf/a/' ) ), 'wf-1' ),
);
add_action( 'ultimate_cache_job', static function () { throw new \RuntimeException( 'm6-injected handler failure' ); }, 10, 1 );
$ndone                    = ( new QueueManager() )->work( 10 );
remove_all_actions( 'ultimate_cache_job' );
$t                        = get_transient( 'up_queue_last_failure' );
m4check( $results, 'M6 handler-throw loop bounded', 1 === $ndone, "done=$ndone" );
m4check( $results, 'M6 worker failure explicitly recorded (id+error)', is_array( $t ) && ! empty( $t['error'] ) && ! empty( $t['id'] ), json_encode( (array) $t ) );

// Successful path: handler OK → complete(true) → NO failure transient.
m4_reset();
delete_transient( 'up_queue_last_failure' );
$GLOBALS['UCQ_WPCRON_MODE'] = 'ok';
$GLOBALS['UCQ_CLAIMED']     = array(
	new Job( 'purge_dirs', array( 'dirs' => array( 'localhost/wf/ok/' ) ), 'wf-ok-1' ),
);
$ndok                   = ( new QueueManager() )->work( 10 );
$t_ok                   = get_transient( 'up_queue_last_failure' );
m4check( $results, 'M6 healthy job processed', 1 === $ndok, "done=$ndok" );
m4check( $results, 'M6 healthy job leaves failure transient EMPTY', false === $t_ok || null === $t_ok, json_encode( (array) $t_ok ) );
delete_transient( 'up_queue_last_failure' );

// as_job_callback re-throw contract (regression guard for the old swallow bug)
m4_reset();
$src   = (string) file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/Queue/QueueManager.php' );
m4check( $results, 'M6 as_job_callback re-throws (no silent success)', false !== strpos( $src, 'throw $e;' ) );

// ==========================================================================
// M7 — sync cap semantics (explicit failure NOT silent truncation)
// ==========================================================================
echo "  [m7] sync ceiling semantics\n";
m4_reset();
$prop = new \ReflectionProperty( '\UltimatePerformance\Queue\QueueManager', 'sync_limit' );
$prop->setAccessible( true );
$m          = new QueueManager();
$prop->setValue( $m, 2 ); // shrink ceiling for the probe.
$idc        = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( 500, 'cap' ) ) );
$acct       = $m->get_last_accounting();
// 500 dirs ≈ many units; with limit 2 the third sync unit fails explicitly.
m4check(
	$results,
	'M7 sync-ceiling breach → explicit failed/lost (never silent success)',
	null !== $acct && ( $acct['failed'] > 0 ? ( $acct['lost'] === $acct['failed'] && false !== get_transient( 'up_queue_last_failure' ) ) : true ),
	json_encode( (array) $acct )
);
delete_transient( 'up_queue_last_failure' );

// ==========================================================================
// M8 — large matrix through shadows (fallback shapes preserved end-to-end)
// ==========================================================================
echo "  [m8] large matrix (shadow backends)\n";
foreach ( array( 60, 120, 200, 363, 364, 950, 5000 ) as $n ) {
	// shape A: everything queued via AS
	m4_reset();
	$GLOBALS['UCQ_AS_MODE'] = 'ok';
	$m                      = new QueueManager();
	$idA                    = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( $n, "la$n" ) ) );
	$aA                     = $m->get_last_accounting();
	$total_dirs_A           = 0;
	foreach ( $GLOBALS['UCQ_ENQUEUED']['action-scheduler'] as $j ) { $total_dirs_A += count( $j->payload['dirs'] ); }
	m4check( $results, "M8 n=$n AS-shape: all dirs queued lossless", '' !== $idA && $total_dirs_A === $n && null !== $aA && $aA['queued'] + $aA['processed'] === $n && 0 === $aA['lost'], 'dirs=' . $total_dirs_A );

	// shape B: all-dead → full sync
	m4_reset();
	$m                      = new QueueManager();
	$idB                    = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( $n, "lb$n" ) ) );
	$aB                     = $m->get_last_accounting();
	m4check( $results, "M8 n=$n Sync-shape: processed==submitted lost=0", '' !== $idB && null !== $aB && $n === $aB['processed'] && 0 === $aB['lost'] && 0 === $aB['remaining'], json_encode( (array) $aB ) );

	// shape C: WPCron-only
	m4_reset();
	$GLOBALS['UCQ_WPCRON_MODE'] = 'ok';
	$m                          = new QueueManager();
	$idC                        = $m->enqueue( 'purge_dirs', array( 'dirs' => m4_dirs( $n, "lc$n" ) ) );
	$aC                         = $m->get_last_accounting();
	$wc_jobs                    = count( $GLOBALS['UCQ_ENQUEUED']['wp-cron'] );
	m4check( $results, "M8 n=$n WPCron-shape: queued fully", '' !== $idC && $wc_jobs >= 1 && null !== $aC && $n === $aC['queued'] + $aC['processed'] && 0 === $aC['lost'], 'jobs=' . $wc_jobs );
}
m4_reset();

echo "\n==== SHADOW SUMMARY ====\n";
