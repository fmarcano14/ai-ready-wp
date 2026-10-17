<?php
/**
 * Pure sanitizer for the settings array.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Rejects unexpected settings values instead of storing them.
 *
 * Missing boolean keys keep the factory default so a read of an older option
 * does not turn features off. A submitted form sends an explicit 0 or 1 for
 * every boolean because each checkbox has a hidden companion field.
 *
 * @since 1.0.0
 */
final class SettingsSanitizer {
	/**
	 * Sanitize a settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input   Raw values.
	 * @param array<int, string>   $allowed Allowed post type slugs.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $allowed ) {
		$defaults = self::default_shape();
		$clean    = $defaults;
		$bools    = array(
			'enable_llms_full',
			'enable_markdown',
			'enable_head_links',
			'markdown_links',
			'link_notes',
		);

		foreach ( $bools as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$clean[ $key ] = $defaults[ $key ];
				continue;
			}
			$clean[ $key ] = self::flag( $input[ $key ] );
		}

		$mode                    = isset( $input['discovery_mode'] ) ? strtolower( (string) $input['discovery_mode'] ) : $defaults['discovery_mode'];
		$clean['discovery_mode'] = in_array( $mode, array( 'follow', 'enabled', 'disabled' ), true ) ? $mode : 'follow';

		$allowed = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'slug' ), $allowed ) ) ) );

		if ( ! empty( $input['post_types_present'] ) ) {
			$selected = isset( $input['post_types'] ) ? $input['post_types'] : array();
		} elseif ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$selected = $input['post_types'];
		} else {
			$selected = $defaults['post_types'];
		}
		$clean['post_types'] = self::slug_list( $selected, $allowed );

		$optional                     = isset( $input['optional_post_types'] ) && is_array( $input['optional_post_types'] ) ? $input['optional_post_types'] : array();
		$clean['optional_post_types'] = self::slug_list( $optional, $clean['post_types'] );

		$ranks = array();
		if ( isset( $input['section_ranks'] ) && is_array( $input['section_ranks'] ) ) {
			foreach ( $input['section_ranks'] as $slug => $rank ) {
				$slug = self::slug( $slug );
				if ( in_array( $slug, $clean['post_types'], true ) ) {
					$ranks[ $slug ] = max( 0, min( 9999, (int) $rank ) );
				}
			}
		}
		foreach ( $clean['post_types'] as $index => $slug ) {
			if ( ! isset( $ranks[ $slug ] ) ) {
				$ranks[ $slug ] = ( $index + 1 ) * 10;
			}
		}
		asort( $ranks, SORT_NUMERIC );
		$clean['section_ranks'] = $ranks;
		$clean['section_order'] = array_keys( $ranks );

		$labels = array();
		if ( isset( $input['section_labels'] ) && is_array( $input['section_labels'] ) ) {
			foreach ( $input['section_labels'] as $slug => $label ) {
				$slug = self::slug( $slug );
				if ( ! in_array( $slug, $clean['post_types'], true ) ) {
					continue;
				}
				$label = self::single_line( $label, 80 );
				if ( '' !== $label ) {
					$labels[ $slug ] = $label;
				}
			}
		}
		$clean['section_labels'] = $labels;

		$clean['exclude'] = self::exclude_text( isset( $input['exclude'] ) ? $input['exclude'] : '' );
		$clean['summary'] = self::single_line( isset( $input['summary'] ) ? $input['summary'] : '', 300 );
		$clean['intro']   = self::intro( isset( $input['intro'] ) ? $input['intro'] : '' );

		if ( array_key_exists( 'cache_ttl_minutes', $input ) ) {
			$clean['cache_ttl'] = self::bound( (int) $input['cache_ttl_minutes'] * 60, 60, 2592000, 3600 );
		} else {
			$clean['cache_ttl'] = self::bound( isset( $input['cache_ttl'] ) ? $input['cache_ttl'] : $defaults['cache_ttl'], 60, 2592000, 3600 );
		}

		$clean['max_items']      = self::bound( isset( $input['max_items'] ) ? $input['max_items'] : $defaults['max_items'], 1, 5000, 500 );
		$clean['max_full_items'] = self::bound( isset( $input['max_full_items'] ) ? $input['max_full_items'] : $defaults['max_full_items'], 0, 2000, 100 );

		unset( $clean['post_types_present'], $clean['cache_ttl_minutes'] );
		return $clean;
	}

	/**
	 * Defaults expressed without WordPress time constants.
	 *
	 * Settings::defaults() is preferred at runtime. This copy exists so the
	 * sanitizer can be exercised before WordPress is loaded. The numbers match.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private static function default_shape() {
		if ( class_exists( Settings::class ) && function_exists( 'get_option' ) ) {
			return Settings::defaults();
		}
		return array(
			'discovery_mode'      => 'follow',
			'enable_llms_full'    => 1,
			'enable_markdown'     => 1,
			'enable_head_links'   => 1,
			'markdown_links'      => 1,
			'link_notes'          => 1,
			'post_types'          => array( 'post', 'page' ),
			'optional_post_types' => array(),
			'section_order'       => array( 'page', 'post' ),
			'section_ranks'       => array(
				'page' => 10,
				'post' => 20,
			),
			'section_labels'      => array(),
			'exclude'             => '',
			'summary'             => '',
			'intro'               => '',
			'max_items'           => 500,
			'max_full_items'      => 100,
			'cache_ttl'           => 3600,
		);
	}

	/**
	 * Cast a checkbox-like value to 1 or 0.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function flag( $value ) {
		return ( 1 === $value || '1' === $value || true === $value ) ? 1 : 0;
	}

	/**
	 * Keep only allowed slugs.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $values  Candidate list.
	 * @param array<int, string> $allowed Allowed slugs.
	 * @return array<int, string>
	 */
	private static function slug_list( $values, array $allowed ) {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$clean = array();
		foreach ( $values as $value ) {
			$slug = self::slug( $value );
			if ( '' !== $slug && in_array( $slug, $allowed, true ) ) {
				$clean[] = $slug;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Normalize a post type slug.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw slug.
	 * @return string
	 */
	private static function slug( $value ) {
		$slug = strtolower( trim( (string) $value ) );
		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
			return '';
		}
		return $slug;
	}

	/**
	 * Clamp an integer.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @param int   $fallback Value used when the input is not numeric.
	 * @return int
	 */
	private static function bound( $value, $min, $max, $fallback ) {
		if ( is_string( $value ) && ! is_numeric( $value ) ) {
			$value = $fallback;
		}
		if ( ! is_numeric( $value ) ) {
			$value = $fallback;
		}
		$value = (int) $value;
		return max( (int) $min, min( (int) $max, $value ) );
	}

	/**
	 * Single-line plain text with a maximum length.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum characters.
	 * @return string
	 */
	private static function single_line( $value, $limit ) {
		// Pure sanitizer. Unit tests run it before WordPress is loaded.
		$value = strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );
		$value = trim( (string) $value );
		if ( strlen( $value ) > $limit ) {
			$value = rtrim( substr( $value, 0, $limit ) );
		}
		return $value;
	}

	/**
	 * Intro text. Heading lines are flattened so they cannot break the file structure.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw intro.
	 * @return string
	 */
	private static function intro( $value ) {
		// Pure sanitizer. Unit tests run it before WordPress is loaded.
		$value = strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		$value = str_replace( array( "\0", "\r\n", "\r" ), array( '', "\n", "\n" ), $value );
		$lines = explode( "\n", $value );
		$out   = array();
		foreach ( $lines as $line ) {
			$trimmed = ltrim( $line );
			if ( preg_match( '/^#{1,6}\s+/', $trimmed ) ) {
				$trimmed = preg_replace( '/^#{1,6}\s+/', '', $trimmed );
				$out[]   = rtrim( (string) $trimmed );
				continue;
			}
			$out[] = rtrim( $line );
		}
		$text = trim( implode( "\n", $out ) );
		if ( strlen( $text ) > 5000 ) {
			$text = rtrim( substr( $text, 0, 5000 ) );
		}
		return $text;
	}

	/**
	 * Keep the characters that can express IDs, slugs, and paths.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw exclusion list.
	 * @return string
	 */
	private static function exclude_text( $value ) {
		$value = str_replace( array( "\0", "\r\n", "\r" ), array( '', "\n", "\n" ), (string) $value );
		if ( 1 === preg_match( '//u', $value ) ) {
			$value = preg_replace( '/[^\p{L}\p{N},\/_.\-\s]/u', '', $value );
		} else {
			$value = preg_replace( '/[^0-9A-Za-z,\/_.\-\s]/', '', $value );
		}
		$value = trim( (string) $value );
		if ( strlen( $value ) > 10000 ) {
			$value = substr( $value, 0, 10000 );
		}
		return $value;
	}
}
