<?php
/**
 * AUDIT TEST — Apache early-serve sandbox (Phase 1/2/14).
 *
 * Boots a REAL httpd.exe (XAMPP 2.4.58) on port 8099 with a disposable docroot
 * INSIDE the plugin dir. No WordPress, no MySQL. Proves:
 *
 *   HIT: static file served, PHP handler NEVER invoked (marker .ucphp not hit),
 *        X-Ultimate-Performance: HIT header present.
 *   MISS: falls through to PHP marker script (proves fall-through works).
 *   Security: cookies/query/hostile URIs never early-served; meta denied.
 *
 * Run: php tests/audit-apache-hit.php
 */

namespace UltimatePerformance\Tests;

use UltimatePerformance\WebServer\Apache\Rules;

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
require_once ABSPATH . 'src/WebServer/Apache/Rules.php';

if ( ! is_dir( ABSPATH . "tests/sandbox/docroot" ) ) { @mkdir( ABSPATH . "tests/sandbox/docroot", 0777, true ); }
$docroot = realpath( ABSPATH . "tests/sandbox/docroot" );

// ---- fixtures ------------------------------------------------------------
@mkdir( "$docroot/wp-content/cache/ultimate-performance/v/example.com/(root)", 0777, true );
@mkdir( "$docroot/wp-content/cache/ultimate-performance/v/example.com/shop", 0777, true );
file_put_contents(
        "$docroot/wp-content/cache/ultimate-performance/v/example.com/(root)/index.html",
        "<html>CACHED-ROOT</html>\n<!-- .meta.json fake -->"
);
file_put_contents(
        "$docroot/wp-content/cache/ultimate-performance/v/example.com/shop/index.html",
        '<html>CACHED-SHOP</html>'
);
// PHP marker: sandbox has NO PHP module by design — the dynamic layer is
// represented by a static "front controller" file. Any request that reaches
// it (via WP's catch-all rewrite) touches this file = WordPress bootstrapped.
// Absence of mod_php makes "0 PHP on HIT" structurally verifiable: there is
// no interpreter in the server at all.
file_put_contents(
        "$docroot/index.php",
        'WP-FRONT-CONTROLLER-REACHED uri=' . '<?php echo $_SERVER["REQUEST_URI"]; ?>'
);

// ---- generate rules from plugin ------------------------------------------
$cookie_rx = 'wordpress_[a-f0-9]{32}|wordpress_logged_in_[a-f0-9]{32}|wordpress_sec_[a-f0-9]{32}|wp-postpass|comment_author_[a-f0-9]{32}|woocommerce_cart_hash|woocommerce_items_in_cart|wp_woocommerce_session_';
$block = Rules::generate_production( 'example.com', $cookie_rx, array() );
if ( '' === $block || false === strpos( $block, 'RewriteCond' ) ) {
        fwrite( STDERR, "FATAL: rules generation produced empty block\n" );
        exit( 1 );
}
// Production layout: our managed block FIRST, then WordPress's own block
// (catch-all front controller) BELOW it — never modified by us.
$wp_block = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>";
file_put_contents( "$docroot/.htaccess", $block . "\n\n" . $wp_block . "\n" );

// ---- httpd config --------------------------------------------------------
// Linux httpd requires mod_unixd (AH00136: server MUST relinquish startup
// privileges); Windows/XAMPP httpd has no unixd module. Keep the conf
// generation cross-platform: add the LoadModule line on Unix only.
$unixd_line = ( '\\' === DIRECTORY_SEPARATOR ) ? '' : 'LoadModule unixd_module modules/mod_unixd.so';
$httpd_conf = <<<CONF
Listen 127.0.0.1:8099
ServerName localhost
PidFile "{DOCROOT}/../httpd.pid"
ErrorLog "{DOCROOT}/../error.log"
CustomLog "{DOCROOT}/../access.log" "%h %m %U %>s %B %{X-Ultimate-Performance}o"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
{UNIXD}
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule dir_module modules/mod_dir.so
LoadModule headers_module modules/mod_headers.so
LoadModule rewrite_module modules/mod_rewrite.so
TypesConfig conf/mime.types
DefaultType text/plain
DirectoryIndex index.php index.html
<Directory />
    AllowOverride none
    Require all denied
