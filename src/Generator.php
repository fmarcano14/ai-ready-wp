<?php
/**
 * Build the public files from published posts.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regeneration entry point. Public HTTP requests are not trusted to call run().
 */
final class Generator {

	/**
	 * Documents per query. The rebuild stops after MAX_ITEMS.
	 * 50 per page and fewer than 10,000 pages is the existing cap.
	 */
	public const BATCH_SIZE = 50;

	public const MAX_ITEMS = 499950;

	/**
	 * Bytes left free before a request rebuild hands the rest to cron.
	 */
	private const MEMORY_RESERVE = 33554432;

	/**
	 * True only while admin, cron, or CLI has opened a rebuild.
	 *
	 * @var bool
	 */
	private static bool $trusted = false;

	/**
	 * @param callable $callback Rebuild work.
	 * @return mixed
	 */
	public static function trusted( $callback ) {
		$previous      = self::$trusted;
		self::$trusted = true;
		try {
			return $callback();
		} finally {
			self::$trusted = $previous;
		}
	}

	public static function generation_allowed(): bool {
		if ( self::$trusted ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return function_exists( 'wp_doing_cron' ) && wp_doing_cron();
	}

	/**
	 * Header for llms-full.txt. The body is streamed from post meta.
	 */
	public function preamble(): string {
		$settings = Settings::current();
		return ( new LlmsTxt() )->preamble( $this->site_name(), $this->summary( $settings ), $settings->details() );
	}

	/**
	 * @param bool $fresh True skips the reuse cache. Manual generate and WP-CLI pass true.
	 * @return int|\WP_Error Document count, or the storage error. Never a count when the write failed.
	 */
	public function run( bool $fresh = false ) {
		if ( ! self::generation_allowed() ) {
			return 0;
		}

		$settings          = Settings::current();
		$reader            = new ContentReader();
		$converter         = new HtmlToMarkdown();
		$store             = Store::open();
		$rows              = array();
		$converter_version = $this->converter_version();
		$done              = 0;
		$page_number       = 1;
		$pages             = 1;

		$previous = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			do {
				if ( $this->should_defer( $done ) ) {
					return $this->fail( $this->hand_off() );
				}
				$batch = $this->batch( $settings, $page_number );
				$pages = $batch['pages'];
				foreach ( $batch['posts'] as $post ) {
					if ( $done >= self::MAX_ITEMS ) {
						break 2;
					}
					$facts = Snapshot::from_post( $post );
					if ( ! Inclusion::allowed( $facts, $settings->post_types() ) ) {
						continue;
					}
					$id    = (int) $post->ID;
					$title = $this->title( $post );
					$stamp = $this->stamp( $post, $settings, $facts, $store );
					$prior = $store->read_cache( $id );
					$reuse = ! $fresh && $this->cache_matches( $prior, $stamp, $converter_version );
					if ( $reuse ) {
						$page = $prior['markdown'];
						$note = $prior['note'];
					} else {
						$html = Text::strip_shortcodes( $reader->render( $post ) );
						$body = $converter->convert( $html );
						$page = LlmsTxt::page( $title, $body );
						$note = Text::note( (string) $post->post_excerpt, $html, $settings->note_length() );
						unset( $html, $body );
					}
					$staged = $store->stage( $id, $page, $stamp, $note, $converter_version, $done );
					if ( is_wp_error( $staged ) ) {
						return $this->fail( $staged );
					}
					$rows[] = array(
						'id'       => $id,
						'title'    => $title,
						'html'     => $this->html_url( $post ),
						'markdown' => Urls::markdown( $post ),
						'note'     => $note,
						'section'  => $this->section_label( $post ),
						'optional' => $settings->is_optional( (string) $post->post_type ),
					);
					unset( $page, $note, $prior );
					wp_cache_delete( $id, 'post_meta' );
					++$done;
				}
				unset( $batch );
				++$page_number;
			} while ( $page_number <= $pages && $done < self::MAX_ITEMS );
		} finally {
			wp_set_current_user( $previous );
		}

		$documents = array();
		$ids       = array();
		foreach ( $rows as $row ) {
			$ids[]       = $row['id'];
			$documents[] = new Document(
				$row['id'],
				$row['title'],
				$row['html'],
				$row['markdown'],
				$row['note'],
				'',
				$row['section'],
				$row['optional']
			);
		}
		unset( $rows );
		$catalog = new Catalog( $this->site_name(), $this->summary( $settings ), $settings->details(), $documents );
		$index   = ( new LlmsTxt() )->index( $catalog );
		unset( $documents, $catalog );
		$stored = $store->commit( $index, $ids );
		unset( $index );
		if ( is_wp_error( $stored ) ) {
			return $this->fail( $stored );
		}
		$store->clear_error();
		do_action( 'ai_ready_stored', count( $ids ) );
		return count( $ids );
	}

	private function stamp( \WP_Post $post, Settings $settings, PostFacts $facts, Store $store ): string {
		return hash(
			'sha256',
			implode(
				"\n",
				array(
					AI_READY_VERSION,
					$this->converter_version(),
					(string) $store->cache_epoch(),
					(string) $post->post_modified_gmt,
					(string) $post->post_title,
					(string) $post->post_excerpt,
					(string) $post->post_name,
					(string) $post->post_type,
					(string) $settings->note_length(),
					$facts->excluded() ? '1' : '0',
					$facts->noindex() ? '1' : '0',
					(string) $post->post_password,
				)
			)
		);
	}

