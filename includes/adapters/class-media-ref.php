<?php
/**
 * Media reference value object.
 *
 * @package ApexChute\ApexCast\Adapters
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Adapters;

/**
 * Pointer to media that has already been uploaded to the backend.
 *
 * Returned by BackendAdapterInterface::upload_media() and embedded in PostPayload
 * so a single PostPayload can carry references to many backend-hosted assets.
 */
final class MediaRef {

	/**
	 * Constructor.
	 *
	 * @param string $id   Backend-specific media identifier.
	 * @param string $path Backend-specific path / URL of the uploaded asset.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $path
	) {}

	/**
	 * Export as a plain associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'   => $this->id,
			'path' => $this->path,
		);
	}
}
