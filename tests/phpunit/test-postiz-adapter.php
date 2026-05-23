<?php
/**
 * PostizAdapter unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\Adapters\BackendAdapterException;
use ApexChute\ApexCast\Adapters\PostPayload;
use ApexChute\ApexCast\Adapters\PostStatus;
use ApexChute\ApexCast\Adapters\PostizAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the Postiz backend adapter.
 *
 * Mocks Guzzle to verify integration parsing, the multi-platform `posts` array
 * shape, and HTTP error mapping. Rate-limit accounting uses the in-memory
 * transient stubs in `tests/phpunit/bootstrap.php`.
 */
final class Postiz_Adapter_Test extends TestCase {

	/**
	 * Reset the in-memory transient store between tests so the rate-limit
	 * counter from one test doesn't leak into another.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__apex_cast_test_transients'] = array();
	}

	/**
	 * @var array<int, Request> Recorded outbound requests, populated by Guzzle History middleware.
	 */
	private array $history = array();

	/**
	 * Build an adapter whose HTTP client is backed by the given mocked responses.
	 *
	 * @param Response[] $responses Mocked responses, in call order.
	 * @return PostizAdapter
	 */
	private function adapter_with_responses( array $responses ): PostizAdapter {
		$mock  = new MockHandler( $responses );
		$stack = HandlerStack::create( $mock );
		// History middleware: capture every outbound request for assertions.
		$this->history = array();
		$stack->push(
			\GuzzleHttp\Middleware::history( $this->history )
		);
		$client = new Client( array( 'handler' => $stack ) );
		return new PostizAdapter( 'test-postiz-key', 'https://api.postiz.test/public/v1', $client );
	}

	public function test_get_adapter_id_returns_postiz(): void {
		$adapter = $this->adapter_with_responses( array() );
		$this->assertSame( 'postiz', $adapter->get_adapter_id() );
	}

	public function test_fetch_integrations_parses_top_level_array_shape(): void {
		$body    = (string) json_encode(
			array(
				array(
					'id'         => 'int_1',
					'name'       => 'Apex on FB',
					'identifier' => 'facebook',
					'picture'    => 'https://example.com/p.png',
				),
				array(
					'id'         => 'int_2',
					'name'       => 'Apex on IG',
					'identifier' => 'instagram',
				),
			)
		);
		$adapter = $this->adapter_with_responses( array( new Response( 200, array(), $body ) ) );

		$list = $adapter->fetch_integrations();

		$this->assertCount( 2, $list );
		$this->assertSame( 'int_1', $list[0]->id );
		$this->assertSame( 'facebook', $list[0]->platform );
		$this->assertSame( '', $list[1]->picture );
	}

	public function test_fetch_integrations_parses_wrapped_shape(): void {
		$body    = (string) json_encode(
			array(
				'integrations' => array(
					array(
						'id'       => 'int_99',
						'name'     => 'On Threads',
						'platform' => 'threads',
					),
				),
			)
		);
		$adapter = $this->adapter_with_responses( array( new Response( 200, array(), $body ) ) );

		$list = $adapter->fetch_integrations();

		$this->assertCount( 1, $list );
		$this->assertSame( 'threads', $list[0]->platform );
	}

	public function test_401_maps_to_auth_failed_exception(): void {
		$adapter = $this->adapter_with_responses( array( new Response( 401 ) ) );

		$this->expectException( BackendAdapterException::class );
		$this->expectExceptionMessageMatches( '/API key/i' );
		$adapter->fetch_integrations();
	}

	public function test_429_maps_to_rate_limited(): void {
		$adapter = $this->adapter_with_responses( array( new Response( 429 ) ) );

		$this->expectException( BackendAdapterException::class );
		$this->expectExceptionMessageMatches( '/rate limit/i' );
		$adapter->fetch_integrations();
	}

	public function test_test_connection_reports_integration_count_on_success(): void {
		$body    = (string) json_encode(
			array(
				array( 'id' => 'a' ),
				array( 'id' => 'b' ),
				array( 'id' => 'c' ),
			)
		);
		$adapter = $this->adapter_with_responses( array( new Response( 200, array(), $body ) ) );

		$result = $adapter->test_connection();

		$this->assertTrue( $result->success );
		$this->assertSame( 3, $result->details['integration_count'] ?? -1 );
	}

