<?php
/**
 * Inclusion rule for public files.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\Inclusion;
use AiReady\Noindex;
use AiReady\PostFacts;
use PHPUnit\Framework\TestCase;

/**
 * Which posts are allowed.
 */
final class InclusionTest extends TestCase {

	public function test_only_published_public_documents_are_allowed(): void {
		$types = array( 'post', 'page' );
		$this->assertTrue( Inclusion::allowed( $this->facts( array() ), $types ) );

		foreach ( array( 'draft', 'private', 'future', 'pending', 'trash' ) as $status ) {
			$this->assertFalse(
				Inclusion::allowed( $this->facts( array( 'status' => $status ) ), $types ),
				$status
			);
		}

		$this->assertFalse( Inclusion::allowed( $this->facts( array( 'has_password' => true ) ), $types ) );
		$this->assertFalse( Inclusion::allowed( $this->facts( array( 'public_type' => false ) ), $types ) );
		$this->assertFalse( Inclusion::allowed( $this->facts( array( 'excluded' => true ) ), $types ) );
		$this->assertFalse( Inclusion::allowed( $this->facts( array( 'noindex' => true ) ), $types ) );
		$this->assertFalse( Inclusion::allowed( $this->facts( array( 'type' => 'revision' ) ), $types ) );
		$this->assertFalse( Inclusion::allowed( $this->facts( array( 'type' => 'post' ) ), array( 'page' ) ) );
	}

	public function test_common_seo_noindex_flags_are_detected(): void {
		$this->assertTrue( Noindex::detect( array( '_yoast_wpseo_meta-robots-noindex' => '1' ) ) );
		$this->assertFalse( Noindex::detect( array( '_yoast_wpseo_meta-robots-noindex' => '2' ) ) );
		$this->assertTrue( Noindex::detect( array( 'rank_math_robots' => array( 'noindex', 'nofollow' ) ) ) );
		$this->assertTrue( Noindex::detect( array( '_seopress_robots_index' => 'yes' ) ) );
		$this->assertTrue( Noindex::detect( array( '_aioseop_noindex' => 'on' ) ) );
		$this->assertTrue( Noindex::detect( array( '_aioseo_noindex' => '1' ) ) );
		$this->assertTrue( Noindex::detect( array( '_genesis_noindex' => '1' ) ) );
		$this->assertFalse( Noindex::detect( array( 'rank_math_robots' => array( 'index' ) ) ) );
	}

	/**
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function facts( array $overrides ): PostFacts {
		$data = array_merge(
			array(
				'id'           => 1,
				'status'       => 'publish',
				'type'         => 'post',
				'public_type'  => true,
				'has_password' => false,
				'excluded'     => false,
				'noindex'      => false,
			),
			$overrides
		);

		return new PostFacts(
			(int) $data['id'],
			(string) $data['status'],
			(string) $data['type'],
			(bool) $data['public_type'],
			(bool) $data['has_password'],
			(bool) $data['excluded'],
			(bool) $data['noindex']
		);
	}
}
