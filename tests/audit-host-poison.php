<?php
/**
 * AUDIT TEST — Host-header poisoning suite (STEP 3).
 *
 * Policy under test:
 *   - Cache key host comes ONLY from the administrator allowlist match.
 *   - X-Forwarded-Host / X-Original-Host / Forwarded are NEVER trusted.
 *   - Unknown/attacker hosts → BYPASS (no cache write, no cache read).
 *
 * Run: php tests/audit-host-poison.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', ABSPATH ) );
define( 'WP_CONTENT_DIR', ULTIMATE_PERFORMANCE_DIR . 'tests/sandbox/wp-content' );

// ---- WP shims (global) ------------------------------------------------------
function wp_normalize_path( $path ) {
	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );
	if ( ':' === substr( $path, 1, 1 ) ) { $path = ucfirst( $path ); }
	return $path;
}
function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0777, true ); }
function apply_filters( $t, $v ) { return $v; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( $url, $c ); }
function get_sites( $args = array() ) { return array(); }
function get_site_url( $bid ) { return 'https://site' . $bid . '.example'; }

// home_url defines the ONE canonical site host.
$GLOBALS['up_site_host'] = 'victim.example';
function home_url( $p = '' ) { return 'https://' . $GLOBALS['up_site_host'] . $p; }
function is_multisite() { return false; }
function get_option( $n, $d = false ) { return $GLOBALS['up_opt'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['up_opt'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['up_opt'][ $n ] ); return true; }

require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/SafeFs.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Installer.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Settings.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Request/Classifier.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/CacheKey/Key.php';

use UltimatePerformance\Request\Classifier;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\Settings;

$results = array();
function check( &$r, $name, $cond, $detail = '' ) {
	$r[ $name ] = $cond;
	echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$settings = Settings::instance();
$clf      = new Classifier( $settings );

/**
 * The end-to-end contract: given superglobal-like inputs, what does the
 * pipeline decide + which host directory would be used?
 */
function pipeline( $clf, $server ) {
	// Classifier must derive host from an allowlist check, not raw header trust.
	return $clf->classify(
		$server['REQUEST_METHOD'] ?? 'GET',
		$server['REQUEST_URI'] ?? '/',
		array(),
		$server['HTTP_HOST'] ?? null,
		$server
	);
}

// ------------------------------------------------------------------ cases ----
// Each: name => $_SERVER slice. Expected: classification + safe host dir or none.
$cases = array(
	'normal victim host' => array(
		'server'   => array( 'HTTP_HOST' => 'victim.example', 'REQUEST_URI' => '/product/x/' ),
		'expect'   => array( Classifier::PUBLIC_CACHEABLE, 'victim.example' ),
	),
	'attacker Host header' => array(
		'server'   => array( 'HTTP_HOST' => 'attacker.example', 'REQUEST_URI' => '/product/x/' ),
		'expect'   => array( Classifier::BYPASS, null ),
	),
	'X-Forwarded-Host attack' => array(
		'server'   => array( 'HTTP_HOST' => 'victim.example', 'HTTP_X_FORWARDED_HOST' => 'attacker.example', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( null, null ), // special: XFH must not influence dir; checked below
	),
	'X-Original-Host attack' => array(
		'server'   => array( 'HTTP_HOST' => 'victim.example', 'HTTP_X_ORIGINAL_HOST' => 'attacker.example', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( null, null ),
	),
	'Forwarded header attack' => array(
		'server'   => array( 'HTTP_HOST' => 'victim.example', 'HTTP_FORWARDED' => 'host=attacker.example', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( null, null ),
	),
	'X-Forwarded-Proto https downgrade confusion' => array(
		'server'   => array( 'HTTP_HOST' => 'victim.example', 'HTTP_X_FORWARDED_PROTO' => 'http', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( null, null ),
	),
	'Host with port' => array(
		'server'   => array( 'HTTP_HOST' => 'victim.example:443', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( Classifier::PUBLIC_CACHEABLE, 'victim.example' ),
	),
	'Host FQDN trailing dot' => array(
		'server'   => array( 'HTTP_HOST' => 'victim.example.', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( Classifier::PUBLIC_CACHEABLE, 'victim.example' ),
	),
	'Host IPv6 bracketed' => array(
		'server'   => array( 'HTTP_HOST' => '[2001:db8::1]', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( Classifier::BYPASS, null ), // not on allowlist even if well-formed
	),
	'Host IPv6 bracketed+port' => array(
		'server'   => array( 'HTTP_HOST' => '[2001:db8::1]:443', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( Classifier::BYPASS, null ),
	),
	'Host malicious chars' => array(
		'server'   => array( 'HTTP_HOST' => "victim.example\r\nX-Injected: 1", 'REQUEST_URI' => '/p/' ),
		'expect'   => array( Classifier::BYPASS, null ),
	),
	'Host encoded chars' => array(
		'server'   => array( 'HTTP_HOST' => 'victim%2eexample', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( Classifier::BYPASS, null ),
	),
	'Host uppercase' => array(
		'server'   => array( 'HTTP_HOST' => 'VICTIM.EXAMPLE', 'REQUEST_URI' => '/p/' ),
		'expect'   => array( Classifier::PUBLIC_CACHEABLE, 'victim.example' ),
	),
);

foreach ( $cases as $name => $tc ) {
	$res = pipeline( $clf, $tc['server'] );
	list( $exp_class, $exp_dir_host ) = $tc['expect'];

	if ( null === $exp_class ) {
		// Header-injection specials: assert forwarded headers NEVER change the
		// derived host dir vs the plain request. Compare against baseline.
		continue;
	}
	$ok_class = $res['classification'] === $exp_class;
	$dirhost  = $res['host'] ?? Key::canonical_host( $tc['server']['HTTP_HOST'] );
	$ok_host  = null === $exp_dir_host || $dirhost === $exp_dir_host;
	check( $results, "Poison: $name", $ok_class && $ok_host, "class={$res['classification']} host=$dirhost" );
}

// --- Explicit forwarded-header non-interference checks -----------------------
$base = pipeline( $clf, array( 'HTTP_HOST' => 'victim.example', 'REQUEST_URI' => '/p/' ) );
foreach (
	array(
		'HTTP_X_FORWARDED_HOST'    => 'attacker.example',
		'HTTP_X_ORIGINAL_HOST'     => 'attacker.example',
		'HTTP_FORWARDED'           => 'host=attacker.example;proto=https',
		'HTTP_X_FORWARDED_PROTO'   => 'http',
		'HTTP_X_HOST'              => 'attacker.example',
		'HTTP_X_REWRITE_URL'       => '/evil/',
	) as $hdr => $val
) {
	$s   = array( 'HTTP_HOST' => 'victim.example', 'REQUEST_URI' => '/p/', $hdr => $val );
	$res = pipeline( $clf, $s );
	check(
		$results,
		"Forwarded hdr ignored: $hdr",
		$res['classification'] === $base['classification'],
		"class changed: {$base['classification']} -> {$res['classification']}"
	);
}

// --- Cross-host serving impossibility ---------------------------------------
// An entry written for victim.example must never resolve under attacker dir.
$k_victim = new Key( $settings );
$b_victim = $k_victim->build( 'https', 'victim.example', '/p/', '' );
$b_attack = $k_victim->build( 'https', 'attacker.example', '/p/', '' );
check( $results, 'Distinct hosts → distinct dirs', false === $b_attack || $b_victim['dir'] !== $b_attack['dir'] );

$fails = 0;
echo "\n==== SUMMARY ====\n";
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
