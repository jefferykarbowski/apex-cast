<?php
/**
 * AI provider interface.
 *
 * Implementations: AnthropicProvider (v0.1). Future: OpenAIProvider, GeminiProvider.
 *
 * @package ApexChute\ApexCast\AI
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\AI;

/**
 * Anything that can generate platform-tailored social copy from product data.
 */
interface AIProviderInterface {

	/**
	 * Generate platform-tailored social copy drafts for a single product.
	 *
	 * @param ProductContext $product   Product data extracted from WooCommerce.
	 * @param string[]       $platforms Platforms to generate for (e.g. ['facebook','instagram']).
	 * @param BrandVoice     $voice     Brand voice configuration.
	 *
	 * @return GenerationResult Drafts keyed by platform plus model metadata.
	 *
	 * @throws AIProviderException When the provider fails (auth, rate limit, malformed response, etc.).
	 */
	public function generate_drafts(
		ProductContext $product,
		array $platforms,
		BrandVoice $voice
	): GenerationResult;

	/**
	 * Platforms this provider knows how to format for.
	 *
	 * @return string[] e.g. ['facebook','instagram','pinterest','threads','bluesky','x','tiktok','reddit']
	 */
	public function get_supported_platforms(): array;

	/**
	 * Stable identifier for settings storage and UI display.
	 * MUST match the key used in apex_cast_settings.ai_provider.{id}.
	 *
	 * @return string e.g. "anthropic", "openai", "gemini"
	 */
	public function get_provider_id(): string;

	/**
	 * Test the connection (used by Settings → Test button).
	 *
	 * @return TestConnectionResult
	 */
	public function test_connection(): TestConnectionResult;
}
