<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Bootstrap {
	const SCHEMA_VERSION = '0.3.0-beta.2';

	private $settings_page;
	private $rest_controller;
	private $notification_service;
	private $users_service;
	private $post_editor_service;

	public function __construct() {
		$this->settings_page        = new Telepilot_Settings_Page();
		$this->rest_controller      = new Telepilot_REST_Webhook_Controller();
		$this->notification_service = new Telepilot_Notification_Service();
		$this->users_service        = new Telepilot_Users_Service( new Telepilot_Confirmation_Service() );
		$this->post_editor_service  = new Telepilot_Post_Editor_Service();
	}

	public function boot() {
		load_plugin_textdomain( 'telepilot', false, dirname( plugin_basename( TELEPILOT_FILE ) ) . '/languages' );
		$this->maybe_upgrade_schema();
		$this->ensure_settings_defaults();
		$this->settings_page->register();

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'telepilot_daily_maintenance', array( $this, 'run_daily_maintenance' ) );
		add_action( 'telepilot_poll_updates', array( $this, 'poll_updates' ) );
		add_action( 'telepilot_process_jobs', array( $this, 'process_jobs' ) );
		add_action( 'admin_post_' . Telepilot_Post_Editor_Service::HANDLER_ACTION, array( $this->post_editor_service, 'handle_request' ) );
		add_action( 'admin_post_nopriv_' . Telepilot_Post_Editor_Service::HANDLER_ACTION, array( $this->post_editor_service, 'handle_request' ) );
		add_action( 'comment_post', array( $this->notification_service, 'handle_new_comment' ), 10, 3 );
		add_action( 'wp_set_comment_status', array( $this->notification_service, 'handle_comment_status_change' ), 10, 2 );
		add_action( 'transition_post_status', array( $this->notification_service, 'handle_post_transition' ), 10, 3 );
		add_action( 'wp_login_failed', array( $this->notification_service, 'handle_failed_login' ) );
		add_action( 'user_register', array( $this->notification_service, 'handle_user_registered' ), 10, 2 );
		add_action( 'profile_update', array( $this->notification_service, 'handle_user_profile_updated' ), 10, 3 );
		add_action( 'delete_user', array( $this->notification_service, 'handle_user_deleted' ), 10, 3 );
		add_action( 'set_user_role', array( $this->notification_service, 'handle_user_role_changed' ), 10, 3 );
		add_action( 'retrieve_password_key', array( $this->notification_service, 'handle_password_reset_requested' ), 10, 2 );
		add_action( 'after_password_reset', array( $this->notification_service, 'handle_password_reset_completed' ), 10, 2 );
		add_action( 'activated_plugin', array( $this->notification_service, 'handle_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this->notification_service, 'handle_plugin_deactivated' ), 10, 2 );
		add_action( 'switch_theme', array( $this->notification_service, 'handle_theme_switched' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this->notification_service, 'handle_upgrader_process_complete' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( $this->notification_service, 'handle_automatic_updates_complete' ), 10, 1 );
		add_filter( 'wp_authenticate_user', array( $this->users_service, 'block_disabled_user' ), 20, 1 );

		if ( ! wp_next_scheduled( 'telepilot_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'telepilot_daily_maintenance' );
		}

		$this->sync_transport_schedule();
	}

	public function run_daily_maintenance() {
		$settings = get_option( 'telepilot_settings', array() );
		$days     = isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30;

		Telepilot_Audit_Log_Repository::purge_expired_logs( $days );
		Telepilot_Processed_Updates_Repository::purge_expired( min( $days, 14 ) );
		$this->notification_service->maybe_send_update_notifications();
	}

	public function poll_updates() {
		$settings = get_option( 'telepilot_settings', array() );

		if ( empty( $settings['bot_token'] ) || ( isset( $settings['transport_mode'] ) && 'polling' !== $settings['transport_mode'] ) ) {
			return;
		}

		$telegram = new Telepilot_Telegram_Service();
		$telegram->poll_updates();
	}

	public function process_jobs() {
		$telegram = new Telepilot_Telegram_Service();
		$telegram->process_jobs();
	}

	public function register_cron_schedules( $schedules ) {
		$schedules['telepilot_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every Minute (Telepilot)', 'telepilot' ),
		);

		return $schedules;
	}

	private function sync_transport_schedule() {
		$settings       = get_option( 'telepilot_settings', array() );
		$transport_mode = isset( $settings['transport_mode'] ) ? (string) $settings['transport_mode'] : 'webhook';

		if ( 'polling' === $transport_mode ) {
			if ( ! wp_next_scheduled( 'telepilot_poll_updates' ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'telepilot_every_minute', 'telepilot_poll_updates' );
			}

			return;
		}

		wp_clear_scheduled_hook( 'telepilot_poll_updates' );
	}

	private function maybe_upgrade_schema() {
		$current_version = (string) get_option( 'telepilot_schema_version', '' );

		if ( self::SCHEMA_VERSION === $current_version ) {
			return;
		}

		Telepilot_Audit_Log_Repository::create_table();
		Telepilot_Jobs_Repository::create_table();
		Telepilot_Processed_Updates_Repository::create_table();
		update_option( 'telepilot_schema_version', self::SCHEMA_VERSION, false );
	}

	private function ensure_settings_defaults() {
		$settings = get_option( 'telepilot_settings', array() );
		$defaults = array(
			'bot_token'             => '',
			'transport_mode'        => 'webhook',
			'allowed_chat_ids'      => '',
			'default_notifications' => array( 'new_comment', 'failed_login', 'plugin_updates', 'theme_updates', 'core_updates' ),
			'stale_update_window'   => Telepilot_Telegram_Service::DEFAULT_STALE_WINDOW,
			'log_retention_days'    => 30,
			'rate_limit_per_minute' => 20,
			'linking_enabled'       => 1,
			'cleanup_on_uninstall'  => 0,
		);

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$hydrated = wp_parse_args( $settings, $defaults );
		$updated  = $hydrated !== $settings;
		$settings = $hydrated;

		if ( empty( $settings['webhook_secret'] ) ) {
			$settings['webhook_secret'] = wp_generate_password( 32, false, false );
			$updated                    = true;
		}

		if ( empty( $settings['worker_secret'] ) ) {
			$settings['worker_secret'] = wp_generate_password( 32, false, false );
			$updated                   = true;
		}

		if ( $updated ) {
			update_option( 'telepilot_settings', $settings, false );
		}
	}
}
