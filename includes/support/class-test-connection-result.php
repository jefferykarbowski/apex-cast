<?php
/**
 * Test-connection result value object.
 *
 * Shared between AI providers and backend adapters so they speak the same
 * shape back to the settings UI's "Test connection" button.
 *
 * @package ApexChute\ApexCast\Support
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Support;

/**
 * Immutable result returned by provider / adapter connection tests.
 */
final class TestConnectionResult {

	/**
	 * Constructor.
	 *
	 * @param bool                 $success Whether the connection test passed.
	 * @param string               $message Human-readable summary, safe to display in the admin UI.
	 * @param array<string, mixed> $details Optional structured detail (e.g. integration list).
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $message,
		public readonly array $details = array()
	) {}

	/**
	 * Build a successful result.
	 *
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $details Optional structured detail.
	 * @return self
	 */
	public static function success( string $message, array $details = array() ): self {
		return new self( true, $message, $details );
	}

	/**
	 * Build a failed result.
	 *
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $details Optional structured detail.
	 * @return self
	 */
	public static function failure( string $message, array $details = array() ): self {
		return new self( false, $message, $details );
	}

	/**
	 * Export as a plain array for REST responses.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'success' => $this->success,
			'message' => $this->message,
			'details' => $this->details,
		);
	}
}
