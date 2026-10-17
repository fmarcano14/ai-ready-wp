<?php
/**
 * Database storage for generated output. Routes are the only way to read it.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * llms.txt and llms-full.txt are options. Each document's Markdown is post meta.
 * Neither is autoloaded. Nothing here is a file the web server can fetch.
 */
final class Store {

	public const INDEX_OPTION = 'ai_ready_llms_txt';

	public const FULL_OPTION = 'ai_ready_llms_full';

	public const GENERATED_OPTION = 'ai_ready_generated_at';

	public const EPOCH_OPTION = 'ai_ready_cache_epoch';

	public const ERROR_TRANSIENT = 'ai_ready_last_error';

	public const META_MARKDOWN = '_ai_ready_markdown';

	public const META_STAMP = '_ai_ready_stamp';

	public const META_NOTE = '_ai_ready_note';

	public const META_CONVERTER = '_ai_ready_converter';

	public static function open(): self {
		return new self();
	}

	/**
	 * Directories older versions wrote. Removed only after a confirmed write.
	 *
	 * @return string[]
	 */
	public static function legacy_directories(): array {
		$home = defined( 'ABSPATH' ) ? rtrim( (string) ABSPATH, '/\\' ) : '';
		$key  = substr( hash( 'sha256', $home . '|ai-ready-wp' ), 0, 16 );
		$name = 'ai-ready-' . $key;
		$dirs = array();
		if ( '' !== $home ) {
			$dirs[] = dirname( $home ) . '/' . $name;
		}
		$dirs[] = rtrim( sys_get_temp_dir(), '/\\' ) . '/' . $name;
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
				$dirs[] = rtrim( (string) $uploads['basedir'], '/\\' ) . '/ai-ready';
			}
		}
		return array_values( array_unique( $dirs ) );
	}

	/**
	 * Swap in the new copy, confirm it, then drop anything it replaces.
	 *
	 * A failed step returns WP_Error and leaves the previous copy in place.
	 *
	 * @param Document[]                        $documents Documents to keep.
	 * @param array<int, array<string, string>> $cache     Per-document reuse records.
	 * @return true|\WP_Error
	 */
	public function replace( string $index, ?string $full, array $documents, array $cache = array() ) {
		$kept = array();
		foreach ( $documents as $document ) {
			if ( ! $document instanceof Document || $document->id() < 1 ) {
				continue;
			}
			$id        = $document->id();
			$row       = ( isset( $cache[ $id ] ) && is_array( $cache[ $id ] ) ) ? $cache[ $id ] : array();
			$stamp     = ( isset( $row['stamp'] ) && is_string( $row['stamp'] ) ) ? $row['stamp'] : '';
			$note      = ( isset( $row['note'] ) && is_string( $row['note'] ) ) ? $row['note'] : $document->note();
			$converter = ( isset( $row['converter'] ) && is_string( $row['converter'] ) ) ? $row['converter'] : '';
			$written   = $this->write_meta( $id, self::META_MARKDOWN, $document->markdown() )
				&& $this->write_meta( $id, self::META_STAMP, $stamp )
				&& $this->write_meta( $id, self::META_NOTE, $note )
				&& $this->write_meta( $id, self::META_CONVERTER, $converter );
			if ( ! $written ) {
				return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save a document.', 'ai-ready-wp' ) );
			}
			$kept[] = $id;
		}

		if ( ! $this->write_option( self::INDEX_OPTION, $index ) ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save llms.txt.', 'ai-ready-wp' ) );
		}
		if ( null === $full ) {
			$full_ok = $this->clear_option( self::FULL_OPTION );
		} else {
			$full_ok = $this->write_option( self::FULL_OPTION, $full );
		}
		if ( ! $full_ok ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save llms-full.txt.', 'ai-ready-wp' ) );
		}
		if ( ! $this->write_option( self::GENERATED_OPTION, (string) time() ) ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save the generation time.', 'ai-ready-wp' ) );
		}
		if ( ! $this->delete_stale( $kept ) ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not remove the previous documents.', 'ai-ready-wp' ) );
		}
		if ( ! $this->remove_legacy() ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not remove the previous files.', 'ai-ready-wp' ) );
		}
		delete_transient( self::ERROR_TRANSIENT );
		return true;
	}

	public function read_index(): ?string {
		return $this->read_option( self::INDEX_OPTION );
	}

	public function read_full(): ?string {
		return $this->read_option( self::FULL_OPTION );
	}

	/**
	 * @return array{stamp: string, markdown: string, note: string, converter: string}|null
	 */
	public function read_cache( int $id ): ?array {
		if ( $id < 1 ) {
			return null;
		}
		$stamp     = get_post_meta( $id, self::META_STAMP, true );
		$markdown  = get_post_meta( $id, self::META_MARKDOWN, true );
		$note      = get_post_meta( $id, self::META_NOTE, true );
		$converter = get_post_meta( $id, self::META_CONVERTER, true );
		if ( ! is_string( $stamp ) || '' === $stamp || ! is_string( $markdown ) || ! is_string( $note ) ) {
			return null;
		}
		return array(
			'stamp'     => $stamp,
			'markdown'  => $markdown,
			'note'      => $note,
			'converter' => is_string( $converter ) ? $converter : '',
		);
	}

	public function read_document( int $id ): ?string {
		if ( $id < 1 ) {
			return null;
		}
		$value = get_post_meta( $id, self::META_MARKDOWN, true );
		if ( ! is_string( $value ) || ! metadata_exists( 'post', $id, self::META_MARKDOWN ) ) {
			return null;
		}
		return $value;
	}

	public function has_index(): bool {
		return is_string( get_option( self::INDEX_OPTION, null ) );
	}

	public function has_full(): bool {
		return is_string( get_option( self::FULL_OPTION, null ) );
	}

	public function generated_at(): int {
		return (int) get_option( self::GENERATED_OPTION, 0 );
	}

	public function cache_epoch(): int {
		return (int) get_option( self::EPOCH_OPTION, 0 );
	}

	public function bump_cache_epoch(): void {
		update_option( self::EPOCH_OPTION, (string) ( $this->cache_epoch() + 1 ), false );
	}

	/**
	 * @return int[]
	 */
	public function document_ids(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		// The id list is the source of truth for this generation, so it is not cached.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_MARKDOWN
			)
		);
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		sort( $clean );
		return $clean;
	}

	/**
	 * Drop generated rows and any leftover directories from older versions.
	 */
	public function destroy(): void {
		delete_option( self::INDEX_OPTION );
		delete_option( self::FULL_OPTION );
		delete_option( self::GENERATED_OPTION );
		delete_option( self::EPOCH_OPTION );
		delete_transient( self::ERROR_TRANSIENT );
		delete_metadata( 'post', 0, self::META_MARKDOWN, '', true );
		delete_metadata( 'post', 0, self::META_STAMP, '', true );
		delete_metadata( 'post', 0, self::META_NOTE, '', true );
		delete_metadata( 'post', 0, self::META_CONVERTER, '', true );
		$this->remove_legacy();
	}

	private function read_option( string $name ): ?string {
		$value = get_option( $name, null );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * update_option() is false both when the write fails and when the value did not change.
	 * The read-back is the confirmation.
	 */
	private function write_option( string $name, string $value ): bool {
		if ( $this->blocked( $name ) ) {
			return false;
		}
		update_option( $name, $value, false );
		$stored = get_option( $name, null );
		return is_string( $stored ) && $stored === $value;
	}

	private function clear_option( string $name ): bool {
		if ( $this->blocked( $name ) ) {
			return false;
		}
		delete_option( $name );
		return null === $this->read_option( $name );
	}

	private function write_meta( int $id, string $key, string $value ): bool {
		if ( $this->blocked( $key ) ) {
			return false;
		}
		// update_post_meta() unslashes. Slash first so Markdown backslashes survive the round trip.
		update_post_meta( $id, $key, wp_slash( $value ) );
		if ( ! metadata_exists( 'post', $id, $key ) ) {
			return false;
		}
		$stored = get_post_meta( $id, $key, true );
		return is_string( $stored ) && $stored === $value;
	}

	/**
	 * @param int[] $keep Document ids that belong to the new copy.
	 */
	private function delete_stale( array $keep ): bool {
		$keep_map = array();
		foreach ( $keep as $id ) {
			$keep_map[ (int) $id ] = true;
		}
		foreach ( $this->document_ids() as $id ) {
			if ( isset( $keep_map[ $id ] ) ) {
				continue;
			}
			if ( $this->blocked( 'delete' ) ) {
				return false;
			}
			foreach ( array( self::META_MARKDOWN, self::META_STAMP, self::META_NOTE, self::META_CONVERTER ) as $key ) {
				delete_post_meta( $id, $key );
				if ( metadata_exists( 'post', $id, $key ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Older builds wrote a directory outside the web root, or under uploads.
	 * A path this process cannot see (open_basedir) is not ours to delete.
	 */
	private function remove_legacy(): bool {
		if ( $this->blocked( 'legacy' ) ) {
			return false;
		}
		foreach ( self::legacy_directories() as $dir ) {
			if ( ! $this->remove_tree( $dir ) ) {
				return false;
			}
		}
		return true;
	}

	private function remove_tree( string $dir ): bool {
		if ( ! @is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warns when the parent is hidden.
			return true;
		}
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same open_basedir boundary as is_dir.
		if ( ! is_array( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( @is_dir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! $this->remove_tree( $path ) ) {
					return false;
				}
				continue;
			}
			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $path );
			} elseif ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return false;
			}
			if ( file_exists( $path ) ) {
				return false;
			}
		}
		if ( ! @rmdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			return ! @is_dir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}

	/**
	 * Tests set this filter to force one write to fail.
	 */
	private function blocked( string $key ): bool {
		return (bool) apply_filters( 'ai_ready_block_store', false, $key );
	}
}
