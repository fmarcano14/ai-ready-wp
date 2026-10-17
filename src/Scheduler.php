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
 * Ordinary edits share one delayed rebuild. Leaving the public set rewrites immediately.
 */
final class Scheduler {

	public const HOOK = 'ai_ready_rebuild';

	public const DELAY = 30;

	/**
	 * Stops a rewrite from starting another rewrite.
	 *
	 * @var bool
	 */
	private static bool $rewriting = false;

	public function register(): void {
		add_action( 'save_post', array( $this, 'queue' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'queue_id' ), 20 );
		add_action( 'wp_trash_post', array( $this, 'queue_id' ), 20 );
		add_action( 'untrash_post', array( $this, 'queue_id' ), 20 );
		add_action( 'transition_post_status', array( $this, 'drop_hidden' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'drop_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'drop_meta' ), 10, 4 );
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
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			Store::from_uploads()->delete_document( $post_id );
			$this->rewrite_now();
			return;
		}
		$this->queue( $post_id, $post );
	}

	/**
	 * Pull a document out of the public files when it is no longer published.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function drop_hidden( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'publish' === $new_status && '' === (string) $post->post_password ) {
			return;
		}
		Store::from_uploads()->delete_document( (int) $post->ID );
		// The title and note would stay public until cron. Drop them in this request.
		if ( 'publish' === $old_status ) {
			$this->rewrite_now();
		}
	}

	/**
	 * @param int    $meta_id Unused meta row ID.
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Unused new value.
	 */
	public function drop_meta( $meta_id, $post_id, $key, $value ): void {
		unset( $meta_id, $value );
		$key = is_string( $key ) ? $key : '';
		if ( ExcludeMeta::META_KEY !== $key && ! Noindex::watches_key( $key ) ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( Inclusion::allowed( Snapshot::from_post( $post ), Settings::current()->post_types() ) ) {
			return;
		}
		$store = Store::from_uploads();
		if ( null === $store->read_document( (int) $post->ID ) ) {
			return;
		}
		$store->delete_document( (int) $post->ID );
		$this->rewrite_now();
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

	/**
	 * Rewrite after a post leaves the public set. Content edits keep the debounce.
	 */
	private function rewrite_now(): void {
		if ( self::$rewriting ) {
			return;
		}
		if ( ! is_user_logged_in() && ! $this->trusted_context() ) {
			return;
		}
		self::$rewriting = true;
		try {
			$this->rebuild();
		} finally {
			self::$rewriting = false;
		}
	}

	private function trusted_context(): bool {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		return defined( 'WP_CLI' ) && WP_CLI;
	}
}
