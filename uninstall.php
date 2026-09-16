<?php
/**
 * Uninstall handler (WordPress.org contract).
 *
 * Runs ONCE when the plugin is deleted (WP defines WP_UNINSTALL_PLUGIN and
 * requires the plugin to be inactive first). Complete, honest data removal:
 * settings, plugin transients, cron hooks, the purge capability, the whole
 * per-node cache tree (page cache bodies+meta, tag registry, node identity,
 * watermark, telemetry + cluster state) and the SHARED cluster events table.
 *
 * Multisite note: the events table lives on the BASE prefix (network-wide
 * coordination point per docs/PHASE-M-CLUSTER-INVALIDATION.md §3) — one DROP
 * removes it for the whole network. Per-site settings on OTHER sites of a
 * multisite network are reset by WP's own per-site uninstall semantics; this
 * file cleans the network-shared state explicitly.
 *
 * @package UltimatePerformance
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || '' === (string) WP_UNINSTALL_PLUGIN ) {
        exit; // never run outside a real uninstall
}

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

$up_autoloader = __DIR__ . '/src/Core/Autoloader.php';
if ( ! is_readable( $up_autoloader ) ) {
        exit; // refuse to guess: without the plugin's own classes we cannot clean up honestly
}
// uninstall.php is loaded OUTSIDE the main plugin bootstrap context
// (WP_UNINSTALL_PLUGIN is defined but the main plugin file is NOT loaded),
// so ULTIMATE_PERFORMANCE_DIR is not yet defined. Define it here so the
// Autoloader can resolve paths.
if ( ! defined( 'ULTIMATE_PERFORMANCE_DIR' ) ) {
        define( 'ULTIMATE_PERFORMANCE_DIR', __DIR__ . '/' );
}
require_once $up_autoloader;
\UltimatePerformance\Core\Autoloader::register();
\UltimatePerformance\Core\Installer::uninstall_data();
