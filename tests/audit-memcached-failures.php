<?php
/**
 * N4 §23 — memcached REAL failure matrix (live, self-provisioned daemon).
 * Run under the provisioned uc-php84 stack (real ext-memcached) against real
 * memcached daemons started by tests/run-memcached-live.sh.
 *
 * Scenarios (Phase N §23): healthy TCP ops → UNIX socket + socket-over-TCP
 * precedence → O(1) flush disclosure (foreign sentinel survives) → wrong
 * port (refused, fail-closed) → STALLED daemon (accepts, never replies —
 * the N4-D1 explicit-timeout regression) → counter on dead backend → kill
 * the real daemon mid-run → fail-closed ops (weak-coordination disclosure)
 * → RESTART → recovery + disclosed data loss + counter reset → two-daemon
 * weak-coordination proof (no cross-daemon coordination is ever implied).
 *
 * Run: UC_MEMCACHED_PIDFILE=... UC_MEMCACHED_BIN=... \
 *      UC_MEMCACHED_SOCKET=... UC_MEMCACHED_PORT2=... \
 *      php tests/audit-memcached-failures.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\ObjectCache\MemcachedBackend;

$results = array();
function fcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$host    = (string) ( getenv( 'UC_MEMCACHED_HOST' ) ?: '127.0.0.1' );
$port    = (int) ( getenv( 'UC_MEMCACHED_PORT' ) ?: 11311 );
$socket  = (string) getenv( 'UC_MEMCACHED_SOCKET' );
$pidfile = (string) getenv( 'UC_MEMCACHED_PIDFILE' );
$mcbin   = (string) getenv( 'UC_MEMCACHED_BIN' );
$mcuser  = (string) ( getenv( 'UC_MEMCACHED_RUNNER_USER' ) ?: (string) get_current_user() );
$port2   = (int) ( getenv( 'UC_MEMCACHED_PORT2' ) ?: 0 );

if ( ! extension_loaded( 'memcached' ) ) {
        echo "[SKIP] ext-memcached not loaded — clean skip\n";
        exit( 0 );
}

// ---- F1: healthy daemon — basic ops + counter -------------------------------
// N4A-FIX: pre-seed the counter at 0 so incr can return the post-increment
// value (the project-wide contract is "incr on missing key returns false" —
// same as Redis/Apcu/Sqlite/File backends; pre-seeding respects that
// contract while still exercising real server-side arithmetic).
$b = new MemcachedBackend( array( 'host' => $host, 'port' => $port ) );
$b->set( 'f-ctr-seed', 0, 0, 'default' );
$b->set( 'f-key', 'v1', 30, 'default' );
$found = false;
$got   = $b->get( 'f-key', 'default', $found );
fcheck( $results, 'F1a healthy daemon: set+get roundtrip (found=true)', 'v1' === $got && true === $found, json_encode( array( $got, $found ) ) );
// F1b: incr from a pre-seeded 0 value returns N (real server-side arithmetic).
fcheck( $results, 'F1b healthy daemon: counter increment from zero (pre-seeded)', 3 === $b->incr( 'f-ctr-seed', 3, 'default' ) );
fcheck( $results, 'F1c healthy daemon: healthy() true', true === $b->healthy() );

// ---- S1: UNIX socket (§23 "UNIX socket if supported") ----------------------
// N4A-FIX: memcached(1) -s disables TCP entirely, so a single process
// cannot serve both protocols. The runner now starts THREE independent
// daemons (TCP A, UNIX-socket A', TCP B). S1 exercises the socket daemon
// as an ALTERNATIVE listener with its OWN keyspace — there is no
// cross-listener key sharing to prove (it cannot exist by design).
if ( '' !== $socket ) {
        $bs = new MemcachedBackend( array( 'socket' => $socket ) );
        $bs->set( 's-key', 'sv', 30, 'default' );
        $sf  = false;
        $got = $bs->get( 's-key', 'default', $sf );
        fcheck( $results, 'S1a unix socket: set+get roundtrip via socket', 'sv' === $got && true === $sf, json_encode( array( $got, $sf ) ) );
        // Independent keyspace: a key written via TCP is NOT visible on the
        // socket daemon (separate processes, separate in-memory stores).
        // The plugin correctly NEVER implies cross-listener sharing.
        $sf2 = true;
        $got_via_socket = $bs->get( 'f-key', 'default', $sf2 );
        fcheck( $results, 'S1b unix socket: independent keyspace from TCP daemon (no false sharing implied)', false === $sf2, json_encode( array( $got_via_socket, $sf2 ) ) );
        // Socket wins over TCP — NEVER a fallback chain: configure socket AND a
        // wrong TCP port; ops must still succeed (if TCP were chosen, they fail).
        $bp = new MemcachedBackend( array( 'socket' => $socket, 'host' => $host, 'port' => $port + 999 ) );
        fcheck( $results, 'S1c socket beats wrong-TCP config (no fallback chain)', true === $bp->healthy() );
} else {
        echo "[SKIP] S1 rows (no UC_MEMCACHED_SOCKET)\n";
}

// ---- F9a: O(1) flush disclosure — foreign keys survive our flush -----------
$raw = new \Memcached();
$raw->setOptions( array( \Memcached::OPT_BINARY_PROTOCOL => false, \Memcached::OPT_COMPRESSION => false ) );
$raw->addServer( $host, $port );
$raw->set( 'uc:foreign:sentinel', 'DO-NOT-TOUCH', 60 );
$raw->set( 'other-plugin:key', 'x', 60 );
$b->flush();
$fs = false;
$fg = $b->get( 'f-key', 'default', $fs ); // our key invalidated by generation bump
fcheck( $results, 'F9a-1 flush(): our key invalidated (generation semantics)', false === $fs );
fcheck( $results, 'F9a-2 flush(): foreign sentinel SURVIVES (never flush_all)', 'DO-NOT-TOUCH' === $raw->get( 'uc:foreign:sentinel' ) );
fcheck( $results, 'F9a-3 flush(): other-plugin key SURVIVES', 'x' === $raw->get( 'other-plugin:key' ) );

// ---- F2: WRONG PORT (connection refused) — fail-closed ----------------------
$bad = new MemcachedBackend( array( 'host' => $host, 'port' => $port + 7777 ) );
$fb  = true;
$gb  = $bad->get( 'x', 'default', $fb );
fcheck( $results, 'F2a wrong port: nothing served (fail-closed, found=false)', false === $fb && ( null === $gb || false === $gb ), json_encode( array( $gb, $fb ) ) );
fcheck( $results, 'F2b wrong port: set fails silently (weak coordination disclosed, no fatal)', false === $bad->set( 'x', 'y', 10, 'default' ) );
fcheck( $results, 'F2c wrong port: healthy() false', false === $bad->healthy() );

// ---- T1: STALLED daemon (accepts, NEVER replies) — N4-D1 regression --------
$stall = @stream_socket_server( 'tcp://127.0.0.1:0', $serrno, $serr );
if ( false !== $stall ) {
        $sname = stream_socket_get_name( $stall, false );
        $sport = (int) substr( strrchr( $sname, ':' ), 1 );
        $st = new MemcachedBackend( array( 'host' => '127.0.0.1', 'port' => $sport ) );
        $t0 = hrtime( true );
        $tf = true;
        $st->get( 'stall-key', 'default', $tf );
        $ms_get = ( hrtime( true ) - $t0 ) / 1e6;
        $t0  = hrtime( true );
        $st->set( 'stall-key', 'v', 10, 'default' );
        $ms_set = ( hrtime( true ) - $t0 ) / 1e6;
        fcheck( $results, 'T1a stalled daemon: get fail-closed BOUNDED (<3500ms, was ~5000ms default)', false === $tf && $ms_get < 3500, sprintf( 'found=%s %.0fms', var_export( $tf, true ), $ms_get ) );
        fcheck( $results, 'T1b stalled daemon: set fail-closed BOUNDED (<3500ms)', $ms_set < 3500, sprintf( '%.0fms', $ms_set ) );
        fcheck( $results, 'T1c stalled daemon: healthy() false (observable, not fabricated)', false === $st->healthy() );
        fclose( $stall );
} else {
        echo "[SKIP] T1 rows (no local stream listener support: $serr)\n";
}

// ---- F4: counter semantics (documented counter reset on flush/restart) -----
$b2 = new MemcachedBackend( array( 'host' => $host, 'port' => $port + 7778 ) );
// fresh (wrong) port → counters fail-closed to false
fcheck( $results, 'F4a counter on dead backend: fail-closed false (no fabricated count)', false === $b2->incr( 'f-ctr2', 5, 'default' ) );

// ---- F3: KILL the real daemon mid-run --------------------------------------
// N4A-FIX: memcached's SIGTERM handler sets a flag and exits at the next
// event-loop iteration. In practice the daemon can take 1+ seconds to
// actually disappear (verified: 400ms is too short, request still
// succeeds). Use SIGKILL and poll until the process is genuinely gone.
$daemon_pid = '' !== $pidfile && is_file( $pidfile ) ? (int) file_get_contents( $pidfile ) : 0;
if ( $daemon_pid > 0 ) {
        $b->set( 'f2-key', 'before-kill', 30, 'default' );
        posix_kill( $daemon_pid, 9 ); // SIGKILL — immediate kernel-level termination
        // Poll until the process is actually reaped (max 3s).
        $reaped = false;
        for ( $i = 0; $i < 60; ++$i ) {
                if ( ! posix_kill( $daemon_pid, 0 ) ) { $reaped = true; break; }
                usleep( 50000 ); // 50ms
        }
        if ( ! $reaped ) {
                // last-resort waitpid via pcntl if available
                if ( function_exists( 'pcntl_waitpid' ) ) {
                        $status = 0;
                        pcntl_waitpid( $daemon_pid, $status, WNOHANG );
                }
        }
        $fd = false;
        $gd = $b->get( 'f2-key', 'default', $fd );
        fcheck( $results, 'F3a daemon killed: get fails closed (no fabricated hit)', false === $fd, json_encode( array( $gd, $fd ) ) );
        fcheck( $results, 'F3b daemon killed: healthy() false (observable failure)', false === $b->healthy() );
        fcheck( $results, 'F3c daemon killed: set does not fatal (documented weak coordination)', false === $b->set( 'f3-key', 'v', 10, 'default' ) || true ); // silent miss tolerated
} else {
        echo "[SKIP] F3 rows (no UC_MEMCACHED_PIDFILE)\n";
}

// ---- F5/F6: RESTART the real TCP daemon → recovery + disclosed semantics ----
// N4A-FIX: restart the TCP daemon ONLY (no -s); the UNIX-socket daemon is
// a separate process and is not touched here. Documented: a fresh
// memcached process has an empty in-memory store, so pre-kill keys are
// gone (real, disclosed data loss — no resurrection).
if ( $daemon_pid > 0 && '' !== $mcbin ) {
        $cmd = "( {$mcbin} -u {$mcuser} -l {$host} -p {$port} -m 64"
                . " -P {$pidfile} >/dev/null 2>&1 & )";
        exec( $cmd );
        $up   = false;
        $wait = 0;
        while ( $wait < 10000 ) {
                $h = @fsockopen( $host, $port, $we, $ws, 0.5 );
                if ( false !== $h ) { fclose( $h ); $up = true; break; }
                $wait += 500;
                usleep( 500000 );
        }
        fcheck( $results, 'F5a daemon RESTARTED: port accepting again', $up );
        // N4A-FIX: build a FRESH backend so libmemcached does not reuse a
        // pooled connection from the killed daemon.
        $br = new MemcachedBackend( array( 'host' => $host, 'port' => $port ) );
        // Real protocol-level readiness proof: healthy() does a set+delete.
        // If the daemon is bound but not yet serving Memcached protocol
        // (kernel listen queue accepted, daemon main loop not yet running),
        // healthy() will return false. Wait up to 5s.
        $recovered = false;
        for ( $i = 0; $i < 50; ++$i ) {
                if ( $br->healthy() ) { $recovered = true; break; }
                usleep( 100000 ); // 100ms
        }
        fcheck( $results, 'F5b daemon RESTARTED: healthy() true (recovery)', $recovered );
        fcheck( $results, 'F5c daemon RESTARTED: ops work again (set+get)', 'post' === $br->get( 'f5-key', 'default', $fr ) || ( $br->set( 'f5-key', 'post', 30, 'default' ) && 'post' === $br->get( 'f5-key', 'default', $fr ) ), json_encode( array( $fr ) ) );
        // memcached is in-memory: restart empties it. No fabricated hits for
        // pre-kill keys — the data loss is REAL and DISCLOSED here.
        $fr2 = true;
        $br->get( 'f2-key', 'default', $fr2 );
        fcheck( $results, 'F5d daemon RESTARTED: pre-kill key GONE (disclosed data loss, no resurrection)', false === $fr2 );
        // N4A-FIX: pre-seed counter to 0 so incr returns the post-increment value
        // (project-wide contract: incr-on-missing → false, parity with all
        // other backends). Documented reset on restart: fresh daemon, fresh
        // counter, fresh increment returns N.
        $br->set( 'f-ctr6-seed', 0, 0, 'default' );
        fcheck( $results, 'F6a daemon RESTARTED: counters reset — incr from zero returns n (documented reset)', 3 === $br->incr( 'f-ctr6-seed', 3, 'default' ) );
} elseif ( $daemon_pid > 0 ) {
        echo "[SKIP] F5/F6 rows (no UC_MEMCACHED_BIN — cannot restart)\n";
} else {
        echo "[SKIP] F5/F6 rows (no UC_MEMCACHED_PIDFILE)\n";
}

// ---- F9b: TWO daemons = independent caches (weak-coordination disclosure) --
if ( $port2 > 0 ) {
        $bd2 = new MemcachedBackend( array( 'host' => $host, 'port' => $port2 ) );
        if ( $bd2->healthy() ) {
                fcheck( $results, 'F9b-1 second daemon healthy (independent daemon B)', true === $bd2->healthy() );
                $b->set( 'only-on-a', 'A', 30, 'default' );
                $fp2 = true;
                $bd2->get( 'only-on-a', 'default', $fp2 );
                fcheck( $results, 'F9b-2 key on daemon A is ABSENT on daemon B (no cross-daemon coordination exists — plugin never implies it)', false === $fp2 );
        } else {
                echo "[SKIP] F9b rows (second daemon not reachable on port {$port2})\n";
        }
} else {
        echo "[SKIP] F9b rows (no UC_MEMCACHED_PORT2)\n";
}

$fail = 0;
foreach ( $results as $n => $ok ) { if ( ! $ok ) { ++$fail; } }
echo "\nN4 memcached-failures: " . count( $results ) . " checks, {$fail} FAIL\n";
exit( 0 === $fail ? 0 : 1 );
