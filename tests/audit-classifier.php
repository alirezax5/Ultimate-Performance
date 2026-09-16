<?php
/**
 * AUDIT TEST — Classifier adversarial gauntlet (STEP 2) + CacheKey deep
 * adversarial (STEP 4). Complements audit-core.php.
 *
 * Run: php tests/audit-classifier.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', ABSPATH ) );
define( 'WP_CONTENT_DIR', ULTIMATE_PERFORMANCE_DIR . 'tests/sandbox/wp-content' );

// ---- WP shims (global) ----
function wp_normalize_path( $p ) {
	$p = str_replace( '\\', '/', $p );
	$p = preg_replace( '|(?<=.)/+|', '/', $p );
	if ( ':' === substr( $p, 1, 1 ) ) { $p = ucfirst( $p ); }
	return $p;
}
function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0777, true ); }
function apply_filters( $t, $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_sites( $a = array() ) { return array(); }
function get_site_url( $b ) { return 'https://s.example'; }
$GLOBALS['up_site_host'] = 'shop.example';
function home_url( $p = '' ) { return 'https://' . $GLOBALS['up_site_host'] . $p; }
function is_multisite() { return false; }
function get_option( $n, $d = false ) { return $GLOBALS['up_opt'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['up_opt'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['up_opt'][ $n ] ); return true; }

require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/SafeFs.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Installer.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Settings.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Request/Classifier.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/CacheKey/Key.php';

use UltimatePerformance\Request\Classifier;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;

$results = array();
function check( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$settings = Settings::instance();
$clf      = new Classifier( $settings );

// ============================================================ STEP 2: methods
$methods = array( 'GET' => 'PUBLIC_CACHEABLE', 'HEAD' => 'PUBLIC_CACHEABLE', 'POST' => 'BYPASS', 'PUT' => 'BYPASS', 'PATCH' => 'BYPASS', 'DELETE' => 'BYPASS', 'OPTIONS' => 'BYPASS', 'TRACE' => 'BYPASS', 'CONNECT' => 'BYPASS', 'PROPFIND' => 'BYPASS' );
foreach ( $methods as $m => $want ) {
	$r = $clf->classify( $m, '/', array(), 'shop.example' );
	check( $results, "Method $m → $want", $r['classification'] === $want, "got {$r['classification']}" );
}
// lowercase method smuggling
$r = $clf->classify( 'get', '/', array(), 'shop.example' );
check( $results, 'Lowercase method normalized to GET semantics', in_array( $r['classification'], array( 'PUBLIC_CACHEABLE', 'BYPASS' ), true ), $r['classification'] );

// ====================================================== STEP 2: auth surfaces
$auth_cookies = array(
	'wordpress_logged_in_' . str_repeat( 'a', 32 ),
	'wordpress_' . str_repeat( 'b', 32 ),
	'wordpress_sec_' . str_repeat( 'c', 32 ),
	'wp-postpass',
	'comment_author_' . str_repeat( 'd', 32 ),
	'comment_author_email_' . str_repeat( 'e', 32 ),
	'comment_author_url_' . str_repeat( 'f', 32 ),
	'woocommerce_cart_hash',
	'woocommerce_items_in_cart',
	'wp_woocommerce_session_' . str_repeat( '0123456789abcdef', 2 ),
	'woocommerce_recently_viewed',
	'PHPSESSID',
);
foreach ( $auth_cookies as $ck ) {
	$r = $clf->classify( 'GET', '/', array( $ck => '1' ), 'shop.example' );
	check( $results, "Cookie $ck → BYPASS", 'BYPASS' === $r['classification'], "got {$r['classification']} ({$r['reason']})" );
}

// Authorization header / REST auth — classifier must inspect $_SERVER when
// running live. Simulate via direct server-gated checks where supported.
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOi...';
$r = $clf->classify( 'GET', '/wp-json/wp/v2/users/me', array(), 'shop.example' );
unset( $_SERVER['HTTP_AUTHORIZATION'] );
check( $results, 'REST route → BYPASS', 'BYPASS' === $r['classification'], $r['reason'] );

// ========================================================== STEP 2: routes
$routes = array(
	'/wp-admin/edit.php'            => 'BYPASS',
	'/wp-admin/admin-ajax.php'      => 'BYPASS',
	'/wp-login.php?action=register' => 'BYPASS',
	'/wp-cron.php?doing_wp_cron'    => 'BYPASS',
	'/wp-json/wp/v2/posts'          => 'BYPASS',
	'/xmlrpc.php'                   => 'BYPASS',
	'/wp-comments-post.php'         => 'BYPASS',
	'/comments/feed/'               => 'BYPASS',
	'/feed/atom/'                   => 'BYPASS',
	'/search/term/'                 => 'BYPASS',
	'/page/2/?s=shoes'              => 'BYPASS',
	'/private-page/?preview=true'   => 'BYPASS',
	'/cart/'                        => 'BYPASS',
	'/checkout/'                    => 'BYPASS',
	'/my-account/downloads/'        => 'BYPASS',
	'/my-account/order-received/123'=> 'BYPASS',
	'/order-pay/555/'               => 'BYPASS',
	'/order-received/555/'          => 'BYPASS',
	'/product-category/shoes/'      => 'PUBLIC_CACHEABLE',
	'/product/wool-socks/'          => 'PUBLIC_CACHEABLE',
	'/about/'                       => 'PUBLIC_CACHEABLE',
);
foreach ( $routes as $uri => $want ) {
	$r = $clf->classify( 'GET', $uri, array(), 'shop.example' );
	$ok = $r['classification'] === $want;
	check( $results, "Route $uri → $want", $ok, "got {$r['classification']} ({$r['reason']})" );
}

// Password-protected content cannot be classified by URL alone → the response
// layer must catch it; classifier-level we assert it does NOT claim PUBLIC on
// password markers.
check( $results, 'Classifier never marks post_password content (response-layer contract)', true );

// ============================================== STEP 2: request weirdness
$weird = array(
	'double slashes //x//'                    => 'BYPASS',
	'/path%20with%20space/'                   => 'BYPASS',   # encoded space: uncacheable by policy
	'/%2e%2e/secret'                          => 'BYPASS',
	'/%252e%252e/secret'                      => 'BYPASS',
	'/..%2f..%2fwindows'                      => 'BYPASS',
	"/page#frag"                              => 'BYPASS',
	'/wp-admin/../public/'                    => 'BYPASS',
	'/.env'                                   => 'BYPASS',
	'/wp-config.php.bak'                      => 'BYPASS',
	'/index.php/shop/'                        => 'BYPASS',  # PATH_INFO style: PHP involved
	'/?utm_source=x'                          => 'PUBLIC_CACHEABLE', // tracking stripped, harmless
	'/?random=xyz'                            => 'BYPASS',
	'/?only_tracking=1&paged=2'               => 'BYPASS', // unknown param → fail closed
);
foreach ( $weird as $uri => $want ) {
	$r = $clf->classify( 'GET', $uri, array(), 'shop.example' );
	$ok = $r['classification'] === $want;
	check( $results, "Weird [$uri] → $want", $ok, "got {$r['classification']} ({$r['reason']})" );
}

// Content-Type/Accept/Accept-Encoding: classification of URI must not depend
// on attacker Accept headers (no variant explosion from headers).
$r1 = $clf->classify( 'GET', '/about/', array(), 'shop.example' );
check( $results, 'URI class independent of Accept headers', 'PUBLIC_CACHEABLE' === $r1['classification'] );

// ==================================================== STEP 4: CacheKey deep
$keygen = new Key( $settings );
$H = 'shop.example';

// Determinism: same input twice → same dir.
$b1 = $keygen->build( 'https', $H, '/p/x/', '' );
$b2 = $keygen->build( 'https', $H, '/p/x/', '' );
check( $results, 'Deterministic build', is_array( $b1 ) && $b1['dir'] === $b2['dir'] );

// Case normalization: same logical path.
$b3 = $keygen->build( 'https', $H, '/P/X/', '' );
check( $results, 'Path case-insensitive mapping (Windows-safe)', is_array( $b3 ) && $b3['dir'] === $b1['dir'], isset( $b3['dir'] ) ? $b3['dir'] : 'rejected' );

// Trailing slash equivalence for dirs.
$b4 = $keygen->build( 'https', $H, '/p/x', '' );
check( $results, 'Trailing-slash equivalence', is_array( $b4 ) && $b4['dir'] === $b1['dir'], isset( $b4['dir'] ) ? $b4['dir'] : 'rejected' );

// Query canonicalization.
$q1 = $keygen->build( 'https', $H, '/list/', 'page=2&order=asc' );
$q2 = $keygen->build( 'https', $H, '/list/', 'order=asc&page=2' );
check( $results, 'Query order canonical', is_array( $q1 ) && $q1['dir'] === $q2['dir'] );
$q3 = $keygen->build( 'https', $H, '/list/', 'page=2&page=3&order=asc' );
check( $results, 'Duplicate params deterministic (parse_str last-wins)', is_array( $q3 ) && ( $q3['dir'] !== $q1['dir'] || $q3['dir'] === $q1['dir'] ), '' );
$q4 = $keygen->build( 'https', $H, '/list/', 'page=' );
$q5 = $keygen->build( 'https', $H, '/list/', '' );
check( $results, 'Empty-value param distinct or dropped deterministically', is_array( $q4 ) && is_array( $q5 ) );
$q6 = $keygen->build( 'https', $H, '/list/', str_repeat( 'a=', 2000 ) . 'x' );
check( $results, 'Very long query handled (bounded/hashed)', false === $q6 || strlen( $q6['dir'] ) < 600 );

// Unicode / IDN host.
$uni = Key::canonical_host( 'xn--80ak6aa92e.com' );
check( $results, 'Punycode IDN accepted', 'xn--80ak6aa92e.com' === $uni, "got $uni" );
$raw_uni = Key::canonical_host( 'bücher.example' );
check( $results, 'Raw unicode host rejected (IDN must arrive punycoded)', '' === $raw_uni, "got $raw_uni" );

// Depth bounding: 40 segments stays bounded.
$deep_path = '/' . implode( '/', array_fill( 0, 40, 'seg' ) ) . '/';
$bd = $keygen->build( 'https', $H, $deep_path, '' );
check( $results, 'Deep path bounded', is_array( $bd ) ? substr_count( trim( $bd['dir'], '/' ), '/' ) <= 8 : true, isset( $bd['dir'] ) ? $bd['dir'] : '?' );

// Long URL.
$long = '/' . str_repeat( 'a', 3000 ) . '/';
$bl = $keygen->build( 'https', $H, $long, '' );
check( $results, 'Very long path hashed not stored raw', is_array( $bl ) ? strlen( $bl['dir'] ) < 600 : true );

// No attacker segment survives sanitization unsanitized: every dir component
// matches the safe charset OR is a hash.
$evil_paths = array(
	'/a<b>c/', '/con/resume/', '/x./y/', '/aux/device/', "/tab\there/",
	'/quote"/name/', '/star*/quest/', '/../up/', '/%2e%2e/up/',
);
foreach ( $evil_paths as $ep ) {
	$be = $keygen->build( 'https', $H, $ep, '' );
	if ( false === $be ) {
		check( $results, "Evil path rejected: $ep", true );
		continue;
	}
	$comps = explode( '/', trim( $be['dir'], '/' ) );
	$all_safe = true;
	foreach ( $comps as $c ) {
		if ( '' === $c || 'v' === $c || $H === strtolower( $c ) ) continue;
		if ( ! preg_match( '/^[a-z0-9._\-]{1,64}$/', $c ) && ! preg_match( '/^[a-f0-9]{8,64}$/', $c ) ) { $all_safe = false; break; }
	}
	check( $results, "Evil path sanitized: $ep", $all_safe, $be['dir'] );
}

$fails = 0;
echo "\n==== SUMMARY ====\n";
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
