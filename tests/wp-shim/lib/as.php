<?php
/**
 * WP shim — Action Scheduler-compatible store/logger + $wpdb.
 *
 * Same class/function surface the production code and the shared queue driver
 * consume: as_enqueue_async_action(), ActionScheduler_Store::instance()
 * (query_actions/fetch_action/mark_complete/mark_failure/get_status),
 * ActionScheduler_Logger::instance()->log(), and $wpdb->get_results() over the
 * actionscheduler_logs table. File-backed, cross-process safe.
 *
 * @package UltimatePerformance\Tests\Shim
 */

namespace UltimatePerformance\Tests\Shim;

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
        $id = 0;
        state_update(
                'as-actions.json',
                static function ( $all ) use ( &$id, $hook, $args ) {
                        $max = 0;
                        foreach ( $all as $row ) {
                                $max = max( $max, (int) $row['id'] );
                        }
                        $id = $max + 1;
                        $all[ $id ] = array(
                                'id'      => $id,
                                'hook'    => (string) $hook,
                                'args'    => is_array( $args ) ? $args : array(),
                                'status'  => 'pending',
                                'created' => current_time( 'mysql' ) . '-' . sprintf( '%06d', $id ),
                        );
                        return $all;
                }
        );
        return $id;
}

function as_schedule_single_action( $hook, $args = array(), $ts = 0, $group = '' ) {
        return as_enqueue_async_action( $hook, $args, $group );
}

function as_unschedule_action( $hook, $args = array(), $group = '' ) {
        return true;
}
