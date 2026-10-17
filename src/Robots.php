<?php
/**
 * Reads common SEO plugin meta and decides whether a document is noindex.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Interprets stored robots directives without calling WordPress.
 *
 * The collector passes values already loaded from post meta. Only the keys
 * below are consulted, so unrelated content cannot trip the check.
 *
 * @since 1.0.0
 */
final class Robots {
	/**
	 * Whether the meta map marks the document noindex.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $meta Post meta keyed by meta key.
	 * @return bool
	 */
	public static function is_noindex( array $meta ) {
		if ( self::is_flag( self::value( $meta, '_ai_ready_wp_noindex' ) ) ) {
			return true;
		}

		$yoast = self::value( $meta, '_yoast_wpseo_meta-robots-noindex' );
		if ( 1 === $yoast || '1' === $yoast ) {
			return true;
		}

		if ( self::contains_noindex( self::value( $meta, 'rank_math_robots' ) ) ) {
			return true;
		}

		if ( 'yes' === self::value( $meta, '_seopress_robots_index' ) ) {
			return true;
		}

		foreach ( array( '_aioseop_noindex', '_aioseo_noindex', '_aioseo_robots_noindex', '_genesis_noindex' ) as $key ) {
			if ( self::is_flag( self::value( $meta, $key ) ) ) {
				return true;
			}
		}

		return self::contains_noindex( self::value( $meta, '_aioseo_robots' ) );
	}

	/**
	 * Read one meta value.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $meta Meta map.
	 * @param string               $key  Meta key.
	 * @return mixed
	 */
	private static function value( array $meta, $key ) {
		return array_key_exists( $key, $meta ) ? $meta[ $key ] : null;
	}

	/**
	 * Whether a scalar looks like an enabled checkbox.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	private static function is_flag( $value ) {
		return in_array( $value, array( 1, '1', true, 'true', 'on', 'yes' ), true );
	}

	/**
	 * Whether a robots value includes a noindex directive.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value String, list, or JSON object.
	 * @return bool
	 */
	private static function contains_noindex( $value ) {
		if ( is_string( $value ) && isset( $value[0] ) && '{' === $value[0] ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			}
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && 'noindex' === strtolower( $key ) && ! empty( $item ) ) {
					return true;
				}
				if ( self::contains_noindex( $item ) ) {
					return true;
				}
			}
			return false;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		return 1 === preg_match( '/(?:^|[\s,"\'])noindex(?:$|[\s,"\'])/i', $value );
	}
}
