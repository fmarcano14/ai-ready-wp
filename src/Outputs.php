<?php
/**
 * The public files this plugin owns.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Rewrite rules and file names for the index and Markdown routes.
 *
 * @since 1.0.0
 */
final class Outputs {
	/**
	 * Rewrite regex mapped to its query.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules() {
		return array(
			'^llms\.txt$'      => 'index.php?ai_ready_wp_file=llms.txt',
			'^llms-full\.txt$' => 'index.php?ai_ready_wp_file=llms-full.txt',
			'^(.+?)\.md$'      => 'index.php?ai_ready_wp_markdown=1&ai_ready_wp_path=$matches[1]',
		);
	}

	/**
	 * Index file names, in serve order.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function index_names() {
		return array( 'llms.txt', 'llms-full.txt' );
	}

	/**
	 * Whether the file is the concatenated index.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_full( $name ) {
		return 'llms-full.txt' === $name;
	}

	/**
	 * Settings key that must be on before this file is public. Empty means none.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function required_setting( $name ) {
		return self::is_full( $name ) ? 'enable_llms_full' : '';
	}
}
