<?php
/**
 * AUDIT TEST — M3: Nginx rules generator contract (unit-level).
 *
 * Covers:
 *   G1 determinism — same input → byte-identical snippet.
 *   G2 host gate   — hostile/unparseable hosts refused (fail closed, '').
 *   G3 path gate   — relative/traversal/control-char cache roots refused.
 *   G4 origin gate — only IP:port literals accepted (no resolver dependency).
 *   G5 shape       — managed markers, maps, internal-only cache location,
 *                    alias onto <cache_root>/v/, host literal, root physical
 *                    dir == Key::segment('(root)'), php-never-served-from-disk
 *                    guard, original-URI propagation header.
 *   G6 injection   — CR/LF/NUL in any input can never reach the output.
 *   G7 probe       — probe_uri/probe_body shape, token validation, byte
 *                    contract for the static-serve verification.
 *
 * Run: php tests/audit-nginx-rules.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\WebServer\Nginx\Rules;

$results = array();
function gcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$ok = array(
        'host'       => 'nginx1.test',
        'cache_root' => '/srv/site/wp-content/cache/ultimate-performance',
        'origin'     => '127.0.0.1:8098',
        'listen'     => '127.0.0.1:8097',
        'docroot'    => '/srv/site/wordpress',
);

// G1 — determinism
$a = Rules::generate( $ok );
$b = Rules::generate( $ok );
gcheck( $results, 'G1a generation is deterministic', '' !== $a && $a === $b );

// G2 — host gate
gcheck( $results, 'G2a hostile host refused', '' === Rules::generate( array_merge( $ok, array( 'host' => 'bad host/../x' ) ) ) );
gcheck( $results, 'G2b empty host refused', '' === Rules::generate( array_merge( $ok, array( 'host' => '' ) ) ) );
gcheck( $results, 'G2c newline-in-host refused (header injection)', '' === Rules::generate( array_merge( $ok, array( 'host' => "good.test\r\nX: y" ) ) ) );
gcheck( $results, 'G2d uppercase host canonicalized into output', false !== strpos( Rules::generate( array_merge( $ok, array( 'host' => 'NGINX1.TEST' ) ) ), 'nginx1.test' ) );

// G3 — cache_root gate
gcheck( $results, 'G3a relative cache_root refused', '' === Rules::generate( array_merge( $ok, array( 'cache_root' => 'wp-content/cache' ) ) ) );
gcheck( $results, 'G3b traversal cache_root refused', '' === Rules::generate( array_merge( $ok, array( 'cache_root' => '/srv/../etc/cache' ) ) ) );
gcheck( $results, 'G3c newline-in-cache_root refused', '' === Rules::generate( array_merge( $ok, array( 'cache_root' => "/srv/x\nalias /etc;#" ) ) ) );

// G4 — origin gate
gcheck( $results, 'G4a hostname origin refused (no resolver dependency)', '' === Rules::generate( array_merge( $ok, array( 'origin' => 'backend.internal:8098' ) ) ) );
gcheck( $results, 'G4b bare port origin refused', '' === Rules::generate( array_merge( $ok, array( 'origin' => '8098' ) ) ) );
gcheck( $results, 'G4c invalid port refused', '' === Rules::generate( array_merge( $ok, array( 'origin' => '127.0.0.1:0' ) ) ) );

// G5 — shape contract (whitespace-collapsed checks; indentation is not a contract)
// BENCH-D5 (HARDEN-2): root now maps through Key::ROOT_SENTINEL ('__root__')
// instead of Key::segment('(root)') which hashed to 'h<sha1[:20]>'.
$root_phys = Key::ROOT_SENTINEL;
$flat = preg_replace( '/\s+/', ' ', $a );
gcheck( $results, 'G5a managed block markers present', false !== strpos( $a, Rules::BEGIN ) && false !== strpos( $a, Rules::END ) );
gcheck( $results, 'G5b composite map gate present (http-level)', false !== strpos( $flat, 'map "$up_method_ok|$up_cookieless|$is_args" $up_static' ) && false !== strpos( $flat, 'map $http_cookie $up_cookieless' ) );
gcheck( $results, 'G5c cache location is internal-only', false !== strpos( $flat, 'location ^~ /uc-cache/ {' ) && false !== strpos( $flat, 'internal;' ) );
gcheck( $results, 'G5d alias maps onto cache_root/v/', false !== strpos( $flat, 'alias ' . $ok['cache_root'] . '/v/;' ) );
gcheck( $results, 'G5e host literal embedded (per-site mapping)', false !== strpos( $flat, '/uc-cache/nginx1.test/' ) );
gcheck( $results, 'G5f root maps to ROOT_SENTINEL (single source of truth, unhashed)', false !== strpos( $flat, '/uc-cache/nginx1.test/' . $root_phys . '/index.html' ) );
gcheck( $results, 'G5g php never served from disk (always proxied)', false !== strpos( $flat, 'location ~ \.php$ {' ) && false !== strpos( $flat, 'proxy_pass http://$up_origin;' ) );
gcheck( $results, 'G5h original URI propagated to origin (WP routing intact)', false !== strpos( $flat, 'X-UC-Original-URI' ) && false !== strpos( $flat, 'set $up_orig_uri $request_uri;' ) );
gcheck( $results, 'G5i origin literal embedded', false !== strpos( $flat, 'set $up_origin 127.0.0.1:8098;' ) );
gcheck( $results, 'G5j complete server{} emitted (http-context include)', false !== strpos( $flat, 'server {' ) && false !== strpos( $flat, 'listen ' . $ok['listen'] . ';' ) && false !== strpos( $flat, 'root ' . $ok['docroot'] . ';' ) );
gcheck( $results, 'G5k rewrite-only if bodies (no bare set inside if)', 0 === preg_match( '/if \( \$up_static[^)]*\) \{\s*set /', $a ) );
gcheck( $results, 'G5l shipped hardening: wp-config/.env/.git denied', false !== strpos( $flat, 'location = /wp-config.php { deny all; }' ) && false !== strpos( $flat, 'location ~* /\.(env|git) { deny all; }' ) );

// G6 — injection hardening (already refused upstream; assert output clean anyway)
$out = Rules::generate( $ok );
gcheck( $results, 'G6a output contains no CR bytes', false === strpos( $out, "\r" ) );
gcheck( $results, 'G6b output contains no NUL bytes', false === strpos( $out, "\0" ) );

// G7 — probe contract
$t  = 'abc123def456';
$pu = Rules::probe_uri( $t );
gcheck( $results, 'G7a probe URI shape', '/uc-verify-abc123def456/' === $pu, $pu );
gcheck( $results, 'G7b probe body byte contract', 'ultimate-performance-nginx-probe:abc123def456' === Rules::probe_body( $t ) );
gcheck( $results, 'G7c short token refused', '' === Rules::probe_uri( 'abc' ) );
gcheck( $results, 'G7d token with meta chars refused', '' === Rules::probe_uri( 'abc123../etc' ) );
gcheck( $results, 'G7e 64-char token accepted', '/uc-verify-' . str_repeat( 'a', 64 ) . '/' === Rules::probe_uri( str_repeat( 'a', 64 ) ) );
gcheck( $results, 'G7f 65-char token refused', '' === Rules::probe_uri( str_repeat( 'a', 65 ) ) );

// G8 — M6 admin UX: host_dir single source of truth + admin derivation gates
gcheck( $results, 'G8a host_dir maps the canonical host (same mapping generate() emits)', 'nginx1.test' === Rules::host_dir( 'nginx1.test' ) );
gcheck( $results, 'G8b host_dir folds case (uppercase host → same dir)', 'nginx1.test' === Rules::host_dir( 'NGINX1.TEST' ) );
// host_dir is a SANITIZER (strips to the safe charset) — refusal semantics
// live in canonical_host/generate (G2). The security contract here: no
// slash, space or control byte can ever reach the emitted config.
$hd1 = Rules::host_dir( "good.test\r\nX: y" );
$hd2 = Rules::host_dir( 'good.test/../etc' );
$hd3 = Rules::host_dir( 'bad host/../x' );
gcheck( $results, 'G8c host_dir output is charset-safe for hostile inputs (no /, space, CR, LF, NUL)', 1 === preg_match( '/^[a-z0-9.\[\]\-]*$/', $hd1 ) && 1 === preg_match( '/^[a-z0-9.\[\]\-]*$/', $hd2 ) && 1 === preg_match( '/^[a-z0-9.\[\]\-]*$/', $hd3 ), json_encode( array( $hd1, $hd2, $hd3 ) ) );
gcheck( $results, 'G8d generate() still REFUSES the hostile hosts host_dir sanitized (refusal lives upstream)', '' === Rules::generate( array_merge( $ok, array( 'host' => 'bad host/../x' ) ) ) && '' === Rules::generate( array_merge( $ok, array( 'host' => "good.test\r\nX: y" ) ) ) );
gcheck( $results, 'G8e generate() + host_dir agree on the probe-write path', false !== strpos( $a, '/uc-cache/' . Rules::host_dir( 'nginx1.test' ) . '/' ) );
// G8f: admin derivation — the AdminPage must refuse to emit when origin/listen
// are unset (fail-closed UX: instructions instead of an empty snippet).
$admin_inputs_missing = array( 'host' => 'nginx1.test', 'cache_root' => $ok['cache_root'], 'origin' => '', 'listen' => '', 'docroot' => $ok['docroot'] );
gcheck( $results, 'G8f generator refuses empty origin (admin pre-derivation)', '' === Rules::generate( $admin_inputs_missing ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
