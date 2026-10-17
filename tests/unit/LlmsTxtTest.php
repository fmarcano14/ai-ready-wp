<?php
/**
 * Expected llms.txt shape from the public spec, not from a previous run.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\Catalog;
use AiReady\Document;
use AiReady\LlmsTxt;
use PHPUnit\Framework\TestCase;

/**
 * Builder output.
 */
final class LlmsTxtTest extends TestCase {

	public function test_index_follows_the_llms_txt_shape(): void {
		$index = ( new LlmsTxt() )->index( $this->catalog() );

		$expected  = "# Café 🌧️\n";
		$expected .= "\n";
		$expected .= "> Resumen ñ del sitio\n";
		$expected .= "\n";
		$expected .= "Short details stay under the summary.\n";
		$expected .= "\n";
		$expected .= "## Posts\n";
		$expected .= "\n";
		$expected .= "- [River notes](https://example.com/river.md): Field notes from the last trip\n";
		$expected .= "\n";
		$expected .= "## Optional\n";
		$expected .= "\n";
		$expected .= "- [Old checklist](https://example.com/old.md)\n";

		$this->assertSame( $expected, $index );
		$this->assertTrue( mb_check_encoding( $index, 'UTF-8' ) );
		$this->assertSame( 1, substr_count( $index, "\n# " ) + ( 0 === strpos( $index, '# ' ) ? 1 : 0 ) );
	}

	public function test_index_is_stable_when_built_twice(): void {
		$builder = new LlmsTxt();
		$catalog = $this->catalog();
		$this->assertSame( $builder->index( $catalog ), $builder->index( $catalog ) );
	}

	public function test_empty_summary_still_produces_a_valid_file(): void {
		$catalog = new Catalog( 'Café', '', '', array() );
		$index   = ( new LlmsTxt() )->index( $catalog );

		$this->assertStringStartsWith( "# Café\n", $index );
		$this->assertStringContainsString( '> Public content from Café.', $index );
		$this->assertStringNotContainsString( "## Optional\n", $index );
		$this->assertNotSame( '', trim( $index ) );
		$this->assertTrue( mb_check_encoding( $index, 'UTF-8' ) );
	}

	public function test_full_file_contains_each_document_body(): void {
		$full = ( new LlmsTxt() )->full( $this->catalog() );
		$this->assertStringContainsString( '# Café 🌧️', $full );
		$this->assertStringContainsString( 'Body of the river notes.', $full );
		$this->assertStringContainsString( '# Old checklist', $full );
		$this->assertTrue( mb_check_encoding( $full, 'UTF-8' ) );
	}

	public function test_page_keeps_an_accented_title(): void {
		$page = LlmsTxt::page( 'Niño 🌧️', 'Una línea.' );
		$this->assertSame( "# Niño 🌧️\n\nUna línea.\n", $page );
		$this->assertTrue( mb_check_encoding( $page, 'UTF-8' ) );
	}

	public function test_link_titles_do_not_break_the_markdown_link(): void {
		$catalog = new Catalog(
			'Site',
			'Summary',
			'',
			array(
				new Document(
					3,
					'A [weird] title',
					'https://example.com/a/',
					'https://example.com/a (1).md',
					'',
					"# A title\n",
					'Posts',
					false
				),
			)
		);
		$index   = ( new LlmsTxt() )->index( $catalog );
		$this->assertStringContainsString( '[A (weird) title](https://example.com/a%20%281%29.md)', $index );
		$this->assertStringNotContainsString( "## Optional\n", $index );
	}

	private function catalog(): Catalog {
		return new Catalog(
			'Café 🌧️',
			'Resumen ñ del sitio',
			"Short details stay under the summary.\n## Not a section",
			array(
				new Document(
					1,
					'River notes',
					'https://example.com/river/',
					'https://example.com/river.md',
					'Field notes from the last trip',
					"# River notes\n\nBody of the river notes.\n",
					'Posts',
					false
				),
				new Document(
					2,
					'Old checklist',
					'https://example.com/old/',
					'https://example.com/old.md',
					'',
					"# Old checklist\n\nArchive.\n",
					'Pages',
					true
				),
			)
		);
	}
}
