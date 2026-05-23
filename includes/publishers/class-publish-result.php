<?php
/**
 * Publish result value object.
 *
 * @package ApexChute\ApexCast\Publishers
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Publishers;

/**
 * Result returned by `PlatformPublisherInterface::publish()`.
 *
 * Carries everything the metabox + jobs log need to render a "sent" state for
 * the user (success flag, platform URL, post identifier) and to record what
 * happened in `apex_cast_jobs.platform_results`.
 */
final class PublishResult {

	/**
	 * Constructor.
	 *
	 * @param bool                 $success          True if the platform accepted and published the post.
	 * @param string               $platform         Platform identifier this result is for.
	 * @param string               $platform_post_id Platform's own identifier for the published post (may be empty on failure).
	 * @param string               $platform_url     Public URL of the published post (may be empty if the platform doesn't return one).
	 * @param string|null          $error_message    Human-readable failure reason (null on success).
	 * @param array<string, mixed> $context          Optional structured extras (rate-limit headers, retry-after hints, etc.).
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $platform,
		public readonly string $platform_post_id = '',
		public readonly string $platform_url = '',
		public readonly ?string $error_message = null,
		public readonly array $context = array()
	) {}

	/**
	 * Build a successful result.
	 *
	 * @param string               $platform         Platform identifier.
	 * @param string               $platform_post_id Platform-issued post id.
	 * @param string               $platform_url     Public URL of the published post.
	 * @param array<string, mixed> $context          Optional structured extras.
	 * @return self
	 */
	public static function success_for( string $platform, string $platform_post_id, string $platform_url = '', array $context = array() ): self {
		return new self( true, $platform, $platform_post_id, $platform_url, null, $context );
	}

	/**
	 * Build a failed result.
	 *
	 * @param string               $platform Platform identifier.
	 * @param string               $message  Human-readable failure reason.
	 * @param array<string, mixed> $context  Optional structured extras.
	 * @return self
	 */
	public static function failure_for( string $platform, string $message, array $context = array() ): self {
		return new self( false, $platform, '', '', $message, $context );
	}

	/**
	 * Export as a plain associative array (for JSON envelopes and the job row's `platform_results`).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'success'          => $this->success,
			'platform'         => $this->platform,
			'platform_post_id' => $this->platform_post_id,
			'platform_url'     => $this->platform_url,
			'error_message'    => $this->error_message,
			'context'          => $this->context,
		);
	}
}
