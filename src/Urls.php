<?php
/**
 * Public URLs for a document.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markdown URL derived from the HTML permalink.
 */
final class Urls {

	public static function markdown( \WP_Post $post ): string {
		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return home_url( '/?p=' . (int) $post->ID . '&format=markdown' );
		}
		$parts = wp_parse_url( $permalink );
		if ( ! is_array( $parts ) ) {
			return $permalink;
		}
		if ( ! empty( $parts['query'] ) ) {
			return add_query_arg( 'format', 'markdown', $permalink );
		}
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$path   = untrailingslashit( $path ) . '.md';
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return $scheme . $host . $port . $path;
	}
}
