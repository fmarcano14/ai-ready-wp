<?php
/**
 * Read noindex flags stored by common SEO plugins.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interprets raw post meta. It does not call WordPress.
 */
final class Noindex {

	public static function watches_key( string $key ): bool {
		return in_array(
			$key,
			array(
				'_yoast_wpseo_meta-robots-noindex',
				'rank_math_robots',
				'_seopress_robots_index',
				'_aioseop_noindex',
				'_aioseo_noindex',
				'_genesis_noindex',
			),
			true
		);
	}

	/**
	 * @param array<string, mixed> $meta Meta values keyed by meta key.
	 */
	public static function detect( array $meta ): bool {
		if ( self::is_one( $meta, '_yoast_wpseo_meta-robots-noindex' ) ) {
			return true;
		}
		if ( self::rank_math( $meta ) ) {
			return true;
		}
		if ( isset( $meta['_seopress_robots_index'] ) && 'yes' === (string) $meta['_seopress_robots_index'] ) {
			return true;
		}
		if ( self::is_on( $meta, '_aioseop_noindex' ) || self::is_on( $meta, '_aioseo_noindex' ) ) {
			return true;
		}
		if ( self::is_one( $meta, '_genesis_noindex' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $meta Meta values.
	 */
	private static function rank_math( array $meta ): bool {
		if ( ! isset( $meta['rank_math_robots'] ) ) {
			return false;
		}
		$rank = $meta['rank_math_robots'];
		if ( is_array( $rank ) ) {
			return in_array( 'noindex', $rank, true );
		}
		return is_string( $rank ) && false !== strpos( $rank, 'noindex' );
	}

	/**
	 * @param array<string, mixed> $meta Meta values.
	 */
	private static function is_one( array $meta, string $key ): bool {
		return isset( $meta[ $key ] ) && '1' === (string) $meta[ $key ];
	}

	/**
	 * @param array<string, mixed> $meta Meta values.
	 */
	private static function is_on( array $meta, string $key ): bool {
		if ( ! isset( $meta[ $key ] ) ) {
			return false;
		}
		return in_array( (string) $meta[ $key ], array( '1', 'on', 'true' ), true );
	}
}
