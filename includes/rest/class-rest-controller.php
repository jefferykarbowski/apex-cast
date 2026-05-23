<?php
/**
 * REST controller — registers and handles every `/wp-json/apex-cast/v1/*` route.
 *
 * @package ApexChute\ApexCast\Rest
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Rest;

use ApexChute\ApexCast\AI\AIProviderException;
use ApexChute\ApexCast\AI\BrandVoice;
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

	private const GENERATE_PER_HOUR = 60;
	private const RATE_KEY_PREFIX   = 'apex_cast_gen_rate_';

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
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate' ),
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
			'/save-drafts',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_drafts' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $auth,
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
	 * POST /generate — call the AI provider and return drafts.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function generate( WP_REST_Request $request ): WP_REST_Response {
		$plugin   = Plugin::instance();
		$provider = $plugin->ai_provider();
		if ( null === $provider ) {
			return $this->error( 'no_provider', 'No AI provider is configured.', 400 );
		}

		$product_id = (int) $request['product_id'];
		$context    = $plugin->product_context_builder()->build( $product_id );
		if ( null === $context ) {
			return $this->error( 'product_not_found', 'Product not found.', 404 );
		}

		$platforms = $this->coerce_platforms( $request['platforms'] ?? array() );
		if ( empty( $platforms ) ) {
			return $this->error( 'no_platforms', 'At least one platform is required.', 400 );
		}

		if ( $this->is_rate_limited() ) {
			return $this->error( 'rate_limited', 'Too many generation requests, please wait.', 429 );
		}

		$voice_raw = $plugin->settings()->get( 'brand_voice', array() );
		$voice     = BrandVoice::from_settings( is_array( $voice_raw ) ? $voice_raw : array() );

		try {
			$result = $provider->generate_drafts( $context, $platforms, $voice );
			$plugin->logger()->info(
				'rest.generate',
				'Generated drafts.',
				array(
					'product_id'    => $product_id,
					'platforms'     => $platforms,
					'input_tokens'  => $result->input_tokens,
					'output_tokens' => $result->output_tokens,
				)
			);
			return $this->ok( $result->to_array() );
		} catch ( AIProviderException $e ) {
			$plugin->logger()->error(
				'rest.generate',
				$e->getMessage(),
				array( 'product_id' => $product_id )
			);
			return $this->error( 'ai_provider_failed', $e->getMessage(), 502 );
		}
	}

	/**
	 * POST /save-drafts — persist user-edited drafts to post meta.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function save_drafts( WP_REST_Request $request ): WP_REST_Response {
		$product_id = (int) ( $request['product_id'] ?? 0 );
		if ( 0 === $product_id ) {
			return $this->error( 'missing_product', 'product_id is required.', 400 );
		}

		$raw_drafts = $request['drafts'] ?? array();
		if ( ! is_array( $raw_drafts ) ) {
			return $this->error( 'invalid_drafts', 'drafts must be an object keyed by platform.', 400 );
		}

		$sanitized = array();
		foreach ( $raw_drafts as $platform => $draft ) {
			if ( ! is_string( $platform ) || ! is_array( $draft ) ) {
				continue;
			}
			$hashtags                               = ( isset( $draft['hashtags'] ) && is_array( $draft['hashtags'] ) )
				? array_values( array_map( 'sanitize_text_field', array_map( 'strval', $draft['hashtags'] ) ) )
				: array();
			$sanitized[ sanitize_key( $platform ) ] = array(
				'content'  => wp_kses_post( (string) ( $draft['content'] ?? '' ) ),
				'hashtags' => $hashtags,
			);
		}

		update_post_meta( $product_id, '_apex_cast_drafts', $sanitized );

		return $this->ok(
			array(
				'product_id' => $product_id,
				'drafts'     => $sanitized,
			)
		);
	}

	/**
	 * POST /send — publish a per-platform set of drafts via the publisher registry.
	 *
	 * Dispatches each requested platform to its registered publisher. Aggregates
	 * the per-platform results into one job row and one response. Overall job
	 * status is `sent` if all platforms succeeded, `partial` if some did and
	 * some didn't, `failed` if none did.
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

		$drafts_raw = $request['drafts'] ?? array();
		if ( ! is_array( $drafts_raw ) ) {
			return $this->error( 'invalid_drafts', 'drafts must be an object keyed by platform.', 400 );
		}

		$platforms_raw = $request['platforms'] ?? array_keys( $drafts_raw );
		$platforms     = $this->coerce_platforms( $platforms_raw );
		if ( empty( $platforms ) ) {
			return $this->error( 'no_platforms', 'At least one platform is required.', 400 );
		}

		$context = $plugin->product_context_builder()->build( $product_id );
		if ( null === $context ) {
			return $this->error( 'product_not_found', 'Product not found.', 404 );
		}

		$scheduled_at = isset( $request['scheduled_at'] ) ? (string) $request['scheduled_at'] : null;
		$registry     = $plugin->publisher_registry();
		$repo         = $plugin->job_repository();

		$job_id = $repo->create(
			$product_id,
			get_current_user_id(),
			'multi',
			$platforms,
			$drafts_raw
		);

		$platform_results = array();
		$success_count    = 0;
		$failure_count    = 0;

		foreach ( $platforms as $platform ) {
			$draft = $drafts_raw[ $platform ] ?? null;
			if ( ! is_array( $draft ) ) {
				$platform_results[ $platform ] = array(
					'success'       => false,
					'platform'      => $platform,
					'error_message' => 'No draft was provided for this platform.',
				);
				++$failure_count;
				continue;
			}

			$publisher = $registry->get( $platform );
			if ( null === $publisher ) {
				$platform_results[ $platform ] = array(
					'success'       => false,
					'platform'      => $platform,
					'error_message' => sprintf( 'No publisher implementation is available yet for "%s".', $platform ),
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

			$hashtags = ( isset( $draft['hashtags'] ) && is_array( $draft['hashtags'] ) )
				? array_values( array_map( 'strval', $draft['hashtags'] ) )
				: array();

			$publish_request = new PublishRequest(
				$product_id,
				$platform,
				(string) ( $draft['content'] ?? '' ),
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
	 * POST /test-connection — ping the AI provider or a specific platform publisher.
	 *
	 * Accepts a `target` field:
	 *   - "ai" tests the active AI provider
	 *   - "facebook" / "instagram" / "pinterest" / "x" / "reddit" tests that platform's publisher
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$raw_target = $request['target'] ?? $request['provider_type'] ?? 'ai';
		$target     = sanitize_key( (string) $raw_target );

		if ( 'ai' === $target ) {
			$provider = Plugin::instance()->ai_provider();
			if ( null === $provider ) {
				return $this->error( 'not_configured', 'AI provider is not configured.', 400 );
			}
			return $this->ok( $provider->test_connection()->to_array() );
		}

		$publisher = Plugin::instance()->publisher_registry()->get( $target );
		if ( null === $publisher ) {
			return $this->error(
				'unknown_target',
				sprintf( 'No publisher is available yet for "%s".', $target ),
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

		if ( 'pinterest' !== $platform ) {
			return $this->error(
				'unsupported_platform',
				sprintf( 'OAuth is not yet implemented for "%s".', $platform ),
				400
			);
		}

		$oauth = $this->build_pinterest_oauth();
		if ( null === $oauth ) {
			return $this->error(
				'not_configured',
				'Pinterest app credentials are not set. Define APEX_CAST_PINTEREST_CLIENT_ID and APEX_CAST_PINTEREST_CLIENT_SECRET in wp-config.php.',
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
		$platform      = sanitize_key( (string) $request['platform'] );
		$code          = (string) ( $request['code'] ?? '' );
		$state         = (string) ( $request['state'] ?? '' );
		$pinterest_err = (string) ( $request['error'] ?? '' );

		$settings_url = admin_url( 'options-general.php?page=apex-cast-settings' );

		if ( 'pinterest' !== $platform ) {
			$this->finish_oauth_callback( $settings_url, $platform, 'unsupported_platform' );
		}

		if ( '' !== $pinterest_err ) {
			Plugin::instance()->logger()->warn(
				'rest.oauth_callback',
				'Provider returned an error.',
				array(
					'platform' => $platform,
					'error'    => $pinterest_err,
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

		$oauth = $this->build_pinterest_oauth();
		if ( null === $oauth ) {
			$this->finish_oauth_callback( $settings_url, $platform, 'not_configured' );
		}

		try {
			$tokens = $oauth->exchange_code( $code, $this->oauth_callback_url( $platform ) );
		} catch ( PublisherException $e ) {
			Plugin::instance()->logger()->error( 'rest.oauth_callback', $e->getMessage(), array( 'platform' => $platform ) );
			$this->finish_oauth_callback( $settings_url, $platform, 'token_exchange_failed' );
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
		Plugin::instance()->logger()->info( 'rest.oauth_callback', 'OAuth completed.', array( 'platform' => $platform ) );

		$this->finish_oauth_callback( $settings_url, $platform, 'success' );
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
	 * Per-user hourly rate limit for the /generate endpoint.
	 *
	 * @return bool True when over budget.
	 */
	private function is_rate_limited(): bool {
		$key   = self::RATE_KEY_PREFIX . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::GENERATE_PER_HOUR ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
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
