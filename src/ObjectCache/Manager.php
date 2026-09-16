<?php
/**
 * Object cache runtime — the WordPress-facing semantics layer (Phase I).
 *
 * Sits between the wp_cache_* API (drop-in) and a Backend. Responsibilities:
 *  - group registries: global groups (blog-independent) and non-persistent
 *    groups (per-process only, never sent to the persistent backend),
 *  - blog scope stack (switch_to_blog / restore_current_blog semantics),
 *  - a per-process runtime layer (read-through mirror + non-persistent store),
 *  - fail-closed backend handling with a bounded recheck window,
 *  - promotion fencing (Phase K): a backend that failed is reconciled
 *    (flushed) ONCE before it regains service rights — stale data from
 *    an outage window never re-enters the chain; a healthy flush is
 *    never blocked by a fenced backend (skip, not fail),
 *  - truthful wp_cache_supports() and stats.
 *
 * Scope model mirrors core WP_Object_Cache: blog-scoped groups are isolated
 * per blog; global groups are shared; flush() wipes EVERYTHING (core WP
 * behavior); flushGroup() wipes one group in the active scope; non-persistent
 * groups live only in this process.
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

defined( 'ABSPATH' ) || exit;

final class Manager {

        const VERSION = '1';

        /** @var Manager|null */
        private static $instance = null;

        /** @var array<int,Backend> persistent backend chain, priority order */
        private $backends = array();

        /** @var MemoryBackend per-process runtime layer (mirror + non-persistent) */
        private $runtime;

        /** @var array<string,bool> global groups (keys of the set) */
        private $global_groups = array();

        /** @var array<string,bool> non-persistent groups */
        private $non_persistent_groups = array();

        /** @var array<int,int> blog scope stack (current last) */
        private $blog_stack = array();

        /** @var bool one-shot flag so the lazy chain upgrade never recurs */
        private $upgraded = false;

        /** @var array<int,int> microts until which each backend index is presumed unhealthy */
        private $unhealthy = array();

        /** @var float recheck window in seconds */
        private $recheck_window = 1.0;

        /** @var array<int,bool> indices that failed at least once and await promotion (fence) */
        private $was_unhealthy = array();

        /** @var int chain epoch floor (monotonic in-process; persisted best-effort) */
        private $epoch = 0;

        /** @var int number of fence reconciles performed (diagnostic, low cardinality) */
        private $fences = 0;

        const EPOCH_GROUP = 'uc:internal:chain-epoch';
        const EPOCH_KEY   = 'chain';

        /** @var string|null site namespace prefix (cached) */
        private $prefix = null;

        /**
         * Resolve the object cache prefix/namespace for the current site.
         * When setting 'object_cache_prefix' is empty, auto-generates from site hostname.
         *
         * @return string Safe prefix (e.g. "woolena" or "shop-example")
         */
        public function resolve_prefix() {
                if ( null !== $this->prefix ) {
                        return $this->prefix;
                }
                // Check if a custom prefix is configured
                if ( function_exists( 'get_option' ) ) {
                        $settings = \UltimatePerformance\Core\Settings::instance();
                        $custom = (string) $settings->get( 'object_cache_prefix', '' );
                        if ( '' !== $custom ) {
                                $this->prefix = $custom;
                                return $this->prefix;
                        }
                }
                // Auto-generate from site hostname
                $host = '';
                if ( function_exists( 'home_url' ) ) {
                        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
                }
                if ( '' === $host && isset( $_SERVER['HTTP_HOST'] ) ) {
                        $host = (string) $_SERVER['HTTP_HOST'];
                }
                $this->prefix = self::generate_prefix_from_host( $host );
                return $this->prefix;
        }

        /**
         * Deterministic host → prefix generation.
         * Removes port, www., TLD (heuristic), sanitizes to safe chars.
         *
         * @param string $host Raw hostname (e.g. "www.shop.example.com:443")
         * @return string Safe prefix (e.g. "shop-example")
         */
        public static function generate_prefix_from_host( $host ) {
                $host = strtolower( trim( (string) $host ) );
                // Strip port
                $host = preg_replace( '/:\d+$/', '', $host );
                // Strip www.
                $host = preg_replace( '/^www\./', '', $host );
                // Split into labels
                $labels = explode( '.', $host );
                $labels = array_filter( $labels, 'strlen' );
                if ( count( $labels ) <= 1 ) {
                        $base = implode( '', $labels );
                } else {
                        // Heuristic: remove the last 1-2 labels (TLD)
                        // e.g. example.com → example, shop.example.com → shop-example
                        // For known multi-part TLDs (co.uk, com.au), remove 2 labels
                        $tld = $labels[ count( $labels ) - 1 ];
                        $sld = count( $labels ) >= 2 ? $labels[ count( $labels ) - 2 ] : '';
                        $multi_tlds = array( 'uk', 'au', 'nz', 'za', 'br', 'mx', 'in', 'jp', 'kr', 'tw', 'cn', 'ru', 'ua', 'tr', 'pl', 'cz', 'sk', 'hu', 'ro', 'bg', 'hr', 'si', 'ee', 'lv', 'lt', 'il', 'ar', 'cl', 'pe', 'co', 've', 'ec' );
                        $multi_slds = array( 'co', 'com', 'org', 'net', 'gov', 'ac', 'edu', 'mil', 'mod' );
                        if ( in_array( $tld, $multi_tlds, true ) && in_array( $sld, $multi_slds, true ) ) {
                                // co.uk, com.au etc — remove 2 labels
                                $labels = array_slice( $labels, 0, -2 );
                        } else {
                                // Regular TLD — remove 1 label
                                $labels = array_slice( $labels, 0, -1 );
                        }
                        $base = implode( '-', $labels );
                }
                // Sanitize: only a-z 0-9 - _ :
                $base = preg_replace( '/[^a-z0-9\-_]/', '', $base );
                $base = substr( $base, 0, 64 );
                if ( '' === $base ) {
                        $base = 'uc-default';
                }
                return $base;
        }

        /**
         * Prefix a key for backend storage.
         *
         * @param string $key   Original key.
         * @param string $group Group (already runtime_group'd by caller).
         * @return string Prefixed key for backend.
         */
        private function prefixed_key( $key, $group ) {
                $prefix = $this->resolve_prefix();
                return $prefix . ':' . $group . ':' . $key;
        }
        private $hits = 0;
        private $misses = 0;

        /** @var array<string,bool> wp_cache_supports feature map of the ACTIVE backend */
        private $features = array(
                'add_multiple'  => false,
                'set_multiple'  => false,
                'get_multiple'  => false,
                'flush_runtime' => true,
                'flush_group'   => false,
                'incr'          => false,
                'decr'          => false,
                'group'         => true,
        );

        /**
         * @param Backend|array<int,Backend>|null $backend Persistent backend (chain in priority order); empty = runtime-only mode.
         */
        public function __construct( $backend = null ) {
                $this->runtime = new MemoryBackend();
                foreach ( is_array( $backend ) ? $backend : array( $backend ) as $b ) {
                        if ( $b instanceof Backend ) {
                                $this->backends[] = $b;
                        }
                }
                if ( ! empty( $this->backends ) ) {
                        $this->probe_backends();
                }
                $this->register_core_groups();
        }

        /**
         * Process-wide singleton used by the generated drop-in. When the persistent
         * backend cannot be constructed the manager degrades to runtime-only mode
         * (never fatals, never blocks WordPress).
         *
         * @return Manager
         */
        public static function instance() {
                // §BOOT — Lazy in-place chain upgrade.
                //
                // object-cache.php is included by wp-settings.php VERY early —
                // before plugins and before get_option() is usable. At that
                // moment RedisBackend::configured() cannot read the stored
                // settings, so the first instance() call builds an EMPTY chain
                // and the manager degrades to runtime-only.
                //
                // WordPress's wp_cache_*() functions dispatch through
                // $GLOBALS['up_object_cache'], which is assigned at drop-in load
                // time. Replacing the singleton here would leave that global
                // pointing at the OLD runtime-only manager, so the upgrade MUST
                // happen in place: the same object gains its persistent chain
                // once settings become readable. Object identity is preserved
                // (spl_object_id unchanged), no duplicate manager exists, and
                // runtime data already written during early boot is untouched.
                if ( null === self::$instance ) {
                        self::$instance = new self( self::resolve_chain() );
                } elseif ( function_exists( 'get_option' )
                        && empty( self::$instance->backends )
                        && ! self::$instance->upgraded ) {
                        self::$instance->upgrade_persistent_chain();
                }
                return self::$instance;
        }

        /**
         * Build the persistent backend chain (Redis, then Memcached).
         *
         * Each construction is independent — a failing backend never blocks
         * the others. Safe at ANY load stage: returns an empty array when the
         * settings API is not yet available (early boot).
         *
         * @return array<int,Backend>
         */
        private static function resolve_chain() {
                $chain = array();
                // §3 directive: a DB number configured in the admin UI must
                // reach RedisBackend and ultimately SELECT the configured DB.
                //
                // PRECEDENCE: when the admin has explicitly enabled object
                // cache + set redis.host in the Settings option, the Settings
                // option wins over constants/env. This is critical because a
                // partial constant/env setup (e.g. only UC_REDIS_HOST set,
                // UC_REDIS_DB not set) would otherwise ignore the admin UI's
                // redis.db value and silently fall back to db=0.
                //
                // Constants/env are used only as a FALLBACK when Settings is
                // not explicitly configured (e.g. wp-config.php constants on
                // a fresh install before the admin UI is touched).
                try {
                        if ( class_exists( '\\Redis' ) && RedisBackend::configured() ) {
                                $rb = RedisBackend::from_settings();
                                if ( null === $rb && RedisBackend::configured_via_constants() ) {
                                        $rb = new RedisBackend();
                                }
                                if ( null !== $rb ) {
                                        $chain[] = $rb;
                                }
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }
                try {
                        if ( class_exists( '\\Memcached' ) && MemcachedBackend::configured() ) {
                                // Same precedence as Redis: Settings option wins over
                                // constants/env when the admin has explicitly configured it.
                                $mb = MemcachedBackend::from_settings();
                                if ( null === $mb && MemcachedBackend::configured_via_constants() ) {
                                        $mb = new MemcachedBackend();
                                }
                                if ( null !== $mb ) {
                                        $chain[] = $mb;
                                }
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }
                return $chain;
        }

        /**
         * Upgrade a runtime-only manager in place once settings are readable.
         *
         * Called from instance() when the manager was built during early boot
         * (empty persistent chain) but WordPress can now read stored options.
         * Runtime data is untouched; only the persistent chain is added.
         *
         * @return void
         */
        private function upgrade_persistent_chain() {
                $this->upgraded = true; // one-shot, even on failure
                $chain          = self::resolve_chain();
                if ( empty( $chain ) ) {
                        return;
                }
                foreach ( $chain as $b ) {
                        if ( $b instanceof Backend ) {
                                $this->backends[] = $b;
                        }
                }
                if ( ! empty( $this->backends ) ) {
                        $this->probe_backends();
                }
        }

        /**
         * Bootstrap-safe diagnostics for the lazy chain upgrade.
         *
         * @return array{persistent_backends_initialized:bool,upgrade_attempted:bool,upgrade_result:string}
         */
        public function boot_diagnostics() {
                $result = 'runtime';
                if ( ! empty( $this->backends ) ) {
                        $names = $this->backendNames();
                        $result = isset( $names[0] ) ? (string) $names[0] : 'runtime';
                }
                return array(
                        'persistent_backends_initialized' => ! empty( $this->backends ),
                        'upgrade_attempted'               => (bool) $this->upgraded,
                        'upgrade_result'                  => $result,
                );
        }

        /**
         * Reset the singleton (test seam only).
         *
         * @return void
         */
        public static function reset_instance() {
                if ( null !== self::$instance ) {
                        self::$instance->close();
                }
                self::$instance = null;
        }

        private function register_core_groups() {
                $global = array(
                        'users', 'userlogins', 'usermeta', 'user_meta', 'useremail', 'userslugs',
                        'site-transient', 'site-options', 'network-aliases', 'networks', 'sites',
                        'site-details', 'blog-details', 'blog-id-cache', 'blog-lookup', 'blog-meta',
                        'global-cache', 'rss', 'object-cache',
                        // M1-D3 (real-WP multisite matrix): the plugin's OWN internal
                        // groups must be blog-independent — the chain epoch is a
                        // site-wide notion (cross-process fencing compares epochs no
                        // matter which blog a request serves). Blog-scoped epoch keys
                        // would let different blogs disagree about chain generation.
                        'uc:internal', 'uc:internal:chain-epoch',
                );
                foreach ( $global as $g ) {
                        $this->global_groups[ $g ] = true;
                }
                // Non-persistent: counts + anything registered. Kept minimal + filterable.
                $this->non_persistent_groups['counts'] = true;
        }

        // ----------------------------------------------------------------- reads

        public function get( $key, $group = 'default', $force = false, &$found = null ) {
                $group  = $this->normalize_group( $group );
                $found  = false;
                if ( ! $this->normalize_key( $key ) ) {
                        return false; // M1-D2: WP contract — wp_cache_get returns false on failure
                }

                $slot_group = $this->runtime_group( $group );
                $has        = false;
                $cached     = $this->runtime->get( $key, $slot_group, $has );
                if ( ! $force && $has ) {
                        // runtime mirror / non-persistent hit — $found=true for ANY stored value.
                        $found = true;
                        ++$this->hits;
                        return $cached;
                }

                if ( $this->is_non_persistent( $group ) ) {
                        ++$this->misses;
                        return false; // M1-D2: WP contract — miss is false, never null
                }

                $value = $this->backend_get( $key, $group, $found );
                if ( $found ) {
                        ++$this->hits;
                        $this->runtime->set( $key, $value, 0, $slot_group ); // read-through mirror
                } else {
                        ++$this->misses;
                }
                return $value;
        }

        public function getMultiple( $keys, $group = 'default' ) {
                $group = $this->normalize_group( $group );
                $out   = array();
                if ( ! is_array( $keys ) ) {
                        return $out;
                }
                foreach ( $keys as $k ) {
                        if ( ! $this->normalize_key( $k ) ) {
                                $out[ $k ] = false; // M1-D2: WP shape — even invalid keys surface as false
                                continue;
                        }
                        $found     = false;
                        $out[ $k ] = $this->get( $k, $group, false, $found ); // M1-D2: WP shape — EVERY key present; missing = false
                }
                return $out;
        }

        // ---------------------------------------------------------------- writes

        public function set( $key, $value, $group = 'default', $ttl = 0 ) {
                $group = $this->normalize_group( $group );
                if ( ! $this->normalize_key( $key ) ) {
                        return false;
                }
                $ttl        = $this->normalize_ttl( $ttl );
                $slot_group = $this->runtime_group( $group );
                $this->runtime->set( $key, $value, $ttl, $slot_group );
                if ( $this->is_non_persistent( $group ) ) {
                        return true;
                }
                if ( null === $this->require_backend() ) {
                        return true; // M1-D2: runtime-only mode — the in-memory write IS the write
                }
                return $this->backend_set( $key, $value, $ttl, $group );
        }

        public function add( $key, $value, $group = 'default', $ttl = 0 ) {
                $group = $this->normalize_group( $group );
                if ( ! $this->normalize_key( $key ) ) {
                        return false;
                }
                $ttl        = $this->normalize_ttl( $ttl );
                $slot_group = $this->runtime_group( $group );
                // CAS against the runtime layer first: within this process an existing
                // mirror entry means the key exists (mirror is invalidated on delete).
                $probe = null;
                $this->runtime->get( $key, $slot_group, $exists );
                if ( $exists ) {
                        return false;
                }
                if ( $this->is_non_persistent( $group ) ) {
                        return $this->runtime->add( $key, $value, $ttl, $slot_group );
                }
                if ( null === $this->require_backend() ) {
                        return $this->runtime->add( $key, $value, $ttl, $slot_group ); // runtime-only mode
                }
                $ok = $this->backend_add( $key, $value, $ttl, $group );
                if ( $ok ) {
                        $this->runtime->set( $key, $value, $ttl, $slot_group );
                }
                return $ok;
        }

        public function replace( $key, $value, $group = 'default', $ttl = 0 ) {
                $group = $this->normalize_group( $group );
                if ( ! $this->normalize_key( $key ) ) {
                        return false;
                }
                $ttl        = $this->normalize_ttl( $ttl );
                $slot_group = $this->runtime_group( $group );
                $probe      = null;
                $this->runtime->get( $key, $slot_group, $exists );
                if ( ! $exists ) {
                        return false; // WP replace: false when the key is not cached here.
                }
                if ( $this->is_non_persistent( $group ) ) {
                        return $this->runtime->set( $key, $value, $ttl, $slot_group );
                }
                if ( null === $this->require_backend() ) {
                        return $this->runtime->set( $key, $value, $ttl, $slot_group ); // runtime-only mode
                }
                $ok = $this->backend_replace( $key, $value, $ttl, $group );
                if ( $ok ) {
                        $this->runtime->set( $key, $value, $ttl, $slot_group );
                }
                return $ok;
        }

        public function delete( $key, $group = 'default' ) {
                $group = $this->normalize_group( $group );
                if ( ! $this->normalize_key( $key ) ) {
                        return false;
                }
                $slot_group = $this->runtime_group( $group );
                $probe      = null;
                $this->runtime->get( $key, $slot_group, $existed );
                $this->runtime->delete( $key, $slot_group );
                if ( $this->is_non_persistent( $group ) || null === $this->require_backend() ) {
                        return (bool) $existed; // WP semantics: true when deleted, false when absent.
                }
                return $this->backend_delete( $key, $group );
        }

        public function incr( $key, $n = 1, $group = 'default' ) {
                return $this->arith( $key, abs( (int) $n ), $group, true );
        }

        public function decr( $key, $n = 1, $group = 'default' ) {
                return $this->arith( $key, abs( (int) $n ), $group, false );
        }

        private function arith( $key, $n, $group, $up ) {
                $group = $this->normalize_group( $group );
                if ( ! $this->normalize_key( $key ) ) {
                        return false;
                }
                $slot_group = $this->runtime_group( $group );
                if ( $this->is_non_persistent( $group ) || null === $this->require_backend() ) {
                        return $up
                                ? $this->runtime->incr( $key, $n, $slot_group )
                                : $this->runtime->decr( $key, $n, $slot_group );
                }
                $r = null;
                try {
                        $bg    = $this->runtime_group( $group ); // scope-embedded group at the backend
                        $index = 0;
                        $backend = $this->pick_backend( $index );
                        if ( null === $backend ) {
                                return false; // every backend degraded
                        }
                        $r = $up ? $backend->incr( $this->prefixed_key( $key, $bg ), $n, $bg ) : $backend->decr( $this->prefixed_key( $key, $bg ), $n, $bg );
                } catch ( \Throwable $e ) {
                        $this->mark_unhealthy( $index ?? 0 );
                        return false;
                }
                if ( false === $r ) {
                        return false;
                }
                $this->runtime->set( $key, $r, 0, $slot_group ); // mirror the new value
                return $r;
        }

        // ----------------------------------------------------------------- flush

        public function flush() {
                $this->runtime->flush(); // mirror + non-persistent
                if ( null === $this->require_backend() ) {
                        return true;
                }
                $epoch = $this->next_epoch(); // one bump per logical flush
                $ok    = true;
                // A flush must invalidate data wherever it may live: apply to ALL backends.
                // Unhealthy indices are SKIPPED — never blocking a healthy flush; the
                // promotion fence reconciles them before they are reused.
                foreach ( $this->backends as $i => $b ) {
                        if ( $this->is_unhealthy( $i ) ) {
                                continue;
                        }
                        try {
                                $ok = $b->flush() && $ok;
                                $this->write_epoch( $b, $epoch ); // best-effort, never fails the flush
                        } catch ( \Throwable $e ) {
                                $this->mark_unhealthy( $i );
                                $ok = false;
                        }
                }
                return $ok;
        }

        public function flushGroup( $group ) {
                $group = $this->normalize_group( $group );
                $this->runtime->flushGroup( $this->runtime_group( $group ) );
                if ( $this->is_non_persistent( $group ) || null === $this->require_backend() ) {
                        return true;
                }
                $epoch = $this->next_epoch(); // group misses are fence-covered too
                $ok    = true;
                // The group's data may live on any backend in the chain: flush ALL.
                // Unhealthy indices are skipped (fence reconciles them later).
                foreach ( $this->backends as $i => $b ) {
                        if ( $this->is_unhealthy( $i ) ) {
                                continue;
                        }
                        try {
                                $ok = $b->flushGroup( $this->runtime_group( $group ) ) && $ok;
                                $this->write_epoch( $b, $epoch ); // best-effort, never fails the flush
                        } catch ( \Throwable $e ) {
                                $this->mark_unhealthy( $i );
                                $ok = false;
                        }
                }
                return $ok;
        }

        /**
         * Clear ONLY the per-process layer (mirror + non-persistent groups).
         *
         * @return bool
         */
        public function flushRuntime() {
                $this->runtime->flush();
                return true;
        }

        // ----------------------------------------------------------------- blogs

        public function switch_blog( $blog_id ) {
                $blog_id            = (int) $blog_id;
                $this->blog_stack[] = $blog_id > 0 ? $blog_id : 1;
                return true;
        }

        public function restore_blog() {
                if ( ! empty( $this->blog_stack ) ) {
                        array_pop( $this->blog_stack ); // last entry was the switched-to blog
                }
                return true;
        }

        public function current_blog() {
                if ( ! empty( $this->blog_stack ) ) {
                        return $this->blog_stack[ count( $this->blog_stack ) - 1 ];
                }
                return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
        }

        // --------------------------------------------------------------- groups

        public function addGlobalGroups( $groups ) {
                foreach ( (array) $groups as $g ) {
                        $this->global_groups[ (string) $g ] = true;
                }
        }

        public function addNonPersistentGroups( $groups ) {
                foreach ( (array) $groups as $g ) {
                        $this->non_persistent_groups[ (string) $g ] = true;
                }
        }

        public function isGlobalGroup( $group ) {
                return ! empty( $this->global_groups[ $group ] );
        }

        public function isNonPersistentGroup( $group ) {
                return ! empty( $this->non_persistent_groups[ $group ] );
        }

        // -------------------------------------------------------------- supports

        public function supports( $feature ) {
                return ! empty( $this->features[ $feature ] );
        }

        /**
         * Current chain epoch held by the healthy persistent backends (max),
         * 0 when none holds one. This is the truthful CROSS-PROCESS generation
         * source for the M5 cluster epoch guard (docs/PHASE-M-CLUSTER-INVALIDATION
         * §4.6 "mirrors the promotion fencing epoch"): a fresh process must read
         * the cluster's persisted generation, not its own in-process floor.
         * Runtime-only mode (no persistent backend configured) yields 0 — the
         * guard then compares 0<0 and never skips (documented vacuity).
         *
         * @return int
         */
        public function current_epoch() {
                $max = 0;
                foreach ( $this->backends as $i => $b ) {
                        if ( $this->is_unhealthy( $i ) ) {
                                continue;
                        }
                        $ep = $this->read_epoch( $b );
                        if ( $ep > $max ) {
                                $max = $ep;
                        }
                }
                return $max;
        }

        public function stats() {
                return array(
                        'hits'            => $this->hits,
                        'misses'          => $this->misses,
                        'backend_healthy' => $this->backendHealthy(),
                        'backend'         => $this->backendNames(),
                        'global_groups'   => array_keys( $this->global_groups ),
                        'non_persistent'  => array_keys( $this->non_persistent_groups ),
                        // Phase K: promotion fencing + warm-standby disclosure.
                        'fences'          => $this->fences,
                        'write_mode'      => 'single-writer',
                        'consistency'     => 'eventual',
                );
        }

        /**
         * Whether the persistent backend chain is populated.
         *
         * Used by the generated drop-in's wp_cache_init() to decide whether the
         * runtime-only manager built during early boot needs its in-place
         * upgrade now that WordPress can read stored settings.
         *
         * @return bool
         */
        public function has_persistent_backend() {
                return ! empty( $this->backends );
        }

        public function backendHealthy() {
                if ( null === $this->require_backend() ) {
                        return false; // runtime-only mode or every backend degraded
                }
                foreach ( $this->backends as $i => $b ) {
                        if ( ! $this->is_unhealthy( $i ) ) {
                                return true;
                        }
                }
                return false;
        }

        /**
         * Backend chain identity for diagnostics (low cardinality).
         *
         * @return array<int,string>
         */
        public function backendNames() {
                $names = array();
                foreach ( $this->backends as $b ) {
                        $names[] = get_class( $b );
                }
                return $names;
        }

        /**
         * Active backend short name for runtime diagnostics.
         *
         * Returns the short class name of the FIRST non-unhealthy backend in
         * the chain ('RedisBackend', 'MemcachedBackend', ...), or 'runtime'
         * when no persistent backend is healthy.
         *
         * §14 directive — the admin UI must honestly report which backend
         * is actually serving cache reads. Never claims Redis is active just
         * because Redis settings exist.
         *
         * @return string
         */
        public function active_backend_name() {
                if ( empty( $this->backends ) ) {
                        return 'runtime';
                }
                foreach ( $this->backends as $i => $b ) {
                        if ( ! $this->is_unhealthy( $i ) ) {
                                $cls = ( new \ReflectionClass( $b ) )->getShortName();
                                return strtolower( preg_replace( '/Backend$/', '', $cls ) );
                        }
                }
                return 'runtime';
        }

        /**
         * Preferred backend short name (configured/preferred chain head).
         *
         * @return string
         */
        public function preferred_backend_name() {
                if ( empty( $this->backends ) ) {
                        return 'runtime';
                }
                $b   = $this->backends[0];
                $cls = ( new \ReflectionClass( $b ) )->getShortName();
                return strtolower( preg_replace( '/Backend$/', '', $cls ) );
        }

        /**
         * Human-readable runtime diagnostics. Used by the Test Object Cache
         * Runtime button (§27.2) and the Diagnostics page.
         *
         * @return array{
         *   preferred:string,
         *   active:string,
         *   healthy:bool,
         *   chain:string[],
         *   prefix:string,
         *   dropin_loaded:bool,
         *   ext_object_cache:bool,
         * }
         */
        public function runtime_status() {
                return array(
                        'preferred'         => $this->preferred_backend_name(),
                        'active'            => $this->active_backend_name(),
                        'healthy'           => $this->backendHealthy(),
                        'chain'             => $this->backendNames(),
                        'prefix'            => $this->resolve_prefix(),
                        'dropin_loaded'     => defined( 'ULTIMATE_PERFORMANCE_DIR' ) && class_exists( '\\UltimatePerformance\\ObjectCache\\Manager', false ),
                        'ext_object_cache'  => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
                );
        }

        public function close() {
                $this->runtime->close();
                foreach ( $this->backends as $b ) {
                        try {
                                $b->close();
                        } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                                // close must never throw.
                        }
                }
        }

        // ------------------------------------------------------------------ misc

        /**
         * Feature map of the active backend (used at construction; test seam).
         *
         * @param array<string,bool> $features
         * @return void
         */
        public function set_features( $features ) {
                foreach ( (array) $features as $k => $v ) {
                        $this->features[ (string) $k ] = (bool) $v;
                }
        }

        private function normalize_group( $group ) {
                $group = (string) $group;
                return '' === $group ? 'default' : $group;
        }

        private function normalize_key( &$key ) {
                if ( is_string( $key ) && '' !== $key ) {
                        return true;
                }
                if ( is_int( $key ) ) {
                        $key = (string) $key;
                        return true;
                }
                return false;
        }

        private function normalize_ttl( $ttl ) {
                $ttl = (int) $ttl;
                return $ttl > 0 ? $ttl : 0;
        }

        /**
         * Runtime-layer slot group: scope-embedded, so blog isolation and global
         * groups both fall out of the naming.
         *
         * @param string $group Normalized group.
         * @return string
         */
        private function runtime_group( $group ) {
                $scope = $this->isGlobalGroup( $group ) ? 'global' : ( 'b' . $this->current_blog() );
                return $scope . '::' . $group;
        }

        private function is_non_persistent( $group ) {
                return $this->isNonPersistentGroup( $group );
        }

        // -------------------------------------------------- backend gated calls

        /**
         * Backend-chain guard. Returns the chain array, or NULL when there is
         * no persistent backend at all (runtime-only mode).
         *
         * @return array<int,Backend>|null
         */
        private function require_backend() {
                return empty( $this->backends ) ? null : $this->backends;
        }

        /**
         * @param int $i Backend index.
         * @return bool True when the index is inside its bounded recheck window.
         */
        private function is_unhealthy( $i ) {
                return microtime( true ) < ( $this->unhealthy[ $i ] ?? 0.0 );
        }

        /**
         * First non-degraded backend from $index onward (null when none).
         *
         * @param int $index Start index (by-ref: remembers the one used).
         * @return Backend|null
         */
        private function pick_backend( &$index ) {
                for ( $i = $index, $n = count( $this->backends ); $i < $n; ++$i ) {
                        if ( $this->is_unhealthy( $i ) ) {
                                continue;
                        }
                        // Promotion fencing (Phase K): a backend that failed earlier
                        // in this process is reconciled (flushed) ONCE before it
                        // regains read/write rights — stale data never re-enters.
                        if ( ! empty( $this->was_unhealthy[ $i ] ) && ! $this->promote( $i ) ) {
                                $this->mark_unhealthy( $i );
                                continue;
                        }
                        $index = $i;
                        return $this->backends[ $i ];
                }
                return null;
        }

        /**
         * Promotion fence (Phase K): gate a previously-failed backend back into
         * service. Health gate first, then reconcile = flush THAT backend only
         * (everything it may have missed is invalidated), then re-arm its chain
         * epoch. A failed reconcile keeps the backend fenced.
         *
         * @param int $i Backend index.
         * @return bool True when the backend is safe to use again.
         */
        private function promote( $i ) {
                $b = $this->backends[ $i ];
                try {
                        if ( ! $b->healthy() ) {
                                return false; // cannot prove health: stays fenced
                        }
                } catch ( \Throwable $e ) {
                        return false;
                }
                try {
                        $b->flush(); // reconcile: invalidate the whole outage window
                } catch ( \Throwable $e ) {
                        return false;
                }
                $this->was_unhealthy[ $i ] = false;
                ++$this->fences;
                $this->write_epoch( $b ); // best-effort re-arm
                return true;
        }

        /**
         * Next monotonic chain epoch: floor is the in-process counter, ceiling
         * comes from what healthy backends currently hold. Every flush form
         * bumps it exactly once and writes it to every flushed backend.
         *
         * @return int
         */
        private function next_epoch() {
                $max = $this->epoch;
                foreach ( $this->backends as $i => $b ) {
                        if ( $this->is_unhealthy( $i ) ) {
                                continue;
                        }
                        $ep = $this->read_epoch( $b );
                        if ( $ep > $max ) {
                                $max = $ep;
                        }
                }
                $this->epoch = $max + 1;
                return $this->epoch;
        }

        /**
         * @param Backend $b
         * @return int Current chain epoch held by the backend (0 when absent).
         */
        private function read_epoch( $b ) {
                try {
                        $found = false;
                        $v     = $b->get( self::EPOCH_KEY, self::EPOCH_GROUP, $found );
                        return $found ? (int) $v : 0;
                } catch ( \Throwable $e ) {
                        return 0;
                }
        }

        /**
         * Best-effort epoch write — an advisory safety net; never fails an op.
         *
         * @param Backend   $b
         * @param int|null  $epoch
         * @return void
         */
        private function write_epoch( $b, $epoch = null ) {
                try {
                        $b->set( self::EPOCH_KEY, (int) ( null === $epoch ? max( $this->epoch, 1 ) : $epoch ), 0, self::EPOCH_GROUP );
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }
        }

        private function backend_get( $key, $group, &$found ) {
                $found = false;
                if ( null === $this->require_backend() ) {
                        return false; // M1-D2
                }
                // Chain reads: try backends in priority order until a hit; each
                // backend's failure is isolated (degrade that one, try next).
                $index = 0;
                while ( null !== ( $b = $this->pick_backend( $index ) ) ) {
                        try {
                                $pk = $this->prefixed_key( $key, $this->runtime_group( $group ) );
                                $v = $b->get( $pk, $this->runtime_group( $group ), $found );
                                if ( $found ) {
                                        return $v;
                                }
                                // healthy miss on the FIRST backend is authoritative.
                                // Phase K fencing guarantees index 0 was reconciled
                                // (flushed) before regaining service, so its miss can
                                // never resurrect pre-outage data.
                                if ( 0 === $index ) {
                                        return false; // M1-D2
                                }
                                return false; // M1-D2: deeper backends — stop at first healthy miss too
                        } catch ( \Throwable $e ) {
                                $this->mark_unhealthy( $index );
                                ++$index; // fault isolation: try the next backend
                        }
                }
                return false; // M1-D2
        }

        private function backend_set( $key, $value, $ttl, $group ) {
                $rg = $this->runtime_group( $group );
                return $this->first_backend_call( 'set', array( $this->prefixed_key( $key, $rg ), $value, $ttl, $rg ) );
        }

        private function backend_add( $key, $value, $ttl, $group ) {
                $rg = $this->runtime_group( $group );
                return $this->first_backend_call( 'add', array( $this->prefixed_key( $key, $rg ), $value, $ttl, $rg ) );
        }

        private function backend_replace( $key, $value, $ttl, $group ) {
                $rg = $this->runtime_group( $group );
                return $this->first_backend_call( 'replace', array( $this->prefixed_key( $key, $rg ), $value, $ttl, $rg ) );
        }

        private function backend_delete( $key, $group ) {
                $rg = $this->runtime_group( $group );
                return $this->first_backend_call( 'delete', array( $this->prefixed_key( $key, $rg ), $rg ) );
        }

        /**
         * Apply a write to the first healthy backend; on its fault, degrade it
         * in isolation and fail over to the next.
         *
         * @return bool
         */
        private function first_backend_call( $op, $args ) {
                if ( null === $this->require_backend() ) {
                        return false;
                }
                $index = 0;
                while ( null !== ( $b = $this->pick_backend( $index ) ) ) {
                        try {
                                return (bool) call_user_func_array( array( $b, $op ), $args );
                        } catch ( \Throwable $e ) {
                                $this->mark_unhealthy( $index );
                                ++$index; // fail over to the next backend
                        }
                    }
                return false;
        }

        private function mark_unhealthy( $index = 0 ) {
                $this->unhealthy[ $index ]     = microtime( true ) + $this->recheck_window;
                $this->was_unhealthy[ $index ] = true; // fence: reconcile required before reuse
        }

        private function probe_backends() {
                $epochs = array();
                foreach ( $this->backends as $i => $b ) {
                        if ( 0 === $i && method_exists( $b, 'features' ) ) {
                                $this->set_features( $b->features() );
                        }
                        try {
                                $healthy = (bool) $b->healthy();
                        } catch ( \Throwable $e ) {
                                $healthy = false;
                        }
                        if ( ! $healthy ) {
                                $this->mark_unhealthy( $i );
                                continue;
                        }
                        $ep = $this->read_epoch( $b );
                        if ( $ep > 0 ) {
                                $epochs[ $i ] = $ep;
                        }
                }
                // Cross-process promotion fencing (best-effort): a healthy backend
                // whose chain epoch is BEHIND a peer's missed flushes while it was
                // unavailable — reconcile it before this fresh process trusts it.
                // Single-backend chains have no peer to compare against (documented
                // limitation: bounded by TTL + in-process fencing).
                if ( count( $epochs ) > 1 ) {
                        $max = max( $epochs );
                        foreach ( $epochs as $i => $ep ) {
                                if ( $ep < $max && $this->promote( $i ) ) {
                                        $epochs[ $i ] = $max; // converged
                                }
                        }
                }
                if ( ! empty( $epochs ) ) {
                        $this->epoch = max( $this->epoch, max( $epochs ) );
                }
        }
}
