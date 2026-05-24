<?php
/**
 * Facebook Page publisher.
 *
 * @package ApexChute\ApexCast\Publishers
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Publishers;

use ApexChute\ApexCast\Support\TestConnectionResult;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Publishes a product photo + caption to a Facebook Page via the Graph API.
 *
 * Uses the Page Access Token captured during the Meta OAuth flow. A Page
 * Access Token does not expire (only the User Access Token expires after
 * ~60 days), so as long as the user keeps connecting periodically the
 * publisher stays usable.
 *
 * Single-call publish: `POST /{page-id}/photos` with `url` (image), `caption`
 * (combined product copy + permalink + hashtags). Returns the resulting post id.
 */
final class FacebookPagePublisher implements PlatformPublisherInterface {

	private const PLATFORM_ID = 'facebook';
	private const GRAPH_BASE  = 'https://graph.facebook.com/v19.0';
	private const CAPTION_MAX = 60000; // Generous FB cap; we mostly worry about display, not API limits.

	/**
	 * HTTP client.
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Plaintext Page Access Token.
	 *
	 * @var string
	 */
	private string $page_access_token;

	/**
	 * Facebook Page id.
	 *
	 * @var string
	 */
	private string $page_id;

	/**
	 * Cached Page name for display in test_connection messages.
	 *
	 * @var string
	 */
	private string $page_name;

	/**
	 * Constructor.
	 *
	 * @param string               $page_access_token Plaintext Page Access Token.
	 * @param string               $page_id           Facebook Page id (numeric string).
	 * @param string               $page_name         Optional Page display name.
	 * @param ClientInterface|null $client            Optional Guzzle override for tests.
	 */
	public function __construct(
		string $page_access_token,
		string $page_id,
		string $page_name = '',
		?ClientInterface $client = null
	) {
		$this->page_access_token = $page_access_token;
		$this->page_id           = $page_id;
		$this->page_name         = $page_name;
		$this->client            = $client ?? new Client( array( 'timeout' => 30 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_platform_id(): string {
		return self::PLATFORM_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->page_access_token && '' !== $this->page_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): TestConnectionResult {
		if ( ! $this->is_configured() ) {
			return TestConnectionResult::failure( 'Facebook Page is not configured.' );
		}

		try {
			$response = $this->request(
				'GET',
				'/' . rawurlencode( $this->page_id ),
				array(
					'query' => array(
						'fields'       => 'id,name,category',
						'access_token' => $this->page_access_token,
					),
				)
			);
		} catch ( PublisherException $e ) {
			return TestConnectionResult::failure( $e->getMessage() );
		}

		$data = $this->decode_json( $response );
		$name = isset( $data['name'] ) ? (string) $data['name'] : $this->page_name;

		return TestConnectionResult::success(
			'' !== $name
				? sprintf( 'Connected to Facebook Page "%s".', $name )
				: 'Facebook Page token is valid.',
			array(
				'page_id'   => $this->page_id,
				'page_name' => $name,
			)
		);
	}

	/**
	 * Publish a photo post to the Page.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return PublishResult
	 *
	 * @throws PublisherException When the publisher isn't configured.
	 */
	public function publish( PublishRequest $request ): PublishResult {
		if ( ! $this->is_configured() ) {
			throw PublisherException::not_configured( self::PLATFORM_ID );
		}

		$caption = $this->build_caption( $request );
		$form    = array(
			'url'          => $request->media_url,
			'caption'      => $caption,
			'access_token' => $this->page_access_token,
		);

		try {
			$response = $this->request(
				'POST',
				'/' . rawurlencode( $this->page_id ) . '/photos',
				array( 'form_params' => $form )
			);
		} catch ( PublisherException $e ) {
			return PublishResult::failure_for( self::PLATFORM_ID, $e->getMessage() );
		}

		$data    = $this->decode_json( $response );
		$post_id = isset( $data['post_id'] ) ? (string) $data['post_id'] : ( isset( $data['id'] ) ? (string) $data['id'] : '' );
		if ( '' === $post_id ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				'Facebook response was missing post_id.'
			);
		}

		// The Graph response gives us "<page-id>_<post-id>" for post_id; the
		// public-facing URL uses that exact value.
		$url = sprintf( 'https://www.facebook.com/%s', $post_id );

		return PublishResult::success_for( self::PLATFORM_ID, $post_id, $url );
	}

	/**
	 * Build the FB caption from the publish request: content body + permalink
	 * + hashtags, truncated to a sane length.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return string
	 */
	private function build_caption( PublishRequest $request ): string {
		$parts = array( $request->content );
		if ( '' !== $request->product_url ) {
			$parts[] = $request->product_url;
		}
		if ( ! empty( $request->hashtags ) ) {
			$parts[] = implode( ' ', $request->hashtags );
		}
		$caption = implode(
			"\n\n",
			array_filter(
				$parts,
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);
		if ( strlen( $caption ) > self::CAPTION_MAX ) {
			$caption = substr( $caption, 0, self::CAPTION_MAX - 1 ) . '…';
		}
		return $caption;
	}

	/**
	 * Authenticated Graph request with error mapping.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path under the Graph base.
	 * @param array<string, mixed> $options Additional Guzzle options.
	 * @return ResponseInterface
	 *
	 * @throws PublisherException On HTTP failure.
	 */
	private function request( string $method, string $path, array $options = array() ): ResponseInterface {
		$headers            = isset( $options['headers'] ) && is_array( $options['headers'] ) ? $options['headers'] : array();
		$headers['Accept']  = 'application/json';
		$options['headers'] = $headers;

		try {
			$response = $this->client->request( $method, self::GRAPH_BASE . $path, $options );
		} catch ( RequestException $e ) {
			$response = $e->getResponse();
			if ( null === $response ) {
				throw PublisherException::http_error( self::PLATFORM_ID, 0 );
			}
			throw $this->classify_http_error( $response->getStatusCode() );
		} catch ( GuzzleException $e ) {
			throw PublisherException::http_error( self::PLATFORM_ID, 0 );
		}

		$status = $response->getStatusCode();
		if ( $status >= 400 ) {
			throw $this->classify_http_error( $status );
		}
		return $response;
	}

	/**
	 * Map an HTTP status code to a publisher exception.
	 *
	 * @param int $status HTTP status.
	 * @return PublisherException
	 */
	private function classify_http_error( int $status ): PublisherException {
		if ( 401 === $status || 403 === $status ) {
			return PublisherException::auth_failed( self::PLATFORM_ID );
		}
		if ( 429 === $status ) {
			return PublisherException::rate_limited( self::PLATFORM_ID );
		}
		return PublisherException::http_error( self::PLATFORM_ID, $status );
	}

	/**
	 * Decode a Graph response body.
	 *
	 * @param ResponseInterface $response HTTP response.
	 * @return array<string, mixed>
	 *
	 * @throws PublisherException When the body is not a JSON object.
	 */
	private function decode_json( ResponseInterface $response ): array {
		$data = json_decode( (string) $response->getBody(), true );
		if ( ! is_array( $data ) ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'Response was not valid JSON.' );
		}
		return $data;
	}
}
