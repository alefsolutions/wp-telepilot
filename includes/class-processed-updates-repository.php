<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Processed_Updates_Repository {
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'telepilot_processed_updates';
	}

	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			telegram_update_id BIGINT UNSIGNED NOT NULL,
			received_at DATETIME NOT NULL,
			processed_at DATETIME NOT NULL,
			transport VARCHAR(20) NOT NULL DEFAULT 'webhook',
			result VARCHAR(20) NOT NULL DEFAULT 'processed',
			PRIMARY KEY  (telegram_update_id),
			KEY processed_at (processed_at),
			KEY transport (transport)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function has_processed( $update_id ) {
		global $wpdb;

		$update_id = absint( $update_id );

		if ( ! $update_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Telegram deduplication must read the current custom-table update ID.
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT telegram_update_id FROM %i WHERE telegram_update_id = %d LIMIT 1', self::table_name(), $update_id )
		);

		return ! empty( $found );
	}

	public static function mark_processed( $update_id, $transport, $result = 'processed' ) {
		global $wpdb;

		$update_id = absint( $update_id );

		if ( ! $update_id ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Persist Telegram update deduplication in the plugin-owned table.
		$wpdb->replace(
			self::table_name(),
			array(
				'telegram_update_id' => $update_id,
				'received_at'        => current_time( 'mysql', true ),
				'processed_at'       => current_time( 'mysql', true ),
				'transport'          => sanitize_key( $transport ),
				'result'             => sanitize_key( $result ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public static function purge_expired( $retention_days = 7 ) {
		global $wpdb;

		$retention_days = max( 1, absint( $retention_days ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $retention_days . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention maintenance deletes expired deduplication rows.
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE processed_at < %s', self::table_name(), $cutoff )
		);
	}
}
