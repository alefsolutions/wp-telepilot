<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_AI_Session_Manager {
	const SESSION_PREFIX = 'telepilot_ai_session_';

	public function get_session( $identity ) {
		$key = $this->build_session_key( $identity );

		if ( '' === $key ) {
			return null;
		}

		$session = get_transient( $key );
		if ( ! is_array( $session ) ) {
			return null;
		}

		if ( ! empty( $session['expires_at'] ) && time() >= (int) $session['expires_at'] ) {
			delete_transient( $key );
			return array(
				'expired' => true,
			);
		}

		return $session;
	}

	public function is_active( $identity ) {
		$session = $this->get_session( $identity );

		return is_array( $session ) && empty( $session['expired'] ) && ! empty( $session['ai_mode_active'] );
	}

	public function start_session( $identity, $provider, $model ) {
		$key = $this->build_session_key( $identity );

		if ( '' === $key || empty( $identity['wp_user'] ) || ! $identity['wp_user'] instanceof WP_User ) {
			return null;
		}

		$timeout = Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS;
		$session = array(
			'session_id'             => wp_generate_uuid4(),
			'telegram_chat_id'       => ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : '',
			'telegram_user_id'       => ! empty( $identity['telegram_user_id'] ) ? (string) $identity['telegram_user_id'] : '',
			'wordpress_user_id'      => (int) $identity['wp_user']->ID,
			'site_id'                => (int) get_current_blog_id(),
			'ai_mode_active'         => true,
			'provider'               => (string) $provider,
			'model'                  => (string) $model,
			'conversation_reference' => '',
			'started_at'             => time(),
			'last_activity_at'       => time(),
			'expires_at'             => time() + $timeout,
			'pending_action_id'      => '',
		);

		set_transient( $key, $session, $timeout );

		return $session;
	}

	public function refresh_session( $identity, $session = null ) {
		$key = $this->build_session_key( $identity );

		if ( '' === $key ) {
			return null;
		}

		if ( ! is_array( $session ) ) {
			$session = $this->get_session( $identity );
		}

		if ( ! is_array( $session ) || ! empty( $session['expired'] ) ) {
			return null;
		}

		$timeout                     = Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS;
		$session['last_activity_at'] = time();
		$session['expires_at']       = time() + $timeout;

		set_transient( $key, $session, $timeout );

		return $session;
	}

	public function update_session( $identity, $session ) {
		$key = $this->build_session_key( $identity );

		if ( '' === $key || ! is_array( $session ) ) {
			return null;
		}

		$timeout = Telepilot_AI_Settings::get_timeout_minutes() * MINUTE_IN_SECONDS;
		set_transient( $key, $session, $timeout );

		return $session;
	}

	public function end_session( $identity ) {
		$key = $this->build_session_key( $identity );

		if ( '' === $key ) {
			return;
		}

		delete_transient( $key );
	}

	private function build_session_key( $identity ) {
		$chat_id          = ! empty( $identity['chat_id'] ) ? (string) $identity['chat_id'] : '';
		$telegram_user_id = ! empty( $identity['telegram_user_id'] ) ? (string) $identity['telegram_user_id'] : '';

		if ( '' === $chat_id || '' === $telegram_user_id ) {
			return '';
		}

		return self::SESSION_PREFIX . md5( wp_json_encode( array( $chat_id, $telegram_user_id, (int) get_current_blog_id() ) ) );
	}
}
