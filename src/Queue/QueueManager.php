<?php
/**
 * Queue subsystem — backend-agnostic job runner with fallback chain.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Queue;

use UltimatePerformance\Core\Settings;
use UltimatePerformance\Queue\Backend\Backend;
use UltimatePerformance\Queue\Backend\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a backend cannot durably persist a job. QueueManager treats it
 * as "backend failed" and falls through to the next backend in the chain.
 */
class EnqueueException extends \RuntimeException {
}

/**
 * Picks queue backends and routes jobs.
 * Chain: RabbitMQ → Action Scheduler → WP-Cron → full synchronous processing.
 *
 * Correctness contract (Phase H):
 *  - a backend "success" requires durable persistence (positive receipt);
 *  - any enqueue failure propagates and triggers the next backend;
 *  - large payloads are split into byte-budgeted chunks (lossless);
 *  - if every async backend fails, the WHOLE remaining workload runs
 *    synchronously — partial-sync-with-success is forbidden;
 *  - accounting is kept for submitted/queued/processed/failed/remaining.
 */
final class QueueManager {

	/** Whitelisted job types — payload schemas validated per type. */
	const JOB_TYPES = array( 'purge_dirs', 'preload_url', 'regenerate' );

	/**
	 * Serialized-payload budget per queued job, in JSON bytes. Action Scheduler
	 * stores args in VARCHAR(8000)/VARCHAR(191)+extended_args and rejects
	 * anything whose encoded form exceeds ~8KB (factory swallows the error and
	 * returns 0). 4096 leaves comfortable headroom under that ceiling while
	 * keeping per-job DB rows small.
	 */
	const CHUNK_BYTES = 4096;

	/**
	 * Exact JSON overhead added around a chunk when it becomes a purge_dirs
	 * payload: wp_json_encode( array( 'dirs' => $chunk ) ) === '{"dirs":' . $chunk . '}'
	 * i.e. chunk_bytes + 9. Chunking subtracts this so the INVARIANT
	 * strlen(wp_json_encode(array('dirs' => $chunk))) <= CHUNK_BYTES is
	 * mathematically exact, not approximate.
	 */
	const DIRS_WRAPPER_BYTES = 9;

	/** @var QueueManager|null */
	private static $instance = null;

	/** @var Backend|null resolved lazily (worker/diagnostics only) */
	private $backend = null;

	/** @var int sync jobs executed this request (re-entrancy guard only) */
	private $sync_count = 0;

	/** @var int hard ceiling on synchronous jobs per request (DoS bound for direct callers) */
	private $sync_limit = 200;

	/** @var array<string,bool> per-request negative cache: backend known-dead */
	private $dead_backends = array();

	/** @var array<string,mixed>|null accounting for the most recent enqueue() */
	private $last_accounting = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Enqueue a job. Never throws. Falls through the backend chain; if all
	 * async backends fail, processes the full validated workload synchronously.
	 *
	 * @param string              $type One of JOB_TYPES.
	 * @param array<string,mixed> $payload Validated payload.
	 * @return string Job id ('' when rejected).
	 */
	public function enqueue( $type, $payload = array() ) {
		$this->last_accounting = null;
		if ( ! in_array( $type, self::JOB_TYPES, true ) ) {
			return '';
		}
		$payload = $this->validate_payload( $type, is_array( $payload ) ? $payload : array() );
		if ( null === $payload ) {
			return '';
		}

		if ( 'purge_dirs' === $type ) {
			return $this->enqueue_chunked( $type, $payload['dirs'] );
		}
		return $this->dispatch_units( $type, array( array( 'url' => $payload['url'] ) ) );
	}

	/**
	 * Split the validated dir list into deterministic byte-budgeted chunks and
	 * route each chunk through the backend chain independently (partial-failure
	 * isolation: earlier successful chunks are never retried or duplicated).
	 *
	 * @param string           $type
	 * @param array<int,string> $dirs Validated, unique, ordered dir list.
	 * @return string First chunk's job id ('' when nothing persisted).
	 */
	private function enqueue_chunked( $type, array $dirs ) {
		$chunks = self::chunk_dirs( $dirs, self::CHUNK_BYTES );
		$units  = array();
		foreach ( $chunks as $chunk ) {
			$units[] = array( 'dirs' => $chunk );
		}
		return $this->dispatch_units( $type, $units );
	}

