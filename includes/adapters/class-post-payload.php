<?php
/**
 * Multi-platform post payload value object.
 *
 * @package ApexChute\ApexCast\Adapters
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Adapters;

/**
 * Normalized post request handed to BackendAdapterInterface::queue_post().
 *
 * Adapters translate this into their backend's native API shape. The structure
 * intentionally mirrors how a user thinks about a send: one product, several
 * platforms, one set of drafts.
 */
final class PostPayload {

	public const TYPE_NOW      = 'now';
	public const TYPE_SCHEDULE = 'schedule';
	public const TYPE_DRAFT    = 'draft';

	/**
	 * Constructor.
	 *
	 * @param int                                 $product_id      WooCommerce product ID.
	 * @param string[]                            $platforms       Platforms to post to.
	 * @param array<string, array<string, mixed>> $drafts          Drafts keyed by platform, each with content + hashtags.
	 * @param array<string, string>               $integration_map Map of platform -> backend integration ID.
	 * @param MediaRef[]                          $media           Optional media references (already uploaded).
	 * @param string                              $post_type       One of the TYPE_* constants.
	 * @param string|null                         $scheduled_at    ISO-8601 timestamp for scheduled posts (null otherwise).
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly array $platforms,
		public readonly array $drafts,
		public readonly array $integration_map,
		public readonly array $media = array(),
		public readonly string $post_type = self::TYPE_DRAFT,
		public readonly ?string $scheduled_at = null
	) {}
}
