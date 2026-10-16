<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Durable inbox/outbox. Execution outcomes are immutable and delivery is retried independently. */
class Telepilot_Jobs_Repository {
	public static function table_name() { global $wpdb; return $wpdb->prefix . 'telepilot_jobs'; }
	public static function operations_table() { global $wpdb; return $wpdb->prefix . 'telepilot_operations'; }
	public static function locks_table() { global $wpdb; return $wpdb->prefix . 'telepilot_job_locks'; }

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table = self::table_name();
		dbDelta( "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			update_id BIGINT UNSIGNED NULL DEFAULT NULL,
			transport VARCHAR(20) NOT NULL DEFAULT 'webhook',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payload LONGTEXT NOT NULL,
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			placeholder_chat_id VARCHAR(64) NULL,
			placeholder_message_id BIGINT UNSIGNED NULL,
			command_name VARCHAR(64) NULL,
			priority SMALLINT UNSIGNED NOT NULL DEFAULT 50,
			stale_after DATETIME NULL,
			job_group VARCHAR(191) NULL,
			is_replaceable TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			available_at DATETIME NOT NULL,
			locked_at DATETIME NULL,
			lease_token VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY update_id (update_id),
			KEY status_priority_available_at (status, priority, available_at),
			KEY actor_id (actor_id),
			KEY placeholder_chat_id (placeholder_chat_id)
		) ENGINE=InnoDB $charset;" );
		$table = self::operations_table();
		dbDelta( "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'accepted',
			result LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id)
		) ENGINE=InnoDB $charset;" );
		$table = self::locks_table();
		dbDelta( "CREATE TABLE $table (
			chat_id VARCHAR(64) NOT NULL,
			job_id BIGINT UNSIGNED NOT NULL,
			owner VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (chat_id),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB $charset;" );
	}

	public static function enqueue( $update_id, $transport, $payload, $command_name = '', $placeholder = array(), $options = array() ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $update_id && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table_name() . ' WHERE update_id=%d', $update_id ) ) ) {
			return 'duplicate';
		}
		Telepilot_DB_Transaction::begin();
		$previous_errors = $wpdb->suppress_errors();
		$inserted = $wpdb->insert( self::table_name(), array(
			'update_id' => $update_id ? absint( $update_id ) : null,
			'transport' => sanitize_key( $transport ),
			'payload' => wp_json_encode( $payload ),
			'actor_id' => absint( $payload['_telepilot_actor']['user_id'] ?? 0 ),
			'placeholder_chat_id' => (string) ( $placeholder['chat_id'] ?? '' ),
			'placeholder_message_id' => absint( $placeholder['message_id'] ?? 0 ),
			'command_name' => sanitize_text_field( $command_name ),
			'priority' => max( 1, min( 999, (int) ( $options['priority'] ?? 50 ) ) ),
			'stale_after' => $options['stale_after'] ?? null,
			'job_group' => $options['job_group'] ?? null,
			'is_replaceable' => empty( $options['is_replaceable'] ) ? 0 : 1,
			'available_at' => gmdate( 'Y-m-d H:i:s', time() + max( 0, min( 60, (int) ( $options['delay'] ?? 0 ) ) ) ), 'created_at' => $now, 'updated_at' => $now,
		) );
		if ( false === $inserted ) {
			$details = self::capture_database_failure( 'queue_insert' );
			$wpdb->suppress_errors( $previous_errors );
			$duplicate = $update_id && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table_name() . ' WHERE update_id=%d', $update_id ) );
			Telepilot_DB_Transaction::rollback();
			if ( $duplicate ) { return 'duplicate'; }
			if ( ! $update_id && 'null_constraint' === $details['error_category'] ) { delete_option( Telepilot_Queue_Schema::REVISION_OPTION ); }
			self::record_database_failure( $details );
			return new WP_Error( 'telepilot_job_enqueue_failed', __( 'Unable to persist request. No action was executed.', 'wp-telepilot' ), $details );
		}
		$id = (int) $wpdb->insert_id;
		$result = $options['result'] ?? null;
		if ( false === $wpdb->insert( self::operations_table(), array( 'job_id' => $id, 'state' => null === $result ? 'accepted' : 'completed', 'result' => null === $result ? null : wp_json_encode( $result ), 'created_at' => $now, 'updated_at' => $now ) ) ) {
			$details = self::capture_database_failure( 'operation_insert' );
			$wpdb->suppress_errors( $previous_errors );
			Telepilot_DB_Transaction::rollback();
			self::record_database_failure( $details );
			return new WP_Error( 'telepilot_operation_enqueue_failed', __( 'Unable to persist operation.', 'wp-telepilot' ), $details );
		}
		$wpdb->suppress_errors( $previous_errors );
		Telepilot_DB_Transaction::commit();
		return $id;
	}

	/** Capture immediately: even a duplicate check or rollback clears the driver error. */
	public static function capture_database_failure( $stage ) {
		global $wpdb;
		$errno = $wpdb->dbh instanceof mysqli ? mysqli_errno( $wpdb->dbh ) : 0;
		$categories = array( 1048 => 'null_constraint', 1062 => 'duplicate_key', 1142 => 'permission_denied', 1146 => 'missing_table', 1205 => 'lock_timeout', 1213 => 'deadlock', 2006 => 'connection_lost', 2013 => 'connection_lost' );
		return array( 'error_category' => $categories[$errno] ?? ( $errno ? 'database_error' : 'insert_validation' ), 'database_errno' => $errno ?: null, 'correlation_id' => wp_generate_uuid4(), 'stage' => sanitize_key( $stage ) );
	}

	private static function record_database_failure( $details ) {
		update_option( 'telepilot_queue_enqueue_failure', array_merge( $details, array( 'recorded_at' => time() ) ), false );
		Telepilot_Audit_Log_Repository::log( array( 'action_name' => 'queue_enqueue_failed', 'resource_type' => 'queue', 'was_successful' => 0, 'context' => $details ) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT j.*, o.state AS execution_state, o.result AS outcome FROM ' . self::table_name() . ' j LEFT JOIN ' . self::operations_table() . ' o ON o.job_id=j.id WHERE j.id=%d', $id ), ARRAY_A );
	}

	public static function claim_pending_jobs( $limit = 1, $lock_timeout = 180 ) {
		global $wpdb;
		self::recover_expired();
		$now = gmdate( 'Y-m-d H:i:s' );
		$table = self::table_name();
		$operations = self::operations_table();
		$locks = self::locks_table();
		// Expiry must also progress in quarantined chats; completed outcomes are never re-executed.
		$expired = $wpdb->query( $wpdb->prepare( "UPDATE $table j INNER JOIN $operations o ON o.job_id=j.id SET j.status='cancelled',j.last_error='Request expired before execution.',j.updated_at=%s WHERE j.status='pending' AND o.state='accepted' AND j.stale_after IS NOT NULL AND j.stale_after<=%s", $now, $now ) );
		if ( false === $expired ) { return array(); }
		$rank = "CASE WHEN j.transport IN ('webhook','polling') AND o.state='accepted' THEN 0 WHEN j.transport='ai_continuation' AND o.state='accepted' THEN 1 WHEN j.transport='notification' THEN 3 ELSE 2 END";
		$cursor = null;
		do {
			$after = $cursor ? $wpdb->prepare( " AND ($rank,j.priority,j.available_at,j.id)>(%d,%d,%s,%d)", $cursor['queue_rank'], $cursor['priority'], $cursor['available_at'], $cursor['id'] ) : '';
			$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT j.*,o.state AS execution_state,o.result AS outcome,$rank AS queue_rank FROM $table j INNER JOIN $operations o ON j.id=o.job_id
				WHERE j.status='pending' AND j.available_at<=%s
				AND NOT EXISTS (SELECT 1 FROM $table q WHERE q.placeholder_chat_id <=> j.placeholder_chat_id AND q.status='uncertain')
				AND NOT EXISTS (SELECT 1 FROM $locks l WHERE l.chat_id=COALESCE(j.placeholder_chat_id,''))
				AND (NOT (j.transport IN ('webhook','polling') AND o.state='accepted') OR NOT EXISTS (
					SELECT 1 FROM $table h INNER JOIN $operations ho ON ho.job_id=h.id WHERE h.placeholder_chat_id <=> j.placeholder_chat_id AND h.id<j.id AND h.transport IN ('webhook','polling') AND h.status IN ('pending','processing') AND ho.state IN ('accepted','executing')))
				$after ORDER BY queue_rank,j.priority,j.available_at,j.id LIMIT 100", $now ), ARRAY_A );
			if ( ! is_array( $jobs ) ) { return array(); }
			foreach ( $jobs as $job ) {
				$cursor = $job;
				$chat = (string) $job['placeholder_chat_id'];
				$owner = wp_generate_uuid4();
				$locked = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::locks_table() . ' (chat_id,job_id,owner,expires_at) VALUES (%s,%d,%s,%s)', $chat, $job['id'], $owner, gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $lock_timeout ) ) ) );
				if ( 1 !== $locked ) { continue; }
				// A different worker may have quarantined this chat after candidate selection.
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE COALESCE(placeholder_chat_id,'')=%s AND status='uncertain' LIMIT 1", $chat ) ) ) { self::release_lock( $job['id'], $owner ); continue; }
				$claimed = $wpdb->update( self::table_name(), array( 'status' => 'processing', 'lease_token' => $owner, 'locked_at' => $now, 'updated_at' => $now ), array( 'id' => $job['id'], 'status' => 'pending' ) );
				if ( ! $claimed ) { self::release_lock( $job['id'], $owner ); continue; }
				$job['lease_token'] = $owner;
				$job['status'] = 'processing';
				// Claim only the job we are about to execute; never lease a whole batch in advance.
				return array( $job );
			}
		} while ( count( $jobs ) === 100 );
		return array();
	}

	public static function begin_execution( $id, $owner ) {
		global $wpdb;
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::operations_table() . ' o INNER JOIN ' . self::table_name() . ' j ON j.id=o.job_id SET o.state=%s,o.updated_at=%s WHERE j.id=%d AND j.status=%s AND j.lease_token=%s AND o.state=%s', 'executing', gmdate( 'Y-m-d H:i:s' ), $id, 'processing', $owner, 'accepted' ) );
	}

	public static function save_outcome( $id, $owner, $result ) {
		global $wpdb;
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::operations_table() . ' o INNER JOIN ' . self::table_name() . ' j ON j.id=o.job_id SET o.state=%s,o.result=%s,o.updated_at=%s WHERE j.id=%d AND j.status=%s AND j.lease_token=%s AND o.state=%s', 'completed', wp_json_encode( $result ), gmdate( 'Y-m-d H:i:s' ), $id, 'processing', $owner, 'executing' ) );
	}

	public static function save_placeholder( $id, $owner, $placeholder ) {
		global $wpdb;
		return $wpdb->update( self::table_name(), array( 'placeholder_message_id' => absint( $placeholder['message_id'] ?? 0 ) ), array( 'id' => $id, 'lease_token' => $owner, 'status' => 'processing' ) );
	}

	public static function mark_complete( $id, $owner = '' ) { return self::finish( $id, $owner, 'complete' ); }
	public static function mark_uncertain( $id, $owner, $message = 'Execution interrupted. Reconcile the target before cancelling this job.' ) { return self::finish( $id, $owner, 'uncertain', $message ); }

	public static function mark_failed( $id, $error, $owner = '' ) {
		global $wpdb;
		$job = self::get( $id );
		if ( ! $job || ! $owner || $job['lease_token'] !== $owner ) { return false; }
		if ( 'completed' !== $job['execution_state'] ) { return self::mark_uncertain( $id, $owner ); }
		$attempts = (int) $job['attempts'] + 1;
		$status = $attempts >= 3 ? 'failed' : 'pending';
		$updated = $wpdb->update( self::table_name(), array( 'status' => $status, 'attempts' => $attempts, 'last_error' => sanitize_text_field( $error ), 'available_at' => gmdate( 'Y-m-d H:i:s', time() + min( 300, 15 * $attempts ) ), 'locked_at' => null, 'lease_token' => null, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id, 'lease_token' => $owner, 'status' => 'processing' ) );
		self::release_lock( $id, $owner );
		return (bool) $updated;
	}

	private static function finish( $id, $owner, $status, $error = '' ) {
		global $wpdb;
		if ( ! $owner ) { return false; }
		$updated = $wpdb->update( self::table_name(), array( 'status' => $status, 'last_error' => $error, 'locked_at' => null, 'lease_token' => null, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id, 'lease_token' => $owner, 'status' => 'processing' ) );
		self::release_lock( $id, $owner );
		return (bool) $updated;
	}

	private static function release_lock( $id, $owner ) {
		global $wpdb;
		$wpdb->delete( self::locks_table(), array( 'job_id' => $id, 'owner' => $owner ) );
	}

	public static function cancel( $id, $reason = 'Cancelled.' ) {
		global $wpdb;
		// Never cancel a live worker. Uncertain jobs require an administrator's reconciliation.
		return (bool) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table_name() . ' SET status=%s,last_error=%s,updated_at=%s WHERE id=%d AND status IN (%s,%s,%s)', 'cancelled', $reason, gmdate( 'Y-m-d H:i:s' ), $id, 'pending', 'failed', 'uncertain' ) );
	}

	public static function recover_expired() {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$expired = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::locks_table() . ' WHERE expires_at<=%s', $now ), ARRAY_A );
		foreach ( $expired as $lock ) {
			$job = self::get( $lock['job_id'] );
			if ( $job && 'completed' === $job['execution_state'] ) {
				self::mark_failed( $job['id'], 'Delivery worker lease expired.', $lock['owner'] );
			} elseif ( $job && 'accepted' === $job['execution_state'] ) {
				self::finish( $job['id'], $lock['owner'], 'pending' );
			} else {
				self::mark_uncertain( $lock['job_id'], $lock['owner'] );
			}
			self::release_lock( $lock['job_id'], $lock['owner'] );
		}
	}

	public static function supersede_replaceable_jobs( $current_id, $chat, $group, $message_id = 0 ) {
		// Keep accepted requests durable; priority replaces the previous destructive coalescing policy.
		return array();
	}

	public static function status_counts() {
		global $wpdb;
		$counts = array_fill_keys( array( 'pending', 'processing', 'failed', 'complete', 'stale', 'uncertain', 'cancelled' ), 0 );
		foreach ( $wpdb->get_results( 'SELECT status,COUNT(*) AS total FROM ' . self::table_name() . ' GROUP BY status', ARRAY_A ) as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	public static function pending_count() { return self::status_counts()['pending']; }

	public static function personal_jobs( $actor_id, $page = 1, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id,status,command_name,payload,created_at FROM ' . self::table_name() . ' WHERE actor_id=%d ORDER BY id LIMIT %d OFFSET %d', $actor_id, $limit, ( max( 1, (int) $page ) - 1 ) * $limit ), ARRAY_A );
	}

	public static function erase_actor( $actor_id ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare( "UPDATE " . self::table_name() . " j LEFT JOIN " . self::operations_table() . " o ON j.id=o.job_id SET j.payload='',j.actor_id=0,j.placeholder_chat_id=NULL,j.lease_token=NULL,j.status=IF(j.status='processing','uncertain','cancelled'),o.result=NULL WHERE j.actor_id=%d", $actor_id ) );
	}

	public static function stop_pending() {
		global $wpdb;
		$wpdb->query( "UPDATE " . self::table_name() . " SET status=IF(status='processing','uncertain','cancelled'),lease_token=NULL,last_error='Plugin deactivated; pending access revoked.' WHERE status IN ('pending','processing')" );
	}

	public static function quarantine_legacy() {
		global $wpdb;
		$wpdb->query( "UPDATE " . self::table_name() . " j LEFT JOIN " . self::operations_table() . " o ON j.id=o.job_id SET j.status='uncertain',j.last_error='Legacy request quarantined; inspect the target before cancellation.' WHERE o.job_id IS NULL AND j.status IN ('pending','processing','failed')" );
	}

	public static function purge_payloads() {
		global $wpdb;
		$now = time();
		$wpdb->query( $wpdb->prepare( "UPDATE " . self::table_name() . " j LEFT JOIN " . self::operations_table() . " o ON j.id=o.job_id SET j.payload='',o.result=NULL WHERE (j.status IN ('complete','cancelled','stale','uncertain') AND j.updated_at<=%s) OR (j.status IN ('pending','failed') AND o.state='completed' AND j.created_at<=%s)", gmdate( 'Y-m-d H:i:s', $now - 23 * HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now - 167 * HOUR_IN_SECONDS ) ) );
	}
}
