<?php
/**
 * Settings — single structured option, strict sanitization.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central settings store. One autoloaded option. Fail-closed defaults.
 */
final class Settings {

        const OPTION = 'ultimate_performance_settings';

        /** @var Settings|null */
        private static $instance = null;

        /** @var array<string,mixed> */
        private $data;

        /** @var array|null What changed during the most recent save_from_admin() call. */
        private $last_save_summary = null;

        private function __construct() {
                $stored = get_option( self::OPTION, array() );
                if ( ! is_array( $stored ) ) {
                        $stored = array();
                }
                $this->data = array_merge( self::defaults(), $stored );
        }

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Defaults are the security posture: everything sensitive OFF.
         *
         * @return array<string,mixed>
         */
        public static function defaults() {
                return array(
                        'enabled'               => true,
                        'page_cache_enabled'    => true,    // ON by default — shared-hosting users get caching out-of-box
                        'object_cache_enabled'  => false,   // opt-in
                        'queue_enabled'         => true,
                        'invalidation_enabled'  => true,
                        'ttl'                   => 3600,
                        'swr_enabled'           => true,
                        'swr_grace'             => 300,
                        'query_allowlist'       => array( 'p', 'page_id', 'page', 'paged', 'feed', 'lang' ),
                        'query_unknown_policy'  => 'bypass', // bypass|strip|variant
                        'nginx'                 => array( 'origin' => '', 'listen' => '' ), // M6: admin-entered nginx integration inputs
                        'tracking_params_strip' => true,
                        'cookie_bypass_regex'   => 'wordpress_[a-f0-9]{32}|wordpress_logged_in_[a-f0-9]{32}|wordpress_sec_[a-f0-9]{32}|wp-postpass|comment_author|wp_woocommerce_session_|woocommerce_cart_hash|woocommerce_items_in_cart|woocommerce_recently_viewed|PHPSESSID|wp-settings-[0-9]+',
                        'bypass_paths'          => array( 'cart', 'checkout', 'my-account', 'wc-api', 'wishlist', 'compare', 'order-pay', 'order-received', 'orders', 'view-order', 'edit-address', 'lost-password', 'customer-logout' ),
                        'deny_extensions'       => array( 'php', 'json', 'xml', 'axd', 'aspx', 'jsp', 'cgi', 'phar', 'phtml', 'svg' ),
                        'variants'              => array( 'webp' => false, 'mobile' => false ),
                        'object_cache_chain'    => array( 'redis', 'memcached', 'apcu', 'sqlite', 'file' ),
                        'object_cache_prefix'   => '',         // auto-generated from domain when empty
                        'queue_backend'         => 'wp-cron', // safe default — never probe RabbitMQ on fresh install
                        'redis'                 => array(
                                'host'    => '127.0.0.1',
                                'port'    => 6379,
                                'auth'    => '',
                                'db'      => 0,
                                'tls'     => false,
                                'timeout' => 1.5,
                        ),
                        'memcached'             => array(
                                'host'    => '127.0.0.1',
                                'port'    => 11211,
                                'timeout' => 1.5,
                        ),
                        'sqlite'                => array( 'file' => '' ),
                        'amqp'                  => array(
                                'host'     => '127.0.0.1',
                                'port'     => 5672,
                                'user'     => 'guest',
                                'pass'     => '',
                                'vhost'    => '/',
                                'exchange' => 'ultimate-performance',
                        ),
                        'preload'               => array(
                                'concurrency' => 2,
                                'batch'       => 50,
                        ),
                        'debug_headers'         => false,
                        'apache_integration'    => false,
                        'lscache_mode'          => 'auto', // auto|native|generic
                        // BENCH-D7 (HARDEN-1): stampede protection settings.
                        'genlock_ttl'           => 30,        // seconds; flock auto-releases on process death.
                        'genlock_wait_budget_us' => 2500000,   // 2.5s; bounded waiter budget.
                        'herd_protection'       => true,       // master toggle for single-flight generation.
                        // HARDEN-5: PHP fallback mode (advanced-cache.php drop-in).
                        'php_fallback_enabled'  => true,    // install drop-in on activation for shared-hosting compat.
                );
        }

