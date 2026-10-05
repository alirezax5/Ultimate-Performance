<?php
/**
 * CronGuard — single source of truth for Ultimate Performance cron scheduling.
 *
 * The bug this class closes: three independent code paths scheduled WP-Cron
 * events on every WordPress request using the non-atomic
 * `if (!wp_next_scheduled()) { wp_schedule_event(); }` pattern. With tens
 * of requests/second the result was a cron option bloated with thousands
 * of duplicate events, rewritten on every request — a self-DoS that also
 * raced every concurrent scheduler. CronGuard is the ONE entry point for
 * scheduling any UP-owned hook, and every operation is idempotent,
 * deduplicated, and transient-gated to avoid DB writes on steady-state
 * requests.
 *
 * Owned hooks:
 *  - ultimate_performance_tick       (up_every_minute) — queue worker
 *  - ultimate_cache/janitor_tick     (daily)            — SQLite cleanup
 *  - ultimate_cache/warmup_tick      (daily)            — cache warmup
 *  - ultimate_cache/telemetry_tick   (daily)           — telemetry snapshot
 *
 * Dead hooks created by an old migration and never given callbacks:
 *  - ultimate_performance_janitor
 *  - ultimate_performance_telemetry
 *
 * @package UltimatePerformance\Core
 */

namespace UltimatePerformance\Core;

defined( 'ABSPATH' ) || exit;

final class CronGuard {

        // ===== Canonical hooks (exactly ONE scheduled event each) =====

        /** up_every_minute recurrence — queue worker. */
        const HOOK_TICK      = 'ultimate_performance_tick';
        /** daily recurrence — SQLite janitor. */
        const HOOK_JANITOR   = 'ultimate_cache/janitor_tick';
        /** daily recurrence — cache warmup. */
        const HOOK_WARMUP    = 'ultimate_cache/warmup_tick';
        /** daily recurrence — telemetry snapshot. */
        const HOOK_TELEMETRY = 'ultimate_cache/telemetry_tick';

        /** Dead hooks from the pre-0.7.0 rebrand migration (no callbacks). */
        const DEAD_HOOKS = array(
                'ultimate_performance_janitor',
                'ultimate_performance_telemetry',
        );

        /** Every hook this plugin has ever owned — for cleanup/repair. */
        const ALL_KNOWN_HOOKS = array(
                'ultimate_performance_tick',
                'ultimate_performance_janitor',
                'ultimate_performance_telemetry',
                'ultimate_cache/janitor_tick',
                'ultimate_cache/warmup_tick',
                'ultimate_cache/telemetry_tick',
        );

        const SCHEMA_VERSION_OPTION  = 'ultimate_performance_cron_schema_version';
        const CURRENT_SCHEMA_VERSION = '1';
        const HEALTH_CHECK_TRANSIENT  = 'up_cron_health_check';
        const HEALTH_CHECK_TTL        = 300;  // 5 minutes — see ensure_scheduled().
        // Atomic concurrency lock for repair(). We use an OPTION row (not a
        // transient) because add_option() returns false if the option already
        // exists, giving us database-level atomicity on MySQL (INSERT with a
        // UNIQUE-key-equivalent failure). The previous transient-based
        // check-then-set pattern was non-atomic — two concurrent admin
        // requests could both see "no lock" and both run repair().
        const LOCK_OPTION_PREFIX       = 'up_cron_lock_';
        const LOCK_TTL                  = 60;   // 60 seconds — concurrent-repair guard.

        /** Tick recurrence registered by QueueManager::schedules(). */
        const TICK_RECURRENCE = 'up_every_minute';
        /** Daily recurrence (WordPress core built-in). */
        const DAILY_RECURRENCE = 'daily';

        /**
         * Canonical hooks that SHOULD have exactly one scheduled event.
         *
         * @return array<int,string>
         */
        public static function canonical_hooks() {
                return array(
                        self::HOOK_TICK,
                        self::HOOK_JANITOR,
                        self::HOOK_WARMUP,
                        self::HOOK_TELEMETRY,
                );
        }

