<?php
/**
 * WP shim — file-backed shared state (options / transients / cron).
 *
 * Cross-process "DB": one JSON file per store, every mutation under an
 * exclusive flock, readers take a shared lock. Per-process non-persistent
 * cache mirrors WordPress alloptions semantics so wp_cache_delete()/flush()
 * invalidate in-process copies exactly like real WP object caching.
 *
 * @package UltimatePerformance\Tests\Shim
 */

namespace UltimatePerformance\Tests\Shim;

const SHIM_STATE_DIR = __DIR__ . '/../state/';

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Shared-read a JSON state file.
 *
 * @param string $file
 * @return array<string,mixed>
 */
function state_get( $file ) {
        $path = SHIM_STATE_DIR . $file;
        if ( ! file_exists( $path ) ) {
                return array();
        }
        $fh = @fopen( $path, 'r' );
        if ( ! $fh ) {
                return array();
        }
        @flock( $fh, LOCK_SH );
        $raw = (string) stream_get_contents( $fh );
        @flock( $fh, LOCK_UN );
        fclose( $fh );
        // JSON_INVALID_UTF8_SUBSTITUTE: test payloads may deliberately contain
        // poison byte sequences (e.g. audit-queue-callback C5 "\xff\xfe") —
        // they must be persisted (the real DB does) instead of breaking the store.
        $d = json_decode( $raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
        return is_array( $d ) ? $d : array();
}

/**
 * Exclusive write of a JSON state file (read-modify-write under one lock).
 *
 * @param string   $file
 * @param callable $fn function(array $data): array
 * @return array<string,mixed> Final data.
 */
function state_update( $file, $fn ) {
        $path = SHIM_STATE_DIR . $file;
        if ( ! is_dir( SHIM_STATE_DIR ) ) {
                @mkdir( SHIM_STATE_DIR, 0777, true );
        }
        $fh = @fopen( $path, 'c+' );
        if ( ! $fh ) {
                return array();
        }
        $final = array();
        if ( @flock( $fh, LOCK_EX ) ) {
                $raw = (string) stream_get_contents( $fh );
                $d   = json_decode( $raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
                if ( ! is_array( $d ) ) {
                        $d = array();
                }
                $final = call_user_func( $fn, $d );
                if ( ! is_array( $final ) ) {
                        $final = $d;
                }
                ftruncate( $fh, 0 );
                rewind( $fh );
                // JSON_INVALID_UTF8_SUBSTITUTE — see state_get(): poison payloads
                // (audit C5) must round-trip, never corrupt the whole store.
                fwrite( $fh, (string) json_encode( $final, JSON_INVALID_UTF8_SUBSTITUTE ) );
                fflush( $fh );
                @flock( $fh, LOCK_UN );
        }
        fclose( $fh );
        return $final;
}

// ---------------------------------------------------------------------------
// Options (WP semantics: autoloaded options live in one cached row).
// ---------------------------------------------------------------------------

function options_alloptions_cached() {
        return isset( $GLOBALS['up_shim_cache']['alloptions']['options'] );
}

function options_file() {
        return 'options.json';
}

function shim_get_option( $name, $default = false ) {
        // In-process alloptions cache mirrors WP: autoloaded options are served
        // from cache until wp_cache_delete/flush invalidates it.
        if ( options_alloptions_cached() ) {
                $cached = $GLOBALS['up_shim_cache']['alloptions']['options'];
                return array_key_exists( $name, $cached ) ? $cached[ $name ] : $default;
        }
        $all = state_get( options_file() );
        // Rebuild the process cache the same way WP rebuilds alloptions.
        $GLOBALS['up_shim_cache']['alloptions']['options'] = $all;
        return array_key_exists( $name, $all ) ? $all[ $name ] : $default;
}

function shim_update_option( $name, $value, $autoload = null ) {
        state_update(
                options_file(),
                static function ( $all ) use ( $name, $value ) {
                        $all[ $name ] = $value;
                        return $all;
                }
        );
        if ( options_alloptions_cached() ) {
                $GLOBALS['up_shim_cache']['alloptions']['options'][ $name ] = $value;
        }
        return true;
}

function shim_add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
        if ( array_key_exists( $name, state_get( options_file() ) ) ) {
                return false;
        }
        return shim_update_option( $name, $value, $autoload );
}

function shim_delete_option( $name ) {
        state_update(
                options_file(),
                static function ( $all ) use ( $name ) {
                        unset( $all[ $name ] );
                        return $all;
                }
        );
        if ( options_alloptions_cached() ) {
                unset( $GLOBALS['up_shim_cache']['alloptions']['options'][ $name ] );
        }
        return true;
}

// ---------------------------------------------------------------------------
// Transients (options-backed, timeout via paired option).
// ---------------------------------------------------------------------------

function shim_get_transient( $name ) {
        $key     = '_transient_' . $name;
        $tout    = '_transient_timeout_' . $name;
        $timeout = shim_get_option( $tout, 0 );
        if ( is_numeric( $timeout ) && 0 !== (int) $timeout && (int) $timeout < time() ) {
                shim_delete_option( $key );
                shim_delete_option( $tout );
                return false;
        }
        $v = shim_get_option( $key, false );
        return array_key_exists( $key, state_get( options_file() ) ) || false !== $v ? $v : false;
}

function shim_set_transient( $name, $value, $expiration = 0 ) {
        shim_update_option( '_transient_' . $name, $value );
        shim_update_option( '_transient_timeout_' . $name, 0 !== (int) $expiration ? time() + (int) $expiration : 0 );
        return true;
}

function shim_delete_transient( $name ) {
        shim_delete_option( '_transient_' . $name );
        shim_delete_option( '_transient_timeout_' . $name );
        return true;
}

// ---------------------------------------------------------------------------
// Cron schedule store.
// ---------------------------------------------------------------------------
//
// Phase 0.7.0: the store was upgraded to mirror the REAL WordPress cron
// option shape: `[ts => [hook => [args_key => array(schedule, args)]]]`.
// The previous flat `[hook => [ts => true]]` shape did not let CronGuard's
// count_events_for() (which iterates _get_cron_array() the same way
// WordPress core does) find any events.
//
// All shim_* helpers and the cron.json file now use the WP-shaped format.

function shim_cron_events() {
        return state_get( 'cron.json' );
}

/**
 * Return the full cron array in real-WP shape.
 *
 * @return array<int|string,array<string,array<string,array<string,mixed>>>
 */
function shim_cron_array() {
        return shim_cron_events();
}

function shim_next_scheduled( $hook ) {
        $all = shim_cron_events();
        if ( ! is_array( $all ) ) {
                return false;
        }
        $ts_sorted = array_keys( $all );
        $now        = time();
        sort( $ts_sorted );
        foreach ( $ts_sorted as $t ) {
                if ( ! isset( $all[ $t ][ $hook ] ) ) {
                        continue;
                }
                if ( (int) $t >= $now - 5 ) {
                        return (int) $t;
                }
        }
        // Past events: WordPress still reports the earliest one (so a
        // caller can detect "due now") — return the first match.
        foreach ( $ts_sorted as $t ) {
                if ( isset( $all[ $t ][ $hook ] ) ) {
                        return (int) $t;
                }
        }
        return false;
}

function shim_schedule_event( $ts, $recurrence, $hook, $args = array() ) {
        $args = is_array( $args ) ? $args : array();
        state_update(
                'cron.json',
                static function ( $all ) use ( $hook, $ts, $recurrence, $args ) {
                        $ts = (int) $ts;
                        if ( ! isset( $all[ $ts ] ) || ! is_array( $all[ $ts ] ) ) {
                                $all[ $ts ] = array();
                        }
                        if ( ! isset( $all[ $ts ][ $hook ] ) || ! is_array( $all[ $ts ][ $hook ] ) ) {
                                $all[ $ts ][ $hook ] = array();
                        }
                        // 0.7.1: args_key must match the key computed by
                        // shim_unschedule_event so events scheduled WITH args
                        // can be unscheduled by passing the same args. Real
                        // WordPress uses md5(serialize($args)); the shim uses
                        // md5(json_encode($args)) for cross-tool consistency
                        // (PHP serialize format is version-sensitive).
                        $key = md5( (string) json_encode( $args ) );
                        $all[ $ts ][ $hook ][ $key ] = array(
                                'schedule' => $recurrence,
                                'args'     => $args,
                        );
                        return $all;
                }
        );
        return true;
}

function shim_clear_scheduled_hook( $hook ) {
        state_update(
                'cron.json',
                static function ( $all ) use ( $hook ) {
                        if ( ! is_array( $all ) ) {
                                return $all;
                        }
                        foreach ( $all as $ts => $hooks ) {
                                if ( is_array( $hooks ) && isset( $hooks[ $hook ] ) ) {
                                        unset( $all[ $ts ][ $hook ] );
                                }
                                if ( isset( $all[ $ts ] ) && empty( $all[ $ts ] ) ) {
                                        unset( $all[ $ts ] );
                                }
                        }
                        return $all;
                }
        );
}

/**
 * Remove ONE specific (ts, hook, args) instance — mirrors wp_unschedule_event.
 *
 * @param int                $timestamp
 * @param string             $hook
 * @param array<string,mixed> $args
 * @return bool
 */
function shim_unschedule_event( $timestamp, $hook, $args = array() ) {
        $key = md5( (string) json_encode( $args ) );
        state_update(
                'cron.json',
                static function ( $all ) use ( $timestamp, $hook, $key ) {
                        $ts = (int) $timestamp;
                        if ( ! is_array( $all ) || ! isset( $all[ $ts ][ $hook ][ $key ] ) ) {
                                return $all;
                        }
                        unset( $all[ $ts ][ $hook ][ $key ] );
                        if ( empty( $all[ $ts ][ $hook ] ) ) {
                                unset( $all[ $ts ][ $hook ] );
                        }
                        if ( isset( $all[ $ts ] ) && empty( $all[ $ts ] ) ) {
                                unset( $all[ $ts ] );
                        }
                        return $all;
                }
        );
        return true;
}

