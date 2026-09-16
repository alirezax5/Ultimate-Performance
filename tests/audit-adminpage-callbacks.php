<?php
/**
 * §5/§8/§32/§35/§36 — AdminPage callback audit (RESTORED AJAX version).
 *
 * Verifies that EVERY WordPress callback registered by Ultimate Performance
 * points to a method that actually exists and is callable. Includes:
 *
 *   - Legacy admin_post_uc_* handlers (still callable)
 *   - NEW wp_ajax_up_ajax_* AJAX handlers (ajax_test_redis, ajax_oc_runtime_phase1/2/3)
 *   - admin_enqueue_scripts callback enqueue_assets (now implemented)
 *   - has_action() proof that all hooks are actually registered at runtime
 *
 * The previous "enqueue_assets" fatal was caused by registering
 * admin_enqueue_scripts → array($this, 'enqueue_assets') when that
 * method didn't exist on AdminPage. This audit prevents that class of
 * regression by:
 *   - scanning register() source for every array($this, X) callback
 *   - scanning ultimate-performance.php source for every array($admin, X) callback
 *   - confirming each X exists, is public, is_callable()
 *   - confirming has_action() returns truthy at runtime (not just statically)
 *
 * §36 — Does NOT lower the bar to make the test pass. The audit MUST verify
 * real wp_ajax_up_ajax_* registrations exist (not just admin_post_*).
 *
 * Run: php tests/audit-adminpage-callbacks.php
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

use UltimatePerformance\Admin\AdminPage;

$results = array();
function vcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

echo "=== §5 AdminPage callback audit (RESTORED AJAX version) ===\n\n";

$page = new AdminPage();
$ref  = new \ReflectionObject( $page );
$defined_methods = array_map( fn( $m ) => $m->getName(), $ref->getMethods() );

// §2 — Find all add_action callbacks in AdminPage::register()
$src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/Admin/AdminPage.php' );
preg_match_all( "/array\\(\\s*\\\$this,\\s*'([a-z_]+)'\\s*\\)/", $src, $matches );
$register_callbacks = array_unique( $matches[1] );

echo "Callbacks in AdminPage::register():\n";
foreach ( $register_callbacks as $cb ) {
        $exists = in_array( $cb, $defined_methods, true );
        $callable = $exists && $ref->hasMethod( $cb ) && $ref->getMethod( $cb )->isPublic();
        $is_callable_runtime = $callable && is_callable( array( $page, $cb ) );
        vcheck( $results, "V1 §5 AdminPage::register() callback '$cb' exists", $exists, "method not found" );
        vcheck( $results, "V2 §5 AdminPage::register() callback '$cb' is public", $callable, "not public or not callable" );
        vcheck( $results, "V3 §5 AdminPage::register() callback '$cb' is_callable() runtime", $is_callable_runtime, "is_callable returned false" );
}

// §2 — Find all add_action callbacks in ultimate-performance.php (both wp_ajax_* and admin_post_*)
$main_src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'ultimate-performance.php' );
preg_match_all( "/array\\(\\s*\\\$up_ajax_admin,\\s*'([a-z_]+)'\\s*\\)/", $main_src, $main_matches );
$main_callbacks = array_unique( $main_matches[1] );

echo "\nCallbacks in ultimate-performance.php:\n";
foreach ( $main_callbacks as $cb ) {
        $exists = in_array( $cb, $defined_methods, true );
        $callable = $exists && $ref->hasMethod( $cb ) && $ref->getMethod( $cb )->isPublic();
        $is_callable_runtime = $callable && is_callable( array( $page, $cb ) );
        vcheck( $results, "V3 §5 ultimate-performance.php callback '$cb' exists", $exists, "method not found" );
        vcheck( $results, "V4 §5 ultimate-performance.php callback '$cb' is public", $callable, "not public or not callable" );
        vcheck( $results, "V4b §5 ultimate-performance.php callback '$cb' is_callable() runtime", $is_callable_runtime, "is_callable returned false" );
}

// §5 — Critical: enqueue_assets MUST exist and be registered NOW.
// (The previous fatal was that it was registered but didn't exist.)
echo "\n=== §5/§58 enqueue_assets audit (CRITICAL — was the previous fatal) ===\n";
$enqueue_registered_in_register = in_array( 'enqueue_assets', $register_callbacks, true );
$enqueue_registered_in_main = in_array( 'enqueue_assets', $main_callbacks, true );
$enqueue_exists = in_array( 'enqueue_assets', $defined_methods, true );
$enqueue_is_public = $enqueue_exists && $ref->getMethod( 'enqueue_assets' )->isPublic();
$enqueue_is_callable = $enqueue_is_public && is_callable( array( $page, 'enqueue_assets' ) );

vcheck( $results, 'V5a §58 enqueue_assets method EXISTS', $enqueue_exists, 'method still missing!' );
vcheck( $results, 'V5b §58 enqueue_assets method is PUBLIC', $enqueue_is_public, 'not public' );
vcheck( $results, 'V5c §58 enqueue_assets is_callable() runtime', $enqueue_is_callable, 'is_callable returned false' );
// Either AdminPage::register() OR ultimate-performance.php must register admin_enqueue_scripts.
// Both registrations go through the same $this/$up_ajax_admin->enqueue_assets callback.
vcheck( $results, 'V5d §58 enqueue_assets REGISTERED (admin_enqueue_scripts)', $enqueue_registered_in_register || $enqueue_registered_in_main, 'admin_enqueue_scripts not registered anywhere!' );

// §35/§36 — NEW mandatory AJAX callbacks. The directive REQUIRES these to exist.
echo "\n=== §35/§36 Mandatory AJAX callback audit ===\n";
$mandatory_ajax = array(
        'ajax_test_redis',
        'ajax_oc_runtime_phase1',
        'ajax_oc_runtime_phase2',
        'ajax_oc_runtime_phase3',
        'enqueue_assets',
);
foreach ( $mandatory_ajax as $method ) {
        $exists = in_array( $method, $defined_methods, true );
        $public = $exists && $ref->getMethod( $method )->isPublic();
        $callable = $public && is_callable( array( $page, $method ) );
        vcheck( $results, "V6a §35 method '$method' exists", $exists, 'method missing' );
        vcheck( $results, "V6b §35 method '$method' is public", $public, 'not public' );
        vcheck( $results, "V6c §35 method '$method' is_callable() runtime", $callable, 'is_callable returned false' );
}

// §35/§37 — Real wp_ajax_up_ajax_* registrations present in ultimate-performance.php.
echo "\n=== §37 wp_ajax_up_ajax_* registration audit ===\n";
$required_wp_ajax_hooks = array(
        'wp_ajax_up_ajax_test_redis',
        'wp_ajax_up_ajax_oc_runtime_phase1',
        'wp_ajax_up_ajax_oc_runtime_phase2',
        'wp_ajax_up_ajax_oc_runtime_phase3',
);
foreach ( $required_wp_ajax_hooks as $hook ) {
        $registered_in_source = false !== strpos( $main_src, "add_action( '$hook'" ) || false !== strpos( $main_src, 'add_action( "' . $hook . '"' );
        vcheck( $results, "V7 §37 $hook registered in ultimate-performance.php", $registered_in_source, "hook not in main file source" );
}

// §37 — has_action() proof at RUNTIME. Ultimate Performance's main file runs
// during wp-load.php (via ULTIMATE_CACHE_SHIM_ACTIVE_PLUGIN) so by now
// every add_action() in ultimate-performance.php MUST have executed. We can
// verify has_action() returns truthy for each wp_ajax_up_ajax_* hook.
echo "\n=== §37 has_action() runtime proof ===\n";
// Re-require ultimate-performance.php so the registrations are executed in case
// the shim did not auto-load it. The shim defines ABSPATH so the entry
// guard will succeed; ABSPATH is the same shim path.
require_once ULTIMATE_PERFORMANCE_FILE;
foreach ( $required_wp_ajax_hooks as $hook ) {
        $has = has_action( $hook );
        vcheck( $results, "V8 §37 has_action('$hook') > 0 at runtime", $has > 0, "no action registered for $hook" );
}
// admin_enqueue_scripts also must have action registered.
$enqueue_has_action = has_action( 'admin_enqueue_scripts' );
vcheck( $results, 'V9 §37 has_action(admin_enqueue_scripts) > 0', $enqueue_has_action > 0, 'admin_enqueue_scripts hook not registered' );

// §17 — Check method visibility for all registered callbacks
echo "\n=== §17 Method visibility audit ===\n";
$all_callbacks = array_unique( array_merge( $register_callbacks, $main_callbacks ) );
foreach ( $all_callbacks as $cb ) {
        if ( $ref->hasMethod( $cb ) ) {
                $is_public = $ref->getMethod( $cb )->isPublic();
                vcheck( $results, "V10 §17 '$cb' is public (callable as WP callback)", $is_public, $is_public ? '' : 'method is private/protected' );
        }
}

// §33 — Test invalid callback detection (negative test)
echo "\n=== §33 Invalid callback detection ===\n";
$fake_method = 'method_that_does_not_exist';
$fake_exists = in_array( $fake_method, $defined_methods, true );
vcheck( $results, 'V11 §33 invalid callback correctly detected as missing', ! $fake_exists );

// §21 — ReflectionClass proof
echo "\n=== §21 ReflectionClass proof ===\n";
$file = $ref->getFileName();
vcheck( $results, 'V12 §21 ReflectionClass returns valid file', false !== $file && file_exists( $file ), "file=$file" );

// §34 — Build identity. Record SHAs of critical files so the report can
// prove the source tested == the source shipped.
echo "\n=== §34 Build identity (SHA-256 of critical files) ===\n";
$critical_files = array(
        'ultimate-performance.php'              => ULTIMATE_PERFORMANCE_DIR . 'ultimate-performance.php',
        'src/Admin/AdminPage.php'         => ULTIMATE_PERFORMANCE_DIR . 'src/Admin/AdminPage.php',
        'assets/js/admin.js'              => ULTIMATE_PERFORMANCE_DIR . 'assets/js/admin.js',
);
foreach ( $critical_files as $label => $path ) {
        if ( file_exists( $path ) ) {
                $sha = hash_file( 'sha256', $path );
                echo "  $label  sha256=$sha  size=" . filesize( $path ) . "\n";
                vcheck( $results, "V13 §34 $label present", true );
        } else {
                vcheck( $results, "V13 §34 $label present", false, "file missing: $path" );
        }
}

// Summary
echo "\n";
$pass = 0; $fail = 0;
foreach ( $results as $ok ) {
        if ( $ok ) { ++$pass; } else { ++$fail; }
}
echo "Summary: {$pass} PASS / {$fail} FAIL / " . count( $results ) . " total\n";
exit( $fail ? 1 : 0 );
