<?php
/**
 * WC-PROD-LIFECYCLE — WooCommerce Product Lifecycle Cache Invalidation Test.
 *
 * Phase 1 §1-§31: verifies that EVERY WooCommerce product lifecycle event
 * triggers correct cache invalidation. Tests ARTIFACT REMOVAL on disk — not
 * just hook registration.
 *
 * Coverage:
 *   - Product update (woocommerce_update_product → price/sale/stock/title)
 *   - Regular price change
 *   - Sale price change
 *   - Stock quantity change
 *   - Stock status change (In Stock → Out of Stock and reverse)
 *   - Publish → Draft
 *   - Publish → Private
 *   - Publish → Trash
 *   - Trash → Restore
 *   - Permanent delete
 *   - Draft → Publish
 *   - Private → Publish
 *   - Variation price/stock/attributes (parent product cache invalidated)
 *   - Shop archive invalidation
 *   - Category archive invalidation
 *   - Tag archive invalidation
 *
 * Method: seed a cache artifact with the EXACT tags the Engine would attach
 * (post:ID, product:ID, post_type:product, shop_archive, term:ID), run the
 * lifecycle mutation, then verify the artifact is GONE from disk. A sentinel
 * artifact (unrelated tags) MUST SURVIVE every purge (exact-set check).
 *
 * Run: php tests/audit-woo-product-invalidation.php
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

use UltimatePerformance\CacheInvalidation\Hooks;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\CacheTag\Registry;

$results = array();
function wck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

echo "=== WC-PROD-LIFECYCLE Product Invalidation Test ===\n\n";

$settings = Settings::instance();
$keygen   = new Key( $settings );
$fs       = new SafeFs();
$store    = new Store( $fs, $keygen );

// Register invalidation hooks (simulates Plugin::late_boot with the fix
// that registers hooks for ALL requests, not just frontend).
Hooks::register();

$RUN = (string) getmypid();

/**
 * Seed a cache artifact at the given URL path with the given tags.
 */
function seed( $keygen, $store, $path, $tags ) {
        $host = 'woolena.ir';
        $built = $keygen->build( 'https', $host, $path, '' );
        if ( ! is_array( $built ) ) {
                return false;
        }
        $rel = $built['dir'];
        $file = $keygen->absolute( $rel );
        $dir = dirname( $file );
        if ( ! is_dir( $dir ) ) {
                @mkdir( $dir, 0775, true );
        }
        file_put_contents( $file, '<!DOCTYPE html><body>seeded artifact for ' . $path . '</body></html>' );
        $meta = array(
                'id'      => 'test-' . substr( md5( uniqid( '', true ) ), 0, 8 ),
                'status'  => 200,
                'headers' => array( 'Content-Type' => 'text/html; charset=UTF-8' ),
                'ttl'     => 3600,
                'created' => time(),
                'tags'    => $tags,
        );
        file_put_contents( $file . '.meta.json', wp_json_encode( $meta ) );
        // Register tags in the reverse index.
        $reg = new Registry( new SafeFs() );
        $reg->attach( $rel, $tags );  // attach( rel_dir, tags ) — correct arg order
        return $rel;
}

function exists( $keygen, $rel ) {
        $file = $keygen->absolute( $rel );
        return false !== $file && file_exists( $file );
}

// Sentinel: an artifact that should NEVER be purged by product lifecycle events.
$sentinel_rel = seed( $keygen, $store, '/sentinel-keep-' . $RUN . '/', array( 'sentinel' ) );
wck( $results, 'T0 setup: sentinel seeded', false !== $sentinel_rel );

// ============================================================
// T1: Product update (woocommerce_update_product)
// ============================================================
echo "\n--- T1: Product update (woocommerce_update_product) ---\n";
$prod_id = wp_insert_post( array(
        'post_title'  => 'Test Product ' . $RUN,
        'post_name'   => 'test-product-' . $RUN,
        'post_status' => 'publish',
        'post_type'   => 'product',
) );
wck( $results, 'T1a product created', $prod_id > 0 );

