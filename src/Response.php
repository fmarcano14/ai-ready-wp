<?php
/**
 * HTTP response for a generated file.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable response. emit() is the WordPress boundary.
 */
final class Response {

	private int $status;

	/**
	 * Header values.
	 *
	 * @var array<string, string>
	 */
	private array $headers;

	private string $body;

	/**
	 * @param array<string, string> $headers Headers.
	 */
	public function __construct( int $status, array $headers, string $body ) {
		$this->status  = $status;
		$this->headers = $headers;
		$this->body    = $body;
	}

	public function status(): int {
		return $this->status;
	}

	public function body(): string {
		return $this->body;
	}

	public function header( string $name ): string {
		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : '';
	}

	public static function markdown( string $body, string $canonical ): self {
		return new self(
			200,
			array(
				'Content-Type'  => 'text/markdown; charset=utf-8',
				'X-Robots-Tag'  => 'noindex',
				'Link'          => '<' . self::header_url( $canonical ) . '>; rel="canonical"',
				'Cache-Control' => 'no-cache',
			),
			$body
		);
	}

	public static function plain( string $body ): self {
		return new self(
			200,
			array(
				'Content-Type'  => 'text/plain; charset=utf-8',
				'Cache-Control' => 'no-cache',
			),
			$body
		);
	}

	public static function not_found(): self {
		return new self(
			404,
			array(
				'Content-Type' => 'text/plain; charset=utf-8',
				'X-Robots-Tag' => 'noindex',
			),
			"Not found\n"
		);
	}

	/**
	 * Send the response and stop. Callers that only need to inspect it should not call this.
	 */
	public function emit(): void {
		status_header( $this->status );
		nocache_headers();
		foreach ( $this->headers as $name => $value ) {
			header( $name . ': ' . $value, 'Link' !== $name );
		}
		// Markdown and plain text, not HTML.
		echo $this->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function header_url( string $url ): string {
		$url = preg_replace( '/[\x00-\x1F\x7F].*/', '', $url );
		if ( ! is_string( $url ) ) {
			return '';
		}
		return str_replace( array( '<', '>' ), '', $url );
	}
}
