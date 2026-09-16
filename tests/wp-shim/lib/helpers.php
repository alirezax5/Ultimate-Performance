<?php
/**
 * WP shim — core helper functions used by the plugin + audit suites.
 *
 * @package UltimatePerformance\Tests\Shim
 */

namespace UltimatePerformance\Tests\Shim;

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

function wp_json_encode( $data, $flags = 0 ) {
        $r = json_encode( $data, $flags );
        return false === $r ? null : $r;
}

function wp_normalize_path( $path ) {
        $p = str_replace( '\\', '/', (string) $path );
        $p = preg_replace( '|(?<=.)/+|', '/', $p );
        if ( ':' === substr( $p, 1, 1 ) ) {
                $p = ucfirst( $p );
        }
        return $p;
}

function wp_mkdir_p( $dir ) {
        if ( is_dir( (string) $dir ) ) {
                return true;
        }
        return @mkdir( (string) $dir, 0777, true );
}

function wp_delete_file( $file ) {
        if ( is_string( $file ) && '' !== $file ) {
                @unlink( $file );
        }
}

function wp_parse_url( $url, $component = -1 ) {
        $url = (string) $url;
        $parts = @parse_url( $url );
        if ( false === $parts || ! is_array( $parts ) ) {
                return false;
        }
        if ( -1 === $component ) {
                return $parts;
        }
        $map = array( PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment' );
        if ( PHP_URL_USER === $component || PHP_URL_PASS === $component ) {
                return isset( $parts[ 8 === $component ? 'user' : 'pass' ] ) ? $parts[ 8 === $component ? 'user' : 'pass' ] : null;
        }
        if ( isset( $map[ $component ] ) ) {
                return isset( $parts[ $map[ $component ] ] ) ? $parts[ $map[ $component ] ] : null;
        }
        return null;
}

function untrailingslashit( $s ) {
        return rtrim( (string) $s, '/\\ ' );
}
function trailingslashit( $s ) {
        return untrailingslashit( $s ) . '/';
}

function esc_url_raw( $url, $protocols = null ) {
        return filter_var( (string) $url, FILTER_SANITIZE_URL );
}
function esc_url( $url ) {
        return esc_url_raw( $url );
}
function esc_html( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
}
function esc_attr( $s ) {
        return esc_html( $s );
}
function esc_html__( $s, $d = null ) {
        return $s;
}
function __( $s, $d = null ) {
        return $s;
}
function sanitize_key( $k ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
function sanitize_text_field( $s ) {
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) strip_tags( (string) $s ) ) );
}
function absint( $n ) {
        return abs( (int) $n );
}
function is_wp_error( $x ) {
        return $x instanceof WpError;
}
function wp_list_pluck( $arr, $field ) {
        $out = array();
        foreach ( (array) $arr as $item ) {
                if ( is_object( $item ) && isset( $item->$field ) ) {
                        $out[] = $item->$field;
                } elseif ( is_array( $item ) && isset( $item[ $field ] ) ) {
                        $out[] = $item[ $field ];
                }
        }
        return $out;
}
function wp_unslash( $v ) {
        return is_array( $v ) ? array_map( __NAMESPACE__ . '\\wp_unslash', $v ) : stripslashes( (string) $v );
}
function current_time( $type ) {
        return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : ( ( 'timestamp' === $type ) ? time() : ( (string) time() ) );
}

class WpError {
        public $errors = array();
        public function __construct( $code = '', $message = '' ) {
                if ( '' !== $code ) {
                        $this->errors[ $code ] = $message;
                }
        }
        public function get_error_message() {
                $f = reset( $this->errors );
                return false === $f ? '' : (string) $f;
        }
        public function get_error_code() {
                $k = array_keys( $this->errors );
                return isset( $k[0] ) ? $k[0] : '';
        }
}

