<?php
/**
 * Stops a response during the test suite so PHPUnit can inspect it.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown only when AI_READY_TESTING is set. Production calls exit instead.
 */
final class Halt extends \RuntimeException {

	/**
	 * Response that would have been sent.
	 *
	 * @var Response
	 */
	private Response $response;

	public function __construct( Response $response ) {
		parent::__construct( 'halt' );
		$this->response = $response;
	}

	public function response(): Response {
		return $this->response;
	}
}
