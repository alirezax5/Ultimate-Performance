<?php
/**
 * AUDIT TEST — Phase D7 regression: positive page-cache write path.
 *
 * Proves:
 *  W1  Fresh/wiped install (root subtree absent): clean write succeeds,
 *      creates dirs INSIDE the root, HTML+meta both exist, lookup round-trips.
 *  W2  Normal geometry (root exists): write still succeeds (no regression).
 *  W3  Metadata-mandatory invariant: when meta commit fails, the body is
 *      rolled back (deleted) and the entry reads as NOT found — no
 *      inconsistent state ever observable.
 *  W4  Containment stays strict: traversal/ADS/UNC/trailing-dot targets are
 *      still rejected after the ancestor-resolution fix (fail-closed).
 *  W5  Engine-level safe/unsafe pair (mirrors D6/D7 contract end-to-end).
 *
 * Run: php tests/audit-write-path.php   (real WordPress + DB required)
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\PageCache\Engine;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\Request\Classifier;
use UltimatePerformance\Security\ResponseSanitizer;

// Buffer ALL suite output for the whole run (same class of fix as the
// sanitizer suite's E8, M2-T2): the W5 engine-level block needs
// http_response_code() to be settable mid-run; once echoed output exceeds
// php.ini output_buffering the SAPI buffer flushes and the status can no
// longer be established, making W5 nondeterministic as the suite grows.
ob_start();

$results = array();
function wcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$settings = Settings::instance();
$san      = new ResponseSanitizer();
$fs       = new SafeFs();
$keygen   = new Key( $settings );
$store    = new Store( $fs, $keygen );

$root  = \UltimatePerformance\Core\Installer::cache_root();
$vtree = $root . '/v';

$clean = '<!DOCTYPE html><html><head><title>W</title></head><body>'
        . '<p>write-path regression probe marker qz9x.</p></body></html>';

// ---- W1: fresh/wiped install geometry --------------------------------------
// Remove the whole v-tree to force walk-up ABOVE the root.
if ( is_dir( $vtree ) ) {
        (new SafeFs())->delete_tree( $vtree );
}
wcheck( $results, 'W1a v-tree removed (fresh-install precondition)', ! is_dir( $vtree ) );

$built1 = $keygen->build( 'http', 'wp-regression.test', '/deep/path', '', array() );
$dir1   = is_array( $built1 ) ? $built1['dir'] : '';
$id1    = false === $built1 ? false : $store->write(
        $dir1, $clean, 200,
        array( 'Content-Type' => 'text/html; charset=UTF-8' ),
        array( 'post:1' ), 3600
);
wcheck( $results, 'W1b write succeeds with root subtree missing (fresh install)', false !== $id1, 'id=' . var_export( $id1, true ) . ' errs=' . implode( '; ', $fs->errors() ) );

$file1 = is_array( $built1 ) ? $keygen->absolute( $dir1 ) : '';
wcheck( $results, 'W1c HTML committed', false !== $file1 && file_exists( $file1 ), $file1 );
wcheck( $results, 'W1d metadata committed', false !== $file1 && file_exists( $file1 . '.meta.json' ) );
wcheck( $results, 'W1e created inside root (containment held)',
        false !== $file1 && 0 === stripos( wp_normalize_path( $file1 ), $root . '/' ) );
$lookup1 = $store->lookup( $dir1 );
wcheck( $results, 'W1f lookup round-trip finds fresh entry',
        is_array( $lookup1 ) && true === $lookup1['found'] && true === $lookup1['fresh']
        && false !== strpos( (string) $lookup1['body'], 'qz9x' )
        && isset( $lookup1['meta']['id'] ) && $id1 === $lookup1['meta']['id'] );
$meta1 = json_decode( (string) file_get_contents( $file1 . '.meta.json' ), true );
wcheck( $results, 'W1g meta consistent (tags attached, url_key set)',
        is_array( $meta1 ) && array( 'post:1' ) === $meta1['tags'] && '' !== (string) $meta1['url_key'] );

// ---- W2: normal geometry ----------------------------------------------------
$id2 = $store->write(
        $dir1, $clean . '<!--gen2-->', 200,
        array( 'Content-Type' => 'text/html; charset=UTF-8' ), array(), 3600
);
wcheck( $results, 'W2 rewrite over existing entry succeeds', false !== $id2 && $id1 !== $id2 );
$lookup2 = $store->lookup( $dir1 );
wcheck( $results, 'W2b lookup serves newest generation',
        false !== strpos( (string) $lookup2['body'], 'gen2' )
        && false === strpos( (string) $lookup2['body'], 'qz9x<!--' ) ); // old gen replaced

// ---- W2b (M3-D1): allowlisted query variants are DISCRIMINATED --------------
// Default query_allowlist is NON-empty (p, page_id, page, paged, feed, lang);
// before M3-D1 every allowlisted-query request shared the no-query dir and
// paginated archives overwrote each other (last write wins).
$bq0 = $keygen->build( 'http', 'wp-regression.test', '/', '', array() );
$bq2 = $keygen->build( 'http', 'wp-regression.test', '/', 'page=2', array() );
$bq3 = $keygen->build( 'http', 'wp-regression.test', '/', 'page=3', array() );
$bq2b = $keygen->build( 'http', 'wp-regression.test', '/', 'lang=en&page=2', array() ); // same canonical as page=2&lang=en (sorted)
$bq2t = $keygen->build( 'http', 'wp-regression.test', '/', 'page=2&utm_source=x', array() ); // tracking stripped → page=2
wcheck( $results, 'Q1 no-query and page=2 map to DIFFERENT dirs',
        is_array( $bq0 ) && is_array( $bq2 ) && $bq0['dir'] !== $bq2['dir'], $bq0['dir'] . ' vs ' . $bq2['dir'] );
wcheck( $results, 'Q2 page=2 and page=3 map to DIFFERENT dirs',
        is_array( $bq2 ) && is_array( $bq3 ) && $bq2['dir'] !== $bq3['dir'], $bq2['dir'] . ' vs ' . $bq3['dir'] );
$bq2c = $keygen->build( 'http', 'wp-regression.test', '/', 'page=2&lang=en', array() ); // same keys, other order
wcheck( $results, 'Q3 canonically-equal queries share one dir (sorted equality)',
        is_array( $bq2b ) && is_array( $bq2c ) && $bq2b['dir'] === $bq2c['dir'], $bq2b['dir'] . ' vs ' . $bq2c['dir'] );
wcheck( $results, 'Q4 tracking params stripped before discrimination (utm == base query)',
        is_array( $bq2 ) && is_array( $bq2t ) && $bq2['dir'] === $bq2t['dir'], $bq2t['dir'] );
wcheck( $results, 'Q5 query suffix shape (@q + 16 hex)',
        is_array( $bq2 ) && 1 === preg_match( '/@q[0-9a-f]{16}$/', $bq2['dir'] ), $bq2['dir'] );

$qid2 = $store->write(
        $bq2['dir'], $clean . '<!--query-page-2-->', 200,
        array( 'Content-Type' => 'text/html; charset=UTF-8' ), array( 'front_page' ), 3600
);
$qid3 = $store->write(
        $bq3['dir'], $clean . '<!--query-page-3-->', 200,
        array( 'Content-Type' => 'text/html; charset=UTF-8' ), array( 'front_page' ), 3600
);
wcheck( $results, 'Q6 both query variants written as separate entries',
        false !== $qid2 && false !== $qid3 && $qid2 !== $qid3, 'ids=' . var_export( array( $qid2, $qid3 ), true ) );
$lq2 = $store->lookup( $bq2['dir'] );
$lq3 = $store->lookup( $bq3['dir'] );
wcheck( $results, 'Q7 page=2 lookup returns page=2 body (no collision)',
        is_array( $lq2 ) && false !== strpos( (string) $lq2['body'], 'query-page-2' ) && false === strpos( (string) $lq2['body'], 'query-page-3' ), '' );
wcheck( $results, 'Q8 page=3 lookup returns page=3 body (no collision)',
        is_array( $lq3 ) && false !== strpos( (string) $lq3['body'], 'query-page-3' ) && false === strpos( (string) $lq3['body'], 'query-page-2' ), '' );
$lq0 = $store->lookup( is_array( $bq0 ) ? $bq0['dir'] : '' );
wcheck( $results, 'Q9 no-query entry absent (queries never leak into base dir)',
        ! is_array( $lq0 ) || true !== ( $lq0['found'] ?? false ), '' );
$store->purge( $bq2['dir'] );
$lq3b = $store->lookup( $bq3['dir'] );
wcheck( $results, 'Q10 purge of page=2 leaves page=3 intact',
        is_array( $lq3b ) && true === ( $lq3b['found'] ?? false ) && false !== strpos( (string) $lq3b['body'], 'query-page-3' ), '' );
$store->purge( $bq3['dir'] );

// ---- W3: metadata mandatory + rollback --------------------------------------
// Deterministic meta-commit failure WITHOUT touching SafeFs semantics:
// occupy the meta path with a directory so rename cannot land there.
$metapath = $file1 . '.meta.json';
@unlink( $metapath );
@mkdir( $metapath ); // directory blocks the atomic rename
wcheck( $results, 'W3 precondition: meta path occupied by directory', is_dir( $metapath ) );

$fs2    = new SafeFs(); // fresh error buffer
$store2 = new Store( $fs2, $keygen );
$id3    = $store2->write(
        $dir1, $clean . '<!--orphan-body-->', 200,
        array(), array(), 3600
);
wcheck( $results, 'W3a write returns false when meta commit fails', false === $id3, var_export( $id3, true ) );
wcheck( $results, 'W3b body rolled back — NO html-without-meta state',
        ! file_exists( $file1 ), 'body survived without meta!' );
$lookup3 = $store2->lookup( $dir1 );
wcheck( $results, 'W3c entry reads NOT FOUND after rollback (consistent)',
        is_array( $lookup3 ) && false === $lookup3['found'] );
@rmdir( $metapath ); // restore

// ---- W4: containment still strict after fix ---------------------------------
$escapes = array(
        'traversal-lexical'        => $root . '/../outside-qz.txt',
        'drive-relative'           => 'D:outside-qz.txt',
        'ads-colon'                => $root . '/v/x/index.html:hidden',
        'unc'                      => '//evil/share/qz.html',
        'trailing-dot-segment'     => $root . '/v/bad./index.html',
);
$strict = true;
foreach ( $escapes as $kind => $p ) {
        if ( false !== $fs2->validate_write( $p ) ) {
                $strict = false;
                echo "   escape ACCEPTED (bad): $kind => $p\n";
        }
}
wcheck( $results, 'W4 traversal/ADS/UNC/trailing-dot writes still rejected', $strict );
wcheck( $results, 'W4b outside-target file untouched',
        file_exists( WP_CONTENT_DIR . '/cache/../ultimate-performance-outside-canary.txt' ) || ! file_exists( dirname( $root ) . '/outside-qz.txt' ) );

// ---- W5: engine-level safe/unsafe pair (end-to-end contract) ----------------
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTP_ACCEPT']    = '';
http_response_code( 200 );

$engine = new Engine( $settings, new Classifier( $settings ), $san );
$engine->request_class = array( 'classification' => Classifier::PUBLIC_CACHEABLE, 'reason' => 'regression' );
$refDir = new \ReflectionProperty( $engine, 'current_rel_dir' );
$refDir->setAccessible( true );
$b5     = $keygen->build( 'http', $_SERVER['HTTP_HOST'], '/', '', array( 'accept' => '' ) );
$refDir->setValue( $engine, $b5['dir'] );

// Unsafe first: nonce-bearing markup must not reach disk.
$nonce_html = '<!DOCTYPE html><html><body>' . wp_nonce_field( 'up_reg', '_uc_reg_nonce', true, false ) . '</body></html>';
$engine->on_output( $unsafe_out = $nonce_html );
$hit_unsafe = false;
$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $vtree, \FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
        $c = (string) file_get_contents( $f->getPathname() );
        if ( false !== strpos( $c, '_uc_reg_nonce' ) || false !== strpos( $c, 'up_reg' ) ) { $hit_unsafe = true; }
}
wcheck( $results, 'W5a UNSAFE response wrote NOTHING', false === $hit_unsafe );

// Safe second: identical pipeline must write and serve from store.
$out5 = $engine->on_output( $clean );
$safe_lookup = ( new Store( new SafeFs(), $keygen ) )->lookup( $b5['dir'] );
wcheck( $results, 'W5b SAFE response written + readable back',
        is_array( $safe_lookup ) && true === $safe_lookup['found']
        && false !== strpos( (string) $safe_lookup['body'], 'qz9x' ) );

// ---- cleanup -----------------------------------------------------------------
( new Store( new SafeFs(), $keygen ) )->purge( $b5['dir'] );
$store->purge( $dir1 );
if ( is_dir( $root . '/v/wp-regression.test' ) ) {
        (new SafeFs())->delete_tree( $root . '/v/wp-regression.test' );
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