	public function test_test_connection_reports_failure_on_4xx(): void {
		$adapter = $this->adapter_with_responses( array( new Response( 403 ) ) );
		$result  = $adapter->test_connection();
		$this->assertFalse( $result->success );
	}

	public function test_queue_post_sends_expected_body_shape(): void {
		$response_body = (string) json_encode( array( 'id' => 'grp_42' ) );
		$adapter       = $this->adapter_with_responses( array( new Response( 200, array(), $response_body ) ) );

		$payload = new PostPayload(
			42,
			array( 'facebook', 'instagram' ),
			array(
				'facebook'  => array( 'content' => 'FB copy' ),
				'instagram' => array( 'content' => 'IG copy' ),
			),
			array(
				'facebook'  => 'int_fb',
				'instagram' => 'int_ig',
			),
			array(),
			PostPayload::TYPE_DRAFT
		);

		$result = $adapter->queue_post( $payload );

		$this->assertSame( 'grp_42', $result->backend_post_id );
		$this->assertSame( PostStatus::STATUS_QUEUED, $result->status );

		// Inspect the captured request body.
		$this->assertCount( 1, $this->history );
		$sent = $this->history[0]['request'];
		$this->assertSame( 'POST', $sent->getMethod() );
		$this->assertStringContainsString( '/posts', (string) $sent->getUri() );
		$this->assertSame( 'test-postiz-key', $sent->getHeaderLine( 'Authorization' ) );

		$body = json_decode( (string) $sent->getBody(), true );
		$this->assertIsArray( $body );
		$this->assertSame( PostPayload::TYPE_DRAFT, $body['type'] );
		$this->assertCount( 2, $body['posts'] );
		$this->assertSame( 'int_fb', $body['posts'][0]['integration']['id'] );
		$this->assertSame( 'facebook', $body['posts'][0]['settings']['__type'] );
		$this->assertSame( 'FB copy', $body['posts'][0]['value'][0]['content'] );
	}

	public function test_queue_post_skips_platforms_with_no_integration_mapping(): void {
		$response_body = (string) json_encode( array( 'id' => 'grp_1' ) );
		$adapter       = $this->adapter_with_responses( array( new Response( 200, array(), $response_body ) ) );

		$payload = new PostPayload(
			42,
			array( 'facebook', 'instagram' ),
			array(
				'facebook'  => array( 'content' => 'FB copy' ),
				'instagram' => array( 'content' => 'IG copy' ),
			),
			array( 'facebook' => 'int_fb' ), // Only facebook is mapped.
			array(),
			PostPayload::TYPE_DRAFT
		);

		$adapter->queue_post( $payload );

		$body = json_decode( (string) $this->history[0]['request']->getBody(), true );
		$this->assertCount( 1, $body['posts'] );
		$this->assertSame( 'facebook', $body['posts'][0]['settings']['__type'] );
	}

	public function test_queue_post_increments_rate_counter(): void {
		$response_body = (string) json_encode( array( 'id' => 'grp_x' ) );
		$adapter       = $this->adapter_with_responses(
			array(
				new Response( 200, array(), $response_body ),
				new Response( 200, array(), $response_body ),
			)
		);

		$payload = new PostPayload(
			1,
			array( 'facebook' ),
			array( 'facebook' => array( 'content' => 'hi' ) ),
			array( 'facebook' => 'int_fb' )
		);

		$adapter->queue_post( $payload );
		$adapter->queue_post( $payload );

		$this->assertSame( 2, $GLOBALS['__apex_cast_test_transients']['apex_cast_postiz_rate'] ?? 0 );
	}

	public function test_get_post_status_returns_status_and_platform_results(): void {
		$body    = (string) json_encode(
			array(
				'status' => 'sent',
				'posts'  => array(
					'facebook' => array( 'status' => 'sent' ),
				),
			)
		);
		$adapter = $this->adapter_with_responses( array( new Response( 200, array(), $body ) ) );

		$status = $adapter->get_post_status( 'grp_1' );

		$this->assertSame( 'sent', $status->status );
		$this->assertSame( array( 'facebook' => array( 'status' => 'sent' ) ), $status->platform_results );
	}

	public function test_malformed_response_body_raises_malformed_exception(): void {
		$adapter = $this->adapter_with_responses( array( new Response( 200, array(), 'definitely not json' ) ) );

		$this->expectException( BackendAdapterException::class );
		$this->expectExceptionMessageMatches( '/JSON/i' );
		$adapter->fetch_integrations();
	}
}
