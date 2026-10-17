<?php
/**
 * Index membership and rendered llms.txt.
 *
 * @package AiReadyWp
 */

namespace Fer\AiReady\Tests\Unit;

use Fer\AiReady\Content\LlmsTxtBuilder;
use Fer\AiReady\Domain\Index;
use Fer\AiReady\Domain\Link;
use Fer\AiReady\Domain\Membership;
use Fer\AiReady\Domain\Section;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Fer\AiReady\Domain\Membership
 * @covers \Fer\AiReady\Content\LlmsTxtBuilder
 */
final class IndexMembershipTest extends TestCase {
	/**
	 * The index lists only public documents, in section order, using the spec shape.
	 *
	 * @return void
	 */
	public function test_index_lists_only_public_documents() {
		$candidates = array(
			array(
				'title'    => 'About',
				'url'      => 'https://example.com/about.md',
				'note'     => 'A public page',
				'heading'  => 'Pages',
				'optional' => false,
				'status'   => 'publish',
				'selected' => true,
			),
			array(
				'title'    => 'Secret',
				'url'      => 'https://example.com/secret.md',
				'note'     => 'Hidden',
				'heading'  => 'Posts',
				'optional' => false,
				'status'   => 'publish',
				'password' => true,
				'selected' => true,
			),
			array(
				'title'    => 'Noindex note',
				'url'      => 'https://example.com/noindex-note.md',
				'note'     => 'Hidden',
				'heading'  => 'Posts',
				'optional' => false,
				'status'   => 'publish',
				'selected' => true,
				'noindex'  => true,
			),
			array(
				'title'    => 'Excluded note',
				'url'      => 'https://example.com/excluded-note.md',
				'note'     => 'Hidden',
				'heading'  => 'Posts',
				'optional' => false,
				'status'   => 'publish',
				'selected' => true,
				'excluded' => true,
			),
			array(
				'title'    => 'Field notes',
				'url'      => 'https://example.com/field-notes.md',
				'note'     => 'Garden',
				'heading'  => 'Posts',
				'optional' => false,
				'status'   => 'publish',
				'selected' => true,
			),
			array(
				'title'    => 'Archive',
				'url'      => 'https://example.com/old.md',
				'note'     => '',
				'heading'  => 'Notes',
				'optional' => true,
				'status'   => 'publish',
				'selected' => true,
			),
			array(
				'title'    => 'Bad',
				'url'      => 'javascript:alert(1)',
				'note'     => 'nope',
				'heading'  => 'Posts',
				'optional' => false,
				'status'   => 'publish',
				'selected' => true,
			),
		);

		$grouped = array();
		foreach ( $candidates as $candidate ) {
			if ( ! Membership::keeps( $candidate ) ) {
				continue;
			}
			$key = $candidate['heading'] . '|' . ( ! empty( $candidate['optional'] ) ? '1' : '0' );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = new Section( $candidate['heading'], array(), ! empty( $candidate['optional'] ) );
			}
			$grouped[ $key ]->links[] = new Link( $candidate['title'], $candidate['url'], $candidate['note'] );
		}

		$markdown = ( new LlmsTxtBuilder() )->build(
			new Index( 'Demo', 'A tagline', 'Details here.', array_values( $grouped ) )
		);

		$expected  = "# Demo\n\n";
		$expected .= "> A tagline\n\n";
		$expected .= "Details here.\n\n";
		$expected .= "## Pages\n\n";
		$expected .= "- [About](https://example.com/about.md): A public page\n\n";
		$expected .= "## Posts\n\n";
		$expected .= "- [Field notes](https://example.com/field-notes.md): Garden\n\n";
		$expected .= "## Optional\n\n";
		$expected .= "- [Archive](https://example.com/old.md)\n";

		$this->assertSame( $expected, $markdown );
	}
}
