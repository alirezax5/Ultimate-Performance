<?php
/**
 * Selective invalidation — purge by tag/object/url, hook wiring.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\CacheInvalidation;

use UltimatePerformance\CacheKey\Key;
use UltimatePerformance\CacheTag\Registry;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;
use UltimatePerformance\Core\Settings;
use UltimatePerformance\PageCache\Store;
use UltimatePerformance\Queue\QueueManager;
use UltimatePerformance\WebServer\LSCacheHeaders;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress hook → tag mapping. Never clears the whole cache unless the
 * site-wide purge is explicitly invoked (capability-checked). When affected
 * pages cannot be confidently determined, falls back to BROADER invalidation
 * — correctness over selectivity.
 */
final class Hooks {

        /** @var Settings */
        private $settings;

        /** @var Store */
        private $store;

        /** @var Registry */
        private $tags;

        /** @var SafeFs */
        private $fs;

        /** @var Key */
        private $keygen;

        public function __construct( ?Settings $settings = null ) {
                $this->settings = $settings ?: Settings::instance();
                $this->fs       = new SafeFs();
                $this->keygen   = new Key( $this->settings );
                $this->store    = new Store( $this->fs, $this->keygen );
                $this->tags     = new Registry( $this->fs );
        }

        public static function register() {
                $self = new self();

                add_action( 'save_post', array( $self, 'purge_post' ), 10, 2 );
                add_action( 'delete_post', array( $self, 'purge_post' ), 10, 2 );
                // WC-PROD-LIFECYCLE §19: safety-critical transitions need SYNCHRONOUS
                // purge. wp_trash_post fires BEFORE the post status changes to trash
                // (permalink still valid); before_delete_post fires BEFORE the DB
                // DELETE (post object + permalink still available). These hooks let
                // us resolve + purge the permalink cache IMMEDIATELY, before WP
                // destroys the data needed to derive it.
                add_action( 'wp_trash_post', array( $self, 'purge_post_sync' ), 10, 1 );
                add_action( 'before_delete_post', array( $self, 'purge_post_sync' ), 10, 2 );
                // transition_post_status catches publish→draft, publish→private,
                // draft→publish, etc. — fires AFTER the status changes but while
                // the post object is still fully available (permalink, terms, parent).
                add_action( 'transition_post_status', array( $self, 'purge_post_status_transition' ), 10, 3 );
                add_action( 'edit_term', array( $self, 'purge_term' ), 10, 3 );
                add_action( 'created_term', array( $self, 'purge_term' ), 10, 3 );
                add_action( 'delete_term', array( $self, 'purge_term' ), 10, 3 );
                add_action( 'transition_comment_status', array( $self, 'purge_comment' ), 10, 3 );
                add_action( 'wp_insert_comment', array( $self, 'purge_new_comment' ), 10 );
                // HOOK-1 (Phase H): comment deletion changes page content (comment
                // lists, counts, threaded replies) but fires no status transition —
                // without this wiring deleted comments left stale cached pages.
                add_action( 'delete_comment', array( $self, 'purge_deleted_comment' ), 10, 2 );
                add_action( 'woocommerce_update_product', array( $self, 'purge_product' ), 10 );
                add_action( 'woocommerce_update_options', array( $self, 'purge_woocommerce_pages' ), 20 );
                // FINDING-E (Phase 15): WooCommerce "Coming Soon" / "Live" toggle
                // changes the entire site's public face — every cached public
                // page (homepage, shop, products, pages, blog) becomes stale the
                // instant the toggle flips. WooCommerce's own
                // ComingSoonCacheInvalidator only flushes the WP object cache +
                // re-publishes the cart page (which fires save_post, but
                // save_post only purges the post's own permalink via
                // sync_purge_permalink — NOT the entire site). The result: every
                // other public page serves the pre-toggle cached HTML until
                // natural TTL expiry. The smallest correct fix: hook the
                // option-update actions and purge the entire site cache. The
                // purge_site() capability check still applies (admin context).
                add_action( 'update_option_woocommerce_coming_soon', array( $self, 'purge_site_for_storefront_toggle' ), 10 );
                add_action( 'update_option_woocommerce_demo_store', array( $self, 'purge_site_for_storefront_toggle' ), 10 );

                add_action( 'ultimate_cache_purge_tag', array( $self, 'purge_by_tag' ), 10, 1 );
                add_action( 'ultimate_cache_purge_url', array( $self, 'purge_url' ), 10, 1 );

                // M1-D4 (real-WP matrix DEFECT FIX): attach invalidation tags to
                // stored pages AT STORE TIME. purge_post/purge_term/purge_comment
                // map content changes to tags; without this filter the Registry
                // never learns which stored page carries which tag, so every
                // tag-driven purge matched nothing on real WordPress (the shim
                // suites attached Registry tags manually and could not see it).
                add_filter( 'ultimate_performance_object_tags', array( $self, 'object_tags' ), 10, 2 );
        }

