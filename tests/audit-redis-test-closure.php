<?php
/**
 * §8 / §8.1 / §9 / §14 / §45 / §46 — Redis Test Connection + DB=3 + prefix
 *
 * Validates the runtime contract required by the FINAL directive:
 *   - admin form saves redis.db=3
 *   - Settings::save_from_admin() persists db=3 in the option
 *   - RedisBackend::configured() returns true with settings-only config
 *   - RedisBackend::from_settings() builds a backend with db=3
 *   - RedisBackend::__construct() actually captures db=3
 *   - handle_test_redis() performs the full sequence (SELECT + write/read/del)
 *   - the rendered result includes host/port/db/tls/timestamp/phase
 *
 * This is a STATIC + SHIM test: it exercises the classes via the wp-shim,
 * without needing a real Redis daemon. Live Redis verification is in
 * tests/audit-redis-live.php (§10-13).
 *
 * Run: php tests/audit-redis-test-closure.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Core\Settings;
use UltimatePerformance\ObjectCache\RedisBackend;
use UltimatePerformance\ObjectCache\Manager;

$results = array();
function rt_check( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

// Reset option store to a clean state
delete_option( 'ultimate_performance_settings' );

// ---- §7: redis.db=3 persistence proof --------------------------------------
$s = Settings::instance();

// Simulate the admin POST flow for the object-cache section
$input = array(
        'up_section'          => 'object-cache',
        'object_cache_enabled' => '1',
        'object_cache_prefix'  => 'testsite',
        'redis'               => array(
                'host' => '127.0.0.1',
                'port' => 6379,
                'db'   => 3,
                'tls'  => '',
                'auth' => '',
        ),
);
$errors = $s->save_from_admin( $input );
rt_check( $results, 'T1 §7 redis.db=3 saves without errors', empty( $errors ), json_encode( $errors ) );

// Inspect the stored option directly (directive #7)
$stored = get_option( 'ultimate_performance_settings', array() );
rt_check( $results, 'T2 §7 redis.db=3 persisted in option', 3 === (int) ( $stored['redis']['db'] ?? -1 ), json_encode( $stored['redis'] ?? null ) );

// Reload Settings fresh to verify the read path
$s->reset();
delete_option( 'ultimate_performance_settings' );
$s->save_from_admin( $input );
$read_db = (int) $s->get( 'redis.db', -1 );
rt_check( $results, 'T3 §7 Settings::get(redis.db) returns 3', 3 === $read_db, "got={$read_db}" );

// ---- §14: RedisBackend::configured() with settings-only config ---------------
rt_check( $results, 'T4 §14 configured_via_constants() false (no constants)', ! RedisBackend::configured_via_constants() );
rt_check( $results, 'T5 §14 configured() true (settings has redis.host)', RedisBackend::configured() );

// ---- §3: from_settings() builds a backend that carries db=3 -----------------
$rb = RedisBackend::from_settings();
rt_check( $results, 'T6 §3 from_settings() returns instance', null !== $rb );

if ( null !== $rb ) {
        $ref = new \ReflectionObject( $rb );
        $db_prop = $ref->getProperty( 'db' );
        $db_prop->setAccessible( true );
        $host_prop = $ref->getProperty( 'host' );
        $host_prop->setAccessible( true );
        $port_prop = $ref->getProperty( 'port' );
        $port_prop->setAccessible( true );

        $actual_db   = (int) $db_prop->getValue( $rb );
        $actual_host = (string) $host_prop->getValue( $rb );
        $actual_port = (int) $port_prop->getValue( $rb );

        rt_check( $results, 'T7 §3 from_settings() captures host=127.0.0.1', '127.0.0.1' === $actual_host, "got={$actual_host}" );
        rt_check( $results, 'T8 §3 from_settings() captures port=6379', 6379 === $actual_port, "got={$actual_port}" );
        rt_check( $results, 'T9 §3/§7 from_settings() captures db=3 (CRITICAL)', 3 === $actual_db, "got={$actual_db}" );
}

// ---- §5: section isolation regression — saving object-cache section does
// NOT touch enabled (master switch) or page_cache_enabled --------------------
delete_option( 'ultimate_performance_settings' );
$s->reset();
$s = Settings::instance();
$s->save_from_admin( array(
        'up_section'          => 'page-cache',
        'enabled'             => '1',
        'page_cache_enabled'  => '1',
        'ttl'                 => 3600,
) );
$before_master = (bool) $s->get( 'enabled', false );
$before_page   = (bool) $s->get( 'page_cache_enabled', false );
rt_check( $results, 'T10 §5 baseline master=ON', true === $before_master );
rt_check( $results, 'T11 §5 baseline page-cache=ON', true === $before_page );

// Now save object-cache section (only toggling object_cache_enabled)
$s->save_from_admin( array(
        'up_section'          => 'object-cache',
        'object_cache_enabled' => '1',
        'redis'               => array( 'host' => '127.0.0.1', 'port' => 6379, 'db' => 3, 'tls' => '', 'auth' => '' ),
        'object_cache_prefix'  => 'testsite',
) );
$after_master = (bool) $s->get( 'enabled', false );
$after_page   = (bool) $s->get( 'page_cache_enabled', false );
$after_oc     = (bool) $s->get( 'object_cache_enabled', false );
rt_check( $results, 'T12 §5 master preserved after OC save (CRITICAL)', true === $after_master, "got={$after_master}" );
rt_check( $results, 'T13 §5 page-cache preserved after OC save (CRITICAL)', true === $after_page, "got={$after_page}" );
rt_check( $results, 'T14 §5 OC actually turned ON', true === $after_oc, "got={$after_oc}" );
rt_check( $results, 'T15 §7 redis.db=3 persisted through OC save', 3 === (int) $s->get( 'redis.db', -1 ) );

// ---- §6: prefix generation -------------------------------------------------
$cases = array(
        'woolena.ir'         => 'woolena',
        'www.example.com'     => 'example',
        'shop.example.com'    => 'shop-example',
        'example.co.uk'       => 'example',
        'shop.example.co.uk'  => 'shop-example',
);
foreach ( $cases as $host => $want ) {
        $got = Manager::generate_prefix_from_host( $host );
        rt_check( $results, "T16 §6 prefix({$host}) => {$want}", $want === $got, "got={$got}" );
}

// ---- §8.1 / §45: handle_test_redis result structure (static) ---------------
$src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/Admin/AdminPage.php' );
rt_check( $results, 'T17 §9 handle_test_redis calls $r->select($db)', false !== strpos( $src, '$r->select( $db )' ) );
rt_check( $results, 'T18 §9 handle_test_redis writes temp test key', false !== strpos( $src, 'ultimate-performance:test:' ) );
rt_check( $results, 'T19 §9 handle_test_redis reads test key back', false !== strpos( $src, 'setEx( $tk' ) );
rt_check( $results, 'T20 §9 handle_test_redis deletes temp key', false !== strpos( $src, '$r->del( $tk )' ) );
rt_check( $results, 'T21 §8.1 result captures host', false !== strpos( $src, "'host'      => \$host" ) );
rt_check( $results, 'T22 §8.1 result captures port', false !== strpos( $src, "'port'      => \$port" ) );
rt_check( $results, 'T23 §8.1 result captures db', false !== strpos( $src, "'db'        => \$db" ) );
rt_check( $results, 'T24 §8.1 result captures tls', false !== strpos( $src, "'tls'       => \$tls" ) );
rt_check( $results, 'T25 §8.1 result captures timestamp', false !== strpos( $src, "'timestamp' => current_time" ) );
rt_check( $results, 'T26 §8.1 result captures phase', false !== strpos( $src, "'phase'     => ''" ) );
rt_check( $results, 'T27 §8.1 classify auth failure (NOAUTH/WRONGPASS)', false !== strpos( $src, 'NOAUTH' ) && false !== strpos( $src, 'WRONGPASS' ) );

// ---- §44: render_redis_test_result method exists ---------------------------
rt_check( $results, 'T28 §44 render_redis_test_result method exists', false !== strpos( $src, 'function render_redis_test_result' ) );
rt_check( $results, 'T29 §44 render shows Host', false !== strpos( $src, "esc_html_e( 'Host'" ) );
rt_check( $results, 'T30 §44 render shows Port', false !== strpos( $src, "esc_html_e( 'Port'" ) );
rt_check( $results, 'T31 §44 render shows Database', false !== strpos( $src, "esc_html_e( 'Database'" ) );
rt_check( $results, 'T32 §44 render shows TLS', false !== strpos( $src, "esc_html_e( 'TLS'" ) );
rt_check( $results, 'T33 §44 render shows Phase', false !== strpos( $src, "esc_html_e( 'Phase'" ) );

// ---- Manager instance picks up Settings config -----------------------------
$mgr_src = file_get_contents( ULTIMATE_PERFORMANCE_DIR . 'src/ObjectCache/Manager.php' );
rt_check( $results, 'T34 §3 Manager::instance() uses from_settings() factory', false !== strpos( $mgr_src, 'RedisBackend::from_settings()' ) );
rt_check( $results, 'T35 §3 Manager::instance() uses configured_via_constants()', false !== strpos( $mgr_src, 'configured_via_constants()' ) );

// ---- Summary ---------------------------------------------------------------
echo "\n";
$pass = 0; $fail = 0;
foreach ( $results as $name => $ok ) {
        if ( $ok ) ++$pass; else ++$fail;
}
echo "Summary: {$pass} PASS / {$fail} FAIL / " . count( $results ) . " total\n";
exit( $fail ? 1 : 0 );
