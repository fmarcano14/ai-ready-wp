<?php
/**
 * Public routes for llms.txt and Markdown documents.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Http;

use Fer\AiReady\Cache\Store;
use Fer\AiReady\Content\Renderer;
use Fer\AiReady\Generator;
use Fer\AiReady\Publication;
use Fer\AiReady\Settings;

/**
 * Serves /llms.txt, /llms-full.txt, permalink.md, and ?format=markdown.
 *
 * Rewrite rules are registered on init and again during activation. Requests
 * are also recognized from the parsed path so a flushed-rule miss still works
 * when the request reaches WordPress. Canonical redirects are disabled for
 * these routes. Eligibility is checked before any cached Markdown is read.
 *
 * @since 1.0.0
 */
final class Router {
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
	 * Cache store.
	 *
	 * @since 1.0.0
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Index generator.
	 *
	 * @since 1.0.0
	 *
	 * @var Generator
	 */
	private $generator;

	/**
	 * Document renderer.
	 *
	 * @since 1.0.0
	 *
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings    $settings    Settings reader.
	 * @param Publication $publication Eligibility policy.
	 * @param Store       $store       Cache store.
	 * @param Generator   $generator   Index generator.
	 * @param Renderer    $renderer    Document renderer.
	 */
	public function __construct( Settings $settings, Publication $publication, Store $store, Generator $generator, Renderer $renderer ) {
		$this->settings    = $settings;
		$this->publication = $publication;
		$this->store       = $store;
		$this->generator   = $generator;
		$this->renderer    = $renderer;
	}

	/**
	 * Register rewrite rules.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_rewrites() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?ai_ready_wp_file=llms.txt', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?ai_ready_wp_file=llms-full.txt', 'top' );
		add_rewrite_rule( '^(.+?)\.md$', 'index.php?ai_ready_wp_markdown=1&ai_ready_wp_path=$matches[1]', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function query_vars( $vars ) {
		$vars[] = 'ai_ready_wp_file';
		$vars[] = 'ai_ready_wp_markdown';
		$vars[] = 'ai_ready_wp_path';
		return $vars;
	}

	/**
	 * Recognize routes from the request path even before rules are flushed.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP $wp WordPress environment.
	 * @return void
	 */
	public function parse_request( $wp ) {
		if ( ! $wp instanceof \WP ) {
			return;
		}
		$path = isset( $wp->request ) ? (string) $wp->request : '';
		$path = trim( $path, '/' );
		if ( 'llms.txt' === $path ) {
			$wp->query_vars['ai_ready_wp_file'] = 'llms.txt';
			return;
		}
		if ( 'llms-full.txt' === $path ) {
			$wp->query_vars['ai_ready_wp_file'] = 'llms-full.txt';
			return;
		}
		if ( strlen( $path ) > 3 && '.md' === substr( $path, -3 ) ) {
			$wp->query_vars['ai_ready_wp_markdown'] = '1';
			$wp->query_vars['ai_ready_wp_path']     = substr( $path, 0, -3 );
		}
	}

