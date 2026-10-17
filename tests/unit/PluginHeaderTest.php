<?php
/**
 * Plugin identity checks that do not need WordPress.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The plugin states, in the header, that it is single-site.
 */
final class PluginHeaderTest extends TestCase {

	/**
	 * Header copy is part of the single-site contract.
	 */
	public function test_header_declares_single_site_plugin(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/ai-ready-wp.php' );
		$this->assertIsString( $src );
		$this->assertStringContainsString( 'Plugin Name: AI Ready WP', $src );
		$this->assertStringContainsString( 'Single-site only', $src );
		$this->assertStringContainsString( 'GPL-2.0-or-later', $src );
		$this->assertStringContainsString( 'Requires PHP: 7.4', $src );
	}
}
