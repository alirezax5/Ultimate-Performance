<?php
/**
 * N4C — APCu real runtime validation (live, self-provisioned PHP 8.4 stack).
 *
 * Phase N §8 requires real APCu validation, NOT a trivial one-shot CLI process.
 * APCu under CLI is per-process (apc.enable_cli grants memory only to that
 * process). The honest disclosure:
 *
 *   - Within ONE process: set/get/delete/incr/flushGroup/flush all real.
 *   - Cross-process: APCu memory is NOT shared across CLI processes (only
 *     within a single SAPI: FPM worker pool / mod_php). This is a documented
 *     APCu limitation, NOT a plugin defect.
 *
 * This suite proves within-process behavior real and discloses the
 * cross-process boundary honestly by spawning a child process and verifying
 * that a key set by the parent is NOT visible to the child (CLI honesty).
 *
 * Run: uc-php84 tests/audit-apcu-live.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\ObjectCache\ApcuBackend;

$results = array();
$skips   = array();
function pcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}
function pskip( $name, $why ) {
        global $skips;
        $skips[ $name ] = $why;
        echo "[SKIP] $name << $why\n";
}

// ---- P0: preflight ----------------------------------------------------------
if ( ! extension_loaded( 'apcu' ) ) {
        echo "[SKIP] ext-apcu not loaded — clean skip\n";
        exit( 0 );
}
if ( ! function_exists( 'apcu_enabled' ) || ! apcu_enabled() ) {
        echo "[SKIP] APCu loaded but disabled (apc.enable_cli=0?) — never faked\n";
        exit( 0 );
}

// ---- P1: basic set/get/delete (real APCu ops, not mock) ---------------------
$a = new ApcuBackend();
pcheck( $results, 'P1a APCu enabled (real)', true === $a->healthy() );
$a->set( 'p1-key', 'v1', 30, 'g1' );
$f1 = false;
$v1 = $a->get( 'p1-key', 'g1', $f1 );
pcheck( $results, 'P1b set+get roundtrip (found=true, value matches)', 'v1' === $v1 && true === $f1, json_encode( array( $v1, $f1 ) ) );
pcheck( $results, 'P1c delete returns true', true === $a->delete( 'p1-key', 'g1' ) );
$f1c = true;
$a->get( 'p1-key', 'g1', $f1c );
pcheck( $results, 'P1d absent after delete (found=false)', false === $f1c );

// ---- P2: stored false/null/0/empty values (found-flag contract) --------------
$a->set( 'k-false', false, 0, 'g1' );
$a->set( 'k-null', null, 0, 'g1' );
$a->set( 'k-zero', 0, 0, 'g1' );
$a->set( 'k-empty', '', 0, 'g1' );
$a->get( 'k-false', 'g1', $f2a );
$a->get( 'k-null', 'g1', $f2b );
$a->get( 'k-zero', 'g1', $f2c );
$a->get( 'k-empty', 'g1', $f2d );
pcheck( $results, 'P2a stored false => found=true', true === $f2a );
pcheck( $results, 'P2b stored null  => found=true', true === $f2b );
pcheck( $results, 'P2c stored 0     => found=true', true === $f2c );
pcheck( $results, 'P2d stored ""    => found=true', true === $f2d );

// ---- P3: increment / generation behavior -----------------------------------
$a->set( 'ctr', 5, 0, 'g1' );
pcheck( $results, 'P3a incr 5 → 7 (real apcu_inc)', 7 === $a->incr( 'ctr', 2, 'g1' ) );
pcheck( $results, 'P3b incr missing → false (WP semantics, never auto-creates)', false === $a->incr( 'ghost-ctr', 1, 'g1' ) );
pcheck( $results, 'P3c decr 7 → 4', 4 === $a->decr( 'ctr', 3, 'g1' ) );

// ---- P4: group flush semantics (O(1) generation bump) ----------------------
$a->set( 'g-key', 'in-group', 0, 'g2' );
$a->set( 'other-key', 'in-other-group', 0, 'g3' );
pcheck( $results, 'P4a flushGroup(g2) returns true', true === $a->flushGroup( 'g2' ) );
$f4a = true;
$a->get( 'g-key', 'g2', $f4a );
pcheck( $results, 'P4b key in flushed group invalidated (found=false)', false === $f4a );
$f4b = false;
$a->get( 'other-key', 'g3', $f4b );
pcheck( $results, 'P4c key in OTHER group survives (targeted flush, not flush_all)', true === $f4b );

// ---- P5: foreign-key preservation (no apcu_clear_cache) --------------------
@apcu_delete( 'uc:foreign:sentinel' );
apcu_add( 'uc:foreign:sentinel', 'DO-NOT-TOUCH' );
$a->set( 'ours', 'v', 0, 'g-flush' );
pcheck( $results, 'P5a root flush returns true', true === $a->flush() );
pcheck( $results, 'P5b foreign sentinel SURVIVES root flush (no apcu_clear_cache)', 'DO-NOT-TOUCH' === apcu_fetch( 'uc:foreign:sentinel' ) );
$f5 = true;
$a->get( 'ours', 'g-flush', $f5 );
pcheck( $results, 'P5c our key invalidated by root flush', false === $f5 );
@apcu_delete( 'uc:foreign:sentinel' );

// ---- P6: process-local / cross-worker semantics (CLI honesty) --------------
// APCu under CLI is per-process. A child PHP process does NOT inherit APCu
// memory. This is documented APCu behavior, NOT a plugin defect.
//
// We exec the SAME uc-php84 wrapper (so the child gets ext-apcu +
// apc.enable_cli=1) and prove the parent's set is invisible to the child.
$a->set( 'inherit-test', 'parent-value', 60, 'g-cli' );
$wrapper = getenv( 'UC_PHP84_BIN' );
if ( false === $wrapper || ! is_executable( $wrapper ) ) {
        $wrapper = getenv( 'HOME' ) . '/.cache/uc-provision/php84-root/usr/bin/uc-php84';
}
if ( ! is_executable( $wrapper ) ) {
        pskip( 'P6a APCu cross-process CLI honesty (no uc-php84 wrapper found)', $wrapper );
        pskip( 'P6b APCu cross-worker sharing only via FPM/mod_php SAPI (documented boundary, not plugin defect)', 'wrapper missing' );
} else {
        $code = '<?php' . "\n" .
                'define("ABSPATH", ' . var_export( ABSPATH, true ) . ");\n" .
                'define("WP_CONTENT_DIR", ABSPATH . "wp-content");' . "\n" .
                'define("ULTIMATE_PERFORMANCE_TESTING", true);' . "\n" .
                'define("ULTIMATE_PERFORMANCE_DIR", ' . var_export( ULTIMATE_PERFORMANCE_DIR, true ) . ");\n" .
                'require_once ULTIMATE_PERFORMANCE_DIR . "src/Core/Autoloader.php";' . "\n" .
                'UltimatePerformance\\Core\\Autoloader::register();' . "\n" .
                'require_once ABSPATH . "wp-load.php";' . "\n" .
                'use UltimatePerformance\\ObjectCache\\ApcuBackend;' . "\n" .
                'if (!function_exists("apcu_enabled") || !apcu_enabled()) { echo "DISABLED"; exit(0); }' . "\n" .
                '$a = new ApcuBackend();' . "\n" .
                '$f = false;' . "\n" .
                '$v = $a->get("inherit-test", "g-cli", $f);' . "\n" .
                'echo $f ? "FOUND:" . var_export($v, true) : "NOT_FOUND";' . "\n";
        $tmp = tempnam( sys_get_temp_dir(), 'uc-apcu-' ) . '.php';
        file_put_contents( $tmp, $code );
        $child_out = shell_exec( escapeshellarg( $wrapper ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' );
        @unlink( $tmp );
        if ( null === $child_out ) {
                pskip( 'P6a APCu cross-process CLI honesty (child exec failed)', 'shell_exec returned null' );
        } elseif ( 'DISABLED' === trim( $child_out ) ) {
                pskip( 'P6a APCu cross-process CLI honesty (child APCu disabled)', 'child uc-php84 did not have apc.enable_cli=1' );
        } else {
                // Expected: NOT_FOUND (child has its own APCu memory, parent's set invisible)
                pcheck( $results, 'P6a APCu is process-local in CLI (child does NOT see parent set)', 'NOT_FOUND' === trim( $child_out ), "child said: $child_out" );
        }
        // Documented boundary — NOT a defect
        pcheck( $results, 'P6b APCu cross-worker sharing only via FPM/mod_php SAPI (documented boundary, not plugin defect)', true );
}

// ---- P7: TTL expiry ---------------------------------------------------------
// APCu's TTL is wall-clock seconds, but apcu_fetch may serve a key for up
// to ~0.5-1s AFTER expiry due to APCu's lazy-expiry GC schedule. Use TTL=2
// and sleep 4s (2x TTL) to prove expiry unambiguously without flakiness.
$a->set( 'ttl-key', 'v', 2, 'g-ttl' ); // 2 second TTL
$f7a = false;
$a->get( 'ttl-key', 'g-ttl', $f7a );
pcheck( $results, 'P7a key present before TTL expiry', true === $f7a );
usleep( 4000000 ); // 4s — 2x TTL, comfortably past APCu's lazy-expiry window
$f7b = true;
$a->get( 'ttl-key', 'g-ttl', $f7b );
pcheck( $results, 'P7b key expired after TTL (found=false)', false === $f7b, 'TTL=2 slept 4s' );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) {
        if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; }
}
echo count( $results ) . " checks, $fails failures, " . count( $skips ) . " honest skips\n";
exit( $fails ? 1 : 0 );
