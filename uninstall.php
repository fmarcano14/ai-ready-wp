<?php
/**
 * Remove plugin data when the plugin is deleted.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete options, transients, exclusion meta, cron, and cache files for one site.
 *
 * @since 1.0.0
 *
 * @return void
 */
function ai_ready_wp_delete_site() {
	delete_option( 'ai_ready_wp_settings' );
	delete_option( 'ai_ready_wp_cache_meta' );
	delete_option( 'ai_ready_wp_version' );
	delete_option( 'ai_ready_wp_last_error' );
	wp_clear_scheduled_hook( 'ai_ready_wp_regenerate' );

	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_ai_ready_wp_exclude' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_ai_ready_wp_noindex' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$patterns = array(
		$wpdb->esc_like( '_transient_ai_ready_wp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ai_ready_wp_' ) . '%',
		$wpdb->esc_like( 'ai_ready_wp_lock_' ) . '%',
	);
	foreach ( $patterns as $pattern ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
		ai_ready_wp_delete_tree( trailingslashit( $uploads['basedir'] ) . 'ai-ready-wp' );
	}
}

/**
 * Recursively delete a cache directory created by this plugin.
 *
 * @since 1.0.0
 *
 * @param string $dir Absolute directory.
 * @return void
 */
function ai_ready_wp_delete_tree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
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
			ai_ready_wp_delete_tree( $path );
			continue;
		}
		wp_delete_file( $path );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing an empty cache directory during uninstall.
	rmdir( $dir );
}

if ( is_multisite() ) {
	$ai_ready_wp_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $ai_ready_wp_site_ids as $ai_ready_wp_site_id ) {
		switch_to_blog( (int) $ai_ready_wp_site_id );
		ai_ready_wp_delete_site();
		restore_current_blog();
	}
} else {
	ai_ready_wp_delete_site();
}
