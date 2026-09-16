<?php
/**
 * EnvironmentDetector — detects the active cache mode and server environment.
 *
 * HARDEN-6: Provides admin-visible health status so users never silently run
 * with zero page-cache benefit. Detects:
 *   - Server type (Nginx, Apache, LiteSpeed/OLS, etc.)
 *   - Whether server-accelerated mode is active (Nginx try_files rule)
 *   - Whether PHP fallback mode is active (advanced-cache.php drop-in)
 *   - Cache directory writability
 *   - Homepage cache health via verify probe
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Core;

defined( 'ABSPATH' ) || exit;

final class EnvironmentDetector {

        const MODE_SERVER_ACCELERATED = 'SERVER_ACCELERATED';
        const MODE_PHP_FALLBACK        = 'PHP_FALLBACK';
        const MODE_HYBRID             = 'HYBRID';
        const MODE_MISCONFIGURED      = 'MISCONFIGURED';
        const MODE_DISABLED           = 'DISABLED';
        const MODE_CONFLICTED         = 'CONFLICTED';

        /**
         * Detect the web server software from SERVER_SOFTWARE.
         *
         * @return string 'nginx' | 'apache' | 'litespeed' | 'ols' | 'iis' | 'unknown'
         */
        public static function detect_server() {
                $s = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
                if ( '' === $s && function_exists( 'apache_get_modules' ) ) {
                        return 'apache';
                }
                // J3: Check OpenLiteSpeed BEFORE LiteSpeed — 'openlitespeed' contains
                // 'litespeed' and would be swallowed by the generic match.
                if ( false !== strpos( $s, 'openlitespeed' ) ) {
                        return 'ols';
                }
                if ( false !== strpos( $s, 'litespeed' ) ) {
                        return 'litespeed';
                }
                if ( false !== strpos( $s, 'nginx' ) ) {
                        return 'nginx';
                }
                if ( false !== strpos( $s, 'apache' ) ) {
                        return 'apache';
                }
                if ( false !== strpos( $s, 'microsoft-iis' ) ) {
                        return 'iis';
                }
                return 'unknown';
        }

        /**
         * Detect the active cache mode by checking:
         *   1. Is page_cache_enabled in settings?
         *   2. Is the cache root writable?
         *   3. Is the advanced-cache.php drop-in owned by us?
         *   4. Is there a foreign drop-in conflict?
         *
         * Note: TRUE server-accelerated detection requires a live verify probe
         * (request a test URL and check if Nginx served it statically). This
         * method returns the CONFIGURED mode; the self-test verifies it.
         *
         * @return array{mode:string, server:string, details:array}
         */
        public static function detect_mode() {
                $settings = Settings::instance();
                $server   = self::detect_server();

                if ( ! $settings->get( 'enabled' ) ) {
                        return array(
                                'mode'    => self::MODE_DISABLED,
                                'server'  => $server,
                                'details' => array( 'reason' => 'Plugin disabled' ),
                        );
                }
                if ( ! $settings->get( 'page_cache_enabled' ) ) {
                        return array(
                                'mode'    => self::MODE_DISABLED,
                                'server'  => $server,
                                'details' => array( 'reason' => 'Page cache disabled in settings' ),
                        );
                }

                // Check cache root.
                if ( ! Installer::ensure_cache_root() ) {
                        return array(
                                'mode'    => self::MODE_MISCONFIGURED,
                                'server'  => $server,
                                'details' => array( 'reason' => 'Cache directory not writable' ),
                        );
                }

                // Check advanced-cache.php drop-in ownership.
                $ac_path = WP_CONTENT_DIR . '/advanced-cache.php';
                $has_dropin = file_exists( $ac_path );
                $our_dropin = false;
                $foreign_dropin = false;
                if ( $has_dropin && is_readable( $ac_path ) ) {
                        $head = (string) file_get_contents( $ac_path, false, null, 0, 256 );
                        if ( false !== strpos( $head, 'Ultimate Performance advanced-cache.php' ) ) {
                                $our_dropin = true;
                        } else {
                                $foreign_dropin = true;
                        }
                }

                // Check WP_CACHE constant.
                $wp_cache_active = ( defined( 'WP_CACHE' ) && WP_CACHE );

                // Determine mode.
                $details = array(
                        'server'           => $server,
                        'cache_root_ok'    => true,
                        'has_dropin'       => $has_dropin,
                        'our_dropin'       => $our_dropin,
                        'foreign_dropin'   => $foreign_dropin,
                        'wp_cache_active'  => $wp_cache_active,
                        'php_fallback_setting' => (bool) $settings->get( 'php_fallback_enabled', true ),
                );

                if ( $foreign_dropin ) {
                        return array(
                                'mode'    => self::MODE_CONFLICTED,
                                'server'  => $server,
                                'details' => array_merge( $details, array( 'reason' => 'Foreign advanced-cache.php drop-in detected' ) ),
                        );
                }

                // §0.6.8 — Detect server acceleration via HTTP response headers.
                // When Nginx/Apache/LiteSpeed is configured to serve cache directly
                // (try_files pointing to the /up-cache/ or similar), the response
                // includes the X-Ultimate-Performance: HIT header (set by the web server,
                // NOT by PHP). When PHP serves the page, the header is set by the
                // PHP fallback drop-in as "FALLBACK-HIT".
                //
                // We can detect server acceleration by issuing an internal HTTP
                // request and inspecting the response header. To avoid network
                // round-trips on every admin page load, this is only done when:
                //   1. The user is on the Server Integration tab (handled by
                //      handle_nginx_verify()), OR
                //   2. The cached probe transient 'up_nginx_probe' says 'active'.
                //
                // Here we read the transient — the actual probe happens via the
                // admin's "Verify Nginx rules" button.
                $server_acceleration_verified = false;
                if ( function_exists( 'get_transient' ) ) {
                        $probe = get_transient( 'up_nginx_probe' );
                        if ( is_array( $probe ) && isset( $probe['ok'] ) && 'active' === $probe['why'] ) {
                                $server_acceleration_verified = true;
                        }
                }
                $details['server_acceleration_verified'] = $server_acceleration_verified;

                // §0.6.8 — HYBRID mode: best of both worlds. Both the web server
                // direct-serve AND the PHP fallback drop-in are configured. This
                // means cached requests are served by Nginx (zero PHP), and any
                // request that bypasses the web-server rules still gets served
                // from the PHP fallback drop-in (skipping most of WP bootstrap).
                if ( $server_acceleration_verified && $our_dropin && $wp_cache_active ) {
                        return array(
                                'mode'    => self::MODE_HYBRID,
                                'server'  => $server,
                                'details' => array_merge( $details, array(
                                        'reason' => 'Server acceleration verified + PHP fallback drop-in active. Best of both: zero-PHP HITs on cached pages, with PHP fallback for edge cases.',
                                ) ),
                        );
                }

                // §0.6.8 — Pure server-accelerated mode (no PHP fallback drop-in,
                // or drop-in disabled by user). Nginx serves cache directly.
                if ( $server_acceleration_verified ) {
                        return array(
                                'mode'    => self::MODE_SERVER_ACCELERATED,
                                'server'  => $server,
                                'details' => array_merge( $details, array(
                                        'reason' => 'Server acceleration verified — web server serves cached pages directly (zero PHP).',
                                ) ),
                        );
                }

                // If our drop-in is active, PHP fallback is available.
                if ( $our_dropin && $wp_cache_active ) {
                        return array(
                                'mode'    => self::MODE_PHP_FALLBACK,
                                'server'  => $server,
                                'details' => $details,
                        );
                }

                // No drop-in AND no server acceleration confirmed.
                // Do NOT assume SERVER_ACCELERATED just because the server is Nginx —
                // detecting the web server is NOT the same as verifying that Nginx
                // try_files rules are actually configured. Without a drop-in, the
                // plugin has no confirmed serving path. Report as MISCONFIGURED
                // (the admin should either install the drop-in for PHP fallback
                // or configure Nginx rules for server acceleration).
                return array(
                        'mode'    => self::MODE_MISCONFIGURED,
                        'server'  => $server,
                        'details' => array_merge( $details, array(
                                'reason' => 'No cache serving path confirmed. Install the PHP fallback drop-in or configure Nginx server acceleration rules.',
                                'nginx_detected' => 'nginx' === $server,
                                'nginx_acceleration_verified' => false,
                        ) ),
                );
        }

        /**
         * Run a self-test probe: write a unique probe cache file, then check
         * whether it exists and is readable.
         *
         * @return array{cache_writable:bool, probe_written:bool, probe_readable:bool, probe_url:string}
         */
        public static function run_self_test() {
                $settings = Settings::instance();
                $keygen   = new \UltimatePerformance\CacheKey\Key( $settings );
                $fs       = new SafeFs();
                $store    = new \UltimatePerformance\PageCache\Store( $fs, $keygen );

                $token = substr( md5( uniqid( '', true ) ), 0, 16 );
                $rel_dir = 'self-test.local/uc-verify-' . $token;
                $body = 'ultimate-performance-self-test:' . $token . ':' . time();

                $written = $store->write(
                        $rel_dir,
                        $body,
                        200,
                        array( 'Content-Type' => 'text/plain' ),
                        array(),
                        60
                );

                $probe_file = $keygen->absolute( $rel_dir );
                $readable = $written && file_exists( $probe_file ) && is_readable( $probe_file );

                // Cleanup.
                $store->purge( $rel_dir );

                return array(
                        'cache_writable'  => (bool) $written,
                        'probe_written'   => (bool) $written,
                        'probe_readable'  => $readable,
                        'probe_token'     => $token,
                );
        }

        /**
         * Get a human-readable status summary for admin display.
         *
         * @return array{mode_label:string, mode_color:string, details:array}
         */
        public static function get_status_summary() {
                $detected = self::detect_mode();
                $labels = array(
                        self::MODE_SERVER_ACCELERATED => array( 'Server Accelerated', 'green' ),
                        self::MODE_HYBRID             => array( 'Hybrid (Best)', 'green' ),
                        self::MODE_PHP_FALLBACK       => array( 'PHP Compatibility', 'orange' ),
                        self::MODE_MISCONFIGURED      => array( 'Misconfigured', 'red' ),
                        self::MODE_DISABLED           => array( 'Disabled', 'gray' ),
                        self::MODE_CONFLICTED         => array( 'Conflicted', 'red' ),
                );
                $label = isset( $labels[ $detected['mode'] ] ) ? $labels[ $detected['mode'] ] : array( 'Unknown', 'gray' );

                return array(
                        'mode_label'  => $label[0],
                        'mode_color'  => $label[1],
                        'mode'        => $detected['mode'],
                        'server'      => $detected['server'],
                        'details'     => $detected['details'],
                );
        }
}