function home_url( $path = '' ) {
        $base = 'http://localhost';
        return '' === $path ? $base : $base . '/' . ltrim( (string) $path, '/' );
}
function get_site_url( $blog = null, $path = '', $scheme = null ) {
        return home_url( $path );
}
function is_multisite() {
        return false;
}
function is_admin() {
        return false;
}
function wp_doing_ajax() {
        return false;
}
function wp_doing_cron() {
        return false;
}
function is_ssl() {
        return false;
}
function current_user_can( $cap, ...$rest ) {
        return true; // ULTIMATE_PERFORMANCE_TESTING context.
}
function wp_set_current_user( $id ) {
        return true;
}
function get_role( $role ) {
        return new ShimRole();
}
function add_options_page( ...$a ) {
        return null;
}
function admin_url( $path = '' ) {
        return 'http://localhost/wp-admin/' . ltrim( $path, '/' );
}
function add_query_arg( ...$a ) {
        if ( 1 === count( $a ) && is_array( $a[0] ) ) {
                return '?' . http_build_query( $a[0] );
        }
        $url   = count( $a ) >= 3 ? (string) $a[2] : '';
        $key   = (string) $a[0];
        $val   = (string) $a[1];
        $parts = wp_parse_url( $url );
        parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $q );
        $q[ $key ] = $val;
        $qs        = http_build_query( $q );
        $base      = isset( $parts['path'] ) ? $parts['path'] : '';
        return $base . ( '' !== $qs ? '?' . $qs : '' );
}
function checked( $a, $b = true, $echo = true ) {
        $s = ( (string) $a === (string) $b ) ? " checked='checked'" : '';
        if ( $echo ) {
                echo $s;
        }
        return $s;
}
function selected( $a, $b = true, $echo = true ) {
        $s = ( (string) $a === (string) $b ) ? " selected='selected'" : '';
        if ( $echo ) {
                echo $s;
        }
        return $s;
}
function check_admin_referer( ...$a ) {
        return true;
}
function wp_safe_redirect( $url, $code = 302 ) {
        return true;
}
function wp_die( $msg = '', $title = '', $args = array() ) {
        throw new \RuntimeException( 'wp_die: ' . ( is_string( $msg ) ? $msg : '' ) );
}
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
        $nonce = wp_create_nonce( $action );
        $field = '<input type="hidden" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $nonce ) . '" />';
        if ( $referer ) {
                $field .= '<input type="hidden" name="_wp_http_referer" value="/" />';
        }
        if ( $echo ) {
                echo $field;
        }
        return $field;
}
function wp_create_nonce( $action = -1 ) {
        return substr( md5( 'shim-nonce|' . (string) $action ), 0, 10 );
}
function wp_verify_nonce( $nonce, $action = -1 ) {
        return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : 0;
}
function wp_logout_url( $redirect = '' ) {
        return home_url( '/wp-login.php?action=logout' );
}
function wp_remote_get( $url, $args = array() ) {
        return new WpError( 'http_request_not_executed', 'shim: no loopback HTTP in CLI tests' );
}
function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
        return add_query_arg( $name, wp_create_nonce( $action ), $url );
}
function submit_button( ...$a ) {
}

// ---------------------------------------------------------------------------
// Object cache emulation (per-process, non-persistent — mirrors WP defaults).
// ---------------------------------------------------------------------------

function wp_cache_add( $key, $data, $group = '', $ttl = 0 ) {
        $GLOBALS['up_shim_cache'][ $group . '|' . $key ] = $data;
        return true;
}
function wp_cache_set( $key, $data, $group = '', $ttl = 0 ) {
        $GLOBALS['up_shim_cache'][ $group . '|' . $key ] = $data;
        return true;
}
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
        $k = $group . '|' . $key;
        $found = array_key_exists( $k, $GLOBALS['up_shim_cache'] );
        return $found ? $GLOBALS['up_shim_cache'][ $k ] : false;
}
function wp_cache_delete( $key, $group = '' ) {
        unset( $GLOBALS['up_shim_cache'][ $group . '|' . $key ] );
        return true;
}
function wp_cache_flush() {
        $GLOBALS['up_shim_cache'] = array();
        return true;
}
function wp_cache_flush_group( $group ) {
        return true;
}

// Cron API.
function wp_next_scheduled( $hook ) {
        return shim_next_scheduled( $hook );
}
function wp_schedule_event( $ts, $recurrence, $hook ) {
        return shim_schedule_event( $ts, $recurrence, $hook );
}
function wp_schedule_single_event( $ts, $hook ) {
        return shim_schedule_event( $ts, 'single', $hook );
}
function wp_clear_scheduled_hook( $hook ) {
        return shim_clear_scheduled_hook( $hook );
}

// Activation / plugin plumbing for the REAL entrypoint (audit-boot).
function register_activation_hook( $file, $cb ) {
        add_action( 'activate_plugin', $cb, 10, 0 );
}
function register_deactivation_hook( $file, $cb ) {
        add_action( 'deactivate_plugin', $cb, 10, 0 );
}
function plugin_dir_url( $file ) {
        return 'http://localhost/wp-content/plugins/' . basename( dirname( (string) $file ) ) . '/';
}
function plugin_basename( $file ) {
        return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
}

final class ShimRole {
        public $capabilities = array();
        public function has_cap( $cap ) {
                return isset( $this->capabilities[ $cap ] );
        }
        public function add_cap( $cap, $grant = true ) {
                $this->capabilities[ $cap ] = $grant;
        }
        public function remove_cap( $cap ) {
                unset( $this->capabilities[ $cap ] );
        }
}

