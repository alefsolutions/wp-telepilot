<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Null-only jobs are internal continuations, not Telegram update IDs. */
class Telepilot_Queue_Schema {
	const REVISION = '1';
	const REVISION_OPTION = 'telepilot_queue_repair_revision';
	const STATUS_OPTION = 'telepilot_queue_repair_status';
	const LOCK_OPTION = 'telepilot_queue_repair_lock';
	const RETRY_SECONDS = 300;

	public static function table_exists() {
		global $wpdb;
		$table = Telepilot_Jobs_Repository::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Check the actual custom-table schema before enabling AI queue processing.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** Inspect before DDL. Never guess how to convert an unfamiliar type or index. */
	public static function inspect() {
		global $wpdb;
		$table = Telepilot_Jobs_Repository::table_name();
		$previous = $wpdb->suppress_errors();
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect real column and index metadata; cached readiness could allow unsafe queue writes.
			$column = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i WHERE Field=\'update_id\'', $table ), ARRAY_A );
			if ( ! $column || ! preg_match( '/^bigint(?:\(\d+\))? unsigned$/i', $column['Type'] ) || '' !== $column['Extra'] ) {
				return self::error( 'unexpected_column', __( 'The queue update_id column is missing or is not an ordinary BIGINT UNSIGNED. Ask your database administrator to inspect it; no conversion was attempted.', 'wp-telepilot' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect real column and index metadata; cached readiness could allow unsafe queue writes.
			$indexes = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=\'update_id\'', $table ), ARRAY_A );
			if ( count( $indexes ) !== 1 || '0' !== (string) $indexes[0]['Non_unique'] || 'update_id' !== $indexes[0]['Column_name'] || null !== $indexes[0]['Sub_part'] ) {
				return self::error( 'unexpected_index', __( 'The queue requires a single-column UNIQUE update_id index. Ask your database administrator to restore that index without deleting queue records, then retry the repair.', 'wp-telepilot' ) );
			}
			if ( null !== $column['Default'] ) {
				return self::error( 'unexpected_default', __( 'The queue update_id column has an unexpected default. Ask your database administrator to inspect it before retrying.', 'wp-telepilot' ) );
			}
			return array( 'ready' => 'YES' === $column['Null'], 'nullable' => $column['Null'] );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	public static function status() {
		$schema = self::inspect();
		$record = get_option( self::STATUS_OPTION, array() );
		$ready = ! is_wp_error( $schema ) && $schema['ready'] && self::REVISION === get_option( self::REVISION_OPTION );
		return array(
			'ready' => $ready,
			'message' => $ready ? __( 'Verified: queue schema supports AI continuations.', 'wp-telepilot' ) : ( is_wp_error( $schema ) ? $schema->get_error_message() : ( $record['message'] ?? __( 'Queue database repair is required. Use Retry Database Repair in diagnostics. AI provider requests are paused; ordinary commands remain available where possible.', 'wp-telepilot' ) ) ),
			'next_retry' => (int) ( $record['next_retry'] ?? 0 ),
			'correlation_id' => (string) ( $record['correlation_id'] ?? '' ),
			'error_category' => (string) ( $record['error_category'] ?? '' ),
			'database_errno' => $record['database_errno'] ?? null,
		);
	}

	public static function provider_gate() {
		$status = self::status();
		if ( ! $status['ready'] ) { delete_option( self::REVISION_OPTION ); }
		return $status['ready'] ? true : self::error( 'required', __( 'AI is paused because its queue database repair is unresolved. Ask an administrator to open WP Telepilot diagnostics and use Retry Database Repair. This request was not sent to OpenAI.', 'wp-telepilot' ) );
	}

	/** Called at bootstrap/admin time only, never from enqueue or a job transaction. */
	public static function ensure( $force = false ) {
		global $wpdb;
		if ( Telepilot_DB_Transaction::is_active() ) { return self::error( 'transaction', __( 'Database repair must run outside job execution. Retry from WP Telepilot diagnostics.', 'wp-telepilot' ) ); }
		if ( ! $force && self::REVISION === get_option( self::REVISION_OPTION ) ) { return true; }
		$record = get_option( self::STATUS_OPTION, array() );
		if ( ! $force && time() < (int) ( $record['next_retry'] ?? 0 ) ) { return self::error( 'deferred', $record['message'] ); }
		// The connection lock prevents overlap even if a long ALTER outlives the lease.
		$name = 'telepilot_queue_' . md5( DB_NAME . ':' . Telepilot_Jobs_Repository::table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
		$connection_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) );
		if ( '1' !== (string) $connection_lock ) {
			return null === $connection_lock ? self::failed( self::error( 'connection_lock', __( 'Database repair could not acquire a connection lock. Ask your host to check GET_LOCK support and database availability, then retry.', 'wp-telepilot' ) ) ) : self::error( 'busy', __( 'Another database repair is running. Wait for it to finish before retrying.', 'wp-telepilot' ) );
		}
		$owner = wp_generate_uuid4();
		$value = ( time() + self::RETRY_SECONDS ) . ':' . $owner;
		$acquired = false;
		$previous = $wpdb->suppress_errors();
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
			$old = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, self::LOCK_OPTION ) );
			if ( null !== $old && (int) $old > time() ) { return self::error( 'busy', __( 'A database repair lease is active. Retry in five minutes.', 'wp-telepilot' ) ); }
			if ( null !== $old ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name=%s AND option_value=%s', $wpdb->options, self::LOCK_OPTION, $old ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
			$acquired = 1 === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name,option_value,autoload) VALUES (%s,%s,\'no\')', $wpdb->options, self::LOCK_OPTION, $value ) );
			if ( ! $acquired ) { return self::error( 'lock', __( 'Could not acquire the database repair lock. Check database availability and options-table write permissions.', 'wp-telepilot' ) ); }
			wp_cache_delete( self::REVISION_OPTION, 'options' );
			wp_cache_delete( self::STATUS_OPTION, 'options' );
			$record = get_option( self::STATUS_OPTION, array() );
			if ( ! $force && self::REVISION === get_option( self::REVISION_OPTION ) ) { return true; }
			if ( ! $force && time() < (int) ( $record['next_retry'] ?? 0 ) ) { return self::error( 'deferred', $record['message'] ); }
			delete_option( self::REVISION_OPTION );
			$retry_at = time() + self::RETRY_SECONDS;
			update_option( self::STATUS_OPTION, array( 'message' => __( 'Queue repair has not finished. Retry Database Repair after five minutes if it was interrupted.', 'wp-telepilot' ), 'next_retry' => $retry_at ), false );
			if ( $retry_at !== ( get_option( self::STATUS_OPTION, array() )['next_retry'] ?? 0 ) ) {
				return self::error( 'record_failed', __( 'Could not save the database repair attempt. Check options-table write permissions before retrying; no schema change was attempted.', 'wp-telepilot' ) );
			}
			$schema = self::inspect();
			if ( is_wp_error( $schema ) ) { return self::failed( $schema ); }
			if ( ! $schema['ready'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
				$timeout = (int) $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
				$wpdb->query( 'SET SESSION lock_wait_timeout=15' );
				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
					$changed = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY COLUMN update_id BIGINT UNSIGNED NULL DEFAULT NULL', Telepilot_Jobs_Repository::table_name() ) );
					$details = false === $changed ? Telepilot_Jobs_Repository::capture_database_failure( 'queue_schema' ) : array();
				} finally {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
					$wpdb->query( $wpdb->prepare( 'SET SESSION lock_wait_timeout=%d', max( 1, $timeout ) ) );
				}
				if ( false === $changed ) { return self::failed( self::error( 'alter_failed', __( 'Queue repair could not change update_id to nullable. Ask your host to check ALTER permission and database locks, then use Retry Database Repair.', 'wp-telepilot' ), $details ) ); }
			}
			$verified = self::inspect();
			if ( is_wp_error( $verified ) || ! $verified['ready'] ) { return self::failed( is_wp_error( $verified ) ? $verified : self::error( 'verification', __( 'Queue repair did not verify. Ask your host to inspect update_id nullability and its unique index, then retry.', 'wp-telepilot' ) ) ); }
			update_option( self::REVISION_OPTION, self::REVISION, false );
			if ( self::REVISION !== get_option( self::REVISION_OPTION ) ) { return self::failed( self::error( 'record_failed', __( 'Queue schema is compatible, but its verified revision could not be saved. Check options-table write permissions and retry.', 'wp-telepilot' ) ) ); }
			delete_option( self::STATUS_OPTION );
			return true;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
			if ( $acquired ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name=%s AND option_value=%s', $wpdb->options, self::LOCK_OPTION, $value ) ); }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The versioned, administrator-retryable schema repair uses connection locks and atomic leases outside job transactions.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$wpdb->suppress_errors( $previous );
		}
	}

	private static function failed( $error ) {
		$details = $error->get_error_data() ?: array();
		update_option( self::STATUS_OPTION, array( 'message' => $error->get_error_message(), 'next_retry' => time() + self::RETRY_SECONDS, 'correlation_id' => $details['correlation_id'] ?? wp_generate_uuid4(), 'error_category' => $details['error_category'] ?? $error->get_error_code(), 'database_errno' => $details['database_errno'] ?? null ), false );
		return $error;
	}

	private static function error( $code, $message, $data = array() ) { return new WP_Error( 'telepilot_queue_repair_' . $code, $message, $data ); }
}
