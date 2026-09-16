<?php
/**
 * Page Cache Store — filesystem read/write with atomicity + metadata.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\PageCache;

use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Uuid7;

defined( 'ABSPATH' ) || exit;

/**
 * Storage layer for rendered HTML pages.
 *
 * Files:
 *   <root>/v/<dir>/index.html            body (public-cacheable content)
 *   <root>/v/<dir>/index.html.meta.json  {status,headers,ttl,created,tags,id}
 */
final class Store {

	/** @var SafeFs */
	private $fs;

	/** @var Key */
	private $keygen;

	public function __construct( SafeFs $fs, Key $keygen ) {
		$this->fs     = $fs;
		$this->keygen = $keygen;
	}

	/**
	 * Lookup a cached page by rel dir.
	 *
	 * @param string $rel_dir Relative dir from Key::build()['dir'].
	 * @return array{found:bool,fresh:bool,stale:bool,body:string,meta:array}|false false on rejected paths
	 */
	public function lookup( $rel_dir ) {
		$file = $this->keygen->absolute( $rel_dir );
		if ( false === $file || false === $this->fs->validate( $file ) || is_link( $file ) ) {
			return false;
		}
		if ( ! is_readable( $file ) ) {
			return array( 'found' => false, 'fresh' => false, 'stale' => false, 'body' => '', 'meta' => array() );
		}
		$body = (string) file_get_contents( $file );
		$meta = $this->read_meta( $file . '.meta.json' );

		$now  = time();
		$ttl  = isset( $meta['ttl'] ) ? max( 30, (int) $meta['ttl'] ) : 3600;
		$grace = (int) apply_filters( 'ultimate_cache_swr_grace', 300, $meta );
		$age  = $now - ( isset( $meta['created'] ) ? (int) $meta['created'] : 0 );

		return array(
			'found' => true,
			'fresh' => $age <= $ttl,
			'stale' => ! ( $age <= $ttl ) && $grace > 0 && $age <= $ttl + $grace,
			'body'  => $body,
			'meta'  => $meta,
		);
	}

	/**
	 * Atomic write of body + meta. Attaches tags to reverse index.
	 * Returns object id (uuid7) or false.
	 *
	 * @param string               $rel_dir
	 * @param string               $html
	 * @param int                  $status HTTP status.
	 * @param array<string,string> $headers Stored headers (sanitized).
	 * @param array<int,string>    $tags
	 * @param int                  $ttl
	 * @return string|false
	 */
	public function write( $rel_dir, $html, $status, $headers, $tags, $ttl ) {
		$file = $this->keygen->absolute( $rel_dir );
		if ( false === $file || false === $this->fs->validate_write( $file ) || is_link( $file ) ) {
			return false;
		}
		$id      = Uuid7::generate();
		$headers = self::sanitize_headers( (array) $headers );
		$tags    = array_values( array_unique( array_map( 'strval', (array) $tags ) ) );
		$meta    = array(
			'id'      => $id,
			'status'  => max( 100, min( 599, (int) $status ) ),
			'headers' => $headers,
			'ttl'     => max( 30, min( MONTH_IN_SECONDS, (int) $ttl ) ),
			'created' => time(),
			'tags'    => $tags,
			'url_key' => md5( (string) $rel_dir ),
		);

		$json = wp_json_encode( $meta, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return false;
		}

		if ( ! $this->fs->write_atomic( $file, (string) $html ) ) {
			return false;
		}
		if ( ! $this->fs->write_atomic( $file . '.meta.json', $json ) ) {
			$this->fs->delete( $file ); // never leave body without meta
			return false;
		}

		// Attach reverse index AFTER files exist (purge of missing entry harmless).
		try {
			( new \UltimatePerformance\CacheTag\Registry( $this->fs ) )->attach( (string) $rel_dir, $tags );
		} catch ( \Throwable $e ) {
			// tag index failure → object still valid; purge-all remains available.
		}
		return $id;
	}

	/**
	 * Delete a cached page (body + meta).
	 *
	 * @return bool
	 */
	public function purge( $rel_dir ) {
		$file = $this->keygen->absolute( $rel_dir );
		if ( false === $file ) {
			return false;
		}
		$this->fs->delete( $file );
		$this->fs->delete( $file . '.meta.json' );
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_meta( $meta_file ) {
		if ( ! is_readable( $meta_file ) || is_link( $meta_file ) ) {
			return array();
		}
		$dec = json_decode( (string) file_get_contents( $meta_file ), true );
		return is_array( $dec ) ? $dec : array();
	}

	/**
	 * Header names/values hardened: no CR/LF/TAB, name charset-limited, length-capped.
	 *
	 * @param array<string,string> $headers
	 * @return array<string,string>
	 */
	public static function sanitize_headers( $headers ) {
		$out = array();
		foreach ( (array) $headers as $name => $value ) {
			$name  = preg_replace( '/[^A-Za-z0-9-]/', '', (string) $name );
			$value = str_replace( array( "\r", "\n", "\t", "\0" ), array( '', '', ' ', '' ), (string) $value );
			if ( '' !== $name && '' !== $value ) {
				$out[ $name ] = substr( $value, 0, 512 );
			}
		}
		return $out;
	}
}
