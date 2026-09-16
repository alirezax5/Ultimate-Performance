<?php
/**
 * Portable WordPress CLI shim for the Ultimate Performance audit suites.
 *
 * Purpose: the permanent suites historically required a live WordPress install
 * at a hardcoded local path (D:/xampp). To make every suite runnable on any
 * host WITHOUT weakening what they verify, this shim emulates the exact WP-API
 * surface the plugin + suites exercise:
 *
 *   - options/transients/cron: file-backed (flock-serialized) so child
 *     processes see the same "DB" — required by the concurrency matrix —
 *     plus a per-process non-persistent cache mirroring WP alloptions
 *     semantics (wp_cache_delete/flush invalidate it exactly like WP).
 *   - hooks: WP_Hook-compatible objects ($wp_filter[hook]->callbacks) with
 *     priority + accepted_args + remove_all_actions/has_action.
 *   - Action Scheduler-compatible store/logger (file-backed) — same class
 *     names the production driver (tests/fixtures/queue-driver.php) consumes.
 *   - posts/terms/comments/permalinks (file-backed) firing the real hook
 *     sequence (save_post/delete_post/edit_term/delete_comment/…).
 *   - $wpdb shim exposing prefix + prepare/get_results for the AS logs table.
 *
 * It is TEST INFRASTRUCTURE ONLY — never loaded by production code. Wherever
 * the real plugin code calls WP, the semantics below mirror core behavior.
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests\Shim;

if ( ! defined( 'ABSPATH' ) ) {
        exit( 'ABSPATH must be defined by the calling suite' );
}

if ( defined( 'UC_SHIM_LOADED' ) ) {
        return;
}
define( 'UC_SHIM_LOADED', true );

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
        define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}
if ( ! defined( 'WP_CONTENT_URL' ) ) {
        define( 'WP_CONTENT_URL', 'http://localhost/wp-content' );
}
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'WP_DEBUG', false );

// M5-T1 (harness): $wpdb fetch-array shape constants. Real WordPress defines
// these globally in wp-includes/class-wpdb.php; the M5 cluster suite's
// EventStore::pending_for() fetches ARRAY_A. Inside a PHP namespace an
// unqualified constant falls back to the GLOBAL constant only when the
// global one exists — so the shim must provide it.
if ( ! defined( 'ARRAY_A' ) ) {
        define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
        define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
        define( 'OBJECT', 'OBJECT' );
}

$GLOBALS['up_shim_cache']  = array(); // per-process non-persistent cache (mirrors WP object cache).
$GLOBALS['up_shim_mem']    = array(); // non-option runtime store.
$GLOBALS['wp_filter']      = array();
$GLOBALS['wp_actions']     = array();
$GLOBALS['wp_current_filter'] = array();
// AJAX / asset tracking (Phase AJAX-RUNTIME-RESTORE). Real WP stores registered
// and enqueued scripts in WP_Scripts; the shim uses simple arrays so the audit
// can introspect enqueue_assets() behavior in tests/audit-ajax-runtime.php.
$GLOBALS['_uc_scripts']              = array();
$GLOBALS['_uc_enqueued_scripts']      = array();
$GLOBALS['_uc_last_json_response']    = null;

require_once __DIR__ . '/lib/state.php';
require_once __DIR__ . '/lib/hooks.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/ms.php';
require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/as.php';
require_once __DIR__ . '/lib/as-classes.php';

// Mirror wp-settings.php load order: when an object-cache drop-in exists it
// REPLACES the default cache function set (real WP skips wp-includes/cache.php
// entirely when wp-content/object-cache.php is present). Loading it BEFORE
// lib/globals.php lets the drop-in's wp_cache_* functions win; the wrappers in
// globals.php are function_exists-guarded and yield.
if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
        $GLOBALS['_wp_using_ext_object_cache'] = true;
        require_once WP_CONTENT_DIR . '/object-cache.php';
}

require_once __DIR__ . '/lib/globals.php';

// Stable shared content dir for cache roots.
if ( ! is_dir( WP_CONTENT_DIR ) ) {
        @mkdir( WP_CONTENT_DIR, 0777, true );
}

// The real host defines ULTIMATE_PERFORMANCE_DIR by loading the active plugin's
// entrypoint during wp-load. Suites that define it earlier are unaffected.
if ( ! defined( 'ULTIMATE_PERFORMANCE_DIR' ) ) {
        define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__, 2 ) . '/' );
}

// Emulate a WP install with optional active-plugin loading: when the suite
// defines ULTIMATE_CACHE_SHIM_ACTIVE_PLUGIN the REAL entrypoint is required
// and 'plugins_loaded' fires exactly like a boot with the plugin active
// (mirrors how the original dev box ran audit-boot against an active plugin).
do_action( 'plugins_loaded' );
if ( defined( 'ULTIMATE_CACHE_SHIM_ACTIVE_PLUGIN' ) ) {
        // Mirror the original dev box: the plugin was ACTIVE and CONFIGURED
        // (page cache + queue enabled), so late_boot registers everything.
        $cfg = shim_get_option( 'ultimate_performance_settings', array() );
        if ( ! is_array( $cfg ) ) {
                $cfg = array();
        }
        $cfg['enabled']            = true;
        $cfg['page_cache_enabled'] = true;
        $cfg['queue_enabled']      = true;
        shim_update_option( 'ultimate_performance_settings', $cfg, true );

        $entry = dirname( __DIR__, 2 ) . '/ultimate-performance.php';
        if ( file_exists( $entry ) ) {
                require_once $entry;
        }
        do_action( 'plugins_loaded' );
}
do_action( 'init' );