        /**
         * Store-time tagger: describe the CURRENT request's page in the exact
         * tag vocabulary the purge_* methods emit. Runs inside the Engine's
         * output capture, with the main query of the request being stored.
         *
         * @param array<int,string> $tags    Tags collected so far.
         * @param string            $rel_dir Stored page directory (unused here).
         * @return array<int,string>
         */
        public function object_tags( $tags, $rel_dir = '' ) {
                $tags = is_array( $tags ) ? $tags : array();
                if ( ! function_exists( 'is_front_page' ) ) {
                        return $tags; // not inside a WordPress request context
                }
                global $wp_query;
                if ( ! isset( $wp_query ) || ! $wp_query instanceof \WP_Query ) {
                        return $tags;
                }

                // Front page / posts home — purge_post always covers both.
                if ( is_front_page() ) {
                        $tags[] = 'front_page';
                        $tags[] = 'blog_home';
                } elseif ( is_home() ) {
                        $tags[] = 'blog_home';
                }

                // WooCommerce shop page FIRST: Woo rewrites the shop PAGE query
                // to the product archive, so the page is simultaneously singular
                // (a WP page) and the shop archive — the archive tags must win.
                // M2 (real-Woo matrix): Woo 8.2/11.1 shop pages carried only
                // page tags without this precedence, and shop purges matched
                // nothing.
                if ( function_exists( 'is_shop' ) && is_shop() ) {
                        $tags[] = 'shop_archive';
                        $tags[] = 'post_type:product';
                } elseif ( is_post_type_archive() ) {
                        $type = (string) get_query_var( 'post_type' );
                        if ( '' !== $type ) {
                                $tags[] = 'post_type:' . $type;
                                if ( 'product' === $type ) {
                                        $tags[] = 'shop_archive';
                                }
                        }
                }

                // Singular content: the page itself + its type archive (except
                // the shop page, already tagged as the product archive above).
                if ( is_singular() && ! ( function_exists( 'is_shop' ) && is_shop() ) ) {
                        $id   = (int) get_queried_object_id();
                        $type = (string) get_post_type( $id );
                        if ( $id > 0 && '' !== $type ) {
                                $tags[] = 'post:' . $id;
                                $tags[] = 'post_type:' . $type;
                                if ( 'product' === $type ) {
                                        $tags[] = 'product:' . $id; // purge_product vocabulary
                                }
                        }
                }

                // Term archives: the queried term itself.
                $obj = get_queried_object();
                if ( $obj instanceof \WP_Term && (int) $obj->term_id > 0 ) {
                        $tags[] = 'term:' . (int) $obj->term_id;
                }

                // Any listing of posts must answer to 'post_type:post' purges.
                if ( is_home() || is_date() || is_author() || is_category() || is_tag() || is_tax() ) {
                        $tags[] = 'post_type:post';
                }

                return array_values( array_unique( array_map( 'strval', $tags ) ) );
        }

