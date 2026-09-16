<?php
/**
 * WP shim — Action Scheduler-compatible classes + $wpdb (GLOBAL namespace).
 *
 * Mirrors the class/function surface production code and the shared queue
 * driver consume. File-backed via the shim state layer; cross-process safe.
 *
 * @package UltimatePerformance\Tests
 */

use UltimatePerformance\Tests\Shim as S;

class ActionScheduler_Action {
        private $hook;
        private $args;

        public function __construct( $hook, $args ) {
                $this->hook = (string) $hook;
                $this->args = is_array( $args ) ? $args : array();
        }
        public function get_hook() {
                return $this->hook;
        }
        public function get_args() {
                return $this->args;
        }
        public function get_status() {
                return 'pending';
        }
}

class ActionScheduler_Store {
        private static $instance = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function actions() {
                return S\state_get( 'as-actions.json' );
        }

        public function query_actions( $args = array() ) {
                $hook   = isset( $args['hook'] ) ? (string) $args['hook'] : '';
                $status = isset( $args['status'] ) ? (string) $args['status'] : '';
                $per    = isset( $args['per_page'] ) ? (int) $args['per_page'] : 100;
                $order  = isset( $args['order'] ) ? strtoupper( (string) $args['order'] ) : 'ASC';
                $out    = array();
                foreach ( $this->actions() as $id => $row ) {
                        if ( '' !== $hook && $row['hook'] !== $hook ) {
                                continue;
                        }
                        if ( '' !== $status && $row['status'] !== $status ) {
                                continue;
                        }
                        $out[ $row['created'] . '-' . $id ] = (int) $id;
                }
                ksort( $out, SORT_STRING );
                if ( 'DESC' === $order ) {
                        $out = array_reverse( $out );
                }
                return array_slice( array_values( $out ), 0, max( 1, $per ) );
        }

        public function fetch_action( $id ) {
                $all = $this->actions();
                if ( ! isset( $all[ (int) $id ] ) ) {
                        return new ActionScheduler_Action( '', array() );
                }
                return new ActionScheduler_Action( $all[ (int) $id ]['hook'], $all[ (int) $id ]['args'] );
        }

        public function get_status( $id ) {
                $all = $this->actions();
                return isset( $all[ (int) $id ] ) ? (string) $all[ (int) $id ]['status'] : '';
        }

        public function mark_complete( $id ) {
                S\state_update(
                        'as-actions.json',
                        static function ( $all ) use ( $id ) {
                                if ( isset( $all[ (int) $id ] ) ) {
                                        $all[ (int) $id ]['status'] = 'complete';
                                }
                                return $all;
                        }
                );
        }

        public function mark_failure( $id ) {
                S\state_update(
                        'as-actions.json',
                        static function ( $all ) use ( $id ) {
                                if ( isset( $all[ (int) $id ] ) ) {
                                        $all[ (int) $id ]['status'] = 'failed';
                                }
                                return $all;
                        }
                );
        }
}

class ActionScheduler_Logger {
        private static $instance = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        public function log( $action_id, $message ) {
                S\state_update(
                        'as-logs.json',
                        static function ( $all ) use ( $action_id, $message ) {
                                $max = 0;
                                foreach ( $all as $e ) {
                                        $max = max( $max, (int) $e['log_id'] );
                                }
                                $all[] = array(
                                        'log_id'    => $max + 1,
                                        'action_id' => (int) $action_id,
                                        'message'   => (string) $message,
                                        'time'      => S\current_time( 'mysql' ),
                                );
                                return $all;
                        }
                );
                return true;
        }
}

class ShimWpdb {
        public $prefix = 'wp_';
        public $base_prefix = 'wp_';

        public function prepare( $sql, ...$args ) {
                $i = 0;
                return preg_replace_callback(
                        '/%[dsf]/',
                        static function ( $m ) use ( &$i, $args ) {
                                $v = isset( $args[ $i ] ) ? $args[ $i ] : '';
                                ++$i;
                                if ( '%d' === $m[0] ) {
                                        return (string) (int) $v;
                                }
                                if ( '%f' === $m[0] ) {
                                        return (string) (float) $v;
                                }
                                return "'" . addslashes( (string) $v ) . "'";
                        },
                        (string) $sql
                );
        }

        public function get_results( $sql, $output = 'OBJECT' ) {
                $sql = (string) $sql;
                if ( false === stripos( $sql, 'actionscheduler_logs' ) ) {
                        return array();
                }
                if ( ! preg_match( '/action_id\s*=\s*(\d+)/', $sql, $m ) ) {
                        return array();
                }
                $action_id = (int) $m[1];
                $limit     = preg_match( '/LIMIT\s+(\d+)/i', $sql, $lm ) ? (int) $lm[1] : PHP_INT_MAX;
                $logs      = S\state_get( 'as-logs.json' );
                $rows      = array();
                foreach ( (array) $logs as $entry ) {
                        if ( (int) $entry['action_id'] === $action_id ) {
                                $rows[] = (object) array(
                                        'log_id'    => (int) $entry['log_id'],
                                        'action_id' => (int) $entry['action_id'],
                                        'message'   => (string) $entry['message'],
                                );
                        }
                }
                usort( $rows, static function ( $a, $b ) {
                        return $b->log_id <=> $a->log_id;
                } );
                return array_slice( $rows, 0, $limit );
        }
}

$GLOBALS['wpdb'] = new ShimWpdb();
