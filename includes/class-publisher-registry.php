<?php
/**
 * Publisher registry.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

use ApexChute\ApexCast\Publishers\PlatformPublisherInterface;

/**
 * Owns the set of `PlatformPublisherInterface` instances available at runtime.
 *
 * Replaces the v0.1 `AdapterFactory`, which only ever returned one Postiz
 * instance. Each phase from Phase 5 onward adds one publisher (Pinterest, X,
 * Reddit, Facebook Page, Instagram); the registry is the single place that
 * knows which platforms are supported and which are configured right now.
 *
 * Construction is lazy — the registry is built by `Plugin::publisher_registry()`
 * and publishers are registered as they exist. Until any publisher class
 * exists, the registry is empty and every lookup returns null. REST callers
 * map "no publisher" → "platform not yet implemented" rather than a 500.
 */
final class PublisherRegistry {

	/**
	 * Publishers keyed by platform id.
	 *
	 * @var array<string, PlatformPublisherInterface>
	 */
	private array $publishers = array();

	/**
	 * Register a publisher. Duplicate platform ids overwrite the previous entry.
	 *
	 * @param PlatformPublisherInterface $publisher The publisher to register.
	 * @return void
	 */
	public function register( PlatformPublisherInterface $publisher ): void {
		$this->publishers[ $publisher->get_platform_id() ] = $publisher;
	}

	/**
	 * Look up a publisher by platform id.
	 *
	 * @param string $platform_id Platform identifier.
	 * @return PlatformPublisherInterface|null Null if no publisher has been registered for that platform.
	 */
	public function get( string $platform_id ): ?PlatformPublisherInterface {
		return $this->publishers[ $platform_id ] ?? null;
	}

	/**
	 * Whether a publisher has been registered for a platform.
	 *
	 * @param string $platform_id Platform identifier.
	 * @return bool
	 */
	public function has( string $platform_id ): bool {
		return isset( $this->publishers[ $platform_id ] );
	}

	/**
	 * Whether a registered publisher reports itself as configured.
	 *
	 * Returns false if no publisher exists for the platform.
	 *
	 * @param string $platform_id Platform identifier.
	 * @return bool
	 */
	public function is_configured( string $platform_id ): bool {
		$publisher = $this->get( $platform_id );
		return null !== $publisher && $publisher->is_configured();
	}

	/**
	 * All registered publishers, keyed by platform id.
	 *
	 * @return array<string, PlatformPublisherInterface>
	 */
	public function all(): array {
		return $this->publishers;
	}

	/**
	 * Platform ids of every registered publisher that's currently configured.
	 *
	 * @return string[]
	 */
	public function configured_platforms(): array {
		$ids = array();
		foreach ( $this->publishers as $platform_id => $publisher ) {
			if ( $publisher->is_configured() ) {
				$ids[] = $platform_id;
			}
		}
		return $ids;
	}
}
