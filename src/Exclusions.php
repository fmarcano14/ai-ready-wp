<?php
/**
 * Parses and matches the manual exclusion list.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Turns a textarea of IDs, slugs, and paths into match rules.
 *
 * @since 1.0.0
 */
final class Exclusions {
	/**
	 * Parse an exclusion list.
	 *
	 * Numeric tokens are IDs. Tokens containing a slash are paths.
	 * Everything else is a slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw setting value.
	 * @return array{ids: array<int, int>, slugs: array<int, string>, paths: array<int, string>}
	 */
	public static function parse( $raw ) {
		$raw   = str_replace( array( "\r\n", "\r", "\t" ), "\n", (string) $raw );
		$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$ids   = array();
		$slugs = array();
		$paths = array();

		if ( ! is_array( $parts ) ) {
			return array(
				'ids'   => array(),
				'slugs' => array(),
				'paths' => array(),
			);
		}

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^[0-9]+$/', $part ) ) {
				$ids[] = (int) $part;
				continue;
			}
			if ( false !== strpos( $part, '/' ) ) {
				$paths[] = strtolower( trim( $part, '/' ) );
				continue;
			}
			$slugs[] = strtolower( $part );
		}

		return array(
			'ids'   => array_values( array_unique( $ids ) ),
			'slugs' => array_values( array_unique( $slugs ) ),
			'paths' => array_values( array_unique( array_filter( $paths, 'strlen' ) ) ),
		);
	}

	/**
	 * Whether a document matches the rules.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $post_id        Post ID.
	 * @param string               $slug           Post slug.
	 * @param string               $permalink_path Path from the permalink.
	 * @param array<string, mixed> $rules          Parsed rules.
	 * @return bool
	 */
	public static function matches( $post_id, $slug, $permalink_path, array $rules ) {
		$ids   = isset( $rules['ids'] ) && is_array( $rules['ids'] ) ? $rules['ids'] : array();
		$slugs = isset( $rules['slugs'] ) && is_array( $rules['slugs'] ) ? $rules['slugs'] : array();
		$paths = isset( $rules['paths'] ) && is_array( $rules['paths'] ) ? $rules['paths'] : array();

		$post_id = (int) $post_id;
		if ( $post_id > 0 && in_array( $post_id, array_map( 'intval', $ids ), true ) ) {
			return true;
		}

		$slug = strtolower( (string) $slug );
		if ( '' !== $slug && in_array( $slug, $slugs, true ) ) {
			return true;
		}

		$path = strtolower( trim( (string) $permalink_path, '/' ) );
		if ( '' === $path ) {
			return false;
		}

		foreach ( $paths as $rule ) {
			$rule = strtolower( trim( (string) $rule, '/' ) );
			if ( '' === $rule ) {
				continue;
			}
			if ( $path === $rule ) {
				return true;
			}
			$suffix = '/' . $rule;
			if ( strlen( $path ) > strlen( $rule ) && substr( $path, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}
}
