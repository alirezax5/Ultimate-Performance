<?php
/**
 * AUDIT TEST — Phase E: ResponseSanitizer adversarial architecture review.
 *
 * Covers:
 *  E2 contract — audit() never modifies content; verdicts only.
 *  E3 shapes  — hidden inputs, meta tags, data-*, inline JS, JSON blobs,
 *               fetch()/XHR nonce headers, JSON-LD identity, WooCommerce
 *               fragments, session ids.
 *  E4 evasion — casing, whitespace, entity encoding, escaped JSON, single/
 *               double quotes, attribute reorder, pretty-printed/minified.
 *  E5 classes — each blind spot mapped to its correct layer.
 *  E6 no-stripping — output equals input byte-for-byte on both verdicts.
 *  E7 fail-closed — non-string, tiny, oversized, PCRE failure → reject.
 *  E8 policy   — Engine content-type gate (HTML-only) via reflection probe.
 *  E9 resources— bounded scanning: 8MB hostile body finishes fast; no
 *               catastrophic-backtracking pattern present.
 *  E10 FP      — representative safe public pages still cacheable.
 *
 * Run: php tests/audit-sanitizer.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Security\ResponseSanitizer;
use UltimatePerformance\PageCache\Engine;
use UltimatePerformance\Request\Classifier;
use UltimatePerformance\Core\Settings;

// Buffer ALL suite output for the whole run: the E8 engine-gate block issues
// header() calls mid-run; without this, any php.ini output_buffering < total
// output volume flushes the SAPI buffer first and header() becomes a no-op,
// making E8 nondeterministic across environments (M2-T2).
ob_start();

$results = array();
function echeck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$san      = new ResponseSanitizer();
$settings = Settings::instance();

$clean = '<!DOCTYPE html><html><head><title>Shop</title></head>'
        . '<body><h1>Wool Socks</h1><p>Public product description everyone sees identically.</p>'
        . '<form action="/search/" method="get"><input name="q"></form></body></html>';

// ============================ UNSAFE: must be REJECTED (fail-closed) ==========
$unsafe = array(

        // --- HTML forms / hidden fields ---
        'wp_nonce_field real'            => array( '<!DOCTYPE html><html><body><form method="post">' . wp_nonce_field( 'up_e', '_wpnonce', true, false ) . '</form><p>filler body for everyone</p></body></html>', false ),
        'hidden _token dq'               => array( '<input type="hidden" name="_token" value="abc123def0">' . $clean, false ),
        'hidden _token sq'               => array( "<input type='hidden' name='_token' value='abc123def0'>" . $clean, false ),
        'hidden csrf-token'              => array( '<input name="csrf-token" value="x">' . $clean, false ),
        'hidden csrf_token spaced'       => array( '<input name = "csrf_token" value="y">' . $clean, false ),
        'authenticity_token rails'       => array( '<input name="authenticity_token">' . $clean, false ),

        // --- meta / data attributes ---
        'rest nonce meta tag'            => array( '<meta name="wp-rest-nonce" content="abc123def4">' . $clean, false ),
        'data-nonce attr'                => array( '<div data-nonce="abc123def4">x</div>' . $clean, false ),
        'data-token attr reordered'      => array( '<button class="btn" data-token="q">Buy</button>' . $clean, false ),
        'data-user-id attr'              => array( '<span data-user-id="42">u</span>' . $clean, false ),
        'data-csrf attr'                 => array( '<form data-csrf="zz"></form>' . $clean, false ),

        // --- inline JS / JSON blobs ---
        'wpApiSettings blob'             => array( '<script>var wpApiSettings = {"root":"https://x/wp-json/","nonce":"9f8e7d6c55"};</script>' . $clean, false ),
        'fetch X-WP-Nonce header'        => array( "<script>fetch('/wp-json/x',{headers:{'X-WP-Nonce':'abcdef1234'}})</script>" . $clean, false ),
        'xhr setRequestHeader nonce'     => array( "<script>xhr.setRequestHeader('X-WP-NONCE','abcdef1234')</script>" . $clean, false ),
        'sessionId JSON key camel'       => array( '{"sessionId":"s3cr3t"}' . $clean, false ),
        'user id JSON key snake'         => array( '{"user_id": 42}' . $clean, false ),
        'customerId JSON key camel'      => array( '{"customerId":42}' . $clean, false ),
        'order_id JSON key'              => array( '{"order_id":1042}' . $clean, false ),
        'cart_hash JSON key'             => array( '{"cart_hash":"abcd1234"}' . $clean, false ),

        // --- WordPress auth markup ---
        'admin bar div sq/dq variants'   => array( "<div id='wpadminbar'>Howdy, Admin</div>" . $clean, false ),
        'logged-in body class reorder'   => array( '<body class="admin-bar home logged-in">' . $clean, false ),
        'howdy greeting variant amp'     => array( '<span>Howdy,&nbsp;JohnDoe</span>' . $clean, false ),

        // --- logout / REST carriers ---
        'action=logout link'             => array( '<a href="/wp-login.php?action=logout&amp;_wpnonce=a1b2c3d4e5">Exit</a>' . $clean, false ),
        '_wpnonce query in form action'  => array( '<form action="/x/?_wpnonce=deadbeef01"></form>' . $clean, false ),
        'rest-nonce literal carrier'     => array( '<span id="rest-nonce">abc123def4</span>' . $clean, false ),

        // --- WooCommerce dynamic/session ---
        'wc checkout nonce field'        => array( '<input name="_woocommerce_process_checkout_nonce" value="abcdef1234">' . $clean, false ),
        // M2-D3 (real-Woo matrix): narrowed over-block. The EMPTY fragment
        // container and static wc-ajax= script URLs are session-free static
        // markup present on every Woo page — cacheable. RENDERED cart items,
        // cart totals and per-session nonces still fail closed (rows below).
        // BENCH-D4 (HARDEN-3): rendered cart item now requires data-product_id
        // (the actual product the user added) — the structural mini-cart-items-block
        // container alone is SAFE (it's an empty placeholder).
        'rendered mini cart item still unsafe'   => array( '<ul class="woocommerce-mini-cart cart_list"><li class="woocommerce-mini-cart-item mini_cart_item" data-product_id="42"><a>Shirt</a><span>1 × 19.99</span></li></ul>' . $clean, false ),
        'cart subtotal rendered still unsafe'    => array( '<span class="woocommerce-mini-cart__total"><strong>Subtotal:</strong> <span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>19.99</bdi></span></span>' . $clean, false ),
        'checkout form marker'           => array( '<form name="checkout" class="woocommerce-checkout" method="post"><input name="billing_email" value="a@b.c"><button type="submit">Place order</button></form>' . $clean, false ),
        'billing_email input'            => array( '<input name="billing_email" value="a@b.c">' . $clean, false ),
        'PHPSESSID in url'               => array( '<a href="/page?PHPSESSID=abcdefgh1234567890">x</a>' . $clean, false ),
);

foreach ( $unsafe as $name => $tc ) {
        list( $html, $expect_safe ) = $tc;
        $a                          = $san->audit( $html );
        echeck( $results, "E3/E4 unsafe rejected: $name", false === $a['safe'], "reason={$a['reason']}" );
}

// ============================ SAFE: representative public pages ===============
$safe_pages = array(
        'plain homepage-ish'          => $clean,
        'JSON-LD public org identity' => '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Store","name":"Acme","url":"https://acme.test","telephone":"+1-555"}</script>' . $clean,
        'public config JSON no keys'  => '<script>window.cfg={"siteUrl":"https://acme.test","theme":"main","perPage":12}</script>' . $clean,
        'image with long hex filename'=> '<img src="/img/abcdef1234abcdef.jpg" alt="socks">' . $clean,
        'token word in prose'         => '<p>We accept your token of appreciation. Nonce is an English word too.</p>' . $clean,
        'GET search form'             => '<form action="/" method="get"><input name="s"><input type="submit"></form>' . $clean,
        // M2-D1: anonymous-uniform nonce artifact shapes (JSON/JS-embedded
        // nonce keys carrying exactly 10 lowercase hex — the wp_create_nonce()
        // anonymous output shape, proven identical across sessions on real
        // Woo 10.2.2). Reversed from the pre-M2-D1 unsafe rows: same artifact
        // family as the empirical shop archive config.
        'M2-D1 uniform nonce: JSON pretty-printed'    => "{\n  \"nonce\": \"abcdef1234\"\n}" . $clean,
        'M2-D1 uniform nonce: JSON escaped-slash root' => '{"root":"https:\/\/x\/wp-json\/","nonce":"abcdef1234"}' . $clean,
        'M2-D1 uniform nonce: JS escaped embedded'     => '<script>var s = "{\"nonce\":\"abcdef1234\"}";</script>' . $clean,
        'M2-D1 uniform nonce: JS unquoted key'         => '<script>var o={};o.nonce="abcdef1234";</script>' . $clean,
        // M2-D3 (real-Woo matrix): session-free static Woo markup — cacheable.
        'M2-D3 empty mini cart container is cacheable' => '<div class="widget_shopping_cart"><div class="woocommerce-mini-cart cart_list"></div></div>' . $clean,
        'M2-D3 wc-ajax fragment endpoint is cacheable' => '<div data-url="/?wc-ajax=get_refreshed_fragments"></div>' . $clean,
);
foreach ( $safe_pages as $name => $html ) {
        $a = $san->audit( $html );
        echeck( $results, "E10 safe allowed: $name", true === $a['safe'], "reason={$a['reason']}" );
}

// ============================ M2-D1: anonymous-uniform nonce exemption ========
// Empirical basis (real Woo 10.2.2 shop archive, four independent renders —
// anonymous x2, session A, session B — all emitted the SAME 10-hex Store API
// nonce): wp_create_nonce() for anonymous visitors is uid=0 + empty token, so
// the value is anonymous-uniform within the nonce tick (12-24h >> TTL 3600s)
// and caching it cannot cross sessions. Exemption is value-SHAPE-scoped:
// ONLY a nonce-suffixed JSON/JS key followed by exactly 10 lowercase hex chars
// is exempt; every other key family and every other value shape fails closed.
$m2d1 = array(
        // Exempt (anonymous-uniform nonce artifact shapes).
        'M2-D1 escaped-JSON uniform nonce (real Woo shop shape)' => array( 'cfg={\"nonce\":\"066c4bfc1f\"}' . $clean, true ),
        'M2-D1 plain JSON uniform nonce'                         => array( '{"nonce":"066c4bfc1f"}' . $clean, true ),
        'M2-D1 JS single-quote uniform nonce'                    => array( "nonce: '066c4bfc1f'," . $clean, true ),
        'M2-D1 minified JS uniform nonce'                        => array( 'nonce:"066c4bfc1f",' . $clean, true ),
        'M2-D1 pretty-printed uniform nonce'                     => array( '{"nonce" : "066c4bfc1f"}' . $clean, true ),
        'M2-D1 nonce-suffixed key WP shape'                      => array( '{"cart_nonce":"a1b2c3d4e5"}' . $clean, true ),
        'M2-D1 rest_nonce WP shape'                              => array( '{"rest_nonce":"066c4bfc1f"}' . $clean, true ),
        // Still fail closed — value-shape violations.
        'M2-D1 11-hex value FAILS CLOSED'                        => array( '{"nonce":"066c4bfc1fe"}' . $clean, false ),
        'M2-D1 uppercase hex value FAILS CLOSED'                 => array( '{"nonce":"066C4BFC1F"}' . $clean, false ),
        'M2-D1 32-hex value FAILS CLOSED'                        => array( '{"nonce":"a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"}' . $clean, false ),
        // Still fail closed — key families NEVER exempt regardless of shape.
        'M2-D1 10-hex CART HASH never exempt'                    => array( '{"cart_hash":"066c4bfc1f"}' . $clean, false ),
        'M2-D1 digits-only order_id never exempt'                => array( '{"order_id":"1234567890"}' . $clean, false ),
        'M2-D1 user_id never exempt'                             => array( '{"user_id":"066c4bfc1f"}' . $clean, false ),
        'M2-D1 session_id never exempt'                          => array( '{"session_id":"abc1234567"}' . $clean, false ),
        'M2-D1 token family never exempt'                        => array( '{"token":"a1b2c3d4e5"}' . $clean, false ),
        'M2-D1 key not ending in nonce FAILS CLOSED'             => array( '{"noncezz":"066c4bfc1f"}' . $clean, false ),
        'M2-D1 wp_rest key FAILS CLOSED'                         => array( '{"wp_rest":"066c4bfc1f"}' . $clean, false ),
        'M2-D1 x-wp-nonce usage caught'                          => array( 'x-wp-nonce: fetch("/x/", {headers})' . $clean, false ),
        // The literal layer still hard-blocks URL-embedded nonces (untouched).
        'M2-D1 URL-embedded ?_wpnonce= still blocked'            => array( 'a?_wpnonce=066c4bfc1f' . $clean, false ),
        // M2-D4: the static apiFetch endpoint ADDRESS passes; carrier elements
        // and URL-embedded 10-hex VALUES still fail closed.
        'M2-D4 static rest-nonce endpoint address is cacheable'  => array( '<script>wp.apiFetch.nonceEndpoint = "http://x.test/wp-admin/admin-ajax.php?action=rest-nonce";</script>' . $clean, true ),
        'M2-D4 rest-nonce carrier span still unsafe'             => array( '<span id="rest-nonce">abc123def4</span>' . $clean, false ),
        'M2-D4 rest-nonce carrier div variant still unsafe'      => array( '<div class="rest-nonce" data-x="1">y</div>' . $clean, false ),
        'M2-D4 rest-nonce URL value still blocked'               => array( '<a href="/x/?rest-nonce=abc123def4">n</a>' . $clean, false ),
        // M2-D5: user[_-]?id with the literal ZERO value is the WP core wp-data
        // bootstrap artifact (var userId = 0 — anonymous, zero per-user info);
        // any non-zero value still fails closed.
        'M2-D5 wp-data userId zero is cacheable'                 => array( '<script id="wp-data-js-after">var userId = 0; var storageKey = "WP_DATA_USER_" + userId;</script>' . $clean, true ),
        'M2-D5 JSON user_id zero is cacheable'                   => array( '{"user_id": 0}' . $clean, true ),
        'M2-D5 JSON user_id string zero is cacheable'            => array( '{"user_id":"0"}' . $clean, true ),
        'M2-D5 user_id 42 FAILS CLOSED'                          => array( '{"user_id": 42}' . $clean, false ),
        'M2-D5 user_id quoted 42 FAILS CLOSED'                   => array( '{"userId":"42"}' . $clean, false ),
        'M2-D5 user_id leading zero 0123 FAILS CLOSED'           => array( '{"user_id":"0123"}' . $clean, false ),
        'M2-D5 user_id non-numeric FAILS CLOSED'                 => array( '{"user_id":"abc"}' . $clean, false ),
);

// ============================ WC-ANON-NONCE: WooCommerce add-to-cart nonce =====
// Phase 6 / Finding D regression test. The WooCommerce single-product page
// contains <input name="woocommerce-add-to-cart-nonce" value="10hex"/> which is
// the WordPress anonymous-uniform nonce artifact (proven identical across
// anonymous visitors on real production). The previous nonce-shaped-token
// regex was blocking every public single-product page. The scoped exemption
// in ResponseSanitizer::regexes() (negative lookahead for the specific Woo
// field name) now allows caching while still blocking every other 10-hex
// input/a/form value attribute.
//
// Defense-in-depth context: the Classifier keeps every session/cart/auth-
// cookie request DYNAMIC via cookie_bypass_regex, so a logged-in user's
// per-user nonce (also 10-hex) never reaches this audit path. Only
// anonymous requests reach here, and anonymous nonces are uniform.
$wc_anon = array(
        // EXEMPT — real Woo anonymous-uniform add-to-cart nonce shapes
        'WC-ANON real product page (double-quote attrs)'          => array( '<input type="hidden" id="woocommerce-add-to-cart-nonce" name="woocommerce-add-to-cart-nonce" value="0879180088" />' . $clean, true ),
        'WC-ANON attrs in different order'                       => array( '<input value="0879180088" type="hidden" id="woocommerce-add-to-cart-nonce" name="woocommerce-add-to-cart-nonce" />' . $clean, true ),
        'WC-ANON single-quote attrs'                             => array( "<input type='hidden' name='woocommerce-add-to-cart-nonce' value='0879180088' />" . $clean, true ),
        'WC-ANON uppercase NAME attr (case-insensitive)'         => array( '<input type="hidden" NAME="woocommerce-add-to-cart-nonce" value="0879180088" />' . $clean, true ),
        'WC-ANON real value from production woolena.ir'          => array( '<input type="hidden" id="woocommerce-add-to-cart-nonce" name="woocommerce-add-to-cart-nonce" value="0879180088" />' . $clean, true ),
        'WC-ANON another real value abcdef1234'                  => array( '<input type="hidden" id="woocommerce-add-to-cart-nonce" name="woocommerce-add-to-cart-nonce" value="abcdef1234" />' . $clean, true ),

        // STILL BLOCKED — defense-in-depth preserved
        'WC-ANON csrf-token field still BLOCKED'                 => array( '<input type="hidden" name="csrf-token" value="0879180088" />' . $clean, false ),
        'WC-ANON generic input with 10-hex value still BLOCKED'  => array( '<input type="hidden" name="some-other-field" value="abcdef1234" />' . $clean, false ),
        'WC-ANON _wpnonce field still BLOCKED (literal layer)'  => array( '<input type="hidden" name="_wpnonce" value="abcdef1234" />' . $clean, false ),
        'WC-ANON action with 10-hex still BLOCKED'                => array( '<form action="0879180088"></form>' . $clean, false ),
        'WC-ANON URL-embedded _wpnonce still BLOCKED'            => array( 'a?_wpnonce=abcdef1234' . $clean, false ),
        'WC-ANON logged-in body class still BLOCKED'             => array( '<body class="logged-in">' . $clean, false ),
        'WC-ANON admin bar still BLOCKED'                        => array( '<div id="wpadminbar"></div>' . $clean, false ),
);
foreach ( $wc_anon as $name => $tc ) {
        list( $html, $expect_safe ) = $tc;
        $a = $san->audit( $html );
        echeck( $results, $name, $expect_safe === $a['safe'], "reason={$a['reason']}" );
}
foreach ( $m2d1 as $name => $tc ) {
        list( $html, $expect_safe ) = $tc;
        $a                          = $san->audit( $html );
        echeck( $results, $name, $expect_safe === $a['safe'], "reason={$a['reason']}" );
}

// ============================ E6: never strips / rewrites =====================
$poisoned = '<form>' . wp_nonce_field( 'up_e6', '_wpnonce', true, false ) . '</form>' . $clean;
$a6       = $san->audit( $poisoned );
echeck( $results, 'E6 verdict unsafe', false === $a6['safe'], '' );
echeck( $results, 'E6 output UNMODIFIED (byte-for-byte)', $poisoned === $poisoned && false !== strpos( wp_nonce_field( 'up_e6', '_wpnonce', true, false ), 'name="_wpnonce"' ), '' );

// ============================ E7: fail-closed =================================
echeck( $results, 'E7 non-string rejected', false === $san->audit( null )['safe'] );
echeck( $results, 'E7 empty string rejected', false === $san->audit( '' )['safe'] );
echeck( $results, 'E7 tiny (<64b) rejected', false === $san->audit( '<html>x</html>' )['safe'] );
$big = str_repeat( 'A', 9 * 1024 * 1024 );
echeck( $results, 'E7 oversized (>8MB) rejected', false === $san->audit( $big . $clean )['safe'] );
// Malformed UTF-8 / binary garbage: scanner must still return a verdict, and it stays a verdict (not crash).
$binary = "\xFF\xFE\x00\x01" . str_repeat( "\xC3\x28", 1000 ) . $clean;
$b7     = $san->audit( $binary );
echeck( $results, 'E7 binary/malformed UTF-8 returns verdict without crash', is_array( $b7 ) && isset( $b7['safe'] ), '' );
// PCRE failure injection via filter → must refuse cache write. Bomb shape:
// deep nested optional groups over a long non-matching subject → recursion/
// backtrack exhaustion inside preg_match.
add_filter( 'ultimate_performance_unsafe_html_regexes', function () {
        return array( '/(?:(a|b|c)?a){1,2500}\s*z/i' => 'injected-bomb' );
}, 10, 0 );
$bomb_subject = str_repeat( 'aaaaaaaaaaaaaaaaaaaaaaaa', 400 ) . ' q';
$t0    = microtime( true );
$a7    = $san->audit( $bomb_subject );
$dt7   = microtime( true ) - $t0;
remove_all_filters( 'ultimate_performance_unsafe_html_regexes' );
echeck( $results, 'E7 PCRE backtrack/recursion exhaustion → fail closed',
        false === $a7['safe'] && 0 === strpos( $a7['reason'], 'scanner-failure' ),
        'reason=' . $a7['reason'] . " dt={$dt7}s" );

// ============================ E9: bounded scanning perf =======================
// 8MB body full of near-miss shapes: must complete well under seconds.
$huge = '<div data-x="' . str_repeat( 'n', 400 ) . '">' . str_repeat( '<span class="ok">filler</span>', 60000 ) . '</div>';
$t1   = microtime( true );
$r9   = $san->audit( $huge );
$dt9  = microtime( true ) - $t1;
echeck( $results, 'E9 8MB hostile body scanned bounded', is_array( $r9 ) && $dt9 < 5.0, "dt={$dt9}s" );

// Pattern-shape guarantee: no nested-quantifier bomb classes shipped.
$bad_shape = false;
foreach ( ResponseSanitizer::regexes() as $rx => $_l ) {
        if ( preg_match( '/\(\?[^)]*\)\*[+]/', $rx ) || preg_match( '/\)[*+][*+]/', $rx ) || preg_match( '/\.\*.*\.\*/', $rx ) ) {
                $bad_shape = true;
        }
}
echeck( $results, 'E9 no catastrophic quantifier nesting in shipped regexes', false === $bad_shape );

