<?php
/**
 * WP shim — global-namespace API surface.
 *
 * The plugin + suites call the GLOBAL WP functions; the implementations live
 * in UltimatePerformance\Tests\Shim. This file (deliberately un-namespaced) bridges
 * them. Defining wrapper functions (not aliases) keeps the shim readable and
 * allows semantics tweaks without touching every call site.
 *
 * @package UltimatePerformance\Tests
 */

use UltimatePerformance\Tests\Shim as S;

if ( defined( 'UC_SHIM_GLOBALS' ) ) {
        return;
}
define( 'UC_SHIM_GLOBALS', true );

// --- options / transients / state -----------------------------------------
function get_option( $name, $default = false ) {
        return S\shim_get_option( $name, $default );
}
function update_option( $name, $value, $autoload = null ) {
        return S\shim_update_option( $name, $value, $autoload );
}
function add_option( $name, $value = '', $dep = '', $autoload = null ) {
        return S\shim_add_option( $name, $value, $dep, $autoload );
}
function delete_option( $name ) {
        return S\shim_delete_option( $name );
}
function get_transient( $name ) {
        return S\shim_get_transient( $name );
}
function set_transient( $name, $value, $expiration = 0 ) {
        return S\shim_set_transient( $name, $value, $expiration );
}
function delete_transient( $name ) {
        return S\shim_delete_transient( $name );
}

// --- hooks ------------------------------------------------------------------
function add_action( $hook, $cb, $prio = 10, $accepted = 1 ) {
        return S\shim_add_filter( $hook, $cb, $prio, $accepted );
}
function add_filter( $hook, $cb, $prio = 10, $accepted = 1 ) {
        return S\shim_add_filter( $hook, $cb, $prio, $accepted );
}
function do_action( $hook, ...$args ) {
        return S\shim_do_action( $hook, ...$args );
}
function do_action_ref_array( $hook, $args ) {
        return S\shim_do_action( $hook, ...(array) $args );
}
function apply_filters( $hook, $value, ...$extra ) {
        return S\shim_apply_filters( $hook, $value, ...$extra );
}
function apply_filters_ref_array( $hook, $args ) {
        $a    = (array) $args;
        $val  = array_shift( $a );
        return S\shim_apply_filters( $hook, $val, ...$a );
}
function remove_action( $hook, $cb, $prio = 10 ) {
        return S\shim_remove_action( $hook, $cb, $prio );
}
function remove_filter( $hook, $cb, $prio = 10 ) {
        return S\shim_remove_action( $hook, $cb, $prio );
}
function remove_all_actions( $hook ) {
        return S\shim_remove_all_actions( $hook );
}
function remove_all_filters( $hook ) {
        return S\shim_remove_all_actions( $hook );
}
function has_action( $hook, $cb = false ) {
        return S\shim_has_action( $hook, $cb );
}
function has_filter( $hook, $cb = false ) {
        return S\shim_has_action( $hook, $cb );
}
function did_action( $hook ) {
        return S\shim_did_action( $hook );
}

// --- content ----------------------------------------------------------------
function get_post( $id ) {
        return S\get_post( $id );
}
function wp_insert_post( $arr, $wp_error = false ) {
        return S\wp_insert_post( $arr, $wp_error );
}
function wp_update_post( $arr, $wp_error = false ) {
        return S\wp_update_post( $arr, $wp_error );
}
function wp_trash_post( $id ) {
        return S\wp_trash_post( $id );
}
function wp_delete_post( $id, $force = false ) {
        return S\wp_delete_post( $id, $force );
}
function get_permalink( $id ) {
        return S\get_permalink( $id );
}
function post_type_exists( $type ) {
        return S\post_type_exists( $type );
}
function get_object_taxonomies( $type, $output = 'names' ) {
        return S\get_object_taxonomies( $type, $output );
}
function wp_insert_term( $term, $taxonomy, $args = array() ) {
        return S\wp_insert_term( $term, $taxonomy, $args );
}
function wp_update_term( $term_id, $taxonomy, $args = array() ) {
        return S\wp_update_term( $term_id, $taxonomy, $args );
}
function wp_delete_term( $term_id, $taxonomy ) {
        return S\wp_delete_term( $term_id, $taxonomy );
}
function wp_set_object_terms( $post_id, $terms, $taxonomy, $append = false ) {
        return S\wp_set_object_terms( $post_id, $terms, $taxonomy, $append );
}
function wp_remove_object_terms( $post_id, $terms, $taxonomy ) {
        return S\wp_remove_object_terms( $post_id, $terms, $taxonomy );
}
function wp_get_object_terms( $post_id, $taxonomies, $args = array() ) {
        return S\wp_get_object_terms( $post_id, $taxonomies, $args );
}
function get_term_link( $term, $taxonomy = '' ) {
        return S\get_term_link( $term, $taxonomy );
}
function wp_insert_comment( $arr ) {
        return S\wp_insert_comment( $arr );
}
function get_comment( $id ) {
        return S\get_comment( $id );
}
function wp_set_comment_status( $id, $status ) {
        return S\wp_set_comment_status( $id, $status );
}
function wp_delete_comment( $id, $force = false ) {
        return S\wp_delete_comment( $id, $force );
}

