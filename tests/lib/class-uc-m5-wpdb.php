<?php
/**
 * M5 unit-suite $wpdb double — SQLite-backed.
 *
 * TEST INFRASTRUCTURE ONLY (loaded by tests/audit-cluster.php). This is NOT
 * a WordPress emulator and NOT live evidence: it implements exactly the
 * $wpdb surface src/Cluster/EventStore.php exercises, executing REAL SQL on
 * REAL SQLite so the store's row semantics (insert shape, origin exclusion,
 * id-order, LIMIT, consumed flag, cutoff DELETE) are verified unmodified.
 * The MySQL dialect of EventStore::ensure_table() (dbDelta DDL) is proven
 * LIVE against real MariaDB by tests/run-cluster-live.sh — never by this
 * double (its dbDelta seam creates the equivalent sqlite table).
 *
 * Deliberate honesty boundary: PDO sqlite in ERRMODE_EXCEPTION — any SQL
 * the plugin issues beyond the implemented surface fails LOUDLY here
 * instead of silently passing.
 *
 * @package UltimatePerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit( 'ABSPATH must be defined by the calling suite' );
}

class UC_M5_WPDB {

        /** @var string */
        public $base_prefix = 'wp_';

        /** @var bool failure-injection seam: true → insert/update return false */
        public $readonly = false;

        /**
         * @var bool failure-injection seam (N1): true → reads return empty/null
         * and writes are no-ops — mirroring REAL wpdb behavior during a DB
         * outage (wpdb never throws; it degrades to empty results), which is
         * exactly the authority-outage semantics the epoch code must survive.
         */
        public $fail_reads = false;

        /** @var PDO */
        private $pdo;

        /** @var string */
        private $table = 'wp_uc_invalidation_events';

        /** @var int emulated LAST_INSERT_ID (connection-scoped, like MySQL) */
        private $last_insert_id = 0;

        public function __construct( $path ) {
                $this->pdo = new PDO( 'sqlite:' . (string) $path );
                $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        }

        public function get_charset_collate() {
                return '';
        }

        /**
         * Minimal %-placeholder substitution. The cluster SQL only ever binds
         * %s (origin/table names) and %d (limit/cutoff) — anything else fails
         * loudly downstream (unknown sql), by design.
         *
         * @param string $sql
         * @param mixed  ...$args
         * @return string
         */
        public function prepare( $sql, ...$args ) {
                return (string) preg_replace_callback(
                        '/%[sd]/',
                        function ( $m ) use ( &$args ) {
                                $v = array_shift( $args );
                                if ( '%s' === $m[0] ) {
                                        return "'" . str_replace( "'", "''", (string) $v ) . "'";
                                }
                                return (string) (int) $v;
                        },
                        (string) $sql
                );
        }

        public function get_var( $sql ) {
                $sql = (string) $sql;
                if ( $this->fail_reads ) {
                        return null; // DB outage: wpdb degrades, never throws
                }
                // SHOW TABLES LIKE '<t>' → sqlite_master lookup (same answer shape).
                if ( preg_match( "/SHOW TABLES LIKE '([^']*)'/", $sql, $m ) ) {
                        $st = $this->pdo->prepare( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?" );
                        $st->execute( array( $m[1] ) );
                        $row = $st->fetch( PDO::FETCH_NUM );
                        return ( false === $row || null === $row ) ? null : $row[0];
                }
                // SELECT LAST_INSERT_ID() → the value this connection's last
                // intercepted bump produced (MySQL connection-scoped semantic).
                if ( false !== stripos( $sql, 'SELECT LAST_INSERT_ID()' ) ) {
                        return $this->last_insert_id;
                }
                $row = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM );
                return ( false === $row || null === $row ) ? null : $row[0];
        }

        /**
         * @param string $sql
         * @param string|null $mode ARRAY_A expected by the cluster code.
         * @return array<int,array<string,mixed>>
         */
        /**
         * Fetch a single row as an assoc array (or null). Mirrors the
         * wpdb::get_row(..., ARRAY_A) shape Lease::verify() exercises.
         */
        public function get_row( $sql, $mode = ARRAY_A ) {
                if ( $this->fail_reads ) {
                        return null;
                }
                $r = $this->pdo->query( (string) $sql )->fetch( PDO::FETCH_ASSOC );
                return false === $r ? null : $r;
        }

        public function get_results( $sql, $mode = null ) {
                if ( $this->fail_reads ) {
                        return array(); // DB outage: empty result set
                }
                $out = array();
                $st  = $this->pdo->query( (string) $sql );
                while ( $r = $st->fetch( PDO::FETCH_ASSOC ) ) {
                        $out[] = $r;
                }
                return $out;
        }

        public function insert( $table, $data, $format = null ) {
                if ( $this->readonly ) {
                        return false;
                }
                try {
                        $cols = implode( ',', array_map( array( $this, 'ident' ), array_keys( $data ) ) );
                        $ph   = implode( ',', array_fill( 0, count( $data ), '?' ) );
                        $st   = $this->pdo->prepare( "INSERT INTO {$table} ({$cols}) VALUES ({$ph})" );
                        return $st->execute( array_values( $data ) ) ? 1 : false;
                } catch ( \PDOException $e ) {
                        // Runtime DML failure (e.g. UNIQUE violation): real wpdb
                        // returns false instead of throwing — mirror that.
                        return false;
                }
        }

        public function update( $table, $data, $where, $format1 = null, $format2 = null ) {
                if ( $this->readonly ) {
                        return false;
                }
                try {
                        $set  = implode( ',', array_map( function ( $k ) { return $this->ident( $k ) . ' = ?'; }, array_keys( $data ) ) );
                        $cond = implode( ' AND ', array_map( function ( $k ) { return $this->ident( $k ) . ' = ?'; }, array_keys( $where ) ) );
                        $st   = $this->pdo->prepare( "UPDATE {$table} SET {$set} WHERE {$cond}" );
                        return $st->execute( array_merge( array_values( $data ), array_values( $where ) ) ) ? $st->rowCount() : false;
                } catch ( \PDOException $e ) {
                        return false; // runtime DML failure — wpdb semantics
                }
        }

        public function query( $sql ) {
                $sql = (string) $sql;
                if ( $this->fail_reads ) {
                        return false; // DB outage: writes fail closed
                }
                // INSERT IGNORE → SQLite's INSERT OR IGNORE (same semantics:
                // PK/uniqueness conflicts are swallowed, not errors).
                if ( 0 === stripos( $sql, 'INSERT IGNORE INTO' ) ) {
                        $sql = 'INSERT OR IGNORE INTO' . substr( $sql, strlen( 'INSERT IGNORE INTO' ) );
                }
                // The N1 atomic bump pair (MySQL LAST_INSERT_ID(expr) idiom),
                // emulated ATOMICALLY: BEGIN IMMEDIATE serializes writers;
                // the increment and the read happen inside one transaction so
                // two concurrent bumps each observe their OWN unique value.
                if ( preg_match( "/UPDATE (\S+) SET epoch = LAST_INSERT_ID\(epoch \+ 1\), updated_ms = (\d+) WHERE name = '(\S+)'$/", $sql, $m ) ) {
                        $this->pdo->exec( 'BEGIN IMMEDIATE' );
                        try {
                                $st = $this->pdo->prepare( "UPDATE {$m[1]} SET epoch = epoch + 1, updated_ms = ? WHERE name = ?" );
                                $st->execute( array( (int) $m[2], $m[3] ) );
                                if ( 0 === $st->rowCount() ) {
                                        $this->pdo->exec( 'COMMIT' );
                                        return 0; // row vanished — caller recreates
                                }
                                $row = $this->pdo->query( "SELECT epoch FROM {$m[1]} WHERE name = '{$m[3]}'" )->fetch( PDO::FETCH_NUM );
                                $this->last_insert_id = (int) $row[0];
                                $this->pdo->exec( 'COMMIT' );
                                return 1;
                        } catch ( \Throwable $e ) {
                                $this->pdo->exec( 'ROLLBACK' );
                                throw $e;
                        }
                }
                return $this->pdo->exec( $sql );
        }

        /**
         * dbDelta seam — called through tests/wp-shim/wp-admin/includes/upgrade.php.
         * Creates the sqlite equivalent of the production DDL (same columns,
         * same uniqueness, same indexes) for BOTH cluster tables.
         */
        public function create_uc_events_table() {
                $this->pdo->exec(
                        'CREATE TABLE IF NOT EXISTS wp_uc_invalidation_events (
                                id INTEGER PRIMARY KEY AUTOINCREMENT,
                                event_id TEXT NOT NULL UNIQUE,
                                schema_version INTEGER NOT NULL DEFAULT 2,
                                origin TEXT NOT NULL,
                                epoch INTEGER NOT NULL DEFAULT 0,
                                gen_epoch INTEGER NOT NULL DEFAULT 0,
                                scope TEXT NOT NULL,
                                payload TEXT NOT NULL,
                                created INTEGER NOT NULL,
                                consumed INTEGER NOT NULL DEFAULT 0
                        )'
                );
                $this->pdo->exec( 'CREATE INDEX IF NOT EXISTS consumed_idx ON wp_uc_invalidation_events (consumed, id)' );
                $this->pdo->exec( 'CREATE INDEX IF NOT EXISTS gen_idx ON wp_uc_invalidation_events (gen_epoch)' );
                // N1: single-row shared purge-generation authority.
                $this->pdo->exec(
                        "CREATE TABLE IF NOT EXISTS wp_uc_cluster_epoch (
                                name TEXT PRIMARY KEY,
                                epoch INTEGER NOT NULL DEFAULT 0,
                                updated_ms INTEGER NOT NULL DEFAULT 0
                        )"
                );
                $this->pdo->exec( "INSERT OR IGNORE INTO wp_uc_cluster_epoch (name, epoch, updated_ms) VALUES ('cluster', 0, 0)" );
                // N3: per-node lease table (clone detection).
                $this->pdo->exec(
                        "CREATE TABLE IF NOT EXISTS wp_uc_cluster_nodes (
                                node_id TEXT PRIMARY KEY,
                                boot_secret TEXT NOT NULL,
                                last_instance TEXT NOT NULL DEFAULT '',
                                first_ms INTEGER NOT NULL DEFAULT 0,
                                last_ms INTEGER NOT NULL DEFAULT 0
                        )"
                );
        }

        /* ----------------------- test helpers (never used by production) ------ */

        public function raw( $sql ) {
                return $this->pdo->exec( (string) $sql );
        }

        public function scalar( $sql ) {
                $row = $this->pdo->query( (string) $sql )->fetch( PDO::FETCH_NUM );
                return ( false === $row || null === $row ) ? null : $row[0];
        }

        /** Insert a FOREIGN-origin event the way a peer node's publish() would. */
        public function peer_event( $origin, $scope, array $payload, $epoch = 0, $schema = 1, $created = null, $consumed = 0, $gen_epoch = 0 ) {
                return (bool) $this->insert(
                        $this->table,
                        array(
                                'event_id'       => \UltimatePerformance\Core\Uuid7::generate(),
                                'schema_version' => (int) $schema,
                                'origin'         => (string) $origin,
                                'epoch'          => (int) $epoch,
                                'gen_epoch'      => (int) $gen_epoch,
                                'scope'          => (string) $scope,
                                'payload'        => (string) wp_json_encode( $payload ),
                                'created'        => null === $created ? (int) round( microtime( true ) * 1000 ) : (int) $created,
                                'consumed'       => (int) $consumed,
                        )
                );
        }

        private function ident( $name ) {
                return '"' . str_replace( '"', '', (string) $name ) . '"';
        }
}

/**
 * Global dbDelta seam (the shim's dbDelta delegates here when defined).
 *
 * @param string $sql
 * @return array
 */
function uc_m5_dbdelta( $sql = '' ) {
        global $wpdb;
        if ( $wpdb instanceof UC_M5_WPDB ) {
                $wpdb->create_uc_events_table();
        }
        return array();
}
