<?php
/**
 * Plugin Name:       Ultimate Performance
 * Plugin URI:        https://github.com/alirezax5/Ultimate-Performance
 * Description:       Production-grade WordPress caching platform. Zero-PHP public page cache HITs via web-server integration, object cache with Redis/Memcached/APCu/SQLite/File backends and promotion-fenced failover, RabbitMQ queue with fallback chain, off-peak cron scheduler, bounded telemetry, WooCommerce-safe request classification.
 * Version:           0.6.9
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            alirezax5
 * License:           GPL-2.0-or-later
 * Text Domain:       ultimate-performance
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance;

defined( 'ABSPATH' ) || exit;

// B: Guard ALL constants against double-load (object-cache drop-in + plugin
// bootstrap both load this file's symbols). Only ULTIMATE_PERFORMANCE_DIR was
// previously guarded; VERSION/FILE/URL emitted PHP warnings on re-include.
if ( ! defined( 'ULTIMATE_PERFORMANCE_VERSION' ) ) {
        define( 'ULTIMATE_PERFORMANCE_VERSION', '0.6.9' );
}
if ( ! defined( 'ULTIMATE_PERFORMANCE_FILE' ) ) {
        define( 'ULTIMATE_PERFORMANCE_FILE', __FILE__ );
}
if ( ! defined( 'ULTIMATE_PERFORMANCE_DIR' ) ) {
        define( 'ULTIMATE_PERFORMANCE_DIR', __DIR__ . '/' );
}
if ( ! defined( 'ULTIMATE_PERFORMANCE_URL' ) ) {
        define( 'ULTIMATE_PERFORMANCE_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) . '/' );
}

require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();

// §0.6.7 — Load plugin textdomain for translations (Persian/Farsi etc.).
// Must be called BEFORE any admin strings are output. We call it on
// 'plugins_loaded' so WP's translation subsystem is ready (some plugins
// register translations on init; we use plugins_loaded for earlier load
// — admin notices fired during plugins_loaded hook can use translations).
if ( function_exists( 'add_action' ) ) {
        add_action( 'plugins_loaded', function () {
                load_plugin_textdomain(
                        'ultimate-performance',
                        false, // deprecated $abs_rel_path argument (WP <2.7)
                        dirname( plugin_basename( ULTIMATE_PERFORMANCE_FILE ) ) . '/languages'
                );
                // Backward-compat: also try the old text domain from pre-0.6.6
                // in case a translation file ships under the old name.
                load_plugin_textdomain(
                        'ultimate-cache',
                        false,
                        dirname( plugin_basename( ULTIMATE_PERFORMANCE_FILE ) ) . '/languages'
                );
        }, 1 );
}

// Lightweight runtime kernel — must load on every request, admin or not.
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Plugin.php';
register_activation_hook( __FILE__, array( '\UltimatePerformance\Core\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\UltimatePerformance\Core\Installer', 'deactivate' ) );

// §9/§10/§35 — Register ALL wp_ajax_* hooks DIRECTLY in the plugin main file.
// This is the MOST RELIABLE registration point: WordPress loads this file
// during wp-settings.php BEFORE plugins_loaded fires and BEFORE admin-ajax.php
// dispatches actions.
//
// §19/§47 — This registration MUST NOT depend on Plugin::instance()->boot()
// succeeding. If boot() throws (e.g., Redis construction failure during
// object-cache bootstrap), the AJAX hooks must STILL be registered so the
// admin can test/repair Redis settings.
//
// §48 — Redis is an OPTIONAL backend. Plugin must NEVER self-deactivate due
// to optional service failure. AJAX handlers enforce nonce + capability
// independently, so unconditional registration is safe.
//
// §5/§8 — Audit: EVERY callback registered here MUST have a corresponding
// public method on AdminPage. The callback audit tests/audit-adminpage-callbacks.php
// verifies this at regression time. The previous "enqueue_assets" fatal was
// caused by registering a callback for a method that didn't exist.
$up_ajax_admin = new \UltimatePerformance\Admin\AdminPage();
if ( function_exists( 'add_action' ) ) {
        // §5/§6 — Real AJAX handlers. The methods below are implemented and
        // verified callable by tests/audit-adminpage-callbacks.php:
        //   ajax_test_redis, ajax_oc_runtime_phase1/2/3, enqueue_assets.
        add_action( 'wp_ajax_up_ajax_test_redis',        array( $up_ajax_admin, 'ajax_test_redis' ) );
        add_action( 'wp_ajax_up_ajax_oc_runtime_phase1', array( $up_ajax_admin, 'ajax_oc_runtime_phase1' ) );
        add_action( 'wp_ajax_up_ajax_oc_runtime_phase2', array( $up_ajax_admin, 'ajax_oc_runtime_phase2' ) );
        add_action( 'wp_ajax_up_ajax_oc_runtime_phase3', array( $up_ajax_admin, 'ajax_oc_runtime_phase3' ) );
        // §28 — Legacy admin_post_* handlers kept for backwards compatibility
        // with any external tools that POST to admin-post.php. The AJAX
        // transport is the primary path; admin_post_* is a fallback for
        // non-JS clients. Both can coexist safely.
        add_action( 'admin_post_up_save_settings',    array( $up_ajax_admin, 'handle_save' ) );
        add_action( 'admin_post_up_purge_all',         array( $up_ajax_admin, 'handle_purge_all' ) );
        add_action( 'admin_post_up_test_redis',        array( $up_ajax_admin, 'handle_test_redis' ) );
        add_action( 'admin_post_up_test_oc_runtime',   array( $up_ajax_admin, 'handle_test_oc_runtime' ) );
        add_action( 'admin_post_up_test_amqp',         array( $up_ajax_admin, 'handle_test_amqp' ) );
        add_action( 'admin_post_up_oc_install',        array( $up_ajax_admin, 'handle_oc_install' ) );
        add_action( 'admin_post_up_oc_remove',         array( $up_ajax_admin, 'handle_oc_remove' ) );
        add_action( 'admin_post_up_nginx_verify',      array( $up_ajax_admin, 'handle_nginx_verify' ) );
        // §12 — admin_enqueue_scripts callback also lives here so it is
        // registered even if boot() throws. The callback enqueue_assets()
        // is implemented on AdminPage and is verified callable by the audit.
        add_action( 'admin_enqueue_scripts',            array( $up_ajax_admin, 'enqueue_assets' ) );
}

// §19/§47 — Wrap boot() in try/catch. If the plugin runtime throws during
// construction (e.g., Settings/Manager/object-cache drop-in failure), the
// plugin MUST remain loaded and the admin MUST remain accessible. The
// catch logs the error but does NOT deactivate the plugin. WordPress
// continues with degraded functionality (no page cache, runtime-only
// object cache via the drop-in's fallback).
try {
        \UltimatePerformance\Core\Plugin::instance()->boot();
} catch ( \Throwable $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                error_log( 'Ultimate Performance: Plugin::boot() failed: ' . $e->getMessage() . ' — plugin remains active in degraded mode.' );
        }
        // DO NOT deactivate. DO NOT wp_die(). WordPress continues.
        // The admin AJAX hooks above are ALREADY registered (they were
        // registered BEFORE this try block). The admin can still access
        // the settings page and repair Redis configuration.
}
