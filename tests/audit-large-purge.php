<?php
/**
 * AUDIT TEST — Large Live-Disk Purge Matrix (Phase H, permanent).
 *
 * Proves the REAL production invalidation pipeline stays lossless and
 * filesystem-exact for workloads 60/120/200/363/364/950/5000:
 *
 *   Registry reverse index → Hooks::purge_tags() (real trigger)
 *     → QueueManager::enqueue (byte-aware chunking, backend chain)
 *     → real Action Scheduler actions driven by the shared queue driver
 *     → Handlers::run_purge_dirs → SafeFs → disk
 *
 * For every workload N:
 *   - seeds N REAL cache entries under a per-run getmypid() namespace
 *     (incl. localhost/(root) and nested paths) via Store::write
 *   - records the exact TARGET_SET + SENTINEL_SET before invalidation
 *   - attaches targets to one purge tag; sentinel gets its own tag
 *   - triggers Hooks::purge_tags( tag ) — production entry point
 *   - drains pending AS actions through tests/fixtures/queue-driver.php
 *   - verifies exact-set equality on DISK (not counters):
 *       REMAINING_TARGET_SET = ∅
 *       SENTINEL_SET ⊆ REMAINING_SENTINEL_SET
 *       UNRELATED_DELETED_SET = ∅
 *   - verifies accounting invariant submitted=queued+processed+failed+remaining,
 *     failed=0, remaining=0, lost=0
 *   - verifies every chunk's wire payload ≤ CHUNK_BYTES by re-running the
 *     REAL QueueManager::chunk_dirs over the workload
 *
 * Critical sizes 363/364/950/5000 run twice with fresh namespaces.
 *
 * Run: php tests/audit-large-purge.php   (exit 0 only when all checks pass)
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once __DIR__ . '/fixtures/queue-driver.php';
require ABSPATH . 'wp-load.php';

use UltimatePerformance\CacheInvalidation\Hooks;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\CacheTag\Registry;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\Queue\QueueManager;

$results = array();
function lpcheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$settings = Settings::instance();
$keygen   = new Key( $settings );
$fs       = new SafeFs();
$store    = new Store( $fs, $keygen );
$registry = new Registry( $fs );
$hooks    = new Hooks(); // production wiring: builds its own collaborators.
$root_abs = Installer::cache_root() . '/v';

/**
 * Build N realistic rel dirs under this run's namespace.
 * Entry 0 is always localhost/(root); then nested normal paths.
 */
function lp_dirs( $n, $ns ) {
	global $keygen;
	$out = array();
	$rb  = $keygen->build( 'http', 'localhost', '/', '', array() );
	$out[] = is_array( $rb ) ? $rb['dir'] : '(root)';
	for ( $i = 1; $i < $n; ++$i ) {
		$depth = 1 + ( $i % 3 ); // nested paths up to 3 levels.
		$seg   = $ns . '/d' . $i;
		for ( $d = 1; $d < $depth; ++$d ) {
			$seg .= '/lvl' . $d . '_' . $i;
		}
		$b     = $keygen->build( 'http', 'localhost', '/' . $seg . '/', '', array() );
		$out[] = is_array( $b ) ? $b['dir'] : $seg;
	}
	return $out;
}

/** Recursively delete a directory tree (test-owned namespace only). */
function lp_rrmdir( $dir ) {
	if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	foreach ( (array) $items as $it ) {
		if ( '.' === $it || '..' === $it ) {
			continue;
		}
		$p = $dir . '/' . $it;
		if ( is_dir( $p ) && ! is_link( $p ) ) {
			lp_rrmdir( $p );
		} else {
			@unlink( $p );
		}
	}
	@rmdir( $dir );
}

