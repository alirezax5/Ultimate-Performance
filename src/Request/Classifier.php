<?php
/**
 * Request classification — fail closed.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Request;

use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides if a request is safe to serve from / store into the public page cache.
 * UNKNOWN → BYPASS. Extensible via `ultimate_performance_classification` filter.
 */
final class Classifier {

	const PUBLIC_CACHEABLE  = 'PUBLIC_CACHEABLE';
	const PRIVATE_CACHEABLE = 'PRIVATE_CACHEABLE';
	const DYNAMIC           = 'DYNAMIC';
	const BYPASS            = 'BYPASS';
	const UNKNOWN           = 'UNKNOWN';

	/** @var Settings */
	private $settings;

	/** Tracking params always stripped from cache keys and ignored for policy. */
	public const TRACKING_PARAMS = array(
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
		'gclid', 'fbclid', 'msclkid', 'dclid', 'twclid', 'mc_eid', 'igshid', '_ga',
	);

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Classify a request.
	 *
	 * @param string|null               $method  HTTP method (null = current).
	 * @param string|null               $uri     Path+query, e.g. /shop/?p=5.
	 * @param array<string,string>|null $cookies Cookie map (null = current).
	 * @param string|null               $host    Host header (null = current).
	 * @return array{classification:string,reason:string}
	 */
	public function classify( $method = null, $uri = null, $cookies = null, $host = null ) {
		if ( null === $method ) {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		} else {
			$method = strtoupper( (string) $method );
		}
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'method:' . $method );
		}

		$raw_uri = null !== $uri ? (string) $uri : ( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/' );
		$parts   = wp_parse_url( $raw_uri );
		if ( ! is_array( $parts ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'uri-unparseable' );
		}
		$path  = isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) && is_string( $parts['query'] ) ? $parts['query'] : '';

		// 0. Fragments never reach the server; if one appears the URI is hostile.
		if ( false !== strpos( $raw_uri, '#' ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'fragment-in-uri' );
		}

		// 1. Sensitive cookies → never public-cache.
		$cookie_reason = $this->sensitive_cookie( $cookies );
		if ( '' !== $cookie_reason ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'cookie:' . $cookie_reason );
		}

		// 2. Host must be on the site allowlist (host-header poisoning defense).
		if ( null === $host ) {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		}
		$canon = Key::canonical_host( $host );
		if ( '' === $canon || ! $this->host_allowed( $canon ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'host-not-allowed:' . substr( strtolower( trim( (string) $host ) ), 0, 80 ) );
		}

		// 3. Path normalization (traversal/encoding attacks rejected outright).
		$pn = $this->normalize_path( $path );
		if ( false === $pn ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'path-normalization-rejected' );
		}

		// 4. Reserved WordPress/system routes.
		//
		// Matched against the path RELATIVE to the site base (home_url path)
		// so a WordPress installed in a subdirectory (/wordpress/wp-admin)
		// still resolves correctly. Absolute-path prefixes that do not begin
		// with the site base (e.g. another WP at /other/wp-admin on the same
		// host) are intentionally NOT matched — they are outside this site.
		$rel = self::strip_site_base( $pn );
		foreach ( self::reserved_prefixes() as $prefix ) {
			if ( '' !== $prefix && 0 === stripos( $rel, $prefix ) ) {
				return array( 'classification' => self::BYPASS, 'reason' => 'reserved-path:' . $prefix );
			}
		}

		// 5. Files that are always dynamic when requested literally.
		$base = strtolower( substr( $pn, strrpos( $pn, '/' ) + 1 ) );
		if ( in_array( $base, array( 'robots.txt', 'favicon.ico', 'wp-cron.php', 'wp-login.php', 'xmlrpc.php', 'wp-links-opml.php', 'wp-signup.php', 'wp-activate.php', 'wp-trackback.php', 'wp-comments-post.php', '.env', 'wp-config.php' ), true ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'dynamic-file:' . $base );
		}
		// Any .php anywhere in the path (incl. PATH_INFO-style /index.php/...)
		// involves PHP dispatch → never publicly cached.
		if ( preg_match( '#\.php(/|$)#i', $pn ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'php-dispatch-path' );
		}
		// Dotfiles and backup/config artifacts anywhere in the path.
		if ( preg_match( '#(^|/)\\.(?!well-known)[^/]*$#i', $pn ) || preg_match( '#\.(bak|old|orig|save|swp|sql|log|ini|conf)$#i', $base ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'dotfile-or-backup' );
		}

		// 6. Cache deception: denylisted extensions never cached.
		$ext = strtolower( pathinfo( $pn, PATHINFO_EXTENSION ) );
		if ( '' !== $ext && in_array( $ext, (array) $this->settings->get( 'deny_extensions', array() ), true ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'extension:' . $ext );
		}
		// Sitemap/feed-ish endpoints.
		if ( preg_match( '#(^|/)(sitemap[^/]*|feed|rss2?|atom)(/|$)#i', $pn ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'feed-or-sitemap' );
		}
		// Search & password-protected content & previews: dynamic or private.
		if ( preg_match( '#(^|/)(search|preview)/?#i', $pn ) || false !== stripos( $query, 'preview=' ) || false !== stripos( $query, 's=' ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'search-preview-or-private' );
		}
		// WordPress preview links / p= / page_id= with draft semantics are only
		// safe for published content; default conservative unless filtered open.
		if ( preg_match( '/(?:^|&)(p|page_id|post_type)=/', $query ) && apply_filters( 'ultimate_performance_allow_query_posts', false, $query ) ) {
			// admin opted in — fall through to query policy below.
		} elseif ( preg_match( '/(?:^|&)(p|page_id|attachment_id)=/', $query ) ) {
			return array( 'classification' => self::DYNAMIC, 'reason' => 'raw-post-query-default' );
		}

		// 7. Admin-configured bypass slugs (cart, checkout...).
		//
		// Also site-relative: /wordpress/cart/ must match the 'cart' slug.
		foreach ( (array) $this->settings->get( 'bypass_paths', array() ) as $slug ) {
			if ( '' !== $slug && preg_match( '#^/' . preg_quote( $slug, '#' ) . '(/|$)#i', $rel ) ) {
				return array( 'classification' => self::BYPASS, 'reason' => 'bypass-path:' . $slug );
			}
		}

		// 7b. WooCommerce public/private split (§8 REQUIRED POLICY).
		//
		// A blanket "WooCommerce = no cache" rule would exclude /shop/,
		// /product/<slug>/ and public archives — all anonymous-safe HTML.
		// Only mutation/session/private surfaces bypass. Enumerated once,
		// in one place, so no scattered is_product()/is_shop() exceptions.
		$woo = self::woo_gate( $rel, $query, $method );
		if ( '' !== $woo ) {
			return array( 'classification' => self::BYPASS, 'reason' => $woo );
		}

		// 8. Query policy: unknown params default BYPASS.
		$qcheck = $this->check_query( $query );
		if ( is_array( $qcheck ) ) {
			return array( 'classification' => self::BYPASS, 'reason' => 'query:' . $qcheck['param'] );
		}

		$result = array(
			'classification' => self::PUBLIC_CACHEABLE,
			'reason'         => 'default-public',
		);
		/**
		 * Extensibility point. Return BYPASS to refuse caching.
		 *
		 * @param array{classification:string,reason:string} $result
		 * @param string $method
		 * @param string $pn Normalized path.
		 * @param string $query
		 * @param array<string,string>|null $cookies
		 */
		return apply_filters( 'ultimate_performance_classification', $result, $method, $pn, $query, $cookies );
	}

	/**
	 * Returns matched cookie name if any cookie matches the sensitive pattern.
	 * Covers WP auth cookies, comment authors, WooCommerce cart/session, nonce
	 * cookies, post-password cookies, test cookie.
	 *
	 * @param array<string,string>|null $cookies
	 * @return string Matched cookie name or ''.
	 */
	public function sensitive_cookie( $cookies = null ) {
		if ( null === $cookies ) {
			$cookies = isset( $_COOKIE ) && is_array( $_COOKIE ) ? $_COOKIE : array();
		}
		$rx = '~(' . str_replace( '~', '\~', (string) $this->settings->get( 'cookie_bypass_regex', '' ) ) . ')~';
		foreach ( array_keys( (array) $cookies ) as $name ) {
			if ( '' === $name ) {
				continue;
			}
			if ( 1 === @preg_match( $rx, (string) $name ) ) {
				return (string) $name;
			}
		}
		return '';
	}

	/**
	 * Host allowlist: home_url + multisite network sites. SERVER_NAME is NOT
	 * trusted (it can be attacker-controlled on misconfigured servers).
	 */
	public function host_allowed( $canonical_host ) {
		static $cache = null;
		if ( null === $cache ) {
			$hosts = array();
			$h     = Key::canonical_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			if ( '' !== $h ) {
				$hosts[] = $h;
			}
			if ( is_multisite() ) {
				$sites = get_sites( array( 'number' => 100, 'fields' => 'ids' ) );
				if ( is_array( $sites ) ) {
					foreach ( $sites as $bid ) {
						$sh = Key::canonical_host( (string) wp_parse_url( (string) get_site_url( $bid ), PHP_URL_HOST ) );
						if ( '' !== $sh ) {
							$hosts[] = $sh;
						}
					}
				}
			}
			$cache = apply_filters( 'ultimate_performance_allowed_hosts', array_values( array_unique( array_filter( $hosts ) ) ) );
		}
		return in_array( $canonical_host, $cache, true );
	}

	/**
	 * WooCommerce public/private classification.
	 *
	 * §8 REQUIRED POLICY — never implement "WooCommerce = no cache".
	 * Public catalog (shop, product, category, tag) is cacheable anonymous
	 * HTML; only session-sensitive or mutation surfaces bypass.
	 *
	 * @param string $rel   Site-relative normalized path.
	 * @param string $query Raw query string.
	 * @param string $method Uppercase HTTP method.
	 * @return string BYPASS reason, or '' when the request is Woo-public (or not Woo).
	 */
	public static function woo_gate( $rel, $query, $method = 'GET' ) {
		$rel   = '/' . ltrim( (string) $rel, '/' );
		$query = (string) $query;

		// 1. Mutation/cart actions — never cache, regardless of path.
		//    add-to-cart is a cart mutation, wc-ajax is an XHR endpoint.
		$mut_params = array( 'add-to-cart', 'add_to_cart', 'remove_item', 'update_cart', 'wc-ajax', 'wc_api', 'set-payment-method', 'update_order_review' );
		foreach ( $mut_params as $p ) {
			if ( false !== stripos( $query, $p . '=' ) ) {
				return 'woo-mutation:' . $p;
			}
		}
		// wc-ajax is also reachable as /?wc-ajax=... and /wc-ajax/...
		if ( preg_match( '#/wc-ajax(/|$)#i', $rel ) || 'wc-ajax' === substr( ltrim( $rel, '/' ), 0, 7 ) ) {
			return 'woo:wc-ajax';
		}

		// 2. Private/session-sensitive Woo endpoints → BYPASS.
		//
		// These render customer-specific state (cart contents, order
		// details, account pages) or are redirect endpoints. They are
		// duplicated in bypass_paths (admin-configurable) but enforced here
		// so the policy holds even with default settings.
		$private = array(
			'/cart',
			'/checkout',
			'/my-account',
			'/order-pay',
			'/order-received',
			'/orders',
			'/view-order',
			'/edit-address',
			'/lost-password',
			'/customer-logout',
			'/add-payment-method',
			'/delete-payment-method',
			'/set-default-payment-method',
			'/wc-api',
			'/wc-ajax',
			'/wishlist',
			'/compare',
			'/downloads',
			'/edit-account',
			'/payment-methods',
		);
		foreach ( $private as $p ) {
			if ( $rel === $p || 0 === stripos( $rel, $p . '/' ) ) {
				return 'woo-private:' . $p;
			}
		}

		// 3. Non-GET/HEAD on any Woo surface is a potential mutation.
		if ( ! in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD' ), true ) ) {
			return 'woo-method:' . strtoupper( (string) $method );
		}

		// Public catalog (/shop, /product/<slug>, /product-category/<slug>,
		// /product-tag/<slug>) falls through → PUBLIC_CACHEABLE. Incoming
		// Woo session/cart cookies are handled separately by sensitive_cookie().
		return '';
	}

	/**
	 * Compute the path relative to the site base (home_url path).
	 *
	 * WordPress may live in a subdirectory (site at http://host/wordpress).
	 * Reserved prefixes and admin bypass slugs are defined site-relative
	 * ('/wp-admin', 'cart'), so the request path must be rewritten to that
	 * frame before matching. A request outside the site base is returned
	 * untouched (and will fail the reserved-prefix/bypass checks above).
	 *
	 * @param string $path Normalized absolute path.
	 * @return string Site-relative path (starts with '/').
	 */
	public static function strip_site_base( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$base = '/' . trim( (string) $base, '/' );
		if ( '/' === $base ) {
			return $path;
		}
		if ( 0 !== stripos( $path, $base ) ) {
			return $path; // outside this site — leave untouched, checks will reject.
		}
		$rest = substr( $path, strlen( $base ) );
		return '/' . ltrim( $rest, '/' );
	}

	/**
	 * Normalize a URL path for keying/classification.
	 * Rejects traversal, over-encoding, control chars, backslashes.
	 *
	 * @return string|false Normalized path or false when unsafe.
	 */
	public function normalize_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || strlen( $path ) > 2048 ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
			return false;
		}
		if ( false !== strpos( $path, '\\' ) ) {
			return false;
		}
		// Single urldecode pass; double-encoding detection.
		$dec = rawurldecode( $path );
		if ( ! is_string( $dec ) || rawurldecode( $dec ) !== $dec ) {
			return false; // %252e style double-encode
		}
		if ( false !== strpos( $dec, "\0" ) ) {
			return false;
		}
		$dec = preg_replace( '#/{2,}#', '/', $dec );

		$segs = explode( '/', $dec );
		$out  = array();
		foreach ( $segs as $seg ) {
			if ( '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				return false; // explicit traversal attempt
			}
			// Control chars / whitespace inside segments make the path unsafe
			// for deterministic FS mapping and HTTP semantics.
			if ( preg_match( '/[\x00-\x1f\x7f\s]/', $seg ) ) {
				return false;
			}
			$out[] = $seg;
		}
		$res = implode( '/', $out );
		if ( '' === $res ) {
			return '/';
		}
		if ( '/' !== $res[0] ) {
			$res = '/' . $res;
		}
		return $res;
	}

	/**
	 * Query-string policy check.
	 *
	 * @param string $query Raw query string.
	 * @return true|array true=ok; array('bypass'=>true,'param'=>k) when unknown param forces bypass.
	 */
	public function check_query( $query ) {
		if ( '' === trim( (string) $query ) ) {
			return true;
		}
		parse_str( (string) $query, $params );
		if ( ! is_array( $params ) ) {
			return array( 'bypass' => true, 'param' => 'unparseable' );
		}
		$allow  = (array) $this->settings->get( 'query_allowlist', array() );
		$policy = (string) $this->settings->get( 'query_unknown_policy', 'bypass' );
		$count  = 0;
		foreach ( $params as $k => $v ) {
			$k = (string) $k;
			if ( '' === $k ) {
				return array( 'bypass' => true, 'param' => 'empty-name' );
			}
			if ( $this->settings->get( 'tracking_params_strip', true ) && in_array( $k, self::TRACKING_PARAMS, true ) ) {
				continue; // stripped by key builder, harmless here
			}
			if ( ! in_array( $k, $allow, true ) ) {
				if ( 'variant' === $policy ) {
					++$count; // allowed but keyed
				} elseif ( 'strip' === $policy ) {
					continue;
				} else {
					return array( 'bypass' => true, 'param' => substr( $k, 0, 40 ) );
				}
			} else {
				// Allowlisted params still count toward the cap.
				++$count;
			}
			if ( $count > 8 ) {
				return array( 'bypass' => true, 'param' => 'too-many-params' );
			}
		}
		return true;
	}

	/**
	 * Reserved path prefixes that must never be publicly cached.
	 *
	 * @return array<int,string>
	 */
	public static function reserved_prefixes() {
		return apply_filters(
			'ultimate_cache_reserved_prefixes',
			array(
				'/wp-admin',
				'/wp-login.php',
				'/wp-cron.php',
				'/wp-json',
				'/xmlrpc.php',
				'/wp-content/plugins',
				'/wp-content/themes',
				'/wp-content/uploads/woocommerce_uploads',
				'/feed/',
				'/comments/feed',
				'/author/',
				'/wp-login',
			)
		);
	}
}
