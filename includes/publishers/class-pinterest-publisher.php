<?php
/**
 * Pinterest publisher.
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
 * Publishes pins to Pinterest via the v5 API.
 *
 * Phase 9 introduces per-tag board routing: each WC `product_tag` can be mapped
 * to a Pinterest board id, and optionally configured to auto-create a board on
 * the first send when no mapping exists. The legacy `board_id` becomes the
 * default fallback when no tag routes resolve.
 *
 * Pinterest API docs: https://developers.pinterest.com/docs/api/v5/
 */
final class PinterestPublisher implements PlatformPublisherInterface {

	private const PLATFORM_ID         = 'pinterest';
	private const API_BASE_PRODUCTION = 'https://api.pinterest.com/v5';
	private const API_BASE_SANDBOX    = 'https://api-sandbox.pinterest.com/v5';
	private const DESCRIPTION_MAX     = 800;
	private const ALT_TEXT_MAX        = 500;

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
	 * Default Pinterest board id used when no per-tag mapping resolves.
	 *
	 * @var string
	 */
	private string $default_board_id;

	/**
	 * WC product_tag slug → Pinterest board id.
	 *
	 * @var array<string, string>
	 */
	private array $tag_board_map;

	/**
	 * WC product_tag slug → bool. When true and no mapping exists for that
	 * slug, the publisher will auto-create a board on first send.
	 *
	 * @var array<string, bool>
	 */
	private array $tag_auto_create;

	/**
	 * Optional service used to auto-create boards when `tag_auto_create` is
	 * true for a slug with no existing mapping.
	 *
	 * @var PinterestBoardService|null
	 */
	private ?PinterestBoardService $board_service;

	/**
	 * Optional callback invoked after a successful auto-create so the wiring
	 * layer can persist the new mapping back to settings. Signature:
	 * `function(string $slug, string $new_board_id): void`.
	 *
	 * @var Closure|null
	 */
	private ?Closure $on_auto_create;

	/**
	 * Resolved API base URL. Determined at construction from the requested API
	 * mode; production and sandbox have separate realms and cannot share
	 * tokens, so the realm is fixed for the publisher's lifetime.
	 *
	 * @var string
	 */
	private string $api_base;

	/**
	 * Constructor.
	 *
	 * @param string                     $default_board_id Default Pinterest board id (fallback when no tag resolves).
	 * @param array<string, string>      $tag_board_map    WC product_tag slug → Pinterest board id.
	 * @param array<string, bool>        $tag_auto_create  WC product_tag slug → bool: auto-create on first send.
	 * @param PinterestBoardService|null $board_service    Optional board service for auto-create lookups.
	 * @param Closure|null               $on_auto_create   Optional callback invoked on successful auto-create.
	 * @param string                     $access_token     Plaintext Pinterest API access token.
	 * @param string                     $api_mode         'production' (default) or 'sandbox'.
	 * @param ClientInterface|null       $client           Optional Guzzle client override for tests.
	 */
	public function __construct(
		string $default_board_id,
		array $tag_board_map,
		array $tag_auto_create,
		?PinterestBoardService $board_service,
		?Closure $on_auto_create,
		string $access_token,
		string $api_mode = 'production',
		?ClientInterface $client = null
	) {
		$this->default_board_id = $default_board_id;
		$this->tag_board_map    = $tag_board_map;
		$this->tag_auto_create  = $tag_auto_create;
		$this->board_service    = $board_service;
		$this->on_auto_create   = $on_auto_create;
		$this->access_token     = $access_token;
		$this->api_base         = 'sandbox' === $api_mode
			? self::API_BASE_SANDBOX
			: self::API_BASE_PRODUCTION;
		$this->client           = $client ?? new Client( array( 'timeout' => 30 ) );
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
	 * Returns true when an access token is present AND there is at least one
	 * destination configured (either a default board id, or any tag mapping).
	 */
	public function is_configured(): bool {
		return '' !== $this->access_token
			&& ( '' !== $this->default_board_id || array() !== $this->tag_board_map );
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

		try {
			$board_id = $this->resolve_board_id( $request );
		} catch ( PublisherException $e ) {
			return PublishResult::failure_for( self::PLATFORM_ID, $e->getMessage() );
		}

		if ( '' === $board_id ) {
			return PublishResult::failure_for(
				self::PLATFORM_ID,
				'No destination board configured for this product.'
			);
		}

		$body = array(
			'board_id'     => $board_id,
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
	 * Resolve the destination board id for a publish request.
	 *
	 * Precedence:
	 *   1. Explicit per-publish `board_id_override` (the metabox "Pin to"
	 *      dropdown). When present and non-empty, always wins — no tag lookup,
	 *      no auto-create.
	 *   2. Tag routing: walk the request's `tag_slugs` in order. First slug
	 *      with an explicit mapping wins. If a slug has `tag_auto_create`
	 *      enabled and a board service is available, a new public board is
	 *      created and the mapping is cached on this instance for the rest of
	 *      the request (and persisted via the `on_auto_create` callback when
	 *      one is supplied).
	 *   3. Configured default board id.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return string Board id (may be empty when nothing is configured).
	 *
	 * @throws PublisherException When auto-creation fails (bubbles from the board service).
	 */
	public function resolve_board_id( PublishRequest $request ): string {
		$override = isset( $request->platform_options['board_id_override'] )
			? (string) $request->platform_options['board_id_override']
			: '';
		if ( '' !== $override ) {
			return $override;
		}

		$slugs = isset( $request->platform_options['tag_slugs'] )
			&& is_array( $request->platform_options['tag_slugs'] )
				? $request->platform_options['tag_slugs']
				: array();

		foreach ( $slugs as $raw_slug ) {
			$slug = (string) $raw_slug;
			if ( '' === $slug ) {
				continue;
			}

			if ( isset( $this->tag_board_map[ $slug ] ) && '' !== $this->tag_board_map[ $slug ] ) {
				return (string) $this->tag_board_map[ $slug ];
			}

			if (
				isset( $this->tag_auto_create[ $slug ] )
				&& true === $this->tag_auto_create[ $slug ]
				&& null !== $this->board_service
			) {
				$new_id                       = $this->board_service->create_public_board( $this->humanize_slug( $slug ) );
				$this->tag_board_map[ $slug ] = $new_id;
				if ( null !== $this->on_auto_create ) {
					( $this->on_auto_create )( $slug, $new_id );
				}
				return $new_id;
			}
		}

		return $this->default_board_id;
	}

	/**
	 * Convert a slug into a human-readable board name.
	 *
	 * @param string $slug Tag slug (e.g. "anraku-ansaku").
	 * @return string Human-readable name (e.g. "Anraku Ansaku").
	 */
	public function humanize_slug( string $slug ): string {
		return ucwords( str_replace( '-', ' ', $slug ) );
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
			$response = $this->client->request( $method, $this->api_base . $path, $options );
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
