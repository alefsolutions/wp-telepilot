<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Confirmation_Manager {
	const ACTION_PREFIX = 'telepilot_ai_action_';
	const SESSION_PREFIX = 'telepilot_ai_actions_';

	public function create_pending_action( $session, $identity, $tool_name, $ability, $arguments, $summary, $risk_level = 'low_write' ) {
		$action_id = sanitize_key( wp_generate_password( 16, false, false ) );
		$payload   = array(
			'action_id'         => $action_id,
			'session_id'        => ! empty( $session['session_id'] ) ? (string) $session['session_id'] : '',
			'telegram_user_id'  => ! empty( $identity['telegram_user_id'] ) ? (string) $identity['telegram_user_id'] : '',
			'chat_id'           => ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : '',
			'wordpress_user_id' => ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ? (int) $identity['wp_user']->ID : 0,
			'site_id'           => (int) get_current_blog_id(),
			'tool_name'         => (string) $tool_name,
			'ability'           => (string) $ability,
			'arguments'         => is_array( $arguments ) ? $arguments : array(),
			'summary'           => trim( (string) $summary ),
			'risk_level'        => sanitize_key( (string) $risk_level ),
			'status'            => 'pending',
			'created_at'        => time(),
			'expires_at'        => time() + 300,
		);

		set_transient( self::ACTION_PREFIX . $action_id, $payload, 300 );
		$this->remember_session_action( $payload['session_id'], $action_id );

		return $payload;
	}

	public function get_pending_action( $action_id ) {
		$action_id = sanitize_key( (string) $action_id );
		if ( '' === $action_id ) {
			return null;
		}

		$payload = get_transient( self::ACTION_PREFIX . $action_id );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		if ( ! empty( $payload['expires_at'] ) && time() >= (int) $payload['expires_at'] ) {
			$this->delete_pending_action( $action_id, ! empty( $payload['session_id'] ) ? (string) $payload['session_id'] : '' );
			return null;
		}

		return $payload;
	}

	public function consume_pending_action( $action_id ) {
		$payload = $this->get_pending_action( $action_id );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		$this->delete_pending_action( $action_id, ! empty( $payload['session_id'] ) ? (string) $payload['session_id'] : '' );

		return $payload;
	}

	public function cancel_pending_action( $action_id ) {
		$payload = $this->get_pending_action( $action_id );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		$this->delete_pending_action( $action_id, ! empty( $payload['session_id'] ) ? (string) $payload['session_id'] : '' );

		return $payload;
	}

	public function cancel_session_actions( $session_id ) {
		$session_id = trim( (string) $session_id );
		if ( '' === $session_id ) {
			return;
		}

		$action_ids = get_transient( self::SESSION_PREFIX . md5( $session_id ) );
		if ( empty( $action_ids ) || ! is_array( $action_ids ) ) {
			return;
		}

		foreach ( $action_ids as $action_id ) {
			delete_transient( self::ACTION_PREFIX . sanitize_key( (string) $action_id ) );
		}

		delete_transient( self::SESSION_PREFIX . md5( $session_id ) );
	}

	public function build_confirmation_keyboard( $action_id ) {
		return Telepilot_Telegram_Response_Builder::keyboard(
			array(
				array(
					array(
						'text'          => Telepilot_Telegram_Response_Builder::button_label( 'confirm', __( 'Confirm', 'wp-telepilot' ) ),
						'callback_data' => 'tp:ai:confirm:' . sanitize_key( (string) $action_id ),
					),
					array(
						'text'          => Telepilot_Telegram_Response_Builder::button_label( 'cancel', __( 'Cancel', 'wp-telepilot' ) ),
						'callback_data' => 'tp:ai:cancel:' . sanitize_key( (string) $action_id ),
					),
				),
			)
		);
	}

	private function remember_session_action( $session_id, $action_id ) {
		$session_id = trim( (string) $session_id );
		$action_id  = sanitize_key( (string) $action_id );

		if ( '' === $session_id || '' === $action_id ) {
			return;
		}

		$key        = self::SESSION_PREFIX . md5( $session_id );
		$action_ids = get_transient( $key );

		if ( ! is_array( $action_ids ) ) {
			$action_ids = array();
		}

		$action_ids[] = $action_id;
		$action_ids   = array_values( array_unique( array_filter( $action_ids ) ) );

		set_transient( $key, $action_ids, Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS );
	}

	private function delete_pending_action( $action_id, $session_id ) {
		delete_transient( self::ACTION_PREFIX . sanitize_key( (string) $action_id ) );

		$session_id = trim( (string) $session_id );
		if ( '' === $session_id ) {
			return;
		}

		$key        = self::SESSION_PREFIX . md5( $session_id );
		$action_ids = get_transient( $key );

		if ( ! is_array( $action_ids ) ) {
			return;
		}

		$action_ids = array_values(
			array_filter(
				$action_ids,
				function( $existing_action_id ) use ( $action_id ) {
					return sanitize_key( (string) $existing_action_id ) !== sanitize_key( (string) $action_id );
				}
			)
		);

		if ( empty( $action_ids ) ) {
			delete_transient( $key );
			return;
		}

		set_transient( $key, $action_ids, Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS );
	}
}
