<?php
/**
 * AUDIT TEST — Phase F: metadata / private-storage HTTP denial live-fire.
 *
 * Real Apache + real filesystem. Cache root is inside the docroot
 * (wp-content/cache/ultimate-performance), so every internal file type must be
 * HTTP-denied by the generated .htaccess. Architecture: PUBLIC = only
 * v/** /index.html bodies. PRIVATE = .meta.json, locks, tmp, indexes, tags,
 * stats, diagnostics.
 *
 * Live-fires direct HTTP access to every internal file type, plus URL-encoded
 * and case-variant paths (NTFS is case-insensitive; FilesMatch lookahead is
 * case-sensitive on purpose — uppercase alias of a private file MUST be denied).
 *
 * ENVIRONMENT-SPECIFIC RESULT: measurements on Windows/XAMPP/HDD — latency not
 * a universal benchmark. Correctness assertions are environment-independent.
 *
 * Run: php tests/audit-metadata.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' ); // portable WP shim (test infrastructure)
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WPINC', 'wp-includes' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Core\Installer;

$results = array();
$skips  = array();
function fcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? " ($detail)" : " << $detail" ) . "\n";
}
// TB-4 FIX (re-applied): the case-variant row (ext4 vs NTFS) called fskip()
// which was never defined - undefined only on the live path (the env gate
// SKIPs before reaching it), so live execution FATALED exit 255 after 17
// PASS lines. First application of this fix was destroyed by the pre-commit
// restore script in the 1aebcee stage (commit captured only the worklog);
// documented in the worklog. A skip is recorded OUTSIDE \$results so it is
// never counted as a PASS.
function fskip( $name, $reason = '' ) {
        global $skips;
        $skips[ $name ] = true;
        echo "[SKIP] \$name << \$reason\n";
}

// TB-3 FIX: $root_url was hardcoded to the original Windows/XAMPP deployment
// (http://localhost/wordpress/...) while the server-presence gate below reads
// UC_METADATA_BASE_URL — the documented override never affected the live-fire
// URLs, so every row returned HTTP 0 on any other deployment. Both the gate
// and the live-fire base now use the same env-aware source.
$root_url = getenv( 'UC_METADATA_BASE_URL' ) ?: 'http://localhost/wordpress/wp-content/cache/ultimate-performance';
$root_fs  = Installer::cache_root();

// ---------------------------------------------------------------- gate
// This suite LIVE-FIRES HTTP requests against a real Apache serving the
// WordPress docroot (the denial semantics under test live in .htaccess —
// PHP dev servers cannot reproduce them). Without that server the suite
// SKIPS CLEANLY: a skip is reported as a skip, never as a pass.
// UC_METADATA_BASE_URL lets deployments point at their own host.
$base_probe = getenv( 'UC_METADATA_BASE_URL' ) ?: 'http://localhost/wordpress/wp-content/cache/ultimate-performance';
$probe_ctx  = stream_context_create( array( 'http' => array( 'method' => 'GET', 'ignore_errors' => true, 'timeout' => 5 ) ) );
$probe_body = @file_get_contents( $base_probe . '/', false, $probe_ctx );
$probe_code = 0;
foreach ( $http_response_header ?? array() as $h ) {
        if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $h, $m ) ) { $probe_code = (int) $m[1]; }
}
if ( 0 === $probe_code ) {
        echo "[SKIP] audit-metadata << no HTTP server at {$base_probe} (set UC_METADATA_BASE_URL or serve the docroot with Apache)\n";
        echo "       .htaccess denial live-fire requires Apache; skipped rows are NOT counted as PASS\n";
        echo "\n==== SUMMARY ====\n";
        echo "0 checks executed, all skipped (no HTTP server) — environment-gated\n";
        exit( 0 );
}

// ---------------------------------------------------------------- 0. fixture
Installer::ensure_cache_root(); // rewrites .htaccess with current generator output
@mkdir( "$root_fs/v/localhost/(root)", 0777, true );

$fixtures = array(
        'meta'        => array( "$root_fs/v/localhost/(root)/index.html.meta.json", '{"id":"f","tags":[]}' ),
        'lock'        => array( "$root_fs/v/localhost/(root)/index.html.lock", '{}' ),
        'tmp'         => array( "$root_fs/v/localhost/(root)/.uctmp_probe.tmp", 'x' ),
        'index-dot'   => array( "$root_fs/v/localhost/(root)/.index", 'i' ),
        'stat'        => array( "$root_fs/stats.jsonl", "[0,\"probe\"]\n" ),
        'tag'         => array( "$root_fs/meta/tag-probe.json", '["x"]' ),
        'queue'       => array( "$root_fs/meta/queue-state.queue", 'q' ),
        'log'         => array( "$root_fs/diag.log", 'l' ),
        'serialize'   => array( "$root_fs/dump.serialize", 'O:8:"stdClass"' ),
        'diagtxt'     => array( "$root_fs/diagnostics.txt", 'd' ),
        'dotfile'     => array( "$root_fs/.secret", 's' ),
);
foreach ( $fixtures as $f ) {
        @file_put_contents( $f[0], $f[1] );
}
// Public body that MUST remain reachable.
@file_put_contents( "$root_fs/v/localhost/(root)/index.html", '<!DOCTYPE html><html><body>F-BODY-OK-MARKER</body></html>' );

function http_code( $url ) {
        $ctx = stream_context_create( array( 'http' => array(
                'method'        => 'GET',
                'ignore_errors' => true,
                'timeout'       => 10,
        ) ) );
        $data = @file_get_contents( $url, false, $ctx );
        $code = 0;
        foreach ( $http_response_header ?? array() as $h ) {
                if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $h, $m ) ) { $code = (int) $m[1]; }
        }
        return array( $code, false === $data ? '' : substr( $data, 0, 120 ) );
}

// ------------------------------------------------- 1. private types denied
$private_urls = array(
        '.meta.json direct'      => "$root_url/v/localhost/(root)/index.html.meta.json",
        '.meta.json URL-encoded' => "$root_url/v/localhost/%28root%29/index.html.meta%2ejson",
        '.lock'                  => "$root_url/v/localhost/(root)/index.html.lock",
        '.tmp'                   => "$root_url/v/localhost/(root)/.uctmp_probe.tmp",
        '.index internal'        => "$root_url/v/localhost/(root)/.index",
        'stats.jsonl'            => "$root_url/stats.jsonl",
        'tag json'               => "$root_url/meta/tag-probe.json",
        'queue state'            => "$root_url/meta/queue-state.queue",
        '.log'                   => "$root_url/diag.log",
        '.serialize dump'        => "$root_url/dump.serialize",
        'diagnostics.txt'        => "$root_url/diagnostics.txt",
        'dotfile'                => "$root_url/.secret",
        // Case variants: NTFS maps these to the SAME files.
        'META.JSON uppercase ext'=> "$root_url/v/localhost/(root)/INDEX.HTML.META.JSON",
        'TAG json uppercase'     => "$root_url/META/TAG-PROBE.JSON",
        'DIAG.LOG uppercase'     => "$root_url/DIAG.LOG",
        'STATS.JSONL uppercase'  => "$root_url/STATS.JSONL",
);
foreach ( $private_urls as $label => $u ) {
        list( $c, $b ) = http_code( $u );
        fcheck( $results, "F deny: $label", in_array( $c, array( 403, 404 ), true ) && false === strpos( $b, '{' ), "HTTP $c" );
}

// ---------------------------------------------- 2. public body still served
list( $c2, $b2 ) = http_code( "$root_url/v/localhost/(root)/index.html" );
fcheck( $results, 'F allow: index.html body reachable', 200 === $c2 && false !== strpos( $b2, 'F-BODY-OK-MARKER' ), "HTTP $c2" );
list( $c3, $b3 ) = http_code( "$root_url/v/localhost/(ROOT)/index.html" ); // dir case variant
// The uppercase-dir alias only resolves to the same file on a CASE-INSENSITIVE
// filesystem (NTFS — the suite's original platform; header documents this).
// On case-sensitive filesystems (ext4) (ROOT) is a distinct, non-existent
// path and the request can legitimately 403/404. Gate the row on the REAL
// filesystem capability instead of hardcoding one platform's expectation:
// a SKIP here is honest environment disclosure, never a fake PASS.
if ( @is_file( "$root_fs/v/localhost/(ROOT)/index.html" ) ) {
        fcheck( $results, 'F allow: index.html via case-variant dir', 200 === $c3 && false !== strpos( $b3, 'F-BODY-OK-MARKER' ), "HTTP $c3" );
} else {
        fskip( 'F allow: index.html via case-variant dir', 'case-insensitive filesystem required (NTFS/macOS); this FS is case-sensitive — uppercase alias is a distinct path' );
}

// --------------------------------------- 3. no directory listing anywhere
list( $c4, $b4 ) = http_code( "$root_url/v/" );
fcheck( $results, 'F no listing: v/', 403 === $c4 || ( 200 === $c4 && false === strpos( $b4, 'index.html' ) ), "HTTP $c4" );
list( $c5, $b5 ) = http_code( "$root_url/meta/" );
fcheck( $results, 'F no listing: meta/', 403 === $c5 || 404 === $c5 || ( 200 === $c5 && false === strpos( $b5, 'tag-' ) ), "HTTP $c5" );

// ------------------------------- 4. generator contract (unit-level checks)
$block = Installer::cache_htaccess();
fcheck( $results, 'F whitelist FilesMatch present', false !== strpos( $block, '^(?!index\\.html$)' ) );
fcheck( $results, 'F (?i) extension blacklist present', false !== strpos( $block, '(?i)\\.(php|phtml|phar|json' ) );
fcheck( $results, 'F dotfile denial present', false !== strpos( $block, '(?i)^\\.' ) );
fcheck( $results, 'F Options -Indexes present', false !== strpos( $block, 'Options -Indexes' ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures, " . count( $skips ) . " honest skips\n";
foreach ( $skips as $k => $v ) { echo "SKIP: $k\n"; }
exit( $fails ? 1 : 0 );
