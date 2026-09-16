<?php
/**
 * Canonical cache key + deterministic filesystem path mapping.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\CacheKey;

use UltimatePerformance\Request\Classifier;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * URL → canonical key → filesystem path.
 *
 * Deterministic: same canonical input always maps to the same path. No UUID,
 * no randomness in lookup. UUID7 lives only in object metadata.
 */
final class Key {

        /** @var Settings */
        private $settings;

        /** @var Classifier|null shared classifier (path normalization) */
        private $classifier;

        public function __construct( Settings $settings ) {
                $this->settings   = $settings;
                $this->classifier = new Classifier( $settings );
        }

        /**
         * Build the canonical key struct for a request.
         *
         * @param string               $scheme http|https (anything else coerced to http).
         * @param string               $host   Already allowlisted host.
         * @param string               $path   Path as received.
         * @param string               $query  Raw query string.
         * @param array<string,string> $accept Variant inputs (accept, ua).
         * @return array{key:string,dir:string,file:string,variants:array<int,string>,query_used:string}|false false when unsafe
         */
        public function build( $scheme, $host, $path, $query, $accept = array() ) {
                $scheme = 'https' === strtolower( (string) $scheme ) ? 'https' : 'http';

                $host = self::canonical_host( $host );
                if ( '' === $host ) {
                        return false; // unparseable/hostile host never cached.
                }

                $pn = $this->classifier->normalize_path( (string) $path );
                if ( false === $pn ) {
                        return false;
                }

                $query_used = $this->canonical_query( (string) $query );

                // Variants — ordered, only when admin-enabled AND input present.
                $variants = array();
                $vconf    = (array) $this->settings->get( 'variants', array() );
                if ( ! empty( $vconf['webp'] ) && stripos( isset( $accept['accept'] ) ? (string) $accept['accept'] : '', 'image/webp' ) !== false ) {
                        $variants[] = 'webp';
                }
                $variants = apply_filters( 'ultimate_performance_variants', $variants, $accept );

                $key = implode(
                        ' ',
                        array_filter(
                                array(
                                        'GET',
                                        $scheme,
                                        $host,
                                        $pn . ( '' !== $query_used ? '?' . $query_used : '' ),
                                        $variants ? 'v=' . implode( ',', $variants ) : '',
                                )
                        )
                );

                // M3-D1 (release-blocking, production): discriminated storage for
                // allowlisted query variants. The default query_allowlist is NON-empty
                // (p, page_id, page, paged, feed, lang) — before this fix every
                // allowlisted-query request shared the no-query dir, so /?page=2 and
                // /?page=3 overwrote each other (last write wins) and paginated
                // archives served the wrong content in DEFAULT configuration. The
                // canonical (allowlisted, sorted, urlencoded) query is discriminated by
                // a bounded sha1 prefix — deterministic across processes, fs-safe, and
                // consistent through Store::write/lookup/purge + Registry (every
                // consumer re-sanitizes via Key::absolute/segment, so lookups, writes
                // and purge payloads always agree). Two raw queries that canonicalize
                // identically share one dir (correct); different canonical queries get
                // distinct dirs. Under the strip policy tracking/unknown params are
                // already dropped before this point, so ?page=2&utm=x shares page=2's
                // dir as documented; under the variant policy every unknown param
                // variant gets its own dir (admin's explicit choice, bounded by the
                // sha1 suffix — no depth explosion).
                $dir = $this->dir_for( $host, $pn, $variants );
                if ( false === $dir ) {
                        return false; // unreachable via canonical_host + normalize_path; fail closed anyway
                }
                if ( '' !== $query_used ) {
                        $dir .= '@q' . substr( sha1( $query_used ), 0, 16 );
                }

                return array(
                        'key'        => $key,
                        'dir'        => $dir,
                        'file'       => 'index.html',
                        'variants'   => $variants,
                        'query_used' => $query_used,
                );
        }

