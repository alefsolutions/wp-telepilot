<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Bootstrap {
	private $settings_page;
	private $rest_controller;
	private $notification_service;
	private $users_service;

	public function __construct() {
		$this->settings_page        = new TelePress_Settings_Page();
		$this->rest_controller      = new TelePress_REST_Webhook_Controller();
		$this->notification_service = new TelePress_Notification_Service();
		$this->users_service        = new TelePress_Users_Service( new TelePress_Confirmation_Service() );
	}

	public function boot() {
		load_plugin_textdomain( 'telepress', false, dirname( plugin_basename( TELEPRESS_FILE ) ) . '/languages' );
		$this->settings_page->register();

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'telepress_daily_maintenance', array( $this, 'run_daily_maintenance' ) );
		add_action( 'telepress_poll_updates', array( $this, 'poll_updates' ) );
		add_action( 'comment_post', array( $this->notification_service, 'handle_new_comment' ), 10, 2 );
		add_action( 'transition_post_status', array( $this->notification_service, 'handle_post_transition' ), 10, 3 );
		add_action( 'wp_login_failed', array( $this->notification_service, 'handle_failed_login' ) );
		add_filter( 'wp_authenticate_user', array( $this->users_service, 'block_disabled_user' ), 20, 1 );

		if ( ! wp_next_scheduled( 'telepress_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'telepress_daily_maintenance' );
		}

		$this->sync_transport_schedule();
	}

	public function run_daily_maintenance() {
		$settings = get_option( 'telepress_settings', array() );
		$days     = isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30;

		TelePress_Audit_Log_Repository::purge_expired_logs( $days );
		$this->notification_service->maybe_send_update_notifications();
	}

	public function poll_updates() {
		$settings = get_option( 'telepress_settings', array() );

		if ( empty( $settings['bot_token'] ) || ( isset( $settings['transport_mode'] ) && 'polling' !== $settings['transport_mode'] ) ) {
			return;
		}

		$telegram = new TelePress_Telegram_Service();
		$telegram->poll_updates();
	}

	public function register_cron_schedules( $schedules ) {
		$schedules['telepress_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every Minute (TelePress)', 'telepress' ),
		);

		return $schedules;
	}

	private function sync_transport_schedule() {
		$settings       = get_option( 'telepress_settings', array() );
		$transport_mode = isset( $settings['transport_mode'] ) ? (string) $settings['transport_mode'] : 'webhook';

		if ( 'polling' === $transport_mode ) {
			if ( ! wp_next_scheduled( 'telepress_poll_updates' ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'telepress_every_minute', 'telepress_poll_updates' );
			}

			return;
		}

		wp_clear_scheduled_hook( 'telepress_poll_updates' );
	}
}