	/**
	 * Route each payload unit through the backend chain; fall back to full
	 * synchronous processing per unit when every async backend fails. Keeps
	 * explicit accounting; never silently discards a unit.
	 *
	 * @param string                       $type
	 * @param array<int,array<string,mixed>> $units Payload units (each becomes one backend job).
	 * @return string Job id of first successfully queued unit ('' otherwise).
	 */
	private function dispatch_units( $type, array $units ) {
		$total = 0;
		foreach ( $units as $u ) {
			$total += isset( $u['dirs'] ) ? count( $u['dirs'] ) : 1;
		}

		$accounting = array(
			'submitted' => $total,
			'queued'    => 0,
			'processed' => 0,
			'failed'    => 0,
			'remaining' => $total,
			'lost'      => 0,
			'first_job_id' => '',
			'attempts'  => array(),
		);

		$first_id = '';
		foreach ( $units as $unit ) {
			$count = isset( $unit['dirs'] ) ? count( $unit['dirs'] ) : 1;
			$job   = new Job( $type, $unit );

			try {
				$id = $this->enqueue_via_chain( $job );
			} catch ( \Throwable $e ) {
				// Chain exhausted → synchronous processing of THIS unit.
				// The whole unit runs; a handler failure is recorded as an
				// explicit failure — never as success-with-loss.
				if ( $this->run_sync_full( $job ) ) {
					$accounting['processed'] += $count;
					$accounting['remaining'] -= $count;
					$accounting['attempts'][] = 'sync:ok';
					if ( '' === $first_id ) {
						$first_id = $job->id;
					}
				} else {
					$accounting['failed'] += $count;
					$accounting['remaining'] -= $count;
					$accounting['lost']     += $count;
					$accounting['attempts'][] = 'sync:failed';
					$this->record_failure( $type, $unit, $e->getMessage() );
				}
				continue;
			}

			$accounting['queued']    += $count;
			$accounting['remaining'] -= $count;
			$accounting['attempts'][] = 'queued:' . $id;
			if ( '' === $first_id ) {
				$first_id = $id;
			}
		}

		$this->last_accounting = $accounting;
		return $first_id;
	}

	/**
	 * Walk the full backend chain for ONE job. Every failure (unavailable,
	 * dependency missing, enqueue throw, suspicious receipt) moves to the next
	 * backend. Throws EnqueueException when no async backend accepted the job.
	 *
	 * @param Job $job
	 * @return string Backend receipt id.
	 * @throws EnqueueException When the whole chain fails.
	 */
	private function enqueue_via_chain( Job $job ) {
		$configured = (string) Settings::instance()->get( 'queue_backend', 'auto' );
		$chain      = 'auto' === $configured
			? array( 'rabbitmq', 'action-scheduler', 'wp-cron' )
			: array( $configured );

		$errors = array();
		foreach ( $chain as $name ) {
			if ( ! empty( $this->dead_backends[ $name ] ) ) {
				$errors[] = $name . ':known-dead';
				continue;
			}
			$cls = 'UltimatePerformance\\Queue\\BackendImpl\\' . self::studly( $name );
			if ( ! class_exists( $cls ) ) {
				$this->dead_backends[ $name ] = true;
				$errors[]                     = $name . ':missing';
				continue;
			}
			try {
				$candidate = new $cls();
				if ( ! $candidate instanceof Backend ) {
					throw new EnqueueException( 'not a backend' );
				}
				if ( ! $candidate->available() ) {
					$this->dead_backends[ $name ] = true;
					$errors[]                     = $name . ':unavailable';
					continue;
				}
				$id = $candidate->enqueue( $job );
				// Defense-in-depth: a backend must NEVER hand back a falsy or
				// placeholder receipt ("0", '', 0, null). Treat as failure and
				// move on even if a future backend misbehaves.
				if ( ! is_string( $id ) || '' === $id || '0' === $id ) {
					throw new EnqueueException( 'invalid receipt: ' . var_export( $id, true ) );
				}
				return $id;
			} catch ( \Throwable $e ) {
				$errors[] = $name . ': ' . substr( (string) $e->getMessage(), 0, 120 );
				$this->note_fallback( $name, $e->getMessage() );
				continue;
			}
		}
		throw new EnqueueException( 'all queue backends failed: ' . implode( ' | ', $errors ) );
	}

