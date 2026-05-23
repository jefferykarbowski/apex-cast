<?php
/**
 * Postiz backend adapter.
 *
 * @package ApexChute\ApexCast\Adapters
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Adapters;

use ApexChute\ApexCast\Support\TestConnectionResult;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends posts to the Postiz public API.
 *
 * Auth is a raw API key in the Authorization header (no "Bearer" prefix);
 * the multi-platform send batches into a single POST /posts call so we stay
 * under Postiz's 30-requests-per-hour-per-key budget. Rate-limit accounting
 * lives in a WordPress transient.
 */
final class PostizAdapter implements BackendAdapterInterface {

	private const ADAPTER_ID          = 'postiz';
	private const DEFAULT_BASE_URL    = 'https://api.postiz.com/public/v1';
	private const RATE_LIMIT_PER_HOUR = 30;
	private const RATE_LIMIT_KEY      = 'apex_cast_postiz_rate';

	/**
	 * HTTP client.
	 *
	 * @var ClientInterface
	 */
	private ClientInterface $client;

	/**
	 * API key (no "Bearer" prefix).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param string               $api_key  Postiz API key.
	 * @param string               $base_url Base URL (override for self-hosted Postiz).
	 * @param ClientInterface|null $client   Optional Guzzle client override for tests.
	 */
	public function __construct(
		string $api_key,
		string $base_url = self::DEFAULT_BASE_URL,
		?ClientInterface $client = null
	) {
		$this->api_key  = $api_key;
		$this->base_url = rtrim( $base_url, '/' );
		$this->client   = $client ?? new Client( array( 'timeout' => 30 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_adapter_id(): string {
		return self::ADAPTER_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): TestConnectionResult {
		try {
			$integrations = $this->fetch_integrations();
			return TestConnectionResult::success(
				sprintf( '%d integration(s) connected.', count( $integrations ) ),
				array( 'integration_count' => count( $integrations ) )
			);
		} catch ( BackendAdapterException $e ) {
			return TestConnectionResult::failure( $e->getMessage() );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return IntegrationInfo[]
	 */
	public function fetch_integrations(): array {
		$response = $this->request( 'GET', '/integrations' );
		$data     = $this->decode_json( $response );

		// Postiz has shipped at least two response shapes; accept either.
		$rows = ( isset( $data['integrations'] ) && is_array( $data['integrations'] ) )
			? $data['integrations']
			: $data;

		$list = array();
		if ( ! is_array( $rows ) ) {
			return $list;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$platform = (string) ( $row['identifier'] ?? $row['providerIdentifier'] ?? $row['platform'] ?? '' );
			$list[]   = new IntegrationInfo(
				(string) ( $row['id'] ?? '' ),
				(string) ( $row['name'] ?? '' ),
				$platform,
				(string) ( $row['picture'] ?? '' )
			);
		}
		return $list;
	}

	/**
	 * Upload media to the backend and return a backend-native reference.
	 *
	 * @param string $local_path Absolute filesystem path to the local asset to upload.
	 * @param string $mime_type  MIME type of the asset (e.g. "image/jpeg").
	 * @return MediaRef Backend-native pointer to the uploaded media.
	 *
	 * @throws BackendAdapterException When the upload fails.
	 */
	public function upload_media( string $local_path, string $mime_type ): MediaRef {
		$handle = $this->open_file_for_upload( $local_path );

		$response = $this->request(
			'POST',
			'/upload',
			array(
				'multipart' => array(
					array(
						'name'     => 'file',
						'contents' => $handle,
						'filename' => basename( $local_path ),
						'headers'  => array( 'Content-Type' => $mime_type ),
					),
				),
			)
		);

		$data = $this->decode_json( $response );
		return new MediaRef(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['path'] ?? '' )
		);
	}

	/**
	 * Queue a multi-platform post.
	 *
	 * @param PostPayload $payload Normalized post payload.
	 * @return QueueResult Backend identifier + initial status.
	 *
	 * @throws BackendAdapterException When the queue call fails.
	 */
	public function queue_post( PostPayload $payload ): QueueResult {
		$posts = $this->build_posts_array( $payload );

		$body = array(
			'type'      => $payload->post_type,
			'date'      => $payload->scheduled_at ?? gmdate( 'c' ),
			'shortLink' => false,
			'tags'      => array(),
			'posts'     => $posts,
		);

		$this->assert_rate_budget();
		$response = $this->request( 'POST', '/posts', array( 'json' => $body ) );
		$this->record_request();

		$data     = $this->decode_json( $response );
		$group_id = (string) ( $data['id'] ?? $data['groupId'] ?? '' );
		return new QueueResult( $group_id, PostStatus::STATUS_QUEUED, array() );
	}

	/**
	 * Look up the status of a previously queued post.
	 *
	 * @param string $backend_post_id Identifier returned by `queue_post()`.
	 * @return PostStatus Current status snapshot from the backend.
	 *
	 * @throws BackendAdapterException When the status call fails.
	 */
	public function get_post_status( string $backend_post_id ): PostStatus {
		$response = $this->request( 'GET', '/posts/' . rawurlencode( $backend_post_id ) );
		$data     = $this->decode_json( $response );

		$status           = (string) ( $data['status'] ?? PostStatus::STATUS_QUEUED );
		$platform_results = is_array( $data['posts'] ?? null ) ? $data['posts'] : array();
		return new PostStatus( $status, $platform_results );
	}

	/**
	 * Construct the per-platform `posts` array Postiz expects.
	 *
	 * @param PostPayload $payload Normalized payload.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_posts_array( PostPayload $payload ): array {
		$image_refs = array();
		foreach ( $payload->media as $media ) {
			$image_refs[] = array(
				'id'   => $media->id,
				'path' => $media->path,
			);
		}

		$posts = array();
		foreach ( $payload->platforms as $platform ) {
			$draft          = $payload->drafts[ $platform ] ?? null;
			$integration_id = $payload->integration_map[ $platform ] ?? '';
			if ( ! is_array( $draft ) || '' === $integration_id ) {
				continue;
			}
			$content = (string) ( $draft['content'] ?? '' );
			$posts[] = array(
				'integration' => array( 'id' => $integration_id ),
				'value'       => array(
					array(
						'content' => $content,
						'image'   => $image_refs,
					),
				),
				'settings'    => array(
					'__type' => $platform,
				),
			);
		}
		return $posts;
	}

	/**
	 * Generic authenticated request with error mapping.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path relative to base URL.
	 * @param array<string, mixed> $options Additional Guzzle request options.
	 * @return ResponseInterface
	 *
	 * @throws BackendAdapterException On HTTP failure.
	 */
	private function request( string $method, string $path, array $options = array() ): ResponseInterface {
		$headers                  = isset( $options['headers'] ) && is_array( $options['headers'] ) ? $options['headers'] : array();
		$headers['Authorization'] = $this->api_key;
		$options['headers']       = $headers;

		try {
			$response = $this->client->request( $method, $this->base_url . $path, $options );
		} catch ( RequestException $e ) {
			$response = $e->getResponse();
			if ( null === $response ) {
				throw BackendAdapterException::http_error( 0 );
			}
			throw $this->classify_http_error( $response->getStatusCode() );
		} catch ( GuzzleException $e ) {
			throw BackendAdapterException::http_error( 0 );
		}

		$status = $response->getStatusCode();
		if ( $status >= 400 ) {
			throw $this->classify_http_error( $status );
		}
		return $response;
	}

	/**
	 * Map an HTTP status to the appropriate exception shape.
	 *
	 * @param int $status HTTP status code.
	 * @return BackendAdapterException
	 */
	private function classify_http_error( int $status ): BackendAdapterException {
		if ( 401 === $status || 403 === $status ) {
			return BackendAdapterException::auth_failed();
		}
		if ( 429 === $status ) {
			return BackendAdapterException::rate_limited();
		}
		return BackendAdapterException::http_error( $status );
	}

	/**
	 * Decode the response body as JSON, raising on malformed output.
	 *
	 * @param ResponseInterface $response HTTP response.
	 * @return array<string, mixed>
	 *
	 * @throws BackendAdapterException When the body is not a JSON object.
	 */
	private function decode_json( ResponseInterface $response ): array {
		$data = json_decode( (string) $response->getBody(), true );
		if ( ! is_array( $data ) ) {
			throw BackendAdapterException::malformed_response( 'Response was not valid JSON.' );
		}
		return $data;
	}

	/**
	 * Refuse to send if the hourly budget is already used up.
	 *
	 * @return void
	 *
	 * @throws BackendAdapterException When the budget is exhausted.
	 */
	private function assert_rate_budget(): void {
		$used = (int) get_transient( self::RATE_LIMIT_KEY );
		if ( $used >= self::RATE_LIMIT_PER_HOUR ) {
			throw BackendAdapterException::rate_limited();
		}
	}

	/**
	 * Increment the hourly request counter.
	 *
	 * @return void
	 */
	private function record_request(): void {
		$used = (int) get_transient( self::RATE_LIMIT_KEY );
		set_transient( self::RATE_LIMIT_KEY, $used + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Open a local file for streaming upload.
	 *
	 * @param string $path Absolute path.
	 * @return resource
	 *
	 * @throws BackendAdapterException When the file is unreadable.
	 */
	private function open_file_for_upload( string $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors -- Streaming binary to a 3rd-party API; WP_Filesystem cannot return a stream; failure detected via false return below.
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new BackendAdapterException( sprintf( 'Could not open file for upload: %s', $path ) );
		}
		return $handle;
	}
}
