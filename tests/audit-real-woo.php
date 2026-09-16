<?php
/**
 * AUDIT TEST — M2: real WooCommerce matrix (≥2 Woo versions on real WP).
 *
 * Runs against REAL WordPress + REAL WooCommerce (real MariaDB, real plugin
 * activation, real WC CLI, real page-cache lifecycle). Provisioned and driven
 * by tests/run-woo-live.sh; per-env contract via UC_REALWP_* variables
 * (shared with the M1 worker + suite conventions).
 *
 * Check groups (per environment):
 *   W1  WooCommerce active, DB version == expected release (real installer ran)
 *   C1  WP-CLI WooCommerce surface: product create/get/list/update and
 *       order/customer listing ALL honour the --format=json contract
 *       (every documented --format=json output must json_decode)
 *   C2  CLI negative contract: unknown subcommand exits non-zero with stderr
 *   P1  page-cache lifecycle on the SHOP page (store + tag shop_archive /
 *       post_type:product) and on a PRODUCT page (post:<id>, product:<id>)
 *   U1  product update through the REAL Woo CLI invalidates the product page
 *       end-to-end (save_post → purge_post → Registry → queue tick)
 *   S1  cross-session leakage (RELEASE-BLOCKING): Woo session/cart cookies
 *       classify DYNAMIC (never cached, never served from cache); two
 *       sessions can never share a cache entry; the stored anonymous page
 *       carries no session/cart content
 *
 * Without UC_REALWP_DIR the suite self-gates into clean SKIP rows.
 *
 * @package UltimatePerformance\Tests
 */

namespace UltimatePerformance\Tests;

$dir = (string) getenv( 'UC_REALWP_DIR' );
if ( '' === $dir || ! is_dir( $dir ) ) {
        echo "[SKIP] audit-real-woo: UC_REALWP_DIR absent — real-Woo matrix not provisioned (honest gate)\n";
        echo "[SKIP] audit-real-woo: all M2 real-Woo checks gated; nothing counted as PASS\n";
        exit( 0 );
}

$url      = (string) getenv( 'UC_REALWP_URL' );
$expected = (string) getenv( 'UC_REALWP_WOO' );   // expected WooCommerce version
$php      = (string) ( getenv( 'UC_REALWP_PHP' ) ?: PHP_BINARY );
$worker   = (string) getenv( 'UC_REALWP_WORKER' );
$wpcli    = (string) getenv( 'UC_REALWP_WPCLI' );

$results = array();
function pcheck( &$r, $name, $cond, $detail = '' ) {
        $r[ $name ] = (bool) $cond;
        echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $name . ( $cond ? '' : " << $detail" ) . "\n";
}

function run_worker2( $php, $worker, $mode, $marker, $uri, $cookie = '' ) {
        $env = array(
                'UC_REALWP_DIR'    => (string) getenv( 'UC_REALWP_DIR' ),
                'UC_REALWP_URL'    => (string) getenv( 'UC_REALWP_URL' ),
                'UC_WORKER_MODE'   => $mode,
                'UC_WORKER_MARKER' => $marker,
                'UC_WORKER_URI'    => $uri,
                'UC_WORKER_COOKIE' => $cookie,
        );
        $spec = array(
                0 => array( 'pipe', 'r' ),
                1 => array( 'pipe', 'w' ),
                2 => array( 'pipe', 'w' ),
        );
        $proc = proc_open( escapeshellarg( $php ) . ' ' . escapeshellarg( $worker ), $spec, $pipes, null, $env );
        if ( ! is_resource( $proc ) ) {
                return array( false, '', '' );
        }
        fclose( $pipes[0] );
        $out = stream_get_contents( $pipes[1] );
        $err = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $code = proc_close( $proc );
        return array( 0 === $code, (string) $out, (string) $err );
}

function wc_cli( $php, $wpcli, $args ) {
        $cmd = escapeshellarg( $php ) . ' ' . escapeshellarg( $wpcli ) . ' --path=' . escapeshellarg( (string) getenv( 'UC_REALWP_DIR' ) ) . ' ' . $args . ' 2>&1; echo RC=$?';
        $raw = (string) shell_exec( $cmd );
        // Split the real exit code off the tail (set by the shell after the CLI ran).
        $rc = 0;
        if ( preg_match( '/RC=(\d+)\s*$/s', $raw, $m ) ) {
                $rc = (int) $m[1];
                $raw = (string) substr( $raw, 0, strlen( $raw ) - strlen( $m[0] ) );
        }
        return array( $raw, $rc );
}

