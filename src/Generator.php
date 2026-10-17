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
	 * @param bool $fresh True skips the reuse cache. Manual generate and WP-CLI pass true.
	 * @return int|\WP_Error Document count, or the storage error. Never a count when the write failed.
	 */
	public function run( bool $fresh = false ) {
		if ( ! self::generation_allowed() ) {
			return 0;
		}

		$settings          = Settings::current();
		$posts             = $this->posts( $settings );
		$reader            = new ContentReader();
		$converter         = new HtmlToMarkdown();
		$builder           = new LlmsTxt();
		$store             = Store::open();
		$documents         = array();
		$cache             = array();
		$converter_version = $this->converter_version();

		$previous = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			foreach ( $posts as $post ) {
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
				}
				$cache[ $id ] = array(
					'stamp'     => $stamp,
					'markdown'  => $page,
					'note'      => $note,
					'converter' => $converter_version,
				);
				$documents[]  = new Document(
					$id,
					$title,
					$this->html_url( $post ),
					Urls::markdown( $post ),
					$note,
					$page,
					$this->section_label( $post ),
					$settings->is_optional( (string) $post->post_type )
				);
			}
		} finally {
			wp_set_current_user( $previous );
		}

		$catalog = new Catalog( $this->site_name(), $this->summary( $settings ), $settings->details(), $documents );
		$full    = $settings->write_full() ? $builder->full( $catalog ) : null;
		$stored  = $store->replace( $builder->index( $catalog ), $full, $documents, $cache );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		do_action( 'ai_ready_stored', count( $documents ) );
		return count( $documents );
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
	 * @return \WP_Post[]
	 */
	private function posts( Settings $settings ): array {
		$found = array();
		$page  = 1;
		$pages = 1;
		do {
			$query = new \WP_Query(
				array(
					'post_type'              => $settings->post_types(),
					'post_status'            => 'publish',
					'posts_per_page'         => 50,
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
			foreach ( $query->posts as $post ) {
				if ( $post instanceof \WP_Post && '' === (string) $post->post_password ) {
					$found[] = $post;
				}
			}
			$pages = (int) $query->max_num_pages;
			wp_reset_postdata();
			++$page;
		} while ( $page <= $pages && $page < 10000 );

		return $found;
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