        /**
         * @param string $key Dot key e.g. 'redis.host'.
         * @param mixed  $default Fallback.
         * @return mixed
         */
        public function get( $key, $default = null ) {
                $node = $this->data;
                foreach ( explode( '.', $key ) as $part ) {
                        if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
                                return $default;
                        }
                        $node = $node[ $part ];
                }
                return $node;
        }

        /**
         * Raw access for admin form re-population (unsanitized source).
         *
         * @return array<string,mixed>
         */
        public function raw() {
                return $this->data;
        }

        /**
         * Form-ownership map. KEY = "section/subsection" (the uc[up_subsection]
         * hidden field every settings form emits); VALUE = the setting keys that
         * ONE form owns. A form may only mutate the keys listed under its own key.
         *
         * A key absent from the submitted form means PRESERVE, never "false" and
         * never "default" — because that absence may simply mean a DIFFERENT form
         * owns the field. This is the fix for the Redis-form-disables-Object-Cache
         * defect: 'object-cache/general' and 'object-cache/redis' are siblings,
         * and neither may touch the other's settings.
         *
         * @var array<string,array{booleans:string[],fields:string[],secrets:string[]}>
         */
        private static function form_ownership() {
                return array(
                        'page-cache/general'      => array(
                                'booleans' => array( 'enabled', 'page_cache_enabled', 'swr_enabled', 'php_fallback_enabled', 'herd_protection' ),
                                'fields'   => array( 'ttl', 'swr_grace', 'query_unknown_policy' ),
                                'secrets'  => array(),
                        ),
                        'object-cache/general'    => array(
                                'booleans' => array( 'object_cache_enabled' ),
                                'fields'   => array( 'object_cache_prefix' ),
                                'secrets'  => array(),
                        ),
                        'object-cache/redis'      => array(
                                'booleans' => array( 'redis.tls' ),
                                'fields'   => array( 'redis.host', 'redis.port', 'redis.db' ),
                                'secrets'  => array( 'redis.auth' ),
                        ),
                        'object-cache/memcached'  => array(
                                'booleans' => array(),
                                'fields'   => array( 'memcached.host', 'memcached.port' ),
                                'secrets'  => array(),
                        ),
                        'queue/general'           => array(
                                'booleans' => array( 'queue_enabled', 'invalidation_enabled' ),
                                'fields'   => array( 'queue_backend' ),
                                'secrets'  => array(),
                        ),
                        'queue/rabbitmq'          => array(
                                'booleans' => array(),
                                'fields'   => array( 'amqp.host', 'amqp.port', 'amqp.user', 'amqp.vhost', 'amqp.exchange' ),
                                'secrets'  => array( 'amqp.pass' ),
                        ),
                        'server-integration/general' => array(
                                'booleans' => array( 'apache_integration' ),
                                'fields'   => array( 'nginx.origin', 'nginx.listen', 'lscache_mode' ),
                                'secrets'  => array(),
                        ),
                        'advanced/general'        => array(
                                'booleans' => array( 'debug_headers', 'tracking_params_strip' ),
                                'fields'   => array( 'genlock_ttl', 'genlock_wait_budget_us', 'preload.concurrency', 'preload.batch', 'cookie_bypass_regex', 'bypass_paths', 'query_allowlist' ),
                                'secrets'  => array(),
                        ),
                );
        }

        /**
         * Keys a given ownership group is allowed to write, as dot-notation paths.
         * Used to test "is this field owned by the submitted form?".
         *
         * @param array $group One entry of form_ownership().
         * @return string[] Dot keys, including boolean and secret keys.
         */
        private static function owned_keys( $group ) {
                if ( ! is_array( $group ) ) {
                        return array();
                }
                $keys = array_merge(
                        isset( $group['booleans'] ) ? (array) $group['booleans'] : array(),
                        isset( $group['fields'] ) ? (array) $group['fields'] : array(),
                        isset( $group['secrets'] ) ? (array) $group['secrets'] : array()
                );
                return $keys;
        }

        /**
         * Resolve the ownership group for a submitted form. Falls back to the
         * legacy section-level map (and then to "no ownership" = preserve
         * everything) so older payloads that omit up_subsection do not silently
         * gain write access to keys they do not own.
         *
         * @param array<string,mixed> $input Raw submitted input.
         * @return array|null Null = no ownership declared (read-only save path).
         */
        private static function resolve_ownership( $input ) {
                $map = self::form_ownership();
                $section    = isset( $input['up_section'] ) ? sanitize_key( $input['up_section'] ) : '';
                $subsection = isset( $input['up_subsection'] ) ? sanitize_key( $input['up_subsection'] ) : '';

                if ( '' !== $subsection ) {
                        $key = $section . '/' . $subsection;
                        if ( ! isset( $map[ $key ] ) ) {
                                return null;
                        }
                        $group = $map[ $key ];
                        // Stamp the resolved section/subsection so the save summary
                        // can tell the admin notices WHICH form just saved. These
                        // keys are NOT used by the ownership/preservation logic —
                        // they are diagnostic metadata only.
                        if ( is_array( $group ) ) {
                                $group['_section']    = $section;
                                $group['_subsection'] = $subsection;
                        }
                        return $group;
                }
                // Legacy payload (no subsection): treat as section-level owner of the
                // booleans the old map granted, but NEVER a block/secret owner — a
                // legacy form cannot be trusted to own nested connection secrets.
                $section_bools = array(
                        'page-cache'         => array( 'enabled', 'page_cache_enabled', 'swr_enabled' ),
                        'object-cache'       => array( 'object_cache_enabled' ),
                        'queue'              => array( 'queue_enabled', 'invalidation_enabled' ),
                        'advanced'           => array( 'debug_headers' ),
                        'server-integration' => array(),
                        'dashboard'          => array(),
                );
                if ( '' === $section || ! isset( $section_bools[ $section ] ) ) {
                        return null;
                }
                return array(
                        '_section'    => $section,
                        '_subsection' => '',
                        'booleans'    => $section_bools[ $section ],
                        'fields'      => array(),
                        'secrets'     => array(),
                );
        }

        /**
         * Explicit "clear the stored secret" flag a form may submit. Only this
         * explicit action may erase a stored password — a blank field never does.
         *
         * @param array  $blk    The submitted service block (e.g. $input['redis']).
         * @param string $secret The secret key inside the block ('auth'/'pass').
         * @return bool True when the user explicitly asked to clear.
         */
        private static function secret_clear_requested( $blk, $secret ) {
                if ( ! is_array( $blk ) ) {
                        return false;
                }
                // uc[redis][auth_clear] / uc[amqp][pass_clear] — checkbox semantics.
                $flag = $secret . '_clear';
                return ! empty( $blk[ $flag ] );
        }

        /**
         * Resolve a secret under the preserve/replace/clear contract:
         *   existing + field omitted      → preserve
         *   existing + field blank        → preserve
         *   existing + non-empty new      → replace
         *   none existing + blank         → remain empty
         *   explicit clear flag           → clear to ''
         * The stored value is NEVER a placeholder ('********', '__KEEP__').
         *
         * @param mixed  $blk     Submitted service block.
         * @param string $secret  Secret key ('auth' for redis, 'pass' for amqp).
         * @param mixed  $current Currently stored secret value.
         * @param bool   $owned   Whether the submitting form owns this secret.
         * @return string
         */
        private static function resolve_secret( $blk, $secret, $current, $owned ) {
                $current = is_string( $current ) ? $current : '';
                if ( ! $owned ) {
                        return $current; // not this form's secret → preserve
                }
                if ( self::secret_clear_requested( $blk, $secret ) ) {
                        return ''; // explicit clear only
                }
                if ( ! is_array( $blk ) || ! isset( $blk[ $secret ] ) ) {
                        return $current; // omitted → preserve
                }
                $new = trim( (string) $blk[ $secret ] );
                if ( '' === $new ) {
                        return $current; // blank → preserve, NEVER clear
                }
                return $new; // replace
        }

        /**
         * Read a dot-notation path from the settings array. Nested keys
         * ('redis.auth') resolve against the stored nested block.
         *
         * @param array  $data Settings array.
         * @param string $key  Dot key.
         * @return mixed Existing value, or null when unset.
         */
        private static function existing( $data, $key ) {
                if ( ! is_array( $data ) ) {
                        return null;
                }
                $node = $data;
                foreach ( explode( '.', $key ) as $part ) {
                        if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
                                return null;
                        }
                        $node = $node[ $part ];
                }
                return $node;
        }

        /**
         * Is a setting key present in the raw submitted input? Admin forms submit
         * BOTH shapes and both must be accepted:
         *   - flat dotted name:  uc[preload.concurrency] → $input['preload.concurrency']
         *   - nested block:      uc[redis][host]         → $input['redis']['host']
         *
         * @param array  $input Raw submitted input.
         * @param string $key   Dot key.
         * @return bool
         */
        private static function input_has( $input, $key ) {
                if ( ! is_array( $input ) ) {
                        return false;
                }
                if ( array_key_exists( $key, $input ) ) {
                        return true; // flat dotted name (uc[preload.concurrency])
                }
                if ( strpos( $key, '.' ) === false ) {
                        return false;
                }
                $node = $input;
                foreach ( explode( '.', $key ) as $p ) {
                        if ( ! is_array( $node ) || ! array_key_exists( $p, $node ) ) {
                                return false;
                        }
                        $node = $node[ $p ];
                }
                return true;
        }

        /**
         * Fetch a submitted scalar value for a setting key. Accepts the same two
         * payload shapes as input_has(). Null when absent.
         *
         * @param array  $input Raw submitted input.
         * @param string $key   Dot key.
         * @return mixed
         */
        private static function input_get( $input, $key ) {
                if ( ! is_array( $input ) ) {
                        return null;
                }
                if ( array_key_exists( $key, $input ) ) {
                        return $input[ $key ]; // flat dotted name
                }
                if ( strpos( $key, '.' ) === false ) {
                        return null;
                }
                $parts = explode( '.', $key );
                $node  = $input;
                foreach ( $parts as $p ) {
                        if ( ! is_array( $node ) || ! array_key_exists( $p, $node ) ) {
                                return null;
                        }
                        $node = $node[ $p ];
                }
                return $node;
        }

        /**
         * Write a value at a dot path into the settings array (max depth 2).
         *
         * @param array  $d    Settings array (by reference).
         * @param string $key  Dot key.
         * @param mixed  $val  Value.
         */
        private static function assign( &$d, $key, $val ) {
                if ( strpos( $key, '.' ) === false ) {
                        $d[ $key ] = $val;
                        return;
                }
                list( $top, $sub ) = explode( '.', $key, 2 );
                if ( ! isset( $d[ $top ] ) || ! is_array( $d[ $top ] ) ) {
                        $d[ $top ] = array();
                }
                $d[ $top ][ $sub ] = $val;
        }

        /**
         * Enumerate every setting key that may ever be written, grouped by kind,
         * so the "preserve everything else" pass has a complete whitelist.
         *
         * @return array{booleans:string[],ints:string[],enums:string[],secrets:string[]}
         */
        private static function all_known_keys() {
                return array(
                        'booleans' => array( 'enabled', 'page_cache_enabled', 'object_cache_enabled', 'queue_enabled', 'invalidation_enabled', 'swr_enabled', 'tracking_params_strip', 'debug_headers', 'apache_integration', 'php_fallback_enabled', 'herd_protection', 'redis.tls' ),
                        'ints'     => array( 'ttl', 'swr_grace', 'genlock_ttl', 'genlock_wait_budget_us', 'preload.concurrency', 'preload.batch', 'redis.port', 'redis.db', 'memcached.port', 'amqp.port' ),
                        'enums'    => array( 'query_unknown_policy', 'queue_backend', 'lscache_mode' ),
                        'secrets'  => array( 'redis.auth', 'amqp.pass' ),
                        'strings'  => array( 'object_cache_prefix', 'cookie_bypass_regex', 'redis.host', 'memcached.host', 'amqp.host', 'amqp.user', 'amqp.vhost', 'amqp.exchange', 'nginx.origin', 'nginx.listen' ),
                        'lists'    => array( 'query_allowlist', 'bypass_paths' ),
                );
        }

        /**
         * Validate + persist a full settings array from admin input.
         *
         * Ownership model: each settings FORM declares its subsection. Only the
         * keys owned by that subsection may change. Everything else is preserved
         * byte-for-byte — an absent field means "another form owns it", never
         * "false" and never "reset to default".
         *
         * @param array<string,mixed> $input Raw $_POST-ish.
         * @return array<string,string> map of field→error (empty = ok).
         */
        public function save_from_admin( $input ) {
                $errors = array();
                $d      = $this->data;
                $input  = is_array( $input ) ? $input : array();

                $owner = self::resolve_ownership( $input );
                $owned = self::owned_keys( $owner );

                // ---- Connection blocks (redis / memcached / amqp / nginx). ----
                //
                // Runs BEFORE the typed-scalar passes: every block field is also a
                // typed scalar, and the typed passes below own the final coercion
                // (int/bool/string). Ordering the block pass first means the typed
                // passes are authoritative, and the block walk only contributes the
                // secret-resolution step.
                $block_defs = array(
                        'redis'     => array(
                                'host'   => 'redis.host',
                                'port'   => 'redis.port',
                                'db'     => 'redis.db',
                                'tls'    => 'redis.tls',
                                'secret' => array( 'key' => 'auth', 'stored' => 'redis.auth' ),
                        ),
                        'memcached' => array(
                                'host'   => 'memcached.host',
                                'port'   => 'memcached.port',
                        ),
                        'amqp'      => array(
                                'host'   => 'amqp.host',
                                'port'   => 'amqp.port',
                                'user'   => 'amqp.user',
                                'vhost'  => 'amqp.vhost',
                                'exchange' => 'amqp.exchange',
                                'secret' => array( 'key' => 'pass', 'stored' => 'amqp.pass' ),
                        ),
                        'nginx'     => array(
                                'host'   => 'nginx.origin', // IP:port literal — capped at 21 below
                                'listen' => 'nginx.listen',
                        ),
                );
                $nginx_caps = array( 'nginx.origin' => 21, 'nginx.listen' => 21 );
                foreach ( $block_defs as $svc => $def ) {
                        $blk = isset( $input[ $svc ] ) && is_array( $input[ $svc ] ) ? $input[ $svc ] : array();
                        foreach ( $def as $field => $owned_key ) {
                                if ( 'secret' === $field ) {
                                        continue; // handled below
                                }
                                if ( ! in_array( $owned_key, $owned, true ) ) {
                                        continue; // this form does not own the field → preserve
                                }
                                if ( ! self::input_has( $input, $owned_key ) ) {
                                        continue; // absent → preserve
                                }
                                $val = self::input_get( $input, $owned_key );
                                $cap = isset( $nginx_caps[ $owned_key ] ) ? $nginx_caps[ $owned_key ] : 255;
                                // Integer-valued connection fields stay integers.
                                if ( in_array( $owned_key, array( 'redis.port', 'memcached.port', 'amqp.port' ), true ) ) {
                                        self::assign( $d, $owned_key, min( max( 1, absint( $val ) ), 65535 ) );
                                        continue;
                                }
                                self::assign( $d, $owned_key, substr( sanitize_text_field( (string) $val ), 0, $cap ) );
                        }
                        // Secret under the preserve/replace/clear contract.
                        if ( isset( $def['secret'] ) ) {
                                $stored_key = $def['secret']['stored'];
                                $secret_key = $def['secret']['key'];
                                $is_owned   = in_array( $stored_key, $owned, true );
                                self::assign( $d, $stored_key, self::resolve_secret( $blk, $secret_key, self::existing( $d, $stored_key ), $is_owned ) );
                        }
                }

                // ---- Typed scalars: only keys OWNED by the submitted form may change. ----
                //
                // Rule: field present AND owned → validate + write (final coercion here).
                //       field absent, or not owned → preserve existing (never default).
                $known = self::all_known_keys();

                // Bounded integers.
                $int_ranges = array(
                        'ttl'                   => array( 30, MONTH_IN_SECONDS ),
                        'swr_grace'             => array( 0, DAY_IN_SECONDS ),
                        'genlock_ttl'           => array( 1, 300 ),
                        'genlock_wait_budget_us'=> array( 0, 10000000 ),
                        'preload.concurrency'   => array( 1, 16 ),
                        'preload.batch'         => array( 1, 1000 ),
                        'redis.port'            => array( 1, 65535 ),
                        'redis.db'              => array( 0, 15 ),
                        'memcached.port'        => array( 1, 65535 ),
                        'amqp.port'             => array( 1, 65535 ),
                );
                foreach ( $int_ranges as $key => $range ) {
                        if ( ! in_array( $key, $owned, true ) ) {
                                continue; // not this form's field → preserve
                        }
                        if ( ! self::input_has( $input, $key ) ) {
                                continue; // absent → preserve (never the old default fallback)
                        }
                        $val = absint( self::input_get( $input, $key ) );
                        if ( $val < $range[0] || $val > $range[1] ) {
                                $errors[ $key ] = sprintf( 'must be between %d and %d', $range[0], $range[1] );
                                continue;
                        }
                        self::assign( $d, $key, $val );
                }

                // Enums.
                $enum_sets = array(
                        'query_unknown_policy' => array( 'bypass', 'strip', 'variant' ),
                        'queue_backend'        => array( 'auto', 'rabbitmq', 'action-scheduler', 'wp-cron', 'local', 'sync' ),
                        'lscache_mode'         => array( 'auto', 'native', 'generic' ),
                );
                foreach ( $enum_sets as $key => $allowed ) {
                        if ( ! in_array( $key, $owned, true ) ) {
                                continue; // not this form's field → preserve
                        }
                        // Both payload shapes are accepted: flat uc[queue_backend] and
                        // nested uc[nginx][lscache_mode]. Absent → preserve.
                        if ( ! self::input_has( $input, $key ) ) {
                                continue;
                        }
                        $val = sanitize_key( self::input_get( $input, $key ) );
                        if ( in_array( $val, $allowed, true ) ) {
                                self::assign( $d, $key, $val );
                        } else {
                                $errors[ $key ] = 'invalid value';
                        }
                }

                // Booleans (checkbox semantics apply ONLY to owned fields).
                foreach ( $known['booleans'] as $b ) {
                        if ( ! in_array( $b, $owned, true ) ) {
                                continue; // absent checkbox owned by another form → PRESERVE
                        }
                        // Owned checkbox: absent OR empty = false; present truthy = true.
                        // (An unchecked checkbox is absent from the payload; a checkbox
                        // rendered but left empty is also false. Only a checked box —
                        // value '1' — is true.)
                        self::assign( $d, $b, ! empty( self::input_get( $input, $b ) ) );
                        }

                        // Free strings (size-capped, sanitized).
                $string_caps = array(
                        'object_cache_prefix' => 64,
                        'redis.host'          => 255,
                        'memcached.host'      => 255,
                        'amqp.host'           => 255,
                        'amqp.user'           => 128,
                        'amqp.vhost'          => 128,
                        'amqp.exchange'       => 128,
                        'nginx.origin'        => 21,
                        'nginx.listen'        => 21,
                );
                foreach ( $string_caps as $key => $cap ) {
                        if ( ! in_array( $key, $owned, true ) || ! self::input_has( $input, $key ) ) {
                                continue; // not owned / absent → preserve
                        }
                        $val = substr( sanitize_text_field( (string) self::input_get( $input, $key ) ), 0, $cap );
                        self::assign( $d, $key, $val );
                }

                // Object cache prefix: structural validation (charset) on top of the
                // raw string write above.
                if ( in_array( 'object_cache_prefix', $owned, true ) ) {
                        $prefix = trim( (string) self::input_get( $input, 'object_cache_prefix' ) );
                        if ( '' === $prefix ) {
                                $d['object_cache_prefix'] = ''; // empty = auto-generate from domain
                        } elseif ( ! preg_match( '/^[a-z0-9\-_:]{1,64}$/', $prefix ) ) {
                                $errors['object_cache_prefix'] = 'must be 1-64 chars, only a-z 0-9 - _ :';
                        } else {
                                $d['object_cache_prefix'] = $prefix;
                        }
                }

                // Regex — validate it compiles; on failure keep old + error.
                if ( in_array( 'cookie_bypass_regex', $owned, true )
                        && self::input_has( $input, 'cookie_bypass_regex' ) ) {
                        $rx = trim( (string) self::input_get( $input, 'cookie_bypass_regex' ) );
                        if ( '' === $rx ) {
                                $d['cookie_bypass_regex'] = '';
                        } else {
                                $ok = (bool) @preg_match( '~(' . str_replace( '~', '\\~', $rx ) . ')~', '' );
                                if ( false === $ok ) {
                                        $errors['cookie_bypass_regex'] = 'invalid PCRE';
                                } else {
                                        $d['cookie_bypass_regex'] = $rx;
                                }
                        }
                }

                // Comma/space lists.
                $list_maps = array(
                        'query_allowlist' => 24,
                        'bypass_paths'    => 64,
                );
                foreach ( $list_maps as $key => $limit ) {
                        if ( ! in_array( $key, $owned, true ) || ! self::input_has( $input, $key ) ) {
                                continue; // not owned / absent → preserve
                        }
                        $raw = self::input_get( $input, $key );
                        if ( ! is_string( $raw ) ) {
                                continue;
                        }
                        $parts = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', $raw ) ) );
                        self::assign( $d, $key, array_values( array_unique( array_slice( $parts, 0, $limit ) ) ) );
                }

                if ( empty( $errors ) ) {
                        // Capture a "what changed" summary for the admin notices.
                        // Critical for the secrets (redis.auth, amqp.pass): surfaces
                        // "preserved (no change)" so the user can SEE that a blank
                        // password field did NOT delete the stored password.
                        $this->last_save_summary = self::build_save_summary( $this->data, $d, $owner );
                        update_option( self::OPTION, $d, true );
                        $this->data = $d;
                        do_action( 'ultimate_performance_settings_saved', $d );
                }
                return $errors;
                }

        /**
         * Compare previous vs new data and emit a human-readable summary of
         * what changed. Secrets are reported without leaking their values:
         * only the operation (preserved / replaced / cleared) is shown.
         *
         * @param array      $prev  Data BEFORE the save.
         * @param array      $next  Data AFTER the save.
         * @param array|null $owner Form-ownership group (so we know which form
         *                          submitted the save and only report keys
         *                          owned by it).
         * @return array{section:string,subsection:string,changes:array<string,string>} Summary.
         */
        private static function build_save_summary( $prev, $next, $owner ) {
                $section    = is_array( $owner ) && isset( $owner['_section'] )    ? $owner['_section']    : '';
                $subsection = is_array( $owner ) && isset( $owner['_subsection'] ) ? $owner['_subsection'] : '';
                $summary    = array(
                        'section'    => $section,
                        'subsection' => $subsection,
                        'changes'    => array(),
                );
                if ( ! is_array( $prev ) || ! is_array( $next ) ) {
                        return $summary;
                }
                // The dot-path keys we care about (secrets + connection fields).
                $watch = array(
                        'redis.host'    => array( 'label' => 'Redis host',     'secret' => false ),
                        'redis.port'    => array( 'label' => 'Redis port',     'secret' => false ),
                        'redis.db'      => array( 'label' => 'Redis database', 'secret' => false ),
                        'redis.tls'     => array( 'label' => 'Redis TLS',      'secret' => false ),
                        'redis.auth'    => array( 'label' => 'Redis password', 'secret' => true  ),
                        'memcached.host'=> array( 'label' => 'Memcached host', 'secret' => false ),
                        'memcached.port'=> array( 'label' => 'Memcached port', 'secret' => false ),
                        'amqp.host'     => array( 'label' => 'RabbitMQ host',    'secret' => false ),
                        'amqp.port'     => array( 'label' => 'RabbitMQ port',    'secret' => false ),
                        'amqp.user'     => array( 'label' => 'RabbitMQ user',    'secret' => false ),
                        'amqp.vhost'    => array( 'label' => 'RabbitMQ vhost',   'secret' => false ),
                        'amqp.exchange' => array( 'label' => 'RabbitMQ exchange', 'secret' => false ),
                        'amqp.pass'     => array( 'label' => 'RabbitMQ password', 'secret' => true  ),
                );
                foreach ( $watch as $key => $meta ) {
                        $old = self::existing( $prev, $key );
                        $new = self::existing( $next, $key );
                        if ( $meta['secret'] ) {
                                // For secrets, only report the OPERATION, never the value.
                                $old_set = is_string( $old ) && '' !== $old;
                                $new_set = is_string( $new ) && '' !== $new;
                                if ( ! $old_set && ! $new_set ) {
                                    // unchanged empty — skip (too noisy)
                                } elseif ( $old_set && $new_set ) {
                                        $summary['changes'][ $key ] = 'preserved';
                                } elseif ( ! $old_set && $new_set ) {
                                        $summary['changes'][ $key ] = 'set';
                                } elseif ( $old_set && ! $new_set ) {
                                        $summary['changes'][ $key ] = 'cleared';
                                }
                        } else {
                                $old_s = null === $old ? '' : (string) $old;
                                $new_s = null === $new ? '' : (string) $new;
                                if ( $old_s !== $new_s ) {
                                        $summary['changes'][ $key ] = 'changed';
                                }
                        }
                }
                return $summary;
        }

        /**
         * Public accessor: what changed during the most recent save_from_admin()?
         *
         * Returns an array with keys 'section', 'subsection', 'changes'.
         * 'changes' is a map of dot-path → operation ('preserved' | 'set' |
         * 'cleared' | 'changed'). Empty array when no save has happened yet
         * in this request.
         *
         * @return array{section:string,subsection:string,changes:array<string,string>}
         */
        public function last_save_summary() {
                if ( ! is_array( $this->last_save_summary ) ) {
                        return array( 'section' => '', 'subsection' => '', 'changes' => array() );
                }
                return $this->last_save_summary;
        }

        /**
         * Reset to defaults (explicit action only).
         */
        public function reset() {
                delete_option( self::OPTION );
                $this->data = self::defaults();
        }
}
