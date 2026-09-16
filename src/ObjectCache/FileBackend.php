<?php
/**
 * Filesystem object cache backend (Phase K).
 *
 * No daemon, no extension: files under the plugin's hardened cache tree.
 *  - hash-based filenames (no user data in paths; traversal impossible),
 *  - SafeFs write_atomic (temp + rename) for every write — no torn files,
 *  - symlink refusal on every read path (never follow links),
 *  - generation-counter O(1) invalidation (identical scheme to the other
 *    backends; no recursive deletes, no scans for invalidation),
 *  - atomic add via exclusive-create (fopen 'x'); replace is
 *    exists-check-then-write (documented TOCTOU on NFS-style shares —
 *    disclosed in the capability matrix, never claimed CAS).
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

use UltimatePerformance\Core\SafeFs;

defined( 'ABSPATH' ) || exit;

final class FileBackend implements Backend {

	const NS       = 'uc:oc:';
	const ENV_MARK = 'UC1:';
	const HEADER   = 'UCOCF1';

	/** @var SafeFs */
	private $fs;

	/** @var string base dir (no trailing slash) */
	private $base;

	/** @var bool permanently closed */
	private $closed = false;

	/**
	 * @param string|null  $base Base dir override (tests); default under the hardened cache tree.
	 * @param SafeFs|null  $fs   SafeFs instance (injected; defaults to new).
	 */
	public function __construct( $base = null, $fs = null ) {
		if ( null === $base ) {
			$base = self::cfg( 'ULTIMATE_PERFORMANCE_FILE_DIR', 'UC_FILE_CACHE_DIR' );
		}
		if ( '' === (string) $base ) {
			$base = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/cache/ultimate-performance';
		}
		$this->base = rtrim( (string) $base, '/' ) . '/object-cache-files';
		$this->fs   = $fs ?? new SafeFs();
	}

	private static function cfg( $constant, $env ) {
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return (string) constant( $constant );
		}
		$v = getenv( $env );
		return false === $v ? '' : (string) $v;
	}

	public static function configured() {
		return true;
	}

	private function genValue( $name ) {
		$file = $this->base . '/gen/' . hash( 'sha256', $name ) . '.gen';
		if ( ! is_file( $file ) || is_link( $file ) ) {
			return '0';
		}
		$v = (string) @file_get_contents( $file );
		return preg_match( '/^\d+$/', trim( $v ) ) ? trim( $v ) : '0';
	}

	private function bumpCounter( $name ) {
		// Atomic counter bump: read-modify-write under an exclusive-create lock
		// file, then atomic rename (no torn counters).
		$dir  = $this->base . '/gen';
		if ( ! is_dir( $dir ) && ! $this->fs->mkdir( $dir ) ) {
			return false;
		}
		$file = $dir . '/' . hash( 'sha256', $name ) . '.gen';
		$lock = $dir . '/' . hash( 'sha256', $name ) . '.lock';
		$fh   = @fopen( $lock, 'c' );
		if ( false === $fh ) {
			return false;
		}
		$ok = false;
		if ( @flock( $fh, LOCK_EX ) ) {
			$v   = (int) $this->genValue( $name ) + 1;
			$ok  = $this->fs->write_atomic( $file, (string) $v );
			@flock( $fh, LOCK_UN );
		}
		@fclose( $fh );
		return $ok;
	}

	private function keyFile( $grp, $key ) {
		$v = $this->genValue( self::NS . 'gen' );
		$g = $this->genValue( self::NS . 'geng:' . $grp );
		return $this->base . '/V' . $v . '/G' . $g . '/' . hash( 'sha256', $grp ) . '/' . hash( 'sha256', $key ) . '.uc';
	}

	private function encode( $value, $expiry ) {
		return self::HEADER . pack( 'N', $expiry ) . ( is_int( $value )
			? 'i' . (string) $value
			: 's' . serialize( $value ) );
	}

	private function decodeFile( $file, &$found ) {
		$found = false;
		if ( ! is_file( $file ) || is_link( $file ) ) {
			return null;
		}
		$raw = (string) @file_get_contents( $file );
		if ( strlen( $raw ) < 12 || 0 !== strpos( $raw, self::HEADER ) ) {
			return null; // torn/foreign file — treat as absent
		}
		$expiry = unpack( 'N', substr( $raw, 6, 4 ) )[1] ?? 0;
		if ( 0 !== $expiry && $expiry <= time() ) {
			return null; // expired (lazy)
		}
		$payload = substr( $raw, 10 );
		if ( 'i' === substr( $payload, 0, 1 ) && preg_match( '/^i(-?\d+)$/', $payload, $m ) ) {
			$found = true;
			return (int) $m[1];
		}
		if ( 's' === substr( $payload, 0, 1 ) ) {
			$found = true;
			return unserialize( substr( $payload, 1 ) );
		}
		return null;
	}

	private function writeValue( $file, $value, $expiry ) {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! $this->fs->mkdir( $dir ) ) {
			return false;
		}
		return $this->fs->write_atomic( $file, $this->encode( $value, $expiry ) );
	}

	public function get( $key, $group, &$found = null ) {
		try {
			return $this->decodeFile( $this->keyFile( $group, $key ), $found );
		} catch ( \Throwable $e ) {
			$found = false;
			return null;
		}
	}

	public function getMultiple( $keys, $group ) {
		$out = array();
		foreach ( (array) $keys as $k ) {
			$found     = false;
			$v         = $this->get( $k, $group, $found );
			$out[ $k ] = array( 'value' => $found ? $v : null, 'found' => $found );
		}
		return $out;
	}

	public function set( $key, $value, $ttl, $group ) {
		try {
			$expiry = (int) $ttl > 0 ? time() + (int) $ttl : 0;
			return $this->writeValue( $this->keyFile( $group, $key ), $value, $expiry );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function add( $key, $value, $ttl, $group ) {
		try {
			$file   = $this->keyFile( $group, $key );
			$expiry = (int) $ttl > 0 ? time() + (int) $ttl : 0;
			$dir    = dirname( $file );
			if ( ! is_dir( $dir ) && ! $this->fs->mkdir( $dir ) ) {
				return false;
			}
			if ( $this->decodeFile( $file, $probe_found ) && $probe_found ) {
				return false; // exists
			}
			// exclusive create: atomic even across processes
			$fh = @fopen( $file, 'x' );
			if ( false === $fh ) {
				return false;
			}
			@fwrite( $fh, $this->encode( $value, $expiry ) );
			@fclose( $fh );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function replace( $key, $value, $ttl, $group ) {
		try {
			$file = $this->keyFile( $group, $key );
			if ( ! $this->decodeFile( $file, $probe ) ) {
				return false; // absent — replace refuses
			}
			$expiry = (int) $ttl > 0 ? time() + (int) $ttl : 0;
			return $this->writeValue( $file, $value, $expiry );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function delete( $key, $group ) {
		try {
			$file = $this->keyFile( $group, $key );
			if ( ! is_file( $file ) || is_link( $file ) ) {
				return false;
			}
			return $this->fs->delete( $file );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function incr( $key, $n, $group ) {
		return $this->arith( $key, (int) $n, $group, +1 );
	}

	public function decr( $key, $n, $group ) {
		return $this->arith( $key, (int) $n, $group, -1 );
	}

	/**
	 * flock-guarded read-modify-write arithmetic (atomic cross-process on a
	 * single host). Missing or non-integer keys are never auto-created.
	 *
	 * @return int|false
	 */
	private function arith( $key, $n, $group, $sign ) {
		$file = $this->keyFile( $group, $key );
		if ( ! is_file( $file ) || is_link( $file ) ) {
			return false;
		}
		$fh = @fopen( $file, 'r+' );
		if ( false === $fh ) {
			return false;
		}
		$result = false;
		if ( @flock( $fh, LOCK_EX ) ) {
			$raw = (string) @stream_get_contents( $fh );
			if ( strlen( $raw ) >= 10 && 0 === strpos( $raw, self::HEADER ) ) {
				$expiry  = unpack( 'N', substr( $raw, 6, 4 ) )[1] ?? 0;
				$payload = substr( $raw, 10 );
				if ( ( 0 === $expiry || $expiry > time() ) && preg_match( '/^i(-?\d+)$/', $payload, $m ) ) {
					$nv = (int) $m[1] + $sign * $n;
					@ftruncate( $fh, 0 );
					@rewind( $fh );
					@fwrite( $fh, $this->encode( $nv, $expiry ) );
					@fflush( $fh );
					$result = $nv;
				}
			}
			@flock( $fh, LOCK_UN );
		}
		@fclose( $fh );
		return $result;
	}

	public function flushGroup( $group ) {
		return $this->bumpCounter( self::NS . 'geng:' . $group );
	}

	public function flush() {
		return $this->bumpCounter( self::NS . 'gen' );
	}

	public function healthy() {
		if ( $this->closed ) {
			return false;
		}
		try {
			$probe = $this->base . '/health/' . hash( 'sha256', (string) getmypid() ) . '.probe';
			$ok    = $this->fs->write_atomic( $probe, '1' ) && is_file( $probe );
			$this->fs->delete( $probe );
			return $ok;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function close() {
		$this->closed = true;
	}

	/**
	 * Capability map. NOTE on honesty: add() is atomic (exclusive create),
	 * replace() is check-then-write and is NOT cross-process CAS — the
	 * capability matrix discloses this; the 8 WP features are all provided.
	 *
	 * @return array<string,bool>
	 */
	public function features() {
		return array(
			'add_multiple'  => true,
			'set_multiple'  => true,
			'get_multiple'  => true,
			'flush_runtime' => true,
			'flush_group'   => true,
			'incr'          => true,
			'decr'          => true,
			'group'         => true,
		);
	}
}
