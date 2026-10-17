<?php
/**
 * Plugin Name:       AI Ready WP
 * Plugin URI:        https://github.com/fmarcano14/ai-ready-wp
 * Description:       Make a WordPress site AI-ready with /llms.txt and clean Markdown versions of public content. Runs entirely inside wp-admin.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Fernando Marcano
 * Author URI:        https://fer.marketing
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-ready-wp
 * Domain Path:       /languages
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin notice shown when the runtime is too old to boot the plugin.
 *
 * @since 1.0.0
 *
 * @return void
 */
function ai_ready_wp_requirement_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Ready WP requires PHP 7.4 or newer and WordPress 6.2 or newer. The plugin has not started.', 'ai-ready-wp' ) . '</p></div>';
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', 'ai_ready_wp_requirement_notice' );
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, '6.2', '<' ) ) {
	add_action( 'admin_notices', 'ai_ready_wp_requirement_notice' );
	return;
}

define( 'AI_READY_WP_VERSION', '1.0.0' );
define( 'AI_READY_WP_FILE', __FILE__ );
define( 'AI_READY_WP_PATH', plugin_dir_path( __FILE__ ) );
define( 'AI_READY_WP_URL', plugin_dir_url( __FILE__ ) );

if ( is_readable( AI_READY_WP_PATH . 'vendor/autoload.php' ) ) {
	require AI_READY_WP_PATH . 'vendor/autoload.php';
} else {
	require AI_READY_WP_PATH . 'src/Autoloader.php';
	\Fer\AiReady\Autoloader::register();
}

register_activation_hook( __FILE__, array( '\Fer\AiReady\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Fer\AiReady\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\Fer\AiReady\Plugin::instance()->boot();
	}
);
