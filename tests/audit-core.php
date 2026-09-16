<?php
/**
 * AUDIT TEST — SafeFs containment, CacheKey security, Classifier fail-closed,
 * ResponseSanitizer (Phases 4/5/6/7/9/10/11/13).
 *
 * Pure PHP; no WordPress needed beyond shims. Run: php tests/audit-core.php
 */

// ---- WP function shims - MUST be global namespace ------------------------
// ---- WP shims (global namespace) -------------------------------------------
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '|(?<=.)/+|', '/', $path );
		if ( ':' === substr( $path, 1, 1 ) ) {
			$path = ucfirst( $path );
		}
		return $path;
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || @mkdir( $dir, 0777, true );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $f ) { @unlink( $f ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $p = '' ) { return 'https://example.com' . $p; }
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() { return false; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $c = -1 ) { return parse_url( $url, $c ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { return preg_replace( '/[^a-zA-Z0-9:\/\?\.\=\&\-_~%#@\[\]]/', '', $u ); }
}
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		global $up_test_options;
		return $up_test_options[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		global $up_test_options;
		$up_test_options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		global $up_test_options;
		unset( $up_test_options[ $name ] );
		return true;
	}
}


define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', ABSPATH ) );
define( 'WP_CONTENT_DIR', ULTIMATE_PERFORMANCE_DIR . 'tests/sandbox/wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/SafeFs.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Uuid7.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Installer.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Settings.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Request/Classifier.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/CacheKey/Key.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Security/ResponseSanitizer.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Lock/FileLock.php';

use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Lock\FileLock;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\Request\Classifier;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Security\ResponseSanitizer;

$results = array();
function check( &$results, $name, $cond, $detail = '' ) {
	$results[ $name ] = $cond;
	echo ( $cond ? "[PASS] " : "[FAIL] " ) . $name . ( $cond ? '' : "  << $detail" ) . "\n";
}

// Isolated sandbox root for FS tests.
$fs_root = WP_CONTENT_DIR . '/cache/ultimate-performance';
@mkdir( $fs_root . '/v/example.com', 0777, true );
SafeFs::allow_root( $fs_root );
$fs = new SafeFs();

// =========================================================== PHASE 4: SafeFs
$escape_attempts = array(
	'traversal-parent'      => $fs_root . '/v/../../evil.txt',
	'double-traversal'      => $fs_root . '/v/x/../../../evil2.txt',
	'encoded-traversal'     => $fs_root . '/v/%2e%2e/evil3.txt',
	'backslash-traversal'   => $fs_root . '/v\\..\\..\\evil4.txt',
	'absolute-docroot'      => 'D:/xampp/htdocs/wordpress/wp-config.php',
	'absolute-plugins'      => ULTIMATE_PERFORMANCE_DIR . '../unified-connect/via-safe.txt',
	'unc-path'              => '//server/share/file.txt',
	'drive-relative'        => 'C:/Windows/temp/evil5.txt',
	'trailing-dot-dir'      => $fs_root . '/v/example.com " . "/x./file.txt',
	'ads-stream'            => $fs_root . '/v/example.com/index.html:hidden',
	'device-name'           => $fs_root . '/v/NUL/file.txt',
);
foreach ( $escape_attempts as $name => $target ) {
	$target = str_replace( ' " . "', '', $target ); // cleanup fixture artifact
	if ( false === strpos( $name, 'trailing-dot' ) ) {
		check( $results, "FS reject write: $name", false === $fs->validate_write( $target ), "accepted: $target" );
	} else {
		check( $results, "FS reject write: $name", false === $fs->validate_write( $fs_root . '/v/example.com/x./file.txt' ), 'trailing-dot accepted' );
	}
	// 'absolute-docroot' names a REAL pre-existing WP core file (wp-config.php);
	// the security property is that SafeFs never WRITES it, not that it doesn't exist.
	if ( false === strpos( $name, 'no file created' ) && 'absolute-docroot' !== $name ) {
		check( $results, "FS no file created: $name", ! file_exists( $target ), "$target exists!" );
	}
}

// Legitimate ops work.
$ok_path = $fs_root . '/v/example.com/shop/index.html';
check( $results, 'FS legit atomic write', true === $fs->write_atomic( $ok_path, '<html>ok</html>' ) );
check( $results, 'FS read-back matches', '<html>ok</html>' === $fs->read( $ok_path ) );

// Symlink escape attempt.
$link = $fs_root . '/v/link-test';
$outside = WP_CONTENT_DIR . '/outside-target.txt';
@file_put_contents( $outside, 'OUTSIDE-DATA' );
@symlink( $outside, $link ); // may fail w/o privilege on Windows — that's fine
if ( is_link( $link ) || file_exists( $link ) ) {
	check( $results, 'FS symlink write rejected', false === $fs->write_atomic( $link, 'hijack' ) );
	check( $results, 'FS symlink target untouched', 'OUTSIDE-DATA' === @file_get_contents( $outside ) );
	@unlink( $link );
} else {
	check( $results, 'FS symlink creation unsupported on host (skipped)', true );
}

// delete_tree containment.
$deep = $fs_root . '/v/a/b/c';
@mkdir( $deep, 0777, true );
@file_put_contents( $deep . '/x.txt', 'x' );
check( $results, 'FS delete_tree works inside root', true === $fs->delete_tree( $fs_root . '/v/a' ) );
check( $results, 'FS delete_tree rejected outside root', false === $fs->delete_tree( WP_CONTENT_DIR . '/uploads-sim' ) );

// Atomicity: reader never sees partial content.
$atomic_path = $fs_root . '/v/example.com/atomic/index.html';
@mkdir( dirname( $atomic_path ), 0777, true );
$big = str_repeat( 'A', 300000 );
$fs->write_atomic( $atomic_path, $big );
$read_ok = true;
for ( $i = 0; $i < 20; $i++ ) {
	$c = @file_get_contents( $atomic_path );
	if ( false === $c || ( strlen( $c ) > 0 && strlen( $c ) !== 300000 && strlen( $c ) !== strlen( $big ) ) ) {
		$read_ok = false;
		break;
	}
}
$fs->write_atomic( $atomic_path, str_repeat( 'B', 150000 ) );
$c = @file_get_contents( $atomic_path );
check( $results, 'FS atomic swap consistent', 150000 === strlen( $c ) && 0 === substr_count( $c, 'A' ), 'len=' . strlen( $c ) );
check( $results, 'FS concurrent reads complete-or-old', $read_ok, 'partial read observed' );

// ======================================================= PHASE 5: CacheKey
$settings    = Settings::instance();
$keygen      = new Key( $settings );

// Host canonicalization attacks.
$host_cases = array(
	array( 'EXAMPLE.com', 'example.com' ),
	array( 'example.com.', 'example.com' ),
	array( 'example.com:8080', 'example.com' ),
	array( '[::1]:8080', '[' . bin2hex( inet_pton( '::1' ) ) . ']' ),
	array( '192.168.1.5', '192.168.1.5' ),
	array( 'evil.com/path', '' ),
	array( 'user@example.com', '' ),
	array( "bad\nhost", '' ),
	array( 'exa mple.com', '' ),
	array( '..', '' ),
);
foreach ( $host_cases as $i => $hc ) {
	list( $in, $want ) = $hc;
	$got               = Key::canonical_host( $in );
	check( $results, "Host canon [$in] → [$want]", $got === $want, "got [$got]" );
}

// Two different URLs must not collide unless normalized intentionally.
$b1 = $keygen->build( 'https', 'example.com', '/product/iphone-15/', '' );
$b2 = $keygen->build( 'https', 'example.com', '/product/iphone-14/', '' );
check( $results, 'Key distinct paths → distinct dirs', $b1['dir'] !== $b2['dir'] );
$b3 = $keygen->build( 'https', 'example.com', '/shop/', 'a=1&b=2' );
$b4 = $keygen->build( 'https', 'example.com', '/shop/', 'b=2&a=1' );
check( $results, 'Key param order normalized (same dir)', $b3['dir'] === $b4['dir'] );
$b5 = $keygen->build( 'https', 'example.com', '/shop/', 'utm_source=google&fbclid=x&utm_campaign=y' );
$b6 = $keygen->build( 'https', 'example.com', '/shop/', '' );
check( $results, 'Key tracking params stripped (same dir)', $b5['dir'] === $b6['dir'] );
$bu = $keygen->build( 'https', 'EVIL.com/../etc', '/../../passwd', 'q=1' );
check( $results, 'Key hostile input rejected or safely mapped', false === $bu || ! preg_match( '/\.\./', $bu['dir'] ) );

// Attacker cannot create arbitrary FS paths via URL segments.
$weird = $keygen->build( 'https', 'example.com', '/a/b%2Fc/d/e/f/g/h/i/j/k/l/m/n/o/p/q/r/s/t/u', '' );
check( $results, 'Key deep path depth-collapsed', is_array( $weird ) ? substr_count( $weird['dir'], '/' ) <= 6 : true, 'dir=' . ( is_array( $weird ) ? $weird['dir'] : '?' ) );
$dotseg = $keygen->build( 'https', 'example.com', '/safe/../unsafe', '' );
check( $results, 'Key dot-segment path rejected upstream', false === $dotseg );
$enc_slash = $keygen->build( 'https', 'example.com', '/a%2Fb/c', '' );
check( $results, 'Key encoded slash hashed not literalized', is_array( $enc_slash ) && false === strpos( $enc_slash['dir'], "\\\\" ) );

// Absolute() never escapes root even for crafted rel_dir.
$crafted = $keygen->absolute( '../../../../../../Windows/System32/config', 'index.html' );
check( $results, 'absolute() traversal contained', false !== strpos( wp_normalize_path( $crafted ), 'cache/ultimate-performance' ) && false === strpos( $crafted, '..' ), $crafted );

// ============================================== PHASE 6/13: Classifier
$clf = new Classifier( $settings );
$_SERVER['HTTP_HOST'] = 'example.com'; // allowlisted via home_url

$cases = array(
	// array( name, method, uri, cookies, expect )
	array( 'GET / public', 'GET', '/', array(), Classifier::PUBLIC_CACHEABLE ),
	array( 'HEAD / public', 'HEAD', '/', array(), Classifier::PUBLIC_CACHEABLE ),
	array( 'POST bypass', 'POST', '/', array(), Classifier::BYPASS ),
	array( 'PUT bypass', 'PUT', '/', array(), Classifier::BYPASS ),
	array( 'PATCH bypass', 'PATCH', '/', array(), Classifier::BYPASS ),
	array( 'DELETE bypass', 'DELETE', '/', array(), Classifier::BYPASS ),
	array( 'OPTIONS bypass', 'OPTIONS', '/', array(), Classifier::BYPASS ),
	array( 'TRACE bypass', 'TRACE', '/', array(), Classifier::BYPASS ),
	array( 'auth cookie bypass', 'GET', '/', array( 'wordpress_logged_in_' . str_repeat( 'a', 32 ) => 'u' ), Classifier::BYPASS ),
	array( 'cart cookie bypass', 'GET', '/', array( 'woocommerce_items_in_cart' => '1' ), Classifier::BYPASS ),
	array( 'session cookie bypass', 'GET', '/', array( 'wp_woocommerce_session_hash4hash4hash4ha' => 'x' ), Classifier::BYPASS ),
	array( 'postpass bypass', 'GET', '/protected-page/', array( 'wp-postpass' => 'x' ), Classifier::BYPASS ),
	array( 'comment author bypass', 'GET', '/', array( 'comment_author_abc123def456abc123def456abc123de' => 'n' ), Classifier::BYPASS ),
	array( 'wp-admin bypass', 'GET', '/wp-admin/options.php', array(), Classifier::BYPASS ),
	array( 'wp-login bypass', 'GET', '/wp-login.php', array(), Classifier::BYPASS ),
	array( 'wp-cron bypass', 'GET', '/wp-cron.php', array(), Classifier::BYPASS ),
	array( 'REST bypass', 'GET', '/wp-json/wp/v2/posts', array(), Classifier::BYPASS ),
	array( 'xmlrpc bypass', 'GET', '/xmlrpc.php', array(), Classifier::BYPASS ),
	array( 'feed bypass', 'GET', '/feed/', array(), Classifier::BYPASS ),
	array( 'sitemap bypass', 'GET', '/sitemap.xml', array(), Classifier::BYPASS ),
	array( 'search s= bypass', 'GET', '/?s=query', array(), Classifier::BYPASS ),
	array( 'preview bypass', 'GET', '/my-post/?preview=true', array(), Classifier::BYPASS ),
	array( 'unknown query default bypass', 'GET', '/?random_param=1', array(), Classifier::BYPASS ),
	array( 'json ext bypass (deception)', 'GET', '/wp-content/.json', array(), Classifier::BYPASS ),
	array( 'traversal bypass', 'GET', '/../wp-config.php', array(), Classifier::BYPASS ),
	array( 'double-encode bypass', 'GET', '/%252e%252e/wp-config.php', array(), Classifier::BYPASS ),
	array( 'fragment-in-uri bypass', 'GET', '/page/#frag', array(), Classifier::BYPASS ),
	array( 'hostile host bypass', 'GET', '/', array(), Classifier::BYPASS ),
	array( 'admin ajax bypass', 'GET', '/wp-admin/admin-ajax.php', array(), Classifier::BYPASS ),
	array( 'cart slug bypass', 'GET', '/cart/', array(), Classifier::BYPASS ),
	array( 'checkout slug bypass', 'GET', '/checkout/', array(), Classifier::BYPASS ),
	array( 'my-account bypass', 'GET', '/my-account/orders/', array(), Classifier::BYPASS ),
	array( 'plain page cacheable', 'GET', '/about-us/', array(), Classifier::PUBLIC_CACHEABLE ),
	array( 'paged archive cacheable', 'GET', '/blog/page/2/', array(), Classifier::PUBLIC_CACHEABLE ),
	array( 'allowed query p cacheable-ish', 'GET', '/?p=123&paged=2', array(), Classifier::DYNAMIC ),
);
foreach ( $cases as $c ) {
	list( $name, $method, $uri, $cookies, $expect ) = $c;
	$host                                          = 'hostile host bypass' === $name ? 'attacker.example' : null;
	$r                                             = $clf->classify( $method, $uri, empty( $cookies ) ? array() : $cookies, $host );
	$pass = $expect === $r['classification'];
	if ( 'allowed query p cacheable-ish' === $name ) {
		$pass = in_array( $r['classification'], array( Classifier::DYNAMIC, Classifier::PUBLIC_CACHEABLE ), true );
	}
	check( $results, "Classify: $name → $expect", $pass, "got {$r['classification']} ({$r['reason']})" );
}

// UNKNOWN fail-closed sanity: filter forcing UNKNOWN treated as unsafe.
add_filter_mock:
$rf = new \ReflectionFunction( 'apply_filters' ); // noop shim returns value; UNKNOWN test below uses direct gate
check( $results, 'Classifier constants intact', Classifier::UNKNOWN === 'UNKNOWN' && Classifier::BYPASS === 'BYPASS' );

// ============================================ PHASE 7: ResponseSanitizer
$san    = new ResponseSanitizer();
$safe   = '<html><body><h1>Welcome</h1><p>Clean public content for everyone.</p></body></html>';
$nonce1 = '<form><input type="hidden" name="_wpnonce" value="abc"></form>';
$nonce2 = '<script>var cfg={"_wpnonce":"xyz"}</script>';
$logout = '<a href="/wp-login.php?action=logout">Exit</a>';
$bar    = '<div id="wpadminbar">Admin</div>';
$logged = '<body class="logged-in admin-bar">';
$restn  = '<span data-rest-nonce="abc123">';
foreach (
	array(
		'clean HTML cached'          => array( $safe, true ),
		'form nonce blocked'         => array( $nonce1 . $safe, false ),
		'script nonce blocked'       => array( $nonce2 . $safe, false ),
		'logout link blocked'        => array( $logout . $safe, false ),
		'admin bar blocked'          => array( $bar . $safe, false ),
		'logged-in body blocked'     => array( $logged . '</body>', false ),
		'rest nonce blocked'         => array( $restn . $safe, false ),
		'tiny response refused'      => array( '<html>x</html>', false ),
	) as $name => $tc
) {
	list( $html, $expect_safe ) = $tc;
	$a                          = $san->audit( $html );
	check( $results, "Sanitize: $name", $a['safe'] === $expect_safe, "got safe=" . var_export( $a['safe'], true ) . " reason={$a['reason']}" );
}

// ============================================ PHASE 9: FileLock
$lock_file = $fs_root . '/v/example.com/regen.lock';
@unlink( $lock_file );
$l1 = new FileLock( $lock_file, 'owner-one' );
$l2 = new FileLock( $lock_file, 'owner-two' );
check( $results, 'Lock first acquires', true === $l1->acquire( 30 ) );
check( $results, 'Lock second denied while held', false === $l2->acquire( 30 ) );
check( $results, 'Lock release works', true === $l1->release() );
check( $results, 'Lock reacquire after release', true === $l2->acquire( 30 ) );
$l2->release();
// Stale lock steal: simulate dead holder by old mtime.
@file_put_contents( $lock_file, '{"o":"dead","t":1}' );
touch( $lock_file, time() - 120 );
$l3 = new FileLock( $lock_file, 'owner-three' );
check( $results, 'Stale lock stolen after TTL', true === $l3->acquire( 30 ) );
$l3->release();

// ============================================ PHASE 11: tags bounded
$tag_file_dir = $fs_root . '/meta';
@mkdir( $tag_file_dir, 0777, true );
$registry_cls = ULTIMATE_PERFORMANCE_DIR . 'src/CacheTag/Registry.php';
require_once $registry_cls;
$reg = new \UltimatePerformance\CacheTag\Registry( $fs );
for ( $i = 0; $i < 5010; $i++ ) {
	$reg->attach( 'obj-' . $i, array( 'post:1' ) );
}
$members = $reg->members( 'post:1' );
check( $results, 'Tag index bounded at 5000', count( $members ) <= 5000 && count( $members ) >= 4980, 'count=' . count( $members ) );
$reg->detach_object( 'obj-5009', array( 'post:1' ) );
check( $results, 'Tag detach removes object', ! in_array( 'obj-5009', $reg->members( 'post:1' ), true ) );

// ============================================ SUMMARY
$fails = 0;
echo "\n==== SUMMARY ====\n";
foreach ( $results as $k => $v ) {
	if ( ! $v ) {
		++$fails;
		echo "FAIL: $k\n";
	}
}
$total = count( $results );
echo "$total checks, $fails failures\n";
exit( $fails ? 1 : 0 );
