<?php
/**
 * Notes, shortcode stripping, and multibyte trimming.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\Text;
use PHPUnit\Framework\TestCase;

/**
 * Text helpers.
 */
final class TextTest extends TestCase {

	public function test_note_prefers_the_excerpt_and_strips_shortcodes(): void {
		$note = Text::note( 'Manual excerpt [gallery id="9"] here', '<p>ignored [secret]HIDDEN[/secret]</p>', 200 );
		$this->assertSame( 'Manual excerpt here', $note );
		$this->assertStringNotContainsString( '[gallery', $note );
		$this->assertStringNotContainsString( 'HIDDEN', $note );
	}

	public function test_note_falls_back_to_rendered_text_without_shortcodes_or_tags(): void {
		$html = '<p>Hello <strong>ñ</strong> [secret]HIDDEN[/secret] world</p>';
		$note = Text::note( '   ', $html, 200 );
		$this->assertSame( 'Hello ñ world', $note );
		$this->assertStringNotContainsString( '[secret]', $note );
		$this->assertStringNotContainsString( 'HIDDEN', $note );
		$this->assertStringNotContainsString( '<', $note );
	}

	public function test_trim_is_multibyte_safe_for_accents_and_emoji(): void {
		$this->assertSame( 'éñ', Text::boundary( 'éñ🌧️ word', 2 ) );
		$this->assertSame( 'Café niño', Text::boundary( 'Café niño extra words here', 12 ) );
		$this->assertSame( 'Hola niño.', Text::boundary( 'Hola niño. Más texto largo después', 20 ) );

		$emoji = Text::boundary( '🌧️🌧️🌧️', 1 );
		$this->assertNotSame( '', $emoji );
		$this->assertTrue( mb_check_encoding( $emoji, 'UTF-8' ) );
		$this->assertSame( 'ñ', Text::boundary( 'ñ', 10 ) );
		$this->assertSame( 'é', Text::boundary( 'é', 1 ) );
	}

	public function test_a_broken_byte_does_not_yield_invalid_text(): void {
		$note = Text::boundary( "Café \xC3 niño", 40 );
		$this->assertTrue( mb_check_encoding( $note, 'UTF-8' ) );
		$this->assertNotSame( '', $note );
		$this->assertStringContainsString( 'Café', $note );
	}

	public function test_long_token_without_spaces_stays_valid_utf8(): void {
		$long   = str_repeat( 'é', 50 );
		$result = Text::boundary( $long, 10 );
		$this->assertSame( str_repeat( 'é', 10 ), $result );
		$this->assertTrue( mb_check_encoding( $result, 'UTF-8' ) );
	}
}
