<?php
/**
 * Renders one post as a public Markdown document.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Content;

use Fer\AiReady\Domain\Document;
use Fer\AiReady\Sanitizer;

/**
 * Converts rendered post content in-process.
 *
 * Rendering uses `the_content` so blocks and shortcodes run locally. The
 * oEmbed auto-embed callback is removed for the duration of the render so
 * generation does not call out to other sites.
 *
 * @since 1.0.0
 */
final class Renderer {
	/**
	 * HTML to Markdown converter.
	 *
	 * @since 1.0.0
	 *
	 * @var MarkdownConverter
	 */
	private $converter;

	/**
	 * Output sanitizer.
	 *
	 * @since 1.0.0
	 *
	 * @var Sanitizer
	 */
	private $sanitizer;

	/**
	 * Document composer.
	 *
	 * @since 1.0.0
	 *
	 * @var MarkdownDocument
	 */
	private $document;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param MarkdownConverter $converter HTML converter.
	 * @param Sanitizer         $sanitizer Output sanitizer.
	 */
	public function __construct( MarkdownConverter $converter, Sanitizer $sanitizer ) {
		$this->converter = $converter;
		$this->sanitizer = $sanitizer;
		$this->document  = new MarkdownDocument();
	}

	/**
	 * Render a post.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	public function render_post( \WP_Post $post ) {
		$html = $this->rendered_html( $post );
		$html = apply_filters( 'ai_ready_wp_rendered_html', $html, $post );
		if ( ! is_string( $html ) ) {
			$html = '';
		}
		$body     = $this->converter->convert( $html );
		$markdown = $this->document->compose(
			new Document(
				get_the_title( $post ),
				(string) get_permalink( $post ),
				(string) get_post_time( 'c', true, $post ),
				(string) get_post_modified_time( 'c', true, $post ),
				$post->post_type,
				$body
			)
		);
		$markdown = $this->sanitizer->clean_markdown( $markdown, Sanitizer::MAX_DOCUMENT_BYTES );
		$filtered = apply_filters( 'ai_ready_wp_markdown', $markdown, $post );
		if ( ! is_string( $filtered ) ) {
			return $markdown;
		}
		return $this->sanitizer->clean_markdown( $filtered, Sanitizer::MAX_DOCUMENT_BYTES );
	}

	/**
	 * Render post content to HTML without making HTTP requests.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function rendered_html( \WP_Post $post ) {
		$embed = isset( $GLOBALS['wp_embed'] ) ? $GLOBALS['wp_embed'] : null;
		if ( $embed instanceof \WP_Embed ) {
			remove_filter( 'the_content', array( $embed, 'autoembed' ), 8 );
		}

		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- setup_postdata() requires the post global. It is restored below.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		try {
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core content filter.
		} finally {
			if ( $previous instanceof \WP_Post ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the post global captured above.
				$GLOBALS['post'] = $previous;
				setup_postdata( $previous );
			} else {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
			}
			if ( $embed instanceof \WP_Embed ) {
				add_filter( 'the_content', array( $embed, 'autoembed' ), 8 );
			}
		}

		return is_string( $html ) ? $html : '';
	}
}
