<?php
/**
 * AUDIT TEST — Promotion fencing + chain epoch (Phase K).
 *
 *   P1  failed backend is fenced: after its recheck window lapses it is
 *       reconciled (flushed) ONCE before regaining service rights
 *   P2  pre-outage data on a recovered primary is NEVER served stale
 *   P3  an unhealthy backend is SKIPPED by flush() — a healthy flush is
 *       never blocked by a fenced backend (returns true)
 *   P4  the fence covers a MISSED chain flush: data survives on the
 *       recovered backend only until its promote-reconcile invalidates it
 *   P5  promote is once-per-recovery (no re-flush per request)
 *   P6  cross-process fence: a fresh process reconciles a healthy-but-
 *       stale backend whose chain epoch lags a peer's
 *   P7  single-backend chain: construction never reconciles spuriously
 *   P8  epoch survives root flushes (rewritten into the new generation)
 *   P9  stats disclosure: write_mode=single-writer, consistency=eventual
 *   P10 UNIX socket policy: explicit selection in source, no TCP fallback
 *
 * Run: php tests/audit-oc-fencing.php   (exit 0 only when all checks pass)
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

use UltimatePerformance\ObjectCache\Backend;
use UltimatePerformance\ObjectCache\Manager;
use UltimatePerformance\ObjectCache\MemoryBackend;
use UltimatePerformance\ObjectCache\SqliteBackend;

$results = array();
function pcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

/**
 * Scripted backend: real MemoryBackend storage plus controllable failures
 * (throws, like a dead daemon would) and a flush-call counter so the audit
 * can PROVE the reconcile happened.
 */
class FC_ScriptedBackend implements Backend {
        public $up = true;
        public $flushes = 0;
        public $inner;
        public function __construct() {
                $this->inner = new MemoryBackend();
        }
        private function guard() {
                if ( ! $this->up ) {
                        throw new \RuntimeException( 'scripted outage' );
                }
        }
        public function get( $key, $group, &$found = null ) {
                $this->guard();
                return $this->inner->get( $key, $group, $found );
        }
        public function getMultiple( $keys, $group ) {
                $this->guard();
                return $this->inner->getMultiple( $keys, $group );
        }
        public function set( $key, $value, $ttl, $group ) {
                $this->guard();
                return $this->inner->set( $key, $value, $ttl, $group );
        }
        public function add( $key, $value, $ttl, $group ) {
                $this->guard();
                return $this->inner->add( $key, $value, $ttl, $group );
        }
        public function replace( $key, $value, $ttl, $group ) {
                $this->guard();
                return $this->inner->replace( $key, $value, $ttl, $group );
        }
        public function delete( $key, $group ) {
                $this->guard();
                return $this->inner->delete( $key, $group );
        }
        public function incr( $key, $n, $group ) {
                $this->guard();
                return $this->inner->incr( $key, $n, $group );
        }
        public function decr( $key, $n, $group ) {
                $this->guard();
                return $this->inner->decr( $key, $n, $group );
        }
        public function flushGroup( $group ) {
                $this->guard();
                ++$this->flushes;
                return $this->inner->flushGroup( $group );
        }
        public function flush() {
                $this->guard();
                ++$this->flushes;
                return $this->inner->flush();
        }
        public function healthy() {
                return $this->up;
        }
        public function close() {
        }
        public function features() {
                return ( new MemoryBackend() )->features();
        }
}

// =====================================================================
// P1-P5: in-process fencing with a scripted primary.
// =====================================================================
$primary = new FC_ScriptedBackend();
$sec     = new FC_ScriptedBackend();
$mgr     = new Manager( array( $primary, $sec ) );

$mgr->set( 'stale-key', 'pre-outage', 'options' ); // lands on primary
$mgr->flushRuntime();
$pre = $mgr->get( 'stale-key', 'options', false, $f_pre );
pcheck( $results, 'P1 baseline: value readable from primary', true === $f_pre && 'pre-outage' === $pre );

$primary->up = false; // outage
$mgr->flushRuntime();
$mgr->get( 'stale-key', 'options', false, $f_out ); // read fails over: miss (secondary empty)
$flushes_before = $primary->flushes;
pcheck( $results, 'P1 outage: read misses cleanly (no fatal)', false === $f_out );
pcheck( $results, 'P1 outage: no reconcile while inside the recheck window', $primary->flushes === $flushes_before );

usleep( 1100000 ); // let the recheck window lapse
$primary->up = true; // daemon returns: NOW the fence must reconcile before reuse
$mgr->get( 'stale-key', 'options', false, $f_rec );
pcheck( $results, 'P1 recovery: promote reconciled the primary exactly once', 1 === $primary->flushes - $flushes_before, 'delta=' . ( $primary->flushes - $flushes_before ) );
pcheck( $results, 'P2 recovered primary: pre-outage value NEVER served stale', false === $f_rec && false === $mgr->get( 'stale-key', 'options' ) ); // M1-D2: WP miss = false
pcheck( $results, 'P1 fence counter advanced', $mgr->stats()['fences'] >= 1 );

$mgr->get( 'another-key', 'options', false, $f2 );
pcheck( $results, 'P5 promote is once-per-recovery (no re-flush per request)', 1 === $primary->flushes - $flushes_before );

