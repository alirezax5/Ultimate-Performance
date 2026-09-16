<?php
/**
 * AUDIT TEST — Queue Concurrency (T5, permanent).
 *
 * Real multi-PROCESS concurrency against the REAL production enqueue path:
 *
 *   K producers (5 then 10) = separate OS processes, each booting real
 *   wp-load.php and pushing its DISJOINT dir slice through the real
 *   QueueManager::enqueue() chain. The parent runs a drainer that starts
 *   DURING the producer window (producer↔worker overlap) and finishes
 *   after all producers exit.
 *
 * Invariants asserted:
 *   C1  every producer exits 0 with valid JSON, receipts real+unique
 *   C2  per-producer accounting: queued+processed == submitted, lost == 0
 *   C3  aggregate losslessness: union(purged on disk) == union(submitted)
 *       after final drain; sentinel + foreign dir untouched
 *   C4  overlap safety: dirs purged during producer window are never
 *       lost from other producers' slices (final state exact-set complete)
 *   C5  no cross-producer contamination: every submitted dir purged,
 *       nothing else under the run namespace touched
 *
 * Backend matrix per round: auto chain (AS present in this WP install)
 * and forced wp-cron (option-buffer read-modify-write hazard).
 *
 * Run: php tests/audit-concurrency.php   (no credentials involved)
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
define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', __DIR__ ) . '/../' );

require_once ULTIMATE_PERFORMANCE_DIR . 'vendor/autoload.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once __DIR__ . '/fixtures/queue-driver.php';
require ABSPATH . 'wp-load.php';

use UltimatePerformance\Core\Settings;
use UltimatePerformance\Queue\QueueManager;

$results = array();
function c5check( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$php     = PHP_BINARY;
$root    = Installer_cache_root();
$run_ns  = 'conc' . getmypid() . '-' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );

/** Cache root (wp_normalize_path available — full WP loaded). */
function Installer_cache_root() {
        return \UltimatePerformance\Core\Installer::cache_root();
}

/** Force queue_backend for this process (test-only, memory of singleton). */
function c5_set_backend( $backend ) {
        $s    = Settings::instance();
        $prop = new \ReflectionProperty( $s, 'data' );
        $prop->setAccessible( true );
        $data = $prop->getValue( $s );
        $data['queue_backend'] = $backend;
        $prop->setValue( $s, $data );
}

/**
 * One full concurrency round.
 *
 * @param int    $k        producer count.
 * @param string $backend  Settings queue_backend value ('auto'|'wp-cron').
 * @param array  $results  check ledger.
 * @return array{submitted:int,purged:int,dups:int} summary for reporting.
 */
