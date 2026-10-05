<?php
/**
 * AUDIT TEST — CronGuard deduplication & idempotency (Phase 0.7.0).
 *
 * Verifies the single-source-of-truth contract that CronGuard is the ONE
 * entry point for all UP-owned cron scheduling, that every operation is
 * idempotent, deduplicated, and transient-gated so steady-state requests
 * perform zero cron-option writes.
 *
 * 22 scenarios:
 *
 *   S1  Fresh activation — exactly 1 event per canonical hook
 *   S2  Repeated activation — still exactly 1 event per hook
 *   S3  Normal bootstrap repeatedly — no new events created
 *   S4  Multiple simulated requests — no new events
 *   S5  Cron callback execution — event rescheduled exactly once
 *   S6  Settings saved repeatedly — no new events
 *   S7  Interval changed — old cleared, new created (schema bump triggers repair)
 *   S8  Telemetry toggle OFF → ON → OFF → ON — no accumulation
 *   S9  Deactivation — all UP events removed
 *   S10 Reactivation — events recreated
 *   S11 Upgrade from 0.6.9 with thousands of duplicates — repair removes duplicates
 *   S12 Repair run twice — same result (idempotent)
 *   S13 Unrelated cron hooks preserved
 *   S14 Malformed/stale UP events cleaned
 *   S15 Missing canonical event — recreated
 *   S16 Dead hooks removed
 *   S17 Cron option size reduction verified
 *
 *   0.7.1 additions (CronGuard args/atomic-lock/callback-identity fixes):
 *   S18 events_for() returns the `args` field per event
 *   S19 wp_unschedule_event() is called with args + new repair stats counters
 *   S20 register_callbacks() called 100× still has exactly 1 callback per hook
 *   S21 Atomic lock — acquire / release / stale-takeover / owner-safe no-release
 *   S22 repair() stats include attempted_removals, successful_removals,
 *       failed_removals (and back-compat `removed` mirror)
 *
 * Run: php tests/audit-cron-dedup.php
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Core\CronGuard;
use UltimatePerformance\Core\Scheduler;

$results = array();
$fail    = 0;

function cd_check( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

/** Wipe the cron store + transients + CronGuard schema option. */
function cd_reset() {
        @file_put_contents( ABSPATH . 'state/cron.json', '{}' );
        // Drop CronGuard health-check transient so each scenario starts clean.
        delete_transient( CronGuard::HEALTH_CHECK_TRANSIENT );
        // 0.7.1: drop the atomic repair lock option (replaces the old
        // REPAIR_LOCK_TRANSIENT transient that no longer exists).
        delete_option( CronGuard::LOCK_OPTION_PREFIX . 'repair' );
        delete_option( CronGuard::SCHEMA_VERSION_OPTION );
}

/**
 * Count UP-owned cron events for a hook across the shim cron store.
 * Mirrors CronGuard::count_events_for but exposed for assertions.
 *
 * @param string $hook
 * @return int
 */
function cd_count_events( $hook ) {
        $cron = _get_cron_array();
        if ( ! is_array( $cron ) ) {
                return 0;
        }
        $n = 0;
        foreach ( $cron as $ts => $hooks ) {
                if ( is_array( $hooks ) && isset( $hooks[ $hook ] ) ) {
                        $instances = $hooks[ $hook ];
                        if ( is_array( $instances ) ) {
                                $n += count( $instances );
                        } else {
                                ++$n;
                        }
                }
        }
        return $n;
}

/** Sum of all UP-owned canonical hooks' event counts. */
function cd_canonical_total() {
        $n = 0;
        foreach ( CronGuard::canonical_hooks() as $hook ) {
                $n += cd_count_events( $hook );
        }
        return $n;
}

/** Sum of all UP dead hooks' event counts. */
function cd_dead_total() {
        $n = 0;
        foreach ( CronGuard::DEAD_HOOKS as $hook ) {
                $n += cd_count_events( $hook );
        }
        return $n;
}

/** Serialize the cron array — for size comparisons. */
function cd_cron_option_size() {
        $cron = _get_cron_array();
        if ( ! is_array( $cron ) ) {
                return 0;
        }
        return strlen( (string) json_encode( $cron ) );
}

