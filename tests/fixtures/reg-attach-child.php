<?php
/**
 * Child process for audit-registry-concurrency: attaches its slice (and
 * optionally detaches a second file's members) to ONE shared tag, then
 * reports a JSON result line. Uses the REAL Registry::attach() path.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Tests;

if ( PHP_SAPI !== 'cli' ) {
        exit( 1 );
}
if ( 3 !== $argc && 4 !== $argc ) {
        echo json_encode( array( 'ok' => false, 'error' => 'usage: reg-attach-child.php <members-file> <tag> [detach-file]' ) ), "\n";
        exit( 2 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/wp-shim/' ); // portable WP shim
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( dirname( __DIR__ ) ) . '/' );

require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
\UltimatePerformance\Core\Autoloader::register();
require_once ABSPATH . 'wp-load.php';

$members   = array_values( array_filter( explode( "\n", (string) file_get_contents( $argv[1] ) ), 'strlen' ) );
$tag       = (string) $argv[2];
$detach    = ( isset( $argv[3] ) && file_exists( $argv[3] ) )
        ? array_values( array_filter( explode( "\n", (string) file_get_contents( $argv[3] ) ), 'strlen' ) )
        : array();

$out = array( 'ok' => true, 'attached' => 0, 'detached' => 0, 'error' => '' );
try {
        $reg = new \UltimatePerformance\CacheTag\Registry();
        foreach ( $members as $rel ) {
                $reg->attach( (string) $rel, array( $tag ) );
                ++$out['attached'];
        }
        if ( ! empty( $detach ) ) {
                usleep( random_int( 0, 40000 ) ); // interleave attach/detach across children
                foreach ( $detach as $rel ) {
                        $reg->detach_object( (string) $rel, array( $tag ) );
                        ++$out['detached'];
                }
        }
} catch ( \Throwable $e ) {
        $out['ok']    = false;
        $out['error'] = get_class( $e ) . ': ' . $e->getMessage();
}

echo json_encode( $out ), "\n";
exit( $out['ok'] ? 0 : 4 );
