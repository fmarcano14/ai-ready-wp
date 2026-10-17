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
}
