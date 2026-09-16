<?php
/**
 * AUDIT TEST — Object cache runtime, Phase I (Memory + Manager semantics).
 *
 * Proves the WordPress-facing semantics layer against the reference Memory
 * backend plus an instrumented probe backend:
 *
 *   - found-flag contract for false / null / 0 / '' stored values
 *   - add/replace compare-and-set, delete WP semantics (true=deleted,false=absent)
 *   - atomic incr/decr (missing → false, non-integer → false, negative steps)
 *   - TTL expiry, get_multiple, group normalization
 *   - global groups vs blog scope isolation (switch_blog / restore_blog stack)
 *   - non-persistent groups never reach the persistent backend
 *   - read-through runtime mirror (force bypasses it)
 *   - fail-closed behavior when the backend throws (no fatals, honest flags)
 *   - truthful wp_cache_supports feature map
 *
 * Run: php tests/audit-object-cache.php   (exit 0 only when all checks pass)
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
function ocheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

/**
 * Probe backend: records every call, forwards to an inner backend.
 */
class OC_ProbeBackend implements Backend {
        public $ops   = array();
        public $inner;
        public $throw_on = array(); // op => true

        public function __construct( $inner = null ) {
                $this->inner = $inner ?? new MemoryBackend();
        }
        private function rec( $op ) {
                $this->ops[] = $op;
                if ( ! empty( $this->throw_on[ $op ] ) ) {
                        throw new \RuntimeException( 'probe failure: ' . $op );
                }
        }
        public function get( $key, $group, &$found = null ) {
                $this->rec( 'get' );
                return $this->inner->get( $key, $group, $found );
        }
        public function getMultiple( $keys, $group ) {
                $this->rec( 'getMultiple' );
                return $this->inner->getMultiple( $keys, $group );
        }
        public function set( $key, $value, $ttl, $group ) {
                $this->rec( 'set' );
                return $this->inner->set( $key, $value, $ttl, $group );
        }
        public function add( $key, $value, $ttl, $group ) {
                $this->rec( 'add' );
                return $this->inner->add( $key, $value, $ttl, $group );
        }
        public function replace( $key, $value, $ttl, $group ) {
                $this->rec( 'replace' );
                return $this->inner->replace( $key, $value, $ttl, $group );
        }
        public function delete( $key, $group ) {
                $this->rec( 'delete' );
                return $this->inner->delete( $key, $group );
        }
        public function incr( $key, $n, $group ) {
                $this->rec( 'incr' );
                return $this->inner->incr( $key, $n, $group );
        }
        public function decr( $key, $n, $group ) {
                $this->rec( 'decr' );
                return $this->inner->decr( $key, $n, $group );
        }
        public function flushGroup( $group ) {
                $this->rec( 'flushGroup' );
                return $this->inner->flushGroup( $group );
        }
        public function flush() {
                $this->rec( 'flush' );
                return $this->inner->flush();
        }
        public function healthy() {
                $this->rec( 'healthy' );
                return true;
        }
        public function close() {
                $this->rec( 'close' );
        }
        public function features() {
                return method_exists( $this->inner, 'features' ) ? $this->inner->features() : array();
        }
        public function countOp( $op ) {
                return count( array_keys( $this->ops, $op, true ) );
        }
}

// =====================================================================
// O1: MemoryBackend reference semantics.
// =====================================================================
$m = new MemoryBackend();

$v = $m->get( 'none', 'g', $f );
// M1-D2 clarification: MemoryBackend::get implements the INTERNAL Backend
// contract — miss = null + $found=false (callers gate on $found, which carries
// the found/absent distinction even for cached false/null values). The
// WP-facing wp_cache_get contract (miss = false, never null) is enforced one
// layer up, at Manager + the generated drop-in — asserted in O1b below.
ocheck( $results, 'O1 miss returns null (internal Backend contract) + found=false', null === $v && false === $f );

// M1-D2 (real-WP matrix): WP-facing miss contract at the Manager layer —
// wp_cache_get must return FALSE on a miss; core checks `false !== $cache`
// and a null miss fataled real-WP term queries (class-wp-term-query.php).
$mgr_wp = new Manager();
$fwp = null;
$vwp = $mgr_wp->get( 'none', 'g', false, $fwp ); // Manager signature: ($key,$group,$force,&$found)
ocheck( $results, 'O1b Manager-level miss returns false (WP contract)', false === $vwp && false === $fwp );