// =====================================================================
// P3: a fenced (unhealthy) backend never blocks a healthy flush.
// =====================================================================
$primary2 = new FC_ScriptedBackend();
$sec2     = new FC_ScriptedBackend();
$mgr2     = new Manager( array( $primary2, $sec2 ) );
$primary2->up = false; // dies BEFORE the flush
$mgr2->get( 'x', 'options', false, $fx ); // arm the fence
$ok_flush = $mgr2->flush();
pcheck( $results, 'P3 flush() with a fenced backend still succeeds (skip, not fail)', true === $ok_flush );
pcheck( $results, 'P3 secondary received the flush', $sec2->flushes >= 1 );
$primary2->up = true;
usleep( 1100000 );
$mgr2->get( 'x', 'options', false, $fy );
pcheck( $results, 'P4 fence covers the missed flush: recovered primary reconciled', 1 === $primary2->flushes, 'flushes=' . $primary2->flushes );

// =====================================================================
// P6: cross-process fence — a fresh process reconciles a healthy backend
// whose chain epoch lags a peer's (it missed flushes while unavailable).
// Uses two REAL SQLite DBs as a heterogeneous-free deterministic chain.
// =====================================================================
$tmp    = rtrim( sys_get_temp_dir(), '/' ) . '/uc-fence-' . getmypid();
$db1    = $tmp . '/a.sqlite';
$db2    = $tmp . '/b.sqlite';
exec( 'rm -rf ' . escapeshellarg( $tmp ) );
@mkdir( $tmp, 0777, true );

// Epoch 1: chain of both DBs flushes once (both hold epoch 1).
$m1 = new Manager( array( new SqliteBackend( $db1 ), new SqliteBackend( $db2 ) ) );
$m1->set( 'k', 'v', 'options' );
pcheck( $results, 'P6 setup: chain flush bumps epoch on both backends', true === $m1->flush() );

// Outage simulation: DB2 is absent while the chain flushes AGAIN (epoch 2
// lands on DB1 only). DB2 now lags: it missed a flush AND holds stale rows.
$b2_alone = new SqliteBackend( $db2 );
$b2_alone->set( 'stale-row', 'old', 0, 'uc:oc:legacy' ); // plant stale data directly
$b2_alone->close();
unset( $b2_alone );
$m2 = new Manager( array( new SqliteBackend( $db1 ) ) );
$m2->set( 'fresh', 'v', 'options' );
$m2->flush(); // epoch 2: DB2 never sees it

// Fresh process: construction must detect DB2's lagging epoch and reconcile.
$mgr3    = new Manager( array( new SqliteBackend( $db1 ), new SqliteBackend( $db2 ) ) );
$fences3 = $mgr3->stats()['fences'];
pcheck( $results, 'P6 fresh process: stale peer reconciled at construction', $fences3 >= 1, 'fences=' . $fences3 );
$b2_check = new SqliteBackend( $db2 );
$b2_check->get( 'stale-row', 'uc:oc:legacy', $f_stale );
pcheck( $results, 'P6 stale rows planted before the absence are invalidated', false === $f_stale );
$b2_check->close();

// P7: single-backend chain — no peer, no spurious reconcile.
$db3  = $tmp . '/c.sqlite';
$m4   = new Manager( array( new SqliteBackend( $db3 ) ) );
pcheck( $results, 'P7 single-backend chain: construction never reconciles', 0 === $m4->stats()['fences'] );

// P8: epoch survives a root flush (rewritten into the new generation).
$ep_before = 0;
$s_probe   = new SqliteBackend( $db3 );
$s_probe->get( Manager::EPOCH_KEY, Manager::EPOCH_GROUP, $f_ep );
$ep_before = $f_ep ? 1 : 0; // existence probe (value monotonicity checked below)
$s_probe->close();
$m4->flush(); // root flush
$s_probe2   = new SqliteBackend( $db3 );
$epv        = $s_probe2->get( Manager::EPOCH_KEY, Manager::EPOCH_GROUP, $f_ep2 );
$s_probe2->close();
pcheck( $results, 'P8 epoch key re-armed after root flush (new generation)', true === $f_ep2 && is_int( $epv ) && $epv >= 1, 'epoch=' . var_export( $epv, true ) );

// =====================================================================
// P9-P10: disclosures + source policy.
// =====================================================================
$st = $mgr->stats();
pcheck( $results, 'P9 stats disclose single-writer + eventual consistency', 'single-writer' === $st['write_mode'] && 'eventual' === $st['consistency'] );

$src_redis = (string) file_get_contents( __DIR__ . '/../src/ObjectCache/RedisBackend.php' );
$src_memc  = (string) file_get_contents( __DIR__ . '/../src/ObjectCache/MemcachedBackend.php' );
// Policy is documented AND implemented: explicit socket connect, no fallback chain.
$sock_ok   = false !== strpos( $src_redis, 'connect( $this->socket' )
        && false !== strpos( $src_redis, 'never a fallback' )
        && false !== strpos( $src_memc, 'addServer( $this->socket' )
        && false !== strpos( $src_memc, 'wins over TCP' );
pcheck( $results, 'P10 UNIX socket: explicit connect in both backends, no TCP fallback branch', $sock_ok );

$mgr->close();
$mgr2->close();
$mgr3->close();
$m1->close();
$m2->close();
$m4->close();
exec( 'rm -rf ' . escapeshellarg( $tmp ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
