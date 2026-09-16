<?php
/**
 * AUDIT TEST — Registry multi-process attach race (REG-1, permanent).
 *
 * Focused regression for the lost-update race previously present in
 * Registry::attach(): N REAL child processes attach DISJOINT members to the
 * SAME tag file concurrently. The final index must contain the complete
 * union — no member may be lost (RMW sections are serialized behind the
 * registry FileLock since the REG-1 fix).
 *
 * Controls:
 *   R1  single-process baseline: 200 attaches → 200 members.
 *   R2  8 children × 25 disjoint members, same tag → 200 members (union exact).
 *   R3  mixed attach/detach storm, 6 children: net union stays consistent,
 *       no phantom members, no lost members for survivors.
 *   R4  lock file released after runs (no stale registry.lock left).
 *
 * Run: php tests/audit-registry-concurrency.php   (exit 0 only when all pass)
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

if ( PHP_SAPI !== 'cli' ) {
        exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\CacheTag\Registry;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\Settings;

$results = array();
function rcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$php = PHP_BINARY;
$tag_ns = 'regcc-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );

/** Spawn one child that attaches its slice to one shared tag; returns decoded JSON. */
function reg_child( $php, $tag, array $members ) {
        $f = tempnam( sys_get_temp_dir(), 'uc-regcc-' );
        file_put_contents( $f, implode( "\n", $members ) );
        $cmd = sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg( $php ),
                escapeshellarg( __DIR__ . '/fixtures/reg-attach-child.php' ),
                escapeshellarg( $f ),
                escapeshellarg( $tag )
        );
        // BLOCKING variant — kept for single-child scenarios only.
        $raw = (string) shell_exec( $cmd );
        unlink( $f );
        return reg_decode_child_output( $raw );
}

/** Decode a child JSON result line (children may emit warnings first). */
function reg_decode_child_output( $raw ) {
        $line = '';
        foreach ( explode( "\n", trim( (string) $raw ) ) as $ln ) {
                if ( '' !== $ln && '{' === $ln[0] ) {
                        $line = $ln; // last JSON line
                }
        }
        $dec = json_decode( $line, true );
        return is_array( $dec ) ? $dec : array( 'ok' => false, 'error' => substr( (string) $raw, 0, 200 ) );
}

/**
 * Spawn ALL children in PARALLEL (popen handles are collected concurrently).
 * Sequential spawning would serialize the children and hide the exact
 * lost-update race this suite exists to catch.
 *
 * @param string $php
 * @param string $tag
 * @param array<int,array<int,string>> $slices member lists per child
 * @return array<int,array<string,mixed>> decoded outputs keyed by child index
 */
function reg_children_parallel( $php, $tag, array $slices ) {
        $files = array();
        $procs = array();
        $cmds  = array();
        foreach ( $slices as $p => $members ) {
                $f = tempnam( sys_get_temp_dir(), 'uc-regcc-' );
                file_put_contents( $f, implode( "\n", $members ) );
                $files[ $p ] = $f;
                $cmds[ $p ] = sprintf(
                        '%s %s %s %s 2>&1',
                        escapeshellarg( $php ),
                        escapeshellarg( __DIR__ . '/fixtures/reg-attach-child.php' ),
                        escapeshellarg( $f ),
                        escapeshellarg( $tag )
                );
                $procs[ $p ] = popen( $cmds[ $p ], 'r' );
        }
        $out = array();
        foreach ( $procs as $p => $rp ) {
                $raw       = (string) stream_get_contents( $rp );
                pclose( $rp );
                $out[ $p ] = reg_decode_child_output( $raw );
                unlink( $files[ $p ] );
        }
        return $out;
}

// ============================================================ R1 baseline
$tag1 = $tag_ns . '-base';
$reg  = new Registry();
for ( $i = 0; $i < 200; ++$i ) {
        $reg->attach( "localhost/$tag1/m$i/", array( $tag1 ) );
}
$m1 = count( $reg->members( $tag1 ) );
rcheck( $results, 'R1 single-process 200 attaches → 200 members', 200 === $m1, "members=$m1" );

