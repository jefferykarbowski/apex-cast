<?php
/**
 * PinterestOAuth unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\OAuth\PinterestOAuth;
use ApexChute\ApexCast\Publishers\PublisherException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Pinterest OAuth client: auth-URL assembly and token exchange.
 *
 * Pure HTTP work — Guzzle MockHandler injection, no WordPress dependencies.
 */
final class Pinterest_OAuth_Test extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> Captured outbound requests.
	 */
	private array $history = array();

	/**
	 * Build a PinterestOAuth instance whose HTTP client is backed by the given
	 * mocked responses and whose outbound requests are recorded.
	 *
	 * @param Response[] $responses     Mocked responses in call order.
	 * @param string     $client_id     Optional client id override.
	 * @param string     $client_secret Optional client secret override.
	 * @return PinterestOAuth
	 */
	private function oauth_with_responses( array $responses, string $client_id = 'cid_test', string $client_secret = 'csec_test' ): PinterestOAuth {
		$mock          = new MockHandler( $responses );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );
		return new PinterestOAuth( $client_id, $client_secret, $client );
	}

	public function test_is_configured_requires_both_id_and_secret(): void {
		$this->assertFalse( $this->oauth_with_responses( array(), '', 'csec' )->is_configured() );
		$this->assertFalse( $this->oauth_with_responses( array(), 'cid', '' )->is_configured() );
		$this->assertTrue( $this->oauth_with_responses( array(), 'cid', 'csec' )->is_configured() );
	}

	public function test_build_auth_url_includes_all_required_params(): void {
		$oauth = $this->oauth_with_responses( array() );
		$url   = $oauth->build_auth_url( 'https://example.com/callback', 'STATE123' );

		$this->assertStringStartsWith( 'https://www.pinterest.com/oauth/?', $url );

		$parts = array();
		parse_str( (string) wp_parse_url_query_safe( $url ), $parts );
		$this->assertSame( 'cid_test', $parts['client_id'] );
		$this->assertSame( 'https://example.com/callback', $parts['redirect_uri'] );
		$this->assertSame( 'code', $parts['response_type'] );
		$this->assertSame( 'STATE123', $parts['state'] );
		$this->assertSame( 'boards:read,pins:write,user_accounts:read', $parts['scope'] );
	}

	public function test_exchange_code_returns_tokens_on_success(): void {
		$body  = (string) json_encode(
			array(
				'access_token'  => 'pina_AT',
				'refresh_token' => 'pinr_RT',
				'expires_in'    => 2592000,
				'scope'         => 'boards:read,pins:write',
				'token_type'    => 'bearer',
			)
		);
		$oauth = $this->oauth_with_responses( array( new Response( 200, array(), $body ) ) );

		$tokens = $oauth->exchange_code( 'CODE_X', 'https://example.com/callback' );

		$this->assertSame( 'pina_AT', $tokens['access_token'] );
		$this->assertSame( 'pinr_RT', $tokens['refresh_token'] );
		$this->assertSame( 2592000, $tokens['expires_in'] );
		$this->assertSame( 'boards:read,pins:write', $tokens['scope'] );
	}

	public function test_exchange_code_sends_basic_auth_and_form_body(): void {
		$body  = (string) json_encode( array( 'access_token' => 'tok' ) );
		$oauth = $this->oauth_with_responses( array( new Response( 200, array(), $body ) ) );

		$oauth->exchange_code( 'C', 'https://example.com/cb' );

		$sent = $this->history[0]['request'];
		$this->assertSame( 'POST', $sent->getMethod() );
		$this->assertStringContainsString( '/v5/oauth/token', (string) $sent->getUri() );

		$auth_header = $sent->getHeaderLine( 'Authorization' );
		$this->assertStringStartsWith( 'Basic ', $auth_header );
		// Decoded auth header should be `cid_test:csec_test`.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding for test assertion.
		$decoded = base64_decode( substr( $auth_header, 6 ), true );
		$this->assertSame( 'cid_test:csec_test', $decoded );

		$form_body = (string) $sent->getBody();
		$this->assertStringContainsString( 'grant_type=authorization_code', $form_body );
		$this->assertStringContainsString( 'code=C', $form_body );
		$this->assertStringContainsString( 'redirect_uri=', $form_body );
	}

	public function test_exchange_code_throws_auth_failed_on_401(): void {
		$oauth = $this->oauth_with_responses( array( new Response( 401 ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/credentials/i' );
		$oauth->exchange_code( 'C', 'https://example.com/cb' );
	}

	public function test_exchange_code_throws_rate_limited_on_429(): void {
		$oauth = $this->oauth_with_responses( array( new Response( 429 ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/rate limit/i' );
		$oauth->exchange_code( 'C', 'https://example.com/cb' );
	}

	public function test_exchange_code_throws_http_error_on_5xx(): void {
		$oauth = $this->oauth_with_responses( array( new Response( 503 ) ) );

		try {
			$oauth->exchange_code( 'C', 'https://example.com/cb' );
			$this->fail( 'Expected PublisherException.' );
		} catch ( PublisherException $e ) {
			$this->assertStringContainsString( '503', $e->getMessage() );
		}
	}

	public function test_exchange_code_throws_malformed_on_missing_access_token(): void {
		$body  = (string) json_encode( array( 'wrong_field' => 'oops' ) );
		$oauth = $this->oauth_with_responses( array( new Response( 200, array(), $body ) ) );

		$this->expectException( PublisherException::class );
		$this->expectExceptionMessageMatches( '/access_token/i' );
		$oauth->exchange_code( 'C', 'https://example.com/cb' );
	}

	public function test_exchange_code_throws_malformed_on_invalid_json(): void {
		$oauth = $this->oauth_with_responses( array( new Response( 200, array(), 'not json' ) ) );

		$this->expectException( PublisherException::class );
		$oauth->exchange_code( 'C', 'https://example.com/cb' );
	}
}

// PHPUnit-only helper: there's no `wp_parse_url_query_safe`; the test uses it
// only to keep the assertion shape obvious. Define a shim that just splits the
// query string out of a URL.
if ( ! function_exists( __NAMESPACE__ . '\\wp_parse_url_query_safe' ) ) {
	/**
	 * Test helper: returns the query string portion of a URL.
	 *
	 * @param string $url Full URL.
	 * @return string Query string portion, or empty when none is present.
	 */
	function wp_parse_url_query_safe( string $url ): string {
		$parts = parse_url( $url );
		return is_array( $parts ) && isset( $parts['query'] ) ? (string) $parts['query'] : '';
	}
}
