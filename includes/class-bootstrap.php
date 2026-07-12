<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Bootstrap {
	const SCHEMA_VERSION = '0.2.2';

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
		$this->maybe_upgrade_schema();
		$this->ensure_settings_defaults();
		$this->settings_page->register();

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'telepress_daily_maintenance', array( $this, 'run_daily_maintenance' ) );
		add_action( 'telepress_poll_updates', array( $this, 'poll_updates' ) );
		add_action( 'telepress_process_jobs', array( $this, 'process_jobs' ) );
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
		TelePress_Processed_Updates_Repository::purge_expired( min( $days, 14 ) );
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

	public function process_jobs() {
		$telegram = new TelePress_Telegram_Service();
		$telegram->process_jobs();
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

	private function maybe_upgrade_schema() {
		$current_version = (string) get_option( 'telepress_schema_version', '' );

		if ( self::SCHEMA_VERSION === $current_version ) {
			return;
		}

		TelePress_Audit_Log_Repository::create_table();
		TelePress_Jobs_Repository::create_table();
		TelePress_Processed_Updates_Repository::create_table();
		update_option( 'telepress_schema_version', self::SCHEMA_VERSION, false );
	}

	private function ensure_settings_defaults() {
		$settings = get_option( 'telepress_settings', array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$updated = false;

		if ( empty( $settings['webhook_secret'] ) ) {
			$settings['webhook_secret'] = wp_generate_password( 32, false, false );
			$updated                    = true;
		}

		if ( empty( $settings['worker_secret'] ) ) {
			$settings['worker_secret'] = wp_generate_password( 32, false, false );
			$updated                   = true;
		}

		if ( $updated ) {
			update_option( 'telepress_settings', $settings, false );
		}
	}
}
