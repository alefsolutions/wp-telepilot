<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Settings {
	const PROVIDER_STATUS_OPTION = 'telepilot_ai_provider_status';

	public static function defaults() {
		return array(
			'ai_enabled'                    => 0,
			'ai_provider'                   => 'openai',
			'ai_model'                      => 'gpt-4.1-mini',
			'ai_timeout_minutes'            => 60,
			'ai_preserve_context'           => 1,
			'ai_confirm_writes'             => 1,
			'ai_log_activity'               => 1,
			'ai_mask_sensitive'             => 1,
			'ai_max_tool_calls'             => 5,
			'ai_max_retries'                => 2,
			'ai_max_pending_confirmations'  => 3,
			'ai_openai_api_key_encrypted'   => '',
			'ai_allow_site_read'            => 1,
			'ai_allow_content_read'         => 1,
			'ai_allow_plugins_read'         => 1,
			'ai_allow_users_read'           => 1,
			'ai_allow_comments_read'        => 1,
			'ai_allow_create_drafts'        => 1,
			'ai_allow_edit_drafts'          => 1,
			'ai_allow_publish_posts'        => 0,
			'ai_allow_manage_comments'      => 0,
			'ai_allow_activate_plugins'     => 0,
			'ai_allow_update_plugins'       => 0,
			'ai_allowed_roles' => array( 'administrator' ),
			'ai_include_email_fields' => 0,
			'ai_daily_request_limit' => 100,
		);
	}

	public static function merge_defaults( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::defaults() );
	}

	public static function get_settings( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'telepilot_settings', array() );
		}

		return self::merge_defaults( $settings );
	}

	public static function sanitize_into_settings( $input, $existing ) {
		$existing = self::get_settings( $existing );
		$output   = array();

		$output['ai_enabled']                   = ! empty( $input['ai_enabled'] ) ? 1 : 0;
		$output['ai_provider']                  = 'openai';
		$output['ai_model']                     = isset( $input['ai_model'] ) ? sanitize_text_field( (string) $input['ai_model'] ) : (string) $existing['ai_model'];
		$output['ai_timeout_minutes']           = isset( $input['ai_timeout_minutes'] ) ? max( 5, absint( $input['ai_timeout_minutes'] ) ) : (int) $existing['ai_timeout_minutes'];
		$output['ai_preserve_context']          = ! empty( $input['ai_preserve_context'] ) ? 1 : 0;
		$output['ai_confirm_writes']            = 1;
		$output['ai_log_activity']              = ! empty( $input['ai_log_activity'] ) ? 1 : 0;
		$output['ai_mask_sensitive']            = 1;
		$output['ai_max_tool_calls']            = isset( $input['ai_max_tool_calls'] ) ? max( 1, min( 5, absint( $input['ai_max_tool_calls'] ) ) ) : (int) $existing['ai_max_tool_calls'];
		$output['ai_max_retries']               = isset( $input['ai_max_retries'] ) ? max( 0, min( 2, absint( $input['ai_max_retries'] ) ) ) : (int) $existing['ai_max_retries'];
		$output['ai_max_pending_confirmations'] = isset( $input['ai_max_pending_confirmations'] ) ? max( 1, min( 3, absint( $input['ai_max_pending_confirmations'] ) ) ) : (int) $existing['ai_max_pending_confirmations'];

		foreach ( self::permission_fields() as $field ) {
			$output[ $field ] = ! empty( $input[ $field ] ) ? 1 : 0;
		}

		$api_key = isset( $input['ai_openai_api_key'] ) ? trim( (string) $input['ai_openai_api_key'] ) : '';
		if ( '' === $api_key ) {
			$output['ai_openai_api_key_encrypted'] = (string) $existing['ai_openai_api_key_encrypted'];
		} else {
			$output['ai_openai_api_key_encrypted'] = self::encrypt_secret( $api_key );
		}

		if ( ! empty( $input['ai_remove_api_key'] ) ) { $output['ai_openai_api_key_encrypted'] = ''; }
		$output['ai_allowed_roles'] = array_values( array_intersect( array_keys( wp_roles()->roles ), array_map( 'sanitize_key', (array) ( $input['ai_allowed_roles'] ?? array( 'administrator' ) ) ) ) );
		$output['ai_include_email_fields'] = empty( $input['ai_include_email_fields'] ) ? 0 : 1;
		$output['ai_daily_request_limit'] = max( 1, min( 10000, absint( $input['ai_daily_request_limit'] ?? 100 ) ) );
		return $output;
	}

	public static function permission_fields() {
		return array(
			'ai_allow_site_read',
			'ai_allow_content_read',
			'ai_allow_plugins_read',
			'ai_allow_users_read',
			'ai_allow_comments_read',
			'ai_allow_create_drafts',
			'ai_allow_edit_drafts',
			'ai_allow_publish_posts',
			'ai_allow_manage_comments',
			'ai_allow_activate_plugins',
			'ai_allow_update_plugins',
		);
	}

	public static function is_enabled( $settings = null ) {
		$settings = self::get_settings( $settings );

		return ! empty( $settings['ai_enabled'] );
	}

	public static function get_provider( $settings = null ) {
		$settings = self::get_settings( $settings );

		return ! empty( $settings['ai_provider'] ) ? (string) $settings['ai_provider'] : 'openai';
	}

	public static function get_model( $settings = null ) {
		$settings = self::get_settings( $settings );

		return ! empty( $settings['ai_model'] ) ? (string) $settings['ai_model'] : 'gpt-4.1-mini';
	}

	public static function get_timeout_minutes( $settings = null ) {
		$settings = self::get_settings( $settings );

		return max( 5, (int) $settings['ai_timeout_minutes'] );
	}

	public static function get_api_key( $settings = null ) {
		$settings = self::get_settings( $settings );
		$secret   = ! empty( $settings['ai_openai_api_key_encrypted'] ) ? (string) $settings['ai_openai_api_key_encrypted'] : '';

		if ( '' === $secret ) {
			return '';
		}

		return self::decrypt_secret( $secret );
	}

	public static function has_api_key( $settings = null ) {
		return '' !== self::get_api_key( $settings );
	}

	public static function masked_api_key( $settings = null ) {
		$key = self::get_api_key( $settings );

		if ( '' === $key ) {
			return '';
		}

		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}

		return substr( $key, 0, 4 ) . str_repeat( '*', max( 4, strlen( $key ) - 8 ) ) . substr( $key, -4 );
	}

	public static function store_provider_status( $status ) {
		update_option(
			self::PROVIDER_STATUS_OPTION,
			wp_parse_args(
				is_array( $status ) ? $status : array(),
				array(
					'ok'         => 0,
					'message'    => '',
					'checked_at' => time(),
					'provider'   => 'openai',
					'model'      => '',
				)
			),
			false
		);
	}

	public static function get_provider_status() {
		return wp_parse_args(
			get_option( self::PROVIDER_STATUS_OPTION, array() ),
			array(
				'ok'         => 0,
				'message'    => __( 'Not checked yet.', 'wp-telepilot' ),
				'checked_at' => 0,
				'provider'   => 'openai',
				'model'      => '',
			)
		);
	}

	private static function encrypt_secret( $secret ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			add_settings_error( 'telepilot_settings', 'telepilot_encryption_missing', 'Authenticated encryption is unavailable. No API key was stored.' );
			return '';
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( trim( (string) $secret ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag, 'wp-telepilot-openai-v1', 16 );
		return false === $cipher ? '' : 'gcm1:' . base64_encode( $iv . $tag . $cipher );
	}

	private static function decrypt_secret( $payload ) {
		if ( 0 !== strpos( (string) $payload, 'gcm1:' ) || ! function_exists( 'openssl_decrypt' ) ) { return ''; }
		$decoded = base64_decode( substr( $payload, 5 ), true );
		if ( false === $decoded || strlen( $decoded ) <= 28 ) { return ''; }
		$result = openssl_decrypt( substr( $decoded, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $decoded, 0, 12 ), substr( $decoded, 12, 16 ), 'wp-telepilot-openai-v1' );
		return false === $result ? '' : $result;
	}
}