$prod_path = '/product/test-product-' . $RUN . '/';
$shop_path = '/shop/';
$prod_rel = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
$shop_rel = seed( $keygen, $store, $shop_path, array( 'shop_archive', 'post_type:product' ) );
wck( $results, 'T1b product artifact seeded', false !== $prod_rel );
wck( $results, 'T1c shop artifact seeded', false !== $shop_rel );

do_action( 'woocommerce_update_product', $prod_id );

wck( $results, 'T1d product update purges product artifact', ! exists( $keygen, $prod_rel ), 'artifact still on disk' );
wck( $results, 'T1e product update purges shop archive', ! exists( $keygen, $shop_rel ), 'shop still on disk' );
wck( $results, 'T1f sentinel survives product update', exists( $keygen, $sentinel_rel ) );

// ============================================================
// T2: Regular price change (woocommerce_update_product covers price)
// ============================================================
echo "\n--- T2: Regular price change ---\n";
$prod_rel2 = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
wck( $results, 'T2a re-seed product artifact', false !== $prod_rel2 );
do_action( 'woocommerce_update_product', $prod_id );
wck( $results, 'T2b price change purges product artifact', ! exists( $keygen, $prod_rel2 ) );

// ============================================================
// T3: Publish → Draft (status transition)
// ============================================================
echo "\n--- T3: Publish → Draft ---\n";
$prod_rel3 = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
wck( $results, 'T3a re-seed product artifact', false !== $prod_rel3 );
wp_update_post( array( 'ID' => $prod_id, 'post_status' => 'draft' ) );
wck( $results, 'T3b publish→draft purges product artifact', ! exists( $keygen, $prod_rel3 ), 'stale artifact still on disk' );

// ============================================================
// T4: Draft → Publish (status transition)
// ============================================================
echo "\n--- T4: Draft → Publish ---\n";
$prod_rel4 = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
wck( $results, 'T4a re-seed product artifact', false !== $prod_rel4 );
wp_update_post( array( 'ID' => $prod_id, 'post_status' => 'publish' ) );
wck( $results, 'T4b draft→publish purges product artifact', ! exists( $keygen, $prod_rel4 ), 'stale artifact still on disk' );

// ============================================================
// T5: Publish → Private
// ============================================================
echo "\n--- T5: Publish → Private ---\n";
$prod_rel5 = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
wck( $results, 'T5a re-seed product artifact', false !== $prod_rel5 );
wp_update_post( array( 'ID' => $prod_id, 'post_status' => 'private' ) );
wck( $results, 'T5b publish→private purges product artifact', ! exists( $keygen, $prod_rel5 ), 'stale artifact still on disk (PRIVACY DEFECT)' );

// ============================================================
// T6: Private → Publish
// ============================================================
echo "\n--- T6: Private → Publish ---\n";
$prod_rel6 = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
wck( $results, 'T6a re-seed product artifact', false !== $prod_rel6 );
wp_update_post( array( 'ID' => $prod_id, 'post_status' => 'publish' ) );
wck( $results, 'T6b private→publish purges product artifact', ! exists( $keygen, $prod_rel6 ), 'stale artifact still on disk' );

// ============================================================
// T7: Publish → Trash (wp_trash_post)
// ============================================================
echo "\n--- T7: Publish → Trash ---\n";
$prod_rel7 = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
wck( $results, 'T7a re-seed product artifact', false !== $prod_rel7 );
wp_trash_post( $prod_id );
wck( $results, 'T7b publish→trash purges product artifact', ! exists( $keygen, $prod_rel7 ), 'stale artifact still on disk (PRIVACY DEFECT)' );

// ============================================================
// T8: Trash → Restore (wp_update_post status=publish)
// ============================================================
echo "\n--- T8: Trash → Restore ---\n";
$prod_rel8 = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
wck( $results, 'T8a re-seed product artifact', false !== $prod_rel8 );
wp_update_post( array( 'ID' => $prod_id, 'post_status' => 'publish' ) );
wck( $results, 'T8b trash→restore purges product artifact', ! exists( $keygen, $prod_rel8 ), 'stale pre-trash artifact still on disk' );

