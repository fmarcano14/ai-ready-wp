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

	/**
	 * One line for an index that stopped at the document cap.
	 */
	public static function truncation_line( int $limit ): string {
		return 'Truncated after ' . $limit . ' documents.';
	}

	/**
	 * One line for a full file that stopped at the byte budget.
	 */
	public static function size_limit_line(): string {
		return 'Truncated at the size limit.';
	}

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

		if ( array() !== $optional ) {
			$lines[] = '## Optional';
			$lines[] = '';
			foreach ( $optional as $document ) {
				$lines[] = $this->link_line( $document );
			}
		}

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * Header shared by llms.txt and llms-full.txt, without the document bodies.
	 */
	public function preamble( string $name, string $summary, string $details ): string {
		$catalog = new Catalog( $name, $summary, $details, array() );
		return rtrim( implode( "\n", $this->header_lines( $catalog ) ) );
	}

	public function full( Catalog $catalog ): string {
		$parts = array( $this->preamble( $catalog->name(), $catalog->summary(), $catalog->details() ) );
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
