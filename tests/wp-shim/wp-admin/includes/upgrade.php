<?php
/**
 * WP shim: wp-admin/includes/upgrade.php (dbDelta only).
 *
 * The M5 cluster suite swaps $wpdb for a SQLite-backed test double whose
 * table dialect differs from MySQL; dbDelta therefore delegates to the
 * suite-registered `uc_m5_dbdelta` callback, which creates the equivalent
 * table on the double. When no callback is registered (other suites),
 * dbDelta is a harmless no-op — the shim never pretends to run real DDL.
 *
 * It is TEST INFRASTRUCTURE ONLY — never loaded by production code.
 *
 * @package UltimatePerformance\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'ABSPATH must be defined by the calling suite' );
}

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Emulated dbDelta. Delegates table creation to the suite's registered
	 * callback (tests/lib pattern: the double owns its own dialect).
	 *
	 * @param string $sql CREATE TABLE statement(s).
	 * @return array
	 */
	function dbDelta( $sql = '' ) {
		if ( function_exists( 'up_m5_dbdelta' ) ) {
			return call_user_func( 'up_m5_dbdelta', (string) $sql );
		}
		return array();
	}
}
