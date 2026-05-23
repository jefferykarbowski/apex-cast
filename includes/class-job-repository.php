<?php
/**
 * Job repository.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

/**
 * Read / write operations for the `apex_cast_jobs` custom table.
 *
 * Each "send" creates one row; subsequent status polls update the same row.
 * JSON-shaped columns (`platforms`, `platform_results`, `drafts_snapshot`)
 * round-trip through `wp_json_encode` here.
 */
final class JobRepository {

	/**
	 * Resolve the fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'apex_cast_jobs';
	}

	/**
	 * Insert a new job row in the "queued" state.
	 *
	 * @param int                                 $product_id WooCommerce product ID.
	 * @param int                                 $user_id    WordPress user who triggered the send.
	 * @param string                              $backend_id Adapter identifier (e.g. "postiz").
	 * @param string[]                            $platforms  Platforms being sent to.
	 * @param array<string, array<string, mixed>> $drafts     Snapshot of the drafts being sent.
	 * @return int The inserted row ID.
	 */
	public function create( int $product_id, int $user_id, string $backend_id, array $platforms, array $drafts ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to a plugin-owned custom table.
		$wpdb->insert(
			self::table(),
			array(
				'product_id'      => $product_id,
				'user_id'         => $user_id,
				'backend_id'      => $backend_id,
				'status'          => 'queued',
				'platforms'       => (string) wp_json_encode( $platforms ),
				'drafts_snapshot' => (string) wp_json_encode( $drafts ),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the status (and optional backend identifiers) of a job row.
	 *
	 * @param int                  $id              Job row ID.
	 * @param string               $status          New status.
	 * @param string               $backend_post_id Backend identifier (optional, only set on success).
	 * @param array<string, mixed> $platform_results Per-platform result map (optional).
	 * @return void
	 */
	public function update_status( int $id, string $status, string $backend_post_id = '', array $platform_results = array() ): void {
		global $wpdb;

		$data   = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%s' );

		if ( '' !== $backend_post_id ) {
			$data['backend_post_id'] = $backend_post_id;
			$format[]                = '%s';
		}
		if ( ! empty( $platform_results ) ) {
			$data['platform_results'] = (string) wp_json_encode( $platform_results );
			$format[]                 = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating a plugin-owned custom table.
		$wpdb->update( self::table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Fetch a single job row by ID.
	 *
	 * @param int $id Job row ID.
	 * @return array<string, mixed>|null Null if not found.
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reading from a plugin-owned table; the table name is built from $wpdb->prefix and is not user-controlled.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Recent jobs for a product, newest first.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @param int $limit      Max rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_for_product( int $product_id, int $limit = 10 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- See find().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE product_id = %d ORDER BY created_at DESC LIMIT %d',
				$product_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
