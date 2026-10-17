<?php
/**
 * Settings screen controller.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Admin;

use Fer\AiReady\Cache\Store;
use Fer\AiReady\Generator;
use Fer\AiReady\PostTypes;
use Fer\AiReady\Publication;
use Fer\AiReady\Settings;
use Fer\AiReady\SettingsSanitizer;

/**
 * Renders Settings → AI Ready and handles generation and preview.
 *
 * The view receives prepared values and escapes them. This class checks
 * capabilities and nonces before changing anything.
 *
 * @since 1.0.0
 */
final class SettingsPage {
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
	 * Index generator.
	 *
	 * @since 1.0.0
	 *
	 * @var Generator
	 */
	private $generator;

	/**
	 * Cache store.
	 *
	 * @since 1.0.0
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings    $settings    Settings reader.
	 * @param Publication $publication Eligibility policy.
	 * @param Generator   $generator   Index generator.
	 * @param Store       $store       Cache store.
	 */
	public function __construct( Settings $settings, Publication $publication, Generator $generator, Store $store ) {
		$this->settings    = $settings;
		$this->publication = $publication;
		$this->generator   = $generator;
		$this->store       = $store;
	}

	/**
	 * Register the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function menu() {
		$hook = add_options_page(
			__( 'AI Ready', 'ai-ready-wp' ),
			__( 'AI Ready', 'ai-ready-wp' ),
			$this->capability(),
			'ai-ready-wp',
			array( $this, 'render' )
		);
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'help' ) );
		}
	}

	/**
	 * Add a Settings action link on the plugins screen.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function action_links( $links ) {
		$url     = admin_url( 'options-general.php?page=ai-ready-wp' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ai-ready-wp' ) . '</a>';
		return $links;
	}

	/**
	 * Help tabs.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function help() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'ai-ready-wp-overview',
				'title'   => __( 'Overview', 'ai-ready-wp' ),
				'content' => '<p>' . esc_html__( 'AI Ready publishes /llms.txt and a Markdown version of each public document. Nothing is sent to an external service.', 'ai-ready-wp' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'ai-ready-wp-eligibility',
				'title'   => __( 'What is included', 'ai-ready-wp' ),
				'content' => '<p>' . esc_html__( 'Only published, password-free documents of the selected types are included. Noindex documents, the exclusion list, and the per-document checkbox are skipped. The posts index and WooCommerce cart, checkout, and account pages are skipped.', 'ai-ready-wp' ) . '</p>',
			)
		);
	}

	/**
	 * Enqueue admin assets and show a flash notice.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'settings_page_ai-ready-wp' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ai-ready-wp-admin', AI_READY_WP_URL . 'assets/css/admin.css', array(), AI_READY_WP_VERSION );
		wp_enqueue_script( 'ai-ready-wp-admin', AI_READY_WP_URL . 'assets/js/admin.js', array(), AI_READY_WP_VERSION, true );
		wp_localize_script(
			'ai-ready-wp-admin',
			'aiReadyWp',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ai_ready_wp_preview' ),
				'i18n'    => array(
					'loading' => __( 'Updating preview…', 'ai-ready-wp' ),
					'ready'   => __( 'Preview uses the form, not the saved copy, until you save.', 'ai-ready-wp' ),
					'error'   => __( 'The preview could not be built.', 'ai-ready-wp' ),
					'copied'  => __( 'Copied.', 'ai-ready-wp' ),
				),
			)
		);
	}

	/**
	 * Render a one-request admin notice.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function notices() {
		$key    = 'ai_ready_wp_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$class = ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	/**
	 * Render the page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}
		$settings    = $this->settings->all();
		$post_types  = PostTypes::selectable();
		$meta        = $this->store->meta();
		$status      = array(
			'discovery'    => $this->publication->discovery_enabled(),
			'mode'         => $settings['discovery_mode'],
			'blog_public'  => '1' === (string) get_option( 'blog_public', '1' ),
			'llms_url'     => home_url( '/llms.txt' ),
			'full_url'     => home_url( '/llms-full.txt' ),
			'generated_at' => isset( $meta['generated_at'] ) ? (int) $meta['generated_at'] : 0,
			'total'        => isset( $meta['total'] ) ? (int) $meta['total'] : 0,
			'skipped'      => isset( $meta['skipped'] ) ? (int) $meta['skipped'] : 0,
			'llms_bytes'   => isset( $meta['llms_bytes'] ) ? (int) $meta['llms_bytes'] : 0,
			'full_bytes'   => isset( $meta['full_bytes'] ) ? (int) $meta['full_bytes'] : 0,
			'next_cron'    => wp_next_scheduled( 'ai_ready_wp_regenerate' ),
			'last_error'   => (string) get_option( 'ai_ready_wp_last_error', '' ),
		);
		$preview     = $this->generator->preview( $settings );
		$option_name = Settings::OPTION;
		$minutes     = max( 1, (int) round( $settings['cache_ttl'] / 60 ) );
		require AI_READY_WP_PATH . 'views/settings-page.php';
	}

	/**
	 * Generate the index from the admin button.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_generate() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to generate AI Ready files.', 'ai-ready-wp' ), 403 );
		}
		check_admin_referer( 'ai_ready_wp_generate' );
		$result = $this->generator->generate();
		if ( is_wp_error( $result ) ) {
			$this->flash( $result->get_error_message(), true );
		} else {
			$this->flash(
				sprintf(
					/* translators: %d: number of documents written into the index. */
					__( 'Generated llms.txt with %d documents.', 'ai-ready-wp' ),
					(int) $result['total']
				)
			);
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=ai-ready-wp' ) );
		exit;
	}

	/**
	 * AJAX preview of llms.txt from the current form.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function preview() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to preview this file.', 'ai-ready-wp' ) ), 403 );
		}
		check_ajax_referer( 'ai_ready_wp_preview' );
		$input = isset( $_POST[ Settings::OPTION ] ) ? wp_unslash( $_POST[ Settings::OPTION ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$clean = SettingsSanitizer::sanitize( $input, array_keys( PostTypes::selectable() ) );
		wp_send_json_success( array( 'markdown' => $this->generator->preview( $clean ) ) );
	}

	/**
	 * Capability required to manage the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function capability() {
		$capability = apply_filters( 'ai_ready_wp_capability', 'manage_options' );
		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Store a one-request notice for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Notice text.
	 * @param bool   $error   Whether the notice is an error.
	 * @return void
	 */
	private function flash( $message, $error = false ) {
		set_transient(
			'ai_ready_wp_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'error'   => (bool) $error,
			),
			MINUTE_IN_SECONDS
		);
	}
}
