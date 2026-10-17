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
		foreach ( $this->status_lines() as $line ) {
			\WP_CLI::log( $line );
		}
	}

	/**
	 * @return string[]
	 */
	public function status_lines(): array {
		$store = Store::open();
		$when  = $store->generated_at();
		$error = $store->last_error();
		$lines = array(
			'Single-site plugin.',
			'llms.txt: ' . ( $store->has_index() ? 'yes' : 'no' ),
			'llms-full.txt: ' . ( $store->has_full() ? 'yes' : 'no' ),
			'documents: ' . (string) count( $store->document_ids() ),
			'generated_at: ' . ( $when ? gmdate( 'c', $when ) : 'never' ),
			'max_items: ' . (string) Generator::MAX_ITEMS,
		);
		if ( is_array( $error ) ) {
			$lines[] = 'last_error: ' . $error['message'];
			$lines[] = 'last_error_at: ' . gmdate( 'c', $error['time'] );
		} else {
			$lines[] = 'last_error: none';
		}
		return $lines;
	}
}
