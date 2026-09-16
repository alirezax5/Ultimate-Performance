<?php
/**
 * object-cache.php drop-in ownership manager (Phase I).
 *
 * State machine for wp-content/object-cache.php:
 *
 *   ABSENT  → install (temp file + atomic rename, 0640)
 *   OURS    → atomic in-place update (temp + rename over; never truncate)
 *   FOREIGN → REFUSE (file left byte-identical; status recorded; never overwritten)
 *
 * Ownership is proven by an exact header marker — a file that merely mentions
 * the plugin inside its body is NOT ours. Deactivation removes the drop-in
 * only when the marker proves ownership (Installer::deactivate delegates).
 *
 * @package UltimatePerformance\ObjectCache
 */

namespace UltimatePerformance\ObjectCache;

use UltimatePerformance\Core\SafeFs;

defined( 'ABSPATH' ) || exit;

final class Dropin {

        const DROPIN_VERSION   = '2';
        const MARKER           = '<?php // Ultimate Performance object cache drop-in v';
        const STATUS_TRANSIENT = 'up_oc_dropin_status';

        /** @var string|null override for the plugin directory (tests) */
        private $plugin_dir;

        /**
         * @param string|null $plugin_dir Absolute plugin dir with trailing slash; null = derive from ULTIMATE_PERFORMANCE_DIR.
         */
        public function __construct( $plugin_dir = null ) {
                $this->plugin_dir = null !== $plugin_dir
                        ? rtrim( $plugin_dir, '/' ) . '/'
                        : ( defined( 'ULTIMATE_PERFORMANCE_DIR' ) ? ULTIMATE_PERFORMANCE_DIR : '' );
        }

        /**
         * Ownership state of a drop-in path.
         *
         * @param string $wp_content_dir wp-content directory.
         * @return string 'absent'|'ours'|'foreign'
         */
        public function state( $wp_content_dir ) {
                $path = $wp_content_dir . '/object-cache.php';
                if ( ! file_exists( $path ) ) {
                        return 'absent';
                }
                $head = (string) @file_get_contents( $path, false, null, 0, strlen( self::MARKER ) + 4 );
                if ( 0 === strpos( $head, self::MARKER ) ) {
                        return 'ours';
                }
                return 'foreign';
        }

        /**
         * Ensure the drop-in reflects the current generator output.
         * Foreign drop-ins are never touched (fail-closed).
         *
         * @param string $wp_content_dir wp-content directory.
         * @return array{state:string,action:string,version?:string} status
         */
        public function ensure( $wp_content_dir ) {
                $state  = $this->state( $wp_content_dir );
                $action = 'none';
                switch ( $state ) {
                        case 'absent':
                                $ok     = $this->write_atomic( $wp_content_dir, $this->generate() );
                                $action = $ok ? 'installed' : 'install-failed';
                                $state  = $ok ? 'ours' : 'absent';
                                break;
                        case 'ours':
                                $path   = $wp_content_dir . '/object-cache.php';
                                $have   = (string) file_get_contents( $path );
                                $want   = $this->generate();
                                if ( $have !== $want ) {
                                        $ok     = $this->write_atomic( $wp_content_dir, $want );
                                        $action = $ok ? 'updated' : 'update-failed';
                                }
                                break;
                        case 'foreign':
                                $action = 'refused-foreign';
                                break;
                }
                $status = array( 'state' => $state, 'action' => $action, 'time' => time() );
                $this->record_status( $status );
                return $status;
        }

        /**
         * Remove OUR drop-in (deactivation/uninstall). Foreign files are never removed.
         *
         * @param string $wp_content_dir wp-content directory.
         * @return bool True when removed; false when absent, foreign, or unlink failed.
         */
        public function remove( $wp_content_dir ) {
                if ( 'ours' !== $this->state( $wp_content_dir ) ) {
                        return false;
                }
                return @unlink( $wp_content_dir . '/object-cache.php' );
        }