// ============================================================ R2 multi-process union
$tag2      = $tag_ns . '-union';
$children  = 8;
$per_child = 25;
$expected  = array();
$slices    = array();
for ( $p = 0; $p < $children; ++$p ) {
        $slices[ $p ] = array();
        for ( $i = 0; $i < $per_child; ++$i ) {
                $rel                 = "localhost/$tag2/p$p/m$i/";
                $slices[ $p ][]      = $rel;
                $expected[ $rel ]    = true;
        }
}
// TRUE parallel start: all 8 children boot and attach simultaneously.
$outs      = reg_children_parallel( $php, $tag2, $slices );
$ok_all    = true;
foreach ( $outs as $p => $out ) {
        if ( empty( $out['ok'] ) ) {
                $ok_all = false;
                rcheck( $results, "R2 child $p reported ok", false, isset( $out['error'] ) ? $out['error'] : 'unknown' );
        }
}
$members2 = $reg->members( $tag2 );
$got      = array_fill_keys( array_map( 'strval', $members2 ), true );
$missing  = array_diff_key( $expected, $got );
$extra    = array_diff_key( $got, $expected );
rcheck( $results, 'R2 all children healthy', $ok_all );
rcheck(
        $results,
        'R2 union complete: ' . count( $expected ) . ' expected members all present',
        count( $members2 ) === count( $expected ) && empty( $missing ),
        'members=' . count( $members2 ) . ' missing=' . count( $missing )
);
rcheck( $results, 'R2 no phantom members', empty( $extra ), json_encode( array_slice( array_keys( $extra ), 0, 3 ) ) );

// ============================================================ R3 mixed attach/detach storm
$tag3    = $tag_ns . '-mixed';
$stable  = array();
$doomed  = array();
for ( $i = 0; $i < 30; ++$i ) {
        $stable[ "localhost/$tag3/keep/m$i/" ] = true;
        $doomed[ "localhost/$tag3/drop/m$i/" ] = true;
}
// Seed doomed members first (parent), children re-attach keep + doomed and detach doomed.
$reg->attach( "localhost/$tag3/starter/", array( $tag3 ) );
$child_members = array_merge( array_keys( $stable ), array( "localhost/$tag3/drop/m0/", "localhost/$tag3/drop/m1/" ) );
$child_args    = $child_members;
$detach_args   = array( "localhost/$tag3/drop/m0/", "localhost/$tag3/drop/m1/", "localhost/$tag3/starter/" );
$f             = tempnam( sys_get_temp_dir(), 'uc-regcc3-' );
file_put_contents( $f, implode( "\n", $child_args ) );
$detach_file   = tempnam( sys_get_temp_dir(), 'uc-regcc3d-' );
file_put_contents( $detach_file, implode( "\n", $detach_args ) );
$procs = array();
for ( $p = 0; $p < 6; ++$p ) {
        $cmd = sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg( $php ),
                escapeshellarg( __DIR__ . '/fixtures/reg-attach-child.php' ),
                escapeshellarg( $f ),
                escapeshellarg( $tag3 ),
                escapeshellarg( $detach_file )
        );
        $procs[] = popen( $cmd, 'r' );
}
$all_out = '';
foreach ( $procs as $rp ) {
        $all_out .= (string) stream_get_contents( $rp );
        pclose( $rp );
}
unlink( $f );
unlink( $detach_file );
$members3 = array_map( 'strval', $reg->members( $tag3 ) );
$got3     = array_fill_keys( $members3, true );
// Every keep member must be present (attached by every child under lock; dedup keeps one entry).
$missing_keep = array_diff_key( $stable, $got3 );
$phantoms     = array();
foreach ( $members3 as $m ) {
        if ( 0 !== strpos( $m, "localhost/$tag3/" ) ) {
                $phantoms[] = $m;
        }
}
rcheck(
        $results,
        'R3 storm: all keep members present after concurrent attach+detach',
        empty( $missing_keep ),
        'missing=' . count( $missing_keep )
);
rcheck( $results, 'R3 storm: no phantom members', empty( $phantoms ), json_encode( array_slice( $phantoms, 0, 3 ) ) );
rcheck(
        $results,
        'R3 storm: detached members absent (child detach honored)',
        ! isset( $got3[ "localhost/$tag3/drop/m0/" ] ) && ! isset( $got3[ "localhost/$tag3/starter/" ] ),
        'starter present=' . var_export( isset( $got3[ 'localhost/' . $tag3 . '/starter/' ] ), true )
);

// ============================================================ R4 lock hygiene
// LOCK-1 semantics: the lock FILE may persist (persistence is safe — flock
// ownership is the gate), but NO stale ownership may remain: the lock must be
// immediately acquirable and releasable after the runs.
$lock_probe = new \UltimatePerformance\Core\Lock\FileLock( Installer::cache_root() . '/meta/registry.lock' );
$acquirable = $lock_probe->acquire( 5 );
if ( $acquirable ) {
        $lock_probe->release();
}
rcheck(
        $results,
        'R4 registry.lock not stale (acquirable+releasable after runs)',
        $acquirable,
        'lock stuck — an owner did not release'
);

// Cleanup tag files owned by this run.
$meta = Installer::cache_root() . '/meta';
foreach ( array( $tag1, $tag2, $tag3 ) as $t ) {
        @unlink( $meta . '/tag-' . md5( $t ) . '.json' );
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
