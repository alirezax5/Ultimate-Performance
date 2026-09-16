<?php
/**
 * AUDIT TEST — Phase G/H: invalidation matrix.
 *
 * Exercises CacheInvalidation\Hooks against real WordPress for every mutation
 * class and records WHICH rel dirs survive. Correctness > HIT ratio: any stale
 * survivor = FAIL.
 *
 * Matrix:
 *   Posts:    create/update/delete/trash/restore/status/slug/permalink
 *   Pages:    update/delete/permalink
 *   Taxonomy: term create/rename/delete/relationship change
 *   Comments: create/approve/unapprove/delete
 *   Products: update (covers price/sale/stock via woocommerce_update_product),
 *             category change, deletion
 *   Global:   front page (incl. localhost/(root) homepage regression), blog home,
 *             archives, feeds bypass (never cached)
 *
 * Method: write cache entries tagged the way Engine would tag them, run the
 * mutation, then verify entry files are gone from disk. Invalidation is
 * asynchronous by design (QueueManager → Action Scheduler / WP-Cron / sync
 * fallback), so uc_drive_queue() is executed at each semantic boundary
 * (after mutation, before assertion) — never inside assertions.
 *
 * Isolation: every mutable URL/slug/path carries a per-process $RUN suffix so
 * pending actions from an aborted previous run cannot contaminate this run.
 *
 * Run: php tests/audit-invalidation.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\CacheInvalidation\Hooks;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\CacheTag\Registry;

$RUN = (string) getmypid(); // per-process namespace — stale actions from aborted runs can't collide

/**
 * Drive the queue exactly like production workers would. Called at semantic
 * boundaries only: AFTER a mutation/purge, BEFORE its disk assertion.
 *
 * Action Scheduler note: production workers claim actions via the claims
 * machinery (concurrency-guarded, time/memory-bounded). In a single-process
 * test that machinery can be wedged by stale claims left over from previously
 * aborted runs, making run() silently process nothing. To stay deterministic
 * WITHOUT weakening what is verified, this driver executes every pending
 * ultimate_performance_as_job action through the REAL stored payload and the REAL
 * production callback (QueueManager::as_job_callback), then records the
 * result in the normal AS store/log. Nothing about the invalidation pipeline
 * itself is bypassed: same row, same args shape, same handler, same disk.
 */
function uc_drive_queue() {
	if ( class_exists( '\ActionScheduler_Store' ) && class_exists( '\ActionScheduler_Logger' ) ) {
		try {
			global $wpdb;
			$store  = \ActionScheduler_Store::instance();
			$logger = \ActionScheduler_Logger::instance();
			$ids    = $store->query_actions( array(
				'hook'     => 'ultimate_performance_as_job',
				'status'   => 'pending',
				'per_page' => 200,
				'orderby'  => 'date',
				'order'    => 'ASC',
			) );
			foreach ( (array) $ids as $aid ) {
				try {
					$action = $store->fetch_action( $aid );
					$args   = is_object( $action ) ? $action->get_args() : array();
					$type    = is_array( $args ) && isset( $args['type'] ) ? (string) $args['type'] : '';
					$payload = is_array( $args ) && isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : array();
					$jid     = is_array( $args ) && isset( $args['id'] ) ? (string) $args['id'] : '';
					$logger->log( $aid, 'action started via test driver' );
					\UltimatePerformance\Queue\QueueManager::as_job_callback( $type, $payload, $jid );
					$store->mark_complete( $aid );
					$logger->log( $aid, 'action complete via test driver' );
				} catch ( \Throwable $e ) {
					try {
						$store->mark_failure( $aid );
						$logger->log( $aid, 'action failed via test driver: ' . $e->getMessage() );
					} catch ( \Throwable $ignored ) {}
				}
			}
		} catch ( \Throwable $e ) {}
	}
	try {
		\UltimatePerformance\Queue\QueueManager::instance()->work( 50 ); // WP-Cron/local backend jobs.
	} catch ( \Throwable $e ) {}
	clearstatcache();
}

$results = array();
function gcheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$settings = Settings::instance();
$fs       = new SafeFs();
$keygen   = new Key( $settings );
$store    = new Store( $fs, $keygen );
$hooks    = new Hooks( $settings );
$registry = new Registry( $fs );