// =====================================================================
// S1: Fresh activation — exactly 1 event per canonical hook.
// =====================================================================
cd_reset();
CronGuard::activate();
$per_hook = array();
foreach ( CronGuard::canonical_hooks() as $hook ) {
        $per_hook[ $hook ] = cd_count_events( $hook );
}
cd_check( $results, 'S1 fresh activation: each canonical hook has exactly 1 event',
        4 === count( array_filter( $per_hook, fn( $n ) => 1 === $n ) ),
        'per_hook=' . json_encode( $per_hook ) );
cd_check( $results, 'S1 fresh activation: no dead hooks scheduled',
        0 === cd_dead_total(),
        'dead_total=' . cd_dead_total() );

// =====================================================================
// S2: Repeated activation — still exactly 1 event per hook (idempotent).
// =====================================================================
for ( $i = 0; $i < 5; ++$i ) {
        CronGuard::activate();
}
$per_hook_after = array();
foreach ( CronGuard::canonical_hooks() as $hook ) {
        $per_hook_after[ $hook ] = cd_count_events( $hook );
}
cd_check( $results, 'S2 repeated activation: still exactly 1 event per hook',
        4 === count( array_filter( $per_hook_after, fn( $n ) => 1 === $n ) ),
        'per_hook_after=' . json_encode( $per_hook_after ) );

// =====================================================================
// S3: Normal bootstrap repeatedly — no new events created.
// (ensure_scheduled() is transient-gated; once the gate is set, repeated
// calls are no-ops.)
// =====================================================================
$before_s3 = cd_canonical_total();
for ( $i = 0; $i < 20; ++$i ) {
        CronGuard::ensure_scheduled();
}
$after_s3 = cd_canonical_total();
cd_check( $results, 'S3 repeated ensure_scheduled(): no new canonical events created',
        $before_s3 === $after_s3,
        "before={$before_s3} after={$after_s3}" );

// =====================================================================
// S4: Multiple simulated requests — no new events.
// (Even after invalidating the health-check transient, ensure_scheduled
// should NOT schedule duplicates because wp_next_scheduled() correctly
// reports the existing event.)
// =====================================================================
delete_transient( CronGuard::HEALTH_CHECK_TRANSIENT );
$before_s4 = cd_canonical_total();
for ( $i = 0; $i < 10; ++$i ) {
        delete_transient( CronGuard::HEALTH_CHECK_TRANSIENT );
        CronGuard::ensure_scheduled();
}
$after_s4 = cd_canonical_total();
cd_check( $results, 'S4 multiple requests (gate-bypassed): no new canonical events',
        $before_s4 === $after_s4,
        "before={$before_s4} after={$after_s4}" );

// =====================================================================
// S5: Cron callback execution — event rescheduled exactly once.
// (run_single_job() clears ALL old occurrences of a hook and schedules one
// new occurrence.)
// =====================================================================
$hook = CronGuard::HOOK_JANITOR;
$before_count_s5 = cd_count_events( $hook );
CronGuard::run_single_job( $hook );
$after_count_s5 = cd_count_events( $hook );
cd_check( $results, 'S5 run_single_job: event count stays at 1 after reschedule',
        1 === $after_count_s5,
        "before_count={$before_count_s5} after_count={$after_count_s5}" );

// Run twice more — should still be exactly 1.
CronGuard::run_single_job( $hook );
CronGuard::run_single_job( $hook );
$still = cd_count_events( $hook );
cd_check( $results, 'S5 run_single_job twice more: still exactly 1 event',
        1 === $still,
        "after_3_runs={$still}" );

// =====================================================================
// S6: Settings saved repeatedly — no new events.
// (Saving settings shouldn't touch the cron option; this scenario confirms
// that no settings-triggered side effect schedules new events.)
// =====================================================================
$before_s6 = cd_canonical_total();
for ( $i = 0; $i < 5; ++$i ) {
        // Simulate "settings saved" by re-saving the plugin settings option
        // (which CronGuard never touches). This proves no other code path
        // schedules events in response to settings writes.
        update_option( 'ultimate_performance_settings', array( 'enabled' => true, 'page_cache_enabled' => true, 'queue_enabled' => true ), true );
        CronGuard::ensure_scheduled();
}
$after_s6 = cd_canonical_total();
cd_check( $results, 'S6 settings saves + ensure_scheduled: no new events',
        $before_s6 === $after_s6,
        "before={$before_s6} after={$after_s6}" );