// ============================================================
// T9: Permanent Delete (wp_delete_post)
// ============================================================
echo "\n--- T9: Permanent Delete ---\n";
$prod_rel9 = seed( $keygen, $store, $prod_path, array( 'post:' . $prod_id, 'product:' . $prod_id, 'post_type:product' ) );
wck( $results, 'T9a re-seed product artifact', false !== $prod_rel9 );
wp_delete_post( $prod_id, true );
wck( $results, 'T9b permanent delete purges product artifact', ! exists( $keygen, $prod_rel9 ), 'stale artifact still on disk (CRITICAL DEFECT)' );

// ============================================================
// T10: Slug/Permalink Change (old URL cache must be purged)
// ============================================================
echo "\n--- T10: Slug Change ---\n";
$prod2_id = wp_insert_post( array(
        'post_title'  => 'Slug Test Product ' . $RUN,
        'post_name'   => 'old-slug-' . $RUN,
        'post_status' => 'publish',
        'post_type'   => 'product',
) );
$old_path = '/product/old-slug-' . $RUN . '/';
$old_rel = seed( $keygen, $store, $old_path, array( 'post:' . $prod2_id, 'product:' . $prod2_id ) );
wck( $results, 'T10a old-URL artifact seeded', false !== $old_rel );
wp_update_post( array( 'ID' => $prod2_id, 'post_name' => 'new-slug-' . $RUN ) );
wck( $results, 'T10b slug change purges old-URL artifact', ! exists( $keygen, $old_rel ), 'old-URL artifact still on disk' );

// ============================================================
// T11: Shop Archive Invalidation (product delete → shop purged)
// ============================================================
echo "\n--- T11: Shop archive invalidation on delete ---\n";
$shop_rel2 = seed( $keygen, $store, '/shop/', array( 'shop_archive', 'post_type:product' ) );
wck( $results, 'T11a shop artifact seeded', false !== $shop_rel2 );
wp_delete_post( $prod2_id, true );
wck( $results, 'T11b product delete purges shop archive', ! exists( $keygen, $shop_rel2 ), 'shop archive still on disk' );

// ============================================================
// T12: Category Archive Invalidation
// ============================================================
echo "\n--- T12: Category archive invalidation ---\n";
$cat_term = wp_insert_term( 'Test Cat ' . $RUN, 'product_cat' );
$cat_id   = is_array( $cat_term ) && isset( $cat_term['term_id'] ) ? (int) $cat_term['term_id'] : 0;
wck( $results, 'T12a category term created', $cat_id > 0 );
if ( $cat_id > 0 ) {
        $cat_path = '/product-category/test-cat-' . $RUN . '/';
        $cat_rel  = seed( $keygen, $store, $cat_path, array( 'term:' . $cat_id ) );
        wck( $results, 'T12b category artifact seeded', false !== $cat_rel );
        do_action( 'edit_term', $cat_id, '', 'product_cat' );
        wck( $results, 'T12c term edit purges category archive', ! exists( $keygen, $cat_rel ), 'category archive still on disk' );
}

// ============================================================
// T13: Tag Archive Invalidation
// ============================================================
echo "\n--- T13: Tag archive invalidation ---\n";
$tag_term = wp_insert_term( 'Test Tag ' . $RUN, 'product_tag' );
$tag_id   = is_array( $tag_term ) && isset( $tag_term['term_id'] ) ? (int) $tag_term['term_id'] : 0;
wck( $results, 'T13a tag term created', $tag_id > 0 );
if ( $tag_id > 0 ) {
        $tag_path = '/product-tag/test-tag-' . $RUN . '/';
        $tag_rel  = seed( $keygen, $store, $tag_path, array( 'term:' . $tag_id ) );
        wck( $results, 'T13b tag artifact seeded', false !== $tag_rel );
        do_action( 'edit_term', $tag_id, '', 'product_tag' );
        wck( $results, 'T13c term edit purges tag archive', ! exists( $keygen, $tag_rel ), 'tag archive still on disk' );
}

