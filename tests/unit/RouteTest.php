<?php
/**
 * Routing decisions for public URLs.
 *
 * @package AiReady
 */

namespace AiReady\Tests;

use AiReady\Response;
use AiReady\Route;
use PHPUnit\Framework\TestCase;

/**
 * Which URLs this plugin claims.
 */
final class RouteTest extends TestCase {

	public function test_markdown_route_claims_only_an_included_document(): void {
		$claimed = Route::decide( 'hello.md', true, true, 5 );
		$this->assertSame( Route::DOC, $claimed['action'] );
		$this->assertSame( 5, $claimed['id'] );

		$this->assertSame( Route::MISS, Route::decide( 'secret.md', true, false, 9 )['action'] );
		$this->assertSame( Route::FALL, Route::decide( 'not-a-page.md', false, false, 0 )['action'] );
		$this->assertSame( Route::FALL, Route::decide( 'about', false, false, 0 )['action'] );
	}

	public function test_index_files_are_claimed_without_a_rebuild_action(): void {
		$this->assertSame( Route::INDEX, Route::decide( 'llms.txt', false, false, 0 )['action'] );
		$this->assertSame( Route::FULL, Route::decide( 'llms-full.txt', false, false, 0 )['action'] );
		$this->assertSame( Route::FALL, Route::decide( 'notes/llms.txt', false, false, 0 )['action'] );
	}

	public function test_format_query_ignores_anything_that_is_not_a_single_document(): void {
		$this->assertSame( Route::FALL, Route::decide_format( true, false, false, false, 0 )['action'] );
		$this->assertSame( Route::FALL, Route::decide_format( false, true, true, true, 1 )['action'] );
		$this->assertSame( Route::MISS, Route::decide_format( true, true, true, false, 4 )['action'] );
		$this->assertSame( Route::DOC, Route::decide_format( true, true, true, true, 4 )['action'] );
	}

	public function test_markdown_response_sends_the_required_headers(): void {
		$response = Response::markdown( "# Café\n", 'https://example.com/cafe/' );
		$this->assertSame( 200, $response->status() );
		$this->assertSame( 'text/markdown; charset=utf-8', $response->header( 'Content-Type' ) );
		$this->assertSame( 'noindex', $response->header( 'X-Robots-Tag' ) );
		$this->assertSame( '<https://example.com/cafe/>; rel="canonical"', $response->header( 'Link' ) );
		$this->assertStringContainsString( 'Café', $response->body() );
	}

	public function test_canonical_header_cannot_inject_another_header(): void {
		$response = Response::markdown( 'x', "https://example.com/\r\nX-Evil: 1" );
		$this->assertStringNotContainsString( "\n", $response->header( 'Link' ) );
		$this->assertStringNotContainsString( "\r", $response->header( 'Link' ) );
		$this->assertStringNotContainsString( 'X-Evil', $response->header( 'Link' ) );
	}

	public function test_missing_file_is_a_plain_404(): void {
		$response = Response::not_found();
		$this->assertSame( 404, $response->status() );
		$this->assertSame( 'text/plain; charset=utf-8', $response->header( 'Content-Type' ) );
		$this->assertStringContainsString( 'Not found', $response->body() );
	}
}
