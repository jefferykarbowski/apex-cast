<?php
/**
 * AI provider factory.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

use ApexChute\ApexCast\AI\AIProviderInterface;
use ApexChute\ApexCast\AI\AnthropicProvider;

/**
 * Builds an `AIProviderInterface` instance from the current settings.
 *
 * Returns null whenever the configured provider is unusable (no API key,
 * unknown id, etc.) so callers render "configure me" rather than crash.
 */
final class ProviderFactory {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create the currently-configured provider.
	 *
	 * @return AIProviderInterface|null
	 */
	public function create(): ?AIProviderInterface {
		$id = (string) $this->settings->get( 'ai_provider.active', 'anthropic' );

		if ( 'anthropic' === $id ) {
			return $this->create_anthropic();
		}
		return null;
	}

	/**
	 * Build an AnthropicProvider, or null if not configured.
	 *
	 * @return AnthropicProvider|null
	 */
	private function create_anthropic(): ?AnthropicProvider {
		$api_key = $this->settings->get_secret( 'ai_provider.anthropic.api_key_encrypted' );
		if ( '' === $api_key ) {
			return null;
		}

		$model      = (string) $this->settings->get( 'ai_provider.anthropic.model', 'claude-sonnet-4-6' );
		$max_tokens = (int) $this->settings->get( 'ai_provider.anthropic.max_tokens', 1024 );
		$store_name = (string) $this->settings->get( 'store.name', '' );
		$store_desc = (string) $this->settings->get( 'store.description', '' );

		return new AnthropicProvider( $api_key, $model, $max_tokens, $store_name, $store_desc );
	}
}
