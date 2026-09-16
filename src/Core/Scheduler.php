<?php
/**
 * WP-Cron scheduler (Phase K) — off-peak, timezone-aware, jittered,
 * deduplicated, backpressure-guarded recurring maintenance.
 *
 * Owns the plugin's cron hooks (janitor sweep, warmup run, telemetry
 * snapshot) and the rules that make them safe on shared hosting:
 *  - OFF-PEAK + TIMEZONE: heavy jobs land inside the site's local
 *    [OFFPEAK_START, OFFPEAK_END) window (timezone_string option, else PHP
 *    default, else UTC — validated),
 *  - JITTER: the occurrence is randomized inside the window so thousands of
 *    sites never wake their daemons in the same second,
 *  - DEDUP: register() is idempotent — a hook already scheduled for the
 *    future is never double-booked; dispatch re-books exactly one next
 *    occurrence per run,
 *  - BACKPRESSURE: a hook whose previous run still holds its FileLock is
 *    SKIPPED this tick (never queued, never stacked); a budget caps every
 *    run so a slow night can never occupy a worker for long.
 *
 * @package UltimatePerformance\Core
 */

namespace UltimatePerformance\Core;

use UltimatePerformance\Core\Lock\FileLock;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	const CRON_JANITOR   = 'ultimate_cache/janitor_tick';
	const CRON_WARMUP    = 'ultimate_cache/warmup_tick';
	const CRON_TELEMETRY = 'ultimate_cache/telemetry_tick';

	const OFFPEAK_START = 1;   // site-local hour, window start (inclusive)
	const OFFPEAK_END   = 5;   // site-local hour, window end (exclusive)
	const RUN_BUDGET_S  = 10;  // per-dispatch runtime budget (seconds)
	const LOCK_TTL_S    = 300; // backpressure lock validity window

	/** @var string lock/state dir under the hardened cache tree */
	private $base;

	/** @var array<string,callable> hook => job (test/inject seam) */
	private $jobs;

	/**
	 * @param string|null               $base Lock dir override (tests).
	 * @param array<string,callable>    $jobs Job overrides (tests).
	 */
	public function __construct( $base = null, array $jobs = array() ) {
		$this->base = null === $base
			? ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/cache/ultimate-performance/meta'
			: rtrim( (string) $base, '/' );
		if ( ! is_dir( $this->base ) ) {
			@mkdir( $this->base, 0775, true );
		}
		$this->jobs = $jobs;
	}

	/**
	 * @return array<int,string> The plugin-owned cron hooks.
	 */
	public static function scheduled_hooks() {
		return array( self::CRON_JANITOR, self::CRON_WARMUP, self::CRON_TELEMETRY );
	}

	/**
	 * Site timezone (timezone_string option, else PHP default, else UTC —
	 * always validated, never trusted).
	 *
	 * @return string
	 */
	public static function site_tz() {
		$tz = '';
		if ( function_exists( 'get_option' ) ) {
			$tz = (string) get_option( 'timezone_string', '' );
		}
		if ( '' === $tz ) {
			$tz = (string) @date_default_timezone_get();
		}
		try {
			new \DateTimeZone( $tz );
		} catch ( \Throwable $e ) {
			$tz = 'UTC';
		}
		return $tz;
	}

	/**
	 * Next off-peak occurrence: the site-local window start plus a uniform
	 * random jitter (never in the past; inside today's remaining window when
	 * we are already inside it).
	 *
	 * @param int|null $now Test seam (defaults to time()).
	 * @return int Unix timestamp.
	 */
	public function next_offpeak( $now = null ) {
		$now      = null === $now ? time() : (int) $now;
		$tz       = new \DateTimeZone( self::site_tz() );
		$window_s = ( self::OFFPEAK_END - self::OFFPEAK_START ) * 3600;
		$local    = ( new \DateTime( '@' . $now ) )->setTimezone( $tz );
		$h        = (int) $local->format( 'G' );
		$today    = $local->format( 'Y-m-d' );
		$start_ts = ( new \DateTime( $today . sprintf( ' %02d:00:00', self::OFFPEAK_START ), $tz ) )->getTimestamp();

		if ( $h < self::OFFPEAK_START ) {
			// Before today's window: jitter across the whole window.
			return $start_ts + random_int( 0, max( 60, $window_s - 60 ) );
		}
		if ( $h < self::OFFPEAK_END ) {
			// Inside today's window: jitter across the REMAINING part.
			$remaining = $start_ts + $window_s - $now;
			return $now + random_int( 60, max( 120, $remaining - 60 ) );
		}
		// After today's window: tomorrow's window, full jitter.
		$tomorrow = ( new \DateTime( $today . ' 00:00:00', $tz ) )->modify( '+1 day' );
		$tstart   = ( new \DateTime( $tomorrow->format( 'Y-m-d' ) . sprintf( ' %02d:00:00', self::OFFPEAK_START ), $tz ) )->getTimestamp();
		return $tstart + random_int( 0, max( 60, $window_s - 60 ) );
	}

	/**
	 * Register all plugin cron hooks (idempotent / deduplicated).
	 *
	 * @param int|null $now Test seam.
	 * @return array<int,string> Hooks that were newly scheduled.
	 */
	public function register( $now = null ) {
		$now        = null === $now ? time() : (int) $now;
		$scheduled  = array();
		if ( ! function_exists( 'wp_schedule_event' ) ) {
			return $scheduled;
		}
		foreach ( self::scheduled_hooks() as $hook ) {
			// DEDUP: an already-scheduled future occurrence is never double-booked.
			$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;
			if ( false !== $next && (int) $next > $now ) {
				continue;
			}
			if ( wp_schedule_event( $this->next_offpeak( $now ), 'daily', $hook ) ) {
				$scheduled[] = $hook;
			}
		}
		return $scheduled;
	}

	/**
	 * Cron tick: dispatch every due hook. Runs under a per-hook FileLock
	 * (backpressure: a still-running predecessor SKIPS this tick) and a hard
	 * runtime budget, then re-books exactly one next off-peak occurrence.
	 *
	 * @param int|null $now Test seam.
	 * @return array<int,string> Hooks that actually ran.
	 */
	public function dispatch_due( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$ran = array();
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return $ran;
		}
		foreach ( self::scheduled_hooks() as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( false === $ts || (int) $ts > $now ) {
				continue; // not due
			}
			if ( $this->run_job( $hook ) ) {
				$ran[] = $hook;
			}
			// Self-perpetuate: one next occurrence, whatever the run outcome.
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( $hook );
			}
			if ( function_exists( 'wp_schedule_event' ) ) {
				wp_schedule_event( $this->next_offpeak( $now ), 'daily', $hook );
			}
		}
		return $ran;
	}

	/**
	 * @param string $hook
	 * @return bool True when the job ran (not skipped by backpressure).
	 */
	private function run_job( $hook ) {
		if ( ! is_dir( $this->base ) ) {
			@mkdir( $this->base, 0775, true );
		}
		$lock = new FileLock( $this->base . '/sched-' . md5( $hook ) . '.lock' );
		if ( ! $lock->acquire( self::LOCK_TTL_S ) ) {
			return false; // BACKPRESSURE: the previous run is still active
		}
		try {
			$job      = $this->resolve_job( $hook );
			$deadline = microtime( true ) + self::RUN_BUDGET_S;
			call_user_func( $job, $deadline );
			return true;
		} catch ( \Throwable $e ) {
			return false; // a failing job must never break the cron tick
		} finally {
			$lock->release();
		}
	}

	/**
	 * @param string $hook
	 * @return callable fn( int $deadline_microts ): void
	 */
	private function resolve_job( $hook ) {
		if ( isset( $this->jobs[ $hook ] ) && is_callable( $this->jobs[ $hook ] ) ) {
			return $this->jobs[ $hook ];
		}
		switch ( $hook ) {
			case self::CRON_JANITOR:
				return function ( $deadline ) {
					if ( ! class_exists( '\PDO' ) || ! in_array( 'sqlite', \PDO::getAvailableDrivers(), true ) || ! class_exists( '\UltimatePerformance\ObjectCache\SqliteBackend' ) ) {
						return; // nothing to sweep on this host
					}
					$b = new \UltimatePerformance\ObjectCache\SqliteBackend();
					while ( microtime( true ) < $deadline ) {
						$deleted = $b->janitor( 1 ); // one bounded chunk per pass
						if ( false === $deleted || $deleted < 1 ) {
							break; // done (or backend degraded)
						}
					}
					$b->close();
				};
			case self::CRON_WARMUP:
				return function ( $deadline ) {
					if ( ! class_exists( '\UltimatePerformance\Warmup\Runner' ) ) {
						return;
					}
					( new \UltimatePerformance\Warmup\Runner() )->run();
				};
			case self::CRON_TELEMETRY:
				return function ( $deadline ) {
					if ( class_exists( '\UltimatePerformance\Core\Telemetry' ) ) {
						( new Telemetry() )->write_snapshot();
					}
				};
		}
		return function () {
			return true;
		};
	}
}
