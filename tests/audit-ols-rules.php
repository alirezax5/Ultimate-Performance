<?php
/**
 * O5 §36-37 — OpenLiteSpeed Rules writer audit.
 *
 * Phase O §36-37 require an OLS Rules writer + security rules +
 * config fuzzing (Phase O §50).
 *
 * This audit exercises the Rules::generate() method with:
 *   - valid hosts (sanity)
 *   - invalid hosts (fail-closed to empty string)
 *   - fuzz inputs (newline/quotes/semicolon/../%2e/control chars)
 *   - security rule presence (wp-config.php / .env / .git / cache-tree / traversal)
 *   - cookie bypass regex
 *   - query string bypass
 *   - POST bypass
 *
 * Run: php tests/audit-ols-rules.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\WebServer\OpenLiteSpeed\Rules;

$results = array();
function okcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

// ---- 1. Valid host generates non-empty snippet -----------------------------
$rules = Rules::generate( 'example.com' );
okcheck( $results, 'O1 valid host generates non-empty rules', '' !== $rules );
okcheck( $results, 'O2 rules contain RewriteEngine On', false !== strpos( $rules, 'RewriteEngine On' ) );
okcheck( $results, 'O3 rules contain the host literal (escaped)', false !== strpos( $rules, 'example\.com' ) );

// ---- 2. Invalid hosts fail-closed -----------------------------------------
$invalid_hosts = array(
        '',                                     // empty
        'host with spaces',                     // spaces
        'host/with/path',                       // path
        'host..double-dot',                     // double dot
        'host:8080',                            // port
        str_repeat( 'a', 254 ) . '.com',        // too long
        'host\x00null',                        // null byte
        'host%2e%2eevil',                       // encoded traversal in host
        'host\r\nEvil: header',                // CRLF injection
        'host;semicolon',                       // semicolon
);
foreach ( $invalid_hosts as $i => $h ) {
        $r = Rules::generate( $h );
        okcheck( $results, 'O4_' . $i . ' invalid host ' . substr( (string) $h, 0, 30 ) . ' fails closed (empty)', '' === $r, 'got=' . substr( $r, 0, 80 ) );
}

// ---- 3. Security rules present ---------------------------------------------
$rules = Rules::generate( 'example.com' );
okcheck( $results, 'O5 wp-config.php deny rule', false !== strpos( $rules, 'wp-config\.php$' ) && false !== strpos( $rules, '[F,L]' ) );
okcheck( $results, 'O6 .env deny rule', false !== strpos( $rules, '\.env$' ) );
okcheck( $results, 'O7 .git deny rule', false !== strpos( $rules, '\.git/' ) );
okcheck( $results, 'O8 cache-tree direct access deny', false !== strpos( $rules, 'wp-content/cache/ultimate-performance/v/' ) );
okcheck( $results, 'O9 encoded traversal deny (%2e)', false !== strpos( $rules, '%2e%2e' ) );

// ---- 4. Cookie bypass -------------------------------------------------------
okcheck( $results, 'O10 cookie bypass regex present (wordpress_logged_in)', false !== strpos( $rules, 'wordpress_logged_in' ) );
okcheck( $results, 'O11 woo cart cookie bypass present', false !== strpos( $rules, 'woocommerce_cart_hash' ) );
okcheck( $results, 'O12 woo session cookie bypass present', false !== strpos( $rules, 'wp_woocommerce_session_' ) );
okcheck( $results, 'O13 comment_author cookie bypass present', false !== strpos( $rules, 'comment_author_' ) );

// ---- 5. Query string bypass -------------------------------------------------
okcheck( $results, 'O14 query string bypass present (RewriteCond QUERY_STRING)', false !== strpos( $rules, '%{QUERY_STRING}' ) );

// ---- 6. POST bypass ---------------------------------------------------------
okcheck( $results, 'O15 POST bypass present (REQUEST_METHOD !^(GET|HEAD))', false !== strpos( $rules, '!^(GET|HEAD)$' ) );

// ---- 7. Config fuzzing (Phase O §50) ----------------------------------------
$fuzz_inputs = array(
        "host\nEvil-Injection",                 // newline
        "host'; DROP TABLE wp_users; --",       // SQL injection
        'host"quotes"',                         // double quotes
        'host\\backslash',                       // backslash
        "host\x01control",                       // control char
        'host' . str_repeat( 'a', 1000 ),        // very long
        '127.0.0.1',                             // IPv4 (not a domain — should fail)
        '::1',                                   // IPv6
        'xn--e1afmkfd.example',                  // punycode IDN
        '../etc/passwd',                         // path traversal
        '%2e%2e%2f',                             // encoded traversal
);
foreach ( $fuzz_inputs as $i => $h ) {
        $r = Rules::generate( $h );
        // Either empty (fail-closed) OR contains no injection (newline/CRLF/semicolon in the output)
        $is_safe = ( '' === $r ) || (
                false === strpos( $r, "Evil-Injection" ) &&
                false === strpos( $r, "DROP TABLE" ) &&
                false === strpos( $r, "../etc/passwd" )
        );
        okcheck( $results, 'O16_' . $i . ' fuzz input ' . substr( (string) $h, 0, 30 ) . ' is safe (empty or sanitized)', $is_safe, 'output=' . substr( $r, 0, 80 ) );
}

// ---- 8. Probe URI + body ---------------------------------------------------
$token = '0123456789abcdef';
$uri = Rules::probe_uri( $token );
$body = Rules::probe_body( $token );
okcheck( $results, 'O17 probe_uri returns /uc-verify-<token>', '/uc-verify-0123456789abcdef' === $uri, "got={$uri}" );
okcheck( $results, 'O18 probe_body contains token', 'UC-VERIFY-PROBE-BODY-0123456789abcdef' === $body, "got={$body}" );
okcheck( $results, 'O19 probe_uri rejects non-hex token', '' === Rules::probe_uri( 'evil!@#' ) );

// ---- 9. Output is bounded (no extremely long output) -----------------------
$long_rules = Rules::generate( str_repeat( 'a', 60 ) . '.example.com' );
okcheck( $results, 'O20 output is bounded (< 5KB even with long host)', strlen( $long_rules ) < 5000, 'len=' . strlen( $long_rules ) );

// ---- 10. Host header is NEVER an authorization input -----------------------
// The rules map %{HTTP_HOST} to the canonical host — a poisoned Host
// header cannot select another site's cache tree.
okcheck( $results, 'O21 host header check is in RewriteCond (canonical host gate)', false !== strpos( $rules, '%{HTTP_HOST}' ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: {$k}\n"; } }
echo count( $results ) . " checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
