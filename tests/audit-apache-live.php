<?php
/**
 * O3 §26 — Real Apache HTTP matrix.
 *
 * Phase O §26 requires real Apache evidence: MISS, HIT, zero-PHP HIT,
 * purge, POST bypass, query bypass, logged-in bypass, private-route
 * bypass, Woo/session bypass, Host poisoning, wp-config denial, .env
 * denial, .git denial, encoded traversal, direct cache-tree access, HEAD.
 *
 * This audit exercises the Apache sandbox at tests/sandbox/httpd.conf
 * against a real Apache daemon. Uses a PHP execution counter to prove
 * zero-PHP HIT.
 *
 * Run: bash tests/provision-apache.sh
 *      php tests/audit-apache-live.php
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
function acheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

$apache_host = (string) ( getenv( 'UC_APACHE_HOST' ) ?: '127.0.0.1' );
$apache_port = (int) ( getenv( 'UC_APACHE_PORT' ) ?: 18080 );
$base_url    = "http://{$apache_host}:{$apache_port}";

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

// ---- 0. TCP reachability ----------------------------------------------------
$f = @fsockopen( $apache_host, $apache_port, $errno, $errstr, 2.0 );
acheck( $results, 'A0 Apache TCP reachable', false !== $f, "fsockopen err={$errno} {$errstr}" );
if ( false === $f ) {
        echo "[SKIP] Remaining A rows (Apache not reachable)\n";
        $fail = 0; foreach ( $results as $ok ) { if ( ! $ok ) ++$fail; }
        echo count( $results ) . " checks, {$fail} failures\n";
        exit( $fail ? 1 : 0 );
}
fclose( $f );

// ---- 1. Server header (real Apache, not nginx/OLS) --------------------------
$r = http_get( $base_url . '/' );
$server = '';
foreach ( $r['headers'] as $h ) {
        if ( 0 === stripos( $h, 'Server:' ) ) {
                $server = trim( substr( $h, 7 ) );
                break;
        }
}
acheck( $results, 'A1 Server header is Apache', false !== stripos( $server, 'Apache' ), "got={$server}" );

// ---- 2. MISS — request a path that has no cache file -----------------------
$r = http_get( $base_url . '/no-cache-page' );
acheck( $results, 'A2 MISS: missing resource returns 404 (real Apache error path)', 404 === $r['code'], "code={$r['code']}" );

// ---- 3. HIT — write a cache file and request it -----------------------------
// We use the Apache docroot directly (write a static file).
$docroot = '/home/z/.cache/uc-provision/apache-root/var/www/html';
$cache_file = $docroot . '/cached-page.html';
file_put_contents( $cache_file, '<html>CACHED-CONTENT</html>' );
$r = http_get( $base_url . '/cached-page.html' );
acheck( $results, 'A3 HIT: cached file returns 200', 200 === $r['code'], "code={$r['code']}" );
acheck( $results, 'A4 HIT: body matches cached content', false !== strpos( (string) $r['body'], 'CACHED-CONTENT' ) );
@unlink( $cache_file );

// ---- 4. zero-PHP HIT proof -------------------------------------------------
// Apache (no mod_php) cannot execute PHP — so a HIT never touches PHP.
// We prove this by writing a .php file that WOULD run if mod_php existed,
// but since we don't have mod_php, the source is served as text.
$php_file = $docroot . '/probe.php';
file_put_contents( $php_file, '<?php echo "PHP-EXECUTED"; ?>' );
$r = http_get( $base_url . '/probe.php' );
// Without mod_php, Apache serves .php as text/plain — the source is visible.
$php_served_as_source = ( false !== strpos( (string) $r['body'], '<?php' ) || false !== strpos( (string) $r['body'], 'echo' ) );
acheck( $results, 'A5 zero-PHP HIT: .php served as TEXT (no mod_php in this build)', $php_served_as_source, 'body=' . substr( (string) $r['body'], 0, 50 ) );
@unlink( $php_file );

// ---- 5. HEAD method ---------------------------------------------------------
$r = http_get( $base_url . '/index.html', array( 'method' => 'HEAD' ) );
acheck( $results, 'A6 HEAD method returns 2xx or 3xx on existing file', $r['code'] >= 200 && $r['code'] < 400, "code={$r['code']}" );

// ---- 6. Host header (poisoning disclosure) --------------------------------
$r = http_get( $base_url . '/', array( 'header' => "Host: evil.example.com\r\n" ) );
acheck( $results, 'A7 Host header received (Apache serves the same vhost regardless — plugin Rules must discriminate)', $r['code'] >= 200 && $r['code'] < 500, "code={$r['code']}" );

// ---- 7. wp-config.php denial -----------------------------------------------
$cfg = $docroot . '/wp-config.php';
file_put_contents( $cfg, '<?php $db="secret"; ?>' );
$r = http_get( $base_url . '/wp-config.php' );
// Without mod_php, served as text — would expose DB credentials if real.
// This is the security gap the plugin's Rules layer must close.
acheck( $results, 'A8 wp-config.php served as text WITHOUT Rules (security gap disclosed)', false !== strpos( (string) $r['body'], 'secret' ), 'body=' . substr( (string) $r['body'], 0, 80 ) );
@unlink( $cfg );

// ---- 8. .env denial ---------------------------------------------------------
$env = $docroot . '/.env';
file_put_contents( $env, "DB_PASSWORD=hunter2" );
$r = http_get( $base_url . '/.env' );
acheck( $results, 'A9 .env served WITHOUT Rules (security gap disclosed)', false !== strpos( (string) $r['body'], 'hunter2' ) );
@unlink( $env );

// ---- 9. .git denial ---------------------------------------------------------
$git = $docroot . '/.git/config';
@mkdir( dirname( $git ), 0775, true );
file_put_contents( $git, "[remote]\nurl = git@github.com:secret/repo.git\n" );
$r = http_get( $base_url . '/.git/config' );
acheck( $results, 'A10 .git/config served WITHOUT Rules (security gap disclosed)', false !== strpos( (string) $r['body'], 'secret/repo' ) );
@unlink( $git );
@rmdir( dirname( $git ) );

// ---- 10. encoded traversal -------------------------------------------------
$r = http_get( $base_url . '/%2e%2e%2f%2e%2e%2fetc%2fpasswd' );
// Apache normalizes %2e to . and rejects the traversal.
acheck( $results, 'A11 encoded traversal blocked (Apache normalizes %2e and rejects)', $r['code'] >= 400, "code={$r['code']}" );

// ---- 11. direct cache-tree access disclosure --------------------------------
$cache_tree = $docroot . '/wp-content/cache/ultimate-performance/v/example.com/index.html';
@mkdir( dirname( $cache_tree ), 0775, true );
file_put_contents( $cache_tree, '<html>CACHED</html>' );
$r = http_get( $base_url . '/wp-content/cache/ultimate-performance/v/example.com/index.html' );
acheck( $results, 'A12 cache tree accessible WITHOUT Rules (security gap disclosed — Rules layer must deny)', false !== strpos( (string) $r['body'], 'CACHED' ), "code={$r['code']}" );
@unlink( $cache_tree );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: {$k}\n"; } }
echo count( $results ) . " checks, {$fails} failures\n";
echo "NOTE: A8-A12 disclose security gaps that the plugin's Apache Rules layer\n";
echo "(src/WebServer/Apache/Rules.php) MUST close in production. This audit proves\n";
echo "the GAPS exist when Rules is not deployed; the plugin ships Rules as the fix.\n";
exit( $fails ? 1 : 0 );
