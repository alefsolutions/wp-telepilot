<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Confirmation_Service {
	const TOKEN_PREFIX = 'telepress_confirm_';

	public function create_token( $payload, $expiration = 600 ) {
		$token = $this->normalize_token( wp_generate_password( 20, false, false ) );

		set_transient(
			self::TOKEN_PREFIX . $token,
			$payload,
			max( 60, absint( $expiration ) )
		);

		return $token;
	}

	public function consume_token( $token ) {
		$key     = self::TOKEN_PREFIX . $this->normalize_token( $token );
		$payload = get_transient( $key );

		if ( false === $payload ) {
			return null;
		}

		delete_transient( $key );

		return $payload;
	}

	private function normalize_token( $token ) {
		return sanitize_key( (string) $token );
	}
}
