<?php
/**
 * PinterestPublisher unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\Publishers\PinterestPublisher;
use ApexChute\ApexCast\Publishers\PublishRequest;
use ApexChute\ApexCast\Publishers\PublisherException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the Pinterest publisher.
 *
 * Every test injects a Guzzle MockHandler — no real network. Verifies the
 * publisher's contract for is_configured, test_connection, and publish across
 * happy path, error mapping (401/429/5xx), description-building (hashtags +
 * truncation), and missing-config branches.
 */
final class Pinterest_Publisher_Test extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> History of outbound requests, captured by Middleware::history.
	 */
	private array $history = array();

	/**
	 * Build a Pinterest publisher with the given access token/board id and a
	 * Guzzle client backed by the supplied mocked responses. Records every
	 * request to $this->history.
	 *
	 * @param Response[] $responses    Mocked responses, in call order.
	 * @param string     $access_token Optional access token override.
	 * @param string     $board_id     Optional board id override.
	 * @return PinterestPublisher
	 */
	private function publisher_with_responses( array $responses, string $access_token = 'pp_test_token', string $board_id = 'board_42' ): PinterestPublisher {
		$mock = new MockHandler( $responses );
		$stack = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );
		return new PinterestPublisher( $access_token, $board_id, $client );
	}

	/**
	 * Build a sample PublishRequest for use in publish() tests.
	 *
	 * @param string   $content  Optional content override.
	 * @param string[] $hashtags Optional hashtag list override.
	 * @return PublishRequest
	 */
	private function sample_request( string $content = 'Apex Chute 3.0 — built for go-pro skydivers.', array $hashtags = array( '#skydive', '#apexchute' ) ): PublishRequest {
		return new PublishRequest(
			42,
			'pinterest',
			$content,
			$hashtags,
			'https://apexchute.com/product/apex-chute-3-0',
			'https://apexchute.com/wp-content/uploads/apex.jpg'
		);
	}

	public function test_get_platform_id_returns_pinterest(): void {
		$publisher = $this->publisher_with_responses( array() );
		$this->assertSame( 'pinterest', $publisher->get_platform_id() );
	}

	public function test_is_configured_requires_both_token_and_board(): void {
		$this->assertFalse( $this->publisher_with_responses( array(), '', 'board_1' )->is_configured() );
		$this->assertFalse( $this->publisher_with_responses( array(), 'tok', '' )->is_configured() );
		$this->assertTrue( $this->publisher_with_responses( array(), 'tok', 'board_1' )->is_configured() );
	}

	public function test_test_connection_returns_failure_when_no_token(): void {
		$publisher = $this->publisher_with_responses( array(), '', '' );
		$result    = $publisher->test_connection();
		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'access token', $result->message );
		$this->assertCount( 0, $this->history, 'No HTTP calls should have been made.' );
	}

	public function test_test_connection_returns_success_with_username(): void {
		$body = (string) json_encode(
			array(
				'username'     => 'iamviciousfun',
				'account_type' => 'BUSINESS',
				'id'           => 'user_1',
			)
		);
		$publisher = $this->publisher_with_responses( array( new Response( 200, array(), $body ) ) );

		$result = $publisher->test_connection();

		$this->assertTrue( $result->success );
		$this->assertStringContainsString( 'iamviciousfun', $result->message );
		$this->assertSame( 'iamviciousfun', $result->details['username'] );
		$this->assertSame( 'BUSINESS', $result->details['account_type'] );

		$sent = $this->history[0]['request'];
		$this->assertSame( 'GET', $sent->getMethod() );
		$this->assertStringContainsString( '/user_account', (string) $sent->getUri() );
		$this->assertSame( 'Bearer pp_test_token', $sent->getHeaderLine( 'Authorization' ) );
	}

	public function test_test_connection_returns_failure_on_401(): void {
		$publisher = $this->publisher_with_responses( array( new Response( 401 ) ) );
		$result    = $publisher->test_connection();
		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'credentials', $result->message );
	}

	public function test_publish_throws_when_unconfigured(): void {
		$publisher = $this->publisher_with_responses( array(), '', '' );
		$this->expectException( PublisherException::class );
		$publisher->publish( $this->sample_request() );
	}

	public function test_publish_sends_expected_body_shape(): void {
		$body = (string) json_encode( array( 'id' => 'pin_9001' ) );
		$publisher = $this->publisher_with_responses( array( new Response( 201, array(), $body ) ) );

		$result = $publisher->publish( $this->sample_request() );

		$this->assertTrue( $result->success );
		$this->assertSame( 'pin_9001', $result->platform_post_id );
		$this->assertSame( 'https://www.pinterest.com/pin/pin_9001/', $result->platform_url );

		$sent = $this->history[0]['request'];
		$this->assertSame( 'POST', $sent->getMethod() );
		$this->assertStringContainsString( '/pins', (string) $sent->getUri() );

		$payload = json_decode( (string) $sent->getBody(), true );
		$this->assertIsArray( $payload );
		$this->assertSame( 'board_42', $payload['board_id'] );
		$this->assertSame( 'https://apexchute.com/product/apex-chute-3-0', $payload['link'] );
		$this->assertSame( 'image_url', $payload['media_source']['source_type'] );
		$this->assertSame( 'https://apexchute.com/wp-content/uploads/apex.jpg', $payload['media_source']['url'] );
	}

	public function test_publish_appends_hashtags_to_description(): void {
		$body = (string) json_encode( array( 'id' => 'pin_1' ) );
		$publisher = $this->publisher_with_responses( array( new Response( 201, array(), $body ) ) );

		$publisher->publish( $this->sample_request( 'Body copy.', array( '#a', '#b' ) ) );

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertStringContainsString( 'Body copy.', $payload['description'] );
		$this->assertStringContainsString( '#a #b', $payload['description'] );
	}

	public function test_publish_truncates_description_at_800_chars(): void {
		$body = (string) json_encode( array( 'id' => 'pin_1' ) );
		$publisher = $this->publisher_with_responses( array( new Response( 201, array(), $body ) ) );

		$long_content = str_repeat( 'A', 1000 );
		$publisher->publish( $this->sample_request( $long_content, array() ) );

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertLessThanOrEqual( 800, mb_strlen( $payload['description'] ) );
	}

	public function test_publish_truncates_alt_text_at_500_chars(): void {
		$body = (string) json_encode( array( 'id' => 'pin_1' ) );
		$publisher = $this->publisher_with_responses( array( new Response( 201, array(), $body ) ) );

		$long_content = str_repeat( 'B', 1000 );
		$publisher->publish( $this->sample_request( $long_content, array() ) );

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertLessThanOrEqual( 500, mb_strlen( $payload['alt_text'] ) );
	}

	public function test_publish_returns_failure_on_401(): void {
		$publisher = $this->publisher_with_responses( array( new Response( 401 ) ) );
		$result    = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'credentials', (string) $result->error_message );
	}

	public function test_publish_returns_failure_on_429(): void {
		$publisher = $this->publisher_with_responses( array( new Response( 429 ) ) );
		$result    = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'rate limit', strtolower( (string) $result->error_message ) );
	}

	public function test_publish_returns_failure_on_5xx_with_status(): void {
		$publisher = $this->publisher_with_responses( array( new Response( 503 ) ) );
		$result    = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( '503', (string) $result->error_message );
	}

	public function test_publish_returns_failure_when_response_missing_id(): void {
		$body = (string) json_encode( array( 'wrong_field' => 'oops' ) );
		$publisher = $this->publisher_with_responses( array( new Response( 201, array(), $body ) ) );

		$result = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'pin id', (string) $result->error_message );
	}

	public function test_publish_returns_failure_on_malformed_json(): void {
		$publisher = $this->publisher_with_responses( array( new Response( 201, array(), 'not json' ) ) );
		$result    = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'JSON', (string) $result->error_message );
	}
}
