<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_User_Linking_Service {
	const META_LINK_CODE      = '_telepilot_link_code';
	const META_LINK_CODE_HASH = '_telepilot_link_code_hash';
	const META_LINK_EXPIRES   = '_telepilot_link_expires';
	const META_TELEGRAM_ID    = '_telepilot_telegram_user_id';
	const META_TELEGRAM_CHAT  = '_telepilot_telegram_chat_id';
	const META_TELEGRAM_NAME  = '_telepilot_telegram_username';
	const META_LINKED_AT      = '_telepilot_linked_at';

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
			return Telepilot_Telegram_Response_Builder::error_html(
				Telepilot_Telegram_Response_Builder::join_blocks(
					array(
						Telepilot_Telegram_Response_Builder::bold( __( 'Link Code Invalid', 'wp-telepilot' ) ),
						__( 'That link code is invalid or has already expired.', 'wp-telepilot' ),
						Telepilot_Telegram_Response_Builder::italic( __( 'Generate a fresh code from your WordPress profile, then try /link CODE again within the hour.', 'wp-telepilot' ) ),
					)
				),
				array(
					'command' => '/link',
				)
			);
		}

		$telegram_user_id = isset( $message['from']['id'] ) ? (string) $message['from']['id'] : '';
		$chat_id          = isset( $message['chat']['id'] ) ? (string) $message['chat']['id'] : '';
		$username         = isset( $message['from']['username'] ) ? sanitize_text_field( $message['from']['username'] ) : '';
		if ( ! Telepilot_Actor_Access::active_user( $user ) || 'private' !== ( $message['chat']['type'] ?? '' ) || '' === $telegram_user_id || $telegram_user_id !== $chat_id || ! Telepilot_Actor_Access::chat_allowed( $chat_id ) ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'Linking requires an active site account and an authorized private chat.', 'wp-telepilot' ) );
		}
		$existing = get_users( array( 'meta_key' => self::META_TELEGRAM_ID, 'meta_value' => $telegram_user_id, 'fields' => 'ID' ) );
		foreach ( $existing as $existing_id ) {
			if ( (int) $existing_id !== (int) $user->ID ) {
				return Telepilot_Telegram_Response_Builder::error( __( 'Unlink the existing WordPress account before linking a different account.', 'wp-telepilot' ) );
			}
		}
		global $wpdb;
		$verified_hash = (string) get_user_meta( $user->ID, self::META_LINK_CODE_HASH, true );
		if ( ! $verified_hash || ! wp_check_password( $code, $verified_hash ) || 1 !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s AND meta_value=%s", $user->ID, self::META_LINK_CODE_HASH, $verified_hash ) ) ) {
			return Telepilot_Telegram_Response_Builder::error( __( 'This link code has already been used. Generate another code.', 'wp-telepilot' ) );
		}
		wp_cache_delete( $user->ID, 'user_meta' );
		Telepilot_Actor_Access::revoke( $user->ID );

		update_user_meta( $user->ID, self::META_TELEGRAM_ID, $telegram_user_id );
		update_user_meta( $user->ID, self::META_TELEGRAM_CHAT, $chat_id );
		update_user_meta( $user->ID, self::META_TELEGRAM_NAME, $username );
		update_user_meta( $user->ID, self::META_LINKED_AT, time() );
		$this->clear_link_code( $user->ID );

		Telepilot_Audit_Log_Repository::log(
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

		$display_name = $user->display_name ? $user->display_name : $user->user_login;
		$roles        = ! empty( $user->roles ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $user->roles ) ) : __( 'no role', 'wp-telepilot' );
		$lines        = array(
			Telepilot_Telegram_Response_Builder::bold( __( 'Telegram Linked', 'wp-telepilot' ) ),
			sprintf(
				__( 'WordPress user: %s', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $display_name )
			),
			sprintf(
				__( 'Role: %s', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $roles )
			),
		);

		if ( '' !== $username ) {
			$lines[] = sprintf(
				__( 'Telegram: @%s', 'wp-telepilot' ),
				Telepilot_Telegram_Response_Builder::escape( $username )
			);
		}

		$lines[] = Telepilot_Telegram_Response_Builder::italic( __( 'Next: use /menu to open your command hub or /help to review available commands.', 'wp-telepilot' ) );

		return Telepilot_Telegram_Response_Builder::success_html(
			Telepilot_Telegram_Response_Builder::join_blocks( $lines ),
			array(
				'command' => '/link',
			)
		);
	}

	public function unlink_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'telepilot_user_not_found', __( 'User not found.', 'wp-telepilot' ) );
		}

		delete_user_meta( $user_id, self::META_TELEGRAM_ID );
		Telepilot_Actor_Access::revoke( $user_id );
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
		return 'telepilot_link_code_' . absint( $user_id );
	}
}
