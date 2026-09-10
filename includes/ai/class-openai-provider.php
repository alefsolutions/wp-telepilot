<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Telepilot_OpenAI_Provider implements Telepilot_AI_Provider_Interface {
	const API_BASE = 'https://api.openai.com/v1';
	public function get_name() { return 'openai'; }
	public function is_configured() { return Telepilot_AI_Settings::has_api_key(); }

	public static function usage() {
		return array( 'date' => gmdate( 'Y-m-d' ), 'requests' => (int) get_option( 'telepilot_ai_requests_' . gmdate( 'Ymd' ), 0 ), 'tokens' => (int) get_option( 'telepilot_ai_tokens_' . gmdate( 'Ymd' ), 0 ) );
	}

	private function increment( $kind, $amount, $limit = PHP_INT_MAX ) {
		global $wpdb;
		$key = 'telepilot_ai_' . $kind . '_' . gmdate( 'Ymd' );
		add_option( $key, 0, '', false );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->options SET option_value=CAST(option_value AS UNSIGNED)+%d WHERE option_name=%s AND CAST(option_value AS UNSIGNED)<%d", $amount, $key, $limit ) );
		wp_cache_delete( $key, 'options' );
		return 1 === $changed;
	}

	public function test_connection() {
		$response = $this->chat( array( array( 'role' => 'user', 'content' => 'Reply OK only.' ) ), array(), array( 'test' => true ) );
		$status = array( 'ok' => ! is_wp_error( $response ), 'message' => is_wp_error( $response ) ? $response->get_error_message() : 'Saved API key and selected model responded successfully.', 'checked_at' => time(), 'model' => Telepilot_AI_Settings::get_model() );
		Telepilot_AI_Settings::store_provider_status( $status );
		return is_wp_error( $response ) ? $response : $status;
	}

	public function chat( $messages, $tools = array(), $options = array() ) {
		if ( ! $this->is_configured() ) { return new WP_Error( 'telepilot_ai_missing_api_key', 'Configure or replace the API key using authenticated encryption first.' ); }
		if ( ! $this->increment( 'requests', 1, (int) Telepilot_AI_Settings::get_settings()['ai_daily_request_limit'] ) ) { return new WP_Error( 'telepilot_ai_quota', 'The site daily AI request limit has been reached.' ); }
		$payload = array( 'model' => Telepilot_AI_Settings::get_model(), 'messages' => Telepilot_AI_Data_Policy::clean( array_values( $messages ) ), 'max_completion_tokens' => empty( $options['test'] ) ? 1200 : 16, 'store' => false );
		if ( $tools ) { $payload['tools'] = array_values( $tools ); $payload['tool_choice'] = 'auto'; $payload['parallel_tool_calls'] = false; }
		if ( strlen( wp_json_encode( $payload ) ) > 120000 ) { return new WP_Error( 'telepilot_ai_input_limit', 'This conversation is too large. Start /newchat and narrow your request.' ); }
		$response = wp_remote_post( self::API_BASE . '/chat/completions', array(
			'timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536,
			'headers' => array( 'Authorization' => 'Bearer ' . Telepilot_AI_Settings::get_api_key(), 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) ) { return $this->failure( 'network', true ); }
		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) { return $this->failure( (string) $status, 429 === $status || $status >= 500 ); }
		if ( empty( $body['choices'][0]['message'] ) || ! is_array( $body['choices'][0]['message'] ) ) { return $this->failure( 'invalid_response', false ); }
		$this->increment( 'tokens', max( 0, (int) ( $body['usage']['total_tokens'] ?? 0 ) ) );
		Telepilot_AI_Settings::store_provider_status( array( 'ok' => 1, 'message' => 'Provider request completed.', 'checked_at' => time(), 'model' => Telepilot_AI_Settings::get_model() ) );
		return $body['choices'][0]['message'];
	}

	private function failure( $code, $retryable ) {
		$message = 'OpenAI request failed (' . $code . '). Check saved configuration, quota, and provider diagnostics.';
		Telepilot_AI_Settings::store_provider_status( array( 'ok' => 0, 'message' => $message, 'checked_at' => time(), 'model' => Telepilot_AI_Settings::get_model() ) );
		return new WP_Error( 'telepilot_ai_provider_error', $message, array( 'retryable' => $retryable ) );
	}
}
