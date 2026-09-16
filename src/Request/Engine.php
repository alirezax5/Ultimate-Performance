<?php
/**
 * PageCache Engine — orchestrates classification, lookup, capture, write.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\PageCache;

use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\Lock\FileLock;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\Request\Classifier;
use UltimatePerformance\Security\ResponseSanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime page cache controller. Hooks output buffering at template_redirect
 * priority 1. Web-server early serving (htaccess) is the primary HIT path;
 * this engine is the MISS-write path and the WP-level fallback serving path.
 */
final class Engine {

        /** @var Settings */
        private $settings;

        /** @var Classifier */
        private $classifier;

        /** @var ResponseSanitizer */
        private $sanitizer;

        /** @var Store|null */
        private $store;

        /** @var array{classification:string,reason:string}|null */
        public $request_class = null;

        /** @var bool */
        private $ob_started = false;

        /** @var string|null */
        private $current_rel_dir = null;

        /** @var Key */
        private $keygen;

        /**
         * BENCH-D7 (HARDEN-1): Generation lock for single-flight cache regeneration.
         * When non-null, this request is the "generator" and MUST release on output.
         *
         * @var GenerationLock|null
         */
        private $genlock = null;

        public function __construct( Settings $settings, Classifier $classifier, ResponseSanitizer $sanitizer ) {
                $this->settings   = $settings;
                $this->classifier = $classifier;
                $this->sanitizer  = $sanitizer;
                require_once ULTIMATE_PERFORMANCE_DIR . 'src/CacheKey/Key.php';
                $this->keygen = new \UltimatePerformance\CacheKey\Key( $settings );
        }

        /**
         * Begin request interception. Returns silently when storage unusable —
         * optimization failure must never become application failure.
         */
        public function start() {
                if ( ! Installer::ensure_cache_root() ) {
                        return;
                }
                add_action( 'template_redirect', array( $this, 'intercept' ), 1 );
        }

        /**
         * WP-level serving path (fallback when no web-server early serve).
         *
         * BENCH-D7 (HARDEN-1): Single-flight generation lock prevents thundering
         * herd. When cache is cold, ONE request acquires the generation lock and
         * renders; concurrent waiters poll the store and serve fresh/stale cache
         * as soon as it appears, never spawning parallel PHP renders.
         */
        public function intercept() {
                $this->request_class = $this->classifier->classify();

                if ( Classifier::BYPASS === $this->request_class['classification']
                	|| Classifier::DYNAMIC === $this->request_class['classification'] ) {
                	$this->header( 'X-Ultimate-Performance', 'BYPASS' );
                	if ( $this->settings->get( 'debug_headers' ) ) {
                		$this->header( 'X-Ultimate-Performance-Reason', substr( (string) $this->request_class['reason'], 0, 120 ) );
                		$this->woo_debug_header();
                	}
                	return; // normal WordPress flow.
                }

                $lookup = $this->lookup_current();
                if ( is_array( $lookup ) && $lookup['found'] && $lookup['fresh'] ) {
                        $this->serve_from_cache( $lookup );
                        exit; // cache HIT: never continue to theme render.
                }

                // SWR: if a stale cache exists and SWR is enabled, serve it immediately
                // (background regeneration is enqueued; no herd because we exit here).
                if ( is_array( $lookup ) && $lookup['found'] && $lookup['stale'] && $this->settings->get( 'swr_enabled' ) ) {
                        $this->serve_from_cache( $lookup );
                        $this->schedule_regeneration();
                        exit;
                }

                // BENCH-D7: Cold cache (MISS). Acquire generation lock (single-flight).
                if ( $this->settings->get( 'herd_protection', true ) ) {
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/PageCache/GenerationLock.php';
                        GenerationLock::ensure_dir();
                        $this->genlock = new GenerationLock( (string) $this->current_rel_dir );

                        if ( ! $this->genlock->try_acquire( (int) $this->settings->get( 'genlock_ttl', GenerationLock::DEFAULT_TTL ) ) ) {
                                // We are a WAITER. Poll the store with bounded wait + jitter.
                                $this->stat( 'herd_waiter', substr( (string) $this->current_rel_dir, 0, 120 ) );
                                $store   = $this->store();
                                $eng     = $this;
                                $on_stale = null;
                                if ( $this->settings->get( 'swr_enabled' ) ) {
                                        $on_stale = function ( $stale_lookup ) use ( $eng ) {
                                                $eng->serve_from_cache( $stale_lookup );
                                                exit;
                                        };
                                }
                                $fresh = $this->genlock->wait_for_generation(
                                        $store,
                                        $on_stale,
                                        (int) $this->settings->get( 'genlock_wait_budget_us', GenerationLock::DEFAULT_WAIT_BUDGET_US )
                                );
                                if ( is_array( $fresh ) && $fresh['found'] && $fresh['fresh'] ) {
                                        $this->stat( 'herd_waiter_served', substr( (string) $this->current_rel_dir, 0, 120 ) );
                                        $this->serve_from_cache( $fresh );
                                        exit;
                                }
                                // Budget exhausted: fall through to render as last resort.
                                $this->stat( 'herd_waiter_timeout', substr( (string) $this->current_rel_dir, 0, 120 ) );
                                // Release the (non-owned) lock reference; we never acquired it.
                                $this->genlock = null;
                        }
                }

                // We are the GENERATOR. Render + capture + write cache, then release lock.
                $this->stat( 'herd_generator', substr( (string) $this->current_rel_dir, 0, 120 ) );
                $this->begin_capture();
        }

