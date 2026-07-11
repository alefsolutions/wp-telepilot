<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Telegram_Client {
	private $bot_token = '';

	public function __construct( $bot_token = '' ) {
		if ( '' !== $bot_token ) {
			$this->bot_token = (string) $bot_token;
			return;
		}

		$settings        = get_option( 'telepress_settings', array() );
		$this->bot_token = isset( $settings['bot_token'] ) ? (string) $settings['bot_token'] : '';
	}

	public function send_message( $chat_id, $text, $args = array() ) {
		$payload = array(
			'chat_id'                  => (string) $chat_id,
			'text'                     => (string) $text,
			'disable_web_page_preview' => true,
		);

		if ( ! empty( $args['parse_mode'] ) ) {
			$payload['parse_mode'] = (string) $args['parse_mode'];
		}

		if ( ! empty( $args['reply_markup'] ) ) {
			$payload['reply_markup'] = wp_json_encode( $args['reply_markup'] );
		}

		if ( ! empty( $args['reply_to_message_id'] ) ) {
			$payload['reply_to_message_id'] = (int) $args['reply_to_message_id'];
		}

		return $this->request( 'sendMessage', $payload );
	}

	public function answer_callback_query( $callback_query_id, $text = '' ) {
		$payload = array(
			'callback_query_id' => (string) $callback_query_id,
		);

		if ( '' !== $text ) {
			$payload['text'] = (string) $text;
		}

		return $this->request( 'answerCallbackQuery', $payload );
	}

	public function edit_message_text( $chat_id, $message_id, $text, $args = array() ) {
		$payload = array(
			'chat_id'                  => (string) $chat_id,
			'message_id'               => (int) $message_id,
			'text'                     => (string) $text,
			'disable_web_page_preview' => true,
		);

		if ( ! empty( $args['parse_mode'] ) ) {
			$payload['parse_mode'] = (string) $args['parse_mode'];
		}

		if ( ! empty( $args['reply_markup'] ) ) {
			$payload['reply_markup'] = wp_json_encode( $args['reply_markup'] );
		}

		return $this->request( 'editMessageText', $payload );
	}

	public function get_file( $file_id ) {
		return $this->request( 'getFile', array( 'file_id' => (string) $file_id ) );
	}

	public function set_commands( $commands ) {
		$payload = array(
			'commands' => wp_json_encode( array_values( $commands ) ),
		);

		return $this->request( 'setMyCommands', $payload );
	}

	public function set_webhook( $url, $secret_token = '' ) {
		$payload = array(
			'url' => esc_url_raw( (string) $url ),
		);

		if ( '' !== $secret_token ) {
			$payload['secret_token'] = (string) $secret_token;
		}

		return $this->request( 'setWebhook', $payload );
	}

	public function delete_webhook( $drop_pending_updates = false ) {
		return $this->request(
			'deleteWebhook',
			array(
				'drop_pending_updates' => ! empty( $drop_pending_updates ),
			)
		);
	}

	public function get_webhook_info() {
		return $this->request( 'getWebhookInfo' );
	}

	public function get_updates( $offset = 0, $limit = 10, $timeout = 0 ) {
		return $this->request(
			'getUpdates',
			array(
				'offset'  => max( 0, (int) $offset ),
				'limit'   => max( 1, min( 100, (int) $limit ) ),
				'timeout' => max( 0, (int) $timeout ),
			)
		);
	}

	public function build_file_url( $file_path ) {
		if ( '' === $this->bot_token ) {
			return '';
		}

		return sprintf(
			'https://api.telegram.org/file/bot%s/%s',
			rawurlencode( $this->bot_token ),
			ltrim( (string) $file_path, '/' )
		);
	}

	public function request( $method, $payload = array() ) {
		if ( '' === $this->bot_token ) {
			return new WP_Error( 'telepress_missing_bot_token', __( 'TelePress bot token is not configured.', 'telepress' ) );
		}

		$url      = sprintf( 'https://api.telegram.org/bot%s/%s', rawurlencode( $this->bot_token ), rawurlencode( $method ) );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'telepress_telegram_request_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || empty( $body['ok'] ) ) {
			$message = isset( $body['description'] ) ? (string) $body['description'] : __( 'Telegram API request failed.', 'telepress' );

			return new WP_Error(
				'telepress_telegram_api_error',
				$message,
				array(
					'status' => $status,
					'method' => (string) $method,
				)
			);
		}

		return $body;
	}
}
