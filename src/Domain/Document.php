<?php
/**
 * One public document in Markdown.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Domain;

/**
 * Title, canonical URL, dates, type, and converted body.
 *
 * @since 1.0.0
 */
final class Document {
	/**
	 * Document title.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Canonical HTML URL.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $url;

	/**
	 * Published date.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $date;

	/**
	 * Modified date.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $modified;

	/**
	 * Post type slug.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Converted Markdown body, without the YAML header.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $body;

	/**
	 * Finished Markdown, including the header. Empty until composed.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $markdown;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title    Document title.
	 * @param string $url      Canonical HTML URL.
	 * @param string $date     Published date.
	 * @param string $modified Modified date.
	 * @param string $type     Post type slug.
	 * @param string $body     Converted body.
	 */
	public function __construct( $title, $url, $date, $modified, $type, $body ) {
		$this->title    = (string) $title;
		$this->url      = (string) $url;
		$this->date     = (string) $date;
		$this->modified = (string) $modified;
		$this->type     = (string) $type;
		$this->body     = (string) $body;
		$this->markdown = '';
	}
}
