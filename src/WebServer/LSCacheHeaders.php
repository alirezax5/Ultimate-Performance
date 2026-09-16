<?php
/**
 * LiteSpeed/LSCache header integration (native cache when server supports it).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\WebServer;

defined( 'ABSPATH' ) || exit;

/**
 * Emits LSCache control headers on cacheable responses and collects purge
 * directives. Never fatal; no-ops when headers already sent.
 */
final class LSCacheHeaders {

	/** @var array<int,string> pending purge expressions */
	private static $purges = array();

	/**
	 * Register output hooks when native LSCache mode is active.
	 */
	public static function boot() {
		add_action( 'send_headers', array( __CLASS__, 'emit_control' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_purges' ) );
	}

	/**
	 * @param bool $public Whether response is public-cacheable.
	 * @param int  $ttl
	 * @param array<int,string> $tags Cache tags (post:1 etc).
	 */
	public static function emit_control_headers( $public, $ttl, $tags = array() ) {
		if ( headers_sent() || ! $public ) {
			return;
		}
		$ttl = max( 30, min( 86400, (int) $ttl ) );
		@header( 'X-LiteSpeed-Cache-Control: public,max-age=' . $ttl );
		if ( $tags ) {
			$ls_tags = array();
			foreach ( array_slice( (array) $tags, 0, 32 ) as $tag ) {
				$t = preg_replace( '/[^A-Za-z0-9_]/', '_', (string) $tag );
				if ( '' !== $t ) {
					$ls_tags[] = 'uc_' . strtolower( substr( $t, 0, 60 ) );
				}
			}
			if ( $ls_tags ) {
				@header( 'X-LiteSpeed-Tag: ' . implode( ',', array_unique( $ls_tags ) ) );
			}
		}
	}

	/**
	 * Queue a URI purge for shutdown emission.
	 *
	 * @param string $uri
	 */
	public static function queue_purge_uri( $uri ) {
		self::$purges[] = 'U' . self::tag_for_uri( (string) $uri );
	}

	public static function queue_purge_all() {
		self::$purges[] = '*';
	}

	private static function tag_for_uri( $uri ) {
		return 'l' . substr( md5( (string) $uri ), 0, 10 );
	}

	/**
	 * Emit queued purge headers at shutdown (works from admin context too —
	 * LiteSpeed picks these up on the triggering request).
	 */
	public static function flush_purges() {
		if ( empty( self::$purges ) || headers_sent() ) {
			return;
		}
		@header( 'X-LiteSpeed-Purge: ' . implode( ',', array_unique( self::$purges ) ) );
		self::$purges = array();
	}
}
