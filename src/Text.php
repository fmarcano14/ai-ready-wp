<?php
/**
 * Plain-text cleanup for notes and summaries.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multibyte trimming and shortcode removal.
 */
final class Text {

	/**
	 * Remove shortcode tokens, including the inner text of paired tags.
	 *
	 * Core strip_shortcodes() only drops registered shortcodes, so an unknown
	 * token would otherwise be copied into a public file.
	 */
	public static function strip_shortcodes( string $text ): string {
		$current  = $text;
		$previous = null;
		$paired   = '/\[([A-Za-z][A-Za-z0-9_-]*)(?:\s[^\[\]]*)?\].*?\[\/\1\]/s';
		$single   = '/\[\/?[A-Za-z][A-Za-z0-9_-]*(?:\s[^\[\]]*)?\]/';

		while ( $current !== $previous ) {
			$previous = $current;
			$next     = preg_replace( $paired, '', $current );
			if ( is_string( $next ) ) {
				$current = $next;
			}
			$next = preg_replace( $single, '', $current );
			if ( is_string( $next ) ) {
				$current = $next;
			}
		}

		return $current;
	}

	/**
	 * Tag-stripped text with shortcodes already removed.
	 */
	public static function plain( string $html ): string {
		$html = self::strip_shortcodes( $html );
		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $html );
		if ( ! is_string( $html ) ) {
			$html = '';
		}
		$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $html );
		if ( ! is_string( $html ) ) {
			$html = '';
		}

		// Pure helper: wp_strip_all_tags() is not available outside WordPress.
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		return self::collapse( $text );
	}

	/**
	 * Prefer a manual excerpt. Otherwise use rendered, tag-stripped text.
	 */
	public static function note( string $excerpt, string $rendered_html, int $limit ): string {
		$source = '' !== trim( $excerpt ) ? $excerpt : $rendered_html;
		return self::boundary( self::plain( $source ), $limit );
	}

	/**
	 * Cut on a sentence or word boundary without splitting a UTF-8 character.
	 */
	public static function boundary( string $text, int $limit ): string {
		if ( ! extension_loaded( 'mbstring' ) ) {
			return '';
		}
		$text = self::collapse( self::valid_utf8( $text ) );
		if ( $limit < 1 || '' === $text ) {
			return '';
		}
		if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return $text;
		}

		$slice    = mb_substr( $text, 0, $limit, 'UTF-8' );
		$sentence = self::last_sentence_end( $slice );
		$min      = (int) floor( $limit * 0.4 );
		if ( $sentence >= $min && $sentence > 0 ) {
			return rtrim( mb_substr( $slice, 0, $sentence, 'UTF-8' ) );
		}

		$space = mb_strrpos( $slice, ' ', 0, 'UTF-8' );
		if ( false !== $space && $space > 0 ) {
			return rtrim( mb_substr( $slice, 0, $space, 'UTF-8' ) );
		}

		return $slice;
	}

	/**
	 * Collapse whitespace so a value can sit on one Markdown line.
	 */
	public static function one_line( string $text ): string {
		return self::collapse( self::valid_utf8( $text ) );
	}

	private static function valid_utf8( string $text ): string {
		if ( ! extension_loaded( 'mbstring' ) ) {
			return 1 === preg_match( '//u', $text ) ? $text : '';
		}
		if ( mb_check_encoding( $text, 'UTF-8' ) ) {
			return $text;
		}
		$converted = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		return is_string( $converted ) ? $converted : '';
	}

	private static function collapse( string $text ): string {
		$text      = str_replace( "\xC2\xA0", ' ', $text );
		$collapsed = preg_replace( '/\s+/u', ' ', trim( $text ) );
		return is_string( $collapsed ) ? $collapsed : trim( $text );
	}

	/**
	 * Character offset just after the last sentence end, or -1.
	 */
	private static function last_sentence_end( string $slice ): int {
		$best = -1;
		$len  = mb_strlen( $slice, 'UTF-8' );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = mb_substr( $slice, $i, 1, 'UTF-8' );
			if ( '.' !== $char && '!' !== $char && '?' !== $char ) {
				continue;
			}
			$next = ( $i + 1 < $len ) ? mb_substr( $slice, $i + 1, 1, 'UTF-8' ) : ' ';
			if ( ' ' === $next || "\n" === $next || ( $i + 1 ) === $len ) {
				$best = $i + 1;
			}
		}
		return $best;
	}
}