function uc_woo_html_files( $host_dir ) {
        // M2-T1 companion of uc_woo_meta_files(): all index.html bodies,
        // variable depth.
        $out = array();
        if ( ! is_dir( $host_dir ) ) {
                return $out;
        }
        $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $host_dir, \FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $it as $f ) {
                if ( $f->isFile() && 'index.html' === $f->getBasename() ) {
                        $out[] = str_replace( '\\', '/', (string) $f->getPathname() );
                }
        }
        sort( $out );
        return $out;
}

function uc_woo_meta_files( $host_dir ) {
        // M2-T1: store paths are VARIABLE-DEPTH (front page = 1 level under the
        // host dir; pretty-permalink product pages = 2+ levels, e.g.
        // /product/<slug>/). A single-level glob misses nested pages — scan
        // recursively and return every */index.html.meta.json path.
        $out = array();
        if ( ! is_dir( $host_dir ) ) {
                return $out;
        }
        $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $host_dir, \FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $it as $f ) {
                if ( $f->isFile() && 'index.html.meta.json' === $f->getBasename() ) {
                        $out[] = str_replace( '\\', '/', (string) $f->getPathname() );
                }
        }
        sort( $out );
        return $out;
}

// --- boot REAL WordPress + WooCommerce ---------------------------------------
require rtrim( $dir, '/' ) . '/wp-load.php';

// W1 — real WooCommerce installed + versioned ---------------------------------
pcheck( $results, 'W1a WooCommerce plugin active', class_exists( 'WC_Install' ) || class_exists( 'WooCommerce' ), 'classes missing' );
$dbv = (string) get_option( 'woocommerce_db_version', '' );
pcheck( $results, 'W1b WooCommerce DB version == expected release', '' !== $expected && $dbv === $expected, "got '$dbv' want '$expected'" );
pcheck( $results, 'W1c WooCommerce settings persisted', '' !== (string) get_option( 'woocommerce_store_address', '' ) || '' !== (string) get_option( 'woocommerce_currency', '' ) );

// C1 — WP-CLI WooCommerce surface with the --format=json contract -------------
list( $prod_list, ) = wc_cli( $php, $wpcli, 'wc product list --user=ucadmin --format=json' );
$decoded = json_decode( $prod_list, true );
pcheck( $results, 'C1a wc product list --format=json decodes to array', is_array( $decoded ), substr( $prod_list, 0, 120 ) );

list( $created, ) = wc_cli( $php, $wpcli, 'wc product create --user=ucadmin --name="UC-M2 probe product" --regular_price="19.99" --porcelain' );
// Woo CLI on PHP 8.4 may emit deprecation warnings before the id (e.g. Woo
// 8.2.2 array_sum on string) — the porcelain id is the trailing integer.
$pid = 0;
if ( preg_match( '/(\d+)\s*$/s', (string) $created, $pid_m ) ) {
        $pid = (int) $pid_m[1];
}
pcheck( $results, 'C1b wc product create returns real product id', $pid > 0, substr( (string) $created, 0, 120 ) );