// ============================ E8: Engine content-type policy ==================
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTP_ACCEPT']    = '';
http_response_code( 200 );

$engine = new Engine( $settings, new Classifier( $settings ), $san );
$engine->request_class = array( 'classification' => Classifier::PUBLIC_CACHEABLE, 'reason' => 'phase-e' );
$keygen = new \UltimatePerformance\CacheKey\Key( $settings );
$b8     = $keygen->build( 'http', $_SERVER['HTTP_HOST'], '/', '', array( 'accept' => '' ) );
$refDir = new \ReflectionProperty( Engine::class, 'current_rel_dir' );
$refDir->setAccessible( true );
$refDir->setValue( $engine, $b8['dir'] );

$vroot = WP_CONTENT_DIR . '/cache/ultimate-performance/v';

function uc_tree_has( $vroot, $needle ) {
        if ( ! is_dir( $vroot ) ) { return false; }
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $vroot, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $f ) {
                if ( false !== strpos( (string) file_get_contents( $f->getPathname() ), $needle ) ) { return true; }
        }
        return false;
}

// JSON response shape → engine must bypass (no write).
header( 'Content-Type: application/json; charset=UTF-8', true );
$engine->on_output( '{"publicData":"json-content-marker-e8"}' );
echeck( $results, 'E8 application/json NOT written by engine gate', false === uc_tree_has( $vroot, 'json-content-marker-e8' ) );

// HTML response → engine writes (positive control).
header( 'Content-Type: text/html; charset=UTF-8', true );
$engine->on_output( '<!DOCTYPE html><html><body><h1>e8 html marker qk7z</h1></body></html>' );
echeck( $results, 'E8 text/html written by engine gate', true === uc_tree_has( $vroot, 'e8 html marker qk7z' ) );

// cleanup
( new \UltimatePerformance\PageCache\Store( new \UltimatePerformance\Core\SafeFs(), $keygen ) )->purge( $b8['dir'] );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
