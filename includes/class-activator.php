<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Activator {
	public static function activate() {
		TelePress_Audit_Log_Repository::create_table();

		$defaults = array(
			'bot_token'             => '',
			'webhook_secret'        => wp_generate_password( 32, false, false ),
			'transport_mode'        => 'webhook',
			'allowed_chat_ids'      => '',
			'default_notifications' => array( 'new_comment', 'failed_login', 'plugin_updates', 'theme_updates', 'core_updates' ),
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
	}
}