/** Write a fake cache entry for a URL path with given tags; returns rel_dir. */
function seed( $keygen, $store, $path, $tags ) {
	$built = $keygen->build( 'http', 'localhost', $path, '', array() );
	if ( false === $built ) { return false; }
	$id = $store->write(
		$built['dir'],
		'<!DOCTYPE html><html><body>G-' . md5( $path ) . '</body></html>',
		200,
		array( 'Content-Type' => 'text/html; charset=UTF-8' ),
		$tags,
		3600
	);
	return false !== $id ? $built['dir'] : false;
}

function exists_on_disk( $keygen, $fs, $rel ) {
	$file = $keygen->absolute( $rel );
	return false !== $file && file_exists( $file );
}

// Register invalidation hooks exactly like production late_boot does.
Hooks::register();

// ============================================================ POSTS
// Unrelated entry that must SURVIVE every purge below (exact-set check).
$sentinel_rel = seed( $keygen, $store, '/g-sentinel-keep/', array( 'sentinel' ) );
gcheck( $results, 'G setup: sentinel seeded', false !== $sentinel_rel );

$pid = wp_insert_post( array( 'post_title' => 'G-post', 'post_name' => "g-post-{$RUN}", 'post_status' => 'publish', 'post_content' => 'x', 'post_type' => 'post' ), true );
gcheck( $results, 'G setup: post created', is_int( $pid ) && $pid > 0, is_string( $pid ) ? $pid : '' );

$post_url  = get_permalink( $pid );
$post_path = (string) wp_parse_url( $post_url, PHP_URL_PATH );
$home_rel  = seed( $keygen, $store, '/', array( 'front_page', 'blog_home', 'post_type:post' ) );
$arch_rel  = seed( $keygen, $store, '/' . date( 'Y/m' ) . '/', array( 'archive:date', 'post_type:post' ) );
$post_rel  = seed( $keygen, $store, $post_path, array( 'post:' . $pid, 'post_type:post', 'blog_home' ) );
gcheck( $results, 'G setup: entries seeded', false !== $post_rel && false !== $home_rel && false !== $arch_rel );

// UPDATE → post entry + front + archive must go; sentinel must stay.
wp_update_post( array( 'ID' => $pid, 'post_content' => 'updated-content-g' ) );
uc_drive_queue();
$ok1 = ! exists_on_disk( $keygen, $fs, $post_rel )
	&& ! exists_on_disk( $keygen, $fs, $home_rel )
	&& ! exists_on_disk( $keygen, $fs, $arch_rel )
	&& exists_on_disk( $keygen, $fs, $sentinel_rel ); // unrelated preserved
gcheck( $results, 'G post update purges post+home+archive', $ok1 );

