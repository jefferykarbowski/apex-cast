<?php
/**
 * ThreadsOAuth unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\OAuth\ThreadsOAuth;
use ApexChute\ApexCast\Publishers\PublisherException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Threads OAuth client: auth-URL assembly + the three token calls
 * (code exchange, long-lived exchange, refresh).
 *
 * Pure HTTP work — Guzzle MockHandler injection, no WordPress dependencies.
 */
final class Threads_OAuth_Test extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> Captured outbound requests.
	 */
	private array $history = array();

	/**
	 * Build a ThreadsOAuth whose HTTP client is backed by the given mocked
	 * responses and whose outbound requests are recorded.
	 *
	 * @param Response[] $responses  Mocked responses in call order.
	 * @param string     $app_id     Optional app id override.
	 * @param string     $app_secret Optional app secret override.
	 * @return ThreadsOAuth
	 */
	private function oauth_with_responses( array $responses, string $app_id = 'tid_test', string $app_secret = 'tsec_test' ): ThreadsOAuth {
		$mock          = new MockHandler( $responses );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );
		return new ThreadsOAuth( $app_id, $app_secret, $client );
	}

	/**
	 * Pull the query string out of a URL for assertion.
	 *
	 * @param string $url Full URL.
	 * @return array<string, string>
	 */
	private function query_of( string $url ): array {
		$parts = parse_url( $url );
		$query = is_array( $parts ) && isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$out   = array();
		parse_str( $query, $out );
		return $out;
	}

	public function test_is_configured_requires_both_id_and_secret(): void {
		$this->assertFalse( $this->oauth_with_responses( array(), '', 'sec' )->is_configured() );
		$this->assertFalse( $this->oauth_with_responses( array(), 'id', '' )->is_configured() );
		$this->assertTrue( $this->oauth_with_responses( array(), 'id', 'sec' )->is_configured() );
	}

	public function test_build_auth_url_includes_all_required_params(): void {
		$oauth = $this->oauth_with_responses( array() );
		$url   = $oauth->build_auth_url( 'https://example.com/cb', 'STATE_T' );

		$this->assertStringStartsWith( 'https://threads.net/oauth/authorize?', $url );

		$parts = $this->query_of( $url );
		$this->assertSame( 'tid_test', $parts['client_id'] );
		$this->assertSame( 'https://example.com/cb', $parts['redirect_uri'] );
		$this->assertSame( 'code', $parts['response_type'] );
		$this->assertSame( 'STATE_T', $parts['state'] );
		$this->assertSame( 'threads_basic,threads_content_publish', $parts['scope'] );
	}

	public function test_exchange_code_returns_token_and_user_id(): void {
		$body  = (string) json_encode(
			array(
				'access_token' => 'short_tok',
				'user_id'      => 17841400000000000,
			)
		);
		$oauth = $this->oauth_with_responses( array( new Response( 200, array(), $body ) ) );

		$result = $oauth->exchange_code_for_token( 'CODE_X', 'https://example.com/cb' );

		$this->assertSame( 'short_tok', $result['access_token'] );
		$this->assertSame( '17841400000000000', $result['user_id'] );

		$sent = $this->history[0]['request'];
		$this->assertSame( 'POST', $sent->getMethod() );
		$this->assertStringContainsString( 'graph.threads.net/oauth/access_token', (string) $sent->getUri() );

		$form = (string) $sent->getBody();
		$this->assertStringContainsString( 'grant_type=authorization_code', $form );
		$this->assertStringContainsString( 'code=CODE_X', $form );
		$this->assertStringContainsString( 'client_id=tid_test', $form );
	}

	public function test_exchange_code_throws_malformed_when_user_id_missing(): void {
		$body  = (string) json_encode( array( 'access_token' => 'short_tok' ) );
		$oauth = $this->oauth_with_responses( array( new Response( 200, array(), $body ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/user_id/i' );
		$oauth->exchange_code_for_token( 'C', 'https://example.com/cb' );
	}

	public function test_exchange_code_throws_auth_failed_on_401(): void {
		$oauth = $this->oauth_with_responses( array( new Response( 401 ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/credentials/i' );
		$oauth->exchange_code_for_token( 'C', 'https://example.com/cb' );
	}

	public function test_long_lived_exchange_returns_token_and_expiry(): void {
		$body  = (string) json_encode(
			array(
				'access_token' => 'long_tok',
				'token_type'   => 'bearer',
				'expires_in'   => 5184000,
			)
		);
		$oauth = $this->oauth_with_responses( array( new Response( 200, array(), $body ) ) );

		$result = $oauth->exchange_for_long_lived_token( 'short_tok' );

		$this->assertSame( 'long_tok', $result['access_token'] );
		$this->assertSame( 5184000, $result['expires_in'] );

		$sent  = $this->history[0]['request'];
		$this->assertSame( 'GET', $sent->getMethod() );
		$this->assertStringContainsString( 'graph.threads.net/access_token', (string) $sent->getUri() );
		$query = $this->query_of( (string) $sent->getUri() );
		$this->assertSame( 'th_exchange_token', $query['grant_type'] );
		$this->assertSame( 'short_tok', $query['access_token'] );
		$this->assertSame( 'tsec_test', $query['client_secret'] );
	}

	public function test_refresh_returns_token_and_expiry(): void {
		$body  = (string) json_encode(
			array(
				'access_token' => 'refreshed_tok',
				'token_type'   => 'bearer',
				'expires_in'   => 5184000,
			)
		);
		$oauth = $this->oauth_with_responses( array( new Response( 200, array(), $body ) ) );

		$result = $oauth->refresh_long_lived_token( 'long_tok' );

		$this->assertSame( 'refreshed_tok', $result['access_token'] );
		$this->assertSame( 5184000, $result['expires_in'] );

		$sent  = $this->history[0]['request'];
		$this->assertSame( 'GET', $sent->getMethod() );
		$this->assertStringContainsString( 'graph.threads.net/refresh_access_token', (string) $sent->getUri() );
		$query = $this->query_of( (string) $sent->getUri() );
		$this->assertSame( 'th_refresh_token', $query['grant_type'] );
		$this->assertSame( 'long_tok', $query['access_token'] );
	}

	public function test_long_lived_throws_rate_limited_on_429(): void {
		$oauth = $this->oauth_with_responses( array( new Response( 429 ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/rate limit/i' );
		$oauth->exchange_for_long_lived_token( 'short_tok' );
	}

	public function test_refresh_throws_http_error_on_5xx(): void {
		$oauth = $this->oauth_with_responses( array( new Response( 503 ) ) );

		try {
			$oauth->refresh_long_lived_token( 'long_tok' );
			$this->fail( 'Expected PublisherException.' );
		} catch ( PublisherException $e ) {
			$this->assertStringContainsString( '503', $e->getMessage() );
		}
	}
}