        /**
         * Version marker parsed from OUR drop-in; null when absent/foreign.
         *
         * @param string $wp_content_dir wp-content directory.
         * @return string|null
         */
        public function installed_version( $wp_content_dir ) {
                if ( 'ours' !== $this->state( $wp_content_dir ) ) {
                        return null;
                }
                $head = (string) file_get_contents( $wp_content_dir . '/object-cache.php', false, null, 0, 120 );
                if ( preg_match( '/drop-in v(\d+)/', $head, $m ) ) {
                        return $m[1];
                }
                return null;
        }

        /**
         * Generate the drop-in source. The generated file is a THIN bootstrap:
         * it never requires the plugin entry file (safe at any WP load stage),
         * registers the autoloader, and degrades to an in-memory fallback when
         * the plugin directory is missing — WordPress must never fatal.
         *
         * M1 (real-WP matrix) DEFECT FIX: the drop-in must define the COMPLETE
         * wp_cache_* API surface INCLUDING wp_cache_init(). WordPress only skips
         * wp-includes/cache.php when wp_cache_init() exists at object-cache.php
         * include time; core cache.php declares its functions UNGUARDED since
         * WP 6.x, so any missing surface function collides into a fatal
         * "Cannot redeclare function wp_cache_*()" on every real-WP boot.
         *
         * @return string
         */
        public function generate() {
                $plugin_dir = $this->plugin_dir;
                $version    = self::DROPIN_VERSION;

                return <<<PHP
<?php // Ultimate Performance object cache drop-in v{$version} (generated - do not edit; ownership marker)
/**
 * Ultimate Performance — object cache drop-in. Thin bootstrap: proxies the
 * wp_cache_* API to the plugin's ObjectCache\\Manager. When the plugin
 * directory is missing the file degrades to an in-memory runtime cache so
 * WordPress keeps working. Ownership marker in the first line.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

\$up_oc_plugin_dir = {$this->exportPath( $plugin_dir )};

if ( ! defined( 'ULTIMATE_PERFORMANCE_DIR' ) && is_readable( \$up_oc_plugin_dir . 'src/Core/Autoloader.php' ) ) {
        define( 'ULTIMATE_PERFORMANCE_DIR', \$up_oc_plugin_dir );
}
if ( defined( 'ULTIMATE_PERFORMANCE_DIR' ) && is_readable( ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php' ) ) {
        require_once ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
        \\UltimatePerformance\\Core\\Autoloader::register();
}

if ( ! class_exists( 'UP_Object_Cache_Fallback' ) ) {
        /**
         * Minimal in-memory cache used ONLY when the plugin cannot be loaded.
         */
        final class UP_Object_Cache_Fallback {
                public \$data = array();
                public function get( \$k, \$g, \$force = false, &\$f = null ) {
                        \$f = isset( \$this->data[ \$g ][ \$k ] );
                        return \$f ? \$this->data[ \$g ][ \$k ] : false; // M1-D2: WP contract — miss = false
                }
                public function getMultiple( \$ks, \$g ) {
                        \$o = array();
                        foreach ( (array) \$ks as \$k ) { \$f = false; \$o[ \$k ] = \$this->get( \$k, \$g, \$f ); } // M1-D2: WP shape
                        return \$o;
                }
                public function set( \$k, \$v, \$g ) { \$this->data[ \$g ][ \$k ] = \$v; return true; }
                public function add( \$k, \$v, \$g ) { if ( isset( \$this->data[ \$g ][ \$k ] ) ) { return false; } return \$this->set( \$k, \$v, \$g ); }
                public function replace( \$k, \$v, \$g ) { if ( ! isset( \$this->data[ \$g ][ \$k ] ) ) { return false; } return \$this->set( \$k, \$v, \$g ); }
                public function delete( \$k, \$g ) { if ( ! isset( \$this->data[ \$g ][ \$k ] ) ) { return false; } unset( \$this->data[ \$g ][ \$k ] ); return true; }
                public function incr( \$k, \$n, \$g ) { if ( ! isset( \$this->data[ \$g ][ \$k ] ) || ! is_int( \$this->data[ \$g ][ \$k ] ) ) { return false; } \$this->data[ \$g ][ \$k ] += \$n; return \$this->data[ \$g ][ \$k ]; }
                public function decr( \$k, \$n, \$g ) { return \$this->incr( \$k, -\$n, \$g ); }
                public function flush() { \$this->data = array(); return true; }
                public function flushGroup( \$g ) { unset( \$this->data[ \$g ] ); return true; }
                public function flushRuntime() { return \$this->flush(); }
                public function switch_blog( \$b ) { return true; }
                public function restore_blog() { return true; }
                public function supports( \$f ) { return in_array( \$f, array( 'flush_runtime', 'group' ), true ); }
                public function addGlobalGroups( \$x ) {}
                public function addNonPersistentGroups( \$x ) {}
                public function close() {}
        }
}

\$GLOBALS['up_object_cache'] = null;
if ( class_exists( '\\\\UltimatePerformance\\\\ObjectCache\\\\Manager' ) ) {
        try {
                \$GLOBALS['up_object_cache'] = \\UltimatePerformance\\ObjectCache\\Manager::instance();
        } catch ( \\Throwable \$e ) {
                \$GLOBALS['up_object_cache'] = null; // fail closed to the fallback
        }
}
if ( ! \$GLOBALS['up_object_cache'] instanceof \\UltimatePerformance\\ObjectCache\\Manager ) {
        \$GLOBALS['up_object_cache'] = new UP_Object_Cache_Fallback();
}

function up_oc() { return \$GLOBALS['up_object_cache']; }

// ---- COMPLETE wp_cache_* surface (order mirrors wp-includes/cache.php) ------
// wp_cache_init MUST exist: WordPress calls wp_using_ext_object_cache( true )
// only when this function is defined at object-cache.php include time. Without
// it, core cache.php loads and its unguarded declarations fatal.
if ( ! function_exists( 'wp_cache_init' ) ) {
        function wp_cache_init() {
                if ( ! isset( \$GLOBALS['up_object_cache'] ) || ! \$GLOBALS['up_object_cache'] instanceof \UltimatePerformance\ObjectCache\Manager ) {
                        try {
                                \$GLOBALS['up_object_cache'] = \UltimatePerformance\ObjectCache\Manager::instance();
                        } catch ( \Throwable \$e ) {
                                \$GLOBALS['up_object_cache'] = null; // fail closed to the fallback
                        }
                        if ( ! \$GLOBALS['up_object_cache'] instanceof \UltimatePerformance\ObjectCache\Manager ) {
                                \$GLOBALS['up_object_cache'] = new UP_Object_Cache_Fallback();
                        }
                } elseif ( ! \$GLOBALS['up_object_cache']->has_persistent_backend() ) {
                        // BOOT: the drop-in loads before get_option() exists, so the
                        // global manager was built runtime-only. wp_cache_init() runs
                        // later in the WP lifecycle - kick the in-place upgrade so
                        // wp_cache_*() calls reach the persistent backend. Object
                        // identity is preserved (same manager, upgraded in place).
                        try {
                        \UltimatePerformance\ObjectCache\Manager::instance();
                        } catch ( \Throwable \$e ) { // fail closed to runtime-only
                        }

                }
        }
}
if ( ! function_exists( 'wp_cache_add' ) ) {
        function wp_cache_add( \$key, \$data, \$group = 'default', \$ttl = 0 ) { return up_oc()->add( \$key, \$data, \$group, \$ttl ); }
}
if ( ! function_exists( 'wp_cache_add_multiple' ) ) {
        function wp_cache_add_multiple( \$data, \$group = 'default', \$ttl = 0 ) {
                \$out = array();
                foreach ( (array) \$data as \$key => \$value ) { \$out[ \$key ] = up_oc()->add( \$key, \$value, \$group, \$ttl ); }
                return \$out;
        }
}
if ( ! function_exists( 'wp_cache_replace' ) ) {
        function wp_cache_replace( \$key, \$data, \$group = 'default', \$ttl = 0 ) { return up_oc()->replace( \$key, \$data, \$group, \$ttl ); }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
        function wp_cache_set( \$key, \$data, \$group = 'default', \$ttl = 0 ) { return up_oc()->set( \$key, \$data, \$group, \$ttl ); }
}
if ( ! function_exists( 'wp_cache_set_multiple' ) ) {
        function wp_cache_set_multiple( \$data, \$group = 'default', \$ttl = 0 ) {
                \$out = array();
                foreach ( (array) \$data as \$key => \$value ) { \$out[ \$key ] = up_oc()->set( \$key, \$value, \$group, \$ttl ); }
                return \$out;
        }
}
if ( ! function_exists( 'wp_cache_get' ) ) {
        function wp_cache_get( \$key, \$group = 'default', \$force = false, &\$found = null ) { return up_oc()->get( \$key, \$group, \$force, \$found ); }
}
if ( ! function_exists( 'wp_cache_get_multiple' ) ) {
        function wp_cache_get_multiple( \$keys, \$group = 'default' ) { return up_oc()->getMultiple( \$keys, \$group ); }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
        function wp_cache_delete( \$key, \$group = 'default' ) { return up_oc()->delete( \$key, \$group ); }
}
if ( ! function_exists( 'wp_cache_delete_multiple' ) ) {
        function wp_cache_delete_multiple( \$keys, \$group = 'default' ) {
                \$out = array();
                foreach ( (array) \$keys as \$key ) { \$out[ \$key ] = up_oc()->delete( \$key, \$group ); }
                return \$out;
        }
}
if ( ! function_exists( 'wp_cache_incr' ) ) {
        function wp_cache_incr( \$key, \$n = 1, \$group = 'default' ) { return up_oc()->incr( \$key, \$n, \$group ); }
}
if ( ! function_exists( 'wp_cache_decr' ) ) {
        function wp_cache_decr( \$key, \$n = 1, \$group = 'default' ) { return up_oc()->decr( \$key, \$n, \$group ); }
}
if ( ! function_exists( 'wp_cache_flush' ) ) {
        function wp_cache_flush() { return up_oc()->flush(); }
}
if ( ! function_exists( 'wp_cache_flush_runtime' ) ) {
        function wp_cache_flush_runtime() { return up_oc()->flushRuntime(); }
}
if ( ! function_exists( 'wp_cache_flush_group' ) ) {
        function wp_cache_flush_group( \$group ) { return up_oc()->flushGroup( \$group ); }
}
if ( ! function_exists( 'wp_cache_supports' ) ) {
        function wp_cache_supports( \$feature ) { return up_oc()->supports( \$feature ); }
}
if ( ! function_exists( 'wp_cache_close' ) ) {
        function wp_cache_close() { up_oc()->close(); return true; }
}
if ( ! function_exists( 'wp_cache_add_global_groups' ) ) {
        function wp_cache_add_global_groups( \$groups ) { up_oc()->addGlobalGroups( \$groups ); }
}
if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
        function wp_cache_add_non_persistent_groups( \$groups ) { up_oc()->addNonPersistentGroups( \$groups ); }
}
if ( ! function_exists( 'wp_cache_switch_to_blog' ) ) {
        function wp_cache_switch_to_blog( \$blog_id ) { return up_oc()->switch_blog( \$blog_id ); }
}
if ( ! function_exists( 'wp_cache_reset' ) ) {
        function wp_cache_reset() { return true; } // deprecated core API; legacy callers must not fatal
}

return true;
PHP;
        }

        /**
         * Atomic write: temp file in the same directory + rename over the target.
         *
         * @param string $wp_content_dir Target directory.
         * @param string $contents       File contents.
         * @return bool
         */
        private function write_atomic( $wp_content_dir, $contents ) {
                $tmp = $wp_content_dir . '/object-cache.php.uc-tmp-' . getmypid();
                if ( false === @file_put_contents( $tmp, $contents, LOCK_EX ) ) {
                        return false;
                }
                @chmod( $tmp, 0640 );
                if ( ! @rename( $tmp, $wp_content_dir . '/object-cache.php' ) ) {
                        @unlink( $tmp );
                        return false;
                }
                return true;
        }

        private function exportPath( $path ) {
                return var_export( $path, true );
        }

        private function record_status( $status ) {
                if ( function_exists( 'set_transient' ) ) {
                        set_transient( self::STATUS_TRANSIENT, $status, HOUR_IN_SECONDS );
                }
        }
}
