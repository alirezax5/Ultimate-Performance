<?php
/**
 * Warmup planner (Phase J) — SSRF-safe by construction.
 *
 * Candidate URLs come ONLY from the plugin's own page-cache tree on local
 * disk (wp-content/cache/ultimate-performance/v/**). Nothing remote, nothing
 * user-supplied: the tree is written by the Store from requests this site
 * already served. Every reconstructed URL is then re-validated against the
 * site's own host (defense in depth, second guard beside
 * Handlers::is_local_url), normalized and de-duplicated, and the final plan
 * is capped by a hard budget.
 *
 * @package UltimatePerformance\Warmup
 */

namespace UltimatePerformance\Warmup;

use UltimatePerformance\CacheKey\Key;

defined( 'ABSPATH' ) || exit;

final class Planner {

        const DEFAULT_BUDGET = 500;
        const MAX_DEPTH      = 16;

        /** @var string cache root (no trailing slash) */
        private $root;

        /**
         * @param string|null $root Cache root override (tests); null = derive from WP_CONTENT_DIR.
         */
        public function __construct( $root = null ) {
                if ( null === $root ) {
                        $this->root = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/cache/ultimate-performance';
                } else {
                        $this->root = rtrim( $root, '/' );
                }
        }

        /**
         * Build the warm plan.
         *
         * @param int|null $budget Hard cap on planned URLs (filterable).
         * @return array{urls:array<int,string>,skipped_foreign:int,skipped_dup:int,capped:bool}
         */
        public function plan( $budget = null ) {
                $budget = null === $budget && function_exists( 'apply_filters' )
                        ? (int) apply_filters( 'ultimate_cache_warmup_budget', self::DEFAULT_BUDGET )
                        : (int) ( $budget ?? self::DEFAULT_BUDGET );
                $budget = max( 1, min( $budget, 5000 ) );

                $site_hosts = $this->site_hosts();
                $seen       = array();
                $urls       = array();
                $foreign    = 0;
                $dups       = 0;
                $capped     = false;

                foreach ( $this->candidate_dirs() as $dir ) {
                        if ( count( $urls ) >= $budget ) {
                                $capped = true;
                                break;
                        }
                        $url = $this->reconstruct( $dir, $site_hosts );
                        if ( null === $url ) {
                                ++$foreign; // foreign host / bad shape — never planned
                                continue;
                        }
                        if ( isset( $seen[ $url ] ) ) {
                                ++$dups;
                                continue;
                        }
                        $seen[ $url ] = true;
                        $urls[]       = $url;
                }

                return array(
                        'urls'            => $urls,
                        'skipped_foreign' => $foreign,
                        'skipped_dup'     => $dups,
                        'capped'          => $capped,
                );
        }

        /**
         * Allowed hosts: this site's own host (home_url) — the single source of
         * truth. Filterable for networks that genuinely own aliases.
         *
         * @return array<string,bool>
         */
        private function site_hosts() {
                $hosts = array();
                $h     = '';
                if ( function_exists( 'home_url' ) ) {
                        $h = (string) wp_parse_url( home_url(), PHP_URL_HOST );
                }
                $hosts[ Key::canonical_host( $h ) ] = true;
                unset( $hosts[''] );
                return $hosts;
        }

        /**
         * Walk v/** for cache entries holding an index.html. Bounded by MAX_DEPTH;
         * the plan loop above additionally breaks once the budget is met.
         *
         * @return array<int,string> Relative dir paths (e.g. "v/example.com/shop").
         */
        private function candidate_dirs() {
                $base = $this->root . '/v';
                $out  = array();
                if ( ! is_dir( $base ) ) {
                        return $out;
                }
                $stack = array( array( $base, 0 ) );
                while ( ! empty( $stack ) ) {
                        list( $dir, $depth ) = array_pop( $stack );
                        if ( $depth > self::MAX_DEPTH ) {
                                continue;
                        }
                        $items = @scandir( $dir );
                        if ( false === $items ) {
                                continue;
                        }
                        foreach ( $items as $item ) {
                                if ( '.' === $item || '..' === $item ) {
                                        continue;
                                }
                                $path = $dir . '/' . $item;
                                if ( is_link( $path ) ) {
                                        continue; // never follow symlinks
                                }
                                if ( is_dir( $path ) ) {
                                        $stack[] = array( $path, $depth + 1 );
                                        continue;
                                }
                                if ( 'index.html' === $item ) {
                                        $rel = substr( dirname( $path ), strlen( $this->root ) + 1 );
                                        if ( false !== $rel && '' !== $rel ) {
                                                $out[] = $rel;
                                        }
                                }
                        }
                }
                return $out;
        }

        /**
         * Reconstruct a URL from a relative cache dir "v/<host>/<path…>".
         * Returns null for anything not belonging to this site (SSRF guard).
         *
         * @param string             $rel        Relative dir.
         * @param array<string,bool> $site_hosts Allowed hosts.
         * @return string|null
         */
        private function reconstruct( $rel, $site_hosts ) {
                // Expected shape: v/<host>/<path…>  (path segments are URL-safe names)
                if ( 0 !== strpos( $rel, 'v/' ) || false !== strpos( $rel, '..' ) ) {
                        return null;
                }
                $rest    = substr( $rel, 2 );
                $slash   = strpos( $rest, '/' );
                $rawhost = false === $slash ? $rest : substr( $rest, 0, $slash );
                $path    = false === $slash ? '' : substr( $rest, $slash );

                $host = Key::canonical_host( urldecode( $rawhost ) );
                if ( '' === $host || ! isset( $site_hosts[ $host ] ) ) {
                        return null; // foreign host — SSRF guard
                }
                $scheme = 'http';
                if ( function_exists( 'is_ssl' ) && function_exists( 'wp_parse_url' ) ) {
                        $scheme = ( is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ? 'https' : 'http';
                }

                // Re-normalize the path defensively (no traversal, no empty/dot segments).
                $seg = array();
                foreach ( explode( '/', trim( $path, '/' ) ) as $s ) {
                        if ( '' === $s || '.' === $s || '..' === $s ) {
                                if ( '' !== $s || '' === $path ) {
                                        continue;
                                }
                                return null;
                        }
                        $seg[] = $s;
                }
                // BENCH-D5: The Store marks the site root with the ROOT_SENTINEL
                // segment ('__root__') — map it to /.
                if ( isset( $seg[0] ) && ( '(root)' === $seg[0] || \UltimatePerformance\CacheKey\Key::ROOT_SENTINEL === $seg[0] ) ) {
                        array_shift( $seg );
                }
                $base = $scheme . '://' . $host;
                return empty( $seg ) ? $base . '/' : $base . '/' . implode( '/', $seg );
        }
}
