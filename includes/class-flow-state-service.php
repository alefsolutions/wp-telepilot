<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Flow_State_Service {
	const FLOW_PREFIX         = 'telepilot_flow_';
	const PENDING_POST_PREFIX = 'telepilot_pending_post_';
	const PENDING_PAGE_PREFIX = 'telepilot_pending_page_';

	public function create_flow( $payload, $expiration = 900, $token = '' ) {
		$payload = Telepilot_Actor_Access::bind_payload( (array) $payload );
		if ( '' !== $token ) { return $this->update_flow( $token, $payload, $expiration ) ? $token : ''; }
		return Telepilot_Security_Records::issue( 'flow', $payload, get_current_user_id(), max( 60, absint( $expiration ) ) );
	}

	public function get_flow( $token ) {
		$token = $this->normalize_token( $token );
		if ( '' === $token ) {
			return null;
		}

		$payload = Telepilot_Security_Records::read( $token, 'flow' );

		return is_array( $payload ) && Telepilot_Actor_Access::valid_payload( $payload ) ? $payload : null;
	}

	public function update_flow( $token, $payload, $expiration = 900 ) {
		if ( ! $this->get_flow( $token ) ) {
			return false;
		}
		$payload = Telepilot_Actor_Access::bind_payload( (array) $payload );
		$token = $this->normalize_token( $token );
		if ( '' === $token ) {
			return false;
		}

		global $wpdb;
		$existing = Telepilot_Security_Records::read( $token, 'flow' );
		$payload['_security_binding'] = $existing['_security_binding'];
		return false !== $wpdb->update( Telepilot_Security_Records::table_name(), array( 'payload' => wp_json_encode( $payload ) ), array( 'token_hash' => hash( 'sha256', $token ), 'purpose' => 'flow', 'state' => 'ready' ) );
	}

	public function delete_flow( $token ) {
		$token = $this->normalize_token( $token );
		if ( '' === $token ) {
			return false;
		}

		return $this->get_flow( $token ) ? Telepilot_Security_Records::claim( $token, 'flow' ) : false;
	}

	public function start_pending_post( $telegram_user_id, $payload, $expiration = 900 ) {
		$payload = Telepilot_Actor_Access::bind_payload( (array) $payload );
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

		return is_array( $payload ) && Telepilot_Actor_Access::valid_payload( $payload ) ? $payload : null;
	}

	public function clear_pending_post( $telegram_user_id ) {
		$user_key = $this->normalize_user_key( $telegram_user_id );
		if ( '' === $user_key ) {
			return false;
		}

		return delete_transient( self::PENDING_POST_PREFIX . $user_key );
	}

	public function start_pending_page( $telegram_user_id, $payload, $expiration = 900 ) {
		$payload = Telepilot_Actor_Access::bind_payload( (array) $payload );
		$user_key = $this->normalize_user_key( $telegram_user_id );
		if ( '' === $user_key ) {
			return false;
		}

		set_transient(
			self::PENDING_PAGE_PREFIX . $user_key,
			(array) $payload,
			max( 60, absint( $expiration ) )
		);

		return true;
	}

	public function get_pending_page( $telegram_user_id ) {
		$user_key = $this->normalize_user_key( $telegram_user_id );
		if ( '' === $user_key ) {
			return null;
		}

		$payload = get_transient( self::PENDING_PAGE_PREFIX . $user_key );

		return is_array( $payload ) && Telepilot_Actor_Access::valid_payload( $payload ) ? $payload : null;
	}

	public function clear_pending_page( $telegram_user_id ) {
		$user_key = $this->normalize_user_key( $telegram_user_id );
		if ( '' === $user_key ) {
			return false;
		}

		return delete_transient( self::PENDING_PAGE_PREFIX . $user_key );
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
