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

	public const PENDING_OPTION = 'ai_ready_full_pending';

	public const LOCK_OPTION = 'ai_ready_rebuild_lock';

	public const LOCK_TTL = 900;

	/**
	 * Token for the lock held by this process.
	 *
	 * @var string
	 */
	private static string $lock_token = '';

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
		delete_option( self::PENDING_OPTION );
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
		$budget = (int) apply_filters( 'ai_ready_full_bytes', Generator::FULL_BYTES );
		if ( $budget < 1 ) {
			$budget = Generator::FULL_BYTES;
		}
		$head = rtrim( $preamble );
		if ( strlen( $head ) >= $budget ) {
			$write( substr( $head, 0, $budget ) );
			return;
		}
		$write( $head );
		$sent     = strlen( $head );
		$after    = -1;
		$count    = 0;
		$limit    = Generator::MAX_ITEMS;
		$settings = Settings::current();
		while ( $count < $limit && $sent < $budget ) {
			$batch = $this->positions_after( $after, Generator::BATCH_SIZE );
			if ( array() === $batch ) {
				break;
			}
			$visible = $this->live_public_ids( $batch, $settings );
			foreach ( $batch as $row ) {
				$after = $row['position'];
				if ( $count >= $limit || $sent >= $budget ) {
					break 2;
				}
				if ( ! isset( $visible[ $row['id'] ] ) ) {
					continue;
				}
				$markdown = $this->markdown_column( (int) $row['id'] );
				$piece    = "\n\n" . rtrim( $markdown );
				unset( $markdown );
				if ( ( $sent + strlen( $piece ) ) > $budget ) {
					break 2;
				}
				$write( $piece );
				$sent += strlen( $piece );
				unset( $piece );
				++$count;
			}
		}
		if ( $sent < $budget ) {
			$write( "\n" );
		}
	}

	public function etag(): string {
		$stamp = get_option( self::STAMP_OPTION, '' );
		if ( ! is_string( $stamp ) || '' === $stamp ) {
			$stamp = hash( 'sha256', (string) $this->generated_at() );
		}
		$preamble = ( new Generator() )->preamble();
		return '"' . hash( 'sha256', $stamp . "\n" . $preamble ) . '"';
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

	/**
	 * Remove one document from the live files without waiting for a rebuild.
	 */
	public function forget( \WP_Post $post ): void {
		$this->forget_id( (int) $post->ID );
		$needles = array();
		$url     = Urls::markdown( $post );
		if ( '' !== $url ) {
			$needles[] = $url;
		}
		$slug = (string) $post->post_name;
		if ( '' !== $slug ) {
			$needles[] = '/' . $slug . '.md';
		}
		if ( Urls::is_front_page( $post ) ) {
			$needles[] = '/index.md';
		}
		$this->remove_index_needles( $needles );
	}

	/**
	 * Delete stored Markdown and position for one post. Safe when the row is already gone.
	 */
	public function forget_id( int $id ): void {
		if ( $id < 1 ) {
			return;
		}
		foreach ( $this->meta_keys() as $key ) {
			delete_post_meta( $id, $key );
		}
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

	public function full_pending(): bool {
		return '1' === (string) get_option( self::PENDING_OPTION, '' );
	}

	/**
	 * One rebuild at a time. An expired lock can be taken over.
	 */
	public function acquire_lock(): bool {
		$token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 12, false ) : substr( hash( 'sha256', (string) microtime( true ) ), 0, 12 );
		$until = time() + self::LOCK_TTL;
		$value = $token . '|' . (string) $until;
		if ( add_option( self::LOCK_OPTION, $value, '', 'no' ) ) {
			self::$lock_token = $token;
			return true;
		}
		$current = get_option( self::LOCK_OPTION, '' );
		$expires = 0;
		if ( is_string( $current ) && false !== strpos( $current, '|' ) ) {
			$expires = (int) substr( $current, (int) strrpos( $current, '|' ) + 1 );
		}
		if ( $expires >= time() ) {
			return false;
		}
		update_option( self::LOCK_OPTION, $value, false );
		$check = get_option( self::LOCK_OPTION, '' );
		if ( $check !== $value ) {
			return false;
		}
		self::$lock_token = $token;
		return true;
	}

	public function release_lock(): void {
		if ( '' === self::$lock_token ) {
			return;
		}
		$current = get_option( self::LOCK_OPTION, '' );
		if ( is_string( $current ) && 0 === strpos( $current, self::$lock_token . '|' ) ) {
			delete_option( self::LOCK_OPTION );
		}
		self::$lock_token = '';
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
		delete_option( self::PENDING_OPTION );
		delete_option( self::LOCK_OPTION );
		self::$lock_token = '';
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
			if ( ! $this->publish_id( $id, $position ) ) {
				return false;
			}
			++$position;
		}
		return true;
	}

	/**
	 * Staged rows replace the live copy. An unchanged document only updates its position.
	 */
	private function publish_id( int $id, int $position ): bool {
		if ( ! metadata_exists( 'post', $id, self::META_NEXT_MARKDOWN ) ) {
			if ( ! metadata_exists( 'post', $id, self::META_MARKDOWN ) ) {
				return false;
			}
			$current = get_post_meta( $id, self::META_POSITION, true );
			if ( metadata_exists( 'post', $id, self::META_POSITION ) && (string) $position === (string) $current ) {
				return true;
			}
			return $this->write_meta( $id, self::META_POSITION, (string) $position );
		}
		$markdown  = get_post_meta( $id, self::META_NEXT_MARKDOWN, true );
		$stamp     = get_post_meta( $id, self::META_NEXT_STAMP, true );
		$note      = get_post_meta( $id, self::META_NEXT_NOTE, true );
		$converter = get_post_meta( $id, self::META_NEXT_CONVERTER, true );
		if ( ! is_string( $markdown ) || ! is_string( $stamp ) || ! is_string( $note ) || ! is_string( $converter ) ) {
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
		return true;
	}

	/**
	 * Ids in this batch that are still published, unprotected, and included.
	 * Status and the small exclusion keys are read here. The Markdown body is not.
	 *
	 * @param array<int, array{id: int, position: int}> $batch    Position rows.
	 * @param Settings                                  $settings Current settings.
	 * @return array<int, true>
	 */
	private function live_public_ids( array $batch, Settings $settings ): array {
		global $wpdb;
		$ids = array();
		foreach ( $batch as $row ) {
			$id = (int) $row['id'];
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( array() === $ids || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$placeholders      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql               = "SELECT ID, post_status, post_password, post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are %d tokens, finished by prepare below.
		$prepared          = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $ids ) );
		$posts             = $wpdb->get_results( $prepared ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->last_result = array();
		if ( ! is_array( $posts ) ) {
			return array();
		}
		$flags = $this->exclusion_flags( $ids );
		$allow = array();
		$types = $settings->post_types();
		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
				continue;
			}
			$id = (int) $post->ID;
			if ( 'publish' !== (string) $post->post_status || '' !== (string) $post->post_password ) {
				continue;
			}
			$type   = (string) $post->post_type;
			$object = get_post_type_object( $type );
			if ( ! $object instanceof \WP_Post_Type || empty( $object->public ) || ! in_array( $type, $types, true ) ) {
				continue;
			}
			$meta = isset( $flags[ $id ] ) ? $flags[ $id ] : array();
			if ( isset( $meta[ ExcludeMeta::META_KEY ] ) && '1' === (string) $meta[ ExcludeMeta::META_KEY ] ) {
				continue;
			}
			if ( Noindex::detect( $meta ) ) {
				continue;
			}
			$allow[ $id ] = true;
		}
		unset( $posts, $flags );
		return $allow;
	}

	/**
	 * Exclusion and noindex values for a batch, without loading Markdown meta.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, array<string, string>>
	 */
	private function exclusion_flags( array $ids ): array {
		global $wpdb;
		$keys              = array_merge( array( ExcludeMeta::META_KEY ), Noindex::keys() );
		$id_tokens         = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$key_tokens        = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql               = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_tokens) AND meta_key IN ($key_tokens)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are finished by prepare below.
		$prepared          = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $ids, $keys ) );
		$rows              = $wpdb->get_results( $prepared ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->last_result = array();
		$grouped           = array();
		if ( ! is_array( $rows ) ) {
			return $grouped;
		}
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->post_id, $row->meta_key ) ) {
				continue;
			}
			$grouped[ (int) $row->post_id ][ (string) $row->meta_key ] = isset( $row->meta_value ) ? (string) $row->meta_value : '';
		}
		unset( $rows );
		return $grouped;
	}

	/**
	 * @param string[] $needles URLs or path suffixes that identify one document.
	 */
	private function remove_index_needles( array $needles ): void {
		$index = $this->read_index();
		if ( ! is_string( $index ) || array() === $needles ) {
			return;
		}
		$kept    = array();
		$changed = false;
		foreach ( explode( "\n", $index ) as $line ) {
			$drop = false;
			foreach ( $needles as $needle ) {
				if ( '' !== $needle && false !== strpos( $line, $needle ) ) {
					$drop = true;
					break;
				}
			}
			if ( $drop ) {
				$changed = true;
				continue;
			}
			$kept[] = $line;
		}
		if ( ! $changed ) {
			return;
		}
		$updated = rtrim( implode( "\n", $kept ) ) . "\n";
		if ( ! $this->write_option( self::INDEX_OPTION, $updated ) ) {
			return;
		}
		$this->write_option( self::STAMP_OPTION, hash( 'sha256', $updated . '|' . (string) time() ) );
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
