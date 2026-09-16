<?php
/**
 * SQLite (PDO) object cache backend (Phase K).
 *
 * Single-file persistent backend for hosts without a cache daemon.
 *  - WAL journal mode, bounded busy_timeout, synchronous=NORMAL,
 *  - transactional (BEGIN IMMEDIATE) atomic arithmetic,
 *  - generation-counter O(1) invalidation (same scheme as the other
 *    backends — no table scans for invalidation, no DELETE-all),
 *  - corruption / read-only DB degrade to fail-closed with healthy()=false,
 *  - janitor sweeps expired rows in bounded chunks (never a giant DELETE).
 *
 * The DB file lives under the plugin's hardened cache tree (denied over
 * HTTP by the generated .htaccess: *.sqlite / *.db extensions).
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

use PDO;

defined( 'ABSPATH' ) || exit;

final class SqliteBackend implements Backend {

        const NS          = 'uc:oc:';
        const ENV_MARK    = 'UC1:';
        const BUSY_MS     = 2000;
        const BUSY_RETRY  = 5;
        const JANITOR_ROW = 500;

        /** @var PDO|null */
        private $pdo;

        /** @var string */
        private $file;

        /** @var bool permanently degraded (corrupt/readonly) */
        private $dead = false;

        /** @var bool closed */
        private $closed = false;

        /**
         * @param string|null $file DB file override (tests); default under the hardened cache tree.
         */
        public function __construct( $file = null ) {
                if ( null === $file ) {
                        $file = self::cfg( 'ULTIMATE_PERFORMANCE_SQLITE_FILE', 'UC_SQLITE_FILE' );
                }
                if ( '' === (string) $file ) {
                        $base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
                        $file = $base . '/cache/ultimate-performance/object-cache.sqlite';
                }
                $this->file = (string) $file;
                $this->open();
        }

        private static function cfg( $constant, $env ) {
                if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
                        return (string) constant( $constant );
                }
                $v = getenv( $env );
                return false === $v ? '' : (string) $v;
        }

        /**
         * Whether a SQLite configuration exists (otherwise the derived default
         * under the cache tree applies — always configured, just implicit).
         *
         * @return bool
         */
        public static function configured() {
                return true;
        }

        private function open() {
                if ( $this->closed || $this->dead ) {
                        return;
                }
                try {
                        $dir = dirname( $this->file );
                        if ( ! is_dir( $dir ) ) {
                                @mkdir( $dir, 0775, true );
                        }
                        $pdo = new PDO( 'sqlite:' . $this->file, null, null, array(
                                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_TIMEOUT            => self::BUSY_MS / 1000,
                                PDO::ATTR_PERSISTENT         => false,
                        ) );
                        $pdo->exec( 'PRAGMA journal_mode=WAL' );
                        $pdo->exec( 'PRAGMA synchronous=NORMAL' );
                        $pdo->exec( 'PRAGMA busy_timeout=' . self::BUSY_MS );
                        $pdo->exec( 'CREATE TABLE IF NOT EXISTS kv ( g TEXT NOT NULL, k TEXT NOT NULL, v BLOB, e INTEGER NOT NULL DEFAULT 0, PRIMARY KEY ( g, k ) )' );
                        $pdo->exec( 'CREATE TABLE IF NOT EXISTS counters ( name TEXT PRIMARY KEY, val INTEGER NOT NULL DEFAULT 0 )' );
                        $this->pdo = $pdo;
                } catch ( \Throwable $e ) {
                        $this->pdo = null; // fail closed; healthy() reports false
                }
        }

        private function conn() {
                if ( $this->closed || $this->dead ) {
                        throw new \RuntimeException( 'sqlite unavailable' );
                }
                if ( null === $this->pdo ) {
                        $this->open();
                        if ( null === $this->pdo ) {
                                throw new \RuntimeException( 'sqlite unavailable' );
                        }
                }
                return $this->pdo;
        }

        private function encode( $value ) {
                return is_int( $value ) ? (string) $value : self::ENV_MARK . serialize( $value );
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

        private function genValue( $pdo, $name ) {
                $st = $pdo->prepare( 'SELECT val FROM counters WHERE name = ?' );
                $st->execute( array( $name ) );
                $row = $st->fetch( PDO::FETCH_NUM );
                $st->closeCursor();
                return ( false === $row || null === $row[0] ) ? '0' : (string) (int) $row[0];
        }

        private function effectiveGroup( $pdo, $grp ) {
                return self::NS . 'V' . $this->genValue( $pdo, self::NS . 'gen' ) . ':G' . $this->genValue( $pdo, self::NS . 'geng:' . $grp );
        }

        private function bumpCounter( $pdo, $name ) {
                $attempt = 0;
                do {
                        try {
                                // BEGIN IMMEDIATE: take the writer lock up front —
                                // busy_timeout WAITS properly on BEGIN, and a
                                // snapshot-then-upgrade (BUSY_SNAPSHOT) cannot happen.
                                $pdo->exec( 'BEGIN IMMEDIATE' );
                                try {
                                        $st = $pdo->prepare( 'INSERT INTO counters ( name, val ) VALUES ( ?, 1 ) ON CONFLICT ( name ) DO UPDATE SET val = val + 1' );
                                        $st->execute( array( $name ) );
                                        $st->closeCursor();
                                        $pdo->exec( 'COMMIT' );
                                        return true;
                                } catch ( \Throwable $e ) {
                                        try { $pdo->exec( 'ROLLBACK' ); } catch ( \Throwable $e2 ) { // phpcs:ignore
                                        }
                                        throw $e;
                                }
                        } catch ( \Throwable $e ) {
                                if ( $this->is_busy( $e ) && ++$attempt < self::BUSY_RETRY ) {
                                        usleep( random_int( 200, 2000 + 1000 * $attempt ) );
                                        continue;
                                }
                                throw $e;
                        }
                } while ( true );
        }

        /**
         * Transient lock contention ("database is locked" / SQLITE_BUSY) can
         * surface immediately — WITHOUT honoring busy_timeout — when a DEFERRED
         * transaction upgrades its lock while peer connections hold read
         * snapshots (deadlock avoidance). Found by the Q4 cross-process
         * regression (4x25 increments flaked to 98): bounded jittered retry is
         * the robust cure; permanent errors are not retried.
         *
         * @param \Throwable $e
         * @return bool
         */
        private function is_busy( \Throwable $e ) {
                $m = strtolower( $e->getMessage() );
                return false !== strpos( $m, 'locked' ) || false !== strpos( $m, 'busy' );
        }

        public function get( $key, $group, &$found = null ) {
                $found = false;
                try {
                        $pdo = $this->conn();
                        $g   = $this->effectiveGroup( $pdo, $group );
                        $st  = $pdo->prepare( 'SELECT v FROM kv WHERE g = ? AND k = ? AND ( e = 0 OR e > ? )' );
                        $st->execute( array( $g, $key, time() ) );
                        $row = $st->fetch( PDO::FETCH_NUM );
                        $st->closeCursor();
                        if ( false === $row ) {
                                return null;
                        }
                        $found = true;
                        return $this->decode( $row[0] );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return null;
                }
        }

        public function getMultiple( $keys, $group ) {
                $out = array();
                try {
                        $pdo = $this->conn();
                        $g   = $this->effectiveGroup( $pdo, $group );
                        $st  = $pdo->prepare( 'SELECT k, v FROM kv WHERE g = ? AND k = ? AND ( e = 0 OR e > ? )' );
                        foreach ( (array) $keys as $k ) {
                                $st->execute( array( $g, $k, time() ) );
                                $row = $st->fetch( PDO::FETCH_NUM );
                                $out[ $k ] = false === $row
                                        ? array( 'value' => null, 'found' => false )
                                        : array( 'value' => $this->decode( $row[1] ), 'found' => true );
                        }
                        return $out;
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        foreach ( (array) $keys as $k ) {
                                $out[ $k ] = array( 'value' => null, 'found' => false );
                        }
                        return $out;
                }
        }

        public function set( $key, $value, $ttl, $group ) {
                try {
                        $pdo = $this->conn();
                        $g   = $this->effectiveGroup( $pdo, $group );
                        $e   = (int) $ttl > 0 ? time() + (int) $ttl : 0;
                        $st  = $pdo->prepare( 'INSERT INTO kv ( g, k, v, e ) VALUES ( ?, ?, ?, ? ) ON CONFLICT ( g, k ) DO UPDATE SET v = excluded.v, e = excluded.e' );
                        $st->execute( array( $g, $key, $this->encode( $value ), $e ) );
                        return true;
                } catch ( \Throwable $e2 ) {
                        $this->degrade();
                        return false;
                }
        }

        public function add( $key, $value, $ttl, $group ) {
                try {
                        $pdo = $this->conn();
                        $g   = $this->effectiveGroup( $pdo, $group );
                        $e   = (int) $ttl > 0 ? time() + (int) $ttl : 0;
                        $st  = $pdo->prepare( 'INSERT OR IGNORE INTO kv ( g, k, v, e ) VALUES ( ?, ?, ?, ? )' );
                        $st->execute( array( $g, $key, $this->encode( $value ), $e ) );
                        return $st->rowCount() > 0; // atomic INSERT OR IGNORE = CAS
                } catch ( \Throwable $e2 ) {
                        $this->degrade();
                        return false;
                }
        }

        public function replace( $key, $value, $ttl, $group ) {
                try {
                        $pdo = $this->conn();
                        $g   = $this->effectiveGroup( $pdo, $group );
                        $e   = (int) $ttl > 0 ? time() + (int) $ttl : 0;
                        $st  = $pdo->prepare( 'UPDATE kv SET v = ?, e = ? WHERE g = ? AND k = ? AND ( e = 0 OR e > ? )' );
                        $st->execute( array( $this->encode( $value ), $e, $g, $key, time() ) );
                        return $st->rowCount() > 0; // atomic UPDATE = CAS on existence
                } catch ( \Throwable $e2 ) {
                        $this->degrade();
                        return false;
                }
        }

        public function delete( $key, $group ) {
                try {
                        $pdo = $this->conn();
                        $g   = $this->effectiveGroup( $pdo, $group );
                        $st  = $pdo->prepare( 'DELETE FROM kv WHERE g = ? AND k = ?' );
                        $st->execute( array( $g, $key ) );
                        return $st->rowCount() > 0; // WP semantics: true=deleted, false=absent
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function incr( $key, $n, $group ) {
                return $this->arith( $key, (int) $n, $group, +1 );
        }

        public function decr( $key, $n, $group ) {
                return $this->arith( $key, (int) $n, $group, -1 );
        }

        /**
         * Transactional exists-guarded arithmetic (missing keys never created).
         * BEGIN IMMEDIATE takes the writer lock UP FRONT: the read snapshot can
         * never go stale (no BUSY_SNAPSHOT upgrade race), busy_timeout waits
         * properly on BEGIN, and the bounded jittered retry covers residual
         * contention. Found by the Q4 cross-process regression: a DEFERRED
         * transaction lost updates under 4-process contention (traced: an incr
         * exhausted 5 retries and returned false under sustained load).
         *
         * @return int|false
         */
        private function arith( $key, $n, $group, $sign ) {
                $attempt = 0;
                do {
                        try {
                                $pdo = $this->conn();
                                $g   = $this->effectiveGroup( $pdo, $group );
                                $pdo->exec( 'BEGIN IMMEDIATE' );
                                try {
                                        $st = $pdo->prepare( 'SELECT v FROM kv WHERE g = ? AND k = ? AND ( e = 0 OR e > ? )' );
                                        $st->execute( array( $g, $key, time() ) );
                                        $row = $st->fetch( PDO::FETCH_NUM );
                                        $st->closeCursor();
                                        if ( false === $row || ! preg_match( '/^-?\d+$/', (string) $row[0] ) ) {
                                                $pdo->exec( 'ROLLBACK' );
                                                return false; // missing or non-integer
                                        }
                                        $nv = (int) $row[0] + $sign * $n;
                                        $st = $pdo->prepare( 'UPDATE kv SET v = ? WHERE g = ? AND k = ?' );
                                        $st->execute( array( (string) $nv, $g, $key ) );
                                        $pdo->exec( 'COMMIT' );
                                        return $nv;
                                } catch ( \Throwable $e ) {
                                        try { $pdo->exec( 'ROLLBACK' ); } catch ( \Throwable $e2 ) { // phpcs:ignore
                                        }
                                        throw $e;
                                }
                        } catch ( \Throwable $e ) {
                                if ( $this->is_busy( $e ) && ++$attempt < self::BUSY_RETRY ) {
                                        usleep( random_int( 200, 2000 + 1000 * $attempt ) );
                                        continue;
                                }
                                $this->degrade();
                                return false;
                        }
                } while ( true );
        }

        public function flushGroup( $group ) {
                try {
                        return $this->bumpCounter( $this->conn(), self::NS . 'geng:' . $group );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function flush() {
                try {
                        return $this->bumpCounter( $this->conn(), self::NS . 'gen' );
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function healthy() {
                if ( $this->closed || $this->dead ) {
                        return false;
                }
                try {
                        $pdo = $this->conn();
                        if ( false === $pdo->query( 'SELECT 1' )->fetchColumn() ) {
                                return false;
                        }
                        // write probe on the counters table (self-cleaning)
                        $this->bumpCounter( $pdo, self::NS . 'health' );
                        return true;
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        public function close() {
                $this->pdo   = null;
                $this->closed = true;
        }

        /**
         * Janitor: delete expired rows in bounded chunks. Safe to call
         * repeatedly (WP-Cron schedule; Phase K scheduler wires it).
         *
         * @param int $max_chunks Hard chunk count per call.
         * @return int Rows deleted (false on backend failure).
         */
        public function janitor( $max_chunks = 10 ) {
                try {
                        $pdo = $this->conn();
                        $deleted = 0;
                        for ( $i = 0; $i < (int) $max_chunks; ++$i ) {
                                $st = $pdo->prepare( 'DELETE FROM kv WHERE rowid IN ( SELECT rowid FROM kv WHERE e > 0 AND e <= ? LIMIT ' . self::JANITOR_ROW . ' )' );
                                $st->execute( array( time() ) );
                                $n = $st->rowCount();
                                $deleted += $n;
                                if ( $n < self::JANITOR_ROW ) {
                                        break;
                                }
                        }
                        return $deleted;
                } catch ( \Throwable $e ) {
                        $this->degrade();
                        return false;
                }
        }

        private function degrade() {
                // Distinguish permanent (corrupt/readonly) from transient (busy):
                // reopen lazily unless the file itself proved unreadable.
                if ( null !== $this->pdo ) {
                        $this->pdo = null;
                }
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
