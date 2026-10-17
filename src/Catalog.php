<?php
/**
 * Collects the public documents that belong in llms.txt.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

use Fer\AiReady\Content\MarkdownText;
use Fer\AiReady\Content\Renderer;

/**
 * Queries selected post types and builds link sections.
 *
 * @since 1.0.0
 */
final class Catalog {
	/**
	 * Eligibility policy.
	 *
	 * @since 1.0.0
	 *
	 * @var Publication
	 */
	private $publication;

	/**
	 * Markdown renderer, used for llms-full.txt.
	 *
	 * @since 1.0.0
	 *
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Publication $publication Eligibility policy.
	 * @param Renderer    $renderer    Markdown renderer.
	 */
	public function __construct( Publication $publication, Renderer $renderer ) {
		$this->publication = $publication;
		$this->renderer    = $renderer;
	}

	/**
	 * Collect sections and, when requested, full documents.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings    Sanitized settings.
	 * @param bool                 $with_bodies Whether to render full Markdown bodies.
	 * @return array<string, mixed>
	 */
	public function collect( array $settings, $with_bodies ) {
		$sequence  = $this->sequence( $settings );
		$remaining = isset( $settings['max_items'] ) ? (int) $settings['max_items'] : 500;
		$full_left = ( $with_bodies && ! empty( $settings['enable_llms_full'] ) ) ? (int) $settings['max_full_items'] : 0;
		$exclude   = Exclusions::parse( isset( $settings['exclude'] ) ? (string) $settings['exclude'] : '' );
		$sections  = array();
		$documents = array();
		$counts    = array();
		$skipped   = 0;

		foreach ( $sequence as $slug ) {
			if ( $remaining < 1 ) {
				break;
			}
			$links = array();
			$posts = $this->query( $slug, $remaining, $exclude['ids'] );
			foreach ( $posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				if ( ! $this->publication->is_index_candidate( $post ) ) {
					++$skipped;
					continue;
				}
				$links[] = $this->link_for( $post, $settings );
				if ( $full_left > 0 ) {
					$documents[] = array(
						'markdown' => $this->renderer->render_post( $post ),
					);
					--$full_left;
				}
				--$remaining;
				if ( $remaining < 1 ) {
					break;
				}
			}
			$counts[ $slug ] = count( $links );
			$sections[]      = array(
				'heading'  => $this->heading( $slug, $settings ),
				'optional' => in_array( $slug, $settings['optional_post_types'], true ),
				'links'    => $links,
				'type'     => $slug,
			);
		}

		$filtered = apply_filters( 'ai_ready_wp_sections', $sections, $settings );
		if ( is_array( $filtered ) ) {
			$sections = $filtered;
		}

		return array(
			'sections'  => $sections,
			'documents' => $documents,
			'counts'    => $counts,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Selected post types in display order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<int, string>
	 */
	private function sequence( array $settings ) {
		$types    = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array();
		$order    = isset( $settings['section_order'] ) && is_array( $settings['section_order'] ) ? $settings['section_order'] : array();
		$sequence = array();
		foreach ( $order as $slug ) {
			if ( in_array( $slug, $types, true ) ) {
				$sequence[] = $slug;
			}
		}
		foreach ( $types as $slug ) {
			if ( ! in_array( $slug, $sequence, true ) ) {
				$sequence[] = $slug;
			}
		}
		return $sequence;
	}

	/**
	 * Query one post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $slug      Post type.
	 * @param int             $remaining Remaining link budget.
	 * @param array<int, int> $ids    Excluded IDs.
	 * @return array<int, \WP_Post>
	 */
	private function query( $slug, $remaining, array $ids ) {
		$scan = min( 1000, max( $remaining, $remaining * 3 ) );
		$args = array(
			'post_type'              => $slug,
			'post_status'            => 'publish',
			'posts_per_page'         => $scan,
			'has_password'           => false,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'orderby'                => 'page' === $slug ? array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			) : array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
		);
		if ( array() !== $ids ) {
			$args['post__not_in'] = $ids;
		}
		$query = new \WP_Query( $args );
		$posts = $query->posts;
		wp_reset_postdata();
		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * One llms.txt link.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post             $post     Post object.
	 * @param array<string, mixed> $settings Settings.
	 * @return array{name: string, url: string, note: string}
	 */
	private function link_for( \WP_Post $post, array $settings ) {
		$permalink = (string) get_permalink( $post );
		$url       = $permalink;
		if ( ! empty( $settings['enable_markdown'] ) && ! empty( $settings['markdown_links'] ) ) {
			$markdown = MarkdownUrl::from_permalink( $permalink, home_url( '/' ) );
			if ( '' !== $markdown ) {
				$url = $markdown;
			}
		}
		$note = '';
		if ( ! empty( $settings['link_notes'] ) ) {
			$note = MarkdownText::plain_text( $post->post_excerpt );
			if ( '' === $note ) {
				$note = MarkdownText::plain_text( $post->post_content );
			}
			$note = wp_trim_words( $note, 24, '' );
		}
		$title = MarkdownText::single_line( get_the_title( $post ) );
		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %d: post ID. */
				__( 'Document %d', 'ai-ready-wp' ),
				(int) $post->ID
			);
		}
		return array(
			'name' => $title,
			'url'  => $url,
			'note' => $note,
		);
	}

	/**
	 * Section heading for a post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $slug     Post type.
	 * @param array<string, mixed> $settings Settings.
	 * @return string
	 */
	private function heading( $slug, array $settings ) {
		if ( ! empty( $settings['section_labels'][ $slug ] ) && is_string( $settings['section_labels'][ $slug ] ) ) {
			return $settings['section_labels'][ $slug ];
		}
		$types = PostTypes::selectable();
		return isset( $types[ $slug ] ) ? $types[ $slug ] : $slug;
	}
}
