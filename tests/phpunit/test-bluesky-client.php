<?php
/**
 * BlueskyClient unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\Publishers\BlueskyClient;
use ApexChute\ApexCast\Publishers\PublisherException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the AT Protocol client.
 *
 * Pure HTTP work — Guzzle MockHandler injection, no WordPress dependencies.
 */
final class Bluesky_Client_Test extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> Captured outbound requests.
	 */
	private array $history = array();

	/**
	 * Build a BlueskyClient whose HTTP client is backed by the given mocked
	 * responses and whose outbound requests are recorded.
	 *
	 * @param Response[] $responses Mocked responses, in call order.
	 * @return BlueskyClient
	 */
	private function client_with_responses( array $responses ): BlueskyClient {
		$mock          = new MockHandler( $responses );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$guzzle = new Client( array( 'handler' => $stack ) );
		return new BlueskyClient( $guzzle );
	}

	public function test_create_session_returns_jwts_and_did(): void {
		$body = (string) json_encode(
			array(
				'accessJwt'  => 'access_jwt_X',
				'refreshJwt' => 'refresh_jwt_X',
				'did'        => 'did:plc:abc123',
				'handle'     => 'viciousfun.bsky.social',
			)
		);
		$client  = $this->client_with_responses( array( new Response( 200, array(), $body ) ) );
		$session = $client->create_session( 'viciousfun.bsky.social', 'app-pass-word' );

		$this->assertSame( 'access_jwt_X', $session['accessJwt'] );
		$this->assertSame( 'refresh_jwt_X', $session['refreshJwt'] );
		$this->assertSame( 'did:plc:abc123', $session['did'] );
		$this->assertSame( 'viciousfun.bsky.social', $session['handle'] );

		$sent = $this->history[0]['request'];
		$this->assertSame( 'POST', $sent->getMethod() );
		$this->assertStringContainsString( '/com.atproto.server.createSession', (string) $sent->getUri() );

		$payload = json_decode( (string) $sent->getBody(), true );
		$this->assertSame( 'viciousfun.bsky.social', $payload['identifier'] );
		$this->assertSame( 'app-pass-word', $payload['password'] );
	}

	public function test_create_session_throws_auth_failed_on_401(): void {
		$client = $this->client_with_responses( array( new Response( 401 ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/credentials/i' );
		$client->create_session( 'h', 'p' );
	}

	public function test_create_session_throws_malformed_when_did_missing(): void {
		$body   = (string) json_encode( array( 'accessJwt' => 'x' ) );
		$client = $this->client_with_responses( array( new Response( 200, array(), $body ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/accessJwt or did/i' );
		$client->create_session( 'h', 'p' );
	}

	public function test_upload_blob_returns_blob_ref(): void {
		$blob = array(
			'$type'    => 'blob',
			'ref'      => array( '$link' => 'bafyrefX' ),
			'mimeType' => 'image/jpeg',
			'size'     => 12345,
		);
		$body   = (string) json_encode( array( 'blob' => $blob ) );
		$client = $this->client_with_responses( array( new Response( 200, array(), $body ) ) );

		$ref = $client->upload_blob( 'access_jwt_X', 'RAWBYTES', 'image/jpeg' );

		$this->assertSame( 'blob', $ref['$type'] );
		$this->assertSame( 'bafyrefX', $ref['ref']['$link'] );

		$sent = $this->history[0]['request'];
		$this->assertSame( 'POST', $sent->getMethod() );
		$this->assertStringContainsString( '/com.atproto.repo.uploadBlob', (string) $sent->getUri() );
		$this->assertSame( 'Bearer access_jwt_X', $sent->getHeaderLine( 'Authorization' ) );
		$this->assertSame( 'image/jpeg', $sent->getHeaderLine( 'Content-Type' ) );
		$this->assertSame( 'RAWBYTES', (string) $sent->getBody() );
	}

	public function test_upload_blob_throws_malformed_when_blob_missing(): void {
		$body   = (string) json_encode( array( 'nope' => true ) );
		$client = $this->client_with_responses( array( new Response( 200, array(), $body ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/blob ref/i' );
		$client->upload_blob( 'jwt', 'bytes', 'image/png' );
	}

	public function test_create_record_returns_uri_and_cid(): void {
		$body = (string) json_encode(
			array(
				'uri' => 'at://did:plc:abc123/app.bsky.feed.post/3kabc',
				'cid' => 'bafyCID',
			)
		);
		$client = $this->client_with_responses( array( new Response( 200, array(), $body ) ) );

		$record  = array( 'text' => 'hi', '$type' => 'app.bsky.feed.post' );
		$created = $client->create_record( 'jwt', 'did:plc:abc123', $record );

		$this->assertSame( 'at://did:plc:abc123/app.bsky.feed.post/3kabc', $created['uri'] );
		$this->assertSame( 'bafyCID', $created['cid'] );

		$sent = $this->history[0]['request'];
		$this->assertStringContainsString( '/com.atproto.repo.createRecord', (string) $sent->getUri() );
		$this->assertSame( 'Bearer jwt', $sent->getHeaderLine( 'Authorization' ) );

		$payload = json_decode( (string) $sent->getBody(), true );
		$this->assertSame( 'did:plc:abc123', $payload['repo'] );
		$this->assertSame( 'app.bsky.feed.post', $payload['collection'] );
		$this->assertSame( 'hi', $payload['record']['text'] );
	}

	public function test_create_record_throws_malformed_when_uri_missing(): void {
		$body   = (string) json_encode( array( 'cid' => 'x' ) );
		$client = $this->client_with_responses( array( new Response( 200, array(), $body ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/post uri/i' );
		$client->create_record( 'jwt', 'did', array() );
	}

	public function test_classify_429_maps_to_rate_limited(): void {
		$client = $this->client_with_responses( array( new Response( 429 ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/rate limit/i' );
		$client->create_session( 'h', 'p' );
	}

	public function test_classify_500_maps_to_http_error_with_status(): void {
		$client = $this->client_with_responses( array( new Response( 500 ) ) );

		try {
			$client->create_session( 'h', 'p' );
			$this->fail( 'Expected PublisherException.' );
		} catch ( PublisherException $e ) {
			$this->assertStringContainsString( '500', $e->getMessage() );
		}
	}
}
