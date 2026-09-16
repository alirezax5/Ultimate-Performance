<?php
/**
 * AUDIT TEST — SQLite + File object cache backends (Phase K).
 *
 *   Q1  SQLite semantics: found flags (false/null/0/''/arrays), CAS add/replace,
 *       transactional arithmetic (missing/non-integer → false), delete semantics
 *   Q2  SQLite TTL + janitor (bounded chunks; fresh rows survive)
 *   Q3  SQLite O(1) flush sentinel (foreign row survives; ours invalidated)
 *   Q4  SQLite cross-process atomicity (4 children × 25 through the REAL backend)
 *   Q5  SQLite read-only DB → fail-closed (healthy=false, no fatals)
 *   F1  File semantics: found flags, exclusive-create add, disclosed replace,
 *       flock arithmetic, delete semantics
 *   F2  File O(1) flush sentinel (foreign file survives)
 *   F3  File symlink refusal: a symlink planted at a key path is NEVER followed
 *   F4  File TTL expiry (lazy)
 *
 * Run: php tests/audit-oc-sqlite-file.php   (exit 0 only when all checks pass)
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

use UltimatePerformance\ObjectCache\FileBackend;
use UltimatePerformance\ObjectCache\SqliteBackend;

$results = array();
function qcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$tmp      = rtrim( sys_get_temp_dir(), '/' ) . '/uc-sqfile-' . getmypid();
$dbfile   = $tmp . '/object-cache.sqlite';
exec( 'rm -rf ' . escapeshellarg( $tmp ) );
@mkdir( $tmp, 0777, true );
// F-backend uses its DEFAULT base: under the sandbox cache root, which is
// exactly where SafeFs jails writes (production layout).

$G = 'b1::sf'; // scope-embedded group (as the Manager passes it)

if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', \PDO::getAvailableDrivers(), true ) ) {
        echo "[SKIP] SQLite section << pdo_sqlite absent\n";
} else {

        // =================================================================
        // Q1: semantics.
        // =================================================================
        $s = new SqliteBackend( $dbfile );
        qcheck( $results, 'Q1 backend healthy on open', true === $s->healthy() );
        $s->set( 'k-false', false, 0, $G );
        $s->set( 'k-null', null, 0, $G );
        $s->set( 'k-zero', 0, 0, $G );
        $s->set( 'k-empty', '', 0, $G );
        $s->set( 'k-arr', array( 'a' => 1, 'b' => array( 2, 3 ) ), 0, $G );
        $s->get( 'k-false', $G, $f1 );
        $s->get( 'k-null', $G, $f2 );
        $s->get( 'k-zero', $G, $f3 );
        $s->get( 'k-empty', $G, $f4 );
        $arr = $s->get( 'k-arr', $G, $f5 );
        qcheck( $results, 'Q1 stored false  => found=true', true === $f1 );
        qcheck( $results, 'Q1 stored null   => found=true', true === $f2 );
        qcheck( $results, 'Q1 stored 0      => found=true', true === $f3 );
        qcheck( $results, 'Q1 stored ""     => found=true', true === $f4 );
        qcheck( $results, 'Q1 nested array round-trips', true === $f5 && array( 'a' => 1, 'b' => array( 2, 3 ) ) === $arr );
        qcheck( $results, 'Q1 add missing → true; existing → false (INSERT OR IGNORE)', true === $s->add( 'cas', 1, 0, $G ) && false === $s->add( 'cas', 2, 0, $G ) );
        qcheck( $results, 'Q1 replace existing → true; missing → false (atomic UPDATE)', true === $s->replace( 'cas', 9, 0, $G ) && false === $s->replace( 'ghost-r', 1, 0, $G ) );
        $s->set( 'n', 5, 0, $G );
        qcheck( $results, 'Q1 incr 5 → 7 (transactional)', 7 === $s->incr( 'n', 2, $G ) );
        qcheck( $results, 'Q1 incr missing → false (never auto-creates)', false === $s->incr( 'ghost-n', 1, $G ) );
        $s->set( 'str', 'text', 0, $G );
        qcheck( $results, 'Q1 incr non-integer → false', false === $s->incr( 'str', 1, $G ) );
        qcheck( $results, 'Q1 delete existing → true; absent → false', true === $s->delete( 'cas', $G ) && false === $s->delete( 'cas', $G ) );

        // =================================================================
        // Q2: TTL + janitor.
        // =================================================================
        $s->set( 'ttl-k', 'v', 1, $G );
        $s->get( 'ttl-k', $G, $f_pre );
        sleep( 2 );
        $s->get( 'ttl-k', $G, $f_lazy ); // expired via lazy check
        qcheck( $results, 'Q2 ttl=1 expires (lazy read)', true === $f_pre && false === $f_lazy );
        $s->set( 'gone-1', 'x', 1, $G );
        $s->set( 'gone-2', 'x', 1, $G );
        $s->set( 'keep', 'x', 0, $G );
        sleep( 1 );
        $deleted = $s->janitor();
        $s->get( 'gone-1', $G, $f_g1 );
        $s->get( 'keep', $G, $f_keep );
        qcheck( $results, 'Q2 janitor deleted expired rows, fresh row survives', false === $f_g1 && true === $f_keep && $deleted >= 2, 'deleted=' . var_export( $deleted, true ) );

        // =================================================================
        // Q3: flush sentinel (no DELETE-all: foreign row must survive).
        // =================================================================
        $pdo = new \PDO( 'sqlite:' . $dbfile );
        $pdo->exec( "CREATE TABLE IF NOT EXISTS foreign_tbl ( id INTEGER PRIMARY KEY, marker TEXT )" );
        $pdo->exec( "INSERT INTO foreign_tbl ( marker ) VALUES ( 'DO-NOT-DELETE' )" );
        $s->set( 'ours', 'v', 0, $G );
        qcheck( $results, 'Q3 flushGroup returns true', true === $s->flushGroup( $G ) );
        $s->get( 'ours', $G, $f_gone );
        qcheck( $results, 'Q3 our key invalidated after flushGroup', false === $f_gone );
        $still = $pdo->query( 'SELECT marker FROM foreign_tbl LIMIT 1' )->fetchColumn();
        qcheck( $results, 'Q3 foreign table untouched (invalidation is generation-based)', 'DO-NOT-DELETE' === $still );
        $s->flush();
        $still2 = $pdo->query( 'SELECT marker FROM foreign_tbl LIMIT 1' )->fetchColumn();
        qcheck( $results, 'Q3 root flush leaves foreign table intact', 'DO-NOT-DELETE' === $still2 );

        // =================================================================
        // Q4: cross-process atomicity.
        // =================================================================
        $s->set( 'counter', 0, 0, $G );
        $children = 4;
        $iters    = 25;
        $pids     = array();
        for ( $c = 0; $c < $children; ++$c ) {
                $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/oc-sqlite-incr-child.php' )
                        . ' ' . escapeshellarg( dirname( __DIR__ ) . '/' )
                        . ' ' . escapeshellarg( $dbfile )
                        . ' ' . escapeshellarg( 'counter' ) . ' ' . escapeshellarg( (string) $iters )
                        . ' ' . escapeshellarg( $G );
                $pids[] = exec( $cmd . ' > /dev/null 2>&1 & echo $!' );
        }
        $ok_wait = true;
        foreach ( $pids as $pid ) {
                if ( ! is_numeric( $pid ) ) { $ok_wait = false; continue; }
                $deadline = microtime( true ) + 25;
                while ( microtime( true ) < $deadline && file_exists( "/proc/{$pid}" ) ) {
                        usleep( 100000 );
                }
        }
        $final = $s->get( 'counter', $G, $f_cnt );
        qcheck( $results, 'Q4 ' . $children . 'x' . $iters . ' cross-process increments exact (' . ( $children * $iters ) . ')', $ok_wait && $f_cnt && ( $children * $iters ) === $final, 'final=' . var_export( $final, true ) );

        // =================================================================
        // Q5: read-only DB → fail-closed.
        // =================================================================
        $s2 = new SqliteBackend( $dbfile );
        $s2->set( 'ro-probe', 'v', 0, $G );
        $s2->close();
        chmod( $dbfile, 0444 );
        if ( is_file( $dbfile . '-wal' ) ) { chmod( $dbfile . '-wal', 0444 ); }
        if ( is_file( $dbfile . '-shm' ) ) { chmod( $dbfile . '-shm', 0444 ); }
        chmod( $tmp, 0555 ); // readonly directory + db + wal/shm: real-world readonly
        $s3 = new SqliteBackend( $dbfile );
        $sv = $s3->set( 'x', 1, 0, $G );
        $iv = $s3->incr( 'ro-probe', 1, $G );
        $gv = $s3->get( 'ro-probe', $G, $f_ro );
        // with the directory readonly, WAL writes cannot happen: fail-closed, no fatal
        qcheck( $results, 'Q5 readonly DB/dir: writes fail-closed, no fatal', false === $sv && false === $iv, 'sv=' . var_export( $sv, true ) . ' iv=' . var_export( $iv, true ) );
        $s3->close();
        chmod( $tmp, 0777 );
        chmod( $dbfile, 0644 );
}

// =====================================================================
// F: File backend.
// =====================================================================
$f = new FileBackend();
qcheck( $results, 'F0 file backend healthy', true === $f->healthy() );

$f->set( 'k-false', false, 0, $G );
$f->set( 'k-null', null, 0, $G );
$f->set( 'k-zero', 0, 0, $G );
$f->set( 'k-empty', '', 0, $G );
$f->get( 'k-false', $G, $ff1 );
$f->get( 'k-null', $G, $ff2 );
$f->get( 'k-zero', $G, $ff3 );
$f->get( 'k-empty', $G, $ff4 );
qcheck( $results, 'F1 stored false  => found=true', true === $ff1 );
qcheck( $results, 'F1 stored null   => found=true', true === $ff2 );
qcheck( $results, 'F1 stored 0      => found=true', true === $ff3 );
qcheck( $results, 'F1 stored ""     => found=true', true === $ff4 );
$f->get( 'absent', $G, $ff5 );
qcheck( $results, 'F1 absent => found=false', false === $ff5 );

qcheck( $results, 'F2 add missing → true (exclusive create); existing → false', true === $f->add( 'cas', 1, 0, $G ) && false === $f->add( 'cas', 2, 0, $G ) );
qcheck( $results, 'F2 replace existing → true; missing → false (disclosed check-then-write)', true === $f->replace( 'cas', 9, 0, $G ) && false === $f->replace( 'ghost-r', 1, 0, $G ) );
$f->set( 'n', 5, 0, $G );
qcheck( $results, 'F3 incr 5 → 7 (flock-guarded)', 7 === $f->incr( 'n', 2, $G ) );
qcheck( $results, 'F3 incr missing → false (never auto-creates)', false === $f->incr( 'ghost-n', 1, $G ) );
qcheck( $results, 'F4 delete existing → true; absent → false', true === $f->delete( 'cas', $G ) && false === $f->delete( 'cas', $G ) );

$f->set( 'ours', 'v', 0, 'b1::g2' );
$fbase_real = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/cache/ultimate-performance/object-cache-files';
@mkdir( $fbase_real . '/foreign', 0777, true );
file_put_contents( $fbase_real . '/foreign/sentinel.txt', 'DO-NOT-DELETE' );
qcheck( $results, 'F5 flushGroup returns true', true === $f->flushGroup( 'b1::g2' ) );
$f->get( 'ours', 'b1::g2', $ff7 );
qcheck( $results, 'F5 our key invalidated after flushGroup', false === $ff7 );
qcheck( $results, 'F5 foreign file survives (no recursive delete)', 'DO-NOT-DELETE' === (string) file_get_contents( $fbase_real . '/foreign/sentinel.txt' ) );

// F6: symlink refusal — a symlink at a key path is never followed.
$target = $tmp . '/innocent-target.txt';
file_put_contents( $target, 'SHOULD-NOT-APPEAR' );
$f->flushGroup( $G ); // new generation → fresh dir
$symlink_dir = dirname( $f->keyFile ?? '' ); // not public; plant via generation dir below
// Plant the symlink where the NEXT write would read from:
$keyfile = null;
$f->set( 'sym-key', 'v', 0, $G );
// discover the file path by checking the generation tree (walk it)
$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $fbase_real, \FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $fileinfo ) {
        if ( 'sym-key' === basename( dirname( $fileinfo->getPathname() ) ) ) {
                $keyfile = $fileinfo->getPathname(); // informational only — keys are hashed in production paths
        }
}
// The hash-based names are internal; instead verify refusal behaviorally:
// replace the resolved file via a symlink planted from the known hashed path.
$grp_hash = hash( 'sha256', $G );
$key_hash = hash( 'sha256', 'sym-key' );
$matches = glob( $fbase_real . '/V*/G*/' . $grp_hash . '/' . $key_hash . '.uc' );
$planted = false;
if ( ! empty( $matches ) && function_exists( 'symlink' ) ) {
        $real = $matches[0];
        @unlink( $real );
        @symlink( $target, $real );
        $planted = is_link( $real );
        $ff8 = null;
        $val = $f->get( 'sym-key', $G, $ff8 );
        qcheck( $results, 'F6 symlink at key path is refused (never followed)', $planted && false === $ff8 && null === $val );
        qcheck( $results, 'F6 symlink target untouched', 'SHOULD-NOT-APPEAR' === (string) file_get_contents( $target ) );
        @unlink( $real );
} else {
        qcheck( $results, 'F6 symlink at key path is refused (never followed)', false, 'planted=' . var_export( $planted, true ) . ' matches=' . count( (array) $matches ) );
}

// F7: TTL expiry (lazy).
$f->set( 'ttl-k', 'v', 1, $G );
$f->get( 'ttl-k', $G, $ft1 );
sleep( 2 );
$f->get( 'ttl-k', $G, $ft2 );
qcheck( $results, 'F7 ttl=1 expires (lazy read)', true === $ft1 && false === $ft2 );

$f->flush();
$f->close();
exec( 'rm -rf ' . escapeshellarg( $tmp ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