	/**
	 * @param array{stamp: string, markdown: string, note: string, converter: string}|null $prior Stored row.
	 */
	private function cache_matches( $prior, string $stamp, string $converter_version ): bool {
		if ( ! is_array( $prior ) || ! isset( $prior['stamp'], $prior['markdown'], $prior['note'], $prior['converter'] ) ) {
			return false;
		}
		if ( ! is_string( $prior['converter'] ) || strlen( $prior['converter'] ) !== strlen( $converter_version ) ) {
			return false;
		}
		if ( ! hash_equals( $converter_version, $prior['converter'] ) ) {
			return false;
		}
		if ( ! is_string( $prior['stamp'] ) || strlen( $prior['stamp'] ) !== strlen( $stamp ) ) {
			return false;
		}
		return hash_equals( $stamp, $prior['stamp'] );
	}

	private function converter_version(): string {
		$version  = defined( 'AI_READY_CONVERTER' ) ? (string) AI_READY_CONVERTER : '1';
		$filtered = apply_filters( 'ai_ready_converter_version', $version );
		if ( ! is_string( $filtered ) || '' === $filtered ) {
			return $version;
		}
		return $filtered;
	}

	/**
	 * @param \WP_Error $error Failure to show and return.
	 * @return \WP_Error
	 */
	private function fail( \WP_Error $error ) {
		$message = $error->get_error_message();
		Store::open()->record_error( is_string( $message ) ? $message : '' );
		return $error;
	}

	/**
	 * @return \WP_Error
	 */
	private function hand_off() {
		if ( ! wp_next_scheduled( Scheduler::HOOK ) ) {
			wp_schedule_single_event( time() + 1, Scheduler::HOOK );
		}
		return new \WP_Error(
			'ai_ready_deferred',
			__( 'AI Ready could not finish this rebuild in the current request. Cron will finish it.', 'ai-ready-wp' )
		);
	}

	private function should_defer( int $done ): bool {
		$force = (bool) apply_filters( 'ai_ready_force_defer', false, $done );
		if ( $this->in_background() ) {
			return false;
		}
		if ( $force ) {
			return true;
		}
		return $this->memory_is_low();
	}

	private function in_background(): bool {
		if ( (bool) apply_filters( 'ai_ready_treat_as_background', false ) ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	private function memory_is_low(): bool {
		if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return false;
		}
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $limit < 1 ) {
			return false;
		}
		$reserve = (int) apply_filters( 'ai_ready_memory_reserve', self::MEMORY_RESERVE );
		if ( $reserve < 1 ) {
			$reserve = self::MEMORY_RESERVE;
		}
		return ( memory_get_usage( true ) + $reserve ) >= $limit;
	}

	private function batch_size(): int {
		$size = (int) apply_filters( 'ai_ready_batch_size', self::BATCH_SIZE );
		if ( $size < 1 ) {
			return self::BATCH_SIZE;
		}
		return $size;
	}

	/**
	 * @return array{posts: \WP_Post[], pages: int}
	 */
	private function batch( Settings $settings, int $page ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => $settings->post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => $this->batch_size(),
				'paged'                  => $page,
				'has_password'           => false,
				'orderby'                => array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && '' === (string) $post->post_password ) {
				$posts[] = $post;
			}
		}
		$pages = (int) $query->max_num_pages;
		wp_reset_postdata();
		unset( $query );
		return array(
			'posts' => $posts,
			'pages' => $pages,
		);
	}

	private function title( \WP_Post $post ): string {
		$title = Text::one_line( wp_strip_all_tags( html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ) ) );
		return '' === $title ? 'Untitled' : $title;
	}

	private function html_url( \WP_Post $post ): string {
		$url = get_permalink( $post );
		return is_string( $url ) && '' !== $url ? $url : home_url( '/?p=' . (int) $post->ID );
	}

	private function site_name(): string {
		$name = get_bloginfo( 'name' );
		$name = Text::one_line( wp_strip_all_tags( is_string( $name ) ? $name : '' ) );
		return '' === $name ? 'Site' : $name;
	}

	private function summary( Settings $settings ): string {
		if ( '' !== $settings->summary() ) {
			return $settings->summary();
		}
		$tagline = get_bloginfo( 'description' );
		$tagline = Text::one_line( wp_strip_all_tags( is_string( $tagline ) ? $tagline : '' ) );
		if ( '' !== $tagline ) {
			return $tagline;
		}
		return 'Public pages and posts from ' . $this->site_name() . '.';
	}

	private function section_label( \WP_Post $post ): string {
		$object = get_post_type_object( $post->post_type );
		if ( $object instanceof \WP_Post_Type && isset( $object->labels->name ) && is_string( $object->labels->name ) ) {
			$label = Text::one_line( wp_strip_all_tags( $object->labels->name ) );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return ucfirst( (string) $post->post_type );
	}
}
