<?php
/**
 * AUDIT TEST — ResponseSanitizer adversarial (STEP 5) + FileLock concurrency
 * (STEP 7) + Atomic write (STEP 9) + Stampede simulation (STEP 8).
 *
 * Run: php tests/audit-response-lock.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ULTIMATE_PERFORMANCE_DIR', str_replace( '\\', '/', ABSPATH ) );
define( 'WP_CONTENT_DIR', ULTIMATE_PERFORMANCE_DIR . 'tests/sandbox/wp-content' );

function wp_normalize_path( $p ) {
        $p = str_replace( '\\', '/', $p );
        $p = preg_replace( '|(?<=.)/+|', '/', $p );
        if ( ':' === substr( $p, 1, 1 ) ) { $p = ucfirst( $p ); }
        return $p;
}
function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0777, true ); }
function apply_filters( $t, $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
$GLOBALS['up_site_host'] = 'shop.example';
function home_url( $p = '' ) { return 'https://' . $GLOBALS['up_site_host'] . $p; }
function is_multisite() { return false; }
function get_option( $n, $d = false ) { return $GLOBALS['up_opt'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['up_opt'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['up_opt'][ $n ] ); return true; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/SafeFs.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Lock/FileLock.php';
require_once ULTIMATE_PERFORMANCE_DIR . 'src/Security/ResponseSanitizer.php';

use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Lock\FileLock;
use UltimatePerformance\Security\ResponseSanitizer;

$results = array();
function check( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

// ================================================== STEP 5: Sanitizer
$san = new ResponseSanitizer();
$clean_body = '<!DOCTYPE html><html><head><title>Shop</title></head><body><h1>Wool Socks</h1><p>Public product description everyone sees.</p></body></html>';

// Real WP output shapes.
$leaks = array(
        'wp_nonce_field form'        => '<form method="post"><input type="hidden" id="_wpnonce" name="_wpnonce" value="a8f3c2e119" /></form>',
        'wp_create_nonce in JS var'  => '<script>var wpApiSettings = {"root":"https:\/\/shop.example\/wp-json\/","nonce":"9f8e7d6c55"};</script>',
        'rest_nonce meta'            => '<meta name="wp-rest-nonce" content="abc123def4">',
        'wp_logout_url link'         => '<a href="https://shop.example/wp-login.php?action=logout&amp;_wpnonce=a1b2c3">Log out</a>',
        'admin bar div'              => '<div id="wpadminbar" class="nojq">Howdy, Admin</div>',
        'logged-in body class'       => '<body class="home blog logged-in admin-bar">',
        'display name leak'          => '<span class="author">Howdy, JohnDoe</span>',
        'user-specific avatar url'   => '<img src="https://shop.example/?gravatar=user-42-priv" data-user-id="42">',
        'cart count widget'          => '<span class="cart-count">3 items</span><span class="woocommerce-Price-amount amount"><bdi>$45.00</bdi></span>',
        'order received fragment'    => '<div class="woocommerce-order-overview"><li>Order #1234</li></div>',
        'price per user'             => '<span class="customer-price vip">$99.99</span>',
        'session token in html'      => '<input type="hidden" name="token" value="s3cr3tsess10ntok3n">',
        'form action with nonce'     => '<form action="/checkout/?_wpnonce=deadbeef01" method="post"></form>',
);
foreach ( $leaks as $name => $frag ) {
        $a = $san->audit( $frag . $clean_body );
        check( $results, "Leak blocked: $name", false === $a['safe'], "reason={$a['reason']}" );
}

// Clean pages pass.
foreach (
        array(
                'plain product page' => $clean_body,
                'public form no nonce' => '<form action="/search/" method="get"><input name="q"></form>' . $clean_body,
        ) as $name => $html
) {
        $a = $san->audit( $html );
        check( $results, "Clean allowed: $name", true === $a['safe'], "reason={$a['reason']}" );
}

// Logged-in user requesting same URL must never populate public cache:
// Engine contract — classifier BYPASSes before write; sanitizer double-gates.
$auth_html = '<body class="logged-in">' . $clean_body;
check( $results, 'Auth-marked body rejected at sanitizer layer', false === $san->audit( $auth_html )['safe'] );

// ================================================== STEP 7/9: Lock + atomic
$fs_root = WP_CONTENT_DIR . '/cache/ultimate-performance';
@mkdir( $fs_root . '/v/shop.example', 0777, true );
SafeFs::allow_root( $fs_root );
$fs = new SafeFs();

// Multi-process lock contention via distinct PHP processes.
$lock_file = $fs_root . '/v/shop.example/regen.lock';
@unlink( $lock_file );
$php   = PHP_BINARY;
$owner = $fs_root . '/lock-owner.txt';

// Parent holds lock; child processes must ALL fail to acquire.
$lk = new FileLock( $lock_file, 'parent' );
check( $results, 'Parent acquired lock', true === $lk->acquire( 30 ) );

$script = ULTIMATE_PERFORMANCE_DIR . 'tests/lock-child.php';
$procs  = array();
for ( $i = 0; $i < 10; $i++ ) {
        $procs[] = popen( sprintf( '"%s" "%s" "%s" "%d"', $php, $script, $lock_file, $i ), 'r' );
}
$child_results = array();
foreach ( $procs as $i => $p ) {
        $child_results[ $i ] = trim( (string) fgets( $p ) );
        pclose( $p );
}
$denied = 0;
$owners = array();
foreach ( $child_results as $line ) {
        $j = json_decode( (string) $line, true );
        if ( is_array( $j ) ) {
                if ( empty( $j['acquired'] ) ) { ++$denied; } else { $owners[] = $j['owner']; }
        } else {
                ++$denied; // unreadable output treated as failure-to-acquire
        }
}
check( $results, '10 concurrent children all denied while held', 10 === $denied && 0 === count( $owners ), json_encode( $child_results ) );
$lk->release();

// Stale steal across processes. INVARIANT: at most ONE owner at any instant.
// Children report {owner,acquired,start_ms,end_ms}; parent verifies no tenure
// overlap (flock is the ownership gate; unlink-on-release means a child that
// arrives after release legitimately acquires an EMPTY lock — that is a fresh
// acquisition, not a stale-steal double-owner).
file_put_contents( $lock_file, '{"o":"dead","t":1}' );
touch( $lock_file, time() - 120 );
$race_counts = array( 5, 10, 50 );
foreach ( $race_counts as $n ) {
        file_put_contents( $lock_file, '{"o":"dead","t":1}' );
        touch( $lock_file, time() - 120 ); // stale again for every round
        $procs   = array();
        $first   = array();
        for ( $i = 0; $i < $n; $i++ ) {
                $procs[ $i ] = popen( sprintf( '"%s" "%s" "%s" "%d"', $php, $script, $lock_file, $i + 1 ), 'r' );
        }
        $tenures  = array();
        $denied_n = 0;
        foreach ( $procs as $i => $p ) {
                $line        = trim( (string) fgets( $p ) );
                $j           = json_decode( $line, true );
                $first[ $i ] = $j;
                if ( is_array( $j ) && ! empty( $j['acquired'] ) ) {
                        $end_line = trim( (string) fgets( $p ) ); // released marker
                        $ej       = json_decode( $end_line, true );
                        $tenures[] = array(
                                (int) $j['start_ms'],
                                is_array( $ej ) && isset( $ej['end_ms'] ) ? (int) $ej['end_ms'] : PHP_INT_MAX,
                                (string) $j['owner'],
                        );
                } else {
                        ++$denied_n;
                }
                pclose( $p );
        }
        $max_overlap = 0;
        for ( $a = 0; $a < count( $tenures ); $a++ ) {
                for ( $b = $a + 1; $b < count( $tenures ); $b++ ) {
                        if ( $tenures[ $a ][0] <= $tenures[ $b ][1] && $tenures[ $b ][0] <= $tenures[ $a ][1] ) {
                                ++$max_overlap; // any pairwise tenure intersection
                                if ( $max_overlap > 3 ) { break 2; }
                        }
                }
        }
        check(
                $results,
                "Stale-lock race N=$n: no overlapping tenures",
                0 === $max_overlap,
                "acquired=" . count( $tenures ) . " denied=$denied_n overlaps=$max_overlap"
        );
        check(
                $results,
                "Stale-lock race N=$n: unique owner tokens",
                count( $tenures ) === count( array_unique( array_column( $tenures, 2 ) ) ),
                json_encode( array_column( $tenures, 2 ) )
        );
}


// ================================================== STEP 9: atomic swap under readers
$atomic_path = $fs_root . '/v/shop.example/swap/index.html';
@mkdir( dirname( $atomic_path ), 0777, true );
$fs->write_atomic( $atomic_path, str_repeat( 'A', 200000 ) );
$reader_script = ULTIMATE_PERFORMANCE_DIR . 'tests/reader-child.php';
$reader_cmd    = sprintf( '"%s" "%s" "%s" 60 2>&1', $php, $reader_script, $atomic_path );
$rp            = popen( $reader_cmd, 'r' );
// Child needs a full php.exe boot (~0.5–1s on HDD) before its first read.
// Writing too early means the reader never sees the 'A' generation →
// false NO-SWAP. Wait for boot, then spread the rewrite window so the
// reader provably observes BOTH generations mid-flight.
usleep( 1200000 );
for ( $w = 0; $w < 40; $w++ ) {
        $fs->write_atomic( $atomic_path, str_repeat( 'B', 100000 + $w * 1000 ) );
        usleep( 50000 );
}
$reader_out = trim( stream_get_contents( $rp ) );
$reader_ec  = pclose( $rp );
check( $results, 'Concurrent reader never saw partial content', 'OK' === substr( $reader_out, 0, 2 ), $reader_out . ' (ec=' . $reader_ec . ')' );

$fails = 0;
echo "\n==== SUMMARY ====\n";
foreach ( $results as $k => $v ) { if ( ! $v ) { ++$fails; echo "FAIL: $k\n"; } }
echo count( $results ) . " checks, $fails failures\n";
exit( $fails ? 1 : 0 );
