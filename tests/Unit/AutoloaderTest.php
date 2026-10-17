<?php
/**
 * Fallback autoloader tests.
 *
 * @package AiReadyWp
 */

namespace Fer\AiReady\Tests\Unit;

use Fer\AiReady\Autoloader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Fer\AiReady\Autoloader
 */
final class AutoloaderTest extends TestCase {
	/**
	 * The loader points class names at the src tree.
	 *
	 * @return void
	 */
	public function test_path_for_known_class() {
		$path = Autoloader::path_for( 'Fer\\AiReady\\Content\\MarkdownConverter' );
		$this->assertStringEndsWith( '/src/Content/MarkdownConverter.php', $path );
		$this->assertFileExists( $path );
		$this->assertSame( '', Autoloader::path_for( 'Other\\Class' ) );
	}
}
