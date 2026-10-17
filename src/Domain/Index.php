<?php
/**
 * The llms.txt document before it is rendered.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Domain;

/**
 * Site name, summary, details, and ordered sections.
 *
 * @since 1.0.0
 */
final class Index {
	/**
	 * H1 text.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Blockquote summary. Empty omits the blockquote.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $summary;

	/**
	 * Non-heading details between the summary and the first section.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $details;

	/**
	 * Sections in display order.
	 *
	 * @since 1.0.0
	 *
	 * @var Section[]
	 */
	public $sections;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $name     H1 text.
	 * @param string    $summary  Blockquote summary.
	 * @param string    $details  Non-heading details.
	 * @param Section[] $sections Sections.
	 */
	public function __construct( $name, $summary, $details, array $sections ) {
		$this->name     = (string) $name;
		$this->summary  = (string) $summary;
		$this->details  = (string) $details;
		$this->sections = array();
		foreach ( $sections as $section ) {
			if ( $section instanceof Section ) {
				$this->sections[] = $section;
			}
		}
	}
}
