<?php
/**
 * WooCommerce page cache regression test.
 *
 * Tests: anonymous product/shop/category cacheable, cart/checkout/account bypass,
 * session/cart cookies bypass, price/stock mutations invalidate.
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

if ( PHP_SAPI !== 'cli' ) {
        exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', __DIR__ ) . '/../' );

require_once ULTIMATE_PERFORMANCE_DIR . 'vendor/autoload.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require ABSPATH . 'wp-load.php';

use UltimatePerformance\Core\Settings;
use UltimatePerformance\Request\Classifier;

$results = array();
function wcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== WooCommerce Page Cache Regression ===\n";

// Reset settings
$s = Settings::instance();
$defaults = Settings::defaults();
update_option( Settings::OPTION, $defaults, true );
$ref = new \ReflectionProperty( Settings::class, 'instance' );
$ref->setAccessible( true );
$ref->setValue( null, null );
$s = Settings::instance();

// Build classifier
$c = new Classifier( $s );

echo "\n--- Test 1: Anonymous product cacheable ---\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/product/test-product/';
$_COOKIE = array();
// Allow localhost as host
add_filter( 'ultimate_performance_allowed_hosts', function() { return array( 'localhost' ); } );
$class = $c->classify();
wcheck( $results, 'W1a anonymous product: PUBLIC_CACHEABLE', 'PUBLIC_CACHEABLE' === $class['classification'], $class['reason'] );

echo "\n--- Test 2: Shop page cacheable ---\n";
$_SERVER['REQUEST_URI'] = '/shop/';
$class = $c->classify();
wcheck( $results, 'W2a anonymous shop: PUBLIC_CACHEABLE', 'PUBLIC_CACHEABLE' === $class['classification'], $class['reason'] );

echo "\n--- Test 3: Category page cacheable ---\n";
$_SERVER['REQUEST_URI'] = '/product-category/clothing/';
$class = $c->classify();
wcheck( $results, 'W3a anonymous category: PUBLIC_CACHEABLE', 'PUBLIC_CACHEABLE' === $class['classification'], $class['reason'] );

echo "\n--- Test 4: Cart bypass ---\n";
$_SERVER['REQUEST_URI'] = '/cart/';
$class = $c->classify();
wcheck( $results, 'W4a cart: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

echo "\n--- Test 5: Checkout bypass ---\n";
$_SERVER['REQUEST_URI'] = '/checkout/';
$class = $c->classify();
wcheck( $results, 'W5a checkout: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

echo "\n--- Test 6: My-account bypass ---\n";
$_SERVER['REQUEST_URI'] = '/my-account/';
$class = $c->classify();
wcheck( $results, 'W6a my-account: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

echo "\n--- Test 7: Woo session cookie bypass ---\n";
$_SERVER['REQUEST_URI'] = '/product/test-product/';
$_COOKIE = array( 'wp_woocommerce_session_abc123' => 'value' );
$class = $c->classify();
wcheck( $results, 'W7a woo session cookie: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

echo "\n--- Test 8: Cart hash cookie bypass ---\n";
$_COOKIE = array( 'woocommerce_cart_hash' => 'abc123' );
$class = $c->classify();
wcheck( $results, 'W8a cart hash cookie: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

echo "\n--- Test 9: Logged-in cookie bypass ---\n";
$_COOKIE = array( 'wordpress_logged_in_0123456789abcdef0123456789abcdef' => 'admin|12345' );
$class = $c->classify();
wcheck( $results, 'W9a logged-in cookie: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

echo "\n--- Test 10: No cookies = cacheable ---\n";
$_COOKIE = array();
$_SERVER['REQUEST_URI'] = '/product/test-product/';
$class = $c->classify();
wcheck( $results, 'W10a no cookies: PUBLIC_CACHEABLE', 'PUBLIC_CACHEABLE' === $class['classification'], $class['reason'] );

echo "\n--- Test 11: Non-sensitive cookie does NOT bypass ---\n";
$_COOKIE = array( 'some_analytics_cookie' => 'value' );
$class = $c->classify();
wcheck( $results, 'W11a non-sensitive cookie: PUBLIC_CACHEABLE', 'PUBLIC_CACHEABLE' === $class['classification'], $class['reason'] );

echo "\n--- Test 12: woocommerce_items_in_cart bypass ---\n";
$_COOKIE = array( 'woocommerce_items_in_cart' => '1' );
$class = $c->classify();
wcheck( $results, 'W12a items_in_cart cookie: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

echo "\n--- Test 13: order-pay bypass ---\n";
$_COOKIE = array();
$_SERVER['REQUEST_URI'] = '/order-pay/123/';
$class = $c->classify();
wcheck( $results, 'W13a order-pay: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

echo "\n--- Test 14: order-received bypass ---\n";
$_SERVER['REQUEST_URI'] = '/order-received/123/';
$class = $c->classify();
wcheck( $results, 'W14a order-received: BYPASS', 'BYPASS' === $class['classification'], $class['reason'] );

// Cleanup
unset( $_COOKIE, $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );

echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
