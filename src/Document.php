<?php
/**
 * One public document in the generated files.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable document.
 */
final class Document {

	private int $id;

	private string $title;

	private string $html_url;

	private string $markdown_url;

	private string $note;

	private string $markdown;

	private string $section;

	private bool $optional;

	public function __construct( int $id, string $title, string $html_url, string $markdown_url, string $note, string $markdown, string $section, bool $optional ) {
		$this->id           = $id;
		$this->title        = $title;
		$this->html_url     = $html_url;
		$this->markdown_url = $markdown_url;
		$this->note         = $note;
		$this->markdown     = $markdown;
		$this->section      = $section;
		$this->optional     = $optional;
	}

	public function id(): int {
		return $this->id;
	}

	public function title(): string {
		return $this->title;
	}

	public function html_url(): string {
		return $this->html_url;
	}

	public function markdown_url(): string {
		return $this->markdown_url;
	}

	public function note(): string {
		return $this->note;
	}

	public function markdown(): string {
		return $this->markdown;
	}

	public function section(): string {
		return $this->section;
	}

	public function optional(): bool {
		return $this->optional;
	}
}
