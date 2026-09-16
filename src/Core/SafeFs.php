<?php
/**
 * Filesystem safety abstraction. Every plugin FS op routes through here.
 *
 * Containment invariant: no operation may escape the allowed roots — including
 * via symlinks, NTFS junctions (realpath resolves them), 8.3 short names,
 * trailing-dot/space Win32 aliasing, case variation, or UNC paths.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Core;

defined( 'ABSPATH' ) || exit;

final class SafeFs {

	/** @var array<int,string>|null normalized roots */
	private static $roots = null;

	/** @var array<int,string> */
	private $errors = array();

	/** @var bool */
	private $windows;

	public function __construct() {
		self::init_roots();
		$this->windows = 0 === stripos( PHP_OS, 'WIN' );
	}

	private static function init_roots() {
		if ( null !== self::$roots ) {
			return;
		}
		$norm = array();
		foreach ( self::default_roots() as $p ) {
			$rp     = realpath( $p );
			$norm[] = wp_normalize_path( rtrim( (string) ( $rp ? $rp : $p ), '/\\' ) );
		}
		self::$roots = array_values( array_unique( array_filter( $norm ) ) );
	}

	/**
	 * @return array<int,string>
	 */
	public static function default_roots() {
		return array_values(
			array_unique(
				array_filter(
					array(
						defined('ULTIMATE_PERFORMANCE_DIR') ? ULTIMATE_PERFORMANCE_DIR : '',
						class_exists('UltimatePerformance\\Core\\Installer') ? Installer::cache_root() : '',
					)
				)
			)
		);
	}

	/**
	 * Register additional allowed root (tests only).
	 *
	 * @param string $path
	 */
	public static function allow_root( $path ) {
		self::init_roots();
		$rp            = realpath( $path );
		self::$roots[] = wp_normalize_path( rtrim( (string) ( $rp ? $rp : $path ), '/\\' ) );
		self::$roots   = array_values( array_unique( self::$roots ) );
	}

	/**
	 * Lexical sanity: device names, trailing dot/space segments, ADS colons,
	 * control chars, UNC forms. Purely textual — no FS access.
	 *
	 * @param string $norm
	 * @return bool
	 */
	private function lexically_safe( $norm ) {
		if ( false !== strpos( $norm, "\0" ) || strlen( $norm ) > 4096 ) {
			return false;
		}
		if ( 0 === strpos( $norm, '//' ) ) {
			return false; // UNC survived normalization
		}
		if ( preg_match( '/%(?![0-9a-fA-F]{2})|%2f|%5c|%00/i', $norm ) ) {
			return false; // raw/encoded traversal byte sequences: never store as-is
		}
		foreach ( explode( '/', $norm ) as $seg ) {
			if ( '' === $seg ) {
				continue;
			}
			if ( preg_match( '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $seg ) ) {
				return false;
			}
			if ( preg_match( '/[. ]$/', $seg ) ) {
				return false; // Win32 strips trailing dot/space -> aliasing escape
			}
			if ( false !== strpos( $seg, ':' ) && ! preg_match( '/^[A-Za-z]:$/', $seg ) ) {
				return false; // NTFS ADS / device colon; bare "D:" drive prefix OK
			}
			if ( false !== strpos( $seg, '%' ) ) {
				return false; // encoded segments rejected: upstream must decode first
			}
		}
		return true;
	}

	/**
	 * Case-sensitive prefix compare; case-insensitive on Windows.
	 *
	 * @param string $child
	 * @param string $root
	 * @return bool
	 */
	private function contained( $child, $root ) {
		if ( '' === $child || '' === $root ) {
			return false;
		}
		$child .= '/';
		$root  .= '/';
		return $this->windows
			? 0 === strcasecmp( $child, $root ) || 0 === stripos( $child, $root )
			: 0 === strcmp( $child, $root ) || 0 === strpos( $child, $root );
	}

	/**
	 * Validate a candidate path lexically + root containment (+ existence).
	 *
	 * @param string $path
	 * @param bool   $must_exist
	 * @return string|false Normalized path or false when rejected.
	 */
	public function validate( $path, $must_exist = false ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}
		if ( preg_match( '#(^|[/\\\\])\.\.($|[/\\\\])#', $path ) ) {
			return false;
		}
		$norm = wp_normalize_path( $path );
		if ( ! $this->lexically_safe( $norm ) ) {
			return false;
		}
		foreach ( self::$roots as $root ) {
			if ( $this->contained( $norm, $root ) ) {
				if ( $must_exist && ! file_exists( $norm ) ) {
					return false;
				}
				return $norm;
			}
		}
		return false;
	}

	/**
	 * Validate for writes: lexical + containment + resolved containment of the
	 * deepest existing ancestor. realpath() collapses symlinks AND junctions,
	 * closing reparse-point escapes; trailing-dot segments are pre-rejected so
	 * the resolved name cannot silently differ from the requested name.
	 *
	 * @param string $path
	 * @return string|false
	 */
	public function validate_write( $path ) {
		$ok = $this->validate( $path );
		if ( false === $ok ) {
			return false;
		}
		// Deepest existing ancestor.
		$probe = $ok;
		$tail  = array();
		while ( ! file_exists( $probe ) ) {
			$pos    = strrpos( $probe, '/' );
			if ( false === $pos ) {
				return false;
			}
			$tail[] = substr( $probe, $pos + 1 );
			$parent = substr( $probe, 0, $pos );
			if ( '' === $parent ) {
				return false;
			}
			$probe = $parent;
		}
		if ( is_link( $probe ) ) {
			$this->err( 'ancestor-is-link: ' . $probe );
			return false;
		}
		$resolved = wp_normalize_path( (string) realpath( $probe ) );
		if ( '' === $resolved ) {
			return false;
		}
		$recomposed = $tail ? $resolved . '/' . implode( '/', array_reverse( $tail ) ) : $resolved;

		foreach ( self::$roots as $root ) {
			/*
			 * Two valid geometries:
			 *
			 * A) Deepest existing ancestor inside/at the root (normal case):
			 *    resolved must sit inside the root.
			 * B) Root subtree missing entirely (fresh/wiped install): the walk
			 *    legitimately stops ABOVE the root. Accept only when realpath
			 *    proves the resolved ancestor physically contains the root —
			 *    i.e. the lazy mkdir chain will land inside the verified tree.
			 *    Junctions/symlinks in any EXISTING component still redirect
			 *    $resolved and fail both clauses, so escapes stay rejected.
			 */
			if ( $this->contained( $recomposed, $root )
				&& ( $this->contained( $resolved, $root ) || $this->contained( $root, $resolved ) ) ) {
				return $ok;
			}
		}
		$this->err( 'resolved-path-escape: ' . $ok . ' -> ' . $resolved );
		return false;
	}

	public function errors() {
		return $this->errors;
	}

	private function err( $msg ) {
		$this->errors[] = substr( (string) $msg, 0, 300 );
		if ( count( $this->errors ) > 50 ) {
			array_shift( $this->errors );
		}
	}

	// ---------------------------------------------------------------- IO ops

	public function read( $path ) {
		$p = $this->validate( $path, true );
		if ( false === $p || is_link( $p ) ) {
			$this->err( 'read rejected: ' . $path );
			return false;
		}
		return file_get_contents( $p );
	}

	/**
	 * Atomic write: unique tmp in same dir → write → flush → close → rename.
	 * Windows: retry rename; fallback delete+rename. Readers see old-or-new.
	 *
	 * @param string $path
	 * @param string $data
	 * @return bool
	 */
	public function write_atomic( $path, $data ) {
		$p = $this->validate_write( $path );
		if ( false === $p ) {
			$this->err( 'write rejected: ' . $path );
			return false;
		}
		if ( is_link( $p ) ) {
			$this->err( 'refusing symlink target: ' . $p );
			return false;
		}
		$dir = dirname( $p );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			$this->err( 'mkdir failed: ' . $dir );
			return false;
		}
		$tmp = $dir . '/.' . uniqid( 'uctmp_', true ) . '.tmp';
		$fh  = @fopen( $tmp, 'wb' );
		if ( ! $fh ) {
			$this->err( 'tmp open failed: ' . $tmp );
			return false;
		}
		fwrite( $fh, $data );
		fflush( $fh );
		fclose( $fh );
		for ( $i = 0; $i < 5; $i++ ) {
			if ( @rename( $tmp, $p ) ) {
				return true;
			}
			if ( file_exists( $p ) && @unlink( $p ) && @rename( $tmp, $p ) ) {
				return true;
			}
			usleep( 20000 );
		}
		@unlink( $tmp );
		$this->err( 'rename failed: ' . $p );
		return false;
	}

	/**
	 * Append with size cap + rotation (stats/logs never grow unbounded).
	 *
	 * @param string $path
	 * @param string $line
	 * @param int    $max_bytes
	 * @return bool
	 */
	public function append_capped( $path, $line, $max_bytes = 262144 ) {
		$p = $this->validate_write( $path );
		if ( false === $p ) {
			return false;
		}
		if ( file_exists( $p ) && filesize( $p ) > $max_bytes ) {
			$tail = (string) substr( (string) file_get_contents( $p ), -(int) ( $max_bytes / 2 ) );
			@file_put_contents( $p . '.rot', $tail );
			rename( $p . '.rot', $p );
		}
		return false !== @file_put_contents( $p, $line, FILE_APPEND | LOCK_EX );
	}

	public function exists( $path ) {
		$v = $this->validate( $path );
		return false !== $v && file_exists( $v );
	}

	public function delete( $path ) {
		$p = $this->validate_write( $path );
		if ( false === $p || ! file_exists( $p ) || is_link( $p ) ) {
			return false;
		}
		if ( is_dir( $p ) ) {
			return @rmdir( $p );
		}
		wp_delete_file( $p );
		return ! file_exists( $p );
	}

	/**
	 * Recursive delete restricted to validated subtree of an allowed root.
	 *
	 * @param string $dir
	 * @return bool
	 */
	public function delete_tree( $dir ) {
		$p = $this->validate_write( $dir );
		if ( false === $p || ! is_dir( $p ) || is_link( $p ) ) {
			return false;
		}
		return $this->rrmdir( $p );
	}

	private function rrmdir( $dir ) {
		$items = scandir( $dir );
		if ( false === $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_link( $path ) ) {
				continue; // never descend into links
			}
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		return @rmdir( $dir );
	}

	/**
	 * @param string $dir
	 * @return array<int,string>|false
	 */
	public function scandir( $dir ) {
		$p = $this->validate( $dir, true );
		if ( false === $p || ! is_dir( $p ) || is_link( $p ) ) {
			return false;
		}
		return array_values( array_diff( scandir( $p ), array( '.', '..' ) ) );
	}

	public function mkdir( $dir ) {
		$p = $this->validate_write( $dir );
		if ( false === $p ) {
			return false;
		}
		return is_dir( $p ) ? true : (bool) wp_mkdir_p( $p );
	}
}