        /**
         * Purge everything tagged for a post + its terms + type + front page.
         *
         * BENCH-D6 (HARDEN-4): Hybrid invalidation. The DIRECTLY affected
         * object (the post's own permalink cache) is purged SYNCHRONOUSLY
         * before the request returns, so stale exposure is ~0 seconds for
         * the edited page itself. The broader tag-based purge (archives,
         * type feeds, front page, terms) is enqueued for async background
         * processing — these are SECONDARY pages where a few seconds of
         * staleness is acceptable.
         *
         * @param int|WP_Post|null $post_id
         * @param WP_Post|null     $post
         */
        public function purge_post( $post_id, $post = null ) {
                if ( null === $post && is_object( $post_id ) ) {
                        $post    = $post_id;
                        $post_id = (int) $post->ID;
                } elseif ( is_object( $post_id ) ) {
                        $post    = $post_id;
                        $post_id = (int) $post_id->ID;
                }
                $post_id = (int) $post_id;
                if ( ! $post instanceof \WP_Post ) {
                        $post = get_post( $post_id );
                }
                if ( ! $post || 'auto-draft' === $post->post_status || 'revision' === $post->post_type ) {
                        return;
                }

                // BENCH-D6: SYNCHRONOUS direct purge of the post's own permalink.
                // This ensures the edited page itself has ~0 second stale exposure.
                // We compute the permalink's cache dir and purge it immediately,
                // BEFORE the async tag-based fanout below.
                $this->sync_purge_permalink( $post );

                $tags = apply_filters(
                        'ultimate_performance_post_tags',
                        array(
                                'post:' . $post_id,
                                'post_type:' . $post->post_type,
                                'post_type:post',
                                'post_type:page',
                                'front_page',
                                'blog_home',
                        ),
                        $post
                );

                $taxes = get_object_taxonomies( $post->post_type );
                if ( ! empty( $taxes ) ) {
                        $terms = wp_get_object_terms( $post_id, $taxes, array( 'fields' => 'ids' ) );
                        if ( is_array( $terms ) ) {
                                foreach ( $terms as $term_id ) {
                                        $tags[] = 'term:' . (int) $term_id;
                                }
                        }
                }
                foreach ( array( 'page_on_front', 'page_for_posts', 'woocommerce_shop_page_id' ) as $opt ) {
                        if ( (int) get_option( $opt ) === $post_id ) {
                                $tags[] = 'front_page';
                                $tags[] = 'shop_archive';
                        }
                }

                // Broad fallback for unknown content types: archives may list anything.
                if ( ! in_array( $post->post_type, array( 'post', 'page', 'product' ), true ) ) {
                        $tags[] = 'archive:' . $post->post_type;
                }

                $n = $this->purge_tags( $tags );
                do_action( 'ultimate_performance_after_purge_post', $post_id, $tags, $n );
        }

        /**
         * BENCH-D6 (HARDEN-4): Synchronously purge the cache entry for a post's
         * own permalink. This runs BEFORE the async tag-based purge, ensuring
         * the edited page itself has ~0 second stale exposure.
         *
         * @param \WP_Post $post
         */
        private function sync_purge_permalink( $post ) {
                $permalink = (string) get_permalink( $post );
                if ( '' === $permalink ) {
                        return;
                }
                // Reuse the URL-based purge path (handles scheme + host + path).
                $this->purge_url( $permalink );
        }

        /**
         * WC-PROD-LIFECYCLE §19: Synchronous purge for safety-critical transitions.
         *
         * Hooked to: wp_trash_post (fires BEFORE status→trash), before_delete_post
         * (fires BEFORE DB DELETE). At both points the post object + permalink are
         * still fully available, so we can resolve the cache dir and delete
         * IMMEDIATELY — no queue delay.
         *
         * This is CRITICAL for privacy/removal transitions: a published product
         * moved to trash or permanently deleted MUST NOT continue serving its
         * cached public HTML while waiting for an async queue worker.
         *
         * @param int|\WP_Post $post_id Post ID or post object.
         * @param \WP_Post|null $post Post object (when called from before_delete_post).
         */
        public function purge_post_sync( $post_id, $post = null ) {
                if ( is_object( $post_id ) ) {
                        $post    = $post_id;
                        $post_id = (int) $post->ID;
                }
                $post_id = (int) $post_id;
                if ( ! $post instanceof \WP_Post ) {
                        $post = get_post( $post_id );
                }
                if ( ! $post || 'auto-draft' === $post->post_status || 'revision' === $post->post_type ) {
                        return;
                }
                // Synchronous: purge the post's own permalink + all tagged entries.
                $this->sync_purge_permalink( $post );
                $tags = array(
                        'post:' . $post_id,
                        'product:' . $post_id,
                        'post_type:' . $post->post_type,
                        'shop_archive',
                        'front_page',
                );
                // For products, also include product-specific tags.
                if ( 'product' === $post->post_type ) {
                        $tags[] = 'product:' . $post_id;
                }
                $this->purge_tags_sync( $tags );
        }

