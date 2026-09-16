<?php
/**
 * Branding Audit — verifies Ultimate Performance rebrand completeness.
 * Run: php tests/audit-branding.php
 */
namespace UltimatePerformance\Tests;
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
$results = array();
function bcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}
echo "=== Branding Audit ===\n\n";
$main = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'ultimate-performance.php' );
bcheck( $results, 'B1 Plugin Name = Ultimate Performance', false !== strpos( $main, 'Plugin Name:       Ultimate Performance' ), 'header not updated' );
bcheck( $results, 'B2 Author = alirezax5', false !== strpos( $main, 'Author:            alirezax5' ), 'author not updated' );
bcheck( $results, 'B3 Plugin URI = GitHub repo', false !== strpos( $main, 'https://github.com/alirezax5/Ultimate-Performance' ), 'URI not updated' );
bcheck( $results, 'B4 Version = 0.6.4 (preserved)', false !== strpos( $main, "define( 'ULTIMATE_PERFORMANCE_VERSION', '0.6.4' )" ), 'version changed' );
bcheck( $results, 'B5 Text Domain = ultimate-performance', false !== strpos( $main, "Text Domain:       ultimate-performance" ), 'text domain changed' );
bcheck( $results, 'B6 ULTIMATE_PERFORMANCE_VERSION retained', false !== strpos( $main, 'ULTIMATE_PERFORMANCE_VERSION' ), 'constant removed' );
bcheck( $results, 'B7 UltimateCache namespace retained', false !== strpos( $main, 'namespace UltimateCache' ), 'namespace changed' );
bcheck( $results, 'B8 ULTIMATE_PERFORMANCE_DIR retained', false !== strpos( $main, 'ULTIMATE_PERFORMANCE_DIR' ), 'constant removed' );
// Stale public branding check
$stale = 0;
foreach ( array( 'ultimate-performance.php', 'readme.txt', 'README.md', 'src/Admin/AdminPage.php', 'assets/js/admin.js', 'languages/ultimate-performance.pot' ) as $f ) {
        $src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . $f );
        if ( preg_match( '/[\'"]Ultimate Performance[\'"]/', $src ) || preg_match( '/Plugin Name:.*Ultimate Performance/', $src ) || preg_match( '/^=== Ultimate Performance ===/', $src ) || preg_match( '/^# Ultimate Performance/', $src ) ) {
                ++$stale; echo "  STALE: $f\n";
        }
}
bcheck( $results, 'B9 No stale PUBLIC "Ultimate Performance" branding', $stale === 0, "$stale stale refs" );
$readme = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'readme.txt' );
bcheck( $results, 'B10 readme.txt = Ultimate Performance', false !== strpos( $readme, '=== Ultimate Performance ===' ), 'readme not updated' );
bcheck( $results, 'B11 readme.txt Contributors = alirezax5', false !== strpos( $readme, 'Contributors: alirezax5' ), 'contributors not updated' );
$fa_exists = file_exists( ULTIMATE_PERFORMANCE_DIR . 'docs/fa/README.md' );
bcheck( $results, 'B12 Persian docs exist', $fa_exists, 'no docs/fa/README.md' );
if ( $fa_exists ) {
        $fa = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'docs/fa/README.md' );
        bcheck( $results, 'B13 Persian docs have RTL', false !== strpos( $fa, 'dir="rtl"' ), 'no RTL marker' );
}
$rm = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'README.md' );
bcheck( $results, 'B14 README.md has repo URL', false !== strpos( $rm, 'github.com/alirezax5/Ultimate-Performance' ), 'no repo URL' );
bcheck( $results, 'B15 Publisher alirezax5 in README', false !== strpos( $rm, 'alirezax5' ), 'no publisher' );
$pot = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'languages/ultimate-performance.pot' );
bcheck( $results, 'B16 POT updated brand', false !== strpos( $pot, 'Ultimate Performance' ), 'POT not updated' );
echo "\n==== SUMMARY ====\n";
$f = 0; foreach ( $results as $k => $v ) { if ( ! $v ) { ++$f; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $f failures\n";
exit( $f ? 1 : 0 );
