<?php
/**
 * AnthropicProvider unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\AI\AIProviderException;
use ApexChute\ApexCast\AI\AnthropicProvider;
use ApexChute\ApexCast\AI\BrandVoice;
use ApexChute\ApexCast\AI\ProductContext;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the Anthropic AI provider.
 *
 * Every test injects a Guzzle MockHandler — no real network IO. We pin the
 * provider against the contract documented in SPEC §5.2: happy path,
 * 401/429/5xx mapping, malformed JSON, and the markdown-fence stripping the
 * model occasionally needs.
 */
final class Anthropic_Provider_Test extends TestCase {

	/**
	 * Build a provider whose HTTP client is backed by the given mocked responses.
	 *
	 * @param Response[] $responses Mocked responses, in call order.
	 * @return array{0: AnthropicProvider, 1: MockHandler}
	 */
	private function provider_with_responses( array $responses ): array {
		$mock    = new MockHandler( $responses );
		$client  = new Client( array( 'handler' => HandlerStack::create( $mock ) ) );
		$provider = new AnthropicProvider(
			'test-api-key',
			'claude-sonnet-4-6',
			512,
			'Apex Chute',
			'Quality skydiving gear.',
			$client
		);
		return array( $provider, $mock );
	}

	/**
	 * Build a sample ProductContext for prompt tests.
	 */
	private function sample_product(): ProductContext {
		return new ProductContext(
			42,
			'Apex Chute 3.0',
			'https://apexchute.com/product/apex-chute-3-0',
			'A short description.',
			'A longer description, truncated.',
			'$199.00',
			array( 'Sporting Goods' ),
			array( 'skydiving' ),
			'instock',
			'https://apexchute.com/wp-content/uploads/apex.jpg'
		);
	}