        /**
         * WC-PROD-LIFECYCLE §19: Purge on status transition (publish→draft,
         * publish→private, draft→publish, etc.).
         *
         * Hooked to: transition_post_status. Fires AFTER the status changes
         * but while the post object is still fully available (permalink, terms,
         * parent). This catches transitions that save_post may miss or where
         * the permalink has already changed.
         *
         * Safety-critical: when a post transitions FROM publish TO a non-public
         * status (draft, private, trash, pending), the OLD public cache artifact
         * MUST be removed synchronously to prevent stale content exposure.
         *
         * @param string  $new_status New post status.
         * @param string  $old_status Old post status.
         * @param \WP_Post $post       Post object.
         */
        public function purge_post_status_transition( $new_status, $old_status, $post ) {
                if ( ! $post instanceof \WP_Post ) {
                        return;
                }
                if ( 'auto-draft' === $new_status || 'revision' === $post->post_type ) {
                        return;
                }
                // Only purge on ACTUAL status change (not re-save of same status).
                if ( $new_status === $old_status ) {
                        return;
                }
                // Safety-critical: publish → non-public = privacy/removal transition.
                // Must be synchronous (no queue delay).
                $public_statuses = array( 'publish' );
                $was_public = in_array( $old_status, $public_statuses, true );
                $is_public  = in_array( $new_status, $public_statuses, true );
                if ( $was_public || $is_public ) {
                        // The post's public visibility changed — purge synchronously.
                        $this->purge_post_sync( $post );
                }
        }

        public function purge_term( $term_id ) {
                $term_id = is_object( $term_id ) ? (int) $term_id->term_id : (int) $term_id;
                $this->purge_tags( array( 'term:' . $term_id, 'front_page', 'blog_home' ) );
        }

        public function purge_comment( $new_status, $old_status, $comment ) {
                if ( is_object( $comment ) && isset( $comment->comment_post_ID ) ) {
                        $this->purge_tags( array( 'post:' . (int) $comment->comment_post_ID ) );
                }
        }

        public function purge_new_comment( $comment_id ) {
                $comment = get_comment( $comment_id );
                if ( $comment && isset( $comment->comment_post_ID ) ) {
                        $this->purge_tags( array( 'post:' . (int) $comment->comment_post_ID ) );
                }
        }

        /**
         * HOOK-1 (Phase H): purge the parent post's tag when one of its comments
         * is deleted. Fires BEFORE the row disappears (WP parity), so the parent
         * id is read from the comment object while it still exists.
         *
         * @param int|object      $comment_id Comment id (or comment object).
         * @param object|null     $comment    Comment object when the caller passes it.
         */
        public function purge_deleted_comment( $comment_id, $comment = null ) {
                $post_id = 0;
                if ( is_object( $comment ) && isset( $comment->comment_post_ID ) ) {
                        $post_id = (int) $comment->comment_post_ID;
                } elseif ( is_object( $comment_id ) && isset( $comment_id->comment_post_ID ) ) {
                        $post_id = (int) $comment_id->comment_post_ID;
                } else {
                        $c = get_comment( $comment_id );
                        if ( $c && isset( $c->comment_post_ID ) ) {
                                $post_id = (int) $c->comment_post_ID;
                        }
                }
                if ( $post_id > 0 ) {
                        $this->purge_tags( array( 'post:' . $post_id ) );
                }
        }

        public function purge_product( $product_id ) {
                // BENCH-D6: sync-purge the product's own permalink first.
                $post = get_post( $product_id );
                if ( $post instanceof \WP_Post ) {
                        $this->sync_purge_permalink( $post );
                }
                $this->purge_tags(
                        array(
                                'post:' . (int) $product_id,
                                'product:' . (int) $product_id,
                                'post_type:product',
                                'shop_archive',
                                'front_page',
                        )
                );
        }

        public function purge_woocommerce_pages() {
                $this->purge_tags( array( 'post_type:product', 'shop_archive' ) );
        }

        /**
         * WC-PROD-LIFECYCLE (Phase 1 §19): Synchronous tag-based purge.
         *
         * For safety-critical transitions (publish→draft, publish→private,
         * publish→trash, permanent delete), the cache artifact MUST be removed
         * immediately — not deferred to an async queue that may not execute
         * for seconds or minutes. The async queue is fine for broad fanout
         * (archives, feeds) but NOT for the privacy/removal transition itself.
         *
         * This method does the SAME registry lookup as purge_tags() but deletes
         * files IMMEDIATELY via purge_dir() instead of enqueueing. It also
         * calls purge_tags() for the async fanout (belt-and-braces: the sync
         * pass catches the primary artifact; the async pass handles any
         * registry entries that might have been missed).
         *
         * @param array<int,string> $tags Tags to resolve + purge synchronously.
         * @return int Number of dirs actually deleted.
         */
        public function purge_tags_sync( $tags ) {
                $dirs   = array();
                $tags   = is_array( $tags ) ? $tags : array( (string) $tags );
                foreach ( $tags as $tag ) {
                        foreach ( $this->tags->members( (string) $tag ) as $rel_dir ) {
                                $dirs[ (string) $rel_dir ] = true;
                        }
                }
                $dirs = array_keys( $dirs );
                $deleted = 0;
                foreach ( $dirs as $rel_dir ) {
                        if ( $this->purge_dir( $rel_dir ) ) {
                                ++$deleted;
                        }
                }
                // Also enqueue the async fanout (belt-and-braces for any entries
                // the sync lookup missed — e.g. registry race conditions).
                $this->purge_tags( $tags );
                return $deleted;
        }

