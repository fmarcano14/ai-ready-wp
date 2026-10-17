<?php
/**
 * Saved plugin settings.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable settings. from_array() does not call WordPress.
 */
final class Settings {

	public const OPTION = 'ai_ready_settings';

	private string $summary;

	private string $details;

	/**
	 * Enabled post types.
	 *
	 * @var string[]
	 */
	private array $post_types;

	/**
	 * Post types listed under Optional.
	 *
	 * @var string[]
	 */
	private array $optional_types;

	private bool $write_full;

	private int $note_length;

	/**
	 * @param string[] $post_types     Enabled types.
	 * @param string[] $optional_types Optional types.
	 */
	public function __construct( string $summary, string $details, array $post_types, array $optional_types, bool $write_full, int $note_length ) {
		$this->summary        = $summary;
		$this->details        = $details;
		$this->post_types     = $post_types;
		$this->optional_types = $optional_types;
		$this->write_full     = $write_full;
		$this->note_length    = $note_length;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'summary'        => '',
			'details'        => '',
			'post_types'     => array( 'post', 'page' ),
			'optional_types' => array(),
			'write_full'     => true,
			'note_length'    => 200,
		);
	}

	public static function current(): self {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::from_array( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * @param array<string, mixed> $input         Raw input.
	 * @param string[]             $allowed_types Public types the site may enable. Empty skips that filter.
	 */
	public static function from_array( array $input, array $allowed_types = array() ): self {
		$summary = Text::one_line( self::plain( self::string_value( $input, 'summary' ) ) );
		if ( mb_strlen( $summary, 'UTF-8' ) > 400 ) {
			$summary = Text::boundary( $summary, 400 );
		}
		$details = self::details_text( self::string_value( $input, 'details' ) );
		$types   = self::types( isset( $input['post_types'] ) ? $input['post_types'] : array() );
		if ( array() !== $allowed_types ) {
			$types = array_values( array_intersect( $types, $allowed_types ) );
		}
		if ( array() === $types ) {
			if ( array() === $allowed_types ) {
				$types = array( 'post', 'page' );
			} else {
				$preferred = array_values( array_intersect( array( 'post', 'page' ), $allowed_types ) );
				$types     = array() !== $preferred ? $preferred : array( (string) reset( $allowed_types ) );
			}
		}
		$optional = self::types( isset( $input['optional_types'] ) ? $input['optional_types'] : array() );
		$optional = array_values( array_intersect( $optional, $types ) );
		$length   = isset( $input['note_length'] ) ? (int) $input['note_length'] : 200;
		if ( $length < 40 ) {
			$length = 40;
		}
		if ( $length > 500 ) {
			$length = 500;
		}
		$write_full = ! empty( $input['write_full'] );

		return new self( $summary, $details, $types, $optional, $write_full, $length );
	}

	public function summary(): string {
		return $this->summary;
	}

	public function details(): string {
		return $this->details;
	}

	/**
	 * @return string[]
	 */
	public function post_types(): array {
		return $this->post_types;
	}

	/**
	 * @return string[]
	 */
	public function optional_types(): array {
		return $this->optional_types;
	}

	public function write_full(): bool {
		return $this->write_full;
	}

	public function note_length(): int {
		return $this->note_length;
	}

	public function is_optional( string $type ): bool {
		return in_array( $type, $this->optional_types, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'summary'        => $this->summary,
			'details'        => $this->details,
			'post_types'     => $this->post_types,
			'optional_types' => $this->optional_types,
			'write_full'     => $this->write_full,
			'note_length'    => $this->note_length,
		);
	}

	/**
	 * @param array<string, mixed> $input Input.
	 */
	private static function string_value( array $input, string $key ): string {
		if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
			return '';
		}
		return $input[ $key ];
	}

	private static function plain( string $value ): string {
		$value = strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure settings parser, WordPress is not loaded.
		return $value;
	}

	private static function details_text( string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", self::plain( $value ) );
		$lines = array();
		foreach ( explode( "\n", $value ) as $line ) {
			$lines[] = trim( $line );
		}
		$text = trim( implode( "\n", $lines ) );
		if ( extension_loaded( 'mbstring' ) && mb_strlen( $text, 'UTF-8' ) > 2000 ) {
			$text = Text::boundary( $text, 2000 );
		}
		return $text;
	}

	/**
	 * @param mixed $value Raw list.
	 * @return string[]
	 */
	private static function types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$types = array();
		foreach ( $value as $type ) {
			if ( ! is_string( $type ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,40}\z/', $type ) ) {
				continue;
			}
			$types[] = $type;
		}
		return array_values( array_unique( $types ) );
	}
}
