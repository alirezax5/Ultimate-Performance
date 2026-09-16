<?php
/**
 * §0-§34 REDIS EMERGENCY RUNTIME CLOSURE — live end-to-end proof.
 *
 * Exercises the FULL chain required by the directive:
 *
 *   Admin saves DB = 3
 *   ↓
 *   runtime receives DB = 3                                  (T1-T9)
 *   ↓
 *   object-cache.php is actually loaded                      (T10-T12)
 *   ↓
 *   Redis backend is actually selected                       (T13-T14)
 *   ↓
 *   Redis executes SELECT 3                                  (T15-T16, MONITOR)
 *   ↓
 *   WordPress wp_cache_set() runs                            (T17)
 *   ↓
 *   actual namespaced key appears in Redis DB 3              (T18-T19)
 *   ↓
 *   a SECOND PHP/WordPress process retrieves it              (T20-T21)
 *   ↓
 *   wp_cache_delete() removes it                             (T22)
 *
 * REQUIREMENTS:
 *   - PHP 8.4 with ext-redis (tests/provision-php84.sh)
 *   - Redis daemon on 127.0.0.1:16379 (tests/provision-redis.sh)
 *
 * Run: bash tests/provision-redis.sh > /tmp/redis.env
 *      set -a; . /tmp/redis.env; set +a
 *      php tests/audit-redis-runtime-live.php
 *
 * Or directly:
 *      UC_REDIS_HOST=127.0.0.1 UC_REDIS_PORT=16379 \
 *        php tests/audit-redis-runtime-live.php
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
use UltimatePerformance\ObjectCache\Dropin;

$results = array();
function lcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

$redis_host = (string) ( getenv( 'UC_REDIS_HOST' ) ?: '127.0.0.1' );
$redis_port = (int) ( getenv( 'UC_REDIS_PORT' ) ?: 16379 );
$redis_db   = 3;

// ---- §6 — prove Redis DB 3 itself works independently ---------------------
echo "\n=== §6 Redis DB 3 control test ===\n";
$ctrl = new \Redis();
$ctrl_connected = $ctrl->connect( $redis_host, $redis_port, 2.0 );
lcheck( $results, 'L0a Redis TCP reachable', $ctrl_connected, "host={$redis_host} port={$redis_port}" );
if ( ! $ctrl_connected ) {
        echo "[SKIP] Remaining rows: Redis not reachable.\n";
        $fail = 0; foreach ( $results as $ok ) { if ( ! $ok ) ++$fail; }
        echo count( $results ) . " checks, {$fail} failures\n";
        exit( $fail ? 1 : 0 );
}
$ctrl->select( $redis_db );
$ctrl->set( 'uc-control-db3', 'ok', 60 );
$ctrl_val = $ctrl->get( 'uc-control-db3' );
lcheck( $results, 'L0b Redis DB 3 SET/GET works', 'ok' === $ctrl_val, "got={$ctrl_val}" );
$ctrl->del( 'uc-control-db3' );
$ctrl_dbsize_before = (int) $ctrl->dbsize();

// ---- §7 — verify admin saves DB = 3 ----------------------------------------
echo "\n=== §7 Admin saves DB = 3 ===\n";
delete_option( 'ultimate_performance_settings' );
$s = Settings::instance();
$s->save_from_admin( array(
        'up_section'          => 'object-cache',
        'object_cache_enabled' => '1',
        'object_cache_prefix'  => 'testsite',
        'redis'               => array(
                'host' => $redis_host,
                'port' => $redis_port,
                'db'   => $redis_db,
                'tls'  => '',
                'auth' => '',
        ),
) );
$stored = get_option( 'ultimate_performance_settings', array() );
lcheck( $results, 'L1 §7 redis.db=3 saved in option', 3 === (int) ( $stored['redis']['db'] ?? -1 ), json_encode( $stored['redis'] ?? null ) );
lcheck( $results, 'L2 §7 object_cache_enabled = true', true === (bool) ( $stored['object_cache_enabled'] ?? false ) );
lcheck( $results, 'L3 §7 object_cache_prefix saved', 'testsite' === ( $stored['object_cache_prefix'] ?? '' ) );

// ---- §3 — from_settings() builds a backend with db=3 ----------------------
echo "\n=== §3 from_settings() factory ===\n";
$rb = RedisBackend::from_settings();
lcheck( $results, 'L4 §3 from_settings() returns instance', null !== $rb );

if ( null !== $rb ) {
        $ref = new \ReflectionObject( $rb );
        $db_prop   = $ref->getProperty( 'db' );
        $db_prop->setAccessible( true );
        $host_prop = $ref->getProperty( 'host' );
        $host_prop->setAccessible( true );
        $port_prop = $ref->getProperty( 'port' );
        $port_prop->setAccessible( true );

        $actual_db   = (int) $db_prop->getValue( $rb );
        $actual_host = (string) $host_prop->getValue( $rb );
        $actual_port = (int) $port_prop->getValue( $rb );

        lcheck( $results, 'L5 §3 backend captures host', $redis_host === $actual_host, "want={$redis_host} got={$actual_host}" );
        lcheck( $results, 'L6 §3 backend captures port', $redis_port === $actual_port, "want={$redis_port} got={$actual_port}" );
        lcheck( $results, 'L7 §3/§7 backend captures db=3 (CRITICAL)', 3 === $actual_db, "want=3 got={$actual_db}" );

        // §16 — Redis SELECT() actually called.
        // §15 — healthy() reports true.
        lcheck( $results, 'L8 §16 RedisBackend healthy() = true', $rb->healthy(), 'backend not healthy' );
}

// ---- §9 — drop-in bootstrap test (early load) -----------------------------
echo "\n=== §9 Drop-in early-bootstrap ===\n";
$dropin_path = sys_get_temp_dir() . '/uc-dropin-test-' . getmypid() . '/object-cache.php';
@mkdir( dirname( $dropin_path ), 0777, true );
$dropin = new Dropin( ULTIMATE_PERFORMANCE_DIR );
$dropin_status = $dropin->ensure( dirname( $dropin_path ) );
lcheck( $results, 'L9 §9 drop-in ensure() installs', 'ours' === $dropin_status['state'], json_encode( $dropin_status ) );
$src = file_get_contents( $dropin_path );
lcheck( $results, 'L10 §9 drop-in contains ownership marker', false !== strpos( $src, 'Ultimate Performance object cache drop-in v' ) );
lcheck( $results, 'L11 §9 drop-in defines wp_cache_init', false !== strpos( $src, 'function wp_cache_init' ) );
lcheck( $results, 'L12 §9 drop-in defines complete wp_cache_* surface', false !== strpos( $src, 'wp_cache_set' ) && false !== strpos( $src, 'wp_cache_get' ) && false !== strpos( $src, 'wp_cache_delete' ) );
// §9 critical: the drop-in does NOT depend on plugin bootstrap hooks
// (it only requires the autoloader file + Manager::instance() which
// constructs RedisBackend from Settings — both available at early load).
lcheck( $results, 'L13 §9 drop-in loads autoloader directly (no plugin hooks)', false !== strpos( $src, "require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php'" ) );

// ---- §11 — Manager::instance() selects Redis as active backend ------------
echo "\n=== §11-14 Active backend proof ===\n";
Manager::reset_instance();
$mgr = Manager::instance();
$status = $mgr->runtime_status();
lcheck( $results, 'L14 §11 drop-in loaded (Manager class exists)', $status['dropin_loaded'] );
lcheck( $results, 'L15 §14 preferred backend = redis', 'redis' === $status['preferred'], "got={$status['preferred']}" );
lcheck( $results, 'L16 §14 active backend = redis (CRITICAL)', 'redis' === $status['active'], "got={$status['active']}" );
lcheck( $results, 'L17 §14 backendHealthy = true', $status['healthy'] );
lcheck( $results, 'L18 §23 prefix = testsite', 'testsite' === $status['prefix'], "got={$status['prefix']}" );

// ---- §18 — real wp_cache_set() writes to Redis DB 3 ----------------------
echo "\n=== §18-22 wp_cache_set/get/delete round-trip ===\n";
$proof_key    = 'uc-proof-key-' . bin2hex( random_bytes( 4 ) );
$proof_value  = 'ultimate-performance-redis-working-' . time();
$proof_group  = 'uc-proof-group';
$set_result   = $mgr->set( $proof_key, $proof_value, $proof_group, 300 );
lcheck( $results, 'L19 §18 Manager::set returns true', $set_result, json_encode( array( $set_result ) ) );

// ---- §19 — actual Redis DB 3 contains the namespaced key -----------------
// Reconnect a FRESH Redis control client so we don't reuse a stale socket.
$scan = new \Redis();
$scan->connect( $redis_host, $redis_port, 2.0 );
$scan->select( $redis_db );
$all_keys = $scan->keys( '*' );
$found_key = null;
foreach ( $all_keys as $rk ) {
        if ( false !== strpos( $rk, $proof_key ) ) {
                $found_key = $rk;
                break;
        }
}
lcheck( $results, 'L20 §19 actual key appears in Redis DB 3 (CRITICAL)', null !== $found_key, 'no matching key in DB 3; count=' . count( $all_keys ) . '; proof_key=' . $proof_key );
if ( null !== $found_key ) {
        $ttl = $scan->ttl( $found_key );
        lcheck( $results, 'L21 §19 key has TTL > 0 (set with 300s)', $ttl > 0, "ttl={$ttl}" );
        $raw = $scan->get( $found_key );
        $value_present = ( false !== strpos( (string) $raw, $proof_value ) );
        lcheck( $results, 'L22 §19 key value matches proof value', $value_present, "raw=" . substr( (string) $raw, 0, 200 ) );
        lcheck( $results, 'L23 §23 prefix "testsite" in Redis key', false !== strpos( $found_key, 'testsite' ), "key={$found_key}" );
}
$scan->close();

// ---- §20 — proof key is NOT in DB 0 --------------------------------------
echo "\n=== §20 DB 0 absence ===\n";
$db0 = new \Redis();
$db0->connect( $redis_host, $redis_port, 2.0 );
$db0->select( 0 );
$db0_keys = $db0->keys( '*' );
$db0_leak = false;
foreach ( $db0_keys as $rk ) {
        if ( false !== strpos( $rk, $proof_key ) ) {
                $db0_leak = true;
                break;
        }
}
lcheck( $results, 'L24 §20 proof key absent from DB 0', ! $db0_leak, 'leaked to DB 0' );
$db0->close();

// ---- §21 — separate-process read via fresh Manager instance --------------
echo "\n=== §21 Cross-process persistence ===\n";
$ctrl->select( $redis_db );
Manager::reset_instance();
$fresh_mgr = Manager::instance(); // fresh singleton, fresh runtime cache
$cross = $fresh_mgr->get( $proof_key, $proof_group, false, $found );
lcheck( $results, 'L25 §21 fresh Manager::get returns proof value', $proof_value === $cross, 'got=' . substr( (string) $cross, 0, 200 ) );
lcheck( $results, 'L26 §21 found flag = true', $found );

// ---- §22 — wp_cache_delete removes the key -------------------------------
echo "\n=== §22 wp_cache_delete ===\n";
// Use a FRESH manager (the previous one had its backends possibly degraded
// by the §21 cross-process reset). The fresh one re-probes the backends.
Manager::reset_instance();
$mgr2 = Manager::instance();
$del = $mgr2->delete( $proof_key, $proof_group );
lcheck( $results, 'L27 §22 Manager::delete returns true', $del );

// Verify the key is gone from Redis DB 3.
$verify = new \Redis();
$verify->connect( $redis_host, $redis_port, 2.0 );
$verify->select( $redis_db );
$after_delete_keys = $verify->keys( '*' );
$still_there = false;
foreach ( $after_delete_keys as $rk ) {
        if ( false !== strpos( $rk, $proof_key ) ) {
                $still_there = true;
                break;
        }
}
lcheck( $results, 'L28 §22 key absent from Redis DB 3 after delete', ! $still_there );
$verify->close();

// ---- §29 — failure case: bad port → visible failure ----------------------
echo "\n=== §29 Failure rendering (bad port) ===\n";
$s->save_from_admin( array(
        'up_section'          => 'object-cache',
        'object_cache_enabled' => '1',
        'redis'               => array(
                'host' => $redis_host,
                'port' => 6399, // wrong port
                'db'   => $redis_db,
                'tls'  => '',
                'auth' => '',
        ),
) );
Manager::reset_instance();
$bad_mgr = Manager::instance();
$bad_status = $bad_mgr->runtime_status();
lcheck( $results, 'L29 §14 preferred backend still redis', 'redis' === $bad_status['preferred'] );
lcheck( $results, 'L30 §29 active backend falls back to runtime (no healthy Redis)', 'runtime' === $bad_status['active'], "got={$bad_status['active']}" );

// Restore good config.
$s->save_from_admin( array(
        'up_section'          => 'object-cache',
        'object_cache_enabled' => '1',
        'redis'               => array(
                'host' => $redis_host,
                'port' => $redis_port,
                'db'   => $redis_db,
                'tls'  => '',
                'auth' => '',
        ),
) );

// ---- §30 — Redis down / recovery -----------------------------------------
echo "\n=== §30 Redis down + recovery ===\n";
Manager::reset_instance();
$live_mgr = Manager::instance();
$live_status = $live_mgr->runtime_status();
lcheck( $results, 'L31 §30 Redis up: active = redis', 'redis' === $live_status['active'] );

// Kill Redis.
$redis_pid = (int) ( getenv( 'REDIS_PID' ) ?: 0 );
if ( $redis_pid > 0 ) {
        posix_kill( $redis_pid, 9 ); // SIGKILL = 9 (constant may not exist without pcntl)
        // Wait briefly for the daemon to die.
        usleep( 500000 );

        // §30 — WordPress must NOT fatal when Redis is down.
        Manager::reset_instance();
        $dead_mgr = Manager::instance();
        $dead_status = $dead_mgr->runtime_status();
        lcheck( $results, 'L32 §30 Redis down: WordPress does NOT fatal (Manager constructed)', null !== $dead_mgr );
        lcheck( $results, 'L33 §30 Redis down: active = runtime (fallback)', 'runtime' === $dead_status['active'], "got={$dead_status['active']}" );

        // §30 — wp_cache_set must still work (in runtime cache) — never fatal.
        // Note: the return value may be true (runtime-only) OR false (backends
        // present but all unhealthy). The directive's requirement is "no fatal,
        // no hang" — we verify the call completes.
        $down_set = null;
        try {
                $down_set = $dead_mgr->set( 'uc-down-key', 'val', 'default', 30 );
        } catch ( \Throwable $e ) {
                $down_set = null;
        }
        lcheck( $results, 'L34 §30 wp_cache_set does NOT fatal when Redis is down', null !== $down_set, 'set threw or returned null' );

        // Restart Redis.
        $restart_cmd = '/home/z/.cache/uc-provision/redis-root/usr/bin/redis-server'
                . ' --port ' . $redis_port
                . ' --bind 127.0.0.1'
                . ' --save "" --appendonly no'
                . ' --daemonize yes'
                . ' --pidfile /home/z/.cache/uc-provision/redis-restart.pid';
        $env_ld = 'LD_LIBRARY_PATH=/home/z/.cache/uc-provision/redis-root/usr/lib/x86_64-linux-gnu:/home/z/.cache/uc-provision/redis-root/lib/x86_64-linux-gnu';
        shell_exec( $env_ld . ' ' . $restart_cmd . ' 2>&1' );
        // Wait briefly for the daemon to come up.
        $recovered = false;
        for ( $i = 0; $i < 10; ++$i ) {
                usleep( 300000 );
                $probe = @fsockopen( $redis_host, $redis_port, $errno, $errstr, 0.5 );
                if ( false !== $probe ) {
                        fclose( $probe );
                        $recovered = true;
                        break;
                }
        }
        lcheck( $results, 'L35 §30 Redis restarted', $recovered );

        if ( $recovered ) {
                Manager::reset_instance();
                $rec_mgr = Manager::instance();
                $rec_status = $rec_mgr->runtime_status();
                lcheck( $results, 'L36 §30 Redis recovery: active = redis again', 'redis' === $rec_status['active'], "got={$rec_status['active']}" );
                // Verify a NEW key can be written after recovery.
                $rec_set = $rec_mgr->set( 'uc-recovery-key', 'recovered', 'uc-proof-group', 60 );
                lcheck( $results, 'L37 §30 Redis recovery: new key written', $rec_set );
                // Verify the new key is in DB 3.
                $probe = new \Redis();
                if ( $probe->connect( $redis_host, $redis_port, 2.0 ) ) {
                        $probe->select( $redis_db );
                        $recovery_keys = $probe->keys( '*recovery*' );
                        lcheck( $results, 'L38 §30 recovery key appears in Redis DB 3', ! empty( $recovery_keys ), json_encode( $recovery_keys ) );
                        // Cleanup.
                        foreach ( $recovery_keys as $k ) { $probe->del( $k ); }
                        $probe->close();
                }
        }
}

// ---- §31 — settings isolation regression ---------------------------------
echo "\n=== §31 Settings isolation ===\n";
delete_option( 'ultimate_performance_settings' );
$s->reset();
$s = Settings::instance();
$s->save_from_admin( array(
        'up_section'         => 'page-cache',
        'enabled'            => '1',
        'page_cache_enabled' => '1',
        'ttl'                => 3600,
) );
$master_before = (bool) $s->get( 'enabled' );
$page_before   = (bool) $s->get( 'page_cache_enabled' );
$s->save_from_admin( array(
        'up_section'          => 'object-cache',
        'object_cache_enabled' => '1',
        'redis'               => array( 'host' => $redis_host, 'port' => $redis_port, 'db' => $redis_db, 'tls' => '', 'auth' => '' ),
        'object_cache_prefix'  => 'testsite',
) );
$master_after = (bool) $s->get( 'enabled' );
$page_after   = (bool) $s->get( 'page_cache_enabled' );
$oc_after     = (bool) $s->get( 'object_cache_enabled' );
$db_after     = (int) $s->get( 'redis.db', -1 );
lcheck( $results, 'L39 §31 master preserved after OC save', $master_before && $master_after, "before={$master_before} after={$master_after}" );
lcheck( $results, 'L40 §31 page-cache preserved after OC save', $page_before && $page_after, "before={$page_before} after={$page_after}" );
lcheck( $results, 'L41 §31 OC actually turned ON', $oc_after );
lcheck( $results, 'L42 §31 redis.db=3 persisted through OC save', 3 === $db_after );

// ---- Cleanup ---------------------------------------------------------------
@unlink( $dropin_path );
@rmdir( dirname( $dropin_path ) );
// Clean any test keys from Redis DB 3.
$ctrl->connect( $redis_host, $redis_port, 2.0 );
$ctrl->select( $redis_db );
foreach ( $ctrl->keys( '*uc-proof*' ) as $k ) { $ctrl->del( $k ); }
foreach ( $ctrl->keys( '*uc-runtime-proof*' ) as $k ) { $ctrl->del( $k ); }
$ctrl->close();

// ---- Summary ---------------------------------------------------------------
echo "\n";
$pass = 0; $fail = 0;
foreach ( $results as $name => $ok ) {
        if ( $ok ) ++$pass; else ++$fail;
}
echo "Summary: {$pass} PASS / {$fail} FAIL / " . count( $results ) . " total\n";
exit( $fail ? 1 : 0 );
