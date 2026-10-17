<?php
/**
 * One link in an llms.txt section.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Domain;

/**
 * Name, absolute URL, and optional note for one Markdown link.
 *
 * @since 1.0.0
 */
final class Link {
	/**
	 * Link label.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Destination URL.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $url;

	/**
	 * Optional note after the link.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $note;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Link label.
	 * @param string $url  Destination URL.
	 * @param string $note Optional note.
	 */
	public function __construct( $name, $url, $note = '' ) {
		$this->name = (string) $name;
		$this->url  = (string) $url;
		$this->note = (string) $note;
	}
}