        /**
         * All UP-owned hooks ever created (canonical + dead). Used for
         * deactivate() and repair() so nothing this plugin scheduled is
         * left behind on the site.
         *
         * @return array<int,string>
         */
        public static function owned_hooks() {
                return self::ALL_KNOWN_HOOKS;
        }

        /**
         * Register the WP action callbacks for every canonical hook.
         *
         * Cheap on every request: this only adds the callback (the same way
         * QueueManager::boot() registers its tick callback). It NEVER writes
         * to the cron option. Idempotent — repeated calls just re-bind.
         *
         * Lifecycle: must run on every request, BEFORE any cron tick could
         * fire. Plugin.php calls this from late_boot() on plugins_loaded.
         */
        public static function register_callbacks() {
                // Tick: dispatches the queue worker. The actual registration
                // of this callback historically lived in QueueManager::boot()
                // — we keep it there for back-compat (it's already idempotent
                // and free) but ALSO register here so the canonical
                // ownership is visible in one place. add_action() is itself
                // idempotent when the same callback is re-bound.
                //
                // 0.7.1: tick_callback uses a deterministic array callback
                // `array(__CLASS__, 'tick_callback')` which has STABLE
                // identity across calls — has_action() correctly recognizes
                // a previously-registered callback and prevents duplicates.
                if ( ! has_action( self::HOOK_TICK, array( __CLASS__, 'tick_callback' ) ) ) {
                        add_action( self::HOOK_TICK, array( __CLASS__, 'tick_callback' ) );
                }

                // Daily hooks: each dispatches ONE Scheduler job, then
                // self-perpetuates exactly one next occurrence. Replaces the
                // old Scheduler::dispatch_due() iteration which had no
                // per-hook add_action binding (dead hooks, no callbacks).
                //
                // 0.7.1 FIX (Closure callback identity): the previous
                // implementation created a NEW Closure on every call to
                // register_callbacks() and bound it via `has_action($hook, $cb)`
                // — but every Closure has a different spl_object_hash, so
                // has_action() could NEVER match a previously-registered
                // Closure and the callback was registered REPEATEDLY (one
                // new entry per request). The fix uses deterministic
                // `array(__CLASS__, 'method_name')` callbacks so has_action()
                // correctly recognizes the prior registration.
                $daily = array(
                        self::HOOK_JANITOR   => 'run_janitor',
                        self::HOOK_WARMUP    => 'run_warmup',
                        self::HOOK_TELEMETRY => 'run_telemetry',
                );
                foreach ( $daily as $hook => $method ) {
                        if ( ! has_action( $hook, array( __CLASS__, $method ) ) ) {
                                add_action( $hook, array( __CLASS__, $method ) );
                        }
                }
        }

        /**
         * Activation: schedule every canonical hook if not already present.
         *
         * Idempotent — calling this repeatedly produces the same end state
         * (exactly one scheduled event per canonical hook). No DB write
         * happens for hooks that are already correctly scheduled.
         *
         * Called by Installer::activate().
         */
        public static function activate() {
                if ( ! function_exists( 'wp_schedule_event' ) ) {
                        return;
                }

                self::register_callbacks();

                // Tick: 60s cadence, first fire ~60s out so we never pile on
                // top of an in-flight request.
                if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
                        wp_schedule_event( time() + 60, self::TICK_RECURRENCE, self::HOOK_TICK );
                }

                // Daily hooks: off-peak-aware so a sleeping site never wakes
                // all nodes at the same second. The Scheduler class owns the
                // window logic — we delegate.
                $sched = new Scheduler();
                $now   = time();
                foreach ( array( self::HOOK_JANITOR, self::HOOK_WARMUP, self::HOOK_TELEMETRY ) as $hook ) {
                        if ( ! wp_next_scheduled( $hook ) ) {
                                wp_schedule_event( $sched->next_offpeak( $now ), self::DAILY_RECURRENCE, $hook );
                        }
                }

                // Mark schema as current so maybe_migrate() is a no-op on
                // freshly-activated installs.
                update_option( self::SCHEMA_VERSION_OPTION, self::CURRENT_SCHEMA_VERSION, true );

