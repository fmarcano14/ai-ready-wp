<?php
/**
 * Build llms.txt and llms-full.txt from a catalog.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure formatter for the llmstxt.org file shape.
 */
final class LlmsTxt {

	public function index( Catalog $catalog ): string {
		$lines    = $this->header_lines( $catalog );
		$grouped  = array();
		$optional = array();

		foreach ( $catalog->documents() as $document ) {
			if ( $document->optional() ) {
				$optional[] = $document;
				continue;
			}
			$section = $document->section();
			if ( '' === $section ) {
				$section = 'Pages';
			}
			if ( ! isset( $grouped[ $section ] ) ) {
				$grouped[ $section ] = array();
			}
			$grouped[ $section ][] = $document;
		}

		ksort( $grouped );
		foreach ( $grouped as $heading => $documents ) {
			$lines[] = '## ' . $this->heading( (string) $heading );
			$lines[] = '';
			foreach ( $documents as $document ) {
				$lines[] = $this->link_line( $document );
			}
			$lines[] = '';
		}

		$lines[] = '## Optional';
		$lines[] = '';
		foreach ( $optional as $document ) {
			$lines[] = $this->link_line( $document );
		}

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	public function full( Catalog $catalog ): string {
		$parts = array( rtrim( implode( "\n", $this->header_lines( $catalog ) ) ) );
		foreach ( $catalog->documents() as $document ) {
			$parts[] = rtrim( $document->markdown() );
		}
		return implode( "\n\n", $parts ) . "\n";
	}

	/**
	 * Markdown page stored for one document.
	 */
	public static function page( string $title, string $body ): string {
		$title = self::plain_title( Text::one_line( $title ) );
		if ( '' === $title ) {
			$title = 'Untitled';
		}
		$heading = '# ' . $title;
		$body    = trim( $body );
		if ( '' === $body ) {
			return $heading . "\n";
		}
		if ( 0 === strpos( $body, $heading . "\n" ) || $body === $heading ) {
			return $body . ( "\n" === substr( $body, -1 ) ? '' : "\n" );
		}
		return $heading . "\n\n" . $body . "\n";
	}

	/**
	 * @return string[]
	 */
	private function header_lines( Catalog $catalog ): array {
		$name = Text::one_line( $catalog->name() );
		if ( '' === $name ) {
			$name = 'Site';
		}
		$summary = Text::one_line( $catalog->summary() );
		if ( '' === $summary ) {
			$summary = 'Public content from ' . $name . '.';
		}

		$lines   = array(
			'# ' . $name,
			'',
			'> ' . $summary,
			'',
		);
		$details = $this->details( $catalog->details() );
		if ( '' !== $details ) {
			$lines[] = $details;
			$lines[] = '';
		}
		return $lines;
	}

	private function details( string $details ): string {
		$details = str_replace( array( "\r\n", "\r" ), "\n", $details );
		$kept    = array();
		foreach ( explode( "\n", $details ) as $line ) {
			$trim = trim( $line );
			if ( '' === $trim ) {
				$kept[] = '';
				continue;
			}
			// Headings are reserved for the file lists below the summary.
			if ( '#' === substr( $trim, 0, 1 ) ) {
				continue;
			}
			$kept[] = $trim;
		}
		return trim( implode( "\n", $kept ) );
	}

	private function link_line( Document $document ): string {
		$title = $this->link_label( $document->title() );
		$url   = $this->link_url( $document->markdown_url() );
		$note  = Text::one_line( $document->note() );
		$line  = '- [' . $title . '](' . $url . ')';
		if ( '' !== $note ) {
			$line .= ': ' . $note;
		}
		return $line;
	}

	private function link_label( string $title ): string {
		$title = str_replace( array( '[', ']' ), array( '(', ')' ), Text::one_line( $title ) );
		return '' === $title ? 'Untitled' : $title;
	}

	private function link_url( string $url ): string {
		return str_replace(
			array( "\r", "\n", ' ', '(', ')' ),
			array( '', '', '%20', '%28', '%29' ),
			$url
		);
	}

	private function heading( string $heading ): string {
		$heading = str_replace( '#', '', Text::one_line( $heading ) );
		return '' === $heading ? 'Pages' : $heading;
	}

	private static function plain_title( string $title ): string {
		return str_replace( array( "\r", "\n", '#' ), ' ', $title );
	}
}
