<?php
/**
 * Second boundary for Markdown that will be sent to the public.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Checks encoding, size, link destinations, and stray HTML.
 *
 * The converter already applies the URL allowlist. This pass runs again on
 * the finished string, including anything added by a filter, and refuses
 * input that is not valid UTF-8. There is no raw-input fallback.
 *
 * @since 1.0.0
 */
final class Sanitizer {
	const MAX_DOCUMENT_BYTES = 524288;
	const MAX_INDEX_BYTES    = 1048576;
	const MAX_FULL_BYTES     = 2097152;

	/**
	 * Clean a Markdown document.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $markdown  Candidate Markdown.
	 * @param int   $max_bytes Maximum accepted size.
	 * @return string Empty when the input is not usable text.
	 */
	public function clean_markdown( $markdown, $max_bytes = self::MAX_DOCUMENT_BYTES ) {
		if ( ! is_string( $markdown ) || 1 !== preg_match( '//u', $markdown ) ) {
			return '';
		}

		$markdown  = str_replace( array( "\0", "\r\n", "\r" ), array( '', "\n", "\n" ), $markdown );
		$max_bytes = (int) $max_bytes;
		if ( $max_bytes < 1 ) {
			$max_bytes = self::MAX_DOCUMENT_BYTES;
		}
		if ( strlen( $markdown ) > $max_bytes ) {
			$markdown = substr( $markdown, 0, $max_bytes );
			$break    = strrpos( $markdown, "\n" );
			if ( false !== $break ) {
				$markdown = substr( $markdown, 0, $break );
			}
			$markdown = rtrim( $markdown ) . "\n\n_Truncated to the output limit._\n";
		}

		$lines      = explode( "\n", $markdown );
		$in_fence   = false;
		$fence_char = '';
		$fence_len  = 0;
		foreach ( $lines as $index => $line ) {
			if ( preg_match( '/^[ ]{0,3}(`{3,}|~{3,})/', $line, $match ) ) {
				$marker = $match[1];
				$char   = $marker[0];
				$len    = strlen( $marker );
				if ( ! $in_fence ) {
					$in_fence   = true;
					$fence_char = $char;
					$fence_len  = $len;
				} elseif ( $char === $fence_char && $len >= $fence_len ) {
					$in_fence = false;
				}
				continue;
			}
			if ( $in_fence ) {
				continue;
			}
			$line = $this->neutralize_resources( $line );
			// The sanitizer is pure and is exercised without WordPress loaded.
			$lines[ $index ] = strip_tags( $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		}

		return implode( "\n", $lines );
	}

	/**
	 * Drop images and links whose destinations fail the allowlist.
	 *
	 * @since 1.0.0
	 *
	 * @param string $line One Markdown line outside a code fence.
	 * @return string
	 */
	private function neutralize_resources( $line ) {
		$destination = '((?:[^()]|\([^()]*\))*)';
		$line        = preg_replace_callback(
			'/!\[([^\]]*)\]\(' . $destination . '\)/',
			static function ( $matches ) {
				if ( UrlPolicy::allows( $matches[2], true ) ) {
					return $matches[0];
				}
				return $matches[1];
			},
			$line
		);
		$line        = preg_replace_callback(
			'/(?<!!)\[([^\]]*)\]\(' . $destination . '\)/',
			static function ( $matches ) {
				if ( UrlPolicy::allows( $matches[2], false ) ) {
					return $matches[0];
				}
				return $matches[1];
			},
			(string) $line
		);
		return is_string( $line ) ? $line : '';
	}
}
