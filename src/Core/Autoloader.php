<?php
/**
 * PSR-4-ish autoloader. Zero dependencies.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps UltimatePerformance\* classnames to src/ files.
 *
 * UltimatePerformance\PageCache\Store → src/PageCache/Store.php
 * UltimatePerformance\ObjectCache\Backends\Redis\Backend → src/ObjectCache/Redis/Backend.php
 */
final class Autoloader {
	/** @var bool */
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * @param string $class Fully qualified class name.
	 */
	public static function load( $class ) {
		if ( 0 !== strpos( $class, 'UltimatePerformance\\' ) ) {
			return;
		}
		$rel = str_replace( '\\', '/', substr( $class, strlen( 'UltimatePerformance\\' ) ) );

		// Special-case: Backends live in backend-named dirs.
		$rel = str_replace( '/Backends/', '/', $rel );

		// Special-case: multiple classes share one file.
		$shared = array(
			'Queue/Backend/Job'     => 'src/Queue/Backend/Backend.php',  // Job + Backend interface live together.
			'Queue/EnqueueException' => 'src/Queue/QueueManager.php',    // EnqueueException lives beside QueueManager.
		);
		if ( isset( $shared[ $rel ] ) ) {
			require_once ULTIMATE_PERFORMANCE_DIR . $shared[ $rel ];
			return;
		}

		$path = ULTIMATE_PERFORMANCE_DIR . 'src/' . $rel . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
