<?php
/**
 * Shared allowlist for URLs emitted in Markdown.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Decides whether a URL may appear in generated Markdown.
 *
 * Links may use http, https, mailto, and tel. Images may use http and https.
 * Relative references are allowed. Every other scheme is rejected, including
 * forms that only become dangerous after URL-decoding.
 *
 * @since 1.0.0
 */
final class UrlPolicy {
	const LINK_SCHEMES  = array( 'http', 'https', 'mailto', 'tel' );
	const IMAGE_SCHEMES = array( 'http', 'https' );

	/**
	 * Whether the URL is safe to publish.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $url   Candidate URL.
	 * @param bool  $image True when the URL is an image source.
	 * @return bool
	 */
	public static function allows( $url, $image = false ) {
		if ( ! is_string( $url ) || ! self::is_utf8( $url ) ) {
			return false;
		}

		$decoded = $url;
		$pass    = 0;
		for ( $pass = 0; $pass < 8; $pass++ ) {
			$next = rawurldecode( html_entity_decode( $decoded, ENT_QUOTES, 'UTF-8' ) );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}

		if ( 8 === $pass || ! self::is_utf8( $decoded ) ) {
			return false;
		}

		$decoded = preg_replace( '/[\p{C}\p{Z}\s]+/u', '', $decoded );
		if ( ! is_string( $decoded ) || '' === $decoded || false !== strpos( $decoded, '\\' ) ) {
			return false;
		}

		$schemes = $image ? self::IMAGE_SCHEMES : self::LINK_SCHEMES;
		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $decoded, $match ) ) {
			$scheme = strtolower( $match[1] );
			if ( ! in_array( $scheme, $schemes, true ) ) {
				return false;
			}
			if ( 'http' === $scheme || 'https' === $scheme ) {
				// parse_url keeps this class free of WordPress so the policy can be unit tested.
				$parts = parse_url( $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				return is_array( $parts ) && ! empty( $parts['host'] );
			}
			return strlen( $decoded ) > strlen( $match[0] );
		}

		$first = strtok( $decoded, '/?#' );
		if ( ! is_string( $first ) ) {
			$first = '';
		}
		$starts_relative = isset( $decoded[0] ) && in_array( $decoded[0], array( '/', '?', '#' ), true );
		if ( false !== strpos( $first, ':' ) && ! $starts_relative ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a string is valid UTF-8.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Candidate text.
	 * @return bool
	 */
	private static function is_utf8( $value ) {
		return 1 === preg_match( '//u', $value );
	}
}
