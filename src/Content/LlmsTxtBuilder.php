<?php
/**
 * Builds a spec-compliant llms.txt document.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Content;

use Fer\AiReady\Domain\Index;
use Fer\AiReady\Domain\Link;
use Fer\AiReady\Domain\Section;
use Fer\AiReady\UrlPolicy;

/**
 * Assembles an llms.txt file.
 *
 * The order follows the llms.txt specification: one H1, an optional blockquote
 * summary, optional non-heading details, then H2 sections of Markdown links.
 * Sections marked optional are grouped under the conventional "Optional" heading.
 *
 * @since 1.0.0
 * @see https://llmstxt.org/
 */
final class LlmsTxtBuilder {
	/**
	 * Build the document.
	 *
	 * @since 1.0.0
	 *
	 * @param Index $index Site summary and ordered sections.
	 * @return string
	 */
	public function build( Index $index ) {
		$name = ltrim( MarkdownText::single_line( $index->name ), "# \t" );
		if ( '' === $name ) {
			$name = 'Site';
		}

		$chunks = array( '# ' . $name );

		$summary = trim( $index->summary );
		if ( '' !== $summary ) {
			$chunks[] = $this->blockquote( $summary );
		}

		$intro = $this->plain_intro( $index->details );
		if ( '' !== $intro ) {
			$chunks[] = $intro;
		}

		$optional = array();
		foreach ( $index->sections as $section ) {
			if ( ! $section instanceof Section ) {
				continue;
			}
			if ( $section->optional ) {
				foreach ( $section->links as $link ) {
					$optional[] = $link;
				}
				continue;
			}
			$heading = MarkdownText::single_line( $section->heading );
			$block   = $this->section( $heading, $section->links );
			if ( '' !== $block ) {
				$chunks[] = $block;
			}
		}

		// "Optional" is the section name defined by the llms.txt convention.
		$optional_block = $this->section( 'Optional', $optional );
		if ( '' !== $optional_block ) {
			$chunks[] = $optional_block;
		}

		return implode( "\n\n", $chunks ) . "\n";
	}

	/**
	 * Format a summary as a blockquote.
	 *
	 * @since 1.0.0
	 *
	 * @param string $summary Summary text.
	 * @return string
	 */
	private function blockquote( $summary ) {
		$summary = str_replace( array( "\r\n", "\r" ), "\n", $summary );
		$lines   = explode( "\n", $summary );
		$out     = array();
		foreach ( $lines as $line ) {
			$out[] = '> ' . trim( $line );
		}
		return implode( "\n", $out );
	}

	/**
	 * Remove heading markers from the intro so it cannot be read as an H2 section.
	 *
	 * @since 1.0.0
	 *
	 * @param string $intro Intro text.
	 * @return string
	 */
	private function plain_intro( $intro ) {
		$intro = str_replace( array( "\r\n", "\r" ), "\n", (string) $intro );
		$lines = explode( "\n", $intro );
		$out   = array();
		foreach ( $lines as $line ) {
			$trimmed = ltrim( $line );
			if ( preg_match( '/^#{1,6}\s+/', $trimmed ) ) {
				$trimmed = preg_replace( '/^#{1,6}\s+/', '', $trimmed );
				$out[]   = rtrim( (string) $trimmed );
				continue;
			}
			$out[] = rtrim( $line );
		}
		return trim( implode( "\n", $out ) );
	}

	/**
	 * Format one H2 file list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $heading Section heading.
	 * @param Link[] $links   Links.
	 * @return string
	 */
	private function section( $heading, array $links ) {
		$heading = trim( (string) $heading );
		$lines   = array();
		foreach ( $links as $link ) {
			if ( ! $link instanceof Link ) {
				continue;
			}
			$line = $this->link_line( $link );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		if ( '' === $heading || array() === $lines ) {
			return '';
		}
		return '## ' . $heading . "\n\n" . implode( "\n", $lines );
	}

	/**
	 * Format one Markdown link list item.
	 *
	 * @since 1.0.0
	 *
	 * @param Link $link Link.
	 * @return string
	 */
	private function link_line( Link $link ) {
		$url = trim( $link->url );
		if ( ! UrlPolicy::allows( $url, false ) ) {
			return '';
		}
		$url  = str_replace( array( ' ', ')' ), array( '%20', '%29' ), $url );
		$name = MarkdownText::escape_link_label( $link->name );
		if ( '' === $name ) {
			$name = $url;
		}
		$line = '- [' . $name . '](' . $url . ')';
		$note = MarkdownText::single_line( $link->note );
		$note = ltrim( $note, ": \t" );
		if ( strlen( $note ) > 240 ) {
			$note = rtrim( substr( $note, 0, 240 ) );
		}
		if ( '' !== $note ) {
			$line .= ': ' . $note;
		}
		return $line;
	}
}
