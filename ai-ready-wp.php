<?php
/**
 * Plugin Name: AI Ready WP
 * Description: Publishes llms.txt and Markdown views of public content. Single-site only.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Fernando Marcano
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-ready-wp
 *
 * @package AiReady
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AI_READY_VERSION', '1.0.0' );
define( 'AI_READY_FILE', __FILE__ );

/**
 * Load plugin classes without Composer so a normal install does not need vendor/.
 */
function ai_ready_autoload( $class_name ) {
	$prefix = 'AiReady\\';
	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $path ) ) {
		require $path;
	}
}

spl_autoload_register( 'ai_ready_autoload' );
