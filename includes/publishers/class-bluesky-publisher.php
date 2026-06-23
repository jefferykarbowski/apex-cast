<?php
/**
 * Bluesky publisher.
 *
 * @package ApexChute\ApexCast\Publishers
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Publishers;

use ApexChute\ApexCast\Support\TestConnectionResult;
use Closure;

/**
 * Publishes a product post to Bluesky via the AT Protocol.
 *
 * Auth model differs from the OAuth publishers: Bluesky uses an *app password*
 * (handle + app-password text), so there is no redirect flow — the publisher
 * creates a fresh session per publish call (well under the createSession rate
 * limit at our cadence) and uses the short-lived access JWT for the upload +
 * post.
 *
 * Post shape: a single `app.bsky.embed.external` link card pointing at the
 * product page, with the product image as the card thumbnail (when it fits in
 * Bluesky's ~1MB blob limit). Hashtags are appended to the post text and given
 * explicit richtext facets (Bluesky does NOT auto-detect tags).
 *
 * The image fetch is the only WordPress-flavoured dependency, so it's injected
 * as a closure to keep this class unit-testable without a WP runtime.
 */
final class BlueskyPublisher implements PlatformPublisherInterface {

	private const PLATFORM_ID    = 'bluesky';
	private const TEXT_MAX       = 300; // Graphemes, per the AT Protocol.
	private const MAX_BLOB_BYTES = 1000000; // ~1MB feed-image limit.

	/**
	 * Bluesky handle (e.g. "viciousfun.bsky.social").
	 *
	 * @var string
	 */
	private string $handle;

	/**
	 * App password (NOT the account password).
	 *
	 * @var string
	 */
	private string $app_password;

	/**
	 * AT Protocol HTTP client.
	 *
	 * @var BlueskyClient
	 */
	private BlueskyClient $client;

	/**
	 * Image fetcher. Signature: `function(string $url): ?array{bytes:string, mime:string}`.
	 * Returns null when the image can't be fetched. Injected so the WP-specific
	 * `wp_remote_get` call stays out of this otherwise WP-free class.
	 *
	 * @var Closure
	 */
	private Closure $image_fetcher;

