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
				return ( new Generator() )->run( true );
			}
		);
		if ( is_wp_error( $count ) ) {
			$message = $count->get_error_message();
			if ( ! is_string( $message ) || '' === $message ) {
				$message = 'AI Ready could not save the generated output.';
			}
			if ( defined( 'AI_READY_TESTING' ) && AI_READY_TESTING ) {
				throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test signal, not rendered output.
			}
			\WP_CLI::error( $message );
		}
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
		$store = Store::open();
		$when  = $store->generated_at();
		\WP_CLI::log( 'Single-site plugin.' );
		\WP_CLI::log( 'llms.txt: ' . ( $store->has_index() ? 'yes' : 'no' ) );
		\WP_CLI::log( 'llms-full.txt: ' . ( $store->has_full() ? 'yes' : 'no' ) );
		\WP_CLI::log( 'documents: ' . (string) count( $store->document_ids() ) );
		\WP_CLI::log( 'generated_at: ' . ( $when ? gmdate( 'c', $when ) : 'never' ) );
	}
}
