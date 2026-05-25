<?php
/**
 * PinterestPublisher unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\Publishers\PinterestBoardService;
use ApexChute\ApexCast\Publishers\PinterestPublisher;
use ApexChute\ApexCast\Publishers\PublishRequest;
use ApexChute\ApexCast\Publishers\PublisherException;
use Closure;
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
 * truncation), missing-config branches, and the Phase 9 tag-routing rules:
 * explicit override → tag map → auto-create → default board.
 */
final class Pinterest_Publisher_Test extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> History of outbound requests, captured by Middleware::history.
	 */
	private array $history = array();

	/**
	 * Build a Pinterest publisher with the given default board id and a Guzzle
	 * client backed by the supplied mocked responses. Records every request to
	 * `$this->history`.
	 *
	 * @param Response[]             $responses        Mocked responses, in call order.
	 * @param string                 $access_token     Optional access token override.
	 * @param string                 $default_board_id Optional default board id override.
	 * @param array<string, string>  $tag_board_map    Optional tag → board mapping.
	 * @param array<string, bool>    $tag_auto_create  Optional tag → auto-create flag.
	 * @param PinterestBoardService|null $board_service Optional board service.
	 * @param Closure|null           $on_auto_create   Optional auto-create callback.
	 * @return PinterestPublisher
	 */
	private function publisher_with_responses(
		array $responses,
		string $access_token = 'pp_test_token',
		string $default_board_id = 'board_42',
		array $tag_board_map = array(),
		array $tag_auto_create = array(),
		?PinterestBoardService $board_service = null,
		?Closure $on_auto_create = null
	): PinterestPublisher {
		$mock          = new MockHandler( $responses );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );
		return new PinterestPublisher(
			$default_board_id,
			$tag_board_map,
			$tag_auto_create,
			$board_service,
			$on_auto_create,
			$access_token,
			'production',
			$client
		);
	}

	/**
	 * Build a sample PublishRequest for use in publish() tests.
	 *
	 * @param string               $content         Optional content override.
	 * @param string[]             $hashtags        Optional hashtag list override.
	 * @param array<string, mixed> $platform_options Optional platform_options override.
	 * @return PublishRequest
	 */
	private function sample_request(
		string $content = 'Apex Chute 3.0 — built for go-pro skydivers.',
		array $hashtags = array( '#skydive', '#apexchute' ),
		array $platform_options = array()
	): PublishRequest {
		return new PublishRequest(
			42,
			'pinterest',
			$content,
			$hashtags,
			'https://apexchute.com/product/apex-chute-3-0',
			'https://apexchute.com/wp-content/uploads/apex.jpg',
			null,
			$platform_options
		);
	}

	public function test_get_platform_id_returns_pinterest(): void {
		$publisher = $this->publisher_with_responses( array() );
		$this->assertSame( 'pinterest', $publisher->get_platform_id() );
	}

	public function test_is_configured_requires_token_and_some_destination(): void {
		// No token at all.
		$this->assertFalse( $this->publisher_with_responses( array(), '', 'board_1' )->is_configured() );
		// Token but no default board AND no tag map.
		$this->assertFalse( $this->publisher_with_responses( array(), 'tok', '' )->is_configured() );
		// Token + default board.
		$this->assertTrue( $this->publisher_with_responses( array(), 'tok', 'board_1' )->is_configured() );
		// Token + tag map (no default).
		$this->assertTrue(
			$this->publisher_with_responses( array(), 'tok', '', array( 'gargamel' => 'b1' ) )->is_configured()
		);
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

	/* ----------------------------------------------------------------- *
	 * Phase 9: tag routing + override resolution.
	 * ----------------------------------------------------------------- */

	public function test_publish_uses_tag_mapping_when_slug_matches(): void {
		$body = (string) json_encode( array( 'id' => 'pin_X' ) );
		$publisher = $this->publisher_with_responses(
			array( new Response( 201, array(), $body ) ),
			'tok',
			'default_board',
			array( 'gargamel' => 'board_gargamel' )
		);

		$publisher->publish(
			$this->sample_request( 'X', array(), array( 'tag_slugs' => array( 'gargamel' ) ) )
		);

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertSame( 'board_gargamel', $payload['board_id'] );
	}

	public function test_first_matching_tag_wins_when_multiple_tags_mapped(): void {
		$body = (string) json_encode( array( 'id' => 'pin_X' ) );
		$publisher = $this->publisher_with_responses(
			array( new Response( 201, array(), $body ) ),
			'tok',
			'default_board',
			array(
				'gargamel'  => 'board_gargamel',
				'shirahama' => 'board_shirahama',
			)
		);

		// gargamel appears first in tag_slugs — it wins.
		$publisher->publish(
			$this->sample_request( 'X', array(), array( 'tag_slugs' => array( 'gargamel', 'shirahama' ) ) )
		);

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertSame( 'board_gargamel', $payload['board_id'] );
	}

	public function test_no_matching_tags_falls_back_to_default_board(): void {
		$body = (string) json_encode( array( 'id' => 'pin_X' ) );
		$publisher = $this->publisher_with_responses(
			array( new Response( 201, array(), $body ) ),
			'tok',
			'default_board',
			array( 'gargamel' => 'board_gargamel' )
		);

		$publisher->publish(
			$this->sample_request( 'X', array(), array( 'tag_slugs' => array( 'unrelated-tag' ) ) )
		);

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertSame( 'default_board', $payload['board_id'] );
	}

	public function test_no_matching_tags_and_no_default_returns_failure(): void {
		// is_configured() requires *some* destination, so we set a mapping
		// but route tag_slugs that don't match it; default is empty.
		$publisher = $this->publisher_with_responses(
			array(),
			'tok',
			'',
			array( 'gargamel' => 'board_gargamel' )
		);

		$result = $publisher->publish(
			$this->sample_request( 'X', array(), array( 'tag_slugs' => array( 'unmapped' ) ) )
		);

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'No destination board', (string) $result->error_message );
		$this->assertCount( 0, $this->history, 'Should not have hit Pinterest when no board resolves.' );
	}

	public function test_override_wins_over_tag_mapping(): void {
		$body = (string) json_encode( array( 'id' => 'pin_X' ) );
		$publisher = $this->publisher_with_responses(
			array( new Response( 201, array(), $body ) ),
			'tok',
			'default_board',
			array( 'gargamel' => 'board_gargamel' )
		);

		$publisher->publish(
			$this->sample_request(
				'X',
				array(),
				array(
					'board_id_override' => 'board_OVERRIDE',
					'tag_slugs'         => array( 'gargamel' ),
				)
			)
		);

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertSame( 'board_OVERRIDE', $payload['board_id'] );
	}

	public function test_override_wins_over_default_board(): void {
		$body = (string) json_encode( array( 'id' => 'pin_X' ) );
		$publisher = $this->publisher_with_responses(
			array( new Response( 201, array(), $body ) ),
			'tok',
			'default_board'
		);

		$publisher->publish(
			$this->sample_request(
				'X',
				array(),
				array( 'board_id_override' => 'board_OVERRIDE' )
			)
		);

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertSame( 'board_OVERRIDE', $payload['board_id'] );
	}

	public function test_empty_override_falls_through_to_tag_mapping(): void {
		$body = (string) json_encode( array( 'id' => 'pin_X' ) );
		$publisher = $this->publisher_with_responses(
			array( new Response( 201, array(), $body ) ),
			'tok',
			'default_board',
			array( 'gargamel' => 'board_gargamel' )
		);

		$publisher->publish(
			$this->sample_request(
				'X',
				array(),
				array(
					'board_id_override' => '',
					'tag_slugs'         => array( 'gargamel' ),
				)
			)
		);

		$payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertSame( 'board_gargamel', $payload['board_id'] );
	}

	public function test_auto_create_creates_board_and_fires_callback(): void {
		// Two mocked responses: 1) board create, 2) pin create.
		$board_create_body = (string) json_encode( array( 'id' => 'new_board_id' ) );
		$pin_body          = (string) json_encode( array( 'id' => 'pin_X' ) );

		$mock          = new MockHandler( array( new Response( 201, array(), $board_create_body ), new Response( 201, array(), $pin_body ) ) );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );

		$service = new PinterestBoardService( 'tok', 'production', $client );

		$captured = array();
		$on_auto  = function ( string $slug, string $new_id ) use ( &$captured ): void {
			$captured[] = array( $slug, $new_id );
		};

		$publisher = new PinterestPublisher(
			'default_board',
			array(),
			array( 'gargamel' => true ),
			$service,
			$on_auto,
			'tok',
			'production',
			$client
		);

		$publisher->publish(
			$this->sample_request( 'X', array(), array( 'tag_slugs' => array( 'gargamel' ) ) )
		);

		$this->assertCount( 2, $this->history );
		$create_payload = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertSame( 'Gargamel', $create_payload['name'] );
		$this->assertSame( 'PUBLIC', $create_payload['privacy'] );

		$pin_payload = json_decode( (string) $this->history[1]['request']->getBody(), true );
		$this->assertSame( 'new_board_id', $pin_payload['board_id'] );

		$this->assertSame( array( array( 'gargamel', 'new_board_id' ) ), $captured );
	}

	public function test_humanize_slug_capitalizes_words(): void {
		$publisher = $this->publisher_with_responses( array(), 'tok', 'board_42' );
		$this->assertSame( 'Anraku Ansaku', $publisher->humanize_slug( 'anraku-ansaku' ) );
		$this->assertSame( 'Gargamel', $publisher->humanize_slug( 'gargamel' ) );
	}

	public function test_sandbox_mode_uses_sandbox_host(): void {
		$body          = (string) json_encode( array( 'id' => 'pin_S' ) );
		$mock          = new MockHandler( array( new Response( 201, array(), $body ) ) );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );

		$publisher = new PinterestPublisher(
			'board_42',
			array(),
			array(),
			null,
			null,
			'tok',
			'sandbox',
			$client
		);

		$result = $publisher->publish( $this->sample_request() );
		$this->assertTrue( $result->success );

		$sent = $this->history[0]['request'];
		$this->assertStringContainsString(
			'api-sandbox.pinterest.com',
			(string) $sent->getUri()
		);
	}
}
