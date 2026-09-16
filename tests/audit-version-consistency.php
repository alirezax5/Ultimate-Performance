<?php
/**
 * Version consistency — plugin header, constant, readme, CHANGELOG, and
 * POT all reference the same version string.
 *
 * Run: php tests/audit-version-consistency.php
 */

namespace UltimatePerformance\Tests;

define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
$plugin_dir = ULTIMATE_PERFORMANCE_DIR;

$results = array();
function vc_check( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

$main_src   = file_get_contents( $plugin_dir . 'ultimate-performance.php' );
$readme_src = file_get_contents( $plugin_dir . 'readme.txt' );
$pot_src    = file_get_contents( $plugin_dir . 'languages/ultimate-performance.pot' );
$cl_src     = file_get_contents( $plugin_dir . 'CHANGELOG.md' );

// Extract version from header
preg_match( '/^\s*\* Version:\s*([0-9.]+)/m', $main_src, $m );
$header_version = $m[1] ?? '';
vc_check( $results, 'V1 header version present', '' !== $header_version, "got={$header_version}" );

// Extract from constant
preg_match( "/define\(\s*'ULTIMATE_PERFORMANCE_VERSION',\s*'([0-9.]+)'\s*\)/", $main_src, $m2 );
$const_version = $m2[1] ?? '';
vc_check( $results, 'V2 ULTIMATE_PERFORMANCE_VERSION constant present', '' !== $const_version, "got={$const_version}" );
vc_check( $results, 'V3 header version == constant', $header_version === $const_version, "header={$header_version} const={$const_version}" );

// Extract from readme stable tag
preg_match( '/^Stable tag:\s*([0-9.]+)/m', $readme_src, $m3 );
$readme_version = $m3[1] ?? '';
vc_check( $results, 'V4 readme stable tag present', '' !== $readme_version, "got={$readme_version}" );
vc_check( $results, 'V5 readme stable tag == header version', $readme_version === $header_version, "header={$header_version} readme={$readme_version}" );

// Extract from POT header
preg_match( '/Project-Id-Version: Ultimate Performance ([0-9.]+)/', $pot_src, $m4 );
$pot_version = $m4[1] ?? '';
vc_check( $results, 'V6 POT Project-Id-Version present', '' !== $pot_version, "got={$pot_version}" );
vc_check( $results, 'V7 POT version == header version', $pot_version === $header_version, "header={$header_version} pot={$pot_version}" );

// Extract from CHANGELOG.md (most recent header)
preg_match( '/^## \[([0-9.]+)\]/m', $cl_src, $m5 );
$cl_version = $m5[1] ?? '';
vc_check( $results, 'V8 CHANGELOG.md most recent version present', '' !== $cl_version, "got={$cl_version}" );
vc_check( $results, 'V9 CHANGELOG most recent == header version', $cl_version === $header_version, "header={$header_version} cl={$cl_version}" );

// Changelog entry exists in readme.txt
vc_check( $results, 'V10 readme.txt has changelog entry for current version', false !== strpos( $readme_src, "= {$header_version} =" ), "missing = {$header_version} = in readme" );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