$m->set( 'k-false', false, 0, 'g' );
$m->set( 'k-null', null, 0, 'g' );
$m->set( 'k-zero', 0, 0, 'g' );
$m->set( 'k-empty', '', 0, 'g' );
$m->get( 'k-false', 'g', $f1 );
$m->get( 'k-null', 'g', $f2 );
$m->get( 'k-zero', 'g', $f3 );
$m->get( 'k-empty', 'g', $f4 );
ocheck( $results, 'O1 stored false  => found=true', true === $f1 );
ocheck( $results, 'O1 stored null   => found=true', true === $f2 );
ocheck( $results, 'O1 stored 0      => found=true', true === $f3 );
ocheck( $results, 'O1 stored ""     => found=true', true === $f4 );

ocheck( $results, 'O1 add on missing → true; on existing → false', true === $m->add( 'cas', 1, 0, 'g' ) && false === $m->add( 'cas', 2, 0, 'g' ) );
ocheck( $results, 'O1 replace on existing → true; value updated', true === $m->replace( 'cas', 9, 0, 'g' ) && 9 === $m->get( 'cas', 'g', $fx ) && $fx );
ocheck( $results, 'O1 replace on missing → false', false === $m->replace( 'nope', 1, 0, 'g' ) );
ocheck( $results, 'O1 delete existing → true; absent → false', true === $m->delete( 'cas', 'g' ) && false === $m->delete( 'cas', 'g' ) );

$m->set( 'n', 5, 0, 'g' );
ocheck( $results, 'O1 incr 5 → 7', 7 === $m->incr( 'n', 2, 'g' ) );
ocheck( $results, 'O1 decr 7 → 2 (and below 0 allowed)', 2 === $m->decr( 'n', 5, 'g' ) && -1 === $m->decr( 'n', 3, 'g' ) );
ocheck( $results, 'O1 incr missing → false (never auto-creates)', false === $m->incr( 'ghost', 1, 'g' ) );
$m->set( 's', 'str', 0, 'g' );
ocheck( $results, 'O1 incr non-integer → false', false === $m->incr( 's', 1, 'g' ) );

$mm = new MemoryBackend();
$mm->set( 'a', 1, 0, 'g1' );
$mm->set( 'b', 2, 0, 'g1' );
$mm->set( 'c', 3, 0, 'g1' );
$res = $mm->getMultiple( array( 'a', 'b', 'zz' ), 'g1' );
ocheck( $results, 'O1 getMultiple found map', true === $res['a']['found'] && true === $res['b']['found'] && false === $res['zz']['found'] && 2 === $res['b']['value'] );

// =====================================================================
// O2: TTL expiry (real short sleep — the only sleep in this suite).
// =====================================================================
$mt = new MemoryBackend();
$mt->set( 't', 'x', 1, 'g' );
$mt->get( 't', 'g', $tf );
$ok_pre = ( true === $tf );
sleep( 1 );
$mt->get( 't', 'g', $tf2 );
ocheck( $results, 'O2 ttl=1 expires after sleep(1)', $ok_pre && false === $tf2 );
$mt->set( 'p', 'x', 0, 'g' );
$mt->get( 'p', 'g', $pf );
ocheck( $results, 'O2 ttl=0 is persistent', true === $pf );

// =====================================================================
// O3: Manager + probe — group routing, mirror, force, non-persistent.
// =====================================================================
$probe   = new OC_ProbeBackend();
$mgr     = new Manager( $probe );
$probe->ops = array(); // construction probes healthy()

$mgr->set( 'k', 'v', 'options' );
$g1 = $mgr->get( 'k', 'options', false, $gf );
$before = $probe->countOp( 'get' );
$mgr->get( 'k', 'options', false, $gf2 );  // served by runtime mirror
$after  = $probe->countOp( 'get' );
ocheck( $results, 'O3 set+get via mirror: second get does NOT hit backend', true === $gf && 'v' === $g1 && $after === $before );
$mgr->get( 'k', 'options', true, $gf3 );   // force bypasses mirror
ocheck( $results, 'O3 force=true hits backend (mirror bypassed)', ( $before + 1 ) === $probe->countOp( 'get' ) && true === $gf3 );

