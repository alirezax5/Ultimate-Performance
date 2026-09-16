<?php
/**
 * Shared queue driver for audit suites (Phase H).
 *
 * Production invalidation is asynchronous by design: QueueManager persists
 * purge work to Action Scheduler / WP-Cron and a worker executes it later.
 * Disk-state assertions therefore need a deterministic way to run pending
 * jobs between mutation and assertion — exactly what production workers do,
 * but in-process.
 *
 * Why not ActionScheduler_QueueRunner::run()? The claims machinery is
 * concurrency-guarded; stale claim rows from aborted runs wedge claiming in
 * a single-process CLI context (observed empirically). This driver instead
 * fetches every PENDING ultimate_performance_as_job action through the real store
 * and executes it through the REAL production callback
 * (QueueManager::as_job_callback) with the REAL stored args, then marks the
 * action complete/failed in the normal AS store + logs. Same row, same
 * payload shape, same handler, same filesystem effects as production.
 */

namespace UltimatePerformance\Tests;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\\uc_drive_queue' ) ) {

	/**
	 * Drain all pending ultimate-performance queue work deterministically.
	 *
	 * @param int $max_per_pass Safety bound on actions handled per driver pass.
	 */
	function uc_drive_queue( $max_per_pass = 200 ) {
		if ( class_exists( '\ActionScheduler_Store' ) && class_exists( '\ActionScheduler_Logger' ) ) {
			try {
				$store  = \ActionScheduler_Store::instance();
				$logger = \ActionScheduler_Logger::instance();
				$ids    = $store->query_actions(
					array(
						'hook'     => 'ultimate_performance_as_job',
						'status'   => 'pending',
						'per_page' => $max_per_pass,
						'orderby'  => 'date',
						'order'    => 'ASC',
					)
				);
				foreach ( (array) $ids as $aid ) {
					try {
						$action = $store->fetch_action( $aid );
						$args   = is_object( $action ) ? $action->get_args() : array();
						$type    = is_array( $args ) && isset( $args['type'] ) ? (string) $args['type'] : '';
						$payload = is_array( $args ) && isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : array();
						$jid     = is_array( $args ) && isset( $args['id'] ) ? (string) $args['id'] : '';
						$logger->log( $aid, 'action started via test driver' );
						\UltimatePerformance\Queue\QueueManager::as_job_callback( $type, $payload, $jid );
						$store->mark_complete( $aid );
						$logger->log( $aid, 'action complete via test driver' );
					} catch ( \Throwable $e ) {
						try {
							$store->mark_failure( $aid );
							$logger->log( $aid, 'action failed via test driver: ' . $e->getMessage() );
						} catch ( \Throwable $ignored ) {} // phpcs:ignore
					}
				}
			} catch ( \Throwable $e ) {} // phpcs:ignore -- store absent → nothing to drain.
		}
		try {
			\UltimatePerformance\Queue\QueueManager::instance()->work( 50 ); // WP-Cron/local backend jobs.
		} catch ( \Throwable $e ) {} // phpcs:ignore
		if ( function_exists( 'clearstatcache' ) ) {
			clearstatcache();
		}
	}
}
