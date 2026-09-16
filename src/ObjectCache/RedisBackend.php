<?php
/**
 * Redis object cache backend (Phase I).
 *
 * O(1) invalidation via generation counters — the backend NEVER issues
 * KEYS/SCAN for invalidation and NEVER issues FLUSHALL/FLUSHDB (asserted by
 * the audit suite and by a live sentinel row: keys outside the runtime's
 * namespace survive flush()).
 *
 * Key layout (grp = Manager's scope-embedded group, e.g. "b2::options"):
 *
 *   uc:oc:V{vgen}:G{ggen}:{grp}:{key}    value keys (EX = ttl when ttl > 0)
 *   uc:oc:gen                            root generation counter (flush)
 *   uc:oc:geng:{grp}                     per-group generation (flushGroup)
 *
 * Value encoding: "UC1:" . serialize($value) for general values; integers are
 * stored as bare decimal strings so INCRBY/DECRBY operate server-side and the
 * result round-trips through the same envelope decoder.
 *
 * Connection: explicit configuration only (plugin constants, then runtime
 * environment). TCP host+port OR a UNIX socket path — never a fallback chain
 * between the two. Auth credentials are consumed from the configuration
 * source and are never logged, persisted, or embedded in keys.
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

defined( 'ABSPATH' ) || exit;

final class RedisBackend implements Backend {

        const NS       = 'uc:oc:';
        const ENV_MARK = 'UC1:';

        /** @var \Redis|null */
        private $redis;

        /** @var string */
        private $host = '';
        private $port = 6379;
        private $socket = '';
        private $auth = '';
        private $db = 0;

        /** @var float */
        private $timeout = 1.0;

        /** @var bool connection permanently closed */
        private $closed = false;

        /**
         * Build from explicit parameters (tests / advanced embedders). With NO
         * explicit opts the constructor falls back to the same configuration
         * source as configured() (constants, then environment) — an explicitly
         * configured Redis must never silently degrade to runtime-only.
         *
         * @param array{host?:string,port?:int,socket?:string,auth?:string,db?:int,timeout?:float} $opts
         */
        public function __construct( array $opts = array() ) {
                $this->host   = isset( $opts['host'] ) ? (string) $opts['host'] : self::cfg( 'ULTIMATE_PERFORMANCE_REDIS_HOST', 'UC_REDIS_HOST' );
                $this->port   = isset( $opts['port'] ) ? (int) $opts['port'] : (int) ( self::cfg( 'ULTIMATE_PERFORMANCE_REDIS_PORT', 'UC_REDIS_PORT' ) ?: 6379 );
                $this->socket = isset( $opts['socket'] ) ? (string) $opts['socket'] : self::cfg( 'ULTIMATE_PERFORMANCE_REDIS_SOCKET', 'UC_REDIS_SOCKET' );
                $this->auth   = isset( $opts['auth'] ) ? (string) $opts['auth'] : self::cfg( 'ULTIMATE_PERFORMANCE_REDIS_AUTH', 'UC_REDIS_AUTH' );
                $this->db     = isset( $opts['db'] ) ? (int) $opts['db'] : (int) self::cfg( 'ULTIMATE_PERFORMANCE_REDIS_DB', 'UC_REDIS_DB' );
                $this->timeout = isset( $opts['timeout'] ) ? (float) $opts['timeout'] : 1.0;
                $this->connect();
        }

        /**
         * Factory: build a RedisBackend from the plugin's Settings option when
         * constants/env are NOT set. This closes the gap where Redis was
         * configured in the admin UI but never reached the runtime backend.
         *
         * Returns null when Settings is unavailable (drop-in context before WP
         * bootstrap) OR when the admin has not explicitly enabled object cache
         * + set a redis.host.
         *
         * @return self|null
         */
        public static function from_settings() {
                if ( ! function_exists( 'get_option' ) || ! defined( 'ABSPATH' )
                        || ! class_exists( '\\UltimatePerformance\\Core\\Settings' ) ) {
                        return null;
                }
                // Use the RAW stored option to detect EXPLICIT configuration.
                // Settings::get() merges with defaults, so '127.0.0.1' would
                // always look "configured".
                $raw = get_option( \UltimatePerformance\Core\Settings::OPTION, array() );
                if ( ! is_array( $raw ) ) {
                        return null;
                }
                if ( empty( $raw['object_cache_enabled'] ) ) {
                        return null; // object cache not enabled
                }
                if ( ! isset( $raw['redis']['host'] ) || '' === (string) $raw['redis']['host'] ) {
                        return null; // no explicit redis.host
                }
                $s = \UltimatePerformance\Core\Settings::instance();
                return new self( array(
                        'host'    => (string) $s->get( 'redis.host', '127.0.0.1' ),
                        'port'    => (int) $s->get( 'redis.port', 6379 ),
                        'auth'    => (string) $s->get( 'redis.auth', '' ),
                        'db'      => (int) $s->get( 'redis.db', 0 ),
                        'timeout' => (float) $s->get( 'redis.timeout', 1.5 ),
                ) );
        }

        /**
         * Whether an explicit Redis configuration exists (constants, env, or
         * Settings option). Socket config wins over TCP when both are present
         * (explicit selection, no silent TCP fallback).
         *
         * IMPORTANT: a Settings option counts as "configured" only when:
         *   1. The admin has explicitly enabled object_cache_enabled, AND
         *   2. A redis.host is present in the RAW stored option (the default
         *      '127.0.0.1' alone does NOT count — otherwise the default that
         *      gets merged into the option on ANY save would trigger an
         *      auto-probe even when the admin never configured Redis).
         *
         * @return bool
         */
        public static function configured() {
                if ( self::configured_via_constants() ) {
                        return true;
                }
                if ( ! function_exists( 'get_option' ) || ! defined( 'ABSPATH' )
                        || ! class_exists( '\\UltimatePerformance\\Core\\Settings' ) ) {
                        return false;
                }
                $raw = get_option( \UltimatePerformance\Core\Settings::OPTION, array() );
                if ( ! is_array( $raw ) ) {
                        return false;
                }
                // Object cache must be explicitly enabled by the admin.
                if ( empty( $raw['object_cache_enabled'] ) ) {
                        return false;
                }
                // Redis host must be present in the RAW stored option.
                if ( ! isset( $raw['redis']['host'] ) || '' === (string) $raw['redis']['host'] ) {
                        return false;
                }
                return true;
        }

        /**
         * Whether configuration was supplied via WP constants or env vars
         * (NOT via the admin UI Settings option). Used by Manager::instance()
         * to decide whether to instantiate via the legacy constructor or via
         * from_settings().
         *
         * @return bool
         */
        public static function configured_via_constants() {
                return '' !== self::cfg( 'ULTIMATE_PERFORMANCE_REDIS_SOCKET', 'UC_REDIS_SOCKET' )
                        || '' !== self::cfg( 'ULTIMATE_PERFORMANCE_REDIS_HOST', 'UC_REDIS_HOST' );
        }

        /**
         * @param string $constant WP config constant.
         * @param string $env      Runtime environment variable.
         * @return string '' when unset.
         */
        private static function cfg( $constant, $env ) {
                if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
                        return (string) constant( $constant );
                }
                $v = getenv( $env );
                return false === $v ? '' : (string) $v;
        }

        private function connect() {
                if ( $this->closed || ! class_exists( '\Redis' ) ) {
                        return;
                }
                try {
                        $redis = new \Redis();
                        $ok    = false;
                        if ( '' !== $this->socket ) {
                                $ok = $redis->connect( $this->socket, 0, $this->timeout ); // UNIX socket, explicit
                        } elseif ( '' !== $this->host ) {
                                $ok = $redis->connect( $this->host, (int) $this->port, $this->timeout );
                        }
                        if ( ! $ok ) {
                                return; // stay unconnected; healthy() reports false
                        }
                        if ( '' !== $this->auth ) {
                                $redis->auth( $this->auth );
                        }
                        if ( $this->db > 0 ) {
                                $redis->select( $this->db );
                        }
                        $redis->setOption( \Redis::OPT_PREFIX, '' );
                        $this->redis = $redis;
                } catch ( \Throwable $e ) {
                        $this->redis = null; // fail closed
                }
        }

        private function conn() {
                if ( $this->closed ) {
                        throw new \RuntimeException( 'redis closed' );
                }
                if ( null === $this->redis ) {
                        $this->connect();
                        if ( null === $this->redis ) {
                                throw new \RuntimeException( 'redis unavailable' );
                        }
                }
                return $this->redis;
        }

        // ------------------------------------------------------------- key math

        /**
         * Effective key for a (group, key) pair: reads both generation counters in
         * one pipeline. Correctness-first: counters are NEVER cached locally, so a
         * flush from any process invalidates every other process immediately.
         *
         * @param string $grp   Scope-embedded group.
         * @param string $key   Key.
         * @param \Redis $redis Connection.
         * @return string
         */
        private function effectiveKey( $grp, $key, $redis ) {
                $gens = $redis->pipeline()
                        ->get( self::NS . 'gen' )
                        ->get( self::NS . 'geng:' . $grp )
                        ->exec();
                $v = ( false === $gens || count( $gens ) < 2 ) ? '0' : (string) $gens[0];
                $g = ( false === $gens || count( $gens ) < 2 ) ? '0' : (string) $gens[1];
                return self::NS . 'V' . ( '' === $v ? '0' : $v ) . ':G' . ( '' === $g ? '0' : $g ) . ':' . $grp . ':' . $key;
        }

        private function encode( $value ) {
                if ( is_int( $value ) ) {
                        return (string) $value; // bare integer: INCRBY-compatible
                }
                return self::ENV_MARK . serialize( $value );
        }

        private function decode( $raw ) {
                if ( ! is_string( $raw ) ) {
                        return null;
                }
                if ( 0 === strpos( $raw, self::ENV_MARK ) ) {
                        return unserialize( substr( $raw, strlen( self::ENV_MARK ) ) );
                }
                if ( preg_match( '/^-?\d+$/', $raw ) ) {
                        return (int) $raw;
                }
                return $raw;
        }

        // -------------------------------------------------------------- Backend

        public function get( $key, $group, &$found = null ) {
                $found = false;
                try {
                        $redis = $this->conn();
                        $raw   = $redis->get( $this->effectiveKey( $group, $key, $redis ) );
                        if ( false === $raw ) {
                                return null;
                        }
                        $found = true;
                        return $this->decode( $raw );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return null;
                }
        }

        public function getMultiple( $keys, $group ) {
                $out = array();
                try {
                        $redis = $this->conn();
                        $gens  = $redis->pipeline()
                                ->get( self::NS . 'gen' )
                                ->get( self::NS . 'geng:' . $group )
                                ->exec();
                        $v = ( false === $gens || count( $gens ) < 2 ) ? '0' : (string) $gens[0];
                        $g = ( false === $gens || count( $gens ) < 2 ) ? '0' : (string) $gens[1];
                        $prefix = self::NS . 'V' . ( '' === $v ? '0' : $v ) . ':G' . ( '' === $g ? '0' : $g ) . ':' . $group . ':';
                        $pipe   = $redis->pipeline();
                        foreach ( $keys as $k ) {
                                $pipe->get( $prefix . $k );
                        }
                        $raws = $pipe->exec();
                        if ( ! is_array( $raws ) ) {
                                return $out;
                        }
                        $i = 0;
                        foreach ( $keys as $k ) {
                                $raw       = $raws[ $i++ ] ?? false;
                                $out[ $k ] = array( 'value' => false === $raw ? null : $this->decode( $raw ), 'found' => false !== $raw );
                        }
                        return $out;
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        foreach ( $keys as $k ) {
                                $out[ $k ] = array( 'value' => null, 'found' => false );
                        }
                        return $out;
                }
        }

        public function set( $key, $value, $ttl, $group ) {
                try {
                        $redis = $this->conn();
                        $k     = $this->effectiveKey( $group, $key, $redis );
                        $ttl   = (int) $ttl;
                        return (bool) ( $ttl > 0
                                ? $redis->set( $k, $this->encode( $value ), array( 'ex' => $ttl ) )
                                : $redis->set( $k, $this->encode( $value ) ) );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function add( $key, $value, $ttl, $group ) {
                try {
                        $redis = $this->conn();
                        $k     = $this->effectiveKey( $group, $key, $redis );
                        $ttl   = (int) $ttl;
                        $opt   = array( 'nx' );
                        if ( $ttl > 0 ) {
                                $opt['ex'] = $ttl;
                        }
                        return (bool) $redis->set( $k, $this->encode( $value ), $opt );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function replace( $key, $value, $ttl, $group ) {
                try {
                        $redis = $this->conn();
                        $k     = $this->effectiveKey( $group, $key, $redis );
                        $ttl   = (int) $ttl;
                        $opt   = array( 'xx' );
                        if ( $ttl > 0 ) {
                                $opt['ex'] = $ttl;
                        }
                        return (bool) $redis->set( $k, $this->encode( $value ), $opt );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function delete( $key, $group ) {
                try {
                        $redis = $this->conn();
                        return ( (int) $redis->del( $this->effectiveKey( $group, $key, $redis ) ) ) > 0;
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function incr( $key, $n, $group ) {
                return $this->arith( $key, (int) $n, $group, 'INCRBY' );
        }

        public function decr( $key, $n, $group ) {
                return $this->arith( $key, (int) $n, $group, 'DECRBY' );
        }

        /**
         * Atomic exists-guarded arithmetic (Lua) — missing keys return false and
         * are never auto-created (WP semantics); the value stays a bare integer
         * for future INCRBY round-trips.
         *
         * @return int|false
         */
        private function arith( $key, $n, $group, $op ) {
                try {
                        $redis = $this->conn();
                        $k     = $this->effectiveKey( $group, $key, $redis );
                        $lua   = "if redis.call('EXISTS', KEYS[1]) == 1 then return redis.call('" . $op . "', KEYS[1], ARGV[1]) else return false end";
                        $r     = $redis->eval( $lua, array( $k, (string) $n ), 1 );
                        return false === $r ? false : (int) $r;
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function flushGroup( $group ) {
                try {
                        $redis = $this->conn();
                        return (bool) $redis->incr( self::NS . 'geng:' . $group );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function flush() {
                try {
                        $redis = $this->conn();
                        return (bool) $redis->incr( self::NS . 'gen' );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function healthy() {
                if ( $this->closed ) {
                        return false;
                }
                try {
                        $redis = $this->conn();
                        if ( ! $redis->ping() ) {
                                return false;
                        }
                        // Write capability probe on a dedicated health key (self-cleaning).
                        $hk = self::NS . 'health';
                        if ( ! $redis->set( $hk, '1', array( 'ex' => 5 ) ) ) {
                                return false;
                        }
                        $redis->del( $hk );
                        return true;
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function close() {
                if ( null !== $this->redis ) {
                        try {
                                $this->redis->close();
                        } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                                // close must never throw.
                        }
                }
                $this->redis  = null;
                $this->closed = true;
        }

        /**
         * Drop the connection object; the next op reconnects (bounded by the
         * Manager's recheck window).
         *
         * @return void
         */
        private function degrade() {
                if ( null !== $this->redis ) {
                        try {
                                $this->redis->close();
                        } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        }
                }
                $this->redis = null;
        }

        /**
         * Capability map (all 8 WP object cache features).
         *
         * @return array<string,bool>
         */
        public function features() {
                return array(
                        'add_multiple'  => true,
                        'set_multiple'  => true,
                        'get_multiple'  => true,
                        'flush_runtime' => true,
                        'flush_group'   => true,
                        'incr'          => true,
                        'decr'          => true,
                        'group'         => true,
                );
        }
}
