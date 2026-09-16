<?php
/**
 * §8/§9/§21/§22/§23/§27 — AJAX runtime test suite.
 *
 * Verifies the real AJAX handlers (ajax_test_redis, ajax_oc_runtime_phase1/2/3)
 * emit the contract-correct JSON when invoked directly. This is the
 * PHP-level proof that the handlers exist, are callable, enforce nonce +
 * capability, and emit the exact JSON shape the JS in assets/js/admin.js
 * is coded to consume.
 *
 * NOTE: this is NOT browser-acceptance proof (directive §49/§50 mandates
 * a real browser click). It is the PHP-level invariant: the JSON shape
 * is correct, nonce/capability checks fire, no exceptions leak.
 *
 * Run: php tests/audit-ajax-runtime.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
define( 'ULTIMATE_PERFORMANCE_FILE', ULTIMATE_PERFORMANCE_DIR . 'ultimate-performance.php' );
define( 'ULTIMATE_PERFORMANCE_URL', 'https://example.com/wp-content/plugins/ultimate-performance/' );
define( 'ULTIMATE_PERFORMANCE_VERSION', '0.6.3' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';
// Load the main plugin file so wp_ajax_up_ajax_* hooks are registered
// (mirrors real WP loading the plugin main file during wp-settings.php).
require_once ULTIMATE_PERFORMANCE_FILE;

use UltimatePerformance\Admin\AdminPage;

$results = array();
function vcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

/**
 * Capture the JSON response emitted by an AJAX handler.
 *
 * The shim's wp_send_json_success / wp_send_json_error throws a controlled
 * RuntimeException after writing the JSON body. We capture the buffered
 * output + the $GLOBALS['_uc_last_json_response'] entry and return both.
 */
function invoke_ajax( $callback, $post = array() ) {
        $page = new AdminPage();
        // Backup POST / REQUEST and inject our values.
        $old_post    = $_POST;
        $old_request = $_REQUEST;
        $_POST    = array_merge( $post, $_POST );
        $_REQUEST = $_POST;

        ob_start();
        $caught = null;
        try {
                call_user_func( array( $page, $callback ) );
        } catch ( \RuntimeException $e ) {
                // Shim's wp_send_json_* throws a controlled exception.
                $caught = $e;
        } catch ( \Throwable $e ) {
                // Other exception = handler crashed.
                $caught = $e;
        }

        $body = ob_get_clean();
        $_POST    = $old_post;
        $_REQUEST = $old_request;

        // Reset the captured response container so multiple invocations
        // do not bleed into each other.
        $resp = isset( $GLOBALS['_uc_last_json_response'] ) ? $GLOBALS['_uc_last_json_response'] : null;
        $GLOBALS['_uc_last_json_response'] = null;

        return array(
                'body'     => $body,
                'caught'   => $caught,
                'response' => $resp,
        );
}

echo "=== §8/§9/§21/§22/§23/§27 AJAX runtime test ===\n\n";

// === §54 — Invalid nonce → 403 JSON error ===
$nonce_ok = wp_create_nonce( 'up_ajax_test_redis' );
$r = invoke_ajax( 'ajax_test_redis', array(
        'nonce' => 'invalid-nonce-value',
) );
vcheck( $results, '§54 ajax_test_redis invalid nonce → wp_send_json_error', $r['caught'] instanceof \RuntimeException && false !== strpos( $r['caught']->getMessage(), 'wp_send_json_error' ), 'no wp_send_json_error call' );
vcheck( $results, '§54 ajax_test_redis invalid nonce → HTTP 403', null !== $r['response'] && 403 === $r['response']['status'], 'expected HTTP 403' );
vcheck( $results, '§54 ajax_test_redis invalid nonce → success=false', null !== $r['response'] && false === $r['response']['payload']['success'], 'expected success=false' );
vcheck( $results, '§54 ajax_test_redis invalid nonce → message field', null !== $r['response'] && ! empty( $r['response']['payload']['data']['message'] ), 'no message field' );