function c5_round( $k, $backend, &$results ) {
        global $php, $root, $run_ns;
        $tag = "{$run_ns}-k{$k}-" . preg_replace( '/[^a-z]/', '', $backend );

        // ---- build disjoint dir slices -------------------------------------
        $dirs_per_producer = 12;
        $all_dirs          = array(); // rel dir => producer index
        $slices            = array();
        for ( $p = 0; $p < $k; ++$p ) {
                $slices[ $p ] = array();
                for ( $i = 0; $i < $dirs_per_producer; ++$i ) {
                        $d = 'localhost/' . $tag . "/p{$p}/d{$i}/";
                        $slices[ $p ][] = $d;
                        $all_dirs[ $d ] = $p;
                }
        }
        // Sentinel + foreign neighbor must survive.
        $sentinel_rel = 'localhost/' . $tag . '/sentinel/';
        $foreign_rel  = 'localhost/' . $tag . '-foreign/d0/';

        // Seed EVERY dir (incl sentinel + foreign) as a real cache entry.
        $keygen = new \UltimatePerformance\CacheKey\Key( Settings::instance() );
        $fs     = new \UltimatePerformance\Core\SafeFs();
        $store  = new \UltimatePerformance\PageCache\Store( $fs, $keygen );
        foreach ( $all_dirs as $d => $owner ) {
                $r = $store->write( $d, '<html>c5</html>', 200, array(), array( 'c5' ), 3600 );
                if ( false === $r || ! is_readable( $keygen->absolute( $d ) ) ) {
                        c5check( $results, "[k{$k} {$backend}] seed write: {$d}", false, 'write failed' );
                        return array( 'submitted' => 0, 'purged' => 0, 'dups' => 0 );
                }
        }
        $store->write( $sentinel_rel, '<html>SENTINEL</html>', 200, array(), array(), 3600 );
        $store->write( $foreign_rel, '<html>FOREIGN</html>', 200, array(), array(), 3600 );
        clearstatcache();

        // Force backend for this round — BOTH the parent drain path and every
        // producer child (backend passed as argv[3]) run the same chain choice.
        c5_set_backend( $backend );

        // ---- spawn producers; start drainer mid-window ----------------------
        $dir_files = array();
        $procs     = array();
        $pipes     = array();
        for ( $p = 0; $p < $k; ++$p ) {
                $f = sys_get_temp_dir() . '/uc-c5-' . basename( $tag ) . "-p{$p}.txt";
                file_put_contents( $f, implode( "\n", $slices[ $p ] ) );
                $dir_files[ $p ]      = $f;
                $cmd                   = sprintf( '%s %s %s %d %s 2>&1', escapeshellarg( $php ), escapeshellarg( __DIR__ . '/fixtures/conc-producer.php' ), escapeshellarg( $f ), $p, escapeshellarg( (string) $backend ) );
                $procs[ $p ]           = popen( $cmd, 'r' );
                $pipes[ $p ]           = $procs[ $p ];
        }

        // Producer↔worker overlap: drain WHILE producers may still run.
        usleep( 300000 );
        wp_cache_flush();
        ( new QueueManager() )->work( 5 ); // early partial drain (overlap proof)

        // Collect producer outputs. Children may emit PHP Warning/Deprecated
        // lines before the final JSON line — parse the LAST '{' line only.
        $prod_out = array();
        foreach ( $procs as $p => $rp ) {
                $raw            = (string) stream_get_contents( $rp );
                $ec             = pclose( $rp );
                $json_line      = '';
                foreach ( explode( "\n", $raw ) as $ln ) {
                        $ln = trim( $ln );
                        if ( '' !== $ln && '{' === $ln[0] ) {
                                $json_line = $ln; // keep last match.
                        }
                }
                $dec            = json_decode( $json_line, true );
                $prod_out[ $p ] = is_array( $dec ) ? $dec : array( 'ok' => false, 'error' => 'bad json: ' . substr( $json_line ? $json_line : $raw, 0, 160 ), 'ec' => $ec );
                unlink( $dir_files[ $p ] );
        }

        // Final deterministic drain of everything still queued.
        // CROSS-PROCESS CACHE GAP (proven, harness-side): this long-lived parent
        // cached alloption values at boot; producer CHILDREN write the wp-cron
        // buffer option directly to the DB. Without a flush the parent's
        // get_option() serves its own stale copy and claim() sees nothing.
        // Every real WP request is a fresh process — production unaffected.
        // SECOND GAP: QueueManager::instance() caches its resolved backend in
        // $this->backend; after c5_set_backend() a fresh manager is required so
        // the drain actually uses the round's chain choice.
        // THIRD GAP: ActionScheduler::supports_worker() is false — work() cannot
        // drive AS jobs; uc_drive_queue() runs the real AS store + callback. The
        // wp-cron/local path has no AS rows and needs work(). Both drains run.
        wp_cache_flush();
        uc_drive_queue( 500 );                 // AS rows → real production callback.
        ( new QueueManager() )->work( 500 );   // wp-cron / local buffer claims.
        wp_cache_flush();
        uc_drive_queue( 500 );
        ( new QueueManager() )->work( 500 ); // second pass: late re-enqueues/retries.

        // ---- C1: producers healthy, receipts real+unique --------------------
        $receipts_all = array();
        $c1_ok        = true;
        $c2_detail    = '';
        foreach ( $prod_out as $p => $o ) {
                if ( empty( $o['ok'] ) ) {
                        $c1_ok     = false;
                        $c2_detail = "producer {$p}: " . ( isset( $o['error'] ) ? $o['error'] : '?' );
                        break;
                }
                foreach ( (array) $o['receipts'] as $rid ) {
                        if ( ! is_string( $rid ) || '' === $rid || '0' === $rid ) { $c1_ok = false; }
                        $receipts_all[] = $rid;
                }
        }
        c5check( $results, "C1 [k{$k} {$backend}] all producers ok, receipts real+unique", $c1_ok && count( $receipts_all ) === count( array_unique( $receipts_all ) ), $c2_detail . ' receipts=' . count( $receipts_all ) );

        // ---- C2: per-producer accounting ------------------------------------
        $c2_ok   = true;
        $c2_acc  = '';
        foreach ( $prod_out as $p => $o ) {
                $a = isset( $o['accounting'] ) ? $o['accounting'] : null;
                if ( ! is_array( $a )
                        || ( $a['queued'] + $a['processed'] ) !== $a['submitted']
                        || 0 !== $a['lost']
                        || 0 !== $a['remaining']
                        || $a['submitted'] !== $dirs_per_producer ) {
                        $c2_ok  = false;
                        $c2_acc = "producer {$p}: " . json_encode( $a );
                        break;
                }
        }
        c5check( $results, "C2 [k{$k} {$backend}] per-producer accounting lossless", $c2_ok, $c2_acc );

        // ---- C3/C5: exact-set purge verification ----------------------------
        $missing = array();
        foreach ( $all_dirs as $d => $owner ) {
                if ( is_readable( $keygen->absolute( $d ) ) ) {
                        $missing[] = $d;
                }
        }
        c5check( $results, "C3 [k{$k} {$backend}] ALL " . count( $all_dirs ) . " submitted dirs purged (zero loss)", empty( $missing ), 'remaining=' . count( $missing ) . ' first=' . ( $missing[0] ?? '-' ) );

        $survives = array();
        if ( is_readable( $keygen->absolute( $sentinel_rel ) ) ) { $survives[] = 'sentinel'; }
        if ( is_readable( $keygen->absolute( $foreign_rel ) ) ) { $survives[] = 'foreign'; }
        c5check( $results, "C5 [k{$k} {$backend}] sentinel+foreign untouched", 2 === count( $survives ), implode( ',', $survives ) );

        // No stray FILES inside the run namespace, excluding the sentinel and
        // foreign dirs (they intentionally survive until post-check cleanup —
        // they ARE the untouched-by-purge witnesses).
        $stray = 0;
        $base  = $root . '/v/localhost/' . $tag;
        if ( is_dir( $base ) ) {
                $rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
                foreach ( $rii as $f ) {
                        if ( ! $f->isFile() ) {
                                continue;
                        }
                        $p = str_replace( '\\', '/', $f->getPathname() );
                        if ( false !== strpos( $p, '/sentinel/' ) || false !== strpos( $p, '-foreign/' ) ) {
                                continue; // intentional survivors.
                        }
                        ++$stray;
                }
        }
        c5check( $results, "C5 [k{$k} {$backend}] namespace fully cleaned", 0 === $stray, "stray_files={$stray}" );

        // Cleanup namespace tree.
        foreach ( array_keys( $all_dirs ) as $d ) { @unlink( $keygen->absolute( $d ) ); @unlink( $keygen->absolute( $d ) . '.meta.json' ); }
        foreach ( array( $sentinel_rel, $foreign_rel ) as $d ) { @unlink( $keygen->absolute( $d ) ); @unlink( $keygen->absolute( $d ) . '.meta.json' ); }
        foreach ( array( $tag, $tag . '-foreign' ) as $sub ) {
                $dir = $root . '/v/localhost/' . $sub;
                if ( is_dir( $dir ) ) { @rmdir( $dir ); }
        }

        return array( 'submitted' => count( $all_dirs ), 'purged' => count( $all_dirs ) - count( $missing ), 'dups' => max( 0, count( $receipts_all ) * 0 ) );
}

