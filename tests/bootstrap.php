<?php
/**
 * PHPUnit bootstrap.
 *
 * Defines ABSPATH so class files can guard direct access, then loads Composer.
 *
 * @package AiReadyWp
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Run composer install before the tests.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require $autoload;
