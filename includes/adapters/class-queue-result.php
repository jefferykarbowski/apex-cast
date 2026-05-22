<?php
/**
 * Queue result value object.
 *
 * @package ApexChute\ApexCast\Adapters
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Adapters;

/**
 * Result returned by BackendAdapterInterface::queue_post().
 *
 * Carries the backend's identifier for the queued post (used later to poll status)
 * and an initial per-platform result map if the backend returns one synchronously.
 */
final class QueueResult {

	/**
	 * Constructor.
	 *
	 * @param string               $backend_post_id  Backend-specific group/post identifier.
	 * @param string               $status           Initial status (queued | sent | partial | failed).
	 * @param array<string, mixed> $platform_results Per-platform results returned synchronously by the backend.
	 */
	public function __construct(
		public readonly string $backend_post_id,
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
			'backend_post_id'  => $this->backend_post_id,
			'status'           => $this->status,
			'platform_results' => $this->platform_results,
		);
	}
}
