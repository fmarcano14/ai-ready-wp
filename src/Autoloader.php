<?php
/**
 * Fallback class loader used when Composer has not been installed.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * PSR-4 loader for the Fer\AiReady namespace.
 *
 * @since 1.0.0
 */
final class Autoloader {
	const PREFIX = 'Fer\\AiReady\\';

	/**
	 * Register the loader.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file when it belongs to this plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		$path = self::path_for( $class_name );
		if ( '' === $path || ! is_readable( $path ) ) {
			return;
		}
		require_once $path;
	}

	/**
	 * Resolve a class name to an absolute file path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string Empty when the class is outside this namespace.
	 */
	public static function path_for( $class_name ) {
		$class_name = (string) $class_name;
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return '';
		}
		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', '/', $relative );
		return __DIR__ . '/' . $relative . '.php';
	}
}