$mgr->delete( 'k', 'options' );
$mgr->get( 'k', 'options', false, $gf4 );
ocheck( $results, 'O3 delete invalidates mirror + backend', false === $gf4 );

$mgr->set( 'np', 'x', 'counts' );
$sets_before = $probe->countOp( 'set' );
$mgr->set( 'np2', 'y', 'counts' );
$mgr->get( 'np2', 'counts', false, $nf );
ocheck( $results, 'O3 non-persistent group never touches backend', true === $nf && $sets_before === $probe->countOp( 'set' ) );

$mgr->set( 'multi-a', 1, 'grp' );
$mgr->set( 'multi-b', 2, 'grp' );
$multi = $mgr->getMultiple( array( 'multi-a', 'multi-b', 'multi-z' ), 'grp' );
// M1-D2: WP get_multiple shape — every requested key present, missing = false.
ocheck( $results, 'O3 get_multiple WP shape (missing present as false)', 1 === $multi['multi-a'] && 2 === $multi['multi-b'] && array_key_exists( 'multi-z', $multi ) && false === $multi['multi-z'] );

// CAS through the manager (WP add/replace semantics)
ocheck( $results, 'O3 manager add existing → false', false === $mgr->add( 'multi-a', 9, 'grp' ) );
ocheck( $results, 'O3 manager replace mirrored → true', true === $mgr->replace( 'multi-a', 9, 'grp' ) );
$mgr->flushRuntime();
$mgr->get( 'multi-a', 'grp', false, $rf );
ocheck( $results, 'O3 flush_runtime clears mirror; read-through refills from backend', true === $rf && 9 === $mgr->get( 'multi-a', 'grp', false, $rf2 ) && true === $rf2 );

// =====================================================================
// O4: blog scope isolation + global groups (WP multisite semantics).
// =====================================================================
$p2  = new OC_ProbeBackend();
$mgr = new Manager( $p2 );
$mgr->addGlobalGroups( 'my-global' );

$mgr->set( 'site-key', 'blog1', 'options' );   // blog-scoped (current blog)
$mgr->set( 'glob-key', 'shared', 'my-global' ); // global group

$mgr->switch_blog( 2 );
$mgr->get( 'site-key', 'options', false, $f_b2 );
$mgr->get( 'glob-key', 'my-global', false, $f_glob );
ocheck( $results, 'O4 blog switch isolates scoped group (miss in blog 2)', false === $f_b2 );
ocheck( $results, 'O4 global group survives blog switch', true === $f_glob && 'shared' === $mgr->get( 'glob-key', 'my-global', false, $fg2 ) );

$mgr->set( 'site-key', 'blog2', 'options' );   // write in blog 2
$mgr->restore_blog();
$mgr->get( 'site-key', 'options', false, $f_back );
ocheck( $results, 'O4 restore returns to blog 1 view (blog2 write invisible)', true === $f_back && 'blog1' === $mgr->get( 'site-key', 'options', false, $fb3 ) );

$mgr->flushGroup( 'options' );
$mgr->get( 'site-key', 'options', false, $f_flushed );
$mgr->get( 'glob-key', 'my-global', false, $f_glob2 );
ocheck( $results, 'O4 flush_group clears the scoped group only', false === $f_flushed && true === $f_glob2 );

$mgr->flush();
$mgr->switch_blog( 2 );
$mgr->get( 'glob-key', 'my-global', false, $f_gone );
ocheck( $results, 'O4 flush() clears everything incl. global groups', false === $f_gone );

