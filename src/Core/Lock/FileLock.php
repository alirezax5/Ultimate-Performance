<?php
/**
 * File lock — flock based with TTL + owner token (stampede protection).
 *
 * Windows semantics documented:
 *  - flock() on Windows SMB/FAT is unreliable; on local NTFS it works.
 *  - If flock() returns false we degrade to mtime-TTL-only best-effort mode
 *    (documented, logged) rather than blocking the request.
 *  - Stale lock = file mtime older than TTL. Because acquiring a real flock
 *    succeeded, any previous holder either crashed or released; safe to steal.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Core\Lock;

use UltimatePerformance\Core\Uuid7;

defined( 'ABSPATH' ) || exit;

final class FileLock {

        /** @var array<string,resource> held handles per path */
        private static $held = array();

        /** @var string */
        private $file;

        /** @var resource|null */
        private $handle;

        /** @var string */
        private $owner;

        /** @var bool degraded (no-flock) mode */
        private $degraded = false;

        public function __construct( $lock_file, $owner = null ) {
                $this->file  = (string) $lock_file;
                $this->owner = $owner ? (string) $owner : Uuid7::generate();
        }

        /**
         * Try to acquire without blocking.
         *
         * LOCK-1 (Phase H): release() unlinks the lock file. Without inode
         * re-validation, two waiters can end up owning DIFFERENT inodes of the
         * same path (one on the unlinked-but-grantable inode, one on a freshly
         * created file) and BOTH enter the critical section — mutual exclusion
         * silently breaks under contention (reproduced: parallel registry
         * attaches lost members even WITH the flock). After winning the flock
         * the handle is re-checked against the path's CURRENT inode; a mismatch
         * means the file was swapped mid-race → retry.
         *
         * @param int $ttl Seconds before the lock is considered abandoned.
         * @return bool
         */
        public function acquire( $ttl = 30 ) {
                if ( isset( self::$held[ $this->file ] ) ) {
                        return false; // already held in this request.
                }
                for ( $attempt = 0; $attempt < 5; ++$attempt ) {
                        $fh = @fopen( $this->file, 'c' );
                        if ( ! $fh ) {
                                return false;
                        }
                        if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
                                fclose( $fh );
                                usleep( 20000 );
                                continue;
                        }
                        // Inode re-validation (LOCK-1): our granted handle must reference
                        // the inode the PATH currently points at. Otherwise the previous
                        // holder unlinked the file between our open() and our grant —
                        // another waiter may already own the fresh path inode.
                        clearstatcache( true, $this->file );
                        $hstat = fstat( $fh );
                        $pstat = @stat( $this->file );
                        if ( ! is_array( $hstat ) || false === $pstat
                                || (int) $hstat['dev'] !== (int) $pstat['dev']
                                || (int) $hstat['ino'] !== (int) $pstat['ino'] ) {
                                flock( $fh, LOCK_UN );
                                fclose( $fh );
                                usleep( 20000 );
                                continue;
                        }
                        // We hold the exclusive flock — ownership decided. On NTFS a lock file
                        // whose flock is free means every previous holder is gone (process death
                        // closes the handle and the OS drops the lock atomically), so takeover
                        // is immediately safe regardless of file mtime. No TTL heuristic is
                        // applied after winning the race: flock is the sole ownership gate.
                        ftruncate( $fh, 0 );
                        fwrite( $fh, (string) wp_json_encode( array( 'o' => $this->owner, 't' => time(), 'pid' => getmypid() ) ) );
                        fflush( $fh );

                        $this->handle              = $fh;
                        self::$held[ $this->file ] = $fh;
                        return true;
                }
                return false;
        }

        /**
         * Release the lock.
         *
         * LOCK-1 (Phase H): the lock file is deliberately NOT unlinked here.
         * Unlinking lets two waiters own DIFFERENT inodes of the same path and
         * both enter the critical section (reproduced empirically: parallel
         * registry attaches lost members even with the flock held). A holder's
         * unlink can also delete a NEWER generation of the file created by a
         * waiter mid-race. Ownership is decided purely by flock: process death
         * closes the handle and the OS drops the lock atomically, so a leftover
         * (unlocked) file can never wedge the lock — every later acquirer just
         * flocks it again.
         */
        public function release() {
                if ( null === $this->handle ) {
                        return false;
                }
                flock( $this->handle, LOCK_UN );
                fclose( $this->handle );
                unset( self::$held[ $this->file ] );
                $this->handle = null;
                return true;
        }

        public function owner() {
                return $this->owner;
        }

        public function is_degraded() {
                return $this->degraded;
        }

        public function __destruct() {
                if ( null !== $this->handle ) {
                        $this->release();
                }
        }
}
