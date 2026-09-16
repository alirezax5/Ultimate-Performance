<?php
/**
 * M1 real-WP page-cache lifecycle worker.
 *
 * Runs as its OWN PHP process against a REAL WordPress docroot (no shim).
 * The plugin boots inside real WordPress; the page cache Engine captures the
 * rendered front page exactly as it would under a web server.
 *
 * Modes (UC_WORKER_MODE):
 *   render — create the canary post, run the full WP main loop (wp-blog-header),
 *            let the Engine store the rendered page, then report the canary
 *            post id + the page-cache tree that was created (JSON, last line).
 *   fetch  — full WP main loop again; on HIT the Engine serves the cached body
 *            and exits inside intercept(), so stdout IS the served body.
 *
 * Marker contract: this worker registers a template_redirect hook (priority 2,
 * AFTER the Engine's intercept at priority 1). On MISS the marker is echoed
 * into the captured render and therefore stored. On HIT intercept() exits at
 * priority 1, so the marker hook never fires and stdout contains ONLY the
 * previously stored marker. This makes marker containment a real HIT proof.
 *
 * Environment contract (set by tests/run-real-wp-live.sh):
 *   UC_REALWP_DIR, UC_REALWP_URL, UC_WORKER_MODE, UC_WORKER_MARKER
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

if ( PHP_SAPI !== 'cli' ) {
        exit( 1 );
}

$dir    = (string) getenv( 'UC_REALWP_DIR' );
$url    = (string) getenv( 'UC_REALWP_URL' );
$mode   = (string) getenv( 'UC_WORKER_MODE' );
$marker = (string) getenv( 'UC_WORKER_MARKER' );
$uri    = (string) ( getenv( 'UC_WORKER_URI' ) ?: '/' );
$cookie = (string) ( getenv( 'UC_WORKER_COOKIE' ) ?: '' );

if ( '' === $dir || '' === $url || '' === $mode || ! is_dir( $dir ) ) {
        fwrite( STDERR, "worker: bad environment\n" );
        exit( 1 );
}

$host = (string) parse_url( $url, PHP_URL_HOST );
if ( '' === $host ) {
        fwrite( STDERR, "worker: cannot parse host from UC_REALWP_URL\n" );
        exit( 1 );
}

// Anonymous front-page GET: no cookies, no auth, standard accept header.
// UC_WORKER_URI / UC_WORKER_COOKIE let the M2 Woo matrix exercise shop,
// product and session-cookie requests through the same real-WP flow.
$_SERVER = array(
        'HTTP_HOST'      => $host,
        'SERVER_NAME'    => $host,
        'REQUEST_URI'    => $uri,
        'REQUEST_METHOD' => 'GET',
        'HTTP_ACCEPT'    => 'text/html,application/xhtml+xml',
        'REMOTE_ADDR'    => '127.0.0.1',
        'SERVER_PORT'    => '80',
        'SERVER_PROTOCOL'=> 'HTTP/1.1',
        'SCRIPT_NAME'    => '/index.php',
        'PHP_SELF'       => '/index.php',
        'SERVER_SOFTWARE'=> 'UC-M1-worker (CLI, real-WP lifecycle)',
        'HTTP_USER_AGENT'=> 'UC-M1-Worker/1.0',
);
if ( '' !== $cookie ) {
        $_SERVER['HTTP_COOKIE'] = $cookie;
        // CLI SAPIs do not parse the Cookie header into $_COOKIE — a real web SAPI
        // does this before WordPress/WooCommerce ever run. Reproduce it so session
        // cookies reach the classifier and WooCommerce exactly as under httpd.
        foreach ( explode( ';', $cookie ) as $kv ) {
                $eq = strpos( $kv, '=' );
                if ( false === $eq ) {
                        continue;
                }
                $k = trim( substr( $kv, 0, $eq ) );
                $v = trim( substr( $kv, $eq + 1 ) );
                if ( '' !== $k ) {
                        $_COOKIE[ $k ] = $v;
                }
        }
}

// WordPress's parse_request() reads the query string from
// $_SERVER['QUERY_STRING'] — the query part of REQUEST_URI is NOT parsed
// (M2 finding: without it, '/?page_id=N' silently degraded to the home
// query and the shop page stored with front-page tags). Mirror the SAPI.
$qpos = strpos( $uri, '?' );
$_SERVER['QUERY_STRING']      = false === $qpos ? '' : substr( $uri, $qpos + 1 );
$_SERVER['REQUEST_TIME']      = time();
$_SERVER['REQUEST_TIME_FLOAT']= microtime( true );

// WP_USE_THEMES must be defined BEFORE the library loads: wp-settings reads
// it into $GLOBALS['wp_using_themes'], and wp-includes/template-loader.php
// fires template_redirect (where the page-cache Engine intercepts) ONLY when
// wp_using_themes() is true. wp-blog-header.php sets this for real requests.
if ( ! defined( 'WP_USE_THEMES' ) ) {
        define( 'WP_USE_THEMES', true );
}

// Boot the WordPress library ONLY (no main loop yet): wp-load → wp-config →
// wp-settings, firing plugins_loaded — the plugin's late_boot registers the
// page-cache Engine on template_redirect (priority 1).
require_once rtrim( $dir, '/' ) . '/wp-load.php';

// NOW add_action exists. Marker hook: priority 2 = strictly after the Engine's
// intercept (priority 1). Fires only on the MISS/render path, so it becomes
// part of the stored body.
add_action(
        'template_redirect',
        static function () use ( $marker ) {
                echo "\n<!--UCM:" . esc_html( $marker ) . "-->\n";
        },
        2
);

// Canary post: created on the render path only, so the rendered front page
// contains fresh content whose invalidation can later be verified end-to-end
// (wp_update_post → save_post → Hooks::purge_post → stored page purged).
$canary_id = 0;
if ( 'render' === $mode ) {
        $canary_id = wp_insert_post(
                array(
                        'post_title'   => 'UC-M1 canary ' . $marker,
                        'post_content' => 'Real-WP invalidation canary for marker ' . $marker,
                        'post_status'  => 'publish',
                ),
                true
        );
        if ( is_wp_error( $canary_id ) ) {
                $canary_id = 0;
        }
}

// Real web SAPIs always carry a response status; CLI starts with none
// (http_response_code() === false), which the Engine treats as a bypass
// status. Establish the same baseline a real server would.
http_response_code( 200 );

// Full WP main loop, exactly as wp-blog-header.php runs it — the query first,
// then template-loader (which fires template_redirect → Engine intercept).
wp();
require ABSPATH . WPINC . '/template-loader.php';

// Reaching this point = the request was NOT served from cache (no exit inside
// intercept). The Engine's capture buffer is still open — flush it NOW so the
// ob callback (on_output → Store::write) runs BEFORE anything else is echoed;
// otherwise the JSON report below would be captured into the stored body.
while ( ob_get_level() > 0 ) {
        ob_end_flush();
}

// Report what was rendered/stored.
$root  = \UltimatePerformance\Core\Installer::cache_root() . '/v';
$files = array();
if ( is_dir( $root ) ) {
        $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $it as $f ) {
                if ( $f->isFile() ) {
                        $files[] = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $root ) + 1 ) );
                }
        }
        sort( $files );
}

echo "\nUCWORKER-JSON:" . wp_json_encode(
        array(
                'mode'      => $mode,
                'marker'    => $marker,
                'host'      => $host,
                'canary_id' => $canary_id,
                'v_files'   => $files,
        )
) . "\n";
exit( 0 );
