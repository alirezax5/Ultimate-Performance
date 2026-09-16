<?php
/**
 * Installer — activation, deactivation, uninstall prep.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Core;

use UltimatePerformance\Compatibility\DropinGuards;

defined( 'ABSPATH' ) || exit;

final class Installer {

        const CAP_PURGE_ALL = 'ultimate_performance_purge_all';

        public static function activate() {
                // §0.6.5: removed the `current_user_can('activate_plugins')` guard.
                // WordPress already verifies the capability before firing the
                // `activate_{$plugin}` action — re-checking here blocks CLI
                // and programmatic activation (wp-cli, REST, automated tests,
                // multisite network-admin bulk-activate, etc.) which all run
                // without a logged-in user in the request scope.
                $settings = Settings::instance();
                add_option( Settings::OPTION, $settings->raw(), '', true );

                $admin = get_role( 'administrator' );
                if ( $admin && ! $admin->has_cap( self::CAP_PURGE_ALL ) ) {
                        $admin->add_cap( self::CAP_PURGE_ALL );
                }

                // §0.6.6: rebrand migration — upgrade sites that installed
                // pre-0.6.6 (which used the 'uc_' / 'UltimateCache' brand) to
                // the new 'up_' / 'Ultimate Performance' brand. Idempotent:
                // each step is wrapped in existence checks so running it on a
                // fresh install (no old data) is a no-op.
                self::migrate_uc_to_up_brand();

                self::ensure_cache_root();

                // WP_CACHE must be enabled in wp-config.php or WordPress never
                // includes wp-content/advanced-cache.php — the PHP-fallback HIT
                // path and the Engine capture path both stay dead. This is the
                // documented WP drop-in contract, and it is what activation is
                // responsible for. Silent no-op when the file is not writable
                // (admin diagnostics surface the resulting "undefined" state).
                self::set_wp_cache( true );

                // BENCH-D5/HARDEN-5: Install the PHP fallback drop-in if the
                // setting is enabled and no foreign drop-in exists. Safe: never
                // overwrites another plugin's advanced-cache.php.
                if ( $settings->get( 'php_fallback_enabled', true ) ) {
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/Compatibility/AdvancedCacheDropin.php';
                        $dropin = new \UltimatePerformance\Compatibility\AdvancedCacheDropin();
                        $dropin->install();
                }

                set_transient(
                        'up_env_snapshot',
                        array(
                                'time'    => time(),
                                'dropins' => ( new DropinGuards() )->snapshot(),
                        ),
                        DAY_IN_SECONDS
                );
        }

        /**
         * Cache root under wp-content/cache/ultimate-performance. Hardened by generated
         * .htaccess: no PHP execution, no meta/lock/log/tmp retrieval, no listing.
         *
         * @return bool True when root usable for writes.
         */
        public static function ensure_cache_root() {
                $root = self::cache_root();
                $ok   = true;
                foreach ( array( $root, $root . '/v', $root . '/meta', $root . '/tmp' ) as $dir ) {
                        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
                                $ok = false;
                        }
                }
                if ( ! is_dir( $root ) || ! is_writable( $root ) ) {
                        return false;
                }

                $htaccess = $root . '/.htaccess';
                // Regenerate whenever the managed block drifts from the current
                // generator output (version upgrades, manual tampering). Outside the
                // "# BEGIN/END Ultimate Performance Storage" markers nothing is touched.
                $want = self::cache_htaccess();
                $have = file_exists( $htaccess ) ? (string) file_get_contents( $htaccess ) : '';
                if ( false === strpos( $have, $want ) ) {
                        $have = preg_match(
                                '#' . preg_quote( '# BEGIN Ultimate Performance Storage', '#' ) . '[\s\S]*?' . preg_quote( '# END Ultimate Performance Storage', '#' ) . '#',
                                $have,
                                $m
                        );
                        if ( ! empty( $m[0] ) ) {
                                @file_put_contents( $htaccess, str_replace( $m[0], $want, (string) file_get_contents( $htaccess ) ) );
                        } else {
                                @file_put_contents( $htaccess, $want ); // fresh or foreign-only dir
                        }
                }
                $idx = $root . '/index.html';
                if ( ! file_exists( $idx ) ) {
                        @file_put_contents( $idx, '' );
                }
                return $ok;
        }

        /**
         * Protection block for the cache storage tree itself. Architecture (Phase F):
         * PUBLIC: only v/**\/index.html bodies. PRIVATE: everything else (metadata,
         * locks, tags, indexes, stats, diagnostics, tmp) — HTTP-denied.
         *
         * Fail-closed whitelist: any file whose name is not exactly "index.html"
         * is denied, so future internal file types are denied BY DEFAULT and
         * security never depends on enumerating filenames. Case-sensitivity of
         * FilesMatch is a feature here: NTFS is case-insensitive, so an uppercase
         * request for "INDEX.HTML.META.JSON" maps to the same file but fails the
         * case-sensitive lookahead → denied. The (?i) extension blacklist below is
         * belt-and-braces for Apache builds without PCRE lookahead support.
         *
         * @return string
         */
        public static function cache_htaccess() {
                $deny = "       <IfModule mod_authz_core.c>\n           Require all denied\n    </IfModule>\n   <IfModule !mod_authz_core.c>\n          Order allow,deny\n              Deny from all\n </IfModule>\n";
                // NOTE (live-fire verified on Apache 2.4.58): a bare directory-level
                // "Require all denied" cannot be re-opened by a section-level grant,
                // so the deny must live ONLY inside FilesMatch sections.
                return "# BEGIN Ultimate Performance Storage (auto-generated - do not edit)\n"
                        . "Options -Indexes\n"
                        . "<FilesMatch \"^(?!index\\.html$)\">\n" . $deny . "</FilesMatch>\n"
                        . "<FilesMatch \"(?i)\\.(php|phtml|phar|json|jsonl|lock|tmp|log|txt|md|idx|data|serialize|queue|tag|sqlite|db)$\">\n" . $deny . "</FilesMatch>\n"
                        . "<FilesMatch \"(?i)^\\.\">\n" . $deny . "</FilesMatch>\n"
                        . "# END Ultimate Performance Storage\n";
        }

        /**
         * @return string Normalized absolute cache root, no trailing slash.
         */
        public static function cache_root() {
                $base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( ABSPATH . 'wp-content' );
                return wp_normalize_path( $base . '/cache/ultimate-performance' );
        }

        /**
         * Enable or disable the WP_CACHE constant in wp-config.php.
         *
         * WordPress only loads wp-content/advanced-cache.php when WP_CACHE is
         * truthy, so Page Cache is inert until this constant exists. Idempotent:
         * toggling on twice writes one line; toggling off removes only our own
         * marked line. Foreign/unmanaged WP_CACHE definitions are never touched.
         * Silently returns false when wp-config.php is unwritable — the admin
         * diagnostics page reports the resulting state instead of failing here.
         *
         * @param bool $enable True to add our define, false to remove it.
         * @return bool True when wp-config.php reflects the desired state.
         */
        public static function set_wp_cache( $enable ) {
                $config = self::wp_config_path();
                if ( '' === $config || ! is_readable( $config ) ) {
                        return false;
                }
                $contents = (string) file_get_contents( $config );
                if ( '' === $contents ) {
                        return false;
                }

                $begin = "/* BEGIN Ultimate Performance */\n";
                $end   = "/* END Ultimate Performance */\n";
                $block = $begin . "define( 'WP_CACHE', true ); // Added by Ultimate Performance\n" . $end;

                // Strip any prior managed block (handles old single-line markers too).
                $managed = array();
                if ( preg_match( '#/\* BEGIN Ultimate Performance \*/[\s\S]*?/\* END Ultimate Performance \*/\r?\n?#', $contents, $managed ) ) {
                        $contents = str_replace( $managed[0], '', $contents );
                }

                if ( $enable ) {
                        // Leave an existing unmanaged define alone; WP already has it on.
                        if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
                                return true;
                        }
                        $contents = self::insert_before_requires( $contents, $block );
                }

                if ( ! is_writable( $config ) ) {
                        return false;
                }
                // Atomic: temp + rename so a concurrent request never reads a
                // half-written wp-config.php.
                $tmp = $config . '.tmp-' . substr( md5( uniqid( '', true ) ), 0, 8 );
                if ( false === file_put_contents( $tmp, $contents ) ) {
                        return false;
                }
                if ( ! @rename( $tmp, $config ) ) {
                        @unlink( $tmp );
                        return false;
                }
                @chmod( $config, 0644 );
                return true;
        }

        /**
         * Insert a line immediately before the first require_once/include that
         * loads wp-settings.php — the same convention core recommends for
         * wp-config constants. Falls back to appending at the end.
         *
         * @param string $contents wp-config.php body.
         * @param string $block    Line(s) to insert.
         * @return string Modified body.
         */
        private static function insert_before_requires( $contents, $block ) {
                if ( preg_match( '/^[ \t]*(require|include)[^\n\r]*wp-settings\.php[^\n\r]*[\r\n]/m', $contents, $m, PREG_OFFSET_CAPTURE ) ) {
                        $pos  = $m[0][1];
                        $line = $m[0][0];
                        // Insert before the whole matched line.
                        return substr( $contents, 0, $pos ) . $block . $line . substr( $contents, $pos + strlen( $line ) );
                }
                return $contents . $block;
        }

        /**
         * Absolute path to wp-config.php for this install (one level above or
         * at ABSPATH). Empty string when it cannot be resolved.
         *
         * @return string
         */
        public static function wp_config_path() {
                if ( ! defined( 'ABSPATH' ) || ! is_string( ABSPATH ) || '' === ABSPATH ) {
                        return '';
                }
                $candidates = array(
                        rtrim( ABSPATH, '/\\' ) . '/../wp-config.php',
                        rtrim( ABSPATH, '/\\' ) . '/wp-config.php',
                );
                foreach ( $candidates as $c ) {
                        $real = realpath( $c );
                        if ( false !== $real && is_file( $real ) ) {
                                return $real;
                        }
                }
                return '';
        }

        public static function deactivate() {
                // §0.6.5: removed the `current_user_can('activate_plugins')` guard.
                // WordPress already verifies the capability before firing the
                // `deactivate_{$plugin}` action — re-checking here blocks CLI
                // and programmatic deactivation (wp-cli, REST, automated tests,
                // multisite network-admin bulk-deactivate, etc.) which all run
                // without a logged-in user in the request scope.
                wp_clear_scheduled_hook( 'ultimate_performance_tick' );
                wp_clear_scheduled_hook( 'ultimate_performance_janitor' );
                wp_clear_scheduled_hook( 'ultimate_performance_telemetry' );

                // If WE own the object-cache drop-in, remove it so WP falls back cleanly.
                $dropin = WP_CONTENT_DIR . '/object-cache.php';
                if ( file_exists( $dropin ) ) {
                        $contents = (string) file_get_contents( $dropin );
                        if ( false !== strpos( $contents, 'UltimateCache' ) || false !== strpos( $contents, 'Ultimate Performance' ) ) {
                                wp_delete_file( $dropin );
                        }
                }

                // HARDEN-5: Remove our advanced-cache.php drop-in if we own it.
                require_once ULTIMATE_PERFORMANCE_DIR . 'src/Compatibility/AdvancedCacheDropin.php';
                $ac_dropin = new \UltimatePerformance\Compatibility\AdvancedCacheDropin();
                $ac_dropin->remove();

                // WP_CACHE was enabled by activation; remove our line so WP no
                // longer loads a drop-in we no longer ship.
                self::set_wp_cache( false );

                // §0.6.5: also wipe the cache directory on deactivation. The plugin
                // is no longer active — leaving a stale cache tree on disk serves
                // no purpose and confuses both admins (looking at "where did my
                // disk space go?") and any caching reverse proxy that might still
                // be configured to read from this path. Settings/options/tables
                // are PRESERVED so reactivation restores the previous config.
                self::rrmdir( self::cache_root() );
                // Belt-and-braces: legacy "ultimate-cache" path from before the
                // 0.6.3 rebrand. Some sites upgraded from ultimate-cache 0.6.x
                // and still have this old directory lying around.
                $legacy = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( ABSPATH . 'wp-content' );
                $legacy = wp_normalize_path( $legacy . '/cache/ultimate-cache' );
                if ( is_dir( $legacy ) ) {
                        self::rrmdir( $legacy );
                }

                // §0.6.5: also clear all plugin transients on deactivation. They
                // hold cached test results / status snapshots that are stale the
                // moment the plugin goes inactive.
                self::delete_all_plugin_transients();

                do_action( 'ultimate_performance_deactivated' );
        }

        /**
         * §0.6.5 — Delete every transient the plugin uses.
         *
         * Called by both deactivate() and uninstall_data() so neither path can
         * leak stale cached state. Listed here as a single source of truth so
         * adding a new transient in the future only requires updating one place.
         */
        private static function delete_all_plugin_transients() {
                $transients = array(
                        'up_env_snapshot',      // activation environment snapshot
                        'up_oc_dropin_status',  // object-cache drop-in status
                        'up_registry_overflow', // CacheTag registry overflow flag
                        'up_settings_errors',   // last save validation errors
                        'up_save_summary',      // 0.6.4 — last save summary (preserved/cleared/changed)
                        'up_redis_test',        // last Redis connection test result
                        'up_oc_runtime_test',   // last Object Cache Runtime test result
                        'up_amqp_test',          // last AMQP connection test result
                        'up_oc_action',          // last drop-in install/remove action
                );
                foreach ( $transients as $t ) {
                        delete_transient( $t );
                }
        }

        /**
         * Explicit "delete all plugin data" — invoked from admin Tools only and
         * by uninstall.php (M6). Idempotent; never throws into the uninstall.
         */
        public static function uninstall_data() {
                Settings::instance()->reset();
                // §0.6.5: delete EVERY plugin-owned transient, not just a few.
                self::delete_all_plugin_transients();
                // Belt and braces: deactivation already clears these; uninstall must
                // not depend on that having happened.
                wp_clear_scheduled_hook( 'ultimate_performance_tick' );
                wp_clear_scheduled_hook( 'ultimate_performance_janitor' );
                wp_clear_scheduled_hook( 'ultimate_performance_telemetry' );
                // M5: the SHARED cluster tables (base prefix = network-wide
                // coordination point; one DROP removes them for the whole network).
                // N5: drop ALL three cluster tables (epoch + nodes/lease + events).
                // Previously only uc_invalidation_events was dropped — uc_cluster_epoch
                // and uc_cluster_nodes leaked across uninstall, causing stale identity
                // and stale epoch state after plugin deletion. Symmetric with the
                // lazy ensure_table() calls in Epoch/Lease/EventStore.
                try {
                        global $wpdb;
                        // Duck-typed on purpose: any real $wpdb surface (and the audit
                        // suite's double) exposes query(); instanceof \wpdb would make the
                        // cleanup silently skip in test doubles.
                        if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
                                $tables = array(
                                        $wpdb->base_prefix . 'up_invalidation_events',
                                        $wpdb->base_prefix . 'up_cluster_epoch',
                                        $wpdb->base_prefix . 'up_cluster_nodes',
                                );
                                foreach ( $tables as $table ) {
                                        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL — identifier is the base prefix, not user input
                                }
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        // uninstall must complete even when the DB layer is hostile
                }
                // §0.6.5: delete BOTH cache directories — the current rebrand path
                // AND the legacy ultimate-cache path (so sites that upgraded from
                // ultimate-cache 0.6.x also leave no trace).
                self::rrmdir( self::cache_root() );
                $legacy_cache = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( ABSPATH . 'wp-content' );
                $legacy_cache = wp_normalize_path( $legacy_cache . '/cache/ultimate-cache' );
                if ( is_dir( $legacy_cache ) ) {
                        self::rrmdir( $legacy_cache );
                }
                // §0.6.5: ensure drop-ins are removed even if deactivation was
                // bypassed (e.g., plugin deleted via FTP without prior deactivation).
                $oc = WP_CONTENT_DIR . '/object-cache.php';
                if ( file_exists( $oc ) ) {
                        $c = (string) file_get_contents( $oc );
                        if ( false !== strpos( $c, 'UltimateCache' ) || false !== strpos( $c, 'Ultimate Performance' ) ) {
                                wp_delete_file( $oc );
                        }
                }
                $ac = WP_CONTENT_DIR . '/advanced-cache.php';
                if ( file_exists( $ac ) ) {
                        $c = (string) file_get_contents( $ac );
                        if ( false !== strpos( $c, 'UltimateCache' ) || false !== strpos( $c, 'Ultimate Performance' ) || false !== strpos( $c, 'ultimate-performance' ) ) {
                                wp_delete_file( $ac );
                        }
                }
                // §0.6.5: also remove the WP_CACHE line we added (in case the user
                // uninstalled without first deactivating).
                self::set_wp_cache( false );
                $role = get_role( 'administrator' );
                if ( $role ) {
                        $role->remove_cap( self::CAP_PURGE_ALL );
                }
        }

        /**
         * §0.6.6 — Rebrand migration: rename pre-0.6.6 'uc_' / 'UltimateCache'
         * artifacts to the new 'up_' / 'Ultimate Performance' brand.
         *
         * Steps (idempotent — each step is a no-op if already migrated):
         *   1. Rename DB cluster tables: uc_cluster_epoch → up_cluster_epoch,
         *      uc_cluster_nodes → up_cluster_nodes, uc_invalidation_events →
         *      up_invalidation_events. Uses RENAME TABLE IF EXISTS (each table
         *      renamed only if both: old exists AND new does not exist).
         *   2. Migrate transients: read old uc_* transient, if it has a value
         *      AND the new up_* transient is empty, copy the value to up_*,
         *      then delete uc_*. (Skips cleanly when old transient is gone.)
         *   3. Migrate cron schedule: any event scheduled with the
         *      'uc_every_minute' recurrence gets rescheduled with the new
         *      'up_every_minute' recurrence. The action hook itself
         *      (ultimate_performance_tick) is NOT renamed — only the
         *      recurrence name.
         *
         * Wrapped in try/catch so a hostile DB never blocks activation.
         */
        private static function migrate_uc_to_up_brand() {
                // STEP 1: Rename DB tables (or DROP old if new already auto-created
                // by the cluster classes). Cluster state is runtime-recoverable, so
                // dropping stale data is acceptable when both tables exist.
                try {
                        global $wpdb;
                        if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
                                $table_pairs = array(
                                        $wpdb->base_prefix . 'uc_cluster_epoch'      => $wpdb->base_prefix . 'up_cluster_epoch',
                                        $wpdb->base_prefix . 'uc_cluster_nodes'      => $wpdb->base_prefix . 'up_cluster_nodes',
                                        $wpdb->base_prefix . 'uc_invalidation_events' => $wpdb->base_prefix . 'up_invalidation_events',
                                );
                                foreach ( $table_pairs as $old => $new ) {
                                        $old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) );
                                        $new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) );
                                        if ( $old_exists === $old && $new_exists !== $new ) {
                                                // Old exists, new doesn't — RENAME preserves all data.
                                                $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
                                        } elseif ( $old_exists === $old && $new_exists === $new ) {
                                                // Both exist — new was auto-created by ensure_table() after the
                                                // new code loaded but BEFORE this migration ran. Try to copy
                                                // any non-conflicting rows from old to new, then drop old.
                                                try {
                                                        $wpdb->query( "INSERT IGNORE INTO `{$new}` SELECT * FROM `{$old}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
                                                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                                                        // Column mismatch between old and new schema — skip copy,
                                                        // data is regenerable cluster state.
                                                }
                                                $wpdb->query( "DROP TABLE IF EXISTS `{$old}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
                                        }
                                }
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        // Migration must not block activation.
                }

                // STEP 2: Migrate transients.
                $transient_pairs = array(
                        'uc_env_snapshot'      => 'up_env_snapshot',
                        'up_oc_dropin_status'  => 'up_oc_dropin_status', // already correct, skip
                        'uc_oc_dropin_status'  => 'up_oc_dropin_status',
                        'uc_registry_overflow' => 'up_registry_overflow',
                        'uc_settings_errors'   => 'up_settings_errors',
                        'uc_save_summary'      => 'up_save_summary',
                        'uc_redis_test'        => 'up_redis_test',
                        'uc_oc_runtime_test'   => 'up_oc_runtime_test',
                        'uc_amqp_test'          => 'up_amqp_test',
                        'uc_oc_action'          => 'up_oc_action',
                        'uc_queue_last_failure' => 'up_queue_last_failure',
                        'uc_queue_last_fallback' => 'up_queue_last_fallback',
                );
                foreach ( $transient_pairs as $old => $new ) {
                        if ( $old === $new ) {
                                continue;
                        }
                        $val = get_transient( $old );
                        if ( false !== $val ) {
                                $existing = get_transient( $new );
                                if ( false === $existing ) {
                                        set_transient( $new, $val, HOUR_IN_SECONDS );
                                }
                                delete_transient( $old );
                        }
                }

                // STEP 3: Migrate cron schedule.
                // The action hook name (ultimate_performance_tick) is unchanged.
                // Only the recurrence name changes from 'uc_every_minute' to
                // 'up_every_minute'. WordPress stores the recurrence as a
                // property of the scheduled event, so we clear + reschedule.
                try {
                        $next = wp_next_scheduled( 'ultimate_performance_tick' );
                        if ( $next ) {
                                wp_clear_scheduled_hook( 'ultimate_performance_tick' );
                                wp_schedule_event( time() + 60, 'up_every_minute', 'ultimate_performance_tick' );
                        }
                        $next_j = wp_next_scheduled( 'ultimate_performance_janitor' );
                        if ( $next_j ) {
                                wp_clear_scheduled_hook( 'ultimate_performance_janitor' );
                                wp_schedule_event( time() + 60, 'up_every_minute', 'ultimate_performance_janitor' );
                        }
                        $next_t = wp_next_scheduled( 'ultimate_performance_telemetry' );
                        if ( $next_t ) {
                                wp_clear_scheduled_hook( 'ultimate_performance_telemetry' );
                                wp_schedule_event( time() + 60, 'up_every_minute', 'ultimate_performance_telemetry' );
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        // Cron migration is best-effort.
                }
        }

        private static function rrmdir( $dir ) {
                if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
                        return;
                }
                $items = scandir( $dir );
                if ( false === $items ) {
                        return false === $items ? false : null; // unreachable guard kept simple
                }
                foreach ( $items as $item ) {
                        if ( '.' === $item || '..' === $item ) {
                                continue;
                        }
                        $path = $dir . '/' . $item;
                        if ( is_link( $path ) ) {
                                continue; // never follow/delete links
                        }
                        if ( is_dir( $path ) ) {
                                self::rrmdir( $path );
                        } else {
                                wp_delete_file( $path );
                        }
                }
                @rmdir( $dir );
        }
}