	/**
	 * Constructor.
	 *
	 * @param string             $handle        Bluesky handle.
	 * @param string             $app_password  App password.
	 * @param BlueskyClient|null $client        Optional AT Protocol client override for tests.
	 * @param Closure|null       $image_fetcher Optional image fetcher; defaults to a no-op
	 *                                          that always returns null (Plugin injects the
	 *                                          real wp_remote_get-based one).
	 */
	public function __construct(
		string $handle,
		string $app_password,
		?BlueskyClient $client = null,
		?Closure $image_fetcher = null
	) {
		$this->handle        = $handle;
		$this->app_password  = $app_password;
		$this->client        = $client ?? new BlueskyClient();
		$this->image_fetcher = $image_fetcher ?? static function ( string $url ): ?array {
			unset( $url );
			return null;
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_platform_id(): string {
		return self::PLATFORM_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->handle && '' !== $this->app_password;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): TestConnectionResult {
		if ( ! $this->is_configured() ) {
			return TestConnectionResult::failure( 'Bluesky handle and app password are not configured.' );
		}

		try {
			$session = $this->client->create_session( $this->handle, $this->app_password );
		} catch ( PublisherException $e ) {
			return TestConnectionResult::failure( $e->getMessage() );
		}

		return TestConnectionResult::success(
			sprintf( 'Connected to Bluesky as @%s.', $this->handle ),
			array( 'did' => $session['did'] )
		);
	}

	/**
	 * Publish a post to Bluesky.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return PublishResult
	 *
	 * @throws PublisherException When the publisher isn't configured.
	 */
	public function publish( PublishRequest $request ): PublishResult {
		if ( ! $this->is_configured() ) {
			throw PublisherException::not_configured( self::PLATFORM_ID );
		}

		try {
			$session    = $this->client->create_session( $this->handle, $this->app_password );
			$access_jwt = $session['accessJwt'];
			$did        = $session['did'];

			// Best-effort thumbnail: fetch + upload, but never fail the post over it.
			$thumb = $this->resolve_thumbnail( $access_jwt, $request->media_url );

			$text   = $this->build_text( $request );
			$facets = $this->build_hashtag_facets( $text, $request->hashtags );

			$external = array(
				'uri'         => $request->product_url,
				'title'       => $this->resolve_card_title( $request ),
				'description' => '',
			);
			if ( null !== $thumb ) {
				$external['thumb'] = $thumb;
			}

			$record = array(
				'$type'     => 'app.bsky.feed.post',
				'text'      => $text,
				'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'embed'     => array(
					'$type'    => 'app.bsky.embed.external',
					'external' => $external,
				),
			);
			if ( array() !== $facets ) {
				$record['facets'] = $facets;
			}

			$created = $this->client->create_record( $access_jwt, $did, $record );
		} catch ( PublisherException $e ) {
			return PublishResult::failure_for( self::PLATFORM_ID, $e->getMessage() );
		}

		$public_url = $this->at_uri_to_public_url( $created['uri'] );

		return PublishResult::success_for( self::PLATFORM_ID, $created['uri'], $public_url );
	}

	/**
	 * Fetch the product image and upload it as a blob, returning the blob ref —
	 * or null when the image is unavailable, oversized, or the upload fails.
	 * Never throws: a missing thumbnail must not sink the whole post.
	 *
	 * @param string $access_jwt Session access JWT.
	 * @param string $media_url  Product image URL.
	 * @return array<string, mixed>|null Blob ref, or null to post without a thumb.
	 */
	private function resolve_thumbnail( string $access_jwt, string $media_url ): ?array {
		if ( '' === $media_url ) {
			return null;
		}

		$fetched = ( $this->image_fetcher )( $media_url );
		if ( ! is_array( $fetched ) || ! isset( $fetched['bytes'] ) || ! is_string( $fetched['bytes'] ) ) {
			return null;
		}

		$bytes = $fetched['bytes'];
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_BLOB_BYTES ) {
			return null;
		}

		$mime = isset( $fetched['mime'] ) && is_string( $fetched['mime'] ) && '' !== $fetched['mime']
			? $fetched['mime']
			: 'image/jpeg';

		try {
			return $this->client->upload_blob( $access_jwt, $bytes, $mime );
		} catch ( PublisherException $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Resolve the link-card title: the product name (passed in platform_options)
	 * when present, otherwise the post content.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return string
	 */
	private function resolve_card_title( PublishRequest $request ): string {
		if ( isset( $request->platform_options['title'] ) && is_string( $request->platform_options['title'] ) && '' !== $request->platform_options['title'] ) {
			return $request->platform_options['title'];
		}
		return $request->content;
	}

	/**
	 * Build the post text: content body + hashtags, truncated to 300 graphemes.
	 *
	 * @param PublishRequest $request Normalized publish request.
	 * @return string
	 */
	private function build_text( PublishRequest $request ): string {
		$text = $request->content;
		if ( ! empty( $request->hashtags ) ) {
			$text .= "\n\n" . implode( ' ', $request->hashtags );
		}
		return $this->truncate_graphemes( $text, self::TEXT_MAX );
	}

	/**
	 * Truncate a string to at most `$max` graphemes, preferring the intl
	 * grapheme functions and falling back to `mb_*` when intl is unavailable.
	 *
	 * @param string $value Input string.
	 * @param int    $max   Maximum number of graphemes.
	 * @return string
	 */
	private function truncate_graphemes( string $value, int $max ): string {
		if ( function_exists( 'grapheme_strlen' ) && function_exists( 'grapheme_substr' ) ) {
			$length = grapheme_strlen( $value );
			if ( is_int( $length ) && $length > $max ) {
				$cut = grapheme_substr( $value, 0, $max - 1 );
				return ( is_string( $cut ) ? $cut : '' ) . '…';
			}
			return $value;
		}

		if ( mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max - 1 ) . '…';
		}
		return $value;
	}

	/**
	 * Build richtext facets for every hashtag occurrence in the final text.
	 *
	 * Bluesky requires manual facets for clickable tags. `byteStart`/`byteEnd`
	 * are UTF-8 BYTE offsets into the text — we walk the byte string with
	 * `strpos`/`strlen` (NOT the multibyte variants) so multibyte characters
	 * before a tag push the offsets correctly. The leading `#` is excluded from
	 * the tag value but included in the facet byte range (Bluesky highlights the
	 * `#`).
	 *
	 * @param string   $text     The final post text.
	 * @param string[] $hashtags Hashtags as `#tag` strings.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_hashtag_facets( string $text, array $hashtags ): array {
		$facets = array();
		$offset = 0;

		foreach ( $hashtags as $hashtag ) {
			$hashtag = (string) $hashtag;
			if ( '' === $hashtag || '#' !== $hashtag[0] ) {
				continue;
			}

			$byte_start = strpos( $text, $hashtag, $offset );
			if ( false === $byte_start ) {
				// Tag was truncated out of the text (300-grapheme cap) — skip it.
				continue;
			}

			$byte_end = $byte_start + strlen( $hashtag );
			$tag      = substr( $hashtag, 1 ); // Strip the leading '#'.

			$facets[] = array(
				'index'    => array(
					'byteStart' => $byte_start,
					'byteEnd'   => $byte_end,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#tag',
						'tag'   => $tag,
					),
				),
			);

			// Advance past this occurrence so repeated tags map to distinct ranges.
			$offset = $byte_end;
		}

		return $facets;
	}

	/**
	 * Convert an AT URI (`at://did/app.bsky.feed.post/RKEY`) into the public
	 * bsky.app permalink (`https://bsky.app/profile/{handle}/post/{rkey}`).
	 *
	 * @param string $at_uri The AT URI returned by createRecord.
	 * @return string Public URL (empty when the URI has no rkey segment).
	 */
	private function at_uri_to_public_url( string $at_uri ): string {
		$segments = explode( '/', $at_uri );
		$rkey     = end( $segments );
		if ( ! is_string( $rkey ) || '' === $rkey ) {
			return '';
		}
		return sprintf( 'https://bsky.app/profile/%s/post/%s', $this->handle, $rkey );
	}
}
