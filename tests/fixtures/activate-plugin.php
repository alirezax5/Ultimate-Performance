<?php
/**
 * Activate the plugin (real activation hook) so production boot path —
 * including the AS job bridge registered in late_boot — is live for tests.
 * Idempotent; leaves plugin active afterwards.
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/wp-shim/' ); // portable WP shim
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ULTIMATE_PERFORMANCE_TESTING', true );
define( 'ULTIMATE_PERFORMANCE_DIR', dirname( dirname( __DIR__ ) ) . '/' );
require ULTIMATE_PERFORMANCE_DIR . 'src/Core/Autoloader.php';
UltimatePerformance\Core\Autoloader::register();
require ABSPATH . 'wp-load.php';

// Mark active the way WP does.
$plugins = (array) get_option( 'active_plugins', array() );
if ( ! in_array( 'ultimate-performance/ultimate-performance.php', $plugins, true ) ) {
	$plugins[] = 'ultimate-performance/ultimate-performance.php';
	update_option( 'active_plugins', $plugins, true );
}

// Run the real activation hook once (creates settings + cache root).
if ( ! get_option( 'ultimate_performance_settings', false ) ) {
	\UltimatePerformance\Core\Installer::activate();
}
// Enable page cache + queue so late_boot registers everything.
$s = \UltimatePerformance\Core\Settings::instance();
$d = $s->raw();
$d['enabled']            = true;
$d['page_cache_enabled'] = true;
$d['queue_enabled']      = true;
update_option( 'ultimate_performance_settings', $d, true );

echo "active: ", var_export( in_array( 'ultimate-performance/ultimate-performance.php', (array) get_option( 'active_plugins' ), true ), true ), "\n";
echo "settings: ", json_encode( array_intersect_key( $d, array_flip( array( 'enabled', 'page_cache_enabled', 'queue_enabled' ) ) ) ), "\n";
