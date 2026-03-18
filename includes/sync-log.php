<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sync_Log {

	private const TABLE_BASE = '360_api_sync_log';
	private static $table_ready = false;

	public static function install(): void {
		self::$table_ready = true;

		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			sync_time DATETIME NOT NULL,
			context VARCHAR(20) NOT NULL DEFAULT 'manual',
			clinics_processed INT(11) UNSIGNED NOT NULL DEFAULT 0,
			doctors_processed INT(11) UNSIGNED NOT NULL DEFAULT 0,
			images_imported INT(11) UNSIGNED NOT NULL DEFAULT 0,
			errors LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY sync_time (sync_time)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public static function log_run( array $payload ): void {
		self::ensure_table();

		global $wpdb;

		$errors = $payload['errors'] ?? array();
		if ( ! is_array( $errors ) ) {
			$errors = array( (string) $errors );
		}

		$wpdb->insert(
			self::table_name(),
			array(
				'sync_time'          => gmdate( 'Y-m-d H:i:s' ),
				'context'            => sanitize_key( (string) ( $payload['context'] ?? 'manual' ) ),
				'clinics_processed'  => max( 0, (int) ( $payload['clinics_processed'] ?? 0 ) ),
				'doctors_processed'  => max( 0, (int) ( $payload['doctors_processed'] ?? 0 ) ),
				'images_imported'    => max( 0, (int) ( $payload['images_imported'] ?? 0 ) ),
				'errors'             => wp_json_encode( array_values( array_map( 'strval', $errors ) ) ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $limit = 100 ): array {
		self::ensure_table();

		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$sql   = $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT %d', $limit );
		$rows  = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public static function clear(): void {
		self::ensure_table();

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	private static function ensure_table(): void {
		if ( self::$table_ready ) {
			return;
		}

		self::install();
	}
}
