<?php
/**
 * Pinterest OAuth 2.0 client.
 *
 * @package ApexChute\ApexCast\OAuth
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\OAuth;

use ApexChute\ApexCast\Publishers\PublisherException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Builds the Pinterest OAuth 2.0 auth URL and performs the authorization-code
 * exchange against `POST https://api.pinterest.com/v5/oauth/token`.
 *
 * Sits behind the REST `/oauth/pinterest/start` and `/oauth/pinterest/callback`
 * handlers; itself has no WordPress dependency, so it's fully unit-testable
 * with a mocked Guzzle client.
 */
final class PinterestOAuth {

	private const AUTH_URL_BASE = 'https://www.pinterest.com/oauth/';
	private const TOKEN_URL     = 'https://api.pinterest.com/v5/oauth/token';
	private const PLATFORM_ID   = 'pinterest';

	/**
	 * OAuth scopes required to read boards, publish pins, and identify the user.
	 *
	 * @var string[]
	 */
	public const SCOPES = array( 'boards:read', 'pins:write', 'user_accounts:read' );

	/**
	 * HTTP client.
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Pinterest app client id (public).
	 *
	 * @var string
	 */
	private string $client_id;

	/**
	 * Pinterest app client secret.
	 *
	 * @var string
	 */
	private string $client_secret;

	/**
	 * Constructor.
	 *
	 * @param string               $client_id     Pinterest app client id.
	 * @param string               $client_secret Pinterest app client secret.
	 * @param ClientInterface|null $client        Optional Guzzle client override for tests.
	 */
	public function __construct( string $client_id, string $client_secret, ?ClientInterface $client = null ) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->client        = $client ?? new Client( array( 'timeout' => 30 ) );
	}

	/**
	 * True when both the client id and client secret are non-empty.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->client_id && '' !== $this->client_secret;
	}

	/**
	 * Build the URL the user's browser must navigate to in order to authorize the app.
	 *
	 * @param string $redirect_uri Where Pinterest should send the user back to.
	 * @param string $state        CSRF token from the state store.
	 * @return string
	 */
	public function build_auth_url( string $redirect_uri, string $state ): string {
		$params = array(
			'client_id'     => $this->client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => implode( ',', self::SCOPES ),
			'state'         => $state,
		);
		return self::AUTH_URL_BASE . '?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for an access + refresh token pair.
	 *
	 * @param string $code         The authorization code from the callback's `?code=` param.
	 * @param string $redirect_uri The exact `redirect_uri` used in the original auth request.
	 * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
	 *
	 * @throws PublisherException When the token endpoint returns an error or a malformed body.
	 */
	public function exchange_code( string $code, string $redirect_uri ): array {
		try {
			$response = $this->client->request(
				'POST',
				self::TOKEN_URL,
				array(
					'auth'        => array( $this->client_id, $this->client_secret ),
					'form_params' => array(
						'grant_type'   => 'authorization_code',
						'code'         => $code,
						'redirect_uri' => $redirect_uri,
					),
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			);
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

		$data = json_decode( (string) $response->getBody(), true );
		if ( ! is_array( $data ) || ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'Token exchange response missing access_token.' );
		}

		return array(
			'access_token'  => (string) $data['access_token'],
			'refresh_token' => isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '',
			'expires_in'    => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 0,
			'scope'         => isset( $data['scope'] ) ? (string) $data['scope'] : '',
		);
	}

	/**
	 * Map an HTTP status to a publisher exception shape.
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
}
