<?php
/**
 * Threads publisher.
 *
 * @package ApexChute\ApexCast\Publishers
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Publishers;

use ApexChute\ApexCast\Support\TestConnectionResult;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Publishes a single image + caption to Threads via the graph.threads.net API,
 * using Threads' two-step container flow with a readiness poll between create
 * and publish.
 *
 *   1. POST /{user_id}/threads         → creates a media container (creation_id)
 *   2. GET  /{creation_id}?fields=status → poll until FINISHED (or ERROR/EXPIRED)
 *   3. POST /{user_id}/threads_publish → publishes the container → media_id
 *   4. GET  /{media_id}?fields=permalink → best-effort public URL
 *
 * Auth is the long-lived Threads user token + the numeric Threads user_id; no
 * app id/secret is needed at publish time (those only matter for OAuth and the
 * register-time refresh, handled in Plugin).
 */
final class ThreadsPublisher implements PlatformPublisherInterface {

	private const PLATFORM_ID  = 'threads';
	private const API_BASE     = 'https://graph.threads.net/v1.0';
	private const TEXT_MAX     = 500;
	private const POLL_MAX     = 12; // ~24s worst case at 2s/poll.
	private const POLL_SECONDS = 2;

	/**
	 * HTTP client.
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Plaintext long-lived Threads user access token.
	 *
	 * @var string
	 */
	private string $access_token;

	/**
	 * Numeric Threads user id (the publishing target).
	 *
	 * @var string
	 */
	private string $user_id;

	/**
	 * Cached Threads username for display in test_connection messages.
	 *
	 * @var string
	 */
	private string $username;

	/**
	 * Sleep function for the readiness poll. Injectable so tests can pass a
	 * no-op and not actually block for ~24s.
	 *
	 * @var Closure
	 */
	private Closure $sleeper;

	/**
	 * Constructor.
	 *
	 * @param string               $access_token Plaintext long-lived Threads user token.
	 * @param string               $user_id      Numeric Threads user id.
	 * @param string               $username     Optional Threads username for display.
	 * @param ClientInterface|null $client       Optional Guzzle override for tests.
	 * @param Closure|null         $sleeper      Optional sleep override; defaults to PHP sleep().
	 */
	public function __construct(
		string $access_token,
		string $user_id,
		string $username = '',
		?ClientInterface $client = null,
		?Closure $sleeper = null
	) {
		$this->access_token = $access_token;
		$this->user_id      = $user_id;
		$this->username     = $username;
		$this->client       = $client ?? new Client( array( 'timeout' => 30 ) );
		$this->sleeper      = $sleeper ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
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
		return '' !== $this->access_token && '' !== $this->user_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): TestConnectionResult {
		if ( ! $this->is_configured() ) {
			return TestConnectionResult::failure( 'Threads is not configured.' );
		}

		try {
			$response = $this->request(
				'GET',
				'/me',
				array(
					'query' => array(
						'fields'       => 'id,username',
						'access_token' => $this->access_token,
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
				? sprintf( 'Connected to Threads as @%s.', $username )
				: 'Threads token is valid.',
			array(
				'user_id'  => $this->user_id,
				'username' => $username,
			)
		);
	}

	/**
	 * Publish a single image post.
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
				'/' . rawurlencode( $this->user_id ) . '/threads',
				array(
					'form_params' => array(
						'media_type'   => 'IMAGE',
						'image_url'    => $request->media_url,
						'text'         => $this->build_text( $request ),
						'access_token' => $this->access_token,
					),
				)
			);
		} catch ( PublisherException $e ) {
			return PublishResult::failure_for( self::PLATFORM_ID, $e->getMessage() );
		}

		$container_data = $this->decode_json( $container_response );
		$creation_id    = isset( $container_data['id'] ) ? (string) $container_data['id'] : '';
		if ( '' === $creation_id ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				'Threads did not return a container id.'
			);
		}

		// Step 2: poll readiness.
		$readiness = $this->poll_until_ready( $creation_id );
		if ( true !== $readiness['ready'] ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				$readiness['message'],
				array( 'creation_id' => $creation_id )
			);
		}

		// Step 3: publish container.
		try {
			$publish_response = $this->request(
				'POST',
				'/' . rawurlencode( $this->user_id ) . '/threads_publish',
				array(
					'form_params' => array(
						'creation_id'  => $creation_id,
						'access_token' => $this->access_token,
					),
				)
			);
		} catch ( PublisherException $e ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				$e->getMessage(),
				array( 'creation_id' => $creation_id )
			);
		}

		$publish_data = $this->decode_json( $publish_response );
		$media_id     = isset( $publish_data['id'] ) ? (string) $publish_data['id'] : '';
		if ( '' === $media_id ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				'Threads did not return a media id after publishing.',
				array( 'creation_id' => $creation_id )
			);
		}

		// Step 4: best-effort permalink.
		$permalink = $this->fetch_permalink( $media_id );

		return PublishResult::success_for(
			self::PLATFORM_ID,
			$media_id,
			$permalink,
			array( 'creation_id' => $creation_id )
		);
	}

	/**
	 * Poll the container's processing status until it finishes, errors, or we
	 * exhaust the attempt budget.
	 *
	 * @param string $creation_id The container id from the create step.
	 * @return array{ready: bool, message: string}
	 */
	private function poll_until_ready( string $creation_id ): array {
		for ( $attempt = 0; $attempt < self::POLL_MAX; $attempt++ ) {
			try {
				$response = $this->request(
					'GET',
					'/' . rawurlencode( $creation_id ),
					array(
						'query' => array(
							'fields'       => 'status,error_message',
							'access_token' => $this->access_token,
						),
					)
				);
			} catch ( PublisherException $e ) {
				return array(
					'ready'   => false,
					'message' => $e->getMessage(),
				);
			}

			$data   = $this->decode_json( $response );
			$status = isset( $data['status'] ) ? (string) $data['status'] : '';

			if ( 'FINISHED' === $status ) {
				return array(
					'ready'   => true,
					'message' => '',
				);
			}

			if ( 'ERROR' === $status || 'EXPIRED' === $status ) {
				$detail = isset( $data['error_message'] ) && '' !== (string) $data['error_message']
					? (string) $data['error_message']
					: sprintf( 'Threads reported container status %s.', $status );
				return array(
					'ready'   => false,
					'message' => $detail,
				);
			}

			// Still IN_PROGRESS — wait and retry, unless this was the last attempt.
			if ( $attempt < self::POLL_MAX - 1 ) {
				( $this->sleeper )( self::POLL_SECONDS );
			}
		}

		return array(
			'ready'   => false,
			'message' => 'Threads is still processing the image — try again in a moment.',
		);
	}

	/**
	 * Best-effort fetch of the public permalink for a just-published media.
	 * Returns an empty string when the call fails — the publish itself already
	 * succeeded, so we don't propagate the error.
	 *
	 * @param string $media_id Threads media id.
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
						'access_token' => $this->access_token,
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
	 * Build the post text: content body + hashtags, truncated to 500 chars.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return string
	 */
	private function build_text( PublishRequest $request ): string {
		$text = $request->content;
		if ( ! empty( $request->hashtags ) ) {
			$text .= "\n\n" . implode( ' ', $request->hashtags );
		}
		if ( mb_strlen( $text ) > self::TEXT_MAX ) {
			$text = mb_substr( $text, 0, self::TEXT_MAX - 1 ) . '…';
		}
		return $text;
	}

	/**
	 * Authenticated request with error mapping.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path under the API base.
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
