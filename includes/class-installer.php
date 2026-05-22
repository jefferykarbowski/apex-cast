<?php
/**
 * Plugin installer.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

/**
 * Handles activation / deactivation: creates the two custom tables, seeds the
 * default settings option, and clears scheduled events on deactivate.
 *
 * Tables use `longtext` for JSON-shaped columns rather than the native JSON
 * type because dbDelta does not understand JSON and many shared hosts still
 * run MySQL versions where JSON support is patchy. Application code is the
 * sole reader/writer of these columns and round-trips JSON itself.
 */
final class Installer {

	private const DB_VERSION_OPTION = 'apex_cast_db_version';
	private const DB_VERSION        = '1';

	/**
	 * Activation entry point.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::seed_settings();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Deactivation entry point.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'apex_cast_daily_maintenance' );
	}

	/**
	 * Create / update the custom tables via dbDelta.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$jobs_table      = $wpdb->prefix . 'apex_cast_jobs';
		$logs_table      = $wpdb->prefix . 'apex_cast_logs';

		// Note the two-space gap after PRIMARY KEY — dbDelta requires it.
		$jobs_sql = "CREATE TABLE {$jobs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			backend_id varchar(64) NOT NULL,
			backend_post_id varchar(255) DEFAULT NULL,
			status varchar(32) NOT NULL DEFAULT 'queued',
			platforms longtext NOT NULL,
			platform_results longtext DEFAULT NULL,
			drafts_snapshot longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$logs_sql = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned DEFAULT NULL,
			level varchar(16) NOT NULL,
			component varchar(64) NOT NULL,
			message text NOT NULL,
			context longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY created_at (created_at),
			KEY level (level)
		) {$charset_collate};";

		dbDelta( $jobs_sql );
		dbDelta( $logs_sql );
	}

	/**
	 * Seed the settings option on first activation only.
	 *
	 * Subsequent activations leave the user's stored settings untouched.
	 *
	 * @return void
	 */
	private static function seed_settings(): void {
		if ( false === get_option( 'apex_cast_settings', false ) ) {
			add_option( 'apex_cast_settings', Settings::defaults() );
		}
	}
}