	/**
	 * Keep WordPress from redirecting these routes to a guessed permalink.
	 *
	 * @since 1.0.0
	 *
	 * @param string|false $redirect  Redirect target.
	 * @param string       $requested Requested URL.
	 * @return string|false
	 */
	public function disable_canonical( $redirect, $requested ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( $this->is_plugin_request() ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Disable the 404 permalink guess for plugin routes.
	 *
	 * @since 1.0.0
	 *
	 * @param string|false|null $guess Guessed permalink.
	 * @return string|false|null
	 */
	public function disable_guess( $guess ) {
		if ( $this->is_plugin_request() ) {
			return false;
		}
		return $guess;
	}

	/**
	 * Remove this plugin's rules during deactivation.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $rules Rewrite rules.
	 * @return array<string, string>
	 */
	public static function strip_rules( $rules ) {
		$owned = array(
			'^llms\.txt$',
			'^llms-full\.txt$',
			'^(.+?)\.md$',
		);
		foreach ( array_keys( $rules ) as $regex ) {
			if ( in_array( $regex, $owned, true ) ) {
				unset( $rules[ $regex ] );
			}
		}
		return $rules;
	}

	/**
	 * Serve a matching request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function serve() {
		$file = (string) get_query_var( 'ai_ready_wp_file' );
		if ( 'llms.txt' === $file ) {
			$this->serve_index( false );
		}
		if ( 'llms-full.txt' === $file ) {
			$this->serve_index( true );
		}
		if ( '1' === (string) get_query_var( 'ai_ready_wp_markdown' ) ) {
			$this->serve_markdown_path( (string) get_query_var( 'ai_ready_wp_path' ) );
		}
		if ( $this->wants_format_markdown() ) {
			$this->serve_markdown_query();
		}
	}

	/**
	 * Whether the current request belongs to the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_plugin_request() {
		if ( '' !== (string) get_query_var( 'ai_ready_wp_file' ) || '1' === (string) get_query_var( 'ai_ready_wp_markdown' ) ) {
			return true;
		}
		return $this->wants_format_markdown();
	}

	/**
	 * Whether the public format query argument asks for Markdown.
	 *
	 * This is a read-only representation of an already public document, so it
	 * does not use a nonce.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function wants_format_markdown() {
		if ( ! isset( $_GET['format'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$format = sanitize_key( wp_unslash( $_GET['format'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'markdown' === $format;
	}

	/**
	 * Serve an index file.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $full Whether this is llms-full.txt.
	 * @return void
	 */
	private function serve_index( $full ) {
		if ( ! $this->publication->discovery_enabled() ) {
			$this->emit_error( 404, __( 'LLM discovery is disabled.', 'ai-ready-wp' ) );
		}
		$settings = $this->settings->all();
		if ( $full && empty( $settings['enable_llms_full'] ) ) {
			$this->emit_error( 404, __( 'llms-full.txt is disabled.', 'ai-ready-wp' ) );
		}

		$body = $this->store->get_index( $full );
		if ( ! is_string( $body ) ) {
			$result = $this->generator->generate();
			if ( is_wp_error( $result ) ) {
				$status = 'ai_ready_wp_busy' === $result->get_error_code() ? 503 : 500;
				$this->emit_error( $status, $result->get_error_message(), 503 === $status ? 10 : 0 );
			}
			$body = $full ? $result['full'] : $result['llms'];
		}
		if ( ! is_string( $body ) || '' === $body ) {
			$this->emit_error( 404, __( 'The index is not available.', 'ai-ready-wp' ) );
		}

		$meta     = $this->store->meta();
		$modified = isset( $meta['generated_at'] ) ? (int) $meta['generated_at'] : 0;
		$this->emit( $body, 'text/plain', home_url( '/' ), $modified, true );
	}

	/**
	 * Serve Markdown addressed by a rewrite path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path without the .md suffix.
	 * @return void
	 */
	private function serve_markdown_path( $path ) {
		if ( ! $this->markdown_enabled() ) {
			$this->emit_error( 404, __( 'Markdown output is disabled.', 'ai-ready-wp' ) );
		}
		$path = trim( (string) $path, '/' );
		if ( '' === $path || false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
			$this->emit_error( 404, __( 'Markdown representation not available.', 'ai-ready-wp' ) );
		}
		$post = $this->resolve_path( $path );
		$this->serve_post( $post );
	}

	/**
	 * Serve Markdown for the current singular query.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function serve_markdown_query() {
		if ( ! $this->markdown_enabled() ) {
			$this->emit_error( 404, __( 'Markdown output is disabled.', 'ai-ready-wp' ) );
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post && is_front_page() ) {
			$front = (int) get_option( 'page_on_front' );
			$post  = $front ? get_post( $front ) : null;
		}
		$this->serve_post( $post instanceof \WP_Post ? $post : null );
	}

	/**
	 * Resolve a path to a post.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path without the suffix.
	 * @return \WP_Post|null
	 */
	private function resolve_path( $path ) {
		if ( 'index' === $path ) {
			$front = (int) get_option( 'page_on_front' );
			$post  = $front ? get_post( $front ) : null;
			return $post instanceof \WP_Post ? $post : null;
		}
		$id = url_to_postid( home_url( '/' . $path . '/' ) );
		if ( ! $id ) {
			$id = url_to_postid( home_url( '/' . $path ) );
		}
		if ( $id ) {
			$post = get_post( $id );
			return $post instanceof \WP_Post ? $post : null;
		}
		$types = $this->settings->all()['post_types'];
		$post  = get_page_by_path( $path, OBJECT, $types );
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Serve one post after the eligibility check.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post|null $post Post object.
	 * @return void
	 */
	private function serve_post( $post ) {
		if ( ! $post instanceof \WP_Post || ! $this->publication->is_public_post( $post ) ) {
			$this->emit_error( 404, __( 'Markdown representation not available.', 'ai-ready-wp' ) );
		}
		$body = $this->store->markdown_for( $post, $this->renderer );
		if ( is_wp_error( $body ) ) {
			$status = 'ai_ready_wp_busy' === $body->get_error_code() ? 503 : 500;
			if ( 'ai_ready_wp_too_large' === $body->get_error_code() ) {
				$status = 413;
			}
			$this->emit_error( $status, $body->get_error_message(), 503 === $status ? 10 : 0 );
		}
		$modified = strtotime( $post->post_modified_gmt . ' UTC' );
		$this->emit( $body, 'text/markdown', (string) get_permalink( $post ), $modified ? (int) $modified : 0, false );
	}

	/**
	 * Whether Markdown endpoints are enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function markdown_enabled() {
		return ! empty( $this->settings->all()['enable_markdown'] );
	}

	/**
	 * Send an error and stop.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $status      HTTP status.
	 * @param string $message     Plain-text message.
	 * @param int    $retry_after Retry-After seconds. Zero omits the header.
	 * @return void
	 */
	private function emit_error( $status, $message, $retry_after = 0 ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $retry_after > 0 ) {
			header( 'Retry-After: ' . (int) $retry_after );
		}
		echo $message . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text error, already translated.
		exit;
	}

	/**
	 * Send a successful body and stop.
	 *
	 * @since 1.0.0
	 *
	 * @param string $body     Response body.
	 * @param string $mime     Media type without charset.
	 * @param string $source   Canonical or home URL.
	 * @param int    $modified Unix timestamp.
	 * @param bool   $index    Whether this response is an index file.
	 * @return void
	 */
	private function emit( $body, $mime, $source, $modified, $index ) {
		$etag = '"' . hash( 'sha256', $body ) . '"';
		status_header( 200 );
		header( 'Content-Type: ' . $mime . '; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: ' . ( $index ? 'all' : 'noindex' ) );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'ETag: ' . $etag );
		if ( $modified > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
		}
		$links = array();
		if ( ! $index && '' !== $source ) {
			$links[] = '<' . esc_url_raw( $source ) . '>; rel="canonical"';
		}
		if ( $this->publication->discovery_enabled() ) {
			$links[] = '<' . esc_url_raw( home_url( '/llms.txt' ) ) . '>; rel="describedby"';
		}
		if ( array() !== $links ) {
			header( 'Link: ' . implode( ', ', $links ) );
		}
		if ( $this->etag_matches( $etag ) ) {
			status_header( 304 );
			exit;
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized text/markdown body, not HTML.
		exit;
	}

	/**
	 * Whether the request's If-None-Match header matches.
	 *
	 * @since 1.0.0
	 *
	 * @param string $etag Expected ETag.
	 * @return bool
	 */
	private function etag_matches( $etag ) {
		if ( ! isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) || ! is_string( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$header   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expected = preg_replace( '/^W\//i', '', trim( $etag ) );
		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			$bare      = preg_replace( '/^W\//i', '', $candidate );
			if ( '*' === $candidate || $bare === $expected ) {
				return true;
			}
		}
		return false;
	}
}