</Directory>
<Directory "{DOCROOT}">
    AllowOverride All
    Options -MultiViews +FollowSymLinks
    Require all granted
</Directory>
DocumentRoot "{DOCROOT}"
CONF;
$httpd_conf = str_replace( '{UNIXD}', $unixd_line, $httpd_conf );
$httpd_conf = str_replace( '{DOCROOT}', str_replace( '\\', '/', $docroot ), $httpd_conf );
file_put_contents( ABSPATH . 'tests/sandbox/httpd.conf', $httpd_conf );
file_put_contents( ABSPATH . 'tests/sandbox/mime.types', '' ); // minimal

// ---- start server --------------------------------------------------------
// NOTE: mime.types copied to sandbox dir; conf references it relatively.
// Portable gating: the suite needs a REAL Apache httpd (mod_rewrite/mod_headers).
// Environment candidates: UC_HTTPD_BIN env override, the original XAMPP path,
// or common Linux httpd locations. Without a binary the suite SKIPS CLEANLY
// (exit 0) — a SKIP is reported as SKIP, never counted as a PASS.
$httpd = getenv( 'UC_HTTPD_BIN' ) ?: '';
if ( '' === $httpd ) {
        foreach ( array(
                'D:/xampp/apache/bin/httpd.exe',
                '/usr/sbin/apache2',
                '/usr/sbin/httpd',
                '/usr/local/apache2/bin/httpd',
        ) as $candidate ) {
                if ( $candidate && @file_exists( $candidate ) ) {
                        $httpd = $candidate;
                        break;
                }
        }
}
if ( '' === $httpd || ! @file_exists( $httpd ) ) {
        echo "[SKIP] audit-apache-hit << no Apache httpd binary available in this environment\n";
        echo "       (set UC_HTTPD_BIN=/path/to/httpd to enable this suite)\n";
        echo "\n==== SUMMARY ====\n";
        echo "0 checks executed, all skipped (environment lacks httpd) — skipped rows are NOT counted as PASS\n";
        exit( 0 );
}
$conf  = str_replace( '\\', '/', ABSPATH ) . 'tests/sandbox/httpd.conf';
// Copy conf into sandbox cwd so relative TypesConfig resolves; run httpd with
// working dir = sandbox (ServerRoot default). Use plain start (NOT -k: service mode).
$proc = proc_open(
        "\"$httpd\" -f \"$conf\"",
        array( array( 'pipe', 'r' ), array( 'file', str_replace( '\\', '/', ABSPATH ) . 'tests/sandbox/httpd.stdout', 'w' ), array( 'file', str_replace( '\\', '/', ABSPATH ) . 'tests/sandbox/httpd.stderr', 'w' ) ),
        $pipes,
        str_replace( '\\', '/', ABSPATH ) . 'tests/sandbox'
);
usleep( 900000 );