/** Remove empty parent dirs of a rel dir inside the cache root. */
function lp_prune_parents( $abs_dir, $root ) {
	$d = $abs_dir;
	while ( strlen( $d ) > strlen( $root ) ) {
		$parent = dirname( $d );
		if ( strlen( $parent ) <= strlen( $root ) || ! is_dir( $parent ) ) {
			break;
		}
		$left = scandir( $parent );
		$left = array_diff( (array) $left, array( '.', '..' ) );
		if ( ! empty( $left ) ) {
			break;
		}
		@rmdir( $parent );
		$d = $parent;
	}
}

/**
 * One full matrix run for workload size $n.
 */
function lp_run( &$results, $n, $settings, $keygen, $fs, $store, $registry, $hooks, $root_abs ) {
	$run_ns = 'lp' . $n . '-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
	$tag    = 'tag-' . $run_ns;
	echo "\n=== workload n=$n ns=$run_ns ===\n";
	$t0 = microtime( true );

	$dirs      = lp_dirs( $n, $run_ns );
	$sentinel  = null; // rel dir that must survive: own namespace + own tag.
	$sb        = $keygen->build( 'http', 'localhost', '/sent-' . $run_ns . '/keep/', '', array() );
	$sentinel  = is_array( $sb ) ? $sb['dir'] : 'sent-' . $run_ns . '/keep';
	$unrelated = array(); // extra entries sharing NO tag with targets.
	for ( $u = 0; $u < 5; ++$u ) {
		$ub         = $keygen->build( 'http', 'localhost', '/unrel-' . $run_ns . '/u' . $u . '/', '', array() );
		$unrelated[] = is_array( $ub ) ? $ub['dir'] : 'unrel-u' . $u;
	}

	// ---- seed REAL files ------------------------------------------------
	$seed_ok = 0;
	foreach ( $dirs as $rel ) {
		if ( $store->write( $rel, "<html>$run_ns</html>", 200, array(), array( $tag ), 3600 ) ) {
			++$seed_ok;
		}
	}
	$store->write( $sentinel, '<html>sentinel</html>', 200, array(), array( 'sent-tag-' . $run_ns ), 3600 );
	foreach ( $unrelated as $rel ) {
		$store->write( $rel, '<html>unrel</html>', 200, array(), array( 'unrel-tag-' . $run_ns ), 3600 );
	}

	// ---- exact sets BEFORE ----------------------------------------------
	$f          = static function ( $rel ) use ( $keygen ) { return (string) $keygen->absolute( $rel ); };
	$target_set = array_map( $f, $dirs );
	$sent_file  = $f( $sentinel );
	$unrel_set  = array_map( $f, $unrelated );

	// ---- chunking proof via the REAL chunk_dirs --------------------------
	$max_enc  = 0;
	$chunks   = QueueManager::chunk_dirs( $dirs, QueueManager::CHUNK_BYTES );
	foreach ( $chunks as $ch ) {
		$enc = strlen( (string) wp_json_encode( array( 'dirs' => $ch ) ) );
		$max_enc = max( $max_enc, $enc );
	}
	lpcheck(
		$results,
		"n=$n chunk budget ≤ CHUNK_BYTES",
		count( $chunks ) > 0 && $max_enc <= QueueManager::CHUNK_BYTES && count( array_merge( ...array_map( static function ( $c ) { return (array) $c; }, $chunks ) ) ) === $n,
		"chunks=" . count( $chunks ) . " max=$max_enc"
	);

	// ---- PRODUCTION invalidation trigger ---------------------------------
	$hooks->purge_tags( array( $tag ) );

	// ---- drain the real queue (bounded polling) ---------------------------
	$deadline = time() + 300; // HDD-bounded, generous but finite.
	$passes   = 0;
	do {
		uc_drive_queue( 500 );
		++$passes;
		$pending = 0;
		if ( class_exists( '\ActionScheduler_Store' ) ) {
			try {
				$pending = count(
					(array) \ActionScheduler_Store::instance()->query_actions(
						array( 'hook' => 'ultimate_performance_as_job', 'status' => 'pending', 'per_page' => 1 )
					)
				);
			} catch ( \Throwable $e ) {
				$pending = 0;
			}
		}
		clearstatcache();
		$gone_all = true;
		foreach ( $target_set as $tf ) {
			if ( file_exists( $tf ) ) {
				$gone_all = false;
				break;
			}
		}
	} while ( ( $pending > 0 || ! $gone_all ) && time() < $deadline );

	$timed_out = time() >= $deadline && ( $pending > 0 || ! $gone_all );

	// ---- exact-set verification on DISK -----------------------------------
	clearstatcache();
	$remaining_targets = array();
	foreach ( $target_set as $tf ) {
		if ( file_exists( $tf ) ) {
			$remaining_targets[] = $tf;
		}
	}
	$sent_alive = file_exists( $sent_file );
	$deleted_unrelated = array();
	foreach ( $unrel_set as $uf ) {
		if ( ! file_exists( $uf ) ) {
			$deleted_unrelated[] = $uf;
		}
	}

	lpcheck( $results, "n=$n all target files deleted", empty( $remaining_targets ), 'left=' . count( $remaining_targets ) . ' timeout=' . var_export( $timed_out, true ) );
	lpcheck( $results, "n=$n sentinel preserved", $sent_alive, $sent_file );
	lpcheck( $results, "n=$n unrelated untouched", empty( $deleted_unrelated ), json_encode( array_slice( $deleted_unrelated, 0, 3 ) ) );
	lpcheck( $results, "n=$n seeded==targets (validation kept all)", $seed_ok === $n, "seeded=$seed_ok of $n" );

	// accounting: purge_tags enqueues ONE job whose payload holds all dirs.
	$acct = QueueManager::instance()->get_last_accounting();
	$ok_inv = null !== $acct
		&& isset( $acct['submitted'], $acct['queued'], $acct['processed'], $acct['failed'], $acct['remaining'] )
		&& ( $acct['submitted'] === $acct['queued'] + $acct['processed'] + $acct['failed'] + $acct['remaining'] );
	lpcheck( $results, "n=$n accounting invariant", $ok_inv, json_encode( (array) $acct ) );
	lpcheck( $results, "n=$n healthy (failed=0 lost=0)", null !== $acct && 0 === $acct['failed'] && 0 === $acct['lost'] && 0 === $acct['remaining'], json_encode( (array) $acct ) );

	$dur = round( microtime( true ) - $t0, 2 );
	echo "    [info] n=$n chunks=" . count( $chunks ) . " max_chunk_bytes=$max_enc passes=$passes duration={$dur}s\n";

	// ---- cleanup (finally-equivalent: always runs per workload) -----------
	$base = "$root_abs/localhost/$run_ns";
	lp_rrmdir( $base );
	lp_rrmdir( "$root_abs/localhost/sent-$run_ns" );
	lp_rrmdir( "$root_abs/localhost/unrel-$run_ns" );
	lp_prune_parents( $base, "$root_abs/localhost" );
	@unlink( Installer::cache_root() . '/meta/tag-' . md5( $tag ) . '.json' );
	@unlink( Installer::cache_root() . '/meta/tag-' . md5( 'sent-tag-' . $run_ns ) . '.json' );
	@unlink( Installer::cache_root() . '/meta/tag-' . md5( 'unrel-tag-' . $run_ns ) . '.json' );
	@unlink( (string) $keygen->absolute( $sentinel ) . '.meta.json' );
	unset( $GLOBALS['UCQ_ENQUEUED'] );
	delete_transient( 'up_queue_last_failure' );
}

// ============================================================
// MATRIX — critical boundaries twice, others once.
// ============================================================
foreach ( array( 60, 120, 200, 363, 363, 364, 364, 950, 950, 5000, 5000 ) as $size ) {
	lp_run( $results, $size, $settings, $keygen, $fs, $store, $registry, $hooks, $root_abs );
}

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) {
	if ( ! $v ) {
		++$fails;
		echo "FAIL: $k\n";
	}
}
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
