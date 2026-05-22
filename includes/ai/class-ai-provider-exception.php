<?php
/**
 * AI provider exception.
 *
 * @package ApexChute\ApexCast\AI
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\AI;

/**
 * Thrown by any AIProviderInterface implementation when generation fails.
 *
 * Use the named-constructor factories so callers can switch on a small set of
 * shapes (auth, rate limit, malformed, generic HTTP) without parsing strings.
 */
final class AIProviderException extends \RuntimeException {

	/**
	 * Provider rejected the configured API key.
	 *
	 * @return self
	 */
	public static function auth_failed(): self {
		return new self( 'The AI provider rejected the configured API key.' );
	}

	/**
	 * Provider returned a rate-limit response (HTTP 429).
	 *
	 * @return self
	 */
	public static function rate_limited(): self {
		return new self( 'The AI provider is rate limiting requests. Please retry shortly.' );
	}

	/**
	 * Provider returned a body that did not match the expected JSON schema.
	 *
	 * @param string $detail Optional extra context to append to the message.
	 * @return self
	 */
	public static function malformed_response( string $detail = '' ): self {
		$message = 'The AI provider returned malformed output.';
		return new self( '' === $detail ? $message : $message . ' ' . $detail );
	}

	/**
	 * Provider returned an unexpected HTTP status that is not specifically handled.
	 *
	 * @param int $status The HTTP status code returned by the provider.
	 * @return self
	 */
	public static function http_error( int $status ): self {
		return new self( sprintf( 'The AI provider returned an unexpected HTTP %d response.', $status ) );
	}
}
