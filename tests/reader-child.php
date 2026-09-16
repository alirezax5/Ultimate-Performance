<?php
/**
 * Reader child for audit-response-lock.php STEP 9.
 *
 * Polls the target file in a tight loop while the parent rewrites it via
 * write_atomic(); exits 'OK' iff EVERY read returned well-formed full
 * content (never empty, never partial, never mixed A/B).
 *
 * Usage: php reader-child.php <path> <seconds>
 *
 * @package UltimatePerformance
 */

if ( $argc < 3 ) {
	echo "usage: reader-child.php <path> <seconds>\n";
	exit( 2 );
}

$path    = $argv[1];
$seconds = (int) $argv[2];
$deadline= microtime( true ) + $seconds;
$saw_a   = false;
$saw_b   = false;
$reads   = 0;

while ( microtime( true ) < $deadline ) {
	$content = @file_get_contents( $path );
	if ( is_string( $content ) && '' !== $content ) {
		$all_a = (bool) preg_match( '/^A+$/', $content );
		$all_b = (bool) preg_match( '/^B+$/', $content );
		if ( ! $all_a && ! $all_b ) {
			echo 'MIXED-CONTENT';
			exit( 1 );
		}
		if ( $all_a ) { $saw_a = true; }
		if ( $all_b ) { $saw_b = true; }
		++$reads;
	}
	// ENOENT windows between the writer's unlink+rename fallback are
	// 404-shaped transients, not partial content — skip and keep polling.
	usleep( 2000 );
}

if ( ! $saw_a || ! $saw_b ) {
	echo 'NO-SWAP-OBSERVED';
	exit( 1 );
}
echo "OK reads={$reads}";
exit( 0 );