	/**
	 * Anthropic-style API response body wrapping the given model text content.
	 */
	private function anthropic_response_body( string $model_text, int $input = 100, int $output = 50 ): string {
		return (string) wp_safe_json_encode_or_fallback(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $model_text,
					),
				),
				'usage'   => array(
					'input_tokens'  => $input,
					'output_tokens' => $output,
				),
				'model'   => 'claude-sonnet-4-6',
			)
		);
	}

	public function test_get_provider_id_returns_anthropic(): void {
		list( $provider, ) = $this->provider_with_responses( array() );
		$this->assertSame( 'anthropic', $provider->get_provider_id() );
	}

	public function test_supported_platforms_match_spec(): void {
		list( $provider, ) = $this->provider_with_responses( array() );
		$this->assertSame(
			array( 'facebook', 'instagram', 'pinterest', 'threads', 'bluesky', 'x', 'tiktok', 'reddit' ),
			$provider->get_supported_platforms()
		);
	}

	public function test_happy_path_returns_parsed_drafts(): void {
		$model_text = json_encode(
			array(
				'drafts' => array(
					'facebook' => array(
						'content'    => 'Apex Chute 3.0 is here.',
						'hashtags'   => array( '#skydive' ),
						'char_count' => 23,
					),
				),
				'notes'  => 'Product-led, casual.',
			)
		);
		list( $provider, ) = $this->provider_with_responses(
			array( new Response( 200, array(), $this->anthropic_response_body( (string) $model_text, 120, 80 ) ) )
		);

		$result = $provider->generate_drafts(
			$this->sample_product(),
			array( 'facebook' ),
			new BrandVoice( 'friendly', 'no exclamation marks' )
		);

		$this->assertSame( 'Apex Chute 3.0 is here.', $result->for_platform( 'facebook' )['content'] ?? '' );
		$this->assertSame( 'Product-led, casual.', $result->notes );
		$this->assertSame( 'claude-sonnet-4-6', $result->model );
		$this->assertSame( 120, $result->input_tokens );
		$this->assertSame( 80, $result->output_tokens );
	}

	public function test_response_with_markdown_code_fence_is_unwrapped(): void {
		$raw = "```json\n" . json_encode(
			array(
				'drafts' => array(
					'x' => array(
						'content'    => 'Short.',
						'hashtags'   => array(),
						'char_count' => 6,
					),
				),
				'notes'  => '',
			)
		) . "\n```";
		list( $provider, ) = $this->provider_with_responses(
			array( new Response( 200, array(), $this->anthropic_response_body( $raw ) ) )
		);

		$result = $provider->generate_drafts( $this->sample_product(), array( 'x' ), new BrandVoice() );
		$this->assertSame( 'Short.', $result->for_platform( 'x' )['content'] ?? '' );
	}

	public function test_401_response_maps_to_auth_failed(): void {
		list( $provider, ) = $this->provider_with_responses( array( new Response( 401 ) ) );

		$this->expectException( AIProviderException::class );
		$this->expectExceptionMessageMatches( '/API key/i' );
		$provider->generate_drafts( $this->sample_product(), array( 'facebook' ), new BrandVoice() );
	}

	public function test_429_response_maps_to_rate_limited(): void {
		list( $provider, ) = $this->provider_with_responses( array( new Response( 429 ) ) );

		$this->expectException( AIProviderException::class );
		$this->expectExceptionMessageMatches( '/rate limit/i' );
		$provider->generate_drafts( $this->sample_product(), array( 'facebook' ), new BrandVoice() );
	}

	public function test_500_response_maps_to_http_error_with_status(): void {
		list( $provider, ) = $this->provider_with_responses( array( new Response( 503 ) ) );

		try {
			$provider->generate_drafts( $this->sample_product(), array( 'facebook' ), new BrandVoice() );
			$this->fail( 'Expected AIProviderException.' );
		} catch ( AIProviderException $e ) {
			$this->assertStringContainsString( '503', $e->getMessage() );
		}
	}

	public function test_malformed_json_body_maps_to_malformed_response(): void {
		list( $provider, ) = $this->provider_with_responses(
			array( new Response( 200, array(), 'not even json' ) )
		);

		$this->expectException( AIProviderException::class );
		$this->expectExceptionMessageMatches( '/malformed/i' );
		$provider->generate_drafts( $this->sample_product(), array( 'facebook' ), new BrandVoice() );
	}

	public function test_model_text_without_drafts_key_is_malformed(): void {
		list( $provider, ) = $this->provider_with_responses(
			array( new Response( 200, array(), $this->anthropic_response_body( '{"hello":"world"}' ) ) )
		);

		$this->expectException( AIProviderException::class );
		$this->expectExceptionMessageMatches( '/drafts/i' );
		$provider->generate_drafts( $this->sample_product(), array( 'facebook' ), new BrandVoice() );
	}

	public function test_test_connection_reports_success_on_200(): void {
		list( $provider, ) = $this->provider_with_responses(
			array( new Response( 200, array(), $this->anthropic_response_body( '{"drafts":{}}', 1, 1 ) ) )
		);
		$result = $provider->test_connection();
		$this->assertTrue( $result->success );
	}

	public function test_test_connection_reports_failure_on_401(): void {
		list( $provider, ) = $this->provider_with_responses( array( new Response( 401 ) ) );
		$result = $provider->test_connection();
		$this->assertFalse( $result->success );
		$this->assertNotSame( '', $result->message );
	}

	public function test_empty_platform_list_defaults_to_facebook(): void {
		$model_text = json_encode(
			array(
				'drafts' => array(
					'facebook' => array(
						'content'    => 'Fallback used.',
						'hashtags'   => array(),
						'char_count' => 14,
					),
				),
				'notes'  => '',
			)
		);
		list( $provider, ) = $this->provider_with_responses(
			array( new Response( 200, array(), $this->anthropic_response_body( (string) $model_text ) ) )
		);

		// Passing platforms the provider doesn't support should fall through to facebook.
		$result = $provider->generate_drafts( $this->sample_product(), array( 'orkut', 'myspace' ), new BrandVoice() );
		$this->assertNotNull( $result->for_platform( 'facebook' ) );
	}
}

// ---------------------------------------------------------------------------
// PHPUnit doesn't bring wp_json_encode into scope; tests use this tiny helper
// so the JSON-encoding intent is explicit and aligned with how the production
// code talks about its output.
// ---------------------------------------------------------------------------
if ( ! function_exists( __NAMESPACE__ . '\\wp_safe_json_encode_or_fallback' ) ) {
	/**
	 * Encode an array to JSON, returning an empty object string on failure.
	 *
	 * @param array<string, mixed> $value Value to encode.
	 * @return string
	 */
	function wp_safe_json_encode_or_fallback( array $value ): string {
		$encoded = json_encode( $value );
		return false === $encoded ? '{}' : $encoded;
	}
}
