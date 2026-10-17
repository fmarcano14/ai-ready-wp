<?php
/**
 * Per-document exclude control.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Admin;

use Fer\AiReady\Cache\Store;
use Fer\AiReady\MarkdownUrl;
use Fer\AiReady\Publication;
use Fer\AiReady\Settings;

/**
 * Adds an exclude checkbox to the editor and saves it.
 *
 * The checkbox is an exclusion, not an override that can publish a private
 * or password-protected document. Quick edit does not send the nonce, so it
 * leaves the stored value alone.
 *
 * @since 1.0.0
 */
final class MetaBox {
	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Eligibility policy.
	 *
	 * @since 1.0.0
	 *
	 * @var Publication
	 */
	private $publication;

	/**
	 * Cache store.
	 *
	 * @since 1.0.0
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings    $settings    Settings reader.
	 * @param Publication $publication Eligibility policy.
	 * @param Store       $store       Cache store.
	 */
	public function __construct( Settings $settings, Publication $publication, Store $store ) {
		$this->settings    = $settings;
		$this->publication = $publication;
		$this->store       = $store;
	}

	/**
	 * Register the meta box for selectable post types.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add() {
		foreach ( array_keys( \Fer\AiReady\PostTypes::selectable() ) as $type ) {
			add_meta_box(
				'ai-ready-wp-exclude',
				__( 'AI Ready', 'ai-ready-wp' ),
				array( $this, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$excluded     = '1' === (string) get_post_meta( $post->ID, Publication::EXCLUDE_META, true );
		$effective    = $this->publication->is_discoverable_post( $post );
		$explanation  = $this->publication->explanation( $post );
		$markdown_url = '';
		if ( $this->publication->is_public_post( $post ) && ! empty( $this->settings->all()['enable_markdown'] ) ) {
			$markdown_url = MarkdownUrl::from_permalink( (string) get_permalink( $post ), home_url( '/' ) );
		}
		$effective_label = $effective ? __( 'Listed in llms.txt', 'ai-ready-wp' ) : __( 'Not listed', 'ai-ready-wp' );
		wp_nonce_field( 'ai_ready_wp_exclude', 'ai_ready_wp_exclude_nonce' );
		require AI_READY_WP_PATH . 'views/meta-box.php';
	}

	/**
	 * Save the checkbox and invalidate caches for an edited document.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $this->publication->supports_meta_box( $post_id ) ) {
			return;
		}

		$has_nonce = isset( $_POST['ai_ready_wp_exclude_nonce'] ) && is_string( $_POST['ai_ready_wp_exclude_nonce'] );
		if ( $has_nonce && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ai_ready_wp_exclude_nonce'] ) ), 'ai_ready_wp_exclude' ) ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				$exclude = isset( $_POST['ai_ready_wp_exclude'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_ready_wp_exclude'] ) ) : '0';
				if ( '1' === $exclude ) {
					update_post_meta( $post_id, Publication::EXCLUDE_META, '1' );
				} else {
					delete_post_meta( $post_id, Publication::EXCLUDE_META );
				}
			}
		}

		$this->store->invalidate_post( (int) $post_id );
	}
}
