<?php
/**
 * BENCH-D4 regression test — WooCommerce sanitizer correctness.
 *
 * HARDEN-3: Verifies that the ResponseSanitizer does NOT over-block
 * normal WooCommerce pages (which contain structural mini-cart containers
 * on 100% of pages) while still blocking RENDERED personalized content
 * (actual cart items, cart totals, checkout forms with user input).
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

use UltimatePerformance\Security\ResponseSanitizer;

$results = array();
function wcheck( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = (bool) $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== BENCH-D4 WooCommerce Sanitizer Regression Test ===\n";

$san = new ResponseSanitizer();

// A minimal clean HTML baseline (64+ bytes to pass the size gate).
$clean = '<!DOCTYPE html><html><head><title>Test</title></head><body><h1>Shop</h1><p>Welcome to our store.</p></body></html>';

// ============================================================
// CACHEABLE: Real-world WooCommerce page with EMPTY mini-cart
// (the structural markup present on 100% of Woo pages)
// ============================================================
echo "\n--- CACHEABLE: Empty mini-cart structural markup ---\n";

// This is the ACTUAL markup from the benchmark that was over-blocked.
$empty_mini_cart = '<div data-block-name="woocommerce/mini-cart" class="wc-block-mini-cart wp-block-woocommerce-mini-cart">
<button class="wc-block-mini-cart__button" aria-label="Cart">
<span class="wc-block-mini-cart__quantity-badge">
<svg class="wc-block-mini-cart__icon" viewBox="0 0 32 32"></svg>
</span></button>
<div class="wc-block-mini-cart__drawer">
<div class="wc-block-mini-cart__template-part">
<div data-block-name="woocommerce/mini-cart-contents" class="wp-block-woocommerce-mini-cart-contents">
<div data-block-name="woocommerce/filled-mini-cart-contents-block" class="wp-block-woocommerce-filled-mini-cart-contents-block">
<div data-block-name="woocommerce/mini-cart-items-block" class="wp-block-woocommerce-mini-cart-items-block">
<div data-block-name="woocommerce/mini-cart-products-table-block" class="wp-block-woocommerce-mini-cart-products-table-block">
</div>
</div>
<div data-block-name="woocommerce/mini-cart-footer-block" class="wp-block-woocommerce-mini-cart-footer-block">
<div data-block-name="woocommerce/mini-cart-cart-button-block" class="wp-block-woocommerce-mini-cart-cart-button-block"></div>
<div data-block-name="woocommerce/mini-cart-checkout-button-block" class="wp-block-woocommerce-mini-cart-checkout-button-block"></div>
</div>
</div>
</div>
</div>
</div>' . $clean;

$a = $san->audit( $empty_mini_cart );
wcheck( $results, 'W1 empty mini-cart structural markup is CACHEABLE', $a['safe'], "reason={$a['reason']}" );

// Shop archive page with product listings (public, cacheable)
$shop_page = '<div class="woocommerce columns-4">
<ul class="products columns-4">
<li class="product type-product">
<a href="/product/t-shirt/" class="woocommerce-LoopProduct-link">
<h2>T-Shirt</h2>
<span class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>19.99</bdi></span></span>
</a>
</li>
<li class="product type-product">
<a href="/product/hoodie/" class="woocommerce-LoopProduct-link">
<h2>Hoodie</h2>
<span class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>39.99</bdi></span></span>
</a>
</li>
</ul>
</div>' . $clean;

$a = $san->audit( $shop_page );
wcheck( $results, 'W2 shop archive with product prices is CACHEABLE', $a['safe'], "reason={$a['reason']}" );

// Single product page (public, cacheable)
$product_page = '<div class="woocommerce single-product">
<div class="product type-product">
<h1>T-Shirt</h1>
<p class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>19.99</bdi></span></p>
<button name="add-to-cart" value="42">Add to cart</button>
</div>
</div>' . $clean;

$a = $san->audit( $product_page );
wcheck( $results, 'W3 single product page is CACHEABLE', $a['safe'], "reason={$a['reason']}" );

// Product category page (public, cacheable)
$category_page = '<div class="woocommerce archive">
<h1>Clothing</h1>
<ul class="products">
<li class="product"><a href="/product/t-shirt/">T-Shirt</a> <span class="price">$19.99</span></li>
</ul>
</div>' . $clean;

$a = $san->audit( $category_page );
wcheck( $results, 'W4 product category page is CACHEABLE', $a['safe'], "reason={$a['reason']}" );

// ============================================================
// NOT CACHEABLE: Rendered personalized WooCommerce content
// ============================================================
echo "\n--- NOT CACHEABLE: Rendered personalized content ---\n";

// RENDERED cart item (actual product in cart, with data-product_id)
$rendered_cart_item = '<ul class="woocommerce-mini-cart cart_list">
<li class="woocommerce-mini-cart-item mini_cart_item" data-product_id="42">
<a href="/product/t-shirt/">T-Shirt</a>
<span class="quantity">1 × <span class="woocommerce-Price-amount amount"><bdi>$19.99</bdi></span></span>
</li>
</ul>' . $clean;

$a = $san->audit( $rendered_cart_item );
wcheck( $results, 'W5 rendered cart item with data-product_id is BLOCKED', ! $a['safe'], "reason={$a['reason']}" );

// RENDERED cart total (mini-cart__total containing Price-amount)
$rendered_cart_total = '<div class="woocommerce-mini-cart__total">
<strong>Subtotal:</strong>
<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>19.99</bdi></span>
</div>' . $clean;

$a = $san->audit( $rendered_cart_total );
wcheck( $results, 'W6 rendered cart total with Price-amount is BLOCKED', ! $a['safe'], "reason={$a['reason']}" );

// Checkout form with billing_email
$checkout_form = '<form name="checkout" class="woocommerce-checkout" method="post">
<p class="form-row">
<label>Email <input name="billing_email" value="customer@example.com"></label>
</p>
<button type="submit">Place order</button>
</form>' . $clean;

$a = $san->audit( $checkout_form );
wcheck( $results, 'W7 checkout form with billing_email is BLOCKED', ! $a['safe'], "reason={$a['reason']}" );

// Order overview (post-checkout confirmation)
$order_overview = '<div class="woocommerce-order-overview">
<ul>
<li>Order: #1234</li>
<li>Date: September 13, 2026</li>
<li>Email: customer@example.com</li>
<li>Total: <span class="woocommerce-Price-amount">$19.99</span></li>
</ul>
</div>' . $clean;

$a = $san->audit( $order_overview );
wcheck( $results, 'W8 order overview (post-checkout) is BLOCKED', ! $a['safe'], "reason={$a['reason']}" );

// Cart nonce
$cart_nonce = '<input name="woocommerce-cart-nonce" value="abcdef1234">' . $clean;
$a = $san->audit( $cart_nonce );
wcheck( $results, 'W9 cart nonce field is BLOCKED', ! $a['safe'], "reason={$a['reason']}" );

// ============================================================
// Summary
// ============================================================
echo "\n=== SUMMARY ===\n";
$pass_count = count( array_filter( $results ) );
$total = count( $results );
echo "$pass_count / $total checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
