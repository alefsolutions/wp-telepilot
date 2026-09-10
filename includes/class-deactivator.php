<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Deactivator {
	public static function deactivate() {
		Telepilot_AI_Session_Manager::purge( true );
		update_option( 'telepilot_access_epoch', wp_generate_uuid4(), false );
		Telepilot_Jobs_Repository::stop_pending();
		wp_clear_scheduled_hook( 'telepilot_hourly_maintenance' );
		wp_clear_scheduled_hook( 'telepilot_daily_maintenance' );
		wp_clear_scheduled_hook( 'telepilot_poll_updates' );
		wp_clear_scheduled_hook( 'telepilot_process_jobs' );
		delete_transient( Telepilot_Telegram_Service::POLL_LOCK_TRANSIENT );

		$settings = get_option( 'telepilot_settings', array() );
		$token    = isset( $settings['bot_token'] ) ? (string) $settings['bot_token'] : '';

		if ( '' !== $token ) {
			$client = new Telepilot_Telegram_Client( $token );
			$client->delete_webhook();
		}
	}
}
