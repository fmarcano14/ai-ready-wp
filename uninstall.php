<?php
/**
 * Remove AI Ready WP data on uninstall.
 *
 * @package AiReady
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ai_ready_settings' );
delete_metadata( 'post', 0, '_ai_ready_exclude', '', true );
delete_transient( 'ai_ready_rebuild' );
delete_transient( 'ai_ready_last_error' );
wp_clear_scheduled_hook( 'ai_ready_rebuild' );

require_once __DIR__ . '/src/Store.php';
\AiReady\Store::open()->destroy();
