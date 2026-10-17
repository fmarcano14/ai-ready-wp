<?php
/**
 * Default values for the plugin settings.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Reads and caches the sanitized settings option.
 *
 * @since 1.0.0
 */
final class Settings {
	const OPTION = 'ai_ready_wp_settings';

	/**
	 * Memoized settings for this request.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, mixed>|null
	 */
	private $cache = null;

	/**
	 * Factory defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'discovery_mode'      => 'follow',
			'enable_llms_full'    => 1,
			'enable_markdown'     => 1,
			'enable_head_links'   => 1,
			'markdown_links'      => 1,
			'link_notes'          => 1,
			'post_types'          => array( 'post', 'page' ),
			'optional_post_types' => array(),
			'section_order'       => array( 'page', 'post' ),
			'section_ranks'       => array(
				'page' => 10,
				'post' => 20,
			),
			'section_labels'      => array(),
			'exclude'             => '',
			'summary'             => '',
			'intro'               => '',
			'max_items'           => 500,
			'max_full_items'      => 100,
			'cache_ttl'           => HOUR_IN_SECONDS,
		);
	}

	/**
	 * Sanitized settings for the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$allowed = array_keys( PostTypes::selectable() );
		if ( isset( $stored['post_types'] ) && is_array( $stored['post_types'] ) ) {
			foreach ( $stored['post_types'] as $slug ) {
				if ( is_string( $slug ) && post_type_exists( $slug ) ) {
					$allowed[] = $slug;
				}
			}
		}

		$this->cache = SettingsSanitizer::sanitize( $stored, $allowed );
		return $this->cache;
	}

	/**
	 * Forget the memoized settings after an update.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reset() {
		$this->cache = null;
	}

	/**
	 * Settings API callback.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$clean       = SettingsSanitizer::sanitize( $input, array_keys( PostTypes::selectable() ) );
		$this->cache = null;
		return $clean;
	}

	/**
	 * Register the option.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			'ai_ready_wp',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}
}
