<?php
/**
 * Admin page — tabbed configuration UI with WordPress-native tab navigation.
 *
 * Tabs: Dashboard, Page Cache, Object Cache, Queue, Server Integration,
 * Diagnostics, Advanced. Each tab renders only its own section and the
 * active tab is selected via the ?tab=<slug> URL parameter. Save handlers
 * preserve the active tab so the user lands back on the section they edited.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Admin;

use UltimatePerformance\CacheInvalidation\Hooks as InvalidationHooks;
use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\Core\EnvironmentDetector;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\ObjectCache\Dropin as OcDropin;
use UltimatePerformance\WebServer\Nginx\Rules;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

        const SLUG = 'ultimate-performance';

        public function register() {
                add_action( 'admin_menu', array( $this, 'menu' ) );
                // §12 — Enqueue admin JS+CSS ONLY on Ultimate Performance admin pages.
                // The callback enqueue_assets() is implemented below; gating to
                // Ultimate Performance screens happens inside it via $hook_suffix.
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
                // Legacy admin_post_* handlers (full-page-reload UX). Kept for
                // backwards compatibility with any external tools that POST to
                // admin-post.php. The new AJAX transport is the primary path.
                add_action( 'admin_post_up_save_settings', array( $this, 'handle_save' ) );
                add_action( 'admin_post_up_purge_all', array( $this, 'handle_purge_all' ) );
                add_action( 'admin_post_up_nginx_verify', array( $this, 'handle_nginx_verify' ) );
                add_action( 'admin_post_up_test_redis', array( $this, 'handle_test_redis' ) );
                add_action( 'admin_post_up_test_oc_runtime', array( $this, 'handle_test_oc_runtime' ) );
                add_action( 'admin_post_up_test_amqp', array( $this, 'handle_test_amqp' ) );
                add_action( 'admin_post_up_oc_install', array( $this, 'handle_oc_install' ) );
                add_action( 'admin_post_up_oc_remove', array( $this, 'handle_oc_remove' ) );
                // NOTE: wp_ajax_up_ajax_* hooks are registered in ultimate-performance.php
                // (the plugin main file) at plugin load time. They MUST be
                // registered before boot() so admin-ajax.php can find them even
                // when boot() throws (e.g., Redis construction failure).
        }

        /**
         * §12/§13 — Enqueue admin JS+CSS ONLY on Ultimate Performance admin pages.
         *
         * The script (assets/js/admin.js) implements real AJAX transport for
         * the Redis Test and Object Cache Runtime Test buttons so clicking
         * them does NOT trigger a full-page reload. The localized config
         * provides the AJAX URL + nonce so the JS does NOT hardcode them.
         *
         * @param string $hook_suffix Current admin page hook suffix.
         * @return void
         */
        public function enqueue_assets( $hook_suffix ) {
                // §13 — Load ONLY on Ultimate Performance admin pages. The hook suffix
                // for options pages is 'settings_page_ultimate-performance' on a single
                // site or 'toplevel_page_ultimate-performance' if the menu moves. Both
                // are accepted.
                $allowed = array(
                        'settings_page_ultimate-performance',
                        'toplevel_page_ultimate-performance',
                );
                if ( ! in_array( $hook_suffix, $allowed, true ) ) {
                        return;
                }
                $asset_url = plugin_dir_url( ULTIMATE_PERFORMANCE_FILE ) . 'assets/js/admin.js';
                $asset_path = plugin_dir_path( ULTIMATE_PERFORMANCE_FILE ) . 'assets/js/admin.js';
                // §15 — Cache-bust via filemtime so browser never retains stale JS.
                $ver = file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : ULTIMATE_PERFORMANCE_VERSION;
                wp_register_script( 'ultimate-performance-admin', $asset_url, array( 'jquery' ), $ver, true );
                // §14 — Localize AJAX config so JS does NOT hardcode /wp-admin/admin-ajax.php.
                // Nonces are per-action so the JS can verify the right one.
                $config = array(
                        'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                        'nonces'         => array(
                                'testRedis'       => wp_create_nonce( 'up_ajax_test_redis' ),
                                'ocRuntimePhase1' => wp_create_nonce( 'up_ajax_oc_runtime_phase1' ),
                                'ocRuntimePhase2' => wp_create_nonce( 'up_ajax_oc_runtime_phase2' ),
                                'ocRuntimePhase3' => wp_create_nonce( 'up_ajax_oc_runtime_phase3' ),
                        ),
                        'i18n'           => array(
                                'testing'        => __( 'Testing...', 'ultimate-performance' ),
                                'testRedis'      => __( 'Test Redis Connection', 'ultimate-performance' ),
                                'testOcRuntime'  => __( 'Test Object Cache Runtime', 'ultimate-performance' ),
                                'phase1'         => __( 'Phase 1: write test object...', 'ultimate-performance' ),
                                'phase2'         => __( 'Phase 2: verify cross-request read...', 'ultimate-performance' ),
                                'phase3'         => __( 'Phase 3: delete test object...', 'ultimate-performance' ),
                                'pass'           => __( 'PASS', 'ultimate-performance' ),
                                'fail'           => __( 'FAIL', 'ultimate-performance' ),
                                'ajaxError'      => __( 'AJAX transport failure', 'ultimate-performance' ),
                                'httpStatus'     => __( 'HTTP status', 'ultimate-performance' ),
                                'notReceived'    => __( 'Not received', 'ultimate-performance' ),
                        ),
                );
                wp_localize_script( 'ultimate-performance-admin', 'UP_ADMIN', $config );
                wp_enqueue_script( 'ultimate-performance-admin' );
        }

        public function menu() {
                add_options_page(
                        __( 'Ultimate Performance', 'ultimate-performance' ),
                        __( 'Ultimate Performance', 'ultimate-performance' ),
                        'manage_options',
                        self::SLUG,
                        array( $this, 'render' )
                );
        }

        private function capability_ok() {
                return current_user_can( 'manage_options' );
        }

        // ===== Tab navigation helpers =====

        /**
         * Tab slug → label map. Slugs are url-safe (lowercase, hyphenated).
         *
         * @return array<string,string>
         */
        private function tabs() {
                return array(
                        'dashboard'          => __( 'Dashboard', 'ultimate-performance' ),
                        'page-cache'         => __( 'Page Cache', 'ultimate-performance' ),
                        'object-cache'       => __( 'Object Cache', 'ultimate-performance' ),
                        'queue'              => __( 'Queue', 'ultimate-performance' ),
                        'server-integration' => __( 'Server Integration', 'ultimate-performance' ),
                        'diagnostics'        => __( 'Diagnostics', 'ultimate-performance' ),
                        'advanced'           => __( 'Advanced', 'ultimate-performance' ),
                );
        }

        /**
         * Sanitize and validate the requested tab slug.
         *
         * @return string
         */
        private function current_tab() {
                $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
                $tabs = $this->tabs();
                if ( ! isset( $tabs[ $tab ] ) ) {
                        $tab = 'dashboard';
                }
                return $tab;
        }

        /**
         * Build a URL pointing at a specific tab.
         *
         * @param string $tab Tab slug.
         * @return string Escaped-ready admin URL.
         */
        private function tab_url( $tab ) {
                return add_query_arg(
                        array(
                                'page' => self::SLUG,
                                'tab'  => $tab,
                        ),
                        admin_url( 'options-general.php' )
                );
        }

        /**
         * Read the posted tab (hidden form field) and validate it.
         *
         * Used by save/test handlers so they can redirect back to the same tab.
         *
         * @param string $default Fallback when the field is missing or invalid.
         * @return string
         */
        private function posted_tab( $default = 'dashboard' ) {
                $tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : $default;
                $tabs = $this->tabs();
                if ( ! isset( $tabs[ $tab ] ) ) {
                        $tab = $default;
                }
                return $tab;
        }

        /**
         * Render hidden inputs carrying the active tab slug AND the form identity
         * (section + subsection) for the save handler.
         *
         * Every settings form must call this with its own subsection so that
         * Settings::save_from_admin() knows exactly which keys that ONE form owns.
         * Two forms sharing a section (e.g. Object Cache general vs Redis) must
         * pass different subsections — otherwise an absent checkbox from Form B
         * gets interpreted as an unchecked checkbox belonging to Form A.
         *
         * @param string $tab         Tab slug to preserve on submit.
         * @param string $subsection  Form identity within the tab (default 'general').
         */
        private function tab_hidden_field( $tab, $subsection = 'general' ) {
                printf(
                        '<input type="hidden" name="tab" value="%s"><input type="hidden" name="up[up_section]" value="%s"><input type="hidden" name="up[up_subsection]" value="%s">',
                        esc_attr( $tab ),
                        esc_attr( $tab ),
                        esc_attr( $subsection )
                );
        }

        // ===== Handlers (signatures preserved; redirects preserve `tab`) =====

        public function handle_save() {
                if ( ! $this->capability_ok() ) {
                        wp_die( esc_html__( 'Insufficient permissions.', 'ultimate-performance' ), 403 );
                }
                check_admin_referer( 'up_save_settings', '_ucnonce' );
                $input  = isset( $_POST['up'] ) && is_array( $_POST['up'] ) ? wp_unslash( $_POST['up'] ) : array();
                $errors = Settings::instance()->save_from_admin( $input );
                $tab     = $this->posted_tab( 'dashboard' );
                $redirect = $this->tab_url( $tab );
                if ( $errors ) {
                        set_transient( 'up_settings_errors', $errors, 60 );
                        $redirect = add_query_arg( 'up_error', '1', $redirect );
                } else {
                        // Capture what changed during the save (preserved/updated/cleared)
                        // so the admin notice can SHOW the user the secret was kept.
                        // Critical for the "blank password field did NOT delete the
                        // stored password" guarantee (resolve_secret's preserve path).
                        $summary = Settings::instance()->last_save_summary();
                        if ( is_array( $summary ) && ! empty( $summary['changes'] ) ) {
                                set_transient( 'up_save_summary', $summary, 60 );
                                $redirect = add_query_arg( 'up_saved', 'summary', $redirect );
                        } else {
                                $redirect = add_query_arg( 'up_saved', '1', $redirect );
                        }
                }
                wp_safe_redirect( $redirect );
                exit;
        }

        public function handle_purge_all() {
                if ( ! current_user_can( Installer::CAP_PURGE_ALL ) ) {
                        wp_die( esc_html__( 'Insufficient permissions.', 'ultimate-performance' ), 403 );
                }
                check_admin_referer( 'up_purge_all', '_ucnonce' );
                $tab = $this->posted_tab( 'dashboard' );
                $base = $this->tab_url( $tab );
                if ( empty( $_POST['confirm'] ) || 'yes' !== sanitize_key( (string) $_POST['confirm'] ) ) {
                        wp_safe_redirect( add_query_arg( 'up_purge', 'aborted', $base ) );
                        exit;
                }
                $n = ( new InvalidationHooks() )->purge_site();
                wp_safe_redirect( add_query_arg( 'up_purge', (string) (int) $n, $base ) );
                exit;
        }

        public function handle_test_redis() {
                if ( ! $this->capability_ok() ) {
                        wp_die( esc_html__( 'Insufficient permissions.', 'ultimate-performance' ), 403 );
                }
                check_admin_referer( 'up_test_redis', '_ucnonce' );
                // §4 — Reuse shared service method. No business-logic duplication.
                $result = $this->run_redis_test();
                $result = $this->store_redis_test( $result );
                $this->redirect_redis_test( $result );
        }

        /**
         * §4 — Shared Redis test service. Used by BOTH:
         *   - handle_test_redis() (legacy admin_post_* full-page-reload path)
         *   - ajax_test_redis()   (new AJAX inline-result path)
         *
         * Executes the full §9 sequence:
         *   connect → auth (if configured) → SELECT configured DB → PING →
         *   write temp key → read back → delete.
         *
         * Never returns Redis password in the result array (§8 contract).
         *
         * @return array Result array with keys: ok, msg, host, port, db,
         *               tls, timestamp, phase.
         */
        private function run_redis_test() {
                $s = Settings::instance();
                $host = (string) $s->get( 'redis.host', '127.0.0.1' );
                $port = (int) $s->get( 'redis.port', 6379 );
                $auth = (string) $s->get( 'redis.auth', '' );
                $tls  = (bool) $s->get( 'redis.tls', false );
                $db   = (int) $s->get( 'redis.db', 0 );
                $timeout = (float) $s->get( 'redis.timeout', 2.0 );

                // §8.1 / §9 — full test sequence with detailed result.
                // Connection-only tests are NOT enough: we must SELECT the configured
                // DB, write a temporary test key, read it back, and delete it.
                $result = array(
                        'ok'        => false,
                        'msg'       => '',
                        'host'      => $host,
                        'port'      => $port,
                        'db'        => $db,
                        'tls'       => $tls,
                        'timestamp' => current_time( 'mysql' ),
                        'phase'     => '',
                );

                if ( '' === $host ) {
                        $result['phase'] = 'config';
                        $result['msg']   = __( 'No Redis host configured.', 'ultimate-performance' );
                        return $result;
                }
                if ( ! class_exists( '\\Redis' ) ) {
                        $result['phase'] = 'extension';
                        $result['msg']   = __( 'Redis PHP extension not available.', 'ultimate-performance' );
                        return $result;
                }

                try {
                        $r = new \Redis();
                        $connected = false;
                        if ( $tls ) {
                                // phpredis TLS via stream context — phpredis 5.3+.
                                $connected = $r->connect( 'tls://' . $host, $port, $timeout ?: 2.0 );
                        } else {
                                $connected = $r->connect( $host, $port, $timeout ?: 2.0 );
                        }
                        if ( ! $connected ) {
                                $result['phase'] = 'connect';
                                $result['msg']   = __( 'Connection refused.', 'ultimate-performance' );
                                return $result;
                        }
                        $result['phase'] = 'connect';
                        if ( '' !== $auth ) {
                                $auth_ok = $r->auth( $auth );
                                if ( true !== $auth_ok && '+OK' !== $auth_ok ) {
                                        $result['phase'] = 'auth';
                                        $result['msg']   = __( 'Authentication failed.', 'ultimate-performance' );
                                        $r->close();
                                        return $result;
                                }
                        }
                        // §9: SELECT the configured DB. A test that connects but never
                        // selects is meaningless when the user explicitly chose DB=3.
                        if ( $db > 0 ) {
                                $sel = $r->select( $db );
                                if ( ! $sel ) {
                                        $result['phase'] = 'select';
                                        $result['msg']   = sprintf( __( 'Database selection failed (db=%d).', 'ultimate-performance' ), $db );
                                        $r->close();
                                        return $result;
                                }
                        }
                        $pong = $r->ping();
                        if ( '+PONG' !== $pong && true !== $pong && 1 !== $pong ) {
                                $result['phase'] = 'ping';
                                $result['msg']   = __( 'PING failed: ', 'ultimate-performance' ) . (string) $pong;
                                $r->close();
                                return $result;
                        }
                        // §9: write a random temporary key, read it back, delete it.
                        $tk = 'ultimate-performance:test:' . wp_generate_password( 12, false );
                        $tv = 'uc-redis-ok-' . time();
                        $written = $r->setEx( $tk, 60, $tv );
                        if ( ! $written ) {
                                $result['phase'] = 'write';
                                $result['msg']   = __( 'Test key write failed.', 'ultimate-performance' );
                                $r->close();
                                return $result;
                        }
                        $read_back = (string) $r->get( $tk );
                        if ( $tv !== $read_back ) {
                                $result['phase'] = 'read';
                                $result['msg']   = __( 'Test key read-back mismatch.', 'ultimate-performance' );
                                $r->del( $tk );
                                $r->close();
                                return $result;
                        }
                        $r->del( $tk );
                        $result['ok']    = true;
                        $result['phase'] = 'verified';
                        $result['msg']   = __( 'Redis connection successful.', 'ultimate-performance' );
                        $r->close();
                } catch ( \RedisException $e ) {
                        $msg = $e->getMessage();
                        $result['phase'] = 'exception';
                        // Classify common failure reasons (per §8.1).
                        if ( false !== stripos( $msg, 'auth' ) || false !== stripos( $msg, 'NOAUTH' ) || false !== stripos( $msg, 'WRONGPASS' ) ) {
                                $result['phase'] = 'auth';
                                $result['msg']   = __( 'Authentication failed: ', 'ultimate-performance' ) . $msg;
                        } elseif ( false !== stripos( $msg, 'timeout' ) || false !== stripos( $msg, 'Connection refused' ) ) {
                                $result['phase'] = 'connect';
                                $result['msg']   = __( 'Connection failed: ', 'ultimate-performance' ) . $msg;
                        } else {
                                $result['msg'] = $msg;
                        }
                } catch ( \Throwable $e ) {
                        $result['phase'] = 'exception';
                        $result['msg']   = $e->getMessage();
                }
                return $result;
        }

        /**
         * §5/§8 — AJAX handler: Test Redis Connection.
         *
         * Sends POST /wp-admin/admin-ajax.php?action=up_ajax_test_redis
         * Returns JSON: { success: true, data: { ok, host, port, db, tls, message, phase, timestamp } }
         *
         * Contract (§8 / §9):
         *   - valid nonce + manage_options capability → 200 with JSON test result
         *   - invalid nonce or insufficient capability → 403 with explicit JSON error
         *   - Redis service failure → 200 with success=true and data.ok=false (NOT a transport failure)
         *
         * Never returns Redis password (§8 contract).
         *
         * @return void Emits JSON and dies.
         */
        public function ajax_test_redis() {
                // §54 — verify nonce FIRST. Use the AJAX-specific nonce action.
                if ( ! check_ajax_referer( 'up_ajax_test_redis', 'nonce', false ) ) {
                        // §10 — explicit JSON 403, not plain '0'.
                        wp_send_json_error(
                                array( 'message' => __( 'Invalid nonce.', 'ultimate-performance' ) ),
                                403
                        );
                        return;
                }
                // §55 — capability check.
                if ( ! $this->capability_ok() ) {
                        wp_send_json_error(
                                array( 'message' => __( 'Insufficient permissions.', 'ultimate-performance' ) ),
                                403
                        );
                        return;
                }
                // §4 — Reuse the SAME shared service method the legacy
                // admin_post handler uses. No business-logic duplication.
                $result = $this->run_redis_test();
                // §8 — response contract. success=true means the AJAX transport
                // succeeded; data.ok is the actual Redis test verdict.
                wp_send_json_success( $result );
        }

        /**
         * Persist the Redis test result as a transient so the Object Cache tab
         * can render it after redirect (§45: result must persist visibly).
         *
         * @param array $result The test result array.
         * @return array Same result (for chaining).
         */
        private function store_redis_test( $result ) {
                set_transient( 'up_redis_test', $result, HOUR_IN_SECONDS );
                return $result;
        }

        /**
         * Redirect back to the Object Cache tab after the test runs.
         *
         * @param array $result Used only to derive the query-arg suffix.
         */
        private function redirect_redis_test( $result ) {
                $tab  = $this->posted_tab( 'object-cache' );
                $arg  = ! empty( $result['ok'] ) ? 'success' : 'failed';
                wp_safe_redirect( add_query_arg( 'up_redis', $arg, $this->tab_url( $tab ) ) );
                exit;
        }

        /**
         * §27.2 — Test Object Cache Runtime button.
         *
         * Verifies WordPress itself is using the Ultimate Performance object cache
         * end-to-end:
         *   1. wp_using_ext_object_cache() === true
         *   2. Drop-in actually loaded (our class is the active cache)
         *   3. Active backend reported honestly (redis/memcached/runtime)
         *   4. wp_cache_set() succeeds (round-trips to persistent backend)
         *   5. wp_cache_get() returns the value
         *   6. wp_cache_delete() removes the key
         *   7. Cross-call persistence: a NEW Manager instance reads the same key
         *      (proves the value is in Redis, not in per-process memory).
         *
         * Distinct from "Test Redis Connection" (§27.1): that proves Redis is
         * reachable + SELECT DB + write/read/del on a one-shot connection.
         * This proves WordPress itself routes through the persistent backend.
         */
        public function handle_test_oc_runtime() {
                if ( ! $this->capability_ok() ) {
                        wp_die( esc_html__( 'Insufficient permissions.', 'ultimate-performance' ), 403 );
                }
                check_admin_referer( 'up_test_oc_runtime', '_ucnonce' );

                // §4 — Single-request legacy transport. Run all three phases
                // back-to-back in ONE request, persist result, redirect.
                // NOTE: the AJAX transport is the cross-request proof the
                // directive mandates; this admin_post_* legacy path is kept
                // only for backwards compatibility.
                $p1 = $this->run_oc_runtime_phase1();
                $result = $p1;
                if ( ! empty( $p1['ok'] ) && empty( $p1['complete'] ) && ! empty( $p1['token'] ) ) {
                        $p2 = $this->run_oc_runtime_phase2( $p1['token'] );
                        $result = array_merge( $result, $p2 );
                        if ( ! empty( $p2['ok'] ) && empty( $p2['complete'] ) ) {
                                $p3 = $this->run_oc_runtime_phase3( $p1['token'] );
                                // Phase 3 returns the final combined result.
                                $result = array_merge( $result, $p3 );
                        } else {
                                // §44 — Phase 2 failed: still attempt safe cleanup.
                                $this->run_oc_runtime_phase3( $p1['token'] );
                        }
                }

                $this->store_oc_runtime_test( $result );
                $this->redirect_oc_runtime_test( $result );
        }

        /**
         * §7/§21 — Object Cache Runtime Test Phase 1: write a unique test
         * object via the WordPress wp_cache_* path so it routes through the
         * active persistent backend (Redis/Memcached).
         *
         * Required contract (§21):
         *   { ok: bool, complete: bool, phase: 'phase1',
         *     next_phase: 'phase2'|null, token: string|null, ...diagnostics }
         *
         * The token is the unique proof key. Phase 2 will read it back in a
         * SEPARATE HTTP request (cross-request proof).
         *
         * @return array Phase 1 result.
         */
        private function run_oc_runtime_phase1() {
                $result = array(
                        'ok'           => false,
                        'complete'     => false,
                        'phase'        => 'phase1',
                        'next_phase'   => null,
                        'token'        => null,
                        'msg'          => '',
                        'timestamp'    => current_time( 'mysql' ),
                        'ext_oc'       => false,
                        'dropin'       => 'absent',
                        'preferred'    => 'runtime',
                        'active'       => 'runtime',
                        'prefix'       => '',
                        'set_ok'       => false,
                        'get_ok'       => false,
                        'delete_ok'    => false,
                        'cross_ok'     => false,
                );

                // §11 — wp_using_ext_object_cache() must be true.
                $result['ext_oc'] = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
                if ( ! $result['ext_oc'] ) {
                        $result['phase'] = 'ext_oc';
                        $result['msg']   = __( 'wp_using_ext_object_cache() is false — drop-in not active.', 'ultimate-performance' );
                        return $result;
                }

                // §12 — drop-in ownership.
                $dropin   = new OcDropin();
                $state    = $dropin->state( WP_CONTENT_DIR );
                $result['dropin'] = $state;
                if ( 'ours' !== $state ) {
                        $result['phase'] = 'dropin';
                        $result['msg']   = sprintf(
                                /* translators: %s: drop-in state */
                                __( 'object-cache.php is %s (not ours).', 'ultimate-performance' ),
                                $state
                        );
                        return $result;
                }

                // §13 — locate the active Manager.
                $our_mgr = null;
                if ( function_exists( 'up_oc' ) ) {
                        $mgr = up_oc();
                        if ( $mgr instanceof \UltimatePerformance\ObjectCache\Manager ) {
                                $our_mgr = $mgr;
                        }
                }
                if ( null === $our_mgr ) {
                        try {
                                $our_mgr = \UltimatePerformance\ObjectCache\Manager::instance();
                        } catch ( \Throwable $e ) {
                                $result['phase'] = 'manager';
                                $result['msg']   = __( 'Manager construction failed: ', 'ultimate-performance' ) . $e->getMessage();
                                return $result;
                        }
                }

                // §14 — backend diagnostics.
                $status              = $our_mgr->runtime_status();
                $result['preferred'] = $status['preferred'];
                $result['active']    = $status['active'];
                $result['prefix']    = $status['prefix'];

                if ( 'runtime' === $status['active'] ) {
                        $result['phase'] = 'backend';
                        $result['msg']   = sprintf(
                                /* translators: 1: preferred backend, 2: active backend */
                                __( 'Preferred backend is %1$s but no persistent backend is healthy (active = %2$s).', 'ultimate-performance' ),
                                $status['preferred'],
                                $status['active']
                        );
                        return $result;
                }

                // §18 — wp_cache_set() round-trip. 300s TTL survives cross-request.
                $proof_key   = 'uc-runtime-proof-' . wp_generate_password( 8, false );
                $proof_value = 'ultimate-performance-redis-working-' . time();
                $proof_group = 'uc-proof-group';
                $set_result  = wp_cache_set( $proof_key, $proof_value, $proof_group, 300 );
                $result['set_ok'] = (bool) $set_result;
                if ( ! $set_result ) {
                        $result['phase'] = 'set';
                        $result['msg']   = __( 'wp_cache_set() returned false.', 'ultimate-performance' );
                        return $result;
                }

                // §21 — same-process read-back. Proves the round-trip succeeds.
                $got = wp_cache_get( $proof_key, $proof_group );
                $result['get_ok'] = ( $proof_value === $got );
                if ( ! $result['get_ok'] ) {
                        $result['phase'] = 'get';
                        $result['msg']   = sprintf(
                                /* translators: %s: returned value */
                                __( 'wp_cache_get() returned %s (expected the set value).', 'ultimate-performance' ),
                                var_export( $got, true ) // phpcs:ignore -- debug only
                        );
                        // Best-effort cleanup.
                        wp_cache_delete( $proof_key, $proof_group );
                        return $result;
                }

                // §21 / §24 — pass the token to the caller. Phase 2 (separate
                // HTTP request in the AJAX transport, OR same-request in the
                // admin_post_* legacy transport) will use it to prove the value
                // survived across requests.
                $result['ok']         = true;
                $result['complete']   = false;
                $result['phase']      = 'phase1';
                $result['next_phase'] = 'phase2';
                $result['token']      = $proof_key;
                $result['_value']     = $proof_value;
                $result['_group']     = $proof_group;
                $result['msg']        = __( 'Phase 1 (write test object) PASS — proceed to Phase 2.', 'ultimate-performance' );
                return $result;
        }

        /**
         * §22/§24 — Object Cache Runtime Test Phase 2: cross-request read.
         *
         * In the AJAX transport this MUST run in a SEPARATE HTTP request from
         * phase1. Required contract (§22):
         *   { ok: bool, complete: bool, phase: 'phase2',
         *     next_phase: 'phase3'|null, token: string, ...diagnostics }
         *
         * @param string $token The proof key written by phase 1.
         * @return array Phase 2 result.
         */
        private function run_oc_runtime_phase2( $token ) {
                $result = array(
                        'ok'           => false,
                        'complete'     => false,
                        'phase'        => 'phase2',
                        'next_phase'   => null,
                        'token'        => $token,
                        'msg'          => '',
                        'cross_ok'     => false,
                        'timestamp'    => current_time( 'mysql' ),
                );

                if ( '' === (string) $token ) {
                        $result['phase'] = 'token';
                        $result['msg']   = __( 'Phase 2 requires a token from Phase 1.', 'ultimate-performance' );
                        return $result;
                }

                // §42 — cross-process proof: reset the Manager runtime cache,
                // then read the token back. If the value lives only in
                // per-process memory, this read will miss.
                $proof_group = 'uc-proof-group';
                $cross = null;
                try {
                        \UltimatePerformance\ObjectCache\Manager::reset_instance();
                        $fresh = \UltimatePerformance\ObjectCache\Manager::instance();
                        $cross = $fresh->get( (string) $token, $proof_group );
                } catch ( \Throwable $e ) {
                        $result['phase'] = 'cross';
                        $result['msg']   = __( 'Cross-process read failed: ', 'ultimate-performance' ) . $e->getMessage();
                        return $result;
                }

                // Phase 2 expects to read back the value written in Phase 1.
                // Accept the universal "ultimate-performance-redis-working-<ts>" prefix.
                $is_valid = is_string( $cross )
                        && 0 === strpos( $cross, 'ultimate-performance-redis-working-' );
                $result['cross_ok'] = $is_valid;
                if ( ! $is_valid ) {
                        $result['phase'] = 'cross';
                        $result['msg']   = sprintf(
                                /* translators: %s: returned value */
                                __( 'Cross-process read returned %s (value is not in persistent backend).', 'ultimate-performance' ),
                                var_export( $cross, true ) // phpcs:ignore -- debug only
                        );
                        return $result;
                }

                $result['ok']         = true;
                $result['complete']   = false;
                $result['phase']      = 'phase2';
                $result['next_phase'] = 'phase3';
                $result['msg']        = __( 'Phase 2 (cross-request wp_cache_get) PASS — proceed to Phase 3.', 'ultimate-performance' );
                return $result;
        }

        /**
         * §23/§43 — Object Cache Runtime Test Phase 3: delete the test
         * object and verify deletion. Required contract (§23):
         *   { ok: bool, complete: bool, phase: 'phase3',
         *     token: string, ...final_diagnostics }
         *
         * §44 — If Phase 2 already failed, this is still called for cleanup.
         *
         * @param string $token The proof key written by phase 1.
         * @return array Phase 3 result (final).
         */
        private function run_oc_runtime_phase3( $token ) {
                $result = array(
                        'ok'           => false,
                        'complete'     => true,
                        'phase'        => 'phase3',
                        'next_phase'   => null,
                        'token'        => $token,
                        'msg'          => '',
                        'delete_ok'    => false,
                        'timestamp'    => current_time( 'mysql' ),
                );

                $proof_group = 'uc-proof-group';

                if ( '' === (string) $token ) {
                        $result['phase'] = 'token';
                        $result['msg']   = __( 'Phase 3 requires a token from Phase 1.', 'ultimate-performance' );
                        return $result;
                }

                // §22 — delete the test object.
                $del_result = wp_cache_delete( (string) $token, $proof_group );
                $result['delete_ok'] = (bool) $del_result;
                if ( ! $del_result ) {
                        $result['phase'] = 'delete';
                        $result['msg']   = __( 'wp_cache_delete() returned false.', 'ultimate-performance' );
                        return $result;
                }

                // Verify deletion: re-read must miss.
                $recheck = wp_cache_get( (string) $token, $proof_group );
                if ( false !== $recheck && null !== $recheck ) {
                        $result['phase']      = 'delete-verify';
                        $result['delete_ok']  = false;
                        $result['msg']        = __( 'Key still readable after delete.', 'ultimate-performance' );
                        return $result;
                }

                $result['ok']    = true;
                $result['phase'] = 'phase3';
                $result['msg']   = __( 'Object Cache Runtime: PASS', 'ultimate-performance' );
                return $result;
        }

        /**
         * §6 — AJAX handler: Object Cache Runtime Test Phase 1 (write).
         *
         * Required separate HTTP request. Contract (§21):
         *   { success: true, data: { ok, complete, phase, next_phase, token, ... } }
         */
        public function ajax_oc_runtime_phase1() {
                if ( ! check_ajax_referer( 'up_ajax_oc_runtime_phase1', 'nonce', false ) ) {
                        wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'ultimate-performance' ) ), 403 );
                        return;
                }
                if ( ! $this->capability_ok() ) {
                        wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ultimate-performance' ) ), 403 );
                        return;
                }
                $result = $this->run_oc_runtime_phase1();
                // Phase 1 may write internal-only fields (_value, _group).
                // Strip them so they never leak into the JSON response.
                unset( $result['_value'], $result['_group'] );
                wp_send_json_success( $result );
        }

        /**
         * §22 — AJAX handler: Object Cache Runtime Test Phase 2 (cross-request read).
         *
         * Required separate HTTP request. The browser supplies the token
         * returned by Phase 1.
         */
        public function ajax_oc_runtime_phase2() {
                if ( ! check_ajax_referer( 'up_ajax_oc_runtime_phase2', 'nonce', false ) ) {
                        wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'ultimate-performance' ) ), 403 );
                        return;
                }
                if ( ! $this->capability_ok() ) {
                        wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ultimate-performance' ) ), 403 );
                        return;
                }
                $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
                $result = $this->run_oc_runtime_phase2( $token );
                wp_send_json_success( $result );
        }

        /**
         * §23 — AJAX handler: Object Cache Runtime Test Phase 3 (delete + verify).
         *
         * Required separate HTTP request. Cleans up the temporary test key.
         */
        public function ajax_oc_runtime_phase3() {
                if ( ! check_ajax_referer( 'up_ajax_oc_runtime_phase3', 'nonce', false ) ) {
                        wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'ultimate-performance' ) ), 403 );
                        return;
                }
                if ( ! $this->capability_ok() ) {
                        wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ultimate-performance' ) ), 403 );
                        return;
                }
                $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
                $result = $this->run_oc_runtime_phase3( $token );
                wp_send_json_success( $result );
        }

        /**
         * Persist the Object Cache runtime test result as a transient so the
         * Object Cache tab can render it after redirect.
         *
         * @param array $result The test result array.
         * @return void
         */
        private function store_oc_runtime_test( $result ) {
                set_transient( 'up_oc_runtime_test', $result, HOUR_IN_SECONDS );
        }

        /**
         * Redirect back to the Object Cache tab after the runtime test runs.
         *
         * @param array $result Used only to derive the query-arg suffix.
         */
        private function redirect_oc_runtime_test( $result ) {
                $tab  = $this->posted_tab( 'object-cache' );
                $arg  = ! empty( $result['ok'] ) ? 'success' : 'failed';
                wp_safe_redirect( add_query_arg( 'up_oc_runtime', $arg, $this->tab_url( $tab ) ) );
                exit;
        }

        public function handle_test_amqp() {
                if ( ! $this->capability_ok() ) {
                        wp_die( esc_html__( 'Insufficient permissions.', 'ultimate-performance' ), 403 );
                }
                check_admin_referer( 'up_test_amqp', '_ucnonce' );
                $s = Settings::instance();
                $host  = (string) $s->get( 'amqp.host', '127.0.0.1' );
                $port  = (int) $s->get( 'amqp.port', 5672 );
                $user  = (string) $s->get( 'amqp.user', 'guest' );
                $pass  = (string) $s->get( 'amqp.pass', '' );
                $vhost = (string) $s->get( 'amqp.vhost', '/' );
                $result = array( 'ok' => false, 'msg' => '' );
                if ( ! class_exists( '\PhpAmqpLib\Connection\AMQPStreamConnection' ) ) {
                        $result['msg'] = __( 'php-amqplib library not available.', 'ultimate-performance' );
                } else {
                        try {
                                $conn = new \PhpAmqpLib\Connection\AMQPStreamConnection( $host, $port, $user, $pass, $vhost, false, '', 3 );
                                $ch = $conn->channel();
                                $ch->close();
                                $conn->close();
                                $result['ok']  = true;
                                $result['msg'] = __( 'Connected.', 'ultimate-performance' );
                        } catch ( \Throwable $e ) {
                                $result['msg'] = $e->getMessage();
                        }
                }
                set_transient( 'up_amqp_test', $result, HOUR_IN_SECONDS );
                $tab = $this->posted_tab( 'queue' );
                wp_safe_redirect( add_query_arg( 'up_amqp', 'test', $this->tab_url( $tab ) ) );
                exit;
        }

        public function handle_oc_install() {
                if ( ! $this->capability_ok() ) {
                        wp_die( esc_html__( 'Insufficient permissions.', 'ultimate-performance' ), 403 );
                }
                check_admin_referer( 'up_oc_install', '_ucnonce' );
                $dropin = new OcDropin();
                $status  = $dropin->ensure( WP_CONTENT_DIR );
                set_transient( 'up_oc_action', $status, HOUR_IN_SECONDS );
                $tab = $this->posted_tab( 'object-cache' );
                wp_safe_redirect( add_query_arg( 'up_oc', 'install', $this->tab_url( $tab ) ) );
                exit;
        }

        public function handle_oc_remove() {
                if ( ! $this->capability_ok() ) {
                        wp_die( esc_html__( 'Insufficient permissions.', 'ultimate-performance' ), 403 );
                }
                check_admin_referer( 'up_oc_remove', '_ucnonce' );
                $dropin = new OcDropin();
                $ok = $dropin->remove( WP_CONTENT_DIR );
                set_transient( 'up_oc_action', array( 'state' => $ok ? 'absent' : 'ours', 'action' => $ok ? 'removed' : 'remove-failed' ), HOUR_IN_SECONDS );
                $tab = $this->posted_tab( 'object-cache' );
                wp_safe_redirect( add_query_arg( 'up_oc', 'remove', $this->tab_url( $tab ) ) );
                exit;
        }

        public function nginx_inputs() {
                $s = Settings::instance();
                $origin = trim( (string) $s->get( 'nginx.origin', '' ) );
                $listen = trim( (string) $s->get( 'nginx.listen', '' ) );
                if ( '' === $origin || '' === $listen ) {
                        return null;
                }
                $host = (string) parse_url( home_url(), PHP_URL_HOST );
                return array(
                        'host'       => $host,
                        'cache_root' => Installer::cache_root(),
                        'origin'     => $origin,
                        'listen'     => $listen,
                        'docroot'    => wp_normalize_path( ABSPATH ),
                );
        }

        public function handle_nginx_verify() {
                if ( ! $this->capability_ok() ) {
                        wp_die( esc_html__( 'Insufficient permissions.', 'ultimate-performance' ), 403 );
                }
                check_admin_referer( 'up_nginx_verify', '_ucnonce' );
                $tab     = $this->posted_tab( 'server-integration' );
                $base    = $this->tab_url( $tab );
                $inputs  = $this->nginx_inputs();
                $snippet = null === $inputs ? '' : Rules::generate( $inputs );
                if ( '' === $snippet ) {
                        set_transient( 'up_nginx_probe', array( 'ok' => false, 'why' => 'inputs' ), HOUR_IN_SECONDS );
                        wp_safe_redirect( add_query_arg( 'up_nginx', 'verify', $base ) );
                        exit;
                }
                $token = substr( bin2hex( random_bytes( 8 ) ), 0, 16 );
                $uri   = Rules::probe_uri( $token );
                $body  = Rules::probe_body( $token );
                $file  = ( new Key( Settings::instance() ) )->absolute( Rules::host_dir( (string) $inputs['host'] ) . '/' . 'uc-verify-' . $token );
                if ( '' === $uri || '' === $body || false === $file ) {
                        set_transient( 'up_nginx_probe', array( 'ok' => false, 'why' => 'inputs' ), HOUR_IN_SECONDS );
                        wp_safe_redirect( add_query_arg( 'up_nginx', 'verify', $base ) );
                        exit;
                }
                $fs     = new SafeFs();
                $written = $fs->write_atomic( $file, $body );
                if ( ! $written ) {
                        set_transient( 'up_nginx_probe', array( 'ok' => false, 'why' => 'write_failed' ), HOUR_IN_SECONDS );
                        wp_safe_redirect( add_query_arg( 'up_nginx', 'verify', $base ) );
                        exit;
                }
                $resp = wp_remote_get( home_url( $uri ), array( 'timeout' => 5, 'sslverify' => false, 'redirection' => 0 ) );
                $why  = $this->classify_probe_response( $resp, $body );
                $fs->delete( $file );
                set_transient( 'up_nginx_probe', array( 'ok' => 'active' === $why, 'why' => $why ), HOUR_IN_SECONDS );
                wp_safe_redirect( add_query_arg( 'up_nginx', 'verify', $base ) );
                exit;
        }

        public function classify_probe_response( $resp, $expected_body ) {
                if ( is_wp_error( $resp ) ) {
                        $msg = strtolower( (string) $resp->get_error_message() );
                        if ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) ) {
                                return 'timeout';
                        }
                        if ( false !== strpos( $msg, 'ssl' ) || false !== strpos( $msg, 'certificate' ) ) {
                                return 'tls_error';
                        }
                        if ( false !== strpos( $msg, 'could not resolve' ) || false !== strpos( $msg, 'dns' ) || false !== strpos( $msg, 'name or service not known' ) ) {
                                return 'dns_error';
                        }
                        return 'unreachable';
                }
                $code = is_array( $resp ) && isset( $resp['response']['code'] ) ? (int) $resp['response']['code'] : 0;
                if ( $code >= 300 && $code < 400 ) {
                        return 'redirected';
                }
                if ( $code >= 500 ) {
                        return 'unreachable';
                }
                $got = is_array( $resp ) && isset( $resp['body'] ) ? (string) $resp['body'] : '';
                if ( '' === $got ) {
                        return 'not_active';
                }
                if ( $got === $expected_body ) {
                        return 'active';
                }
                return 'content_mismatch';
        }

        // ===== Top-level renderer =====

        public function render() {
                if ( ! $this->capability_ok() ) {
                        return;
                }
                $tab = $this->current_tab();
                ?>
                <div class="wrap">
                        <h1><?php esc_html_e( 'Ultimate Performance', 'ultimate-performance' ); ?></h1>

                        <?php $this->render_notices(); ?>

                        <h2 class="nav-tab-wrapper">
                                <?php foreach ( $this->tabs() as $slug => $label ) : ?>
                                        <a href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>"
                                                class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>">
                                                <?php echo esc_html( $label ); ?>
                                        </a>
                                <?php endforeach; ?>
                        </h2>

                        <?php
                        switch ( $tab ) {
                                case 'page-cache':
                                        $this->render_page_cache();
                                        break;
                                case 'object-cache':
                                        $this->render_object_cache();
                                        break;
                                case 'queue':
                                        $this->render_queue();
                                        break;
                                case 'server-integration':
                                        $this->render_server_integration();
                                        break;
                                case 'diagnostics':
                                        $this->render_diagnostics();
                                        break;
                                case 'advanced':
                                        $this->render_advanced();
                                        break;
                                default:
                                        $this->render_dashboard();
                                        break;
                        }
                        ?>
                </div>
                <?php
        }

        /**
         * Global admin notices — shown on every tab so messages from save/test
         * handlers are never lost when the redirect lands on a different section.
         */
        private function render_notices() {
                $saved = isset( $_GET['up_saved'] ) ? sanitize_key( wp_unslash( $_GET['up_saved'] ) ) : '';
                if ( 'summary' === $saved ) {
                        $summary = get_transient( 'up_save_summary' );
                        if ( is_array( $summary ) && ! empty( $summary['changes'] ) ) {
                                echo '<div class="notice notice-success is-dismissible"><p>';
                                esc_html_e( 'Settings saved.', 'ultimate-performance' );
                                echo '</p><ul style="list-style: disc; margin-left: 20px; margin-top: 6px;">';
                                // Human-readable labels for the dot-path keys.
                                $labels = array(
                                        'redis.host'      => __( 'Redis host', 'ultimate-performance' ),
                                        'redis.port'      => __( 'Redis port', 'ultimate-performance' ),
                                        'redis.db'        => __( 'Redis database', 'ultimate-performance' ),
                                        'redis.tls'       => __( 'Redis TLS', 'ultimate-performance' ),
                                        'redis.auth'      => __( 'Redis password', 'ultimate-performance' ),
                                        'memcached.host'  => __( 'Memcached host', 'ultimate-performance' ),
                                        'memcached.port'  => __( 'Memcached port', 'ultimate-performance' ),
                                        'amqp.host'       => __( 'RabbitMQ host', 'ultimate-performance' ),
                                        'amqp.port'       => __( 'RabbitMQ port', 'ultimate-performance' ),
                                        'amqp.user'       => __( 'RabbitMQ user', 'ultimate-performance' ),
                                        'amqp.vhost'      => __( 'RabbitMQ vhost', 'ultimate-performance' ),
                                        'amqp.exchange'   => __( 'RabbitMQ exchange', 'ultimate-performance' ),
                                        'amqp.pass'       => __( 'RabbitMQ password', 'ultimate-performance' ),
                                );
                                foreach ( $summary['changes'] as $key => $op ) {
                                        $label = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
                                        $msg   = self::format_change_message( $label, $op );
                                        printf( '<li>%s</li>', wp_kses_post( $msg ) );
                                }
                                echo '</ul></div>';
                        } else {
                                echo '<div class="notice notice-success is-dismissible"><p>';
                                esc_html_e( 'Settings saved.', 'ultimate-performance' );
                                echo '</p></div>';
                        }
                } elseif ( '' !== $saved ) {
                        echo '<div class="notice notice-success is-dismissible"><p>';
                        esc_html_e( 'Settings saved.', 'ultimate-performance' );
                        echo '</p></div>';
                }

                if ( isset( $_GET['up_error'] ) ) {
                        echo '<div class="notice notice-error"><p>';
                        esc_html_e( 'Validation failed — check the fields below:', 'ultimate-performance' );
                        echo '</p>';
                        $err_details = get_transient( 'up_settings_errors' );
                        if ( is_array( $err_details ) && ! empty( $err_details ) ) {
                                echo '<ul style="list-style: disc; margin-left: 20px;">';
                                foreach ( $err_details as $field => $msg ) {
                                        printf(
                                                '<li><strong>%s</strong>: %s</li>',
                                                esc_html( $field ),
                                                esc_html( $msg )
                                        );
                                }
                                echo '</ul>';
                        }
                        echo '</div>';
                }

                if ( isset( $_GET['up_purge'] ) ) {
                        $pv = sanitize_key( wp_unslash( $_GET['up_purge'] ) );
                        $cls = 'aborted' === $pv ? 'notice-warning' : 'notice-success';
                        echo '<div class="notice ' . esc_attr( $cls ) . '"><p>';
                        if ( 'aborted' === $pv ) {
                                esc_html_e( 'Purge aborted — confirmation checkbox missing.', 'ultimate-performance' );
                        } else {
                                $n = (int) $pv;
                                /* translators: %d: number of cache files purged */
                                echo esc_html( sprintf( __( 'Purged %d cache entries.', 'ultimate-performance' ), $n ) );
                        }
                        echo '</p></div>';
                }
        }

        /**
         * Format a human-readable, color-coded message for a single setting
         * change reported by Settings::last_save_summary().
         *
         * Operations:
         *   'preserved' — secret was kept (blank field did NOT delete it).
         *   'set'       — secret was newly added (was empty, now set).
         *   'cleared'   — secret was explicitly cleared (auth_clear checkbox).
         *   'changed'   — non-secret field was changed to a different value.
         *
         * @param string $label Human-readable field label (already translated).
         * @param string $op    Operation: 'preserved' | 'set' | 'cleared' | 'changed'.
         * @return string HTML markup (use wp_kses_post on output).
         */
        private static function format_change_message( $label, $op ) {
                switch ( $op ) {
                        case 'preserved':
                                return sprintf(
                                        /* translators: 1: field label, 2: green checkmark */
                                        __( '%1$s %2$s preserved (no change).', 'ultimate-performance' ),
                                        '<strong>' . esc_html( $label ) . '</strong>',
                                        '<span style="color:green;">&#10003;</span>'
                                );
                        case 'set':
                                return sprintf(
                                        /* translators: 1: field label, 2: green checkmark */
                                        __( '%1$s %2$s set (new value stored).', 'ultimate-performance' ),
                                        '<strong>' . esc_html( $label ) . '</strong>',
                                        '<span style="color:green;">&#10003;</span>'
                                );
                        case 'cleared':
                                return sprintf(
                                        /* translators: 1: field label, 2: red warning */
                                        __( '%1$s %2$s cleared (explicit user request).', 'ultimate-performance' ),
                                        '<strong>' . esc_html( $label ) . '</strong>',
                                        '<span style="color:#d63638;">&#9888;</span>'
                                );
                        case 'changed':
                                return sprintf(
                                        /* translators: 1: field label, 2: green checkmark */
                                        __( '%1$s %2$s updated.', 'ultimate-performance' ),
                                        '<strong>' . esc_html( $label ) . '</strong>',
                                        '<span style="color:green;">&#10003;</span>'
                                );
                        default:
                                return '<strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $op );
                }
        }

        /**
         * §30/§29 — Render a diagnostic test button as a REAL <button type="button">.
         *
         * WHY this helper exists (root cause of the page-refresh regression):
         * WordPress core get_submit_button() — wp-admin/includes/template.php —
         * hard-codes '<input type="submit"' and only appends $other_attributes
         * AFTER the value attribute. Passing array('type' => 'button') therefore
         * emits a DUPLICATE type attribute:
         *
         *     <input type="submit" ... value="Test Redis Connection" type="button" />
         *
         * Per the HTML5 spec the FIRST type attribute wins, so the element stays
         * a submit button. Inside a <form method="post" action="admin-post.php">
         * a click submits that form → full-page refresh, and the AJAX handler in
         * assets/js/admin.js never runs. This helper emits a genuine
         * <button type="button"> element so the click is a real non-submitting
         * button that the JS click handler (which also calls preventDefault) can
         * intercept. Verified against real WordPress get_submit_button().
         *
         * @param string $label Button label (already translated).
         * @param string $id    DOM id — MUST match the selector in assets/js/admin.js.
         */
        private function test_button( $label, $id ) {
                printf(
                        '<button type="button" id="%s" name="%s" class="button button-secondary up-test-btn">%s</button>',
                        esc_attr( $id ),
                        esc_attr( $id ),
                        esc_html( $label )
                );
        }

        /**
         * Tiny helper: render a save form footer (nonce + tab + submit).
         *
         * @param string $tab   Current tab slug.
         * @param string $label Submit button label (already translated).
         */
        private function save_button( $tab, $label, $subsection = 'general' ) {
                $this->tab_hidden_field( $tab, $subsection );
                submit_button( $label );
        }

        /**
         * Render the Redis connection test result with full §8.1 / §44 detail:
         * host, port, db, TLS, timestamp, and classified failure phase. Used both
         * on the Object Cache form block and the Diagnostics table.
         *
         * @param array $r   The test result array stored in the transient.
         * @param bool  $compact When true (Diagnostics table), render a smaller block.
         */
        private function render_redis_test_result( $r, $compact = false ) {
                if ( ! is_array( $r ) ) {
                        return;
                }
                $ok       = ! empty( $r['ok'] );
                $host     = isset( $r['host'] ) ? (string) $r['host'] : '';
                $port     = isset( $r['port'] ) ? (int) $r['port'] : 0;
                $db       = isset( $r['db'] ) ? (int) $r['db'] : 0;
                $tls      = ! empty( $r['tls'] );
                $phase    = isset( $r['phase'] ) ? (string) $r['phase'] : '';
                $ts       = isset( $r['timestamp'] ) ? (string) $r['timestamp'] : '';
                $msg      = isset( $r['msg'] ) ? (string) $r['msg'] : '';
                $color    = $ok ? 'green' : 'red';
                $mark     = $ok ? '&#10003;' : '&#10007;';
                $tls_lbl  = $tls ? __( 'Enabled', 'ultimate-performance' ) : __( 'Disabled', 'ultimate-performance' );
                $phase_lbl = array(
                        'config'    => __( 'Configuration', 'ultimate-performance' ),
                        'extension' => __( 'PHP Extension', 'ultimate-performance' ),
                        'connect'   => __( 'Connect', 'ultimate-performance' ),
                        'auth'      => __( 'Authenticate', 'ultimate-performance' ),
                        'select'    => __( 'SELECT Database', 'ultimate-performance' ),
                        'ping'      => __( 'PING', 'ultimate-performance' ),
                        'write'     => __( 'Write Test Key', 'ultimate-performance' ),
                        'read'      => __( 'Read Test Key', 'ultimate-performance' ),
                        'verified'  => __( 'Verified', 'ultimate-performance' ),
                        'exception' => __( 'Exception', 'ultimate-performance' ),
                );
                $ph = isset( $phase_lbl[ $phase ] ) ? $phase_lbl[ $phase ] : $phase;
                ?>
                <table class="form-table" role="presentation" style="background:#f9f9f9;border:1px solid #e0e0e0;padding:6px;margin-top:6px;<?php echo $compact ? 'font-size:11px;' : ''; ?>">
                        <tr>
                                <th scope="row" style="width:160px;"><?php esc_html_e( 'Test Result', 'ultimate-performance' ); ?></th>
                                <td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo $mark; ?> <?php echo esc_html( $msg ); ?></span></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Host', 'ultimate-performance' ); ?></th>
                                <td><code><?php echo esc_html( $host ); ?></code></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Port', 'ultimate-performance' ); ?></th>
                                <td><code><?php echo esc_html( (string) $port ); ?></code></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Database', 'ultimate-performance' ); ?></th>
                                <td><code><?php echo esc_html( (string) $db ); ?></code></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'TLS', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $tls_lbl ); ?></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Phase', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $ph ); ?></td>
                        </tr>
                        <?php if ( '' !== $ts ) : ?>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Tested at', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $ts ); ?></td>
                        </tr>
                        <?php endif; ?>
                </table>
                <?php
        }

        /**
         * Render the Object Cache Runtime test result with full §27.2 detail:
         * ext_oc, dropin, preferred, active backend, prefix, set/get/delete
         * round-trip, cross-process persistence. Used on the Object Cache page.
         *
         * @param array $r The test result array stored in the transient.
         */
        private function render_oc_runtime_test_result( $r ) {
                if ( ! is_array( $r ) ) {
                        return;
                }
                $ok        = ! empty( $r['ok'] );
                $color     = $ok ? 'green' : 'red';
                $mark      = $ok ? '&#10003;' : '&#10007;';
                $ext_oc    = ! empty( $r['ext_oc'] );
                $dropin    = isset( $r['dropin'] ) ? (string) $r['dropin'] : '';
                $preferred = isset( $r['preferred'] ) ? (string) $r['preferred'] : '';
                $active    = isset( $r['active'] ) ? (string) $r['active'] : '';
                $prefix    = isset( $r['prefix'] ) ? (string) $r['prefix'] : '';
                $set_ok    = ! empty( $r['set_ok'] );
                $get_ok    = ! empty( $r['get_ok'] );
                $delete_ok = ! empty( $r['delete_ok'] );
                $cross_ok  = ! empty( $r['cross_ok'] );
                $phase     = isset( $r['phase'] ) ? (string) $r['phase'] : '';
                $ts        = isset( $r['timestamp'] ) ? (string) $r['timestamp'] : '';
                $msg       = isset( $r['msg'] ) ? (string) $r['msg'] : '';
                $yes = '<span style="color:green;">&#10003;</span>';
                $no  = '<span style="color:red;">&#10007;</span>';
                ?>
                <table class="form-table" role="presentation" style="background:#f9f9f9;border:1px solid #e0e0e0;padding:6px;margin-top:12px;">
                        <tr>
                                <th scope="row" style="width:240px;"><?php esc_html_e( 'Runtime Test Result', 'ultimate-performance' ); ?></th>
                                <td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo $mark; ?> <?php echo esc_html( $msg ); ?></span></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'wp_using_ext_object_cache', 'ultimate-performance' ); ?></th>
                                <td><?php echo $ext_oc ? $yes . ' true' : $no . ' false'; ?></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'object-cache.php drop-in', 'ultimate-performance' ); ?></th>
                                <td><code><?php echo esc_html( $dropin ); ?></code></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Preferred backend', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $preferred ); ?></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Active backend', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $active ); ?></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Object Cache Prefix', 'ultimate-performance' ); ?></th>
                                <td><code><?php echo esc_html( $prefix ); ?></code></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'wp_cache_set', 'ultimate-performance' ); ?></th>
                                <td><?php echo $set_ok ? $yes . ' PASS' : $no . ' FAIL'; ?></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'wp_cache_get (same process)', 'ultimate-performance' ); ?></th>
                                <td><?php echo $get_ok ? $yes . ' PASS' : $no . ' FAIL'; ?></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'wp_cache_get (cross-process)', 'ultimate-performance' ); ?></th>
                                <td><?php echo $cross_ok ? $yes . ' PASS' : $no . ' FAIL'; ?></td>
                        </tr>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'wp_cache_delete', 'ultimate-performance' ); ?></th>
                                <td><?php echo $delete_ok ? $yes . ' PASS' : $no . ' FAIL'; ?></td>
                        </tr>
                        <?php if ( '' !== $phase ) : ?>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Phase', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $phase ); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ( '' !== $ts ) : ?>
                        <tr>
                                <th scope="row"><?php esc_html_e( 'Tested at', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $ts ); ?></td>
                        </tr>
                        <?php endif; ?>
                </table>
                <?php
        }

        // ===== Dashboard =====

        private function render_dashboard() {
                $s          = Settings::instance();
                $env        = EnvironmentDetector::get_status_summary();
                $probe      = EnvironmentDetector::run_self_test();
                $oc_dropin  = new OcDropin();
                $oc_state   = $oc_dropin->state( WP_CONTENT_DIR );
                $ac_path    = WP_CONTENT_DIR . '/advanced-cache.php';
                $ac_ours    = false;
                $ac_foreign = false;
                $ac_present = file_exists( $ac_path );
                if ( $ac_present && is_readable( $ac_path ) ) {
                        $head = (string) file_get_contents( $ac_path, false, null, 0, 256 );
                        if ( false !== strpos( $head, 'Ultimate Performance' ) ) {
                                $ac_ours = true;
                        } else {
                                $ac_foreign = true;
                        }
                }
                ?>
                <h2><?php esc_html_e( 'Status Overview', 'ultimate-performance' ); ?></h2>
                <table class="form-table" role="presentation">
                        <tr>
                                <th><?php esc_html_e( 'Cache mode', 'ultimate-performance' ); ?></th>
                                <td><span style="font-weight:bold;color:<?php echo esc_attr( $env['mode_color'] ); ?>;"><?php echo esc_html( $env['mode_label'] ); ?></span></td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Web server', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $env['server'] ); ?></td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Self-test', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( ! empty( $probe['cache_writable'] ) ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Cache writable', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Cache not writable', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'advanced-cache.php', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( $ac_ours ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Installed by Ultimate Performance', 'ultimate-performance' ); ?></span>
                                        <?php elseif ( $ac_foreign ) : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Foreign drop-in', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:gray;"><?php esc_html_e( 'Not installed', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'object-cache.php', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( 'ours' === $oc_state ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Installed by Ultimate Performance', 'ultimate-performance' ); ?></span>
                                        <?php elseif ( 'foreign' === $oc_state ) : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Foreign drop-in detected', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:gray;"><?php esc_html_e( 'Not installed', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Cache enabled', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( $s->get( 'enabled' ) ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Active', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Disabled', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                </table>

                <hr>
                <h3><?php esc_html_e( 'Purge', 'ultimate-performance' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Purge the ENTIRE public page cache?', 'ultimate-performance' ) ); ?>');">
                        <input type="hidden" name="action" value="up_purge_all">
                        <input type="hidden" name="confirm" value="yes">
                        <?php wp_nonce_field( 'up_purge_all', '_ucnonce' ); ?>
                        <?php $this->tab_hidden_field( 'dashboard' ); ?>
                        <?php submit_button( __( 'Purge entire page cache', 'ultimate-performance' ), 'delete', 'submit', false ); ?>
                </form>

                <p class="description">
                        <?php
                        echo esc_html(
                                sprintf(
                                        /* translators: %s: cache root path */
                                        __( 'Cache root: %s', 'ultimate-performance' ),
                                        Installer::cache_root()
                                )
                        );
                        ?>
                </p>
                <?php
        }

        // ===== Page Cache =====

        private function render_page_cache() {
                $s    = Settings::instance();
                $tab  = 'page-cache';
                ?>
                <h2><?php esc_html_e( 'Page Cache', 'ultimate-performance' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="up_save_settings">
                        <?php wp_nonce_field( 'up_save_settings', '_ucnonce' ); ?>
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Master switch', 'ultimate-performance' ); ?></th>
                                        <td><label><input type="checkbox" name="up[enabled]" value="1" <?php checked( $s->get( 'enabled' ) ); ?>> <?php esc_html_e( 'Enable Ultimate Performance', 'ultimate-performance' ); ?></label></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Page cache', 'ultimate-performance' ); ?></th>
                                        <td><label><input type="checkbox" name="up[page_cache_enabled]" value="1" <?php checked( $s->get( 'page_cache_enabled' ) ); ?>> <?php esc_html_e( 'Enable page caching', 'ultimate-performance' ); ?></label></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'TTL (seconds)', 'ultimate-performance' ); ?></th>
                                        <td><input type="number" min="30" max="2678400" name="up[ttl]" value="<?php echo esc_attr( (string) $s->get( 'ttl', 3600 ) ); ?>" class="small-text"></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Stale-while-revalidate', 'ultimate-performance' ); ?></th>
                                        <td><label><input type="checkbox" name="up[swr_enabled]" value="1" <?php checked( $s->get( 'swr_enabled' ) ); ?>> <?php esc_html_e( 'Serve stale up to grace window', 'ultimate-performance' ); ?></label></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'SWR grace (seconds)', 'ultimate-performance' ); ?></th>
                                        <td><input type="number" min="0" max="86400" name="up[swr_grace]" value="<?php echo esc_attr( (string) $s->get( 'swr_grace', 300 ) ); ?>" class="small-text">
                                                <p class="description"><?php esc_html_e( 'How long a stale entry may be served while a fresh one is regenerated.', 'ultimate-performance' ); ?></p></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'PHP fallback', 'ultimate-performance' ); ?></th>
                                        <td><label><input type="checkbox" name="up[php_fallback_enabled]" value="1" <?php checked( $s->get( 'php_fallback_enabled', true ) ); ?>> <?php esc_html_e( 'Enable PHP fallback for shared hosting', 'ultimate-performance' ); ?></label></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Unknown query params', 'ultimate-performance' ); ?></th>
                                        <td><select name="up[query_unknown_policy]">
                                                <?php
                                                $policies = array(
                                                        'bypass'  => __( 'BYPASS (safest)', 'ultimate-performance' ),
                                                        'strip'   => __( 'Strip from key', 'ultimate-performance' ),
                                                        'variant' => __( 'Cache as variants', 'ultimate-performance' ),
                                                );
                                                foreach ( $policies as $v => $label ) :
                                                        ?>
                                                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $s->get( 'query_unknown_policy', 'bypass' ), $v ); ?>><?php echo esc_html( $label ); ?></option>
                                                <?php endforeach; ?>
                                        </select></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Herd protection', 'ultimate-performance' ); ?></th>
                                        <td><label><input type="checkbox" name="up[herd_protection]" value="1" <?php checked( $s->get( 'herd_protection', true ) ); ?>> <?php esc_html_e( 'Single-flight cache generation (prevents stampedes)', 'ultimate-performance' ); ?></label></td>
                                </tr>
                        </table>
                        <?php $this->save_button( $tab, __( 'Save Page Cache Settings', 'ultimate-performance' ), 'general' ); ?>
                </form>
                <?php
        }

        // ===== Object Cache =====

        private function render_object_cache() {
                $s          = Settings::instance();
                $tab        = 'object-cache';
                $oc_dropin  = new OcDropin();
                $oc_state   = $oc_dropin->state( WP_CONTENT_DIR );
                $oc_action  = get_transient( 'up_oc_action' );
                $redis_test = get_transient( 'up_redis_test' );
                $has_redis  = class_exists( '\Redis' );
                $has_memc   = class_exists( '\Memcached' );

                // Inline memcached probe (no dedicated handler — runtime connectivity check).
                $memc_status = '';
                if ( $has_memc ) {
                        try {
                                $m = new \Memcached();
                                $m->setOption( \Memcached::OPT_CONNECT_TIMEOUT, 1500 );
                                $ok = $m->addServer( (string) $s->get( 'memcached.host', '127.0.0.1' ), (int) $s->get( 'memcached.port', 11211 ) );
                                if ( $ok ) {
                                        $m->set( 'up_probe', '1', 5 );
                                        $got = $m->get( 'up_probe' );
                                        $memc_status = '1' === $got
                                                ? '<span style="color:green;">&#10003; ' . esc_html__( 'Connected.', 'ultimate-performance' ) . '</span>'
                                                : '<span style="color:red;">&#10007; ' . esc_html__( 'Set/get round-trip failed.', 'ultimate-performance' ) . '</span>';
                                } else {
                                        $memc_status = '<span style="color:red;">&#10007; ' . esc_html__( 'Could not add server.', 'ultimate-performance' ) . '</span>';
                                }
                                $m->quit();
                        } catch ( \Throwable $e ) {
                                $memc_status = '<span style="color:red;">&#10007; ' . esc_html( $e->getMessage() ) . '</span>';
                        }
                } else {
                        $memc_status = '<span style="color:red;">&#10007; ' . esc_html__( 'Memcached extension not available.', 'ultimate-performance' ) . '</span>';
                }
                ?>
                <h2><?php esc_html_e( 'Object Cache', 'ultimate-performance' ); ?></h2>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="up_save_settings">
                        <?php wp_nonce_field( 'up_save_settings', '_ucnonce' ); ?>
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Object cache', 'ultimate-performance' ); ?></th>
                                        <td><label><input type="checkbox" name="up[object_cache_enabled]" value="1" <?php checked( $s->get( 'object_cache_enabled' ) ); ?>> <?php esc_html_e( 'Enable object cache', 'ultimate-performance' ); ?></label></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Object Cache Prefix', 'ultimate-performance' ); ?></th>
                                        <td><input type="text" name="up[object_cache_prefix]" value="<?php echo esc_attr( (string) $s->get( 'object_cache_prefix', '' ) ); ?>" placeholder="<?php esc_attr_e( 'Auto (from site hostname)', 'ultimate-performance' ); ?>" class="regular-text" maxlength="64">
                                                <p class="description"><?php esc_html_e( 'Prefix used to isolate this site\'s object-cache keys. Leave empty to automatically use the site hostname.', 'ultimate-performance' ); ?></p>
                                                <?php
                                                $auto_prefix = \UltimatePerformance\ObjectCache\Manager::generate_prefix_from_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
                                                if ( '' === (string) $s->get( 'object_cache_prefix', '' ) && '' !== $auto_prefix ) :
                                                        ?>
                                                        <p class="description"><em><?php echo esc_html( sprintf( __( 'Automatic prefix: %s', 'ultimate-performance' ), $auto_prefix ) ); ?></em></p>
                                                <?php endif; ?>
                                        </td>
                                </tr>
                        </table>
                        <?php $this->save_button( $tab, __( 'Save Object Cache Settings', 'ultimate-performance' ), 'general' ); ?>
                </form>

                <h3><?php esc_html_e( 'object-cache.php drop-in', 'ultimate-performance' ); ?></h3>
                <table class="form-table" role="presentation">
                        <tr>
                                <th><?php esc_html_e( 'State', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( 'ours' === $oc_state ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Installed by Ultimate Performance', 'ultimate-performance' ); ?></span>
                                        <?php elseif ( 'foreign' === $oc_state ) : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Foreign drop-in detected — cannot install', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:gray;"><?php esc_html_e( 'Not installed', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                        <br>
                                        <?php if ( 'absent' === $oc_state ) : ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                        <input type="hidden" name="action" value="up_oc_install">
                                                        <?php wp_nonce_field( 'up_oc_install', '_ucnonce' ); ?>
                                                        <?php $this->tab_hidden_field( $tab ); ?>
                                                        <?php submit_button( __( 'Install object-cache.php', 'ultimate-performance' ), 'secondary', 'submit', false ); ?>
                                                </form>
                                        <?php elseif ( 'ours' === $oc_state ) : ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                        <input type="hidden" name="action" value="up_oc_remove">
                                                        <?php wp_nonce_field( 'up_oc_remove', '_ucnonce' ); ?>
                                                        <?php $this->tab_hidden_field( $tab ); ?>
                                                        <?php submit_button( __( 'Remove object-cache.php', 'ultimate-performance' ), 'delete', 'submit', false ); ?>
                                                </form>
                                        <?php endif; ?>
                                        <?php if ( $oc_action ) : ?>
                                                <p class="description"><?php echo esc_html( sprintf( __( 'Last action: %s', 'ultimate-performance' ), $oc_action['action'] ) ); ?></p>
                                        <?php endif; ?>
                                </td>
                        </tr>
                </table>

                <h3><?php esc_html_e( 'Backend availability', 'ultimate-performance' ); ?></h3>
                <table class="widefat" style="max-width:600px;">
                        <thead>
                                <tr>
                                        <th><?php esc_html_e( 'Backend', 'ultimate-performance' ); ?></th>
                                        <th><?php esc_html_e( 'Extension', 'ultimate-performance' ); ?></th>
                                        <th><?php esc_html_e( 'Status', 'ultimate-performance' ); ?></th>
                                </tr>
                        </thead>
                        <tbody>
                                <tr>
                                        <td><?php esc_html_e( 'Redis', 'ultimate-performance' ); ?></td>
                                        <td><?php echo $has_redis ? esc_html__( 'Available', 'ultimate-performance' ) : esc_html__( 'Missing', 'ultimate-performance' ); ?></td>
                                        <td><?php echo $has_redis ? '<span style="color:green;">&#10003;</span>' : '<span style="color:red;">&#10007;</span>'; ?></td>
                                </tr>
                                <tr>
                                        <td><?php esc_html_e( 'Memcached', 'ultimate-performance' ); ?></td>
                                        <td><?php echo $has_memc ? esc_html__( 'Available', 'ultimate-performance' ) : esc_html__( 'Missing', 'ultimate-performance' ); ?></td>
                                        <td><?php echo $has_memc ? '<span style="color:green;">&#10003;</span>' : '<span style="color:red;">&#10007;</span>'; ?></td>
                                </tr>
                                <tr>
                                        <td><?php esc_html_e( 'APCu', 'ultimate-performance' ); ?></td>
                                        <td><?php echo function_exists( 'apcu_fetch' ) ? esc_html__( 'Available', 'ultimate-performance' ) : esc_html__( 'Missing', 'ultimate-performance' ); ?></td>
                                        <td><?php echo function_exists( 'apcu_fetch' ) ? '<span style="color:green;">&#10003;</span>' : '<span style="color:red;">&#10007;</span>'; ?></td>
                                </tr>
                                <tr>
                                        <td><?php esc_html_e( 'SQLite', 'ultimate-performance' ); ?></td>
                                        <td><?php echo extension_loaded( 'pdo_sqlite' ) ? esc_html__( 'Available', 'ultimate-performance' ) : esc_html__( 'Missing', 'ultimate-performance' ); ?></td>
                                        <td><?php echo extension_loaded( 'pdo_sqlite' ) ? '<span style="color:green;">&#10003;</span>' : '<span style="color:red;">&#10007;</span>'; ?></td>
                                </tr>
                                <tr>
                                        <td><?php esc_html_e( 'File', 'ultimate-performance' ); ?></td>
                                        <td><?php esc_html_e( 'Always', 'ultimate-performance' ); ?></td>
                                        <td><span style="color:green;">&#10003;</span></td>
                                </tr>
                        </tbody>
                </table>

                <?php if ( $has_redis ) : ?>
                        <h3><?php esc_html_e( 'Redis', 'ultimate-performance' ); ?></h3>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="up_save_settings">
                                <?php wp_nonce_field( 'up_save_settings', '_ucnonce' ); ?>
                                <table class="form-table" role="presentation">
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'Redis host', 'ultimate-performance' ); ?></th>
                                                <td><input type="text" name="up[redis][host]" value="<?php echo esc_attr( (string) $s->get( 'redis.host', '127.0.0.1' ) ); ?>" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'Redis port', 'ultimate-performance' ); ?></th>
                                                <td><input type="number" min="1" max="65535" name="up[redis][port]" value="<?php echo esc_attr( (string) $s->get( 'redis.port', 6379 ) ); ?>" class="small-text"></td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'Redis database', 'ultimate-performance' ); ?></th>
                                                <td><input type="number" min="0" max="15" name="up[redis][db]" value="<?php echo esc_attr( (string) $s->get( 'redis.db', 0 ) ); ?>" class="small-text"></td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'Redis password', 'ultimate-performance' ); ?></th>
                                                <td><input type="password" name="up[redis][auth]" value="" placeholder="<?php esc_attr_e( 'Leave blank to keep current', 'ultimate-performance' ); ?>" class="regular-text" autocomplete="new-password">
                                                        <?php
                                                        $redis_auth = (string) $s->get( 'redis.auth', '' );
                                                        $auth_set   = '' !== $redis_auth;
                                                        printf(
                                                                '<p class="description" style="font-weight:600;">%s %s</p>',
                                                                $auth_set
                                                                        ? '<span style="color:green;">&#10003;</span>'
                                                                        : '<span style="color:gray;">&#10007;</span>',
                                                                $auth_set ? esc_html__( 'Password configured: yes (will be preserved when this field is left blank)', 'ultimate-performance' ) : esc_html__( 'Password configured: no', 'ultimate-performance' )
                                                        );
                                                        ?>
                                                        <p class="description"><?php esc_html_e( 'Leave blank to preserve current password — blank does NOT delete the stored password.', 'ultimate-performance' ); ?></p>
                                                        <?php if ( $auth_set ) : ?>
                                                                <label style="display:block;margin-top:6px;"><input type="checkbox" name="up[redis][auth_clear]" value="1"> <?php esc_html_e( 'Clear saved Redis password', 'ultimate-performance' ); ?>
                                                                        <span style="color:#d63638;">&#9888;</span> <?php esc_html_e( '(requires confirmation)', 'ultimate-performance' ); ?>
                                                                </label>
                                                        <?php endif; ?>
                                                </td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'Redis TLS', 'ultimate-performance' ); ?></th>
                                                <td><label><input type="checkbox" name="up[redis][tls]" value="1" <?php checked( $s->get( 'redis.tls', false ) ); ?>> <?php esc_html_e( 'Use TLS', 'ultimate-performance' ); ?></label></td>
                                        </tr>
                                </table>
                                <?php $this->save_button( $tab, __( 'Save Redis Settings', 'ultimate-performance' ), 'redis' ); ?>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="up_test_redis">
                                <?php wp_nonce_field( 'up_test_redis', '_ucnonce' ); ?>
                                <?php $this->tab_hidden_field( $tab ); ?>
                                <?php
                                // §16/§17/§30 — Test buttons MUST be type="button" (not the
                                // default "submit") so they do NOT trigger form submission.
                                // The JS in assets/js/admin.js attaches click handlers via
                                // the id="up-test-redis-btn" selector and intercepts the
                                // click to perform AJAX instead. The legacy admin_post_*
                                // path remains available if JS is disabled.
                                // NOTE: WP core submit_button() cannot emit type="button"
                                // (duplicate type= attribute → first wins → submit).
                                $this->test_button( __( 'Test Redis Connection', 'ultimate-performance' ), 'up-test-redis-btn' );
                                ?>
                        </form>

                        <?php if ( $redis_test ) : ?>
                                <?php $this->render_redis_test_result( $redis_test ); ?>
                        <?php endif; ?>

                        <?php
                        // §27.2 — Test Object Cache Runtime button + result.
                        // This is a SEPARATE test from "Test Redis Connection": it exercises
                        // the actual WordPress wp_cache_* path, not a one-shot connection.
                        $oc_runtime_test = get_transient( 'up_oc_runtime_test' );
                        ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-top: 12px;">
                                <input type="hidden" name="action" value="up_test_oc_runtime">
                                <?php wp_nonce_field( 'up_test_oc_runtime', '_ucnonce' ); ?>
                                <?php $this->tab_hidden_field( $tab ); ?>
                                <?php
                                // §17 — type="button" prevents form submission. JS handles
                                // the 3-phase AJAX chain (phase1 → phase2 → phase3).
                                $this->test_button( __( 'Test Object Cache Runtime', 'ultimate-performance' ), 'up-test-oc-runtime-btn' );
                                ?>
                        </form>

                        <?php if ( $oc_runtime_test ) : ?>
                                <?php $this->render_oc_runtime_test_result( $oc_runtime_test ); ?>
                        <?php endif; ?>
                <?php else : ?>
                        <p><span style="color:red;">&#10007; <?php esc_html_e( 'Redis PHP extension not available — Redis fields hidden.', 'ultimate-performance' ); ?></span></p>
                <?php endif; ?>

                <?php if ( $has_memc ) : ?>
                        <h3><?php esc_html_e( 'Memcached', 'ultimate-performance' ); ?></h3>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="up_save_settings">
                                <?php wp_nonce_field( 'up_save_settings', '_ucnonce' ); ?>
                                <table class="form-table" role="presentation">
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'Memcached host', 'ultimate-performance' ); ?></th>
                                                <td><input type="text" name="up[memcached][host]" value="<?php echo esc_attr( (string) $s->get( 'memcached.host', '127.0.0.1' ) ); ?>" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'Memcached port', 'ultimate-performance' ); ?></th>
                                                <td><input type="number" min="1" max="65535" name="up[memcached][port]" value="<?php echo esc_attr( (string) $s->get( 'memcached.port', 11211 ) ); ?>" class="small-text"></td>
                                        </tr>
                                </table>
                                <?php $this->save_button( $tab, __( 'Save Memcached Settings', 'ultimate-performance' ), 'memcached' ); ?>
                        </form>
                        <p class="description"><?php echo $memc_status; // already escaped above ?></p>
                <?php else : ?>
                        <p><span style="color:red;">&#10007; <?php esc_html_e( 'Memcached PHP extension not available — Memcached fields hidden.', 'ultimate-performance' ); ?></span></p>
                <?php endif; ?>
                <?php
        }

        // ===== Queue =====

        private function render_queue() {
                $s          = Settings::instance();
                $tab        = 'queue';
                $qb         = (string) $s->get( 'queue_backend', 'wp-cron' );
                $amqp_test  = get_transient( 'up_amqp_test' );
                $has_amqp   = class_exists( '\PhpAmqpLib\Connection\AMQPStreamConnection' );
                $show_amqp  = in_array( $qb, array( 'rabbitmq', 'auto' ), true );
                ?>
                <h2><?php esc_html_e( 'Queue / Background Jobs', 'ultimate-performance' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="up_save_settings">
                        <?php wp_nonce_field( 'up_save_settings', '_ucnonce' ); ?>
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Queue', 'ultimate-performance' ); ?></th>
                                        <td><label><input type="checkbox" name="up[queue_enabled]" value="1" <?php checked( $s->get( 'queue_enabled' ) ); ?>> <?php esc_html_e( 'Enable background invalidation queue', 'ultimate-performance' ); ?></label></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Queue backend', 'ultimate-performance' ); ?></th>
                                        <td><select name="up[queue_backend]" id="up_queue_backend">
                                                <?php
                                                $backends = array(
                                                        'wp-cron'          => __( 'WP-Cron', 'ultimate-performance' ),
                                                        'rabbitmq'         => __( 'RabbitMQ', 'ultimate-performance' ),
                                                        'action-scheduler' => __( 'Action Scheduler', 'ultimate-performance' ),
                                                        'local'            => __( 'Local', 'ultimate-performance' ),
                                                        'sync'             => __( 'Synchronous', 'ultimate-performance' ),
                                                        'auto'             => __( 'Automatic', 'ultimate-performance' ),
                                                );
                                                foreach ( $backends as $v => $label ) :
                                                        ?>
                                                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $qb, $v ); ?>><?php echo esc_html( $label ); ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e( 'Automatic tries RabbitMQ first, then falls back to Action Scheduler, WP-Cron, and synchronous.', 'ultimate-performance' ); ?></p></td>
                                </tr>
                        </table>

                        <div id="up_rabbitmq_fields" style="<?php echo $show_amqp ? '' : 'display:none;'; ?>">
                                <h3><?php esc_html_e( 'RabbitMQ', 'ultimate-performance' ); ?></h3>
                                <table class="form-table" role="presentation">
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'RabbitMQ host', 'ultimate-performance' ); ?></th>
                                                <td><input type="text" name="up[amqp][host]" value="<?php echo esc_attr( (string) $s->get( 'amqp.host', '127.0.0.1' ) ); ?>" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'RabbitMQ port', 'ultimate-performance' ); ?></th>
                                                <td><input type="number" min="1" max="65535" name="up[amqp][port]" value="<?php echo esc_attr( (string) $s->get( 'amqp.port', 5672 ) ); ?>" class="small-text"></td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'RabbitMQ username', 'ultimate-performance' ); ?></th>
                                                <td><input type="text" name="up[amqp][user]" value="<?php echo esc_attr( (string) $s->get( 'amqp.user', 'guest' ) ); ?>" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'RabbitMQ password', 'ultimate-performance' ); ?></th>
                                                <td><input type="password" name="up[amqp][pass]" value="" placeholder="<?php esc_attr_e( 'Leave blank to keep current', 'ultimate-performance' ); ?>" class="regular-text" autocomplete="new-password">
                                                        <?php
                                                        $amqp_pass = (string) $s->get( 'amqp.pass', '' );
                                                        $pass_set  = '' !== $amqp_pass;
                                                        printf(
                                                                '<p class="description" style="font-weight:600;">%s %s</p>',
                                                                $pass_set
                                                                        ? '<span style="color:green;">&#10003;</span>'
                                                                        : '<span style="color:gray;">&#10007;</span>',
                                                                $pass_set ? esc_html__( 'Password configured: yes (will be preserved when this field is left blank)', 'ultimate-performance' ) : esc_html__( 'Password configured: no', 'ultimate-performance' )
                                                        );
                                                        ?>
                                                        <p class="description"><?php esc_html_e( 'Leave blank to preserve current password — blank does NOT delete the stored password.', 'ultimate-performance' ); ?></p>
                                                        <?php if ( $pass_set ) : ?>
                                                                <label style="display:block;margin-top:6px;"><input type="checkbox" name="up[amqp][pass_clear]" value="1"> <?php esc_html_e( 'Clear saved RabbitMQ password', 'ultimate-performance' ); ?>
                                                                        <span style="color:#d63638;">&#9888;</span> <?php esc_html_e( '(requires confirmation)', 'ultimate-performance' ); ?>
                                                                </label>
                                                        <?php endif; ?>
                                                </td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'RabbitMQ vhost', 'ultimate-performance' ); ?></th>
                                                <td><input type="text" name="up[amqp][vhost]" value="<?php echo esc_attr( (string) $s->get( 'amqp.vhost', '/' ) ); ?>" class="regular-text"></td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><?php esc_html_e( 'RabbitMQ exchange', 'ultimate-performance' ); ?></th>
                                                <td><input type="text" name="up[amqp][exchange]" value="<?php echo esc_attr( (string) $s->get( 'amqp.exchange', 'ultimate-performance' ) ); ?>" class="regular-text"></td>
                                        </tr>
                                </table>
                        </div>

                        <?php $this->save_button( $tab, __( 'Save Queue Settings', 'ultimate-performance' ), 'general' ); ?>
                </form>

                <?php if ( $show_amqp ) : ?>
                        <?php if ( $has_amqp ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="up_test_amqp">
                                        <?php wp_nonce_field( 'up_test_amqp', '_ucnonce' ); ?>
                                        <?php $this->tab_hidden_field( $tab ); ?>
                                        <?php submit_button( __( 'Test RabbitMQ Connection', 'ultimate-performance' ), 'secondary', 'submit', false ); ?>
                                </form>
                        <?php else : ?>
                                <p><span style="color:red;">&#10007; <?php esc_html_e( 'php-amqplib library not available.', 'ultimate-performance' ); ?></span></p>
                        <?php endif; ?>

                        <?php if ( $amqp_test ) : ?>
                                <p class="description"><?php echo $amqp_test['ok'] ? '<span style="color:green;">&#10003; ' . esc_html( $amqp_test['msg'] ) . '</span>' : '<span style="color:red;">&#10007; ' . esc_html( $amqp_test['msg'] ) . '</span>'; ?></p>
                        <?php endif; ?>
                <?php endif; ?>

                <script>
                (function(){
                        var sel = document.getElementById('up_queue_backend');
                        var rabbit = document.getElementById('up_rabbitmq_fields');
                        if (!sel || !rabbit) return;
                        function update(){
                                var v = sel.value;
                                rabbit.style.display = (v === 'rabbitmq' || v === 'auto') ? '' : 'none';
                        }
                        sel.addEventListener('change', update);
                        update();
                })();
                </script>
                <?php
        }

        // ===== Server Integration =====

        private function render_server_integration() {
                $s            = Settings::instance();
                $tab          = 'server-integration';
                $server       = EnvironmentDetector::detect_server();
                $env          = EnvironmentDetector::get_status_summary();
                $nginx_probe  = get_transient( 'up_nginx_probe' );
                $inputs       = $this->nginx_inputs();
                $server_labels = array(
                        'nginx'     => __( 'Nginx', 'ultimate-performance' ),
                        'apache'    => __( 'Apache', 'ultimate-performance' ),
                        'litespeed' => __( 'LiteSpeed', 'ultimate-performance' ),
                        'ols'       => __( 'OpenLiteSpeed', 'ultimate-performance' ),
                        'iis'       => __( 'Microsoft IIS', 'ultimate-performance' ),
                        'unknown'   => __( 'Unknown', 'ultimate-performance' ),
                );
                $server_label = isset( $server_labels[ $server ] ) ? $server_labels[ $server ] : ucfirst( $server );
                ?>
                <h2><?php esc_html_e( 'Server Integration', 'ultimate-performance' ); ?></h2>

                <table class="form-table" role="presentation">
                        <tr>
                                <th><?php esc_html_e( 'Detected server', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $server_label ); ?></td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Serving mode', 'ultimate-performance' ); ?></th>
                                <td>
                                        <span style="font-weight:bold;color:<?php echo esc_attr( $env['mode_color'] ); ?>;font-size:1.1em;">
                                                <?php echo esc_html( $env['mode_label'] ); ?>
                                        </span>
                                        <?php if ( ! empty( $env['details']['reason'] ) ) : ?>
                                                <p class="description" style="margin-top:4px;margin-bottom:0;"><?php echo esc_html( $env['details']['reason'] ); ?></p>
                                        <?php endif; ?>
                                        <?php
                                        // §0.6.8 — show a quick mode legend so users understand what each mode means.
                                        $mode_legend = array(
                                                'Hybrid (Best)'        => __( 'Web server (Nginx/Apache/LiteSpeed) serves cached pages directly with zero PHP. The PHP fallback drop-in is also active as a backup for edge cases. Best of both worlds.', 'ultimate-performance' ),
                                                'Server Accelerated'    => __( 'Web server (Nginx/Apache/LiteSpeed) serves cached pages directly — zero PHP on cache HITs. Maximum performance. No PHP fallback drop-in is installed.', 'ultimate-performance' ),
                                                'PHP Compatibility'    => __( 'PHP fallback drop-in (advanced-cache.php) serves cached pages before WordPress fully boots. Works on shared hosting without Nginx config changes. Slightly slower than Server Accelerated but no server access required.', 'ultimate-performance' ),
                                                'Misconfigured'        => __( 'No cache serving path is active. Either install the PHP fallback drop-in OR configure Nginx server acceleration rules. Cached pages will be MISS until fixed.', 'ultimate-performance' ),
                                                'Disabled'              => __( 'Plugin or page cache is disabled in settings. No pages are being cached.', 'ultimate-performance' ),
                                                'Conflicted'           => __( 'A foreign advanced-cache.php drop-in was detected. Cannot install our drop-in until the foreign one is removed. Common conflict sources: other caching plugins (W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Rocket).', 'ultimate-performance' ),
                                        );
                                        $current_label = $env['mode_label'];
                                        if ( isset( $mode_legend[ $current_label ] ) ) :
                                                ?>
                                                <p class="description" style="margin-top:6px;color:#666;font-style:italic;">
                                                        <?php echo esc_html( $mode_legend[ $current_label ] ); ?>
                                                </p>
                                        <?php endif; ?>
                                </td>
                        </tr>
                </table>

                <?php
                // §0.6.8 — Performance hint comparing current mode to the best achievable.
                $mode_performance = array(
                        'Hybrid (Best)'     => array( 'speed' => 'Maximum', 'ttfb' => '< 5ms', 'php' => '0% on HITs' ),
                        'Server Accelerated' => array( 'speed' => 'Maximum', 'ttfb' => '< 5ms', 'php' => '0% on HITs' ),
                        'PHP Compatibility' => array( 'speed' => 'Good',    'ttfb' => '~25ms', 'php' => '~5% on HITs (drop-in bootstrap)' ),
                        'Misconfigured'     => array( 'speed' => 'None',    'ttfb' => '~1000ms+', 'php' => '100% (no cache)' ),
                        'Disabled'          => array( 'speed' => 'None',    'ttfb' => '~1000ms+', 'php' => '100% (no cache)' ),
                        'Conflicted'        => array( 'speed' => 'None',    'ttfb' => '~1000ms+', 'php' => '100% (no cache)' ),
                );
                if ( isset( $mode_performance[ $current_label ] ) ) :
                        $perf = $mode_performance[ $current_label ];
                        ?>
                        <p class="description" style="margin-top:8px;">
                                <strong><?php esc_html_e( 'Current performance:', 'ultimate-performance' ); ?></strong>
                                <?php echo esc_html( sprintf( __( 'Speed: %s | TTFB: %s | PHP usage: %s', 'ultimate-performance' ), $perf['speed'], $perf['ttfb'], $perf['php'] ) ); ?>
                        </p>
                        <?php
                        // If user is in PHP_FALLBACK, show a tip to upgrade.
                        if ( 'PHP Compatibility' === $current_label ) :
                                ?>
                                <p class="description" style="margin-top:4px;color:#d63603;">
                                        ⚡ <?php echo wp_kses_post( __( '<strong>Tip:</strong> To reach "Hybrid (Best)" mode (5× faster), click "Verify Nginx rules" below after configuring Nginx acceleration. See <code>examples/nginx.sample.conf</code> in the plugin for a sample configuration.', 'ultimate-performance' ) ); ?>
                                </p>
                        <?php endif; ?>
                <?php endif; ?>

                <p class="description"><?php esc_html_e( 'Optional. These settings are only needed when configuring server-level Nginx acceleration. If you are using shared hosting or do not have access to your Nginx configuration, leave these fields empty. Ultimate Performance can use PHP fallback mode instead.', 'ultimate-performance' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="up_save_settings">
                        <?php wp_nonce_field( 'up_save_settings', '_ucnonce' ); ?>
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Nginx origin', 'ultimate-performance' ); ?></th>
                                        <td><input type="text" name="up[nginx][origin]" value="<?php echo esc_attr( (string) $s->get( 'nginx.origin', '' ) ); ?>" placeholder="127.0.0.1:8080" class="regular-text" maxlength="21">
                                                <p class="description"><?php esc_html_e( 'IP:port of the PHP origin. Leave empty for shared hosting / PHP fallback mode.', 'ultimate-performance' ); ?></p></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Nginx listen', 'ultimate-performance' ); ?></th>
                                        <td><input type="text" name="up[nginx][listen]" value="<?php echo esc_attr( (string) $s->get( 'nginx.listen', '' ) ); ?>" placeholder="127.0.0.1:80" class="regular-text" maxlength="21">
                                                <p class="description"><?php esc_html_e( 'Leave empty for shared hosting / PHP fallback mode.', 'ultimate-performance' ); ?></p></td>
                                </tr>
                        </table>
                        <?php $this->save_button( $tab, __( 'Save Nginx Settings', 'ultimate-performance' ), 'general' ); ?>
                </form>

                <hr>
                <h3><?php esc_html_e( 'Nginx verify probe', 'ultimate-performance' ); ?></h3>

                <?php if ( $nginx_probe ) : ?>
                        <div class="notice <?php echo ! empty( $nginx_probe['ok'] ) ? 'notice-success' : 'notice-warning'; ?>"><p>
                                <?php
                                $msgs = array(
                                        'active'          => __( 'Nginx integration ACTIVE — verify probe served statically.', 'ultimate-performance' ),
                                        'inputs'          => __( 'Verify skipped: configure the Nginx origin + listen first.', 'ultimate-performance' ),
                                        'write_failed'    => __( 'Verify failed: could not write the probe file.', 'ultimate-performance' ),
                                        'not_active'      => __( 'Nginx integration NOT active — probe body empty.', 'ultimate-performance' ),
                                        'content_mismatch'=> __( 'Nginx integration NOT active — body mismatch.', 'ultimate-performance' ),
                                        'redirected'      => __( 'Nginx integration NOT active — got redirect.', 'ultimate-performance' ),
                                        'unreachable'     => __( 'Server UNREACHABLE.', 'ultimate-performance' ),
                                        'dns_error'       => __( 'DNS error.', 'ultimate-performance' ),
                                        'tls_error'       => __( 'TLS error.', 'ultimate-performance' ),
                                        'timeout'         => __( 'Timeout.', 'ultimate-performance' ),
                                );
                                $why = isset( $nginx_probe['why'] ) ? $nginx_probe['why'] : '';
                                echo esc_html( isset( $msgs[ $why ] ) ? $msgs[ $why ] : sprintf( __( 'Unknown: %s', 'ultimate-performance' ), $why ) );
                                ?>
                        </p></div>
                <?php endif; ?>

                <?php if ( null === $inputs ) : ?>
                        <p class="description"><?php esc_html_e( 'Configure Nginx origin and Nginx listen above, save, then copy the snippet below into your nginx configuration and reload nginx.', 'ultimate-performance' ); ?></p>
                <?php else : ?>
                        <?php $snippet = Rules::generate( $inputs ); ?>
                        <?php if ( '' === $snippet ) : ?>
                                <p class="description"><?php esc_html_e( 'The generator refused the current inputs (fail-closed) — check the origin/listen format and the site host.', 'ultimate-performance' ); ?></p>
                        <?php else : ?>
                                <p class="description"><?php esc_html_e( 'Copy this into a file included from your http block, then reload nginx and press the verify button.', 'ultimate-performance' ); ?></p>
                                <textarea readonly rows="18" class="large-text code"><?php echo esc_textarea( $snippet ); ?></textarea>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="up_nginx_verify">
                                        <?php wp_nonce_field( 'up_nginx_verify', '_ucnonce' ); ?>
                                        <?php $this->tab_hidden_field( $tab ); ?>
                                        <?php submit_button( __( 'Verify nginx integration', 'ultimate-performance' ), 'secondary', 'submit', false ); ?>
                                </form>
                        <?php endif; ?>
                <?php endif; ?>
                <?php
        }

        // ===== Diagnostics =====

        private function render_diagnostics() {
                $s            = Settings::instance();
                $tab          = 'diagnostics';
                $oc_dropin    = new OcDropin();
                $oc_state     = $oc_dropin->state( WP_CONTENT_DIR );
                $cache_root   = Installer::cache_root();
                $root_writable = is_dir( $cache_root ) ? is_writable( $cache_root ) : false;
                $ac_path      = WP_CONTENT_DIR . '/advanced-cache.php';
                $ac_present   = file_exists( $ac_path );
                $ac_ours      = false;
                $ac_foreign   = false;
                if ( $ac_present && is_readable( $ac_path ) ) {
                        $head = (string) file_get_contents( $ac_path, false, null, 0, 256 );
                        if ( false !== strpos( $head, 'Ultimate Performance' ) ) {
                                $ac_ours = true;
                        } else {
                                $ac_foreign = true;
                        }
                }
                $has_redis  = class_exists( '\Redis' );
                $has_memc   = class_exists( '\Memcached' );
                $has_apcu   = function_exists( 'apcu_fetch' );
                $has_sqlite = extension_loaded( 'pdo_sqlite' );
                $has_amqp   = class_exists( '\PhpAmqpLib\Connection\AMQPStreamConnection' );
                $redis_test = get_transient( 'up_redis_test' );
                $amqp_test  = get_transient( 'up_amqp_test' );
                $nginx_probe = get_transient( 'up_nginx_probe' );
                $queue_backends = array(
                        'wp-cron'          => __( 'WP-Cron', 'ultimate-performance' ),
                        'rabbitmq'         => __( 'RabbitMQ', 'ultimate-performance' ),
                        'action-scheduler' => __( 'Action Scheduler', 'ultimate-performance' ),
                        'local'            => __( 'Local', 'ultimate-performance' ),
                        'sync'             => __( 'Synchronous', 'ultimate-performance' ),
                        'auto'             => __( 'Automatic', 'ultimate-performance' ),
                );
                $qb_value   = (string) $s->get( 'queue_backend', 'wp-cron' );
                $qb_label   = isset( $queue_backends[ $qb_value ] ) ? $queue_backends[ $qb_value ] : $qb_value;
                $wp_cache   = defined( 'WP_CACHE' ) ? ( WP_CACHE ? 'true' : 'false' ) : 'undefined';
                ?>
                <h2><?php esc_html_e( 'Diagnostics', 'ultimate-performance' ); ?></h2>
                <table class="form-table" role="presentation">
                        <tr><th><?php esc_html_e( 'WordPress version', 'ultimate-performance' ); ?></th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'PHP version', 'ultimate-performance' ); ?></th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Web server', 'ultimate-performance' ); ?></th><td><?php echo esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) : __( 'unknown', 'ultimate-performance' ) ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'WP_CACHE', 'ultimate-performance' ); ?></th><td><code><?php echo esc_html( $wp_cache ); ?></code></td></tr>
                        <tr>
                                <th><?php esc_html_e( 'advanced-cache.php', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( $ac_ours ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Installed by Ultimate Performance', 'ultimate-performance' ); ?></span>
                                        <?php elseif ( $ac_foreign ) : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Foreign drop-in', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:gray;"><?php esc_html_e( 'Not installed', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'object-cache.php', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( 'ours' === $oc_state ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Installed by Ultimate Performance', 'ultimate-performance' ); ?></span>
                                        <?php elseif ( 'foreign' === $oc_state ) : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Foreign drop-in detected', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:gray;"><?php esc_html_e( 'Not installed', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Cache root', 'ultimate-performance' ); ?></th>
                                <td>
                                        <code><?php echo esc_html( $cache_root ); ?></code>
                                        <?php if ( $root_writable ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'writable', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'not writable', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Redis extension', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( $has_redis ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Available', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Missing', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $redis_test ) : ?>
                                                — <?php echo $redis_test['ok'] ? '<span style="color:green;">&#10003; ' . esc_html( $redis_test['msg'] ) . '</span>' : '<span style="color:red;">&#10007; ' . esc_html( $redis_test['msg'] ) . '</span>'; ?>
                                                <?php $this->render_redis_test_result( $redis_test, true ); ?>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Memcached extension', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( $has_memc ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Available', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Missing', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'APCu extension', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( $has_apcu ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Available', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Missing', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'PDO SQLite', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( $has_sqlite ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Available', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Missing', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'RabbitMQ library', 'ultimate-performance' ); ?></th>
                                <td>
                                        <?php if ( $has_amqp ) : ?>
                                                <span style="color:green;">&#10003; <?php esc_html_e( 'Available', 'ultimate-performance' ); ?></span>
                                        <?php else : ?>
                                                <span style="color:red;">&#10007; <?php esc_html_e( 'Missing', 'ultimate-performance' ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $amqp_test ) : ?>
                                                — <?php echo $amqp_test['ok'] ? '<span style="color:green;">&#10003; ' . esc_html( $amqp_test['msg'] ) . '</span>' : '<span style="color:red;">&#10007; ' . esc_html( $amqp_test['msg'] ) . '</span>'; ?>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Queue backend', 'ultimate-performance' ); ?></th>
                                <td><?php echo esc_html( $qb_label ); ?></td>
                        </tr>
                </table>

                <h3><?php esc_html_e( 'Test buttons', 'ultimate-performance' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Run live connectivity probes using the settings saved in the other tabs.', 'ultimate-performance' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="up_test_redis">
                        <?php wp_nonce_field( 'up_test_redis', '_ucnonce' ); ?>
                        <?php $this->tab_hidden_field( $tab ); ?>
                        <?php
                        // §30 — type="button" so the AJAX transport can intercept
                        // the click and avoid a full-page reload. This Test Redis
                        // button on the Diagnostics tab uses the same JS handler
                        // as the one on the Object Cache tab (selector by id).
                        $this->test_button( __( 'Test Redis', 'ultimate-performance' ), 'up-test-redis-btn' );
                        ?>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="up_test_amqp">
                        <?php wp_nonce_field( 'up_test_amqp', '_ucnonce' ); ?>
                        <?php $this->tab_hidden_field( $tab ); ?>
                        <?php submit_button( __( 'Test RabbitMQ', 'ultimate-performance' ), 'secondary', 'submit', false ); ?>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="up_nginx_verify">
                        <?php wp_nonce_field( 'up_nginx_verify', '_ucnonce' ); ?>
                        <?php $this->tab_hidden_field( $tab ); ?>
                        <?php submit_button( __( 'Verify Nginx', 'ultimate-performance' ), 'secondary', 'submit', false ); ?>
                </form>

                <?php if ( $nginx_probe ) : ?>
                        <div class="notice <?php echo ! empty( $nginx_probe['ok'] ) ? 'notice-success' : 'notice-warning'; ?>"><p>
                                <?php
                                $nginx_msgs = array(
                                        'active'          => __( 'Nginx integration ACTIVE.', 'ultimate-performance' ),
                                        'inputs'          => __( 'Verify skipped: configure Nginx origin + listen first.', 'ultimate-performance' ),
                                        'write_failed'    => __( 'Verify failed: could not write the probe file.', 'ultimate-performance' ),
                                        'not_active'      => __( 'Nginx integration NOT active — probe body empty.', 'ultimate-performance' ),
                                        'content_mismatch'=> __( 'Nginx integration NOT active — body mismatch.', 'ultimate-performance' ),
                                        'redirected'      => __( 'Nginx integration NOT active — got redirect.', 'ultimate-performance' ),
                                        'unreachable'     => __( 'Server UNREACHABLE.', 'ultimate-performance' ),
                                        'dns_error'       => __( 'DNS error.', 'ultimate-performance' ),
                                        'tls_error'       => __( 'TLS error.', 'ultimate-performance' ),
                                        'timeout'         => __( 'Timeout.', 'ultimate-performance' ),
                                );
                                $why = isset( $nginx_probe['why'] ) ? $nginx_probe['why'] : '';
                                echo esc_html( isset( $nginx_msgs[ $why ] ) ? $nginx_msgs[ $why ] : sprintf( __( 'Unknown: %s', 'ultimate-performance' ), $why ) );
                                ?>
                        </p></div>
                <?php endif; ?>
                <?php
        }

        // ===== Advanced =====

        private function render_advanced() {
                $s   = Settings::instance();
                $tab = 'advanced';
                ?>
                <h2><?php esc_html_e( 'Advanced', 'ultimate-performance' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="up_save_settings">
                        <?php wp_nonce_field( 'up_save_settings', '_ucnonce' ); ?>
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Debug headers', 'ultimate-performance' ); ?></th>
                                        <td><label><input type="checkbox" name="up[debug_headers]" value="1" <?php checked( $s->get( 'debug_headers' ) ); ?>> <?php esc_html_e( 'Emit X-Ultimate-Performance-* response headers', 'ultimate-performance' ); ?></label></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Generation lock TTL', 'ultimate-performance' ); ?></th>
                                        <td><input type="number" min="1" max="300" name="up[genlock_ttl]" value="<?php echo esc_attr( (string) $s->get( 'genlock_ttl', 30 ) ); ?>" class="small-text"> <?php esc_html_e( 'seconds', 'ultimate-performance' ); ?>
                                                <p class="description"><?php esc_html_e( 'How long a single-flight generation lock is held (flock auto-releases on process death).', 'ultimate-performance' ); ?></p></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Generation wait budget', 'ultimate-performance' ); ?></th>
                                        <td><input type="number" min="0" max="10000000" name="up[genlock_wait_budget_us]" value="<?php echo esc_attr( (string) $s->get( 'genlock_wait_budget_us', 2500000 ) ); ?>" class="regular-text"> <?php esc_html_e( 'microseconds', 'ultimate-performance' ); ?>
                                                <p class="description"><?php esc_html_e( 'Bounded waiter budget for stampede protection (in microseconds).', 'ultimate-performance' ); ?></p></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Preload concurrency', 'ultimate-performance' ); ?></th>
                                        <td><input type="number" min="1" max="16" name="up[preload.concurrency]" value="<?php echo esc_attr( (string) $s->get( 'preload.concurrency', 2 ) ); ?>" class="small-text"></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Preload batch size', 'ultimate-performance' ); ?></th>
                                        <td><input type="number" min="1" max="1000" name="up[preload.batch]" value="<?php echo esc_attr( (string) $s->get( 'preload.batch', 50 ) ); ?>" class="small-text"></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Cookie bypass regex', 'ultimate-performance' ); ?></th>
                                        <td><input type="text" name="up[cookie_bypass_regex]" value="<?php echo esc_attr( (string) $s->get( 'cookie_bypass_regex', '' ) ); ?>" class="large-text code">
                                                <p class="description"><?php esc_html_e( 'PCRE pattern. Matching cookies force a cache bypass (logged-in users, sessions, carts).', 'ultimate-performance' ); ?></p></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Bypass paths', 'ultimate-performance' ); ?></th>
                                        <td><input type="text" name="up[bypass_paths]" value="<?php echo esc_attr( implode( ', ', array_map( 'esc_html', (array) $s->get( 'bypass_paths', array() ) ) ) ); ?>" class="large-text code">
                                                <p class="description"><?php esc_html_e( 'Comma- or space-separated URL path slugs that bypass the cache (e.g. cart, checkout).', 'ultimate-performance' ); ?></p></td>
                                </tr>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Query allowlist', 'ultimate-performance' ); ?></th>
                                        <td><input type="text" name="up[query_allowlist]" value="<?php echo esc_attr( implode( ', ', array_map( 'esc_html', (array) $s->get( 'query_allowlist', array() ) ) ) ); ?>" class="large-text code">
                                                <p class="description"><?php esc_html_e( 'Comma- or space-separated query parameter keys included in the cache key (e.g. p, page_id, paged).', 'ultimate-performance' ); ?></p></td>
                                </tr>
                        </table>
                        <?php $this->save_button( $tab, __( 'Save Advanced Settings', 'ultimate-performance' ), 'general' ); ?>
                </form>
                <?php
        }
}
