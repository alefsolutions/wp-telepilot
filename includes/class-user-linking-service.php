<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_User_Linking_Service {
	const META_LINK_CODE      = '_telepress_link_code';
	const META_LINK_CODE_HASH = '_telepress_link_code_hash';
	const META_LINK_EXPIRES   = '_telepress_link_expires';
	const META_TELEGRAM_ID    = '_telepress_telegram_user_id';
	const META_TELEGRAM_CHAT  = '_telepress_telegram_chat_id';
	const META_TELEGRAM_NAME  = '_telepress_telegram_username';
	const META_LINKED_AT      = '_telepress_linked_at';

	public function generate_link_code( $user_id ) {
		$user_id = absint( $user_id );
		$code    = strtoupper( wp_generate_password( 8, false, false ) );

		update_user_meta( $user_id, self::META_LINK_CODE_HASH, wp_hash_password( $code ) );
		update_user_meta( $user_id, self::META_LINK_EXPIRES, time() + HOUR_IN_SECONDS );
		set_transient( $this->get_display_transient_key( $user_id ), $code, HOUR_IN_SECONDS );

		return $code;
	}

	public function get_active_link_code( $user_id ) {
		$user_id = absint( $user_id );
		$expires = (int) get_user_meta( $user_id, self::META_LINK_EXPIRES, true );

		if ( ! $expires || $expires < time() ) {
			$this->clear_link_code( $user_id );
			return '';
		}

		$code = get_transient( $this->get_display_transient_key( $user_id ) );

		return is_string( $code ) ? $code : '';
	}

	public function consume_link_code( $code, $message ) {
		$code = strtoupper( sanitize_text_field( $code ) );
		$user = $this->find_user_by_code( $code );

		if ( ! $user ) {
			return array(
				'ok'      => false,
				'message' => __( 'Link code is invalid or expired.', 'telepress' ),
			);
		}

		$telegram_user_id = isset( $message['from']['id'] ) ? (string) $message['from']['id'] : '';
		$chat_id          = isset( $message['chat']['id'] ) ? (string) $message['chat']['id'] : '';
		$username         = isset( $message['from']['username'] ) ? sanitize_text_field( $message['from']['username'] ) : '';

		update_user_meta( $user->ID, self::META_TELEGRAM_ID, $telegram_user_id );
		update_user_meta( $user->ID, self::META_TELEGRAM_CHAT, $chat_id );
		update_user_meta( $user->ID, self::META_TELEGRAM_NAME, $username );
		update_user_meta( $user->ID, self::META_LINKED_AT, time() );
		$this->clear_link_code( $user->ID );

		TelePress_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $user->ID,
				'telegram_user_id' => $telegram_user_id,
				'chat_id'          => $chat_id,
				'action_name'      => 'telegram_account_linked',
				'resource_type'    => 'user',
				'resource_id'      => (string) $user->ID,
				'after_state'      => array(
					'telegram_user_id' => $telegram_user_id,
					'telegram_chat_id' => $chat_id,
					'telegram_username' => $username,
				),
			)
		);

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: WordPress display name. */
				__( 'Telegram successfully linked to WordPress user %s.', 'telepress' ),
				$user->display_name
			),
		);
	}

	public function unlink_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'telepress_user_not_found', __( 'User not found.', 'telepress' ) );
		}

		delete_user_meta( $user_id, self::META_TELEGRAM_ID );
		delete_user_meta( $user_id, self::META_TELEGRAM_CHAT );
		delete_user_meta( $user_id, self::META_TELEGRAM_NAME );
		delete_user_meta( $user_id, self::META_LINKED_AT );
		$this->clear_link_code( $user_id );

		return true;
	}

	private function find_user_by_code( $code ) {
		$users = get_users(
			array(
				'meta_key'    => self::META_LINK_CODE_HASH,
				'number'      => 50,
				'count_total' => false,
			)
		);

		foreach ( $users as $user ) {
			$expires = (int) get_user_meta( $user->ID, self::META_LINK_EXPIRES, true );

			if ( $expires < time() ) {
				$this->clear_link_code( $user->ID );
				continue;
			}

			$hash = (string) get_user_meta( $user->ID, self::META_LINK_CODE_HASH, true );

			if ( $hash && wp_check_password( $code, $hash ) ) {
				return $user;
			}
		}

		return null;
	}

	private function clear_link_code( $user_id ) {
		delete_user_meta( $user_id, self::META_LINK_CODE );
		delete_user_meta( $user_id, self::META_LINK_CODE_HASH );
		delete_user_meta( $user_id, self::META_LINK_EXPIRES );
		delete_transient( $this->get_display_transient_key( $user_id ) );
	}

	private function get_display_transient_key( $user_id ) {
		return 'telepress_link_code_' . absint( $user_id );
	}
}