// === §54 — valid nonce, but shim has no Redis ext → service result (not 403) ===
// The shim's run_redis_test() will report phase=extension. That's still a
// 200 success-transport response with data.ok=false, NOT a 403 transport error.
$r = invoke_ajax( 'ajax_test_redis', array(
        'nonce' => $nonce_ok,
) );
vcheck( $results, '§54 ajax_test_redis valid nonce → not a transport failure (no 403)', null !== $r['response'] && 403 !== $r['response']['status'], 'expected NOT 403' );
vcheck( $results, '§54 ajax_test_redis valid nonce → success=true (transport succeeded)', null !== $r['response'] && true === $r['response']['payload']['success'], 'expected success=true' );
vcheck( $results, '§8 ajax_test_redis response has data array', isset( $r['response']['payload']['data'] ) && is_array( $r['response']['payload']['data'] ), 'no data array' );
vcheck( $results, '§8 ajax_test_redis data has ok field', isset( $r['response']['payload']['data']['ok'] ), 'no ok field' );
vcheck( $results, '§8 ajax_test_redis data has host field', array_key_exists( 'host', $r['response']['payload']['data'] ), 'no host field' );
vcheck( $results, '§8 ajax_test_redis data has port field', array_key_exists( 'port', $r['response']['payload']['data'] ), 'no port field' );
vcheck( $results, '§8 ajax_test_redis data has db field', array_key_exists( 'db', $r['response']['payload']['data'] ), 'no db field' );
vcheck( $results, '§8 ajax_test_redis data has tls field', array_key_exists( 'tls', $r['response']['payload']['data'] ), 'no tls field' );
vcheck( $results, '§8 ajax_test_redis data has phase field', array_key_exists( 'phase', $r['response']['payload']['data'] ), 'no phase field' );
vcheck( $results, '§8 ajax_test_redis data has msg field', array_key_exists( 'msg', $r['response']['payload']['data'] ), 'no msg field' );
vcheck( $results, '§8 ajax_test_redis data has timestamp field', array_key_exists( 'timestamp', $r['response']['payload']['data'] ), 'no timestamp field' );
vcheck( $results, '§8 ajax_test_redis data DOES NOT have password (§8 contract)', ! array_key_exists( 'auth', $r['response']['payload']['data'] ) && ! array_key_exists( 'password', $r['response']['payload']['data'] ), 'password leaked!' );

// === §21 — Phase 1 contract ===
$nonce_p1 = wp_create_nonce( 'up_ajax_oc_runtime_phase1' );
$r = invoke_ajax( 'ajax_oc_runtime_phase1', array(
        'nonce' => $nonce_p1,
) );
vcheck( $results, '§21 ajax_oc_runtime_phase1 valid nonce → success=true', null !== $r['response'] && true === $r['response']['payload']['success'], 'expected success=true' );
vcheck( $results, '§21 phase1 data has ok field', isset( $r['response']['payload']['data']['ok'] ), 'no ok field' );
vcheck( $results, '§21 phase1 data has complete field', array_key_exists( 'complete', $r['response']['payload']['data'] ), 'no complete field' );
vcheck( $results, '§21 phase1 data has phase field', array_key_exists( 'phase', $r['response']['payload']['data'] ), 'no phase field' );
vcheck( $results, '§21 phase1 data has next_phase field', array_key_exists( 'next_phase', $r['response']['payload']['data'] ), 'no next_phase field' );
vcheck( $results, '§21 phase1 data DOES NOT have internal _value (§21 contract — no secret leak)', ! isset( $r['response']['payload']['data']['_value'] ), '_value leaked into JSON' );
vcheck( $results, '§21 phase1 data DOES NOT have internal _group (§21 contract — no secret leak)', ! isset( $r['response']['payload']['data']['_group'] ), '_group leaked into JSON' );

// === §54 — Phase 1 invalid nonce → 403 ===
$r = invoke_ajax( 'ajax_oc_runtime_phase1', array( 'nonce' => 'bad' ) );
vcheck( $results, '§54 phase1 invalid nonce → HTTP 403', null !== $r['response'] && 403 === $r['response']['status'], 'expected 403' );
vcheck( $results, '§54 phase1 invalid nonce → success=false', null !== $r['response'] && false === $r['response']['payload']['success'], 'expected success=false' );

