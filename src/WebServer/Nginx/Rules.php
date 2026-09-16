<?php
/**
 * Nginx integration rules generator (admin-applied snippet).
 *
 * ARCHITECTURE.md contract: the plugin never claims the nginx integration is
 * "active" until the admin applies the snippet AND a verify probe is served
 * statically (see probe_uri()/probe_body() and the M3 live suite). This class
 * only EMITS configuration; it never writes to nginx's own config files.
 *
 * Design (mirrors the Apache early-serve contract):
 *   GET/HEAD + cookieless + no query string
 *     → internal rewrite into /up-cache/<host><path>/index.html
 *     → alias maps /up-cache/ onto <cache_root>/v/ (the Store's tree)
 *     → found: served statically, PHP NEVER runs (live gate proves this via
 *       an origin-side execution counter).
 *     → not found (uncached / hashed deep paths / dynamic): try_files falls
 *       through to a named fallback that proxies the ORIGINAL request URI to
 *       the PHP origin — the plugin's Engine then owns classification, store,
 *       and headers exactly as without nginx.
 *
 * Host handling: the snippet maps the GENERATED host literal only. A poisoned
 * Host header therefore cannot select ANOTHER site's cache tree (each
 * server block maps its own); it may receive this site's anonymous static
 * page — the same bytes every anonymous visitor gets. Dynamic requests are
 * proxied with the real Host header and the plugin's canonical_host() gate
 * owns them. The Host header is never an authorization input anywhere.
 *
 * Mapping contract with CacheKey\Key:
 *   no-query, ≤6 safe segments → v/<host>/<segments...>/index.html
 *   root "/"                   → v/<host>/<Key::segment('(root)')>/index.html
 *   allowlisted queries        → "@q<sha1>" suffixed dirs — NOT statically
 *                                mapped (they are proxied and served by the
 *                                Engine's own lookup, which computes the full
 *                                dir). Unsafe segments hash at the PHP layer
 *                                only; nginx static serving simply misses and
 *                                falls back. Writer may hash, reader never
 *                                invents — the miss direction is always safe.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\WebServer\Nginx;

use UltimatePerformance\CacheKey\Key;

defined( 'ABSPATH' ) || exit;

final class Rules {

        const BEGIN = '# BEGIN Ultimate Performance (nginx)';
        const END   = '# END Ultimate Performance (nginx)';

        /**
         * Cache-tree URI prefix used INSIDE the snippet (internal-only location).
         * Chosen to be namespace-distinct from typical WP paths.
         */
        const INTERNAL_PREFIX = '/up-cache/';

        /**
         * Emit the complete http-context include: the maps (http-level only in
         * nginx) plus the ready-made server{} block. The admin adds ONE line
         * to their nginx.conf:  include /path/ultimate-performance-nginx.conf;
         *
         * Gate design: the static-serving decision is a COMPOSED MAP
         * (method|cookieless|no-query) evaluated at the http level, and each
         * location carries exactly ONE if whose body is only a rewrite-last.
         * This avoids the nginx "if" trap (a matching if with a bare set
         * creates an implicit pseudo-location that swallows the request
         * before try_files ever runs — M3-T5, found live in the matrix).
         *
         * @param array<string,string> $o {
         *     @type string $host       Public host of the site (canonicalized).
         *     @type string $cache_root Absolute path to wp-content/cache/ultimate-performance.
         *     @type string $origin     IP:port of the PHP origin (e.g. 127.0.0.1:8098).
         *     @type string $listen     listen directive value (e.g. 127.0.0.1:8097).
         *     @type string $docroot    Absolute path to the WordPress docroot.
         * }
         * @return string Snippet, or '' when any input is unusable (fail closed).
         */
        public static function generate( $o ) {
                $o = (array) $o;

                $host = Key::canonical_host( (string) ( $o['host'] ?? '' ) );
                if ( '' === $host ) {
                        return ''; // refuse to emit for a hostile/unparseable host.
                }
                $host_dir = self::host_dir( $host );
                if ( '' === $host_dir ) {
                        return '';
                }

                $cache_root = (string) ( $o['cache_root'] ?? '' );
                if ( '' === $cache_root || '/' !== $cache_root[0] || false !== strpos( $cache_root, '..' ) ) {
                        return ''; // absolute, traversal-free path required.
                }
                if ( preg_match( '/[\r\n\0]/', $cache_root ) ) {
                        return ''; // newline/control injection can never appear in the config.
                }
                $cache_root = rtrim( $cache_root, '/' );

                $docroot = (string) ( $o['docroot'] ?? '' );
                if ( '' === $docroot || '/' !== $docroot[0] || false !== strpos( $docroot, '..' ) || preg_match( '/[\r\n\0]/', $docroot ) ) {
                        return '';
                }
                $docroot = rtrim( $docroot, '/' );

                $listen = (string) ( $o['listen'] ?? '' );
                if ( ! preg_match( '/^[0-9.]+:[1-9][0-9]{1,4}$/', $listen ) ) {
                        return '';
                }

                $origin = (string) ( $o['origin'] ?? '' );
                if ( ! preg_match( '/^(?:\d{1,3}(?:\.\d{1,3}){3}):[1-9][0-9]{1,4}$/', $origin ) ) {
                        return ''; // IP:port literal only (no resolver needed at runtime).
                }

                // BENCH-D5 (HARDEN-2): Physical directory name for the site root —
                // MUST match what Key::dir_for() writes for the "/" path. Uses the
                // ROOT_SENTINEL constant so the PHP writer and Nginx rule are
                // guaranteed to agree (previously both used Key::segment('(root)')
                // which hashed to 'h<sha1[:20]>' — consistent between PHP and Nginx
                // but broke simple hand-written try_files rules that expected
                // v/<host>/index.html).
                $root_phys = Key::ROOT_SENTINEL;

                $L   = array();
                $L[] = self::BEGIN;
                $L[] = '# Generated by Ultimate Performance — include ONCE inside the http{} block:';
                $L[] = '#   include /path/to/ultimate-performance-nginx.conf;';
                $L[] = '# Static page-cache serving for host ' . $host_dir . '.';
                $L[] = '# Any cookie, any query string, or non-GET/HEAD => PHP origin (dynamic).';
                $L[] = '# Direct external access to the cache tree is denied (internal-only).';
                $L[] = 'map $request_method $up_method_ok { default 0; GET 1; HEAD 1; }';
                $L[] = 'map $http_cookie $up_cookieless { default 0; "" 1; }';
                $L[] = 'map "$up_method_ok|$up_cookieless|$is_args" $up_static { default 0; "1|1|" 1; }';
                $L[] = 'server {';
                $L[] = '    listen ' . $listen . ';';
                $L[] = '    server_name ' . $host_dir . ';';
                $L[] = '    root ' . $docroot . ';';
                $L[] = '    index index.php;';
                $L[] = '    set $up_origin ' . $origin . ';';
                $L[] = '    # Original client URI captured once per request (server rewrite phase):';
                $L[] = '    set $up_orig_uri $request_uri;';
                $L[] = '    # Site hardening shipped with the plugin: secret files never served.';
                $L[] = '    location ~* /\.(env|git) { deny all; }';
                $L[] = '    location = /wp-config.php { deny all; }';
                $L[] = '    location = / {';
                $L[] = '        if ($up_static) { rewrite ^ /up-cache/' . $host_dir . '/' . $root_phys . '/index.html last; }';
                $L[] = '        try_files $uri @up_dynamic;';
                $L[] = '    }';
                $L[] = '    location / {';
                $L[] = '        if ($up_static) {';
                $L[] = '            rewrite ^/(.+)/$ /up-cache/' . $host_dir . '/$1/index.html last;';
                $L[] = '            rewrite ^ /up-cache/' . $host_dir . '$uri/index.html last;';
                $L[] = '        }';
                $L[] = '        try_files $uri $uri/ @up_dynamic;';
                $L[] = '    }';
                $L[] = '    location ^~ /up-cache/ {';
                $L[] = '        internal;';
                $L[] = '        alias ' . $cache_root . '/v/;';
                $L[] = '        try_files $uri @up_dynamic;';
                $L[] = '    }';
                $L[] = '    location @up_dynamic {';
                $L[] = '        proxy_pass http://$up_origin;';
                $L[] = '        proxy_set_header Host $host;';
                $L[] = '        proxy_set_header X-UP-Original-URI $up_orig_uri;';
                $L[] = '        proxy_set_header X-Real-IP $remote_addr;';
                $L[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
                $L[] = '    }';
                $L[] = '    # .php is NEVER served from disk — always proxied (source-disclosure guard).';
                $L[] = '    location ~ \.php$ {';
                $L[] = '        proxy_pass http://$up_origin;';
                $L[] = '        proxy_set_header Host $host;';
                $L[] = '        proxy_set_header X-UP-Original-URI $up_orig_uri;';
                $L[] = '        proxy_set_header X-Real-IP $remote_addr;';
                $L[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
                $L[] = '    }';
                $L[] = '}';
                $L[] = self::END;

                return implode( "\n", $L ) . "\n";
        }

        /**
         * Single source of truth for the per-host physical directory under the
         * cache tree (M6: the admin verify-probe flow must map the site host to
         * the SAME directory the generator emits — previously this regex was
         * private to generate()).
         *
         * @param string $host Canonical lowercase host.
         * @return string Directory name ('' when unusable).
         */
        public static function host_dir( $host ) {
                return (string) preg_replace( '/[^a-z0-9\.\[\]\-]/', '', strtolower( (string) $host ) );
        }

        /**
         * Verify-probe URI (relative). The admin flow: request the probe URL; when
         * the response is byte-identical to probe_body() for the token AND the PHP
         * origin saw NO request, the snippet is serving statically => active.
         *
         * @param string $token 8-64 chars [a-z0-9].
         * @return string Probe URI ('/uc-verify-<token>/') or '' when unusable.
         */
        public static function probe_uri( $token ) {
                $token = (string) $token;
                if ( ! preg_match( '/^[a-z0-9]{8,64}$/', $token ) ) {
                        return '';
                }
                return '/uc-verify-' . $token . '/';
        }

        /**
         * Canonical body stored under the probe URI. Byte-identical comparison is
         * the "served statically" proof (a proxy hit would append WP markup, a 404
         * would not match at all).
         *
         * @param string $token
         * @return string
         */
        public static function probe_body( $token ) {
                if ( '' === self::probe_uri( $token ) ) {
                        return '';
                }
                return 'ultimate-performance-nginx-probe:' . $token;
        }
}
