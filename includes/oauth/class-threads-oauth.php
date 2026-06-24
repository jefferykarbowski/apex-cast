<?php
/**
 * Threads OAuth 2.0 client.
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
 * Drives the Threads OAuth flow.
 *
 * Threads is a Meta product but a wholly separate OAuth client from the
 * Facebook/Instagram one: its own Threads App ID/secret, threads.net auth host,
 * graph.threads.net API host, and its own scopes. Mirrors `PinterestOAuth`'s
 * shape (separate-app OAuth with its own wp-config credentials).
 *
 * Flow:
 *
 *   1. User browser → threads.net/oauth/authorize (auth screen) → callback with `code`
 *   2. Server: code + secret  → short-lived token + the numeric Threads user_id
 *   3. Server: short-lived     → long-lived token (~60 days)
 *   4. (later) long-lived      → refreshed long-lived token (must be ≥24h old)
 *
 * The user_id captured in step 2 is the publishing target. The OAuth/token
 * endpoints are UNVERSIONED (graph.threads.net/oauth/... and
 * graph.threads.net/access_token); only the publishing API is versioned.
 *
 * Pure HTTP work — Guzzle MockHandler-mockable, no WordPress dependency.
 */
final class ThreadsOAuth {

	private const AUTH_URL_BASE  = 'https://threads.net/oauth/authorize';
	private const TOKEN_URL      = 'https://graph.threads.net/oauth/access_token';
	private const LONG_LIVED_URL = 'https://graph.threads.net/access_token';
	private const REFRESH_URL    = 'https://graph.threads.net/refresh_access_token';
	private const PLATFORM_ID    = 'threads';

	/**
	 * Scopes the plugin asks for: read basic profile + publish content.
	 *
	 * @var string[]
	 */
	public const SCOPES = array(
		'threads_basic',
		'threads_content_publish',
	);

	/**
	 * HTTP client.
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Threads app id.
	 *
	 * @var string
	 */
	private string $app_id;

	/**
	 * Threads app secret.
	 *
	 * @var string
	 */
	private string $app_secret;

	/**
	 * Constructor.
	 *
	 * @param string               $app_id     Threads App ID.
	 * @param string               $app_secret Threads App Secret.
	 * @param ClientInterface|null $client     Optional Guzzle override for tests.
	 */
	public function __construct( string $app_id, string $app_secret, ?ClientInterface $client = null ) {
		$this->app_id     = $app_id;
		$this->app_secret = $app_secret;
		$this->client     = $client ?? new Client( array( 'timeout' => 30 ) );
	}

	/**
	 * True when both the app id and the app secret are non-empty.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->app_id && '' !== $this->app_secret;
	}

	/**
	 * Build the URL the user's browser must navigate to in order to authorize the app.
	 *
	 * @param string $redirect_uri Where Threads should send the user back to.
	 * @param string $state        CSRF token from the state store.
	 * @return string
	 */
	public function build_auth_url( string $redirect_uri, string $state ): string {
		$params = array(
			'client_id'     => $this->app_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => implode( ',', self::SCOPES ),
			'state'         => $state,
		);
		return self::AUTH_URL_BASE . '?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for a *short-lived* token + the Threads user id.
	 *
	 * @param string $code         Authorization code from the callback `?code=` param.
	 * @param string $redirect_uri The exact redirect_uri used in the auth request.
	 * @return array{access_token: string, user_id: string}
	 *
	 * @throws PublisherException When the call fails or the response is malformed.
	 */
	public function exchange_code_for_token( string $code, string $redirect_uri ): array {
		try {
			$response = $this->client->request(
				'POST',
				self::TOKEN_URL,
				array(
					'form_params' => array(
						'client_id'     => $this->app_id,
						'client_secret' => $this->app_secret,
						'grant_type'    => 'authorization_code',
						'redirect_uri'  => $redirect_uri,
						'code'          => $code,
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
		if (
			! is_array( $data )
			|| ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] )
			|| ! isset( $data['user_id'] )
		) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'Code exchange response was missing access_token or user_id.' );
		}

		return array(
			'access_token' => (string) $data['access_token'],
			'user_id'      => (string) $data['user_id'],
		);
	}

	/**
	 * Swap a short-lived token for a *long-lived* one (~60 days).
	 *
	 * @param string $short_token Short-lived token from `exchange_code_for_token()`.
	 * @return array{access_token: string, expires_in: int}
	 *
	 * @throws PublisherException When the call fails or the response is malformed.
	 */
	public function exchange_for_long_lived_token( string $short_token ): array {
		$data = $this->token_get(
			self::LONG_LIVED_URL,
			array(
				'grant_type'    => 'th_exchange_token',
				'client_secret' => $this->app_secret,
				'access_token'  => $short_token,
			)
		);
		if ( ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'Long-lived exchange was missing access_token.' );
		}
		return array(
			'access_token' => (string) $data['access_token'],
			'expires_in'   => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 0,
		);
	}

	/**
	 * Refresh a long-lived token (must be ≥24h old; valid up to 60 days).
	 *
	 * @param string $long_token Current long-lived token.
	 * @return array{access_token: string, expires_in: int}
	 *
	 * @throws PublisherException When the call fails or the response is malformed.
	 */
	public function refresh_long_lived_token( string $long_token ): array {
		$data = $this->token_get(
			self::REFRESH_URL,
			array(
				'grant_type'   => 'th_refresh_token',
				'access_token' => $long_token,
			)
		);
		if ( ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'Token refresh was missing access_token.' );
		}
		return array(
			'access_token' => (string) $data['access_token'],
			'expires_in'   => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 0,
		);
	}

	/**
	 * Issue an authenticated GET to a Threads token endpoint and decode the JSON body.
	 *
	 * @param string               $url   Full token endpoint URL.
	 * @param array<string, mixed> $query Query-string parameters.
	 * @return array<string, mixed>
	 *
	 * @throws PublisherException On HTTP failure or non-JSON body.
	 */
	private function token_get( string $url, array $query ): array {
		try {
			$response = $this->client->request(
				'GET',
				$url,
				array(
					'query'   => $query,
					'headers' => array( 'Accept' => 'application/json' ),
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
		if ( ! is_array( $data ) ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'Response was not valid JSON.' );
		}
		return $data;
	}

	/**
	 * Map an HTTP status to a publisher exception shape.
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
}
