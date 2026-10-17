<?php
/**
 * Advertises the Markdown and llms.txt URLs from public HTML.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Http;

use Fer\AiReady\MarkdownUrl;
use Fer\AiReady\Publication;
use Fer\AiReady\Settings;

/**
 * Prints link elements and an admin-bar shortcut.
 *
 * @since 1.0.0
 */
final class Discovery {
	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Eligibility policy.
	 *
	 * @since 1.0.0
	 *
	 * @var Publication
	 */
	private $publication;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings    $settings    Settings reader.
	 * @param Publication $publication Eligibility policy.
	 */
	public function __construct( Settings $settings, Publication $publication ) {
		$this->settings    = $settings;
		$this->publication = $publication;
	}

	/**
	 * Print head links.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function head() {
		if ( is_admin() || empty( $this->settings->all()['enable_head_links'] ) ) {
			return;
		}
		if ( $this->publication->discovery_enabled() ) {
			echo '<link rel="describedby" href="' . esc_url( home_url( '/llms.txt' ) ) . '" />' . "\n";
		}
		$href = $this->markdown_href();
		if ( '' !== $href ) {
			echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $href ) . '" title="' . esc_attr__( 'Markdown version', 'ai-ready-wp' ) . '" />' . "\n";
		}
	}

	/**
	 * Add an llms.txt node to the admin bar.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar( $bar ) {
		if ( ! $bar instanceof \WP_Admin_Bar || ! current_user_can( 'manage_options' ) || ! $this->publication->discovery_enabled() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'ai-ready-wp',
				'title' => 'llms.txt',
				'href'  => home_url( '/llms.txt' ),
				'meta'  => array(
					'title' => __( 'Open the llms.txt index', 'ai-ready-wp' ),
				),
			)
		);
	}

	/**
	 * Markdown URL for the current singular document, when it is public.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function markdown_href() {
		$settings = $this->settings->all();
		if ( empty( $settings['enable_markdown'] ) || ! is_singular() ) {
			return '';
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! $this->publication->is_public_post( $post ) ) {
			return '';
		}
		return MarkdownUrl::from_permalink( (string) get_permalink( $post ), home_url( '/' ) );
	}
}
