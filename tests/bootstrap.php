<?php
/**
 * Unit-test bootstrap. WordPress is loaded only for the integration suite.
 *
 * @package AiReady
 */

$ai_ready_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false !== $ai_ready_tests_dir && '' !== $ai_ready_tests_dir && '1' === getenv( 'RUN_WP_TESTS' ) ) {
	define( 'AI_READY_TESTING', true );
	require rtrim( $ai_ready_tests_dir, '/' ) . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require dirname( __DIR__ ) . '/ai-ready-wp.php';
		}
	);

	require rtrim( $ai_ready_tests_dir, '/' ) . '/includes/bootstrap.php';
} else {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}
	require dirname( __DIR__ ) . '/vendor/autoload.php';
}
