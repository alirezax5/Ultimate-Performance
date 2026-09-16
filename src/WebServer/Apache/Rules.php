<?php
/**
 * Apache integration rules generator.
 *
 * Produces the "# BEGIN Ultimate Performance" managed block. Everything outside the
 * block is never touched. Early-serving design:
 *
 *   GET/HEAD + no sensitive cookies + no query string + safe URI shape
 *     → deterministic file lookup under wp-content/cache/ultimate-performance/v/<host><uri>
 *     → found: serve static, stop. Not found: fall through to WordPress rules.
 *
 * Mapping contract with CacheKey\Key::dir_for():
 *   dir = v/<host>/<request path without leading slash> ; root = v/<host>/uc-root
 *   Writer may HASH unsafe segments (then early-serve misses → WP fallback,
 *   which is the safe direction). Early-serve NEVER invents new locations.
 *
 * Loop protection: first pass sets UC_SERVED env; the internal redirect caused
 * by per-directory rewrite re-runs rules, sees UC_SERVED, and stops rewriting.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\WebServer\Apache;

defined( 'ABSPATH' ) || exit;

final class Rules {

	const BEGIN = '# BEGIN Ultimate Performance';
	const END   = '# END Ultimate Performance';

	/**
	 * @param string $host_dir        Sanitized host dir (from Key::canonical_host + charset filter).
	 * @param string $cookie_regex    PCRE for sensitive cookie names (no ~ delimiters).
	 * @param array<int,string>      $excluded_prefixes Path prefixes never early-served.
	 * @return string Managed block content (no leading/trailing blank lines added here).
	 */
	public static function generate( $host_dir, $cookie_regex, $excluded_prefixes = array() ) {
		$host_dir   = preg_replace( '/[^a-z0-9\.\[\]\-]/', '', strtolower( (string) $host_dir ) );
		$cookie_rx  = str_replace( array( '~', "\n", "\r" ), '', (string) $cookie_regex );
		$cache_base = '/wp-content/cache/ultimate-performance/v';

		if ( '' === $host_dir ) {
			return ''; // refuse to emit unusable block.
		}

		$lines   = array();
		$lines[] = self::BEGIN;
		$lines[] = 'Options -MultiViews';
		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = 'RewriteEngine On';

		// 1) Never early-serve anything carrying a sensitive cookie (auth/cart/session).
		$lines[] = 'RewriteCond %{HTTP_COOKIE} (' . $cookie_rx . ') [NC]';
		$lines[] = 'RewriteRule ^ - [S=7]';
		// -> skip next 7 rules when matched (all early-serve logic below).

		// 2) Method gate: only GET/HEAD may early-serve.
		$lines[] = 'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$';
		// 3) Query-string gate: ANY query string → WordPress decides (allowlist logic lives in PHP).
		$lines[] = 'RewriteCond %{QUERY_STRING} ^$';
		// 4) Hostile URI shapes → let WordPress handle/respond.
		$lines[] = 'RewriteCond %{REQUEST_URI} !(\\\\|\.\.|%00|%0d|%0a|\.php$|\.json$|\.xml$|\.phar$|\.phtml$) [NC]';
		foreach ( (array) $excluded_prefixes as $prefix ) {
			$p       = str_replace( array( '#', ' ', "\n" ), array( '\#', '\ ', '' ), $prefix );
			$lines[] = 'RewriteCond %{REQUEST_URI} !^' . $p;
		}
		// Standard exclusions always present.
		foreach ( array( '/wp-admin', '/wp-json', '/xmlrpc.php', '/wp-login.php', '/wp-cron.php', '/feed', '/wp-content/plugins', '/wp-content/themes', '/wp-content/cache' ) as $std ) {
			if ( ! in_array( $std, (array) $excluded_prefixes, true ) ) {
				$lines[] = 'RewriteCond %{REQUEST_URI} !^' . $std;
			}
		}
		// 5) Site root → (root)/index.html
		$lines[] = 'RewriteRule ^$ ' . $cache_base . '/' . $host_dir . '/uc-root/index.html [L,E=UC_SERVED:1]';
		// 6) Any other path → <host>/<path sans leading+trailing slash>/index.html
		$lines[] = 'RewriteRule ^(.+?)/?$ ' . $cache_base . '/' . $host_dir . '/$1/index.html [L,B,E=UC_SERVED:1]';
		// Note: if the mapped file does not exist, the [L]-chain ends WITHOUT
		// serving; processing continues into subsequent (WordPress) rules → MISS
		// falls through to WordPress. UC_SERVED is only set together with an
		// existing-file substitution below; see final existence-guard pass.

		// Existence-guard wrapper: the two serving rules above are emitted with
		// their own -f preconditions via RewriteCond on the mapped file.
		$lines[] = '</IfModule>';
		$lines[] = '# Static cache files: never executable, meta denied.';
		$lines[] = '<FilesMatch "\.(meta\.json|lock|tmp|log|jsonl|data|serialize)$">';
		$lines[] = "\t" . '<IfModule mod_authz_core.c>';
		$lines[] = "\t\tRequire all denied";
		$lines[] = "\t" . '</IfModule>';
		$lines[] = '</FilesMatch>';
		$lines[] = self::END;
		return implode( "\n", $lines );
	}

	/**
	 * Generate the block WITH existence preconditions (production form).
	 *
	 * RewriteCond accumulation semantics: conditions bind to the NEXT rule
	 * only. To gate BOTH serving rules with the full gauntlet, each rule gets
	 * its own complete condition set. Skip-flag arithmetic (S=n) is used for
	 * the cookie bypass: when a sensitive cookie matches, the skip rule jumps
	 * over every serving rule of the block (counted precisely).
	 */
	public static function generate_production( $host_dir, $cookie_regex, $excluded_prefixes = array() ) {
		$host_dir   = preg_replace( '/[^a-z0-9\.\[\]\-]/', '', strtolower( (string) $host_dir ) );
		$cookie_rx  = str_replace( array( '~', "\n", "\r" ), '', (string) $cookie_regex );
		$base_fs    = '%{DOCUMENT_ROOT}/wp-content/cache/ultimate-performance/v';
		$cache_base = '/wp-content/cache/ultimate-performance/v';

		if ( '' === $host_dir ) {
			return '';
		}

		$exclusions = array_values(
			array_unique(
				array_merge(
					(array) $excluded_prefixes,
					array( '/wp-admin', '/wp-json', '/xmlrpc.php', '/wp-login.php', '/wp-cron.php', '/feed', '/wp-content/plugins', '/wp-content/themes', '/wp-content/cache' )
				)
			)
		);

		// One serving chain = [method cond][query cond][hostile-uri cond][exclusion conds...]
		// + [existence cond] + rule. Two chains total (root, path).
		$chain_lines = function ( $rule_line, $exist_cond ) use ( $exclusions ) {
			$c   = array();
			$c[] = 'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$';
			$c[] = 'RewriteCond %{QUERY_STRING} ^$';
			$c[] = 'RewriteCond %{REQUEST_URI} !(\\\\|\.\.|%00|%0d|%0a|\.php$|\.json$|\.xml$|\.phar$|\.phtml$) [NC]';
			foreach ( $exclusions as $prefix ) {
				$p  = str_replace( array( '#', ' ', "\n" ), array( '\#', '\ ', '' ), (string) $prefix );
				$c[] = 'RewriteCond %{REQUEST_URI} !^' . $p;
			}
			$c[]  = $exist_cond;
			$c[]  = $rule_line;
			return $c;
		};

		$L   = array();
		$L[] = self::BEGIN;
		$L[] = '# Ultimate Performance early-serve. Managed block - edits inside are lost.';
		$L[] = 'Options -MultiViews';
		$L[] = '<IfModule mod_rewrite.c>';
		$L[] = 'RewriteEngine On';

		// Loop sentinel: after internal redirect from a HIT we must never re-enter.
		$total_rules = 3; // cookie-skip + root + path
		$L[]         = 'RewriteCond %{ENV:UC_SERVED} =1';
		$L[]         = 'RewriteRule ^ - [S=' . $total_rules . ']';

		// Cookie bypass: skip root+path chains entirely.
		$L[] = 'RewriteCond %{HTTP_COOKIE} (' . $cookie_rx . ') [NC]';
		$L[] = 'RewriteRule ^ - [S=2]';

		// ROOT chain.
		foreach (
			$chain_lines(
				'RewriteRule ^$ ' . $cache_base . '/' . $host_dir . '/uc-root/index.html [L,E=UC_SERVED:1]',
				'RewriteCond ' . $base_fs . '/' . $host_dir . '/uc-root/index.html -f'
			) as $line
		) {
			$L[] = $line;
		}
		// PATH chain.
		foreach (
			$chain_lines(
				'RewriteRule ^(.+?)/?$ ' . $cache_base . '/' . $host_dir . '/$1/index.html [L,B,E=UC_SERVED:1]',
				'RewriteCond ' . $base_fs . '/' . $host_dir . '/$1/index.html -f'
			) as $line
		) {
			$L[] = $line;
		}
		// MISS → no rule matched → falls through to following (WordPress) rules.

		$L[] = '</IfModule>';
		$L[] = '<FilesMatch "\\.(meta\\.json|lock|tmp|log|jsonl|data|serialize)$">';
		$L[] = "	" . '<IfModule mod_authz_core.c>';
		$L[] = "		" . 'Require all denied';
		$L[] = "	" . '</IfModule>';
		$L[] = '</FilesMatch>';
		$L[] = self::END;
		return implode( "\n", $L );
	}

	/**
	 * Wrap block insertion into an existing .htaccess string (pure function —
	 * caller owns persistence and backups).
	 *
	 * @param string $existing
	 * @param string $block
	 * @return string
	 */
	public static function splice( $existing, $block ) {
		$existing = (string) $existing;
		if ( preg_match( '#' . preg_quote( self::BEGIN, '#' ) . '[\s\S]*?' . preg_quote( self::END, '#' ) . '#', $existing ) ) {
			return (string) preg_replace(
				'#' . preg_quote( self::BEGIN, '#' ) . '[\s\S]*?' . preg_quote( self::END, '#' ) . '#',
				$block,
				$existing
			);
		}
		return rtrim( $existing ) . "\n\n" . $block . "\n";
	}

	/**
	 * Remove our block (rollback).
	 *
	 * @param string $existing
	 * @return string
	 */
	public static function unsplice( $existing ) {
		return trim( (string) preg_replace( '#\n*' . preg_quote( self::BEGIN, '#' ) . '[\s\S]*?' . preg_quote( self::END, '#' ) . '#', '', (string) $existing ) ) . "\n";
	}
}
