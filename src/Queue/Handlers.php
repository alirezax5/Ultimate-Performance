<?php
/**
 * Job handlers registry — maps job type → executable.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Queue;

use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\CacheTag\Registry;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\PageCache\Store;

defined( 'ABSPATH' ) || exit;

final class Handlers {

	/**
	 * Execute a job. Any exception = failed attempt (backend handles retry).
	 *
	 * @param \UltimatePerformance\Queue\Backend\Job $job
	 */
	public static function dispatch( $job ) {
		do_action( 'ultimate_cache_job', $job ); // extensibility first

		switch ( $job->type ) {
			case 'purge_dirs':
				self::run_purge_dirs( isset( $job->payload['dirs'] ) && is_array( $job->payload['dirs'] ) ? $job->payload['dirs'] : array() );
				break;

			case 'preload_url':
			case 'regenerate':
				if ( ! self::is_local_url( (string) $job->payload['url'] ) ) {
					return; // SSRF guard: loopback preload only.
				}
				self::http_touch( (string) $job->payload['url'] );
				break;
		}
	}

	private static function run_purge_dirs( $dirs ) {
		$settings = Settings::instance();
		$fs       = new SafeFs();
		$key      = new Key( $settings );
		$store    = new Store( $fs, $key );
		$tags     = new Registry( $fs );

		foreach ( (array) $dirs as $rel_dir ) {
			$file = $key->absolute( (string) $rel_dir );
			if ( false === $file ) {
				continue;
			}
			$meta_file = $file . '.meta.json';
			$meta      = array();
			if ( is_readable( $meta_file ) && ! is_link( $meta_file ) ) {
				$dec = json_decode( (string) file_get_contents( $meta_file ), true );
				if ( is_array( $dec ) ) {
					$meta = $dec;
				}
			}
			$store->purge( (string) $rel_dir );
			$tags->detach_object(
				(string) $rel_dir,
				isset( $meta['tags'] ) && is_array( $meta['tags'] ) ? $meta['tags'] : array()
			);
		}
	}

	/**
	 * SSRF guard: only the site's own hosts may be preloaded.
	 *
	 * @param string $url
	 */
	public static function is_local_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = Key::canonical_host( (string) $parts['host'] );
		if ( '' === $host ) {
			return false;
		}
		$allowed = apply_filters(
			'ultimate_cache_preload_hosts',
			array( Key::canonical_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ),
			$url
		);
		return in_array( $host, array_filter( (array) $allowed ), true );
	}

	/**
	 * Loopback fetch so WP renders and repopulates cache. Bounded timeout.
	 *
	 * @param string $url
	 * @return bool
	 */
	private static function http_touch( $url ) {
		$r = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'blocking'    => true,
				'sslverify'   => false, // local loopback certs often self-signed
				'redirection' => 2,
				'user-agent'  => 'UltimatePerformance-Preload/0.1',
				'headers'     => array( 'X-Ultimate-Performance-Preload' => '1' ),
			)
		);
		return ! is_wp_error( $r );
	}
}
