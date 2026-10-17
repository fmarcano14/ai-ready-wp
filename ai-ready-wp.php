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
define( 'AI_READY_CONVERTER', '1' );

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

/**
 * Boot the single-site plugin.
 */
function ai_ready_boot() {
	// WordPress polyfills mb_strlen(). mb_check_encoding() still needs the extension.
	if ( ! extension_loaded( 'mbstring' ) ) {
		add_action( 'admin_notices', 'ai_ready_mbstring_notice' );
		return;
	}
	if ( is_multisite() ) {
		add_action( 'admin_notices', 'ai_ready_multisite_notice' );
		return;
	}
	( new AiReady\Front() )->register();
	( new AiReady\Scheduler() )->register();
	if ( is_admin() ) {
		( new AiReady\AdminPage() )->register();
		( new AiReady\ExcludeMeta() )->register();
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'ai-ready', \AiReady\CliCommand::class );
	}
}

/**
 * Activation flushes rewrite rules once. It does not build the files.
 */
function ai_ready_activate() {
	if ( ! extension_loaded( 'mbstring' ) ) {
		deactivate_plugins( plugin_basename( AI_READY_FILE ) );
		wp_die( esc_html__( 'AI Ready WP needs the PHP mbstring extension.', 'ai-ready-wp' ) );
	}
	if ( is_multisite() ) {
		deactivate_plugins( plugin_basename( AI_READY_FILE ) );
		wp_die( esc_html__( 'AI Ready WP is a single-site plugin and does not run on multisite.', 'ai-ready-wp' ) );
	}
	( new AiReady\Front() )->rewrites();
	flush_rewrite_rules();
}

/**
 * Deactivation drops rewrite rules and leaves generated files for uninstall.
 * Rules added on init are still in memory, so they have to be removed before the flush.
 * Otherwise /llms.txt stays in the rewrite list and WordPress sends it to the home page.
 */
function ai_ready_deactivate() {
	wp_clear_scheduled_hook( 'ai_ready_rebuild' );
	ai_ready_remove_rewrite_rules();
	flush_rewrite_rules( false );
}

/**
 * Drop this plugin's rewrite rules from the in-memory list.
 */
function ai_ready_remove_rewrite_rules() {
	global $wp_rewrite;
	if ( ! $wp_rewrite instanceof WP_Rewrite ) {
		return;
	}
	foreach ( array( '^llms\.txt$', '^llms-full\.txt$' ) as $regex ) {
		unset( $wp_rewrite->extra_rules_top[ $regex ], $wp_rewrite->extra_rules[ $regex ] );
	}
}

/**
 * Admin notice when PHP was built without mbstring.
 */
function ai_ready_mbstring_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'AI Ready WP needs the PHP mbstring extension. It stays inactive until mbstring is installed.', 'ai-ready-wp' );
	echo '</p></div>';
}

/**
 * Admin notice when the plugin is active on a network.
 */
function ai_ready_multisite_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'AI Ready WP is a single-site plugin and does not run on multisite.', 'ai-ready-wp' );
	echo '</p></div>';
}

add_action( 'plugins_loaded', 'ai_ready_boot' );
register_activation_hook( AI_READY_FILE, 'ai_ready_activate' );
register_deactivation_hook( AI_READY_FILE, 'ai_ready_deactivate' );