        /**
         * Lazy storage accessor (SafeFs-backed page cache store).
         */
        private function store() {
                if ( null === $this->store ) {
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/SafeFs.php';
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/PageCache/Store.php';
                        $this->store = new Store( new SafeFs(), $this->keygen );
                }
                return $this->store;
        }

        /**
         * Compute rel dir + store lookup for the current request.
         *
         * @return array|false
         */
        private function lookup_current() {
                $scheme = is_ssl() ? 'https' : 'http';
                $host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
                $uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
                $parts  = wp_parse_url( $uri );
                $path   = isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '/';
                $query  = isset( $parts['query'] ) && is_string( $parts['query'] ) ? $parts['query'] : '';
                try {
                        $built = $this->keygen->build(
                                $scheme,
                                $host,
                                $path,
                                $query,
                                array(
                                        'accept' => isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '',
                                )
                        );
                } catch ( \Throwable $e ) {
                        return false;
                }
                if ( false === $built ) {
                        return false;
                }
                $this->current_rel_dir = $built['dir'];
                return $this->store()->lookup( $built['dir'] );
        }

        private function serve_from_cache( $lookup ) {
                $meta    = is_array( $lookup['meta'] ) ? $lookup['meta'] : array();
                $headers = isset( $meta['headers'] ) && is_array( $meta['headers'] ) ? $meta['headers'] : array();
                foreach ( $headers as $name => $value ) {
                        $this->header( (string) $name, (string) $value );
                }
                $this->header( 'X-Ultimate-Performance', 'HIT' );
                http_response_code( isset( $meta['status'] ) ? (int) $meta['status'] : 200 );

                $body = (string) $lookup['body'];
                // Defense in depth: refuse to emit cached HTML that fails the sanitizer.
                $audit = $this->sanitizer->audit( $body );
                if ( ! $audit['safe'] ) {
                        $this->purge_current();
                        header_remove( 'X-Ultimate-Performance' );
                        // fall through to normal WordPress rendering instead of emitting poisoned HTML.
                        $this->begin_capture();
                        return;
                }
                echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- verified above; content as rendered by WP.
        }

        private function begin_capture() {
                if ( $this->ob_started || headers_sent() === false && ob_get_length() !== false ) {
                        // ob_start is still safe even if other buffers exist; just guard double-start.
                }
                if ( $this->ob_started ) {
                        return;
                }
                $this->ob_started = true;
                ob_start( array( $this, 'on_output' ) );
        }

