<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Flow_State_Service {
	const FLOW_PREFIX         = 'telepilot_flow_';
	const PENDING_POST_PREFIX = 'telepilot_pending_post_';

	public function create_flow( $payload, $expiration = 900, $token = '' ) {
		$token = '' !== (string) $token ? $this->normalize_token( $token ) : $this->generate_token();

		if ( '' === $token ) {
			return '';
		}

		set_transient(
			self::FLOW_PREFIX . $token,
			(array) $payload,
			max( 60, absint( $expiration ) )
		);

		return $token;
	}

	public function get_flow( $token ) {
		$token = $this->normalize_token( $token );
		if ( '' === $token ) {
			return null;
		}

		$payload = get_transient( self::FLOW_PREFIX . $token );

		return is_array( $payload ) ? $payload : null;
	}

	public function update_flow( $token, $payload, $expiration = 900 ) {
		$token = $this->normalize_token( $token );
		if ( '' === $token ) {
			return false;
		}

		set_transient(
			self::FLOW_PREFIX . $token,
			(array) $payload,
			max( 60, absint( $expiration ) )
		);

		return true;
	}

	public function delete_flow( $token ) {
		$token = $this->normalize_token( $token );
		if ( '' === $token ) {
			return false;
		}

		return delete_transient( self::FLOW_PREFIX . $token );
	}

	public function start_pending_post( $telegram_user_id, $payload, $expiration = 900 ) {
		$user_key = $this->normalize_user_key( $telegram_user_id );
		if ( '' === $user_key ) {
			return false;
		}

		set_transient(
			self::PENDING_POST_PREFIX . $user_key,
			(array) $payload,
			max( 60, absint( $expiration ) )
		);

		return true;
	}

	public function get_pending_post( $telegram_user_id ) {
		$user_key = $this->normalize_user_key( $telegram_user_id );
		if ( '' === $user_key ) {
			return null;
		}

		$payload = get_transient( self::PENDING_POST_PREFIX . $user_key );

		return is_array( $payload ) ? $payload : null;
	}

	public function clear_pending_post( $telegram_user_id ) {
		$user_key = $this->normalize_user_key( $telegram_user_id );
		if ( '' === $user_key ) {
			return false;
		}

		return delete_transient( self::PENDING_POST_PREFIX . $user_key );
	}

	private function generate_token() {
		return $this->normalize_token( wp_generate_password( 20, false, false ) );
	}

	private function normalize_token( $token ) {
		return sanitize_key( (string) $token );
	}

	private function normalize_user_key( $telegram_user_id ) {
		return preg_replace( '/[^0-9]/', '', (string) $telegram_user_id );
	}
}