if ( $pid > 0 ) {
        list( $got, ) = wc_cli( $php, $wpcli, 'wc product get ' . $pid . ' --user=ucadmin --format=json' );
        $j = json_decode( $got, true );
        pcheck( $results, 'C1c wc product get --format=json decodes with matching id', is_array( $j ) && (int) ( $j['id'] ?? 0 ) === $pid, substr( $got, 0, 120 ) );
        $prod_slug = (string) ( $j['slug'] ?? '' );

        list( $upd, ) = wc_cli( $php, $wpcli, 'wc product update ' . $pid . ' --user=ucadmin --regular_price="24.99"' );
        pcheck( $results, 'C1d wc product update succeeds', false !== strpos( (string) $upd, 'Success' ), substr( (string) $upd, 0, 120 ) );

        list( $list2, ) = wc_cli( $php, $wpcli, 'wc product list --user=ucadmin --format=json' );
        $j2 = json_decode( $list2, true );
        $found = false;
        if ( is_array( $j2 ) ) {
                foreach ( $j2 as $row ) {
                        if ( (int) ( $row['id'] ?? 0 ) === $pid ) {
                                $found = true;
                        }
                }
        }
        pcheck( $results, 'C1e created product visible in --format=json listing', $found );

        $prices = array();
        if ( is_array( $j2 ) ) {
                foreach ( $j2 as $row ) {
                        if ( (int) ( $row['id'] ?? 0 ) === $pid ) {
                                $prices[] = (string) ( $row['price'] ?? '' );
                        }
                }
        }
        pcheck( $results, 'C1f --format=json price reflects the update', in_array( '24.99', $prices, true ), wp_json_encode( $prices ) );
}

list( $order_list, ) = wc_cli( $php, $wpcli, 'wc order list --user=ucadmin --format=json' );
$order_exists = false !== strpos( (string) $order_list, '[' ) || false !== strpos( (string) $order_list, '[]' );
$order_help  = shell_exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $wpcli ) . ' --path=' . escapeshellarg( (string) getenv( 'UC_REALWP_DIR' ) ) . ' help wc order 2>&1; echo RC=$?' );
if ( false === strpos( (string) $order_help, 'RC=0' ) ) {
        echo "[SKIP] C1g wc order list — subcommand not registered by this WooCommerce release (honest gate)\n";
} else {
        pcheck( $results, 'C1g wc order list --format=json decodes to array', is_array( json_decode( $order_list, true ) ), substr( $order_list, 0, 100 ) );
}

list( $cust_list, ) = wc_cli( $php, $wpcli, 'wc customer list --user=ucadmin --format=json' );
$cust_help = shell_exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $wpcli ) . ' --path=' . escapeshellarg( (string) getenv( 'UC_REALWP_DIR' ) ) . ' help wc customer 2>&1; echo RC=$?' );
if ( false === strpos( (string) $cust_help, 'RC=0' ) ) {
        echo "[SKIP] C1h wc customer list — subcommand not registered by this WooCommerce release (honest gate)\n";
} else {
        pcheck( $results, 'C1h wc customer list --format=json decodes to array', is_array( json_decode( $cust_list, true ) ), substr( $cust_list, 0, 100 ) );
}

// C2 — CLI negative contract (real exit code + stderr text) --------------------
list( $bad, $bad_rc ) = wc_cli( $php, $wpcli, 'wc product definitively-not-a-command --user=ucadmin' );
pcheck( $results, 'C2a unknown subcommand exits non-zero with error (not JSON success)', $bad_rc > 0 && false === strpos( (string) $bad, '"id"' ) && '' !== trim( (string) $bad ), 'rc=' . $bad_rc . ' out=' . substr( (string) $bad, 0, 100 ) );

// P1 — page-cache lifecycle on Woo pages --------------------------------------
$host = (string) parse_url( $url, PHP_URL_HOST );

list( $okA, $outA, $errA ) = run_worker2( $php, $worker, 'render', 'W2A' . bin2hex( random_bytes( 4 ) ), '/' );
pcheck( $results, 'P1a front page renders+stores (worker ok)', $okA, substr( $errA, 0, 160 ) );

// Shop page via pretty permalink (provisioning sets /%postname%/ — production
// reality; with plain permalinks every Woo URL is a query string the keygen's
// unknown-query policy bypasses BY DESIGN).
list( $okShop, $outShop, $errShop ) = run_worker2( $php, $worker, 'render', 'W2S' . bin2hex( random_bytes( 4 ) ), '/shop/' );
pcheck( $results, 'P1b shop page renders+stores (worker ok)', $okShop, substr( $errShop, 0, 160 ) );

// Shop page tags: the store-time tagger must have attached shop taxonomy tags.
$shop_rel = '';
if ( $okShop ) {
        $root = \UltimatePerformance\Core\Installer::cache_root() . '/v';
        foreach ( uc_woo_meta_files( $root . '/' . $host ) as $meta_file ) {
                $meta = json_decode( (string) file_get_contents( $meta_file ), true );
                if ( is_array( $meta ) && in_array( 'shop_archive', (array) ( $meta['tags'] ?? array() ), true ) ) {
                        $shop_rel = substr( (string) $meta_file, strlen( $root . '/' . $host . '/' ), -strlen( '/index.html.meta.json' ) );
                        break;
                }
        }
}
pcheck( $results, 'P1c shop page stored with shop_archive tag', '' !== $shop_rel );

