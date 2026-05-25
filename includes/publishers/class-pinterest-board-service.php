<?php
/**
 * Pinterest board service.
 *
 * @package ApexChute\ApexCast\Publishers
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Publishers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin HTTP wrapper around Pinterest's v5 boards API.
 *
 * Lives next to `PinterestPublisher` but doesn't share its publishing surface —
 * this class is the read+create side that the settings UI uses to fill in the
 * "tag → board" mapping, and that the publisher consults when a tag is
 * configured for auto-create.
 *
 * Has no WordPress dependency, so it's fully unit-testable with a Guzzle
 * MockHandler.
 */
final class PinterestBoardService {

	private const API_BASE    = 'https://api.pinterest.com/v5';
	private const PLATFORM_ID = 'pinterest';
	private const PAGE_SIZE   = 100;
	private const MAX_PAGES   = 50;

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
	 * Constructor.
	 *
	 * @param string               $access_token Plaintext Pinterest API access token.
	 * @param ClientInterface|null $client       Optional Guzzle client override for tests.
	 */
	public function __construct( string $access_token, ?ClientInterface $client = null ) {
		$this->access_token = $access_token;
		$this->client       = $client ?? new Client( array( 'timeout' => 30 ) );
	}

	/**
	 * List every board on the connected account, paginating up to `$limit` rows.
	 *
	 * Pinterest returns at most 100 boards per page; we follow the `bookmark`
	 * cursor until we hit `$limit`, run out of pages, or hit the
	 * `MAX_PAGES` safety stop.
	 *
	 * @param int $limit Soft cap on the number of boards to return.
	 * @return array<int, array{id: string, name: string, privacy: string}>
	 *
	 * @throws PublisherException When any page returns a non-2xx response.
	 */
	public function list_boards( int $limit = 250 ): array {
		$boards   = array();
		$bookmark = '';
		$page     = 0;

		do {
			$query = array(
				'page_size' => self::PAGE_SIZE,
				'privacy'   => 'ALL',
			);
			if ( '' !== $bookmark ) {
				$query['bookmark'] = $bookmark;
			}

			$response = $this->request( 'GET', '/boards', array( 'query' => $query ) );
			$data     = $this->decode_json( $response );

			$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$boards[] = array(
					'id'      => isset( $item['id'] ) ? (string) $item['id'] : '',
					'name'    => isset( $item['name'] ) ? (string) $item['name'] : '',
					'privacy' => isset( $item['privacy'] ) ? (string) $item['privacy'] : '',
				);
				if ( count( $boards ) >= $limit ) {
					return $boards;
				}
			}

			$bookmark = isset( $data['bookmark'] ) && is_string( $data['bookmark'] ) ? $data['bookmark'] : '';
			++$page;
		} while ( '' !== $bookmark && $page < self::MAX_PAGES );

		return $boards;
	}

	/**
	 * Create a new public board with the given name and return its id.
	 *
	 * @param string $name Board name (must be non-empty).
	 * @return string Newly-created board id.
	 *
	 * @throws PublisherException When the API rejects the request or returns no id.
	 */
	public function create_public_board( string $name ): string {
		$response = $this->request(
			'POST',
			'/boards',
			array(
				'json' => array(
					'name'    => $name,
					'privacy' => 'PUBLIC',
				),
			)
		);
		$data     = $this->decode_json( $response );

		$id = isset( $data['id'] ) ? (string) $data['id'] : '';
		if ( '' === $id ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'Board create response was missing the board id.' );
		}
		return $id;
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
