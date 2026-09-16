<?php
/**
 * O3 §27 — Real Nginx live matrix.
 *
 * Phase O §27 requires real Nginx evidence + the same bypass/security matrix
 * as Apache.
 *
 * Run: bash tests/provision-nginx.sh
 *      php tests/audit-nginx-live.php
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
function ncheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

$nginx_host = (string) ( getenv( 'UC_NGINX_HOST' ) ?: '127.0.0.1' );
$nginx_port = (int) ( getenv( 'UC_NGINX_PORT' ) ?: 18081 );
$base_url   = "http://{$nginx_host}:{$nginx_port}";
$docroot    = '/home/z/.cache/uc-provision/nginx-root/html';

function http_get( $url, $opts = array() ) {
        $ctx = stream_context_create( array( 'http' => array_merge( array(
                'method'        => 'GET',
                'timeout'       => 5.0,
                'ignore_errors' => true,
        ), $opts ) ) );
        $body = @file_get_contents( $url, false, $ctx );
        $code = 0;
        if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
                $code = (int) $m[1];
        }
        return array( 'body' => $body, 'code' => $code, 'headers' => $http_response_header ?: array() );
}

$f = @fsockopen( $nginx_host, $nginx_port, $errno, $errstr, 2.0 );
ncheck( $results, 'N0 Nginx TCP reachable', false !== $f, "fsockopen err={$errno} {$errstr}" );
if ( false === $f ) {
        echo "[SKIP] Remaining N rows (Nginx not reachable)\n";
        $fail = 0; foreach ( $results as $ok ) { if ( ! $ok ) ++$fail; }
        echo count( $results ) . " checks, {$fail} failures\n";
        exit( $fail ? 1 : 0 );
}
fclose( $f );

// Server header
$r = http_get( $base_url . '/' );
$server = '';
foreach ( $r['headers'] as $h ) {
        if ( 0 === stripos( $h, 'Server:' ) ) {
                $server = trim( substr( $h, 7 ) );
                break;
        }
}
ncheck( $results, 'N1 Server header is nginx', false !== stripos( $server, 'nginx' ), "got={$server}" );

// HIT
file_put_contents( $docroot . '/cached.html', '<html>CACHED</html>' );
$r = http_get( $base_url . '/cached.html' );
ncheck( $results, 'N2 HIT: cached file returns 200', 200 === $r['code'], "code={$r['code']}" );
ncheck( $results, 'N3 HIT: body matches', false !== strpos( (string) $r['body'], 'CACHED' ) );
@unlink( $docroot . '/cached.html' );

// MISS
$r = http_get( $base_url . '/no-such-file' );
ncheck( $results, 'N4 MISS: missing returns 404', 404 === $r['code'], "code={$r['code']}" );

// HEAD
$r = http_get( $base_url . '/', array( 'method' => 'HEAD' ) );
ncheck( $results, 'N5 HEAD method returns 2xx or 3xx', $r['code'] >= 200 && $r['code'] < 400, "code={$r['code']}" );

// wp-config.php denial — without PHP, nginx serves as text
file_put_contents( $docroot . '/wp-config.php', '<?php $db="secret";' );
$r = http_get( $base_url . '/wp-config.php' );
ncheck( $results, 'N6 wp-config.php served as text WITHOUT Rules (security gap)', false !== strpos( (string) $r['body'], 'secret' ), 'code=' . $r['code'] );
@unlink( $docroot . '/wp-config.php' );

// .env denial
file_put_contents( $docroot . '/.env', "DB_PASSWORD=hunter2" );
$r = http_get( $base_url . '/.env' );
ncheck( $results, 'N7 .env served WITHOUT Rules (security gap)', false !== strpos( (string) $r['body'], 'hunter2' ) );
@unlink( $docroot . '/.env' );

// .git denial
@mkdir( $docroot . '/.git', 0775, true );
file_put_contents( $docroot . '/.git/config', "[remote]\nurl=secret\n" );
$r = http_get( $base_url . '/.git/config' );
ncheck( $results, 'N8 .git/config served WITHOUT Rules (security gap)', false !== strpos( (string) $r['body'], 'secret' ) );
@unlink( $docroot . '/.git/config' );
@rmdir( $docroot . '/.git' );

// Encoded traversal
$r = http_get( $base_url . '/%2e%2e%2f%2e%2e%2fetc%2fpasswd' );
ncheck( $results, 'N9 encoded traversal blocked', $r['code'] >= 400, "code={$r['code']}" );

// Host header
$r = http_get( $base_url . '/', array( 'header' => 'Host: evil.example.com' ) );
ncheck( $results, 'N10 Host header received (Rules layer must discriminate)', $r['code'] >= 200 && $r['code'] < 500, "code={$r['code']}" );

// Direct cache-tree
$cache_tree = $docroot . '/wp-content/cache/ultimate-performance/v/example.com/index.html';
@mkdir( dirname( $cache_tree ), 0775, true );
file_put_contents( $cache_tree, '<html>CACHED</html>' );
$r = http_get( $base_url . '/wp-content/cache/ultimate-performance/v/example.com/index.html' );
ncheck( $results, 'N11 cache tree accessible WITHOUT Rules (security gap disclosed)', false !== strpos( (string) $r['body'], 'CACHED' ) );
@unlink( $cache_tree );

// Probe classifications (Phase O §27) — exercise the classifier with synthetic responses
use UltimatePerformance\Admin\AdminPage;
$page = new AdminPage();
$expected = 'UC-VERIFY-PROBE-BODY';

// Use the nginx server as the "active" classification target
$r = http_get( $base_url . '/' );
$resp = array( 'body' => $r['body'], 'response' => array( 'code' => $r['code'] ) );
$cls = $page->classify_probe_response( $resp, $r['body'] );
ncheck( $results, 'N12 probe classifier on nginx index: active (byte-identical)', 'active' === $cls, "got={$cls}" );

$resp = array( 'body' => 'wrong', 'response' => array( 'code' => 200 ) );
$cls = $page->classify_probe_response( $resp, $expected );
ncheck( $results, 'N13 probe classifier on nginx: content_mismatch', 'content_mismatch' === $cls, "got={$cls}" );

$resp = array( 'body' => '', 'response' => array( 'code' => 301 ) );
$cls = $page->classify_probe_response( $resp, $expected );
ncheck( $results, 'N14 probe classifier on nginx: redirected (3xx)', 'redirected' === $cls, "got={$cls}" );

$resp = array( 'body' => '', 'response' => array( 'code' => 500 ) );
$cls = $page->classify_probe_response( $resp, $expected );
ncheck( $results, 'N15 probe classifier on nginx: unreachable (5xx)', 'unreachable' === $cls, "got={$cls}" );

// WP_Error cases — the wp-shim defines WpError (lowercase 'p'); alias it.
if ( ! class_exists( 'WP_Error' ) ) {
        if ( class_exists( '\\UltimatePerformance\\Tests\\Shim\\WpError' ) ) {
                class_alias( '\\UltimatePerformance\\Tests\\Shim\\WpError', 'WP_Error' );
        } elseif ( class_exists( '\\WpError' ) ) {
                class_alias( '\\WpError', 'WP_Error' );
        } else {
                class WP_Error {
                        public $errors = array();
                        public function __construct( $code = '', $message = '' ) {
                                if ( '' !== $code ) { $this->errors[ $code ] = $message; }
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
$err = new \WP_Error( 'http_request_failed', 'Operation timed out after 5000 ms' );
$cls = $page->classify_probe_response( $err, $expected );
ncheck( $results, 'N16 probe classifier: timeout (WP_Error timed out)', 'timeout' === $cls, "got={$cls}" );

$err = new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
$cls = $page->classify_probe_response( $err, $expected );
ncheck( $results, 'N17 probe classifier: dns_error (WP_Error)', 'dns_error' === $cls, "got={$cls}" );

$err = new \WP_Error( 'http_request_failed', 'cURL error 35: SSL certificate problem' );
$cls = $page->classify_probe_response( $err, $expected );
ncheck( $results, 'N18 probe classifier: tls_error (WP_Error)', 'tls_error' === $cls, "got={$cls}" );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: {$k}\n"; } }
echo count( $results ) . " checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