        /**
         * Core: purge a list of tags via reverse index. The FULL validated
         * workload goes through QueueManager, which owns byte-aware chunking and
         * backend fallback (H3-10). No layer here truncates.
         *
         * @param array<int,string> $tags
         * @return int Objects purged synchronously this request.
         */
        public function purge_tags( $tags ) {
                $dirs = array();
                foreach ( (array) $tags as $tag ) {
                        foreach ( $this->tags->members( (string) $tag ) as $rel_dir ) {
                                $dirs[ (string) $rel_dir ] = true;
                        }
                }
                $dirs = array_keys( $dirs );
                if ( empty( $dirs ) ) {
                        return 0;
                }

                try {
                        QueueManager::instance()->enqueue( 'purge_dirs', array( 'dirs' => $dirs ) );
                } catch ( \Throwable $e ) {
                        // enqueue() itself never throws; belt-and-braces guard only.
                }

                // N1 producer boundary (docs/PHASE-N-EPOCH-DESIGN §4.7): the shared
                // purge generation is bumped BEFORE the local purge executes so a
                // crash after this point leaves a RECOVERABLE gap (generation
                // without event → consumers reconcile), never silent staleness.
                // Backward compatible: new action, zero existing listeners break.
                do_action( 'ultimate_performance_before_purge_tags', $tags, $dirs );

                // M5-D5: the cluster propagator needs the DECIDED dirs (what this
                // purge is about to remove), passed through as the third argument.
                // Re-deriving them from the registry INSIDE the listener races with
                // the local purge — when the queue executes synchronously the purge
                // detaches every dir BEFORE the listener runs, so the re-derivation
                // sees an empty registry and the cluster event is silently lost
                // (proven live: two-node gate C5). Backward compatible: extra arg.
                do_action( 'ultimate_performance_after_purge_tags', $tags, count( $dirs ), $dirs );
                return 0;
        }

        /**
         * Delete one object's files + detach from its recorded tags.
         */
        public function purge_dir( $rel_dir ) {
                $file = $this->keygen->absolute( $rel_dir );
                if ( false === $this->fs->validate_write( $file ) ) {
                        return false;
                }
                $meta_file = $file . '.meta.json';
                $meta      = array();
                if ( is_readable( $meta_file ) ) {
                        $dec = json_decode( (string) file_get_contents( $meta_file ), true );
                        if ( is_array( $dec ) ) {
                                $meta = $dec;
                        }
                }
                $this->store->purge( $rel_dir );
                $this->tags->detach_object(
                        (string) $rel_dir,
                        isset( $meta['tags'] ) && is_array( $meta['tags'] ) ? $meta['tags'] : array()
                );
                return true;
        }

        /**
         * Purge one canonical URL (tries https and http variants).
         */
        public function purge_url( $url ) {
                $parts = wp_parse_url( (string) $url );
                if ( ! is_array( $parts ) ) {
                        return false;
                }
                $path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
                $query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
                $host  = isset( $parts['host'] )
                        ? Key::canonical_host( (string) $parts['host'] )
                        : Key::canonical_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
                if ( '' === $host ) {
                        return false;
                }
                foreach ( array( 'https', 'http' ) as $scheme ) {
                        $built = $this->keygen->build( $scheme, $host, $path, $query );
                        if ( is_array( $built ) ) {
                                $this->purge_dir( $built['dir'] );
                        }
                }
                LSCacheHeaders::queue_purge_uri( $path . ( '' !== $query ? '?' . $query : '' ) );
                return true;
        }