        /**
         * Host canonicalization: lowercase, strip trailing dot, bracket IPv6,
         * strip port. Rejects control chars/whitespace/slashes and over-long hosts.
         * IPv6 keeps brackets in dir name but colon-free hex form for FS safety.
         *
         * @return string Canonical host or '' when hostile/unparseable.
         */
        public static function canonical_host( $host ) {
                $host = trim( strtolower( (string) $host ) );
                if ( '' === $host || strlen( $host ) > 253 ) {
                        return '';
                }
                // Bracketed IPv6 literal, optionally followed by :port.
                if ( '[' === $host[0] ) {
                        if ( ! preg_match( '/^\[([0-9a-f:.]+)\](?::\d{1,5})?$/', $host, $m ) ) {
                                return '';
                        }
                        $ip = false === strpos( $m[1], '%' ) ? $m[1] : strstr( $m[1], '%', true );
                        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                                return '';
                        }
                        return '[' . bin2hex( @inet_pton( $ip ) ) . ']'; // deterministic, colon-free
                }
                // Hostname[:port] — strip a valid trailing :port BEFORE the bare-IPv6
                // branch, otherwise "example.com:8080" is mistaken for an IPv6 literal.
                if ( preg_match( '/:([0-9]{1,5})$/', $host, $pm ) && (int) $pm[1] <= 65535 ) {
                        $host = substr( $host, 0, -strlen( $pm[1] ) - 1 );
                        if ( '' === $host ) {
                                return '';
                        }
                }
                // Bare IPv6 (no brackets) — accept strict literals only.
                if ( false !== strpos( $host, ':' ) ) {
                        if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                                return '[' . bin2hex( @inet_pton( $host ) ) . ']';
                        }
                        return '';
                }
                if ( preg_match( '/[\s\/\\\?\#@%\[\]]/', $host ) ) {
                        return '';
                }
                $host = rtrim( $host, '.' ); // FQDN trailing dot == same host
                if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                        return $host;
                }
                // hostname: labels alnum/hyphen, no empty labels, no leading/trailing hyphen.
                if ( ! preg_match( '/^(?:[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host ) ) {
                        return '';
                }
                return $host;
        }

        /**
         * Allowlisted+sorted query string. Tracking stripped always; unknown params
         * follow the configured policy (bypass was already decided by Classifier).
         */
        private function canonical_query( $query ) {
                if ( '' === trim( $query ) ) {
                        return '';
                }
                parse_str( $query, $params );
                if ( ! is_array( $params ) ) {
                        return '';
                }
                $allow  = (array) $this->settings->get( 'query_allowlist', array() );
                $policy = (string) $this->settings->get( 'query_unknown_policy', 'bypass' );
                $out    = array();
                foreach ( $params as $k => $v ) {
                        $k = (string) $k;
                        if ( in_array( $k, Classifier::TRACKING_PARAMS, true ) ) {
                                continue;
                        }
                        $known = in_array( $k, $allow, true );
                        if ( ! $known && 'variant' !== $policy ) {
                                continue; // bypass/strip drop unknowns here (bypass already rejected upstream)
                        }
                        if ( is_array( $v ) ) {
                                $v = implode( ',', array_map( 'strval', $v ) );
                        }
                        $out[ $k ] = (string) $v;
                }
                ksort( $out );
                $str = '';
                foreach ( $out as $k => $v ) {
                        $str .= ( '' === $str ? '' : '&' ) . rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
                }
                return $str;
        }

        /**
         * BENCH-D5 (HARDEN-2): The root path "/" sentinel.
         *
         * MUST be a valid segment per segment()'s regex
         * /^[a-z0-9][a-z0-9._\-]{0,63}$/ so it passes through UNHASHED —
         * otherwise dir_for() writes to v/<host>/h<hash>/index.html while
         * a simple Nginx try_files v/<host>$uri/index.html (the common
         * hand-written rule) looks for v/<host>/index.html and never finds it.
         *
         * 'uc-root' is chosen because:
         *   - starts with a letter (passes segment regex)
         *   - contains a hyphen (readable)
         *   - 'uc-' prefix is the plugin's namespace, never a public WP slug
         *   - WP slug sanitizer lowercases and replaces spaces with hyphens,
         *     so no real post/page can ever produce 'uc-root' as a slug
         */
        const ROOT_SENTINEL = 'uc-root';

        /**
         * Deterministic directory under cache root/v/<host>/...
         * Depth cap: beyond MAX_DEPTH segments collapse into one hashed dir.
         *
         * @return string|false false when any segment is unsafe.
         */
        public function dir_for( $host, $normalized_path, $variants = array() ) {
                $host_dir = preg_replace( '/[^a-z0-9\.\[\]\-]/', '', strtolower( (string) $host ) );
                if ( '' === $host_dir ) {
                        return false;
                }

                $segs = array_values( array_filter( explode( '/', (string) $normalized_path ), 'strlen' ) );

                $safe = array();
                foreach ( $segs as $seg ) {
                        $s = self::segment( $seg );
                        if ( null === $s ) {
                                return false;
                        }
                        $safe[] = $s;
                }
                if ( empty( $safe ) ) {
                        // BENCH-D5: use the ROOT_SENTINEL constant (valid segment, unhashed)
                        // so the cache path is v/<host>/__root__/index.html — consistent
                        // between PHP writer, Nginx Rules generator, and simple hand-written
                        // try_files rules.
                        $safe = array( self::ROOT_SENTINEL );
                }

                $MAX_DEPTH = 6;
                if ( count( $safe ) > $MAX_DEPTH ) {
                        $head      = array_slice( $safe, 0, $MAX_DEPTH - 1 );
                        $tail      = array_slice( $safe, $MAX_DEPTH - 1 );
                        $collapsed = substr( sha1( implode( '/', $tail ) ), 0, 16 ) . '.d';
                        $safe      = array_merge( $head, array( $collapsed ) );
                }

                $rel = $host_dir . '/' . implode( '/', $safe );
                if ( $variants ) {
                        $rel .= '@' . implode( ',', $variants );
                }
                return $rel;
        }

        /**
         * Sanitize one path segment. Safe names pass through; anything else is
         * hashed deterministically. Returns null for empty input only.
         *
         * @param string $seg Raw decoded segment.
         * @return string|null
         */
        public static function segment( $seg ) {
                $seg = (string) $seg;
                if ( '' === $seg ) {
                        return null;
                }
                if ( '.' === $seg || '..' === $seg ) {
                        return 'h' . substr( sha1( $seg ), 0, 20 ); // unreachable via normalize_path, defense in depth
                }
                // Case-folded: NTFS/macOS are case-insensitive — "P" and "p" must map
                // to the SAME directory or one of them becomes unreachable.
                $folded = strtolower( $seg );
                if ( preg_match( '/^[a-z0-9][a-z0-9._\-]{0,63}$/', $folded ) && false === strpos( $folded, '..' ) ) {
                        return $folded;
                }
                return 'h' . substr( sha1( $seg ), 0, 20 );
        }

        /**
         * Absolute file path for rel dir + suffix name. Validated containment.
         *
         * @param string $rel_dir e.g. example.com/product/iphone-15
         * @param string $name    index.html | index.html.meta.json | index.html.lock
         * @return string
         */
        public function absolute( $rel_dir, $name = 'index.html' ) {
                $rel = ltrim( (string) $rel_dir, '/' );
                // Defense in depth: re-sanitize every segment before joining.
                $parts = array();
                foreach ( explode( '/', $rel ) as $seg ) {
                        if ( '' === $seg ) {
                                continue;
                        }
                        $s = self::segment( $seg );
                        if ( null === $s ) {
                                continue;
                        }
                        $parts[] = $s;
                }
                $suffix = preg_replace( '/[^A-Za-z0-9._@\-]/', '', (string) $name );
                return Installer::cache_root() . '/v/' . implode( '/', $parts ) . ( '' !== $suffix ? '/' . $suffix : '' );
        }
}
