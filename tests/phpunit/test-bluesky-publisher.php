<?php
/**
 * BlueskyPublisher unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\Publishers\BlueskyClient;
use ApexChute\ApexCast\Publishers\BlueskyPublisher;
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
 * Behavioural tests for the Bluesky publisher.
 *
 * The publisher's AT Protocol calls go through a real BlueskyClient backed by a
 * Guzzle MockHandler, so we can both control responses and inspect the exact
 * createRecord payload. The image fetch is stubbed via the injected closure.
 */
final class Bluesky_Publisher_Test extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> Captured outbound requests.
	 */
	private array $history = array();

	/**
	 * Build a publisher whose AT Protocol client is backed by the given mocked
	 * responses and whose image fetch is the supplied closure.
	 *
	 * @param Response[]   $responses     Mocked responses, in call order.
	 * @param Closure|null $image_fetcher Image fetcher stub.
	 * @param string       $handle        Handle override.
	 * @param string       $app_password  App password override.
	 * @return BlueskyPublisher
	 */
	private function publisher_with(
		array $responses,
		?Closure $image_fetcher = null,
		string $handle = 'viciousfun.bsky.social',
		string $app_password = 'app-pass'
	): BlueskyPublisher {
		$mock          = new MockHandler( $responses );
		$stack         = HandlerStack::create( $mock );
		$this->history = array();
		$stack->push( Middleware::history( $this->history ) );
		$guzzle = new Client( array( 'handler' => $stack ) );
		$client = new BlueskyClient( $guzzle );
		return new BlueskyPublisher( $handle, $app_password, $client, $image_fetcher );
	}

	/**
	 * JSON body for a successful createSession.
	 *
	 * @return string
	 */
	private function session_body(): string {
		return (string) json_encode(
			array(
				'accessJwt'  => 'access_jwt_X',
				'refreshJwt' => 'refresh_jwt_X',
				'did'        => 'did:plc:abc123',
				'handle'     => 'viciousfun.bsky.social',
			)
		);
	}

	/**
	 * JSON body for a successful uploadBlob.
	 *
	 * @return string
	 */
	private function blob_body(): string {
		return (string) json_encode(
			array(
				'blob' => array(
					'$type'    => 'blob',
					'ref'      => array( '$link' => 'bafyrefX' ),
					'mimeType' => 'image/jpeg',
					'size'     => 1234,
				),
			)
		);
	}

	/**
	 * JSON body for a successful createRecord.
	 *
	 * @return string
	 */
	private function record_body(): string {
		return (string) json_encode(
			array(
				'uri' => 'at://did:plc:abc123/app.bsky.feed.post/3kabc',
				'cid' => 'bafyCID',
			)
		);
	}

	/**
	 * Build a sample PublishRequest.
	 *
	 * @param string               $content          Content override.
	 * @param string[]             $hashtags         Hashtags override.
	 * @param array<string, mixed> $platform_options platform_options override.
	 * @return PublishRequest
	 */
	private function sample_request(
		string $content = 'Fresh sofubi just landed.',
		array $hashtags = array( '#gargamel', '#sofubi' ),
		array $platform_options = array()
	): PublishRequest {
		return new PublishRequest(
			42,
			'bluesky',
			$content,
			$hashtags,
			'https://viciousfun.com/product/gargamel',
			'https://viciousfun.com/wp-content/uploads/gargamel.jpg',
			null,
			$platform_options
		);
	}

	/**
	 * Image fetcher stub returning fixed bytes/mime.
	 *
	 * @param string $bytes Bytes to return.
	 * @param string $mime  MIME to return.
	 * @return Closure
	 */
	private function fetcher_returning( string $bytes, string $mime = 'image/jpeg' ): Closure {
		return static function ( string $url ) use ( $bytes, $mime ): ?array {
			unset( $url );
			return array(
				'bytes' => $bytes,
				'mime'  => $mime,
			);
		};
	}

	public function test_get_platform_id_returns_bluesky(): void {
		$publisher = $this->publisher_with( array() );
		$this->assertSame( 'bluesky', $publisher->get_platform_id() );
	}

	public function test_is_configured_requires_handle_and_password(): void {
		$this->assertFalse( $this->publisher_with( array(), null, '', 'p' )->is_configured() );
		$this->assertFalse( $this->publisher_with( array(), null, 'h', '' )->is_configured() );
		$this->assertTrue( $this->publisher_with( array(), null, 'h', 'p' )->is_configured() );
	}

	public function test_test_connection_success(): void {
		$publisher = $this->publisher_with( array( new Response( 200, array(), $this->session_body() ) ) );
		$result    = $publisher->test_connection();

		$this->assertTrue( $result->success );
		$this->assertStringContainsString( '@viciousfun.bsky.social', $result->message );
		$this->assertSame( 'did:plc:abc123', $result->details['did'] );
	}

	public function test_test_connection_failure_on_401(): void {
		$publisher = $this->publisher_with( array( new Response( 401 ) ) );
		$result    = $publisher->test_connection();

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'credentials', $result->message );
	}

	public function test_publish_throws_when_unconfigured(): void {
		$publisher = $this->publisher_with( array(), null, '', '' );
		$this->expectException( PublisherException::class );
		$publisher->publish( $this->sample_request() );
	}

	public function test_publish_happy_path_with_image(): void {
		// createSession → uploadBlob → createRecord.
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), $this->session_body() ),
				new Response( 200, array(), $this->blob_body() ),
				new Response( 200, array(), $this->record_body() ),
			),
			$this->fetcher_returning( 'SMALLBYTES' )
		);

		$result = $publisher->publish( $this->sample_request() );

		$this->assertTrue( $result->success );
		// platform_post_id is the AT URI; platform_url is the bsky.app permalink.
		$this->assertSame( 'at://did:plc:abc123/app.bsky.feed.post/3kabc', $result->platform_post_id );
		$this->assertSame(
			'https://bsky.app/profile/viciousfun.bsky.social/post/3kabc',
			$result->platform_url
		);

		// Inspect the createRecord payload (3rd request).
		$this->assertCount( 3, $this->history );
		$record_payload = json_decode( (string) $this->history[2]['request']->getBody(), true );
		$record         = $record_payload['record'];

		$this->assertSame( 'app.bsky.feed.post', $record['$type'] );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
		$this->assertSame( 'https://viciousfun.com/product/gargamel', $record['embed']['external']['uri'] );
		$this->assertArrayHasKey( 'thumb', $record['embed']['external'] );
		$this->assertSame( 'blob', $record['embed']['external']['thumb']['$type'] );
		// createdAt must be ISO-8601 UTC with trailing Z.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $record['createdAt'] );
	}

	public function test_publish_uses_platform_option_title_for_card(): void {
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), $this->session_body() ),
				new Response( 200, array(), $this->blob_body() ),
				new Response( 200, array(), $this->record_body() ),
			),
			$this->fetcher_returning( 'SMALLBYTES' )
		);

		$publisher->publish( $this->sample_request( 'Body copy.', array(), array( 'title' => 'Gargamel Original' ) ) );

		$record = json_decode( (string) $this->history[2]['request']->getBody(), true )['record'];
		$this->assertSame( 'Gargamel Original', $record['embed']['external']['title'] );
	}

	public function test_publish_skips_thumbnail_when_image_too_large(): void {
		// Image > 1MB → no uploadBlob call; only createSession + createRecord.
		$big       = str_repeat( 'A', 1000001 );
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), $this->session_body() ),
				new Response( 200, array(), $this->record_body() ),
			),
			$this->fetcher_returning( $big )
		);

		$result = $publisher->publish( $this->sample_request() );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->history, 'uploadBlob should be skipped for oversized images.' );

		$record = json_decode( (string) $this->history[1]['request']->getBody(), true )['record'];
		$this->assertArrayNotHasKey( 'thumb', $record['embed']['external'] );
		// External embed is still present, just thumbless.
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
	}

	public function test_publish_skips_thumbnail_when_fetch_fails(): void {
		$fetcher   = static function ( string $url ): ?array {
			unset( $url );
			return null;
		};
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), $this->session_body() ),
				new Response( 200, array(), $this->record_body() ),
			),
			$fetcher
		);

		$result = $publisher->publish( $this->sample_request() );

		$this->assertTrue( $result->success );
		$this->assertCount( 2, $this->history, 'uploadBlob should be skipped when the fetch fails.' );
		$record = json_decode( (string) $this->history[1]['request']->getBody(), true )['record'];
		$this->assertArrayNotHasKey( 'thumb', $record['embed']['external'] );
	}

	public function test_publish_returns_failure_on_session_401(): void {
		$publisher = $this->publisher_with( array( new Response( 401 ) ) );
		$result    = $publisher->publish( $this->sample_request() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'credentials', (string) $result->error_message );
	}

	public function test_hashtag_facets_use_utf8_byte_offsets(): void {
		// A multibyte emoji ("🎉" = 4 UTF-8 bytes) and an accented char ("é" = 2
		// bytes) precede the hashtag. The facet byteStart MUST account for those
		// bytes — char-based offsets would be wrong.
		$content   = '🎉 Café drop #gargamel';
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), $this->session_body() ),
				new Response( 200, array(), $this->record_body() ),
			),
			static function ( string $url ): ?array {
				unset( $url );
				return null;
			}
		);

		// Only one hashtag, already inline in the content; pass it so the facet
		// builder picks it up.
		$publisher->publish( $this->sample_request( $content, array( '#gargamel' ) ) );

		$record = json_decode( (string) $this->history[1]['request']->getBody(), true )['record'];
		$this->assertArrayHasKey( 'facets', $record );
		$facet = $record['facets'][0];

		// The post text is "<content>\n\n#gargamel" — the hashtag the builder
		// matches first is the inline "#gargamel" inside the content. Compute the
		// expected BYTE offset of the first "#gargamel" occurrence.
		$text          = $record['text'];
		$expected_byte = strpos( $text, '#gargamel' );

		$this->assertSame( $expected_byte, $facet['index']['byteStart'] );
		$this->assertSame( $expected_byte + strlen( '#gargamel' ), $facet['index']['byteEnd'] );
		$this->assertSame( 'app.bsky.richtext.facet#tag', $facet['features'][0]['$type'] );
		$this->assertSame( 'gargamel', $facet['features'][0]['tag'] );

		// Sanity: the byte offset must be larger than the *character* offset,
		// proving multibyte chars shifted it. "🎉 Café drop " before the '#':
		// char length is shorter than byte length.
		$char_offset = mb_strpos( $text, '#gargamel' );
		$this->assertGreaterThan( $char_offset, $expected_byte );
	}

	public function test_publish_truncates_text_to_300_graphemes(): void {
		$long      = str_repeat( 'a', 400 );
		$publisher = $this->publisher_with(
			array(
				new Response( 200, array(), $this->session_body() ),
				new Response( 200, array(), $this->record_body() ),
			),
			static function ( string $url ): ?array {
				unset( $url );
				return null;
			}
		);

		$publisher->publish( $this->sample_request( $long, array() ) );

		$record = json_decode( (string) $this->history[1]['request']->getBody(), true )['record'];
		$length = function_exists( 'grapheme_strlen' )
			? grapheme_strlen( $record['text'] )
			: mb_strlen( $record['text'] );
		$this->assertLessThanOrEqual( 300, $length );
	}
}
