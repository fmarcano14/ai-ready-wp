<?php
/**
 * Decides which documents may be published for agents.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

use Fer\AiReady\Domain\Membership;

/**
 * Eligibility policy for Markdown documents and llms.txt.
 *
 * A document is public only when it is published, has no password, uses a
 * selected post type, is not on the exclusion list, is not marked noindex,
 * and is not a structural page such as the posts index or a WooCommerce
 * cart, checkout, or account page. Filters may deny publication. They cannot
 * force an ineligible document into the output.
 *
 * @since 1.0.0
 */
final class Publication {
	const EXCLUDE_META = '_ai_ready_wp_exclude';

	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings $settings Settings reader.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether /llms.txt and its describedby link should be public.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function discovery_enabled() {
		$mode = $this->settings->all()['discovery_mode'];
		if ( 'enabled' === $mode ) {
			return true;
		}
		if ( 'disabled' === $mode ) {
			return false;
		}
		return '1' === (string) get_option( 'blog_public', '1' );
	}

	/**
	 * Whether a document may have a public Markdown representation.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function is_public_post( \WP_Post $post ) {
		$allowed  = Membership::keeps( $this->facts( $post ) );
		$filtered = apply_filters( 'ai_ready_wp_post_is_public', $allowed, $post );
		return $allowed && (bool) $filtered;
	}

	/**
	 * Whether a document belongs in the index body.
	 *
	 * The site-wide discovery switch controls whether that file is public.
	 * It does not change which documents the file would list.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function is_index_candidate( \WP_Post $post ) {
		$allowed  = $this->is_public_post( $post );
		$filtered = apply_filters( 'ai_ready_wp_post_is_discoverable', $allowed, $post );
		return $allowed && (bool) $filtered;
	}

	/**
	 * Whether a document is listed while discovery is currently public.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function is_discoverable_post( \WP_Post $post ) {
		return $this->discovery_enabled() && $this->is_index_candidate( $post );
	}

	/**
	 * Whether the post type can show the exclude meta box.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function supports_meta_box( $post_id ) {
		$type = get_post_type( $post_id );
		if ( ! is_string( $type ) ) {
			return false;
		}
		$selectable = PostTypes::selectable();
		return isset( $selectable[ $type ] );
	}

	/**
	 * Status, password, post type, and reserved-page checks.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function is_structurally_eligible( \WP_Post $post ) {
		return Membership::structural( $this->facts( $post ) );
	}

	/**
	 * Facts the pure membership rules read.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private function facts( \WP_Post $post ) {
		$settings = $this->settings->all();
		$types    = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array();
		$selected = in_array( $post->post_type, $types, true ) && isset( PostTypes::selectable()[ $post->post_type ] );
		$reserved = false;
		if ( 'page' === $post->post_type && (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
			$reserved = true;
		}
		foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $option ) {
			if ( (int) get_option( $option ) === (int) $post->ID ) {
				$reserved = true;
			}
		}
		return array(
			'status'   => (string) $post->post_status,
			'password' => '' !== (string) $post->post_password,
			'selected' => $selected,
			'reserved' => $reserved,
			'excluded' => $this->is_excluded( $post ),
			'noindex'  => $this->is_noindex( $post ),
		);
	}

	/**
	 * Whether the document is excluded by meta or the settings list.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function is_excluded( \WP_Post $post ) {
		if ( '1' === (string) get_post_meta( $post->ID, self::EXCLUDE_META, true ) ) {
			return true;
		}
		$rules = Exclusions::parse( (string) $this->settings->all()['exclude'] );
		$path  = (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );
		return Exclusions::matches( (int) $post->ID, (string) $post->post_name, $path, $rules );
	}

	/**
	 * Whether SEO meta marks the document noindex.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function is_noindex( \WP_Post $post ) {
		return Robots::is_noindex( $this->meta_map( $post->ID ) );
	}

	/**
	 * Human explanation of the effective state, for the editor meta box.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	public function explanation( \WP_Post $post ) {
		if ( ! $this->is_structurally_eligible( $post ) ) {
			return __( 'Not included. The document is not a published, password-free page of a selected type, or it is reserved for the posts index, cart, checkout, or account screen.', 'ai-ready-wp' );
		}
		if ( $this->is_excluded( $post ) ) {
			return __( 'Not included because this document is on the exclusion list or its editor box is checked.', 'ai-ready-wp' );
		}
		if ( $this->is_noindex( $post ) ) {
			return __( 'Not included because the document is marked noindex.', 'ai-ready-wp' );
		}
		if ( ! $this->is_public_post( $post ) ) {
			return __( 'Not included because another plugin denied publication.', 'ai-ready-wp' );
		}
		if ( ! $this->discovery_enabled() ) {
			return __( 'Markdown can be served. It is omitted from llms.txt because discovery is off.', 'ai-ready-wp' );
		}
		return __( 'Included in the public Markdown output and in llms.txt.', 'ai-ready-wp' );
	}

	/**
	 * Flatten bulk post meta to a key => value map.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function meta_map( $post_id ) {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$map = array();
		foreach ( $all as $key => $values ) {
			if ( is_array( $values ) && array_key_exists( 0, $values ) ) {
				$map[ $key ] = $values[0];
			}
		}
		return $map;
	}
}