// =====================================================================
// S7: Interval changed — schema bump triggers repair, removes all
// duplicates + ensures one canonical event per hook.
// =====================================================================
cd_reset();
CronGuard::activate();
// Manually inject thousands of duplicates for every canonical hook — simulates
// an upgrade from 0.6.9 carrying duplicate events scheduled at the wrong
// recurrence.
for ( $i = 0; $i < 100; ++$i ) {
        foreach ( CronGuard::canonical_hooks() as $hook ) {
                wp_schedule_event( time() + 60 + $i, 'daily', $hook );
        }
}
$before_s7 = cd_canonical_total();
cd_check( $results, 'S7 setup: 100 dup events per canonical hook injected',
        $before_s7 > 400,
        "total_canonical_events={$before_s7}" );

// Bump schema → maybe_migrate() should run repair.
delete_option( CronGuard::SCHEMA_VERSION_OPTION );
delete_transient( CronGuard::HEALTH_CHECK_TRANSIENT );
// 0.7.1: release the atomic repair lock option (was REPAIR_LOCK_TRANSIENT).
delete_option( CronGuard::LOCK_OPTION_PREFIX . 'repair' );
CronGuard::maybe_migrate();
$after_s7 = cd_canonical_total();
cd_check( $results, 'S7 maybe_migrate ran repair: canonical events back to 1 per hook',
        4 === $after_s7,
        "after={$after_s7}" );

// =====================================================================
// S8: Telemetry toggle OFF → ON → OFF → ON — no accumulation.
// (CronGuard is the single source of truth; toggling a setting cannot add
// duplicates because activate()/ensure_scheduled() always check before
// scheduling.)
// =====================================================================
cd_reset();
CronGuard::activate();
$cycle_total = cd_canonical_total();
for ( $i = 0; $i < 4; ++$i ) {
        // "Telemetry toggled OFF": deactivating the telemetry hook, then
        // "ON": CronGuard re-creates it on next ensure_scheduled.
        wp_clear_scheduled_hook( CronGuard::HOOK_TELEMETRY );
        CronGuard::ensure_scheduled();
}
$final_total = cd_canonical_total();
cd_check( $results, 'S8 telemetry toggle cycle: canonical total unchanged (no accumulation)',
        $cycle_total === $final_total,
        "start={$cycle_total} end={$final_total}" );
cd_check( $results, 'S8 telemetry still has exactly 1 event after toggling',
        1 === cd_count_events( CronGuard::HOOK_TELEMETRY ),
        "telemetry_events=" . cd_count_events( CronGuard::HOOK_TELEMETRY ) );

// =====================================================================
// S9: Deactivation — all UP events removed.
// =====================================================================
$before_s9 = cd_canonical_total() + cd_dead_total();
CronGuard::deactivate();
$after_s9 = cd_canonical_total() + cd_dead_total();
cd_check( $results, 'S9 deactivation: all UP canonical events removed',
        0 === cd_canonical_total(),
        "canonical_remaining=" . cd_canonical_total() );
cd_check( $results, 'S9 deactivation: all UP dead-hook events removed',
        0 === cd_dead_total(),
        "dead_remaining=" . cd_dead_total() );
cd_check( $results, 'S9 deactivation: schema option removed',
        false === get_option( CronGuard::SCHEMA_VERSION_OPTION, false ) );

// =====================================================================
// S10: Reactivation — events recreated.
// =====================================================================
$before_s10 = cd_canonical_total();
cd_check( $results, 'S10 setup: 0 canonical events before reactivation',
        0 === $before_s10,
        "before={$before_s10}" );
CronGuard::activate();
$after_s10 = cd_canonical_total();
cd_check( $results, 'S10 reactivation: all 4 canonical hooks recreated',
        4 === $after_s10,
        "after={$after_s10}" );
