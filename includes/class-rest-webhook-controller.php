<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_REST_Webhook_Controller {
	const REST_NAMESPACE = 'telepilot/v1';
	const ROUTE          = '/webhook';
	const WORKER_ROUTE   = '/process-jobs';
	const WORKER_HEADER  = 'x-telepilot-worker-secret';

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::WORKER_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_process_jobs' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$settings = get_option( 'telepilot_settings', array() );
		$secret   = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
		$mode     = isset( $settings['transport_mode'] ) ? (string) $settings['transport_mode'] : 'webhook';
		$header   = $this->get_webhook_secret_header( $request );

		if ( 'webhook' !== $mode ) {
			$this->update_diagnostics(
				array(
					'last_webhook_ignored_at' => time(),
					'last_webhook_auth_status'=> 'ignored',
					'last_webhook_auth_error' => 'Webhook update received while polling mode is active.',
				)
			);

			return new WP_REST_Response(
				array(
					'ok'      => true,
					'message' => __( 'Webhook ignored because Telepilot is currently using polling mode.', 'wp-telepilot' ),
				),
				202
			);
		}

		if ( '' !== $secret && ! hash_equals( $secret, $header ) ) {
			$this->update_diagnostics(
				array(
					'last_webhook_auth_at'     => time(),
					'last_webhook_auth_status' => 'failed',
					'last_webhook_auth_error'  => 'Webhook secret mismatch.',
				)
			);
			Telepilot_Audit_Log_Repository::log(
				array(
					'action_name'     => 'webhook_auth_failed',
					'resource_type'   => 'webhook',
					'was_successful'  => 0,
					'failure_reason'  => 'Webhook secret mismatch.',
					'context'         => array( 'headers' => $request->get_headers() ),
				)
			);

			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Invalid webhook secret.', 'wp-telepilot' ),
				),
				403
			);
		}

		$this->update_diagnostics(
			array(
				'last_webhook_auth_at'     => time(),
				'last_webhook_auth_status' => 'success',
				'last_webhook_auth_error'  => '',
			)
		);

		$payload  = $request->get_json_params();
		$telegram = new Telepilot_Telegram_Service();
		$result   = $telegram->handle_webhook_update( is_array( $payload ) ? $payload : array() );

		Telepilot_Audit_Log_Repository::log(
			array(
				'telegram_user_id' => $this->extract_update_telegram_user_id( $payload ),
				'chat_id'          => $this->extract_update_chat_id( $payload ),
				'action_name'      => 'webhook_received',
				'resource_type'    => 'telegram_update',
				'after_state'      => $result,
				'context'          => array(
					'update_id' => isset( $payload['update_id'] ) ? $payload['update_id'] : null,
					'command'   => $this->extract_update_command_text( $payload ),
				),
				'was_successful'   => ! empty( $result['ok'] ),
				'failure_reason'   => empty( $result['ok'] ) ? $result['message'] : null,
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	public function handle_process_jobs( WP_REST_Request $request ) {
		$settings = get_option( 'telepilot_settings', array() );
		$secret   = isset( $settings['worker_secret'] ) ? (string) $settings['worker_secret'] : '';
		$header   = (string) $request->get_header( self::WORKER_HEADER );

		if ( '' === $secret || ! hash_equals( $secret, $header ) ) {
			$this->update_diagnostics(
				array(
					'last_worker_auth_at'     => time(),
					'last_worker_auth_status' => 'failed',
					'last_worker_auth_error'  => 'Worker secret mismatch.',
				)
			);

			Telepilot_Audit_Log_Repository::log(
				array(
					'action_name'     => 'worker_auth_failed',
					'resource_type'   => 'worker',
					'was_successful'  => 0,
					'failure_reason'  => 'Worker secret mismatch.',
				)
			);

			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Invalid worker secret.', 'wp-telepilot' ),
				),
				403
			);
		}

		$limit = max( 1, min( 10, (int) $request->get_param( 'limit' ) ) );

		$this->update_diagnostics(
			array(
				'last_worker_auth_at'     => time(),
				'last_worker_auth_status' => 'success',
				'last_worker_auth_error'  => '',
				'last_worker_request_at'  => time(),
			)
		);

		$telegram = new Telepilot_Telegram_Service();
		$jobs     = $telegram->process_jobs( $limit );
		$count    = is_array( $jobs ) ? count( $jobs ) : 0;

		Telepilot_Audit_Log_Repository::log(
			array(
				'action_name'    => 'worker_processed_jobs',
				'resource_type'  => 'worker',
				'after_state'    => array(
					'processed_jobs' => $count,
					'limit'          => $limit,
				),
				'was_successful' => 1,
			)
		);

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'processed_jobs' => $count,
			),
			200
		);
	}

	private function get_webhook_secret_header( WP_REST_Request $request ) {
		$telegram_header = (string) $request->get_header( 'x-telegram-bot-api-secret-token' );

		if ( '' !== $telegram_header ) {
			return $telegram_header;
		}

		return (string) $request->get_header( 'x-telepilot-secret' );
	}

	private function extract_update_command_text( $payload ) {
		if ( ! empty( $payload['message']['text'] ) ) {
			return sanitize_text_field( (string) $payload['message']['text'] );
		}

		if ( ! empty( $payload['callback_query']['data'] ) ) {
			return sanitize_text_field( (string) $payload['callback_query']['data'] );
		}

		return '';
	}

	private function extract_update_telegram_user_id( $payload ) {
		if ( ! empty( $payload['message']['from']['id'] ) ) {
			return (string) $payload['message']['from']['id'];
		}

		if ( ! empty( $payload['callback_query']['from']['id'] ) ) {
			return (string) $payload['callback_query']['from']['id'];
		}

		return null;
	}

	private function extract_update_chat_id( $payload ) {
		if ( ! empty( $payload['message']['chat']['id'] ) ) {
			return (string) $payload['message']['chat']['id'];
		}

		if ( ! empty( $payload['callback_query']['message']['chat']['id'] ) ) {
			return (string) $payload['callback_query']['message']['chat']['id'];
		}

		return null;
	}

	private function update_diagnostics( $data ) {
		$diagnostics = get_option( Telepilot_Telegram_Service::DIAGNOSTICS_OPTION, array() );
		$diagnostics = array_merge( $diagnostics, $data );
		update_option( Telepilot_Telegram_Service::DIAGNOSTICS_OPTION, $diagnostics, false );
	}
}