// --- Action Scheduler --------------------------------------------------------
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
        return S\as_enqueue_async_action( $hook, $args, $group );
}
function as_schedule_single_action( $hook, $args = array(), $ts = 0, $group = '' ) {
        return S\as_schedule_single_action( $hook, $args, $ts, $group );
}
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
        return S\as_unschedule_action( $hook, $args, $group );
}
function as_get_scheduled_actions( $args = array(), $return_format = 'ids' ) {
        $ids = ActionScheduler_Store::instance()->query_actions( $args );
        return 'ids' === $return_format ? $ids : array_map( array( ActionScheduler_Store::class, 'fetch_action' ), $ids );
}

// --- misc helpers ------------------------------------------------------------
function wp_json_encode( $data, $flags = 0 ) {
        return S\wp_json_encode( $data, $flags );
}
function wp_normalize_path( $path ) {
        return S\wp_normalize_path( $path );
}
function wp_mkdir_p( $dir ) {
        return S\wp_mkdir_p( $dir );
}
function wp_delete_file( $file ) {
        return S\wp_delete_file( $file );
}
function wp_parse_url( $url, $component = -1 ) {
        return S\wp_parse_url( $url, $component );
}
function untrailingslashit( $s ) {
        return S\untrailingslashit( $s );
}
function trailingslashit( $s ) {
        return S\trailingslashit( $s );
}
function esc_url_raw( $url, $protocols = null ) {
        return S\esc_url_raw( $url, $protocols );
}
function esc_url( $url ) {
        return S\esc_url( $url );
}
function esc_html( $s ) {
        return S\esc_html( $s );
}
function esc_attr( $s ) {
        return S\esc_attr( $s );
}
function esc_html__( $s, $d = null ) {
        return S\esc_html__( $s, $d );
}
function __( $s, $d = null ) {
        return S\__( $s, $d );
}
function sanitize_key( $k ) {
        return S\sanitize_key( $k );
}
function sanitize_text_field( $s ) {
        return S\sanitize_text_field( $s );
}
function absint( $n ) {
        return S\absint( $n );
}
function is_wp_error( $x ) {
        return S\is_wp_error( $x );
}
function wp_list_pluck( $arr, $field ) {
        return S\wp_list_pluck( $arr, $field );
}
function wp_unslash( $v ) {
        return S\wp_unslash( $v );
}
function current_time( $type ) {
        return S\current_time( $type );
}
function home_url( $path = '' ) {
        return S\home_url( $path );
}
function get_site_url( $blog = null, $path = '', $scheme = null ) {
        return S\get_site_url( $blog, $path, $scheme );
}
function is_multisite() {
        return S\is_multisite();
}
function is_admin() {
        return S\is_admin();
}
function wp_doing_ajax() {
        return S\wp_doing_ajax();
}
function wp_doing_cron() {
        return S\wp_doing_cron();
}
function is_ssl() {
        return S\is_ssl();
}
function current_user_can( $cap, ...$rest ) {
        return S\current_user_can( $cap, ...$rest );
}
function wp_set_current_user( $id ) {
        return S\wp_set_current_user( $id );
}
function get_role( $role ) {
        return S\get_role( $role );
}
function add_options_page( ...$a ) {
        return S\add_options_page( ...$a );
}
function admin_url( $path = '' ) {
        return S\admin_url( $path );
}
function add_query_arg( ...$a ) {
        return S\add_query_arg( ...$a );
}
function checked( $a, $b = true, $echo = true ) {
        return S\checked( $a, $b, $echo );
}
function selected( $a, $b = true, $echo = true ) {
        return S\selected( $a, $b, $echo );
}
function check_admin_referer( ...$a ) {
        return S\check_admin_referer( ...$a );
}
function wp_safe_redirect( $url, $code = 302 ) {
        return S\wp_safe_redirect( $url, $code );
}
function wp_die( $msg = '', $title = '', $args = array() ) {
        return S\wp_die( $msg, $title, $args );
}
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
        return S\wp_nonce_field( $action, $name, $referer, $echo );
}
function wp_create_nonce( $action = -1 ) {
        return S\wp_create_nonce( $action );
}
function wp_verify_nonce( $nonce, $action = -1 ) {
        return S\wp_verify_nonce( $nonce, $action );
}
function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
        return S\wp_nonce_url( $url, $action, $name );
}
function wp_logout_url( $redirect = '' ) {
        return S\wp_logout_url( $redirect );
}
function wp_remote_get( $url, $args = array() ) {
        return S\wp_remote_get( $url, $args );
}
function submit_button( ...$a ) {
        return S\submit_button( ...$a );
}

