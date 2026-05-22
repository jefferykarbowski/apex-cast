<?php
/**
 * Logger.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

/**
 * Persistent log writer backed by the `apex_cast_logs` custom table.
 *
 * Surface area is intentionally tiny: every callsite goes through `log()` or
 * one of the level-specific convenience wrappers. The table is created by
 * the Installer; this class never auto-creates it.
 */
final class Logger {

	public const LEVEL_DEBUG = 'debug';
	public const LEVEL_INFO  = 'info';
	public const LEVEL_WARN  = 'warn';
	public const LEVEL_ERROR = 'error';

	/**
	 * Resolve the fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'apex_cast_logs';
	}

	/**
	 * Write a single log row.
	 *
	 * @param string               $level     One of the LEVEL_* constants.
	 * @param string               $component Dotted component identifier (e.g. "ai.anthropic", "backend.postiz").
	 * @param string               $message   Short human-readable message.
	 * @param array<string, mixed> $context   Structured detail serialized to JSON. Pass an empty array for none.
	 * @param int|null             $job_id    Optional foreign key into apex_cast_jobs.
	 *
	 * @return void
	 */
	public function log( string $level, string $component, string $message, array $context = array(), ?int $job_id = null ): void {
		global $wpdb;

		$encoded_context = empty( $context )
			? null
			: (string) wp_json_encode( $context );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing a single row to a plugin-owned table; no caching concerns apply.
		$wpdb->insert(
			self::table(),
			array(
				'job_id'     => $job_id,
				'level'      => $level,
				'component'  => $component,
				'message'    => $message,
				'context'    => $encoded_context,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Convenience wrapper for LEVEL_DEBUG.
	 *
	 * @param string               $component See log().
	 * @param string               $message   See log().
	 * @param array<string, mixed> $context   See log().
	 * @param int|null             $job_id    See log().
	 * @return void
	 */
	public function debug( string $component, string $message, array $context = array(), ?int $job_id = null ): void {
		$this->log( self::LEVEL_DEBUG, $component, $message, $context, $job_id );
	}

	/**
	 * Convenience wrapper for LEVEL_INFO.
	 *
	 * @param string               $component See log().
	 * @param string               $message   See log().
	 * @param array<string, mixed> $context   See log().
	 * @param int|null             $job_id    See log().
	 * @return void
	 */
	public function info( string $component, string $message, array $context = array(), ?int $job_id = null ): void {
		$this->log( self::LEVEL_INFO, $component, $message, $context, $job_id );
	}

	/**
	 * Convenience wrapper for LEVEL_WARN.
	 *
	 * @param string               $component See log().
	 * @param string               $message   See log().
	 * @param array<string, mixed> $context   See log().
	 * @param int|null             $job_id    See log().
	 * @return void
	 */
	public function warn( string $component, string $message, array $context = array(), ?int $job_id = null ): void {
		$this->log( self::LEVEL_WARN, $component, $message, $context, $job_id );
	}

	/**
	 * Convenience wrapper for LEVEL_ERROR.
	 *
	 * @param string               $component See log().
	 * @param string               $message   See log().
	 * @param array<string, mixed> $context   See log().
	 * @param int|null             $job_id    See log().
	 * @return void
	 */
	public function error( string $component, string $message, array $context = array(), ?int $job_id = null ): void {
		$this->log( self::LEVEL_ERROR, $component, $message, $context, $job_id );
	}
}
