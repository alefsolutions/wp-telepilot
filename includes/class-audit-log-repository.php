<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Audit_Log_Repository {
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'telepilot_audit_logs';
	}

	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			telegram_user_id VARCHAR(64) NULL,
			chat_id VARCHAR(64) NULL,
			action_name VARCHAR(120) NOT NULL,
			resource_type VARCHAR(120) NULL,
			resource_id VARCHAR(120) NULL,
			before_state LONGTEXT NULL,
			after_state LONGTEXT NULL,
			was_successful TINYINT(1) NOT NULL DEFAULT 1,
			failure_reason TEXT NULL,
			ip_address VARCHAR(45) NULL,
			context LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY action_name (action_name),
			KEY wp_user_id (wp_user_id),
			KEY chat_id (chat_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function log( $data ) {
		global $wpdb;

		$defaults = array(
			'created_at'       => current_time( 'mysql', true ),
			'wp_user_id'       => null,
			'telegram_user_id' => null,
			'chat_id'          => null,
			'action_name'      => 'unknown_action',
			'resource_type'    => null,
			'resource_id'      => null,
			'before_state'     => null,
			'after_state'      => null,
			'was_successful'   => 1,
			'failure_reason'   => null,
			'ip_address'       => null,
			'context'          => null,
		);

		$record = Telepilot_Data_Redactor::redact( wp_parse_args( $data, $defaults ) );
		$record['ip_address'] = null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WordPress has no audit-table API; persist the redacted record through wpdb.
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'       => $record['created_at'],
				'wp_user_id'       => $record['wp_user_id'],
				'telegram_user_id' => $record['telegram_user_id'],
				'chat_id'          => $record['chat_id'],
				'action_name'      => $record['action_name'],
				'resource_type'    => $record['resource_type'],
				'resource_id'      => $record['resource_id'],
				'before_state'     => self::maybe_encode_json( $record['before_state'] ),
				'after_state'      => self::maybe_encode_json( $record['after_state'] ),
				'was_successful'   => (int) $record['was_successful'],
				'failure_reason'   => $record['failure_reason'],
				'ip_address'       => $record['ip_address'],
				'context'          => self::maybe_encode_json( $record['context'] ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	public static function recent_logs( $limit = 10 ) {
		global $wpdb;

		$limit = max( 1, absint( $limit ) );


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The audit feed must immediately include new events and reflect privacy erasure.
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d', self::table_name(), $limit ),
			ARRAY_A
		);
	}

	public static function latest_log_by_action( $action_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the latest event for operational diagnostics without retaining erased data in a cache.
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE action_name = %s ORDER BY created_at DESC LIMIT 1', self::table_name(), $action_name ),
			ARRAY_A
		);
	}

	public static function purge_expired_logs( $retention_days ) {
		global $wpdb;

		$retention_days = max( 1, absint( $retention_days ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $retention_days . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention cleanup deletes expired records from the plugin-owned audit table.
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table_name(), $cutoff )
		);
	}

	public static function find_personal_logs( $wp_user_id, $telegram_user_id = '', $chat_id = '', $page = 1, $limit = 50 ) {
		global $wpdb;

		$page       = max( 1, absint( $page ) );
		$limit      = max( 1, absint( $limit ) );
		$offset     = ( $page - 1 ) * $limit;
		if ( ! $wp_user_id && '' === (string) $telegram_user_id && '' === (string) $chat_id ) { return array(); }

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export reads current matching audit rows, including the latest erasure state.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE (%d<>0 AND wp_user_id=%d) OR (%s<>'' AND telegram_user_id=%s) OR (%s<>'' AND chat_id=%s) ORDER BY created_at DESC LIMIT %d OFFSET %d",
				self::table_name(), absint( $wp_user_id ), absint( $wp_user_id ), (string) $telegram_user_id, (string) $telegram_user_id, (string) $chat_id, (string) $chat_id, $limit, $offset
			),
			ARRAY_A
		);
	}

	public static function anonymize_personal_logs( $wp_user_id, $telegram_user_id = '', $chat_id = '' ) {
		global $wpdb;
		if ( ! $wp_user_id && '' === (string) $telegram_user_id && '' === (string) $chat_id ) { return 0; }
		$previous = $wpdb->suppress_errors();
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure atomically anonymizes every matching custom-table row.
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE %i SET wp_user_id=NULL,telegram_user_id=NULL,chat_id=NULL,before_state=NULL,after_state=NULL,ip_address=NULL,context=NULL WHERE (%d<>0 AND wp_user_id=%d) OR (%s<>'' AND telegram_user_id=%s) OR (%s<>'' AND chat_id=%s)",
				self::table_name(), (int) $wp_user_id, (int) $wp_user_id, (string) $telegram_user_id, (string) $telegram_user_id, (string) $chat_id, (string) $chat_id
			) );
		} finally { $wpdb->suppress_errors( $previous ); }
		return false === $updated ? new WP_Error( 'telepilot_privacy_audit_failed', __( 'Audit data could not be erased. Retry the erasure after database availability is restored.', 'wp-telepilot' ) ) : (int) $updated;
	}

	private static function maybe_encode_json( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}

		return $value;
	}

	private static function detect_ip_address() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return null;
	}
}
