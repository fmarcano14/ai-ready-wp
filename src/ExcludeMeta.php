<?php
/**
 * Per-document exclude checkbox.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Exclude from AI files" on the post editing screen.
 */
final class ExcludeMeta {

	public const META_KEY = '_ai_ready_exclude';

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'boxes' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	public function boxes(): void {
		$types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! is_array( $types ) ) {
			return;
		}
		unset( $types['attachment'] );
		foreach ( $types as $type ) {
			add_meta_box(
				'ai_ready_exclude',
				__( 'AI Ready', 'ai-ready-wp' ),
				array( $this, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * @param \WP_Post $post Current post.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$on = '1' === (string) get_post_meta( $post->ID, self::META_KEY, true );
		wp_nonce_field( 'ai_ready_exclude_' . $post->ID, 'ai_ready_exclude_nonce' );
		echo '<label for="ai_ready_exclude">';
		echo '<input type="checkbox" id="ai_ready_exclude" name="ai_ready_exclude" value="1" ' . checked( $on, true, false ) . ' /> ';
		echo esc_html__( 'Exclude from AI files', 'ai-ready-wp' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Leave this out of llms.txt and the Markdown view.', 'ai-ready-wp' ) . '</p>';
	}

	/**
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post ): void {
		if ( ! isset( $_POST['ai_ready_exclude_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ai_ready_exclude_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ai_ready_exclude_' . (int) $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( (int) $post_id ) || wp_is_post_autosave( (int) $post_id ) ) {
			return;
		}
		unset( $post );
		$exclude = isset( $_POST['ai_ready_exclude'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_ready_exclude'] ) ) : '';
		if ( '1' === $exclude ) {
			update_post_meta( (int) $post_id, self::META_KEY, '1' );
			return;
		}
		delete_post_meta( (int) $post_id, self::META_KEY );
	}
}
