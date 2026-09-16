<?php
/**
 * Drop-in conflict detection (advanced-cache.php / object-cache.php).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Compatibility;

defined( 'ABSPATH' ) || exit;

final class DropinGuards {

	const MARKER = 'UltimatePerformance';

	/**
	 * @param string $dropin e.g. 'object-cache.php'
	 * @return string 'ultimate-performance'|'foreign'|'none'
	 */
	public function owner_of( $dropin ) {
		$path = WP_CONTENT_DIR . '/' . $dropin;
		if ( ! file_exists( $path ) ) {
			return 'none';
		}
		$head = (string) file_get_contents( $path, false, null, 0, 4096 );
		return false !== strpos( $head, self::MARKER ) ? 'ultimate-performance' : 'foreign';
	}

	public function object_cache_status() {
		$owner = $this->owner_of( 'object-cache.php' );
		return array(
			'dropin' => 'object-cache.php',
			'owner'  => $owner,
			'status' => 'foreign' === $owner ? 'CONFLICT' : ( 'none' === $owner ? 'NOT CONFIGURED' : 'ACTIVE' ),
		);
	}

	public function advanced_cache_status() {
		$owner = $this->owner_of( 'advanced-cache.php' );
		return array(
			'dropin' => 'advanced-cache.php',
			'owner'  => $owner,
			'status' => 'foreign' === $owner ? 'CONFLICT' : ( 'none' === $owner ? 'NOT CONFIGURED' : 'ACTIVE' ),
		);
	}

	public function snapshot() {
		return array(
			'object-cache.php'   => $this->object_cache_status(),
			'advanced-cache.php' => $this->advanced_cache_status(),
		);
	}
}