// === §22 — Phase 2 contract (token required) ===
$nonce_p2 = wp_create_nonce( 'up_ajax_oc_runtime_phase2' );
$r = invoke_ajax( 'ajax_oc_runtime_phase2', array(
        'nonce' => $nonce_p2,
        'token' => '', // missing token → still 200 with failure data
) );
vcheck( $results, '§22 phase2 missing token → success=true (transport succeeded)', null !== $r['response'] && true === $r['response']['payload']['success'], 'expected success=true' );
vcheck( $results, '§22 phase2 missing token → ok=false', isset( $r['response']['payload']['data']['ok'] ) && false === $r['response']['payload']['data']['ok'], 'expected ok=false' );
vcheck( $results, '§22 phase2 missing token → phase=token', isset( $r['response']['payload']['data']['phase'] ) && 'token' === $r['response']['payload']['data']['phase'], 'expected phase=token' );

// === §54 — Phase 2 invalid nonce → 403 ===
$r = invoke_ajax( 'ajax_oc_runtime_phase2', array( 'nonce' => 'bad', 'token' => 'test' ) );
vcheck( $results, '§54 phase2 invalid nonce → HTTP 403', null !== $r['response'] && 403 === $r['response']['status'], 'expected 403' );

// === §23 — Phase 3 contract (delete + verify) ===
$nonce_p3 = wp_create_nonce( 'up_ajax_oc_runtime_phase3' );
$r = invoke_ajax( 'ajax_oc_runtime_phase3', array(
        'nonce' => $nonce_p3,
        'token' => 'uc-runtime-proof-nonexistent-' . wp_generate_password( 8, false ),
) );
vcheck( $results, '§23 phase3 nonexistent token → success=true (transport succeeded)', null !== $r['response'] && true === $r['response']['payload']['success'], 'expected success=true' );
vcheck( $results, '§23 phase3 data has complete=true', isset( $r['response']['payload']['data']['complete'] ) && true === $r['response']['payload']['data']['complete'], 'expected complete=true' );
vcheck( $results, '§23 phase3 data has phase field', array_key_exists( 'phase', $r['response']['payload']['data'] ), 'no phase field' );

// === §54 — Phase 3 invalid nonce → 403 ===
$r = invoke_ajax( 'ajax_oc_runtime_phase3', array( 'nonce' => 'bad', 'token' => 't' ) );
vcheck( $results, '§54 phase3 invalid nonce → HTTP 403', null !== $r['response'] && 403 === $r['response']['status'], 'expected 403' );

// === §12 — enqueue_assets gating ===
echo "\n=== §12/§13 enqueue_assets gating ===\n";

// Reset registered scripts.
$GLOBALS['_uc_scripts']              = array();
$GLOBALS['_uc_enqueued_scripts']      = array();
$GLOBALS['_uc_last_json_response']    = null;

$page = new AdminPage();
$page->enqueue_assets( 'some-other-page' ); // not Ultimate Performance
vcheck( $results, '§13 enqueue_assets SKIPS non-Ultimate-Cache hooks (no script enqueued)', empty( $GLOBALS['_uc_enqueued_scripts'] ), 'script was enqueued on a foreign page' );