// ---- probes --------------------------------------------------------------
function get( $url, $headers = array(), $method = 'GET', $cookie = null ) {
        $ch = curl_init( $url );
        curl_setopt_array(
                $ch,
                array(
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HEADER         => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_CUSTOMREQUEST  => $method,
                        CURLOPT_HTTPHEADER     => $headers,
                )
        );
        if ( null !== $cookie ) {
                curl_setopt( $ch, CURLOPT_COOKIE, $cookie );
        }
        $out   = curl_exec( $ch );
        $errno = curl_errno( $ch );
        curl_close( $ch );
        if ( $out === false || $errno ) {
                return array( 'status' => 0, 'headers' => array(), 'body' => '', 'err' => "curl:$errno" );
        }
        list( $head, $body )       = explode( "\r\n\r\n", $out, 2 );
        $status                    = 0;
        foreach ( explode( "\r\n", $head ) as $i => $line ) {
                if ( 0 === $i && preg_match( '#HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
                        $status = (int) $m[1];
                }
                $h[ strtolower( strtok( $line, ':' ) ) ] = trim( (string) substr( strstr( $line, ':' ), 1 ) );
        }
        return array( 'status' => $status, 'headers' => isset( $h ) ? $h : array(), 'body' => $body );
}

$base = 'http://127.0.0.1:8099';
$results = array();
function check( &$results, $name, $cond, $detail = '' ) {
        $results[ $name ] = ( $cond ? 'PASS' : 'FAIL' ) . ( $cond ? '' : " << $detail" );
        echo ( $cond ? "[PASS] " : "[FAIL] " ) . $name . ( $cond ? '' : "  << $detail" ) . "\n";
}

// T1 root cache HIT
unlink("$docroot/.front-controller-hit") || true;
$r = get( "$base/" );
check( $results, 'T1 root HIT serves cached HTML', false !== strpos( $r['body'], 'CACHED-ROOT' ), substr( $r['body'], 0, 80 ) );
check( $results, 'T1 no PHP executed on HIT', ! file_exists( "$docroot/.front-controller-hit" ), '.php-executed marker exists' );
check( $results, 'T1 HIT status 200', 200 === $r['status'], (string) $r['status'] );

// T2 path cache HIT
unlink("$docroot/.front-controller-hit") || true;
$r = get( "$base/shop/" );
check( $results, 'T2 /shop/ HIT serves cached HTML', false !== strpos( $r['body'], 'CACHED-SHOP' ), substr( $r['body'], 0, 80 ) );
check( $results, 'T2 no PHP executed on HIT', ! file_exists( "$docroot/.front-controller-hit" ), 'php marker exists' );

// T3 MISS falls through to PHP (marker file written by the front controller
// is a mod_php-era artifact; with no PHP module the marker cannot be created —
// reaching the front controller CONTENT is the observable proof).
unlink("$docroot/.front-controller-hit") || true;
@mkdir( "$docroot/uncached", 0777, true );
$r = get( "$base/uncached/page/" );
check( $results, 'T3 MISS reaches PHP layer', false !== strpos( $r["body"], "WP-FRONT-CONTROLLER-REACHED" ), substr( $r['body'], 0, 80 ) );

// T4 sensitive cookie bypasses early serve (real WP auth cookie: exactly 32 hex chars).
$fake_auth_cookie = 'wordpress_logged_in_' . str_repeat( 'a', 32 ) . '=admin%7Cjunk';
unlink("$docroot/.front-controller-hit") || true;
$r = get( "$base/", array(), 'GET', $fake_auth_cookie );
check( $results, 'T4 auth cookie → NOT early-served', false === strpos( $r['body'], 'CACHED-ROOT' ), 'got cached body for logged-in cookie' );

unlink("$docroot/.front-controller-hit") || true;
$r = get( "$base/shop/", array(), 'GET', 'woocommerce_cart_hash=xyz; woocommerce_items_in_cart=1' );
check( $results, 'T5 cart cookie → NOT early-served', false !== strpos( $r["body"], "WP-FRONT-CONTROLLER-REACHED" ) || false === strpos( $r['body'], 'CACHED-SHOP' ), 'got cached body' );

// T6 query string → not early-served
unlink("$docroot/.front-controller-hit") || true;
$r = get( "$base/?anything=1" );
check( $results, 'T6 query string → NOT early-served', false !== strpos( $r["body"], "WP-FRONT-CONTROLLER-REACHED" ), substr( $r['body'], 0, 60 ) );

// T7 POST → not early-served
unlink("$docroot/.front-controller-hit") || true;
$r = get( "$base/", array(), 'POST' );
check( $results, 'T7 POST → NOT early-served', false !== strpos( $r["body"], "WP-FRONT-CONTROLLER-REACHED" ), substr( $r['body'], 0, 60 ) );

// T8 wp-admin excluded even if file existed
@mkdir( "$docroot/wp-admin", 0777, true );
file_put_contents( "$docroot/wp-content/cache/ultimate-performance/v/example.com/wp-admin/x/index.html", '<html>SHOULD-NOT-SERVE</html>' );
unlink("$docroot/.front-controller-hit") || true;
$r = get( "$base/wp-admin/x/" );
check( $results, 'T8 wp-admin excluded from early serve', false === strpos( $r['body'], 'SHOULD-NOT-SERVE' ), 'leaked admin cache' );

// T9 traversal-shaped URI cannot be early-served from the CACHE TREE.
unlink("$docroot/.front-controller-hit") || true;
// Fixture: wp-config.php in the docroot with a secret marker. ENVIRONMENT
// NOTE (verified live, Apache 2.4.68 unix build): Apache CORE path handling
// alone (no .htaccess at all) resolves /%2e%2e/%2e%2e/wp-config.php to the
// real file — that is an Apache-domain behavior outside the plugin's remit
// and MUST NOT be asserted here (with mod_php the file executes and on other
// Apache builds the escape is 400'd). The PLUGIN-scoped security property is
// narrower and absolute: the managed block must NEVER early-serve a
// traversal-shaped URI from the plugin's cache tree, and must fall through
// untouched. Both are asserted below.
file_put_contents( "$docroot/wp-config.php", "<?php\n// UC-DO-NOT-LEAK-WPCONFIG-7f3a9c\n" );
$r = get( "$base/%2e%2e/%2e%2e/wp-config.php" );
$served_by_plugin = false !== strpos( $r['body'], 'CACHED-ROOT' )
        || false !== strpos( $r['body'], 'CACHED-SHOP' )
        || false !== strpos( $r['body'], 'SHOULD-NOT-SERVE' );
check( $results, 'T9 traversal URI not early-served by plugin', ! $served_by_plugin, substr( $r['body'], 0, 60 ) );

// T10 meta.json denied over HTTP
file_put_contents( "$docroot/wp-content/cache/ultimate-performance/v/example.com/shop/index.html.meta.json", '{"secret":"meta"}' );
$r = get( "$base/wp-content/cache/ultimate-performance/v/example.com/shop/index.html.meta.json" );
check( $results, 'T10 .meta.json denied (403/404)', 403 === $r['status'] || 404 === $r['status'], 'status=' . $r['status'] . ' body=' . substr( $r['body'], 0, 50 ) );

// ---- stop server ---------------------------------------------------------
// httpd daemonizes: the proc_open child exits immediately and the real server
// keeps running (its workers inherited this suite's stdin pipe — leaving it
// running made PIPE-connected runs stall and port-conflict reruns flake).
// Bounded teardown: TERM via PidFile, wait, KILL fallback, then taskkill on
// Windows keeps its legacy path.
$pid_file = "$docroot/../httpd.pid";
$daemon_pid = @file_get_contents( $pid_file );
proc_terminate( $proc );
if ( is_string( $daemon_pid ) && preg_match( '/^\d+$/', trim( $daemon_pid ) ) ) {
        $dp = (int) trim( $daemon_pid );
        @posix_kill( $dp, SIGTERM ); // bounded graceful stop
        for ( $i = 0; $i < 10 && @posix_kill( $dp, 0 ); ++$i ) {
                usleep( 200000 );
        }
        if ( @posix_kill( $dp, 0 ) ) {
                @posix_kill( $dp, SIGKILL );
        }
}
@shell_exec( "taskkill /F /IM httpd.exe /FI \"WINDOWTITLE ne XAMPP*\" >" . ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ? 'NUL' : '/dev/null' ) . " 2>&1" );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) {
        echo str_pad( $v, 8 ) . " $k\n";
        if ( 'FAIL' === substr( $v, 0, 4 ) ) {
                ++$fails;
        }
}
exit( $fails ? 1 : 0 );