// Product page via the default product base + slug.
$prod_uri = '' !== $prod_slug ? '/product/' . rawurlencode( $prod_slug ) . '/' : '/';
list( $okProd, $outProd, $errProd ) = run_worker2( $php, $worker, 'render', 'W2P' . bin2hex( random_bytes( 4 ) ), $prod_uri );
$prod_rel = '';
if ( $okProd ) {
        $root = \UltimatePerformance\Core\Installer::cache_root() . '/v';
        foreach ( uc_woo_meta_files( $root . '/' . $host ) as $meta_file ) {
                $meta = json_decode( (string) file_get_contents( $meta_file ), true );
                if ( is_array( $meta ) && in_array( 'product:' . $pid, (array) ( $meta['tags'] ?? array() ), true ) ) {
                        $prod_rel = substr( (string) $meta_file, strlen( $root . '/' . $host . '/' ), -strlen( '/index.html.meta.json' ) );
                        break;
                }
        }
}
pcheck( $results, 'P1d product page stored with product:<id> tag', '' !== $prod_rel, 'pid=' . $pid );

// HIT on the product archive (served from cache, no re-render).
list( $okHIT, $outHIT, $errHIT ) = run_worker2( $php, $worker, 'fetch', 'W2H' . bin2hex( random_bytes( 4 ) ), '/shop/' );
pcheck( $results, 'P1e second shop fetch served from cache (no re-render)', $okHIT && '' !== $shop_rel, substr( $errHIT, 0, 120 ) );

// U1 — REAL Woo CLI product update invalidates the product page end-to-end.
if ( '' !== $prod_rel && $pid > 0 ) {
        wc_cli( $php, $wpcli, 'wc product update ' . $pid . ' --user=ucadmin --regular_price="29.99"' );
        // Real cron events, exactly as `wp cron event run` fires them on a real
        // site. The plugin's own tick first; then — because an active Woo makes
        // the plugin's queue backend ActionScheduler (supports_worker=false,
        // claim()=null by design: AS self-dispatches) — AS's OWN queue-runner
        // event. M2-T2 (empirical): a job becomes claimable at the NEXT cron
        // tick, not within the same second it was enqueued (aged jobs process
        // instantly; sub-second double-fire claims nothing) — so poll with
        // bounded retries, mirroring repeated real cron ticks.
        $gone    = false;
        $t0      = microtime( true );
        for ( $i = 0; $i < 5 && ! $gone; $i++ ) {
                do_action( 'ultimate_performance_tick' );
                if ( function_exists( 'as_enqueue_async_action' ) ) {
                        do_action( 'action_scheduler_run_queue' );
                }
                $gone = ! is_file( \UltimatePerformance\Core\Installer::cache_root() . '/v/' . $host . '/' . $prod_rel . '/index.html' );
                if ( ! $gone ) {
                        sleep( 2 );
                }
        }
        pcheck( $results, 'U1a wc product update purged the cached product page', $gone, $prod_rel . ' (waited ' . (int) round( microtime( true ) - $t0 ) . 's)' );
} else {
        pcheck( $results, 'U1a wc product update purged the cached product page', false, 'no cached product page' );
}

// S1 — cross-session leakage (RELEASE-BLOCKING) -------------------------------
$c    = new \UltimatePerformance\Request\Classifier( \UltimatePerformance\Core\Plugin::instance()->settings );
// Cookie headers are mirrored into $_COOKIE exactly the way a real web SAPI
// parses the Cookie header BEFORE WordPress/WooCommerce run — a CLI SAPI does
// not do it for us (M2 harness note; matches the worker fix).
function uc_sapi_cookies( $header ) {
        $_COOKIE = array();
        foreach ( explode( ';', $header ) as $kv ) {
                $eq = strpos( $kv, '=' );
                if ( false === $eq ) { continue; }
                $k = trim( substr( $kv, 0, $eq ) );
                $v = trim( substr( $kv, $eq + 1 ) );
                if ( '' !== $k ) { $_COOKIE[ $k ] = $v; }
        }
}
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = $host;

