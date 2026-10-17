<?php
/**
 * URL policy, sanitizer, robots, exclusions, and URL tests.
 *
 * @package AiReadyWp
 */

namespace Fer\AiReady\Tests\Unit;

use Fer\AiReady\Content\MarkdownDocument;
use Fer\AiReady\Domain\Document;
use Fer\AiReady\Content\MarkdownText;
use Fer\AiReady\Exclusions;
use Fer\AiReady\MarkdownUrl;
use Fer\AiReady\Robots;
use Fer\AiReady\Sanitizer;
use Fer\AiReady\SettingsSanitizer;
use Fer\AiReady\UrlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Fer\AiReady\UrlPolicy
 * @covers \Fer\AiReady\Sanitizer
 * @covers \Fer\AiReady\Robots
 * @covers \Fer\AiReady\Exclusions
 * @covers \Fer\AiReady\MarkdownUrl
 * @covers \Fer\AiReady\SettingsSanitizer
 * @covers \Fer\AiReady\Content\MarkdownDocument
 * @covers \Fer\AiReady\Content\MarkdownText
 */
final class PolicyTest extends TestCase {
	/**
	 * The allowlist accepts ordinary web and relative URLs.
	 *
	 * @return void
	 */
	public function test_url_policy_allows_expected_schemes() {
		$this->assertTrue( UrlPolicy::allows( 'https://example.com/a' ) );
		$this->assertTrue( UrlPolicy::allows( '/docs/page' ) );
		$this->assertTrue( UrlPolicy::allows( 'mailto:editor@example.com' ) );
		$this->assertFalse( UrlPolicy::allows( 'mailto:editor@example.com', true ) );
		$this->assertFalse( UrlPolicy::allows( 'javascript:alert(1)' ) );
		$this->assertFalse( UrlPolicy::allows( 'java%0ascript:alert(1)' ) );
		$this->assertFalse( UrlPolicy::allows( '%256Aavascript:alert(1)' ) );
		$this->assertFalse( UrlPolicy::allows( 'data:text/html,hi' ) );
	}

	/**
	 * The sanitizer removes a bad link but leaves fenced code alone.
	 *
	 * @return void
	 */
	public function test_sanitizer_strips_unsafe_links_outside_fences() {
		$sanitizer = new Sanitizer();
		$markdown  = "See [bad](javascript:alert(1)) and [ok](https://example.com).\n\n```\n[bad](javascript:alert(1))\n```\n";
		$clean     = $sanitizer->clean_markdown( $markdown );

		$this->assertStringContainsString( 'See bad and [ok](https://example.com).', $clean );
		$this->assertStringContainsString( "[bad](javascript:alert(1))\n```", $clean );
		$this->assertSame( '', $sanitizer->clean_markdown( "\xB1\x31" ) );
	}

	/**
	 * Known SEO meta shapes are recognized.
	 *
	 * @return void
	 */
	public function test_robots_meta() {
		$this->assertTrue( Robots::is_noindex( array( '_yoast_wpseo_meta-robots-noindex' => '1' ) ) );
		$this->assertFalse( Robots::is_noindex( array( '_yoast_wpseo_meta-robots-noindex' => '2' ) ) );
		$this->assertTrue( Robots::is_noindex( array( 'rank_math_robots' => array( 'noindex' ) ) ) );
		$this->assertTrue( Robots::is_noindex( array( '_seopress_robots_index' => 'yes' ) ) );
		$this->assertTrue( Robots::is_noindex( array( '_aioseo_robots' => '{"noindex":"on"}' ) ) );
		$this->assertTrue( Robots::is_noindex( array( '_genesis_noindex' => '1' ) ) );
		$this->assertFalse( Robots::is_noindex( array( 'rank_math_robots' => array( 'index' ) ) ) );
	}

	/**
	 * Exclusion rules match IDs, slugs, and paths.
	 *
	 * @return void
	 */
	public function test_exclusions() {
		$rules = Exclusions::parse( "12, Hello\n/parent/child/" );
		$this->assertSame( array( 12 ), $rules['ids'] );
		$this->assertContains( 'hello', $rules['slugs'] );
		$this->assertContains( 'parent/child', $rules['paths'] );
		$this->assertTrue( Exclusions::matches( 12, 'other', '/other', $rules ) );
		$this->assertTrue( Exclusions::matches( 3, 'hello', '/hello', $rules ) );
		$this->assertTrue( Exclusions::matches( 3, 'child', '/blog/parent/child', $rules ) );
		$this->assertFalse( Exclusions::matches( 3, 'about', '/about', $rules ) );
	}

