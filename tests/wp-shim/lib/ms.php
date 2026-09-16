<?php
/**
 * Multisite shim (Phase J) — minimal WP multisite API surface.
 *
 * Mirrors the load-order and blog-switching semantics core implements:
 *
 *   - get_current_blog_id(): active blog (default 1),
 *   - switch_to_blog()/restore_current_blog(): scope stack; the real core
 *     calls wp_cache_switch_to_blog() on every switch — so does this shim,
 *   - wpmu_create_blog(): registers a subdirectory blog and returns its id,
 *   - get_sites()/wp_is_multisite() helpers the suites need.
 *
 * TEST INFRASTRUCTURE ONLY — mirrors the exact behaviors the plugin consumes.
 *
 * @package UltimatePerformance\Tests\Shim
 */

namespace UltimatePerformance\Tests\Shim;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'ABSPATH must be defined by the calling suite' );
}

$GLOBALS['_shim_blog_id']  = 1;
$GLOBALS['_shim_blog_stack'] = array();
$GLOBALS['_shim_blogs']    = array(
	1 => array( 'domain' => 'example.com', 'path' => '/', 'blog_id' => 1 ),
);

function get_current_blog_id() {
	return isset( $GLOBALS['_shim_blog_id'] ) ? (int) $GLOBALS['_shim_blog_id'] : 1;
}

function wp_is_multisite() {
	return true; // the multisite suites run the shim as a network
}

function switch_to_blog( $new_blog ) {
	$new_blog = (int) $new_blog;
	if ( $new_blog <= 0 || ! isset( $GLOBALS['_shim_blogs'][ $new_blog ] ) ) {
		return false;
	}
	$GLOBALS['_shim_blog_stack'][] = $GLOBALS['_shim_blog_id'];
	$GLOBALS['_shim_blog_id']      = $new_blog;
	// Core WP calls wp_cache_switch_to_blog() inside switch_to_blog().
	if ( function_exists( 'wp_cache_switch_to_blog' ) ) {
		wp_cache_switch_to_blog( $new_blog );
	}
	return true;
}

function restore_current_blog() {
	if ( empty( $GLOBALS['_shim_blog_stack'] ) ) {
		return false;
	}
	$GLOBALS['_shim_blog_id'] = array_pop( $GLOBALS['_shim_blog_stack'] );
	if ( function_exists( 'wp_cache_switch_to_blog' ) ) {
		wp_cache_switch_to_blog( $GLOBALS['_shim_blog_id'] );
	}
	return true;
}

function ms_is_switched() {
	return ! empty( $GLOBALS['_shim_blog_stack'] );
}

/**
 * Register a subdirectory blog (subdomain form is created when the suite
 * passes an absolute domain and an empty path — both forms supported so the
 * suites can cover both; subdirectory is the REQUIRED form per phase spec).
 *
 * @return int|false New blog id.
 */
function wpmu_create_blog( $domain, $path = '/', $title = 'Blog', $user_id = 1 ) {
	$next   = count( $GLOBALS['_shim_blogs'] ) + 1;
	$blog_id = (int) apply_filters( 'shim_wpmu_new_blog', $next, $domain, $path );
	$GLOBALS['_shim_blogs'][ $blog_id ] = array(
		'domain'  => (string) $domain,
		'path'    => '/' === $path ? '/' : '/' . trim( (string) $path, '/' ) . '/',
		'title'   => (string) $title,
		'user_id' => (int) $user_id,
	);
	do_action( 'wpmu_new_blog', $blog_id );
	return $blog_id;
}

function get_blog_details( $blog_id = null ) {
	$blog_id = $blog_id ?: ( $GLOBALS['_shim_blog_id'] ?? 1 );
	return isset( $GLOBALS['_shim_blogs'][ (int) $blog_id ] ) ? (object) $GLOBALS['_shim_blogs'][ (int) $blog_id ] : false;
}

function get_sites() {
	$out = array();
	foreach ( $GLOBALS['_shim_blogs'] as $id => $meta ) {
		$out[] = (object) array_merge( $meta, array( 'blog_id' => (int) $id ) );
	}
	return $out;
}
