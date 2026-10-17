<?php
/**
 * Render post content the way an anonymous visitor would see it.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the_content as user 0 so membership shortcodes cannot leak into public files.
 */
final class ContentReader {

	public function render( \WP_Post $post ): string {
		$previous = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, rendered as a logged-out visitor.
		} finally {
			wp_set_current_user( $previous );
		}
		return is_string( $content ) ? $content : '';
	}
}
