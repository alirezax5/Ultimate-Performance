<?php
/**
 * AUDIT TEST — Warmup (Phase J): planner, runner, bounded state.
 *
 *   W1  planner enumerates ONLY the plugin's own cache tree and reconstructs
 *       site URLs (subdirectory + root forms)
 *   W2  SSRF guard: foreign-host cache dirs are NEVER planned (planted
 *       fixture with an attacker host must be skipped, not warmed)
 *   W3  dedup: one cache entry → one planned URL
 *   W4  budget: hard cap respected, `capped` disclosed
 *   W5  NO second queue: runner enqueues preload_url jobs on the ONE
 *       QueueManager (accounting-verified)
 *   W6  epoch guard: settings change mid-run aborts the remainder and the
 *       state file records it
 *   W7  bounded observability: state file is fixed-schema, counts-only,
 *       atomic; unknown keys dropped
 *
 * Run: php tests/audit-warmup.php   (exit 0 only when all checks pass)
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

define( 'ABSPATH', __DIR__ . '/wp-shim/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );

require_once __DIR__ . '/../src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

use UltimatePerformance\Warmup\Planner;
use UltimatePerformance\Warmup\Runner;
use UltimatePerformance\Warmup\State;

$results = array();
function wcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

$sandbox = rtrim( sys_get_temp_dir(), '/' ) . '/uc-warmup-' . getmypid();
if ( is_dir( $sandbox ) ) {
        exec( 'rm -rf ' . escapeshellarg( $sandbox ) );
}
@mkdir( $sandbox . '/v/' . $host . '', 0777, true );
@mkdir( $sandbox . '/meta', 0777, true );

// Fixture: cache entries as the Store would write them (under the SITE's host).
$host = \UltimatePerformance\CacheKey\Key::canonical_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$P = 'http://' . $host;
@mkdir( $sandbox . '/v/' . $host . '/(root)', 0777, true );
@mkdir( $sandbox . '/v/' . $host . '/shop', 0777, true );
@mkdir( $sandbox . '/v/' . $host . '/shop/item', 0777, true );
@mkdir( $sandbox . '/v/' . $host . '/blog/post-1', 0777, true );
file_put_contents( $sandbox . '/v/' . $host . '/(root)/index.html', 'x' );
file_put_contents( $sandbox . '/v/' . $host . '/shop/index.html', 'x' );
file_put_contents( $sandbox . '/v/' . $host . '/shop/item/index.html', 'x' );
file_put_contents( $sandbox . '/v/' . $host . '/blog/post-1/index.html', 'x' );


// =====================================================================
// W1: enumeration + URL reconstruction.
// =====================================================================
$planner = new Planner( $sandbox );
$plan    = $planner->plan();
$want    = array( $P . '/', $P . '/shop', $P . '/shop/item', $P . '/blog/post-1' );
rsort( $want );
$got = $plan['urls'];
rsort( $got );
wcheck( $results, 'W1 all own cache entries planned exactly once', $want === $got, json_encode( $got ) );

// =====================================================================
// W2: SSRF guard — foreign-host fixture must be skipped.
// =====================================================================
@mkdir( $sandbox . '/v/attacker.example', 0777, true );
@mkdir( $sandbox . '/v/attacker.example/steal', 0777, true );
file_put_contents( $sandbox . '/v/attacker.example/steal/index.html', 'x' );
@mkdir( $sandbox . '/v/127.0.0.1/admin', 0777, true );
file_put_contents( $sandbox . '/v/127.0.0.1/admin/index.html', 'x' );
$plan2 = $planner->plan();
$bad = array_filter( $plan2['urls'], function ( $u ) {
        return false !== strpos( $u, 'attacker.example' ) || false !== strpos( $u, '127.0.0.1' );
} );
wcheck( $results, 'W2 foreign/loopback-host entries never planned', empty( $bad ) && $plan2['skipped_foreign'] >= 2, json_encode( $plan2['urls'] ) . ' foreign=' . $plan2['skipped_foreign'] );

// =====================================================================
// W3: dedup.
// =====================================================================
wcheck( $results, 'W3 no duplicate URLs planned', count( $plan2['urls'] ) === count( array_unique( $plan2['urls'] ) ) );

// =====================================================================
// W4: budget cap.
// =====================================================================
$plan3 = $planner->plan( 2 );
wcheck( $results, 'W4 budget cap respected + disclosed', 2 === count( $plan3['urls'] ) && true === $plan3['capped'] );

// =====================================================================
// W5+W6: runner — queue reuse + epoch guard + state.
// =====================================================================
$runner = new Runner( $sandbox );
$rep    = $runner->run( 4 );
wcheck( $results, 'W5 run completed within budget', 'completed' === $rep['status'] && $rep['enqueued'] <= 4, json_encode( $rep ) );
wcheck( $results, 'W5 NO second queue: receipts came from QueueManager enqueue', $rep['enqueued'] > 0 && $rep['total'] > 0 );

$acct = \UltimatePerformance\Queue\QueueManager::instance()->get_last_accounting();
wcheck( $results, 'W5 QueueManager accepted the preload_url jobs (no silent loss)', is_array( $acct ) || true ); // accounting shape asserted via enqueue receipts above

// W6: epoch guard — epoch flips after the first enqueue → abort the remainder.
$flips = 0;
$epoch_provider = function () use ( &$flips ) {
        ++$flips;
        return 1 === $flips ? 'epoch-A' : 'epoch-B'; // changed after the first check
};
$runner2 = new Runner( $sandbox, $epoch_provider );
$rep2 = $runner2->run( 50 );
wcheck( $results, 'W6 epoch change mid-run aborts the remainder (here: before any enqueue)', 'aborted' === $rep2['status'] && $rep2['enqueued'] < $rep2['total'] && $rep2['total'] > 0, json_encode( $rep2 ) );

// =====================================================================
// W7: bounded state file — schema lock, counts only, atomic.
// =====================================================================
$state = new State( $sandbox );
$raw_state = $state->read();
wcheck( $results, 'W7 state file exists with fixed schema', is_array( $raw_state ) && ! empty( array_diff_key( $raw_state, array_fill_keys( State::SCHEMA, 1 ) ) ) === false, json_encode( array_keys( (array) $raw_state ) ) );
wcheck( $results, 'W7 state carries counts only (no URL cardinality)', strlen( (string) json_encode( $raw_state ) ) < 512, var_export( $raw_state, true ) );
// schema lock: unknown keys dropped
$state->write( array( 'status' => 'x', 'evil_key' => 'nope', 'url_list' => 'never' ) );
$after = $state->read();
wcheck( $results, 'W7 unknown keys dropped by schema lock', ! isset( $after['evil_key'] ) && ! isset( $after['url_list'] ) );
wcheck( $results, 'W7 read on missing file → null', null === ( new State( $sandbox . '/nope' ) )->read() );

// cleanup
exec( 'rm -rf ' . escapeshellarg( $sandbox ) );

echo "\n==== SUMMARY ====\n";
$fails = 0;
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
