<?php
/**
 * Small text helpers shared by the converter and the llms.txt builder.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Content;

/**
 * Normalizes labels and notes so they stay on one Markdown line.
 *
 * @since 1.0.0
 */
final class MarkdownText {
	/**
	 * Collapse a string to a single trimmed line.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function single_line( $text ) {
		// This helper is loaded by unit tests that do not bootstrap WordPress.
		$text = strip_tags( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		if ( self::is_utf8( $text ) ) {
			$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
			$text = preg_replace( '/\s+/u', ' ', $text );
		} else {
			$text = str_replace( array( "\r", "\n", "\t" ), ' ', $text );
			$text = preg_replace( '/\s+/', ' ', $text );
		}
		if ( ! is_string( $text ) ) {
			return '';
		}
		return trim( $text );
	}

	/**
	 * Escape a link label so it cannot close the Markdown link early.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Label.
	 * @return string
	 */
	public static function escape_link_label( $text ) {
		$text = self::single_line( $text );
		return str_replace(
			array( '\\', '[', ']' ),
			array( '\\\\', '\\[', '\\]' ),
			$text
		);
	}

	/**
	 * Whether a string is valid UTF-8.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Candidate text.
	 * @return bool
	 */
	private static function is_utf8( $value ) {
		return 1 === preg_match( '//u', (string) $value );
	}
}
