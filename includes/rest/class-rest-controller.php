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
use ApexChute\ApexCast\Adapters\BackendAdapterException;
use ApexChute\ApexCast\Adapters\PostPayload;
use ApexChute\ApexCast\Plugin;
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
			'/integrations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'integrations' ),
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
			'/webhook/(?P<adapter>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook' ),
				// Public on purpose — signature validation lives inside the handler (v0.2+).
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
	 * POST /send — queue a multi-platform post via the configured backend.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function send( WP_REST_Request $request ): WP_REST_Response {
		$plugin  = Plugin::instance();
		$adapter = $plugin->backend_adapter();
		if ( null === $adapter ) {
			return $this->error( 'no_backend', 'No backend is configured.', 400 );
		}

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

		$post_type    = $this->coerce_post_type( $request['post_type'] ?? PostPayload::TYPE_DRAFT );
		$scheduled_at = isset( $request['scheduled_at'] ) ? (string) $request['scheduled_at'] : null;

		$integration_map = $this->coerce_integration_map(
			$plugin->settings()->get( 'backend.postiz.integration_map', array() )
		);

		$payload = new PostPayload(
			$product_id,
			$platforms,
			$drafts_raw,
			$integration_map,
			array(),
			$post_type,
			$scheduled_at
		);

		$repo   = $plugin->job_repository();
		$job_id = $repo->create(
			$product_id,
			get_current_user_id(),
			$adapter->get_adapter_id(),
			$platforms,
			$drafts_raw
		);

		try {
			$result = $adapter->queue_post( $payload );
			$repo->update_status( $job_id, $result->status, $result->backend_post_id, $result->platform_results );
			update_post_meta( $product_id, '_apex_cast_last_sent_at', time() );
			update_post_meta( $product_id, '_apex_cast_last_job_id', $job_id );

			return $this->ok(
				array(
					'job_id'          => $job_id,
					'status'          => $result->status,
					'backend_post_id' => $result->backend_post_id,
				)
			);
		} catch ( BackendAdapterException $e ) {
			$repo->update_status( $job_id, 'failed' );
			$plugin->logger()->error(
				'rest.send',
				$e->getMessage(),
				array( 'product_id' => $product_id ),
				$job_id
			);
			return $this->error( 'backend_failed', $e->getMessage(), 502 );
		}
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
	 * POST /test-connection — ping the configured provider/adapter.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$which = (string) ( $request['provider_type'] ?? 'ai' );

		if ( 'ai' === $which ) {
			$provider = Plugin::instance()->ai_provider();
			if ( null === $provider ) {
				return $this->error( 'not_configured', 'AI provider is not configured.', 400 );
			}
			return $this->ok( $provider->test_connection()->to_array() );
		}

		if ( 'backend' === $which ) {
			$adapter = Plugin::instance()->backend_adapter();
			if ( null === $adapter ) {
				return $this->error( 'not_configured', 'Backend is not configured.', 400 );
			}
			return $this->ok( $adapter->test_connection()->to_array() );
		}

		return $this->error( 'invalid_type', 'provider_type must be "ai" or "backend".', 400 );
	}

	/**
	 * GET /integrations — proxy the backend's connected channels.
	 *
	 * @return WP_REST_Response
	 */
	public function integrations(): WP_REST_Response {
		$adapter = Plugin::instance()->backend_adapter();
		if ( null === $adapter ) {
			return $this->error( 'not_configured', 'Backend is not configured.', 400 );
		}
		try {
			$list    = $adapter->fetch_integrations();
			$payload = array();
			foreach ( $list as $info ) {
				$payload[] = $info->to_array();
			}
			return $this->ok( array( 'integrations' => $payload ) );
		} catch ( BackendAdapterException $e ) {
			return $this->error( 'backend_failed', $e->getMessage(), 502 );
		}
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
	 * POST /webhook/:adapter — endpoint for backend status callbacks.
	 *
	 * Stub for v0.1: signature validation and payload routing arrive in v0.2.
	 * Returning 200 keeps Postiz from retrying needlessly during initial setup.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function webhook( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->ok( array( 'received' => true ) );
	}

	/**
	 * Strip encrypted-secret fields from a settings tree for safe transport to the browser.
	 *
	 * @param array<string, mixed> $settings Full settings tree.
	 * @return array<string, mixed>
	 */
	private function redact_secrets( array $settings ): array {
		if ( isset( $settings['ai_provider']['anthropic'] ) && is_array( $settings['ai_provider']['anthropic'] ) ) {
			$encrypted = $settings['ai_provider']['anthropic']['api_key_encrypted'] ?? '';
			$settings['ai_provider']['anthropic']['api_key_set'] = is_string( $encrypted ) && '' !== $encrypted;
			unset( $settings['ai_provider']['anthropic']['api_key_encrypted'] );
		}
		if ( isset( $settings['backend']['postiz'] ) && is_array( $settings['backend']['postiz'] ) ) {
			$encrypted                                    = $settings['backend']['postiz']['api_key_encrypted'] ?? '';
			$settings['backend']['postiz']['api_key_set'] = is_string( $encrypted ) && '' !== $encrypted;
			unset( $settings['backend']['postiz']['api_key_encrypted'] );
		}
		return $settings;
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

		if ( isset( $incoming['backend']['postiz'] ) && is_array( $incoming['backend']['postiz'] ) ) {
			$p = $incoming['backend']['postiz'];
			if ( isset( $p['api_key'] ) && is_string( $p['api_key'] ) && '' !== $p['api_key'] ) {
				$out['backend']['postiz']['api_key_encrypted'] = $store->encrypt_secret( $p['api_key'] );
			}
			if ( isset( $p['api_url'] ) ) {
				$out['backend']['postiz']['api_url'] = esc_url_raw( (string) $p['api_url'] );
			}
			if ( isset( $p['default_post_type'] ) ) {
				$type = (string) $p['default_post_type'];
				if ( in_array( $type, array( 'now', 'schedule', 'draft' ), true ) ) {
					$out['backend']['postiz']['default_post_type'] = $type;
				}
			}
			if ( isset( $p['integration_map'] ) && is_array( $p['integration_map'] ) ) {
				$map = array();
				foreach ( $p['integration_map'] as $platform => $integration_id ) {
					if ( is_string( $platform ) && is_string( $integration_id ) ) {
						$map[ sanitize_key( $platform ) ] = sanitize_text_field( $integration_id );
					}
				}
				$out['backend']['postiz']['integration_map'] = $map;
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
	 * Coerce a post_type input to a known constant.
	 *
	 * @param mixed $raw User-supplied post_type input.
	 * @return string One of PostPayload::TYPE_*.
	 */
	private function coerce_post_type( mixed $raw ): string {
		$value = is_string( $raw ) ? $raw : PostPayload::TYPE_DRAFT;
		if ( ! in_array( $value, array( PostPayload::TYPE_NOW, PostPayload::TYPE_SCHEDULE, PostPayload::TYPE_DRAFT ), true ) ) {
			return PostPayload::TYPE_DRAFT;
		}
		return $value;
	}

	/**
	 * Coerce the stored integration map to `array<string, string>`.
	 *
	 * @param mixed $raw Stored value from settings.
	 * @return array<string, string>
	 */
	private function coerce_integration_map( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $platform => $integration_id ) {
			if ( is_string( $platform ) && is_string( $integration_id ) ) {
				$out[ $platform ] = $integration_id;
			}
		}
		return $out;
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
