<?php
/**
 * Composes one public Markdown document.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Content;

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
	 * @param string $title    Document title.
	 * @param string $url      Canonical HTML URL.
	 * @param string $date     Published date.
	 * @param string $modified Modified date.
	 * @param string $type     Post type slug.
	 * @param string $body     Converted Markdown body.
	 * @return string
	 */
	public function compose( $title, $url, $date, $modified, $type, $body ) {
		$title = MarkdownText::single_line( $title );
		if ( '' === $title ) {
			$title = 'Untitled';
		}

		$lines   = array( '---' );
		$lines[] = 'title: ' . $this->yaml_quote( $title );
		$lines[] = 'url: ' . $this->yaml_quote( $url );
		$lines[] = 'date: ' . $this->yaml_quote( $date );
		$lines[] = 'modified: ' . $this->yaml_quote( $modified );
		$lines[] = 'type: ' . $this->yaml_quote( $type );
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $title;
		$lines[] = '';
		$lines[] = trim( (string) $body );

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
