<?php
/**
 * Facts the inclusion rule needs. WordPress fills this in at the boundary.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable snapshot of one post.
 */
final class PostFacts {

	private int $id;

	private string $status;

	private string $type;

	private bool $public_type;

	private bool $has_password;

	private bool $excluded;

	private bool $noindex;

	public function __construct( int $id, string $status, string $type, bool $public_type, bool $has_password, bool $excluded, bool $noindex ) {
		$this->id           = $id;
		$this->status       = $status;
		$this->type         = $type;
		$this->public_type  = $public_type;
		$this->has_password = $has_password;
		$this->excluded     = $excluded;
		$this->noindex      = $noindex;
	}

	public function id(): int {
		return $this->id;
	}

	public function status(): string {
		return $this->status;
	}

	public function type(): string {
		return $this->type;
	}

	public function public_type(): bool {
		return $this->public_type;
	}

	public function has_password(): bool {
		return $this->has_password;
	}

	public function excluded(): bool {
		return $this->excluded;
	}

	public function noindex(): bool {
		return $this->noindex;
	}
}
