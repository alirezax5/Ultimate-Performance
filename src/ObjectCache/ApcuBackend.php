<?php
/**
 * APCu object cache backend (Phase J).
 *
 * Honest local persistence: APCu memory is shared across the SAPI's workers
 * on ONE server (FPM/mod_php) and — deliberately — NOT pretended to be
 * anything else. Under CLI it is per-process (apc.enable_cli grants memory
 * only to this process); the capability matrix and this docblock state that
 * plainly. It is never advertised as a distributed store.
 *
 * O(1) invalidation via the SAME generation-counter + effective-key scheme
 * as the Redis/Memcached backends:
 *
 *   uc:oc:V{vgen}:G{ggen}:{grp}:{key}
 *
 * apcu_clear_cache() is NEVER used (audit suite proves a foreign key
 * survives every flush this runtime performs).
 *
 * Known and disclosed: APCu has no server-side "replace if exists" for
 * arbitrary values — replace() is check-then-store (atomic within one
 * process, TOCTOU window across processes). Disclosed in the capability
 * matrix; never claimed as CAS.
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

defined( 'ABSPATH' ) || exit;

final class ApcuBackend implements Backend {

        const NS = 'uc:oc:';

        /**
         * Generation-prefixed effective key. Counters are read fresh on every op
         * (never cached), so a flush from any process invalidates immediately.
         *
         * @param string $grp Scope-embedded group.
         * @return string
         */
        private function effectivePrefix( $grp ) {
                $v   = $this->readCounter( self::NS . 'gen' );
                $g   = $this->readCounter( self::NS . 'geng:' . $grp );
                return self::NS . 'V' . $v . ':G' . $g . ':' . $grp . ':';
        }

        private function readCounter( $ctr ) {
                $success = false;
                $v       = apcu_fetch( $ctr, $success );
                return ( $success && is_int( $v ) ) ? (string) $v : '0';
        }

        public function get( $key, $group, &$found = null ) {
                $found = false;
                if ( ! function_exists( 'apcu_fetch' ) || ! apcu_enabled() ) {
                        return null;
                }
                $success = false;
                $v       = apcu_fetch( $this->effectivePrefix( $group ) . $key, $success );
                if ( ! $success ) {
                        return null;
                }
                $found = true;
                return $v;
        }

        public function getMultiple( $keys, $group ) {
                $out = array();
                if ( ! function_exists( 'apcu_fetch' ) || ! apcu_enabled() ) {
                        foreach ( (array) $keys as $k ) {
                                $out[ $k ] = array( 'value' => null, 'found' => false );
                        }
                        return $out;
                }
                $pre       = $this->effectivePrefix( $group );
                $full_keys = array();
                foreach ( (array) $keys as $k ) {
                        $full_keys[] = $pre . $k;
                }
                $got = apcu_fetch( $full_keys ); // only found keys returned
                foreach ( (array) $keys as $k ) {
                        $full = $pre . $k;
                        if ( is_array( $got ) && array_key_exists( $full, $got ) ) {
                                $out[ $k ] = array( 'value' => $got[ $full ], 'found' => true );
                        } else {
                                $success   = false;
                                $v         = apcu_fetch( $full, $success );
                                $out[ $k ] = array( 'value' => $success ? $v : null, 'found' => $success );
                        }
                }
                return $out;
        }

        public function set( $key, $value, $ttl, $group ) {
                if ( ! function_exists( 'apcu_store' ) || ! apcu_enabled() ) {
                        return false;
                }
                return (bool) apcu_store( $this->effectivePrefix( $group ) . $key, $value, (int) $ttl );
        }

        public function add( $key, $value, $ttl, $group ) {
                if ( ! function_exists( 'apcu_add' ) || ! apcu_enabled() ) {
                        return false;
                }
                return (bool) apcu_add( $this->effectivePrefix( $group ) . $key, $value, (int) $ttl );
        }

        public function replace( $key, $value, $ttl, $group ) {
                if ( ! function_exists( 'apcu_store' ) || ! apcu_enabled() ) {
                        return false;
                }
                // Check-then-store: atomic in-process; TOCTOU window across processes
                // is a documented APCu limitation (see class docblock).
                $success = false;
                apcu_fetch( $this->effectivePrefix( $group ) . $key, $success );
                if ( ! $success ) {
                        return false;
                }
                return (bool) apcu_store( $this->effectivePrefix( $group ) . $key, $value, (int) $ttl );
        }

        public function delete( $key, $group ) {
                if ( ! function_exists( 'apcu_delete' ) || ! apcu_enabled() ) {
                        return false;
                }
                return (bool) @apcu_delete( $this->effectivePrefix( $group ) . $key );
        }

        public function incr( $key, $n, $group ) {
                if ( ! function_exists( 'apcu_inc' ) || ! apcu_enabled() ) {
                        return false;
                }
                $k = $this->effectivePrefix( $group ) . $key;
                // APCu >= 5.1.24 auto-creates counters on apcu_inc — probe existence
                // FIRST so missing keys are never auto-created (WP semantics).
                $success = false;
                apcu_fetch( $k, $success );
                if ( ! $success ) {
                        return false;
                }
                $success = false;
                $r       = apcu_inc( $k, (int) $n, $success );
                return $success ? (int) $r : false;
        }

        public function decr( $key, $n, $group ) {
                if ( ! function_exists( 'apcu_inc' ) || ! apcu_enabled() ) {
                        return false;
                }
                $k = $this->effectivePrefix( $group ) . $key;
                $success = false;
                apcu_fetch( $k, $success );
                if ( ! $success ) {
                        return false;
                }
                $success = false;
                $r       = apcu_inc( $k, -( (int) $n ), $success );
                return $success ? (int) $r : false;
        }

        public function flushGroup( $group ) {
                return $this->bump( self::NS . 'geng:' . $group );
        }

        public function flush() {
                // NEVER apcu_clear_cache(): bumping the root counter invalidates every
                // key this runtime wrote, in O(1), touching nothing else in APCu.
                return $this->bump( self::NS . 'gen' );
        }

        /**
         * Bump a generation counter; create it atomically when missing. Bounded
         * retry (inc → add → inc) covers the tiny create race between processes.
         *
         * @param string $ctr Counter key.
         * @return bool
         */
        private function bump( $ctr ) {
                if ( ! function_exists( 'apcu_inc' ) || ! apcu_enabled() ) {
                        return false;
                }
                for ( $i = 0; $i < 3; ++$i ) {
                        $success = false;
                        apcu_inc( $ctr, 1, $success );
                        if ( $success ) {
                                return true;
                        }
                        if ( apcu_add( $ctr, 1, 0 ) ) {
                                return true;
                        }
                }
                return false;
        }

        public function healthy() {
                return function_exists( 'apcu_enabled' ) && apcu_enabled();
        }

        public function close() {
                // nothing to release; shared memory lifetime is the SAPI's
        }

        /**
         * Capability map. All 8 WP features are implemented; group flushes are
         * generation-based exactly like the other backends.
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
