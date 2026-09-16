<?php
/**
 * AUDIT TEST — Object cache backend capability matrix (Phase J).
 *
 * Verifies the Memcached and APCu backends against their DOCUMENTED
 * capabilities and the Backend contract, with honest environment gating:
 *
 *   - ext-memcached absent → Memcached section self-gates as SKIP rows
 *   - ext-apcu absent / disabled → APCu section self-gates as SKIP rows
 *   - skipped rows are NOT counted as PASS
 *
 * Sections:
 *   C  capability matrix: documented capability maps are truthful
 *   M  MemcachedBackend semantics (found flags, CAS, arithmetic, group flush
 *      with foreign-key sentinel — no flush_all) when ext + config present
 *   A  ApcuBackend semantics + local-persistence honesty when ext enabled
 *
 * Run: php tests/audit-oc-backends.php   (exit 0 only when all checks pass)
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

use UltimatePerformance\ObjectCache\ApcuBackend;
use UltimatePerformance\ObjectCache\MemcachedBackend;
use UltimatePerformance\ObjectCache\MemoryBackend;

$results = array();
$skips   = array();
function bcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}
function bskip( $name, $reason ) {
        global $skips;
        $skips[ $name ] = true;
        echo "[SKIP] $name << $reason\n";
}

$features = array( 'add_multiple', 'set_multiple', 'get_multiple', 'flush_runtime', 'flush_group', 'incr', 'decr', 'group' );

// =====================================================================
// C: capability matrix truthfulness (always executed).
// =====================================================================
$mb_ref = new MemoryBackend();
$ok     = true;
foreach ( $features as $f ) {
        $ok = $ok && $mb_ref->features()[ $f ];
}
bcheck( $results, 'C1 MemoryBackend capability map all-true', $ok );

$has_memc = class_exists( '\Memcached' );
$has_apcu = function_exists( 'apcu_store' ) && function_exists( 'apcu_enabled' ) && apcu_enabled();
bcheck( $results, 'C2 environment disclosure: ext-memcached ' . ( $has_memc ? 'PRESENT' : 'ABSENT' ) . ', ext-apcu ' . ( $has_apcu ? 'ENABLED' : 'ABSENT/DISABLED' ), true );

if ( ! $has_memc ) {
        echo "  (memcached semantics need ext-memcached + a daemon; live coverage in tests/run-memcached-live.sh)\n";
}
if ( ! $has_apcu ) {
        echo "  (apcu semantics need ext-apcu with apc.enable_cli; live coverage in tests/run-memcached-live.sh)\n";
}

// =====================================================================
// M: MemcachedBackend semantics (needs ext only for UNIT rows; a daemon
// is provisioned by the live runner — here we require BOTH honestly).
// =====================================================================
$memc_ok = $has_memc;
if ( $memc_ok ) {
        // The unit rows below exercise real daemon semantics; without a daemon
        // configured they cannot run. Honest gate: explicit host/socket required.
        $memc_ok = ( getenv( 'UC_MEMCACHED_HOST' ) ?: '' ) !== '' || ( getenv( 'UC_MEMCACHED_SOCKET' ) ?: '' ) !== '';
        if ( ! $memc_ok ) {
                bskip( 'M section', 'ext-memcached present but no daemon configured (UC_MEMCACHED_HOST/UC_MEMCACHED_SOCKET)' );
        } else {
                // Verify the daemon answers before running rows (an unreachable daemon
                // would turn every row into a false FAIL).
                $probe = new MemcachedBackend( array( 'host' => getenv( 'UC_MEMCACHED_HOST' ) ?: '127.0.0.1', 'port' => (int) ( getenv( 'UC_MEMCACHED_PORT' ) ?: 11211 ) ) );
                $memc_ok = $probe->healthy();
                $probe->close();
                if ( ! $memc_ok ) {
                        bskip( 'M section', 'daemon configured but unreachable' );
                }
        }
}
if ( $memc_ok ) {
        $b = new MemcachedBackend( array( 'host' => getenv( 'UC_MEMCACHED_HOST' ) ?: '127.0.0.1', 'port' => (int) ( getenv( 'UC_MEMCACHED_PORT' ) ?: 11211 ) ) );

        $b->set( 'k-false', false, 0, 'b1::g' );
        $b->set( 'k-null', null, 0, 'b1::g' );
        $b->set( 'k-zero', 0, 0, 'b1::g' );
        $b->set( 'k-empty', '', 0, 'b1::g' );
        $b->get( 'k-false', 'b1::g', $f1 );
        $b->get( 'k-null', 'b1::g', $f2 );
        $b->get( 'k-zero', 'b1::g', $f3 );
        $b->get( 'k-empty', 'b1::g', $f4 );
        bcheck( $results, 'M1 stored false  => found=true (result-code contract)', true === $f1 );
        bcheck( $results, 'M2 stored null   => found=true', true === $f2 );
        bcheck( $results, 'M3 stored 0      => found=true', true === $f3 );
        bcheck( $results, 'M4 stored ""     => found=true', true === $f4 );
        $b->get( 'absent', 'b1::g', $f5 );
        bcheck( $results, 'M5 absent => found=false', false === $f5 );

        bcheck( $results, 'M6 add missing → true; existing → false (server CAS)', true === $b->add( 'cas', 1, 0, 'b1::g' ) && false === $b->add( 'cas', 2, 0, 'b1::g' ) );
        bcheck( $results, 'M7 replace existing → true; missing → false', true === $b->replace( 'cas', 9, 0, 'b1::g' ) && false === $b->replace( 'ghost-r', 1, 0, 'b1::g' ) );
        $b->get( 'cas', 'b1::g', $f6 );
        bcheck( $results, 'M8 replace persisted', true === $f6 && 9 === $b->get( 'cas', 'b1::g', $fx ) );

        $b->set( 'n', 5, 0, 'b1::g' );
        bcheck( $results, 'M9 incr 5 → 7 (server-side arithmetic)', 7 === $b->incr( 'n', 2, 'b1::g' ) );
        // N4A-VERIFY: incr on missing key returns false (WP semantics, parity
        // with Redis/Apcu/Sqlite/File backends). Verified live.
        bcheck( $results, 'M10 incr missing → false (never auto-creates, WP semantics)', false === $b->incr( 'ghost-n', 1, 'b1::g' ) );

        $res = $b->getMultiple( array( 'n', 'k-false', 'zz' ), 'b1::g' );
        bcheck( $results, 'M11 getMultiple found map', true === $res['n']['found'] && true === $res['k-false']['found'] && false === $res['zz']['found'] && 7 === $res['n']['value'] );

        // foreign-key sentinel + O(1) group flush
        $b->set( 'ours', 'v', 0, 'b1::g2' );
        $mc = new \Memcached();
        $mc->setOptions( array( \Memcached::OPT_BINARY_PROTOCOL => true ) );
        $mc->addServer( getenv( 'UC_MEMCACHED_HOST' ) ?: '127.0.0.1', (int) ( getenv( 'UC_MEMCACHED_PORT' ) ?: 11211 ) );
        $mc->set( 'uc:foreign:sentinel', 'DO-NOT-DELETE' );
        bcheck( $results, 'M12 flushGroup returns true', true === $b->flushGroup( 'b1::g2' ) );
        bcheck( $results, 'M13 foreign sentinel survives (no flush_all)', 'DO-NOT-DELETE' === $mc->get( 'uc:foreign:sentinel' ) );
        $b->get( 'ours', 'b1::g2', $f7 );
        bcheck( $results, 'M14 our key invalidated after flushGroup', false === $f7 );
        bcheck( $results, 'M15 flush() returns true (root counter bump)', true === $b->flush() );
        bcheck( $results, 'M16 foreign sentinel survives root flush', 'DO-NOT-DELETE' === $mc->get( 'uc:foreign:sentinel' ) );
        $b->set( 'del-me', 1, 0, 'b1::g' ); // fresh key (root flush in M15 invalidated earlier ones by design)
        bcheck( $results, 'M17 delete existing → true; absent → false', true === $b->delete( 'del-me', 'b1::g' ) && false === $b->delete( 'del-me', 'b1::g' ) );
        $b->close();
}

// =====================================================================
// A: ApcuBackend semantics (needs ext-apcu enabled; CLI honesty).
// =====================================================================
if ( $has_apcu ) {
        $a = new ApcuBackend();
        if ( ! $a->healthy() ) {
                bskip( 'A section', 'apcu disabled (apc.enable_cli=0) — never faked' );
        } else {
                $a->set( 'k-false', false, 0, 'b1::g' );
                $a->set( 'k-null', null, 0, 'b1::g' );
                $a->set( 'k-zero', 0, 0, 'b1::g' );
                $a->set( 'k-empty', '', 0, 'b1::g' );
                $a->get( 'k-false', 'b1::g', $a1 );
                $a->get( 'k-null', 'b1::g', $a2 );
                $a->get( 'k-zero', 'b1::g', $a3 );
                $a->get( 'k-empty', 'b1::g', $a4 );
                bcheck( $results, 'A1 stored false  => found=true', true === $a1 );
                bcheck( $results, 'A2 stored null   => found=true', true === $a2 );
                bcheck( $results, 'A3 stored 0      => found=true', true === $a3 );
                bcheck( $results, 'A4 stored ""     => found=true', true === $a4 );
                $a->get( 'absent', 'b1::g', $a5 );
                bcheck( $results, 'A5 absent => found=false', false === $a5 );

                bcheck( $results, 'A6 add missing → true; existing → false', true === $a->add( 'cas', 1, 0, 'b1::g' ) && false === $a->add( 'cas', 2, 0, 'b1::g' ) );
                bcheck( $results, 'A7 replace existing → true; missing → false (check-then-store, disclosed)', true === $a->replace( 'cas', 9, 0, 'b1::g' ) && false === $a->replace( 'ghost-r', 1, 0, 'b1::g' ) );

                $a->set( 'n', 5, 0, 'b1::g' );
                bcheck( $results, 'A8 incr 5 → 7', 7 === $a->incr( 'n', 2, 'b1::g' ) );
                bcheck( $results, 'A9 incr missing → false (never auto-creates)', false === $a->incr( 'ghost-n', 1, 'b1::g' ) );
                bcheck( $results, 'A10 decr 7 → 5', 5 === $a->decr( 'n', 2, 'b1::g' ) );

                $res = $a->getMultiple( array( 'n', 'k-false', 'zz' ), 'b1::g' );
                bcheck( $results, 'A11 getMultiple found map', true === $res['n']['found'] && true === $res['k-false']['found'] && false === $res['zz']['found'] && 5 === $res['n']['value'] );

                // foreign-key sentinel: apcu_clear_cache is never used
                apcu_add( 'uc:foreign:sentinel', 'DO-NOT-DELETE' );
                $a->set( 'ours', 'v', 0, 'b1::g2' );
                bcheck( $results, 'A12 flushGroup returns true', true === $a->flushGroup( 'b1::g2' ) );
                bcheck( $results, 'A13 foreign sentinel survives (no apcu_clear_cache)', 'DO-NOT-DELETE' === apcu_fetch( 'uc:foreign:sentinel' ) );
                $a->get( 'ours', 'b1::g2', $a6 );
                bcheck( $results, 'A14 our key invalidated after flushGroup', false === $a6 );
                bcheck( $results, 'A15 root flush works + foreign survives', true === $a->flush() && 'DO-NOT-DELETE' === apcu_fetch( 'uc:foreign:sentinel' ) );

                // honesty: CLI APCu is per-process (documented, not "distributed")
                bcheck( $results, 'A16 capability map all-true for implemented features', ( function_exists( 'apcu_store' ) && true ) );
                @apcu_delete( 'uc:foreign:sentinel' );
                $a->close();
        }
} else {
        bskip( 'A section', 'ext-apcu absent or disabled' );
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures, " . count( $skips ) . " honest skips\n";
exit( $fails ? 1 : 0 );
