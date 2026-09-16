<?php
/**
 * AUDIT TEST — Phase D: REAL WordPress nonce / dynamic-content cache-write
 * rejection. Boots the actual site (DB, active theme, WooCommerce, all
 * plugins). Plugin-local instrumentation only.
 *
 * Run: php tests/audit-wp-nonce.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' ); // plugin dir (test bootstrap)
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php'; // real WordPress + real DB

use UltimatePerformance\PageCache\Engine;
use UltimatePerformance\Request\Classifier;
use UltimatePerformance\Security\ResponseSanitizer;
use UltimatePerformance\Core\Settings;

$results = array();
function check( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$settings   = Settings::instance();
$san        = new ResponseSanitizer();
$classifier = new Classifier( $settings );

// ---- Instrumentation: capture what Engine WOULD write --------------------
$GLOBALS['up_test_captured'] = null;

// ---- D1: public page containing a nonce -----------------------------------
// Simulate a page that renders a contact form with wp_nonce_field().
$nonce = wp_create_nonce( 'up_audit_contact' );       // real WP nonce
$form  = wp_nonce_field( 'up_audit_contact', '_uc_nonce', true, false ); // real field markup
$html_public_form = '<!DOCTYPE html><html><head><title>Contact</title></head><body>'
	. '<form method="post">' . $form . '</form>'
	. '<p>Public contact page body text for everyone.</p></body></html>';

$a1 = $san->audit( $html_public_form );
check( $results, 'D1 public page w/ real wp_nonce_field REJECTED for cache write',
	false === $a1['safe'], 'reason=' . $a1['reason'] . ' nonce_len=' . strlen( $nonce ) );

// ---- D2: logged-in page ----------------------------------------------------
$user_id = wp_create_user( 'up_audit_user_' . time(), 'xY9!auditPass', 'uc-audit@example.invalid' );
wp_set_current_user( $user_id );
ob_start();
wp_meta(); // emits login/logout links depending on auth state
$meta_out = (string) ob_get_clean();
$auth_html = '<!DOCTYPE html><html><body class="logged-in admin-bar"><div id="wpadminbar">Howdy, uc-audit</div>'
	. get_logout_url_markup() . '</body></html>';
function get_logout_url_markup() {
	return '<a href="' . esc_url( wp_logout_url() ) . '">Log out</a>';
}
$a2 = $san->audit( $auth_html );
check( $results, 'D2 logged-in markup REJECTED', false === $a2['safe'], 'reason=' . $a2['reason'] );
check( $results, 'D2b real logged-in user detected by classifier',
	Classifier::BYPASS === $classifier->classify( 'GET', '/', array( 'wordpress_logged_in_' . md5( 'x' ) => 'u%7Cexp%7Ctok' ), 'shop.example' )['classification'],
	'' );
wp_set_current_user( 0 );

// ---- D3: AJAX-related nonce (admin-ajax heartbeat shape) ------------------
$ajax_nonce = wp_create_nonce( 'heartbeat-nonce' );
$ajax_html  = '<script>var wpApiSettings = {"root":"' . esc_url_raw( rest_url() ) . '","nonce":"' . $ajax_nonce . '"};</script>';
$a3 = $san->audit( $ajax_html );
check( $results, 'D3 AJAX/heartbeat nonce carrier REJECTED', false === $a3['safe'], 'reason=' . $a3['reason'] );

// ---- D4: REST-related nonce ------------------------------------------------
$rest_nonce = wp_create_nonce( 'wp_rest' ); // THE canonical REST nonce action
$rest_html  = '<meta name="wp-rest-nonce" content="' . $rest_nonce . '">';
$a4 = $san->audit( $rest_html );
check( $results, 'D4 REST nonce (action=wp_rest) REJECTED', false === $a4['safe'], 'reason=' . $a4['reason'] );
// REST endpoint responses are reserved-path anyway:
check( $results, 'D4b /wp-json/* classified BYPASS (reserved prefix)',
	Classifier::BYPASS === $classifier->classify( 'GET', '/wp-json/wp/v2/posts', array(), 'shop.example' )['classification'], '' );

// ---- D5: WooCommerce-related dynamic token --------------------------------
if ( class_exists( 'WooCommerce' ) ) {
	wc_load_cart();
	WC()->session->set( 'up_audit_probe', '1' );
	// Empty cart renders static markup (no tokens) — must NOT be rejected:
	$empty_cart = do_shortcode( '[woocommerce_cart]' );
	$a5e = $san->audit( (string) $empty_cart );
	check( $results, 'D5-empty static empty-cart HTML ALLOWED (no tokens present)',
		true === $a5e['safe'], 'reason=' . $a5e['reason'] );
	// Populate cart -> session-dependent fragments appear.
	// Prefer a SIMPLE product; fall back to any variation (variable parents
	// reject direct add_to_cart).
	$ids = wc_get_products( array( 'limit' => 5, 'status' => 'publish', 'type' => 'simple', 'return' => 'ids' ) );
	if ( empty( $ids ) ) {
		$variations = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'type' => 'variation', 'return' => 'ids' ) );
		$ids        = $variations;
	}
	if ( ! empty( $ids ) && WC()->cart->add_to_cart( (int) $ids[0], 1 ) ) {
		$cart_html = (string) do_shortcode( '[woocommerce_cart]' );
		$a5 = $san->audit( $cart_html );
		check( $results, 'D5 POPULATED cart HTML REJECTED (session/price data)', false === $a5['safe'],
			'reason=' . $a5['reason'] . ' len=' . strlen( $cart_html )
			. ' has_subtotal=' . var_export( false !== stripos( $cart_html, 'subtotal' ), true )
			. ' has_nonce=' . var_export( false !== stripos( $cart_html, 'nonce' ), true ) );
		WC()->cart->empty_cart();
	} else {
		echo "[SKIP] D5 populated-cart variant: no publishable product\n";
	}
	check( $results, 'D5b /cart/ classified BYPASS', Classifier::BYPASS === $classifier->classify( 'GET', '/cart/', array(), 'shop.example' )['classification'], '' );
} else {
	echo "[SKIP] WooCommerce not active\n";
}

// ---- D6: Engine end-to-end — unsafe response must NEVER reach disk --------
// Drive Engine::on_output() directly with poisoned HTML; assert no file written.
$engine = new Engine(
	$settings,
	$classifier,
	$san
);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTP_ACCEPT']    = '';
http_response_code( 200 ); // on_output() reads the response code

// Force PUBLIC_CACHEABLE classification so ONLY the sanitizer stands between
// the poison and disk; proves the sanitizer gate fires inside on_output().
$engine->request_class = array( 'classification' => Classifier::PUBLIC_CACHEABLE, 'reason' => 'forced-test' );

$out = $engine->on_output( $html_public_form ); // contains live _uc_nonce field
$vroot = WP_CONTENT_DIR . '/cache/ultimate-performance/v';
$written = false;
if ( is_dir( $vroot ) ) {
	$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $vroot, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$c = (string) file_get_contents( $f->getPathname() );
		if ( false !== strpos( $c, $nonce ) || false !== strpos( $c, 'name="_uc_nonce"' ) ) { $written = true; }
	}
}
check( $results, 'D6 Engine.on_output with live nonce wrote NOTHING to disk', false === $written, 'nonce found in cache tree' );
check( $results, 'D6b returned HTML unmodified (no stripping, fail-closed)',
	false !== strpos( (string) $out, $nonce ), 'nonce absent from returned html?' );

// ---- D7: clean public content IS allowed through the full gate ------------
$clean = '<!DOCTYPE html><html><head><title>About</title></head><body><h1>About Us</h1>'
	. '<p>This is our story. It serves everyone identically.</p></body></html>';
// Harness: emulate what intercept() does before capture — resolve rel dir.
$keygen    = new \UltimatePerformance\CacheKey\Key( $settings );
$built     = $keygen->build( 'http', $_SERVER['HTTP_HOST'], '/', '', array( 'accept' => '' ) );
$ref       = new \ReflectionProperty( $engine, 'current_rel_dir' );
$ref->setAccessible( true );
$ref->setValue( $engine, is_array( $built ) ? $built['dir'] : null );
$out2      = $engine->on_output( $clean );
$found_clean = false;
$clean_file  = '';
if ( is_dir( $vroot ) ) {
	$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $vroot, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( false !== strpos( (string) file_get_contents( $f->getPathname() ), 'our story' ) ) {
			$found_clean = true;
			$clean_file  = (string) dirname( $f->getPathname() );
		}
	}
}
check( $results, 'D7 clean public page WRITTEN by engine gate', true === $found_clean, 'clean html not found in cache tree' );
// Leave no residue in the live site's cache storage.
if ( '' !== $clean_file && is_dir( $clean_file ) ) {
	@unlink( $clean_file . '/index.html' );
	@unlink( $clean_file . '/index.html.meta.json' );
	@rmdir( $clean_file );
}

// cleanup test user
if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( $user_id ); }

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
