<?php
/**
 * Markdown converter tests.
 *
 * @package AiReadyWp
 */

namespace Fer\AiReady\Tests\Unit;

use Fer\AiReady\Content\MarkdownConverter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Fer\AiReady\Content\MarkdownConverter
 */
final class MarkdownConverterTest extends TestCase {
	/**
	 * Converter under test.
	 *
	 * @var MarkdownConverter
	 */
	private $converter;

	/**
	 * Create the converter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->converter = new MarkdownConverter();
	}

	/**
	 * Empty input stays empty.
	 *
	 * @return void
	 */
	public function test_empty_input_returns_empty_string() {
		$this->assertSame( '', $this->converter->convert( '   ' ) );
	}

	/**
	 * Headings, emphasis, and links survive.
	 *
	 * @return void
	 */
	public function test_converts_headings_emphasis_and_links() {
		$html = '<h2>A heading</h2><p>Hello <strong>world</strong> and <em>friends</em>. <a href="https://example.com/ok">ok</a></p>';
		$md   = $this->converter->convert( $html );

		$this->assertStringContainsString( "## A heading\n", $md );
		$this->assertStringContainsString( 'Hello **world** and *friends*.', $md );
		$this->assertStringContainsString( '[ok](https://example.com/ok)', $md );
	}

	/**
	 * Nested lists keep their depth.
	 *
	 * @return void
	 */
	public function test_converts_nested_lists() {
		$html = '<ul><li>One</li><li>Two<ul><li>Nested</li></ul></li></ul>';
		$md   = $this->converter->convert( $html );

		$this->assertStringContainsString( "- One\n", $md );
		$this->assertStringContainsString( "- Two\n", $md );
		$this->assertStringContainsString( '  - Nested', $md );
	}

	/**
	 * Gutenberg blocks become Markdown and unsafe fragments are dropped.
	 *
	 * @return void
	 */
	public function test_converts_gutenberg_content_and_strips_scripts() {
		$html = <<<'HTML'
<!-- wp:paragraph -->
<p>Intro</p>
<!-- /wp:paragraph -->
<pre class="wp-block-code"><code class="language-php">echo 'hi';</code></pre>
<figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="Alt text" /><figcaption>Caption</figcaption></figure>
<figure class="wp-block-table"><table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table></figure>
<blockquote><p>Quoted</p><cite>Ada</cite></blockquote>
<script>alert(1)</script>
<style>.x{}</style>
[gallery ids="1,2"]
<iframe src="https://example.com/embed"></iframe>
<a href="javascript:alert(1)">bad</a>
HTML;
		$md   = $this->converter->convert( $html );

		$this->assertStringContainsString( 'Intro', $md );
		$this->assertStringContainsString( "```php\necho 'hi';\n```", $md );
		$this->assertStringContainsString( '![Alt text](https://example.com/a.jpg)', $md );
		$this->assertStringContainsString( '*Caption*', $md );
		$this->assertStringContainsString( '| A | B |', $md );
		$this->assertStringContainsString( '| --- | --- |', $md );
		$this->assertStringContainsString( '| 1 | 2 |', $md );
		$this->assertStringContainsString( "> Quoted\n>\n> — Ada", $md );
		$this->assertStringContainsString( 'https://example.com/embed', $md );
		$this->assertStringContainsString( 'bad', $md );
		$this->assertStringNotContainsString( 'javascript:', $md );
		$this->assertStringNotContainsString( 'alert(1)', $md );
		$this->assertStringNotContainsString( '[gallery', $md );
		$this->assertStringNotContainsString( '<script', $md );
	}

	/**
	 * UTF-8 text is preserved.
	 *
	 * @return void
	 */
	public function test_preserves_utf8() {
		$md = $this->converter->convert( '<p>Café — naïve</p>' );
		$this->assertStringContainsString( 'Café — naïve', $md );
	}
}