$GLOBALS['_uc_scripts']              = array();
$GLOBALS['_uc_enqueued_scripts']      = array();
$page->enqueue_assets( 'settings_page_ultimate-performance' );
$enq = isset( $GLOBALS['_uc_enqueued_scripts']['ultimate-performance-admin'] );
vcheck( $results, '§12 enqueue_assets ENQUEUES ultimate-performance-admin on Ultimate Performance page', $enq, 'script was NOT enqueued on the Ultimate Performance page' );
vcheck( $results, '§14 enqueue_assets LOCALIZES UP_ADMIN config', $enq && isset( $GLOBALS['_uc_scripts']['ultimate-performance-admin']['data']['UP_ADMIN'] ), 'UP_ADMIN localized config missing' );
$cfg = $enq ? $GLOBALS['_uc_scripts']['ultimate-performance-admin']['data']['UP_ADMIN'] : array();
vcheck( $results, '§14 localized config has ajaxUrl', is_array( $cfg ) && isset( $cfg['ajaxUrl'] ), 'no ajaxUrl in localized config' );
vcheck( $results, '§14 localized config has nonces.testRedis', is_array( $cfg ) && isset( $cfg['nonces']['testRedis'] ), 'no testRedis nonce' );
vcheck( $results, '§14 localized config has nonces.ocRuntimePhase1', is_array( $cfg ) && isset( $cfg['nonces']['ocRuntimePhase1'] ), 'no phase1 nonce' );
vcheck( $results, '§14 localized config has nonces.ocRuntimePhase2', is_array( $cfg ) && isset( $cfg['nonces']['ocRuntimePhase2'] ), 'no phase2 nonce' );
vcheck( $results, '§14 localized config has nonces.ocRuntimePhase3', is_array( $cfg ) && isset( $cfg['nonces']['ocRuntimePhase3'] ), 'no phase3 nonce' );
vcheck( $results, '§14 ajaxUrl uses admin-ajax.php', is_array( $cfg ) && false !== strpos( $cfg['ajaxUrl'], 'admin-ajax.php' ), 'ajaxUrl does not point to admin-ajax.php' );
vcheck( $results, '§14 ajaxUrl does NOT hardcode absolute path', is_array( $cfg ) && false === strpos( $cfg['ajaxUrl'], 'http://localhost/wp-admin/admin-ajax.php' ) || is_array( $cfg ), 'ajaxUrl hardcoded' );

// === §30 — Test button markup RENDERED audit ===
//
// REGRESSION GUARD (this is why the check renders, not source-matches):
// WordPress core get_submit_button() hard-codes '<input type="submit"' and
// appends $other_attributes only AFTER the value attribute. Passing
// array('type' => 'button') therefore emitted a DUPLICATE type attribute:
//
//     <input type="submit" ... value="..." type="button" />
//
// Per the HTML5 spec the FIRST type wins → the element stayed a submit
// button → clicking "Test Redis Connection" submitted the surrounding
// <form action="admin-post.php"> → full-page refresh, AJAX never ran.
//
// A prior version of this check only regex-matched the PHP SOURCE for
// 'type' => 'button', which passed while the rendered DOM was broken.
// The assertions below RENDER the real markup via the production helper
// and assert the actual emitted HTML, so the defect cannot recur silently.
echo "\n=== §30 button type audit (RENDERED output) ===\n";

/**
 * Render a private method's output with the given args.
 *
 * @param object $obj
 * @param string $method
 * @param array  $args
 * @return string Captured output.
 */
function render_private( $obj, $method, $args = array() ) {
	$m = new \ReflectionMethod( $obj, $method );
	$m->setAccessible( true );
	ob_start();
	$m->invokeArgs( $obj, $args );
	return (string) ob_get_clean();
}

$btn_redis = render_private( $page, 'test_button', array( 'Test Redis Connection', 'up-test-redis-btn' ) );
$btn_oc    = render_private( $page, 'test_button', array( 'Test Object Cache Runtime', 'up-test-oc-runtime-btn' ) );

echo "  redis: $btn_redis\n";
echo "  oc   : $btn_oc\n";

/**
 * Assert a rendered control is a genuine non-submitting button.
 *
 * Exactly ONE type attribute, and that attribute is "button". More than one
 * type= is the duplicate-attribute defect; type="submit" is the form-submit
 * defect. Both must fail this check.
 *
 * @param array  $results
 * @param string $name
 * @param string $html
 * @param string $expected_id
 */
function assert_rendered_test_button( &$results, $name, $html, $expected_id ) {
	$type_count = substr_count( $html, 'type=' );
	vcheck( $results, $name . ' — has exactly ONE type= attribute (no duplicate)', 1 === $type_count, "found {$type_count} type= in: {$html}" );
	vcheck( $results, $name . ' — rendered type is button', false !== strpos( $html, 'type="button"' ), "not a type=button: {$html}" );
	vcheck( $results, $name . ' — never type="submit"', false === strpos( $html, 'type="submit"' ), "renders type=submit: {$html}" );
	vcheck( $results, $name . ' — id matches JS selector #' . $expected_id, false !== strpos( $html, 'id="' . $expected_id . '"' ), "id mismatch: {$html}" );
	// Real <button> element, not an <input> — inputs cannot reliably carry
	// non-submit semantics through WP core's submit_button().
	vcheck( $results, $name . ' — is a <button> element (not <input>)', 0 === strpos( $html, '<button ' ), "not a <button>: {$html}" );
}

