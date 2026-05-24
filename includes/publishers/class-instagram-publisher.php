<?php
/**
 * Instagram publisher.
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
 * Publishes a single photo + caption to a connected Instagram Business or
 * Creator account, using the Instagram Graph API's two-step container flow.
 *
 *   1. POST /{ig-user-id}/media         → creates a media container
 *   2. POST /{ig-user-id}/media_publish → publishes that container
 *
 * Uses the same Page Access Token as `FacebookPagePublisher` (Meta vends one
 * token per Page that grants both Page posting and IG content publishing to
 * the linked account).
 *
 * IG personal accounts cannot be published to via this API — the linked
 * account must be Business or Creator. The plugin's settings UI enforces
 * connecting via Facebook Login for Business, which only surfaces eligible
 * accounts; we don't need to re-check here.
 */
final class InstagramPublisher implements PlatformPublisherInterface {

	private const PLATFORM_ID = 'instagram';
	private const GRAPH_BASE  = 'https://graph.facebook.com/v19.0';
	private const CAPTION_MAX = 2200;

	/**
	 * HTTP client.
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Plaintext Page Access Token (shared with the linked Facebook Page).
	 *
	 * @var string
	 */
	private string $page_access_token;

	/**
	 * IG Business / Creator account id (numeric string).
	 *
	 * @var string
	 */
	private string $ig_account_id;

	/**
	 * Cached IG username for display.
	 *
	 * @var string
	 */
	private string $username;

	/**
	 * Constructor.
	 *
	 * @param string               $page_access_token Plaintext Page Access Token.
	 * @param string               $ig_account_id     IG Business / Creator account id.
	 * @param string               $username          Optional IG username for display.
	 * @param ClientInterface|null $client            Optional Guzzle override for tests.
	 */
	public function __construct(
		string $page_access_token,
		string $ig_account_id,
		string $username = '',
		?ClientInterface $client = null
	) {
		$this->page_access_token = $page_access_token;
		$this->ig_account_id     = $ig_account_id;
		$this->username          = $username;
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
		return '' !== $this->page_access_token && '' !== $this->ig_account_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): TestConnectionResult {
		if ( ! $this->is_configured() ) {
			return TestConnectionResult::failure( 'Instagram is not configured.' );
		}

		try {
			$response = $this->request(
				'GET',
				'/' . rawurlencode( $this->ig_account_id ),
				array(
					'query' => array(
						'fields'       => 'id,username',
						'access_token' => $this->page_access_token,
					),
				)
			);
		} catch ( PublisherException $e ) {
			return TestConnectionResult::failure( $e->getMessage() );
		}

		$data     = $this->decode_json( $response );
		$username = isset( $data['username'] ) ? (string) $data['username'] : $this->username;

		return TestConnectionResult::success(
			'' !== $username
				? sprintf( 'Connected to Instagram as @%s.', $username )
				: 'Instagram token is valid.',
			array(
				'ig_account_id' => $this->ig_account_id,
				'username'      => $username,
			)
		);
	}

	/**
	 * Publish a single image post.
	 *
	 * Step 1 creates a media container, step 2 publishes it. If step 2 fails,
	 * the container is left dangling on IG's side (they auto-expire); we don't
	 * try to clean up.
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

		// Step 1: create container.
		try {
			$container_response = $this->request(
				'POST',
				'/' . rawurlencode( $this->ig_account_id ) . '/media',
				array(
					'form_params' => array(
						'image_url'    => $request->media_url,
						'caption'      => $this->build_caption( $request ),
						'access_token' => $this->page_access_token,
					),
				)
			);
		} catch ( PublisherException $e ) {
			return PublishResult::failure_for( self::PLATFORM_ID, $e->getMessage() );
		}

		$container_data = $this->decode_json( $container_response );
		$container_id   = isset( $container_data['id'] ) ? (string) $container_data['id'] : '';
		if ( '' === $container_id ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				'Instagram did not return a media container id.'
			);
		}

		// Step 2: publish container.
		try {
			$publish_response = $this->request(
				'POST',
				'/' . rawurlencode( $this->ig_account_id ) . '/media_publish',
				array(
					'form_params' => array(
						'creation_id'  => $container_id,
						'access_token' => $this->page_access_token,
					),
				)
			);
		} catch ( PublisherException $e ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				$e->getMessage(),
				array( 'container_id' => $container_id )
			);
		}

		$publish_data = $this->decode_json( $publish_response );
		$media_id     = isset( $publish_data['id'] ) ? (string) $publish_data['id'] : '';
		if ( '' === $media_id ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				'Instagram did not return a media id after publishing.',
				array( 'container_id' => $container_id )
			);
		}

		// IG public-URL form needs the media's permalink — fetch it best-effort.
		$permalink = $this->fetch_permalink( $media_id );

		return PublishResult::success_for(
			self::PLATFORM_ID,
			$media_id,
			$permalink,
			array( 'container_id' => $container_id )
		);
	}

	/**
	 * Best-effort fetch of the public permalink for a just-published media.
	 * Returns an empty string when the call fails — the publish itself was
	 * already successful, so we don't propagate the error.
	 *
	 * @param string $media_id Instagram media id.
	 * @return string
	 */
	private function fetch_permalink( string $media_id ): string {
		try {
			$response = $this->request(
				'GET',
				'/' . rawurlencode( $media_id ),
				array(
					'query' => array(
						'fields'       => 'permalink',
						'access_token' => $this->page_access_token,
					),
				)
			);
		} catch ( PublisherException $e ) {
			return '';
		}

		$data = json_decode( (string) $response->getBody(), true );
		if ( ! is_array( $data ) || ! isset( $data['permalink'] ) ) {
			return '';
		}
		return (string) $data['permalink'];
	}

	/**
	 * Build the IG caption: content body, hashtags grouped at the end, capped
	 * at IG's 2200-character limit.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return string
	 */
	private function build_caption( PublishRequest $request ): string {
		$caption = $request->content;
		if ( ! empty( $request->hashtags ) ) {
			$caption .= "\n\n" . implode( ' ', $request->hashtags );
		}
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
	 * Map an HTTP status to a publisher exception.
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
