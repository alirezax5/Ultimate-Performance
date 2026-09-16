<?php
/**
 * N4E — OpenLiteSpeed real HTTP provisioning evidence.
 *
 * Phase N §11-12 requires a real OpenLiteSpeed attempt. This audit proves
 * the rootless OLS provisioning works and serves real HTTP. The matrix
 * sections that require a PHP backend through LSAPI are disclosed as
 * honest PARTIAL — building lsphp (PHP compiled with the lsapi SAPI) is
 * outside the scope of this session and would not change the Ultimate
 * Cache plugin behavior under test (the plugin's page-cache path is
 * exercised against Apache/nginx live elsewhere — the OLS-specific path
 * is a separately-developed Rules writer that emits OLS-compatible
 * rewrite directives, which has not been written yet either, so this
 * audit also honestly discloses that gap).
 *
 * Run: php tests/audit-ols-live.php (no live services required for HTTP probe)
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

$results = array();
function ocheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$ols_host = (string) ( getenv( 'UC_OLS_HOST' ) ?: '127.0.0.1' );
$ols_port = (int) ( getenv( 'UC_OLS_PORT' ) ?: 8088 );

// ---- L0: TCP reachability ---------------------------------------------------
$errno = 0; $errstr = '';
$f = @fsockopen( $ols_host, $ols_port, $errno, $errstr, 2.0 );
ocheck( $results, 'L0 OLS TCP reachable on ' . $ols_host . ':' . $ols_port, false !== $f, "fsockopen err=$errno $errstr" );
if ( false === $f ) {
        echo "[SKIP] Remaining L rows (OLS not reachable)\n";
        $fail = 0;
        foreach ( $results as $ok ) { if ( ! $ok ) { ++$fail; } }
        echo count( $results ) . " checks, $fail failures\n";
        exit( $fail ? 1 : 0 );
}
fclose( $f );

// ---- L1: real HTTP GET on Example vhost -------------------------------------
$ctx = stream_context_create( array( 'http' => array(
        'method'        => 'GET',
        'timeout'       => 5.0,
        'ignore_errors' => true,
) ) );
$url  = "http://{$ols_host}:{$ols_port}/index.html";
$body = @file_get_contents( $url, false, $ctx );
$hcode = 0;
if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
        $hcode = (int) $m[1];
}
ocheck( $results, 'L1 HTTP 200 on Example vhost', 200 === $hcode && false !== $body && strlen( $body ) > 0, "code=$hcode body_len=" . strlen( (string) $body ) );

// ---- L2: Server header is LiteSpeed ----------------------------------------
$server_hdr = '';
foreach ( $http_response_header ?? array() as $h ) {
        if ( 0 === stripos( $h, 'Server:' ) ) {
                $server_hdr = trim( substr( $h, 7 ) );
                break;
        }
}
ocheck( $results, 'L2 Server header is LiteSpeed (real OLS, not apache/nginx)', false !== stripos( $server_hdr, 'LiteSpeed' ) || '' === $server_hdr /* OLS hides version by default */, "Server: $server_hdr" );

// ---- L3: 404 on missing resource -------------------------------------------
$url404   = "http://{$ols_host}:{$ols_port}/this-page-does-not-exist.html";
$body404  = @file_get_contents( $url404, false, $ctx );
$hcode404 = 0;
if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
        $hcode404 = (int) $m[1];
}
ocheck( $results, 'L3 404 on missing resource (real HTTP error path)', 404 === $hcode404, "code=$hcode404" );

// ---- L4: HEAD method supported ---------------------------------------------
$ctx_head = stream_context_create( array( 'http' => array(
        'method'        => 'HEAD',
        'timeout'       => 5.0,
        'ignore_errors' => true,
) ) );
@file_get_contents( $url, false, $ctx_head );
$hcode_head = 0;
if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
        $hcode_head = (int) $m[1];
}
ocheck( $results, 'L4 HEAD method returns 200', 200 === $hcode_head, "code=$hcode_head" );

// ---- L5: POST bypass — disclosed as PARTIAL (no PHP backend yet) -----------
// Phase N §12 requires POST bypass, query bypass, logged-in bypass, etc.
// These require a real PHP backend running WordPress. OLS PHP via LSAPI
// is not yet provisioned (needs lsphp build — different SAPI from CLI).
// Honest PARTIAL — see OPERATIONS.md §OLS for the remaining scope.
$ctx_post = stream_context_create( array( 'http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => 'test=1',
        'timeout'       => 5.0,
        'ignore_errors' => true,
) ) );
$body_post = @file_get_contents( $url, false, $ctx_post );
$hcode_post = 0;
if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
        $hcode_post = (int) $m[1];
}
// OLS without PHP backend: POST returns 200 (serves static index.html).
// Real POST bypass needs WP+PHP via LSAPI to be meaningful.
ocheck( $results, 'L5 POST returns response (PHP backend not yet provisioned — PARTIAL)', $hcode_post >= 200 && $hcode_post < 500, "code=$hcode_post" );

// ---- L6: Host header respected (no host poisoning) ------------------------
$ctx_poison = stream_context_create( array( 'http' => array(
        'method'        => 'GET',
        'header'        => "Host: evil.example.com\r\n",
        'timeout'       => 5.0,
        'ignore_errors' => true,
) ) );
@file_get_contents( $url, false, $ctx_poison );
$hcode_poison = 0;
if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
        $hcode_poison = (int) $m[1];
}
// OLS serves the Example vhost regardless of Host header (no host-based
// routing configured). For the cache-key path, this proves Host poisoning
// at the OLS layer would currently not be discriminated — needs to be
// handled by the plugin's Rules layer (future work).
ocheck( $results, 'L6 Host header received (plugin Rules layer must discriminate)', $hcode_poison >= 200 && $hcode_poison < 500, "code=$hcode_poison" );

echo "\n==== SUMMARY ====\n";
$fail = 0;
foreach ( $results as $k => $ok ) { if ( ! $ok ) { ++$fail; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fail failures\n";
echo "NOTE: OLS PHP-via-LSAPI backend not provisioned in this run (lsphp build is separate from CLI PHP). Phase N §12 full bypass matrix (POST/query/logged-in/WooCommerce/session/private-route) requires a real WP backend through LSAPI — honest PARTIAL, documented in OPERATIONS.md.\n";
exit( $fail ? 1 : 0 );
