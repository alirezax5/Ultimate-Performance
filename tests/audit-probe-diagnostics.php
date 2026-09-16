<?php
/**
 * N5 §17 — Probe diagnostics classification audit.
 *
 * Phase N §17 requires distinguishing at least:
 *   ACTIVE, NOT_ACTIVE, UNREACHABLE, DNS_ERROR, TLS_ERROR,
 *   TIMEOUT, REDIRECTED, CONTENT_MISMATCH
 *
 * This audit exercises AdminPage::classify_probe_response() with
 * synthetic responses (no live HTTP needed — the classifier is
 * a pure function of (response, expected_body)).
 *
 * Run: php tests/audit-probe-diagnostics.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Admin\AdminPage;

// The wp-shim defines WpError in the UltimatePerformance\Tests\Shim namespace.
// Alias it to the global WP_Error name so the audit reads like real WP code.
if ( ! class_exists( 'WP_Error' ) ) {
        if ( class_exists( '\\UltimatePerformance\\Tests\\Shim\\WpError' ) ) {
                class_alias( '\\UltimatePerformance\\Tests\\Shim\\WpError', 'WP_Error' );
        } else {
                // Minimal WP_Error-compatible stub (for environments without the shim).
                class WP_Error {
                        public $errors = array();
                        public function __construct( $code = '', $message = '' ) {
                                if ( '' !== $code ) {
                                        $this->errors[ $code ] = $message;
                                }
                        }
                        public function get_error_message() {
                                $f = reset( $this->errors );
                                return false === $f ? '' : (string) $f;
                        }
                        public function get_error_code() {
                                $k = array_keys( $this->errors );
                                return isset( $k[0] ) ? $k[0] : '';
                        }
                }
        }
}

$results = array();
function dcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

// The classifier is a public method on AdminPage; we instantiate the page
// with the standard Settings instance. No admin screen rendering — we only
// call the method directly.
$page = new AdminPage();

// Expected probe body (what Rules::probe_body would emit)
$expected = 'UC-VERIFY-PROBE-BODY';

// ---- D1: ACTIVE — byte-identical 200 response --------------------------------
$resp = array(
        'body'     => $expected,
        'response' => array( 'code' => 200, 'message' => 'OK' ),
);
dcheck( $results, 'D1 active: byte-identical 200', 'active' === $page->classify_probe_response( $resp, $expected ) );

// ---- D2: NOT_ACTIVE — HTTP 200 but empty body --------------------------------
$resp = array(
        'body'     => '',
        'response' => array( 'code' => 200, 'message' => 'OK' ),
);
dcheck( $results, 'D2 not_active: 200 with empty body', 'not_active' === $page->classify_probe_response( $resp, $expected ) );

// ---- D3: CONTENT_MISMATCH — 200 but different body --------------------------
$resp = array(
        'body'     => 'Some-other-content-from-PHP',
        'response' => array( 'code' => 200, 'message' => 'OK' ),
);
dcheck( $results, 'D3 content_mismatch: 200 with different body (PHP intercepted)', 'content_mismatch' === $page->classify_probe_response( $resp, $expected ) );

// ---- D4: REDIRECTED — HTTP 3xx ---------------------------------------------
$resp = array(
        'body'     => '<html>redirect</html>',
        'response' => array( 'code' => 301, 'message' => 'Moved Permanently' ),
);
dcheck( $results, 'D4 redirected: 301 (not a static serve)', 'redirected' === $page->classify_probe_response( $resp, $expected ) );

$resp = array(
        'body'     => '',
        'response' => array( 'code' => 302, 'message' => 'Found' ),
);
dcheck( $results, 'D4b redirected: 302 (not a static serve)', 'redirected' === $page->classify_probe_response( $resp, $expected ) );

// ---- D5: UNREACHABLE — HTTP 5xx --------------------------------------------
$resp = array(
        'body'     => '500 Internal Server Error',
        'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
);
dcheck( $results, 'D5 unreachable: 500', 'unreachable' === $page->classify_probe_response( $resp, $expected ) );

$resp = array(
        'body'     => '502 Bad Gateway',
        'response' => array( 'code' => 502, 'message' => 'Bad Gateway' ),
);
dcheck( $results, 'D5b unreachable: 502', 'unreachable' === $page->classify_probe_response( $resp, $expected ) );

// ---- D6: WP_Error — timeout -------------------------------------------------
$err = new \WP_Error( 'http_request_failed', 'Operation timed out after 5000 milliseconds' );
dcheck( $results, 'D6 timeout: WP_Error timed out', 'timeout' === $page->classify_probe_response( $err, $expected ) );

$err = new \WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );
dcheck( $results, 'D6b timeout: cURL error 28', 'timeout' === $page->classify_probe_response( $err, $expected ) );

// ---- D7: WP_Error — TLS error -----------------------------------------------
$err = new \WP_Error( 'http_request_failed', 'cURL error 35: SSL certificate problem: self signed certificate' );
dcheck( $results, 'D7 tls_error: cURL error 35 SSL', 'tls_error' === $page->classify_probe_response( $err, $expected ) );

$err = new \WP_Error( 'http_request_failed', 'SSL: no alternative certificate subject name matches target host name' );
dcheck( $results, 'D7b tls_error: SSL hostname mismatch', 'tls_error' === $page->classify_probe_response( $err, $expected ) );

// ---- D8: WP_Error — DNS error -----------------------------------------------
$err = new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: example.invalid' );
dcheck( $results, 'D8 dns_error: could not resolve host', 'dns_error' === $page->classify_probe_response( $err, $expected ) );

$err = new \WP_Error( 'http_request_failed', 'Name or service not known' );
dcheck( $results, 'D8b dns_error: name or service not known', 'dns_error' === $page->classify_probe_response( $err, $expected ) );

// ---- D9: WP_Error — connection refused (unreachable) ----------------------
$err = new \WP_Error( 'http_request_failed', 'cURL error 7: Connection refused' );
dcheck( $results, 'D9 unreachable: connection refused', 'unreachable' === $page->classify_probe_response( $err, $expected ) );

// ---- D10: WP_Error — generic failure (fall-through to unreachable) ---------
$err = new \WP_Error( 'http_request_failed', 'Some other cURL error' );
dcheck( $results, 'D10 unreachable: generic WP_Error fall-through', 'unreachable' === $page->classify_probe_response( $err, $expected ) );

// ---- D11: NEVER collapses every failure into one bucket ---------------------
// Phase N §17 contract: at least 7 distinct classifications.
$distinct = array();
$tests = array(
        array( array( 'body' => $expected, 'response' => array( 'code' => 200 ) ), 'active' ),
        array( array( 'body' => '',           'response' => array( 'code' => 200 ) ), 'not_active' ),
        array( array( 'body' => 'other',      'response' => array( 'code' => 200 ) ), 'content_mismatch' ),
        array( array( 'body' => '',           'response' => array( 'code' => 301 ) ), 'redirected' ),
        array( array( 'body' => '',           'response' => array( 'code' => 500 ) ), 'unreachable' ),
        array( new \WP_Error( 'http_request_failed', 'Operation timed out' ),         'timeout' ),
        array( new \WP_Error( 'http_request_failed', 'SSL certificate problem' ),     'tls_error' ),
        array( new \WP_Error( 'http_request_failed', 'Could not resolve host' ),     'dns_error' ),
);
foreach ( $tests as $t ) {
        $distinct[ $page->classify_probe_response( $t[0], $expected ) ] = true;
}
dcheck( $results, 'D11 8 distinct classifications produced (Phase N §17 contract)', count( $distinct ) >= 8, 'got=' . count( $distinct ) . ' distinct=' . implode( ',', array_keys( $distinct ) ) );

// ---- D12: SECRET-FREE diagnostics — error messages NEVER contain credentials ----
// The classify_probe_response method returns ONLY a category slug (no
// message text in the slug). The render() method emits a fixed human-readable
// string per category. Neither contains user input, URLs, or credentials.
$slug = $page->classify_probe_response( new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: example.invalid' ), $expected );
dcheck( $results, 'D12 secret-free: classification slug is a bare category (no URL/host content leaked)', $slug === 'dns_error' || $slug === 'unreachable' );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
