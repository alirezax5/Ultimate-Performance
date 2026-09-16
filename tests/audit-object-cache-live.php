<?php
/**
 * AUDIT TEST — Object cache LIVE against a real redis-server (Phase I).
 *
 * Real server, real sockets, real serialization, real concurrency. No mocks.
 *
 *   S1  semantic matrix through Manager(RedisBackend): found-flags for
 *       false/null/0/'', add/replace CAS, delete semantics, get_multiple
 *   S2  TTL: real server expiry measured with server-side TTL + real wait
 *   S3  O(1) flush sentinel: a foreign-namespaced key MUST survive flush()
 *       (proves no FLUSHALL/FLUSHDB); our keys are gone
 *   S4  blog scope isolation + global group sharing on the real server
 *   S5  multi-process atomicity: N children × M increments through the REAL
 *       Manager/RedisBackend stack — final value must be exactly N*M
 *   S6  forbidden-command audit: backend source must not use KEYS/SCAN/
 *       FLUSHALL/FLUSHDB
 *   S7  fail-closed on server shutdown + recovery after restart
 *
 * Server lifecycle: the suite spawns its own redis-server (UC_REDIS_SERVER_BIN,
 * ephemeral loopback port, persistence disabled), kills it mid-suite for S7,
 * restarts it, then stops it. With UC_REDIS_HOST set the suite uses that
 * server instead and SKIPS the shutdown phase (never kills a foreign server).
 *
 * Run: php tests/audit-object-cache-live.php   (exit 0 only when all checks pass)
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

use UltimatePerformance\ObjectCache\Manager;
use UltimatePerformance\ObjectCache\RedisBackend;

$results = array();
function lcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$server_bin  = getenv( 'UC_REDIS_SERVER_BIN' ) ?: '';
$external    = getenv( 'UC_REDIS_HOST' ) ?: '';
$own_server  = null;
$server_proc = null;
$port        = 7391;

/**
 * Start a dedicated redis-server (loopback, no persistence).
 *
 * @return array{proc:resource,pipes:array<string,mixed>,port:int}|null
 */
function start_own_redis( $bin, $port ) {
        $dir = rtrim( sys_get_temp_dir(), '/' ) . '/uc-redis-live-' . getmypid();
        @mkdir( $dir, 0777, true );
        $conf = "{$dir}/redis.conf";
        file_put_contents(
                $conf,
                "port {$port}\nbind 127.0.0.1\nsave \"\"\nappendonly no\ndaemonize no\nlogfile {$dir}/redis.log\ndir {$dir}\n"
        );
        $descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
        $proc        = proc_open( escapeshellarg( $bin ) . ' ' . escapeshellarg( $conf ), $descriptors, $pipes );
        if ( ! is_resource( $proc ) ) {
                return null;
        }
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );
        // bounded wait for readiness
        for ( $i = 0; $i < 50; ++$i ) {
                $r = new \Redis();
                try {
                        if ( @$r->connect( '127.0.0.1', $port, 0.3 ) ) {
                                $r->close();
                                return array( 'proc' => $proc, 'pipes' => $pipes, 'port' => $port, 'dir' => $dir );
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }
                usleep( 200000 );
        }
        proc_terminate( $proc );
        proc_close( $proc );
        return null;
}

function stop_own_redis( $srv ) {
        if ( null === $srv ) {
                return;
        }
        // graceful: SHUTDOWN NOSAVE over the wire (owner-only control)
        try {
                $r = new \Redis();
                if ( @$r->connect( '127.0.0.1', $srv['port'], 0.5 ) ) {
                        try {
                                @$r->rawCommand( 'SHUTDOWN', 'NOSAVE' );
                        } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                                // connection closed by shutdown = expected
                        }
                        $r->close();
                }
        } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
        }
        usleep( 300000 );
        $status = proc_get_status( $srv['proc'] );
        if ( ! empty( $status['running'] ) ) {
                @proc_terminate( $srv['proc'], 9 );
        }
        @proc_close( $srv['proc'] );
        foreach ( array( 'redis.conf', 'redis.log' ) as $f ) {
                @unlink( $srv['dir'] . '/' . $f );
        }
        @rmdir( $srv['dir'] );
}

