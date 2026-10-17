<?php
/**
 * Build a PostFacts value from a WordPress post.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boundary adapter. The inclusion rule stays pure.
 */
final class Snapshot {

	/**
	 * @param array<string, mixed>|null $meta Prefetched exclusion values. Null reads them for this post alone.
	 */
	public static function from_post( \WP_Post $post, ?array $meta = null ): PostFacts {
		$object   = get_post_type_object( $post->post_type );
		$public   = $object instanceof \WP_Post_Type && ! empty( $object->public );
		$password = (string) $post->post_password;
		if ( null === $meta ) {
			$meta = array(
				ExcludeMeta::META_KEY              => get_post_meta( $post->ID, ExcludeMeta::META_KEY, true ),
				'_yoast_wpseo_meta-robots-noindex' => get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ),
				'rank_math_robots'                 => get_post_meta( $post->ID, 'rank_math_robots', true ),
				'_seopress_robots_index'           => get_post_meta( $post->ID, '_seopress_robots_index', true ),
				'_aioseop_noindex'                 => get_post_meta( $post->ID, '_aioseop_noindex', true ),
				'_aioseo_noindex'                  => get_post_meta( $post->ID, '_aioseo_noindex', true ),
				'_genesis_noindex'                 => get_post_meta( $post->ID, '_genesis_noindex', true ),
			);
		}
		$excluded = isset( $meta[ ExcludeMeta::META_KEY ] ) ? (string) $meta[ ExcludeMeta::META_KEY ] : '';

		return new PostFacts(
			(int) $post->ID,
			(string) $post->post_status,
			(string) $post->post_type,
			$public,
			'' !== $password,
			'1' === $excluded,
			Noindex::detect( $meta )
		);
	}
}
