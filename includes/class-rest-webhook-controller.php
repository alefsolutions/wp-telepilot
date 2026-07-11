<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_REST_Webhook_Controller {
	const REST_NAMESPACE = 'telepress/v1';
	const ROUTE          = '/webhook';

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
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$settings = get_option( 'telepress_settings', array() );
		$secret   = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
		$header   = $this->get_webhook_secret_header( $request );

		if ( '' !== $secret && ! hash_equals( $secret, $header ) ) {
			$this->update_diagnostics(
				array(
					'last_webhook_auth_at'     => time(),
					'last_webhook_auth_status' => 'failed',
					'last_webhook_auth_error'  => 'Webhook secret mismatch.',
				)
			);
			TelePress_Audit_Log_Repository::log(
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
					'message' => __( 'Invalid webhook secret.', 'telepress' ),
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
		$telegram = new TelePress_Telegram_Service();
		$result   = $telegram->handle_update( is_array( $payload ) ? $payload : array(), 'webhook' );

		TelePress_Audit_Log_Repository::log(
			array(
				'telegram_user_id' => isset( $payload['message']['from']['id'] ) ? (string) $payload['message']['from']['id'] : null,
				'chat_id'          => isset( $payload['message']['chat']['id'] ) ? (string) $payload['message']['chat']['id'] : null,
				'action_name'      => 'webhook_received',
				'resource_type'    => 'telegram_update',
				'after_state'      => $result,
				'context'          => array( 'update_id' => isset( $payload['update_id'] ) ? $payload['update_id'] : null ),
				'was_successful'   => ! empty( $result['ok'] ),
				'failure_reason'   => empty( $result['ok'] ) ? $result['message'] : null,
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	private function get_webhook_secret_header( WP_REST_Request $request ) {
		$telegram_header = (string) $request->get_header( 'x-telegram-bot-api-secret-token' );

		if ( '' !== $telegram_header ) {
			return $telegram_header;
		}

		return (string) $request->get_header( 'x-telepress-secret' );
	}

	private function update_diagnostics( $data ) {
		$diagnostics = get_option( TelePress_Telegram_Service::DIAGNOSTICS_OPTION, array() );
		$diagnostics = array_merge( $diagnostics, $data );
		update_option( TelePress_Telegram_Service::DIAGNOSTICS_OPTION, $diagnostics, false );
	}
}