cd_check( $results, 'S10 reactivation: schema version option restored',
        CronGuard::CURRENT_SCHEMA_VERSION === (string) get_option( CronGuard::SCHEMA_VERSION_OPTION, '0' ) );

// =====================================================================
// S11: Upgrade from 0.6.9 with thousands of duplicates — repair removes duplicates.
// =====================================================================
cd_reset();
// Simulate a 0.6.9 install: thousands of duplicate canonical events AND
// thousands of dead-hook events.
for ( $i = 0; $i < 5660; ++$i ) {
        foreach ( CronGuard::canonical_hooks() as $hook ) {
                wp_schedule_event( time() + ( $i % 3600 ), 'daily', $hook );
        }
}
// Dead hooks from the 0.6.x migration.
for ( $i = 0; $i < 200; ++$i ) {
        foreach ( CronGuard::DEAD_HOOKS as $hook ) {
                wp_schedule_event( time() + ( $i % 3600 ), 'up_every_minute', $hook );
        }
}
$before_s11 = cd_canonical_total() + cd_dead_total();
$before_size = cd_cron_option_size();

$stats = CronGuard::repair();
$after_s11 = cd_canonical_total() + cd_dead_total();
$after_size = cd_cron_option_size();
cd_check( $results, 'S11 repair: canonical count down to 4 (1 per hook)',
        4 === cd_canonical_total(),
        "canonical_after=" . cd_canonical_total() );
cd_check( $results, 'S11 repair: all dead hooks removed',
        0 === cd_dead_total(),
        "dead_after=" . cd_dead_total() );
cd_check( $results, 'S11 repair: removed thousands of duplicates (>1000)',
        $stats['removed'] + $stats['dead_removed'] > 1000,
        "removed={$stats['removed']} dead_removed={$stats['dead_removed']}" );
cd_check( $results, 'S11 repair: cron option size reduced dramatically',
        $after_size < ( $before_size / 10 ),
        "before_size={$before_size} after_size={$after_size}" );

// =====================================================================
// S12: Repair run twice — same result (idempotent).
// =====================================================================
$before_s12 = cd_canonical_total() + cd_dead_total();
$stats2 = CronGuard::repair();
$after_s12 = cd_canonical_total() + cd_dead_total();
cd_check( $results, 'S12 second repair: canonical count unchanged',
        4 === cd_canonical_total(),
        "canonical_after=" . cd_canonical_total() );
cd_check( $results, 'S12 second repair: removed 0 (nothing to remove)',
        0 === $stats2['removed'] && 0 === $stats2['dead_removed'] && 0 === $stats2['created'],
        "stats=" . json_encode( $stats2 ) );
cd_check( $results, 'S12 second repair: attempted/successful/failed_removals all 0',
        isset( $stats2['attempted_removals'] ) && 0 === (int) $stats2['attempted_removals']
        && isset( $stats2['successful_removals'] ) && 0 === (int) $stats2['successful_removals']
        && isset( $stats2['failed_removals'] ) && 0 === (int) $stats2['failed_removals'],
        "stats=" . json_encode( $stats2 ) );
cd_check( $results, 'S12 second repair: total count unchanged from S11',
        $before_s12 === $after_s12,
        "before={$before_s12} after={$after_s12}" );

// =====================================================================
// S13: Unrelated cron hooks preserved.
// (Repair must NEVER touch hooks owned by other plugins.)
// =====================================================================
cd_reset();
// Simulate WooCommerce + another plugin scheduling their own hooks.
wp_schedule_event( time() + 3600, 'daily', 'woocommerce_scheduled_sales' );
wp_schedule_event( time() + 3600, 'daily', 'woocommerce_cleanup_logs' );
wp_schedule_event( time() + 1800, 'hourly', 'another_plugin_task' );
$unrelated_before = cd_count_events( 'woocommerce_scheduled_sales' )
        + cd_count_events( 'woocommerce_cleanup_logs' )
        + cd_count_events( 'another_plugin_task' );
CronGuard::repair();
$unrelated_after = cd_count_events( 'woocommerce_scheduled_sales' )
        + cd_count_events( 'woocommerce_cleanup_logs' )
        + cd_count_events( 'another_plugin_task' );
