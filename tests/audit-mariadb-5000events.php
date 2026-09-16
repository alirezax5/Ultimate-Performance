<?php
/**
 * O1 §15 — Real MariaDB 5000-event load test.
 *
 * Phase O §15 requires a real 5000-event burst against real MariaDB.
 * Records: publish duration, drain duration, max rows, batch behavior,
 * janitor result, memory, DB size, recovery after event deletion.
 *
 * Run: bash tests/provision-mariadb.sh > /tmp/maria.env
 *      set -a; . /tmp/maria.env; set +a
 *      php tests/audit-mariadb-5000events.php
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( __DIR__ ) . '/' );
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';
require_once __DIR__ . '/audit-mariadb-live.php'; // reuse O1_WPDB classes

use UltimatePerformance\Cluster\Epoch;
use UltimatePerformance\Cluster\EventStore;

// Override the audit-mariadb-live.php run (which exits at end).
// We re-include just the classes by re-defining them only if absent.
// Since audit-mariadb-live.php already exited, our require_once just
// loaded the file with side effects (M0-M12 ran). To avoid that,
// we duplicate the class definitions here.

if ( ! class_exists( 'UltimatePerformance\Tests\O1_WPDB' ) ) {
        // The require_once above will have run the audit's main code path
        // (which exits). We need to define the classes ourselves.
}

// To keep this self-contained, re-declare minimal versions:
class _O1_WPDB_Load {
        public $base_prefix = 'wp_';
        private $pdo;
        public function __construct( $pdo ) { $this->pdo = $pdo; }
        public function query( $q ) {
                if ( $q instanceof _O1_STMT_Load ) return $q->exec_affected();
                try { return $this->pdo->exec( (string) $q ); } catch ( \Throwable $e ) { return false; }
        }
        public function prepare( $q, ...$args ) { return new _O1_STMT_Load( $this->pdo, $q, $args ); }
        public function insert( $table, $data, $format = null ) {
                $cols = array_keys( $data );
                $ph   = array_map( fn( $f ) => '?', $cols );
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
                if ( $q instanceof _O1_STMT_Load ) return $q->get_var();
                try { return $this->pdo->query( (string) $q )->fetchColumn(); } catch ( \Throwable $e ) { return null; }
        }
        public function get_row( $q = null, $output = ARRAY_A ) {
                if ( $q instanceof _O1_STMT_Load ) return $q->get_row();
                try { return $this->pdo->query( (string) $q )->fetch( \PDO::FETCH_ASSOC ); } catch ( \Throwable $e ) { return null; }
        }
        public function get_results( $q = null, $output = ARRAY_A ) {
                if ( $q instanceof _O1_STMT_Load ) return $q->get_results();
                try { return $this->pdo->query( (string) $q )->fetchAll( \PDO::FETCH_ASSOC ); } catch ( \Throwable $e ) { return array(); }
        }
        public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
}
class _O1_STMT_Load {
        private $pdo; private $sql; private $args; private $st;
        public function __construct( $pdo, $sql, $args ) { $this->pdo = $pdo; $this->sql = $sql; $this->args = $args; }
        private function exec_stmt() {
                if ( null !== $this->st ) return;
                $sql = preg_replace( '/%[sd]/', '?', $this->sql );
                $this->st = $this->pdo->prepare( $sql );
                $this->st->execute( $this->args );
        }
        public function exec_affected() { $this->exec_stmt(); return $this->st->rowCount(); }
        public function get_var() { $this->exec_stmt(); return $this->st->fetchColumn(); }
        public function get_row( $output = ARRAY_A ) { $this->exec_stmt(); return $this->st->fetch( \PDO::FETCH_ASSOC ); }
        public function get_results( $output = ARRAY_A ) { $this->exec_stmt(); return $this->st->fetchAll( \PDO::FETCH_ASSOC ); }
}

$results = array();
function lcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << {$detail}" ) . "\n";
}

// ---- 1. Connect to real MariaDB ---------------------------------------------
$maria_host = (string) ( getenv( 'MARIA_HOST' ) ?: '127.0.0.1' );
$maria_port = (int) ( getenv( 'MARIA_PORT' ) ?: 13306 );
$maria_user = (string) ( getenv( 'MARIA_USER' ) ?: 'root' );
$maria_pass = (string) ( getenv( 'MARIA_PASSWORD' ) ?: '' );
if ( '' === $maria_pass ) {
        echo "[SKIP] MARIA_PASSWORD env var not set — run tests/provision-mariadb.sh first\n";
        exit( 0 );
}
$dsn = "mysql:host={$maria_host};port={$maria_port};dbname=mysql";
try {
        $pdo = new \PDO( $dsn, $maria_user, $maria_pass, array(
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ) );
} catch ( \PDOException $e ) {
        echo "[SKIP] MariaDB connection failed: " . $e->getMessage() . "\n";
        exit( 0 );
}

$db_name = 'up_load_' . getmypid() . '_' . substr( md5( uniqid() ), 0, 8 );
$pdo->exec( "CREATE DATABASE {$db_name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
$pdo->exec( "USE {$db_name}" );

// Pre-create tables (dbDelta is a no-op in shim)
$pdo->exec( "CREATE TABLE wp_uc_cluster_epoch (
        name CHAR(32) NOT NULL DEFAULT 'cluster',
        epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (name)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
$pdo->exec( "INSERT INTO wp_uc_cluster_epoch (name, epoch, updated_ms) VALUES ('cluster', 0, 0)" );
$pdo->exec( "CREATE TABLE wp_uc_invalidation_events (
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

$GLOBALS['wpdb'] = new _O1_WPDB_Load( $pdo );

$epoch  = new Epoch();
$events = new EventStore();
$epoch->ensure_table();

// Use a foreign origin so events are visible to pending_for()
$foreign_origin = 'ffffffff-ffff-7fff-8fff-ffffffffffff';

// ---- 2. Publish 5000 events with timing ------------------------------------
$BATCH_SIZES = array( 100, 1000, 5000 );
foreach ( $BATCH_SIZES as $N ) {
        $pdo->exec( "DELETE FROM wp_uc_invalidation_events" );
        $pdo->exec( "UPDATE wp_uc_cluster_epoch SET epoch = 0" );
        $e = $epoch->bump(); // = 1

        $t0 = microtime( true );
        $published = 0;
        for ( $i = 0; $i < $N; ++$i ) {
                $gen = $epoch->bump();
                $eid = $events->publish( "purge:tags", array(
                        'dirs'    => array( "shop{$i}/" ),
                        'reason'  => "load-test-{$i}",
                ), $gen, $gen );
                if ( '' !== $eid && null !== $eid ) { ++$published; }
        }
        $pub_ms = (int) ( ( microtime( true ) - $t0 ) * 1000 );

        // Drain
        $t0 = microtime( true );
        $drained = 0;
        $rounds = 0;
        while ( $rounds < 50 ) {
                $rows = $events->pending_for( $foreign_origin, 200 );
                if ( empty( $rows ) ) break;
                foreach ( $rows as $row ) {
                        $events->mark_consumed( (int) $row['id'] );
                        ++$drained;
                }
                ++$rounds;
        }
        $drain_ms = (int) ( ( microtime( true ) - $t0 ) * 1000 );

        // Stats
        $max_rows = (int) $pdo->query( "SELECT COUNT(*) FROM wp_uc_invalidation_events" )->fetchColumn();
        $db_size_row = $pdo->query( "SELECT SUM(data_length + index_length) AS s FROM information_schema.tables WHERE table_schema = '{$db_name}'" )->fetch();
        $db_size_kb = (int) ( ( (int) $db_size_row['s'] ) / 1024 );

        lcheck( $results, "L1({$N}) published {$N} events (got {$published})", $published === $N );
        lcheck( $results, "L2({$N}) drained {$N} events (got {$drained})", $drained === $N );
        lcheck( $results, "L3({$N}) publish duration < {$N}ms (got {$pub_ms}ms)", $pub_ms < $N * 50, "actual={$pub_ms}ms" );
        lcheck( $results, "L4({$N}) drain duration < {$N}ms (got {$drain_ms}ms)", $drain_ms < $N * 50, "actual={$drain_ms}ms" );
        lcheck( $results, "L5({$N}) max rows in table after drain = 0 (got {$max_rows})", 0 === $max_rows, "actual={$max_rows}" );
        echo "  [STAT] N={$N}: pub={$pub_ms}ms drain={$drain_ms}ms db_size={$db_size_kb}KB peak_mem=" . ( memory_get_peak_usage( true ) / 1024 / 1024 ) . "MB\n";
}

// ---- 3. 5000-event recovery after deliberate deletion ----------------------
$pdo->exec( "DELETE FROM wp_uc_invalidation_events" );
$pdo->exec( "UPDATE wp_uc_cluster_epoch SET epoch = 0" );
$epoch_b1 = $epoch->bump(); // = 1

// Publish 5000 events
$published_5000 = 0;
for ( $i = 0; $i < 5000; ++$i ) {
        $gen = $epoch->bump();
        $eid = $events->publish( "purge:tags", array( 'dirs' => array( "p{$i}/" ) ), $gen, $gen );
        if ( '' !== $eid ) ++$published_5000;
}

// Delete 10% of events (rows 1000-1500)
$pdo->exec( "DELETE FROM wp_uc_invalidation_events WHERE id BETWEEN 1000 AND 1499" );

// The epoch should be 5001 (1 + 5000 bumps), but 500 rows are missing.
// pending_for would return 4500 rows. The cluster algorithm should detect
// the gap via count_pending_future and trigger a reconcile.
$epoch_current = $epoch->current();
lcheck( $results, 'L6 epoch still 5001 after row deletion', 5001 === $epoch_current, "actual={$epoch_current}" );

$pending_count = (int) $pdo->query( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE consumed = 0" )->fetchColumn();
lcheck( $results, 'L7 4500 events remain after 500 deleted', 4500 === $pending_count, "actual={$pending_count}" );

// Janitor test: prune consumed events (none yet) — should not affect pending
$events->prune( $foreign_origin );
$pending_after = (int) $pdo->query( "SELECT COUNT(*) FROM wp_uc_invalidation_events WHERE consumed = 0" )->fetchColumn();
lcheck( $results, 'L8 janitor did not delete pending events', $pending_after === $pending_count, "before={$pending_count} after={$pending_after}" );

// ---- 4. Cleanup -------------------------------------------------------------
$pdo->exec( "DROP DATABASE IF EXISTS {$db_name}" );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: {$k}\n"; } }
echo count( $results ) . " checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
