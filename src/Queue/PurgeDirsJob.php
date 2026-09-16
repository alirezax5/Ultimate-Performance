<?php
/**
 * Queue Handlers — purge_dirs job.
 *
 * Deletes a batch of cache objects (rel dirs) outside the request that
 * invalidated them. Bounded per job; safe to re-run (purge of missing
 * entries is a no-op).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Queue;

use UltimatePerformance\CacheInvalidation\Hooks;
use UltimatePerformance\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PurgeDirsJob {

	/** Hard cap: one job may never carry more than this many dirs. */
	const MAX_DIRS = 5000;

	/**
	 * @param array<string,mixed> $payload
	 * @return bool
	 */
	public function handle( $payload ) {
		$dirs = isset( $payload['dirs'] ) && is_array( $payload['dirs'] ) ? $payload['dirs'] : array();
		if ( empty( $dirs ) ) {
			return true;
		}
		if ( count( $dirs ) > self::MAX_DIRS ) {
			$dirs = array_slice( $dirs, 0, self::MAX_DIRS );
		}
		$hooks = new Hooks( Settings::instance() );
		foreach ( $dirs as $d ) {
			$hooks->purge_dir( (string) $d );
		}
		return true;
	}
}