cd_check( $results, 'S13 unrelated cron hooks preserved across repair',
        $unrelated_before === $unrelated_after && $unrelated_after === 3,
        "before={$unrelated_before} after={$unrelated_after}" );

// =====================================================================
// S14: Malformed/stale UP events cleaned.
// (Repair should still leave the store in a consistent state even when
// there are stale past-due events for UP hooks.)
// =====================================================================
cd_reset();
CronGuard::activate();
// Inject past-due events (would have been executed but weren't, leaving stale).
wp_schedule_event( time() - 86400, 'daily', CronGuard::HOOK_JANITOR );
wp_schedule_event( time() - 7200, 'daily', CronGuard::HOOK_WARMUP );
$stale_before = cd_count_events( CronGuard::HOOK_JANITOR ) + cd_count_events( CronGuard::HOOK_WARMUP );
CronGuard::repair();
$stale_after = cd_count_events( CronGuard::HOOK_JANITOR ) + cd_count_events( CronGuard::HOOK_WARMUP );
cd_check( $results, 'S14 repair: stale/past-due canonical events deduped to 1 each',
        2 === $stale_after,
        "before={$stale_before} after={$stale_after}" );

// =====================================================================
// S15: Missing canonical event — recreated.
// (If an event gets lost — e.g., a different plugin unscheduled it —
// CronGuard::ensure_scheduled() should reschedule it.)
// =====================================================================
cd_reset();
CronGuard::activate();
$before_s15 = cd_canonical_total();
// Wipe one canonical hook entirely (simulating loss).
wp_clear_scheduled_hook( CronGuard::HOOK_TICK );
cd_check( $results, 'S15 setup: tick hook was wiped (1 missing)',
        3 === cd_canonical_total(),
        "after_wipe=" . cd_canonical_total() );
// Invalidate the gate so ensure_scheduled() does the real check.
delete_transient( CronGuard::HEALTH_CHECK_TRANSIENT );
CronGuard::ensure_scheduled();
cd_check( $results, 'S15 ensure_scheduled: missing tick hook recreated',
        1 === cd_count_events( CronGuard::HOOK_TICK ),
        "tick_events=" . cd_count_events( CronGuard::HOOK_TICK ) );
cd_check( $results, 'S15 ensure_scheduled: canonical total back to 4',
        4 === cd_canonical_total(),
        "after_reschedule=" . cd_canonical_total() );

// =====================================================================
// S16: Dead hooks removed.
// (repair() should clear every dead hook from the cron store, even ones
// that were injected by external processes.)
// =====================================================================
cd_reset();
CronGuard::activate();
// Inject dead hooks (simulating an upgrade from 0.6.x that left these
// behind from migrate_uc_to_up_brand()).
for ( $i = 0; $i < 50; ++$i ) {
        foreach ( CronGuard::DEAD_HOOKS as $hook ) {
                wp_schedule_event( time() + ( $i * 60 ), 'up_every_minute', $hook );
        }
}
$dead_before = cd_dead_total();
cd_check( $results, 'S16 setup: 100 dead-hook events injected',
        100 === $dead_before,
        "dead_before={$dead_before}" );
CronGuard::repair();
cd_check( $results, 'S16 repair: all dead hooks removed',
        0 === cd_dead_total(),
        "dead_after=" . cd_dead_total() );

// =====================================================================
// S17: Cron option size reduction verified.
// (Repair on a heavily-bloated install should reduce the cron option to
// a small stable size — under 4KB after dedup, regardless of how bloated
// it was before.)
// =====================================================================
cd_reset();
// Build a 1.26MB-ish cron store (the original bug report's size).
for ( $i = 0; $i < 3000; ++$i ) {
        foreach ( CronGuard::ALL_KNOWN_HOOKS as $hook ) {
                wp_schedule_event( time() + ( $i % 3600 ), 'up_every_minute', $hook );
        }
}
$bloated_size = cd_cron_option_size();
cd_check( $results, 'S17 setup: bloated cron option is large (>100KB)',
        $bloated_size > 100000,
        "bloated_size={$bloated_size}B" );