// ---------------------------------------------------------------------------
// Users (minimal — audit-wp-nonce D2 logged-in context).
// ---------------------------------------------------------------------------

function wp_create_user( $username, $password, $email = '' ) {
        $id = 0;
        state_update(
                'users.json',
                static function ( $all ) use ( &$id, $username, $email ) {
                        $max = 0;
                        foreach ( $all as $u ) {
                                $max = max( $max, (int) $u['ID'] );
                        }
                        $id = $max + 1;
                        $all[ $id ] = array( 'ID' => $id, 'user_login' => (string) $username, 'user_email' => (string) $email );
                        return $all;
                }
        );
        return $id;
}

function wp_delete_user( $id, $reassign = null ) {
        state_update(
                'users.json',
                static function ( $all ) use ( $id ) {
                        unset( $all[ (int) $id ] );
                        return $all;
                }
        );
        return true;
}

function wp_block( $id ) {
        return true;
}

function rest_url( $path = '/' ) {
        return home_url( '/wp-json/' . ltrim( (string) $path, '/' ) );
}

function wp_meta() {
        // Core wp_meta() fires the wp_meta action; themes echo login links there.
        do_action( 'wp_meta' );
}

// ---------------------------------------------------------------------------
// AJAX / asset / JSON-emitter functions (Phase AJAX-RUNTIME-RESTORE).
// Real WP provides these via wp-includes/load.php, wp-includes/links.php,
// wp-admin/includes/ajax-actions.php, etc. The shim needs them because the
// new AJAX handlers + enqueue_assets in AdminPage call them.
// ---------------------------------------------------------------------------

/**
 * WP function: returns true if an external object cache drop-in is loaded.
 * Real WP sets $GLOBALS['_wp_using_ext_object_cache'] during wp-settings.php
 * load when the drop-in file exists. The shim sets it in wp-load.php.
 */
function wp_using_ext_object_cache() {
        return ! empty( $GLOBALS['_wp_using_ext_object_cache'] );
}

/**
 * Generate a random password. Used for unique proof keys (12 / 8 chars).
 * The shim returns a base64-no-padded random hex so keys are url-safe.
 */
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
        $bytes = function_exists( 'random_bytes' ) ? random_bytes( max( 1, (int) $length ) ) : openssl_randomPseudoBytes( max( 1, (int) $length ) );
        $hex = bin2hex( $bytes );
        return substr( $hex, 0, (int) $length );
}

/**
 * Verify an AJAX nonce. The shim's wp_create_nonce is deterministic so a
 * nonce generated by the same actor matches. Real WP dies on failure; the
 * shim returns false so the AJAX handler can wp_send_json_error(403).
 */
function check_ajax_referer( $action = -1, $query_arg = 'nonce', $die = true ) {
        $nonce = isset( $_REQUEST[ $query_arg ] ) ? (string) $_REQUEST[ $query_arg ] : ( isset( $_POST[ $query_arg ] ) ? (string) $_POST[ $query_arg ] : '' );
        $ok = 1 === wp_verify_nonce( $nonce, $action );
        if ( ! $ok && $die ) {
                // Mirror real WP: die with 403. The AJAX handlers below catch
                // the JSON-error path themselves so $die=false is the norm.
                return false;
        }
        return $ok;
}

/**
 * Send a JSON success response. Real WP dies after sending.
 * The shim stores the response in $GLOBALS so the test harness can introspect.
 */
function wp_send_json_success( $data = null, $status_code = null ) {
        $payload = array( 'success' => true, 'data' => $data );
        $GLOBALS['_uc_last_json_response'] = array(
                'status'  => $status_code ?: 200,
                'payload' => $payload,
        );
        if ( ! headers_sent() ) {
                header( 'Content-Type: application/json; charset=utf-8' );
                if ( null !== $status_code ) {
                        http_response_code( $status_code );
                }
        }
        echo wp_json_encode( $payload );
        // Mirror real WP: end the request. The shim throws a controlled
        // exception so PHPUnit / PHP-level test harnesses can catch it.
        throw new \RuntimeException( 'wp_send_json_success' );
}

/**
 * Send a JSON error response. Real WP dies after sending.
 */
function wp_send_json_error( $data = null, $status_code = null ) {
        $payload = array( 'success' => false, 'data' => $data );
        $code = null !== $status_code ? (int) $status_code : 500;
        $GLOBALS['_uc_last_json_response'] = array(
                'status'  => $code,
                'payload' => $payload,
        );
        if ( ! headers_sent() ) {
                header( 'Content-Type: application/json; charset=utf-8' );
                http_response_code( $code );
        }
        echo wp_json_encode( $payload );
        throw new \RuntimeException( 'wp_send_json_error' );
}

