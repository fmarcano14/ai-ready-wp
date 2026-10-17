<?php
/**
 * Behaviors exercised against the WordPress test suite.
 *
 * @package AiReady
 */

use AiReady\AdminPage;
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

		Store::from_uploads()->destroy();
		wp_clear_scheduled_hook( Scheduler::HOOK );
		wp_set_current_user( $this->admin );
		$this->generate();
	}

	public function tear_down(): void {
		remove_shortcode( 'secret' );
		remove_shortcode( 'who' );
		Store::from_uploads()->destroy();
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

		$this->assertStringNotContainsString( 'cafe-nino.md', $this->index() );
		$this->assertStringNotContainsString( '`<div>`', (string) $this->full() );
		$hidden = $this->markdown_response( '/cafe-nino.md' );
		$this->assertSame( 404, $hidden->status() );
		$this->assertStringNotContainsString( 'user-0', $hidden->body() );

		update_post_meta( $this->excerpt_id, ExcludeMeta::META_KEY, '1' );
		$this->assertStringNotContainsString( 'river-notes.md', $this->index() );
		$this->assertStringNotContainsString( 'Manual note about ñ', (string) $this->full() );

		update_post_meta( $this->page_id, '_yoast_wpseo_meta-robots-noindex', '1' );
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

	public function test_generated_output_lives_in_one_place(): void {
		$store = Store::from_uploads();
		$this->assertTrue( $store->has_index() );
		$this->assertTrue( $store->has_full() );
		$this->assertNotNull( $store->read_document( $this->public_id ) );

		$needle = 'cafe-nino.md';
		foreach ( wp_load_alloptions() as $key => $value ) {
			if ( is_string( $value ) && false !== strpos( $value, $needle ) ) {
				$this->fail( 'Generated output was stored in option ' . $key );
			}
		}
		$meta = get_post_meta( $this->public_id );
		foreach ( $meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				if ( is_string( $value ) && false !== strpos( $value, $needle ) ) {
					$this->fail( 'Generated output was stored in meta ' . $key );
				}
			}
		}
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

	public function test_uninstall_removes_plugin_data(): void {
		update_post_meta( $this->public_id, ExcludeMeta::META_KEY, '1' );
		set_transient( 'ai_ready_rebuild', '1', MINUTE_IN_SECONDS );
		$this->assertTrue( Store::from_uploads()->has_index() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertSame( '', (string) get_post_meta( $this->public_id, ExcludeMeta::META_KEY, true ) );
		$this->assertFalse( get_transient( 'ai_ready_rebuild' ) );
		$this->assertFalse( Store::from_uploads()->has_index() );
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
		$body = Store::from_uploads()->read_index();
		$this->assertIsString( $body );
		return $body;
	}

	private function full(): ?string {
		return Store::from_uploads()->read_full();
	}

	private function document( int $id ): string {
		$body = Store::from_uploads()->read_document( $id );
		$this->assertIsString( $body );
		return $body;
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
