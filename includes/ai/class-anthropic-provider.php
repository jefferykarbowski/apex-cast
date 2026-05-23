<?php
/**
 * Anthropic AI provider implementation.
 *
 * @package ApexChute\ApexCast\AI
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\AI;

use ApexChute\ApexCast\Support\TestConnectionResult;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Generates platform-tailored social copy by calling the Anthropic Messages API.
 *
 * Uses Guzzle for HTTP so unit tests can inject a MockHandler and exercise
 * every branch (auth failure, rate limit, malformed JSON, markdown fences,
 * happy path) without touching the network.
 */
final class AnthropicProvider implements AIProviderInterface {

	private const PROVIDER_ID        = 'anthropic';
	private const API_URL            = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION        = '2023-06-01';
	private const DEFAULT_MODEL      = 'claude-sonnet-4-6';
	private const DEFAULT_MAX_TOKENS = 1024;

	/**
	 * Platforms this provider knows how to format for.
	 */
	public const SUPPORTED_PLATFORMS = array(
		'facebook',
		'instagram',
		'pinterest',
		'threads',
		'bluesky',
		'x',
		'tiktok',
		'reddit',
	);

	/**
	 * HTTP client (Guzzle by default, injectable for tests).
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * Plaintext Anthropic API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Model identifier passed to the API.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Max tokens cap.
	 *
	 * @var int
	 */
	private int $max_tokens;

	/**
	 * Store name (injected into the system prompt).
	 *
	 * @var string
	 */
	private string $store_name;

	/**
	 * Store description (injected into the system prompt).
	 *
	 * @var string
	 */
	private string $store_description;

	/**
	 * Constructor.
	 *
	 * @param string               $api_key           Plaintext Anthropic API key.
	 * @param string               $model             Model identifier.
	 * @param int                  $max_tokens        Max tokens cap.
	 * @param string               $store_name        Store name for prompt context.
	 * @param string               $store_description Store description for prompt context.
	 * @param ClientInterface|null $client            Optional Guzzle client override (tests).
	 */
	public function __construct(
		string $api_key,
		string $model = self::DEFAULT_MODEL,
		int $max_tokens = self::DEFAULT_MAX_TOKENS,
		string $store_name = '',
		string $store_description = '',
		?ClientInterface $client = null
	) {
		$this->api_key           = $api_key;
		$this->model             = $model;
		$this->max_tokens        = $max_tokens;
		$this->store_name        = $store_name;
		$this->store_description = $store_description;
		$this->client            = $client ?? new Client( array( 'timeout' => 30 ) );
	}

	/**
	 * Generate platform-tailored drafts.
	 *
	 * @param ProductContext $product   Product data.
	 * @param string[]       $platforms Platforms to generate for.
	 * @param BrandVoice     $voice     Brand voice settings.
	 * @return GenerationResult
	 *
	 * @throws AIProviderException When the provider fails or the response is malformed.
	 */
	public function generate_drafts( ProductContext $product, array $platforms, BrandVoice $voice ): GenerationResult {
		$valid_platforms = $this->validate_platforms( $platforms );

		$body = array(
			'model'      => $this->model,
			'max_tokens' => $this->max_tokens,
			'system'     => $this->build_system_prompt( $voice ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $this->build_user_prompt( $product, $valid_platforms ),
				),
			),
		);

