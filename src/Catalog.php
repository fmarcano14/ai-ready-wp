<?php
/**
 * Site name, summary, and the documents that belong in llms.txt.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable catalog passed to the builders.
 */
final class Catalog {

	private string $name;

	private string $summary;

	private string $details;

	/**
	 * Included documents, in display order.
	 *
	 * @var Document[]
	 */
	private array $documents;

	/**
	 * @param Document[] $documents Included documents, in display order.
	 */
	public function __construct( string $name, string $summary, string $details, array $documents ) {
		$this->name    = $name;
		$this->summary = $summary;
		$this->details = $details;
		$clean         = array();
		foreach ( $documents as $document ) {
			if ( $document instanceof Document ) {
				$clean[] = $document;
			}
		}
		$this->documents = $clean;
	}

	public function name(): string {
		return $this->name;
	}

	public function summary(): string {
		return $this->summary;
	}

	public function details(): string {
		return $this->details;
	}

	/**
	 * @return Document[]
	 */
	public function documents(): array {
		return $this->documents;
	}
}
