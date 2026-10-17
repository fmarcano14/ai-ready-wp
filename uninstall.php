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
wp_clear_scheduled_hook( 'ai_ready_rebuild' );

$ai_ready_uploads = wp_upload_dir( null, false );
if ( empty( $ai_ready_uploads['error'] ) && ! empty( $ai_ready_uploads['basedir'] ) ) {
	$ai_ready_root = $ai_ready_uploads['basedir'] . '/ai-ready';
	if ( is_dir( $ai_ready_root ) ) {
		ai_ready_uninstall_rmdir( $ai_ready_root );
	}
}

/**
 * Delete a directory tree created by this plugin.
 *
 * @param string $dir Absolute path.
 */
function ai_ready_uninstall_rmdir( $dir ) {
	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			ai_ready_uninstall_rmdir( $path );
			continue;
		}
		wp_delete_file( $path );
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- uninstall must remove the private cache directory.
}
