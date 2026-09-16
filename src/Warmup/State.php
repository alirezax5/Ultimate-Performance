<?php
/**
 * Bounded warmup state (Phase J) — fixed-schema, atomic, cardinality-free.
 *
 * The state file is the warmup's ENTIRE observability surface:
 *   - FIXED schema: the keys below and nothing else (no URL lists, no
 *     per-request rows — counts only, so the file cannot grow unbounded),
 *   - atomic write via temp file + rename,
 *   - values are ints/bools/short strings; nothing user-controlled is
 *     ever embedded.
 *
 * @package UltimatePerformance\Warmup
 */

namespace UltimatePerformance\Warmup;

defined( 'ABSPATH' ) || exit;

final class State {

	const FILENAME = 'meta/warmup-state.json';
	const SCHEMA   = array( 'status', 'total', 'enqueued', 'skipped_dup', 'skipped_foreign', 'capped', 'aborted_at', 'epoch', 'time' );

	/** @var string cache root (no trailing slash) */
	private $root;

	/**
	 * @param string|null $root Cache root override (tests).
	 */
	public function __construct( $root = null ) {
		$this->root = null === $root
			? ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/cache/ultimate-performance'
			: rtrim( $root, '/' );
	}

	/**
	 * Atomic state write. Extra/unknown keys are DROPPED (schema lock).
	 *
	 * @param array<string,mixed> $report Report values.
	 * @return bool
	 */
	public function write( array $report ) {
		$dir = $this->root . '/meta';
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) ) {
			return false;
		}
		$row = array();
		foreach ( self::SCHEMA as $k ) {
			if ( 'time' === $k ) {
				$row[ $k ] = time();
				continue;
			}
			if ( array_key_exists( $k, $report ) ) {
				$v = $report[ $k ];
				$row[ $k ] = is_bool( $v ) ? $v : ( is_int( $v ) ? $v : (string) substr( (string) $v, 0, 32 ) );
			}
		}
		$json  = wp_json_encode( $row );
		$tmp   = $dir . '/.warmup-state.tmp-' . getmypid();
		$final = $this->root . '/' . self::FILENAME;
		if ( false === @file_put_contents( $tmp, (string) $json, LOCK_EX ) ) {
			return false;
		}
		@chmod( $tmp, 0640 );
		if ( ! @rename( $tmp, $final ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function read() {
		$file = $this->root . '/' . self::FILENAME;
		if ( ! is_file( $file ) || is_link( $file ) ) {
			return null;
		}
		$dec = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $dec ) ? $dec : null;
	}
}
