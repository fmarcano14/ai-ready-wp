<?php
/**
 * File storage is the only copy of generated output.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\Document;
use AiReady\Settings;
use AiReady\Store;
use PHPUnit\Framework\TestCase;

/**
 * Store behavior.
 */
final class StoreTest extends TestCase {

	public function test_replace_converges_and_drops_stale_documents(): void {
		$dir   = sys_get_temp_dir() . '/ai-ready-' . bin2hex( random_bytes( 4 ) );
		$store = new Store( $dir );
		$first = $this->document( 1, 'River' );
		$second = $this->document( 2, 'Old' );

		$store->replace( "# Café 🌧️\n", "# Full\n", array( $first, $second ) );
		$this->assertSame( "# Café 🌧️\n", $store->read_index() );
		$this->assertSame( "# Full\n", $store->read_full() );
		$this->assertSame( "# River\n", $store->read_document( 1 ) );
		$this->assertSame( array( 1, 2 ), $store->document_ids() );
		$this->assertNotSame( 0, $store->generated_at() );

		$store->replace( "# Café 🌧️\n", null, array( $first ) );
		$this->assertSame( "# Café 🌧️\n", $store->read_index() );
		$this->assertNull( $store->read_full() );
		$this->assertNull( $store->read_document( 2 ) );
		$this->assertFalse( $store->has_full() );
		$this->assertSame( array( 1 ), $store->document_ids() );

		$store->destroy();
		$this->assertDirectoryDoesNotExist( $dir );
	}

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

	private function document( int $id, string $title ): Document {
		return new Document(
			$id,
			$title,
			'https://example.com/' . $id . '/',
			'https://example.com/' . $id . '.md',
			'note',
			'# ' . $title . "\n",
			'Posts',
			false
		);
	}
}
