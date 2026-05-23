<?php
/**
 * Publish request value object.
 *
 * @package ApexChute\ApexCast\Publishers
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Publishers;

/**
 * Normalized publish request handed to a `PlatformPublisherInterface::publish()`.
 *
 * Mirrors the shape of a single per-platform draft from the metabox UI, enriched
 * with the WC product fields the publisher needs to construct its native call
 * (the public URL for link previews, the featured image URL for media uploads).
 *
 * Per-platform extras (e.g. Pinterest board id, Reddit subreddit) live in
 * `platform_options` so each publisher can read what it needs without us
 * widening this object every time a new platform lands.
 */
final class PublishRequest {

	/**
	 * Constructor.
	 *
	 * @param int                  $product_id      WooCommerce product ID.
	 * @param string               $platform        Platform identifier the publisher is being asked to publish to.
	 * @param string               $content         The post body the user reviewed and approved.
	 * @param string[]             $hashtags        Hashtags as `#tag` strings; publishers append/inline per their convention.
	 * @param string               $product_url    Public permalink to the product (for link previews and platforms that store the URL separately).
	 * @param string               $media_url       Public URL of the featured product image (publishers may re-host or upload).
	 * @param string|null          $scheduled_at    Optional ISO-8601 timestamp if the publish is to be scheduled (null = immediate).
	 * @param array<string, mixed> $platform_options Per-platform extras (e.g. ['board_id' => '…', 'subreddit' => '…']).
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly string $platform,
		public readonly string $content,
		public readonly array $hashtags,
		public readonly string $product_url,
		public readonly string $media_url,
		public readonly ?string $scheduled_at = null,
		public readonly array $platform_options = array()
	) {}
}
