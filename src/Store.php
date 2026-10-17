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
 * llms.txt is one option. Each document's Markdown is post meta.
 * llms-full.txt is not stored. It is streamed from that meta.
 * Nothing here is autoloaded, and nothing is a file the web server can fetch.
 */
final class Store {

	public const INDEX_OPTION = 'ai_ready_llms_txt';

	public const FULL_OPTION = 'ai_ready_llms_full';

	public const GENERATED_OPTION = 'ai_ready_generated_at';

	public const STAMP_OPTION = 'ai_ready_build_stamp';

	public const SCHEMA_OPTION = 'ai_ready_schema';

	public const EPOCH_OPTION = 'ai_ready_cache_epoch';

	public const ERROR_OPTION = 'ai_ready_last_error';

	public const ERROR_TRANSIENT = 'ai_ready_last_error';

	public const META_MARKDOWN = '_ai_ready_markdown';

	public const META_STAMP = '_ai_ready_stamp';

	public const META_NOTE = '_ai_ready_note';

	public const META_CONVERTER = '_ai_ready_converter';

	public const META_POSITION = '_ai_ready_position';

	public const META_NEXT_MARKDOWN = '_ai_ready_next_markdown';

	public const META_NEXT_STAMP = '_ai_ready_next_stamp';

	public const META_NEXT_NOTE = '_ai_ready_next_note';

	public const META_NEXT_CONVERTER = '_ai_ready_next_converter';

	public const META_NEXT_POSITION = '_ai_ready_next_position';

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
	 * Write one document into the staging meta. The live copy stays until commit().
	 *
	 * @return true|\WP_Error
	 */
	public function stage( int $id, string $markdown, string $stamp, string $note, string $converter, int $position ) {
		if ( $id < 1 ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save a document.', 'ai-ready-wp' ) );
		}
		$written = $this->write_meta( $id, self::META_NEXT_MARKDOWN, $markdown )
			&& $this->write_meta( $id, self::META_NEXT_STAMP, $stamp )
			&& $this->write_meta( $id, self::META_NEXT_NOTE, $note )
			&& $this->write_meta( $id, self::META_NEXT_CONVERTER, $converter )
			&& $this->write_meta( $id, self::META_NEXT_POSITION, (string) $position );
		if ( ! $written ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save a document.', 'ai-ready-wp' ) );
		}
		return true;
	}

	/**
	 * Publish staged documents, then the index. llms-full.txt is not stored.
	 *
	 * A failed step returns WP_Error and leaves the previous index in place
	 * when the failure happens before that write.
	 *
	 * @param int[] $ids Document ids in display order. Their staged meta is already written.
	 * @return true|\WP_Error
	 */
	public function commit( string $index, array $ids ) {
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		if ( ! $this->swap( $clean ) ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save a document.', 'ai-ready-wp' ) );
		}
		if ( ! $this->write_option( self::INDEX_OPTION, $index ) ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save llms.txt.', 'ai-ready-wp' ) );
		}
		$when = (string) time();
		if ( ! $this->write_option( self::GENERATED_OPTION, $when ) ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save the generation time.', 'ai-ready-wp' ) );
		}
		$stamp = hash( 'sha256', $index . '|' . (string) count( $clean ) . '|' . $when );
		if ( ! $this->write_option( self::STAMP_OPTION, $stamp ) ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not save the generation time.', 'ai-ready-wp' ) );
		}
		if ( ! $this->delete_stale( $clean ) ) {
			return new \WP_Error( 'ai_ready_store_failed', __( 'AI Ready could not remove the previous documents.', 'ai-ready-wp' ) );
		}
		delete_option( self::FULL_OPTION );
		$this->remove_legacy();
		delete_transient( self::ERROR_TRANSIENT );
		return true;
	}

	public function read_index(): ?string {
		return $this->read_option( self::INDEX_OPTION );
	}

	public function read_full(): ?string {
		if ( ! $this->has_full() ) {
			return null;
		}
		$preamble = ( new Generator() )->preamble();
		$body     = '';
		$this->stream_full(
			$preamble,
			static function ( $chunk ) use ( &$body ) {
				$body .= $chunk;
			}
		);
		return $body;
	}

	/**
	 * Echo llms-full.txt in small pieces. The caller writes each chunk and drops it.
	 *
	 * @param callable $write Receives one string chunk.
	 */
	public function stream_full( string $preamble, $write ): void {
		if ( ! is_callable( $write ) ) {
			return;
		}
		$write( rtrim( $preamble ) );
		$after = -1;
		$count = 0;
		$limit = Generator::MAX_ITEMS;
		while ( $count < $limit ) {
			$batch = $this->positions_after( $after, Generator::BATCH_SIZE );
			if ( array() === $batch ) {
				break;
			}
			foreach ( $batch as $row ) {
				if ( $count >= $limit ) {
					break 2;
				}
				$markdown = $this->markdown_column( (int) $row['id'] );
				$piece    = "\n\n" . rtrim( $markdown );
				unset( $markdown );
				$write( $piece );
				unset( $piece );
				$after = $row['position'];
				++$count;
			}
		}
		$write( "\n" );
	}