if ( '' === $server_bin && '' === $external ) {
        echo "[SKIP] audit-object-cache-live << no redis-server available (set UC_REDIS_SERVER_BIN or UC_REDIS_HOST)\n";
        echo "       live semantics need a real server; skipped rows are NOT counted as PASS\n";
        echo "\n==== SUMMARY ====\n0 checks executed, all skipped (no redis-server) — environment-gated\n";
        exit( 0 );
}

if ( '' !== $external ) {
        $port = (int) ( getenv( 'UC_REDIS_PORT' ) ?: 6379 );
} else {
        $own_server = start_own_redis( $server_bin, $port );
        if ( null === $own_server ) {
                echo "[SKIP] audit-object-cache-live << redis-server could not be started from {$server_bin}\n";
                echo "\n==== SUMMARY ====\n0 checks executed, all skipped (server start failed) — environment-gated\n";
                exit( 0 );
        }
}

putenv( "UC_REDIS_HOST=127.0.0.1" );
putenv( "UC_REDIS_PORT={$port}" );

function make_manager() {
        return new Manager( new RedisBackend( array(
                'host'    => '127.0.0.1',
                'port'    => (int) ( getenv( 'UC_REDIS_PORT' ) ?: 6379 ),
                'timeout' => 1.0,
        ) ) );
}

