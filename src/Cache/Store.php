<?php
/**
 * Transient and file cache for generated documents.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Cache;

use Fer\AiReady\Content\Renderer;
use Fer\AiReady\Outputs;
use Fer\AiReady\Sanitizer;
use Fer\AiReady\Settings;

/**
 * Stores llms files and per-document Markdown.
 *
 * Public callers must decide eligibility before asking for a cached document.
 * Invalidation deletes cached bodies and files. It does not delete a generation
 * lock held by another request. A lock older than 30 seconds is treated as
 * abandoned and may be replaced.
 *
 * @since 1.0.0
 */
final class Store {
	const META = 'ai_ready_wp_cache_meta';

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
	 * Cached Markdown for a post, generating it when needed.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post     Eligible post.
	 * @param Renderer $renderer Renderer used on a cache miss.
	 * @return string|\WP_Error
	 */
	public function markdown_for( \WP_Post $post, Renderer $renderer ) {
		$fingerprint = $this->fingerprint( $post );
		$key         = $this->markdown_key( $post->ID );
		$cached      = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached[ $fingerprint ] ) && is_string( $cached[ $fingerprint ] ) ) {
			return $cached[ $fingerprint ];
		}

		$lock = $this->markdown_lock_key( $post->ID );
		if ( ! $this->acquire( $lock ) ) {
			return new \WP_Error( 'ai_ready_wp_busy', __( 'Markdown is being generated. Please retry shortly.', 'ai-ready-wp' ) );
		}

		try {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached[ $fingerprint ] ) && is_string( $cached[ $fingerprint ] ) ) {
				return $cached[ $fingerprint ];
			}
			$body = $renderer->render_post( $post );
			if ( strlen( $body ) > Sanitizer::MAX_DOCUMENT_BYTES ) {
				return new \WP_Error( 'ai_ready_wp_too_large', __( 'The Markdown representation exceeds the output limit.', 'ai-ready-wp' ) );
			}
			set_transient( $key, array( $fingerprint => $body ), $this->ttl() );
			return $body;
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Read a cached index file.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $full Whether to read llms-full.txt.
	 * @return string|null
	 */
	public function get_index( $full = false ) {
		$key    = $full ? $this->full_key() : $this->llms_key();
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$file = $this->read_file( $full ? 'llms-full.txt' : 'llms.txt' );
		if ( is_string( $file ) && '' !== $file ) {
			set_transient( $key, $file, $this->ttl() );
			return $file;
		}
		return null;
	}

	/**
	 * Store both index files and the status metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $llms    llms.txt body.
	 * @param string               $full    llms-full.txt body. Empty removes that file.
	 * @param array<string, mixed> $meta    Status metadata.
	 * @param bool                 $publish Whether the files may be stored where the web server can read them.
	 * @return void
	 */
	public function put_indexes( $llms, $full, array $meta, $publish = true ) {
		if ( ! $publish ) {
			$this->invalidate_index();
			$meta['generated_at'] = time();
			$meta['llms_bytes']   = 0;
			$meta['full_bytes']   = 0;
			$meta['published']    = false;
			update_option( self::META, $meta, false );
			delete_option( 'ai_ready_wp_last_error' );
			return;
		}
		set_transient( $this->llms_key(), $llms, $this->ttl() );
		$this->write_file( 'llms.txt', $llms );
		if ( '' !== $full ) {
			set_transient( $this->full_key(), $full, $this->ttl() );
			$this->write_file( 'llms-full.txt', $full );
		} else {
			delete_transient( $this->full_key() );
			$this->delete_file( 'llms-full.txt' );
		}
		$meta['generated_at'] = time();
		$meta['llms_bytes']   = strlen( $llms );
		$meta['full_bytes']   = strlen( $full );
		$meta['published']    = true;
		update_option( self::META, $meta, false );
		delete_option( 'ai_ready_wp_last_error' );
	}

	/**
	 * Drop cached index files.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function invalidate_index() {
		delete_transient( $this->llms_key() );
		delete_transient( $this->full_key() );
		$this->delete_file( 'llms.txt' );
		$this->delete_file( 'llms-full.txt' );
	}

	/**
	 * Drop one document and the index that lists it.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function invalidate_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return;
		}
		delete_transient( $this->markdown_key( $post_id ) );
		$this->invalidate_index();
	}

	/**
	 * Try to acquire the index generation lock.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function acquire_index_lock() {
		return $this->acquire( $this->index_lock_key() );
	}

	/**
	 * Release the index generation lock.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function release_index_lock() {
		delete_option( $this->index_lock_key() );
	}

	/**
	 * Saved generation metadata.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function meta() {
		$meta = get_option( self::META, array() );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Cache lifetime in seconds.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function ttl() {
		$settings = $this->settings->all();
		$ttl      = isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : HOUR_IN_SECONDS;
		$ttl      = (int) apply_filters( 'ai_ready_wp_cache_ttl', $ttl );
		return max( MINUTE_IN_SECONDS, min( 30 * DAY_IN_SECONDS, $ttl ) );
	}

	/**
	 * Absolute directory used for the file copy, or an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function directory() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'ai-ready-wp';
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$index = $dir . '/index.php';
		if ( ! is_file( $index ) ) {
			$this->write( $index, "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Fingerprint of the inputs that affect one document.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function fingerprint( \WP_Post $post ) {
		return hash(
			'sha256',
			implode(
				'|',
				array(
					(string) get_current_blog_id(),
					AI_READY_WP_VERSION,
					(string) $post->ID,
					(string) $post->post_modified_gmt,
					hash( 'sha256', $post->post_title . '|' . $post->post_excerpt . '|' . $post->post_content ),
				)
			)
		);
	}

	/**
	 * Transient key for one document.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function markdown_key( $post_id ) {
		return 'ai_ready_wp_md_' . get_current_blog_id() . '_' . (int) $post_id;
	}

	/**
	 * Lock option for one document.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function markdown_lock_key( $post_id ) {
		return 'ai_ready_wp_lock_md_' . get_current_blog_id() . '_' . (int) $post_id;
	}

	/**
	 * Transient key for llms.txt.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function llms_key() {
		return 'ai_ready_wp_llms_' . get_current_blog_id();
	}

	/**
	 * Transient key for llms-full.txt.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function full_key() {
		return 'ai_ready_wp_full_' . get_current_blog_id();
	}

	/**
	 * Lock option for index generation.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function index_lock_key() {
		return 'ai_ready_wp_lock_index_' . get_current_blog_id();
	}

	/**
	 * Acquire an option lock, replacing one that is older than 30 seconds.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	private function acquire( $key ) {
		$locked = add_option( $key, time(), '', false );
		if ( ! $locked ) {
			$started = (int) get_option( $key, 0 );
			if ( $started > 0 && $started < time() - 30 ) {
				delete_option( $key );
				$locked = add_option( $key, time(), '', false );
			}
		}
		return (bool) $locked;
	}

	/**
	 * Read a whitelisted cache file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return string|null
	 */
	private function read_file( $name ) {
		$path = $this->path( $name );
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache file in uploads.
		return is_string( $contents ) ? $contents : null;
	}

	/**
	 * Write a whitelisted cache file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name     File name.
	 * @param string $contents Body.
	 * @return void
	 */
	private function write_file( $name, $contents ) {
		$path = $this->path( $name );
		if ( '' === $path ) {
			return;
		}
		$this->write( $path, $contents );
	}

	/**
	 * Delete a whitelisted cache file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return void
	 */
	private function delete_file( $name ) {
		$path = $this->path( $name );
		if ( '' === $path || ! is_file( $path ) ) {
			return;
		}
		wp_delete_file( $path );
	}

	/**
	 * Absolute path for a whitelisted file name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function path( $name ) {
		if ( ! in_array( $name, Outputs::index_names(), true ) ) {
			return '';
		}
		$dir = $this->directory();
		if ( '' === $dir ) {
			return '';
		}
		return $dir . '/' . $name;
	}

	/**
	 * Write a file, preferring the WordPress filesystem API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents Body.
	 * @return void
	 */
	private function write( $path, $contents ) {
		$temporary = $path . '.tmp';
		// A crashed write leaves only the temporary file. The next run replaces it and renames over the published path.
		$written = file_put_contents( $temporary, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Rename publishes the file in one step so readers never see a partial body.
		if ( ! rename( $temporary, $path ) ) {
			wp_delete_file( $temporary );
		}
	}
}
