<?php
/**
 * Bluesky AT Protocol client.
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
 * Thin HTTP wrapper around the Bluesky / AT Protocol XRPC endpoints we need.
 *
 * Mirrors the `PinterestBoardService` style: no WordPress dependency, fully
 * unit-testable with a Guzzle MockHandler. The publisher orchestrates these
 * calls; this class only knows how to speak XRPC and map errors.
 *
 * Endpoints used:
 *   - com.atproto.server.createSession  (auth: handle + app password → JWTs)
 *   - com.atproto.repo.uploadBlob       (image bytes → blob ref)
 *   - com.atproto.repo.createRecord     (post record → uri + cid)
 *
 * Docs: https://docs.bsky.app/docs/advanced-guides/posts
 */
final class BlueskyClient {

	private const API_BASE    = 'https://bsky.social/xrpc';
	private const PLATFORM_ID = 'bluesky';

	/**
	 * HTTP client (Guzzle by default, injectable for tests).
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface|null $client Optional Guzzle client override for tests.
	 */
	public function __construct( ?ClientInterface $client = null ) {
		$this->client = $client ?? new Client( array( 'timeout' => 30 ) );
	}

	/**
	 * Create a session (log in) with a handle + app password.
	 *
	 * @param string $identifier Bluesky handle (e.g. "viciousfun.bsky.social") or DID.
	 * @param string $password   App password (NOT the account password).
	 * @return array{accessJwt: string, refreshJwt: string, did: string, handle: string}
	 *
	 * @throws PublisherException On auth failure or malformed response.
	 */
	public function create_session( string $identifier, string $password ): array {
		$response = $this->request(
			'POST',
			'/com.atproto.server.createSession',
			array(
				'json' => array(
					'identifier' => $identifier,
					'password'   => $password,
				),
			)
		);
		$data     = $this->decode_json( $response );

		$access_jwt = isset( $data['accessJwt'] ) ? (string) $data['accessJwt'] : '';
		$did        = isset( $data['did'] ) ? (string) $data['did'] : '';
		if ( '' === $access_jwt || '' === $did ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'createSession response was missing accessJwt or did.' );
		}

		return array(
			'accessJwt'  => $access_jwt,
			'refreshJwt' => isset( $data['refreshJwt'] ) ? (string) $data['refreshJwt'] : '',
			'did'        => $did,
			'handle'     => isset( $data['handle'] ) ? (string) $data['handle'] : '',
		);
	}

	/**
	 * Upload raw image bytes as a blob, returning the blob ref the post record
	 * needs to embed it.
	 *
	 * @param string $access_jwt Bearer access JWT from create_session().
	 * @param string $bytes      Raw image bytes.
	 * @param string $mime       MIME type of the image (e.g. "image/jpeg").
	 * @return array<string, mixed> The `blob` field of the response (an AT blob ref).
	 *
	 * @throws PublisherException On HTTP failure or malformed response.
	 */
	public function upload_blob( string $access_jwt, string $bytes, string $mime ): array {
		$response = $this->request(
			'POST',
			'/com.atproto.repo.uploadBlob',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_jwt,
					'Content-Type'  => $mime,
				),
				'body'    => $bytes,
			)
		);
		$data     = $this->decode_json( $response );

		if ( ! isset( $data['blob'] ) || ! is_array( $data['blob'] ) ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'uploadBlob response was missing the blob ref.' );
		}

		return $data['blob'];
	}

	/**
	 * Create a record (a post) in the user's repo.
	 *
	 * @param string               $access_jwt Bearer access JWT from create_session().
	 * @param string               $repo_did   The repo DID (the user's DID).
	 * @param array<string, mixed> $record     The `app.bsky.feed.post` record body.
	 * @return array{uri: string, cid: string}
	 *
	 * @throws PublisherException On HTTP failure or malformed response.
	 */
	public function create_record( string $access_jwt, string $repo_did, array $record ): array {
		$response = $this->request(
			'POST',
			'/com.atproto.repo.createRecord',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_jwt ),
				'json'    => array(
					'repo'       => $repo_did,
					'collection' => 'app.bsky.feed.post',
					'record'     => $record,
				),
			)
		);
		$data     = $this->decode_json( $response );

		$uri = isset( $data['uri'] ) ? (string) $data['uri'] : '';
		if ( '' === $uri ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'createRecord response was missing the post uri.' );
		}

		return array(
			'uri' => $uri,
			'cid' => isset( $data['cid'] ) ? (string) $data['cid'] : '',
		);
	}

	/**
	 * Perform an XRPC request with error mapping.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path relative to the API base.
	 * @param array<string, mixed> $options Additional Guzzle request options.
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