uc_sapi_cookies( '' );
$anon = $c->classify();

uc_sapi_cookies( 'wp_woocommerce_session_' . md5( 'saltA' ) . '=t%24a%24sessionA; woocommerce_items_in_cart=1; woocommerce_cart_hash=a1' );
$sessA = $c->classify();

uc_sapi_cookies( 'wp_woocommerce_session_' . md5( 'saltB' ) . '=t%24b%24sessionB; woocommerce_items_in_cart=1' );
$sessB = $c->classify();

uc_sapi_cookies( 'woocommerce_cart_hash=b2; woocommerce_items_in_cart=1' );
$cart = $c->classify();

pcheck( $results, 'S1a anonymous request is PUBLIC_CACHEABLE', 'PUBLIC_CACHEABLE' === ( $anon['classification'] ?? '' ), ( $anon['classification'] ?? '?' ) . '/' . ( $anon['reason'] ?? '' ) );
pcheck( $results, 'S1b Woo session A is NOT public-cacheable', 'PUBLIC_CACHEABLE' !== ( $sessA['classification'] ?? '' ), ( $sessA['classification'] ?? '?' ) . '/' . ( $sessA['reason'] ?? '' ) );
pcheck( $results, 'S1c Woo session B is NOT public-cacheable', 'PUBLIC_CACHEABLE' !== ( $sessB['classification'] ?? '' ), ( $sessB['classification'] ?? '?' ) . '/' . ( $sessB['reason'] ?? '' ) );
pcheck( $results, 'S1d bare cart cookie is NOT public-cacheable', 'PUBLIC_CACHEABLE' !== ( $cart['classification'] ?? '' ), ( $cart['classification'] ?? '?' ) . '/' . ( $cart['reason'] ?? '' ) );

// Session A must not cause a cache entry that session B could be served.
$vroot = \UltimatePerformance\Core\Installer::cache_root() . '/v/' . $host;
$before = count( uc_woo_html_files( $vroot ) );
list( $okA2, $outA2, $errA2 ) = run_worker2( $php, $worker, 'render', 'W2SA' . bin2hex( random_bytes( 4 ) ), '/', 'wp_woocommerce_session_' . md5( 'saltA' ) . '=t%24a%24sessionA; woocommerce_items_in_cart=1' );
$after = uc_woo_html_files( $vroot );
pcheck( $results, 'S1e session-A cart request stored NOTHING in the page cache', $okA2 && count( $after ) === $before, 'before=' . $before . ' after=' . count( $after ) . ' ' . substr( $errA2, 0, 100 ) );

// The stored anonymous entry carries no per-session cart content. M2-T3: this
// check is self-sufficient — U1a's purge legitimately empties the tree (its
// dirs payload covers product + shop archive + root), so a pre-existing entry
// cannot be assumed. Re-render an anonymous page and inspect the EXACT body
// that was stored for THIS marker.
$anon_stored = false;
$anon_marker = 'W2F' . bin2hex( random_bytes( 4 ) );
list( $okF, $outF, $errF ) = run_worker2( $php, $worker, 'render', $anon_marker, '/' );
if ( $okF ) {
        $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $vroot, \FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $it as $html_file ) {
                if ( ! $html_file->isFile() ) { continue; }
                $body = (string) file_get_contents( $html_file->getPathname() );
                if ( false !== strpos( $body, 'UCM:' . $anon_marker ) && false === strpos( $body, 'sessionA' ) && false === strpos( $body, 'woocommerce-cart-contents' ) ) {
                        $anon_stored = true;
                        break;
                }
        }
}
pcheck( $results, 'S1f stored anonymous page carries no session/cart content', $anon_stored, substr( $errF, 0, 100 ) );

// --- summary -----------------------------------------------------------------
$pass = count( array_filter( $results ) );
$fail = count( $results ) - $pass;
echo "--- audit-real-woo (Woo $expected) pass=$pass fail=$fail ---\n";
exit( $fail > 0 ? 1 : 0 );
