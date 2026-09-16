<?php
/**
 * Per-process memory backend (Phase I).
 *
 * Always available; the reference implementation of the Backend contract.
 * Honest non-persistence: data lives for the lifetime of THIS process only —
 * the Manager never presents it as a persistent store.
 *
 * Layout: data[group][key] = array( 'v' => mixed, 'e' => int expiry (0 = none) ).
 * Values are stored by reference-value (PHP copies); any PHP value round-trips,
 * so the found-flag contract holds for false/null/0/'' as well.
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

defined( 'ABSPATH' ) || exit;

final class MemoryBackend implements Backend {

        /** @var array<string,array<string,array{v:mixed,e:int}>> */
        private $data = array();

        /** @var array<string,int> hits/misses per group-agnostic counters */
        private $hits   = 0;
        private $misses = 0;

        public function get( $key, $group, &$found = null ) {
                $found = false;
                if ( ! isset( $this->data[ $group ] ) || ! array_key_exists( $key, $this->data[ $group ] ) ) {
                        ++$this->misses;
                        return null;
                }
                $slot = $this->data[ $group ][ $key ];
                if ( 0 !== $slot['e'] && $slot['e'] <= microtime( true ) ) {
                        unset( $this->data[ $group ][ $key ] ); // lazy expiry
                        ++$this->misses;
                        return null;
                }
                $found = true;
                ++$this->hits;
                return $slot['v'];
        }

        public function getMultiple( $keys, $group ) {
                $out = array();
                foreach ( $keys as $k ) {
                        $found        = false;
                        $v            = $this->get( $k, $group, $found );
                        $out[ $k ]    = array( 'value' => $v, 'found' => $found );
                }
                return $out;
        }

        public function set( $key, $value, $ttl, $group ) {
                if ( ! is_string( $key ) || '' === $key ) {
                        return false;
                }
                $this->data[ $group ][ $key ] = array( 'v' => $value, 'e' => self::expiry( $ttl ) );
                return true;
        }

        public function add( $key, $value, $ttl, $group ) {
                if ( ! is_string( $key ) || '' === $key ) {
                        return false;
                }
                if ( isset( $this->data[ $group ] ) && array_key_exists( $key, $this->data[ $group ] )
                        && ! $this->expired( $group, $key ) ) {
                        return false;
                }
                $this->data[ $group ][ $key ] = array( 'v' => $value, 'e' => self::expiry( $ttl ) );
                return true;
        }

        public function replace( $key, $value, $ttl, $group ) {
                if ( ! isset( $this->data[ $group ] ) || ! array_key_exists( $key, $this->data[ $group ] )
                        || $this->expired( $group, $key ) ) {
                        return false;
                }
                $this->data[ $group ][ $key ] = array( 'v' => $value, 'e' => self::expiry( $ttl ) );
                return true;
        }

        public function delete( $key, $group ) {
                if ( ! isset( $this->data[ $group ] ) || ! array_key_exists( $key, $this->data[ $group ] ) ) {
                        return false;
                }
                unset( $this->data[ $group ][ $key ] );
                return true;
        }

        public function incr( $key, $n, $group ) {
                if ( ! isset( $this->data[ $group ] ) || ! array_key_exists( $key, $this->data[ $group ] )
                        || $this->expired( $group, $key ) ) {
                        return false;
                }
                $v = $this->data[ $group ][ $key ]['v'];
                if ( ! is_int( $v ) ) {
                        return false;
                }
                $v += (int) $n;
                $this->data[ $group ][ $key ]['v'] = $v;
                return $v;
        }

        public function decr( $key, $n, $group ) {
                return $this->incr( $key, -( (int) $n ), $group );
        }

        public function flushGroup( $group ) {
                unset( $this->data[ $group ] );
                return true;
        }

        public function flush() {
                $this->data = array();
                return true;
        }

        public function healthy() {
                return true;
        }

        public function close() {
                $this->data = array();
        }

        /**
         * Expiry absolute timestamp from a TTL; 0 / negative TTL = no expiry.
         *
         * @param int $ttl Seconds.
         * @return float 0 when persistent.
         */
        private static function expiry( $ttl ) {
                $ttl = (int) $ttl;
                if ( $ttl <= 0 ) {
                        return 0;
                }
                return microtime( true ) + $ttl;
        }

        /**
         * @param string $group Group.
         * @param string $key   Key.
         * @return bool True when the slot exists but has expired.
         */
        private function expired( $group, $key ) {
                $slot = $this->data[ $group ][ $key ];
                return 0 !== $slot['e'] && $slot['e'] <= microtime( true );
        }

        /**
         * Test/diagnostic counters.
         *
         * @return array{hits:int,misses:int}
         */
        public function stats() {
                return array( 'hits' => $this->hits, 'misses' => $this->misses );
        }

        /**
         * Capability map for wp_cache_supports(). The reference backend implements
         * the full WP feature set (persistence is NOT a feature flag — it is a
         * property disclosed by the backend class itself).
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
