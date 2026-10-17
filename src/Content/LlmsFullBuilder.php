<?php
/**
 * Builds the concatenated llms-full.txt document.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Content;

/**
 * Concatenates per-document Markdown behind an llms.txt-style header.
 *
 * @since 1.0.0
 */
final class LlmsFullBuilder {
	/**
	 * Build the full document.
	 *
	 * @since 1.0.0
	 *
	 * @param string            $name      Site name.
	 * @param string            $summary   Summary.
	 * @param string            $intro     Intro placed before the documents.
	 * @param array<int, mixed> $documents Rows with a markdown key.
	 * @return string
	 */
	public function build( $name, $summary, $intro, array $documents ) {
		$header = ( new LlmsTxtBuilder() )->build( $name, $summary, $intro, array() );
		$parts  = array( rtrim( $header ) );

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) || empty( $document['markdown'] ) || ! is_string( $document['markdown'] ) ) {
				continue;
			}
			$body = trim( $document['markdown'] );
			if ( '' === $body ) {
				continue;
			}
			$parts[] = "---\n\n" . $body;
		}

		return implode( "\n\n", $parts ) . "\n";
	}
}
