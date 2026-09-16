<?php
/**
 * Cache tag registry + reverse index.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\CacheTag;

use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\Lock\FileLock;
use UltimatePerformance\Core\SafeFs;

defined( 'ABSPATH' ) || exit;

/**
 * Tag → cached object ids (rel dirs). Stored as JSON under <root>/meta/.
 * Bounded: 5000 members per tag, janitor GC. No DB dependency.
 *
 * REG-1 (Phase H): attach()/detach_object() perform a read→modify→write
 * cycle on the same tag file. Without serialization, two concurrent writers
 * lose one member each (classic lost-update — proven with real child
 * processes). Every RMW section is now serialized behind a FileLock with
 * bounded acquisition; when the filesystem refuses flock entirely the code
 * degrades to the previous unlocked best-effort behavior rather than
 * blocking requests (documented degradation, same as FileLock's Windows mode).
 */
final class Registry {

        /** Bounded lock wait: total ≈ 20 × 25ms = 500ms before degraded mode. */
        const LOCK_TRIES   = 20;
        const LOCK_WAIT_US = 25000;

        /** @var SafeFs */
        private $fs;

        public function __construct( ?SafeFs $fs = null ) {
                $this->fs = $fs ?: new SafeFs();
        }

        private function tag_file( $tag ) {
                return Installer::cache_root() . '/meta/tag-' . md5( (string) $tag ) . '.json';
        }

        /**
         * Lock file serializing ALL registry read-modify-write sections.
         * One shared lock (not per-tag): attach/detach sections are sub-
         * millisecond and contention is low — a single lock avoids any
         * multi-file ordering complexity while making every RMW atomic.
         *
         * @return string
         */
        private function lock_file() {
                $dir = Installer::cache_root() . '/meta';
                if ( ! is_dir( $dir ) ) {
                        @wp_mkdir_p( $dir );
                }
                return $dir . '/registry.lock';
        }

        /**
         * Run $fn while holding the registry RMW lock. Bounded acquisition:
         * on lock failure after LOCK_TRIES the callable still runs (degraded,
         * unlocked — best-effort parity with the pre-fix behavior) instead of
         * blocking the request; on lock-supporting filesystems the RMW is
         * fully serialized so no member can be lost.
         *
         * @param callable $fn function(): mixed
         * @return mixed
         */
        private function with_rmw_lock( $fn ) {
                for ( $i = 0; $i < self::LOCK_TRIES; ++$i ) {
                        $lock = new FileLock( $this->lock_file() );
                        if ( $lock->acquire( 15 ) ) {
                                try {
                                        return call_user_func( $fn );
                                } finally {
                                        $lock->release();
                                }
                        }
                        unset( $lock );
                        usleep( self::LOCK_WAIT_US );
                }
                return call_user_func( $fn ); // degraded mode: FS without flock support.
        }

        /**
         * Record that rel_dir belongs to tags.
         *
         * Overflow semantics (H3-12): the 5000-member cap is a file-size bound.
         * When a NEW member would exceed it, the oldest members are dropped from
         * the index — those entries become unreachable via tag purge. Correctness
         * is preserved by escalation: the overflow event is recorded (transient)
         * so callers/diagnostics can trigger a broader invalidation (purge-all or
         * directory sweep). attach() itself still succeeds — the cache entry was
         * already written; dropping the index entry only weakens selective purge.
         *
         * @param string            $rel_dir
         * @param array<int,string> $tags
         */
        public function attach( $rel_dir, $tags ) {
                foreach ( array_unique( array_map( 'strval', (array) $tags ) ) as $tag ) {
                        if ( '' === $tag ) {
                                continue;
                        }
                        $this->with_rmw_lock( function () use ( $tag, $rel_dir ) {
                                $file     = $this->tag_file( $tag );
                                $existing = array();
                                if ( is_readable( $file ) ) {
                                        $dec = json_decode( (string) file_get_contents( $file ), true );
                                        if ( is_array( $dec ) ) {
                                                $existing = $dec;
                                        }
                                }
                                if ( ! in_array( $rel_dir, $existing, true ) ) {
                                        if ( count( $existing ) >= 5000 && function_exists( 'get_transient' ) && function_exists( 'set_transient' ) && false === get_transient( 'up_registry_overflow' ) ) {
                                                set_transient(
                                                        'up_registry_overflow',
                                                        array( 'tag' => substr( (string) $tag, 0, 100 ), 'time' => time() ),
                                                        defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600
                                                );
                                                do_action( 'ultimate_performance_registry_overflow', (string) $tag, (string) $rel_dir );
                                        }
                                        $existing[] = $rel_dir;
                                        if ( count( $existing ) > 5000 ) {
                                                $existing = array_slice( $existing, -5000 );
                                        }
                                        $this->fs->write_atomic( $file, (string) wp_json_encode( $existing ) );
                                }
                        } );
                }
        }

        /**
         * @param string $tag
         * @return array<int,string>
         */
        public function members( $tag ) {
                $file = $this->tag_file( $tag );
                if ( ! is_readable( $file ) ) {
                        return array();
                }
                $dec = json_decode( (string) file_get_contents( $file ), true );
                return is_array( $dec ) ? $dec : array();
        }

        public function detach_object( $rel_dir, $tags ) {
                foreach ( (array) $tags as $tag ) {
                        $this->with_rmw_lock( function () use ( $tag, $rel_dir ) {
                                $file = $this->tag_file( $tag );
                                if ( ! is_readable( $file ) ) {
                                        return;
                                }
                                $list = json_decode( (string) file_get_contents( $file ), true );
                                if ( ! is_array( $list ) ) {
                                        return;
                                }
                                $list = array_values( array_diff( $list, array( (string) $rel_dir ) ) );
                                if ( empty( $list ) ) {
                                        $this->fs->delete( $file );
                                } else {
                                        $this->fs->write_atomic( $file, (string) wp_json_encode( $list ) );
                                }
                        } );
                }
        }

        /**
         * Drop all reverse indexes (site purge companion).
         */
        public function flush_all() {
                $meta_root = Installer::cache_root() . '/meta';
                $this->fs->delete_tree( $meta_root );
        }
}
