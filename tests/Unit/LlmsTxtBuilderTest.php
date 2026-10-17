<?php
/**
 * Llms.txt builder tests.
 *
 * @package AiReadyWp
 */

namespace Fer\AiReady\Tests\Unit;

use Fer\AiReady\Content\LlmsFullBuilder;
use Fer\AiReady\Content\LlmsTxtBuilder;
use Fer\AiReady\Domain\Document;
use Fer\AiReady\Domain\Index;
use Fer\AiReady\Domain\Link;
use Fer\AiReady\Domain\Section;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Fer\AiReady\Content\LlmsTxtBuilder
 * @covers \Fer\AiReady\Content\LlmsFullBuilder
 */
final class LlmsTxtBuilderTest extends TestCase {
	/**
	 * The builder matches the specification example.
	 *
	 * @return void
	 */
	public function test_builds_the_spec_shape() {
		$builder = new LlmsTxtBuilder();
		$md      = $builder->build(
			$this->index(
				'Title',
				'Optional description goes here',
				'Optional details go here',
				array(
					array(
						'heading'  => 'Section name',
						'optional' => false,
						'links'    => array(
							array(
								'name' => 'Link title',
								'url'  => 'https://example.com/docs',
								'note' => 'Optional link details',
							),
						),
					),
					array(
						'heading'  => 'Skipped heading',
						'optional' => true,
						'links'    => array(
							array(
								'name' => 'Other',
								'url'  => 'https://example.com/other',
								'note' => '',
							),
						),
					),
				)
			)
		);

		$expected = "# Title\n\n> Optional description goes here\n\nOptional details go here\n\n## Section name\n\n- [Link title](https://example.com/docs): Optional link details\n\n## Optional\n\n- [Other](https://example.com/other)\n";
		$this->assertSame( $expected, $md );
	}

	/**
	 * A multiline summary stays a blockquote and empty sections disappear.
	 *
	 * @return void
	 */
	public function test_blockquote_and_empty_sections() {
		$md = ( new LlmsTxtBuilder() )->build(
			$this->index(
				' # Site ',
				"Line one\nLine two",
				"## Not a section\nStill details",
				array(
					array(
						'heading' => 'Empty',
						'links'   => array(),
					),
				)
			)
		);

		$this->assertStringStartsWith( "# Site\n", $md );
		$this->assertStringContainsString( "> Line one\n> Line two", $md );
		$this->assertStringContainsString( "Not a section\nStill details", $md );
		$this->assertStringNotContainsString( '## Empty', $md );
		$this->assertStringNotContainsString( '## Not a section', $md );
	}

	/**
	 * Labels and unsafe URLs cannot break the list.
	 *
	 * @return void
	 */
	public function test_escapes_labels_and_drops_unsafe_urls() {
		$md = ( new LlmsTxtBuilder() )->build(
			$this->index(
				'Site',
				'',
				'',
				array(
					array(
						'heading' => 'Docs',
						'links'   => array(
							array(
								'name' => 'A [b]',
								'url'  => 'https://example.com/a)',
								'note' => "first\nsecond",
							),
							array(
								'name' => 'Bad',
								'url'  => 'javascript:alert(1)',
								'note' => 'nope',
							),
						),
					),
				)
			)
		);

		$this->assertStringContainsString( '- [A \[b\]](https://example.com/a%29): first second', $md );
		$this->assertStringNotContainsString( 'javascript:', $md );
	}

	/**
	 * The full file keeps the header and each document.
	 *
	 * @return void
	 */
	public function test_full_file_concatenates_documents() {
		$one             = new Document( 'One', 'https://example.com/one', '2026-01-01', '2026-01-01', 'page', 'Body' );
		$one->markdown   = "# One\n\nBody";
		$empty           = new Document( 'Empty', 'https://example.com/empty', '2026-01-01', '2026-01-01', 'page', '' );
		$empty->markdown = '';
		$md              = ( new LlmsFullBuilder() )->build(
			new Index( 'Site', 'Summary', 'Intro', array() ),
			array( $one, $empty )
		);

		$this->assertStringContainsString( "# Site\n", $md );
		$this->assertStringContainsString( '> Summary', $md );
		$this->assertStringContainsString( "---\n\n# One\n\nBody\n", $md );
	}

	/**
	 * Build an index from plain rows. The rows are test input, not the production shape.
	 *
	 * @param string            $name     H1.
	 * @param string            $summary  Summary.
	 * @param string            $details  Details.
	 * @param array<int, mixed> $sections Section rows.
	 * @return Index
	 */
	private function index( $name, $summary, $details, array $sections ) {
		$built = array();
		foreach ( $sections as $section ) {
			$links = array();
			$rows  = isset( $section['links'] ) && is_array( $section['links'] ) ? $section['links'] : array();
			foreach ( $rows as $row ) {
				$links[] = new Link(
					isset( $row['name'] ) ? $row['name'] : '',
					isset( $row['url'] ) ? $row['url'] : '',
					isset( $row['note'] ) ? $row['note'] : ''
				);
			}
			$built[] = new Section(
				isset( $section['heading'] ) ? $section['heading'] : '',
				$links,
				! empty( $section['optional'] )
			);
		}
		return new Index( $name, $summary, $details, $built );
	}
}