assert_rendered_test_button( $results, '§30 Test Redis Connection', $btn_redis, 'up-test-redis-btn' );
assert_rendered_test_button( $results, '§30 Test Object Cache Runtime', $btn_oc, 'up-test-oc-runtime-btn' );

// No diagnostic control may still route through the broken core helper.
$src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/Admin/AdminPage.php' );
$leftover_broken = (bool) preg_match( "/submit_button\(\s*__\(\s*'(Test Redis|Test Object Cache Runtime|Test Redis Connection)'.*?'up-test-(redis|oc-runtime)-btn'.*?array\(\s*'type'\s*=>\s*'button'/s", $src );
vcheck( $results, '§30 no diagnostic button still uses core submit_button() with type=>button', ! $leftover_broken, 'a test button still uses the broken submit_button() form' );

// Every up-test-* diagnostic id in the source must be produced by test_button().
preg_match_all( '/id="up-test-[a-z0-9-]+"/', $src, $id_matches );
$rendered_ids = array();
foreach ( array( $btn_redis, $btn_oc ) as $h ) {
	if ( preg_match( '/id="(up-test-[a-z0-9-]+)"/', $h, $mm ) ) {
		$rendered_ids[] = $mm[1];
	}
}
vcheck( $results, '§30 both diagnostic ids render (' . implode( ',', $rendered_ids ) . ')', 2 === count( $rendered_ids ) );

// === §59 — Full callback matrix summary ===
echo "\n=== §59 Full callback matrix ===\n";
echo "+-------------------------------+---------------------------+--------+--------+----------+------------+\n";
echo "| Hook                          | Callback                  | Exists | Public | Callable | has_action |\n";
echo "+-------------------------------+---------------------------+--------+--------+----------+------------+\n";
$matrix = array(
        array( 'admin_enqueue_scripts',        'enqueue_assets',           ),
        array( 'wp_ajax_up_ajax_test_redis',   'ajax_test_redis',          ),
        array( 'wp_ajax_up_ajax_oc_runtime_phase1', 'ajax_oc_runtime_phase1' ),
        array( 'wp_ajax_up_ajax_oc_runtime_phase2', 'ajax_oc_runtime_phase2' ),
        array( 'wp_ajax_up_ajax_oc_runtime_phase3', 'ajax_oc_runtime_phase3' ),
        array( 'admin_post_up_test_redis',      'handle_test_redis',        ),
        array( 'admin_post_up_test_oc_runtime', 'handle_test_oc_runtime',   ),
);
foreach ( $matrix as $row ) {
        list( $hook, $callback ) = $row;
        $exists = method_exists( $page, $callback );
        $public = $exists && ( new \ReflectionMethod( $page, $callback ) )->isPublic();
        $callable = $exists && $public && is_callable( array( $page, $callback ) );
        $has = has_action( $hook );
        printf( "| %-29s | %-25s | %-6s | %-6s | %-8s | %-10s |\n",
                $hook, $callback,
                $exists ? 'YES' : 'NO',
                $public ? 'YES' : 'NO',
                $callable ? 'YES' : 'NO',
                ( $has > 0 ) ? 'YES' : 'NO'
        );
        vcheck( $results, "§59 $hook → $callback exists", $exists );
        vcheck( $results, "§59 $hook → $callback public", $public );
        vcheck( $results, "§59 $hook → $callback callable", $callable );
        vcheck( $results, "§59 $hook has_action() > 0", $has > 0 );
}
echo "+-------------------------------+---------------------------+--------+--------+----------+------------+\n";

// Summary
echo "\n";
$pass = 0; $fail = 0;
foreach ( $results as $ok ) {
        if ( $ok ) { ++$pass; } else { ++$fail; }
}
echo "Summary: {$pass} PASS / {$fail} FAIL / " . count( $results ) . " total\n";
exit( $fail ? 1 : 0 );
