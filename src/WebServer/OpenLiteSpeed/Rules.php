<?php
/**
 * OpenLiteSpeed integration rules generator (Phase O §36).
 *
 * Analog of src/WebServer/Nginx/Rules.php but for OLS. Emits OLS-compatible
 * rewrite + cache directives that the admin pastes into the OLS vhost's
 * "Context" / "Rewrite" config panel. The plugin never writes to OLS's own
 * config files — only EMITS the snippet for the admin to apply.
 *
 * Design (mirrors the Apache/Nginx early-serve contract):
 *   GET/HEAD + cookieless + no query string
 *     → rewrite into /up-cache/<host><path>/index.html
 *     → OLS serves the file STATICALLY (cache location)
 *     → not found: rewrite falls through to /index.php (the WP front
 *       controller) — the plugin's Engine then owns classification, store,
 *       and headers exactly as without OLS.
 *
 * Security rules (Phase O §37):
 *   - wp-config.php / .env / .git/* → deny (return 403)
 *   - Direct cache-tree access (/wp-content/cache/ultimate-performance/v/) → deny
 *   - Encoded traversal (../%2e) → deny via OLS's normalize/forbid
 *   - Host header is NEVER an authorization input (canonical_host() gate)
 *
 * Cookie bypass rules (Phase O §37):
 *   - wordpress_logged_in_* cookie present → bypass cache (proxy to PHP)
 *   - woocommerce_cart_hash / wp_woocommerce_session_* → bypass (cart/checkout)
 *   - comment_author_* / wp-postpass → bypass (logged-in visitor state)
 *
 * @package UltimatePerformance\WebServer\OpenLiteSpeed
 */

namespace UltimatePerformance\WebServer\OpenLiteSpeed;

defined( 'ABSPATH' ) || exit;

final class Rules {

        const CACHE_PREFIX = '/up-cache/';