// --- object cache --------------------------------------------------------------
// function_exists-guarded: a real object-cache drop-in (loaded by wp-load.php
// before this file, mirroring wp-settings.php) defines these itself and wins.
if ( ! function_exists( 'wp_cache_add' ) ) {
function wp_cache_add( $key, $data, $group = '', $ttl = 0 ) {
        return S\wp_cache_add( $key, $data, $group, $ttl );
}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
function wp_cache_set( $key, $data, $group = '', $ttl = 0 ) {
        return S\wp_cache_set( $key, $data, $group, $ttl );
}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
        return S\wp_cache_get( $key, $group, $force, $found );
}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
function wp_cache_delete( $key, $group = '' ) {
        return S\wp_cache_delete( $key, $group );
}
}
if ( ! function_exists( 'wp_cache_flush' ) ) {
function wp_cache_flush() {
        return S\wp_cache_flush();
}
}

// --- cron -----------------------------------------------------------------------
function wp_next_scheduled( $hook ) {
        return S\wp_next_scheduled( $hook );
}
function wp_schedule_event( $ts, $recurrence, $hook ) {
        return S\wp_schedule_event( $ts, $recurrence, $hook );
}
function wp_schedule_single_event( $ts, $hook ) {
        return S\wp_schedule_single_event( $ts, $hook );
}
function wp_clear_scheduled_hook( $hook ) {
        return S\wp_clear_scheduled_hook( $hook );
}

// --- activation plumbing -----------------------------------------------------------
function register_activation_hook( $file, $cb ) {
        return S\register_activation_hook( $file, $cb );
}
function register_deactivation_hook( $file, $cb ) {
        return S\register_deactivation_hook( $file, $cb );
}
function plugin_dir_url( $file ) {
        return S\plugin_dir_url( $file );
}
function plugin_basename( $file ) {
        return S\plugin_basename( $file );
}

// ---------------------------------------------------------------------------
// AJAX / asset / JSON-emitter wrappers (Phase AJAX-RUNTIME-RESTORE).
// These delegate to the implementations in helpers.php.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
function wp_using_ext_object_cache() {
        return S\wp_using_ext_object_cache();
}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
        return S\wp_generate_password( $length, $special_chars, $extra_special_chars );
}
}
function check_ajax_referer( $action = -1, $query_arg = 'nonce', $die = true ) {
        return S\check_ajax_referer( $action, $query_arg, $die );
}
function wp_send_json_success( $data = null, $status_code = null ) {
        return S\wp_send_json_success( $data, $status_code );
}
function wp_send_json_error( $data = null, $status_code = null ) {
        return S\wp_send_json_error( $data, $status_code );
}
function wp_send_json( $response, $status_code = null ) {
        return S\wp_send_json( $response, $status_code );
}
function plugin_dir_path( $file ) {
        return S\plugin_dir_path( $file );
}
function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
        return S\wp_register_script( $handle, $src, $deps, $ver, $in_footer );
}
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
        return S\wp_enqueue_script( $handle, $src, $deps, $ver, $in_footer );
}
function wp_localize_script( $handle, $object_name, $data ) {
        return S\wp_localize_script( $handle, $object_name, $data );
}
function wp_add_inline_script( $handle, $data, $position = 'after' ) {
        return S\wp_add_inline_script( $handle, $data, $position );
}
function wp_script_is( $handle, $list = 'enqueued' ) {
        return S\wp_script_is( $handle, $list );
}
function _uc_fire_admin_enqueue_scripts( $hook_suffix ) {
        return S\_uc_fire_admin_enqueue_scripts( $hook_suffix );
}
function get_bloginfo( $show = '', $filter = true ) {
        return S\get_bloginfo( $show, $filter );
}
function _e( $text, $domain = 'default' ) {
        echo S\__( $text, $domain );
}
function esc_html_e( $text, $domain = 'default' ) {
        echo S\esc_html( S\__( $text, $domain ) );
}
function esc_attr_e( $text, $domain = 'default' ) {
        echo S\esc_attr( S\__( $text, $domain ) );
}
function esc_js( $text ) {
        return S\esc_html( $text );
}
function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
        return S\load_plugin_textdomain( $domain, $deprecated, $plugin_rel_path );
}
function get_current_screen() {
        return S\get_current_screen();
}

// --- users / REST (audit-wp-nonce context) -----------------------------------
function wp_create_user( $username, $password, $email = '' ) {
        return S\wp_create_user( $username, $password, $email );
}
function wp_delete_user( $id, $reassign = null ) {
        return S\wp_delete_user( $id, $reassign );
}
function wp_block( $id ) {
        return S\wp_block( $id );
}
function rest_url( $path = '/' ) {
        return S\rest_url( $path );
}
function wp_meta() {
        return S\wp_meta();
}

// --- multisite (Phase J) --------------------------------------------------------
function get_current_blog_id() {
        return S\get_current_blog_id();
}
function wp_is_multisite() {
        return S\wp_is_multisite();
}
function switch_to_blog( $new_blog ) {
        return S\switch_to_blog( $new_blog );
}
function restore_current_blog() {
        return S\restore_current_blog();
}
function ms_is_switched() {
        return S\ms_is_switched();
}
function wpmu_create_blog( $domain, $path = '/', $title = 'Blog', $user_id = 1 ) {
        return S\wpmu_create_blog( $domain, $path, $title, $user_id );
}
function get_blog_details( $blog_id = null ) {
        return S\get_blog_details( $blog_id );
}
function get_sites() {
        return S\get_sites();
}
