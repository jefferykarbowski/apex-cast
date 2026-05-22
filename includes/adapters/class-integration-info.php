<?php
/**
 * Integration info value object.
 *
 * @package ApexChute\ApexCast\Adapters
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Adapters;

/**
 * A connected social channel as reported by the backend (e.g. a Postiz integration).
 *
 * Used by the settings UI to render the "platform -> backend integration" mapping table.
 */
final class IntegrationInfo {

	/**
	 * Constructor.
	 *
	 * @param string $id       Backend-specific integration ID.
	 * @param string $name     Human-readable integration name (e.g. "Apex Chute on Facebook").
	 * @param string $platform Apex Cast platform identifier this integration posts to.
	 * @param string $picture  Optional avatar / page picture URL.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $platform,
		public readonly string $picture = ''
	) {}

	/**
	 * Export as a plain associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'       => $this->id,
			'name'     => $this->name,
			'platform' => $this->platform,
			'picture'  => $this->picture,
		);
	}
}
