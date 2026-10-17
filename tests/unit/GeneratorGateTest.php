<?php
/**
 * Public code paths are not allowed to rebuild.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\Generator;
use PHPUnit\Framework\TestCase;

/**
 * The generation gate.
 */
final class GeneratorGateTest extends TestCase {

	public function test_the_catalog_cap_is_ten_thousand_documents_and_ten_megabytes(): void {
		$this->assertSame( 50, Generator::BATCH_SIZE );
		$this->assertSame( 10000, Generator::MAX_ITEMS );
		$this->assertSame( 10485760, Generator::FULL_BYTES );
	}

	public function test_a_normal_call_is_not_allowed_to_generate(): void {
		$this->assertFalse( Generator::generation_allowed() );
	}

	public function test_trusted_callers_may_generate_and_the_gate_closes_again(): void {
		$allowed = Generator::trusted(
			static function () {
				return Generator::generation_allowed();
			}
		);
		$this->assertTrue( $allowed );
		$this->assertFalse( Generator::generation_allowed() );
	}

	public function test_run_without_trust_does_not_require_wordpress_output(): void {
		$generator = new Generator();
		$this->assertSame( 0, $generator->run() );
	}
}
