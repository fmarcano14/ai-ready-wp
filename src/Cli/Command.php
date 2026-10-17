<?php
/**
 * WP-CLI commands.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Cli;

use Fer\AiReady\Cache\Store;
use Fer\AiReady\Generator;
use Fer\AiReady\Publication;
use Fer\AiReady\Settings;

/**
 * Generates and inspects AI Ready files.
 *
 * ## EXAMPLES
 *
 *     wp ai-ready generate
 *     wp ai-ready status
 *
 * @since 1.0.0
 */
final class Command {
	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Eligibility policy.
	 *
	 * @since 1.0.0
	 *
	 * @var Publication
	 */
	private $publication;

	/**
	 * Index generator.
	 *
	 * @since 1.0.0
	 *
	 * @var Generator
	 */
	private $generator;

	/**
	 * Cache store.
	 *
	 * @since 1.0.0
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings    $settings    Settings reader.
	 * @param Publication $publication Eligibility policy.
	 * @param Generator   $generator   Index generator.
	 * @param Store       $store       Cache store.
	 */
	public function __construct( Settings $settings, Publication $publication, Generator $generator, Store $store ) {
		$this->settings    = $settings;
		$this->publication = $publication;
		$this->generator   = $generator;
		$this->store       = $store;
	}

	/**
	 * Generate llms.txt and, when enabled, llms-full.txt.
	 *
	 * ## OPTIONS
	 *
	 * [--stdout]
	 * : Print the llms.txt body instead of a summary.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-ready generate
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function generate( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$result = $this->generator->generate();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( isset( $assoc_args['stdout'] ) ) {
			\WP_CLI::log( $result['llms'] );
			return;
		}
		if ( empty( $result['published'] ) ) {
			\WP_CLI::success(
				sprintf(
					'Generated llms.txt (%1$d bytes, %2$d documents) without publishing it. Discovery is off, so the public index files were removed.',
					strlen( $result['llms'] ),
					(int) $result['total']
				)
			);
			return;
		}
		\WP_CLI::success(
			sprintf(
				'Wrote llms.txt (%1$d bytes, %2$d documents).',
				strlen( $result['llms'] ),
				(int) $result['total']
			)
		);
		if ( '' !== $result['full'] ) {
			\WP_CLI::log( sprintf( 'Wrote llms-full.txt (%d bytes).', strlen( $result['full'] ) ) );
		}
	}

	/**
	 * Show the effective configuration and cache status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-ready status
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$settings = $this->settings->all();
		$meta     = $this->store->meta();
		$next     = wp_next_scheduled( 'ai_ready_wp_regenerate' );
		$rows     = array(
			array(
				'field' => 'version',
				'value' => AI_READY_WP_VERSION,
			),
			array(
				'field' => 'discovery_mode',
				'value' => $settings['discovery_mode'],
			),
			array(
				'field' => 'discovery_effective',
				'value' => $this->publication->discovery_enabled() ? 'yes' : 'no',
			),
			array(
				'field' => 'markdown',
				'value' => ! empty( $settings['enable_markdown'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'llms_full',
				'value' => ! empty( $settings['enable_llms_full'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'post_types',
				'value' => implode( ', ', $settings['post_types'] ),
			),
			array(
				'field' => 'documents',
				'value' => isset( $meta['total'] ) ? (string) $meta['total'] : '0',
			),
			array(
				'field' => 'llms_bytes',
				'value' => isset( $meta['llms_bytes'] ) ? (string) $meta['llms_bytes'] : '0',
			),
			array(
				'field' => 'generated_at',
				'value' => ! empty( $meta['generated_at'] ) ? gmdate( 'c', (int) $meta['generated_at'] ) : 'never',
			),
			array(
				'field' => 'next_cron',
				'value' => $next ? gmdate( 'c', (int) $next ) : 'none',
			),
			array(
				'field' => 'llms_url',
				'value' => home_url( '/llms.txt' ),
			),
			array(
				'field' => 'last_error',
				'value' => (string) get_option( 'ai_ready_wp_last_error', '' ),
			),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}