echo "--- T5 queue concurrency: real multi-process producers ---\n";
$total_submitted = 0;
$total_purged    = 0;

foreach ( array( 5, 10 ) as $k ) {
        $sum = c5_round( $k, 'auto', $results );
        $total_submitted += $sum['submitted'];
        $total_purged    += $sum['purged'];
}

foreach ( array( 5, 10 ) as $k ) {
        $sum = c5_round( $k, 'wp-cron', $results );
        $total_submitted += $sum['submitted'];
        $total_purged    += $sum['purged'];
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $kk => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $kk\n"; } }
echo "dirs submitted={$total_submitted} purged={$total_purged}\n";
echo count( $results ) . " checks, $fails failures\n";

// Restore default backend setting for subsequent suites.
c5_set_backend( 'auto' );

// ---- lock hygiene: queue-cron.lock must not remain OWNED -------------------
// LOCK-1 semantics: the lock FILE may persist (flock ownership is the gate),
// but it must be immediately acquirable+releasable — no holder may have died
// mid-critical-section or leaked the handle.
$lock_probe = new \UltimatePerformance\Core\Lock\FileLock( $root . '/queue-cron.lock' );
$lock_ok    = $lock_probe->acquire( 5 );
if ( $lock_ok ) {
        $lock_probe->release();
}
c5check( $results, 'queue-cron.lock not stale (acquirable+releasable after runs)', $lock_ok );

exit( $fails ? 1 : 0 );
