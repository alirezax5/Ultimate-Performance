<?php
/**
 * FallbackServer — PHP-level cache serving for shared hosting.
 *
 * HARDEN-5: When advanced-cache.php is installed, this class is called
 * BEFORE WordPress fully boots. It checks the cache and serves the cached
 * response with minimal PHP overhead, then exits. On MISS, it returns
 * control to WordPress (which will boot fully and let the Engine capture
 * the response for caching).
 *
 * Safety: all bypass rules from the Classifier are respected. POST,
 * logged-in users, cart/checkout/account, Woo session cookies, etc. are
 * NEVER served from the fallback cache.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Compatibility;

use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Request\Classifier;
use UltimatePerformance\Security\ResponseSanitizer;

defined( 'ABSPATH' ) || exit;

final class FallbackServer {

        /**
         * Serve a cached response if possible. Exits on HIT; returns on MISS.
         */
        public static function serve() {
                // Bypass for any method that isn't GET/HEAD.
                $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
                if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
                        return; // POST, PUT, DELETE, etc. → always dynamic.
                }

                // BENCH-D5/HARDEN-5: The drop-in runs BEFORE wp-settings.php loads
                // wp-includes/option.php, so get_option() is NOT available. We read
                // the settings directly from the DB using the credentials in wp-config.php
                // (DB_NAME, DB_USER, DB_PASSWORD, DB_HOST are already defined).
                $settings = self::load_settings_early();
                if ( ! $settings ) {
                        return; // Can't load settings → let WP boot normally.
                }
                if ( empty( $settings['enabled'] ) || empty( $settings['page_cache_enabled'] ) ) {
                        return; // Page cache disabled.
                }
                // Resolve the real site base path BEFORE classification. WordPress
                // may live in a subdirectory (http://host/wordpress) and Host:
                // alone cannot reveal that; bypass slugs are site-relative.
                $settings['home_url_early'] = self::resolve_site_url_early( $settings );

                // Simplified cacheability check (cookies, bypass paths).
                if ( ! self::is_cacheable_request( $settings ) ) {
                        return; // Bypass (logged-in, cart, checkout, etc.).
                }

                // Compute the cache path directly, WITHOUT using Key/Store/SafeFs
                // (which depend on WP functions not yet loaded at this early stage).
                // We replicate the minimal logic: host + path → cache file path.
                $scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || 443 === (int) ( $_SERVER['SERVER_PORT'] ?? 0 ) ? 'https' : 'http';
                $host   = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
                // Strip port from host (same as Key::canonical_host) — HTTP_HOST includes port
                // e.g. "127.0.0.1:8095" → "127.0.0.1", "example.com:443" → "example.com"
                if ( false !== strpos( $host, ':' ) ) {
                        $host = substr( $host, 0, strpos( $host, ':' ) );
                }
                $uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
                $parts  = parse_url( $uri ); // PHP builtin, not wp_parse_url
                $path   = isset( $parts['path'] ) ? $parts['path'] : '/';

                // Normalize path (remove double slashes, trailing dot, etc.)
                $path = preg_replace( '#/{2,}#', '/', $path );

                // Sanitize host for directory name (same as Key::host_dir).
                $host_dir = preg_replace( '/[^a-z0-9.\[\]\-]/', '', $host );
                if ( '' === $host_dir ) {
                        return;
                }

                // Build the cache file path (same logic as Key::dir_for + Key::absolute).
                $segs = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
                $safe = array();
                foreach ( $segs as $seg ) {
                        $folded = strtolower( $seg );
                        if ( preg_match( '/^[a-z0-9][a-z0-9._\-]{0,63}$/', $folded ) && false === strpos( $folded, '..' ) ) {
                                $safe[] = $folded;
                        } else {
                                // Hashed segment — fallback can't serve this (let WP handle).
                                return;
                        }
                }
                if ( empty( $safe ) ) {
                        $safe = array( 'uc-root' ); // ROOT_SENTINEL
                }

                // Depth cap (same as Key::MAX_DEPTH = 6).
                if ( count( $safe ) > 6 ) {
                        return; // Too deep — let WP/Engine handle.
                }

                $cache_root = WP_CONTENT_DIR . '/cache/ultimate-performance';
                $rel_path   = implode( '/', $safe );
                $cache_file = $cache_root . '/v/' . $host_dir . '/' . $rel_path . '/index.html';
                $meta_file  = $cache_file . '.meta.json';

                // Check if cache file exists and is readable.
                if ( ! file_exists( $cache_file ) || ! is_readable( $cache_file ) ) {
                        return; // MISS → let WP render.
                }

                // Check freshness via meta file.
                $ttl = 3600; // default
                $created = 0;
                $meta_headers = array();
                $meta_status = 200;
                if ( file_exists( $meta_file ) && is_readable( $meta_file ) ) {
                        $meta = json_decode( (string) file_get_contents( $meta_file ), true );
                        if ( is_array( $meta ) ) {
                                $ttl = isset( $meta['ttl'] ) ? max( 30, (int) $meta['ttl'] ) : 3600;
                                $created = isset( $meta['created'] ) ? (int) $meta['created'] : 0;
                                $meta_headers = isset( $meta['headers'] ) && is_array( $meta['headers'] ) ? $meta['headers'] : array();
                                $meta_status = isset( $meta['status'] ) ? (int) $meta['status'] : 200;
                        }
                }

                $now = time();
                $age = $created > 0 ? ( $now - $created ) : $ttl + 1; // treat unknown as stale
                $is_fresh = $age <= $ttl;

                if ( ! $is_fresh ) {
                        // Stale — check SWR.
                        $swr_enabled = ! empty( $settings['swr_enabled'] );
                        $grace = isset( $settings['swr_grace'] ) ? (int) $settings['swr_grace'] : 300;
                        $is_stale = $swr_enabled && $grace > 0 && $age <= $ttl + $grace;
                        if ( ! $is_stale ) {
                                return; // Expired → let WP render.
                        }
                        // Serve stale below.
                }

                // Serve the cached response.
                $body = (string) file_get_contents( $cache_file );

                // Defense in depth: basic size check (sanitizer requires >64 bytes).
                if ( strlen( $body ) < 64 ) {
                        return;
                }

                // Emit headers.
                foreach ( $meta_headers as $name => $value ) {
                        header( (string) $name . ': ' . substr( str_replace( array( "\r", "\n" ), '', (string) $value ), 0, 512 ) );
                }
                header( 'X-Ultimate-Performance: FALLBACK-HIT' );
                http_response_code( $meta_status );

                echo $body;

                if ( ! defined( 'ULTIMATE_PERFORMANCE_TESTING' ) || ! ULTIMATE_PERFORMANCE_TESTING ) {
                        exit;
                }
        }

        /**
         * Load settings directly from the DB, bypassing get_option().
         * Uses the DB credentials defined in wp-config.php.
         *
         * @return array|null Settings array, or null if DB unavailable.
         */
        private static function load_settings_early() {
                if ( ! defined( 'DB_NAME' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_HOST' ) ) {
                        // In test environments without DB constants, fall back to defaults
                        // (the test can use the filter to inject custom settings).
                        $defaults = Settings::defaults();
                        $defaults['enabled'] = true;
                        $defaults['page_cache_enabled'] = true;
                        return apply_filters( 'ultimate_performance_fallback_settings', $defaults );
                }
                if ( ! defined( 'ABSPATH' ) ) {
                        return null;
                }
                // Read table prefix from wp-config.php (it's defined there).
                $table_prefix = defined( '$table_prefix' ) ? $table_prefix : 'wp_';
                // Actually $table_prefix is a variable, not a constant. Read it from globals.
                global $table_prefix;
                $table_prefix = $table_prefix ?: 'wp_';

                $mysqli = @new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
                if ( $mysqli->connect_errno ) {
                        return null;
                }
                $option_name = 'ultimate_performance_settings';
                $stmt = $mysqli->prepare( "SELECT option_value FROM {$table_prefix}options WHERE option_name = ? LIMIT 1" );
                if ( ! $stmt ) {
                        $mysqli->close();
                        return null;
                }
                $stmt->bind_param( 's', $option_name );
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                $mysqli->close();

                if ( ! $row || ! isset( $row['option_value'] ) ) {
                        return Settings::defaults();
                }
                // Use PHP's unserialize directly (WP's maybe_unserialize not loaded yet).
                $raw = $row['option_value'];
                $stored = @unserialize( $raw );
                if ( false === $stored && $raw !== 'b:0;' ) {
                        // Not serialized → maybe it's JSON or raw.
                        $stored = json_decode( $raw, true );
                        if ( ! is_array( $stored ) ) {
                                return Settings::defaults();
                        }
                }
                if ( ! is_array( $stored ) ) {
                        return Settings::defaults();
                }
                return array_merge( Settings::defaults(), $stored );
        }

        /**
         * Build a Settings object from an array, bypassing get_option().
         *
         * @param array $data Settings data.
         * @return Settings
         */
        private static function build_settings_object( $data ) {
                // Use reflection to create Settings without calling get_option().
                $ref = new \ReflectionProperty( Settings::class, 'instance' );
                $ref->setAccessible( true );
                $inst = $ref->getValue();
                if ( null !== $inst ) {
                        return $inst;
                }
                // Create via reflection (bypass constructor which calls get_option).
                $class = new \ReflectionClass( Settings::class );
                $inst = $class->newInstanceWithoutConstructor();
                $data_prop = $class->getProperty( 'data' );
                $data_prop->setAccessible( true );
                $data_prop->setValue( $inst, is_array( $data ) ? $data : Settings::defaults() );
                $ref->setValue( null, $inst );
                return $inst;
        }

        /**
         * Simplified cacheability check for the fallback path.
         * Checks: cookies (logged-in, cart, session), bypass paths (cart, checkout, etc.)
         *
         * @param array $settings Settings array.
         * @return bool True if the request might be cacheable.
         */
        private static function is_cacheable_request( $settings ) {
                // Check cookies.
                $cookie_regex = isset( $settings['cookie_bypass_regex'] ) ? $settings['cookie_bypass_regex'] : '';
                if ( '' !== $cookie_regex && ! empty( $_COOKIE ) ) {
                        $rx = '~(' . str_replace( '~', '\~', $cookie_regex ) . ')~';
                        foreach ( array_keys( $_COOKIE ) as $name ) {
                                if ( 1 === @preg_match( $rx, (string) $name ) ) {
                                        return false; // Sensitive cookie → bypass.
                                }
                        }
                }

                // Check bypass paths (site-relative: /wordpress/cart must match 'cart').
                $bypass_paths = isset( $settings['bypass_paths'] ) ? $settings['bypass_paths'] : array();
                $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
                $parts = parse_url( $uri ); // PHP builtin, not wp_parse_url
                $path = isset( $parts['path'] ) ? $parts['path'] : '/';
                // Strip the WP base path (home_url path) so a WordPress installed
                // in a subdirectory still resolves bypass slugs correctly.
                $base = (string) parse_url( (string) self::home_url_early( $settings ), PHP_URL_PATH );
                $base = '/' . trim( $base, '/' );
                if ( '/' !== $base && 0 === stripos( $path, $base ) ) {
                        $path = '/' . ltrim( substr( $path, strlen( $base ) ), '/' );
                }
                foreach ( $bypass_paths as $slug ) {
                        if ( '' !== $slug && preg_match( '#^/' . preg_quote( $slug, '#' ) . '(/|$)#i', $path ) ) {
                                return false; // Bypass path → not cacheable.
                        }
                }

                // WooCommerce private/mutation surfaces must never be served here
                // even when the admin bypass-path list is incomplete.
                //
                // Resolve the request method in THIS scope. $method is a local
                // of serve(); it is not visible here (that was the undefined-
                // variable warning). Use the same canonical resolution as
                // Classifier::classify(): $_SERVER['REQUEST_METHOD'], uppercased.
                //
                // Conservative default: serve() already rejected anything that
                // isn't GET/HEAD, so the only way to reach this branch with an
                // unknown method is a missing REQUEST_METHOD (CLI/cron/some
                // server APIs). Treat that as a non-cacheable mutation (POST)
                // rather than a cacheable GET — never widen cache eligibility
                // merely to silence a warning.
                $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'POST';
                $query = isset( $parts['query'] ) ? $parts['query'] : '';
                $woo_bypass = isset( $settings['woo_bypass'] ) ? (bool) $settings['woo_bypass'] : true;
                if ( $woo_bypass && '' !== Classifier::woo_gate( $path, $query, $method ) ) {
                        return false;
                }

                // Check for query strings (simplified: bypass if any query present).
                if ( '' !== trim( $query ) ) {
                        return false; // Query string → bypass (let Engine handle classification).
                }

                return true;
        }

        /**
         * Best-effort home_url before WordPress is booted.
         *
         * The drop-in runs before wp-includes/option.php exists, so
         * home_url() is unavailable. We read the stored option directly
         * from the DB (already opened by load_settings_early); the value is
         * used only to compute the site base path for slug matching.
         *
         * @param array $settings Settings array (may carry a resolved home url).
         * @return string
         */
        private static function home_url_early( $settings ) {
                if ( ! empty( $settings['home_url_early'] ) && is_string( $settings['home_url_early'] ) ) {
                        return $settings['home_url_early'];
                }
                if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
                        $scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
                        return $scheme . '://' . (string) $_SERVER['HTTP_HOST'];
                }
                return 'http://localhost';
        }

        /**
         * Read the site's real home_url from the DB, before WP boots.
         *
         * Classifying a subdirectory WordPress needs the base path (e.g.
         * /wordpress), which the Host header cannot supply. We open a short
         * mysqli connection to read wp_options:home. Falls back to the
         * Host-header guess when the DB is unavailable or the row is absent
         * (in which case classification simply degrades to absolute matching).
         *
         * @param array $settings Settings array.
         * @return string Absolute home URL.
         */
        private static function resolve_site_url_early( $settings ) {
                if ( ! defined( 'DB_NAME' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_HOST' ) || ! defined( 'ABSPATH' ) ) {
                        return self::home_url_early( $settings );
                }
                $table_prefix = 'wp_';
                if ( isset( $GLOBALS['table_prefix'] ) && is_string( $GLOBALS['table_prefix'] ) && '' !== $GLOBALS['table_prefix'] ) {
                        $table_prefix = $GLOBALS['table_prefix'];
                }
                $url = null;
                try {
                        $mysqli = @new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
                        if ( ! $mysqli->connect_errno ) {
                                $names = array( 'home', 'siteurl' );
                                foreach ( $names as $name ) {
                                        $stmt = $mysqli->prepare( "SELECT option_value FROM {$table_prefix}options WHERE option_name = ? LIMIT 1" );
                                        if ( ! $stmt ) {
                                                continue;
                                        }
                                        $stmt->bind_param( 's', $name );
                                        $stmt->execute();
                                        $row = $stmt->get_result()->fetch_assoc();
                                        $stmt->close();
                                        if ( ! empty( $row['option_value'] ) && is_string( $row['option_value'] ) ) {
                                                $url = $row['option_value'];
                                                break;
                                        }
                                }
                                $mysqli->close();
                        }
                } catch ( \Throwable $e ) {
                        return self::home_url_early( $settings );
                }
                return is_string( $url ) ? $url : self::home_url_early( $settings );
        }
}
