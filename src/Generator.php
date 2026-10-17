<?php
/**
 * Builds llms.txt and llms-full.txt from the current catalog.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

use Fer\AiReady\Cache\Store;
use Fer\AiReady\Content\LlmsFullBuilder;
use Fer\AiReady\Content\LlmsTxtBuilder;

/**
 * Generates, sanitizes, and stores the index files.
 *
 * @since 1.0.0
 */
final class Generator {
	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Document catalog.
	 *
	 * @since 1.0.0
	 *
	 * @var Catalog
	 */
	private $catalog;

	/**
	 * Cache store.
	 *
	 * @since 1.0.0
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Output sanitizer.
	 *
	 * @since 1.0.0
	 *
	 * @var Sanitizer
	 */
	private $sanitizer;

	/**
	 * Publication policy. Discovery decides whether index files are public.
	 *
	 * @since 1.0.0
	 *
	 * @var Publication
	 */
	private $publication;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings    $settings    Settings reader.
	 * @param Catalog     $catalog     Document catalog.
	 * @param Store       $store       Cache store.
	 * @param Sanitizer   $sanitizer   Output sanitizer.
	 * @param Publication $publication Publication policy.
	 */
	public function __construct( Settings $settings, Catalog $catalog, Store $store, Sanitizer $sanitizer, Publication $publication ) {
		$this->settings    = $settings;
		$this->catalog     = $catalog;
		$this->store       = $store;
		$this->sanitizer   = $sanitizer;
		$this->publication = $publication;
	}

	/**
	 * Generate and store the index files.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function generate() {
		if ( ! $this->store->acquire_index_lock() ) {
			return new \WP_Error( 'ai_ready_wp_busy', __( 'The index is already being generated. Please retry shortly.', 'ai-ready-wp' ) );
		}
		try {
			$result  = $this->compile( $this->settings->all(), true );
			$publish = $this->publication->discovery_enabled();
			$this->store->put_indexes( $result['llms'], $result['full'], $result['meta'], $publish );
			$result['published'] = $publish;
			/**
			 * Fires after the index files have been stored.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string, mixed> $result Generation result.
			 */
			do_action( 'ai_ready_wp_generated', $result );
			return $result;
		} catch ( \Throwable $error ) {
			update_option( 'ai_ready_wp_last_error', $error->getMessage(), false );
			return new \WP_Error( 'ai_ready_wp_generate', __( 'AI Ready could not generate the index.', 'ai-ready-wp' ) );
		} finally {
			$this->store->release_index_lock();
		}
	}

	/**
	 * Build llms.txt from unsaved settings without writing the cache.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return string
	 */
	public function preview( array $settings ) {
		$result = $this->compile( $settings, false );
		return $result['llms'];
	}

	/**
	 * Compile index strings from settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param bool                 $store_full Whether to render llms-full.txt.
	 * @return array<string, mixed>
	 */
	private function compile( array $settings, $store_full ) {
		$collected = $this->catalog->collect( $settings, $store_full );
		$name      = $this->site_name();
		$summary   = $this->summary( $settings, $name );
		$intro     = $this->intro( $settings );
		$llms      = ( new LlmsTxtBuilder() )->build( $name, $summary, $intro, $collected['sections'] );
		$filtered  = apply_filters( 'ai_ready_wp_llms_txt', $llms, $collected, $settings );
		if ( is_string( $filtered ) ) {
			$llms = $filtered;
		}
		$llms = $this->sanitizer->clean_markdown( $llms, Sanitizer::MAX_INDEX_BYTES );

		$full = '';
		if ( $store_full && ! empty( $settings['enable_llms_full'] ) && 'disabled' !== $settings['discovery_mode'] ) {
			$full_intro = $intro;
			if ( '' === trim( $full_intro ) ) {
				$full_intro = __( 'This file concatenates the Markdown version of each public document listed in llms.txt.', 'ai-ready-wp' );
			}
			$full     = ( new LlmsFullBuilder() )->build( $name, $summary, $full_intro, $collected['documents'] );
			$filtered = apply_filters( 'ai_ready_wp_llms_full', $full, $collected, $settings );
			if ( is_string( $filtered ) ) {
				$full = $filtered;
			}
			$full = $this->sanitizer->clean_markdown( $full, Sanitizer::MAX_FULL_BYTES );
		}

		$total = 0;
		foreach ( $collected['counts'] as $count ) {
			$total += (int) $count;
		}

		return array(
			'llms'    => $llms,
			'full'    => $full,
			'counts'  => $collected['counts'],
			'skipped' => (int) $collected['skipped'],
			'total'   => $total,
			'meta'    => array(
				'counts'  => $collected['counts'],
				'skipped' => (int) $collected['skipped'],
				'total'   => $total,
			),
		);
	}

	/**
	 * Site name used as the H1.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function site_name() {
		$name = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' !== $name ) {
			return $name;
		}
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : 'Site';
	}

	/**
	 * Summary blockquote, using the custom text, the tagline, or a fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $name     Site name.
	 * @return string
	 */
	private function summary( array $settings, $name ) {
		$summary = isset( $settings['summary'] ) ? trim( (string) $settings['summary'] ) : '';
		if ( '' !== $summary ) {
			return $summary;
		}
		$tagline = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
		if ( '' !== $tagline ) {
			return $tagline;
		}
		return sprintf(
			/* translators: %s: site name. */
			__( '%s publishes public pages and posts for readers and AI assistants.', 'ai-ready-wp' ),
			$name
		);
	}

	/**
	 * Intro placed between the summary and the first section.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return string
	 */
	private function intro( array $settings ) {
		$intro = isset( $settings['intro'] ) ? trim( (string) $settings['intro'] ) : '';
		if ( '' !== $intro ) {
			return $intro;
		}
		$intro = __( 'Each item links to the Markdown version of a public document. Password-protected, private, excluded, and noindex content is omitted.', 'ai-ready-wp' );
		if ( ! empty( $settings['enable_llms_full'] ) && 'disabled' !== $settings['discovery_mode'] ) {
			$intro .= ' ' . sprintf(
				/* translators: %s: absolute URL of llms-full.txt. */
				__( 'A combined copy is available at %s.', 'ai-ready-wp' ),
				home_url( '/llms-full.txt' )
			);
		}
		return $intro;
	}
}
