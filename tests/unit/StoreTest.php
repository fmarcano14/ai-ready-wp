<?php
/**
 * Settings parsing does not need WordPress. Storage is covered with WordPress loaded.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings behavior.
 */
final class StoreTest extends TestCase {

	public function test_settings_sanitize_rejects_unknown_types_and_shortcodes_in_text(): void {
		$settings = Settings::from_array(
			array(
				'summary'        => "  Hello\n<script>alert(1)</script> ñ  ",
				'details'        => "<b>Details</b>\nsecond",
				'post_types'     => array( 'post', 'Not A Type', 'page' ),
				'optional_types' => array( 'page', 'other' ),
				'write_full'     => '1',
				'note_length'    => 10,
			),
			array( 'post', 'page' )
		);

		$this->assertSame( 'Hello alert(1) ñ', $settings->summary() );
		$this->assertStringNotContainsString( '<', $settings->summary() );
		$this->assertSame( "Details\nsecond", $settings->details() );
		$this->assertSame( array( 'post', 'page' ), $settings->post_types() );
		$this->assertSame( array( 'page' ), $settings->optional_types() );
		$this->assertTrue( $settings->is_optional( 'page' ) );
		$this->assertFalse( $settings->is_optional( 'post' ) );
		$this->assertSame( 40, $settings->note_length() );
		$this->assertTrue( $settings->write_full() );
	}
}