		$response = $this->call_api( $body );
		return $this->parse_response( $response );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function get_supported_platforms(): array {
		return self::SUPPORTED_PLATFORMS;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_provider_id(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): TestConnectionResult {
		try {
			$this->call_api(
				array(
					'model'      => $this->model,
					'max_tokens' => 16,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => 'Reply with the single word OK.',
						),
					),
				)
			);
			return TestConnectionResult::success( 'Anthropic API key is valid.' );
		} catch ( AIProviderException $e ) {
			return TestConnectionResult::failure( $e->getMessage() );
		}
	}

	/**
	 * Reduce the user-supplied platform list to a non-empty validated subset.
	 *
	 * @param string[] $platforms Caller-supplied platforms.
	 * @return string[] Validated subset; falls back to ["facebook"] if empty.
	 */
	private function validate_platforms( array $platforms ): array {
		$valid = array_values( array_intersect( self::SUPPORTED_PLATFORMS, $platforms ) );
		return empty( $valid ) ? array( 'facebook' ) : $valid;
	}

	/**
	 * Build the system prompt fed to the model. Mirrors SPEC §5.2 verbatim.
	 *
	 * @param BrandVoice $voice Brand voice config.
	 * @return string
	 */
	private function build_system_prompt( BrandVoice $voice ): string {
		$store_name = '' === $this->store_name ? 'this store' : $this->store_name;
		$tone       = '' === $voice->tone ? '(neutral)' : $voice->tone;
		$notes      = '' === $voice->voice_notes ? '(none)' : $voice->voice_notes;
		$do_not_use = empty( $voice->do_not_use ) ? '(none)' : implode( ', ', $voice->do_not_use );

		$lines = array(
			'You are a social media copywriter for ' . $store_name . '. ' . $this->store_description,
			'',
			'Brand voice:',
			'- Tone: ' . $tone,
			'- Voice notes: ' . $notes,
			'- Hashtag strategy: ' . $voice->hashtag_strategy . ' (sparse | moderate | heavy)',
			'- Avoid: ' . $do_not_use,
			'',
			"You write platform-specific copy that respects each platform's culture and constraints. You output ONLY valid JSON matching the requested schema. No prose, no markdown fences, no preamble.",
			'',
			'Platform conventions:',
			'- facebook: 1-3 sentences, conversational, can include link. Max 500 chars. Light hashtag use.',
			'- instagram: 2200 char max. Strong opener. Hashtags grouped at the end (per hashtag_strategy).',
			'- pinterest: Keyword-rich descriptive caption. Max 500 chars. Search-optimized phrasing.',
			'- threads: 500 char max. Casual, conversational, lowercase opener common.',
			'- bluesky: 300 char max. No hashtag culture - use only if essential. Direct, link-friendly.',
			'- x: 280 char max. Punchy. 1-2 hashtags max.',
			'- tiktok: Caption only (200 chars max for hook). User adds video manually.',
			'- reddit: Title + body. Title 300 chars. Body markdown-friendly. NEVER promotional-sounding.',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Build the user message — product fields + platform list + expected output schema.
	 *
	 * @param ProductContext $product   Product data.
	 * @param string[]       $platforms Validated platform list.
	 * @return string
	 */
	private function build_user_prompt( ProductContext $product, array $platforms ): string {
		$cats = empty( $product->categories ) ? '(none)' : implode( ', ', $product->categories );
		$tags = empty( $product->tags ) ? '(none)' : implode( ', ', $product->tags );

		$lines = array(
			'Generate social copy for this product across these platforms: ' . implode( ', ', $platforms ),
			'',
			'Product:',
			'- Title: ' . $product->title,
			'- Permalink: ' . $product->permalink,
			'- Short description: ' . $product->short_description,
			'- Full description (truncated): ' . $product->description_excerpt,
			'- Price: ' . $product->price,
			'- Categories: ' . $cats,
			'- Tags: ' . $tags,
			'- Stock status: ' . $product->stock_status,
			'- Featured image URL: ' . $product->featured_image,
			'',
			'Output schema (use exactly these keys, one entry per requested platform):',
			'{',
			'  "drafts": {',
			'    "<platform>": {',
			'      "content": "<the post copy>",',
			'      "hashtags": ["<#tag1>", "<#tag2>"],',
			'      "char_count": <int>',
			'    }',
			'  },',
			'  "notes": "<one short sentence about your creative angle for this product, for the human reviewer>"',
			'}',
			'',
			'Do NOT include placeholder text. Do NOT include the URL twice. Do NOT use exclamation marks unless the brand voice notes explicitly request them.',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Perform the HTTP POST to the Messages API and map errors.
	 *
	 * @param array<string, mixed> $body JSON-encodeable request body.
	 * @return ResponseInterface
	 *
	 * @throws AIProviderException On HTTP failure.
	 */
	private function call_api( array $body ): ResponseInterface {
		try {
			$response = $this->client->request(
				'POST',
				self::API_URL,
				array(
					'headers' => array(
						'x-api-key'         => $this->api_key,
						'anthropic-version' => self::API_VERSION,
						'content-type'      => 'application/json',
					),
					'json'    => $body,
				)
			);
		} catch ( RequestException $e ) {
			$response = $e->getResponse();
			if ( null === $response ) {
				throw AIProviderException::http_error( 0 );
			}
			throw $this->classify_http_error( $response->getStatusCode() );
		} catch ( GuzzleException $e ) {
			throw AIProviderException::http_error( 0 );
		}

		$status = $response->getStatusCode();
		if ( $status >= 400 ) {
			throw $this->classify_http_error( $status );
		}
		return $response;
	}

	/**
	 * Map an HTTP status to the appropriate exception shape.
	 *
	 * @param int $status HTTP status code.
	 * @return AIProviderException
	 */
	private function classify_http_error( int $status ): AIProviderException {
		if ( 401 === $status || 403 === $status ) {
			return AIProviderException::auth_failed();
		}
		if ( 429 === $status ) {
			return AIProviderException::rate_limited();
		}
		return AIProviderException::http_error( $status );
	}

	/**
	 * Parse the Anthropic Messages API response into a GenerationResult.
	 *
	 * @param ResponseInterface $response The HTTP response.
	 * @return GenerationResult
	 *
	 * @throws AIProviderException When the JSON or the drafts shape is invalid.
	 */
	private function parse_response( ResponseInterface $response ): GenerationResult {
		$body = (string) $response->getBody();
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw AIProviderException::malformed_response( 'Response was not valid JSON.' );
		}

		$content_blocks = $data['content'] ?? array();
		if ( ! is_array( $content_blocks ) || empty( $content_blocks ) ) {
			throw AIProviderException::malformed_response( 'Response had no content blocks.' );
		}

		$text = '';
		foreach ( $content_blocks as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && isset( $block['text'] ) ) {
				$text .= (string) $block['text'];
			}
		}
		if ( '' === $text ) {
			throw AIProviderException::malformed_response( 'Response contained no text content.' );
		}

		// Strip optional markdown code fences the model sometimes emits despite instructions.
		$text   = trim( $text );
		$text   = (string) preg_replace( '/^```(?:json)?\s*\n?/i', '', $text );
		$text   = (string) preg_replace( '/\n?```\s*$/', '', $text );
		$parsed = json_decode( $text, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['drafts'] ) || ! is_array( $parsed['drafts'] ) ) {
			throw AIProviderException::malformed_response( 'Model output was not the expected drafts JSON.' );
		}

		$drafts = array();
		foreach ( $parsed['drafts'] as $platform => $draft ) {
			if ( ! is_string( $platform ) || ! is_array( $draft ) ) {
				continue;
			}
			$content             = isset( $draft['content'] ) ? (string) $draft['content'] : '';
			$hashtags            = ( isset( $draft['hashtags'] ) && is_array( $draft['hashtags'] ) )
				? array_values( array_map( 'strval', $draft['hashtags'] ) )
				: array();
			$char_cnt            = isset( $draft['char_count'] ) ? (int) $draft['char_count'] : strlen( $content );
			$drafts[ $platform ] = array(
				'content'    => $content,
				'hashtags'   => $hashtags,
				'char_count' => $char_cnt,
			);
		}

		$notes = isset( $parsed['notes'] ) ? (string) $parsed['notes'] : '';

		$usage         = is_array( $data['usage'] ?? null ) ? $data['usage'] : array();
		$input_tokens  = isset( $usage['input_tokens'] ) && is_int( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0;
		$output_tokens = isset( $usage['output_tokens'] ) && is_int( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0;
		$model         = isset( $data['model'] ) ? (string) $data['model'] : $this->model;

		return new GenerationResult( $drafts, $notes, $model, $input_tokens, $output_tokens );
	}
}
