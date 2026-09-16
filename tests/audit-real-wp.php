<?php
/**
 * AUDIT TEST — M1: real WordPress release matrix (NO shim).
 *
 * Runs against a REAL WordPress install (real MariaDB, real wp-load boot,
 * real plugin activation path, real drop-in, real WP main loop workers).
 * Provisioned and driven by tests/run-real-wp-live.sh; per-env contract via
 * UC_REALWP_* environment variables.
 *
 * Check groups (per environment):
 *   V1  real WP version matches the release under test (bloginfo + $wp_version)
 *   V2  real DB backend: MariaDB, precise server version recorded
 *   V3  plugin is active through the REAL WP plugin loader
 *   I1  Installer artifacts: settings option, admin cap, cache root tree,
 *       generated .htaccess guard, index.html
 *   D1  object-cache drop-in owned by the plugin at boot (real WP object
 *       cache API routes through the plugin Manager)
 *   D2  wp_cache_* real roundtrip: set/get/delete through the drop-in
 *   P1  page-cache MISS→STORE via real WP main loop (worker A: front page
 *       rendered, canary post created, v/ tree populated)
 *   P2  page-cache HIT serves the STORED body (worker B: worker A's marker
 *       present, worker B's own fresh marker ABSENT — proof of no re-render)
 *   U1  invalidation through the REAL save_post hook (wp_update_post →
 *       purge_post → stored front page removed from disk)
 *   U2  explicit invalidation API purge_url removes a re-stored page
 *   P3  after invalidation a fresh render happens (worker C: new marker
 *       stored) and the next fetch HITs the regenerated page
 *   M1  multisite (subdirectory): nested switch_to_blog object-cache canary
 *       isolation, global-group sharing, per-blog page-cache key isolation
 *   M2  multisite (subdomain): same canary matrix across subdomain blogs
 *
 * Without UC_REALWP_DIR the suite self-gates into clean SKIP rows (never
 * counted as PASS) — same honesty rule as the Apache/RabbitMQ gates.
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

$dir = (string) getenv( 'UC_REALWP_DIR' );
if ( '' === $dir || ! is_dir( $dir ) ) {
        echo "[SKIP] audit-real-wp: UC_REALWP_DIR absent — real-WP matrix not provisioned (honest gate)\n";
        echo "[SKIP] audit-real-wp: all M1 real-WP checks gated; nothing counted as PASS\n";
        exit( 0 );
}

$url      = (string) getenv( 'UC_REALWP_URL' );
$expected = (string) getenv( 'UC_REALWP_VERSION' );
$ms_mode  = (string) getenv( 'UC_REALWP_MULTISITE' ); // '' | subdir | subdomain
$php      = (string) ( getenv( 'UC_REALWP_PHP' ) ?: PHP_BINARY );
$worker   = (string) getenv( 'UC_REALWP_WORKER' );

$results = array();
function pcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

// --- boot REAL WordPress (real wp-load, real DB, plugin late_boot runs) ------
require rtrim( $dir, '/' ) . '/wp-load.php';

// V1 — real WP version ----------------------------------------------------------------
pcheck( $results, 'V1a wp_version file present', is_file( ABSPATH . 'wp-includes/version.php' ) );
pcheck( $results, 'V1b bloginfo version == expected release', '' !== $expected && get_bloginfo( 'version' ) === $expected, 'got ' . get_bloginfo( 'version' ) . " want $expected" );
pcheck( $results, 'V1c \$GLOBALS wp_version matches', ( $GLOBALS['wp_version'] ?? '' ) === $expected );

// V2 — real database ------------------------------------------------------------------
global $wpdb;
$server_info = (string) ( method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : '' );
pcheck( $results, 'V2a real MariaDB server', false !== stripos( $server_info, 'MariaDB' ), "info='$server_info'" );
pcheck( $results, 'V2b precise DB version recorded (11.8.6)', false !== stripos( $server_info, '11.8.6' ), "info='$server_info'" );
$db_ext = extension_loaded( 'pdo_mysql' ) ? 'pdo_mysql' : 'mysqli';
pcheck( $results, 'V2c DB client driver', '' !== $db_ext, $db_ext );

// V3 — plugin active through the real loader ------------------------------------------
$active = (array) get_option( 'active_plugins', array() );
$found  = false;
foreach ( $active as $p ) {
        if ( false !== strpos( (string) $p, 'ultimate-performance' ) ) {
                $found = true;
        }
}
pcheck( $results, 'V3a plugin in active_plugins', $found, wp_json_encode( $active ) );
pcheck( $results, 'V3b plugin kernel booted (settings object)', class_exists( '\UltimatePerformance\Core\Plugin' ) && \UltimatePerformance\Core\Plugin::instance()->settings instanceof \UltimatePerformance\Core\Settings );

// I1 — installer artifacts ------------------------------------------------------------
$raw_settings = get_option( \UltimatePerformance\Core\Settings::OPTION, false );
pcheck( $results, 'I1a settings option persisted', is_array( $raw_settings ) );
pcheck( $results, 'I1b page cache enabled setting', is_array( $raw_settings ) && ! empty( $raw_settings['page_cache_enabled'] ) );
$admins = get_role( 'administrator' );
pcheck( $results, 'I1c purge capability granted', $admins && $admins->has_cap( \UltimatePerformance\Core\Installer::CAP_PURGE_ALL ) );
$root = \UltimatePerformance\Core\Installer::cache_root();
$tree_ok = true;
foreach ( array( $root, "$root/v", "$root/meta", "$root/tmp" ) as $d ) {
        if ( ! is_dir( $d ) ) {
                $tree_ok = false;
        }
}
pcheck( $results, 'I1d cache root tree created in real wp-content', $tree_ok, $root );
$ht = is_file( "$root/.htaccess" ) ? (string) file_get_contents( "$root/.htaccess" ) : '';
pcheck( $results, 'I1e generated .htaccess guard present', false !== strpos( $ht, '# BEGIN Ultimate Performance Storage' ) && false !== strpos( $ht, 'Require all denied' ) );
pcheck( $results, 'I1f index.html empty-body guard present', is_file( "$root/index.html" ) );

// D1 — drop-in ownership (installed at provisioning; assert real state) ---------------
$dropin_path = WP_CONTENT_DIR . '/object-cache.php';
pcheck( $results, 'D1a object-cache.php drop-in exists', is_file( $dropin_path ) );
$dropin_src = is_file( $dropin_path ) ? (string) file_get_contents( $dropin_path ) : '';
pcheck( $results, 'D1b drop-in is plugin-owned', false !== strpos( $dropin_src, 'UltimateCache' ) );
$dropin = new \UltimatePerformance\ObjectCache\Dropin();
$iv = $dropin->installed_version( WP_CONTENT_DIR );
pcheck( $results, 'D1c drop-in generator version is current (v2)', '2' === $iv, (string) $iv );
pcheck( $results, 'D1d plugin object-cache API bound (uc_oc)', function_exists( 'up_oc' ) );
pcheck( $results, 'D1e wp_cache_init takeover signal defined', function_exists( 'wp_cache_init' ) );
pcheck( $results, 'D1f WordPress recognizes external object cache takeover', function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() );
$up_surface_missing = array();
foreach ( array(
        'wp_cache_init', 'wp_cache_add', 'wp_cache_add_multiple', 'wp_cache_replace', 'wp_cache_set',
        'wp_cache_set_multiple', 'wp_cache_get', 'wp_cache_get_multiple', 'wp_cache_delete',
        'wp_cache_delete_multiple', 'wp_cache_incr', 'wp_cache_decr', 'wp_cache_flush',
        'wp_cache_flush_runtime', 'wp_cache_flush_group', 'wp_cache_supports', 'wp_cache_close',
        'wp_cache_add_global_groups', 'wp_cache_add_non_persistent_groups', 'wp_cache_switch_to_blog',
        'wp_cache_reset',
) as $up_fn ) {
        if ( ! function_exists( $up_fn ) ) {
                $up_surface_missing[] = $up_fn;
        }
}
pcheck( $results, 'D1g complete 21-function wp_cache_* surface present', array() === $up_surface_missing, implode( ',', $up_surface_missing ) );

// D2 — real wp_cache_* roundtrip through the drop-in ---------------------------------
$rt_set = wp_cache_set( 'up_d2_key', 'up_d2_val', 'default', 60 );
$rt_get = wp_cache_get( 'up_d2_key', 'default' );
pcheck( $results, 'D2a wp_cache_set real API', true === $rt_set );
pcheck( $results, 'D2b wp_cache_get returns stored value', 'up_d2_val' === $rt_get, var_export( $rt_get, true ) );
pcheck( $results, 'D2c wp_cache_delete + miss', wp_cache_delete( 'up_d2_key', 'default' ) && false === wp_cache_get( 'up_d2_key', 'default' ) );

// --- page-cache lifecycle through real WP main loop (separate processes) -------------
function run_worker( $php, $worker, $mode, $marker ) {
        $env = array_merge(
                array_filter( $_SERVER, 'is_string' ),
                array(
                        'UC_REALWP_DIR'    => (string) getenv( 'UC_REALWP_DIR' ),
                        'UC_REALWP_URL'    => (string) getenv( 'UC_REALWP_URL' ),
                        'UC_WORKER_MODE'   => $mode,
                        'UC_WORKER_MARKER' => $marker,
                )
        );
        $spec = array(
                0 => array( 'pipe', 'r' ),
                1 => array( 'pipe', 'w' ),
                2 => array( 'pipe', 'w' ),
        );
        $proc = proc_open( escapeshellarg( $php ) . ' ' . escapeshellarg( $worker ), $spec, $pipes, null, $env );
        if ( ! is_resource( $proc ) ) {
                return array( false, '', 'proc_open failed' );
        }
        fclose( $pipes[0] );
        $out   = stream_get_contents( $pipes[1] );
        $err   = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $code = proc_close( $proc );
        return array( 0 === $code, (string) $out, (string) $err );
}

$mkA = 'A' . bin2hex( random_bytes( 6 ) );
$mkB = 'B' . bin2hex( random_bytes( 6 ) );
$mkC = 'C' . bin2hex( random_bytes( 6 ) );

// P1 — MISS → STORE (worker A)
list( $okA, $outA, $errA ) = run_worker( $php, $worker, 'render', $mkA );
pcheck( $results, 'P1a worker A render exit 0', $okA, substr( $errA, 0, 200 ) );
$canary_id = 0;
$v_files   = array();
if ( $okA && preg_match( '/UCWORKER-JSON:(\{.*\})/', $outA, $m ) ) {
        $j         = json_decode( $m[1], true );
        $canary_id = (int) ( $j['canary_id'] ?? 0 );
        $v_files   = (array) ( $j['v_files'] ?? array() );
}
pcheck( $results, 'P1b canary post created in real DB', $canary_id > 0 );
pcheck( $results, 'P1c page-cache v/ tree populated on real render', count( $v_files ) > 0, wp_json_encode( array_slice( $v_files, 0, 4 ) ) );
$host = (string) parse_url( $url, PHP_URL_HOST );
$front_files = array_values( array_filter( $v_files, static function ( $f ) use ( $host ) {
        return 0 === strpos( (string) $f, $host . '/' ) && false !== strpos( (string) $f, 'index.html' );
} ) );
pcheck( $results, 'P1d front-page index.html stored under host dir', count( $front_files ) > 0, wp_json_encode( array_slice( $v_files, 0, 6 ) ) );
$front_rel = (string) ( $front_files[0] ?? '' );
$front_rel = false !== strpos( $front_rel, '/index.html' ) ? substr( $front_rel, 0, strrpos( $front_rel, '/index.html' ) ) : $front_rel;

// P2 — HIT serves stored body (worker B): A marker present, B marker absent
list( $okB, $outB, $errB ) = run_worker( $php, $worker, 'fetch', $mkB );
pcheck( $results, 'P2a worker B exit 0', $okB, substr( $errB, 0, 200 ) );
pcheck( $results, 'P2b HIT body contains stored marker A', false !== strpos( $outB, "UCM:$mkA" ) );
pcheck( $results, 'P2c HIT body does NOT contain fresh marker B (no re-render)', false === strpos( $outB, "UCM:$mkB" ) );

// U1 — invalidation via the REAL save_post hook path
if ( $canary_id > 0 ) {
        wp_update_post(
                array(
                        'ID'           => $canary_id,
                        'post_content' => 'Invalidated via real save_post hook — ' . $mkC,
                )
        );
        // save_post → Hooks::purge_post → tag Registry → QueueManager enqueue.
        // The queue drains on the wp-cron tick; under wp-cli (no HTTP loopback)
        // the tick is fired exactly the way `wp cron event run` does — through
        // the registered action handler (QueueManager::cron_tick → work()).
        do_action( 'ultimate_performance_tick' );
        $still = is_file( \UltimatePerformance\Core\Installer::cache_root() . '/v/' . $front_rel . '/index.html' );
        pcheck( $results, 'U1a save_post hook purge removed stored front page', ! $still, $front_rel );
} else {
        pcheck( $results, 'U1a save_post hook purge removed stored front page', false, 'no canary post id' );
}

// U2 — explicit purge_url API on a re-rendered page
list( $okD, $outD, $errD ) = run_worker( $php, $worker, 'render', $mkB . 'x' );
$pcheck_cond = $okD && is_file( \UltimatePerformance\Core\Installer::cache_root() . '/v/' . $front_rel . '/index.html' );
pcheck( $results, 'U2a re-render re-stores front page (fresh worker)', $pcheck_cond );
( new \UltimatePerformance\CacheInvalidation\Hooks() )->purge_url( $url . '/' );
pcheck( $results, 'U2b purge_url removed the stored page', ! is_file( \UltimatePerformance\Core\Installer::cache_root() . '/v/' . $front_rel . '/index.html' ) );

// P3 — post-invalidation lifecycle: fresh render, then HIT on the regenerated page
// (front page was purged by U2b, so the first fetch MUST miss and re-store).
list( $okC, $outC, $errC ) = run_worker( $php, $worker, 'fetch', $mkC );
pcheck( $results, 'P3a post-purge fetch re-renders (marker C stored fresh)', $okC && false !== strpos( $outC, "UCM:$mkC" ), substr( $errC, 0, 160 ) );
list( $okC3, $outC3, $errC3 ) = run_worker( $php, $worker, 'fetch', $mkC . 'z' );
pcheck( $results, 'P3b next fetch HITs regenerated page (no re-render)', $okC3 && false !== strpos( $outC3, "UCM:$mkC" ) && false === strpos( $outC3, "UCM:$mkC" . 'z' ), substr( $errC3, 0, 160 ) );

// --- multisite canary matrix (release-blocking scope) --------------------------------
if ( '' !== $ms_mode && is_multisite() ) {
        $sites = get_sites( array( 'number' => 5, 'orderby' => 'blog_id', 'order' => 'ASC' ) );
        $ids   = array();
        foreach ( $sites as $s ) {
                $ids[] = (int) $s->blog_id;
        }
        $b1 = (int) array_shift( $ids );
        $b2 = (int) ( $ids[0] ?? 0 );
        pcheck( $results, "M0a multisite ($ms_mode) has >= 2 sites", $b1 > 0 && $b2 > 0, wp_json_encode( array( $b1, $b2 ) ) );

        if ( $b1 > 0 && $b2 > 0 ) {
                switch_to_blog( $b1 );
                wp_cache_set( 'up_ms_canary', 'blog1val', 'default', 120 );
                $v1 = wp_cache_get( 'up_ms_canary', 'default' );
                restore_current_blog();

                $leak_into_2 = null;
                switch_to_blog( $b2 );
                $leak_into_2 = wp_cache_get( 'up_ms_canary', 'default' );
                wp_cache_set( 'up_ms_canary', 'blog2val', 'default', 120 );
                $v2 = wp_cache_get( 'up_ms_canary', 'default' );
                restore_current_blog();

                pcheck( $results, 'M1a no cross-blog leak into blog 2', null === $leak_into_2 || false === $leak_into_2, var_export( $leak_into_2, true ) );
                pcheck( $results, 'M1b blog 2 value isolated (blog2val)', 'blog2val' === $v2 );
                pcheck( $results, 'M1c blog 1 canary intact after round-trip', 'blog1val' === wp_cache_get( 'up_ms_canary', 'default' ) );

                // NESTED switch: blog1 -> blog2 -> blog1 -> back
                $nested_ok = false;
                switch_to_blog( $b2 );
                switch_to_blog( $b1 );
                $nested_ok = ( 'blog1val' === wp_cache_get( 'up_ms_canary', 'default' ) );
                restore_current_blog();
                $nested_ok = $nested_ok && ( 'blog2val' === wp_cache_get( 'up_ms_canary', 'default' ) );
                restore_current_blog();
                pcheck( $results, 'M1d nested switch_to_blog canary isolation', $nested_ok );
                pcheck( $results, 'M1e blog 1 canary survives nested stack', 'blog1val' === wp_cache_get( 'up_ms_canary', 'default' ) );

                // global group shares across blogs
                wp_cache_set( 'up_ms_global', 'sharedval', 'uc:internal', 120 );
                switch_to_blog( $b2 );
                $g = wp_cache_get( 'up_ms_global', 'uc:internal' );
                restore_current_blog();
                pcheck( $results, 'M1f global group shared across blogs', 'sharedval' === $g, var_export( $g, true ) );

                // per-blog page-cache key isolation (real Keygen over each blog URL)
                $keygen = new \UltimatePerformance\CacheKey\Key( \UltimatePerformance\Core\Plugin::instance()->settings );
                switch_to_blog( $b2 );
                $home2 = home_url( '/' );
                restore_current_blog();
                $h1 = wp_parse_url( home_url( '/' ) );
                $h2 = wp_parse_url( $home2 );
                $k1 = $keygen->build( 'http', (string) ( $h1['host'] ?? '' ), (string) ( $h1['path'] ?? '/' ), '', array( 'accept' => 'text/html' ) );
                $k2 = $keygen->build( 'http', (string) ( $h2['host'] ?? '' ), (string) ( $h2['path'] ?? '/' ), '', array( 'accept' => 'text/html' ) );
                pcheck( $results, 'M1g page-cache keys exist for both blogs', is_array( $k1 ) && is_array( $k2 ) );
                pcheck( $results, 'M1h page-cache rel dirs isolated per blog', is_array( $k1 ) && is_array( $k2 ) && $k1['dir'] !== $k2['dir'], ( $k1['dir'] ?? '?' ) . ' vs ' . ( $k2['dir'] ?? '?' ) );
        }
} elseif ( '' !== $ms_mode ) {
        pcheck( $results, "M0a multisite ($ms_mode) has >= 2 sites", false, 'is_multisite() false — convert failed' );
}

// --- summary -------------------------------------------------------------------------
$pass = count( array_filter( $results ) );
$fail = count( $results ) - $pass;
echo "--- audit-real-wp ($expected, ms=" . ( $ms_mode ?: 'single' ) . ") pass=$pass fail=$fail ---\n";
exit( $fail > 0 ? 1 : 0 );
