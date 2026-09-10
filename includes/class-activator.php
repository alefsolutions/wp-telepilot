<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Activator {
	public static function activate() {
		Telepilot_Audit_Log_Repository::create_table();
		Telepilot_Jobs_Repository::create_table();
		Telepilot_Security_Records::create_table();
		Telepilot_Processed_Updates_Repository::create_table();

		$defaults = array(
			'bot_token'             => '',
			'webhook_secret'        => wp_generate_password( 32, false, false ),
			'worker_secret'         => wp_generate_password( 32, false, false ),
			'transport_mode'        => 'webhook',
			'allowed_chat_ids'      => '',
			'default_notifications' => array( 'new_comment', 'failed_login', 'plugin_updates', 'theme_updates', 'core_updates' ),
			'stale_update_window'   => Telepilot_Telegram_Service::DEFAULT_STALE_WINDOW,
			'log_retention_days'    => 30,
			'rate_limit_per_minute' => 20,
			'linking_enabled'       => 1,
			'cleanup_on_uninstall'  => 0,
		);

		if ( ! get_option( 'telepilot_settings' ) ) {
			add_option( 'telepilot_settings', $defaults );
		}

		if ( ! get_option( Telepilot_Telegram_Service::DIAGNOSTICS_OPTION ) ) {
			add_option(
				Telepilot_Telegram_Service::DIAGNOSTICS_OPTION,
				array(
					'stale_updates_dropped' => 0,
				)
			);
		}

		( new Telepilot_Bootstrap() )->maybe_upgrade_schema();
	}
}
