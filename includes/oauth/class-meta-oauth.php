<?php
/**
 * Meta (Facebook + Instagram) OAuth 2.0 client.
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
 * Drives the Meta OAuth flow that lets Apex Cast post to a Facebook Page and
 * the linked Instagram Business / Creator account in one go.
 *
 * Meta's flow is multi-step:
 *
 *   1. User browser → www.facebook.com/v19.0/dialog/oauth (auth screen) → callback with `code`
 *   2. Server: code + app secret  → short-lived User Access Token (~1 hour)
 *   3. Server: short-lived UAT    → long-lived User Access Token (~60 days)
 *   4. Server: long-lived UAT     → list of Pages with Page Access Tokens (never expire for that Page)
 *   5. Server: page-id + page-token → connected Instagram Business Account id
 *
 * The Page Access Token returned by step 4 is the credential the
 * FacebookPagePublisher and InstagramPublisher use; both run against the same
 * token because IG publishing for a linked account flows through the Page.
 *
 * Pure HTTP work — Guzzle MockHandler-mockable, no WordPress dependency.
 */
final class MetaOAuth {

	private const AUTH_URL_BASE = 'https://www.facebook.com/v19.0/dialog/oauth';
	private const GRAPH_BASE    = 'https://graph.facebook.com/v19.0';
	private const PLATFORM_ID   = 'facebook';

	/**
	 * Scopes the plugin asks for. Enough to enumerate Pages, post to a Page,
	 * and publish to the linked Instagram Business / Creator account.
	 *
	 * @var string[]
	 */
	public const SCOPES = array(
		'pages_show_list',
		'pages_manage_posts',
		'pages_read_engagement',
		'instagram_basic',
		'instagram_content_publish',
		'business_management',
	);

	/**
	 * HTTP client.
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Meta app id.
	 *
	 * @var string
	 */
	private string $app_id;

	/**
	 * Meta app secret.
	 *
	 * @var string
	 */
	private string $app_secret;

	/**
	 * Constructor.
	 *
	 * @param string               $app_id     Meta App ID.
	 * @param string               $app_secret Meta App Secret.
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
	 * @param string $redirect_uri Where Meta should send the user back to.
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
	 * Exchange an authorization code for a *short-lived* User Access Token.
	 *
	 * @param string $code         Authorization code from the callback `?code=` param.
	 * @param string $redirect_uri The exact redirect_uri used in the auth request.
	 * @return string The short-lived user access token.
	 *
	 * @throws PublisherException When the call fails or the response is malformed.
	 */
	public function exchange_code_for_user_token( string $code, string $redirect_uri ): string {
		$data = $this->graph_get(
			'/oauth/access_token',
			array(
				'client_id'     => $this->app_id,
				'client_secret' => $this->app_secret,
				'redirect_uri'  => $redirect_uri,
				'code'          => $code,
			)
		);
		if ( ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			throw PublisherException::malformed_response( self::PLATFORM_ID, 'Code exchange response was missing access_token.' );
		}
		return (string) $data['access_token'];
	}

	/**
	 * Swap a short-lived User Access Token for a *long-lived* one (~60 days).
	 *
	 * @param string $short_lived_token Short-lived UAT from `exchange_code_for_user_token()`.
	 * @return array{access_token: string, expires_in: int}
	 *
	 * @throws PublisherException When the call fails or the response is malformed.
	 */
	public function exchange_for_long_lived_token( string $short_lived_token ): array {
		$data = $this->graph_get(
			'/oauth/access_token',
			array(
				'grant_type'        => 'fb_exchange_token',
				'client_id'         => $this->app_id,
				'client_secret'     => $this->app_secret,
				'fb_exchange_token' => $short_lived_token,
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
	 * List the Pages a User Access Token can manage, with each Page's own Page Access Token.
	 *
	 * @param string $user_access_token Long-lived UAT.
	 * @return array<int, array{id: string, name: string, access_token: string, category: string}>
	 *
	 * @throws PublisherException When the call fails or the response is malformed.
	 */
	public function fetch_pages( string $user_access_token ): array {
		$data = $this->graph_get(
			'/me/accounts',
			array(
				'access_token' => $user_access_token,
				'fields'       => 'id,name,access_token,category',
			)
		);
		$rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['name'], $row['access_token'] ) ) {
				continue;
			}
			$out[] = array(
				'id'           => (string) $row['id'],
				'name'         => (string) $row['name'],
				'access_token' => (string) $row['access_token'],
				'category'     => isset( $row['category'] ) ? (string) $row['category'] : '',
			);
		}
		return $out;
	}

	/**
	 * Fetch the Instagram Business / Creator account linked to a Facebook Page.
	 *
	 * @param string $page_id           Facebook Page id.
	 * @param string $page_access_token Page Access Token for that page.
	 * @return array{id: string, username: string}|null Null when the Page has no linked IG account.
	 *
	 * @throws PublisherException When the call fails.
	 */
	public function fetch_instagram_account( string $page_id, string $page_access_token ): ?array {
		$data = $this->graph_get(
			'/' . rawurlencode( $page_id ),
			array(
				'fields'       => 'instagram_business_account{id,username}',
				'access_token' => $page_access_token,
			)
		);
		if ( ! isset( $data['instagram_business_account'] ) || ! is_array( $data['instagram_business_account'] ) ) {
			return null;
		}
		$ig = $data['instagram_business_account'];
		if ( ! isset( $ig['id'] ) ) {
			return null;
		}
		return array(
			'id'       => (string) $ig['id'],
			'username' => isset( $ig['username'] ) ? (string) $ig['username'] : '',
		);
	}

	/**
	 * Issue an authenticated GET to a Graph API endpoint and decode the JSON body.
	 *
	 * @param string               $path  Path under the Graph base (must start with '/').
	 * @param array<string, mixed> $query Query-string parameters.
	 * @return array<string, mixed>
	 *
	 * @throws PublisherException On HTTP failure or non-JSON body.
	 */
	private function graph_get( string $path, array $query ): array {
		try {
			$response = $this->client->request(
				'GET',
				self::GRAPH_BASE . $path,
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
