<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Deactivator {
	public static function deactivate() {
		wp_clear_scheduled_hook( 'telepress_daily_maintenance' );
		wp_clear_scheduled_hook( 'telepress_poll_updates' );

		$settings = get_option( 'telepress_settings', array() );
		$token    = isset( $settings['bot_token'] ) ? (string) $settings['bot_token'] : '';

		if ( '' !== $token ) {
			$client = new TelePress_Telegram_Client( $token );
			$client->delete_webhook();
		}
	}
}
