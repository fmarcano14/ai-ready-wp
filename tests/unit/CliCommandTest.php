<?php
/**
 * The CLI command is registered under the documented names.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\CliCommand;
use PHPUnit\Framework\TestCase;

/**
 * Command surface.
 */
final class CliCommandTest extends TestCase {

	public function test_generate_and_status_are_public_commands(): void {
		$generate = new \ReflectionMethod( CliCommand::class, 'generate' );
		$status   = new \ReflectionMethod( CliCommand::class, 'status' );
		$this->assertTrue( $generate->isPublic() );
		$this->assertTrue( $status->isPublic() );
	}
}
