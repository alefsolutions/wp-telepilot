<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Activator {
	public static function activate() {
		TelePress_Audit_Log_Repository::create_table();
		TelePress_Jobs_Repository::create_table();
		TelePress_Processed_Updates_Repository::create_table();

		$defaults = array(
			'bot_token'             => '',
			'webhook_secret'        => wp_generate_password( 32, false, false ),
			'worker_secret'         => wp_generate_password( 32, false, false ),
			'transport_mode'        => 'webhook',
			'allowed_chat_ids'      => '',
			'default_notifications' => array( 'new_comment', 'failed_login', 'plugin_updates', 'theme_updates', 'core_updates' ),
			'stale_update_window'   => TelePress_Telegram_Service::DEFAULT_STALE_WINDOW,
			'log_retention_days'    => 30,
			'rate_limit_per_minute' => 20,
			'linking_enabled'       => 1,
		);

		if ( ! get_option( 'telepress_settings' ) ) {
			add_option( 'telepress_settings', $defaults );
		}

		if ( ! get_option( TelePress_Telegram_Service::DIAGNOSTICS_OPTION ) ) {
			add_option(
				TelePress_Telegram_Service::DIAGNOSTICS_OPTION,
				array(
					'stale_updates_dropped' => 0,
				)
			);
		}

		update_option( 'telepress_schema_version', TelePress_Bootstrap::SCHEMA_VERSION, false );
	}
}
