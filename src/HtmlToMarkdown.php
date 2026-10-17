<?php
/**
 * Convert a fragment of post HTML into Markdown.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small HTML to Markdown converter for post content.
 */
final class HtmlToMarkdown {

	public function convert( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$markup   = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>';
		$dom->loadHTML( $markup );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return '';
		}

		$markdown = $this->tidy( $this->render_children( $body ) );
		return $markdown;
	}

	private function render_children( \DOMNode $node ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= $this->render_node( $child );
		}
		return $out;
	}

	private function render_node( \DOMNode $node ): string {
		if ( $node instanceof \DOMText ) {
			return $this->escape_text( $node->nodeValue );
		}
		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		$tag = strtolower( $node->tagName );
		if ( 'script' === $tag || 'style' === $tag || 'noscript' === $tag ) {
			return '';
		}
		if ( 'br' === $tag ) {
			return "\n";
		}
		if ( 'hr' === $tag ) {
			return "\n\n---\n\n";
		}
		if ( 'code' === $tag ) {
			return $this->inline_code( $this->raw_text( $node ) );
		}
		if ( 'pre' === $tag ) {
			return $this->fence( $node );
		}
		if ( 1 === preg_match( '/\Ah([1-6])\z/', $tag, $level ) ) {
			$inner = trim( $this->render_children( $node ) );
			return $this->block( str_repeat( '#', (int) $level[1] ) . ' ' . $inner );
		}
		if ( 'p' === $tag ) {
			return $this->block( trim( $this->render_children( $node ) ) );
		}
		if ( 'blockquote' === $tag ) {
			return $this->blockquote( $node );
		}
		if ( 'ul' === $tag ) {
			return $this->list_block( $node, false );
		}
		if ( 'ol' === $tag ) {
			return $this->list_block( $node, true );
		}
		if ( 'a' === $tag ) {
			return $this->link( $node );
		}
		if ( 'img' === $tag ) {
			return $this->image( $node );
		}
		if ( 'strong' === $tag || 'b' === $tag ) {
			return $this->wrap( '**', $node );
		}
		if ( 'em' === $tag || 'i' === $tag ) {
			return $this->wrap( '*', $node );
		}
		if ( 'table' === $tag ) {
			return $this->table( $node );
		}

		return $this->render_children( $node );
	}

	private function escape_text( string $text ): string {
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = str_replace( array( '\\', '`' ), array( '\\\\', '\\`' ), $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return is_string( $text ) ? $text : '';
	}

	private function raw_text( \DOMNode $node ): string {
		return $node->textContent;
	}

	private function inline_code( string $code ): string {
		$code = trim( str_replace( array( "\r", "\n" ), ' ', $code ) );
		if ( '' === $code ) {
			return '';
		}
		if ( false === strpos( $code, '`' ) ) {
			return '`' . $code . '`';
		}
		if ( '`' === substr( $code, 0, 1 ) || '`' === substr( $code, -1 ) ) {
			$code = ' ' . $code . ' ';
		}
		return '``' . $code . '``';
	}

	private function fence( \DOMElement $node ): string {
		$code    = str_replace( "\r\n", "\n", rtrim( $this->raw_text( $node ), "\n" ) );
		$lang    = '';
		$code_el = $node->getElementsByTagName( 'code' )->item( 0 );
		if ( $code_el instanceof \DOMElement ) {
			$class = $code_el->getAttribute( 'class' );
			if ( preg_match( '/language-([A-Za-z0-9_+-]+)/', $class, $match ) ) {
				$lang = $match[1];
			}
		}
		$fence = '```';
		while ( false !== strpos( $code, $fence ) ) {
			$fence .= '`';
		}
		return $this->block( $fence . $lang . "\n" . $code . "\n" . $fence );
	}

	private function link( \DOMElement $node ): string {
		$text = trim( $this->render_children( $node ) );
		$href = trim( $node->getAttribute( 'href' ) );
		if ( '' === $text ) {
			$text = $href;
		}
		if ( '' === $href || 0 === strpos( $href, 'javascript:' ) ) {
			return $text;
		}
		$href = str_replace( array( "\r", "\n", ' ', '(', ')' ), array( '', '', '%20', '%28', '%29' ), $href );
		return '[' . $text . '](' . $href . ')';
	}

	private function image( \DOMElement $node ): string {
		$src = trim( $node->getAttribute( 'src' ) );
		if ( '' === $src ) {
			return '';
		}
		$alt = trim( $node->getAttribute( 'alt' ) );
		return '![' . str_replace( array( '[', ']' ), '', $alt ) . '](' . $src . ')';
	}

	private function wrap( string $marker, \DOMElement $node ): string {
		$inner = trim( $this->render_children( $node ) );
		if ( '' === $inner ) {
			return '';
		}
		return $marker . $inner . $marker;
	}

	private function blockquote( \DOMElement $node ): string {
		$inner = trim( $this->render_children( $node ) );
		if ( '' === $inner ) {
			return '';
		}
		$lines = array();
		foreach ( explode( "\n", $inner ) as $line ) {
			$lines[] = '> ' . $line;
		}
		return implode( "\n", $lines ) . "\n\n";
	}

	private function list_block( \DOMElement $node, bool $ordered ): string {
		$items = array();
		$index = 1;
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}
			$text = trim( $this->render_children( $child ) );
			$text = preg_replace( "/\n{2,}/", "\n", $text );
			if ( ! is_string( $text ) ) {
				$text = '';
			}
			$prefix = $ordered ? (string) $index . '. ' : '- ';
			$lines  = explode( "\n", $text );
			$first  = (string) array_shift( $lines );
			$item   = $prefix . $first;
			foreach ( $lines as $line ) {
				$item .= "\n  " . $line;
			}
			$items[] = $item;
			++$index;
		}
		if ( array() === $items ) {
			return '';
		}
		return implode( "\n", $items ) . "\n\n";
	}

	private function table( \DOMElement $node ): string {
		$rows = array();
		foreach ( $node->getElementsByTagName( 'tr' ) as $row ) {
			if ( ! $row instanceof \DOMElement ) {
				continue;
			}
			$cells = array();
			foreach ( $row->childNodes as $cell ) {
				if ( ! $cell instanceof \DOMElement ) {
					continue;
				}
				$cell_tag = strtolower( $cell->tagName );
				if ( 'td' !== $cell_tag && 'th' !== $cell_tag ) {
					continue;
				}
				$value   = $this->render_children( $cell );
				$value   = preg_replace( '/\s+/u', ' ', $value );
				$cells[] = trim( is_string( $value ) ? $value : '' );
			}
			if ( array() !== $cells ) {
				$rows[] = '| ' . implode( ' | ', $cells ) . ' |';
			}
		}
		if ( array() === $rows ) {
			return '';
		}
		return implode( "\n", $rows ) . "\n\n";
	}

	private function block( string $inner ): string {
		$inner = trim( $inner );
		if ( '' === $inner ) {
			return '';
		}
		return $inner . "\n\n";
	}

	private function tidy( string $markdown ): string {
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		$markdown = preg_replace( "/[ \t]+\n/", "\n", $markdown );
		if ( ! is_string( $markdown ) ) {
			return '';
		}
		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );
		if ( ! is_string( $markdown ) ) {
			return '';
		}
		$markdown = trim( $markdown );
		return '' === $markdown ? '' : $markdown . "\n";
	}
}
