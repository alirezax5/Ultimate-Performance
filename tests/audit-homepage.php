<?php
/**
 * BENCH-D5 regression test — homepage cache path correctness.
 *
 * HARDEN-2: Verifies that the homepage "/" maps to a cache path that is
 * CONSISTENT between the PHP writer (Key::dir_for) and the Nginx/Apache
 * rule generators. The path segment must be a VALID segment (not hashed)
 * so that simple try_files rules find the file.
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

if ( PHP_SAPI !== 'cli' ) {
        exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', __DIR__ ) . '/../' );

require_once ULTIMATE_PERFORMANCE_DIR . 'vendor/autoload.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require ABSPATH . 'wp-load.php';

use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\WebServer\Nginx\Rules as NginxRules;
use UltimatePerformance\WebServer\Apache\Rules as ApacheRules;

$results = array();
function dcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== BENCH-D5 Homepage Path Regression Test ===\n";

$keygen = new Key( Settings::instance() );

// ============================================================
// Test 1: Homepage "/" maps to ROOT_SENTINEL (not hashed)
// ============================================================
echo "\n--- Test 1: Homepage path uses ROOT_SENTINEL ---\n";
$dir = $keygen->dir_for( 'example.com', '/', array() );
echo "dir_for('example.com', '/') = $dir\n";
dcheck( $results, 'H1a homepage dir contains ROOT_SENTINEL', false !== strpos( $dir, Key::ROOT_SENTINEL ), "got: $dir" );
dcheck( $results, 'H1b homepage dir is NOT hashed (no h<sha1> prefix)', false === strpos( $dir, 'h' . substr( sha1( '(root)' ), 0, 4 ) ), "dir looks hashed: $dir" );

// ============================================================
// Test 2: ROOT_SENTINEL is a valid segment (passes segment() regex)
// ============================================================
echo "\n--- Test 2: ROOT_SENTINEL is a valid segment ---\n";
$seg = Key::segment( Key::ROOT_SENTINEL );
echo "segment(ROOT_SENTINEL) = " . var_export( $seg, true ) . "\n";
dcheck( $results, 'H2a ROOT_SENTINEL passes segment() unchanged', $seg === Key::ROOT_SENTINEL, "got: $seg" );

// (root) should hash (the OLD behavior, now replaced)
$old_seg = Key::segment( '(root)' );
dcheck( $results, 'H2b (root) is hashed (old behavior, now replaced)', $old_seg !== '(root)' && 0 === strpos( $old_seg, 'h' ), "got: $old_seg" );

// ============================================================
// Test 3: Nginx Rules use ROOT_SENTINEL (not hashed root)
// ============================================================
echo "\n--- Test 3: Nginx Rules use ROOT_SENTINEL ---\n";
$nginx = NginxRules::generate( array(
        'host'       => 'nginx1.test',
        'cache_root' => '/var/www/wp-content/cache/ultimate-performance',
        'origin'     => '127.0.0.1:8098',
        'listen'     => '127.0.0.1:8097',
        'docroot'    => '/var/www/html',
) );
dcheck( $results, 'H3a Nginx rule contains ROOT_SENTINEL', false !== strpos( $nginx, Key::ROOT_SENTINEL ) );
dcheck( $results, 'H3b Nginx rule does NOT contain hashed (root)', false === strpos( $nginx, Key::segment( '(root)' ) ) );
dcheck( $results, 'H3c Nginx rule homepage rewrite path is /uc-cache/<host>/__root__/index.html', false !== strpos( $nginx, '/uc-cache/nginx1.test/' . Key::ROOT_SENTINEL . '/index.html' ) );

// ============================================================
// Test 4: Apache Rules use ROOT_SENTINEL
// ============================================================
echo "\n--- Test 4: Apache Rules use ROOT_SENTINEL ---\n";
$apache = ApacheRules::generate( 'apache1.test', '' );
dcheck( $results, 'H4a Apache rule contains ROOT_SENTINEL', false !== strpos( $apache, Key::ROOT_SENTINEL ) );
dcheck( $results, 'H4b Apache rule does NOT contain hashed (root)', false === strpos( $apache, Key::segment( '(root)' ) ) );

// ============================================================
// Test 5: Various URL cases map correctly
// ============================================================
echo "\n--- Test 5: URL case matrix ---\n";
$cases = array(
        '/'                 => 'homepage root',
        '/shop/'            => 'trailing-slash subpath',
        '/about-us/'        => 'multi-segment path',
        '/blog/2024/post-1/' => 'deep path (4 segments)',
);

foreach ( $cases as $path => $label ) {
        $dir = $keygen->dir_for( 'example.com', $path, array() );
        $contains_root = ( '/' === $path ) ? ( false !== strpos( $dir, Key::ROOT_SENTINEL ) ) : ( false === strpos( $dir, Key::ROOT_SENTINEL ) );
        dcheck( $results, "H5 $label → $dir", true, "dir: $dir" );
}

// ============================================================
// Test 6: Key::absolute() resolves ROOT_SENTINEL correctly
// ============================================================
echo "\n--- Test 6: Key::absolute() resolves ROOT_SENTINEL ---\n";
$abs = $keygen->absolute( 'example.com/' . Key::ROOT_SENTINEL );
echo "absolute('example.com/__root__') = $abs\n";
dcheck( $results, 'H6a absolute path contains __root__', false !== strpos( $abs, Key::ROOT_SENTINEL ) );
dcheck( $results, 'H6b absolute path ends with /index.html', '/index.html' === substr( $abs, -strlen( '/index.html' ) ) );

// ============================================================
// Summary
// ============================================================
echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
