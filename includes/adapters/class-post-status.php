<?php
/**
 * Post status value object.
 *
 * @package ApexChute\ApexCast\Adapters
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Adapters;

/**
 * Snapshot of the current status of a previously queued post, as observed at the backend.
 */
final class PostStatus {

	public const STATUS_QUEUED  = 'queued';
	public const STATUS_SENT    = 'sent';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_FAILED  = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string               $status           One of the STATUS_* constants.
	 * @param array<string, mixed> $platform_results Per-platform results (status + optional URL per platform).
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $platform_results = array()
	) {}

	/**
	 * Export as a plain associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'status'           => $this->status,
			'platform_results' => $this->platform_results,
		);
	}
}
