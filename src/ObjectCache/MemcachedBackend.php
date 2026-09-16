<?php
/**
 * Memcached object cache backend (Phase J).
 *
 * ext-memcached against a real memcached daemon. Explicit configuration only
 * (constants, then environment). UNIX socket selection wins over TCP — never
 * a fallback chain between the two (Phase K requirement built in from J).
 *
 * O(1) invalidation, same generation-counter scheme as the Redis backend —
 * the daemon's global flush_all is NEVER used (the audit suite proves a
 * foreign-namespaced key survives every flush this runtime performs).
 *
 * Found-flag contract: libmemcached result codes distinguish "stored false"
 * (RES_SUCCESS) from "absent" (RES_NOTFOUND), so any PHP value round-trips.
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

defined( 'ABSPATH' ) || exit;

final class MemcachedBackend implements Backend {

        const NS = 'uc:oc:';

        /** @var \Memcached|null */
        private $mc;

        /** @var string */
        private $host = '';
        private $port = 11211;
        private $socket = '';

        /** @var bool */
        private $closed = false;

        /**
         * @param array{host?:string,port?:int,socket?:string} $opts Explicit config (tests/embedders).
         */
        public function __construct( array $opts = array() ) {
                $this->host   = isset( $opts['host'] ) ? (string) $opts['host'] : '';
                $this->port   = isset( $opts['port'] ) ? (int) $opts['port'] : 11211;
                $this->socket = isset( $opts['socket'] ) ? (string) $opts['socket'] : '';
                if ( '' === $this->host && '' === $this->socket ) {
                        $this->host   = self::cfg( 'ULTIMATE_PERFORMANCE_MEMCACHED_HOST', 'UC_MEMCACHED_HOST' );
                        $this->port   = (int) ( self::cfg( 'ULTIMATE_PERFORMANCE_MEMCACHED_PORT', 'UC_MEMCACHED_PORT' ) ?: 11211 );
                        $this->socket = self::cfg( 'ULTIMATE_PERFORMANCE_MEMCACHED_SOCKET', 'UC_MEMCACHED_SOCKET' );
                }
                $this->connect();
        }

        /**
         * Factory: build a MemcachedBackend from the plugin's Settings option
         * when constants/env are NOT set. Mirrors the RedisBackend pattern.
         *
         * Returns null when Settings is unavailable OR when the admin has not
         * explicitly enabled object cache + set a memcached.host.
         *
         * @return self|null
         */
        public static function from_settings() {
                if ( ! function_exists( 'get_option' ) || ! defined( 'ABSPATH' )
                        || ! class_exists( '\\UltimatePerformance\\Core\\Settings' ) ) {
                        return null;
                }
                $raw = get_option( \UltimatePerformance\Core\Settings::OPTION, array() );
                if ( ! is_array( $raw ) ) {
                        return null;
                }
                if ( empty( $raw['object_cache_enabled'] ) ) {
                        return null;
                }
                if ( ! isset( $raw['memcached']['host'] ) || '' === (string) $raw['memcached']['host'] ) {
                        return null;
                }
                $s = \UltimatePerformance\Core\Settings::instance();
                return new self( array(
                        'host'    => (string) $s->get( 'memcached.host', '127.0.0.1' ),
                        'port'    => (int) $s->get( 'memcached.port', 11211 ),
                        'timeout' => (float) $s->get( 'memcached.timeout', 1.5 ),
                ) );
        }

        /**
         * Whether an explicit Memcached configuration exists (constants, env,
         * or Settings option).
         *
         * IMPORTANT: a Settings option counts as "configured" only when:
         *   1. The admin has explicitly enabled object_cache_enabled, AND
         *   2. A memcached.host is present in the RAW stored option.
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
                if ( empty( $raw['object_cache_enabled'] ) ) {
                        return false;
                }
                if ( ! isset( $raw['memcached']['host'] ) || '' === (string) $raw['memcached']['host'] ) {
                        return false;
                }
                return true;
        }

        /**
         * Whether configuration was supplied via WP constants or env vars
         * (NOT via the admin UI Settings option).
         *
         * @return bool
         */
        public static function configured_via_constants() {
                return '' !== self::cfg( 'ULTIMATE_PERFORMANCE_MEMCACHED_SOCKET', 'UC_MEMCACHED_SOCKET' )
                        || '' !== self::cfg( 'ULTIMATE_PERFORMANCE_MEMCACHED_HOST', 'UC_MEMCACHED_HOST' );
        }

        private static function cfg( $constant, $env ) {
                if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
                        return (string) constant( $constant );
                }
                $v = getenv( $env );
                return false === $v ? '' : (string) $v;
        }

        private function connect() {
                if ( $this->closed || ! class_exists( '\Memcached' ) ) {
                        return;
                }
                try {
                        $mc = new \Memcached();
                        $mc->setOptions( array(
                                \Memcached::OPT_BINARY_PROTOCOL => true,  // needed for increment-initial
                                \Memcached::OPT_COMPRESSION     => false, // deterministic values
                                // N4-D1 (§23): explicit I/O bounds. libmemcached's
                                // default poll/recv window is ~5s per call — a wedged
                                // daemon (accepts, never replies) would add ~10s per
                                // request (generation getMulti + value get). Local
                                // daemons answer in <1ms; remote daemons get a 1s
                                // per-op budget. UNIX-socket connects are unaffected.
                                \Memcached::OPT_CONNECT_TIMEOUT => 1000,
                                \Memcached::OPT_SEND_TIMEOUT    => 1000,
                                \Memcached::OPT_RECV_TIMEOUT    => 1000,
                                \Memcached::OPT_POLL_TIMEOUT    => 1500,
                        ) );
                        if ( '' !== $this->socket ) {
                                if ( ! $mc->addServer( $this->socket, 0 ) ) {
                                        return; // stay unconnected; healthy() reports false
                                }
                        } elseif ( '' !== $this->host ) {
                                if ( ! $mc->addServer( $this->host, $this->port ) ) {
                                        return;
                                }
                        } else {
                                return; // no explicit config: fail closed
                        }
                        $this->mc = $mc;
                } catch ( \Throwable $e ) {
                        $this->mc = null;
                }
        }

        private function conn() {
                if ( $this->closed ) {
                        throw new \RuntimeException( 'memcached closed' );
                }
                if ( null === $this->mc ) {
                        $this->connect();
                        if ( null === $this->mc ) {
                                throw new \RuntimeException( 'memcached unavailable' );
                        }
                }
                return $this->mc;
        }

        /**
         * Effective key from the two generation counters (one getMulti).
         *
         * @param string     $grp Scope-embedded group.
         * @param \Memcached $mc  Connection.
         * @return string
         */
        private function effectiveKey( $grp, $mc ) {
                $gens = $mc->getMulti( array( self::NS . 'gen', self::NS . 'geng:' . $grp ) );
                $v    = isset( $gens[ self::NS . 'gen' ] ) ? (string) (int) $gens[ self::NS . 'gen' ] : '0';
                $g    = isset( $gens[ self::NS . 'geng:' . $grp ] ) ? (string) (int) $gens[ self::NS . 'geng:' . $grp ] : '0';
                return self::NS . 'V' . $v . ':G' . $g . ':' . $grp . ':';
        }

        /**
         * Increment a generation counter, creating it atomically when missing
         * (binary-protocol increment-initial). Bounded retry covers the create
         * race; the bump MUST take effect (a lost bump would break flush).
         *
         * @param \Memcached $mc  Connection.
         * @param string     $ctr Counter key.
         * @return bool
         */
        private function bumpCounter( $mc, $ctr ) {
                for ( $i = 0; $i < 3; ++$i ) {
                        $r = $mc->increment( $ctr, 1, 1, 0 );
                        if ( false !== $r || \Memcached::RES_SUCCESS === $mc->getResultCode() ) {
                                return true;
                        }
                        if ( $mc->add( $ctr, 1, 0 ) ) {
                                return true;
                        }
                }
                return false;
        }

        public function get( $key, $group, &$found = null ) {
                $found = false;
                try {
                        $mc  = $this->conn();
                        $pre = $this->effectiveKey( $group, $mc );
                        $raw = $mc->get( $pre . $key );
                        $rc  = $mc->getResultCode();
                        if ( \Memcached::RES_NOTFOUND === $rc ) {
                                return null; // absent
                        }
                        if ( \Memcached::RES_SUCCESS !== $rc ) {
                                return null;
                        }
                        $found = true; // RES_SUCCESS even for stored false/null
                        return $raw;
                } catch ( \Throwable $e ) {
                        return null;
                }
        }

        public function getMultiple( $keys, $group ) {
                $out = array();
                try {
                        $mc    = $this->conn();
                        $gens  = $mc->getMulti( array( self::NS . 'gen', self::NS . 'geng:' . $group ) );
                        $v     = isset( $gens[ self::NS . 'gen' ] ) ? (string) (int) $gens[ self::NS . 'gen' ] : '0';
                        $g     = isset( $gens[ self::NS . 'geng:' . $group ] ) ? (string) (int) $gens[ self::NS . 'geng:' . $group ] : '0';
                        $pre   = self::NS . 'V' . $v . ':G' . $g . ':' . $group . ':';
                        $want  = array();
                        foreach ( $keys as $k ) {
                                $want[] = $pre . $k;
                        }
                        $got = $mc->getMulti( $want );
                        foreach ( $keys as $k ) {
                                $full = $pre . $k;
                                if ( is_array( $got ) && array_key_exists( $full, $got ) ) {
                                        $out[ $k ] = array( 'value' => $got[ $full ], 'found' => true );
                                } else {
                                        // absent or expired: a single get with result code verifies honestly
                                        $mc->get( $full );
                                        $found     = ( \Memcached::RES_SUCCESS === $mc->getResultCode() );
                                        $out[ $k ] = array( 'value' => $found ? $mc->get( $full ) : null, 'found' => $found );
                                }
                        }
                        return $out;
                } catch ( \Throwable $e ) {
                        foreach ( $keys as $k ) {
                                $out[ $k ] = array( 'value' => null, 'found' => false );
                        }
                        return $out;
                }
        }

        public function set( $key, $value, $ttl, $group ) {
                try {
                        $mc  = $this->conn();
                        $pre = $this->effectiveKey( $group, $mc );
                        return (bool) $mc->set( $pre . $key, $value, (int) $ttl );
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function add( $key, $value, $ttl, $group ) {
                try {
                        $mc  = $this->conn();
                        $pre = $this->effectiveKey( $group, $mc );
                        return (bool) $mc->add( $pre . $key, $value, (int) $ttl );
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function replace( $key, $value, $ttl, $group ) {
                try {
                        $mc  = $this->conn();
                        $pre = $this->effectiveKey( $group, $mc );
                        return (bool) $mc->replace( $pre . $key, $value, (int) $ttl );
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function delete( $key, $group ) {
                try {
                        $mc  = $this->conn();
                        $pre = $this->effectiveKey( $group, $mc );
                        return (bool) $mc->delete( $pre . $key );
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function incr( $key, $n, $group ) {
                try {
                        $mc  = $this->conn();
                        $pre = $this->effectiveKey( $group, $mc );
                        // WP semantics: incr on a missing key returns false (never
                        // auto-creates). All other backends (Redis via Lua EXISTS
                        // guard, APCu via apcu_fetch pre-probe, SQLite via
                        // BEGIN-IMMEDIATE exists check, File via flock+exists)
                        // implement the same contract — verified consistent.
                        // libmemcached's binary increment-with-initial would
                        // auto-create; we probe existence FIRST to keep parity.
                        $exists = $mc->get( $pre . $key );
                        $rc     = $mc->getResultCode();
                        if ( \Memcached::RES_NOTFOUND === $rc ) {
                                return false;
                        }
                        if ( \Memcached::RES_SUCCESS !== $rc ) {
                                return false;
                        }
                        $r = $mc->increment( $pre . $key, (int) $n );
                        return ( \Memcached::RES_SUCCESS === $mc->getResultCode() ) ? (int) $r : false;
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function decr( $key, $n, $group ) {
                try {
                        $mc  = $this->conn();
                        $pre = $this->effectiveKey( $group, $mc );
                        // WP semantics: decr on a missing key returns false.
                        $exists = $mc->get( $pre . $key );
                        $rc     = $mc->getResultCode();
                        if ( \Memcached::RES_NOTFOUND === $rc ) {
                                return false;
                        }
                        if ( \Memcached::RES_SUCCESS !== $rc ) {
                                return false;
                        }
                        $r = $mc->decrement( $pre . $key, (int) $n );
                        return ( \Memcached::RES_SUCCESS === $mc->getResultCode() ) ? (int) $r : false;
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function flushGroup( $group ) {
                try {
                        $mc = $this->conn();
                        return false !== $this->bumpCounter( $mc, self::NS . 'geng:' . $group );
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function flush() {
                try {
                        $mc = $this->conn();
                        // NEVER flush_all: bumping the root counter invalidates every key
                        // this runtime wrote, in O(1), touching nothing else on the server.
                        return false !== $this->bumpCounter( $mc, self::NS . 'gen' );
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function healthy() {
                if ( $this->closed ) {
                        return false;
                }
                try {
                        $mc = $this->conn();
                        $hk = self::NS . 'health';
                        if ( ! $mc->set( $hk, '1', 5 ) ) {
                                return false;
                        }
                        $mc->delete( $hk );
                        return true;
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        public function close() {
                if ( null !== $this->mc ) {
                        try {
                                $this->mc->quit();
                        } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        }
                }
                $this->mc     = null;
                $this->closed = true;
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
