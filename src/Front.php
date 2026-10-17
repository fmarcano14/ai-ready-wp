<?php
/**
 * Serve stored files. Public requests never rebuild them.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP front for /llms.txt, /llms-full.txt, and Markdown views.
 */
final class Front {

	/**
	 * Guard against url_to_postid re-entering parse_request.
	 *
	 * @var bool
	 */
	private static bool $busy = false;

	public function register(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'init', array( $this, 'rewrites' ) );
		add_action( 'parse_request', array( $this, 'on_parse_request' ), 0 );
		add_action( 'template_redirect', array( $this, 'on_template_redirect' ), 0 );
		add_filter( 'redirect_canonical', array( $this, 'keep_plain_404' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'alternate_link' ) );
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ): array {
		$vars[] = 'ai_ready_file';
		return $vars;
	}

	public function rewrites(): void {
		add_rewrite_rule( '^llms\.txt$', 'index.php?ai_ready_file=llms', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?ai_ready_file=full', 'top' );
	}

	/**
	 * @param mixed $wp Request object. Unused.
	 */
	public function on_parse_request( $wp = null ): void {
		unset( $wp );
		if ( self::$busy ) {
			return;
		}
		self::$busy = true;
		try {
			$response = $this->serve_path();
		} finally {
			self::$busy = false;
		}
		if ( $response instanceof Response ) {
			$response->emit();
		}
	}

	public function on_template_redirect(): void {
		$response = $this->serve_format();
		if ( $response instanceof Response ) {
			$response->emit();
		}
	}

	/**
	 * Unknown .md URLs should 404 normally instead of being guessed back to a post.
	 *
	 * @param mixed $redirect  Redirect target.
	 * @param mixed $requested Requested URL.
	 * @return mixed
	 */
	public function keep_plain_404( $redirect, $requested = '' ) {
		unset( $requested );
		$path = $this->request_path();
		if ( strlen( $path ) >= 3 && '.md' === substr( $path, -3 ) ) {
			return false;
		}
		return $redirect;
	}

	public function alternate_link(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! $this->included( $post ) ) {
			return;
		}
		if ( null === Store::from_uploads()->read_document( (int) $post->ID ) ) {
			return;
		}
		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( Urls::markdown( $post ) ) . "\" />\n";
		echo '<link rel="describedby" href="' . esc_url( home_url( '/llms.txt' ) ) . "\" />\n";
	}

	public function serve_path(): ?Response {
		$path     = $this->request_path();
		$resolved = false;
		$included = false;
		$id       = 0;
		$post     = null;
		if ( strlen( $path ) >= 3 && '.md' === substr( $path, -3 ) ) {
			$post = $this->resolve_post( substr( $path, 0, -3 ) );
			if ( $post instanceof \WP_Post ) {
				$resolved = true;
				$id       = (int) $post->ID;
				$included = $this->included( $post );
			}
		}
		return $this->answer( Route::decide( $path, $resolved, $included, $id ), $post );
	}

	public function serve_format(): ?Response {
		$singular = is_singular();
		$post     = $singular ? get_queried_object() : null;
		$resolved = $post instanceof \WP_Post;
		$included = $resolved && $this->included( $post );
		$id       = $resolved ? (int) $post->ID : 0;
		return $this->answer( Route::decide_format( $this->wants_markdown(), $singular, $resolved, $included, $id ), $resolved ? $post : null );
	}

	/**
	 * @param array{action: string, id: int} $decision Decision.
	 * @param \WP_Post|null                  $post     Resolved post.
	 */
	private function answer( array $decision, ?\WP_Post $post ): ?Response {
		if ( Route::FALL === $decision['action'] ) {
			return null;
		}
		if ( Route::MISS === $decision['action'] ) {
			return Response::not_found();
		}
		if ( Route::INDEX === $decision['action'] ) {
			return $this->stored( 'llms.txt' );
		}
		if ( Route::FULL === $decision['action'] ) {
			return $this->stored( 'llms-full.txt' );
		}
		if ( ! $post instanceof \WP_Post ) {
			return Response::not_found();
		}
		$body = Store::from_uploads()->read_document( (int) $post->ID );
		if ( null === $body ) {
			return Response::not_found();
		}
		$canonical = get_permalink( $post );
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			$canonical = home_url( '/' );
		}
		return Response::markdown( $body, $canonical );
	}

	private function stored( string $name ): Response {
		$store = Store::from_uploads();
		$body  = 'llms.txt' === $name ? $store->read_index() : $store->read_full();
		if ( null === $body ) {
			return Response::not_found();
		}
		return Response::plain( $body );
	}

	private function included( \WP_Post $post ): bool {
		$settings = Settings::current();
		return Inclusion::allowed( Snapshot::from_post( $post ), $settings->post_types() );
	}

	private function resolve_post( string $bare ): ?\WP_Post {
		$bare = trim( $bare, '/' );
		if ( '' === $bare || false !== strpos( $bare, '..' ) ) {
			return null;
		}
		$id = (int) url_to_postid( home_url( '/' . $bare . '/' ) );
		if ( $id < 1 ) {
			$id = (int) url_to_postid( home_url( '/' . $bare ) );
		}
		if ( $id < 1 ) {
			$page = get_page_by_path( $bare, OBJECT, $this->lookup_types() );
			if ( $page instanceof \WP_Post ) {
				$id = (int) $page->ID;
			}
		}
		if ( $id < 1 ) {
			return null;
		}
		$post = get_post( $id );
		// Drafts, private, and scheduled posts are not public URLs. Leave them for WordPress.
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}
		return $post;
	}

	/**
	 * @return string[]
	 */
	private function lookup_types(): array {
		$types = get_post_types( array( 'public' => true ) );
		if ( ! is_array( $types ) ) {
			return array( 'post', 'page' );
		}
		unset( $types['attachment'] );
		$names = array_values( $types );
		return array() === $names ? array( 'post', 'page' ) : $names;
	}

	public function request_path(): string {
		$raw = '/';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			// path_from_raw() strips controls and parent segments. sanitize_text_field() would delete percent-encoded UTF-8.
			$candidate = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $candidate ) ) {
				$raw = $candidate;
			}
		}
		return $this->path_from_raw( $raw );
	}

	private function path_from_raw( string $raw ): string {
		$raw  = str_replace( array( "\r", "\n", "\0" ), '', $raw );
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = '/';
		}
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( is_string( $home ) && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = (string) substr( $path, strlen( $home ) );
		}
		$path = trim( rawurldecode( $path ), '/' );
		if ( false !== strpos( $path, '..' ) ) {
			return '';
		}
		return $path;
	}

	private function wants_markdown(): bool {
		// Public content negotiation. This is not a state-changing form.
		if ( ! isset( $_GET['format'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$format = wp_unslash( $_GET['format'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public content negotiation, checked with sanitize_key.
		if ( ! is_string( $format ) ) {
			return false;
		}
		return 'markdown' === sanitize_key( $format );
	}
}
