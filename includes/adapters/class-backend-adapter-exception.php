<?php
/**
 * Backend adapter exception.
 *
 * @package ApexChute\ApexCast\Adapters
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Adapters;

/**
 * Thrown by any BackendAdapterInterface implementation when the backend call fails.
 *
 * Mirrors AIProviderException — small set of shapes callers can switch on.
 */
final class BackendAdapterException extends \RuntimeException {

	/**
	 * Backend rejected the configured API key.
	 *
	 * @return self
	 */
	public static function auth_failed(): self {
		return new self( 'The backend rejected the configured API key.' );
	}

	/**
	 * Backend returned a rate-limit response (HTTP 429).
	 *
	 * @return self
	 */
	public static function rate_limited(): self {
		return new self( 'The backend is rate limiting requests. Please retry shortly.' );
	}

	/**
	 * Backend returned a body that did not match the expected schema.
	 *
	 * @param string $detail Optional extra context to append to the message.
	 * @return self
	 */
	public static function malformed_response( string $detail = '' ): self {
		$message = 'The backend returned an unexpected response shape.';
		return new self( '' === $detail ? $message : $message . ' ' . $detail );
	}

	/**
	 * Backend returned an unexpected HTTP status that is not specifically handled.
	 *
	 * @param int $status The HTTP status code returned by the backend.
	 * @return self
	 */
	public static function http_error( int $status ): self {
		return new self( sprintf( 'The backend returned an unexpected HTTP %d response.', $status ) );
	}
}