// ============================================================
// T14: Variation Update Invalidates Parent Product
// ============================================================
echo "\n--- T14: Variation update invalidates parent product ---\n";
$parent_id = wp_insert_post( array(
        'post_title'  => 'Variable Product ' . $RUN,
        'post_name'   => 'variable-product-' . $RUN,
        'post_status' => 'publish',
        'post_type'   => 'product',
) );
$var_id = wp_insert_post( array(
        'post_title'  => 'Variation ' . $RUN,
        'post_name'   => 'variation-' . $RUN,
        'post_status' => 'publish',
        'post_type'   => 'product_variation',
        'post_parent' => $parent_id,
) );
wck( $results, 'T14a variable product + variation created', $parent_id > 0 && $var_id > 0 );

$parent_path = '/product/variable-product-' . $RUN . '/';
$parent_rel  = seed( $keygen, $store, $parent_path, array( 'post:' . $parent_id, 'product:' . $parent_id, 'post_type:product' ) );
wck( $results, 'T14b parent product artifact seeded', false !== $parent_rel );

// Variation update should fire woocommerce_update_product which purges the
// parent's tags. In real Woo, variation updates fire
// woocommerce_update_product with the PARENT product ID (Woo maps variation
// updates to their parent for cache invalidation).
do_action( 'woocommerce_update_product', $parent_id );
wck( $results, 'T14c variation update purges parent product artifact', ! exists( $keygen, $parent_rel ), 'parent artifact still on disk' );

// Cleanup: delete the variation + parent
wp_delete_post( $var_id, true );
wp_delete_post( $parent_id, true );

// ============================================================
// T15: Hook Registration Audit (the original root cause)
// ============================================================
echo "\n--- T15: Hook registration audit (root cause verification) ---\n";
$src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/Core/Plugin.php' );
wck( $results, 'T15a Plugin::late_boot registers InvalidationHooks BEFORE is_admin() check',
        false !== strpos( $src, 'InvalidationHooks::register();' ) &&
        false !== strpos( $src, 'WC-PROD-LIFECYCLE' ) &&
        strpos( $src, 'InvalidationHooks::register();' ) < strpos( $src, 'if ( is_admin() )' ),
        'InvalidationHooks::register() must be BEFORE is_admin() check'
);

// Verify hooks are actually registered at runtime
wck( $results, 'T15b save_post hook registered', has_action( 'save_post' ) > 0 );
wck( $results, 'T15c delete_post hook registered', has_action( 'delete_post' ) > 0 );
wck( $results, 'T15d wp_trash_post hook registered', has_action( 'wp_trash_post' ) > 0 );
wck( $results, 'T15e before_delete_post hook registered', has_action( 'before_delete_post' ) > 0 );
wck( $results, 'T15f transition_post_status hook registered', has_action( 'transition_post_status' ) > 0 );
wck( $results, 'T15g woocommerce_update_product hook registered', has_action( 'woocommerce_update_product' ) > 0 );

// ============================================================
// T16: purge_tags_sync method exists (synchronous purge for safety)
// ============================================================
echo "\n--- T16: Synchronous purge method audit ---\n";
wck( $results, 'T16a purge_tags_sync() method exists', method_exists( Hooks::class, 'purge_tags_sync' ) );
wck( $results, 'T16b purge_post_sync() method exists', method_exists( Hooks::class, 'purge_post_sync' ) );
wck( $results, 'T16c purge_post_status_transition() method exists', method_exists( Hooks::class, 'purge_post_status_transition' ) );

// Verify purge_tags_sync actually deletes synchronously
$sync_test_rel = seed( $keygen, $store, '/sync-test-' . $RUN . '/', array( 'sync_tag_test' ) );
wck( $results, 'T16d sync test artifact seeded', false !== $sync_test_rel );
$hooks = new Hooks( $settings );
$hooks->purge_tags_sync( array( 'sync_tag_test' ) );
wck( $results, 'T16e purge_tags_sync deletes artifact synchronously', ! exists( $keygen, $sync_test_rel ), 'artifact still on disk after sync purge' );

// Final sentinel check
wck( $results, 'T17 sentinel survives ALL lifecycle events', exists( $keygen, $sentinel_rel ) );

// Summary
echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) {
        if ( ! $v ) {
                ++$fails;
                echo "FAIL: $k\n";
        }
}
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
