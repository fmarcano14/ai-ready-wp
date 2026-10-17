<?php
/**
 * Composition root.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

use Fer\AiReady\Admin\MetaBox;
use Fer\AiReady\Admin\SettingsPage;
use Fer\AiReady\Cache\Store;
use Fer\AiReady\Cli\Command;
use Fer\AiReady\Content\MarkdownConverter;
use Fer\AiReady\Content\Renderer;
use Fer\AiReady\Cron\Regenerator;
use Fer\AiReady\Http\Discovery;
use Fer\AiReady\Http\Router;

/**
 * Wires services and owns the WordPress hook list.
 *
 * @since 1.0.0
 */
final class Plugin {
	/**
	 * Singleton.
	 *
	 * @since 1.0.0
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Settings.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Publication policy.
	 *
	 * @since 1.0.0
	 *
	 * @var Publication
	 */
	private $publication;

	/**
	 * Cache.
	 *
	 * @since 1.0.0
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Router.
	 *
	 * @since 1.0.0
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Regenerator.
	 *
	 * @since 1.0.0
	 *
	 * @var Regenerator
	 */
	private $regenerator;

	/**
	 * Settings page.
	 *
	 * @since 1.0.0
	 *
	 * @var SettingsPage
	 */
	private $page;

	/**
	 * Meta box.
	 *
	 * @since 1.0.0
	 *
	 * @var MetaBox
	 */
	private $meta_box;

	/**
	 * Head-link discovery.
	 *
	 * @since 1.0.0
	 *
	 * @var Discovery
	 */
	private $discovery;

	/**
	 * Active plugin instance.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Activation callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		$plugin = self::instance();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		$plugin->router->register_rewrites();
		flush_rewrite_rules( false );
		update_option( 'ai_ready_wp_version', AI_READY_WP_VERSION, false );
	}

	/**
	 * Deactivation callback. Options are kept until uninstall.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		add_filter( 'rewrite_rules_array', array( Router::class, 'strip_rules' ) );
		flush_rewrite_rules( false );
		remove_filter( 'rewrite_rules_array', array( Router::class, 'strip_rules' ) );
		wp_clear_scheduled_hook( Regenerator::HOOK );
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->settings    = new Settings();
		$this->publication = new Publication( $this->settings );
		$converter         = new MarkdownConverter();
		$sanitizer         = new Sanitizer();
		$renderer          = new Renderer( $converter, $sanitizer );
		$this->store       = new Store( $this->settings );
		$catalog           = new Catalog( $this->publication, $renderer );
		$generator         = new Generator( $this->settings, $catalog, $this->store, $sanitizer, $this->publication );
		$this->router      = new Router( $this->settings, $this->publication, $this->store, $generator, $renderer );
		$discovery         = new Discovery( $this->settings, $this->publication );
		$this->regenerator = new Regenerator( $generator );
		$this->page        = new SettingsPage( $this->settings, $this->publication, $generator, $this->store );
		$this->meta_box    = new MetaBox( $this->settings, $this->publication, $this->store );
		$this->discovery   = $discovery;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ai-ready', new Command( $this->settings, $this->publication, $generator, $this->store ) );
		}
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( $this->router, 'register_rewrites' ), 5 );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
		add_filter( 'query_vars', array( $this->router, 'query_vars' ) );
		add_action( 'parse_request', array( $this->router, 'parse_request' ) );
		add_filter( 'redirect_canonical', array( $this->router, 'disable_canonical' ), 10, 2 );
		add_filter( 'redirect_guess_404_permalink', array( $this->router, 'disable_guess' ) );
		add_action( 'template_redirect', array( $this->router, 'serve' ), 0 );
		add_action( 'wp_head', array( $this->discovery, 'head' ), 2 );
		add_action( 'admin_bar_menu', array( $this->discovery, 'admin_bar' ), 80 );

		add_action( 'admin_menu', array( $this->page, 'menu' ) );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this->page, 'assets' ) );
		add_action( 'admin_notices', array( $this->page, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AI_READY_WP_FILE ), array( $this->page, 'action_links' ) );
		add_action( 'admin_post_ai_ready_wp_generate', array( $this->page, 'handle_generate' ) );
		add_action( 'wp_ajax_ai_ready_wp_preview', array( $this->page, 'preview' ) );

		add_action( 'add_meta_boxes', array( $this->meta_box, 'add' ) );
		add_action( 'save_post', array( $this->meta_box, 'save' ), 10, 2 );
		add_action( 'save_post', array( $this, 'content_changed' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'content_changed' ) );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_changed' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'settings_changed' ) );
		add_action( Regenerator::HOOK, array( $this->regenerator, 'run' ) );
	}

	/**
	 * Load translations.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'ai-ready-wp', false, dirname( plugin_basename( AI_READY_WP_FILE ) ) . '/languages' );
	}

	/**
	 * Flush rewrites once per version.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$previous = (string) get_option( 'ai_ready_wp_version', '' );
		if ( AI_READY_WP_VERSION === $previous ) {
			return;
		}
		$this->router->register_rewrites();
		flush_rewrite_rules( false );
		$this->store->invalidate_index();
		update_option( 'ai_ready_wp_version', AI_READY_WP_VERSION, false );
	}

	/**
	 * Invalidate and schedule a rebuild after content changes.
	 *
	 * @since 1.0.0
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object when provided.
	 * @return void
	 */
	public function content_changed( $post_id, $post = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_id = (int) $post_id;
		if ( $post_id < 1 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $this->publication->supports_meta_box( $post_id ) ) {
			return;
		}
		$this->store->invalidate_post( $post_id );
		$this->regenerator->schedule();
	}

	/**
	 * Invalidate when robots or exclusion meta changes.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $meta_id   Meta row ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public function meta_changed( $meta_id, $object_id, $meta_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! $this->is_watched_meta( (string) $meta_key ) || ! $this->publication->supports_meta_box( (int) $object_id ) ) {
			return;
		}
		$this->store->invalidate_post( (int) $object_id );
		$this->regenerator->schedule();
	}

	/**
	 * Drop cached files when settings change.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function settings_changed() {
		$this->settings->reset();
		$this->store->invalidate_index();
		$this->regenerator->schedule();
	}

	/**
	 * Meta keys that affect publication.
	 *
	 * @since 1.0.0
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private function is_watched_meta( $meta_key ) {
		return in_array(
			$meta_key,
			array(
				Publication::EXCLUDE_META,
				'_ai_ready_wp_noindex',
				'_yoast_wpseo_meta-robots-noindex',
				'rank_math_robots',
				'_seopress_robots_index',
				'_aioseop_noindex',
				'_aioseo_noindex',
				'_aioseo_robots_noindex',
				'_aioseo_robots',
				'_genesis_noindex',
			),
			true
		);
	}
}
