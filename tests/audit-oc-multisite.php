<?php
/**
 * AUDIT TEST — Object cache on MULTISITE (Phase J).
 *
 * Cross-site leakage is a RELEASE-BLOCKING class of defect. This suite drives
 * the real multisite API surface (wpmu_create_blog / switch_to_blog /
 * restore_current_blog — the shim proxies wp_cache_switch_to_blog exactly
 * like core's switch_to_blog does) against the Manager with a persistent
 * backend. Run with the Memory backend here; the LIVE stage (J-4) repeats the
 * critical isolation rows against a real Redis and a real Memcached.
 *
 *   N1  blog creation + subdirectory/subdomain registration
 *   N2  scope isolation: same key+group in different blogs stay distinct
 *   N3  switch/restore stack (nested), incl. current_blog_id tracking
 *   N4  global groups shared across blogs; scoped groups not
 *   N5  flushGroup in one blog must not invalidate another blog's same group
 *   N6  flush() still clears everything (core WP behavior)
 *   N7  backend chain failover does not leak across blogs (fault isolation)
 *
 * Run: php tests/audit-oc-multisite.php   (exit 0 only when all checks pass)
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

$results = array();
function ncheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

/**
 * Failing-then-healing backend: simulates a dead primary for chain tests.
 */
class OC_FlakyBackend implements Backend {
        public $up = true;
        public $inner;
        public $fail_for_scope = ''; // degrade only for this scope group ('' = never)
        public function __construct() {
                $this->inner = new MemoryBackend();
        }
        private function shouldFail( $group ) {
                return ! $this->up || ( '' !== $this->fail_for_scope && 0 === strpos( $group, $this->fail_for_scope ) );
        }
        public function get( $key, $group, &$found = null ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->get( $key, $group, $found );
        }
        public function getMultiple( $keys, $group ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->getMultiple( $keys, $group );
        }
        public function set( $key, $value, $ttl, $group ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->set( $key, $value, $ttl, $group );
        }
        public function add( $key, $value, $ttl, $group ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->add( $key, $value, $ttl, $group );
        }
        public function replace( $key, $value, $ttl, $group ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->replace( $key, $value, $ttl, $group );
        }
        public function delete( $key, $group ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->delete( $key, $group );
        }
        public function incr( $key, $n, $group ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->incr( $key, $n, $group );
        }
        public function decr( $key, $n, $group ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->decr( $key, $n, $group );
        }
        public function flushGroup( $group ) {
                if ( $this->shouldFail( $group ) ) {
                        throw new \RuntimeException( 'flaky' );
                }
                return $this->inner->flushGroup( $group );
        }
        public function flush() {
                if ( ! $this->up ) {
                        throw new \RuntimeException( 'flaky' );
                }
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
// N1: blog registration (subdirectory REQUIRED form + subdomain form).
// =====================================================================
ncheck( $results, 'N1 default blog is 1', 1 === get_current_blog_id() );
$b2 = wpmu_create_blog( 'example.com', '/shop/', 'Shop', 1 );
$b3 = wpmu_create_blog( 'shop2.example.com', '/', 'Shop2 (subdomain form)', 1 );
ncheck( $results, 'N1 subdirectory blog created (id 2)', 2 === $b2 );
ncheck( $results, 'N1 subdomain blog created (id 3)', 3 === $b3 );
ncheck( $results, 'N1 blogs registered in network', 3 === count( get_sites() ) );

// =====================================================================
// N2: scope isolation — THE release-blocking class.
// =====================================================================
$p = new MemoryBackend(); // persistent slot filled by the reference backend
$mgr = new Manager( $p );
$mgr->addGlobalGroups( 'ms-global' );

$mgr->set( 'site-name', 'Value-Of-Blog-1', 'options' );
$mgr->flushRuntime();

switch_to_blog( 2 ); // core API: updates global blog state + calls wp_cache_switch_to_blog
$mgr->get( 'site-name', 'options', false, $f_b2 );
ncheck( $results, 'N2 blog 2 cannot read blog 1 data (no leakage)', false === $f_b2 );
$mgr->set( 'site-name', 'Value-Of-Blog-2', 'options' );

switch_to_blog( 3 );
$mgr->get( 'site-name', 'options', false, $f_b3 );
ncheck( $results, 'N2 blog 3 cannot read blog 1/2 data', false === $f_b3 );

switch_to_blog( 1 ); // direct switch (not restore) to blog 1
$mgr->flushRuntime();
$mgr->get( 'site-name', 'options', false, $f_b1 );
ncheck( $results, 'N2 blog 1 value intact (distinct backend scopes)', true === $f_b1 && 'Value-Of-Blog-1' === $mgr->get( 'site-name', 'options', false, $fb1b ) );
switch_to_blog( 2 );
$mgr->flushRuntime();
$mgr->get( 'site-name', 'options', false, $fb2v );
ncheck( $results, 'N2 blog 2 value intact and distinct', true === $fb2v && 'Value-Of-Blog-2' === $mgr->get( 'site-name', 'options', false, $fb2b ) );

// =====================================================================
// N3: switch/restore stack (nested) — core-compatible ordering.
// =====================================================================
restore_current_blog(); // back to base (1)
switch_to_blog( 2 );
switch_to_blog( 3 );
ncheck( $results, 'N3 nested switch lands on blog 3', 3 === get_current_blog_id() );
restore_current_blog();
ncheck( $results, 'N3 first restore returns to blog 2', 2 === get_current_blog_id() );
restore_current_blog();
ncheck( $results, 'N3 second restore returns to blog 1 (if stack drained)', 1 === get_current_blog_id() );
while ( restore_current_blog() ) {} // drain any leftover switched scopes (core allows direct switches)
ncheck( $results, 'N3 restore on drained stack returns false at blog 1', false === restore_current_blog() && 1 === get_current_blog_id() );

// =====================================================================
// N4: global groups shared; scoped groups not.
// =====================================================================
while ( restore_current_blog() ) {} // deterministic base blog
$mgr->set( 'net-opt', 'network-value', 'ms-global' );
switch_to_blog( 2 );
$mgr->flushRuntime();
$glob_val = $mgr->get( 'net-opt', 'ms-global', false, $f_glob );
$mgr->get( 'leak-probe', 'options', false, $f_scoped ); // fresh key: never written in ANY blog
ncheck( $results, 'N4 global group visible from blog 2', true === $f_glob && 'network-value' === $glob_val );
ncheck( $results, 'N4 scoped group still isolated after global read', false === $f_scoped );
restore_current_blog();

// =====================================================================
// N5: flushGroup in blog 2 must NOT touch blog 1's same-named group.
// =====================================================================
switch_to_blog( 2 );
$mgr->set( 'g-key', 'blog2-g', 'widgets' );
$mgr->flushRuntime();
$mgr->flushGroup( 'widgets' );
$mgr->get( 'g-key', 'widgets', false, $f_g2gone );
ncheck( $results, 'N5 flushGroup clears blog 2 group', false === $f_g2gone );
restore_current_blog();
$g1_val = $mgr->set( 'g-key', 'blog1-g', 'widgets' ) ? $mgr->get( 'g-key', 'widgets', false, $f_g1 ) : null;
ncheck( $results, 'N5 blog 1 same-named group unaffected', true === $f_g1 && 'blog1-g' === $g1_val );

// =====================================================================
// N6: flush() clears everything across blogs (core WP behavior).
// =====================================================================
$mgr->flush();
$mgr->flushRuntime();
$mgr->get( 'g-key', 'widgets', false, $f_all );
ncheck( $results, 'N6 flush() is network-wide (documented core behavior)', false === $f_all );

// =====================================================================
// N7: chain failover isolation under multisite — a dead primary must not
// leak another blog's data into this blog's reads.
// =====================================================================
while ( restore_current_blog() ) {} // deterministic base blog
$primary = new OC_FlakyBackend();
$sec     = new OC_FlakyBackend();
$mgr     = new Manager( array( $primary, $sec ) );
$mgr->set( 'chain-key', 'primary-1', 'options' ); // written to primary
$mgr->flushRuntime();

$primary->up = false; // primary dies
$mgr->get( 'chain-key', 'options', false, $f_fail );
ncheck( $results, 'N7 dead primary: read misses cleanly (no fatal, no leak)', false === $f_fail );
usleep( 1100000 ); // let the primary's recheck window lapse: healthy() false keeps it degraded
$mgr->set( 'chain-key', 'secondary-1', 'options' ); // failover write → secondary
$mgr->flushRuntime();
$sec_val = $mgr->get( 'chain-key', 'options', false, $f_sec );
ncheck( $results, 'N7 failover write lands on secondary and reads back', true === $f_sec && 'secondary-1' === $sec_val );

// Scoped fault: degrade ONLY blog 3's scope on the primary; blog 1 unaffected.
$primary->up = true;
$primary->fail_for_scope = 'b3::'; // set BEFORE construction: probe_backends() runs there
$mgr2 = new Manager( array( $primary, $sec ) );
$mgr2->set( 'sc-key', 'ok-1', 'options' ); // blog 1 → b1::options (healthy)
$mgr2->switch_blog( 3 ); // Manager-level switch (no drop-in loaded here)
$mgr2->set( 'sc-key', 'from-3', 'options' ); // b3::options fails → fails over to secondary
$mgr2->flushRuntime();
$b3_val = $mgr2->get( 'sc-key', 'options', false, $f_b3sc );
ncheck( $results, 'N7 scope-scoped fault fails over to secondary (isolation)', true === $f_b3sc && 'from-3' === $b3_val );
$mgr2->restore_blog(); // Manager-level restore matching the Manager-level switch
$mgr2->flushRuntime();
usleep( 1100000 ); // scope-fault degraded the primary index for the recheck window; wait it out
// Phase K promotion fencing: before the primary regains service rights it is
// RECONCILED (fully flushed) — blog 1's primary copy is invalidated by the
// fence BY DESIGN (staleness safety over hit-rate). The read must miss
// cleanly, never serve stale data, and never leak blog 3's value.
$b1_val = $mgr2->get( 'sc-key', 'options', false, $f_b1sc );
ncheck( $results, 'N7 after fault: primary reconciled before reuse — no stale, no leak', false === $f_b1sc && 'from-3' !== $b1_val );
ncheck( $results, 'N7 fence counter proves the reconcile happened (Phase K promotion fencing)', $mgr2->stats()['fences'] >= 1 );
ncheck( $results, 'N7 backendHealthy reports chain alive (secondary up)', true === $mgr2->backendHealthy() );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
