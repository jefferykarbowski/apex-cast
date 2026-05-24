<?php
/**
 * REST controller — registers and handles every `/wp-json/apex-cast/v1/*` route.
 *
 * @package ApexChute\ApexCast\Rest
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Rest;

use ApexChute\ApexCast\OAuth\MetaOAuth;
use ApexChute\ApexCast\OAuth\OAuthStateStore;
use ApexChute\ApexCast\OAuth\PinterestOAuth;
use ApexChute\ApexCast\Plugin;
use ApexChute\ApexCast\Publishers\PublishRequest;
use ApexChute\ApexCast\Publishers\PublisherException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers every Apex Cast REST endpoint listed in SPEC §7 and wires it to
 * the appropriate provider / adapter / repository.
 *
 * Every response follows the `{ ok: bool, data?: any, error?: { code, message } }`
 * envelope so the front-end can branch on a single shape.
 */
final class RestController {

	private const REST_NAMESPACE = 'apex-cast/v1';

	/**
	 * Register the `rest_api_init` hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register every route. Bound to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$auth = array( $this, 'permission_check' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $auth,
				'args'                => array(
					'product_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'platforms'  => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'job' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'jobs_for_product' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'post_settings' ),
					'permission_callback' => $auth,
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/(?P<platform>[a-z0-9_-]+)/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'oauth_start' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/(?P<platform>[a-z0-9_-]+)/callback',
			array(
				'methods'             => 'GET',
				// Public on purpose — Pinterest's redirect-back can't include a
				// REST nonce. The state-token check + user-id verification inside
				// the handler is the auth gate.
				'callback'            => array( $this, 'oauth_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Capability check shared by all authenticated routes.
	 *
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * POST /send — broadcast a product's short description + featured image to
	 * the requested platforms.
	 *
	 * Reads the source content (short description, image, tags, permalink)
	 * directly from the WooCommerce product on the server side — the caller
	 * doesn't pass any copy. Tags become hashtags. The same payload goes to
	 * every selected platform; each publisher handles its own truncation /
	 * formatting.
	 *
	 * Aggregates the per-platform results into one job row and one response.
	 * Overall job status is `sent` if all platforms succeeded, `partial` if
	 * some did and some didn't, `failed` if none did.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function send( WP_REST_Request $request ): WP_REST_Response {
		$plugin     = Plugin::instance();
		$product_id = (int) ( $request['product_id'] ?? 0 );
		if ( 0 === $product_id ) {
			return $this->error( 'missing_product', 'product_id is required.', 400 );
		}

		$platforms = $this->coerce_platforms( $request['platforms'] ?? array() );
		if ( empty( $platforms ) ) {
			return $this->error( 'no_platforms', 'At least one platform is required.', 400 );
		}

		$context = $plugin->product_context_builder()->build( $product_id );
		if ( null === $context ) {
			return $this->error( 'product_not_found', 'Product not found.', 404 );
		}

		// Caption: the product's short description, falling back to the title
		// when the short description is empty so we never publish a blank caption.
		$content = '' !== trim( $context->short_description )
			? $context->short_description
			: $context->title;

		$hashtags     = $this->product_tags_to_hashtags( $context->tags );
		$scheduled_at = isset( $request['scheduled_at'] ) ? (string) $request['scheduled_at'] : null;
		$registry     = $plugin->publisher_registry();
		$repo         = $plugin->job_repository();

		$snapshot = array(
			'content'  => $content,
			'hashtags' => $hashtags,
			'image'    => $context->featured_image,
			'link'     => $context->permalink,
		);
		$job_id   = $repo->create(
			$product_id,
			get_current_user_id(),
			'multi',
			$platforms,
			$snapshot
		);

		$platform_results = array();
		$success_count    = 0;
		$failure_count    = 0;

		foreach ( $platforms as $platform ) {
			$publisher = $registry->get( $platform );
			if ( null === $publisher ) {
				$platform_results[ $platform ] = array(
					'success'       => false,
					'platform'      => $platform,
					'error_message' => sprintf( 'No publisher implementation is available for "%s".', $platform ),
				);
				++$failure_count;
				continue;
			}

			if ( ! $publisher->is_configured() ) {
				$platform_results[ $platform ] = array(
					'success'       => false,
					'platform'      => $platform,
					'error_message' => sprintf( '%s is not connected. Configure it in Settings → Apex Cast.', $platform ),
				);
				++$failure_count;
				continue;
			}

			$publish_request = new PublishRequest(
				$product_id,
				$platform,
				$content,
				$hashtags,
				$context->permalink,
				$context->featured_image,
				$scheduled_at
			);

			try {
				$result                        = $publisher->publish( $publish_request );
				$platform_results[ $platform ] = $result->to_array();
				if ( $result->success ) {
					++$success_count;
				} else {
					++$failure_count;
				}
			} catch ( PublisherException $e ) {
				$plugin->logger()->error(
					'rest.send',
					$e->getMessage(),
					array(
						'product_id' => $product_id,
						'platform'   => $platform,
					),
					$job_id
				);
				$platform_results[ $platform ] = array(
					'success'       => false,
					'platform'      => $platform,
					'error_message' => $e->getMessage(),
				);
				++$failure_count;
			}
		}

		$status = 'failed';
		if ( $success_count > 0 && 0 === $failure_count ) {
			$status = 'sent';
		} elseif ( $success_count > 0 && $failure_count > 0 ) {
			$status = 'partial';
		}

		$repo->update_status( $job_id, $status, '', $platform_results );

		if ( $success_count > 0 ) {
			update_post_meta( $product_id, '_apex_cast_last_sent_at', time() );
			update_post_meta( $product_id, '_apex_cast_last_job_id', $job_id );
		}

		return $this->ok(
			array(
				'job_id'           => $job_id,
				'status'           => $status,
				'platform_results' => $platform_results,
			)
		);
	}

	/**
	 * Convert WooCommerce product tags into platform-ready hashtags.
	 *
	 * Each tag is lowercased and stripped of every non-alphanumeric character,
	 * then prefixed with '#'. Empty tags drop out. Order preserved.
	 *
	 * @param string[] $tags Raw product tag names from WooCommerce.
	 * @return string[]
	 */
	private function product_tags_to_hashtags( array $tags ): array {
		$hashtags = array();
		foreach ( $tags as $tag ) {
			$normalized = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $tag );
			if ( is_string( $normalized ) && '' !== $normalized ) {
				$hashtags[] = '#' . strtolower( $normalized );
			}
		}
		return $hashtags;
	}

	/**
	 * GET /jobs/:id — fetch a single job row.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function job( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request['id'];
		$row = Plugin::instance()->job_repository()->find( $id );
		if ( null === $row ) {
			return $this->error( 'not_found', 'Job not found.', 404 );
		}
		return $this->ok( $row );
	}

	/**
	 * GET /jobs?product_id=X — recent jobs for a product.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function jobs_for_product( WP_REST_Request $request ): WP_REST_Response {
		$product_id = (int) ( $request['product_id'] ?? 0 );
		if ( 0 === $product_id ) {
			return $this->error( 'missing_product', 'product_id is required.', 400 );
		}
		$rows = Plugin::instance()->job_repository()->recent_for_product( $product_id );
		return $this->ok( array( 'jobs' => $rows ) );
	}

	/**
	 * POST /test-connection — ping a specific platform publisher.
	 *
	 * Accepts a `target` field: one of "facebook" / "instagram" / "pinterest".
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$raw_target = $request['target'] ?? $request['provider_type'] ?? '';
		$target     = sanitize_key( (string) $raw_target );

		$publisher = Plugin::instance()->publisher_registry()->get( $target );
		if ( null === $publisher ) {
			return $this->error(
				'unknown_target',
				sprintf( 'No publisher is available for "%s".', $target ),
				400
			);
		}

		return $this->ok( $publisher->test_connection()->to_array() );
	}

	/**
	 * GET /settings — return the current settings tree with secrets redacted.
	 *
	 * Encrypted API keys are replaced with a boolean `api_key_set` so the React
	 * settings UI can show "configured" without ever seeing plaintext.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$settings = Plugin::instance()->settings()->all();
		return $this->ok( $this->redact_secrets( $settings ) );
	}

	/**
	 * POST /settings — partial update of the settings tree.
	 *
	 * Accepts the same shape returned by GET /settings, plus optional plaintext
	 * `api_key` fields under `ai_provider.anthropic` and `backend.postiz`. If an
	 * `api_key` field is present and non-empty, it is encrypted and stored; if
	 * absent or empty, the existing encrypted value is preserved.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function post_settings( WP_REST_Request $request ): WP_REST_Response {
		$incoming = $request->get_json_params();
		if ( ! is_array( $incoming ) ) {
			return $this->error( 'invalid_body', 'JSON body required.', 400 );
		}

		$store    = Plugin::instance()->settings();
		$existing = $store->all();
		$merged   = $this->merge_settings( $existing, $incoming );
		$store->save( $merged );

		return $this->ok( $this->redact_secrets( $merged ) );
	}

	/**
	 * POST /oauth/{platform}/start — initiate an OAuth 2.0 flow.
	 *
	 * Issues a state token, builds the platform's auth URL, and returns it for
	 * the browser to navigate to. Phase 6a supports Pinterest only.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function oauth_start( WP_REST_Request $request ): WP_REST_Response {
		$platform = sanitize_key( (string) $request['platform'] );

		if ( 'pinterest' === $platform ) {
			$oauth = $this->build_pinterest_oauth();
			if ( null === $oauth ) {
				return $this->error(
					'not_configured',
					'Pinterest app credentials are not set. Define APEX_CAST_PINTEREST_CLIENT_ID and APEX_CAST_PINTEREST_CLIENT_SECRET in wp-config.php.',
					400
				);
			}
		} elseif ( 'facebook' === $platform ) {
			$oauth = $this->build_meta_oauth();
			if ( null === $oauth ) {
				return $this->error(
					'not_configured',
					'Meta app credentials are not set. Define APEX_CAST_META_APP_ID and APEX_CAST_META_APP_SECRET in wp-config.php.',
					400
				);
			}
		} else {
			return $this->error(
				'unsupported_platform',
				sprintf( 'OAuth is not yet implemented for "%s".', $platform ),
				400
			);
		}

		$state_store = new OAuthStateStore();
		$state       = $state_store->create( $platform, get_current_user_id() );
		$auth_url    = $oauth->build_auth_url( $this->oauth_callback_url( $platform ), $state );

		return $this->ok( array( 'auth_url' => $auth_url ) );
	}

	/**
	 * GET /oauth/{platform}/callback — receive the OAuth redirect, exchange the code,
	 * store the resulting tokens, and bounce the user back to the settings page.
	 *
	 * This is a browser-driven endpoint (Pinterest issues a 302 to it); the only
	 * auth gate is the state-token + user-id check, since the browser can't send
	 * a REST nonce on a cross-site redirect.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return void
	 */
	public function oauth_callback( WP_REST_Request $request ): void {
		$platform     = sanitize_key( (string) $request['platform'] );
		$code         = (string) ( $request['code'] ?? '' );
		$state        = (string) ( $request['state'] ?? '' );
		$provider_err = (string) ( $request['error'] ?? '' );

		$settings_url = admin_url( 'options-general.php?page=apex-cast-settings' );

		if ( 'pinterest' !== $platform && 'facebook' !== $platform ) {
			$this->finish_oauth_callback( $settings_url, $platform, 'unsupported_platform' );
		}

		if ( '' !== $provider_err ) {
			Plugin::instance()->logger()->warn(
				'rest.oauth_callback',
				'Provider returned an error.',
				array(
					'platform' => $platform,
					'error'    => $provider_err,
				)
			);
			$this->finish_oauth_callback( $settings_url, $platform, 'provider_error' );
		}

		if ( '' === $code || '' === $state ) {
			$this->finish_oauth_callback( $settings_url, $platform, 'missing_params' );
		}

		$state_store = new OAuthStateStore();
		$state_data  = $state_store->consume( $state, $platform );
		if ( null === $state_data ) {
			$this->finish_oauth_callback( $settings_url, $platform, 'invalid_state' );
		}
		if ( get_current_user_id() !== $state_data['user_id'] ) {
			$this->finish_oauth_callback( $settings_url, $platform, 'user_mismatch' );
		}

		if ( 'pinterest' === $platform ) {
			$this->handle_pinterest_callback( $settings_url, $code );
		} else {
			$this->handle_facebook_callback( $settings_url, $code );
		}
	}

	/**
	 * Pinterest-specific arm of the OAuth callback. Exchanges the auth code,
	 * stores the encrypted tokens, redirects back to the settings page.
	 *
	 * @param string $settings_url Settings-page URL for the redirect.
	 * @param string $code         Authorization code.
	 * @return void
	 */
	private function handle_pinterest_callback( string $settings_url, string $code ): void {
		$oauth = $this->build_pinterest_oauth();
		if ( null === $oauth ) {
			$this->finish_oauth_callback( $settings_url, 'pinterest', 'not_configured' );
		}

		try {
			$tokens = $oauth->exchange_code( $code, $this->oauth_callback_url( 'pinterest' ) );
		} catch ( PublisherException $e ) {
			Plugin::instance()->logger()->error( 'rest.oauth_callback', $e->getMessage(), array( 'platform' => 'pinterest' ) );
			$this->finish_oauth_callback( $settings_url, 'pinterest', 'token_exchange_failed' );
		}

		$settings_store = Plugin::instance()->settings();
		$current        = $settings_store->all();
		if ( ! isset( $current['platforms']['pinterest'] ) || ! is_array( $current['platforms']['pinterest'] ) ) {
			$current['platforms']['pinterest'] = array();
		}

		$current['platforms']['pinterest']['access_token_encrypted'] = $settings_store->encrypt_secret( $tokens['access_token'] );
		if ( '' !== $tokens['refresh_token'] ) {
			$current['platforms']['pinterest']['refresh_token_encrypted'] = $settings_store->encrypt_secret( $tokens['refresh_token'] );
		}
		$current['platforms']['pinterest']['expires_at'] = $tokens['expires_in'] > 0 ? time() + $tokens['expires_in'] : 0;

		$settings_store->save( $current );
		Plugin::instance()->logger()->info( 'rest.oauth_callback', 'OAuth completed.', array( 'platform' => 'pinterest' ) );
		$this->finish_oauth_callback( $settings_url, 'pinterest', 'success' );
	}

	/**
	 * Facebook (+ linked Instagram) arm of the OAuth callback. Runs the Meta
	 * multi-step flow: code → short-lived UAT → long-lived UAT → Pages list →
	 * IG account, then stores everything under `platforms.facebook.*` and
	 * `platforms.instagram.*`.
	 *
	 * For Loren's single-Page case we auto-pick the first Page in the list.
	 * Future iteration: present a Page picker when the user has more than one.
	 *
	 * @param string $settings_url Settings-page URL for the redirect.
	 * @param string $code         Authorization code.
	 * @return void
	 */
	private function handle_facebook_callback( string $settings_url, string $code ): void {
		$oauth = $this->build_meta_oauth();
		if ( null === $oauth ) {
			$this->finish_oauth_callback( $settings_url, 'facebook', 'not_configured' );
		}

		try {
			$short_lived = $oauth->exchange_code_for_user_token( $code, $this->oauth_callback_url( 'facebook' ) );
			$long_lived  = $oauth->exchange_for_long_lived_token( $short_lived );
			$pages       = $oauth->fetch_pages( $long_lived['access_token'] );
		} catch ( PublisherException $e ) {
			Plugin::instance()->logger()->error( 'rest.oauth_callback', $e->getMessage(), array( 'platform' => 'facebook' ) );
			$this->finish_oauth_callback( $settings_url, 'facebook', 'token_exchange_failed' );
		}

		if ( empty( $pages ) ) {
			Plugin::instance()->logger()->warn( 'rest.oauth_callback', 'No Facebook Pages returned for user.', array() );
			$this->finish_oauth_callback( $settings_url, 'facebook', 'no_pages' );
		}

		// Auto-pick the first Page for now; multi-Page picker UI is a future enhancement.
		$page = $pages[0];

		try {
			$ig = $oauth->fetch_instagram_account( $page['id'], $page['access_token'] );
		} catch ( PublisherException $e ) {
			Plugin::instance()->logger()->warn( 'rest.oauth_callback', 'IG lookup failed.', array( 'error' => $e->getMessage() ) );
			$ig = null;
		}

		$settings_store = Plugin::instance()->settings();
		$current        = $settings_store->all();

		if ( ! isset( $current['platforms']['facebook'] ) || ! is_array( $current['platforms']['facebook'] ) ) {
			$current['platforms']['facebook'] = array();
		}
		$current['platforms']['facebook']['user_access_token_encrypted'] = $settings_store->encrypt_secret( $long_lived['access_token'] );
		$current['platforms']['facebook']['user_token_expires_at']       = $long_lived['expires_in'] > 0 ? time() + $long_lived['expires_in'] : 0;
		$current['platforms']['facebook']['page_id']                     = $page['id'];
		$current['platforms']['facebook']['page_name']                   = $page['name'];
		$current['platforms']['facebook']['page_access_token_encrypted'] = $settings_store->encrypt_secret( $page['access_token'] );

		if ( ! isset( $current['platforms']['instagram'] ) || ! is_array( $current['platforms']['instagram'] ) ) {
			$current['platforms']['instagram'] = array();
		}
		if ( null !== $ig ) {
			$current['platforms']['instagram']['ig_business_account_id']      = $ig['id'];
			$current['platforms']['instagram']['username']                    = $ig['username'];
			$current['platforms']['instagram']['page_access_token_encrypted'] = $settings_store->encrypt_secret( $page['access_token'] );
		} else {
			// No IG linked to this Page — clear any stale IG creds so the
			// publisher correctly reports as not-configured.
			$current['platforms']['instagram']['ig_business_account_id']      = '';
			$current['platforms']['instagram']['username']                    = '';
			$current['platforms']['instagram']['page_access_token_encrypted'] = '';
		}

		$settings_store->save( $current );
		Plugin::instance()->logger()->info(
			'rest.oauth_callback',
			'Meta OAuth completed.',
			array(
				'page_id'       => $page['id'],
				'has_instagram' => null !== $ig,
			)
		);

		$this->finish_oauth_callback( $settings_url, 'facebook', 'success' );
	}

	/**
	 * Build the publicly-routable URL Pinterest should redirect back to.
	 *
	 * @param string $platform Platform identifier (becomes part of the path).
	 * @return string
	 */
	private function oauth_callback_url( string $platform ): string {
		return rest_url( sprintf( 'apex-cast/v1/oauth/%s/callback', $platform ) );
	}

	/**
	 * Build a PinterestOAuth instance from wp-config constants. Returns null
	 * when either credential constant is missing or empty.
	 *
	 * @return PinterestOAuth|null
	 */
	private function build_pinterest_oauth(): ?PinterestOAuth {
		$client_id     = defined( 'APEX_CAST_PINTEREST_CLIENT_ID' )
			? (string) constant( 'APEX_CAST_PINTEREST_CLIENT_ID' )
			: '';
		$client_secret = defined( 'APEX_CAST_PINTEREST_CLIENT_SECRET' )
			? (string) constant( 'APEX_CAST_PINTEREST_CLIENT_SECRET' )
			: '';

		if ( '' === $client_id || '' === $client_secret ) {
			return null;
		}

		return new PinterestOAuth( $client_id, $client_secret );
	}

	/**
	 * Build a MetaOAuth instance from wp-config constants. Returns null when
	 * either credential constant is missing or empty.
	 *
	 * @return MetaOAuth|null
	 */
	private function build_meta_oauth(): ?MetaOAuth {
		$app_id     = defined( 'APEX_CAST_META_APP_ID' )
			? (string) constant( 'APEX_CAST_META_APP_ID' )
			: '';
		$app_secret = defined( 'APEX_CAST_META_APP_SECRET' )
			? (string) constant( 'APEX_CAST_META_APP_SECRET' )
			: '';

		if ( '' === $app_id || '' === $app_secret ) {
			return null;
		}

		return new MetaOAuth( $app_id, $app_secret );
	}

	/**
	 * Send the user back to the settings page with a result code and stop the request.
	 *
	 * Used by every termination branch of `oauth_callback`; declared here so the
	 * `wp_safe_redirect + exit` pattern lives in one place.
	 *
	 * @param string $settings_url Base settings-page URL.
	 * @param string $platform     Platform identifier.
	 * @param string $result       Result code: 'success' or one of the failure reasons.
	 * @return never
	 */
	private function finish_oauth_callback( string $settings_url, string $platform, string $result ): void {
		$target = add_query_arg(
			array(
				'apex_cast_oauth' => $result,
				'platform'        => $platform,
			),
			$settings_url
		);
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Strip encrypted-secret fields from a settings tree for safe transport to the browser.
	 *
	 * @param array<string, mixed> $settings Full settings tree.
	 * @return array<string, mixed>
	 */
	private function redact_secrets( array $settings ): array {
		if ( isset( $settings['ai_provider']['anthropic'] ) && is_array( $settings['ai_provider']['anthropic'] ) ) {
			$settings['ai_provider']['anthropic'] = $this->redact_section( $settings['ai_provider']['anthropic'] );
		}

		if ( isset( $settings['platforms'] ) && is_array( $settings['platforms'] ) ) {
			foreach ( $settings['platforms'] as $platform_id => $platform_config ) {
				if ( is_array( $platform_config ) ) {
					$settings['platforms'][ $platform_id ] = $this->redact_section( $platform_config );
				}
			}
		}

		return $settings;
	}

	/**
	 * Redact a single settings sub-array: any field ending in `_encrypted` is
	 * replaced with a `_set` boolean indicating whether it's populated.
	 *
	 * @param array<string, mixed> $section Sub-array to redact in place.
	 * @return array<string, mixed>
	 */
	private function redact_section( array $section ): array {
		foreach ( $section as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( '_encrypted' !== substr( $key, -10 ) ) {
				continue;
			}
			$base                      = substr( $key, 0, -10 );
			$section[ $base . '_set' ] = is_string( $value ) && '' !== $value;
			unset( $section[ $key ] );
		}
		return $section;
	}

	/**
	 * Apply an incoming settings patch on top of the existing tree, with sanitization
	 * and (where applicable) encryption of plaintext API keys.
	 *
	 * @param array<string, mixed> $existing Currently stored settings.
	 * @param array<string, mixed> $incoming Caller-supplied partial update.
	 * @return array<string, mixed>
	 */
	private function merge_settings( array $existing, array $incoming ): array {
		$store = Plugin::instance()->settings();
		$out   = $existing;

		if ( isset( $incoming['store'] ) && is_array( $incoming['store'] ) ) {
			$store_in = $incoming['store'];
			if ( isset( $store_in['name'] ) ) {
				$out['store']['name'] = sanitize_text_field( (string) $store_in['name'] );
			}
			if ( isset( $store_in['description'] ) ) {
				$out['store']['description'] = sanitize_textarea_field( (string) $store_in['description'] );
			}
			if ( isset( $store_in['default_platforms'] ) && is_array( $store_in['default_platforms'] ) ) {
				$cleaned = array();
				foreach ( $store_in['default_platforms'] as $value ) {
					$slug = sanitize_key( (string) $value );
					if ( '' !== $slug ) {
						$cleaned[] = $slug;
					}
				}
				$out['store']['default_platforms'] = array_values( array_unique( $cleaned ) );
			}
		}

		if ( isset( $incoming['brand_voice'] ) && is_array( $incoming['brand_voice'] ) ) {
			$bv = $incoming['brand_voice'];
			if ( isset( $bv['tone'] ) ) {
				$out['brand_voice']['tone'] = sanitize_text_field( (string) $bv['tone'] );
			}
			if ( isset( $bv['voice_notes'] ) ) {
				$out['brand_voice']['voice_notes'] = sanitize_textarea_field( (string) $bv['voice_notes'] );
			}
			if ( isset( $bv['hashtag_strategy'] ) ) {
				$strategy = (string) $bv['hashtag_strategy'];
				if ( in_array( $strategy, array( 'sparse', 'moderate', 'heavy' ), true ) ) {
					$out['brand_voice']['hashtag_strategy'] = $strategy;
				}
			}
			if ( isset( $bv['do_not_use'] ) && is_array( $bv['do_not_use'] ) ) {
				$cleaned = array();
				foreach ( $bv['do_not_use'] as $value ) {
					$item = sanitize_text_field( (string) $value );
					if ( '' !== $item ) {
						$cleaned[] = $item;
					}
				}
				$out['brand_voice']['do_not_use'] = array_values( $cleaned );
			}
		}

		if ( isset( $incoming['ai_provider']['anthropic'] ) && is_array( $incoming['ai_provider']['anthropic'] ) ) {
			$an = $incoming['ai_provider']['anthropic'];
			if ( isset( $an['api_key'] ) && is_string( $an['api_key'] ) && '' !== $an['api_key'] ) {
				$out['ai_provider']['anthropic']['api_key_encrypted'] = $store->encrypt_secret( $an['api_key'] );
			}
			if ( isset( $an['model'] ) ) {
				$out['ai_provider']['anthropic']['model'] = sanitize_text_field( (string) $an['model'] );
			}
			if ( isset( $an['max_tokens'] ) ) {
				$out['ai_provider']['anthropic']['max_tokens'] = max( 1, (int) $an['max_tokens'] );
			}
		}

		if ( isset( $incoming['platforms'] ) && is_array( $incoming['platforms'] ) ) {
			foreach ( $incoming['platforms'] as $platform_id => $platform_in ) {
				if ( ! is_string( $platform_id ) || ! is_array( $platform_in ) ) {
					continue;
				}
				$platform_id = sanitize_key( $platform_id );

				if ( ! isset( $out['platforms'][ $platform_id ] ) || ! is_array( $out['platforms'][ $platform_id ] ) ) {
					$out['platforms'][ $platform_id ] = array();
				}

				foreach ( $platform_in as $key => $value ) {
					if ( ! is_string( $key ) ) {
						continue;
					}

					// Read-only redacted fields the client may have echoed back.
					if ( '_set' === substr( $key, -4 ) ) {
						continue;
					}

					$encrypted_key        = $key . '_encrypted';
					$has_encrypted_target = array_key_exists( $encrypted_key, $out['platforms'][ $platform_id ] );

					// Secrets: client sends plaintext under the bare name; we encrypt to *_encrypted.
					if ( $has_encrypted_target ) {
						if ( is_string( $value ) && '' !== $value ) {
							$out['platforms'][ $platform_id ][ $encrypted_key ] = $store->encrypt_secret( $value );
						} elseif ( null === $value ) {
							$out['platforms'][ $platform_id ][ $encrypted_key ] = '';
						}
						// Empty string = "no change" — preserve existing ciphertext.
						continue;
					}

					// Non-secret scalar fields: sanitize and store.
					if ( is_string( $value ) ) {
						$out['platforms'][ $platform_id ][ $key ] = sanitize_text_field( $value );
					} elseif ( is_int( $value ) || is_bool( $value ) ) {
						$out['platforms'][ $platform_id ][ $key ] = $value;
					} elseif ( is_array( $value ) ) {
						$out['platforms'][ $platform_id ][ $key ] = $value;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Coerce a raw "platforms" input into a clean list of non-empty strings.
	 *
	 * @param mixed $raw User-supplied platforms input.
	 * @return string[]
	 */
	private function coerce_platforms( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$cleaned = array();
		foreach ( $raw as $value ) {
			$slug = sanitize_key( (string) $value );
			if ( '' !== $slug ) {
				$cleaned[] = $slug;
			}
		}
		return array_values( array_unique( $cleaned ) );
	}

	/**
	 * Build a 200 OK envelope.
	 *
	 * @param mixed $data Payload.
	 * @return WP_REST_Response
	 */
	private function ok( mixed $data ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'   => true,
				'data' => $data,
			),
			200
		);
	}

	/**
	 * Build an error envelope at the given HTTP status.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 * @return WP_REST_Response
	 */
	private function error( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'    => false,
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}
}