	public function etag(): string {
		$stamp = get_option( self::STAMP_OPTION, '' );
		if ( ! is_string( $stamp ) || '' === $stamp ) {
			$stamp = hash( 'sha256', (string) $this->generated_at() );
		}
		return '"' . $stamp . '"';
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
		if ( ! $this->has_index() ) {
			return false;
		}
		return Settings::current()->write_full();
	}

	/**
	 * @return array{message: string, time: int}|null
	 */
	public function last_error(): ?array {
		$stored = get_option( self::ERROR_OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['message'], $stored['time'] ) ) {
			return null;
		}
		if ( ! is_string( $stored['message'] ) || '' === $stored['message'] ) {
			return null;
		}
		return array(
			'message' => $stored['message'],
			'time'    => (int) $stored['time'],
		);
	}

	public function record_error( string $message ): void {
		if ( '' === $message ) {
			$message = __( 'AI Ready could not save the generated output.', 'ai-ready-wp' );
		}
		update_option(
			self::ERROR_OPTION,
			array(
				'message' => $message,
				'time'    => time(),
			),
			false
		);
	}

	public function clear_error(): void {
		delete_option( self::ERROR_OPTION );
		delete_transient( self::ERROR_TRANSIENT );
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
		delete_option( self::STAMP_OPTION );
		delete_option( self::SCHEMA_OPTION );
		delete_option( self::EPOCH_OPTION );
		delete_option( self::ERROR_OPTION );
		delete_transient( self::ERROR_TRANSIENT );
		foreach ( $this->meta_keys() as $key ) {
			delete_metadata( 'post', 0, $key, '', true );
		}
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
			foreach ( $this->meta_keys() as $key ) {
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
	 * A folder that cannot be removed is a warning. The published text is already saved.
	 */
	private function remove_legacy(): void {
		foreach ( self::legacy_directories() as $dir ) {
			if ( $this->blocked( 'legacy' ) || ! $this->remove_tree( $dir ) ) {
				$this->warn( 'Could not remove an old generated folder at ' . $dir );
			}
		}
	}

	private function warn( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- leftover cleanup must not fail the rebuild.
		error_log( 'AI Ready WP: ' . $message );
	}

	/**
	 * @param int[] $ids Ids in display order.
	 */
	private function swap( array $ids ): bool {
		$position = 0;
		foreach ( $ids as $id ) {
			$markdown  = get_post_meta( $id, self::META_NEXT_MARKDOWN, true );
			$stamp     = get_post_meta( $id, self::META_NEXT_STAMP, true );
			$note      = get_post_meta( $id, self::META_NEXT_NOTE, true );
			$converter = get_post_meta( $id, self::META_NEXT_CONVERTER, true );
			if ( ! is_string( $markdown ) || ! metadata_exists( 'post', $id, self::META_NEXT_MARKDOWN ) ) {
				return false;
			}
			if ( ! is_string( $stamp ) || ! is_string( $note ) || ! is_string( $converter ) ) {
				return false;
			}
			$written = $this->write_meta( $id, self::META_MARKDOWN, $markdown )
				&& $this->write_meta( $id, self::META_STAMP, $stamp )
				&& $this->write_meta( $id, self::META_NOTE, $note )
				&& $this->write_meta( $id, self::META_CONVERTER, $converter )
				&& $this->write_meta( $id, self::META_POSITION, (string) $position );
			unset( $markdown );
			if ( ! $written ) {
				return false;
			}
			foreach ( array( self::META_NEXT_MARKDOWN, self::META_NEXT_STAMP, self::META_NEXT_NOTE, self::META_NEXT_CONVERTER, self::META_NEXT_POSITION ) as $key ) {
				delete_post_meta( $id, $key );
			}
			++$position;
		}
		return true;
	}

	/**
	 * One Markdown column, then drop the query buffer so the next row can reuse it.
	 */
	private function markdown_column( int $id ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || $id < 1 ) {
			$value = get_post_meta( $id, self::META_MARKDOWN, true );
			return is_string( $value ) ? $value : '';
		}
		$value             = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$id,
				self::META_MARKDOWN
			)
		);
		$wpdb->last_result = array();
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $id, 'post_meta' );
		}
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @return array<int, array{id: int, position: int}>
	 */
	private function positions_after( int $after, int $limit ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || $limit < 1 ) {
			return array();
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d ORDER BY CAST(meta_value AS UNSIGNED) ASC LIMIT %d",
				self::META_POSITION,
				$after,
				$limit
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$batch = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->post_id ) ) {
				continue;
			}
			$batch[] = array(
				'id'       => (int) $row->post_id,
				'position' => isset( $row->meta_value ) ? (int) $row->meta_value : 0,
			);
		}
		$wpdb->last_result = array();
		unset( $rows );
		return $batch;
	}

	/**
	 * @return string[]
	 */
	private function meta_keys(): array {
		return array(
			self::META_MARKDOWN,
			self::META_STAMP,
			self::META_NOTE,
			self::META_CONVERTER,
			self::META_POSITION,
			self::META_NEXT_MARKDOWN,
			self::META_NEXT_STAMP,
			self::META_NEXT_NOTE,
			self::META_NEXT_CONVERTER,
			self::META_NEXT_POSITION,
		);
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