/**
 * Generic JSON emitter (older API used by some WP internals).
 */
function wp_send_json( $response, $status_code = null ) {
        $payload = $response;
        if ( ! isset( $payload['success'] ) ) {
                $payload['success'] = true;
        }
        $GLOBALS['_uc_last_json_response'] = array(
                'status'  => $status_code ?: 200,
                'payload' => $payload,
        );
        echo wp_json_encode( $payload );
        throw new \RuntimeException( 'wp_send_json' );
}

/**
 * Plugin dir path: returns plugin root directory with trailing slash.
 * The shim mirrors real WP: dirname($file) + '/'.
 */
function plugin_dir_path( $file ) {
        return trailingslashit( dirname( (string) $file ) );
}

/**
 * Register a script. The shim tracks registration in $GLOBALS['_uc_scripts'].
 */
function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
        $GLOBALS['_uc_scripts'][ $handle ] = array(
                'src'       => $src,
                'deps'      => $deps,
                'ver'       => $ver,
                'in_footer' => $in_footer,
                'data'      => array(),
                'extra'     => '',
        );
        return true;
}

/**
 * Enqueue a script (mark as enqueued so wp_print_scripts would emit it).
 */
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
        if ( ! isset( $GLOBALS['_uc_scripts'][ $handle ] ) && '' !== $src ) {
                wp_register_script( $handle, $src, $deps, $ver, $in_footer );
        }
        if ( isset( $GLOBALS['_uc_scripts'][ $handle ] ) ) {
                $GLOBALS['_uc_enqueued_scripts'][ $handle ] = $GLOBALS['_uc_scripts'][ $handle ];
        }
        return true;
}

/**
 * Localize data for a script (real WP: builds <script>var X = {...};</script>).
 */
function wp_localize_script( $handle, $object_name, $data ) {
        if ( isset( $GLOBALS['_uc_scripts'][ $handle ] ) ) {
                $GLOBALS['_uc_scripts'][ $handle ]['data'][ $object_name ] = $data;
                return true;
        }
        return false;
}

/**
 * Add inline JS before/after a script.
 */
function wp_add_inline_script( $handle, $data, $position = 'after' ) {
        if ( isset( $GLOBALS['_uc_scripts'][ $handle ] ) ) {
                $GLOBALS['_uc_scripts'][ $handle ]['extra'] .= $data;
                return true;
        }
        return false;
}

/**
 * Was this script enqueued?
 */
function wp_script_is( $handle, $list = 'enqueued' ) {
        return isset( $GLOBALS['_uc_enqueued_scripts'][ $handle ] );
}

/**
 * Fire the admin_enqueue_scripts hook. Real WP fires this in admin-header.php.
 * The shim exposes it as a function so the audit can call it directly.
 */
function _uc_fire_admin_enqueue_scripts( $hook_suffix ) {
        do_action( 'admin_enqueue_scripts', $hook_suffix );
}

/**
 * get_bloginfo: minimal subset used by admin/diagnostics.
 */
function get_bloginfo( $show = '', $filter = true ) {
        switch ( $show ) {
                case 'version':
                        return '6.5.0'; // shim version
                case 'name':
                        return 'Shim Site';
                case 'home':
                case 'siteurl':
                        return home_url();
                default:
                        return '';
        }
}

/**
 * Echo translated string. The shim's __() returns the original, so _e prints it.
 */
function _e( $text, $domain = 'default' ) {
        echo __( $text, $domain );
}
function esc_html_e( $text, $domain = 'default' ) {
        echo esc_html( __( $text, $domain ) );
}
function esc_attr_e( $text, $domain = 'default' ) {
        echo esc_attr( __( $text, $domain ) );
}
function esc_js( $text ) {
        return esc_html( $text );
}

/**
 * Load plugin text domain (i18n). The shim is a no-op: the shim's __()
 * returns the original string, so translation files are not consulted.
 */
function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
        // Real WP loads .mo files from WP_LANG_DIR / wp-content/plugins/<rel>/languages/.
        // The shim returns true (no .mo loading) — __() returns the original
        // string, so callers see their msgid unchanged. Tests assert on
        // msgids (English) anyway.
        return true;
}

/**
 * Determine if the current request is for a given admin page (hook suffix).
 * Used by enqueue_assets() — but the shim is a no-op since hook suffixes are
 * passed by the audit harness directly.
 */
function get_current_screen() {
        return null;
}
