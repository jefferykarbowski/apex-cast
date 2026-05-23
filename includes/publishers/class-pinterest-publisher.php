<?php
/**
 * Pinterest publisher.
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
 * Publishes pins to Pinterest via the v5 API.
 *
 * Phase 5 takes the access token as plain input (the user pastes one generated
 * from the Pinterest Developer dashboard, or via a manual one-off OAuth flow).
 * Phase 6 will add a proper "Connect Pinterest" OAuth UI plus automatic
 * refresh-token handling.
 *
 * Pinterest API docs: https://developers.pinterest.com/docs/api/v5/
 */
final class PinterestPublisher implements PlatformPublisherInterface {

	private const PLATFORM_ID     = 'pinterest';
	private const API_BASE        = 'https://api.pinterest.com/v5';
	private const DESCRIPTION_MAX = 800;
	private const ALT_TEXT_MAX    = 500;

	/**
	 * HTTP client (Guzzle by default, injectable for tests).
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Plaintext Pinterest API access token.
	 *
	 * @var string
	 */
	private string $access_token;

	/**
	 * Pinterest board ID to pin to.
	 *
	 * @var string
	 */
	private string $board_id;

	/**
	 * Constructor.
	 *
	 * @param string               $access_token Plaintext Pinterest API access token.
	 * @param string               $board_id     Target Pinterest board ID.
	 * @param ClientInterface|null $client       Optional Guzzle client override for tests.
	 */
	public function __construct(
		string $access_token,
		string $board_id,
		?ClientInterface $client = null
	) {
		$this->access_token = $access_token;
		$this->board_id     = $board_id;
		$this->client       = $client ?? new Client( array( 'timeout' => 30 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_platform_id(): string {
		return self::PLATFORM_ID;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Returns true only when both an access token and a board ID are present —
	 * publishing requires both.
	 */
	public function is_configured(): bool {
		return '' !== $this->access_token && '' !== $this->board_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Hits `GET /user_account` to confirm the access token is valid. A board ID
	 * is not required to test the token, so a configured token + empty board ID
	 * will still pass this check (the user is mid-setup).
	 */
	public function test_connection(): TestConnectionResult {
		if ( '' === $this->access_token ) {
			return TestConnectionResult::failure( 'Pinterest access token is not configured.' );
		}

		try {
			$response = $this->request( 'GET', '/user_account' );
			$data     = $this->decode_json( $response );
		} catch ( PublisherException $e ) {
			return TestConnectionResult::failure( $e->getMessage() );
		}

		$username = isset( $data['username'] ) ? (string) $data['username'] : '';
		$message  = '' !== $username
			? sprintf( 'Connected to Pinterest as @%s.', $username )
			: 'Pinterest access token is valid.';

		return TestConnectionResult::success(
			$message,
			array(
				'username'     => $username,
				'account_type' => isset( $data['account_type'] ) ? (string) $data['account_type'] : '',
			)
		);
	}

	/**
	 * Publish a single pin.
	 *
	 * Returns a failure `PublishResult` when Pinterest accepts the request but
	 * rejects the content (e.g. board not found, image URL not reachable).
	 * Throws a `PublisherException` when the call itself fails (auth, rate
	 * limit, server error, malformed response).
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

		$body = array(
			'board_id'     => $this->board_id,
			'description'  => $this->build_description( $request ),
			'link'         => $request->product_url,
			'alt_text'     => $this->truncate( $request->content, self::ALT_TEXT_MAX ),
			'media_source' => array(
				'source_type' => 'image_url',
				'url'         => $request->media_url,
			),
		);

		try {
			$response = $this->request( 'POST', '/pins', array( 'json' => $body ) );
			$data     = $this->decode_json( $response );
		} catch ( PublisherException $e ) {
			return PublishResult::failure_for( self::PLATFORM_ID, $e->getMessage() );
		}

		$pin_id = isset( $data['id'] ) ? (string) $data['id'] : '';
		if ( '' === $pin_id ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				'Pinterest response was missing the pin id.'
			);
		}

		return PublishResult::success_for(
			self::PLATFORM_ID,
			$pin_id,
			sprintf( 'https://www.pinterest.com/pin/%s/', $pin_id )
		);
	}

	/**
	 * Build the pin description from the publish request: content body + hashtags
	 * inline, truncated to Pinterest's 800-character limit.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return string
	 */
	private function build_description( PublishRequest $request ): string {
		$description = $request->content;
		if ( ! empty( $request->hashtags ) ) {
			$description .= "\n\n" . implode( ' ', $request->hashtags );
		}
		return $this->truncate( $description, self::DESCRIPTION_MAX );
	}

	/**
	 * Truncate a string to at most `$max` characters, appending an ellipsis when
	 * it had to be shortened.
	 *
	 * @param string $value Input string.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private function truncate( string $value, int $max ): string {
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, $max - 1 ) . '…';
	}

	/**
	 * Authenticated request with error mapping.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path relative to the API base.
	 * @param array<string, mixed> $options Additional Guzzle request options.
	 * @return ResponseInterface
	 *
	 * @throws PublisherException On HTTP failure.
	 */
	private function request( string $method, string $path, array $options = array() ): ResponseInterface {
		$headers                  = isset( $options['headers'] ) && is_array( $options['headers'] ) ? $options['headers'] : array();
		$headers['Authorization'] = 'Bearer ' . $this->access_token;
		$headers['Accept']        = 'application/json';
		$options['headers']       = $headers;

		try {
			$response = $this->client->request( $method, self::API_BASE . $path, $options );
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
	 * Map an HTTP status code to the appropriate `PublisherException` shape.
	 *
	 * @param int $status HTTP status code.
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
	 * Decode a response body as a JSON object.
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
