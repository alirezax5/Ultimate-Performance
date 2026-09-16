<?php
/**
 * Concurrency producer child (Phase H / T5).
 *
 * Boots the REAL WordPress runtime, then pushes its assigned directory
 * slice through the REAL QueueManager::enqueue() chain. Emits one JSON
 * line on stdout: {ok, receipts[], accounting{}, error?} — the parent
 * aggregates these into cross-producer invariants.
 *
 * Usage: php conc-producer.php <run_dir_file> <producer_index>
 *   <run_dir_file>  file containing NEWLINE-separated relative dirs this
 *                   producer must submit (its disjoint slice).
 *
 * @package UltimatePerformance
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
if ( 3 !== $argc && 4 !== $argc ) {
	echo json_encode( array( 'ok' => false, 'error' => 'usage: conc-producer.php <dirs-file> <index> [backend]' ) ), "\n";
	exit( 2 );
}

$dirs_file = $argv[1];
$idx       = (int) $argv[2];
$backend   = isset( $argv[3] ) && '' !== $argv[3] ? $argv[3] : null;
$raw       = (string) file_get_contents( $dirs_file );
$dirs      = array_values( array_filter( explode( "\n", $raw ), 'strlen' ) );
if ( empty( $dirs ) ) {
	echo json_encode( array( 'ok' => false, 'error' => 'empty dir slice' ) ), "\n";
	exit( 3 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/wp-shim/' ); // portable WP shim
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( dirname( __DIR__ ) ) . '/' );

require_once ULTIMATE_PERFORMANCE_DIR . 'vendor/autoload.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require ABSPATH . 'wp-load.php';

use UltimatePerformance\Queue\QueueManager;

$out = array(
	'ok'         => true,
	'producer'   => $idx,
	'receipts'   => array(),
	'accounting' => null,
	'error'      => '',
);

try {
	if ( null !== $backend && ! in_array( $backend, array( 'auto', 'rabbitmq', 'action-scheduler', 'wp-cron', 'local', 'sync' ), true ) ) {
		throw new Exception( 'invalid backend arg' );
	}
	$m   = new QueueManager();
	if ( null !== $backend ) {
		// QueueManager reads the Settings singleton statically — mutate its
		// in-memory data array exactly like the parent's c5_set_backend().
		$s     = \UltimatePerformance\Core\Settings::instance();
		$dprop = new \ReflectionProperty( $s, 'data' );
		$dprop->setAccessible( true );
		$data                  = $dprop->getValue( $s );
		$data['queue_backend'] = $backend;
		$dprop->setValue( $s, $data );
	}
	$id  = $m->enqueue( 'purge_dirs', array( 'dirs' => $dirs ) );
	$acct = $m->get_last_accounting();

	// Extract per-chunk receipt ids from attempts ledger ('queued:<id>').
	$receipts = array();
	if ( is_array( $acct ) && isset( $acct['attempts'] ) ) {
		foreach ( (array) $acct['attempts'] as $a ) {
			if ( is_string( $a ) && 0 === strpos( $a, 'queued:' ) ) {
				$receipts[] = substr( $a, 7 );
			}
		}
	}
	$out['receipts']   = $receipts;
	$out['accounting'] = $acct;
	$out['first_id']   = (string) $id;
} catch ( \Throwable $e ) {
	$out['ok']    = false;
	$out['error'] = get_class( $e ) . ': ' . $e->getMessage();
}

echo json_encode( $out ), "\n";
exit( $out['ok'] ? 0 : 4 );
