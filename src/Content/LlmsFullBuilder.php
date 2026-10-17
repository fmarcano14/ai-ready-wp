<?php
/**
 * Builds the concatenated llms-full.txt document.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Content;

use Fer\AiReady\Domain\Document;
use Fer\AiReady\Domain\Index;

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
	 * @param Index      $index     Header fields. Sections are ignored.
	 * @param Document[] $documents Finished documents.
	 * @return string
	 */
	public function build( Index $index, array $documents ) {
		$header = ( new LlmsTxtBuilder() )->build( new Index( $index->name, $index->summary, $index->details, array() ) );
		$parts  = array( rtrim( $header ) );

		foreach ( $documents as $document ) {
			if ( ! $document instanceof Document ) {
				continue;
			}
			$body = trim( $document->markdown );
			if ( '' === $body ) {
				continue;
			}
			$parts[] = "---\n\n" . $body;
		}

		return implode( "\n\n", $parts ) . "\n";
	}
}