// =====================================================================
// O5: fail-closed under a throwing backend (no fatals, honest flags).
// =====================================================================
class OC_ThrowBackend implements Backend {
        private $mem;
        public function __construct() {
                $this->mem = new MemoryBackend();
        }
        public function healthy() {
                return false;
        }
        public function set( $key, $value, $ttl, $group ) {
                throw new \RuntimeException( 'write failed' );
        }
        public function get( $key, $group, &$found = null ) {
                throw new \RuntimeException( 'read failed' );
        }
        public function incr( $key, $n, $group ) {
                throw new \RuntimeException( 'incr failed' );
        }
        public function getMultiple( $keys, $group ) {
                throw new \RuntimeException( 'read failed' );
        }
        public function add( $key, $value, $ttl, $group ) {
                throw new \RuntimeException( 'write failed' );
        }
        public function replace( $key, $value, $ttl, $group ) {
                throw new \RuntimeException( 'write failed' );
        }
        public function delete( $key, $group ) {
                throw new \RuntimeException( 'delete failed' );
        }
        public function decr( $key, $n, $group ) {
                throw new \RuntimeException( 'decr failed' );
        }
        public function flushGroup( $group ) {
                throw new \RuntimeException( 'flush failed' );
        }
        public function flush() {
                throw new \RuntimeException( 'flush failed' );
        }
        public function close() {
        }
}
$mgr = new Manager( new OC_ThrowBackend() );
$gv  = $mgr->get( 'x', 'g', false, $gf5 );
$sv  = $mgr->set( 'x', 1, 'g' );
$iv  = $mgr->incr( 'x', 1, 'g' );
ocheck( $results, 'O5 throwing backend: get miss + set false + incr false', false === $gv && false === $gf5 && false === $sv && false === $iv ); // M1-D2
ocheck( $results, 'O5 backendHealthy() false (fail-closed, reported)', false === $mgr->backendHealthy() );
// non-persistent groups keep working while the persistent backend is down:
$mgr->set( 'local', 'ok', 'counts' );
$mgr->get( 'local', 'counts', false, $lf );
ocheck( $results, 'O5 non-persistent groups work while backend down', true === $lf && 'ok' === $mgr->get( 'local', 'counts', false, $lf2 ) );

// =====================================================================
// O6: wp_cache_supports truthfulness (Manager feature map).
// =====================================================================
$mgr = new Manager( new OC_ProbeBackend() );
$supports = array( 'add_multiple', 'set_multiple', 'get_multiple', 'flush_runtime', 'flush_group', 'incr', 'decr', 'group' );
$all = true;
foreach ( $supports as $feat ) {
        $all = $all && $mgr->supports( $feat );
}
ocheck( $results, 'O6 Memory-backed manager supports all 8 WP features', $all );
ocheck( $results, 'O6 unknown feature → false', false === $mgr->supports( 'nonexistent-feature' ) );

// =====================================================================
// O7: group normalization + key validation.
// =====================================================================
$mgr->set( 'nk', 'v', '' ); // empty group → default
$mgr->get( 'nk', 'default', false, $nf2 );
ocheck( $results, 'O7 empty group normalizes to default', true === $nf2 && 'v' === $mgr->get( 'nk', 'default', false, $nf3 ) );
$mgr->get( '', 'g', false, $ef );
ocheck( $results, 'O7 empty key rejected (set false, get miss)', false === $mgr->set( '', 'v', 'g' ) && false === $ef );

// =====================================================================
// D: object-cache.php drop-in ownership state machine + generated file.
// =====================================================================
use UltimatePerformance\ObjectCache\Dropin;

$sandbox = rtrim( sys_get_temp_dir(), '/' ) . '/uc-oc-dropin-' . getmypid();
if ( is_dir( $sandbox ) ) {
        foreach ( glob( $sandbox . '/*' ) ?: array() as $sf ) { @unlink( $sf ); }
        @rmdir( $sandbox );
}
@mkdir( $sandbox, 0777, true );
$dl  = new Dropin( dirname( __DIR__ ) . '/' ); // real plugin dir
$st1 = $dl->state( $sandbox );
ocheck( $results, 'D1 fresh sandbox: state=absent', 'absent' === $st1 );

$ens1 = $dl->ensure( $sandbox );
ocheck( $results, 'D2 ensure on absent installs ours', 'ours' === $ens1['state'] && 'installed' === $ens1['action'] && file_exists( $sandbox . '/object-cache.php' ) );
// M1 (real-WP matrix): DROPIN_VERSION bumped 1→2 — the generated drop-in now
// defines the COMPLETE wp_cache_* surface incl. wp_cache_init() (WordPress ≥6.x
// skips its own cache.php only when wp_cache_init exists; core declarations are
// unguarded, so the old partial surface fataled on every real-WP boot).
ocheck( $results, 'D3 marker proves ownership + version readable', 'ours' === $dl->state( $sandbox ) && '2' === $dl->installed_version( $sandbox ) );