	/**
	 * Deterministic byte-aware chunking. Greedy accumulation of validated
	 * items until the ENCODED PURGE PAYLOAD would exceed $budget. The budget
	 * applies to what the backend actually serializes —
	 * wp_json_encode( array( 'dirs' => $chunk ) ) — so DIRS_WRAPPER_BYTES is
	 * reserved up-front and the invariant strlen(wp_json_encode($payload)) <=
	 * CHUNK_BYTES holds EXACTLY (H3 byte-budget fix: the old incremental
	 * bookkeeping measured the bare chunk array, undercounting by the 9-byte
	 * wrapper, letting chunks reach budget+9 on the wire).
	 *
	 * An individual item larger than the budget always gets its own
	 * single-element chunk (no infinite loop; the item is preserved verbatim).
	 * Such a chunk exceeds the budget by construction; backends that cannot
	 * carry it must fail its enqueue (never truncate) so QueueManager's chain
	 * walk / synchronous fallback handles it — zero silent loss.
	 *
	 * Invariant: array_merge(...$chunks) === $dirs (order preserved,
	 * nothing dropped, nothing duplicated).
	 *
	 * @param array<int,string> $dirs
	 * @param int               $budget Byte budget for the encoded payload.
	 * @return array<int,array<int,string>>
	 */
	public static function chunk_dirs( array $dirs, $budget ) {
		$budget    = max( 64 + self::DIRS_WRAPPER_BYTES, (int) $budget - self::DIRS_WRAPPER_BYTES );
		$chunks    = array();
		$current   = array();
		$current_b = 2; // "[]" encoding floor.

		foreach ( $dirs as $d ) {
			$item_b = strlen( (string) wp_json_encode( (string) $d ) );
			$need   = ( empty( $current ) ? $current_b : $current_b + 1 ) + $item_b;
			if ( $need > $budget && ! empty( $current ) ) {
				$chunks[]  = $current;
				$current   = array();
				$current_b = 2;
				$need      = $current_b + $item_b;
			}
			$current[]   = $d;
			$current_b   = $need;
		}
		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}
		return $chunks;
	}

	/**
	 * Cron/worker tick: drain up to N jobs from the active backend.
	 *
	 * @param int $max
	 * @return int Jobs processed.
	 */
	public function work( $max = 20 ) {
		$backend = $this->resolve_backend();
		if ( null === $backend || ! $backend->supports_worker() ) {
			return 0;
		}
		$n = 0;
		while ( $n < $max ) {
			$job = $backend->claim();
			if ( null === $job ) {
				break;
			}
			$ok     = false;
			$thrown = null;
			try {
				Handlers::dispatch( $job );
				$ok = true;
			} catch ( \Throwable $e ) {
				$thrown = $e;
			}
			// H3 silent-loss fix: completion tracking is OBSERVABILITY, not
			// correctness. If complete() itself throws (e.g. WPCron re-enqueue
			// of a failed job hits the 500-entry buffer cap) the failure must
			// still be recorded — never vanish with the claimed job.
			try {
				$backend->complete( $job, $ok );
			} catch ( \Throwable $e ) {
				// completion tracking failure must not kill the loop, but the
				// lost job IS recorded as an explicit failure.
				$ok = false;
				$this->record_failure_safe( $job, 'complete() threw: ' . $e->getMessage() );
			}
			if ( ! $ok ) {
				$this->record_failure_safe(
					$job,
					( null !== $thrown ? $thrown->getMessage() : 'unknown error' )
				);
				if ( defined( 'ULTIMATE_PERFORMANCE_DEBUG' ) && null !== $thrown ) {
					error_log( '[ultimate-performance] job failed: ' . $thrown->getMessage() ); // phpcs:ignore
				}
			}
			++$n;
		}
		return $n;
	}

	/**
	 * Strict per-type payload validation. Unknown shapes rejected (poison guard).
	 *
	 * @return array<string,mixed>|null
	 */
	private function validate_payload( $type, $payload ) {
		switch ( $type ) {
			case 'purge_dirs':
				$dirs = array();
				foreach ( isset( $payload['dirs'] ) && is_array( $payload['dirs'] ) ? $payload['dirs'] : array() as $d ) {
					if ( is_string( $d ) && '' !== $d && strlen( $d ) <= 512 && preg_match( '/^[A-Za-z0-9@\/_.()\-]+$/', $d ) && false === strpos( $d, '..' ) ) {
						$dirs[] = $d;
					}
				}
				$dirs = array_values( array_unique( $dirs ) );
				return empty( $dirs ) ? null : array( 'dirs' => $dirs );

			case 'preload_url':
			case 'regenerate':
				$url = isset( $payload['url'] ) ? (string) $payload['url'] : '';
				if ( '' === $url || strlen( $url ) > 2048 || false !== strpos( $url, '@' ) || ! preg_match( '#^https?://#i', $url ) ) {
					return null;
				}
				return array( 'url' => esc_url_raw( $url ) );
		}
		return null;
	}

	/**
	 * Full synchronous processing of one unit (fallback of last resort).
	 * Deliberately NOT capped at a slice: when every async backend failed,
	 * running everything inline is the only way to guarantee zero silent loss.
	 * Purges are unlinks — bounded and fast even at thousands of entries.
	 *
	 * @param Job $job
	 * @return bool True when fully processed.
	 */
	private function run_sync_full( Job $job ) {
		if ( $this->sync_count >= $this->sync_limit ) {
			// Re-entrancy/DoS guard for pathological call patterns, NOT a
			// correctness slice: reaching this means 200 units already ran
			// synchronously in-request; report explicit failure rather than
			// pretend success.
			return false;
		}
		++$this->sync_count;
		try {
			Handlers::dispatch( $job );
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Explicit, observable failure record for work that could neither be
	 * queued nor processed. Surfaces in diagnostics instead of vanishing.
	 * WP-shim-safe (audit suites run without full WP runtime).
	 *
	 * @param Job    $job
	 * @param string $error
	 */
	private function record_failure_safe( $job, $error ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		$payload = $job->payload;
		set_transient(
			'up_queue_last_failure',
			array(
				'type'    => (string) $job->type,
				'items'   => isset( $payload['dirs'] ) ? count( $payload['dirs'] ) : 1,
				'error'   => substr( (string) $error, 0, 300 ),
				'id'      => (string) $job->id,
				'time'    => time(),
			),
			defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600
		);
	}

	/**
	 * Explicit, observable failure record for work that could neither be
	 * queued nor processed. Surfaces in diagnostics instead of vanishing.
	 *
	 * @param string              $type
	 * @param array<string,mixed> $payload
	 * @param string              $error
	 */
	private function record_failure( $type, $payload, $error ) {
		set_transient(
			'up_queue_last_failure',
			array(
				'type'    => (string) $type,
				'items'   => isset( $payload['dirs'] ) ? count( $payload['dirs'] ) : 1,
				'error'   => substr( (string) $error, 0, 300 ),
				'time'    => time(),
			),
			HOUR_IN_SECONDS
		);
	}

	private function note_fallback( $from, $error ) {
		set_transient(
			'up_queue_last_fallback',
			array( 'from' => (string) $from, 'error' => substr( (string) $error, 0, 200 ), 'time' => time() ),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Register cron tick + Action Scheduler job bridge.
	 */
	public function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'ultimate_performance_tick', array( __CLASS__, 'cron_tick' ) );
		if ( ! wp_next_scheduled( 'ultimate_performance_tick' ) ) {
			wp_schedule_event( time() + 60, 'up_every_minute', 'ultimate_performance_tick' );
		}
		// Action Scheduler dispatches its own workers; they only need the
		// callback registered. Without this, enqueued AS jobs fail with
		// "no callbacks are registered" and queued purges never run.
		// accepted_args must cover type+payload+id (AS spreads the stored
		// array via do_action_ref_array); default 1 would deliver only 'type'.
		add_action( 'ultimate_performance_as_job', array( __CLASS__, 'as_job_callback' ), 10, 3 );
	}

	/**
	 * Action Scheduler entry point. AS dispatches via
	 * do_action_ref_array(hook, args) — the stored array's ELEMENTS arrive as
	 * separate PHP arguments (type, payload, id), not one array. Accept both
	 * shapes for robustness.
	 *
	 * @param mixed ...$argv type,payload[,id] — or a single args array.
	 */
	public static function as_job_callback( ...$argv ) {
		if ( 1 === count( $argv ) && is_array( $argv[0] ) && isset( $argv[0]['type'] ) ) {
			$args = $argv[0]; // defensive: single-array delivery shape
		} else {
			$args = array(
				'type'    => isset( $argv[0] ) ? $argv[0] : '',
				'payload' => isset( $argv[1] ) && is_array( $argv[1] ) ? $argv[1] : array(),
				'id'      => isset( $argv[2] ) ? (string) $argv[2] : '',
			);
		}
		$type = isset( $args['type'] ) ? (string) $args['type'] : '';
		if ( '' === $type || ! in_array( $type, self::JOB_TYPES, true ) ) {
			return;
		}
		$payload = isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : array();
		$payload = self::instance()->validate_payload_public( $type, $payload );
		if ( null === $payload ) {
			return; // poison payload → drop
		}
		try {
			Handlers::dispatch( new Job( $type, $payload, isset( $args['id'] ) ? (string) $args['id'] : '' ) );
		} catch ( \Throwable $e ) {
			if ( defined( 'ULTIMATE_PERFORMANCE_DEBUG' ) ) {
				error_log( '[ultimate-performance] as_job failed: ' . $e->getMessage() ); // phpcs:ignore
			}
			// H3 silent-loss fix: RE-THROW. Swallowing here lets Action
			// Scheduler mark the action "complete" while the work actually
			// failed — a false success. Propagating makes the real runner
			// mark the action FAILED with a log entry (verified against the
			// bundled WooCommerce AS store): observable, truthful state.
			throw $e;
		}
	}

	/**
	 * Public wrapper for strict payload validation (used by AS bridge).
	 *
	 * @param string              $type
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|null
	 */
	public function validate_payload_public( $type, $payload ) {
		return $this->validate_payload( $type, $payload );
	}

	public static function cron_tick() {
		self::instance()->work( 20 );
	}

	/**
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function schedules( $schedules ) {
		if ( ! isset( $schedules['up_every_minute'] ) ) {
			$schedules['up_every_minute'] = array(
				'interval' => 60,
				'display'  => 'Ultimate Performance every minute',
			);
		}
		return $schedules;
	}

	/**
	 * Resolve the preferred backend (worker/diagnostics). Availability result
	 * is cached per instance; enqueue() uses its own per-chunk chain walk so a
	 * mid-batch backend death cannot pin later chunks to a failing backend.
	 */
	public function resolve_backend() {
		if ( null !== $this->backend ) {
			return $this->backend;
		}
		$configured = (string) Settings::instance()->get( 'queue_backend', 'auto' );
		$chain      = 'auto' === $configured
			? array( 'rabbitmq', 'action-scheduler', 'wp-cron' )
			: array( $configured );

		foreach ( $chain as $name ) {
			$cls = 'UltimatePerformance\\Queue\\BackendImpl\\' . self::studly( $name );
			if ( ! class_exists( $cls ) ) {
				continue;
			}
			try {
				$candidate = new $cls();
				if ( $candidate instanceof Backend && $candidate->available() ) {
					$this->backend = $candidate;
					return $candidate;
				}
			} catch ( \Throwable $e ) {
				continue; // try next backend
			}
		}
		return null;
	}

	private static function studly( $name ) {
		return str_replace( ' ', '', ucwords( str_replace( array( '-', '_' ), ' ', strtolower( (string) $name ) ) ) );
	}

	/**
	 * Accounting for the most recent enqueue(): submitted / queued /
	 * processed / failed / remaining / lost plus per-chunk attempts.
	 * Invariant at healthy completion: submitted == queued + processed and
	 * lost == 0. A job ID alone is never treated as proof of success.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_last_accounting() {
		return $this->last_accounting;
	}

	/**
	 * Diagnostics: active backend name ('sync' when none healthy).
	 */
	public function active_backend_name() {
		$b = $this->resolve_backend();
		return $b ? $b->name() : 'sync';
	}
}
