<?php
/**
 * GenerationLock — single-flight cache regeneration (stampede protection).
 *
 * BENCH-D7 fix (HARDEN-1). When cache is missing or expired, 100 concurrent
 * requests MUST NOT trigger 100 independent expensive regenerations.
 *
 * Design:
 *   1. One request acquires the generation lock (becomes the "generator").
 *   2. Other requests (waiters) poll the cached file with bounded wait + jitter.
 *      - If a fresh/stale cache appears during the wait → serve it.
 *      - If the wait budget is exhausted → fall through to render (last resort,
 *        but never a deadlock).
 *   3. The generator renders, writes the cache atomically, releases the lock.
 *
 * The lock is filesystem-based (flock) via the existing FileLock primitive.
 * Crash recovery: flock is dropped automatically when the PHP process dies.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\PageCache;

use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\Lock\FileLock;
use UltimatePerformance\Core\Uuid7;

defined( 'ABSPATH' ) || exit;

final class GenerationLock {

	/** @var string */
	private $rel_dir;

	/** @var FileLock */
	private $lock;

	/** @var string */
	private $owner;

	/** @var int Default lock TTL (seconds) — flock auto-releases on process death, but TTL guards degraded mode. */
	const DEFAULT_TTL = 30;

	/** @var int Default waiter budget (microseconds). Bounded; never infinite. */
	const DEFAULT_WAIT_BUDGET_US = 2500000; // 2.5 seconds

	/** @var int Poll interval base (microseconds). */
	const POLL_INTERVAL_US = 20000; // 20ms

	/** @var int Poll jitter (microseconds). */
	const POLL_JITTER_US = 15000; // 0-15ms random

	/**
	 * @param string $rel_dir Cache-relative directory (the cache key).
	 */
	public function __construct( $rel_dir ) {
		$this->rel_dir = (string) $rel_dir;
		$this->owner   = Uuid7::generate();
		$lock_file     = Installer::cache_root() . '/genlocks/' . $this->safe_lock_name( $this->rel_dir ) . '.lock';
		$this->lock    = new FileLock( $lock_file, $this->owner );
	}

	/**
	 * Try to become the generator. Non-blocking.
	 *
	 * @param int $ttl Lock TTL in seconds.
	 * @return bool True if THIS request is the generator.
	 */
	public function try_acquire( $ttl = self::DEFAULT_TTL ) {
		return $this->lock->acquire( $ttl );
	}

	/**
	 * Release the generation lock. Only the owner may release.
	 */
	public function release() {
		$this->lock->release();
	}

	/**
	 * Wait for the generator to produce a cache file. Polls the store
	 * with bounded wait + jitter. Returns when a fresh cache is available,
	 * or when the wait budget is exhausted.
	 *
	 * @param Store    $store       The page cache store.
	 * @param callable $on_stale   Called with the stale lookup if SWR is safe.
	 *                             Signature: function(array $lookup): void
	 *                             Should serve the stale body and exit.
	 * @param int      $wait_budget_us Maximum total wait in microseconds.
	 * @return array|null The fresh lookup if cache appeared during wait, null on timeout.
	 */
	public function wait_for_generation( Store $store, callable $on_stale, $wait_budget_us = self::DEFAULT_WAIT_BUDGET_US ) {
		$elapsed = 0;
		$served_stale = false;
		while ( $elapsed < $wait_budget_us ) {
			usleep( self::POLL_INTERVAL_US + wp_rand( 0, self::POLL_JITTER_US ) );
			$elapsed += self::POLL_INTERVAL_US + self::POLL_JITTER_US;

			$lookup = $store->lookup( $this->rel_dir );
			if ( is_array( $lookup ) && $lookup['found'] && $lookup['fresh'] ) {
				return $lookup; // Fresh cache appeared.
			}
			// SWR: serve stale once (only if safe — caller decides via $on_stale).
			if ( ! $served_stale && is_array( $lookup ) && $lookup['found'] && $lookup['stale'] && $on_stale ) {
				$served_stale = true;
				call_user_func( $on_stale, $lookup );
				// $on_stale should exit; if it returns, keep polling.
			}
		}
		return null; // Budget exhausted; caller falls through to render.
	}

	/**
	 * Owner identity for diagnostics.
	 *
	 * @return string
	 */
	public function owner() {
		return $this->owner;
	}

	/**
	 * Convert a rel_dir into a filesystem-safe lock filename.
	 * Uses a short hash to keep filenames bounded.
	 *
	 * @param string $rel_dir
	 * @return string
	 */
	private function safe_lock_name( $rel_dir ) {
		return substr( sha1( (string) $rel_dir ), 0, 32 );
	}

	/**
	 * Ensure the genlocks directory exists. Called once before any lock.
	 */
	public static function ensure_dir() {
		$dir = Installer::cache_root() . '/genlocks';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}
}