$mtime_before = filemtime( $sandbox . '/object-cache.php' );
sleep( 0 );
$ens2 = $dl->ensure( $sandbox );
clearstatcache();
$mtime_after = filemtime( $sandbox . '/object-cache.php' );
ocheck( $results, 'D4 ensure idempotent (identical content NOT rewritten)', 'ours' === $ens2['state'] && 'none' === $ens2['action'] && $mtime_after === $mtime_before );

file_put_contents( $sandbox . '/object-cache.php', "<?php // Ultimate Performance object cache drop-in v2 (generated - do not edit; ownership marker)\n// stale content" );
$ens3 = $dl->ensure( $sandbox );
ocheck( $results, 'D5 ensure on stale ours atomically updates', 'updated' === $ens3['action'] && false !== strpos( (string) file_get_contents( $sandbox . '/object-cache.php' ), 'UP_Object_Cache_Fallback' ) );

file_put_contents( $sandbox . '/object-cache.php', "<?php\n// FOREIGN drop-in (some other plugin)\necho 'foreign';\n" );
$foreign_bytes = md5_file( $sandbox . '/object-cache.php' );
$ens4 = $dl->ensure( $sandbox );
ocheck( $results, 'D6 foreign drop-in REFUSED byte-identical', 'refused-foreign' === $ens4['action'] && $foreign_bytes === md5_file( $sandbox . '/object-cache.php' ) );
ocheck( $results, 'D7 remove() on foreign → false, file intact', false === $dl->remove( $sandbox ) && file_exists( $sandbox . '/object-cache.php' ) );

// Reinstall OUR drop-in (D6 left the foreign file in place on purpose).
@unlink( $sandbox . '/object-cache.php' );
$dl->ensure( $sandbox );

// Functional child-process checks of the GENERATED file (never mocked):
// child A = plugin ABSENT → fallback functions; child B = plugin present → Manager semantics.
$child_tpl = '<?php
define( "ABSPATH", %s );
define( "WP_CONTENT_DIR", ABSPATH );
%s
require ABSPATH . "object-cache.php";
wp_cache_set( "x", false, "g" );
$f = null;
$v = wp_cache_get( "x", "g", false, $f );
echo ( true === $f && false === $v && true === wp_cache_supports( "group" ) ) ? "FN-OK" : "FN-BAD";
wp_cache_set( "n", 5, "g" );
echo ( 6 === wp_cache_incr( "n", 1, "g" ) ) ? "|INCR-OK" : "|INCR-BAD";
echo ( true === wp_cache_flush_group( "g" ) && false === wp_cache_get( "n", "g", false, $f2 ) && false === $f2 ) ? "|FLUSH-OK" : "|FLUSH-BAD"; // M1-D2: miss = false
';
$plugin_dir_real = dirname( __DIR__ ) . '/';

// child A: NO plugin resolvable → fallback must keep WP working
$child_a = sprintf( $child_tpl,
        var_export( $sandbox . '/', true ),
        ''
);
file_put_contents( $sandbox . '/child-a.php', $child_a );
$out_a = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $sandbox . '/child-a.php' ) . ' 2>&1' );
ocheck( $results, 'D8 generated drop-in functional WITHOUT plugin (fallback path)', false !== strpos( (string) $out_a, 'FN-OK|INCR-OK|FLUSH-OK' ), substr( (string) $out_a, 0, 90 ) );

// child B: plugin present (ULTIMATE_PERFORMANCE_DIR defined first) → real Manager
$child_b = sprintf( $child_tpl,
        var_export( $sandbox . '/', true ),
        'define( "ULTIMATE_PERFORMANCE_DIR", ' . var_export( $plugin_dir_real, true ) . ' );'
);
file_put_contents( $sandbox . '/child-b.php', $child_b );
$out_b = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $sandbox . '/child-b.php' ) . ' 2>&1' );
ocheck( $results, 'D9 generated drop-in functional WITH plugin (Manager path)', false !== strpos( (string) $out_b, 'FN-OK|INCR-OK|FLUSH-OK' ), substr( (string) $out_b, 0, 90 ) );

// restore-then-remove on OURS works and leaves nothing behind
$dl->ensure( $sandbox );
ocheck( $results, 'D10 remove() on ours deletes drop-in', true === $dl->remove( $sandbox ) && ! file_exists( $sandbox . '/object-cache.php' ) );
foreach ( glob( $sandbox . '/*' ) ?: array() as $sf ) { @unlink( $sf ); }
@rmdir( $sandbox );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
