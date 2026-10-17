<?php
/**
 * Markdown URLs, including the static front page.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\Urls;
use PHPUnit\Framework\TestCase;

/**
 * Pure permalink to markdown URL mapping.
 */
final class UrlsTest extends TestCase {

	public function test_front_page_is_index_md_on_the_same_host(): void {
		$this->assertSame(
			'https://example.com/index.md',
			Urls::from_permalink( 'https://example.com/', true )
		);
		$this->assertSame(
			'https://example.com/index.md',
			Urls::from_permalink( 'https://example.com', true )
		);
		$this->assertSame(
			'https://example.com/blog/index.md',
			Urls::from_permalink( 'https://example.com/blog/', true )
		);
	}

	public function test_a_root_permalink_is_never_a_dot_md_domain(): void {
		$url = Urls::from_permalink( 'https://example.com/', false );
		$this->assertSame( 'https://example.com/index.md', $url );
		$this->assertStringNotContainsString( 'example.com.md', $url );
	}

	public function test_child_pages_keep_their_path(): void {
		$this->assertSame(
			'https://example.com/news.md',
			Urls::from_permalink( 'https://example.com/news/', false )
		);
		$this->assertSame(
			'https://example.com/parent/child.md',
			Urls::from_permalink( 'https://example.com/parent/child/', false )
		);
	}

	public function test_query_permalinks_ask_for_markdown(): void {
		$this->assertSame(
			'https://example.com/?page_id=4&format=markdown',
			Urls::from_permalink( 'https://example.com/?page_id=4', false )
		);
		$this->assertSame(
			'https://example.com/?p=9&format=markdown',
			Urls::from_permalink( 'https://example.com/?p=9&format=markdown', true )
		);
	}
}
