<?php
/**
 * The plugin boots under WordPress.
 *
 * @package AiReady
 */

/**
 * Load check for commits that do not yet ship the behavior suite.
 */
class BootstrapTest extends WP_UnitTestCase {

	public function test_the_plugin_boots_on_a_single_site(): void {
		$this->assertTrue( defined( 'AI_READY_VERSION' ) );
		$this->assertFalse( is_multisite() );
	}
}
