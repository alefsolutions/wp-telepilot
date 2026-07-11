<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Telegram_Service {
	const DIAGNOSTICS_OPTION         = 'telepress_transport_diagnostics';
	const STALE_UPDATE_WINDOW_OPTION = 'stale_update_window';
	const DEFAULT_STALE_WINDOW       = 180;
	private $client;
	private $user_resolver;
	private $permission_service;
	private $rate_limiter;
	private $command_router;
	private $media_service;
	private $users_service;
	private $confirmation_service;

	public function __construct() {
		$this->client             = new TelePress_Telegram_Client();
		$this->user_resolver      = new TelePress_Linked_User_Resolver();
		$this->permission_service = new TelePress_Permission_Service();
		$this->rate_limiter       = new TelePress_Rate_Limiter();
		$this->confirmation_service = new TelePress_Confirmation_Service();
		$this->media_service      = new TelePress_Media_Service( $this->confirmation_service, $this->client );
		$this->users_service      = new TelePress_Users_Service( $this->confirmation_service );
		$this->command_router     = new TelePress_Command_Router(
			new TelePress_User_Linking_Service(),
			$this->permission_service,
			new TelePress_Dashboard_Service(),
			new TelePress_Comments_Service( $this->confirmation_service ),
			new TelePress_Posts_Service( $this->confirmation_service ),
			new TelePress_Pages_Service( $this->confirmation_service ),
			$this->media_service,
			$this->users_service,
			new TelePress_Taxonomies_Service( $this->confirmation_service ),
			$this->confirmation_service
		);
	}

	public function handle_update( $update, $transport = 'webhook' ) {
		$this->record_transport_activity( $transport, $update );

		if ( $this->is_stale_update( $update ) ) {
			$this->record_stale_update( $transport, $update );
			return TelePress_Telegram_Response_Builder::error(
				__( 'Skipped a stale Telegram update.', 'telepress' ),
				array(
					'code' => 'telepress_stale_update',
				)
			);
		}

		$identity = $this->user_resolver->resolve_from_update( $update );
		$chat_id  = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : '';

		if ( '' !== $chat_id && ! $this->is_chat_allowed( $chat_id ) ) {
			$result = TelePress_Telegram_Response_Builder::error(
				sprintf(
					/* translators: %s: Telegram chat ID. */
					__( "This chat is not authorized for the site yet.\n\nYour current chat ID is: %s\n\nAdd it to Allowed Chat IDs in TelePress settings, then send /start again.", 'telepress' ),
					$chat_id
				),
				array(
					'command' => '/chatid',
					'code'    => 'telepress_chat_not_allowed',
				)
			);

			TelePress_Audit_Log_Repository::log(
				array(
					'chat_id'         => $chat_id,
					'action_name'     => 'telegram_rejected_chat',
					'resource_type'   => 'telegram_chat',
					'resource_id'     => $chat_id,
					'was_successful'  => 0,
					'failure_reason'  => 'Chat ID is not in the allowed list.',
					'context'         => array( 'update' => $update ),
				)
			);

			$this->dispatch_response( $identity, $update, $result, $transport );

			return $result;
		}

		$settings          = get_option( 'telepress_settings', array() );
		$limit_per_minute  = isset( $settings['rate_limit_per_minute'] ) ? (int) $settings['rate_limit_per_minute'] : 20;
		$rate_limit_result = $this->rate_limiter->check( $identity, $limit_per_minute );

		if ( true !== $rate_limit_result ) {
			$this->log_command_event( 'telegram_rate_limited', $identity, $update, $rate_limit_result );

			return $rate_limit_result;
		}

		$command = $this->command_router->parse_command( $update );

		if ( ! empty( $command['name'] ) ) {
			$this->log_command_event( 'telegram_command_received', $identity, $update, array( 'command' => $command['name'] ) );
		}

		$result = $this->handle_media_upload( $update, $identity, $command );

		if ( null === $result ) {
			$result = $this->command_router->route( $update, $identity );
		}

		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			update_user_meta( $identity['wp_user']->ID, '_telepress_last_command_at', time() );
		}

		$this->dispatch_response( $identity, $update, $result, $transport );

		if ( ! empty( $result['code'] ) && 'telepress_capability_denied' === $result['code'] ) {
			$this->log_command_event( 'telegram_permission_denied', $identity, $update, $result );
		}

		return $result;
	}

	public function poll_updates() {
		$offset   = (int) get_option( 'telepress_telegram_poll_offset', 0 );
		$response = $this->client->get_updates( $offset, 10, 0 );
		$this->update_diagnostics(
			array(
				'last_poll_at' => time(),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->update_diagnostics(
				array(
					'last_poll_status' => 'failed',
					'last_poll_error'  => $response->get_error_message(),
				)
			);
			TelePress_Audit_Log_Repository::log(
				array(
					'action_name'     => 'telegram_poll_failed',
					'resource_type'   => 'telegram_poll',
					'was_successful'  => 0,
					'failure_reason'  => $response->get_error_message(),
				)
			);
			return $response;
		}

		if ( empty( $response['result'] ) || ! is_array( $response['result'] ) ) {
			$this->update_diagnostics(
				array(
					'last_poll_status' => 'idle',
					'last_poll_count'  => 0,
				)
			);
			return array();
		}

		$last_offset = $offset;

		foreach ( $response['result'] as $update ) {
			if ( isset( $update['update_id'] ) ) {
				$last_offset = max( $last_offset, (int) $update['update_id'] + 1 );
			}

			$this->handle_update( $update, 'polling' );
		}

		update_option( 'telepress_telegram_poll_offset', $last_offset, false );
		$this->update_diagnostics(
			array(
				'last_poll_status' => 'success',
				'last_poll_count'  => count( $response['result'] ),
				'last_poll_error'  => '',
			)
		);

		return $response['result'];
	}

	public function flush_pending_updates( $transport_mode = 'polling' ) {
		$transport_mode = 'webhook' === $transport_mode ? 'webhook' : 'polling';

		if ( 'webhook' === $transport_mode ) {
			$delete_result = $this->client->delete_webhook( true );

			if ( is_wp_error( $delete_result ) ) {
				return $delete_result;
			}

			$settings      = get_option( 'telepress_settings', array() );
			$webhook_url   = rest_url( TelePress_REST_Webhook_Controller::REST_NAMESPACE . TelePress_REST_Webhook_Controller::ROUTE );
			$secret_token  = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
			$webhook_reset = $this->client->set_webhook( $webhook_url, $secret_token );

			if ( is_wp_error( $webhook_reset ) ) {
				return $webhook_reset;
			}

			$this->update_diagnostics(
				array(
					'last_flush_at'     => time(),
					'last_flush_status' => 'success',
					'last_flush_mode'   => 'webhook',
					'last_flush_error'  => '',
				)
			);

			return array( 'flushed' => true, 'mode' => 'webhook' );
		}

		$flushed = 0;
		$offset  = (int) get_option( 'telepress_telegram_poll_offset', 0 );

		while ( true ) {
			$response = $this->client->get_updates( $offset, 100, 0 );

			if ( is_wp_error( $response ) ) {
				$this->update_diagnostics(
					array(
						'last_flush_at'     => time(),
						'last_flush_status' => 'failed',
						'last_flush_mode'   => 'polling',
						'last_flush_error'  => $response->get_error_message(),
					)
				);
				return $response;
			}

			if ( empty( $response['result'] ) || ! is_array( $response['result'] ) ) {
				break;
			}

			foreach ( $response['result'] as $update ) {
				$flushed++;
				if ( isset( $update['update_id'] ) ) {
					$offset = max( $offset, (int) $update['update_id'] + 1 );
				}
			}

			if ( count( $response['result'] ) < 100 ) {
				break;
			}
		}

		update_option( 'telepress_telegram_poll_offset', $offset, false );
		$this->update_diagnostics(
			array(
				'last_flush_at'     => time(),
				'last_flush_status' => 'success',
				'last_flush_mode'   => 'polling',
				'last_flush_error'  => '',
				'last_flush_count'  => $flushed,
			)
		);

		return array(
			'flushed' => true,
			'mode'    => 'polling',
			'count'   => $flushed,
		);
	}

	private function is_chat_allowed( $chat_id ) {
		$settings = get_option( 'telepress_settings', array() );
		$allowed  = isset( $settings['allowed_chat_ids'] ) ? $settings['allowed_chat_ids'] : '';
		$chat_ids = array_filter( array_map( 'trim', explode( "\n", str_replace( ',', "\n", $allowed ) ) ) );

		if ( empty( $chat_ids ) ) {
			return true;
		}

		return in_array( (string) $chat_id, $chat_ids, true );
	}

	private function dispatch_response( $identity, $update, $result, $transport ) {
		$chat_id = ! empty( $identity['chat_id'] ) ? $identity['chat_id'] : null;

		if ( empty( $chat_id ) || empty( $result['message'] ) ) {
			return;
		}

		if ( ! empty( $update['callback_query']['id'] ) ) {
			$this->client->answer_callback_query( (string) $update['callback_query']['id'] );
		}

		$response = $this->client->send_message(
			$chat_id,
			$result['message'],
			array(
				'reply_markup' => ! empty( $result['reply_markup'] ) ? $result['reply_markup'] : array(),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->update_diagnostics(
				array(
					'last_send_error_at'      => time(),
					'last_send_error_message' => $response->get_error_message(),
					'last_delivery_transport' => $transport,
				)
			);
			TelePress_Audit_Log_Repository::log(
				array(
					'wp_user_id'       => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user']->ID : null,
					'telegram_user_id' => ! empty( $identity['telegram_user_id'] ) ? $identity['telegram_user_id'] : null,
					'chat_id'          => $chat_id,
					'action_name'      => 'telegram_response_failed',
					'resource_type'    => 'telegram_message',
					'was_successful'   => 0,
					'failure_reason'   => $response->get_error_message(),
				)
			);
			return;
		}

		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user']->ID : null,
				'telegram_user_id' => ! empty( $identity['telegram_user_id'] ) ? $identity['telegram_user_id'] : null,
				'chat_id'          => $chat_id,
				'action_name'      => 'telegram_response_sent',
				'resource_type'    => 'telegram_message',
				'resource_id'      => ! empty( $result['command'] ) ? $result['command'] : null,
				'after_state'      => array(
					'result' => isset( $response['result'] ) ? $response['result'] : null,
				),
			)
		);
		$this->update_diagnostics(
			array(
				'last_send_success_at'    => time(),
				'last_send_error_message' => '',
				'last_delivery_transport' => $transport,
			)
		);
	}

	private function log_command_event( $action_name, $identity, $update, $context = array() ) {
		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? $identity['wp_user']->ID : null,
				'telegram_user_id' => ! empty( $identity['telegram_user_id'] ) ? $identity['telegram_user_id'] : null,
				'chat_id'          => ! empty( $identity['chat_id'] ) ? $identity['chat_id'] : null,
				'action_name'      => $action_name,
				'resource_type'    => 'telegram_update',
				'resource_id'      => isset( $update['update_id'] ) ? (string) $update['update_id'] : null,
				'context'          => $context,
			)
		);
	}

	private function handle_media_upload( $update, $identity, $command ) {
		if ( ! empty( $command['name'] ) ) {
			return null;
		}

		if ( empty( $update['message']['photo'] ) && empty( $update['message']['document'] ) ) {
			return null;
		}

		$permission_result = $this->permission_service->require_capability( $identity, 'upload_files' );
		if ( true !== $permission_result ) {
			return $permission_result;
		}

		$result = $this->media_service->import_from_update( $update );
		if ( is_wp_error( $result ) ) {
			return TelePress_Telegram_Response_Builder::error( $result->get_error_message() );
		}

		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $identity['wp_user']->ID,
				'telegram_user_id' => $identity['telegram_user_id'],
				'chat_id'          => $identity['chat_id'],
				'action_name'      => 'media_uploaded',
				'resource_type'    => 'attachment',
				'resource_id'      => (string) $result['attachment_id'],
				'after_state'      => $result,
			)
		);

		return TelePress_Telegram_Response_Builder::success(
			sprintf(
				__( "Media uploaded\nID: %1$d\nTitle: %2$s\nURL: %3$s", 'telepress' ),
				$result['attachment_id'],
				$result['title'],
				$result['url']
			),
			array(
				'command' => '/media',
				'data'    => $result,
			)
		);
	}

	private function get_stale_update_window() {
		$settings = get_option( 'telepress_settings', array() );
		$window   = isset( $settings[ self::STALE_UPDATE_WINDOW_OPTION ] ) ? (int) $settings[ self::STALE_UPDATE_WINDOW_OPTION ] : self::DEFAULT_STALE_WINDOW;

		return max( 30, $window );
	}

	private function is_stale_update( $update ) {
		$timestamp = $this->extract_update_timestamp( $update );

		if ( ! $timestamp ) {
			return false;
		}

		return ( current_time( 'timestamp', true ) - $timestamp ) > $this->get_stale_update_window();
	}

	private function extract_update_timestamp( $update ) {
		if ( ! empty( $update['message']['date'] ) ) {
			return (int) $update['message']['date'];
		}

		if ( ! empty( $update['callback_query']['message']['date'] ) ) {
			return (int) $update['callback_query']['message']['date'];
		}

		return 0;
	}

	private function record_stale_update( $transport, $update ) {
		$this->update_diagnostics(
			array(
				'last_stale_update_at' => time(),
				'last_stale_update_id' => isset( $update['update_id'] ) ? (int) $update['update_id'] : 0,
				'last_transport'       => $transport,
			),
			array(
				'stale_updates_dropped' => 1,
			)
		);

		TelePress_Audit_Log_Repository::log(
			array(
				'action_name'     => 'telegram_update_stale',
				'resource_type'   => 'telegram_update',
				'resource_id'     => isset( $update['update_id'] ) ? (string) $update['update_id'] : null,
				'was_successful'  => 0,
				'failure_reason'  => 'Skipped stale Telegram update.',
				'context'         => array(
					'transport' => $transport,
					'date'      => $this->extract_update_timestamp( $update ),
				),
			)
		);
	}

	private function record_transport_activity( $transport, $update ) {
		$data = array(
			'last_transport'      => $transport,
			'last_processed_at'   => time(),
			'last_update_id'      => isset( $update['update_id'] ) ? (int) $update['update_id'] : 0,
			'last_update_message' => isset( $update['message']['text'] ) ? sanitize_text_field( $update['message']['text'] ) : ( isset( $update['callback_query']['data'] ) ? sanitize_text_field( $update['callback_query']['data'] ) : '' ),
		);

		if ( 'webhook' === $transport ) {
			$data['last_webhook_received_at'] = time();
		}

		$this->update_diagnostics( $data );
	}

	private function update_diagnostics( $data, $increments = array() ) {
		$diagnostics = get_option( self::DIAGNOSTICS_OPTION, array() );

		foreach ( $increments as $key => $amount ) {
			$diagnostics[ $key ] = isset( $diagnostics[ $key ] ) ? (int) $diagnostics[ $key ] + (int) $amount : (int) $amount;
		}

		foreach ( $data as $key => $value ) {
			$diagnostics[ $key ] = $value;
		}

		update_option( self::DIAGNOSTICS_OPTION, $diagnostics, false );
	}
}
