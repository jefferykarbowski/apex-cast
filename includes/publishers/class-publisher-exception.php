<?php
/**
 * Publisher exception.
 *
 * @package ApexChute\ApexCast\Publishers
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Publishers;

/**
 * Thrown by any `PlatformPublisherInterface` implementation when a publish fails
 * for a reason that's worth distinguishing in code (rather than returning a
 * generic failure `PublishResult`).
 *
 * Mirrors `AIProviderException` — small set of shapes the REST layer + Logger
 * can switch on without parsing strings.
 */
final class PublisherException extends \RuntimeException {

	/**
	 * Publisher has no usable credentials (token missing, key field empty).
	 *
	 * @param string $platform Platform identifier.
	 * @return self
	 */
	public static function not_configured( string $platform ): self {
		return new self( sprintf( '%s publisher is not configured. Connect it in Settings → Apex Cast.', $platform ) );
	}

	/**
	 * Platform rejected the configured credentials.
	 *
	 * @param string $platform Platform identifier.
	 * @return self
	 */
	public static function auth_failed( string $platform ): self {
		return new self( sprintf( '%s rejected the configured credentials. Reconnect it in Settings → Apex Cast.', $platform ) );
	}

	/**
	 * Platform returned a rate-limit response.
	 *
	 * @param string $platform Platform identifier.
	 * @return self
	 */
	public static function rate_limited( string $platform ): self {
		return new self( sprintf( '%s is rate limiting requests. Please retry shortly.', $platform ) );
	}

	/**
	 * Platform returned a body that did not match the expected schema.
	 *
	 * @param string $platform Platform identifier.
	 * @param string $detail   Optional extra context to append to the message.
	 * @return self
	 */
	public static function malformed_response( string $platform, string $detail = '' ): self {
		$message = sprintf( '%s returned an unexpected response shape.', $platform );
		return new self( '' === $detail ? $message : $message . ' ' . $detail );
	}

	/**
	 * Platform returned an unexpected HTTP status that is not specifically handled.
	 *
	 * @param string $platform Platform identifier.
	 * @param int    $status   The HTTP status code returned by the platform.
	 * @return self
	 */
	public static function http_error( string $platform, int $status ): self {
		return new self( sprintf( '%s returned an unexpected HTTP %d response.', $platform, $status ) );
	}
}