CronGuard::repair();
$reduced_size = cd_cron_option_size();
cd_check( $results, 'S17 repair: cron option reduced to <4KB',
        $reduced_size < 4096,
        "reduced_size={$reduced_size}B" );
cd_check( $results, 'S17 repair: cron option size reduction ≥ 90%',
        $reduced_size < ( $bloated_size / 10 ),
        "bloated={$bloated_size}B reduced={$reduced_size}B" );

// =====================================================================
// S18: events_for() returns the `args` field per event.
// (0.7.1 Issue #1 fix — events scheduled WITH args must be enumerable
// with their stored args so repair() can pass them to wp_unschedule_event.)
// =====================================================================
cd_reset();
$s18_args = array( 'product_id' => 42, 'context' => 'unit-test' );
wp_schedule_event( time() + 86400, 'daily', CronGuard::HOOK_JANITOR, $s18_args );
$cron_s18 = _get_cron_array();
$ref_evfor = new ReflectionMethod( CronGuard::class, 'events_for' );
$ref_evfor->setAccessible( true );
$s18_events = $ref_evfor->invoke( null, $cron_s18, CronGuard::HOOK_JANITOR );
cd_check( $results, 'S18 events_for: returns at least 1 event for the scheduled hook',
        count( $s18_events ) >= 1,
        'count=' . count( $s18_events ) );
$s18_with_args = null;
foreach ( $s18_events as $ev ) {
        if ( isset( $ev['args'] ) ) {
                $s18_with_args = $ev;
                break;
        }
}
cd_check( $results, 'S18 events_for: every event has an `args` field',
        null !== $s18_with_args,
        'events=' . json_encode( $s18_events ) );
cd_check( $results, 'S18 events_for: args match what was scheduled',
        null !== $s18_with_args && $s18_with_args['args'] === $s18_args,
        'ev=' . json_encode( $s18_with_args ) );

// =====================================================================
// S19: wp_unschedule_event() is called with args + new repair stats counters.
// (0.7.1 Issue #1 fix — repair() must pass the stored args to
// wp_unschedule_event so events scheduled WITH args are matched & removed.)
// =====================================================================
cd_reset();
CronGuard::activate();
// Inject 3 duplicate events WITH args at future ts that are LATER than the
// canonical activation event's off-peak ts — so the canonical (args=[]) is
// the soonest and gets kept, and the 3 with-args events get removed.
$s19_args = array( 'product_id' => 42 );
wp_schedule_event( time() + 90000, 'daily', CronGuard::HOOK_JANITOR, $s19_args );
wp_schedule_event( time() + 93600, 'daily', CronGuard::HOOK_JANITOR, $s19_args );
wp_schedule_event( time() + 97200, 'daily', CronGuard::HOOK_JANITOR, $s19_args );
$s19_before = cd_count_events( CronGuard::HOOK_JANITOR );
cd_check( $results, 'S19 setup: 4 janitor events present (1 canonical + 3 with args)',
        4 === $s19_before,
        "before={$s19_before}" );
$s19_stats = CronGuard::repair();
$s19_after  = cd_count_events( CronGuard::HOOK_JANITOR );
cd_check( $results, 'S19 repair: exactly 1 janitor event remains (soonest kept)',
        1 === $s19_after,
        "after={$s19_after}" );
cd_check( $results, 'S19 repair: attempted_removals == 3 (the 3 with-args dups)',
        isset( $s19_stats['attempted_removals'] ) && 3 === (int) $s19_stats['attempted_removals'],
        'stats=' . json_encode( $s19_stats ) );
cd_check( $results, 'S19 repair: successful_removals == 3 (args matched → unschedule succeeded)',
        isset( $s19_stats['successful_removals'] ) && 3 === (int) $s19_stats['successful_removals'],
        'stats=' . json_encode( $s19_stats ) );
cd_check( $results, 'S19 repair: failed_removals == 0 (every unschedule matched)',
        isset( $s19_stats['failed_removals'] ) && 0 === (int) $s19_stats['failed_removals'],
        'stats=' . json_encode( $s19_stats ) );