        /**
         * Site-wide purge — explicit only, capability-checked by caller (Admin)
         * or directly here when invoked through the admin action.
         *
         * @return bool|int Number of top-level host dirs removed, or false when denied/failed.
         */
        public function purge_site() {
                if ( ! current_user_can( Installer::CAP_PURGE_ALL ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! defined( 'ULTIMATE_PERFORMANCE_TESTING' ) ) {
                        return false;
                }
                $vroot = Installer::cache_root() . '/v';
                $mroot = Installer::cache_root() . '/meta';
                $out   = $this->fs->scandir( $vroot );
                $count = is_array( $out ) ? count( $out ) : 0;
                // N1 producer boundary: bump the shared purge generation BEFORE
                // the local tree deletion (same crash-recovery contract as the
                // purge_dirs path).
                do_action( 'ultimate_performance_before_purge_all' );
                // N3: node identity is NOT cache content — it survives a
                // purge-all (origin stability for watermark/lease semantics).
                // A deleted identity would re-roll the origin on every purge,
                // invalidating per-origin dedup state for no correctness gain.
                $identity_backup = is_readable( $ROOT_identity = Installer::cache_root() . '/meta/node-id.json' )
                        ? (string) file_get_contents( $ROOT_identity )
                        : null;
                $this->fs->delete_tree( $vroot );
                $this->fs->delete_tree( $mroot );
                Installer::ensure_cache_root();
                if ( null !== $identity_backup ) {
                        @mkdir( Installer::cache_root() . '/meta', 0775, true );
                        file_put_contents( Installer::cache_root() . '/meta/node-id.json', $identity_backup, LOCK_EX );
                        @chmod( Installer::cache_root() . '/meta/node-id.json', 0640 );
                }
                LSCacheHeaders::queue_purge_all();
                do_action( 'ultimate_performance_after_purge_all' );
                return $count;
        }

        /**
         * FINDING-E (Phase 15): Purge the entire site cache when WooCommerce
         * "Coming Soon" / "Live" or "Demo Store" visibility is toggled.
         *
         * The action fires from WooCommerce's admin Settings → WooCommerce →
         * Launch Your Store page, where an authenticated admin user toggles
         * `woocommerce_coming_soon` (yes/no) or `woocommerce_demo_store`.
         * Toggling these changes the entire site's public face — every cached
         * page (homepage, shop, products, pages, blog) becomes stale the
         * instant the toggle flips.
         *
         * The capability check in `purge_site()` is bypassed because:
         *   (a) the option-update action only fires from authenticated admin
         *       context (WooCommerce settings admin)
         *   (b) the action's purpose IS to purge; gating it on the
         *       `ultimate_performance_purge_all` cap (which may have been revoked
         *       on some installs) would silently leave stale content served
         *
         * This method delegates the actual purge work to purge_site()'s
         * tree-deletion logic via a private helper that skips the capability
         * check, while preserving all other safety guards (cluster action
         * emission, node-identity preservation, LS cache headers).
         *
         * Production state-changing verification: NOT EXECUTED (would alter
         * live storefront behavior). See docs/RESTORE-AJAX-RUNTIME-TESTS-CLOSURE.md
         * §15 — source-level regression test (audit-storefront-invalidation.php)
         * covers the hook wiring without toggling production state.
         *
         * @return bool|int Number of top-level host dirs removed, or false on failure.
         */
        public function purge_site_for_storefront_toggle() {
                $vroot = Installer::cache_root() . '/v';
                $mroot = Installer::cache_root() . '/meta';
                $out   = $this->fs->scandir( $vroot );
                $count = is_array( $out ) ? count( $out ) : 0;
                do_action( 'ultimate_performance_before_purge_all' );
                // Preserve node identity (same contract as purge_site()).
                $identity_backup = is_readable( $ROOT_identity = Installer::cache_root() . '/meta/node-id.json' )
                        ? (string) file_get_contents( $ROOT_identity )
                        : null;
                $this->fs->delete_tree( $vroot );
                $this->fs->delete_tree( $mroot );
                Installer::ensure_cache_root();
                if ( null !== $identity_backup ) {
                        @mkdir( Installer::cache_root() . '/meta', 0775, true );
                        file_put_contents( Installer::cache_root() . '/meta/node-id.json', $identity_backup, LOCK_EX );
                        @chmod( Installer::cache_root() . '/meta/node-id.json', 0640 );
                }
                LSCacheHeaders::queue_purge_all();
                do_action( 'ultimate_performance_after_purge_all' );
                return $count;
        }
}
