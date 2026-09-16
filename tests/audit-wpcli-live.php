<?php
/**
 * O4 §29-32 — WP-CLI command registration audit.
 *
 * Phase O §29-32 require real WP-CLI wiring: WP_CLI::add_command(...) and
 * the four commands (status/epoch/events/reconcile) exposed via `wp`
 * binary.
 *
 * This audit verifies:
 *   - The WpCliCommands class loads without fatal under a fake WP_CLI env
 *   - The `register()` method calls WP_CLI::add_command with the right name
 *   - Each command method exists and dispatches correctly
 *   - Read-only commands do NOT mutate state (especially status)
 *   - JSON output is valid
 *
 * Run: php tests/audit-wpcli-live.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

// Stub a minimal WP_CLI + WP_CLI\Utils\format_items so we can test the
// registration without a real wp binary.
class FakeWpCli {
        public $registered = array();
        public $log_lines  = array();
        public $success_lines = array();
        public $warning_lines = array();
        public static $instance = null;
        public static function instance() {
                if ( null === self::$instance ) { self::$instance = new self(); }
                return self::$instance;
        }
        public static function add_command( $name, $class ) {
                self::instance()->registered[ $name ] = $class;
        }
        public static function log( $msg ) { self::instance()->log_lines[] = $msg; }
        public static function success( $msg ) { self::instance()->success_lines[] = $msg; }
        public static function warning( $msg ) { self::instance()->warning_lines[] = $msg; }
}
if ( ! class_exists( 'WP_CLI' ) ) {
        class_alias( 'UltimatePerformance\\Tests\\FakeWpCli', 'WP_CLI' );
}
namespace UltimatePerformance\Tests;
class FakeWpUtils {
        public static function format_items( $format, $items, $fields ) {
                // minimal — just count
                return $format . ':' . count( $items );
        }
}
namespace WP_CLI\Utils;
if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
        function format_items( $format, $items, $fields ) {
                return \UltimatePerformance\Tests\FakeWpUtils::format_items( $format, $items, $fields );
        }
}
namespace UltimatePerformance\Tests;

// Define WP_CLI = true so WpCliCommands::register() proceeds
defined( 'WP_CLI' ) or define( 'WP_CLI', true );

$results = array();
function wcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

// ---- 1. Load the registration file -----------------------------------------
require_once ULTIMATE_PERFORMANCE_DIR . 'src/WpCliCommands.php';

$fake = FakeWpCli::instance();

// ---- 2. Trigger registration via the cli_init hook --------------------------
// The wp-shim's hooks.php doesn't have a real cli_init hook — call register directly.
\UltimatePerformance\WpCliCommands::register();

wcheck( $results, 'W1 WP_CLI::add_command called for "ultimate-performance cluster"', isset( $fake->registered['ultimate-performance cluster'] ), 'registered=' . json_encode( array_keys( $fake->registered ) ) );
wcheck( $results, 'W2 registered class is UltimatePerformance\\WpCliCommands', 'UltimatePerformance\\WpCliCommands' === $fake->registered['ultimate-performance cluster'] );

// ---- 3. Each command method exists -----------------------------------------
$cls = new \UltimatePerformance\WpCliCommands();
wcheck( $results, 'W3 status() method exists', method_exists( $cls, 'status' ) );
wcheck( $results, 'W4 epoch() method exists',   method_exists( $cls, 'epoch' ) );
wcheck( $results, 'W5 events() method exists', method_exists( $cls, 'events' ) );
wcheck( $results, 'W6 reconcile() method exists', method_exists( $cls, 'reconcile' ) );

// ---- 4. Status emits valid JSON (via FakeWpCli::log capture) ----------------
// We need a real $wpdb for the cluster classes. Use the existing UC_M5_WPDB double.
require_once __DIR__ . '/lib/class-uc-m5-wpdb.php';
$__db_path = sys_get_temp_dir() . '/uc-o4-wpcli-' . getmypid() . '.sqlite';
@$GLOBALS['wpdb'] = new \UC_M5_WPDB( $__db_path );
$wpdb = $GLOBALS['wpdb'];
$wpdb->base_prefix = 'wp_';
register_shutdown_function( function () use ( $__db_path ) { @unlink( $__db_path ); } );

// Reset state so the cluster tables exist
$ROOT = \UltimatePerformance\Core\Installer::cache_root();
if ( is_dir( $ROOT ) ) {
        $rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $ROOT, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $rii as $f ) { @unlink( $f->getRealPath() ); }
        @rmdir( $ROOT );
}
@mkdir( $ROOT . '/meta/', 0775, true );

// Pre-create cluster tables (dbDelta is no-op in shim)
$GLOBALS['wpdb']->query( "CREATE TABLE IF NOT EXISTS wp_uc_cluster_epoch (name CHAR(32) PRIMARY KEY, epoch BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_ms BIGINT UNSIGNED NOT NULL DEFAULT 0)" );
$GLOBALS['wpdb']->query( "INSERT INTO wp_uc_cluster_epoch (name, epoch, updated_ms) VALUES ('cluster', 0, 0)" );
$GLOBALS['wpdb']->query( "CREATE TABLE IF NOT EXISTS wp_uc_invalidation_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id CHAR(36), schema_version INTEGER, origin CHAR(36), epoch INTEGER, gen_epoch INTEGER, scope VARCHAR(255), payload TEXT, created INTEGER, consumed INTEGER)" );
$GLOBALS['wpdb']->query( "CREATE TABLE IF NOT EXISTS wp_uc_cluster_nodes (node_id CHAR(36) PRIMARY KEY, boot_secret CHAR(32), last_instance CHAR(36), last_ms BIGINT)" );

$fake->log_lines = array();
$cls->status( array(), array( 'format' => 'json' ) );
wcheck( $results, 'W7 status() emits 1 log line', 1 === count( $fake->log_lines ), 'log_lines=' . count( $fake->log_lines ) );
$status_json = $fake->log_lines[0] ?? '';
$status_data = json_decode( $status_json, true );
wcheck( $results, 'W8 status() JSON is valid', is_array( $status_data ), 'json=' . substr( $status_json, 0, 80 ) );
wcheck( $results, 'W9 status() JSON has node_id', isset( $status_data['node_id'] ) );
wcheck( $results, 'W10 status() JSON has epoch',  isset( $status_data['epoch'] ) );

// ---- 5. Status does NOT mutate state (read-only) ---------------------------
// Compare epoch before and after status — should be identical.
$epoch_before = ( new \UltimatePerformance\Cluster\Epoch() )->current();
$fake->log_lines = array();
$cls->status( array(), array( 'format' => 'json' ) );
$epoch_after = ( new \UltimatePerformance\Cluster\Epoch() )->current();
wcheck( $results, 'W11 status() is read-only (epoch unchanged)', $epoch_before === $epoch_after, "before={$epoch_before} after={$epoch_after}" );

// ---- 6. epoch show ----------------------------------------------------------
$fake->log_lines = array();
$fake->success_lines = array();
$cls->epoch( array(), array() );
wcheck( $results, 'W12 epoch show emits 1 log line', 1 === count( $fake->log_lines ) );
wcheck( $results, 'W13 epoch show does NOT emit success', 0 === count( $fake->success_lines ) );

// ---- 7. epoch bump (mutating) -----------------------------------------------
$fake->log_lines = array();
$fake->success_lines = array();
$cls->epoch( array(), array( 'bump' => true ) );
wcheck( $results, 'W14 epoch bump emits 1 log line', 1 === count( $fake->log_lines ) );
wcheck( $results, 'W15 epoch bump emits 1 success line', 1 === count( $fake->success_lines ) );

// ---- 8. events (empty initially) -------------------------------------------
$fake->log_lines = array();
$cls->events( array(), array( 'format' => 'json' ) );
wcheck( $results, 'W16 events emits 1 log line', 1 === count( $fake->log_lines ) );
$events_json = $fake->log_lines[0] ?? '';
$events_data = json_decode( $events_json, true );
wcheck( $results, 'W17 events JSON valid + has count=0', is_array( $events_data ) && 0 === ( $events_data['count'] ?? -1 ) );

// ---- 9. reconcile (mutating, idempotent) ------------------------------------
$fake->log_lines = array();
$fake->success_lines = array();
$cls->reconcile( array(), array() );
wcheck( $results, 'W18 reconcile emits 1 log line', 1 === count( $fake->log_lines ) );
wcheck( $results, 'W19 reconcile emits 1 success line', 1 === count( $fake->success_lines ) );

// ---- 10. JSON contract (no sensitive paths/credentials in output) -----------
$all_output = implode( "\n", $fake->log_lines ) . "\n" . implode( "\n", $fake->success_lines );
$leaked = false;
foreach ( array( '@wp-content/cache/ultimate-performance@', '@\.env\b@', '@wp-config\.php@', '@password\s*[:=]@i', '@[a-f0-9]{32}@i' ) as $pat ) {
        if ( preg_match( $pat, $all_output ) ) { $leaked = $pat; break; }
}
wcheck( $results, 'W20 output is secret-free (no paths, no 32-hex secrets)', false === $leaked, "leaked: {$leaked}" );

// ---- 11. Real wp binary available? ------------------------------------------
// wp-cli.phar is at ~/.local/bin/wp (installed in O0). When wp is absent
// (e.g., a fresh CI sandbox without Phase O provisioning), this row is
// reported as a SKIP, not a FAIL — the W20 string-content proof above
// is the actual security guarantee, this row is convenience coverage.
$wp_bin = trim( (string) shell_exec( 'PATH="/home/z/.local/bin:$PATH" command -v wp 2>/dev/null' ) );
if ( '' !== $wp_bin ) {
        wcheck( $results, 'W21 real wp binary is installed at ' . $wp_bin, true );
        // Try `wp --info` to confirm it works
        $wp_info = trim( (string) shell_exec( 'PATH="/home/z/.local/bin:$PATH" wp --info 2>&1' ) );
        wcheck( $results, 'W22 wp --info runs (PHP version reported)', false !== strpos( $wp_info, 'PHP' ), "out=" . substr( $wp_info, 0, 100 ) );
} else {
        echo "[SKIP] W21 real wp binary installed (wp not in PATH — Phase O not provisioned)\n";
}

@unlink( $__db_path );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: {$k}\n"; } }
echo count( $results ) . " checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