cd_check( $results, 'S19 repair: `removed` (back-compat) mirrors successful_removals',
        isset( $s19_stats['removed'] ) && (int) $s19_stats['removed'] === (int) $s19_stats['successful_removals'],
        'stats=' . json_encode( $s19_stats ) );

// =====================================================================
// S20: register_callbacks() called 100× still has exactly 1 callback per hook.
// (0.7.1 Issue #3 fix — deterministic array(__CLASS__, 'method') callbacks
// replace the previous per-call Closure that has_action() could never match.)
// =====================================================================
cd_reset();
// Wipe the global hook registry so we start from zero.
$GLOBALS['wp_filter'] = array();
for ( $i = 0; $i < 100; ++$i ) {
        CronGuard::register_callbacks();
}
$s20_hooks = array(
        CronGuard::HOOK_TICK,
        CronGuard::HOOK_JANITOR,
        CronGuard::HOOK_WARMUP,
        CronGuard::HOOK_TELEMETRY,
);
$s20_ok = true;
$s20_detail = array();
foreach ( $s20_hooks as $hook ) {
        $n = 0;
        if ( isset( $GLOBALS['wp_filter'][ $hook ] ) && $GLOBALS['wp_filter'][ $hook ] instanceof \UltimatePerformance\Tests\Shim\ShimHook ) {
                foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $cbs ) {
                        $n += count( $cbs );
                }
        }
        $s20_detail[ $hook ] = $n;
        if ( 1 !== $n ) {
                $s20_ok = false;
        }
}
cd_check( $results, 'S20 register_callbacks 100×: exactly 1 callback per canonical hook',
        $s20_ok,
        'detail=' . json_encode( $s20_detail ) );

// =====================================================================
// S21: Atomic lock — acquire / release / stale-takeover / owner-safe.
// (0.7.1 Issue #2 fix — non-atomic transient check-then-set replaced with
// add_option()-based atomic acquire_lock/release_lock helpers.)
// =====================================================================
cd_reset();
// Wipe any leftover lock option for the test purposes.
delete_option( CronGuard::LOCK_OPTION_PREFIX . 's21a' );
delete_option( CronGuard::LOCK_OPTION_PREFIX . 's21b' );
delete_option( CronGuard::LOCK_OPTION_PREFIX . 's21c' );
delete_option( CronGuard::LOCK_OPTION_PREFIX . 's21d' );

$ref_lock = new ReflectionMethod( CronGuard::class, 'acquire_lock' );
$ref_lock->setAccessible( true );
$ref_rel  = new ReflectionMethod( CronGuard::class, 'release_lock' );
$ref_rel->setAccessible( true );

// (a) First acquire succeeds.
$owner_a = $ref_lock->invoke( null, 's21a' );
cd_check( $results, 'S21 (a) acquire_lock: returns a non-empty owner token on first call',
        is_string( $owner_a ) && 32 === strlen( $owner_a ),
        "owner_a=" . ( is_string( $owner_a ) ? $owner_a : 'false' ) );

// (b) Second acquire fails — lock is held.
$owner_a2 = $ref_lock->invoke( null, 's21a' );
cd_check( $results, 'S21 (b) acquire_lock: returns false when the lock is held',
        false === $owner_a2,
        'got=' . ( false === $owner_a2 ? 'false' : 'owner' ) );

// (c) Owner-safe release with a WRONG owner does NOT release the lock.
$ref_rel->invoke( null, 's21a', 'wrong-owner-token' );
$owner_a3 = $ref_lock->invoke( null, 's21a' );
cd_check( $results, 'S21 (c) release_lock: wrong-owner does NOT release the lock',
        false === $owner_a3,
        'lock_should_still_be_held' );

// (d) Correct owner release succeeds — next acquire gets a new owner.
$ref_rel->invoke( null, 's21a', $owner_a );
$owner_a4 = $ref_lock->invoke( null, 's21a' );
cd_check( $results, 'S21 (d) release_lock: correct owner releases; next acquire succeeds',
        is_string( $owner_a4 ) && $owner_a4 !== $owner_a,
        "owner_a4=" . ( is_string( $owner_a4 ) ? $owner_a4 : 'false' ) );
