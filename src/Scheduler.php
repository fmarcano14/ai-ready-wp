<?php
/**
 * Debounce rebuilds onto a single cron event.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save hooks schedule one rebuild. They do not write the public files themselves.
 */
final class Scheduler {

	public const HOOK = 'ai_ready_rebuild';

	public const DELAY = 30;

	public function register(): void {
		add_action( 'save_post', array( $this, 'queue' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'queue_id' ), 20 );
		add_action( 'wp_trash_post', array( $this, 'queue_id' ), 20 );
		add_action( 'untrash_post', array( $this, 'queue_id' ), 20 );
		add_action( 'transition_post_status', array( $this, 'drop_hidden' ), 10, 3 );
		add_action( self::HOOK, array( $this, 'rebuild' ) );
	}

	/**
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object when WordPress has one.
	 */
	public function queue( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( $post instanceof \WP_Post && ! $this->relevant( $post ) ) {
			return;
		}
		// A logged-out request must not even schedule a rebuild.
		if ( ! is_user_logged_in() && ! $this->trusted_context() ) {
			return;
		}
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + self::DELAY, self::HOOK );
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function queue_id( $post_id ): void {
		$post = get_post( (int) $post_id );
		$this->queue( $post_id, $post instanceof \WP_Post ? $post : null );
	}

	/**
	 * Remove a document file as soon as it leaves the public set.
	 * The index is refreshed by the debounced rebuild, not here.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function drop_hidden( $new_status, $old_status, $post ): void {
		unset( $old_status );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'publish' === $new_status && '' === (string) $post->post_password ) {
			return;
		}
		Store::from_uploads()->delete_document( (int) $post->ID );
	}

	public function rebuild(): void {
		Generator::trusted(
			static function () {
				( new Generator() )->run();
			}
		);
	}

	private function relevant( \WP_Post $post ): bool {
		if ( 'attachment' === $post->post_type ) {
			return false;
		}
		$object = get_post_type_object( $post->post_type );
		return $object instanceof \WP_Post_Type && ! empty( $object->public );
	}

	private function trusted_context(): bool {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		return defined( 'WP_CLI' ) && WP_CLI;
	}
}