	/**
	 * Permalink mapping covers pretty, root, and plain permalinks.
	 *
	 * @return void
	 */
	public function test_markdown_urls() {
		$this->assertSame( 'https://example.com/about.md', MarkdownUrl::from_permalink( 'https://example.com/about/', 'https://example.com/' ) );
		$this->assertSame( 'https://example.com/index.md', MarkdownUrl::from_permalink( 'https://example.com/', 'https://example.com/' ) );
		$this->assertSame( 'https://example.com/subdir/index.md', MarkdownUrl::from_permalink( 'https://example.com/subdir/', 'https://example.com/subdir/' ) );
		$this->assertSame( 'https://example.com/?p=1&format=markdown', MarkdownUrl::from_permalink( 'https://example.com/?p=1', 'https://example.com/' ) );
		$this->assertSame( 'https://example.com:8443/page.md', MarkdownUrl::from_permalink( 'https://example.com:8443/page/', 'https://example.com:8443/' ) );
	}

	/**
	 * Settings keep safe defaults and reject unexpected values.
	 *
	 * @return void
	 */
	public function test_settings_sanitizer() {
		$clean = SettingsSanitizer::sanitize(
			array(
				'discovery_mode'      => 'nope',
				'enable_markdown'     => '0',
				'post_types_present'  => '1',
				'post_types'          => array( 'page', 'evil', 'post' ),
				'optional_post_types' => array( 'post', 'page' ),
				'section_ranks'       => array(
					'post' => 5,
					'page' => 20,
				),
				'section_labels'      => array( 'page' => "Pages\n<script>" ),
				'summary'             => "  Hello\nthere ",
				'intro'               => "## Hidden\nVisible",
				'max_items'           => '99999',
				'cache_ttl_minutes'   => '10',
				'exclude'             => "12, about\n<script>",
			),
			array( 'post', 'page' )
		);

		$this->assertSame( 'follow', $clean['discovery_mode'] );
		$this->assertSame( 0, $clean['enable_markdown'] );
		$this->assertSame( 1, $clean['enable_llms_full'] );
		$this->assertSame( array( 'post', 'page' ), $clean['section_order'] );
		$this->assertSame( 'Pages', $clean['section_labels']['page'] );
		$this->assertSame( 'Hello there', $clean['summary'] );
		$this->assertStringContainsString( 'Hidden', $clean['intro'] );
		$this->assertStringNotContainsString( '##', $clean['intro'] );
		$this->assertSame( 5000, $clean['max_items'] );
		$this->assertSame( 600, $clean['cache_ttl'] );
		$this->assertStringNotContainsString( '<', $clean['exclude'] );

		$again = SettingsSanitizer::sanitize( $clean, array( 'post', 'page' ) );
		$this->assertSame( $clean['section_order'], $again['section_order'] );
		$this->assertSame( $clean['cache_ttl'], $again['cache_ttl'] );
	}

	/**
	 * Front matter quotes special characters.
	 *
	 * @return void
	 */
	public function test_markdown_document_front_matter() {
		$md = ( new MarkdownDocument() )->compose( new Document( 'Say "hi"', 'https://example.com/hi', '2026-01-01', '2026-01-02', 'page', 'Body' ) );
		$this->assertStringContainsString( 'title: "Say \\"hi\\""', $md );
		$this->assertStringContainsString( "# Say \"hi\"\n", $md );
		$this->assertStringContainsString( "Body\n", $md );
	}

	/**
	 * Block boundaries keep a space in notes.
	 *
	 * @return void
	 */
	public function test_plain_text_separates_adjacent_blocks() {
		$html = '<h2>Welcome</h2><!-- wp:paragraph --><p>This is a <strong>public</strong> page with a <a href="https://example.com/docs">docs link</a>.</p>';
		$this->assertSame( 'Welcome This is a public page with a docs link.', MarkdownText::plain_text( $html ) );
	}
}
