<?php
/**
 * WP-CLI commands.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp ai-ready generate and wp ai-ready status.
 */
final class CliCommand {

	/**
	 * Build llms.txt and the Markdown files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-ready generate
	 *
	 * @param array<int, string>    $args  Positional arguments. Unused.
	 * @param array<string, string> $assoc Associative arguments. Unused.
	 */
	public function generate( $args, $assoc ): void {
		unset( $args, $assoc );
		$count = Generator::trusted(
			static function () {
				return ( new Generator() )->run();
			}
		);
		\WP_CLI::success( sprintf( 'Wrote %d document(s).', (int) $count ) );
	}

	/**
	 * Show whether the generated files exist.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-ready status
	 *
	 * @param array<int, string>    $args  Positional arguments. Unused.
	 * @param array<string, string> $assoc Associative arguments. Unused.
	 */
	public function status( $args, $assoc ): void {
		unset( $args, $assoc );
		$store = Store::from_uploads();
		$when  = $store->generated_at();
		\WP_CLI::log( 'Single-site plugin.' );
		\WP_CLI::log( 'llms.txt: ' . ( $store->has_index() ? 'yes' : 'no' ) );
		\WP_CLI::log( 'llms-full.txt: ' . ( $store->has_full() ? 'yes' : 'no' ) );
		\WP_CLI::log( 'documents: ' . (string) count( $store->document_ids() ) );
		\WP_CLI::log( 'generated_at: ' . ( $when ? gmdate( 'c', $when ) : 'never' ) );
	}
}
