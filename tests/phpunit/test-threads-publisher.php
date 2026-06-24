<?php
/**
 * ThreadsPublisher unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\Publishers\PublishRequest;
use ApexChute\ApexCast\Publishers\PublisherException;
use ApexChute\ApexCast\Publishers\ThreadsPublisher;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the Threads publisher.
 *
 * The publish flow is container-create → status poll → publish → permalink, so
 * tests queue ordered MockHandler responses to simulate each leg. The readiness
 * poll's sleep is overridden with a no-op so the timeout test runs instantly.
 */
final class Threads_Publisher_Test extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> Captured outbound requests.
	 */
	private array $history = array();

	/**
	 * No-op sleeper so the poll loop doesn't actually block.
	 *
	 * @return Closure
	 */
	private function no_sleep(): Closure {
		return static function ( int $seconds ): void {
			unset( $seconds );
		};
	}

	/**
	 * Build a ThreadsPublisher whose HTTP client is backed by the given mocked
	 * responses and whose poll never really sleeps.
	 *
	 * @param Response[] $responses    Mocked responses, in call order.
	 * @param string     $access_token Token override.
	 * @param string     $user_id      User id override.
	 * @return ThreadsPublisher
	 */
	private function publisher_with(
		array $responses,
		string $access_token = 'tok_test',
		string $user_id = '17841400000000000'
	): ThreadsPublisher {
		$mock          = new MockHandler( $responses );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$client = new Client( array( 'handler' => $stack ) );
		return new ThreadsPublisher( $access_token, $user_id, 'viciousfun', $client, $this->no_sleep() );
	}

	/**
	 * Build a sample PublishRequest.
	 *
	 * @param string   $content  Content override.
	 * @param string[] $hashtags Hashtags override.
	 * @return PublishRequest
	 */
	private function sample_request( string $content = 'Fresh sofubi just landed.', array $hashtags = array( '#gargamel' ) ): PublishRequest {
		return new PublishRequest(
			42,
			'threads',
			$content,
			$hashtags,
			'https://viciousfun.com/product/gargamel',
			'https://viciousfun.com/wp-content/uploads/gargamel.jpg'
		);
	}

	public function test_get_platform_id_returns_threads(): void {
		$publisher = $this->publisher_with( array() );
		$this->assertSame( 'threads', $publisher->get_platform_id() );
	}

	public function test_is_configured_requires_token_and_user_id(): void {
		$this->assertFalse( $this->publisher_with( array(), '', '123' )->is_configured() );
		$this->assertFalse( $this->publisher_with( array(), 'tok', '' )->is_configured() );
		$this->assertTrue( $this->publisher_with( array(), 'tok', '123' )->is_configured() );
	}

	public function test_test_connection_success(): void {
		$body = (string) json_encode(
			array(
				'id'       => '17841400000000000',
				'username' => 'viciousfun',
			)
		);
		$publisher = $this->publisher_with( array( new Response( 200, array(), $body ) ) );
		$result    = $publisher->test_connection();

		$this->assertTrue( $result->success );
		$this->assertStringContainsString( '@viciousfun', $result->message );
		$this->assertSame( 'viciousfun', $result->details['username'] );
	}

	public function test_test_connection_failure_on_401(): void {
		$publisher = $this->publisher_with( array( new Response( 401 ) ) );
		$result    = $publisher->test_connection();

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'credentials', $result->message );
	}

	public function test_publish_throws_when_unconfigured(): void {
		$publisher = $this->publisher_with( array(), '', '' );
		$this->expectException( PublisherException::class );
		$publisher->publish( $this->sample_request() );
	}

	public function test_publish_happy_path(): void {
		// create → poll(FINISHED) → publish → permalink.
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), (string) json_encode( array( 'id' => 'creation_99' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'status' => 'FINISHED' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'id' => 'media_777' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'permalink' => 'https://www.threads.net/@viciousfun/post/abc' ) ) ),
			)
		);

		$result = $publisher->publish( $this->sample_request() );

		$this->assertTrue( $result->success );
		$this->assertSame( 'media_777', $result->platform_post_id );
		$this->assertSame( 'https://www.threads.net/@viciousfun/post/abc', $result->platform_url );
		$this->assertSame( 'creation_99', $result->context['creation_id'] );

		$this->assertCount( 4, $this->history );

		// Container create payload.
		$create = $this->history[0]['request'];
		$this->assertSame( 'POST', $create->getMethod() );
		$this->assertStringContainsString( '/17841400000000000/threads', (string) $create->getUri() );
		$create_body = (string) $create->getBody();
		$this->assertStringContainsString( 'media_type=IMAGE', $create_body );
		$this->assertStringContainsString( 'image_url=', $create_body );

		// Publish call hits threads_publish with the creation id.
		$publish = $this->history[2]['request'];
		$this->assertStringContainsString( '/17841400000000000/threads_publish', (string) $publish->getUri() );
		$this->assertStringContainsString( 'creation_id=creation_99', (string) $publish->getBody() );
	}

	public function test_publish_polls_until_finished(): void {
		// First poll IN_PROGRESS, second FINISHED.
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), (string) json_encode( array( 'id' => 'creation_1' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'status' => 'IN_PROGRESS' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'status' => 'FINISHED' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'id' => 'media_1' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'permalink' => 'https://threads/p/1' ) ) ),
			)
		);

		$result = $publisher->publish( $this->sample_request() );

		$this->assertTrue( $result->success );
		$this->assertSame( 'media_1', $result->platform_post_id );
		$this->assertCount( 5, $this->history, 'Two polls + create + publish + permalink.' );
	}

	public function test_publish_fails_on_container_error_status(): void {
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), (string) json_encode( array( 'id' => 'creation_err' ) ) ),
				new Response(
					200,
					array(),
					(string) json_encode(
						array(
							'status'        => 'ERROR',
							'error_message' => 'Image could not be fetched.',
						)
					)
				),
			)
		);

		$result = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Image could not be fetched.', $result->error_message );
		// No publish call — only create + one poll.
		$this->assertCount( 2, $this->history );
	}

	public function test_publish_times_out_when_stuck_in_progress(): void {
		// create + 12 IN_PROGRESS polls; never FINISHED.
		$responses = array(
			new Response( 200, array(), (string) json_encode( array( 'id' => 'creation_slow' ) ) ),
		);
		for ( $i = 0; $i < 12; $i++ ) {
			$responses[] = new Response( 200, array(), (string) json_encode( array( 'status' => 'IN_PROGRESS' ) ) );
		}
		$publisher = $this->publisher_with( $responses );

		$result = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'still processing', (string) $result->error_message );
		// 1 create + 12 polls, no publish.
		$this->assertCount( 13, $this->history );
	}

	public function test_publish_returns_failure_on_container_create_401(): void {
		$publisher = $this->publisher_with( array( new Response( 401 ) ) );
		$result    = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'credentials', (string) $result->error_message );
		$this->assertCount( 1, $this->history );
	}

	public function test_publish_succeeds_even_when_permalink_fetch_fails(): void {
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), (string) json_encode( array( 'id' => 'creation_2' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'status' => 'FINISHED' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'id' => 'media_2' ) ) ),
				new Response( 500 ),
			)
		);

		$result = $publisher->publish( $this->sample_request() );

		$this->assertTrue( $result->success );
		$this->assertSame( 'media_2', $result->platform_post_id );
		$this->assertSame( '', $result->platform_url );
	}

	public function test_publish_truncates_text_to_500_chars(): void {
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), (string) json_encode( array( 'id' => 'creation_3' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'status' => 'FINISHED' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'id' => 'media_3' ) ) ),
				new Response( 200, array(), (string) json_encode( array( 'permalink' => 'https://threads/p/3' ) ) ),
			)
		);

		$long = str_repeat( 'a', 600 );
		$publisher->publish( $this->sample_request( $long, array() ) );

		// Parse the urlencoded form body to read the `text` field.
		$create_body = (string) $this->history[0]['request']->getBody();
		$fields      = array();
		parse_str( $create_body, $fields );
		$this->assertLessThanOrEqual( 500, mb_strlen( (string) $fields['text'] ) );
	}
}
