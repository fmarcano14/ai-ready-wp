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
		$previous_user = get_current_user_id();
		$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		wp_set_current_user( 0 );
		// Core shortcodes such as gallery read the current post. Set it without changing the visitor.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		try {
			$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, rendered as a logged-out visitor.
		} catch ( \Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- a broken shortcode must not abort the rest of the file.
			unset( $throwable );
			$content = $post->post_content;
		} finally {
			$GLOBALS['post'] = $previous_post;
			if ( $previous_post instanceof \WP_Post ) {
				setup_postdata( $previous_post );
			}
			wp_set_current_user( $previous_user );
		}
		return is_string( $content ) ? $content : '';
	}
}
