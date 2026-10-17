<?php
/**
 * Converts rendered HTML into Markdown.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Content;

use Fer\AiReady\UrlPolicy;

/**
 * HTML to Markdown converter.
 *
 * Callers should pass content that WordPress has already rendered (blocks
 * expanded, shortcodes processed). Leftover shortcodes, scripts, and styles
 * are removed. Links and images must pass the URL allowlist or they are
 * reduced to their text.
 *
 * @since 1.0.0
 */
final class MarkdownConverter {
	/**
	 * Convert an HTML fragment to Markdown.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Rendered HTML.
	 * @return string Markdown, or an empty string when there is nothing to keep.
	 */
	public function convert( $html ) {
		$html = $this->preprocess( (string) $html );
		if ( '' === trim( $html ) ) {
			return '';
		}

		$document = $this->load( $html );
		if ( null === $document ) {
			return $this->fallback_text( $html );
		}

		$root = $document->getElementById( 'ai-ready-root' );
		if ( ! $root instanceof \DOMElement ) {
			return $this->fallback_text( $html );
		}

		return $this->normalize( $this->render_blocks( $root ) );
	}

	/**
	 * Remove comments, embedded code, and unprocessed shortcodes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function preprocess( $html ) {
		$html = str_replace( "\0", '', $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html );
		$html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', (string) $html );
		// A Markdown link is "[label](url)". Shortcodes are not followed by "(".
		$html = preg_replace( '/\[\/?[A-Za-z][A-Za-z0-9_-]*(?:\s[^\]]*)?\](?!\()/', '', (string) $html );
		return is_string( $html ) ? $html : '';
	}

	/**
	 * Parse an HTML fragment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html HTML fragment.
	 * @return \DOMDocument|null
	 */
	private function load( $html ) {
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$wrapped  = '<?xml encoding="UTF-8"><div id="ai-ready-root">' . $html . '</div>';
		$flags    = 0;
		if ( defined( 'LIBXML_HTML_NOIMPLIED' ) ) {
			$flags |= LIBXML_HTML_NOIMPLIED;
		}
		if ( defined( 'LIBXML_HTML_NODEFDTD' ) ) {
			$flags |= LIBXML_HTML_NODEFDTD;
		}
		$loaded = $document->loadHTML( $wrapped, $flags );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return null;
		}
		return $document;
	}

	/**
	 * Last resort when DOM parsing fails.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function fallback_text( $html ) {
		// Fallback runs when DOM is unavailable. Tests call it without WordPress.
		$text = trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		return '' === $text ? '' : $text . "\n";
	}

	/**
	 * Render element children as blocks.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMNode $node Parent node.
	 * @return string
	 */
	private function render_blocks( \DOMNode $node ) {
		$buffer = '';
		foreach ( $node->childNodes as $child ) {
			$buffer .= $this->render_block( $child );
		}
		return $buffer;
	}

	/**
	 * Render one node in block context.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private function render_block( \DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $this->inline_text( $node->textContent ) );
			return '' === $text ? '' : "\n\n" . $text . "\n\n";
		}
		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );
		if ( $this->is_skipped( $tag ) ) {
			return '';
		}

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$text = trim( $this->render_inline( $node ) );
				if ( '' === $text ) {
					return '';
				}
				$level = (int) substr( $tag, 1 );
				return "\n\n" . str_repeat( '#', $level ) . ' ' . $text . "\n\n";
			case 'p':
				$text = trim( $this->render_inline( $node ) );
				return '' === $text ? '' : "\n\n" . $text . "\n\n";
			case 'blockquote':
				return $this->render_blockquote( $node );
			case 'ul':
				return $this->render_list( $node, false, 0 );
			case 'ol':
				return $this->render_list( $node, true, 0 );
			case 'pre':
				return $this->render_pre( $node );
			case 'table':
				return $this->render_table( $node );
			case 'hr':
				return "\n\n---\n\n";
			case 'figure':
				return $this->render_figure( $node );
			case 'cite':
				$text = trim( $this->render_inline( $node ) );
				return '' === $text ? '' : "\n\n— " . $text . "\n\n";
			case 'iframe':
			case 'video':
			case 'audio':
			case 'source':
				return $this->render_media_url( $node );
			case 'br':
				return "\n";
			default:
				return $this->render_blocks( $node );
		}
	}

	/**
	 * Render a blockquote, including a citation when present.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $node Blockquote element.
	 * @return string
	 */
	private function render_blockquote( \DOMElement $node ) {
		$clone = $node->cloneNode( true );
		$cites = array();
		foreach ( $clone->getElementsByTagName( 'cite' ) as $cite ) {
			$cites[] = $cite;
		}
		$citations = array();
		foreach ( $cites as $cite ) {
			$text = trim( $this->render_inline( $cite ) );
			if ( '' !== $text ) {
				$citations[] = $text;
			}
			if ( null !== $cite->parentNode ) {
				$cite->parentNode->removeChild( $cite );
			}
		}

		$inner = preg_replace( "/\n{3,}/", "\n\n", $this->render_blocks( $clone ) );
		$inner = trim( is_string( $inner ) ? $inner : '' );
		$lines = array();
		if ( '' !== $inner ) {
			foreach ( explode( "\n", $inner ) as $line ) {
				$lines[] = '> ' . $line;
			}
		}
		if ( array() !== $citations ) {
			if ( array() !== $lines && '>' !== $lines[ count( $lines ) - 1 ] ) {
				$lines[] = '>';
			}
			$lines[] = '> — ' . implode( ', ', $citations );
		}
		if ( array() === $lines ) {
			return '';
		}
		return "\n\n" . implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * Render a list.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $list_node List element.
	 * @param bool        $ordered Whether the list is ordered.
	 * @param int         $depth   Nesting depth, starting at 0.
	 * @return string
	 */
	private function render_list( \DOMElement $list_node, $ordered, $depth ) {
		$lines = array();
		$index = (int) $list_node->getAttribute( 'start' );
		if ( $index < 1 ) {
			$index = 1;
		}
		foreach ( $list_node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}
			$marker  = $ordered ? $index . '. ' : '- ';
			$indent  = str_repeat( '  ', $depth );
			$parts   = explode( "\n", $this->render_list_item( $child, $depth ) );
			$first   = array_shift( $parts );
			$lines[] = $indent . $marker . $first;
			foreach ( $parts as $part ) {
				if ( '' === trim( $part ) ) {
					continue;
				}
				$lines[] = $part;
			}
			++$index;
		}
		if ( array() === $lines ) {
			return '';
		}
		return "\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Render one list item. Nested lists keep the indentation they already have.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $item  List item.
	 * @param int         $depth Depth of the parent list.
	 * @return string
	 */
	private function render_list_item( \DOMElement $item, $depth ) {
		$inline = '';
		$nested = '';
		foreach ( $item->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$name = strtolower( $child->nodeName );
				if ( 'ul' === $name || 'ol' === $name ) {
					$nested .= $this->render_list( $child, 'ol' === $name, $depth + 1 );
					continue;
				}
			}
			$inline .= $this->render_inline_node( $child );
		}
		$inline = trim( (string) preg_replace( '/[ \t]+/', ' ', $inline ) );
		$nested = preg_replace( "/^\n+|\n+\z/", '', $nested );
		$nested = is_string( $nested ) ? $nested : '';
		if ( '' !== $nested ) {
			return $inline . "\n" . $nested;
		}
		return $inline;
	}

	/**
	 * Render a fenced code block.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $node Pre element.
	 * @return string
	 */
	private function render_pre( \DOMElement $node ) {
		$code = str_replace( array( "\r\n", "\r" ), "\n", $node->textContent );
		$code = rtrim( $code, "\n" );
		if ( '' === trim( $code ) ) {
			return '';
		}
		$fence = false !== strpos( $code, '```' ) ? '````' : '```';
		return "\n\n" . $fence . $this->code_language( $node ) . "\n" . $code . "\n" . $fence . "\n\n";
	}

	/**
	 * Read a language class from a code block.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $pre Pre element.
	 * @return string Language token, or an empty string.
	 */
	private function code_language( \DOMElement $pre ) {
		$class = $pre->getAttribute( 'class' );
		foreach ( $pre->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'code' === strtolower( $child->nodeName ) ) {
				$class .= ' ' . $child->getAttribute( 'class' );
				break;
			}
		}
		if ( preg_match( '/(?:language|lang)-([a-z0-9_+-]+)/i', $class, $match ) ) {
			return $match[1];
		}
		return '';
	}

	/**
	 * Render a GitHub-flavored Markdown table.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $table Table element.
	 * @return string
	 */
	private function render_table( \DOMElement $table ) {
		$rows = $this->table_rows( $table );
		if ( array() === $rows ) {
			return '';
		}
		$width = 0;
		foreach ( $rows as $row ) {
			$width = max( $width, count( $row ) );
		}
		$lines   = array();
		$header  = array_shift( $rows );
		$lines[] = $this->table_line( $header, $width );
		$lines[] = $this->table_line( array_fill( 0, $width, '---' ), $width );
		foreach ( $rows as $row ) {
			$lines[] = $this->table_line( $row, $width );
		}
		return "\n\n" . implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * Collect table rows without walking into nested tables.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement            $node  Table or table section.
	 * @param array<int, array>|null $rows Collected rows.
	 * @return array<int, array<int, string>>
	 */
	private function table_rows( \DOMElement $node, &$rows = null ) {
		if ( null === $rows ) {
			$rows = array();
		}
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$name = strtolower( $child->nodeName );
			if ( 'tr' === $name ) {
				$cells = array();
				foreach ( $child->childNodes as $cell ) {
					if ( ! $cell instanceof \DOMElement ) {
						continue;
					}
					$cell_name = strtolower( $cell->nodeName );
					if ( 'td' !== $cell_name && 'th' !== $cell_name ) {
						continue;
					}
					$text    = trim( $this->render_inline( $cell ) );
					$text    = str_replace( '|', '\\|', $text );
					$text    = str_replace( "\n", ' ', $text );
					$cells[] = $text;
				}
				if ( array() !== $cells ) {
					$rows[] = $cells;
				}
				continue;
			}
			if ( in_array( $name, array( 'thead', 'tbody', 'tfoot' ), true ) ) {
				$this->table_rows( $child, $rows );
			}
		}
		return $rows;
	}

	/**
	 * Format one Markdown table row.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $cells Row cells.
	 * @param int                $width Column count.
	 * @return string
	 */
	private function table_line( array $cells, $width ) {
		$cells = array_pad( array_values( $cells ), $width, '' );
		return '| ' . implode( ' | ', $cells ) . ' |';
	}

	/**
	 * Render a figure as an image plus caption, or as the table it wraps.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $figure Figure element.
	 * @return string
	 */
	private function render_figure( \DOMElement $figure ) {
		$table = $this->first_child( $figure, 'table' );
		if ( $table instanceof \DOMElement ) {
			return $this->render_table( $table );
		}

		$image   = '';
		$caption = '';
		foreach ( $figure->getElementsByTagName( 'img' ) as $img ) {
			if ( $img instanceof \DOMElement ) {
				$image = $this->render_image( $img );
				break;
			}
		}
		foreach ( $figure->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'figcaption' === strtolower( $child->nodeName ) ) {
				$caption = trim( $this->render_inline( $child ) );
				break;
			}
		}
		$buffer = '';
		if ( '' !== $image ) {
			$buffer .= "\n\n" . $image . "\n\n";
		}
		if ( '' !== $caption && $caption !== $this->image_alt( $figure ) ) {
			$buffer .= "\n\n*" . $caption . "*\n\n";
		}
		if ( '' === $buffer ) {
			return $this->render_blocks( $figure );
		}
		return $buffer;
	}

	/**
	 * Alt text of the first image in a figure.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $figure Figure element.
	 * @return string
	 */
	private function image_alt( \DOMElement $figure ) {
		foreach ( $figure->getElementsByTagName( 'img' ) as $img ) {
			if ( $img instanceof \DOMElement ) {
				return MarkdownText::single_line( $img->getAttribute( 'alt' ) );
			}
		}
		return '';
	}

	/**
	 * First direct child with a tag name.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $node Parent.
	 * @param string      $tag  Tag name.
	 * @return \DOMElement|null
	 */
	private function first_child( \DOMElement $node, $tag ) {
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$name = strtolower( $child->nodeName );
			if ( $tag === $name ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Publish a media URL when the allowlist accepts it.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $node Media element.
	 * @return string
	 */
	private function render_media_url( \DOMElement $node ) {
		$src = $node->getAttribute( 'src' );
		$url = $this->clean_url( $src, false );
		return '' === $url ? '' : "\n\n" . $url . "\n\n";
	}

	/**
	 * Render inline descendants.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMNode $node Parent node.
	 * @return string
	 */
	private function render_inline( \DOMNode $node ) {
		$buffer = '';
		foreach ( $node->childNodes as $child ) {
			$buffer .= $this->render_inline_node( $child );
		}
		return $buffer;
	}

	/**
	 * Render one inline node.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private function render_inline_node( \DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return $this->inline_text( $node->textContent );
		}
		if ( ! $node instanceof \DOMElement ) {
			return '';
		}
		$tag = strtolower( $node->nodeName );
		if ( $this->is_skipped( $tag ) ) {
			return '';
		}
		switch ( $tag ) {
			case 'strong':
			case 'b':
				return $this->wrap_inline( $node, '**' );
			case 'em':
			case 'i':
				return $this->wrap_inline( $node, '*' );
			case 'del':
			case 's':
			case 'strike':
				return $this->wrap_inline( $node, '~~' );
			case 'code':
				return $this->render_inline_code( $node );
			case 'a':
				return $this->render_link( $node );
			case 'img':
				return $this->render_image( $node );
			case 'br':
				return "\n";
			default:
				return $this->render_inline( $node );
		}
	}

	/**
	 * Wrap inline contents with a Markdown marker.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $node   Element.
	 * @param string      $marker Marker such as `**`.
	 * @return string
	 */
	private function wrap_inline( \DOMElement $node, $marker ) {
		$text = $this->render_inline( $node );
		if ( '' === trim( $text ) ) {
			return '';
		}
		return $marker . $text . $marker;
	}

	/**
	 * Render inline code.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $node Code element.
	 * @return string
	 */
	private function render_inline_code( \DOMElement $node ) {
		$text = str_replace( array( "\r", "\n" ), ' ', $node->textContent );
		$text = str_replace( '`', '\`', $text );
		return '`' . $text . '`';
	}

	/**
	 * Render a link, or its text when the URL is not allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $node Anchor.
	 * @return string
	 */
	private function render_link( \DOMElement $node ) {
		$text = trim( $this->render_inline( $node ) );
		$href = $this->clean_url( $node->getAttribute( 'href' ), false );
		if ( '' === $text ) {
			$text = $href;
		}
		if ( '' === $href ) {
			return $text;
		}
		return '[' . $text . '](' . $href . ')';
	}

	/**
	 * Render an image, or nothing when the source is not allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param \DOMElement $node Image.
	 * @return string
	 */
	private function render_image( \DOMElement $node ) {
		$src = $this->clean_url( $node->getAttribute( 'src' ), true );
		if ( '' === $src ) {
			return '';
		}
		$alt = str_replace( array( '[', ']' ), array( '\\[', '\\]' ), MarkdownText::single_line( $node->getAttribute( 'alt' ) ) );
		return '![' . $alt . '](' . $src . ')';
	}

	/**
	 * Normalize an inline text node.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function inline_text( $text ) {
		$text = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', (string) $text );
		$text = preg_replace( '/ {2,}/', ' ', $text );
		return is_string( $text ) ? $text : '';
	}

	/**
	 * Apply the URL allowlist and make the destination safe inside parentheses.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url   Candidate URL.
	 * @param bool   $image Whether this is an image source.
	 * @return string
	 */
	private function clean_url( $url, $image ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( ! UrlPolicy::allows( $url, $image ) ) {
			return '';
		}
		return str_replace( array( ' ', "\r", "\n", ')' ), array( '%20', '', '', '%29' ), $url );
	}

	/**
	 * Tags whose contents must not be published.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tag Tag name.
	 * @return bool
	 */
	private function is_skipped( $tag ) {
		return in_array(
			$tag,
			array( 'script', 'style', 'noscript', 'svg', 'canvas', 'form', 'input', 'button', 'textarea', 'select', 'template' ),
			true
		);
	}

	/**
	 * Collapse extra blank lines and trailing whitespace.
	 *
	 * @since 1.0.0
	 *
	 * @param string $markdown Markdown.
	 * @return string
	 */
	private function normalize( $markdown ) {
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		$markdown = preg_replace( "/[ \t]+\n/", "\n", $markdown );
		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );
		$markdown = trim( (string) $markdown );
		return '' === $markdown ? '' : $markdown . "\n";
	}
}
