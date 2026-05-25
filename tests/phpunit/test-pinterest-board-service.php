<?php
/**
 * PinterestBoardService unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\Publishers\PinterestBoardService;
use ApexChute\ApexCast\Publishers\PublisherException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the Pinterest board service.
 *
 * Pure HTTP work — Guzzle MockHandler injection, no WordPress dependencies.
 */
final class Pinterest_Board_Service_Test extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> Captured outbound requests.
	 */
	private array $history = array();

	/**
	 * Build a PinterestBoardService whose HTTP client is backed by the given
	 * mocked responses and whose outbound requests are recorded.
	 *
	 * @param Response[] $responses Mocked responses, in call order.
	 * @return PinterestBoardService
	 */
	private function service_with_responses( array $responses ): PinterestBoardService {
		$mock          = new MockHandler( $responses );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );
		return new PinterestBoardService( 'tok_test', 'production', $client );
	}

	public function test_list_boards_returns_single_page(): void {
		$body = (string) json_encode(
			array(
				'items'    => array(
					array( 'id' => 'b1', 'name' => 'Gargamel', 'privacy' => 'PUBLIC' ),
					array( 'id' => 'b2', 'name' => 'Shirahama', 'privacy' => 'PUBLIC' ),
				),
				'bookmark' => null,
			)
		);

		$service = $this->service_with_responses( array( new Response( 200, array(), $body ) ) );
		$boards  = $service->list_boards();

		$this->assertCount( 2, $boards );
		$this->assertSame( 'b1', $boards[0]['id'] );
		$this->assertSame( 'Gargamel', $boards[0]['name'] );
		$this->assertSame( 'PUBLIC', $boards[0]['privacy'] );
		$this->assertSame( 'Shirahama', $boards[1]['name'] );

		$sent = $this->history[0]['request'];
		$this->assertSame( 'GET', $sent->getMethod() );
		$this->assertStringContainsString( '/boards', (string) $sent->getUri() );
		$this->assertStringContainsString( 'page_size=100', (string) $sent->getUri() );
		$this->assertStringContainsString( 'privacy=ALL', (string) $sent->getUri() );
		$this->assertSame( 'Bearer tok_test', $sent->getHeaderLine( 'Authorization' ) );
	}

	public function test_list_boards_follows_bookmark_across_pages(): void {
		$page_one = (string) json_encode(
			array(
				'items'    => array(
					array( 'id' => 'b1', 'name' => 'Gargamel', 'privacy' => 'PUBLIC' ),
				),
				'bookmark' => 'cursor_two',
			)
		);
		$page_two = (string) json_encode(
			array(
				'items'    => array(
					array( 'id' => 'b2', 'name' => 'Shirahama', 'privacy' => 'PUBLIC' ),
				),
				// No bookmark → done.
			)
		);

		$service = $this->service_with_responses(
			array(
				new Response( 200, array(), $page_one ),
				new Response( 200, array(), $page_two ),
			)
		);

		$boards = $service->list_boards();

		$this->assertCount( 2, $boards );
		$this->assertSame( 'b1', $boards[0]['id'] );
		$this->assertSame( 'b2', $boards[1]['id'] );

		$this->assertCount( 2, $this->history, 'Should have followed the bookmark to page 2.' );
		$second_uri = (string) $this->history[1]['request']->getUri();
		$this->assertStringContainsString( 'bookmark=cursor_two', $second_uri );
	}

	public function test_list_boards_throws_auth_failed_on_401(): void {
		$service = $this->service_with_responses( array( new Response( 401 ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/credentials/i' );
		$service->list_boards();
	}

	public function test_list_boards_throws_http_error_on_500(): void {
		$service = $this->service_with_responses( array( new Response( 500 ) ) );

		try {
			$service->list_boards();
			$this->fail( 'Expected PublisherException.' );
		} catch ( PublisherException $e ) {
			$this->assertStringContainsString( '500', $e->getMessage() );
		}
	}

	public function test_create_public_board_returns_id(): void {
		$body    = (string) json_encode( array( 'id' => 'new_board_xyz' ) );
		$service = $this->service_with_responses( array( new Response( 201, array(), $body ) ) );

		$id = $service->create_public_board( 'Gargamel' );

		$this->assertSame( 'new_board_xyz', $id );

		$sent = $this->history[0]['request'];
		$this->assertSame( 'POST', $sent->getMethod() );
		$this->assertStringContainsString( '/boards', (string) $sent->getUri() );

		$payload = json_decode( (string) $sent->getBody(), true );
		$this->assertSame( 'Gargamel', $payload['name'] );
		$this->assertSame( 'PUBLIC', $payload['privacy'] );
	}

	public function test_create_public_board_throws_when_id_missing(): void {
		$body    = (string) json_encode( array( 'wrong_field' => 'nope' ) );
		$service = $this->service_with_responses( array( new Response( 201, array(), $body ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/board id/i' );
		$service->create_public_board( 'Gargamel' );
	}

	public function test_create_public_board_throws_auth_failed_on_401(): void {
		$service = $this->service_with_responses( array( new Response( 401 ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/credentials/i' );
		$service->create_public_board( 'Gargamel' );
	}

	public function test_sandbox_mode_uses_sandbox_host(): void {
		$body          = (string) json_encode(
			array(
				'items'    => array(
					array( 'id' => 'b1', 'name' => 'Gargamel', 'privacy' => 'PUBLIC' ),
				),
				'bookmark' => null,
			)
		);
		$mock          = new MockHandler( array( new Response( 200, array(), $body ) ) );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );

		$service = new PinterestBoardService( 'tok_test', 'sandbox', $client );
		$service->list_boards();

		$sent = $this->history[0]['request'];
		$this->assertStringContainsString(
			'api-sandbox.pinterest.com',
			(string) $sent->getUri()
		);
	}
}
