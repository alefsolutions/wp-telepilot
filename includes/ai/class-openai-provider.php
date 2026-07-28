<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_OpenAI_Provider implements Telepilot_AI_Provider_Interface {
	const API_BASE = 'https://api.openai.com/v1';

	public function get_name() {
		return 'openai';
	}

	public function is_configured() {
		return '' !== Telepilot_AI_Settings::get_api_key();
	}

	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'telepilot_ai_missing_api_key', __( 'OpenAI API key is not configured.', 'wp-telepilot' ) );
		}

		$response = wp_remote_get(
			self::API_BASE . '/models',
			array(
				'timeout' => 15,
				'headers' => $this->build_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = ! empty( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'The AI provider rejected the request.', 'wp-telepilot' );

			return new WP_Error( 'telepilot_ai_provider_error', $message );
		}

		return array(
			'ok'      => true,
			'message' => __( 'OpenAI connection verified successfully.', 'wp-telepilot' ),
		);
	}

	public function chat( $messages, $tools = array(), $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'telepilot_ai_missing_api_key', __( 'OpenAI API key is not configured.', 'wp-telepilot' ) );
		}

		$model   = ! empty( $options['model'] ) ? (string) $options['model'] : Telepilot_AI_Settings::get_model();
		$payload = array(
			'model'    => $model,
			'messages' => array_values( $messages ),
		);

		if ( ! empty( $tools ) ) {
			$payload['tools']       = array_values( $tools );
			$payload['tool_choice'] = 'auto';
		}

		$response = wp_remote_post(
			self::API_BASE . '/chat/completions',
			array(
				'timeout' => 45,
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || empty( $body['choices'][0]['message'] ) ) {
			$message = ! empty( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'The AI provider returned an invalid response.', 'wp-telepilot' );

			return new WP_Error( 'telepilot_ai_provider_error', $message );
		}

		return $body['choices'][0]['message'];
	}

	private function build_headers() {
		return array(
			'Authorization' => 'Bearer ' . Telepilot_AI_Settings::get_api_key(),
			'Content-Type'  => 'application/json',
		);
	}
}
