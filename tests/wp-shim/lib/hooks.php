<?php
/**
 * WP shim — hook system (WP_Hook-compatible objects).
 *
 * $GLOBALS['wp_filter'][hook] is an object exposing ->callbacks as
 * array(priority => array(id => array('function'=>..., 'accepted_args'=>...)))
 * so suites that introspect real WP hook internals keep working.
 *
 * @package UltimatePerformance\Tests\Shim
 */

namespace UltimatePerformance\Tests\Shim;

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class ShimHook {
        /** @var array<int,array<string,array{function:callable,accepted_args:int}>> */
        public $callbacks = array();
}

/**
 * Build the WP-style hook id for a callback (contains class+method for
 * array callables so introspection tests can find registrations).
 *
 * @param callable $cb
 * @return string
 */
function hook_id( $cb ) {
        if ( is_string( $cb ) ) {
                return $cb;
        }
        if ( is_array( $cb ) && 2 === count( $cb ) ) {
                $cls = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
                return $cls . $cb[1];
        }
        if ( $cb instanceof \Closure ) {
                return 'closure' . spl_object_hash( $cb );
        }
        return 'callback' . spl_object_hash( (object) $cb );
}

function shim_add_filter( $hook, $cb, $prio = 10, $accepted = 1 ) {
        if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
                $GLOBALS['wp_filter'][ $hook ] = new ShimHook();
        }
        $id = hook_id( $cb );
        $GLOBALS['wp_filter'][ $hook ]->callbacks[ (int) $prio ][ $id ] = array(
                'function'      => $cb,
                'accepted_args' => (int) $accepted,
        );
        return true;
}

function shim_apply_filters( $hook, $value, ...$extra ) {
        $GLOBALS['wp_current_filter'][] = $hook;
        if ( isset( $GLOBALS['wp_filter'][ $hook ] ) && $GLOBALS['wp_filter'][ $hook ] instanceof ShimHook ) {
                $h = $GLOBALS['wp_filter'][ $hook ];
                ksort( $h->callbacks, SORT_NUMERIC );
                foreach ( $h->callbacks as $prio => $cbs ) {
                        foreach ( $cbs as $spec ) {
                                $n    = (int) $spec['accepted_args'];
                                $out  = array_merge( array( $value ), $extra );
                                $args = ( 0 === $n ) ? array() : ( ( $n >= count( $out ) ) ? $out : array_slice( $out, 0, $n ) );
                                $r    = call_user_func_array( $spec['function'], $args );
                                if ( null !== $r ) {
                                        $value = $r;
                                }
                        }
                }
        }
        array_pop( $GLOBALS['wp_current_filter'] );
        return $value;
}

function shim_do_action( $hook, ...$args ) {
        $GLOBALS['wp_actions'][ $hook ] = 1 + ( isset( $GLOBALS['wp_actions'][ $hook ] ) ? (int) $GLOBALS['wp_actions'][ $hook ] : 0 );
        $GLOBALS['wp_current_filter'][] = $hook;
        if ( isset( $GLOBALS['wp_filter'][ $hook ] ) && $GLOBALS['wp_filter'][ $hook ] instanceof ShimHook ) {
                $h = $GLOBALS['wp_filter'][ $hook ];
                ksort( $h->callbacks, SORT_NUMERIC );
                foreach ( $h->callbacks as $prio => $cbs ) {
                        foreach ( $cbs as $spec ) {
                                $n    = (int) $spec['accepted_args'];
                                $cargs = ( 0 === $n ) ? array() : ( ( $n >= count( $args ) ) ? $args : array_slice( $args, 0, $n ) );
                                call_user_func_array( $spec['function'], $cargs );
                        }
                }
        }
        array_pop( $GLOBALS['wp_current_filter'] );
}

function shim_remove_all_actions( $hook ) {
        if ( isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
                $GLOBALS['wp_filter'][ $hook ] = new ShimHook(); // filters also cleared (remove_all_* parity)
        }
}

function shim_remove_action( $hook, $cb, $prio = 10 ) {
        if ( isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
                unset( $GLOBALS['wp_filter'][ $hook ]->callbacks[ (int) $prio ][ hook_id( $cb ) ] );
        }
}

function shim_has_action( $hook, $cb = false ) {
        if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
                return false;
        }
        if ( false === $cb ) {
                foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $cbs ) {
                        if ( ! empty( $cbs ) ) {
                                return true;
                        }
                }
                return false;
        }
        foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $cbs ) {
                if ( isset( $cbs[ hook_id( $cb ) ] ) ) {
                        return true;
                }
        }
        return false;
}

function shim_did_action( $hook ) {
        return isset( $GLOBALS['wp_actions'][ $hook ] ) ? (int) $GLOBALS['wp_actions'][ $hook ] : 0;
}
