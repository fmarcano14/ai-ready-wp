<?php
/**
 * Builds the public Markdown URL for a permalink.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Maps a canonical permalink to its Markdown representation.
 *
 * Pretty permalinks gain a `.md` suffix (`/about/` becomes `/about.md`).
 * The site root becomes `/index.md`. Query-string permalinks keep the query
 * and add `format=markdown`, which works when the site is not using pretty permalinks.
 *
 * @since 1.0.0
 */
final class MarkdownUrl {
	/**
	 * Convert a permalink into a Markdown URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $permalink Canonical permalink.
	 * @param string $home_url  Site home URL, used to detect the front page.
	 * @return string Empty when the permalink cannot be parsed.
	 */
	public static function from_permalink( $permalink, $home_url = '' ) {
		$permalink = trim( (string) $permalink );
		if ( '' === $permalink ) {
			return '';
		}

		$hash = strpos( $permalink, '#' );
		if ( false !== $hash ) {
			$permalink = substr( $permalink, 0, $hash );
		}

		$parts = parse_url( $permalink ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$origin = self::origin( $parts );
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$url = $origin . $path . '?' . $parts['query'];
			if ( false === strpos( $parts['query'], 'format=' ) ) {
				$url .= '&format=markdown';
			}
			return $url;
		}

		$home_path = '';
		if ( '' !== $home_url ) {
			$home = parse_url( $home_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			if ( is_array( $home ) && isset( $home['path'] ) ) {
				$home_path = rtrim( $home['path'], '/' );
			}
		}

		$normalized = rtrim( $path, '/' );
		if ( $normalized === $home_path ) {
			$prefix = '' === $home_path ? '' : $home_path;
			return $origin . $prefix . '/index.md';
		}

		if ( substr( $normalized, -3 ) !== '.md' ) {
			$normalized .= '.md';
		}

		return $origin . $normalized;
	}

	/**
	 * Scheme, host, and port from a parse_url result.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $parts Parsed URL.
	 * @return string
	 */
	private static function origin( array $parts ) {
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
		$origin = $scheme . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}
}