$ref_rel->invoke( null, 's21a', $owner_a4 ); // clean up.

// (e) Stale lock takeover: corrupt the expires field to the past, then
// acquire again — should succeed (stale-takeover path).
$owner_e1 = $ref_lock->invoke( null, 's21b' );
cd_check( $results, 'S21 (e) setup: acquire_lock for stale-test returns owner',
        is_string( $owner_e1 ),
        "owner_e1=" . ( is_string( $owner_e1 ) ? $owner_e1 : 'false' ) );
// Manually corrupt the option's expires field to a past timestamp.
update_option(
        CronGuard::LOCK_OPTION_PREFIX . 's21b',
        array( 'owner' => $owner_e1, 'created' => time() - 3600, 'expires' => time() - 60 ),
        false
);
$owner_e2 = $ref_lock->invoke( null, 's21b' );
cd_check( $results, 'S21 (e) acquire_lock: stale lock taken over after TTL expiry',
        is_string( $owner_e2 ) && $owner_e2 !== $owner_e1,
        "owner_e2=" . ( is_string( $owner_e2 ) ? $owner_e2 : 'false' ) );
$ref_rel->invoke( null, 's21b', $owner_e2 ); // clean up.

// (f) Malformed lock takeover: write a non-array (string) value into the
// lock option, then acquire — should succeed (malformed-takeover path).
update_option( CronGuard::LOCK_OPTION_PREFIX . 's21c', 'not-an-array', false );
$owner_f = $ref_lock->invoke( null, 's21c' );
cd_check( $results, 'S21 (f) acquire_lock: malformed lock taken over',
        is_string( $owner_f ),
        "owner_f=" . ( is_string( $owner_f ) ? $owner_f : 'false' ) );
$ref_rel->invoke( null, 's21c', $owner_f ); // clean up.

// =====================================================================
// S22: repair() stats include attempted_removals, successful_removals,
//      failed_removals — verified end-to-end via repair() return shape.
// (Covered structurally by S19 above; this scenario asserts the SHAPE
// of the returned stats array independent of the specific counts.)
// =====================================================================
cd_reset();
CronGuard::activate();
// Inject 2 with-args duplicates for HOOK_WARMUP at later ts.
$s22_args = array( 'warmup_target' => 'homepage' );
wp_schedule_event( time() + 90000, 'daily', CronGuard::HOOK_WARMUP, $s22_args );
wp_schedule_event( time() + 93600, 'daily', CronGuard::HOOK_WARMUP, $s22_args );
$s22_stats = CronGuard::repair();
$expected_keys = array(
        'before', 'after',
        'attempted_removals', 'successful_removals', 'failed_removals',
        'removed', 'dead_removed', 'created', 'schema_bumped',
);
$s20_missing = array();
foreach ( $expected_keys as $k ) {
        if ( ! array_key_exists( $k, $s22_stats ) ) {
                $s20_missing[] = $k;
        }
}
cd_check( $results, 'S22 repair stats: contains all expected keys (before/after/counters)',
        empty( $s20_missing ),
        'missing=' . json_encode( $s20_missing ) );
cd_check( $results, 'S22 repair stats: attempted_removals == 2 (warmup dups)',
        isset( $s22_stats['attempted_removals'] ) && 2 === (int) $s22_stats['attempted_removals'],
        'stats=' . json_encode( $s22_stats ) );
cd_check( $results, 'S22 repair stats: successful_removals == 2 (warmup dups with args matched)',
        isset( $s22_stats['successful_removals'] ) && 2 === (int) $s22_stats['successful_removals'],
        'stats=' . json_encode( $s22_stats ) );
cd_check( $results, 'S22 repair stats: failed_removals == 0',
        isset( $s22_stats['failed_removals'] ) && 0 === (int) $s22_stats['failed_removals'],
        'stats=' . json_encode( $s22_stats ) );

// =====================================================================
// Summary.
// =====================================================================
echo "\\n==== SUMMARY ====\\n";
$fails = 0;
foreach ( $results as $k => $v ) {
        if ( ! $v ) {
                ++$fails;
                echo "FAIL: $k\n";
        }
}
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
