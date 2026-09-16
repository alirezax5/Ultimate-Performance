<?php
/**
 * AUDIT TEST — WP-Cron scheduler + telemetry (Phase K).
 *
 *   C1  off-peak window: next occurrence lands inside the site-local
 *       [01:00,05:00) window, timezone-aware, never in the past
 *   C2  jitter: two computations differ (no fixed second)
 *   C3  dedup: register() is idempotent — no double-booking
 *   C4  dispatch: due hooks run (injected jobs), then self-reschedule
 *   C5  backpressure: a held per-hook lock SKIPS the run, still re-books
 *   C6  budget/locks: lock dir used; lock released after run
 *   C7  janitor integration: default job sweeps expired rows from a REAL
 *       SQLite DB at the plugin default path
 *   M1  telemetry fixed schema (unknown keys never leak in)
 *   M2  telemetry enum labels: hostile backend names collapse to 'none'
 *   M3  prometheus exposition: fixed metric names, enum-only labels,
 *       no paths/URLs/credentials
 *   M4  snapshot write: atomic file, 0640, valid JSON, schema=2 (M5 §6
 *       added the six cluster counters — schema evolution, not a weakening)
 *
 * Run: php tests/audit-scheduler-telemetry.php
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Core\Scheduler;
use UltimatePerformance\Core\Telemetry;
use UltimatePerformance\Core\Lock\FileLock;
use UltimatePerformance\ObjectCache\SqliteBackend;

$results = array();
function ccheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

// Deterministic shim state: wipe the cron store, set a real IANA timezone.
@unlink( ABSPATH . 'state/cron.json' );
update_option( 'timezone_string', 'Asia/Tehran' );

$sched = new Scheduler();

// =====================================================================
// C1/C2: off-peak + timezone + jitter.
// =====================================================================
$before_window = mktime( 12, 0, 0, 6, 1, 2026 ); // 12:00 Tehran time — outside window
$ts1 = $sched->next_offpeak( $before_window );
$ts2 = $sched->next_offpeak( $before_window );
$local_hour = (int) ( new \DateTime( '@' . $ts1 ) )->setTimezone( new \DateTimeZone( 'Asia/Tehran' ) )->format( 'G' );
$local_min  = (int) ( new \DateTime( '@' . $ts1 ) )->setTimezone( new \DateTimeZone( 'Asia/Tehran' ) )->format( 'i' );
// M1-T1 (real-WP matrix session): the window is the documented [01:00,05:00)
// — full-jitter tomorrow rolls anywhere inside it, including 04:00:01+ (the
// old assertion rejected those VALID occurrences and flaked ~1 run in 4
// whenever the jitter landed in the 04:xx hour). Aligned with the contract.
ccheck( $results, 'C1 before-window: lands inside local [01:00,05:00)', $local_hour >= 1 && $local_hour <= 4, 'hour=' . $local_hour );
ccheck( $results, 'C1 never in the past', $ts1 > $before_window );
ccheck( $results, 'C2 jitter: two computations differ', $ts1 !== $ts2 );

$inside_window = ( new \DateTime( '2026-06-01 02:30:00', new \DateTimeZone( 'Asia/Tehran' ) ) )->getTimestamp();
$ts3 = $sched->next_offpeak( $inside_window );
ccheck( $results, 'C1 inside-window: stays today, never in the past', $ts3 > $inside_window && $ts3 - $inside_window < 4 * 3600, 'delta=' . ( $ts3 - $inside_window ) );

$after_window = ( new \DateTime( '2026-06-01 10:00:00', new \DateTimeZone( 'Asia/Tehran' ) ) )->getTimestamp();
$ts4 = $sched->next_offpeak( $after_window );
$tomorrow_ok = (int) ( new \DateTime( '@' . $ts4 ) )->setTimezone( new \DateTimeZone( 'Asia/Tehran' ) )->format( 'j' ) === 2;
ccheck( $results, 'C1 after-window: rolls to tomorrow', $tomorrow_ok && $ts4 > $after_window );

// Invalid tz option falls back safely.
update_option( 'timezone_string', '../../etc/passwd' );
ccheck( $results, 'C1 hostile timezone_string falls back (never fatal)', is_int( $sched->next_offpeak( $before_window ) ) );
update_option( 'timezone_string', 'Asia/Tehran' );

// =====================================================================
// C3: dedup.
// =====================================================================
$first  = $sched->register( $before_window );
$second = $sched->register( $before_window + 1 );
ccheck( $results, 'C3 first register books all hooks', count( $first ) === 3, 'got=' . count( $first ) );
ccheck( $results, 'C3 second register is a no-op (dedup)', 0 === count( $second ) );
$store = is_file( ABSPATH . 'state/cron.json' ) ? json_decode( (string) file_get_contents( ABSPATH . 'state/cron.json' ), true ) : array();
$dup_free = true;
foreach ( (array) $store as $hook => $occ ) {
        if ( is_array( $occ ) && count( $occ ) > 1 ) { $dup_free = false; }
}
ccheck( $results, 'C3 cron store holds one occurrence per hook', $dup_free );

// =====================================================================
// C4: dispatch due hooks (injected recording jobs) + self-reschedule.
// =====================================================================
$ran_jobs = array();
$sched2   = new Scheduler(
        sys_get_temp_dir() . '/uc-sched-' . getmypid(),
        array(
                Scheduler::CRON_JANITOR   => function () use ( &$ran_jobs ) { $ran_jobs[] = 'janitor'; },
                Scheduler::CRON_WARMUP    => function () use ( &$ran_jobs ) { $ran_jobs[] = 'warmup'; },
                Scheduler::CRON_TELEMETRY => function () use ( &$ran_jobs ) { $ran_jobs[] = 'telemetry'; },
        )
);
// Force all hooks due in the past.
$due_ts = $before_window - 100;
foreach ( Scheduler::scheduled_hooks() as $h ) {
        wp_clear_scheduled_hook( $h );
        wp_schedule_event( $due_ts, 'daily', $h );
}
$ran = $sched2->dispatch_due( $before_window );
ccheck( $results, 'C4 all due hooks ran', 3 === count( $ran ) && 3 === count( $ran_jobs ), 'ran=' . json_encode( $ran ) );
$next_after = wp_next_scheduled( Scheduler::CRON_JANITOR );
ccheck( $results, 'C4 dispatch re-books the next occurrence (future)', false !== $next_after && (int) $next_after > $before_window );

// =====================================================================
// C5: backpressure — a held lock skips the RUN but still re-books.
// =====================================================================
$lock_dir = sys_get_temp_dir() . '/uc-sched-' . getmypid();
@mkdir( $lock_dir, 0777, true );
$holder   = new FileLock( $lock_dir . '/sched-' . md5( Scheduler::CRON_WARMUP ) . '.lock' );
ccheck( $results, 'C5 setup: holder acquired the hook lock', true === $holder->acquire( 300 ) );
$ran_jobs = array();
$ran      = $sched2->dispatch_due( $before_window + 200 ); // hooks due again (re-booked in the past? no — future). Force past again:
if ( empty( $ran ) ) {
        foreach ( Scheduler::scheduled_hooks() as $h ) {
                wp_clear_scheduled_hook( $h );
                wp_schedule_event( $due_ts, 'daily', $h );
        }
        $ran = $sched2->dispatch_due( $before_window + 200 );
}
ccheck( $results, 'C5 held lock: warmup run skipped (backpressure)', ! in_array( 'warmup', $ran_jobs, true ) && ! in_array( Scheduler::CRON_WARMUP, $ran, true ) );
ccheck( $results, 'C5 other hooks unaffected by the warmup lock', in_array( 'janitor', $ran_jobs, true ) );
ccheck( $results, 'C5 skipped hook still re-booked (never lost)', false !== wp_next_scheduled( Scheduler::CRON_WARMUP ) && (int) wp_next_scheduled( Scheduler::CRON_WARMUP ) > $before_window + 200 );
$holder->release();
$ran_jobs = array();
foreach ( Scheduler::scheduled_hooks() as $h ) {
        wp_clear_scheduled_hook( $h );
        wp_schedule_event( $due_ts, 'daily', $h );
}
$ran = $sched2->dispatch_due( $before_window + 300 );
ccheck( $results, 'C5 released lock: warmup runs on the next tick', in_array( 'warmup', $ran_jobs, true ) );

// =====================================================================
// C6: locks released after runs (no residue wedges the next run).
// =====================================================================
$probe = new FileLock( $lock_dir . '/sched-' . md5( Scheduler::CRON_TELEMETRY ) . '.lock' );
ccheck( $results, 'C6 post-run lock is free (released)', true === $probe->acquire( 300 ) );
$probe->release();

// =====================================================================
// C7: janitor default job sweeps a REAL sqlite DB at the default path.
// =====================================================================
$sb = new SqliteBackend(); // default file under the shim cache tree
$G  = 'b1::sweeptest';
$sb->set( 'dead1', 'x', 1, $G );
$sb->set( 'dead2', 'x', 1, $G );
$sb->set( 'alive', 'x', 0, $G );
sleep( 2 );
$sched3 = new Scheduler( sys_get_temp_dir() . '/uc-sched3-' . getmypid() ); // DEFAULT jobs
wp_clear_scheduled_hook( Scheduler::CRON_JANITOR );
wp_schedule_event( $due_ts, 'daily', Scheduler::CRON_JANITOR );
$ran = $sched3->dispatch_due( $due_ts + 50 );
$sb->get( 'dead1', $G, $f_dead );
$sb->get( 'alive', $G, $f_alive );
ccheck( $results, 'C7 janitor tick ran on the default DB', in_array( Scheduler::CRON_JANITOR, $ran, true ) );
ccheck( $results, 'C7 expired rows swept, fresh row survives', false === $f_dead && true === $f_alive );
$sb->close();
@unlink( WP_CONTENT_DIR . '/cache/ultimate-performance/object-cache.sqlite' );

// =====================================================================
// M1/M2: telemetry fixed schema + hostile label collapse.
// =====================================================================
$hostile = new Telemetry(
        sys_get_temp_dir() . '/uc-tel-' . getmypid(),
        function () {
                return array(
                        'hits'            => 11,
                        'misses'          => 3,
                        'fences'          => 1,
                        'backend_healthy' => true,
                        'backend'         => array( 'UltimatePerformance\\ObjectCache\\RedisBackend', '../../etc/passwd', 'redis://u:p@host:6379', 'WeirdClass' ),
                        // hostile EXTRA keys must never appear in the snapshot:
                        'global_groups'   => array( 'secret-group' ),
                        'password'        => 'hunter2',
                        'db_password'     => 'hunter2',
                        'host'            => 'internal-hostname',
                );
        }
);
$snap = $hostile->snapshot();
$allowed = array( 'schema', 'ts', 'oc_hits', 'oc_misses', 'oc_fences', 'oc_backend_healthy', 'oc_backends', 'warmup_total', 'warmup_enqueued', 'warmup_skipped_dup', 'warmup_skipped_foreign', 'warmup_capped', 'warmup_status', 'cluster_events_published', 'cluster_events_consumed', 'cluster_events_duplicates', 'cluster_events_failures', 'cluster_events_stale', 'cluster_epoch_reconciliations', 'cluster_epoch_authority_failures', 'cluster_event_gaps', 'cluster_watermark_resets', 'cluster_node_id_collisions', 'cluster_lag_ms' );
$extra = array_diff( array_keys( $snap ), $allowed );
ccheck( $results, 'M1 snapshot schema is FIXED (no extra keys leak)', 0 === count( $extra ), 'extra=' . json_encode( array_values( $extra ) ) );
ccheck( $results, 'M2 hostile backend names collapse to enum', $snap['oc_backends'] === array( 'redis', 'none' ), 'got=' . json_encode( $snap['oc_backends'] ) );

$prom = $hostile->render_prometheus( $snap );
foreach ( array( 'passwd', 'hunter2', 'WeirdClass', 'secret-group', '://', 'internal-hostname', "\\", 'u:p' ) as $needle ) {
        if ( false !== strpos( $prom, $needle ) ) {
                ccheck( $results, "M3 prometheus output free of '$needle'", false, 'leaked' );
        }
}
ccheck( $results, 'M3 prometheus exposition fixed names + enum labels only', 1 === preg_match( '/^uc_oc_hits_total\{backend="(redis|memcached|apcu|sqlite|file|memory|none)"\} \d+$/m', $prom ) && false === strpos( $prom, '{' . "\n" ) );
$bad_line = array();
foreach ( explode( "\n", $prom ) as $ln ) {
        if ( '' === $ln || 0 === strpos( $ln, '#' ) ) { continue; }
        if ( ! preg_match( '/^uc_[a-z_]+(_total)?( \d+|\{backend="[a-z]+"\} \d+)$/', $ln ) ) { $bad_line[] = $ln; }
}
ccheck( $results, 'M3 every metric line matches the fixed grammar', 0 === count( $bad_line ), json_encode( $bad_line ) );

// =====================================================================
// M4: snapshot write (atomic, 0640, valid JSON).
// =====================================================================
$tel_dir = sys_get_temp_dir() . '/uc-tel2-' . getmypid();
$tel     = new Telemetry( $tel_dir, function () { return array( 'hits' => 1, 'misses' => 2, 'fences' => 0, 'backend_healthy' => false, 'backend' => array() ); } );
$ok      = $tel->write_snapshot();
$file    = $tel_dir . '/telemetry.json';
$perms   = is_file( $file ) ? substr( sprintf( '%o', fileperms( $file ) ), -4 ) : '0000';
$decoded = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
ccheck( $results, 'M4 snapshot file written', true === $ok && is_file( $file ) );
ccheck( $results, 'M4 snapshot file mode 0640', '0640' === $perms, 'mode=' . $perms );
ccheck( $results, 'M4 snapshot JSON valid with schema=3 (N1 epoch/recovery counters added)', is_array( $decoded ) && 3 === ( $decoded['schema'] ?? 0 ) );
ccheck( $results, 'M4 empty backend chain renders as enum none', ( $decoded['oc_backends'] ?? array() ) === array( 'none' ) );

// Cleanup.
@unlink( ABSPATH . 'state/cron.json' );
update_option( 'timezone_string', '' );
exec( 'rm -rf ' . escapeshellarg( $lock_dir ) . ' ' . escapeshellarg( $tel_dir ) . ' ' . escapeshellarg( $tel_dir . '-x' ) );
$stale = sys_get_temp_dir() . '/uc-sched-' . getmypid();
exec( 'rm -rf ' . escapeshellarg( $stale ) );
$stale3 = sys_get_temp_dir() . '/uc-sched3-' . getmypid();
exec( 'rm -rf ' . escapeshellarg( $stale3 ) );
$stale4 = sys_get_temp_dir() . '/uc-tel-' . getmypid();
exec( 'rm -rf ' . escapeshellarg( $stale4 ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
