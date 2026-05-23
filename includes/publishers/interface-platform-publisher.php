<?php
/**
 * Platform publisher interface.
 *
 * @package ApexChute\ApexCast\Publishers
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Publishers;

use ApexChute\ApexCast\Support\TestConnectionResult;

/**
 * Anything that can publish a single piece of content to a single social platform.
 *
 * Replaces the v0.1 `BackendAdapterInterface` (which assumed one big multi-platform
 * scheduler). Each social platform now has its own publisher implementing this
 * interface: PinterestPublisher, XPublisher, RedditPublisher, FacebookPagePublisher,
 * InstagramPublisher — landing one at a time in Phase 5+.
 *
 * Implementations are responsible for their own:
 *   - Auth (OAuth tokens, App Page Tokens, etc.) stored in settings
 *   - Per-platform media handling (URL ingest vs upload, multi-step containers, etc.)
 *   - Rate-limit accounting
 *   - Mapping the normalized `PublishRequest` to the platform's native API call
 */
interface PlatformPublisherInterface {

	/**
	 * Stable identifier for this publisher.
	 *
	 * Must match the platform slug used elsewhere ('facebook', 'instagram',
	 * 'pinterest', 'x', 'reddit', 'threads', 'bluesky', 'tiktok').
	 *
	 * @return string
	 */
	public function get_platform_id(): string;

	/**
	 * Whether the publisher has enough configuration to attempt a publish.
	 *
	 * Returns false when credentials are missing or unreadable (e.g. encrypted
	 * token couldn't be decrypted). Callers should not call `publish()` when
	 * this returns false; they should prompt the user to connect the platform.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Verify credentials + connectivity. Used by the settings UI's "Test connection" button.
	 *
	 * @return TestConnectionResult
	 */
	public function test_connection(): TestConnectionResult;

	/**
	 * Publish a single piece of content to this platform.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return PublishResult Per-publish result with success/failure + platform URL.
	 *
	 * @throws PublisherException When the publish attempt fails for a reason worth
	 *                            distinguishing (auth, rate limit, malformed response,
	 *                            HTTP error). Returning a failure `PublishResult` is
	 *                            preferred for "the platform accepted our call but
	 *                            rejected the content".
	 */
	public function publish( PublishRequest $request ): PublishResult;
}
