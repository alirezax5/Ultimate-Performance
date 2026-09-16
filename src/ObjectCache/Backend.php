<?php
/**
 * Object cache backend contract (Phase I).
 *
 * A backend owns persistence for one scope of keys. It MUST:
 *  - preserve PHP value fidelity through the found-flag contract ($found=true
 *    for ANY stored value, including false, null, 0 and ''),
 *  - implement add/replace as atomic compare-and-set operations,
 *  - implement incr/decr as atomic server-side arithmetic (never auto-create),
 *  - invalidate a group or a scope in O(1) — scanning commands (KEYS/SCAN
 *    driven invalidation, FLUSHALL, FLUSHDB) are forbidden,
 *  - fail closed: any internal error surfaces as false/null, never a throw.
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

defined( 'ABSPATH' ) || exit;

interface Backend {

	/**
	 * Read one key.
	 *
	 * @param string $key   Cache key (non-empty).
	 * @param string $group Normalized group (never empty).
	 * @param bool   $found By-reference found flag.
	 * @return mixed|null Stored value or null when missing/unavailable.
	 */
	public function get( $key, $group, &$found = null );

	/**
	 * Read many keys of one group in the fewest round-trips possible.
	 *
	 * @param array  $keys  Cache keys.
	 * @param string $group Normalized group.
	 * @return array<string,array{value:mixed,found:bool}> Keyed by input key.
	 */
	public function getMultiple( $keys, $group );

	/**
	 * Unconditional write.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value (any serializable PHP value).
	 * @param int    $ttl   Seconds; 0 = no expiry.
	 * @param string $group Normalized group.
	 * @return bool
	 */
	public function set( $key, $value, $ttl, $group );

	/**
	 * Write only when the key does NOT exist (atomic).
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds; 0 = no expiry.
	 * @param string $group Normalized group.
	 * @return bool
	 */
	public function add( $key, $value, $ttl, $group );

	/**
	 * Write only when the key EXISTS (atomic).
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds; 0 = no expiry.
	 * @param string $group Normalized group.
	 * @return bool
	 */
	public function replace( $key, $value, $ttl, $group );

	/**
	 * Remove one key. WordPress semantics: true when deleted, false when absent.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Normalized group.
	 * @return bool
	 */
	public function delete( $key, $group );

	/**
	 * Atomic integer increment; false when the key is missing or non-integer.
	 *
	 * @param string $key   Cache key.
	 * @param int    $n    Positive step.
	 * @param string $group Normalized group.
	 * @return int|false
	 */
	public function incr( $key, $n, $group );

	/**
	 * Atomic integer decrement; false when the key is missing or non-integer.
	 *
	 * @param string $key   Cache key.
	 * @param int    $n    Positive step.
	 * @param string $group Normalized group.
	 * @return int|false
	 */
	public function decr( $key, $n, $group );

	/**
	 * Invalidate every key of one group in O(1).
	 *
	 * @param string $group Normalized group.
	 * @return bool
	 */
	public function flushGroup( $group );

	/**
	 * Invalidate everything this runtime owns in its current scope, O(1).
	 * Keys outside the runtime's namespace are NEVER touched.
	 *
	 * @return bool
	 */
	public function flush();

	/**
	 * True iff storage can serve reads AND accept writes right now.
	 *
	 * @return bool
	 */
	public function healthy();

	/**
	 * Release resources; idempotent.
	 *
	 * @return void
	 */
	public function close();
}
