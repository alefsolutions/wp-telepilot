<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Linked_User_Resolver {
	public function resolve_from_update( $update ) {
		$telegram_user_id = null;
		$chat_id          = null;
		$username         = '';

		if ( ! empty( $update['message']['from']['id'] ) ) {
			$telegram_user_id = (string) $update['message']['from']['id'];
			$chat_id          = isset( $update['message']['chat']['id'] ) ? (string) $update['message']['chat']['id'] : null;
			$username         = isset( $update['message']['from']['username'] ) ? sanitize_text_field( $update['message']['from']['username'] ) : '';
		} elseif ( ! empty( $update['callback_query']['from']['id'] ) ) {
			$telegram_user_id = (string) $update['callback_query']['from']['id'];
			$chat_id          = isset( $update['callback_query']['message']['chat']['id'] ) ? (string) $update['callback_query']['message']['chat']['id'] : null;
			$username         = isset( $update['callback_query']['from']['username'] ) ? sanitize_text_field( $update['callback_query']['from']['username'] ) : '';
		}

		if ( empty( $telegram_user_id ) ) {
			return array(
				'telegram_user_id' => null,
				'chat_id'          => $chat_id,
				'telegram_username'=> $username,
				'wp_user'          => null,
			);
		}

		$users = get_users(
			array(
				'meta_key'    => TelePress_User_Linking_Service::META_TELEGRAM_ID,
				'meta_value'  => $telegram_user_id,
				'number'      => 1,
				'count_total' => false,
			)
		);

		return array(
			'telegram_user_id' => $telegram_user_id,
			'chat_id'          => $chat_id,
			'telegram_username'=> $username,
			'wp_user'          => empty( $users ) ? null : $users[0],
		);
	}
}
