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
		return self::from_permalink( $permalink, self::is_front_page( $post ) );
	}

	/**
	 * Build a markdown URL from a permalink string. The front page lives at /index.md.
	 * Appending ".md" to a bare host would produce a different domain.
	 */
	public static function from_permalink( string $permalink, bool $front_page ): string {
		// Pure helper. wp_parse_url() is not available to the unit tests.
		$parts = parse_url( $permalink ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) ) {
			return $permalink;
		}
		$path = isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '/';
		if ( isset( $parts['query'] ) && is_string( $parts['query'] ) && '' !== $parts['query'] ) {
			$query = $parts['query'];
			if ( 1 !== preg_match( '/(?:^|&)format=/', $query ) ) {
				$query .= '&format=markdown';
			}
			return self::assemble( $parts, $path, $query );
		}
		$trimmed = rtrim( $path, '/' );
		if ( $front_page || '' === $trimmed ) {
			$path = $trimmed . '/index.md';
		} else {
			$path = $trimmed . '.md';
		}
		return self::assemble( $parts, $path, '' );
	}

	public static function is_front_page( \WP_Post $post ): bool {
		if ( 'page' !== (string) get_option( 'show_on_front' ) ) {
			return false;
		}
		return (int) get_option( 'page_on_front' ) === (int) $post->ID;
	}

	/**
	 * @param array<string, mixed> $parts URL parts from parse_url.
	 */
	private static function assemble( array $parts, string $path, string $query ): string {
		$scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$url    = $scheme . $host . $port . $path;
		if ( '' !== $query ) {
			$url .= '?' . $query;
		}
		return $url;
	}
}