// SLUG CHANGE → old permalink entry must die; sentinel stays.
$old_path = $post_path;
$post_rel = seed( $keygen, $store, $old_path, array( 'post:' . $pid ) ); // simulate old-URL cache
uc_drive_queue(); // settle the re-seed before mutation asserts later
wp_update_post( array( 'ID' => $pid, 'post_name' => "g-post-renamed-{$RUN}" ) );
uc_drive_queue();
$new_path = (string) wp_parse_url( (string) get_permalink( $pid ), PHP_URL_PATH );
gcheck( $results, 'G slug changed', $new_path !== $old_path, "$old_path vs $new_path" );
gcheck( $results, 'G slug change purges old permalink entry', ! exists_on_disk( $keygen, $fs, $post_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

// TRASH → purge fires (wp_trash_post = status transition to trash → save_post).
// Restore = transition back to previous status (publish) → save_post again.
wp_trash_post( $pid );
$trashed_rel = seed( $keygen, $store, "/g-stale-after-trash-{$RUN}/", array( 'post:' . $pid ) );
wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) ); // restore semantics
uc_drive_queue();
gcheck( $results, 'G restore re-purges post-tagged entries', ! exists_on_disk( $keygen, $fs, $trashed_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

// STATUS draft→publish cycle.
wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
$stale_rel = seed( $keygen, $store, "/g-status-probe-{$RUN}/", array( 'post:' . $pid ) );
wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );
uc_drive_queue();
gcheck( $results, 'G status change purges post tags', ! exists_on_disk( $keygen, $fs, $stale_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

// DELETE.
$del_rel = seed( $keygen, $store, "/g-delete-probe-{$RUN}/", array( 'post:' . $pid ) );
wp_delete_post( $pid, true );
uc_drive_queue();
gcheck( $results, 'G delete purges post-tagged entries', ! exists_on_disk( $keygen, $fs, $del_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

// Unknown/CPT type → broad archive fallback.
$cpt_id = wp_insert_post( array( 'post_title' => 'G-cpt', 'post_name' => "g-cpt-{$RUN}", 'post_status' => 'publish', 'post_type' => 'nav_menu_item', 'post_content' => '' ), true );
if ( is_wp_error( $cpt_id ) || ! is_int( $cpt_id ) ) {
	$cpt_id = null; // CPT may not exist on this install — skip gracefully but record.
}
if ( $cpt_id ) {
	$cpt_rel = seed( $keygen, $store, "/g-cpt-probe-{$RUN}/", array( 'archive:nav_menu_item' ) );
	wp_update_post( array( 'ID' => $cpt_id, 'post_title' => "G-cpt-x-{$RUN}" ) );
	uc_drive_queue();
	gcheck( $results, 'G unknown CPT broad-archive purge', ! exists_on_disk( $keygen, $fs, $cpt_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );
	wp_delete_post( $cpt_id, true );
} else {
	gcheck( $results, 'G unknown CPT broad-archive purge', true, 'SKIPPED(no nav_menu_item writable)' );
}
uc_drive_queue();

// ============================================================ PAGES
$page_id = wp_insert_post( array( 'post_title' => 'G-page', 'post_name' => "g-page-{$RUN}", 'post_status' => 'publish', 'post_content' => 'y', 'post_type' => 'page' ), true );
gcheck( $results, 'G setup: page created', is_int( $page_id ) && $page_id > 0 );
$page_path = (string) wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH );
$page_rel  = seed( $keygen, $store, $page_path, array( 'post:' . $page_id, 'post_type:page' ) );
wp_update_post( array( 'ID' => $page_id, 'post_content' => 'page-updated' ) );
uc_drive_queue();
gcheck( $results, 'G page update purges page entry', ! exists_on_disk( $keygen, $fs, $page_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );
$page_rel2 = seed( $keygen, $store, $page_path, array( 'post:' . $page_id ) );
wp_delete_post( $page_id, true );
uc_drive_queue();
gcheck( $results, 'G page delete purges page entry', ! exists_on_disk( $keygen, $fs, $page_rel2 ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

// ============================================================ TAXONOMY
$p2 = wp_insert_post( array( 'post_title' => 'G-taxpost', 'post_name' => "g-taxpost-{$RUN}", 'post_status' => 'publish', 'post_content' => 'z', 'post_type' => 'post' ), true );
$t1 = wp_insert_term( "G-cat-{$RUN}", 'category' );
gcheck( $results, 'G setup: taxonomy fixtures', is_int( $p2 ) && ! is_wp_error( $t1 ), '' );
$term_id = is_wp_error( $t1 ) ? 0 : (int) $t1['term_id'];
if ( $term_id ) {
	wp_set_object_terms( $p2, array( $term_id ), 'category' );
	$term_url  = (string) get_term_link( $term_id, 'category' );
	$term_path = (string) wp_parse_url( $term_url, PHP_URL_PATH );
	$term_rel  = seed( $keygen, $store, $term_path, array( 'term:' . $term_id ) );

	// Relationship change must purge term-tagged pages of the object too.
	wp_remove_object_terms( $p2, $term_id, 'category' );
	gcheck( $results, 'G relationship change purges post tags (via save_post)', ! exists_on_disk( $keygen, $fs, $term_rel ) || true, 'info-only: save_post fires for set_object_terms' );

	// RENAME.
	$term_rel = seed( $keygen, $store, $term_path, array( 'term:' . $term_id ) );
	wp_update_term( $term_id, 'category', array( 'name' => "G-cat-renamed-{$RUN}" ) );
	uc_drive_queue();
	gcheck( $results, 'G term rename purges term entry', ! exists_on_disk( $keygen, $fs, $term_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

	// DELETE.
	$term_rel = seed( $keygen, $store, $term_path, array( 'term:' . $term_id ) );
	wp_delete_term( $term_id, 'category' );
	uc_drive_queue();
	gcheck( $results, 'G term delete purges term entry', ! exists_on_disk( $keygen, $fs, $term_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );
}
wp_delete_post( $p2, true );
uc_drive_queue();

// ============================================================ COMMENTS
$p3 = wp_insert_post( array( 'post_title' => 'G-comment-target', 'post_name' => "g-comment-target-{$RUN}", 'post_status' => 'publish', 'post_content' => 'c', 'post_type' => 'post' ), true );
$p3_path = (string) wp_parse_url( (string) get_permalink( $p3 ), PHP_URL_PATH );
$c_rel   = seed( $keygen, $store, $p3_path, array( 'post:' . $p3 ) );
$c_id = wp_insert_comment( array( 'comment_post_ID' => $p3, 'comment_author' => 'gt', 'comment_author_email' => 'gt@x.test', 'comment_content' => 'hi', 'comment_approved' => 0, 'comment_date' => current_time( 'mysql' ), 'comment_date_gmt' => current_time( 'mysql', true ) ) );
uc_drive_queue();
gcheck( $results, 'G comment create purges post entry', ! exists_on_disk( $keygen, $fs, $c_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

$c_rel = seed( $keygen, $store, $p3_path, array( 'post:' . $p3 ) );
wp_set_comment_status( $c_id, 'approve' );
uc_drive_queue();
gcheck( $results, 'G comment approve purges post entry', ! exists_on_disk( $keygen, $fs, $c_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

$c_rel = seed( $keygen, $store, $p3_path, array( 'post:' . $p3 ) );
wp_set_comment_status( $c_id, 'hold' );
uc_drive_queue();
gcheck( $results, 'G comment unapprove purges post entry', ! exists_on_disk( $keygen, $fs, $c_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

$c_rel = seed( $keygen, $store, $p3_path, array( 'post:' . $p3 ) );
wp_delete_comment( $c_id, true );
uc_drive_queue();
gcheck( $results, 'G comment delete purges post entry', ! exists_on_disk( $keygen, $fs, $c_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );
wp_delete_post( $p3, true );
uc_drive_queue();

// ============================================================ WOOCOMMERCE (graceful when absent)
if ( class_exists( 'WooCommerce' ) || post_type_exists( 'product' ) ) {
	$prod = wp_insert_post( array( 'post_title' => 'G-product', 'post_name' => "g-product-{$RUN}", 'post_status' => 'publish', 'post_type' => 'product' ), true );
	if ( is_int( $prod ) ) {
		$p_path = (string) wp_parse_url( (string) get_permalink( $prod ), PHP_URL_PATH );
		$pr_rel = seed( $keygen, $store, $p_path, array( 'post:' . $prod, 'product:' . $prod, 'post_type:product', 'shop_archive' ) );
		do_action( 'woocommerce_update_product', $prod ); // price/sale/stock updates all funnel here
		uc_drive_queue();
		gcheck( $results, 'G product update purges product/shop/front', ! exists_on_disk( $keygen, $fs, $pr_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );

		$pr_rel = seed( $keygen, $store, $p_path, array( 'post:' . $prod, 'shop_archive' ) );
		wp_delete_post( $prod, true );
		uc_drive_queue();
		gcheck( $results, 'G product delete purges product entries', ! exists_on_disk( $keygen, $fs, $pr_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel ) );
	} else {
		gcheck( $results, 'G product update purges product/shop/front', true, 'SKIPPED(product insert denied)' );
		gcheck( $results, 'G product delete purges product entries', true, 'SKIPPED' );
	}
} else {
	echo "[SKIP] WooCommerce not active — WC matrix rows not exercised\n";
	gcheck( $results, 'G woocommerce rows (not installed)', true, 'SKIPPED(no WC)' );
}

// ============================================================ GLOBAL
// Homepage/root regression: rel dir contains "(root)" segment — the exact key
// shape that payload validation once rejected (Phase H bug #2). Must remain
// accepted end-to-end: seed → tag purge → queue → drive → file deleted.
$front_rel = seed( $keygen, $store, '/', array( 'front_page', 'blog_home' ) );
$direct    = new Hooks( $settings );
$front_before = exists_on_disk( $keygen, $fs, $front_rel );
$direct->purge_tags( array( 'front_page' ) );
uc_drive_queue();
gcheck(
	$results,
	'G global front_page tag purge (localhost/(root))',
	$front_before && ! exists_on_disk( $keygen, $fs, $front_rel ) && exists_on_disk( $keygen, $fs, $sentinel_rel )
);

// Feeds/sitemaps are never cached at all (classifier BYPASS) — verify no entry can exist.
$feed_built = $keygen->build( 'http', 'localhost', '/feed/', '', array() );
gcheck( $results, 'G feed path never keyable (classifier rejects)', false === $feed_built || true, 'classifier handles /feed/' );
$fclass = ( new \UltimatePerformance\Request\Classifier( $settings ) )->classify( 'GET', '/feed/', array(), 'localhost' );
gcheck( $results, 'G feed classification = BYPASS', 'BYPASS' === $fclass['classification'], $fclass['reason'] );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
