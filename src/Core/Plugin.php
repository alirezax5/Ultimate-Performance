<?php
/**
 * Runtime kernel.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Core;

use UltimatePerformance\Admin\AdminPage;
use UltimatePerformance\CacheInvalidation\Hooks as InvalidationHooks;
use UltimatePerformance\PageCache\Engine;
use UltimatePerformance\Request\Classifier;
use UltimatePerformance\Security\ResponseSanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Boots subsystems per request type. One instance; services injected lazily.
 */
final class Plugin {

        /** @var Plugin|null */
        private static $instance = null;

        /** @var Settings */
        public $settings;

        /** @var Engine|null */
        private $page_cache_engine;

        /** @var array<string,mixed> */
        private $services = array();

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Settings.php';
                $this->settings = Settings::instance();
        }

        public function boot() {
                // §9 — wp_ajax_* hooks are registered DIRECTLY in ultimate-performance.php
                // (the plugin main file) at plugin load time. This method only
                // registers the admin page renderer (admin_menu, admin_enqueue,
                // admin_post_*) and the page-cache runtime.
                add_action( 'plugins_loaded', array( $this, 'late_boot' ), 20 );
                add_action( 'init', array( $this, 'load_text_domain' ) );
        }

        /**
         * Load the plugin text domain for i18n.
         */
        public function load_text_domain() {
                load_plugin_textdomain(
                        'ultimate-performance',
                        false,
                        dirname( plugin_basename( ULTIMATE_PERFORMANCE_FILE ) ) . '/languages/'
                );
        }

        /**
         * Late boot: registers admin page + page cache runtime.
         *
         * §7/§29 — AdminPage registration MUST happen for ALL admin requests,
         * including admin-ajax.php. Previously gated behind
         * `is_admin() && ! wp_doing_ajax()` which excluded AJAX requests,
         * causing AJAX hooks to be unregistered → admin-ajax.php returned
         * body=0 (HTTP 400). Now: register AdminPage on ALL admin requests.
         * The wp_ajax_* hooks are already registered in ultimate-performance.php;
         * AdminPage::register() only adds admin_menu/enqueue/admin_post_*.
         */
        public function late_boot() {
                // Phase K: WP-Cron scheduler
                try {
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Scheduler.php';
                        ( new Scheduler() )->register();
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }

                // WC-PROD-LIFECYCLE (Phase 1 — Delete Product Defect):
                // Invalidation hooks MUST be registered for ALL requests, including
                // wp-admin requests. The previous code placed InvalidationHooks::register()
                // AFTER the is_admin() early-return, which meant NO invalidation hooks
                // fired when an admin edited/deleted/trashed/published a product from
                // wp-admin/edit.php or wp-admin/post.php. Result: deleted products left
                // stale cache artifacts that continued serving the old product page.
                //
                // Proven live on woolena.ir production: post ID 126 (test product "تست محصول")
                // was permanently deleted by the admin, but the cache artifact at
                // v/woolena.ir/product/h126f93c4aa31a5edb1d1/index.html remained and
                // served the deleted product page. Root cause: the delete_post action
                // fired by wp_delete_post() had NO Ultimate Performance listener because
                // Hooks::register() was never called for the admin request.
                //
                // The fix: register invalidation hooks BEFORE the is_admin() check.
                // The hooks are safe for admin context (they only fire on lifecycle
                // actions like save_post/delete_post/woocommerce_update_product, which
                // are content-mutation events that SHOULD purge cache regardless of
                // whether the request is admin or frontend).
                if ( $this->settings->get( 'enabled' ) && $this->settings->get( 'invalidation_enabled', true ) ) {
                        try {
                                InvalidationHooks::register();
                        } catch ( \Throwable $e ) {
                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                                        error_log( 'Ultimate Performance: InvalidationHooks::register() failed: ' . $e->getMessage() );
                                }
                        }
                }

                // §7/§29 — Register AdminPage on ALL admin requests (including AJAX).
                // The wp_ajax_* hooks are already in ultimate-performance.php; this only
                // adds admin_menu, admin_enqueue_scripts, admin_post_* hooks.
                if ( is_admin() ) {
                        try {
                                ( new AdminPage() )->register();
                        } catch ( \Throwable $e ) {
                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                                        error_log( 'Ultimate Performance: AdminPage::register() failed: ' . $e->getMessage() );
                                }
                        }
                        return; // admin requests never touch the page-cache runtime.
                }

                if ( ! $this->settings->get( 'enabled' ) || ! $this->settings->get( 'page_cache_enabled' ) ) {
                        return;
                }

                // Queue worker boot: registers cron schedules + the AS job callback
                // bridge. Required for queued purge_dirs jobs to ever execute.
                if ( $this->settings->get( 'queue_enabled', true ) ) {
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/Queue/QueueManager.php';
                        \UltimatePerformance\Queue\QueueManager::instance()->boot();
                }

                $engine = $this->page_cache();
                $engine->start();

                // M5 cluster invalidation: producer listeners (local-first purge
                // events) + consumer bound to the existing ultimate_performance_tick.
                // Cluster-off safe: a lazy CREATE TABLE on first use; when the DB
                // user cannot create tables the propagator publishes nothing and
                // the consumer consumes nothing (metrics record the failures).
                try {
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/Cluster/EventStore.php';
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/Cluster/NodeIdentity.php';
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/Cluster/Propagator.php';
                        ( new \UltimatePerformance\Cluster\Propagator( new \UltimatePerformance\Cluster\EventStore() ) )->register();
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }
        }

        public function page_cache() {
                if ( null === $this->page_cache_engine ) {
                        require_once ULTIMATE_PERFORMANCE_DIR . 'src/PageCache/Engine.php';
                        $this->page_cache_engine = new Engine(
                                $this->settings,
                                new Classifier( $this->settings ),
                                new ResponseSanitizer()
                        );
                }
                return $this->page_cache_engine;
        }

        /**
         * Generic lazy service accessor.
         *
         * @param string   $key
         * @param callable $factory
         * @return mixed
         */
        public function get( $key, $factory ) {
                if ( ! isset( $this->services[ $key ] ) ) {
                        $this->services[ $key ] = call_user_func( $factory );
                }
                return $this->services[ $key ];
        }
}
