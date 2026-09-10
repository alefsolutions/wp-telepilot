<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Confirmation_Service {
	const TOKEN_PREFIX = 'telepilot_confirm_';

	public function create_token( $payload, $expiration = 600 ) {
		$payload = Telepilot_Actor_Access::bind_payload( $payload );
		return Telepilot_Security_Records::issue( 'confirmation', $payload, get_current_user_id(), max( 60, absint( $expiration ) ) );
	}

	public function consume_token( $token ) {
		$token = $this->normalize_token( $token );
		$payload = Telepilot_Security_Records::read( $token, 'confirmation' );

		if ( ! is_array( $payload ) || ! Telepilot_Actor_Access::valid_payload( $payload ) ) {
			return null;
		}

		if ( ! Telepilot_Security_Records::claim( $token, 'confirmation' ) ) { return null; }

		return $payload;
	}

	private function normalize_token( $token ) {
		return sanitize_key( (string) $token );
	}
}
