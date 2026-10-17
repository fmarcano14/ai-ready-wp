<?php
/**
 * Router for PHP's built-in server. WordPress permalinks are not real files.
 *
 * @package AiReady
 */

$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? $_SERVER['DOCUMENT_ROOT'] : getcwd();
$uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url( $uri, PHP_URL_PATH );
if ( ! is_string( $path ) ) {
	$path = '/';
}
$file = $root . $path;

if ( '/' !== $path && is_file( $file ) ) {
	return false;
}

require $root . '/index.php';