                // Invalidate the health-check transient so the very next
                // request sees a fresh check (NOT a write — set_transient
                // is one row, unlike the cron option blob).
                delete_transient( self::HEALTH_CHECK_TRANSIENT );
        }

        /**
         * Deactivation: clear ALL UP-owned events (canonical + dead).
         *
         * wp_clear_scheduled_hook() removes EVERY instance of a hook, which
         * is exactly what we want on deactivation — no leftover scheduled
         * work for a plugin that is no longer running.
         *
         * Called by Installer::deactivate() and Installer::uninstall_data().
         */
        public static function deactivate() {
                if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
                        return;
                }
                foreach ( self::ALL_KNOWN_HOOKS as $hook ) {
                        wp_clear_scheduled_hook( $hook );
                }
                delete_transient( self::HEALTH_CHECK_TRANSIENT );
                // 0.7.1: release the atomic repair lock option so a
                // deactivation/re-activation cycle never leaves a stale lock
                // row in wp_options.
                if ( function_exists( 'delete_option' ) ) {
                        delete_option( self::LOCK_OPTION_PREFIX . 'repair' );
                }
                delete_option( self::SCHEMA_VERSION_OPTION );
        }

        /**
         * Steady-state entry point. Called on every plugins_loaded.
         *
         * The transient is the write-avoidance primitive: as long as it
         * exists, we SKIP wp_next_scheduled() entirely (which itself is a
         * DB read but is what we want to gate). Once it expires (every 5
         * minutes), we re-check and reschedule any missing canonical hook.
         *
         * Net effect on a healthy site: ~1 cron-option READ every 5 minutes
         * (the get_transient path), zero cron-option WRITES.
         */
        public static function ensure_scheduled() {
                if ( ! function_exists( 'wp_schedule_event' ) ) {
                        return;
                }

                self::register_callbacks();

                // Cheap transient probe — one options read, no cron-option
                // touch. While the transient is set we trust the canonical
                // hooks are scheduled.
                $ok = get_transient( self::HEALTH_CHECK_TRANSIENT );
                if ( false !== $ok ) {
                        return;
                }

                // Transient expired — do the real check.
                $now = time();
                $sched = new Scheduler();
                if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
                        wp_schedule_event( $now + 60, self::TICK_RECURRENCE, self::HOOK_TICK );
                }
                foreach ( array( self::HOOK_JANITOR, self::HOOK_WARMUP, self::HOOK_TELEMETRY ) as $hook ) {
                        if ( ! wp_next_scheduled( $hook ) ) {
                                wp_schedule_event( $sched->next_offpeak( $now ), self::DAILY_RECURRENCE, $hook );
                        }
                }

                // Re-arm the gate. We deliberately use a short TTL so a
                // process that dies between checks is never left trusting
                // stale state for long.
                set_transient( self::HEALTH_CHECK_TRANSIENT, 1, self::HEALTH_CHECK_TTL );
        }

        /**
         * Schema migration: runs repair() once per schema bump.
         *
         * The version option tracks which schema generation the cron option
         * is in. When we ship a new schema (new canonical hooks, dead-hook
         * renames), bumping CURRENT_SCHEMA_VERSION triggers a one-shot
         * repair on the first request after the upgrade — no admin
         * interaction required.
         */
        public static function maybe_migrate() {
                $current = get_option( self::SCHEMA_VERSION_OPTION, '0' );
                if ( (string) $current === (string) self::CURRENT_SCHEMA_VERSION ) {
                        return;
                }
                // Run repair — it bumps the schema version itself on success.
                self::repair();
        }

        /**
         * Remove every duplicate event for every UP-owned hook, remove
         * dead hooks entirely, and ensure exactly one canonical event per
         * hook. Idempotent and concurrency-safe (atomic option-based lock).
         *
         * Strategy (uses ONLY WordPress Cron APIs — never edits the
         * serialized option directly):
         *   1. Acquire a 60s atomic lock (via add_option()) so concurrent
         *      repairs (two admin requests, or admin + WP-CLI) cannot race.
         *      A crashed process leaves a stale lock that the next caller
         *      takes over after LOCK_TTL seconds (0.7.1: replaced the old
         *      non-atomic transient check-then-set pattern).
         *   2. For each UP-owned hook, READ _get_cron_array() to count
         *      scheduled instances. (Read-only — no writes here.)
         *   3. For dead hooks: wp_clear_scheduled_hook() (removes all).
         *   4. For canonical hooks with >1 event: keep the SOONEST future
         *      event, wp_unschedule_event() the rest. The event's stored
         *      `args` are passed to wp_unschedule_event() so events
         *      scheduled WITH args are correctly matched (0.7.1 fix).
         *   5. For canonical hooks with 0 events: schedule one new event.
         *   6. Bump the schema version option.
         *
         * @return array{before:array<string,int>,after:array<string,int>,attempted_removals:int,successful_removals:int,failed_removals:int,removed:int,dead_removed:int,created:int,schema_bumped:bool}
         */
        public static function repair() {
                $stats = array(
                        'before'              => array(),
                        'after'               => array(),
                        'attempted_removals'  => 0,
                        'successful_removals' => 0,
                        'failed_removals'     => 0,
                        // 0.7.1 back-compat alias: `removed` is kept as a
                        // mirror of `successful_removals` so existing
                        // consumers (AdminPage, WpCliCommands, audit suite)
                        // that read $stats['removed'] continue to work.
                        'removed'             => 0,
                        'dead_removed'         => 0,
                        'created'             => 0,
                        'schema_bumped'       => false,
                );

                if ( ! function_exists( 'wp_get_schedules' ) || ! function_exists( '_get_cron_array' ) ) {
                        // WP Cron API not available — bail.
                        return $stats;
                }

                // 0.7.1: Atomic concurrency lock. The previous transient
                // pattern (check-then-set) was non-atomic — two concurrent
                // admin requests could both see "no lock" and both run
                // repair(). We now use add_option() which returns false if
                // the option already exists, giving us database-level
                // atomicity on MySQL. A crashed process leaves a stale lock
                // that the next caller takes over after LOCK_TTL seconds.
                $owner = self::acquire_lock( 'repair' );
                if ( false === $owner ) {
                        // Another repair is in progress. Don't race it.
                        $stats['schema_bumped'] = false;
                        return $stats;
                }

                try {
                        self::register_callbacks();

                        $cron = _get_cron_array();
                        $now  = time();

                        // ===== BEFORE snapshot =====
                        foreach ( self::ALL_KNOWN_HOOKS as $hook ) {
                                $stats['before'][ $hook ] = self::count_events_for( $cron, $hook );
                        }

                        // ===== Dead hooks: clear entirely =====
                        foreach ( self::DEAD_HOOKS as $hook ) {
                                if ( $stats['before'][ $hook ] > 0 ) {
                                        wp_clear_scheduled_hook( $hook );
                                        $stats['dead_removed'] += $stats['before'][ $hook ];
                                }
                        }

                        // ===== Canonical hooks: dedup + ensure-one =====
                        $sched = new Scheduler();
                        foreach ( self::canonical_hooks() as $hook ) {
                                $count = $stats['before'][ $hook ];
                                if ( 0 === $count ) {
                                        // Missing — create one.
                                        if ( self::HOOK_TICK === $hook ) {
                                                wp_schedule_event( $now + 60, self::TICK_RECURRENCE, $hook );
                                        } else {
                                                wp_schedule_event( $sched->next_offpeak( $now ), self::DAILY_RECURRENCE, $hook );
                                        }
                                        ++$stats['created'];
                                        continue;
                                }
                                if ( 1 === $count ) {
                                        // Already healthy for this hook — nothing to do.
                                        continue;
                                }
                                // >1 events: keep the soonest future one, drop the rest.
                                $events = self::events_for( $cron, $hook );
                                // Sort ascending by timestamp.
                                usort(
                                        $events,
                                        static function ( $a, $b ) {
                                                return $a['ts'] <=> $b['ts'];
                                        }
                                );
                                $kept = false;
                                foreach ( $events as $ev ) {
                                        if ( ! $kept ) {
                                                // Keep the first (soonest).
                                                $kept = true;
                                                continue;
                                        }
                                        // 0.7.1 FIX (Issue #1): pass the
                                        // event's stored args to
                                        // wp_unschedule_event() so events
                                        // scheduled WITH args are correctly
                                        // matched. WordPress identifies cron
                                        // events by the (timestamp, hook, args)
                                        // tuple — without args, an event with
                                        // non-empty args is never matched and
                                        // the unschedule silently fails.
                                        ++$stats['attempted_removals'];
                                        $ok = wp_unschedule_event( $ev['ts'], $hook, $ev['args'] );
                                        if ( true === $ok ) {
                                                ++$stats['successful_removals'];
                                                ++$stats['removed']; // back-compat alias.
                                        } else {
                                                ++$stats['failed_removals'];
                                        }
                                }
                        }

                        // ===== AFTER snapshot =====
                        $cron_after = _get_cron_array();
                        foreach ( self::ALL_KNOWN_HOOKS as $hook ) {
                                $stats['after'][ $hook ] = self::count_events_for( $cron_after, $hook );
                        }

                        // Mark schema current so maybe_migrate() is a no-op
                        // until the next schema bump.
                        $old = get_option( self::SCHEMA_VERSION_OPTION, '0' );
                        update_option( self::SCHEMA_VERSION_OPTION, self::CURRENT_SCHEMA_VERSION, true );
                        $stats['schema_bumped'] = (string) $old !== (string) self::CURRENT_SCHEMA_VERSION;

                        // Re-arm the health-check gate so ensure_scheduled()
                        // trusts this state for the next HEALTH_CHECK_TTL.
                        set_transient( self::HEALTH_CHECK_TRANSIENT, 1, self::HEALTH_CHECK_TTL );
                } finally {
                        // 0.7.1: Always release the atomic lock — even on
                        // exception. release_lock() is owner-safe: if the
                        // lock was taken over by another process (because
                        // this one exceeded LOCK_TTL), the owner check fails
                        // and we leave the new owner's lock alone.
                        self::release_lock( 'repair', $owner );
                }

                return $stats;
        }

        /**
         * Status report — for the admin UI and WP-CLI.
         *
         * Returns one row per UP-owned hook with: expected count, actual
         * scheduled count, next execution timestamp, recurrence, status.
         *
         * @return array<int,array{name:string,expected:int,actual:int,next_ts:int|false,recurrence:string,status:string}>
         */
        public static function get_status() {
                $cron  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
                $rows  = array();

                foreach ( self::canonical_hooks() as $hook ) {
                        $count = self::count_events_for( $cron, $hook );
                        $next  = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;
                        $recur = self::HOOK_TICK === $hook ? self::TICK_RECURRENCE : self::DAILY_RECURRENCE;
                        $status = 'Missing';
                        if ( $count > 1 ) {
                                $status = 'Duplicate';
                        } elseif ( 1 === $count ) {
                                $status = 'Healthy';
                        }
                        $rows[] = array(
                                'name'       => $hook,
                                'expected'   => 1,
                                'actual'     => $count,
                                'next_ts'    => $next,
                                'recurrence' => $recur,
                                'status'     => $status,
                        );
                }

                // Dead hooks — expected count is 0.
                foreach ( self::DEAD_HOOKS as $hook ) {
                        $count = self::count_events_for( $cron, $hook );
                        $rows[] = array(
                                'name'       => $hook,
                                'expected'   => 0,
                                'actual'     => $count,
                                'next_ts'    => ( $count && function_exists( 'wp_next_scheduled' ) ) ? wp_next_scheduled( $hook ) : false,
                                'recurrence' => '(dead)',
                                'status'     => 0 === $count ? 'Healthy' : 'Stray',
                        );
                }

                return $rows;
        }

        /**
         * Ensure a SINGLE (one-shot) event for a hook is scheduled at
         * $offset seconds from now — IF none is already due soon.
         *
         * Used by the WPCron queue backend to wake the tick worker without
         * bloating the cron option. The non-atomic legacy pattern:
         *   if (!wp_next_scheduled('ultimate_performance_tick'))
         *       wp_schedule_single_event(time()+30, 'ultimate_performance_tick');
         * was a major duplicate-creator under concurrent enqueue storms.
         * CronGuard wraps it behind the same transient-gated health-check
         * used by ensure_scheduled() so steady-state requests skip the
         * DB probe entirely.
         *
         * @param string $hook   Hook name (canonical).
         * @param int    $offset  Seconds from now to fire.
         */
        public static function ensure_single_event( $hook, $offset = 30 ) {
                if ( ! function_exists( 'wp_schedule_single_event' ) ) {
                        return;
                }
                // Cheap transient probe. If we recently verified the tick
                // hook is scheduled, don't bother re-probing wp_next_scheduled
                // for the one-shot wake — wp_schedule_single_event is itself
                // deduplicated against the recurring tick by WP core (same
                // hook, future timestamp).
                $gate = get_transient( self::HEALTH_CHECK_TRANSIENT );
                if ( false === $gate ) {
                        // Gate expired — be cautious: only schedule if no
                        // pending instance is within the next 30s window.
                        $next = wp_next_scheduled( $hook );
                        $now  = time();
                        if ( false === $next || (int) $next > $now + (int) $offset ) {
                                wp_schedule_single_event( $now + (int) $offset, $hook );
                        }
                        // Re-arm the gate.
                        set_transient( self::HEALTH_CHECK_TRANSIENT, 1, self::HEALTH_CHECK_TTL );
                        return;
                }
                // Gate still warm — trust the previous health check. WP's
                // wp_schedule_single_event has its own internal dedup against
                // identical (ts,hook,args) tuples so concurrent enqueues in
                // the same second are still safe.
                wp_schedule_single_event( time() + (int) $offset, $hook );
        }

        // ===== Cron tick callbacks =====

        /**
         * Tick callback — dispatches the queue worker.
         *
         * Bound to the 'ultimate_performance_tick' action. WP-Cron fires
         * this once per up_every_minute interval (and any ad-hoc single
         * events scheduled by the queue backend). The callback itself is
         * stateless and idempotent — running it twice just runs the worker
         * twice, which is safe (the worker is single-flight locked).
         */
        public static function tick_callback() {
                if ( class_exists( '\UltimatePerformance\Queue\QueueManager' ) ) {
                        \UltimatePerformance\Queue\QueueManager::cron_tick();
                }
        }

        // ===== Deterministic daily-hook callbacks (0.7.1) ============
        //
        // These are the per-hook entry points bound by register_callbacks()
        // to each canonical daily action. They use `array(__CLASS__, 'method')`
        // callback identity so has_action() correctly recognizes a prior
        // registration and prevents duplicates (replacing the previous
        // anonymous Closure whose spl_object_hash differed on every call,
        // causing the callback to be re-registered on every request).

        /** Daily janitor callback — bound to HOOK_JANITOR. */
        public static function run_janitor()   { self::run_single_job( self::HOOK_JANITOR ); }
        /** Daily warmup callback — bound to HOOK_WARMUP. */
        public static function run_warmup()    { self::run_single_job( self::HOOK_WARMUP ); }
        /** Daily telemetry callback — bound to HOOK_TELEMETRY. */
        public static function run_telemetry() { self::run_single_job( self::HOOK_TELEMETRY ); }

        /**
         * Run one Scheduler job for a daily hook, then reschedule exactly
         * one next occurrence (clearing old + scheduling new — idempotent).
         *
         * Replaces the legacy Scheduler::dispatch_due() iteration which
         * was never wired to add_action callbacks (so the daily hooks were
         * dead). This method is the per-hook entry point bound by CronGuard
         * to each canonical daily action.
         *
         * @param string $hook One of the canonical daily hooks.
         */
        public static function run_single_job( $hook ) {
                $sched = new Scheduler();
                // Run the job (backpressure-guarded, bounded runtime).
                $sched->run_single_job( $hook );

                // Self-perpetuate: clear ALL old occurrences and book one
                // new off-peak occurrence. Idempotent — even if the cron
                // tick somehow fires twice, we end with one event.
                if ( function_exists( 'wp_clear_scheduled_hook' ) && function_exists( 'wp_schedule_event' ) ) {
                        wp_clear_scheduled_hook( $hook );
                        wp_schedule_event( $sched->next_offpeak( time() ), self::DAILY_RECURRENCE, $hook );
                }
        }

        // ===== Internal helpers =====

        /**
         * Count scheduled events for a hook in a cron array snapshot.
         *
         * @param array<string,mixed> $cron
         * @param string               $hook
         * @return int
         */
        private static function count_events_for( $cron, $hook ) {
                $n = 0;
                if ( ! is_array( $cron ) ) {
                        return 0;
                }
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

        /**
         * Enumerate (ts, key, args) events for a hook in a cron array snapshot.
         *
         * 0.7.1 FIX (Issue #1): the previous implementation captured only
         * `(ts, key)` — but the `$key` is `md5(serialize($args))` and the
         * `$instance` array also carries `args`. Without `args`, callers
         * had no way to pass the correct args to wp_unschedule_event(),
         * which identifies events by the (timestamp, hook, args) tuple.
         *
         * @param array<string,mixed> $cron
         * @param string              $hook
         * @return array<int,array{ts:int,key:string,args:array<mixed>}>
         */
        private static function events_for( $cron, $hook ) {
                $out = array();
                if ( ! is_array( $cron ) ) {
                        return $out;
                }
                foreach ( $cron as $ts => $hooks ) {
                        if ( ! is_array( $hooks ) || ! isset( $hooks[ $hook ] ) ) {
                                continue;
                        }
                        foreach ( (array) $hooks[ $hook ] as $key => $instance ) {
                                $args = is_array( $instance ) && isset( $instance['args'] )
                                        ? (array) $instance['args']
                                        : array();
                                $out[] = array(
                                        'ts'   => (int) $ts,
                                        'key'  => (string) $key,
                                        'args' => $args,
                                );
                        }
                }
                return $out;
        }

        // ===== Atomic concurrency lock (0.7.2 — CAS-based) ================

        /**
         * Acquire an atomic lock for a named purpose (default: 'repair').
         *
         * 0.7.2 FIX: the stale-lock takeover path now uses Compare-And-Swap
         * (CAS) semantics via a direct SQL UPDATE with a WHERE clause that
         * checks the exact old value. Only the FIRST process to execute the
         * UPDATE will affect 1 row; all subsequent processes will affect 0
         * rows and correctly fail.
         *
         * Fresh lock: add_option() — atomic INSERT (returns false if exists)
         * Stale lock: CAS UPDATE — atomic conditional UPDATE
         *
         * @param string $purpose Lock name suffix (default 'repair').
         * @return string|false The owner token on success, false if held.
         */
        private static function acquire_lock( $purpose = 'repair' ) {
                $option = self::LOCK_OPTION_PREFIX . $purpose;
                $owner  = function_exists( 'wp_generate_password' )
                        ? wp_generate_password( 32, false )
                        : md5( (string) uniqid( 'up_lock_', true ) );
                $now    = time();
                $payload = array(
                        'owner'   => $owner,
                        'created' => $now,
                        'expires' => $now + self::LOCK_TTL,
                );

                // Path 1: Fresh lock acquisition via atomic INSERT.
                if ( function_exists( 'add_option' ) && add_option( $option, $payload, '', false ) ) {
                        return $owner; // Lock acquired.
                }

                // Path 2: Lock exists — check if stale/malformed, take over via CAS.
                if ( ! function_exists( 'get_option' ) ) {
                        return false;
                }
                $existing = get_option( $option, null );
                $is_stale = false;
                if ( ! is_array( $existing ) || ! isset( $existing['expires'] ) ) {
                        $is_stale = true; // Malformed
                } elseif ( (int) $existing['expires'] < $now ) {
                        $is_stale = true; // Expired
                }
                if ( ! $is_stale ) {
                        return false; // Lock held by another process and not stale.
                }
                // CAS: atomically take over the stale lock. Only the FIRST
                // process to execute this UPDATE will succeed (1 row affected).
                // All competing processes will affect 0 rows and return false.
                return self::cas_takeover( $option, $existing, $payload, $owner );
        }

        /**
         * Compare-And-Swap lock takeover.
         *
         * Executes a direct SQL UPDATE that only succeeds if the current
         * database value matches the exact value we observed before takeover.
         * This is the ONLY way to atomically claim an expired lock without
         * a TOCTOU race.
         *
         * After successful CAS, the WordPress option cache is invalidated
         * so get_option() cannot return the previous (stale) owner.
         *
         * @param string       $option   Option name.
         * @param mixed        $old_value The value we observed (unserialized).
         * @param array        $new_payload The new lock payload.
         * @param string       $owner    The owner token for the new lock.
         * @return string|false Owner token on success, false on CAS failure.
         */
        private static function cas_takeover( $option, $old_value, $new_payload, $owner ) {
                global $wpdb;

                if ( ! $wpdb || ! ( $wpdb instanceof \wpdb ) ) {
                        // 0.7.3 FIX: FAIL CLOSED. Without $wpdb we cannot
                        // perform the atomic CAS UPDATE. Calling
                        // update_option() would be non-atomic (a TOCTOU race),
                        // and returning $owner would falsely claim lock
                        // acquisition. The locking contract requires that
                        // acquire success PROVES exclusive ownership — if
                        // we cannot prove it, we must fail.
                        return false;
                }

                $old_serialized = maybe_serialize( $old_value );
                $new_serialized = maybe_serialize( $new_payload );

                // CAS: UPDATE only if the row still has the OLD value.
                // MySQL serializes the UPDATE on this row; only one process
                // can match the WHERE clause and affect 1 row.
                $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                        $new_serialized,
                        $option,
                        $old_serialized
                ) );

                if ( $wpdb->rows_affected > 0 ) {
                        // CAS succeeded — we own the lock now.
                        // Invalidate WordPress option cache so subsequent
                        // get_option() calls see our new value, not the
                        // old (expired) value that may still be cached.
                        if ( function_exists( 'wp_cache_delete' ) ) {
                                wp_cache_delete( $option, 'options' );
                                // Also invalidate alloptions cache if present
                                // (non-autoload options shouldn't be in it,
                                // but be defensive).
                                wp_cache_delete( 'alloptions', 'options' );
                        }
                        return $owner;
                }

                // CAS failed — another process already took over the lock.
                return false;
        }

        /**
         * Release a previously-acquired lock.
         *
         * 0.7.2 FIX: uses CAS (conditional DELETE) so a stale former owner
         * cannot delete a new owner's lock. The DELETE only succeeds if the
         * database row still contains the exact value owned by the caller.
         *
         * @param string $purpose Lock name suffix.
         * @param string $owner  The owner token returned by acquire_lock().
         */
        private static function release_lock( $purpose, $owner ) {
                if ( ! function_exists( 'get_option' ) ) {
                        return;
                }
                $option   = self::LOCK_OPTION_PREFIX . $purpose;
                $existing = get_option( $option, null );
                if ( ! is_array( $existing ) || ! isset( $existing['owner'] ) || $existing['owner'] !== $owner ) {
                        return; // Not our lock — another process owns it now.
                }
                // CAS DELETE: only succeeds if the row still has OUR value.
                global $wpdb;
                if ( ! $wpdb || ! ( $wpdb instanceof \wpdb ) ) {
                        // 0.7.3 FIX: FAIL CLOSED. Without $wpdb we cannot
                        // perform the atomic CAS DELETE. Calling
                        // delete_option() would be non-atomic (a TOCTOU race)
                        // and could delete a lock belonging to another process.
                        // Leave the lock in place — TTL/stale recovery will
                        // handle it later. Safety over eager cleanup.
                        return;
                }
                $old_serialized = maybe_serialize( $existing );
                $wpdb->query( $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                        $option,
                        $old_serialized
                ) );
                if ( $wpdb->rows_affected > 0 ) {
                        if ( function_exists( 'wp_cache_delete' ) ) {
                                wp_cache_delete( $option, 'options' );
                                wp_cache_delete( 'alloptions', 'options' );
                        }
                }
        }
}
