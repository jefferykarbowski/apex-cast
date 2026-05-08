<?php
/**
 * Backend adapter interface.
 *
 * Implementations: PostizAdapter (v0.1). Future: BufferAdapter, PublerAdapter.
 *
 * @package ApexChute\ApexCast\Adapters
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Adapters;

/**
 * Anything that can authenticate, queue posts to multiple platforms, and report status.
 *
 * Implementations are responsible for translating Apex Cast's normalized PostPayload
 * into the backend's native API shape, batching multi-platform posts efficiently
 * (Postiz: single multi-integration request; others may differ), and respecting
 * each backend's rate limits.
 */
interface BackendAdapterInterface {

	/**
	 * Verify the configured credentials and connectivity.
	 */
	public function test_connection(): TestConnectionResult;

	/**
	 * Fetch the user's connected social channels from the backend.
	 *
	 * Used by the settings UI to populate the "platform → backend integration" mapping table.
	 *
	 * @return IntegrationInfo[]
	 *
	 * @throws BackendAdapterException
	 */
	public function fetch_integrations(): array;

	/**
	 * Upload media to the backend and return a backend-native reference.
	 *
	 * @throws BackendAdapterException
	 */
	public function upload_media( string $local_path, string $mime_type ): MediaRef;

	/**
	 * Queue a multi-platform post.
	 *
	 * Implementations SHOULD batch all platforms into the smallest number of
	 * backend API calls possible to respect rate limits.
	 *
	 * @throws BackendAdapterException
	 */
	public function queue_post( PostPayload $payload ): QueueResult;

	/**
	 * Look up the status of a previously queued post.
	 *
	 * @throws BackendAdapterException
	 */
	public function get_post_status( string $backend_post_id ): PostStatus;

	/**
	 * Stable identifier for settings storage.
	 * MUST match the key used in apex_cast_settings.backend.{id}.
	 *
	 * @return string e.g. "postiz", "buffer", "publer"
	 */
	public function get_adapter_id(): string;
}