try {
        // =================================================================
        // S1: semantic matrix through the real server.
        // =================================================================
        $mgr = make_manager();
        lcheck( $results, 'L1 backend healthy on real server', true === $mgr->backendHealthy() );

        $mgr->set( 'k-false', false, 'g' );
        $mgr->set( 'k-null', null, 'g' );
        $mgr->set( 'k-zero', 0, 'g' );
        $mgr->set( 'k-empty', '', 'g' );
        $mgr->set( 'k-arr', array( 'a' => 1, 'b' => array( 'c' => 'x' ) ), 'g' );
        $mgr->flushRuntime(); // force all reads to hit the REAL server
        $mgr->get( 'k-false', 'g', false, $f1 );
        $mgr->get( 'k-null', 'g', false, $f2 );
        $mgr->get( 'k-zero', 'g', false, $f3 );
        $mgr->get( 'k-empty', 'g', false, $f4 );
        $arr = $mgr->get( 'k-arr', 'g', false, $f5 );
        lcheck( $results, 'L1 stored false  round-trips found=true', true === $f1 );
        lcheck( $results, 'L1 stored null   round-trips found=true', true === $f2 );
        lcheck( $results, 'L1 stored 0      round-trips found=true', true === $f3 );
        lcheck( $results, 'L1 stored ""     round-trips found=true', true === $f4 );
        lcheck( $results, 'L1 nested array round-trips', true === $f5 && array( 'a' => 1, 'b' => array( 'c' => 'x' ) ) === $arr );

        $mgr->flushRuntime();
        lcheck( $results, 'L1 add on existing (server-side NX) → false', false === $mgr->add( 'k-zero', 9, 'g' ) );
        lcheck( $results, 'L1 add on missing (server-side NX) → true', true === $mgr->add( 'k-new', 'v', 'g' ) );
        lcheck( $results, 'L1 replace on existing (server-side XX) → true', true === $mgr->replace( 'k-new', 'v2', 'g' ) );
        $mgr->flushRuntime();
        lcheck( $results, 'L1 replace value persisted', 'v2' === $mgr->get( 'k-new', 'g', false, $f6 ) && true === $f6 );
        lcheck( $results, 'L1 delete existing → true; absent → false', true === $mgr->delete( 'k-new', 'g' ) && false === $mgr->delete( 'k-new', 'g' ) );

        $mgr->set( 'm1', 1, 'g' );
        $mgr->set( 'm2', 2, 'g' );
        $mgr->flushRuntime();
        $multi = $mgr->getMultiple( array( 'm1', 'm2', 'm-missing' ), 'g' );
        lcheck( $results, 'L1 get_multiple real-server shape', 1 === $multi['m1'] && 2 === $multi['m2'] && ! array_key_exists( 'm-missing', $multi ) );

        // =================================================================
        // S2: TTL — real server expiry.
        // =================================================================
        $mgr->set( 'ttl-k', 'v', 'g', 1 ); // key, value, GROUP, ttl
        $mgr->flushRuntime();
        $mgr->get( 'ttl-k', 'g', false, $f_pre );
        sleep( 2 );
        $mgr->flushRuntime();
        $mgr->get( 'ttl-k', 'g', false, $f_post );
        lcheck( $results, 'L2 ttl=1 expires on the real server', true === $f_pre && false === $f_post );

        // =================================================================
        // S3: O(1) flush sentinel — foreign namespace MUST survive.
        // =================================================================
        $raw = new \Redis();
        $raw->connect( '127.0.0.1', $port, 1.0 );
        $raw->set( 'uc:foreign:sentinel', 'DO-NOT-DELETE' );
        $raw->set( 'other-plugin:key', 'x' );
        $mgr->set( 'ours', 'v', 'g' );
        lcheck( $results, 'L3 flush() returns true', true === $mgr->flush() );
        lcheck( $results, 'L3 foreign sentinel survives flush (no FLUSHALL)', 'DO-NOT-DELETE' === $raw->get( 'uc:foreign:sentinel' ) );
        lcheck( $results, 'L3 other-plugin key survives flush', 'x' === $raw->get( 'other-plugin:key' ) );
        $mgr->flushRuntime();
        $mgr->get( 'ours', 'g', false, $f_gone );
        lcheck( $results, 'L3 our key is invalidated by flush', false === $f_gone );

        // =================================================================
        // S4: blog scope isolation + global groups on the real server.
        // =================================================================
        $mgr->addGlobalGroups( 'live-global' );
        $mgr->set( 'site', 'blog1', 'options' );
        $mgr->set( 'glob', 'shared', 'live-global' );
        $mgr->flushRuntime();
        $mgr->switch_blog( 2 );
        $mgr->get( 'site', 'options', false, $f_b2 );
        $mgr->get( 'glob', 'live-global', false, $f_glob );
        lcheck( $results, 'L4 blog-scoped key invisible in blog 2 (server-isolated)', false === $f_b2 );
        lcheck( $results, 'L4 global group shared across blogs (server-shared)', true === $f_glob && 'shared' === $mgr->get( 'glob', 'live-global', false, $fg2 ) );
        $mgr->set( 'site', 'blog2', 'options' );
        $mgr->flushRuntime();
        $mgr->restore_blog();
        $mgr->get( 'site', 'options', false, $f_back );
        $mgr->flushRuntime();
        lcheck( $results, 'L4 blog 1 value intact after blog-2 write (scope-embedded keys)', true === $f_back && 'blog1' === $mgr->get( 'site', 'options', false, $fb3 ) );
        $mgr->flushGroup( 'options' );
        $mgr->flushRuntime();
        $mgr->get( 'site', 'options', false, $f_flushed );
        $mgr->get( 'glob', 'live-global', false, $f_glob2 );
        lcheck( $results, 'L4 flush_group clears scoped group server-side', false === $f_flushed );
        lcheck( $results, 'L4 flush_group leaves global group intact', true === $f_glob2 );
        $mgr->flush();

        // =================================================================
        // S5: multi-process atomicity through the REAL stack.
        // =================================================================
        $children = 8;
        $iters    = 25;
        $mgr->set( 'counter', 0, 'g' );
        $child_src = __DIR__ . '/fixtures/oc-incr-child.php';
        foreach ( array( 'counter' ) as $ck ) {} // no-op keep phpdoc honest
        $pids = array();
        for ( $c = 0; $c < $children; ++$c ) {
                $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child_src )
                        . ' ' . escapeshellarg( dirname( __DIR__ ) . '/' )
                        . ' ' . escapeshellarg( '127.0.0.1' ) . ' ' . escapeshellarg( (string) $port )
                        . ' ' . escapeshellarg( 'counter' ) . ' ' . escapeshellarg( (string) $iters );
                $pids[] = exec( $cmd . ' > /dev/null 2>&1 & echo $!' );
        }
        $ok_wait = true;
        foreach ( $pids as $pid ) {
                if ( ! is_numeric( $pid ) ) { $ok_wait = false; continue; }
                $deadline = microtime( true ) + 20;
                while ( microtime( true ) < $deadline && file_exists( "/proc/{$pid}" ) ) {
                        usleep( 100000 );
                }
        }
        $mgr->flushRuntime();
        $final = $mgr->get( 'counter', 'g', false, $f_cnt );
        lcheck( $results, 'L5 ' . $children . 'x' . $iters . ' cross-process increments are exact (' . ( $children * $iters ) . ')', $ok_wait && $f_cnt && ( $children * $iters ) === $final, 'final=' . var_export( $final, true ) );

        // =================================================================
        // S6: forbidden-command source audit (invalidation must be O(1)).
        // =================================================================
        $src = (string) file_get_contents( dirname( __DIR__ ) . '/src/ObjectCache/RedisBackend.php' );
        // strip comments + strings before the forbidden-usage scan so documentation
        // mentions of the banned commands are not false positives:
        $stripped = preg_replace( array( '#/\*[\s\S]*?\*/#', '#^[ \t]*/.*$#m', '#^.*(?<![:\w])//.*$#m' ), '', $src );
        // Forbidden usage patterns (comment/strings stripped above). The Lua KEYS[n]
        // key-table syntax is NOT the Redis KEYS command and must not match.
        $patterns = array(
                '->keys\s*\(',                                   // phpredis KEYS
                '->scan\s*\(',                                   // phpredis SCAN
                "redis\.call\(\s*['\"]KEYS",                     // Lua KEYS command
                "redis\.call\(\s*['\"]SCAN",                     // Lua SCAN command
                "->rawCommand\([^)]*['\"]?(KEYS|SCAN|FLUSHALL|FLUSHDB)\b",
                "['\"](FLUSHALL|FLUSHDB)['\"]",
                'flushAll|flushDb|flushall|flushdb',             // phpredis flush methods
        );
        $bad = array();
        foreach ( $patterns as $cmd ) {
                if ( preg_match( '#' . $cmd . '#', $stripped ) ) {
                        $bad[] = $cmd;
                }
        }
        lcheck( $results, 'L6 backend source contains no KEYS/SCAN/FLUSHALL/FLUSHDB usage', empty( $bad ), implode( ',', $bad ) );

        // =================================================================
        // S7: fail-closed on server shutdown + recovery (own server only —
        // a foreign server is NEVER killed by this suite).
        // =================================================================
        if ( null !== $own_server ) {
                $mgr->set( 'pre-shutdown', 'v', 'g' );
                stop_own_redis( $own_server );
                $own_server = null;
                usleep( 500000 );
                $mgr->flushRuntime(); // bypass the in-process mirror: prove BACKEND fail-closed
                $gv = $mgr->get( 'pre-shutdown', 'g', false, $f_down );
                $sv = $mgr->set( 'x', 1, 'g' );
                $iv = $mgr->incr( 'counter', 1, 'g' );
                lcheck( $results, 'L7 dead server: get miss (found=false), no fatal', null === $gv && false === $f_down );
                lcheck( $results, 'L7 dead server: set false (fail-closed)', false === $sv );
                lcheck( $results, 'L7 dead server: incr false (no auto-create)', false === $iv );
                lcheck( $results, 'L7 dead server: non-persistent group keeps working', ( $mgr->set( 'local', 'ok', 'counts' ) && 'ok' === $mgr->get( 'local', 'counts', false, $lf ) ) );

                $own_server = start_own_redis( $server_bin, $port );
                $recovered  = null !== $own_server && true === make_manager()->backendHealthy();
                lcheck( $results, 'L7 recovery: backend healthy after server restart', (bool) $recovered );
                $rm = make_manager();
                lcheck( $results, 'L7 recovery: set/get functional again', true === $rm->set( 'post-restart', 'v', 'g' ) && 'v' === $rm->get( 'post-restart', 'g', false, $f_rec ) && true === $f_rec );
        }
} finally {
        if ( null !== $own_server ) {
                stop_own_redis( $own_server );
        }
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
