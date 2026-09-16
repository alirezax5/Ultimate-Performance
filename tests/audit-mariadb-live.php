<?php
/**
 * O1 §8 — Real MariaDB live validation.
 *
 * Phase O §8 requires real MariaDB evidence: exact version, storage engine,
 * port/socket, sql_mode, transaction isolation, charset, collation.
 *
 * This audit exercises the cluster logic against a real MariaDB daemon
 * (provisioned by tests/provision-mariadb.sh). It uses the UC_M5_WPDB
 * double initialized with a PDO MySQL connection — the same wpdb surface
 * the cluster classes call.
 *
 * Run: bash tests/provision-mariadb.sh > /tmp/maria.env
 *      set -a; . /tmp/maria.env; set +a
 *      php tests/audit-mariadb-live.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Cluster\Epoch;
use UltimatePerformance\Cluster\Lease;
use UltimatePerformance\Cluster\EventStore;
use UltimatePerformance\Cluster\NodeIdentity;
use UltimatePerformance\Cluster\Checkpoint;
use UltimatePerformance\Cluster\Propagator;

$results = array();
function mcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

// ---- 1. Connect to real MariaDB via PDO -------------------------------------
$maria_host = (string) ( getenv( 'MARIA_HOST' ) ?: '127.0.0.1' );
$maria_port = (int) ( getenv( 'MARIA_PORT' ) ?: 13306 );
$maria_user = (string) ( getenv( 'MARIA_USER' ) ?: 'root' );
$maria_pass = (string) ( getenv( 'MARIA_PASSWORD' ) ?: '' );
$maria_sock = (string) ( getenv( 'MARIA_SOCKET' ) ?: '' );

if ( '' === $maria_pass ) {
        echo "[SKIP] MARIA_PASSWORD env var not set — run tests/provision-mariadb.sh first\n";
        exit( 0 );
}

$dsn = '' !== $maria_sock
        ? "mysql:unix_socket={$maria_sock};dbname=mysql"
        : "mysql:host={$maria_host};port={$maria_port};dbname=mysql";
try {
        $pdo = new \PDO( $dsn, $maria_user, $maria_pass, array(
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ) );
} catch ( \PDOException $e ) {
        echo "[SKIP] MariaDB connection failed: " . $e->getMessage() . "\n";
        exit( 0 );
}

// ---- 2. Record MariaDB config (Phase O §8) ---------------------------------
$ver_row = $pdo->query( 'SELECT VERSION() AS v' )->fetch();
$ver = (string) $ver_row['v'];

$storage_engine = $pdo->query( "SHOW VARIABLES LIKE 'storage_engine'" )->fetch();
$storage_engine = (string) ( $storage_engine['Value'] ?? '' );

$tx_iso = $pdo->query( "SHOW VARIABLES LIKE 'transaction_isolation'" )->fetch();
$tx_iso = (string) ( $tx_iso['Value'] ?? '' );

$sql_mode = $pdo->query( "SHOW VARIABLES LIKE 'sql_mode'" )->fetch();
$sql_mode = (string) ( $sql_mode['Value'] ?? '' );

$charset = $pdo->query( "SHOW VARIABLES LIKE 'character_set_server'" )->fetch();
$charset = (string) ( $charset['Value'] ?? '' );

$collation = $pdo->query( "SHOW VARIABLES LIKE 'collation_server'" )->fetch();
$collation = (string) ( $collation['Value'] ?? '' );

mcheck( $results, 'M0a MariaDB version captured', '' !== $ver, "ver=$ver" );
mcheck( $results, 'M0b storage_engine = InnoDB', 'InnoDB' === $storage_engine, "got=$storage_engine" );
mcheck( $results, 'M0c transaction_isolation = REPEATABLE-READ', false !== stripos( $tx_iso, 'REPEATABLE' ), "got=$tx_iso" );
mcheck( $results, 'M0d sql_mode STRICT_TRANS_TABLES', false !== stripos( $sql_mode, 'STRICT_TRANS_TABLES' ), "got=$sql_mode" );
mcheck( $results, 'M0e character_set_server = utf8mb4', 'utf8mb4' === $charset, "got=$charset" );
mcheck( $results, 'M0f collation_server = utf8mb4_unicode_ci', 'utf8mb4_unicode_ci' === $collation, "got=$collation" );

// ---- 3. Create a per-run test database + wpdb double -----------------------
$db_name = 'up_phase_o_' . getmypid() . '_' . substr( md5( uniqid() ), 0, 8 );
$pdo->exec( "CREATE DATABASE {$db_name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
$pdo->exec( "USE {$db_name}" );

// Build a wpdb-compatible double backed by this PDO connection.
class O1_WPDB {
        public $base_prefix = 'wp_';
        private $pdo;
        public function __construct( $pdo ) { $this->pdo = $pdo; }
        public function query( $q ) {
                if ( $q instanceof O1_STMT ) {
                        return $q->exec_affected();
                }
                try { return $this->pdo->exec( (string) $q ); } catch ( \Throwable $e ) { return false; }
        }
        public function prepare( $q, ...$args ) { return new O1_STMT( $this->pdo, $q, $args ); }
        public function insert( $table, $data, $format = null ) {
                $cols = array_keys( $data );
                $ph   = array_map( function( $f ) { return '?'; }, $cols );
                $sql  = "INSERT INTO {$table} (" . implode( ',', $cols ) . ") VALUES (" . implode( ',', $ph ) . ")";
                try { $this->pdo->prepare( $sql )->execute( array_values( $data ) ); return true; }
                catch ( \Throwable $e ) { return false; }
        }
        public function update( $table, $data, $where, $format = null, $where_format = null ) {
                $set = array(); foreach ( $data as $k => $v ) $set[] = "{$k} = ?";
                $wh = array(); foreach ( $where as $k => $v ) $wh[] = "{$k} = ?";
                $sql = "UPDATE {$table} SET " . implode( ',', $set ) . " WHERE " . implode( ' AND ', $wh );
                try { $st = $this->pdo->prepare( $sql ); $st->execute( array_merge( array_values( $data ), array_values( $where ) ) ); return $st->rowCount(); }
                catch ( \Throwable $e ) { return false; }
        }
        public function get_var( $q = null ) {
                if ( $q instanceof O1_STMT ) { return $q->get_var(); }
                try { return $this->pdo->query( (string) $q )->fetchColumn(); } catch ( \Throwable $e ) { return null; }
        }
        public function get_row( $q = null, $output = ARRAY_A ) {
                if ( $q instanceof O1_STMT ) { return $q->get_row( $output ); }
                try { return $this->pdo->query( (string) $q )->fetch( $output ); } catch ( \Throwable $e ) { return null; }
        }
        public function get_results( $q = null, $output = ARRAY_A ) {
                if ( $q instanceof O1_STMT ) { return $q->get_results( $output ); }
                try { return $this->pdo->query( (string) $q )->fetchAll( $output ); } catch ( \Throwable $e ) { return array(); }
        }
        public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
}
class O1_STMT {
        private $pdo; private $sql; private $args; private $st;
        public function __construct( $pdo, $sql, $args ) { $this->pdo = $pdo; $this->sql = $sql; $this->args = $args; }
        private function exec_stmt() {
                if ( null !== $this->st ) return;
                // wpdb prepare uses %s %d placeholders — convert to PDO ?
                $sql = $this->sql;
                $sql = preg_replace( '/%[sd]/', '?', $sql );
                $this->st = $this->pdo->prepare( $sql );
                $this->st->execute( $this->args );
        }
        public function exec_affected() { $this->exec_stmt(); return $this->st->rowCount(); }
        public function get_var() { $this->exec_stmt(); return $this->st->fetchColumn(); }
        public function get_row( $output = ARRAY_A ) { $this->exec_stmt(); return $this->st->fetch( \PDO::FETCH_ASSOC ); }
        public function get_results( $output = ARRAY_A ) { $this->exec_stmt(); return $this->st->fetchAll( \PDO::FETCH_ASSOC ); }
}

// Outage stub: every method returns the "DB unreachable" result.
class O1_WPDB_Outed {
        public $base_prefix = 'wp_';
        public function query( $q ) { return false; }
        public function prepare( $q, ...$args ) { return new O1_STMT_Outed(); }
        public function insert( $table, $data, $format = null ) { return false; }
        public function update( $table, $data, $where, $format = null, $where_format = null ) { return false; }
        public function get_var( $q = null ) { return null; }
        public function get_row( $q = null, $output = ARRAY_A ) { return null; }
        public function get_results( $q = null, $output = ARRAY_A ) { return array(); }
        public function get_charset_collate() { return ''; }
}
class O1_STMT_Outed {
        public function exec_affected() { return false; }
        public function get_var() { return null; }
        public function get_row( $output = ARRAY_A ) { return null; }
        public function get_results( $output = ARRAY_A ) { return array(); }
}

$GLOBALS['wpdb'] = new O1_WPDB( $pdo );

// ---- 4. Real cluster exercise -----------------------------------------------
// Pre-create the epoch table directly (the wp-shim's dbDelta is a no-op
// since it doesn't know about real MySQL DDL).
$pdo->exec( "CREATE TABLE IF NOT EXISTS wp_uc_cluster_epoch (
        name CHAR(32) NOT NULL DEFAULT 'cluster',
        epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (name)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
$pdo->exec( "INSERT IGNORE INTO wp_uc_cluster_epoch (name, epoch, updated_ms) VALUES ('cluster', 0, 0)" );

// Also pre-create events + nodes tables
$pdo->exec( "CREATE TABLE IF NOT EXISTS wp_uc_invalidation_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id CHAR(36) NOT NULL,
        schema_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
        origin CHAR(36) NOT NULL,
        epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
        gen_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
        scope VARCHAR(255) NOT NULL,
        payload LONGTEXT NOT NULL,
        created BIGINT UNSIGNED NOT NULL DEFAULT 0,
        consumed TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_origin_consumed (origin, consumed),
        KEY idx_gen_epoch (gen_epoch)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

$pdo->exec( "CREATE TABLE IF NOT EXISTS wp_uc_cluster_nodes (
        node_id CHAR(36) NOT NULL,
        boot_secret CHAR(32) NOT NULL,
        last_instance CHAR(36) NOT NULL DEFAULT '',
        last_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (node_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

$epoch = new Epoch();
$lease = new Lease();
$events = new EventStore();

mcheck( $results, 'M1 epoch ensure_table() against real MariaDB', $epoch->ensure_table() );
mcheck( $results, 'M2 epoch current() returns 0 on fresh table', 0 === $epoch->current() );

$e1 = $epoch->bump();
mcheck( $results, 'M3 epoch bump() returns 1 (first bump)', 1 === $e1, "got=$e1" );
mcheck( $results, 'M4 epoch current() returns 1 after bump', 1 === $epoch->current() );

// Concurrent epoch bumps: 10 in sequence, each must be strictly monotonic
$prev = 1;
$ok_mono = true;
for ( $i = 0; $i < 10; ++$i ) {
        $v = $epoch->bump();
        if ( $v !== $prev + 1 ) { $ok_mono = false; break; }
        $prev = $v;
}
mcheck( $results, 'M5 epoch strict monotonicity (10 consecutive bumps)', $ok_mono, "stopped at $prev" );
mcheck( $results, 'M6 epoch current() = 11 after 10 bumps', 11 === $epoch->current(), "got=" . $epoch->current() );

// Concurrent epoch bumps: 5 parallel producers via separate connections.
// IMPORTANT: after pcntl_fork, the child inherits the parent's PDO handle,
// which is NOT shareable across processes. The parent must close its PDO
// before forking, and the children create their own connections. After
// all children exit, the parent re-creates its own connection.
$parallel_pids = array();
$parallel_results_file = sys_get_temp_dir() . '/uc-parallel-' . getmypid() . '.txt';
@unlink( $parallel_results_file );

// Close the parent's PDO so children don't inherit a broken handle
$pdo = null;
$GLOBALS['wpdb'] = null;

for ( $i = 0; $i < 5; ++$i ) {
        $pid = pcntl_fork();
        if ( -1 === $pid ) { mcheck( $results, "M7 fork $i failed", false ); continue; }
        if ( 0 === $pid ) {
                // Child: brand new PDO connection, bump 20 times, append each result
                $child_pdo = new \PDO( $dsn, $maria_user, $maria_pass, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
                $child_pdo->exec( "USE {$db_name}" );
                $child_wpdb = new O1_WPDB( $child_pdo );
                $GLOBALS['wpdb'] = $child_wpdb;
                $child_epoch = new Epoch();
                $child_epoch->ensure_table();
                $f = fopen( $parallel_results_file, 'a' );
                flock( $f, LOCK_EX );
                for ( $j = 0; $j < 20; ++$j ) {
                        $v = $child_epoch->bump();
                        fwrite( $f, "$v\n" );
                }
                flock( $f, LOCK_UN );
                fclose( $f );
                $child_pdo = null;
                exit( 0 );
        }
        $parallel_pids[] = $pid;
}
foreach ( $parallel_pids as $p ) { pcntl_waitpid( $p, $status ); }

// Restore the parent's PDO
$pdo = new \PDO( $dsn, $maria_user, $maria_pass, array(
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
) );
$pdo->exec( "USE {$db_name}" );
$GLOBALS['wpdb'] = new O1_WPDB( $pdo );

$parallel_values = array_filter( array_map( 'trim', file( $parallel_results_file ) ?: array() ) );
$parallel_count = count( $parallel_values );
$parallel_unique = count( array_unique( $parallel_values ) );
@unlink( $parallel_results_file );
// 5 producers × 20 bumps = 100 unique values; starting from 11, max should be 111
mcheck( $results, 'M7 5 concurrent producers × 20 bumps = 100 unique epoch values', 100 === $parallel_unique, "got $parallel_unique unique of $parallel_count" );

// Re-create the epoch/lease/events instances so they use the restored $wpdb
$epoch = new Epoch();
$lease = new Lease();
$events = new EventStore();

// ---- 5. Event store + lease ------------------------------------------------
$lease->ensure_table();
$events->ensure_table();

$node_id = NodeIdentity::id();
$boot_secret = NodeIdentity::boot_secret();
$instance_nonce = NodeIdentity::instance_nonce();

mcheck( $results, 'M8 lease verify() first registration returns true', $lease->verify( $node_id, $boot_secret, $instance_nonce, (int) round( microtime( true ) * 1000 ) ) );

// EventStore publish
$e_before = $epoch->current();
$eid = $events->publish( 'purge:tags', array( 'dirs' => array( 'shop/' ) ), $e_before, $e_before );
mcheck( $results, 'M9 event publish() returns non-empty event_id', '' !== $eid && null !== $eid );

// ---- 6. Transaction semantics: FOR UPDATE row lock (MariaDB-specific) ------
// Two concurrent transactions; the one that locks first must hold the lock
// until COMMIT, the other must wait. We measure the wait time.
$lock_pdo = new \PDO( $dsn, $maria_user, $maria_pass, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
$lock_pdo->exec( "USE {$db_name}" );
$lock_pdo->exec( 'SET SESSION transaction_isolation = "REPEATABLE-READ"' );

// Begin a transaction, lock the epoch row FOR UPDATE
$lock_pdo->beginTransaction();
$lock_pdo->query( "SELECT epoch FROM wp_uc_cluster_epoch WHERE name = 'cluster' FOR UPDATE" );

// Now try to bump from a second connection — should wait
$wait_pdo = new \PDO( $dsn, $maria_user, $maria_pass, array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
$wait_pdo->exec( "USE {$db_name}" );
$wait_pdo->setAttribute( \PDO::ATTR_TIMEOUT, 2 ); // 2 second timeout
$t0 = microtime( true );
$wait_blocked = true;
try {
        // Try a SELECT ... FOR UPDATE — should time out because the row is locked
        $wait_pdo->query( "SELECT epoch FROM wp_uc_cluster_epoch WHERE name = 'cluster' FOR UPDATE" );
        $wait_blocked = false; // shouldn't reach here
} catch ( \PDOException $e ) {
        // Lock wait timeout exceeded — expected
        $wait_blocked = ( false !== stripos( $e->getMessage(), 'Lock wait timeout' ) );
}
$elapsed_ms = (int) ( ( microtime( true ) - $t0 ) * 1000 );

$lock_pdo->commit(); // release the lock

mcheck( $results, 'M10 MariaDB FOR UPDATE row lock blocks concurrent reader', $wait_blocked, "elapsed={$elapsed_ms}ms" );

// ---- 7. DB outage + recovery (simulated by closing + reopening PDO) --------
// Close the main connection, attempt a read (should fail-closed to 0).
// Use ERRMODE_SILENT so the connection failure doesn't throw — the
// cluster code is supposed to swallow DB exceptions and return 0.
$old_wpdb = $GLOBALS['wpdb'];
try {
        $bad_pdo = new \PDO( 'mysql:host=127.0.0.1;port=1', 'x', 'x', array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT ) );
        $GLOBALS['wpdb'] = new O1_WPDB( $bad_pdo );
} catch ( \Throwable $e ) {
        // Connection itself failed — that's also a valid "outage" state.
        $GLOBALS['wpdb'] = new O1_WPDB_Outed( null );
}
$epoch_after_outage = $epoch->current(); // should be 0 (outage — table not reachable)
mcheck( $results, 'M11 DB outage: epoch current() returns 0 (fail-closed)', 0 === $epoch_after_outage, "got={$epoch_after_outage}" );

// Restore with a FRESH connection (the old one was destroyed by outage test)
$restore_pdo = new \PDO( $dsn, $maria_user, $maria_pass, array(
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
) );
$restore_pdo->exec( "USE {$db_name}" );
$GLOBALS['wpdb'] = new O1_WPDB( $restore_pdo );
// Re-create the epoch instance so the table_ok cache is fresh
$epoch_restored = new Epoch();
$v = $epoch_restored->current();
mcheck( $results, 'M12 DB recovered: epoch current() returns previous value', $v > 0, "got={$v}" );

// ---- 8. Cleanup -------------------------------------------------------------
$pdo->exec( "DROP DATABASE IF EXISTS {$db_name}" );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: {$k}\n"; } }
echo count( $results ) . " checks, {$fails} failures\n";
echo "MariaDB version: {$ver}\n";
echo "Storage engine: {$storage_engine}\n";
echo "Transaction isolation: {$tx_iso}\n";
echo "SQL mode: {$sql_mode}\n";
echo "Charset: {$charset}\n";
echo "Collation: {$collation}\n";
exit( $fails ? 1 : 0 );
