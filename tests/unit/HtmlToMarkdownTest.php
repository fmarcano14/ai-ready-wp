<?php
/**
 * HTML to Markdown cases, including the inline code contract.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\HtmlToMarkdown;
use AiReady\Text;
use PHPUnit\Framework\TestCase;

/**
 * Converter behavior.
 */
final class HtmlToMarkdownTest extends TestCase {

	public function test_inline_code_decodes_markup_entities(): void {
		$markdown = ( new HtmlToMarkdown() )->convert( '<code>&lt;div&gt;</code>' );
		$this->assertSame( "`<div>`\n", $markdown );
	}

	public function test_code_inside_a_paragraph_stays_inline(): void {
		$html     = '<p>Use <code>&lt;div&gt;</code> and <strong>bold</strong>.</p>';
		$markdown = ( new HtmlToMarkdown() )->convert( $html );
		$this->assertStringContainsString( 'Use `<div>` and **bold**.', $markdown );
	}

	public function test_common_blocks_become_markdown(): void {
		$html     = '<h2>Café</h2><ul><li>One</li><li>Two</li></ul><ol><li>First</li></ol><p><a href="https://example.com/a">Ex</a></p><blockquote><p>Hola ñ</p></blockquote><p><img src="https://example.com/a.png" alt="Rain 🌧️"></p>';
		$markdown = ( new HtmlToMarkdown() )->convert( $html );

		$this->assertStringContainsString( "## Café\n", $markdown );
		$this->assertStringContainsString( "- One\n- Two", $markdown );
		$this->assertStringContainsString( '1. First', $markdown );
		$this->assertStringContainsString( '[Ex](https://example.com/a)', $markdown );
		$this->assertStringContainsString( '> Hola ñ', $markdown );
		$this->assertStringContainsString( '![Rain 🌧️](https://example.com/a.png)', $markdown );
		$this->assertTrue( mb_check_encoding( $markdown, 'UTF-8' ) );
	}

	public function test_preformatted_code_uses_a_fence(): void {
		$html     = '<pre><code class="language-html">&lt;div class="x"&gt;</code></pre>';
		$markdown = ( new HtmlToMarkdown() )->convert( $html );
		$this->assertStringContainsString( "```html\n<div class=\"x\">\n```", $markdown );
	}

	public function test_script_and_style_are_dropped(): void {
		$html     = '<style>.x{color:red}</style><script>alert(1)</script><p>Safe é</p>';
		$markdown = ( new HtmlToMarkdown() )->convert( $html );
		$this->assertStringNotContainsString( 'alert', $markdown );
		$this->assertStringNotContainsString( 'color', $markdown );
		$this->assertStringContainsString( 'Safe é', $markdown );
	}

	public function test_shortcodes_are_removed_before_conversion(): void {
		$html     = Text::strip_shortcodes( '<p>See <code>&lt;div&gt;</code> [gallery id="1"] now</p>' );
		$markdown = ( new HtmlToMarkdown() )->convert( $html );
		$this->assertStringContainsString( '`<div>`', $markdown );
		$this->assertStringNotContainsString( '[gallery', $markdown );
		$this->assertStringContainsString( 'See', $markdown );
		$this->assertStringContainsString( 'now', $markdown );
	}

	public function test_empty_html_stays_empty(): void {
		$this->assertSame( '', ( new HtmlToMarkdown() )->convert( '   ' ) );
	}

	public function test_escaped_markup_in_text_stays_escaped(): void {
		$markdown = ( new HtmlToMarkdown() )->convert( '<p>Use &lt;div&gt; here.</p>' );
		$this->assertStringContainsString( 'Use &lt;div&gt; here.', $markdown );
		$this->assertStringNotContainsString( '<div>', $markdown );
	}

	public function test_scriptable_urls_are_not_emitted(): void {
		$hrefs = array(
			'javascript:alert(1)',
			' JavaScript:alert(1)',
			"java\nscript:alert(1)",
			"java\tscript:alert(1)",
			'vbscript:MsgBox',
			'data:text/html,hi',
		);
		foreach ( $hrefs as $href ) {
			$markdown = ( new HtmlToMarkdown() )->convert( '<p><a href="' . $href . '">Click</a></p>' );
			$this->assertStringNotContainsString( '](', $markdown, $href );
			$this->assertStringContainsString( 'Click', $markdown );
			$this->assertStringNotContainsString( 'alert', strtolower( $markdown ) );
		}

		$image = ( new HtmlToMarkdown() )->convert( '<p><img src="DATA:text/html,hi" alt="Pic"></p>' );
		$this->assertStringNotContainsString( '](', $image );
		$this->assertStringContainsString( 'Pic', $image );
		$this->assertStringNotContainsString( 'data:', strtolower( $image ) );
	}

	public function test_tables_include_a_header_separator(): void {
		$html     = '<table><tr><th>A</th><th>B|C</th></tr><tr><td>1</td><td>2</td></tr></table>';
		$markdown = ( new HtmlToMarkdown() )->convert( $html );
		$this->assertStringContainsString( "| A | B\\|C |\n| --- | --- |\n| 1 | 2 |", $markdown );
	}

	public function test_prose_keeps_a_decoded_ampersand(): void {
		$markdown = ( new HtmlToMarkdown() )->convert( '<p>Salt &amp; pepper</p>' );
		$this->assertStringContainsString( 'Salt & pepper', $markdown );
		$this->assertStringNotContainsString( 'Salt &amp; pepper', $markdown );
	}

	public function test_image_alt_and_title_cannot_inject_markup(): void {
		$html     = '<p><img src="https://example.com/a.png" alt="&lt;img onerror=alert(1)&gt;" title="&lt;img onerror=alert(2)&gt;"></p>';
		$markdown = ( new HtmlToMarkdown() )->convert( $html );
		$this->assertStringNotContainsString( '<img', $markdown );
		$this->assertStringContainsString( '&lt;img onerror=alert(1)&gt;', $markdown );
		$this->assertStringContainsString( '&lt;img onerror=alert(2)&gt;', $markdown );
	}

	public function test_link_title_cannot_inject_markup(): void {
		$html     = '<p><a href="https://example.com/a" title="&lt;img onerror=alert(1)&gt;">Salt &amp; pepper</a></p>';
		$markdown = ( new HtmlToMarkdown() )->convert( $html );
		$this->assertStringNotContainsString( '<img', $markdown );
		$this->assertStringContainsString( 'Salt & pepper', $markdown );
		$this->assertStringContainsString( '&lt;img onerror=alert(1)&gt;', $markdown );
		$this->assertStringNotContainsString( 'Salt &amp; pepper', $markdown );
	}
}
