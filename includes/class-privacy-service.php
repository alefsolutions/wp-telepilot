<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Privacy_Service {
	const EXPORTER_ID = 'wp-telepilot-exporter';
	const ERASER_ID   = 'wp-telepilot-eraser';
	const PAGE_SIZE   = 50;

	public function register() {
		add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	public function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'WP Telepilot lets approved WordPress users link a Telegram account to a site account and then send or receive operational bot messages through Telegram.', 'wp-telepilot' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Once configured, WP Telepilot sends requests to the Telegram Bot API to receive webhook or polling updates and to deliver bot responses back to linked chats. No Telegram traffic is sent until a site administrator adds a bot token.', 'wp-telepilot' ) . '</p>';
		$content .= '<p>' . esc_html__( 'WP Telepilot stores Telegram linkage metadata such as Telegram user IDs, chat IDs, usernames, link timestamps, audit-log records, and limited diagnostics needed to secure and operate the integration.', 'wp-telepilot' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Accepted requests and outcomes are temporarily stored in a background queue. Scheduled maintenance removes terminal payloads within 24 hours and failed delivery payloads within seven days. Configure reliable WordPress cron to enforce retention. Security links expire within one hour and are single-use. Logs redact credentials, security links and message payloads.', 'wp-telepilot' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The plugin includes WordPress personal-data export and erasure hooks for linked Telegram account data. When an erasure request is processed, WP Telepilot removes linked Telegram metadata and anonymizes matching audit-log payloads while preserving basic operational event records.', 'wp-telepilot' ) . '</p>';
		$content .= '<p>' . sprintf(
			wp_kses(
				/* translators: 1: Telegram privacy policy link, 2: Telegram terms link. */
				__( 'Telegram is a third-party service. Review the <a href="%1$s" target="_blank" rel="noopener noreferrer">Telegram Privacy Policy</a> and <a href="%2$s" target="_blank" rel="noopener noreferrer">Telegram Terms of Service</a> before enabling this integration.', 'wp-telepilot' ),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_url( 'https://telegram.org/privacy' ),
			esc_url( 'https://telegram.org/tos' )
		) . '</p>';

		wp_add_privacy_policy_content( __( 'WP Telepilot', 'wp-telepilot' ), wp_kses_post( $content ) );
	}

	public function register_exporter( $exporters ) {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'WP Telepilot data', 'wp-telepilot' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'WP Telepilot data', 'wp-telepilot' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	public function export_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page             = max( 1, absint( $page ) );
		$data_to_export   = array();
		$telegram_user_id = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true );
		$chat_id          = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true );

		if ( 1 === $page ) {
			$linkage_item = $this->build_linkage_export_item( $user );

			if ( ! empty( $linkage_item ) ) {
				$data_to_export[] = $linkage_item;
			}
		}

		$logs = Telepilot_Audit_Log_Repository::find_personal_logs( $user->ID, $telegram_user_id, $chat_id, $page, self::PAGE_SIZE );
		$jobs = Telepilot_Jobs_Repository::personal_jobs( $user->ID, $page, self::PAGE_SIZE );
		foreach ( $jobs as $job ) {
			$data_to_export[] = array( 'group_id' => 'wp-telepilot-jobs', 'group_label' => __( 'WP Telepilot queued requests', 'wp-telepilot' ), 'item_id' => 'telepilot-job-' . $job['id'], 'data' => array(
				array( 'name' => __( 'Operation ID', 'wp-telepilot' ), 'value' => (string) $job['id'] ),
				array( 'name' => __( 'Status', 'wp-telepilot' ), 'value' => $job['status'] ),
				array( 'name' => __( 'Command', 'wp-telepilot' ), 'value' => $job['command_name'] ),
				array( 'name' => __( 'Accepted', 'wp-telepilot' ), 'value' => $job['created_at'] ),
				array( 'name' => __( 'Request (security fields redacted)', 'wp-telepilot' ), 'value' => wp_json_encode( Telepilot_Data_Redactor::redact( json_decode( $job['payload'], true ) ) ) ),
			) );
		}

		foreach ( $logs as $log ) {
			$data_to_export[] = $this->build_log_export_item( $log );
		}

		return array(
			'data' => $data_to_export,
			'done' => count( $logs ) < self::PAGE_SIZE && count( $jobs ) < self::PAGE_SIZE,
		);
	}

	public function erase_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		Telepilot_Actor_Access::revoke( $user->ID );
		$items_removed = (bool) Telepilot_Jobs_Repository::erase_actor( $user->ID );
		$items_removed = (bool) Telepilot_Security_Records::erase_actor( $user->ID ) || $items_removed;
		$flows = new Telepilot_Flow_State_Service();
		$telegram_id = get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true );
		$flows->clear_pending_post( $telegram_id );
		$flows->clear_pending_page( $telegram_id );
		do_action( 'telepilot_erase_actor_data', $user->ID );
		$items_retained   = false;
		$messages         = array();
		$telegram_user_id = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true );
		$chat_id          = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true );
		$meta_keys        = array(
			Telepilot_User_Linking_Service::META_LINK_CODE,
			Telepilot_User_Linking_Service::META_LINK_CODE_HASH,
			Telepilot_User_Linking_Service::META_LINK_EXPIRES,
			Telepilot_User_Linking_Service::META_TELEGRAM_ID,
			Telepilot_User_Linking_Service::META_TELEGRAM_CHAT,
			Telepilot_User_Linking_Service::META_TELEGRAM_NAME,
			Telepilot_User_Linking_Service::META_LINKED_AT,
			'_telepilot_last_command_at',
		);

		foreach ( $meta_keys as $meta_key ) {
			$deleted = delete_user_meta( $user->ID, $meta_key );

			if ( $deleted ) {
				$items_removed = true;
			}
		}

		$anonymized_logs = Telepilot_Audit_Log_Repository::anonymize_personal_logs( $user->ID, $telegram_user_id, $chat_id );

		if ( $anonymized_logs > 0 ) {
			$items_removed  = true;
			$items_retained = true;
			$messages[]     = __( 'WP Telepilot kept high-level audit events but removed Telegram identifiers, IP addresses, and detailed payload data from matching log entries.', 'wp-telepilot' );
		}

		if ( get_user_meta( $user->ID, '_telepilot_disabled', true ) ) {
			$items_retained = true;
			$messages[]     = __( 'WP Telepilot retained the disabled-account flag because it is a site access-control setting rather than Telegram linkage metadata.', 'wp-telepilot' );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	private function build_linkage_export_item( WP_User $user ) {
		$telegram_user_id = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_ID, true );
		$chat_id          = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_CHAT, true );
		$username         = (string) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_TELEGRAM_NAME, true );
		$linked_at        = (int) get_user_meta( $user->ID, Telepilot_User_Linking_Service::META_LINKED_AT, true );
		$last_command_at  = (int) get_user_meta( $user->ID, '_telepilot_last_command_at', true );

		if ( '' === $telegram_user_id && '' === $chat_id && '' === $username && empty( $linked_at ) && empty( $last_command_at ) ) {
			return array(
				'group_id'    => 'wp-telepilot-linkage',
				'group_label' => __( 'WP Telepilot Telegram linkage', 'wp-telepilot' ),
				'item_id'     => 'wp-telepilot-linkage-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Link status', 'wp-telepilot' ),
						'value' => __( 'No Telegram account is currently linked.', 'wp-telepilot' ),
					),
				),
			);
		}

		return array(
			'group_id'    => 'wp-telepilot-linkage',
			'group_label' => __( 'WP Telepilot Telegram linkage', 'wp-telepilot' ),
			'item_id'     => 'wp-telepilot-linkage-' . $user->ID,
			'data'        => array(
				array(
					'name'  => __( 'WordPress user ID', 'wp-telepilot' ),
					'value' => (string) $user->ID,
				),
				array(
					'name'  => __( 'Telegram user ID', 'wp-telepilot' ),
					'value' => '' !== $telegram_user_id ? $telegram_user_id : __( 'Not linked', 'wp-telepilot' ),
				),
				array(
					'name'  => __( 'Telegram chat ID', 'wp-telepilot' ),
					'value' => '' !== $chat_id ? $chat_id : __( 'Not linked', 'wp-telepilot' ),
				),
				array(
					'name'  => __( 'Telegram username', 'wp-telepilot' ),
					'value' => '' !== $username ? '@' . ltrim( $username, '@' ) : __( 'Not linked', 'wp-telepilot' ),
				),
				array(
					'name'  => __( 'Linked at', 'wp-telepilot' ),
					'value' => $linked_at ? wp_date( 'Y-m-d H:i:s', $linked_at ) : __( 'Not linked', 'wp-telepilot' ),
				),
				array(
					'name'  => __( 'Last Telegram command seen at', 'wp-telepilot' ),
					'value' => $last_command_at ? wp_date( 'Y-m-d H:i:s', $last_command_at ) : __( 'No command recorded', 'wp-telepilot' ),
				),
			),
		);
	}

	private function build_log_export_item( $log ) {
		$data = array(
			array(
				'name'  => __( 'Date', 'wp-telepilot' ),
				'value' => ! empty( $log['created_at'] ) ? (string) $log['created_at'] : __( 'Unknown', 'wp-telepilot' ),
			),
			array(
				'name'  => __( 'Action', 'wp-telepilot' ),
				'value' => ! empty( $log['action_name'] ) ? (string) $log['action_name'] : __( 'Unknown', 'wp-telepilot' ),
			),
			array(
				'name'  => __( 'Resource type', 'wp-telepilot' ),
				'value' => ! empty( $log['resource_type'] ) ? (string) $log['resource_type'] : __( 'Not recorded', 'wp-telepilot' ),
			),
			array(
				'name'  => __( 'Resource ID', 'wp-telepilot' ),
				'value' => ! empty( $log['resource_id'] ) ? (string) $log['resource_id'] : __( 'Not recorded', 'wp-telepilot' ),
			),
			array(
				'name'  => __( 'Successful', 'wp-telepilot' ),
				'value' => ! empty( $log['was_successful'] ) ? __( 'Yes', 'wp-telepilot' ) : __( 'No', 'wp-telepilot' ),
			),
		);

		$command = $this->extract_command_from_log( $log );

		if ( '' !== $command ) {
			$data[] = array(
				'name'  => __( 'Command', 'wp-telepilot' ),
				'value' => $command,
			);
		}

		if ( ! empty( $log['telegram_user_id'] ) ) {
			$data[] = array(
				'name'  => __( 'Telegram user ID', 'wp-telepilot' ),
				'value' => (string) $log['telegram_user_id'],
			);
		}

		if ( ! empty( $log['chat_id'] ) ) {
			$data[] = array(
				'name'  => __( 'Telegram chat ID', 'wp-telepilot' ),
				'value' => (string) $log['chat_id'],
			);
		}

		if ( ! empty( $log['ip_address'] ) ) {
			$data[] = array(
				'name'  => __( 'IP address', 'wp-telepilot' ),
				'value' => (string) $log['ip_address'],
			);
		}

		if ( ! empty( $log['failure_reason'] ) ) {
			$data[] = array(
				'name'  => __( 'Failure reason', 'wp-telepilot' ),
				'value' => (string) $log['failure_reason'],
			);
		}

		return array(
			'group_id'    => 'wp-telepilot-audit-log',
			'group_label' => __( 'WP Telepilot audit log', 'wp-telepilot' ),
			'item_id'     => 'wp-telepilot-audit-log-' . (int) $log['id'],
			'data'        => $data,
		);
	}

	private function extract_command_from_log( $log ) {
		if ( ! empty( $log['resource_id'] ) && 0 === strpos( (string) $log['resource_id'], '/' ) ) {
			return (string) $log['resource_id'];
		}

		foreach ( array( 'context', 'after_state', 'before_state' ) as $field ) {
			if ( empty( $log[ $field ] ) ) {
				continue;
			}

			$data = json_decode( (string) $log[ $field ], true );

			if ( is_array( $data ) && ! empty( $data['command'] ) ) {
				return sanitize_text_field( (string) $data['command'] );
			}
		}

		return '';
	}
}
