<?php
/**
 * One H2 section of an llms.txt file.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Domain;

/**
 * A heading and the links that belong under it.
 *
 * @since 1.0.0
 */
final class Section {
	/**
	 * Section heading, without the Markdown marker.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $heading;

	/**
	 * Whether these links belong under the spec's Optional heading.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	public $optional;

	/**
	 * Links in display order.
	 *
	 * @since 1.0.0
	 *
	 * @var Link[]
	 */
	public $links;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $heading  Section heading.
	 * @param Link[] $links    Links.
	 * @param bool   $optional True when the section is secondary.
	 */
	public function __construct( $heading, array $links, $optional = false ) {
		$this->heading  = (string) $heading;
		$this->optional = (bool) $optional;
		$this->links    = array();
		foreach ( $links as $link ) {
			if ( $link instanceof Link ) {
				$this->links[] = $link;
			}
		}
	}
}
