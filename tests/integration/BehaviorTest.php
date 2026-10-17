<?php
/**
 * Behaviors exercised against the WordPress test suite.
 *
 * @package AiReady
 */

use AiReady\AdminPage;
use AiReady\CliCommand;
use AiReady\ExcludeMeta;
use AiReady\Front;
use AiReady\Generator;
use AiReady\Halt;
use AiReady\Response;
use AiReady\Scheduler;
use AiReady\Settings;
use AiReady\Store;

/**
 * Public behavior of the plugin on a real WordPress load.
 */
class BehaviorTest extends WP_UnitTestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/**
	 * Subscriber.
	 *
	 * @var int
	 */
	private int $subscriber = 0;

	/**
	 * Published post that should appear.
	 *
	 * @var int
	 */
	private int $public_id = 0;

	/**
	 * Published post with a manual excerpt.
	 *
	 * @var int
	 */
	private int $excerpt_id = 0;

	/**
	 * Optional page.
	 *
	 * @var int
	 */
	private int $page_id = 0;

	public function set_up(): void {
		parent::set_up();

		update_option( 'permalink_structure', '/%postname%/' );
		update_option( 'blogname', 'Café 🌧️' );
		update_option( 'blogdescription', 'Resumen ñ' );
		update_option(
			Settings::OPTION,
			array(
				'summary'        => '',
				'details'        => 'Short details.',
				'post_types'     => array( 'post', 'page' ),
				'optional_types' => array( 'page' ),
				'write_full'     => true,
				'note_length'    => 200,
			)
		);

		global $wp_rewrite;
		$wp_rewrite->init();
		$wp_rewrite->flush_rules( true );

		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		add_shortcode(
			'secret',
			static function () {
				if ( is_user_logged_in() ) {
					return 'MEMBER-SECRET';
				}
				return 'visitor';
			}
		);
		add_shortcode(
			'who',
			static function () {
				return 'user-' . get_current_user_id();
			}
		);

		$this->public_id  = $this->publish(
			'post',
			'Café niño 🌧️',
			'cafe-nino',
			'<p>Hello <code>&lt;div&gt;</code> [who] [secret]MEMBER-SECRET[/secret] [notashortcode id="9"]</p>'
		);
		$this->excerpt_id = $this->publish(
			'post',
			'River notes',
			'river-notes',
			'<p>[secret]MEMBER-SECRET[/secret] hidden body</p>',
			'Manual note about ñ'
		);
		$this->page_id    = $this->publish( 'page', 'Old checklist', 'old-checklist', '<p>Archive page.</p>' );

		$this->publish( 'post', 'DRAFT-MARKER', 'draft-marker', '<p>DRAFT-BODY</p>', '', 'draft' );
		$this->publish( 'post', 'PRIVATE-MARKER', 'private-marker', '<p>PRIVATE-BODY</p>', '', 'private' );
		$this->publish( 'post', 'FUTURE-MARKER', 'future-marker', '<p>FUTURE-BODY</p>', '', 'future' );
		$this->publish( 'post', 'LOCKED-MARKER', 'locked-marker', '<p>LOCKED-BODY</p>', '', 'publish', 's3cret' );

		$excluded = $this->publish( 'post', 'EXCLUDED-MARKER', 'excluded-marker', '<p>EXCLUDED-BODY</p>' );
		update_post_meta( $excluded, ExcludeMeta::META_KEY, '1' );

		$noindex = $this->publish( 'post', 'NOINDEX-MARKER', 'noindex-marker', '<p>NOINDEX-BODY</p>' );
		update_post_meta( $noindex, '_yoast_wpseo_meta-robots-noindex', '1' );

		Store::open()->destroy();
		wp_clear_scheduled_hook( Scheduler::HOOK );
		wp_set_current_user( $this->admin );
		$this->generate();
	}

	public function tear_down(): void {
		remove_shortcode( 'secret' );
		remove_shortcode( 'who' );
		remove_all_filters( 'ai_ready_block_store' );
		remove_all_filters( 'ai_ready_converter_version' );
		Store::open()->destroy();
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tear_down();
	}

	public function test_only_published_public_documents_are_visible(): void {
		$index = $this->index();
		$this->assertStringContainsString( 'Café niño 🌧️', $index );
		$this->assertStringContainsString( 'River notes', $index );
		$this->assertStringContainsString( 'Old checklist', $index );
		foreach ( array( 'DRAFT-MARKER', 'PRIVATE-MARKER', 'FUTURE-MARKER', 'LOCKED-MARKER', 'EXCLUDED-MARKER', 'NOINDEX-MARKER' ) as $marker ) {
			$this->assertStringNotContainsString( $marker, $index );
			$this->assertStringNotContainsString( $marker, (string) $this->full() );
		}

		$locked = $this->markdown_response( '/locked-marker.md' );
		$this->assertInstanceOf( Response::class, $locked );
		$this->assertSame( 404, $locked->status() );
		$this->assertStringNotContainsString( 'LOCKED-BODY', $locked->body() );

		$this->assertNull( $this->raw_markdown( '/draft-marker.md' ) );
		$this->assertNull( $this->raw_markdown( '/private-marker.md' ) );
		$this->assertNull( $this->raw_markdown( '/future-marker.md' ) );
		$this->assertNull( $this->raw_markdown( '/excluded-marker.md' ) );
		$this->assertNull( $this->raw_markdown( '/noindex-marker.md' ) );
		$this->assertNull( $this->raw_markdown( '/cafe-nino/.md' ) );

		$this->go_to( home_url( '/cafe-nino/.md' ) );
		$this->assertTrue( is_404() );
		$this->go_to( home_url( '/excluded-marker.md' ) );
		$this->assertTrue( is_404() );

		$excluded_id = $this->post_id_by_slug( 'excluded-marker' );
		$this->go_to( get_permalink( $excluded_id ) );
		$_GET['format'] = 'markdown';
		$this->assertNull( ( new Front() )->serve_format() );

		$this->go_to( home_url( '/draft-marker.md' ) );
		$this->assertTrue( is_404() );

		wp_set_current_user( $this->admin );
		$this->go_to( get_permalink( $this->public_id ) );
		$_GET['format'] = 'markdown';
		$visible        = ( new Front() )->serve_format();
		$this->assertInstanceOf( Response::class, $visible );
		$this->assertSame( 200, $visible->status() );

		$locked_id = $this->post_id_by_slug( 'locked-marker' );
		$this->go_to( get_permalink( $locked_id ) );
		$_GET['format'] = 'markdown';
		$hidden         = ( new Front() )->serve_format();
		$this->assertInstanceOf( Response::class, $hidden );
		$this->assertSame( 404, $hidden->status() );
		$this->assertStringNotContainsString( 'LOCKED-BODY', $hidden->body() );
	}

	public function test_leaving_the_public_set_updates_the_files_immediately(): void {
		wp_set_current_user( $this->admin );
		wp_update_post(
			array(
				'ID'            => $this->public_id,
				'post_password' => 'now-secret',
			)
		);
		( new Scheduler() )->flush_pending();

		$this->assertStringNotContainsString( 'cafe-nino.md', $this->index() );
		$this->assertStringNotContainsString( '`<div>`', (string) $this->full() );
		$hidden = $this->markdown_response( '/cafe-nino.md' );
		$this->assertSame( 404, $hidden->status() );
		$this->assertStringNotContainsString( 'user-0', $hidden->body() );

		update_post_meta( $this->excerpt_id, ExcludeMeta::META_KEY, '1' );
		( new Scheduler() )->flush_pending();
		$this->assertStringNotContainsString( 'river-notes.md', $this->index() );
		$this->assertStringNotContainsString( 'Manual note about ñ', (string) $this->full() );

		update_post_meta( $this->page_id, '_yoast_wpseo_meta-robots-noindex', '1' );
		( new Scheduler() )->flush_pending();
		$this->assertStringNotContainsString( 'old-checklist.md', $this->index() );
		$this->assertStringNotContainsString( 'Archive page.', (string) $this->full() );
	}

	public function test_content_is_rendered_as_an_anonymous_visitor(): void {
		$body = $this->document( $this->public_id );
		$this->assertStringContainsString( 'user-0', $body );
		$this->assertStringContainsString( 'visitor', $body );
		$this->assertStringNotContainsString( 'MEMBER-SECRET', $body );
		$this->assertStringNotContainsString( 'MEMBER-SECRET', $this->index() );
		$this->assertStringNotContainsString( 'MEMBER-SECRET', (string) $this->full() );

		wp_set_current_user( $this->admin );
		$view = $this->markdown_response( '/cafe-nino.md' );
		$this->assertSame( 200, $view->status() );
		$this->assertStringContainsString( 'user-0', $view->body() );
		$this->assertStringNotContainsString( 'MEMBER-SECRET', $view->body() );
	}

	public function test_notes_come_from_the_excerpt_or_stripped_content(): void {
		$index = $this->index();
		$this->assertStringContainsString( 'Manual note about ñ', $index );
		$this->assertStringNotContainsString( '[secret]', $index );
		$this->assertStringNotContainsString( '[notashortcode', $index );
		$this->assertStringNotContainsString( '[who]', $this->document( $this->public_id ) );
		$this->assertStringNotContainsString( '<p>', $this->document( $this->excerpt_id ) );
	}

	public function test_accented_and_emoji_text_stays_valid(): void {
		$index = $this->index();
		$this->assertStringStartsWith( "# Café 🌧️\n", $index );
		$this->assertStringContainsString( '> Resumen ñ', $index );
		$this->assertStringContainsString( 'Café niño 🌧️', $index );
		$this->assertStringContainsString( 'Manual note about ñ', $index );
		$this->assertTrue( mb_check_encoding( $index, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $this->document( $this->public_id ), 'UTF-8' ) );
		$this->assertNotSame( '', trim( $index ) );
	}

	public function test_inline_code_survives_conversion(): void {
		$this->assertStringContainsString( '`<div>`', $this->document( $this->public_id ) );
	}

	public function test_public_requests_do_not_rebuild(): void {
		$before = $this->index();
		$this->assertStringNotContainsString( 'CHANGED-UNIQUE-STRING', $before );

		wp_set_current_user( $this->admin );
		wp_update_post(
			array(
				'ID'           => $this->excerpt_id,
				'post_excerpt' => 'CHANGED-UNIQUE-STRING',
			)
		);

		$this->assertSame( $before, $this->index() );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );

		$_SERVER['REQUEST_URI'] = '/llms.txt';
		$served                 = ( new Front() )->serve_path();
		$this->assertInstanceOf( Response::class, $served );
		$this->assertStringNotContainsString( 'CHANGED-UNIQUE-STRING', $served->body() );
		$this->assertSame( $before, $this->index() );
		$this->assertSame( 0, ( new Generator() )->run() );

		do_action( Scheduler::HOOK );
		$this->assertStringContainsString( 'CHANGED-UNIQUE-STRING', $this->index() );
	}

	public function test_generated_output_lives_in_the_database(): void {
		$store = Store::open();
		$this->assertTrue( $store->has_index() );
		$this->assertTrue( $store->has_full() );
		$markdown = $store->read_document( $this->public_id );
		$this->assertIsString( $markdown );
		$this->assertSame( $store->read_index(), get_option( Store::INDEX_OPTION ) );
		$this->assertSame( $store->read_full(), get_option( Store::FULL_OPTION ) );
		$this->assertSame( $markdown, get_post_meta( $this->public_id, Store::META_MARKDOWN, true ) );

		wp_cache_delete( 'alloptions', 'options' );
		$autoloaded = wp_load_alloptions();
		$this->assertArrayNotHasKey( Store::INDEX_OPTION, $autoloaded );
		$this->assertArrayNotHasKey( Store::FULL_OPTION, $autoloaded );
		$this->assertArrayNotHasKey( Store::GENERATED_OPTION, $autoloaded );

		$uploads = wp_upload_dir( null, false );
		$this->assertIsArray( $uploads );
		$this->assertDirectoryDoesNotExist( trailingslashit( (string) $uploads['basedir'] ) . 'ai-ready' );
		foreach ( Store::legacy_directories() as $dir ) {
			$this->assertDirectoryDoesNotExist( $dir );
		}
		$this->assertSame( array(), $this->generated_files_under( ABSPATH ) );
	}

	public function test_upgrade_removes_leftover_directories_only_after_the_write(): void {
		$uploads = wp_upload_dir( null, false );
		$this->assertIsArray( $uploads );
		$public = trailingslashit( (string) $uploads['basedir'] ) . 'ai-ready/current';
		wp_mkdir_p( $public );
		file_put_contents( $public . '/llms.txt', "OLD-PUBLIC\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the test plants a leftover from the old store.

		$private = Store::legacy_directories()[0];
		wp_mkdir_p( $private . '/current' );
		file_put_contents( $private . '/current/llms.txt', "OLD-PRIVATE\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		add_filter(
			'ai_ready_block_store',
			static function ( $block, $key ) {
				return 'ai_ready_llms_txt' === $key ? true : $block;
			},
			10,
			2
		);
		$failed = Generator::trusted(
			static function () {
				return ( new Generator() )->run();
			}
		);
		$this->assertInstanceOf( WP_Error::class, $failed );
		$this->assertFileExists( $public . '/llms.txt' );
		$this->assertFileExists( $private . '/current/llms.txt' );

		remove_all_filters( 'ai_ready_block_store' );
		$this->generate();
		$this->assertDirectoryDoesNotExist( trailingslashit( (string) $uploads['basedir'] ) . 'ai-ready' );
		$this->assertDirectoryDoesNotExist( $private );
		$this->assertStringContainsString( 'cafe-nino.md', $this->index() );
	}

	public function test_a_failed_write_keeps_the_previous_output_and_is_reported(): void {
		$extra = $this->publish( 'post', 'Extra page', 'extra-page', '<p>Extra body.</p>' );
		$this->generate();
		$before = $this->index();
		$this->assertStringContainsString( 'extra-page.md', $before );
		$this->assertNotNull( Store::open()->read_document( $extra ) );

		wp_set_current_user( $this->admin );
		wp_trash_post( $extra );
		add_filter(
			'ai_ready_block_store',
			static function ( $block, $key ) {
				return 'ai_ready_llms_txt' === $key ? true : $block;
			},
			10,
			2
		);

		$stored = 0;
		add_action(
			'ai_ready_stored',
			static function () use ( &$stored ) {
				++$stored;
			}
		);
		$result = Generator::trusted(
			static function () {
				return ( new Generator() )->run();
			}
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ai_ready_store_failed', $result->get_error_code() );
		$this->assertSame( 0, $stored );
		$this->assertSame( $before, $this->index() );
		$this->assertNotNull( Store::open()->read_document( $extra ) );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ai_ready_generate' );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
		$redirected           = false;
		try {
			( new AdminPage() )->generate();
		} catch ( RuntimeException $exception ) {
			$redirected = 'redirect' === $exception->getMessage();
		}
		$this->assertTrue( $redirected );
		$this->assertSame( $before, $this->index() );

		$_GET['ai_ready_error'] = '1';
		ob_start();
		( new AdminPage() )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'could not save llms.txt', $html );
		$this->assertStringNotContainsString( 'Generated files', $html );

		try {
			( new CliCommand() )->generate( array(), array() );
			$this->fail( 'WP-CLI reported success after a failed write.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'could not save llms.txt', $exception->getMessage() );
		}
		$this->assertSame( $before, $this->index() );
	}

	public function test_unknown_markdown_urls_fall_through(): void {
		$this->assertNull( $this->raw_markdown( '/definitely-not-a-page.md' ) );
		$this->go_to( home_url( '/definitely-not-a-page.md' ) );
		$this->assertTrue( is_404() );

		$this->go_to( home_url( '/' ) );
		$_GET['format'] = 'markdown';
		$this->assertNull( ( new Front() )->serve_format() );
	}

	public function test_admin_actions_check_capability_nonce_and_escaping(): void {
		wp_set_current_user( $this->subscriber );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ai_ready_save_settings' );
		try {
			( new AdminPage() )->save();
			$this->fail( 'Subscriber was allowed to save settings.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotEmpty( $exception->getMessage() );
		}

		wp_set_current_user( $this->admin );
		$_REQUEST['_wpnonce'] = 'not-a-nonce';
		try {
			( new AdminPage() )->save();
			$this->fail( 'A bad nonce was accepted.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotEmpty( $exception->getMessage() );
		}

		$_POST                        = array(
			'summary'     => '<script>alert(1)</script> Saved ñ',
			'details'     => 'Details',
			'post_types'  => array( 'post', 'page' ),
			'note_length' => '80',
			'write_full'  => '1',
		);
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'ai_ready_save_settings' );
		$_POST['_wpnonce']            = $_REQUEST['_wpnonce'];
		$redirected                   = false;
		try {
			( new AdminPage() )->save();
		} catch ( RuntimeException $exception ) {
			$redirected = 'redirect' === $exception->getMessage();
		}
		$this->assertTrue( $redirected );
		$this->assertStringNotContainsString( '<script>', Settings::current()->summary() );

		$stored           = Settings::current()->to_array();
		$stored['summary'] = 'Café < niño';
		update_option( Settings::OPTION, $stored );
		wp_set_current_user( $this->admin );
		ob_start();
		( new AdminPage() )->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Single-site only', $html );
		$this->assertStringContainsString( 'Café &lt; niño', $html );
		$this->assertStringNotContainsString( 'Café < niño', $html );

		update_post_meta( $this->public_id, ExcludeMeta::META_KEY, '1' );
		$_POST = array();
		( new ExcludeMeta() )->save( $this->public_id, get_post( $this->public_id ) );
		$this->assertSame( '1', (string) get_post_meta( $this->public_id, ExcludeMeta::META_KEY, true ) );

		$_POST['ai_ready_exclude_nonce'] = wp_create_nonce( 'ai_ready_exclude_' . $this->public_id );
		( new ExcludeMeta() )->save( $this->public_id, get_post( $this->public_id ) );
		$this->assertSame( '', (string) get_post_meta( $this->public_id, ExcludeMeta::META_KEY, true ) );
	}

	public function test_bulk_trash_rebuilds_once_and_skips_unchanged_documents(): void {
		$ids = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->publish( 'post', 'Bulk ' . $i, 'bulk-' . $i, '<p>Body ' . $i . '</p>' );
		}
		$this->generate();

		$renders = 0;
		$runs    = 0;
		add_filter(
			'the_content',
			static function ( $content ) use ( &$renders ) {
				++$renders;
				return $content;
			}
		);
		add_action(
			'ai_ready_stored',
			static function () use ( &$runs ) {
				++$runs;
			}
		);

		wp_set_current_user( $this->admin );
		foreach ( array_slice( $ids, 0, 8 ) as $id ) {
			wp_trash_post( $id );
		}
		( new Scheduler() )->flush_pending();

		$this->assertSame( 1, $runs );
		$this->assertSame( 0, $renders );
		$index = $this->index();
		$this->assertStringNotContainsString( 'bulk-0.md', $index );
		$this->assertStringContainsString( 'bulk-8.md', $index );
	}

	public function test_manual_generate_bypasses_the_render_cache(): void {
		$token = 'FRESH-1';
		add_filter(
			'the_content',
			static function ( $content ) use ( &$token ) {
				return $content . '<p>' . $token . '</p>';
			}
		);
		Generator::trusted(
			static function () {
				return ( new Generator() )->run( true );
			}
		);
		$this->assertStringContainsString( 'FRESH-1', $this->document( $this->public_id ) );

		$token = 'FRESH-2';
		$this->generate();
		$this->assertStringContainsString( 'FRESH-1', $this->document( $this->public_id ) );
		$this->assertStringNotContainsString( 'FRESH-2', $this->document( $this->public_id ) );

		wp_set_current_user( $this->admin );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ai_ready_generate' );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
		try {
			( new AdminPage() )->generate();
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'redirect', $exception->getMessage() );
		}
		$this->assertStringContainsString( 'FRESH-2', $this->document( $this->public_id ) );
		$this->assertStringNotContainsString( 'FRESH-1', $this->document( $this->public_id ) );
	}

	public function test_a_converter_change_invalidates_the_cache(): void {
		$renders = 0;
		add_filter(
			'the_content',
			static function ( $content ) use ( &$renders ) {
				++$renders;
				return $content;
			}
		);
		$this->generate();
		$this->assertSame( 0, $renders );

		update_post_meta( $this->public_id, Store::META_CONVERTER, 'old' );
		$this->generate();
		$this->assertGreaterThan( 0, $renders );
	}

	public function test_saving_a_synced_pattern_refreshes_pages_that_use_it(): void {
		$pattern = (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Promo',
				'post_content' => "<!-- wp:paragraph -->\n<p>PROMO-OLD</p>\n<!-- /wp:paragraph -->",
			)
		);
		$page    = $this->publish(
			'page',
			'Landing',
			'landing',
			'<!-- wp:block {"ref":' . $pattern . '} /-->'
		);
		$this->generate();
		$body = $this->document( $page );
		$this->assertStringContainsString( 'PROMO-OLD', $body );
		$this->assertStringNotContainsString( 'PROMO-NEW', $body );

		$epoch = Store::open()->cache_epoch();
		wp_set_current_user( $this->admin );
		wp_update_post(
			array(
				'ID'           => $pattern,
				'post_content' => "<!-- wp:paragraph -->\n<p>PROMO-NEW</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->assertGreaterThan( $epoch, Store::open()->cache_epoch() );
		( new Scheduler() )->flush_pending();
		$body = $this->document( $page );
		$this->assertStringContainsString( 'PROMO-NEW', $body );
		$this->assertStringNotContainsString( 'PROMO-OLD', $body );
	}

	public function test_saving_settings_rebuilds_the_files(): void {
		$this->assertStringContainsString( 'old-checklist.md', $this->index() );
		wp_set_current_user( $this->admin );
		$_POST                = array(
			'summary'     => 'Saved summary',
			'details'     => 'Details',
			'post_types'  => array( 'post' ),
			'note_length' => '80',
			'write_full'  => '1',
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ai_ready_save_settings' );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
		try {
			( new AdminPage() )->save();
			$this->fail( 'Save did not redirect.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'redirect', $exception->getMessage() );
		}
		$index = $this->index();
		$this->assertStringNotContainsString( 'old-checklist.md', $index );
		$this->assertStringContainsString( 'cafe-nino.md', $index );
		$this->assertStringContainsString( 'Saved summary', $index );
	}

	public function test_uninstall_removes_plugin_data(): void {
		update_post_meta( $this->public_id, ExcludeMeta::META_KEY, '1' );
		set_transient( 'ai_ready_rebuild', '1', MINUTE_IN_SECONDS );
		set_transient( Store::ERROR_TRANSIENT, 'failed', MINUTE_IN_SECONDS );
		$this->assertTrue( Store::open()->has_index() );
		$this->assertNotSame( '', (string) get_post_meta( $this->public_id, Store::META_MARKDOWN, true ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Store::INDEX_OPTION ) );
		$this->assertFalse( get_option( Store::FULL_OPTION ) );
		$this->assertFalse( get_option( Store::GENERATED_OPTION ) );
		$this->assertFalse( get_option( Store::EPOCH_OPTION ) );
		$this->assertSame( '', (string) get_post_meta( $this->public_id, ExcludeMeta::META_KEY, true ) );
		$this->assertSame( '', (string) get_post_meta( $this->public_id, Store::META_MARKDOWN, true ) );
		$this->assertSame( '', (string) get_post_meta( $this->public_id, Store::META_STAMP, true ) );
		$this->assertFalse( get_transient( 'ai_ready_rebuild' ) );
		$this->assertFalse( get_transient( Store::ERROR_TRANSIENT ) );
		$this->assertFalse( Store::open()->has_index() );
	}

	public function test_deactivation_removes_llms_rewrite_rules(): void {
		global $wp_rewrite;
		( new Front() )->rewrites();
		$wp_rewrite->flush_rules( true );

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( '^llms\.txt$', $rules );

		ai_ready_deactivate();

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayNotHasKey( '^llms\.txt$', $rules );
		$this->assertArrayNotHasKey( '^llms-full\.txt$', $rules );

		remove_all_actions( 'parse_request' );
		remove_all_actions( 'template_redirect' );
		$this->go_to( home_url( '/llms.txt' ) );
		$this->assertTrue( is_404() );
		$target = redirect_canonical( home_url( '/llms.txt' ), false );
		$this->assertNotSame( home_url( '/' ), $target );
	}

	public function test_single_site_is_stated_on_the_plugin(): void {
		$src = file_get_contents( AI_READY_FILE );
		$this->assertIsString( $src );
		$this->assertStringContainsString( 'Single-site only', $src );
		$this->assertStringContainsString( 'does not run on multisite', $src );
	}

	public function test_markdown_response_headers_and_alternate_link(): void {
		$view = $this->markdown_response( '/cafe-nino.md' );
		$this->assertSame( 'text/markdown; charset=utf-8', $view->header( 'Content-Type' ) );
		$this->assertSame( 'noindex', $view->header( 'X-Robots-Tag' ) );
		$this->assertStringContainsString( 'rel="canonical"', $view->header( 'Link' ) );
		$this->assertStringContainsString( 'cafe-nino', $view->header( 'Link' ) );

		$this->go_to( get_permalink( $this->public_id ) );
		ob_start();
		( new Front() )->alternate_link();
		$head = ob_get_clean();
		$this->assertStringContainsString( 'rel="alternate"', $head );
		$this->assertStringContainsString( 'text/markdown', $head );
		$this->assertStringContainsString( 'cafe-nino.md', $head );
	}

	public function test_index_has_sections_and_optional(): void {
		$index = $this->index();
		$this->assertStringContainsString( "## Posts\n", $index );
		$this->assertStringContainsString( "## Optional\n", $index );
		$this->assertStringContainsString( '/cafe-nino.md', $index );
		$this->assertStringContainsString( '/old-checklist.md', $index );
		$this->assertGreaterThan( strpos( $index, '## Posts' ), strpos( $index, '## Optional' ) );
	}

	public function test_static_front_page_is_served_at_index_md(): void {
		$front_id = $this->publish( 'page', 'Welcome', 'welcome', '<p>Front door.</p>' );
		$posts_id = $this->publish( 'page', 'News', 'news', '<p>Posts page body.</p>' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );
		update_option( 'page_for_posts', $posts_id );
		$this->generate();

		$index = $this->index();
		$this->assertStringContainsString( '/index.md', $index );
		$this->assertStringContainsString( '/news.md', $index );
		$this->assertStringNotContainsString( '.org.md', $index );
		$this->assertStringNotContainsString( 'example.com.md', $index );

		$front = $this->markdown_response( '/index.md' );
		$this->assertSame( 200, $front->status() );
		$this->assertStringContainsString( 'Front door.', $front->body() );

		$posts = $this->markdown_response( '/news.md' );
		$this->assertSame( 200, $posts->status() );
		$this->assertStringContainsString( 'Posts page body.', $posts->body() );

		$this->go_to( home_url( '/' ) );
		$_GET['format'] = 'markdown';
		$format = ( new Front() )->serve_format();
		$this->assertInstanceOf( Response::class, $format );
		$this->assertSame( 200, $format->status() );
		$this->assertStringContainsString( 'Front door.', $format->body() );

		$this->go_to( home_url( '/' ) );
		ob_start();
		( new Front() )->alternate_link();
		$head = (string) ob_get_clean();
		$this->assertStringContainsString( 'index.md', $head );
		$this->assertStringNotContainsString( '.org.md', $head );
	}

	/**
	 * @param string $type     Post type.
	 * @param string $title    Title.
	 * @param string $slug     Slug.
	 * @param string $content  Content.
	 * @param string $excerpt  Excerpt.
	 * @param string $status   Status.
	 * @param string $password Password.
	 */
	private function publish( string $type, string $title, string $slug, string $content, string $excerpt = '', string $status = 'publish', string $password = '' ): int {
		$args = array(
			'post_type'     => $type,
			'post_title'    => $title,
			'post_name'     => $slug,
			'post_status'   => $status,
			'post_content'  => $content,
			'post_excerpt'  => $excerpt,
			'post_password' => $password,
		);
		if ( 'future' === $status ) {
			$args['post_date']     = '2030-01-01 00:00:00';
			$args['post_date_gmt'] = '2030-01-01 00:00:00';
		}
		return (int) self::factory()->post->create( $args );
	}

	private function generate(): int {
		return (int) Generator::trusted(
			static function () {
				return ( new Generator() )->run();
			}
		);
	}

	private function index(): string {
		$body = Store::open()->read_index();
		$this->assertIsString( $body );
		return $body;
	}

	private function full(): ?string {
		return Store::open()->read_full();
	}

	private function document( int $id ): string {
		$body = Store::open()->read_document( $id );
		$this->assertIsString( $body );
		return $body;
	}

	/**
	 * @return string[]
	 */
	private function generated_files_under( string $root ): array {
		$found = array();
		$items = @scandir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $items ) ) {
			return array();
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = rtrim( $root, '/\\' ) . '/' . $item;
			if ( is_dir( $path ) ) {
				foreach ( $this->generated_files_under( $path ) as $nested ) {
					$found[] = $nested;
				}
				continue;
			}
			if ( 'llms.txt' === $item || 'llms-full.txt' === $item ) {
				$found[] = $path;
			}
		}
		return $found;
	}

	private function raw_markdown( string $path ): ?Response {
		$_SERVER['REQUEST_URI'] = $path;
		return ( new Front() )->serve_path();
	}

	private function markdown_response( string $path ): Response {
		$response = $this->raw_markdown( $path );
		$this->assertInstanceOf( Response::class, $response );
		return $response;
	}

	private function post_id_by_slug( string $slug ): int {
		$posts = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => 'post',
				'post_status' => array( 'publish', 'draft', 'private', 'future' ),
				'numberposts' => 1,
			)
		);
		$this->assertNotEmpty( $posts );
		return (int) $posts[0]->ID;
	}
}
