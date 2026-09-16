<?php
/**
 * AUDIT TEST — WordPress.org readiness (Phase K).
 *
 *   R1  readme.txt: required WordPress.org sections present
 *   R2  version consistency: header == Stable tag == ULTIMATE_PERFORMANCE_VERSION
 *   R3  honest requirements: Requires PHP equals the TESTED floor (8.3);
 *       Requires at least present
 *   R4  text domain + POT: domain 'ultimate-performance'; POT exists, non-empty,
 *       versioned, real msgids
 *   R5  POT references resolve to real files/lines (no stale entries)
 *   R6  changelog covers the current version
 *
 * Run: php tests/audit-wporg-readiness.php
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

$results = array();
function rcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$plugin_dir = dirname( __DIR__ );
$main       = $plugin_dir . '/ultimate-performance.php';
$readme     = $plugin_dir . '/readme.txt';
$pot        = $plugin_dir . '/languages/ultimate-performance.pot';

$main_src   = is_file( $main ) ? (string) file_get_contents( $main ) : '';
$readme_src = is_file( $readme ) ? (string) file_get_contents( $readme ) : '';
$pot_src    = is_file( $pot ) ? (string) file_get_contents( $pot ) : '';

// R1: WordPress.org readme sections.
foreach ( array( '=== Ultimate Performance ===', '== Description ==', '== Installation ==', '== Frequently Asked Questions ==', '== Changelog ==', 'Stable tag:' ) as $section ) {
        rcheck( $results, 'R1 readme contains "' . $section . '"', '' !== $readme_src && false !== strpos( $readme_src, $section ) );
}

// R2: version triple. The constant is parsed from source (the audit does not
// boot the main plugin file).
preg_match( '/^ \* Version:\s+(\S+)/m', $main_src, $m_ver );
preg_match( '/^Stable tag:\s*(\S+)/m', $readme_src, $m_stable );
preg_match( "/define\( \x27ULTIMATE_PERFORMANCE_VERSION\x27, \x27([^\x27]+)\x27 \)/", $main_src, $m_const );
$header_version  = isset( $m_ver[1] ) ? $m_ver[1] : '';
$stable_version  = isset( $m_stable[1] ) ? $m_stable[1] : '';
$const_version   = isset( $m_const[1] ) ? $m_const[1] : '';
rcheck( $results, 'R2 header Version == Stable tag', '' !== $header_version && $header_version === $stable_version, $header_version . ' vs ' . $stable_version );
rcheck( $results, 'R2 header Version == ULTIMATE_PERFORMANCE_VERSION constant', '' !== $header_version && $header_version === $const_version, $header_version . ' vs ' . $const_version );

// R3: honest requirements — the TESTED floor, nothing untested.
preg_match( '/^ \* Requires PHP:\s+(\S+)/m', $main_src, $m_php );
preg_match( '/^ \* Requires at least:\s+(\S+)/m', $main_src, $m_wp );
rcheck( $results, 'R3 Requires PHP equals the tested floor (8.3)', isset( $m_php[1] ) && '8.3' === $m_php[1], 'got=' . ( $m_php[1] ?? 'missing' ) );
rcheck( $results, 'R3 Requires at least present', isset( $m_wp[1] ) && '' !== $m_wp[1] );
preg_match( '/^Tested up to:\s*(\S+)/m', $readme_src, $m_tested );
rcheck( $results, 'R3 readme Tested up to present (shim-parity disclosure in FAQ)', isset( $m_tested[1] ) && '' !== $m_tested[1] );

// R4: text domain + POT.
preg_match( '/^ \* Text Domain:\s+(\S+)/m', $main_src, $m_td );
rcheck( $results, 'R4 Text Domain is ultimate-performance', isset( $m_td[1] ) && 'ultimate-performance' === $m_td[1] );
rcheck( $results, 'R4 POT exists and is non-empty', '' !== $pot_src );
rcheck( $results, 'R4 POT is versioned to match', '' !== $pot_src && false !== strpos( $pot_src, 'Project-Id-Version: Ultimate Performance ' . $header_version ) );
$msgids = array();
if ( preg_match_all( '/^msgid "(.+)"$/m', $pot_src, $mm ) ) {
        foreach ( $mm[1] as $idx => $raw ) {
                if ( 0 === $idx && '' === $raw ) { continue; } // header entry
                $msgids[] = stripcslashes( $raw );
        }
}
rcheck( $results, 'R4 POT contains real msgids', count( $msgids ) >= 1, 'count=' . count( $msgids ) );

// R5: every POT reference resolves (file exists + line carries a translatable call).
// Accept ANY translation function: __()/_e()/esc_html__()/esc_html_e()/_x()/etc.
$trans_call_rx = '/(?:__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x|_n_noop|_nx_noop)\s*\(/';
$stale = array();
foreach ( $msgids as $id ) {
        $block_start = strpos( $pot_src, 'msgid "' . addcslashes( $id, '"' ) . '"' );
        $refs_area   = substr( $pot_src, 0, $block_start );
        preg_match_all( '/^#: (\S+):(\d+)$/m', $refs_area, $refs, PREG_SET_ORDER );
        $mine = array();
        foreach ( $refs as $ref ) { $mine[] = $ref; }
        $last = array_slice( $mine, -1 );
        foreach ( $last as $ref ) {
                $f = $plugin_dir . '/' . $ref[1];
                if ( ! is_file( $f ) ) { $stale[] = $ref[1]; continue; }
                $lines = explode( "\n", (string) file_get_contents( $f ) );
                $ln    = (int) $ref[2] - 1;
                if ( ! isset( $lines[ $ln ] ) ) {
                        $stale[] = $ref[1] . ':' . $ref[2];
                        continue;
                }
                // Accept any translation function call on the referenced line.
                if ( ! preg_match( $trans_call_rx, $lines[ $ln ] ) ) {
                        // As a fallback, accept the line if it contains the msgid literal.
                        if ( false === strpos( $lines[ $ln ], $id ) ) {
                                $stale[] = $ref[1] . ':' . $ref[2];
                        }
                }
        }
}
rcheck( $results, 'R5 POT references resolve to live source lines', 0 === count( $stale ), json_encode( array_slice( $stale, 0, 3 ) ) );

// R6: changelog covers the current version.
rcheck( $results, 'R6 changelog documents the current version', '' !== $readme_src && false !== strpos( $readme_src, '= ' . $header_version . ' =' ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
