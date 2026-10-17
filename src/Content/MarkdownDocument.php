<?php
/**
 * Composes one public Markdown document.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Content;

use Fer\AiReady\Domain\Document;

/**
 * Adds a YAML-style header and a title to converted body text.
 *
 * @since 1.0.0
 */
final class MarkdownDocument {
	/**
	 * Compose a document.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $document Document fields. The body is the converted Markdown.
	 * @return string
	 */
	public function compose( Document $document ) {
		$title = MarkdownText::single_line( $document->title );
		if ( '' === $title ) {
			$title = 'Untitled';
		}

		$lines   = array( '---' );
		$lines[] = 'title: ' . $this->yaml_quote( $title );
		$lines[] = 'url: ' . $this->yaml_quote( $document->url );
		$lines[] = 'date: ' . $this->yaml_quote( $document->date );
		$lines[] = 'modified: ' . $this->yaml_quote( $document->modified );
		$lines[] = 'type: ' . $this->yaml_quote( $document->type );
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $title;
		$lines[] = '';
		$lines[] = trim( $document->body );

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * Quote a value for a single-line YAML field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Field value.
	 * @return string
	 */
	private function yaml_quote( $value ) {
		$value = MarkdownText::single_line( $value );
		$value = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
		return '"' . $value . '"';
	}
}