        /**
         * Generate the OLS rewrite rules snippet for the given host.
         *
         * @param string $host              Canonical site host (no scheme/port).
         * @param string $cookie_rx         Logged-in / cart / session cookie regex.
         * @param array  $extra_directives  Optional extra lines (e.g. custom TTL).
         * @return string OLS rewrite rules. Empty string on bad input (fail-closed).
         */
        public static function generate( $host, $cookie_rx = '', array $extra_directives = array() ) {
                $host = (string) $host;
                if ( '' === $host || ! self::valid_host( $host ) ) {
                        return ''; // fail-closed
                }
                $cookie_rx = (string) $cookie_rx;
                if ( '' === $cookie_rx ) {
                        // Default WP + Woo cookie bypass regex
                        $cookie_rx = 'wordpress_[a-f0-9]{32}|wordpress_logged_in_[a-f0-9]{32}|wordpress_sec_[a-f0-9]{32}|wp-postpass|comment_author_[a-f0-9]{32}|woocommerce_cart_hash|woocommerce_items_in_cart|wp_woocommerce_session_';
                }

                // Escape special chars for OLS RewriteCond
                $host_safe = self::escape_host( $host );

                $lines = array();
                $lines[] = '# Ultimate Performance — OpenLiteSpeed rewrite rules (auto-generated)';
                $lines[] = '# Apply inside the OLS vhost Context for ' . $host . ' (or the httpd_config listener).';
                $lines[] = '# Phase O §36-37 — bypass logic, security, cookie handling.';
                $lines[] = '';
                $lines[] = 'RewriteEngine On';
                $lines[] = '';
                $lines[] = '# --- Security rules (deny sensitive files / paths) -------------------------';
                $lines[] = 'RewriteRule ^/?wp-config\.php$ - [F,L]';
                $lines[] = 'RewriteRule ^/?\.env$ - [F,L]';
                $lines[] = 'RewriteRule ^/?\.git/ - [F,L]';
                $lines[] = 'RewriteRule ^/?wp-content/cache/ultimate-performance/v/ - [F,L]';
                $lines[] = 'RewriteRule ^/?.*%2e%2e.*$ - [F,L]  # encoded traversal deny';
                $lines[] = '';
                $lines[] = '# --- Cookie bypass (logged-in / cart / session / comment → PHP) -------------';
                $lines[] = 'RewriteCond %{HTTP_COOKIE} ' . $cookie_rx . ' [NC]';
                $lines[] = 'RewriteRule ^.*$ - [L]  # bypass cache, fall through to PHP';
                $lines[] = '';
                $lines[] = '# --- Query string bypass (?key=value → PHP) --------------------------------';
                $lines[] = 'RewriteCond %{QUERY_STRING} .+';
                $lines[] = 'RewriteRule ^.*$ - [L]  # bypass cache, fall through to PHP';
                $lines[] = '';
                $lines[] = '# --- POST bypass (any non-GET/HEAD method → PHP) --------------------------';
                $lines[] = 'RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)$';
                $lines[] = 'RewriteRule ^.*$ - [L]';
                $lines[] = '';
                $lines[] = '# --- Page cache early serve (GET/HEAD + cookieless + no query) -----------';
                $lines[] = '# Map /path → /up-cache/<host>/<path>/index.html';
                $lines[] = 'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$';
                $lines[] = 'RewriteCond %{HTTP_COOKIE} !^.*(' . $cookie_rx . ').*$ [NC]';
                $lines[] = 'RewriteCond %{QUERY_STRING} ^$';
                $lines[] = 'RewriteCond %{HTTP_HOST} ^' . $host_safe . '$ [NC]';
                $lines[] = '# Try the cache file; if missing, fall through to /index.php (WP)';
                $lines[] = 'RewriteRule ^(.*)$ /up-cache/' . $host_safe . '/$1/index.html [L]';
                $lines[] = '';
                $lines[] = '# --- Fallthrough to WP front controller -----------------------------------';
                $lines[] = 'RewriteRule ^/?(index\.php)?$ /index.php [L]';

                foreach ( $extra_directives as $line ) {
                        $lines[] = (string) $line;
                }

                return implode( "\n", $lines ) . "\n";
        }

        /**
         * Validate a hostname (RFC 952 / RFC 1123 simplified — no scheme/port/path).
         *
         * @param string $host
         * @return bool
         */
        private static function valid_host( $host ) {
                // Allow letters, digits, hyphens, dots, trailing port is REJECTED
                if ( ! preg_match( '/^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}$/i', (string) $host ) ) {
                        return false;
                }
                // Reject obvious path traversal / encoded chars
                if ( false !== strpos( (string) $host, '/' ) || false !== strpos( (string) $host, '\\' ) ) {
                        return false;
                }
                // Length check (DNS limit)
                if ( strlen( (string) $host ) > 253 ) {
                        return false;
                }
                return true;
        }

        /**
         * Escape a hostname for use in OLS RewriteCond (RegexEscape).
         * Dots become \. to prevent regex wildcard matching other hosts.
         *
         * @param string $host
         * @return string
         */
        private static function escape_host( $host ) {
                $h = str_replace( '.', '\\.', (string) $host );
                return $h;
        }

        /**
         * Path of the cache probe file (admin verify-probe).
         *
         * @param string $token 16-hex token.
         * @return string
         */
        public static function probe_uri( $token ) {
                $token = preg_replace( '/[^0-9a-f]/', '', (string) $token );
                if ( '' === $token || strlen( $token ) < 8 ) {
                        return '';
                }
                return '/uc-verify-' . substr( $token, 0, 16 );
        }

        /**
         * Probe body bytes (compared byte-identical with the served response).
         *
         * @param string $token
         * @return string
         */
        public static function probe_body( $token ) {
                $token = preg_replace( '/[^0-9a-f]/', '', (string) $token );
                if ( '' === $token ) {
                        return '';
                }
                return 'UC-VERIFY-PROBE-BODY-' . substr( $token, 0, 16 );
        }
}