        /**
         * ob callback at end of response. SECURITY GATE lives here (fail-closed):
         * re-classify + audit final HTML before anything touches disk.
         *
         * @param string $html Full rendered output.
         * @return string Unmodified input.
         */
        public function on_output( $html ) {
                $this->ob_started = false;
                $status           = http_response_code();

                if ( null === $this->current_rel_dir || ! is_string( $html ) || strlen( $html ) < 64 ) {
                        $this->maybe_release_genlock();
                        return $html;
                }
                if ( null === $status || $status < 200 || $status > 399 ) {
                        $this->stat( 'bypass', 'status:' . (int) $status );
                        $this->maybe_release_genlock();
                        return $html;
                }

                // Content-type policy: the public page cache stores safe HTML only.
                // Anything not explicitly supported bypasses (fail-closed).
                $ctype = (string) ( isset( $_SERVER['HTTP_CONTENT_TYPE'] ) ? $_SERVER['HTTP_CONTENT_TYPE'] : '' );
                foreach ( headers_list() as $h ) {
                        if ( 0 === stripos( $h, 'content-type:' ) ) {
                                $ctype = substr( $h, 13 );
                                break;
                        }
                }
                if ( '' === trim( $ctype ) ) {
                        $ctype = 'text/html'; // WP template renders default to text/html.
                }
                $ctype_base = strtolower( trim( strtok( $ctype, ';' ) ?: $ctype ) );
                if ( ! in_array( $ctype_base, apply_filters( 'ultimate_performance_cacheable_content_types', array( 'text/html', 'application/xhtml+xml' ) ), true ) ) {
                        $this->stat( 'bypass', 'content-type:' . substr( $ctype_base, 0, 40 ) );
                        $this->maybe_release_genlock();
                        return $html;
                }

                $class = $this->classifier->classify();
                if ( Classifier::PUBLIC_CACHEABLE !== $class['classification'] ) {
                        $this->stat( 'bypass', 'write-gate:' . $class['reason'] );
                        $this->maybe_release_genlock();
                        return $html;
                }
                $audit = $this->sanitizer->audit( $html );
                if ( ! $audit['safe'] ) {
                        $this->stat( 'bypass', 'unsafe-html:' . $audit['reason'] );
                        $this->maybe_release_genlock();
                        return $html;
                }

                $headers = apply_filters(
                        'ultimate_performance_response_headers',
                        array( 'Content-Type' => 'text/html; charset=UTF-8' )
                );
                $tags    = apply_filters( 'ultimate_performance_object_tags', array(), $this->current_rel_dir );

                $id = $this->store()->write(
                        $this->current_rel_dir,
                        $html,
                        (int) $status,
                        is_array( $headers ) ? $headers : array(),
                        is_array( $tags ) ? $tags : array(),
                        (int) $this->settings->get( 'ttl', 3600 )
                );

                if ( false !== $id ) {
                        do_action( 'ultimate_performance_page_stored', $id, $this->current_rel_dir, is_array( $tags ) ? $tags : array() );
                }
                $this->header( 'X-Ultimate-Performance', 'MISS' );

                // BENCH-D7 (HARDEN-1): Release the generation lock so waiters can proceed.
                if ( null !== $this->genlock ) {
                        $this->genlock->release();
                        $this->genlock = null;
                }
                return $html;
        }

        /**
         * BENCH-D7 (HARDEN-1): If this request held the generation lock but the
         * write path was bypassed (unsafe HTML, status, etc.), release the lock
         * so waiters are not blocked until TTL.
         */
        private function maybe_release_genlock() {
                if ( null !== $this->genlock ) {
                        $this->genlock->release();
                        $this->genlock = null;
                }
        }

        private function schedule_regeneration() {
                try {
                        $url = home_url( esc_url_raw( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/' ) );
                        \UltimatePerformance\Queue\QueueManager::instance()->enqueue( 'regenerate', array( 'url' => $url ) );
                } catch ( \Throwable $e ) {
                        // queue unavailable → stale stays until natural TTL expiry; site unaffected.
                }
        }

        private function purge_current() {
                if ( null !== $this->current_rel_dir ) {
                        $this->store()->purge( $this->current_rel_dir );
                }
        }

        private function header( $name, $value ) {
        	if ( ! headers_sent() ) {
        		header( $name . ': ' . substr( str_replace( array( "\r", "\n" ), '', (string) $value ), 0, 300 ) );
        	}
        }

        /**
         * §22: X-Ultimate-Performance-Woo = public|private|session.
         *
         * Diagnostics only. `session` when the request carries a WooCommerce
         * session/cart cookie (never cacheable); `private` when it hit the Woo
         * private/mutation gate; `public` when the Woo classification is
         * cacheable. Empty when WooCommerce is not involved.
         */
        private function woo_debug_header() {
        	$reason = (string) $this->request_class['reason'];
        	if ( 0 === stripos( $reason, 'cookie' ) && false !== stripos( $reason, 'woocommerce' ) ) {
        		$this->header( 'X-Ultimate-Performance-Woo', 'session' );
        		return;
        	}
        	if ( 0 === stripos( $reason, 'woo' ) ) {
        		$this->header( 'X-Ultimate-Performance-Woo', 'private' );
        		return;
        	}
        	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
        	$path = (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?: '/' );
        	if ( preg_match( '#/(shop|product|product-category|product-tag)(/|$)#i', $path ) ) {
        		$this->header( 'X-Ultimate-Performance-Woo', 'public' );
        	}
        }

        private function stat( $kind, $detail = '' ) {
                static $fs = null;
                if ( null === $fs ) {
                        $fs = new SafeFs();
                }
                $fs->append_capped(
                        Installer::cache_root() . '/stats.jsonl',
                        wp_json_encode( array( time(), $kind, substr( (string) $detail, 0, 160 ) ) ) . "\n",
                        131072
                );
        }

        /**
         * Default stored response headers.
         *
         * @param array<string,string> $headers
         * @return array<string,string>
         */
        public function default_headers( $headers ) {
                if ( empty( $headers['Cache-Control'] ) ) {
                        $tll                      = min( (int) $this->settings->get( 'ttl', 3600 ), 14400 );
                        $headers['Cache-Control'] = sprintf( 'public, max-age=%d, s-maxage=%d', min( $tll, 300 ), $tll );
                }
                return $headers;
        }
}
